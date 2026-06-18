<?php
/**
 * Unit tests for the `newspack_nodes/settings_sync/value` resolver callback —
 * the ELN bootstrap filter that resolves a blank/false synced option to its
 * file-backed default (ported from the old Settings_Sync::maybe_queue_static_sync
 * empty→default logic).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class SettingsSyncResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options'] = [];
		if ( \class_exists( Config::class ) ) {
			Config::reset();
		}
	}

	/** A blank PERF option resolves to its file-backed default (log_urls => []). */
	public function test_blank_perf_option_resolves_to_file_default(): void {
		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			'',
			'newspack_event_logger_nodes_log_urls'
		);
		$this->assertSame( Config::load_config_defaults()['log_urls'], $resolved );
	}

	/** A `false` PERF option (option absent) also resolves to the file default. */
	public function test_false_perf_option_resolves_to_file_default(): void {
		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			false,
			'newspack_event_logger_nodes_significant_events'
		);
		$this->assertSame( Config::load_config_defaults()['significant_events'], $resolved );
	}

	/** A non-blank value passes through unchanged for a synced option. */
	public function test_non_blank_synced_value_passes_through(): void {
		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			[ '/foo', '/bar' ],
			'newspack_event_logger_nodes_log_urls'
		);
		$this->assertSame( [ '/foo', '/bar' ], $resolved );
	}

	/** A blank value for a NON-synced option passes through unchanged (no resolution). */
	public function test_blank_non_synced_option_passes_through(): void {
		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			'',
			'some_unrelated_option'
		);
		$this->assertSame( '', $resolved );
	}

	/**
	 * A blank substrate-remap option resolves by the canonical-key rule: strip the
	 * ELN prefix (`remote_num_segments`), drop the `^remote_` segment
	 * (`num_segments`), and look that up in the file defaults — faithful port of
	 * the old prefix-strip logic. When the default key is present the resolver
	 * returns it; when absent it returns the raw blank. Either way the result must
	 * equal `$defaults['num_segments'] ?? ''`, derived the same way the code does.
	 */
	public function test_blank_remap_option_resolves_by_canonical_key(): void {
		$defaults = Config::load_config_defaults();
		$expected = $defaults['num_segments'] ?? '';
		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			'',
			'newspack_event_logger_nodes_remote_num_segments'
		);
		$this->assertSame( $expected, $resolved );
	}
}
