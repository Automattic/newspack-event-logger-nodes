<?php
/**
 * PerfUrlsController:
 *   GET /performance/urls          — paginated, sortable URL list.
 *   GET /performance/urls/{hash}   — full URL detail (stats + recent + flame).
 *
 * Hash regex `[a-f0-9]{8,64}` matches the storage shape produced by
 * RequestBuilder::format_index_entry. Walks all partitions for the index;
 * caps scans at MAX_INDEX_ENTRIES (100k) so a missing-rid request can't
 * become a partition-wide segment scan.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Nodes\Partition;

class PerfUrlsController extends PerfOverviewController {

	public const MAX_INDEX_ENTRIES = 100000;

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/performance/urls',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_urls' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'sort'   => [
						'default'           => 'count',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'order'  => [
						'default'           => 'desc',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'limit'  => [
						'default'           => 50,
						'sanitize_callback' => static fn ( $v ) => \min( 1000, \max( 1, (int) $v ) ),
					],
					'offset' => [
						'default'           => 0,
						'sanitize_callback' => static fn ( $v ) => \min( 10000, \max( 0, (int) $v ) ),
					],
					'search' => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'server' => [
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/performance/urls/(?P<hash>[a-f0-9]{8,64})',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_url_detail' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'hash'         => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'breakdown'    => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'error_status' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'categories'   => [
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => static fn ( $v ) => \filter_var( $v, FILTER_VALIDATE_BOOLEAN ),
					],
				],
			]
		);
	}

	public function get_urls( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$sort   = (string) $request->get_param( 'sort' );
		$order  = (string) $request->get_param( 'order' );
		$limit  = (int) $request->get_param( 'limit' );
		$offset = (int) $request->get_param( 'offset' );
		$search = (string) $request->get_param( 'search' );
		$server = (string) $request->get_param( 'server' );

		$valid_sorts = [ 'count', 'url', 'avg_ms', 'min_ms', 'max_ms', 'p95_ms', 'avg_peak_mb', 'last_updated' ];
		if ( ! \in_array( $sort, $valid_sorts, true ) ) {
			$sort = 'count';
		}
		if ( ! \in_array( $order, [ 'asc', 'desc' ], true ) ) {
			$order = 'desc';
		}

		$index = $this->load_index();

		if ( '' !== $server ) {
			$srv   = \strtolower( $server );
			$index = \array_values( \array_filter( $index, static fn ( $e ) => false !== \strpos( \strtolower( (string) ( $e['url'] ?? '' ) ), $srv ) ) );
		}
		if ( '' !== $search ) {
			$term  = \strtolower( $search );
			$index = \array_values( \array_filter( $index, static fn ( $e ) => false !== \strpos( \strtolower( (string) ( $e['url'] ?? '' ) ), $term ) ) );
		}

		$total = \count( $index );

		\usort(
			$index,
			static fn ( $a, $b ) => 'asc' === $order
				? ( $a[ $sort ] ?? 0 ) <=> ( $b[ $sort ] ?? 0 )
				: ( $b[ $sort ] ?? 0 ) <=> ( $a[ $sort ] ?? 0 )
		);

		return new \WP_REST_Response(
			[
				'data'   => \array_slice( $index, $offset, $limit ),
				'total'  => $total,
				'limit'  => $limit,
				'offset' => $offset,
			],
			200
		);
	}

	public function get_url_detail( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$hash = (string) $request->get_param( 'hash' );
		if ( ! \preg_match( '/^[a-f0-9]{8,64}$/', $hash ) ) {
			return new \WP_Error( 'invalid_hash', 'Invalid hash format', [ 'status' => 400 ] );
		}

		// Stats from the URL index (merged across partitions).
		$stats = $this->find_url_stats( $hash );
		if ( null === $stats ) {
			return $this->not_found_error( "URL not found: {$hash}" );
		}

		$requests = $this->find_recent_requests_for_url( $hash );

		// Optional error_status filter (`F` = failed, `T` = timed out).
		$error_filter = (string) ( $request->get_param( 'error_status' ) ?? '' );
		if ( '' !== $error_filter ) {
			$allowed  = \array_map( 'trim', \explode( ',', $error_filter ) );
			$requests = \array_values( \array_filter( $requests, static fn ( $r ) => \in_array( (string) ( $r['error_status'] ?? '' ), $allowed, true ) ) );
		}

		// Aggregate flame (any partition; whichever has the URL stats blob).
		$aggregate_stats   = $this->find_url_aggregate( $hash );
		$aggregate_flame   = $aggregate_stats['flame']
			?? [ 'name' => 'aggregate', 'value' => 0, 'children' => [] ];
		$aggregate_profiles = $aggregate_stats['profiles'] ?? null;

		$breakdown             = (string) ( $request->get_param( 'breakdown' ) ?? '' );
		$breakdown_time_series = null;
		if ( '' !== $breakdown && \in_array( $breakdown, self::DIMENSIONS, true ) ) {
			$breakdown_time_series = $this->merge_url_dim( $hash, $breakdown );
		}

		$category_time_series = null;
		if ( $request->get_param( 'categories' ) ) {
			$category_time_series = $this->merge_url_categories( $hash );
		}

		return new \WP_REST_Response(
			[
				'stats'                 => $stats,
				'requests'              => $requests,
				'aggregate_flame'       => $aggregate_flame,
				'aggregate_profiles'    => $aggregate_profiles,
				'last_modified'         => $aggregate_stats['last_modified'] ?? 0,
				'breakdown_time_series' => $breakdown_time_series,
				'category_time_series'  => $category_time_series,
			],
			200
		);
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private function find_url_stats( string $hash ): ?array {
		$index   = $this->load_index();
		$scanned = 0;
		foreach ( $index as $entry ) {
			++$scanned;
			if ( $scanned > self::MAX_INDEX_ENTRIES ) {
				break;
			}
			if ( ( $entry['hash'] ?? '' ) === $hash ) {
				return [
					'hash'         => $hash,
					'url'          => $entry['url'] ?? '',
					'count'        => $entry['count'] ?? 0,
					'avg_ms'       => $entry['avg_ms'] ?? 0,
					'min_ms'       => $entry['min_ms'] ?? 0,
					'max_ms'       => $entry['max_ms'] ?? 0,
					'p50_ms'       => $entry['p50_ms'] ?? 0,
					'p95_ms'       => $entry['p95_ms'] ?? 0,
					'p99_ms'       => $entry['p99_ms'] ?? 0,
					'avg_peak_mb'  => $entry['avg_peak_mb'] ?? 0,
					'max_peak_mb'  => $entry['max_peak_mb'] ?? 0,
					'last_updated' => $entry['last_updated'] ?? 0,
					'time_series'  => $this->build_url_time_series( $hash ),
				];
			}
		}
		return null;
	}

	/**
	 * Per-URL time series across recent buckets. Stats_Store stores per-bucket
	 * URL stats keyed by hash; we walk every bucket and emit one entry per
	 * non-empty bucket: `[bucket => {count, sum_ms, sum_peak_mb}]`.
	 */
	private function build_url_time_series( string $hash ): array {
		$buckets = $this->recent_url_buckets();
		$series  = [];
		foreach ( $this->stats_stores() as $store ) {
			$rows = $store->get_url_buckets( $buckets );
			foreach ( $rows as $bucket_key => $bucket_data ) {
				if ( ! \is_array( $bucket_data ) || ! isset( $bucket_data[ $hash ] ) ) {
					continue;
				}
				$stats = $bucket_data[ $hash ];
				$count = (int) ( $stats['count'] ?? 0 );
				if ( 0 === $count ) {
					continue;
				}
				$sum_ms = isset( $stats['sum_ms'] )
					? (float) $stats['sum_ms']
					: (float) ( $stats['sum_req_time'] ?? 0 ) * 1000.0;
				if ( ! isset( $series[ $bucket_key ] ) ) {
					$series[ $bucket_key ] = [
						'count'       => 0,
						'sum_ms'      => 0.0,
						'sum_peak_mb' => 0.0,
					];
				}
				$series[ $bucket_key ]['count']       += $count;
				$series[ $bucket_key ]['sum_ms']      += $sum_ms;
				$series[ $bucket_key ]['sum_peak_mb'] += (float) ( $stats['sum_peak_mb'] ?? 0 );
			}
		}
		\ksort( $series );
		return $series;
	}

	private function find_url_aggregate( string $hash ): ?array {
		foreach ( $this->stats_stores() as $store ) {
			$stats = $store->get_url_stats( $hash );
			if ( null !== $stats ) {
				return $stats;
			}
		}
		return null;
	}

	private function merge_url_dim( string $hash, string $dimension ): array {
		$merged = [];
		foreach ( $this->stats_stores() as $store ) {
			$rows = $store->get_url_dimensional( $hash );
			$dim  = $rows[ $dimension ] ?? [];
			foreach ( $dim as $bucket => $values ) {
				if ( ! isset( $merged[ $bucket ] ) ) {
					$merged[ $bucket ] = [];
				}
				foreach ( $values as $name => $entry ) {
					if ( ! isset( $merged[ $bucket ][ $name ] ) ) {
						$merged[ $bucket ][ $name ] = [ 'c' => 0, 's' => 0.0, 'm' => 0.0 ];
					}
					$merged[ $bucket ][ $name ]['c'] += (int) ( $entry['c'] ?? 0 );
					$merged[ $bucket ][ $name ]['s'] += (float) ( $entry['s'] ?? 0 );
					$merged[ $bucket ][ $name ]['m'] += (float) ( $entry['m'] ?? 0 );
				}
			}
		}
		\ksort( $merged );
		return $merged;
	}

	private function merge_url_categories( string $hash ): array {
		$merged = [];
		foreach ( $this->stats_stores() as $store ) {
			foreach ( $store->get_url_categories( $hash ) as $bucket => $values ) {
				if ( ! isset( $merged[ $bucket ] ) ) {
					$merged[ $bucket ] = [];
				}
				foreach ( $values as $cat => $entry ) {
					if ( ! isset( $merged[ $bucket ][ $cat ] ) ) {
						$merged[ $bucket ][ $cat ] = [ 't' => 0.0, 'c' => 0.0, 'n' => 0 ];
					}
					$merged[ $bucket ][ $cat ]['t'] += (float) ( $entry['t'] ?? 0 );
					$merged[ $bucket ][ $cat ]['c'] += (float) ( $entry['c'] ?? 0 );
					$merged[ $bucket ][ $cat ]['n'] += (int) ( $entry['n'] ?? 0 );
				}
			}
		}
		\ksort( $merged );
		return $merged;
	}

	private function find_recent_requests_for_url( string $url_hash ): array {
		$requests       = [];
		$entries_count  = 0;
		$config         = self::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';

		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$partition = ( new Partition( "{$log_base}/requests.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => RequestBuilder::format_index_entry( $line, $position, $data )
			);
			$partition->scan_index(
				function ( string $line, int $segment_id ) use ( &$requests, &$entries_count, $url_hash, $p ) {
					++$entries_count;
					if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = RequestBuilder::parse_request_index( $line );
					if ( ! \is_array( $entry ) || \trim( (string) $entry['url_hash'] ) !== $url_hash ) {
						return null;
					}
					$requests[] = [
						'rid'          => \trim( (string) $entry['rid'] ),
						'timestamp'    => $entry['timestamp'] ?? 0,
						'duration_ms'  => $entry['duration_ms'] ?? 0,
						'status_code'  => $entry['status_code'] ?? 0,
						'peak_mb'      => $entry['peak_mb'] ?? 0,
						'method'       => $entry['method'] ?? '',
						'error_status' => $entry['error_status'] ?? null,
						'segment_id'   => $entry['segment_id'] ?? $segment_id,
						'offset'       => $entry['offset'] ?? 0,
						'length'       => $entry['length'] ?? 0,
						'partition'    => $p,
					];
					if ( \count( $requests ) >= 500 ) {
						return false;
					}
					return null;
				},
				true
			);
			if ( \count( $requests ) >= 500 || $entries_count > self::MAX_INDEX_ENTRIES ) {
				break;
			}
		}

		\usort( $requests, static fn ( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		$seen   = [];
		$unique = [];
		foreach ( $requests as $r ) {
			if ( ! isset( $seen[ $r['rid'] ] ) ) {
				$seen[ $r['rid'] ] = true;
				$unique[]          = $r;
				if ( \count( $unique ) >= 500 ) {
					break;
				}
			}
		}
		return $unique;
	}
}
