<?php
/**
 * Guard test: structural invariants of the stock `.tsl` topologies.
 *
 * These assert the SHAPE the topologies must keep so a hand-assembled graph
 * can't silently drop a load-bearing wire.
 *
 * The invariants derive from the topologies themselves (glob + parse), so a new
 * topology or a new stateful node is covered automatically.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Tests\TestCase;

class TopologyShapeTest extends TestCase {

	/** A Consumer must add_snapshot_node to the stateful node it feeds, or that node's state is lost. */
	public function test_every_consumer_snapshots_the_stateful_node_it_feeds(): void {
		foreach ( $this->topology_files() as $path ) {
			$name      = \basename( $path );
			$topo      = $this->parse_topology( $path );
			$snapshots = $this->snapshot_map( $topo['cmds'] );

			foreach ( \array_keys( $topo['consumers'] ) as $consumer ) {
				$stateful = $this->reachable_stateful( $consumer, $topo['nodes'], $topo['edges'] );
				if ( empty( $stateful ) ) {
					continue; // Drives no stateful node — no snapshot needed.
				}
				$this->assertLessThanOrEqual(
					1,
					\count( $stateful ),
					"$name: consumer '$consumer' feeds " . \count( $stateful ) . ' stateful nodes [' . \implode( ', ', $stateful ) . '] but a Consumer can snapshot only one — the rest lose state on respawn'
				);
				$this->assertArrayHasKey(
					$consumer,
					$snapshots,
					"$name: consumer '$consumer' feeds stateful node(s) [" . \implode( ', ', $stateful ) . "] but sets no snapshot_node — save_state() never co-commits (state lost on respawn)"
				);
				$this->assertContains(
					$snapshots[ $consumer ],
					$stateful,
					"$name: consumer '$consumer' snapshots '{$snapshots[ $consumer ]}' but feeds stateful node(s) [" . \implode( ', ', $stateful ) . ']'
				);
			}
		}
	}

	public function test_the_dashboard_graphs_keep_the_request_builders_branches(): void {
		\Newspack_Nodes\Topology_Registry::reset();
		\Newspack_Nodes\Topology_Registry::register_plugin(
			'Newspack_Event_Logger_Nodes\\',
			NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
		);
		\Newspack_Nodes\Topology_Registry::register_builtin_dir(
			\dirname( __DIR__, 3 ) . '/newspack-nodes/topologies'
		);

		foreach ( [ 'complete', 'performance' ] as $topology ) {
			$edges = \Newspack_Nodes\Topology_Analyzer::graph_for( $topology )['edges'];
			foreach ( [ 'completed:tee', 'errors:partition', 'gyroscope:partition' ] as $target ) {
				$this->assertContains(
					[ 'request-builder', $target ],
					$edges,
					"$topology: request-builder lost its $target branch"
				);
			}
		}
	}

	/** The document canvas lays the stats Tables out below the builder only along these edges. */
	public function test_the_dashboard_graphs_draw_the_flame_builders_tables(): void {
		\Newspack_Nodes\Topology_Registry::reset();
		\Newspack_Nodes\Topology_Registry::register_plugin(
			'Newspack_Event_Logger_Nodes\\',
			NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
		);
		\Newspack_Nodes\Topology_Registry::register_builtin_dir(
			\dirname( __DIR__, 3 ) . '/newspack-nodes/topologies'
		);

		foreach ( [ 'complete', 'performance', 'flame-builder' ] as $topology ) {
			$edges = \Newspack_Nodes\Topology_Analyzer::graph_for( $topology )['edges'];
			foreach ( \Newspack_Event_Logger_Nodes\Stats_Store::TABLES as $table ) {
				$this->assertContains( [ 'flame-builder', $table ], $edges, "$topology: flame-builder draws no edge to $table" );
			}
		}
	}

	/** The flame builder's topology includes table-probe, so its Tables report. */
	public function test_the_flame_builder_probes_its_tables(): void {
		\Newspack_Nodes\Topology_Registry::reset();
		\Newspack_Nodes\Topology_Registry::register_plugin(
			'Newspack_Event_Logger_Nodes\\',
			NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
		);
		\Newspack_Nodes\Topology_Registry::register_builtin_dir(
			\dirname( __DIR__, 3 ) . '/newspack-nodes/topologies'
		);

		foreach ( [ 'complete', 'performance', 'flame-builder' ] as $topology ) {
			$types = \array_column( \Newspack_Nodes\Topology_Analyzer::graph_for( $topology )['nodes'], 'type' );
			$this->assertContains( 'Table_Probe', $types, "$topology: no Table_Probe sweeps its Tables" );
		}
	}

	/** Staleness lives in the Age_Sieve between job-router and disk, not in Job_Router. */
	public function test_job_router_topologies_sieve_by_age_before_disk(): void {
		foreach ( [ 'job-router', 'complete' ] as $topology ) {
			$statements = \array_column(
				\Newspack_Nodes\Topology_Analyzer::statements( $topology )['statements'],
				'line'
			);
			$this->assertContains( 'make_node Job_Router job-router', $statements, "$topology: Job_Router takes no stale timeout" );
			$this->assertContains( 'make_node Age_Sieve jobs:sieve 900 1', $statements, "$topology: the sieve owns the age guard" );
			$this->assertContains( 'connect_node job-router jobs:sieve', $statements );
			$this->assertContains( 'connect_node jobs:sieve jobs:partition', $statements );
		}
	}

	/** Every Request_Builder sets its completed / errors / inflight output targets. */
	public function test_request_builder_topologies_set_all_output_targets(): void {
		$required = [ 'set_completed_target', 'set_errors_target', 'set_inflight_target' ];
		foreach ( $this->topology_files() as $path ) {
			$name = \basename( $path );
			$topo = $this->parse_topology( $path );
			foreach ( $this->nodes_of_type( $topo['nodes'], 'Request_Builder' ) as $rb ) {
				foreach ( $required as $verb ) {
					$this->assertNotNull( $this->first_cmd_index( $topo['cmds'], $rb, $verb ), "$name: request-builder '$rb' missing $verb" );
				}
			}
		}
	}

	/** Every Flame_Builder names its three stats Tables before configure_stats builds a store over them. */
	public function test_flame_builder_topologies_name_every_stats_table_before_configuring_stats(): void {
		foreach ( $this->topology_files() as $path ) {
			$name = \basename( $path );
			$topo = $this->parse_topology( $path );
			foreach ( $this->nodes_of_type( $topo['nodes'], 'Flame_Builder' ) as $fb ) {
				$configure = $this->first_cmd_index( $topo['cmds'], $fb, 'configure_stats' );
				$this->assertNotNull( $configure, "$name: flame-builder '$fb' never runs configure_stats" );
				foreach ( [ 'set_aggregate_target', 'set_url_target', 'set_url_fine_target' ] as $verb ) {
					$named = $this->first_cmd_index( $topo['cmds'], $fb, $verb );
					$this->assertNotNull( $named, "$name: flame-builder '$fb' missing $verb" );
					$this->assertLessThan( $configure, $named, "$name: flame-builder '$fb' runs $verb after configure_stats" );
				}
			}
		}
	}

	/** connect_node endpoints and cmd targets must reference a node declared in the same topology. */
	public function test_every_directive_references_a_declared_node(): void {
		foreach ( $this->topology_files() as $path ) {
			$name = \basename( $path );
			$topo = $this->parse_topology( $path );
			foreach ( $topo['edges'] as [ $from, $to ] ) {
				$this->assertArrayHasKey( $from, $topo['nodes'], "$name: connect_node from undeclared node '$from'" );
				$this->assertArrayHasKey( $to, $topo['nodes'], "$name: connect_node to undeclared node '$to'" );
			}
			foreach ( $topo['cmds'] as $cmd ) {
				$this->assertArrayHasKey( $cmd['node'], $topo['nodes'], "$name: cmd targets undeclared node '{$cmd['node']}'" );
			}
		}
	}

	/** Wiring verbs whose first arg names another node must reference a declared node (token/number args excluded). */
	public function test_wiring_target_args_reference_declared_nodes(): void {
		$node_ref_verbs = [ 'set_completed_target', 'set_errors_target', 'set_inflight_target', 'set_aggregate_target', 'set_url_target', 'set_url_fine_target', 'add_snapshot_node' ];
		foreach ( $this->topology_files() as $path ) {
			$name = \basename( $path );
			$topo = $this->parse_topology( $path );
			foreach ( $topo['cmds'] as $cmd ) {
				if ( ! \in_array( $cmd['verb'], $node_ref_verbs, true ) || ! isset( $cmd['args'][0] ) ) {
					continue;
				}
				$this->assertArrayHasKey( $cmd['args'][0], $topo['nodes'], "$name: {$cmd['verb']} references undeclared node '{$cmd['args'][0]}'" );
			}
		}
	}

	/**
	 * An offsetlog is a reader's CURSOR, and the reader is the FLEET: two
	 * processes tailing one log need two cursors. So every Consumer offsetlog is
	 * `<topology>`-scoped (distinct across fleets) and distinct within its own
	 * topology (distinct across readers). That pair is the whole invariant — it
	 * replaces the old "the offset filename names the snapshot node" convention,
	 * which broke the moment two topologies wanted to SHARE a reader via include
	 * (dedup needs byte-identical make_node lines).
	 */
	public function test_every_consumer_offsetlog_is_fleet_scoped_and_unique(): void {
		foreach ( $this->topology_files() as $path ) {
			$name = \basename( $path );
			$topo = $this->parse_topology( $path );
			$seen = [];
			foreach ( $topo['consumers'] as $consumer => $offset ) {
				$this->assertStringContainsString(
					'<topology>',
					$offset,
					"$name: consumer '$consumer' offsetlog '$offset' is not fleet-scoped — two fleets tailing this log would share one cursor"
				);
				$this->assertArrayNotHasKey(
					$offset,
					$seen,
					"$name: consumers '$consumer' and '" . ( $seen[ $offset ] ?? '' ) . "' share the offsetlog '$offset'"
				);
				$seen[ $offset ] = $consumer;
			}
		}
	}

	/**
	 * `cmd {partition}:config with_index <name>` resolves the name through the
	 * substrate's Formatters registry. An unregistered name leaves the partition
	 * with no index at all — writes still land, but every index-driven read
	 * (request search, flame lookup) then scans nothing and reports not-found.
	 */
	public function test_every_with_index_name_resolves_to_a_registered_formatter(): void {
		$referenced = [];
		foreach ( $this->topology_files() as $path ) {
			foreach ( $this->parse_topology( $path )['cmds'] as $cmd ) {
				if ( 'with_index' === $cmd['verb'] && isset( $cmd['args'][0] ) ) {
					$referenced[ $cmd['args'][0] ] = \basename( $path );
				}
			}
		}
		$this->assertNotEmpty( $referenced, 'no with_index cmds — a rename silently disabled this guard' );
		foreach ( $referenced as $formatter => $topology ) {
			$this->assertNotNull(
				\Newspack_Nodes\Formatters::resolve( $formatter ),
				"$topology: with_index '$formatter' names no registered formatter — the partition writes no index"
			);
		}
	}

	/**
	 * A Job_Worker heartbeats only where its handler reaches should_continue(),
	 * so a CPU-bound job outlives the 60s lock default and a peer steals the lock
	 * mid-job, replaying it. `stale_timeout` is read from the named file alone —
	 * an included `var` is ignored — so every topology composing a Job_Worker
	 * must restate it, and a new composition is held to it here.
	 */
	public function test_every_topology_composing_a_job_worker_holds_its_lock_ten_minutes(): void {
		\Newspack_Nodes\Topology_Analyzer::reset_caches();
		$composing = [];
		$short     = [];
		foreach ( $this->topology_files() as $path ) {
			$name  = \basename( $path, '.tsl' );
			$types = \array_column( \Newspack_Nodes\Topology_Analyzer::graph_for( $name )['nodes'], 'type' );
			if ( ! \in_array( 'Job_Worker', $types, true ) ) {
				continue;
			}
			$composing[] = $name;
			$stale       = \Newspack_Nodes\Topology_Registry::synthesize_entry( $name )['stale_timeout'] ?? null;
			if ( ! \is_int( $stale ) || $stale < 600 ) {
				$short[ $name ] = $stale;
			}
		}
		$this->assertNotEmpty( $composing, 'no topology composes a Job_Worker — a rename silently disabled this guard' );
		$this->assertSame(
			[],
			$short,
			'topologies composing a Job_Worker run under a 600s stale_timeout; declare `var stale_timeout = 600` in each file itself: ' . \json_encode( $short )
		);
	}

	/**
	 * Anchor the shape guards to the current corpus: a renamed node-type or verb
	 * token must not silently disable a guard by matching nothing (and a stale
	 * classmap must not make every node look non-stateful).
	 */
	public function test_shape_guards_are_not_vacuous(): void {
		$flame_builders     = 0;
		$consumers          = 0;
		$stateful_consumers = 0;
		foreach ( $this->topology_files() as $path ) {
			$topo            = $this->parse_topology( $path );
			$flame_builders += \count( $this->nodes_of_type( $topo['nodes'], 'Flame_Builder' ) );
			$consumers      += \count( $topo['consumers'] );
			foreach ( \array_keys( $topo['consumers'] ) as $consumer ) {
				if ( ! empty( $this->reachable_stateful( $consumer, $topo['nodes'], $topo['edges'] ) ) ) {
					++$stateful_consumers;
				}
			}
		}
		$this->assertGreaterThan( 0, $flame_builders, 'no Flame_Builder nodes — a rename silently disabled the snapshot guard\'s stateful type' );
		$this->assertGreaterThan( 0, $consumers, 'no Consumer nodes — a rename silently disabled the snapshot guard' );
		$this->assertGreaterThan( 0, $stateful_consumers, 'no consumer reaches a stateful node — a rewire made the snapshot guard vacuous' );
		$this->assertTrue( $this->is_stateful_type( 'Flame_Builder' ), 'Flame_Builder no longer resolves as stateful — a stale classmap makes the snapshot guard vacuous' );
	}

	// -------------------------------------------------------------------------
	// TSL parsing helpers.
	// -------------------------------------------------------------------------

	/** @return list<string> Absolute paths of the stock topologies. */
	private function topology_files(): array {
		$files = \glob( \dirname( __DIR__, 2 ) . '/topologies/*.tsl' );
		$this->assertNotEmpty( $files, 'no stock topologies found' );
		return $files;
	}

	/**
	 * Parse a .tsl into its structural elements. Comments (`#`), blank lines, and
	 * directives other than make_node / connect_node / cmd are ignored.
	 *
	 * @return array{nodes: array<string,string>, consumers: array<string,string>, edges: list<array{0:string,1:string}>, cmds: list<array{node:string, verb:string, args:list<string>}>}
	 */
	private function parse_topology( string $path ): array {
		$nodes     = [];
		$consumers = [];
		$edges     = [];
		$cmds      = [];

		// Read the FLATTENED statements, not the raw file: a topology that
		// `include`s others owns few lines of its own, and every directive here
		// must be checked against the graph it actually builds.
		$name  = \basename( $path, '.tsl' );
		$lines = \array_column(
			\Newspack_Nodes\Topology_Analyzer::statements( $name )['statements'],
			'line'
		);

		foreach ( $lines as $line ) {
			// Strip whole-line and trailing `#` comments (no directive token contains '#').
			$line = \trim( (string) \preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line ) {
				continue;
			}
			$parts = \preg_split( '/\s+/', $line );
			switch ( $parts[0] ) {
				case 'make_node':
					$type                = $parts[1];
					$node_name           = $parts[2];
					$nodes[ $node_name ] = $type;
					if ( 'Consumer' === $type ) {
						// make_node Consumer <name> <logpath> <offsetpath>
						$consumers[ $node_name ] = $parts[4] ?? '';
					}
					break;
				case 'connect_node':
					$edges[] = [ $parts[1], $parts[2] ];
					break;
				case 'command_node':
					// command_node <node>:config <verb> [args...] — names contain ':'; strip only the trailing ':config'.
					$cmds[] = [
						'node' => \preg_replace( '/:config$/', '', $parts[1] ),
						'verb' => $parts[2] ?? '',
						'args' => \array_slice( $parts, 3 ),
					];
					break;
			}
		}

		return \compact( 'nodes', 'consumers', 'edges', 'cmds' );
	}

	/**
	 * consumer-name → snapshot target from `add_snapshot_node` cmds.
	 *
	 * @param list<array{node:string, verb:string, args:list<string>}> $cmds
	 * @return array<string,string>
	 */
	private function snapshot_map( array $cmds ): array {
		$map = [];
		foreach ( $cmds as $cmd ) {
			if ( 'add_snapshot_node' === $cmd['verb'] && isset( $cmd['args'][0] ) ) {
				$map[ $cmd['node'] ] = $cmd['args'][0];
			}
		}
		return $map;
	}

	/**
	 * Stateful nodes reachable downstream of $start via connect_node edges
	 * (through Tees). Stateful = the resolved node class implements save_state().
	 *
	 * @param array<string,string>                 $nodes name → type
	 * @param list<array{0:string,1:string}>        $edges
	 * @return list<string>
	 */
	private function reachable_stateful( string $start, array $nodes, array $edges ): array {
		$adj = [];
		foreach ( $edges as [ $from, $to ] ) {
			$adj[ $from ][] = $to;
		}
		$seen     = [];
		$stateful = [];
		$queue    = $adj[ $start ] ?? [];
		while ( $queue ) {
			$node = \array_shift( $queue );
			if ( isset( $seen[ $node ] ) ) {
				continue;
			}
			$seen[ $node ] = true;
			if ( isset( $nodes[ $node ] ) && $this->is_stateful_type( $nodes[ $node ] ) ) {
				$stateful[ $node ] = true;
			}
			foreach ( $adj[ $node ] ?? [] as $next ) {
				$queue[] = $next;
			}
		}
		return \array_keys( $stateful );
	}

	/**
	 * @param array<string,string> $nodes name → type
	 * @return list<string> names of nodes of the given type
	 */
	private function nodes_of_type( array $nodes, string $type ): array {
		return \array_keys( \array_filter( $nodes, static fn( string $t ): bool => $t === $type ) );
	}

	/**
	 * File-order index of the first cmd matching (node, verb), or null.
	 *
	 * @param list<array{node:string, verb:string, args:list<string>}> $cmds
	 */
	private function first_cmd_index( array $cmds, string $node, string $verb ): ?int {
		foreach ( $cmds as $i => $cmd ) {
			if ( $cmd['node'] === $node && $cmd['verb'] === $verb ) {
				return $i;
			}
		}
		return null;
	}

	/** A node type is stateful when its resolved class implements save_state(). */
	private function is_stateful_type( string $type ): bool {
		foreach ( [ 'Newspack_Event_Logger_Nodes\\', 'Newspack_Nodes\\' ] as $prefix ) {
			$class = $prefix . $type . '_Node';
			if ( \class_exists( $class ) && \method_exists( $class, 'save_state' ) ) {
				return true;
			}
		}
		return false;
	}

	/** The three stats Tables resolve per partition from the shipped graph, each on SQLite at its own TTL. */
	public function test_the_flame_builder_declares_its_stats_tables_on_sqlite(): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'min_lifetime' => 5400 ] );
		$this->activate_shipped( 'performance', 2 );
		$table = static fn ( int $p, int $ttl ): array => [ 'namespace' => "evlog:p{$p}", 'ttl' => $ttl, 'backend' => 'sqlite' ];
		$this->assertSame(
			[
				'flame-stats:aggregate' => [ $table( 0, 90000 ), $table( 1, 90000 ) ],
				'flame-stats:url'       => [ $table( 0, 3600 ), $table( 1, 3600 ) ],
				'flame-stats:url-fine'  => [ $table( 0, 5400 ), $table( 1, 5400 ) ],
			],
			\Newspack_Nodes\Bootstrap::node_tables( 'flame-stats:aggregate', 'flame-stats:url', 'flame-stats:url-fine' )
		);
	}
}
