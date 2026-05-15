<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\FlameBuilder;
use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\CaptureSink;
use PHPUnit\Framework\Attributes\CoversClass;

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
#[CoversClass( FlameBuilder::class )]
class FlameBuilderTest extends TestCase {

	/**
	 * Build a completed-request payload for FlameBuilder. Defaults are
	 * production-shaped; tests override only the fields they assert on.
	 *
	 * Timestamp defaults to current time so the FlameBuilder's bucket-key
	 * derivation aligns with the test's `$store->bucket_key_for(\time())`
	 * assertion (otherwise the bucket gets pruned by the retention cutoff).
	 */
	private function completed_request( array $overrides = [] ): array {
		$base = [
			'rid'            => 'r' . \uniqid(),
			'url'            => '/post/123',
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

	private function fill_request( FlameBuilder $fb, array $request ): void {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::VALUE ]     = $request;
		$fb->fill( $msg );
	}

	public function test_constructor_initializes_empty(): void {
		$fb = new FlameBuilder();
		$this->assertSame( 0, $fb->stats_count() );
	}

	public function test_target_includes_flames_partition_and_auto_tuner(): void {
		// FlameBuilder's flame-write path uses the standard
		// target/sink pair like any other Node connection (set via
		// `connect_node flame-builder flames:partition`). The
		// owned auto-tuner sibling is patron-linked, so the GUI
		// hides it via dump_metadata's filter — no extra edge
		// surfaces from target().
		$fb = new FlameBuilder();
		$fb->name( 'fb' );
		$fb->connect_node( 'flames:partition' );

		$this->assertSame( 'flames:partition', $fb->target() );
	}

	public function test_flame_builder_owns_auto_tuner_sibling(): void {
		$fb = new FlameBuilder();
		$fb->name( 'fb' );

		// Auto-tuner registered under {patron}:auto-tuner with patron link.
		$at = \Newspack_Nodes\Core::node( 'fb:auto-tuner' );
		$this->assertInstanceOf( \Newspack_Event_Logger_Nodes\AutoTuner::class, $at );
		$this->assertSame( $fb, $at->patron() );
	}

	public function test_flame_builder_remove_node_cascades_auto_tuner(): void {
		$fb = new FlameBuilder();
		$fb->name( 'fb' );
		$this->assertNotNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
		$fb->remove_node();
		$this->assertNull( \Newspack_Nodes\Core::node( 'fb:auto-tuner' ) );
	}

	public function test_non_array_value_skipped(): void {
		$fb                    = new FlameBuilder();
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = 'not-an-array';
		$fb->fill( $msg );
		$this->assertSame( 0, $fb->stats_count() );
	}

	public function test_non_bytestream_message_skipped(): void {
		$fb                    = new FlameBuilder();
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_INFO;
		$msg[ Message::VALUE ] = $this->completed_request();
		$fb->fill( $msg );
		$this->assertSame( 0, $fb->stats_count() );
	}

	// --- Flame tree construction ------------------------------------------

	public function test_flame_tree_built_from_entries_with_lifo_matching(): void {
		$fb     = new FlameBuilder();
		$capture = new CaptureSink();
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
		$fb      = new FlameBuilder();
		$capture = new CaptureSink();
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
		$fb      = new FlameBuilder();
		$capture = new CaptureSink();
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

	// --- Per-URL aggregate (sums-not-means) -------------------------------

	public function test_per_url_aggregate_sums_durations_across_requests(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
		$fb->set_stats_store( $store );

		$req1 = $this->completed_request( [ 'url' => '/x', 'duration_ms' => 100.0 ] );
		$req2 = $this->completed_request( [ 'url' => '/x', 'duration_ms' => 200.0 ] );
		$this->fill_request( $fb, $req1 );
		$this->fill_request( $fb, $req2 );

		// Force flush.
		$fb->flush();

		$url_hash = RequestBuilder::url_hash( '/x' );
		$stats    = $store->get_url_stats( $url_hash );
		$this->assertNotNull( $stats );
		// flame_raw retains sums; flame is finalized for display.
		$this->assertEqualsWithDelta( 300.0, $stats['flame_raw']['sum_value'], 1e-6 );
		$this->assertSame( 2, $stats['flame_raw']['count'] );
		// Display: 300/2 = 150
		$this->assertEqualsWithDelta( 150.0, $stats['flame']['value'], 1e-6 );
	}

	public function test_flush_persists_hourly_to_memcache(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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
		$bucket = $store->bucket_key_for( $now );
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
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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

	public function test_workers_excluded_from_timing(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 100.0, 'is_worker' => true ] ) );
		$fb->flush();

		$hourly = $store->get_hourly();
		foreach ( $hourly as $bucket => $stats ) {
			$this->assertSame( 0, $stats['count'], 'workers excluded from timing count' );
		}
	}

