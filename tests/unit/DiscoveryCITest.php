<?php
/**
 * DiscoveryCITest: unit tests for Discovery_CI_Node, whose `get` verb reports
 * the hooks this spoke's `$wp_filter` holds callbacks on — never the ruleset,
 * which on a spoke is the one the hub pushed. Config is seeded via the
 * per-test config file pattern from `TestCase::use_base_dir()` so the
 * substrate's `Newspack_Nodes\Config::load_config()` reads from a fresh,
 * isolated config blob per test.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Discovery_CI_Node;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[CoversClass( Discovery_CI_Node::class )]
class DiscoveryCITest extends TestCase {
	private string $tmp;

	/** @var mixed The process's `$wp_filter`, restored after each test. */
	private mixed $saved_wp_filter;

	protected function setUp(): void {
		parent::setUp();
		// Service CI verbs are gate-by-default (manage_options) in the substrate;
		// these happy-path verbs run as an authorized admin (deny-path is its own test).
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_options']       = [];
		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS,
		// matching DiscoveryControllerTest.
		$this->tmp = '/tmp/discovery-ci-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		$this->use_base_dir( $this->tmp );
		$this->saved_wp_filter = $GLOBALS['wp_filter'] ?? [];
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$GLOBALS['wp_filter']   = $this->saved_wp_filter;
		$GLOBALS['_wp_options'] = [];
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/**
	 * A `$wp_filter` entry holding one callback, in the shape `WP_Hook` keeps.
	 *
	 * @return \WP_Hook
	 */
	private static function hook_with_callback(): \WP_Hook {
		$hook            = new \WP_Hook();
		$hook->callbacks = [ 10 => [ 'cb' => [ 'function' => 'strlen', 'accepted_args' => 1 ] ] ];
		return $hook;
	}

	public function test_get_reports_a_registered_hook_no_rule_names(): void {
		global $wp_filter;
		\update_option( Rule_Set::OPTION_RULES, [
			[ 'id' => 'r', 'pattern' => '/', 'action' => 'log', 'hooks' => [ 'zeta_rule_only_hook' ] ],
		] );
		$wp_filter = [ 'zeta_publisher_plugin_hook' => self::hook_with_callback() ];

		$result = VerbHarness::fire( new Discovery_CI_Node(), 'discovery', 'get' );

		$this->assertSame( [ 'registered_hooks' => [ 'zeta_publisher_plugin_hook' ] ], $result );
	}

	public function test_get_skips_a_hook_whose_callbacks_were_all_removed(): void {
		global $wp_filter;
		$wp_filter = [
			'zeta_emptied_hook' => new \WP_Hook(),
			'zeta_live_hook'    => self::hook_with_callback(),
		];

		$result = VerbHarness::fire( new Discovery_CI_Node(), 'discovery', 'get' );

		$this->assertSame( [ 'zeta_live_hook' ], $result['registered_hooks'] );
	}

	public function test_get_omits_the_plugins_and_the_substrates_own_hooks(): void {
		global $wp_filter;
		$wp_filter = [
			'newspack_nodes/zeta_spawn'               => self::hook_with_callback(),
			'newspack_event_logger_nodes_zeta_colors' => self::hook_with_callback(),
			'zeta_publisher_plugin_hook'              => self::hook_with_callback(),
		];

		$result = VerbHarness::fire( new Discovery_CI_Node(), 'discovery', 'get' );

		$this->assertSame( [ 'zeta_publisher_plugin_hook' ], $result['registered_hooks'] );
	}

	public function test_get_reports_a_numeric_hook_name_as_a_string(): void {
		global $wp_filter;
		$wp_filter = [ '4417' => self::hook_with_callback() ];

		$result = VerbHarness::fire( new Discovery_CI_Node(), 'discovery', 'get' );

		$this->assertSame( [ '4417' ], $result['registered_hooks'] );
	}

	// ── schema-driven dispatch ─────────────────────────────────────────────

	public function test_extends_service_ci_node(): void {
		$this->assertTrue(
			\is_subclass_of( Discovery_CI_Node::class, \Newspack_Nodes\Service_CI_Node::class ),
			'Discovery_CI_Node must extend Service_CI_Node so its node_schema is auto-wired by the catalog scan.'
		);
	}

	public function test_node_schema_declares_get_verb_with_category(): void {
		$schema = Discovery_CI_Node::node_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'Service', $schema['category'] );
		$this->assertNotEmpty( $schema['description'] );
		$this->assertArrayHasKey( 'commands', $schema );
		$names = \array_column( $schema['commands'], 'name' );
		$this->assertSame( [ 'get' ], $names );
	}
}
