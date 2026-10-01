<?php
/**
 * Performance_CI: command-dispatch for the performance-dashboard surface.
 *
 * Verbs the live surfaces drive:
 *   - overview / urls / dump_url / search_requests / grep_requests /
 *     dump_request — the performance dashboard's per-slice graph
 *     (`src/overview/hooks/usePerformanceGraph.js`), plus the
 *     current-request overlay tab, which fetches `dump_request`.
 *   - url_breakdown — the URL-detail modal's dimension chart, which polls
 *     one series while the modal is open and wants none of the index walk
 *     `dump_url` pays for.
 *   - ask — one descriptor chain's brief for the `?` picker
 *     (`src/overview/components/AskPanel.js`).
 *   - list_hooks — the Settings page's hook-catalog tree.
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
 *    dashboard slices, `tune` for the settings receiver — and `dispatch()`
 *    refuses a caller below it (ADR-26). No handler re-gates itself; a hard-coded
 *    gate would silently override the declaration.
 *  - Rate limit: none here. The substrate's `/command` endpoint already caps
 *    POSTs per user per window, so a polling dashboard is bounded upstream.
 *  - Stats reads fail soft, as `Stats_Store` and the dashboards' "no data"
 *    state do.
 *  - Stats come from the flame builder's store alone.
 *  - Disk scans are bounded twice. The per-URL walk stops at `scan_floor()`,
 *    in a segment that closed before it, because its index carries time.
 *    Every walk, a missing-rid lookup included, also stops after
 *    MAX_SCAN_S of walking, one budget per verb.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Config as AppConfig;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Reqgrep_Core;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Url_Sketch;
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
use Newspack_Nodes\Table_Client;
use Newspack_Nodes\Table_Unavailable;

\defined( 'ABSPATH' ) || exit;

/**
 * Service CI mounted as `performance` on `newspack_nodes/request_graph_ready`.
 *
 * Every verb is declared once in `node_schema()['commands']`; the inherited
 * Service_CI_Node constructor turns that schema into the dispatch table, so
 * this class holds no commands table of its own.
 *
 * The static helpers below fall into three families:
 *   - stats readers, which fan a Stats_Store out per flame-builder worker
 *     and sum-merge the per-partition buckets;
 *   - disk walkers, which construct throwaway Partition/Consumer nodes over
 *     the DECLARED node dirs and remove them again;
 *   - `set` sanitizers, which bound the values arriving from the hub.
 */
class Performance_CI_Node extends Service_CI_Node {

	/**
	 * Seconds one disk-walking verb may spend reading — .idx lines, or the
	 * firehose lines `grep_requests` groups — the BACKSTOP for a walk that has
	 * no better bound, not the bound the common case reaches.
	 *
	 * The per-URL walk stops at `scan_floor()`, so its real cost is a retention
	 * window of index, whatever the index holds behind that. What is left under
	 * this cap is a walk with nothing to stop it: a rid lookup, which searches
	 * for one line and cannot know how far back it sits; the flame index,
	 * whose lines carry no time to compare (`Flame_Builder_Node::index_completion_columns()`);
	 * and `grep_requests`, whose `recent` window can hold more than a reply
	 * has time to group.
	 *
	 * Time, not a line count, because partitions are walked one after another:
	 * a count sized to one host's traffic ends a busier hub's walk inside its
	 * first partitions, and every request hashed to the rest is unreachable
	 * from its URL. An index miss is one `substr` + `trim`, with the clock read
	 * once per SCAN_CLOCK_STRIDE lines, so a window of millions of index lines
	 * costs well under a second, and the cap binds only a walk that has
	 * genuinely run long; a grep line is dearer, grouped whole. A verb that walks twice, as a rid lookup does over requests and
	 * then flames, spends ONE budget across both. Peak memory stays ONE
	 * segment's index. A walk that spends it says so; see
	 * `scan_index_entries()`.
	 */
	public const MAX_SCAN_S = 10;

	/**
	 * Lines a walk reads between two looks at the clock — index lines, or the
	 * firehose lines `grep_requests` reads. A clock read costs as much as an
	 * index miss, and one per hundred lines keeps it off the walk's cost, far
	 * below anything a 10-second budget can notice.
	 */
	private const SCAN_CLOCK_STRIDE = 100;

	/**
	 * URL rows an `overview:` brief walks for: exactly what the brief keeps,
	 * so raising one raises both. The rest is a pointer to `performance_urls`.
	 */
	private const OVERVIEW_BRIEF_URLS = Ask_Assembler::TOP_SPANS;

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
	private const NODE_FLAMES   = 'flames:partition';
	private const NODE_REQUESTS = 'requests:partition';

	/** `dump_url` per-URL request-list cap, applied to the index walk. */
	private const RECENT_REQUEST_LIMIT = 500;

	/** `grep_requests` default / max matched-request results (bounds the reply). */
	private const GREP_RESULT_LIMIT_DEFAULT = 20;
	private const GREP_RESULT_LIMIT_MAX     = 50;

	/** `grep_requests` in-flight LRU_Cache geometry (100 × 3 = 300 concurrent rids). */
	private const GREP_INFLIGHT_BUCKET_SIZE = 100;
	private const GREP_INFLIGHT_NUM_BUCKETS = 3;

	/** `grep_requests` history-bucket geometry for the shared grouping engine. */
	private const GREP_HISTORY_BUCKET_SIZE = 250;
	private const GREP_HISTORY_NUM_BUCKETS = 10;

	/**
	 * Valid breakdown dimensions for the `dump_url` / `url_breakdown` verbs —
	 * typos fall through without surfacing arbitrary stats reads. A URL
	 * belongs to one server, so it keeps no server axis.
	 */
	private const URL_DIMENSIONS = [ 'status', 'method', 'country', 'from', 'ua', 'ja4' ];

	/** Valid breakdown dimensions for the `overview` verb: the URL's and `server`. */
	private const DIMENSIONS = [ Stats_Store::DIM_SERVER, ...self::URL_DIMENSIONS ];

	/** Slowest rows the `urls` reply carries for the Ask brief's examples. */
	private const SLOWEST_ROWS = 10;

	/** The aggregate flame of a URL with no requests to fold. */
	private const EMPTY_FLAME = [ 'name' => 'aggregate', 'value' => 0, 'children' => [] ];

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

	/** @var array<string,list<string>> Memoized `chart_slots()`, under the one bucket it ends at. */
	private static array $chart_slots = [];

	/** Table namespace of the URL page cache, beside `Rule_Set::TABLE_HOOKS`. */
	private const URLS_PAGE_NS = 'eln-urls-page';

	/**
	 * The widest page cached, and its size bound: 250 of the widest rows a
	 * reply names serialize to about 490KB, inside `Stats_Store::ITEM_BUDGET`.
	 */
	private const URLS_PAGE_CACHE_MAX_ROWS = 250;

	/**
	 * The keys one URL page carries. Three places assemble a page — the fold,
	 * the ranked reader and the header — and the cache reads a missing key as
	 * a miss, so all four spell the shape from here.
	 */
	private const PAGE_FIELDS = [ 'data', 'ranked', 'as_of', ...self::HEADER_FIELDS ];

	/** The subset `url_header()` answers for; a ranked page takes them whole. */
	private const HEADER_FIELDS = [ 'rows', 'totals', 'slowest', 'estimated', 'provisional' ];

	/**
	 * Buckets read per `MGET` while folding the index.
	 *
	 * Decision 6 wants ONE round trip per read, not one per key; it does not
	 * want the whole retention window resident. Twelve is an hour of fine
	 * buckets, so a chunk is a natural unit and the batch stays wide.
	 */
	public const INDEX_READ_CHUNK = 12;

	/**
	 * URL-index read seam. Lazily-defaulted to the real merge-across-partitions
	 * loader (load_index_default). Tests reassign it to COUNT shard reads without
	 * short-circuiting the production fan-out — the merge logic still runs as
	 * real code (mirrors `Insights_CI_Demo_Node::$read_items`). A search and
	 * `load_row()` read by key and never reach it, which is what a count of
	 * zero proves.
	 *
	 * It takes the SHARD and the SERVER: a scoped read is that server's keys.
	 *
	 * Resolved on every read through `read_index()`; reassign in a test
	 * bootstrap, restore in a finally.
	 *
	 * It takes the STORES too, resolved once by the caller: each resolution
	 * builds the topology catalog, and a verb reads sixteen shards.
	 *
	 * It takes the reply's `$now`, so every shard of one reply reads one
	 * window, and last whether the page folds each key's errored rows alone.
	 *
	 * Signature: `function ( string $shard, string $server, list<Stats_Store> $stores, int $now, bool $errored ): array<int,array<string,mixed>>`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $load_index = null;

	/**
	 * The switch for the name scan, OFF: the fold a term the token index
	 * cannot answer falls back to — no token, every token's set past
	 * `URL_SEARCH_MAX`, or more candidates than that — walking every name
	 * for it. Off, `urls --search` reads the token index alone: a term with
	 * no token names nothing, a term too common to narrow is refused, and no
	 * reply walks names beyond the candidates. Either way each candidate is
	 * checked against the whole term on its row's own path. The name scan's
	 * tests turn it on.
	 *
	 * @var bool
	 */
	public static bool $match_names = false;

	/** This CI's asker for the stats Tables, built on the first read. */
	private ?Table_Client $client = null;

	/**
	 * A stats Table's reply goes to the client; every other message is this
	 * CI's input.
	 *
	 * @param array<int,mixed> $message The 7-field positional message array.
	 */
	public function fill( array $message ): void {
		if ( null !== $this->client && $this->client->accepts( $message ) ) {
			return;
		}
		parent::fill( $message );
	}

