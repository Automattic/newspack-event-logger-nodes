<?php
/**
 * Guard test: retired Config keys must not reappear in test fixtures.
 *
 * `enable_workers` was retired in v0.5.0; `enable_aggregator` was retired in
 * the pull-side cutover — hub-mode is now derived from active-topology
 * membership (`aggregator` in the substrate `topologies` list), not a separate
 * operator switch. Leaving either in test fixtures suggests it still gates
 * something — which it does not.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Tests\TestCase;

class RetiredConfigKeysTest extends TestCase {

	/** Keys retired from the substrate or application schema. */
	private const RETIRED_KEYS = [
		'enable_workers',
		'enable_aggregator',
		// The seven global settings the per-URL ruleset absorbed (Task 10):
		// they are now per-RULE fields inside the `newspack_event_logger_nodes_rules`
		// option, NOT global options.
		'log_urls',
		'skip_urls',
		'log_events',
		'custom_events',
		'significant_events',
		'auto_disable_threshold',
		'auto_protect_time_threshold',
	];

	public function test_schema_no_longer_defines_the_retired_ruleset_fields(): void {
		$names = \Newspack_Event_Logger_Nodes\Settings_Schema::get()->setting_option_names();
		foreach ( [ 'log_urls', 'skip_urls', 'log_events', 'custom_events', 'significant_events', 'auto_disable_threshold', 'auto_protect_time_threshold' ] as $short ) {
			$this->assertNotContains( 'newspack_event_logger_nodes_' . $short, $names, "retired field '$short' must be gone from the schema" );
		}
	}

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
