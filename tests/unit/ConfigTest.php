<?php
/**
 * Tests for Config file overlays and WordPress options.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Config_Utils;

#[\PHPUnit\Framework\Attributes\CoversClass( Config::class )]
class ConfigTest extends TestCase {

	private string $temp_dir;

	/** Saved snapshot of the substrate's `Config::$registered_keys` (a process-wide static). */
	private array $saved_registered_keys = [];

	protected function setUp(): void {
		parent::setUp();
		// Blank the env BEFORE the reset: anything that reads config after it
		// (make_temp_dir resolves the base dir) would re-cache the old file.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' );
		Config::reset();
		// The shared helper realpaths, so expectations survive macOS's
		// /tmp -> /private/tmp symlink when Config resolves the base.
		$this->temp_dir = $this->make_temp_dir( 'newspack-event-logger-nodes-test-config-' );
		// Clear WP option store between tests.
		$GLOBALS['_wp_options'] = [];
		// Snapshot the declared-key registry; simulate_unwired_request() empties it.
		$keys                        = new \ReflectionProperty( RuntimeConfig::class, 'registered_keys' );
		$this->saved_registered_keys = $keys->getValue();
	}

	protected function tearDown(): void {
		$this->rmdir_recursive( $this->temp_dir );
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' );
		$GLOBALS['_wp_options'] = [];
		// Restore the declared-key registry (emptied by simulate_unwired_request).
		$keys = new \ReflectionProperty( RuntimeConfig::class, 'registered_keys' );
		$keys->setValue( null, $this->saved_registered_keys );
		parent::tearDown();
	}

	public function test_completed_migration_surface_is_absent(): void {
		$this->assertFalse( \method_exists( Config::class, 'correct_option_autoload' ) );
		$this->assertFalse( \method_exists( Rule_Set::class, 'migrate_from_legacy' ) );
		$this->assertFalse( \defined( Rule_Set::class . '::OPTION_SCHEMA_VERSION' ) );
		$this->assertFalse( \defined( Rule_Set::class . '::SCHEMA_VERSION' ) );
	}

	public function test_activation_hooks_exclude_completed_migrations(): void {
		$callbacks = \array_column( $GLOBALS['_wp_test_activation_hooks'] ?? [], 'callback' );

		foreach (
			[
				[ '\\Newspack_Event_Logger_Nodes\\Config', 'correct_option_autoload' ],
				[ '\\Newspack_Event_Logger_Nodes\\Rule_Set', 'migrate_from_legacy' ],
			] as $completed_migration
		) {
			$this->assertNotContains( $completed_migration, $callbacks );
		}
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

	public function test_substrate_option_wins_shared_file_value_in_application_view(): void {
		$override_path = $this->temp_dir . '/shared-precedence-8317.php';
		\file_put_contents(
			$override_path,
			"<?php return [ 'num_partitions' => 3, 'hook_start_priority' => 42423 ];\n"
		);
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $override_path );
		\update_option( 'newspack_nodes_num_partitions', 8 );
		Config::reset();

		$this->assertSame( 8, RuntimeConfig::load_config()['num_partitions'] );
		$config = Config::load_config();
		$this->assertSame( 8, $config['num_partitions'] );
		$this->assertSame( 42423, $config['hook_start_priority'] );
	}

	// ── stats_retention_seconds: ONE derivation of the retention window ─────

	/**
	 * The window every stats consumer sizes itself by was derived in four
	 * places, each seeded with a literal 86400 — which is the substrate default
	 * for `lifetime`, NOT for `min_lifetime` (43200). A missing key therefore
	 * did not merely fall back, it fell back to double the real window, and the
	 * entry point read the key straight off the array with `??`, bypassing the
	 * fail-loud accessor entirely. 5711 is distinct from 43200, 86400 and 3600.
	 */
	public function test_stats_retention_seconds_reads_the_configured_window(): void {
		$override_path = $this->temp_dir . '/retention-5711.php';
		\file_put_contents( $override_path, "<?php return [ 'min_lifetime' => 5711 ];\n" );
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $override_path );
		Config::reset();

		$this->assertSame( 5711, Config::stats_retention_seconds() );
	}

	public function test_stats_retention_seconds_floors_at_the_stats_prefix_floor(): void {
		// A legal `min_lifetime` of 0 means "keep nothing extra", which is not a
		// usable TTL or time axis; Stats_Store already floored it privately.
		$override_path = $this->temp_dir . '/retention-97.php';
		\file_put_contents( $override_path, "<?php return [ 'min_lifetime' => 97 ];\n" );
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $override_path );
		Config::reset();

		$this->assertSame( \Newspack_Event_Logger_Nodes\Stats_Store::PREFIX_FLOOR, Config::stats_retention_seconds() );
	}

	public function test_substrate_option_does_not_override_application_owned_key(): void {
		$override_path = $this->temp_dir . '/overlapping-ownership-7319.php';
		\file_put_contents(
			$override_path,
			"<?php return [ 'allowed_users' => [ 'application-file-7319' ] ];\n"
		);
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $override_path );
		\update_option( 'newspack_nodes_allowed_users', [ 'substrate-option-2843' ] );
		\delete_option( 'newspack_event_logger_nodes_allowed_users' );
		Config::reset();

		$this->assertSame( [ 'application-file-7319' ], Config::load_config()['allowed_users'] );
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

	public function test_value_reads_substrate_and_app_keys_without_runtime_wiring(): void {
		// The staging fatal: the profiler drop-in flushes its first log line at
		// plugins_loaded:-10001, before the substrate wires its runtime (it never
		// does on a frontend request) and before this plugin's plugins_loaded:11
		// loader registers its keys. Both Configs must self-declare on first read.
		// 7 / true are distinct from the shipped defaults (1 / false).
		$conf = $this->temp_dir . '/unwired.php';
		\file_put_contents( $conf, "<?php return [ 'num_partitions' => 7, 'flush_every_line' => true ];\n" );
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $conf );
		$this->simulate_unwired_request();
		$this->assertSame( 7, Config::value( 'num_partitions' ) );
		$this->assertTrue( Config::value( 'flush_every_line' ) );
	}

	public function test_plugin_file_hooks_declaration_onto_the_substrate_pull(): void {
		// In production this hook is the ONLY thing that declares this plugin's keys,
		// and the plugin file must name the action with a LITERAL (this plugin sorts
		// before newspack-nodes, so the class isn't loadable there to read the const).
		// Assert against the constant: a rename in the substrate — or a dropped
		// registration — otherwise surfaces as "unknown config key" 500s in production
		// while both test suites stay green.
		$hooked = $GLOBALS['_eln_boot_actions'][ RuntimeConfig::DECLARE_ACTION ] ?? [];
		$this->assertContains( [ Config::class, 'register_config_keys' ], $hooked );
	}

	/**
	 * A fresh frontend process: nothing declared, no runtime wired — only the
	 * plugin file's DECLARE_ACTION hook, which is all production has at that point.
	 */
	private function simulate_unwired_request(): void {
		$ref = new \ReflectionProperty( RuntimeConfig::class, 'registered_keys' );
		$ref->setValue( null, [] );
		\add_action( RuntimeConfig::DECLARE_ACTION, [ Config::class, 'register_config_keys' ] );
		Config::reset();
	}

	// ── File-overlay env override ──────────────────────────────────────────

	public function test_local_env_override_loads_external_overlay(): void {
		$override_path = $this->temp_dir . '/override.php';
		\file_put_contents(
			$override_path,
			"<?php return [ 'enable_logging' => false, 'hook_start_priority' => 4242 ];\n"
		);

		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $override_path );
		Config::reset();
		$config = Config::load_config();
		$this->assertFalse( $config['enable_logging'] );
		$this->assertSame( 4242, $config['hook_start_priority'] );
	}

	public function test_invalid_local_env_override_throws(): void {
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->temp_dir . '/missing-config.php' );
		Config::reset();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'LOCAL_NEWSPACK_NODES_CONF' );
		Config::load_config_defaults();
	}

	public function test_non_array_local_env_override_throws(): void {
		$override_path = $this->temp_dir . '/non-array-config.php';
		\file_put_contents(
			$override_path,
			"<?php return 'eln-invalid-config-sentinel-63841';\n"
		);
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $override_path );
		Config::reset();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'config must return array' );
		Config::load_config_defaults();
	}

	// ── value(): fail-loud single-key read over the merged config ───────────

	public function test_value_resolves_substrate_key(): void {
		// A substrate key (declared by the substrate registry) resolves off the
		// MERGED config; 555111 is distinct from the 86400 / 0 defaults it used
		// to fall back to via `?? 86400`.
		$conf = $this->temp_dir . '/value-substrate.php';
		\file_put_contents( $conf, "<?php return [ 'min_lifetime' => 555111 ];\n" );
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $conf );
		Config::reset();
		$this->assertSame( 555111, Config::value( 'min_lifetime' ) );
	}

	public function test_value_resolves_application_key(): void {
		// An ELN-own key (declared via the ELN key registration) resolves off the
		// merged config; 31337 is distinct from the -10000 file default and the
		// old `?? 1` fallback.
		$conf = $this->temp_dir . '/value-app.php';
		\file_put_contents( $conf, "<?php return [ 'hook_start_priority' => 31337 ];\n" );
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $conf );
		Config::reset();
		$this->assertSame( 31337, Config::value( 'hook_start_priority' ) );
	}

	public function test_value_throws_on_undeclared_key(): void {
		$this->expectException( \RuntimeException::class );
		Config::value( 'eln_never_declared_bogus_key' );
	}

	public function test_recommended_log_events_resolves_through_value(): void {
		// The admin-asset enqueue reads this via the fail-loud Config::value()
		// accessor (not `$cfg[...] ?? []`), so the key MUST stay declared. A
		// distinct single-hook override (not the shipped multi-hook default list)
		// proves it round-trips through the merged config.
		$conf = $this->temp_dir . '/value-recommended.php';
		\file_put_contents( $conf, "<?php return [ 'recommended_log_events' => [ 'eln_bespoke_hook_88421' ] ];\n" );
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $conf );
		Config::reset();
		$this->assertSame( [ 'eln_bespoke_hook_88421' ], Config::value( 'recommended_log_events' ) );
	}

	public function test_recommended_log_events_typo_fails_loud(): void {
		// A near-miss key (dropped trailing `s`) throws instead of silently
		// defaulting to [] the way the retired `?? []` read did.
		$this->expectException( \RuntimeException::class );
		Config::value( 'recommended_log_event' );
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
}
