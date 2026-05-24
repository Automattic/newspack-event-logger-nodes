<?php
/**
 * Tests for the `eln` topology-token namespace.
 *
 * The substrate resolves `<ns:key>` tokens via per-namespace resolvers
 * (Core::register_config_namespace / resolve_config_token). This plugin
 * registers an `eln` namespace for its six app-specific tokens
 * (is_hub, auto_disable_threshold, auto_protect_time_threshold,
 * aggregator_require_https, aggregator_verify_ssl, significant_events_csv)
 * so `<eln:KEY>` resolves to the same value the old merged-config
 * `<config:KEY>` produced. Keys it does not own resolve to ''.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class ElnConfigTokenTest extends TestCase {

	/** Snapshot of the process-lifetime resolver registry, restored in tearDown. */
	private array $saved_resolvers;

	protected function setUp(): void {
		parent::setUp();
		$this->saved_resolvers  = Core::$config_resolvers;
		$GLOBALS['_wp_options'] = [];
		Config::reset();
	}

	protected function tearDown(): void {
		Core::$config_resolvers = $this->saved_resolvers;
		$GLOBALS['_wp_options'] = [];
		Config::reset();
		parent::tearDown();
	}

	public function test_eln_namespace_resolves_owned_schema_key_from_wp_option(): void {
		// auto_disable_threshold is a real load_config() schema key (int); the
		// WP option overlays the file default and the `eln` resolver returns it.
		\update_option( 'newspack_event_logger_nodes_auto_disable_threshold', '17' );
		Config::reset();
		$this->assertSame( '17', Core::resolve_config_token( 'eln', 'auto_disable_threshold' ) );
	}

	public function test_eln_namespace_does_not_own_substrate_key(): void {
		// logs_dir is substrate-owned (the `config` namespace), not ELN's —
		// resolving it through the `eln` namespace yields ''.
		$this->assertSame( '', Core::resolve_config_token( 'eln', 'logs_dir' ) );
	}
}