	public function test_per_server_tracking_only_when_hub(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );

		$fb_spoke = new FlameBuilder();
		$fb_spoke->set_stats_store( $store );
		$fb_spoke->set_is_hub( false );

		$fb_hub = new FlameBuilder();
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
		$bucket = $store->bucket_key_for( $now );
		$this->assertEmpty( $store->get_server_leaderboard_bucket( 'srv-a', $bucket ) );

		// Hub: per-server bucket should be populated.
		$this->fill_request( $fb_hub, $req );
		$fb_hub->flush();
		$lb_s = $store->get_server_leaderboard_bucket( 'srv-a', $bucket );
		$this->assertSame( 1, $lb_s['count'] ?? 0 );
	}

	// --- Auto-tune --------------------------------------------------------

	public function test_noisy_hook_detection_threshold_zero_disables_check(): void {
		// With threshold 0, no hook ever gets proposed.
		$fb = new FlameBuilder();
		$fb->set_auto_tune( 0, 0.0 );

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
		$fb = new FlameBuilder();
		$fb->set_auto_tune( 100, 0.0 );

		$req = $this->completed_request( [
			'profiles' => [
				// "wpdb" with count > 100 → base name "wpdb" proposed for disable.
				'wpdb hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'wpdb' ], $state['hooks'] );
	}

	public function test_callback_categories_skipped_from_auto_tune(): void {
		$fb = new FlameBuilder();
		$fb->set_auto_tune( 100, 0.0 );

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
		$fb = new FlameBuilder();
		$fb->set_auto_tune( 0, 0.05 ); // 50ms threshold

		// avg_per_call = sum_time / sum_count = 0.4/2 = 0.2 ≥ 0.05 → significant.
		$req = $this->completed_request( [
			'profiles' => [
				'slow_hook' => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'slow_hook' ], $state['new_significant'] );
	}

	// --- Index format -----------------------------------------------------

	public function test_format_and_parse_flame_index_round_trip(): void {
		// $line is the packed Message wire format (positional JSON); VALUE at index 6.
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::VALUE ]     = [ 'rid' => 'abc', 'url_hash' => 'deadbeef0001' ];
		$line     = Message::packed( $msg );
		$position = [ 'segment_id' => 5, 'offset' => 1024, 'length' => 100 ];
		$entry    = FlameBuilder::format_index_entry( $line, $position );
		$this->assertNotNull( $entry );
		$this->assertSame( 68, \strlen( $entry ) );

		$parsed = FlameBuilder::parse_flame_index( $entry );
		$this->assertSame( 'abc', $parsed['rid'] );
		$this->assertSame( 'deadbeef0001', $parsed['url_hash'] );
		$this->assertSame( 5, $parsed['segment_id'] );
		$this->assertSame( 1024, $parsed['offset'] );
		$this->assertSame( 100, $parsed['length'] );
	}

	public function test_format_index_entry_returns_null_when_rid_missing(): void {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::VALUE ]     = [ 'url_hash' => 'abc' ];
		$line     = Message::packed( $msg );
		$position = [ 'segment_id' => 0, 'offset' => 0, 'length' => 0 ];
		$this->assertNull( FlameBuilder::format_index_entry( $line, $position ) );
	}

	public function test_parse_flame_index_returns_null_for_short_lines(): void {
		$this->assertNull( FlameBuilder::parse_flame_index( 'too-short' ) );
	}

	// --- save/restore state -----------------------------------------------

	public function test_save_and_restore_pending_state_round_trip(): void {
		$fb = new FlameBuilder();
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 50.0,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );

		$saved = $fb->save_state();
		$this->assertArrayHasKey( 'pending_bucket', $saved );
		$this->assertArrayHasKey( 'pending', $saved );

		$fb2 = new FlameBuilder();
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
		FlameBuilder::finalize_flame_node( $node, 1 );
		$this->assertEqualsWithDelta( 6.0, $node['value'], 1e-6 );
	}

	public function test_flush_without_stats_store_does_not_throw(): void {
		// In test mode (no store), flush() still drains state but writes nowhere.
		$fb = new FlameBuilder();
		$this->fill_request( $fb, $this->completed_request() );
		$fb->flush(); // must not throw
		$this->assertSame( 0, $fb->stats_count() );
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
		FlameBuilder::finalize_flame_node( $node, 1 );
		$this->assertArrayNotHasKey( 'sum_value', $node );
		$this->assertArrayNotHasKey( 'seen_count', $node );
		$this->assertArrayNotHasKey( 'ts', $node );
	}

	// ── A3: sibling-CI + verbs ─────────────────────────────────

	public function test_flame_builder_constructs_sibling_ci(): void {
		$fb = new FlameBuilder();
		$fb->name( 'fb' );
		$this->assertNotNull( $fb->interpreter() );
		$this->assertSame( 'fb:config', $fb->interpreter()->name() );
	}

	public function test_flame_builder_set_is_hub_verb_round_trips(): void {
		$fb = new FlameBuilder();
		$fb->name( 'fb' );
		$this->assertSame( 'ok', $fb->interpreter()->execute( 'set_is_hub true' ) );
		$dump = $fb->dump_config();
		$this->assertStringContainsString( 'cmd fb:config set_is_hub true', $dump );
	}

	public function test_flame_builder_set_auto_tune_verb_round_trips(): void {
		$fb = new FlameBuilder();
		$fb->name( 'fb' );
		$this->assertSame( 'ok', $fb->interpreter()->execute( 'set_auto_tune 100 0.5' ) );
		$dump = $fb->dump_config();
		$this->assertStringContainsString( 'cmd fb:config set_auto_tune 100 0.5', $dump );
	}

	public function test_flame_builder_set_significant_events_verb_round_trips(): void {
		$fb = new FlameBuilder();
		$fb->name( 'fb' );
		$this->assertSame( 'ok', $fb->interpreter()->execute( 'set_significant_events init,wp_loaded,shutdown' ) );
		$dump = $fb->dump_config();
		$this->assertStringContainsString( 'cmd fb:config set_significant_events init,wp_loaded,shutdown', $dump );
	}

	public function test_flame_builder_node_schema_declares_verbs(): void {
		$schema = FlameBuilder::node_schema();
		$this->assertSame( 'Transform', $schema['category'] );
		$verb_names = \array_column( $schema['verbs'], 'name' );
		$this->assertContains( 'set_is_hub', $verb_names );
		$this->assertContains( 'set_auto_tune', $verb_names );
		$this->assertContains( 'set_significant_events', $verb_names );
		$this->assertContains( 'configure_stats', $verb_names );
	}

	// --- Clock seam / maintenance / non-stats setters ---------------------

	public function test_set_clock_drives_bucket_key_derivation(): void {
		// Use a fixed clock so we can pin the bucket key without timing flake.
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
		$fb->set_stats_store( $store );

		$fixed = 1_700_000_000; // Stable; floor to 5-min bucket.
		$fb->set_clock( static fn() => $fixed );

		$req = $this->completed_request( [
			'duration_ms' => 25.0,
			'timestamp'   => $fixed,
		] );
		$this->fill_request( $fb, $req );
		$fb->flush();

		$bucket = $store->bucket_key_for( $fixed );
		$hourly = $store->get_hourly();
		$this->assertArrayHasKey( $bucket, $hourly );
		$this->assertSame( 1, $hourly[ $bucket ]['count'] );

		// Restoring the clock to null returns to wall-clock time.
		$fb->set_clock( null );
	}

	public function test_maintenance_triggers_flush_when_interval_elapsed(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
		$fb->set_stats_store( $store );

		// Fill first, then backdate last_flush_time AFTER (fill() itself can
		// trigger a flush if last_flush_time is already old). This way the
		// flush we observe comes only from the maintenance() call.
		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 12.0 ] ) );

		$ref = new \ReflectionProperty( FlameBuilder::class, 'last_flush_time' );
		$ref->setAccessible( true );
		$ref->setValue( $fb, \microtime( true ) - ( FlameBuilder::FLUSH_INTERVAL_SEC + 1 ) );

		// Confirm hourly is NOT yet persisted (last fill happened mid-window).
		$hourly_before = $store->get_hourly();

		$fb->maintenance();

		// After maintenance: hourly persisted to store.
		$hourly_after = $store->get_hourly();
		$this->assertNotEquals( $hourly_before, $hourly_after, 'maintenance flushed pending bucket' );
		$this->assertNotEmpty( $hourly_after );
	}

	public function test_maintenance_skipped_when_interval_not_elapsed(): void {
		$fb = new FlameBuilder();
		// last_flush_time is "now" from the constructor.
		// maintenance() should be a no-op — no exception, no error.
		$fb->maintenance();
		$this->assertSame( 0, $fb->stats_count() );
	}

	public function test_set_custom_event_names_dispatches_to_custom_events(): void {
		$fb = new FlameBuilder();
		$fb->set_auto_tune( 50, 0.0 );
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
		$this->assertSame( [ 'mything' ], $state['custom_events'] );
		$this->assertSame( [ 'wp_init' ], $state['hooks'] );
	}

	public function test_set_significant_events_suppresses_redundant_proposals(): void {
		// Significant events should still appear in `significant_events` set;
		// once present in $significant_events, repeated detection is suppressed.
		$fb = new FlameBuilder();
		$fb->set_auto_tune( 0, 0.05 );
		$fb->set_significant_events( [ 'wpdb' ] );

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
		$fb = new FlameBuilder();
		$fb->restore_state( [ 'pending_bucket' => 12345 ] );
		$saved = $fb->save_state();
		// Default is empty string.
		$this->assertSame( '', $saved['pending_bucket'] );
	}

	public function test_restore_state_ignores_non_array_pending(): void {
		$fb = new FlameBuilder();
		$fb->restore_state( [ 'pending' => 'not-an-array' ] );
		$saved = $fb->save_state();
		// Pending keeps its initialized shape.
		$this->assertIsArray( $saved['pending'] );
		$this->assertArrayHasKey( 'hourly', $saved['pending'] );
	}

	public function test_restore_state_merges_pending_array(): void {
		$fb = new FlameBuilder();
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
		$fb      = new FlameBuilder();
		$capture = new CaptureSink();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_is_hub( true );
		$fb->set_significant_events( [ 'init', 'wp_loaded' ] );

		// Seed some state.
		$this->fill_request( $fb, $this->completed_request( [
			'duration_ms' => 30.0,
			'profiles'    => [ 'wpdb' => [ 'time' => 0.1, 'count' => 1, 'entries' => [] ] ],
		] ) );

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_REQUEST;
		$msg[ Message::FROM ]      = 'caller';
		$msg[ Message::ID ]        = 'req-1';
		$msg[ Message::KEY ]       = 'k-1';
		$msg[ Message::VALUE ]     = 'GET_STATS';
		$fb->fill( $msg );

		$reply = null;
		foreach ( $capture->captured as $captured ) {
			$type = $captured[ Message::TYPE ];
			if ( ( $type & Message::TM_REQUEST ) && ( $type & Message::TM_RESPONSE ) ) {
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

	public function test_handle_request_unknown_verb_returns_error(): void {
		$fb      = new FlameBuilder();
		$capture = new CaptureSink();
		$fb->name( 'fb' );
		$fb->sink( $capture );

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_REQUEST;
		$msg[ Message::FROM ]      = 'caller';
		$msg[ Message::ID ]        = 'req-2';
		$msg[ Message::VALUE ]     = 'NONSENSE_VERB';
		$fb->fill( $msg );

		$reply = $capture->captured[0];
		$this->assertStringContainsString( 'unknown request verb', $reply[ Message::VALUE ]['data']['error'] );
		$this->assertSame( 'NONSENSE_VERB', $reply[ Message::VALUE ]['verb'] );
	}

	public function test_response_messages_dont_trigger_handle_request(): void {
		// TM_REQUEST | TM_RESPONSE should skip handle_request (it's a reply, not a request).
		$fb                    = new FlameBuilder();
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_REQUEST | Message::TM_RESPONSE;
		$msg[ Message::VALUE ] = 'GET_STATS';
		$fb->fill( $msg );
		$this->assertSame( 0, $fb->stats_count(), 'response not processed as request' );
	}

	// --- Auto-tune fire actions + memcache lock ---------------------------

	public function test_apply_auto_tune_emits_messages_via_sink(): void {
		$fb      = new FlameBuilder();
		$capture = new CaptureSink();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_auto_tune( 100, 0.0 );

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
		$this->assertArrayHasKey( 'context', $first[ Message::VALUE ] );
	}

	public function test_apply_auto_tune_with_store_uses_memcache_lock(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );

		$fb      = new FlameBuilder();
		$capture = new CaptureSink();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );
		$fb->set_auto_tune( 100, 0.0 );

		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'spam hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] ) );
		$fb->flush();

		// Lock should have been added and released (no leftover entry under that key).
		$this->assertNotContains( 'evlog:auto_disable_lock', $mc->keys() );

		// And the emit fired through to sink.
		$auto_tune_msgs = \array_filter(
			$capture->captured,
			static fn( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' )
		);
		$this->assertNotEmpty( $auto_tune_msgs );
	}

	public function test_apply_auto_tune_skipped_when_lock_held(): void {
		$mc = new FakeMemcached();
		// Pre-occupy the lock as if a sibling worker holds it.
		$mc->add( 'evlog:auto_disable_lock', 'someone-else', 60 );
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );

		$fb      = new FlameBuilder();
		$capture = new CaptureSink();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_stats_store( $store );
		$fb->set_auto_tune( 100, 0.0 );

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
		$this->assertSame( [ 'spammy' ], $state['hooks'] );
	}

	public function test_apply_auto_tune_no_op_when_queues_empty(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );

		$fb      = new FlameBuilder();
		$capture = new CaptureSink();
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
		$fb = new FlameBuilder();
		$fb->name( 'fb' );
		// No sink attached.
		$fb->set_auto_tune( 100, 0.0 );
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
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 3600 );
		$fb    = new FlameBuilder();
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
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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

		$bucket    = $store->bucket_key_for( $now );
		$index     = $store->get_url_index_hourly( $bucket );
		$url_hash  = RequestBuilder::url_hash( '/p50' );
		$this->assertArrayHasKey( $url_hash, $index );
		$stats     = $index[ $url_hash ];
		$this->assertGreaterThan( 0, $stats['p50_ms'] );
		$this->assertGreaterThanOrEqual( $stats['p50_ms'], $stats['p95_ms'] );
		$this->assertGreaterThanOrEqual( $stats['p95_ms'], $stats['p99_ms'] );
		$this->assertGreaterThan( 0, $stats['avg_ms'] );
	}

	public function test_url_index_caps_at_500_keeps_top_by_count(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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

		$bucket = $store->bucket_key_for( $now );
		$index  = $store->get_url_index_hourly( $bucket );
		$this->assertLessThanOrEqual( 500, \count( $index ) );
	}

	// --- merge_and_cap_dimensional Other rollover -------------------------

	public function test_dim_other_rollover_when_too_many_values(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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
		$bucket = $store->bucket_key_for( $now );
		$this->assertArrayHasKey( $bucket, $dim );
		$this->assertLessThanOrEqual( Stats_Store::MAX_DIM_VALUES, \count( $dim[ $bucket ] ) );
		$this->assertArrayHasKey( 'Other', $dim[ $bucket ], 'low-frequency entries roll into Other' );
		$this->assertGreaterThan( 0, $dim[ $bucket ]['Other']['c'] );
	}

	public function test_url_dim_other_rollover_uses_tighter_cap(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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

		$url_hash = RequestBuilder::url_hash( '/shared' );
		$url_dim  = $store->get_url_dimensional( $url_hash );
		$this->assertArrayHasKey( 'ua', $url_dim );
		$bucket = $store->bucket_key_for( $now );
		$this->assertArrayHasKey( $bucket, $url_dim['ua'] );
		$this->assertLessThanOrEqual( Stats_Store::MAX_URL_DIM_VALUES, \count( $url_dim['ua'][ $bucket ] ) );
	}

	// --- merge_and_cap_categories: Other rollover + total preserved -------

	public function test_categories_other_rollover_preserves_total(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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
		$bucket = $store->bucket_key_for( $now );
		$this->assertArrayHasKey( $bucket, $cats );
		$this->assertLessThanOrEqual( Stats_Store::MAX_CAT_VALUES, \count( $cats[ $bucket ] ) );
		$this->assertArrayHasKey( 'total', $cats[ $bucket ], '"total" pseudo-category preserved' );
		$this->assertArrayHasKey( 'Other', $cats[ $bucket ], 'overflow rolls into Other' );
	}

	public function test_categories_expiration_drops_old_buckets(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 3600 );
		$fb    = new FlameBuilder();
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
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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

		$bucket = $store->bucket_key_for( $now );
		$lb_s   = $store->get_server_leaderboard_bucket( 'srv-cap', $bucket );
		$this->assertArrayHasKey( 'wpdb', $lb_s['categories'] );
		$this->assertLessThanOrEqual(
			FlameBuilder::ENTRY_LIMIT_GLOBAL_UPPER,
			\count( $lb_s['categories']['wpdb']['entries'] )
		);
	}

	public function test_hub_mode_per_server_categories_tracked(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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

		$bucket    = $store->bucket_key_for( $now );
		$srv_cats  = $store->get_server_categories( 'srv-cat' );
		$this->assertArrayHasKey( $bucket, $srv_cats );
		$this->assertArrayHasKey( 'wpdb', $srv_cats[ $bucket ] );
		$this->assertArrayHasKey( 'total', $srv_cats[ $bucket ], 'per-server "total" present' );
		$this->assertEqualsWithDelta( 0.4, $srv_cats[ $bucket ]['wpdb']['t'], 1e-6 );
	}

	public function test_hub_mode_per_server_dim_skips_server_dim(): void {
		// In hub mode, per-server tracking should be populated for non-server dimensions
		// (status, method, country, etc.) but NOT for the 'server' dimension (redundant).
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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
		$mc       = new FakeMemcached();
		$store    = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$url      = '/legacy';
		$url_hash = RequestBuilder::url_hash( $url );
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

		$fb = new FlameBuilder();
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
		$mc       = new FakeMemcached();
		$store    = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$url      = '/promoted';
		$url_hash = RequestBuilder::url_hash( $url );
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

		$fb = new FlameBuilder();
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

	// --- Bucket rotation across multiple buckets --------------------------

	// --- Stack depth safety + edge cases of build_flame_data --------------

	public function test_label_and_detail_attached_to_flame_nodes(): void {
		$fb      = new FlameBuilder();
		$capture = new CaptureSink();
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
		$fb      = new FlameBuilder();
		$capture = new CaptureSink();
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
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 22.0 ] ) );
		$fb->flush();

		// Hourly was still populated → aggregation occurred even without flame write.
		$this->assertNotEmpty( $store->get_hourly() );
	}

	// --- Plugin-suffix and callback-suffix exclusions ---------------------

	public function test_plugin_suffix_categories_skipped_from_auto_tune(): void {
		// Categories ending with " plugin" are not eligible for auto-tune.
		$fb = new FlameBuilder();
		$fb->set_auto_tune( 100, 0.05 );

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
		FlameBuilder::finalize_flame_node( $node, 0 );
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
		FlameBuilder::finalize_flame_node( $node, 1 );
		// Children sum = 5 + 0 = 5; parent had 2 → bumped to 5.
		$this->assertEqualsWithDelta( 5.0, $node['value'], 1e-6 );
	}

	// --- format/parse index edge cases ------------------------------------

	public function test_format_index_entry_handles_pre_decoded_data(): void {
		// The signature accepts pre-decoded $data ref; even if null is passed, function works.
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = [ 'rid' => 'predec', 'url_hash' => 'hashpredec01' ];
		$line                  = Message::packed( $msg );
		$position              = [ 'segment_id' => 1, 'offset' => 0, 'length' => 50 ];
		$pre                   = null;
		$entry                 = FlameBuilder::format_index_entry( $line, $position, $pre );
		$this->assertNotNull( $entry );
		$this->assertSame( 68, \strlen( $entry ) );
	}

	public function test_format_index_entry_truncates_long_rid_and_hash(): void {
		// Long rid + hash should be truncated to 32 and 12 bytes respectively.
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = [
			'rid'      => \str_repeat( 'a', 50 ),
			'url_hash' => \str_repeat( 'b', 30 ),
		];
		$line     = Message::packed( $msg );
		$position = [ 'segment_id' => 0, 'offset' => 0, 'length' => 0 ];
		$entry    = FlameBuilder::format_index_entry( $line, $position );
		$this->assertNotNull( $entry );
		$parsed   = FlameBuilder::parse_flame_index( $entry );
		$this->assertSame( 32, \strlen( $parsed['rid'] ) );
		$this->assertSame( 12, \strlen( $parsed['url_hash'] ) );
	}

	public function test_format_index_entry_rejects_non_array_value(): void {
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = 'just-a-string';
		$line                  = Message::packed( $msg );
		$position              = [ 'segment_id' => 0, 'offset' => 0, 'length' => 0 ];
		$this->assertNull( FlameBuilder::format_index_entry( $line, $position ) );
	}

	// --- handle_request payload includes auto-tune queue depth ------------

	public function test_handle_request_auto_tune_count_reflects_queue(): void {
		$fb      = new FlameBuilder();
		$capture = new CaptureSink();
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->set_auto_tune( 100, 0.05 );

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

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_REQUEST;
		$msg[ Message::FROM ]      = 'caller';
		$msg[ Message::VALUE ]     = 'GET_STATS';
		$fb->fill( $msg );

		// Find the reply.
		$reply = null;
		foreach ( $capture->captured as $m ) {
			$t = $m[ Message::TYPE ];
			if ( ( $t & Message::TM_REQUEST ) && ( $t & Message::TM_RESPONSE ) ) {
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
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
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

		$bucket = $store->bucket_key_for( $now );
		$this->assertEmpty( $store->get_server_leaderboard_bucket( '', $bucket ) );
	}

	// --- Save state after multiple flushes (idempotency) ------------------

	public function test_double_flush_is_idempotent(): void {
		$mc    = new FakeMemcached();
		$store = new Stats_Store( $mc, partition: 0, max_lifespan: 86400 );
		$fb    = new FlameBuilder();
		$fb->set_stats_store( $store );

		$this->fill_request( $fb, $this->completed_request( [ 'duration_ms' => 100.0 ] ) );
		$fb->flush();
		$snap_a = $store->get_hourly();
		// Second flush with nothing pending — should be a no-op for stats.
		$fb->flush();
		$snap_b = $store->get_hourly();
		$this->assertSame( $snap_a, $snap_b, 'second flush does not double-count' );
	}
}
