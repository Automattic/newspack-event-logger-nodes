<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Idle_Reporter;
use Newspack_Nodes\Message;
use Newspack_Nodes\Shutdown_Sweeper;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

/**
 * FlameBuilder consumes completed-request JSON docs (the output shape of
 * RequestBuilder), not raw firehose lines.
 *
 * A completed request looks like:
 *   {
 *     rid, url, duration_ms, status_code, error_status, peak_mb,
 *     request_method, country_code, http_from, user_agent,
 *     ja4_hash, is_worker, timestamp,
 *     entries: [ { n, ts, k, m, l, duration_ms, peak_mb }, ... ],
 *     profiles: { state: { entries: { label: [time, count] }, count, time, ts } }
 *   }
 *
 * The flame tree is built from `entries` via LIFO matching of `^(.+?) \(start\)$`
 * / `^(.+?) \(complete\)$` patterns on `k`. StatsBuilderTest covers the rows a
 * request writes.
 */
#[CoversClass( Flame_Builder_Node::class )]
class FlameBuilderTest extends TestCase {

	protected function tearDown(): void {
		Flame_Builder_Node::$usleep_fn = null;
		parent::tearDown();
	}

	/**
	 * An armed builder whose every message lands in the capture it is
	 * returned beside.
	 *
	 * @return array{0: Flame_Builder_Node, 1: Capture_Sink_Node}
	 */
	private function capturing_builder(): array {
		$capture = new Capture_Sink_Node();
		return [ $this->armed_flame_builder( $capture ), $capture ];
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

	private function fill_request( Flame_Builder_Node $fb, array $request ): void {
		$this->fill_request_at( $fb, $request, '' );
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
		[ $fb, $flames ]           = $this->capturing_builder();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = 'not-an-array';
		$fb->connect_node( 'flames:partition' );
		$fb->fill( $message );
		$this->assertSame( [], $flames->captured );
	}

	public function test_clean_stop_on_the_flame_forward_still_tunes_the_rule_and_raises_clean(): void {
		// When the flame-doc forward triggers a cooperative stop (the partition wrote the
		// doc, then pump() signaled it), FlameBuilder must still tune the request's
		// rule — its own bookkeeping — and re-raise as CLEAN, so the Consumer commits
		// past the line instead of replaying it.
		$this->set_rule( [ 'auto_disable_threshold' => 83 ] );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );
		$fb->connect_node( 'flames:partition' ); // non-empty target so store_flame forwards.
		$fb->sink( new class extends \Newspack_Nodes\Node {
			public function fill( array $message ): void {
				throw new \Newspack_Nodes\Worker_Should_Stop();
			}
		} );

		try {
			$this->fill_request( $fb, $this->completed_request( [ 'profiles' => [ 'tauhou hook' => [ 'time' => 0.2, 'count' => 84, 'entries' => [] ] ] ] ) );
			$this->fail( 'expected a clean stop to propagate' );
		} catch ( \Newspack_Nodes\Worker_Should_Stop_Clean $e ) {
			$this->addToAssertionCount( 1 );
		}

		$this->assertSame( [ 'tauhou' ], $fb->get_auto_tune_state()['disable_hooks']['r'] ?? null, 'the rule is tuned despite the stop on the flame forward' );
	}

	public function test_non_struct_message_skipped(): void {
		[ $fb, $flames ]           = $this->capturing_builder();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_INFO;
		$message[ Message::VALUE ] = $this->completed_request();
		$fb->connect_node( 'flames:partition' );
		$fb->fill( $message );
		$this->assertSame( [], $flames->captured );
	}

