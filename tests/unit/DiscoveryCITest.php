<?php
/**
 * DiscoveryCITest: unit tests for Discovery_CI, the M2 service-CI that
 * replaces the legacy DiscoveryController.
 *
 * Asserts value-equivalence with the legacy `get_discovery()` payload —
 * same `registered_hooks` / `custom_events` shape, same normalization,
 * same custom-out-of-registered filter. Config is seeded via the per-test
 * config file pattern from `TestCase::use_base_dir()` so the substrate's
 * `Newspack_Nodes\Config::load_config()` reads from a fresh, isolated
 * config blob per test.
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
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$GLOBALS['_wp_options'] = [];
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Seed the durable ruleset the discovery union reads. `get` now sources its
	 * payload from the LOG rules' instrumented hooks + custom events, not the
	 * retired global log_events / custom_events options.
	 *
	 * @param string[] $hooks  Inline instrumented-hook list.
	 * @param string[] $custom Custom-event list.
	 */
	private function set_rule( array $hooks, array $custom ): void {
		\update_option( Rule_Set::OPTION_RULES, [
			[ 'id' => 'r', 'pattern' => '/', 'action' => 'log', 'hooks' => $hooks, 'custom_events' => $custom ],
		] );
	}

	public function test_get_verb_returns_registered_hooks_and_custom_events(): void {
		$this->set_rule( [ 'init', 'wp_loaded', 'shutdown' ], [ 'my_event' ] );
		$interpreter = new Discovery_CI_Node();

		$result = VerbHarness::fire( $interpreter, 'discovery', 'get' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'registered_hooks', $result );
		$this->assertArrayHasKey( 'custom_events', $result );
		$this->assertSame( [ 'init', 'wp_loaded', 'shutdown' ], $result['registered_hooks'] );
		$this->assertSame( [ 'my_event' ], $result['custom_events'] );
	}

	public function test_custom_events_filtered_out_of_registered_hooks(): void {
		// A hook that is ALSO a custom event must not leak into registered_hooks.
		$this->set_rule( [ 'my_event', 'init' ], [ 'my_event' ] );
		$interpreter = new Discovery_CI_Node();

		$result = VerbHarness::fire( $interpreter, 'discovery', 'get' );

		$this->assertSame( [ 'init' ], $result['registered_hooks'] );
		$this->assertSame( [ 'my_event' ], $result['custom_events'] );
	}

	public function test_get_verb_unions_hooks_and_custom_events_across_rules(): void {
		// The payload is the union across every LOG rule.
		\update_option( Rule_Set::OPTION_RULES, [
			[ 'id' => 'a', 'pattern' => '/a/', 'action' => 'log', 'hooks' => [ 'init' ], 'custom_events' => [ 'a' ] ],
			[ 'id' => 'b', 'pattern' => '/b/', 'action' => 'log', 'hooks' => [ 'shutdown' ], 'custom_events' => [ 'b' ] ],
		] );
		$interpreter = new Discovery_CI_Node();

		$result = VerbHarness::fire( $interpreter, 'discovery', 'get' );

		$this->assertContains( 'init', $result['registered_hooks'] );
		$this->assertContains( 'shutdown', $result['registered_hooks'] );
		$this->assertSame( [ 'a', 'b' ], $result['custom_events'] );
	}

	public function test_get_verb_dedupes_and_drops_empty_strings(): void {
		$this->set_rule( [ '', 'init', '', 'init' ], [] );
		$interpreter = new Discovery_CI_Node();

		$result = VerbHarness::fire( $interpreter, 'discovery', 'get' );

		$this->assertSame( [ 'init' ], $result['registered_hooks'] );
		$this->assertSame( [], $result['custom_events'] );
	}

	public function test_get_verb_returns_empty_lists_when_no_log_rules_instrument_anything(): void {
		// The minimal log-all baseline instruments no hooks / custom events.
		$this->set_rule( [], [] );
		$interpreter = new Discovery_CI_Node();

		$result = VerbHarness::fire( $interpreter, 'discovery', 'get' );

		$this->assertSame( [], $result['registered_hooks'] );
		$this->assertSame( [], $result['custom_events'] );
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
