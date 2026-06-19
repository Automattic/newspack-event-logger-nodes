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

	/** The exact unprefixed overlay key-set the old Config::$option_schema carried (12 keys). */
	private const OVERLAY_KEYS = [
		'allowed_users',
		'enable_logging',
		'skip_urls',
		'log_urls',
		'log_events',
		'custom_events',
		'significant_events',
		'auto_disable_threshold',
		'auto_protect_time_threshold',
		'log_memory',
		'flush_every_line',
		'hook_start_priority',
	];

	/** The exact prefixed settings-form option names the old Admin::$option_names carried (13). */
	private const OPTION_NAMES = [
		'newspack_event_logger_nodes_enable_logging',
		'newspack_event_logger_nodes_log_urls',
		'newspack_event_logger_nodes_skip_urls',
		'newspack_event_logger_nodes_log_events',
		'newspack_event_logger_nodes_custom_events',
		'newspack_event_logger_nodes_remote_num_segments',
		'newspack_event_logger_nodes_remote_segment_size',
		'newspack_event_logger_nodes_remote_max_lifespan',
		'newspack_event_logger_nodes_significant_events',
		'newspack_event_logger_nodes_auto_disable_threshold',
		'newspack_event_logger_nodes_auto_protect_time_threshold',
		'newspack_event_logger_nodes_log_memory',
		'newspack_event_logger_nodes_flush_every_line',
	];

	/** The exact prefixed delete-on-blank subset the old Admin::$delete_on_blank_options carried (5). */
	private const DELETE_ON_BLANK = [
		'newspack_event_logger_nodes_auto_disable_threshold',
		'newspack_event_logger_nodes_auto_protect_time_threshold',
		'newspack_event_logger_nodes_remote_num_segments',
		'newspack_event_logger_nodes_remote_segment_size',
		'newspack_event_logger_nodes_remote_max_lifespan',
	];

	protected function setUp(): void {
		parent::setUp();

		$schema = new \ReflectionProperty( Settings_Schema::class, 'schema' );
		$schema->setAccessible( true );
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
