<?php
/**
 * GyroscopeController: GET /gyroscope/timeline?request_id=...
 *
 * Synchronous timeline fetch for the `performance-gyroscope` React tree.
 * Walks the requests index newest-first looking for the rid; on hit,
 * returns the request body (already shape-compatible with the timeline
 * renderer). The SSE streaming counterpart lives on the SSE controllers.
 *
 * Parses the request body as JSONL: each line is one event in the
 * request's lifecycle. If the body parses as a single JSON object, we
 * return its `events` field unchanged.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Nodes\Partition;

class GyroscopeController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public const MAX_INDEX_ENTRIES = 100000;

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/gyroscope/timeline',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_timeline' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'request_id' => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	public function get_timeline( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$rid = (string) ( $request->get_param( 'request_id' ) ?? '' );
		if ( '' === $rid ) {
			// Empty initial state shape — same as the legacy stub so the React
			// tree mounts cleanly before a request is selected.
			return new \WP_REST_Response(
				[
					'data' => [ 'events' => [] ],
					'meta' => [],
				],
				200
			);
		}

		$config         = self::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';

		$events = [];
		$scanned = 0;

		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$partition = ( new Partition( "{$log_base}/requests.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => RequestBuilder::format_index_entry( $line, $position, $data )
			);
			$found     = false;
			$partition->scan_index(
				function ( string $line ) use ( &$events, &$scanned, &$found, $partition, $rid ) {
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
					$decoded = \json_decode( \trim( $bytes ), true, 64 );
					if ( \is_array( $decoded ) ) {
						if ( isset( $decoded['events'] ) && \is_array( $decoded['events'] ) ) {
							$events = $decoded['events'];
						} else {
							// Treat the request as a single envelope — render the whole thing.
							$events[] = $decoded;
						}
					}
					$found = true;
					return false;
				},
				true
			);
			if ( $found || $scanned > self::MAX_INDEX_ENTRIES ) {
				break;
			}
		}

		return new \WP_REST_Response(
			[
				'data' => [
					'request_id' => $rid,
					'events'     => $events,
				],
				'meta' => [
					'scanned' => $scanned,
				],
			],
			200
		);
	}
}
