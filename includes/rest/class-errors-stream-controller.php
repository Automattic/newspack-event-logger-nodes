<?php
/**
 * ErrorsStreamController: GET /firehose/errors — errors-only SSE.
 *
 * Tails `errors.log`. Emits `errors` batches with the legacy wire shape
 * `[{rid, ts, k, m, n}]` so the existing Error Log React tree runs unchanged.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

class ErrorsStreamController extends SSEControllerBase {

	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/firehose/errors',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'stream' ],
				'permission_callback' => [ $this, 'stream_permissions_check' ],
				'args'                => [
					'interval'  => [
						'default'           => 1000,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $v ) => \max( 100, \min( 10000, (int) $v ) ),
					],
					'positions' => [
						'default'           => '',
						'type'              => 'string',
						'sanitize_callback' => static fn ( $v ) => \is_string( $v ) ? \trim( $v ) : '',
					],
				],
			]
		);
	}

	/**
	 * Drop non-JSON or rid-less lines; clip the message body to 1000 chars.
	 */
	public static function transform_line( string $line, int $p ): ?array {
		// Lines are packed Messages (positional JSON `[type,ts,from,to,id,key,value]`);
		// the original entry array lives at index `Message::VALUE` (= 6).
		$decoded = \json_decode( $line, true, 64 );
		$entry   = \is_array( $decoded ) ? ( $decoded[ \Newspack_Nodes\Message::VALUE ] ?? null ) : null;
		if ( ! \is_array( $entry ) || empty( $entry['rid'] ) ) {
			return null;
		}
		$m = $entry['m'] ?? '';
		if ( \is_string( $m ) && \strlen( $m ) > 1000 ) {
			$m = \substr( $m, 0, 1000 ) . '...';
		}
		return [
			'rid' => $entry['rid'],
			'ts'  => $entry['ts'] ?? 0,
			'k'   => $entry['k'] ?? '',
			'm'   => $m,
			'n'   => $entry['n'] ?? 0,
		];
	}

	public function stream( \WP_REST_Request $request ) {
		return $this->stream_log(
			$request,
			[
				'log_file'        => 'errors.log',
				'event_name'      => 'errors',
				'tail_bytes'      => 50 * 1024,
				'batch_threshold' => 50,
			],
			[ static::class, 'transform_line' ]
		);
	}
}
