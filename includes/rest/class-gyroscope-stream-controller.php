<?php
/**
 * GyroscopeStreamController: GET /firehose/gyroscope — in-flight requests SSE.
 *
 * Multiplexes ALL partitions into a single SSE stream and feeds each line
 * through `InflightTracker`, which maintains the per-rid stack and emits
 * "active" + "completed" snapshots.
 *
 * Mounted at a different route from the existing sync `GyroscopeController`
 * (which serves `/gyroscope/timeline?request_id=...`). Both can coexist.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\InflightTracker;
use Newspack_Event_Logger_Nodes\Partition_Reader;
use Newspack_Nodes\Partition;

class GyroscopeStreamController extends SSEControllerBase {

	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/firehose/gyroscope',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'stream' ],
				'permission_callback' => [ $this, 'stream_permissions_check' ],
				'args'                => [
					'interval' => [
						'default'           => 1000,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $v ) => \max( 100, \min( 10000, (int) $v ) ),
					],
				],
			]
		);
	}

	public function stream( \WP_REST_Request $request ) {
		$result = $this->stream_run( $request );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}
		exit;
	}

	/**
	 * Loop body. Differs from the generic `stream_log_run` template in two ways:
	 *  1. Reads from EVERY partition (not one), feeding all into one InflightTracker.
	 *  2. Emits two events at the digest interval: `inflight` (active snapshot)
	 *     and `complete_batch` (drained completed buffer).
	 */
	protected function stream_run( \WP_REST_Request $request ): mixed {
		$digest_interval = (int) $request->get_param( 'interval' );

		$context = $this->start_sse_stream(
			[
				'num_partitions' => 0,
				'interval'       => $digest_interval,
			]
		);

		if ( \is_wp_error( $context ) ) {
			return $context;
		}

		$log_base       = $context['log_base'];
		$num_partitions = $context['num_partitions'];
		$tracker        = new InflightTracker();

		$readers      = [];
		$file_handles = [];
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$partition          = new Partition( "{$log_base}/firehose.log", $p );
			$readers[ $p ]      = new Partition_Reader( $partition );
			$readers[ $p ]->next_offset( 'end' );
			$file_handles[ $p ] = null;
		}

		$this->send_sse_event(
			'config',
			[
				'num_partitions' => $num_partitions,
				'interval'       => $digest_interval,
			]
		);

		$last_digest         = \microtime( true );
		$last_heartbeat      = \time();
		$digest_interval_sec = $digest_interval / 1000.0;

		try {
			while ( $this->should_continue_stream( $context ) ) {
				$did_work      = false;
				$all_caught_up = true;

				foreach ( $readers as $p => $reader ) {
					$fh = $file_handles[ $p ];
					if ( \is_resource( $fh ) ) {
						$line = \fgets( $fh );
						$meta = \stream_get_meta_data( $fh );
						if ( ! empty( $meta['timed_out'] ) ) {
							continue;
						}
						if ( false !== $line ) {
							$reader->update_offset();
							$tracker->process_line( \trim( $line ) );
							$did_work = true;
						} else {
							$reader->mark_eof();
							$reader->update_offset();
							$file_handles[ $p ] = $reader->next_segment();
						}
					} else {
						$file_handles[ $p ] = $reader->open();
						if ( \is_resource( $file_handles[ $p ] ) ) {
							\stream_set_timeout( $file_handles[ $p ], 1 );
						}
					}
					if ( \is_resource( $file_handles[ $p ] ) && ! $reader->is_caught_up() ) {
						$all_caught_up = false;
					}
				}

				$completed = $tracker->get_completed();
				if ( ! empty( $completed ) ) {
					$this->send_sse_event( 'complete_batch', $completed );
				}

				$now = \microtime( true );
				if ( $now - $last_digest >= $digest_interval_sec ) {
					$active = $tracker->get_active();
					$this->send_sse_event(
						'inflight',
						[
							'requests' => $active,
							'count'    => \count( $active ),
							'time'     => $now,
						]
					);
					$last_digest = $now;
				}

				if ( $all_caught_up && ! $did_work ) {
					$hb_now = \time();
					if ( $hb_now - $last_heartbeat >= static::HEARTBEAT_INTERVAL ) {
						$this->send_sse_event( 'heartbeat', [ 'ts' => $hb_now ] );
						$last_heartbeat = $hb_now;
					}
					$this->flush_if_needed();
					\usleep( 10000 );
				} elseif ( ! $did_work ) {
					\usleep( 1000 );
				}
			}
		} finally {
			foreach ( $readers as $reader ) {
				$reader->close();
			}
			$this->end_sse_stream();
		}
		return null;
	}
}
