<?php
/**
 * Flame Builder
 *
 * Node that builds flame_data from completed requests, writes to flames.log,
 * and accumulates per-URL aggregate stats.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flame builder node class.
 */
class Flame_Builder_Node extends Node {

	const EMA_SAMPLE_LIMIT   = 1000;
	const FLUSH_INTERVAL_SEC = 5;

	/** Security limits for recursion and unbounded growth. */
	private const MAX_RECURSION_DEPTH = 50;
	private const MAX_STACK_DEPTH     = 50;

	/** Pre-compiled regex patterns for flame data parsing. */
	const PATTERN_START    = '/^(.+?) \(start\)$/';
	const PATTERN_COMPLETE = '/^(.+?) \(complete\)$/';

	/** Entry limits with hysteresis: only trim when upper limit hit, trim to lower limit. */
	const ENTRY_LIMIT_URL_UPPER    = 40;
	const ENTRY_LIMIT_URL_LOWER    = 20;
	const ENTRY_LIMIT_GLOBAL_UPPER = 100;
	const ENTRY_LIMIT_GLOBAL_LOWER = 50;

	/** Dimension field mapping: dim key => request field name. */
	const DIM_FIELDS = [
		'status'  => 'status_category',
		'method'  => 'request_method',
		'server'  => 'server_name',
		'country' => 'country_code',
		'from'    => 'http_from',
		'ua'      => 'user_agent',
		'ja4'     => 'ja4_hash',
	];

	/** Minutes per time-series bucket. */
	private const BUCKET_MINUTES = 5;

	/** LRU cache for per-URL stats accumulators. */
	private const STATS_CACHE_BUCKET_SIZE = 1000;
	private const STATS_CACHE_NUM_BUCKETS = 5;

	/** @var LRU_Cache Per-URL aggregate accumulator. */
	private $stats_cache;

	/** @var array<string, array<string, mixed>> Bucket-keyed hourly accumulator. */
	private $hourly_stats                = [];
	/** @var array<string, array<string, mixed>> Bucket-keyed leaderboard accumulator. */
	private $leaderboard_stats           = [];
	/** @var array<string, array<string, array<string, mixed>>> Server → bucket leaderboard accumulator. */
	private $leaderboard_by_server_stats = [];
	/** @var array<string, array<string, mixed>> Bucket → url-hash URL stats accumulator. */
	private $url_stats                   = [];
	/** @var array<string, array<string, array<string, array{c: int, s: float|int, m: float|int}>>> Dim → bucket → value accumulator. */
	private $dim_stats                   = [];
	/** @var array<string, array<string, array<string, array<string, array{c: int, s: float|int, m: float|int}>>>> Server → dim → bucket → value accumulator. */
	private $dim_stats_by_server         = [];
	/** @var array<string, array<string, array<string, array<string, array{c: int, s: float|int, m: float|int}>>>> Url-hash → dim → bucket → value accumulator. */
	private $url_dim_stats               = [];
	/** @var array<string, array<string, mixed>> Bucket → category accumulator. */
	private $cat_stats                   = [];
	/** @var array<string, array<string, array<string, mixed>>> Server → bucket → category accumulator. */
	private $cat_stats_by_server         = [];
	/** @var array<string, array<string, array<string, mixed>>> Url-hash → bucket → category accumulator. */
	private $url_cat_stats               = [];

	/** Per-URL aggregate state. */
	private float $last_flush_time             = 0.0;
	private int $auto_disable_threshold        = 0;
	private float $auto_protect_time_threshold = 0.0;
	/** @var array<string, mixed> Disable decisions keyed by hook name. */
	private $hooks_to_disable            = [];
	/** @var array<string, mixed> Disable decisions keyed by event name. */
	private $custom_events_to_disable    = [];
	/** @var array<string, bool> Significant-event set ({name => true}). */
	private $significant_events          = [];
	/** @var array<string, mixed> Newly-significant events keyed by name. */
	private $new_significant_events      = [];
	/** @var array<string, bool> Custom-event-name set ({name => true}). */
	private $custom_event_names          = [];
	private bool $is_hub                 = false;

	/** Pending stats for the current (incomplete) 5-minute bucket. */
	private string $pending_bucket = '';

	/**
	 * Pending bucket accumulators. All keys optional so the empty default and
	 * reset_pending() both type-check; leaf shapes drive the deep-offset narrowing.
	 *
	 * @var array{
	 *   hourly?: array<string, mixed>,
	 *   dim?: array<string, array<string, array{c: int, s: float|int, m: float|int}>>,
	 *   dim_by_server?: array<string, array<string, array<string, array{c: int, s: float|int, m: float|int}>>>,
	 *   url_dim?: array<string, array<string, array<string, array{c: int, s: float|int, m: float|int}>>>,
	 *   url_stats?: array<string, mixed>,
	 *   cat?: array<string, array{t: float|int, c: float|int, n: int}>,
	 *   cat_by_server?: array<string, array<string, array{t: float|int, c: float|int, n: int}>>,
	 *   cat_by_url?: array<string, array<string, array{t: float|int, c: float|int, n: int}>>,
	 *   leaderboard?: array{count?: int, sum_req_time?: float|int, categories: array<string, array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>}>},
	 *   leaderboard_by_server?: array<string, array{count?: int, sum_req_time?: float|int, categories: array<string, array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>}>}>
	 * }
	 */
	private $pending = [];

	/** @var Stats_Store|null Memcache-backed stats store. */
	private $stats_store = null;


	/** @var callable|null Test seam: clock function for bucket-key derivation. */
	private $clock_fn = null;

	/** @var Auto_Tuner_Node|null Owned sibling — receives auto-tune decisions. */
	private ?Auto_Tuner_Node $auto_tuner = null;

	public function __construct() {
		$this->stats_cache     = new LRU_Cache( self::STATS_CACHE_BUCKET_SIZE, self::STATS_CACHE_NUM_BUCKETS );
		$this->last_flush_time = \microtime( true );
		$this->reset_pending();

		// Owned auto-tuner sibling. Patron-linked so dump_metadata
		// hides it from the canvas — it's plumbing for FlameBuilder,
		// not a graph node operators interact with. Named on first
		// patron name() (see name() override below).
		$this->auto_tuner = new Auto_Tuner_Node();
		$this->auto_tuner->patron( $this );

		// Base ctor auto-wires the sibling :config interpreter from node_schema()['commands']
		// handlers (static; read $interpreter->patron() lazily, so end-placement is fine).
		parent::__construct();
	}

	/**
	 * Pre-check the owned auto-tuner sibling's `{name}:auto-tuner` slot
	 * alongside the base's own-name + `:config` checks. Chains parent::.
	 */
	protected function check_name_availability( string $name ): void {
		if ( null !== $this->auto_tuner && null !== \Newspack_Nodes\Core::node( "{$name}:auto-tuner" ) ) {
			throw new \RuntimeException( \esc_html( "node name collision: {$name}:auto-tuner already registered" ) );
		}
		parent::check_name_availability( $name );
	}

	/**
	 * Track the owned auto-tuner sibling as `{name}:auto-tuner`. Only called from
	 * name() with a non-empty $name; sibling teardown lives in remove_node().
	 * Chains parent::.
	 */
	protected function set_sibling_names( ?string $name = null ): void {
		$this->auto_tuner?->name( "{$name}:auto-tuner" );
		parent::set_sibling_names( $name );
	}

	/**
	 * Cascade-remove the owned auto-tuner sibling alongside the patron. Full
	 * remove_node (not a bare unregister) so the auto-tuner's own `:config`
	 * interpreter sibling unregisters too and a same-name respawn doesn't collide.
	 */
	public function remove_node(): void {
		if ( null !== $this->auto_tuner ) {
			$this->auto_tuner->remove_node();
			$this->auto_tuner = null;
		}
		parent::remove_node();
	}

	/**
	 * Propagate the make_node auto-sink down to the owned auto-tuner sibling so
	 * it's sunk into _command_interpreter like any other sibling (Rule 2c).
	 */
	public function sink( ?Node $node = null ): ?Node {
		if ( \func_num_args() > 0 ) {
			$this->auto_tuner?->sink( $node );
			return parent::sink( $node );
		}
		return parent::sink();
	}

	/**
	 * Inject the Stats_Store.
	 */
	public function set_stats_store( ?Stats_Store $store ): void {
		$this->stats_store = $store;
	}


	/**
	 * Toggle hub mode (per-server tracking).
	 */
	public function set_is_hub( bool $is_hub ): void {
		$this->is_hub = $is_hub;
	}

	/**
	 * Inject the custom-event-names set.
	 *
	 * @param array<int, string> $names
	 */
	public function set_custom_event_names( array $names ): void {
		$this->custom_event_names = [];
		foreach ( $names as $n ) {
			$this->custom_event_names[ $n ] = true;
		}
	}

	/**
	 * Inject the persisted significant-events set.
	 *
	 * @param array<int, string> $events
	 */
	public function set_significant_events( array $events ): void {
		$this->significant_events = [];
		foreach ( $events as $e ) {
			$this->significant_events[ $e ] = true;
		}
	}

	/**
	 * Configure auto-tune thresholds.
	 *
	 * @param int   $count_threshold Disable check threshold (0 = disabled).
	 * @param float $time_threshold  Significant-event threshold (0 = disabled).
	 */
	public function set_auto_tune( int $count_threshold, float $time_threshold ): void {
		$this->auto_disable_threshold      = $count_threshold;
		$this->auto_protect_time_threshold = $time_threshold;
	}

	/**
	 * Replace the clock used for bucket-key derivation (testing seam).
	 */
	public function set_clock( ?callable $fn ): void {
		$this->clock_fn = $fn;
	}

	private function now_ts(): int {
		return null !== $this->clock_fn ? (int) ( $this->clock_fn )() : \time();
	}

	/**
	 * Total in-flight per-URL accumulator count (test helper).
	 */
	public function stats_count(): int {
		$count = 0;
		foreach ( $this->stats_cache->iterate() as $_ ) {
			++$count;
		}
		return $count;
	}

	/**
	 * Accessor for the auto-tune state.
	 *
	 * @return array<string, list<string>>
	 */
	public function get_auto_tune_state(): array {
		return [
			'hooks'           => \array_keys( $this->hooks_to_disable ),
			'custom_events'   => \array_keys( $this->custom_events_to_disable ),
			'new_significant' => \array_keys( $this->new_significant_events ),
		];
	}

	/**
	 * Save state for persistence.
	 *
	 * @return array<string, mixed>
	 */
	public function save_state(): array {
		return [
			'pending_bucket' => $this->pending_bucket,
			'pending'        => $this->pending,
		];
	}

