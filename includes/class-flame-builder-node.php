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
	use \Newspack_Nodes\Schema_Reflection;
	use \Newspack_Nodes\Deferred_Clean_Stop;

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
	const ENTRY_LIMIT_GLOBAL_LOWER = 50;
	const ENTRY_LIMIT_GLOBAL_UPPER = 100;
	const ENTRY_LIMIT_URL_LOWER    = 20;

	/** Entry limits with hysteresis: only trim when upper limit hit, trim to lower limit. */
	const ENTRY_LIMIT_URL_UPPER    = 40;

	const FLUSH_INTERVAL_SEC = 5;

	/** Category entries unseen for this many seconds expire (1 hour). Keep in sync with Flame_Tree. */
	private const AGGREGATE_EXPIRY_SEC = 3600;

	/** Minutes per time-series bucket. */
	private const BUCKET_MINUTES = 5;

	/** Cap on the per-process string-intern table (dedupes json_decode zvals). */
	private const INTERN_TABLE_LIMIT = 50000;


	/** Max URLs retained per hourly bucket in the URL index (top-N by count). */
	private const MAX_URLS_PER_BUCKET = 500;

	/** LRU cache for per-URL stats accumulators. */
	private const STATS_CACHE_BUCKET_SIZE = 1000;
	private const STATS_CACHE_NUM_BUCKETS = 5;

	/** Per-URL namespaces bounded to top-N by traffic when mirrored. NS_URL (the
	 *  flame profiles) is the STARTING default only — the live bound is $flame_topn
	 *  (see set_flame_topn), so the routing check here still recognizes NS_URL. */
	private const STATS_MIRROR_TOPN = [
		Stats_Store::NS_URL     => 0,    // flame profiles — see $flame_topn
		Stats_Store::NS_URL_DIM => 100,  // per-URL dimensional
		Stats_Store::NS_URL_CAT => 100,  // per-URL categories
	];

	/** @var Auto_Tuner_Node|null Owned sibling — receives auto-tune decisions. */
	private ?Auto_Tuner_Node $auto_tuner = null;
	/** @var array<string, array<string, mixed>> Bucket → category accumulator. */
	private $cat_stats                   = [];
	/** @var array<string, array<string, array<string, mixed>>> Server → bucket → category accumulator. */
	private $cat_stats_by_server         = [];


	/** @var (callable(): int)|null Test seam: clock function for bucket-key derivation. */
	private $clock_fn = null;
	/** @var array<string, bool> Custom-event-name set ({name => true}). */
	private array $custom_event_names       = [];
	/** @var array<string, array<string, bool>> rule_id => {event => true} disable decisions. */
	private array $custom_events_to_disable = [];
	/** @var array<string, array<string, array<string, array{c: int, s: float|int, m: float|int}>>> Dim → bucket → value accumulator. */
	private $dim_stats                   = [];
	/** @var array<string, array<string, array<string, array<string, array{c: int, s: float|int, m: float|int}>>>> Server → dim → bucket → value accumulator. */
	private $dim_stats_by_server         = [];

	/**
	 * Live top-N cap for the per-URL flame-profile mirror (NS_URL). 0 in production
	 * — per-URL flame profiles are NOT mirrored to memcache (a perf win; the per-URL
	 * dimensional/category namespaces still mirror at top-100). A config point:
	 * `set_flame_topn` raises it, which tests use to exercise the persisted-profile
	 * shape at a non-zero cap.
	 */
	private int $flame_topn = 0;
	/** @var array<string, array<string, bool>> rule_id => {hook => true} disable decisions. */
	private array $hooks_to_disable         = [];

	/** @var array<string, array<string, mixed>> Bucket-keyed hourly accumulator. */
	private $hourly_stats                = [];
	private bool $is_hub                    = false;

	/** Per-URL aggregate state. */
	private float $last_flush_time          = 0.0;
	/** @var array<string, array<string, array<string, mixed>>> Server → bucket leaderboard accumulator. */
	private $leaderboard_by_server_stats = [];
	/** @var array<string, array<string, mixed>> Bucket-keyed leaderboard accumulator. */
	private $leaderboard_stats           = [];
	/** @var array<string, array<string, bool>> rule_id => {event => true} newly promoted. */
	private array $new_significant_events   = [];

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

	/** Pending stats for the current (incomplete) 5-minute bucket. */
	private string $pending_bucket = '';

	/** @var Rule_Set|null Lazily-loaded per-worker ruleset (thresholds are per-rule). */
	private ?Rule_Set $rule_set = null;
	/** @var array<string, array<string, bool>> rule_id => {event => true} known-significant dedupe cache. */
	private array $significant_events       = [];

	/** @var LRU_Cache Per-URL aggregate accumulator. */
	private $stats_cache;

	/** @var array<string, array{0: array<array-key, mixed>, 1: int}> Aggregate mirror writes (kept in full): key => [data, ttl]. */
	private array $stats_mirror_buffer = [];

	/** @var array<string, array<string, array{0: array<array-key, mixed>, 1: int, 2: int}>> Per-URL top-N: ns => key => [data, ttl, rank]. */
	private array $stats_mirror_topn = [];

	/** Name of the durable Partition shadowing the stats store (non-Atomic cold-boot replay); '' = disabled. Resolved by name lazily at use. */
	private string $stats_partition = '';

	/** Guards reload_stats_from_partition() to a single cold-boot replay per process. */
	private bool $stats_reloaded = false;

	/** @var Stats_Store|null Memcache-backed stats store. */
	private $stats_store = null;
	/** @var array<string, array<string, array<string, mixed>>> Url-hash → bucket → category accumulator. */
	private $url_cat_stats               = [];
	/** @var array<string, array<string, array<string, array<string, array{c: int, s: float|int, m: float|int}>>>> Url-hash → dim → bucket → value accumulator. */
	private $url_dim_stats               = [];
	/** @var array<string, array<string, mixed>> Bucket → url-hash URL stats accumulator. */
	private $url_stats                   = [];

	/** @api Used by substrate */
	public function __construct() {
		$this->stats_cache     = new LRU_Cache( self::STATS_CACHE_BUCKET_SIZE, self::STATS_CACHE_NUM_BUCKETS );
		$this->last_flush_time = \microtime( true );
		$this->reset_pending();

		// Owned auto-tuner sibling (patron-linked; hidden from the canvas).
		$this->auto_tuner = new Auto_Tuner_Node();
		$this->auto_tuner->patron( $this );

		parent::__construct();
		// Wire :config interpreter last: handlers read patron() lazily (safe).
		$this->auto_wire_interpreter();
	}

	/**
	 * Process a single completed request from requests.log.
	 *
	 * @param array<int, mixed> $message Reference; not mutated.
	 */
	public function fill( array $message ): void {
		++$this->counter;
		// Per-message deferral: clear a stale stop from a prior fill().
		$this->clear_pending_stop();
		$this->reload_stats_from_partition(); // cold-boot warm once ready.
		$type_raw = $message[ Message::TYPE ];
		$type     = Core::int( $type_raw );
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
		$rid      = Core::str( $rid_raw );
		$url_raw  = $request['url'] ?? '';
		$url_hash = Log_Manager::url_hash( Core::str( $url_raw ) );
		$entries  = $request['entries'] ?? [];
		if ( ! \is_array( $entries ) ) {
			$entries = [];
		}

		$duration_raw        = $request['duration_ms'] ?? 0;
		$flame_data          = Flame_Tree::build_flame_data( $entries, $this->now_ts() );
		$flame_data['value'] = Core::num_float( $duration_raw );

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
			$this->guarded( fn () => $this->flush() );
			$this->last_flush_time = $now_f;
		}

		$this->raise_pending_stop();
	}

	/** @param array<int, mixed> $message Incoming command Message. */
	private function handle_request( array $message ): void {
		if ( null === $this->sink ) {
			throw new \RuntimeException( 'Flame_Builder::fill requires a wired sink' );
		}
		$value_raw = $message[ Message::VALUE ];
		$value     = Core::as_string( $value_raw );
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
				'auto_tune_pending_count'  => self::map_total( $this->hooks_to_disable ) + self::map_total( $this->custom_events_to_disable ) + self::map_total( $this->new_significant_events ),
				'is_hub'                   => $this->is_hub,
				'significant_events_count' => self::map_total( $this->significant_events ),
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
		$this->sink->fill( $reply );
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
		// Strip duplicate sibling suffixes before storage (only for merging).
		Flame_Tree::strip_name_suffixes( $flame_data );

		// Add rid and url_hash to flame data so index callback can extract.
		$flame_data['rid']      = $rid;
		$flame_data['url_hash'] = $url_hash;

		if ( '' === $this->target || null === $this->sink ) {
			return true; // Aggregation still happens; just no on-disk flame.
		}
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = Core::$now;
		$message[ Message::FROM ]      = $this->name;
		$message[ Message::TO ]        = $this->target;
		$message[ Message::KEY ]       = $flame_data['rid'];
		$message[ Message::VALUE ]     = $flame_data;
		// Deferred on a stop so the caller still accumulates stats.
		$this->guarded( fn () => $this->sink->fill( $message ) );
		return true;
	}

	/**
	 * Total leaf entries across a per-rule-id keyed map.
	 *
	 * @param array<string, array<string, bool>> $map
	 */
	private static function map_total( array $map ): int {
		$total = 0;
		foreach ( $map as $inner ) {
			$total += \count( $inner );
		}
		return $total;
	}

	/**
	 * Accumulate all per-request stats from a completed request.
	 *
	 * @param string $url_hash   URL hash.
	 * @param array<string, mixed> $flame_data Per-request flame tree.
	 * @param array<array-key, mixed> $profiles   profiles{} from request.
	 * @param array<array-key, mixed> $request    Full request record.
	 */
	private function accumulate_all_stats( string $url_hash, array $flame_data, array $profiles, array $request ): void {
		// Resolve the request's governing rule once; no match = tune inert.
		$rule             = $this->rule_for_request( $request );
		$count_threshold  = null !== $rule ? $rule->auto_disable_threshold : 0;
		$time_threshold   = null !== $rule ? $rule->auto_protect_time_threshold : 0.0;
		$rule_id          = null !== $rule ? $rule->id : '';
		$auto_tune_active = null !== $rule && $rule->is_log() && '' !== $rule_id;

		$duration_val = $flame_data['value'] ?? 0;
		$duration_ms  = Core::num_float( $duration_val );
		$error_status = $request['error_status'] ?? '-';
		$is_timed_out = 'T' === $error_status;
		$is_worker    = ! empty( $request['is_worker'] );
		// Two gates: per-URL rows keep worker timing; global drops workers.
		$record_timing = $duration_ms > 0 && ! $is_timed_out;
		$count_global  = ! $is_worker;
		$now           = $this->now_ts();

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
		// Per-URL: workers keep timing on their own row.
		if ( $record_timing ) {
			$flame['sum_value'] = ( \is_numeric( $flame['sum_value'] ?? null ) ? $flame['sum_value'] : 0 ) + $duration_ms;
			$flame_children     = \is_array( $flame['children'] ?? null ) ? $flame['children'] : [];
			$incoming_children  = \is_array( $flame_data['children'] ?? null ) ? $flame_data['children'] : [];
			$flame['children']  = Flame_Tree::merge_flame_children_incremental( $flame_children, $incoming_children, $now );
		}
		$aggregate['flame'] = $flame;

		// --- 2. Bucket key + rotation ---
		$timestamp_raw = $request['timestamp'] ?? $now;
		$timestamp     = Core::num_int( $timestamp_raw, $now );
		$bucket_key    = $this->bucket_key( $timestamp );
		if ( $bucket_key !== $this->pending_bucket ) {
			if ( '' !== $this->pending_bucket ) {
				$this->promote_pending_bucket();
			}
			$this->pending_bucket = $bucket_key;
		}

		// --- 2b. URL stats (pending bucket) ---
		$url_val = $request['url'] ?? '';
		$url     = Core::str( $url_val );
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
			// Per-URL: workers keep timing on their own row.
			if ( $record_timing ) {
				++$us['timed_count'];
				$us['sum_ms'] += $duration_ms;
				$us['max_ms']  = \max( $us['max_ms'], $duration_ms );
			}
			$us['last_seen']      = \max( $us['last_seen'], $timestamp );
			$status_code          = $request['status_code'] ?? 0;
			$status_category      = (int) \floor( Core::num_float( $status_code ) / 100 );
			if ( $status_category >= 2 && $status_category <= 5 ) {
				++$us[ "count_{$status_category}xx" ];
			}
			if ( $record_timing ) {
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
			$peak_mb  = Core::num_float( $peak_raw );
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
		// Global: workers contribute nothing — count, timing, AND peak.
		if ( $count_global ) {
			if ( $record_timing ) {
				++$hourly['count'];
				$hourly['sum_ms'] += $duration_ms;
			}
			$hourly['sum_peak_mb'] += $hourly_peak_num;
		}
		$this->pending['hourly'] = $hourly;
		$status_code_raw = $request['status_code'] ?? 0;
		$status_cat      = (int) \floor( Core::num_float( $status_code_raw ) / 100 );
		if ( $status_cat >= 2 && $status_cat <= 5 ) {
			$request['status_category'] = "{$status_cat}xx";
		}

		// --- 3b. Dimensional stats (global + per-server + per-URL) ---
		/** @var array<string, string> $intern */
		static $intern      = [];
		static $intern_full = false;
		$server_raw     = $request['server_name'] ?? '';
		$server_name    = Core::str( $server_raw );
		$dim_peak_raw   = $request['peak_mb'] ?? 0;
		$dim_peak_mb    = Core::num_float( $dim_peak_raw );
		$dim_duration   = $record_timing ? $duration_ms : 0;

		foreach ( self::DIM_FIELDS as $dim => $field ) {
			$field_raw = $request[ $field ] ?? '';
			$val       = Core::as_string( $field_raw );
			if ( '' === $val ) {
				$val = 'Unknown';
			}
			if ( ! $intern_full ) {
				$val = $intern[ $val ] ??= $val;
				if ( \count( $intern ) >= self::INTERN_TABLE_LIMIT ) {
					$intern_full = true;
				}
			}
			// Global: workers contribute nothing — count, timing, AND peak.
			if ( $count_global ) {
				if ( ! isset( $this->pending['dim'][ $dim ][ $val ] ) ) {
					$this->pending['dim'][ $dim ][ $val ] = [ 'c' => 0, 's' => 0, 'm' => 0 ];
				}
				++$this->pending['dim'][ $dim ][ $val ]['c'];
				$this->pending['dim'][ $dim ][ $val ]['s'] += $dim_duration;
				$this->pending['dim'][ $dim ][ $val ]['m'] += $dim_peak_mb;
			}

			// Per-server (hub only; skip redundant dim); global drops workers.
			if ( $this->is_hub && '' !== $server_name && 'server' !== $dim && $count_global ) {
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

		// 4. Profile loop: per-URL gate accrues worker rows; global by count.
		if ( ! empty( $profiles ) && $record_timing ) {
			$aggregate_profiles = \is_array( $aggregate['profiles'] ?? null ) ? $aggregate['profiles'] : [];
			$prof_cats          = $aggregate_profiles['categories'] ?? [];
			$aggregate_profiles['categories'] = Core::arr( $prof_cats );
			/** @var array{count?: int, sum_req_time?: float|int, categories: array<string, array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>}>} $prof */
			$prof = $aggregate_profiles;
			/** @var array{count?: int, sum_req_time?: float|int, categories: array<string, array{samples: int, sum_time: float|int, sum_count: float|int, ts?: int, entries: array<string, array<int, float|int>>}>} $lb */
			$lb   = &$this->pending['leaderboard'];

			$req_time = 0.0;

			// Global: workers excluded from the "total" pseudo-category.
			if ( $count_global ) {
				if ( ! isset( $this->pending['cat']['total'] ) ) {
					$this->pending['cat']['total'] = [ 't' => 0, 'c' => 0, 'n' => 0 ];
				}
				$this->pending['cat']['total']['t'] += $duration_ms;
				++$this->pending['cat']['total']['n'];
			}

			// Global per-server: drop workers.
			if ( $this->is_hub && '' !== $server_name && $count_global ) {
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

			// Per-server leaderboard (hub mode only). Global: drop workers.
			$slb = null;
			if ( $this->is_hub && '' !== $server_name && $count_global ) {
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
					$category = Core::str( $interned, $category );
					if ( \count( $intern ) >= self::INTERN_TABLE_LIMIT ) {
						$intern_full = true;
					}
				}

				$is_callback = (bool) \preg_match( '/ @-?\d+$/', $category );
				$is_plugin   = (bool) \preg_match( '/ plugin$/', $category );

				$time_raw  = $data['time'] ?? 0;
				$count_raw = $data['count'] ?? 0;
				$ts_raw    = $data['ts'] ?? 0;
				$cat_time  = Core::num_float( $time_raw );
				$cat_count = Core::num_int( $count_raw );
				$cat_ts    = Core::num_int( $ts_raw );
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

				// Global leaderboard category: workers excluded.
				$lcat = null;
				if ( $count_global ) {
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
				}

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
							$s_name  = Core::str( $s_name_interned, (string) $s_name );
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

				// Global category time series (pending): workers excluded.
				if ( $count_global ) {
					$cat_bucket   = $this->pending['cat'][ $category ] ?? [ 't' => 0, 'c' => 0, 'n' => 0 ];
					$cat_total    = $this->pending['cat']['total'] ?? [ 't' => 0, 'c' => 0, 'n' => 0 ];
					$cat_bucket['t'] += $cat_time;
					$cat_bucket['c'] += $cat_count;
					++$cat_bucket['n'];
					$cat_total['c']  += $cat_count;
					$this->pending['cat'][ $category ] = $cat_bucket;
					$this->pending['cat']['total']      = $cat_total;
				}

				// Global per-server category series: drop workers.
				if ( $this->is_hub && '' !== $server_name && $count_global ) {
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

				// Significant-event: avg/call > threshold; workers excluded.
				if ( $auto_tune_active && null !== $lcat && ! $is_callback && ! $is_plugin && $time_threshold > 0 && $lcat['sum_count'] > 0 ) {
					$avg_per_call = $lcat['sum_time'] / $lcat['sum_count'];
					if ( $avg_per_call >= $time_threshold ) {
						$base_name = \explode( ' ', $category, 2 )[0];
						if ( ! isset( $this->significant_events[ $rule_id ][ $base_name ] ) && ! $this->rule_significant( $rule, $base_name ) ) {
							$this->significant_events[ $rule_id ][ $base_name ]     = true;
							$this->new_significant_events[ $rule_id ][ $base_name ] = true;
						}
					}
				}

				// Entry loop (per-URL + global).
				$entries = $data['entries'] ?? null;
				if ( ! empty( $entries ) && \is_array( $entries ) ) {
					foreach ( $entries as $name => $entry_data ) {
						$name_interned = $intern[ $name ] ??= $name;
						$name        = Core::str( $name_interned, (string) $name );
						$entry_time  = \is_array( $entry_data ) && \is_numeric( $entry_data[0] ?? null ) ? (float) $entry_data[0] : 0.0;
						$entry_count = \is_array( $entry_data ) && \is_numeric( $entry_data[1] ?? null ) ? (float) $entry_data[1] : 0.0;

						if ( ! isset( $pcat['entries'][ $name ] ) ) {
							$pcat['entries'][ $name ] = [ 0.0, 0.0, 0 ];
						}
						$pcat['entries'][ $name ][0] += $entry_time;
						$pcat['entries'][ $name ][1] += $entry_count;
						++$pcat['entries'][ $name ][2];

						// Global entries skipped for workers (ref null).
						if ( null !== $lcat ) {
							if ( ! isset( $lcat['entries'][ $name ] ) ) {
								$lcat['entries'][ $name ] = [ 0.0, 0.0, 0 ];
							}
							$lcat['entries'][ $name ][0] += $entry_time;
							$lcat['entries'][ $name ][1] += $entry_count;
							++$lcat['entries'][ $name ][2];
						}
					}

					// Trim with hysteresis (cap by sum_time).
					if ( \count( $pcat['entries'] ) > self::ENTRY_LIMIT_URL_UPPER ) {
						\uasort( $pcat['entries'], fn( $a, $b ) => ( $b[0] ?? 0 ) <=> ( $a[0] ?? 0 ) );
						$pcat['entries'] = \array_slice( $pcat['entries'], 0, self::ENTRY_LIMIT_URL_LOWER, true );
					}
					// Global cap: skipped for workers (ref stays null).
					if ( null !== $lcat && \count( $lcat['entries'] ) > self::ENTRY_LIMIT_GLOBAL_UPPER ) {
						\uasort( $lcat['entries'], fn( $a, $b ) => ( $b[0] ?? 0 ) <=> ( $a[0] ?? 0 ) );
						$lcat['entries'] = \array_slice( $lcat['entries'], 0, self::ENTRY_LIMIT_GLOBAL_LOWER, true );
					}
				}

				// Noisy detection (global auto-tune signal); workers excluded.
				if ( $auto_tune_active && $count_global && ! $is_callback && ! $is_plugin && $count_threshold > 0 && $cat_count > $count_threshold ) {
					$base_name = \explode( ' ', $category, 2 )[0];
					if ( isset( $this->custom_event_names[ $base_name ] ) ) {
						$this->custom_events_to_disable[ $rule_id ][ $base_name ] = true;
					} else {
						$this->hooks_to_disable[ $rule_id ][ $base_name ] = true;
					}
				}
				unset( $pcat );
				unset( $lcat );
			}

			// Top-level sums: per-URL kept; global leaderboard drops workers.
			$prof['count']        = ( $prof['count']        ?? 0 ) + 1;
			$prof['sum_req_time'] = ( $prof['sum_req_time'] ?? 0 ) + $req_time;
			if ( $count_global ) {
				$lb['count']        = ( $lb['count']        ?? 0 ) + 1;
				$lb['sum_req_time'] = ( $lb['sum_req_time'] ?? 0 ) + $req_time;
			}

			if ( null !== $slb ) {
				$slb['count']        = ( $slb['count']        ?? 0 ) + 1;
				$slb['sum_req_time'] = ( $slb['sum_req_time'] ?? 0 ) + $req_time;
				unset( $slb );
			}

			// Expire old per-URL categories.
			$cutoff = $now - self::AGGREGATE_EXPIRY_SEC;
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

	/**
	 * Resolve the rule that governed a request: by stamped id, else url-rematch,
	 * else null.
	 *
	 * @param array<array-key, mixed> $request Full request record.
	 */
	private function rule_for_request( array $request ): ?Rule {
		$id = \is_string( $request['rule_id'] ?? null ) ? $request['rule_id'] : '';
		if ( '' !== $id ) {
			$rule = $this->rule_set()->rule_by_id( $id );
			if ( null !== $rule ) {
				return $rule;
			}
		}
		$url = \is_string( $request['url'] ?? null ) ? $request['url'] : '';
		return '' !== $url ? $this->rule_set()->matcher()->match( $url ) : null;
	}

	// Stats accumulation: all namespaces into the pending bucket + LRU.

	/** Lazily-loaded ruleset, cached for this worker's lifetime. */
	private function rule_set(): Rule_Set {
		return $this->rule_set ??= Rule_Set::load();
	}

	/** Whether a name is already a rule-declared significant event. */
	private function rule_significant( ?Rule $rule, string $name ): bool {
		return null !== $rule && \in_array( $name, $rule->significant_events, true );
	}

	// Per-URL flame merge + finalize.

	/**
	 * Flush every accumulator to memcache (or to in-memory if no store) and
	 * reset pending. Called every FLUSH_INTERVAL_SEC plus at shutdown.
	 */
	public function flush(): void {
		// Promote pending bucket every flush so dashboards see data within 30s.
		if ( '' !== $this->pending_bucket ) {
			$this->promote_pending_bucket();
		}

		// Drain per-URL flame/profile stats to the store.
		$this->mirror_url_stats();

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

	// Pending-bucket promotion + reset.

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

	// Persist (memcache write of the 9 namespaces).

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
					$stats = Core::arr( $stats_raw );
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
					// Fold min_ms from timed buckets only (skip PHP_INT_MAX).
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
						$tc     = Core::num_float( $tc_raw );
						$sum_ms = Core::num_float( $url_stat['sum_ms'] ?? null );
						$url_stat['avg_ms'] = $tc > 0 ? $sum_ms / $tc : 0;
					}
				}
				unset( $url_stat );

				if ( \count( $existing_urls ) > self::MAX_URLS_PER_BUCKET ) {
					\uasort( $existing_urls, fn( $a, $b ) => ( \is_numeric( $b['count'] ?? null ) ? $b['count'] : 0 ) <=> ( \is_numeric( $a['count'] ?? null ) ? $a['count'] : 0 ) );
					$existing_urls = \array_slice( $existing_urls, 0, self::MAX_URLS_PER_BUCKET, true );
				}

				$stats_store->set_url_index_hourly( $bucket_key, $existing_urls );
			}
		}

		// --- Dimensional (global, per-server, per-URL) ---
		$cutoff = $this->bucket_key( $this->now_ts() - $stats_store->ttl() );
		foreach ( $this->dim_stats as $dim => $buckets ) {
			$existing = $stats_store->get_dimensional( $dim );
			$this->merge_and_cap_dimensional( $existing, $buckets, $cutoff );
			// Restore string bucket keys widened by by-ref merge, for store.
			/** @var array<string, mixed> $existing */
			$stats_store->set_dimensional( $dim, $existing );
		}
		foreach ( $this->dim_stats_by_server as $server => $dims ) {
			foreach ( $dims as $dim => $buckets ) {
				$existing = $stats_store->get_dimensional( $dim, $server );
				$this->merge_and_cap_dimensional( $existing, $buckets, $cutoff );
				/** @var array<string, mixed> $existing */
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

	// Bucket-key helper.

	/**
	 * 5-min bucket key from a Unix timestamp.
	 */
	private function bucket_key( int $timestamp ): string {
		$min        = (int) \gmdate( 'i', $timestamp );
		$bucket_min = \str_pad( (string) ( (int) \floor( $min / self::BUCKET_MINUTES ) * self::BUCKET_MINUTES ), 2, '0', STR_PAD_LEFT );
		return \gmdate( 'Y-m-d-H', $timestamp ) . '-' . $bucket_min;
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
	 * @param array<array-key, mixed> $existing Existing buckets (modified by reference).
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
			if ( \is_array( $bk_cats_raw ) ) {
				$existing[ $bk ] = self::cap_single_bucket( $bk_cats_raw, $max_values );
			}
		}
		\ksort( $existing );
	}

	/**
	 * Cap a single bucket's categories to top N by time, preserving 'total'.
	 *
	 * Key-preserving and key-agnostic: decoded memcache buckets can carry int
	 * keys (numeric category names); the body only names 'total'/'Other'.
	 *
	 * @template TKey of array-key
	 * @param array<TKey, mixed> $cats Category buckets.
	 * @return array<TKey|string, mixed>
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

	// Auto-tune: noisy hooks + significant events with distributed-lock.

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

		// No shared handle → skip cross-worker lock; just fire (single-proc).
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
		$rule_ids = \array_unique( \array_merge(
			\array_keys( $this->hooks_to_disable ),
			\array_keys( $this->custom_events_to_disable ),
			\array_keys( $this->new_significant_events )
		) );
		foreach ( $rule_ids as $rule_id ) {
			$this->emit_auto_tune( 'disable_hooks',          $rule_id, \array_keys( $this->hooks_to_disable[ $rule_id ] ?? [] ) );
			$this->emit_auto_tune( 'disable_custom_events',  $rule_id, \array_keys( $this->custom_events_to_disable[ $rule_id ] ?? [] ) );
			$this->emit_auto_tune( 'add_significant_events', $rule_id, \array_keys( $this->new_significant_events[ $rule_id ] ?? [] ) );
		}

		$this->hooks_to_disable         = [];
		$this->custom_events_to_disable = [];
		$this->new_significant_events   = [];
	}

	/**
	 * Send an auto-tune decision downstream as a Message. The Router (primary
	 * sink) delivers it to the AutoTuner Node named 'auto-tuner', which applies
	 * it locally and (on hubs) fans out via JobIntake. VALUE carries the rule id
	 * the decision belongs to alongside the items.
	 *
	 * @param string             $key     'disable_hooks' | 'disable_custom_events' | 'add_significant_events'
	 * @param string             $rule_id The rule these items were proposed under.
	 * @param array<int, string> $items   Hook/event names — already deduped at the caller.
	 */
	private function emit_auto_tune( string $key, string $rule_id, array $items ): void {
		$sink = $this->sink;
		if ( empty( $items ) || null === $sink ) {
			return;
		}
		// Narrate auto-tune fires so debug_state surfaces them.
		$this->set_state(
			'AUTO_TUNE_FIRED',
			\implode( ' ', [ 'KEY', $key, 'RULE', $rule_id, 'COUNT', \count( $items ) ] )
		);
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = Core::$now;
		$message[ Message::FROM ]      = $this->name;
		$message[ Message::TO ]        = $this->name . ':auto-tuner';
		$message[ Message::KEY ]       = $key;
		$message[ Message::VALUE ]     = [
			'rule_id' => $rule_id,
			'items'   => $items,
		];
		$sink->fill( $message );
	}

	/**
	 * Inject the Stats_Store.
	 */
	public function set_stats_store( ?Stats_Store $store ): void {
		$this->stats_store = $store;
		$this->arm_stats_mirror();
	}

	/**
	 * Name the durable Partition that shadows stats writes (via the store's
	 * mirror seam) and warms memcache on cold boot. For non-Atomic deployments
	 * where memcache is volatile; disabled when the name is empty.
	 *
	 * Stores the name only — the node is resolved by name lazily at flush/reload
	 * (like set_snapshot_node), so this verb can't fail on a not-yet-built node
	 * whose make_node comes later in a console-serialized override. The partition
	 * lifts its own 4KB PIPE_BUF cap via `cmd <name>:config void_warranty` in the
	 * topology, alongside its make_node.
	 *
	 * The mirror buffers writes in memory and flushes them to the partition once
	 * per save_state() checkpoint (flush_stats_mirror). Writes made after the last
	 * checkpoint die with a crash — every partition frame is already committed, so
	 * recovery replays them exactly once with no double-count.
	 */
	public function set_stats_target( string $name ): void {
		$this->stats_partition = \trim( $name );
		$this->arm_stats_mirror();
	}

	/**
	 * Arm (or disarm) the store's mirror seam from the current store + partition
	 * name. Called from BOTH setters so store and partition can be configured in
	 * either order and a configure_stats re-run re-arms the fresh store. Needs
	 * only the store — the partition node is resolved by name lazily at use.
	 */
	private function arm_stats_mirror(): void {
		$store = $this->stats_store;
		if ( null === $store ) {
			return;
		}
		$store->mirror = '' === $this->stats_partition
			? null
			: function ( string $key, array $data, int $ttl, string $ns ): void {
				$this->buffer_mirror_write( $key, $data, $ttl, $ns );
			};
	}

	/**
	 * Buffer a mirrored write. Aggregates are kept in full; the per-URL namespaces
	 * are bounded to top-N by traffic (STATS_MIRROR_TOPN).
	 *
	 * @param array<array-key, mixed> $data
	 */
	private function buffer_mirror_write( string $key, array $data, int $ttl, string $ns ): void {
		if ( ! isset( self::STATS_MIRROR_TOPN[ $ns ] ) ) {
			$this->stats_mirror_buffer[ $key ] = [ $data, $ttl ]; // aggregate: keep all
			return;
		}
		// Rank flame top-N only among URLs with profiling detail (fwd-compat).
		if ( Stats_Store::NS_URL === $ns && ! $this->has_profiling_detail( $data ) ) {
			return;
		}
		$this->stats_mirror_topn[ $ns ][ $key ] = [ $data, $ttl, $this->mirror_traffic_rank( $data, $ns ) ];
		if ( \count( $this->stats_mirror_topn[ $ns ] ) > $this->mirror_topn( $ns ) ) {
			$this->evict_lowest_rank( $ns );
		}
	}

	/**
	 * The live top-N cap for a per-URL namespace: the configurable $flame_topn for
	 * NS_URL (the flame profiles), the fixed STATS_MIRROR_TOPN default otherwise.
	 */
	private function mirror_topn( string $ns ): int {
		return Stats_Store::NS_URL === $ns
			? $this->flame_topn
			: self::STATS_MIRROR_TOPN[ $ns ];
	}

	/**
	 * @param array<array-key, mixed> $data
	 */
	private function has_profiling_detail( array $data ): bool {
		$flame = $data['flame'] ?? null;
		return \is_array( $flame ) && ( \is_numeric( $flame['count'] ?? null ) ? (int) $flame['count'] : 0 ) > 0;
	}

	/**
	 * Traffic rank (~request count) for the per-URL namespaces.
	 *
	 * @param array<array-key, mixed> $data
	 */
	private function mirror_traffic_rank( array $data, string $ns ): int {
		if ( Stats_Store::NS_URL === $ns ) {
			$flame = $data['flame'] ?? null;
			return \is_array( $flame ) && \is_numeric( $flame['count'] ?? null ) ? (int) $flame['count'] : 0;
		}
		if ( Stats_Store::NS_URL_CAT === $ns ) {
			// Sum the per-bucket category totals.
			$sum = 0;
			foreach ( $data as $bucket ) {
				$total = \is_array( $bucket ) ? ( $bucket['total'] ?? null ) : null;
				$sum  += \is_array( $total ) && \is_numeric( $total['n'] ?? null ) ? (int) $total['n'] : 0;
			}
			return $sum;
		}
		// NS_URL_DIM: sum the first dimension's request counts.
		$sum   = 0;
		$first = \reset( $data );
		if ( \is_array( $first ) ) {
			foreach ( $first as $bucket ) {
				if ( ! \is_array( $bucket ) ) {
					continue;
				}
				foreach ( $bucket as $vd ) {
					$sum += \is_array( $vd ) && \is_numeric( $vd['c'] ?? null ) ? (int) $vd['c'] : 0;
				}
			}
		}
		return $sum;
	}

	private function evict_lowest_rank( string $ns ): void {
		$min_key  = null;
		$min_rank = \PHP_INT_MAX;
		foreach ( $this->stats_mirror_topn[ $ns ] as $k => [ , , $rank ] ) {
			if ( $rank < $min_rank ) {
				$min_rank = $rank;
				$min_key  = $k;
			}
		}
		if ( null !== $min_key ) {
			unset( $this->stats_mirror_topn[ $ns ][ $min_key ] );
		}
	}

	/**
	 * Save state for persistence.
	 *
	 * @api Used by substrate.
	 * @return array<string, mixed>
	 */
	public function save_state(): array {
		// Co-commit the current flame trees with the cursor, like pending.
		$this->mirror_url_stats();
		$this->flush_stats_mirror();
		return [
			'pending_bucket' => $this->pending_bucket,
			'pending'        => $this->pending,
		];
	}

	/**
	 * Drain the current per-URL flame/profile stats_cache into the store (memcache + the
	 * mirror seam). Shared by flush() and save_state() so the flame trees co-commit with
	 * the cursor at every checkpoint, not only on the FLUSH_INTERVAL_SEC cadence.
	 * set_url_stats overwrites with the full aggregate and does NOT reset stats_cache, so
	 * a save_state drain plus the next flush() is idempotent (no double-count).
	 */
	private function mirror_url_stats(): void {
		$stats_store = $this->stats_store;
		if ( null === $stats_store ) {
			return;
		}
		$now = $this->now_ts();
		foreach ( $this->stats_cache->iterate() as $url_hash => $aggregate ) {
			if ( ! \is_array( $aggregate ) || ( ! \is_string( $url_hash ) && ! \is_int( $url_hash ) ) ) {
				continue;
			}
			$url_hash = (string) $url_hash;
			/** @var array<string, mixed> $aggregate */
			// Finalized flame for display; keep flame_raw for merging.
			$flame                  = \is_array( $aggregate['flame'] ?? null ) ? $aggregate['flame'] : [];
			$count_raw              = $flame['count'] ?? 0;
			$total_count            = Core::num_int( $count_raw );
			$aggregate['flame_raw'] = $flame;
			Flame_Tree::finalize_flame_node( $flame, $total_count );
			$aggregate['flame']         = $flame;
			$aggregate['last_modified'] = $now;
			$stats_store->set_url_stats( $url_hash, $aggregate );
		}
	}

	private function now_ts(): int {
		return null !== $this->clock_fn ? ( $this->clock_fn )() : \time();
	}

	/**
	 * Flush the buffered mirror writes to the durable partition as one checkpoint.
	 * save_state() is co-committed with the requests-Consumer's durable offset, so
	 * every frame written here is committed: writes made after this die with a
	 * crash and get reprocessed cleanly (no double-count). Aggregates flush in full;
	 * the per-URL namespaces flush their bounded top-N. Both buffers reset after.
	 */
	private function flush_stats_mirror(): void {
		if ( '' === $this->stats_partition ) {
			return;
		}
		$this->reload_stats_from_partition(); // cold-boot warm before first write.
		$partition = $this->resolve_stats_partition();
		if ( null === $partition ) {
			$this->print_less_often( "flame-builder: stats_partition '{$this->stats_partition}' not found at flush" );
			return; // Keep the buffer; retry next checkpoint once the node exists.
		}
		foreach ( $this->stats_mirror_buffer as $key => [ $data, $ttl ] ) {
			$this->write_mirror_frame( $partition, $key, $data, $ttl );
		}
		foreach ( $this->stats_mirror_topn as $entries ) {
			foreach ( $entries as $key => [ $data, $ttl ] ) {
				$this->write_mirror_frame( $partition, $key, $data, $ttl );
			}
		}
		$this->stats_mirror_buffer = [];
		$this->stats_mirror_topn   = [];
	}

	/**
	 * Cold-boot replay: when memcache hourly is empty, read every mirrored write
	 * from the partition oldest→newest, collapse to the latest frame per key
	 * (frames are append-ordered, so last-wins), then restore each key ONCE under
	 * a TTL decayed by its age. O(unique keys) memcache sets instead of O(all
	 * appends). Core::$now is stale during the config phase, so \microtime(true)
	 * is the "now" reference.
	 *
	 * Every partition frame is committed — the un-committed buffer is never written
	 * (it dies with a crash) — so recovery counts each request exactly once.
	 */
	private function reload_stats_from_partition(): void {
		if ( $this->stats_reloaded ) {
			return;
		}
		$store     = $this->stats_store;
		$partition = $this->resolve_stats_partition();
		if ( null === $store || null === $partition ) {
			return; // disabled or not built yet — retry later; don't mark reloaded.
		}
		$this->stats_reloaded = true;
		if ( ! empty( $store->get_hourly() ) ) {
			return; // Warm — skip replay.
		}
		/** @var array<string, array{0: array<string, mixed>, 1: int, 2: float}> $latest */
		$latest = [];
		foreach ( $partition->get_segments() as $seg ) {
			$bytes = $partition->read_at( $seg['id'], 0, $seg['size'] );
			foreach ( \explode( "\n", $bytes ) as $line ) {
				if ( '' === $line ) {
					continue;
				}
				try {
					$msg = Message::unpacked( $line );
				} catch ( \Throwable ) {
					continue; // Torn/corrupt frame — skip, don't crash the cold-boot replay.
				}
				$value = $msg[ Message::VALUE ] ?? null;
				if ( ! \is_array( $value ) ) {
					continue;
				}
				$key  = $value['key'] ?? null;
				$data = $value['data'] ?? null;
				$ttl  = $value['ttl'] ?? null;
				if ( ! \is_string( $key ) || ! \is_array( $data ) || ! \is_int( $ttl ) ) {
					continue;
				}
				$typed = [];
				foreach ( $data as $dk => $dv ) {
					$typed[ (string) $dk ] = $dv;
				}
				$ts             = $msg[ Message::TIMESTAMP ] ?? 0;
				// last-wins
				$latest[ $key ] = [ $typed, $ttl, Core::num_float( $ts ) ];
			}
		}
		$now = \microtime( true );
		foreach ( $latest as $key => [ $data, $ttl, $ts ] ) {
			$store->restore( $key, $data, $ttl - (int) ( $now - $ts ) );
		}
	}

	/** Resolve the named stats partition to its live node, or null when disabled / not-yet-built. */
	private function resolve_stats_partition(): ?\Newspack_Nodes\Partition_Node {
		if ( '' === $this->stats_partition ) {
			return null;
		}
		$node = Core::node( $this->stats_partition );
		return $node instanceof \Newspack_Nodes\Partition_Node ? $node : null;
	}

	/**
	 * Write one mirror frame (TM_STRUCT {key,data,ttl}) to the partition.
	 *
	 * @param array<array-key, mixed> $data
	 */
	private function write_mirror_frame( \Newspack_Nodes\Partition_Node $partition, string $key, array $data, int $ttl ): void {
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = Core::$now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::KEY ]       = $key;
		$msg[ Message::VALUE ]     = [ 'key' => $key, 'data' => $data, 'ttl' => $ttl ];
		$partition->fill( $msg );
	}

	/**
	 * Set the per-URL flame-profile mirror cap (NS_URL top-N). 0 (the production
	 * default) disables the flame-profile mirror; a positive cap mirrors the top-N
	 * profiled URLs by traffic. A config point — tests raise it to exercise the
	 * persisted-profile shape.
	 *
	 * @param int $n Top-N cap; negatives clamp to 0.
	 */
	public function set_flame_topn( int $n ): void {
		$this->flame_topn = \max( 0, $n );
	}

	/**
	 * Pre-check the owned auto-tuner sibling's `{name}:auto-tuner` slot
	 * alongside the base's own-name + `:config` checks. Chains parent::.
	 * 
	 * @api Used by substrate.
	 * @param string $name
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
	 * 
	 * @api Used by substrate.
	 * @param string|null $name
	 */
	protected function set_sibling_names( ?string $name = null ): void {
		$this->auto_tuner?->name( "{$name}:auto-tuner" );
		parent::set_sibling_names( $name );
	}

	/**
	 * Cascade-remove the owned auto-tuner sibling alongside the patron. Full
	 * remove_node (not a bare unregister) so the auto-tuner's own `:config`
	 * interpreter sibling unregisters too and a same-name respawn doesn't collide.
	 *
	 * @api Used by substrate.
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
	 * 
	 * @api Used by substrate.
	 * @param Node|null $node
	 * @return Node|null
	 */
	public function sink( ?Node $node = null ): ?Node {
		if ( \func_num_args() > 0 ) {
			$this->auto_tuner?->sink( $node );
			return parent::sink( $node );
		}
		return parent::sink();
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
	 * @api Used by tests.
	 * @param array<int, string> $names
	 */
	public function set_custom_event_names( array $names ): void {
		$this->custom_event_names = [];
		foreach ( $names as $n ) {
			$this->custom_event_names[ $n ] = true;
		}
	}

	/**
	 * Replace the clock used for bucket-key derivation (testing seam).
	 *
	 * @api Used by tests.
	 * @param (callable(): int)|null $fn
	 */
	public function set_clock( ?callable $fn ): void {
		$this->clock_fn = $fn;
	}

	/**
	 * Accessor for the auto-tune state, keyed per rule id.
	 *
	 * @api Used by tests.
	 * @return array<string, array<string, list<string>>>
	 */
	public function get_auto_tune_state(): array {
		$names = static fn( array $set ): array => \array_keys( $set );
		return [
			'hooks'           => \array_map( $names, $this->hooks_to_disable ),
			'custom_events'   => \array_map( $names, $this->custom_events_to_disable ),
			'new_significant' => \array_map( $names, $this->new_significant_events ),
		];
	}

	/**
	 * Restore state from save_state().
	 *
	 * @api Used by substrate.
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
	 * Surface the stats-mirror partition as a named target so the console draws
	 * the flame-builder → flame-stats:partition edge. Display only: the mirror
	 * writes go straight to the partition at flush (bypassing the sink), so
	 * without this override the partition renders disconnected even while it
	 * fills. What actually gets mirrored is driven by set_snapshot_node +
	 * set_stats_target, not by this method.
	 *
	 * @api Used by substrate.
	 * @param array<int, string>|string|null $value New primary target or null to get current target.
	 * @return array<int, string>|string
	 */
	public function target( $value = null ) {
		if ( null !== $value ) {
			return parent::target( $value );
		}
		$primary = parent::target();
		$extras  = [];
		if ( '' !== $this->stats_partition ) {
			$extras[] = $this->stats_partition;
		}
		if ( ! $extras ) {
			return $primary;
		}
		$all = \is_array( $primary )
			? $primary
			: ( '' !== $primary ? [ $primary ] : [] );
		foreach ( $extras as $e ) {
			if ( ! \in_array( $e, $all, true ) ) {
				$all[] = $e;
			}
		}
		return $all;
	}

	/**
	 * Emit the base config plus this node's verb-config, from STATE — one
	 * `cmd {name}:config <verb> <value>` line per setting that differs from its
	 * default, for dump_config introspection (REPL/GUI). No generic verb recording.
	 * 
	 * @api Used by substrate.
	 */
	public function dump_config(): string {
		$out = parent::dump_config();
		if ( $this->is_hub ) {
			$out .= "cmd {$this->name}:config set_is_hub true\n";
		}
		if ( null !== $this->stats_store ) {
			$out .= "cmd {$this->name}:config configure_stats {$this->stats_store->partition()}\n";
		}
		if ( '' !== $this->stats_partition ) {
			$out .= "cmd {$this->name}:config set_stats_target {$this->stats_partition}\n";
		}
		if ( 0 !== $this->flame_topn ) {
			$out .= "cmd {$this->name}:config set_flame_topn {$this->flame_topn}\n";
		}
		return $out;
	}

	/**
	 * Format index entry callback for Partition::with_index().
	 *
	 * @param array<int, mixed>  $message  The unpacked message array; VALUE is index 6.
	 * @param array<string, int> $position Position array with segment, offset, length.
	 * @return string|null Index entry or null to skip.
	 */
	public static function format_index_entry( array $message, array $position ): ?string {
		$value = $message[ Message::VALUE ] ?? null;
		if ( ! \is_array( $value ) || empty( $value['rid'] ) ) {
			return null;
		}
		$rid_str      = \is_scalar( $value['rid'] ) ? (string) $value['rid'] : '';
		$url_hash_str = \is_scalar( $value['url_hash'] ?? null ) ? (string) $value['url_hash'] : '';

		return \str_pad( \substr( $rid_str, 0, 32 ), 32 )
			. \str_pad( \substr( $url_hash_str, 0, 12 ), 12 )
			. \str_pad( (string) $position['segment'], 6, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['offset'], 10, '0', STR_PAD_LEFT )
			. \str_pad( (string) $position['length'], 8, '0', STR_PAD_LEFT );
	}

	/**
	 * Parse flame index entry.
	 *
	 * @param string $line Index line.
	 * @return array{rid: string, url_hash: string, segment: int, offset: int, length: int}|null
	 */
	public static function parse_flame_index( string $line ): ?array {
		$line = \rtrim( $line, "\n" );
		if ( \strlen( $line ) < 68 ) {
			return null;
		}
		return [
			'rid'        => \trim( \substr( $line, 0, 32 ) ),
			'url_hash'   => \trim( \substr( $line, 32, 12 ) ),
			'segment' => (int) \substr( $line, 44, 6 ),
			'offset'     => (int) \substr( $line, 50, 10 ),
			'length'     => (int) \substr( $line, 60, 8 ),
		];
	}

	/** @api Used by the substrate to provide UI etc. */
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
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						$arg = Core::as_string( $args[0] ?? '' );
						$arg  = \strtolower( \trim( $arg ) );
						$bool = ( 'true' === $arg || '1' === $arg );
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_is_hub( $bool );
						return 'ok';
					},
				],
				[
					'name'        => 'configure_stats',
					'description' => 'Build the Stats_Store from substrate config (memcache + retention).',
					'args'        => [
						[ 'name' => 'partition', 'type' => 'int', 'required' => true, 'default' => '<partition>' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						$arg = Core::as_string( $args[0] ?? '' );
						// Constant valid pattern: preg_split never false here.
						/** @var list<string> $parts */
						$parts = \preg_split( '/\s+/', \trim( $arg ) );
						if ( \count( $parts ) < 1 || '' === $parts[0] ) {
							return 'usage: configure_stats <partition>';
						}
						$partition = (int) $parts[0];

						// Substrate retention config; store uses Core::$memd.
						$max_lifespan = Core::num_int( \Newspack_Event_Logger_Nodes\Config::value( 'min_lifetime' ), 86400 );

						$stats_store = new \Newspack_Event_Logger_Nodes\Stats_Store( $partition, $max_lifespan );

						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_stats_store( $stats_store );
						return 'ok';
					},
				],
				[
					'name'        => 'set_stats_target',
					'description' => 'Mirror stats writes to a durable Partition and reload from it on cold boot (non-Atomic deployments).',
					'args'        => [ [ 'name' => 'target', 'type' => 'node_name', 'required' => false, 'default' => '' ] ],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						$arg = Core::as_string( $args[0] ?? '' );
						// Store name; resolve lazily (empty=disabled).
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_stats_target( \trim( $arg ) );
						return 'ok';
					},
				],
				[
					'name'        => 'set_flame_topn',
					'description' => 'Cap how many per-URL flame profiles mirror to memcache (top-N by traffic). 0 (default) disables the flame-profile mirror.',
					'args'        => [ [ 'name' => 'n', 'type' => 'int', 'required' => false, 'default' => 0 ] ],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						$arg = Core::as_string( $args[0] ?? '' );
						/** @var self $patron */
						$patron = $interpreter->patron();
						$patron->set_flame_topn( (int) \trim( $arg ) );
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
