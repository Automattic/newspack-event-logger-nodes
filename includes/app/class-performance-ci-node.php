<?php
/**
 * Performance_CI: command-dispatch for the performance-dashboard surface.
 *
 * Verbs the live surfaces drive:
 *   - overview / urls / url_detail / request_search / request_grep /
 *     request_detail — the performance dashboard's per-slice graph
 *     (`src/overview/hooks/usePerformanceGraph.js`), plus the
 *     current-request overlay tab, which fetches `request_detail`.
 *   - hooks_registered — the Settings page's hook-catalog tree.
 *   - set — the spoke-side receiver of the substrate Settings_Sync_Node
 *     hub→spoke fanout. `hub-control.tsl` maps three application options
 *     (rules, log_memory, flush_every_line) to this `performance` node;
 *     SETTINGS_OPTIONS is the matching whitelist.
 *
 * SSE-style stream surfaces (request-log, gyroscope, errors) consume the
 * substrate's `/messages/stream` EventSource directly — the
 * CommandInterpreter dispatch path doesn't stream.
 *
 * Cross-cutting design choices:
 *  - Auth: each verb DECLARES its role in node_schema() — `read` for the
 *    dashboard slices, `tune` for the settings receiver — and Service_CI_Node
 *    gates every handler with it. No handler re-gates itself; a hard-coded
 *    gate would silently override the declaration.
 *  - Rate limit: none here. The substrate's `/command` endpoint already caps
 *    POSTs per user per window, so a polling dashboard is bounded upstream.
 *  - Stats reads fail-soft (matches Stats_Store + dashboards "no data" UX).
 *  - Disk scans capped at MAX_INDEX_ENTRIES so a missing-rid lookup can't
 *    escalate into a partition-wide segment walk.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Config as AppConfig;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Reqgrep_Core;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Callback_Node;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config_System\Restart_Planner;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\LRU_Cache;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

/**
 * Service CI mounted as `performance` on `newspack_nodes/request_graph_ready`.
 *
 * Every verb is declared once in `node_schema()['commands']`; the inherited
 * Service_CI_Node constructor turns that schema into the dispatch table, so
 * this class holds no commands table of its own.
 *
 * The static helpers below fall into three families:
 *   - memcache readers, which fan a Stats_Store out per flame-builder worker
 *     and sum-merge the per-partition buckets;
 *   - disk walkers, which construct throwaway Partition/Consumer nodes over
 *     the DECLARED node dirs and remove them again;
 *   - `set` sanitizers, which bound the values arriving from the hub.
 */
class Performance_CI_Node extends Service_CI_Node {

	/**
	 * Hard cap on .idx entries scanned per disk-walking verb — prevents a
	 * missing-rid scan from walking unbounded numbers of firehose entries.
	 */
	public const MAX_INDEX_ENTRIES = 100000;

	/**
	 * TSL node names the disk-walking verbs resolve their partitions through.
	 * The dirs come from the DECLARATION (`Bootstrap::node_dirs`), never from a
	 * path this class builds: request-builder alone pins `alerts.p0` and
	 * `gyroscope.p0` while `requests.p<partition>` expands, so any assumption
	 * about the naming scheme is wrong for most of its partitions.
	 */
	private const NODE_FLAMES        = 'flames:partition';
	private const NODE_FLAME_BUILDER = 'flame-builder';
	private const NODE_REQUESTS      = 'requests:partition';

	/** `request_grep` default / max matched-request results (bounds the reply). */
	private const GREP_RESULT_LIMIT_DEFAULT = 20;
	private const GREP_RESULT_LIMIT_MAX     = 50;

	/**
	 * `request_grep` hard scan budget: stop feeding the grouping engine after this
	 * many firehose lines so a fat firehose can't wedge a request-scope verb even
	 * inside the (already bounded) `recent` seek window.
	 */
	private const GREP_MAX_SCAN_LINES = 200000;

	/** `request_grep` first-match excerpt length (bounded for the reply). */
	private const GREP_EXCERPT_LENGTH = 200;

	/** `request_grep` in-flight LRU_Cache geometry (100 × 3 = 300 concurrent rids). */
	private const GREP_INFLIGHT_BUCKET_SIZE = 100;
	private const GREP_INFLIGHT_NUM_BUCKETS = 3;

	/** `request_grep` history-bucket geometry for the shared grouping engine. */
	private const GREP_HISTORY_BUCKET_SIZE = 250;
	private const GREP_HISTORY_NUM_BUCKETS = 10;

	/**
	 * Valid breakdown dimensions for the `overview` / `url_detail` verbs —
	 * typos fall through without surfacing arbitrary memcache reads.
	 */
	private const DIMENSIONS = [ 'status', 'method', 'server', 'country', 'from', 'ua', 'ja4' ];

	/** Deepest nesting `set` accepts in an array option; deeper is rejected. */
	private const SETTINGS_ARRAY_DEPTH = 5;

	/**
	 * Maximum element count at any single array level for `set`; a wider level
	 * rejects the whole option rather than truncating it.
	 */
	private const SETTINGS_ARRAY_MAX   = 10000;

	/**
	 * Upper bound on `set` float values (24h in seconds); values outside
	 * `0 <= $f <= 86400` are rejected.
	 */
	private const SETTINGS_FLOAT_MAX = 86400;

	/**
	 * Upper bound on `set` integer values (2^30); values outside
	 * `0 <= $int <= 1073741824` are rejected.
	 */
	private const SETTINGS_INT_MAX = 1073741824;

	/**
	 * `set` whitelist: WP option name → sanitization type. An option absent
	 * here is refused outright, so this list and `hub-control.tsl`'s
	 * `add_setting` lines must stay in step — a hub push naming anything else
	 * comes back as "unknown option".
	 *
	 * The sanitizer also handles `int` and `float`; no entry claims those
	 * types today, so SETTINGS_INT_MAX / SETTINGS_FLOAT_MAX bound nothing
	 * until one does.
	 *
	 * @var array<string,string>
	 */
	private const SETTINGS_OPTIONS = [
		'newspack_event_logger_nodes_rules'            => 'array',
		'newspack_event_logger_nodes_log_memory'       => 'bool',
		'newspack_event_logger_nodes_flush_every_line' => 'bool',
	];

	/**
	 * Valid sort fields for the `urls` verb; anything outside falls back
	 * to `count`.
	 */
	private const URL_SORTS = [ 'count', 'url', 'avg_ms', 'min_ms', 'max_ms', 'p95_ms', 'avg_peak_mb', 'last_updated' ];

	/**
	 * URL-index read seam. Lazily-defaulted to the real merge-across-partitions
	 * loader (load_index_default). Tests reassign it to COUNT index reads without
	 * short-circuiting the production fan-out — the surrounding memo + the merge
	 * logic still run as real code (mirrors Insights_CI_Demo_Node::$read_items).
	 *
	 * Resolved once per request through index(); reassign in a test bootstrap,
	 * restore in a finally.
	 *
	 * Signature: `function (): array<int,array<string,mixed>>`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $load_index = null;

	/**
	 * Per-request memo of the merged URL index; null until index() reads once.
	 * Deliberately an instance property, never a static: the node is built per
	 * request graph, so the memo dies with it and no long-lived worker can
	 * serve a stale snapshot.
	 *
	 * @var array<int,array<array-key,mixed>>|null
	 */
	private ?array $index_cache = null;

	/**
	 * Type-coerce + bounds-check a single value for `set`.
	 *
	 * Rejection is signalled by null, so a legitimately-null sanitized value is
	 * not representable — every accepted type here returns a scalar or array.
	 *
	 * @param mixed  $value Raw input.
	 * @param string $type  One of int|float|bool|array; anything else rejects.
	 * @return mixed|null Sanitized value, or null to reject.
	 */
	private static function sanitize_settings_value( mixed $value, string $type ): mixed {
		switch ( $type ) {
			case 'int':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				$int = (int) $value;
				if ( $int < 0 || $int > self::SETTINGS_INT_MAX ) {
					return null;
				}
				return $int;
			case 'float':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				$f = (float) $value;
				if ( $f < 0 || $f > self::SETTINGS_FLOAT_MAX ) {
					return null;
				}
				return $f;
			case 'bool':
				return (bool) $value;
			case 'array':
				if ( ! \is_array( $value ) ) {
					return null;
				}
				return self::sanitize_settings_array( $value );
		}
		return null;
	}

	/**
	 * Bounded-recursion array sanitizer for `set`: depth cap
	 * SETTINGS_ARRAY_DEPTH, per-level size cap SETTINGS_ARRAY_MAX, string keys
	 * and string values through `sanitize_text_field`.
	 *
	 * An object is DROPPED silently; a too-deep or too-wide array rejects the
	 * whole option. NULL survives: it is inert, and it is load-bearing on the
	 * wire — a heavy rule syncs as a POINTER whose `hooks` key is an explicit
	 * null, and dropping it produced a map `Rule::from_array()` refuses, so a
	 * normal settings push could only ever fail.
	 *
	 * @param array<mixed,mixed> $arr   Input array.
	 * @param int                $depth Current recursion depth.
	 * @return array<mixed,mixed>|null Sanitized array, or null if too deep/large.
	 */
	private static function sanitize_settings_array( array $arr, int $depth = 0 ): ?array {
		if ( $depth > self::SETTINGS_ARRAY_DEPTH ) {
			return null;
		}
		if ( \count( $arr ) > self::SETTINGS_ARRAY_MAX ) {
			return null;
		}
		$out = [];
		foreach ( $arr as $key => $value ) {
			$safe_key = \is_int( $key ) ? $key : \sanitize_text_field( $key );
			if ( \is_string( $value ) ) {
				$out[ $safe_key ] = \sanitize_text_field( $value );
			} elseif ( null === $value || \is_bool( $value ) || \is_int( $value ) || \is_float( $value ) ) {
				$out[ $safe_key ] = $value;
			} elseif ( \is_array( $value ) ) {
				$nested = self::sanitize_settings_array( $value, $depth + 1 );
				if ( null === $nested ) {
					return null;
				}
				$out[ $safe_key ] = $nested;
			}
		}
		return $out;
	}