	/**
	 * Restore state from save_state().
	 *
	 * @param array<string, mixed> $saved
	 */
	public function restore_state( array $saved ): void {
		if ( isset( $saved['pending_bucket'] ) && \is_string( $saved['pending_bucket'] ) ) {
			$this->pending_bucket = $saved['pending_bucket'];
		}
		if ( isset( $saved['pending'] ) && \is_array( $saved['pending'] ) ) {
			$merged = \array_merge( $this->pending, $saved['pending'] );
			/** @var array{hourly?: array<string, mixed>, dim?: array<string, array<string, array{c: int, s: float|int, m: float|int}>>, dim_by_server?: array<string, array<string, array<string, array{c: int, s: float|int, m: float|int}>>>, url_dim?: array<string, array<string, array<string, array{c: int, s: float|int, m: float|int}>>>, url_stats?: array<string, mixed>, cat?: array<string, array{t: float|int, c: float|int, n: int}>, cat_by_server?: array<string, array<string, array{t: float|int, c: float|int, n: int}>>, cat_by_url?: array<string, array<string, array{t: float|int, c: float|int, n: int}>>, leaderboard?: array{count?: int, sum_req_time?: float|int, categories: array<string, array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>}>}, leaderboard_by_server?: array<string, array{count?: int, sum_req_time?: float|int, categories: array<string, array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>}>}>} $merged */
			$this->pending = $merged;
		}
	}

	/**
	 * Emit the base config plus this node's verb-config, from STATE — one
	 * `cmd {name}:config <verb> <value>` line per setting that differs from its
	 * default, for dump_config introspection (REPL/GUI). No generic verb recording.
	 */
	public function dump_config(): string {
		$out = parent::dump_config();
		if ( $this->is_hub ) {
			$out .= "cmd {$this->name}:config set_is_hub true\n";
		}
		if ( 0 !== $this->auto_disable_threshold || 0.0 !== $this->auto_protect_time_threshold ) {
			$out .= "cmd {$this->name}:config set_auto_tune {$this->auto_disable_threshold} {$this->auto_protect_time_threshold}\n";
		}
		if ( ! empty( $this->significant_events ) ) {
			$csv  = \implode( ',', \array_keys( $this->significant_events ) );
			$out .= "cmd {$this->name}:config set_significant_events {$csv}\n";
		}
		if ( null !== $this->stats_store ) {
			$out .= "cmd {$this->name}:config configure_stats {$this->stats_store->partition()}\n";
		}
		return $out;
	}

	/**
	 * Maintenance hook — drives periodic flush even with no inbound traffic.
	 */
	public function maintenance(): void {
		$now = \microtime( true );
		if ( $now - $this->last_flush_time >= self::FLUSH_INTERVAL_SEC ) {
			$this->flush();
			$this->last_flush_time = $now;
		}
	}

	/**
	 * Process a single completed request from requests.log.
	 *
	 * @param array<int, mixed> $message Reference; not mutated.
	 */
	public function fill( array &$message ): void {
		++$this->counter;
		$type_raw = $message[ Message::TYPE ];
		$type     = \is_int( $type_raw ) ? $type_raw : 0;
		if ( $type & Message::TM_REQUEST ) {
			$this->handle_request( $message );
			return;
		}
		if ( ! ( $type & Message::TM_STRUCT ) ) {
			return;
		}
		$request = $message[ Message::VALUE ];
		if ( ! \is_array( $request ) ) {
			return;
		}

		$rid_raw  = $request['rid'] ?? '';
		$rid      = \is_string( $rid_raw ) ? $rid_raw : '';
		$url_raw  = $request['url'] ?? '';
		$url_hash = Request_Builder_Node::url_hash( \is_string( $url_raw ) ? $url_raw : '' );
		$entries  = $request['entries'] ?? [];
		if ( ! \is_array( $entries ) ) {
			$entries = [];
		}

		$duration_raw        = $request['duration_ms'] ?? 0;
		$flame_data          = $this->build_flame_data( $entries );
		$flame_data['value'] = \is_numeric( $duration_raw ) ? (float) $duration_raw : 0.0;

		$profiles = $request['profiles'] ?? [];
		if ( ! \is_array( $profiles ) ) {
			$profiles = [];
		}

		if ( $this->store_flame( $rid, $url_hash, $flame_data ) ) {
			$this->accumulate_all_stats( $url_hash, $flame_data, $profiles, $request );
		}

		// Periodic flush.
		$now_f = \microtime( true );
		if ( $now_f - $this->last_flush_time >= self::FLUSH_INTERVAL_SEC ) {
			$this->flush();
			$this->last_flush_time = $now_f;
		}
	}

	/**
	 * Flush every accumulator to memcache (or to in-memory if no store) and
	 * reset pending. Called every FLUSH_INTERVAL_SEC plus at shutdown.
	 */
	public function flush(): void {
		// Promote pending bucket into flush arrays on every flush cycle so
		// dashboards see data within 30s, not after the 5-minute bucket rotation.
		// The merge in persist_aggregate_stats is additive, so flushing partial
		// 30s chunks produces the same result as one batch at rotation time.
		if ( '' !== $this->pending_bucket ) {
			$this->promote_pending_bucket();
		}

		// Flush per-URL stats accumulators (combined flame + profiles) to memcache.
		$stats_store = $this->stats_store;
		if ( null !== $stats_store ) {
			$now = $this->now_ts();
			foreach ( $this->stats_cache->iterate() as $url_hash => $aggregate ) {
				if ( ! \is_array( $aggregate ) || ( ! \is_string( $url_hash ) && ! \is_int( $url_hash ) ) ) {
					continue;
				}
				$url_hash = (string) $url_hash;
				/** @var array<string, mixed> $aggregate */
				// Create finalized flame for display (scale values, strip suffixes, normalize).
				// Keep raw flame_raw for future merging (unscaled, with seen_count).
				$flame                  = \is_array( $aggregate['flame'] ?? null ) ? $aggregate['flame'] : [];
				$count_raw              = $flame['count'] ?? 0;
				$total_count            = \is_numeric( $count_raw ) ? (int) $count_raw : 0;
				$aggregate['flame_raw'] = $flame;
				self::finalize_flame_node( $flame, $total_count );
				$aggregate['flame']         = $flame;
				$aggregate['last_modified'] = $now;
				$stats_store->set_url_stats( $url_hash, $aggregate );
			}
		}

		// Flush combined hourly, leaderboard, and URL stats to memcache.
		$this->persist_aggregate_stats();

		// Apply auto-disable.
		$this->apply_auto_tune();

		$this->stats_cache->flush();
		$this->url_stats                   = [];
		$this->hourly_stats                = [];
		$this->leaderboard_stats           = [];
		$this->leaderboard_by_server_stats = [];
		$this->dim_stats                   = [];
		$this->dim_stats_by_server         = [];
		$this->url_dim_stats               = [];
		$this->cat_stats                   = [];
		$this->cat_stats_by_server         = [];
		$this->url_cat_stats               = [];
	}

