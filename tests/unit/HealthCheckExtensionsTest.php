<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\HealthCheckExtensions;
use Newspack_Event_Logger_Nodes\SettingsSync;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( HealthCheckExtensions::class )]
class HealthCheckExtensionsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options'] = [];
		$GLOBALS['_wp_actions'] = [];
		SettingsSync::suppress_sync( false );
		if ( \class_exists( Config::class ) ) {
			Config::reset();
		}
	}

	public function test_max_events_constant(): void {
		$ref = new \ReflectionClassConstant( HealthCheckExtensions::class, 'MAX_EVENTS' );
		$this->assertSame( 10000, $ref->getValue() );
	}

	public function test_process_discovery_merges_remote_hooks_into_log_events(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'wp_loaded' ];

		HealthCheckExtensions::process_discovery( [
			'site-a' => [
				'registered_hooks' => [ 'wp_loaded', 'init', 'wp_footer' ],
			],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] ?? [];
		$this->assertContains( 'wp_loaded', $result );
		$this->assertContains( 'init', $result );
		$this->assertContains( 'wp_footer', $result );
		// No duplicates.
		$this->assertSame( \count( $result ), \count( \array_unique( $result ) ) );
	}

	public function test_process_discovery_excludes_custom_events_from_log_events(): void {
		// `my_custom` is registered as a custom_event and must NOT end up in
		// log_events even if it shows up in registered_hooks.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']    = [ 'init' ];
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = [ 'my_custom' => true ];

		HealthCheckExtensions::process_discovery( [
			'site-a' => [
				'registered_hooks' => [ 'init', 'my_custom', 'wp_footer' ],
			],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertContains( 'init', $result );
		$this->assertContains( 'wp_footer', $result );
		$this->assertNotContains( 'my_custom', $result, 'custom events must not pollute log_events' );
	}

	public function test_process_discovery_merges_custom_events_into_discovered(): void {
		HealthCheckExtensions::process_discovery( [
			'site-a' => [
				'custom_events' => [ 'event_alpha', 'event_beta' ],
			],
			'site-b' => [
				'custom_events' => [ 'event_alpha', 'event_gamma' ],
			],
		] );

		$discovered = $GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] ?? [];
		$this->assertArrayHasKey( 'event_alpha', $discovered );
		$this->assertArrayHasKey( 'event_beta', $discovered );
		$this->assertArrayHasKey( 'event_gamma', $discovered );
		// Each entry maps to bool true.
		$this->assertTrue( $discovered['event_alpha'] );
	}

	public function test_process_discovery_sanitizes_remote_strings(): void {
		// Control characters in the remote payload must be stripped.
		HealthCheckExtensions::process_discovery( [
			'malicious-site' => [
				'registered_hooks' => [ "init\x00bad", "valid_hook\nwith newline" ],
			],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] ?? [];
		// sanitize_text_field strips null bytes + collapses whitespace.
		foreach ( $result as $hook ) {
			$this->assertStringNotContainsString( "\0", $hook );
		}
	}

	public function test_process_discovery_skips_non_string_hooks(): void {
		HealthCheckExtensions::process_discovery( [
			'site-a' => [
				'registered_hooks' => [ 'valid', 123, [ 'nested' ], null, '' ],
			],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] ?? [];
		$this->assertContains( 'valid', $result );
		// Each entry must be a non-empty string.
		foreach ( $result as $hook ) {
			$this->assertIsString( $hook );
			$this->assertNotSame( '', $hook );
		}
	}

	public function test_process_discovery_caps_events_at_max(): void {
		$max_events = ( new \ReflectionClassConstant( HealthCheckExtensions::class, 'MAX_EVENTS' ) )->getValue();
		// Build an oversized payload — must be capped at MAX_EVENTS=10000.
		$huge = [];
		for ( $i = 0; $i < $max_events + 100; $i++ ) {
			$huge[] = "event_{$i}";
		}
		HealthCheckExtensions::process_discovery( [
			'site-a' => [
				'registered_hooks' => $huge,
			],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] ?? [];
		$this->assertLessThanOrEqual( $max_events, \count( $result ) );
	}

	public function test_process_discovery_caps_existing_at_max_when_merging(): void {
		$max_events = ( new \ReflectionClassConstant( HealthCheckExtensions::class, 'MAX_EVENTS' ) )->getValue();
		// Existing already at max — adding more should be a no-op (the merge
		// breaks once the cap is hit, so the existing list stays intact).
		$existing = [];
		for ( $i = 0; $i < $max_events; $i++ ) {
			$existing[] = "existing_{$i}";
		}
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = $existing;

		HealthCheckExtensions::process_discovery( [
			'site-a' => [ 'registered_hooks' => [ 'new_one' ] ],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertSame( $max_events, \count( $result ) );
	}

	public function test_process_discovery_handles_missing_keys(): void {
		// Server with no registered_hooks / custom_events at all — must not crash.
		HealthCheckExtensions::process_discovery( [
			'site-a' => [ 'lag' => 100 ],
		] );

		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_log_events', $GLOBALS['_wp_options'] );
		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_discovered_events', $GLOBALS['_wp_options'] );
	}

	public function test_process_discovery_handles_non_array_data_per_server(): void {
		// Hostile payload — non-array values for a server. Must skip and not crash.
		HealthCheckExtensions::process_discovery( [
			'site-a' => 'not an array',
			'site-b' => 42,
			'site-c' => null,
		] );
		$this->assertTrue( true );
	}

	public function test_process_discovery_suppresses_settings_sync_during_write(): void {
		// Call process_discovery and assert the static guard is OFF after the
		// merge — i.e., the finally block restored it. (It's set TRUE during
		// update_option but cleared by the time we get back.)
		$this->assertFalse( SettingsSync::is_sync_suppressed() );

		HealthCheckExtensions::process_discovery( [
			'site-a' => [ 'registered_hooks' => [ 'init' ] ],
		] );

		$this->assertFalse(
			SettingsSync::is_sync_suppressed(),
			'suppress_sync must be cleared in the finally block after the merge'
		);
	}

	public function test_process_discovery_normalizes_associative_existing(): void {
		// Existing is an associative array (key => bool) — process must
		// normalize to flat indexed when merging.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [
			'init'      => true,
			'wp_loaded' => true,
		];

		HealthCheckExtensions::process_discovery( [
			'site-a' => [ 'registered_hooks' => [ 'wp_footer' ] ],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertContains( 'init', $result );
		$this->assertContains( 'wp_loaded', $result );
		$this->assertContains( 'wp_footer', $result );
	}

	public function test_process_discovery_skips_when_no_hooks_or_events(): void {
		// Empty payload — neither merge_hooks nor merge_events should write.
		HealthCheckExtensions::process_discovery( [
			'site-a' => [
				'registered_hooks' => [],
				'custom_events'    => [],
			],
		] );
		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_log_events', $GLOBALS['_wp_options'] );
		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_discovered_events', $GLOBALS['_wp_options'] );
	}

	public function test_process_discovery_does_not_duplicate_existing_hooks(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init', 'wp_loaded' ];

		HealthCheckExtensions::process_discovery( [
			'site-a' => [ 'registered_hooks' => [ 'init', 'wp_loaded', 'init' ] ],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'];
		$this->assertSame( [ 'init', 'wp_loaded' ], $result, 'existing entries must not be duplicated' );
	}

	// =========================================================================
	// Coverage: array_slice cap on custom_events, non-array option fallbacks,
	// indexed-string custom_lookup entry, merge_events MAX cap.
	// =========================================================================

	public function test_process_discovery_caps_custom_events_at_max(): void {
		// Existing test caps hooks; this one drives the parallel branch for
		// custom_events. Push a payload larger than MAX_EVENTS through the
		// custom_events path and assert the merged result is bounded.
		$max_events = ( new \ReflectionClassConstant( HealthCheckExtensions::class, 'MAX_EVENTS' ) )->getValue();
		$huge = [];
		for ( $i = 0; $i < $max_events + 25; $i++ ) {
			$huge[] = "evt_{$i}";
		}

		HealthCheckExtensions::process_discovery( [
			'site-a' => [ 'custom_events' => $huge ],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] ?? [];
		$this->assertLessThanOrEqual( $max_events, \count( $result ) );
	}

	public function test_merge_hooks_treats_non_array_log_events_as_empty(): void {
		// Hostile option shape: log_events set to a scalar — must be treated
		// as `[]` so the merge proceeds cleanly.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = 'not-an-array';

		HealthCheckExtensions::process_discovery( [
			'site-a' => [ 'registered_hooks' => [ 'init' ] ],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] ?? [];
		$this->assertContains( 'init', $result, 'init must be added even when existing option was malformed' );
		// No duplicate scalar contamination — every entry is a string.
		foreach ( $result as $entry ) {
			$this->assertIsString( $entry );
		}
	}

	public function test_merge_hooks_treats_non_array_custom_events_as_empty(): void {
		// Hostile option shape: custom_events set to a scalar — must coerce
		// the lookup table to empty and the merge proceeds.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = 42;
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']    = [];

		HealthCheckExtensions::process_discovery( [
			'site-a' => [ 'registered_hooks' => [ 'init', 'my_custom' ] ],
		] );

		// Without the custom_lookup, all hooks land in log_events.
		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] ?? [];
		$this->assertContains( 'init', $result );
		$this->assertContains( 'my_custom', $result, 'no custom_lookup means my_custom is treated as a regular hook' );
	}

	public function test_merge_hooks_custom_lookup_handles_indexed_string_entries(): void {
		// custom_events stored as indexed array of strings (alternative legal
		// shape). The string-value branch of the custom_lookup loop must catch
		// these.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_custom_events'] = [
			'event_alpha',  // indexed key 0 → string value branch
			'event_beta',   // indexed key 1 → string value branch
		];
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events']    = [];

		HealthCheckExtensions::process_discovery( [
			'site-a' => [
				'registered_hooks' => [ 'init', 'event_alpha', 'event_beta', 'wp_footer' ],
			],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] ?? [];
		// alpha + beta are recognized as customs via the indexed-string branch
		// → excluded from log_events.
		$this->assertNotContains( 'event_alpha', $result );
		$this->assertNotContains( 'event_beta', $result );
		// The non-custom hooks pass through.
		$this->assertContains( 'init', $result );
		$this->assertContains( 'wp_footer', $result );
	}

	public function test_merge_events_treats_non_array_discovered_as_empty(): void {
		// discovered_events option set to a scalar — must reset to [] before
		// merging custom_events.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] = 'bogus';

		HealthCheckExtensions::process_discovery( [
			'site-a' => [ 'custom_events' => [ 'event_alpha' ] ],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] ?? [];
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'event_alpha', $result );
	}

	public function test_merge_events_breaks_when_existing_at_max_cap(): void {
		// discovered already at MAX_EVENTS — adding more must hit the
		// `break;` and leave the existing list at exactly MAX_EVENTS.
		$max_events = ( new \ReflectionClassConstant( HealthCheckExtensions::class, 'MAX_EVENTS' ) )->getValue();
		$existing = [];
		for ( $i = 0; $i < $max_events; $i++ ) {
			$existing[ "existing_{$i}" ] = true;
		}
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'] = $existing;

		HealthCheckExtensions::process_discovery( [
			'site-a' => [ 'custom_events' => [ 'overflow_one', 'overflow_two' ] ],
		] );

		$result = $GLOBALS['_wp_options']['newspack_event_logger_nodes_discovered_events'];
		$this->assertSame( $max_events, \count( $result ), 'cap must hold — break short-circuits the add' );
		$this->assertArrayNotHasKey( 'overflow_one', $result );
	}
}
