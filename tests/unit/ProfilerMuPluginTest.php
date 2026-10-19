<?php
/**
 * The 00-newspack-profiler mu-plugin only measures: the event logger's
 * deferred bootstrap writes what it collected, so the drop-in registers no
 * `plugins_loaded` flush of its own.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Tests\TestCase;

class ProfilerMuPluginTest extends TestCase {

	public function test_the_drop_in_collects_and_writes_nothing_itself(): void {
		$saved_actions          = $GLOBALS['_wp_actions'] ?? [];
		$GLOBALS['_wp_actions'] = [];
		try {
			require \dirname( __DIR__, 2 ) . '/mu-plugins/00-newspack-profiler.php';
			$hooks    = \array_keys( $GLOBALS['_wp_actions'] );
			$profiler = $GLOBALS['newspack_profiler'];
		} finally {
			$GLOBALS['_wp_actions'] = $saved_actions;
			unset( $GLOBALS['newspack_profiler'] );
		}

		$this->assertNotContains( 'plugins_loaded', $hooks );
		$this->assertSame( [ 'request_time', 'request_ts', 'plugins' ], \array_keys( $profiler ) );
	}
}
