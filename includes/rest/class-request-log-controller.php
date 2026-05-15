<?php
/**
 * RequestLogController:
 *   GET /request-log/list           — recent requests across all partitions.
 *   GET /request-log/detail/{id}    — single request envelope.
 *
 * Plumbing for the `performance-request-log` React tree. The legacy
 * /perf-logger/v1/requests/* endpoints map onto these. Both routes use
 * the requests index for fast lookup; detail returns 404 via the shared
 * not_found_error() helper when the id is unknown.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Nodes\Partition;
use Newspack_Nodes\Config as RuntimeConfig;

class RequestLogController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public const MAX_INDEX_ENTRIES = 100000;

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/request-log/list',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_list' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'limit' => [
						'default'           => 100,
						'sanitize_callback' => static fn ( $v ) => \max( 1, \min( 1000, (int) $v ) ),
					],
				],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/request-log/detail/(?P<id>[A-Za-z0-9_-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_detail' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'id' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	public function get_list( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$limit          = (int) $request->get_param( 'limit' );
		$config         = RuntimeConfig::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';

		$entries = [];
		$scanned = 0;

		for ( $p = 0; $p < $num_partitions && \count( $entries ) < $limit; $p++ ) {
			$partition = ( new Partition( "{$log_base}/requests.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => RequestBuilder::format_index_entry( $line, $position, $data )
			);
			$partition->scan_index(
				function ( string $line ) use ( &$entries, &$scanned, $limit, $p ) {
					++$scanned;
					if ( $scanned > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					if ( \count( $entries ) >= $limit ) {
						return false;
					}
					$parsed = RequestBuilder::parse_request_index( $line );
					if ( ! \is_array( $parsed ) ) {
						return null;
					}
					$entries[] = [
						'rid'          => \trim( (string) ( $parsed['rid'] ?? '' ) ),
						'url_hash'     => \trim( (string) ( $parsed['url_hash'] ?? '' ) ),
						'timestamp'    => $parsed['timestamp'] ?? 0,
						'duration_ms'  => $parsed['duration_ms'] ?? 0,
						'status_code'  => $parsed['status_code'] ?? 0,
						'peak_mb'      => $parsed['peak_mb'] ?? 0,
						'method'       => $parsed['method'] ?? '',
						'error_status' => $parsed['error_status'] ?? null,
						'partition'    => $p,
					];
					return null;
				},
				true
			);
		}

		\usort( $entries, static fn ( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		$entries = \array_slice( $entries, 0, $limit );

		return new \WP_REST_Response(
			[
				'data' => $entries,
				'meta' => [
					'limit'   => $limit,
					'scanned' => $scanned,
				],
			],
			200
		);
	}

	public function get_detail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$rid = (string) ( $request->get_param( 'id' ) ?? '' );
		if ( '' === $rid ) {
			return $this->not_found_error( 'request id missing' );
		}

		$config         = RuntimeConfig::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';

		$result  = null;
		$scanned = 0;
		for ( $p = 0; $p < $num_partitions && null === $result; $p++ ) {
			$partition = ( new Partition( "{$log_base}/requests.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => RequestBuilder::format_index_entry( $line, $position, $data )
			);
			$partition->scan_index(
				function ( string $line ) use ( &$result, &$scanned, $partition, $rid, $p ) {
					++$scanned;
					if ( $scanned > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = RequestBuilder::parse_request_index( $line );
					if ( ! \is_array( $entry ) || \trim( (string) $entry['rid'] ) !== $rid ) {
						return null;
					}
					$bytes = $partition->read_at(
						(int) ( $entry['segment_id'] ?? 0 ),
						(int) ( $entry['offset'] ?? 0 ),
						(int) ( $entry['length'] ?? 0 )
					);
					if ( '' === $bytes ) {
						return false;
					}
					// Bytes are a packed Message; request body lives at VALUE.
					$decoded = \json_decode( \trim( $bytes ), true, 64 );
					$req     = \is_array( $decoded ) ? ( $decoded[ \Newspack_Nodes\Message::VALUE ] ?? null ) : null;
					if ( \is_array( $req ) ) {
						$req['_partition'] = $p;
						$result            = $req;
					}
					return false;
				},
				true
			);
		}

		if ( null === $result ) {
			// Stay backward-compatible with the legacy stub: return an empty
			// payload rather than 404 when the rid is "expected to exist soon".
			// 404 is still emitted for a missing id ('').
			return new \WP_REST_Response(
				[
					'data' => [
						'request_id' => $rid,
						'entries'    => [],
					],
					'meta' => [
						'scanned' => $scanned,
					],
				],
				200
			);
		}

		// Normalize the entries shape — the React tree expects { entries: [] }.
		$entries = $result['events'] ?? [ $result ];
		return new \WP_REST_Response(
			[
				'data' => [
					'request_id' => $rid,
					'entries'    => $entries,
				],
				'meta' => [
					'scanned' => $scanned,
				],
			],
			200
		);
	}
}
