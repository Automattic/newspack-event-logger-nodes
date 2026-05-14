<?php
/**
 * WorkersController:
 *   GET  /performance/workers           — worker + standalone + log status.
 *   POST /performance/workers/restart   — request a worker restart via Lock flag.
 *
 * Sources worker descriptors from the runtime's Bootstrap::expand_workers()
 * (one row per topology × partition). Live cursor positions come from
 * memcache (`np:pos:{host}:{path}:p{N}`) when available, falling back to
 * the on-disk offsetlog Partition.
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

		$logs = [];

		// Enumerate active Consumers from offsetlog metadata. Each
		// offsetlog entry now carries `name`, `target`, `worker_type`
		// (Consumer publishes them on checkpoint), so the dashboard can
		// render one row per (worker_type, consumer_name, partition)
		// without hardcoding a per-type inputs/outputs map.
		$offsetlog_rows = $this->enumerate_offsetlog_rows( $base_dir );
		// Track which sources are consumed (have an offsetlog row) — any
		// log directory that isn't represented is a terminal output that
		// should still appear in the response so the dashboard can render
		// it as a producer-only step.
		$consumed_basenames = [];
		foreach ( $offsetlog_rows as $row ) {
			$consumed_basenames[ $row['source_basename'] ] = true;
		}
		// Index rows by (worker_type, partition).
		$rows_by_worker = [];
		foreach ( $offsetlog_rows as $row ) {
			$key = $row['worker_type'] . '|' . $row['partition'];
			if ( ! isset( $rows_by_worker[ $key ] ) ) {
				$rows_by_worker[ $key ] = [];
			}
			$rows_by_worker[ $key ][] = $row;
		}

		foreach ( $descriptors as $w ) {
			$type      = (string) ( $w['type'] ?? '' );
			$partition = (int) ( $w['partition'] ?? 0 );
			$stale_to  = (int) ( $w['stale_timeout'] ?? Lock::STALE_TIMEOUT );
			if ( '' === $type ) {
				continue;
			}
			$lock_dir = "{$locks_base}/{$type}.p{$partition}.lock.d";

			$consumer_rows = $rows_by_worker[ "{$type}|{$partition}" ] ?? [];
			if ( empty( $consumer_rows ) ) {
				// Worker hasn't checkpointed yet (fresh spawn) — emit a
				// single placeholder row so the dashboard still renders
				// the worker_type. No consumer metadata available.
				$placeholder = $this->build_worker_status(
					$type,
					$partition,
					'',
					null,
					$log_base,
					$lock_dir,
					$now,
					$stale_to,
					null
				);
				$placeholder['inputs']         = [];
				$placeholder['outputs']        = [];
				$placeholder['inputs_status']  = [];
				$placeholder['outputs_status'] = [];
				$placeholder['target']         = '';
				$workers[]                     = $placeholder;
				continue;
			}

			foreach ( $consumer_rows as $row ) {
				$input_log = "{$row['source_basename']}.log";
				// Each Consumer can have multiple downstream processors
				// (Tee fan-out: firehose:tee → request-builder + job-router).
				// Emit one dashboard row per processor so the operator sees
				// the actual work units — RequestBuilder + JobRouter — not
				// the Consumer plumbing.
				$targets = ! empty( $row['targets'] )
					? $row['targets']
					: [ [ 'name' => $row['target'] ?? '', 'class' => '' ] ];
				foreach ( $targets as $t ) {
					$handler = (string) ( $t['class'] ?? '' );
					if ( '' === $handler ) {
						$handler = (string) ( $t['name'] ?? '' );
					}
					$worker = $this->build_worker_status(
						$type,
						$partition,
						$input_log,
						$t['name'] ?? null,
						$log_base,
						$lock_dir,
						$now,
						$stale_to,
						$handler
					);
					$worker['target']         = $t['name'] ?? '';
					$worker['target_class']   = $t['class'] ?? '';
					$worker['source']         = $row['name'];
					$worker['inputs']         = [ $input_log ];
					$worker['outputs']        = [];
					$worker['inputs_status']  = [
						$this->build_log_status_entry(
							$input_log,
							$partition,
							(int) $worker['cursor_seg'],
							(int) $worker['cursor_offset'],
							$log_base
						),
					];
					$worker['outputs_status'] = [];
					$workers[]                = $worker;
				}
			}
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

		// Terminal logs: filesystem-discovered `{logs_dir}/*.log/` directories
		// that no Consumer reads (e.g. errors.log, flames.log, plus jobs.log
		// when no job-workers Consumer is active). These are Partitions
		// written by some node in a topology but never tailed — the
		// dashboard renders them as producer-only output cards. Sources
		// that ARE consumed already render under their Consumer row.
		$logs = $this->enumerate_terminal_logs( $log_base, $consumed_basenames );

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

	/**
	 * Walk `{logs_dir}/*.log/` and return one entry per log whose basename
	 * isn't in `$consumed_basenames`. Each entry's `partitions[]` lists
	 * segment data per partition (no cursor — these are producer-only).
	 *
	 * @return array<int,array{name:string,partitions:array}>
	 */
	private function enumerate_terminal_logs( string $log_base, array $consumed_basenames ): array {
		if ( ! \is_dir( $log_base ) ) {
			return [];
		}
		$entries = @\scandir( $log_base );
		if ( false === $entries ) {
			return [];
		}
		$logs = [];
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( ! \preg_match( '/^(.+)\.log$/', $entry, $m ) ) {
				continue;
			}
			$basename = $m[1];
			if ( isset( $consumed_basenames[ $basename ] ) ) {
				continue;
			}
			$log_dir     = "{$log_base}/{$entry}";
			$partitions  = [];
			$part_entries = @\scandir( $log_dir );
			if ( false === $part_entries ) {
				continue;
			}
			foreach ( $part_entries as $pe ) {
				if ( ! \preg_match( '/^p(\d+)$/', $pe, $pm ) ) {
					continue;
				}
				$partition_idx = (int) $pm[1];
				$status        = $this->build_log_status_entry(
					$entry,
					$partition_idx,
					null,
					null,
					$log_base
				);
				$partitions[] = [
					'partition'  => $partition_idx,
					'segments'   => $status['segments'] ?? [],
					'total_size' => $status['total_size'] ?? 0,
				];
			}
			if ( ! empty( $partitions ) ) {
				$logs[] = [ 'name' => $entry, 'partitions' => $partitions ];
			}
		}
		return $logs;
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

			// Cursor: live position from memcache (falls back to disk offsetlog
			// internally via read_offsetlog_position).
			$cursor        = $this->get_live_position( $type, $partition, $input_log );
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
		// Memcache key is `np:pos:{hostname}:{source_base_dir}:p{N}` — the
		// same path Consumer writes to. Hostname-prefixed so shared-memcache
		// deployments don't collide across hosts (render1/render2/hub all
		// have the same on-disk `{base_dir}` path). Goes through the
		// controller's Cache_Interface so tests inject FakeMemcached
		// transparently; the production `Memcached_Cache` and Consumer's
		// direct `\Memcached` both hit the same physical server, so keys
		// stay coherent.
		$config      = self::load_config();
		$base_dir    = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$source_path = "{$base_dir}/logs/{$input_log}";
		$host        = \gethostname() ?: 'unknown';
		$cache_key   = "np:pos:{$host}:{$source_path}:p{$partition}";

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
	 * Scan `{base}/offsets/` and return one entry per active Consumer.
	 *
	 * Each Consumer publishes its name + target + worker_type into its
	 * offsetlog on every checkpoint (see Consumer::checkpoint), so the
	 * latest entry of each `{source}.p{N}/` directory tells the dashboard
	 * everything it needs to render a per-Consumer row without hardcoding
	 * a per-worker-type inputs/outputs map.
	 *
	 * @return array<int,array{name:string,target:string,worker_type:string,source_basename:string,partition:int,seg:int,off:int,ts:float}>
	 */
	private function enumerate_offsetlog_rows( string $base_dir ): array {
		$offsets_dir = "{$base_dir}/offsets";
		if ( ! \is_dir( $offsets_dir ) ) {
			return [];
		}
		$entries = @\scandir( $offsets_dir );
		if ( false === $entries ) {
			return [];
		}
		$rows = [];
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			// Expect `{source}.p{N}` directory naming.
			if ( ! \preg_match( '/^(.+)\.p(\d+)$/', $entry, $m ) ) {
				continue;
			}
			$source_basename = $m[1];
			$partition       = (int) $m[2];
			$row             = $this->read_offsetlog_latest_entry( "{$offsets_dir}/{$entry}", $partition );
			if ( null === $row ) {
				continue;
			}
			// Skip entries that pre-date the metadata addition (no
			// worker_type means the controller can't attribute the row
			// to a worker).
			if ( '' === ( $row['worker_type'] ?? '' ) ) {
				continue;
			}
			$rows[] = [
				'name'            => (string) ( $row['name']   ?? '' ),
				'target'          => (string) ( $row['target'] ?? '' ),
				'targets'         => \is_array( $row['targets'] ?? null ) ? $row['targets'] : [],
				'worker_type'     => (string) $row['worker_type'],
				'source_basename' => $source_basename,
				'partition'       => $partition,
				'seg'             => (int) ( $row['seg'] ?? 0 ),
				'off'             => (int) ( $row['off'] ?? 0 ),
				'ts'              => (float) ( $row['ts']  ?? 0 ),
			];
		}
		return $rows;
	}

	/**
	 * Read the latest committed offsetlog entry and return its VALUE array
	 * (or null if empty/unreadable).
	 *
	 * @return array<string,mixed>|null
	 */
	private function read_offsetlog_latest_entry( string $offsetlog_dir, int $partition ): ?array {
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
			return \is_array( $entry ) ? $entry : null;
		} catch ( \Throwable $e ) {
			return null;
		}
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
