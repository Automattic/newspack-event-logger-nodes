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
// The overflow tests fill two shard families past their caps: 0.5s under coverage.
#[CoversClass( Flame_Builder_Node::class )]
class FlameBuilderTest extends TestCase {

	/**
	 * An hour inside the retention window, and one of its five-minute buckets.
	 *
	 * The mirror seam sizes what it hands back by what is LEFT of the window,
	 * so a fixed historical date is past retention and recovers nothing — which
	 * is right, and would make a mirror-recovery test vacuous.
	 */
	private static function live_hour(): string {
		return \gmdate( 'Y-m-d-H', \time() - 3600 );
	}

	/** One five-minute bucket of that hour. */
	private static function live_bucket(): string {
		return self::live_hour() . '-05';
	}

	/** @var list<string> Temp partition dirs created during a test, removed in tearDown. */
	private array $temp_dirs = [];

	protected function tearDown(): void {
		foreach ( $this->temp_dirs as $dir ) {
			$this->rrmdir( $dir );
		}
		$this->temp_dirs               = [];
		Flame_Builder_Node::$usleep_fn = null;
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
		$msg[ Message::VALUE ]       = [ 'data' => $data, 'ttl' => $ttl ];
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
				$msg = Message::unpacked( $line );
				$val = $msg[ Message::VALUE ];
				if ( \is_array( $val ) && \is_string( $msg[ Message::KEY ] ?? null ) ) {
					$out[] = $val + [ 'key' => $msg[ Message::KEY ] ];
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

	/**
	 * Every mirror frame in a flushed partition as the whole unpacked message —
	 * what a size or an envelope assertion reads, where `mirror_frames()` gives
	 * only VALUE.
	 *
	 * @return list<array<int,mixed>>
	 */
	private function raw_mirror_messages( \Newspack_Nodes\Partition_Node $p ): array {
		$out = [];
		foreach ( $p->get_segments( true ) as $seg ) {
			$bytes = $p->read_at( (int) $seg['id'], 0, (int) $seg['size'] );
			foreach ( \explode( "\n", $bytes ) as $line ) {
				if ( '' !== $line ) {
					$out[] = Message::unpacked( $line );
				}
			}
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

	/**
	 * A clean stop flushes the pending buckets and hands the checkpoint
	 * nothing to carry.
	 *
	 * The periodic flush runs inside fill(), on a record arriving five seconds
	 * or more after the last one, and an on-demand worker rarely sees such a
	 * record: it spawns on a backlog, folds it within the second, idles out.
	 * Every record it folded rode `pending` into the checkpoint and out to the
	 * next worker, never reaching the store — gazettenet carried six hours of
	 * URL stats that way while its dashboard read zero. The substrate calls
	 * `shutdown_sweep()` on every clean stop, so that is where they land.
	 */
	public function test_a_clean_stop_flushes_the_pending_buckets_to_the_store(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/signup', 'duration_ms' => 1132.0 ] ) );
		$this->assertSame( [], $this->recent_hourly( $store ), 'a record within the flush window stays pending' );

		$this->assertInstanceOf( \Newspack_Nodes\Shutdown_Sweeper::class, $fb );
		$fb->shutdown_sweep();

		$hourly = $this->recent_hourly( $store );
		$this->assertCount( 1, $hourly );
		$this->assertSame( 1, \array_values( $hourly )[0]['count'] );
		$rows = $store->url_row_sources( \array_keys( $hourly ) );
		$this->assertCount( 1, $rows, 'the URL row landed with the hourly total' );
		$this->assertSame( [], $fb->save_state()['pending'], 'the checkpoint carries nothing forward' );
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

	public function test_a_flush_ranks_the_bucket_it_wrote_site_wide_and_per_server(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now = \time();
		foreach ( [ 1, 2, 3 ] as $i ) {
			$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731', 'duration_ms' => 40.0, 'server_name' => 'kea.test', 'timestamp' => $now ] ) );
		}
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://moa.test/kiwi-8842', 'duration_ms' => 900.0, 'server_name' => 'moa.test', 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$wombat = Log_Manager::url_hash( 'https://kea.test/wombat-7731' );
		$kiwi   = Log_Manager::url_hash( 'https://moa.test/kiwi-8842' );

		$by_count = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
		$this->assertCount( 1, $by_count );
		$this->assertSame( [ $wombat, $kiwi ], \array_column( $by_count[0][1], Stats_Store::RANK_HASH ) );
		$this->assertSame( 3, $by_count[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
		$this->assertArrayNotHasKey( Stats_Store::ROW_PATH, $by_count[0][1][0][ Stats_Store::RANK_ROW ] );

		$slowest = $store->url_rank_window( [], [ $bucket ], 'avg_ms', 'desc', '' );
		$this->assertSame( [ $kiwi, $wombat ], \array_column( $slowest[0][1], Stats_Store::RANK_HASH ) );

		$by_url = $store->url_rank_window( [], [ $bucket ], 'url', 'asc', '' );
		$this->assertSame( '/kiwi-8842', $by_url[0][1][0][ Stats_Store::RANK_PATH ] );

		// A URL belongs to one site, so a server's list holds its own rows only.
		$moa = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', 'moa.test' );
		$this->assertSame( [ $kiwi ], \array_column( $moa[0][1], Stats_Store::RANK_HASH ) );
		$this->assertSame( 1, $moa[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
		$this->assertSame( [], $store->url_rank_window( [], [ $bucket ], 'count', 'desc', 'tui.test' ) );
	}

	public function test_a_second_flush_ranks_the_whole_bucket_not_its_own_rows(): void {
		// The list is derived from the STORED bucket after the merge landed,
		// so a URL flushed earlier keeps its place against one flushed now.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now = \time();
		foreach ( [ 1, 2, 3, 4, 5 ] as $i ) {
			$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731', 'timestamp' => $now ] ) );
		}
		Core::$now = $now;
		$fb->flush();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/kiwi-8842', 'timestamp' => $now ] ) );
		// Past RANK_EVERY_S, so the second flush ranks rather than waiting.
		Core::$now = $now + 61;
		$fb->flush();

		$list = $store->url_rank_window( [], [ Stats_Store::bucket_key( $now ) ], 'count', 'desc', '' )[0][1];
		$this->assertSame(
			[ Log_Manager::url_hash( 'https://kea.test/wombat-7731' ), Log_Manager::url_hash( 'https://kea.test/kiwi-8842' ) ],
			\array_column( $list, Stats_Store::RANK_HASH )
		);
		$this->assertSame( 5, $list[0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
	}

	public function test_a_flush_landing_every_reader_shard_reads_no_extra_shard(): void {
		// Every reader shard freshly written this flush leaves the ranker's
		// gap-fill nothing to read back, so its cost must not creep toward a
		// whole-bucket scan — the common case on a busy (hub) site.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			/** @var array<int,array<int,array{0:array<int,string>,1:string}>> */
			public array $reads_log = [];
			public string $watch = '';
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				// The rollup reads the same way; count reads of the FLUSHED bucket.
				foreach ( $reads as [ , $bucket ] ) {
					if ( $this->watch === $bucket ) {
						$this->reads_log[] = $reads;
						break;
					}
				}
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now = \time();

		$seen = [];
		for ( $i = 0; \count( $seen ) < Stats_Store::URL_SHARDS; $i++ ) {
			$url   = "https://tui.test/moa-{$i}-9001";
			$shard = Stats_Store::url_shard( Log_Manager::url_hash( $url ) );
			if ( isset( $seen[ $shard ] ) ) {
				continue;
			}
			$seen[ $shard ] = true;
			$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'timestamp' => $now ] ) );
		}
		$this->assertCount( Stats_Store::URL_SHARDS, $seen, 'every reader shard represented before the flush' );

		$store->reads_log = [];
		$store->watch     = Stats_Store::bucket_key( $now );
		$fb->flush();

		$this->assertCount(
			2,
			$store->reads_log,
			'the server index and flush_writes() alone; the rank gap-fill reads nothing extra'
		);
	}

	public function test_a_worker_only_flush_ranks_nothing(): void {
		// No reader-family traffic landed this flush, so the lists are a
		// pure function of rows this request never touched — nothing ranks,
		// and the gap-fill never runs to fetch them either.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			/** @var array<int,array<int,array{0:array<int,string>,1:string,2:array<array-key,mixed>}>> */
			public array $set_log = [];
			/** @var array<int,array<int,array{0:array<int,string>,1:string}>> */
			public array $get_log = [];
			public function bucket_set_multi( array $writes ): array {
				$this->set_log[] = $writes;
				return parent::bucket_set_multi( $writes );
			}
			public string $watch = '';
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				// The rollup reads the same way; count reads of the FLUSHED bucket.
				foreach ( $reads as [ , $bucket ] ) {
					if ( $this->watch === $bucket ) {
						$this->get_log[] = $reads;
						break;
					}
				}
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/wren-3312?worker_type', 'is_worker' => true, 'timestamp' => $now ] ) );

		$store->set_log = [];
		$store->get_log = [];
		$store->watch   = Stats_Store::bucket_key( $now );
		$fb->flush();

		foreach ( $store->set_log as $writes ) {
			foreach ( $writes as [ $parts ] ) {
				$this->assertNotContains(
					$parts[0],
					[ Stats_Store::NS_URLRANK_S ],
					'no rank write from worker-only traffic'
				);
			}
		}
		$this->assertCount( 2, $store->get_log, 'the server index and flush_writes() alone; the rank gap-fill never runs' );
	}

	public function test_write_url_ranks_gives_a_server_of_overflow_rows_empty_lists(): void {
		// A server holding only its overflow row has nothing rankable, but the
		// index names it, so it gets its lists, empty: a reader merging the
		// site's lists tells a server ranked idle from one never ranked.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			/** @var array<int,array<int,array{0:array<int,string>,1:string,2:array<array-key,mixed>}>> */
			public array $set_log = [];
			public function bucket_set_multi( array $writes ): array {
				$this->set_log[] = $writes;
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$servers = [
			'good.test'  => [ 'hash1' => self::positional_url_row( [ 'path' => '/good-4471', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 40.0 ] ) ],
			'bogus.test' => [ Stats_Store::OTHER_KEY => self::positional_url_row( [ 'count' => 9, 'timed_count' => 9, 'sum_ms' => 90.0 ] ) ],
		];

		( new \ReflectionMethod( $fb, 'write_url_ranks' ) )->invoke(
			$fb, $store, '2026-09-22-13-05', $servers, false
		);

		$servers_written = [];
		foreach ( $store->set_log as $writes ) {
			foreach ( $writes as [ $parts, , $entries ] ) {
				if ( Stats_Store::NS_URLRANK_S === $parts[0] ) {
					$servers_written[ $parts[1] ][] = \count( $entries );
				}
			}
		}
		$this->assertSame( 14, \array_sum( $servers_written[ Stats_Store::server_key( 'good.test' ) ] ?? [] ), 'the real server ranks its one row' );
		$this->assertSame( \array_fill( 0, 14, 0 ), $servers_written[ Stats_Store::server_key( 'bogus.test' ) ] ?? null, 'an overflow-only server gets empty lists' );
	}

	public function test_write_url_ranks_chunks_its_writes_past_the_batch_size(): void {
		// 37 servers x 14 lists each = 518 writes,
		// over WRITE_BATCH_KEYS (500), so the round trip chunks like
		// flush_writes() does rather than sending one unbounded batch.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			public int $set_calls = 0;
			public function bucket_set_multi( array $writes ): array {
				++$this->set_calls;
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$servers = [];
		for ( $i = 0; $i < 37; $i++ ) {
			$servers[ "srv{$i}.test" ] = [
				\sprintf( 'a%011x', $i ) => self::positional_url_row( [ 'path' => "/many-servers-{$i}", 'count' => 1, 'timed_count' => 1, 'sum_ms' => 5.0 ] ),
			];
		}

		( new \ReflectionMethod( $fb, 'write_url_ranks' ) )->invoke(
			$fb, $store, '2026-09-22-13-05', $servers, false
		);

		$this->assertGreaterThan( 1, $store->set_calls, 'a large ranking round trip chunks like flush_writes() does' );
	}

	public function test_an_hour_whose_lists_all_land_is_marked_done(): void {
		// The marker lands beside the lists, so the probe reads the hour as
		// ranked rather than folding it again.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$hour = '2026-08-27-13';
		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'c6c6c6c6c6c6' ), [
			'c6c6c6c6c6c6' => [ 'url' => '/marked-done-3318', 'count' => 6 ],
		] );
		( new \ReflectionProperty( $fb, 'stale_hours' ) )->setValue( $fb, [ $hour => true ] );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->flush_buckets( $fb, [] );

		$this->assertSame( 6, self::ranked_count( $store, $hour, true ), 'the fixture ranks the hour' );
		$this->assertSame(
			[],
			$this->url_rank_done( $store, $hour ),
			'the marker is present and empty: nothing reads a value here'
		);
	}

	/**
	 * One server's DONE marker for an hour, or null while its hour is unranked.
	 *
	 * @return array<string,mixed>|null
	 */
	private function url_rank_done( Stats_Store $store, string $hour, string $server = self::SEED_SERVER ): ?array {
		return $store->bucket_get_multi( [ [ Stats_Store::url_rank_done_parts( Stats_Store::server_key( $server ) ), $hour ] ] )[0];
	}

	public function test_a_refused_ranking_is_logged_once_and_never_retried(): void {
		// Over the item limit never fits, so nothing retries it: the log is
		// the signal that N is wrong. Both tiers, past a refresh and past the
		// bucket's close, with no new write. Seeds distinct from every
		// default: 13 requests into the bucket, 17 into the folded hour.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			/** @var array<string,int> */
			public array $attempts = [];
			public function bucket_set_multi( array $writes ): array {
				[ $parts, $key ] = $writes[0] ?? [ [ '' ], '' ];
				if ( \in_array( $parts[0], [ self::NS_URLRANK_S, self::NS_URLRANK_HOUR_S ], true ) ) {
					$this->attempts[ $key ] = ( $this->attempts[ $key ] ?? 0 ) + 1;
					return \array_fill( 0, \count( $writes ), false );
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$at     = \gmmktime( 9, 7, 0, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		$hour   = '2026-09-22-07';
		// Folded with no marker: the probe's find, ranked from its rows.
		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( 'd4d4d4d4d4d4' ), [
			'd4d4d4d4d4d4' => [ 'url' => '/refused-hour-4471', 'count' => 17 ],
		] );
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, [ $hour => true ] );
		( new \ReflectionProperty( $fb, 'stale_hours' ) )->setValue( $fb, [ $hour => true ] );
		// Hour 06 names a server, so its fold has a list to refuse.
		$this->set_url_bucket( $store, '2026-09-22-06-10', [
			'e7e7e7e7e7e7' => [ 'url' => '/refused-fold-6610', 'count' => 11, 'last_seen' => $at - 10000 ],
		] );

		Core::$now = $at;
		$this->flush_buckets( $fb, [
			$bucket => [ 'url_stats' => [ 'b5b5b5b5b5b5' => self::positional_url_row( [ 'count' => 13, 'last_seen' => $at ] ) ] ],
		] );
		$this->assertSame( 1, $store->attempts[ $bucket ] ?? 0, 'the bucket ranked once' );
		$this->assertSame( 1, $store->attempts[ $hour ] ?? 0, 'the hour ranked once' );
		$this->assertStringContainsString( 'URL rank write refused', $err );

		// Full flushes: the first also folds the next hour, whose ranking the
		// probe must memoize like a landed one rather than re-rank.
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [] );
		foreach ( [ Stats_Store::URL_PAGE_REFRESH_S, 400, 400 + Stats_Store::URL_PAGE_REFRESH_S ] as $later ) {
			Core::$now = $at + $later;
			$fb->flush();
		}
		$this->assertNotSame( $bucket, Stats_Store::bucket_key( $at + 400 ), 'the fixture closes the bucket' );
		$this->assertSame( 1, $store->attempts[ $bucket ], 'the bucket is not ranked again' );
		$this->assertSame( 1, $store->attempts[ $hour ], 'nor the hour' );
		$this->assertSame( 1, $store->attempts['2026-09-22-06'] ?? 0, 'the folded hour ranked once' );
		$this->assertSame( [], ( new \ReflectionProperty( $fb, 'rank_pending' ) )->getValue( $fb ) );
		$this->assertSame( [], ( new \ReflectionProperty( $fb, 'stale_hours' ) )->getValue( $fb ) );
	}

	public function test_the_ranking_cadence_and_the_memo_prune_read_one_instant(): void {
		// The tick moves across a bucket boundary during the store read, as a
		// right_now() caller inside a flush (a lock, a heartbeat) moves it: a
		// second read would move the prune floor an hour on and drop a memo
		// entry the flush's own instant keeps.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			public int $later = 0;
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				Core::$now = $this->later;
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$first        = \gmmktime( 10, 59, 59, 9, 22, 2026 );
		$store->later = $first + 2;
		$due          = Stats_Store::bucket_key( $first );
		$fine_at      = static fn ( int $at ): array => Stats_Store::read_plan( Stats_Store::retention_buckets( $store->ttl(), $at ) )['fine'];
		$tail         = $fine_at( $first );
		$edge         = (string) \end( $tail );
		$this->assertNotContains( $edge, $fine_at( $store->later ), 'the tick crosses the floor' );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$ranked_at = new \ReflectionProperty( $fb, 'ranked_at' );
		$ranked_at->setValue( $fb, [ $edge => $first - 37 ] );
		( new \ReflectionProperty( $fb, 'rank_pending' ) )->setValue( $fb, [ $due => true ] );

		Core::$now = $first;
		$fb->flush();

		$this->assertSame( $first - 37, $ranked_at->getValue( $fb )[ $edge ] ?? null, 'the prune floor dates from the first instant' );
		$this->assertSame( $first, $ranked_at->getValue( $fb )[ $due ] ?? null, 'the cadence stamp dates from the first instant' );
	}

	public function test_a_flush_stamps_its_rankings_and_names_with_the_tick_it_read_first(): void {
		// The tick moves during the chunk's store read, as a right_now()
		// caller inside a flush moves it; every date the flush writes must
		// still be the instant it began at.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			public int $later = 0;
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				Core::$now = $this->later;
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$first        = 1_600_000_123;
		$store->later = $first + 61;
		$hash         = 'c6c6c6c6c6c6';
		$bucket       = Stats_Store::bucket_key( $first );
		$fb           = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		Core::$now = $first;
		$this->flush_buckets( $fb, [ $bucket => [
			'url_stats' => [ $hash => self::positional_url_row( [ 'count' => 3, 'last_seen' => $first ] ) ],
			'url_names' => [ $hash => '/tick-once-4417' ],
		] ] );

		$this->assertSame( $store->later, (int) Core::$now, 'the store read did tick the clock' );
		$this->assertSame( $first, ( new \ReflectionProperty( $fb, 'ranked_at' ) )->getValue( $fb )[ $bucket ] ?? null, 'the ranking stamp dates from the first instant' );
		$this->assertSame(
			$first,
			( new \ReflectionProperty( $fb, 'named_urls' ) )->getValue( $fb )->get( Stats_Store::server_key( self::SEED_SERVER ) . ':' . $hash ),
			'the name memo dates from the first instant'
		);
	}

	public function test_the_shutdown_sweep_ranks_a_bucket_the_cadence_deferred(): void {
		// The pending memo lives in memory alone and dies with the worker, so
		// the last flush ranks what the cadence was still holding back. Seeds
		// distinct from every default: 6 requests ranked, then 5 more.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$hash   = 'b8b8b8b8b8b8';
		$at     = \gmmktime( 11, 7, 0, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		$rows   = static fn ( int $count ): array => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => $count, 'last_seen' => $at ] ) ] ];

		Core::$now = $at - 10;
		$this->flush_buckets( $fb, [ $bucket => $rows( 6 ) ] );
		Core::$now = $at;
		$this->flush_buckets( $fb, [ $bucket => $rows( 5 ) ] );
		$this->assertSame( 6, self::ranked_count( $store, $bucket, false ), 'ten seconds on is inside the cadence' );

		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [] );
		Core::$now = $at + 3;
		$fb->shutdown_sweep();
		$this->assertSame( 11, self::ranked_count( $store, $bucket, false ), 'the sweep ranks it anyway' );
	}

	public function test_a_flush_dates_every_stage_from_one_read_of_the_tick(): void {
		// The tick moves during the coarse probe, as a right_now() caller
		// inside a flush moves it; the ranking placed after it must still date
		// from the flush's first instant, or the hour plan and the cadence
		// disagree on which hour has closed.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			public int $later = 0;
			public function url_hours_derived( array $hours ): array {
				Core::$now = $this->later;
				return parent::url_hours_derived( $hours );
			}
		};
		$first        = \gmmktime( 10, 3, 0, 9, 22, 2026 );
		$store->later = $first + 61;
		$bucket       = Stats_Store::bucket_key( $first );
		$fb           = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [
			$bucket => \array_replace(
				( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ),
				[ 'url_stats' => [ self::SEED_SERVER => [ 'e4e4e4e4e4e4' => self::positional_url_row( [ 'count' => 13, 'last_seen' => $first ] ) ] ] ]
			),
		] );

		Core::$now = $first;
		$fb->flush();

		$this->assertSame( $store->later, (int) Core::$now, 'the probe did tick the clock' );
		$this->assertSame( $first, ( new \ReflectionProperty( $fb, 'ranked_at' ) )->getValue( $fb )[ $bucket ] ?? null );
	}

	/** The top `count desc` entry's count in one site-wide list, or null. */
	private static function ranked_count( Stats_Store $store, string $key, bool $hour ): ?int {
		$list = $store->url_rank_window( $hour ? [ $key ] : [], $hour ? [] : [ $key ], 'count', 'desc', '' );
		return $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null;
	}

	public function test_a_bucket_ranks_once_a_minute_rather_than_once_a_flush(): void {
		// The page cache and the ranked reader look once a minute, so ranking
		// every five-second flush spends twelve rankings on one read. Seeds
		// distinct from every default: counts 4, 7 and 9, one hash.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$hash   = 'a4a4a4a4a4a4';
		$at     = \gmmktime( 14, 2, 0, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		$count  = function ( Stats_Store $store ) use ( $bucket ): ?int {
			$list = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
			return $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null;
		};
		$flush  = function ( int $now, int $rows ) use ( $fb, $hash, $bucket ): void {
			Core::$now = $now;
			$this->flush_buckets( $fb, [
				$bucket => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => $rows, 'last_seen' => $now ] ) ] ],
			] );
		};

		$flush( $at, 4 );
		$this->assertSame( 4, $count( $store ), 'a bucket never ranked ranks at once' );

		$flush( $at + 20, 7 );
		$flush( $at + 40, 9 );
		$this->assertSame( 4, $count( $store ), 'three flushes inside the minute, one ranking' );
		$this->assertSame( 20, $this->url_bucket_rows( $store, $bucket )[ $hash ]['count'], 'the ROWS still merge every flush' );