	/**
	 * Coerce and bounds-check one value for `set`.
	 *
	 * Rejection is signalled by null, so a legitimately-null sanitized value is
	 * not representable — every accepted type here returns a scalar or array.
	 *
	 * A bool is one of the substrate's bool words, or a blank for false,
	 * which is how a hub pushes one; any other word rejects.
	 *
	 * @param mixed  $value Raw input.
	 * @param string $type  `bool` or `array`; anything else rejects.
	 * @return mixed|null Sanitized value, or null to reject.
	 */
	private static function sanitize_settings_value( mixed $value, string $type ): mixed {
		switch ( $type ) {
			case 'bool':
				return '' === $value ? false : Command_Args::typed( Core::as_string( $value ), 'bool' );
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
	 * null, and dropping it produces a map `Rule::from_array()` refuses, so a
	 * normal settings push fails.
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
	 * @param string                 $dimension One of DIMENSIONS.
	 * @param string                 $server    Server scope; ignored for the `server` dimension.
	 * @param array<int,Stats_Store> $stores    Stores the caller resolved once.
	 * @param int                    $now       The reply's clock, read once at its entry.
	 * @return array<array-key,mixed> Bucket keys derive from decoded stored values.
	 */
	private static function merge_dim_across_partitions( string $dimension, string $server, array $stores, int $now ): array {
		$store_server = 'server' === $dimension ? '' : $server;
		return self::merged_across_stores(
			static fn ( Stats_Store $store, array $hours ): array => $store->get_slots( Stats_Store::dim_parts( $dimension, $store_server ), $hours ),
			Stats_Store::DIM_SUMS,
			Stats_Store::DIM_COUNT,
			$stores,
			$now
		);
	}

	/**
	 * Sum-merge category buckets across all partitions, for one reporting
	 * server or, with '', for the site.
	 *
	 * @param string                 $server Server scope; '' merges every server.
	 * @param array<int,Stats_Store> $stores Stores the caller resolved once.
	 * @param int                    $now    The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 */
	private static function merge_categories_across_partitions( string $server, array $stores, int $now ): array {
		return self::merged_across_stores(
			static fn ( Stats_Store $store, array $hours ): array => $store->get_slots( Stats_Store::cat_parts( $server ), $hours ),
			Stats_Store::CAT_SUMS,
			Stats_Store::CAT_REQUESTS,
			$stores,
			$now
		);
	}

	/**
	 * Sum-merge per-URL category buckets for one hash.
	 *
	 * @param string                 $hash   12-char URL hash.
	 * @param array<int,Stats_Store> $stores Stores the caller resolved once.
	 * @param int                    $now    The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 */
	private static function merge_url_categories( string $hash, array $stores, int $now ): array {
		return self::merged_across_stores(
			static fn ( Stats_Store $store, array $hours ): array => $store->get_slots( Stats_Store::url_cat_parts( $hash ), $hours ),
			Stats_Store::CAT_SUMS,
			Stats_Store::CAT_REQUESTS,
			$stores,
			$now
		);
	}

	/**
	 * The site-wide half of the dashboard: request totals and the chart series.
	 *
	 * These are the SITE's: `hourly_h` has no server dimension, so scoping one of
	 * them and not the others is how a payload comes to contradict itself. Every
	 * URL-set fact — how many, which are slowest, which are busiest — belongs to
	 * the `urls` verb, which owns the filters and answers all of it in one scope
	 * (decision 15). Nothing here touches the URL index, which is what keeps a
	 * filtered poll to ONE fan-out across the chart window.
	 *
	 * The totals sum the slots of `hourly_slots()` that `chart_slots()`
	 * draws, so they cover what the charts beside them draw; the reply names
	 * those slots. `total_requests` and the average duration count the timed
	 * requests (decision 24), and the average peak divides every request its
	 * peak sum covers.
	 *
	 * @param array<string,array<array-key,mixed>> $hourly `hourly_slots()`.
	 * @param int                                  $now    The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 */
	private static function build_overview_payload( array $hourly, int $now ): array {
		$slots  = self::chart_slots( $now );
		$totals = [];
		foreach ( \array_intersect_key( $hourly, \array_flip( $slots ) ) as $slot ) {
			$totals = Stats_Store::add_totals( $totals, $slot );
		}
		$count    = Core::num_int( $totals['count'] ?? null );
		$requests = Core::num_int( $totals['requests'] ?? null );
		return [
			'total_requests'     => $count,
			'global_avg_ms'      => Stats_Store::mean( Core::num_float( $totals['sum_ms'] ?? null ), $count ),
			'global_avg_peak_mb' => Stats_Store::mean( Core::num_float( $totals['sum_peak_mb'] ?? null ), $requests ),
			'slots'              => $slots,
		];
	}

	/**
	 * The site's request totals over `chart_keys()`, every slot of them,
	 * summed across the stores by bucket: the one `hourly_h` read an
	 * `overview` makes, for its totals and the board's average alike.
	 *
	 * @param array<int,Stats_Store> $stores Stores the caller resolved once.
	 * @param int                    $now    The reply's clock, read once at its entry.
	 * @return array<string,array<array-key,mixed>>
	 */
	private static function hourly_slots( array $stores, int $now ): array {
		$hours  = self::chart_keys( $now );
		$merged = [];
		foreach ( $stores as $store ) {
			foreach ( $store->get_slots( Stats_Store::hourly_parts(), $hours ) as $bucket => $slot ) {
				$merged[ $bucket ] = Stats_Store::add_totals( $merged[ $bucket ] ?? [], Core::arr( $slot ) );
			}
		}
		return $merged;
	}

	/**
	 * Locate a single request index entry by rid and return the search shape
	 * `{rid, partition, url_hash}` — enough for the dashboard to then ask for
	 * `dump_request`; the request body is not read here. Its own partition is
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
	 * per-request byte/line cap (in the engine), MAX_SCAN_S of reading — which
	 * stops the drain itself, so the partitions after it go unread — and a
	 * result cap. Every limit is reported honestly in `truncated`.
	 *
	 * Many processes append to the firehose, so a torn line can sit in it until
	 * its segment rotates. Having no cursor to replay it from, the scan skips
	 * one and counts it in `unparseable_lines` rather than failing every search.
	 *
	 * @param string $pattern Raw user pattern; matched case-insensitively.
	 * @param int    $limit   Maximum matching requests to return.
	 * @return array{pattern:string, scope:string, scanned_partitions:int, results:array<int,array<string,mixed>>, truncated:bool, result_count:int, unparseable_lines:int}
	 */
	private static function run_grep_requests( string $pattern, int $limit ): array {
		$results   = [];
		$truncated = false;
		$regex     = Reqgrep_Core::compile( $pattern );
		$clock     = self::scan_clock();
		$deadline  = self::scan_deadline();
		$lines     = 0;
		$spent     = false;

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
		$on_message = static function ( array $message ) use ( $core, $clock, $deadline, &$lines, &$spent, &$truncated ): void {
			$entry = $message[ Message::VALUE ];
			$rid   = Core::as_string( $message[ Message::KEY ] ?? '' );
			if ( $spent || ! \is_array( $entry ) || '' === $rid ) {
				return;
			}
			if ( 0 === ++$lines % self::SCAN_CLOCK_STRIDE && $clock() > $deadline ) {
				$spent     = true;
				$truncated = true;
				return;
			}
			// array_values keeps the positional list for the packer.
			$core->push( $entry, $rid, Message::packed( \array_values( $message ) ) );
		};

		$scanned = [];
		foreach ( Log_Manager::firehose_dirs() as $p => $source_dir ) {
			if ( $spent ) {
				break;
			}
			if ( ! \is_dir( $source_dir ) ) {
				continue;
			}
			$consumer = Consumer_Node::scan( $source_dir );
			try {
				self::name_scratch_consumer( $consumer, $p );
				$consumer->sink( new Callback_Node( $on_message ) );
				$consumer->next_offset( 'recent' );
				// By reference: an arrow fn would freeze $spent at false.
				$consumer->drain(
					static function () use ( &$spent ): bool {
						return $spent;
					}
				);
				$scanned[] = $consumer;
			} finally {
				$consumer->remove_node();
			}
		}

		return [
			'pattern'            => $pattern,
			'scope'              => 'recent',
			'scanned_partitions' => \count( $scanned ),
			'results'            => $results,
			'truncated'          => $truncated,
			'result_count'       => \count( $results ),
			'unparseable_lines'  => Consumer_Node::take_unparseable_lines_of( $scanned ),
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
	 * Human-readable excerpt of the first matching entry ("key: message").
	 * Arrays JSON-encode; `GREP_RESULT_LIMIT_MAX` bounds the reply, not this.
	 *
	 * @param string $key     The entry `k` field.
	 * @param mixed  $message The entry `m` field (string or array).
	 */
	private static function grep_excerpt( string $key, mixed $message ): string {
		$text = \is_array( $message ) ? Core::as_string( \wp_json_encode( $message ) ) : Core::as_string( $message );
		$raw  = '' === $key ? $text : "{$key}: {$text}";
		return \trim( $raw );
	}

	/**
	 * Name a transient scratch Consumer `firehose-grep.{token}.p{N}`, unique per
	 * scan so a live worker's registry cannot collide with it. Callers
	 * `remove_node()` it after use.
	 *
	 * @param Consumer_Node $consumer Freshly-constructed scratch Consumer.
	 * @param int           $index    Firehose partition index.
	 */
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
	 * @param string              $descriptor The target.
	 * @param list<string>        $context    Its containers, outermost last.
	 * @param string              $server     Reporting server the brief answers for; '' is every server.
	 * @param array<string,mixed> $filters    The url filters in force, which only `overview:` reads: every other descriptor names one thing, and a filtered view of one thing is the same thing.
	 * @param int                 $now        The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On an unknown descriptor or a missing context.
	 */
	private function assemble_ask( string $descriptor, array $context, string $server, array $filters, int $now ): array {
		$target = Ask_Assembler::parse_descriptor( $descriptor )
			?? throw new \RuntimeException( \esc_html( "unknown descriptor: {$descriptor}" ) );

		switch ( $target['type'] ) {
			case 'overview':
				return $this->ask_overview( $server, $filters, $now );
			case 'url':
				return $this->ask_url( $target['id'], $server, $now );
			case 'request':
				return self::ask_request( $target['id'], self::descriptor_partition( $target ), $context, $server );
			case 'span':
				return $this->ask_span( $target['id'], $context, $now );
			case 'entry':
				return self::ask_entry( $target['id'], $context );
			case 'category':
				return $this->ask_category( $target['id'], $context, $server, $now );
		}
		throw new \RuntimeException( \esc_html( 'unknown descriptor: ' . $target['type'] ) );
	}

	/**
	 * The `overview:` brief — the dashboard as it is being read.
	 *
	 * It asks the same two verbs the page does, with the same scope, so the
	 * brief and the screen cannot disagree about WHAT they describe:
	 * `url_page()` for the filtered set's totals and leaderboard,
	 * `build_leaderboard()` for the category board beside it. They can
	 * disagree about WHEN: the URL half is a page the cache may hold up to
	 * `Stats_Store::URL_PAGE_REFRESH_S` behind the board. The site-wide
	 * `build_overview_payload()` is
	 * deliberately NOT used — its totals ignore every filter, so under a
	 * server or a search they would describe a different site than the one on
	 * screen.
	 *
	 * @param string              $server  Server the page is scoped to; '' is every server.
	 * @param array<string,mixed> $filters search / errors_only / include_workers, as the page has them.
	 * @param int                 $now     The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 */
	private function ask_overview( string $server, array $filters, int $now ): array {
		$stores = $this->stats_stores( [ Stats_Store::TABLE_AGGREGATE, Stats_Store::TABLE_URL_FINE ] );
		$page   = $this->url_page(
			$server,
			Core::as_string( $filters['search'] ?? '' ),
			(bool) ( $filters['errors_only'] ?? false ),
			(bool) ( $filters['include_workers'] ?? false ),
			'count',
			'desc',
			0,
			self::OVERVIEW_BRIEF_URLS,
			$stores,
			$now
		);

		return Ask_Assembler::for_overview(
			[
				// The page already answered whether its totals cover the scope.
				'totals'      => $page['totals'],
				'estimated'   => $page['estimated'],
				'provisional' => $page['provisional'],
				'data'        => $page['data'],
			],
			self::build_leaderboard( $server, $stores, $now ),
			$server,
			$filters
		);
	}

	/**
	 * The `url:` brief — stats, worst recent requests, and the
	 * cold-start finding when nothing governs it.
	 *
	 * @param string $hash   12-char URL hash the descriptor names.
	 * @param string $server Server the brief answers for; '' is every server.
	 * @param int    $now    The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When no URL row carries that hash.
	 */
	private function ask_url( string $hash, string $server, int $now ): array {
		// @longform Through `row()`, never the loader: the loader emits sums
		// and leaves the means to the projection, so a reader taking its
		// output raw quotes a confident 0 for every average. Scoped, because
		// the facts block stamps the filters onto every surface, and an
		// unscoped number under a server's name is quotable and wrong.
		$stats = $this->row( $hash, $server, $this->stats_stores( [ Stats_Store::TABLE_AGGREGATE, Stats_Store::TABLE_URL_FINE ] ), $now );
		if ( null === $stats ) {
			throw new \RuntimeException( \esc_html( "URL not found: {$hash}" ) );
		}
		$recent = self::find_recent_requests_for_url( $hash, $now );
		return Ask_Assembler::for_url(
			$stats,
			$recent['requests'],
			Rule_Set::load()->for_url( Core::as_string( $stats['url'] ?? '' ) ),
			$server,
			$recent['truncated'],
			$recent['window_start']
		);
	}

	/**
	 * The filtered URL set: its totals, its slowest, and one page of it.
	 *
	 * Two paths answer. A page with no filter and an end inside the fine
	 * tier's list depth reads the writer's ranked lists — `URL_RANK_N`
	 * entries per bucket, cut and folded here — and takes its header from
	 * the writer's header records through `url_header()`, once a refresh.
	 * Everything else folds. A ranked page says so with `ranked`, because
	 * its averages are the means of the bucket averages it ranked with, and
	 * a count outside every bucket's list is a count the page cannot see.
	 *
	 * It is read THROUGH the cache for `Stats_Store::URL_PAGE_REFRESH_S` under every filter,
	 * the window bucket, the retention and the store count: a fold over a
	 * hub's whole URL index runs tens of seconds, and every tab polling the
	 * same page would otherwise pay it again. A page wider than
	 * `URLS_PAGE_CACHE_MAX_ROWS` is built and never stored.
	 *
	 * @param string                 $server  Reporting server to scope to; '' reads every server.
	 * @param string                 $search  Case-insensitive whole URL words; '' matches all.
	 * @param bool                   $errors  Keep only rows with unclassified requests.
	 * @param bool                   $workers Keep worker traffic (the default excludes it).
	 * @param string                 $sort    A URL_SORTS field.
	 * @param string                 $order   'asc' or 'desc'.
	 * @param int                    $offset  Page offset.
	 * @param int                    $limit   Page size.
	 * @param array<int,Stats_Store> $stores  Stores the caller resolved once.
	 * @param int                    $now     The reply's clock, read once at its entry.
	 * @return array{data:array<int,array<array-key,mixed>>,rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,estimated:bool,provisional:bool,ranked:bool,as_of:int}
	 */
	private function url_page( string $server, string $search, bool $errors, bool $workers, string $sort, string $order, int $offset, int $limit, array $stores, int $now ): array {
		// @longform Normalized ONCE, here: `Womb`, `womb` and `womb ` are one
		// search, and a key that normalizes while the paths below it read the
		// raw term would answer one entry two ways. The handler echoes the
		// raw value in `filters`, which is the only place it still matters.
		$search = \strtolower( \trim( $search ) );
		$build  = function () use ( $server, $search, $errors, $workers, $sort, $order, $offset, $limit, $stores, $now ): array {
			$result = self::ranked_serves( $search, \max( 0, $offset ) + \max( 0, $limit ) )
				? $this->ranked_page( $server, $workers, $errors, $sort, $order, $offset, $limit, $stores, $now )
				: null;
			return $result ?? $this->fold_page( $server, $search, $errors, $workers, $sort, $order, $offset, $limit, $stores, $now );
		};
		if ( $limit > self::URLS_PAGE_CACHE_MAX_ROWS ) {
			return $build();
		}
		/** @var array{data:array<int,array<array-key,mixed>>,rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,estimated:bool,provisional:bool,ranked:bool,as_of:int} */
		return self::read_through_page(
			Flame_Tree::URL_PAGE_CACHE,
			[ $server, $search, $errors, $workers, $sort, $order, $offset, $limit, Stats_Store::bucket_key( $now ), AppConfig::stats_retention_seconds(), \count( $stores ) ],
			self::PAGE_FIELDS,
			Stats_Store::URL_PAGE_REFRESH_S,
			$build
		);
	}

	/**
	 * A page from the ranked lists: the two tiers' lists for this scope and
	 * sort across the read plan, folded by hash and cut here. The site's
	 * lists are the writer's, one a key, ranked over every server's rows
	 * when it wrote theirs (`Stats_Store::ranked_writes()`).
	 *
	 * Both tiers are known before the first read, so they go out together:
	 * one round trip per store after the site's server index. An hour whose
	 * list is present stands for its twelve buckets, and a folded hour
	 * missing its list takes the whole page back to the fold, which reads the
	 * hour's rows, rather than serving a window one hour short as ranked. An
	 * hour the writer still owes, holding no index or missing a DONE marker,
	 * is no hole: it adds nothing, and the page says `provisional`.
	 *
	 * The two averages are the mean of the per-BUCKET averages at each
	 * bucket's own tier — a five-minute bucket of the current hour, a folded
	 * hour behind it — every stored bucket weighing the same. So an hour-tier
	 * entry contributes ONE average covering that hour where a fine entry
	 * contributes one per five minutes, and nothing reweights them: an entry
	 * weighs what it ranked as. `fold_index_row()` carries every other field
	 * exactly as the fold would, and `totals` stays request-weighted, so the
	 * header and a row's own average answer different questions. No list
	 * anywhere reads as no tier to read, not as an empty site.
	 *
	 * @param string                 $server  Reporting server; '' reads the site's lists.
	 * @param bool                   $workers Read the worker family's lists beside the reader's.
	 * @param bool                   $errors  Read the errored rows' lists alone.
	 * @param string                 $sort    A `Stats_Store::URL_SORTS` value.
	 * @param string                 $order   A `Stats_Store::URL_ORDERS` value.
	 * @param int                    $offset  Page offset.
	 * @param int                    $limit   Page size.
	 * @param array<int,Stats_Store> $stores  Stores the caller resolved once.
	 * @param int                    $now     The reply's clock, read once at its entry.
	 * @return array{data:array<int,array<array-key,mixed>>,rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,estimated:bool,provisional:bool,ranked:bool,as_of:int}|null
	 */
	private function ranked_page( string $server, bool $workers, bool $errors, string $sort, string $order, int $offset, int $limit, array $stores, int $now ): ?array {
		// The header first: its own miss folds the page this poll answers with.
		$header = $this->url_header( $server, $workers, $errors, $sort, $order, $offset, $limit, $stores, $now );
		if ( isset( $header['data'] ) ) {
			/** @var array{data:array<int,array<array-key,mixed>>,rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,estimated:bool,provisional:bool,ranked:bool,as_of:int} */
			return $header;
		}
		$plan    = self::read_plan( $now );
		$recent  = self::recent_keys( $plan );
		$lagging = self::lagging( $plan );
		$merged  = [];
		$means   = [];
		$found   = 0;
		$holes   = [];
		$skipped = false;
		$sets    = Stats_Store::rank_sets( $workers, $errors );
		foreach ( $stores as $store ) {
			$covered = [];
			foreach ( $store->url_rank_window( $plan['hours'], $plan['fine'], $sort, $order, $server, $sets, $waiting ) as [ $key, $entries ] ) {
				$covered[ $key ] = true;
				self::note_bucket_means( $means, self::fold_rank_entries( $merged, $entries, isset( $recent[ $key ] ) ) );
				++$found;
			}
			// Left out of the lists: a hole, but for lag or a fold owed.
			$owed    = $lagging + \array_fill_keys( $waiting, true );
			$missing = \array_fill_keys( \array_diff( $plan['hours'], \array_keys( $covered ) ), true );
			$skipped = $skipped || [] !== \array_intersect_key( $missing, $owed );
			$holes   = \array_map( 'strval', \array_keys( \array_diff_key( $missing, $owed ) ) );
			if ( [] !== $holes ) {
				break;
			}
		}
		$m = match ( true ) {
			[] !== $holes => 'fold: holes ' . \implode( ' ', $holes ),
			0 === $found  => 'fold: no lists',
			default       => 'ranked',
		};
		Log_Manager::started_instance()?->message(
			Flame_Tree::URL_RANK_LISTS,
			[
				'm'     => $m,
				'found' => $found,
				'holes' => \count( $holes ),
				'keep'  => 1,
			]
		);
		if ( 'ranked' !== $m ) {
			return null;
		}
		$rows = [];
		foreach ( $merged as $hash => $entry ) {
			$row = self::project_row( $entry );
			[ $ms_sum, $ms_n, $peak_sum, $peak_n ] = $means[ $hash ];
			$row['avg_ms']      = Stats_Store::mean( $ms_sum, $ms_n );
			$row['avg_peak_mb'] = Stats_Store::mean( $peak_sum, $peak_n );
			$rows[]             = $row;
		}
		\usort( $rows, self::by_sort( Stats_Store::rank_key( $sort, $errors ), $order ) );
		// The header answers `HEADER_FIELDS`; these three are the page's own.
		return [
			'data'        => self::resolve_urls( \array_slice( $rows, $offset, $limit ), $stores ),
			'ranked'      => true,
			'as_of'       => $now,
			'provisional' => $skipped || $header['provisional'],
		] + $header;
	}

	/**
	 * The header of a ranked page of one scope, summed from the
	 * writer's header records (`recorded_header()`), held for
	 * `Stats_Store::URL_PAGE_REFRESH_S`, the cadence the writer ranks the
	 * open bucket at, which every header's window holds: a server filed
	 * after the site record's last ranking is short from it for no longer
	 * than a ranked row lags (decision 28).
	 *
	 * A hole in the records folds instead, and the fold folds THIS page —
	 * the caller's sort, order, offset and limit — and returns it whole, so
	 * the poll that pays for the walk answers with what it walked and the
	 * next one reads the lists. A hit carries `HEADER_FIELDS` alone.
	 *
	 * @param string                 $server  Reporting server; '' is the site.
	 * @param bool                   $workers Sum the worker family's records beside the reader's.
	 * @param bool                   $errors  Sum the errored rows' records alone.
	 * @param string                 $sort    A URL_SORTS field.
	 * @param string                 $order   'asc' or 'desc'.
	 * @param int                    $offset  Page offset.
	 * @param int                    $limit   Page size.
	 * @param array<int,Stats_Store> $stores  Stores the caller resolved once.
	 * @param int                    $now     The reply's clock, read once at its entry.
	 * @return array{rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,estimated:bool,provisional:bool,data?:array<int,array<array-key,mixed>>,ranked?:bool,as_of?:int}
	 */
	private function url_header( string $server, bool $workers, bool $errors, string $sort, string $order, int $offset, int $limit, array $stores, int $now ): array {
		$page  = null;
		$build = function () use ( $server, $workers, $errors, $sort, $order, $offset, $limit, $stores, $now, &$page ): array {
			$header = self::recorded_header( $server, $workers, $errors, $stores, $now );
			if ( null !== $header ) {
				return $header;
			}
			$page = $this->fold_page( $server, '', $errors, $workers, $sort, $order, $offset, $limit, $stores, $now );
			return \array_intersect_key( $page, \array_flip( self::HEADER_FIELDS ) );
		};
		/** @var array{rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,estimated:bool,provisional:bool} $header */
		$header = self::read_through_page(
			Flame_Tree::URL_HEADER_CACHE,
			[ 'header', $server, $workers, $errors, Stats_Store::bucket_key( $now ), AppConfig::stats_retention_seconds(), \count( $stores ) ],
			self::HEADER_FIELDS,
			Stats_Store::URL_PAGE_REFRESH_S,
			$build
		);
		return $page ?? $header;
	}

	/**
	 * The header of one scope's list sets, summed from the writer's header
	 * records over the read plan, or null where a record is missing.
	 *
	 * Each store answers the plan's hours and the current hour's buckets in
	 * one round trip after its server index's own. A hole is null: a header
	 * summed over a window with a key missing understates the site's traffic
	 * and still reads as the site's, so the caller folds instead. A record
	 * the writer can still owe is no hole but ranking lag (`lagging()`),
	 * skipped as `url_rank_window()` skips a list not yet written, and so is
	 * an hour whose fold it still owes (`Stats_Store::url_headers()`'s
	 * `$waiting`). A header that skipped one says so with `provisional`, and
	 * is never cached.
	 *
	 * `totals.urls` is the merged `Url_Sketch`'s estimate, and the reply says
	 * so with `estimated`; `rows` adds the overflow row where any record
	 * holds one. The rate sums `recent_keys()`' records. `slowest` comes
	 * from the `Stats_Store::SLOWEST_LIST` lists over the same keys, each
	 * URL's mean weighted by request over the entries it made, and like a
	 * ranked page is exact only over the keys where it made one. No list
	 * ranks the `Other` overflow row, so unlike the fold's this `slowest`
	 * never carries it.
	 *
	 * @param string                 $server  Reporting server; '' is the site.
	 * @param bool                   $workers Sum the worker family's records beside the reader's.
	 * @param bool                   $errors  Sum the errored rows' records alone, and their errors.
	 * @param array<int,Stats_Store> $stores  Stores the caller resolved once.
	 * @param int                    $now     The reply's clock, read once at its entry.
	 * @return array{rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,estimated:true,provisional:bool}|null
	 */
	private static function recorded_header( string $server, bool $workers, bool $errors, array $stores, int $now ): ?array {
		$plan             = self::read_plan( $now );
		$lagging          = self::lagging( $plan );
		$provisional      = false;
		$recent           = self::recent_keys( $plan );
		[ $sort, $order ] = Stats_Store::SLOWEST_LIST;
		$summed           = [];
		$rated            = 0;
		$slowest          = [];
		$sets             = Stats_Store::rank_sets( $workers, $errors );
		foreach ( $stores as $store ) {
			// A folded hour's missing record is a hole; an owed one waits.
			$records = \array_replace( \array_fill_keys( $plan['hours'], null ), $store->url_headers( $plan['hours'], $plan['fine'], $server, $sets, $waiting ) );
			$owed    = $lagging + \array_fill_keys( $waiting, true );
			foreach ( $records as $key => $by_set ) {
				if ( null === $by_set || \in_array( null, $by_set, true ) ) {
					if ( ! isset( $owed[ $key ] ) ) {
						return null;
					}
					$provisional = true;
					unset( $records[ $key ] );
					continue;
				}
				foreach ( $by_set as $at => $record ) {
					$summed[ $at ][] = $record;
					$rated          += isset( $recent[ $key ] ) ? $record[ Stats_Store::HDR_COUNT ] : 0;
				}
			}
			foreach ( $store->url_rank_window( \array_values( \array_intersect( $plan['hours'], \array_keys( $records ) ) ), $plan['fine'], $sort, $order, $server, $sets ) as [ , $entries ] ) {
				self::fold_rank_entries( $slowest, $entries, false );
			}
		}
		// Each set keeps an overflow row of its own, as the fold counts them.
		$set_totals = \array_map( Stats_Store::merge_url_headers( ... ), $summed );
		$others     = \count( \array_filter( \array_column( $set_totals, Stats_Store::HDR_HAS_OTHER ) ) );
		$total      = Stats_Store::merge_url_headers( \array_values( $set_totals ) );
		$top        = \array_map( self::project_row( ... ), \array_values( $slowest ) );
		\usort( $top, self::by_sort( $sort, $order ) );
		$urls = Url_Sketch::estimate( $total[ Stats_Store::HDR_URLS ] );
		return [
			'rows'      => $urls + $others,
			'totals'    => [
				'urls'                => $urls,
				'requests'            => $total[ Stats_Store::HDR_COUNT ],
				'avg_ms'              => Stats_Store::mean( $total[ Stats_Store::HDR_SUM_MS ], $total[ Stats_Store::HDR_TIMED_COUNT ] ),
				'avg_peak_mb'         => Stats_Store::mean( $total[ Stats_Store::HDR_SUM_PEAK_MB ], $total[ Stats_Store::HDR_COUNT ] ),
				'requests_per_second' => self::recent_rate( $rated, $recent ),
			] + ( $errors ? [ 'errors' => $total[ Stats_Store::HDR_ERRORS ] ] : [] ),
			'slowest'     => self::resolve_urls( \array_slice( $top, 0, self::SLOWEST_ROWS ), $stores ),
			'estimated'   => true,
			'provisional' => $provisional,
		];
	}

	/**
	 * Fold one list's entries into the merged rows.
	 *
	 * @param array<string,array<string,mixed>> $merged    Merged display rows by hash, mutated.
	 * @param array<array-key,mixed>            $entries   One list.
	 * @param bool                              $is_recent Among the keys the recent rate sums.
	 * @return array<array-key,array<array-key,mixed>> The stored rows folded, by hash.
	 */
	private static function fold_rank_entries( array &$merged, array $entries, bool $is_recent ): array {
		$folded = [];
		foreach ( $entries as $raw ) {
			$entry = Core::arr( $raw );
			$hash  = Core::as_string( $entry[ Stats_Store::RANK_HASH ] ?? '' );
			$row   = Core::arr( $entry[ Stats_Store::RANK_ROW ] ?? null );
			if ( '' === $hash || [] === $row ) {
				continue;
			}
			$merged[ $hash ] = self::fold_index_row( $merged[ $hash ] ?? self::empty_index_row( $hash ), $row, $is_recent );
			if ( isset( $entry[ Stats_Store::RANK_PATH ] ) ) {
				$merged[ $hash ]['url'] = Core::str( $entry[ Stats_Store::RANK_PATH ] );
			}
			$folded[ $hash ] = $row;
		}
		return $folded;
	}

	/**
	 * Note each of one list's rows' two averages, its bucket's own, beside
	 * those of the other buckets its URL ranked in.
	 *
	 * @param array<string,array{0:float,1:int,2:float,3:int}> $means Per-hash `[ ms sum, ms n, peak sum, peak n ]`, mutated.
	 * @param array<array-key,array<array-key,mixed>>         $rows  One list's stored rows, by hash.
	 */
	private static function note_bucket_means( array &$means, array $rows ): void {
		foreach ( $rows as $key => $row ) {
			// An all-digit hash arrives as an int array key; cast back.
			$hash             = (string) $key;
			$means[ $hash ] ??= [ 0.0, 0, 0.0, 0 ];
			$timed            = Core::num_int( $row[ Stats_Store::ROW_TIMED_COUNT ] ?? null );
			$count            = Core::num_int( $row[ Stats_Store::ROW_COUNT ] ?? null );
			if ( $timed > 0 ) {
				$means[ $hash ][0] += Core::num_float( $row[ Stats_Store::ROW_SUM_MS ] ?? null ) / $timed;
				++$means[ $hash ][1];
			}
			if ( $count > 0 ) {
				$means[ $hash ][2] += Core::num_float( $row[ Stats_Store::ROW_SUM_PEAK_MB ] ?? null ) / $count;
				++$means[ $hash ][3];
			}
		}
	}

	/**
	 * This class's page cache, in one spelling: the substrate's read-through
	 * over `URLS_PAGE_NS`, built on a miss and warmed by the table itself.
	 *
	 * The SHAPE rides in the key, because a read-through cannot report a
	 * stored value missing a field this reader needs, and a `provisional`
	 * build, short of what the writer has yet to rank or fold, states
	 * `ttl => 0` — served, never warmed, or the gap it left would stand for
	 * the entry's whole life. With no cache backend there is no table and
	 * `$build` simply answers.
	 *
	 * Its own namespace rather than a partition's: a page folds every
	 * partition, so it belongs to none, and the install salt still scopes it.
	 *
	 * The read is the span `$span` on the request's record; the architecture
	 * guide lists what its `(complete)` says.
	 *
	 * @param string                             $span   The span the read is logged as.
	 * @param array<array-key,mixed>             $parts  What the key covers.
	 * @param list<string>                       $fields The keys the value carries.
	 * @param int                                $ttl    Entry lifetime in seconds.
	 * @param \Closure():array<array-key,mixed>  $build  Produces the value on a miss.
	 * @return mixed The stored value, or what `$build` produced.
	 */
	private static function read_through_page( string $span, array $parts, array $fields, int $ttl, \Closure $build ): mixed {
		$outcome = 'hit';
		$read    = static function () use ( $parts, $fields, $ttl, $build, &$outcome ): mixed {
			if ( null === \Newspack_Nodes\Cache_Backend::shared_first() ) {
				$outcome = 'built, not stored';
				return $build();
			}
			$parts[] = \md5( \implode( ',', $fields ) );
			return \Newspack_Nodes\Table_Node::table( self::URLS_PAGE_NS, $ttl )->backed_by(
				static function ( array $keys ) use ( $build, $ttl, &$outcome ): array {
					$value   = $build();
					$outcome = true === $value['provisional'] ? 'built, not stored' : 'built';
					return [ $keys[0] => [ 'value' => $value, 'ttl' => 'built' === $outcome ? $ttl : 0 ] ];
				}
			)->lookup( \md5( (string) \wp_json_encode( $parts ) ) );
		};
		$lm = Log_Manager::started_instance();
		return null === $lm ? $read() : $lm->timed( $span, $read, static function () use ( &$outcome ): string {
			return $outcome;
		} );
	}

	/**
	 * Sum-merge per-URL dimensional buckets for one dim/hash.
	 *
	 * @param string                 $hash      12-char URL hash.
	 * @param string                 $dimension One of URL_DIMENSIONS.
	 * @param array<int,Stats_Store> $stores    Stores the caller resolved once.
	 * @param int                    $now       The reply's clock, read once at its entry.
	 * @return array<array-key,mixed> Bucket keys derive from decoded stored values.
	 */
	private static function merge_url_dim( string $hash, string $dimension, array $stores, int $now ): array {
		return self::merged_across_stores(
			static fn ( Stats_Store $store, array $hours ): array => $store->get_slots( Stats_Store::url_dim_parts( $hash ), $hours, $dimension ),
			Stats_Store::DIM_SUMS,
			Stats_Store::DIM_COUNT,
			$stores,
			$now
		);
	}

	/**
	 * One series merged across every flame-builder partition: each store's
	 * rows summed into the buckets under one field table, the values nothing
	 * measured dropped once at the end, and the buckets sorted.
	 *
	 * @param callable(Stats_Store, array<int,string>): array<string,mixed> $rows_of     A store's slots for the series, over `chart_keys()`, keyed by bucket.
	 * @param array<int|string,bool>                                        $fields      Field table for the sum.
	 * @param int                                                           $count_field The entry index a value's request count sits at.
	 * @param array<int,Stats_Store>                                        $stores      Stores the caller resolved once.
	 * @param int                                                           $now         The reply's clock, read once at its entry.
	 * @return array<string,array<array-key,mixed>> Bucket key => value name => summed entry.
	 */
	private static function merged_across_stores( callable $rows_of, array $fields, int $count_field, array $stores, int $now ): array {
		$merged = [];
		$hours  = self::chart_keys( $now );
		foreach ( $stores as $store ) {
			foreach ( $rows_of( $store, $hours ) as $bucket => $values ) {
				$merged[ $bucket ] = Stats_Store::sum_fields( $merged[ $bucket ] ?? [], Core::arr( $values ), $fields );
			}
		}
		// The oldest hour's head and any slot past now are not drawn.
		$merged = \array_intersect_key( $merged, \array_flip( self::chart_slots( $now ) ) );
		foreach ( $merged as $bucket => $values ) {
			$merged[ $bucket ] = Stats_Store::measured( $values, $count_field );
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Walk the request partitions newest-first and list the RECENT_REQUEST_LIMIT
	 * index entries for the given url_hash that finished last, deduplicated by
	 * rid and sorted by completion DESC. Each partition's walk ends at
	 * `scan_floor()`, at its own RECENT_REQUEST_LIMIT'th entry, or at the
	 * position `$after` holds for it; the whole fan-out ends on the shared
	 * MAX_SCAN_S budget.
	 *
	 * The cap is per partition, then on the merged list, because a partition
	 * says nothing about its siblings: one URL spreads over every partition,
	 * and a cap spent on the first leaves the newest requests of the rest
	 * unread. A partition that reached its cap has older entries in the
	 * window it did not list, so the list stops short of it, and says so as a
	 * spent budget does.
	 *
	 * `$after` tails by POSITION: a partition's index is append-only, and the
	 * log position each line names grows in index order, so a line at or
	 * below the one the caller holds is one it has read. A time would be read
	 * across four indexes their workers append at different moments, and lose
	 * any request a partition indexed after a newer one landed elsewhere.
	 *
	 * Every partition walked to an end of its own answers `positions`, its
	 * newest index line's position, whether or not it held the URL, so the
	 * next refresh stops at that partition's first line. One the budget cut,
	 * or never reached, answers none, and the caller keeps the position it
	 * sent rather than skipping the lines no walk read.
	 *
	 * Both endings are the caller's to pass on. A URL whose entries sit behind
	 * ten seconds of its neighbours' is never reached, and a list that
	 * stopped short reads exactly like a URL with no traffic; a list that ran
	 * out of WINDOW reads the same way, so the floor it stopped at rides back
	 * with it.
	 *
	 * NOT server-scoped: an index entry carries no server, so filtering would
	 * mean reading every record. Free today because a stored `url` is ABSOLUTE,
	 * so one url_hash belongs to one site. If a host is ever reported by two
	 * servers, the server has to go on the index entry.
	 *
	 * @param string                                 $url_hash 12-char URL hash to match.
	 * @param int                                    $now      The reply's clock, read once at its entry.
	 * @param array<int,array{0:int,1:int}>          $after    Partition => the [segment, offset] its walk ends at, exclusive; a partition absent reads the whole window.
	 * @param ?float                                 $deadline The verb's shared `scan_deadline()`; null starts one.
	 * @return array{requests:array<int,array<string,mixed>>, truncated:bool, window_start:int, positions:array<int,array{0:int,1:int}>} The list, whether it stopped short of the window, the window it is of, and each partition's position.
	 */
	private static function find_recent_requests_for_url( string $url_hash, int $now, array $after = [], ?float $deadline = null ): array {
		$requests = [];
		$listed   = [];
		$capped   = false;
		$reached  = [];
		$floor    = self::scan_floor( $now );
		$spent    = self::scan_index_entries(
			Bootstrap::node_dirs( self::NODE_REQUESTS ),
			'requests',
			'url_hash',
			$url_hash,
			static function ( array $entry, int $partition, int $segment ) use ( &$requests, &$listed, &$capped ): ?string {
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
				$listed[ $partition ] = ( $listed[ $partition ] ?? 0 ) + 1;
				if ( $listed[ $partition ] < self::RECENT_REQUEST_LIMIT ) {
					return null;
				}
				$capped = true;
				return self::SCAN_STOP_PARTITION;
			},
			$floor,
			$deadline,
			$after,
			$reached
		);

		$finished = static fn ( array $r ): float => Core::num_float( $r['timestamp'] ) + Core::num_float( $r['duration_ms'] ) / 1000;
		\usort( $requests, static fn ( array $a, array $b ): int => $finished( $b ) <=> $finished( $a ) );
		$seen   = [];
		$unique = [];
		foreach ( $requests as $r ) {
			if ( ! isset( $seen[ $r['rid'] ] ) ) {
				$seen[ $r['rid'] ] = true;
				$unique[]          = $r;
			}
		}
		return [
			'requests'     => \array_slice( $unique, 0, self::RECENT_REQUEST_LIMIT ),
			'truncated'    => $spent || $capped,
			'window_start' => $floor,
			'positions'    => $reached,
		];
	}

	/**
	 * The `request:` brief. Picked inside the URL modal, the chain names the
	 * URL too, and the brief carries a pointer to it.
	 *
	 * @param string       $rid       Request id the descriptor names.
	 * @param int          $partition Partition its qualifier names, searched first.
	 * @param list<string> $context   Container descriptors, outermost last.
	 * @param string       $server    Server the modal is scoped to; rides the URL pointer.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When the rid resolves nowhere.
	 */
	private static function ask_request( string $rid, int $partition, array $context, string $server ): array {
		$record = self::load_request( $rid, $partition );
		return Ask_Assembler::for_request( $record, self::rule_for_record( $record ), self::descriptor_of( $context, 'url' ), $server );
	}

	/**
	 * The `span:` brief. A span is not addressable on its own — it needs the
	 * tree it sits in, which the descriptor chain supplies: the request it ran
	 * in, or the URL whose aggregate flame folds it.
	 *
	 * @param string       $name    Span name the descriptor carries.
	 * @param list<string> $context Container descriptors, outermost last.
	 * @param int          $now     The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 * @throws \RuntimeException With neither context, or an absent span.
	 */
	private function ask_span( string $name, array $context, int $now ): array {
		$record = self::request_in_context( $context );
		if ( null !== $record ) {
			$brief = Ask_Assembler::for_span( $record, $name, self::rule_for_record( $record ), self::descriptor_of( $context, 'request' ) );
			if ( null === $brief ) {
				throw new \RuntimeException( \esc_html( "no span '{$name}' in this request" ) );
			}
			return $brief;
		}
		$url = self::url_in_context( $context, $this->stats_stores( [ Stats_Store::TABLE_URL, Stats_Store::TABLE_AGGREGATE ] ) );
		if ( null === $url ) {
			throw new \RuntimeException( \esc_html( 'a span needs its request or its URL for context' ) );
		}
		if ( null === $url['aggregate'] ) {
			throw new \RuntimeException( \esc_html( "no aggregate for URL {$url['hash']} in this window" ) );
		}
		$brief = Ask_Assembler::for_url_span(
			Core::arr( $url['aggregate']['flame'] ?? null ),
			$name,
			$url['name'],
			'' === $url['name'] ? null : Rule_Set::load()->for_url( $url['name'] ),
			$url['descriptor']
		);
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no span '{$name}' in this URL's aggregate" ) );
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
	 * match the request itself made. Re-deriving it here finds nothing, because
	 * a stored `url` is absolute and query-stripped (`https://host/path`) while
	 * rules are path patterns — not even a catch-all `/` matches one, so every
	 * brief would report "no rule governs this URL" and propose creating a rule
	 * whose pattern could never match anything.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 */
	private static function rule_for_record( array $record ): ?Rule {
		$id = Findings::rule_stamp( $record );
		return '' === $id ? null : Rule_Set::load()->rule_by_id( $id );
	}

	/**
	 * The `entry:` brief.
	 *
	 * @param string       $index   Entry position within the request, as sent.
	 * @param list<string> $context Container descriptors, outermost last.
	 * @return array<string,mixed>
	 * @throws \RuntimeException With no request context, or an absent entry.
	 */
	private static function ask_entry( string $index, array $context ): array {
		$record   = self::request_from_context( $context, 'entry' );
		$position = Core::canonical_decimal( $index );
		$brief    = null === $position ? null : Ask_Assembler::for_entry( $record, $position );
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no entry {$index} in this request" ) );
		}
		return $brief;
	}

	/**
	 * The `category:` brief. A breakdown row inside a request shows THAT
	 * request's profile, and one inside the URL modal shows that URL's
	 * aggregate, so the context chain decides which board answers — the
	 * global leaderboard describes a different thing entirely.
	 *
	 * A URL with no aggregate answers from the scoped leaderboard.
	 *
	 * @param string       $name    Category name the descriptor carries.
	 * @param list<string> $context Container descriptors, outermost last.
	 * @param string       $server  Server the leaderboard fallback answers for;
	 *                              '' builds the global board.
	 * @param int          $now     The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When no board holds the category, or the name is a callback row.
	 */
	private function ask_category( string $name, array $context, string $server, int $now ): array {
		// A callback row is no board; its time counts inside its hook.
		if ( Flame_Tree::is_listener_span( $name ) ) {
			throw new \RuntimeException( \esc_html( "'{$name}' is a callback row; ask about the hook it ran under" ) );
		}
		$record = self::request_in_context( $context );
		if ( null !== $record ) {
			$brief = Ask_Assembler::for_request_category( $record, $name );
			if ( null !== $brief ) {
				return $brief;
			}
		}
		$stores = $this->stats_stores( [ Stats_Store::TABLE_URL, Stats_Store::TABLE_AGGREGATE ] );
		$url    = self::url_in_context( $context, $stores );
		if ( null !== $url && null !== $url['aggregate'] ) {
			$brief = Ask_Assembler::for_url_category( Core::arr( $url['aggregate']['profiles'] ?? null ), $name, $url['name'] );
			if ( null !== $brief ) {
				return $brief;
			}
		}
		// The card this is asked from renders the same scoped board.
		$board      = self::build_leaderboard( $server, $stores, $now );
		$categories = \is_array( $board['categories'] ?? null ) ? $board['categories'] : [];
		$brief      = Ask_Assembler::for_category( $categories, $name, $server );
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no category '{$name}' in this request or the recent window" ) );
		}
		return $brief;
	}

	/**
	 * The URL a context chain names — for a span or a category picked inside
	 * the URL modal, where the chain carries `url:<hash>` and no request — or
	 * null when it names none.
	 *
	 * Two single-key reads and no index walk: the aggregate blob, null when
	 * the URL has none, and then the name through the one name resolver, ''
	 * when the table no longer holds it. The blob lives a 24th of the window
	 * where the row lives all of it, so a URL with no blob is routine, and each
	 * caller decides what answers then; the name is read only once there is a
	 * blob to answer with. The aggregate keeps no per-server split, so no
	 * server narrows this.
	 *
	 * @param list<string>           $context Container descriptors.
	 * @param array<int,Stats_Store> $stores  Stores the caller resolved once.
	 * @return array{descriptor:string, hash:string, name:string, aggregate:?array<array-key,mixed>}|null
	 */
	private static function url_in_context( array $context, array $stores ): ?array {
		$descriptor = self::descriptor_of( $context, 'url' );
		$parsed     = Ask_Assembler::parse_descriptor( $descriptor );
		if ( null === $parsed ) {
			return null;
		}
		$aggregate = Stats_Store::url_stats( $stores, $parsed['id'] );
		return [
			'descriptor' => $descriptor,
			'hash'       => $parsed['id'],
			'name'       => null === $aggregate ? '' : Core::as_string( self::resolve_urls( [ [ 'hash' => $parsed['id'], 'url' => '' ] ], $stores )[0]['url'] ?? '' ),
			'aggregate'  => $aggregate,
		];
	}

	/**
	 * A URL aggregate rebuilt from the flames its listed requests stored, for a
	 * URL whose blob has left the store: it lives an hour past the URL's last
	 * request.
	 *
	 * Rebuilt only on a full read. A tailing read lists only the newest few, and
	 * a flame folded from them would describe those, not the URL, so it answers
	 * a null flame, which the dashboard reads as "keep the one you hold", its
	 * stamp included. A rebuilt flame's `last_modified` is the newest listed
	 * request's start, which is when the fold it describes last changed.
	 *
	 * One walk of the flame index by URL, under the verb's deadline, each hit
	 * folded as the builder folds it. A flame lands in the partition its
	 * request did, so the walk visits only the partitions its listed requests
	 * sit in and leaves each once it holds them all. A walk the deadline cuts
	 * short answers a null flame and says so, because a partial fold would
	 * read as the URL's whole flame and be kept. A stored flame has lost the
	 * suffixes that hold a request's same-named siblings apart, so those merge
	 * into one node here; and profiles live in the request records, so none
	 * are rebuilt.
	 *
	 * @param string                           $hash     12-char URL hash.
	 * @param array<int,array<string,mixed>>   $requests The requests `dump_url` lists.
	 * @param bool                             $tailing  Whether the reply tails the list; a tail rebuilds nothing.
	 * @param int                              $now      The reply's clock.
	 * @param float                            $deadline The verb's shared `scan_deadline()`.
	 * @return array{flame:?array<array-key,mixed>, profiles:null, last_modified:int, truncated:bool}
	 */
	private static function rebuilt_url_aggregate( string $hash, array $requests, bool $tailing, int $now, float $deadline ): array {
		$newest  = 0;
		$listed  = [];
		$missing = [];
		foreach ( $requests as $request ) {
			$newest = \max( $newest, Core::num_int( $request['timestamp'] ?? 0 ) );
			$listed[ Core::as_string( $request['rid'] ?? '' ) ] = $request;
			$partition             = Core::num_int( $request['partition'] ?? 0 );
			$missing[ $partition ] = ( $missing[ $partition ] ?? 0 ) + 1;
		}
		$rebuilt = [ 'flame' => null, 'profiles' => null, 'last_modified' => $newest, 'truncated' => false ];
		if ( $tailing ) {
			return $rebuilt;
		}
		$flame = Flame_Builder_Node::empty_url_flame();
		if ( [] !== $listed ) {
			$cut = self::scan_index_entries(
				\array_intersect_key( Bootstrap::node_dirs( self::NODE_FLAMES ), $missing ),
				'flames',
				'url_hash',
				$hash,
				static function ( array $entry, int $partition, int $segment, Partition_Node $node ) use ( &$listed, &$missing, &$flame, $now ): string|bool|null {
					$rid     = \trim( Core::as_string( $entry['rid'] ?? '' ) );
					$request = $listed[ $rid ] ?? null;
					if ( null === $request ) {
						return null;
					}
					$message = $node->read_message_at(
						Core::as_int( $entry['segment'] ?? 0 ),
						Core::as_int( $entry['offset'] ?? 0 ),
						Core::as_int( $entry['length'] ?? 0 )
					);
					$tree    = \is_array( $message ) ? ( $message[ Message::VALUE ] ?? null ) : null;
					if ( \is_array( $tree ) ) {
						$duration = Core::num_float( $request['duration_ms'] ?? 0 );
						$flame    = Flame_Builder_Node::fold_url_flame( $flame, $tree, $duration, Flame_Builder_Node::timing_counts( $duration, $request['error_status'] ?? '-' ), $now );
					}
					unset( $listed[ $rid ] );
					--$missing[ $partition ];
					if ( [] === $listed ) {
						return false;
					}
					return 0 >= $missing[ $partition ] ? self::SCAN_STOP_PARTITION : null;
				},
				null,
				$deadline
			);
			if ( $cut ) {
				$rebuilt['truncated'] = true;
				return $rebuilt;
			}
		}
		$rebuilt['flame'] = 0 === Core::num_int( $flame['count'] ?? 0 )
			? self::EMPTY_FLAME
			: Flame_Builder_Node::url_flame_for_display( $flame )[1];
		return $rebuilt;
	}

	/**
	 * Build the category leaderboard, global or scoped to one reporting
	 * server — one function, because the scope is the only thing separating
	 * them. It sums `chart_keys()`, the current hour's key and the 24 whole
	 * hours before it, in ONE round trip per store: an hour key is one sum,
	 * so the board cannot trim to the 288 slots the charts draw, and its own
	 * averages divide its own counts. A missing hour leaves the board short
	 * there. `overview` alone adds the board's `avg_ms` (`board_avg_ms()`),
	 * so an ask, which reads the categories alone, spends no read on it.
	 *
	 * @param string                 $server Server to scope to; '' builds the global board.
	 * @param array<int,Stats_Store> $stores Stores the caller resolved once.
	 * @param int                    $now    The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 */
	private static function build_leaderboard( string $server, array $stores, int $now ): array {
		$count        = 0;
		$sum_req_time = 0.0;
		$sums         = [];
		$fold         = static function ( array $rows ) use ( &$count, &$sum_req_time, &$sums ): void {
			foreach ( $rows as $row ) {
				if ( ! \is_array( $row ) ) {
					continue;
				}
				$count        += Core::num_int( $row['count'] ?? 0 );
				$sum_req_time += Core::num_float( $row['sum_req_time'] ?? 0 );
				$sums          = Stats_Store::sum_fields( $sums, Core::arr( $row['categories'] ?? null ), Stats_Store::LB_CAT_SUMS );
			}
		};
		$hours = self::chart_keys( $now );
		foreach ( $stores as $store ) {
			$fold( $store->get_leaderboard_hours( $hours, $server ) );
		}
		return Stats_Store::sums_to_display( $count, $sum_req_time, $sums );
	}

	/**
	 * The mean duration of the timed requests over a board's hour keys,
	 * every slot of them, the wall clock the Time Breakdown divides the
	 * board's categories by: the site's from the `hourly_h` slots `overview`
	 * already read, and a server's from its row of the `server` dimension,
	 * the one read this costs. Null where no timed request reached the board.
	 *
	 * @param string                               $server Server the board is scoped to; '' is the site.
	 * @param array<string,array<array-key,mixed>> $hourly `hourly_slots()`, the site's.
	 * @param array<int,Stats_Store>               $stores Stores the caller resolved once.
	 * @param int                                  $now    The reply's clock, read once at its entry.
	 */
	private static function board_avg_ms( string $server, array $hourly, array $stores, int $now ): ?float {
		$timed  = 0;
		$sum_ms = 0.0;
		if ( '' === $server ) {
			foreach ( $hourly as $slot ) {
				$timed  += Core::num_int( $slot['count'] ?? null );
				$sum_ms += Core::num_float( $slot['sum_ms'] ?? null );
			}
			return Stats_Store::mean( $sum_ms, $timed );
		}
		$hours = self::chart_keys( $now );
		foreach ( $stores as $store ) {
			foreach ( $store->get_slots( Stats_Store::dim_parts( Stats_Store::DIM_SERVER, '' ), $hours ) as $slot ) {
				$row     = Core::arr( $slot[ $server ] ?? null );
				$timed  += Core::num_int( $row[ Stats_Store::DIM_TIMED ] ?? null );
				$sum_ms += Core::num_float( $row[ Stats_Store::DIM_SUM_MS ] ?? null );
			}
		}
		return Stats_Store::mean( $sum_ms, $timed );
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
	 * @param int $now The reply's clock, read once at its entry.
	 * @return int Unix timestamp.
	 */
	private static function scan_floor( int $now ): int {
		return Stats_Store::window_start( AppConfig::stats_retention_seconds(), $now );
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
				return self::load_request( $parsed['id'], self::descriptor_partition( $parsed ) );
			}
		}
		return null;
	}

	/**
	 * The partition a `request:` descriptor names, read through the
	 * substrate's refusing decimal parse: a cast would read `abc` as p0 and
	 * `-3` as p-3, and the record would be found under a partition nobody
	 * named.
	 *
	 * @param array{type:string,id:string,qualifier:string} $parsed What `Ask_Assembler::parse_descriptor()` returned.
	 * @throws \RuntimeException When the qualifier is no canonical non-negative decimal.
	 */
	private static function descriptor_partition( array $parsed ): int {
		$partition = Core::canonical_decimal( $parsed['qualifier'] );
		if ( null === $partition ) {
			throw new \RuntimeException( \esc_html( "invalid partition in request:{$parsed['id']}:{$parsed['qualifier']}" ) );
		}
		return $partition;
	}

	/**
	 * The descriptor of a given type in a context chain, verbatim. A brief's
	 * `fetch` pointer has to name what an agent would ASK, which is the
	 * descriptor as the picker wrote it — not the record it resolves to.
	 *
	 * @param list<string> $context Container descriptors.
	 * @param string       $type    Descriptor type to look for.
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
	 * @param string $rid       Request id to load.
	 * @param int    $partition Caller's hint, searched ahead of the hashed order.
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
		$deadline = self::scan_deadline();
		$found    = self::first_record( $dirs, 'requests', $rid, $stopped, $deadline );
		if ( null === $found ) {
			if ( $stopped ) {
				self::fail_budget_spent( $rid );
			}
			return null;
		}
		[ $entry, $record ] = $found;
		$record['url_hash'] = \trim( Core::as_string( $entry['url_hash'] ?? '' ) );
		// A flame miss is normal, budget or not: unprofiled requests have none.
		$flame              = self::first_record( Bootstrap::node_dirs( self::NODE_FLAMES ), 'flames', $rid, deadline: $deadline );
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
	 * @param bool|null         $stopped  Set true when the budget ended the walk.
	 * @param float|null        $deadline Shared with an earlier walk; null starts one.
	 * @param-out bool          $stopped
	 * @return array{0:array<array-key,mixed>,1:array<array-key,mixed>}|null Entry + decoded record.
	 */
	private static function first_record( array $dirs, string $log, string $rid, ?bool &$stopped = null, ?float $deadline = null ): ?array {
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
			},
			null,
			$deadline
		);
		return $found;
	}

	/**
	 * Fan a bounded index scan across a set of partition dirs, newest entry
	 * first, handing every entry whose `$field` equals `$match` to `$on_hit`.
	 *
	 * The one boundary MAX_SCAN_S lives at: the budget spans the whole
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
	 * @param int|null          $floor    Stop a closed segment below this completion time; null walks to the budget.
	 * @param float|null        $deadline A verb's shared `scan_deadline()`; null starts one.
	 * @param array<int,array{0:int,1:int}> $after   Partition => the [segment, offset] its walk stops at, inclusive of every line at or below it; a request index only.
	 * @param array<int,array{0:int,1:int}>|null $reached Set to partition => its newest line's [segment, offset], for each partition walked to an end of its own.
	 * @param-out array<int,array{0:int,1:int}> $reached
	 * @return bool True when the time budget ended the scan.
	 */
	private static function scan_index_entries( array $dirs, string $log, string $field, string $match, callable $on_hit, ?int $floor = null, ?float $deadline = null, array $after = [], ?array &$reached = null ): bool {
		// Both halves of ONE format: never read an index we didn't write.
		[ $formatter, $parse, $column, $times, $places ] = 'flames' === $log
			? [ 'flame-index', Flame_Builder_Node::parse_flame_index( ... ), Flame_Builder_Node::index_column( $field ), Flame_Builder_Node::index_completion_columns(), [] ]
			: [ 'request-index', Request_Builder_Node::parse_request_index( ... ), Request_Builder_Node::index_column( $field ), Request_Builder_Node::index_completion_columns(), Request_Builder_Node::index_position_columns() ];
		// Past the columns' last byte: a short line is skipped, not read as 0.
		$span_end      = [] === $times ? 0 : \max( $times[0][0] + $times[0][1], $times[1][0] + $times[1][1] );
		$place_end     = [] === $places ? 0 : $places[1][0] + $places[1][1];
		$reached       = [];
		$clock     = self::scan_clock();
		$deadline ??= self::scan_deadline();
		$spent     = false;
		$lines     = 0;
		foreach ( $dirs as $p => $dir ) {
			$stopped = false;
			$head    = null;
			$bound   = $after[ $p ] ?? null;
			$node    = new Partition_Node();
			self::name_scratch_partition( $node, $log, $p );
			$node->arguments( [ $dir ] );
			// Read-only; the NAME still proves its writer is in the graph.
			if ( null === \Newspack_Nodes\Formatters::resolve( $formatter ) ) {
				$node->remove_node();
				throw new \RuntimeException( \esc_html( "index formatter not registered: {$formatter}" ) );
			}
			$closed = null === $floor || [] === $times ? [] : self::segments_closed_before( $node, $floor );
			$node->scan_index(
				static function ( string $line, int $segment ) use ( &$spent, &$stopped, &$lines, &$head, $clock, $deadline, $node, $p, $field, $match, $column, $times, $span_end, $places, $place_end, $bound, $closed, $floor, $parse, $on_hit ): ?bool {
					if ( 0 === ++$lines % self::SCAN_CLOCK_STRIDE && $clock() > $deadline ) {
						$spent   = true;
						$stopped = true;
						return false;
					}
					// At or below the caller's position, it has read the rest.
					if ( [] !== $places && \strlen( $line ) >= $place_end ) {
						$at     = [ (int) \substr( $line, $places[0][0], $places[0][1] ), (int) \substr( $line, $places[1][0], $places[1][1] ) ];
						$head ??= $at;
						if ( null !== $bound && $at <= $bound ) {
							return false;
						}
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
				return $spent;
			}
			if ( null !== $head ) {
				$reached[ $p ] = $head;
			}
		}
		return false;
	}

	/**
	 * The moment a walk starting now must stop by. A verb that walks twice
	 * takes one and hands it to both, so it spends MAX_SCAN_S once.
	 */
	private static function scan_deadline(): float {
		return ( self::scan_clock() )() + self::MAX_SCAN_S;
	}

	/**
	 * The clock the index walks are timed by: the substrate's `Core::$clock`
	 * seam when a test pins it, else the monotonic `hrtime()`, so a wall-clock
	 * step can neither end a walk early nor stretch it. The seam is read
	 * directly because `Core::right_now()` would move the reply's tick
	 * (decision 29). The deadline and every reading come from this one clock.
	 *
	 * @return \Closure(): float
	 */
	private static function scan_clock(): \Closure {
		return Core::$clock ?? static fn (): float => \hrtime( true ) / 1e9;
	}

	/**
	 * Prepare a transient scratch Partition: a name unique per scan
	 * (`{log}.{token}.p{N}`) so a live worker's registry cannot collide with it,
	 * itself as its own patron, and — per Rule 4 — the in-scope
	 * CommandInterpreter as its sink. Callers `remove_node()` it after use.
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
	 * An unscoped read asks `urlmap` which server the hash is filed under and
	 * reads that server's keys alone, falling back to every server's when the
	 * name has expired or its server holds no row.
	 *
	 * @param string                 $hash   12-char URL hash.
	 * @param string                 $server Reporting server to scope to; '' reads every server.
	 * @param array<int,Stats_Store> $stores Stores the caller resolved once.
	 * @param int                    $now    The reply's clock, read once at its entry.
	 * @return array<array-key,mixed>|null
	 */
	private function row( string $hash, string $server, array $stores, int $now ): ?array {
		$name = self::url_names( [ $hash ], $stores )[ $hash ] ?? null;
		$raw  = null;
		if ( '' === $server && null !== $name ) {
			$raw = self::load_row( $hash, $name['server'], $stores, $now );
		}
		$raw ??= self::load_row( $hash, $server, $stores, $now );
		if ( null === $raw ) {
			return null;
		}
		if ( null !== $name ) {
			$raw['url'] = $name['url'];
		}
		return self::project_row( $raw );
	}

	/**
	 * One URL's merged row, read BY KEY (`candidate_rows()`): its
	 * `url_row_h` value in each planned hour its server's index names,
	 * never the shard holding every URL of its digit beside it.
	 *
	 * The reader population answers first and the worker one only when it has
	 * nothing: a URL served both ways shows the row the default table showed,
	 * and a job-only URL still opens.
	 *
	 * @param string                 $hash   12-char URL hash.
	 * @param string                 $server Reporting server; '' reads every server the index names.
	 * @param array<int,Stats_Store> $stores Stores the caller resolved once.
	 * @param int                    $now    The reply's clock, read once at its entry.
	 * @return array<array-key,mixed>|null The merged row, or null when absent.
	 */
	public static function load_row( string $hash, string $server, array $stores, int $now ): ?array {
		$rows = self::candidate_rows( [ $hash => '' === $server ? null : [ Stats_Store::server_key( $server ) ] ], true, false, $stores, $now );
		return $rows[0][ $hash ] ?? $rows[1][ $hash ] ?? null;
	}

	/**
	 * One Stats_Store per flame-builder partition, over the stats Tables this
	 * verb reads, mounted into the request graph — read-only, in-process, one
	 * catalog read for all of them. A mount lives for the rest of the request,
	 * so a later verb in the same POST reads through it rather than mounting
	 * again (substrate ADR-23). A store asks only the Tables named here, and
	 * a partition whose worker has written no file yet reads as empty.
	 *
	 * A Table whose backend cannot open here (`Table_Unavailable`) reads as
	 * none — a missing pdo_sqlite, or a path SQLite cannot open: the list
	 * comes back empty, and every stats reader degrades to an empty or zeroed
	 * shape instead of throwing (decision 3). Any other refusal is the
	 * operator's to fix, and fails the verb: a mount in a process running as
	 * root, a Table two topologies declare differently, a TTL that is not
	 * one, a declaration its arm refuses, a topology that will not read, or a
	 * tables directory the runtime refuses to adopt — a symlink, another
	 * owner, group- or world-writable. Each store's read window comes from
	 * the substrate `min_lifetime` key.
	 *
	 * @param list<string> $tables `Stats_Store::TABLE_*` names the verb reads.
	 * @return array<int,Stats_Store>
	 * @throws \Throwable Every refusal but a backend that cannot open.
	 */
	private function stats_stores( array $tables ): array {
		try {
			$stems = Bootstrap::mount_table( $tables );
		} catch ( Table_Unavailable $e ) {
			Core::print_less_often( 'performance: stats Tables did not mount', ' — ' . $e->getMessage() );
			return [];
		}
		$names = [];
		foreach ( $stems as $table => $partitions ) {
			foreach ( $partitions as $p => $stem ) {
				$names[ $p ][ $table ] = $stem;
			}
		}
		$this->client ??= new Table_Client( $this );
		$max_lifespan   = AppConfig::stats_retention_seconds();
		$stores         = [];
		foreach ( $names as $p => $by_table ) {
			$store = new Stats_Store( $max_lifespan, $this->client, $by_table );
			// One reply's stores: every shard asks the same buckets' index.
			$store->server_indexes = [];
			$store->done_markers   = [];
			$stores[]              = $store;
		}
		return $stores;
	}

	/**
	 * One page of the URL set, walked from the raw index inside the `url fold`
	 * span; `walk_url_index()` states the walk.
	 *
	 * @param string                 $server  Reporting server to scope to; '' reads every server.
	 * @param string                 $search  Case-insensitive whole URL words; '' matches all.
	 * @param bool                   $errors  Keep only rows with unclassified requests.
	 * @param bool                   $workers Keep worker traffic (the default excludes it).
	 * @param string                 $sort    A URL_SORTS field.
	 * @param string                 $order   'asc' or 'desc'.
	 * @param int                    $offset  Page offset.
	 * @param int                    $limit   Page size.
	 * @param array<int,Stats_Store> $stores  Stores the caller resolved once.
	 * @param int                    $now     The reply's clock, read once at its entry.
	 * @return array{data:array<int,array<array-key,mixed>>,rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,estimated:bool,provisional:bool,ranked:bool,as_of:int}
	 */
	private function fold_page( string $server, string $search, bool $errors, bool $workers, string $sort, string $order, int $offset, int $limit, array $stores, int $now ): array {
		$walk = fn (): array => $this->walk_url_index( $server, $search, $errors, $workers, $sort, $order, $offset, $limit, $stores, $now );
		$lm   = Log_Manager::started_instance();
		return null === $lm ? $walk() : $lm->timed( Flame_Tree::URL_FOLD, $walk, static fn ( array $page ): int => $page['rows'] );
	}

	/**
	 * One page of the URL set, walked from the raw index: its totals, its
	 * slowest, and one page of it.
	 *
	 * A search walks its candidates' rows, read by key (`candidate_rows()`),
	 * as one group, and reads no shard. The rest is the name scan and the
	 * pages the lists cannot answer, which walk the whole index,
	 * ONE SHARD AT A TIME. A url_hash's shard is its first hex digit, so
	 * shards are disjoint and a shard's fold is complete for every URL it
	 * holds — there is no cross-shard merge to miss. The whole merged index is
	 * otherwise the count of distinct URLs across the retention window, which
	 * nothing bounds: each stored bucket fits one cache item, the MERGE of
	 * them does not, and folding all sixteen at once exhausts a production
	 * hub's 512MB inside the fold itself. A server scope reads
	 * that server's keys alone; no scope reads every server the index names.
	 *
	 * The union of the per-shard top-N is exactly the global top-N, because
	 * every row belongs to exactly one shard — so keeping each shard's best
	 * `$offset + $limit` by the sort key, and its best `SLOWEST_ROWS` by
	 * `avg_ms`, loses nothing. `rows` and `totals` accumulate across shards and
	 * stay site-wide (decision 15).
	 *
	 * @param string                 $server  Reporting server to scope to; '' reads every server.
	 * @param string                 $search  Case-insensitive whole URL words; '' matches all.
	 * @param bool                   $errors  Keep only rows with unclassified requests.
	 * @param bool                   $workers Keep worker traffic (the default excludes it).
	 * @param string                 $sort    A URL_SORTS field.
	 * @param string                 $order   'asc' or 'desc'.
	 * @param int                    $offset  Page offset.
	 * @param int                    $limit   Page size.
	 * @param array<int,Stats_Store> $stores  Stores the caller resolved once.
	 * @param int                    $now     The reply's clock, read once at its entry.
	 * @return array{data:array<int,array<array-key,mixed>>,rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,estimated:bool,provisional:bool,ranked:bool,as_of:int}
	 */
	private function walk_url_index( string $server, string $search, bool $errors, bool $workers, string $sort, string $order, int $offset, int $limit, array $stores, int $now ): array {
		// `$search` is normalized by `url_page()`, which keys its cache on it.
		$page_keep = \max( 0, $offset ) + \max( 0, $limit );
		$ranked    = [];
		$slowest   = [];
		$rows      = 0;
		$urls      = 0;
		$requests  = 0;
		$errored   = 0;
		$timed     = 0;
		$recent    = 0;
		$sum_ms    = 0.0;
		$sum_peak  = 0.0;

		// Under the filter a count ranks the errors, not the traffic.
		$by_sort = self::by_sort( Stats_Store::rank_key( $sort, $errors ), $order );
		$by_mean = self::by_sort( ...Stats_Store::SLOWEST_LIST );

		$plan       = self::read_plan( $now );
		$tokens     = '' === $search ? [] : Stats_Store::term_tokens( $search );
		$unread     = false;
		$candidates = '' === $search
			? null
			: self::search_candidates( $search, $tokens, $server, $stores, $plan, $now, $unread ) ?? ( self::$match_names ? null : [] );

		// A term's candidates are read by key; the name scan walks the shards.
		$groups = null === $candidates
			? self::shard_groups( $server, $workers, $errors, $stores, $now )
			: [ \array_merge( ...\array_map( 'array_values', self::candidate_rows( $candidates, $workers, $errors, $stores, $now ) ) ) ];

		$overflow = [];
		foreach ( $groups as $group ) {
			$kept = [];
			foreach ( $group as $raw ) {
				$raw_row = Core::arr( $raw );
				// @longform Every shard's overflow row shares ONE key, so a
				// per-shard fold must collapse all sixteen deliberately —
				// decision 17 says a merge on the url_hash collapses sixteen
				// into one. Held raw and projected once below, so the means
				// divide a whole row.
				$hash = Core::as_string( $raw_row['hash'] ?? '' );
				if ( Stats_Store::is_other_key( $hash ) ) {
					$overflow[ $hash ] = isset( $overflow[ $hash ] )
						? self::merge_overflow_rows( $overflow[ $hash ], $raw_row )
						: $raw_row;
					continue;
				}
				// Each candidate carries every word, or its cut path hides it.
				if ( '' !== $search ) {
					$path = Stats_Store::row_search_path( Core::as_string( $raw_row['url'] ?? '' ) );
					$cut  = null !== $candidates && \str_ends_with( $path, '…' );
					if ( ! $cut && ! Stats_Store::term_matches( $path, $search, $tokens ) ) {
						continue;
					}
				}
				$row       = self::project_row( $raw_row );
				$aggregate = ! empty( $row['aggregate'] );
				++$rows;
				// The overflow row stands for many URLs; not one of them.
				$urls     += $aggregate ? 0 : 1;
				$requests += Core::num_int( $row['count'] ?? null );
				$errored  += Core::num_int( $row['errors'] ?? null );
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
			$row = self::project_row( $raw_row );
			// Not one of `totals.urls`, but its requests are real.
			if ( '' !== $search ) {
				continue;
			}
			++$rows;
			$requests += Core::num_int( $row['count'] ?? null );
			$errored  += Core::num_int( $row['errors'] ?? null );
			$recent   += Core::num_int( $row['recent_count'] ?? null );
			$timed    += Core::num_int( $row['timed_count'] ?? null );
			$sum_ms   += Core::num_float( $row['sum_ms'] ?? null );
			$sum_peak += Core::num_float( $row['sum_peak_mb'] ?? null );
			$ranked[]  = $row;
			$slowest[] = $row;
		}

		\usort( $slowest, $by_mean );
		\usort( $ranked, $by_sort );

		// The page and its slowest rows are named together, once.
		$page  = \array_slice( $ranked, $offset, $limit );
		$top   = \array_slice( $slowest, 0, self::SLOWEST_ROWS );
		$named = self::resolve_urls( \array_merge( $page, $top ), $stores );
		return [
			'data'    => \array_slice( $named, 0, \count( $page ) ),
			// The pager's question; `totals.urls` is another.
			'rows'    => $rows,
			'totals'  => [
				'urls'                => $urls,
				'requests'            => $requests,
				'avg_ms'              => Stats_Store::mean( $sum_ms, $timed ),
				'avg_peak_mb'         => Stats_Store::mean( $sum_peak, $requests ),
				'requests_per_second' => self::recent_rate( $recent, self::recent_keys( $plan ) ),
			] + ( $errors ? [ 'errors' => $errored ] : [] ),
			'slowest'     => \array_slice( $named, \count( $page ) ),
			'estimated'   => false,
			'provisional' => $unread || self::unfolded( $plan, $stores ),
			'ranked'      => false,
			'as_of'       => $now,
		];
	}

	/**
	 * Named URLs' merged rows, read BY KEY: each hash's `url_row_h` value
	 * under each of its servers, for every key of the read plan whose
	 * server index names that server with the hash's shard, in one exchange
	 * per store. Each is folded through `fold_index_row()` in the order the
	 * shard walk folds — the plan's folded hours, each the sum of its slots
	 * as the hour fold sums its buckets, then the current hour's buckets
	 * slot by slot — so a row read here is the row the walk shows, the
	 * errored filter and the recent rate included. An hour a store holds no
	 * index for is unfolded, and neither reader reads it.
	 *
	 * Exact while the shards' byte cap keeps the URL: a row that cap folds
	 * into `Other` there is whole here.
	 *
	 * @param array<array-key,list<string>|null> $candidates hash => the server keys holding
	 *                                                       it; null reads every one the index names.
	 * @param bool                               $workers    Read the worker family beside the reader's.
	 * @param bool                               $errored    Fold each key's errored rows alone.
	 * @param array<int,Stats_Store>             $stores     Stores the caller resolved once.
	 * @param int                                $now        The reply's clock, read once at its entry.
	 * @return array<int,array<string,array<string,mixed>>> Family, 0 the reader's and 1 the
	 *                                                      worker's => hash => merged row.
	 */
	private static function candidate_rows( array $candidates, bool $workers, bool $errored, array $stores, int $now ): array {
		$plan     = self::read_plan( $now );
		$recent   = self::recent_keys( $plan );
		$families = Stats_Store::families( $workers );
		$rows     = [];
		foreach ( $stores as $store ) {
			$index = $store->server_index( $plan['hours'], $plan['fine'] );
			$held  = [];
			$reads = [];
			foreach ( $candidates as $hash => $keys ) {
				$hash = (string) $hash;
				$bits = Stats_Store::shard_mask( \array_map( static fn ( bool $worker ): string => Stats_Store::url_shard( $hash, $worker ), $families ) );
				foreach ( $index as $at => $entries ) {
					$hour = Stats_Store::hour_of( $at );
					foreach ( null === $keys ? $entries : \array_intersect_key( $entries, \array_flip( $keys ) ) as $key => [ Stats_Store::SRV_SHARDS => $mask ] ) {
						if ( 0 !== ( $mask & $bits ) ) {
							$held[ $hash ][ $at ][]            = [ $key, $mask ];
							$reads[ "{$hour} {$key} {$hash}" ] = [ Stats_Store::url_row_parts( $key, $hash ), $hour ];
						}
					}
				}
			}
			$values = $store->bucket_get_multi( $reads );
			foreach ( $held as $hash => $by_key ) {
				foreach ( $families as $worker ) {
					$bit    = Stats_Store::shard_mask( [ Stats_Store::url_shard( $hash, $worker ) ] );
					$family = Stats_Store::url_row_family( $worker );
					foreach ( $by_key as $at => $servers ) {
						$hour = Stats_Store::hour_of( $at );
						foreach ( $servers as [ $key, $mask ] ) {
							$value = Core::arr( 0 === ( $mask & $bit ) ? null : $values[ "{$hour} {$key} {$hash}" ] ?? null );
							$slots = Core::arr( $value[ $family ] ?? null );
							$row   = $at === $hour ? self::hour_row( $slots ) : Core::arr( $slots[ Stats_Store::slot_of( $at ) ] ?? null );
							if ( [] === $row || ( $errored && Stats_Store::row_errors( $row ) <= 0 ) ) {
								continue;
							}
							$row[ Stats_Store::ROW_PATH ] = Core::str( $value[ Stats_Store::URL_ROW_PATH ] ?? '' );
							$rows[ (int) $worker ][ $hash ] = self::fold_index_row( $rows[ (int) $worker ][ $hash ] ?? self::empty_index_row( $hash ), $row, isset( $recent[ $at ] ) );
						}
					}
				}
			}
		}
		foreach ( $rows as &$family_rows ) {
			foreach ( $family_rows as &$entry ) {
				// Null when nothing timed folded a min.
				$entry['min_ms'] = Core::as_float( $entry['min_ms'] );
			}
			unset( $entry );
		}
		unset( $family_rows );
		return $rows;
	}

	/**
	 * A folded hour's row out of its slots: summed in slot order by
	 * `Stats_Store::merge_url_row()`, as the hour fold sums its buckets. A
	 * lone slot is the hour's row as it stands.
	 *
	 * @param array<array-key,mixed> $slots One family's slots.
	 * @return array<array-key,mixed> The row; empty where no slot holds one.
	 */
	private static function hour_row( array $slots ): array {
		if ( 1 === \count( $slots ) ) {
			return Core::arr( \reset( $slots ) );
		}
		\ksort( $slots );
		$row = [];
		foreach ( $slots as $slot ) {
			if ( \is_array( $slot ) ) {
				$row = Stats_Store::merge_url_row( $row, $slot );
			}
		}
		return $row;
	}

	/**
	 * The whole index, one shard at a time, both families' where the page
	 * asks for workers: what the fold walks when it names no candidates.
	 *
	 * @param string                 $server  Reporting server; '' reads every server.
	 * @param bool                   $workers Read the worker family's shards too.
	 * @param bool                   $errored Fold each key's errored rows alone.
	 * @param array<int,Stats_Store> $stores  Stores the caller resolved once.
	 * @param int                    $now     The reply's clock, read once at its entry.
	 * @return \Generator<int,array<int,array<array-key,mixed>>>
	 */
	private static function shard_groups( string $server, bool $workers, bool $errored, array $stores, int $now ): \Generator {
		foreach ( Stats_Store::families( $workers ) as $worker ) {
			foreach ( Stats_Store::url_shards( $worker ) as $shard ) {
				yield self::read_index( $shard, $server, $stores, $now, $errored );
			}
		}
	}

	/**
	 * The hashes the token index names for a term, each with the keys of the
	 * servers whose sets named it, or null when it cannot answer: a term
	 * with no token, or a read left unanswered. A term too common to narrow
	 * — every word read over `URL_SEARCH_MAX`, or more candidates than that
	 * across every server — is REFUSED, because its candidates' rows are
	 * read by key and that many is a slow read (decision 28); while
	 * `$match_names` is on it is null instead, the name fold. The caller
	 * reads null as that fold, or as no rows while the switch is off.
	 * Which tokens can be answered is the store's to say — `false` is one
	 * no read can answer, and one token unanswerable among several narrows
	 * nothing while the rest still do, because every candidate's row is
	 * checked against the whole term anyway. A token no partition holds is
	 * a real answer — an empty set — and reads nothing. The term is read in
	 * `Stats_Store::search_groups()`, each group from every store, and the
	 * next group only while every token of the last is unanswerable
	 * somewhere: whether a group narrows is the site's answer, so a word one
	 * store holds over the limit reads the next group in every store.
	 *
	 * Each server files its own sets, so a scope reads its server's and the
	 * site reads those of every server its index across the plan names.
	 *
	 * @param string                                         $search The normalized term.
	 * @param list<string>                                   $tokens The term's tokens.
	 * @param string                                         $server Reporting server; '' is the site.
	 * @param array<int,Stats_Store>                         $stores Stores the caller resolved once.
	 * @param array{fine: list<string>, hours: list<string>} $plan   The reply's read plan.
	 * @param int                                            $now    The reply's tick, which dates the token buckets.
	 * @param-out bool                                       $unread
	 * @param ?bool                                          $unread Set true when a store left a set unanswered.
	 * @return array<string,list<string>>|null hash => server keys.
	 * @throws \RuntimeException On a term too common to read by key.
	 */
	private static function search_candidates( string $search, array $tokens, string $server, array $stores, array $plan, int $now, ?bool &$unread = null ): ?array {
		$unread = false;
		if ( [] === $tokens ) {
			return null;
		}
		$servers_of = [];
		foreach ( $stores as $at => $store ) {
			$servers_of[ $at ] = '' === $server ? self::indexed_servers( $store, $plan ) : [ $server ];
		}
		$sets  = [];
		$read  = [];
		$named = [];
		foreach ( Stats_Store::search_groups( $tokens ) as $group ) {
			foreach ( $stores as $at => $store ) {
				foreach ( $store->url_token_sets( $group, $servers_of[ $at ], $now, $failed, $named_by ) as $token => $hashes ) {
					// One partition's set unanswerable is the token's answer.
					if ( false === $hashes || false === ( $sets[ $token ] ?? null ) ) {
						$sets[ $token ] = false;
						continue;
					}
					$sets[ $token ] = ( $sets[ $token ] ?? [] ) + \array_fill_keys( $hashes, true );
				}
				foreach ( $named_by as $hash => $by ) {
					$named[ $hash ] = ( $named[ $hash ] ?? [] ) + $by;
				}
				$unread = $unread || $failed;
			}
			$read = [ ...$read, ...$group ];
			// The next group is read only while this one narrows nowhere.
			if ( $unread || [] !== \array_filter( $group, static fn ( string $token ): bool => false !== ( $sets[ $token ] ?? null ) ) ) {
				break;
			}
		}
		$usable = [];
		foreach ( $read as $token ) {
			if ( false !== ( $sets[ $token ] ?? null ) ) {
				$usable[ $token ] = Core::arr( $sets[ $token ] ?? [] );
			}
		}
		if ( $unread ) {
			return null;
		}
		// Smallest first: every intersection after it walks the smallest side.
		\uasort( $usable, static fn ( array $a, array $b ): int => \count( $a ) <=> \count( $b ) );
		$result = null;
		foreach ( $usable as $set ) {
			$result = null === $result ? $set : \array_intersect_key( $result, $set );
		}
		$candidates = [];
		$pairs      = 0;
		foreach ( \array_keys( $result ?? [] ) as $hash ) {
			$candidates[ (string) $hash ] = \array_map( Stats_Store::server_key( ... ), \array_map( 'strval', \array_keys( $named[ (string) $hash ] ?? [] ) ) );
			$pairs                       += \count( $candidates[ (string) $hash ] );
		}
		if ( null === $result || $pairs > Stats_Store::URL_SEARCH_MAX ) {
			return self::$match_names ? null : throw new \RuntimeException(
				\esc_html( \sprintf( 'search "%s" is too common: its URLs run past the %d a search reads; add a word', $search, Stats_Store::URL_SEARCH_MAX ) )
			);
		}
		return $candidates;
	}

	/**
	 * Every server a store's index names across the read plan.
	 *
	 * @param Stats_Store                                    $store A store of the reply.
	 * @param array{fine: list<string>, hours: list<string>} $plan  The reply's read plan.
	 * @return list<string>
	 */
	private static function indexed_servers( Stats_Store $store, array $plan ): array {
		return \array_values( Stats_Store::index_names( \array_replace( [], ...\array_values( $store->server_index( $plan['hours'], $plan['fine'] ) ) ) ) );
	}

	/**
	 * The `urls` sort comparator for one `URL_SORTS` field and direction —
	 * shared by the fold and the ranked reader. A row no timed request
	 * reached ranks last on the `Stats_Store::TIMED_SORTS` in both orders,
	 * as the writer's lists rank it. A tie breaks by hash, ascending either
	 * way, as the writer's lists break it, so the two paths agree on ties.
	 *
	 * @param string $sort  A URL_SORTS field.
	 * @param string $order 'asc' or 'desc'.
	 */
	private static function by_sort( string $sort, string $order ): \Closure {
		$by_value = static fn ( array $a, array $b ): int => ( 'asc' === $order
			? ( $a[ $sort ] ?? 0 ) <=> ( $b[ $sort ] ?? 0 )
			: ( $b[ $sort ] ?? 0 ) <=> ( $a[ $sort ] ?? 0 ) )
			?: \strcmp( Core::as_string( $a['hash'] ?? '' ), Core::as_string( $b['hash'] ?? '' ) );
		if ( ! \in_array( $sort, Stats_Store::TIMED_SORTS, true ) ) {
			return $by_value;
		}
		// A display row's `timed_count` is an int: no coercion per compare.
		return static fn ( array $a, array $b ): int => ( ( $b['timed_count'] ?? 0 ) > 0 ) <=> ( ( $a['timed_count'] ?? 0 ) > 0 )
			?: $by_value( $a, $b );
	}

	/**
	 * Merge one shard's overflow row into another's.
	 *
	 * The same accumulation `fold_index_row()` performs, over two MERGED rows
	 * rather than a merged row and a stored one: sums add, extremes take the
	 * extreme, and `last_updated` takes the later. Only the overflow rows need
	 * this — every other hash lives in one shard.
	 *
	 * @param array<array-key,mixed> $into A merged overflow row.
	 * @param array<array-key,mixed> $from Another shard's, same key.
	 * @return array<array-key,mixed>
	 */
	private static function merge_overflow_rows( array $into, array $from ): array {
		$into                 = self::add_row_sums( $into, $from, false );
		$into['recent_count'] = Core::num_int( $into['recent_count'] ?? null ) + Core::num_int( $from['recent_count'] ?? null );
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
		return $into;
	}

	/**
	 * Requests per second over the recent keys.
	 *
	 * The divisor is the WINDOW, not the keys that carried traffic: dividing
	 * by the buckets a URL appeared in makes one seen in two of twelve read six
	 * times its rate.
	 *
	 * @param int               $requests Requests counted across `$recent`.
	 * @param array<string,int> $recent   `recent_keys()`.
	 */
	private static function recent_rate( int $requests, array $recent ): float {
		return $requests / \max( 1, \array_sum( $recent ) );
	}

	/**
	 * Fill in each row's URL from the name table.
	 *
	 * A stored row carries the path its server's key does not imply, so the
	 * whole URL is read for the rows a response actually SHOWS — one
	 * `MGET` per partition. Every displayed row is named here,
	 * including one carrying the PATH it was folded or ranked by; only the
	 * synthetic overflow rows are skipped, and they name no URL to look up.
	 *
	 * Naming runs on a budget of its own — see `url_names()` — and the `urls`
	 * verb names its page and its slowest rows in ONE call rather than two.
	 *
	 * @param array<int,array<array-key,mixed>> $rows   Merged display rows.
	 * @param array<int,Stats_Store>            $stores Stores the caller resolved once.
	 * @return array<int,array<array-key,mixed>>
	 */
	private static function resolve_urls( array $rows, array $stores ): array {
		$wanted = [];
		foreach ( $rows as $row ) {
			$hash = Core::as_string( $row['hash'] ?? '' );
			// The overflow rows name no URL, so nothing can name them.
			if ( '' !== $hash && ! Stats_Store::is_other_key( $hash ) ) {
				$wanted[ $hash ] = true;
			}
		}
		if ( [] === $wanted ) {
			return $rows;
		}
		$names = self::url_names( \array_keys( $wanted ), $stores );
		foreach ( $rows as $i => $row ) {
			$hash = Core::as_string( $row['hash'] ?? '' );
			if ( isset( $names[ $hash ] ) ) {
				// @longform Overwrites rather than filling a gap: a row carries
				// the PATH it was folded or ranked by, and the name table is
				// the one authority for what a row DISPLAYS.
				$rows[ $i ]['url'] = $names[ $hash ]['url'];
			}
		}
		return $rows;
	}

	/**
	 * Name hashes from whichever partition saw them: one `MGET` per
	 * partition asks for every name still missing at once, and none once
	 * every hash is named.
	 *
	 * @param array<int,string>      $hashes 12-char URL hashes.
	 * @param array<int,Stats_Store> $stores Stores the caller resolved once.
	 * @return array<string,array{server:string,url:string}> hash => its server and URL.
	 */
	private static function url_names( array $hashes, array $stores ): array {
		if ( [] === $hashes ) {
			return [];
		}
		$names = [];
		foreach ( $stores as $store ) {
			// Named in the partition that saw it; first name wins.
			$names += $store->get_url_names( \array_diff( $hashes, \array_keys( $names ) ) );
		}
		return $names;
	}

	/**
	 * One merged URL row with its means, which belong to the reader
	 * (decision 2): the row carries the sums its scope's keys held.
	 *
	 * @param array<array-key,mixed> $row A merged index row.
	 * @return array<array-key,mixed>
	 */
	private static function project_row( array $row ): array {
		// Two populations: a timeout has peak memory but no duration.
		$row['avg_ms']      = Stats_Store::mean( Core::num_float( $row['sum_ms'] ?? null ), Core::num_int( $row['timed_count'] ?? null ) );
		$row['avg_peak_mb'] = Stats_Store::mean( Core::num_float( $row['sum_peak_mb'] ?? null ), Core::num_int( $row['count'] ?? null ) );
		return $row;
	}

	/**
	 * The read seam, resolved. One entry point for both shapes, so a test
	 * counting reads counts a point read as well as a whole-index one.
	 *
	 * @param string                 $shard   Shard token from `Stats_Store::url_shard()`.
	 * @param string                 $server  Reporting server; '' reads every server.
	 * @param array<int,Stats_Store> $stores  Stores the caller resolved once.
	 * @param int                    $now     The reply's clock, read once at its entry.
	 * @param bool                   $errored Fold each key's errored rows alone.
	 * @return array<int,array<array-key,mixed>>
	 */
	private static function read_index( string $shard, string $server, array $stores, int $now, bool $errored ): array {
		$read = self::$load_index ?? self::load_index_default( ... );
		$rows = [];
		foreach ( Core::arr( $read( $shard, $server, $stores, $now, $errored ) ) as $row ) {
			if ( \is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * One shard of the merged URL index across all partitions, shaped for
	 * dashboard display.
	 *
	 * Rows are keyed by URL hash while merging, then flattened to a list,
	 * unsorted: `url_page()` sorts what survives. A server scope reads that
	 * server's keys; no scope reads every server the index names, and a hash
	 * two servers share folds into one row.
	 *
	 * The bucket key IS the hash `Log_Manager::url_hash()` stamped on the
	 * record — never derive another, or the row indexes under a hash no rid
	 * lookup can produce.
	 *
	 * An errored read folds each key's `Stats_Store::errored_rows()` alone,
	 * the rows the writer ranks for an errored page, so a URL's row there
	 * is the traffic of the keys it errored in.
	 *
	 * @param string                 $shard   Shard token from `Stats_Store::url_shard()`.
	 * @param string                 $server  Reporting server; '' reads every server.
	 * @param array<int,Stats_Store> $stores  Stores the caller resolved once.
	 * @param int                    $now     The reply's clock, read once at its entry.
	 * @param bool                   $errored Fold each key's errored rows alone.
	 * @return array<int,array<string,mixed>>
	 */
	public static function load_index_default( string $shard, string $server, array $stores, int $now, bool $errored ): array {
		$plan   = self::read_plan( $now );
		$recent = self::recent_keys( $plan );
		$result = [];
		foreach ( $stores as $store ) {
			self::walk_shard_tiers(
				$plan,
				static fn ( array $hours ): array => $store->url_hour_sources( $hours, $shard, false, $server ),
				static fn ( array $buckets ): array => $store->url_row_sources( $buckets, $shard, false, $server ),
				static function ( string $bucket, array $data ) use ( &$result, $recent, $errored ): void {
					self::fold_bucket( $result, $errored ? Stats_Store::errored_rows( $data ) : $data, isset( $recent[ $bucket ] ) );
				}
			);
		}

		// @longform The display shape, keeping `sum_ms` and `sum_peak_mb`: the
		// means belong to the projection, over whichever scope it is asked
		// for, and un-averaging a figure divided here would put this
		// denominator in a second place.
		// @longform By reference, and `array_values` after: copying each row
		// into a second list materialises the WHOLE index twice, which
		// exhausts a production hub's 512MB. The rows are refcounted here, so
		// only the outer list is new.
		foreach ( $result as &$entry ) {
			// Null when nothing timed folded a min.
			$entry['min_ms'] = Core::as_float( $entry['min_ms'] );
		}
		unset( $entry );
		return \array_values( $result );
	}

	/**
	 * The two-tier shard walk both index readers make, over one store and one
	 * shard: every chunk of the plan's coarse hours through `$coarse`, then
	 * the current hour's buckets through `$fine`, each `[ bucket, data ]`
	 * pair handed to `$fold`.
	 *
	 * An hour with no coarse key — not folded yet — leaves the fold short
	 * there, a lag `unfolded()` reports; its buckets are never read, since
	 * reading both would count the hour twice wherever the fold stands.
	 *
	 * Each chunk is read, folded and DROPPED before the next is read: holding
	 * the whole window's buckets beside the index they build exhausts a
	 * production hub's 512MB. Order across the tiers is not load-bearing — no
	 * read here is first-wins — and one fold serves both, because an hour key
	 * is never a five-minute bucket of the current hour.
	 *
	 * @param array{fine: list<string>, hours: list<string>} $plan   `Stats_Store::read_plan()`.
	 * @param \Closure(list<string>): list<array{0:string,1:array<array-key,mixed>,2:string}> $coarse Coarse-tier reader.
	 * @param \Closure(list<string>): list<array{0:string,1:array<array-key,mixed>,2:string}> $fine   Fine-tier reader.
	 * @param \Closure(string, array<array-key,mixed>): void $fold One pair's sink.
	 */
	private static function walk_shard_tiers( array $plan, \Closure $coarse, \Closure $fine, \Closure $fold ): void {
		foreach ( [ [ $coarse, $plan['hours'] ], [ $fine, $plan['fine'] ] ] as [ $read, $keys ] ) {
			foreach ( \array_chunk( $keys, self::INDEX_READ_CHUNK ) as $chunk ) {
				foreach ( $read( $chunk ) as [ $key, $data ] ) {
					$fold( $key, $data );
				}
			}
		}
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
	 * @param bool                              $is_recent Whether it is among
	 *                                                     the keys the recent rate sums.
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
			// Many URLs; `dump_url` cannot answer for it.
			'aggregate'    => Stats_Store::is_other_key( $hash ),
			'count'        => 0,
			'timed_count'  => 0,
			'count_2xx'    => 0,
			'count_3xx'    => 0,
			'count_4xx'    => 0,
			'count_5xx'    => 0,
			'errors'       => 0,
			'sum_ms'       => 0.0,
			// null until a TIMED bucket has a min to fold in.
			'min_ms'       => null,
			'max_ms'       => 0.0,
			'sum_peak_mb'  => 0.0,
			'max_peak_mb'  => 0.0,
			'worker'       => false,
			'recent_count' => 0,
			'last_updated' => 0,
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
	 * @param bool                   $is_recent Whether that key is among the
	 *                                          keys the recent rate sums.
	 * @return array<string,mixed>
	 */
	private static function fold_index_row( array $entry, array $stat_arr, bool $is_recent ): array {
		// @longform The storage/display boundary for the ROW: stored rows are
		// POSITIONAL (`Stats_Store::ROW_*`) and this one crosses the wire as
		// JSON, so it keeps its names.
		// @longform Arithmetic reads take the VALIDATED family, per `Core`'s own
		// rule: a stored row carries a bool at ROW_WORKER, so a shifted index
		// puts one where a count is read, and `as_int( true )` folds it as 1
		// while `num_int` takes the default. Under NAMES that needed a writer
		// to spell `'count' => true`; under indexes any drift does it.
		$entry                 = self::add_row_sums( $entry, $stat_arr, true );
		$entry['recent_count'] = Core::num_int( $entry['recent_count'] ) + ( $is_recent ? Core::num_int( $stat_arr[ Stats_Store::ROW_COUNT ] ?? 0 ) : 0 );
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
		// The path until `resolve_urls()` names the row in full.
		if ( '' === $entry['url'] ) {
			$entry['url'] = Core::str( $stat_arr[ Stats_Store::ROW_PATH ] ?? '' );
		}
		return $entry;
	}

	/**
	 * A display row with another row's `Stats_Store::ROW_SUMS` added, each
	 * under its `ROW_FIELD_NAMES` name: a whole count as an int, a sum as a
	 * float.
	 *
	 * @template TKey of array-key
	 * @param array<TKey,mixed>      $into   A display row.
	 * @param array<array-key,mixed> $from   A stored row, or another display row.
	 * @param bool                   $stored Whether `$from` is positional.
	 * @return array<TKey|string,mixed>
	 */
	private static function add_row_sums( array $into, array $from, bool $stored ): array {
		foreach ( Stats_Store::ROW_SUMS as $index => $whole ) {
			$name          = Stats_Store::ROW_FIELD_NAMES[ $index ];
			$value         = $from[ $stored ? $index : $name ] ?? null;
			$into[ $name ] = $whole
				? Core::num_int( $into[ $name ] ?? null ) + Core::num_int( $value )
				: Core::num_float( $into[ $name ] ?? null ) + Core::num_float( $value );
		}
		return $into;
	}

	/**
	 * The hour keys a chart reads, newest first: the current hour and the
	 * `CHART_HOURS - 1` before it, which together hold every slot
	 * `chart_slots()` draws (decision 35).
	 *
	 * @param int $now The reply's clock, read once at its entry.
	 * @return list<string>
	 */
	private static function chart_keys( int $now ): array {
		return \array_map(
			static fn ( int $back ): string => Stats_Store::hour_of( Stats_Store::bucket_key( $now - $back * Stats_Store::HOUR_SECONDS ) ),
			\range( 0, Stats_Store::CHART_HOURS - 1 )
		);
	}

	/**
	 * The five-minute slots a chart draws, newest first: the
	 * `MAX_READ_BUCKETS` (288) ending at the current bucket. The reply names
	 * them as `slots`, and the dashboard's axis is exactly these.
	 *
	 * Memoized while that bucket is current, as `read_window()` is: one
	 * `overview` asks for them once per series it draws, and each would
	 * otherwise spend 288 `gmdate()` calls.
	 *
	 * @param int $now The reply's clock, read once at its entry.
	 * @return list<string>
	 */
	private static function chart_slots( int $now ): array {
		$at = Stats_Store::bucket_key( $now );
		if ( ! isset( self::$chart_slots[ $at ] ) ) {
			self::$chart_slots = [
				$at => \array_map(
					static fn ( int $back ): string => Stats_Store::bucket_key( $now - $back * Stats_Store::BUCKET_SECONDS ),
					\range( 0, Stats_Store::MAX_READ_BUCKETS - 1 )
				),
			];
		}
		return self::$chart_slots[ $at ];
	}

	/**
	 * The reply's read plan (`Stats_Store::read_plan()`) over `read_window()`.
	 *
	 * @param int $now The reply's clock, read once at its entry.
	 * @return array{fine: list<string>, hours: list<string>}
	 */
	private static function read_plan( int $now ): array {
		return Stats_Store::read_plan( \array_values( self::read_window( $now ) ) );
	}

	/**
	 * The keys a "recent" rate sums, each with the seconds it spans: the
	 * current hour's closed buckets, or the hour before while none of them
	 * has closed. The bucket still filling is left out: counting it drags
	 * the figure down by however much of it has not happened yet.
	 *
	 * @param array{fine: list<string>, hours: list<string>} $plan The reply's read plan.
	 * @return array<string,int> Key => seconds.
	 */
	private static function recent_keys( array $plan ): array {
		$closed = \array_slice( $plan['fine'], 1 );
		return [] !== $closed
			? \array_fill_keys( $closed, Stats_Store::BUCKET_SECONDS )
			: \array_fill_keys( \array_slice( $plan['hours'], 0, 1 ), Stats_Store::HOUR_SECONDS );
	}

	/**
	 * Whether a fold is short of the hour just closed, whose fold the writer
	 * may still owe (`lagging()`): some store holds no index for it. An
	 * older hour's hole is a short answer (decision 3).
	 *
	 * @param array{fine: list<string>, hours: list<string>} $plan   The reply's read plan.
	 * @param array<int,Stats_Store>                         $stores Stores the caller resolved once.
	 */
	private static function unfolded( array $plan, array $stores ): bool {
		$owed = \array_keys( \array_intersect_key( self::lagging( $plan ), \array_flip( $plan['hours'] ) ) );
		foreach ( $stores as $store ) {
			if ( \count( $store->server_index( $owed, [] ) ) < \count( $owed ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The keys whose derived records the writer may still owe: the open
	 * bucket, since `rank_due()` ranks a bucket at most once a
	 * `URL_PAGE_REFRESH_S`, and whatever closed last, whose ranking or fold
	 * at the close rides a flush no clock bounds, for the whole of the
	 * bucket after it — the bucket before, or the hour just closed.
	 *
	 * @param array{fine: list<string>, hours: list<string>} $plan The reply's read plan.
	 * @return array<string,true>
	 */
	private static function lagging( array $plan ): array {
		return \array_fill_keys( \array_slice( [ ...$plan['fine'], ...\array_slice( $plan['hours'], 0, 1 ) ], 0, 2 ), true );
	}

	/**
	 * The bucket keys a reader walks — the configured retention window.
	 *
	 * Memoized for as long as the current bucket is current. One `urls` page
	 * calls this once per shard it loads, each otherwise rebuilding up to 288
	 * keys with a `gmdate()` apiece. The caller's `$now` is what makes those
	 * calls one window: a reply reads the clock once and hands the same
	 * instant to every call.
	 *
	 * @param int $now The reply's clock, read once at its entry.
	 * @return array<int,string>
	 */
	private static function read_window( int $now ): array {
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
	 * Whether the ranked lists can answer a page: no search, and a page that
	 * ends inside the fine tier's list depth. Worker traffic and the errored
	 * rows each have lists of their own (`Stats_Store::RANK_SETS`).
	 */
	private static function ranked_serves( string $search, int $end ): bool {
		return '' === $search && $end <= Stats_Store::URL_RANK_N;
	}

	/**
	 * The category series on the wire: `[ nameIndex, t, c, n ]` rows.
	 *
	 * `CAT_MS` is rounded all the same, and the store having rounded it is not
	 * the reason it can be skipped — it is the reason it CANNOT. What arrives
	 * here is a SUM across one `Stats_Store` per flame-builder partition, and a
	 * sum of doubles at `CAT_MS_DECIMALS` places is not itself at that
	 * precision: 0.1 + 0.2 serializes as 0.30000000000000004, nineteen
	 * characters for a number a chart draws as three. Measured over 5,000
	 * realistic millisecond pairs, 22.1% of two-store sums and 30.0% of
	 * four-store sums serialize LONGER than their rounded value — the same
	 * bloat the stored rounding removes, put back on the wire of every
	 * multi-partition install.
	 *
	 * @param array<string,mixed> $merged `{ bucket => { name => CAT_SUMS entry } }`.
	 * @return array{names: list<string>, buckets: array<string,list<list<int|float>>>}
	 */
	private static function compact_category_series( array $merged ): array {
		return self::name_table(
			$merged,
			static fn ( array $stat ): array => [
				\round( Core::num_float( $stat[ Stats_Store::CAT_MS ] ?? null ), Stats_Store::CAT_MS_DECIMALS ),
				Core::num_int( $stat[ Stats_Store::CAT_CALLS ] ?? null ),
				Core::num_int( $stat[ Stats_Store::CAT_REQUESTS ] ?? null ),
			]
		);
	}

	/**
	 * A dimensional series on the wire: `[ nameIndex, count, sumMs,
	 * sumPeakMb, timed ]` rows, `DIM_SUMS` in its stored order.
	 *
	 * @param array<array-key,mixed> $merged `{ bucket => { value => DIM_SUMS entry } }`.
	 * @return array{names: list<string>, buckets: array<string,list<list<int|float>>>}
	 */
	private static function compact_dim_series( array $merged ): array {
		return self::name_table(
			$merged,
			static fn ( array $entry ): array => [
				Core::num_int( $entry[ Stats_Store::DIM_COUNT ] ?? null ),
				Core::num_float( $entry[ Stats_Store::DIM_SUM_MS ] ?? null ),
				Core::num_float( $entry[ Stats_Store::DIM_SUM_PEAK_MB ] ?? null ),
				Core::num_int( $entry[ Stats_Store::DIM_TIMED ] ?? null ),
			]
		);
	}

	/**
	 * A bucketed series in its WIRE shape: a name table, and per bucket
	 * positional rows `[ nameIndex, ...$row( entry ) ]`.
	 *
	 * Nothing is dropped — every name and every bucket survives. What goes is
	 * repetition: a name is spelled once per bucket it appears in, 288 times
	 * across a chart. Measured on a production hub, the category series was
	 * the largest thing the `overview` reply carried, and the reply was being
	 * cut off before it finished; a `ua` breakdown at 288 slots is about 1 MB
	 * a poll spelled out. Decision 18's argument at the wire. A name PHP keys
	 * as an integer goes out a string, as the dashboard compares it.
	 *
	 * @param array<array-key,mixed>                            $merged `{ bucket => { name => entry } }`.
	 * @param \Closure(array<array-key,mixed>): list<int|float> $row    An entry's positional fields.
	 * @return array{names: list<string>, buckets: array<string,list<list<int|float>>>}
	 */
	private static function name_table( array $merged, \Closure $row ): array {
		$names   = [];
		$buckets = [];
		foreach ( $merged as $bucket => $entries ) {
			$rows = [];
			foreach ( Core::arr( $entries ) as $name => $entry ) {
				$names[ $name ] ??= \count( $names );
				$rows[]         = [ $names[ $name ], ...$row( Core::arr( $entry ) ) ];
			}
			$buckets[ (string) $bucket ] = $rows;
		}
		return [ 'names' => \array_map( 'strval', \array_keys( $names ) ), 'buckets' => $buckets ];
	}

	/**
	 * `dump_url --after`: the log position the caller holds in each request
	 * partition, as a JSON object `{"<partition>":{"segment":S,"offset":O}}`.
	 * Anything else is refused, since a cursor read wrongly skips requests.
	 *
	 * @param mixed $raw The bound token; null when the caller sent none.
	 * @return array<int,array{0:int,1:int}> Partition => [segment, offset].
	 * @throws \InvalidArgumentException When it is not partition positions.
	 */
	private static function positions( mixed $raw ): array {
		if ( null === $raw ) {
			return [];
		}
		$decoded   = \json_decode( Core::as_string( $raw ) );
		$positions = [];
		$valid     = $decoded instanceof \stdClass;
		foreach ( $valid ? \get_object_vars( $decoded ) : [] as $partition => $at ) {
			$segment = $at instanceof \stdClass ? ( $at->segment ?? null ) : null;
			$offset  = $at instanceof \stdClass ? ( $at->offset ?? null ) : null;
			if ( ! \preg_match( '/^\d+$/D', (string) $partition ) || ! \is_int( $segment ) || ! \is_int( $offset ) || $segment < 0 || $offset < 0 ) {
				$valid = false;
				break;
			}
			$positions[ (int) $partition ] = [ $segment, $offset ];
		}
		if ( ! $valid ) {
			throw new \InvalidArgumentException( 'after wants partition positions: {"<partition>":{"segment":<int>,"offset":<int>}}' );
		}
		return $positions;
	}

	/**
	 * Epoch seconds from `Core::$now`, read once per verb and passed down,
	 * per ELN decision 29.
	 */
	private static function now(): int {
		return (int) Core::$now;
	}

	/**
	 * Decode a synced array-option value. Settings_Sync_Node::scalarize()
	 * JSON-encodes arrays unconditionally, so the wire form is always JSON. A
	 * non-JSON value is a contract violation, refused before anything is
	 * written: read as [], the ruleset option would reach
	 * `Rule_Set::apply_synced( [] )` and SAVE an empty ruleset.
	 *
	 * @param string $raw The raw positional value off the wire.
	 * @return array<array-key,mixed>
	 * @throws \RuntimeException When the value is not a JSON array.
	 */
	private static function decode_array_value( string $raw ): array {
		$decoded = \json_decode( $raw, true );
		if ( ! \is_array( $decoded ) ) {
			throw new \RuntimeException( 'synced array-option value is not a JSON array' );
		}
		return $decoded;
	}

	/**
	 * Refuse a dimension this CI does not know.
	 *
	 * Dropped instead of refused, the reply simply says nothing about it, and
	 * a reader that reads an absent dimension as "still in flight" waits on
	 * one nobody will ever send.
	 *
	 * @param string       $dimension The dimension name as the caller spelled it.
	 * @param list<string> $known     The dimensions the verb answers.
	 * @throws \RuntimeException When it is not one of `$known`.
	 */
	private static function assert_dimension( string $dimension, array $known ): void {
		if ( ! \in_array( $dimension, $known, true ) ) {
			throw new \RuntimeException( \esc_html( "invalid breakdown dimension: {$dimension}" ) );
		}
	}

	/**
	 * Schema-driven dispatch: each verb is declared once in
	 * `commands[]` carrying its `handler`. The inherited Service_CI_Node ctor
	 * builds the commands table from this schema. Stats-reading verbs build a
	 * per-partition Stats_Store over the mounted Tables; when the mount throws
	 * `Table_Unavailable` they answer with empty or zeroed shapes. Disk-walking
	 * verbs work regardless.
	 *
	 * The substrate binds each verb's tokens against its `args` before the
	 * handler runs, so a handler reads `$args['<name>']` typed and defaulted,
	 * and a token that does not fit never reaches it. A handler throws a
	 * RuntimeException on a value its declaration cannot rule out; the
	 * interpreter turns either throw into a TM_COMMAND|TM_ERROR reply, so no
	 * handler returns an error shape.
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
						[ 'name' => 'categories', 'type' => 'bool', 'required' => false, 'default' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				// Server scopes; breakdown is a comma-separated dimension list.
				$server     = Core::as_string( $args['server'] );
				$breakdown  = Core::as_string( $args['breakdown'] );
				$categories = true === $args['categories'];

				\assert( $self instanceof self );
				$now                           = self::now();
				$stores                        = $self->stats_stores( [ Stats_Store::TABLE_AGGREGATE ] );
				$hourly                        = self::hourly_slots( $stores, $now );
				$payload                       = self::build_overview_payload( $hourly, $now );
				$payload['global_leaderboard'] = [
					...self::build_leaderboard( $server, $stores, $now ),
					'avg_ms' => self::board_avg_ms( $server, $hourly, $stores, $now ),
				];

				// One key per dimension ASKED for, whatever the count.
				if ( '' !== $breakdown ) {
					$payload['breakdowns'] = [];
					foreach ( \array_map( 'trim', \explode( ',', $breakdown ) ) as $dim ) {
						self::assert_dimension( $dim, self::DIMENSIONS );
						$payload['breakdowns'][ $dim ] = self::compact_dim_series( self::merge_dim_across_partitions( $dim, $server, $stores, $now ) );
					}
				}

				if ( $categories ) {
					$payload['category_time_series'] = self::compact_category_series( self::merge_categories_across_partitions( $server, $stores, $now ) );
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
				$sort    = Core::as_string( $args['sort'] );
				$order   = Core::as_string( $args['order'] );
				$limit   = \min( 1000, \max( 1, Core::as_int( $args['limit'] ) ) );
				$offset  = \min( 10000, Core::as_int( $args['offset'] ) );
				$search  = Core::as_string( $args['search'] );
				$server  = Core::as_string( $args['server'] );
				$errors  = true === $args['errors_only'];
				// Opts IN: the default EXCLUDES. See decision 15.
				$workers = true === $args['include_workers'];

				if ( ! \in_array( $sort, Stats_Store::URL_SORTS, true ) ) {
					$sort = 'count';
				}
				if ( 'asc' !== $order && 'desc' !== $order ) {
					$order = 'desc';
				}

				\assert( $self instanceof self );
				$now  = self::now();
				$page = $self->url_page( $server, $search, $errors, $workers, $sort, $order, $offset, $limit, $self->stats_stores( [ Stats_Store::TABLE_AGGREGATE, Stats_Store::TABLE_URL_FINE ] ), $now );

				return [
					'data'    => $page['data'],
					'rows'    => $page['rows'],
					// Zeros for a scope holding no rows, never null.
					'totals'  => $page['totals'],
					'slowest' => $page['slowest'],
					// `totals.urls` is the sketch's where the records answered.
					'estimated' => $page['estimated'],
					// Short of a fold, a ranking or a read; never cached.
					'provisional' => $page['provisional'],
					'ranked'  => $page['ranked'],
					'as_of'   => $page['as_of'],
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
					'name'        => 'dump_url',
					'capability'  => Capabilities::READ,
					'description' => 'Single-URL detail incl. aggregate flame data summed across partitions. Its request list is the newest RECENT_REQUEST_LIMIT inside the window opening at requests_window_start; `after` tails it.',
					'args'        => [
						[ 'name' => 'hash', 'type' => 'string', 'required' => true ],
						[ 'name' => 'breakdown', 'type' => 'string', 'required' => false ],
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						[ 'name' => 'categories', 'type' => 'bool', 'required' => false, 'default' => false ],
						[ 'name' => 'after', 'type' => 'json', 'required' => false, 'description' => 'Tails the request list: {"<partition>":{"segment":S,"offset":O}}, the `positions` the last reply reported. Each partition reads only index lines past its own; one absent is read whole.' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$hash = Core::as_string( $args['hash'] );
				if ( ! \preg_match( '/^[a-f0-9]{8,64}$/D', $hash ) ) {
					throw new \RuntimeException( 'invalid hash format' );
				}
				$breakdown = Core::as_string( $args['breakdown'] );
				if ( '' !== $breakdown ) {
					self::assert_dimension( $breakdown, self::URL_DIMENSIONS );
				}

				// @longform The row that opened this modal was the selected
				// server's, so this answers for the same server — otherwise one
				// click puts a site-wide average under a scoped count, on two
				// surfaces too far apart to compare.
				$server = Core::as_string( $args['server'] );

				// A tail reads its URL blob from its Table alone.
				$after = self::positions( $args['after'] );

				\assert( $self instanceof self );
				$now    = self::now();
				$stores = $self->stats_stores( Stats_Store::TABLES );
				$entry  = $self->row( $hash, $server, $stores, $now );
				$stats  = null;
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
						'requests_per_second' => self::recent_rate( Core::num_int( $entry['recent_count'] ?? null ), self::recent_keys( self::read_plan( $now ) ) ),
					];
				}
				if ( null === $stats ) {
					throw new \RuntimeException( \esc_html( "URL not found: {$hash}" ) );
				}

				$deadline  = self::scan_deadline();
				$recent    = self::find_recent_requests_for_url( $hash, $now, $after, $deadline );
				$aggregate = Stats_Store::url_stats( $stores, $hash )
					?? self::rebuilt_url_aggregate( $hash, $recent['requests'], [] !== $after, $now, $deadline );
				// A stored blob may hold profiles alone; a null flame is meant.
				$flame     = \array_key_exists( 'flame', $aggregate ) ? $aggregate['flame'] : self::EMPTY_FLAME;

				$payload = [
					'stats'              => $stats,
					'requests'           => $recent['requests'],
					// An empty list that stopped short is not an empty URL.
					'scan_stopped_early' => $recent['truncated'] || ! empty( $aggregate['truncated'] ),
					// Nor is one that ran out of window an empty record.
					'requests_window_start' => $recent['window_start'],
					// An object even when keyed 0..n, so --after takes it back.
					'positions'          => (object) \array_map( static fn ( array $at ): array => [ 'segment' => $at[0], 'offset' => $at[1] ], $recent['positions'] ),
					'aggregate_flame'    => $flame,
					'aggregate_profiles' => $aggregate['profiles'] ?? null,
					'last_modified'      => $aggregate['last_modified'],
					'slots'              => self::chart_slots( $now ),
				];

				if ( '' !== $breakdown ) {
					$payload['breakdown_time_series'] = self::compact_dim_series( self::merge_url_dim( $hash, $breakdown, $stores, $now ) );
				}

				if ( true === $args['categories'] ) {
					$payload['category_time_series'] = self::compact_category_series( self::merge_url_categories( $hash, $stores, $now ) );
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
				// keeps only the series, so it reads the stats Tables and never
				// the index: `dump_url` walks every partition's index to build
				// `requests`, which a breakdown fetch throws away.
				$hash = Core::as_string( $args['hash'] );
				if ( ! \preg_match( '/^[a-f0-9]{8,64}$/D', $hash ) ) {
					throw new \RuntimeException( 'invalid hash format' );
				}
				$breakdown = Core::as_string( $args['breakdown'] );
				self::assert_dimension( $breakdown, self::URL_DIMENSIONS );
				\assert( $self instanceof self );
				$now = self::now();
				return [
					'breakdown_time_series' => self::compact_dim_series( self::merge_url_dim( $hash, $breakdown, $self->stats_stores( [ Stats_Store::TABLE_AGGREGATE ] ), $now ) ),
					'slots'                 => self::chart_slots( $now ),
				];
					},
				],
				[
					'name'        => 'search_requests',
					'capability'  => Capabilities::READ,
					'description' => 'Locate a request by rid across partitions.',
					'args'        => [
						[ 'name' => 'rid', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$rid   = Core::as_string( $args['rid'] );
				$found = self::find_request_index_entry( $rid );
				if ( null === $found ) {
					throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
				}
				return $found;
					},
				],
				[
					'name'        => 'grep_requests',
					'capability'  => Capabilities::READ,
					'description' => 'Pattern-search recent firehose traffic; returns a bounded summary of matching requests (rid, url, method, ts, match_count).',
					'args'        => [
						[ 'name' => 'pattern', 'type' => 'string', 'required' => true ],
						[ 'name' => 'limit', 'type' => 'int', 'required' => false, 'default' => self::GREP_RESULT_LIMIT_DEFAULT ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$pattern = Core::as_string( $args['pattern'] );
				// The binder takes whitespace as a value; it matches too much.
				if ( '' === \trim( $pattern ) ) {
					throw new \RuntimeException( 'pattern required' );
				}
				$limit = \min( self::GREP_RESULT_LIMIT_MAX, \max( 1, Core::as_int( $args['limit'] ) ) );

				return self::run_grep_requests( $pattern, $limit );
					},
				],
				[
					'name'        => 'dump_request',
					'capability'  => Capabilities::READ,
					'description' => 'Full request + flame data for a rid; --partition hints where to look first.',
					'args'        => [
						[ 'name' => 'rid', 'type' => 'string', 'required' => true ],
						[ 'name' => 'partition', 'type' => 'int', 'required' => false, 'default' => 0 ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$rid       = Core::as_string( $args['rid'] );
				$partition = Core::as_int( $args['partition'] );

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
						// The trailing descriptors, or `--context=` repeated.
						[ 'name' => 'context', 'type' => 'string', 'required' => false, 'variadic' => true ],
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						// The page's own scope; only `overview:` reads them.
						[ 'name' => 'search', 'type' => 'string', 'required' => false ],
						[ 'name' => 'errors_only', 'type' => 'bool', 'required' => false, 'default' => false ],
						[ 'name' => 'include_workers', 'type' => 'bool', 'required' => false, 'default' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				\assert( $self instanceof self );
				return $self->assemble_ask(
					Core::as_string( $args['descriptor'] ),
					\array_values( \array_map( Core::as_string( ... ), Core::arr( $args['context'] ) ) ),
					Core::as_string( $args['server'] ),
					[
						'search'          => Core::as_string( $args['search'] ),
						'errors_only'     => true === $args['errors_only'],
						'include_workers' => true === $args['include_workers'],
					],
					self::now()
				);
					},
				],
				[
					'name'        => 'list_hooks',
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
					'description' => 'Normalized positional single-option perf setting write with sync guard. A bool option takes a bool word, or a blank for false, as a hub pushes one.',
					'args'        => [
						[ 'name' => 'option', 'type' => 'string', 'required' => true ],
						[ 'name' => 'value', 'type' => 'string', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				// One option per command; Settings_Sync_Node fans it out.
				$option = Core::as_string( $args['option'] );
				if ( ! isset( self::SETTINGS_OPTIONS[ $option ] ) ) {
					throw new \RuntimeException( \esc_html( "unknown option: {$option}" ) );
				}

				if ( null === $args['value'] ) {
					throw new \RuntimeException( 'value required' );
				}

				// Wire value is string; array options carry JSON, decoded here.
				$type      = self::SETTINGS_OPTIONS[ $option ];
				$raw_value = Core::as_string( $args['value'] );
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
				// Outside is_admin(): no updated_option tells the workers.
				Restart_Planner::plan( [] );

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
