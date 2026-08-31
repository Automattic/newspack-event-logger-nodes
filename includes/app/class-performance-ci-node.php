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
 *  - Disk scans are bounded by TIME where the index carries one — the per-URL
 *    walk stops at `scan_floor()`, in a segment that closed before it — and
 *    by MAX_INDEX_ENTRIES everywhere else, so a missing-rid lookup can't
 *    escalate into a partition-wide walk.
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
	 * Hard cap on .idx entries scanned per disk-walking verb — the BACKSTOP for
	 * a walk that has no better bound, not the bound the common case reaches.
	 *
	 * The per-URL walk stops at `scan_floor()`, so its real cost is a retention
	 * window of index, whatever the index holds behind that. What is left under
	 * this cap is a walk with nothing to stop it: a rid lookup, which searches
	 * for one line and cannot know how far back it sits, and the flame index,
	 * whose lines carry no time to compare (`Flame_Builder_Node::index_completion_columns()`).
	 *
	 * The floor is a full retention window of requests rather than a round
	 * number, because a budget spent on one URL's high-traffic neighbours never
	 * reaches that URL at all. At 97 bytes an entry, a million lines is ~97MB
	 * of index read one segment at a time, so peak memory is ONE segment's
	 * index — a few tens of MB — and a full spend costs ~0.2s of line work
	 * rather than ~2s: a miss is one `substr` + `trim`, not a parse. Still an
	 * answer rather than a wedged verb. A walk that spends the budget says so;
	 * see `scan_index_entries()`.
	 *
	 * Revisit when a TIME-BOUNDED walk spends it — with a floor in place that
	 * means one retention window holds a million entries, so the site outgrew
	 * the number rather than misusing it. A rid lookup or a flame walk spending
	 * it is the cap doing its job, and a bigger number would only buy a slower
	 * miss.
	 */
	public const MAX_INDEX_ENTRIES = 1000000;

	/**
	 * What an `on_hit` callback tells the scan to do next.
	 *
	 * The distinction that matters is the middle one: a partition's log is
	 * independent of its siblings', so a reader that has seen enough of THIS
	 * one has learned nothing about the next. Ending the fan-out there drops
	 * every later partition's entries silently — which is the whole reason a
	 * plain `stop` is not enough.
	 */
	private const SCAN_STOP_PARTITION = 'partition';

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

	/** `url_detail` per-URL request-list cap, applied to the index walk. */
	private const RECENT_REQUEST_LIMIT = 500;

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

	/**
	 * Complete 5-minute buckets one "Req/s (last hour)" figure averages over.
	 *
	 * Twelve, not thirteen: `recent_buckets()` drops the one still filling.
	 */
	private const RECENT_BUCKETS = 12;

	/** Slowest rows the `urls` reply carries for the Ask brief's examples. */
	private const SLOWEST_ROWS = 10;

	/**
	 * Row field holding each server's share of `recent_buckets()`.
	 *
	 * Beside `Stats_Store::URL_SRV_FIELD`, not inside it: recency is derived
	 * from the bucket KEY at read time and has no place in a stored field table.
	 */
	private const SRV_RECENT_FIELD = 'srv_recent';

	/** Deepest nesting `set` accepts in an array option; deeper is rejected. */
	private const SETTINGS_ARRAY_DEPTH = 5;

	/**
	 * Maximum element count at any single array level for `set`; a wider level
	 * rejects the whole option rather than truncating it.
	 */
	private const SETTINGS_ARRAY_MAX   = 10000;

	/**
	 * `set` whitelist: WP option name → sanitization type. An option absent
	 * here is refused outright, so this list and `hub-control.tsl`'s
	 * `add_setting` lines must stay in step — a hub push naming anything else
	 * comes back as "unknown option".
	 *
	 * @var array<string,string>
	 */
	private const SETTINGS_OPTIONS = [
		'newspack_event_logger_nodes_rules'            => 'array',
		'newspack_event_logger_nodes_log_memory'       => 'bool',
		'newspack_event_logger_nodes_flush_every_line' => 'bool',
	];

	/** @var array<int,string> Memoized `read_window()`, valid while its bucket is current. */
	private static array $read_window = [];

	/** @var string What `$read_window` was built for: its bucket AND its retention. */
	private static string $read_window_at = '';

	/**
	 * Valid sort fields for the `urls` verb; anything outside falls back
	 * to `count`.
	 */
	private const URL_SORTS = [ 'count', 'url', 'avg_ms', 'min_ms', 'max_ms', 'avg_peak_mb', 'last_updated' ];

	/**
	 * Buckets read per `lookup_multi` while folding the index.
	 *
	 * Decision 6 wants ONE round trip per read, not one per key; it does not
	 * want the whole retention window resident. Twelve is an hour of fine
	 * buckets, so a chunk is a natural unit and the batch stays wide.
	 */
	public const INDEX_READ_CHUNK = 12;

	/**
	 * URL-index read seam. Lazily-defaulted to the real merge-across-partitions
	 * loader (load_index_default). Tests reassign it to COUNT index reads without
	 * short-circuiting the production fan-out — the surrounding memo + the merge
	 * logic still run as real code (mirrors Insights_CI_Demo_Node::$read_items).
	 *
	 * It takes the SHARD, so a point read goes through it too. Given a seam
	 * that could not express one, `raw_row()` had to branch on the seam's
	 * presence — and the narrowing this exists to measure never ran under it.
	 *
	 * Resolved once per request through index(); reassign in a test bootstrap,
	 * restore in a finally.
	 *
	 * Signature: `function ( ?string $shard ): array<int,array<string,mixed>>`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $load_index = null;

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
		$merged       = [];
		$buckets      = self::read_window();
		foreach ( self::stats_stores() as $store ) {
			self::merge_buckets_into( $merged, $store->get_dimensional_buckets( $dimension, $buckets, $store_server ), Stats_Store::DIM_SUMS );
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge category buckets across all partitions (global scope).
	 *
	 * @return array<string,mixed>
	 */
	private static function merge_categories_across_partitions( string $server = '' ): array {
		$merged  = [];
		$buckets = self::read_window();
		foreach ( self::stats_stores() as $store ) {
			self::merge_buckets_into( $merged, $store->get_category_buckets( $buckets, $server ), Stats_Store::CAT_SUMS );
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
		$merged  = [];
		$buckets = self::read_window();
		foreach ( self::stats_stores() as $store ) {
			self::merge_buckets_into( $merged, $store->get_url_category_buckets( $hash, $buckets ), Stats_Store::CAT_SUMS );
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * The site-wide half of the dashboard: request totals and the chart series.
	 *
	 * These are the SITE's: `hourly` has no server dimension, so scoping one of
	 * them and not the others is how a payload comes to contradict itself. Every
	 * URL-set fact — how many, which are slowest, which are busiest — belongs to
	 * the `urls` verb, which owns the filters and answers all of it in one scope
	 * (decision 15). Nothing here touches the URL index, which is what keeps a
	 * filtered poll to ONE fan-out across the retention window.
	 *
	 * @return array<string,mixed>
	 */
	private static function build_overview_payload(): array {
		$time_series       = self::merge_hourly_across_partitions();
		$total_requests    = 0;
		$total_sum_ms      = 0.0;
		$total_sum_peak_mb = 0.0;
		foreach ( $time_series as $row ) {
			$row_arr            = Core::arr( $row );
			$total_requests    += Core::num_int( $row_arr['count'] ?? 0 );
			$total_sum_ms      += Core::num_float( $row_arr['sum_ms'] ?? 0 );
			$total_sum_peak_mb += Core::num_float( $row_arr['sum_peak_mb'] ?? 0 );
		}

		return [
			'total_requests'        => $total_requests,
			'global_avg_ms'         => $total_requests > 0 ? $total_sum_ms / $total_requests : 0.0,
			'global_avg_peak_mb'    => $total_requests > 0 ? $total_sum_peak_mb / $total_requests : 0.0,
			'aggregate_time_series' => $time_series,
		];
	}

	/**
	 * Sum-merge per-partition hourly buckets into one sorted time_series.
	 *
	 * @return array<int,mixed>
	 */
	private static function merge_hourly_across_partitions(): array {
		$merged  = [];
		$buckets = self::read_window();
		foreach ( self::stats_stores() as $store ) {
			foreach ( $store->get_hourly_buckets( $buckets ) as $hour => $row ) {
				$row_arr = Core::arr( $row );
				$merged[ $hour ] = Stats_Store::add_totals( $merged[ $hour ] ?? [ 'hour' => $hour ], $row_arr );
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
	 * Locate a single request index entry by rid and return the search shape
	 * `{rid, partition, url_hash}` — enough for the dashboard to then ask for
	 * `request_detail`; the request body is not read here. Its own partition is
	 * tried first, and the shared scan budget spans the fan-out.
	 *
	 * @param string $rid Request id to match.
	 * @return array<string,mixed>|null Search shape, or null when unmatched.
	 */
	private static function find_request_index_entry( string $rid ): ?array {
		$result  = null;
		$stopped = self::scan_index_entries(
			self::search_order( $rid ),
			'requests',
			'rid',
			$rid,
			static function ( array $entry, int $partition ) use ( &$result, $rid ): bool {
				$result = [
					'rid'       => $rid,
					'partition' => $partition,
					'url_hash'  => \trim( Core::as_string( $entry['url_hash'] ?? '' ) ),
				];
				return false;
			}
		);
		if ( null === $result && $stopped ) {
			self::fail_budget_spent( $rid );
		}
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
			if ( Log_Manager::REQUEST_START === $key ) {
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
	 * @param string       $server      Reporting server the brief answers for; '' is every server.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On an unknown descriptor or a missing context.
	 */
	private function assemble_ask( array $descriptors, string $server = '' ): array {
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
				return $this->ask_url( $target['id'], $server );
			case 'request':
				return self::ask_request( $target['id'], (int) $target['qualifier'] );
			case 'span':
				return self::ask_span( $target['id'], $context );
			case 'entry':
				return self::ask_entry( (int) $target['id'], $context );
			case 'category':
				return self::ask_category( $target['id'], $context, $server );
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
	private function ask_url( string $hash, string $server = '' ): array {
		// @longform Through `row()`, never the loader: the loader emits sums
		// and leaves the means to the projection, so a reader taking its
		// output raw quotes a confident 0 for every average. Scoped, because
		// the facts block stamps the filters onto every surface, and an
		// unscoped number under a server's name is quotable and wrong.
		$stats = $this->row( $hash, $server );
		if ( null === $stats ) {
			throw new \RuntimeException( \esc_html( "URL not found: {$hash}" ) );
		}
		$recent = self::find_recent_requests_for_url( $hash );
		return Ask_Assembler::for_url(
			$stats,
			$recent['requests'],
			self::rule_for_url( Core::as_string( $stats['url'] ?? '' ) ),
			$server,
			$recent['truncated'],
			$recent['window_start']
		);
	}

	/**
	 * Merged URL index across all partitions, shaped for dashboard display.
	 *
	 * Rows are keyed by URL hash while merging, then flattened to a list. The
	 * list is UNSCOPED and unsorted: `index()` projects it into the caller's
	 * scope and sorts what survives, so one fan-out serves every scope a request
	 * asks for. Each row therefore carries two maps the display never sees — its
	 * per-server split, and that split's share of the recent buckets — which
	 * `project_row()` consumes and strips.
	 *
	 * The bucket key IS the hash `Log_Manager::url_hash()` stamped on the
	 * record — never derive another, or the row indexes under a hash no rid
	 * lookup can produce.
	 *
	 * @param ?string $shard Shard to read, or null for the whole index.
	 * @return array<int,array<string,mixed>>
	 */
	public static function load_index_default( ?string $shard = null ): array {
		// ONE window: the flag and the plan cannot straddle a boundary.
		$plan   = Stats_Store::read_plan( \array_values( self::read_window() ) );
		$recent = \array_flip( self::recent_buckets() );
		$result = [];
		// An hour is folded when EVERY shard this read covers carries it.
		$whole = null === $shard ? \count( Stats_Store::url_shards() ) : 1;
		foreach ( self::stats_stores() as $store ) {
			// @longform An hour with no coarse key has not been folded YET — a
			// fresh deploy, or a worker down when it closed — so its twelve
			// fine buckets answer for it. A folded hour's buckets are NOT read:
			// they outlive the fold, and reading both counts the hour twice.
			// ALL its shards or none, because a fold that died between shards
			// leaves some: taking those beside the buckets answering for the
			// rest would count the folded shards twice, and the writer treats
			// the same hour as unfolded and redoes it. Chunked, and the check
			// stays exact because an hour's shards are read together.
			$missing = [];
			foreach ( \array_chunk( $plan['hours'], self::INDEX_READ_CHUNK ) as $hour_chunk ) {
				$by_hour = [];
				foreach ( $store->url_hour_sources( $hour_chunk, $shard ) as [ $hour, $data ] ) {
					$by_hour[ $hour ][] = [ $hour, $data ];
				}
				foreach ( $hour_chunk as $hour ) {
					$found = $by_hour[ $hour ] ?? [];
					if ( \count( $found ) === $whole ) {
						foreach ( $found as [ $bucket, $bucket_data ] ) {
							self::fold_bucket( $result, $bucket_data, isset( $recent[ $bucket ] ) );
						}
						continue;
					}
					$missing = \array_merge( $missing, Stats_Store::buckets_in_hour( $hour ) );
				}
			}

			// @longform Fine, then the fallback for unfolded hours. One fold
			// for both: an hour key is never a recent five-minute bucket, and
			// neither is a bucket behind the fine tail, so the recency
			// predicate is uniform. Order is no longer load-bearing — the last
			// first-wins read went with the percentiles (decision 19). Each
			// chunk is folded and DROPPED before the next is read: holding the
			// whole window's rows beside the index it builds is what exhausted
			// 512MB once the duplicate copies were gone.
			foreach ( [ $plan['fine'], $missing ] as $tier ) {
				foreach ( \array_chunk( $tier, self::INDEX_READ_CHUNK ) as $chunk ) {
					foreach ( $store->url_row_sources( $chunk, $shard ) as [ $bucket, $bucket_data ] ) {
						self::fold_bucket( $result, $bucket_data, isset( $recent[ $bucket ] ) );
					}
				}
			}
		}

		// @longform The display shape, keeping `sum_ms` and `sum_peak_mb`: the
		// means belong to the projection, over whichever scope it is asked
		// for, and un-averaging a figure divided here would put this
		// denominator in a second place.
		// @longform By reference, and `array_values` after: copying each row
		// into a second list materialised the WHOLE index twice, which is what
		// exhausted 512MB on a production hub. The rows are refcounted here, so
		// only the outer list is new.
		foreach ( $result as &$entry ) {
			// Null when nothing timed folded a min.
			$entry['min_ms'] = Core::as_float( $entry['min_ms'] );
		}
		unset( $entry );
		return \array_values( $result );
	}

	/**
	 * Fold one stored bucket — fine or coarse, they hold the same shape — into
	 * the merged rows so far.
	 *
	 * Taken BY REFERENCE: the caller holds the merged index across
	 * the call, so a by-value parameter copies the whole thing on the callee's
	 * first write — once per bucket source, of which there are thousands.
	 *
	 * @param array<string,array<string,mixed>> $result    Merged rows by hash, mutated.
	 * @param array<array-key,mixed>            $data      One stored bucket.
	 * @param bool                              $is_recent Whether it is inside
	 *                                                     the "last hour" window the rate divides by.
	 */
	private static function fold_bucket( array &$result, array $data, bool $is_recent ): void {
		foreach ( $data as $key => $stats ) {
			// An all-digit hash arrives as an int array key; cast back.
			$hash            = (string) $key;
			$result[ $hash ] = self::fold_index_row(
				$result[ $hash ] ?? self::empty_index_row( $hash ),
				Core::arr( $stats ),
				$is_recent
			);
		}
	}

	/**
	 * The zero row a hash folds its buckets into.
	 *
	 * @param string $hash 12-char URL hash, or an overflow key.
	 * @return array<string,mixed>
	 */
	private static function empty_index_row( string $hash ): array {
		return [
			'hash'         => $hash,
			'url'          => '',
			// Many URLs; `url_detail` cannot answer for it.
			'aggregate'    => Stats_Store::is_other_key( $hash ),
			'count'        => 0,
			'timed_count'  => 0,
			'count_2xx'    => 0,
			'count_3xx'    => 0,
			'count_4xx'    => 0,
			'count_5xx'    => 0,
			'sum_ms'       => 0.0,
			// null until a TIMED bucket has a min to fold in.
			'min_ms'       => null,
			'max_ms'       => 0.0,
			'sum_peak_mb'  => 0.0,
			'max_peak_mb'  => 0.0,
			'worker'       => false,
			'recent_count' => 0,
			'last_updated' => 0,
			Stats_Store::URL_SRV_FIELD => [],
			self::SRV_RECENT_FIELD     => [],
		];
	}

	/**
	 * Fold ONE stored bucket row into the merged row for its hash.
	 *
	 * The whole index and a single URL's point read share this: written twice,
	 * the table and the detail modal would disagree about the same URL.
	 *
	 * @param array<string,mixed>    $entry     The merged row so far.
	 * @param array<array-key,mixed> $stat_arr  One bucket's stored row.
	 * @param bool                   $is_recent Whether that bucket is inside the
	 *                                          "last hour" window the rate divides by.
	 * @return array<string,mixed>
	 */
	private static function fold_index_row( array $entry, array $stat_arr, bool $is_recent ): array {
		// @longform The storage/display boundary for the ROW: stored rows are
		// POSITIONAL (`Stats_Store::ROW_*`) and this one crosses the wire as
		// JSON, so it keeps its names. The SPLIT does not cross — every
		// projection strips it — so it stays positional past here and is named
		// once per scoped read by `Stats_Store::swap_url_server_sums()`.
		// @longform Arithmetic reads take the VALIDATED family, per `Core`'s own
		// rule: a stored row carries a bool at ROW_WORKER, so a shifted index
		// puts one where a count is read, and `as_int( true )` folds it as 1
		// while `num_int` takes the default. Under NAMES that needed a writer
		// to spell `'count' => true`; under indexes any drift does it.
		$row_count             = Core::num_int( $stat_arr[ Stats_Store::ROW_COUNT ] ?? 0 );
		$entry['count']        = Core::num_int( $entry['count'] ) + $row_count;
		// Only timed requests contribute ms; only they divide it.
		$entry['timed_count']  = Core::num_int( $entry['timed_count'] ) + Core::num_int( $stat_arr[ Stats_Store::ROW_TIMED_COUNT ] ?? 0 );
		$entry['recent_count'] = Core::num_int( $entry['recent_count'] ) + ( $is_recent ? $row_count : 0 );
		foreach ( Stats_Store::ROW_STATUS_COUNTS as $index ) {
			$name           = Stats_Store::ROW_FIELD_NAMES[ $index ];
			$entry[ $name ] = Core::num_int( $entry[ $name ] ) + Core::num_int( $stat_arr[ $index ] ?? 0 );
		}
		$entry['sum_ms']       = Core::num_float( $entry['sum_ms'] ) + Core::num_float( $stat_arr[ Stats_Store::ROW_SUM_MS ] ?? 0 );
		$entry['sum_peak_mb']  = Core::num_float( $entry['sum_peak_mb'] ) + Core::num_float( $stat_arr[ Stats_Store::ROW_SUM_PEAK_MB ] ?? 0 );
		// Fold min_ms only from timed buckets; skip sentinels.
		if ( isset( $stat_arr[ Stats_Store::ROW_MIN_MS ] ) && Core::num_int( $stat_arr[ Stats_Store::ROW_TIMED_COUNT ] ?? 0 ) > 0 ) {
			$stat_min        = Core::num_float( $stat_arr[ Stats_Store::ROW_MIN_MS ] );
			$entry['min_ms'] = null === $entry['min_ms']
				? $stat_min
				: \min( Core::num_float( $entry['min_ms'] ), $stat_min );
		}
		$entry['max_ms']      = \max( Core::num_float( $entry['max_ms'] ),      Core::num_float( $stat_arr[ Stats_Store::ROW_MAX_MS ]      ?? 0 ) );
		$entry['max_peak_mb'] = \max( Core::num_float( $entry['max_peak_mb'] ), Core::num_float( $stat_arr[ Stats_Store::ROW_MAX_PEAK_MB ] ?? 0 ) );
		$entry['worker']       = ! empty( $entry['worker'] ) || ! empty( $stat_arr[ Stats_Store::ROW_WORKER ] );
		$entry['last_updated'] = \max(
			Core::num_int( $entry['last_updated'] ),
			Core::num_int( $stat_arr[ Stats_Store::ROW_LAST_SEEN ] ?? 0 )
		);

		// Expanded FIRST: `sum_fields()` skips a null and would drop the host.
		$row_srv = Stats_Store::expand_sole_server(
			$stat_arr,
			Core::arr( $stat_arr[ Stats_Store::ROW_SRV ] ?? null )
		);
		if ( [] !== $row_srv ) {
			$entry[ Stats_Store::URL_SRV_FIELD ] = Stats_Store::sum_fields(
				Core::arr( $entry[ Stats_Store::URL_SRV_FIELD ] ),
				$row_srv,
				Stats_Store::URL_SRV_SUMS
			);
			if ( $is_recent ) {
				$entry[ self::SRV_RECENT_FIELD ] = Stats_Store::sum_fields(
					Core::arr( $entry[ self::SRV_RECENT_FIELD ] ),
					$row_srv,
					[ Stats_Store::ROW_COUNT => true ]
				);
			}
		}
		return $entry;
	}

	/**
	 * The filtered URL set: its totals, its slowest, and one page of it.
	 *
	 * Folded ONE SHARD AT A TIME. A url_hash's shard is its first hex digit, so
	 * shards are disjoint and a shard's fold is complete for every URL it
	 * holds — there is no cross-shard merge to miss. The whole merged index is
	 * otherwise the count of distinct URLs across the retention window, which
	 * nothing bounds: the stored buckets are capped at `MAX_URLS_PER_SHARD`,
	 * the MERGE of them is not, and a production hub exhausted 512MB inside the
	 * fold itself once three releases had removed three real duplicate copies.
	 *
	 * The union of the per-shard top-N is exactly the global top-N, because
	 * every row belongs to exactly one shard — so keeping each shard's best
	 * `$offset + $limit` by the sort key, and its best `SLOWEST_ROWS` by
	 * `avg_ms`, loses nothing. `rows` and `totals` accumulate across shards and
	 * stay site-wide (decision 15).
	 *
	 * @param string $server Reporting server to scope to; '' reads every server.
	 * @param string $search Case-insensitive URL substring; '' matches all.
	 * @param bool   $errors Keep only rows with unclassified requests.
	 * @param bool   $workers Keep worker traffic (the default excludes it).
	 * @param string $sort   A URL_SORTS field.
	 * @param string $order  'asc' or 'desc'.
	 * @param int    $offset Page offset.
	 * @param int    $limit  Page size.
	 * @return array{data:array<int,array<array-key,mixed>>,rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,has_split:bool}
	 */
	private function url_page( string $server, string $search, bool $errors, bool $workers, string $sort, string $order, int $offset, int $limit ): array {
		$term      = '' === $search ? '' : \strtolower( $search );
		$page_keep = \max( 0, $offset ) + \max( 0, $limit );
		$ranked    = [];
		$slowest   = [];
		$rows      = 0;
		$urls      = 0;
		$requests  = 0;
		$timed     = 0;
		$recent    = 0;
		$sum_ms    = 0.0;
		$sum_peak  = 0.0;
		$has_split = false;

		$by_sort = static fn ( array $a, array $b ): int => 'asc' === $order
			? ( $a[ $sort ] ?? 0 ) <=> ( $b[ $sort ] ?? 0 )
			: ( $b[ $sort ] ?? 0 ) <=> ( $a[ $sort ] ?? 0 );
		$by_mean = static fn ( array $a, array $b ): int =>
			( $b['avg_ms'] ?? 0 ) <=> ( $a['avg_ms'] ?? 0 );

		// @longform A term matches on the name and a url-sort orders by it, so
		// those two need every candidate named; every other read names a page.
		$needs_names = '' !== $term || 'url' === $sort;

		// Worker traffic is its own shard family
		$shards = $workers
			? \array_merge( Stats_Store::url_shards(), Stats_Store::url_shards( true ) )
			: Stats_Store::url_shards();

		$overflow = [];
		foreach ( $shards as $shard ) {
			$kept  = [];
			$index = self::read_index( $shard );
			foreach ( $needs_names ? self::resolve_urls( $index ) : $index as $raw ) {
				$raw_row = Core::arr( $raw );
				// Derived here: there is no second walk to spend on it.
				$has_split = $has_split
					|| [] !== Core::arr( $raw_row[ Stats_Store::URL_SRV_FIELD ] ?? null );
				// @longform Every shard's overflow row shares ONE key, so the
				// whole-index fold collapsed all sixteen for free and a
				// per-shard fold must do it deliberately — decision 14 says a
				// merge on the url_hash collapses sixteen into one. Held raw
				// and projected once below, so the means divide a whole row.
				$hash = Core::as_string( $raw_row['hash'] ?? '' );
				if ( Stats_Store::is_other_key( $hash ) ) {
					$overflow[ $hash ] = isset( $overflow[ $hash ] )
						? self::merge_overflow_rows( $overflow[ $hash ], $raw_row )
						: $raw_row;
					continue;
				}
				$row = self::project_row( $raw_row, $server );
				if ( null === $row ) {
					continue;
				}
				$aggregate = ! empty( $row['aggregate'] );
				// A folded row stands for many URLs; no term speaks for it.
				if ( '' !== $term
					&& ( $aggregate
						|| false === \strpos( \strtolower( Core::as_string( $row['url'] ?? '' ) ), $term ) ) ) {
					continue;
				}
				if ( $errors && ! self::has_unclassified_requests( $row ) ) {
					continue;
				}
				++$rows;
				// The overflow row stands for many URLs; not one of them.
				$urls     += $aggregate ? 0 : 1;
				$requests += Core::num_int( $row['count'] ?? null );
				$recent   += Core::num_int( $row['recent_count'] ?? null );
				// Denominator from the SAME row as its numerator.
				$timed    += Core::num_int( $row['timed_count'] ?? null );
				$sum_ms   += Core::num_float( $row['sum_ms'] ?? null );
				$sum_peak += Core::num_float( $row['sum_peak_mb'] ?? null );
				$kept[]    = $row;
			}

			// This shard's contenders only; the rest of it is dropped here.
			\usort( $kept, $by_mean );
			$slowest = \array_merge( $slowest, \array_slice( $kept, 0, self::SLOWEST_ROWS ) );
			\usort( $kept, $by_sort );
			$ranked  = \array_merge( $ranked, \array_slice( $kept, 0, $page_keep ) );
		}

		foreach ( $overflow as $raw_row ) {
			$row = self::project_row( $raw_row, $server );
			if ( null === $row ) {
				continue;
			}
			// Not one of `totals.urls`, but its requests are real.
			if ( '' !== $term ) {
				continue;
			}
			if ( $errors && ! self::has_unclassified_requests( $row ) ) {
				continue;
			}
			++$rows;
			$requests += Core::num_int( $row['count'] ?? null );
			$recent   += Core::num_int( $row['recent_count'] ?? null );
			$timed    += Core::num_int( $row['timed_count'] ?? null );
			$sum_ms   += Core::num_float( $row['sum_ms'] ?? null );
			$sum_peak += Core::num_float( $row['sum_peak_mb'] ?? null );
			$ranked[]  = $row;
			$slowest[] = $row;
		}

		\usort( $slowest, $by_mean );
		\usort( $ranked, $by_sort );

		return [
			'data'      => self::resolve_urls( \array_slice( $ranked, $offset, $limit ) ),
			// The pager's question; `totals.urls` is another.
			'rows'      => $rows,
			'totals'    => [
				'urls'                => $urls,
				'requests'            => $requests,
				'avg_ms'              => self::mean_ms( $sum_ms, $timed ),
				'avg_peak_mb'         => $requests > 0 ? $sum_peak / $requests : 0.0,
				'requests_per_second' => self::recent_rate( $recent ),
			],
			'slowest'   => self::resolve_urls( \array_slice( $slowest, 0, self::SLOWEST_ROWS ) ),
			// Pre-split data cannot answer a scoped question; see the handler.
			'has_split' => $has_split,
		];
	}

	/**
	 * Merge one shard's overflow row into another's.
	 *
	 * The same accumulation `fold_index_row()` performs, over two MERGED rows
	 * rather than a merged row and a stored one: sums add, extremes take the
	 * extreme, `last_updated` takes the later, and the split sums per server.
	 * Only the overflow rows need this — every other hash lives in one shard.
	 *
	 * @param array<array-key,mixed> $into A merged overflow row.
	 * @param array<array-key,mixed> $from Another shard's, same key.
	 * @return array<array-key,mixed>
	 */
	private static function merge_overflow_rows( array $into, array $from ): array {
		foreach ( [ 'count', 'timed_count', 'recent_count' ] as $field ) {
			$into[ $field ] = Core::num_int( $into[ $field ] ?? null ) + Core::num_int( $from[ $field ] ?? null );
		}
		foreach ( Stats_Store::ROW_STATUS_COUNTS as $name ) {
			$into[ $name ] = Core::num_int( $into[ $name ] ?? null ) + Core::num_int( $from[ $name ] ?? null );
		}
		foreach ( [ 'sum_ms', 'sum_peak_mb' ] as $field ) {
			$into[ $field ] = Core::num_float( $into[ $field ] ?? null ) + Core::num_float( $from[ $field ] ?? null );
		}
		foreach ( [ 'max_ms', 'max_peak_mb' ] as $field ) {
			$into[ $field ] = \max( Core::num_float( $into[ $field ] ?? null ), Core::num_float( $from[ $field ] ?? null ) );
		}
		// Null means nothing timed; a real minimum always wins over it.
		$from_min = $from['min_ms'] ?? null;
		if ( null !== $from_min ) {
			$into['min_ms'] = null === ( $into['min_ms'] ?? null )
				? Core::num_float( $from_min )
				: \min( Core::num_float( $into['min_ms'] ), Core::num_float( $from_min ) );
		}
		$into['last_updated'] = \max(
			Core::num_int( $into['last_updated'] ?? null ),
			Core::num_int( $from['last_updated'] ?? null )
		);
		$into['worker'] = ! empty( $into['worker'] ) || ! empty( $from['worker'] );
		$into[ Stats_Store::URL_SRV_FIELD ] = Stats_Store::sum_fields(
			Core::arr( $into[ Stats_Store::URL_SRV_FIELD ] ?? null ),
			Core::arr( $from[ Stats_Store::URL_SRV_FIELD ] ?? null ),
			Stats_Store::URL_SRV_SUMS
		);
		return $into;
	}

	/**
	 * The complete buckets a "last hour" rate averages over, newest first.
	 *
	 * The newest bucket is still filling, so it is dropped: including a partial
	 * one drags the figure down by however much of it has not happened yet.
	 *
	 * @return array<int,string>
	 */
	private static function recent_buckets(): array {
		return \array_slice( self::read_window(), 1, self::RECENT_BUCKETS );
	}

	/**
	 * Requests per second over those buckets.
	 *
	 * The divisor is the WINDOW, not the buckets that carried traffic: dividing
	 * by the buckets it had made a URL seen in two of twelve read 6x its rate.
	 *
	 * @param int $requests Requests counted across `recent_buckets()`.
	 */
	private static function recent_rate( int $requests ): float {
		return $requests / ( self::RECENT_BUCKETS * Stats_Store::BUCKET_SECONDS );
	}

	/**
	 * Sum-merge per-URL dimensional buckets for one dim/hash.
	 *
	 * @param string $hash      12-char URL hash.
	 * @param string $dimension One of DIMENSIONS.
	 * @return array<array-key,mixed> Bucket keys derive from decoded memcache blobs.
	 */
	private static function merge_url_dim( string $hash, string $dimension ): array {
		$merged  = [];
		$buckets = self::read_window();
		foreach ( self::stats_stores() as $store ) {
			$rows = $store->get_url_dimensional_buckets( $hash, $buckets );
			// Bucket-major: pull the dimension asked for.
			$series = [];
			foreach ( $rows as $bucket_key => $dims ) {
				$values = Core::arr( $dims )[ $dimension ] ?? null;
				if ( \is_array( $values ) ) {
					$series[ $bucket_key ] = $values;
				}
			}
			self::merge_buckets_into( $merged, $series, Stats_Store::DIM_SUMS );
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge one namespace's buckets into the running totals, through the
	 * SAME arithmetic and the same field table the writer used.
	 *
	 * The reader used to hand-roll this per namespace with `Core::as_*`, the
	 * permissive family that casts any scalar — against values the writer stored
	 * through the refusing `num_*` family, and with `c` as a float where
	 * `CAT_SUMS` calls it a whole count. One producer, one reader, one table.
	 *
	 * @param array<string,mixed> $merged Mutated.
	 * @param array<string,mixed> $rows   Inbound, keyed by bucket.
	 * @param array<string,bool>  $fields Field name => is a whole count.
	 */
	private static function merge_buckets_into( array &$merged, array $rows, array $fields ): void {
		foreach ( $rows as $bucket => $values ) {
			$merged[ $bucket ] = Stats_Store::sum_fields( Core::arr( $merged[ $bucket ] ?? null ), Core::arr( $values ), $fields );
		}
	}

	/**
	 * Walk the request partitions newest-first and collect up to
	 * RECENT_REQUEST_LIMIT index entries for the given url_hash, deduplicated by
	 * rid and sorted by timestamp DESC. Each partition's walk ends at
	 * `scan_floor()`; the whole fan-out ends on that cap or on the shared
	 * MAX_INDEX_ENTRIES budget.
	 *
	 * Both endings are the caller's to pass on. A URL quiet enough to sit behind
	 * a million of its neighbours' entries is never reached, and a list that
	 * stopped short reads exactly like a URL with no traffic; a list that ran
	 * out of WINDOW reads the same way, so the floor it stopped at rides back
	 * with it.
	 *
	 * NOT server-scoped: an index entry carries no server, so filtering would
	 * mean reading every record. Free today because a stored `url` is ABSOLUTE,
	 * so one url_hash belongs to one site. If a host is ever reported by two
	 * servers, the server has to go on the index entry.
	 *
	 * @param string $url_hash 12-char URL hash to match.
	 * @param int    $since    Watermark (epoch seconds): the walk stops at the
	 *                         first entry that COMPLETED below it. 0 reads the
	 *                         whole retained window.
	 * @return array{requests:array<int,array<string,mixed>>, truncated:bool, window_start:int} The list, whether the budget cut it short, and the window it is of.
	 */
	private static function find_recent_requests_for_url( string $url_hash, int $since = 0 ): array {
		$requests  = [];
		$floor     = self::scan_floor();
		$truncated = self::scan_index_entries(
			Bootstrap::node_dirs( self::NODE_REQUESTS ),
			'requests',
			'url_hash',
			$url_hash,
			static function ( array $entry, int $partition, int $segment ) use ( &$requests, $since ): string|bool|null {
				// @longform Comparing START would end the partition at the
				// first long-running request and drop everything behind it for
				// good, the watermark having advanced past them.
				if ( $since > 0 ) {
					$completed_at = Core::num_int( $entry['timestamp'] ?? 0 )
						+ \intdiv( Core::num_int( $entry['duration_ms'] ?? 0 ), 1000 );
					if ( $completed_at < $since ) {
						return self::SCAN_STOP_PARTITION;
					}
				}
				$requests[] = [
					'rid'          => \trim( Core::as_string( $entry['rid'] ?? '' ) ),
					'timestamp'    => $entry['timestamp'] ?? 0,
					'duration_ms'  => $entry['duration_ms'] ?? 0,
					'status_code'  => $entry['status_code'] ?? 0,
					'peak_mb'      => $entry['peak_mb'] ?? 0,
					'method'       => $entry['method'] ?? '',
					'error_status' => $entry['error_status'] ?? null,
					'segment'      => $entry['segment'] ?? $segment,
					'offset'       => $entry['offset'] ?? 0,
					'length'       => $entry['length'] ?? 0,
					'partition'    => $partition,
				];
				return \count( $requests ) >= self::RECENT_REQUEST_LIMIT ? false : null;
			},
			$floor
		);

		\usort( $requests, static fn ( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		$seen   = [];
		$unique = [];
		foreach ( $requests as $r ) {
			if ( ! isset( $seen[ $r['rid'] ] ) ) {
				$seen[ $r['rid'] ] = true;
				$unique[]          = $r;
			}
		}
		return [ 'requests' => $unique, 'truncated' => $truncated, 'window_start' => $floor ];
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
	private static function ask_category( string $name, array $context, string $server = '' ): array {
		$record = self::request_in_context( $context );
		if ( null !== $record ) {
			$brief = Ask_Assembler::for_request_category( $record, $name );
			if ( null !== $brief ) {
				return $brief;
			}
		}
		// The card this is asked from renders the same scoped board.
		$board      = self::build_leaderboard( $server );
		$categories = \is_array( $board['categories'] ?? null ) ? $board['categories'] : [];
		$brief      = Ask_Assembler::for_category( $categories, $name, $server );
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no category '{$name}' in this request or the recent window" ) );
		}
		return $brief;
	}

	/**
	 * Build the category leaderboard for the recent window, global or scoped to
	 * one reporting server — the scope was the only thing the two ever differed
	 * by, and the window is read in ONE round trip per store rather than one per
	 * bucket across hundreds of them.
	 *
	 * @param string $server Server to scope to; '' builds the global board.
	 * @return array<string,mixed>
	 */
	private static function build_leaderboard( string $server = '' ): array {
		$count        = 0;
		$sum_req_time = 0.0;
		$sums         = [];
		$buckets      = self::read_window();
		foreach ( self::stats_stores() as $store ) {
			foreach ( $store->get_leaderboard_buckets( $buckets, $server ) as $row ) {
				if ( ! \is_array( $row ) ) {
					continue;
				}
				$count        += Core::num_int( $row['count'] ?? 0 );
				$sum_req_time += Core::num_float( $row['sum_req_time'] ?? 0 );
				$sums          = Stats_Store::sum_fields( $sums, Core::arr( $row['categories'] ?? null ), Stats_Store::LB_CAT_SUMS );
			}
		}
		return Stats_Store::sums_to_display( $count, $sum_req_time, $sums );
	}

	/**
	 * The bucket keys a reader walks — the configured retention window.
	 *
	 * Memoized for as long as the current bucket is current. One `overview` calls
	 * this eleven times (seven dimensions, plus hourly, leaderboard, index and
	 * categories), each otherwise rebuilding up to 288 keys with a `gmdate()`
	 * apiece — and each re-reading the clock, so two panels of one response could
	 * straddle a boundary and answer for different windows.
	 *
	 * @return array<int,string>
	 */
	private static function read_window(): array {
		$now       = \time();
		$retention = AppConfig::stats_retention_seconds();
		// Keyed on retention too, or a settings change goes unnoticed.
		$at = Stats_Store::bucket_key( $now ) . ':' . $retention;
		if ( $at !== self::$read_window_at ) {
			self::$read_window    = Stats_Store::retention_buckets( $retention, $now );
			self::$read_window_at = $at;
		}
		return self::$read_window;
	}

	/**
	 * The completion time below which a request-index walk can stop reading.
	 *
	 * The window floor `read_window()` enumerates, and nothing else: a request
	 * that completed before the window opened cannot be answered with, and one
	 * that completed inside it is in the window however long ago it started.
	 * No slack — the walk compares completions, so it needs no allowance for
	 * how long a request may have been in flight.
	 *
	 * It bounds the walk; it does not filter the answer. An entry the walk
	 * reaches is returned whatever its time, which is the side to err on: the
	 * alternative drops rows the operator can see in the chart beside the list.
	 *
	 * @return int Unix timestamp.
	 */
	private static function scan_floor(): int {
		return Stats_Store::window_start( AppConfig::stats_retention_seconds(), \time() );
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
		$dirs = Bootstrap::node_dirs( self::NODE_REQUESTS );
		// `+` keeps the caller's partition first; search_order has the rest.
		$order  = isset( $dirs[ $partition ] )
			? [ $partition => $dirs[ $partition ] ] + self::search_order( $rid )
			: self::search_order( $rid );
		$record = self::find_request( $order, $rid );
		if ( null === $record ) {
			throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
		}
		return $record;
	}

	/**
	 * The full request body for a rid, from the first of `$dirs` holding it,
	 * with any matching flame merged in as `flame_data`. A missing flame is
	 * normal — they are built asynchronously, into whichever partition their
	 * builder is wired to, so that lookup fans across all of them — and leaves
	 * the body otherwise intact.
	 *
	 * @param array<int,string> $dirs Partition index => dir, in search order.
	 * @param string            $rid  Request id to match.
	 * @return array<array-key,mixed>|null Decoded request body (keys come from the JSON envelope).
	 */
	private static function find_request( array $dirs, string $rid ): ?array {
		$found = self::first_record( $dirs, 'requests', $rid, $stopped );
		if ( null === $found ) {
			if ( $stopped ) {
				self::fail_budget_spent( $rid );
			}
			return null;
		}
		[ $entry, $record ] = $found;
		$record['url_hash'] = \trim( Core::as_string( $entry['url_hash'] ?? '' ) );
		// A flame miss is normal, budget or not: unprofiled requests have none.
		$flame              = self::first_record( Bootstrap::node_dirs( self::NODE_FLAMES ), 'flames', $rid );
		if ( null !== $flame ) {
			$record['flame_data'] = $flame[1];
		}
		return $record;
	}

	/**
	 * The ending a spent budget actually had. A rid the walk never reached is
	 * not a rid that is gone: reported as a definite negative it sends an
	 * operator after a retention bug that does not exist.
	 *
	 * @param string $rid Request id the walk was looking for.
	 * @throws \RuntimeException Always.
	 */
	private static function fail_budget_spent( string $rid ): never {
		throw new \RuntimeException( \esc_html( "request index scan budget spent before rid {$rid} was reached" ) );
	}

	/**
	 * The first STORED record whose index entry matches, paired with that entry.
	 * One seek, never a log walk: the entry carries segment, offset and length.
	 *
	 * @param array<int,string> $dirs    Partition index => dir, in search order.
	 * @param string            $log     Log basename ('requests' | 'flames').
	 * @param string            $rid     Request id the entry must carry.
	 * @param bool|null         $stopped Set true when the budget ended the walk.
	 * @param-out bool          $stopped
	 * @return array{0:array<array-key,mixed>,1:array<array-key,mixed>}|null Entry + decoded record.
	 */
	private static function first_record( array $dirs, string $log, string $rid, ?bool &$stopped = null ): ?array {
		$found   = null;
		$stopped = self::scan_index_entries(
			$dirs,
			$log,
			'rid',
			$rid,
			static function ( array $entry, int $partition, int $segment, Partition_Node $node ) use ( &$found ): ?bool {
				$message = $node->read_message_at(
					Core::as_int( $entry['segment'] ?? 0 ),
					Core::as_int( $entry['offset'] ?? 0 ),
					Core::as_int( $entry['length'] ?? 0 )
				);
				$record = \is_array( $message ) ? ( $message[ Message::VALUE ] ?? null ) : null;
				if ( \is_array( $record ) ) {
					$found = [ $entry, $record ];
				}
				return null === $found ? null : false;
			}
		);
		return $found;
	}

	/**
	 * Fan a bounded index scan across a set of partition dirs, newest entry
	 * first, handing every entry whose `$field` equals `$match` to `$on_hit`.
	 *
	 * The one boundary MAX_INDEX_ENTRIES lives at: the budget spans the whole
	 * fan-out, and the scan ends everywhere the moment it is spent or `$on_hit`
	 * returns false. Each scratch Partition is built, named, formatted and
	 * removed here, so a caller carries nothing but its predicate.
	 *
	 * The two endings are NOT the same answer, so they are told apart: a caller
	 * satisfied by `$on_hit` holds the whole truth, while a caller whose budget
	 * ran out holds however much of it the walk reached.
	 *
	 * Misses dominate any walk that spends its budget, so a miss costs ONE
	 * `substr` + `trim` against the field's fixed column — the offsets coming
	 * from the writer that laid the line out. The parse, and the check that
	 * settles the match, run only behind that filter.
	 *
	 * A `$floor` bounds the walk by TIME instead of by luck: past it, nothing
	 * left in this partition can still be in the window, so the walk moves to
	 * the next partition rather than reading the rest. That is not an ending
	 * either caller has to hear about — it is where the answers stop being.
	 *
	 * Two facts have to agree before it ends anything, because either alone
	 * truncates. The SEGMENT's index must have taken no line since the window
	 * opened, which is a clock this machine owns — a hub takes its spokes in
	 * ARRIVAL order, so one spoke reconnecting after a lag lays hours-old lines
	 * between live ones and no line's own time orders the file. And the LINE
	 * must have completed before the window too, read as start + duration:
	 * start alone is a request's beginning, which a long-running one carries
	 * from hours outside a window it finished inside. A duration too wide for
	 * its column is written clamped, so a completion can only be UNDER-stated
	 * — and the segment has the last word, which is what makes that safe.
	 *
	 * @param array<int,string> $dirs   Partition index => dir, in scan order.
	 * @param string            $log    Log basename ('requests' | 'flames').
	 * @param string            $field  Index-entry field the match compares.
	 * @param string            $match  Value that field must equal, trimmed.
	 * @param callable(array<array-key,mixed>, int, int, Partition_Node): (self::SCAN_STOP_PARTITION|bool|null) $on_hit
	 *        Return false to end the whole fan-out, `SCAN_STOP_PARTITION` to
	 *        finish this partition and carry on with the next, null to continue.
	 * @param int|null          $floor  Stop a closed segment below this completion time; null walks to the budget.
	 * @return bool True when the entry budget ended the scan.
	 */
	private static function scan_index_entries( array $dirs, string $log, string $field, string $match, callable $on_hit, ?int $floor = null ): bool {
		// Both halves of ONE format: never read an index we didn't write.
		[ $formatter, $parse, $column, $times ] = 'flames' === $log
			? [ 'flame-index', Flame_Builder_Node::parse_flame_index( ... ), Flame_Builder_Node::index_column( $field ), Flame_Builder_Node::index_completion_columns() ]
			: [ 'request-index', Request_Builder_Node::parse_request_index( ... ), Request_Builder_Node::index_column( $field ), Request_Builder_Node::index_completion_columns() ];
		// Past the columns' last byte: a short line is skipped, not read as 0.
		$span_end      = [] === $times ? 0 : \max( $times[0][0] + $times[0][1], $times[1][0] + $times[1][1] );
		$entries_count = 0;
		foreach ( $dirs as $p => $dir ) {
			$stopped = false;
			$node    = new Partition_Node();
			self::name_scratch_partition( $node, $log, $p );
			$node->arguments( [ $dir ] );
			// Unresolvable installs no index; the scan then finds nothing.
			if ( ! $node->with_index_named( $formatter ) ) {
				$node->remove_node();
				throw new \RuntimeException( \esc_html( "index formatter not registered: {$formatter}" ) );
			}
			$closed = null === $floor || [] === $times ? [] : self::segments_closed_before( $node, $floor );
			$node->scan_index(
				static function ( string $line, int $segment ) use ( &$entries_count, &$stopped, $node, $p, $field, $match, $column, $times, $span_end, $closed, $floor, $parse, $on_hit ): ?bool {
					++$entries_count;
					if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
						$stopped = true;
						return false;
					}
					// A closed segment, and a line that agrees it is past.
					if ( isset( $closed[ $segment ] ) && \strlen( $line ) >= $span_end ) {
						$started = (int) \substr( $line, $times[0][0], $times[0][1] );
						$done    = $started + \intdiv( (int) \substr( $line, $times[1][0], $times[1][1] ), 1000 );
						if ( $started > 0 && $done < $floor ) {
							return false;
						}
					}
					// One slice per MISS; the parse runs only on a hit.
					if ( [] !== $column && \trim( \substr( $line, $column[0], $column[1] ) ) !== $match ) {
						return null;
					}
					$entry = $parse( $line );
					if ( ! \is_array( $entry ) || \trim( Core::as_string( $entry[ $field ] ?? '' ) ) !== $match ) {
						return null;
					}
					$outcome = $on_hit( $entry, $p, $segment, $node );
					// Done with this log, not with the fan-out.
					if ( self::SCAN_STOP_PARTITION === $outcome ) {
						return false;
					}
					$stopped = false === $outcome;
					return $stopped ? false : null;
				},
				true
			);
			$node->remove_node();
			if ( $stopped ) {
				return $entries_count > self::MAX_INDEX_ENTRIES;
			}
		}
		return false;
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
		$partition->patron( $partition );
		$partition->name( "{$log}.{$token}.p{$index}" );
		$ci = Core::node( Node_Names::COMMAND_INTERPRETER );
		if ( null === $partition->sink() && null !== $ci ) {
			$partition->sink( $ci );
		}
	}

	/**
	 * Which of a partition's segments took their last index line before
	 * `$floor`, as a `{ id: true }` set the line callback tests with one
	 * `isset()`.
	 *
	 * A segment still being appended to can hold an in-window line anywhere,
	 * so only a CLOSED one may end a walk. Asked of the filesystem rather than
	 * of the lines: a line's time is its producer's, and a hub has many.
	 *
	 * @param Partition_Node $node  The partition being walked.
	 * @param int            $floor Unix time the window opens at.
	 * @return array<int,bool> Segment id => true.
	 */
	private static function segments_closed_before( Partition_Node $node, int $floor ): array {
		$closed = [];
		foreach ( $node->index_mtimes() as $id => $mtime ) {
			if ( $mtime < $floor ) {
				$closed[ $id ] = true;
			}
		}
		return $closed;
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
	 * One URL's display row in the given scope, or null when it is absent from
	 * the index — or present but never served by that server.
	 *
	 * Projects the single match, not the whole index: its two callers would
	 * otherwise hold a second copy of every URL to read one.
	 *
	 * @param string $hash   12-char URL hash.
	 * @param string $server Reporting server to scope to; '' reads every server.
	 * @return array<array-key,mixed>|null
	 * @throws \RuntimeException When the row exists but the index carries no
	 *                           per-server split to answer the scope with.
	 */
	private function row( string $hash, string $server = '' ): ?array {
		$raw = $this->raw_row( $hash );
		if ( null === $raw ) {
			return null;
		}
		return self::project_row( self::resolve_urls( [ $raw ] )[0], $server );
	}

	/**
	 * Fill in each row's URL from the name table.
	 *
	 * A stored row carries the 12-char hash and nothing else identifying, so
	 * the name is read for the rows a response actually SHOWS — one
	 * `lookup_multi` per partition rather than 101 bytes in every bucket of
	 * every window. Rows that already carry a name, and the synthetic overflow
	 * rows, which name no URL, cost nothing.
	 *
	 * @param array<int,array<array-key,mixed>> $rows Merged display rows.
	 * @return array<int,array<array-key,mixed>>
	 */
	private static function resolve_urls( array $rows ): array {
		$wanted = [];
		foreach ( $rows as $row ) {
			$hash = Core::as_string( $row['hash'] ?? '' );
			if ( '' !== $hash
				&& '' === Core::as_string( $row['url'] ?? '' )
				&& ! Stats_Store::is_other_key( $hash ) ) {
				$wanted[ $hash ] = true;
			}
		}
		if ( [] === $wanted ) {
			return $rows;
		}
		$names = [];
		foreach ( self::stats_stores() as $store ) {
			// A hash is named in the partition that saw it; first name wins.
			$names += $store->get_url_names( \array_keys( $wanted ) );
		}
		foreach ( $rows as $i => $row ) {
			$hash = Core::as_string( $row['hash'] ?? '' );
			if ( isset( $names[ $hash ] ) && '' === Core::as_string( $row['url'] ?? '' ) ) {
				$rows[ $i ]['url'] = $names[ $hash ];
			}
		}
		return $rows;
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
		// Not Core::$memd: an APCu-only pool reaches stats via the mirror.
		if ( null === \Newspack_Nodes\Cache_Backend::shared_first() ) {
			return [];
		}
		$max_lifespan = AppConfig::stats_retention_seconds();
		$stores       = [];
		foreach ( Bootstrap::node_partitions( self::NODE_FLAME_BUILDER ) as $p ) {
			$store = new Stats_Store( $p, $max_lifespan );
			// No worker here to arm it: a miss must still reach the mirror.
			Flame_Builder_Node::arm_stats_reader( $store );
			$stores[] = $store;
		}
		return $stores;
	}

	/**
	 * One URL's unscoped merged row, as cheaply as this request allows.
	 *
	 * A POINT READ of the hash's own shard — unless the whole index is already
	 * in hand for this request, in which case walking it is free and reading
	 * again would be a second fan-out for a row we hold.
	 *
	 * @param string $hash 12-char URL hash.
	 * @return array<array-key,mixed>|null
	 */
	private function raw_row( string $hash ): ?array {
		return self::load_row_default( $hash );
	}

	/**
	 * One URL's merged row, read from the ONE shard its hash names.
	 *
	 * `Stats_Store::url_shard()` is the first hex digit of the hash, so a single
	 * URL lives in a single shard and the other fifteen answer nothing. Reaching
	 * this row through the whole index made the detail modal pay the URL TABLE's
	 * fan-out — on the staging hub, 18,432 keys and 54 MB to answer about one row.
	 *
	 * The reader population answers first and the worker one only when it has
	 * nothing: a URL served both ways shows the row the default table showed,
	 * and a job-only URL still opens.
	 *
	 * @param string $hash 12-char URL hash.
	 * @return array<array-key,mixed>|null The merged row, or null when absent.
	 */
	public static function load_row_default( string $hash ): ?array {
		foreach ( [ false, true ] as $worker ) {
			$found = self::row_in_shard( $hash, Stats_Store::url_shard( $hash, $worker ) );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	/**
	 * One URL's merged row from one shard, or null when that shard has none.
	 *
	 * @param string $hash  12-char URL hash.
	 * @param string $shard Shard token from `Stats_Store::url_shard()`.
	 * @return array<array-key,mixed>|null
	 */
	private static function row_in_shard( string $hash, string $shard ): ?array {
		foreach ( self::read_index( $shard ) as $row ) {
			if ( Core::as_string( $row['hash'] ?? '' ) === $hash ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * One merged URL row projected into the caller's scope, or null when that
	 * server never served it.
	 *
	 * A PROJECTION over the merged row, not a filter before the merge: every
	 * field it swaps adds, so selecting then summing and summing then selecting
	 * agree, and ONE fan-out serves every scope. The means come after the swap,
	 * so a scoped row never carries the site's average beside a server's count.
	 *
	 * @param array<array-key,mixed> $row    A merged row from load_index_default().
	 * @param string                 $server Reporting server; '' scopes to none.
	 * @return array<array-key,mixed>|null
	 */
	private static function project_row( array $row, string $server ): ?array {
		$recent = Core::arr( $row[ self::SRV_RECENT_FIELD ] ?? null );
		unset( $row[ self::SRV_RECENT_FIELD ] );

		$scoped = Stats_Store::swap_url_server_sums( $row, $server );
		if ( null === $scoped ) {
			return null;
		}
		if ( '' !== $server ) {
			$scoped['recent_count'] = Core::num_int(
				Core::arr( $recent[ $server ] ?? null )[ Stats_Store::ROW_COUNT ] ?? null
			);
		}

		// Two populations: a timeout has peak memory but no duration.
		$all                   = \max( 1, Core::num_int( $scoped['count'] ?? null ) );
		$scoped['avg_ms']      = self::mean_ms(
			Core::num_float( $scoped['sum_ms'] ?? null ),
			Core::num_int( $scoped['timed_count'] ?? null )
		);
		$scoped['avg_peak_mb'] = Core::num_float( $scoped['sum_peak_mb'] ?? null ) / $all;
		return $scoped;
	}

	/**
	 * Mean request duration over the requests that HAVE one.
	 *
	 * Only timed requests contribute milliseconds, so dividing by every request
	 * would understate the mean by the untimed fraction.
	 *
	 * @param float $sum_ms Summed durations.
	 * @param int   $timed  Requests that contributed one.
	 */
	private static function mean_ms( float $sum_ms, int $timed ): float {
		return $timed > 0 ? $sum_ms / $timed : 0.0;
	}

	/**
	 * The read seam, resolved. One entry point for both shapes, so a test
	 * counting reads counts a point read as well as a whole-index one.
	 *
	 * @param ?string $shard Shard to read, or null for the whole index.
	 * @return array<int,array<array-key,mixed>>
	 */
	private static function read_index( ?string $shard ): array {
		$read = self::$load_index ?? static fn ( ?string $s ): array => self::load_index_default( $s );
		$rows = [];
		foreach ( Core::arr( $read( $shard ) ) as $row ) {
			if ( \is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
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
	 * @param array<array-key,mixed> $row A URL index row.
	 */
	private static function has_unclassified_requests( array $row ): bool {
		// A folded row mixes hundreds of URLs; no row test speaks for it.
		if ( ! empty( $row['aggregate'] ) ) {
			return false;
		}
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
	 * Refuse a dimension this CI does not know.
	 *
	 * Dropped instead of refused, the reply simply says nothing about it, and
	 * a reader that reads an absent dimension as "still in flight" waits on
	 * one nobody will ever send.
	 *
	 * @param string $dimension The dimension name as the caller spelled it.
	 * @throws \RuntimeException When it is not one of DIMENSIONS.
	 */
	private static function assert_dimension( string $dimension ): void {
		if ( ! \in_array( $dimension, self::DIMENSIONS, true ) ) {
			throw new \RuntimeException( \esc_html( "invalid breakdown dimension: {$dimension}" ) );
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
				$payload                       = self::build_overview_payload();
				$payload['global_leaderboard'] = self::build_leaderboard( $server );

				// One key per dimension ASKED for, whatever the count.
				if ( '' !== $breakdown ) {
					$payload['breakdowns'] = [];
					foreach ( \array_map( 'trim', \explode( ',', $breakdown ) ) as $dim ) {
						self::assert_dimension( $dim );
						$payload['breakdowns'][ $dim ] = self::merge_dim_across_partitions( $dim, $server );
					}
				}

				if ( $categories ) {
					$payload['category_time_series'] = self::merge_categories_across_partitions( $server );
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
						[ 'name' => 'include_workers', 'type' => 'bool', 'required' => false, 'default' => false ],
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
				// Opts IN: the default EXCLUDES. See decision 15.
				$workers = self::flag( $opts, 'include_workers' );

				if ( ! \in_array( $sort, self::URL_SORTS, true ) ) {
					$sort = 'count';
				}
				if ( 'asc' !== $order && 'desc' !== $order ) {
					$order = 'desc';
				}

				\assert( $self instanceof self );
				$page = $self->url_page( $server, $search, $errors, $workers, $sort, $order, $offset, $limit );

				return [
					'data'    => $page['data'],
					'rows'    => $page['rows'],
					// Pre-split data cannot answer this; 0 would read as idle.
					'totals'  => ( '' === $server || $page['has_split'] )
						? $page['totals']
						: null,
					'slowest' => $page['slowest'],
					// What the totals are OF, or they read as the site's.
					'filters' => [
						'server'      => $server,
						'search'      => $search,
						'errors_only'     => $errors,
						'include_workers' => $workers,
					],
					'limit'   => $limit,
					'offset'  => $offset,
				];
					},
				],
				[
					'name'        => 'url_detail',
					'capability'  => Capabilities::READ,
					'description' => 'Single-URL detail incl. aggregate flame data. Its request list covers the window opening at requests_window_start, not the whole record; `since` tails it.',
					'args'        => [
						[ 'name' => 'hash', 'type' => 'string', 'required' => true ],
						[ 'name' => 'breakdown', 'type' => 'string', 'required' => false ],
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						[ 'name' => 'categories', 'type' => 'bool', 'required' => false ],
						[ 'name' => 'since', 'type' => 'int', 'required' => false, 'description' => 'Watermark (epoch): tails the request list. Compared against COMPLETION, exclusively — a request sharing this second is still returned.' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$parsed = Command_Args::parse( self::arg_strings( $args ) );
				$opts   = $parsed['options'];
				$hash   = $parsed['positional'][0] ?? '';
				if ( ! \preg_match( '/^[a-f0-9]{8,64}$/', $hash ) ) {
					throw new \RuntimeException( 'invalid hash format' );
				}

				// @longform The row that opened this modal was the selected
				// server's, so this answers for the same server — otherwise one
				// click puts a site-wide average under a scoped count, on two
				// surfaces too far apart to compare.
				$server = (string) ( $opts['server'] ?? '' );

				\assert( $self instanceof self );
				$entry = $self->row( $hash, $server );
				$stats = null;
				if ( null !== $entry ) {
					$stats = [
						'hash'                => $hash,
						'url'                 => $entry['url'] ?? '',
						'count'               => $entry['count'] ?? 0,
						'avg_ms'              => $entry['avg_ms'] ?? 0,
						'min_ms'              => $entry['min_ms'] ?? 0,
						'max_ms'              => $entry['max_ms'] ?? 0,
						'avg_peak_mb'         => $entry['avg_peak_mb'] ?? 0,
						'max_peak_mb'         => $entry['max_peak_mb'] ?? 0,
						'last_updated'        => $entry['last_updated'] ?? 0,
						// The header's own window and divisor.
						'requests_per_second' => self::recent_rate( Core::num_int( $entry['recent_count'] ?? null ) ),
					];
				}
				if ( null === $stats ) {
					throw new \RuntimeException( \esc_html( "URL not found: {$hash}" ) );
				}

				$aggregate = self::find_url_aggregate( $hash );
				$flame     = $aggregate['flame']
					?? [ 'name' => 'aggregate', 'value' => 0, 'children' => [] ];

				$recent  = self::find_recent_requests_for_url( $hash, self::require_option_int( $opts, 'since', 0 ) );
				$payload = [
					'stats'              => $stats,
					'requests'           => $recent['requests'],
					// An empty list that stopped short is not an empty URL.
					'scan_stopped_early' => $recent['truncated'],
					// Nor is one that ran out of window an empty record.
					'requests_window_start' => $recent['window_start'],
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
					'name'        => 'url_breakdown',
					'capability'  => Capabilities::READ,
					'description' => "One URL's dimensional time series, and nothing else.",
					'args'        => [
						[ 'name' => 'hash', 'type' => 'string', 'required' => true ],
						[ 'name' => 'breakdown', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				// @longform The chart polls this while the modal is open and
				// keeps only the series, so it reads memcache and never the
				// index: `url_detail` walks every partition's index to build
				// `requests`, which a breakdown fetch throws away.
				$parsed = Command_Args::parse( self::arg_strings( $args ) );
				$hash   = $parsed['positional'][0] ?? '';
				if ( ! \preg_match( '/^[a-f0-9]{8,64}$/', $hash ) ) {
					throw new \RuntimeException( 'invalid hash format' );
				}
				$breakdown = (string) ( $parsed['options']['breakdown'] ?? '' );
				self::assert_dimension( $breakdown );
				return [ 'breakdown_time_series' => self::merge_url_dim( $hash, $breakdown ) ];
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

				$found = self::find_request_index_entry( $rid );
				if ( null === $found ) {
					throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
				}
				return $found;
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
					'description' => 'Full request + flame data for a rid; --partition hints where to look first.',
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

				// A hint, as in `ask`: that partition first, then the rest.
				$result = self::load_request( $rid, $partition );
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
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						// Declared because the handler reads it, positionally.
						[ 'name' => 'context', 'type' => 'string', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				\assert( $self instanceof self );
				$parsed  = Command_Args::parse( self::arg_strings( $args ) );
				$context = (string) ( $parsed['options']['context'] ?? '' );
				// Declared as an option; the descriptors stay positional.
				return $self->assemble_ask(
					'' === $context
						? $parsed['positional']
						: [ ...$parsed['positional'], $context ],
					(string) ( $parsed['options']['server'] ?? '' )
				);
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
