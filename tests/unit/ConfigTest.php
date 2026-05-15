<?php
/**
 * Tests for Config (file overlay + WP options + path validation + kill_readers).
 *
 * Ported from `tests/unit/ConfigTest.php` in the legacy `newspack-event-logger-plugins`
 * monorepo, with namespace renames and `kill_readers()` semantics moved here from
 * Supervisor (the legacy home). Reflection is used to exercise private surfaces:
 * `validate_config_path()`, `validate_config_values()`, and `sanitize_option()` —
 * matches the legacy test pattern so we cover the same code paths.
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
		$ref->setAccessible( true );
		$this->saved_allowed_dirs = $ref->getValue();
	}

	protected function tearDown(): void {
		$this->rmdir_recursive( $this->temp_dir );
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' );
		$GLOBALS['_wp_options'] = [];
		// Restore allowed_config_dirs in case allow_dir() was used.
		$ref = new \ReflectionProperty( Config::class, 'allowed_config_dirs' );
		$ref->setAccessible( true );
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
		\update_option( 'newspack_nodes_memcache_servers', 'test-host:11211' );
		Config::reset();
		$this->assertSame( [ 'test-host:11211' ], Config::load_config()['memcache_servers'] );
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
		$ref->setAccessible( true );

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

	public function test_wp_option_override_takes_effect(): void {
		Config::reset();
		\update_option( 'newspack_event_logger_nodes_hook_start_priority', '8' );
		$config = Config::load_config();
		$this->assertSame( 8, $config['hook_start_priority'] );
	}

	public function test_wp_option_invalid_int_falls_back_to_default(): void {
		Config::reset();
		\update_option( 'newspack_event_logger_nodes_hook_start_priority', 'not-a-number' );
		$config = Config::load_config();
		// Should keep the default from the config file, not the invalid WP option.
		$this->assertSame( -10000, $config['hook_start_priority'] );
	}

	public function test_empty_wp_option_uses_file_default(): void {
		Config::reset();
		\update_option( 'newspack_event_logger_nodes_hook_start_priority', '' );
		$config = Config::load_config();
		$this->assertSame( -10000, $config['hook_start_priority'] );
	}

	// ── Path/directory accessors ───────────────────────────────────────────

	public function test_get_base_directory_creates_dir(): void {
		// `base_directory` is a substrate key — the WP option name is the
		// substrate-prefixed `newspack_nodes_base_directory`. The application
		// Config's `get_base_directory()` delegates to the substrate.
		\update_option( 'newspack_nodes_base_directory', $this->temp_dir . '/base' );
		Config::reset();
		$base = Config::get_base_directory();
		$this->assertSame( $this->temp_dir . '/base', $base );
		$this->assertDirectoryExists( $base );
	}

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

	public function test_ensure_path_creates_nested(): void {
		$path   = $this->temp_dir . '/sub/deep/dir';
		$result = Config::ensure_path( $path );
		$this->assertDirectoryExists( $path );
		$this->assertSame( $path, $result );
	}

	public function test_ensure_path_strips_trailing_slash(): void {
		$path = $this->temp_dir . '/trailing';
		@\mkdir( $path, 0755, true );
		$result = Config::ensure_path( $path . '/' );
		$this->assertSame( $path, $result );
	}

	public function test_ensure_path_rejects_null_byte(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'null byte' );
		Config::ensure_path( "/tmp/evil\0path" );
	}

	// ── sanitize_option: type matrix ──────────────────────────────────────

	public function test_sanitize_option_bool_truthy(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertTrue( $ref->invoke( null, '1', 'bool' ) );
	}

	public function test_sanitize_option_bool_falsy(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertFalse( $ref->invoke( null, '', 'bool' ) );
	}

	public function test_sanitize_option_int_valid(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertSame( 42, $ref->invoke( null, '42', 'int' ) );
	}

	public function test_sanitize_option_int_rejects_non_numeric(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, 'abc', 'int' ) );
	}

	public function test_sanitize_option_float_valid(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertSame( 3.14, $ref->invoke( null, '3.14', 'float' ) );
	}

	public function test_sanitize_option_float_rejects_non_numeric(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, 'not-a-number', 'float' ) );
	}

	public function test_sanitize_option_path_accepts_valid_absolute(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertSame( '/var/www/html', $ref->invoke( null, '/var/www/html', 'path' ) );
	}

	public function test_sanitize_option_path_trims_whitespace(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertSame( '/var/log', $ref->invoke( null, '  /var/log  ', 'path' ) );
	}

	public function test_sanitize_option_path_rejects_relative(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, 'relative/path', 'path' ) );
	}

	public function test_sanitize_option_path_rejects_null_byte(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, "/tmp/evil\0path", 'path' ) );
	}

	public function test_sanitize_option_path_rejects_traversal(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, '/tmp/../etc/passwd', 'path' ) );
	}

	public function test_sanitize_option_path_rejects_non_string(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, 12345, 'path' ) );
	}

	public function test_sanitize_option_memcache_servers_valid(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertSame(
			[ 'host1:11211', 'host2:11212' ],
			$ref->invoke( null, "host1:11211\nhost2:11212", 'memcache_servers' )
		);
	}

	public function test_sanitize_option_memcache_servers_filters_invalid(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertSame(
			[ 'valid:11211', 'ok:1234' ],
			$ref->invoke( null, "valid:11211\ninvalid\nhost@bad:999999\nok:1234", 'memcache_servers' )
		);
	}

	public function test_sanitize_option_memcache_servers_empty_string(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, '', 'memcache_servers' ) );
	}

	public function test_sanitize_option_memcache_servers_rejects_non_string(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, 12345, 'memcache_servers' ) );
	}

	public function test_sanitize_option_array_strings_valid(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$result = $ref->invoke( null, [ 'k1' => 'v1', 'k2' => 'v2' ], 'array_strings' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'k1', $result );
		$this->assertArrayHasKey( 'k2', $result );
	}

	public function test_sanitize_option_array_strings_with_booleans(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$result = $ref->invoke( null, [ 'enabled' => true, 'disabled' => false ], 'array_strings' );
		$this->assertTrue( $result['enabled'] );
		$this->assertFalse( $result['disabled'] );
	}

	public function test_sanitize_option_array_strings_rejects_non_array(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, 'not-an-array', 'array_strings' ) );
	}

	public function test_sanitize_option_aggregator_servers_valid(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$input = [
			'server1' => [
				'url'           => 'https://example.com/api',
				'auth_username' => 'user',
				'auth_password' => 'pass',
				'enabled'       => true,
			],
		];
		$result = $ref->invoke( null, $input, 'aggregator_servers' );
		$this->assertArrayHasKey( 'server1', $result );
		$this->assertStringStartsWith( 'https://', $result['server1']['url'] );
		$this->assertTrue( $result['server1']['enabled'] );
	}

	public function test_sanitize_option_aggregator_servers_rejects_http(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$input  = [ 'bad' => [ 'url' => 'http://insecure.example.com' ] ];
		$result = $ref->invoke( null, $input, 'aggregator_servers' );
		$this->assertSame( [], $result );
	}

	public function test_sanitize_option_aggregator_servers_rejects_non_array(): void {
		$ref = new \ReflectionMethod( Config::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, 'string', 'aggregator_servers' ) );
	}

	public function test_sanitize_option_unknown_type_returns_null(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'sanitize_option' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, 'value', 'never-heard-of-this-type' ) );
	}

	// ── validate_config_path ──────────────────────────────────────────────

	public function test_validate_config_path_rejects_non_php(): void {
		$ref = new \ReflectionMethod( Config::class, 'validate_config_path' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, '/tmp/config.txt' ) );
	}

	public function test_validate_config_path_rejects_null_byte(): void {
		$ref = new \ReflectionMethod( Config::class, 'validate_config_path' );
		$ref->setAccessible( true );
		$this->assertNull( $ref->invoke( null, "/tmp/evil\0config.php" ) );
	}

	// ── validate_config_values ────────────────────────────────────────────

	public function test_validate_config_values_rejects_objects(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'validate_config_values' );
		$ref->setAccessible( true );
		$this->assertFalse( $ref->invoke( null, new \stdClass() ) );
	}

	public function test_validate_config_values_rejects_deep_nesting(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'validate_config_values' );
		$ref->setAccessible( true );
		$value = 'leaf';
		for ( $i = 0; $i < 12; $i++ ) {
			$value = [ $value ];
		}
		$this->assertFalse( $ref->invoke( null, $value ) );
	}

	public function test_validate_config_values_allows_scalars(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'validate_config_values' );
		$ref->setAccessible( true );
		$this->assertTrue( $ref->invoke( null, 'string' ) );
		$this->assertTrue( $ref->invoke( null, 42 ) );
		$this->assertTrue( $ref->invoke( null, 3.14 ) );
		$this->assertTrue( $ref->invoke( null, true ) );
		$this->assertTrue( $ref->invoke( null, null ) );
	}

	public function test_validate_config_values_allows_arrays(): void {
		$ref = new \ReflectionMethod( Config_Utils::class, 'validate_config_values' );
		$ref->setAccessible( true );
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

	// ── kill_readers ──────────────────────────────────────────────────────

	public function test_kill_readers_empty_array_noop(): void {
		// Should never throw with no groups.
		Config::kill_readers( [] );
		$this->assertTrue( true );
	}

	public function test_kill_readers_no_locks_dir_noop(): void {
		// Even with no locks dir present, kill_readers must not throw.
		\update_option( 'newspack_nodes_base_directory', $this->temp_dir . '/no-locks-base' );
		Config::reset();
		Config::kill_readers( [ 'firehose-workers' ] );
		$this->assertTrue( true );
	}

	public function test_kill_readers_writes_restart_flag_for_partitioned_dir(): void {
		\update_option( 'newspack_nodes_base_directory', $this->temp_dir . '/with-locks' );
		Config::reset();
		$locks = Config::get_locks_directory();

		// Create a partitioned worker lock dir for group `firehose-workers`.
		$lock_dir = "{$locks}/firehose-workers.p0.lock.d";
		@\mkdir( $lock_dir, 0755, true );
		\file_put_contents( "{$lock_dir}/heartbeat", (string) \getmypid() );

		Config::kill_readers( [ 'firehose-workers' ] );

		$this->assertFileExists( "{$lock_dir}/restart" );
	}

	public function test_kill_readers_writes_restart_flag_for_singleton_dir(): void {
		\update_option( 'newspack_nodes_base_directory', $this->temp_dir . '/with-singleton' );
		Config::reset();
		$locks = Config::get_locks_directory();

		// Create a singleton lock dir for group `aggregator`.
		$lock_dir = "{$locks}/aggregator.lock.d";
		@\mkdir( $lock_dir, 0755, true );
		\file_put_contents( "{$lock_dir}/heartbeat", (string) \getmypid() );

		Config::kill_readers( [ 'aggregator' ] );

		$this->assertFileExists( "{$lock_dir}/restart" );
	}

	public function test_kill_readers_skips_non_matching_groups(): void {
		\update_option( 'newspack_nodes_base_directory', $this->temp_dir . '/skip-test' );
		Config::reset();
		$locks = Config::get_locks_directory();

		$other = "{$locks}/some-other-worker.p0.lock.d";
		@\mkdir( $other, 0755, true );
		\file_put_contents( "{$other}/heartbeat", (string) \getmypid() );

		Config::kill_readers( [ 'firehose-workers' ] );

		// Should NOT have written a restart flag to the unrelated dir.
		$this->assertFileDoesNotExist( "{$other}/restart" );
	}

	public function test_kill_readers_ignores_non_lock_entries(): void {
		\update_option( 'newspack_nodes_base_directory', $this->temp_dir . '/non-lock' );
		Config::reset();
		$locks = Config::get_locks_directory();

		// A regular file with the right prefix but wrong suffix.
		\file_put_contents( "{$locks}/firehose-workers.p0.txt", 'x' );
		// A directory with the right prefix but wrong suffix.
		@\mkdir( "{$locks}/firehose-workers.p0.notlock", 0755, true );

		// Should not throw, and shouldn't write into either entry.
		Config::kill_readers( [ 'firehose-workers' ] );

		$this->assertFileDoesNotExist( "{$locks}/firehose-workers.p0.txt/restart" );
		$this->assertFileDoesNotExist( "{$locks}/firehose-workers.p0.notlock/restart" );
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
		$ref->setAccessible( true );
		$dirs   = $ref->getValue();
		$dirs[] = $dir;
		$ref->setValue( null, $dirs );
	}
}
