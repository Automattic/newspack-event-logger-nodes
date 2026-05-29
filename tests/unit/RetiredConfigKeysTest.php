<?php
/**
 * Guard test: retired Config keys must not reappear in test fixtures.
 *
 * `enable_workers` was retired in v0.5.0 — the aggregator-mode gate is
 * `enable_aggregator` (typed bool, default OFF). Settings_Sync's push side
 * is intentionally ungated; without an aggregator topology + remotes,
 * queued remote_manager jobs are silent no-ops. Leaving `enable_workers`
 * in test fixtures suggests it still gates something — which it does not.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Tests\TestCase;

class RetiredConfigKeysTest extends TestCase {

	/** Keys retired from the substrate or application schema. */
	private const RETIRED_KEYS = [
		'enable_workers',
	];

	public function test_baseline_test_config_does_not_reference_retired_keys(): void {
		$config = include \dirname( __DIR__ ) . '/newspack-event-logger-nodes-test-config.php';
		foreach ( self::RETIRED_KEYS as $key ) {
			$this->assertArrayNotHasKey(
				$key,
				$config,
				"baseline test config must not reference retired key '$key'"
			);
		}
	}

	public function test_per_test_config_fixtures_do_not_reference_retired_keys(): void {
		$dir = \dirname( __DIR__ ) . '/configs';
		foreach ( \glob( $dir . '/*.php' ) as $path ) {
			$config = include $path;
			foreach ( self::RETIRED_KEYS as $key ) {
				$this->assertArrayNotHasKey(
					$key,
					$config,
					"$path must not reference retired key '$key'"
				);
			}
		}
	}
}
