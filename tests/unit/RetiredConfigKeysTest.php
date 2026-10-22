<?php
/**
 * Guard test: each key in `RETIRED_KEYS` is absent from the application
 * Settings_Schema, from the baseline test config and from every per-test
 * config fixture.
 *
 * Neither `enable_workers` nor `enable_aggregator` is a key: a hub declares
 * itself through `var is_hub = 1` in an active topology's top-level
 * frontmatter, not through an operator switch. Either one in a test fixture
 * suggests it gates something, which it does not. `log_memory` and
 * `flush_every_line` are switches on each rule, as the row switches are.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Tests\TestCase;

class RetiredConfigKeysTest extends TestCase {

	/** Keys the application Settings_Schema and the test configs must not carry. */
	private const RETIRED_KEYS = [
		'enable_workers',
		'enable_aggregator',
		// Per-RULE fields inside the `newspack_event_logger_nodes_rules` option,
		// NOT global options.
		'log_urls',
		'skip_urls',
		'log_events',
		'custom_events',
		'significant_events',
		'auto_disable_threshold',
		'auto_protect_time_threshold',
		// Per-RULE switches, NOT global options.
		'log_memory',
		'flush_every_line',
		// Stats live in named Tables, so no stats-mirror key exists.
		'stats_mirror_read_budget_ms',
		'stats_mirror_node',
		'stats_mirror_lifetime',
		'stats_mirror_segment_size',
		'stats_mirror_num_segments',
	];

	public function test_schema_defines_no_retired_key(): void {
		$defaults = \Newspack_Event_Logger_Nodes\Settings_Schema::get()->defaults();
		foreach ( self::RETIRED_KEYS as $key ) {
			$this->assertArrayNotHasKey( $key, $defaults, "retired key '$key' must be gone from the schema" );
		}
	}

	public function test_schema_no_longer_defines_the_retired_ruleset_fields(): void {
		$names = \Newspack_Event_Logger_Nodes\Settings_Schema::get()->setting_option_names();
		foreach ( [ 'log_urls', 'skip_urls', 'log_events', 'custom_events', 'significant_events', 'auto_disable_threshold', 'auto_protect_time_threshold', 'log_memory', 'flush_every_line' ] as $short ) {
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