	public function test_flame_tree_built_from_entries_with_lifo_matching(): void {
		$fb      = new Flame_Builder_Node();
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
	 * Records a request line always fills in, broken one field at a time.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function records_refused_at_intake(): array {
		return [
			'naming no host'     => [ [ 'url' => '/unknown-7731' ], '/unknown-7731' ],
			'carrying no method' => [ [ 'url' => 'https://kea-7713.test/tui-7731', 'request_method' => '' ], 'no method' ],
		];
	}

	/**
	 * A record whose URL names no host, or that carries no method, throws at
	 * the fold's intake, before anything of it is forwarded, and the builder
	 * folds the next.
	 *
	 * @param array<string,mixed> $overrides What breaks the record.
	 * @param string              $refusal   What the throw names.
	 */
	#[DataProvider( 'records_refused_at_intake' )]
	public function test_a_record_refused_at_intake_throws_before_anything_is_folded( array $overrides, string $refusal ): void {
		[ $fb, $flames ] = $this->capturing_builder();
		$fb->connect_node( 'flames:partition' );

		try {
			$this->fill_request_at( $fb, $this->completed_request( [ 'timestamp' => self::tick() ] + $overrides ), '7:100:41' );
			$this->fail( 'the record is refused' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( $refusal, $e->getMessage() );
		}
		$this->assertSame( [], $flames->captured, 'nothing is forwarded' );

		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-kea-7731', 'url' => 'https://kea-7713.test/kea-7731', 'duration_ms' => 29.0, 'timestamp' => self::tick() ] ), '7:200:42' );
		$this->assertSame( [ 'flames:partition' ], \array_column( $flames->captured, Message::TO ), 'the next record forwards its flame' );
		$this->assertSame( '7:200:42', $flames->captured[0][ Message::VALUE ]['request_at'] );
	}

	/** A record whose `entries` are not a list still forwards a flame, with no children. */
	public function test_a_record_whose_entries_are_not_a_list_forwards_an_empty_flame(): void {
		[ $fb, $flames ] = $this->capturing_builder();
		$fb->connect_node( 'flames:partition' );

		$this->fill_request( $fb, $this->completed_request( [ 'rid' => 'rid-titi-12', 'duration_ms' => 64.0, 'entries' => 'not-a-list' ] ) );

		$this->assertSame( [], $flames->captured[0][ Message::VALUE ]['children'] );
		$this->assertEqualsWithDelta( 64.0, $flames->captured[0][ Message::VALUE ]['value'], 1e-9 );
	}

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

	public function test_finalize_strips_internal_fields(): void {
		$node = [
			'name'      => 'root',
			'sum_value' => 5.0,
			'seen_count' => 1,
			'count'     => 1,
			'children'  => [],
		];
		Flame_Tree::finalize_flame_node( $node, 1 );
		$this->assertArrayNotHasKey( 'sum_value', $node );
		$this->assertArrayNotHasKey( 'seen_count', $node );
	}

	/** Truly idle: no auto-tune queued, so a tick sends nothing. */
	public function test_a_tick_with_nothing_owed_sends_nothing(): void {
		[ $fb, $capture ] = $this->capturing_builder();

		$fb->fire_cb();

		$this->assertSame( [], $capture->captured );
	}

	/**
	 * Decisions a sibling's lock held back outlive the records that made them:
	 * the next tick applies them though it folded nothing.
	 */
	public function test_a_tick_with_nothing_folded_applies_the_auto_tune_a_held_lock_left(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		$lock       = self::scoped( 'evlog:auto_disable_lock' );
		$mc->add( $lock, 'sibling-4433', 300 );
		[ $fb, $capture ] = $this->capturing_builder();
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [ 'gannet hook' => [ 'time' => 0.1, 'count' => 211, 'entries' => [] ] ],
		] ) );
		$this->tick_router( 0 );
		$this->assertSame( [ 'gannet' ], $fb->get_auto_tune_state()['disable_hooks']['r'] ?? null, 'the lock held it back' );
		$mc->delete( $lock );

		$this->tick_router( Flame_Builder_Node::FLUSH_INTERVAL_SEC );

