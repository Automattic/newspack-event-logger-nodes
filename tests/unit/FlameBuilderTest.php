<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

/**
 * FlameBuilder consumes completed-request JSON docs (the output shape of
 * RequestBuilder), not raw firehose lines.
 *
 * A completed request looks like:
 *   {
 *     rid, url, duration_ms, status_code, error_status, peak_mb,
 *     request_method, server_name, country_code, http_from, user_agent,
 *     ja4_hash, is_worker, timestamp,
 *     entries: [ { n, ts, k, m, l, duration_ms, peak_mb }, ... ],
 *     profiles: { state: { entries: { label: [time, count] }, count, time, ts } }
 *   }
 *
 * The flame tree is built from `entries` via LIFO matching of `^(.+?) \(start\)$`
 * / `^(.+?) \(complete\)$` patterns on `k`.
 */
#[CoversClass( Flame_Builder_Node::class )]
class FlameBuilderTest extends TestCase {

	/** @var list<string> Temp partition dirs created during a test, removed in tearDown. */
	private array $temp_dirs = [];

	protected function tearDown(): void {
		foreach ( $this->temp_dirs as $dir ) {
			$this->rrmdir( $dir );
		}
		$this->temp_dirs = [];
		parent::tearDown();
	}

	private function rrmdir( string $dir ): void {
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) \scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$path = "{$dir}/{$f}";
			if ( \is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				@\unlink( $path );
			}
		}
		@\rmdir( $dir );
	}

	private function make_partition( string $name ): \Newspack_Nodes\Partition_Node {
		// Inside the runtime tree: storage nodes refuse a path outside it.
		$dir               = \Newspack_Nodes\Config::get_base_directory() . '/flamestats_' . \uniqid();
		$this->temp_dirs[] = $dir;
		$p = new \Newspack_Nodes\Partition_Node();
		// Pin a 64 MiB segment so every mirror frame lands in one un-pruned segment.
		// The `<config:segment_size>` default resolves via a process-global token
		// resolver other tests mutate; leaving it unpinned makes this sink's
		// retention (and thus what a test can read back) order-dependent.
		$p->arguments( [ "{$dir}", "67108864" ] );
		$p->name( $name );
		$p->void_warranty();
		return $p;
	}

	private function fill_partition_entry( \Newspack_Nodes\Partition_Node $p, string $key, array $data, int $ttl, int $timestamp ): void {
		$msg                         = Message::new_message();
		$msg[ Message::TYPE ]        = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ]   = $timestamp;
		$msg[ Message::KEY ]         = $key;
		$msg[ Message::VALUE ]       = [ 'key' => $key, 'data' => $data, 'ttl' => $ttl ];
		$p->fill( $msg );
	}

	/**
	 * Read all mirror frames from a flushed partition, collapsed last-wins to
	 * the latest VALUE per key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function read_mirror_frames( \Newspack_Nodes\Partition_Node $p ): array {
		$out = [];
		foreach ( $p->get_segments( true ) as $seg ) {
			$bytes = $p->read_at( (int) $seg['id'], 0, (int) $seg['size'] );
			foreach ( \explode( "\n", $bytes ) as $line ) {
				if ( '' === $line ) {
					continue;
				}
				$val = Message::unpacked( $line )[ Message::VALUE ];
				if ( \is_array( $val ) && \is_string( $val['key'] ?? null ) ) {
					$out[ $val['key'] ] = $val;
				}
			}
		}
		return $out;
	}

	/**
	 * The persisted per-URL flame-profile frame keys (`evlog:p0:url:*`) — NOT the
	 * url_dim / url_cat namespaces (their keys carry a `_dim` / `_cat` stem, so the
	 * `url:` colon prefix excludes them).
	 *
	 * @return list<string>
	 */
	private function url_flame_keys( \Newspack_Nodes\Partition_Node $p ): array {
		return \array_values(
			\array_filter(
				\array_keys( $this->read_mirror_frames( $p ) ),
				static fn ( string $k ): bool => \str_starts_with( $k, self::scoped( 'evlog:p0:url:' ) )
			)
		);
	}

	/**
	 * Build a completed-request payload for FlameBuilder. Defaults are
	 * production-shaped; tests override only the fields they assert on.
	 *
	 * Timestamp defaults to current time so the FlameBuilder's bucket-key
	 * derivation aligns with the test's local bucket-key assertion
	 * (otherwise the bucket gets pruned by the retention cutoff).
	 */
	private function completed_request( array $overrides = [] ): array {
		$base = [
			'rid'            => 'r' . \uniqid(),
			'url'            => '/post/123',
			'rule_id'        => 'r',
			'duration_ms'    => 100.0,
			'status_code'    => 200,
			'error_status'   => '-',
			'peak_mb'        => 32.0,
			'request_method' => 'GET',
			'server_name'    => 'example.com',
			'country_code'   => 'US',
			'http_from'      => '',
			'user_agent'     => 'curl/7.85',
			'ja4_hash'       => '',
			'is_worker'      => false,
			'timestamp'      => \time(),
			'entries'        => [],
			'profiles'       => [],
		];
		return \array_replace( $base, $overrides );
	}

	/**
	 * Seed the durable ruleset option with a single rule. Defaults to a
	 * log-all rule id 'r' at prefix '/'; overrides customize id/pattern/
	 * thresholds so the request's stamped rule_id resolves to it.
	 *
	 * @param array<string, mixed> $overrides
	 */
	private function set_rule( array $overrides = [] ): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] = [
			\array_replace( [ 'id' => 'r', 'pattern' => '/', 'action' => 'log' ], $overrides ),
		];
	}

	private function bucket_key_for( int $timestamp ): string {
		$min        = (int) \gmdate( 'i', $timestamp );
		$bucket_min = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', \STR_PAD_LEFT );
		return \gmdate( 'Y-m-d-H', $timestamp ) . '-' . $bucket_min;
	}

	private function fill_request( Flame_Builder_Node $fb, array $request ): void {
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::VALUE ]     = $request;
		$fb->fill( $message );
	}

	/**
	 * Read the node's introspection payload through the production GET_STATS
	 * request verb — the same path the dashboard and the REPL read.
	 *
	 * @return array<string, mixed>
	 */
	private function get_stats( Flame_Builder_Node $fb ): array {
		$prev    = $fb->sink();
		$capture = new Capture_Sink_Node();
		$fb->sink( $capture );

		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::FROM ]  = 'test-probe';
		$message[ Message::VALUE ] = 'GET_STATS';
		$fb->fill( $message );

		$fb->sink( $prev );

		foreach ( $capture->captured as $captured ) {
			$type = $captured[ Message::TYPE ];
			if ( ( $type & Message::TM_RESPONSE ) && ( $type & Message::TM_STRUCT ) ) {
				return $captured[ Message::VALUE ]['data'];
			}
		}
		$this->fail( 'GET_STATS reply not captured' );
	}

	private function stats_count( Flame_Builder_Node $fb ): int {
		return (int) $this->get_stats( $fb )['stats_count'];
	}

	public function test_constructor_initializes_empty(): void {
		$fb = new Flame_Builder_Node();
		$this->assertSame( 0, $this->stats_count( $fb ) );
	}

	public function test_target_includes_flames_partition_and_auto_tuner(): void {
		// FlameBuilder's flame-write path uses the standard
		// target/sink pair like any other Node connection (set via
		// `connect_node flame-builder flames:partition`). The
		// owned auto-tuner sibling is patron-linked, so the GUI
		// hides it via dump_metadata's filter — no extra edge
		// surfaces from target().
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->connect_node( 'flames:partition' );

		$this->assertSame( 'flames:partition', $fb->target() );
	}

	public function test_flame_builder_owns_auto_tuner_sibling(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		// Auto-tuner registered under {patron}:auto-tuner with patron link.
		$at = \Newspack_Nodes\Core::node( 'fb:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, $at );
		$this->assertSame( $fb, $at->patron() );
	}

	public function test_flame_builder_remove_node_cascades_auto_tuner(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertNotNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
		$fb->remove_node();
		$this->assertNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
	}

	public function test_rename_cascades_auto_tuner_and_drops_old_name(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->name( 'fb2' );

		$this->assertNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
		$at = \Newspack_Nodes\Core::node( 'fb2:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, $at );
		$this->assertSame( $fb, $at->patron() );
	}

	public function test_name_null_throws(): void {
		// A named node is committed until remove_node(); name(null) throws.
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->expectException( \RuntimeException::class );
		$fb->name( null );
	}

	public function test_name_empty_string_throws(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->expectException( \RuntimeException::class );
		$fb->name( '' );
	}

	public function test_remove_node_unregisters_auto_tuner(): void {
		// remove_node() (not name(null)) tears down the owned auto-tuner sibling.
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );

		$fb->remove_node();
		$this->assertNull( \Newspack_Nodes\Core::node( 'fb' ) );
		$this->assertNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
	}

	public function test_zero_name_yields_zero_auto_tuner_sibling(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( '0' );

		$at = \Newspack_Nodes\Core::node( '0:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, $at );
	}

	public function test_check_name_availability_throws_on_auto_tuner_collision(): void {
		$squatter = new \Newspack_Event_Logger_Nodes\Auto_Tuner_Node();
		$squatter->name( 'fb:auto-tuner' );

		$fb = new Flame_Builder_Node();
		$this->expectException( \RuntimeException::class );
		$fb->name( 'fb' );
	}

	public function test_non_array_value_skipped(): void {
		$fb                    = new Flame_Builder_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = 'not-an-array';
		$fb->fill( $message );
		$this->assertSame( 0, $this->stats_count( $fb ) );
	}

	public function test_clean_stop_on_the_flame_forward_still_accumulates_stats_and_raises_clean(): void {
		// When the flame-doc forward triggers a cooperative stop (the partition wrote the
		// doc, then pump() signaled it), FlameBuilder must still accumulate the request's
		// stats — its recoverable state — and re-raise as CLEAN, so the Consumer commits
		// past the line instead of replaying it and double-counting the stats.
		$this->set_rule();
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->connect_node( 'flames:partition' ); // non-empty target so store_flame forwards.
		$fb->sink( new class extends \Newspack_Nodes\Node {
			public function fill( array $message ): void {
				throw new \Newspack_Nodes\Worker_Should_Stop();
			}
		} );

		try {
			$this->fill_request( $fb, $this->completed_request() );
			$this->fail( 'expected a clean stop to propagate' );
		} catch ( \Newspack_Nodes\Worker_Should_Stop_Clean $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( 1, $this->stats_count( $fb ), 'stats accumulated despite the stop on the flame forward' );
	}

	public function test_a_stale_pending_stop_does_not_leak_into_a_later_message(): void {
		// pending_stop is a PER-MESSAGE deferral. A non-Worker_Should_Stop throwable escaping
		// one fill() after a guarded() catch (dead-lettered, worker survives) must not strand
		// it into the next message — else that innocent line would be clean-stopped + dropped.
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		$ref = new \ReflectionProperty( $fb, 'pending_stop' );
		$ref->setValue( $fb, new \Newspack_Nodes\Worker_Should_Stop() );

		$this->fill_request( $fb, $this->completed_request() );
		$this->assertNull( $ref->getValue( $fb ), 'fill() clears any stale pending_stop at entry' );
	}

	public function test_non_bytestream_message_skipped(): void {
		$fb                    = new Flame_Builder_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_INFO;
		$message[ Message::VALUE ] = $this->completed_request();
		$fb->fill( $message );
		$this->assertSame( 0, $this->stats_count( $fb ) );
	}

	// --- Flame tree construction ------------------------------------------

	public function test_flame_tree_built_from_entries_with_lifo_matching(): void {
		$fb     = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->connect_node( 'flames:partition' );

		$req = $this->completed_request( [
			'duration_ms' => 50.0,
			'entries'     => [
				[ 'k' => 'wp_head hook (start)', 'l' => '', 'm' => '' ],
				[ 'k' => 'init (start)', 'l' => '' ],
				[ 'k' => 'init (complete)', 'duration_ms' => 5.0 ],
				[ 'k' => 'wp_head hook (complete)', 'duration_ms' => 25.0 ],
			],
		] );

		$this->fill_request( $fb, $req );

		$this->assertCount( 1, $capture->captured, 'flame_data is written to flames_sink' );
		$flame = $capture->captured[0][ Message::VALUE ];
		$this->assertSame( 'request', $flame['name'] );
		// Root has duration assigned to value at fill().
		$this->assertEqualsWithDelta( 50.0, $flame['value'], 1e-9 );
		// Child for wp_head hook.
		$this->assertNotEmpty( $flame['children'] );
		$wp_head = $flame['children'][0];
		$this->assertSame( 'wp_head hook', $wp_head['name'] );
		$this->assertEqualsWithDelta( 25.0, $wp_head['value'], 1e-9 );
		// Grandchild for init.
		$this->assertNotEmpty( $wp_head['children'] );
		$init = $wp_head['children'][0];
		$this->assertSame( 'init', $init['name'] );
		$this->assertEqualsWithDelta( 5.0, $init['value'], 1e-9 );
	}

	public function test_orphaned_complete_event_ignored(): void {
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->connect_node( 'flames:partition' );

		$req = $this->completed_request( [
			'entries' => [
				// "complete" with no preceding "start" — must not crash, must not emit a node.
				[ 'k' => 'unmatched (complete)', 'duration_ms' => 1.0 ],
			],
		] );
		$this->fill_request( $fb, $req );

		$flame = $capture->captured[0][ Message::VALUE ];
		$this->assertSame( 'request', $flame['name'] );
		$this->assertEmpty( $flame['children'] );
	}

	public function test_duplicate_sibling_names_numbered_with_suffix_then_stripped(): void {
		// Two `init (start)` siblings under the root. They get \x00N suffixes
		// during merge for unambiguous tracking, then suffixes get stripped
		// before storage / display.
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->connect_node( 'flames:partition' );

		$req = $this->completed_request( [
			'entries' => [
				[ 'k' => 'init (start)' ],
				[ 'k' => 'init (complete)', 'duration_ms' => 2.0 ],
				[ 'k' => 'init (start)' ],
				[ 'k' => 'init (complete)', 'duration_ms' => 3.0 ],
			],
		] );
		$this->fill_request( $fb, $req );

		$flame = $capture->captured[0][ Message::VALUE ];
		$this->assertCount( 2, $flame['children'], '2 siblings at root' );
		// After strip_name_suffixes: both children have name "init" with no \x00.
		foreach ( $flame['children'] as $c ) {
			$this->assertSame( 'init', $c['name'], 'suffix stripped before store' );
		}
	}

	public function test_save_state_persists_current_flame_stats_without_a_periodic_flush(): void {
		// stats_cache (per-URL flame trees) must be co-committed with the cursor at
		// save_state, exactly like the `pending` aggregates — else a clean recycle advances
		// past messages whose flame data was only in RAM (drained to the store only every
		// FLUSH_INTERVAL_SEC), losing up to a flush window of per-URL flame data.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/x', 'duration_ms' => 100.0 ] ) );

		// No periodic flush() — only the checkpoint's save_state().
		$fb->save_state();

		$stats = $store->get_url_stats( Log_Manager::url_hash( '/x' ) );
		$this->assertNotNull( $stats, 'save_state drains the current flame stats to the store' );
		$this->assertSame( 1, $stats['flame_raw']['count'] );
	}

	// --- Per-URL aggregate (sums-not-means) -------------------------------

	public function test_per_url_aggregate_sums_durations_across_requests(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$req1 = $this->completed_request( [ 'url' => '/x', 'duration_ms' => 100.0 ] );
		$req2 = $this->completed_request( [ 'url' => '/x', 'duration_ms' => 200.0 ] );
		$this->fill_request( $fb, $req1 );
		$this->fill_request( $fb, $req2 );

		// Force flush.
		$fb->flush();

		$url_hash = Log_Manager::url_hash( '/x' );
		$stats    = $store->get_url_stats( $url_hash );
		$this->assertNotNull( $stats );
		// flame_raw retains sums; flame is finalized for display.
		$this->assertEqualsWithDelta( 300.0, $stats['flame_raw']['sum_value'], 1e-6 );
		$this->assertSame( 2, $stats['flame_raw']['count'] );
		// Display: 300/2 = 150
		$this->assertEqualsWithDelta( 150.0, $stats['flame']['value'], 1e-6 );
	}

	public function test_cold_read_restores_current_shape_sums_from_the_store(): void {
		// A fresh builder (cold stats_cache) cold-reads the persisted aggregate back
		// through the flame_raw-restore branch and accumulates onto it. A current-shape
		// (sums) value must survive that branch intact — this pins the live read the
		// deleted EMA→sums migrations sat astride (they only fired on pre-fix values,
		// which no longer exist). Distinct 140/260 → 400 / mean 200, count 2.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );

		$seed = new Flame_Builder_Node();
		$seed->set_stats_store( $store );
		$this->fill_request( $seed, $this->completed_request( [ 'url' => '/cold', 'duration_ms' => 140.0 ] ) );
		$seed->flush();

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/cold', 'duration_ms' => 260.0 ] ) );
		$fb->flush();

		$stats = $store->get_url_stats( Log_Manager::url_hash( '/cold' ) );
		$this->assertNotNull( $stats );
		$this->assertEqualsWithDelta( 400.0, $stats['flame_raw']['sum_value'], 1e-6 );
		$this->assertSame( 2, $stats['flame_raw']['count'] );
		$this->assertEqualsWithDelta( 200.0, $stats['flame']['value'], 1e-6 );
	}

	public function test_url_index_min_ms_zero_for_untimed_only_url(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// A zero-duration request carries no timing (record_timing false): count
		// increments, timed_count stays 0, so min_ms must persist as 0 — never the sentinel.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/w', 'duration_ms' => 0.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket   = $this->bucket_key_for( $now );
		$index    = $store->get_url_index_hourly( $bucket );
		$url_hash = Log_Manager::url_hash( '/w' );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertSame( 1, $index[ $url_hash ]['count'] );
		$this->assertSame( 0, $index[ $url_hash ]['timed_count'] );
		$this->assertSame( 0, $index[ $url_hash ]['min_ms'] );
	}

	public function test_url_index_worker_request_now_records_timing(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// Workers now keep per-URL timing on their own ?worker_type row.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/w?reconcile', 'duration_ms' => 100.0, 'is_worker' => true, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket   = $this->bucket_key_for( $now );
		$index    = $store->get_url_index_hourly( $bucket );
		$url_hash = Log_Manager::url_hash( '/w?reconcile' );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertSame( 1, $index[ $url_hash ]['count'] );
		$this->assertSame( 1, $index[ $url_hash ]['timed_count'] );
		$this->assertEqualsWithDelta( 100.0, $index[ $url_hash ]['min_ms'], 1e-6 );
	}

	public function test_url_index_min_ms_real_when_timed_request_mixed_in(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// One untimed (worker) + one timed request for the same URL. min_ms must
		// reflect the real timed minimum, not the sentinel or 0.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/m', 'duration_ms' => 100.0, 'is_worker' => true, 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/m', 'duration_ms' => 42.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket   = $this->bucket_key_for( $now );
		$index    = $store->get_url_index_hourly( $bucket );
		$url_hash = Log_Manager::url_hash( '/m' );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertEqualsWithDelta( 42.0, $index[ $url_hash ]['min_ms'], 1e-6 );
	}

	public function test_url_index_min_ms_persisted_real_survives_later_untimed_flush(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// First flush persists a real min (42) for the URL. A later, separate
		// flush of an untimed-only (worker) request for the same URL must not
		// clobber the already-persisted real min — the write-side timed_count
		// guard protects it across flushes.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/p', 'duration_ms' => 42.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/p', 'duration_ms' => 100.0, 'is_worker' => true, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket   = $this->bucket_key_for( $now );
		$index    = $store->get_url_index_hourly( $bucket );
		$url_hash = Log_Manager::url_hash( '/p' );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertEqualsWithDelta( 42.0, $index[ $url_hash ]['min_ms'], 1e-6 );
	}

	public function test_flush_persists_hourly_to_memcache(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$req = $this->completed_request( [ 'duration_ms' => 100.0, 'peak_mb' => 32.0 ] );
		$this->fill_request( $fb, $req );
		$fb->flush();

		$hourly = $store->get_hourly();
		$this->assertNotEmpty( $hourly );
		// Some hour bucket has count=1, sum_ms=100, sum_peak_mb=32.
		$bucket = \array_keys( $hourly )[0];
		$this->assertSame( 1, $hourly[ $bucket ]['count'] );
		$this->assertEqualsWithDelta( 100.0, $hourly[ $bucket ]['sum_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 32.0, $hourly[ $bucket ]['sum_peak_mb'], 1e-6 );
	}

	public function test_flush_persists_dimensional_status(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'status_code' => 200 ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'status_code' => 500 ] ) );
		$fb->flush();

		$dim = $store->get_dimensional( 'status' );
		$this->assertNotEmpty( $dim );
		// Status normalized to "Nxx" form by accumulate_all_stats.
		$bucket = \array_keys( $dim )[0];
		$this->assertSame( 1, $dim[ $bucket ]['2xx']['c'] );
		$this->assertSame( 1, $dim[ $bucket ]['5xx']['c'] );
	}

	public function test_flush_persists_categories_and_leaderboard(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		$req = $this->completed_request( [
			'duration_ms' => 50.0,
			'timestamp'   => $now,
			'profiles'    => [
				'wpdb' => [
					'time'    => 0.4,
					'count'   => 12,
					'ts'      => $now,
					'entries' => [ 'SELECT' => [ 0.3, 8 ] ],
				],
			],
		] );
		$this->fill_request( $fb, $req );
		$fb->flush();

		// Global category time series.
		$cats = $store->get_categories();
		$this->assertNotEmpty( $cats );
		$bucket = $this->bucket_key_for( $now );
		$this->assertArrayHasKey( $bucket, $cats );
		$this->assertArrayHasKey( 'wpdb', $cats[ $bucket ] );
		$this->assertArrayHasKey( 'total', $cats[ $bucket ] );
		$this->assertEqualsWithDelta( 0.4, $cats[ $bucket ]['wpdb']['t'], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $cats[ $bucket ]['wpdb']['c'], 1e-6 );

		// Leaderboard bucket holds sums-not-means.
		$lb = $store->get_leaderboard_bucket( $bucket );
		$this->assertSame( 1, $lb['count'] );
		$this->assertArrayHasKey( 'wpdb', $lb['categories'] );
		$this->assertEqualsWithDelta( 0.4, $lb['categories']['wpdb']['sum_time'], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $lb['categories']['wpdb']['sum_count'], 1e-6 );
	}

	public function test_timed_out_requests_excluded_from_timing_but_counted(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// error_status='T' means timed-out; duration is synthetic. Must not
		// pollute the timing/leaderboard sums.
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 99999.0, 'error_status' => 'T' ] ) );
		$fb->flush();

		$hourly = $store->get_hourly();
		// hourly gets count++ and sum_ms is incremented only for has_timing requests.
		// With T-status, count stays 0 (since has_timing is false).
		foreach ( $hourly as $bucket => $stats ) {
			$this->assertSame( 0, $stats['count'], 'timed-out excluded from count' );
			$this->assertSame( 0, $stats['sum_ms'], 'timed-out excluded from sum_ms' );
		}
	}

	/**
	 * An ABORTED request was killed partway — a worker cut off mid-job, or a
	 * gyrobase render whose lease was stolen — so its duration is a fragment of
	 * the real one. Counting it drags every percentile down and invents fast
	 * requests that never happened, exactly like the timed-out case above.
	 */
	public function test_aborted_excluded_from_timing(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 12.0, 'error_status' => 'A' ] ) );
		$fb->flush();

		foreach ( $store->get_hourly() as $stats ) {
			$this->assertSame( 0, $stats['count'], 'aborted excluded from count' );
			$this->assertSame( 0, $stats['sum_ms'], 'aborted excluded from sum_ms' );
		}
	}

	public function test_workers_excluded_from_timing(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 100.0, 'is_worker' => true ] ) );
		$fb->flush();

		$hourly = $store->get_hourly();
		foreach ( $hourly as $bucket => $stats ) {
			$this->assertSame( 0, $stats['count'], 'workers excluded from timing count' );
		}
	}

	public function test_worker_request_records_url_timing_but_no_global(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		$req = $this->completed_request( [
			'url'         => '/?cache-cozy',
			'duration_ms' => 40.0,
			'is_worker'   => true,
			'peak_mb'     => 12.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.2, 'count' => 3, 'ts' => $now, 'entries' => [] ] ],
		] );
		$this->fill_request( $fb, $req );
		$fb->flush();

		$bucket   = $this->bucket_key_for( $now );
		$url_hash = Log_Manager::url_hash( '/?cache-cozy' );

		// Per-URL timing IS kept for the synthetic worker row.
		$index = $store->get_url_index_hourly( $bucket );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertSame( 1, $index[ $url_hash ]['count'] );
		$this->assertSame( 1, $index[ $url_hash ]['timed_count'] );
		$this->assertEqualsWithDelta( 40.0, $index[ $url_hash ]['sum_ms'], 1e-6 );

		// Global hourly: no count, no timing, and no peak (closed leak).
		foreach ( $store->get_hourly() as $stats ) {
			$this->assertSame( 0, $stats['count'], 'worker excluded from global count' );
			$this->assertSame( 0, $stats['sum_ms'], 'worker excluded from global timing' );
			$this->assertSame( 0, $stats['sum_peak_mb'], 'worker peak leak closed' );
		}

		// Global dimensional: no count and no peak contribution.
		$dim = $store->get_dimensional( 'status' );
		foreach ( $dim as $vals ) {
			foreach ( $vals as $cell ) {
				$this->assertSame( 0, $cell['c'], 'worker excluded from global dimensional count' );
				$this->assertSame( 0, $cell['m'], 'worker excluded from global dimensional peak' );
			}
		}

		// Global leaderboard + categories: untouched by the worker.
		$this->assertEmpty( $store->get_leaderboard_bucket( $bucket ) );
		$this->assertEmpty( $store->get_categories()[ $bucket ] ?? [] );
	}

	public function test_non_worker_request_records_global_count_and_peak(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/x', 'duration_ms' => 40.0, 'peak_mb' => 12.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$hourly = $store->get_hourly();
		$bucket = \array_keys( $hourly )[0];
		$this->assertSame( 1, $hourly[ $bucket ]['count'] );
		$this->assertEqualsWithDelta( 40.0, $hourly[ $bucket ]['sum_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $hourly[ $bucket ]['sum_peak_mb'], 1e-6 );
	}

	public function test_per_server_tracking_only_when_hub(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );

		$fb_spoke = new Flame_Builder_Node();
		$fb_spoke->set_stats_store( $store );
		$fb_spoke->set_is_hub( false );

		$fb_hub = new Flame_Builder_Node();
		$fb_hub->set_stats_store( $store );
		$fb_hub->set_is_hub( true );

		// Use current time so the bucket alignment between fill and assertion is exact.
		$now = \time();
		$req = $this->completed_request( [
			'server_name' => 'srv-a',
			'duration_ms' => 50.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] );

		$this->fill_request( $fb_spoke, $req );
		$fb_spoke->flush();

		// Spoke: per-server bucket should be empty (no per-server tracking).
		$bucket = $this->bucket_key_for( $now );
		$this->assertEmpty( $store->get_server_leaderboard_bucket( 'srv-a', $bucket ) );

		// Hub: per-server bucket should be populated.
		$this->fill_request( $fb_hub, $req );
		$fb_hub->flush();
		$lb_s = $store->get_server_leaderboard_bucket( 'srv-a', $bucket );
		$this->assertSame( 1, $lb_s['count'] ?? 0 );
	}

	// --- Auto-tune --------------------------------------------------------

	public function test_disable_uses_the_stamped_rules_threshold(): void {
		// The governing rule's threshold — resolved by the request's stamped
		// rule_id — decides, and the proposal is keyed under that rule id.
		$this->set_rule( [ 'id' => 'loud', 'pattern' => '/loud/', 'auto_disable_threshold' => 100 ] );
		$fb  = new Flame_Builder_Node();
		$req = $this->completed_request( [
			'rule_id'  => 'loud',
			'url'      => '/loud/x',
			'profiles' => [ 'noisy_hook hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'noisy_hook' ], $state['hooks']['loud'] );
	}

	public function test_no_stamped_rule_applies_no_auto_tune(): void {
		// Stale stamped id + a url that matches no rule ⇒ null rule ⇒ no tuning.
		$this->set_rule( [ 'id' => 'quiet', 'pattern' => '/q/' ] );
		$fb  = new Flame_Builder_Node();
		$req = $this->completed_request( [
			'rule_id'  => 'ghost',
			'url'      => '/unmatched',
			'profiles' => [ 'h hook' => [ 'time' => 0.1, 'count' => 999, 'entries' => [] ] ],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['hooks'] );
	}

	public function test_noisy_hook_detection_threshold_zero_disables_check(): void {
		// With threshold 0, no hook ever gets proposed.
		$this->set_rule( [ 'auto_disable_threshold' => 0 ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'profiles' => [
				'wpdb' => [ 'time' => 0.4, 'count' => 99999, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['hooks'] );
	}

	public function test_noisy_hook_detection_proposes_when_count_exceeds_threshold(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'profiles' => [
				// "wpdb" with count > 100 → base name "wpdb" proposed for disable.
				'wpdb hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'wpdb' ], $state['hooks']['r'] );
	}

	public function test_noisy_hook_detection_excludes_worker_requests(): void {
		// Auto-disable is a global signal: worker traffic feeds only its own
		// per-URL row, never the plugin-wide hooks_to_disable decision.
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'is_worker' => true,
			'profiles'  => [
				'wpdb hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['hooks'], 'worker traffic must not drive global hook auto-disable' );
	}

	public function test_callback_categories_skipped_from_auto_tune(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'profiles' => [
				// callback frames end with " @N" — not independent events.
				'wpdb @10' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['hooks'], 'callback categories never proposed' );
	}

	public function test_significant_event_detection_picks_up_slow_avg(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] ); // 50ms threshold
		$fb = new Flame_Builder_Node();

		// avg_per_call = sum_time / sum_count = 0.4/2 = 0.2 ≥ 0.05 → significant.
		$req = $this->completed_request( [
			'profiles' => [
				'slow_hook' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'slow_hook' ], $state['new_significant']['r'] );
	}

	// --- Index format -----------------------------------------------------

	public function test_format_and_parse_flame_index_round_trip(): void {
		// The formatter receives the unpacked message array; VALUE at index 6.
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::VALUE ]     = [ 'rid' => 'abc', 'url_hash' => 'deadbeef0001' ];
		$position = [ 'segment' => 5, 'offset' => 1024, 'length' => 100 ];
		$entry    = Flame_Builder_Node::format_index_entry( $message, $position );
		$this->assertNotNull( $entry );
		$this->assertSame( 68, \strlen( $entry ) );

		$parsed = Flame_Builder_Node::parse_flame_index( $entry );
		$this->assertSame( 'abc', $parsed['rid'] );
		$this->assertSame( 'deadbeef0001', $parsed['url_hash'] );
		$this->assertSame( 5, $parsed['segment'] );
		$this->assertSame( 1024, $parsed['offset'] );
		$this->assertSame( 100, $parsed['length'] );
	}

	public function test_format_index_entry_returns_null_when_rid_missing(): void {
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::VALUE ]     = [ 'url_hash' => 'abc' ];
		$position = [ 'segment' => 0, 'offset' => 0, 'length' => 0 ];
		$this->assertNull( Flame_Builder_Node::format_index_entry( $message, $position ) );
	}

	public function test_parse_flame_index_returns_null_for_short_lines(): void {
		$this->assertNull( Flame_Builder_Node::parse_flame_index( 'too-short' ) );
	}

	public function test_format_index_entry_handles_deeply_nested_flame(): void {
		// A MAX_STACK_DEPTH (50) deeply-nested flame VALUE: the formatter reads the
		// already-unpacked message array, so there is no json_decode depth to exceed.
		$flame = [ 'name' => 'leaf', 'value' => 1, 'children' => [] ];
		for ( $i = 0; $i < 49; $i++ ) {
			$flame = [ 'name' => "level{$i}", 'value' => 1, 'children' => [ $flame ] ];
		}
		$flame['rid']      = 'deep-rid';
		$flame['url_hash'] = 'deadbeef0001';

		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = $flame;

		$position = [ 'segment' => 0, 'offset' => 0, 'length' => 100 ];
		$entry    = Flame_Builder_Node::format_index_entry( $message, $position );
		$this->assertNotNull( $entry );
		$this->assertSame( 'deep-rid', Flame_Builder_Node::parse_flame_index( $entry )['rid'] );
	}

	// --- save/restore state -----------------------------------------------

	public function test_save_and_restore_pending_state_round_trip(): void {
		$fb = new Flame_Builder_Node();
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 50.0,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );

		$saved = $fb->save_state();
		$this->assertArrayHasKey( 'pending_bucket', $saved );
		$this->assertArrayHasKey( 'pending', $saved );

		$fb2 = new Flame_Builder_Node();
		$fb2->restore_state( $saved );
		$saved2 = $fb2->save_state();
		$this->assertSame( $saved['pending_bucket'], $saved2['pending_bucket'] );
		$this->assertSame( $saved['pending']['hourly'], $saved2['pending']['hourly'] );
	}

	// --- finalize_flame_node ----------------------------------------------

	public function test_finalize_normalizes_parent_value_to_at_least_children_sum(): void {
		// floating-point asymmetry: parent is slightly less than child sum.
		$node = [
			'name'      => 'parent',
			'sum_value' => 4.0,
			'count'     => 1,
			'children'  => [
				[ 'name' => 'a', 'sum_value' => 3.0, 'children' => [] ],
				[ 'name' => 'b', 'sum_value' => 3.0, 'children' => [] ],
			],
		];
		Flame_Tree::finalize_flame_node( $node, 1 );
		$this->assertEqualsWithDelta( 6.0, $node['value'], 1e-6 );
	}

	public function test_flush_without_stats_store_does_not_throw(): void {
		// In test mode (no store), flush() still drains state but writes nowhere.
		$fb = new Flame_Builder_Node();
		$this->fill_request( $fb, $this->completed_request() );
		$fb->flush(); // must not throw
		$this->assertSame( 0, $this->stats_count( $fb ) );
	}

	public function test_finalize_strips_internal_fields(): void {
		$node = [
			'name'      => 'root',
			'sum_value' => 5.0,
			'seen_count' => 1,
			'ts'        => 12345,
			'count'     => 1,
			'children'  => [],
		];
		Flame_Tree::finalize_flame_node( $node, 1 );
		$this->assertArrayNotHasKey( 'sum_value', $node );
		$this->assertArrayNotHasKey( 'seen_count', $node );
		$this->assertArrayNotHasKey( 'ts', $node );
	}

	// ── A3: sibling-interpreter + verbs ─────────────────────────────────

	public function test_flame_builder_constructs_sibling_interpreter(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertNotNull( $this->read_private( $fb, 'interpreter' ) );
		$this->assertSame( 'fb:config', $this->read_private( $fb, 'interpreter' )->name() );
	}

	public function test_flame_builder_set_is_hub_verb_round_trips(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertSame( 'ok', $this->read_private( $fb, 'interpreter' )->dispatch( 'set_is_hub', [ 'true' ] ) );
		$dump = $fb->dump_config();
		$this->assertStringContainsString( 'cmd fb:config set_is_hub true', $dump );
	}

	public function test_flame_builder_node_schema_declares_verbs(): void {
		$schema = Flame_Builder_Node::node_schema();
		$this->assertSame( 'Transform', $schema['category'] );
		$verb_names = \array_column( $schema['commands'], 'name' );
		$this->assertContains( 'set_is_hub', $verb_names );
		$this->assertContains( 'configure_stats', $verb_names );
		// Node-wide auto-tune verbs were removed in favor of per-rule thresholds.
		$this->assertNotContains( 'set_auto_tune', $verb_names );
		$this->assertNotContains( 'set_significant_events', $verb_names );
	}

	// --- Clock seam / maintenance / non-stats setters ---------------------

	public function test_set_clock_drives_bucket_key_derivation(): void {
		// Use a fixed clock so we can pin the bucket key without timing flake.
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$fixed = 1_700_000_000; // Stable; floor to 5-min bucket.
		$fb->set_clock( static fn() => $fixed );

		$req = $this->completed_request( [
			'duration_ms' => 25.0,
			'timestamp'   => $fixed,
		] );
		$this->fill_request( $fb, $req );
		$fb->flush();

		$bucket = $this->bucket_key_for( $fixed );
		$hourly = $store->get_hourly();
		$this->assertArrayHasKey( $bucket, $hourly );
		$this->assertSame( 1, $hourly[ $bucket ]['count'] );

		// Restoring the clock to null returns to wall-clock time.
		$fb->set_clock( null );
	}

	public function test_fill_triggers_flush_when_interval_elapsed(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// Fill first, then backdate last_flush_time AFTER (fill() itself can
		// trigger a flush if last_flush_time is already old). This way the
		// flush we observe comes only from the second fill() call.
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 12.0 ] ) );

		$ref = new \ReflectionProperty( Flame_Builder_Node::class, 'last_flush_time' );
		$ref->setValue( $fb, \microtime( true ) - ( Flame_Builder_Node::FLUSH_INTERVAL_SEC + 1 ) );

		// Confirm hourly is NOT yet persisted (last fill happened mid-window).
		$hourly_before = $store->get_hourly();

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 13.0 ] ) );

		// After the interval-triggered fill: hourly persisted to store.
		$hourly_after = $store->get_hourly();
		$this->assertNotEquals( $hourly_before, $hourly_after, 'fill flushed pending bucket' );
		$this->assertNotEmpty( $hourly_after );
	}

	public function test_set_custom_event_names_dispatches_to_custom_events(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 50 ] );
		$fb = new Flame_Builder_Node();
		$fb->set_custom_event_names( [ 'mything', 'other' ] );

		$req = $this->completed_request( [
			'profiles' => [
				// "mything" is in custom event names — should route to custom_events_to_disable.
				'mything hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
				// "wp_init" is NOT a custom event — should route to hooks_to_disable.
				'wp_init hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'mything' ], $state['custom_events']['r'] );
		$this->assertSame( [ 'wp_init' ], $state['hooks']['r'] );
	}

	public function test_rule_significant_events_suppress_redundant_proposals(): void {
		// An event already declared significant on the governing rule is not
		// re-flagged when its avg-time detection would otherwise promote it.
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05, 'significant_events' => [ 'wpdb' ] ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'profiles' => [
				// avg = 0.2 ≥ 0.05 → would be significant, but already known.
				'wpdb' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['new_significant'], 'already-significant event not re-flagged' );
	}

	// --- restore_state edge cases -----------------------------------------

	public function test_restore_state_ignores_non_string_pending_bucket(): void {
		$fb = new Flame_Builder_Node();
		$fb->restore_state( [ 'pending_bucket' => 12345 ] );
		$saved = $fb->save_state();
		// Default is empty string.
		$this->assertSame( '', $saved['pending_bucket'] );
	}

	public function test_restore_state_ignores_non_array_pending(): void {
		$fb = new Flame_Builder_Node();
		$fb->restore_state( [ 'pending' => 'not-an-array' ] );
		$saved = $fb->save_state();
		// Pending keeps its initialized shape.
		$this->assertIsArray( $saved['pending'] );
		$this->assertArrayHasKey( 'hourly', $saved['pending'] );
	}

	public function test_restore_state_merges_pending_array(): void {
		$fb = new Flame_Builder_Node();
		// Manually craft pending payload.
		$fb->restore_state( [
			'pending_bucket' => '2024-01-01-12-00',
			'pending'        => [
				'hourly' => [ 'count' => 7, 'sum_ms' => 700, 'sum_peak_mb' => 21 ],
			],
		] );
		$saved = $fb->save_state();
		$this->assertSame( '2024-01-01-12-00', $saved['pending_bucket'] );
		$this->assertSame( 7, $saved['pending']['hourly']['count'] );
	}

	// --- handle_request (TM_REQUEST GET_STATS) ----------------------------

	public function test_handle_request_get_stats_returns_payload(): void {
		// A low per-rule time threshold promotes both seeded hooks so
		// significant_events_count (now summed across the per-rule cache) is 2.
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_is_hub( true );

		// Seed some state; two distinct slow hooks → two significant promotions.
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 30.0,
			'profiles'    => [
				'init'      => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ],
				'wp_loaded' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ],
			],
		] ) );

		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_REQUEST;
		$message[ Message::FROM ]      = 'caller';
		$message[ Message::ID ]        = 'req-1';
		$message[ Message::KEY ]       = 'k-1';
		$message[ Message::VALUE ]     = 'GET_STATS';
		$fb->fill( $message );

		$reply = null;
		foreach ( $capture->captured as $captured ) {
			$type = $captured[ Message::TYPE ];
			if ( ( $type & Message::TM_RESPONSE ) && ( $type & Message::TM_STRUCT ) ) {
				$reply = $captured;
				break;
			}
		}
		$this->assertNotNull( $reply, 'GET_STATS response emitted' );
		$this->assertSame( 'caller', $reply[ Message::TO ], 'reply addresses original FROM' );
		$this->assertSame( 'req-1', $reply[ Message::ID ], 'reply carries original ID' );

		$payload = $reply[ Message::VALUE ];
		$this->assertSame( 'GET_STATS', $payload['verb'] );
		$this->assertArrayHasKey( 'stats_count', $payload['data'] );
		$this->assertArrayHasKey( 'pending_url_count', $payload['data'] );
		$this->assertArrayHasKey( 'auto_tune_pending_count', $payload['data'] );
		$this->assertTrue( $payload['data']['is_hub'] );
		$this->assertSame( 2, $payload['data']['significant_events_count'] );
	}

	public function test_pending_url_count_reports_distinct_urls(): void {
		$this->set_rule();
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );

		foreach ( [ '/alpha', '/beta', '/gamma' ] as $url ) {
			$this->fill_request( $fb, $this->completed_request( [ 'url' => $url ] ) );
		}

		// Three distinct URLs — not the ten fixed accumulator keys.
		$this->assertSame( 3, $this->get_stats( $fb )['pending_url_count'] );
	}

	public function test_intern_table_freezes_for_entry_names_at_the_cap(): void {
		$this->set_rule();
		$fb = new TinyInternFlameBuilder();
		$fb->name( 'fb' );
		$fb->sink( new Capture_Sink_Node() );

		// One request is enough to reach a cap of three and freeze the table.
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/warm' ] ) );
		$stats = $this->get_stats( $fb );
		$this->assertArrayHasKey( 'intern_count', $stats );
		$frozen_at = $stats['intern_count'];

		$entries = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$entries[ "hook_number_{$i}" ] = [ 1.5, 1 ];
		}
		$this->fill_request(
			$fb,
			$this->completed_request(
				[ 'profiles' => [ 'plugins_loaded' => [ 'entries' => $entries, 'count' => 1, 'time' => 30.0 ] ] ]
			)
		);

		// Entry names are the highest-cardinality strings; a frozen table takes none.
		$this->assertSame( $frozen_at, $this->get_stats( $fb )['intern_count'] );
	}

	public function test_a_custom_event_named_total_does_not_corrupt_the_rollup_row(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$this->set_rule();
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// 'total' is the reserved rollup key; a custom event may be named anything.
		$this->fill_request(
			$fb,
			$this->completed_request(
				[
					'duration_ms' => 250.0,
					'profiles'    => [
						'total'  => [ 'entries' => [], 'count' => 7, 'time' => 40.0 ],
						'wpdb'   => [ 'entries' => [], 'count' => 3, 'time' => 10.0 ],
					],
				]
			)
		);
		$fb->flush();

		$buckets = $store->get_categories();
		$this->assertNotEmpty( $buckets, 'category series written' );
		$bucket = \reset( $buckets );

		// One request in the bucket, whatever the incoming categories are called.
		$this->assertSame( 1, $bucket['total']['n'], 'rollup counts requests, not categories' );
		$this->assertEqualsWithDelta( 250.0, $bucket['total']['t'], 1e-6, 'rollup time is request wall time' );
		$this->assertEqualsWithDelta( 10.0, $bucket['total']['c'], 1e-6, 'rollup counts every category call' );

		// The colliding event still gets its own row, under a distinct key.
		$this->assertArrayHasKey( 'wpdb', $bucket );
		$rows = \array_diff( \array_keys( $bucket ), [ 'total', 'wpdb' ] );
		$this->assertCount( 1, $rows, 'the colliding event keeps a row of its own' );
		$own = $bucket[ \reset( $rows ) ];
		$this->assertEqualsWithDelta( 40.0, $own['t'], 1e-6 );
		$this->assertEqualsWithDelta( 7.0, $own['c'], 1e-6 );
	}

	public function test_handle_request_unknown_verb_returns_error(): void {
		$fb                    = new Flame_Builder_Node();
		$capture               = new Capture_Sink_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::FROM ]  = 'caller';
		$message[ Message::ID ]    = 'req-2';
		$message[ Message::VALUE ] = 'NONSENSE_VERB';
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->fill( $message );
		$reply = $capture->captured[0];
		$this->assertStringContainsString( 'unknown request verb', $reply[ Message::VALUE ]['data']['error'] );
		$this->assertSame( 'NONSENSE_VERB', $reply[ Message::VALUE ]['verb'] );
	}

	public function test_response_messages_dont_trigger_handle_request(): void {
		// TM_REQUEST | TM_RESPONSE should skip handle_request (it's a reply, not a request).
		$fb                    = new Flame_Builder_Node();
		$capture               = new Capture_Sink_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST | Message::TM_RESPONSE;
		$message[ Message::VALUE ] = 'GET_STATS';
		$fb->sink( $capture );
		$fb->fill( $message );
		$this->assertSame( 0, $this->stats_count( $fb ), 'response not processed as request' );
		$this->assertCount( 1, $capture->captured );
	}

	// --- Auto-tune fire actions + memcache lock ---------------------------

	public function test_apply_auto_tune_emits_messages_via_sink(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );

		$req = $this->completed_request( [
			'profiles' => [
				'noisy hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		// flush() triggers apply_auto_tune → fire_auto_tune_actions → emit_auto_tune.
		$fb->flush();

		// Find the auto-tune emit (disable_hooks with non-empty items).
		$auto_tune_msgs = \array_filter(
			$capture->captured,
			static fn( $m ) =>
				'disable_hooks' === ( $m[ Message::KEY ] ?? '' )
				&& \is_array( $m[ Message::VALUE ] ?? null )
				&& ! empty( $m[ Message::VALUE ]['items'] ?? [] )
		);
		$this->assertNotEmpty( $auto_tune_msgs );
		$first = \array_values( $auto_tune_msgs )[0];
		$this->assertSame( 'fb:auto-tuner', $first[ Message::TO ] );
		$this->assertSame( [ 'noisy' ], $first[ Message::VALUE ]['items'] );
		$this->assertSame( 'r', $first[ Message::VALUE ]['rule_id'] );
	}

	public function test_apply_auto_tune_with_store_uses_memcache_lock(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );

		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );

		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'spam hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] ) );
		$fb->flush();

		// Lock should have been added and released (no leftover entry under that key).
		$this->assertNotContains( self::scoped( 'evlog:auto_disable_lock' ), $mc->keys() );

		// And the emit fired through to sink.
		$auto_tune_msgs = \array_filter(
			$capture->captured,
			static fn( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' )
		);
		$this->assertNotEmpty( $auto_tune_msgs );
	}

	public function test_apply_auto_tune_skipped_when_lock_held(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		// Pre-occupy the lock as if a sibling worker holds it.
		$mc->add( self::scoped( 'evlog:auto_disable_lock' ), 'someone-else', 60 );
		$store = new Stats_Store( partition: 0, max_lifespan: 86400 );

		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );

		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [ 'spammy hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		// No disable_hooks emit because the lock is held by someone else.
		$disable_msgs = \array_filter(
			$capture->captured,
			static fn( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' )
		);
		$this->assertEmpty( $disable_msgs );

		// And the pending queue is still loaded — proves we early-returned, not consumed.
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'spammy' ], $state['hooks']['r'] );
	}

	public function test_apply_auto_tune_no_op_when_queues_empty(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );

		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );

		// No auto-tune state set, just a basic flush.
		$fb->flush();

		// Lock not touched at all.
		$this->assertEmpty( $mc->keys(), 'no lock or other keys written' );
		// No auto-tune emits (only the flush has nothing to emit).
		foreach ( $capture->captured as $m ) {
			$this->assertNotContains( $m[ Message::KEY ], [ 'disable_hooks', 'disable_custom_events', 'add_significant_events' ] );
		}
	}

	public function test_emit_auto_tune_no_op_when_no_sink(): void {
		// Sink-less FlameBuilder still completes flush without crashing.
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		// No sink attached.
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [ 'a hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] ) );
		// Should not throw — just drops the emit silently.
		$fb->flush();
		// Auto-tune queues drained after flush.
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [], $state['hooks'] );
	}

	// --- persist_aggregate_stats internals: hourly expiration, percentiles --

	public function test_hourly_expiration_drops_buckets_outside_retention(): void {
		// Use a tight retention (1 hour) and seed an in-memory hourly entry with a far-past bucket.
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 3600 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// Pre-populate hourly with a very old bucket via Stats_Store directly.
		$store->set_hourly( [
			'1999-01-01-00-00' => [ 'count' => 99, 'sum_ms' => 9900, 'sum_peak_mb' => 9 ],
		] );

		// Run a fresh request — flush will merge, then prune.
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 5.0 ] ) );
		$fb->flush();

		$hourly = $store->get_hourly();
		$this->assertArrayNotHasKey(
			'1999-01-01-00-00',
			$hourly,
			'old bucket expired by retention cutoff'
		);
	}

	public function test_url_index_computes_percentiles_from_durations(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// Drive 100 requests at the same URL with monotonic durations.
		$now = \time();
		for ( $i = 1; $i <= 100; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => '/p50',
				'duration_ms' => (float) $i,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$bucket    = $this->bucket_key_for( $now );
		$index     = $store->get_url_index_hourly( $bucket );
		$url_hash  = Log_Manager::url_hash( '/p50' );
		$this->assertArrayHasKey( $url_hash, $index );
		$stats     = $index[ $url_hash ];
		$this->assertGreaterThan( 0, $stats['p50_ms'] );
		$this->assertGreaterThanOrEqual( $stats['p50_ms'], $stats['p95_ms'] );
		$this->assertGreaterThanOrEqual( $stats['p95_ms'], $stats['p99_ms'] );
		$this->assertGreaterThan( 0, $stats['avg_ms'] );
	}

	public function test_url_index_caps_at_500_keeps_top_by_count(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		// Use 510 distinct URLs to trigger the 500-entry cap.
		for ( $i = 0; $i < 510; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => "/path-$i",
				'duration_ms' => 1.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$bucket = $this->bucket_key_for( $now );
		$index  = $store->get_url_index_hourly( $bucket );
		$this->assertLessThanOrEqual( 500, \count( $index ) );
	}

	// --- merge_and_cap_dimensional Other rollover -------------------------

	public function test_dim_other_rollover_when_too_many_values(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		// 30 distinct user agents → exceeds MAX_DIM_VALUES (20) → Other rollover.
		for ( $i = 0; $i < 30; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'user_agent'  => "UA-$i",
				'duration_ms' => 10.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$dim    = $store->get_dimensional( 'ua' );
		$bucket = $this->bucket_key_for( $now );
		$this->assertArrayHasKey( $bucket, $dim );
		$this->assertLessThanOrEqual( Stats_Store::MAX_DIM_VALUES, \count( $dim[ $bucket ] ) );
		$this->assertArrayHasKey( 'Other', $dim[ $bucket ], 'low-frequency entries roll into Other' );
		$this->assertGreaterThan( 0, $dim[ $bucket ]['Other']['c'] );
	}

	public function test_url_dim_other_rollover_uses_tighter_cap(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		// 15 distinct UAs on the SAME URL → exceeds MAX_URL_DIM_VALUES (10) → Other rollover.
		for ( $i = 0; $i < 15; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => '/shared',
				'user_agent'  => "ShUA-$i",
				'duration_ms' => 10.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$url_hash = Log_Manager::url_hash( '/shared' );
		$url_dim  = $store->get_url_dimensional( $url_hash );
		$this->assertArrayHasKey( 'ua', $url_dim );
		$bucket = $this->bucket_key_for( $now );
		$this->assertArrayHasKey( $bucket, $url_dim['ua'] );
		$this->assertLessThanOrEqual( Stats_Store::MAX_URL_DIM_VALUES, \count( $url_dim['ua'][ $bucket ] ) );
	}

	// --- merge_and_cap_categories: Other rollover + total preserved -------

	public function test_categories_other_rollover_preserves_total(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		// 60 distinct categories → exceeds MAX_CAT_VALUES (50) → Other rollover.
		$profiles = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$profiles[ "cat$i" ] = [ 'time' => 0.01, 'count' => 1, 'entries' => [] ];
		}
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 50.0,
			'timestamp'   => $now,
			'profiles'    => $profiles,
		] ) );
		$fb->flush();

		$cats   = $store->get_categories();
		$bucket = $this->bucket_key_for( $now );
		$this->assertArrayHasKey( $bucket, $cats );
		$this->assertLessThanOrEqual( Stats_Store::MAX_CAT_VALUES, \count( $cats[ $bucket ] ) );
		$this->assertArrayHasKey( 'total', $cats[ $bucket ], '"total" pseudo-category preserved' );
		$this->assertArrayHasKey( 'Other', $cats[ $bucket ], 'overflow rolls into Other' );
	}

	public function test_categories_expiration_drops_old_buckets(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 3600 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// Pre-seed an old bucket.
		$store->set_categories( [
			'1999-01-01-00-00' => [
				'total' => [ 't' => 99, 'c' => 99, 'n' => 99 ],
				'old'   => [ 't' => 99, 'c' => 99, 'n' => 99 ],
			],
		] );

		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 5.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		$cats = $store->get_categories();
		$this->assertArrayNotHasKey( '1999-01-01-00-00', $cats, 'old category bucket expired' );
	}

	// --- Per-server leaderboard merge + cap (hub mode) --------------------

	public function test_per_server_leaderboard_cap_global_upper_bound(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		$now = \time();
		// Generate >100 distinct entry names under one category for one server.
		$entries = [];
		for ( $i = 0; $i < 120; $i++ ) {
			$entries[ "stmt-$i" ] = [ 0.01, 1 ];
		}
		$this->fill_request( $fb, $this->completed_request( [
			'server_name' => 'srv-cap',
			'duration_ms' => 100.0,
			'timestamp'   => $now,
			'profiles'    => [
				'wpdb' => [ 'time' => 0.4, 'count' => 12, 'entries' => $entries ],
			],
		] ) );
		$fb->flush();

		$bucket = $this->bucket_key_for( $now );
		$lb_s   = $store->get_server_leaderboard_bucket( 'srv-cap', $bucket );
		$this->assertArrayHasKey( 'wpdb', $lb_s['categories'] );
		$this->assertLessThanOrEqual(
			Flame_Builder_Node::ENTRY_LIMIT_GLOBAL_UPPER,
			\count( $lb_s['categories']['wpdb']['entries'] )
		);
	}

	public function test_hub_mode_per_server_categories_tracked(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [
			'server_name' => 'srv-cat',
			'duration_ms' => 50.0,
			'timestamp'   => $now,
			'profiles'    => [
				'wpdb' => [ 'time' => 0.4, 'count' => 12, 'ts' => $now, 'entries' => [ 'SELECT' => [ 0.3, 8 ] ] ],
			],
		] ) );
		$fb->flush();

		$bucket    = $this->bucket_key_for( $now );
		$srv_cats  = $store->get_server_categories( 'srv-cat' );
		$this->assertArrayHasKey( $bucket, $srv_cats );
		$this->assertArrayHasKey( 'wpdb', $srv_cats[ $bucket ] );
		$this->assertArrayHasKey( 'total', $srv_cats[ $bucket ], 'per-server "total" present' );
		$this->assertEqualsWithDelta( 0.4, $srv_cats[ $bucket ]['wpdb']['t'], 1e-6 );
	}

	public function test_hub_mode_per_server_dim_skips_server_dim(): void {
		// In hub mode, per-server tracking should be populated for non-server dimensions
		// (status, method, country, etc.) but NOT for the 'server' dimension (redundant).
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [
			'server_name'  => 'srv-x',
			'request_method' => 'POST',
			'duration_ms'  => 25.0,
			'timestamp'    => $now,
		] ) );
		$fb->flush();

		// Per-server dim under 'method' should be populated.
		$dim_method = $store->get_dimensional( 'method', 'srv-x' );
		$this->assertNotEmpty( $dim_method );

		// Per-server dim under 'server' should be EMPTY (skipped).
		$dim_server = $store->get_dimensional( 'server', 'srv-x' );
		$this->assertEmpty( $dim_server, "per-server 'server' dim is skipped" );
	}

	// --- Per-URL aggregate flame migration paths --------------------------

	public function test_legacy_ema_flame_shape_migrated_on_load(): void {
		// Pre-seed the store with the legacy EMA-style shape (no sum_value).
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$url      = '/legacy';
		$url_hash = Log_Manager::url_hash( $url );
		$store->set_url_stats( $url_hash, [
			'flame'    => [
				'name'     => 'aggregate',
				'value'    => 42.0, // Legacy EMA running mean — no sum_value.
				'children' => [],
			],
			'profiles' => [
				'count' => 5, // Legacy: no sum_req_time.
			],
		] );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [
			'url'         => $url,
			'duration_ms' => 100.0,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.2, 'count' => 1, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		$stats = $store->get_url_stats( $url_hash );
		$this->assertNotNull( $stats );
		// flame_raw should hold the sums (post-migration), flame is finalized.
		$this->assertArrayHasKey( 'flame_raw', $stats );
		$this->assertArrayHasKey( 'sum_value', $stats['flame_raw'] );
		$this->assertEqualsWithDelta( 100.0, $stats['flame_raw']['sum_value'], 1e-6 );
		// Legacy profiles migrated too.
		$this->assertArrayHasKey( 'sum_req_time', $stats['profiles'] );
	}

	public function test_flame_raw_promoted_to_flame_on_reload(): void {
		// Set store with an entry that has flame_raw set (post-flush format).
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$url      = '/promoted';
		$url_hash = Log_Manager::url_hash( $url );
		$store->set_url_stats( $url_hash, [
			'flame_raw' => [
				'name'      => 'aggregate',
				'sum_value' => 300.0,
				'count'     => 3,
				'children'  => [],
			],
			'flame'     => [ /* finalized for display */
				'name'  => 'aggregate',
				'value' => 100.0,
				'count' => 3,
			],
			'profiles'  => [
				'count'        => 0,
				'sum_req_time' => 0.0,
				'categories'   => [],
			],
		] );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// Hit the URL once more.
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => $url,
			'duration_ms' => 100.0,
		] ) );
		$fb->flush();

		$stats = $store->get_url_stats( $url_hash );
		// sum_value should be 300 + 100 = 400 (proves flame_raw was promoted and added to).
		$this->assertEqualsWithDelta( 400.0, $stats['flame_raw']['sum_value'], 1e-6 );
		$this->assertSame( 4, $stats['flame_raw']['count'] );
	}

	// --- Stack depth safety + edge cases of build_flame_data --------------

	public function test_label_and_detail_attached_to_flame_nodes(): void {
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->connect_node( 'flames:partition' );

		$req = $this->completed_request( [
			'duration_ms' => 50.0,
			'entries'     => [
				[
					'k' => 'wpdb query (start)',
					'l' => 'SELECT_USERS',
					'm' => 'SELECT * FROM wp_users WHERE id = 1',
				],
				[ 'k' => 'wpdb query (complete)', 'duration_ms' => 5.0 ],
			],
		] );
		$this->fill_request( $fb, $req );

		$flame = $capture->captured[0][ Message::VALUE ];
		$this->assertNotEmpty( $flame['children'] );
		$child = $flame['children'][0];
		$this->assertSame( 'wpdb query: SELECT_USERS', $child['name'] );
		$this->assertSame( 'wpdb query: SELECT * FROM wp_users WHERE id = 1', $child['detail'] );
	}

	public function test_label_equal_to_detail_skips_detail_field(): void {
		// If label and detail are identical, detail shouldn't be added.
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->connect_node( 'flames:partition' );

		$req = $this->completed_request( [
			'duration_ms' => 30.0,
			'entries'     => [
				[ 'k' => 'foo (start)', 'l' => 'same', 'm' => 'same' ],
				[ 'k' => 'foo (complete)', 'duration_ms' => 1.0 ],
			],
		] );
		$this->fill_request( $fb, $req );

		$child = $capture->captured[0][ Message::VALUE ]['children'][0];
		$this->assertArrayNotHasKey( 'detail', $child, 'detail omitted when equal to label' );
	}

	public function test_store_flame_returns_true_without_target(): void {
		// When target/sink unset, store_flame returns true (aggregation continues)
		// without emitting a message.
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 22.0 ] ) );
		$fb->flush();

		// Hourly was still populated → aggregation occurred even without flame write.
		$this->assertNotEmpty( $store->get_hourly() );
	}

	// --- Plugin-suffix and callback-suffix exclusions ---------------------

	public function test_plugin_suffix_categories_skipped_from_auto_tune(): void {
		// Categories ending with " plugin" are not eligible for auto-tune.
		$this->set_rule( [ 'auto_disable_threshold' => 100, 'auto_protect_time_threshold' => 0.05 ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'profiles' => [
				'foo plugin' => [ 'time' => 0.5, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$state = $fb->get_auto_tune_state();
		$this->assertEmpty( $state['hooks'], 'plugin-suffix categories never proposed' );
		$this->assertEmpty( $state['new_significant'], 'plugin-suffix never significant' );
	}

	public function test_finalize_handles_missing_value_field(): void {
		// Node without value or sum_value falls through to 0.
		$node = [
			'name'     => 'orphan',
			'children' => [],
		];
		Flame_Tree::finalize_flame_node( $node, 0 );
		$this->assertSame( 0, $node['value'] );
	}

	public function test_finalize_normalizes_with_only_some_children_sums(): void {
		$node = [
			'name'      => 'parent',
			'sum_value' => 2.0,
			'count'     => 1,
			'children'  => [
				[ 'name' => 'a', 'sum_value' => 5.0, 'children' => [] ],
				[ 'name' => 'b', 'children' => [] ], // No sum_value.
			],
		];
		Flame_Tree::finalize_flame_node( $node, 1 );
		// Children sum = 5 + 0 = 5; parent had 2 → bumped to 5.
		$this->assertEqualsWithDelta( 5.0, $node['value'], 1e-6 );
	}

	// --- format/parse index edge cases ------------------------------------

	public function test_format_index_entry_truncates_long_rid_and_hash(): void {
		// Long rid + hash should be truncated to 32 and 12 bytes respectively.
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = [
			'rid'      => \str_repeat( 'a', 50 ),
			'url_hash' => \str_repeat( 'b', 30 ),
		];
		$position = [ 'segment' => 0, 'offset' => 0, 'length' => 0 ];
		$entry    = Flame_Builder_Node::format_index_entry( $message, $position );
		$this->assertNotNull( $entry );
		$parsed   = Flame_Builder_Node::parse_flame_index( $entry );
		$this->assertSame( 32, \strlen( $parsed['rid'] ) );
		$this->assertSame( 12, \strlen( $parsed['url_hash'] ) );
	}

	public function test_format_index_entry_rejects_non_array_value(): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$message[ Message::VALUE ] = 'just-a-string';
		$position              = [ 'segment' => 0, 'offset' => 0, 'length' => 0 ];
		$this->assertNull( Flame_Builder_Node::format_index_entry( $message, $position ) );
	}

	// --- handle_request payload includes auto-tune queue depth ------------

	public function test_handle_request_auto_tune_count_reflects_queue(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100, 'auto_protect_time_threshold' => 0.05 ] );
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );

		// Drive both noisy + significant events to populate three queues.
		$req = $this->completed_request( [
			'profiles' => [
				'noisy hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
				'slow'       => [ 'time' => 1.0, 'count' => 2,   'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		// Pre-fill queues — confirm via state.
		$state = $fb->get_auto_tune_state();
		$this->assertNotEmpty( $state['hooks'] );
		$this->assertNotEmpty( $state['new_significant'] );

		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_REQUEST;
		$message[ Message::FROM ]      = 'caller';
		$message[ Message::VALUE ]     = 'GET_STATS';
		$fb->fill( $message );

		// Find the reply.
		$reply = null;
		foreach ( $capture->captured as $m ) {
			$t = $m[ Message::TYPE ];
			if ( ( $t & Message::TM_RESPONSE ) && ( $t & Message::TM_STRUCT ) ) {
				$reply = $m;
				break;
			}
		}
		$this->assertNotNull( $reply );
		$this->assertGreaterThan( 0, $reply[ Message::VALUE ]['data']['auto_tune_pending_count'] );
	}

	// --- Per-server leaderboard tracks count when hub mode + server set ---

	public function test_per_server_leaderboard_skipped_when_server_name_empty(): void {
		// Hub mode but empty server_name → no per-server data.
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [
			'server_name' => '',
			'duration_ms' => 50.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		$bucket = $this->bucket_key_for( $now );
		$this->assertEmpty( $store->get_server_leaderboard_bucket( '', $bucket ) );
	}

	// --- Save state after multiple flushes (idempotency) ------------------

	public function test_double_flush_is_idempotent(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 100.0 ] ) );
		$fb->flush();
		$snap_a = $store->get_hourly();
		// Second flush with nothing pending — should be a no-op for stats.
		$fb->flush();
		$snap_b = $store->get_hourly();
		$this->assertSame( $snap_a, $snap_b, 'second flush does not double-count' );
	}

	// --- Rule 2: sibling is sunk into the interpreter ---------------------

	public function test_sink_propagates_to_auto_tuner_sibling(): void {
		// make_node auto-sinks FlameBuilder into _command_interpreter; the
		// overridden sink() setter must propagate that sink to the owned
		// auto-tuner sibling so it routes like any other sibling (Rule 2c).
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		$capture = new Capture_Sink_Node();
		$fb->sink( $capture );

		$at = Core::node( 'fb:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\Auto_Tuner_Node::class, $at );
		$this->assertSame( $capture, $at->sink(), 'auto-tuner sibling adopts the interpreter sink' );
	}

	public function test_sink_getter_returns_own_sink(): void {
		// The sink() override must still behave as a plain getter when called
		// with no argument (don't accidentally return the sibling's sink).
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$capture = new Capture_Sink_Node();
		$fb->sink( $capture );
		$this->assertSame( $capture, $fb->sink() );
	}

	// --- Durable stats-partition mirror + cold-boot reload ----------------

	public function test_aggregates_buffered_in_full(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		$store->set_hourly( [ '2026-01-01-00' => [ 'count' => 7 ] ] );
		$store->set_leaderboard_bucket( '2026-01-01-00-05', [ 'count' => 3, 'sum_req_time' => 1.5, 'categories' => [] ] );

		$fb->save_state();
		$p->flush();

		$frames = $this->read_mirror_frames( $p );
		$this->assertArrayHasKey( self::scoped( 'evlog:p0:hourly' ), $frames, 'hourly aggregate landed' );
		$this->assertSame( [ '2026-01-01-00' => [ 'count' => 7 ] ], $frames[self::scoped( 'evlog:p0:hourly' )]['data'] );
		$this->assertSame( 86400, $frames[self::scoped( 'evlog:p0:hourly' )]['ttl'] );
		$this->assertArrayHasKey( self::scoped( 'evlog:p0:lb:2026-01-01-00-05' ), $frames, 'leaderboard aggregate landed' );
	}

	public function test_mirror_buffers_until_save_state(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		$store->set_hourly( [ '2026-01-01-00' => [ 'count' => 7 ] ] );
		$p->flush();
		$this->assertArrayNotHasKey( self::scoped( 'evlog:p0:hourly' ), $this->read_mirror_frames( $p ), 'not flushed before save_state' );

		$fb->save_state();
		$p->flush();
		$this->assertArrayHasKey( self::scoped( 'evlog:p0:hourly' ), $this->read_mirror_frames( $p ), 'flushed on save_state' );
	}

	public function test_uncommitted_writes_absent_from_partition(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		// Buffer writes but never checkpoint — a crash loses them, no double-count.
		$store->set_hourly( [ '2026-01-01-00' => [ 'count' => 7 ] ] );
		$store->set_leaderboard_bucket( 'b', [ 'count' => 3, 'sum_req_time' => 1.5, 'categories' => [] ] );
		$p->flush();

		$this->assertSame( [], $this->read_mirror_frames( $p ), 'nothing written until save_state' );
		foreach ( $p->get_segments( true ) as $seg ) {
			$this->assertSame( 0, (int) $seg['size'], 'nothing written until save_state' );
		}
	}

	public function test_url_flame_profiles_not_mirrored_by_default(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		// No set_flame_topn() → the production default of 0: the per-URL flame
		// mirror is OFF, so no `url:` frames persist regardless of traffic.
		for ( $i = 1; $i <= 15; $i++ ) {
			$store->set_url_stats( "h{$i}", [ 'flame' => [ 'count' => $i ] ] );
		}

		$fb->save_state();
		$p->flush();

		$url_keys = $this->url_flame_keys( $p );
		$this->assertCount( 0, $url_keys, 'flame profiles not mirrored at the default top-N of 0' );
	}

	public function test_url_flame_profiles_bounded_to_configured_topn(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );
		$topn = 10;
		$fb->set_flame_topn( $topn );

		// 15 distinct URLs with ascending flame.count — exactly the configured
		// top-N (highest-traffic) survive; the count persisted IS the number
		// configured.
		for ( $i = 1; $i <= 15; $i++ ) {
			$store->set_url_stats( "h{$i}", [ 'flame' => [ 'count' => $i ] ] );
		}

		$fb->save_state();
		$p->flush();

		$url_keys = $this->url_flame_keys( $p );
		$this->assertCount( $topn, $url_keys, 'exactly the configured top-N flame profiles persisted' );
		for ( $i = 16 - $topn; $i <= 15; $i++ ) {
			$this->assertContains( self::scoped( "evlog:p0:url:h{$i}" ), $url_keys );
		}
		$this->assertNotContains( self::scoped( 'evlog:p0:url:h5' ), $url_keys, 'lowest-traffic URL evicted' );
	}

	public function test_url_dim_and_url_cat_bounded_to_top_100(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		// 105 distinct URLs, highest-traffic inserted FIRST (rank DESCENDING) so eviction
		// order != insertion order — a rank that misreads the value shape would fall back to
		// evict-by-insertion and keep the wrong 100. Real persisted shapes: url_dim is
		// { dim => { bucket => { val => {c,s,m} } } }; url_cat is { bucket => { category => {t,c,n}, total => {t,c,n} } }.
		for ( $i = 1; $i <= 105; $i++ ) {
			$rank = 106 - $i; // h1 busiest (105), h105 quietest (1).
			$store->set_url_dimensional( "h{$i}", [ 'status' => [ '1700000000' => [ '200' => [ 'c' => $rank, 's' => 0, 'm' => 0 ] ] ] ] );
			$store->set_url_categories( "h{$i}", [ '1700000000' => [ 'db' => [ 't' => 0, 'c' => 0, 'n' => $rank ], 'total' => [ 't' => 0, 'c' => 0, 'n' => $rank ] ] ] );
		}

		$fb->save_state();
		$p->flush();

		$frames    = \array_keys( $this->read_mirror_frames( $p ) );
		$dim_keys  = \array_filter( $frames, static fn ( string $k ): bool => \str_starts_with( $k, self::scoped( 'evlog:p0:url_dim:' ) ) );
		$cat_keys  = \array_filter( $frames, static fn ( string $k ): bool => \str_starts_with( $k, self::scoped( 'evlog:p0:url_cat:' ) ) );
		$this->assertCount( 100, $dim_keys, 'top-100 url_dim retained' );
		$this->assertCount( 100, $cat_keys, 'top-100 url_cat retained' );
		$this->assertContains( self::scoped( 'evlog:p0:url_dim:h1' ), $dim_keys, 'busiest url_dim retained' );
		$this->assertNotContains( self::scoped( 'evlog:p0:url_dim:h105' ), $dim_keys, 'quietest url_dim evicted' );
		$this->assertContains( self::scoped( 'evlog:p0:url_cat:h1' ), $cat_keys, 'busiest url_cat retained' );
		$this->assertNotContains( self::scoped( 'evlog:p0:url_cat:h105' ), $cat_keys, 'quietest url_cat evicted' );
	}

	public function test_flame_requires_profiling_detail(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		$fb->set_flame_topn( 10 ); // enable the flame mirror to exercise the gate

		$store->set_url_stats( 'empty', [ 'flame' => [ 'count' => 0 ] ] );
		$store->set_url_stats( 'filled', [ 'flame' => [ 'count' => 3 ] ] );

		$fb->save_state();
		$p->flush();

		$frames = $this->read_mirror_frames( $p );
		$this->assertArrayNotHasKey( self::scoped( 'evlog:p0:url:empty' ), $frames, 'un-profiled URL not mirrored' );
		$this->assertArrayHasKey( self::scoped( 'evlog:p0:url:filled' ), $frames, 'profiled URL mirrored' );
	}

	public function test_warm_memcache_skips_partition_reload(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$now = \time();
		$this->fill_partition_entry( $p, self::scoped( 'evlog:p0:hourly' ), [ 'from-partition' => [ 'count' => 99 ] ], 100, $now );
		$p->flush();

		// Memcache already warm — reload must not clobber it.
		$store->set_hourly( [ 'from-memcache' => [ 'count' => 1 ] ] );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );
		$fb->save_state(); // Drive the lazy cold-boot reload.

		$this->assertSame( [ 'from-memcache' => [ 'count' => 1 ] ], $store->get_hourly(), 'warm memcache preserved' );
	}

	public function test_decayed_out_partition_entry_not_restored(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		// ttl=100 but the entry is 200s old → age >= ttl → decayed out.
		$old = \time() - 200;
		$this->fill_partition_entry( $p, self::scoped( 'evlog:p0:hourly' ), [ 'x' => [ 'count' => 5 ] ], 100, $old );
		$p->flush();

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );
		$fb->save_state(); // Drive the lazy cold-boot reload.

		$this->assertSame( [], $store->get_hourly(), 'decayed-out entry not restored' );
	}

	public function test_set_stats_target_before_store_records_name_but_stays_inert(): void {
		$p  = $this->make_partition( 'flames-stats' );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		// Misordered: no set_stats_store first. Late-bind still records the name
		// (advertised for round-trip), but with no store the mirror never arms.
		$fb->set_stats_target( $p->name() );
		$this->fill_request( $fb, $this->completed_request() );
		$p->flush();
		foreach ( $p->get_segments( true ) as $seg ) {
			$this->assertSame( 0, (int) $seg['size'], 'no store → mirror inert, partition stays empty' );
		}
		$this->assertStringContainsString( 'set_stats_target flames-stats', $fb->dump_config() );
	}

	public function test_large_mirror_writes_land_when_partition_void_warranty(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );

		// The topology lifts the 4KB PIPE_BUF cap via `cmd
		// flame-stats:partition:config void_warranty`; make_partition() mirrors that.
		$p = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		$fb->set_flame_topn( 10 ); // enable the flame mirror
		// >4KB and carries profiling detail so it survives the top-N gate.
		$data = [ 'flame' => [ 'count' => 1 ], 'blob' => \str_repeat( 'x', 5000 ) ];
		$store->set_url_stats( 'abc', $data );
		$fb->save_state();
		$p->flush();

		$frames = $this->read_mirror_frames( $p );
		$this->assertArrayHasKey( self::scoped( 'evlog:p0:url:abc' ), $frames, 'large mirror write survived (partition cap lifted in topology)' );
		$this->assertSame( $data, $frames[self::scoped( 'evlog:p0:url:abc' )]['data'] );
	}

	public function test_save_state_without_partition_does_not_throw(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertIsArray( $fb->save_state() );
	}

	public function test_reload_restores_all_committed_frames_last_wins(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$now = \time();
		$this->fill_partition_entry( $p, self::scoped( 'evlog:p0:hourly' ), [ 'v1' => [ 'count' => 1 ] ], 100, $now );
		$this->fill_partition_entry( $p, self::scoped( 'evlog:p0:hourly' ), [ 'v2' => [ 'count' => 2 ] ], 100, $now );
		$p->flush();

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );
		$fb->save_state(); // Drive the lazy cold-boot reload.

		$this->assertSame( [ 'v2' => [ 'count' => 2 ] ], $store->get_hourly(), 'last frame for a key wins; every frame is committed' );
	}

	public function test_node_schema_declares_set_stats_target_as_a_node_reference(): void {
		$commands = \array_column( Flame_Builder_Node::node_schema()['commands'], null, 'name' );
		$this->assertArrayHasKey( 'set_stats_target', $commands );
		$this->assertSame( 'node_name', $commands['set_stats_target']['args'][0]['type'] );
	}

	public function test_flame_builder_set_stats_target_verb_round_trips(): void {
		Core::$memd = new InMemoryMemcached();
		$p  = $this->make_partition( 'flames-stats' );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( new Stats_Store( partition: 0, max_lifespan: 86400 ) );
		$this->assertSame( 'ok', $this->read_private( $fb, 'interpreter' )->dispatch( 'set_stats_target', [ 'flames-stats' ] ) );
		$dump = $fb->dump_config();
		$this->assertStringContainsString( 'cmd fb:config set_stats_target flames-stats', $dump );
	}

	public function test_configure_stats_rejects_a_non_numeric_partition(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		// An unresolved token must fail loud, not configure partition 0.
		$result = $this->read_private( $fb, 'interpreter' )
			->dispatch( 'configure_stats', [ '<partition>' ] );
		$this->assertSame( 'usage: configure_stats <partition>', $result );
		$this->assertStringNotContainsString( 'configure_stats', $fb->dump_config() );
	}

	public function test_configure_stats_builds_the_store_on_the_given_partition(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$result = $this->read_private( $fb, 'interpreter' )
			->dispatch( 'configure_stats', [ '3' ] );
		$this->assertSame( 'ok', $result );
		$this->assertStringContainsString( 'cmd fb:config configure_stats 3', $fb->dump_config() );
	}

	public function test_set_stats_store_after_partition_arms_the_mirror(): void {
		Core::$memd = new InMemoryMemcached();
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		// Reversed order (or a configure_stats re-run): the partition name is set
		// before the store, so set_stats_store must arm the mirror itself.
		$fb->set_stats_target( $p->name() );
		$store = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb->set_stats_store( $store );

		$store->set_hourly( [ '2026-01-01-00' => [ 'count' => 7 ] ] );
		$fb->save_state();
		$p->flush();

		$this->assertArrayHasKey( self::scoped( 'evlog:p0:hourly' ), $this->read_mirror_frames( $p ), 'set_stats_store arms the mirror when a partition name is already set' );
	}

	public function test_set_stats_target_verb_late_binds_a_forward_referenced_node(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );

		// The verb runs BEFORE the partition's make_node — the ordering a
		// console-serialized override produces. It must store the name, not
		// fail on the not-yet-built node.
		$this->assertSame( 'ok', $this->read_private( $fb, 'interpreter' )->dispatch( 'set_stats_target', [ 'late:stats' ] ) );

		// Partition created afterward, then a buffered aggregate + checkpoint.
		$p = $this->make_partition( 'late:stats' );
		$store->set_hourly( [ '2026-01-01-00' => [ 'count' => 5 ] ] );
		$fb->save_state();
		$p->flush();

		$this->assertArrayHasKey( self::scoped( 'evlog:p0:hourly' ), $this->read_mirror_frames( $p ), 'forward-referenced stats partition resolved lazily at flush' );
	}
}

/**
 * A Flame_Builder whose intern table fills after three names, so a test can
 * reach the freeze without pushing 50000 distinct strings through it.
 */
class TinyInternFlameBuilder extends Flame_Builder_Node {
	protected const INTERN_TABLE_LIMIT = 3;
}