	/**
	 * Walk every recent URL bucket for the given hash and emit a per-bucket
	 * `{count, sum_ms, sum_peak_mb}` time series, keyed by bucket and sorted
	 * ascending. Zero-count buckets are skipped, so the series is sparse.
	 *
	 * @param string $hash 12-char URL hash.
	 * @return array<string,mixed>
	 */
	private static function build_url_time_series( string $hash ): array {
		$buckets = self::recent_url_buckets();
		$series  = [];
		foreach ( self::stats_stores() as $store ) {
			$rows = $store->get_url_buckets( $buckets );
			foreach ( $rows as $bucket_key => $bucket_data ) {
				if ( ! \is_array( $bucket_data ) || ! isset( $bucket_data[ $hash ] ) ) {
					continue;
				}
				$stats = Core::arr( $bucket_data[ $hash ] );
				$count = Core::as_int( $stats['count'] ?? 0 );
				if ( 0 === $count ) {
					continue;
				}
				// sum_ms is current; legacy sum_req_time is in seconds.
				$sum_ms = isset( $stats['sum_ms'] )
					? Core::as_float( $stats['sum_ms'] )
					: Core::as_float( $stats['sum_req_time'] ?? 0 ) * 1000.0;
				$series[ $bucket_key ] ??= [ 'count' => 0, 'sum_ms' => 0.0, 'sum_peak_mb' => 0.0 ];
				$series[ $bucket_key ]['count']       += $count;
				$series[ $bucket_key ]['sum_ms']      += $sum_ms;
				$series[ $bucket_key ]['sum_peak_mb'] += Core::as_float( $stats['sum_peak_mb'] ?? 0 );
			}
		}
		\ksort( $series );
		return $series;
	}

	/**
	 * Build the per-server category leaderboard for the recent window.
	 *
	 * @param string $server Server name to scope to.
	 * @return array<string,mixed>
	 */
	private static function build_server_leaderboard( string $server ): array {
		$count        = 0;
		$sum_req_time = 0.0;
		$sums         = [];
		$buckets      = self::recent_url_buckets();
		foreach ( self::stats_stores() as $store ) {
			foreach ( $buckets as $b ) {
				$row = $store->get_server_leaderboard_bucket( $server, $b );
				if ( empty( $row ) ) {
					continue;
				}
				$count        += Core::as_int( $row['count'] ?? 0 );
				$sum_req_time += Core::as_float( $row['sum_req_time'] ?? 0 );
				/** @var array<string,mixed> $categories -- decoded memcache leaderboard blob, keyed by category name. */
				$categories    = \is_array( $row['categories'] ?? null ) ? $row['categories'] : [];
				self::accumulate_leaderboard_categories( $sums, $categories );
			}
		}
		return Stats_Store::sums_to_display( $count, $sum_req_time, $sums );
	}

