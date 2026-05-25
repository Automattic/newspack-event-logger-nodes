<?php
/**
 * ServiceCiHandlerGuardTest: construction-time + catalog guards for the five App
 * service CIs migrated to the schema-driven command mechanism (Events / Settings
 * / Performance / Aggregator / Servers).
 *
 * Each now extends Service_CI_Node, whose ctor builds its dispatch table from
 * `node_schema()['verbs']` via `commands_from_schema()`. That helper emits a
 * rate-limited "no callable handler; skipping" warning (through
 * Core::print_less_often → the stderr handler) for any named verb that lost its
 * handler in the migration. `test_migrated_ci_emits_no_handlerless_warning`
 * captures the stderr handler and asserts NONE of the five CIs warns at
 * construction — i.e. every node_schema verb kept a callable handler.
 *
 * Two further guards protect the migration's HEADLINE contract — that these five
 * CIs are now catalog-visible (Inspector-invokable) Service nodes:
 *   - test_migrated_cis_appear_in_substrate_class_catalog_as_service: fires the
 *     substrate's `Classes_CI list` and asserts every CI's shell_name is in the
 *     catalog with `category === 'Service'`. A future typo dropping a category
 *     back to ''/'Hidden' would silently re-hide the CI; this fails loudly.
 *   - test_every_schema_verb_installs_as_a_command: for each CI, asserts every
 *     verb in its node_schema()['verbs'] is a key in `commands()` — the
 *     schema→commands derivation actually installed each verb (symmetry with the
 *     substrate's ServiceCiSchemaCommandsTest install guard).
 *
 * Mirrors the substrate's ClassesCITest + ServiceCiSchemaCommandsTest guards.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Aggregator_CI_Node;
use Newspack_Event_Logger_Nodes\App\Events_CI_Node;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\App\Servers_CI_Node;
use Newspack_Event_Logger_Nodes\App\Settings_CI_Node;
use Newspack_Event_Logger_Nodes\Server_Registry;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Rest\Classes_CI_Node;

class ServiceCiHandlerGuardTest extends TestCase {

	/**
	 * The five migrated CIs keyed by substrate `shell_name` (class short-name
	 * minus the `_Node` suffix). Single source of truth for both the catalog
	 * guard's expected set and the per-CI install guard's data provider.
	 */
	private const SHELL_NAMES = [
		'Events_CI',
		'Settings_CI',
		'Performance_CI',
		'Aggregator_CI',
		'Servers_CI',
	];

	protected function tearDown(): void {
		VerbHarness::reset();
		parent::tearDown();
	}

	/**
	 * Construct each migrated CI and assert it does NOT emit the
	 * "no callable handler" warning — proving every schema verb has a handler.
	 *
	 * @dataProvider provide_migrated_cis
	 *
	 * @param callable $factory Builds the CI under a captured stderr handler.
	 */
	public function test_migrated_ci_emits_no_handlerless_warning( callable $factory ): void {
		$buf = '';
		Core::set_stderr_handler( function ( $msg ) use ( &$buf ) {
			$buf .= $msg;
		} );

		$factory();

		$this->assertStringNotContainsString(
			'no callable handler',
			$buf,
			'a migrated service CI dropped a verb handler in the schema migration'
		);
	}

	/**
	 * Headline-contract guard: the five migrated CIs are catalog-visible Service
	 * nodes. Fire the substrate's `Classes_CI list` (the same catalog the
	 * topology-editor palette + Inspector consume) and assert each CI's
	 * shell_name appears with `category === 'Service'`.
	 *
	 * Non-vacuous: the `category === 'Service'` assertion can fail — it would if
	 * a CI's node_schema category were dropped back to ''/'Hidden' (the substrate
	 * filters those out of the catalog entirely, so the shell_name would simply
	 * be absent and assertArrayHasKey fails first; if it were some OTHER
	 * non-empty category it would appear but assertSame catches it). A
	 * stale/empty composer classmap fails LOUDLY at the assertNotEmpty guard, not
	 * vacuously green — mirrors the substrate ClassesCITest guard.
	 */
	public function test_migrated_cis_appear_in_substrate_class_catalog_as_service(): void {
		$result = VerbHarness::fire( new Classes_CI_Node(), 'classes', 'list' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'classes', $result );
		// A stale/empty classmap (no `composer dump-autoload -o`) yields zero
		// classes and would pass every per-CI assertion vacuously. Fail loudly.
		$this->assertNotEmpty(
			$result['classes'],
			'class discovery found nothing — stale composer classmap? (run composer dump-autoload -o)'
		);

		// Index the catalog by shell_name → category for direct assertions.
		$by_shell = [];
		foreach ( $result['classes'] as $entry ) {
			$by_shell[ $entry['shell_name'] ] = $entry['category'];
		}

		foreach ( self::SHELL_NAMES as $shell_name ) {
			$this->assertArrayHasKey(
				$shell_name,
				$by_shell,
				"migrated CI '{$shell_name}' is absent from the substrate class catalog — its node_schema category was dropped to ''/'Hidden', or class discovery is broken"
			);
			$this->assertSame(
				'Service',
				$by_shell[ $shell_name ],
				"migrated CI '{$shell_name}' must be catalog-visible under the 'Service' category"
			);
		}
	}

	/**
	 * Install-as-command guard: for each migrated CI, every verb declared in its
	 * node_schema()['verbs'] is present as a key in `commands()`. The
	 * schema→commands derivation (Service_CI_Node::commands_from_schema) must
	 * actually install each verb — this catches a verb that lists in the schema
	 * but gets filtered out of dispatch.
	 *
	 * Non-vacuous: the assertion can fail — it would if a schema verb were
	 * dropped from the commands table (e.g. losing its callable handler, which
	 * commands_from_schema skips). The provider asserts every CI declares at
	 * least one verb, so the loop is never empty.
	 *
	 * @dataProvider provide_migrated_cis
	 *
	 * @param callable $factory Builds the CI instance.
	 */
	public function test_every_schema_verb_installs_as_a_command( callable $factory ): void {
		$ci = $factory();
		$this->assertInstanceOf( Command_Interpreter_Node::class, $ci );

		$schema = $ci::node_schema();
		$verbs  = \array_filter(
			$schema['verbs'] ?? [],
			static fn ( $v ): bool => \is_array( $v ) && '' !== (string) ( $v['name'] ?? '' )
		);
		$this->assertNotEmpty(
			$verbs,
			'migrated CI declares no named verbs — schema scan is vacuous'
		);

		$commands = $ci->commands();
		foreach ( $verbs as $verb ) {
			$name = (string) $verb['name'];
			$this->assertArrayHasKey(
				$name,
				$commands,
				"schema verb '{$name}' on " . $ci::class . ' did not install as a command — schema→commands derivation dropped it'
			);
		}
	}

	/**
	 * @return array<string,array{0:callable}>
	 */
	public static function provide_migrated_cis(): array {
		return [
			'Events_CI'      => [ static fn () => new Events_CI_Node() ],
			'Settings_CI'    => [ static fn () => new Settings_CI_Node() ],
			'Performance_CI' => [ static fn () => new Performance_CI_Node() ],
			'Aggregator_CI'  => [ static fn () => new Aggregator_CI_Node( new Server_Registry() ) ],
			'Servers_CI'     => [ static fn () => new Servers_CI_Node( new Server_Registry() ) ],
		];
	}
}