		$flush( $at + 61, 1 );
		$this->assertSame( 21, $count( $store ), 'past the minute it ranks the whole stored bucket' );
	}

	public function test_a_bucket_that_has_closed_ranks_on_the_next_flush(): void {
		// A closed bucket is what the reader reads for the rest of the window,
		// so the last writes into it must reach its lists rather than waiting
		// out a cadence nothing will come back for.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$hash   = 'b5b5b5b5b5b5';
		$at     = \gmmktime( 14, 4, 50, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		$flush  = function ( int $now, int $rows ) use ( $fb, $hash, $bucket ): void {
			Core::$now = $now;
			$this->flush_buckets( $fb, [
				$bucket => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => $rows, 'last_seen' => $now ] ) ] ],
			] );
		};

		$flush( $at, 6 );
		// Twenty seconds later, and a bucket boundary has passed.
		$flush( $at + 20, 5 );
		$this->assertNotSame( $bucket, Stats_Store::bucket_key( $at + 20 ), 'the fixture crosses the boundary' );

		$list = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
		$this->assertSame( 11, $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
	}

	public function test_a_closed_bucket_the_cadence_deferred_ranks_on_a_later_flush(): void {
		// A flush inside `RANK_EVERY_S` of the last ranking defers the bucket
		// and drops its collected rows. Nothing writes into that bucket once
		// it closes, and the closed-bucket clause only fires on a write — so
		// the deferred rows reach the lists through the pending memo or not
		// at all. Seeds distinct from every default: counts 4 and 7.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$hash   = 'f8f8f8f8f8f8';
		$at     = \gmmktime( 14, 2, 0, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		$count  = static function () use ( $store, $bucket ): ?int {
			$list = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
			return $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null;
		};
		$flush  = function ( int $now, int $rows ) use ( $fb, $hash, $bucket ): void {
			Core::$now = $now;
			$this->flush_buckets( $fb, [
				$bucket => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => $rows, 'last_seen' => $now ] ) ] ],
			] );
		};

		$flush( $at, 4 );
		$flush( $at + 30, 7 );
		$this->assertSame( 4, $count(), 'the second flush is inside the minute, so it defers' );

		// The bucket has closed, and this flush writes nothing into it.
		Core::$now = $at + 400;
		$this->assertNotSame( $bucket, Stats_Store::bucket_key( $at + 400 ), 'the fixture closes the bucket' );
		$this->flush_buckets( $fb, [] );

		$this->assertSame( 11, $count(), 'the deferred rows reach the lists once the bucket closes' );
	}

	public function test_a_new_store_ranks_the_current_bucket_on_its_first_flush(): void {
		// `set_stats_store()` moves the writer to another keyspace, and every
		// rank memo names buckets in the old one — so a bucket ranked there
		// is unranked here, whatever the cadence says. Seeds distinct from
		// every default: counts 13 and 21.
		Core::$memd = new InMemoryMemcached();
		$first      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$second     = new Stats_Store( partition: 1, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $first );
		$hash   = 'a9a9a9a9a9a9';
		$at     = \gmmktime( 14, 1, 0, 9, 22, 2026 );
		$bucket = Stats_Store::bucket_key( $at );
		$flush  = function ( int $now, int $rows ) use ( $fb, $hash, $bucket ): void {
			Core::$now = $now;
			$this->flush_buckets( $fb, [
				$bucket => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => $rows, 'last_seen' => $now ] ) ] ],
			] );
		};

		$flush( $at, 13 );
		$fb->set_stats_store( $second );
		$flush( $at + 10, 21 );

		$list = $second->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
		$this->assertSame(
			21,
			$list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null,
			'the new store ranks the bucket its first flush wrote'
		);
	}

	public function test_two_intents_for_one_key_compose_before_the_read(): void {
		// Two fine buckets of a FOLDED hour land on one `urls_h` key. Composed
		// as the intents are built, one pre-read serves one write and both
		// rows reach it; a second merge built on the one pre-read value would
		// discard the first's rows outright.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			/** @var list<string> */
			public array $hour_writes = [];
			public function bucket_set_multi( array $writes ): array {
				foreach ( $writes as [ $parts, $bucket ] ) {
					if ( self::NS_URLS_HOUR === $parts[0] ) {
						$this->hour_writes[] = self::key( ...[ ...$parts, $bucket ] );
					}
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$hour  = '2026-08-27-13';
		$one   = 'c6c6c6c6c6c6';
		$two   = 'c7c7c7c7c7c7';
		$shard = Stats_Store::url_shard( $one );
		$this->assertSame( $shard, Stats_Store::url_shard( $two ), 'the fixture puts both rows in one shard' );
		// A folded hour holds its shard, empty or not.
		$this->seed_url_hour( $store, $hour, $shard, [] );
		$store->hour_writes = [];
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, [ $hour => true ] );

		$this->flush_buckets( $fb, [
			$hour . '-05' => [ 'url_stats' => [ $one => self::positional_url_row( [ 'count' => 6, 'last_seen' => 1756300000 ] ) ] ],
			$hour . '-40' => [ 'url_stats' => [ $two => self::positional_url_row( [ 'count' => 8, 'last_seen' => 1756302000 ] ) ] ],
		] );

		$rows = self::named_url_rows( $store->url_hour_sources( [ $hour ], $shard )[0][1] );
		$this->assertSame( 6, $rows[ $one ]['count'] );
		$this->assertSame( 8, $rows[ $two ]['count'] );
		$this->assertSame(
			[ Stats_Store::key( Stats_Store::NS_URLS_HOUR, Stats_Store::server_key( self::SEED_SERVER ), $shard, $hour ) ],
			$store->hour_writes,
			'one key, written once'
		);
	}

	public function test_a_bucket_is_ranked_before_the_next_chunk_is_written(): void {
		// Ranking after the whole flush holds every bucket's merged reader
		// shards until it ends, which is the memory the chunking exists to
		// bound — a replay spanning the window would hold 288 of them.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			/** @var list<string> */
			public array $log = [];
			public function bucket_set_multi( array $writes ): array {
				foreach ( $writes as [ $parts, $bucket ] ) {
					$this->log[] = $parts[0] . ':' . $bucket;
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now     = \time();
		$earlier = Stats_Store::bucket_key( $now - 300 );
		$later   = Stats_Store::bucket_key( $now );
		$hash    = Log_Manager::url_hash( 'https://kea.test/hoiho-5520' );
		// 520 per-URL dimension keys push the LATER bucket's rows past the
		// 500-key write chunk, so the two buckets rank in different chunks.
		$dims = [];
		for ( $i = 0; $i < 520; ++$i ) {
			$dims[ \sprintf( '%012x', $i ) ] = [];
		}
		$this->flush_buckets( $fb, [
			$earlier => [
				'url_stats' => [ $hash => self::positional_url_row( [ 'count' => 4, 'last_seen' => $now - 300 ] ) ],
				'url_dim'   => $dims,
			],
			$later => [
				'url_stats' => [ $hash => self::positional_url_row( [ 'count' => 6, 'last_seen' => $now ] ) ],
			],
		] );

		$ranked_first = \array_search( Stats_Store::NS_URLRANK_S . ':' . $earlier, $store->log, true );
		$rows_second  = \array_search( Stats_Store::NS_URLS . ':' . $later, $store->log, true );
		$this->assertIsInt( $ranked_first, 'the first bucket ranked' );
		$this->assertIsInt( $rows_second, 'the second bucket wrote its rows' );
		$this->assertLessThan( $rows_second, $ranked_first, 'and it ranked before that write' );
	}

	public function test_a_buckets_url_writes_are_never_split_across_write_chunks(): void {
		// A bucket's reader-family rows and names are ONE ranking group, and
		// a chunk never splits one: split across two, the bucket ranks off
		// the first chunk's shards alone and the rest never reach its lists.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now = \time();
		// 480 per-URL dimension keys, then a bucket whose sixteen reader
		// shards are 32 more: 512 keys over the 500-key chunk.
		$dims = [];
		for ( $i = 0; $i < 480; ++$i ) {
			$dims[ \sprintf( '%012x', 0xd00000 + $i ) ] = [];
		}
		$rows = [];
		for ( $i = 0; $i < Stats_Store::URL_SHARDS; ++$i ) {
			$rows[ \dechex( $i ) . '1a2b3c4d5e6' ] = self::positional_url_row( [ 'count' => 3, 'last_seen' => $now ] );
		}
		$this->assertCount( Stats_Store::URL_SHARDS, Stats_Store::rows_by_shard( $rows ), 'one row per reader shard' );

		$bucket = Stats_Store::bucket_key( $now );
		$this->flush_buckets( $fb, [
			Stats_Store::bucket_key( $now - 300 ) => [ 'url_dim' => $dims ],
			$bucket                               => [ 'url_stats' => $rows ],
		] );

		$list = $store->url_rank_window( [], [ $bucket ], 'count', 'desc', '' );
		$this->assertCount(
			Stats_Store::URL_SHARDS,
			$list[0][1],
			'every shard of the bucket reached its lists'
		);
	}

	public function test_a_write_that_never_ranks_joins_no_ranking_group(): void {
		// A worker shard or the leaderboard collects nothing for the ranker,
		// so its write forms no group — it neither holds a chunk open nor
		// ranks off the back of one. A folded hour's write goes to its fine
		// bucket, grouped with nothing, and to the hour key where it exists.
		$fb      = new Flame_Builder_Node();
		$for     = new \ReflectionMethod( $fb, 'hour_tier_intents' );
		$merge   = static fn ( array $value ): array => $value;
		$refused = static function (): void {};
		$collect = static function ( array $merged ): void {};
		$intents = static fn ( ?\Closure $collects ): array => \array_map(
			static fn ( array $i ): array => [ $i['parts'], $i['bucket'], $i['landed'], $i['group'], $i['present'] ],
			$for->invoke( $fb, '2026-09-21-11-15', [ 'fine' ], [ 'coarse' ], $merge, $refused, $collects, 'c0ffee42' )
		);

		$this->assertSame(
			[ [ [ 'fine' ], '2026-09-21-11-15', null, null, false ] ],
			$intents( null ),
			'a write that never ranks reports to no one'
		);
		$this->assertSame(
			[ [ [ 'fine' ], '2026-09-21-11-15', $collect, '2026-09-21-11-15 c0ffee42', false ] ],
			$intents( $collect ),
			'a ranked write collects, under its (bucket, server) group'
		);

		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, [ '2026-09-21-11' => true ] );
		$folded = $intents( $collect );
		$this->assertSame(
			[ [ 'fine' ], '2026-09-21-11-15', null, null, false ],
			$folded[0],
			'a write into a folded hour still reaches its fine bucket, ranked by no one'
		);
		$this->assertSame(
			[ [ 'coarse' ], '2026-09-21-11', null, true ],
			[ $folded[1][0], $folded[1][1], $folded[1][3], $folded[1][4] ],
			'and the hour key, only where it exists'
		);
		$this->assertNotNull( $folded[1][2], 'where landing unranks the HOUR' );
		( $folded[1][2] )( [] );
		$this->assertSame(
			[ '2026-09-21-11' => [ 'c0ffee42' => true ] ],
			( new \ReflectionProperty( $fb, 'unranked_hours' ) )->getValue( $fb ),
			'for that server alone'
		);
		$this->assertNull( $intents( null )[1][2], 'and a folded write that never ranks still reports to no one' );
	}

	public function test_the_buckets_of_one_chunk_gap_fill_in_one_read(): void {
		// The ranker reads back only the shards the flush did not land. One
		// read per BUCKET is a round trip per bucket on a replay; the set
		// ranked together asks once.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			public int $gap_fills = 0;
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				$url_only = [] !== $reads;
				foreach ( $reads as [ $parts ] ) {
					if ( self::NS_URLS !== $parts[0] ) {
						$url_only = false;
					}
				}
				$this->gap_fills += $url_only ? 1 : 0;
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now     = \time();
		$buckets = [];
		foreach ( [ 0, 300, 600 ] as $back ) {
			$buckets[ Stats_Store::bucket_key( $now - $back ) ] = [
				'hourly'    => [ 'count' => 1 ],
				'url_stats' => [
					Log_Manager::url_hash( "https://kea.test/pukeko-{$back}" ) => self::positional_url_row(
						[ 'count' => 2, 'last_seen' => $now - $back ]
					),
				],
			];
		}
		$this->flush_buckets( $fb, $buckets );

		$this->assertSame( 1, $store->gap_fills, 'three buckets, one gap-fill read' );
	}

	public function test_the_fine_tier_writes_its_lists_in_one_batch(): void {
		// Only `url_hours_derived()` probes a sentinel, and it probes the HOUR
		// tier alone, so a fine bucket has nothing to hold its count list back
		// for — and a second round trip per bucket per flush is what it would
		// cost to do so anyway.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			public int $rank_batches = 0;
			public function bucket_set_multi( array $writes ): array {
				if ( self::NS_URLRANK_S === ( $writes[0][0][0] ?? '' ) ) {
					++$this->rank_batches;
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/tuatara-9077', 'duration_ms' => 77.0, 'timestamp' => $now ] ) );
		$this->flush_buckets( $fb, [
			Stats_Store::bucket_key( $now ) => [
				'url_stats' => [ 'f9f9f9f9f9f9' => self::positional_url_row( [ 'count' => 9, 'last_seen' => $now ] ) ],
			],
		] );

		$this->assertSame( 1, $store->rank_batches, 'one batch, no sentinel held back' );
		$this->assertNotEmpty( $store->url_rank_window( [], [ Stats_Store::bucket_key( $now ) ], 'count', 'desc', '' ) );
	}

	public function test_the_rank_namespaces_are_not_mirrored(): void {
		$mirrors = new \ReflectionMethod( Flame_Builder_Node::class, 'mirrors_key' );
		foreach ( [ Stats_Store::NS_URLRANK_S, Stats_Store::NS_URLRANK_HOUR_S ] as $ns ) {
			$this->assertFalse( $mirrors->invoke( null, $ns . ':count:desc:2026-09-22-14' ), "$ns is derived from urls" );
		}
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

	public function test_a_flush_files_each_servers_rows_under_its_own_key(): void {
		// Per-server data has the server in the KEY: two servers' rows never
		// share one value, so neither competes for the other's shard cap, and
		// a row carries only the path its key does not already say.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now = \time();
		foreach ( [ 1, 2, 3 ] as $i ) {
			$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731', 'server_name' => 'kea.test', 'duration_ms' => 40.0, 'timestamp' => $now ] ) );
		}
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'http://moa.test/kiwi-8842', 'server_name' => 'moa.test', 'duration_ms' => 90.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$wombat = Log_Manager::url_hash( 'https://kea.test/wombat-7731' );
		$kiwi   = Log_Manager::url_hash( 'http://moa.test/kiwi-8842' );
		$kea    = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $wombat ), 'kea.test' ) );
		$moa    = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $kiwi ), 'moa.test' ) );

		$this->assertSame( [ $wombat ], \array_keys( $kea ), 'kea.test\'s key holds kea.test\'s rows alone' );
		$this->assertSame( 3, $kea[ $wombat ]['count'] );
		$this->assertSame( '/wombat-7731', $kea[ $wombat ]['path'], 'the https origin is the key\'s' );
		$this->assertSame( [ $kiwi ], \array_keys( $moa ) );
		$this->assertSame( 'http://moa.test/kiwi-8842', $moa[ $kiwi ]['path'], 'an http URL is kept whole' );
		$this->assertSame(
			[ Stats_Store::server_key( 'kea.test' ) => 'kea.test', Stats_Store::server_key( 'moa.test' ) => 'moa.test' ],
			$store->server_index( [], [ $bucket ] )[ $bucket ] ?? null,
			'the bucket\'s index names both'
		);
	}

	public function test_a_server_past_the_index_cap_is_filed_under_other(): void {
		// Host-header spray would otherwise mint a key set per request: once
		// a bucket names MAX_SERVER_VALUES servers, a new one's rows share the
		// `Other` server key, and the index gains that one entry.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$index  = [];
		for ( $i = 0; $i < Stats_Store::MAX_SERVER_VALUES; $i++ ) {
			$index[ Stats_Store::server_key( "spray{$i}.test" ) ] = "spray{$i}.test";
		}
		$store->bucket_set_multi( [ [ Stats_Store::url_srv_parts( false ), $bucket, $index ] ] );

		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://late-4471.test/kokako', 'server_name' => 'late-4471.test', 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://spray7.test/y', 'server_name' => 'spray7.test', 'timestamp' => $now ] ) );
		$fb->flush();

		$late    = Log_Manager::url_hash( 'https://late-4471.test/kokako' );
		$shard   = Stats_Store::url_shard( $late );
		$stored  = $store->server_index( [], [ $bucket ] )[ $bucket ];
		$this->assertCount( Stats_Store::MAX_SERVER_VALUES + 1, $stored );
		$this->assertSame( Stats_Store::OTHER_KEY, $stored[ Stats_Store::server_key( Stats_Store::OTHER_KEY ) ] );
		$this->assertArrayNotHasKey( Stats_Store::server_key( 'late-4471.test' ), $stored );
		$this->assertSame( 1, self::named_url_rows( $this->get_url_shard( $store, $bucket, $shard, Stats_Store::OTHER_KEY ) )[ $late ]['count'] );
		$this->assertSame( [ 'koka' => [ $late ] ], $store->url_token_sets( [ 'koka' ], [ Stats_Store::OTHER_KEY ] ), 'its tokens are filed where its rows are' );
		$this->assertSame( [], $store->url_token_sets( [ 'koka' ], [ 'late-4471.test' ] ) );
		$spray = Log_Manager::url_hash( 'https://spray7.test/y' );
		$this->assertArrayHasKey( $spray, $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $spray ), 'spray7.test' ), 'a named server keeps its key' );
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
			public function bucket_set_multi( array $writes ): array {
				return \array_fill( 0, \count( $writes ), false );
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

		$servers = $this->get_dimensional_bucket( $store, 'server', Stats_Store::bucket_key( $now ) );

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
		// One under the cap, so the row this flush adds lands EXACTLY on it.
		$cap = Flame_Builder_Node::MAX_URLS_PER_SHARD;
		for ( $i = 0; $i < $cap - 1; $i++ ) {
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url' => "/cap{$i}", 'count' => $cap + 500 - $i, 'timed_count' => $cap + 500 - $i,
				'sum_ms' => 2.0 * ( $cap + 500 - $i ), 'min_ms' => 2.0, 'max_ms' => 2.0,
				'sum_peak_mb' => 0, 'max_peak_mb' => 0, 'count_2xx' => $cap + 500 - $i,
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

		$this->assertLessThanOrEqual( $cap, \count( $this->get_url_shard( $store, $bucket, 'a' ) ) );
	}

	public function test_the_overflow_row_keeps_worker_traffic_separate(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// Each population caps its OWN tail now, in its own shard family, so
		// the two overflow rows can no longer be produced by one blob — and a
		// worker share can no longer ride into a header that excludes it.
		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$seed   = [];
		$cap    = Flame_Builder_Node::MAX_URLS_PER_SHARD;
		// Alternating, so BOTH families overflow their own cap.
		for ( $i = 0; $i < 2 * ( $cap + 100 ); $i++ ) {
			$base = 2 * $cap + 1000 - $i;
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url' => "/u{$i}", 'count' => $base, 'timed_count' => $base,
				'sum_ms' => 2.0 * $base, 'min_ms' => 2.0, 'max_ms' => 2.0,
				'sum_peak_mb' => 0, 'max_peak_mb' => 0, 'count_2xx' => $base,
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
		// One of each: a shard is capped when it is WRITTEN, and each family is
		// written by its own traffic.
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 2.0, 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 3.0, 'timestamp' => $now, 'is_worker' => true ] ) );
		$fb->flush();

		$shard  = self::named_url_rows( $this->get_url_shard( $store, $bucket, 'a' ) );
		$worker = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( 'a', true ) ) );

		$this->assertArrayNotHasKey( Stats_Store::other_key( true ), $shard, 'the reader family folds reader rows only' );
		$this->assertFalse( $shard[ Stats_Store::other_key( false ) ]['worker'] );
		$this->assertTrue( $worker[ Stats_Store::other_key( true ) ]['worker'], 'and the worker family its own' );
		$this->assertLessThanOrEqual( $cap, \count( $worker ) );
		// Two overflow rows means two reserved slots, not one — the cap is a
		// ceiling on the ITEM, and reserving for one row while emitting two
		// puts it over by exactly the row that was supposed to bound it.
		$this->assertLessThanOrEqual( $cap, \count( $shard ) );
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

	public function test_a_host_header_spray_folds_instead_of_growing_the_axis(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->set_is_hub( true );

		// @longform `server_name` is `SERVER_NAME`, which under Apache's default
		// `UseCanonicalName Off` is the CLIENT'S Host header — so on a
		// domain-mapped multisite or any catch-all vhost this axis is visitor
		// input, not the fleet. Uncapped, the global `dim:server` item grows
		// without limit until memcached refuses the write, and the URL index
		// mints a key set per name.
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
		$servers = $this->get_dimensional_bucket( $store, 'server', $bucket );
		$index   = $store->server_index( [], [ $bucket ] )[ $bucket ];
		$hash    = Log_Manager::url_hash( '/xmlrpc.php' );

		$this->assertLessThanOrEqual( Stats_Store::MAX_SERVER_VALUES, \count( $servers ) );
		$this->assertCount( Stats_Store::MAX_SERVER_VALUES + 1, $index, 'the index names the cap and `Other`' );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $servers );
		$this->assertContains( Stats_Store::OTHER_KEY, $index );
		// Folded, not dropped: every request is still counted on both axes.
		$this->assertSame( $spray, \array_sum( \array_column( $servers, Stats_Store::DIM_COUNT ) ) );
		$this->assertSame( $spray, $this->url_bucket_rows( $store, $bucket )[ $hash ]['count'] );
	}

	public function test_a_nameless_producer_is_filed_under_unknown(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// `accumulate_dimensions()` maps an empty server to the literal
		// 'Unknown' on the `server` axis, and the dashboard builds its picker
		// from THAT axis — so WP-CLI and cron traffic put 'Unknown' in the
		// dropdown, and choosing it has to find their rows. The two axes have
		// to agree on the name.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/cron', 'server_name' => '', 'duration_ms' => 12.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$hash   = Log_Manager::url_hash( '/cron' );
		$this->assertSame( [ Stats_Store::server_key( 'Unknown' ) => 'Unknown' ], $store->server_index( [], [ $bucket ] )[ $bucket ] );
		$this->assertSame( 1, self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash ), 'Unknown' ) )[ $hash ]['count'] );
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
			$bytes    = \strlen( \serialize( $this->get_url_shard( $store, $bucket, $shard ) ) );
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

	public function test_a_stored_url_row_carries_its_path_and_not_its_origin(): void {
		// The server is the key, so the origin on every row of every bucket
		// would say it again. The path is what the row names; the whole URL
		// still lives once in the name table.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$url    = 'https://bend.example/2026/08/31/a-headline-worth-101-bytes/';
		$hash   = Log_Manager::url_hash( $url );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'server_name' => 'bend.example', 'duration_ms' => 2.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$row = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash ), 'bend.example' ) )[ $hash ] ?? [];
		$this->assertSame( '/2026/08/31/a-headline-worth-101-bytes/', $row['path'] ?? null );
		$this->assertSame(
			[ $hash => [ 'server' => 'bend.example', 'url' => $url ] ],
			$store->get_url_names( [ $hash ] )
		);
	}

	public function test_worker_traffic_is_indexed_apart_from_reader_traffic(): void {
		// One row per URL merged both kinds behind a `worker` flag, so a URL a
		// job also visits left the default table taking its READER requests
		// with it — and every default read paid for rows it then discarded.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$url    = '/served-both-ways-8261';
		$hash   = Log_Manager::url_hash( $url );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 8.0, 'timestamp' => $now ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 61.0, 'timestamp' => $now, 'is_worker' => true ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'duration_ms' => 62.0, 'timestamp' => $now, 'is_worker' => true ] ) );
		$fb->flush();

		$reader = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash ) ) );
		$worker = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash, true ) ) );

		$this->assertSame( 1, $reader[ $hash ]['count'] ?? -1, 'the reader row counts reader requests only' );
		$this->assertSame( 2, $worker[ $hash ]['count'] ?? -1, 'and the worker rows are their own index' );
	}

	public function test_the_capped_tail_folds_into_one_other_row(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// A dropped tail would make every total summed from this index a lower
		// bound by however much traffic fell off. Folding it into one row keeps
		// the shard bounded and the totals exact.
		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$seed   = [];
		$cap    = Flame_Builder_Node::MAX_URLS_PER_SHARD;
		for ( $i = 0; $i < $cap + 100; $i++ ) {
			// All in one shard, which is where the cap now applies.
			$base = $cap + 500 - $i;
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url'         => "/u{$i}",
				// Descending, so the last 101 are the ones that fall off.
				'count'       => $base,
				'timed_count' => $base,
				'sum_ms'      => 2.0 * $base,
				'count_2xx'   => $base,
				'count_3xx'   => 0,
				'count_4xx'   => 0,
				'count_5xx'   => 0,
				'min_ms'      => 2.0,
				'max_ms'      => 2.0,
				'sum_peak_mb' => 0,
				'max_peak_mb' => 0,
				'last_seen'   => $now,
			];
		}
		$this->set_url_bucket( $store, $bucket, $seed, 'alpha.example' );

		// A shard is capped when it is WRITTEN, so the flush has to land in it.
		$url = '';
		for ( $i = 0; '' === $url; $i++ ) {
			if ( 'a' === Stats_Store::url_shard( Log_Manager::url_hash( "/in-a-{$i}" ) ) ) {
				$url = "/in-a-{$i}";
			}
		}
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url, 'server_name' => 'alpha.example', 'duration_ms' => 2.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$shard = self::named_url_rows( $this->get_url_shard( $store, $bucket, 'a', 'alpha.example' ) );
		$other = $shard[ Stats_Store::OTHER_KEY ] ?? null;

		$this->assertNotNull( $other, 'the tail folds into one row' );
		// Two under the cap plus both overflow rows: a slot is reserved for each
		// the fold can emit, whether or not this tail fills them.
		$this->assertLessThanOrEqual( $cap, \count( $shard ) );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $shard );
		// cap+101 rows in the shard (cap+100 seeded plus the one just written,
		// count 1), keeping cap-2 — a slot reserved for each overflow row the
		// fold can emit — folds the 103 smallest: counts 502 down to 401, and
		// the 1. Seeding from cap+500 holds those two numbers still.
		$expected = \array_sum( \range( 401, 502 ) ) + 1;
		$this->assertSame( $expected, $other['count'] );
		$this->assertSame( '', $other['path'], 'many URLs, so no one path' );
	}

	public function test_the_url_index_is_filed_by_server_on_a_spoke_too(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$fb->set_is_hub( false );

		// The three per-server AGGREGATES are hub-only, and this one looks like
		// it forgot the gate. It did not: the server filter is offered wherever
		// the `server` dimension has values, which is everywhere, so gating
		// this would empty the URL table on every spoke rather than save it
		// anything.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/spoke', 'server_name' => 'lone.example', 'duration_ms' => 12.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		$hash   = Log_Manager::url_hash( '/spoke' );
		$this->assertSame( [ Stats_Store::server_key( 'lone.example' ) => 'lone.example' ], $store->server_index( [], [ $bucket ] )[ $bucket ] );
		$this->assertArrayHasKey( $hash, $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash ), 'lone.example' ) );
	}

	public function test_a_stored_row_carries_exactly_the_summed_fields(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// The accumulator adds its fields by hand while the persist merge sums
		// `ROW_SUMS`. A field added to one and not the other is dropped or
		// invented silently, so the two are held to the same set here.
		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => '/fields', 'server_name' => 'alpha.example', 'duration_ms' => 30.0, 'peak_mb' => 4.0, 'timestamp' => $now ] ) );
		$fb->flush();

		$row = $this->url_bucket_rows( $store, Stats_Store::bucket_key( $now ) )[ Log_Manager::url_hash( '/fields' ) ];

		// Values, not keys: `sum_entry()` materializes all eight at persist, so a
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
			\array_intersect_key( $row, \array_flip( \array_intersect_key( Stats_Store::ROW_FIELD_NAMES, Stats_Store::ROW_SUMS ) ) )
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
		$this->assertSame( 1, $dim[ $bucket ]['2xx'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( 1, $dim[ $bucket ]['5xx'][ Stats_Store::DIM_COUNT ] );
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
			'leaderboard' => $this->get_leaderboard_bucket( $store, $bucket ),
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
		$this->assertEqualsWithDelta( 0.4, $cats[ $bucket ]['wpdb'][ Stats_Store::CAT_MS ], 1e-6 );
		$this->assertEqualsWithDelta( 12.0, $cats[ $bucket ]['wpdb'][ Stats_Store::CAT_CALLS ], 1e-6 );

		// Leaderboard bucket holds sums-not-means.
		$lb = $this->get_leaderboard_bucket( $store, $bucket );
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
		Core::$now = $open;
		$this->set_hourly_bucket( $store, $bucket, [ 'count' => 5 ] );
		$this->set_leaderboard_bucket( $store, $bucket, [ 'blob' => \str_repeat( 'z', 9000 ) ] );
		$fb->save_state();

		$stats = $this->get_stats( $fb );
		$this->assertGreaterThan( 9000, $stats['mirror_held_bytes'], 'their bytes are reported' );
	}

	public function test_held_frames_reports_the_namespace_the_backstop_binds_on(): void {
		// `MAX_HELD_FRAMES` is PER namespace, so a cross-namespace TOTAL cannot
		// warn: six namespaces holding five thousand each read as thirty
		// thousand with nothing near the bound, while one spilling on every
		// write reads as ten thousand and fifty. The payload therefore reports
		// the WIDEST single namespace, which is the number the bound is against.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb ]     = $this->mirrored_builder( $store, 'flames-stats' );

		$open = 1_700_000_000;
		Core::$now = $open;
		$bucket = Stats_Store::bucket_key( $open );
		// Four frames across three namespaces; the widest holds two.
		$this->set_hourly_bucket( $store, $bucket, [ 'count' => 41 ] );
		$this->set_dimensional_bucket( $store, 'status', $bucket, [ '418' => self::dim_entry( 9, 0, 0 ) ] );
		$this->set_category_bucket( $store, $bucket, [ 'zither render' => self::cat_entry( 7.5, 3, 1 ) ] );
		$this->set_category_bucket( $store, Stats_Store::bucket_key( $open + 300 ), [ 'zither render' => self::cat_entry( 2.5, 1, 1 ) ] );
		$fb->save_state();

		$this->assertSame(
			2,
			$this->get_stats( $fb )['mirror_held_frames'],
			'the widest namespace, not the four-frame total'
		);
	}

	public function test_a_multi_megabyte_held_set_is_carried_whole(): void {
		// A staging hub holds ~1,400 per-URL frames, 2.1-3.5MB a checkpoint.
		// The budget is sized from the record cliff, half of
		// `Partition_Node::MAX_LARGE_LINE_SIZE`, rather than a fixture, so a
		// held set of that size rides the keyframe entire and the tripwire
		// stays quiet: one that fires every checkpoint reports nothing.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );

		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb ]     = $this->mirrored_builder( $store, 'flames-stats' );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		Core::$now = $open;

		// 5.4MB held: a third of the budget, and past any fixture-sized one.
		$this->set_hourly_bucket( $store, $bucket, [ 'count' => 3 ] );
		$this->set_category_bucket( $store, $bucket, [ 'blob' => \str_repeat( 'c', 2400000 ) ] );
		$this->set_leaderboard_bucket( $store, $bucket, [ 'blob' => \str_repeat( 'x', 3000000 ) ] );

		$frames = $fb->save_state()['mirror']['frames'];

		$this->assertStringNotContainsString( 'over the checkpoint budget', $err, 'the tripwire stays quiet' );
		$this->assertCount( 3, \array_merge( ...\array_values( $frames ) ), 'every held frame is carried' );
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
		Core::$now = $open;

		// One that fits and TWO that do not, the leaderboard the larger.
		$this->set_hourly_bucket( $store, $bucket, [ 'count' => 3 ] );
		$this->set_category_bucket( $store, $bucket, [ 'blob' => \str_repeat( 'c', 16800000 ) ] );
		$this->set_leaderboard_bucket( $store, $bucket, [ 'blob' => \str_repeat( 'x', 16900000 ) ] );

		$fb->save_state();

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
		$this->assertStringContainsString( '16777216', $hit, 'names the budget' );
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
		Core::$now = $open;

		$this->set_hourly_bucket( $store, $bucket, [ 'count' => 7 ] );
		$this->set_category_bucket( $store, $bucket, [ 'blob' => \str_repeat( 'c', 16800000 ) ] );
		$this->set_leaderboard_bucket( $store, $bucket, [ 'blob' => \str_repeat( 'x', 16900000 ) ] );

		$fb->save_state();

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
		Core::$now = $open;
		$this->set_hourly_bucket( $store, $bucket, [ 'count' => 11 ] );
		$this->set_category_bucket( $store, $bucket, [ 'blob' => \str_repeat( 'c', 16800000 ) ] );
		$this->set_leaderboard_bucket( $store, $bucket, [ 'blob' => \str_repeat( 'x', 16900000 ) ] );
		$fb->save_state();

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

	// --- Tick clock / maintenance / non-stats setters ---------------------

	public function test_the_tick_drives_bucket_key_derivation(): void {
		// Use a fixed clock so we can pin the bucket key without timing flake.
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb    = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$fixed = 1_700_000_000; // Stable; floor to 5-min bucket.
		Core::$now = $fixed;

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

	/** A custom event's name is proposed whole; only the ` hook` suffix is a suffix. */
	public function test_a_multi_word_custom_event_is_proposed_whole(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'profiles' => [
				'render page' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$this->assertSame( [ 'render page' ], $fb->get_auto_tune_state()['add_significant_events']['r'] );
	}

	/** Only a one-token slug is a plugin load; a custom event ending in "plugin" is the application's. */
	public function test_a_multi_word_custom_event_ending_in_plugin_is_still_promoted(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'profiles' => [
				'sync remote plugin' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$this->assertSame( [ 'sync remote plugin' ], $fb->get_auto_tune_state()['add_significant_events']['r'] );
	}

	/** The candidate is the application's own name, not the collision-free key the accumulator files it under. */
	public function test_a_custom_event_named_total_is_proposed_under_its_own_name(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'profiles' => [
				'total' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$this->assertSame( [ 'total' ], $fb->get_auto_tune_state()['add_significant_events']['r'] );
	}

	/** The noisy path names a custom event whole too, so the applier can find it. */
	public function test_a_noisy_multi_word_custom_event_is_disabled_whole(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 50 ] );
		$fb = new Flame_Builder_Node();
		$fb->set_custom_event_names( [ 'render page' ] );

		$req = $this->completed_request( [
			'profiles' => [
				'render page' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'render page' ], $state['disable_custom_events']['r'] );
		$this->assertArrayNotHasKey( 'r', $state['disable_hooks'] );
	}

	// --- restore_state edge cases -----------------------------------------

	public function test_restore_state_ignores_non_array_pending(): void {
		$fb = new Flame_Builder_Node();
		$fb->restore_state( [ 'pending' => 'not-an-array' ] );
		$this->assertSame( [], $fb->save_state()['pending'] );
	}

	public function test_restore_state_drops_a_carried_frame_under_a_foreign_key(): void {
		// A checkpoint written by the release before this one carried its held
		// frames under the scoped cache key. Taken back, such a frame matches no
		// lookup, counts against the held bound, and is written to disk at close
		// as a record nothing reads. The carry keeps only this mirror's keys.
		$fb     = new Flame_Builder_Node();
		$now    = 1_700_003_000;
		$bucket = Stats_Store::bucket_key( $now );
		Core::$now = $now;
		$durable = Stats_Store::entry_key( 0, 'hourly:' . $bucket );
		$foreign = 'newspack_nodes:v3:4f82f2fc5124:table:evlog:p0:hourly:' . $bucket;
		$fb->restore_state( [
			'mirror' => [
				'at'     => $now,
				'frames' => [
					'hourly' => [
						$foreign => [ [ 'count' => 41 ], 3600 ],
						$durable => [ [ 'count' => 43 ], 3600 ],
					],
				],
			],
		] );

		$this->assertSame( [ $durable ], \array_keys( $fb->save_state()['mirror']['frames']['hourly'] ) );
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
		$this->assertSame( 1, $bucket['total'][ Stats_Store::CAT_REQUESTS ], 'rollup counts requests, not categories' );
		$this->assertEqualsWithDelta( 250.0, $bucket['total'][ Stats_Store::CAT_MS ], 1e-6, 'rollup time is request wall time' );
		$this->assertEqualsWithDelta( 10.0, $bucket['total'][ Stats_Store::CAT_CALLS ], 1e-6, 'rollup counts every category call' );

		// The colliding event still gets its own row, under a distinct key.
		$this->assertArrayHasKey( 'wpdb', $bucket );
		$rows = \array_diff( \array_keys( $bucket ), [ 'total', 'wpdb' ] );
		$this->assertCount( 1, $rows, 'the colliding event keeps a row of its own' );
		$own = $bucket[ \reset( $rows ) ];
		$this->assertEqualsWithDelta( 40.0, $own[ Stats_Store::CAT_MS ], 1e-6 );
		$this->assertEqualsWithDelta( 7.0, $own[ Stats_Store::CAT_CALLS ], 1e-6 );
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

	/**
	 * The auto-tune decisions fire on a clean stop, through the sweep.
	 *
	 * An on-demand worker folds its backlog and idles out without a periodic
	 * flush, and the decisions live only in this process, so a stop that did
	 * not fire them would drop them with the process.
	 */
	public function test_a_clean_stop_fires_the_auto_tune_decisions(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'chatty hook' => [ 'time' => 0.1, 'count' => 250, 'entries' => [] ],
			],
		] ) );
		$this->assertSame( [], $capture->captured, 'nothing fires before the sweep' );

		$fb->shutdown_sweep();

		$fired = \array_values( \array_filter(
			$capture->captured,
			static fn( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' )
		) );
		$this->assertCount( 1, $fired );
		$this->assertSame( [ 'chatty' ], $fired[0][ Message::VALUE ]['items'] );
	}

	/**
	 * A stop waits out a sibling partition's lock rather than dropping the
	 * decisions: a periodic flush can retry on the next one, a stop has none.
	 */
	public function test_a_clean_stop_waits_for_a_held_auto_tune_lock(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$capture    = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'spam hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] ) );
		// Another partition holds the lock, and only the seam below releases
		// it. The TTL must outlive the test: the double expires at
		// `time() + ttl` at second granularity, so a one-second hold added
		// near a second boundary is already gone when the stop looks, and the
		// lock is taken on the first try without ever polling.
		$lock = self::scoped( 'evlog:auto_disable_lock' );
		$mc->add( $lock, 'other-worker', 300 );
		// The seam IS the wait: the sibling's hold ends on the first poll, so
		// this proves the stop outwaits a lock without spending real time.
		$polls                          = 0;
		Flame_Builder_Node::$usleep_fn = static function () use ( $mc, $lock, &$polls ): void {
			++$polls;
			$mc->delete( $lock );
		};

		$fb->shutdown_sweep();

		$this->assertSame( 1, $polls, 'the stop polled rather than giving up' );

		$fired = \array_filter(
			$capture->captured,
			static fn( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' )
		);
		$this->assertNotEmpty( $fired, 'the stop outwaited the lock' );
		$this->assertNotContains( self::scoped( 'evlog:auto_disable_lock' ), $mc->keys() );
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
		// One fold, four coarse tiers: rows, the server index, the leaderboard,
		// and the ranked lists the fold derives from those rows.
		$coarse = [
			':' . Stats_Store::NS_URLS_HOUR . ':',
			':' . Stats_Store::NS_URLSRV_HOUR . ':',
			':' . Stats_Store::NS_LB_HOUR . ':',
			':' . Stats_Store::NS_URLRANK_HOUR_S . ':',
		];
		foreach ( $mc->keys() as $key ) {
			$hit = false;
			foreach ( $coarse as $tier ) {
				$hit = $hit || \str_contains( $key, $tier );
			}
			$this->assertTrue( $hit, 'no lock or other keys written' );
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
		$this->set_hourly_bucket( $store, '1999-01-01-00-00', $untouched );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 5.0 ] ) );
		$fb->flush();

		$this->assertSame( $untouched, $this->get_hourly_bucket( $store, '1999-01-01-00-00' ) );
		$this->assertNotSame( [], $this->recent_hourly( $store ), 'and the request it did fill landed' );
	}

	/**
	 * A closed hour is folded ONCE into a coarse key, not added to
	 * incrementally: the five-minute write is a full overwrite and is safe to
	 * repeat, while adding into an hour bucket double-counts on a re-flush.
	 */
	public function test_a_request_is_filed_in_the_bucket_it_FINISHED_in(): void {
		// The record reaches the builder at completion, so a long request must
		// land where it ended, not where it began — a 7-minute request started
		// at :58 belongs to the next hour, not the one that has since closed.
		// Seeds distinct from every default: 420s duration, 5-minute buckets.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$start      = \gmmktime( 13, 58, 0, 8, 27, 2026 );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = (float) ( $start + 420 );
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => '/slow-job-9930',
			'timestamp'   => $start,
			'duration_ms' => 420000.0,
			'status_code' => 200,
		] ) );
		$fb->flush();

		$hash  = Log_Manager::url_hash( '/slow-job-9930' );
		$shard = Stats_Store::url_shard( $hash );
		$ended = self::named_url_rows( $this->get_url_shard( $store, '2026-08-27-14-05', $shard ) );
		$began = self::named_url_rows( $this->get_url_shard( $store, '2026-08-27-13-55', $shard ) );
		$this->assertSame( 1, $ended[ $hash ]['count'] ?? 0, 'filed in the bucket it finished in' );
		$this->assertArrayNotHasKey( $hash, $began, 'and not in the one it started in' );
	}

	public function test_a_respawned_worker_probes_the_coarse_tier_before_it_writes(): void {
		// folded_hours is per-PROCESS and one partition has one worker, so a
		// respawn is the only way the memo goes stale. flush() must probe —
		// which roll_up_hours() does — before placing any row, or the first
		// flush after a respawn writes into fine buckets nothing reads.
		// Seeds distinct from every default: a folded hour of 3, then 8 more.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = Log_Manager::url_hash( '/respawn-7714' );
		$shard      = Stats_Store::url_shard( $hash );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$hash => [ 'url' => '/respawn-7714', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 33.0, 'last_seen' => $now - 7200 ],
		] );
		$folder = new Flame_Builder_Node();
		$folder->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $folder, (int) Core::$now );

		// A DIFFERENT process: same partition, no memory of the fold.
		$fresh = new Flame_Builder_Node();
		$fresh->set_stats_store( $store );
		Core::$now = (float) $now;
		$ref = new \ReflectionProperty( $fresh, 'pending' );
		$ref->setValue( $fresh, [ '2026-08-27-13-40' => \array_replace(
			( new \ReflectionMethod( $fresh, 'empty_bucket' ) )->invoke( null ),
			[ 'url_stats' => [ self::SEED_SERVER => [ $hash => self::positional_url_row( [ 'url' => '/respawn-7714', 'count' => 8, 'timed_count' => 8, 'sum_ms' => 240.0, 'last_seen' => $now - 5400 ] ) ] ] ]
		) ] );
		$fresh->flush();

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$this->assertSame(
			11,
			$hour[ $hash ]['count'] ?? 0,
			'the first flush after a respawn must probe before it writes'
		);
	}

	public function test_a_flush_costs_round_trips_by_batch_not_by_url(): void {
		// persist_aggregate_stats() issued one read-modify-write per KEY, and
		// two of its loops are per URL, so a full-window replay decayed as the
		// retention window refilled: ~490 round trips per bucket at 20 distinct
		// URLs, ~2,400 at the 500 cap. The property is that the flush's single
		// round trips stop SCALING with URL count — a fixed ceiling would only
		// pin whatever else the flush happens to do today.
		$singles = [];
		foreach ( [ 12, 48 ] as $urls ) {
			$counter = new class() extends InMemoryMemcached {
				public int $singles = 0;
				public int $batches = 0;
				public function set( string $key, mixed $value, int $expiration = 0 ): bool {
					++$this->singles;
					return parent::set( $key, $value, $expiration );
				}
				// The fake loops setMulti() over set(); snapshot around it, the
				// way its own getMulti() does for the single-call counter.
				public function setMulti( array $items, int $expiration = 0 ): bool {
					++$this->batches;
					$held          = $this->singles;
					$ok            = parent::setMulti( $items, $expiration );
					$this->singles = $held;
					return $ok;
				}
			};
			Core::$memd = $counter;
			$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
			$fb         = new Flame_Builder_Node();
			$fb->set_stats_store( $store );

			for ( $i = 0; $i < $urls; $i++ ) {
				$this->fill_request( $fb, $this->completed_request( [
					'url'         => "/batched-{$i}-6612",
					'duration_ms' => 12.0,
					'status_code' => 200,
				] ) );
			}
			$counter->singles = 0;
			$counter->batches = 0;
			$fb->flush();

			$this->assertGreaterThan( 0, $counter->batches, 'the flush must write in batches' );
			$singles[ $urls ] = $counter->singles;
		}

		// @longform Four times the URLs must not cost four times the round
		// trips. On the pre-batch code these were 72 and 151 — dead linear in
		// URLs — and the last per-URL write left was the aggregate blob, which
		// `mirror_url_stats()` now batches with the rest. Zero, not merely
		// sublinear: an inequality against a constant offset is what let that
		// last linearity sit here unnoticed.
		$this->assertSame( 0, $singles[12], 'a flush makes no single round trips' );
		$this->assertSame( 0, $singles[48], 'however many URLs it carries' );
	}

	/** Seed one bucket's URL rows into the node's pending state and persist them. */
	private function flush_pending( Flame_Builder_Node $fb, string $bucket, array $url_stats ): void {
		$this->flush_buckets( $fb, [ $bucket => [ 'url_stats' => $url_stats ] ] );
	}

	/**
	 * Drive one `persist_aggregate_stats()` over hand-built accumulators. URL
	 * rows are given by hash and filed under `SEED_SERVER`, as a request
	 * carrying the default `server_name` files them.
	 *
	 * @param array<string,array<string,mixed>> $buckets bucket => accumulator overrides.
	 */
	private function flush_buckets( Flame_Builder_Node $fb, array $buckets ): void {
		$pending = [];
		foreach ( $buckets as $bucket => $overrides ) {
			foreach ( [ 'url_stats', 'url_stats_worker' ] as $slot ) {
				if ( isset( $overrides[ $slot ] ) ) {
					$overrides[ $slot ] = [ self::SEED_SERVER => $overrides[ $slot ] ];
				}
			}
			$pending[ $bucket ] = \array_replace(
				( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ),
				$overrides
			);
		}
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, $pending );
		$store = self::store_of( $fb );
		( new \ReflectionMethod( $fb, 'persist_aggregate_stats' ) )->invoke( $fb, $store, (int) Core::$now, self::fine_floor( $store, (int) Core::$now ) );
	}

	/** Run `roll_up_hours()` as a flush at `$now` would. */
	private static function roll_up( Flame_Builder_Node $fb, int $now ): void {
		$store = self::store_of( $fb );
		$fb->roll_up_hours( $store, Stats_Store::read_plan( Stats_Store::retention_buckets( $store->ttl(), $now ) ) );
	}

	/** The oldest bucket of the read plan's fine tail at `$now`. */
	private static function fine_floor( Stats_Store $store, int $now ): string {
		return (string) \end( Stats_Store::read_plan( Stats_Store::retention_buckets( $store->ttl(), $now ) )['fine'] );
	}

	/** The store a builder was wired with. */
	private static function store_of( Flame_Builder_Node $fb ): Stats_Store {
		$store = ( new \ReflectionProperty( $fb, 'stats_store' ) )->getValue( $fb );
		self::assertInstanceOf( Stats_Store::class, $store );
		return $store;
	}

	public function test_a_bucket_inside_a_folded_hour_merges_into_the_coarse_key(): void {
		// A replay files a record under its ORIGINAL timestamp. The reader
		// takes the hour key and never re-reads a folded hour's fine buckets,
		// and the hour folds exactly once — so a fine write landing after the
		// fold is stored where nothing will ever read it. Seeds distinct from
		// every default: 7 requests, 210ms, against a folded hour of 3.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = Log_Manager::url_hash( '/replayed-8823' );
		$shard      = Stats_Store::url_shard( $hash );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$hash => [ 'url' => '/replayed-8823', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 33.0, 'last_seen' => $now - 7200 ],
		] );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		// Now a replayed record for a DIFFERENT bucket of that same closed hour.
		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'url' => '/replayed-8823', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 210.0, 'last_seen' => $now - 5400 ] ),
		] );

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$this->assertSame(
			10,
			$hour[ $hash ]['count'],
			'a write into a folded hour must reach the key the reader actually reads'
		);
		$this->assertSame( 243.0, (float) $hour[ $hash ]['sum_ms'] );
	}

	/**
	 * A store that records every hour-tier ranking it is asked to write.
	 *
	 * @return Stats_Store&object{hour_ranks: list<string>, bucket_ranks: list<string>}
	 */
	private static function hour_rank_recorder(): Stats_Store {
		return new class( 0, 86400 ) extends Stats_Store {
			/** @var list<string> */
			public array $hour_ranks = [];
			/** @var list<string> */
			public array $bucket_ranks = [];
			public function bucket_set_multi( array $writes ): array {
				foreach ( $writes as [ $parts, $key ] ) {
					if ( self::NS_URLRANK_HOUR_S === $parts[0] ) {
						$this->hour_ranks[] = $key;
					} elseif ( self::NS_URLRANK_S === $parts[0] ) {
						$this->bucket_ranks[] = $key;
					}
				}
				return parent::bucket_set_multi( $writes );
			}
		};
	}

	/**
	 * Fold one hour holding `$rows` in bucket `-05` of shard `$shard`, as of
	 * 15:07 on 2026-08-27, and return the builder that memoized it.
	 *
	 * @param Stats_Store            $store Destination.
	 * @param string                 $shard Shard the rows sit in.
	 * @param array<array-key,mixed> $rows  Named rows by hash.
	 */
	private function folded_builder( Stats_Store $store, string $shard, array $rows ): Flame_Builder_Node {
		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, $rows );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );
		$this->assertSame( [ '2026-08-27-13' => true ], \array_intersect_key(
			( new \ReflectionProperty( $fb, 'folded_hours' ) )->getValue( $fb ),
			[ '2026-08-27-13' => true ]
		), 'the fixture folds the hour' );
		return $fb;
	}

	public function test_a_late_write_into_a_folded_hour_missing_its_row_shard_goes_to_its_fine_bucket_only(): void {
		// The coarse tier is unmirrored and evictable. Merged into a missing
		// hour key, the late rows would recreate the shard holding only
		// themselves, and the probe would read the hour as folded with its
		// original rows gone. Seeds distinct from every default: 3 folded
		// requests, 7 late ones.
		Core::$memd = new InMemoryMemcached();
		$store      = self::hour_rank_recorder();
		$hash       = Log_Manager::url_hash( '/evicted-hour-6071' );
		$shard      = Stats_Store::url_shard( $hash );
		$fb         = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/evicted-hour-6071', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 33.0 ],
		] );
		$coarse = self::cache_key( 0, 'urls_h:' . Stats_Store::server_key( self::SEED_SERVER ) . ":{$shard}:2026-08-27-13" );
		Core::$memd->delete( $coarse );
		$store->hour_ranks = [];

		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'url' => '/evicted-hour-6071', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 210.0 ] ),
		] );

		$fine = self::named_url_rows( $this->get_url_shard( $store, '2026-08-27-13-40', $shard ) );
		$this->assertSame( 7, $fine[ $hash ]['count'] ?? null, 'the late rows land in their fine bucket' );
		$this->assertFalse( Core::$memd->get( $coarse ), 'and the missing hour key stays missing' );
		$this->assertNotContains( '2026-08-27-13', $store->hour_ranks, 'nor is the hour re-ranked off a shard it lacks' );
	}

	public function test_a_late_write_into_a_folded_hour_holding_its_shard_lands_in_both_tiers(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = self::hour_rank_recorder();
		$hash       = Log_Manager::url_hash( '/present-hour-4419' );
		$shard      = Stats_Store::url_shard( $hash );
		$fb         = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/present-hour-4419', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 33.0 ],
		] );
		$store->hour_ranks = [];

		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'url' => '/present-hour-4419', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 210.0 ] ),
		] );

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$this->assertSame( 10, $hour[ $hash ]['count'] ?? null, 'merged into the hour key' );
		$fine = self::named_url_rows( $this->get_url_shard( $store, '2026-08-27-13-40', $shard ) );
		$this->assertSame( 7, $fine[ $hash ]['count'] ?? null, 'and into the fine bucket the hour derives from' );
		$this->assertNotContains( '2026-08-27-13-40', $store->bucket_ranks, 'which no one ranks' );
		$this->assertNotContains( '2026-08-27-13', $store->hour_ranks, 'and the hour waits for the probe' );
	}

	public function test_a_refold_keeps_late_rows_merged_into_a_present_shard(): void {
		// End to end: the late rows merge into a PRESENT urls_h shard while
		// another shard of the server is evicted. The reprobe re-folds the
		// whole hour from its fine buckets, so rows that reached only the hour
		// key are overwritten away; rows that also reached their fine bucket
		// return.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$kept       = 'c6c6c6c6c6c6';
		$late       = 'c7c7c7c7c7c7';
		$shard      = Stats_Store::url_shard( $kept );
		$this->assertSame( $shard, Stats_Store::url_shard( $late ), 'the fixture puts both rows in one shard' );
		$fb = $this->folded_builder( $store, $shard, [
			$kept => [ 'url' => '/kept-row-3390', 'count' => 3 ],
		] );
		Core::$memd->delete( self::cache_key( 0, 'urls_h:' . Stats_Store::server_key( self::SEED_SERVER ) . ':w0:2026-08-27-13' ) );
		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$late => self::positional_url_row( [ 'url' => '/late-row-3391', 'count' => 7 ] ),
		] );
		$merged = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] ?? [] );
		$this->assertSame( 7, $merged[ $late ]['count'] ?? null, 'the late rows merged into the present hour key' );

		( new \ReflectionProperty( $fb, 'folds_since_reprobe' ) )->setValue( $fb, PHP_INT_MAX - 1 );
		self::roll_up( $fb, (int) Core::$now );

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] ?? [] );
		$this->assertSame( 3, $hour[ $kept ]['count'] ?? null, 'the re-fold keeps the original rows' );
		$this->assertSame( 7, $hour[ $late ]['count'] ?? null, 'and the late ones' );
	}

	public function test_a_late_write_from_a_new_server_names_it_in_the_folded_hour_and_the_reprobe_folds_it(): void {
		// The server index is what every hour reader enumerates, so a server
		// the folded hour never named has to join it, or its late rows stay
		// in fine buckets nothing reads. Its hour keys do not exist yet, so
		// the probe reads the hour as unfolded and the reprobe folds it again.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = 'c5c5c5c5c5c5';
		$shard      = Stats_Store::url_shard( $hash );
		$fb         = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/folded-5511', 'count' => 3 ],
		] );
		$late = 'c9c9c9c9c9c9';
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [ '2026-08-27-13-40' => \array_replace(
			( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ),
			[ 'url_stats' => [ 'late-7731.test' => [ $late => self::positional_url_row( [ 'count' => 13, 'path' => '/late-7731' ] ) ] ] ]
		) ] );
		( new \ReflectionMethod( $fb, 'persist_aggregate_stats' ) )->invoke( $fb, $store, (int) Core::$now, self::fine_floor( $store, (int) Core::$now ) );
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [] );

		$this->assertContains( 'late-7731.test', $store->server_index( [ '2026-08-27-13' ], [] )['2026-08-27-13'] );
		$this->assertFalse( $store->url_hours_derived( [ '2026-08-27-13' ] )['2026-08-27-13']['folded'], 'its hour keys are missing' );

		( new \ReflectionProperty( $fb, 'folds_since_reprobe' ) )->setValue( $fb, PHP_INT_MAX - 1 );
		self::roll_up( $fb, (int) Core::$now );

		$this->assertSame( 13, self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', $shard, 'late-7731.test' ) )[ $late ]['count'] ?? null );
		$this->assertSame( 3, self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', $shard ) )[ $hash ]['count'] ?? null );
	}

	public function test_a_late_write_from_a_new_server_is_ranked_by_the_next_flush(): void {
		// The late write names a server the folded hour's index lacked, so the
		// hour holds a server with no hour lists: a ranked page reads the
		// hour as a hole. The flush forgets the memo for that hour, so the
		// next flush's probe revisits it rather than waiting for the reprobe.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = 'c5c5c5c5c5c5';
		$fb         = $this->folded_builder( $store, Stats_Store::url_shard( $hash ), [
			$hash => [ 'url' => '/folded-5511', 'count' => 3 ],
		] );
		$late = 'c9c9c9c9c9c9';
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [ '2026-08-27-13-40' => \array_replace(
			( new \ReflectionMethod( $fb, 'empty_bucket' ) )->invoke( null ),
			[ 'url_stats' => [ 'late-7731.test' => [ $late => self::positional_url_row( [ 'count' => 13, 'path' => '/late-7731' ] ) ] ] ]
		) ] );
		( new \ReflectionMethod( $fb, 'persist_aggregate_stats' ) )->invoke( $fb, $store, (int) Core::$now, self::fine_floor( $store, (int) Core::$now ) );
		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [] );
		$this->assertSame( [], $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' ), 'the new server leaves the hour unranked' );

		$fb->flush();

		$late_list = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', 'late-7731.test' );
		$this->assertSame( 13, $late_list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] ?? null, 'its hour lists exist' );
		$site = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' );
		$this->assertSame( [ $late, $hash ], \array_column( $site[0][1] ?? [], Stats_Store::RANK_HASH ), 'the site hour answers ranked' );
	}

	public function test_a_spent_fold_budget_still_memoizes_an_older_folded_hour(): void {
		// Every hour newer than 10:00 is unfolded and the budget folds two of
		// them. Hour 10 is folded and ranked already; unless this flush
		// memoizes it, its late rows reach only a fine bucket nothing reads.
		// Seeds distinct from every default: 5 folded requests, 9 late.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = 'e8e8e8e8e8e8';
		$shard      = Stats_Store::url_shard( $hash );
		$older      = '2026-08-27-10';
		foreach ( \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) ) as $one ) {
			$this->seed_url_hour( $store, $older, $one, $one === $shard ? [
				$hash => [ 'url' => '/older-hour-5162', 'count' => 5 ],
			] : [] );
		}
		$store->bucket_set_multi( [
			[ Stats_Store::lb_hour_parts(), $older, [] ],
			[ Stats_Store::url_rank_done_parts( Stats_Store::server_key( self::SEED_SERVER ) ), $older, [] ],
		] );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );
		$this->assertArrayHasKey( $older, ( new \ReflectionProperty( $fb, 'folded_hours' ) )->getValue( $fb ), 'the probed hour is memoized' );

		$this->flush_pending( $fb, $older . '-20', [
			$hash => self::positional_url_row( [ 'url' => '/older-hour-5162', 'count' => 9 ] ),
		] );

		$hour = self::named_url_rows( $store->url_hour_sources( [ $older ], $shard )[0][1] ?? [] );
		$this->assertSame( 14, $hour[ $hash ]['count'] ?? null, 'the late rows merge into the hour key the reader reads' );
	}

	/**
	 * One closed hour's leaderboard category, as a fine bucket or an
	 * accumulator holds it.
	 *
	 * @return array<string,mixed>
	 */
	private static function lb_sums( int $count, float $sum_time ): array {
		return [
			'count'        => $count,
			'sum_req_time' => $count * 2.0,
			'categories'   => [ 'db' => [ 'samples' => $count, 'sum_time' => $sum_time, 'sum_count' => 2 * $count, 'entries' => [] ] ],
		];
	}

	/** The global leaderboard's coarse key for one hour, or null while missing. */
	private static function lb_hour( Stats_Store $store, string $hour ): ?array {
		return $store->bucket_get_multi( [ [ Stats_Store::lb_hour_parts(), $hour ] ] )[0];
	}

	public function test_a_late_leaderboard_record_into_a_folded_hour_reaches_its_hour_key(): void {
		// `build_leaderboard()` takes a folded hour's `lb_h` and skips its
		// fine buckets, so a replay landing only in `lb` never shows. Seeds
		// distinct from every default: 4 folded requests, 6 late ones.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$this->set_leaderboard_bucket( $store, '2026-08-27-13-05', self::lb_sums( 4, 40.0 ) );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );
		$this->assertSame( 4, self::lb_hour( $store, '2026-08-27-13' )['count'] ?? null, 'the fixture folds the hour' );

		$this->flush_buckets( $fb, [ '2026-08-27-13-40' => [ 'leaderboard' => self::lb_sums( 6, 60.0 ) ] ] );

		$hour = self::lb_hour( $store, '2026-08-27-13' );
		$this->assertSame( 10, $hour['count'] ?? null, 'the late record reaches the hour key the board reads' );
		$this->assertSame( 100.0, (float) ( $hour['categories']['db']['sum_time'] ?? 0 ) );
		$fine = $store->get_leaderboard_buckets( [ '2026-08-27-13-40' ] )['2026-08-27-13-40'] ?? [];
		$this->assertSame( 6, $fine['count'] ?? null, 'and the fine bucket a re-fold reads' );
	}

	/**
	 * A store whose batch read answers every slot a miss whenever it asks for
	 * `$ns`, as an evicted tier does: an answer, not a failure.
	 */
	private static function missing_batch_store( string $ns ): Stats_Store {
		$store = new class( 0, 86400 ) extends Stats_Store {
			public string $fail = '';
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				$failed = false;
				foreach ( $reads as [ $parts ] ) {
					if ( $this->fail === $parts[0] ) {
						return \array_fill_keys( \array_keys( $reads ), null );
					}
				}
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$store->fail = '';
		return $store;
	}

	public function test_an_hour_key_whose_batch_read_missed_is_forgotten_and_re_folded(): void {
		// The key is there, but the batch reads it as missing. Skipped,
		// the late rows never reach it and the probe still reads the hour as
		// folded; forgotten, the reprobe re-folds it from the fine buckets,
		// which hold both. Seeds: 3 folded requests, 7 late ones.
		Core::$memd = new InMemoryMemcached();
		$store      = self::missing_batch_store( Stats_Store::NS_URLS_HOUR );
		$hash       = Log_Manager::url_hash( '/missed-read-5307' );
		$shard      = Stats_Store::url_shard( $hash );
		$fb         = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/missed-read-5307', 'count' => 3 ],
		] );
		$coarse = self::cache_key( 0, 'urls_h:' . Stats_Store::server_key( self::SEED_SERVER ) . ":{$shard}:2026-08-27-13" );

		$store->fail = Stats_Store::NS_URLS_HOUR;
		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'url' => '/missed-read-5307', 'count' => 7 ] ),
		] );
		$store->fail = '';
		$this->assertFalse( Core::$memd->get( $coarse ), 'the hour key is forgotten' );

		( new \ReflectionProperty( $fb, 'folds_since_reprobe' ) )->setValue( $fb, PHP_INT_MAX - 1 );
		self::roll_up( $fb, (int) Core::$now );

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] ?? [] );
		$this->assertSame( 10, $hour[ $hash ]['count'] ?? null, 'the re-fold holds the original rows and the late ones' );
	}

	public function test_a_leaderboard_hour_key_whose_batch_read_missed_is_re_folded(): void {
		// The probe has to see a missing `lb_h` for the forget to heal it:
		// otherwise the hour reads settled and the board stays short of it.
		Core::$memd = new InMemoryMemcached();
		$store      = self::missing_batch_store( Stats_Store::NS_LB_HOUR );
		$this->set_leaderboard_bucket( $store, '2026-08-27-13-05', self::lb_sums( 4, 40.0 ) );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );

		$store->fail = Stats_Store::NS_LB_HOUR;
		$this->flush_buckets( $fb, [ '2026-08-27-13-40' => [ 'leaderboard' => self::lb_sums( 6, 60.0 ) ] ] );
		$store->fail = '';
		$this->assertNull( self::lb_hour( $store, '2026-08-27-13' ), 'the hour key is forgotten' );

		( new \ReflectionProperty( $fb, 'folds_since_reprobe' ) )->setValue( $fb, PHP_INT_MAX - 1 );
		self::roll_up( $fb, (int) Core::$now );

		$this->assertSame( 10, self::lb_hour( $store, '2026-08-27-13' )['count'] ?? null, 'the reprobe re-folds it whole' );
	}

	public function test_a_chunk_whose_batch_read_failed_writes_nothing_and_says_so(): void {
		// Decision 3: stats fail soft. A failed read merged onto [] would
		// write the delta alone over the stored bucket, so the chunk's deltas
		// are dropped instead. Seeds: 4 stored requests, 6 in the flush.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		$memd       = new class() extends InMemoryMemcached {
			public bool $broken = false;
			public function getMulti( array $keys, int $get_flags = 0 ): array|false {
				return $this->broken ? false : parent::getMulti( $keys, $get_flags );
			}
		};
		Core::$memd = $memd;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->set_leaderboard_bucket( $store, '2026-08-27-15-05', self::lb_sums( 4, 40.0 ) );

		$memd->broken = true;
		$this->flush_buckets( $fb, [ '2026-08-27-15-05' => [ 'leaderboard' => self::lb_sums( 6, 60.0 ) ] ] );
		$memd->broken = false;

		$fine = $store->get_leaderboard_buckets( [ '2026-08-27-15-05' ] )['2026-08-27-15-05'] ?? [];
		$this->assertSame( 4, $fine['count'] ?? null, 'the stored bucket keeps its value' );
		$this->assertStringContainsString( 'stats flush read failed', $err, 'the dropped chunk is logged' );
	}

	/**
	 * A store that refuses, and does not store, every write into `$refuse`'s
	 * namespace while it is set.
	 */
	private static function refusing_store(): Stats_Store {
		return new class( 0, 86400 ) extends Stats_Store {
			public string $refuse = '';
			public function bucket_set_multi( array $writes ): array {
				$refused = [];
				foreach ( $writes as $i => [ $parts ] ) {
					if ( $this->refuse === $parts[0] ) {
						$refused[ $i ] = false;
					}
				}
				$kept = \array_diff_key( $writes, $refused );
				$out  = [] === $kept ? [] : \array_combine( \array_keys( $kept ), parent::bucket_set_multi( \array_values( $kept ) ) );
				return \array_replace( $out, $refused );
			}
		};
	}

	public function test_a_refused_late_fine_write_leaves_the_hour_key_alone(): void {
		// Only a derived key is re-derivable; a refused fine write logs.
		Core::$memd = new InMemoryMemcached();
		$store      = self::refusing_store();
		$this->set_leaderboard_bucket( $store, '2026-08-27-13-05', self::lb_sums( 4, 40.0 ) );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );

		$store->refuse = Stats_Store::NS_LB;
		$this->flush_buckets( $fb, [ '2026-08-27-13-40' => [ 'leaderboard' => self::lb_sums( 6, 60.0 ) ] ] );
		$store->refuse = '';

		$this->assertSame( 10, self::lb_hour( $store, '2026-08-27-13' )['count'] ?? null, 'the hour key took the late write' );
	}

	public function test_a_late_write_forgets_the_hours_marker_and_the_reprobe_re_ranks_it(): void {
		// Persistent, not in memory: the marker's absence is what the probe
		// reads, so the re-rank survives a stop. Seeds: 3 folded, 7 late.
		Core::$memd = new InMemoryMemcached();
		$store      = self::hour_rank_recorder();
		$hash       = Log_Manager::url_hash( '/marker-gone-7714' );
		$shard      = Stats_Store::url_shard( $hash );
		$fb         = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/marker-gone-7714', 'count' => 3 ],
		] );
		$store->hour_ranks = [];

		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'url' => '/marker-gone-7714', 'count' => 7 ] ),
		] );

		$this->assertNull( $this->url_rank_done( $store, '2026-08-27-13' ), 'the landed late write forgets the marker' );
		$this->assertSame( [], $store->hour_ranks, 'and ranks nothing itself' );
		$this->assertSame( [], ( new \ReflectionProperty( $fb, 'stale_hours' ) )->getValue( $fb ) );

		( new \ReflectionProperty( $fb, 'pending' ) )->setValue( $fb, [] );
		( new \ReflectionProperty( $fb, 'folds_since_reprobe' ) )->setValue( $fb, PHP_INT_MAX - 1 );
		$fb->flush();

		$this->assertSame( 10, self::ranked_count( $store, '2026-08-27-13', true ), 'the reprobe re-ranks the hour from its stored rows' );
		$this->assertSame( [], $this->url_rank_done( $store, '2026-08-27-13' ), 'and writes the marker back' );
	}

	public function test_a_server_left_unmarked_re_ranks_its_hour(): void {
		// The marker is per server, so the probe that finds moa.test's hour
		// unmarked while kea.test's is marked re-ranks the hour from its
		// stored rows, every server of it. Seeds: kea 5, moa 8 then 19.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			/** @var list<string> */
			public array $ranked = [];
			public function bucket_set_multi( array $writes ): array {
				foreach ( $writes as [ $parts ] ) {
					if ( self::NS_URLRANK_HOUR_S === $parts[0] && 'done' !== $parts[1] ) {
						$this->ranked[ $parts[1] ] = $parts[1];
					}
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$now  = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$hour = '2026-08-27-13';
		$kea  = 'a5a5a5a5a5a5';
		$moa  = 'b8b8b8b8b8b8';
		$this->set_url_bucket( $store, $hour . '-05', [ $kea => [ 'url' => 'https://kea.test/kea-5', 'count' => 5, 'last_seen' => $now - 7000 ] ], 'kea.test' );
		$this->set_url_bucket( $store, $hour . '-05', [ $moa => [ 'url' => 'https://moa.test/moa-8', 'count' => 8, 'last_seen' => $now - 7000 ] ], 'moa.test' );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, $now );
		$this->assertCount( 2, $store->ranked, 'the fold ranks both servers' );

		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $moa ), [ $moa => [ 'url' => 'https://moa.test/moa-8', 'count' => 19 ] ], 'moa.test' );
		$store->bucket_forget( Stats_Store::url_rank_done_parts( Stats_Store::server_key( 'moa.test' ) ), $hour );
		$store->ranked = [];
		( new \ReflectionProperty( $fb, 'folds_since_reprobe' ) )->setValue( $fb, PHP_INT_MAX - 1 );
		$fb->flush();

		$this->assertEqualsCanonicalizing(
			[ Stats_Store::server_key( 'kea.test' ), Stats_Store::server_key( 'moa.test' ) ],
			\array_values( $store->ranked ),
			'the hour re-ranks every server it names'
		);
		$this->assertSame( [], $this->url_rank_done( $store, $hour, 'moa.test' ), 'its marker is back' );
		$moa_list = $store->url_rank_window( [ $hour ], [], 'count', 'desc', 'moa.test' );
		$this->assertSame( 19, $moa_list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
		$site = $store->url_rank_window( [ $hour ], [], 'count', 'desc', '' );
		$this->assertSame( [ $moa, $kea ], \array_column( $site[0][1], Stats_Store::RANK_HASH ), 'the site merges both' );
	}

	public function test_a_late_write_forgets_each_hours_marker_once_a_flush(): void {
		// Rows and names across two buckets: several hour keys land for one
		// hour, and one delete says what all of them do.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			/** @var list<string> */
			public array $forgotten = [];
			public function bucket_forget( array $parts, string $bucket ): void {
				$this->forgotten[] = self::key( ...[ ...$parts, $bucket ] );
				parent::bucket_forget( $parts, $bucket );
			}
		};
		$hash  = 'b6b6b6b6b6b6';
		$shard = Stats_Store::url_shard( $hash );
		$fb    = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/once-a-flush-2208', 'count' => 3 ],
		] );

		$this->flush_buckets( $fb, [
			'2026-08-27-13-20' => [
				'url_stats' => [ $hash => self::positional_url_row( [ 'count' => 5 ] ) ],
				'url_names' => [ $hash => 'https://kea.test/once-a-flush-2208' ],
			],
			'2026-08-27-13-40' => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => 7 ] ) ] ],
		] );

		$this->assertSame( [ 'urlrank_sh:done:' . Stats_Store::server_key( self::SEED_SERVER ) . ':2026-08-27-13' ], $store->forgotten );
	}

	public function test_a_flushed_bucket_below_the_fine_floor_is_not_ranked(): void {
		// Hour 09 is unfolded, so its bucket's write forms a ranking group,
		// but no reader plans it fine-grained. Seeds: 19 requests old, 5 new.
		Core::$memd = new InMemoryMemcached();
		$store      = self::hour_rank_recorder();
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$old       = '2026-08-27-09-20';
		$new       = '2026-08-27-15-05';

		$this->flush_buckets( $fb, [
			$old => [ 'url_stats' => [ 'a4a4a4a4a4a4' => self::positional_url_row( [ 'count' => 19 ] ) ] ],
			$new => [ 'url_stats' => [ 'a4a4a4a4a4a4' => self::positional_url_row( [ 'count' => 5 ] ) ] ],
		] );

		$this->assertContains( $new, $store->bucket_ranks, 'a bucket in the fine tail ranks' );
		$this->assertNotContains( $old, $store->bucket_ranks, 'one below it does not' );
		$this->assertArrayNotHasKey( $old, ( new \ReflectionProperty( $fb, 'rank_pending' ) )->getValue( $fb ) );
	}

	public function test_a_refused_late_write_names_each_key_it_lost(): void {
		// One late write is two keys in two tiers; which one was refused is
		// the operator's next move, so each refusal names its own key.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			public bool $refuse = false;
			public function bucket_set_multi( array $writes ): array {
				$out = parent::bucket_set_multi( $writes );
				foreach ( $writes as $i => [ $parts ] ) {
					if ( $this->refuse && \in_array( $parts[0], [ self::NS_URLS, self::NS_URLS_HOUR ], true ) ) {
						$out[ $i ] = false;
					}
				}
				return $out;
			}
		};
		$hash  = 'c8c8c8c8c8c8';
		$shard = Stats_Store::url_shard( $hash );
		$fb    = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/refused-late-6630', 'count' => 3 ],
		] );

		$store->refuse = true;
		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'count' => 7 ] ),
		] );

		$srv = Stats_Store::server_key( self::SEED_SERVER );
		$this->assertStringContainsString( "urls:{$srv}:{$shard}:2026-08-27-13-40", $err, 'the fine key' );
		$this->assertStringContainsString( "urls_h:{$srv}:{$shard}:2026-08-27-13", $err, 'and the hour key' );
	}

	public function test_the_rank_memo_prune_floor_is_the_read_plans_fine_tail(): void {
		// At :59 the fine tail runs 24 buckets back, to the start of the hour
		// it reaches into, so a bucket 20 back is still read fine-grained.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \gmmktime( 15, 59, 0, 8, 27, 2026 );
		$fine       = Stats_Store::read_plan( Stats_Store::retention_buckets( $store->ttl(), $now ) )['fine'];
		$inside     = '2026-08-27-14-15';
		$outside    = '2026-08-27-13-55';
		$this->assertContains( $inside, $fine, 'the fixture bucket is in the fine tail' );
		$this->assertNotContains( $outside, $fine, 'and the other is behind it' );
		$this->assertLessThan( Stats_Store::bucket_key( $now - ( Stats_Store::FINE_BUCKETS * Stats_Store::BUCKET_SECONDS ) ), $inside, 'below FINE_BUCKETS' );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$ranked_at = new \ReflectionProperty( $fb, 'ranked_at' );
		$pending   = new \ReflectionProperty( $fb, 'rank_pending' );
		$pending->setValue( $fb, [ $inside => true, $outside => true ] );

		Core::$now = $now;
		$fb->flush();

		$this->assertSame( $now, $ranked_at->getValue( $fb )[ $inside ] ?? null, 'a bucket the reader plans is ranked' );
		$this->assertArrayNotHasKey( $outside, $ranked_at->getValue( $fb ), 'one it does not is not ranked' );
		$this->assertSame( [], $pending->getValue( $fb ), 'and both leave the pending set' );
	}

	public function test_a_late_fine_write_is_mirrored_and_kept_at_the_fine_ttl(): void {
		// Hour 13 is folded at 15:07. A late write into its 13:00
		// and 13:40 buckets goes through the Table like any other fine write.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = 'e9e9e9e9e9e9';
		$shard      = Stats_Store::url_shard( $hash );
		$fb         = $this->folded_builder( $store, $shard, [
			$hash => [ 'url' => '/spent-bucket-2284', 'count' => 3 ],
		] );
		$fb->set_stats_target( 'no-such-mirror-7731' );

		$this->flush_buckets( $fb, [
			'2026-08-27-13-00' => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => 7 ] ) ] ],
			'2026-08-27-13-40' => [ 'url_stats' => [ $hash => self::positional_url_row( [ 'count' => 11 ] ) ] ],
		] );

		$held    = ( new \ReflectionProperty( $fb, 'mirror' ) )->getValue( $fb )[ Stats_Store::NS_URLS ] ?? [];
		$expires = Core::$memd->expiries();
		foreach ( [ '2026-08-27-13-00', '2026-08-27-13-40' ] as $bucket ) {
			$key = 'urls:' . Stats_Store::server_key( self::SEED_SERVER ) . ":{$shard}:{$bucket}";
			$this->assertArrayHasKey( Stats_Store::entry_key( 0, $key ), $held, "{$bucket} reaches the mirror" );
			$this->assertEqualsWithDelta( \time() + $store->ttl_url_fine(), $expires[ self::cache_key( 0, $key ) ] ?? 0, 2, "{$bucket} keeps the fine TTL in memcache" );
		}
	}

	public function test_an_all_digit_hash_merges_into_its_stored_row(): void {
		// A 12-hex-digit URL hash whose digits are all decimal is an INT array
		// key wherever PHP stores it, so the flush's shard merge has to find
		// the stored row under it rather than seeding an empty one beside it.
		// Seeds distinct from every default: 4 stored requests at 88ms against
		// 11 flushed at 275ms.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = '481602937158';
		$shard      = Stats_Store::url_shard( $hash );
		$bucket     = '2026-08-27-13-40';

		$this->seed_url_shard( $store, $bucket, $shard, [
			$hash => [ 'url' => '/numeral-4816', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 88.0 ],
		] );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$this->flush_pending( $fb, $bucket, [
			$hash => self::positional_url_row( [ 'url' => '/numeral-4816', 'count' => 11, 'timed_count' => 11, 'sum_ms' => 275.0 ] ),
		] );

		$rows = $this->url_bucket_rows( $store, $bucket );
		$this->assertArrayHasKey( $hash, $rows, 'the row is still reachable by its hash' );
		$this->assertSame( 15, $rows[ $hash ]['count'], 'the flush merged into the stored row' );
		$this->assertSame( 363.0, (float) $rows[ $hash ]['sum_ms'] );
	}

	public function test_a_refused_hour_shard_write_is_logged_by_shard(): void {
		// The fold writes every server's shards in one batch, and the batch
		// answers per write — so a refusal names the shard that was lost, not
		// the hour, and the operator's next move is which shard is over the
		// item limit.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		Core::$memd = new InMemoryMemcached();
		$hash       = Log_Manager::url_hash( 'https://kea.test/dugong-9914' );
		$shard      = Stats_Store::url_shard( $hash );
		$store      = new class( 0, 86400 ) extends Stats_Store {
			public string $refuse_shard = '';
			public function bucket_set_multi( array $writes ): array {
				$out = parent::bucket_set_multi( $writes );
				foreach ( $writes as $i => [ $parts ] ) {
					if ( self::NS_URLS_HOUR === $parts[0] && $this->refuse_shard === ( $parts[2] ?? '' ) ) {
						$out[ $i ] = false;
					}
				}
				return $out;
			}
		};
		$store->refuse_shard = $shard;
		$this->set_url_bucket( $store, '2026-08-27-13-05', [
			$hash => [ 'url' => 'https://kea.test/dugong-9914', 'count' => 17, 'timed_count' => 17, 'sum_ms' => 340.0, 'last_seen' => 1756300000 ],
		] );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );

		$this->assertStringContainsString( 'hour fold write refused', $err );
		$this->assertStringContainsString( Stats_Store::NS_URLS_HOUR . ':' . Stats_Store::server_key( self::SEED_SERVER ) . ':' . $shard . ':2026-08-27-13', $err, 'the refusal names the shard' );
	}

	public function test_a_refused_fold_write_is_not_re_folded_next_flush(): void {
		// Nothing retries a refused cache write: the hour is folded whatever
		// its writes answered, so the next flush neither probes nor folds it.
		Core::$memd = new InMemoryMemcached();
		$hash       = Log_Manager::url_hash( 'https://kea.test/weka-6107' );
		$shard      = Stats_Store::url_shard( $hash );
		$store      = new class( 0, 86400 ) extends Stats_Store {
			public string $refuse_shard = '';
			public int $folds           = 0;
			public function bucket_set_multi( array $writes ): array {
				$refused = [];
				foreach ( $writes as $i => [ $parts, $key ] ) {
					if ( self::NS_URLS_HOUR === $parts[0] && $this->refuse_shard === ( $parts[2] ?? '' ) && '2026-08-27-13' === $key ) {
						++$this->folds;
						$refused[ $i ] = false;
					}
				}
				$kept = \array_diff_key( $writes, $refused );
				return \array_replace( \array_combine( \array_keys( $kept ), parent::bucket_set_multi( \array_values( $kept ) ) ), $refused );
			}
		};
		$store->refuse_shard = $shard;
		$this->set_url_bucket( $store, '2026-08-27-13-05', [
			$hash => [ 'url' => 'https://kea.test/weka-6107', 'count' => 19, 'timed_count' => 19, 'sum_ms' => 380.0, 'last_seen' => 1756300000 ],
		] );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		self::roll_up( $fb, $now );
		self::roll_up( $fb, $now + 5 );

		$this->assertSame( 1, $store->folds, 'the refused shard is folded once' );
	}

	public function test_a_refused_hour_list_is_not_re_ranked_on_the_next_reprobe(): void {
		// The re-probe finds the hour folded; a done marker written beside a
		// refused list is what keeps it from ranking the hour again.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			public int $rankings = 0;
			public function bucket_set_multi( array $writes ): array {
				$out     = parent::bucket_set_multi( $writes );
				$counted = false;
				foreach ( $writes as $i => [ $parts, $key ] ) {
					if ( self::NS_URLRANK_HOUR_S === $parts[0] && 'done' !== $parts[1] && '2026-08-27-13' === $key ) {
						$counted   = true;
						$out[ $i ] = false;
					}
				}
				$this->rankings += $counted ? 1 : 0;
				return $out;
			}
		};
		$this->set_url_bucket( $store, '2026-08-27-13-05', [
			'e8e8e8e8e8e8' => [ 'url' => 'https://kea.test/ruru-5528', 'count' => 23, 'timed_count' => 23, 'sum_ms' => 460.0, 'last_seen' => 1756300000 ],
		] );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$fb->flush();
		$this->assertSame( 1, $store->rankings, 'the fold ranked the hour' );

		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, [] );
		Core::$now += 5;
		$fb->flush();

		$this->assertSame( 1, $store->rankings, 'the re-probe does not rank it again' );
	}

	public function test_a_closed_hour_is_ranked_when_it_is_folded(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->set_url_bucket( $store, '2026-08-27-13-05', [
			'a1a1a1a1a1a1' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 70.0, 'last_seen' => 1756300000 ],
		], 'kea.test' );
		$this->set_url_bucket( $store, '2026-08-27-13-40', [
			'a1a1a1a1a1a1' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 20.0, 'last_seen' => 1756302100 ],
			'b2b2b2b2b2b2' => [ 'url' => 'https://kea.test/kiwi-8842', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 4000.0, 'last_seen' => 1756302200 ],
		], 'kea.test' );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$count = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' );
		$this->assertCount( 1, $count, 'the hour has a list' );
		$this->assertSame( [ 'a1a1a1a1a1a1', 'b2b2b2b2b2b2' ], \array_column( $count[0][1], Stats_Store::RANK_HASH ) );
		$this->assertSame( 9, $count[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ], 'ranked over the FOLDED row' );
		$this->assertSame(
			'/kiwi-8842',
			$store->url_rank_window( [ '2026-08-27-13' ], [], 'url', 'asc', '' )[0][1][0][ Stats_Store::RANK_PATH ]
		);
	}

	public function test_an_hour_with_rows_and_names_but_no_lists_is_ranked_from_its_coarse_rows(): void {
		// The shape a release before the rank tier left behind, or an evicted
		// list key. Its fine buckets are gone, so the fold must NOT run again —
		// that would overwrite the hour with nothing — and the lists come
		// from the coarse rows themselves. The FIRST closed hour of the plan,
		// so one flush reaches it whatever `ROLLUP_HOURS_PER_FLUSH` is.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$hash       = 'c3c3c3c3c3c3';
		foreach ( \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) ) as $one ) {
			$this->seed_url_hour( $store, '2026-08-27-13', $one, Stats_Store::url_shard( $hash ) === $one
				? [ $hash => [ 'url' => 'https://moa.test/tui-9913', 'count' => 11, 'timed_count' => 11, 'sum_ms' => 110.0, 'last_seen' => 1756285000 ] ]
				: [], 'moa.test' );
		}
		$store->bucket_set_multi( [ [ Stats_Store::lb_hour_parts(), '2026-08-27-13', [] ] ] );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = $now;
		$fb->flush();

		$rows = $store->url_hour_sources( [ '2026-08-27-13' ], Stats_Store::url_shard( $hash ) );
		$this->assertSame( 11, Core::arr( $rows[0][1][ $hash ] )[ Stats_Store::ROW_COUNT ], 'the coarse rows survive' );
		$this->assertSame( 'moa.test', $rows[0][2], 'under the server the hour\'s index names' );
		$list = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' );
		$this->assertSame( [ $hash ], \array_column( $list[0][1], Stats_Store::RANK_HASH ) );
		$this->assertSame( '/tui-9913', $store->url_rank_window( [ '2026-08-27-13' ], [], 'url', 'asc', '' )[0][1][0][ Stats_Store::RANK_PATH ] );
	}

	public function test_a_late_write_into_a_folded_hour_is_re_ranked_by_the_next_worker(): void {
		// The hour is folded AND ranked, so the probe memoizes it done and
		// never revisits it. A replay merges into the coarse rows the reader
		// takes; a list still holding the pre-replay count is a count that
		// shows in the fold and the header but never on a ranked page. The
		// forgotten marker is in the store, so a stop loses no re-rank.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = Log_Manager::url_hash( '/restaged-4417' );
		$shard      = Stats_Store::url_shard( $hash );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$hash => [ 'url' => '/restaged-4417', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 33.0, 'last_seen' => $now - 7200 ],
		] );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );
		$folded = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' );
		$this->assertSame( 3, $folded[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ], 'the fold ranked it' );

		// A replayed record for another bucket of that same closed hour.
		$this->flush_pending( $fb, '2026-08-27-13-40', [
			$hash => self::positional_url_row( [ 'url' => '/restaged-4417', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 210.0, 'last_seen' => $now - 5400 ] ),
		] );

		$next = new Flame_Builder_Node();
		$next->set_stats_store( $store );
		$next->flush();

		$list = $store->url_rank_window( [ '2026-08-27-13' ], [], 'count', 'desc', '' );
		$this->assertCount( 1, $list, 'the hour still has a list' );
		$this->assertSame( [ $hash ], \array_column( $list[0][1], Stats_Store::RANK_HASH ) );
		$this->assertSame( 10, $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ] );
	}

	public function test_stale_folded_hours_are_re_ranked_in_two_reads(): void {
		// A replay leaves several folded hours unranked, and the probe finds
		// each. A read PER HOUR is a round trip per hour; the whole set's
		// server index comes in one and its coarse rows in a second. Two
		// hours, because `ROLLUP_HOURS_PER_FLUSH` bounds the stale hours one
		// flush ranks.
		$memd       = new InMemoryMemcached();
		Core::$memd = $memd;
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$hash  = Log_Manager::url_hash( 'https://kea.test/kokako-2280' );
		$hours = [ '2026-08-27-11', '2026-08-27-12' ];
		foreach ( $hours as $i => $hour ) {
			$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $hash ), [
				$hash => [ 'url' => 'https://kea.test/kokako-2280', 'count' => 7 + $i, 'last_seen' => 1756290000 + $i ],
			] );
		}
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, \array_fill_keys( $hours, true ) );
		( new \ReflectionProperty( $fb, 'stale_hours' ) )->setValue( $fb, \array_fill_keys( $hours, true ) );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		$memd->multi_calls = 0;
		$this->flush_buckets( $fb, [] );

		$this->assertSame( 2, $memd->multi_calls, 'one index read and one rows read for both hours' );
		foreach ( $hours as $i => $hour ) {
			$list = $store->url_rank_window( [ $hour ], [], 'count', 'desc', '' );
			$this->assertSame( [ $hash ], \array_column( $list[0][1], Stats_Store::RANK_HASH ), $hour );
			$this->assertSame( 7 + $i, $list[0][1][0][ Stats_Store::RANK_ROW ][ Stats_Store::ROW_COUNT ], $hour );
		}
	}

	public function test_two_buckets_of_one_folded_hour_both_reach_the_coarse_key(): void {
		// Both buckets' rows are keyed on the SAME `urls_h:{shard}:{hour}`
		// item, and the chunk reads it once: a merge built on that one
		// pre-read value discards whatever the intent before it merged in.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		// One shard, so the two intents collide on one key.
		$early = 'd1d1d1d1d1d1';
		$late  = 'd2d2d2d2d2d2';
		$hour  = '2026-08-27-13';
		// A folded hour holds its shard, empty or not.
		$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $early ), [] );
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, [ $hour => true ] );

		$this->flush_buckets( $fb, [
			$hour . '-05' => [ 'url_stats' => [ $early => self::positional_url_row( [ 'count' => 3, 'last_seen' => 1756300000 ] ) ] ],
			$hour . '-40' => [ 'url_stats' => [ $late => self::positional_url_row( [ 'count' => 8, 'last_seen' => 1756302100 ] ) ] ],
		] );

		$rows = self::named_url_rows( $store->url_hour_sources( [ $hour ], Stats_Store::url_shard( $early ) )[0][1] );
		$this->assertSame( [ $early, $late ], \array_keys( $rows ), 'both buckets reach the hour key' );
		$this->assertSame( 3, $rows[ $early ]['count'] );
		$this->assertSame( 8, $rows[ $late ]['count'] );
	}

	public function test_rank_only_hours_do_not_spend_the_fold_budget(): void {
		// Every closed hour but the oldest holds rows and names but no lists,
		// so the probe marks each stale and folds nothing. Charging those to
		// the fold budget would starve the one hour that still needs a fold.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$hours      = Stats_Store::read_plan( Stats_Store::retention_buckets( $store->ttl(), $now ) )['hours'];
		// Newest first, so the unfolded hour is the last the probe reaches.
		$unfolded = \array_pop( $hours );
		$this->assertGreaterThanOrEqual( 20, \count( $hours ) );
		foreach ( $hours as $hour ) {
			foreach ( \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) ) as $one ) {
				$this->seed_url_hour( $store, $hour, $one, [] );
			}
			$store->bucket_set_multi( [ [ Stats_Store::lb_hour_parts(), $hour, [] ] ] );
		}
		$hash = 'f4f4f4f4f4f4';
		$this->set_url_bucket( $store, $unfolded . '-20', [
			$hash => [ 'url' => 'https://kea.test/takahe-6621', 'count' => 13, 'timed_count' => 13, 'sum_ms' => 390.0, 'last_seen' => $now - 80000 ],
		] );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, $now );

		$rows = $store->url_hour_sources( [ $unfolded ], Stats_Store::url_shard( $hash ) );
		$this->assertNotEmpty( $rows, 'the unfolded hour folds on the first flush' );
		$this->assertSame( 13, Core::arr( $rows[0][1][ $hash ] )[ Stats_Store::ROW_COUNT ] );
	}

	public function test_stale_hours_rank_at_most_the_budget_per_flush(): void {
		// A replay spanning the window marks every hour stale, and ranking
		// holds each hour's coarse rows at once: the budget bounds that.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$budget = ( new \ReflectionClassConstant( Flame_Builder_Node::class, 'ROLLUP_HOURS_PER_FLUSH' ) )->getValue();
		$hash   = 'a7a7a7a7a7a7';
		$hours  = [ '2026-08-27-06', '2026-08-27-07', '2026-08-27-08', '2026-08-27-09', '2026-08-27-10' ];
		foreach ( $hours as $i => $hour ) {
			$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $hash ), [
				$hash => [ 'url' => 'https://kea.test/hihi-5519', 'count' => 21 + $i, 'last_seen' => 1756270000 + $i ],
			] );
		}
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, \array_fill_keys( $hours, true ) );
		( new \ReflectionProperty( $fb, 'stale_hours' ) )->setValue( $fb, \array_fill_keys( $hours, true ) );
		$ranked = static fn (): int => \count( \array_filter(
			$hours,
			static fn ( string $hour ): bool => [] !== $store->url_rank_window( [ $hour ], [], 'count', 'desc', '' )
		) );

		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->flush_buckets( $fb, [] );
		$this->assertSame( $budget, $ranked(), 'one flush ranks the budget' );

		$this->flush_buckets( $fb, [] );
		$this->assertSame( \min( 2 * $budget, \count( $hours ) ), $ranked(), 'the rest wait for the next flush' );

		for ( $i = 0; $i < \count( $hours ); $i++ ) {
			$this->flush_buckets( $fb, [] );
		}
		$this->assertSame( \count( $hours ), $ranked(), 'every stale hour is ranked in the end' );
	}

	public function test_a_stop_ranks_no_more_stale_hours_than_a_flush(): void {
		// Every stale hour is probe-found, so its missing marker is in the
		// store and the next worker's probe finds it again: a stop owes none
		// of them more than the flush's own bound.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$budget = ( new \ReflectionClassConstant( Flame_Builder_Node::class, 'ROLLUP_HOURS_PER_FLUSH' ) )->getValue();
		$hash   = 'a9a9a9a9a9a9';
		$hours  = [ '2026-08-27-06', '2026-08-27-07', '2026-08-27-08', '2026-08-27-09', '2026-08-27-10' ];
		foreach ( $hours as $i => $hour ) {
			$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $hash ), [
				$hash => [ 'url' => 'https://kea.test/pipipi-7102', 'count' => 31 + $i, 'last_seen' => 1756270000 + $i ],
			] );
		}
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$plan      = Stats_Store::read_plan( Stats_Store::retention_buckets( $store->ttl(), (int) Core::$now ) )['hours'];
		( new \ReflectionProperty( $fb, 'folded_hours' ) )->setValue( $fb, \array_fill_keys( $plan, true ) );
		( new \ReflectionProperty( $fb, 'stale_hours' ) )->setValue( $fb, \array_fill_keys( $hours, true ) );

		$fb->shutdown_sweep();

		$ranked = \array_filter( $hours, static fn ( string $hour ): bool => null !== self::ranked_count( $store, $hour, true ) );
		$this->assertCount( $budget, $ranked, 'the stop ranks the flush\'s bound' );
		$this->assertCount( \count( $hours ) - $budget, ( new \ReflectionProperty( $fb, 'stale_hours' ) )->getValue( $fb ), 'and leaves the rest' );
	}

	public function test_due_buckets_rank_in_bounded_reads(): void {
		// Each due bucket gap-fills sixteen keys a server and holds its row
		// maps until it ranks, so one read of every offered bucket is the
		// unbounded batch the per-chunk ranking exists to prevent. A replay's
		// flushed buckets reach the ranker unpruned, so one bucket more than a
		// write chunk's worth of groups forces a second read.
		Core::$memd = new InMemoryMemcached();
		$limit      = ( new \ReflectionClassConstant( Flame_Builder_Node::class, 'WRITE_BATCH_KEYS' ) )->getValue();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			/** @var list<int> */
			public array $shard_reads = [];
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				if ( self::NS_URLS === ( $reads[ \array_key_first( $reads ) ][0][0] ?? '' ) ) {
					$this->shard_reads[] = \count( $reads );
				}
				return parent::bucket_get_multi( $reads, $failed );
			}
		};
		$at      = \gmmktime( 13, 2, 0, 9, 22, 2026 );
		$count   = \intdiv( $limit, Stats_Store::URL_SHARDS ) + 1;
		$buckets = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$bucket             = Stats_Store::bucket_key( $at - ( ( $i + 1 ) * Stats_Store::BUCKET_SECONDS ) );
			$buckets[ $bucket ] = 23 + $i;
			$this->set_url_bucket( $store, $bucket, [
				'f2f2f2f2f2f2' => [ 'url' => 'https://kea.test/kaka-8830', 'count' => 23 + $i, 'timed_count' => 1, 'sum_ms' => 12.0, 'last_seen' => $at - 60 ],
			] );
		}
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		Core::$now = $at;
		( new \ReflectionMethod( $fb, 'rank_buckets' ) )->invoke( $fb, $store, \array_keys( $buckets ), $at );

		$this->assertGreaterThanOrEqual( 2, \count( $store->shard_reads ), 'more than one read' );
		foreach ( $store->shard_reads as $size ) {
			$this->assertLessThanOrEqual( $limit, $size, 'each read within a write chunk' );
		}
		foreach ( $buckets as $bucket => $n ) {
			$this->assertSame( $n, self::ranked_count( $store, $bucket, false ), $bucket );
		}
	}

	public function test_a_pending_bucket_older_than_the_fine_window_is_never_ranked(): void {
		// Past the fine floor no reader plans the bucket, so ranking it spends
		// a 32-key read and a list write on nothing. Seeds distinct from every
		// default: 17 requests, two buckets beyond the window.
		Core::$memd = new InMemoryMemcached();
		$store      = new class( 0, 86400 ) extends Stats_Store {
			/** @var list<string> */
			public array $touched = [];
			public function bucket_get_multi( array $reads, ?bool &$failed = null ): array {
				foreach ( $reads as [ , $bucket ] ) {
					$this->touched[] = $bucket;
				}
				return parent::bucket_get_multi( $reads, $failed );
			}
			public function bucket_set_multi( array $writes ): array {
				foreach ( $writes as [ , $bucket ] ) {
					$this->touched[] = $bucket;
				}
				return parent::bucket_set_multi( $writes );
			}
		};
		$at  = \gmmktime( 16, 4, 0, 9, 22, 2026 );
		$old = Stats_Store::bucket_key( $at - ( ( Stats_Store::FINE_BUCKETS + 2 ) * Stats_Store::BUCKET_SECONDS ) );
		$this->set_url_bucket( $store, $old, [
			'e5e5e5e5e5e5' => [ 'url' => 'https://kea.test/weka-3391', 'count' => 17, 'timed_count' => 1, 'sum_ms' => 9.0, 'last_seen' => $at - 7000 ],
		] );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		( new \ReflectionProperty( $fb, 'rank_pending' ) )->setValue( $fb, [ $old => true ] );
		$store->touched = [];

		Core::$now = $at;
		( new \ReflectionMethod( $fb, 'rank_owed' ) )->invoke( $fb, $store, $at, self::fine_floor( $store, $at ) );

		$this->assertNotContains( $old, $store->touched, 'the out-of-window bucket is neither read nor written' );
		$this->assertSame( [], ( new \ReflectionProperty( $fb, 'rank_pending' ) )->getValue( $fb ) );
	}

	public function test_a_checkpoint_is_dated_by_the_tick_save_state_first_read(): void {
		// Writing a closed frame can take long enough for the tick to move;
		// the checkpoint dates what it snapshotted, not when it finished.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb ]     = $this->mirrored_builder( $store, 'flames-stats', ClockAdvancingPartition::class );
		$at         = 1_700_000_000;
		Core::$now  = $at;
		$this->set_hourly_bucket( $store, Stats_Store::bucket_key( $at - 900 ), [ 'count' => 5 ] );

		$this->assertSame( $at, $fb->save_state()['mirror']['at'] );
		$this->assertGreaterThan( $at, Core::$now, 'the frame write moved the tick' );
	}

	public function test_a_closed_hour_folds_the_leaderboard_too(): void {
		// `build_leaderboard()` reads 288 buckets x 4 partitions, and every one
		// of them carries a category per hook the site fires — 1,198 on a
		// production hub, each with its own entry map. That is the read that
		// takes the overview past any answering deadline. The same fold `urls`
		// gets takes it to 13 fine buckets plus 23 hours.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );

		$this->set_leaderboard_bucket( $store, '2026-08-27-13-05', [
			'count' => 4, 'sum_req_time' => 8.0,
			'categories' => [ 'db' => [ 'samples' => 4, 'sum_time' => 40.0, 'sum_count' => 8, 'entries' => [] ] ],
		] );
		$this->set_leaderboard_bucket( $store, '2026-08-27-13-40', [
			'count' => 6, 'sum_req_time' => 12.0,
			'categories' => [ 'db' => [ 'samples' => 6, 'sum_time' => 60.0, 'sum_count' => 12, 'entries' => [] ] ],
		] );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$hour = $store->get_leaderboard_hours( [ '2026-08-27-13' ] )['2026-08-27-13'] ?? null;
		$this->assertNotNull( $hour, 'the hour has a coarse leaderboard key' );
		$this->assertSame( 10, $hour['count'], 'the hour sums its buckets' );
		$this->assertSame( 100.0, (float) $hour['categories']['db']['sum_time'] );
	}

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
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$rolled = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], $shard )[0][1] );
		$this->assertSame( 10, $rolled[ $hash ]['count'], 'the hour sums its buckets' );
		$this->assertSame( 130.0, (float) $rolled[ $hash ]['sum_ms'] );
		$this->assertSame( 22.0, (float) $rolled[ $hash ]['max_ms'], 'an extreme is a max, not a sum' );
		// The name is not in the row: the fold carries statistics, and the URL
		// name table carries the one copy of what those statistics are about.
		$this->assertSame( [ $hash => [ 'server' => self::SEED_SERVER, 'url' => 'https://' . self::SEED_SERVER . '/wombat-4471' ] ], $store->get_url_names( [ $hash ] ) );
	}

	public function test_a_folded_hour_keeps_each_server_apart(): void {
		// The fold reads each server's fine keys and writes each server's hour
		// keys: a folded hour never merges two servers into one value, and its
		// index names every server its buckets named.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$hash       = Log_Manager::url_hash( '/wombat-4471' );
		$shard      = Stats_Store::url_shard( $hash );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		$this->seed_url_shard( $store, '2026-08-27-13-05', $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 40.0 ],
		], 'web-4471.test' );
		$this->seed_url_shard( $store, '2026-08-27-13-40', $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 6, 'timed_count' => 6, 'sum_ms' => 90.0 ],
		], 'web-8823.test' );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$this->assertSame( 4, self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', $shard, 'web-4471.test' ) )[ $hash ]['count'] );
		$this->assertSame( 6, self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', $shard, 'web-8823.test' ) )[ $hash ]['count'] );
		$this->assertSame(
			[ Stats_Store::server_key( 'web-4471.test' ) => 'web-4471.test', Stats_Store::server_key( 'web-8823.test' ) => 'web-8823.test' ],
			$store->server_index( [ '2026-08-27-13' ], [] )['2026-08-27-13']
		);
		$this->assertSame( [], $this->get_url_hour( $store, '2026-08-27-13', 'w3', 'web-4471.test' ), 'every shard of a named server is written, empty or not' );
	}

	public function test_an_hour_naming_too_many_servers_folds_the_quietest_into_other(): void {
		// Each bucket admits MAX_SERVER_VALUES, so twelve of them can name
		// more between them; the hour keeps the busiest and folds the rest
		// into its `Other` server, whose rows stay whole.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		for ( $i = 0; $i < Stats_Store::MAX_SERVER_VALUES; $i++ ) {
			$this->seed_url_shard( $store, '2026-08-27-13-05', '0', [
				\sprintf( '0%011x', $i ) => [ 'url' => "/busy-{$i}", 'count' => 5 ],
			], "busy{$i}.test" );
		}
		$this->seed_url_shard( $store, '2026-08-27-13-40', '0', [
			'0fffffffffff' => [ 'url' => '/quiet-4471', 'count' => 1 ],
		], 'quiet-4471.test' );

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );

		$index = $store->server_index( [ '2026-08-27-13' ], [] )['2026-08-27-13'];
		$this->assertCount( Stats_Store::MAX_SERVER_VALUES + 1, $index );
		$this->assertArrayNotHasKey( Stats_Store::server_key( 'quiet-4471.test' ), $index );
		$this->assertSame( Stats_Store::OTHER_KEY, $index[ Stats_Store::server_key( Stats_Store::OTHER_KEY ) ] );
		$this->assertSame( 1, self::named_url_rows( $this->get_url_hour( $store, '2026-08-27-13', '0', Stats_Store::OTHER_KEY ) )['0fffffffffff']['count'] );
	}

	public function test_a_busy_servers_tail_never_folds_a_quiet_servers_rows(): void {
		// The reason the server is in the key: a busy spoke filling a shard
		// folds only its own tail into its own `Other`, and a quiet spoke's
		// URL in the same shard keeps its row.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \time();
		$bucket     = Stats_Store::bucket_key( $now );

		$seed = [];
		$cap  = Flame_Builder_Node::MAX_URLS_PER_SHARD;
		for ( $i = 0; $i < $cap + 20; $i++ ) {
			$seed[ \sprintf( 'a%011x', $i ) ] = [
				'url' => "/u{$i}", 'count' => 7 + $i, 'timed_count' => 7 + $i,
				'sum_ms' => 2.0 * ( 7 + $i ), 'last_seen' => $now,
			];
		}
		$this->seed_url_shard( $store, $bucket, 'a', $seed, 'tail.example' );

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
		$this->fill_request( $fb, $this->completed_request( [
			'url' => $url, 'server_name' => 'tail.example', 'duration_ms' => 2.0, 'timestamp' => $now,
		] ) );
		$fb->flush();

		$live = self::named_url_rows( $this->get_url_shard( $store, $bucket, 'a', 'live.example' ) );
		$this->assertSame( [ Log_Manager::url_hash( $url ) ], \array_keys( $live ), 'the quiet server keeps its row' );
		$tail = self::named_url_rows( $this->get_url_shard( $store, $bucket, 'a', 'tail.example' ) );
		$this->assertLessThanOrEqual( $cap, \count( $tail ), 'the busy server is capped' );
		$this->assertArrayHasKey( Stats_Store::OTHER_KEY, $tail, 'and folds its own tail' );
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
		$row    = self::named_url_rows( $this->get_url_shard( $store, $bucket, Stats_Store::url_shard( $hash ) ) )[ $hash ];

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

	public function test_a_folded_hour_takes_the_same_row_cap_as_a_bucket(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$now        = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		// Six buckets of distinct URLs, all in one shard, summing to half again
		// the ceiling the folded hour has to apply.
		$cap   = Flame_Builder_Node::MAX_URLS_PER_SHARD;
		$per   = (int) \ceil( $cap / 4 );
		$total = 0;
		foreach ( \array_slice( Stats_Store::buckets_in_hour( '2026-08-27-13' ), 0, 6 ) as $b => $bucket ) {
			$rows = [];
			for ( $i = 0; $i < $per; $i++ ) {
				$rows[ \sprintf( 'a%011x', $b * ( $per + 1 ) + $i ) ] = [
					'url'   => "/row-{$b}-{$i}",
					'count' => 3,
				];
				$total += 3;
			}
			$this->seed_url_shard( $store, $bucket, 'a', $rows );
		}

		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$hour = self::named_url_rows( $store->url_hour_sources( [ '2026-08-27-13' ], 'a' )[0][1] );
		$this->assertLessThanOrEqual( $cap, \count( $hour ), 'the hour takes the shard cap' );
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
		// Every hour already derived — index, rows and lists — so the probe is
		// all this flush does.
		$families = \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) );
		foreach ( Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) )['hours'] as $hour ) {
			foreach ( $families as $shard ) {
				$this->seed_url_hour( $store, $hour, $shard, [] );
			}
			$store->bucket_set_multi( [ [ Stats_Store::lb_hour_parts(), $hour, [] ] ] );
			$this->set_url_rank_lists( $store, $hour, [], true );
		}
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$memd->multi_calls = 0;
		$memd->get_calls   = 0;
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$this->assertSame( 2, $memd->multi_calls, 'one probe for the whole window: its heads, then the rows they name' );
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
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );
		$memd->multi_calls = 0;

		// Everything it folded, it now knows; only the rest can be probed.
		self::roll_up( $fb, (int) Core::$now );
		self::roll_up( $fb, (int) Core::$now );

		$folded = 0;
		foreach ( Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) )['hours'] as $hour ) {
			$folded += [] !== $store->server_index( [ $hour ], [] ) ? 1 : 0;
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
		Core::$now = $now;
		self::roll_up( $fb, (int) Core::$now );

		$other = new Stats_Store( partition: 3, max_lifespan: 86400 );
		$fb->set_stats_store( $other );
		self::roll_up( $fb, (int) Core::$now );

		$hour = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) )['hours'][0];
		$this->assertNotSame(
			[],
			$other->server_index( [ $hour ], [] ),
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

		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );

		$this->assertSame(
			[ '2026-08-27-13' => [] ],
			$store->server_index( [ '2026-08-27-13' ], [] ),
			'its index is written naming no server, so the read finds it rather than falling back'
		);
	}

	/** The hour still filling has more to come; folding it would freeze it. */
	public function test_the_open_hour_is_not_rolled_up(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		Core::$now = \gmmktime( 15, 7, 0, 8, 27, 2026 );
		self::roll_up( $fb, (int) Core::$now );

		$this->assertSame( [], $store->server_index( [ '2026-08-27-15' ], [] ) );
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
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => 'https://edge-a.example/wombat-4471',
			'duration_ms' => 447.0,
			'timestamp'   => $now,
			'server_name' => 'edge-a.example',
		] ) );
		$fb->flush();

		$hash = Log_Manager::url_hash( 'https://edge-a.example/wombat-4471' );
		// RAW, not through the naming read helper: the shape is the assertion.
		$row  = $this->get_url_shard( $store, Stats_Store::bucket_key( $now ), Stats_Store::url_shard( $hash ), 'edge-a.example' )[ $hash ];
		$this->assertSame(
			[],
			\array_values( \array_filter( \array_keys( $row ), '\is_string' ) ),
			'no field NAMES in a stored row — that is the whole point'
		);
		$this->assertSame( '/wombat-4471', $row[ Stats_Store::ROW_PATH ], 'the path is the row\'s one string' );
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
			$this->assertLessThanOrEqual( 500, \count( $this->get_url_shard( $store, $bucket, $shard ) ) );
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
		$this->assertGreaterThan( 0, $dim[ $bucket ]['Other'][ Stats_Store::DIM_COUNT ] );
	}

	public function test_a_stored_dimensional_entry_is_positional(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		for ( $i = 0; $i < 2; $i++ ) {
			$this->fill_request( $fb, $this->completed_request( [
				'url'            => '/quartz',
				'request_method' => 'PATCH',
				'duration_ms'    => 41.5,
				'peak_mb'        => 2.25,
				'timestamp'      => $now,
			] ) );
		}
		$fb->flush();

		$bucket = Stats_Store::bucket_key( $now );
		// Count, summed ms, summed peak MB — in DIM_COUNT / DIM_SUM_MS /
		// DIM_SUM_PEAK_MB order, with no key names stored anywhere.
		$this->assertSame(
			[ 2, 83.0, 4.5 ],
			$this->get_dimensional_bucket( $store, 'method', $bucket )['PATCH']
		);
		$this->assertSame(
			[ 2, 83.0, 4.5 ],
			$this->get_url_dimensional_bucket( $store, Log_Manager::url_hash( '/quartz' ), $bucket )['method']['PATCH']
		);
	}

	public function test_a_pre_deploy_named_dimensional_slot_is_discarded(): void {
		// The offsetlog checkpoint carries no salt, so the first respawn after
		// a deploy really does meet a pending accumulator in the named shape.
		// Reading it would be a second format to maintain; what discarding it
		// costs is one worker's un-flushed delta, which `restore_state()`
		// already declares acceptable.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		Core::$now = $now;
		$fb->restore_state( [
			'pending' => [
				$bucket => [ 'dim' => [ 'method' => [ 'PATCH' => [ 'c' => 61, 's' => 7.5, 'm' => 3.25 ] ] ] ],
			],
		] );

		$this->fill_request( $fb, $this->completed_request( [
			'url'            => '/quartz',
			'request_method' => 'PATCH',
			'duration_ms'    => 41.5,
			'peak_mb'        => 2.25,
			'timestamp'      => $now,
		] ) );
		$fb->flush();

		$this->assertSame(
			[ 1, 41.5, 2.25 ],
			$this->get_dimensional_bucket( $store, 'method', $bucket )['PATCH'],
			'the named slot is dropped whole, never summed into the positional one'
		);
	}

	public function test_a_dimension_value_nothing_measured_is_not_stored(): void {
		// Every stored entry is seeded by a request, so its count is at least
		// one. A zero-count entry is a slot in the cap and a row in a chart
		// legend standing for nothing, and the only way to hold one is to fold
		// a slot the merge could not name.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		Core::$now = $now;
		$fb->restore_state( [
			'pending' => [
				$bucket => [ 'dim' => [ 'method' => [ 'PATCH' => [ 'c' => 61, 's' => 7.5, 'm' => 3.25 ] ] ] ],
			],
		] );
		// No request folds into it, so the fold-time guard never runs.
		$fb->flush();

		$this->assertArrayNotHasKey(
			'PATCH',
			$this->get_dimensional_bucket( $store, 'method', $bucket ),
			'a slot the merge could not name is dropped, not stored as zeros'
		);
	}

	public function test_a_category_nothing_measured_is_not_stored(): void {
		// `add_cat()` counts a request into every entry it folds, so a stored
		// category has seen at least one. Zero means the merge could not name
		// the slot it rebuilt from, and the entry stands for nothing.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		Core::$now = $now;
		$fb->restore_state( [
			'pending' => [ $bucket => [ 'cat' => [ 'zither render' => [ 't' => 812.5, 'c' => 61, 'n' => 7 ] ] ] ],
		] );
		$fb->flush();

		$this->assertArrayNotHasKey(
			'zither render',
			$this->get_category_bucket( $store, $bucket ),
			'a slot the merge could not name is dropped, not stored as zeros'
		);
	}

	public function test_a_capped_dimension_keeps_its_BUSIEST_values(): void {
		// Ranked by the field NAME on a positional entry, `cap_bucket()`'s sort
		// compares nulls, ties every pair, degrades to insertion order and
		// folds the BUSIEST values into Other — invisible to a cap test that
		// only counts what survived.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		// Seeded busiest-LAST, so insertion order is the wrong answer.
		$now = \time();
		for ( $ua = 0; $ua < 15; $ua++ ) {
			for ( $hit = 0; $hit <= $ua; $hit++ ) {
				$this->fill_request( $fb, $this->completed_request( [
					'url'        => '/shared',
					'user_agent' => "ShUA-{$ua}",
					'timestamp'  => $now,
				] ) );
			}
		}
		$fb->flush();

		// The per-URL cap is the tighter of the two, so 15 values cross it.
		$kept = $this->get_url_dimensional_bucket( $store, Log_Manager::url_hash( '/shared' ), Stats_Store::bucket_key( $now ) )['ua'];
		$this->assertArrayHasKey( 'ShUA-14', $kept, 'the busiest value survives the cap' );
		$this->assertArrayNotHasKey( 'ShUA-0', $kept, 'the quietest folds into Other' );
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
		$url_dim  = $this->get_url_dimensional_bucket( $store, $url_hash, $bucket );
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
			'total' => self::cat_entry( 99, 99, 99 ),
			'old'   => self::cat_entry( 99, 99, 99 ),
		];
		$this->set_category_bucket( $store, '1999-01-01-00-00', $untouched );

		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 5.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		$this->assertSame( $untouched, $this->get_category_bucket( $store, '1999-01-01-00-00' ) );
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
		$lb_s   = $this->get_leaderboard_bucket( $store, $bucket, 'srv-cap' );
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
		$this->assertEqualsWithDelta( 0.4, $srv_cats[ $bucket ]['wpdb'][ Stats_Store::CAT_MS ], 1e-6 );
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

	// --- Per-URL aggregate reload ------------------------------------------

	public function test_flame_raw_promoted_to_flame_on_reload(): void {
		// Set store with an entry that has flame_raw set (post-flush format).
				Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$url      = '/promoted';
		$url_hash = Log_Manager::url_hash( $url );
		$this->set_url_stats( $store, $url_hash, [
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
		$this->assertNotEmpty( $this->get_leaderboard_bucket( $store, $bucket ), 'it still counts globally' );
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
		Core::$now = $now;
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

		$this->assertSame( [], $this->get_leaderboard_bucket( $store, $bucket ), 'the global series is untouched' );
	}

	public function test_a_flush_writes_dim_and_cat_per_bucket_and_leaves_others_alone(): void {
		// Bucket-keyed maps under one key meant every flush rewrote the whole
		// series; retention is the key's own TTL now.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$stale_dim = [ '418' => self::dim_entry( 83, 9.5, 4.5 ) ];
		$stale_cat = [ 'sabbath' => self::cat_entry( 7.5, 61, 3 ) ];
		$this->set_dimensional_bucket( $store, 'status', '1999-01-01-00-00', $stale_dim );
		$this->set_category_bucket( $store, '1999-01-01-00-00', $stale_cat );

		$now = \time();
		Core::$now = $now;
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 27.0,
			'timestamp'   => $now,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.25, 'count' => 3, 'entries' => [] ] ],
		] ) );
		$fb->flush();

		$this->assertSame( $stale_dim, $store->get_dimensional_buckets( 'status', [ '1999-01-01-00-00' ] )[ '1999-01-01-00-00' ] ?? [] );
		$this->assertSame( $stale_cat, $this->get_category_bucket( $store, '1999-01-01-00-00' ) );
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
			$this->get_hourly_bucket( $store, Stats_Store::bucket_key( $now ) )['sum_ms'] ?? 0.0,
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
			$this->get_hourly_bucket( $store, Stats_Store::bucket_key( $early ) )['count'] ?? 0,
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
		Core::$now = $now;
		$this->fill_request( $fb, $this->completed_request( [
			'url'         => '/dims',
			'duration_ms' => 33.0,
			'timestamp'   => $now,
			'status'      => 503,
			'method'      => 'POST',
		] ) );
		$fb->flush();

		$hash   = Log_Manager::url_hash( '/dims' );
		$bucket = $this->get_url_dimensional_bucket( $store, $hash, Stats_Store::bucket_key( $now ) );
		$this->assertArrayHasKey( 'status', $bucket );
		$this->assertArrayHasKey( 'method', $bucket, 'every dimension shares the bucket key' );
		$this->assertArrayNotHasKey( 'server', $bucket, 'a URL belongs to one server, so it keeps no server axis' );
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
		Core::$now = $now;
		$fb->restore_state( [
			'pending' => [
				$bucket => [
					'cat_by_server' => [ '' => [ 'db' => self::cat_entry( 4.5, 71, 3 ) ] ],
					'dim_by_server' => [ '' => [ 'status' => [ '503' => self::dim_entry( 67, 2.5, 1.5 ) ] ] ],
				],
			],
		] );
		$fb->flush();

		$this->assertSame( [], $this->get_category_bucket( $store, $bucket ), 'global categories untouched' );
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
		$values = [ 'Other' => self::dim_entry( 640, 0.0, 0.0 ) ];
		for ( $i = 0; $i <= Stats_Store::MAX_DIM_VALUES; $i++ ) {
			$values[ "v{$i}" ] = self::dim_entry( 100 + $i, 0.0, 0.0 );
		}
		Core::$now = $open;
		$fb->restore_state( [
			'pending' => [ $bucket => [ 'dim' => [ 'status' => $values ] ] ],
		] );
		$fb->flush();

		$after = $store->get_dimensional_buckets( 'status', [ $bucket ] )[ $bucket ] ?? [];
		$this->assertLessThanOrEqual( Stats_Store::MAX_DIM_VALUES, \count( $after ), 'still capped' );
		$this->assertGreaterThanOrEqual( 640, $after['Other'][ Stats_Store::DIM_COUNT ] ?? 0, 'the earlier overflow is still counted' );
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

		$this->set_hourly_bucket( $store, self::live_hour(), [ 'count' => 7 ] );
		$this->set_leaderboard_bucket( $store, self::live_bucket(), [ 'count' => 3, 'sum_req_time' => 1.5, 'categories' => [] ] );

		$fb->save_state();
		$p->flush();

		$frames = $this->read_mirror_frames( $p );
		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() ), $frames, 'hourly aggregate landed' );
		$this->assertSame( [ 'count' => 7 ], $frames[Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() )]['data'] );
		$this->assertSame( 86400, $frames[Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() )]['ttl'] );
		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'lb:' . self::live_bucket() ), $frames, 'leaderboard aggregate landed' );
	}

	public function test_mirror_buffers_until_save_state(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		$this->set_hourly_bucket( $store, self::live_hour(), [ 'count' => 7 ] );
		$p->flush();
		$this->assertArrayNotHasKey( Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() ), $this->read_mirror_frames( $p ), 'not flushed before save_state' );

		$fb->save_state();
		$p->flush();
		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() ), $this->read_mirror_frames( $p ), 'flushed on save_state' );
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
		$this->set_hourly_bucket( $store, self::live_hour(), [ 'count' => 7 ] );
		$this->set_leaderboard_bucket( $store, 'b', [ 'count' => 3, 'sum_req_time' => 1.5, 'categories' => [] ] );
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
			$this->set_url_stats( $store, "h{$i}", [ 'flame' => [ 'count' => $i ] ] );
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
			$this->set_url_stats( $store, "h{$i}", [ 'flame' => [ 'count' => $i ] ] );
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

	/**
	 * Every per-URL frame reaches the durable mirror, whatever its traffic rank.
	 *
	 * A rank cap here kept only the busiest hundred, so a quiet URL lived in the
	 * shared cache alone: when an install's cache scope moved under it, the
	 * site-wide series rehydrated from the mirror and that URL's history was
	 * gone, with nothing to recover it from.
	 */
	public function test_url_dim_and_url_cat_mirror_every_frame(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		// 137 distinct URLs, busiest FIRST, so a rank cap keeps the head and
		// drops the tail rather than dropping by insertion order. Counts run
		// 415, 412, 409 … 7 — distinct from every other fixture's ranks.
		// Persisted shapes, per bucket: url_dim is { dim => { val => {c,s,m} } };
		// url_cat is { category => CAT_SUMS entry }.
		for ( $i = 1; $i <= 137; $i++ ) {
			$rank = ( 137 - $i ) * 3 + 7;
			$this->set_url_dimensional_bucket( $store, "q{$i}", '1655444333', [ 'status' => [ '200' => self::dim_entry( $rank, 0, 0 ) ] ] );
			$this->set_url_category_bucket( $store, "q{$i}", '1655444333', [ 'db' => self::cat_entry( 0, 0, $rank ), 'total' => self::cat_entry( 0, 0, $rank ) ] );
		}

		$fb->save_state();
		$p->flush();

		$frames   = \array_keys( $this->read_mirror_frames( $p ) );
		$dim_keys = \array_filter( $frames, static fn ( string $k ): bool => \str_starts_with( $k, Stats_Store::entry_key( 0, 'url_dim:' ) ) );
		$cat_keys = \array_filter( $frames, static fn ( string $k ): bool => \str_starts_with( $k, Stats_Store::entry_key( 0, 'url_cat:' ) ) );
		$this->assertCount( 137, $dim_keys, 'every url_dim frame mirrored' );
		$this->assertCount( 137, $cat_keys, 'every url_cat frame mirrored' );
		$this->assertContains( Stats_Store::entry_key( 0, 'url_dim:q137:1655444333' ), $dim_keys, 'the quietest url_dim among them' );
		$this->assertContains( Stats_Store::entry_key( 0, 'url_cat:q137:1655444333' ), $cat_keys, 'the quietest url_cat among them' );
	}

	/**
	 * The three DERIVED hour tiers stay out of the mirror: each is rebuilt from
	 * fine buckets the mirror already keeps in full, so a durable copy would
	 * store the same information twice on the axis decision 11 watches.
	 */
	public function test_derived_hour_tiers_are_refused_by_the_mirror(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		/** @var CountingIndexPartition $p */
		[ , $p ] = $this->mirrored_builder( $store, 'flames-stats', CountingIndexPartition::class );

		$keys = [
			Stats_Store::NS_URLS_HOUR . ':0a1b2c3d:7:' . self::live_hour(),
			Stats_Store::NS_URLSRV_HOUR . ':' . self::live_hour(),
			Stats_Store::NS_LB_HOUR . ':' . self::live_hour(),
		];
		// Seeded ANYWAY: a reader that looked WOULD find these, so what fails
		// here is the looking, not an empty partition.
		foreach ( $keys as $key ) {
			$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, $key ), [ 'seeded' => 4931 ], 86400, \time() );
		}
		$p->flush();
		$p->index_scans = 0;

		$this->assertSame( [], ( $store->rehydrate )( $keys ), 'no derived hour tier is read back' );
		$this->assertSame( 0, $p->index_scans, 'and the futile walk never happens' );
	}

	/**
	 * The held buffer has a hard ceiling, and reaching it costs a redundant
	 * write rather than a frame: the overflow goes to the partition early
	 * instead of being dropped, so nothing the buffer stops holding is lost.
	 */
	public function test_held_frames_over_the_backstop_spill_instead_of_dropping(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new TinyHoldFlameBuilder();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		// The OPEN bucket, so nothing here flushes on its own — every frame
		// that reaches the partition got there by spilling.
		$bucket = Stats_Store::bucket_key( \time() );
		$wrote  = [];
		for ( $i = 1; $i <= 9; $i++ ) {
			$this->set_url_dimensional_bucket( $store, "s{$i}", $bucket, [ 'status' => [ '200' => self::dim_entry( $i * 11 + 3, 0, 0 ) ] ] );
			$wrote[] = Stats_Store::entry_key( 0, "url_dim:s{$i}:{$bucket}" );
		}
		$p->flush();

		$spilled = \array_keys( $this->read_mirror_frames( $p ) );
		$held    = \array_keys( $fb->save_state()['mirror']['frames'][ Stats_Store::NS_URL_DIM ] );

		$this->assertCount( 3, $held, 'the spill leaves the band below the backstop free' );
		$this->assertCount( 6, $spilled, 'the overflow was written, not dropped' );
		\sort( $wrote );
		$both = \array_merge( $spilled, $held );
		\sort( $both );
		$this->assertSame( $wrote, $both, 'every frame is either held or already durable' );
	}

	/**
	 * The backstop's scan is amortized across the writes past it, not paid on
	 * every one of them.
	 *
	 * The buffer pins at the bound under exactly the traffic the bound exists
	 * for — a crawler, or a query-string spray of unique URLs — so re-ranking
	 * the whole buffer per write makes the cost quadratic in the spray length,
	 * inside the worker whose failure mode the bound was added to prevent. One
	 * scan spills a BAND, and the next is not due until that headroom refills:
	 * sixty writes past a hundred-frame bound cost six scans, not sixty.
	 */
	public function test_the_backstop_scan_is_amortized_across_the_writes_past_it(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$p          = $this->make_partition( 'flames-stats' );

		$fb = new CountingRankFlameBuilder();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		// The OPEN bucket, so nothing drains on its own.
		$bucket = Stats_Store::bucket_key( \time() );
		CountingRankFlameBuilder::$rank_reads = 0;
		for ( $i = 1; $i <= 160; $i++ ) {
			$this->set_url_dimensional_bucket( $store, "z{$i}", $bucket, [ 'status' => [ '200' => self::dim_entry( $i * 13 + 5, 0, 0 ) ] ] );
		}

		$this->assertLessThan(
			1200,
			CountingRankFlameBuilder::$rank_reads,
			'sixty writes past the bound must not each re-rank the whole buffer'
		);
		$held = $fb->save_state()['mirror']['frames'][ Stats_Store::NS_URL_DIM ];
		$this->assertGreaterThanOrEqual( 90, \count( $held ), 'the band, and no deeper' );
		$this->assertLessThanOrEqual( 100, \count( $held ), 'the backstop bound it' );
	}

	/**
	 * With no partition to spill INTO, the backstop refuses NEW frames rather
	 * than discarding held ones — the same reading `flush_stats_mirror()` takes
	 * of a name that does not resolve, which is that it may resolve next
	 * checkpoint. A refused frame's value is still in memcache and its bucket's
	 * next write re-offers it.
	 */
	public function test_held_frames_survive_a_backstop_with_no_partition_to_spill_into(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );

		$fb = new TinyHoldFlameBuilder();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( 'no-stats-partition-here' );

		$bucket = Stats_Store::bucket_key( \time() );
		for ( $i = 1; $i <= 9; $i++ ) {
			$this->set_url_dimensional_bucket( $store, "d{$i}", $bucket, [ 'status' => [ '200' => self::dim_entry( $i * 17 + 5, 0, 0 ) ] ] );
		}

		$held = \array_keys( $fb->save_state()['mirror']['frames'][ Stats_Store::NS_URL_DIM ] );
		\sort( $held );
		$this->assertSame(
			[
				Stats_Store::entry_key( 0, "url_dim:d1:{$bucket}" ),
				Stats_Store::entry_key( 0, "url_dim:d2:{$bucket}" ),
				Stats_Store::entry_key( 0, "url_dim:d3:{$bucket}" ),
				Stats_Store::entry_key( 0, "url_dim:d4:{$bucket}" ),
			],
			$held,
			'nothing already held is discarded, and the bound still binds'
		);
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

		$this->set_url_stats( $store, 'empty', [ 'flame' => [ 'count' => 0 ] ] );
		$this->set_url_stats( $store, 'filled', [ 'flame' => [ 'count' => 3 ] ] );

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

		$this->set_hourly_bucket( $store, self::live_hour(), [ 'count' => 91 ] );
		$fb->save_state();
		$p->flush();
		// Live memcache moves on; the mirror still holds the older frame.
		$this->set_hourly_bucket( $store, self::live_hour(), [ 'count' => 17 ] );
		$p->index_scans = 0;

		$this->assertSame( [ 'count' => 17 ], $this->get_hourly_bucket( $store, self::live_hour() ), 'the live value wins' );
		$this->assertSame( 0, $p->index_scans, 'a hit never consults the mirror' );
	}

	/**
	 * The coarse tiers are buffered at a cap of ZERO, so a read of one can only
	 * ever walk the whole index and find nothing. `locate_by()` cannot stop
	 * early on a key that is absent, so each such read is a full pass — and a
	 * cold dashboard poll issues hundreds of them inside one request.
	 */
	public function test_a_coarse_tier_key_is_never_looked_for_on_the_mirror(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		/** @var CountingIndexPartition $p */
		[ , $p ] = $this->mirrored_builder( $store, 'flames-stats', CountingIndexPartition::class );

		// Seeded ANYWAY: a reader that looks WOULD find it, so what fails here
		// is the looking, not an empty partition.
		$key = 'urls_h:3:' . self::live_hour();
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, $key ), [ 'ab12cd34ef56' => [ 71 ] ], 86400, \time() );
		$p->flush();
		$p->index_scans = 0;

		$this->assertSame( [], ( $store->rehydrate )( [ $key ] ), 'a tier the mirror refuses is never read back' );
		$this->assertSame( 0, $p->index_scans, 'and the futile walk never happens' );
	}

	/**
	 * The DASHBOARD's mirror read is best-effort inside a request budget.
	 *
	 * `locate_by()` cannot early-stop on an absent key, so every batch that
	 * misses walks the whole index; a cold `urls` poll issues over three
	 * thousand such batches across sixteen shards and four partitions. The
	 * budget is what makes that bounded rather than unbounded, and zero spends
	 * it before the first read.
	 */
	public function test_a_spent_read_budget_stops_the_reader_consulting_the_mirror(): void {
		Core::$memd = new InMemoryMemcached();
		Flame_Builder_Node::reset_mirror_read_budget();
		$this->use_base_dir( $this->make_temp_dir(), [ 'stats_mirror_node' => 'flames-stats', 'stats_mirror_read_budget_ms' => 0 ] );
		$store   = new Stats_Store( partition: 0, max_lifespan: 86400 );
		/** @var CountingIndexPartition $p */
		[ , $p ] = $this->mirrored_builder( $store, 'flames-stats', CountingIndexPartition::class );
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() ), [ 'count' => 83 ], 86400, \time() );
		$p->flush();

		$reader = new Stats_Store( partition: 0, max_lifespan: 86400 );
		Flame_Builder_Node::arm_stats_reader( $reader );
		$p->index_scans = 0;

		$this->assertNull( ( $reader->rehydrate )( [ 'hourly:' . self::live_hour() ] ), 'a spent budget did not look, and says so' );
		$this->assertSame( 0, $p->index_scans, 'and walks nothing' );
	}

	/**
	 * A read the budget cut short is no absence: nothing is remembered of it,
	 * and the next poll, with budget again, finds the frame.
	 *
	 * Through the Table, not the seam alone, since it is the Table that would
	 * hold the marker — and held for the window, a frame the mirror has would
	 * then read as missing on every poll after a cold one.
	 */
	public function test_a_read_the_budget_cut_short_records_no_absence(): void {
		Core::$memd = new InMemoryMemcached();
		Flame_Builder_Node::reset_mirror_read_budget();
		$dir = $this->make_temp_dir();
		$this->use_base_dir( $dir, [ 'stats_mirror_node' => 'flames-stats', 'stats_mirror_read_budget_ms' => 0 ] );
		$store   = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ , $p ] = $this->mirrored_builder( $store, 'flames-stats', CountingIndexPartition::class );
		$bucket  = Stats_Store::bucket_key( \time() - 3 * 3600 );
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, 'lb:' . $bucket ), [ 'count' => 83, 'sum_req_time' => 1.0, 'categories' => [] ], 86400, \time() );
		$p->flush();

		$reader = new Stats_Store( partition: 0, max_lifespan: 86400 );
		Flame_Builder_Node::arm_stats_reader( $reader );
		$this->assertSame( [], $reader->get_leaderboard_buckets( [ $bucket ] ), 'out of budget, the read answers nothing' );

		Flame_Builder_Node::reset_mirror_read_budget();
		$this->use_base_dir( $dir, [ 'stats_mirror_node' => 'flames-stats', 'stats_mirror_read_budget_ms' => 2500 ] );
		$reader = new Stats_Store( partition: 0, max_lifespan: 86400 );
		Flame_Builder_Node::arm_stats_reader( $reader );
		$rows = $reader->get_leaderboard_buckets( [ $bucket ] );
		$this->assertSame( 83, $rows[ $bucket ]['count'] ?? null, 'with budget, the frame the mirror holds is found' );
	}

	/** A mirror no topology declares is looked for once, not on every miss. */
	public function test_an_undeclared_mirror_is_resolved_once(): void {
		Core::$memd = new InMemoryMemcached();
		Flame_Builder_Node::reset_mirror_read_budget();
		$this->use_base_dir( $this->make_temp_dir(), [ 'stats_mirror_node' => 'flames-nowhere', 'stats_mirror_read_budget_ms' => 2500 ] );
		$catalog_reads = self::count_catalog_reads();
		$reader = new Stats_Store( partition: 0, max_lifespan: 86400 );
		Flame_Builder_Node::arm_stats_reader( $reader );

		$this->assertNull( ( $reader->rehydrate )( [ 'hourly:' . self::live_hour() ] ), 'no mirror to look at' );
		$first = $catalog_reads();
		$this->assertNull( ( $reader->rehydrate )( [ 'lb:' . self::live_hour() ] ) );

		$this->assertGreaterThan( 0, $first, 'the mirror was looked for' );
		$this->assertSame( $first, $catalog_reads(), 'and not looked for again' );
	}

	/** Keys of a namespace the mirror never holds resolve no mirror at all. */
	public function test_unmirrored_keys_resolve_no_mirror(): void {
		Core::$memd = new InMemoryMemcached();
		Flame_Builder_Node::reset_mirror_read_budget();
		$this->use_base_dir( $this->make_temp_dir(), [ 'stats_mirror_node' => 'flames-nowhere', 'stats_mirror_read_budget_ms' => 2500 ] );
		$catalog_reads = self::count_catalog_reads();
		$reader = new Stats_Store( partition: 0, max_lifespan: 86400 );
		Flame_Builder_Node::arm_stats_reader( $reader );

		$this->assertSame( [], ( $reader->rehydrate )( [ Stats_Store::NS_LB_HOUR . ':' . self::live_hour() ] ), 'nothing the mirror could hold' );
		$this->assertSame( 0, $catalog_reads(), 'so no mirror was looked for' );
	}

	/** With budget left, the same read finds the frame — zero is the switch. */
	public function test_a_reader_inside_its_budget_still_reads_the_mirror(): void {
		Core::$memd = new InMemoryMemcached();
		Flame_Builder_Node::reset_mirror_read_budget();
		$this->use_base_dir( $this->make_temp_dir(), [ 'stats_mirror_node' => 'flames-stats', 'stats_mirror_read_budget_ms' => 2500 ] );
		$store   = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ , $p ] = $this->mirrored_builder( $store, 'flames-stats', CountingIndexPartition::class );
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() ), [ 'count' => 83 ], 86400, \time() );
		$p->flush();

		$reader = new Stats_Store( partition: 0, max_lifespan: 86400 );
		Flame_Builder_Node::arm_stats_reader( $reader );

		$found = ( $reader->rehydrate )( [ 'hourly:' . self::live_hour() ] );

		$this->assertSame( [ 'count' => 83 ], $found['hourly:' . self::live_hour()]['value'] ?? null );
	}

	/**
	 * A reader remembers the absences it walked for, so the same poll's next
	 * turn walks only for what may have landed since.
	 *
	 * A sparse server has buckets the mirror holds no frame for in every
	 * window, and an absent key is the one the walk cannot stop early on;
	 * asked on every poll they spent the whole budget before the series was
	 * reached. A closed bucket's absence holds for the window; the walk that
	 * found nothing is not repeated.
	 */
	public function test_a_reader_remembers_a_closed_buckets_absence_and_walks_once(): void {
		Core::$memd = new InMemoryMemcached();
		Flame_Builder_Node::reset_mirror_read_budget();
		$this->use_base_dir( $this->make_temp_dir(), [ 'stats_mirror_node' => 'flames-stats', 'stats_mirror_read_budget_ms' => 2500 ] );
		$store   = new Stats_Store( partition: 0, max_lifespan: 86400 );
		/** @var CountingIndexPartition $p */
		[ , $p ] = $this->mirrored_builder( $store, 'flames-stats', CountingIndexPartition::class );
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() ), [ 'count' => 83 ], 86400, \time() );
		$p->flush();
		// A bucket three hours back that the mirror never saw: sparse traffic.
		$absent = Stats_Store::bucket_key( \time() - 3 * 3600 );

		$reader = new Stats_Store( partition: 0, max_lifespan: 86400 );
		Flame_Builder_Node::arm_stats_reader( $reader );
		$p->index_scans = 0;

		$this->assertSame( [], $reader->get_leaderboard_buckets( [ $absent ], 'spoke-sparse' ) );
		$this->assertSame( 1, $p->index_scans, 'one walk to learn the absence' );
		// The mirror is appended every few seconds, which is what discards
		// the walk's own per-request memo; only what landed in memcache holds.
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() ), [ 'count' => 84 ], 86400, \time() );
		$p->flush();
		$this->assertSame( [], $reader->get_leaderboard_buckets( [ $absent ], 'spoke-sparse' ) );
		$this->assertSame( 1, $p->index_scans, 'and none to be told again' );
	}

	/** A namespace the mirror refuses earns no absence marker: nothing was walked for. */
	public function test_a_refused_namespace_records_no_absence(): void {
		Core::$memd = new InMemoryMemcached();
		Flame_Builder_Node::reset_mirror_read_budget();
		$this->use_base_dir( $this->make_temp_dir(), [ 'stats_mirror_node' => 'flames-stats', 'stats_mirror_read_budget_ms' => 2500 ] );
		$reader = new Stats_Store( partition: 0, max_lifespan: 86400 );
		Flame_Builder_Node::arm_stats_reader( $reader );
		// A closed hour three back: a bucket in this tier would be remembered.
		$hour = Stats_Store::hour_of( Stats_Store::bucket_key( \time() - 3 * 3600 ) );

		$this->assertSame( [], $reader->get_leaderboard_hours( [ $hour ] ) );

		$this->assertFalse( Core::$memd->get( self::cache_key( 0, Stats_Store::NS_LB_HOUR . ':' . $hour ) ), 'no marker for a key the mirror could never hold' );
	}

	/** An unnamed mirror leaves the reader memcache-only: there is nothing to budget. */
	public function test_an_unnamed_mirror_leaves_the_reader_unarmed(): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'stats_mirror_node' => '', 'stats_mirror_read_budget_ms' => 2500 ] );

		$reader = new Stats_Store( partition: 0, max_lifespan: 86400 );
		Flame_Builder_Node::arm_stats_reader( $reader );

		$this->assertNull( $reader->rehydrate, 'no mirror named, so no seam to wrap' );
	}

	/** Skipping the coarse tier filters the batch; it does not disarm the seam. */
	public function test_a_mirrored_key_beside_a_coarse_one_still_resolves(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ , $p ]    = $this->mirrored_builder( $store, 'flames-stats', CountingIndexPartition::class );

		$fine   = 'urls:3:{HOUR}-00';
		$coarse = 'urls_h:3:{HOUR}';
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, $fine ), [ 'ab12cd34ef56' => [ 71 ] ], 86400, \time() );
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, $coarse ), [ 'ab12cd34ef56' => [ 83 ] ], 86400, \time() );
		$p->flush();

		$found = ( $store->rehydrate )( [ $coarse, $fine ] );

		$this->assertArrayHasKey( $fine, $found, 'the mirrored tier still reads back' );
		$this->assertArrayNotHasKey( $coarse, $found, 'only the never-mirrored key is skipped' );
	}

	public function test_a_decayed_out_frame_is_not_restored(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ , $p ]    = $this->mirrored_builder( $store, 'flames-stats' );

		// A bucket a day past the window: nothing reads it, nothing restores it.
		$gone = \gmdate( 'Y-m-d-H', \time() - ( 48 * 3600 ) );
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, "hourly:{$gone}" ), [ 'count' => 53 ], 86400, \time() );
		$p->flush();

		$this->assertSame( [], $this->get_hourly_bucket( $store, $gone ), 'a bucket past retention stays gone' );
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
		$this->set_url_stats( $store, 'abc', $data );
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
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() ), [ 'count' => 29 ], 100, $now );
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() ), [ 'count' => 74 ], 100, $now );
		$p->flush();

		$this->assertSame( [ 'count' => 74 ], $this->get_hourly_bucket( $store, self::live_hour() ), 'the newest frame for a key wins' );
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

		$this->set_hourly_bucket( $store, self::live_hour(), [ 'count' => 7 ] );
		$fb->save_state();
		$p->flush();

		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() ), $this->read_mirror_frames( $p ), 'set_stats_store arms the mirror when a partition name is already set' );
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
		$this->set_hourly_bucket( $store, self::live_hour(), [ 'count' => 5 ] );
		$fb->save_state();
		$p->flush();

		$this->assertArrayHasKey( Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() ), $this->read_mirror_frames( $p ), 'forward-referenced stats partition resolved lazily at flush' );
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
		$this->set_hourly_bucket( $store, self::live_bucket(), [ 'count' => 37 ] );
		$fb->save_state();
		$p->flush();

		$key   = Stats_Store::entry_key( 0, 'hourly:' . self::live_bucket() );
		$found = null;
		$p->scan_index(
			function ( string $line, int $segment ) use ( &$found, $key, $p ): bool {
				$entry = Flame_Builder_Node::parse_stats_index( $line );
				if ( null === $entry || $entry['key_hash'] !== Log_Manager::url_hash( $key ) ) {
					return true;
				}
				$found = Message::unpacked( $p->read_at( $segment, $entry['offset'], $entry['length'] ) );
				return false;
			},
			true
		);

		$this->assertIsArray( $found, 'the index located the hourly frame' );
		$this->assertSame( $key, $found[ Message::KEY ] );
		$this->assertSame( [ 'data' => [ 'count' => 37 ], 'ttl' => 86400 ], $found[ Message::VALUE ] );
	}

	public function test_a_closed_bucket_is_not_re_mirrored_when_a_later_bucket_fills(): void {
		// A bucket is written when it changes, so a closed one is written once.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$early = 1_700_000_000;          // floors to :05
		$late  = $early + 600;           // two buckets later
		$first = Stats_Store::bucket_key( $early );

		Core::$now = $early;
		// Two checkpoints while `early` is still open: the old code wrote it at both.
		foreach ( [ 41.0, 43.0 ] as $duration_ms ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => $duration_ms, 'timestamp' => $early ] ) );
			$fb->flush();
			$fb->save_state();
		}

		Core::$now = $late;
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

		$bucket = self::live_bucket();
		$rows   = [ 'ab12cd34ef56' => [ 'url' => 'https://example.test/jobs/import', 'count' => 639 ] ];
		$this->set_url_bucket( $store, $bucket, $rows );
		// hourly stays warm: it is the sentinel the retired gate keyed on.
		$this->set_hourly_bucket( $store, $bucket, [ 'count' => 12 ] );
		$fb->save_state();
		$p->flush();

		// Evict just that row's shard, the way memcache does under pressure.
		$shard = Stats_Store::url_shard( 'ab12cd34ef56' );
		$key   = self::cache_key( 0, 'urls:' . Stats_Store::server_key( self::SEED_SERVER ) . ":{$shard}:{$bucket}" );
		Core::$memd->delete( $key );
		$this->assertFalse( Core::$memd->get( $key ), 'the shard holding it is evicted' );
		$this->assertNotSame( [], $this->get_hourly_bucket( $store, $bucket ), 'memcache still warm by the old sentinel' );

		// The row and its path: the whole URL lives on its own key in the name table.
		$this->assertSame(
			[ $bucket => [ 'ab12cd34ef56' => [ 'count' => 639, 'path' => 'https://example.test/jobs/import' ] ] ],
			$this->url_rows_by_bucket( $store, [ $bucket ] )
		);
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

		$bucket = self::live_bucket();
		$key    = self::cache_key( 0, 'urls:' . Stats_Store::server_key( self::SEED_SERVER ) . ':' . Stats_Store::url_shard( 'h' ) . ':' . $bucket );

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

		$hour    = self::live_hour();
		$buckets = [ "{$hour}-05", "{$hour}-10", "{$hour}-15" ];
		foreach ( $buckets as $i => $bucket ) {
			$this->set_url_bucket( $store, $bucket, [ "hash{$i}" => [ 'url' => "/j{$i}", 'count' => 641 + $i ] ] );
		}
		$fb->save_state();
		$p->flush();
		foreach ( $buckets as $bucket ) {
			foreach ( Stats_Store::url_shards() as $shard ) {
				Core::$memd->delete( self::cache_key( 0, 'urls:' . Stats_Store::server_key( self::SEED_SERVER ) . ":{$shard}:{$bucket}" ) );
			}
		}
		$p->index_scans = 0;

		$out = $this->url_rows_by_bucket( $store, $buckets );

		$this->assertCount( 3, $out, 'every evicted bucket recovered' );
		$this->assertSame( 641, $out[ "{$hour}-05" ]['hash0']['count'] );
		// The seed's own index reads may have built the locator table already.
		$this->assertLessThanOrEqual( 1, $p->index_scans, 'one index pass for all three misses, not one per bucket' );
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
				$this->assertSame( 0, $cell[ Stats_Store::DIM_COUNT ], 'worker excluded from global dimensional count' );
				$this->assertSame( 0, $cell[ Stats_Store::DIM_SUM_PEAK_MB ], 'worker excluded from global dimensional peak' );
			}
		}

		// Global leaderboard + categories: untouched by the worker.
		$this->assertEmpty( $this->get_leaderboard_bucket( $store, $bucket ) );
		$this->assertEmpty( $this->get_category_bucket( $store, $bucket ) );
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
		$this->assertEmpty( $this->get_leaderboard_bucket( $store, $bucket, 'srv-a' ) );

		// Hub: per-server bucket should be populated.
		$this->fill_request( $fb_hub, $req );
		$fb_hub->flush();
		$lb_s = $this->get_leaderboard_bucket( $store, $bucket, 'srv-a' );
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
		Core::$now = $open;

		foreach ( [ 61.0, 62.0, 63.0 ] as $duration_ms ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => $duration_ms, 'timestamp' => $open ] ) );
			$fb->flush();
			$fb->save_state();
		}
		$p->flush();
		$this->assertNotContains( $key, $this->raw_mirror_frame_keys( $p ), 'the open bucket stays out of the mirror' );

		// Closed: the next checkpoint writes it once, whole.
		Core::$now = $open + 300;
		$fb->save_state();
		$p->flush();

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
		Core::$now = $open;
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
		Core::$now = $open + 60;
		$successor->restore_state( $checkpoint );
		Core::$now = $open + 300;
		$successor->save_state();
		$p->flush();

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
		$this->assertSame( 2, $frames[ $url_dim ]['data']['method']['GET'][ Stats_Store::DIM_COUNT ] ?? 0, 'and so did the per-URL top-N' );
	}

	public function test_an_evicted_open_bucket_is_repaired_from_the_held_frames(): void {
		// The open bucket is in no durable log yet, so the HELD frames are the
		// only copy a read-modify-write can recover it from.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		Core::$now = $open;

		foreach ( \range( 1, 9 ) as $ignored ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 31.0, 'timestamp' => $open ] ) );
		}
		$fb->flush();
		$fb->save_state();
		$this->assertSame( 9, $this->get_hourly_bucket( $store, $bucket )['count'] ?? 0, 'nine landed' );

		// memcached evicts the open bucket under pressure.
		Core::$memd->delete( self::cache_key( 0, Stats_Store::NS_HOURLY . ':' . $bucket ) );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 32.0, 'timestamp' => $open ] ) );
		$fb->flush();

		$this->assertSame(
			10,
			$this->get_hourly_bucket( $store, $bucket )['count'] ?? 0,
			'the merge read through the held frame instead of restarting from zero'
		);
	}

	public function test_a_held_frame_whose_life_ran_out_is_not_restored(): void {
		// The carry is an UNMERGED delta, not a copy of durable data, so a spent
		// frame is dropped here where read_through() would serve it (ADR-18).
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 7200 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		Core::$now = $open;
		$this->set_hourly_bucket( $store, $bucket, [ 'count' => 47 ] );
		$checkpoint = $fb->save_state();

		$successor = new Flame_Builder_Node();
		// Resumed a day later: the frame's 2h life is long gone.
		Core::$now = $open + 86400;
		$successor->restore_state( $checkpoint );

		$this->assertSame(
			[],
			$successor->save_state()['mirror']['frames'][''] ?? [],
			'the expired frame did not come back'
		);
	}

	public function test_an_evicted_per_url_bucket_is_repaired_from_the_held_top_n(): void {
		// The per-URL namespaces are held in a different buffer; it reads too.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$open   = 1_700_000_000;
		$bucket = Stats_Store::bucket_key( $open );
		$hash   = Log_Manager::url_hash( '/post/123' );
		Core::$now = $open;

		foreach ( \range( 1, 6 ) as $ignored ) {
			$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 29.0, 'timestamp' => $open ] ) );
		}
		$fb->flush();
		$this->assertSame( 6, $this->get_url_dimensional_bucket( $store, $hash, $bucket )['method']['GET'][ Stats_Store::DIM_COUNT ] ?? 0 );

		Core::$memd->delete( self::cache_key( 0, Stats_Store::NS_URL_DIM . ':' . $hash . ':' . $bucket ) );
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 30.0, 'timestamp' => $open ] ) );
		$fb->flush();

		$this->assertSame(
			7,
			$this->get_url_dimensional_bucket( $store, $hash, $bucket )['method']['GET'][ Stats_Store::DIM_COUNT ] ?? 0,
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
		Core::$now = $open;

		$this->set_hourly_bucket( $store, $bucket, [ 'count' => 3 ] );
		$this->set_leaderboard_bucket( $store, $bucket, [ 'blob' => \str_repeat( 'x', 16900000 ) ] );

		// One namespaced space now: flatten it, the assertions are about WHICH
		// frames rode, not which namespace they sat under.
		$carried = [];
		foreach ( $fb->save_state()['mirror']['frames'] ?? [] as $frames ) {
			$carried += \is_array( $frames ) ? $frames : [];
		}

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

	public function test_a_checkpoint_in_the_old_row_shape_files_no_phantom_server(): void {
		// The old accumulator keyed rows by hash, each carrying its split at
		// index 13. Read as server => rows, the hash would become a server and
		// the split a row keyed `13`. No migration: the old delta is dropped.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$hash   = 'b3b3b3b3b3b3';
		$fb->restore_state( [
			'pending' => [
				$bucket => [
					'url_stats' => [
						$hash => [ 4, 4, 88.0, 0, 4, 0, 0, 0, 22.0, 22.0, 0, $now, false, [ 'kea.test' => [ 4, 4, 88.0 ] ] ],
					],
				],
			],
		] );
		Core::$now = $now;
		$fb->flush();

		$this->assertArrayNotHasKey( $bucket, $store->server_index( [], [ $bucket ] ), 'no server is admitted for the hash' );
		foreach ( \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) ) as $shard ) {
			$this->assertSame( [], $this->get_url_shard( $store, $bucket, $shard, $hash ), "no phantom row in {$shard}" );
		}
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
						self::SEED_SERVER => [
							$hash => self::positional_url_row(
								[ 'count' => 6, 'timed_count' => 6, 'sum_ms' => 300.0, 'path' => $url ]
							),
						],
					],
				],
			],
		] );

		$this->fill_request( $fb, $this->completed_request( [
			'url'         => $url,
			'duration_ms' => 41.0,
			'timestamp'   => $now,
		] ) );

		$rows = self::named_url_rows( $fb->save_state()['pending'][ $bucket ]['url_stats'][ self::SEED_SERVER ] ?? [] );
		$this->assertSame( 7, $rows[ $hash ]['count'] ?? 0 );
	}
	// --- Frame size: one key, rounded milliseconds, positional categories ---

	/**
	 * A frame the new writer produced and the new reader read back yields the
	 * identical category aggregate — the three size changes are only correct
	 * together, so the round trip is what pins them.
	 */
	public function test_a_mirrored_category_frame_round_trips_its_aggregate(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$bucket = self::live_bucket();
		$stored = [
			'zither render'  => [
				Stats_Store::CAT_MS       => 913.207,
				Stats_Store::CAT_CALLS    => 47,
				Stats_Store::CAT_REQUESTS => 11,
			],
			'quokka dispatch' => [
				Stats_Store::CAT_MS       => 12.049,
				Stats_Store::CAT_CALLS    => 3,
				Stats_Store::CAT_REQUESTS => 2,
			],
		];
		$this->set_category_bucket( $store, $bucket, $stored );
		$fb->save_state();
		$p->flush();

		$key   = Stats_Store::NS_CATEGORIES . ':' . $bucket;
		$found = ( $store->rehydrate )( [ $key ] );

		$this->assertSame( $stored, $found[ $key ]['value'] ?? null, 'the aggregate survives the round trip unchanged' );
	}

	/**
	 * The collision check reads the key off `Message::KEY`, so a frame the
	 * index landed on under another key's hash is still rejected.
	 */
	public function test_a_frame_reached_under_another_keys_hash_is_rejected(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$wanted     = 'hourly:' . self::live_hour();
		$collided   = Log_Manager::url_hash( Stats_Store::entry_key( 0, $wanted ) );

		$p = $this->make_partition( 'flames-stats' );
		// Every line indexed under the WANTED key's hash: a forced collision.
		$p->with_index(
			static fn ( array $message, array $position ): string => $collided
				. \str_pad( (string) $position['segment'], 6, '0', STR_PAD_LEFT )
				. \str_pad( (string) $position['offset'], 10, '0', STR_PAD_LEFT )
				. \str_pad( (string) $position['length'], 8, '0', STR_PAD_LEFT )
		);
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->set_stats_store( $store );
		$fb->set_stats_target( $p->name() );

		$other = 'hourly:' . \gmdate( 'Y-m-d-H', \time() - 7200 );
		$this->fill_partition_entry( $p, Stats_Store::entry_key( 0, $other ), [ 'count' => 61 ], 86400, \time() );
		$p->flush();

		$this->assertSame( [], ( $store->rehydrate )( [ $wanted ] ), 'another key\'s frame is not filed under this one' );
	}

	/** The frame carries the key once — `Message::KEY` — and not again inside VALUE. */
	public function test_a_mirror_frame_carries_its_key_once(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$this->set_hourly_bucket( $store, self::live_hour(), [ 'count' => 61 ] );
		$fb->save_state();
		$p->flush();

		$key = Stats_Store::entry_key( 0, 'hourly:' . self::live_hour() );
		$this->assertSame(
			[ $key ],
			\array_column( $this->raw_mirror_messages( $p ), Message::KEY ),
			'the key is the Message key'
		);
		$this->assertSame(
			[ [ 'data' => [ 'count' => 61 ], 'ttl' => 86400 ] ],
			\array_column( $this->raw_mirror_messages( $p ), Message::VALUE ),
			'and VALUE carries no second copy of it'
		);
	}

	/**
	 * A category's milliseconds are rounded where the value is stored, so a
	 * full-precision double never reaches the frame.
	 */
	public function test_category_milliseconds_are_stored_at_display_precision(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now = \time();
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 500.51303300000006,
			'timestamp'   => $now,
			'profiles'    => [
				'zither render' => [
					'time'    => 41.903852000000015,
					'count'   => 7,
					'ts'      => $now,
					'entries' => [],
				],
			],
		] ) );
		$fb->flush();

		$cats = $this->cat_series( $store )[ Stats_Store::bucket_key( $now ) ];
		$this->assertSame( 41.904, $cats['zither render'][ Stats_Store::CAT_MS ], 'the category time is rounded' );
		$this->assertSame( 500.513, $cats['total'][ Stats_Store::CAT_MS ], 'and so is the rollup' );
		$this->assertSame( 7, $cats['zither render'][ Stats_Store::CAT_CALLS ], 'a count is untouched' );
		$this->assertSame( 1, $cats['zither render'][ Stats_Store::CAT_REQUESTS ], 'and so is a sample count' );
	}

	/**
	 * A pending category slot carried over from a pre-deploy checkpoint is
	 * DISCARDED, never read.
	 *
	 * The offsetlog checkpoint is not salted, so the first respawn after a
	 * deploy really does meet the old named slot. Adding to it warns once per
	 * category per bucket, and reading it would be a dual-format reader — so
	 * the slot is replaced, losing one worker's un-flushed delta, which
	 * `restore_state()` already declares acceptable.
	 */
	public function test_a_pending_category_slot_from_a_pre_deploy_checkpoint_is_discarded(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );

		$now    = \time();
		$bucket = Stats_Store::bucket_key( $now );
		$fb->restore_state( [
			'pending' => [ $bucket => [ 'cat' => [ 'zither render' => [ 't' => 812.5, 'c' => 61, 'n' => 7 ] ] ] ],
		] );
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 40.0,
			'timestamp'   => $now,
			'profiles'    => [
				'zither render' => [ 'time' => 9.25, 'count' => 3, 'ts' => $now, 'entries' => [] ],
			],
		] ) );

		// A call count accumulates as a float and becomes whole at the store.
		$this->assertSame(
			[ Stats_Store::CAT_MS => 9.25, Stats_Store::CAT_CALLS => 3.0, Stats_Store::CAT_REQUESTS => 1 ],
			$fb->save_state()['pending'][ $bucket ]['cat']['zither render'],
			'the request lands in a fresh positional slot; the old shape is gone'
		);
	}

	/**
	 * The three changes together, measured. This fixed twenty-category frame
	 * packs to 799 bytes; the same frame carrying the key twice, at full double
	 * precision, with named category fields, packs to 1,294 — 38.2% more. The
	 * cap is what makes a change that reinstates any of the three fail here
	 * rather than quietly cost that in production.
	 */
	public function test_a_mirrored_category_frame_stays_under_its_byte_budget(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		[ $fb, $p ] = $this->mirrored_builder( $store, 'flames-stats' );

		$cats = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$cats[ "zither stage {$i}" ] = [
				Stats_Store::CAT_MS       => \round( 913.207 + ( $i / 7 ), 3 ),
				Stats_Store::CAT_CALLS    => 47 + $i,
				Stats_Store::CAT_REQUESTS => 11 + $i,
			];
		}
		$this->set_category_bucket( $store, self::live_bucket(), $cats );
		$fb->save_state();
		$p->flush();

		$bytes = \array_map(
			static fn ( array $m ): int => \strlen( Message::packed( $m ) ),
			$this->raw_mirror_messages( $p )
		);
		$this->assertCount( 1, $bytes );
		$this->assertLessThanOrEqual( 820, $bytes[0], 'twenty categories in one frame' );
	}

	public function test_a_flush_files_the_tokens_of_the_names_it_wrote(): void {
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731' ] ) );
		$fb->flush();
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-8842' ] ) );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://moa.test/wombat-5510', 'server_name' => 'moa.test' ] ) );
		$fb->flush();

		$this->assertSame(
			[ 'wombat' => [ Log_Manager::url_hash( 'https://moa.test/wombat-5510' ) ] ],
			$store->url_token_sets( [ 'wombat' ], [ 'moa.test' ] ),
			'each server files its own'
		);
		$sets = $store->url_token_sets( [ 'womb', 'wombat', '7731', '884' ], [ self::SEED_SERVER ] );
		$a    = Log_Manager::url_hash( 'https://kea.test/wombat-7731' );
		$b    = Log_Manager::url_hash( 'https://kea.test/wombat-8842' );
		$this->assertSame( [ $a, $b ], $sets['womb'], 'the second flush unions' );
		$this->assertSame( [ $a, $b ], $sets['wombat'] );
		$this->assertSame( [ $a ], $sets['7731'] );
		$this->assertSame( [ $b ], $sets['884'] );
	}

	public function test_an_all_digit_token_and_hash_round_trip_as_strings(): void {
		// PHP keys an array by INT wherever the key spells a number, so an
		// all-digit path token and the one URL in ~220 whose hash is twelve
		// digits both leave the flush as ints unless something types them back.
		Core::$memd = new InMemoryMemcached();
		$store      = new Stats_Store( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$url = 'https://kea319.test/20260922';
		$this->assertSame( '481169627974', Log_Manager::url_hash( $url ), 'the fixture is the all-digit case' );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url ] ) );
		$fb->flush();

		$this->assertSame( [ '481169627974' ], $store->url_token_sets( [ '20260922' ], [ self::SEED_SERVER ] )['20260922'] ?? null );
	}

	public function test_a_saturated_token_key_is_not_rewritten(): void {
		// A saturated set says "fold for this token"; restamping it every
		// flush would hold that key alive forever. Skipping the write lets it
		// expire on the TTL it already had, and the token rebuilds live-only.
		Core::$memd = new InMemoryMemcached();
		$store      = new RecordingStatsStore( partition: 0, max_lifespan: 86400 );
		$store->bucket_set_multi( [
			[ Stats_Store::url_token_parts( Stats_Store::server_key( self::SEED_SERVER ) ), 'wom', [ Stats_Store::TOKEN_SATURATED => \time() ] ],
		] );
		$fb = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$this->fill_request( $fb, $this->completed_request( [ 'url' => 'https://kea.test/wombat-7731' ] ) );
		$store->writes = [];
		$fb->flush();

		$this->assertNotContains( 'wom', $store->writes, 'the saturated token is read and left alone' );
		$this->assertContains( 'womb', $store->writes, 'its unsaturated siblings are still written' );
	}

	public function test_a_refused_token_write_is_logged_and_not_written_again(): void {
		// Nothing retries a refused cache write: the refusal is logged, the
		// URL stays named, and the next flush writes no token for it.
		$err = '';
		Core::set_stderr_handler( static function ( $text ) use ( &$err ) {
			$err .= $text;
		} );
		Core::$memd = new InMemoryMemcached();
		$store      = new RecordingStatsStore( partition: 0, max_lifespan: 86400 );
		$fb         = new Flame_Builder_Node();
		$fb->set_stats_store( $store );
		$url = 'https://kea.test/takahe-4410';

		$store->refuse_tokens = true;
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url ] ) );
		$fb->flush();
		$this->assertSame( [], $store->url_token_sets( [ 'takahe' ], [ self::SEED_SERVER ] ), 'the write was refused' );
		$this->assertStringContainsString( 'token index write refused', $err );

		$store->refuse_tokens = false;
		$store->writes        = [];
		$this->fill_request( $fb, $this->completed_request( [ 'url' => $url ] ) );
		$fb->flush();

		$this->assertNotContains( 'takahe', $store->writes, 'the refused token is not written again' );
	}

	public function test_the_token_namespace_is_not_mirrored(): void {
		$mirrors = new \ReflectionMethod( Flame_Builder_Node::class, 'mirrors_key' );
		$this->assertFalse( $mirrors->invoke( null, Stats_Store::NS_URLTOKEN . ':' . Stats_Store::server_key( self::SEED_SERVER ) . ':wombat' ) );
	}
}

