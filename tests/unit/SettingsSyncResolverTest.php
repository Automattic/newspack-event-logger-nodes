<?php
/**
 * Unit tests for the `newspack_nodes/settings_sync/value` resolver callback —
 * the ELN bootstrap filter that resolves a blank/false synced option to its
 * file-backed default (the empty→default rule applied at sync-emit time).
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
	 * A blank SUBSTRATE option (`newspack_nodes_*`) resolves to the SUBSTRATE's
	 * default, not ELN's. The substrate owns `num_partitions`, so its default lives
	 * in `\Newspack_Nodes\Config` — ELN's own defaults don't carry it, so resolving
	 * against ELN's defaults (the bug) returns the raw `false` and the reset-to-
	 * default never ships.
	 */
	public function test_blank_substrate_option_resolves_to_substrate_default(): void {
		// Live, ELN's own defaults do NOT carry the substrate key num_partitions
		// (the bundled test config masks this by setting it via LOCAL_NEWSPACK_NODES_CONF,
		// which load_config_defaults merges in). Strip it so the test reproduces the
		// live miss: the resolver must reach the SUBSTRATE config for the default.
		$eln_defaults = Config::load_config_defaults();
		unset( $eln_defaults['num_partitions'] );
		$ref = new \ReflectionProperty( Config::class, 'config_defaults' );
		$ref->setValue( null, $eln_defaults );

		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			false,
			'newspack_nodes_num_partitions'
		);
		$this->assertSame( \Newspack_Nodes\Config::load_config_defaults()['num_partitions'], $resolved );
	}

	/**
	 * A remote_* option is a first-class substrate setting with its OWN default
	 * (remote_max_lifespan=3600), distinct from the hub's canonical key
	 * (max_lifespan=86400). The resolver must return the remote default — NOT strip
	 * `^remote_` to the canonical key — so the spoke gets the remote geometry the
	 * operator sees on the Nodes Runtime page, not the hub's retention.
	 */
	public function test_blank_remote_option_resolves_to_its_own_remote_default(): void {
		$defaults = \Newspack_Nodes\Config::load_config_defaults();
		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			false,
			'newspack_nodes_remote_max_lifespan'
		);
		$this->assertSame( $defaults['remote_max_lifespan'], $resolved );
		$this->assertNotSame( $defaults['max_lifespan'], $resolved );
	}
}
