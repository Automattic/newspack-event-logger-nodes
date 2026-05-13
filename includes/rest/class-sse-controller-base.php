<?php
/**
 * SSEControllerBase: shared base for Server-Sent Events REST controllers.
 *
 * Lift-adapt of `Newspack_Event_Logger\REST\SSEControllerBase`. The wire shape
 * (event names, headers, sleep cadence) is preserved 1:1 so the same React
 * hooks (`useFirehoseConnection`, etc.) run unchanged.
 *
 * This class provides:
 *   - Slot acquisition (atomic memcache add() loop, fail-CLOSED → HTTP 429).
 *   - Headers that disable proxy/zlib/mod_deflate buffering for streaming.
 *   - `send_sse_event()` with an event-name allowlist + regex sanitizer fallback.
 *   - `flush_if_needed()`: a 4093-byte SSE comment that pushes recent events
 *     through TLS / nginx / Apache buffers when the loop is about to sleep.
 *   - A reusable polling-loop template (`stream_log` / `stream_log_run`) for
 *     subclasses that just want "tail this log file with batching + heartbeats".
 *
 * Substrate differences from the legacy class:
 *   - Static `Memcached::*` slot helpers are replaced by `Cache_Interface`
 *     methods on a `Memcached_Cache` instance (so tests can inject FakeMemcached).
 *   - Legacy `Newspack_Event_Logger\Firehose` / `FirehoseReader` become
 *     `Newspack_Nodes\Partition` + this plugin's `Partition_Reader`.
 *   - Permission check uses `current_user_can('manage_options')` (matching
 *     `PerformanceControllerBase`); legacy used `Admin::current_user_allowed()`.
 *   - Event prefix on `do_action` calls is `newspack_event_logger_nodes/sse_*`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Cache_Interface;
use Newspack_Event_Logger_Nodes\Memcached_Cache;
use Newspack_Event_Logger_Nodes\Partition_Reader;
use Newspack_Nodes\Partition;

abstract class SSEControllerBase {

	/**
	 * Subclasses register their REST routes here.
	 */
	abstract public function register_routes(): void;

	/**
	 * Maximum concurrent SSE connections per `(user, ip_hash)` (or per partition
	 * for aggregator pools). Each browser tab opens one stream per partition.
	 */
	public const MAX_SSE_SLOTS = 10;

	/**
	 * Flush comment size. SSE comments start with `:` and end with `\n\n`, so a
	 * 4096-byte total flush comment is 3 chars of framing + 4093 dots. Sized to
	 * push past nginx/Apache/TLS write buffers without overshooting PIPE_BUF.
	 */
	public const FLUSH_SIZE = 4096;

	/** Loop-iteration cadence for slot revocation checks (seconds). */
	public const SLOT_CHECK_INTERVAL = 5;

	/** How often the loop emits an SSE `heartbeat` event when no data is flowing. */
	public const HEARTBEAT_INTERVAL = 5;

	/**
	 * Maximum stream lifetime (seconds). Browser EventSource will reconnect on
	 * close; capping at 1h prevents orphan streams holding slots forever.
	 */
	public const MAX_RUNTIME = 3600;

	/** Slot TTL for browser tabs. Heartbeat-from-JS every 5s ⇒ 2x headroom. */
	public const SLOT_TTL_BROWSER = 10;

	/** Slot TTL for aggregator (StreamMerger) connections. Heartbeat every 15s. */
	public const SLOT_TTL_AGGREGATOR = 30;

	/**
	 * Allowlist of REST namespace prefixes any subclass is permitted to mount under.
	 * Acts as a security boundary — code review can grep this constant to confirm
	 * no third-party namespace registers an SSE endpoint via this base.
	 */
	public const ALLOWED_ENDPOINT_PREFIXES = [
		'newspack-nodes/v1',
		'newspack-nodes-aggregator/v1',
	];

	/**
	 * Internal SSE event names used by the various controller subclasses. Hot-path:
	 * an O(1) hash lookup short-circuits the regex sanitizer for known events.
	 *
	 * @var array<string,int>
	 */
	private const SAFE_EVENTS = [
		'entry'          => 1,
		'entries'        => 1,
		'lines'          => 1,
		'positions'      => 1,
		'heartbeat'      => 1,
		'config'         => 1,
		'connected'      => 1,
		'timeout'        => 1,
		'complete_batch' => 1,
		'inflight'       => 1,
		'errors'         => 1,
		'hello'          => 1,
		'msg'            => 1,
	];

	protected int $user_id        = 0;
	protected string $ip_hash     = '';
	protected int|false $slot     = false;
	protected int $slot_partition = -1;
	protected bool $needs_flush   = false;

	/**
	 * Per-request cache reference. Tests inject via PerformanceControllerBase::set_cache().
	 *
	 * @var Cache_Interface|null
	 */
	private static ?Cache_Interface $sse_cache = null;

	/**
	 * Inject a cache. Tests use this to wire a FakeMemcached. In production the
	 * SSE base shares one Memcached_Cache instance with PerformanceControllerBase
	 * so a heartbeat POST and a stream request hit the same connection.
	 */
	public static function set_cache( ?Cache_Interface $cache ): void {
		self::$sse_cache = $cache;
	}

	/**
	 * Resolve the cache. Tests-injected first; falls back to building a fresh
	 * Memcached_Cache from filtered config.
	 */
	public static function cache(): Cache_Interface {
		if ( null !== self::$sse_cache ) {
			return self::$sse_cache;
		}
		$config  = PerformanceControllerBase::load_config();
		$servers = $config['memcache_servers'] ?? Memcached_Cache::DEFAULT_SERVERS;
		if ( ! \is_array( $servers ) ) {
			$servers = Memcached_Cache::DEFAULT_SERVERS;
		}
		self::$sse_cache = new Memcached_Cache( $servers );
		return self::$sse_cache;
	}

	/**
	 * Permission check: capability gate. Subclasses register this as
	 * `permission_callback` on every SSE route.
	 *
	 * @return bool|\WP_Error True if allowed; WP_Error otherwise.
	 */
	public function stream_permissions_check(): bool|\WP_Error {
		if ( ! \function_exists( 'current_user_can' ) || ! \current_user_can( 'manage_options' ) ) {
			$status = \function_exists( 'rest_authorization_required_code' ) ? \rest_authorization_required_code() : 401;
			return new \WP_Error(
				'rest_forbidden',
				'You do not have permission to access this resource.',
				[ 'status' => $status ]
			);
		}
		return true;
	}

	/**
	 * 8-character md5 of REMOTE_ADDR. Used only as a cache-key shard, never
	 * displayed or stored on disk — privacy-safe by construction.
	 */
	protected function get_ip_hash(): string {
		// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders, WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___SERVER__REMOTE_ADDR__, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
		return \substr( \md5( (string) $ip ), 0, 8 );
	}

	/**
	 * Try to acquire an SSE slot from the cache.
	 *
	 * @param int $ttl       Slot TTL (seconds).
	 * @param int $partition `-1` for the shared browser pool, `>=0` for a
	 *                       per-partition aggregator pool.
	 * @return int|false     Slot index 0..MAX_SSE_SLOTS-1; false on rate-limit.
	 */
	protected function acquire_sse_slot( int $ttl = self::SLOT_TTL_BROWSER, int $partition = -1 ): int|false {
		$cache                 = self::cache();
		$this->user_id        = \function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
		$this->ip_hash        = $this->get_ip_hash();
		$this->slot_partition = $partition;
		$this->slot           = $cache->acquire_sse_slot( $this->user_id, $this->ip_hash, static::MAX_SSE_SLOTS, $ttl, $partition );
		return $this->slot;
	}

	protected function release_sse_slot(): void {
		if ( false !== $this->slot ) {
			self::cache()->release_sse_slot( $this->user_id, $this->ip_hash, $this->slot, $this->slot_partition );
			$this->slot           = false;
			$this->slot_partition = -1;
		}
	}

	protected function check_sse_slot(): bool {
		if ( false === $this->slot ) {
			return false;
		}
		return self::cache()->check_sse_slot( $this->user_id, $this->ip_hash, $this->slot, $this->slot_partition );
	}

	/**
	 * Emit SSE response headers. Disables every layer of buffering between PHP
	 * and the browser:
	 *  - `output_buffering=off` + `ob_end_clean` clears PHP's stack.
	 *  - `zlib.output_compression=false` disables PHP's gzip wrapper.
	 *  - `Content-Encoding: none` defends against any other gzip wrapper.
	 *  - `apache_setenv('no-gzip','1')` covers mod_deflate.
	 *  - `X-Accel-Buffering: no` covers nginx proxy_buffering.
	 */
	protected function init_sse_headers(): void {
		// phpcs:disable WordPress.PHP.IniSet.Risky
		@\ini_set( 'output_buffering', 'off' );
		@\ini_set( 'zlib.output_compression', false );
		@\ini_set( 'implicit_flush', true );
		// phpcs:enable

		while ( \ob_get_level() > 0 ) {
			\ob_end_clean();
		}

		if ( \function_exists( 'apache_setenv' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_apache_setenv
			@\apache_setenv( 'no-gzip', '1' );
		}

		\header( 'Content-Type: text/event-stream' );
		\header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		\header( 'Connection: keep-alive' );
		\header( 'X-Accel-Buffering: no' );
		\header( 'Content-Encoding: none' );
	}

	/**
	 * Send a single SSE event. Event-name sanitizing has a fast path for the
	 * SAFE_EVENTS allowlist; arbitrary event names fall through to a
	 * `[^a-zA-Z0-9_-]` regex sanitizer which strips newlines / control chars
	 * (defending against SSE injection).
	 *
	 * @param string $event Event name.
	 * @param mixed  $data  JSON-serializable payload.
	 */
	protected function send_sse_event( string $event, mixed $data ): void {
		if ( ! isset( self::SAFE_EVENTS[ $event ] ) ) {
			$event = (string) \preg_replace( '/[^a-zA-Z0-9_-]/', '', $event );
		}
		$json    = \wp_json_encode( $data );
		$payload = "event: {$event}\ndata: {$json}\n\n";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $payload;
		@\flush();
		$this->needs_flush = true;
	}

	/**
	 * If anything has been sent since the last flush, push a 4093-dot SSE
	 * comment to flush proxy / TLS / web-server output buffers. Idempotent:
	 * the second call in a row is a no-op.
	 */
	protected function flush_if_needed(): void {
		if ( ! $this->needs_flush ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo ':' . \str_repeat( '.', static::FLUSH_SIZE - 3 ) . "\n\n";
		@\flush();
		$this->needs_flush = false;
	}

	/**
	 * Acquire a slot, set headers, emit the `connected` event, return a context
	 * dict the loop body uses for clock comparisons. WP_Error on rate-limit.
	 *
	 * Aggregator connections (`$is_aggregator=true`) get the longer slot TTL
	 * AND a per-partition slot pool — one StreamMerger per partition shouldn't
	 * compete with browser tabs for the global 10-slot pool.
	 *
	 * @param array<string,mixed> $connected_data  Extra fields for the `connected` event.
	 * @param array<string,string>$custom_headers  Extra HTTP headers (sanitized for CRLF injection).
	 * @param bool                $is_aggregator   Hub-side caller flag.
	 * @return array{slot:int,start_time:int,last_slot_check:int,config:array<string,mixed>,log_base:string,num_partitions:int,segment_size:int,num_segments:int}|\WP_Error
	 */
	protected function start_sse_stream( array $connected_data = [], array $custom_headers = [], bool $is_aggregator = false ): array|\WP_Error {
		$ttl       = $is_aggregator ? static::SLOT_TTL_AGGREGATOR : static::SLOT_TTL_BROWSER;
		$partition = ( $is_aggregator && isset( $connected_data['partition'] ) ) ? (int) $connected_data['partition'] : -1;
		$slot      = $this->acquire_sse_slot( $ttl, $partition );
		if ( false === $slot ) {
			if ( \function_exists( 'do_action' ) ) {
				/** Fires when an SSE connection is rate-limited (429). */
				\do_action( 'newspack_event_logger_nodes/sse_rate_limited', $this->user_id, static::class );
			}
			return new \WP_Error(
				'too_many_connections',
				'Maximum concurrent SSE streams reached. Close other tabs or wait.',
				[ 'status' => 429 ]
			);
		}

		$this->init_sse_headers();

		// CRLF / NUL stripping prevents HTTP header injection via custom headers.
		foreach ( $custom_headers as $name => $value ) {
			$name  = \str_replace( [ "\r", "\n", "\0" ], '', (string) $name );
			$value = \str_replace( [ "\r", "\n", "\0" ], '', (string) $value );
			\header( "{$name}: {$value}" );
		}

		@\set_time_limit( 0 );

		$config = PerformanceControllerBase::load_config();

		$connected_data['slot'] = $slot;
		$this->send_sse_event( 'connected', $connected_data );

		if ( \function_exists( 'do_action' ) ) {
			/** Fires when an SSE stream is opened. */
			\do_action( 'newspack_event_logger_nodes/sse_connected', $slot, $this->user_id, static::class );
		}

		return [
			'slot'            => $slot,
			'start_time'      => \time(),
			'last_slot_check' => \time(),
			'config'          => $config,
			'log_base'        => (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' ) . '/logs',
			'num_partitions'  => (int) ( $config['num_partitions'] ?? 1 ),
			'segment_size'    => (int) ( $config['segment_size'] ?? ( 16 * 1024 * 1024 ) ),
			'num_segments'    => (int) ( $config['num_segments'] ?? 8 ),
		];
	}

	/**
	 * Per-iteration loop guard. Returns false on:
	 *  - client disconnect (connection_aborted),
	 *  - MAX_RUNTIME exceeded (emits a `timeout` event so the client knows to reconnect),
	 *  - slot revoked (heartbeat stopped, TTL expired).
	 *
	 * @param array<string,mixed> $context From start_sse_stream(); mutated in-place.
	 */
	protected function should_continue_stream( array &$context ): bool {
		if ( \connection_aborted() ) {
			return false;
		}
		$now = \time();
		if ( $now - (int) $context['start_time'] > static::MAX_RUNTIME ) {
			$this->send_sse_event( 'timeout', [ 'message' => 'Max runtime reached, reconnect to continue' ] );
			return false;
		}
		if ( $now - (int) $context['last_slot_check'] >= static::SLOT_CHECK_INTERVAL ) {
			if ( ! $this->check_sse_slot() ) {
				return false;
			}
			$context['last_slot_check'] = $now;
		}
		return true;
	}

	/**
	 * Release slot, fire disconnect action.
	 */
	protected function end_sse_stream(): void {
		if ( \function_exists( 'do_action' ) ) {
			/** Fires when an SSE stream is closed. */
			\do_action( 'newspack_event_logger_nodes/sse_disconnected', $this->user_id, static::class );
		}
		$this->release_sse_slot();
	}

	/**
	 * Validate / clip a positions parameter. Used by subclasses that accept a
	 * resume position via the `positions` query arg.
	 *
	 * @param string|null $raw            Raw param value.
	 * @param int         $num_partitions Drop entries past partition count.
	 * @return array<int,array{s:int,o:int}>|null
	 */
	protected function parse_positions( ?string $raw, int $num_partitions ): ?array {
		if ( \is_string( $raw ) && \strlen( $raw ) > 4096 ) {
			$raw = null;
		}
		$saved_pos = ! empty( $raw ) ? \json_decode( (string) $raw, true ) : null;
		if ( \is_array( $saved_pos ) && \count( $saved_pos ) > $num_partitions ) {
			$saved_pos = \array_slice( $saved_pos, 0, $num_partitions );
		}
		return \is_array( $saved_pos ) ? $saved_pos : null;
	}

	/**
	 * Build per-partition `Partition_Reader`s seeded either at saved positions
	 * (resume) or at `end - tail_bytes` (first-connect tail). When tail-seeking,
	 * the first partial line is consumed off the file handle so the client never
	 * sees a half-line.
	 *
	 * @return array{readers:array<int,Partition_Reader>,file_handles:array<int,resource|null>}
	 */
	protected function setup_readers( string $log_base, string $log_file, int $num_partitions, ?array $saved_pos, int $tail_bytes ): array {
		$readers      = [];
		$file_handles = [];
		$needs_skip   = [];

		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$partition = new Partition( "{$log_base}/{$log_file}", $p );
			$reader    = new Partition_Reader( $partition );
			$reader->next_offset( 'end' );
			$end_pos = $reader->get_position();

			$resumed = false;
			if ( \is_array( $saved_pos ) && isset( $saved_pos[ $p ] ) ) {
				$sp = $saved_pos[ $p ];
				if (
					isset( $sp['s'], $sp['o'] )
					&& (int) $sp['s'] === $end_pos['segment_id']
					&& $end_pos['offset'] - (int) $sp['o'] <= $tail_bytes
				) {
					$reader->next_offset(
						[
							'segment_id' => (int) $sp['s'],
							'offset'     => (int) $sp['o'],
						]
					);
					$resumed = true;
				}
			}

			if ( ! $resumed ) {
				$reader->next_offset(
					[
						'segment_id' => $end_pos['segment_id'],
						'offset'     => \max( 0, $end_pos['offset'] - $tail_bytes ),
					]
				);
				$needs_skip[ $p ] = true;
			}

			$readers[ $p ]      = $reader;
			$file_handles[ $p ] = null;
		}

		// First-line resync after a mid-file seek.
		foreach ( $needs_skip as $p => $_ ) {
			$pos = $readers[ $p ]->get_position();
			if ( $pos['offset'] > 0 ) {
				$fh = $readers[ $p ]->open();
				if ( \is_resource( $fh ) ) {
					\stream_set_timeout( $fh, 1 );
					\fgets( $fh );
					$readers[ $p ]->update_offset();
					$file_handles[ $p ] = $fh;
				}
			}
		}

		return [
			'readers'      => $readers,
			'file_handles' => $file_handles,
		];
	}

	/**
	 * Wrapper that runs the polling loop and `exit`s. Subclasses with the
	 * "tail this log file" shape call this directly.
	 *
	 * @param \WP_REST_Request $request    REST request.
	 * @param array<string,mixed> $config Stream config (see stream_log_run for keys).
	 * @param callable         $transform fn(string $line, int $partition):?array — return null to skip.
	 * @return \WP_Error|void  WP_Error if rate-limited; exits otherwise.
	 */
	protected function stream_log( \WP_REST_Request $request, array $config, callable $transform ) {
		$result = $this->stream_log_run( $request, $config, $transform );
		if ( \is_wp_error( $result ) ) {
			return $result;
		}
		exit;
	}

	/**
	 * Polling-loop body. Same shape as the legacy `stream_log_run`:
	 *  1. acquire slot, send `connected`,
	 *  2. open per-partition readers (resume or tail-seek),
	 *  3. send `config`,
	 *  4. loop: read line → transform → buffer; flush batch on interval/threshold;
	 *     emit `positions` after every batch; heartbeat every 5s when caught up.
	 *  5. release readers + slot in `finally`.
	 *
	 * Stream config keys:
	 *  - log_file        (string,required) Log directory under {log_base}.
	 *  - event_name      (string,required) SSE event name for batch payloads.
	 *  - tail_bytes      (int,1MB)         First-connect tail size.
	 *  - batch_threshold (int,50)          Force-emit when batch size hits this.
	 *  - config_extras   (array,[])        Additional fields on the `config` event.
	 */
	protected function stream_log_run( \WP_REST_Request $request, array $config, callable $transform ): mixed {
		$digest_interval = (int) $request->get_param( 'interval' );
		$log_file        = (string) $config['log_file'];
		$event_name      = (string) $config['event_name'];
		$tail_bytes      = (int) ( $config['tail_bytes'] ?? 1048576 );
		$batch_threshold = (int) ( $config['batch_threshold'] ?? 50 );
		$config_extras   = (array) ( $config['config_extras'] ?? [] );

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
		$saved_pos      = $this->parse_positions( $request->get_param( 'positions' ), $num_partitions );

		$setup        = $this->setup_readers( $log_base, $log_file, $num_partitions, $saved_pos, $tail_bytes );
		$readers      = $setup['readers'];
		$file_handles = $setup['file_handles'];

		$this->send_sse_event(
			'config',
			\array_merge(
				[
					'num_partitions' => $num_partitions,
					'interval'       => $digest_interval,
				],
				$config_extras
			)
		);

		$last_batch         = \microtime( true );
		$last_heartbeat     = \time();
		$batch_interval_sec = $digest_interval / 1000.0;
		$batch              = [];

		try {
			while ( $this->should_continue_stream( $context ) ) {
				$did_work      = false;
				$all_caught_up = true;

				foreach ( $readers as $p => $reader ) {
					$fh = $file_handles[ $p ];
					if ( \is_resource( $fh ) ) {
						$line = \fgets( $fh );
						if ( false !== $line ) {
							$reader->update_offset();
							$entry = $transform( \trim( $line ), $p );
							if ( null !== $entry ) {
								$batch[]  = $entry;
								$did_work = true;
							}
						} else {
							$meta = \stream_get_meta_data( $fh );
							if ( ! empty( $meta['timed_out'] ) ) {
								continue;
							}
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

				$now = \microtime( true );
				if ( ! empty( $batch ) && ( $now - $last_batch >= $batch_interval_sec || \count( $batch ) >= $batch_threshold ) ) {
					$this->send_sse_event( $event_name, $batch );
					$positions = [];
					foreach ( $readers as $rp => $r ) {
						$rpos             = $r->get_position();
						$positions[ $rp ] = [ 's' => $rpos['segment_id'], 'o' => $rpos['offset'] ];
					}
					$this->send_sse_event( 'positions', $positions );
					$batch      = [];
					$last_batch = $now;
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
