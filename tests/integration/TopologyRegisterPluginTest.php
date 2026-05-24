<?php
/**
 * ELN's deferred-loader topology registration contract.
 *
 * Step 4b replaced the bespoke `add_filter('newspack_nodes/topologies')` +
 * `add_action('newspack_nodes/spawn_worker')` pair with a single
 * `Topology_Registry::register_plugin()` call. This test pins what ELN owns
 * about that call: it registers the application namespace + stock dir, and it
 * publishes ONLY the curated `topologies` config list (passing `names:`, NOT
 * `null` — `null` would publish every *.tsl in the dir, including
 * `aggregator` / `job-workers` that the default config leaves off).
 *
 * The substrate's TopologyRegistryRegisterPluginTest covers register_plugin's
 * generic mechanics (clamp, guard, before_worker_spawn ordering). This test
 * covers ELN's specific wiring of it against real config + real .tsl files.
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
		$this->registry_state  = $this->snapshot_registry();
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
		foreach ( [ 'stock_dirs', 'user_dir', 'basename_cache', 'segment_size_overrides_cache', 'registered_plugins' ] as $prop ) {
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
	 * ELN's namespace, ELN's topologies dir constant, and the curated `names`
	 * from the application config (NOT null).
	 */
	private function register_eln_plugin(): void {
		$eln_config = Config::load_config();
		$names      = ( isset( $eln_config['topologies'] ) && \is_array( $eln_config['topologies'] ) ) ? $eln_config['topologies'] : [];
		Topology_Registry::register_plugin(
			'Newspack_Event_Logger_Nodes\\',
			NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies',
			names: $names
		);
	}

	/** First curated topology name (config curation differs per deploy environment). */
	private function first_configured_topology(): string {
		$names = Config::load_config()['topologies'] ?? [];
		$this->assertNotEmpty( $names, 'precondition: config must curate at least one topology' );
		return (string) $names[0];
	}

	/**
	 * Basenames of *.tsl files in the topologies dir that the config does NOT curate.
	 *
	 * @param array<int,string> $configured Curated topology names.
	 * @return array<int,string>
	 */
	private function uncurated_topology_basenames( array $configured ): array {
		$out = [];
		foreach ( \glob( NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies/*.tsl' ) ?: [] as $path ) {
			$name = \basename( $path, '.tsl' );
			if ( ! \in_array( $name, $configured, true ) ) {
				$out[] = $name;
			}
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
		// The first curated topology resolves to its real .tsl file via the
		// stock dir. (Config curation differs between deploy environments, so
		// derive the name rather than hard-coding it.)
		$name = $this->first_configured_topology();
		$path = Topology_Registry::resolve( $name );
		$this->assertNotNull( $path );
		$this->assertFileExists( $path );
		$this->assertStringEndsWith( "/topologies/{$name}.tsl", $path );
	}

	public function test_catalog_contains_each_configured_topology(): void {
		$this->register_eln_plugin();
		$catalog = \apply_filters( 'newspack_nodes/topologies', [] );
		foreach ( Config::load_config()['topologies'] as $name ) {
			$this->assertArrayHasKey(
				$name,
				$catalog,
				"configured topology '{$name}' must appear in the catalog"
			);
		}
	}

	public function test_catalog_excludes_non_configured_tsl_files(): void {
		// The dir ships more .tsl files than any deploy curates. Passing the
		// curated `names:` (not null) keeps the off-by-default fleets out of the
		// catalog. If register_plugin were called with null, every *.tsl would
		// publish and this assertion would fail. Compute an actually-uncurated
		// .tsl basename rather than hard-coding one (config differs per deploy).
		$configured = Config::load_config()['topologies'];
		$uncurated  = $this->uncurated_topology_basenames( $configured );
		$this->assertNotEmpty(
			$uncurated,
			'precondition: the topologies dir must ship at least one .tsl the config leaves off'
		);

		$this->register_eln_plugin();
		$catalog = \apply_filters( 'newspack_nodes/topologies', [] );
		foreach ( $uncurated as $name ) {
			$this->assertArrayNotHasKey(
				$name,
				$catalog,
				"uncurated topology '{$name}' must NOT appear in the catalog (names: was passed, not null)"
			);
		}
	}

	public function test_catalog_entries_carry_topology_name_and_partition_count(): void {
		$this->register_eln_plugin();
		$catalog = \apply_filters( 'newspack_nodes/topologies', [] );
		$name    = $this->first_configured_topology();
		$entry   = $catalog[ $name ] ?? null;
		$this->assertIsArray( $entry );
		$this->assertSame( $name, $entry['topology'] );
		// num_partitions defaults to the (clamped) substrate option; always >= 1.
		$this->assertGreaterThanOrEqual( 1, $entry['num_partitions'] );
		$this->assertLessThanOrEqual( 16, $entry['num_partitions'] );
	}
}
