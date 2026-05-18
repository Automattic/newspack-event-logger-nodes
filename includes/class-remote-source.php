<?php
/**
 * RemoteSource
 *
 * One SSE-pulled spoke firehose, modeled as a first-class graph node.
 *
 * Each RemoteSource owns exactly one cURL multi handle (registered with the
 * substrate's EventFramework), one cURL easy handle (the SSE GET), one
 * in-memory cursor `{segment_id, offset}`, and one SSE connection's worth of
 * parser state. The class is a *source*: `fill()` is a no-op (it doesn't
 * receive messages); it generates Messages from the SSE feed it parses and
 * forwards them to its sink (typically the hub's `firehose:topic`).
 *
 * StreamMerger instantiates one of these per `ServerRegistry::get_enabled()`
 * entry and keeps a reference to each. The shared offsetlog (one file for
 * the whole hub) lives on StreamMerger, which periodically walks its
 * RemoteSource children, calls `position()` on each, and writes a combined
 * commit line. On startup StreamMerger reads that file and pushes positions
 * back into each RemoteSource via `restore_position()` before `connect()`.
 *
 * Lifecycle is driven by StreamMerger (no patron link — these nodes are
 * meant to be visible in the topology console). StreamMerger's
 * `remove_node()` walks its ref list and tears down each RemoteSource.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\EventFramework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

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

class RemoteSource extends Node {

	// ----- Reconnect / liveness tuning (mirrors upstream class-sse-client.php) -----

	public const MAX_BACKOFF        = 30;
	public const INITIAL_BACKOFF    = 1;
	public const CONNECT_TIMEOUT    = 5;
	public const HEARTBEAT_TIMEOUT  = 45;
	public const HEARTBEAT_INTERVAL = 15;

	// ----- Memory / size guards -----

	public const MAX_BUFFER_SIZE = 10485760; // 10MB
	public const MAX_EVENT_SIZE  = 10485760; // 10MB
	public const MAX_QUEUE_SIZE  = 10000;
	public const MAX_LINE_BYTES  = 3900;

	/** Memcache TTL for aggregator status keys (seconds). */
	public const STATUS_TTL = 300;

	private string $server_id;
	private string $url;
	private string $auth_username;
	private string $auth_password;
	private string $auth_token;
	private int    $partition;

	private bool $verify_ssl    = true;
	private bool $require_https = true;

	private ?Cache_Interface $cache = null;

	/** Owned multi handle, registered with the substrate's EventFramework. */
	private ?\CurlMultiHandle $multi = null;

	/** Active easy handle when connected, null otherwise. */
	private ?\CurlHandle $handle = null;

	private string  $buffer        = '';
	private array   $current_event = [ 'event' => '', 'data' => '' ];
	private ?int    $slot          = null;
	private array   $position      = [ 'segment_id' => 0, 'offset' => 0 ];
	private float   $last_event_time = 0.0;
	private int     $current_backoff = self::INITIAL_BACKOFF;
	private float   $last_attempt    = 0.0;
	private bool    $connected       = false;
	private ?string $last_error     = null;
	private ?int    $last_http_code  = null;
	private int     $last_heartbeat  = 0;

	/**
	 * Constructor.
	 *
	 * Credentials and URL come from the ServerRegistry entry that StreamMerger
	 * looked up — RemoteSource doesn't read the registry itself.
	 *
	 * @param string $server_id     Identifier — also the memcache status key fragment.
	 * @param string $url           Base URL (no trailing slash).
	 * @param string $auth_username Application-Password user (Basic auth).
	 * @param string $auth_password Application-Password secret.
	 * @param string $auth_token    Optional Bearer token fallback.
	 * @param int    $partition     Partition for the heartbeat URL params.
	 */
	public function __construct(
		string $server_id,
		string $url,
		string $auth_username = '',
		string $auth_password = '',
		string $auth_token = '',
		int $partition = 0
	) {
		$this->server_id     = $server_id;
		$this->url           = \rtrim( $url, '/' );
		$this->auth_username = $auth_username;
		$this->auth_password = $auth_password;
		$this->auth_token    = $auth_token;
		$this->partition     = \max( 0, $partition );
		$this->arguments     = \implode( ' ', [
			$server_id,
			$this->url,
			$auth_username,
			'[REDACTED]',
			'[REDACTED]',
			(string) $this->partition,
		] );
	}

	/**
	 * Node contract. RemoteSource is a *source* — like Tail, it generates
	 * messages from an external stream and pushes them down its sink. It
	 * doesn't accept upstream messages.
	 */
	public function fill( array &$message ): void {
		++$this->counter;
	}

	/**
	 * Override Node::dump_node to redact application-password and bearer-token
	 * fields before they hit the REPL. Default reflection-based dump would
	 * print the raw secrets — `dump_node my_remote` from the topology console
	 * was a credential-disclosure vector.
	 *
	 * @return array<string, mixed>
	 */
	public function dump_node(): array {
		$snapshot = parent::dump_node();
		foreach ( [ 'auth_password', 'auth_token' ] as $k ) {
			if ( isset( $snapshot[ $k ] ) && '' !== $snapshot[ $k ] ) {
				$snapshot[ $k ] = '[REDACTED]';
			}
		}
		return $snapshot;
	}

	// =========================================================================
	// Configuration / DI
	// =========================================================================

	public function server_id(): string {
		return $this->server_id;
	}

	public function url(): string {
		return $this->url;
	}

	public function set_cache( ?Cache_Interface $cache ): void {
		$this->cache = $cache;
	}

	public function set_verify_ssl( bool $verify ): void {
		$this->verify_ssl = $verify;
	}

	public function set_require_https( bool $require ): void {
		if ( ! $require && $this->require_https ) {
			Core::print_less_often( "RemoteSource[{$this->server_id}]: require_https=false — credentials WILL travel cleartext on insecure links." );
		}
		$this->require_https = $require;
	}

	/**
	 * Restore last-committed position. Called by StreamMerger after reading the
	 * shared offsetlog at startup, before `connect()`.
	 */
	public function restore_position( int $segment_id, int $offset ): void {
		$this->position = [
			'segment_id' => \max( 0, $segment_id ),
			'offset'     => \max( 0, $offset ),
		];
	}

	/**
	 * Current in-memory cursor. StreamMerger gathers this from each child on
	 * its periodic commit.
	 *
	 * @return array{segment_id:int,offset:int}
	 */
	public function position(): array {
		return $this->position;
	}

	/**
	 * Status snapshot for the StreamMerger GET_REMOTES request.
	 *
	 * @return array
	 */
	public function current_status(): array {
		return [
			'connected'        => $this->connected,
			'last_error'       => $this->last_error,
			'last_http_code'   => $this->last_http_code,
			'position'         => $this->position,
			'last_event_age_s' => $this->last_event_time > 0
				? (int) ( (float) ( Core::$now ?: \microtime( true ) ) - $this->last_event_time )
				: null,
			'current_backoff'  => $this->current_backoff,
			'slot'             => $this->slot,
		];
	}

	// =========================================================================
	// Periodic tick — driven by StreamMerger (one TIMER, fanned to children).
	// =========================================================================

	/**
	 * Per-tick housekeeping for this remote: stale check, reconnect-if-needed,
	 * heartbeat POST. Idempotent and cheap.
	 */
	public function tick(): void {
		$this->check_stale();
		$this->maybe_connect();
		$this->maybe_send_heartbeat();
	}

	// =========================================================================
	// cURL lifecycle
	// =========================================================================

	/**
	 * Ensure the owned multi handle exists and is registered with the
	 * EventFramework. Idempotent.
	 */
	private function ensure_multi(): void {
		if ( null !== $this->multi ) {
			return;
		}
		$this->multi = \curl_multi_init();
		EventFramework::instance()->register_curl_handle( $this, $this->multi );
	}

	/**
	 * Open an easy handle if currently disconnected and outside backoff.
	 *
	 * @return bool true if a handle was opened.
	 */
	private function maybe_connect(): bool {
		if ( $this->handle instanceof \CurlHandle ) {
			return false;
		}

		$now = (float) ( Core::$now ?: \microtime( true ) );
		if ( $this->last_attempt > 0.0 && ( $now - $this->last_attempt ) < $this->current_backoff ) {
			$this->update_connection_status(
				'backoff',
				null,
				$this->last_error ?? "Waiting {$this->current_backoff}s before retry",
				$this->current_backoff
			);
			return false;
		}

		if ( $this->require_https && \stripos( $this->url, 'https://' ) !== 0 ) {
			$this->last_error = 'refusing non-HTTPS URL';
			Core::print_less_often( "RemoteSource[{$this->server_id}]: non-HTTPS URL refused: {$this->url}" );
			$this->increase_backoff();
			return false;
		}

		$this->ensure_multi();

		// Subscription shape `firehose.pN` lands in Sse_Slot_Pool's per-partition
		// aggregator pool (60s TTL) via Messages_Stream_Controller's
		// `subscription_partition()` helper — no `aggregator=1` flag needed.
		$endpoint = $this->url . '/wp-json/newspack-nodes/v1/messages/stream';
		$params   = [
			'subscribe' => "firehose.p{$this->partition}",
		];
		if ( $this->position['segment_id'] > 0 || $this->position['offset'] > 0 ) {
			// Substrate's parse_positions expects a JSON-encoded object keyed
			// by subscription name → partition index → `{seg, off}`. The same
			// shape the dashboards use; only one entry here because RemoteSource
			// runs one connection per partition.
			$params['positions'] = (string) \wp_json_encode(
				[
					'firehose' => [
						$this->partition => [
							'seg' => $this->position['segment_id'],
							'off' => $this->position['offset'],
						],
					],
				]
			);
		}
		$endpoint .= ( false === \strpos( $endpoint, '?' ) ? '?' : '&' ) . \http_build_query( $params );

		$headers = [
			'Accept: text/event-stream',
			'Cache-Control: no-cache',
		];
		if ( '' !== $this->auth_username && '' !== $this->auth_password ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth.
			$headers[] = 'Authorization: Basic ' . \base64_encode( $this->auth_username . ':' . $this->auth_password );
		} elseif ( '' !== $this->auth_token ) {
			$headers[] = 'Authorization: Bearer ' . $this->auth_token;
		}

		$ch = \curl_init();
		if ( false === $ch ) {
			$this->last_error = 'curl_init failed';
			$this->increase_backoff();
			return false;
		}

		\curl_setopt_array(
			$ch,
			[
				\CURLOPT_URL            => $endpoint,
				\CURLOPT_RETURNTRANSFER => false,
				\CURLOPT_FOLLOWLOCATION => false,
				\CURLOPT_TIMEOUT        => 0,
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
		$this->buffer          = '';
		$this->current_event   = [ 'event' => '', 'data' => '' ];
		$this->last_event_time = $now;
		$this->connected       = true;
		$this->last_error      = null;
		$this->last_http_code  = null;
		$this->handle          = $ch;
		$this->last_attempt    = $now;
		$this->slot            = null;

		$result = \curl_multi_add_handle( $this->multi, $ch );
		if ( 0 !== $result ) {
			$this->last_error = "curl_multi_add_handle failed: {$result}";
			\curl_close( $ch );
			$this->handle    = null;
			$this->connected = false;
			$this->increase_backoff();
			return false;
		}

		$this->clear_heartbeat_status();
		$this->update_connection_status( 'connecting', null, null, $this->current_backoff );
		return true;
	}

	/**
	 * Detach the active handle from the multi + close it. Idempotent.
	 *
	 * Order matters: curl_multi_remove_handle() MUST run before curl_close().
	 */
	private function detach_handle(): void {
		if ( ! ( $this->handle instanceof \CurlHandle ) ) {
			return;
		}
		if ( null !== $this->multi ) {
			@\curl_multi_remove_handle( $this->multi, $this->handle );
		}
		@\curl_close( $this->handle );
		$this->handle    = null;
		$this->connected = false;
	}

	/**
	 * Force-disconnect. Called externally (by StreamMerger::remove_node, by
	 * the GUI's Delete Node action).
	 */
	public function disconnect(): void {
		$this->detach_handle();
		$this->update_connection_status( 'disconnected', $this->last_http_code, $this->last_error, $this->current_backoff );
		$this->clear_heartbeat_status();
	}

	// =========================================================================
	// EventFramework callbacks (cURL multi)
	// =========================================================================

	/**
	 * CURLOPT_WRITEFUNCTION callback. Returns bytes-consumed or 0 to abort.
	 */
	public function on_curl_data( \CurlHandle $handle, string $bytes ): int {
		if ( $handle !== $this->handle ) {
			return \strlen( $bytes );
		}
		$len = \strlen( $bytes );
		if ( 0 === $len ) {
			return 0;
		}
		if ( null === $this->last_http_code ) {
			$code                 = (int) \curl_getinfo( $handle, CURLINFO_HTTP_CODE );
			$this->last_http_code = $code > 0 ? $code : null;
		}
		return $this->process_sse_chunk( $bytes ) ? $len : 0;
	}

	/**
	 * Called by EventFramework::drain_curl_multi() when curl_multi_info_read
	 * returns CURLMSG_DONE for this RemoteSource's multi.
	 */
	public function on_curl_message( array $info ): void {
		if ( ! isset( $info['msg'] ) || \CURLMSG_DONE !== $info['msg'] ) {
			return;
		}
		$handle = $info['handle'] ?? null;
		if ( ! ( $handle instanceof \CurlHandle ) || $handle !== $this->handle ) {
			// Stale or foreign handle — best-effort cleanup.
			if ( $handle instanceof \CurlHandle ) {
				if ( null !== $this->multi ) {
					@\curl_multi_remove_handle( $this->multi, $handle );
				}
				@\curl_close( $handle );
			}
			return;
		}

		$result    = (int) ( $info['result'] ?? \CURLE_OK );
		$http_code = (int) \curl_getinfo( $handle, \CURLINFO_HTTP_CODE );
		$err       = \curl_error( $handle );
		$this->last_http_code = $http_code > 0 ? $http_code : null;

		if ( \CURLE_OK !== $result ) {
			$this->last_error = "cURL error {$result}: {$err}";
			Core::print_less_often( "RemoteSource[{$this->server_id}]: disconnected: {$this->last_error}" );
		} elseif ( 200 !== $http_code && 0 !== $http_code ) {
			$this->last_error = "HTTP {$http_code}";
			Core::print_less_often( "RemoteSource[{$this->server_id}]: HTTP {$http_code}" );
		} else {
			$this->last_error = 'Connection closed by server';
		}
		$this->set_state(
			'DISCONNECTED',
			[ 'server' => $this->server_id, 'error' => $this->last_error, 'http' => $http_code ]
		);

		$this->detach_handle();
		$this->increase_backoff();
		$this->update_connection_status( 'disconnected', $this->last_http_code, $this->last_error, $this->current_backoff );
		$this->clear_heartbeat_status();
	}

	private function increase_backoff(): void {
		$this->current_backoff = \min( self::MAX_BACKOFF, \max( self::INITIAL_BACKOFF, $this->current_backoff * 2 ) );
	}

	// =========================================================================
	// SSE parsing
	// =========================================================================

	/**
	 * Parse a chunk of SSE bytes off the buffer. Returns false on overflow.
	 *
	 * Public so tests can drive the parser without setting up cURL.
	 */
	public function process_sse_chunk( string $bytes ): bool {
		$this->buffer .= $bytes;

		if ( \strlen( $this->buffer ) > self::MAX_BUFFER_SIZE ) {
			$this->last_error = 'Buffer overflow (no newline in ' . self::MAX_BUFFER_SIZE . ' bytes)';
			$this->buffer     = '';
			$this->connected  = false;
			return false;
		}

		// The parser runs regardless of `$connected` so this method works as
		// the test-path entry point (fixture drives chunks without going
		// through curl). Production gates parsing by checking $connected
		// inside parse_sse_line's per-line loop — see the inner `&& $this->connected` guard.
		while ( false !== ( $newline_pos = \strpos( $this->buffer, "\n" ) ) ) {
			$line          = \substr( $this->buffer, 0, $newline_pos );
			$this->buffer  = \substr( $this->buffer, $newline_pos + 1 );
			$line          = \rtrim( $line, "\r" );
			if ( ! $this->parse_sse_line( $line ) ) {
				return false;
			}
		}
		return true;
	}

	private function parse_sse_line( string $line ): bool {
		if ( '' === $line ) {
			return $this->dispatch_event();
		}

		$colon_pos = \strpos( $line, ':' );
		if ( false === $colon_pos || 0 === $colon_pos ) {
			// Comment line (`: keepalive`) — ignore per SSE spec.
			return true;
		}

		$field = \substr( $line, 0, $colon_pos );
		$value = \substr( $line, $colon_pos + 1 );
		if ( isset( $value[0] ) && ' ' === $value[0] ) {
			$value = \substr( $value, 1 );
		}

		switch ( $field ) {
			case 'event':
				$this->current_event['event'] = $value;
				break;
			case 'data':
				if ( '' !== $this->current_event['data'] ) {
					$this->current_event['data'] .= "\n";
				}
				$this->current_event['data'] .= $value;
				if ( \strlen( $this->current_event['data'] ) > self::MAX_EVENT_SIZE ) {
					$this->last_error    = 'Event data overflow (' . self::MAX_EVENT_SIZE . ' bytes)';
					$this->current_event = [ 'event' => '', 'data' => '' ];
					$this->connected     = false;
					return false;
				}
				break;
		}
		return true;
	}

	private function dispatch_event(): bool {
		$type     = $this->current_event['event'];
		$raw_data = $this->current_event['data'];
		$this->current_event = [ 'event' => '', 'data' => '' ];

		// Default `event:` (no field at all) is allowed for the test path.
		if ( '' === $type && '' === $raw_data ) {
			return true;
		}

		// Any successful event receipt resets backoff and refreshes liveness.
		$this->current_backoff = self::INITIAL_BACKOFF;
		$this->last_event_time = (float) ( Core::$now ?: \microtime( true ) );

		$decoded = \json_decode( $raw_data, true, 16 );

		// Unified `/messages/stream` wire format: every line is a `msg` event
		// carrying a 7-field Message envelope. The substrate emits no other
		// event types, so anything else is silently ignored.
		if ( 'msg' === $type && \is_array( $decoded ) && \count( $decoded ) === 7 ) {
			return $this->dispatch_msg_envelope( $decoded );
		}

		return true;
	}

	/**
	 * Dispatch a parsed `msg`-event Message envelope (7-field array).
	 *
	 * Handles three envelope shapes:
	 *   * `connected` (KEY = 'connected', VALUE = `{pid, slot, ...}`) —
	 *     capture slot, mark connected, do NOT forward to sink.
	 *   * Firehose entry (KEY = rid, VALUE = `{k, ts, ...}`) — back-fill
	 *     rid from KEY when entry doesn't carry it, then forward.
	 *   * Anything else — drop silently.
	 *
	 * Per-envelope position from `ID = "seg:off"` (Consumer stamps at emit
	 * time). Substrate guarantees this on every line of a log subscription.
	 *
	 * @param array<int,mixed> $envelope 7-field Message array.
	 */
	private function dispatch_msg_envelope( array $envelope ): bool {
		$id    = (string) $envelope[ Message::ID ];
		$key   = (string) $envelope[ Message::KEY ];
		$value = $envelope[ Message::VALUE ];

		// Position from envelope ID — `{segment_id}:{offset}` shape. Empty ID
		// (e.g. the connected envelope, which fires BEFORE Consumer stamps
		// anything) is a no-op. The numeric-check is defensive: `(int)` on a
		// non-numeric string silently returns 0, which would reset our cursor
		// to the start of the segment.
		if ( '' !== $id ) {
			$colon = \strpos( $id, ':' );
			if ( false !== $colon ) {
				$seg_str = \substr( $id, 0, $colon );
				$off_str = \substr( $id, $colon + 1 );
				if ( \ctype_digit( $seg_str ) && \ctype_digit( $off_str ) ) {
					$this->position = [
						'segment_id' => (int) $seg_str,
						'offset'     => (int) $off_str,
					];
				}
			}
		}

		// `connected` envelope — substrate's first emission per stream. Mirrors
		// the legacy `connected`-event handler (slot capture + heartbeat-status
		// recording) but does NOT forward to the sink.
		if ( 'connected' === $key && \is_array( $value ) && isset( $value['slot'] ) ) {
			$this->slot = (int) $value['slot'];
			$this->record_successful_heartbeat();
			$this->last_heartbeat = (int) Core::$now;
			$this->update_connection_status( 'connected', $this->last_http_code, '' );
			return true;
		}

		// Anything else with an entry-shaped VALUE → forward. The legacy
		// transform_line shape (`{k, ts, ...}`) is what RequestBuilder writes
		// at storage time, so envelope[VALUE] IS the entry dict.
		if ( \is_array( $value ) && isset( $value['k'] ) ) {
			// Back-fill rid from KEY when the entry doesn't carry it. Matches
			// the producer convention RequestBuilder uses (KEY=rid for the
			// completed:tee fan-out into firehose.log).
			if ( ! isset( $value['rid'] ) && '' !== $key ) {
				$value['rid'] = $key;
			}
			$this->forward_entry( $value );
		}
		return true;
	}

	/**
	 * Validate, stamp, encode, filter, PIPE_BUF-guard, and sink an `entry`.
	 */
	private function forward_entry( array $data ): void {
		if ( ! isset( $data['k'] ) || ! \is_string( $data['k'] ) ) {
			return;
		}
		if ( ! isset( $data['ts'] ) || ! \is_numeric( $data['ts'] ) ) {
			return;
		}
		$data['_source'] = $this->server_id;

		$line = \wp_json_encode( $data );
		if ( false === $line ) {
			return;
		}

		if ( \strlen( $line ) > self::MAX_LINE_BYTES ) {
			Core::print_less_often( "RemoteSource[{$this->server_id}]: dropping entry > " . self::MAX_LINE_BYTES . ' bytes' );
			$this->set_state(
				'DROPPED',
				[ 'server' => $this->server_id, 'reason' => 'oversize', 'size' => \strlen( $line ) ]
			);
			return;
		}

		// 3-arg ingest filter (line, server_id, partition). Filters can return
		// null/false to drop. The k:"job" -> k:"remote_job" rewrite registered
		// statically by StreamMerger::register_remote_job_rewrite_filter() fires
		// here — same callsite as before the refactor, just nested one node down.
		$line = \apply_filters( 'newspack_nodes/aggregator_ingest_line', $line, $this->server_id, $this->partition );
		if ( null === $line || false === $line || '' === $line ) {
			return;
		}
		if ( ! \is_string( $line ) ) {
			return;
		}
		if ( \strlen( $line ) > self::MAX_LINE_BYTES ) {
			Core::print_less_often( "RemoteSource[{$this->server_id}]: post-filter entry > " . self::MAX_LINE_BYTES . ' bytes' );
			return;
		}

		$decoded = \json_decode( $line, true, 64 );
		if ( ! \is_array( $decoded ) ) {
			return;
		}
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::TO ]        = \is_string( $this->target ) ? $this->target : '';
		$msg[ Message::KEY ]       = (string) ( $data['rid'] ?? '' );
		$msg[ Message::VALUE ]     = $decoded;
		$this->sink?->fill( $msg );
	}

	// =========================================================================
	// Stale check + heartbeat POST
	// =========================================================================

	private function check_stale(): void {
		if ( ! $this->connected || ! ( $this->handle instanceof \CurlHandle ) ) {
			return;
		}
		$now     = (float) ( Core::$now ?: \microtime( true ) );
		$elapsed = $now - $this->last_event_time;
		if ( $elapsed <= self::HEARTBEAT_TIMEOUT ) {
			return;
		}
		$stale_seconds    = (int) $elapsed;
		$this->last_error = "Stale connection (no events for {$stale_seconds}s)";
		Core::print_less_often( "RemoteSource[{$this->server_id}]: stale ({$stale_seconds}s) — reconnecting" );

		$this->detach_handle();
		$this->increase_backoff();
		$this->update_connection_status( 'disconnected', $this->last_http_code, $this->last_error, $this->current_backoff );
		$this->clear_heartbeat_status();
	}

	private function maybe_send_heartbeat(): void {
		if ( ! $this->connected ) {
			return;
		}
		if ( null === $this->slot || $this->slot < 0 ) {
			return;
		}
		$now = (int) Core::$now;
		if ( $now - $this->last_heartbeat < self::HEARTBEAT_INTERVAL ) {
			return;
		}
		$this->last_heartbeat = $now;

		$headers = [];
		if ( '' !== $this->auth_username && '' !== $this->auth_password ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth.
			$headers['Authorization'] = 'Basic ' . \base64_encode( $this->auth_username . ':' . $this->auth_password );
		} elseif ( '' !== $this->auth_token ) {
			$headers['Authorization'] = 'Bearer ' . $this->auth_token;
		}

		$endpoint = $this->url . '/wp-json/newspack-nodes/v1/firehose/heartbeat';
		if ( $this->require_https && \stripos( $endpoint, 'https://' ) !== 0 ) {
			$this->last_error = 'heartbeat endpoint not HTTPS';
			return;
		}

		if ( ! \function_exists( 'wp_remote_post' ) ) {
			return;
		}

		$start    = \microtime( true );
		$response = @\wp_remote_post(
			$endpoint,
			[
				'headers'             => $headers,
				'body'                => [
					'slot'       => $this->slot,
					'aggregator' => true,
					'partition'  => $this->partition,
				],
				// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Cross-server heartbeat.
				'timeout'             => 10,
				'sslverify'           => $this->verify_ssl,
				'redirection'         => 0,
				'limit_response_size' => 1048576,
			]
		);
		$rtt = ( \microtime( true ) - $start ) * 1000;

		$this->update_heartbeat_status( $response, $rtt, $now );
	}

	// =========================================================================
	// Memcache status writers (Aggregator dashboard)
	// =========================================================================

	private function status_key(): string {
		return "aggregator_status:{$this->server_id}:p{$this->partition}";
	}

	private function cache(): ?Cache_Interface {
		if ( null !== $this->cache ) {
			return $this->cache->is_available() ? $this->cache : null;
		}
		$this->cache = Memcached_Cache::from_substrate_config();
		return $this->cache->is_available() ? $this->cache : null;
	}

	private function update_connection_status( string $status, ?int $http_code = null, ?string $error = null, ?int $backoff = null ): void {
		$cache = $this->cache();
		if ( null === $cache ) {
			return;
		}
		$key      = $this->status_key();
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

	private function record_successful_heartbeat(): void {
		$cache = $this->cache();
		if ( null === $cache ) {
			return;
		}
		$key      = $this->status_key();
		$existing = $cache->get( $key );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$now  = (int) Core::$now;
		$data = [
			'last_heartbeat_sent'            => $now,
			'last_heartbeat_response'        => $now,
			'last_heartbeat_rtt'             => 0,
			'last_heartbeat_response_status' => 'success',
			'last_heartbeat_error'           => null,
		];
		$cache->set( $key, \array_merge( $existing, $data ), self::STATUS_TTL );
	}

	private function update_sse_heartbeat( int $timestamp ): void {
		$cache = $this->cache();
		if ( null === $cache ) {
			return;
		}
		$key      = $this->status_key();
		$existing = $cache->get( $key );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$cache->set( $key, \array_merge( $existing, [ 'last_sse_heartbeat' => $timestamp ] ), self::STATUS_TTL );
	}

	private function clear_heartbeat_status(): void {
		$cache = $this->cache();
		if ( null === $cache ) {
			return;
		}
		$key      = $this->status_key();
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

	private function update_heartbeat_status( $response, float $rtt, int $sent_at ): void {
		$cache = $this->cache();
		if ( null === $cache ) {
			return;
		}
		$key      = $this->status_key();
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

	// =========================================================================
	// Test introspection
	// =========================================================================

	public function test_get_handle(): ?\CurlHandle {
		return $this->handle;
	}

	public function get_last_http_code(): ?int {
		return $this->last_http_code;
	}

	public function get_last_error(): ?string {
		return $this->last_error;
	}

	public function get_backoff(): int {
		return $this->current_backoff;
	}

	public function get_slot(): ?int {
		return $this->slot;
	}

	public function is_connected(): bool {
		return $this->connected;
	}

	// =========================================================================
	// Lifecycle
	// =========================================================================

	public function remove_node(): void {
		$this->disconnect();
		if ( null !== $this->multi ) {
			EventFramework::instance()->unregister_curl_handle( $this );
			@\curl_multi_close( $this->multi );
			$this->multi = null;
		}
		parent::remove_node();
	}

	// =========================================================================
	// Schema
	// =========================================================================

	public static function node_schema(): array {
		return [
			'category'    => 'I/O',
			'description' => 'One SSE-pulled spoke firehose. Instantiated by StreamMerger from ServerRegistry.',
			'ctor'        => [
				[ 'name' => 'server_id',     'type' => 'string', 'required' => true ],
				[ 'name' => 'url',           'type' => 'string', 'required' => true ],
				[ 'name' => 'auth_username', 'type' => 'string' ],
				[ 'name' => 'auth_password', 'type' => 'string' ],
				[ 'name' => 'auth_token',    'type' => 'string' ],
				[ 'name' => 'partition',     'type' => 'int',    'default' => 0 ],
			],
			'verbs'       => [],
			'requests'    => [],
		];
	}
}
