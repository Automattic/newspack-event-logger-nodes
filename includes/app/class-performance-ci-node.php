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

	/** Table namespace of the URL page cache, beside `Rule_Set::TABLE_HOOKS`. */
	private const URLS_PAGE_NS = 'eln-urls-page';

	/**
	 * The widest page cached, and its size bound: 250 of the widest rows a
	 * reply names serialize to about 490KB, inside `Stats_Store::ITEM_BUDGET`.
	 */
	private const URLS_PAGE_CACHE_MAX_ROWS = 250;

	/**
	 * The keys one URL page carries. Two places assemble a page — the ranked
	 * read and the searched read — and the cache reads a missing key as a
	 * miss, so both spell the shape from here.
	 */
	private const PAGE_FIELDS = [ 'data', 'as_of', ...self::HEADER_FIELDS ];

	/** The URL row fields a header adds across its URLs. */
	private const HEADER_SUMS = [ 'count', 'timed_count', 'sum_ms', 'sum_peak_mb', 'errors' ];

	/** The subset `url_header()` answers for. */
	private const HEADER_FIELDS = [ 'rows', 'totals', 'slowest', 'provisional' ];

	/**
	 * What the url-rows Ledger ranks each `urls` sort by: a column, `x` for
	 * the URL, or a mean as `[ sum, count ]`. A searched page reads its
	 * candidates' rows and ranks them here, as the Ledger ranks a `TOP`.
	 */
	private const ORDER_BY = [
		'count'        => 'count',
		'url'          => 'x',
		'avg_ms'       => [ 'sum_ms', 'timed_count' ],
		'min_ms'       => 'min_ms',
		'max_ms'       => 'max_ms',
		'avg_peak_mb'  => [ 'sum_peak_mb', 'count' ],
		'last_updated' => 'last_seen',
	];

	/**
	 * The switch for the name scan, OFF: a term the word index cannot
	 * answer — one of no word, or one whose every word is too common —
	 * falls back to it, the scope's rows read whole and kept by
	 * `Stats_Store::url_matches()`. Off, a term of no word keeps nothing and
	 * a term too common is refused. It is the only name scan; the name
	 * scan's tests turn it on.
	 *
	 * @var bool
	 */
	public static bool $match_names = false;

	/** This CI's asker for the stats stores, built on the first read. */
	private ?Table_Client $client = null;

	/**
	 * A stats store's reply goes to the client; every other message is this
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
	 * It asks the same two reads the page does, with the same scope, so the
	 * brief and the screen cannot disagree about WHAT they describe:
	 * `url_page()` for the filtered set's totals and leaderboard, the
	 * leaderboard for the category board beside it. They can disagree about
	 * WHEN: the URL half is a page the cache may hold up to
	 * `Stats_Store::URL_PAGE_REFRESH_S` behind the board. The site-wide
	 * totals are deliberately NOT used — they ignore every filter, so under
	 * a server or a search they would describe a different site than the
	 * one on screen.
	 *
	 * @param string              $server  Server the page is scoped to; '' is every server.
	 * @param array<string,mixed> $filters search / errors_only / include_workers, as the page has them.
	 * @param int                 $now     The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 */
	private function ask_overview( string $server, array $filters, int $now ): array {
		$page = $this->url_page(
			$server,
			Core::as_string( $filters['search'] ?? '' ),
			(bool) ( $filters['errors_only'] ?? false ),
			(bool) ( $filters['include_workers'] ?? false ),
			'count',
			'desc',
			0,
			self::OVERVIEW_BRIEF_URLS,
			$now
		);
		[ $from, $to ] = self::chart_window( $now );

		return Ask_Assembler::for_overview(
			[
				// The page already answered whether its totals cover the scope.
				'totals'      => $page['totals'],
				'provisional' => $page['provisional'],
				'data'        => $page['data'],
			],
			$this->stats_store( [ Stats_Store::LEDGER_LEADERBOARD ], false )->leaderboard( $server, $from, $to ),
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
		// @longform Scoped, because the facts block stamps the filters onto
		// every surface, and an unscoped number under a server's name is
		// quotable and wrong.
		$stats = self::row( $hash, $server, $this->stats_store( [ Stats_Store::LEDGER_NAMES, Stats_Store::LEDGER_URL_ROWS ], false ), $now );
		if ( null === $stats ) {
			throw new \RuntimeException( \esc_html( "URL not found: {$hash}" ) );
		}
		$recent = self::find_recent_requests_for_url( $hash, $now );
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
	 * The filtered URL set: its totals, its slowest, and one page of it.
	 *
	 * With no search a page is a `TOP` over the scope, ranked in the Ledger
	 * by the sort's `ORDER_BY`, and its header comes from `url_header()`,
	 * once a refresh. A search reads the rows of the URLs the word index
	 * names and pages them here (`searched_page()`).
	 *
	 * It is read THROUGH the cache for `Stats_Store::URL_PAGE_REFRESH_S`
	 * under every filter, the window bucket and the retention: every tab
	 * polling the same page would otherwise read the Ledger again. A page
	 * wider than `URLS_PAGE_CACHE_MAX_ROWS` is built and never stored.
	 *
	 * @param string $server  Reporting server to scope to; '' reads every server.
	 * @param string $search  Case-insensitive whole URL words; '' matches all.
	 * @param bool   $errors  Keep only the URLs that timed out or fataled.
	 * @param bool   $workers Keep worker traffic (the default excludes it).
	 * @param string $sort    A URL_SORTS field.
	 * @param string $order   'asc' or 'desc'.
	 * @param int    $offset  Page offset.
	 * @param int    $limit   Page size.
	 * @param int    $now     The reply's clock, read once at its entry.
	 * @return array{data:array<int,array<array-key,mixed>>,rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,provisional:bool,as_of:int}
	 */
	private function url_page( string $server, string $search, bool $errors, bool $workers, string $sort, string $order, int $offset, int $limit, int $now ): array {
		// @longform Normalized ONCE, here: `Womb`, `womb` and `womb ` are one
		// search, and a key that normalizes while the paths below it read the
		// raw term would answer one entry two ways. The handler echoes the
		// raw value in `filters`, which is the only place it still matters.
		$search = \strtolower( \trim( $search ) );
		$build  = function () use ( $server, $search, $errors, $workers, $sort, $order, $offset, $limit, $now ): array {
			$store   = $this->stats_store( [ Stats_Store::LEDGER_NAMES, Stats_Store::LEDGER_URL_ROWS, ...( '' === $search ? [] : [ Stats_Store::LEDGER_SEARCH ] ) ], false );
			$servers = self::scope_servers( $store, $server, $workers, $now );
			if ( '' !== $search ) {
				$page = self::searched_page( $store, $server, $servers, $search, $errors, $workers, $sort, $order, $offset, $limit, $now );
			} else {
				[ $from, $to ] = self::window( $now );
				$header        = $this->url_header( $store, $server, $servers, $errors, $workers, $now );
				$ranked        = $store->url_page( $servers, $workers, self::order_by( $sort, $errors ), $order, $limit, $offset, $errors, $from, $to );
				$page          = [
					'data' => \array_map( self::project_row( ... ), $ranked['rows'] ),
					'rows' => $ranked['total'],
				] + $header;
			}
			$page['provisional'] = $page['provisional'] || $store->unanswered();
			return [ 'as_of' => $now ] + $page;
		};
		if ( $limit > self::URLS_PAGE_CACHE_MAX_ROWS ) {
			return $build();
		}
		/** @var array{data:array<int,array<array-key,mixed>>,rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,provisional:bool,as_of:int} */
		return self::read_through_page(
			Flame_Tree::URL_PAGE_CACHE,
			[ $server, $search, $errors, $workers, $sort, $order, $offset, $limit, Stats_Store::bucket_key( $now ), AppConfig::stats_retention_seconds() ],
			self::PAGE_FIELDS,
			Stats_Store::URL_PAGE_REFRESH_S,
			$build
		);
	}

	/**
	 * The header of one scope's URL set, held for
	 * `Stats_Store::URL_PAGE_REFRESH_S`: every page of the scope shares it,
	 * whatever its sort and offset.
	 *
	 * @param Stats_Store  $store   The reply's store.
	 * @param string       $server  Reporting server; '' is the site.
	 * @param list<string> $servers The servers the scope reads.
	 * @param bool         $errors  Only the URLs that errored.
	 * @param bool         $workers Keep worker traffic.
	 * @param int          $now     The reply's clock, read once at its entry.
	 * @return array{rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,provisional:bool}
	 */
	private function url_header( Stats_Store $store, string $server, array $servers, bool $errors, bool $workers, int $now ): array {
		/** @var array{rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,provisional:bool} */
		return self::read_through_page(
			Flame_Tree::URL_HEADER_CACHE,
			[ 'header', $server, $workers, $errors, Stats_Store::bucket_key( $now ), AppConfig::stats_retention_seconds() ],
			self::HEADER_FIELDS,
			Stats_Store::URL_PAGE_REFRESH_S,
			static function () use ( $store, $servers, $errors, $workers, $now ): array {
				[ $from, $to ] = self::window( $now );
				$recent        = self::recent_window( $now );
				$slowest       = $store->url_page( $servers, $workers, self::ORDER_BY[ Stats_Store::SLOWEST_LIST[0] ], Stats_Store::SLOWEST_LIST[1], self::SLOWEST_ROWS, 0, $errors, $from, $to );
				$sums          = $store->scope_totals( $servers, $workers, $errors, $from, $to );
				$rated         = $store->scope_totals( $servers, $workers, $errors, ...$recent );
				return [
					'rows'        => $slowest['total'],
					'totals'      => self::header_totals( $slowest['total'], $sums, Core::num_int( $rated['count'] ?? null ), $recent, $errors ),
					'slowest'     => \array_map( self::project_row( ... ), $slowest['rows'] ),
					'provisional' => $store->unanswered(),
				];
			}
		);
	}

	/**
	 * One page of a searched URL set, inside the `url search read` span: the
	 * rows of the URLs `search_candidates()` names over the window —
	 * errors-only keeping only the key and bucket in which each URL
	 * errored — ranked by `by_rank()` as the Ledger ranks a `TOP` and cut
	 * here, with the header those rows sum to. `rows` and `totals` count the
	 * whole set (decision 15).
	 *
	 * @param Stats_Store  $store   The reply's store.
	 * @param string       $server  Reporting server; '' is the site.
	 * @param list<string> $servers The servers the scope reads.
	 * @param string       $search  The normalized term.
	 * @param bool         $errors  Only the URLs that errored.
	 * @param bool         $workers Keep worker traffic.
	 * @param string       $sort    A URL_SORTS field.
	 * @param string       $order   'asc' or 'desc'.
	 * @param int          $offset  Page offset.
	 * @param int          $limit   Page size.
	 * @param int          $now     The reply's clock, read once at its entry.
	 * @return array{data:array<int,array<array-key,mixed>>,rows:int,totals:array<string,mixed>,slowest:array<int,array<array-key,mixed>>,provisional:bool}
	 * @throws \RuntimeException On a term naming more URLs than a search reads.
	 */
	private static function searched_page( Stats_Store $store, string $server, array $servers, string $search, bool $errors, bool $workers, string $sort, string $order, int $offset, int $limit, int $now ): array {
		$read = static function () use ( $store, $server, $servers, $search, $errors, $workers, $sort, $order, $offset, $limit, $now ): array {
			[ $from, $to ] = self::window( $now );
			$words         = Stats_Store::term_tokens( $search );
			$urls          = self::search_candidates( $store, $server, $search, $words, $from, $to );
			$rows          = [];
			$sums          = [];
			foreach ( [] === $urls ? [] : $store->url_rows( $servers, $workers, $urls, $errors, $from, $to ) as $url => $row ) {
				if ( null === $urls && ! Stats_Store::url_matches( Core::as_string( $url ), $search, $words ) ) {
					continue;
				}
				$rows[ Core::as_string( $url ) ] = $row;
				$sums                            = self::add_sums( $sums, $row );
			}
			$recent = self::recent_window( $now );
			$rated  = 0;
			foreach ( [] === $rows ? [] : $store->url_rows( $servers, $workers, \array_keys( $rows ), $errors, ...$recent ) as $row ) {
				$rated += Core::num_int( $row['count'] ?? null );
			}
			$slowest = \array_values( $rows );
			\usort( $slowest, self::by_rank( self::ORDER_BY[ Stats_Store::SLOWEST_LIST[0] ], Stats_Store::SLOWEST_LIST[1] ) );
			$ranked = \array_values( $rows );
			\usort( $ranked, self::by_rank( self::order_by( $sort, $errors ), $order ) );
			return [
				'data'        => \array_map( self::project_row( ... ), \array_slice( $ranked, $offset, $limit ) ),
				'rows'        => \count( $rows ),
				'totals'      => self::header_totals( \count( $rows ), $sums, $rated, $recent, $errors ),
				'slowest'     => \array_map( self::project_row( ... ), \array_slice( $slowest, 0, self::SLOWEST_ROWS ) ),
				'provisional' => $store->unanswered(),
			];
		};
		$lm = Log_Manager::started_instance();
		return null === $lm ? $read() : $lm->timed( Flame_Tree::URL_SEARCH_READ, $read, static fn ( array $page ): int => $page['rows'] );
	}

	/**
	 * The URLs a term names in the scope, which a searched page reads the
	 * rows of: the word index's (`Stats_Store::search_urls()`), at most
	 * `Stats_Store::URL_SEARCH_MAX`, each carrying every word of the term,
	 * and of those the ones a server scope's host logged. A term the index
	 * cannot answer — of no word, or too common — is null, the name scan,
	 * while `$match_names` is on; off, a term of no word names none and a
	 * term too common is refused.
	 *
	 * `Other` is the one server named by no host — the servers filed past
	 * the cap under it log their own — so its scope keeps every URL.
	 *
	 * @param Stats_Store  $store  The reply's store.
	 * @param string       $server Reporting server; '' is the site.
	 * @param string       $search The normalized term.
	 * @param list<string> $words  The term's words.
	 * @param int          $from   First second read.
	 * @param int          $to     The second the read stops short of.
	 * @return list<string>|null
	 * @throws \RuntimeException On a term too common, the name scan off.
	 */
	private static function search_candidates( Stats_Store $store, string $server, string $search, array $words, int $from, int $to ): ?array {
		$urls = [] === $words ? null : $store->search_urls( $words, $from, $to );
		if ( null === $urls ) {
			if ( self::$match_names ) {
				return null;
			}
			if ( [] === $words ) {
				return [];
			}
			throw new \RuntimeException( \esc_html( \sprintf( 'search "%s" is too common: its URLs run past the %d a search reads; add a word', $search, Stats_Store::URL_SEARCH_MAX ) ) );
		}
		$host = '' === $server || Stats_Store::OTHER_KEY === $server ? null : $server;
		return \array_values( \array_filter( $urls, static fn ( string $url ): bool => Stats_Store::url_matches( $url, $search, $words ) && ( null === $host || Stats_Store::server_of( $url ) === $host ) ) );
	}

	/**
	 * What the url-rows Ledger ranks a sort by. Under errors-only a count
	 * ranks the errors, not the traffic.
	 *
	 * @param string $sort   A URL_SORTS field.
	 * @param bool   $errors Only the URLs that errored.
	 * @return string|array{0:string,1:string}
	 */
	private static function order_by( string $sort, bool $errors ): string|array {
		return $errors && 'count' === $sort ? 'errors' : self::ORDER_BY[ $sort ];
	}

	/**
	 * The header totals of a URL set: its URLs, its requests, the mean of
	 * its timed requests, the mean peak of every request, the recent rate,
	 * and under errors-only its errors.
	 *
	 * @param int                 $urls   URLs in the set.
	 * @param array<string,mixed> $sums   The set's summed row.
	 * @param int                 $rated  Requests in the recent window.
	 * @param array{0:int,1:int}  $recent `recent_window()`.
	 * @param bool                $errors Only the URLs that errored.
	 * @return array<string,mixed>
	 */
	private static function header_totals( int $urls, array $sums, int $rated, array $recent, bool $errors ): array {
		$requests = Core::num_int( $sums['count'] ?? null );
		return [
			'urls'                => $urls,
			'requests'            => $requests,
			'avg_ms'              => self::mean_of( Core::num_float( $sums['sum_ms'] ?? null ), Core::num_int( $sums['timed_count'] ?? null ) ),
			'avg_peak_mb'         => self::mean_of( Core::num_float( $sums['sum_peak_mb'] ?? null ), $requests ),
			'requests_per_second' => self::recent_rate( $rated, $recent ),
		] + ( $errors ? [ 'errors' => Core::num_int( $sums['errors'] ?? null ) ] : [] );
	}

	/**
	 * A row's `HEADER_SUMS` added onto a header's running sums.
	 *
	 * @param array<string,float> $sums The sums so far.
	 * @param array<string,mixed> $row  A URL row.
	 * @return array<string,float>
	 */
	private static function add_sums( array $sums, array $row ): array {
		foreach ( self::HEADER_SUMS as $field ) {
			$sums[ $field ] = ( $sums[ $field ] ?? 0.0 ) + Core::num_float( $row[ $field ] ?? null );
		}
		return $sums;
	}

	/**
	 * One URL's display row in the given scope, or null when the window
	 * holds none: the reader traffic first, and the worker traffic only when
	 * it has none, so a URL served both ways shows the row the default table
	 * shows, and a job-only URL still opens.
	 *
	 * @param string      $hash   12-char URL hash.
	 * @param string      $server Reporting server to scope to; '' reads every server.
	 * @param Stats_Store $store  The reply's store.
	 * @param int         $now    The reply's clock, read once at its entry.
	 * @return array<string,mixed>|null
	 */
	private static function row( string $hash, string $server, Stats_Store $store, int $now ): ?array {
		[ $from, $to ] = self::window( $now );
		$url           = $store->url_of( $hash, $from, $to );
		if ( null === $url ) {
			return null;
		}
		foreach ( [ false, true ] as $workers ) {
			$servers = self::scope_servers( $store, $server, $workers, $now );
			$row     = $store->url_rows( $servers, $workers, [ $url ], false, $from, $to )[ $url ] ?? null;
			if ( null !== $row ) {
				$recent = self::recent_window( $now );
				$rated  = Core::num_int( $store->url_rows( $servers, $workers, [ $url ], false, ...$recent )[ $url ]['count'] ?? null );
				return self::project_row( $row ) + [ 'requests_per_second' => self::recent_rate( $rated, $recent ) ];
			}
		}
		return null;
	}

	/**
	 * The servers a scope reads: the one named, or every server the names
	 * Ledger holds for the window, worker traffic's too with `$workers`.
	 *
	 * @param Stats_Store $store   The reply's store.
	 * @param string      $server  Reporting server; '' is the site.
	 * @param bool        $workers Keep worker traffic.
	 * @param int         $now     The reply's clock, read once at its entry.
	 * @return list<string>
	 * @throws \RuntimeException When more servers filed rows than a page names.
	 */
	private static function scope_servers( Stats_Store $store, string $server, bool $workers, int $now ): array {
		if ( '' !== $server ) {
			return [ $server ];
		}
		return $store->servers( $workers, ...self::window( $now ) ) ?? throw new \RuntimeException(
			\esc_html( \sprintf( 'more than %d servers filed rows in the window; pick one', Stats_Store::SERVERS_READ_MAX ) )
		);
	}

	/**
	 * One URL row as the dashboard reads it: its hash, which addresses the
	 * URL's requests and blob, its two means, which belong to the reader
	 * (decision 2), and its last completion as `last_updated`.
	 *
	 * @param array<string,mixed> $row A `Stats_Store::url_rows()` row.
	 * @return array<string,mixed>
	 */
	private static function project_row( array $row ): array {
		$url          = Core::as_string( $row['url'] ?? '' );
		$row['hash']  = Log_Manager::url_hash( $url );
		$last_seen    = $row['last_seen'] ?? 0;
		unset( $row['last_seen'] );
		// Two populations: a timeout has peak memory but no duration.
		$row['avg_ms']       = self::mean_of( Core::num_float( $row['sum_ms'] ?? null ), Core::num_int( $row['timed_count'] ?? null ) );
		$row['avg_peak_mb']  = self::mean_of( Core::num_float( $row['sum_peak_mb'] ?? null ), Core::num_int( $row['count'] ?? null ) );
		$row['last_updated'] = $last_seen;
		return $row;
	}

	/**
	 * The window a "recent" rate sums, `[ from, to )`: the current hour's
	 * closed buckets, or the hour before while none of them has closed. The
	 * bucket still filling is left out: counting it drags the figure down by
	 * however much of it has not happened yet.
	 *
	 * @param int $now The reply's clock, read once at its entry.
	 * @return array{0:int,1:int}
	 */
	private static function recent_window( int $now ): array {
		$bucket = Stats_Store::bucket_start( $now );
		$hour   = $now - $now % Stats_Store::HOUR_SECONDS;
		return $bucket > $hour ? [ $hour, $bucket ] : [ $hour - Stats_Store::HOUR_SECONDS, $hour ];
	}

	/**
	 * The mean duration of the timed requests over the chart window, the
	 * wall clock the Time Breakdown divides the board's categories by: the
	 * site's from the totals `overview` already read, and a server's from
	 * its row of the `server` dimension, the one read this costs.
	 *
	 * @param string              $server Server the board is scoped to; '' is the site.
	 * @param array<array-key,mixed> $totals The site's totals over the window.
	 * @param Stats_Store         $store  The reply's store.
	 * @param int                 $from   First second read.
	 * @param int                 $to     The second the read stops short of.
	 */
	private static function board_avg_ms( string $server, array $totals, Stats_Store $store, int $from, int $to ): float {
		if ( '' === $server ) {
			return self::mean_of( Core::num_float( $totals['sum_ms'] ?? null ), Core::num_int( $totals['count'] ?? null ) );
		}
		$row = Core::arr( $store->dimension( Stats_Store::DIM_SERVER, '', $from, $to, false )[ $server ] ?? null );
		return self::mean_of( Core::num_float( $row[ Stats_Store::DIM_SUM_MS ] ?? null ), Core::num_int( $row[ Stats_Store::DIM_TIMED ] ?? null ) );
	}

	/**
	 * This class's page cache, in one spelling: the substrate's read-through
	 * over `URLS_PAGE_NS`, built on a miss and warmed by the table itself.
	 *
	 * The SHAPE rides in the key, because a read-through cannot report a
	 * stored value missing a field this reader needs, and a `provisional`
	 * build, short of a read that went unanswered, states `ttl => 0` —
	 * served, never warmed, or the gap it left would stand for the entry's
	 * whole life. With no cache backend there is no table and
	 * `$build` simply answers.
	 *
	 * Its own namespace rather than a partition's: a page sums every
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
		$url = self::url_in_context( $context, $this->stats_store( [ Stats_Store::LEDGER_NAMES ], true ), $now );
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
			self::rule_for_url( $url['name'] ),
			$url['descriptor']
		);
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no span '{$name}' in this URL's aggregate" ) );
		}
		return $brief;
	}

	/**
	 * The rule governing a URL, for the surfaces that hold no record — the
	 * `url:` brief works from an index row. Matching takes the PATH: a stored
	 * url is absolute, and `Rule_Matcher` compares against patterns like `/`.
	 *
	 * @param string $url The stored absolute URL.
	 * @return ?Rule The governing rule, or null when no pattern matches.
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
		$store = $this->stats_store( [ Stats_Store::LEDGER_NAMES, Stats_Store::LEDGER_LEADERBOARD ], true );
		$url   = self::url_in_context( $context, $store, $now );
		if ( null !== $url && null !== $url['aggregate'] ) {
			$brief = Ask_Assembler::for_url_category( Core::arr( $url['aggregate']['profiles'] ?? null ), $name, $url['name'] );
			if ( null !== $brief ) {
				return $brief;
			}
		}
		// The card this is asked from renders the same scoped board.
		$board      = $store->leaderboard( $server, ...self::chart_window( $now ) );
		$categories = \is_array( $board['categories'] ?? null ) ? $board['categories'] : [];
		$brief      = Ask_Assembler::for_category( $categories, $name, $server );
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no category '{$name}' in this request or the recent window" ) );
		}
		return $brief;
	}

	/**
	 * A store over the Ledgers a verb reads, mounted into the request graph —
	 * read-only, in-process, one catalog read for all of them — and every
	 * partition's url Table when `$blobs`. A mount lives for the rest of the
	 * request, so a later verb in the same POST reads through it (substrate
	 * ADR-23). A Ledger no active topology declares is no store: its reads
	 * answer empty (decision 3).
	 *
	 * A url Table whose backend cannot open here (`Table_Unavailable`) reads
	 * as none, as a missing blob does. Any other refusal is the operator's
	 * to fix, and fails the verb: a mount in a process running as root, a
	 * store two topologies declare differently, a file another declaration
	 * made, a topology that will not read, or a directory the runtime
	 * refuses to adopt.
	 *
	 * @param list<string> $ledgers `Stats_Store::LEDGER_*` names the verb reads.
	 * @param bool         $blobs   Mount every partition's url Table too.
	 * @throws \Throwable Every refusal but a url Table that cannot open.
	 */
	private function stats_store( array $ledgers, bool $blobs ): Stats_Store {
		$url_tables = [];
		if ( $blobs ) {
			try {
				$url_tables = \array_values( Bootstrap::mount_table( [ Stats_Store::TABLE_URL ] )[ Stats_Store::TABLE_URL ] ?? [] );
			} catch ( Table_Unavailable $e ) {
				Core::print_less_often( 'performance: the url Tables did not mount', ' — ' . $e->getMessage() );
			}
		}
		$this->client ??= new Table_Client( $this );
		return new Stats_Store( $this->client, Bootstrap::mount_ledger( $ledgers ), $url_tables );
	}

	/**
	 * The window a chart draws, `[ from, to )`: `Stats_Store::MAX_READ_BUCKETS`
	 * buckets ending with the tick's, whatever the retention.
	 *
	 * @param int $now The reply's clock, read once at its entry.
	 * @return array{0:int,1:int}
	 */
	private static function chart_window( int $now ): array {
		$to = Stats_Store::bucket_start( $now ) + Stats_Store::BUCKET_SECONDS;
		return [ $to - Stats_Store::MAX_READ_BUCKETS * Stats_Store::BUCKET_SECONDS, $to ];
	}

	/**
	 * The URL a context chain names — for a span or a category picked inside
	 * the URL modal, where the chain carries `url:<hash>` and no request — or
	 * null when it names none.
	 *
	 * No index walk: the aggregate blob, null when the URL has none, and then
	 * the URL the names Ledger files the hash under, '' when the window holds
	 * none. The blob lives a 24th of the window where the row lives all of
	 * it, so a URL with no blob is routine, and each caller decides what
	 * answers then; the name is read only once there is a blob to answer
	 * with. The aggregate keeps no per-server split, so no server narrows
	 * this.
	 *
	 * @param list<string> $context Container descriptors.
	 * @param Stats_Store  $store   The reply's store, over the names and the url Tables.
	 * @param int          $now     The reply's clock, read once at its entry.
	 * @return array{descriptor:string, hash:string, name:string, aggregate:?array<array-key,mixed>}|null
	 */
	private static function url_in_context( array $context, Stats_Store $store, int $now ): ?array {
		$descriptor = self::descriptor_of( $context, 'url' );
		$parsed     = Ask_Assembler::parse_descriptor( $descriptor );
		if ( null === $parsed ) {
			return null;
		}
		$aggregate = $store->url_stats( $parsed['id'] );
		return [
			'descriptor' => $descriptor,
			'hash'       => $parsed['id'],
			'name'       => null === $aggregate ? '' : $store->url_of( $parsed['id'], ...self::window( $now ) ) ?? '',
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
	 * The completion time below which a request-index walk can stop reading.
	 *
	 * The floor of `window()`, and nothing else: a request
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
		return self::window( $now )[0];
	}

	/**
	 * The window every URL read reads, `[ from, to )`: the retention's
	 * buckets up to `Stats_Store::MAX_READ_BUCKETS`, ending with the tick's.
	 *
	 * @param int $now The reply's clock, read once at its entry.
	 * @return array{0:int,1:int}
	 */
	private static function window( int $now ): array {
		$buckets = \min( (int) \ceil( AppConfig::stats_retention_seconds() / Stats_Store::BUCKET_SECONDS ) + 1, Stats_Store::MAX_READ_BUCKETS );
		$to      = Stats_Store::bucket_start( $now ) + Stats_Store::BUCKET_SECONDS;
		return [ $to - $buckets * Stats_Store::BUCKET_SECONDS, $to ];
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
	 * The `urls` comparator over `Stats_Store::url_rows()` rows for one rank
	 * and direction, ordered as the Ledger's `TOP` orders them: a column, the
	 * URL (`x`), or a ratio null over a zero denominator; a null ranks last
	 * either way, and a tie breaks by URL, ascending.
	 *
	 * @param string|array{0:string,1:string} $rank  What `order_by()` ranks by.
	 * @param string                          $order 'asc' or 'desc'.
	 */
	private static function by_rank( string|array $rank, string $order ): \Closure {
		$value = static fn ( array $row ): mixed => match ( true ) {
			\is_array( $rank ) => Core::num_float( $row[ $rank[1] ] ?? null ) > 0.0 ? Core::num_float( $row[ $rank[0] ] ?? null ) / Core::num_float( $row[ $rank[1] ] ?? null ) : null,
			'x' === $rank      => $row['url'] ?? null,
			default            => $row[ $rank ] ?? null,
		};
		return static function ( array $a, array $b ) use ( $value, $order ): int {
			[ $x, $y ] = [ $value( $a ), $value( $b ) ];
			$by        = null === $x || null === $y ? ( null === $x ) <=> ( null === $y ) : ( 'asc' === $order ? $x <=> $y : $y <=> $x );
			return $by ?: \strcmp( Core::as_string( $a['url'] ?? '' ), Core::as_string( $b['url'] ?? '' ) );
		};
	}

	/**
	 * Requests per second over the recent window.
	 *
	 * The divisor is the WINDOW, not the buckets that carried traffic:
	 * dividing by the buckets a URL appeared in makes one seen in two of
	 * twelve read six times its rate.
	 *
	 * @param int                $requests Requests counted across `$recent`.
	 * @param array{0:int,1:int} $recent   `recent_window()`.
	 */
	private static function recent_rate( int $requests, array $recent ): float {
		return $requests / \max( 1, $recent[1] - $recent[0] );
	}

	/**
	 * A mean over the things that HAVE one — the requests a duration was
	 * measured for. Dividing by every request instead would understate it
	 * by the unmeasured fraction.
	 *
	 * @param float $sum Summed values.
	 * @param int   $n   How many contributed one.
	 */
	private static function mean_of( float $sum, int $n ): float {
		return $n > 0 ? $sum / $n : 0.0;
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
	 * The buckets a chart draws, newest first, as its axis names them. The
	 * reply names them as `slots`, and the dashboard's axis is exactly these.
	 *
	 * @param int $now The reply's clock, read once at its entry.
	 * @return list<string>
	 */
	private static function chart_slots( int $now ): array {
		return \array_map(
			static fn ( int $back ): string => Stats_Store::bucket_key( $now - $back * Stats_Store::BUCKET_SECONDS ),
			\range( 0, Stats_Store::MAX_READ_BUCKETS - 1 )
		);
	}

	/**
	 * A read keyed by bucket start as the chart keys it, by `bucket_key()`.
	 *
	 * @param array<array-key,mixed> $by_t Bucket start => value.
	 * @return array<string,mixed>
	 */
	private static function by_bucket_key( array $by_t ): array {
		$out = [];
		foreach ( $by_t as $t => $value ) {
			$out[ Stats_Store::bucket_key( (int) $t ) ] = $value;
		}
		return $out;
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
				$now           = self::now();
				[ $from, $to ] = self::chart_window( $now );
				$store         = $self->stats_store( [ Stats_Store::LEDGER_TOTALS, Stats_Store::LEDGER_DIMS, Stats_Store::LEDGER_CATEGORIES, Stats_Store::LEDGER_LEADERBOARD ], false );
				// @longform The SITE's totals, over the slots the charts draw:
				// scoping them and not the rest is how a payload contradicts
				// itself, and every URL-set fact is the `urls` verb's (decision
				// 15). The average duration divides the timed requests
				// (decision 24), the average peak every request.
				$totals  = $store->totals( $from, $to, false );
				$payload = [
					'total_requests'     => Core::num_int( $totals['count'] ?? null ),
					'global_avg_ms'      => self::mean_of( Core::num_float( $totals['sum_ms'] ?? null ), Core::num_int( $totals['count'] ?? null ) ),
					'global_avg_peak_mb' => self::mean_of( Core::num_float( $totals['sum_peak_mb'] ?? null ), Core::num_int( $totals['requests'] ?? null ) ),
					'slots'              => self::chart_slots( $now ),
				];
				$payload['global_leaderboard'] = [
					...$store->leaderboard( $server, $from, $to ),
					'avg_ms' => self::board_avg_ms( $server, $totals, $store, $from, $to ),
				];

				// One key per dimension ASKED for, whatever the count.
				if ( '' !== $breakdown ) {
					$payload['breakdowns'] = [];
					foreach ( \array_map( 'trim', \explode( ',', $breakdown ) ) as $dim ) {
						self::assert_dimension( $dim, self::DIMENSIONS );
						// The server axis is the picker's: it stays the site's.
						$payload['breakdowns'][ $dim ] = self::compact_dim_series( self::by_bucket_key( $store->dimension( $dim, Stats_Store::DIM_SERVER === $dim ? '' : $server, $from, $to, true ) ) );
					}
				}

				if ( $categories ) {
					$payload['category_time_series'] = self::compact_category_series( self::by_bucket_key( $store->categories( $server, $from, $to, true ) ) );
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
				if ( ! \in_array( $order, Stats_Store::URL_ORDERS, true ) ) {
					$order = 'desc';
				}

				\assert( $self instanceof self );
				$now  = self::now();
				$page = $self->url_page( $server, $search, $errors, $workers, $sort, $order, $offset, $limit, $now );

				return [
					'data'    => $page['data'],
					'rows'    => $page['rows'],
					// Zeros for a scope holding no rows, never null.
					'totals'  => $page['totals'],
					'slowest' => $page['slowest'],
					// Short of a read that went unanswered; never cached.
					'provisional' => $page['provisional'],
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
				$now   = self::now();
				$store = $self->stats_store( [ Stats_Store::LEDGER_NAMES, Stats_Store::LEDGER_URL_ROWS, Stats_Store::LEDGER_URL_DIMS, Stats_Store::LEDGER_URL_CATS ], true );
				$entry = self::row( $hash, $server, $store, $now );
				if ( null === $entry ) {
					throw new \RuntimeException( \esc_html( "URL not found: {$hash}" ) );
				}
				$url   = Core::as_string( $entry['url'] );
				$stats = [ 'hash' => $hash ] + \array_intersect_key( $entry, \array_flip( [ 'url', 'count', 'avg_ms', 'min_ms', 'max_ms', 'avg_peak_mb', 'max_peak_mb', 'last_updated', 'requests_per_second' ] ) );

				$deadline  = self::scan_deadline();
				$recent    = self::find_recent_requests_for_url( $hash, $now, $after, $deadline );
				$aggregate = $store->url_stats( $hash )
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

				[ $from, $to ] = self::chart_window( $now );
				if ( '' !== $breakdown ) {
					$payload['breakdown_time_series'] = self::compact_dim_series( self::by_bucket_key( $store->url_breakdown( $url, $breakdown, $from, $to ) ) );
				}

				if ( true === $args['categories'] ) {
					$payload['category_time_series'] = self::compact_category_series( self::by_bucket_key( $store->url_categories( $url, $from, $to ) ) );
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
				// keeps only the series, so it reads two Ledgers and never the
				// index: `dump_url` walks every partition's index to build
				// `requests`, which a breakdown fetch throws away.
				$hash = Core::as_string( $args['hash'] );
				if ( ! \preg_match( '/^[a-f0-9]{8,64}$/D', $hash ) ) {
					throw new \RuntimeException( 'invalid hash format' );
				}
				$breakdown = Core::as_string( $args['breakdown'] );
				self::assert_dimension( $breakdown, self::URL_DIMENSIONS );
				\assert( $self instanceof self );
				$now           = self::now();
				$store         = $self->stats_store( [ Stats_Store::LEDGER_NAMES, Stats_Store::LEDGER_URL_DIMS ], false );
				[ $from, $to ] = self::chart_window( $now );
				$url           = $store->url_of( $hash, ...self::window( $now ) );
				return [
					'breakdown_time_series' => self::compact_dim_series( self::by_bucket_key( null === $url ? [] : $store->url_breakdown( $url, $breakdown, $from, $to ) ) ),
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
