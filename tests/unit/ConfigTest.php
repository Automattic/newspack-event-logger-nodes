<?php
/**
 * Tests for Config (file overlay + WP options + path validation + kill_readers).
 *
 * Ported from `tests/unit/ConfigTest.php` in the legacy `newspack-event-logger-plugins`
 * monorepo, with namespace renames and `kill_readers()` semantics moved here from
 * Supervisor (the legacy home). Reflection is used to exercise private surfaces:
 * `validate_config_path()`, `validate_config_values()`, matches the legacy test
 * pattern so we cover the same code paths.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Nodes\Config_Utils;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass( Config::class )]
class ConfigTest extends TestCase {

	private string $temp_dir;

	/** Saved snapshot of `Config::$allowed_config_dirs` so tests can mutate freely. */
	private array $saved_allowed_dirs = [];

	protected function setUp(): void {
		parent::setUp();
		Config::reset();
		$this->temp_dir = '/tmp/newspack-event-logger-nodes-test-config-' . \uniqid();
		@\mkdir( $this->temp_dir, 0755, true );
		// Clear WP option store between tests.
		$GLOBALS['_wp_options'] = [];
		// Clear any LOCAL_NEWSPACK_NODES_CONF leftover.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' );
		// Snapshot the allowlist; allow_dir() restores from this in tearDown.
		$ref                      = new \ReflectionProperty( Config::class, 'allowed_config_dirs' );
		$this->saved_allowed_dirs = $ref->getValue();
	}

	protected function tearDown(): void {
		$this->rmdir_recursive( $this->temp_dir );
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' );
		$GLOBALS['_wp_options'] = [];
		// Restore allowed_config_dirs in case allow_dir() was used.
		$ref = new \ReflectionProperty( Config::class, 'allowed_config_dirs' );
		$ref->setValue( null, $this->saved_allowed_dirs );
		parent::tearDown();
	}

	// ── load_config: shape + caching ───────────────────────────────────────

	public function test_load_config_returns_array(): void {
		$config = Config::load_config();
		$this->assertIsArray( $config );
	}

	public function test_load_config_has_base_directory(): void {
		// `base_directory` comes from the substrate Config (file → WP option),
		// merged into the application Config so callers see substrate keys
		// via either Config.
		$config = Config::load_config();
		$this->assertArrayHasKey( 'base_directory', $config );
		$this->assertNotEmpty( $config['base_directory'] );
	}

	public function test_load_config_caches_result(): void {
		$config1 = Config::load_config();
		$config2 = Config::load_config();
		$this->assertSame( $config1, $config2 );
	}

	public function test_load_config_includes_substrate_memcache_servers(): void {
		// Substrate-owned key reaches us via the substrate-overlay layer
		// in `load_config()`.
		$config = Config::load_config();
		$this->assertArrayHasKey( 'memcache_servers', $config );
	}

	public function test_memcache_servers_wp_option_override_applies(): void {
		// Stored in its typed array shape (the substrate's sanitize_memcache_servers
		// writes an array; the read path is a raw passthrough since coerce was removed).
		\update_option( 'newspack_nodes_memcache_servers', [ 'test-host:11211' ] );
		Config::reset();
		$this->assertSame( [ 'test-host:11211' ], Config::load_config()['memcache_servers'] );
	}

	public function test_correct_option_autoload_applies_policy(): void {
		// One-time sweep flips existing installs to match autoload_for():
		// hot-path scalars autoloaded; the admin-only discovered_events staging
		// list kept off autoload.
		$GLOBALS['_wp_set_option_autoload'] = [];
		$GLOBALS['_wp_options']             = [];

		Config::correct_option_autoload();

		$this->assertTrue( $GLOBALS['_wp_set_option_autoload']['newspack_event_logger_nodes_enable_logging'] );
		$this->assertFalse( $GLOBALS['_wp_set_option_autoload']['newspack_event_logger_nodes_discovered_events'] );
	}

	public function test_correct_option_autoload_runs_once(): void {
		$GLOBALS['_wp_options'] = [];
		Config::correct_option_autoload();
		$GLOBALS['_wp_set_option_autoload'] = [];
		Config::correct_option_autoload();
		$this->assertSame( [], $GLOBALS['_wp_set_option_autoload'] );
	}

	public function test_reset_clears_cache(): void {
		$config1 = Config::load_config();
		Config::reset();
		$config2 = Config::load_config();
		$this->assertEquals( $config1, $config2 );
	}

	public function test_load_config_defaults_cached(): void {
		Config::reset();
		$d1 = Config::load_config_defaults();
		$d2 = Config::load_config_defaults();
		$this->assertSame( $d1, $d2 );
	}

	// ── File-overlay env override ──────────────────────────────────────────

	public function test_local_env_override_loads_overlay(): void {
		$override_path = $this->temp_dir . '/override.php';
		\file_put_contents(
			$override_path,
			"<?php return [ 'enable_logging' => false, 'hook_start_priority' => 4242 ];\n"
		);
		// Allow temp_dir by hacking the allowlist via reflection (matches
		// legacy bootstrap pattern). Tests need a writable allowed dir.
		$this->allow_dir( $this->temp_dir );

		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $override_path );
		Config::reset();
		$config = Config::load_config();
		$this->assertFalse( $config['enable_logging'] );
		$this->assertSame( 4242, $config['hook_start_priority'] );
	}

	public function test_local_env_override_outside_allowed_dirs_rejected(): void {
		$ref = new \ReflectionMethod( Config::class, 'validate_config_path' );

		// /var/tmp is not in the allowlist (and isn't the plugin dir).
		$outside_dir = '/var/tmp/newspack-nodes-test-evil-' . \uniqid();
		@\mkdir( $outside_dir, 0755, true );
		$path = $outside_dir . '/evil-config.php';
		\file_put_contents( $path, "<?php return [];\n" );

		try {
			$result = $ref->invoke( null, $path );
			$this->assertNull( $result );
		} finally {
			@\unlink( $path );
			@\rmdir( $outside_dir );
		}
	}

	// ── WP option overrides ────────────────────────────────────────────────

	public function test_present_empty_wp_option_overrides_file_default(): void {
		// Presence decides override, not emptiness: a stored '' is a deliberate
		// value and wins over the file default. To get the default back, the
		// option must be deleted (see the absence test below), which is what the
		// admin "reset to defaults" / blank-save path does.
		Config::reset();
		\update_option( 'newspack_event_logger_nodes_hook_start_priority', '' );
		$config = Config::load_config();
		$this->assertSame( '', $config['hook_start_priority'] );
	}

	public function test_absent_wp_option_uses_file_default(): void {
		// Only true absence (no stored row) falls back to the file default.
		Config::reset();
		\delete_option( 'newspack_event_logger_nodes_hook_start_priority' );
		$config = Config::load_config();
		$this->assertSame( -10000, $config['hook_start_priority'] );
	}

	// ── Path/directory accessors ───────────────────────────────────────────

	public function test_get_logs_locks_offsets_dirs(): void {
		\update_option( 'newspack_nodes_base_directory', $this->temp_dir . '/base2' );
		Config::reset();
		$logs    = Config::get_logs_directory();
		$locks   = Config::get_locks_directory();
		$offsets = Config::get_offsets_directory();
		$this->assertSame( $this->temp_dir . '/base2/logs', $logs );
		$this->assertSame( $this->temp_dir . '/base2/locks', $locks );
		$this->assertSame( $this->temp_dir . '/base2/offsets', $offsets );
		$this->assertDirectoryExists( $logs );
		$this->assertDirectoryExists( $locks );
		$this->assertDirectoryExists( $offsets );
	}

	public function test_directories_are_cached(): void {
		\update_option( 'newspack_nodes_base_directory', $this->temp_dir . '/base3' );
		Config::reset();
		$logs1 = Config::get_logs_directory();
		$logs2 = Config::get_logs_directory();
		$this->assertSame( $logs1, $logs2 );
	}

	// ── validate_config_path ──────────────────────────────────────────────

	public function test_validate_config_path_rejects_non_php(): void {
		$ref = new \ReflectionMethod( Config::class, 'validate_config_path' );
		$this->assertNull( $ref->invoke( null, '/tmp/config.txt' ) );
	}

	public function test_validate_config_path_rejects_null_byte(): void {
		$ref = new \ReflectionMethod( Config::class, 'validate_config_path' );
		$this->assertNull( $ref->invoke( null, "/tmp/evil\0config.php" ) );
	}

	// ── validate_config_values ────────────────────────────────────────────

	public function test_validate_config_values_rejects_objects(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'validate_config_values' );
		$this->assertFalse( $ref->invoke( null, new \stdClass() ) );
	}

	public function test_validate_config_values_rejects_deep_nesting(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'validate_config_values' );
		$value = 'leaf';
		for ( $i = 0; $i < 12; $i++ ) {
			$value = [ $value ];
		}
		$this->assertFalse( $ref->invoke( null, $value ) );
	}

	public function test_validate_config_values_allows_scalars(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'validate_config_values' );
		$this->assertTrue( $ref->invoke( null, 'string' ) );
		$this->assertTrue( $ref->invoke( null, 42 ) );
		$this->assertTrue( $ref->invoke( null, 3.14 ) );
		$this->assertTrue( $ref->invoke( null, true ) );
		$this->assertTrue( $ref->invoke( null, null ) );
	}

	public function test_validate_config_values_allows_arrays(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'validate_config_values' );
		$this->assertTrue( $ref->invoke( null, [ 'a', 'b' ] ) );
		$this->assertTrue( $ref->invoke( null, [ 'nested' => [ 'k' => 'v' ] ] ) );
	}

	// ── get_custom_colors ──────────────────────────────────────────────────

	public function test_get_custom_colors_returns_array(): void {
		$colors = Config::get_custom_colors();
		$this->assertIsArray( $colors );
	}

	public function test_get_custom_colors_returns_sorted(): void {
		$colors = Config::get_custom_colors();
		$keys   = \array_keys( $colors );
		$sorted = $keys;
		\sort( $sorted, SORT_NATURAL | SORT_FLAG_CASE );
		$this->assertSame( $sorted, $keys );
	}

	public function test_get_custom_colors_merges_discovered_events(): void {
		\update_option( 'newspack_event_logger_nodes_discovered_events', [ 'remote_hook' => '#ff0000' ] );
		Config::reset();
		$colors = Config::get_custom_colors();
		$this->assertArrayHasKey( 'remote_hook', $colors );
		$this->assertSame( '#ff0000', $colors['remote_hook'] );
	}

	public function test_get_custom_colors_handles_non_string_discovered(): void {
		\update_option( 'newspack_event_logger_nodes_discovered_events', [ 'h' => 123 ] );
		Config::reset();
		$colors = Config::get_custom_colors();
		$this->assertArrayHasKey( 'h', $colors );
		$this->assertSame( '#ffa726', $colors['h'] );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Append a directory to the allowed-config-dirs allowlist for the
	 * duration of the test. Used by `test_local_env_override_loads_overlay`
	 * so a writable temp dir can host the override file. Mirrors the legacy
	 * test bootstrap's reflection hack.
	 */
	private function allow_dir( string $dir ): void {
		$ref  = new \ReflectionProperty( Config::class, 'allowed_config_dirs' );
		$dirs   = $ref->getValue();
		$dirs[] = $dir;
		$ref->setValue( null, $dirs );
	}
}
