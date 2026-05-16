<?php
/**
 * RequestsStreamController: GET /firehose/requests — completed-request SSE.
 *
 * Tails `requests.log` and emits `complete_batch` events with the legacy
 * shape consumed by the Request Log dashboard.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

class RequestsStreamController extends SSEControllerBase {

	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/firehose/requests',
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
	 * Map a JSON-encoded completed-request entry to the dashboard wire shape.
	 * URL clipped to 2000 chars, user-agent to 500.
	 */
	public static function transform_line( string $line, int $p ): ?array {
		// Lines are packed Messages (positional JSON); request lives at Message::VALUE.
		$decoded = \json_decode( $line, true, 64 );
		$req     = \is_array( $decoded ) ? ( $decoded[ \Newspack_Nodes\Message::VALUE ] ?? null ) : null;
		if ( ! \is_array( $req ) || empty( $req['url'] ) ) {
			return null;
		}
		$url = (string) $req['url'];
		$ua  = (string) ( $req['user_agent'] ?? '' );
		$ts  = $req['timestamp'] ?? 0;
		$dur = $req['duration_ms'] ?? 0;
		return [
			'rid'          => $req['rid'] ?? '',
			'method'       => $req['request_method'] ?? 'GET',
			'url'          => \strlen( $url ) > 2000 ? \substr( $url, 0, 2000 ) . '...' : $url,
			'start_time'   => $ts,
			'end_time'     => $ts + ( $dur / 1000 ),
			'duration_ms'  => $dur,
			'status_code'  => $req['status_code'] ?? 0,
			'state'        => 'complete',
			'error_status' => $req['error_status'] ?? '-',
			'remote_addr'  => $req['remote_addr'] ?? '',
			'user_agent'   => \strlen( $ua ) > 500 ? \substr( $ua, 0, 500 ) . '...' : $ua,
		];
	}

	public function stream( \WP_REST_Request $request ) {
		return $this->stream_log(
			$request,
			[
				'log_file'        => 'requests.log',
				'event_name'      => 'complete_batch',
				'tail_bytes'      => 1048576,
				'batch_threshold' => 50,
			],
			[ static::class, 'transform_line' ]
		);
	}
}
