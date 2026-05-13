<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Rest\TopologyStreamController;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class TopologyStreamControllerTest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = $this->make_temp_dir( 'topology-stream-test-' );
		\mkdir( $this->tmp . '/locks', 0755, true );
		$GLOBALS['_current_user_can'] = true;
	}

	protected function tearDown(): void {
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	public function test_stream_returns_404_when_worker_lock_missing(): void {
		$ctrl = new TopologyStreamController();
		$ctrl->set_base_dir( $this->tmp );

		$req = new \WP_REST_Request();
		$req->set_param( 'topology', 'firehose-workers' );
		$req->set_param( 'partition', 0 );

		$resp = $ctrl->stream( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 404, $resp->data['status'] ?? 0 );
	}
}
