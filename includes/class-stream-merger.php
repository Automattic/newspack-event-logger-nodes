<?php
/**
 * Stream Merger
 *
 * Pulls remote firehoses via SSE and fans them into the local Topic.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\CommandInterpreter;
use Newspack_Nodes\Core;
use Newspack_Nodes\EventFramework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Partition;
use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_close
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_error
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_init
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle
// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_multi_close
// Note: cURL is required for SSE multiplexing — wp_remote_get() doesn't support it.

class StreamMerger extends Node {

	// ----- Reconnect / liveness tuning (mirrors upstream class-sse-client.php) -----

	/** Maximum reconnect backoff in seconds. */
	public const MAX_BACKOFF = 30;

	/** Initial reconnect backoff in seconds. */
	public const INITIAL_BACKOFF = 1;

	/** TCP/SSL connect timeout (per attempt). Short so failures rotate quickly. */
	public const CONNECT_TIMEOUT = 5;

	/**
	 * No-event-received-since-this stalling. Must exceed the server's 15s
	 * aggregator-mode SSE heartbeat interval.
	 */
	public const HEARTBEAT_TIMEOUT = 45;

	/** Cadence for `/firehose/heartbeat` POSTs to extend the hub's TTL on the spoke. */
	public const HEARTBEAT_INTERVAL = 15;

	// ----- Memory / size guards (mirror upstream) -----

	/** Read-side raw buffer cap. */
	public const MAX_BUFFER_SIZE = 10485760; // 10MB

	/** Per-event accumulated `data:` cap. */
	public const MAX_EVENT_SIZE = 10485760; // 10MB

	/** Pending event-queue depth. */
	public const MAX_QUEUE_SIZE = 10000;

	/** Atomic-write boundary safety: reject lines larger than this before forwarding. */
	public const MAX_LINE_BYTES = 3900;

	/** Offsetlog commit cadence. */
	public const COMMIT_INTERVAL_S = 5;

	/** Memcache TTL for aggregator status keys (seconds). */
	public const STATUS_TTL = 300;

	// ----- Per-remote state (one row in $this->remotes per server_id). -----
	//
	// Layout:
	//   url               (string)        Base URL of the remote (no trailing slash).
	//   auth_username     (string)        Application-Password user (Basic auth).
	//   auth_password     (string)        Application-Password secret.
	//   auth_token        (string)        Optional Bearer token (compat fallback).
	//   handle            (?\CurlHandle)  Active easy handle, or null if disconnected.
	//   buffer            (string)        Raw bytes pending newline split.
	//   current_event     (array)         { event:string, data:string } in-progress.
	//   event_queue       (array<array>)  [{type, data}, ...] pending dispatch.
	//   slot              (?int)          Slot returned by the `connected` event.
	//   position          (array)         { segment_id:int, offset:int } latest seen.
	//   last_event_time   (float)         microtime of last event receipt.
	//   current_backoff   (int)           Seconds before next reconnect attempt.
	//   last_attempt      (float)         microtime of last connect attempt.
	//   connected         (bool)          True between connect() and on_curl_message().
	//   last_error        (?string)       Human-readable error / disconnect reason.
	//   last_http_code    (?int)          Last observed HTTP code on this remote.
	//   last_heartbeat    (int)           Unix ts of last `/firehose/heartbeat` POST.

	/** @var array<string,array<string,mixed>> Per-remote state, keyed by server_id. */
	private array $remotes = [];

	/** @var \CurlMultiHandle|null Shared multi handle owned by this node, registered with EventFramework. */
	private ?\CurlMultiHandle $multi = null;

	/** @var array<int,string> handle_id (int) -> server_id, for on_curl_message dispatch. */
	private array $handle_to_server = [];

	/** @var Partition|null Per-partition offsetlog (one Partition per StreamMerger instance). */
	private ?Partition $offsetlog = null;

	/** @var int Partition number for the offsetlog directory + heartbeat URL params. */
	private int $partition;

	/** @var float Last commit_all timestamp. */
	private float $last_commit_time = 0.0;

	/** @var Cache_Interface|null Status writer (memcache); null when injection hasn't happened yet. */
	private ?Cache_Interface $cache = null;

	/** @var bool Whether to require HTTPS (default: true, blocks plain HTTP). */
	private bool $require_https = true;

	/** @var bool Whether to verify SSL certs (default: true). */
	private bool $verify_ssl = true;

	/** @var string|null Logs base directory (controls offsetlog placement). null = lazy-load from config. */
	private ?string $logs_dir = null;

	/**
	 * Constructor.
	 *
	 * @param int $partition Partition this merger writes for (offsetlog + heartbeat
	 *                       params). Defaults to 0 — aggregator topology is single-
	 *                       partition (the StreamMerger is a fan-in; the destination
	 *                       Topic distributes by KEY).
	 */
	/** @var HealthCheckTick|null Owned sibling — drives aggregator's periodic health-check sweep. */
	private ?HealthCheckTick $health_check = null;

	public function __construct( int $partition = 0 ) {
		$this->partition = \max( 0, $partition );

		// Sibling CommandInterpreter — TSL aggregator topology
		// configures StreamMerger via verbs (set_verify_ssl,
		// set_require_https, start_periodic_tick,
		// load_remotes_from_registry).
		$ci = new CommandInterpreter();
		$ci->patron( $this );
		$ci->commands( self::config_verbs() );
		$this->attach_interpreter( $ci );

		// Owned HealthCheckTick sibling — also TIMER-driven, only
		// meaningful when the aggregator topology is running, and
		// never independently configurable. Patron-linked so
		// dump_metadata hides it from the canvas, and so a single
		// `start_periodic_tick` verb on the StreamMerger kicks off
		// both ticks. Named on first patron name() (see name()
		// override below) and cascade-cleaned in remove_node().
		$this->health_check = new HealthCheckTick();
		$this->health_check->patron( $this );
	}

	/**
	 * Override name() so the owned HealthCheckTick sibling tracks
	 * `{patron_name}:health-check` whenever the patron is named or
	 * renamed. Mirrors FlameBuilder::name()'s AutoTuner cascade.
	 */
	public function name( ?string $name = null ): string {
		$result = parent::name( $name );
		if ( null !== $name && null !== $this->health_check ) {
			$this->health_check->name( $name . ':health-check' );
		}
		return $result;
	}

	/**
	 * Cascade-unregister the owned HealthCheckTick sibling alongside
	 * the patron. Without this the satellite orphans in
	 * Core::$nodes_by_name and a re-spawned StreamMerger with the
	 * same name collides on its sibling.
	 */
	public function remove_node(): void {
		if ( null !== $this->health_check && '' !== $this->health_check->name() ) {
			\Newspack_Nodes\Core::unregister_node( $this->health_check->name() );
		}
		$this->health_check = null;
		parent::remove_node();
	}

	/**
	 * One-time hub-side init: register the `k:"job"` -> `k:"remote_job"` rewrite
	 * on `newspack_nodes/aggregator_ingest_line`.
	 *
	 * Per-spec, StreamMerger does NOT do the rewrite itself — this is the
	 * canonical filter that lives next to it so the application's hub bootstrap
	 * has a single one-liner to call:
	 *
	 *   StreamMerger::register_remote_job_rewrite_filter();
	 *
	 * Idempotent: a static guard means calling twice is a no-op. Spokes (which
	 * never instantiate StreamMerger) never call this; their k:"job" entries
	 * stay raw and dispatch locally — exactly the spec invariant.
	 */
	public static function register_remote_job_rewrite_filter(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		// Note: priority + accepted_args defaults work for both real WP
		// (priority=10, accepted_args=1) and the test bootstrap shim (which
		// passes all args regardless). The callback only needs $line — the
		// other two parameters are accepted with defaults so the same body
		// works in both cases.
		\add_filter(
			'newspack_nodes/aggregator_ingest_line',
			static function ( $line, string $server_id = '', int $partition = 0 ) {
				if ( ! \is_string( $line ) || '' === $line ) {
					return $line;
				}
				$decoded = \json_decode( $line, true, 16 );
				if ( ! \is_array( $decoded ) ) {
					return $line;
				}
				if ( ! isset( $decoded['k'] ) || 'job' !== $decoded['k'] ) {
					return $line;
				}
				$decoded['k'] = 'remote_job';
				return \wp_json_encode( $decoded );
			}
		);
	}

	// =========================================================================
	// Configuration / DI
	// =========================================================================

	/**
	 * Inject the cache driver (used by tests; production lazy-loads via cache()).
	 */
	public function set_cache( ?Cache_Interface $cache ): void {
		$this->cache = $cache;
	}

	/**
	 * Inject the logs base directory (used by tests; production lazy-loads).
	 *
	 * Setting this re-creates the offsetlog Partition — calling after offsets
	 * have been committed is a test-only path.
	 */
	public function set_logs_dir( string $logs_dir ): void {
		$this->logs_dir  = \rtrim( $logs_dir, '/' );
		$this->offsetlog = null;
	}

	/**
	 * Override the HTTP/HTTPS policy. Used by tests and by the
	 * `aggregator_require_https` config bypass. Always issues a stern warning
	 * when require_https=false so it shows up in the operational logs.
	 */
	public function set_require_https( bool $require ): void {
		if ( ! $require && $this->require_https ) {
			Core::print_less_often( 'StreamMerger: aggregator_require_https=false — SSE traffic permitted on plain HTTP. Credentials WILL travel in cleartext on insecure links.' );
		}
		$this->require_https = $require;
	}

	public function set_verify_ssl( bool $verify ): void {
		$this->verify_ssl = $verify;
	}

	// =========================================================================
	// Node interface
	// =========================================================================

	public function fill( array &$message ): void {
		++$this->counter;
		// Router-hitchhike TIMER notification (TM_INFO, KEY='TIMER') drives
		// tick() — the spoke's aggregator-slot TTL (30s) is short enough that
		// without a periodic heartbeat POST from this side the spoke closes
		// the SSE connection cleanly after one TTL window. See start_periodic_tick().
		if (
			( $message[ Message::TYPE ] & Message::TM_INFO )
			&& 'TIMER' === $message[ Message::KEY ]
		) {
			$this->tick();
			return;
		}
		$this->sink?->fill( $message );
	}

	/**
	 * Register with `_router`'s TIMER event so tick() fires on every Router
	 * heartbeat (~5s). Called by the aggregator topology after `add_remote()`.
	 *
	 * The Router-hitchhike pattern is borrowed from the Timer class: register
	 * by Node name with no callback so the dispatcher routes a TM_INFO message
	 * back through fill() — closure dispatch would self-unregister after the
	 * first tick because tick() returns void → null → falsy.
	 */
	public function start_periodic_tick(): void {
		$router = Core::node( '_router' );
		if ( null === $router ) {
			Core::print_less_often( 'StreamMerger::start_periodic_tick: no _router; periodic tick disabled' );
			return;
		}
		$router->register( 'TIMER', $this->name );
		// Kick off the owned HealthCheckTick sibling's TIMER
		// hitchhike too. Both are TIMER-driven, hub-only, and
		// share the same start signal — the operator never wants
		// one without the other.
		if ( null !== $this->health_check ) {
			$this->health_check->start_periodic_tick();
		}
	}

	// =========================================================================
	// Remote registration (driven by aggregator topology + ServerRegistry)
	// =========================================================================

	/**
	 * Add a remote SSE source.
	 *
	 * Two-arg shape (back-compat with the prototype's tests):
	 *   add_remote( $server_id, $url, $token = '' )
	 *
	 * Single-arg shape (production, registry-driven):
	 *   add_remote( $server_id ) -> reads { url, auth_username, auth_password, enabled }
	 *   from ServerRegistry. Skips if entry missing or disabled.
	 *
	 * @param string $server_id    Identifier — also the offsetlog key + memcache key fragment.
	 * @param string $url          Base URL (no trailing slash). Empty -> registry lookup.
	 * @param string $auth_token   Bearer fallback for compat. Ignored when Basic creds are set.
	 */
	public function add_remote( string $server_id, string $url = '', string $auth_token = '' ): void {
		$auth_username = '';
		$auth_password = '';

		if ( '' === $url ) {
			// Registry-driven path. ServerRegistry::get() returns null for missing
			// entries; SettingsSync (unrelated) doesn't gate this — caller does.
			$registry = new ServerRegistry();
			$entry    = $registry->get( $server_id );
			if ( null === $entry ) {
				Core::print_less_often( "StreamMerger::add_remote: no registry entry for {$server_id}" );
				return;
			}
			if ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) {
				return;
			}
			$url           = (string) ( $entry['url'] ?? '' );
			$auth_username = (string) ( $entry['auth_username'] ?? '' );
			$auth_password = (string) ( $entry['auth_password'] ?? '' );
			$auth_token    = (string) ( $entry['token'] ?? $auth_token );
			if ( '' === $url ) {
				Core::print_less_often( "StreamMerger::add_remote: missing URL for {$server_id}" );
				return;
			}
		}

		// HTTPS-only enforcement at registration time. Caller's `$url` is the
		// only thing we control; downgrade rejection is the safest possible
		// place to enforce the protocol invariant.
		if ( $this->require_https && \stripos( $url, 'https://' ) !== 0 ) {
			Core::print_less_often( "StreamMerger::add_remote: refusing non-HTTPS URL for {$server_id}: {$url}" );
			return;
		}

		$this->remotes[ $server_id ] = [
			'url'             => \rtrim( $url, '/' ),
			'auth_username'   => $auth_username,
			'auth_password'   => $auth_password,
			'auth_token'      => $auth_token,
			'handle'          => null,
			'buffer'          => '',
			'current_event'   => [ 'event' => '', 'data' => '' ],
			'event_queue'     => [],
			'slot'            => null,
			'position'        => [ 'segment_id' => 0, 'offset' => 0 ],
			'last_event_time' => 0.0,
			'current_backoff' => self::INITIAL_BACKOFF,
			'last_attempt'    => 0.0,
			'connected'       => false,
			'last_error'      => null,
			'last_http_code'  => null,
			'last_heartbeat'  => 0,
		];

		$this->init_curl_multi();
		$this->restore_offset( $server_id );
		$this->maybe_connect( $server_id );
	}

	/**
	 * Remove a remote — closes any active handle, unregisters from offsetlog,
	 * forgets state.
	 */
	public function remove_remote( string $server_id ): void {
		if ( ! isset( $this->remotes[ $server_id ] ) ) {
			return;
		}
		$handle = $this->remotes[ $server_id ]['handle'];
		if ( $handle instanceof \CurlHandle ) {
			$this->detach_handle( $server_id, $handle );
		}
		unset( $this->remotes[ $server_id ] );
	}

	public function remote_count(): int {
		return \count( $this->remotes );
	}

	public function active_count(): int {
		$n = 0;
		foreach ( $this->remotes as $r ) {
			if ( $r['handle'] instanceof \CurlHandle ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Test-only inspector: returns the cURL handle for a remote (or null if disconnected).
	 *
	 * @internal
	 */
	public function test_get_handle( string $server_id ): ?\CurlHandle {
		return $this->remotes[ $server_id ]['handle'] ?? null;
	}

	/**
	 * Test-only inspector: returns last_http_code for a remote.
	 *
	 * @internal
	 */
	public function get_last_http_code( string $server_id ): ?int {
		return $this->remotes[ $server_id ]['last_http_code'] ?? null;
	}

	/**
	 * Test-only inspector: returns last_error for a remote.
	 *
	 * @internal
	 */
	public function get_last_error( string $server_id ): ?string {
		return $this->remotes[ $server_id ]['last_error'] ?? null;
	}

	/**
	 * Test-only inspector: returns current backoff seconds for a remote.
	 *
	 * @internal
	 */
	public function get_backoff( string $server_id ): int {
		return $this->remotes[ $server_id ]['current_backoff'] ?? self::INITIAL_BACKOFF;
	}

	/**
	 * Test-only inspector: returns slot for a remote.
	 *
	 * @internal
	 */
	public function get_slot( string $server_id ): ?int {
		return $this->remotes[ $server_id ]['slot'] ?? null;
	}

	/**
	 * Test-only inspector: returns last position for a remote.
	 *
	 * @internal
	 */
	public function get_position( string $server_id ): array {
		return $this->remotes[ $server_id ]['position'] ?? [ 'segment_id' => 0, 'offset' => 0 ];
	}

	// =========================================================================
	// SSE write callback + parser (one path; called by cURL via WRITEFUNCTION
	// and directly from tests via process_sse_chunk for fixture-driven runs).
	// =========================================================================

	/**
	 * cURL WRITEFUNCTION callback. Routes the chunk to the per-server parser.
	 *
	 * Returning fewer bytes than received signals cURL to abort the transfer —
	 * we use that as the disconnect mechanism for buffer / event / queue overflow.
	 *
	 * @param \CurlHandle $handle Easy handle.
	 * @param string      $bytes  Chunk.
	 * @return int Bytes consumed (== strlen($bytes) on success, 0 to abort).
	 */
	public function on_curl_data( \CurlHandle $handle, string $bytes ): int {
		$server_id = $this->handle_to_server[ \spl_object_id( $handle ) ] ?? null;
		if ( null === $server_id || ! isset( $this->remotes[ $server_id ] ) ) {
			return \strlen( $bytes );
		}
		$len = \strlen( $bytes );
		if ( 0 === $len ) {
			return 0;
		}

		// Capture HTTP code on first byte of the response body.
		if ( null === $this->remotes[ $server_id ]['last_http_code'] ) {
			$code                                                 = (int) \curl_getinfo( $handle, CURLINFO_HTTP_CODE );
			$this->remotes[ $server_id ]['last_http_code']        = $code > 0 ? $code : null;
		}

		$ok = $this->process_sse_chunk_for( $server_id, $bytes );
		return $ok ? $len : 0;
	}

	/**
	 * Public chunk-parser. The original prototype tests invoke this without
	 * specifying a server_id; we route those into a synthetic `__test__` server
	 * so the caller can drive the parser without setting up a remote.
	 *
	 * @param string $chunk Bytes from a (real or fake) SSE feed.
	 */
	public function process_sse_chunk( string $chunk ): void {
		// Synthetic remote for unparented test drives.
		if ( ! isset( $this->remotes['__test__'] ) ) {
			$this->remotes['__test__'] = [
				'url'             => 'https://__test__/',
				'auth_username'   => '',
				'auth_password'   => '',
				'auth_token'      => '',
				'handle'          => null,
				'buffer'          => '',
				'current_event'   => [ 'event' => '', 'data' => '' ],
				'event_queue'     => [],
				'slot'            => null,
				'position'        => [ 'segment_id' => 0, 'offset' => 0 ],
				'last_event_time' => Core::$now,
				'current_backoff' => self::INITIAL_BACKOFF,
				'last_attempt'    => 0.0,
				'connected'       => true,
				'last_error'      => null,
				'last_http_code'  => null,
				'last_heartbeat'  => 0,
			];
		}
		$this->process_sse_chunk_for( '__test__', $chunk );
		$this->drain_test_queue();
	}

	/**
	 * Drain the synthetic-remote queue: convert any `data` event the test feed
	 * left in `event_queue` into TM_BYTESTREAM messages so the legacy tests
	 * that consume `$capture->captured[*][Message::VALUE]` still see plain
	 * payload strings. Production-path remotes go through forward_entry()
	 * instead (which JSON-encodes + applies the 3-arg ingest filter).
	 */
	private function drain_test_queue(): void {
		if ( ! isset( $this->remotes['__test__'] ) ) {
			return;
		}
		while ( ! empty( $this->remotes['__test__']['event_queue'] ) ) {
			$event   = \array_shift( $this->remotes['__test__']['event_queue'] );
			$payload = $event['raw_data'] ?? '';
			// Test feeds frequently use `data: <plain>` without an `event:` field.
			// Apply the 3-arg ingest filter so the rewrite-filter test path keeps
			// working; fall back to pass-through if no filter is registered.
			$filtered = \apply_filters( 'newspack_nodes/aggregator_ingest_line', $payload, '__test__', $this->partition );
			if ( ! \is_string( $filtered ) || '' === $filtered ) {
				continue;
			}
			$msg                       = Message::new_message();
			$msg[ Message::TYPE ]      = Message::TM_BYTESTREAM;
			$msg[ Message::TIMESTAMP ] = Core::$now;
			$msg[ Message::FROM ]      = $this->name;
			$msg[ Message::VALUE ]     = $filtered;
			$this->sink?->fill( $msg );
		}
	}

	/**
	 * Per-server SSE parser. Returns false on overflow (caller signals cURL to abort).
	 *
	 * The buffer / event / queue caps live here so a misbehaving remote can't
	 * drag the whole worker process down — disconnect-on-overflow is a hard
	 * boundary, not a soft warning.
	 */
	private function process_sse_chunk_for( string $server_id, string $bytes ): bool {
		$state            = &$this->remotes[ $server_id ];
		$state['buffer'] .= $bytes;

		// Hard cap on raw buffer growth. Catches "no newline ever arrives" attacks.
		if ( \strlen( $state['buffer'] ) > self::MAX_BUFFER_SIZE ) {
			$state['last_error'] = 'Buffer overflow (no newline in ' . self::MAX_BUFFER_SIZE . ' bytes)';
			$state['buffer']     = '';
			$state['connected']  = false;
			return false;
		}

		// Per SSE spec: dispatch on blank line. Walk lines until buffer has none.
		while ( $state['connected'] && false !== ( $newline_pos = \strpos( $state['buffer'], "\n" ) ) ) {
			$line            = \substr( $state['buffer'], 0, $newline_pos );
			$state['buffer'] = \substr( $state['buffer'], $newline_pos + 1 );
			$line            = \rtrim( $line, "\r" );
			if ( ! $this->parse_sse_line( $server_id, $line ) ) {
				return false;
			}
		}

		return $state['connected'];
	}

	/**
	 * Parse one SSE line, dispatching on blank line.
	 *
	 * Returns false on event-data overflow so the caller can abort the cURL
	 * transfer immediately (rather than waiting for the next chunk to arrive).
	 */
	private function parse_sse_line( string $server_id, string $line ): bool {
		$state = &$this->remotes[ $server_id ];

		// Blank line dispatches the in-progress event.
		if ( '' === $line ) {
			return $this->dispatch_event( $server_id );
		}

		$colon_pos = \strpos( $line, ':' );
		// Comment lines (`: keepalive`) start with `:`. SSE spec says ignore.
		if ( false === $colon_pos || 0 === $colon_pos ) {
			return true;
		}

		$field = \substr( $line, 0, $colon_pos );
		$value = \substr( $line, $colon_pos + 1 );
		// Spec: strip exactly one leading space after the colon.
		if ( isset( $value[0] ) && ' ' === $value[0] ) {
			$value = \substr( $value, 1 );
		}

		switch ( $field ) {
			case 'event':
				$state['current_event']['event'] = $value;
				break;
			case 'data':
				if ( '' !== $state['current_event']['data'] ) {
					$state['current_event']['data'] .= "\n";
				}
				$state['current_event']['data'] .= $value;
				if ( \strlen( $state['current_event']['data'] ) > self::MAX_EVENT_SIZE ) {
					$state['last_error']    = 'Event data overflow (' . self::MAX_EVENT_SIZE . ' bytes)';
					$state['current_event'] = [ 'event' => '', 'data' => '' ];
					$state['connected']     = false;
					return false;
				}
				break;
			// Other SSE fields (`id`, `retry`) are deliberately ignored.
		}
		return true;
	}

	/**
	 * Dispatch the accumulated event. Mutates state per event-type:
	 *
	 *  - `connected` -> capture slot, mark connection-status `connected`.
	 *  - `heartbeat` -> update `last_sse_heartbeat` in memcache; advance position.
	 *  - `entry`     -> validate, stamp _source, JSON-encode, apply ingest filter,
	 *                   PIPE_BUF-guard, sink it, advance position.
	 *  - `timeout`   -> hint that the remote is closing; do NOT change connected
	 *                   state (cURL will report DONE shortly).
	 *
	 * Returns false on queue overflow so the caller can abort transfer.
	 */
	private function dispatch_event( string $server_id ): bool {
		$state              = &$this->remotes[ $server_id ];
		$type               = $state['current_event']['event'];
		$raw_data           = $state['current_event']['data'];
		$state['current_event'] = [ 'event' => '', 'data' => '' ];

		// Real remotes always carry an `event:` field — strip the no-type case.
		// The synthetic `__test__` remote (process_sse_chunk fixture-driver) is
		// the exception: existing tests feed `data:`-only blocks; the test-queue
		// drain forwards them with type='' so back-compat fixtures keep working.
		if ( '' === $type && '__test__' !== $server_id ) {
			return true;
		}
		// Even the test path needs SOMETHING to dispatch — empty data + empty
		// type is just a heartbeat-comment, drop it.
		if ( '' === $type && '' === $raw_data ) {
			return true;
		}

		// Backpressure: too many events queued -> disconnect rather than OOM.
		// Only the synthetic `__test__` remote actually queues; production-side
		// remotes process events inline in this same function so the queue is
		// always empty. Keep the cap so test-feed fixtures can exercise the
		// overflow path matching upstream semantics.
		if ( '__test__' === $server_id && \count( $state['event_queue'] ) >= self::MAX_QUEUE_SIZE ) {
			$state['last_error'] = 'Event queue overflow (' . self::MAX_QUEUE_SIZE . ' events)';
			$state['connected']  = false;
			return false;
		}

		// Reset backoff: any successful event receipt counts.
		$state['current_backoff'] = self::INITIAL_BACKOFF;
		$state['last_event_time'] = Core::$now;

		// Try to JSON-decode the data. `entry` events MUST decode; the others
		// are accepted as-strings if decode fails (heartbeats may carry empty `{}`).
		$decoded = \json_decode( $raw_data, true, 16 );

		if ( 'entry' === $type && null === $decoded ) {
			// Drop malformed entries silently (matches upstream's null-decode skip).
			return true;
		}

		// Capture slot from the connected-event payload (used by /firehose/heartbeat).
		if ( 'connected' === $type && \is_array( $decoded ) && isset( $decoded['slot'] ) ) {
			$state['slot'] = (int) $decoded['slot'];
			$this->record_successful_heartbeat( $server_id );
			$state['last_heartbeat'] = (int) Core::$now;
			$this->update_connection_status( $server_id, 'connected', $state['last_http_code'], '' );
		}

		// Update position from heartbeat or entry events. Mirrors upstream:
		// position lives in heartbeat too so the offsetlog can advance even on
		// idle remotes.
		if ( ( 'heartbeat' === $type || 'entry' === $type )
			&& \is_array( $decoded ) && isset( $decoded['position'] ) ) {
			$state['position'] = [
				'segment_id' => \max( 0, (int) ( $decoded['position']['segment_id'] ?? $state['position']['segment_id'] ) ),
				'offset'     => \max( 0, (int) ( $decoded['position']['offset'] ?? $state['position']['offset'] ) ),
			];
			unset( $decoded['position'] );
		}

		// SSE heartbeat status (display-only; the round-trip POST is separate).
		if ( 'heartbeat' === $type && \is_array( $decoded ) ) {
			$ts = $decoded['ts'] ?? null;
			$this->update_sse_heartbeat( $server_id, \is_numeric( $ts ) ? (int) $ts : (int) Core::$now );
		}

		// Forward `entry` events as actual firehose lines.
		if ( 'entry' === $type && \is_array( $decoded ) ) {
			$this->forward_entry( $server_id, $decoded );
		}

		// Synthetic test remote stashes events for the legacy fixture-driven
		// drain path. Real remotes don't need the queue: dispatch_event does
		// all the side effects inline, so queueing only burns memory.
		if ( '__test__' === $server_id ) {
			$state['event_queue'][] = [
				'type'     => $type,
				'data'     => $decoded,
				'raw_data' => $raw_data,
			];
		}

		return true;
	}

	/**
	 * Validate, stamp, encode, filter, PIPE_BUF-guard, and sink an `entry`-type
	 * payload. Position advances on successful sink OR on intentional drop
	 * (filter returning null/false) — only a write/encode failure leaves the
	 * remote position unchanged so the next iteration re-fetches.
	 */
	private function forward_entry( string $server_id, array $data ): void {
		// Per upstream: entries must have `data.k` (string) and `data.ts` (numeric).
		if ( ! isset( $data['k'] ) || ! \is_string( $data['k'] ) ) {
			return;
		}
		if ( ! isset( $data['ts'] ) || ! \is_numeric( $data['ts'] ) ) {
			return;
		}
		// Stamp source server BEFORE encoding so downstream filters see it.
		$data['_source'] = $server_id;

		// wp_json_encode handles UTF-8 sanitization for malformed upstream data
		// (SSE chunks from remote servers may contain arbitrary user content).
		$line = \wp_json_encode( $data );
		if ( false === $line || '' === $line ) {
			return;
		}

		// PIPE_BUF guard. The Topic/Partition does its own check too, but
		// rejecting here saves a write attempt and a filter invocation.
		if ( \strlen( $line ) > self::MAX_LINE_BYTES ) {
			Core::print_less_often( "StreamMerger: dropping {$server_id} entry > " . self::MAX_LINE_BYTES . ' bytes' );
			$this->set_state(
				'DROPPED',
				[ 'server' => $server_id, 'reason' => 'oversize', 'size' => \strlen( $line ) ]
			);
			return;
		}

		// 3-arg ingest filter (line, server_id, partition). Filters can return
		// null/false to drop the line; null is the documented `drop` sentinel.
		$line = \apply_filters( 'newspack_nodes/aggregator_ingest_line', $line, $server_id, $this->partition );
		if ( null === $line || false === $line || '' === $line ) {
			// Drop is intentional: the position is kept advancing because the
			// remote DID send us this entry — we just chose to skip it.
			return;
		}
		if ( ! \is_string( $line ) ) {
			return;
		}
		// Re-check after filter (filters can mutate length).
		if ( \strlen( $line ) > self::MAX_LINE_BYTES ) {
			Core::print_less_often( "StreamMerger: post-filter {$server_id} entry > " . self::MAX_LINE_BYTES . ' bytes' );
			return;
		}

		// Re-decode after the filter ran on the JSON string form (filters may
		// have mutated the payload). VALUE must be a parsed array (TM_STRUCT)
		// so RequestBuilder accepts it — TM_BYTESTREAM gets dropped at
		// RequestBuilder::fill's type-gate, silently dropping every spoke
		// entry. Match what local LogManager::message() writes.
		$decoded = \json_decode( $line, true, 64 );
		if ( ! \is_array( $decoded ) ) {
			return;
		}
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::TO ]        = \is_string( $this->target ) ? $this->target : '';
		// KEY = rid so hub-side partition routing co-locates entries by request,
		// matching the producer-side convention. The upstream SSE controller
		// back-fills entry['rid'] from the source Message::KEY, so `$data['rid']`
		// is populated.
		$msg[ Message::KEY ]       = (string) ( $data['rid'] ?? '' );
		$msg[ Message::VALUE ]     = $decoded;
		$this->sink?->fill( $msg );
	}

	// =========================================================================
	// cURL multi-handle integration with EventFramework
	// =========================================================================

	/**
	 * Lazily create the shared cURL multi handle and register it with the
	 * runtime's EventFramework so drain_curl_multi() runs each tick.
	 */
	public function init_curl_multi(): void {
		if ( null !== $this->multi ) {
			return;
		}
		$this->multi = \curl_multi_init();
		EventFramework::instance()->register_curl_handle( $this, $this->multi );
	}

	/**
	 * Open an easy handle for $server_id if disconnected and outside backoff.
	 *
	 * Returns true if a new handle was created and added to the multi.
	 */
	private function maybe_connect( string $server_id ): bool {
		if ( ! isset( $this->remotes[ $server_id ] ) ) {
			return false;
		}
		$state = &$this->remotes[ $server_id ];
		if ( $state['handle'] instanceof \CurlHandle ) {
			return false;
		}

		$now = Core::$now;
		// Skip if last attempt was within backoff (but allow first attempt at last_attempt=0).
		if ( $state['last_attempt'] > 0.0 && ( $now - $state['last_attempt'] ) < $state['current_backoff'] ) {
			$this->update_connection_status( $server_id, 'backoff', null, $state['last_error'] ?? "Waiting {$state['current_backoff']}s before retry", $state['current_backoff'] );
			return false;
		}

		// Build URL with position-resume + aggregator flag. Match upstream:
		// position params are emitted only if either is non-zero (so a fresh
		// remote with no offsetlog history sends the cleaner `?aggregator=1`
		// URL upstream's REST endpoint expects).
		$endpoint = $state['url'] . '/wp-json/newspack-nodes/v1/firehose/stream';
		$params   = [
			'partition'  => $this->partition,
			'aggregator' => 1, // skip per-connection slot rate-limit on remote.
		];
		if ( $state['position']['segment_id'] > 0 || $state['position']['offset'] > 0 ) {
			$params['segment_id'] = (int) $state['position']['segment_id'];
			$params['offset']     = (int) $state['position']['offset'];
		}
		$url = $endpoint . ( false === \strpos( $endpoint, '?' ) ? '?' : '&' ) . \http_build_query( $params );

		// Build HTTP headers. Basic auth is preferred; Bearer is the compat fallback.
		$headers = [
			'Accept: text/event-stream',
			'Cache-Control: no-cache',
		];
		if ( '' !== $state['auth_username'] && '' !== $state['auth_password'] ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth.
			$headers[] = 'Authorization: Basic ' . \base64_encode( $state['auth_username'] . ':' . $state['auth_password'] );
		} elseif ( '' !== $state['auth_token'] ) {
			$headers[] = 'Authorization: Bearer ' . $state['auth_token'];
		}

		$ch = \curl_init();
		if ( false === $ch || ! ( $ch instanceof \CurlHandle ) ) {
			$state['last_error'] = 'curl_init failed';
			$this->increase_backoff( $server_id );
			return false;
		}

		\curl_setopt_array(
			$ch,
			[
				\CURLOPT_URL            => $url,
				\CURLOPT_RETURNTRANSFER => false,
				\CURLOPT_FOLLOWLOCATION => false,
				\CURLOPT_TIMEOUT        => 0, // Long-running connection; rely on HEARTBEAT_TIMEOUT.
				\CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
				\CURLOPT_HTTPHEADER     => $headers,
				\CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
				\CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
				\CURLOPT_PROTOCOLS      => $this->require_https ? \CURLPROTO_HTTPS : ( \CURLPROTO_HTTPS | \CURLPROTO_HTTP ),
				\CURLOPT_WRITEFUNCTION  => function ( $h, $bytes ) {
					return $this->on_curl_data( $h, $bytes );
				},
			]
		);

		// Reset per-connection state.
		$state['buffer']           = '';
		$state['current_event']    = [ 'event' => '', 'data' => '' ];
		$state['event_queue']      = [];
		$state['last_event_time']  = $now;
		$state['connected']        = true;
		$state['last_error']       = null;
		$state['last_http_code']   = null;
		$state['handle']           = $ch;
		$state['last_attempt']     = $now;
		$state['slot']             = null;

		if ( null !== $this->multi ) {
			$result = \curl_multi_add_handle( $this->multi, $ch );
			if ( 0 !== $result ) {
				$state['last_error'] = "curl_multi_add_handle failed: {$result}";
				\curl_close( $ch );
				$state['handle']    = null;
				$state['connected'] = false;
				$this->increase_backoff( $server_id );
				return false;
			}
		}
		$this->handle_to_server[ \spl_object_id( $ch ) ] = $server_id;

		// Status keys: clear any stale heartbeat status from the previous connection
		// then announce we're attempting (memcache-driven dashboard reads this).
		$this->clear_heartbeat_status( $server_id );
		$this->update_connection_status( $server_id, 'connecting', null, null, $state['current_backoff'] );

		return true;
	}

	/**
	 * Detach a handle from the shared multi + close it. Idempotent.
	 *
	 * Order matters: curl_multi_remove_handle() MUST run before curl_close()
	 * — otherwise the multi handle holds a pointer to a freed easy handle.
	 */
	private function detach_handle( string $server_id, \CurlHandle $handle ): void {
		if ( null !== $this->multi ) {
			@\curl_multi_remove_handle( $this->multi, $handle );
		}
		unset( $this->handle_to_server[ \spl_object_id( $handle ) ] );
		@\curl_close( $handle );
		if ( isset( $this->remotes[ $server_id ] ) ) {
			$this->remotes[ $server_id ]['handle']    = null;
			$this->remotes[ $server_id ]['connected'] = false;
		}
	}

	/**
	 * Called by EventFramework::drain_curl_multi() when curl_multi_info_read
	 * returns a CURLMSG_DONE for one of our handles.
	 */
	public function on_curl_message( array $info ): void {
		if ( ! isset( $info['msg'] ) || \CURLMSG_DONE !== $info['msg'] ) {
			return;
		}
		$handle = $info['handle'] ?? null;
		if ( ! ( $handle instanceof \CurlHandle ) ) {
			return;
		}
		$server_id = $this->handle_to_server[ \spl_object_id( $handle ) ] ?? null;
		if ( null === $server_id || ! isset( $this->remotes[ $server_id ] ) ) {
			// Unknown handle (test injection or stale) — best-effort cleanup.
			if ( null !== $this->multi ) {
				@\curl_multi_remove_handle( $this->multi, $handle );
			}
			@\curl_close( $handle );
			return;
		}

		$state                       = &$this->remotes[ $server_id ];
		$result                      = (int) ( $info['result'] ?? \CURLE_OK );
		$http_code                   = (int) \curl_getinfo( $handle, \CURLINFO_HTTP_CODE );
		$curl_error                  = \curl_error( $handle );
		$state['last_http_code']     = $http_code > 0 ? $http_code : null;

		// Classify the completion. Mirrors upstream's handle_completion() —
		// distinguishes cURL error vs non-200 vs clean close.
		if ( \CURLE_OK !== $result ) {
			$state['last_error'] = "cURL error {$result}: {$curl_error}";
			Core::print_less_often( "StreamMerger: {$server_id} disconnected: {$state['last_error']}" );
		} elseif ( 200 !== $http_code && 0 !== $http_code ) {
			$state['last_error'] = "HTTP {$http_code}";
			Core::print_less_often( "StreamMerger: {$server_id} HTTP {$http_code}" );
		} else {
			$state['last_error'] = 'Connection closed by server';
		}
		$this->set_state(
			'DISCONNECTED',
			[ 'server' => $server_id, 'error' => $state['last_error'], 'http' => $http_code ]
		);

		$this->detach_handle( $server_id, $handle );
		$this->increase_backoff( $server_id );
		$this->update_connection_status( $server_id, 'disconnected', $state['last_http_code'], $state['last_error'], $state['current_backoff'] );
		$this->clear_heartbeat_status( $server_id );
	}

	/**
	 * Bump backoff on disconnect: 1s -> 2s -> 4s -> ... -> capped at 30s.
	 */
	private function increase_backoff( string $server_id ): void {
		if ( ! isset( $this->remotes[ $server_id ] ) ) {
			return;
		}
		$cur = $this->remotes[ $server_id ]['current_backoff'];
		$this->remotes[ $server_id ]['current_backoff'] = \min( self::MAX_BACKOFF, \max( self::INITIAL_BACKOFF, $cur * 2 ) );
	}

	// =========================================================================
	// Periodic tick: stale detection, reconnect, heartbeat POST, commit.
	// =========================================================================

	/**
	 * Periodic-tick entry point. Driven by the topology owner (Router-hitchhike
	 * register('TIMER', ...) or an explicit Timer node). All four sub-tasks
	 * are idempotent + cheap.
	 */
	public function tick(): void {
		// NOTE: deliberately does NOT refresh Core::$now. EventFramework's
		// drain loop owns the clock and pokes Core::$now right before our
		// timer fires; tests pin Core::$now by direct assignment, so a tick()
		// that wrote microtime(true) would clobber the pinned value.
		foreach ( \array_keys( $this->remotes ) as $server_id ) {
			if ( '__test__' === $server_id ) {
				continue;
			}
			$this->check_stale( $server_id );
			$this->maybe_connect( $server_id );
			$this->maybe_send_heartbeat( $server_id );
		}
		$this->maybe_commit();
	}

	/**
	 * If the remote hasn't sent ANY event (including heartbeat) in
	 * HEARTBEAT_TIMEOUT seconds, kill the connection so a fresh one can rotate
	 * in. Bumps backoff so we don't thrash.
	 */
	private function check_stale( string $server_id ): void {
		if ( ! isset( $this->remotes[ $server_id ] ) ) {
			return;
		}
		$state = &$this->remotes[ $server_id ];
		if ( ! $state['connected'] ) {
			return;
		}
		if ( ! ( $state['handle'] instanceof \CurlHandle ) ) {
			return;
		}
		$elapsed = Core::$now - $state['last_event_time'];
		if ( $elapsed <= self::HEARTBEAT_TIMEOUT ) {
			return;
		}
		$stale_seconds       = (int) $elapsed;
		$state['last_error'] = "Stale connection (no events for {$stale_seconds}s)";
		Core::print_less_often( "StreamMerger: {$server_id} stale ({$stale_seconds}s) — reconnecting" );

		$handle = $state['handle'];
		$this->detach_handle( $server_id, $handle );
		$this->increase_backoff( $server_id );
		$this->update_connection_status( $server_id, 'disconnected', $state['last_http_code'], $state['last_error'], $state['current_backoff'] );
		$this->clear_heartbeat_status( $server_id );
	}

	/**
	 * Send a keepalive POST to /firehose/heartbeat to extend the hub's TTL on
	 * the spoke's slot. Fire-and-forget; we read the result for the dashboard
	 * but don't act on errors (server will close the SSE if the slot expires).
	 */
	private function maybe_send_heartbeat( string $server_id ): void {
		if ( ! isset( $this->remotes[ $server_id ] ) ) {
			return;
		}
		$state = &$this->remotes[ $server_id ];
		if ( ! $state['connected'] ) {
			return;
		}
		$slot = $state['slot'];
		if ( null === $slot || $slot < 0 ) {
			return;
		}
		$now = (int) Core::$now;
		if ( $now - (int) $state['last_heartbeat'] < self::HEARTBEAT_INTERVAL ) {
			return;
		}
		$state['last_heartbeat'] = $now;

		// Build auth headers, mirroring connect-time logic so tokens stay in sync.
		$headers = [];
		if ( '' !== $state['auth_username'] && '' !== $state['auth_password'] ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth.
			$headers['Authorization'] = 'Basic ' . \base64_encode( $state['auth_username'] . ':' . $state['auth_password'] );
		} elseif ( '' !== $state['auth_token'] ) {
			$headers['Authorization'] = 'Bearer ' . $state['auth_token'];
		}

		// Honour HTTPS-only invariant on the heartbeat path too.
		$endpoint = $state['url'] . '/wp-json/newspack-nodes/v1/firehose/heartbeat';
		if ( $this->require_https && \stripos( $endpoint, 'https://' ) !== 0 ) {
			$state['last_error'] = 'heartbeat endpoint not HTTPS';
			return;
		}

		if ( ! \function_exists( 'wp_remote_post' ) ) {
			return;
		}

		$start = \microtime( true );
		$response = @\wp_remote_post(
			$endpoint,
			[
				'headers'             => $headers,
				'body'                => [
					'slot'       => $slot,
					'aggregator' => true,
					'partition'  => $this->partition,
				],
				// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Cross-server heartbeat needs a longer timeout.
				'timeout'             => 10,
				'sslverify'           => $this->verify_ssl,
				'redirection'         => 0,
				'limit_response_size' => 1048576,
			]
		);
		$rtt = ( \microtime( true ) - $start ) * 1000;

		$this->update_heartbeat_status( $server_id, $response, $rtt, $now );
	}

	/**
	 * Periodic offsetlog commit (every COMMIT_INTERVAL_S). Single JSONL line
	 * per commit holds positions for all remotes — atomic w.r.t. the offsetlog,
	 * cheap to restore at startup.
	 */
	private function maybe_commit(): void {
		$now = Core::$now;
		if ( $now - $this->last_commit_time < self::COMMIT_INTERVAL_S ) {
			return;
		}
		$this->commit_all();
		$this->last_commit_time = $now;
	}

	/**
	 * Write a single JSONL line to the offsetlog covering all remotes.
	 *
	 * Format (per spec): `{server_id, segment_id, offset, ts}` — but emitted as
	 * a single line containing `{ "<server_id>": {"seg":N,"off":M}, ..., "_ts":t }`
	 * so restore is one decode.
	 */
	public function commit_all(): void {
		if ( empty( $this->remotes ) ) {
			return;
		}
		$offsetlog = $this->ensure_offsetlog();
		if ( null === $offsetlog ) {
			return;
		}
		$entry = [];
		foreach ( $this->remotes as $server_id => $state ) {
			if ( '__test__' === $server_id ) {
				continue;
			}
			$entry[ $server_id ] = [
				'seg' => (int) ( $state['position']['segment_id'] ?? 0 ),
				'off' => (int) ( $state['position']['offset'] ?? 0 ),
			];
		}
		if ( empty( $entry ) ) {
			return;
		}
		$entry['_ts'] = (int) Core::$now;
		// Wrap the multi-spoke position struct as TM_STRUCT and route through
		// Partition::fill — the offsetlog stores the canonical packed wire
		// format, same as every other Partition. restore_offset() unpacks back.
		// Message::packed JSON-encodes the envelope (VALUE included), so
		// passing the array directly avoids double-encoding.
		$msg                       = \Newspack_Nodes\Message::new_message();
		$msg[ \Newspack_Nodes\Message::TYPE ]      = \Newspack_Nodes\Message::TM_STRUCT;
		$msg[ \Newspack_Nodes\Message::TIMESTAMP ] = Core::$now;
		$msg[ \Newspack_Nodes\Message::VALUE ]     = $entry;
		$offsetlog->fill( $msg );
		// Force the position to disk — supervisor restarts and the next
		// StreamMerger instance read it via restore_offset; we can't wait
		// for the offsetlog Partition's PIPE_BUF threshold to fire.
		$offsetlog->flush();
	}

	/**
	 * Restore last-committed positions for $server_id from the offsetlog.
	 *
	 * Reads the newest segment, falls back to the previous one if the newest
	 * is empty (post-rotation race). Position remains {0,0} if no offsetlog
	 * exists — a fresh aggregator starts from the head of the spoke firehose.
	 */
	private function restore_offset( string $server_id ): void {
		$offsetlog = $this->ensure_offsetlog();
		if ( null === $offsetlog ) {
			return;
		}
		$segments = $offsetlog->get_segments( true );
		if ( empty( $segments ) ) {
			return;
		}
		$last     = \end( $segments );
		$content  = $offsetlog->read_at( $last['id'], 0, $last['size'] );
		if ( '' === $content && \count( $segments ) > 1 ) {
			$prev    = $segments[ \count( $segments ) - 2 ];
			$content = $offsetlog->read_at( $prev['id'], 0, $prev['size'] );
		}
		if ( '' === $content ) {
			return;
		}
		$lines = \explode( "\n", \rtrim( $content, "\n" ) );
		// Each line is a packed Tachikoma Message; unpack the outer envelope.
		// VALUE is the position struct itself (TM_STRUCT — Message::packed
		// already JSON-encoded the envelope, so VALUE comes back as an array).
		$msg    = \Newspack_Nodes\Message::unpacked( (string) \end( $lines ) );
		$latest = $msg[ \Newspack_Nodes\Message::VALUE ];
		if ( ! \is_array( $latest ) ) {
			return;
		}
		if ( ! isset( $latest[ $server_id ] ) || ! \is_array( $latest[ $server_id ] ) ) {
			return;
		}
		$pos = $latest[ $server_id ];
		$this->remotes[ $server_id ]['position'] = [
			'segment_id' => \max( 0, (int) ( $pos['seg'] ?? 0 ) ),
			'offset'     => \max( 0, (int) ( $pos['off'] ?? 0 ) ),
		];
	}

	/**
	 * Lazily build the per-partition offsetlog Partition. allow_large_writes
	 * because each commit serializes positions for ALL remotes — easy to push
	 * past 4KB on a hub with many spokes.
	 */
	private function ensure_offsetlog(): ?Partition {
		if ( null !== $this->offsetlog ) {
			return $this->offsetlog;
		}
		$logs_dir = $this->resolve_logs_dir();
		if ( '' === $logs_dir ) {
			return null;
		}
		$dir = "{$logs_dir}/remote_firehose.log";
		if ( ! \is_dir( $dir ) ) {
			// logs_dir is base_dir-relative — operator storage, not WP-managed.
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
			@\mkdir( $dir, 0755, true );
		}
		$this->offsetlog = new Partition( $dir, $this->partition );
		// Offsetlog is single-writer per StreamMerger — small JSON payloads,
		// no cross-process contention. Skips allow_large_writes() and its
		// graph-registered Lock+heartbeat machinery.
		return $this->offsetlog;
	}

	private function resolve_logs_dir(): string {
		if ( null !== $this->logs_dir ) {
			return $this->logs_dir;
		}
		$config = PerformanceControllerBase::load_config();
		$base   = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		return $base . '/logs';
	}

	// =========================================================================
	// Memcache status writers (drive the Aggregator dashboard).
	// =========================================================================

	private function status_key( string $server_id ): string {
		return "aggregator_status:{$server_id}:p{$this->partition}";
	}

	private function cache(): ?Cache_Interface {
		if ( null !== $this->cache ) {
			return $this->cache->is_available() ? $this->cache : null;
		}
		$config  = PerformanceControllerBase::load_config();
		$servers = $config['memcache_servers'] ?? Memcached_Cache::DEFAULT_SERVERS;
		if ( ! \is_array( $servers ) ) {
			$servers = Memcached_Cache::DEFAULT_SERVERS;
		}
		$this->cache = new Memcached_Cache( $servers );
		return $this->cache->is_available() ? $this->cache : null;
	}

	/**
	 * Update connection-status row.
	 *
	 * @param string      $server_id Server identifier.
	 * @param string      $status    'connected'|'connecting'|'disconnected'|'backoff'.
	 * @param int|null    $http_code HTTP code if available (omit to preserve existing).
	 * @param string|null $error     Error message (omit to preserve existing).
	 * @param int|null    $backoff   Current backoff in seconds.
	 */
	private function update_connection_status( string $server_id, string $status, ?int $http_code = null, ?string $error = null, ?int $backoff = null ): void {
		$cache = $this->cache();
		if ( null === $cache ) {
			return;
		}
		$key      = $this->status_key( $server_id );
		$existing = $cache->get( $key );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$data = [
			'last_connection_attempt' => (int) Core::$now,
			'last_connection_status'  => $status,
		];
		if ( null !== $http_code ) {
			$data['last_connection_response'] = $http_code;
		}
		if ( null !== $error ) {
			$data['last_connection_error'] = $error;
		}
		if ( null !== $backoff ) {
			$data['current_backoff'] = $backoff;
		}
		$cache->set( $key, \array_merge( $existing, $data ), self::STATUS_TTL );
	}

	private function record_successful_heartbeat( string $server_id ): void {
		$cache = $this->cache();
		if ( null === $cache ) {
			return;
		}
		$key      = $this->status_key( $server_id );
		$existing = $cache->get( $key );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$now  = (int) Core::$now;
		$data = [
			'last_heartbeat_sent'            => $now,
			'last_heartbeat_response'        => $now,
			'last_heartbeat_rtt'             => 0, // Instant — slot just acquired.
			'last_heartbeat_response_status' => 'success',
			'last_heartbeat_error'           => null,
		];
		$cache->set( $key, \array_merge( $existing, $data ), self::STATUS_TTL );
	}

	private function update_sse_heartbeat( string $server_id, int $timestamp ): void {
		$cache = $this->cache();
		if ( null === $cache ) {
			return;
		}
		$key      = $this->status_key( $server_id );
		$existing = $cache->get( $key );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$cache->set( $key, \array_merge( $existing, [ 'last_sse_heartbeat' => $timestamp ] ), self::STATUS_TTL );
	}

	private function clear_heartbeat_status( string $server_id ): void {
		$cache = $this->cache();
		if ( null === $cache ) {
			return;
		}
		$key      = $this->status_key( $server_id );
		$existing = $cache->get( $key );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$data = [
			'last_heartbeat_sent'            => null,
			'last_heartbeat_response'        => null,
			'last_heartbeat_rtt'             => null,
			'last_heartbeat_response_status' => 'pending',
			'last_heartbeat_error'           => null,
			'last_sse_heartbeat'             => null,
		];
		$cache->set( $key, \array_merge( $existing, $data ), self::STATUS_TTL );
	}

	/**
	 * Parse a wp_remote_post() response into status fields.
	 *
	 * Mirrors upstream — distinguishes WP_Error / non-200 / slot_expired (200
	 * with `success:false`).
	 *
	 * @param string                $server_id Server identifier.
	 * @param array|\WP_Error|mixed $response  Response from wp_remote_post().
	 * @param float                 $rtt       Round-trip time in milliseconds.
	 * @param int                   $sent_at   Wall-clock send time.
	 */
	private function update_heartbeat_status( string $server_id, $response, float $rtt, int $sent_at ): void {
		$cache = $this->cache();
		if ( null === $cache ) {
			return;
		}
		$key      = $this->status_key( $server_id );
		$existing = $cache->get( $key );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}

		$status = 'success';
		$error  = null;

		if ( $response instanceof \WP_Error ) {
			$status = 'error';
			$error  = $response->get_error_message();
		} elseif ( ! \is_array( $response ) ) {
			$status = 'error';
			$error  = 'Unexpected wp_remote_post response shape';
		} else {
			$code = isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			$body = isset( $response['body'] ) ? \json_decode( (string) $response['body'], true, 16 ) : null;

			if ( 200 !== $code ) {
				$status = 'error';
				$error  = "HTTP {$code}";
			} elseif ( \is_array( $body ) && isset( $body['success'] ) && false === $body['success'] ) {
				$status = 'slot_expired';
				$error  = (string) ( $body['error'] ?? 'Slot no longer valid' );
			}
		}

		$cache->set(
			$key,
			\array_merge( $existing, [
				'last_heartbeat_sent'            => $sent_at,
				'last_heartbeat_response'        => (int) Core::$now,
				'last_heartbeat_rtt'             => \round( $rtt, 2 ),
				'last_heartbeat_response_status' => $status,
				'last_heartbeat_error'           => $error,
			] ),
			self::STATUS_TTL
		);
	}

	// -------------------------------------------------------------------------
	// Sibling-CI verb table + node_schema (A3).
	// -------------------------------------------------------------------------

	/**
	 * Verbs the TSL `cmd stream-merger:config <verb>` invocations
	 * dispatch through. Resolved per-instance via $ci->patron().
	 *
	 * @return array<string,callable>
	 */
	private static function config_verbs(): array {
		static $verbs = null;
		if ( null === $verbs ) {
			$verbs = [
				'set_verify_ssl'             => static function ( CommandInterpreter $ci, string $args ): string {
					$args = \strtolower( \trim( $args ) );
					$bool = ( 'true' === $args || '1' === $args );
					/** @var self $patron */
					$patron = $ci->patron();
					$patron->set_verify_ssl( $bool );
					$patron->mark_verb_invoked( 'set_verify_ssl', $bool ? 'true' : 'false' );
					return 'ok';
				},
				'set_require_https'          => static function ( CommandInterpreter $ci, string $args ): string {
					$args = \strtolower( \trim( $args ) );
					$bool = ( 'true' === $args || '1' === $args );
					/** @var self $patron */
					$patron = $ci->patron();
					$patron->set_require_https( $bool );
					$patron->mark_verb_invoked( 'set_require_https', $bool ? 'true' : 'false' );
					return 'ok';
				},
				'start_periodic_tick'        => static function ( CommandInterpreter $ci, string $args ): string {
					/** @var self $patron */
					$patron = $ci->patron();
					$patron->start_periodic_tick();
					$patron->mark_verb_invoked( 'start_periodic_tick', '' );
					return 'ok';
				},
				'add_remote'                 => static function ( CommandInterpreter $ci, string $args ): string {
					$args = \trim( $args );
					if ( '' === $args ) {
						return 'usage: add_remote <server_id>';
					}
					/** @var self $patron */
					$patron = $ci->patron();
					$patron->add_remote( $args );
					$patron->mark_verb_invoked( 'add_remote', $args );
					return 'ok';
				},
				'load_remotes_from_registry' => static function ( CommandInterpreter $ci, string $args ): string {
					/** @var self $patron */
					$patron = $ci->patron();
					$registry = ServerRegistry::get_instance();
					foreach ( $registry->get_enabled() as $server_id => $entry ) {
						$patron->add_remote( (string) $server_id );
					}
					$patron->mark_verb_invoked( 'load_remotes_from_registry', '' );
					return 'ok';
				},
			];
		}
		return $verbs;
	}

	public static function node_schema(): array {
		return [
			'category'    => 'I/O',
			'description' => 'Pulls remote firehoses via SSE (cURL multi) and fans them into a local Topic.',
			'ctor'        => [
				[ 'name' => 'partition', 'type' => 'int', 'default' => 0 ],
			],
			'verbs'       => [
				[
					'name'        => 'set_verify_ssl',
					'description' => 'Toggle SSL certificate verification on outbound SSE connections.',
					'args'        => [ [ 'name' => 'verify', 'type' => 'bool', 'required' => true ] ],
				],
				[
					'name'        => 'set_require_https',
					'description' => 'Refuse to connect to non-HTTPS remote URLs.',
					'args'        => [ [ 'name' => 'require', 'type' => 'bool', 'required' => true ] ],
				],
				[
					'name'        => 'start_periodic_tick',
					'description' => 'Register with _router TIMER for periodic heartbeat + stale-check.',
					'args'        => [],
				],
				[
					'name'        => 'add_remote',
					'description' => 'Add a single remote SSE source (registry-driven).',
					'args'        => [ [ 'name' => 'server_id', 'type' => 'string', 'required' => true ] ],
				],
				[
					'name'        => 'load_remotes_from_registry',
					'description' => 'Iterate ServerRegistry::get_enabled() and add each remote.',
					'args'        => [],
				],
			],
		];
	}
}
