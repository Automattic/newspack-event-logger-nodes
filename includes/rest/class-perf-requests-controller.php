<?php
/**
 * PerfRequestsController:
 *   GET /performance/requests/search/{rid}  — minimal "where is rid?" lookup.
 *   GET /performance/requests/{rid}?partition= — full request + flame data.
 *
 * Two-step UX: search returns just `{rid, partition, url_hash}` so the
 * dashboard can deep-link to the right partition without scanning all of
 * them on every detail load. Detail pulls the request body and merges
 * flame data (separately stored per-partition).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\FlameBuilder;
use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Nodes\Partition;

class PerfRequestsController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public const MAX_INDEX_ENTRIES = 100000;

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/performance/requests/search/(?P<rid>[a-zA-Z0-9_-]{1,128})',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'search_request' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'rid' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/performance/requests/(?P<rid>[a-zA-Z0-9_-]{1,128})',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_request' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'rid'       => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'partition' => [
						'required'          => true,
						'sanitize_callback' => static fn ( $v ) => \max( 0, (int) $v ),
					],
				],
			]
		);
	}

	public function search_request( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$rid            = (string) $request->get_param( 'rid' );
		$config         = self::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';
		$entries_count  = 0;

		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$result = $this->find_request_index_entry( $log_base, $p, $rid, $entries_count );
			if ( null !== $result ) {
				return new \WP_REST_Response( $result, 200 );
			}
			if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
				break;
			}
		}

		return $this->not_found_error( "Request not found: rid={$rid}" );
	}

	public function get_request( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$rid            = (string) $request->get_param( 'rid' );
		$partition      = (int) $request->get_param( 'partition' );
		$config         = self::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';

		if ( $partition < 0 || $partition >= $num_partitions ) {
			return $this->not_found_error( 'Invalid partition.' );
		}

		$result = $this->find_request_in_partition( $log_base, $partition, $rid, $num_partitions );
		if ( null !== $result ) {
			return new \WP_REST_Response( $result, 200 );
		}

		return $this->not_found_error( "Request not found: rid={$rid}" );
	}

	private function find_request_index_entry( string $log_base, int $partition, string $rid, int &$entries_count ): ?array {
		$result   = null;
		$requests = ( new Partition( "{$log_base}/requests.log", $partition ) )->with_index(
			static fn ( $line, $position, &$data = null ) => RequestBuilder::format_index_entry( $line, $position, $data )
		);
		$requests->scan_index(
			function ( string $line ) use ( &$result, &$entries_count, $partition, $rid ) {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = RequestBuilder::parse_request_index( $line );
				if ( ! \is_array( $entry ) || \trim( (string) $entry['rid'] ) !== $rid ) {
					return null;
				}
				$result = [
					'rid'       => $rid,
					'partition' => $partition,
					'url_hash'  => \trim( (string) $entry['url_hash'] ),
				];
				return false;
			},
			true
		);
		return $result;
	}

	private function find_request_in_partition( string $log_base, int $partition, string $rid, int $num_partitions ): ?array {
		$result        = null;
		$entries_count = 0;
		$requests      = ( new Partition( "{$log_base}/requests.log", $partition ) )->with_index(
			static fn ( $line, $position, &$data = null ) => RequestBuilder::format_index_entry( $line, $position, $data )
		);
		$requests->scan_index(
			function ( string $line ) use ( &$result, &$entries_count, $requests, $rid ) {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = RequestBuilder::parse_request_index( $line );
				if ( ! \is_array( $entry ) || \trim( (string) $entry['rid'] ) !== $rid ) {
					return null;
				}
				$data = $requests->read_at(
					(int) ( $entry['segment_id'] ?? 0 ),
					(int) ( $entry['offset'] ?? 0 ),
					(int) ( $entry['length'] ?? 0 )
				);
				if ( '' === $data ) {
					return false;
				}
				$decoded = \json_decode( \trim( $data ), true, 64 );
				if ( ! \is_array( $decoded ) ) {
					return false;
				}
				$decoded['url_hash'] = \trim( (string) $entry['url_hash'] );
				$result              = $decoded;
				return false;
			},
			true
		);

		if ( null === $result ) {
			return null;
		}

		// Pull flame data — flame entries can live in any partition since
		// FlameBuilder writes to whatever partition it's wired into.
		$flame = $this->find_flame_for_rid( $log_base, $rid, $num_partitions );
		if ( null !== $flame ) {
			$result['flame_data'] = $flame;
		}

		return $result;
	}

	private function find_flame_for_rid( string $log_base, string $rid, int $num_partitions ): ?array {
		$entries_count = 0;
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$flames = ( new Partition( "{$log_base}/flames.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => FlameBuilder::format_index_entry( $line, $position, $data )
			);
			$result = null;
			$flames->scan_index(
				function ( string $line ) use ( &$result, &$entries_count, $flames, $rid ) {
					++$entries_count;
					if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = FlameBuilder::parse_flame_index( $line );
					if ( ! \is_array( $entry ) || \trim( (string) ( $entry['rid'] ?? '' ) ) !== $rid ) {
						return null;
					}
					$data = $flames->read_at(
						(int) ( $entry['segment_id'] ?? 0 ),
						(int) ( $entry['offset'] ?? 0 ),
						(int) ( $entry['length'] ?? 0 )
					);
					if ( '' === $data ) {
						return false;
					}
					$decoded = \json_decode( \trim( $data ), true, 64 );
					if ( \is_array( $decoded ) ) {
						$result = $decoded;
					}
					return false;
				},
				true
			);
			if ( null !== $result ) {
				return $result;
			}
			if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
				break;
			}
		}
		return null;
	}
}
