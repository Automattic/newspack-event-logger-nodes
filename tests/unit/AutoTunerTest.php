<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\AutoTuner;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\SettingsSync;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( AutoTuner::class )]
class AutoTunerTest extends TestCase {
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

	/**
	 * Build a TM_STRUCT message routed at the AutoTuner. Returned by reference
	 * via output variable so the caller's `fill( array &$message )` doesn't
	 * trip "Only variables should be passed by reference" on a function-call
	 * result.
	 */
	private function autotune_message( string $key, array $items, array $context = [] ): array {
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::KEY ]   = $key;
		$msg[ Message::VALUE ] = [ 'items' => $items, 'context' => $context ];
		return $msg;
	}

	private function worker_context(): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE'] = 'firehose';
	}

	private function dispatch( AutoTuner $tuner, string $key, array $items, array $context = [] ): void {
		$msg = $this->autotune_message( $key, $items, $context );
		$tuner->fill( $msg );
	}

	// --- Type / shape gates ---------------------------------------------------

	public function test_non_struct_message_ignored(): void {
		$this->worker_context();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		$tuner                 = new AutoTuner();
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::KEY ]   = 'disable_hooks';
		$msg[ Message::VALUE ] = 'not-an-array';
		$tuner->fill( $msg );

		$this->assertSame(
			[ 'init', 'noisy' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']
		);
	}

	public function test_non_array_value_ignored(): void {
		$this->worker_context();
		$tuner                 = new AutoTuner();
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::KEY ]   = 'disable_hooks';
		$msg[ Message::VALUE ] = 'string-value';
		$tuner->fill( $msg );
		$this->assertEmpty( $GLOBALS['_wp_options'] );
	}

	public function test_unknown_key_ignored(): void {
		$this->worker_context();
		$tuner = new AutoTuner();
		$this->dispatch( $tuner, 'bogus_key', [ 'a', 'b' ] );
		$this->assertEmpty( $GLOBALS['_wp_options'] );
	}

	public function test_empty_items_ignored(): void {
		$this->worker_context();
		$tuner = new AutoTuner();
		$this->dispatch( $tuner, 'disable_hooks', [] );
		$this->dispatch( $tuner, 'disable_custom_events', [] );
		$this->dispatch( $tuner, 'add_significant_events', [] );
		$this->assertEmpty( $GLOBALS['_wp_options'] );
	}

	// --- Authorization gate ---------------------------------------------------

	public function test_skips_when_unauthorized(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		$tuner = new AutoTuner();
		$this->dispatch( $tuner, 'disable_hooks', [ 'noisy' ] );

		$this->assertSame(
			[ 'init', 'noisy' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']
		);
	}

	public function test_runs_when_worker_env_set(): void {
		$this->worker_context();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		$tuner = new AutoTuner();
		$this->dispatch( $tuner, 'disable_hooks', [ 'noisy' ] );

		$this->assertSame(
			[ 'init' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']
		);
	}

	public function test_runs_when_manage_options_capability(): void {
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		$tuner = new AutoTuner();
		$this->dispatch( $tuner, 'disable_hooks', [ 'noisy' ] );

		$this->assertSame(
			[ 'init' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']
		);
	}

	// --- disable_hooks --------------------------------------------------------

	public function test_disable_hooks_preserves_significant(): void {
		$this->worker_context();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [
			'init',
			'noisy',
			'important',
		];

		$tuner = new AutoTuner();
		$this->dispatch(
			$tuner,
			'disable_hooks',
			[ 'noisy', 'important' ],
			[ 'significant_events' => [ 'important' => true ] ]
		);

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertNotContains( 'noisy', $result );
		$this->assertContains( 'important', $result, 'significant events must be preserved' );
		$this->assertContains( 'init', $result );
	}

	public function test_disable_hooks_handles_non_array_existing_option(): void {
		$this->worker_context();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = 'not-an-array';

		$tuner = new AutoTuner();
		$this->dispatch( $tuner, 'disable_hooks', [ 'noisy' ] );

		$this->assertSame( [], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );
	}

	public function test_disable_hooks_significant_context_with_non_array(): void {
		$this->worker_context();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		$tuner = new AutoTuner();
		$this->dispatch(
			$tuner,
			'disable_hooks',
			[ 'noisy' ],
			[ 'significant_events' => 'not-an-array' ]
		);

		$this->assertSame(
			[ 'init' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']
		);
	}

	// --- disable_custom_events ------------------------------------------------

	public function test_disable_custom_events_preserves_significant(): void {
		$this->worker_context();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = [
			'event_a' => true,
			'event_b' => true,
			'event_c' => true,
		];

		$tuner = new AutoTuner();
		$this->dispatch(
			$tuner,
			'disable_custom_events',
			[ 'event_a', 'event_b' ],
			[ 'significant_events' => [ 'event_b' => true ] ]
		);

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'];
		$this->assertArrayNotHasKey( 'event_a', $result );
		$this->assertArrayHasKey( 'event_b', $result, 'significant event_b must be preserved' );
		$this->assertArrayHasKey( 'event_c', $result );
	}

	public function test_disable_custom_events_handles_non_array_existing_option(): void {
		$this->worker_context();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = false;

		$tuner = new AutoTuner();
		$this->dispatch( $tuner, 'disable_custom_events', [ 'a' ] );

		$this->assertSame( [], $GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] );
	}

	// --- add_significant_events -----------------------------------------------

	public function test_add_significant_events_merges_no_duplicates(): void {
		$this->worker_context();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events'] = [
			'existing_a',
			'existing_b',
		];

		$tuner = new AutoTuner();
		$this->dispatch( $tuner, 'add_significant_events', [ 'new_one', 'existing_a', 'new_two' ] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events'];
		$this->assertContains( 'existing_a', $result );
		$this->assertContains( 'existing_b', $result );
		$this->assertContains( 'new_one', $result );
		$this->assertContains( 'new_two', $result );
		$this->assertSame( \count( $result ), \count( \array_unique( $result ) ) );
	}

	public function test_add_significant_events_handles_non_array_existing_option(): void {
		$this->worker_context();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events'] = null;

		$tuner = new AutoTuner();
		$this->dispatch( $tuner, 'add_significant_events', [ 'new_one' ] );

		$this->assertSame(
			[ 'new_one' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events']
		);
	}

	public function test_add_significant_events_resets_when_option_is_scalar(): void {
		// Hostile option: significant_events stored as a scalar instead of an
		// array. The `! is_array( $existing )` branch resets to []; the merge
		// then operates against an empty list. The existing null-option test
		// hits the `?? default` short-circuit (null coalesces to the default
		// `[]` in get_option) — to exercise the actual non-array fallback we
		// need a non-null scalar.
		$this->worker_context();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events'] = 'not-an-array';

		$tuner = new AutoTuner();
		$this->dispatch( $tuner, 'add_significant_events', [ 'new_one', 'new_two' ] );

		$this->assertSame(
			[ 'new_one', 'new_two' ],
			$GLOBALS['_wp_options']['newspack_event_logger_nodes_significant_events']
		);
	}

	// --- SettingsSync suppression ---------------------------------------------

	public function test_suppresses_settings_sync_during_write(): void {
		$this->worker_context();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'noisy' ];

		$tuner = new AutoTuner();
		$this->dispatch( $tuner, 'disable_hooks', [ 'noisy' ] );

		$this->assertFalse(
			SettingsSync::is_sync_suppressed(),
			'suppress_sync must be cleared in the finally block after the local update'
		);
	}
}
