<?php
/**
 * FirehoseStreamController: GET /firehose/stream — raw firehose SSE.
 *
 * Streams raw `firehose.log` entries from one partition. Used by browsers and
 * by aggregator (StreamMerger) connections; the latter passes `aggregator=true`
 * which engages the per-partition slot pool and the longer slot TTL.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Partition_Reader;
use Newspack_Nodes\Partition;

class FirehoseStreamController extends SSEControllerBase {

	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/firehose/stream',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'stream' ],
				'permission_callback' => [ $this, 'stream_permissions_check' ],
				'args'                => [
					'partition'  => [
						'default'           => 0,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $v ) => \max( 0, (int) $v ),
						'validate_callback' => static function ( $v ) {
							$config         = PerformanceControllerBase::load_config();
							$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
							return $v >= 0 && $v < $num_partitions;
						},
					],
					'segment_id' => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $v ) => \max( 0, (int) $v ),
					],
					'offset'     => [
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => static fn ( $v ) => \max( 0, (int) $v ),
					],
					'aggregator' => [
						'required'          => false,
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => static fn ( $v ) => \filter_var( $v, FILTER_VALIDATE_BOOLEAN ),
					],
				],
			]
		);
	}

	/**
	 * Stream raw firehose entries via SSE. Exits the request on completion.
	 */
	public function stream( \WP_REST_Request $request ) {
		$result = $this->stream_run( $request );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}
		exit;
	}

	/**
	 * Same loop as the legacy controller, scaled to one partition. Single-reader
	 * tail with explicit resume; emits `entry` per JSON line and `heartbeat` on
	 * idle.
	 */
	protected function stream_run( \WP_REST_Request $request ): mixed {
		$partition  = (int) $request->get_param( 'partition' );
		$segment_id = $request->get_param( 'segment_id' );
		$offset     = $request->get_param( 'offset' );
		$aggregator = (bool) $request->get_param( 'aggregator' );

		$hostname = \gethostname() ?: 'unknown';
		$context  = $this->start_sse_stream(
			[
				'partition' => $partition,
				'log'       => 'firehose.log',
			],
			[ 'X-Server-Id' => $hostname ],
			$aggregator
		);

		if ( \is_wp_error( $context ) ) {
			return $context;
		}

		$log_base   = $context['log_base'];
		$partn_obj  = new Partition( "{$log_base}/firehose.log", $partition );
		$reader     = new Partition_Reader( $partn_obj, 'end' );

		// Explicit resume position takes priority over default 'end'.
		if ( null !== $segment_id && null !== $offset ) {
			$reader->next_offset(
				[
					'segment_id' => (int) $segment_id,
					'offset'     => (int) $offset,
				]
			);
		} elseif ( null !== $offset ) {
			// Legacy: offset within the current segment.
			$reader->next_offset(
				[
					'segment_id' => $reader->get_segment_id(),
					'offset'     => (int) $offset,
				]
			);
		}

		$last_heartbeat = \time();

		try {
			while ( $this->should_continue_stream( $context ) ) {
				$fh = $reader->open();
				if ( ! \is_resource( $fh ) ) {
					$now = \time();
					if ( $now - $last_heartbeat >= static::HEARTBEAT_INTERVAL ) {
						$this->send_sse_event(
							'heartbeat',
							[
								'ts'        => $now,
								'partition' => $partition,
								'position'  => $reader->get_position(),
							]
						);
						$last_heartbeat = $now;
					}
					$this->flush_if_needed();
					\usleep( 100000 );
					continue;
				}

				$lines_read = 0;
				while ( null !== ( $line = $reader->read_line() ) ) {
					$trimmed = \trim( $line );
					if ( '' === $trimmed ) {
						continue;
					}
					// Lines are packed Messages; the entry payload lives at VALUE.
					$decoded = \json_decode( $trimmed, true, 64 );
					$entry   = \is_array( $decoded ) ? ( $decoded[ \Newspack_Nodes\Message::VALUE ] ?? null ) : null;
					if ( ! \is_array( $entry ) ) {
						continue;
					}
					$entry['position'] = $reader->get_position();
					$this->send_sse_event( 'entry', $entry );
					++$lines_read;
					if ( 0 === $lines_read % 100 && \connection_aborted() ) {
						break 2;
					}
				}

				if ( $reader->is_caught_up() ) {
					$now = \time();
					if ( $now - $last_heartbeat >= static::HEARTBEAT_INTERVAL ) {
						$this->send_sse_event(
							'heartbeat',
							[
								'ts'        => $now,
								'partition' => $partition,
								'position'  => $reader->get_position(),
							]
						);
						$last_heartbeat = $now;
					}
					$this->flush_if_needed();
					\usleep( 100000 );
				} else {
					$reader->next_segment();
				}
			}
		} finally {
			$reader->close();
			$this->end_sse_stream();
		}
		return null;
	}
}
