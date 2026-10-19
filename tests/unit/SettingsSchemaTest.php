<?php
/**
 * SettingsSchemaTest: the ELN config declaration parity net.
 *
 * Pins the three arrays the single Settings_Schema derives for Config and
 * Admin: the overlay keys, the option names and the delete-on-blank options.
 * The literals below are the authoritative sets; if the Schema drifts from
 * them, a consumer breaks.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Settings_Schema;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[CoversClass( Settings_Schema::class )]
class SettingsSchemaTest extends TestCase {

	/**
	 * The unprefixed overlay key-set. The seven per-URL settings
	 * (log_urls/skip_urls/log_events/custom_events/significant_events/
	 * auto_disable_threshold/auto_protect_time_threshold) are per-rule fields,
	 * not keys.
	 *
	 * Seven keys, because the schema declares every key the application has:
	 * `custom_colors` and `recommended_log_events` carry no
	 * option and no settings field, but a key declared only by the config file
	 * is null on every install whose file predates it.
	 */
	private const OVERLAY_KEYS = [
		'enable_logging',
		'log_memory',
		'flush_every_line',
		'hook_start_priority',
		// The per-URL ruleset overlays the config file (so `$config['rules']`
		// can seed it) but is not a settings-form field — the rules editor
		// owns its option, not the WP Settings API.
		'rules',
		'custom_colors',
		'recommended_log_events',
	];

	/** The prefixed settings-form option names. */
	private const OPTION_NAMES = [
		'newspack_event_logger_nodes_enable_logging',
		'newspack_event_logger_nodes_log_memory',
		'newspack_event_logger_nodes_flush_every_line',
	];

	/**
	 * The prefixed delete-on-blank subset: no settings Field is delete-on-blank.
	 */
	private const DELETE_ON_BLANK = [];

	protected function setUp(): void {
		parent::setUp();

		$schema = new \ReflectionProperty( Settings_Schema::class, 'schema' );
		$schema->setValue( null, null );
	}

	public function test_overlay_keys_match_legacy_option_schema(): void {
		$this->assertSameSet( self::OVERLAY_KEYS, Settings_Schema::get()->overlay_keys() );
	}

	public function test_setting_option_names_match_legacy_option_names(): void {
		$this->assertSameSet( self::OPTION_NAMES, Settings_Schema::get()->setting_option_names() );
	}

	public function test_setting_option_names_exclude_overlay_only_keys(): void {
		// custom_colors + hook_start_priority overlay but are not settings-form fields.
		$names = Settings_Schema::get()->setting_option_names();
		$this->assertNotContains( 'newspack_event_logger_nodes_custom_colors', $names );
		$this->assertNotContains( 'newspack_event_logger_nodes_hook_start_priority', $names );
	}

	public function test_rules_overlays_but_is_not_a_settings_field(): void {
		$schema = Settings_Schema::get();
		$this->assertContains( 'rules', $schema->overlay_keys() );
		$this->assertNotContains( 'newspack_event_logger_nodes_rules', $schema->setting_option_names() );
	}

	public function test_delete_on_blank_options_match_legacy(): void {
		$this->assertSameSet( self::DELETE_ON_BLANK, Settings_Schema::get()->delete_on_blank_options() );
	}

	public function test_prefix_is_the_eln_prefix(): void {
		$this->assertSame( 'newspack_event_logger_nodes_', Settings_Schema::get()->prefix() );
	}

	public function test_get_is_memoized(): void {
		$this->assertSame( Settings_Schema::get(), Settings_Schema::get() );
	}

	/** Order-independent set equality (the overlay/reset sweeps treat these as sets). */
	private function assertSameSet( array $expected, array $actual ): void {
		\sort( $expected );
		\sort( $actual );
		$this->assertSame( $expected, $actual );
	}
}
