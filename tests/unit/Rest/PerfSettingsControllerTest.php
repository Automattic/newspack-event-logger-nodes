<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\PerfSettingsController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfSettingsController::class )]
class PerfSettingsControllerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_options']       = [];
		PerformanceControllerBase::set_cache( new FakeMemcached() );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		parent::tearDown();
	}

	private function call( string $option, mixed $value ): \WP_REST_Response|\WP_Error {
		$req = new \WP_REST_Request();
		$req->set_param( 'option', $option );
		$req->set_param( 'value', $value );
		return ( new PerfSettingsController() )->update_setting( $req );
	}

	// ── register_routes ────────────────────────────────────────────────

	public function test_register_routes_registers_settings(): void {
		( new PerfSettingsController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/settings', $GLOBALS['_rest_routes'] );
	}

	public function test_register_routes_uses_post(): void {
		( new PerfSettingsController() )->register_routes();
		$this->assertSame( 'POST', $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/settings']['methods'] );
	}

	// ── validate_option_name ────────────────────────────────────────────

	public function test_validate_option_name_accepts_whitelisted(): void {
		$ctrl = new PerfSettingsController();
		$this->assertTrue( $ctrl->validate_option_name( 'newspack_event_logger_nodes_log_events' ) );
		$this->assertTrue( $ctrl->validate_option_name( 'newspack_event_logger_nodes_log_memory' ) );
		$this->assertTrue( $ctrl->validate_option_name( 'newspack_event_logger_nodes_auto_disable_threshold' ) );
		$this->assertTrue( $ctrl->validate_option_name( 'newspack_event_logger_nodes_auto_protect_time_threshold' ) );
	}

	public function test_validate_option_name_rejects_non_whitelisted(): void {
		$ctrl = new PerfSettingsController();
		$this->assertFalse( $ctrl->validate_option_name( 'arbitrary_option' ) );
		$this->assertFalse( $ctrl->validate_option_name( '' ) );
		$this->assertFalse( $ctrl->validate_option_name( 12345 ) );  // non-string
	}

	// ── update_setting: array ───────────────────────────────────────────

	public function test_update_setting_writes_array_option(): void {
		$resp = $this->call( 'newspack_event_logger_nodes_log_events', [ 'init', 'shutdown' ] );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertSame( 'newspack_event_logger_nodes_log_events', $body['option'] );
		$this->assertTrue( $body['updated'] );
		$this->assertSame( [ 'init', 'shutdown' ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] );
	}

	public function test_update_setting_array_rejects_non_array_value(): void {
		$resp = $this->call( 'newspack_event_logger_nodes_log_events', 'not-an-array' );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_value', $resp->get_error_code() );
	}

	public function test_update_setting_array_sanitizes_text_values(): void {
		$resp  = $this->call( 'newspack_event_logger_nodes_log_events', [ "<b>init</b>", "  trim_me\t" ] );
		$saved = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertSame( 'init', $saved[0] );    // tags stripped (content preserved)
		$this->assertSame( 'trim_me', $saved[1] ); // whitespace collapsed/trimmed
	}

	public function test_update_setting_array_handles_nested_arrays(): void {
		$value = [
			'group' => [ 'nested-event-1', 'nested-event-2' ],
		];
		$this->call( 'newspack_event_logger_nodes_log_events', $value );
		$saved = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertSame( [ 'nested-event-1', 'nested-event-2' ], $saved['group'] );
	}

	public function test_update_setting_array_rejects_too_deep(): void {
		// MAX depth in sanitize_array is 5.
		$deep = 'value';
		for ( $i = 0; $i < 7; $i++ ) {
			$deep = [ 'nest' => $deep ];
		}
		$resp = $this->call( 'newspack_event_logger_nodes_log_events', $deep );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_value', $resp->get_error_code() );
	}

	public function test_update_setting_array_rejects_excessive_count(): void {
		// MAX_EVENTS = 10000.
		$big = \array_fill( 0, 10001, 'x' );
		$resp = $this->call( 'newspack_event_logger_nodes_log_events', $big );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	// ── update_setting: bool / int / float ──────────────────────────────

	public function test_update_setting_writes_bool_option(): void {
		$this->call( 'newspack_event_logger_nodes_log_memory', true );
		$this->assertTrue( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
	}

	public function test_update_setting_writes_int_option(): void {
		$this->call( 'newspack_event_logger_nodes_auto_disable_threshold', 50 );
		$this->assertSame( 50, $GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_disable_threshold'] );
	}

	public function test_update_setting_int_rejects_negative(): void {
		$resp = $this->call( 'newspack_event_logger_nodes_auto_disable_threshold', -5 );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_value', $resp->get_error_code() );
	}

	public function test_update_setting_int_rejects_overflow(): void {
		// Max is 1073741824 (2^30).
		$resp = $this->call( 'newspack_event_logger_nodes_auto_disable_threshold', 2 ** 31 );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_update_setting_int_rejects_non_numeric(): void {
		$resp = $this->call( 'newspack_event_logger_nodes_auto_disable_threshold', 'banana' );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_update_setting_writes_float_option(): void {
		$this->call( 'newspack_event_logger_nodes_auto_protect_time_threshold', 1.5 );
		$this->assertEqualsWithDelta( 1.5, $GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_protect_time_threshold'], 0.001 );
	}

	public function test_update_setting_float_rejects_negative(): void {
		$resp = $this->call( 'newspack_event_logger_nodes_auto_protect_time_threshold', -0.1 );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_update_setting_float_rejects_overflow(): void {
		$resp = $this->call( 'newspack_event_logger_nodes_auto_protect_time_threshold', 99999.0 );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	public function test_update_setting_float_rejects_non_numeric(): void {
		$resp = $this->call( 'newspack_event_logger_nodes_auto_protect_time_threshold', [ 'array' ] );
		$this->assertInstanceOf( \WP_Error::class, $resp );
	}

	// ── update_setting: rejection paths ─────────────────────────────────

	public function test_update_setting_rejects_unknown_option(): void {
		$resp = $this->call( 'arbitrary_option', 'x' );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'invalid_option', $resp->get_error_code() );
		$this->assertSame( 400, $resp->data['status'] ?? 0 );
	}

	// ── update_permissions_check ────────────────────────────────────────

	public function test_update_permissions_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ctrl = new PerfSettingsController();
		$result = $ctrl->update_permissions_check( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->data['status'] ?? 0 );
	}

	public function test_update_permissions_check_passes_for_admin(): void {
		$GLOBALS['_current_user_can'] = true;
		$ctrl = new PerfSettingsController();
		$this->assertTrue( $ctrl->update_permissions_check( new \WP_REST_Request() ) );
	}

	// ── side effects: SettingsSync suppression + Config::reset ──────────

	public function test_update_setting_suppresses_settings_sync(): void {
		// We only assert the call goes through cleanly when SettingsSync exists.
		// Internally the controller toggles the suppression flag around update_option.
		$resp = $this->call( 'newspack_event_logger_nodes_log_memory', true );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
	}

	// ── sanitize_array sub-branches via integer-keyed arrays ───────────

	public function test_update_setting_array_preserves_int_keys(): void {
		$value = [ 0 => 'first', 1 => 'second', 'named' => 'third' ];
		$this->call( 'newspack_event_logger_nodes_log_events', $value );
		$saved = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertSame( 'first',  $saved[0] );
		$this->assertSame( 'second', $saved[1] );
		$this->assertSame( 'third',  $saved['named'] );
	}

	public function test_update_setting_array_passes_through_numeric_values(): void {
		$value = [ 'count' => 42, 'ratio' => 1.5, 'enabled' => true ];
		$this->call( 'newspack_event_logger_nodes_log_events', $value );
		$saved = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertSame( 42,   $saved['count'] );
		$this->assertSame( 1.5,  $saved['ratio'] );
		$this->assertTrue( $saved['enabled'] );
	}
}
