<?php
/**
 * WorkersController:
 *   GET  /performance/workers           — worker + standalone + log status.
 *   POST /performance/workers/restart   — request a worker restart via Lock flag.
 *
 * Sources worker descriptors from the runtime's Bootstrap::expand_workers()
 * (one row per topology × partition). Live cursor positions come from
 * memcache (`evlog:pos:{host}:{name}:p{N}`) when available, falling back to
 * the per-reader offsetlog provided by the
 * `newspack_event_logger_nodes/log_reader_positions` filter.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Cache_Interface;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Lock;
use Newspack_Nodes\Partition;

class WorkersController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/performance/workers',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_workers' ],
				'permission_callback' => [ $this, 'read_permissions_check' ],
			]
		);
		\register_rest_route(
			self::NAMESPACE,
			'/performance/workers/restart',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'restart_workers' ],
				'permission_callback' => [ $this, 'restart_permissions_check' ],
				'args'                => [
					'type'           => [
						'default'           => 'all',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'partition'      => [
						'default'           => 0,
						'sanitize_callback' => static fn ( $v ) => (int) $v,
					],
					'all_partitions' => [
						'default'           => false,
						'sanitize_callback' => static fn ( $v ) => \filter_var( $v, FILTER_VALIDATE_BOOLEAN ),
					],
					'nonce'          => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	public function restart_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		$base = $this->read_permissions_check();
		if ( \is_wp_error( $base ) ) {
			return $base;
		}
		$nonce = (string) $request->get_param( 'nonce' );
		if ( '' === $nonce || ! \function_exists( 'wp_verify_nonce' ) || ! \wp_verify_nonce( $nonce, 'newspack_nodes_restart_worker' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'Invalid or missing security nonce.',
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function get_workers( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$check = $this->check_rate_limit( $this->rate_limit_key() );
		if ( \is_wp_error( $check ) ) {
			return $check;
		}

		$now            = \time();
		$config         = self::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$num_segments   = (int) ( $config['num_segments'] ?? 8 );
		$segment_size   = (int) ( $config['segment_size'] ?? ( 16 * 1024 * 1024 ) );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';
		$locks_base     = $base_dir . '/locks';

		$workers    = [];
		$standalone = [];
		$logs       = [];

		// Discover topology workers via the runtime's expand_workers().
		$descriptors = [];
		if ( \class_exists( '\\Newspack_Nodes\\Bootstrap' ) ) {
			try {
				$descriptors = Bootstrap::expand_workers();
			} catch ( \Throwable $e ) {
				$descriptors = [];
			}
		}

		// Pull saved positions once — filter return shape:
		// [ name => [ partition => [ input_log => [ seg, off ] ] ] ]
		$saved_positions = [];
		if ( \function_exists( 'apply_filters' ) ) {
			$saved_positions = (array) \apply_filters( 'newspack_event_logger_nodes/log_reader_positions', [] );
		}

		// Pull additional reader configs (input_log/output_log per reader name).
		$readers = [];
		if ( \function_exists( 'apply_filters' ) ) {
			$readers = (array) \apply_filters( 'newspack_event_logger_nodes/log_readers', [] );
		}

		foreach ( $descriptors as $w ) {
			$type      = (string) ( $w['type'] ?? '' );
			$partition = (int) ( $w['partition'] ?? 0 );
			$stale_to  = (int) ( $w['stale_timeout'] ?? Lock::STALE_TIMEOUT );
			if ( '' === $type ) {
				continue;
			}
			$lock_dir   = "{$locks_base}/{$type}.p{$partition}.lock.d";
			$reader_cfg = $readers[ $type ] ?? [];
			$inputs     = $reader_cfg['inputs'] ?? [];
			$outputs    = $reader_cfg['outputs'] ?? [];
			$input_log  = \is_array( $inputs ) && ! empty( $inputs ) ? (string) $inputs[0] : 'firehose.log';
			$output_log = \is_array( $outputs ) && ! empty( $outputs ) ? (string) $outputs[0] : null;

			$worker = $this->build_worker_status(
				$type,
				$partition,
				$input_log,
				$output_log,
				$log_base,
				$lock_dir,
				$now,
				$stale_to,
				$saved_positions,
				$reader_cfg['handler'] ?? null
			);
			$workers[] = $worker;
		}

		// Standalone workers (supervisor + plugin-registered partitioned/non-partitioned).
		$standalone[] = $this->build_standalone_status(
			'supervisor',
			null,
			"{$locks_base}/supervisor.lock.d",
			$now,
			Lock::STALE_TIMEOUT
		);
		$standalone_workers = [];
		if ( \function_exists( 'apply_filters' ) ) {
			$standalone_workers = (array) \apply_filters( 'newspack_event_logger_nodes/standalone_workers', [] );
		}
		foreach ( $standalone_workers as $name => $cfg ) {
			$partitioned = ! empty( $cfg['partitions'] );
			if ( $partitioned ) {
				for ( $p = 0; $p < $num_partitions; $p++ ) {
					$standalone[] = $this->build_standalone_status( (string) $name, $p, "{$locks_base}/{$name}.p{$p}.lock.d", $now );
				}
			} else {
				$standalone[] = $this->build_standalone_status( (string) $name, null, "{$locks_base}/{$name}.lock.d", $now );
			}
		}

		// Terminal logs (outputs not consumed by any reader). Best-effort: scan
		// segment dirs so the React tree can render an indexed segments table
		// for them too.
		foreach ( $readers as $reader_cfg ) {
			$out_list = $reader_cfg['outputs'] ?? [];
			if ( ! \is_array( $out_list ) ) {
				continue;
			}
			foreach ( $out_list as $out ) {
				if ( ! \is_string( $out ) || '' === $out ) {
					continue;
				}
				// Add one row per partition for visibility.
				for ( $p = 0; $p < $num_partitions; $p++ ) {
					$logs[] = $this->build_log_segments_status(
						\str_replace( '.log', '', $out ),
						$p,
						"{$log_base}/{$out}/p{$p}"
					);
				}
			}
		}

		return new \WP_REST_Response(
			[
				'workers'        => $workers,
				'standalone'     => $standalone,
				'logs'           => $logs,
				'num_partitions' => $num_partitions,
				'num_segments'   => $num_segments,
				'segment_size'   => $segment_size,
				'timestamp'      => $now,
			],
			200
		);
	}

	public function restart_workers( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$type           = (string) $request->get_param( 'type' );
		$partition      = (int) $request->get_param( 'partition' );
		$all_partitions = (bool) $request->get_param( 'all_partitions' );

		$config         = self::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$locks_base     = $base_dir . '/locks';

		if ( ! $all_partitions && ( $partition < 0 || $partition >= $num_partitions ) ) {
			return new \WP_Error( 'invalid_partition', "Partition $partition out of range", [ 'status' => 400 ] );
		}
		$partitions = $all_partitions ? \range( 0, $num_partitions - 1 ) : [ $partition ];
		$results    = [];

		// Standalone first (supervisor / health-check / etc.)
		$standalone_workers = [];
		if ( \function_exists( 'apply_filters' ) ) {
			$standalone_workers = (array) \apply_filters( 'newspack_event_logger_nodes/standalone_workers', [] );
		}
		$is_standalone = ( 'supervisor' === $type ) || isset( $standalone_workers[ $type ] );

		if ( $is_standalone && 'all' !== $type ) {
			if ( 'supervisor' === $type ) {
				$results[] = [
					'type'      => 'supervisor',
					'partition' => null,
					'requested' => Lock::request_restart_at( "{$locks_base}/supervisor.lock.d" ),
				];
			} else {
				$cfg = $standalone_workers[ $type ];
				if ( ! empty( $cfg['partitions'] ) ) {
					foreach ( $partitions as $p ) {
						$results[] = [
							'type'      => $type,
							'partition' => $p,
							'requested' => Lock::request_restart_at( "{$locks_base}/{$type}.p{$p}.lock.d" ),
						];
					}
				} else {
					$results[] = [
						'type'      => $type,
						'partition' => null,
						'requested' => Lock::request_restart_at( "{$locks_base}/{$type}.lock.d" ),
					];
				}
			}
		} else {
			// Topology workers (firehose-workers, aggregator, plugin-registered).
			$descriptors = \class_exists( '\\Newspack_Nodes\\Bootstrap' ) ? Bootstrap::expand_workers() : [];
			$known_types = [];
			foreach ( $descriptors as $d ) {
				$known_types[ (string) ( $d['type'] ?? '' ) ] = true;
			}
			foreach ( $partitions as $p ) {
				foreach ( \array_keys( $known_types ) as $known ) {
					if ( '' === $known ) {
						continue;
					}
					if ( 'all' === $type || $known === $type ) {
						$results[] = [
							'type'      => $known,
							'partition' => $p,
							'requested' => Lock::request_restart_at( "{$locks_base}/{$known}.p{$p}.lock.d" ),
						];
					}
				}
			}
		}

		return new \WP_REST_Response(
			[
				'success' => true,
				'results' => $results,
			],
			200
		);
	}

	private function build_worker_status(
		string $type,
		int $partition,
		string $input_log,
		?string $output_log,
		string $log_base,
		string $lock_dir,
		int $now,
		int $stale_timeout,
		array $saved_positions,
		?string $handler_name
	): array {
		$partition_obj = new Partition( "{$log_base}/{$input_log}", $partition );
		$segments      = $partition_obj->get_segments();
		$total_size    = (int) \array_sum( \array_column( $segments, 'size' ) );

		// Cursor: prefer live (memcache); fall back to saved offsetlog.
		$cursor    = $this->get_live_position( $type, $partition, $input_log );
		if ( null === $cursor ) {
			$cursor = $saved_positions[ $type ][ $partition ][ $input_log ] ?? null;
		}
		$cursor_seg    = (int) ( $cursor['seg'] ?? 0 );
		$cursor_offset = (int) ( $cursor['off'] ?? 0 );

		// Bytes-behind: walk segments at/after cursor_seg, summing remaining bytes.
		$behind        = 0;
		$found_current = false;
		foreach ( $segments as $seg ) {
			$sid = (int) $seg['id'];
			if ( $sid === $cursor_seg ) {
				$found_current = true;
				$remaining     = (int) $seg['size'] - $cursor_offset;
				if ( $remaining > 0 ) {
					$behind += $remaining;
				}
			} elseif ( $found_current || $sid > $cursor_seg ) {
				$behind += (int) $seg['size'];
			}
		}

		// Status: heartbeat freshness inside the lock dir.
		$status        = 'dead';
		$heartbeat_age = null;
		$hb_file       = $lock_dir . '/heartbeat';
		if ( \file_exists( $hb_file ) ) {
			$mtime = @\filemtime( $hb_file );
			if ( false !== $mtime ) {
				$heartbeat_age = $now - (int) $mtime;
				if ( $heartbeat_age < $stale_timeout ) {
					$status = 'running';
				}
			}
		}

		return [
			'type'            => $type,
			'partition'       => $partition,
			'input_log'       => $input_log,
			'output_log'      => $output_log,
			'status'          => $status,
			'started_at'      => Lock::get_started_time( $lock_dir ),
			'heartbeat_age'   => $heartbeat_age,
			'restart_pending' => Lock::is_restart_pending( $lock_dir ),
			'segments'        => $segments,
			'total_size'      => $total_size,
			'cursor_seg'      => $cursor_seg,
			'cursor_offset'   => $cursor_offset,
			'behind'          => $behind,
			'handler'         => $handler_name,
		];
	}

	private function build_standalone_status( string $name, ?int $partition, string $lock_dir, int $now, int $stale_timeout = 60 ): array {
		$status        = 'dead';
		$heartbeat_age = null;
		$hb_file       = $lock_dir . '/heartbeat';
		if ( \file_exists( $hb_file ) ) {
			$mtime = @\filemtime( $hb_file );
			if ( false !== $mtime ) {
				$heartbeat_age = $now - (int) $mtime;
				if ( $heartbeat_age < $stale_timeout ) {
					$status = 'running';
				}
			}
		}

		return [
			'type'            => $name,
			'partition'       => $partition,
			'status'          => $status,
			'started_at'      => Lock::get_started_time( $lock_dir ),
			'heartbeat_age'   => $heartbeat_age,
			'restart_pending' => Lock::is_restart_pending( $lock_dir ),
		];
	}

	private function build_log_segments_status( string $name, int $partition, string $segment_dir ): array {
		$segments   = [];
		$total_size = 0;
		if ( \is_dir( $segment_dir ) ) {
			$files = @\scandir( $segment_dir );
			if ( \is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( \preg_match( '/^(\d+)\.log$/', $file, $m ) ) {
						$path = "{$segment_dir}/{$file}";
						if ( \is_link( $path ) ) {
							continue;
						}
						$size = @\filesize( $path );
						$mtime = @\filemtime( $path );
						$segments[] = [
							'id'    => (int) $m[1],
							'size'  => false !== $size ? (int) $size : 0,
							'mtime' => false !== $mtime ? (int) $mtime : 0,
						];
						$total_size += false !== $size ? (int) $size : 0;
					}
				}
				\usort( $segments, static fn ( $a, $b ) => $a['id'] <=> $b['id'] );
			}
		}
		return [
			'name'       => $name,
			'partition'  => $partition,
			'segments'   => $segments,
			'total_size' => $total_size,
		];
	}

	/**
	 * Live cursor lookup from memcache. Workers publish their positions every
	 * ~10s under `evlog:pos:{host}:{name}:p{N}` per spec § 11.
	 *
	 * @return array{seg:int, off:int}|null
	 */
	private function get_live_position( string $type, int $partition, string $input_log ): ?array {
		$cache = $this->resolve_cache();
		if ( ! $cache->is_available() ) {
			return null;
		}
		$host = \gethostname() ?: 'host';
		$key  = "evlog:pos:{$host}:{$type}:p{$partition}";
		$val  = $cache->get( $key );
		if ( ! \is_array( $val ) ) {
			return null;
		}
		// Expected shape: [ input_log => { seg, off } ].
		if ( isset( $val[ $input_log ] ) && \is_array( $val[ $input_log ] ) ) {
			return [
				'seg' => (int) ( $val[ $input_log ]['seg'] ?? 0 ),
				'off' => (int) ( $val[ $input_log ]['off'] ?? 0 ),
			];
		}
		// Fallback: a flat {seg, off} (single-input reader).
		if ( isset( $val['seg'], $val['off'] ) ) {
			return [
				'seg' => (int) $val['seg'],
				'off' => (int) $val['off'],
			];
		}
		return null;
	}

	private function resolve_cache(): Cache_Interface {
		return self::cache();
	}
}
