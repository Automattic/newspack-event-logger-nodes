<?php
/**
 * Topology Console SSE stream.
 *
 * Attaches to a live worker via the same IPC paths `wp nodes cli` uses,
 * issues `ls -al` (initial) + periodic `ls -ct` (live counters) commands,
 * and forwards every Message on the worker's output Partition as an SSE
 * event. Inspect-only; no edit mode in v1.
 *
 * Mirrors the cli's pivoted-REPL contract: same IPC paths, same FROM
 * stamping (`_output/$pid`), same TM_COMMAND → `_command_interpreter`
 * routing. The frontend is essentially a long-lived cli session that
 * happens to render React Flow.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Cli;

\defined( 'ABSPATH' ) || exit;

class TopologyStreamController extends SSEControllerBase {
	public const REST_NAMESPACE = 'newspack-event-logger-nodes/v1';

	/** Override seam for tests — production uses Bootstrap::base_dir(). */
	private ?string $base_dir_override = null;

	public function set_base_dir( string $dir ): void {
		$this->base_dir_override = $dir;
	}

	public function register_routes(): void {
		\register_rest_route(
			self::REST_NAMESPACE,
			'/topology/(?P<topology>[a-z0-9_-]+)/p(?P<partition>\d+)/stream',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'stream' ],
				'permission_callback' => [ $this, 'stream_permissions_check' ],
				'args'                => [
					'topology'  => [ 'required' => true, 'type' => 'string' ],
					'partition' => [ 'required' => true, 'type' => 'integer' ],
				],
			]
		);
	}

	public function stream( \WP_REST_Request $request ) {
		$topology  = (string) $request->get_param( 'topology' );
		$partition = (int) $request->get_param( 'partition' );
		$base_dir  = $this->base_dir_override ?? Bootstrap::base_dir();
		$cli       = new Cli( $base_dir );
		try {
			$ipc = $cli->attach_to_worker( "{$topology}.p{$partition}" );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error(
				'worker_not_found',
				$e->getMessage(),
				[ 'status' => 404 ]
			);
		}
		// Real stream loop lands in subsequent tasks. For now the route
		// exists and validates that the worker is alive.
		return new \WP_REST_Response( [ 'ipc' => $ipc ], 200 );
	}
}
