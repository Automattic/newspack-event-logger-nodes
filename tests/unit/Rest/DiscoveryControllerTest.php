<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\DiscoveryController;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( DiscoveryController::class )]
class DiscoveryControllerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_actions']       = [];
		PerformanceControllerBase::set_cache( new FakeMemcached() );
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		$GLOBALS['_wp_actions'] = [];
		parent::tearDown();
	}

	public function test_register_routes_registers_discovery_endpoint(): void {
		( new DiscoveryController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/discovery', $GLOBALS['_rest_routes'] );
	}

	public function test_get_discovery_returns_registered_hooks_and_custom_events(): void {
		\add_filter( 'newspack_nodes/config', static function ( array $cfg ): array {
			$cfg['log_events']    = [ 'init', 'wp_loaded', 'shutdown' ];
			$cfg['custom_events'] = [ 'my_event' => true ];
			return $cfg;
		} );

		$ctrl = new DiscoveryController();
		$resp = $ctrl->get_discovery( new \WP_REST_Request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();

		$this->assertArrayHasKey( 'registered_hooks', $body );
		$this->assertArrayHasKey( 'custom_events', $body );
		$this->assertSame( [ 'init', 'wp_loaded', 'shutdown' ], $body['registered_hooks'] );
		$this->assertSame( [ 'my_event' ], $body['custom_events'] );
	}

	public function test_custom_events_filtered_out_of_registered_hooks(): void {
		\add_filter( 'newspack_nodes/config', static function ( array $cfg ): array {
			$cfg['log_events']    = [ 'my_event', 'init' ];
			$cfg['custom_events'] = [ 'my_event' ];
			return $cfg;
		} );

		$ctrl = new DiscoveryController();
		$resp = $ctrl->get_discovery( new \WP_REST_Request() );
		$body = $resp->get_data();
		$this->assertSame( [ 'init' ], $body['registered_hooks'] );
		$this->assertSame( [ 'my_event' ], $body['custom_events'] );
	}

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new DiscoveryController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
