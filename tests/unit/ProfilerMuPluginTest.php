<?php
/**
 * The 00-newspack-profiler mu-plugin flushes deferred plugin-load events to
 * Log_Manager on plugins_loaded(-10001). That flush is gated on a class_exists
 * check; a stale class name silently no-ops it (the feature dies invisibly).
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class ProfilerMuPluginTest extends TestCase {

	public function test_profiler_flush_resolves_log_manager(): void {
		// The profiler ships in this repo's mu-plugins/ — a sibling of tests/,
		// present in any full checkout (CI, local, and the dndocker deploy).
		$profiler = \dirname( __DIR__, 2 ) . '/mu-plugins/00-newspack-profiler.php';

		Log_Manager::reset();

		// Isolate the action registry so only the profiler's plugins_loaded
		// flush fires during our do_action; restore it afterward.
		$saved_actions          = $GLOBALS['_wp_actions'] ?? [];
		$GLOBALS['_wp_actions'] = [];
		try {
			require $profiler;

			// The flush iterates $newspack_profiler['plugins']; give it one row.
			$GLOBALS['newspack_profiler']['plugins'] = [
				[ 'slug' => 'x', 'start_ts' => 1.0, 'duration_ns' => 1000, 'new_classes' => 0, 'new_files' => 0 ],
			];

			\do_action( 'plugins_loaded' );

			// The flush calls Log_Manager::instance() once its class_exists guard
			// passes, materializing the singleton. A stale class name returns at
			// the guard and leaves the singleton null.
			$ref = new \ReflectionProperty( Log_Manager::class, 'instance' );
			$ref->setAccessible( true );
			$this->assertNotNull(
				$ref->getValue(),
				'profiler plugins_loaded flush must resolve Log_Manager — a stale class name would silently no-op it'
			);
		} finally {
			$GLOBALS['_wp_actions'] = $saved_actions;
			Log_Manager::reset();
		}
	}
}
