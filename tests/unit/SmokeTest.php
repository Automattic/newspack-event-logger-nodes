<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Tests\TestCase;

class SmokeTest extends TestCase {
	public function test_runtime_loaded(): void {
		$this->assertTrue( \class_exists( '\Newspack_Nodes\Node' ) );
		$this->assertTrue( \class_exists( '\Newspack_Nodes\Topic' ) );
	}

	public function test_app_constants_defined(): void {
		$this->assertTrue( \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' ) );
	}
}
