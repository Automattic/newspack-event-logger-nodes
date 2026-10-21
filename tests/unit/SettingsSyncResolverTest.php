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

	/** A blank synced APP option resolves to its file-backed default (rules => []). */
	public function test_blank_perf_option_resolves_to_file_default(): void {
		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			'',
			'newspack_event_logger_nodes_rules'
		);
		$this->assertSame( Config::load_config_defaults()['rules'], $resolved );
	}

	/** A `false` APP option (option absent) also resolves to the file default. */
	public function test_false_perf_option_resolves_to_file_default(): void {
		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			false,
			'newspack_event_logger_nodes_rules'
		);
		$this->assertSame( Config::load_config_defaults()['rules'], $resolved );
	}

	/** A non-blank ruleset with only inline/skip rules passes through unchanged. */
	public function test_non_blank_synced_value_passes_through(): void {
		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			[ [ 'id' => 'r', 'pattern' => '/', 'action' => 'log' ] ],
			'newspack_event_logger_nodes_rules'
		);
		$this->assertSame( [ [ 'id' => 'r', 'pattern' => '/', 'action' => 'log' ] ], $resolved );
	}

	/**
	 * A pointer (mc-tier) rule's hooks live in a SEPARATE durable option that
	 * settings-sync never carries; the resolver must inline them so the spoke
	 * receives the ruleset hook-complete (else it logs "hooks missing" forever).
	 */
	public function test_pointer_rule_hooks_are_inlined_for_transport(): void {
		$GLOBALS['_wp_options'] = [
			\Newspack_Event_Logger_Nodes\Rule_Set::hooks_option_name( 'heavy' ) => [ 'init', 'wp', 'shutdown' ],
		];

		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			[ [ 'id' => 'heavy', 'pattern' => '/x/', 'action' => 'log', 'hooks' => null, 'hooks_in' => 'mc' ] ],
			'newspack_event_logger_nodes_rules'
		);

		$this->assertSame( 'inline', $resolved[0]['hooks_in'], 'pointer hooks must be inlined for the wire' );
		$this->assertSame( [ 'init', 'wp', 'shutdown' ], $resolved[0]['hooks'] );
	}

	/** An option outside this plugin's prefix passes through, even one spelled like a short key. */
	public function test_an_unprefixed_option_passes_through_unresolved(): void {
		$this->assertSame( '', newspack_event_logger_nodes_resolve_settings_sync_value( '', 'rules' ) );
		$this->assertFalse( newspack_event_logger_nodes_resolve_settings_sync_value( false, 'newspack_nodes_remote_lifetime' ) );
	}

	/** A blank value for a NON-synced option passes through unchanged (no resolution). */
	public function test_blank_non_synced_option_passes_through(): void {
		$resolved = newspack_event_logger_nodes_resolve_settings_sync_value(
			'',
			'some_unrelated_option'
		);
		$this->assertSame( '', $resolved );
	}
}