		$fired = \array_values( \array_filter( $capture->captured, static fn ( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' ) ) );
		$this->assertCount( 1, $fired, 'the next tick applied what was queued' );
		$this->assertSame( [ 'gannet' ], $fired[0][ Message::VALUE ]['items'] );
	}

	/** Round-trip: what `arguments()` was given is what it reads back. */
	public function test_arguments_read_back_what_they_were_given(): void {
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		$fb->arguments( [ 'petrel-4434', 'skua-4435' ] );

		$this->assertSame( [ 'petrel-4434', 'skua-4435' ], $fb->arguments() );
	}

	/** The flush rides the Router tick at its own cadence. */
	public function test_arguments_arm_the_flush_on_the_tick_router(): void {
		$router = new \Newspack_Nodes\Router_Node();
		$router->name( \Newspack_Nodes\Node_Names::ROUTER );
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		$fb->arguments( [] );

		$this->assertSame( 'router', $fb->timer_mode() );
		$this->assertSame( Flame_Builder_Node::FLUSH_INTERVAL_SEC * 1000, $fb->interval_ms );
	}

	/** A noisy category the governing rule logs as a custom event is disabled as one; any other is a hook. */
	public function test_the_rules_custom_events_dispatch_to_disable_custom_events(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 50, 'custom_events' => [ 'mything', 'other' ] ] );
		$fb = new Flame_Builder_Node();

		$req = $this->completed_request( [
			'profiles' => [
				// "mything" is one of the rule's custom events: disable_custom_events.
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

	/**
	 * A platform request tunes a rule written for one of the platform's
	 * per-request paths, however the pattern spells it, and a job context a
	 * rule at `/jobs` or below.
	 *
	 * @return array<string,array{0:string,1:string}> Rule pattern, then the request's URL.
	 */
	public static function platform_rules(): array {
		return [
			'the command route'      => [ '/wp-json/newspack-nodes/v1/command?', 'https://example.com/wp-json/newspack-nodes/v1/command' ],
			'cron, by its query'     => [ '/wp-cron.php?doing_wp_cron', 'https://example.com/wp-cron.php?doing_wp_cron=1792407316' ],
			'the command route, cased' => [ '/WP-JSON/Newspack-Nodes/v1/Command?', 'https://example.com/wp-json/newspack-nodes/v1/command' ],
			'a job, under /jobs'       => [ '/jobs', 'https://example.com/jobs/kiwi-7316/42' ],
			'a job, under its handler' => [ '/jobs/kiwi-7316', 'https://example.com/jobs/kiwi-7316/42' ],
			'a spawned worker'         => [ '/wp-json/newspack-nodes/v1/workers/spawn?', 'https://example.com/wp-json/newspack-nodes/v1/workers/spawn' ],
			'a stream'                 => [ '/wp-json/newspack-nodes/v1/messages/stream?', 'https://example.com/wp-json/newspack-nodes/v1/messages/stream' ],
		];
	}

	#[DataProvider( 'platform_rules' )]
	public function test_a_platform_request_tunes_a_rule_over_platform_paths( string $pattern, string $url ): void {
		$this->set_rule( [ 'pattern' => $pattern, 'auto_disable_threshold' => 37, 'auto_protect_time_threshold' => 0.05 ] );
		$fb = new Flame_Builder_Node();

		$this->fill_request( $fb, $this->completed_request( [
			'url'       => $url,
			'is_worker' => true,
			'profiles'  => [
				'noisy_7316 hook' => [ 'time' => 0.1, 'count' => 38, 'entries' => [] ],
				'slow_7316 hook'  => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] ) );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'noisy_7316' ], $state['disable_hooks']['r'] );
		$this->assertSame( [ 'slow_7316' ], $state['add_significant_events']['r'] );
	}

	/**
	 * A platform request tunes nothing under the catch-all, whatever its
	 * path: that rule governs every visitor's page too.
	 *
	 * @return array<string,array{0:string,1:string}> Rule pattern, then the request's URL.
	 */
	public static function untuned_rules(): array {
		return [
			'the catch-all'         => [ '/', 'https://example.com/wp-json/newspack-nodes/v1/workers/spawn' ],
			'a job, under the catch-all' => [ '/', 'https://example.com/jobs/kiwi-7316/42' ],
		];
	}

	#[DataProvider( 'untuned_rules' )]
	public function test_a_platform_request_leaves_the_catch_all_alone( string $pattern, string $url ): void {
		$this->set_rule( [ 'pattern' => $pattern, 'auto_disable_threshold' => 37, 'auto_protect_time_threshold' => 0.05 ] );
		$fb = new Flame_Builder_Node();

		$this->fill_request( $fb, $this->completed_request( [
			'url'       => $url,
			'is_worker' => true,
			'profiles'  => [
				'noisy_7316 hook' => [ 'time' => 0.1, 'count' => 38, 'entries' => [] ],
				'slow_7316 hook'  => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] ) );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [], $state['disable_hooks'] );
		$this->assertSame( [], $state['add_significant_events'] );
	}

	/** A category's mean per call belongs to its rule: another rule's slow calls promote nothing here. */
	public function test_the_significance_mean_is_kept_per_rule(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] = [
			[ 'id' => 'quick', 'pattern' => '/quick', 'action' => 'log', 'auto_protect_time_threshold' => 50.0 ],
			[ 'id' => 'slow', 'pattern' => '/slow', 'action' => 'log' ],
		];
		$fb = new Flame_Builder_Node();

		$this->fill_request( $fb, $this->completed_request( [
			'url'      => 'https://example.com/slow',
			'rule_id'  => 'slow',
			'profiles' => [ 'shared_7316 hook' => [ 'time' => 900.0, 'count' => 1, 'entries' => [] ] ],
		] ) );
		$this->fill_request( $fb, $this->completed_request( [
			'url'      => 'https://example.com/quick',
			'rule_id'  => 'quick',
			'profiles' => [ 'shared_7316 hook' => [ 'time' => 1.0, 'count' => 1, 'entries' => [] ] ],
		] ) );

		$this->assertSame( [], $fb->get_auto_tune_state()['add_significant_events'] );
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

	/** Only a one-token slug is a plugin load; a custom event ending in "plugin" belongs to the application. */
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

	/** The candidate is the application's own name, whatever it is called. */
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
		$this->set_rule( [ 'auto_disable_threshold' => 50, 'custom_events' => [ 'render page' ] ] );
		$fb = new Flame_Builder_Node();

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

	public function test_get_stats_returns_payload(): void {
		// A low per-rule time threshold promotes both seeded hooks so
		// significant_events_count (summed across the per-rule cache) is 2.
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb      = new Flame_Builder_Node();
		$capture = new Capture_Sink_Node();
		$fb->name( 'fb' );
		$fb->sink( $capture );

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
		$this->assertSame( 2, $payload['data']['auto_tune_pending_count'] );
		$this->assertSame( 2, $payload['data']['significant_events_count'] );
	}

	public function test_an_unknown_request_verb_is_refused_on_the_error_plane(): void {
		$fb                        = new Flame_Builder_Node();
		$capture                   = new Capture_Sink_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::FROM ]  = 'caller';
		$message[ Message::ID ]    = 'req-2';
		$message[ Message::VALUE ] = 'NONSENSE_VERB';
		$fb->name( 'fb' );
		$fb->sink( $capture );
		$fb->fill( $message );
		$reply = $capture->captured[0];
		$this->assertSame( Message::TM_ERROR, $reply[ Message::TYPE ] );
		$this->assertSame( "unknown request verb: NONSENSE_VERB\n", $reply[ Message::VALUE ] );
		$this->assertSame( 'caller', $reply[ Message::TO ] );
		$this->assertSame( 'req-2', $reply[ Message::ID ] );
	}

	/** Any message carrying TM_REQUEST is answered once and never folded as data. */
	public function test_a_request_is_answered_and_never_folded_whatever_else_it_flags(): void {
		$fb                        = new Flame_Builder_Node();
		$capture                   = new Capture_Sink_Node();
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST | Message::TM_RESPONSE;
		$message[ Message::VALUE ] = 'GET_STATS';
		$fb->sink( $capture );
		$fb->fill( $message );
		$this->assertCount( 1, $capture->captured );
		$this->assertSame( Message::TM_STRUCT | Message::TM_RESPONSE, $capture->captured[0][ Message::TYPE ], 'answered, not folded' );
	}

	public function test_apply_auto_tune_emits_messages_via_sink(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		[ $fb, $capture ] = $this->capturing_builder();

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
	 * An emit the auto-tuner refuses raises from the flush and keeps its
	 * decision: a kind's queue clears only after every rule under it
	 * emitted, so the next flush sends the same decision again.
	 */
	public function test_a_refused_emit_keeps_its_decision_for_the_next_flush(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 57 ] );
		$tuner = new class() extends \Newspack_Nodes\Node {
			public int $refusals = 1;

			/** @var list<array<int,mixed>> */
			public array $emitted = [];

			public function fill( array $message ): void {
				if ( $this->refusals-- > 0 ) {
					throw new \RuntimeException( 'auto-tuner refused the kestrel rewrite' );
				}
				$this->emitted[] = $message;
			}
		};
		$fb    = $this->armed_flame_builder( $tuner );
		$this->fill_request( $fb, $this->completed_request( [ 'profiles' => [ 'kestrel hook' => [ 'time' => 3.0, 'count' => 58, 'entries' => [] ] ] ] ) );

		$thrown = null;
		try {
			$fb->flush();
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		}
		$fb->flush();

		$this->assertSame( 'auto-tuner refused the kestrel rewrite', $thrown?->getMessage() );
		$this->assertSame(
			[ [ 'disable_hooks', [ 'rule_id' => 'r', 'items' => [ 'kestrel' ] ] ] ],
			\array_map( static fn ( array $m ): array => [ $m[ Message::KEY ], $m[ Message::VALUE ] ], $tuner->emitted )
		);
		$this->assertSame( [], $fb->get_auto_tune_state()['disable_hooks'] );
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
		[ $fb, $capture ] = $this->capturing_builder();
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
		[ $fb, $capture ] = $this->capturing_builder();
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

	/**
	 * The stop's wait is a duration inside one process, so it runs on the
	 * monotonic clock: a lock never released is given up after the wait's
	 * 5 s of monotonic time, 50 polls of 100 ms, whatever the wall reads.
	 */
	public function test_a_stop_gives_up_a_held_lock_after_its_monotonic_wait(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		[ $fb, $capture ] = $this->capturing_builder();
		$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'spam hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ],
			],
		] ) );
		$mc->add( self::scoped( 'evlog:auto_disable_lock' ), 'other-worker', 300 );
		$mono                          = 8_100_000_000_000;
		Flame_Builder_Node::$hrtime_fn              = static function () use ( &$mono ): int {
			return $mono;
		};
		$polls                         = 0;
		Flame_Builder_Node::$usleep_fn = static function ( int $us ) use ( &$mono, &$polls ): void {
			++$polls;
			$mono += $us * 1000;
		};

		$fb->shutdown_sweep();

		$this->assertSame( 50, $polls, 'the wait ends on the monotonic deadline' );
		$fired = \array_filter( $capture->captured, static fn( $m ) => 'disable_hooks' === ( $m[ Message::KEY ] ?? '' ) );
		$this->assertSame( [], $fired, 'the held lock kept the decisions back' );
	}

	public function test_apply_auto_tune_takes_and_releases_the_memcache_lock(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		[ $fb, $capture ] = $this->capturing_builder();
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

	/**
	 * With no memcached handle, the lock still holds through APCu.
	 */
	public function test_the_auto_tune_lock_holds_on_an_apcu_only_host(): void {
		Core::$memd                                 = null;
		\Newspack_Nodes\Cache_Backend::$apcu_usable = static fn (): bool => true;
		$lock                                       = self::scoped( 'evlog:auto_disable_lock' );
		\apcu_store( $lock, 'held-by-p3', 60 );
		try {
			[ $fb, $capture ] = $this->capturing_builder();
			$this->set_rule( [ 'auto_disable_threshold' => 100 ] );
			$this->fill_request( $fb, $this->completed_request( [
				'profiles' => [ 'spammy hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
			] ) );
			$fb->flush();
			$this->assertSame( [ 'spammy' ], $fb->get_auto_tune_state()['disable_hooks']['r'], 'an APCu-held lock defers the emit' );
			\apcu_delete( $lock );
			$fb->flush();
			$this->assertSame( [], $fb->get_auto_tune_state()['disable_hooks'] );
			$this->assertFalse( \apcu_exists( $lock ), 'the flush released its own lock' );
		} finally {
			\apcu_delete( $lock );
			\Newspack_Nodes\Cache_Backend::$apcu_usable = static fn (): bool => false;
		}
	}

	public function test_apply_auto_tune_skipped_when_lock_held(): void {
		$mc         = new InMemoryMemcached();
		Core::$memd = $mc;
		// Pre-occupy the lock as if a sibling worker holds it.
		$mc->add( self::scoped( 'evlog:auto_disable_lock' ), 'someone-else', 60 );
		[ $fb, $capture ] = $this->capturing_builder();
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
		[ $fb, $capture ] = $this->capturing_builder();

		// No auto-tune state set, just a basic flush.
		$fb->flush();

		$this->assertSame( [], $mc->keys(), 'no lock taken' );
		// No auto-tune emits (only the flush has nothing to emit).
		foreach ( $capture->captured as $m ) {
			$this->assertNotContains( $m[ Message::KEY ], [ 'disable_hooks', 'disable_custom_events', 'add_significant_events' ] );
		}
	}

	/** The catalog's `reply_shape` names every key `GET_STATS` answers with. */
	public function test_get_stats_reply_shape_names_every_key_it_carries(): void {
		$fb   = new Flame_Builder_Node();
		$verb = \array_column( Flame_Builder_Node::node_schema()['requests'], null, 'name' )['GET_STATS'];
		\preg_match_all( '/\w+/', $verb['reply_shape'], $named );

		$this->assertSame( \array_keys( $this->get_stats( $fb ) ), $named[0] );
	}

	public function test_label_and_message_attached_to_flame_nodes(): void {
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
		$this->assertSame( 'SELECT * FROM wp_users WHERE id = 1', $child['message'] );
	}

	public function test_label_equal_to_message_skips_message_field(): void {
		// If label and message are identical, message shouldn't be added.
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
		$this->assertArrayNotHasKey( 'message', $child, 'message omitted when equal to label' );
	}

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

	public function test_disable_uses_the_stamped_rules_threshold(): void {
		// The governing rule's threshold — resolved by the request's stamped
		// rule_id — decides, and the proposal is keyed under that rule id.
		$this->set_rule( [ 'id' => 'loud', 'pattern' => '/loud/', 'auto_disable_threshold' => 100 ] );
		$fb  = new Flame_Builder_Node();
		$req = $this->completed_request( [
			'rule_id'  => 'loud',
			'url'      => 'https://example.com/loud/x',
			'profiles' => [ 'noisy_hook hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'noisy_hook' ], $state['disable_hooks']['loud'] );
	}

	/** A stamp naming no rule, and none at all. */
	public static function unresolved_stamps(): array {
		return [
			'no stamp'       => [ '' ],
			'a deleted rule' => [ 'ghost-9271' ],
		];
	}

	/** With no stamped rule the URL decides, and rules match the path, not the absolute URL. */
	#[DataProvider( 'unresolved_stamps' )]
	public function test_an_unstamped_request_matches_its_rule_by_path( string $stamp ): void {
		$this->set_rule( [ 'id' => 'zephyr', 'pattern' => '/zephyr-9271/', 'auto_disable_threshold' => 100 ] );
		$fb  = new Flame_Builder_Node();
		$req = $this->completed_request( [
			'rule_id'  => $stamp,
			'url'      => 'https://kea-7713.test/zephyr-9271/x',
			'profiles' => [ 'noisy_hook hook' => [ 'time' => 0.1, 'count' => 200, 'entries' => [] ] ],
		] );
		$this->fill_request( $fb, $req );
		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ 'noisy_hook' ], $state['disable_hooks']['zephyr'] ?? null );
	}

	public function test_no_stamped_rule_applies_no_auto_tune(): void {
		// Stale stamped id + a url that matches no rule ⇒ null rule ⇒ no tuning.
		$this->set_rule( [ 'id' => 'quiet', 'pattern' => '/q/' ] );
		$fb  = new Flame_Builder_Node();
		$req = $this->completed_request( [
			'rule_id'  => 'ghost',
			'url'      => 'https://example.com/unmatched',
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

	/**
	 * A verb's frame and the plugin's own spans are logged whatever the rule
	 * says, so auto-tune neither promotes nor disables them; a transport span,
	 * which `significant_events` gives meaning, still tunes beside them.
	 */
	public function test_command_and_platform_spans_are_skipped_from_auto_tune(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 61, 'auto_protect_time_threshold' => 0.05 ] );
		$fb    = new Flame_Builder_Node();
		$heavy = [ 'time' => 16.7, 'count' => 73, 'entries' => [] ];

		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				'Performance_CI dump_url command' => $heavy,
				Flame_Tree::PREFETCH_ANSWER      => $heavy,
				Flame_Tree::SNAPSHOT_INDEX       => $heavy,
				Flame_Tree::STATS_SWEEP          => $heavy,
				Flame_Tree::SQL_STATE            => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] ) );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ Flame_Tree::SQL_STATE ], $state['add_significant_events']['r'] );
		$this->assertArrayNotHasKey( 'r', $state['disable_hooks'] );
		$this->assertArrayNotHasKey( 'r', $state['disable_custom_events'] );
	}

	/**
	 * A Query Monitor timer rides every logged request whatever the rule
	 * names, so auto-tune never promotes or disables `qm` in a rule.
	 */
	public function test_query_monitor_spans_are_skipped_from_auto_tune(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 61, 'auto_protect_time_threshold' => 0.05 ] );
		$fb = new Flame_Builder_Node();

		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				Flame_Tree::QM_STATE  => [ 'time' => 16.7, 'count' => 73, 'entries' => [ 'kea-import' => [ 16.7, 73 ] ] ],
				Flame_Tree::SQL_STATE => [ 'time' => 0.4, 'count' => 2, 'entries' => [] ],
			],
		] ) );

		$state = $fb->get_auto_tune_state();
		$this->assertSame( [ Flame_Tree::SQL_STATE ], $state['add_significant_events']['r'] );
		$this->assertArrayNotHasKey( 'r', $state['disable_hooks'] );
		$this->assertArrayNotHasKey( 'r', $state['disable_custom_events'] );
	}

	/** Auto-tune judges every category, the ones the cap leaves unwritten included. */
	public function test_auto_tune_judges_a_category_the_cap_cuts(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.05 ] );
		$fb       = new Flame_Builder_Node();
		$profiles = [];
		for ( $i = 0; $i < 139; $i++ ) {
			$profiles[ \sprintf( 'busy-%03d', $i ) ] = [ 'time' => 70.0 + $i, 'count' => 5000, 'entries' => [] ];
		}
		$profiles['ruru_slow_render'] = [ 'time' => 61.5, 'count' => 1, 'entries' => [] ];

		$this->fill_request( $fb, $this->completed_request( [ 'profiles' => $profiles ] ) );

		$this->assertSame( [ 'ruru_slow_render' ], $fb->get_auto_tune_state()['add_significant_events']['r'] );
	}

	/** One predicate names every span whose time already counts in its parent's. */
	public function test_the_overlay_spans_are_listeners_and_query_monitor_timers(): void {
		$this->assertTrue( Flame_Tree::is_overlay_span( Flame_Tree::listener_name( 'kea_cb', 10 ) ) );
		$this->assertTrue( Flame_Tree::is_overlay_span( 'qm: kea-import' ) );
		$this->assertFalse( Flame_Tree::is_overlay_span( 'kea_7746 hook' ) );
		$this->assertFalse( Flame_Tree::is_overlay_span( 'qmx' ) );
	}

	/** No hook list holds a transport, so a noisy one is never disabled. */
	public function test_a_noisy_transport_is_never_disabled(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 61 ] );
		$fb = new Flame_Builder_Node();

		$this->fill_request( $fb, $this->completed_request( [
			'profiles' => [
				Flame_Tree::SQL_STATE  => [ 'time' => 3.1, 'count' => 452, 'entries' => [] ],
				Flame_Tree::HTTP_STATE => [ 'time' => 2.6, 'count' => 97, 'entries' => [] ],
				'kakapo_render hook'   => [ 'time' => 1.3, 'count' => 88, 'entries' => [] ],
			],
		] ) );

		$this->assertSame( [ 'r' => [ 'kakapo_render' ] ], $fb->get_auto_tune_state()['disable_hooks'] );
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

	/** The flame names its record's place, where the stats builder reads it. */
	public function test_the_forwarded_flame_names_its_records_place(): void {
		[ $fb, $flames ] = $this->capturing_builder();
		$fb->connect_node( 'flames:partition' );

		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-tuturuatu-5' ] ), '12:48213:3907' );

		$this->assertSame( '12:48213:3907', $flames->captured[0][ Message::VALUE ]['request_at'] );
		$this->assertSame( 'rid-tuturuatu-5', $flames->captured[0][ Message::VALUE ]['rid'] );
	}

	/**
	 * Auto-tune averages a category over at most `AUTO_TUNE_WINDOW`, 1,000
	 * folds: across 2,500 folds no tick separates, the sums start again
	 * after the 1,000th and the 2,000th, and only there. Each probe's slow
	 * call follows a fold of nine fast ones, so a reset one fold early or
	 * late averages it under the threshold; a slow call halfway through the
	 * second window averages under it too.
	 */
	public function test_the_significance_window_starts_again_every_thousand_folds(): void {
		$this->set_rule( [ 'auto_protect_time_threshold' => 0.2 ] );
		$fb       = new Flame_Builder_Node();
		$limit    = 1000;
		$proposed = [];
		for ( $fold = 1; $fold <= 2500; $fold++ ) {
			$probe = $fold <= $limit + 1 ? 'kokako_first' : 'kokako_second';
			$call  = match ( $fold ) {
				$limit, 2 * $limit               => [ 'time' => 0.1, 'count' => 9 ],
				$limit + 1, 1500, 2 * $limit + 1 => [ 'time' => 0.5, 'count' => 1 ],
				default                          => [ 'time' => 0.1, 'count' => 1 ],
			};
			$this->fill_request_at( $fb, $this->completed_request( [ 'profiles' => [ $probe => $call + [ 'entries' => [] ] ] ] ), "2:{$fold}:61" );
			$proposed[ $fold ] = $fb->get_auto_tune_state()['add_significant_events']['r'] ?? [];
		}

		$this->assertSame( [], $proposed[ $limit ] );
		$this->assertSame( [ 'kokako_first' ], $proposed[ $limit + 1 ] );
		$this->assertSame( [ 'kokako_first' ], $proposed[2000], 'the slow call at 1,500 averages over the 499 before it' );
		$this->assertSame( [ 'kokako_first', 'kokako_second' ], $proposed[2500] );
	}

	/**
	 * The significant-event signal is the average per call of a category
	 * over every fold that tunes its rule since the last flush: one slow
	 * call among many fast ones proposes nothing until a flush starts the
	 * window again, and a worker's call under a visitors' rule doesn't count
	 * toward it.
	 */
	public function test_a_significant_event_is_judged_over_the_folds_since_the_last_flush(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] = [ [ 'id' => 'r-takahe', 'pattern' => '/', 'action' => 'log', 'auto_protect_time_threshold' => 0.2 ] ];
		$fb   = new Flame_Builder_Node();
		$fill = fn ( array $sql, bool $worker = false ) => $this->fill_request_at( $fb, $this->completed_request( [ 'rule_id' => 'r-takahe', 'is_worker' => $worker, 'profiles' => [ 'sql' => $sql + [ 'entries' => [] ] ] ] ), '5:880:61' );

		$fill( [ 'time' => 0.1, 'count' => 20 ] );
		$fill( [ 'time' => 90.0, 'count' => 1 ], true );
		$fill( [ 'time' => 0.5, 'count' => 1 ] );
		$diluted = $fb->get_auto_tune_state()['add_significant_events'];
		$fb->flush();
		$fill( [ 'time' => 0.5, 'count' => 1 ] );

		$this->assertSame( [], $diluted, '0.6 ms over 21 calls averages under 0.2; the worker is not counted' );
		$this->assertSame( [ 'r-takahe' => [ 'sql' ] ], $fb->get_auto_tune_state()['add_significant_events'], 'a flush starts the window again' );
	}

	/** `GET_STATS` answers the auto-tune queue; the stats builder answers the rows. */
	public function test_get_stats_answers_the_auto_tune_queue_alone(): void {
		$this->set_rule( [ 'auto_disable_threshold' => 100, 'auto_protect_time_threshold' => 0.05 ] );
		$fb = new Flame_Builder_Node();
		$this->fill_request_at(
			$fb,
			$this->completed_request(
				[
					'profiles' => [
						'karearea hook' => [ 'time' => 0.1, 'count' => 211, 'entries' => [] ],
						'kaka_slow'     => [ 'time' => 1.5, 'count' => 3, 'entries' => [] ],
					],
				]
			),
			'8:411:52'
		);

		$this->assertSame( [ 'auto_tune_pending_count' => 2, 'significant_events_count' => 1 ], $this->get_stats( $fb ) );
	}

	/** The builder names no Row_Table: it declares no `:config` verb at all. */
	public function test_the_schema_declares_no_config_verb(): void {
		$schema = Flame_Builder_Node::node_schema();
		$this->assertSame( 'Transform', $schema['category'] );
		$this->assertSame( [], $schema['commands'] ?? [] );
	}

	/** The builder holds nothing durable: a checkpoint and an idle scan pass it by. */
	public function test_the_builder_holds_nothing_a_checkpoint_or_an_idle_scan_reads(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb' );

		$this->assertInstanceOf( Shutdown_Sweeper::class, $fb );
		$this->assertNotInstanceOf( Idle_Reporter::class, $fb );
		$this->assertFalse( \method_exists( $fb, 'save_state' ) );
		$this->assertFalse( \method_exists( $fb, 'idle_since' ) );
		$this->assertNull( Core::node( 'fb:config' ) );
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

	public function test_parse_flame_index_refuses_a_line_one_byte_long(): void {
		$message                   = Message::new_message();
		$message[ Message::VALUE ] = [ 'rid' => 'r9k2', 'url_hash' => 'a1b2c3d4e5f6' ];
		$line = Flame_Builder_Node::format_index_entry( $message, [ 'segment' => 3, 'offset' => 4096, 'length' => 812 ] );

		$this->assertSame( 'r9k2', Flame_Builder_Node::parse_flame_index( $line )['rid'] );
		$this->assertNull( Flame_Builder_Node::parse_flame_index( $line . '5' ) );
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
}