	/**
	 * Sum-merge dimensional buckets across all partitions for one dim/server.
	 * The server dimension is the global routing index: Flame Builder deliberately
	 * omits its redundant per-server copy, so keep that dimension global while a
	 * server scope narrows every other dimension.
	 *
	 * @param string $dimension One of DIMENSIONS.
	 * @param string $server    Server scope; ignored for the `server` dimension.
	 * @return array<array-key,mixed> Bucket keys derive from decoded memcache blobs.
	 */
	private static function merge_dim_across_partitions( string $dimension, string $server ): array {
		$store_server = 'server' === $dimension ? '' : $server;
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			self::merge_dim_buckets_into( $merged, $store->get_dimensional( $dimension, $store_server ) );
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge category buckets across all partitions (global scope).
	 *
	 * @return array<string,mixed>
	 */
	private static function merge_categories_across_partitions(): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			self::merge_category_buckets_into( $merged, $store->get_categories() );
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge per-server category buckets across all partitions.
	 *
	 * @param string $server Server name to scope to.
	 * @return array<string,mixed>
	 */
	private static function merge_server_categories_across_partitions( string $server ): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			self::merge_category_buckets_into( $merged, $store->get_server_categories( $server ) );
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge per-URL category buckets for one hash.
	 *
	 * @param string $hash 12-char URL hash.
	 * @return array<string,mixed>
	 */
	private static function merge_url_categories( string $hash ): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			self::merge_category_buckets_into( $merged, $store->get_url_categories( $hash ) );
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge category `[bucket => [cat => {t,c,n}]]` blobs into the running
	 * totals. Shared by the three category-merge variants (global, per-server,
	 * per-URL), which iterate identically and differ only in the store read.
	 *
	 * @param array<string,array<string,array{t:float,c:float,n:int}>> $merged Mutated.
	 * @param array<string,mixed>                                       $rows   Inbound.
	 */
	private static function merge_category_buckets_into( array &$merged, array $rows ): void {
		foreach ( $rows as $bucket => $values ) {
			$merged[ $bucket ] ??= [];
			if ( ! \is_array( $values ) ) {
				continue;
			}
			foreach ( $values as $cat => $entry ) {
				$entry_arr = Core::arr( $entry );
				$merged[ $bucket ][ $cat ] ??= [ 't' => 0.0, 'c' => 0.0, 'n' => 0 ];
				$merged[ $bucket ][ $cat ]['t'] += Core::as_float( $entry_arr['t'] ?? 0 );
				$merged[ $bucket ][ $cat ]['c'] += Core::as_float( $entry_arr['c'] ?? 0 );
				$merged[ $bucket ][ $cat ]['n'] += Core::as_int( $entry_arr['n'] ?? 0 );
			}
		}
	}

	/**
	 * Compose the overview payload shape from a pre-loaded URL index.
	 *
	 * Takes the index as a parameter rather than calling index() itself so the
	 * caller controls the single fan-out. `most_requested` is the head of
	 * `$index` untouched, which assumes the count-DESC sort load_index_default
	 * applies; `slowest_urls` re-sorts a copy by p95.
	 *
	 * @param array<int,array<array-key,mixed>> $index Output of the memoized index() (load_index_default).
	 * @return array<string,mixed>
	 */
	private static function build_overview_payload( array $index ): array {
		$time_series       = self::merge_hourly_across_partitions();
		$total_requests    = 0;
		$total_sum_ms      = 0.0;
		$total_sum_peak_mb = 0.0;
		foreach ( $time_series as $row ) {
			$row_arr            = Core::arr( $row );
			$total_requests    += Core::as_int( $row_arr['count'] ?? 0 );
			$total_sum_ms      += Core::as_float( $row_arr['sum_ms'] ?? 0 );
			$total_sum_peak_mb += Core::as_float( $row_arr['sum_peak_mb'] ?? 0 );
		}

		$slowest = $index;
		\usort( $slowest, static fn ( $a, $b ) => ( $b['p95_ms'] ?? 0 ) <=> ( $a['p95_ms'] ?? 0 ) );

		return [
			'total_urls'            => \count( $index ),
			'total_requests'        => $total_requests,
			'global_avg_ms'         => $total_requests > 0 ? $total_sum_ms / $total_requests : 0.0,
			'global_avg_peak_mb'    => $total_requests > 0 ? $total_sum_peak_mb / $total_requests : 0.0,
			'slowest_urls'          => \array_slice( $slowest, 0, 10 ),
			'most_requested'        => \array_slice( $index, 0, 10 ),
			'aggregate_time_series' => $time_series,
		];
	}

	/**
	 * Sum-merge per-partition hourly buckets into one sorted time_series.
	 *
	 * @return array<int,mixed>
	 */
	private static function merge_hourly_across_partitions(): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			foreach ( $store->get_hourly() as $hour => $row ) {
				$row_arr = Core::arr( $row );
				$merged[ $hour ] ??= [
					'hour'        => $hour,
					'count'       => 0,
					'sum_ms'      => 0.0,
					'sum_peak_mb' => 0.0,
				];
				$merged[ $hour ]['count']       += Core::as_int( $row_arr['count'] ?? 0 );
				$merged[ $hour ]['sum_ms']      += Core::as_float( $row_arr['sum_ms'] ?? 0 );
				$merged[ $hour ]['sum_peak_mb'] += Core::as_float( $row_arr['sum_peak_mb'] ?? 0 );
			}
		}
		\ksort( $merged );
		return \array_values( $merged );
	}

	/**
	 * Pull the per-URL aggregate stats blob (flame, profiles, last_modified).
	 * First partition with a matching blob wins — the blob is whole, not
	 * summable, so there is nothing to merge across partitions.
	 *
	 * @param string $hash 12-char URL hash.
	 * @return array<array-key,mixed>|null Decoded per-URL stats blob from get_url_stats().
	 */
	private static function find_url_aggregate( string $hash ): ?array {
		foreach ( self::stats_stores() as $store ) {
			$stats = $store->get_url_stats( $hash );
			if ( null !== $stats ) {
				return $stats;
			}
		}
		return null;
	}

	/**
	 * Locate a single request index entry by rid in one partition and return the
	 * search shape `{rid, partition, url_hash}` — enough for the dashboard to
	 * then ask for `request_detail`; the request body is not read here.
	 *
	 * @param string $dir           Partition directory.
	 * @param int    $partition     Partition index, echoed back in the result.
	 * @param string $rid           Request id to match.
	 * @param int    $entries_count Running scan budget, shared across partitions
	 *                              by the caller and mutated here.
	 * @return array<string,mixed>|null Search shape, or null when unmatched.
	 */
	private static function find_request_index_entry( string $dir, int $partition, string $rid, int &$entries_count ): ?array {
		$result   = null;
		$requests = new Partition_Node();
		self::name_scratch_partition( $requests, 'requests', $partition );
		$requests->arguments( [ $dir ] );
		$requests->with_index(
			static function ( array $message, array $position ): ?string {
				return Request_Builder_Node::format_index_entry( $message, $position );
			}
		);
		$requests->scan_index(
			static function ( string $line ) use ( &$result, &$entries_count, $partition, $rid ): ?bool {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = Request_Builder_Node::parse_request_index( $line );
				if ( ! \is_array( $entry ) || \trim( Core::as_string( $entry['rid'] ) ) !== $rid ) {
					return null;
				}
				$result = [
					'rid'       => $rid,
					'partition' => $partition,
					'url_hash'  => \trim( Core::as_string( $entry['url_hash'] ) ),
				];
				return false;
			},
			true
		);
		$requests->remove_node();
		return $result;
	}

	/**
	 * Pattern-search the RECENT firehose window; return a bounded summary of matching
	 * REQUESTS (grouped by rid). Reuses the shared Reqgrep_Core grouping/matching
	 * engine so the dashboard and `wp nodes reqgrep` agree byte-for-byte on what
	 * matches. Each firehose partition is drained by an EPHEMERAL request-scope
	 * Consumer (no offsetlog/deadletter, seeded at `recent`) removed in a finally, so
	 * the workers' durable cursor dirs are never touched. Bounded three ways: a
	 * per-request byte/line cap (in the engine), a global scan-line budget, and a
	 * result cap — every limit is reported honestly in `truncated`.
	 *
	 * @param string $pattern Raw user pattern; matched case-insensitively.
	 * @param int    $limit   Maximum matching requests to return.
	 * @return array{pattern:string, scope:string, scanned_partitions:int, results:array<int,array<string,mixed>>, truncated:bool, result_count:int}
	 */
	private static function run_request_grep( string $pattern, int $limit ): array {
		$results       = [];
		$truncated     = false;
		$scanned_lines = 0;
		$regex         = Reqgrep_Core::compile( $pattern );

		/** @param list<string> $lines */
		$on_complete = static function ( array $lines, string $rid, bool $clipped = false ) use ( &$results, &$truncated, $limit, $regex ): void {
			if ( \count( $results ) >= $limit ) {
				$truncated = true;
				return;
			}
			// Engine byte/line caps clip the tail — the reply must say so.
			$truncated = $truncated || $clipped;
			$results[] = self::summarize_grep_request( $lines, $rid, $regex );
		};

		$inflight = new LRU_Cache( self::GREP_INFLIGHT_BUCKET_SIZE, self::GREP_INFLIGHT_NUM_BUCKETS );
		$core     = new Reqgrep_Core(
			$pattern,
			$inflight,
			self::GREP_HISTORY_BUCKET_SIZE,
			self::GREP_HISTORY_NUM_BUCKETS,
			$on_complete
		);

		/** @param array<int,mixed> $message */
		$on_message = static function ( array $message ) use ( $core, &$scanned_lines, &$truncated ): void {
			$entry = $message[ Message::VALUE ];
			$rid   = Core::as_string( $message[ Message::KEY ] ?? '' );
			if ( ! \is_array( $entry ) || '' === $rid ) {
				return;
			}
			if ( $scanned_lines >= self::GREP_MAX_SCAN_LINES ) {
				$truncated = true;
				return;
			}
			++$scanned_lines;
			// array_values keeps the positional list for the packer.
			$core->push( $entry, $rid, Message::packed( \array_values( $message ) ) );
		};

		$scanned_partitions = 0;
		foreach ( Log_Manager::firehose_dirs() as $p => $source_dir ) {
			if ( ! \is_dir( $source_dir ) ) {
				continue;
			}
			$consumer = new Consumer_Node();
			self::name_scratch_consumer( $consumer, $p );
			$consumer->sink( new Callback_Node( $on_message ) );
			try {
				// source_dir only: no offsetlog/deadletter (ephemeral).
				$consumer->arguments( [ $source_dir ] );
				$consumer->next_offset( 'recent' );
				$consumer->drain();
			} finally {
				$consumer->remove_node();
			}
			++$scanned_partitions;
		}

		return [
			'pattern'            => $pattern,
			'scope'              => 'recent',
			'scanned_partitions' => $scanned_partitions,
			'results'            => $results,
			'truncated'          => $truncated,
			'result_count'       => \count( $results ),
		];
	}

	/**
	 * Build one matching request's summary from its grouped packed-Message lines.
	 * url/method come from the `request` firehose entry ("METHOD url"); ts from the
	 * `process (start)` entry (fallback: the first entry). match_count / excerpt are
	 * derived by re-matching each packed line against the SAME compiled regex the
	 * grouping used, so the dashboard's count agrees with reqgrep's.
	 *
	 * @param array<array-key,mixed> $lines Packed Message envelopes for the request.
	 * @param string                 $rid   Request id.
	 * @param string                 $regex The pre-compiled search regex.
	 * @return array<string,mixed>
	 */
	private static function summarize_grep_request( array $lines, string $rid, string $regex ): array {
		$url         = '';
		$method      = '';
		$ts          = 0.0;
		$ts_set      = false;
		$match_count = 0;
		$excerpt     = '';

		foreach ( $lines as $line ) {
			if ( ! \is_string( $line ) ) {
				continue;
			}
			try {
				$message = Message::unpacked( $line );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
			$entry = $message[ Message::VALUE ];
			if ( ! \is_array( $entry ) ) {
				continue;
			}
			$key = Core::as_string( $entry['k'] ?? '' );

			if ( ! $ts_set ) {
				$ts     = Core::num_float( $entry['ts'] ?? 0 );
				$ts_set = true;
			}
			if ( 'process (start)' === $key ) {
				$ts = Core::num_float( $entry['ts'] ?? $ts );
			}
			if ( 'request' === $key && '' === $method ) {
				[ $method, $url ] = self::parse_request_line( Core::as_string( $entry['m'] ?? '' ) );
			}

			if ( 1 === \preg_match( $regex, $line ) ) {
				++$match_count;
				if ( '' === $excerpt ) {
					$excerpt = self::grep_excerpt( $key, $entry['m'] ?? '' );
				}
			}
		}

		return [
			'rid'                 => $rid,
			'url'                 => $url,
			'method'              => $method,
			'ts'                  => $ts,
			'match_count'         => $match_count,
			'first_match_excerpt' => $excerpt,
		];
	}

	/**
	 * Parse a firehose `request` entry's `m` ("METHOD full-url") into [method, url].
	 * Mirrors Request_Builder_Node's request-line parse (query stripped from url).
	 *
	 * @param string $message The entry's `m` field.
	 * @return array{0:string,1:string}
	 */
	private static function parse_request_line( string $message ): array {
		$parts  = \explode( ' ', $message, 2 );
		$method = $parts[0];
		$url    = isset( $parts[1] ) ? \explode( '?', $parts[1], 2 )[0] : '';
		return [ $method, $url ];
	}

	/**
	 * Bounded, human-readable excerpt of the first matching entry ("key: message").
	 * Arrays JSON-encode; the whole thing is trimmed to GREP_EXCERPT_LENGTH.
	 *
	 * @param string $key     The entry `k` field.
	 * @param mixed  $message The entry `m` field (string or array).
	 */
	private static function grep_excerpt( string $key, mixed $message ): string {
		$text = \is_array( $message ) ? Core::as_string( \wp_json_encode( $message ) ) : Core::as_string( $message );
		$raw  = '' === $key ? $text : "{$key}: {$text}";
		return \substr( \trim( $raw ), 0, self::GREP_EXCERPT_LENGTH );
	}

	/** Name a transient scratch Consumer uniquely (per scan) so a live worker's registry can't collide; caller removes it. */
	private static function name_scratch_consumer( Consumer_Node $consumer, int $index ): void {
		$token = \getmypid() . '-' . \spl_object_id( $consumer );
		$consumer->name( "firehose-grep.{$token}.p{$index}" );
	}

	/**
	 * Assemble the brief for a descriptor CHAIN — the clicked element first,
	 * then each `[data-ask]` ancestor, outermost last.
	 *
	 * The chain is what makes the small descriptor vocabulary self-sufficient:
	 * `span:wp_loaded` names a span but not which request's, and DOM nesting
	 * already expresses that containment, so the picker sends it rather than
	 * inventing a second attribute for scope.
	 *
	 * @param list<string> $descriptors Target first, containers after.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On an unknown descriptor or a missing context.
	 */
	private static function assemble_ask( array $descriptors ): array {
		$target = Ask_Assembler::parse_descriptor( Core::as_string( $descriptors[0] ?? '' ) );
		if ( null === $target ) {
			throw new \RuntimeException(
				'' === Core::as_string( $descriptors[0] ?? '' )
					? 'descriptor required'
					: \esc_html( 'unknown descriptor: ' . Core::as_string( $descriptors[0] ) )
			);
		}
		$context = \array_slice( $descriptors, 1 );

		switch ( $target['type'] ) {
			case 'url':
				return self::ask_url( $target['id'] );
			case 'request':
				return self::ask_request( $target['id'], (int) $target['qualifier'] );
			case 'span':
				return self::ask_span( $target['id'], $context );
			case 'entry':
				return self::ask_entry( (int) $target['id'], $context );
			case 'category':
				return self::ask_category( $target['id'], $context );
		}
		throw new \RuntimeException( \esc_html( 'unknown descriptor: ' . $target['type'] ) );
	}

	/**
	 * The `url:` brief — stats, worst recent requests, and the
	 * cold-start finding when nothing governs it.
	 *
	 * @return array<string,mixed>
	 * @throws \RuntimeException When no URL row carries that hash.
	 */
	private static function ask_url( string $hash ): array {
		$stats = null;
		foreach ( self::load_index_default() as $row ) {
			if ( Core::as_string( $row['hash'] ?? '' ) === $hash ) {
				$stats = $row;
				break;
			}
		}
		if ( null === $stats ) {
			throw new \RuntimeException( \esc_html( "URL not found: {$hash}" ) );
		}
		return Ask_Assembler::for_url(
			$stats,
			self::find_recent_requests_for_url( $hash ),
			self::rule_for_url( Core::as_string( $stats['url'] ?? '' ) )
		);
	}

	/**
	 * Merged URL index across all partitions, shaped for dashboard display.
	 *
	 * Rows are keyed by URL hash while merging, then flattened to a list sorted
	 * by `count` DESC. That sort is load-bearing: `build_overview_payload` takes
	 * the head of this list as `most_requested` without re-sorting, so a
	 * replacement `$load_index` seam must sort the same way.
	 *
	 * The bucket key IS the hash `Log_Manager::url_hash()` stamped on the
	 * record — never derive another, or the row indexes under a hash no rid
	 * lookup can produce. Flame_Builder writes `sum_ms` directly; older
	 * aggregator buckets carry `sum_req_time` in seconds, folded in at ×1000.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function load_index_default(): array {
		$buckets = self::recent_url_buckets();
		$result  = [];
		foreach ( self::stats_stores() as $store ) {
			$rows = $store->get_url_buckets( $buckets );
			foreach ( $rows as $bucket_data ) {
				if ( ! \is_array( $bucket_data ) ) {
					continue;
				}
				foreach ( $bucket_data as $key => $stats ) {
					$stat_arr = Core::arr( $stats );
					// An all-digit hash arrives as an int array key; cast back.
					$hash     = (string) $key;
					if ( ! isset( $result[ $hash ] ) ) {
						$result[ $hash ] = [
							'hash'        => $hash,
							'url'         => Core::as_string( $stat_arr['url'] ?? '' ),
							'count'       => 0,
							'count_2xx'   => 0,
							'count_3xx'   => 0,
							'count_4xx'   => 0,
							'count_5xx'   => 0,
							'sum_ms'      => 0.0,
							'max_ms'      => 0.0,
							'p50_ms'      => 0.0,
							'p95_ms'      => 0.0,
							'p99_ms'      => 0.0,
							'sum_peak_mb' => 0.0,
							'max_peak_mb' => 0.0,
							'last_seen'   => 0,
						];
					}
					$entry = $result[ $hash ];
					// Whichever bucket names the URL wins; merge order varies.
					if ( '' === $entry['url'] ) {
						$entry['url'] = Core::as_string( $stat_arr['url'] ?? '' );
					}
					$entry['count']     += Core::as_int( $stat_arr['count']     ?? 0 );
					$entry['count_2xx'] += Core::as_int( $stat_arr['count_2xx'] ?? 0 );
					$entry['count_3xx'] += Core::as_int( $stat_arr['count_3xx'] ?? 0 );
					$entry['count_4xx'] += Core::as_int( $stat_arr['count_4xx'] ?? 0 );
					$entry['count_5xx'] += Core::as_int( $stat_arr['count_5xx'] ?? 0 );
					// sum_ms is current; legacy sum_req_time is in seconds.
					$entry['sum_ms']      += isset( $stat_arr['sum_ms'] )
						? Core::as_float( $stat_arr['sum_ms'] )
						: Core::as_float( $stat_arr['sum_req_time'] ?? 0 ) * 1000.0;
					$entry['sum_peak_mb'] += Core::as_float( $stat_arr['sum_peak_mb'] ?? 0 );
					// Fold min_ms only from timed buckets; skip sentinels.
					if ( isset( $stat_arr['min_ms'] ) && ( $stat_arr['timed_count'] ?? 0 ) > 0 ) {
						$stat_min        = Core::as_float( $stat_arr['min_ms'] );
						$entry['min_ms'] = isset( $entry['min_ms'] )
							? \min( Core::as_float( $entry['min_ms'] ), $stat_min )
							: $stat_min;
					}
					$entry['max_ms']      = \max( Core::as_float( $entry['max_ms'] ),      Core::as_float( $stat_arr['max_ms']      ?? 0 ) );
					$entry['max_peak_mb'] = \max( Core::as_float( $entry['max_peak_mb'] ), Core::as_float( $stat_arr['max_peak_mb'] ?? 0 ) );
					foreach ( [ 'p50_ms', 'p95_ms', 'p99_ms' ] as $k ) {
						if ( ! empty( $stat_arr[ $k ] ) ) {
							$entry[ $k ] = Core::as_float( $stat_arr[ $k ] );
						}
					}
					$entry['last_seen'] = \max(
						Core::as_int( $entry['last_seen'] ),
						Core::as_int( $stat_arr['last_seen'] ?? 0 )
					);
					$result[ $hash ] = $entry;
				}
			}
		}

		// Convert into the display shape the React tree expects.
		$out = [];
		foreach ( $result as $entry ) {
			$count = $entry['count'];
			$denom = \max( 1, $count );
			$out[] = [
				'hash'         => $entry['hash'],
				'url'          => $entry['url'],
				'count'        => $count,
				'count_2xx'    => $entry['count_2xx'],
				'count_3xx'    => $entry['count_3xx'],
				'count_4xx'    => $entry['count_4xx'],
				'count_5xx'    => $entry['count_5xx'],
				'avg_ms'       => $entry['sum_ms'] / $denom,
				'min_ms'       => (float) ( $entry['min_ms'] ?? 0 ),
				'max_ms'       => $entry['max_ms'],
				'p50_ms'       => $entry['p50_ms'],
				'p95_ms'       => $entry['p95_ms'],
				'p99_ms'       => $entry['p99_ms'],
				'avg_peak_mb'  => $entry['sum_peak_mb'] / $denom,
				'max_peak_mb'  => $entry['max_peak_mb'],
				'last_updated' => $entry['last_seen'],
			];
		}
		\usort( $out, static fn ( $a, $b ) => $b['count'] <=> $a['count'] );
		return $out;
	}

	/**
	 * Sum-merge per-URL dimensional buckets for one dim/hash.
	 *
	 * @param string $hash      12-char URL hash.
	 * @param string $dimension One of DIMENSIONS.
	 * @return array<array-key,mixed> Bucket keys derive from decoded memcache blobs.
	 */
	private static function merge_url_dim( string $hash, string $dimension ): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			$rows = $store->get_url_dimensional( $hash );
			$dim  = $rows[ $dimension ] ?? [];
			if ( \is_array( $dim ) ) {
				self::merge_dim_buckets_into( $merged, $dim );
			}
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge dimensional `[bucket => [name => {c,s,m}]]` blobs into the running
	 * totals. Shared by the global (merge_dim_across_partitions) and per-URL
	 * (merge_url_dim) breakdown builders — the two iterate identically.
	 *
	 * @param array<array-key,array<array-key,array{c:int,s:float,m:float}>> $merged Mutated.
	 * @param array<array-key,mixed>                                         $rows   Inbound (key-agnostic).
	 */
	private static function merge_dim_buckets_into( array &$merged, array $rows ): void {
		foreach ( $rows as $bucket => $values ) {
			$merged[ $bucket ] ??= [];
			if ( ! \is_array( $values ) ) {
				continue;
			}
			foreach ( $values as $name => $entry ) {
				$entry_arr = Core::arr( $entry );
				$merged[ $bucket ][ $name ] ??= [ 'c' => 0, 's' => 0.0, 'm' => 0.0 ];
				$merged[ $bucket ][ $name ]['c'] += Core::as_int( $entry_arr['c'] ?? 0 );
				$merged[ $bucket ][ $name ]['s'] += Core::as_float( $entry_arr['s'] ?? 0 );
				$merged[ $bucket ][ $name ]['m'] += Core::as_float( $entry_arr['m'] ?? 0 );
			}
		}
	}

	/**
	 * Walk the request partitions newest-first and collect up to 500 index
	 * entries for the given url_hash, deduplicated by rid and sorted by
	 * timestamp DESC. Stops early on either the 500-result cap or the shared
	 * MAX_INDEX_ENTRIES scan budget, which spans all partitions.
	 *
	 * @param string $url_hash 12-char URL hash to match.
	 * @return array<int,array<string,mixed>>
	 */
	private static function find_recent_requests_for_url( string $url_hash ): array {
		$requests      = [];
		$entries_count = 0;
		foreach ( Bootstrap::node_dirs( self::NODE_REQUESTS ) as $p => $dir ) {
			$partition = new Partition_Node();
			self::name_scratch_partition( $partition, 'requests', $p );
			$partition->arguments( [ $dir ] );
			$partition->with_index(
				static function ( array $message, array $position ): ?string {
					return Request_Builder_Node::format_index_entry( $message, $position );
				}
			);
			$partition->scan_index(
				static function ( string $line, int $segment ) use ( &$requests, &$entries_count, $url_hash, $p ): ?bool {
					++$entries_count;
					if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = Request_Builder_Node::parse_request_index( $line );
					if ( ! \is_array( $entry ) || \trim( Core::as_string( $entry['url_hash'] ) ) !== $url_hash ) {
						return null;
					}
					$requests[] = [
						'rid'          => \trim( Core::as_string( $entry['rid'] ) ),
						'timestamp'    => $entry['timestamp'] ?? 0,
						'duration_ms'  => $entry['duration_ms'] ?? 0,
						'status_code'  => $entry['status_code'] ?? 0,
						'peak_mb'      => $entry['peak_mb'] ?? 0,
						'method'       => $entry['method'] ?? '',
						'error_status' => $entry['error_status'] ?? null,
						'segment'   => $entry['segment'] ?? $segment,
						'offset'       => $entry['offset'] ?? 0,
						'length'       => $entry['length'] ?? 0,
						'partition'    => $p,
					];
					return \count( $requests ) >= 500 ? false : null;
				},
				true
			);
			$partition->remove_node();
			if ( \count( $requests ) >= 500 || $entries_count > self::MAX_INDEX_ENTRIES ) {
				break;
			}
		}

		\usort( $requests, static fn ( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		$seen   = [];
		$unique = [];
		foreach ( $requests as $r ) {
			if ( ! isset( $seen[ $r['rid'] ] ) ) {
				$seen[ $r['rid'] ] = true;
				$unique[]          = $r;
				if ( \count( $unique ) >= 500 ) {
					break;
				}
			}
		}
		return $unique;
	}

	/**
	 * The rule governing a URL, for the surfaces that hold no record — the
	 * `url:` brief works from an index row. Matching takes the PATH: a stored
	 * url is absolute, and `Rule_Matcher` compares against patterns like `/`.
	 */
	private static function rule_for_url( string $url ): ?Rule {
		if ( '' === $url ) {
			return null;
		}
		$path = Core::as_string( \wp_parse_url( $url, \PHP_URL_PATH ), '' );
		if ( '' === $path ) {
			$path = \str_starts_with( $url, '/' ) ? $url : '/';
		}
		$query = Core::as_string( \wp_parse_url( $url, \PHP_URL_QUERY ), '' );
		return Rule_Set::load()->matcher()->match( '' === $query ? $path : "{$path}?{$query}" );
	}

	/**
	 * The `request:` brief.
	 *
	 * @return array<string,mixed>
	 * @throws \RuntimeException When the rid resolves nowhere.
	 */
	private static function ask_request( string $rid, int $partition ): array {
		$record = self::load_request( $rid, $partition );
		return Ask_Assembler::for_request( $record, self::rule_for_record( $record ) );
	}

	/**
	 * The `span:` brief. A span is not addressable on its own — it needs the
	 * request it ran in, which the descriptor chain supplies.
	 *
	 * @param list<string> $context Container descriptors, outermost last.
	 * @return array<string,mixed>
	 * @throws \RuntimeException With no request context, or an absent span.
	 */
	private static function ask_span( string $name, array $context ): array {
		$record = self::request_from_context( $context, 'span' );
		$brief  = Ask_Assembler::for_span(
			$record,
			$name,
			self::rule_for_record( $record ),
			self::descriptor_of( $context, 'request' )
		);
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no span '{$name}' in this request" ) );
		}
		return $brief;
	}

	/**
	 * The rule this request ran under. Findings about a span or an entry are
	 * actionable only through the rule governing THAT request's URL — the
	 * finest grain a rule has is a URL pattern, and there is no such thing as a
	 * rule about a hook.
	 *
	 * The RECORD answers this: `Request_Builder_Node` stamps `rule_id` from the
	 * match the request itself made. Re-deriving it here found nothing, because
	 * a stored `url` is absolute and query-stripped (`https://host/path`) while
	 * rules are path patterns — so not even a catch-all `/` matched, and every
	 * brief reported "no rule governs this URL" and proposed creating one whose
	 * pattern could never match anything.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 */
	private static function rule_for_record( array $record ): ?Rule {
		$id = Core::as_string( $record['rule_id'] ?? '' );
		return '' === $id ? null : Rule_Set::load()->rule_by_id( $id );
	}

	/**
	 * The `entry:` brief.
	 *
	 * @param list<string> $context Container descriptors, outermost last.
	 * @return array<string,mixed>
	 * @throws \RuntimeException With no request context, or an absent entry.
	 */
	private static function ask_entry( int $n, array $context ): array {
		$brief = Ask_Assembler::for_entry( self::request_from_context( $context, 'entry' ), $n );
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no entry {$n} in this request" ) );
		}
		return $brief;
	}

	/**
	 * The `category:` brief. A breakdown row inside a request shows THAT
	 * request's profile, so the context chain decides which board answers —
	 * the global leaderboard describes a different thing entirely.
	 *
	 * @param list<string> $context Container descriptors, outermost last.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When neither board holds the category.
	 */
	private static function ask_category( string $name, array $context ): array {
		$record = self::request_in_context( $context );
		if ( null !== $record ) {
			$brief = Ask_Assembler::for_request_category( $record, $name );
			if ( null !== $brief ) {
				return $brief;
			}
		}
		$board      = self::build_global_leaderboard();
		$categories = \is_array( $board['categories'] ?? null ) ? $board['categories'] : [];
		$brief      = Ask_Assembler::for_category( $categories, $name );
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no category '{$name}' in this request or the recent window" ) );
		}
		return $brief;
	}

	/**
	 * Build the merged global category leaderboard for the recent window.
	 * Sums stay raw across the merge; `Stats_Store::sums_to_display` computes
	 * the means once at the end.
	 *
	 * @return array<string,mixed>
	 */
	private static function build_global_leaderboard(): array {
		$count        = 0;
		$sum_req_time = 0.0;
		$sums         = [];
		$buckets      = self::recent_url_buckets();
		foreach ( self::stats_stores() as $store ) {
			foreach ( $buckets as $b ) {
				$row = $store->get_leaderboard_bucket( $b );
				if ( empty( $row ) ) {
					continue;
				}
				$count        += Core::as_int( $row['count'] ?? 0 );
				$sum_req_time += Core::as_float( $row['sum_req_time'] ?? 0 );
				/** @var array<string,mixed> $categories -- decoded memcache leaderboard blob, keyed by category name. */
				$categories    = \is_array( $row['categories'] ?? null ) ? $row['categories'] : [];
				self::accumulate_leaderboard_categories( $sums, $categories );
			}
		}
		return Stats_Store::sums_to_display( $count, $sum_req_time, $sums );
	}

	/**
	 * Build a list of recent 5-min bucket keys (`Y-m-d-H-MM`, UTC) walking
	 * backwards from now. Capped at 288 (24h × 12 buckets/h) so memcache
	 * get_multi stays bounded regardless of the configured retention.
	 *
	 * @return array<int,string>
	 */
	private static function recent_url_buckets(): array {
		$now = \time();
		$out = [];
		for ( $i = 0; $i < 288; $i++ ) {
			$ts         = $now - ( $i * 300 );
			$min        = (int) \gmdate( 'i', $ts );
			$bucket_min = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', \STR_PAD_LEFT );
			$out[]      = \gmdate( 'Y-m-d-H', $ts ) . '-' . $bucket_min;
		}
		return \array_unique( $out );
	}

	/**
	 * Sum-merge a single leaderboard bucket's categories into the running totals.
	 * Used by both global + server leaderboard builders.
	 *
	 * @param array<string,array{samples:int,sum_time:float,sum_count:float,entries:array<int,mixed>}> $sums       Running totals (mutated).
	 * @param array<string,mixed>                                                             $categories Inbound categories.
	 */
	private static function accumulate_leaderboard_categories( array &$sums, array $categories ): void {
		foreach ( $categories as $cat => $data ) {
			$data_arr = Core::arr( $data );
			$sums[ $cat ] ??= [
				'samples'   => 0,
				'sum_time'  => 0.0,
				'sum_count' => 0.0,
				'entries'   => [],
			];
			$sums[ $cat ]['samples']   += Core::as_int( $data_arr['samples'] ?? 0 );
			$sums[ $cat ]['sum_time']  += Core::as_float( $data_arr['sum_time'] ?? 0 );
			$sums[ $cat ]['sum_count'] += Core::as_float( $data_arr['sum_count'] ?? 0 );
		}
	}

	/**
	 * One Stats_Store per flame-builder worker over the shared `Core::$memd`
	 * handle. `configure_stats <partition>` keys each store by the WORKER index,
	 * and nothing of it lands on disk — so the index space comes from the
	 * declaring topology's count, not from a dir listing.
	 *
	 * With no memcache handle this returns an empty list, which is what makes
	 * every stats reader above degrade to an empty or zeroed shape instead of
	 * throwing. Each store's TTL comes from the substrate `min_lifetime` key.
	 *
	 * @return array<int,Stats_Store>
	 */
	private static function stats_stores(): array {
		if ( null === Core::$memd ) {
			return [];
		}
		$max_lifespan = AppConfig::stats_retention_seconds();
		$stores       = [];
		foreach ( Bootstrap::node_partitions( self::NODE_FLAME_BUILDER ) as $p ) {
			$stores[] = new Stats_Store( $p, $max_lifespan );
		}
		return $stores;
	}

	/**
	 * The request record named by the first `request:` descriptor in a context
	 * chain.
	 *
	 * @param list<string> $context Container descriptors.
	 * @param string       $subject What was clicked, for the refusal text.
	 * @return array<array-key,mixed>
	 * @throws \RuntimeException When the chain carries no request.
	 */
	private static function request_from_context( array $context, string $subject ): array {
		$record = self::request_in_context( $context );
		if ( null === $record ) {
			throw new \RuntimeException( \esc_html( "a {$subject} needs its request for context" ) );
		}
		return $record;
	}

	/**
	 * The request a context chain names, or null when it names none. Separate
	 * from `request_from_context()` because a category is answerable either
	 * way, and only a span or an entry is not.
	 *
	 * @param list<string> $context Container descriptors.
	 * @return array<array-key,mixed>|null
	 */
	private static function request_in_context( array $context ): ?array {
		foreach ( $context as $descriptor ) {
			$parsed = Ask_Assembler::parse_descriptor( Core::as_string( $descriptor ) );
			if ( null !== $parsed && 'request' === $parsed['type'] ) {
				return self::load_request( $parsed['id'], (int) $parsed['qualifier'] );
			}
		}
		return null;
	}

	/**
	 * The descriptor of a given type in a context chain, verbatim. A brief's
	 * `fetch` pointer has to name what an agent would ASK, which is the
	 * descriptor as the picker wrote it — not the record it resolves to.
	 *
	 * @param list<string> $context Container descriptors.
	 * @return string The descriptor, or '' when the chain names none.
	 */
	private static function descriptor_of( array $context, string $type ): string {
		foreach ( $context as $descriptor ) {
			$text   = Core::as_string( $descriptor );
			$parsed = Ask_Assembler::parse_descriptor( $text );
			if ( null !== $parsed && $type === $parsed['type'] ) {
				return $text;
			}
		}
		return '';
	}

	/**
	 * One request record by rid, searching its hashed partition first.
	 *
	 * @return array<array-key,mixed>
	 * @throws \RuntimeException When the rid resolves nowhere.
	 */
	private static function load_request( string $rid, int $partition ): array {
		$dirs    = Bootstrap::node_dirs( self::NODE_REQUESTS );
		$scanned = [];
		if ( isset( $dirs[ $partition ] ) ) {
			$record            = self::find_request_in_partition( $dirs[ $partition ], $partition, $rid );
			$scanned[ $partition ] = true;
			if ( null !== $record ) {
				return $record;
			}
		}
		foreach ( self::search_order( $rid ) as $p => $dir ) {
			// search_order() repeats every partition after the hashed one.
			if ( isset( $scanned[ $p ] ) ) {
				continue;
			}
			$scanned[ $p ] = true;
			$record        = self::find_request_in_partition( $dir, $p, $rid );
			if ( null !== $record ) {
				return $record;
			}
		}
		throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
	}

	/**
	 * Read the full request body from a known partition, then merge any matching
	 * flame data in as `flame_data`. A missing flame is normal — flames are
	 * built asynchronously — and leaves the body otherwise intact.
	 *
	 * Unlike find_request_index_entry, the scan budget here is per-call: this
	 * walks exactly one partition.
	 *
	 * @param string $dir       Partition directory.
	 * @param int    $partition Partition index (names the scratch node).
	 * @param string $rid       Request id to match.
	 * @return array<array-key,mixed>|null Decoded request body (keys come from the JSON envelope).
	 */
	private static function find_request_in_partition( string $dir, int $partition, string $rid ): ?array {
		$result        = null;
		$entries_count = 0;
		$requests = new Partition_Node();
		self::name_scratch_partition( $requests, 'requests', $partition );
		$requests->arguments( [ $dir ] );
		$requests->with_index(
			static function ( array $message, array $position ): ?string {
				return Request_Builder_Node::format_index_entry( $message, $position );
			}
		);
		$requests->scan_index(
			static function ( string $line ) use ( &$result, &$entries_count, $requests, $rid ): ?bool {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = Request_Builder_Node::parse_request_index( $line );
				if ( ! \is_array( $entry ) || \trim( Core::as_string( $entry['rid'] ) ) !== $rid ) {
					return null;
				}
				$message = $requests->read_message_at(
					Core::as_int( $entry['segment'] ?? 0 ),
					Core::as_int( $entry['offset'] ?? 0 ),
					Core::as_int( $entry['length'] ?? 0 )
				);
				$req = \is_array( $message ) ? ( $message[ Message::VALUE ] ?? null ) : null;
				if ( ! \is_array( $req ) ) {
					return false;
				}
				$req['url_hash'] = \trim( Core::as_string( $entry['url_hash'] ) );
				$result          = $req;
				return false;
			},
			true
		);
		$requests->remove_node();

		if ( null === $result ) {
			return null;
		}

		$flame = self::find_flame_for_rid( $rid );
		if ( null !== $flame ) {
			$result['flame_data'] = $flame;
		}
		return $result;
	}

	/**
	 * Search every flame partition for a flame entry matching the rid; the
	 * first hit wins. Flame_Builder writes to whatever partition it's wired
	 * into, so a per-rid lookup has to fan out across all of them.
	 *
	 * @param string $rid Request id to match.
	 * @return array<array-key,mixed>|null Decoded flame blob (keys come from the JSON envelope).
	 */
	private static function find_flame_for_rid( string $rid ): ?array {
		$entries_count = 0;
		foreach ( Bootstrap::node_dirs( self::NODE_FLAMES ) as $p => $dir ) {
			$flames = new Partition_Node();
			self::name_scratch_partition( $flames, 'flames', $p );
			$flames->arguments( [ $dir ] );
			$flames->with_index(
				static function ( array $message, array $position ): ?string {
					return Flame_Builder_Node::format_index_entry( $message, $position );
				}
			);
			$result = null;
			$flames->scan_index(
				static function ( string $line ) use ( &$result, &$entries_count, $flames, $rid ): ?bool {
					++$entries_count;
					if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = Flame_Builder_Node::parse_flame_index( $line );
					if ( ! \is_array( $entry ) || \trim( $entry['rid'] ) !== $rid ) {
						return null;
					}
					$message = $flames->read_message_at(
						$entry['segment'],
						$entry['offset'],
						$entry['length']
					);
					$flame = \is_array( $message ) ? ( $message[ Message::VALUE ] ?? null ) : null;
					if ( \is_array( $flame ) ) {
						$result = $flame;
					}
					return false;
				},
				true
			);
			$flames->remove_node();
			if ( null !== $result ) {
				return $result;
			}
			if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
				break;
			}
		}
		return null;
	}

	/**
	 * Name (`{log}.{token}.p{N}`, unique per scan), self-patron, and Rule-4 interpreter-sink
	 * a transient scratch Partition. Callers remove_node() it after use.
	 *
	 * @param Partition_Node $partition Freshly-constructed scratch Partition.
	 * @param string         $log       Log basename ('requests' | 'flames').
	 * @param int            $index     Partition index.
	 */
	private static function name_scratch_partition( Partition_Node $partition, string $log, int $index ): void {
		$token = \getmypid() . '-' . \spl_object_id( $partition );
		$partition->name( "{$log}.{$token}.p{$index}" );
		$partition->patron( $partition );
		$ci = Core::node( Node_Names::COMMAND_INTERPRETER );
		if ( null === $partition->sink() && null !== $ci ) {
			$partition->sink( $ci );
		}
	}

	/**
	 * Request partitions to search for `$rid`, its own partition first.
	 *
	 * A rid rides the same hash the whole way: the firehose Topic routes it by
	 * KEY, the worker on that partition consumes it, and Request_Builder writes
	 * it to the request partition of the SAME index. So the hash names the
	 * partition outright and the rest of the fan-out is a fallback — needed
	 * because the guess uses the reader's partition count, which lags the
	 * writer's across a re-partition.
	 *
	 * @param string $rid Request id whose hash names the first partition.
	 * @return array<int,string> Partition index => dir, hashed partition first.
	 */
	private static function search_order( string $rid ): array {
		$dirs = Bootstrap::node_dirs( self::NODE_REQUESTS );
		$hit  = Partition_Node::hash_to_partition( $rid, \max( 1, \count( $dirs ) ) );
		if ( ! isset( $dirs[ $hit ] ) ) {
			return $dirs;
		}
		return [ $hit => $dirs[ $hit ] ] + $dirs;
	}

	/**
	 * Merged URL index for THIS request — read at most once and memoized, so the
	 * three verbs that derive from it (`overview`, `urls`, and `url_detail`'s
	 * stats lookup) share a single memcache fan-out instead of re-loading per
	 * verb. Resolves the `load_index` seam (the real loader by default) on the
	 * first call.
	 *
	 * @return array<int,array<array-key,mixed>>
	 */
	private function index(): array {
		if ( null !== $this->index_cache ) {
			return $this->index_cache;
		}
		$read = self::$load_index ?? static fn (): array => self::load_index_default();
		$raw  = $read();
		$rows = [];
		foreach ( Core::arr( $raw ) as $row ) {
			if ( \is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		$this->index_cache = $rows;
		return $this->index_cache;
	}

	/**
	 * Whether a URL row saw requests no status bucket accounted for — that is
	 * what the dashboard's "Errors" filter means: timeouts (T) and fatals (F),
	 * not 5xx, which IS a response.
	 *
	 * Server-side so `total` counts what is actually rendered; the client used
	 * to apply this filter alone, leaving the footer reading an unfiltered
	 * count ("1-100 of 5,000" above three rows).
	 *
	 * @param array<string,mixed> $row A URL index row.
	 */
	private static function has_unclassified_requests( array $row ): bool {
		$classified = Core::num_int( $row['count_2xx'] ?? 0 )
			+ Core::num_int( $row['count_3xx'] ?? 0 )
			+ Core::num_int( $row['count_4xx'] ?? 0 )
			+ Core::num_int( $row['count_5xx'] ?? 0 );
		return $classified < Core::num_int( $row['count'] ?? 0 );
	}

	/**
	 * Decode a synced array-option value. Settings_Sync_Node::scalarize()
	 * JSON-encodes arrays unconditionally, so the wire form is always JSON. A
	 * non-JSON value is a contract violation: reject it explicitly to [] with a
	 * rate-limited notice rather than silently mis-parsing it.
	 *
	 * Footgun: the empty array is not inert downstream. For the ruleset option
	 * it reaches `Rule_Set::apply_synced( [] )`, which SAVES an empty ruleset —
	 * so a malformed push clears the spoke's rules rather than leaving them be.
	 * Watch the notice.
	 *
	 * @param string $raw The raw positional value off the wire.
	 * @return array<array-key,mixed>
	 */
	private static function decode_array_value( string $raw ): array {
		$decoded = \json_decode( $raw, true );
		if ( \is_array( $decoded ) ) {
			return $decoded;
		}
		Core::print_less_often( 'PerformanceCI: rejected non-JSON synced array-option value' );
		return [];
	}

	/**
	 * Ask every live worker to re-read its boot-frozen option cache.
	 *
	 * This is the hub→spoke settings-sync RECEIVE path, and it runs outside
	 * `is_admin()`, so the settings form's `updated_option` signal never fires
	 * for it — without this the spoke's workers serve the old value until they
	 * recycle. The ruleset branch signals through `Rule_Set::save()` instead.
	 *
	 * Best-effort: the next worker generation loads the new value regardless, so
	 * an unresolvable locks directory must not fail the write or the response.
	 */
	private static function request_reloads(): void {
		try {
			Restart_Planner::request_reloads( AppConfig::get_locks_directory() );
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'PerformanceCI: reload signalling failed: ', $e->getMessage() );
		}
	}

	/**
	 * Resolve a Command_Args boolean flag. A bare `--flag` and any value other
	 * than `0` / `false` read as true; `--flag=0`, `--flag=false`, and an
	 * absent key read as false. The JS `formatCommandArgs` only ever emits the
	 * bare form or `--flag=false`, so the permissive middle is for hand-typed
	 * commands.
	 *
	 * @param array<string,string|true> $options Parsed options.
	 * @param string                    $key     Flag name.
	 */
	private static function flag( array $options, string $key ): bool {
		if ( ! \array_key_exists( $key, $options ) ) {
			return false;
		}
		$value = $options[ $key ];
		if ( true === $value ) {
			return true;
		}
		return ! \in_array( \strtolower( $value ), [ '0', 'false' ], true );
	}

	/**
	 * Schema-driven dispatch: each verb is declared once in
	 * `commands[]` carrying its `handler`. The inherited Service_CI_Node ctor
	 * builds the commands table from this schema. Stats-reading verbs build
	 * per-partition Stats_Store off the shared `Core::$memd` handle; a null
	 * handle yields empty/zeroed shapes. Disk-walking verbs work regardless.
	 *
	 * Every handler throws a RuntimeException on bad input; the interpreter
	 * turns the throw into a TM_COMMAND|TM_ERROR reply, so no handler returns
	 * an error shape.
	 *
	 * @api Used by substrate.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Performance-dashboard surface: overview, URLs, requests, hooks, config, settings.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'overview',
					'capability'  => Capabilities::READ,
					'description' => 'High-level performance stats across all partitions.',
					'args'        => [
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						[ 'name' => 'breakdown', 'type' => 'string', 'required' => false ],
						[ 'name' => 'categories', 'type' => 'bool', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				// Optional args: server scopes; breakdown = comma-sep dim list.
				$opts       = Command_Args::parse( self::arg_strings( $args ) )['options'];
				$server     = (string) ( $opts['server'] ?? '' );
				$breakdown  = (string) ( $opts['breakdown'] ?? '' );
				$categories = self::flag( $opts, 'categories' );

				\assert( $self instanceof self );
				$payload                       = self::build_overview_payload( $self->index() );
				$payload['global_leaderboard'] = '' === $server
					? self::build_global_leaderboard()
					: self::build_server_leaderboard( $server );

				if ( '' !== $breakdown ) {
					$dims = \array_values(
						\array_filter(
							\array_map( 'trim', \explode( ',', $breakdown ) ),
							static fn ( $d ) => \in_array( $d, self::DIMENSIONS, true )
						)
					);
					if ( 1 === \count( $dims ) ) {
						$payload['breakdown_time_series'] = self::merge_dim_across_partitions( $dims[0], $server );
					} elseif ( ! empty( $dims ) ) {
						$payload['breakdowns'] = [];
						foreach ( $dims as $dim ) {
							$payload['breakdowns'][ $dim ] = self::merge_dim_across_partitions( $dim, $server );
						}
					}
				}

				if ( $categories ) {
					$payload['category_time_series'] = '' === $server
						? self::merge_categories_across_partitions()
						: self::merge_server_categories_across_partitions( $server );
				}

				return $payload;
					},
				],
				[
					'name'        => 'urls',
					'capability'  => Capabilities::READ,
					'description' => 'Paginated/sortable URL leaderboard.',
					'args'        => [
						[ 'name' => 'sort', 'type' => 'string', 'required' => false, 'default' => 'count' ],
						[ 'name' => 'order', 'type' => 'string', 'required' => false, 'default' => 'desc' ],
						[ 'name' => 'limit', 'type' => 'int', 'required' => false, 'default' => 50 ],
						[ 'name' => 'offset', 'type' => 'int', 'required' => false, 'default' => 0 ],
						[ 'name' => 'search', 'type' => 'string', 'required' => false ],
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						[ 'name' => 'errors_only', 'type' => 'bool', 'required' => false, 'default' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$opts    = Command_Args::parse( self::arg_strings( $args ) )['options'];
				$sort    = (string) ( $opts['sort']   ?? 'count' );
				$order   = (string) ( $opts['order']  ?? 'desc' );
				$limit   = \min( 1000, \max( 1, (int) ( $opts['limit']  ?? 50 ) ) );
				$offset  = \min( 10000, \max( 0, (int) ( $opts['offset'] ?? 0 ) ) );
				$search  = (string) ( $opts['search'] ?? '' );
				$server  = (string) ( $opts['server'] ?? '' );
				$errors  = self::flag( $opts, 'errors_only' );

				if ( ! \in_array( $sort, self::URL_SORTS, true ) ) {
					$sort = 'count';
				}
				if ( 'asc' !== $order && 'desc' !== $order ) {
					$order = 'desc';
				}

				\assert( $self instanceof self );
				$index = $self->index();

				if ( '' !== $server ) {
					$srv   = \strtolower( $server );
					$index = \array_values( \array_filter(
						$index,
						static fn ( $e ) => false !== \strpos( \strtolower( Core::as_string( $e['url'] ?? '' ) ), $srv )
					) );
				}
				if ( '' !== $search ) {
					$term  = \strtolower( $search );
					$index = \array_values( \array_filter(
						$index,
						static fn ( $e ) => false !== \strpos( \strtolower( Core::as_string( $e['url'] ?? '' ) ), $term )
					) );
				}

				if ( $errors ) {
					$index = \array_values( \array_filter( $index, [ self::class, 'has_unclassified_requests' ] ) );
				}

				$total = \count( $index );

				\usort(
					$index,
					static fn ( $a, $b ) => 'asc' === $order
						? ( $a[ $sort ] ?? 0 ) <=> ( $b[ $sort ] ?? 0 )
						: ( $b[ $sort ] ?? 0 ) <=> ( $a[ $sort ] ?? 0 )
				);

				return [
					'data'   => \array_slice( $index, $offset, $limit ),
					'total'  => $total,
					'limit'  => $limit,
					'offset' => $offset,
				];
					},
				],
				[
					'name'        => 'url_detail',
					'capability'  => Capabilities::READ,
					'description' => 'Single-URL detail incl. aggregate flame data.',
					'args'        => [
						[ 'name' => 'hash', 'type' => 'string', 'required' => true ],
						[ 'name' => 'breakdown', 'type' => 'string', 'required' => false ],
						[ 'name' => 'categories', 'type' => 'bool', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$parsed = Command_Args::parse( self::arg_strings( $args ) );
				$opts   = $parsed['options'];
				$hash   = $parsed['positional'][0] ?? '';
				if ( ! \preg_match( '/^[a-f0-9]{8,64}$/', $hash ) ) {
					throw new \RuntimeException( 'invalid hash format' );
				}

				\assert( $self instanceof self );
				$index = $self->index();
				$stats = null;
				foreach ( $index as $entry ) {
					if ( ( $entry['hash'] ?? '' ) === $hash ) {
						$stats = [
							'hash'         => $hash,
							'url'          => $entry['url'] ?? '',
							'count'        => $entry['count'] ?? 0,
							'avg_ms'       => $entry['avg_ms'] ?? 0,
							'min_ms'       => $entry['min_ms'] ?? 0,
							'max_ms'       => $entry['max_ms'] ?? 0,
							'p50_ms'       => $entry['p50_ms'] ?? 0,
							'p95_ms'       => $entry['p95_ms'] ?? 0,
							'p99_ms'       => $entry['p99_ms'] ?? 0,
							'avg_peak_mb'  => $entry['avg_peak_mb'] ?? 0,
							'max_peak_mb'  => $entry['max_peak_mb'] ?? 0,
							'last_updated' => $entry['last_updated'] ?? 0,
							// Per-URL time series (UrlDetailView + rps).
							'time_series'  => self::build_url_time_series( $hash ),
						];
						break;
					}
				}
				if ( null === $stats ) {
					throw new \RuntimeException( \esc_html( "URL not found: {$hash}" ) );
				}

				$aggregate = self::find_url_aggregate( $hash );
				$flame     = $aggregate['flame']
					?? [ 'name' => 'aggregate', 'value' => 0, 'children' => [] ];

				$payload = [
					'stats'              => $stats,
					'requests'           => self::find_recent_requests_for_url( $hash ),
					'aggregate_flame'    => $flame,
					'aggregate_profiles' => $aggregate['profiles'] ?? null,
					'last_modified'      => $aggregate['last_modified'] ?? 0,
				];

				$breakdown = (string) ( $opts['breakdown'] ?? '' );
				if ( '' !== $breakdown && \in_array( $breakdown, self::DIMENSIONS, true ) ) {
					$payload['breakdown_time_series'] = self::merge_url_dim( $hash, $breakdown );
				}

				if ( self::flag( $opts, 'categories' ) ) {
					$payload['category_time_series'] = self::merge_url_categories( $hash );
				}

				return $payload;
					},
				],
				[
					'name'        => 'request_search',
					'capability'  => Capabilities::READ,
					'description' => 'Locate a request by rid across partitions.',
					'args'        => [
						[ 'name' => 'rid', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$rid = Command_Args::parse( self::arg_strings( $args ) )['positional'][0] ?? '';
				if ( '' === $rid ) {
					throw new \RuntimeException( 'rid required' );
				}

				$scanned = 0;
				foreach ( self::search_order( $rid ) as $p => $dir ) {
					$found = self::find_request_index_entry( $dir, $p, $rid, $scanned );
					if ( null !== $found ) {
						return $found;
					}
					if ( $scanned > self::MAX_INDEX_ENTRIES ) {
						break;
					}
				}

				throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
					},
				],
				[
					'name'        => 'request_grep',
					'capability'  => Capabilities::READ,
					'description' => 'Pattern-search recent firehose traffic; returns a bounded summary of matching requests (rid, url, method, ts, match_count).',
					'args'        => [
						[ 'name' => 'pattern', 'type' => 'string', 'required' => true ],
						[ 'name' => 'limit', 'type' => 'int', 'required' => false, 'default' => self::GREP_RESULT_LIMIT_DEFAULT ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$parsed  = Command_Args::parse( self::arg_strings( $args ) );
				$pattern = Core::as_string( $parsed['positional'][0] ?? '' );
				if ( '' === \trim( $pattern ) ) {
					throw new \RuntimeException( 'pattern required' );
				}
				$limit = \min(
					self::GREP_RESULT_LIMIT_MAX,
					\max( 1, self::require_option_int( $parsed['options'], 'limit', self::GREP_RESULT_LIMIT_DEFAULT ) )
				);

				return self::run_request_grep( $pattern, $limit );
					},
				],
				[
					'name'        => 'request_detail',
					'capability'  => Capabilities::READ,
					'description' => 'Full request + flame data for a known {rid, partition}.',
					'args'        => [
						[ 'name' => 'rid', 'type' => 'string', 'required' => true ],
						[ 'name' => 'partition', 'type' => 'int', 'required' => false, 'default' => 0 ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$parsed = Command_Args::parse( self::arg_strings( $args ) );
				$rid    = $parsed['positional'][0] ?? '';
				if ( '' === $rid ) {
					throw new \RuntimeException( 'rid required' );
				}
				$partition = self::require_option_int( $parsed['options'], 'partition', 0 );

				$dirs = Bootstrap::node_dirs( self::NODE_REQUESTS );
				// No declared set: unfindable rid, not a bad partition.
				if ( [] === $dirs ) {
					throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
				}
				if ( ! isset( $dirs[ $partition ] ) ) {
					throw new \RuntimeException( 'invalid partition' );
				}

				$result = self::find_request_in_partition( $dirs[ $partition ], $partition, $rid );
				if ( null === $result ) {
					throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
				}
				// Findings ride the record; no model is involved in them.
				$rule               = self::rule_for_record( $result );
				$result['findings'] = Findings::for_request( $result, $rule );
				$result['caveat']   = Findings::caveat();
				return $result;
					},
				],
				[
					'name'        => 'ask',
					'capability'  => Capabilities::READ,
					'description' => 'Assemble the brief for one picker descriptor: `ask <descriptor> [<context-descriptor>…]`, outermost context last.',
					'args'        => [
						[ 'name' => 'descriptor', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				return self::assemble_ask( Command_Args::parse( self::arg_strings( $args ) )['positional'] );
					},
				],
				[
					'name'        => 'hooks_registered',
					'capability'  => Capabilities::READ,
					'description' => 'Registered hooks grouped by category.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				// Recompute total_hooks so the response contract stays stable.
				$by_category = Hook_Categorizer::get_registered_hooks_by_category();
				$total       = 0;
				foreach ( $by_category as $list ) {
					$total += \is_array( $list ) ? \count( $list ) : 0;
				}
				return [
					'total_hooks'           => $total,
					'categories'            => Hook_Categorizer::get_categories(),
					'category_descriptions' => Hook_Categorizer::get_descriptions(),
					'hooks_by_category'     => $by_category,
				];
					},
				],
				[
					'name'        => 'set',
					'capability'  => Capabilities::TUNE,
					'description' => 'Normalized positional single-option perf setting write with sync guard.',
					'args'        => [
						[ 'name' => 'option', 'type' => 'string', 'required' => true ],
						[ 'name' => 'value', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				// One option per command; Settings_Sync_Node fans it out.
				[ $option, $value_arg ] = \array_pad( Command_Args::parse( self::arg_strings( $args ) )['positional'], 2, null );

				$option = Core::str( $option );
				if ( '' === $option ) {
					throw new \RuntimeException( 'option required' );
				}
				if ( ! isset( self::SETTINGS_OPTIONS[ $option ] ) ) {
					throw new \RuntimeException( \esc_html( "unknown option: {$option}" ) );
				}

				// Wire value is string; array options carry JSON, decoded here.
				$type      = self::SETTINGS_OPTIONS[ $option ];
				$raw_value = Core::str( $value_arg );
				$value     = 'array' === $type ? self::decode_array_value( $raw_value ) : $raw_value;

				$sanitized = self::sanitize_settings_value( $value, $type );
				if ( null === $sanitized ) {
					throw new \RuntimeException( 'invalid value for option' );
				}

				// apply_synced re-tiers and holds its own no-op gate.
				if ( Rule_Set::OPTION_RULES === $option && \is_array( $sanitized ) ) {
					$changed = Rule_Set::apply_synced( $sanitized );
					AppConfig::reset();
					return [
						'option'  => $option,
						'updated' => $changed,
					];
				}

				// @longform A set to a value already in place is
				// a no-op, not a save. The hub re-pushes every
				// synced option on its sweep whether or not it
				// moved, and a reload is not free: it fires
				// Config::RESET_ACTION on every worker, which
				// re-parses every .tsl for the same answer.
				if ( \get_option( $option, null ) === $sanitized ) {
					return [
						'option'  => $option,
						'updated' => false,
					];
				}

				// Autoload per Config::autoload_for; emits settings event.
				$ok = \update_option( $option, $sanitized, AppConfig::autoload_for( $option ) );
				AppConfig::reset();
				self::request_reloads();

				return [
					'option'  => $option,
					'updated' => $ok,
				];
					},
				],
			],
		] );
	}
}
