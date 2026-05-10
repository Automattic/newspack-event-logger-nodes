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

	/**
	 * Static map of topology worker type → input/output log files. Source-of-truth
	 * for what each worker actually tails / writes; topology PHP files (which own
	 * the real wiring) aren't safe to load at REST-time without spawning workers,
	 * so this mirrors them. The `inputs[0]` entry is the primary tail used to
	 * resolve segments + bytes-behind; additional inputs are reported in the API
	 * but not factored into the headline stats.
	 *
	 * Application plugins can extend this via the `newspack_event_logger_nodes/log_readers`
	 * filter (legacy shape: `[ type => [ 'inputs' => [...], 'outputs' => [...] ] ]`).
	 *
	 * `aggregator` has no local input — StreamMerger pulls remote firehoses via
	 * SSE — so it gets `inputs => []` and the controller reports zero segments
	 * for that row.
	 */
	private const WORKER_INPUTS = [
		'firehose-workers' => [
			'inputs'  => [ 'firehose.log', 'jobintake.log' ],
			'outputs' => [ 'requests.log', 'errors.log', 'jobs.log' ],
		],
		'request-workers'   => [
			'inputs'  => [ 'requests.log' ],
			'outputs' => [ 'flames.log' ],
		],
		'job-workers'      => [
			'inputs'  => [ 'jobs.log' ],
			'outputs' => [],
		],
		'aggregator'       => [
			'inputs'  => [],
			'outputs' => [ 'firehose.log' ],
		],
	];

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
			$lock_dir = "{$locks_base}/{$type}.p{$partition}.lock.d";

			// Resolve inputs/outputs: prefer the static topology map; fall back
			// to anything a plugin registered via the log_readers filter; then
			// last-ditch default to firehose.log so we don't silently report a
			// blank source (matches legacy single-worker behavior).
			$reader_cfg = $readers[ $type ] ?? [];
			$static_cfg = self::WORKER_INPUTS[ $type ] ?? [];
			$inputs     = $reader_cfg['inputs'] ?? $static_cfg['inputs'] ?? null;
			$outputs    = $reader_cfg['outputs'] ?? $static_cfg['outputs'] ?? null;
			if ( ! \is_array( $inputs ) ) {
				$inputs = [ 'firehose.log' ];
			}
			if ( ! \is_array( $outputs ) ) {
				$outputs = [];
			}
			$input_log  = ! empty( $inputs ) ? (string) $inputs[0] : '';
			$output_log = ! empty( $outputs ) ? (string) $outputs[0] : null;

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
			// Surface the full lists so the dashboard can display secondary inputs
			// (e.g. firehose-workers also tails jobintake.log) and downstream
			// outputs (e.g. requests.log + errors.log + jobs.log).
			$input_names       = \array_values( \array_filter( $inputs, 'is_string' ) );
			$output_names      = \array_values( \array_filter( $outputs, 'is_string' ) );
			$worker['inputs']  = $input_names;
			$worker['outputs'] = $output_names;

			// Per-input segment status. Cursor is reported only for the primary
			// input (inputs[0]) — secondary inputs are tailed from a separate
			// Consumer whose offsetlog isn't surfaced through this row, so we
			// render them without cursor data (segments rendered all-green).
			$inputs_status = [];
			foreach ( $input_names as $idx => $log_name ) {
				$is_primary = ( 0 === $idx );
				if ( $is_primary ) {
					$cursor_seg    = (int) ( $worker['cursor_seg'] ?? 0 );
					$cursor_offset = (int) ( $worker['cursor_offset'] ?? 0 );
				} else {
					$cursor = $this->get_live_position( $type, $partition, $log_name );
					if ( null === $cursor ) {
						$cursor = $saved_positions[ $type ][ $partition ][ $log_name ] ?? null;
					}
					$cursor_seg    = null !== $cursor ? (int) ( $cursor['seg'] ?? 0 ) : null;
					$cursor_offset = null !== $cursor ? (int) ( $cursor['off'] ?? 0 ) : null;
				}
				$inputs_status[] = $this->build_log_status_entry(
					$log_name,
					$partition,
					$cursor_seg,
					$cursor_offset,
					$log_base
				);
			}
			$worker['inputs_status'] = $inputs_status;

			// Per-output segment status. No cursor — outputs aren't tailed by
			// this worker; the LogSection treats them as fully-processed.
			$outputs_status = [];
			foreach ( $output_names as $log_name ) {
				$outputs_status[] = $this->build_log_status_entry(
					$log_name,
					$partition,
					null,
					null,
					$log_base
				);
			}
			$worker['outputs_status'] = $outputs_status;

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

		// Each worker now owns its inputs/outputs via inputs_status/outputs_status,
		// so there's no separate "logs" array — outputs render under their
		// producing worker, never as orphan top-level rows.

		return new \WP_REST_Response(
			[
				'workers'        => $workers,
				'standalone'     => $standalone,
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
		// Workers without a local tail (e.g. aggregator pulls remote feeds via
		// SSE) have no Partition to scan; skip the segment lookup and report
		// zeroed stats so the dashboard renders a clean row.
		if ( '' === $input_log ) {
			$segments      = [];
			$total_size    = 0;
			$cursor_seg    = 0;
			$cursor_offset = 0;
			$behind        = 0;
		} else {
			$partition_obj = new Partition( "{$log_base}/{$input_log}", $partition );
			$segments      = $partition_obj->get_segments();
			$total_size    = (int) \array_sum( \array_column( $segments, 'size' ) );

			// Cursor: prefer live (memcache); fall back to saved offsetlog.
			$cursor = $this->get_live_position( $type, $partition, $input_log );
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

	/**
	 * Scan a log's segment directory and return the per-log status block used
	 * by `inputs_status` / `outputs_status`. Cursor fields are included only
	 * when both `$cursor_seg` and `$cursor_offset` are non-null — the React
	 * `LogSection` treats absent cursor data as "output-only" (all segments
	 * rendered green).
	 *
	 * @param string   $log_name      Log file name (e.g. "firehose.log").
	 * @param int      $partition     Partition number.
	 * @param int|null $cursor_seg    Cursor segment id, or null for output-only.
	 * @param int|null $cursor_offset Cursor offset within segment, or null.
	 * @param string   $log_base      Base log directory (e.g. "/var/lib/.../logs").
	 */
	private function build_log_status_entry(
		string $log_name,
		int $partition,
		?int $cursor_seg,
		?int $cursor_offset,
		string $log_base
	): array {
		$segment_dir = "{$log_base}/{$log_name}/p{$partition}";
		$segments    = [];
		$total_size  = 0;
		if ( \is_dir( $segment_dir ) ) {
			$files = @\scandir( $segment_dir );
			if ( \is_array( $files ) ) {
				foreach ( $files as $file ) {
					if ( \preg_match( '/^(\d+)\.log$/', $file, $m ) ) {
						$path = "{$segment_dir}/{$file}";
						if ( \is_link( $path ) ) {
							continue;
						}
						$size  = @\filesize( $path );
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
		$entry = [
			'name'       => $log_name,
			'partition'  => $partition,
			'segments'   => $segments,
			'total_size' => $total_size,
		];
		if ( null !== $cursor_seg && null !== $cursor_offset ) {
			$entry['cursor_seg']    = $cursor_seg;
			$entry['cursor_offset'] = $cursor_offset;
		}
		return $entry;
	}

	/**
	 * Live cursor lookup. Prefer memcache (workers may publish positions every
	 * ~10s under `evlog:pos:{host}:{type}:p{N}`); fall back to reading the
	 * Consumer's offsetlog directly. The offsetlog is a Partition at
	 * `{base}/offsets/{input_log basename}.p{N}` whose newest line carries
	 * the latest committed `{seg, off, ts}`.
	 *
	 * @return array{seg:int, off:int}|null
	 */
	private function get_live_position( string $type, int $partition, string $input_log ): ?array {
		// Memcache key is `np:pos:{source_base_dir}:p{N}` — the same path
		// Consumer writes to from its source_base_dir. Both sides derive it
		// from {base_directory}/{input_log}. Goes through the controller's
		// Cache_Interface so tests inject FakeMemcached transparently; the
		// production `Memcached_Cache` and Consumer's direct `\Memcached`
		// both hit the same physical server, so keys stay coherent.
		$config      = self::load_config();
		$base_dir    = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$source_path = "{$base_dir}/logs/{$input_log}";
		$cache_key   = "np:pos:{$source_path}:p{$partition}";

		$cache = $this->resolve_cache();
		if ( $cache->is_available() ) {
			$val = $cache->get( $cache_key );
			if ( \is_array( $val ) && isset( $val['seg'], $val['off'] ) ) {
				return [ 'seg' => (int) $val['seg'], 'off' => (int) $val['off'] ];
			}
		}
		return $this->read_offsetlog_position( $input_log, $partition );
	}

	/**
	 * Read the latest committed cursor from the on-disk offsetlog.
	 *
	 * @return array{seg:int, off:int}|null
	 */
	private function read_offsetlog_position( string $input_log, int $partition ): ?array {
		$config        = self::load_config();
		$base_dir      = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$basename      = \preg_replace( '/\.log$/', '', $input_log );
		$offsetlog_dir = "{$base_dir}/offsets/{$basename}.p{$partition}";
		if ( ! \is_dir( $offsetlog_dir ) ) {
			return null;
		}
		try {
			$offsetlog = new Partition( $offsetlog_dir, $partition );
			$segments  = $offsetlog->get_segments( true );
			if ( empty( $segments ) ) {
				return null;
			}
			$newest = \end( $segments );
			$bytes  = $offsetlog->read_at( $newest['id'], 0, $newest['size'] );
			if ( '' === $bytes ) {
				return null;
			}
			$lines = \array_filter( \explode( "\n", $bytes ), static fn ( $l ) => '' !== $l );
			if ( empty( $lines ) ) {
				return null;
			}
			$msg   = \Newspack_Nodes\Message::unpacked( (string) \end( $lines ) );
			$entry = $msg[ \Newspack_Nodes\Message::VALUE ] ?? null;
			if ( ! \is_array( $entry ) || ! isset( $entry['seg'], $entry['off'] ) ) {
				return null;
			}
			return [ 'seg' => (int) $entry['seg'], 'off' => (int) $entry['off'] ];
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private function resolve_cache(): Cache_Interface {
		return self::cache();
	}
}
