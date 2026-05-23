<?php
/**
 * Status_CI: command-dispatch for the health/version probe surface.
 *
 * Replaces legacy class-status-controller.php with a CommandInterpreter
 * that mounts at priority 11 alongside the rest of the M2 service CIs.
 *
 * Verbs:
 *   get — return plugin version, runtime version, partition count, configured
 *         topology list, cache reachability, and a wall-clock timestamp.
 *         Enough for an admin dashboard to render a "is this thing alive?"
 *         surface without making a dozen separate calls.
 *
 * The substrate Config is a global accessed directly, matching the legacy
 * controller. `cache_available` reflects whether the shared `Core::$memd`
 * handle is configured.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Status_CI_Node extends Command_Interpreter_Node {

	public function __construct() {
		// Node + CommandInterpreter have no explicit __construct, so the
		// inherited no-op is implicit. Mirrors Workers_CI / Discovery_CI,
		// which extend CommandInterpreter and also skip the parent call.
		$this->commands( [
			'get' => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				$config = RuntimeConfig::load_config();

				$cache_available = null !== Core::$memd;

				return [
					'status'          => 'ok',
					'version'         => \defined( 'NEWSPACK_EVENT_LOGGER_NODES_VERSION' ) ? \NEWSPACK_EVENT_LOGGER_NODES_VERSION : 'unknown',
					'runtime_version' => \defined( 'NEWSPACK_NODES_VERSION' ) ? \NEWSPACK_NODES_VERSION : 'unknown',
					'num_partitions'  => (int) ( $config['num_partitions'] ?? 1 ),
					'topologies'      => \is_array( $config['topologies'] ?? null ) ? \array_values( $config['topologies'] ) : [],
					'cache_available' => $cache_available,
					'timestamp'       => \time(),
				];
			},
		] );
	}
}
