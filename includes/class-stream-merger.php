<?php
/**
 * Stream Merger
 *
 * Orchestrator for hub-side aggregator. Instantiates one RemoteSource node
 * per ServerRegistry-enabled entry, holds a reference to each, drives their
 * periodic ticks, and owns the shared offsetlog Partition that persists
 * every remote's `{segment_id, offset}` cursor across worker restarts.
 *
 * RemoteSource owns the actual cURL lifecycle, SSE parsing, and message
 * forwarding (each pipes directly to the same sink StreamMerger targets,
 * typically `firehose:topic`). StreamMerger does NOT see the data path;
 * it's pure lifecycle + commit.
 *
 * Direction of refs: StreamMerger → RemoteSource (one-way). RemoteSource
 * doesn't know about its parent. No patron link — the RemoteSource nodes
 * are first-class graph citizens, visible in the topology console.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\CommandInterpreter;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Partition;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class StreamMerger extends Node {

	/** Offsetlog commit cadence. */
	public const COMMIT_INTERVAL_S = 5;

	// ---- Back-compat constants ---------------------------------------------
	// Per-remote constants now live on RemoteSource (each child enforces its
	// own limits). These aliases keep old call sites (tests, callers) working
	// without rewriting; new code should reference RemoteSource directly.
	public const INITIAL_BACKOFF    = RemoteSource::INITIAL_BACKOFF;
	public const MAX_BACKOFF        = RemoteSource::MAX_BACKOFF;
	public const CONNECT_TIMEOUT    = RemoteSource::CONNECT_TIMEOUT;
	public const HEARTBEAT_TIMEOUT  = RemoteSource::HEARTBEAT_TIMEOUT;
	public const HEARTBEAT_INTERVAL = RemoteSource::HEARTBEAT_INTERVAL;
	public const MAX_BUFFER_SIZE    = RemoteSource::MAX_BUFFER_SIZE;
	public const MAX_EVENT_SIZE     = RemoteSource::MAX_EVENT_SIZE;
	public const MAX_QUEUE_SIZE     = RemoteSource::MAX_QUEUE_SIZE;
	public const MAX_LINE_BYTES     = RemoteSource::MAX_LINE_BYTES;
	public const STATUS_TTL         = RemoteSource::STATUS_TTL;

	/** @var array<string,RemoteSource> Refs to child RemoteSource nodes, keyed by server_id. */
	private array $remote_nodes = [];

	/** @var Partition|null Per-partition offsetlog (one Partition for the whole merger). */
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

	/** @var HealthCheckTick|null Owned sibling — drives aggregator's periodic health-check sweep. */
	private ?HealthCheckTick $health_check = null;

	public function __construct( int $partition = 0 ) {
		$this->partition = \max( 0, $partition );
		$this->arguments = (string) $this->partition;

		// Sibling CommandInterpreter — TSL aggregator topology configures
		// StreamMerger via verbs (set_verify_ssl, set_require_https,
		// load_remotes_from_registry).
		$ci = new CommandInterpreter();
		$ci->patron( $this );
		$ci->commands( self::config_verbs() );
		$this->attach_interpreter( $ci );

		// Owned HealthCheckTick sibling — TIMER-driven, hub-only, never
		// independently configurable. Patron-linked so dump_metadata hides it
		// and so its name tracks the StreamMerger's automatically.
		$this->health_check = new HealthCheckTick();
		$this->health_check->patron( $this );
	}

	/**
	 * Override name() so the owned HealthCheckTick sibling tracks
	 * `{patron_name}:health-check` whenever the patron is named or
	 * renamed (mirrors FlameBuilder::name()'s AutoTuner cascade) and
	 * the Router TIMER hitchhike registers as soon as the node has a
	 * name to register under. The periodic tick is mandatory — every
	 * StreamMerger needs to heartbeat its SSE connections — so this
	 * runs automatically on first-name-set instead of requiring an
	 * explicit TSL `start_periodic_tick` verb invocation.
	 */
	public function name( ?string $name = null ): string {
		$was_named = '' !== $this->name;
		$result    = parent::name( $name );
		if ( null !== $name && '' !== $name ) {
			if ( null !== $this->health_check ) {
				$this->health_check->name( $name . ':health-check' );
			}
			if ( ! $was_named ) {
				$this->start_periodic_tick();
			}
		}
		return $result;
	}

	/**
	 * Tear down children imperatively (no patron link, so no auto-cascade).
	 * RemoteSource children get full remove_node so their cURL multi handles
	 * unregister from the EventFramework.
	 */
	public function remove_node(): void {
		foreach ( $this->remote_nodes as $server_id => $remote ) {
			$remote->remove_node();
		}
		$this->remote_nodes = [];
		if ( null !== $this->health_check && '' !== $this->health_check->name() ) {
			\Newspack_Nodes\Core::unregister_node( $this->health_check->name() );
		}
		$this->health_check = null;
		parent::remove_node();
	}

	/**
	 * One-time hub-side init: register the `k:"job"` -> `k:"remote_job"` rewrite
	 * on `newspack_nodes/aggregator_ingest_line`. Each RemoteSource invokes
	 * this filter from its forward_entry() — the filter is registered globally
	 * once at plugin load, fires wherever it's applied.
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

	public function set_cache( ?Cache_Interface $cache ): void {
		$this->cache = $cache;
		foreach ( $this->remote_nodes as $remote ) {
			$remote->set_cache( $cache );
		}
	}

	public function set_require_https( bool $require ): void {
		if ( ! $require && $this->require_https ) {
			Core::print_less_often( 'StreamMerger: aggregator_require_https=false — SSE traffic permitted on plain HTTP. Credentials WILL travel in cleartext on insecure links.' );
		}
		$this->require_https = $require;
		foreach ( $this->remote_nodes as $remote ) {
			$remote->set_require_https( $require );
		}
	}

	public function set_verify_ssl( bool $verify ): void {
		$this->verify_ssl = $verify;
		foreach ( $this->remote_nodes as $remote ) {
			$remote->set_verify_ssl( $verify );
		}
	}

	// =========================================================================
	// Node interface
	// =========================================================================

	public function fill( array &$message ): void {
		++$this->counter;
		$type = $message[ Message::TYPE ];
		if ( ( $type & Message::TM_REQUEST ) && ! ( $type & Message::TM_RESPONSE ) ) {
			$this->handle_request( $message );
			return;
		}
		if ( ( $type & Message::TM_INFO ) && 'TIMER' === $message[ Message::KEY ] ) {
			$this->tick();
			return;
		}
		$this->sink?->fill( $message );
	}

	private function handle_request( array $message ): void {
		$value = (string) $message[ Message::VALUE ];
		$verb  = \strtoupper( \explode( ' ', \trim( $value ), 2 )[0] );

		if ( 'GET_REMOTES' === $verb ) {
			$remotes = [];
			foreach ( $this->remote_nodes as $server_id => $remote ) {
				$remotes[ (string) $server_id ] = $remote->current_status();
			}
			$payload = [
				'count'   => \count( $remotes ),
				'remotes' => $remotes,
			];
		} else {
			$payload = [ 'error' => "unknown request verb: {$verb}" ];
		}

		$reply                   = Message::new_message();
		$reply[ Message::TYPE ]  = Message::TM_REQUEST | Message::TM_RESPONSE | Message::TM_STRUCT;
		$reply[ Message::FROM ]  = $this->name;
		$reply[ Message::TO ]    = $message[ Message::FROM ];
		$reply[ Message::ID ]    = $message[ Message::ID ];
		$reply[ Message::KEY ]   = $message[ Message::KEY ];
		$reply[ Message::VALUE ] = $payload;
		$this->sink?->fill( $reply );
	}

	public function start_periodic_tick(): void {
		$router = Core::node( '_router' );
		if ( null === $router ) {
			Core::print_less_often( 'StreamMerger::start_periodic_tick: no _router; periodic tick disabled' );
			return;
		}
		$router->register( 'TIMER', $this->name );
		if ( null !== $this->health_check ) {
			$this->health_check->start_periodic_tick();
		}
	}

	// =========================================================================
	// RemoteSource lifecycle
	// =========================================================================

	/**
	 * Add a remote SSE source.
	 *
	 * Two-arg shape (back-compat with prototype tests):
	 *   add_remote( $server_id, $url, $token = '' )
	 *
	 * Single-arg shape (production, registry-driven):
	 *   add_remote( $server_id ) -> reads { url, auth_username, auth_password, enabled }
	 *   from ServerRegistry. Skips if entry missing or disabled.
	 *
	 * Either way, instantiates a RemoteSource child, restores its position
	 * from the shared offsetlog, registers it in Core::$nodes_by_name so it
	 * appears in the topology console, and asks it to connect.
	 *
	 * @param string $server_id    Identifier — also the offsetlog key + memcache key fragment.
	 * @param string $url          Base URL (no trailing slash). Empty -> registry lookup.
	 * @param string $auth_token   Bearer fallback for compat. Ignored when Basic creds are set.
	 */
	public function add_remote( string $server_id, string $url = '', string $auth_token = '' ): void {
		$auth_username = '';
		$auth_password = '';

		if ( '' === $url ) {
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

		// HTTPS guard before construction so a bad entry never produces a node.
		if ( $this->require_https && \stripos( $url, 'https://' ) !== 0 ) {
			Core::print_less_often( "StreamMerger::add_remote: refusing non-HTTPS URL for {$server_id}: {$url}" );
			return;
		}

		// Replace any existing entry for this server_id (operator-driven reload).
		if ( isset( $this->remote_nodes[ $server_id ] ) ) {
			$this->remote_nodes[ $server_id ]->remove_node();
			unset( $this->remote_nodes[ $server_id ] );
		}

		$remote = new RemoteSource( $server_id, $url, $auth_username, $auth_password, $auth_token, $this->partition );
		// Propagate global policy + cache injection so children inherit current
		// hub config (operator toggles set_verify_ssl/set_require_https at
		// runtime; new children must reflect those without needing a respawn).
		$remote->set_verify_ssl( $this->verify_ssl );
		$remote->set_require_https( $this->require_https );
		if ( null !== $this->cache ) {
			$remote->set_cache( $this->cache );
		}
		// Children pipe to the same sink the merger targets — straight through
		// to firehose:topic. The rewrite filter fires inside RemoteSource.
		if ( null !== $this->sink ) {
			$remote->sink( $this->sink );
		}
		if ( \is_string( $this->target ) && '' !== $this->target ) {
			$remote->target( $this->target );
		}
		// Visible in the topology console: `remote:{server_id}` — a single
		// namespace so multiple StreamMergers in the same process don't
		// collide on entries with the same registry id.
		$child_name = $this->namespaced_remote_name( $server_id );
		$remote->name( $child_name );
		\Newspack_Nodes\Core::register_node( $child_name, $remote );

		$this->restore_position_for( $remote, $server_id );
		$this->remote_nodes[ $server_id ] = $remote;
		// Immediate connect attempt — RemoteSource backs off on its own if
		// it can't reach the spoke.
		$remote->tick();
	}

	/**
	 * Remove a remote — closes its handle, unregisters it from Core, drops the ref.
	 */
	public function remove_remote( string $server_id ): void {
		if ( ! isset( $this->remote_nodes[ $server_id ] ) ) {
			return;
		}
		$this->remote_nodes[ $server_id ]->remove_node();
		unset( $this->remote_nodes[ $server_id ] );
	}

	public function remote_count(): int {
		return \count( $this->remote_nodes );
	}

	public function active_count(): int {
		$n = 0;
		foreach ( $this->remote_nodes as $remote ) {
			if ( $remote->is_connected() ) {
				++$n;
			}
		}
		return $n;
	}

	/** @return array<string,RemoteSource> */
	public function remote_nodes(): array {
		return $this->remote_nodes;
	}

	private function namespaced_remote_name( string $server_id ): string {
		$prefix = '' !== $this->name ? $this->name : 'stream-merger';
		return $prefix . ':remote:' . $server_id;
	}

	// =========================================================================
	// Test inspectors (delegate to child RemoteSource)
	// =========================================================================

	public function test_get_handle( string $server_id ): ?\CurlHandle {
		return isset( $this->remote_nodes[ $server_id ] )
			? $this->remote_nodes[ $server_id ]->test_get_handle()
			: null;
	}

	public function get_last_http_code( string $server_id ): ?int {
		return isset( $this->remote_nodes[ $server_id ] )
			? $this->remote_nodes[ $server_id ]->get_last_http_code()
			: null;
	}

	public function get_last_error( string $server_id ): ?string {
		return isset( $this->remote_nodes[ $server_id ] )
			? $this->remote_nodes[ $server_id ]->get_last_error()
			: null;
	}

	public function get_backoff( string $server_id ): int {
		return isset( $this->remote_nodes[ $server_id ] )
			? $this->remote_nodes[ $server_id ]->get_backoff()
			: RemoteSource::INITIAL_BACKOFF;
	}

	public function get_slot( string $server_id ): ?int {
		return isset( $this->remote_nodes[ $server_id ] )
			? $this->remote_nodes[ $server_id ]->get_slot()
			: null;
	}

	public function get_position( string $server_id ): array {
		return isset( $this->remote_nodes[ $server_id ] )
			? $this->remote_nodes[ $server_id ]->position()
			: [ 'segment_id' => 0, 'offset' => 0 ];
	}

	/**
	 * Back-compat: tests look up a RemoteSource by its cURL handle and drive
	 * its WRITEFUNCTION-equivalent here. Production no longer routes through
	 * StreamMerger — EventFramework dispatches cURL completions directly to
	 * each RemoteSource's owned multi handle.
	 */
	public function on_curl_data( \CurlHandle $handle, string $bytes ): int {
		foreach ( $this->remote_nodes as $remote ) {
			if ( $remote->test_get_handle() === $handle ) {
				return $remote->on_curl_data( $handle, $bytes );
			}
		}
		return \strlen( $bytes );
	}

	/**
	 * Back-compat: same lookup-by-handle pattern as on_curl_data.
	 */
	public function on_curl_message( array $info ): void {
		$handle = $info['handle'] ?? null;
		if ( ! ( $handle instanceof \CurlHandle ) ) {
			return;
		}
		foreach ( $this->remote_nodes as $remote ) {
			if ( $remote->test_get_handle() === $handle ) {
				$remote->on_curl_message( $info );
				return;
			}
		}
	}

	/**
	 * Legacy test wrapper: drives an SSE chunk into a synthetic `__test__`
	 * RemoteSource so prototype-era fixtures keep working without setting up
	 * a real spoke. Production never calls this path (RemoteSource's own
	 * on_curl_data is the production entry).
	 */
	public function process_sse_chunk( string $chunk ): void {
		if ( ! isset( $this->remote_nodes['__test__'] ) ) {
			$remote = new RemoteSource( '__test__', 'https://__test__/', '', '', '', $this->partition );
			$remote->set_verify_ssl( $this->verify_ssl );
			$remote->set_require_https( $this->require_https );
			if ( null !== $this->cache ) {
				$remote->set_cache( $this->cache );
			}
			if ( null !== $this->sink ) {
				$remote->sink( $this->sink );
			}
			$child_name = $this->namespaced_remote_name( '__test__' );
			$remote->name( $child_name );
			\Newspack_Nodes\Core::register_node( $child_name, $remote );
			$this->remote_nodes['__test__'] = $remote;
		}
		$this->remote_nodes['__test__']->process_sse_chunk( $chunk );
	}

	// =========================================================================
	// Periodic tick: walk children + commit offsetlog.
	// =========================================================================

	public function tick(): void {
		foreach ( $this->remote_nodes as $server_id => $remote ) {
			if ( '__test__' === $server_id ) {
				continue;
			}
			$remote->tick();
		}
		$this->maybe_commit();
	}

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
	 * Format: TM_STRUCT envelope with VALUE = `{ "<server_id>": {"seg":N,"off":M}, ..., "_ts":t }`
	 * — one decode per restore.
	 */
	public function commit_all(): void {
		if ( empty( $this->remote_nodes ) ) {
			return;
		}
		$offsetlog = $this->ensure_offsetlog();
		if ( null === $offsetlog ) {
			return;
		}
		$entry = [];
		foreach ( $this->remote_nodes as $server_id => $remote ) {
			if ( '__test__' === $server_id ) {
				continue;
			}
			$pos = $remote->position();
			$entry[ $server_id ] = [
				'seg' => (int) ( $pos['segment_id'] ?? 0 ),
				'off' => (int) ( $pos['offset'] ?? 0 ),
			];
		}
		if ( empty( $entry ) ) {
			return;
		}
		$entry['_ts'] = (int) Core::$now;
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::VALUE ]     = $entry;
		$offsetlog->fill( $msg );
		$offsetlog->flush();
	}

	/**
	 * Read the offsetlog and push the latest position for $server_id into
	 * $remote BEFORE its first connect() — used by add_remote().
	 */
	private function restore_position_for( RemoteSource $remote, string $server_id ): void {
		$offsetlog = $this->ensure_offsetlog();
		if ( null === $offsetlog ) {
			return;
		}
		$segments = $offsetlog->get_segments( true );
		if ( empty( $segments ) ) {
			return;
		}
		$last    = \end( $segments );
		$content = $offsetlog->read_at( $last['id'], 0, $last['size'] );
		if ( '' === $content && \count( $segments ) > 1 ) {
			$prev    = $segments[ \count( $segments ) - 2 ];
			$content = $offsetlog->read_at( $prev['id'], 0, $prev['size'] );
		}
		if ( '' === $content ) {
			return;
		}
		$lines = \explode( "\n", \rtrim( $content, "\n" ) );
		$msg    = Message::unpacked( (string) \end( $lines ) );
		$latest = $msg[ Message::VALUE ];
		if ( ! \is_array( $latest ) || ! isset( $latest[ $server_id ] ) || ! \is_array( $latest[ $server_id ] ) ) {
			return;
		}
		$pos = $latest[ $server_id ];
		$remote->restore_position(
			(int) ( $pos['seg'] ?? 0 ),
			(int) ( $pos['off'] ?? 0 )
		);
	}

	private function ensure_offsetlog(): ?Partition {
		if ( null !== $this->offsetlog ) {
			return $this->offsetlog;
		}
		$logs_dir = Config::get_offsets_directory();
		if ( '' === $logs_dir ) {
			return null;
		}
		$dir = "{$logs_dir}/aggregator.p{$this->partition}";
		if ( ! \is_dir( $dir ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
			@\mkdir( $dir, 0755, true );
		}
		// Offsetlog is a single-partition Partition: the merger's spoke
		// partition is encoded in the dir name (`aggregator.p{N}`), so the
		// inner Partition's own partition axis is always 0. Matches the
		// pattern Consumer uses for its offsetlog.
		$this->offsetlog = new Partition( $dir, 0 );
		return $this->offsetlog;
	}

	// =========================================================================
	// Verb table + node_schema
	// =========================================================================

	private static array $verbs_cache = [];

	private static function config_verbs(): array {
		if ( empty( self::$verbs_cache ) ) {
			self::$verbs_cache = [
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
				'load_remotes_from_registry' => static function ( CommandInterpreter $ci, string $args ): string {
					/** @var self $patron */
					$patron   = $ci->patron();
					$registry = new ServerRegistry();
					foreach ( $registry->get_enabled() as $server_id => $entry ) {
						$patron->add_remote( (string) $server_id );
					}
					$patron->mark_verb_invoked( 'load_remotes_from_registry', '' );
					return 'ok';
				},
			];
		}
		return self::$verbs_cache;
	}

	public static function node_schema(): array {
		return [
			'category'    => 'I/O',
			'description' => 'Owns and supervises RemoteSource children — one per enabled spoke in ServerRegistry.',
			'ctor'        => [
				[ 'name' => 'partition', 'type' => 'int', 'default' => 0 ],
			],
			'verbs'       => [
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
				[
					'name'        => 'load_remotes_from_registry',
					'description' => 'Iterate ServerRegistry::get_enabled() and instantiate a RemoteSource child for each.',
					'args'        => [],
				],
			],
			'requests'    => [
				[
					'name'        => 'GET_REMOTES',
					'description' => 'Per-remote connection state for every active RemoteSource child.',
					'reply_shape' => '{ count, remotes: { server_id: { connected, last_error, last_http_code, position, last_event_age_s, current_backoff, slot } } }',
				],
			],
		];
	}
}
