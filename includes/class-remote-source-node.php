<?php
/**
 * RemoteSource
 *
 * One SSE-pulled spoke topic, modeled as a first-class graph node.
 *
 * Each RemoteSource owns exactly one cURL multi handle (registered with the
 * substrate's EventFramework), one cURL easy handle (the SSE GET), one
 * in-memory cursor `{segment_id, offset}`, and one SSE connection's worth of
 * parser state. The class is a *source*: `fill()` is a no-op (it doesn't
 * receive messages); it generates Messages from the SSE feed it parses and
 * forwards them to its sink (typically a Topic node on a hub).
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
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Node_Names;

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

class Remote_Source_Node extends Node {
	use \Newspack_Nodes\Schema_Reflection;

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

	protected string $server_id     = '';
	protected string $url           = '';
	protected string $auth_username = '';
	protected string $auth_password = '';
	protected string $auth_token    = '';
	protected string $remote_topic  = '';
	protected int    $partition     = 0;

	private bool $verify_ssl    = true;
	private bool $require_https = true;

	/** Owned multi handle, registered with the substrate's EventFramework. */
	private ?\CurlMultiHandle $multi = null;

	/** Active easy handle when connected, null otherwise. */
	private ?\CurlHandle $handle = null;

	private string  $buffer        = '';
	/** @var array{event:string, data:string} Current SSE event accumulator. */
	private array   $current_event = [ 'event' => '', 'data' => '' ];
	private ?int    $slot          = null;
	/** @var array{segment_id:int, offset:int} Read cursor. */
	private array   $position      = [ 'segment_id' => 0, 'offset' => 0 ];
	private float   $last_event_time = 0.0;
	private int     $current_backoff = self::INITIAL_BACKOFF;
	private float   $last_attempt    = 0.0;
	private bool    $connected       = false;
	private ?string $last_error     = null;
	private ?int    $last_http_code  = null;
	private int     $last_heartbeat  = 0;

	/**
	 * Tachikoma-parity: no-arg ctor. Positional config arrives via arguments().
	 * Credentials and URL come from the ServerRegistry entry that StreamMerger
	 * looked up — RemoteSource doesn't read the registry itself.
	 */
	public function __construct() {
		parent::__construct();
	}

	/**
	 * Store the raw string, parse positional tokens via parse_schema_args()
	 * (server_id / url / auth_username / auth_password / auth_token / remote_topic /
	 * partition), then rtrim the URL and clamp partition to >= 0.
	 *
	 * Note: this positional-string path can't represent middle-empty values
	 * (whitespace tokenization collapses empties). Production callers
	 * (StreamMerger::add_remote) use `configure()` instead, which sets each
	 * field independently and writes a redacted summary string for
	 * `dump_config()`. This setter is exercised primarily by tests and any
	 * fully-populated TSL-style line.
	 *
	 * @param string|null $args
	 * @return string
	 */
	public function arguments( ?string $args = null ): string {
		if ( null === $args ) {
			return parent::arguments();
		}
		$result = parent::arguments( $args );
		if ( '' === $args ) {
			return $result;
		}
		$this->parse_schema_args( $args );
		$this->url       = \rtrim( $this->url, '/' );
		$this->partition = \max( 0, $this->partition );
		return $result;
	}

	/**
	 * Programmatic configuration entry point for StreamMerger. Sets every
	 * field directly (so middle-empty values round-trip correctly) and
	 * writes a redacted summary string into `$this->arguments` so
	 * `dump_config()` reflects the configured state without disclosing
	 * secrets.
	 *
	 * @param string $server_id     Identifier — also the memcache status key fragment.
	 * @param string $url           Base URL (no trailing slash).
	 * @param string $auth_username Application-Password user (Basic auth).
	 * @param string $auth_password Application-Password secret.
	 * @param string $auth_token    Optional Bearer token fallback.
	 * @param string $remote_topic  Topic for the subscribe / heartbeat URL params.
	 * @param int    $partition     Partition for the subscribe / heartbeat URL params.
	 */
	public function configure(
		string $server_id,
		string $url,
		string $auth_username = '',
		string $auth_password = '',
		string $auth_token    = '',
		string $remote_topic  = '',
		int $partition        = 0
	): void {
		$this->server_id     = $server_id;
		$this->url           = \rtrim( $url, '/' );
		$this->auth_username = $auth_username;
		$this->auth_password = $auth_password;
		$this->auth_token    = $auth_token;
		$this->remote_topic  = $remote_topic;
		$this->partition     = \max( 0, $partition );
		// Redacted summary string for dump_config — never echoes secrets.
		$this->arguments = \implode( ' ', [
			$server_id,
			$this->url,
			$auth_username,
			'[REDACTED]',
			'[REDACTED]',
			$this->remote_topic,
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

	// dump_node() secret redaction is handled by the base Node (auth_password / auth_token match its patterns).

	// =========================================================================
	// Configuration / DI
	// =========================================================================

	public function server_id(): string {
		return $this->server_id;
	}

	public function url(): string {
		return $this->url;
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
	 * @return array<string, mixed>
	 */
	public function current_status(): array {
		return [
			'connected'        => $this->connected,
			'last_error'       => $this->last_error,
			'last_http_code'   => $this->last_http_code,
			'position'         => $this->position,
			'last_event_age_s' => $this->last_event_time > 0
				? (int) ( ( Core::$now ?: \microtime( true ) ) - $this->last_event_time )
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
	private function ensure_multi(): \CurlMultiHandle {
		if ( null !== $this->multi ) {
			return $this->multi;
		}
		$multi       = \curl_multi_init();
		$this->multi = $multi;
		Event_Framework::instance()->register_curl_handle( $this, $multi );
		return $multi;
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

		$now = Core::$now ?: \microtime( true );
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

		$multi = $this->ensure_multi();

		// Subscription shape `topic.pN` lands in \Newspack_Nodes\Sse_Slot_Pool's per-partition
		// aggregator pool (60s TTL) via SSE_Out's
		// `subscription_partition()` helper — no `aggregator=1` flag needed.
		$endpoint = $this->url . '/wp-json/newspack-nodes/v1/messages/stream';
		$params   = [
			'subscribe' => "{$this->remote_topic}.p{$this->partition}",
		];
		if ( $this->position['segment_id'] > 0 || $this->position['offset'] > 0 ) {
			// Substrate's parse_positions expects a JSON-encoded object keyed
			// by subscription name → partition index → `{seg, off}`. The same
			// shape the dashboards use; only one entry here because RemoteSource
			// runs one connection per partition.
			$params['positions'] = (string) \wp_json_encode(
				[
					$this->remote_topic => [
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
				\CURLOPT_WRITEFUNCTION  => function ( \CurlHandle $h, string $bytes ): int {
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

		$result = \curl_multi_add_handle( $multi, $ch );
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
			$code                 = \curl_getinfo( $handle, CURLINFO_HTTP_CODE );
			$this->last_http_code = $code > 0 ? $code : null;
			// First bytes through with a 200 status means the connection
			// is alive and serving data — any `last_error` from a prior
			// failed attempt is stale and shouldn't keep haunting the
			// dashboard. Clear it on the first successful chunk so the
			// admin UI shows the current connection state, not history.
			if ( 200 === $this->last_http_code ) {
				$this->last_error = null;
			}
		}
		return $this->process_sse_chunk( $bytes ) ? $len : 0;
	}

	/**
	 * Called by EventFramework::drain_curl_multi() when curl_multi_info_read
	 * returns CURLMSG_DONE for this RemoteSource's multi.
	 * @param array{msg?:int, handle?:\CurlHandle, result?:int} $info
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

		$result    = $info['result'] ?? \CURLE_OK;
		$http_code = \curl_getinfo( $handle, \CURLINFO_HTTP_CODE );
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
		$this->last_event_time = Core::$now ?: \microtime( true );

		$decoded = \json_decode( $raw_data, true, 16 );

		// The spoke's messages-stream emits periodic `heartbeat` events when a
		// stream is idle-but-live. Record the receipt so the aggregator
		// dashboard's "Server HB" reflects spoke liveness (not forwarded).
		if ( 'heartbeat' === $type ) {
			$this->record_sse_heartbeat();
			return true;
		}

		// `/messages/stream` data lines are `msg` events carrying a 7-field
		// Message envelope; any other event type is silently ignored.
		if ( 'msg' === $type && \is_array( $decoded ) && \count( $decoded ) === 7 ) {
			// Positional 7-field Message JSON — array_values re-keys 0..6 (no-op
			// on the already-positional decode) so the int-keyed envelope shape
			// holds for the dispatch path.
			return $this->dispatch_msg_envelope( \array_values( $decoded ) );
		}

		return true;
	}

	/**
	 * Dispatch a parsed `msg`-event Message envelope (7-field array).
	 *
	 * RemoteSource is generic transport — it pulls whatever a spoke publishes
	 * on the subscribed log and hands every envelope to the sink. The only
	 * envelope it inspects is the substrate's bookkeeping `connected` frame
	 * (KEY = 'connected', VALUE = `{slot, ...}`), which feeds the local
	 * slot/heartbeat state and is NOT forwarded. Everything else flows
	 * through unchanged; payload-shape decisions belong to downstream nodes
	 * (StreamMerger, application JobRouter, etc.).
	 *
	 * Per-envelope position rides `ID = "seg:off"` (Consumer stamps at emit).
	 *
	 * @param array<int,mixed> $envelope 7-field Message array.
	 */
	private function dispatch_msg_envelope( array $envelope ): bool {
		$id_raw  = $envelope[ Message::ID ];
		$key_raw = $envelope[ Message::KEY ];
		$id      = \is_scalar( $id_raw ) ? (string) $id_raw : '';
		$key     = \is_scalar( $key_raw ) ? (string) $key_raw : '';
		$value   = $envelope[ Message::VALUE ];

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

		// `connected` envelope is the substrate's bookkeeping handshake —
		// capture slot, mark connected, do NOT forward.
		if ( 'connected' === $key && \is_array( $value ) && isset( $value['slot'] ) ) {
			$slot_raw   = $value['slot'];
			$this->slot = \is_scalar( $slot_raw ) ? (int) $slot_raw : 0;
			$this->record_successful_heartbeat();
			$this->last_heartbeat = (int) Core::$now;
			$this->update_connection_status( 'connected', $this->last_http_code, '' );
			return true;
		}

		// Pass-through. Forward the whole envelope unchanged so downstream
		// gets exactly what the spoke published — no shape assumptions, no
		// VALUE rewriting.
		$this->forward_envelope( $envelope );
		return true;
	}

	/**
	 * Pass an incoming Message envelope to the sink — preserving TYPE,
	 * KEY, and VALUE from the spoke verbatim. RemoteSource is generic
	 * cross-server transport, so it does NOT validate, rewrite, or peek
	 * inside VALUE for any shape-specific fields. Three side-effects of
	 * being a hub-side aggregator are layered on top:
	 *
	 *   * `_source` attribution stamped into VALUE (when VALUE is a dict
	 *     — the legacy aggregator convention).
	 *   * `newspack_nodes/aggregator_ingest_line` filter chain (the hub's
	 *     opportunity to rewrite or drop; e.g. StreamMerger's
	 *     `k:"job"` → `k:"remote_job"` rewrite). String-line input for
	 *     backward compat with the legacy filter contract; bypassed when
	 *     VALUE isn't a dict.
	 *   * `MAX_LINE_BYTES` guard so an oversized post-filter line can't
	 *     blow PIPE_BUF when the sink hands off to a Partition write.
	 *
	 * @param array<int,mixed> $envelope 7-field Message array.
	 */
	private function forward_envelope( array $envelope ): void {
		if ( null === $this->sink ) {
			throw new \RuntimeException( 'Remote_Source::forward_envelope requires a wired sink' );
		}
		$value   = $envelope[ Message::VALUE ];
		$key_raw = $envelope[ Message::KEY ];

		if ( \is_array( $value ) ) {
			$value['_source'] = $this->server_id;
			$line             = \wp_json_encode( $value );
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
			$line = \apply_filters( 'newspack_nodes/aggregator_ingest_line', $line, $this->server_id, $this->partition );
			if ( null === $line || false === $line || '' === $line || ! \is_string( $line ) ) {
				return;
			}
			if ( \strlen( $line ) > self::MAX_LINE_BYTES ) {
				Core::print_less_often( "RemoteSource[{$this->server_id}]: post-filter entry > " . self::MAX_LINE_BYTES . ' bytes' );
				return;
			}
			$value = \json_decode( $line, true, 64 );
			if ( ! \is_array( $value ) ) {
				return;
			}
		}

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = $envelope[ Message::TYPE ];
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::TO ]        = \is_string( $this->target ) ? $this->target : '';
		$msg[ Message::KEY ]       = \is_scalar( $key_raw ) ? (string) $key_raw : '';
		$msg[ Message::VALUE ]     = $value;
		++$this->counter;
		$this->sink->fill( $msg );
	}

	// =========================================================================
	// Stale check + heartbeat POST
	// =========================================================================

	private function check_stale(): void {
		if ( ! $this->connected || ! ( $this->handle instanceof \CurlHandle ) ) {
			return;
		}
		$now     = Core::$now ?: \microtime( true );
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

		// M6 deleted the legacy `/firehose/heartbeat` REST route in favor
		// of the substrate's `workers.heartbeat` CommandInterpreter verb
		// dispatched via `/command`. Same wire format the dashboard JS
		// (the runtime `_sse` node) uses for its own slot keep-alive.
		$endpoint = $this->url . '/wp-json/newspack-nodes/v1/command';
		if ( $this->require_https && \stripos( $endpoint, 'https://' ) !== 0 ) {
			$this->last_error = 'heartbeat endpoint not HTTPS';
			return;
		}

		if ( ! \function_exists( 'wp_remote_post' ) ) {
			return;
		}

		// Body is a single packed Message (JSONL line) dispatching the
		// substrate's `workers.heartbeat` verb. TYPE=TM_COMMAND, FROM=`_http`,
		// TO=`workers`, VALUE = the LIVE structured `{name, arguments, payload}`
		// array (NOT a separately wp_json_encode'd string). Content-Type is
		// text/plain because WP REST 400s a JSONL body sent as
		// application/json. Matches the browser CommandClient
		// (src/runtime/command_client.js) — the same wire the dashboard JS
		// uses for its own slot keep-alive.
		$headers['Content-Type'] = Remote_Manager::COMMAND_CONTENT_TYPE;

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_COMMAND;
		$msg[ Message::FROM ]  = Node_Names::HTTP;
		$msg[ Message::TO ]    = 'workers';
		// Workers.heartbeat parses positional `<slot> <ttl> <partition>`.
		// ttl must outlive HEARTBEAT_INTERVAL — only the client refreshes the slot now.
		$msg[ Message::VALUE ] = [
			'name'      => 'heartbeat',
			'arguments' => $this->slot . ' ' . ( self::HEARTBEAT_INTERVAL * 4 ) . ' ' . $this->partition,
		];
		$body = Message::packed( $msg );

		$start    = \microtime( true );
		$response = @\wp_remote_post(
			$endpoint,
			[
				'headers'             => $headers,
				'body'                => $body,
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

	private function update_connection_status( string $status, ?int $http_code = null, ?string $error = null, ?int $backoff = null ): void {
		$cache = Core::$memd;
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
		$cache = Core::$memd;
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

	private function record_sse_heartbeat(): void {
		$cache = Core::$memd;
		if ( null === $cache ) {
			return;
		}
		$key      = $this->status_key();
		$existing = $cache->get( $key );
		if ( ! \is_array( $existing ) ) {
			$existing = [];
		}
		$cache->set( $key, \array_merge( $existing, [ 'last_sse_heartbeat' => (int) Core::$now ] ), self::STATUS_TTL );
	}

	private function clear_heartbeat_status(): void {
		$cache = Core::$memd;
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

	/** @param mixed $response wp_remote_post response (array or \WP_Error; shape not trusted). */
	private function update_heartbeat_status( $response, float $rtt, int $sent_at ): void {
		$cache = Core::$memd;
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
			$resp     = $response['response'] ?? null;
			$code_raw = \is_array( $resp ) ? ( $resp['code'] ?? null ) : null;
			$code     = \is_scalar( $code_raw ) ? (int) $code_raw : 0;
			$body_raw = $response['body'] ?? null;
			$body     = \is_scalar( $body_raw ) ? \json_decode( (string) $body_raw, true, 16 ) : null;
			if ( 200 !== $code ) {
				$status = 'error';
				$error  = "HTTP {$code}";
			} elseif ( \is_array( $body ) && isset( $body['success'] ) && false === $body['success'] ) {
				$status  = 'slot_expired';
				$err_raw = $body['error'] ?? 'Slot no longer valid';
				$error   = \is_scalar( $err_raw ) ? (string) $err_raw : 'Slot no longer valid';
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
		$multi = $this->multi;
		if ( null !== $multi ) {
			Event_Framework::instance()->unregister_curl_handle( $this );
			@\curl_multi_close( $multi );
			$this->multi = null;
		}
		parent::remove_node();
	}

	// =========================================================================
	// Schema
	// =========================================================================

	public static function node_schema(): array {
		return [
			'category'     => 'I/O',
			'description'  => 'One SSE-pulled spoke topic. Instantiated by StreamMerger from ServerRegistry.',
			'arguments'         => [
				[ 'name' => 'server_id',     'type' => 'string', 'required' => true ],
				[ 'name' => 'url',           'type' => 'string', 'required' => true ],
				[ 'name' => 'auth_username', 'type' => 'string' ],
				[ 'name' => 'auth_password', 'type' => 'string' ],
				[ 'name' => 'auth_token',    'type' => 'string' ],
				[ 'name' => 'remote_topic',  'type' => 'string' ],
				[ 'name' => 'partition',     'type' => 'int',    'default' => 0 ],
			],
			'commands'        => [
				[
					'name'        => 'set_verify_ssl',
					'description' => 'Toggle SSL certificate verification on outbound SSE connections.',
					'args'        => [
						[ 'name' => 'verify', 'type' => 'bool', 'required' => true, 'default' => '<config:aggregator_verify_ssl>' ],
					],
				],
				[
					'name'        => 'set_require_https',
					'description' => 'Refuse to connect to non-HTTPS remote URLs.',
					'args'        => [
						[ 'name' => 'require', 'type' => 'bool', 'required' => true, 'default' => '<config:aggregator_require_https>' ],
					],
				],
			],
			'requests'     => [],
			'accepts_fill' => false,
		];
	}
}
