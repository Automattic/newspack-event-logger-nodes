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

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Timer_Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Stream_Merger_Node extends Timer_Node {

	/** Offsetlog commit cadence. */
	public const COMMIT_INTERVAL_S = 5;

	// ---- Back-compat constants ---------------------------------------------
	// Per-remote constants now live on RemoteSource (each child enforces its
	// own limits). These aliases keep old call sites (tests, callers) working
	// without rewriting; new code should reference RemoteSource directly.
	public const INITIAL_BACKOFF    = Remote_Source_Node::INITIAL_BACKOFF;
	public const MAX_BACKOFF        = Remote_Source_Node::MAX_BACKOFF;
	public const CONNECT_TIMEOUT    = Remote_Source_Node::CONNECT_TIMEOUT;
	public const HEARTBEAT_TIMEOUT  = Remote_Source_Node::HEARTBEAT_TIMEOUT;
	public const HEARTBEAT_INTERVAL = Remote_Source_Node::HEARTBEAT_INTERVAL;
	public const MAX_BUFFER_SIZE    = Remote_Source_Node::MAX_BUFFER_SIZE;
	public const MAX_EVENT_SIZE     = Remote_Source_Node::MAX_EVENT_SIZE;
	public const MAX_QUEUE_SIZE     = Remote_Source_Node::MAX_QUEUE_SIZE;
	public const MAX_LINE_BYTES     = Remote_Source_Node::MAX_LINE_BYTES;
	public const STATUS_TTL         = Remote_Source_Node::STATUS_TTL;

	/** @var array<string,Remote_Source_Node> Refs to child RemoteSource nodes, keyed by server_id. */
	private array $remote_nodes = [];

	/** @var Partition_Node|null Per-partition offsetlog (one Partition for the whole merger). */
	private ?Partition_Node $offsetlog = null;

	/** @var string Topic name to pull from + heartbeat URL params. */
	protected string $remote_topic = '';

	/** @var int Partition number for the offsetlog directory + heartbeat URL params. */
	protected int $partition = 0;

	/** @var float Last commit_all timestamp. */
	private float $last_commit_time = 0.0;

	/** @var bool Whether to require HTTPS (default: true, blocks plain HTTP). */
	private bool $require_https = true;

	/** @var bool Whether to verify SSL certs (default: true). */
	private bool $verify_ssl = true;

	/** @var Health_Check_Tick_Node|null Owned sibling — drives aggregator's periodic health-check sweep. */
	private ?Health_Check_Tick_Node $health_check = null;

	/** @var bool One-shot guard so connect_node() loads registry remotes only once. */
	private bool $remotes_loaded = false;

	/**
	 * Tachikoma-parity: no-arg ctor. Positional config arrives via `arguments()`,
	 * which the base setter parses against `node_schema()['arguments']`. The
	 * override below clamps partition to >= 0.
	 *
	 * The owned HealthCheckTick sibling is mounted here because its construction
	 * doesn't depend on the positional args — it's a structural part of every
	 * StreamMerger regardless of remote_topic/partition.
	 */
	public function __construct() {
		// Owned HealthCheckTick sibling — TIMER-driven, hub-only, never
		// independently configurable. Patron-linked so dump_metadata hides it
		// and so its name tracks the StreamMerger's automatically.
		$this->health_check = new Health_Check_Tick_Node();
		$this->health_check->patron( $this );

		// Base ctor auto-wires the sibling :config interpreter from node_schema()['commands']
		// handlers (static; read $interpreter->patron() lazily, so end-placement is fine).
		parent::__construct();
	}

	/**
	 * Setter chains through the base schema walker (which assigns remote_topic
	 * and partition from positional tokens or schema defaults), then clamps
	 * partition to >= 0 to match the legacy ctor's `max(0, ...)`.
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
		$this->partition = \max( 0, $this->partition );
		$this->start_periodic_tick();
		return $result;
	}

	/**
	 * Pre-check the `{name}:health-check` sibling name for collisions before the base
	 * commits a rename. HealthCheck is application-specific; the parent handles the
	 * :config interpreter sibling.
	 */
	protected function check_name_availability( string $name ): void {
		if ( null !== $this->health_check && null !== Core::node( "{$name}:health-check" ) ) {
			throw new \RuntimeException( \esc_html( "node name collision: {$name}:health-check already registered" ) );
		}
		parent::check_name_availability( $name );
	}

	/**
	 * Override set_sibling_names() so the owned HealthCheckTick sibling tracks
	 * `{patron_name}:health-check` whenever the patron is named or
	 * renamed (mirrors FlameBuilder::name()'s AutoTuner cascade)
	 */
	protected function set_sibling_names( ?string $name = null ): void {
		$this->health_check?->name( $name . ':health-check' );
		parent::set_sibling_names( $name );
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
		// Full remove_node (not a bare unregister): Health_Check_Tick auto-wires
		// its own :config interpreter from node_schema, and only remove_node() cascades
		// that sibling interpreter's Core registration — a bare unregister would orphan it
		// and collide on a same-process name-recycle.
		if ( null !== $this->health_check && '' !== $this->health_check->name() ) {
			$this->health_check->remove_node();
		}
		$this->health_check = null;
		// Tear down the named offsetlog sibling (+ its :config) so a removed merger doesn't leak it in Core.
		$this->offsetlog?->remove_node();
		$this->offsetlog = null;
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

	/**
	 * Instantiate a RemoteSource child for every enabled spoke in ServerRegistry.
	 *
	 * One-shot action (formerly the `load_remotes_from_registry` verb). Fired
	 * from the connect_node() lifecycle hook once the target is wired, so a
	 * worker restart re-loads remotes from current registry state without a TSL
	 * verb line. add_remote() reads $this->sink / $this->target, both set by the
	 * time connect_node() runs (make_node sets the interpreter sink; connect_node sets the
	 * target), so children inherit the merger's downstream wiring.
	 */
	public function load_remotes_from_registry(): void {
		$registry = new Server_Registry();
		foreach ( $registry->get_enabled() as $server_id => $entry ) {
			$this->add_remote( $server_id );
		}
	}

	/**
	 * Lifecycle hook: once the target is wired (the topology's
	 * `connect_node stream-merger firehose:topic` line), load the registry
	 * remotes once. Guarded so a later re-connect doesn't re-load.
	 */
	public function connect_node( string $target ): void {
		parent::connect_node( $target );
		if ( ! $this->remotes_loaded ) {
			$this->remotes_loaded = true;
			$this->load_remotes_from_registry();
		}
	}

	/**
	 * Emit the base config plus this node's verb-config, from STATE — one
	 * `cmd {name}:config <verb> <value>` line per setting that differs from its
	 * default (both default true), for dump_config introspection (REPL/GUI).
	 */
	public function dump_config(): string {
		$out = parent::dump_config();
		if ( ! $this->verify_ssl ) {
			$out .= "cmd {$this->name}:config set_verify_ssl false\n";
		}
		if ( ! $this->require_https ) {
			$out .= "cmd {$this->name}:config set_require_https false\n";
		}
		return $out;
	}

	// =========================================================================
	// Node interface
	// =========================================================================

	public function fill( array &$message ): void {
		if ( null === $this->sink ) {
			throw new \RuntimeException( 'Stream_Merger::fill requires a wired sink' );
		}
		++$this->counter;
		/** @var int $type */
		$type = $message[ Message::TYPE ];
		if ( $type & Message::TM_REQUEST ) {
			$this->handle_request( $message );
			return;
		}
	}

	/**
	 * @param array<int, mixed> $message
	 */
	private function handle_request( array $message ): void {
		if ( null === $this->sink ) {
			throw new \RuntimeException( 'Stream_Merger::fill requires a wired sink' );
		}
		/** @var int|float|string|bool|null $raw_value */
		$raw_value = $message[ Message::VALUE ];
		$value     = (string) $raw_value;
		$verb      = \strtoupper( \explode( ' ', \trim( $value ), 2 )[0] );

		if ( 'GET_REMOTES' === $verb ) {
			$remotes = [];
			foreach ( $this->remote_nodes as $server_id => $remote ) {
				$remotes[ $server_id ] = $remote->current_status();
			}
			$payload = [
				'count'   => \count( $remotes ),
				'remotes' => $remotes,
			];
		} else {
			$payload = [ 'error' => "unknown request verb: {$verb}" ];
		}

		$reply                   = Message::new_message();
		$reply[ Message::TYPE ]  = Message::TM_STRUCT | Message::TM_RESPONSE;
		$reply[ Message::FROM ]  = $this->name;
		$reply[ Message::TO ]    = $message[ Message::FROM ];
		$reply[ Message::ID ]    = $message[ Message::ID ];
		$reply[ Message::KEY ]   = $message[ Message::KEY ];
		$reply[ Message::VALUE ] = $payload;
		$this->sink->fill( $reply );
	}

	public function start_periodic_tick(): void {
		// Keyed by $this->name; a nameless node would register an orphan TIMER
		// listener under the empty key (e.g. arguments() before name()). The
		// sanctioned make_node order names the node first, so this only guards
		// the un-sanctioned order.
		if ( '' === $this->name ) {
			return;
		}
		if ( null === Core::node( Node_Names::ROUTER ) ) {
			Core::print_less_often( 'StreamMerger::start_periodic_tick: no _router; periodic tick disabled' );
			return;
		}
		// Router-hitchhike: notify_timer() calls fire_cb() -> fire() each tick.
		$this->set_timer();
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
			$registry = new Server_Registry();
			$entry    = $registry->get( $server_id );
			if ( null === $entry ) {
				Core::print_less_often( "StreamMerger::add_remote: no registry entry for {$server_id}" );
				return;
			}
			if ( isset( $entry['enabled'] ) && false === $entry['enabled'] ) {
				return;
			}
			/** @var int|float|string|bool|null $raw_url */
			$raw_url = $entry['url'] ?? '';
			/** @var int|float|string|bool|null $raw_username */
			$raw_username = $entry['auth_username'] ?? '';
			/** @var int|float|string|bool|null $raw_password */
			$raw_password = $entry['auth_password'] ?? '';
			/** @var int|float|string|bool|null $raw_token */
			$raw_token     = $entry['token'] ?? $auth_token;
			$url           = (string) $raw_url;
			$auth_username = (string) $raw_username;
			$auth_password = (string) $raw_password;
			$auth_token    = (string) $raw_token;
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

		$remote = new Remote_Source_Node();
		$remote->configure( $server_id, $url, $auth_username, $auth_password, $auth_token, $this->remote_topic, $this->partition );
		// Propagate global policy so children inherit current hub config
		// (operator toggles set_verify_ssl/set_require_https at runtime; new
		// children must reflect those without needing a respawn). Memcache is
		// read off the shared Core::$memd handle, so no per-child injection.
		$remote->set_verify_ssl( $this->verify_ssl );
		$remote->set_require_https( $this->require_https );
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

	/** @return array<string, Remote_Source_Node> Child remote-source nodes, keyed by remote id. */
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
			: Remote_Source_Node::INITIAL_BACKOFF;
	}

	public function get_slot( string $server_id ): ?int {
		return isset( $this->remote_nodes[ $server_id ] )
			? $this->remote_nodes[ $server_id ]->get_slot()
			: null;
	}

	/**
	 * @return array<string, mixed>
	 */
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
	 * @param array{msg?: int, handle?: \CurlHandle, result?: int} $info
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
			$remote = new Remote_Source_Node();
			$remote->configure( '__test__', 'https://__test__/', '', '', '', $this->remote_topic, $this->partition );
			$remote->set_verify_ssl( $this->verify_ssl );
			$remote->set_require_https( $this->require_https );
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

	// fire (Timer_Node override): Router::notify_timer() -> fire_cb() -> fire() on
	// each TIMER tick. Drives every remote's poll + the debounced offsetlog commit.
	public function fire(): void {
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
				'seg' => $pos['segment_id'],
				'off' => $pos['offset'],
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
	private function restore_position_for( Remote_Source_Node $remote, string $server_id ): void {
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
		try {
			$msg = Message::unpacked( \end( $lines ) );
		} catch ( \InvalidArgumentException $e ) {
			// Unparseable offsetlog entry: skip restoring the remote's position
			// rather than aborting the merge.
			Core::print_less_often( "StreamMerger: ignoring unparseable offsetlog entry while restoring position: {$e->getMessage()}" );
			return;
		}
		$latest = $msg[ Message::VALUE ];
		if ( ! \is_array( $latest ) || ! isset( $latest[ $server_id ] ) || ! \is_array( $latest[ $server_id ] ) ) {
			return;
		}
		$pos = $latest[ $server_id ];
		/** @var int|float|string|bool|null $raw_seg */
		$raw_seg = $pos['seg'] ?? 0;
		/** @var int|float|string|bool|null $raw_off */
		$raw_off = $pos['off'] ?? 0;
		$remote->restore_position(
			(int) $raw_seg,
			(int) $raw_off
		);
	}

	private function ensure_offsetlog(): ?Partition_Node {
		if ( null !== $this->offsetlog ) {
			return $this->offsetlog;
		}
		$offsets_dir = Config::get_offsets_directory();
		if ( '' === $offsets_dir ) {
			return null;
		}
		$dir = "{$offsets_dir}/aggregator.p{$this->partition}";
		if ( ! \is_dir( $dir ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
			@\mkdir( $dir, 0755, true );
		}
		// Offsetlog is a single-partition Partition: the merger's spoke
		// partition is encoded in the dir name (`aggregator.p{N}`), so the
		// inner Partition's own partition axis is always 0. Matches the
		// pattern Consumer uses for its offsetlog.
		$this->offsetlog = new Partition_Node();
		// Named, patron-linked plumbing sibling (Tachikoma make_node parity):
		// `{merger}:offsetlog`, falling back to the stable partition-dir basename
		// when the merger is unnamed. patron() hides it from the canvas.
		$prefix = '' !== $this->name ? $this->name : "aggregator.p{$this->partition}";
		$this->offsetlog->name( "{$prefix}:offsetlog" );
		$this->offsetlog->patron( $this );
		$ci = Core::node( Node_Names::COMMAND_INTERPRETER );
		if ( null === $this->offsetlog->sink() && null !== $ci ) {
			$this->offsetlog->sink( $ci );
		}
		$this->offsetlog->arguments( "{$dir} 0" );
		return $this->offsetlog;
	}

	// =========================================================================
	// Verb table + node_schema
	// =========================================================================

	public static function node_schema(): array {
		return [
			'category'     => 'I/O',
			'description'  => 'Owns and supervises RemoteSource children — one per enabled spoke in ServerRegistry.',
			'arguments'         => [
				[ 'name' => 'remote_topic', 'type' => 'string', 'default' => '' ],
				[ 'name' => 'partition', 'type' => 'int', 'default' => 0 ],
			],
			'commands'        => [
				[
					'name'        => 'set_verify_ssl',
					'description' => 'Toggle SSL certificate verification on outbound SSE connections.',
					'args'        => [
						[ 'name' => 'verify', 'type' => 'bool', 'required' => true, 'default' => '<config:aggregator_verify_ssl>' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						$args = \strtolower( \trim( $args ) );
						$bool = ( 'true' === $args || '1' === $args );
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_verify_ssl( $bool );
						return 'ok';
					},
				],
				[
					'name'        => 'set_require_https',
					'description' => 'Refuse to connect to non-HTTPS remote URLs.',
					'args'        => [
						[ 'name' => 'require', 'type' => 'bool', 'required' => true, 'default' => '<config:aggregator_require_https>' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						$args = \strtolower( \trim( $args ) );
						$bool = ( 'true' === $args || '1' === $args );
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_require_https( $bool );
						return 'ok';
					},
				],
			],
			'requests'     => [
				[
					'name'        => 'GET_REMOTES',
					'description' => 'Per-remote connection state for every active RemoteSource child.',
					'reply_shape' => '{ count, remotes: { server_id: { connected, last_error, last_http_code, position, last_event_age_s, current_backoff, slot } } }',
				],
			],
			'accepts_fill' => false,
		];
	}
}