/**
 * A Flame_Builder whose intern table fills after three names, so a test can
 * reach the freeze without pushing 50000 distinct strings through it.
 */
class TinyInternFlameBuilder extends Flame_Builder_Node {
	protected const INTERN_TABLE_LIMIT = 3;
}

/**
 * A Flame_Builder whose held-frame backstop is four, so a test can reach the
 * spill without pushing ten thousand distinct URLs through one bucket.
 */
class TinyHoldFlameBuilder extends Flame_Builder_Node {
	protected const MAX_HELD_FRAMES = 4;
}

/**
 * A Flame_Builder that counts rank reads, so a test can assert the backstop's
 * COMPLEXITY rather than its wall clock. The override delegates to the real
 * `mirror_traffic_rank()`, so what is measured is the production body running.
 */
class CountingRankFlameBuilder extends Flame_Builder_Node {
	protected const MAX_HELD_FRAMES = 100;

	/** @var int Rank reads since a test last zeroed it. */
	public static int $rank_reads = 0;

	/**
	 * @param array<array-key,mixed> $data Value being mirrored.
	 * @param string                 $ns   Namespace it belongs to.
	 */
	protected static function mirror_traffic_rank( array $data, string $ns ): int {
		++self::$rank_reads;
		return parent::mirror_traffic_rank( $data, $ns );
	}
}

