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
use Newspack_Nodes\Callback;
use Newspack_Nodes\Cli;
use Newspack_Nodes\Consumer;
use Newspack_Nodes\Message;

\defined( 'ABSPATH' ) || exit;

class TopologyStreamController extends SSEControllerBase {
	public const REST_NAMESPACE = 'newspack-event-logger-nodes/v1';

	/** Override seam for tests — production uses Bootstrap::base_dir(). */
	private ?string $base_dir_override = null;

	/**
	 * Override seam for tests — production loops on connection_aborted().
	 * Test mode does one drain pass and returns so ob_start()/ob_get_clean()
	 * can capture the emitted SSE bytes synchronously.
	 */
	private bool $test_mode = false;

	public function set_base_dir( string $dir ): void {
		$this->base_dir_override = $dir;
	}

	public function set_test_mode( bool $on ): void {
		$this->test_mode = $on;
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
		// Skip init_sse_headers() in test mode — it calls ob_end_clean
		// which would consume our test's outer ob_start() capture buffer.
		if ( ! $this->test_mode ) {
			$this->init_sse_headers();
		}

		$this->send_sse_event(
			'hello',
			[
				'topology'  => $topology,
				'partition' => $partition,
				'pid'       => \getmypid(),
			]
		);

		$reply_in = new Consumer( $ipc['output'], 0, '' );
		// Tests pre-populate the output Partition with messages BEFORE
		// attaching, so we read from segment start. Production attaches to
		// a live worker and only cares about new traffic — read from end.
		$reply_in->next_offset( $this->test_mode ? 'start' : 'end' );

		$this->drain_and_forward( $reply_in );
		$this->flush_if_needed();

		if ( $this->test_mode ) {
			return null;
		}

		// Production loop (heartbeat + ls -ct cadence + connection_aborted)
		// lands in Task 5.
		return null;
	}

	/**
	 * Drain whatever's pending on the worker's output Consumer and forward
	 * each Message as an SSE `msg` event. Wires a Callback sink that calls
	 * back into emit_message_as_sse() per Message.
	 */
	private function drain_and_forward( Consumer $reply_in ): void {
		$controller = $this;
		$sink       = new Callback(
			static function ( array &$message ) use ( $controller ): void {
				$controller->emit_message_as_sse( $message );
			}
		);
		$reply_in->sink( $sink );
		$reply_in->poll();
	}

	/**
	 * Encode a single Message envelope as an SSE `msg` event.
	 *
	 * Public so the Callback closure in drain_and_forward() can reach it
	 * (closure binding through `use ($controller)` keeps it scoped to a
	 * single request lifetime).
	 *
	 * VALUE is decoded one level when it's a JSON envelope (TM_COMMAND
	 * payloads are `{"name":...,"payload":...}` strings on the wire) so
	 * the frontend doesn't double-decode.
	 */
	public function emit_message_as_sse( array $message ): void {
		$value = $message[ Message::VALUE ] ?? '';
		if ( \is_string( $value ) && '' !== $value && ( '{' === $value[0] || '[' === $value[0] ) ) {
			$decoded = \json_decode( $value, true );
			if ( \is_array( $decoded ) ) {
				$value = $decoded;
			}
		}
		$this->send_sse_event(
			'msg',
			[
				'type'  => $message[ Message::TYPE ]      ?? 0,
				'ts'    => $message[ Message::TIMESTAMP ] ?? 0,
				'from'  => $message[ Message::FROM ]      ?? '',
				'to'    => $message[ Message::TO ]        ?? '',
				'id'    => $message[ Message::ID ]        ?? '',
				'key'   => $message[ Message::KEY ]       ?? '',
				'value' => $value,
			]
		);
	}
}
