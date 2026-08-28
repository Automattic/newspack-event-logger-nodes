<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Fold;
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

	private function make_partition( string $name, string $class = \Newspack_Nodes\Partition_Node::class ): \Newspack_Nodes\Partition_Node {
		// Inside the runtime tree: storage nodes refuse a path outside it.
		$dir               = \Newspack_Nodes\Config::get_base_directory() . '/flamestats_' . \uniqid();
		$this->temp_dirs[] = $dir;
		$p = new $class();
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
	 * Every mirror frame in a flushed partition, in write order.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function mirror_frames( \Newspack_Nodes\Partition_Node $p ): array {
		$out = [];
		foreach ( $p->get_segments( true ) as $seg ) {
			$bytes = $p->read_at( (int) $seg['id'], 0, (int) $seg['size'] );
			foreach ( \explode( "\n", $bytes ) as $line ) {
				if ( '' === $line ) {
					continue;
				}
				$val = Message::unpacked( $line )[ Message::VALUE ];
				if ( \is_array( $val ) && \is_string( $val['key'] ?? null ) ) {
					$out[] = $val;
				}
			}
		}
		return $out;
	}

	/**
	 * Those frames collapsed last-wins per key — what a rehydrate would see.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function read_mirror_frames( \Newspack_Nodes\Partition_Node $p ): array {
		$out = [];
		foreach ( $this->mirror_frames( $p ) as $val ) {
			$out[ $val['key'] ] = $val;
		}
		return $out;
	}

	/** Frame keys in write order, duplicates kept — rewrites are the thing under test. */
	private function raw_mirror_frame_keys( \Newspack_Nodes\Partition_Node $p ): array {
		return \array_column( $this->mirror_frames( $p ), 'key' );
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
				static fn ( string $k ): bool => \str_starts_with( $k, Stats_Store::entry_key( 0, 'url:' ) )
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

	/**
	 * The buckets a just-now write can land in — two, so a fill straddling a
	 * bucket boundary does not flake. The reader shape for every namespace.
	 *
	 * @return list<string>
	 */
	private function recent_buckets(): array {
		$now = \time();
		return [ Stats_Store::bucket_key( $now ), Stats_Store::bucket_key( $now - 300 ) ];
	}

	/** One dimension's recent series, keyed by bucket. */
	private function dim_series( Stats_Store $store, string $dimension, string $server = '' ): array {
		return $store->get_dimensional_buckets( $dimension, $this->recent_buckets(), $server );
	}

	/** The recent category series, keyed by bucket. */
	private function cat_series( Stats_Store $store, string $server = '' ): array {
		return $store->get_category_buckets( $this->recent_buckets(), $server );
	}

	/**
	 * Stored request totals for the buckets a just-now request can land in.
	 *
	 * @return array<string,mixed>
	 */
	private function recent_hourly( Stats_Store $store ): array {
		return $store->get_hourly_buckets( $this->recent_buckets() );
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
		// Accumulation lives on the store's table now, so the stats it is about
		// have somewhere to go; with none wired there is nothing to accumulate
		// INTO, and nothing could ever have been persisted from it either.
		Core::$memd = new InMemoryMemcached();
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( new Stats_Store( partition: 0, max_lifespan: 86400 ) );
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

		$bucket   = Stats_Store::bucket_key( $now );
		$index    = $this->url_bucket_rows( $store, $bucket );
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

		$bucket   = Stats_Store::bucket_key( $now );
		$index    = $this->url_bucket_rows( $store, $bucket );
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

		$bucket   = Stats_Store::bucket_key( $now );
		$index    = $this->url_bucket_rows( $store, $bucket );
		$url_hash = Log_Manager::url_hash( '/m' );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertEqualsWithDelta( 42.0, $index[ $url_hash ]['min_ms'], 1e-6 );
	}

	public function test_url_index_row_carries_the_per_server_split(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// One URL, two reporting servers. The index row is keyed by url_hash and
		// so carries no server of its own; without this split the dashboard
		// cannot scope the URL table, and every header stat beside it can.
		// `srv` is the per-URL `server` dimension co-located with the row so one
		// get answers for the whole index instead of one get per URL.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/split', 'server_name' => 'alpha.example', 'duration_ms' => 30.0, 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/split', 'server_name' => 'beta.example', 'duration_ms' => 70.0, 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/split', 'server_name' => 'beta.example', 'duration_ms' => 90.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket   = Stats_Store::bucket_key( $now );
		$url_hash = Log_Manager::url_hash( '/split' );
		$row      = $this->url_bucket_rows( $store, $bucket )[ $url_hash ];

		$this->assertSame( 1, $row['srv']['alpha.example']['count'] );
		$this->assertSame( 2, $row['srv']['beta.example']['count'] );
		$this->assertEqualsWithDelta( 30.0, $row['srv']['alpha.example']['sum_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 160.0, $row['srv']['beta.example']['sum_ms'], 1e-6 );
	}

	public function test_a_refused_url_index_write_is_reported(): void {
		// memcached refuses an item over 1MB, and this blob is the largest the
		// schema writes — up to 500 rows, each now carrying its per-server
		// split. A discarded return means the whole bucket's URL index is lost
		// for that partition, and every later read-modify-write of it fails the
		// same way, with nothing said.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );

		Core::$memd = new InMemoryMemcached();
		$refused    = new class( 0, 86400 ) extends Stats_Store {
			public function set_url_shard( string $bucket, string $shard, array $rows ): bool {
				return false;
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $refused );

		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/too-big', 'duration_ms' => 5.0, 'timestamp' => \time() ] ) );
		$fb->flush();

		$this->assertStringContainsString( 'URL index write refused', $err );
	}

	public function test_the_server_axis_holds_more_names_than_the_generic_cap(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		// The server picker is built from this axis, and the fleet is an
		// operator input — production runs 24 spokes. Capped with the generic
		// MAX_DIM_VALUES (20) four of them roll into a synthetic `Other` and
		// become unselectable, and which four varies by bucket, so even a
		// listed site loses the buckets it fell out of. Unlike `country` or
		// `ua`, this axis is bounded by the fleet, so it gets its own ceiling.
		$now = \time();
		for ( $i = 0; $i < 24; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => "/s{$i}",
				'server_name' => \sprintf( 'spoke%02d.example', $i ),
				'duration_ms' => 5.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$servers = $store->get_dimensional_bucket( 'server', Stats_Store::bucket_key( $now ) );

		$this->assertArrayNotHasKey( Stats_Store::OTHER_KEY, $servers );
		$this->assertCount( 24, $servers );
	}

	public function test_the_cap_counts_the_overflow_rows_it_writes(): void {
		// At EXACTLY the cap no fold runs, and the two overflow rows are added
		// back afterwards regardless — so the item ships at MAX + 2 and the
		// constant stops meaning what its name says.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$seed   = [
			Stats_Store::OTHER_KEY        => [ 'count' => 9, 'timed_count' => 9, 'sum_ms' => 18.0, 'worker' => false, 'last_seen' => $now ],
			Stats_Store::OTHER_WORKER_KEY => [ 'count' => 7, 'timed_count' => 7, 'sum_ms' => 14.0, 'worker' => true, 'last_seen' => $now ],
		];
		// 499 seeded plus the one this flush adds lands EXACTLY on the cap.
		for ( $i = 0; $i < 499; $i++ ) {
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url' => "/cap{$i}", 'count' => 1000 - $i, 'timed_count' => 1000 - $i,
				'sum_ms' => 2.0 * ( 1000 - $i ), 'min_ms' => 2.0, 'max_ms' => 2.0,
				'sum_peak_mb' => 0, 'max_peak_mb' => 0, 'count_2xx' => 1000 - $i,
				'count_3xx' => 0, 'count_4xx' => 0, 'count_5xx' => 0,
				'worker' => false, 'last_seen' => $now,
			];
		}
		// Straight into the shard: an overflow key is not hex, so routing it
		// through the bucket writer would file it under shard '0' instead of
		// the shard whose own tail produced it.
		$this->seed_url_shard( $store, $bucket, 'a', $seed );

		$url = '';
		for ( $i = 0; '' === $url; $i++ ) {
			if ( 'a' === Stats_Store::url_shard( Log_Manager::url_hash( "/in-a-{$i}" ) ) ) {
				$url = "/in-a-{$i}";
			}
		}
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 2.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$this->assertLessThanOrEqual( 500, \count( $store->get_url_shard( $bucket, 'a' ) ) );
	}

	public function test_the_overflow_row_keeps_worker_traffic_separate(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// The capped tail mixes both kinds, and one synthetic row cannot answer
		// a filter about them — the worker share would ride silently into a
		// header that excludes every other worker request.
		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$seed   = [];
		for ( $i = 0; $i < 600; $i++ ) {
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url' => "/u{$i}", 'count' => 1000 - $i, 'timed_count' => 1000 - $i,
				'sum_ms' => 2.0 * ( 1000 - $i ), 'min_ms' => 2.0, 'max_ms' => 2.0,
				'sum_peak_mb' => 0, 'max_peak_mb' => 0, 'count_2xx' => 1000 - $i,
				'count_3xx' => 0, 'count_4xx' => 0, 'count_5xx' => 0,
				'worker' => 0 === $i % 2, 'last_seen' => $now,
			];
		}
		$this->set_url_bucket( $store, $bucket, $seed );

		$url = '';
		for ( $i = 0; '' === $url; $i++ ) {
			if ( 'a' === Stats_Store::url_shard( Log_Manager::url_hash( "/in-a-{$i}" ) ) ) {
				$url = "/in-a-{$i}";
			}
		}
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 2.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$shard = self::named_url_rows( $store->get_url_shard( $bucket, 'a' ) );

		$this->assertTrue( $shard[ Stats_Store::other_key( true ) ]['worker'] );
		$this->assertFalse( $shard[ Stats_Store::other_key( false ) ]['worker'] );
		// Two overflow rows means two reserved slots, not one — the cap is a
		// ceiling on the ITEM, and reserving for one row while emitting two
		// puts it over by exactly the row that was supposed to bound it.
		$this->assertLessThanOrEqual( 500, \count( $shard ) );
	}

	public function test_a_url_row_records_whether_its_traffic_was_a_worker(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// `$count_global` keeps workers out of every site-wide aggregate — "one
		// long-running worker would dominate the site-wide averages" — but the
		// per-URL row deliberately keeps their timing, so a header summed from
		// the index inherits them. The row has to say which it is; deriving it
		// from `?worker_type` in the URL text is the substring guess that made
		// `--server` empty the table.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/w?reconcile', 'duration_ms' => 90000.0, 'is_worker' => true, 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/reader', 'duration_ms' => 12.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$rows   = $this->url_bucket_rows( $store, $bucket );

		$this->assertTrue( $rows[ Log_Manager::url_hash( '/w?reconcile' ) ]['worker'] );
		$this->assertFalse( $rows[ Log_Manager::url_hash( '/reader' ) ]['worker'] );
	}

	public function test_a_busy_url_keeps_every_server_that_served_it(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		// A cap that binds on a real fleet recreates, one layer down, the defect
		// `dim_cap()` removes from the picker: the folded server's scoped read
		// matches nothing, and the URL leaves the filtered table AND its totals
		// with nothing said. So `MAX_SERVER_VALUES` sits far above any fleet —
		// 40 servers on one URL, and the split still keeps every one.
		$now = \time();
		for ( $i = 0; $i < 40; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => '/shared',
				'server_name' => \sprintf( 'edge%02d.example', $i ),
				'duration_ms' => 3.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$hash = Log_Manager::url_hash( '/shared' );
		$row  = self::named_url_rows( $store->get_url_shard( Stats_Store::bucket_key( $now ), Stats_Store::url_shard( $hash ) ) )[ $hash ];
		$srv  = $row[ Stats_Store::URL_SRV_FIELD ];

		$this->assertArrayNotHasKey( Stats_Store::OTHER_KEY, $srv );
		$this->assertCount( 40, $srv );
		$this->assertSame( 1, $srv['edge39.example']['count'] );
	}

	/**
	 * The per-server cap keeps the BUSIEST servers, not the first ones seen.
	 * `cap_bucket()` ranks on a field of each entry, and the split's fields are
	 * indexes now — ranking it by the old NAME finds nothing on every entry, so
	 * every comparison ties and the sort silently becomes insertion order. It
	 * only bites past `MAX_SERVER_VALUES`, which is exactly the Host-header
	 * spray the cap exists for.
	 */
	public function test_the_server_cap_keeps_the_busiest_not_the_first_seen(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		$url = '/spray-4471';
		// The quiet ones arrive FIRST, so insertion order would keep them.
		for ( $i = 0; $i < Stats_Store::MAX_SERVER_VALUES + 20; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => $url,
				'server_name' => \sprintf( 'quiet-%03d.example', $i ),
				'duration_ms' => 3.0,
				'timestamp'   => $now,
			] ) );
		}
		// One loud host, arriving last, with traffic nothing else comes near.
		for ( $i = 0; $i < 50; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => $url,
				'server_name' => 'loud-8823.example',
				'duration_ms' => 3.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$hash = Log_Manager::url_hash( $url );
		$row  = self::named_url_rows(
			$store->get_url_shard( Stats_Store::bucket_key( $now ), Stats_Store::url_shard( $hash ) )
		)[ $hash ];

		$this->assertArrayHasKey(
			'loud-8823.example',
			$row[ Stats_Store::URL_SRV_FIELD ],
			'the busiest server survives the cap'
		);
	}

	public function test_a_host_header_spray_folds_instead_of_growing_the_axis(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		// @longform `server_name` is `SERVER_NAME`, which under Apache's default
		// `UseCanonicalName Off` is the CLIENT'S Host header — so on a
		// domain-mapped multisite or any catch-all vhost this axis is visitor
		// input, not the fleet. Uncapped, the global `dim:server` item and every
		// row's split grow without limit until memcached refuses the write, and
		// a refusal discards the WHOLE merged shard-bucket.
		$now   = \time();
		$spray = 300;
		for ( $i = 0; $i < $spray; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => '/xmlrpc.php',
				'server_name' => \sprintf( 'spray%03d.probe.test', $i ),
				'duration_ms' => 4.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$bucket  = Stats_Store::bucket_key( $now );
		$servers = $store->get_dimensional_bucket( 'server', $bucket );
		$hash    = Log_Manager::url_hash( '/xmlrpc.php' );
		$row     = self::named_url_rows( $store->get_url_shard( $bucket, Stats_Store::url_shard( $hash ) ) )[ $hash ];
		$srv     = $row[ Stats_Store::URL_SRV_FIELD ];

		$this->assertLessThanOrEqual( Stats_Store::MAX_SERVER_VALUES, \count( $servers ) );
		$this->assertLessThanOrEqual( Stats_Store::MAX_SERVER_VALUES, \count( $srv ) );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $servers );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $srv );
		// Folded, not dropped: every request is still counted on both axes.
		$this->assertSame( $spray, \array_sum( \array_column( $servers, 'c' ) ) );
		$this->assertSame( $spray, \array_sum( \array_column( $srv, 'count' ) ) );
		$this->assertSame( $spray, $row['count'] );
	}

	public function test_url_index_split_names_a_nameless_producer_unknown(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// `accumulate_dimensions()` maps an empty server to the literal
		// 'Unknown' on the `server` axis, and the dashboard builds its picker
		// from THAT axis — so WP-CLI and cron traffic put 'Unknown' in the
		// dropdown. Writing no split for it made choosing it empty the table.
		// The two axes have to agree on the name.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/cron', 'server_name' => '', 'duration_ms' => 12.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$row = $this->url_bucket_rows( $store, Stats_Store::bucket_key( $now ) )[ Log_Manager::url_hash( '/cron' ) ];

		// One server, so it collapses: the KEY is what this test is about.
		$this->assertSame( [ 'Unknown' => null ], $row[ Stats_Store::URL_SRV_FIELD ] );
	}

	public function test_no_single_index_item_holds_the_whole_bucket(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// @longform What sharding actually buys, and it is NOT write
		// amplification: with 16 shards a flush carrying k distinct hashes
		// touches 16 * (1 - (15/16)^k) of them — 7.6 at k=10, 16 at k=100 — so
		// at any real flush width every shard is written and the total bytes
		// are unchanged. What changes is the ITEM: memcached refuses one over
		// its limit and the refused write loses that whole item, so a bucket
		// that lives in one blob is one blob away from losing every URL in it.
		$now = \time();
		for ( $i = 0; $i < 320; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url' => "/wide-{$i}", 'duration_ms' => 5.0, 'timestamp' => $now,
			] ) );
		}
		$fb->flush();

		$bucket  = Stats_Store::bucket_key( $now );
		$largest = 0;
		$whole   = 0;
		foreach ( Stats_Store::url_shards() as $shard ) {
			$bytes    = \strlen( \serialize( $store->get_url_shard( $bucket, $shard ) ) );
			$whole   += $bytes;
			$largest  = \max( $largest, $bytes );
		}

		$this->assertGreaterThan( 0, $largest );
		$this->assertLessThan(
			(int) ( $whole / 4 ),
			$largest,
			"the busiest item holds {$largest} of {$whole} bytes — the bucket is not split"
		);
	}

	public function test_a_flush_writes_only_the_shards_its_rows_land_in(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// Routing, not a saving: at production flush width every shard is
		// touched anyway (see the test above). This pins that a row goes to the
		// shard its hash names and nowhere else, which is what makes a point
		// read able to skip the other fifteen.
		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );

		$written       = [];
		$store->mirror = static function ( string $key ) use ( &$written ): void {
			if ( false !== \strpos( $key, ':' . Stats_Store::NS_URLS . ':' ) ) {
				$written[] = $key;
			}
		};

		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/one', 'duration_ms' => 5.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$this->assertCount( 1, $written, \implode( ', ', $written ) );
		$this->assertStringEndsWith(
			Stats_Store::url_shard( Log_Manager::url_hash( '/one' ) ) . ':' . $bucket,
			$written[0] ?? ''
		);
	}

	public function test_the_capped_tail_folds_into_one_other_row(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// Past MAX_URLS_PER_BUCKET the tail used to be DROPPED, so every total
		// summed from this index was a lower bound by however much traffic fell
		// off. Folding it into one row keeps the bucket bounded and the totals
		// exact — including per server, since the splits fold with it.
		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$seed   = [];
		for ( $i = 0; $i < 600; $i++ ) {
			// All in one shard, which is where the cap now applies.
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url'         => "/u{$i}",
				// Descending, so the last 101 are the ones that fall off.
				'count'       => 1000 - $i,
				'timed_count' => 1000 - $i,
				'sum_ms'      => 2.0 * ( 1000 - $i ),
				'count_2xx'   => 1000 - $i,
				'count_3xx'   => 0,
				'count_4xx'   => 0,
				'count_5xx'   => 0,
				'min_ms'      => 2.0,
				'max_ms'      => 2.0,
				'sum_peak_mb' => 0,
				'max_peak_mb' => 0,
				'last_seen'   => $now,
				'srv'         => [ 'alpha.example' => [ 'count' => 1000 - $i, 'timed_count' => 1000 - $i, 'sum_ms' => 2.0 * ( 1000 - $i ), 'count_2xx' => 1000 - $i ] ],
			];
		}
		$this->set_url_bucket( $store, $bucket, $seed );

		// A shard is capped when it is WRITTEN, so the flush has to land in it.
		$url = '';
		for ( $i = 0; '' === $url; $i++ ) {
			if ( 'a' === Stats_Store::url_shard( Log_Manager::url_hash( "/in-a-{$i}" ) ) ) {
				$url = "/in-a-{$i}";
			}
		}
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'server_name' => 'alpha.example', 'duration_ms' => 2.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$shard = self::named_url_rows( $store->get_url_shard( $bucket, 'a' ) );
		$other = $shard[ Stats_Store::OTHER_KEY ] ?? null;

		$this->assertNotNull( $other, 'the tail folds into one row' );
		// 498 kept plus both overflow rows: a slot is reserved for each the fold
		// can emit, whether or not this tail fills them.
		$this->assertLessThanOrEqual( 500, \count( $shard ) );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $shard );
		// 601 rows in the shard (600 seeded plus the one just written, count 1),
		// keep 498 — a slot reserved for each overflow row the fold can emit —
		// so the 103 smallest fold: counts 502 down to 401, and the 1.
		$expected = \array_sum( \range( 401, 502 ) ) + 1;
		$this->assertSame( $expected, $other['count'] );
		// The tail was all one host, so the folded split collapses — which says
		// the same thing more strongly: the collapse is written ONLY when the
		// sole server's count is the row's own, here all 103 folded rows.
		$this->assertSame( [ 'alpha.example' => null ], $other['srv'] );
	}

	public function test_url_index_split_is_written_on_a_spoke_too(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->set_is_hub( false );

		// The three per-server AGGREGATES are hub-only, and this one looks like
		// it forgot the gate. It did not: the server filter is offered wherever
		// the `server` dimension has values, which is everywhere, and a scoped
		// read of a row with no split returns nothing — so gating this would
		// empty the URL table on every spoke rather than save it anything.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/spoke', 'server_name' => 'lone.example', 'duration_ms' => 12.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$row = $this->url_bucket_rows( $store, Stats_Store::bucket_key( $now ) )[ Log_Manager::url_hash( '/spoke' ) ];

		// One server, so it collapses; a spoke writing the split at all is the point.
		$this->assertSame( [ 'lone.example' => null ], $row[ Stats_Store::URL_SRV_FIELD ] );
	}

	public function test_url_index_split_carries_exactly_the_summed_fields(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// The accumulator builds the split from its own literal field list while
		// the persist merge builds it from `URL_SRV_SUMS`. A field added to one
		// and not the other is dropped or invented silently, so the two are held
		// to the same set here rather than by a comment asking them to agree.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/fields', 'server_name' => 'alpha.example', 'duration_ms' => 30.0, 'peak_mb' => 4.0, 'timestamp' => $now ] ) );
		// A SECOND server, so the split does not collapse to a host name: the
		// field set is what this test reads, and a collapse would hide it.
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/fields', 'server_name' => 'beta.example', 'duration_ms' => 70.0, 'peak_mb' => 9.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$row = $this->url_bucket_rows( $store, Stats_Store::bucket_key( $now ) )[ Log_Manager::url_hash( '/fields' ) ];

		// Values, not keys: `sum_fields()` materializes all eight at persist, so a
		// field the accumulator forgot arrives as a plausible 0.
		$this->assertSame(
			[
				'count'       => 1,
				'timed_count' => 1,
				'sum_ms'      => 30.0,
				'sum_peak_mb' => 4.0,
				'count_2xx'   => 1,
				'count_3xx'   => 0,
				'count_4xx'   => 0,
				'count_5xx'   => 0,
			],
			$row[ Stats_Store::URL_SRV_FIELD ]['alpha.example']
		);
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

		$bucket   = Stats_Store::bucket_key( $now );
		$index    = $this->url_bucket_rows( $store, $bucket );
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

		$hourly = $this->recent_hourly( $store );
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

		$dim = $this->dim_series( $store, 'status' );
		$this->assertNotEmpty( $dim );
		// Status normalized to "Nxx" form by accumulate_all_stats.
		$bucket = \array_keys( $dim )[0];
		$this->assertSame( 1, $dim[ $bucket ]['2xx']['c'] );
		$this->assertSame( 1, $dim[ $bucket ]['5xx']['c'] );
	}

	/**
	 * The stats a request contributes must not depend on whether
	 * Request_Builder happened to fold it. This is the highest-risk part of the
	 * pressure fold: a leaderboard that shifts under load is worse than one that
	 * stops.
	 */
	public function test_a_folded_request_contributes_the_same_stats_as_an_unfolded_one(): void {
		$now     = \time();
		$origin  = (float) $now;
		$entries = [
			[ 'k' => 'process (start)', 'ts' => $origin ],
		];
		// Three `save` spans and a `db` — the breadth-at-depth-3 shape that
		// makes an envelope big enough to fold in the first place.
		foreach ( [ 7.0, 13.0, 5.0 ] as $i => $ms ) {
			$entries[] = [ 'k' => 'save (start)', 'ts' => $origin + ( $i * 0.05 ) ];
			$entries[] = [ 'k' => 'save (complete)', 'ts' => $origin + ( $i * 0.05 ) + $ms / 1000, 'duration_ms' => $ms ];
		}
		$entries[] = [ 'k' => 'db (start)', 'ts' => $origin + 0.2 ];
		$entries[] = [ 'k' => 'db (complete)', 'ts' => $origin + 0.24, 'duration_ms' => 40.0 ];
		$entries[] = [ 'k' => 'process (complete)', 'ts' => $origin + 0.3, 'duration_ms' => 300.0 ];

		$fold = Flame_Fold::start( $origin );
		foreach ( $entries as $entry ) {
			Flame_Fold::add( $fold, $entry );
		}

		$base = [
			'duration_ms' => 300.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.4, 'count' => 12, 'ts' => $now, 'entries' => [] ] ],
		];

		$plain = $this->stats_for( \array_replace( $base, [ 'entries' => $entries ] ) );
		$rolled = $this->stats_for(
			\array_replace(
				$base,
				[
					'entries' => [],
					'flame'   => Flame_Fold::tree( $fold ),
					'folded'  => true,
				]
			)
		);

		$this->assertSame( $plain, $rolled );
	}

	/**
	 * Arm a mirror over a fresh indexed partition and return both.
	 *
	 * @return array{0: Flame_Builder_Node, 1: \Newspack_Nodes\Partition_Node}
	 */
	private function mirrored_builder( Stats_Store $store, string $name, string $class = \Newspack_Nodes\Partition_Node::class ): array {
		$p = $this->make_partition( $name, $class );
		$p->with_index( Flame_Builder_Node::format_stats_index_entry( ... ) );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );
		return [ $fb, $p ];
	}

	/**
	 * Every stats namespace one request writes, for the parity comparison
	 * above. Same URL and clock both runs, so any difference is the fold's.
	 *
	 * @param array<string,mixed> $request Completed-request record.
	 * @return array<string,mixed> The persisted stats.
	 */
	private function stats_for( array $request ): array {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( $request ) );
		$fb->flush();

		$timestamp = $request['timestamp'];
		$bucket    = Stats_Store::bucket_key( \is_int( $timestamp ) ? $timestamp : \time() );
		return [
			'leaderboard' => $store->get_leaderboard_bucket( $bucket ),
			'categories'  => $store->get_category_buckets( [ $bucket ] ),
			'hourly'      => $store->get_hourly_buckets( [ $bucket ] ),
			'urls'        => $this->url_bucket_rows( $store, $bucket ),
		];
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
		$cats = $this->cat_series( $store );
		$this->assertNotEmpty( $cats );
		$bucket = Stats_Store::bucket_key( $now );
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

		$hourly = $this->recent_hourly( $store );
		// hourly gets count++ and sum_ms is incremented only for has_timing requests.
		// With T-status, count stays 0 (since has_timing is false).
		foreach ( $hourly as $bucket => $stats ) {
			$this->assertSame( 0, $stats['count'], 'timed-out excluded from count' );
			$this->assertSame( 0.0, $stats['sum_ms'], 'timed-out excluded from sum_ms' );
		}
	}

	public function test_held_mirror_size_is_readable_before_it_overflows(): void {
		// ADR-11's reopen condition asks whether the held total is running at a
		// multiple of the budget. The tripwire only speaks once it is ALREADY
		// over, so the number has to exist on the introspection payload too —
		// a threshold you can only observe after it trips is not a signal.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb ]     = $this->mirrored_builder( $store, 'flames-stats' );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		$fb->set_clock( static fn() => $open );
		$store->set_hourly_bucket( $bucket, [ 'count' => 5 ] );
		$store->set_leaderboard_bucket( $bucket, [ 'blob' => \str_repeat( 'z', 9000 ) ] );
		$fb->save_state();
		$fb->set_clock( null );

		$stats = $this->get_stats( $fb );
		$this->assertSame( 2, $stats['mirror_held_frames'], 'both frames are held' );
		$this->assertGreaterThan( 9000, $stats['mirror_held_bytes'], 'and their bytes are reported' );
	}

	public function test_the_over_budget_tripwire_names_what_did_not_fit(): void {
		// ADR-11 calls this log the only tripwire that can tell you the budget
		// is set wrong. It reported a bare count, so a hub firing it seventeen
		// times in three hours still could not say WHICH frame overflowed or
		// how big it was. The pack is sorted ascending and breaks at the first
		// that will not fit, so the frame it stops on is the SMALLEST of the
		// dropped set — the largest is the one a budget is set from.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );

		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb ]     = $this->mirrored_builder( $store, 'flames-stats' );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		$fb->set_clock( static fn() => $open );

		// One that fits and TWO that do not, the leaderboard the larger.
		$store->set_hourly_bucket( $bucket, [ 'count' => 3 ] );
		$store->set_category_bucket( $bucket, [ 'blob' => \str_repeat( 'c', 2400000 ) ] );
		$store->set_leaderboard_bucket( $bucket, [ 'blob' => \str_repeat( 'x', 3000000 ) ] );

		$fb->save_state();
		$fb->set_clock( null );

		$hit = $err;
		$this->assertStringContainsString( 'over the checkpoint budget', $hit, 'the tripwire fired' );
		$this->assertStringContainsString(
			Stats_Store::entry_key( 0, Stats_Store::NS_LB . ':' . $bucket ),
			$hit,
			'names the LARGEST dropped frame'
		);
		// The stopped-on key appears nowhere in the line, so assert its absence
		// outright rather than against a format fragment that can be reworded.
		$this->assertStringNotContainsString(
			Stats_Store::entry_key( 0, Stats_Store::NS_CATEGORIES . ':' . $bucket ),
			$hit,
			'not the one the loop stopped on'
		);
		$this->assertStringContainsString( '2097152', $hit, 'names the budget' );
		// Makes the third bucket load-bearing: without it the counts go unasserted.
		$this->assertStringContainsString( '2 of 3 frames dropped', $hit, 'counts what fell out' );
	}

	public function test_the_over_budget_tripwire_names_the_dropped_frames_namespace(): void {
		// ADR-11 and ADR-14 both ask the operator whether the largest dropped
		// frame is a per-server leaderboard or a `urls` shard — a question
		// about the NAMESPACE. Sharding made both an opaque key, so the line
		// has to name the namespace in its own right.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );

		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb ]     = $this->mirrored_builder( $store, 'flames-stats' );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		$fb->set_clock( static fn() => $open );

		$store->set_hourly_bucket( $bucket, [ 'count' => 7 ] );
		$store->set_category_bucket( $bucket, [ 'blob' => \str_repeat( 'c', 2400000 ) ] );
		$store->set_leaderboard_bucket( $bucket, [ 'blob' => \str_repeat( 'x', 3000000 ) ] );

		$fb->save_state();
		$fb->set_clock( null );

		$this->assertStringContainsString(
			Stats_Store::NS_LB . '/' . Stats_Store::entry_key( 0, Stats_Store::NS_LB . ':' . $bucket ),
			$err,
			'the namespace is printed beside the key it belongs to'
		);
	}

	public function test_the_over_budget_tripwire_names_the_node_once(): void {
		// `Node::log_midfix()` already prepends "<name>: " to every line, and
		// drops it only when the process name starts with that name. A message
		// that hard-codes its own name too doubles it on every other worker.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );

		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );
		$p->with_index( Flame_Builder_Node::format_stats_index_entry( ... ) );
		$fb = new Flame_Builder_Node();
		// The name this node runs under in flame-builder.tsl, not the suite's
		// short 'fb': the doubling is only visible under the real one.
		$fb->name( 'flame-builder' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		$fb->set_clock( static fn() => $open );
		$store->set_hourly_bucket( $bucket, [ 'count' => 11 ] );
		$store->set_category_bucket( $bucket, [ 'blob' => \str_repeat( 'c', 2400000 ) ] );
		$store->set_leaderboard_bucket( $bucket, [ 'blob' => \str_repeat( 'x', 3000000 ) ] );
		$fb->save_state();
		$fb->set_clock( null );

		$this->assertStringContainsString( 'over the checkpoint budget', $err, 'the tripwire fired' );
		$this->assertSame( 1, \substr_count( $err, 'flame-builder' ), 'the node is named once' );
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
		// What a dump owes is REPLAY, not a literal: the argument it emits has
		// to come back through the same verb as the same state.
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		// The substrate's synthesized toggle handler answers "ok\n".
		$this->assertSame( "ok\n", $this->read_private( $fb, 'interpreter' )->dispatch( 'set_is_hub', [ 'true' ] ) );
		$dump = $fb->dump_config();
		$this->assertMatchesRegularExpression( '/command_node fb:config set_is_hub (\S+)/', $dump );

		\preg_match( '/command_node fb:config set_is_hub (\S+)/', $dump, $m );
		$replayed = new Flame_Builder_Node();
		$replayed->name( 'fb2' );
		$this->read_private( $replayed, 'interpreter' )->dispatch( 'set_is_hub', [ $m[1] ] );
		$this->assertTrue( $this->read_private( $replayed, 'is_hub' ), 'the dumped argument replays as ON' );
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

		$bucket = Stats_Store::bucket_key( $fixed );
		$hourly = $store->get_hourly_buckets( [ $bucket ] );
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
		$hourly_before = $this->recent_hourly( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 13.0 ] ) );

		// After the interval-triggered fill: hourly persisted to store.
		$hourly_after = $this->recent_hourly( $store );
		$this->assertNotEquals( $hourly_before, $hourly_after, 'fill flushed pending bucket' );
		$this->assertNotEmpty( $hourly_after );
	}

	public function test_set_custom_event_names_dispatches_to_custom_events(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 50 ] );
		$fb = new Flame_Builder_Node();
		$fb->set_custom_event_names( [ 'mything', 'other' ] );

		$req = $this->completed_request( [
			'profiles' => [
				// "mything" is in custom event names — routes to disable_custom_events.
				'mything hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
				// "wp_init" is NOT a custom event — routes to disable_hooks.
				'wp_init hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'mything' ], $state['disable_custom_events']['r'] );
		$this->assertSame( [ 'wp_init' ], $state['disable_hooks']['r'] );
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
		$this->assertEmpty( $state['add_significant_events'], 'already-significant event not re-flagged' );
	}

	// --- restore_state edge cases -----------------------------------------

	public function test_restore_state_ignores_non_array_pending(): void {
		$fb = new Flame_Builder_Node();
		$fb->restore_state( [ 'pending' => 'not-an-array' ] );
		$this->assertSame( [], $fb->save_state()['pending'] );
	}

	public function test_restore_state_seeds_every_key_a_partial_bucket_omits(): void {
		$fb = new Flame_Builder_Node();
		$fb->restore_state( [
			'pending' => [
				'2024-01-01-12-00' => [ 'hourly' => [ 'count' => 7, 'sum_ms' => 700, 'sum_peak_mb' => 21 ] ],
			],
		] );
		$bucket = $fb->save_state()['pending']['2024-01-01-12-00'];
		$this->assertSame( 7, $bucket['hourly']['count'] );
		$this->assertSame( [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ], $bucket['leaderboard'] );
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

		$buckets = $this->cat_series( $store );
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
		$this->assertSame( [ 'spammy' ], $state['disable_hooks']['r'] );
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

		// @longform The LOCK is what this test is about. A flush also folds any
		// closed hour that has not been folded into the coarse URL tier, which
		// an idle partition needs as much as a busy one — a missing coarse key
		// is what sends the reader back to twelve fine buckets.
		foreach ( $mc->keys() as $key ) {
			$this->assertStringContainsString( ':' . Stats_Store::NS_URLS_HOUR . ':', $key, 'no lock or other keys written' );
		}
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
		$this->assertSame( [], $state['disable_hooks'] );
	}

	// --- persist_aggregate_stats internals: hourly expiration -------------

	public function test_a_flush_leaves_buckets_it_did_not_fill_alone(): void {
		// Retention is the key's own TTL, so a flush has no reason to touch a
		// bucket it did not fill.
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 3600 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$untouched = [ 'count' => 99, 'sum_ms' => 9900, 'sum_peak_mb' => 9 ];
		$store->set_hourly_bucket( '1999-01-01-00-00', $untouched );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 5.0 ] ) );
		$fb->flush();

		$this->assertSame( $untouched, $store->get_hourly_bucket( '1999-01-01-00-00' ) );
		$this->assertNotSame( [], $this->recent_hourly( $store ), 'and the request it did fill landed' );
	}

	/**
	 * A pre-0.65 NAMED row is not a row this writer can merge into, and the
	 * documented procedure rotates the salt so it is never met. If that step is
	 * skipped it must degrade the same way — discarded, not half-merged.
	 *
	 * Half-merged is silent and lasts a whole retention window: the named row
	 * survives the `?:`, the accumulator sums into indexes it does not have, its
	 * own counts vanish, its string keys ride into the stored row, and its
	 * `durations` — up to a hundred floats — go back onto the read path 0.64.0
	 * took them off.
	 */
	public function test_a_legacy_named_row_is_discarded_not_merged_into(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \time();
		$bucket     = Stats_Store::bucket_key( $now );
		$url        = '/pangolin-6142';
		$hash       = Log_Manager::url_hash( $url );

		// A second legacy row in the SAME shard that this flush never touches:
		// the blob is written back whole, so it rides the read-modify-write out
		// unless the legacy test is applied at the READ.
		$untouched = \str_pad( \dechex( \hexdec( $hash[0] ) ), 13, '7' );

		// What 0.64.0 wrote, verbatim: names, and a reservoir inside the row.
		$store->set_url_shard( $bucket, Stats_Store::url_shard( $hash ), [
			$hash      => [
				'url'         => $url,
				'count'       => 91,
				'timed_count' => 91,
				'sum_ms'      => 6142.0,
				'durations'   => [ 61.0, 142.0 ],
			],
			$untouched => [
				'url'         => '/pangolin-untouched',
				'count'       => 23,
				'timed_count' => 23,
				'sum_ms'      => 1871.0,
			],
		] );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => $url,
			'duration_ms' => 447.0,
			'timestamp'   => $now,
		] ) );
		$fb->flush();

		$rows = $this->url_bucket_rows( $store, $bucket );
		$this->assertSame( 1, $rows[ $hash ]['count'], 'the legacy counts go, they do not half-merge' );
		$this->assertArrayNotHasKey(
			$untouched,
			$rows,
			'and one the flush never touched does not ride the read-modify-write back out'
		);
		$this->assertArrayNotHasKey( 'durations', $rows[ $hash ] );
		$raw  = $store->get_url_shard( $bucket, Stats_Store::url_shard( $hash ) );
		$keys = \array_keys( $raw[ $hash ] );
		$this->assertSame(
			$keys,
			\array_filter( $keys, '\is_int' ),
			'and what is stored is positional, with no legacy string keys riding along'
		);
	}

	/**
	 * A closed hour is folded ONCE into a coarse key, not added to
	 * incrementally: the five-minute write is a full overwrite and is safe to
	 * repeat, while adding into an hour bucket double-counts on a re-flush.
	 */
	public function test_a_closed_hour_is_rolled_up_into_one_coarse_key(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = Log_Manager::url_hash( '/wombat-4471' );
		$shard      = Stats_Store::url_shard( $hash );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		// Two fine buckets of the hour that has just closed.
		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 40.0, 'max_ms' => 15.0, 'last_seen' => $now - 7200 ],
		] );
		$this->seed_url_shard( $store, '2026-08-27-13-40', $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 6, 'timed_count' => 6, 'sum_ms' => 90.0, 'max_ms' => 22.0, 'last_seen' => $now - 5400 ],
		] );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->roll_up_hours( $now );

		$rolled = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$this->assertSame( 10, $rolled[ $hash ]['count'], 'the hour sums its buckets' );
		$this->assertSame( 130.0, (float) $rolled[ $hash ]['sum_ms'] );
		$this->assertSame( 22.0, (float) $rolled[ $hash ]['max_ms'], 'an extreme is a max, not a sum' );
		$this->assertSame( '/wombat-4471', $rolled[ $hash ]['url'] );
	}

	/**
	 * A folded hour must carry the per-server split, and `sum_entry()` does NOT
	 * fold it — it sums the eight scalars of `URL_SRV_SUMS` and nothing else.
	 * A folded hour's fine buckets are deliberately not read, so an hour whose
	 * split came back empty takes every server-scoped read of that URL with it:
	 * `swap_url_server_sums()` answers null for a row missing the server asked
	 * for, and the URL leaves the filtered table AND its totals (decision 14).
	 */
	public function test_a_folded_hour_carries_the_per_server_split(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = Log_Manager::url_hash( '/wombat-4471' );
		$shard      = Stats_Store::url_shard( $hash );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$hash => [
				'url' => '/wombat-4471', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 40.0,
				Stats_Store::URL_SRV_FIELD => [ 'web-4471' => [ 'count' => 4, 'timed_count' => 4, 'sum_ms' => 40.0 ] ],
			],
		] );
		$this->seed_url_shard( $store, '2026-08-27-13-40', $shard, [
			$hash => [
				'url' => '/wombat-4471', 'count' => 6, 'timed_count' => 6, 'sum_ms' => 90.0,
				Stats_Store::URL_SRV_FIELD => [ 'web-8823' => [ 'count' => 6, 'timed_count' => 6, 'sum_ms' => 90.0 ] ],
			],
		] );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->roll_up_hours( $now );

		$hour  = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$split = $hour[ $hash ][ Stats_Store::URL_SRV_FIELD ];
		$this->assertSame( [ 'web-4471', 'web-8823' ], \array_keys( $split ) );
		$this->assertSame( 4, $split['web-4471']['count'] );
		$this->assertSame( 6, $split['web-8823']['count'] );
	}

	/**
	 * The coarse tier needs decision 5's legacy guard too, and worse: a folded
	 * hour's twelve fine buckets are deliberately never read again, so a ghost
	 * written here is what the dashboard shows for the whole hour and outlives
	 * its own source — the buckets age out on their TTL while the hour key
	 * stands for the full window.
	 */
	/**
	 * A split with ONE server whose count is the row's own is the row restated.
	 * Storing it as a host name against `null` is ~33 bytes where the positional
	 * sums are ~112 — and on this fleet every URL is served by exactly one host,
	 * so `srv` was over half the whole read.
	 *
	 * Count alone decides it: every `URL_SRV_SUMS` field is summed into the row
	 * and into the split from the SAME increment, so a matching count means the
	 * other seven match by construction.
	 */
	public function test_a_single_server_split_collapses_to_the_host_name(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		for ( $i = 0; $i < 4; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => 'https://one-host.example/wombat-4471',
				'duration_ms' => 91.0,
				'timestamp'   => $now,
				'server_name' => 'one-host.example',
			] ) );
		}
		$fb->flush();

		$hash = Log_Manager::url_hash( 'https://one-host.example/wombat-4471' );
		$row  = $store->get_url_shard( Stats_Store::bucket_key( $now ), Stats_Store::url_shard( $hash ) )[ $hash ];
		$this->assertSame(
			[ 'one-host.example' => null ],
			$row[ Stats_Store::ROW_SRV ],
			'the host name alone, against null'
		);
		$this->assertSame( 4, $row[ Stats_Store::ROW_COUNT ], 'and the row still carries the numbers' );
	}

	/**
	 * A collapsed split is stored, then read back and merged — by WRITERS, not
	 * just the reader. `sum_fields()` skips a non-array, so an unexpanded null
	 * is not a zero: it is that host deleted from the merge, silently.
	 */
	public function test_a_second_flush_keeps_the_collapsed_hosts_whole_count(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \time();
		$url        = 'https://reflush.example/wombat-4471';

		// Three, flush, then two MORE into the same bucket, host and URL.
		foreach ( [ 3, 2 ] as $batch ) {
			$fb = new Flame_Builder_Node();
			$fb->set_stats_store( $store );
			for ( $i = 0; $i < $batch; $i++ ) {
				$this->fill_request( $fb, $this->completed_request( [
					'url'         => $url,
					'duration_ms' => 10.0,
					'timestamp'   => $now,
					'server_name' => 'reflush.example',
				] ) );
			}
			$fb->flush();
		}

		$hash = Log_Manager::url_hash( $url );
		$row  = self::named_url_rows( $store->get_url_shard( Stats_Store::bucket_key( $now ), Stats_Store::url_shard( $hash ) ) )[ $hash ];
		$this->assertSame( 5, $row['count'], 'the row counts both flushes' );
		// Still one host, so still collapsed — which is the assertion: a
		// collapse means the sole host's count IS the row's, all five.
		$this->assertSame( [ 'reflush.example' => null ], $row['srv'] );
	}

	/** The coarse tier merges stored rows too, so it needs the expansion. */
	public function test_a_folded_hour_keeps_a_collapsed_split(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = Log_Manager::url_hash( '/wombat-4471' );
		$shard      = Stats_Store::url_shard( $hash );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		foreach ( [ '2026-08-27-13-05', '2026-08-27-13-40' ] as $bucket ) {
			$this->seed_url_shard( $store, $bucket, $shard, [
				$hash => [
					'url' => '/wombat-4471', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 40.0,
					'srv' => [ 'sole.example' => null ],
				],
			] );
		}

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->roll_up_hours( $now );

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$this->assertSame( 8, $hour[ $hash ]['count'], 'both buckets fold' );
		$this->assertSame( [ 'sole.example' => null ], $hour[ $hash ]['srv'], 'and the split folds with them' );
	}

	/**
	 * And the capped tail: decision 15 says a total summed from this index is
	 * exact per server, not just site-wide, so the overflow row's split has to
	 * carry the hosts the tail was seen on.
	 */
	public function test_a_capped_tail_of_collapsed_rows_folds_its_hosts(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \time();
		$bucket     = Stats_Store::bucket_key( $now );

		$seed = [];
		for ( $i = 0; $i < 520; $i++ ) {
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url' => "/u{$i}", 'count' => 7 + $i, 'timed_count' => 7 + $i,
				'sum_ms' => 2.0 * ( 7 + $i ), 'last_seen' => $now,
				'srv' => [ 'tail.example' => null ],
			];
		}
		$this->seed_url_shard( $store, $bucket, 'a', $seed );

		$url = '';
		for ( $i = 0; '' === $url; $i++ ) {
			if ( 'a' === Stats_Store::url_shard( Log_Manager::url_hash( "/in-a-{$i}" ) ) ) {
				$url = "/in-a-{$i}";
			}
		}
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [
			'url' => $url, 'server_name' => 'live.example', 'duration_ms' => 2.0, 'timestamp' => $now,
		] ) );
		$fb->flush();

		$other = self::named_url_rows( $store->get_url_shard( $bucket, 'a' ) )[ Stats_Store::OTHER_KEY ];
		$this->assertArrayHasKey( 'tail.example', $other['srv'], 'the folded tail keeps its host' );
		$this->assertSame(
			$other['count'],
			$other['srv']['tail.example']['count'] + Core::num_int( $other['srv']['live.example']['count'] ?? null ),
			'and the split sums to the row: decision 15 is exact PER SERVER'
		);
	}

	/**
	 * The per-URL percentiles are GONE, and the duration reservoir with them.
	 *
	 * A stored percentile was never the window's: percentiles do not merge, so
	 * the fold took ONE bucket's and labelled it as the whole retention window.
	 * The honest fixes were a mergeable sketch (+33 to +129 B/row on a row just
	 * cut to 290) or deletion. Nobody sorts by the column, so deletion wins:
	 * it takes 38 B/row off the read AND the whole `url_dur` namespace — up to
	 * `100` floats per row per bucket — off the write.
	 */
	public function test_a_stored_url_row_carries_no_percentiles_or_reservoir(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => '/pangolin-6142',
				'duration_ms' => 20.0 + $i,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$hash   = Log_Manager::url_hash( '/pangolin-6142' );
		$bucket = Stats_Store::bucket_key( $now );
		$row    = self::named_url_rows( $store->get_url_shard( $bucket, Stats_Store::url_shard( $hash ) ) )[ $hash ];

		foreach ( [ 'p50_ms', 'p95_ms', 'p99_ms', 'durations' ] as $gone ) {
			$this->assertArrayNotHasKey( $gone, $row, "{$gone} is retired" );
		}
		// The mean is not stored either: it divides by `timed_count`, and the
		// reader owns that rule (decision 2, `Performance_CI_Node::mean_ms`).
		$this->assertArrayNotHasKey( 'avg_ms', $row );
		// The exact extremes stay: they fold from duration_ms and cost 2 fields.
		$this->assertSame( 20.0, $row['min_ms'] );
		$this->assertSame( 24.0, $row['max_ms'] );
		// And nothing writes the reservoir namespace any more.
		$this->assertFalse(
			\method_exists( $store, 'get_url_durations' ),
			'the url_dur namespace is gone, not merely unwritten'
		);
	}

	/**
	 * A v0.66.0 row is positional and HAS index 0, so decision 5's legacy probe
	 * cannot see it — and `ROW_SRV` moved onto 14, the index the retired
	 * reservoir occupied. Merging one reads its split as absent, discards that
	 * bucket's whole per-server history, and rides 15..18 back out for a
	 * retention window. Raw integer keys below: this is a RETIRED shape, and
	 * there are no constants left to name it.
	 */
	public function test_a_row_from_before_the_percentiles_were_retired_is_discarded(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \time();
		$bucket     = Stats_Store::bucket_key( $now );
		$url        = 'https://retired.example/quokka-8823';
		$hash       = Log_Manager::url_hash( $url );
		$shard      = Stats_Store::url_shard( $hash );

		// 0..13 as today, then the v0.66.0 tail: srv at 15, percentiles 16-18.
		$store->set_url_shard( $bucket, $shard, [
			$hash => [
				0 => 91, 1 => 91, 2 => 6142.0, 3 => 212.0, 4 => 0, 5 => 88, 6 => 3, 7 => 0,
				8 => $url, 9 => 23.08, 10 => 268.93, 11 => 16.0, 12 => $now, 13 => false,
				15 => [ 'retired.example' => null ], 16 => 24.56, 17 => 132.82, 18 => 268.93,
			],
		] );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => $url,
			'duration_ms' => 47.0,
			'timestamp'   => $now,
			'server_name' => 'retired.example',
		] ) );
		$fb->flush();

		$raw = $store->get_url_shard( $bucket, $shard )[ $hash ];
		$this->assertSame(
			\range( 0, 14 ),
			\array_keys( $raw ),
			'the retired tail does not ride the read-modify-write back out'
		);
		$this->assertSame( 1, $raw[ Stats_Store::ROW_COUNT ], 'and the stale counts go with it' );
		$this->assertSame(
			[ 'retired.example' => null ],
			$raw[ Stats_Store::ROW_SRV ],
			'the split is this flush\'s, at the index it now lives on'
		);
	}

	/** Two hosts are not the row restated, so the split stays whole. */
	public function test_a_two_server_split_does_not_collapse(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		foreach ( [ 'edge-a.example', 'edge-a.example', 'edge-b.example' ] as $server ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => 'https://two-host.example/quokka-8823',
				'duration_ms' => 47.0,
				'timestamp'   => $now,
				'server_name' => $server,
			] ) );
		}
		$fb->flush();

		$hash  = Log_Manager::url_hash( 'https://two-host.example/quokka-8823' );
		$row   = $store->get_url_shard( Stats_Store::bucket_key( $now ), Stats_Store::url_shard( $hash ) )[ $hash ];
		$split = $row[ Stats_Store::ROW_SRV ];
		$this->assertSame( [ 'edge-a.example', 'edge-b.example' ], \array_keys( $split ) );
		$this->assertSame( 2, $split['edge-a.example'][ Stats_Store::ROW_COUNT ] );
		$this->assertSame( 1, $split['edge-b.example'][ Stats_Store::ROW_COUNT ] );
	}

	public function test_a_legacy_named_row_is_not_folded_into_an_hour(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$legacy     = Log_Manager::url_hash( '/pangolin-6142' );
		$live       = Log_Manager::url_hash( '/wombat-4471' );
		$shard      = Stats_Store::url_shard( $live );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		// A live positional row beside a pre-0.65 NAMED one, same shard.
		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$live => [ 'url' => '/wombat-4471', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 91.0 ],
		] );
		$raw = $store->get_url_shard( '2026-08-27-13-05', $shard );
		$raw[ $legacy ] = [
			'url'         => '/pangolin-6142',
			'count'       => 23,
			'timed_count' => 23,
			'sum_ms'      => 1871.0,
		];
		$store->set_url_shard( '2026-08-27-13-05', $shard, $raw );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->roll_up_hours( $now );

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$this->assertSame( 7, $hour[ $live ]['count'] ?? -1, 'the live row folds' );
		$this->assertArrayNotHasKey( $legacy, $hour, 'and the legacy row is not folded into a ghost' );
	}

	/**
	 * An hour folds twelve buckets' URL SETS into one key, so its row count is
	 * the union of twelve capped sets — up to twelve times a bucket's. It takes
	 * the same ceiling the bucket does, and its tail FOLDS into the overflow
	 * rows rather than dropping, so the totals stay exact.
	 */
	public function test_a_folded_hour_takes_the_same_row_cap_as_a_bucket(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		// Six buckets of 120 distinct URLs each, all in one shard: 720 rows
		// into an hour whose ceiling is 500.
		$total = 0;
		foreach ( \array_slice( Stats_Store::buckets_in_hour( '2026-08-27-13' ), 0, 6 ) as $b => $bucket ) {
			$rows = [];
			for ( $i = 0; $i < 120; $i++ ) {
				$rows[ \sprintf( 'a%011x', $b * 1000 + $i ) ] = [
					'url'   => "/row-{$b}-{$i}",
					'count' => 3,
				];
				$total += 3;
			}
			$this->seed_url_shard( $store, $bucket, 'a', $rows );
		}

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->roll_up_hours( $now );

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], 'a' )[0][1] );
		$this->assertLessThanOrEqual( 500, \count( $hour ), 'the hour takes the shard cap' );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $hour, 'the tail folds rather than dropping' );
		$this->assertSame(
			$total,
			\array_sum( \array_column( $hour, 'count' ) ),
			'and the total survives the fold exactly'
		);
	}

	/**
	 * The skip probe is ONE round trip, not one per hour. Steady state is 23
	 * hours already folded and nothing to do, on a flush that runs every few
	 * seconds — asking per hour paid 23 trips to learn that, against an API
	 * that takes a list (decision 6: per-key `get` is a latency cliff).
	 */
	public function test_the_rollup_probe_asks_once_for_every_hour(): void {
		$memd       = new InMemoryMemcached();
		Core::$memd = $memd;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		// Every hour already folded, so the probe is all this flush does.
		foreach ( Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) )['hours'] as $hour ) {
			foreach ( Stats_Store::url_shards() as $shard ) {
				$this->seed_url_hour( $store, $hour, $shard, [] );
			}
		}
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$memd->multi_calls = 0;
		$memd->get_calls   = 0;
		$fb->roll_up_hours( $now );

		$this->assertSame( 1, $memd->multi_calls, 'one probe for the whole window' );
		$this->assertSame( 0, $memd->get_calls, 'and nothing folded, so no per-key reads' );
	}

	/**
	 * A worker that folded an hour is the authority on whether it is folded —
	 * decision 17 puts the fold on the flush path precisely BECAUSE one
	 * partition has one writer. So steady state probes nothing: the probe reads
	 * presence, but `getMulti` fetches and unserializes the VALUES, and the
	 * settled hours are the whole coarse tier.
	 */
	public function test_a_second_flush_does_not_probe_hours_it_folded_itself(): void {
		$memd       = new InMemoryMemcached();
		Core::$memd = $memd;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \gmmktime( 9, 41, 0, 8, 27, 2026 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// First flush: probes, then folds up to its budget.
		$fb->roll_up_hours( $now );
		$memd->multi_calls = 0;

		// Everything it folded, it now knows; only the rest can be probed.
		$fb->roll_up_hours( $now );
		$fb->roll_up_hours( $now );

		$folded = 0;
		foreach ( Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) )['hours'] as $hour ) {
			$folded += \count( $store->url_hour_sources( [ $hour ] ) ) >= Stats_Store::URL_SHARDS ? 1 : 0;
		}
		$this->assertGreaterThanOrEqual( 3, $folded, 'each flush folded its budget' );
	}

	/**
	 * The memo names hours in ONE store's keyspace. `configure_stats <n>` can
	 * repoint a worker at another partition, and a memo that survived it would
	 * assert the old partition's folds and leave the new one's coarse tier
	 * never written at all until the process restarted.
	 */
	public function test_repointing_the_store_forgets_what_was_folded(): void {
		Core::$memd = new InMemoryMemcached();
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( new Stats_Store( partition: 0, max_lifespan: 86400 ) );
		$fb->roll_up_hours( $now );

		$other = new Stats_Store( partition: 3, max_lifespan: 86400 );
		$fb->set_stats_store( $other );
		$fb->roll_up_hours( $now );

		$hour = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) )['hours'][0];
		$this->assertNotSame(
			[],
			$other->url_hour_sources( [ $hour ] ),
			'the new partition gets its own fold'
		);
	}

	/**
	 * An hour with no traffic is still written. A MISSING key means "not folded
	 * yet", which is what sends the reader back to the twelve fine buckets — an
	 * empty hour that looked unfolded would pay that fallback forever.
	 */
	public function test_an_empty_hour_is_still_written_so_it_is_not_read_as_unfolded(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$fb->roll_up_hours( \gmmktime( 15, 7, 0, 8, 27, 2026 ) );

		$this->assertSame(
			[ [ '2026-08-27-13', [] ] ],
			$store->url_hour_sources( [ '2026-08-27-13' ], 'a' ),
			'written with no rows, so the read finds it rather than falling back'
		);
	}

	/** The hour still filling has more to come; folding it would freeze it. */
	public function test_the_open_hour_is_not_rolled_up(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$fb->roll_up_hours( \gmmktime( 15, 7, 0, 8, 27, 2026 ) );

		$this->assertSame( [], $store->url_hour_sources( [ '2026-08-27-15' ], 'a' ) );
	}

	/**
	 * A stored URL row is POSITIONAL — the one test that reads a shard raw, so
	 * the shape is pinned somewhere even though every other test translates at
	 * the seed and read helpers. What it costs, and why, is decision 18's and
	 * the `ROW_*` docblock's; repeating the figure here is a third copy to keep
	 * true.
	 */
	public function test_a_stored_url_row_is_positional(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		// TWO hosts, so the split does not collapse to a host name: this test
		// reads the shape INSIDE it, and a collapse would leave a null to check.
		foreach ( [ 'edge-a.example', 'edge-b.example' ] as $server ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => '/wombat-4471',
				'duration_ms' => 447.0,
				'timestamp'   => $now,
				'server_name' => $server,
			] ) );
		}
		$fb->flush();

		$hash = Log_Manager::url_hash( '/wombat-4471' );
		// RAW, not through the naming read helper: the shape is the assertion.
		$row  = $store->get_url_shard( Stats_Store::bucket_key( $now ), Stats_Store::url_shard( $hash ) )[ $hash ];
		$this->assertSame(
			[],
			\array_values( \array_filter( \array_keys( $row ), '\is_string' ) ),
			'no field NAMES in a stored row — that is the whole point'
		);
		// And the split it carries takes the same treatment. Read through
		// ROW_SRV: `URL_SRV_FIELD` is a NAME, which the assertion above proves
		// a stored row cannot carry, so it would check an empty array.
		$split = \array_values( Core::arr( $row[ Stats_Store::ROW_SRV ] ?? null ) )[0] ?? [];
		$this->assertNotSame( [], $split, 'the row HAS a split to check' );
		$this->assertSame(
			[],
			\array_values( \array_filter( \array_keys( Core::arr( $split ) ), '\is_string' ) ),
			'nor inside the per-server split, which is eight more names per row'
		);
	}

	public function test_url_index_caps_at_500_keeps_top_by_count(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		// 510 distinct URLs in one bucket. They all survive now: the cap is a
		// per-SHARD backstop against an oversized item, not a ceiling on how
		// many URLs a site may have in five minutes — which is what it was when
		// the whole bucket lived in one blob.
		for ( $i = 0; $i < 510; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'         => "/path-$i",
				'duration_ms' => 1.0,
				'timestamp'   => $now,
			] ) );
		}
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$index  = $this->url_bucket_rows( $store, $bucket );
		$this->assertCount( 510, $index );
		foreach ( Stats_Store::url_shards() as $shard ) {
			$this->assertLessThanOrEqual( 500, \count( $store->get_url_shard( $bucket, $shard ) ) );
		}
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

		$dim    = $this->dim_series( $store, 'ua' );
		$bucket = Stats_Store::bucket_key( $now );
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
		$bucket   = Stats_Store::bucket_key( $now );
		$url_dim  = $store->get_url_dimensional_bucket( $url_hash, $bucket );
		$this->assertArrayHasKey( 'ua', $url_dim );
		$this->assertLessThanOrEqual( Stats_Store::MAX_URL_DIM_VALUES, \count( $url_dim['ua'] ) );
	}

	// --- category caps: Other rollover + total preserved ------------------

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

		$cats   = $this->cat_series( $store );
		$bucket = Stats_Store::bucket_key( $now );
		$this->assertArrayHasKey( $bucket, $cats );
		$this->assertLessThanOrEqual( Stats_Store::MAX_CAT_VALUES, \count( $cats[ $bucket ] ) );
		$this->assertArrayHasKey( 'total', $cats[ $bucket ], '"total" pseudo-category preserved' );
		$this->assertArrayHasKey( 'Other', $cats[ $bucket ], 'overflow rolls into Other' );
	}

	public function test_a_flush_leaves_category_buckets_it_did_not_fill_alone(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 3600 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$untouched = [
			'total' => [ 't' => 99, 'c' => 99, 'n' => 99 ],
			'old'   => [ 't' => 99, 'c' => 99, 'n' => 99 ],
		];
		$store->set_category_bucket( '1999-01-01-00-00', $untouched );

		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 5.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		$this->assertSame( $untouched, $store->get_category_bucket( '1999-01-01-00-00' ) );
		$this->assertNotSame( [], $this->cat_series( $store ), 'and the request it did fill landed' );
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

		$bucket = Stats_Store::bucket_key( $now );
		$lb_s   = $store->get_leaderboard_bucket( $bucket, 'srv-cap' );
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

		$bucket    = Stats_Store::bucket_key( $now );
		$srv_cats  = $this->cat_series( $store, 'srv-cat' );
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
		$dim_method = $this->dim_series( $store, 'method', 'srv-x' );
		$this->assertNotEmpty( $dim_method );

		// Per-server dim under 'server' should be EMPTY (skipped).
		$dim_server = $this->dim_series( $store, 'server', 'srv-x' );
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
		$this->assertNotEmpty( $this->recent_hourly( $store ) );
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
		$this->assertEmpty( $state['disable_hooks'], 'plugin-suffix categories never proposed' );
		$this->assertEmpty( $state['add_significant_events'], 'plugin-suffix never significant' );
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
		$this->assertNotEmpty( $state['disable_hooks'] );
		$this->assertNotEmpty( $state['add_significant_events'] );

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

		$bucket = Stats_Store::bucket_key( $now );
		$this->assertNotEmpty( $store->get_leaderboard_bucket( $bucket ), 'it still counts globally' );
		$this->assertSame(
			[],
			\array_filter( Core::$memd->keys(), static fn ( string $k ): bool => \str_contains( $k, ':' . Stats_Store::NS_LB_S . ':' ) ),
			'but no per-server scope is created for a nameless server'
		);
	}

	public function test_a_nameless_server_in_a_restored_checkpoint_stays_out_of_the_global_leaderboard(): void {
		// '' is the GLOBAL scope on the write path, so a per-server bucket
		// carrying it would merge a server's sums into the global series.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $fb_store = $store );

		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$fb->set_clock( static fn() => $now );
		$fb->restore_state( [
			'pending' => [
				$bucket => [
					'leaderboard_by_server' => [
						'' => [ 'count' => 63, 'sum_req_time' => 7.5, 'categories' => [] ],
					],
				],
			],
		] );
		$fb->flush();

		$this->assertSame( [], $store->get_leaderboard_bucket( $bucket ), 'the global series is untouched' );
		$fb->set_clock( null );
	}

	public function test_a_flush_writes_dim_and_cat_per_bucket_and_leaves_others_alone(): void {
		// Bucket-keyed maps under one key meant every flush rewrote the whole
		// series; retention is the key's own TTL now.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$stale_dim = [ '418' => [ 'c' => 83, 's' => 9.5, 'm' => 4.5 ] ];
		$stale_cat = [ 'sabbath' => [ 't' => 7.5, 'c' => 61, 'n' => 3 ] ];
		$store->set_dimensional_bucket( 'status', '1999-01-01-00-00', $stale_dim );
		$store->set_category_bucket( '1999-01-01-00-00', $stale_cat );

		$now = \time();
		$fb->set_clock( static fn() => $now );
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 27.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.25, 'count' => 3, 'entries' => [] ] ],
		] ) );
		$fb->flush();
		$fb->set_clock( null );

		$this->assertSame( $stale_dim, $store->get_dimensional_buckets( 'status', [ '1999-01-01-00-00' ] )[ '1999-01-01-00-00' ] ?? [] );
		$this->assertSame( $stale_cat, $store->get_category_bucket( '1999-01-01-00-00' ) );
		$this->assertNotSame( [], $store->get_dimensional_buckets( 'status', [ Stats_Store::bucket_key( $now ) ] )[ Stats_Store::bucket_key( $now ) ] ?? [], 'this flush landed' );
	}

	public function test_stats_time_the_request_not_the_flame_that_covers_it(): void {
		// The flame's value is raised to COVER its children so the treemap does
		// not overflow. That is a rendering rule; a stat must stay measured.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = 1_700_000_000;
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 137.0,
			'timestamp'   => $now,
			// A covering value far past the measured duration.
			'flame'       => [ 'name' => 'request', 'value' => 911.0, 'children' => [] ],
		] ) );
		$fb->flush();

		$this->assertEqualsWithDelta(
			137.0,
			$store->get_hourly_bucket( Stats_Store::bucket_key( $now ) )['sum_ms'] ?? 0.0,
			1e-6,
			'the request took 137ms; 911 is what the flame was stretched to'
		);
	}

	public function test_a_bucket_revisited_before_the_flush_keeps_both_halves(): void {
		// Bucket keys come from the request's START time and records arrive at
		// COMPLETION, so an older bucket is revisited constantly around a boundary.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$early = 1_700_000_000;
		$late  = $early + 300;
		foreach ( [ $early, $late, $early ] as $ts ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 23.0, 'timestamp' => $ts ] ) );
		}
		$fb->flush();

		$this->assertSame(
			2,
			$store->get_hourly_bucket( Stats_Store::bucket_key( $early ) )['count'] ?? 0,
			'both of the early bucket\'s requests counted'
		);
	}

	public function test_one_url_dimensional_bucket_holds_every_dimension(): void {
		// Transposed to [hash][bucket][dim]: keying the dimension too would be a
		// 7-by-288 cross-product per URL.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		$fb->set_clock( static fn() => $now );
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => '/dims',
			'duration_ms' => 33.0,
			'timestamp'   => $now,
			'status'      => 503,
			'method'      => 'POST',
		] ) );
		$fb->flush();
		$fb->set_clock( null );

		$hash   = Log_Manager::url_hash( '/dims' );
		$bucket = $store->get_url_dimensional_bucket( $hash, Stats_Store::bucket_key( $now ) );
		$this->assertArrayHasKey( 'status', $bucket );
		$this->assertArrayHasKey( 'method', $bucket, 'every dimension shares the bucket key' );
	}

	public function test_a_nameless_server_in_a_restored_checkpoint_stays_out_of_the_global_series(): void {
		// '' is the GLOBAL scope on every write path now, not just the
		// leaderboard's — a per-server bucket carrying it would double-count.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$fb->set_clock( static fn() => $now );
		$fb->restore_state( [
			'pending' => [
				$bucket => [
					'cat_by_server' => [ '' => [ 'db' => [ 't' => 4.5, 'c' => 71, 'n' => 3 ] ] ],
					'dim_by_server' => [ '' => [ 'status' => [ '503' => [ 'c' => 67, 's' => 2.5, 'm' => 1.5 ] ] ] ],
				],
			],
		] );
		$fb->flush();
		$fb->set_clock( null );

		$this->assertSame( [], $store->get_category_bucket( $bucket ), 'global categories untouched' );
		$this->assertSame( [], $store->get_dimensional_buckets( 'status', [ $bucket ] )[ $bucket ] ?? [], 'global dimension untouched' );
	}

	public function test_an_accumulated_other_is_not_clobbered_by_the_next_overflow(): void {
		// `Other` sums the evicted tail, so it sorts HIGH and survives into the
		// kept slice — assigning over it discards every earlier overflow.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );

		// A restored window already carrying a fat Other, plus enough values
		// that the next cap has a tail to roll up.
		$values = [ 'Other' => [ 'c' => 640, 's' => 0.0, 'm' => 0.0 ] ];
		for ( $i = 0; $i <= Stats_Store::MAX_DIM_VALUES; $i++ ) {
			$values[ "v{$i}" ] = [ 'c' => 100 + $i, 's' => 0.0, 'm' => 0.0 ];
		}
		$fb->set_clock( static fn() => $open );
		$fb->restore_state( [
			'pending' => [ $bucket => [ 'dim' => [ 'status' => $values ] ] ],
		] );
		$fb->flush();
		$fb->set_clock( null );

		$after = $store->get_dimensional_buckets( 'status', [ $bucket ] )[ $bucket ] ?? [];
		$this->assertLessThanOrEqual( Stats_Store::MAX_DIM_VALUES, \count( $after ), 'still capped' );
		$this->assertGreaterThanOrEqual( 640, $after['Other']['c'] ?? 0, 'the earlier overflow is still counted' );
	}

	// --- Save state after multiple flushes (idempotency) ------------------

	public function test_double_flush_is_idempotent(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 100.0 ] ) );
		$fb->flush();
		$snap_a = $this->recent_hourly( $store );
		// Second flush with nothing pending — should be a no-op for stats.
		$fb->flush();
		$snap_b = $this->recent_hourly( $store );
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

		$store->set_hourly_bucket( '2026-01-01-00', [ 'count' => 7 ] );
		$store->set_leaderboard_bucket( '2026-01-01-00-05', [ 'count' => 3, 'sum_req_time' => 1.5, 'categories' => [] ] );

		$fb->save_state();
		$p->flush();

		$frames = $this->read_mirror_frames( $p );
		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'hourly:2026-01-01-00' ), $frames, 'hourly aggregate landed' );
		$this->assertSame( [ 'count' => 7 ], $frames[Stats_Store::entry_key( 0, 'hourly:2026-01-01-00' )]['data'] );
		$this->assertSame( 86400, $frames[Stats_Store::entry_key( 0, 'hourly:2026-01-01-00' )]['ttl'] );
		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'lb:2026-01-01-00-05' ), $frames, 'leaderboard aggregate landed' );
	}

	public function test_mirror_buffers_until_save_state(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		$store->set_hourly_bucket( '2026-01-01-00', [ 'count' => 7 ] );
		$p->flush();
		$this->assertArrayNotHasKey( Stats_Store::entry_key( 0, 'hourly:2026-01-01-00' ), $this->read_mirror_frames( $p ), 'not flushed before save_state' );

		$fb->save_state();
		$p->flush();
		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'hourly:2026-01-01-00' ), $this->read_mirror_frames( $p ), 'flushed on save_state' );
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
		$store->set_hourly_bucket( '2026-01-01-00', [ 'count' => 7 ] );
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
			$this->assertContains( Stats_Store::entry_key( 0, "url:h{$i}" ), $url_keys );
		}
		$this->assertNotContains( Stats_Store::entry_key( 0, 'url:h5' ), $url_keys, 'lowest-traffic URL evicted' );
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
		// evict-by-insertion and keep the wrong 100. Persisted shapes, per bucket:
		// url_dim is { dim => { val => {c,s,m} } }; url_cat is { category => {t,c,n} }.
		for ( $i = 1; $i <= 105; $i++ ) {
			$rank = 106 - $i; // h1 busiest (105), h105 quietest (1).
			$store->set_url_dimensional_bucket( "h{$i}", '1700000000', [ 'status' => [ '200' => [ 'c' => $rank, 's' => 0, 'm' => 0 ] ] ] );
			$store->set_url_category_bucket( "h{$i}", '1700000000', [ 'db' => [ 't' => 0, 'c' => 0, 'n' => $rank ], 'total' => [ 't' => 0, 'c' => 0, 'n' => $rank ] ] );
		}

		$fb->save_state();
		$p->flush();

		$frames    = \array_keys( $this->read_mirror_frames( $p ) );
		$dim_keys  = \array_filter( $frames, static fn ( string $k ): bool => \str_starts_with( $k, Stats_Store::entry_key( 0, 'url_dim:' ) ) );
		$cat_keys  = \array_filter( $frames, static fn ( string $k ): bool => \str_starts_with( $k, Stats_Store::entry_key( 0, 'url_cat:' ) ) );
		$this->assertCount( 100, $dim_keys, 'top-100 url_dim retained' );
		$this->assertCount( 100, $cat_keys, 'top-100 url_cat retained' );
		$this->assertContains( Stats_Store::entry_key( 0, 'url_dim:h1:1700000000' ), $dim_keys, 'busiest url_dim retained' );
		$this->assertNotContains( Stats_Store::entry_key( 0, 'url_dim:h105:1700000000' ), $dim_keys, 'quietest url_dim evicted' );
		$this->assertContains( Stats_Store::entry_key( 0, 'url_cat:h1:1700000000' ), $cat_keys, 'busiest url_cat retained' );
		$this->assertNotContains( Stats_Store::entry_key( 0, 'url_cat:h105:1700000000' ), $cat_keys, 'quietest url_cat evicted' );
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
		$this->assertArrayNotHasKey( Stats_Store::entry_key( 0, 'url:empty' ), $frames, 'un-profiled URL not mirrored' );
		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'url:filled' ), $frames, 'profiled URL mirrored' );
	}

	public function test_a_present_key_is_never_overwritten_from_the_mirror(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		/** @var CountingIndexPartition $p */
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats', CountingIndexPartition::class );

		$store->set_hourly_bucket( '2026-01-01-00', [ 'count' => 91 ] );
		$fb->save_state();
		$p->flush();
		// Live memcache moves on; the mirror still holds the older frame.
		$store->set_hourly_bucket( '2026-01-01-00', [ 'count' => 17 ] );
		$p->index_scans = 0;

		$this->assertSame( [ 'count' => 17 ], $store->get_hourly_bucket( '2026-01-01-00' ), 'the live value wins' );
		$this->assertSame( 0, $p->index_scans, 'a hit never consults the mirror' );
	}

	public function test_a_decayed_out_frame_is_not_restored(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ , $p ]    = $this->mirrored_builder( $store, 'flames-stats' );

		// ttl 100 but written 200s ago: age exceeds it, so restore() refuses.
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, 'hourly:2026-01-01-00' ), [ 'count' => 53 ], 100, \time() - 200 );
		$p->flush();

		$this->assertSame( [], $store->get_hourly_bucket( '2026-01-01-00' ), 'a genuinely expired frame stays expired' );
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
		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'url:abc' ), $frames, 'large mirror write survived (partition cap lifted in topology)' );
		$this->assertSame( $data, $frames[Stats_Store::entry_key( 0, 'url:abc' )]['data'] );
	}

	public function test_save_state_without_partition_does_not_throw(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$this->assertIsArray( $fb->save_state() );
	}

	public function test_the_newest_frame_for_a_key_wins(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ , $p ]    = $this->mirrored_builder( $store, 'flames-stats' );

		$now = \time();
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, 'hourly:2026-01-01-00' ), [ 'count' => 29 ], 100, $now );
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, 'hourly:2026-01-01-00' ), [ 'count' => 74 ], 100, $now );
		$p->flush();

		$this->assertSame( [ 'count' => 74 ], $store->get_hourly_bucket( '2026-01-01-00' ), 'the newest frame for a key wins' );
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
		$this->assertStringContainsString( 'command_node fb:config set_stats_target flames-stats', $dump );
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
		$this->assertStringContainsString( 'command_node fb:config configure_stats 3', $fb->dump_config() );
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

		$store->set_hourly_bucket( '2026-01-01-00', [ 'count' => 7 ] );
		$fb->save_state();
		$p->flush();

		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'hourly:2026-01-01-00' ), $this->read_mirror_frames( $p ), 'set_stats_store arms the mirror when a partition name is already set' );
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
		$store->set_hourly_bucket( '2026-01-01-00', [ 'count' => 5 ] );
		$fb->save_state();
		$p->flush();

		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'hourly:2026-01-01-00' ), $this->read_mirror_frames( $p ), 'forward-referenced stats partition resolved lazily at flush' );
	}

	// --- Mirror companion index -------------------------------------------

	public function test_stats_index_locates_a_mirrored_frame(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );
		$p->with_index( Flame_Builder_Node::format_stats_index_entry( ... ) );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		// Values unlike any default: a bucket no other test uses, count 37.
		$store->set_hourly_bucket( '2026-02-03-04-05', [ 'count' => 37 ] );
		$fb->save_state();
		$p->flush();

		$key   = Stats_Store::entry_key( 0, 'hourly:2026-02-03-04-05' );
		$found = null;
		$p->scan_index(
			function ( string $line, int $segment ) use ( &$found, $key, $p ): bool {
				$entry = Flame_Builder_Node::parse_stats_index( $line );
				if ( null === $entry || $entry['key_hash'] !== Log_Manager::url_hash( $key ) ) {
					return true;
				}
				$found = Message::unpacked( $p->read_at( $segment, $entry['offset'], $entry['length'] ) )[ Message::VALUE ];
				return false;
			},
			true
		);

		$this->assertIsArray( $found, 'the index located the hourly frame' );
		$this->assertSame( $key, $found['key'] );
		$this->assertSame( [ 'count' => 37 ], $found['data'] );
	}

	public function test_a_closed_bucket_is_not_re_mirrored_when_a_later_bucket_fills(): void {
		// A bucket is written when it changes, so a closed one is written once.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$early = 1_700_000_000;          // floors to :05
		$late  = $early + 600;           // two buckets later
		$first = Stats_Store::bucket_key( $early );

		$fb->set_clock( static fn() => $early );
		// Two checkpoints while `early` is still open: the old code wrote it at both.
		foreach ( [ 41.0, 43.0 ] as $duration_ms ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => $duration_ms, 'timestamp' => $early ] ) );
			$fb->flush();
			$fb->save_state();
		}

		$fb->set_clock( static fn() => $late );
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 73.0, 'timestamp' => $late ] ) );
		$fb->flush();
		$fb->save_state();
		$p->flush();

		$written = \array_count_values( $this->raw_mirror_frame_keys( $p ) );
		$this->assertSame(
			1,
			$written[ Stats_Store::entry_key( 0, Stats_Store::NS_HOURLY . ':' . $first ) ] ?? 0,
			'a closed bucket is mirrored once and never rewritten'
		);
	}

	public function test_evicted_url_bucket_is_restored_from_the_mirror(): void {
		Core::$memd  = new InMemoryMemcached();
		$store       = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ]  = $this->mirrored_builder( $store, 'flames-stats' );

		$bucket = '2026-02-03-04-05';
		$rows   = [ 'ab12cd34ef56' => [ 'url' => 'https://example.test/jobs/import', 'count' => 639 ] ];
		$this->set_url_bucket( $store, $bucket, $rows );
		// hourly stays warm: it is the sentinel the retired gate keyed on.
		$store->set_hourly_bucket( $bucket, [ 'count' => 12 ] );
		$fb->save_state();
		$p->flush();

		// Evict just that row's shard, the way memcache does under pressure.
		$shard = Stats_Store::url_shard( 'ab12cd34ef56' );
		$key   = Stats_Store::entry_key( 0, "urls:{$shard}:{$bucket}" );
		Core::$memd->delete( $key );
		$this->assertFalse( Core::$memd->get( $key ), 'the shard holding it is evicted' );
		$this->assertNotSame( [], $store->get_hourly_bucket( $bucket ), 'memcache still warm by the old sentinel' );

		$this->assertSame( [ $bucket => $rows ], $this->url_rows_by_bucket( $store, [ $bucket ] ) );
	}

	/**
	 * Nor is the coarse tier. It is DERIVED from the fine buckets, which are
	 * mirrored in full, and `read_plan()`'s fallback rebuilds a missing hour
	 * from them — so a durable copy stores the same information twice, on the
	 * one axis decision 11 says to watch, and an hour frame is the largest
	 * thing that could ride the held set at a checkpoint.
	 */
	public function test_folded_hours_are_not_mirrored(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$this->seed_url_hour( $store, '2026-02-03-04', 'a', [ 'ab12cd34ef56' => [ 'url' => '/x', 'count' => 91 ] ] );
		$fb->save_state();
		$p->flush();

		$this->assertSame(
			[],
			\array_values( \array_filter(
				\array_keys( $this->read_mirror_frames( $p ) ),
				static fn ( string $k ): bool => \str_contains( $k, ':' . Stats_Store::NS_URLS_HOUR . ':' )
			) ),
			'derived from urls; the read_plan fallback rebuilds it'
		);
	}

	public function test_a_frame_mirrored_after_the_first_miss_is_still_found(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$bucket = '2026-02-03-04-05';
		$key    = Stats_Store::entry_key( 0, 'urls:' . Stats_Store::url_shard( 'h' ) . ':' . $bucket );

		$this->set_url_bucket( $store, $bucket, [ 'h' => [ 'url' => '/a', 'count' => 11 ] ] );
		$fb->save_state();
		$p->flush();
		Core::$memd->delete( $key );
		// This read builds the locator table.
		$this->assertSame( 11, ( $this->url_bucket_rows( $store, $bucket ) )['h']['count'] );

		// A newer frame for the same key, mirrored AFTER that table existed.
		$this->set_url_bucket( $store, $bucket, [ 'h' => [ 'url' => '/a', 'count' => 872 ] ] );
		$fb->save_state();
		$p->flush();
		Core::$memd->delete( $key );

		$this->assertSame(
			872,
			( $this->url_bucket_rows( $store, $bucket ) )['h']['count'],
			'the newest mirrored frame, not the one the locator table was built from'
		);
	}

	public function test_every_missing_bucket_is_recovered_in_one_index_pass(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		/** @var CountingIndexPartition $p */
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats', CountingIndexPartition::class );

		$buckets = [ '2026-02-03-04-05', '2026-02-03-04-10', '2026-02-03-04-15' ];
		foreach ( $buckets as $i => $bucket ) {
			$this->set_url_bucket( $store, $bucket, [ "hash{$i}" => [ 'url' => "/j{$i}", 'count' => 641 + $i ] ] );
		}
		$fb->save_state();
		$p->flush();
		foreach ( $buckets as $bucket ) {
			foreach ( Stats_Store::url_shards() as $shard ) {
				Core::$memd->delete( Stats_Store::entry_key( 0, "urls:{$shard}:{$bucket}" ) );
			}
		}
		$p->index_scans = 0;

		$out = $this->url_rows_by_bucket( $store, $buckets );

		$this->assertCount( 3, $out, 'every evicted bucket recovered' );
		$this->assertSame( 641, $out['2026-02-03-04-05']['hash0']['count'] );
		$this->assertSame( 1, $p->index_scans, 'one index pass for all three misses, not one per bucket' );
	}

	/**
	 * An ABORTED request was killed partway — a worker cut off mid-job, or a
	 * gyrobase render whose lease was stolen — so its duration is a fragment of
	 * the real one. Counting it drags the mean down and invents fast
	 * requests that never happened, exactly like the timed-out case above.
	 */
	public function test_aborted_excluded_from_timing(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 12.0, 'error_status' => 'A' ] ) );
		$fb->flush();

		foreach ( $this->recent_hourly( $store ) as $stats ) {
			$this->assertSame( 0, $stats['count'], 'aborted excluded from count' );
			$this->assertSame( 0.0, $stats['sum_ms'], 'aborted excluded from sum_ms' );
		}
	}

	public function test_workers_excluded_from_timing(): void {
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 100.0, 'is_worker' => true ] ) );
		$fb->flush();

		$hourly = $this->recent_hourly( $store );
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

		$bucket   = Stats_Store::bucket_key( $now );
		$url_hash = Log_Manager::url_hash( '/?cache-cozy' );

		// Per-URL timing IS kept for the synthetic worker row.
		$index = $this->url_bucket_rows( $store, $bucket );
		$this->assertArrayHasKey( $url_hash, $index );
		$this->assertSame( 1, $index[ $url_hash ]['count'] );
		$this->assertSame( 1, $index[ $url_hash ]['timed_count'] );
		$this->assertEqualsWithDelta( 40.0, $index[ $url_hash ]['sum_ms'], 1e-6 );

		// Global hourly: no count, no timing, and no peak (closed leak).
		foreach ( $this->recent_hourly( $store ) as $stats ) {
			$this->assertSame( 0, $stats['count'], 'worker excluded from global count' );
			$this->assertSame( 0.0, $stats['sum_ms'], 'worker excluded from global timing' );
			$this->assertSame( 0.0, $stats['sum_peak_mb'], 'worker peak leak closed' );
		}

		// Global dimensional: no count and no peak contribution.
		$dim = $this->dim_series( $store, 'status' );
		foreach ( $dim as $vals ) {
			foreach ( $vals as $cell ) {
				$this->assertSame( 0, $cell['c'], 'worker excluded from global dimensional count' );
				$this->assertSame( 0, $cell['m'], 'worker excluded from global dimensional peak' );
			}
		}

		// Global leaderboard + categories: untouched by the worker.
		$this->assertEmpty( $store->get_leaderboard_bucket( $bucket ) );
		$this->assertEmpty( $store->get_category_bucket( $bucket ) );
	}

	public function test_non_worker_request_records_global_count_and_peak(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/x', 'duration_ms' => 40.0, 'peak_mb' => 12.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$hourly = $store->get_hourly_buckets( [ Stats_Store::bucket_key( $now ) ] );
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
		$bucket = Stats_Store::bucket_key( $now );
		$this->assertEmpty( $store->get_leaderboard_bucket( $bucket, 'srv-a' ) );

		// Hub: per-server bucket should be populated.
		$this->fill_request( $fb_hub, $req );
		$fb_hub->flush();
		$lb_s = $store->get_leaderboard_bucket( $bucket, 'srv-a' );
		$this->assertSame( 1, $lb_s['count'] ?? 0 );
	}

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
		$this->assertSame( [ 'noisy_hook' ], $state['disable_hooks']['loud'] );
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
		$this->assertEmpty( $state['disable_hooks'] );
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
		$this->assertEmpty( $state['disable_hooks'] );
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
		$this->assertSame( [ 'wpdb' ], $state['disable_hooks']['r'] );
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
		$this->assertEmpty( $state['disable_hooks'], 'worker traffic must not drive global hook auto-disable' );
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
		$this->assertEmpty( $state['disable_hooks'], 'callback categories never proposed' );
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
		$this->assertSame( [ 'slow_hook' ], $state['add_significant_events']['r'] );
	}

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

	/**
	 * The scan pre-filters on a raw column before parsing, so the offsets it
	 * slices with have to come from the writer that laid the line out.
	 */
	public function test_index_column_locates_the_matchable_fields_the_writer_wrote(): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = [ 'rid' => 'qq4-flame-rid-8e02', 'url_hash' => 'c0ffee5eeded' ];
		$line = (string) Flame_Builder_Node::format_index_entry(
			$message,
			[ 'segment' => 7, 'offset' => 4096, 'length' => 55 ]
		);

		[ $rid_offset, $rid_length ]   = Flame_Builder_Node::index_column( 'rid' );
		[ $hash_offset, $hash_length ] = Flame_Builder_Node::index_column( 'url_hash' );

		$this->assertSame( 'qq4-flame-rid-8e02', \trim( \substr( $line, $rid_offset, $rid_length ) ) );
		$this->assertSame( 'c0ffee5eeded', \trim( \substr( $line, $hash_offset, $hash_length ) ) );
	}

	public function test_the_flame_index_carries_no_completion_columns(): void {
		// The flame line is rid(32) url_hash(12) segment(6) offset(10) length(8):
		// no timestamp anywhere, so a retention bound cannot read one off it and
		// offset 44 is `segment`, not a time.
		$this->assertSame( [], Flame_Builder_Node::index_completion_columns() );
	}

	public function test_index_column_offers_no_column_for_a_zero_padded_field(): void {
		// `segment` is zero-padded, so its column never equals its parsed int.
		$this->assertSame( [], Flame_Builder_Node::index_column( 'segment' ) );
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

	public function test_save_and_restore_pending_state_round_trip(): void {
		$now = 1_700_000_000;
		$fb  = new Flame_Builder_Node();
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 50.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );

		$saved  = $fb->save_state();
		$bucket = Stats_Store::bucket_key( $now );
		$this->assertArrayHasKey( $bucket, $saved['pending'] );

		$fb2 = new Flame_Builder_Node();
		$fb2->restore_state( $saved );
		$this->assertSame( $saved['pending'], $fb2->save_state()['pending'] );
	}

	public function test_the_open_bucket_is_held_back_until_it_closes(): void {
		// flame-stats keeps only the last state of a bucket, so writing the open
		// one at every checkpoint is ~ten redundant copies per bucket.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$open = 1_700_000_000;
		$key  = Stats_Store::entry_key( 0, Stats_Store::NS_HOURLY . ':' . Stats_Store::bucket_key( $open ) );
		$fb->set_clock( static fn() => $open );

		foreach ( [ 61.0, 62.0, 63.0 ] as $duration_ms ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => $duration_ms, 'timestamp' => $open ] ) );
			$fb->flush();
			$fb->save_state();
		}
		$p->flush();
		$this->assertNotContains( $key, $this->raw_mirror_frame_keys( $p ), 'the open bucket stays out of the mirror' );

		// Closed: the next checkpoint writes it once, whole.
		$fb->set_clock( static fn() => $open + 300 );
		$fb->save_state();
		$p->flush();
		$fb->set_clock( null );

		$this->assertSame( 1, \array_count_values( $this->raw_mirror_frame_keys( $p ) )[ $key ] ?? 0, 'written once, at close' );
		$this->assertSame( 3, $this->read_mirror_frames( $p )[ $key ]['data']['count'], 'carrying the whole bucket' );
	}

	public function test_the_held_open_bucket_survives_a_respawn_through_the_checkpoint(): void {
		// The open bucket is not in flame-stats, so the offsetlog is what backs
		// it: save_state() carries the held frames, restore_state() takes them on.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$open = 1_700_000_000;
		$key  = Stats_Store::entry_key( 0, Stats_Store::NS_HOURLY . ':' . Stats_Store::bucket_key( $open ) );
		$fb->set_clock( static fn() => $open );
		foreach ( [ 71.0, 72.0 ] as $duration_ms ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => $duration_ms, 'timestamp' => $open ] ) );
			$fb->flush();
		}
		$checkpoint = $fb->save_state();
		$fb->remove_node();

		// The respawn: a fresh builder resuming from that frame alone.
		$successor = new Flame_Builder_Node();
		$successor->name( 'fb' );
		$successor->set_stats_store( new Stats_Store( partition: 0, max_lifespan: 86400 ) );
		$successor->set_stats_target( $p->name() );
		// The successor's clock is its own from the start: restore decays a held
		// frame's TTL by how long the checkpoint sat, so the two must agree.
		$successor->set_clock( static fn() => $open + 60 );
		$successor->restore_state( $checkpoint );
		$successor->set_clock( static fn() => $open + 300 );
		$successor->save_state();
		$p->flush();
		$successor->set_clock( null );

		$frames = $this->read_mirror_frames( $p );
		$this->assertSame(
			2,
			$frames[ $key ]['data']['count'] ?? 0,
			'the bucket the predecessor held reached the mirror when it closed'
		);
		// The bounded per-URL buffers ride the same frame; their ranks re-derive.
		$url_dim = Stats_Store::entry_key(
			0,
			Stats_Store::NS_URL_DIM . ':' . Log_Manager::url_hash( '/post/123' ) . ':' . Stats_Store::bucket_key( $open )
		);
		$this->assertSame( 2, $frames[ $url_dim ]['data']['method']['GET']['c'] ?? 0, 'and so did the per-URL top-N' );
	}

	public function test_an_evicted_open_bucket_is_repaired_from_the_held_frames(): void {
		// The open bucket is in no durable log yet, so the HELD frames are the
		// only copy a read-modify-write can recover it from.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		$fb->set_clock( static fn() => $open );

		foreach ( \range( 1, 9 ) as $ignored ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 31.0, 'timestamp' => $open ] ) );
		}
		$fb->flush();
		$fb->save_state();
		$this->assertSame( 9, $store->get_hourly_bucket( $bucket )['count'] ?? 0, 'nine landed' );

		// memcached evicts the open bucket under pressure.
		Core::$memd->delete( Stats_Store::entry_key( 0, Stats_Store::NS_HOURLY . ':' . $bucket ) );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 32.0, 'timestamp' => $open ] ) );
		$fb->flush();
		$fb->set_clock( null );

		$this->assertSame(
			10,
			$store->get_hourly_bucket( $bucket )['count'] ?? 0,
			'the merge read through the held frame instead of restarting from zero'
		);
	}

	public function test_a_held_frame_whose_life_ran_out_is_not_restored(): void {
		// ADR-18: a stated lifetime that has run out is a miss, not a resurrection.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 7200 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		$fb->set_clock( static fn() => $open );
		$store->set_hourly_bucket( $bucket, [ 'count' => 47 ] );
		$checkpoint = $fb->save_state();
		$fb->set_clock( null );

		$successor = new Flame_Builder_Node();
		// Resumed a day later: the frame's 2h life is long gone.
		$successor->set_clock( static fn() => $open + 86400 );
		$successor->restore_state( $checkpoint );

		$this->assertSame(
			[],
			$successor->save_state()['mirror']['frames'][''] ?? [],
			'the expired frame did not come back'
		);
		$successor->set_clock( null );
	}

	public function test_an_evicted_per_url_bucket_is_repaired_from_the_held_top_n(): void {
		// The per-URL namespaces are held in a different buffer; it reads too.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		$hash   = Log_Manager::url_hash( '/post/123' );
		$fb->set_clock( static fn() => $open );

		foreach ( \range( 1, 6 ) as $ignored ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 29.0, 'timestamp' => $open ] ) );
		}
		$fb->flush();
		$this->assertSame( 6, $store->get_url_dimensional_bucket( $hash, $bucket )['method']['GET']['c'] ?? 0 );

		Core::$memd->delete( Stats_Store::entry_key( 0, Stats_Store::NS_URL_DIM . ':' . $hash . ':' . $bucket ) );
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 30.0, 'timestamp' => $open ] ) );
		$fb->flush();
		$fb->set_clock( null );

		$this->assertSame(
			7,
			$store->get_url_dimensional_bucket( $hash, $bucket )['method']['GET']['c'] ?? 0,
			'the per-URL merge read through its held frame'
		);
	}

	public function test_the_checkpoint_carries_held_frames_only_up_to_its_budget(): void {
		// The offsetlog bounds keyframe COUNT, not bytes, and the per-server
		// aggregates grow with the spoke count — so the carry bounds itself.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		$fb->set_clock( static fn() => $open );

		$store->set_hourly_bucket( $bucket, [ 'count' => 3 ] );
		$store->set_leaderboard_bucket( $bucket, [ 'blob' => \str_repeat( 'x', 3000000 ) ] );

		// One namespaced space now: flatten it, the assertions are about WHICH
		// frames rode, not which namespace they sat under.
		$carried = [];
		foreach ( $fb->save_state()['mirror']['frames'] ?? [] as $frames ) {
			$carried += \is_array( $frames ) ? $frames : [];
		}
		$fb->set_clock( null );

		$this->assertArrayHasKey(
			Stats_Store::entry_key( 0, Stats_Store::NS_HOURLY . ':' . $bucket ),
			$carried,
			'the small frame rides the checkpoint'
		);
		$this->assertArrayNotHasKey(
			Stats_Store::entry_key( 0, Stats_Store::NS_LB . ':' . $bucket ),
			$carried,
			'the one past the budget does not'
		);
	}

	public function test_a_restored_row_missing_a_field_does_not_fault_the_accumulator(): void {
		// `restore_state()` merges the BUCKET over `empty_bucket()` and stops,
		// so the per-URL rows inside arrive exactly as the checkpoint held
		// them. The accumulator must not fault on a key one of them lacks —
		// `count( null )` is fatal, not a warning.
		$now    = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $now );
		$url    = '/restored/partial';
		$hash   = Log_Manager::url_hash( $url );

		$fb = new Flame_Builder_Node();
		$fb->restore_state( [
			'pending' => [
				$bucket => [
					// A checkpoint holds the STORED shape, which is positional.
					'url_stats' => [
						$hash => self::positional_url_row(
							[ 'url' => $url, 'count' => 6, 'timed_count' => 6, 'sum_ms' => 300.0 ]
						),
					],
				],
			],
		] );

		$this->fill_request( $fb, $this->completed_request( [
			'url'         => $url,
			'duration_ms' => 41.0,
			'timestamp'   => $now,
		] ) );

		$rows = self::named_url_rows( $fb->save_state()['pending'][ $bucket ]['url_stats'] ?? [] );
		$this->assertSame( 7, $rows[ $hash ]['count'] ?? 0 );
	}
}

/**
 * A Flame_Builder whose intern table fills after three names, so a test can
 * reach the freeze without pushing 50000 distinct strings through it.
 */
class TinyInternFlameBuilder extends Flame_Builder_Node {
	protected const INTERN_TABLE_LIMIT = 3;
}

/** Counts index scans, so a test can pin the batch path to ONE pass. */
class CountingIndexPartition extends \Newspack_Nodes\Partition_Node {
	public int $index_scans = 0;

	public function scan_index( callable $cb, bool $newest_first = false ): void {
		++$this->index_scans;
		parent::scan_index( $cb, $newest_first );
	}

}
