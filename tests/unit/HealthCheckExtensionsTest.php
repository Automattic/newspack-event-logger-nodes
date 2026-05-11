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
}
