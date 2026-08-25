<?php
/**
 * The application's declared-key schema and its defaults must live in CODE.
 *
 * Deriving either from `newspack-event-logger-nodes-config.php` means an
 * install whose file predates a key declares nothing for it, and the first
 * `Config::value()` read throws "unknown config key" — fataling every request.
 * It also makes an operator's typo self-declaring, which is how the wrong
 * spelling wins silently while the real key falls back.
 *
 * So: Settings_Schema declares every key AND its default, and the config file
 * is a commented ledger of the same values — an override surface, never the
 * definition. These tests hold the two together.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Settings_Schema;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Config as RuntimeConfig;

#[CoversClass( Settings_Schema::class )]
#[CoversClass( Config::class )]
class ConfigSchemaTest extends TestCase {

	/** Overrides seeded here are distinct from every schema default. */
	private const OVERRIDES = [
		'hook_start_priority' => -4242,
		'stats_mirror_node'   => 'drift-canary:partition',
		'log_memory'          => true,
	];

	/** Saved snapshot of the substrate's `Config::$registered_keys` (a process-wide static). */
	private array $saved_registered_keys = [];

	protected function setUp(): void {
		parent::setUp();
		$keys                        = new \ReflectionProperty( RuntimeConfig::class, 'registered_keys' );
		$this->saved_registered_keys = $keys->getValue();
		$GLOBALS['_wp_options']      = [];
	}

	protected function tearDown(): void {
		Config::$read_shipped_config = null;
		Config::reset();
		$keys = new \ReflectionProperty( RuntimeConfig::class, 'registered_keys' );
		$keys->setValue( null, $this->saved_registered_keys );
		$GLOBALS['_wp_options'] = [];
		parent::tearDown();
	}

	/**
	 * Every key the application reads through `Config::value()` is declared —
	 * by this plugin's schema for its own keys, by the substrate's for the
	 * retention axes it reads through the merged view.
	 */
	public function test_the_schema_declares_every_key_the_application_reads(): void {
		$declared = \array_merge(
			Settings_Schema::get()->overlay_keys(),
			\Newspack_Nodes\Settings_Schema::get()->overlay_keys()
		);
		$missing  = [];
		foreach ( self::keys_read_by_the_application() as $key => $where ) {
			if ( ! \in_array( $key, $declared, true ) ) {
				$missing[] = "{$key} (read in {$where})";
			}
		}

		$this->assertSame( [], $missing, "keys read but not declared:\n" . \implode( "\n", $missing ) );
	}

	/** Every declared key carries its default in code, so no file is required. */
	public function test_the_schema_supplies_a_default_for_every_declared_key(): void {
		$defaults = Settings_Schema::get()->defaults();

		$this->assertSame(
			[],
			\array_values( \array_diff( Settings_Schema::get()->overlay_keys(), \array_keys( $defaults ) ) ),
			'a declared key with no code default is null on every install whose file predates it'
		);
	}

	/**
	 * The shipped file's ledger matches the schema, key for key and value for
	 * value. A documented default drifts silently, which is the whole failure
	 * this pair of files exists to close.
	 */
	public function test_the_documented_ledger_matches_the_schema_defaults(): void {
		$schema = Settings_Schema::get()->defaults();
		$ledger = $this->documented_ledger();
		\ksort( $schema );
		\ksort( $ledger );

		$this->assertSame( $schema, $ledger );
	}

	/** The three option-less keys are declared but never rendered. */
	public function test_the_option_less_keys_are_declared_but_never_rendered(): void {
		$schema = Settings_Schema::get();

		foreach ( [ 'custom_colors', 'stats_mirror_node', 'recommended_log_events' ] as $key ) {
			$field = $schema->field_for_short( $key );
			$this->assertNotNull( $field, "{$key} must be declared" );
			$this->assertFalse( $field->ui, "{$key} must never render in the settings page" );
			$this->assertNotContains( 'newspack_event_logger_nodes_' . $key, $schema->setting_option_names() );
		}
	}

	/** The application's keys resolve from the schema with no config file. */
	public function test_the_application_keys_resolve_from_the_schema_without_a_config_file(): void {
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		Config::$read_shipped_config = static fn ( array $base ): array => $base;
		Config::reset();

		$this->assertSame( 'flame-stats:partition', Config::value( 'stats_mirror_node' ) );
		$this->assertSame( -10000, Config::value( 'hook_start_priority' ) );
		$this->assertSame( [], Config::value( 'custom_colors' ) );
		$this->assertContains( 'template_redirect', Config::value( 'recommended_log_events' ) );
		$rules = Config::value( 'rules' );
		$this->assertSame( [ 'pattern' => '/', 'action' => 'log' ], \end( $rules ) );
	}

	/** A config file entry still overrides the schema default, key by key. */
	public function test_a_config_file_entry_overrides_the_schema_default(): void {
		$dir = $this->make_temp_dir();
		$this->use_base_dir( $dir, self::OVERRIDES );

		$this->assertSame( -4242, Config::value( 'hook_start_priority' ) );
		$this->assertSame( 'drift-canary:partition', Config::value( 'stats_mirror_node' ) );
		$this->assertTrue( Config::load_config()['log_memory'] );
	}

	/** A key the schema does not know is an operator typo, and it is NAMED. */
	public function test_unknown_keys_names_the_stray_key(): void {
		$this->assertSame(
			[ 'stats_miror_node' ],
			Config::unknown_keys( [ 'stats_miror_node' => 'flame-stats:partition' ] )
		);
	}

	/** The shipped file names only keys the schema declares. */
	public function test_the_shipped_config_file_only_names_known_keys(): void {
		$this->assertSame( [], Config::unknown_keys( $this->documented_ledger() ) );
	}

	/**
	 * An unknown key in the SHIPPED config file must never fatal the request.
	 *
	 * `setup/newspack-event-logger-nodes.sh` copies the operator's server config
	 * over that exact path after install, so the file is theirs, not ours.
	 * Throwing here runs at `plugins_loaded:-10001` and takes down every request
	 * including wp-admin, recoverable only over SSH. It is reported instead,
	 * rate-limited to stderr.
	 */
	public function test_an_unknown_shipped_key_is_reported_not_thrown(): void {
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		Config::$read_shipped_config = static fn ( array $base ): array =>
			[ ...$base, 'retired_knob' => 'left over from a rename' ];
		Config::reset();

		$this->assertSame( 'flame-stats:partition', Config::value( 'stats_mirror_node' ) );
		$this->assertSame( [ 'retired_knob' ], Config::unrecognized_keys() );
	}

	/** A clean shipped file leaves nothing to report. */
	public function test_a_clean_shipped_file_reports_no_unrecognized_keys(): void {
		Config::$read_shipped_config = static fn ( array $base ): array => $base;
		Config::reset();
		Config::value( 'stats_mirror_node' );

		$this->assertSame( [], Config::unrecognized_keys() );
	}

	/**
	 * A config FILE key never becomes a declared key.
	 *
	 * Deriving the declared set from the file makes an operator's typo
	 * self-declaring, and leaves an install whose file predates a key unable to
	 * read it at all.
	 */
	public function test_a_config_file_key_is_never_declared(): void {
		Config::$read_shipped_config = static fn ( array $base ): array =>
			[ ...$base, 'retired_knob' => 'left over from a rename' ];
		Config::reset();

		$keys = new \ReflectionProperty( RuntimeConfig::class, 'registered_keys' );
		$keys->setValue( null, [] );
		Config::register_config_keys();

		$this->assertArrayNotHasKey( 'retired_knob', $keys->getValue() );
		$this->assertArrayHasKey( 'stats_mirror_node', $keys->getValue() );
	}

	/**
	 * The config file's commented ledger, parsed back into the array it
	 * documents. Every key ships commented out beside its default, so this
	 * reads the `// 'key' => value,` lines — including the multi-line array
	 * defaults, which run until their bracket depth returns to zero.
	 *
	 * @return array<string,mixed>
	 */
	private function documented_ledger(): array {
		$path  = \dirname( __DIR__, 2 ) . '/newspack-event-logger-nodes-config.php';
		$lines = \explode( "\n", (string) \file_get_contents( $path ) );
		$body  = [];
		$depth = 0;
		foreach ( $lines as $line ) {
			if ( 0 === $depth && ! \preg_match( "/^\s*\/\/ +('[a-z0-9_]+'\s*=>.*)$/", $line, $m ) ) {
				continue;
			}
			if ( 0 < $depth && ! \preg_match( '/^\s*\/\/ ?(.*)$/', $line, $m ) ) {
				continue;
			}
			$body[] = $m[1];
			$depth += \substr_count( $m[1], '[' ) - \substr_count( $m[1], ']' );
		}
		$this->assertNotEmpty( $body, 'the config file documents no keys at all' );

		$file = $this->make_temp_dir() . '/ledger.php';
		\file_put_contents( $file, "<?php\nreturn [\n" . \implode( "\n", $body ) . "\n];\n" );
		/** @var array<string,mixed> $ledger */
		$ledger = require $file;
		\unlink( $file );
		return $ledger;
	}

	/**
	 * Scrape every `Config::value( 'key' )` read out of the application source.
	 *
	 * @return array<string,string> key => the file it is read in.
	 */
	private static function keys_read_by_the_application(): array {
		$root  = \dirname( __DIR__, 2 );
		$files = [ $root . '/newspack-event-logger-nodes.php' ];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/includes' )
		);
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		$keys = [];
		foreach ( $files as $file ) {
			\preg_match_all(
				"/(?:Config|RuntimeConfig|self)::value\(\s*'([a-z0-9_]+)'/i",
				(string) \file_get_contents( $file ),
				$matches
			);
			foreach ( $matches[1] as $key ) {
				$keys[ $key ] = \basename( $file );
			}
		}

		return $keys;
	}
	/**
	 * `reset_local_cache()` drops the stray-key record with the config it came
	 * from. Leaving it makes `unrecognized_keys()` report strays from a load the
	 * cached config was never built from — reachable whenever
	 * `load_config_defaults()` takes its no-substrate early return.
	 */
	public function test_reset_local_cache_clears_the_unrecognized_record(): void {
		Config::$read_shipped_config = static fn ( array $base ): array =>
			[ ...$base, 'sunset_knob' => 'from a rename' ];
		Config::reset();
		$this->assertSame( [ 'sunset_knob' ], Config::unrecognized_keys() );

		Config::reset_local_cache();

		$record = new \ReflectionProperty( Config::class, 'unrecognized' );
		$this->assertSame( [], $record->getValue(), 'the record must not outlive its config' );
	}
}
