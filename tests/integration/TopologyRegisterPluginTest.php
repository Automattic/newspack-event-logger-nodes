<?php
/**
 * ELN's deferred-loader topology registration contract.
 *
 * `Topology_Registry::register_plugin( $ns, $dir )` (2-arg, no `names:`
 * curation) registers the application namespace + the stock-topology dir.
 * Topologies are NOT plugin-owned: the substrate's `publish_catalog` filter
 * catalogs every *.tsl in the registered dirs, and the active set is the
 * substrate `topologies` config key (operator overlay, else config-file
 * default). This test pins what ELN owns about the call — namespace + stock
 * dir registration, with every shipped *.tsl resolvable through it.
 *
 * The substrate's TopologyRegistryRegisterPluginTest covers register_plugin's
 * generic mechanics (idempotency guard, catalog/spawn wiring). This test
 * covers ELN's specific wiring of it against ELN's real .tsl files.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Topology_Registry;

class TopologyRegisterPluginTest extends TestCase {

	/** Snapshot of Topology_Registry's process-lifetime private statics, restored in tearDown. */
	private array $registry_state = [];

	protected function setUp(): void {
		parent::setUp();
		// add_filter/add_action append to this global; TestCase::setUp does NOT
		// clear it, and register_plugin's idempotency guard would otherwise treat
		// the bootstrap-time call as already-done — reset both so the production
		// registration runs fresh inside the test. Snapshot the registry's
		// process-lifetime statics first so tearDown can restore the bootstrap
		// stock-dir/namespace state other integration tests rely on.
		$this->registry_state   = $this->snapshot_registry();
		$GLOBALS['_wp_actions'] = [];
		Topology_Registry::reset();
		Config::reset();
	}

	protected function tearDown(): void {
		$this->restore_registry( $this->registry_state );
		Config::reset();
		parent::tearDown();
	}

	/** @return array<string,mixed> */
	private function snapshot_registry(): array {
		$out = [];
		foreach ( [ 'stock_dirs', 'user_dir', 'segment_size_overrides_cache', 'registered_plugins' ] as $prop ) {
			$ref = new \ReflectionProperty( Topology_Registry::class, $prop );
			$ref->setAccessible( true );
			$out[ $prop ] = $ref->getValue();
		}
		return $out;
	}

	/** @param array<string,mixed> $state */
	private function restore_registry( array $state ): void {
		foreach ( $state as $prop => $value ) {
			$ref = new \ReflectionProperty( Topology_Registry::class, $prop );
			$ref->setAccessible( true );
			$ref->setValue( null, $value );
		}
	}

	/**
	 * Re-run the exact production registration the deferred loader performs:
	 * ELN's namespace + ELN's topologies dir constant. No `names:` — the
	 * substrate catalogs every *.tsl and the active set is config-driven.
	 */
	private function register_eln_plugin(): void {
		Topology_Registry::register_plugin(
			'Newspack_Event_Logger_Nodes\\',
			NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
		);
	}

	/** Every *.tsl basename ELN ships in its topologies dir. */
	private function shipped_topology_basenames(): array {
		$out = [];
		foreach ( \glob( NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies/*.tsl' ) ?: [] as $path ) {
			$out[] = \basename( $path, '.tsl' );
		}
		return $out;
	}

	public function test_register_plugin_registers_application_namespace(): void {
		$this->register_eln_plugin();
		$this->assertContains(
			'Newspack_Event_Logger_Nodes\\',
			Command_Interpreter_Node::registered_namespaces()
		);
	}

	public function test_register_plugin_registers_stock_dir(): void {
		$this->register_eln_plugin();
		// Every shipped .tsl resolves to its real file via the registered
		// stock dir.
		$names = $this->shipped_topology_basenames();
		$this->assertNotEmpty( $names, 'precondition: ELN ships at least one .tsl' );
		foreach ( $names as $name ) {
			$path = Topology_Registry::resolve( $name );
			$this->assertNotNull( $path, "topology '{$name}' must resolve" );
			$this->assertFileExists( $path );
			$this->assertStringEndsWith( "/topologies/{$name}.tsl", $path );
		}
	}

	public function test_catalog_contains_every_shipped_topology(): void {
		// The substrate's publish_catalog filter catalogs every *.tsl in every
		// registered stock dir — no per-plugin curation. Re-attach it here (the
		// substrate registers it at its own boot; our setUp wipes wp_actions).
		$this->register_eln_plugin();
		\add_filter( 'newspack_nodes/topologies', [ Topology_Registry::class, 'publish_catalog' ] );
		$catalog = \apply_filters( 'newspack_nodes/topologies', [] );
		foreach ( $this->shipped_topology_basenames() as $name ) {
			$this->assertArrayHasKey(
				$name,
				$catalog,
				"shipped topology '{$name}' must appear in the catalog"
			);
		}
	}

	public function test_catalog_entries_carry_topology_name_and_partition_count(): void {
		$this->register_eln_plugin();
		\add_filter( 'newspack_nodes/topologies', [ Topology_Registry::class, 'publish_catalog' ] );
		$catalog = \apply_filters( 'newspack_nodes/topologies', [] );
		$names   = $this->shipped_topology_basenames();
		$name    = $names[0];
		$entry   = $catalog[ $name ] ?? null;
		$this->assertIsArray( $entry );
		$this->assertSame( $name, $entry['topology'] );
		// num_partitions defaults to the (clamped) substrate option; always >= 1.
		$this->assertGreaterThanOrEqual( 1, $entry['num_partitions'] );
		$this->assertLessThanOrEqual( 16, $entry['num_partitions'] );
	}
}
