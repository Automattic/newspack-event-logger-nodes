<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerformanceControllerBase::class )]
class PerformanceControllerBaseTest extends TestCase {
	public function test_read_permissions_check_requires_capability(): void {
		$ctrl = new TestableController();
		$GLOBALS['_current_user_can'] = false;
		$result = $ctrl->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_read_permissions_check_passes_when_capable(): void {
		$ctrl = new TestableController();
		$GLOBALS['_current_user_can'] = true;
		$this->assertTrue( $ctrl->read_permissions_check() );
	}

	public function test_validate_partition_accepts_in_range(): void {
		$ctrl = new TestableController();
		$this->assertSame( 2, $ctrl->validate_partition( 2, 4 ) );
	}

	public function test_validate_partition_rejects_out_of_range(): void {
		$ctrl = new TestableController();
		$result = $ctrl->validate_partition( 10, 4 );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_validate_partition_rejects_negative(): void {
		$ctrl = new TestableController();
		$result = $ctrl->validate_partition( -1, 4 );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}

class TestableController extends PerformanceControllerBase {
	public function register_routes(): void {}
}
