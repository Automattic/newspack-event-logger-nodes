<?php
/**
 * EventsController: GET /events/recent, /events/stats.
 *
 * Plumbing for the `event-dashboards` React tree. /events/recent walks the
 * firehose index newest-first and returns the most recent N entries (with
 * a hard cap of MAX_INDEX_ENTRIES so a missing-rid scan can't escalate).
 * /events/stats merges hourly buckets across all partitions.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Cache_Interface;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Nodes\Partition;
use Newspack_Nodes\Config as RuntimeConfig;

class EventsController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public const MAX_INDEX_ENTRIES = 100000;

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/events/recent',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_recent' ],
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
			'/events/stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
	}

	public function get_recent( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
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
			$partition = new Partition( "{$log_base}/firehose.log", $p );
			$partition->scan_index(
				function ( $seg, $off ) use ( &$entries, &$scanned, $limit, $partition, $p ) {
					++$scanned;
					if ( $scanned > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					if ( \count( $entries ) >= $limit ) {
						return false;
					}
					// Default 8-byte index format (binary). We only have segment_id +
					// offset; pull the line via read_at() up to a generous cap.
					if ( ! \is_int( $seg ) || ! \is_int( $off ) ) {
						return null;
					}
					// We don't know exact length; read up to 4KB — enough for any
					// PIPE_BUF-sized firehose line.
					$bytes = $partition->read_at( $seg, $off, 4096 );
					if ( '' === $bytes ) {
						return null;
					}
					// First newline-delimited line of the chunk.
					$nl   = \strpos( $bytes, "\n" );
					$line = false === $nl ? $bytes : \substr( $bytes, 0, $nl );
					if ( '' === $line ) {
						return null;
					}
					// Line is a packed Message; the entry payload lives at VALUE.
					$decoded = \json_decode( $line, true, 64 );
					$entry   = \is_array( $decoded ) ? ( $decoded[ \Newspack_Nodes\Message::VALUE ] ?? null ) : null;
					if ( ! \is_array( $entry ) ) {
						return null;
					}
					// rid lives in Message::KEY on the wire; back-fill so the
					// REST response carries it.
					$entry['rid']         = (string) ( $decoded[ \Newspack_Nodes\Message::KEY ] ?? '' );
					$entry['_partition']  = $p;
					$entries[]            = $entry;
					return null;
				},
				true
			);
			if ( $scanned > self::MAX_INDEX_ENTRIES ) {
				break;
			}
		}

		// data + meta keeps the legacy stub shape so existing tests + clients still pass.
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

	public function get_stats( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$config         = RuntimeConfig::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$max_lifespan   = (int) ( $config['max_lifespan'] ?? 86400 );
		$cache          = $this->resolve_cache();

		$merged = [];
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$store = new Stats_Store( $cache, $p, $max_lifespan );
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

		return new \WP_REST_Response(
			[
				'data' => [
					'time_series' => \array_values( $merged ),
				],
				'meta' => [],
			],
			200
		);
	}

	private function resolve_cache(): Cache_Interface {
		return self::cache();
	}
}
