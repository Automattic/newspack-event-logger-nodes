<?php
/**
 * FirehoseController: non-SSE firehose admin endpoints.
 *
 * Mounts:
 *   GET  /newspack-nodes/v1/firehose/logs       — list available log files.
 *   GET  /newspack-nodes/v1/firehose/status     — segment metadata for a log.
 *   POST /newspack-nodes/v1/firehose/heartbeat  — keep an SSE slot alive.
 *
 * The streaming endpoints (`/firehose/stream`, `/firehose/rawlogs`,
 * `/firehose/errors`, `/firehose/gyroscope`, `/firehose/requests`) live in
 * sibling SSE controllers — this one is the synchronous control plane.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Partition;

class FirehoseController extends PerformanceControllerBase {

	public const NAMESPACE = 'newspack-nodes/v1';

	/**
	 * Default log catalog — flat key→filename map. The topology fleet is the
	 * source of truth for which logs actually exist; this list mirrors what
	 * the bundled topologies write.
	 *
	 * @return array<string,string> log key (no `.log`) → filename (with `.log`)
	 */
	public static function get_available_logs(): array {
		return [
			'firehose'   => 'firehose.log',
			'jobs'       => 'jobs.log',
			'jobintake'  => 'jobintake.log',
			'requests'   => 'requests.log',
			'errors'     => 'errors.log',
			'flames'     => 'flames.log',
		];
	}

	public static function get_default_log(): string {
		$logs = self::get_available_logs();
		return (string) ( \reset( $logs ) ?: '' );
	}

	public static function validate_log_name( mixed $log ): bool {
		$allowed = self::get_available_logs();
		return \is_string( $log ) && isset( $allowed[ $log ] );
	}

	public function sanitize_log_param( mixed $v ): string {
		$allowed = self::get_available_logs();
		if ( empty( $v ) || empty( $allowed ) ) {
			return self::get_default_log();
		}
		$key = \str_replace( '.log', '', (string) $v );
		return $allowed[ $key ] ?? self::get_default_log();
	}

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/firehose/logs',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_logs' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/firehose/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'log' => [
						'default'           => '',
						'type'              => 'string',
						'sanitize_callback' => [ $this, 'sanitize_log_param' ],
					],
				],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/firehose/heartbeat',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'heartbeat' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'slot'      => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $v ) => \max( 0, (int) $v ),
					],
					'aggregator' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => static fn ( $v ) => \filter_var( $v, FILTER_VALIDATE_BOOLEAN ),
					],
					'partition'  => [
						'required'          => false,
						'type'              => 'integer',
						'default'           => -1,
						'sanitize_callback' => static fn ( $v ) => null === $v ? -1 : (int) $v,
					],
				],
			]
		);
	}

	/**
	 * `GET /firehose/logs` — list available logs as `[{key, label}]`.
	 */
	public function get_logs( \WP_REST_Request $request ): \WP_REST_Response {
		$result = [];
		foreach ( self::get_available_logs() as $key => $filename ) {
			$result[] = [
				'key'   => $key,
				'label' => $filename,
			];
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * `GET /firehose/status?log=` — return segment-level state per partition.
	 */
	public function get_status( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$log_file = (string) $request->get_param( 'log' );
		if ( '' === $log_file ) {
			return new \WP_Error( 'no_logs', 'No logs available', [ 'status' => 404 ] );
		}

		$config         = self::load_config();
		$log_base       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' ) . '/logs';
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$log_key        = \str_replace( '.log', '', $log_file );

		$partitions     = [];
		$total_size     = 0;
		$total_segments = 0;

		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$partition = new Partition( "{$log_base}/{$log_file}", $p );
			$segments  = $partition->get_segments( true );
			$size      = (int) \array_sum( \array_column( $segments, 'size' ) );
			$partitions[ $p ] = [
				'segments'      => $segments,
				'segment_count' => \count( $segments ),
				'size'          => $size,
			];
			$total_size     += $size;
			$total_segments += \count( $segments );
		}

		return new \WP_REST_Response(
			[
				'log_id'         => $log_key,
				'log_file'       => $log_file,
				'num_partitions' => $num_partitions,
				'partitions'     => $partitions,
				'total_segments' => $total_segments,
				'total_size'     => $total_size,
			],
			200
		);
	}

	/**
	 * `POST /firehose/heartbeat` — refresh an SSE slot from the browser/aggregator.
	 *
	 * Browser callers omit `partition` (default -1 → shared pool); aggregators
	 * include it so we touch the right per-partition slot.
	 */
	public function heartbeat( \WP_REST_Request $request ): \WP_REST_Response {
		$cache      = SSEControllerBase::cache();
		$user_id    = \function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
		$ip_hash    = $this->get_ip_hash();
		$slot       = (int) $request->get_param( 'slot' );
		$aggregator = (bool) $request->get_param( 'aggregator' );
		$partition  = (int) $request->get_param( 'partition' );
		$ttl        = $aggregator ? SSEControllerBase::SLOT_TTL_AGGREGATOR : SSEControllerBase::SLOT_TTL_BROWSER;

		$success = $cache->touch_sse_slot( $user_id, $ip_hash, $slot, $ttl, $partition );

		return new \WP_REST_Response(
			[
				'success'   => $success,
				'slot'      => $slot,
				'error'     => $success ? null : 'slot_expired',
				'timestamp' => \time(),
			],
			200
		);
	}

	private function get_ip_hash(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		return \substr( \md5( (string) $ip ), 0, 8 );
	}
}
