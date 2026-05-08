<?php
/**
 * PerfOverviewController: GET /performance/overview
 *
 * Dashboard summary endpoint. Aggregates across all partitions:
 *   total_urls, total_requests, global_avg_ms, global_avg_peak_mb,
 *   slowest_urls, most_requested, aggregate_time_series,
 *   global_leaderboard.
 *
 * Optional query params:
 *   ?breakdown=<dim>     adds breakdown_time_series for status|method|server|country|from|ua|ja4
 *   ?server=<name>       scopes leaderboard / breakdown to a single server
 *   ?categories=true     adds category_time_series (global or per-server)
 *
 * Reads from `Stats_Store` per partition + merges into one shape.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Cache_Interface;
use Newspack_Event_Logger_Nodes\Stats_Store;

class PerfOverviewController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public const DIMENSIONS = [ 'status', 'method', 'server', 'country', 'from', 'ua', 'ja4' ];

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/performance/overview',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_overview' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
				'args'                => [
					'breakdown'  => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'server'     => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'categories' => [
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => static fn ( $v ) => \filter_var( $v, FILTER_VALIDATE_BOOLEAN ),
					],
				],
			]
		);
	}

	public function get_overview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$server     = (string) ( $request->get_param( 'server' ) ?? '' );
		$breakdown  = (string) ( $request->get_param( 'breakdown' ) ?? '' );
		$categories = (bool) $request->get_param( 'categories' );

		$index = $this->load_index();

		// Build slowest list ordered by p95_ms.
		$slowest = $index;
		\usort( $slowest, static fn ( $a, $b ) => ( $b['p95_ms'] ?? 0 ) <=> ( $a['p95_ms'] ?? 0 ) );

		// Aggregate time series — merge hourly buckets across partitions.
		$time_series       = $this->merge_hourly_across_partitions();
		$total_requests    = 0;
		$total_sum_ms      = 0.0;
		$total_sum_peak_mb = 0.0;
		foreach ( $time_series as $row ) {
			$total_requests    += (int) ( $row['count'] ?? 0 );
			$total_sum_ms      += (float) ( $row['sum_ms'] ?? 0 );
			$total_sum_peak_mb += (float) ( $row['sum_peak_mb'] ?? 0 );
		}

		$response = [
			'total_urls'            => \count( $index ),
			'total_requests'        => $total_requests,
			'global_avg_ms'         => $total_requests > 0 ? $total_sum_ms / $total_requests : 0.0,
			'global_avg_peak_mb'    => $total_requests > 0 ? $total_sum_peak_mb / $total_requests : 0.0,
			'slowest_urls'          => \array_slice( $slowest, 0, 10 ),
			'most_requested'        => \array_slice( $index, 0, 10 ),
			'aggregate_time_series' => $time_series,
			'global_leaderboard'    => $server
				? $this->build_server_leaderboard( $server )
				: $this->build_global_leaderboard(),
		];

		if ( '' !== $breakdown && \in_array( $breakdown, self::DIMENSIONS, true ) ) {
			$response['breakdown_time_series'] = $this->merge_dim_across_partitions( $breakdown, $server );
		}

		if ( $categories ) {
			$response['category_time_series'] = '' === $server
				? $this->merge_categories_across_partitions()
				: $this->merge_server_categories_across_partitions( $server );
		}

		return new \WP_REST_Response( $response, 200 );
	}

	// ---------------------------------------------------------------------
	// Stats_Store accessors. Each fans out across all partitions and merges.
	// ---------------------------------------------------------------------

	/**
	 * Build the merged URL index list. Mirrors the legacy `load_index()`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function load_index(): array {
		$result = [];
		foreach ( $this->stats_stores() as $store ) {
			// Walk the recent buckets (~24 hours) and aggregate into a URL index.
			$buckets = $this->recent_url_buckets();
			$rows    = $store->get_url_buckets( $buckets );
			foreach ( $rows as $bucket_data ) {
				if ( ! \is_array( $bucket_data ) ) {
					continue;
				}
				foreach ( $bucket_data as $url => $stats ) {
					$hash = \substr( \hash( 'sha256', (string) $url ), 0, 12 );
					if ( ! isset( $result[ $hash ] ) ) {
						$result[ $hash ] = [
							'hash'         => $hash,
							'url'          => $url,
							'count'        => 0,
							'sum_ms'       => 0.0,
							'last_seen'    => 0,
						];
					}
					$result[ $hash ]['count']  += (int) ( $stats['count'] ?? 0 );
					$result[ $hash ]['sum_ms'] += (float) ( $stats['sum_req_time'] ?? 0 ) * 1000.0;
				}
			}
		}

		// Convert into the display shape the React tree expects.
		$out = [];
		foreach ( $result as $entry ) {
			$count      = (int) $entry['count'];
			$out[]      = [
				'hash'         => $entry['hash'],
				'url'          => $entry['url'],
				'count'        => $count,
				'avg_ms'       => $count > 0 ? $entry['sum_ms'] / $count : 0.0,
				'min_ms'       => 0,
				'max_ms'       => 0,
				'p50_ms'       => 0,
				'p95_ms'       => 0,
				'p99_ms'       => 0,
				'avg_peak_mb'  => 0,
				'max_peak_mb'  => 0,
				'last_updated' => (int) $entry['last_seen'],
			];
		}
		\usort( $out, static fn ( $a, $b ) => $b['count'] <=> $a['count'] );
		return $out;
	}

	private function merge_hourly_across_partitions(): array {
		$merged = [];
		foreach ( $this->stats_stores() as $store ) {
			foreach ( $store->get_hourly() as $hour => $row ) {
				if ( ! isset( $merged[ $hour ] ) ) {
					$merged[ $hour ] = [
						'hour'        => $hour,
						'count'       => 0,
						'sum_ms'      => 0.0,
						'sum_peak_mb' => 0.0,
					];
				}
				$merged[ $hour ]['count']       += (int) ( $row['count'] ?? 0 );
				$merged[ $hour ]['sum_ms']      += (float) ( $row['sum_ms'] ?? 0 );
				$merged[ $hour ]['sum_peak_mb'] += (float) ( $row['sum_peak_mb'] ?? 0 );
			}
		}
		\ksort( $merged );
		return \array_values( $merged );
	}

	private function build_global_leaderboard(): array {
		$count        = 0;
		$sum_req_time = 0.0;
		$sums         = [];
		$buckets      = $this->recent_url_buckets();
		foreach ( $this->stats_stores() as $store ) {
			foreach ( $buckets as $b ) {
				$row = $store->get_leaderboard_bucket( $b );
				if ( empty( $row ) ) {
					continue;
				}
				$count        += (int) ( $row['count'] ?? 0 );
				$sum_req_time += (float) ( $row['sum_req_time'] ?? 0 );
				foreach ( ( $row['categories'] ?? [] ) as $cat => $data ) {
					if ( ! isset( $sums[ $cat ] ) ) {
						$sums[ $cat ] = [
							'samples'   => 0,
							'sum_time'  => 0.0,
							'sum_count' => 0.0,
							'entries'   => [],
						];
					}
					$sums[ $cat ]['samples']   += (int) ( $data['samples'] ?? 0 );
					$sums[ $cat ]['sum_time']  += (float) ( $data['sum_time'] ?? 0 );
					$sums[ $cat ]['sum_count'] += (float) ( $data['sum_count'] ?? 0 );
				}
			}
		}
		return Stats_Store::sums_to_display( $count, $sum_req_time, $sums );
	}

	private function build_server_leaderboard( string $server ): array {
		$count        = 0;
		$sum_req_time = 0.0;
		$sums         = [];
		$buckets      = $this->recent_url_buckets();
		foreach ( $this->stats_stores() as $store ) {
			foreach ( $buckets as $b ) {
				$row = $store->get_server_leaderboard_bucket( $server, $b );
				if ( empty( $row ) ) {
					continue;
				}
				$count        += (int) ( $row['count'] ?? 0 );
				$sum_req_time += (float) ( $row['sum_req_time'] ?? 0 );
				foreach ( ( $row['categories'] ?? [] ) as $cat => $data ) {
					if ( ! isset( $sums[ $cat ] ) ) {
						$sums[ $cat ] = [
							'samples'   => 0,
							'sum_time'  => 0.0,
							'sum_count' => 0.0,
							'entries'   => [],
						];
					}
					$sums[ $cat ]['samples']   += (int) ( $data['samples'] ?? 0 );
					$sums[ $cat ]['sum_time']  += (float) ( $data['sum_time'] ?? 0 );
					$sums[ $cat ]['sum_count'] += (float) ( $data['sum_count'] ?? 0 );
				}
			}
		}
		return Stats_Store::sums_to_display( $count, $sum_req_time, $sums );
	}

	private function merge_dim_across_partitions( string $dimension, string $server ): array {
		$merged = [];
		foreach ( $this->stats_stores() as $store ) {
			$rows = $store->get_dimensional( $dimension, $server );
			foreach ( $rows as $bucket => $values ) {
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

	private function merge_categories_across_partitions(): array {
		$merged = [];
		foreach ( $this->stats_stores() as $store ) {
			foreach ( $store->get_categories() as $bucket => $values ) {
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

	private function merge_server_categories_across_partitions( string $server ): array {
		$merged = [];
		foreach ( $this->stats_stores() as $store ) {
			foreach ( $store->get_server_categories( $server ) as $bucket => $values ) {
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

	/**
	 * Build a list of recent 5-min bucket keys spanning the retention window.
	 * Capped at 288 (24h × 12 buckets/h) so memcache get_multi stays bounded.
	 *
	 * @return array<int,string>
	 */
	private function recent_url_buckets(): array {
		$now    = \time();
		$max    = 288;
		$out    = [];
		for ( $i = 0; $i < $max; $i++ ) {
			$ts = $now - ( $i * 300 );
			$min        = (int) \gmdate( 'i', $ts );
			$bucket_min = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', \STR_PAD_LEFT );
			$out[]      = \gmdate( 'Y-m-d-H', $ts ) . '-' . $bucket_min;
		}
		return \array_unique( $out );
	}

	/**
	 * One Stats_Store per partition over the shared cache.
	 *
	 * @return array<int,Stats_Store>
	 */
	protected function stats_stores(): array {
		$config         = self::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$max_lifespan   = (int) ( $config['max_lifespan'] ?? 86400 );
		$cache          = $this->resolve_cache();
		$stores         = [];
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$stores[] = new Stats_Store( $cache, $p, $max_lifespan );
		}
		return $stores;
	}

	private function resolve_cache(): Cache_Interface {
		return self::cache();
	}
}
