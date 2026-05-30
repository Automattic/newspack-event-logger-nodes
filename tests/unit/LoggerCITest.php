<?php
/**
 * LoggerCITest: unit tests for Logger_CI, the M2 service-CI that replaces
 * the legacy LoggerController.
 *
 * Asserts value-equivalence with the legacy `get_config()` / `list_hooks()`
 * payloads (minus the `{data, meta}` REST envelope, which is a controller
 * concern). `config` returns the full filterable substrate config;
 * `hooks` returns a flattened `{ hooks: [{name, category}, ...],
 * categories: {...} }` shape. Substrate config seeded via
 * `TestCase::use_base_dir()`; HookCategorizer is reset per test so cached
 * configurations don't leak between cases.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Logger_CI_Node;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Logger_CI_Node::class )]
class LoggerCITest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS,
		// matching DiscoveryCITest / StatusCITest / SettingsCITest.
		$this->tmp = '/tmp/logger-ci-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		$this->use_base_dir( $this->tmp );
		$GLOBALS['_wp_options'] = [];
		// Reset the wp_filter global so hooks tests start from a clean slate;
		// HookCategorizer::get_registered_hooks reads from $wp_filter, which
		// is shared with other tests in this run.
		$GLOBALS['wp_filter']   = [];
		Hook_Categorizer::clear_cache();
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		Hook_Categorizer::clear_cache();
		$GLOBALS['_wp_options'] = [];
		$GLOBALS['wp_filter']   = [];
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	public function test_config_verb_returns_substrate_config_snapshot(): void {
		$this->use_base_dir( $this->tmp, [
			'num_partitions' => 4,
			'topologies'     => [ 'firehose-workers-and-jobs' ],
		] );
		$interpreter = new Logger_CI_Node();

		$result = VerbHarness::fire( $interpreter, 'logger', 'config' );

		$this->assertIsArray( $result );
		// Echoes the documented substrate keys exposed by the legacy
		// `get_config` endpoint (the React tree populates the settings UI
		// from this single payload).
		$this->assertArrayHasKey( 'num_partitions', $result );
		$this->assertSame( 4, $result['num_partitions'] );
		$this->assertSame( [ 'firehose-workers-and-jobs' ], $result['topologies'] );
	}

	public function test_hooks_verb_returns_categorized_hook_list_and_color_map(): void {
		// Seed wp_filter with two hooks the categorizer will classify into
		// different buckets (init → Lifecycle, save_post → Query & Posts /
		// some category; whatever the JSON config classifies them as).
		$GLOBALS['wp_filter']['init']       = new \WP_Hook();
		$GLOBALS['wp_filter']['init']->callbacks[10]['cb1'] = [ 'function' => 'noop' ];
		$GLOBALS['wp_filter']['save_post']  = new \WP_Hook();
		$GLOBALS['wp_filter']['save_post']->callbacks[10]['cb1'] = [ 'function' => 'noop' ];

		$interpreter = new Logger_CI_Node();

		$result = VerbHarness::fire( $interpreter, 'logger', 'hooks' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'hooks', $result );
		$this->assertArrayHasKey( 'categories', $result );
		// `hooks` is a flat list of {name, category} entries.
		$this->assertIsArray( $result['hooks'] );
		$names = \array_column( $result['hooks'], 'name' );
		$this->assertContains( 'init', $names );
		$this->assertContains( 'save_post', $names );
		foreach ( $result['hooks'] as $entry ) {
			$this->assertArrayHasKey( 'name', $entry );
			$this->assertArrayHasKey( 'category', $entry );
			$this->assertIsString( $entry['name'] );
			$this->assertIsString( $entry['category'] );
		}
		// `categories` is the category=>color map.
		$this->assertIsArray( $result['categories'] );
		$this->assertArrayHasKey( 'Lifecycle', $result['categories'] );
	}

	public function test_hooks_verb_skips_internal_event_logger_hooks(): void {
		// Internal hooks (newspack_nodes_* / newspack_event_logger_nodes_*)
		// must NEVER appear in the operator-facing hook picker — they're a
		// recursion hazard (Core::hook_start would re-enter via Config), and
		// instrumenting our own filters is a no-op even if it didn't loop.
		$GLOBALS['wp_filter']['newspack_nodes_option_schema_core'] = new \WP_Hook();
		$GLOBALS['wp_filter']['newspack_nodes_option_schema_core']->callbacks[10]['cb1'] = [ 'function' => 'noop' ];
		$GLOBALS['wp_filter']['init'] = new \WP_Hook();
		$GLOBALS['wp_filter']['init']->callbacks[10]['cb1'] = [ 'function' => 'noop' ];

		$interpreter = new Logger_CI_Node();

		$result = VerbHarness::fire( $interpreter, 'logger', 'hooks' );

		$names = \array_column( $result['hooks'], 'name' );
		$this->assertContains( 'init', $names );
		$this->assertNotContains( 'newspack_nodes_option_schema_core', $names );
	}

	public function test_hooks_verb_returns_empty_list_when_no_hooks_registered(): void {
		$interpreter = new Logger_CI_Node();

		$result = VerbHarness::fire( $interpreter, 'logger', 'hooks' );

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['hooks'] );
		// `categories` is still populated from the JSON config even when
		// no hooks are registered — it's the static color palette.
		$this->assertIsArray( $result['categories'] );
		$this->assertNotEmpty( $result['categories'] );
	}

	// ── schema-driven dispatch ─────────────────────────────────────────────

	public function test_extends_service_ci_node(): void {
		$this->assertTrue(
			\is_subclass_of( Logger_CI_Node::class, \Newspack_Nodes\Service_CI_Node::class ),
			'Logger_CI_Node must extend Service_CI_Node so its node_schema is auto-wired by the catalog scan.'
		);
	}

	public function test_node_schema_declares_config_and_hooks_verbs(): void {
		$schema = Logger_CI_Node::node_schema();

		$this->assertIsArray( $schema );
		$this->assertSame( 'Service', $schema['category'] );
		$this->assertNotEmpty( $schema['description'] );
		$this->assertArrayHasKey( 'commands', $schema );
		$names = \array_column( $schema['commands'], 'name' );
		$this->assertEqualsCanonicalizing( [ 'config', 'hooks' ], $names );
	}
}