	/**
	 * Store flame data to flames log.
	 *
	 * Index is written automatically via the with_index() callback.
	 *
	 * @param string $rid        Request ID.
	 * @param string $url_hash   URL hash.
	 * @param array<string, mixed> $flame_data Flame graph data.
	 * @return bool True on success.
	 */
	private function store_flame( string $rid, string $url_hash, array $flame_data ): bool {
		// Strip duplicate sibling suffixes before storage (they're only needed for merging).
		self::strip_name_suffixes( $flame_data );

		// Add rid and url_hash to flame data so index callback can extract them.
		$flame_data['rid']      = $rid;
		$flame_data['url_hash'] = $url_hash;

		if ( '' === $this->target || null === $this->sink ) {
			return true; // Aggregation still happens; just no on-disk flame.
		}
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::TO ]        = $this->target;
		$msg[ Message::KEY ]       = $flame_data['rid'];
		$msg[ Message::VALUE ]     = $flame_data;
		$this->sink->fill( $msg );
		return true;
	}

	/**
	 * Strip hidden sequence suffixes (\x00N) from flame node names recursively.
	 *
	 * @param array<string, mixed> $node  Flame node (modified in place).
	 * @param int                  $depth Current recursion depth.
	 */
	private static function strip_name_suffixes( array &$node, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}
		$name     = $node['name'] ?? '';
		$name     = \is_string( $name ) ? $name : '';
		$null_pos = \strpos( $name, "\x00" );
		if ( false !== $null_pos ) {
			$node['name'] = \substr( $name, 0, $null_pos );
		}
		if ( ! empty( $node['children'] ) && \is_array( $node['children'] ) ) {
			foreach ( $node['children'] as &$child ) {
				if ( \is_array( $child ) ) {
					/** @var array<string, mixed> $child */
					self::strip_name_suffixes( $child, $depth + 1 );
				}
			}
			unset( $child );
		}
	}

	/**
	 * Format index entry callback for Partition::with_index().
	 *
	 * @param string                     $line     The JSON line written to the log.
	 * @param array<string, int>         $position Position array with segment_id, offset, length.
	 * @param array<string, mixed>|null  $data     Pre-decoded data (avoids re-parsing $line).
	 * @return string|null Index entry or null to skip.
	 */
	public static function format_index_entry( string $line, array $position, ?array &$data = null ): ?string {
		// $line is the packed Message (positional JSON); VALUE is index 6.
		$decoded = \json_decode( $line, true, 64 );
		$value   = \is_array( $decoded ) ? ( $decoded[ Message::VALUE ] ?? null ) : null;
		if ( ! \is_array( $value ) || empty( $value['rid'] ) ) {
			return null;
		}
		$rid_str      = \is_scalar( $value['rid'] ) ? (string) $value['rid'] : '';
		$url_hash_str = \is_scalar( $value['url_hash'] ?? null ) ? (string) $value['url_hash'] : '';

		return \str_pad( \substr( $rid_str, 0, 32 ), 32 )
			. \str_pad( \substr( $url_hash_str, 0, 12 ), 12 )
			. \str_pad( (string) $position['segment_id'], 6, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['offset'], 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['length'], 8, '0', STR_PAD_LEFT );
	}

	/**
	 * Parse flame index entry.
	 *
	 * @param string $line Index line.
	 * @return array{rid: string, url_hash: string, segment_id: int, offset: int, length: int}|null
	 */
	public static function parse_flame_index( string $line ): ?array {
		$line = \rtrim( $line, "\n" );
		if ( \strlen( $line ) < 68 ) {
			return null;
		}
		return [
			'rid'        => \trim( \substr( $line, 0, 32 ) ),
			'url_hash'   => \trim( \substr( $line, 32, 12 ) ),
			'segment_id' => (int) \substr( $line, 44, 6 ),
			'offset'     => (int) \substr( $line, 50, 10 ),
			'length'     => (int) \substr( $line, 60, 8 ),
		];
	}

	/**
	 * Build flame graph data using stack-based LIFO matching.
	 *
	 * This handles improperly nested events (e.g., when a child span outlives
	 * its parent) by using LIFO matching like the log-manager does.
	 *
	 * @param array<int, mixed> $entries Log entries.
	 * @return array<string, mixed> Flame graph data.
	 */
	private function build_flame_data( array $entries ): array {
		// Root node.
		$root = [
			'name'     => 'request',
			'value'    => 0,
			'children' => [],
		];

		// Stack of open nodes. Each entry: [ 'node' => &node, 'name' => base_name ].
		/** @var array<int, array{node: array{name?: string, value?: mixed, children?: array<int, mixed>, ts?: int, detail?: string}, name: string}> $stack */
		$stack   = [];
		$stack[] = [
			'node' => &$root,
			'name' => 'request',
		];

		foreach ( $entries as $entry ) {
			if ( ! \is_array( $entry ) ) {
				continue;
			}
			$keyword_raw = $entry['k'] ?? '';
			$keyword     = \is_string( $keyword_raw ) ? $keyword_raw : '';

			if ( \preg_match( self::PATTERN_START, $keyword, $m ) ) {
				$base_name = $m[1];
				// 'l' is the stable label for aggregation/deduplication.
				// 'm' is the message with volatile details (SQL, paths) for display.
				$label  = \is_string( $entry['l'] ?? '' ) ? ( $entry['l'] ?? '' ) : '';
				$detail = \is_string( $entry['m'] ?? '' ) ? ( $entry['m'] ?? '' ) : '';
				$new_node = [
					'name'     => $label ? "{$base_name}: {$label}" : $base_name,
					'value'    => 0,
					'children' => [],
				];
				if ( $detail && $detail !== $label ) {
					$new_node['detail'] = "{$base_name}: {$detail}";
				}

				// Add as child of current top of stack.
				$top_idx                                 = \count( $stack ) - 1;
				$stack[ $top_idx ]['node']['children'][] = &$new_node;

				// Push onto stack (with depth limit to prevent DoS).
				if ( \count( $stack ) < self::MAX_STACK_DEPTH ) {
					$stack[] = [
						'node' => &$new_node,
						'name' => $base_name,
					];
				}
				unset( $new_node ); // Break reference for next iteration.

			} elseif ( \preg_match( self::PATTERN_COMPLETE, $keyword, $m ) ) {
				$base_name   = $m[1];
				$duration_ms = $entry['duration_ms'] ?? 0;
				$ts_raw      = $entry['ts'] ?? $this->now_ts();

				// Search stack from top (LIFO) for matching name.
				$found_idx = -1;
				for ( $i = \count( $stack ) - 1; $i >= 1; $i-- ) { // Don't pop root.
					if ( ( $stack[ $i ]['name'] ?? null ) === $base_name ) {
						$found_idx = $i;
						break;
					}
				}

				if ( $found_idx >= 1 ) {
					// Set duration and timestamp on matched node.
					$stack[ $found_idx ]['node']['value'] = $duration_ms;
					$stack[ $found_idx ]['node']['ts']    = \is_numeric( $ts_raw ) ? (int) $ts_raw : $this->now_ts();

					// Pop all nodes from found_idx to top.
					// Children that outlive their parent become orphaned (value=0).
					\array_splice( $stack, $found_idx );
				}
				// If not found, this is an orphaned complete - ignore it.
			}
		}

		// Number duplicate sibling names to prevent collapse during aggregation.
		self::number_duplicate_siblings( $root );

		return $root;
	}

	/**
	 * Recursively number duplicate sibling names with hidden suffix.
	 *
	 * Appends \x00{N} to duplicate names so they stay separate during merge,
	 * but the suffix is stripped before display.
	 *
	 * @param array<string, mixed> $node  Flame node (modified by reference).
	 * @param int                  $depth Current recursion depth.
	 */
	private static function number_duplicate_siblings( array &$node, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}
		if ( empty( $node['children'] ) || ! \is_array( $node['children'] ) ) {
			return;
		}

		// Count occurrences of each name among siblings.
		$name_counts = [];
		foreach ( $node['children'] as $child ) {
			$child_name           = \is_array( $child ) ? ( $child['name'] ?? 'unknown' ) : 'unknown';
			$name                 = \is_string( $child_name ) ? $child_name : 'unknown';
			$name_counts[ $name ] = ( $name_counts[ $name ] ?? 0 ) + 1;
		}

		// Add sequence numbers to duplicates.
		$name_seq = [];
		foreach ( $node['children'] as &$child ) {
			if ( ! \is_array( $child ) ) {
				continue;
			}
			$child_name = $child['name'] ?? 'unknown';
			$name       = \is_string( $child_name ) ? $child_name : 'unknown';
			if ( $name_counts[ $name ] > 1 ) {
				$seq               = ( $name_seq[ $name ] ?? 0 ) + 1;
				$name_seq[ $name ] = $seq;
				$child['name']     = $name . "\x00" . $seq;
			}
			self::number_duplicate_siblings( $child, $depth + 1 );
		}
	}

	// -------------------------------------------------------------------------
	// Stats accumulation: per-URL flame, dimensional, category, leaderboard,
	// hourly, URL stats — all into the pending bucket + LRU.
	// -------------------------------------------------------------------------

	/**
	 * Accumulate all per-request stats from a completed request.
	 *
	 * @param string $url_hash   URL hash.
	 * @param array<string, mixed> $flame_data Per-request flame tree.
	 * @param array<array-key, mixed> $profiles   profiles{} from request.
	 * @param array<string, mixed> $request    Full request record.
	 */
	private function accumulate_all_stats( string $url_hash, array $flame_data, array $profiles, array $request ): void {
		$duration_val = $flame_data['value'] ?? 0;
		$duration_ms  = \is_numeric( $duration_val ) ? (float) $duration_val : 0.0;
		$error_status = $request['error_status'] ?? '-';
		$is_timed_out = 'T' === $error_status;
		$is_worker    = ! empty( $request['is_worker'] );
		// Exclude from timing stats: timed-out (synthetic duration) and workers (skew averages).
		$has_timing = $duration_ms > 0 && ! $is_timed_out && ! $is_worker;
		$now        = $this->now_ts();

		// --- 1. Per-URL aggregate (LRU, sums-not-means) ---
		$cached    = $this->stats_cache->get( $url_hash );
		$aggregate = \is_array( $cached ) ? $cached : null;
		if ( null === $aggregate ) {
			$aggregate = $this->stats_store?->get_url_stats( $url_hash ) ?? [
				'flame'    => [
					'name'      => 'aggregate',
					'sum_value' => 0.0,
					'count'     => 0,
					'children'  => [],
				],
				'profiles' => [
					'count'        => 0,
					'sum_req_time' => 0.0,
					'categories'   => [],
				],
			];
			if ( isset( $aggregate['flame_raw'] ) ) {
				$aggregate['flame'] = $aggregate['flame_raw'];
				unset( $aggregate['flame_raw'] );
			}
			// Migrate legacy flame shape (EMA running mean → sums).
			$aggregate_flame = $aggregate['flame'] ?? null;
			if ( isset( $aggregate_flame ) && ! ( \is_array( $aggregate_flame ) && isset( $aggregate_flame['sum_value'] ) ) ) {
				$aggregate['flame'] = [
					'name'      => 'aggregate',
					'sum_value' => 0.0,
					'count'     => 0,
					'children'  => [],
				];
			}
			// Migrate legacy profile shape.
			$aggregate_profiles = $aggregate['profiles'] ?? null;
			if ( isset( $aggregate_profiles ) && ! ( \is_array( $aggregate_profiles ) && isset( $aggregate_profiles['sum_req_time'] ) ) ) {
				$aggregate['profiles'] = [
					'count'        => 0,
					'sum_req_time' => 0.0,
					'categories'   => [],
				];
			}
		}

		$flame = \is_array( $aggregate['flame'] ?? null ) ? $aggregate['flame'] : [];
		$flame['count'] = ( \is_numeric( $flame['count'] ?? null ) ? $flame['count'] : 0 ) + 1;
		if ( $has_timing ) {
			$flame['sum_value'] = ( \is_numeric( $flame['sum_value'] ?? null ) ? $flame['sum_value'] : 0 ) + $duration_ms;
			$flame_children     = \is_array( $flame['children'] ?? null ) ? $flame['children'] : [];
			$incoming_children  = \is_array( $flame_data['children'] ?? null ) ? $flame_data['children'] : [];
			$flame['children']  = self::merge_flame_children_incremental( $flame_children, $incoming_children, $now );
		}
		$aggregate['flame'] = $flame;

		// --- 2. Bucket key + rotation ---
		$timestamp_raw = $request['timestamp'] ?? $now;
		$timestamp     = \is_numeric( $timestamp_raw ) ? (int) $timestamp_raw : $now;
		$bucket_key    = $this->bucket_key( $timestamp );
		if ( $bucket_key !== $this->pending_bucket ) {
			if ( '' !== $this->pending_bucket ) {
				$this->promote_pending_bucket();
			}
			$this->pending_bucket = $bucket_key;
		}

		// --- 2b. URL stats (pending bucket) ---
		$url_val = $request['url'] ?? '';
		$url     = \is_string( $url_val ) ? $url_val : '';
		if ( '' !== $url ) {
			if ( ! isset( $this->pending['url_stats'][ $url_hash ] ) ) {
				$this->pending['url_stats'][ $url_hash ] = [
					'url'         => $url,
					'count'       => 0,
					'timed_count' => 0,
					'sum_ms'      => 0,
					'min_ms'      => PHP_INT_MAX,
					'max_ms'      => 0,
					'last_seen'   => 0,
					'durations'   => [],
					'count_2xx'   => 0,
					'count_3xx'   => 0,
					'count_4xx'   => 0,
					'count_5xx'   => 0,
					'sum_peak_mb' => 0,
					'max_peak_mb' => 0,
				];
			}
			/** @var array{url: string, count: int, timed_count: int, sum_ms: float|int, min_ms: float|int, max_ms: float|int, last_seen: int, durations: array<int, float|int>, count_2xx: int, count_3xx: int, count_4xx: int, count_5xx: int, sum_peak_mb: float|int, max_peak_mb: float|int} $us */
			$us = $this->pending['url_stats'][ $url_hash ];
			++$us['count'];
			if ( $has_timing ) {
				++$us['timed_count'];
				$us['sum_ms'] += $duration_ms;
				$us['max_ms']  = \max( $us['max_ms'], $duration_ms );
			}
			$us['last_seen']      = \max( $us['last_seen'], $timestamp );
			$status_code          = $request['status_code'] ?? 0;
			$status_category      = (int) \floor( ( \is_numeric( $status_code ) ? (float) $status_code : 0 ) / 100 );
			if ( $status_category >= 2 && $status_category <= 5 ) {
				++$us[ "count_{$status_category}xx" ];
			}
			if ( $has_timing ) {
				$max_dur     = Stats_Store::MAX_DURATIONS_PER_BUCKET;
				$us['min_ms'] = \min( $us['min_ms'], $duration_ms );
				if ( \count( $us['durations'] ) < $max_dur ) {
					$us['durations'][] = $duration_ms;
				} else {
					$idx = \random_int( 0, \max( 1, $us['timed_count'] ) - 1 );
					if ( $idx < $max_dur ) {
						$us['durations'][ $idx ] = $duration_ms;
					}
				}
			}
			$peak_raw = $request['peak_mb'] ?? 0;
			$peak_mb  = \is_numeric( $peak_raw ) ? (float) $peak_raw : 0.0;
			if ( $peak_mb > 0 ) {
				$us['sum_peak_mb'] += $peak_mb;
				$us['max_peak_mb']  = \max( $us['max_peak_mb'], $peak_mb );
			}
			$this->pending['url_stats'][ $url_hash ] = $us;
		}

		// --- 3. Hourly stats (pending bucket) ---
		$hourly_peak     = $request['peak_mb'] ?? 0;
		$hourly_peak_num = \is_numeric( $hourly_peak ) ? $hourly_peak + 0 : 0;
		$hourly          = $this->pending['hourly'] ?? [];
		$hourly          = [
			'count'       => \is_numeric( $hourly['count'] ?? null ) ? $hourly['count'] : 0,
			'sum_ms'      => \is_numeric( $hourly['sum_ms'] ?? null ) ? $hourly['sum_ms'] : 0,
			'sum_peak_mb' => \is_numeric( $hourly['sum_peak_mb'] ?? null ) ? $hourly['sum_peak_mb'] : 0,
		];
		if ( $has_timing ) {
			++$hourly['count'];
			$hourly['sum_ms'] += $duration_ms;
		}
		$hourly['sum_peak_mb'] += $hourly_peak_num;
		$this->pending['hourly'] = $hourly;
		$status_code_raw = $request['status_code'] ?? 0;
		$status_cat      = (int) \floor( ( \is_numeric( $status_code_raw ) ? (float) $status_code_raw : 0 ) / 100 );
		if ( $status_cat >= 2 && $status_cat <= 5 ) {
			$request['status_category'] = "{$status_cat}xx";
		}

		// --- 3b. Dimensional stats (global + per-server + per-URL) ---
		static $intern      = [];
		static $intern_full = false;
		$server_raw     = $request['server_name'] ?? '';
		$server_name    = \is_string( $server_raw ) ? $server_raw : '';
		$dim_peak_raw   = $request['peak_mb'] ?? 0;
		$dim_peak_mb    = \is_numeric( $dim_peak_raw ) ? (float) $dim_peak_raw : 0.0;
		$dim_duration   = $has_timing ? $duration_ms : 0;

		foreach ( self::DIM_FIELDS as $dim => $field ) {
			$field_raw = $request[ $field ] ?? '';
			$val       = \is_scalar( $field_raw ) ? (string) $field_raw : '';
			if ( '' === $val ) {
				$val = 'Unknown';
			}
			if ( ! $intern_full ) {
				$val = $intern[ $val ] ??= $val;
				if ( \count( $intern ) >= 50000 ) {
					$intern_full = true;
				}
			}
			// Global.
			if ( ! isset( $this->pending['dim'][ $dim ][ $val ] ) ) {
				$this->pending['dim'][ $dim ][ $val ] = [ 'c' => 0, 's' => 0, 'm' => 0 ];
			}
			++$this->pending['dim'][ $dim ][ $val ]['c'];
			$this->pending['dim'][ $dim ][ $val ]['s'] += $dim_duration;
			$this->pending['dim'][ $dim ][ $val ]['m'] += $dim_peak_mb;

			// Per-server (hub mode only; skip 'server' dimension — redundant).
			if ( $this->is_hub && '' !== $server_name && 'server' !== $dim ) {
				if ( ! isset( $this->pending['dim_by_server'][ $server_name ][ $dim ][ $val ] ) ) {
					$this->pending['dim_by_server'][ $server_name ][ $dim ][ $val ] = [ 'c' => 0, 's' => 0, 'm' => 0 ];
				}
				++$this->pending['dim_by_server'][ $server_name ][ $dim ][ $val ]['c'];
				$this->pending['dim_by_server'][ $server_name ][ $dim ][ $val ]['s'] += $dim_duration;
				$this->pending['dim_by_server'][ $server_name ][ $dim ][ $val ]['m'] += $dim_peak_mb;
			}

			// Per-URL.
			if ( ! isset( $this->pending['url_dim'][ $url_hash ][ $dim ][ $val ] ) ) {
				$this->pending['url_dim'][ $url_hash ][ $dim ][ $val ] = [ 'c' => 0, 's' => 0, 'm' => 0 ];
			}
			++$this->pending['url_dim'][ $url_hash ][ $dim ][ $val ]['c'];
			$this->pending['url_dim'][ $url_hash ][ $dim ][ $val ]['s'] += $dim_duration;
			$this->pending['url_dim'][ $url_hash ][ $dim ][ $val ]['m'] += $dim_peak_mb;
		}

		// --- 4. Profile data (per-URL + global leaderboard) - SINGLE LOOP ---
		if ( ! empty( $profiles ) && $has_timing ) {
			$aggregate_profiles = \is_array( $aggregate['profiles'] ?? null ) ? $aggregate['profiles'] : [];
			$prof_cats          = $aggregate_profiles['categories'] ?? [];
			$aggregate_profiles['categories'] = \is_array( $prof_cats ) ? $prof_cats : [];
			/** @var array{count?: int, sum_req_time?: float|int, categories: array<string, array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>}>} $prof */
			$prof = $aggregate_profiles;
			/** @var array{count?: int, sum_req_time?: float|int, categories: array<string, array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>}>} $lb */
			$lb   = &$this->pending['leaderboard'];

			$req_time        = 0.0;
			$count_threshold = $this->auto_disable_threshold;

			// Initialize "total" pseudo-category.
			if ( ! isset( $this->pending['cat']['total'] ) ) {
				$this->pending['cat']['total'] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
			}
			$this->pending['cat']['total']['t'] += $duration_ms;
			++$this->pending['cat']['total']['n'];

			if ( $this->is_hub && '' !== $server_name ) {
				if ( ! isset( $this->pending['cat_by_server'][ $server_name ]['total'] ) ) {
					$this->pending['cat_by_server'][ $server_name ]['total'] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
				}
				$this->pending['cat_by_server'][ $server_name ]['total']['t'] += $duration_ms;
				++$this->pending['cat_by_server'][ $server_name ]['total']['n'];
			}

			if ( ! isset( $this->pending['cat_by_url'][ $url_hash ]['total'] ) ) {
				$this->pending['cat_by_url'][ $url_hash ]['total'] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
			}
			$this->pending['cat_by_url'][ $url_hash ]['total']['t'] += $duration_ms;
			++$this->pending['cat_by_url'][ $url_hash ]['total']['n'];

			// Per-server leaderboard (hub mode only).
			$slb = null;
			if ( $this->is_hub && '' !== $server_name ) {
				if ( ! isset( $this->pending['leaderboard_by_server'][ $server_name ] ) ) {
					$this->pending['leaderboard_by_server'][ $server_name ] = [
						'count'        => 0,
						'sum_req_time' => 0.0,
						'categories'   => [],
					];
				}
				/** @var array{count?: int, sum_req_time?: float|int, categories: array<string, array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>}>} $slb */
				$slb = &$this->pending['leaderboard_by_server'][ $server_name ];
			}

			foreach ( $profiles as $category => $data ) {
				if ( ! \is_string( $category ) || ! \is_array( $data ) ) {
					continue;
				}
				if ( ! $intern_full ) {
					$interned = $intern[ $category ] ??= $category;
					$category = \is_string( $interned ) ? $interned : $category;
					if ( \count( $intern ) >= 50000 ) {
						$intern_full = true;
					}
				}

				$is_callback = (bool) \preg_match( '/ @-?\d+$/', $category );
				$is_plugin   = (bool) \preg_match( '/ plugin$/', $category );

				$time_raw  = $data['time'] ?? 0;
				$count_raw = $data['count'] ?? 0;
				$ts_raw    = $data['ts'] ?? 0;
				$cat_time  = \is_numeric( $time_raw ) ? (float) $time_raw : 0.0;
				$cat_count = \is_numeric( $count_raw ) ? (int) $count_raw : 0;
				$cat_ts    = \is_numeric( $ts_raw ) ? (int) $ts_raw : 0;
				if ( ! $is_callback ) {
					$req_time += $cat_time;
				}

				// Per-URL category.
				if ( ! isset( $prof['categories'][ $category ] ) ) {
					$prof['categories'][ $category ] = [
						'samples'   => 0,
						'sum_time'  => 0.0,
						'sum_count' => 0.0,
						'ts'        => $cat_ts,
						'entries'   => [],
					];
				}
				/** @var array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>} $pcat */
				$pcat              = &$prof['categories'][ $category ];
				++$pcat['samples'];
				$pcat['sum_time']  += $cat_time;
				$pcat['sum_count'] += $cat_count;
				$pcat['ts']        = \max( $pcat['ts'] ?? 0, $cat_ts );

				// Global leaderboard category.
				if ( ! isset( $lb['categories'][ $category ] ) ) {
					$lb['categories'][ $category ] = [
						'samples'   => 0,
						'sum_time'  => 0.0,
						'sum_count' => 0.0,
						'entries'   => [],
					];
				}
				/** @var array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>} $lcat */
				$lcat              = &$lb['categories'][ $category ];
				++$lcat['samples'];
				$lcat['sum_time']  += $cat_time;
				$lcat['sum_count'] += $cat_count;

				// Per-server leaderboard.
				if ( null !== $slb ) {
					if ( ! isset( $slb['categories'][ $category ] ) ) {
						$slb['categories'][ $category ] = [
							'samples'   => 0,
							'sum_time'  => 0.0,
							'sum_count' => 0.0,
							'entries'   => [],
						];
					}
					/** @var array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>} $scat */
					$scat              = &$slb['categories'][ $category ];
					++$scat['samples'];
					$scat['sum_time']  += $cat_time;
					$scat['sum_count'] += $cat_count;

					$s_entries = $data['entries'] ?? null;
					if ( ! empty( $s_entries ) && \is_array( $s_entries ) ) {
						foreach ( $s_entries as $s_name => $s_entry_data ) {
							$s_name_interned = $intern[ $s_name ] ??= $s_name;
							$s_name  = \is_string( $s_name_interned ) ? $s_name_interned : (string) $s_name;
							$s_time  = \is_array( $s_entry_data ) && \is_numeric( $s_entry_data[0] ?? null ) ? (float) $s_entry_data[0] : 0.0;
							$s_count = \is_array( $s_entry_data ) && \is_numeric( $s_entry_data[1] ?? null ) ? (float) $s_entry_data[1] : 0.0;
							if ( ! isset( $scat['entries'][ $s_name ] ) ) {
								$scat['entries'][ $s_name ] = [ 0.0, 0.0, 0 ];
							}
							$scat['entries'][ $s_name ][0] += $s_time;
							$scat['entries'][ $s_name ][1] += $s_count;
							++$scat['entries'][ $s_name ][2];
						}
						if ( \count( $scat['entries'] ) > self::ENTRY_LIMIT_GLOBAL_UPPER ) {
							\uasort( $scat['entries'], fn( $a, $b ) => ( $b[0] ?? 0 ) <=> ( $a[0] ?? 0 ) );
							$scat['entries'] = \array_slice( $scat['entries'], 0, self::ENTRY_LIMIT_GLOBAL_LOWER, true );
						}
					}
					unset( $scat );
				}

				// Category time series (pending bucket).
				if ( ! isset( $this->pending['cat'][ $category ] ) ) {
					$this->pending['cat'][ $category ] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
				}
				$this->pending['cat'][ $category ]['t'] += $cat_time;
				$this->pending['cat'][ $category ]['c'] += $cat_count;
				++$this->pending['cat'][ $category ]['n'];
				$this->pending['cat']['total']['c'] += $cat_count;

				if ( $this->is_hub && '' !== $server_name ) {
					if ( ! isset( $this->pending['cat_by_server'][ $server_name ][ $category ] ) ) {
						$this->pending['cat_by_server'][ $server_name ][ $category ] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
					}
					$this->pending['cat_by_server'][ $server_name ][ $category ]['t'] += $cat_time;
					$this->pending['cat_by_server'][ $server_name ][ $category ]['c'] += $cat_count;
					++$this->pending['cat_by_server'][ $server_name ][ $category ]['n'];
					$this->pending['cat_by_server'][ $server_name ]['total']['c'] += $cat_count;
				}

				if ( ! isset( $this->pending['cat_by_url'][ $url_hash ][ $category ] ) ) {
					$this->pending['cat_by_url'][ $url_hash ][ $category ] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
				}
				$this->pending['cat_by_url'][ $url_hash ][ $category ]['t'] += $cat_time;
				$this->pending['cat_by_url'][ $url_hash ][ $category ]['c'] += $cat_count;
				++$this->pending['cat_by_url'][ $url_hash ][ $category ]['n'];
				$this->pending['cat_by_url'][ $url_hash ]['total']['c'] += $cat_count;

				// Significant event detection (avg time per call exceeds threshold).
				$time_threshold = $this->auto_protect_time_threshold;
				if ( ! $is_callback && ! $is_plugin && $time_threshold > 0 && $lcat['sum_count'] > 0 ) {
					$avg_per_call = $lcat['sum_time'] / $lcat['sum_count'];
					if ( $avg_per_call >= $time_threshold ) {
						$base_name = \explode( ' ', $category, 2 )[0];
						if ( ! isset( $this->significant_events[ $base_name ] ) ) {
							$this->significant_events[ $base_name ]     = true;
							$this->new_significant_events[ $base_name ] = true;
						}
					}
				}

				// Entry loop (per-URL + global).
				$entries = $data['entries'] ?? null;
				if ( ! empty( $entries ) && \is_array( $entries ) ) {
					foreach ( $entries as $name => $entry_data ) {
						$name_interned = $intern[ $name ] ??= $name;
						$name        = \is_string( $name_interned ) ? $name_interned : (string) $name;
						$entry_time  = \is_array( $entry_data ) && \is_numeric( $entry_data[0] ?? null ) ? (float) $entry_data[0] : 0.0;
						$entry_count = \is_array( $entry_data ) && \is_numeric( $entry_data[1] ?? null ) ? (float) $entry_data[1] : 0.0;

						if ( ! isset( $pcat['entries'][ $name ] ) ) {
							$pcat['entries'][ $name ] = [ 0.0, 0.0, 0 ];
						}
						$pcat['entries'][ $name ][0] += $entry_time;
						$pcat['entries'][ $name ][1] += $entry_count;
						++$pcat['entries'][ $name ][2];

						if ( ! isset( $lcat['entries'][ $name ] ) ) {
							$lcat['entries'][ $name ] = [ 0.0, 0.0, 0 ];
						}
						$lcat['entries'][ $name ][0] += $entry_time;
						$lcat['entries'][ $name ][1] += $entry_count;
						++$lcat['entries'][ $name ][2];
					}

					// Trim with hysteresis (cap by sum_time).
					if ( \count( $pcat['entries'] ) > self::ENTRY_LIMIT_URL_UPPER ) {
						\uasort( $pcat['entries'], fn( $a, $b ) => ( $b[0] ?? 0 ) <=> ( $a[0] ?? 0 ) );
						$pcat['entries'] = \array_slice( $pcat['entries'], 0, self::ENTRY_LIMIT_URL_LOWER, true );
					}
					if ( \count( $lcat['entries'] ) > self::ENTRY_LIMIT_GLOBAL_UPPER ) {
						\uasort( $lcat['entries'], fn( $a, $b ) => ( $b[0] ?? 0 ) <=> ( $a[0] ?? 0 ) );
						$lcat['entries'] = \array_slice( $lcat['entries'], 0, self::ENTRY_LIMIT_GLOBAL_LOWER, true );
					}
				}

				// Noisy detection. Significant-event filtering is performed at
				// apply_auto_tune time (matching upstream), not here.
				if ( ! $is_callback && ! $is_plugin && $count_threshold > 0 && $cat_count > $count_threshold ) {
					$base_name = \explode( ' ', $category, 2 )[0];
					if ( isset( $this->custom_event_names[ $base_name ] ) ) {
						$this->custom_events_to_disable[ $base_name ] = true;
					} else {
						$this->hooks_to_disable[ $base_name ] = true;
					}
				}
				unset( $pcat );
				unset( $lcat );
			}

			// Top-level sums.
			$prof['count']        = ( $prof['count']        ?? 0 ) + 1;
			$prof['sum_req_time'] = ( $prof['sum_req_time'] ?? 0 ) + $req_time;
			$lb['count']          = ( $lb['count']          ?? 0 ) + 1;
			$lb['sum_req_time']   = ( $lb['sum_req_time']   ?? 0 ) + $req_time;

			if ( null !== $slb ) {
				$slb['count']        = ( $slb['count']        ?? 0 ) + 1;
				$slb['sum_req_time'] = ( $slb['sum_req_time'] ?? 0 ) + $req_time;
				unset( $slb );
			}

			// Expire old per-URL categories.
			$cutoff = $now - 3600;
			foreach ( $prof['categories'] as $cat => $cd ) {
				if ( ( $cd['ts'] ?? 0 ) < $cutoff ) {
					unset( $prof['categories'][ $cat ] );
				}
			}
			$aggregate['profiles'] = $prof;
			unset( $lb );
		}

		$this->stats_cache->set( $url_hash, $aggregate );
	}

	// -------------------------------------------------------------------------
	// Persist (memcache write of the 9 namespaces).
	// -------------------------------------------------------------------------

	/**
	 * Persist combined aggregate stats (hourly, leaderboard, urls, dim, cat) to memcache.
	 */
	private function persist_aggregate_stats(): void {
		$stats_store = $this->stats_store;
		if ( null === $stats_store ) {
			return;
		}
		if (
			empty( $this->hourly_stats )
			&& empty( $this->leaderboard_stats )
			&& empty( $this->leaderboard_by_server_stats )
			&& empty( $this->url_stats )
			&& empty( $this->dim_stats )
			&& empty( $this->dim_stats_by_server )
			&& empty( $this->url_dim_stats )
			&& empty( $this->cat_stats )
			&& empty( $this->cat_stats_by_server )
			&& empty( $this->url_cat_stats )
		) {
			return;
		}

		// --- Hourly ---
		if ( ! empty( $this->hourly_stats ) ) {
			/** @var array<string, array{count: int, sum_ms: float|int, sum_peak_mb: float|int}> $existing_hourly */
			$existing_hourly = $stats_store->get_hourly();

			foreach ( $this->hourly_stats as $bucket_key => $stats ) {
				if ( ! isset( $existing_hourly[ $bucket_key ] ) ) {
					$existing_hourly[ $bucket_key ] = [
						'count'       => 0,
						'sum_ms'      => 0,
						'sum_peak_mb' => 0,
					];
				}
				$existing_hourly[ $bucket_key ]['count']       += \is_numeric( $stats['count'] ?? null ) ? $stats['count'] : 0;
				$existing_hourly[ $bucket_key ]['sum_ms']      += \is_numeric( $stats['sum_ms'] ?? null ) ? $stats['sum_ms'] : 0;
				$existing_hourly[ $bucket_key ]['sum_peak_mb'] += \is_numeric( $stats['sum_peak_mb'] ?? null ) ? $stats['sum_peak_mb'] : 0;
			}

			// Expire bucket data older than the retention window.
			$cutoff = $this->bucket_key( $this->now_ts() - $stats_store->ttl() );
			foreach ( \array_keys( $existing_hourly ) as $bucket_key ) {
				if ( $bucket_key < $cutoff ) {
					unset( $existing_hourly[ $bucket_key ] );
				}
			}
			\ksort( $existing_hourly );

			$stats_store->set_hourly( $existing_hourly );
		}

		// --- Leaderboard (bucketed, sums-based) ---
		foreach ( $this->leaderboard_stats as $bucket_key => $bucket_sums ) {
			$existing = $stats_store->get_leaderboard_bucket( $bucket_key );
			if ( empty( $existing ) ) {
				$existing = [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ];
			}
			Stats_Store::merge_leaderboard_bucket( $existing, $bucket_sums );
			$this->cap_leaderboard_entries( $existing );
			$stats_store->set_leaderboard_bucket( $bucket_key, $existing );
		}

		// --- Per-server leaderboards ---
		foreach ( $this->leaderboard_by_server_stats as $server => $buckets ) {
			foreach ( $buckets as $bucket_key => $bucket_sums ) {
				$existing = $stats_store->get_server_leaderboard_bucket( $server, $bucket_key );
				if ( empty( $existing ) ) {
					$existing = [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ];
				}
				Stats_Store::merge_leaderboard_bucket( $existing, $bucket_sums );
				$this->cap_leaderboard_entries( $existing );
				$stats_store->set_server_leaderboard_bucket( $server, $bucket_key, $existing );
			}
		}

		// --- URL index (hourly buckets) ---
		if ( ! empty( $this->url_stats ) ) {
			foreach ( $this->url_stats as $bucket_key => $hour_data ) {
				/** @var array<string, array<string, mixed>> $existing_urls */
				$existing_urls = $stats_store->get_url_index_hourly( $bucket_key );

				foreach ( $hour_data as $hash => $stats_raw ) {
					$stats = \is_array( $stats_raw ) ? $stats_raw : [];
					if ( ! isset( $existing_urls[ $hash ] ) ) {
						$existing_urls[ $hash ] = [
							'url'         => $stats['url'] ?? '',
							'count'       => 0,
							'timed_count' => 0,
							'sum_ms'      => 0,
							'min_ms'      => 0,
							'max_ms'      => 0,
							'last_seen'   => 0,
							'durations'   => [],
							'count_2xx'   => 0,
							'count_3xx'   => 0,
							'count_4xx'   => 0,
							'count_5xx'   => 0,
							'sum_peak_mb' => 0,
							'max_peak_mb' => 0,
						];
					}
					/** @var array{url: string, count: int, timed_count: int, sum_ms: float|int, min_ms: float|int, max_ms: float|int, last_seen: int, durations: array<int, float|int>, count_2xx: int, count_3xx: int, count_4xx: int, count_5xx: int, sum_peak_mb: float|int, max_peak_mb: float|int} $e */
					$e               = &$existing_urls[ $hash ];
					$e['count']      += \is_numeric( $stats['count'] ?? null ) ? $stats['count'] : 0;
					$e['timed_count'] += \is_numeric( $stats['timed_count'] ?? null ) ? $stats['timed_count'] : 0;
					$e['sum_ms']     += \is_numeric( $stats['sum_ms'] ?? null ) ? $stats['sum_ms'] : 0;
					// Only fold min_ms from buckets that have timing; untimed-only
					// buckets carry the PHP_INT_MAX sentinel, which must never enter
					// the persisted index (leaves min_ms at 0 for untimed-only URLs).
					$s_min_ms   = \is_numeric( $stats['min_ms'] ?? null ) ? $stats['min_ms'] : 0;
					$s_max_ms   = \is_numeric( $stats['max_ms'] ?? null ) ? $stats['max_ms'] : 0;
					$s_last     = \is_numeric( $stats['last_seen'] ?? null ) ? $stats['last_seen'] : 0;
					if ( ( \is_numeric( $stats['timed_count'] ?? null ) ? $stats['timed_count'] : 0 ) > 0 ) {
						$e['min_ms'] = ( 0 === $e['min_ms'] ) ? $s_min_ms : \min( $e['min_ms'], $s_min_ms );
					}
					$e['max_ms']     = \max( $e['max_ms'], $s_max_ms );
					$e['last_seen']  = \max( $e['last_seen'], $s_last );
					$e['count_2xx'] += \is_numeric( $stats['count_2xx'] ?? null ) ? $stats['count_2xx'] : 0;
					$e['count_3xx'] += \is_numeric( $stats['count_3xx'] ?? null ) ? $stats['count_3xx'] : 0;
					$e['count_4xx'] += \is_numeric( $stats['count_4xx'] ?? null ) ? $stats['count_4xx'] : 0;
					$e['count_5xx'] += \is_numeric( $stats['count_5xx'] ?? null ) ? $stats['count_5xx'] : 0;
					$e['sum_peak_mb'] += \is_numeric( $stats['sum_peak_mb'] ?? null ) ? $stats['sum_peak_mb'] : 0;
					$e['max_peak_mb']  = \max( $e['max_peak_mb'], \is_numeric( $stats['max_peak_mb'] ?? null ) ? $stats['max_peak_mb'] : 0 );

					$max_dur     = Stats_Store::MAX_DURATIONS_PER_BUCKET;
					$s_durations = \is_array( $stats['durations'] ?? null ) ? $stats['durations'] : [];
					$merged      = \array_merge( $e['durations'], $s_durations );
					if ( \count( $merged ) > $max_dur ) {
						\shuffle( $merged );
						$merged = \array_slice( $merged, 0, $max_dur );
					}
					$e['durations'] = $merged;
					unset( $e );
				}

				// Compute percentiles for all URLs in this hour.
				foreach ( $existing_urls as &$url_stat ) {
					if ( ! empty( $url_stat['durations'] ) && \is_array( $url_stat['durations'] ) ) {
						$sorted = $url_stat['durations'];
						\sort( $sorted );
						$n = \count( $sorted );
						$url_stat['p50_ms'] = $sorted[ (int) ( $n * 0.50 ) ] ?? 0;
						$url_stat['p95_ms'] = $sorted[ (int) ( $n * 0.95 ) ] ?? 0;
						$url_stat['p99_ms'] = $sorted[ (int) ( $n * 0.99 ) ] ?? 0;
						$tc_raw = $url_stat['timed_count'] ?? $url_stat['count'] ?? 0;
						$tc     = \is_numeric( $tc_raw ) ? $tc_raw : 0;
						$sum_ms = \is_numeric( $url_stat['sum_ms'] ?? null ) ? $url_stat['sum_ms'] : 0;
						$url_stat['avg_ms'] = $tc > 0 ? $sum_ms / $tc : 0;
					}
				}
				unset( $url_stat );

				if ( \count( $existing_urls ) > 500 ) {
					\uasort( $existing_urls, fn( $a, $b ) => ( \is_numeric( $b['count'] ?? null ) ? $b['count'] : 0 ) <=> ( \is_numeric( $a['count'] ?? null ) ? $a['count'] : 0 ) );
					$existing_urls = \array_slice( $existing_urls, 0, 500, true );
				}

				$stats_store->set_url_index_hourly( $bucket_key, $existing_urls );
			}
		}

		// --- Dimensional (global, per-server, per-URL) ---
		$cutoff = $this->bucket_key( $this->now_ts() - $stats_store->ttl() );
		foreach ( $this->dim_stats as $dim => $buckets ) {
			$existing = $stats_store->get_dimensional( $dim );
			$this->merge_and_cap_dimensional( $existing, $buckets, $cutoff );
			$stats_store->set_dimensional( $dim, $existing );
		}
		foreach ( $this->dim_stats_by_server as $server => $dims ) {
			foreach ( $dims as $dim => $buckets ) {
				$existing = $stats_store->get_dimensional( $dim, $server );
				$this->merge_and_cap_dimensional( $existing, $buckets, $cutoff );
				$stats_store->set_dimensional( $dim, $existing, $server );
			}
		}
		foreach ( $this->url_dim_stats as $url_hash => $dims ) {
			$existing = $stats_store->get_url_dimensional( $url_hash );
			foreach ( $dims as $dim => $buckets ) {
				$dim_existing = isset( $existing[ $dim ] ) && \is_array( $existing[ $dim ] ) ? $existing[ $dim ] : [];
				$this->merge_and_cap_dimensional( $dim_existing, $buckets, $cutoff, Stats_Store::MAX_URL_DIM_VALUES );
				$existing[ $dim ] = $dim_existing;
			}
			$stats_store->set_url_dimensional( $url_hash, $existing );
		}

		// --- Category time series (global, per-server, per-URL) ---
		if ( ! empty( $this->cat_stats ) ) {
			$existing_cats = $stats_store->get_categories();
			$this->merge_and_cap_categories( $existing_cats, $this->cat_stats, $cutoff );
			$stats_store->set_categories( $existing_cats );
		}
		foreach ( $this->cat_stats_by_server as $server => $buckets ) {
			$existing = $stats_store->get_server_categories( $server );
			$this->merge_and_cap_categories( $existing, $buckets, $cutoff );
			$stats_store->set_server_categories( $server, $existing );
		}
		foreach ( $this->url_cat_stats as $url_hash => $buckets ) {
			$existing_url_cats = $stats_store->get_url_categories( $url_hash );
			$this->merge_and_cap_categories( $existing_url_cats, $buckets, $cutoff );
			$stats_store->set_url_categories( $url_hash, $existing_url_cats );
		}
	}

	/**
	 * Cap each leaderboard category's entries to the global limit (sorted by sum_time).
	 *
	 * @param array<string, mixed> $bucket Leaderboard bucket (modified by reference).
	 */
	private function cap_leaderboard_entries( array &$bucket ): void {
		$categories = $bucket['categories'] ?? null;
		if ( ! \is_array( $categories ) ) {
			return;
		}
		foreach ( $categories as &$cat_data ) {
			if ( ! \is_array( $cat_data ) ) {
				continue;
			}
			$entries = $cat_data['entries'] ?? null;
			if ( \is_array( $entries ) && \count( $entries ) > self::ENTRY_LIMIT_GLOBAL_UPPER ) {
				\uasort( $entries, fn( $a, $b ) => ( \is_array( $b ) ? ( $b[0] ?? 0 ) : 0 ) <=> ( \is_array( $a ) ? ( $a[0] ?? 0 ) : 0 ) );
				$cat_data['entries'] = \array_slice( $entries, 0, self::ENTRY_LIMIT_GLOBAL_LOWER, true );
			}
		}
		unset( $cat_data );
		$bucket['categories'] = $categories;
	}

	/**
	 * Merge incoming dimensional buckets into existing, expire old, and cap.
	 *
	 * @param array<string, mixed> $existing Existing buckets (modified by reference).
	 * @param array<string, mixed> $buckets  Incoming buckets to merge.
	 */
	private function merge_and_cap_dimensional( array &$existing, array $buckets, string $cutoff, int $max_values = 0 ): void {
		if ( 0 === $max_values ) {
			$max_values = Stats_Store::MAX_DIM_VALUES;
		}
		foreach ( $buckets as $bk => $values ) {
			if ( ! \is_array( $values ) ) {
				continue;
			}
			$bucket = \is_array( $existing[ $bk ] ?? null ) ? $existing[ $bk ] : [];
			foreach ( $values as $val => $stats ) {
				if ( ! \is_array( $stats ) ) {
					continue;
				}
				$cur          = \is_array( $bucket[ $val ] ?? null ) ? $bucket[ $val ] : [];
				$cur['c']     = ( \is_numeric( $cur['c'] ?? null ) ? $cur['c'] : 0 ) + ( \is_numeric( $stats['c'] ?? null ) ? $stats['c'] : 0 );
				$cur['s']     = ( \is_numeric( $cur['s'] ?? null ) ? $cur['s'] : 0 ) + ( \is_numeric( $stats['s'] ?? null ) ? $stats['s'] : 0 );
				$cur['m']     = ( \is_numeric( $cur['m'] ?? null ) ? $cur['m'] : 0 ) + ( \is_numeric( $stats['m'] ?? null ) ? $stats['m'] : 0 );
				$bucket[ $val ] = $cur;
			}
			$existing[ $bk ] = $bucket;
		}
		foreach ( \array_keys( $existing ) as $bk ) {
			if ( $bk < $cutoff ) {
				unset( $existing[ $bk ] );
			}
		}
		foreach ( $existing as $bk => $bk_values_raw ) {
			if ( ! \is_array( $bk_values_raw ) ) {
				continue;
			}
			$bk_values = $bk_values_raw;
			if ( \count( $bk_values ) > $max_values ) {
				\uasort( $bk_values, fn( $a, $b ) => ( \is_array( $b ) && \is_numeric( $b['c'] ?? null ) ? $b['c'] : 0 ) <=> ( \is_array( $a ) && \is_numeric( $a['c'] ?? null ) ? $a['c'] : 0 ) );
				$top    = \array_slice( $bk_values, 0, $max_values - 1, true );
				$rest_c = $rest_s = $rest_m = 0;
				foreach ( \array_slice( $bk_values, $max_values - 1 ) as $v ) {
					if ( ! \is_array( $v ) ) {
						continue;
					}
					$rest_c += \is_numeric( $v['c'] ?? null ) ? $v['c'] : 0;
					$rest_s += \is_numeric( $v['s'] ?? null ) ? $v['s'] : 0;
					$rest_m += \is_numeric( $v['m'] ?? null ) ? $v['m'] : 0;
				}
				$top['Other']      = [ 'c' => $rest_c, 's' => $rest_s, 'm' => $rest_m ];
				$existing[ $bk ]   = $top;
			}
		}
		\ksort( $existing );
	}

	/**
	 * Merge incoming category buckets into existing, expire old, and cap.
	 *
	 * 'total' pseudo-category preserved before sort; overflow rolls into 'Other'.
	 *
	 * @param array<string, mixed> $existing Existing buckets (modified by reference).
	 * @param array<string, mixed> $buckets  Incoming buckets to merge.
	 */
	private function merge_and_cap_categories( array &$existing, array $buckets, string $cutoff, int $max_values = 0 ): void {
		if ( 0 === $max_values ) {
			$max_values = Stats_Store::MAX_CAT_VALUES;
		}
		foreach ( $buckets as $bk => $categories ) {
			if ( ! \is_array( $categories ) ) {
				continue;
			}
			$bucket = \is_array( $existing[ $bk ] ?? null ) ? $existing[ $bk ] : [];
			foreach ( $categories as $cat => $stats ) {
				if ( ! \is_array( $stats ) ) {
					continue;
				}
				$cur          = \is_array( $bucket[ $cat ] ?? null ) ? $bucket[ $cat ] : [];
				$cur['t']     = ( \is_numeric( $cur['t'] ?? null ) ? $cur['t'] : 0 ) + ( \is_numeric( $stats['t'] ?? null ) ? $stats['t'] : 0 );
				$cur['c']     = ( \is_numeric( $cur['c'] ?? null ) ? $cur['c'] : 0 ) + ( \is_numeric( $stats['c'] ?? null ) ? $stats['c'] : 0 );
				$cur['n']     = ( \is_numeric( $cur['n'] ?? null ) ? $cur['n'] : 0 ) + ( \is_numeric( $stats['n'] ?? null ) ? $stats['n'] : 0 );
				$bucket[ $cat ] = $cur;
			}
			$existing[ $bk ] = $bucket;
		}
		foreach ( \array_keys( $existing ) as $bk ) {
			if ( $bk < $cutoff ) {
				unset( $existing[ $bk ] );
			}
		}
		foreach ( $existing as $bk => $bk_cats_raw ) {
			if ( ! \is_array( $bk_cats_raw ) ) {
				continue;
			}
			$bk_cats = $bk_cats_raw;
			if ( \count( $bk_cats ) > $max_values ) {
				$total = $bk_cats['total'] ?? null;
				unset( $bk_cats['total'] );
				\uasort( $bk_cats, fn( $a, $b ) => ( \is_array( $b ) ? ( $b['t'] ?? 0 ) : 0 ) <=> ( \is_array( $a ) ? ( $a['t'] ?? 0 ) : 0 ) );
				$top    = \array_slice( $bk_cats, 0, $max_values - 2, true );
				$rest_t = $rest_c = $rest_n = 0;
				foreach ( \array_slice( $bk_cats, $max_values - 2 ) as $v ) {
					if ( ! \is_array( $v ) ) {
						continue;
					}
					$rest_t += \is_numeric( $v['t'] ?? null ) ? $v['t'] : 0;
					$rest_c += \is_numeric( $v['c'] ?? null ) ? $v['c'] : 0;
					$rest_n += \is_numeric( $v['n'] ?? null ) ? $v['n'] : 0;
				}
				if ( $rest_t > 0 || $rest_c > 0 ) {
					$top['Other'] = [ 't' => $rest_t, 'c' => $rest_c, 'n' => $rest_n ];
				}
				if ( $total ) {
					$top['total'] = $total;
				}
				$existing[ $bk ] = $top;
			}
		}
		\ksort( $existing );
	}

	// -------------------------------------------------------------------------
	// Pending-bucket promotion + reset.
	// -------------------------------------------------------------------------

	/**
	 * Move pending-bucket data into the flush arrays (caps applied at this stage
	 * for category data, since the bucket is now complete).
	 */
	private function promote_pending_bucket(): void {
		$bk       = $this->pending_bucket;
		$max_cats = Stats_Store::MAX_CAT_VALUES;

		// Hourly.
		if ( ! empty( $this->pending['hourly'] ) ) {
			$this->hourly_stats[ $bk ] = $this->pending['hourly'];
		}

		// URL stats.
		if ( ! empty( $this->pending['url_stats'] ) ) {
			$this->url_stats[ $bk ] = $this->pending['url_stats'];
		}

		// Dimensional — global.
		foreach ( $this->pending['dim'] ?? [] as $dim => $values ) {
			$this->dim_stats[ $dim ][ $bk ] = $values;
		}

		// Dimensional — per-server.
		foreach ( $this->pending['dim_by_server'] ?? [] as $server => $dims ) {
			foreach ( $dims as $dim => $values ) {
				$this->dim_stats_by_server[ $server ][ $dim ][ $bk ] = $values;
			}
		}

		// Dimensional — per-URL.
		foreach ( $this->pending['url_dim'] ?? [] as $url_hash => $dims ) {
			foreach ( $dims as $dim => $values ) {
				$this->url_dim_stats[ $url_hash ][ $dim ][ $bk ] = $values;
			}
		}

		// Category — global, capped.
		if ( ! empty( $this->pending['cat'] ) ) {
			$this->cat_stats[ $bk ] = self::cap_single_bucket( $this->pending['cat'], $max_cats );
		}

		// Category — per-server, capped.
		foreach ( $this->pending['cat_by_server'] ?? [] as $server => $cats ) {
			$this->cat_stats_by_server[ $server ][ $bk ] = self::cap_single_bucket( $cats, $max_cats );
		}

		// Category — per-URL, capped.
		foreach ( $this->pending['cat_by_url'] ?? [] as $url_hash => $cats ) {
			$this->url_cat_stats[ $url_hash ][ $bk ] = self::cap_single_bucket( $cats, $max_cats );
		}

		// Leaderboard — global.
		if ( ( $this->pending['leaderboard']['count'] ?? 0 ) > 0 ) {
			if ( ! isset( $this->leaderboard_stats[ $bk ] ) ) {
				$this->leaderboard_stats[ $bk ] = [
					'count'        => 0,
					'sum_req_time' => 0.0,
					'categories'   => [],
				];
			}
			Stats_Store::merge_leaderboard_bucket( $this->leaderboard_stats[ $bk ], $this->pending['leaderboard'] );
		}

		// Leaderboard (per-server).
		foreach ( $this->pending['leaderboard_by_server'] ?? [] as $server => $slb_data ) {
			if ( ( $slb_data['count'] ?? 0 ) <= 0 ) {
				continue;
			}
			if ( ! isset( $this->leaderboard_by_server_stats[ $server ][ $bk ] ) ) {
				$this->leaderboard_by_server_stats[ $server ][ $bk ] = [
					'count'        => 0,
					'sum_req_time' => 0.0,
					'categories'   => [],
				];
			}
			Stats_Store::merge_leaderboard_bucket( $this->leaderboard_by_server_stats[ $server ][ $bk ], $slb_data );
		}

		$this->reset_pending();
	}

	/**
	 * Cap a single bucket's categories to top N by time, preserving 'total'.
	 *
	 * @param array<string, mixed> $cats Category buckets.
	 * @return array<string, mixed>
	 */
	private static function cap_single_bucket( array $cats, int $max_values ): array {
		if ( \count( $cats ) <= $max_values ) {
			return $cats;
		}
		$total = $cats['total'] ?? null;
		unset( $cats['total'] );
		\uasort( $cats, fn( $a, $b ) => ( \is_array( $b ) ? ( $b['t'] ?? 0 ) : 0 ) <=> ( \is_array( $a ) ? ( $a['t'] ?? 0 ) : 0 ) );
		$top    = \array_slice( $cats, 0, $max_values - 2, true );
		$rest_t = $rest_c = $rest_n = 0;
		foreach ( \array_slice( $cats, $max_values - 2 ) as $v ) {
			if ( ! \is_array( $v ) ) {
				continue;
			}
			$rest_t += \is_numeric( $v['t'] ?? null ) ? $v['t'] : 0;
			$rest_c += \is_numeric( $v['c'] ?? null ) ? $v['c'] : 0;
			$rest_n += \is_numeric( $v['n'] ?? null ) ? $v['n'] : 0;
		}
		if ( $rest_t > 0 || $rest_c > 0 ) {
			$top['Other'] = [ 't' => $rest_t, 'c' => $rest_c, 'n' => $rest_n ];
		}
		if ( $total ) {
			$top['total'] = $total;
		}
		return $top;
	}

	private function reset_pending(): void {
		$this->pending = [
			'hourly'                => [],
			'dim'                   => [],
			'dim_by_server'         => [],
			'url_dim'               => [],
			'url_stats'             => [],
			'cat'                   => [],
			'cat_by_server'         => [],
			'cat_by_url'            => [],
			'leaderboard'           => [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ],
			'leaderboard_by_server' => [],
		];
	}

	// -------------------------------------------------------------------------
	// Per-URL flame merge + finalize.
	// -------------------------------------------------------------------------

	/**
	 * Merge child nodes from a per-request flame into the per-URL aggregate
	 * children additively (sums-not-means). Each node carries `sum_value` (sum
	 * of inclusive durations across every request the node was seen in) and
	 * `seen_count` (true count of those requests). Display values come from
	 * finalize at flush time (sum_value / total_count).
	 *
	 * @param array<int, mixed> $existing Existing aggregate children (list).
	 * @param array<int, mixed> $incoming Incoming per-request children (list).
	 * @return array<int, mixed>
	 */
	private static function merge_flame_children_incremental( array $existing, array $incoming, int $now_ts, int $depth = 0 ): array {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return $existing;
		}

		/** @var array<string, array<string, mixed>> $indexed */
		$indexed = [];
		foreach ( $existing as $child ) {
			if ( ! \is_array( $child ) ) {
				continue;
			}
			$child_name = \is_string( $child['name'] ?? null ) ? $child['name'] : 'unknown';
			$indexed[ $child_name ] = $child;
		}

		foreach ( $incoming as $child ) {
			if ( ! \is_array( $child ) ) {
				continue;
			}
			$name           = \is_string( $child['name'] ?? null ) ? $child['name'] : 'unknown';
			$child_ts       = \is_numeric( $child['ts'] ?? null ) ? (int) $child['ts'] : $now_ts;
			$incoming_value = \is_numeric( $child['value'] ?? null ) ? (float) $child['value'] : 0.0;
			if ( ! isset( $indexed[ $name ] ) ) {
				$indexed[ $name ] = [
					'name'       => $name,
					'sum_value'  => $incoming_value,
					'seen_count' => 1,
					'ts'         => $child_ts,
					'children'   => [],
				];
			} else {
				$indexed[ $name ]['seen_count'] = ( \is_numeric( $indexed[ $name ]['seen_count'] ?? null ) ? $indexed[ $name ]['seen_count'] : 0 ) + 1;
				$indexed[ $name ]['ts']         = $child_ts;
				$indexed[ $name ]['sum_value']  = ( \is_numeric( $indexed[ $name ]['sum_value'] ?? null ) ? $indexed[ $name ]['sum_value'] : 0 ) + $incoming_value;
			}

			$child_children   = $child['children'] ?? null;
			$indexed_children = $indexed[ $name ]['children'] ?? [];
			if ( ! empty( $child_children ) && \is_array( $child_children ) ) {
				$indexed[ $name ]['children'] = self::merge_flame_children_incremental(
					\array_values( \is_array( $indexed_children ) ? $indexed_children : [] ),
					\array_values( $child_children ),
					$now_ts,
					$depth + 1
				);
			}
		}

		// Expire entries not seen in over 1 hour.
		$cutoff = $now_ts - 3600;
		foreach ( $indexed as $name => $child ) {
			if ( ( $child['ts'] ?? 0 ) < $cutoff ) {
				unset( $indexed[ $name ] );
			}
		}

		return \array_values( $indexed );
	}

	/**
	 * Finalize a flame node for display: convert sums to averages, strip
	 * suffixes, normalize parent ≥ children, and remove internal fields.
	 *
	 * @param array<string, mixed> $node Flame node (modified by reference).
	 */
	public static function finalize_flame_node( array &$node, int $total_count, int $depth = 0 ): void {
		if ( $depth > self::MAX_RECURSION_DEPTH ) {
			return;
		}

		// Strip hidden sequence suffix (\x00N) used for duplicate sibling tracking.
		$name     = \is_string( $node['name'] ?? null ) ? $node['name'] : 'unknown';
		$null_pos = \strpos( $name, "\x00" );
		if ( false !== $null_pos ) {
			$node['name'] = \substr( $name, 0, $null_pos );
		}

		// Convert sum to average across all requests for this URL.
		if ( $total_count > 0 && isset( $node['sum_value'] ) ) {
			$node['value'] = ( \is_numeric( $node['sum_value'] ) ? $node['sum_value'] : 0 ) / $total_count;
		} elseif ( ! isset( $node['value'] ) ) {
			$node['value'] = 0;
		}

		if ( ! empty( $node['children'] ) && \is_array( $node['children'] ) ) {
			foreach ( $node['children'] as &$child ) {
				if ( \is_array( $child ) ) {
					/** @var array<string, mixed> $child */
					self::finalize_flame_node( $child, $total_count, $depth + 1 );
				}
			}
			unset( $child );

			// Normalize: ensure parent value >= sum of children.
			$children_sum = 0;
			foreach ( $node['children'] as $child ) {
				$children_sum += \is_array( $child ) && \is_numeric( $child['value'] ?? null ) ? $child['value'] : 0;
			}
			$node_value = \is_numeric( $node['value'] ) ? $node['value'] : 0;
			if ( $children_sum > $node_value ) {
				$node['value'] = $children_sum;
			}
		}

		unset( $node['ts'] );
		unset( $node['sum_value'] );
		unset( $node['seen_count'] );
	}

	// -------------------------------------------------------------------------
	// Auto-tune: noisy hooks + significant events with distributed-lock.
	// -------------------------------------------------------------------------

	/**
	 * Apply auto-disable decisions: persist hooks/events to disable and newly
	 * discovered significant events. Uses memcache add() as a 5s distributed
	 * lock to prevent races between FlameBuilder workers.
	 */
	private function apply_auto_tune(): void {
		if (
			empty( $this->hooks_to_disable )
			&& empty( $this->custom_events_to_disable )
			&& empty( $this->new_significant_events )
		) {
			return;
		}

		// In test mode (no store), fire the actions but skip the lock dance.
		if ( null === $this->stats_store ) {
			$this->fire_auto_tune_actions();
			return;
		}

		$cache        = Core::$memd;
		$lock_key     = 'evlog:auto_disable_lock';
		$lock_timeout = 5;
		$lock_value   = \bin2hex( \random_bytes( 8 ) );

		// No shared handle → skip the cross-worker lock and just fire (single-process).
		if ( null === $cache ) {
			$this->fire_auto_tune_actions();
			return;
		}

		if ( ! $cache->add( $lock_key, $lock_value, $lock_timeout ) ) {
			return; // Lock held by another worker; retry on next flush.
		}

		try {
			$this->fire_auto_tune_actions();
		} finally {
			// Only release if we still own the lock.
			$current = $cache->get( $lock_key );
			if ( $current === $lock_value ) {
				$cache->delete( $lock_key );
			}
		}
	}

	private function fire_auto_tune_actions(): void {
		$this->emit_auto_tune( 'disable_hooks',          \array_keys( $this->hooks_to_disable ) );
		$this->emit_auto_tune( 'disable_custom_events',  \array_keys( $this->custom_events_to_disable ) );
		$this->emit_auto_tune( 'add_significant_events', \array_keys( $this->new_significant_events ) );

		$this->hooks_to_disable         = [];
		$this->custom_events_to_disable = [];
		$this->new_significant_events   = [];
	}

	/**
	 * Send an auto-tune decision downstream as a Message. The Router (primary
	 * sink) delivers it to the AutoTuner Node named 'auto-tuner', which
	 * applies it locally and (on hubs) fans out via JobIntake.
	 *
	 * @param string             $key   'disable_hooks' | 'disable_custom_events' | 'add_significant_events'
	 * @param array<int, string> $items Hook/event names — already deduped at the caller.
	 */
	private function emit_auto_tune( string $key, array $items ): void {
		$sink = $this->sink;
		if ( empty( $items ) || null === $sink ) {
			return;
		}
		// Auto-tune decisions are rare and important — narrate so
		// debug_state on flame-builder surfaces every fire.
		$this->set_state(
			'AUTO_TUNE_FIRED',
			[ 'key' => $key, 'count' => \count( $items ) ]
		);
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::TO ]        = $this->name . ':auto-tuner';
		$msg[ Message::KEY ]       = $key;
		$msg[ Message::VALUE ]     = [
			'items'   => $items,
			'context' => [ 'significant_events' => $this->significant_events ],
		];
		$sink->fill( $msg );
	}

	// -------------------------------------------------------------------------
	// Bucket-key helper.
	// -------------------------------------------------------------------------

	/**
	 * 5-min bucket key from a Unix timestamp.
	 */
	private function bucket_key( int $timestamp ): string {
		$min        = (int) \gmdate( 'i', $timestamp );
		$bucket_min = \str_pad( (string) ( (int) \floor( $min / self::BUCKET_MINUTES ) * self::BUCKET_MINUTES ), 2, '0', STR_PAD_LEFT );
		return \gmdate( 'Y-m-d-H', $timestamp ) . '-' . $bucket_min;
	}

	// -------------------------------------------------------------------------
	// Sibling-interpreter verb table + node_schema (A3).
	// -------------------------------------------------------------------------

	/** @param array<int, mixed> $message Incoming command Message. */
	private function handle_request( array $message ): void {
		$value_raw = $message[ Message::VALUE ];
		$value     = \is_scalar( $value_raw ) ? (string) $value_raw : '';
		$verb      = \strtoupper( \explode( ' ', \trim( $value ), 2 )[0] );

		if ( 'GET_STATS' === $verb ) {
			$stats_count = 0;
			foreach ( $this->stats_cache->iterate() as $_ ) {
				++$stats_count;
			}
			$now = ( Core::$now ?: \microtime( true ) );
			$payload = [
				'stats_count'              => $stats_count,
				'pending_url_count'        => \count( $this->pending ),
				'pending_bucket'           => $this->pending_bucket,
				'last_flush_age_s'         => $this->last_flush_time > 0 ? (int) ( $now - $this->last_flush_time ) : null,
				'auto_tune_pending_count'  => \count( $this->hooks_to_disable ) + \count( $this->custom_events_to_disable ) + \count( $this->new_significant_events ),
				'is_hub'                   => $this->is_hub,
				'significant_events_count' => \count( $this->significant_events ),
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
		$reply[ Message::VALUE ] = [ 'verb' => $verb, 'data' => $payload ];
		$this->sink?->fill( $reply );
	}

	public static function node_schema(): array {
		return [
			'category'    => 'Transform',
			'description' => 'Aggregates per-event count + sum_time into the 9-namespace memcache schema; emits flame JSONL.',
			'arguments'        => [],
			'commands'       => [
				[
					'name'        => 'set_is_hub',
					'description' => 'Toggle hub mode (per-server tracking).',
					'args'        => [
						[ 'name' => 'is_hub', 'type' => 'bool', 'required' => true, 'default' => '<config:is_hub>' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						$args = \strtolower( \trim( $args ) );
						$bool = ( 'true' === $args || '1' === $args );
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_is_hub( $bool );
						return 'ok';
					},
				],
				[
					'name'        => 'set_auto_tune',
					'description' => 'Auto-disable / auto-protect thresholds.',
					'args'        => [
						[ 'name' => 'count_threshold', 'type' => 'int',   'required' => true, 'default' => '<config:auto_disable_threshold>' ],
						[ 'name' => 'time_threshold',  'type' => 'float', 'required' => true, 'default' => '<config:auto_protect_time_threshold>' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						// Constant valid pattern: preg_split never returns false here.
						/** @var list<string> $parts */
						$parts = \preg_split( '/\s+/', \trim( $args ) );
						if ( \count( $parts ) < 2 ) {
							return 'usage: set_auto_tune <count_threshold> <time_threshold>';
						}
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_auto_tune( (int) $parts[0], (float) $parts[1] );
						return 'ok';
					},
				],
				[
					'name'        => 'set_significant_events',
					'description' => 'Comma-separated list of event names to always preserve.',
					'args'        => [
						[ 'name' => 'names', 'type' => 'string', 'required' => false, 'default' => '<config:significant_events_csv>' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						$args = \trim( $args );
						$list = '' === $args
							? []
							: \array_values( \array_filter( \array_map( 'trim', \explode( ',', $args ) ), static fn ( $v ) => (bool) $v ) );
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_significant_events( $list );
						return 'ok';
					},
				],
				[
					'name'        => 'configure_stats',
					'description' => 'Build the Stats_Store from substrate config (memcache + retention).',
					'args'        => [
						[ 'name' => 'partition', 'type' => 'int', 'required' => true, 'default' => '<partition>' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						// Constant valid pattern: preg_split never returns false here.
						/** @var list<string> $parts */
						$parts = \preg_split( '/\s+/', \trim( $args ) );
						if ( \count( $parts ) < 1 || '' === $parts[0] ) {
							return 'usage: configure_stats <partition>';
						}
						$partition = (int) $parts[0];

						// Read current substrate config for retention; the store reads
						// the shared Core::$memd handle directly.
						$config           = \Newspack_Event_Logger_Nodes\Config::load_config();
						$max_lifespan_raw = $config['max_lifespan'] ?? 86400;
						$max_lifespan     = \is_numeric( $max_lifespan_raw ) ? (int) $max_lifespan_raw : 86400;

						$stats_store = new \Newspack_Event_Logger_Nodes\Stats_Store( $partition, $max_lifespan );

						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_stats_store( $stats_store );
						return 'ok';
					},
				],
			],
			'requests'    => [
				[
					'name'        => 'GET_STATS',
					'description' => 'Stats cache + pending bucket + auto-tune queue depth.',
					'reply_shape' => '{ stats_count, pending_url_count, pending_bucket, last_flush_age_s, auto_tune_pending_count, is_hub, significant_events_count }',
				],
			],
		];
	}
}
