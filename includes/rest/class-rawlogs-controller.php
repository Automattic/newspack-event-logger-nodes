<?php
/**
 * RawlogsController: GET /firehose/rawlogs — generic-log SSE.
 *
 * Streams arbitrary firehose log files (default: first registered log).
 * Matches the legacy plugin's RawLogs dashboard wire shape: `lines` event
 * with `[{p, line}]` batch payloads, lines clipped to 1000 chars.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

class RawlogsController extends SSEControllerBase {

	public const NAMESPACE = 'newspack-nodes/v1';

	/**
	 * Log catalog mirrored from the (deleted) FirehoseController. The non-SSE
	 * `/firehose/logs` + `/firehose/status` endpoints moved to
	 * Performance_CI.firehose_logs + .firehose_status (which keeps its own
	 * private const for the same purpose); this SSE controller keeps the
	 * catalog inline so the `log` param can be validated without depending on
	 * a sibling controller.
	 *
	 * @var array<string,string> log key (no `.log`) → filename (with `.log`)
	 */
	private const AVAILABLE_LOGS = [
		'firehose'  => 'firehose.log',
		'jobs'      => 'jobs.log',
		'jobintake' => 'jobintake.log',
		'requests'  => 'requests.log',
		'errors'    => 'errors.log',
		'flames'    => 'flames.log',
	];

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/firehose/rawlogs',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'stream' ],
				'permission_callback' => [ $this, 'stream_permissions_check' ],
				'args'                => [
					'log'       => [
						'default'           => '',
						'type'              => 'string',
						'sanitize_callback' => [ $this, 'sanitize_log_param' ],
					],
					'interval'  => [
						'default'           => 100,
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

	public function sanitize_log_param( mixed $v ): string {
		$default_key = (string) \array_key_first( self::AVAILABLE_LOGS );
		$default     = self::AVAILABLE_LOGS[ $default_key ] ?? '';
		if ( empty( $v ) ) {
			return $default;
		}
		$key = \str_replace( '.log', '', (string) $v );
		return self::AVAILABLE_LOGS[ $key ] ?? $default;
	}

	/**
	 * Default line transformer: skip empties, truncate to 1000 chars + ellipsis.
	 */
	public static function transform_line( string $line, int $p ): ?array {
		if ( '' === $line ) {
			return null;
		}
		// Lines are packed Messages; render the entry payload as JSON so the
		// dashboard sees the application-level shape (rid/k/m/...) rather than
		// the wrapping wire envelope. Prefix with `<KEY>: ` when the wire-format
		// KEY is non-empty — surfaces the partition-routing key (rid for
		// firehose entries, handler for jobintake, etc.) without forcing the
		// JS side to decode the envelope itself.
		$decoded = \json_decode( $line, true, 64 );
		$body    = \is_array( $decoded ) ? ( $decoded[ \Newspack_Nodes\Message::VALUE ] ?? null ) : null;
		$key     = \is_array( $decoded ) ? (string) ( $decoded[ \Newspack_Nodes\Message::KEY ] ?? '' ) : '';
		$out     = null !== $body ? (string) \wp_json_encode( $body ) : $line;
		if ( '' !== $key ) {
			$out = $key . ': ' . $out;
		}
		if ( \strlen( $out ) > 1000 ) {
			$out = \substr( $out, 0, 1000 ) . '...';
		}
		return [
			'p'    => $p,
			'line' => $out,
		];
	}

	public function stream( \WP_REST_Request $request ) {
		$log_file = (string) $request->get_param( 'log' );

		return $this->stream_log(
			$request,
			[
				'log_file'        => $log_file,
				'event_name'      => 'lines',
				'tail_bytes'      => 1048576,
				'batch_threshold' => 10,
				'config_extras'   => [ 'log' => $log_file ],
			],
			[ static::class, 'transform_line' ]
		);
	}
}
