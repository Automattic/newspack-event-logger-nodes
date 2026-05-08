<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Rest\StatusController;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( StatusController::class )]
class StatusControllerTest extends TestCase {
	public function test_register_routes_registers_status_endpoint(): void {
		$GLOBALS['_rest_routes'] = [];
		$ctrl = new StatusController();
		$ctrl->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/status', $GLOBALS['_rest_routes'] );
	}

	public function test_get_status_returns_json_with_version(): void {
		$ctrl = new StatusController();
		$req = new \WP_REST_Request();
		$resp = $ctrl->get_status( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$data = $resp->get_data();
		$this->assertArrayHasKey( 'version', $data );
		$this->assertArrayHasKey( 'status', $data );
		$this->assertSame( 'ok', $data['status'] );
	}
}
