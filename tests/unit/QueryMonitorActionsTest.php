<?php
/**
 * Tests for App\Query_Monitor_Actions: Query Monitor's timing and logging
 * actions, as entries on the logged request.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Query_Monitor_Actions;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[CoversClass( Query_Monitor_Actions::class )]
class QueryMonitorActionsTest extends TestCase {

	/** @var array<string,mixed> $_SERVER as the test found it. */
	private array $server = [];

	protected function setUp(): void {
		parent::setUp();
		$this->server = $_SERVER;
	}

	protected function tearDown(): void {
		Log_Manager::reset();
		$_SERVER = $this->server;
		unset( $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] );
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		Config::reset();
		parent::tearDown();
	}

	/**
	 * Fire one level's listener, as `do_action( "qm/{$level}", … )` would.
	 *
	 * @param Query_Monitor_Actions $qm      The listener set.
	 * @param string                $level   The level.
	 * @param mixed                 $message The message.
	 * @param array<mixed>          $context The context.
	 */
	private function say( Query_Monitor_Actions $qm, string $level, mixed $message, array $context = [] ): void {
		foreach ( $qm->listeners() as [ $hook, $callback ] ) {
			if ( "qm/{$level}" === $hook ) {
				$callback( $message, $context );
				return;
			}
		}
		$this->fail( "no listener for qm/{$level}" );
	}

	/**
	 * A timer stopped while another is running is written inside it, at the
	 * outer's stop, so the two come out nested in the record.
	 */
	public function test_nested_timers_are_written_nested(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		$qm->start( 'kea-outer' );
		$qm->start( 'kea-inner' );
		$qm->lap( 'kea-inner', 'halfway' );
		$qm->stop( 'kea-inner' );
		$qm->stop( 'kea-outer' );

		$rows = \array_map(
			static fn ( array $e ): array => [ $e['k'], $e['l'] ?? '' ],
			self::entries_of( $this->logged_entries(), 'qm (start)', 'qm lap', 'qm (complete)' )
		);
		$this->assertSame(
			[ [ 'qm (start)', 'kea-outer' ], [ 'qm (start)', 'kea-inner' ], [ 'qm lap', 'kea-inner' ], [ 'qm (complete)', 'kea-inner' ], [ 'qm (complete)', 'kea-outer' ] ],
			$rows
		);
	}

	/**
	 * A timer started before a hook and stopped inside it is written inside
	 * the hook, clamped to the hook's start: no child opens before its
	 * parent, and the close says how much ran before.
	 */
	public function test_a_timer_straddling_a_hook_is_clamped_to_it(): void {
		$lm = $this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		\Newspack_Nodes\Core::$clock = static fn (): float => 1_800_000_100.25;
		$qm->start( 'kaka-7746' );
		\Newspack_Nodes\Core::$clock = static fn (): float => 1_800_000_100.75;
		$lm->start( 'kea_7746 hook' );
		$qm->stop( 'kaka-7746' );
		$lm->complete( 'kea_7746 hook' );

		$entries = $this->logged_entries();
		$start   = self::entries_of( $entries, 'qm (start)' )[0];
		$close   = self::entries_of( $entries, 'qm (complete)' )[0];
		$this->assertSame( 1_800_000_100.75, $start['ts'] );
		$this->assertSame( [ 'kea_7746 hook (start)', 'qm (start)', 'qm (complete)', 'kea_7746 hook (complete)' ], \array_column( self::entries_of( $entries, 'kea_7746 hook (start)', 'qm (start)', 'qm (complete)', 'kea_7746 hook (complete)' ), 'k' ) );
		$this->assertMatchesRegularExpression( '/^ran \d+\.\d{3}ms, \d+\.\d{3}ms of it before its enclosing span$/', $close['m'] ?? '' );
		$this->assertLessThanOrEqual( (float) self::entries_of( $entries, 'kea_7746 hook (complete)' )[0]['duration_ms'], (float) $close['duration_ms'] );
	}

	/**
	 * A timer named by digits is a numeric array key once held, and its
	 * name must still be the label its span, its lap and its warning carry.
	 */
	public function test_a_timer_named_by_digits_keeps_its_name(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		$qm->start( '4815' );
		$qm->start( 'kea-inner' );
		$qm->stop( 'kea-inner' );
		$qm->start( '162' );
		$qm->finish();

		$entries = $this->logged_entries();
		$this->assertSame( [ '4815', '162' ], \array_column( self::entries_of( $entries, 'qm (warning)' ), 'l' ) );
		$this->assertSame( [ 'kea-inner' ], \array_column( self::entries_of( $entries, 'qm (start)' ), 'l' ) );
	}

	/** A timer left open drops, but the timers stopped inside it still write. */
	public function test_a_dropped_timer_still_writes_the_timers_stopped_inside_it(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		$qm->start( 'kea-outer' );
		$qm->start( 'kea-inner' );
		$qm->stop( 'kea-inner' );
		$qm->finish();

		$entries = $this->logged_entries();
		$this->assertSame( [ 'kea-inner' ], \array_column( self::entries_of( $entries, 'qm (start)' ), 'l' ) );
		$this->assertSame( [ 'kea-outer' ], \array_column( self::entries_of( $entries, 'qm (warning)' ), 'l' ) );
	}

	/** A self-referencing context or message shapes to `?` past the cap. */
	public function test_a_self_referencing_message_is_shaped_without_a_fatal(): void {
		$this->start_logged_request();
		$qm         = new Query_Monitor_Actions();
		$loop       = new \stdClass();
		$loop->self = $loop;

		$this->say( $qm, 'debug', $loop );

		$m = (string) ( self::entries_of( $this->logged_entries(), 'qm (debug)' )[0]['m'] ?? '' );
		$this->assertStringStartsWith( '{"self":{"self":', $m );
		$this->assertStringEndsWith( '"?"' . \str_repeat( '}', \Newspack_Event_Logger_Nodes\App\Core::MAX_DEPTH ), $m );
	}

	/** A driver exception logged through QM keeps its code, none of its values. */
	public function test_a_driver_exception_is_logged_without_its_values(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		$this->say( $qm, 'error', new \PDOException( "SQLSTATE[23000]: 1062 Duplicate entry 'weka-7746' for key 'k'", 23000 ) );

		$this->assertSame( [ 'PDOException: [23000] SQLSTATE[23000]: 1062 Duplicate entry' ], \array_column( self::entries_of( $this->logged_entries(), 'qm (error)' ), 'm' ) );
	}

	/**
	 * One listener per action QM's collectors bind, at QM's own arity: one
	 * argument for `qm/start` and `qm/stop`, two for `qm/lap` and each level.
	 */
	public function test_it_listens_on_every_timing_and_level_action(): void {
		$listeners = ( new Query_Monitor_Actions() )->listeners();
		$arity     = [];
		foreach ( $listeners as [ $hook, , , $args ] ) {
			$arity[ $hook ] = $args;
		}

		$this->assertSame(
			[
				'qm/start'     => 1,
				'qm/stop'      => 1,
				'qm/lap'       => 2,
				'qm/emergency' => 2,
				'qm/alert'     => 2,
				'qm/critical'  => 2,
				'qm/error'     => 2,
				'qm/warning'   => 2,
				'qm/notice'    => 2,
				'qm/info'      => 2,
				'qm/debug'     => 2,
				'shutdown'     => 0,
			],
			$arity
		);
	}

	/**
	 * A stopped timer is one `qm` span named after it, its laps inside, the
	 * start back-dated to when the timer started: the pair is written at the
	 * stop, so it nests in whatever is open then and never straddles a hook.
	 */
	public function test_a_stopped_timer_is_one_span_with_its_laps_inside(): void {
		$this->start_logged_request();
		\Newspack_Nodes\Core::$clock = static fn (): float => 1_800_000_100.25;
		$qm = new Query_Monitor_Actions();

		$qm->start( 'kea-import' );
		\Newspack_Nodes\Core::$clock = static fn (): float => 1_800_000_100.5;
		$qm->lap( 'kea-import', 'fetched' );
		$qm->lap( 'kea-import' );
		$qm->stop( 'kea-import' );

		$entries = self::entries_of( $this->logged_entries(), 'qm (start)', 'qm lap', 'qm (complete)' );
		$this->assertSame( [ 'qm (start)', 'qm lap', 'qm lap', 'qm (complete)' ], \array_column( $entries, 'k' ) );
		$this->assertSame( 'kea-import', $entries[0]['l'] );
		$this->assertSame( 1_800_000_100.25, $entries[0]['ts'] );
		$this->assertSame( [ 'fetched', '' ], [ $entries[1]['m'], $entries[2]['m'] ] );
		$this->assertSame( 1_800_000_100.5, $entries[1]['ts'] );
		$this->assertIsFloat( $entries[3]['duration_ms'] );
		$this->assertSame( Query_Monitor_Actions::STATE, Flame_Tree::base_name( 'qm (start)' ) );
	}

	/** A stop QM never saw start is QM's own warning, and opens no span. */
	public function test_a_stop_without_a_start_warns_and_writes_no_span(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		$qm->stop( 'weka-7746' );
		$qm->lap( 'weka-7746', 'nope' );

		$entries = $this->logged_entries();
		$this->assertSame( [], self::entries_of( $entries, 'qm (start)', 'qm (complete)', 'qm lap' ) );
		$warnings = self::entries_of( $entries, 'qm (warning)' );
		$this->assertSame( [ 'Timer not started', 'Timer not started' ], \array_column( $warnings, 'm' ) );
		$this->assertSame( 'weka-7746', $warnings[0]['l'] );
	}

	/**
	 * A timer still open at shutdown is DROPPED, as QM drops it, with QM's
	 * warning: nothing was written at its start, so no span is left open.
	 */
	public function test_a_timer_open_at_shutdown_is_dropped_with_a_warning(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		$qm->start( 'kaka-7746' );
		$qm->finish();
		$qm->finish();

		$entries = $this->logged_entries();
		$this->assertSame( [], self::entries_of( $entries, 'qm (start)', 'qm (complete)' ) );
		$this->assertSame( [ [ 'Timer not stopped', 'kaka-7746' ] ], \array_map( static fn ( array $e ): array => [ $e['m'], $e['l'] ], self::entries_of( $entries, 'qm (warning)' ) ) );
	}

	/** A rebind forgets the old scope's timers; their stop writes nothing. */
	public function test_reset_forgets_open_timers(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		$qm->start( 'tui-7746' );
		$qm->reset();
		$qm->finish();

		$this->assertSame( [], self::entries_of( $this->logged_entries(), 'qm (start)', 'qm (warning)' ) );
	}

	/** Restarting a running timer starts it over, as QM's does. */
	public function test_restarting_a_timer_starts_it_over(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		\Newspack_Nodes\Core::$clock = static fn (): float => 1_800_000_100.25;
		$qm->start( 'ruru-7746' );
		$qm->lap( 'ruru-7746', 'first run' );
		\Newspack_Nodes\Core::$clock = static fn (): float => 1_800_000_200.75;
		$qm->start( 'ruru-7746' );
		$qm->stop( 'ruru-7746' );

		$entries = $this->logged_entries();
		$this->assertSame( 1_800_000_200.75, self::entries_of( $entries, 'qm (start)' )[0]['ts'] );
		$this->assertSame( [], self::entries_of( $entries, 'qm lap' ) );
	}

	/** Laps past the cap are counted on the close rather than held. */
	public function test_laps_past_the_cap_are_counted_on_the_close(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		$qm->start( 'hihi-7746' );
		for ( $i = 0; $i < Query_Monitor_Actions::MAX_ITEMS + 3; $i++ ) {
			$qm->lap( 'hihi-7746', "lap {$i}" );
		}
		$qm->stop( 'hihi-7746' );

		$entries = $this->logged_entries();
		$this->assertCount( Query_Monitor_Actions::MAX_ITEMS, self::entries_of( $entries, 'qm lap' ) );
		$this->assertSame( '3 laps or timers not recorded', self::entries_of( $entries, 'qm (complete)' )[0]['m'] ?? null );
	}

	/** Past the cap on open timers a new start is refused, so memory stays bounded. */
	public function test_starts_past_the_open_timer_cap_are_refused(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		for ( $i = 0; $i <= Query_Monitor_Actions::MAX_OPEN; $i++ ) {
			$qm->start( "timer {$i}" );
		}
		$qm->stop( 'timer ' . Query_Monitor_Actions::MAX_OPEN );

		$this->assertSame( [ 'Timer not started' ], \array_column( self::entries_of( $this->logged_entries(), 'qm (warning)' ), 'm' ) );
	}

	/**
	 * Each level is an entry at its level, and its placeholders take the
	 * context SHAPED as a hook argument is: an allowlisted key's value, `?`
	 * for any other, the class of an object and the type of anything else.
	 */
	public function test_a_log_entry_interpolates_its_shaped_context(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		$this->say( $qm, 'error',
			'{post_type} {email} {paged} {flag} {thing} {list} {missing}',
			[ 'post_type' => 'kea_story', 'email' => 'weka-7746@kea.test', 'paged' => 7, 'flag' => true, 'thing' => new \ArrayObject(), 'list' => [ 1 ] ]
		);

		$entry = self::entries_of( $this->logged_entries(), 'qm (error)' )[0] ?? [];
		$this->assertSame( 'kea_story ? 7 ? [ArrayObject] [array] {missing}', $entry['m'] ?? null );
	}

	/** Every level writes under its own name, through the listener QM fires. */
	public function test_every_level_listener_writes_its_own_category(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();
		foreach ( $qm->listeners() as [ $hook, $callback ] ) {
			if ( \str_starts_with( $hook, 'qm/' ) && ! \in_array( $hook, [ 'qm/start', 'qm/stop', 'qm/lap' ], true ) ) {
				$callback( 'said at ' . $hook );
			}
		}

		$said = \array_column(
			\array_filter( $this->logged_entries(), static fn ( array $e ): bool => \str_starts_with( (string) $e['k'], 'qm (' ) ),
			'm',
			'k'
		);
		$this->assertSame( 'said at qm/emergency', $said['qm (emergency)'] ?? null );
		$this->assertSame( 'said at qm/debug', $said['qm (debug)'] ?? null );
		$this->assertCount( 8, $said );
	}

	/** A message that is not a string reads as QM reads it, its data shaped. */
	public function test_a_message_that_is_not_a_string_reads_as_qm_reads_it(): void {
		$this->start_logged_request();
		$qm = new Query_Monitor_Actions();

		$this->say( $qm, 'info', new \WP_Error( 'kea_down', 'Kea feed refused' ) );
		$this->say( $qm, 'info', new \RuntimeException( 'weka gone' ) );
		$this->say( $qm, 'info', null );
		$this->say( $qm, 'info', false );
		$this->say( $qm, 'info', '   ' );
		$this->say( $qm, 'info', [ 'post_type' => 'kea_story', 'user_pass' => 'hunter2' ] );

		$this->assertSame(
			[
				'WP_Error: Kea feed refused (kea_down)',
				'RuntimeException: weka gone',
				'null',
				'false',
				'(Empty string)',
				'{"post_type":"kea_story","user_pass":"?"}',
			],
			\array_column( self::entries_of( $this->logged_entries(), 'qm (info)' ), 'm' )
		);
	}

	/** With no logged request open, every action is a no-op. */
	public function test_nothing_is_written_without_a_started_logger(): void {
		$qm = new Query_Monitor_Actions();

		$qm->start( 'kea' );
		$qm->lap( 'kea', 'lap' );
		$qm->stop( 'kea' );
		$this->say( $qm, 'error', 'kea' );
		$qm->finish();

		$this->assertFalse( Log_Manager::has_instance() );
	}
}