/**
 * A Stats_Store that records which token buckets a flush wrote, and can
 * refuse the token writes outright.
 */
class RecordingStatsStore extends Stats_Store {
	/** @var array<int,string> Token buckets written since a test last zeroed it. */
	public array $writes = [];

	/** @var bool Whether a token write is refused. */
	public bool $refuse_tokens = false;

	/**
	 * @param array<int,array{0: array<int,string>, 1: string, 2: array<array-key,mixed>}> $writes `[ parts, bucket, data ]`.
	 * @return array<int,bool>
	 */
	public function bucket_set_multi( array $writes ): array {
		$refused = [];
		foreach ( $writes as $at => [ $parts, $bucket ] ) {
			if ( Stats_Store::NS_URLTOKEN !== ( $parts[0] ?? '' ) ) {
				continue;
			}
			$this->writes[] = $bucket;
			if ( $this->refuse_tokens ) {
				$refused[ $at ] = true;
			}
		}
		if ( [] === $refused ) {
			return parent::bucket_set_multi( $writes );
		}
		$kept = \array_diff_key( $writes, $refused );
		$out  = [] === $kept ? [] : parent::bucket_set_multi( \array_values( $kept ) );
		$done = [];
		$next = 0;
		foreach ( \array_keys( $writes ) as $at ) {
			$done[ $at ] = isset( $refused[ $at ] ) ? false : ( $out[ $next++ ] ?? false );
		}
		return $done;
	}
}

/** Counts index scans, so a test can pin the batch path to ONE pass. */
class CountingIndexPartition extends \Newspack_Nodes\Partition_Node {
	public int $index_scans = 0;

	public function scan_index( callable $cb, bool $newest_first = false ): void {
		++$this->index_scans;
		parent::scan_index( $cb, $newest_first );
	}
}

/** Moves the tick 17 seconds on every write, as a slow append would. */
class ClockAdvancingPartition extends \Newspack_Nodes\Partition_Node {
	public function fill( array $message ): void {
		Core::$now = (int) Core::$now + 17;
		parent::fill( $message );
	}
}
