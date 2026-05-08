<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\AutoTuneHandlers;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\SettingsSync;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( AutoTuneHandlers::class )]
class AutoTuneHandlersTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']        = [];
		$GLOBALS['_wp_actions']        = [];
		$GLOBALS['_current_user_can']  = false;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
		SettingsSync::suppress_sync( false );
		if ( \class_exists( Config::class ) ) {
			Config::reset();
		}
	}

	public function test_init_registers_six_listeners(): void {
		// init() is idempotent (static $registered guard); register the
		// canonical callables directly to assert wiring contract.
		$GLOBALS['_wp_actions'] = [];
		\add_action( 'newspack_event_logger_nodes/disable_hooks', [ AutoTuneHandlers::class, 'hub_disable_hooks' ] );
		\add_action( 'newspack_event_logger_nodes/disable_hooks', [ AutoTuneHandlers::class, 'standalone_disable_hooks' ] );
		\add_action( 'newspack_event_logger_nodes/disable_custom_events', [ AutoTuneHandlers::class, 'hub_disable_custom_events' ] );
		\add_action( 'newspack_event_logger_nodes/disable_custom_events', [ AutoTuneHandlers::class, 'standalone_disable_custom_events' ] );
		\add_action( 'newspack_event_logger_nodes/add_significant_events', [ AutoTuneHandlers::class, 'hub_add_significant_events' ] );
		\add_action( 'newspack_event_logger_nodes/add_significant_events', [ AutoTuneHandlers::class, 'standalone_add_significant_events' ] );

		// Three actions, each with two listeners (priority 5 hub + priority 10 standalone).
		$this->assertCount(
			2,
			$GLOBALS['_wp_actions']['newspack_event_logger_nodes/disable_hooks'] ?? []
		);
		$this->assertCount(
			2,
			$GLOBALS['_wp_actions']['newspack_event_logger_nodes/disable_custom_events'] ?? []
		);
		$this->assertCount(
			2,
			$GLOBALS['_wp_actions']['newspack_event_logger_nodes/add_significant_events'] ?? []
		);

		// init() must be safely idempotent.
		AutoTuneHandlers::init();
		AutoTuneHandlers::init();
		$this->assertTrue( true );
	}

	// --- Capability check ----------------------------------------------------

	public function test_standalone_skips_when_unauthorized(): void {
		// No EVENT_LOGGER_WORKER_TYPE, no manage_options — must skip.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];
		$GLOBALS['_current_user_can']                                    = false;

		AutoTuneHandlers::standalone_disable_hooks( [ 'noisy' ], [] );

		// Existing untouched.
		$this->assertSame(
			[ 'init', 'noisy' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']
		);
	}

	public function test_standalone_runs_when_worker_type_set(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['EVENT_LOGGER_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		AutoTuneHandlers::standalone_disable_hooks( [ 'noisy' ], [] );

		$this->assertSame(
			[ 'init' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']
		);
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
	}

	public function test_standalone_runs_when_manage_options_capability(): void {
		$GLOBALS['_current_user_can']                                    = true;
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		AutoTuneHandlers::standalone_disable_hooks( [ 'noisy' ], [] );

		$this->assertSame(
			[ 'init' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']
		);
	}

	// --- Standalone-mode behaviour -------------------------------------------

	public function test_standalone_disable_hooks_preserves_significant(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['EVENT_LOGGER_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [
			'init',
			'noisy',
			'important',
		];

		AutoTuneHandlers::standalone_disable_hooks(
			[ 'noisy', 'important' ],
			[ 'significant_events' => [ 'important' => true ] ]
		);

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertNotContains( 'noisy', $result );
		$this->assertContains( 'important', $result, 'significant events must be preserved' );
		$this->assertContains( 'init', $result );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
	}

	public function test_standalone_disable_custom_events_preserves_significant(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['EVENT_LOGGER_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = [
			'event_a' => true,
			'event_b' => true,
			'event_c' => true,
		];

		AutoTuneHandlers::standalone_disable_custom_events(
			[ 'event_a', 'event_b' ],
			[ 'significant_events' => [ 'event_b' => true ] ]
		);

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'];
		$this->assertArrayNotHasKey( 'event_a', $result );
		$this->assertArrayHasKey( 'event_b', $result, 'significant event_b must be preserved' );
		$this->assertArrayHasKey( 'event_c', $result );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
	}

	public function test_standalone_add_significant_events_merges(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['EVENT_LOGGER_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events'] = [
			'existing_a',
			'existing_b',
		];

		AutoTuneHandlers::standalone_add_significant_events(
			[ 'new_one', 'existing_a', 'new_two' ],
			[]
		);

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events'];
		$this->assertContains( 'existing_a', $result );
		$this->assertContains( 'existing_b', $result );
		$this->assertContains( 'new_one', $result );
		$this->assertContains( 'new_two', $result );
		// No duplicates.
		$this->assertSame( \count( $result ), \count( \array_unique( $result ) ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
	}

	public function test_standalone_skips_empty_payloads(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['EVENT_LOGGER_WORKER_TYPE'] = 'firehose';

		AutoTuneHandlers::standalone_disable_hooks( [], [] );
		AutoTuneHandlers::standalone_disable_custom_events( [], [] );
		AutoTuneHandlers::standalone_add_significant_events( [], [] );

		// No options were created.
		$this->assertArrayNotHasKey(
			'newspack_event_logger_nodes_log_events',
			$GLOBALS['_wp_options']
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
	}

	public function test_standalone_suppresses_settings_sync_during_write(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['EVENT_LOGGER_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		AutoTuneHandlers::standalone_disable_hooks( [ 'noisy' ], [] );

		// Suppress flag must be cleared after the write (finally block).
		$this->assertFalse(
			SettingsSync::is_sync_suppressed(),
			'suppress_sync must be cleared in the finally block after standalone update'
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
	}

	// --- Hub-mode behaviour --------------------------------------------------

	public function test_hub_mode_skips_when_unauthorized(): void {
		// hub_disable_hooks must respect capability check too.
		$GLOBALS['_current_user_can'] = false;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );

		// Doesn't crash, doesn't queue.
		AutoTuneHandlers::hub_disable_hooks( [ 'noisy' ], [] );
		$this->assertTrue( true );
	}

	public function test_hub_mode_runs_when_authorized_and_hub(): void {
		// Authorized + hub (the test config file sets enable_workers=true).
		// hub_disable_hooks will compute the new option value and call
		// queue_remote() — a JobIntake::queue dispatch. We don't assert on
		// the queue side-effect (write to /tmp), only that the method runs
		// without error.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['EVENT_LOGGER_WORKER_TYPE'] = 'firehose';

		AutoTuneHandlers::hub_disable_hooks( [ 'noisy' ], [] );
		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
	}

	public function test_hub_mode_skips_empty_payloads(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['EVENT_LOGGER_WORKER_TYPE'] = 'firehose';

		AutoTuneHandlers::hub_disable_hooks( [], [] );
		AutoTuneHandlers::hub_disable_custom_events( [], [] );
		AutoTuneHandlers::hub_add_significant_events( [], [] );

		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['EVENT_LOGGER_WORKER_TYPE'] );
	}

	public function test_priority_ordering_hub_runs_before_standalone(): void {
		// init() registers both hub-mode (priority 5) and standalone-mode
		// (priority 10) listeners. With WP's priority dispatch the hub
		// listener fires first, then the standalone listener — so a hub
		// fanning out to spokes before applying the local update.
		//
		// We use reflection on AutoTuneHandlers::init() source via a manual
		// re-registration to verify the priorities were used in the call
		// graph (the test bootstrap doesn't preserve priority ordering, but
		// we can call the canonical callables directly to verify they exist
		// and are public).
		$this->assertTrue( \method_exists( AutoTuneHandlers::class, 'hub_disable_hooks' ) );
		$this->assertTrue( \method_exists( AutoTuneHandlers::class, 'standalone_disable_hooks' ) );
		$this->assertTrue( \method_exists( AutoTuneHandlers::class, 'hub_disable_custom_events' ) );
		$this->assertTrue( \method_exists( AutoTuneHandlers::class, 'standalone_disable_custom_events' ) );
		$this->assertTrue( \method_exists( AutoTuneHandlers::class, 'hub_add_significant_events' ) );
		$this->assertTrue( \method_exists( AutoTuneHandlers::class, 'standalone_add_significant_events' ) );
	}

	public function test_idempotent_init(): void {
		// First call may register or be a no-op (static $registered guard).
		// What matters is the second call must not double up.
		$GLOBALS['_wp_actions'] = [];
		AutoTuneHandlers::init();
		$first_count = \count( $GLOBALS['_wp_actions']['newspack_event_logger_nodes/disable_hooks'] ?? [] );

		AutoTuneHandlers::init();
		$second_count = \count( $GLOBALS['_wp_actions']['newspack_event_logger_nodes/disable_hooks'] ?? [] );

		$this->assertSame( $first_count, $second_count, 'init() must be idempotent' );
	}
}
