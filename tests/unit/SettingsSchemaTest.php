<?php
/**
 * SettingsSchemaTest: the ELN config declaration parity net.
 *
 * Pins that the single Settings_Schema derives the SAME three arrays the old
 * hand-listed Config::$option_schema / Admin::$option_names /
 * Admin::$delete_on_blank_options literals carried — so wiring Config + Admin
 * to the Schema is a refactor, not a behavior change. The literals below are the
 * authoritative pre-migration sets; if the Schema drifts from them, a consumer
 * breaks.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Settings_Schema;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Settings_Schema::class )]
class SettingsSchemaTest extends TestCase {

	/**
	 * The unprefixed overlay key-set after the seven ruleset-absorbed settings
	 * (log_urls/skip_urls/log_events/custom_events/significant_events/
	 * auto_disable_threshold/auto_protect_time_threshold) were retired to
	 * per-rule fields in Task 10.
	 */
	private const OVERLAY_KEYS = [
		'allowed_users',
		'enable_logging',
		'log_memory',
		'flush_every_line',
		'hook_start_priority',
	];

	/** The prefixed settings-form option names surviving the Task 10 retirement. */
	private const OPTION_NAMES = [
		'newspack_event_logger_nodes_enable_logging',
		'newspack_event_logger_nodes_log_memory',
		'newspack_event_logger_nodes_flush_every_line',
	];

	/**
	 * The prefixed delete-on-blank subset. The two auto-tune thresholds were the
	 * only members and are retired, so no settings Field is delete-on-blank now.
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
		// allowed_users + hook_start_priority overlay but are not settings-form fields.
		$names = Settings_Schema::get()->setting_option_names();
		$this->assertNotContains( 'newspack_event_logger_nodes_allowed_users', $names );
		$this->assertNotContains( 'newspack_event_logger_nodes_hook_start_priority', $names );
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
