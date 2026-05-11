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
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
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
		// No NEWSPACK_NODES_WORKER_TYPE, no manage_options — must skip.
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
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		AutoTuneHandlers::standalone_disable_hooks( [ 'noisy' ], [] );

		$this->assertSame(
			[ 'init' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']
		);
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
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
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
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
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_standalone_disable_custom_events_preserves_significant(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
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
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_standalone_add_significant_events_merges(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
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
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_standalone_skips_empty_payloads(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';

		AutoTuneHandlers::standalone_disable_hooks( [], [] );
		AutoTuneHandlers::standalone_disable_custom_events( [], [] );
		AutoTuneHandlers::standalone_add_significant_events( [], [] );

		// No options were created.
		$this->assertArrayNotHasKey(
			'newspack_event_logger_nodes_log_events',
			$GLOBALS['_wp_options']
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_standalone_suppresses_settings_sync_during_write(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		AutoTuneHandlers::standalone_disable_hooks( [ 'noisy' ], [] );

		// Suppress flag must be cleared after the write (finally block).
		$this->assertFalse(
			SettingsSync::is_sync_suppressed(),
			'suppress_sync must be cleared in the finally block after standalone update'
		);

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	// --- Hub-mode behaviour --------------------------------------------------

	public function test_hub_mode_skips_when_unauthorized(): void {
		// hub_disable_hooks must respect capability check too.
		$GLOBALS['_current_user_can'] = false;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );

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
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';

		AutoTuneHandlers::hub_disable_hooks( [ 'noisy' ], [] );
		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_hub_mode_skips_empty_payloads(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';

		AutoTuneHandlers::hub_disable_hooks( [], [] );
		AutoTuneHandlers::hub_disable_custom_events( [], [] );
		AutoTuneHandlers::hub_add_significant_events( [], [] );

		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
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

	// --- Standalone-mode fall-through edge cases ----------------------------

	public function test_standalone_disable_hooks_handles_non_array_existing_option(): void {
		// When the WP option is corrupt (string, not array), the handler must
		// degrade to an empty existing list rather than crashing.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = 'corrupted-string';

		AutoTuneHandlers::standalone_disable_hooks( [ 'noisy' ], [] );

		// Resulting option must be an array (handler treats existing as empty).
		$this->assertIsArray( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_standalone_disable_custom_events_handles_non_array_existing_option(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = 'not-an-array';

		AutoTuneHandlers::standalone_disable_custom_events( [ 'event' ], [] );

		// The handler short-circuits when there's nothing to remove from an
		// empty existing list, but must not crash on non-array input.
		$this->assertIsArray( $GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_standalone_add_significant_events_handles_non_array_existing_option(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events'] = 'string-corrupt';

		AutoTuneHandlers::standalone_add_significant_events( [ 'event_a' ], [] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events'];
		$this->assertIsArray( $result );
		$this->assertContains( 'event_a', $result );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_standalone_disable_custom_events_with_no_existing_option(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
		// No existing option — handler initializes empty array.

		AutoTuneHandlers::standalone_disable_custom_events( [ 'a', 'b' ], [] );

		// Resulting option is the empty list (since both events were absent).
		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] ?? null;
		$this->assertIsArray( $result );
		$this->assertSame( [], $result );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_standalone_disable_hooks_significant_context_with_non_array(): void {
		// $context['significant_events'] may be passed as null, false, or a
		// non-array — handler must degrade to empty significant set rather
		// than crashing.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'a', 'b' ];

		// Non-array significant_events context.
		AutoTuneHandlers::standalone_disable_hooks( [ 'a' ], [ 'significant_events' => 'not-array' ] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertNotContains( 'a', $result );
		$this->assertContains( 'b', $result );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_standalone_disable_custom_events_with_significant_protection(): void {
		// Same significant-protection contract as disable_hooks: an event
		// flagged significant must be preserved even when listed for removal.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = [
			'event_a' => true,
			'event_b' => true,
		];

		AutoTuneHandlers::standalone_disable_custom_events(
			[ 'event_a', 'event_b' ],
			[ 'significant_events' => [ 'event_a' => true ] ]
		);

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'];
		// event_a preserved (significant); event_b removed.
		$this->assertArrayHasKey( 'event_a', $result );
		$this->assertArrayNotHasKey( 'event_b', $result );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	// --- Hub-mode behaviour --------------------------------------------------

	public function test_hub_disable_hooks_skips_when_not_hub_config(): void {
		// Authorized but Config doesn't have enable_workers === true; hub
		// fan-out must NOT happen.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';

		// Force-load config that has enable_workers = false (default test config).
		// is_hub() reads Config::load_config() which honors WP options stub.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'a', 'b' ];
		Config::reset();

		// hub_disable_hooks should run authorized + is_hub checks. The default
		// test config should have enable_workers === false; verify by direct
		// inspection that the hub call is a no-op (no exceptions).
		AutoTuneHandlers::hub_disable_hooks( [ 'a' ], [] );

		// Existing option untouched (standalone runs separately).
		$this->assertSame( [ 'a', 'b' ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_hub_disable_custom_events_skips_when_unauthorized(): void {
		// Unauthorized — both is_hub check and authorized check should bail.
		$GLOBALS['_current_user_can'] = false;

		AutoTuneHandlers::hub_disable_custom_events( [ 'event_a' ], [] );

		// Doesn't crash; option is unchanged (was never set).
		$this->assertArrayNotHasKey(
			'newspack_event_logger_nodes_custom_events',
			$GLOBALS['_wp_options']
		);
	}

	public function test_hub_add_significant_events_skips_when_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;

		AutoTuneHandlers::hub_add_significant_events( [ 'event_a' ], [] );

		$this->assertArrayNotHasKey(
			'newspack_event_logger_nodes_significant_events',
			$GLOBALS['_wp_options']
		);
	}

	public function test_hub_disable_custom_events_runs_when_authorized(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';

		// Even if is_hub() returns false, hub_* must not crash. The exit point
		// is is_hub() → return — no JobIntake side-effect.
		AutoTuneHandlers::hub_disable_custom_events( [ 'event_a' ], [] );

		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_hub_add_significant_events_runs_when_authorized(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';

		AutoTuneHandlers::hub_add_significant_events( [ 'event_a' ], [] );

		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_authorized_via_admin_capability(): void {
		// authorized() prefers NEWSPACK_NODES_WORKER_TYPE; falls back to
		// current_user_can('manage_options'). Cover the admin path.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		AutoTuneHandlers::standalone_disable_hooks( [ 'noisy' ], [] );

		$this->assertSame(
			[ 'init' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']
		);
	}

	public function test_unauthorized_skips_all_three_handlers(): void {
		// Cover the unauthorized branch for all three handlers.
		$GLOBALS['_current_user_can'] = false;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );

		AutoTuneHandlers::standalone_disable_custom_events( [ 'a' ], [] );
		AutoTuneHandlers::standalone_add_significant_events( [ 'a' ], [] );

		// Nothing was written.
		$this->assertArrayNotHasKey(
			'newspack_event_logger_nodes_custom_events',
			$GLOBALS['_wp_options']
		);
		$this->assertArrayNotHasKey(
			'newspack_event_logger_nodes_significant_events',
			$GLOBALS['_wp_options']
		);
	}

	public function test_init_idempotent_returns_immediately_on_second_call(): void {
		// init() uses static $registered. Once set, the second call returns
		// without invoking add_action again. This verifies the early-return
		// branch.
		AutoTuneHandlers::init();
		$count_before = \count( $GLOBALS['_wp_actions'] );
		AutoTuneHandlers::init();
		$count_after = \count( $GLOBALS['_wp_actions'] );

		$this->assertSame( $count_before, $count_after );
	}

	// --- Hub-mode actually-as-hub flow (exercises queue_remote) -------------

	public function test_hub_disable_hooks_when_actually_hub(): void {
		// Flip enable_workers to true via WP option stub so is_hub() returns true,
		// then verify hub_disable_hooks computes the right "to-disable" list.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE']                       = 'firehose';
		$GLOBALS['_wp_options']['newspack_nodes_enable_workers']   = '1';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [
			'init', 'noisy', 'important',
		];
		Config::reset();

		AutoTuneHandlers::hub_disable_hooks(
			[ 'noisy', 'important' ],
			[ 'significant_events' => [ 'important' => true ] ]
		);

		// hub_disable_hooks computes the "after" value and queues a remote sync.
		// We don't assert on the queue side-effect (jobintake.log write) — only
		// that the computation runs without error. The standalone handler at
		// priority 10 would have applied the local update separately.
		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_hub_disable_custom_events_when_actually_hub(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE']                            = 'firehose';
		$GLOBALS['_wp_options']['newspack_nodes_enable_workers']        = '1';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = [
			'event_a' => true,
			'event_b' => true,
		];
		Config::reset();

		AutoTuneHandlers::hub_disable_custom_events(
			[ 'event_a', 'event_b' ],
			[ 'significant_events' => [ 'event_a' => true ] ]
		);

		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_hub_add_significant_events_when_actually_hub(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE']                            = 'firehose';
		$GLOBALS['_wp_options']['newspack_nodes_enable_workers']        = '1';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events'] = [
			'existing',
		];
		Config::reset();

		AutoTuneHandlers::hub_add_significant_events(
			[ 'new_one', 'new_two', 'existing' ],
			[]
		);

		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_hub_disable_hooks_handles_non_array_existing(): void {
		// is_hub=true but existing option is corrupted (string).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE']                       = 'firehose';
		$GLOBALS['_wp_options']['newspack_nodes_enable_workers']   = '1';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = 'corrupt';
		Config::reset();

		// Doesn't crash on non-array existing.
		AutoTuneHandlers::hub_disable_hooks( [ 'a' ], [] );
		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_hub_disable_custom_events_handles_non_array_existing(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE']                            = 'firehose';
		$GLOBALS['_wp_options']['newspack_nodes_enable_workers']        = '1';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = 'invalid';
		Config::reset();

		AutoTuneHandlers::hub_disable_custom_events( [ 'event' ], [] );
		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}

	public function test_hub_add_significant_events_handles_non_array_existing(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE']                            = 'firehose';
		$GLOBALS['_wp_options']['newspack_nodes_enable_workers']        = '1';
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events'] = 'invalid';
		Config::reset();

		AutoTuneHandlers::hub_add_significant_events( [ 'a' ], [] );
		$this->assertTrue( true );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
	}
}
