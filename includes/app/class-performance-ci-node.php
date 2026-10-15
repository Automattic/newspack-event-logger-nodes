<?php
/**
 * Performance_CI: command-dispatch for the performance-dashboard surface.
 *
 * Verbs the live surfaces drive:
 *   - overview / urls / dump_url / search_requests / grep_requests /
 *     dump_request — the performance dashboard's per-slice graph
 *     (`src/overview/hooks/usePerformanceGraph.js`), plus the
 *     current-request overlay tab, which fetches `dump_request`.
 *   - query — one question over the stats rows in `Stats_Query`'s
 *     vocabulary, which MCP's `performance_query` fronts.
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
 *  - Stats reads fail soft, as `Stats_Query` and the dashboards' "no data"
 *    state do.
 *  - Stats come from the flame builder's `flame-facts` rows alone.
 *  - Disk scans: a URL's flame rebuild and `grep_requests` stop after
 *    MAX_SCAN_S of walking. A rid lookup walks unbudgeted, so a rid it does
 *    not find is not there.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Config as AppConfig;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Reqgrep_Core;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
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
 *   - fact readers, which answer `overview`, `urls`, `dump_url` and `query`
 *     as `Stats_Query` specs over every partition's `flame-facts` rows;
 *   - disk walkers, which construct throwaway Partition/Consumer nodes over
 *     the DECLARED node dirs and remove them again;
 *   - `set` sanitizers, which bound the values arriving from the hub.
 *
 * @phpstan-import-type Url_Filters from Ask_Assembler
 */
class Performance_CI_Node extends Service_CI_Node {

	/**
	 * Seconds one budgeted walk may spend reading — .idx lines, or the
	 * firehose lines `grep_requests` groups. Two walks take it:
	 * `url_flame()` over the flame index, whose lines carry no time to
	 * compare, and `run_grep_requests()`, whose `recent` window can hold more
	 * than a reply has time to group. A rid lookup takes no budget: it reads
	 * until it finds the rid or runs out of index, so its not-found is
	 * definite.
	 *
	 * Time, not a line count, because partitions are walked one after another:
	 * a count sized to one host's traffic ends a busier hub's walk inside its
	 * first partitions, and every request hashed to the rest is unreachable
	 * from its URL. An index miss is one `substr` + `trim`, with the clock read
	 * once per SCAN_CLOCK_STRIDE lines, so a window of millions of index lines
	 * costs well under a second, and the cap binds only a walk that has
	 * genuinely run long; a grep line is dearer, grouped whole. Peak memory
	 * stays ONE segment's index. A walk that spends it says so; see
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
	 * `gyroscope.p0` while `requests.p{partition}` expands, so any assumption
	 * about the naming scheme is wrong for most of its partitions.
	 */
	private const NODE_FLAMES   = 'flames:partition';
	private const NODE_REQUESTS = 'requests:partition';

	/** Most requests `dump_url` lists. */
	public const RECENT_REQUEST_LIMIT = 500;

	/** `grep_requests` default / max matched-request results (bounds the reply). */
	private const GREP_RESULT_LIMIT_DEFAULT = 20;
	private const GREP_RESULT_LIMIT_MAX     = 50;

	/** `grep_requests` in-flight LRU_Cache geometry (100 × 3 = 300 concurrent rids). */
	private const GREP_INFLIGHT_BUCKET_SIZE = 100;
	private const GREP_INFLIGHT_NUM_BUCKETS = 3;

	/** `grep_requests` history-bucket geometry for the shared grouping engine. */
	private const GREP_HISTORY_BUCKET_SIZE = 250;
	private const GREP_HISTORY_NUM_BUCKETS = 10;

	/** Slowest rows the `urls` reply carries for the Ask brief's examples. */
	private const SLOWEST_ROWS = 10;

	/** Each `urls` sort, as the `Stats_Query` sort a `url` group answers it by. */
	private const URL_SORTS = [
		'count'        => 'count',
		'url'          => 'url_name',
		'avg_ms'       => 'avg_ms',
		'min_ms'       => 'min_ms',
		'max_ms'       => 'max_ms',
		'avg_peak_mb'  => 'avg_peak_mb',
		'last_updated' => 'last_seen',
	];

	/** What a `urls` row measures. */
	private const URL_MEASURES = [ 'count', 'timed', 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx', 'errors', 'sum_ms', 'min_ms', 'max_ms', 'sum_peak_mb', 'max_peak_mb', 'worker', 'last_seen', 'avg_ms', 'avg_peak_mb' ];

	/** Buckets the dashboard's charts draw: 24 hours, newest first. */
	private const CHART_BUCKETS = 288;

	/** Entries past which a leaderboard category keeps only its slowest. */
	private const ENTRY_TRIM_ABOVE = 100;

	/** The slowest entries a trimmed category keeps. */
	private const ENTRY_TRIM_TO = 50;

	/** The aggregate flame of a URL with no requests to fold. */
	private const EMPTY_FLAME = [ 'name' => 'aggregate', 'value' => 0, 'children' => [] ];

	/** Nodes a URL's flame displays, its root included; the lightest go first. */
	private const MAX_URL_FLAME_NODES = 1730;

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
	 * tried first, and the walk takes no budget, so null is a definite miss.
	 *
	 * @param string $rid Request id to match.
	 * @return array<string,mixed>|null Search shape, or null when unmatched.
	 */
	private static function find_request_index_entry( string $rid ): ?array {
		$result = null;
		self::scan_index_entries(
			self::search_order( $rid, self::NODE_REQUESTS ),
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
			},
			null
		);
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
			$request_line = Log_Manager::REQUEST_LINE === $key && '' === $method ? Log_Manager::parse_request_line( $entry['m'] ?? null ) : null;
			if ( null !== $request_line ) {
				// A grep row names the query-less URL, as a record's url does.
				[ $method, , $url ] = $request_line;
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
	 * @param Url_Filters         $filters    The url filters in force, which `overview:` reads whole and `url:` reads for `errors_only` and `bucket`, the list the URL modal holds: every other descriptor names one thing, and a filtered view of one thing is the same thing, so only those two read the selection, or refuse it, and carry its canonical spelling.
	 * @param int                 $now        The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On an unknown descriptor, a missing context, or an absent subject.
	 * @throws \InvalidArgumentException On a selection `overview:` or `url:` refuses.
	 */
	private static function assemble_ask( string $descriptor, array $context, string $server, array $filters, int $now ): array {
		$target = Ask_Assembler::parse_descriptor( $descriptor )
			?? throw new \RuntimeException( \esc_html( "unknown descriptor: {$descriptor}" ) );

		switch ( $target['type'] ) {
			case 'overview':
				return self::ask_overview( $server, $filters, $now );
			case 'url':
				return self::ask_url( $target['id'], $server, $filters, $now );
			case 'request':
				return self::ask_request( $target['id'], self::descriptor_partition( $target ), $context, $server );
			case 'span':
				return self::ask_span( $target['id'], $context, $server, $now );
			case 'entry':
				return self::ask_entry( $target['id'], $context );
			case 'category':
				return self::ask_category( $target['id'], $context, $server, $now );
		}
		throw new \RuntimeException( \esc_html( 'unknown descriptor: ' . $target['type'] ) );
	}

	/**
	 * The `overview:` brief — the dashboard as it is being read: the same
	 * `url_page()` the table asks, with the same filters, for the filtered
	 * set's totals and its top rows, and the scoped leaderboard beside it.
	 * `answer_overview()`'s totals are deliberately NOT used: they ignore the
	 * URL filters, so under a search they would describe a different set
	 * than the one on screen.
	 *
	 * @param string      $server  Server the page is scoped to; '' is every server.
	 * @param Url_Filters $filters The url filters, as the page has them.
	 * @param int         $now     The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 */
	private static function ask_overview( string $server, array $filters, int $now ): array {
		$page              = self::url_page( $server, $filters, 'count', 'desc', 0, self::OVERVIEW_BRIEF_URLS, $now );
		$filters['bucket'] = Core::as_string( $page['filters']['bucket'] );
		return Ask_Assembler::for_overview( $page, self::site_leaderboard( $server, $now ), $server, $filters );
	}

	/**
	 * The `url:` brief — stats, worst recent requests, and the cold-start
	 * finding when nothing governs it, of the requests `dump_url` reads
	 * under the same server, `errors_only` and `bucket`.
	 *
	 * @param string      $hash    12-char URL hash the descriptor names.
	 * @param string      $server  Server the brief answers for; '' is every server.
	 * @param Url_Filters $filters The url filters in force.
	 * @param int         $now     The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When no request row names the hash.
	 * @throws \InvalidArgumentException On a selection `Bucket::selection()` refuses.
	 */
	private static function ask_url( string $hash, string $server, array $filters, int $now ): array {
		$detail            = self::url_detail( $hash, $server, $filters['errors_only'], $filters['bucket'], $now );
		$filters['bucket'] = $detail['bucket'];
		return Ask_Assembler::for_url(
			$detail['stats'],
			$detail['requests'],
			$detail['requests_capped'],
			// Stats that did not open name no URL for a rule to match.
			'' === $detail['stats']['url'] ? null : Rule_Set::load()->for_url( $detail['stats']['url'] ),
			$server,
			$filters
		);
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
	 * in, or the URL whose aggregate flame folds it, rebuilt from the flames
	 * of its newest requests over the chart's buckets, on the server the
	 * modal is scoped to.
	 *
	 * @param string       $name    Span name the descriptor carries.
	 * @param list<string> $context Container descriptors, outermost last.
	 * @param string       $server  Server the URL modal is scoped to; '' is every server.
	 * @param int          $now     The reply's clock.
	 * @return array<string,mixed>
	 * @throws \RuntimeException With neither context, an unknown URL, no flame to read, or an absent span.
	 */
	private static function ask_span( string $name, array $context, string $server, int $now ): array {
		$record = self::request_in_context( $context );
		if ( null !== $record ) {
			$brief = Ask_Assembler::for_span( $record, $name, self::rule_for_record( $record ), self::descriptor_of( $context, 'request' ) );
			if ( null === $brief ) {
				throw new \RuntimeException( \esc_html( "no span '{$name}' in this request" ) );
			}
			return $brief;
		}
		$url = self::url_in_context( $context );
		if ( null === $url ) {
			throw new \RuntimeException( \esc_html( 'a span needs its request or its URL for context' ) );
		}
		$detail = self::url_detail( $url['hash'], $server, false, '', $now );
		if ( ! $detail['available'] ) {
			throw self::stats_outage( $url['hash'] );
		}
		$flame = self::url_flame( $url['hash'], $detail['requests'], $now );
		if ( null === $flame ) {
			throw new \RuntimeException( \esc_html( "no aggregate for URL {$url['hash']}: its flame rebuild ran out of time" ) );
		}
		$named = $detail['stats']['url'];
		$brief = Ask_Assembler::for_url_span( $flame, $name, $named, Rule_Set::load()->for_url( $named ), $url['descriptor'], $server );
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no span '{$name}' in this URL's aggregate" ) );
		}
		return $brief;
	}

	/**
	 * One URL over a selection's buckets, or the chart's: its stats and its
	 * newest requests, worker traffic counted, as `dump_url` answers them,
	 * and whether the list holds fewer than the stats count. Under
	 * `errors_only` the list holds its timeouts and fatals alone and is
	 * capped against its errors; the stats stay the whole URL's. A stats
	 * file that will not open answers zero stats and no list, `available`
	 * false (decision 3).
	 *
	 * @param string $hash        12-char URL hash.
	 * @param string $server      Server to scope to; '' is every server.
	 * @param bool   $errors_only List only the requests that errored.
	 * @param string $spelling    A `Bucket::selection()` spelling; '' for the chart's.
	 * @param int    $now         The reply's clock.
	 * @return array{stats: array{hash:string, url:string, count:mixed, avg_ms:?float, min_ms:?float, max_ms:?float, avg_peak_mb:?float, max_peak_mb:mixed, last_updated:mixed, errors:mixed, requests_per_second:float|int}, requests: list<array<string,mixed>>, requests_capped: bool, bucket: string, available: bool}
	 * @throws \RuntimeException When no request row names the hash.
	 * @throws \InvalidArgumentException On a selection `Bucket::selection()` refuses.
	 */
	private static function url_detail( string $hash, string $server, bool $errors_only, string $spelling, int $now ): array {
		$buckets  = self::window( $spelling, $now );
		$filter   = self::scope( $server, true ) + [ 'url' => [ $hash ] ];
		$answer   = Stats_Query::run(
			[
				'source'  => 'requests',
				'group'   => [ 'url' ],
				'filter'  => $filter,
				'measure' => self::URL_MEASURES,
				'buckets' => $buckets,
			]
		);
		$stats    = self::url_stats( $hash, $answer, $filter, $buckets, $now );
		$requests = $answer['available'] ? self::url_requests( $filter + self::errors_filter( $errors_only ), $buckets ) : [];
		return [
			'stats'           => $stats,
			'requests'        => $requests,
			'requests_capped' => \count( $requests ) < Core::num_int( $errors_only ? $stats['errors'] : $stats['count'] ),
			'bucket'          => '' === $spelling ? '' : Bucket::spelling( $buckets ),
			'available'       => $answer['available'],
		];
	}

	/**
	 * A URL's stats out of its `url`-grouped `URL_MEASURES` answer, as the
	 * `urls` row carries them, with its rate over the seconds the buckets
	 * have run. A URL with no request there answers zero, its means and
	 * extremes null, named by `url_name()`.
	 *
	 * @param string                                                                   $hash    12-char URL hash.
	 * @param array{columns: list<string>, rows: list<list<mixed>>, available: bool} $answer  The URL's answer over `$buckets`.
	 * @param array<string,list<scalar>>                                               $filter  The URL and its scope.
	 * @param list<array{0:int,1:int}>                                                 $buckets The buckets read.
	 * @param int                                                                      $now     The reply's clock.
	 * @return array{hash:string, url:string, count:mixed, avg_ms:?float, min_ms:?float, max_ms:?float, avg_peak_mb:?float, max_peak_mb:mixed, last_updated:mixed, errors:mixed, requests_per_second:float|int}
	 * @throws \RuntimeException When no request row names the hash.
	 */
	private static function url_stats( string $hash, array $answer, array $filter, array $buckets, int $now ): array {
		$rows = self::url_rows( $answer );
		$row  = [] !== $rows ? $rows[0] : [
			'url'          => $answer['available'] ? self::url_name( $hash, $filter, $now ) : '',
			'count'        => 0,
			'avg_ms'       => null,
			'min_ms'       => null,
			'max_ms'       => null,
			'avg_peak_mb'  => null,
			'max_peak_mb'  => 0.0,
			'last_updated' => 0,
			'errors'       => 0,
		];
		return [
			'hash'                => $hash,
			'url'                 => Core::as_string( $row['url'] ),
			'count'               => $row['count'],
			'avg_ms'              => $row['avg_ms'],
			'min_ms'              => $row['min_ms'],
			'max_ms'              => $row['max_ms'],
			'avg_peak_mb'         => $row['avg_peak_mb'],
			'max_peak_mb'         => $row['max_peak_mb'],
			'last_updated'        => $row['last_updated'],
			'errors'              => $row['errors'],
			'requests_per_second' => Core::num_int( $row['count'] ) / self::elapsed_seconds( $buckets, $now ),
		];
	}

	/**
	 * The newest RECENT_REQUEST_LIMIT requests `$filter` selects over
	 * `$buckets`, newest first, each with the log position its record sits
	 * at. A row's `duration_ms` is null where no sample was taken (decision
	 * 24), and its `timestamp` is the second its record says it started.
	 *
	 * @param array<string,list<scalar>> $filter  Requests fields to the values each matches.
	 * @param list<array{0:int,1:int}>   $buckets The buckets read.
	 * @return list<array<string,mixed>>
	 */
	private static function url_requests( array $filter, array $buckets ): array {
		return \array_map(
			static fn ( array $row ): array => [
				'rid'          => $row['rid'],
				'timestamp'    => $row['started_at'],
				'duration_ms'  => $row['ms'],
				'finished_at'  => $row['finished_at'],
				'status_code'  => $row['status'],
				'peak_mb'      => $row['peak_mb'],
				'method'       => $row['method'],
				'error_status' => $row['error'] ?? '-',
				'segment'      => $row['segment'],
				'offset'       => $row['offset'],
				'length'       => $row['length'],
				'partition'    => $row['partition'],
			],
			Stats_Query::newest_requests( $filter, $buckets, self::RECENT_REQUEST_LIMIT )
		);
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
	 * profile on the modal's server, so the context chain decides which board
	 * answers — the global leaderboard describes a different thing entirely.
	 *
	 * A URL with no profile answers from the scoped leaderboard.
	 *
	 * @param string       $name    Category name the descriptor carries.
	 * @param list<string> $context Container descriptors, outermost last.
	 * @param string       $server  Server the URL's profile and the leaderboard
	 *                              fallback answer for; '' is every server.
	 * @param int          $now     The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When no board holds the category, the name is a callback row, or a URL's stats did not open or name it.
	 */
	private static function ask_category( string $name, array $context, string $server, int $now ): array {
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
		$url = self::url_in_context( $context );
		if ( null !== $url ) {
			$scope = self::scope( $server, true );
			$named = self::url_name( $url['hash'], $scope + [ 'url' => [ $url['hash'] ] ], $now );
			if ( '' === $named ) {
				throw self::stats_outage( $url['hash'] );
			}
			$profile = self::url_profile( $url['hash'], $scope, $now );
			$brief   = null === $profile ? null : Ask_Assembler::for_url_category( $profile, $name, $named, $server );
			if ( null !== $brief ) {
				return $brief;
			}
		}
		// The card this is asked from renders the same scoped board.
		$brief = Ask_Assembler::for_category( self::site_leaderboard( $server, $now )['categories'], $name, $server );
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no category '{$name}' in this request or the recent window" ) );
		}
		return $brief;
	}

	/**
	 * The URL a request row in the week the rows keep names `$hash` by,
	 * server and path joined; '' when the stats file will not open, which
	 * is no stats rather than no URL (decision 3).
	 *
	 * @param string                     $hash   12-char URL hash.
	 * @param array<string,list<scalar>> $filter The URL and its scope.
	 * @param int                        $now    The reply's clock.
	 * @throws \RuntimeException When the rows open and none names it.
	 */
	private static function url_name( string $hash, array $filter, int $now ): string {
		$answer = Stats_Query::run(
			[
				'source'  => 'requests',
				'group'   => [ 'url' ],
				'filter'  => $filter,
				'measure' => [ 'count' ],
				'buckets' => Bucket::last( Flame_Facts_Row_Schema::REQUESTS_BUCKETS, $now ),
			]
		);
		if ( ! $answer['available'] ) {
			return '';
		}
		if ( [] === $answer['rows'] ) {
			throw new \RuntimeException( \esc_html( "URL not found: {$hash}" ) );
		}
		return Core::as_string( \array_combine( $answer['columns'], $answer['rows'][0] )['url_name'] );
	}

	/**
	 * The refusal a URL's span or category brief answers when the stats
	 * rows did not open: an outage, never an absent span or category.
	 *
	 * @param string $hash 12-char URL hash.
	 */
	private static function stats_outage( string $hash ): \RuntimeException {
		return new \RuntimeException( \esc_html( "no aggregate for URL {$hash}: the stats rows did not open" ) );
	}

	/**
	 * A URL's category profile over the 25 hours the timings keep, in the
	 * leaderboard's shape, or null over no profiled request.
	 *
	 * @param string                     $hash  12-char URL hash.
	 * @param array<string,list<scalar>> $scope The server scope; worker traffic counts.
	 * @param int                        $now   The reply's clock.
	 * @return array{count:int, total_time:?float, avg_ms:?float, categories:array<array-key,mixed>}|null
	 */
	private static function url_profile( string $hash, array $scope, int $now ): ?array {
		$board = self::leaderboard( $scope + [ 'url' => [ $hash ] ], Bucket::last( Flame_Facts_Row_Schema::TIMINGS_BUCKETS, $now ) );
		return 0 === $board['count'] ? null : $board;
	}

	/**
	 * The `url:` descriptor a context chain carries, and its hash — for a
	 * span or a category picked inside the URL modal, where the chain names
	 * the URL and no request — or null when it names none.
	 *
	 * @param list<string> $context Container descriptors.
	 * @return array{descriptor:string, hash:string}|null
	 */
	private static function url_in_context( array $context ): ?array {
		$descriptor = self::descriptor_of( $context, 'url' );
		$parsed     = Ask_Assembler::parse_descriptor( $descriptor );
		return null === $parsed ? null : [ 'descriptor' => $descriptor, 'hash' => $parsed['id'] ];
	}

	/**
	 * A URL's aggregate flame, folded from the flames its listed requests
	 * stored, in the display shape: the empty tree over no stored flame.
	 *
	 * One walk of the flame index by URL, under MAX_SCAN_S, each hit
	 * folded as the builder folds it, its timing counted where its duration
	 * is. A flame lands in the partition its request did, so the walk visits
	 * only the partitions its listed requests sit in and leaves each once it
	 * holds them all. A walk the deadline cuts short answers null, because a
	 * partial fold would read as the URL's whole flame and be kept. A stored
	 * flame has lost the suffixes that hold a request's same-named siblings
	 * apart, so those merge into one node here.
	 *
	 * @param string                    $hash     12-char URL hash.
	 * @param list<array<string,mixed>> $requests The requests `url_requests()` lists.
	 * @param int                       $now      The reply's clock.
	 * @return array<array-key,mixed>|null
	 */
	private static function url_flame( string $hash, array $requests, int $now ): ?array {
		$listed  = [];
		$missing = [];
		foreach ( $requests as $request ) {
			$listed[ Core::as_string( $request['rid'] ) ] = $request;
			$partition                                    = Core::num_int( $request['partition'] );
			$missing[ $partition ]                        = ( $missing[ $partition ] ?? 0 ) + 1;
		}
		$flame = [
			'name'      => 'aggregate',
			'sum_value' => 0.0,
			'count'     => 0,
			'children'  => [],
		];
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
						$ms    = $request['duration_ms'];
						$flame = self::fold_flame( $flame, $tree, Core::num_float( $ms ), null !== $ms, $now );
					}
					unset( $listed[ $rid ] );
					--$missing[ $partition ];
					if ( [] === $listed ) {
						return false;
					}
					return 0 >= $missing[ $partition ] ? self::SCAN_STOP_PARTITION : null;
				},
				self::scan_deadline()
			);
			if ( $cut ) {
				return null;
			}
		}
		return 0 === Core::num_int( $flame['count'] ?? 0 )
			? self::EMPTY_FLAME
			: self::display_flame( $flame );
	}

	/**
	 * Fold one request's flame into a URL's running one: one more request,
	 * and, when its timing counts, its duration and its tree. Sums, never
	 * means; `display_flame()` divides.
	 *
	 * @param array<array-key,mixed> $flame         The running flame, un-finalized.
	 * @param array<array-key,mixed> $flame_data    One request's tree.
	 * @param float                  $duration_ms   That request's duration.
	 * @param bool                   $record_timing Whether its duration was measured.
	 * @param int                    $stamp         Stamp for the merged nodes, whose hour back is the expiry cutoff.
	 * @return array<array-key,mixed>
	 */
	private static function fold_flame( array $flame, array $flame_data, float $duration_ms, bool $record_timing, int $stamp ): array {
		$flame['count'] = Core::num_int( $flame['count'] ?? null ) + 1;
		if ( $record_timing ) {
			$flame['sum_value'] = Core::num_float( $flame['sum_value'] ?? null ) + $duration_ms;
			$flame['children']  = Flame_Tree::merge_flame_children_incremental( Core::arr( $flame['children'] ?? null ), Core::arr( $flame_data['children'] ?? null ), $stamp );
		}
		return $flame;
	}

	/**
	 * A URL's running flame pruned to `MAX_URL_FLAME_NODES` and finalized to
	 * per-request means.
	 *
	 * @param array<array-key,mixed> $flame The running flame, un-finalized.
	 * @return array<array-key,mixed>
	 */
	private static function display_flame( array $flame ): array {
		$display = Flame_Tree::prune_lightest( $flame, self::MAX_URL_FLAME_NODES );
		Flame_Tree::finalize_flame_node( $display, Core::num_int( $flame['count'] ?? 0 ) );
		return $display;
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
		$order = self::search_order( $rid, self::NODE_REQUESTS );
		// `+` lifts the caller's partition to the front; the rest keep order.
		if ( isset( $order[ $partition ] ) ) {
			$order = [ $partition => $order[ $partition ] ] + $order;
		}
		$record = self::find_request( $order, $rid );
		if ( null === $record ) {
			throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
		}
		return $record;
	}

	/**
	 * The full request body for a rid, from the first of `$dirs` holding it,
	 * with its flame merged in as `flame_data`. The flame builder on partition
	 * N folds every record of requests.pN into flames.pN, so the flame sits on
	 * the request's hashed partition, read first. A miss means the builder has
	 * not reached the record yet, and leaves the body otherwise intact.
	 *
	 * @param array<int,string> $dirs Partition index => dir, in search order.
	 * @param string            $rid  Request id to match.
	 * @return array<array-key,mixed>|null Decoded request body (keys come from the JSON envelope).
	 */
	private static function find_request( array $dirs, string $rid ): ?array {
		$found = self::first_record( $dirs, 'requests', $rid );
		if ( null === $found ) {
			return null;
		}
		[ $entry, $record ] = $found;
		$record['url_hash'] = \trim( Core::as_string( $entry['url_hash'] ?? '' ) );
		$flame              = self::first_record( self::search_order( $rid, self::NODE_FLAMES ), 'flames', $rid );
		if ( null !== $flame ) {
			$record['flame_data'] = $flame[1];
		}
		return $record;
	}

	/**
	 * The first STORED record whose index entry matches, paired with that entry.
	 * One seek, never a log walk: the entry carries segment, offset and length.
	 * The walk takes no budget, so null means no index holds the rid.
	 *
	 * @param array<int,string> $dirs Partition index => dir, in search order.
	 * @param string            $log  Log basename ('requests' | 'flames').
	 * @param string            $rid  Request id the entry must carry.
	 * @return array{0:array<array-key,mixed>,1:array<array-key,mixed>}|null Entry + decoded record.
	 */
	private static function first_record( array $dirs, string $log, string $rid ): ?array {
		$found = null;
		self::scan_index_entries(
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
			null
		);
		return $found;
	}

	/**
	 * Fan a bounded index scan across a set of partition dirs, newest entry
	 * first, handing every entry whose `$field` equals `$match` to `$on_hit`.
	 *
	 * The one boundary MAX_SCAN_S lives at: a caller passing a deadline has
	 * it span the whole fan-out, and the scan ends everywhere the moment it
	 * is spent or `$on_hit` returns false. A null deadline walks unbudgeted,
	 * as a rid lookup does; the parameter has no default, so every caller
	 * says which it is. Each scratch Partition is built, named, formatted and
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
	 * @param array<int,string> $dirs   Partition index => dir, in scan order.
	 * @param string            $log    Log basename ('requests' | 'flames').
	 * @param string            $field  Index-entry field the match compares.
	 * @param string            $match  Value that field must equal, trimmed.
	 * @param callable(array<array-key,mixed>, int, int, Partition_Node): (self::SCAN_STOP_PARTITION|bool|null) $on_hit
	 *        Return false to end the whole fan-out, `SCAN_STOP_PARTITION` to
	 *        finish this partition and carry on with the next, null to continue.
	 * @param float|null        $deadline A `scan_deadline()`; null walks with no budget.
	 * @return bool True when the time budget ended the scan.
	 */
	private static function scan_index_entries( array $dirs, string $log, string $field, string $match, callable $on_hit, ?float $deadline ): bool {
		// Both halves of ONE format: never read an index we didn't write.
		[ $formatter, $parse, $column ] = 'flames' === $log
			? [ 'flame-index', Flame_Builder_Node::parse_flame_index( ... ), Flame_Builder_Node::index_column( $field ) ]
			: [ 'request-index', Request_Builder_Node::parse_request_index( ... ), Request_Builder_Node::index_column( $field ) ];
		$clock = self::scan_clock();
		$spent = false;
		$lines = 0;
		foreach ( $dirs as $p => $dir ) {
			$stopped = false;
			$node    = new Partition_Node();
			self::name_scratch_partition( $node, $log, $p );
			$node->arguments( [ $dir ] );
			// Read-only; the NAME still proves its writer is in the graph.
			if ( null === \Newspack_Nodes\Formatters::resolve( $formatter ) ) {
				$node->remove_node();
				throw new \RuntimeException( \esc_html( "index formatter not registered: {$formatter}" ) );
			}
			$node->scan_index(
				static function ( string $line, int $segment ) use ( &$spent, &$stopped, &$lines, $clock, $deadline, $node, $p, $field, $match, $column, $parse, $on_hit ): ?bool {
					if ( null !== $deadline && 0 === ++$lines % self::SCAN_CLOCK_STRIDE && $clock() > $deadline ) {
						$spent   = true;
						$stopped = true;
						return false;
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
		}
		return false;
	}

	/**
	 * The moment a walk starting now must stop by.
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
	 * A node's partitions to search for `$rid`, its own partition first.
	 *
	 * A rid rides the same hash the whole way: the firehose Topic routes it by
	 * KEY, the worker on that partition consumes it, Request_Builder writes it
	 * to the request partition of the SAME index, and the flame builder there
	 * folds it into the flame partition of that index. So the hash names the
	 * partition outright and the rest of the fan-out is a fallback — needed
	 * because the guess uses the reader's partition count, which lags the
	 * writer's across a re-partition.
	 *
	 * @param string $rid  Request id whose hash names the first partition.
	 * @param string $node The partition node, requests or flames.
	 * @return array<int,string> Partition index => dir, hashed partition first.
	 */
	private static function search_order( string $rid, string $node ): array {
		$dirs = Bootstrap::node_dirs( $node );
		$hit  = Partition_Node::hash_to_partition( $rid, \max( 1, \count( $dirs ) ) );
		if ( ! isset( $dirs[ $hit ] ) ) {
			return $dirs;
		}
		return [ $hit => $dirs[ $hit ] ] + $dirs;
	}

	/**
	 * The `overview` verb's answer over the chart's 288 buckets: the
	 * non-worker requests' totals, their exact distinct URLs, every server
	 * that served one, busiest first, and the leaderboard. A server scopes
	 * the totals and the leaderboard; `servers` stays whole, so a scoped
	 * dashboard can still name the rest.
	 *
	 * @param string $server Server to scope to; '' is every server.
	 * @param int    $now    The reply's clock.
	 * @return array{totals: array<string,mixed>, slots: list<string>, servers: list<mixed>, leaderboard: array<string,mixed>}
	 */
	private static function answer_overview( string $server, int $now ): array {
		$chart   = Bucket::last( self::CHART_BUCKETS, $now );
		$totals  = self::measures(
			Stats_Query::run(
				[
					'source'  => 'requests',
					'group'   => [],
					'filter'  => self::scope( $server, false ),
					'measure' => [ 'count', 'timed', 'avg_ms', 'avg_peak_mb', 'errors', 'urls' ],
					'buckets' => $chart,
				]
			)
		);
		$servers = Stats_Query::run(
			[
				'source'  => 'requests',
				'group'   => [ 'server' ],
				'filter'  => self::scope( '', false ),
				'measure' => [ 'count' ],
				'buckets' => $chart,
				'sort'    => 'count',
				'order'   => 'desc',
			]
		);
		return [
			'totals'      => [
				'requests'    => Core::num_int( $totals['count'] ),
				'timed'       => Core::num_int( $totals['timed'] ),
				'avg_ms'      => Ask_Assembler::measured_figure( $totals['avg_ms'] ),
				'avg_peak_mb' => Ask_Assembler::measured_figure( $totals['avg_peak_mb'] ),
				'errors'      => Core::num_int( $totals['errors'] ),
				'urls'        => Core::num_int( $totals['urls'] ),
			],
			'slots'       => \array_map( Bucket::key( ... ), \range( $chart[0][1], $chart[0][0] ) ),
			'servers'     => \array_column( $servers['rows'], 0 ),
			'leaderboard' => self::site_leaderboard( $server, $now ),
		];
	}

	/**
	 * The non-worker leaderboard over the 25 hours the timings keep, scoped
	 * to `$server` when one is named.
	 *
	 * @param string $server Server to scope to; '' is every server.
	 * @param int    $now    The reply's clock.
	 * @return array{count:int, total_time:?float, avg_ms:?float, categories:array<array-key,mixed>}
	 */
	private static function site_leaderboard( string $server, int $now ): array {
		return self::leaderboard( self::scope( $server, false ), Bucket::last( Flame_Facts_Row_Schema::TIMINGS_BUCKETS, $now ) );
	}

	/**
	 * The category leaderboard over `$buckets` of the timings `$filter`
	 * selects, in the `leaderboard_of()` shape, its `count` the profiled
	 * requests the same filter selects and its `avg_ms` their timed mean
	 * over every request it selects. A category's own row carries its
	 * totals and its entries, each read whole through `timing_rows()`.
	 *
	 * @param array<string,list<scalar>>  $filter  Fields both sources hold.
	 * @param list<array{0:int,1:int}>    $buckets The board's buckets.
	 * @return array{count:int, total_time:?float, avg_ms:?float, categories:array<array-key,mixed>}
	 */
	private static function leaderboard( array $filter, array $buckets ): array {
		$timings      = [
			'source'  => 'timings',
			'filter'  => $filter,
			'measure' => [ 'ms', 'calls', 'requests' ],
			'buckets' => $buckets,
		];
		$sums         = [];
		$sum_req_time = 0.0;
		$categories   = self::timing_rows( [ 'category', 'listener' ], [ 'filter' => $filter + [ 'entry' => [ '' ] ] ] + $timings );
		foreach ( $categories as [ $category, $listener, $ms, $calls, $requests ] ) {
			// A listener's time already counts inside its hook's.
			$sum_req_time += 0 === $listener ? Core::num_float( $ms ) : 0.0;
			$sums[ Core::as_string( $category ) ] = [
				'samples'   => $requests,
				'sum_time'  => $ms,
				'sum_count' => $calls,
				'entries'   => [],
			];
		}
		foreach ( self::timing_rows( [ 'category', 'entry' ], $timings ) as [ $category, $entry, $ms, $calls, $requests ] ) {
			$category = Core::as_string( $category );
			if ( '' !== $entry && isset( $sums[ $category ] ) ) {
				$sums[ $category ]['entries'][ Core::as_string( $entry ) ] = [ $ms, $calls, $requests ];
			}
		}
		$count = 0;
		$timed = 0;
		$ms    = 0.0;
		$runs  = Stats_Query::run(
			[
				'source'  => 'requests',
				'group'   => [ 'profiled' ],
				'filter'  => $filter,
				'measure' => [ 'count', 'timed', 'sum_ms' ],
				'buckets' => $buckets,
			]
		);
		foreach ( $runs['rows'] as [ $profiled, $requests, $timed_requests, $sum_ms ] ) {
			$count += 1 === $profiled ? Core::num_int( $requests ) : 0;
			$timed += Core::num_int( $timed_requests );
			$ms    += Core::num_float( $sum_ms );
		}
		$board = self::leaderboard_of( $sums, $count, $sum_req_time );
		return [
			'count'      => $board['count'],
			'total_time' => $board['total_time'],
			'avg_ms'     => Ask_Assembler::mean( $ms, $timed ),
			'categories' => $board['categories'],
		];
	}

	/**
	 * Every row of a timings spec grouped by `$group`, dearest first, a page
	 * of `Stats_Query::MAX_LIMIT` at a time until a page comes back short.
	 * A board deeper than the farthest page a query may start says so once
	 * a window and answers what it read, the cheapest rows left out.
	 *
	 * @param list<string>                                                                                                          $group   The fields to group by.
	 * @param array{source:string, filter:array<string,list<scalar|null>>, measure:list<string>, buckets:list<array{0:int,1:int}>} $timings The timings spec, less its group, sort and page.
	 * @return list<list<mixed>>
	 */
	private static function timing_rows( array $group, array $timings ): array {
		$rows = [];
		for ( $offset = 0; ; $offset += Stats_Query::MAX_LIMIT ) {
			$page = Stats_Query::run(
				[
					'group'  => $group,
					'sort'   => 'ms',
					'order'  => 'desc',
					'limit'  => Stats_Query::MAX_LIMIT,
					'offset' => $offset,
				] + $timings
			)['rows'];
			\array_push( $rows, ...$page );
			if ( \count( $page ) < Stats_Query::MAX_LIMIT ) {
				return $rows;
			}
			if ( $offset + Stats_Query::MAX_LIMIT > Stats_Query::MAX_OFFSET ) {
				Core::print_less_often( 'performance: leaderboard rows past the offset bound', ' — kept the ' . \count( $rows ) . ' dearest' );
				return $rows;
			}
		}
	}

	/**
	 * Summed leaderboard data in the display shape `RequestProfile` and
	 * `Ask_Assembler` read.
	 *
	 *  - `time`  = sum_time  / count — mean exclusive category time per request.
	 *  - `count` = sum_count / count — mean calls per request.
	 *  - entries are per-appearance means (sum / samples).
	 *
	 * Over no profiled request each mean is null, as `Ask_Assembler::mean()`
	 * answers. An entry whose sample count is zero is dropped rather than
	 * divided. Past `ENTRY_TRIM_ABOVE` entries a category keeps only its
	 * `ENTRY_TRIM_TO` slowest, ranked by average exclusive time, so one pathological
	 * category cannot flood a payload.
	 *
	 * @param array<array-key,mixed> $sums         Per category: `samples`, `sum_time`, `sum_count`, and `entries` of `[ ms, calls, samples ]`.
	 * @param int                    $count        Profiled requests.
	 * @param float                  $sum_req_time Their summed profiled time, listeners left out.
	 * @return array{count:int, total_time:?float, categories:array<array-key,mixed>}
	 */
	public static function leaderboard_of( array $sums, int $count, float $sum_req_time ): array {
		$categories = [];
		foreach ( $sums as $category => $data ) {
			$data    = Core::arr( $data );
			$entries = [];
			foreach ( Core::arr( $data['entries'] ?? null ) as $name => $entry ) {
				$entry   = Core::arr( $entry );
				$samples = Core::num_int( $entry[2] ?? null );
				if ( $samples > 0 ) {
					$entries[ $name ] = [ Core::num_float( $entry[0] ?? null ) / $samples, Core::num_float( $entry[1] ?? null ) / $samples, $samples ];
				}
			}
			if ( \count( $entries ) > self::ENTRY_TRIM_ABOVE ) {
				\uasort( $entries, static fn ( array $a, array $b ): int => $b[0] <=> $a[0] );
				$entries = \array_slice( $entries, 0, self::ENTRY_TRIM_TO, true );
			}
			$categories[ $category ] = [
				'time'    => Ask_Assembler::mean( Core::num_float( $data['sum_time'] ?? null ), $count ),
				'count'   => Ask_Assembler::mean( Core::num_float( $data['sum_count'] ?? null ), $count ),
				'samples' => Core::num_int( $data['samples'] ?? null ),
				'entries' => $entries,
			];
		}
		return [
			'count'      => $count,
			'total_time' => Ask_Assembler::mean( $sum_req_time, $count ),
			'categories' => $categories,
		];
	}

	/**
	 * One page of the URL set the filters leave, over the last 288 buckets
	 * or the selected ones: its rows, its exact totals and its slowest ten,
	 * every mean request-weighted. `rows` is the exact count of URLs
	 * matching, the pager's. Worker traffic is left out unless asked for,
	 * and errors-only keeps the timeouts and fatals, whose count the totals
	 * then carry.
	 *
	 * @param string      $server  Server to scope to; '' is every server.
	 * @param Url_Filters $filters The url filters in force.
	 * @param string      $sort    A `URL_SORTS` key.
	 * @param string      $order   'asc' or 'desc'.
	 * @param int         $offset  Page offset, inside `Stats_Query`'s bounds.
	 * @param int         $limit   Page size, inside `Stats_Query`'s bounds.
	 * @param int         $now     The reply's clock.
	 * @return array{data: list<array<string,mixed>>, rows: int, totals: array<string,mixed>, slowest: list<array<string,mixed>>, filters: array<string,mixed>, limit: int, offset: int}
	 * @throws \InvalidArgumentException On a selection `Bucket::selection()` refuses or a search with no word.
	 */
	private static function url_page( string $server, array $filters, string $sort, string $order, int $offset, int $limit, int $now ): array {
		$buckets = self::window( $filters['bucket'], $now );
		$filter  = self::scope( $server, $filters['include_workers'] ) + self::errors_filter( $filters['errors_only'] );
		$base    = [
			'source'  => 'requests',
			'filter'  => $filter,
			'buckets' => $buckets,
			'search'  => $filters['search'],
		];
		$totals  = self::measures( Stats_Query::run( $base + [ 'group' => [], 'measure' => [ 'count', 'avg_ms', 'avg_peak_mb', 'urls', 'errors' ] ] ) );
		$rows    = [ 'group' => [ 'url' ], 'measure' => self::URL_MEASURES ] + $base;
		$page    = Stats_Query::run( $rows + [ 'sort' => self::URL_SORTS[ $sort ], 'order' => $order, 'limit' => $limit, 'offset' => $offset ] );
		$slowest = Stats_Query::run( $rows + [ 'sort' => 'avg_ms', 'order' => 'desc', 'limit' => self::SLOWEST_ROWS ] );
		$count   = Core::num_int( $totals['count'] );
		return [
			'data'    => self::url_rows( $page ),
			'rows'    => Core::num_int( $totals['urls'] ),
			'totals'  => [
				'urls'                => Core::num_int( $totals['urls'] ),
				'requests'            => $count,
				'avg_ms'              => Ask_Assembler::measured_figure( $totals['avg_ms'] ),
				'avg_peak_mb'         => Ask_Assembler::measured_figure( $totals['avg_peak_mb'] ),
				'requests_per_second' => $count / self::elapsed_seconds( $buckets, $now ),
			] + ( $filters['errors_only'] ? [ 'errors' => Core::num_int( $totals['errors'] ) ] : [] ),
			'slowest' => self::url_rows( $slowest ),
			'filters' => [
				'server'          => $server,
				'search'          => $filters['search'],
				'errors_only'     => $filters['errors_only'],
				'include_workers' => $filters['include_workers'],
				'bucket'          => '' === $filters['bucket'] ? '' : Bucket::spelling( $buckets ),
			],
			'limit'   => $limit,
			'offset'  => $offset,
		];
	}

	/**
	 * The filter a server scope and the worker choice name.
	 *
	 * @param string $server  Server to scope to; '' is every server.
	 * @param bool   $workers Keep worker requests; false leaves them out.
	 * @return array<string,list<scalar>>
	 */
	private static function scope( string $server, bool $workers ): array {
		return ( $workers ? [] : [ 'worker' => [ 0 ] ] ) + ( '' === $server ? [] : [ 'server' => [ $server ] ] );
	}

	/**
	 * The filter `errors_only` adds: the timeouts and fatals alone.
	 *
	 * @param bool $errors_only Whether only errors are read.
	 * @return array<string,list<string>>
	 */
	private static function errors_filter( bool $errors_only ): array {
		return $errors_only ? [ 'error' => Flame_Builder_Node::ERROR_KINDS ] : [];
	}

	/**
	 * A `url`-grouped answer's rows as the `urls` reply carries them, means
	 * null where nothing was measured.
	 *
	 * @param array{columns: list<string>, rows: list<list<mixed>>} $answer A `Stats_Query::run()` answer grouped by `url` over `URL_MEASURES`.
	 * @return list<array{hash:mixed,url:mixed,count:mixed,timed_count:mixed,count_2xx:mixed,count_3xx:mixed,count_4xx:mixed,count_5xx:mixed,errors:mixed,sum_ms:mixed,min_ms:?float,max_ms:?float,sum_peak_mb:mixed,max_peak_mb:mixed,worker:bool,last_updated:mixed,avg_ms:?float,avg_peak_mb:?float}>
	 */
	private static function url_rows( array $answer ): array {
		$rows = [];
		foreach ( $answer['rows'] as $values ) {
			$row    = \array_combine( $answer['columns'], $values );
			$rows[] = [
				'hash'         => $row['url'],
				'url'          => $row['url_name'],
				'count'        => $row['count'],
				'timed_count'  => $row['timed'],
				'count_2xx'    => $row['count_2xx'],
				'count_3xx'    => $row['count_3xx'],
				'count_4xx'    => $row['count_4xx'],
				'count_5xx'    => $row['count_5xx'],
				'errors'       => $row['errors'],
				'sum_ms'       => $row['sum_ms'],
				'min_ms'       => Ask_Assembler::measured_figure( $row['min_ms'] ),
				'max_ms'       => Ask_Assembler::measured_figure( $row['max_ms'] ),
				'sum_peak_mb'  => $row['sum_peak_mb'],
				'max_peak_mb'  => $row['max_peak_mb'],
				'worker'       => 1 === $row['worker'],
				'last_updated' => $row['last_seen'],
				'avg_ms'       => Ask_Assembler::measured_figure( $row['avg_ms'] ),
				'avg_peak_mb'  => Ask_Assembler::measured_figure( $row['avg_peak_mb'] ),
			];
		}
		return $rows;
	}

	/**
	 * The seconds `$buckets` have run: each bucket's 300, the current one's
	 * only so far, and at least one, so a rate never divides by zero.
	 *
	 * @param list<array{0:int,1:int}> $buckets Ascending bucket ranges.
	 * @param int                      $now     The reply's clock.
	 */
	private static function elapsed_seconds( array $buckets, int $now ): int {
		$seconds = 0;
		foreach ( $buckets as [ $first, $last ] ) {
			$seconds += ( $last - $first + 1 ) * Bucket::SECONDS;
		}
		$unrun = \in_array( Bucket::id( $now ), \array_column( $buckets, 1 ), true ) ? Bucket::SECONDS - $now % Bucket::SECONDS : 0;
		return \max( 1, $seconds - $unrun );
	}

	/**
	 * An ungrouped answer's one row by column, every column null where the
	 * stats rows did not open and no row came back.
	 *
	 * @param array{columns: list<string>, rows: list<list<mixed>>} $answer A `Stats_Query::run()` answer with no group.
	 * @return array<string,mixed>
	 */
	private static function measures( array $answer ): array {
		return \array_combine( $answer['columns'], $answer['rows'][0] ?? \array_fill( 0, \count( $answer['columns'] ), null ) );
	}

	/**
	 * The `query` verb's answer: its tokens as a `Stats_Query` spec, each
	 * filter token one value and a field's tokens ORed, the bucket column
	 * spelled as keys, and what the rows are OF.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @param int                    $now  The reply's clock.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a bucket selection `Bucket::selection()`
	 *                                   refuses, a filter that is no
	 *                                   `field=value`, or a spec `run()` refuses.
	 */
	private static function answer_query( array $args, int $now ): array {
		$spelling = Core::as_string( $args['bucket'] );
		$buckets  = self::window( $spelling, $now );
		$filter   = [];
		foreach ( Core::arr( $args['filter'] ) as $token ) {
			$token = Core::as_string( $token );
			$parts = \explode( '=', $token, 2 );
			if ( 2 !== \count( $parts ) ) {
				throw new \InvalidArgumentException( \esc_html( "filter {$token} is no field=value" ) );
			}
			$filter[ $parts[0] ][] = $parts[1];
		}
		$by_id = $filter;
		if ( isset( $by_id['bucket'] ) ) {
			$by_id['bucket'] = \array_map( Bucket::of_key( ... ), $by_id['bucket'] );
		}
		$search = Core::as_string( $args['search'] );
		$spec   = [
			'source'  => Core::as_string( $args['source'] ),
			'group'   => \array_values( \array_map( Core::as_string( ... ), Core::arr( $args['group'] ) ) ),
			'filter'  => $by_id,
			'measure' => \array_values( \array_map( Core::as_string( ... ), Core::arr( $args['measure'] ) ) ),
			'buckets' => $buckets,
			'sort'    => Core::as_string( $args['sort'] ),
			'order'   => Core::as_string( $args['order'] ),
			'search'  => $search,
		];
		foreach ( [ 'limit', 'offset', 'top' ] as $name ) {
			if ( null !== $args[ $name ] ) {
				$spec[ $name ] = Core::as_int( $args[ $name ] );
			}
		}
		if ( null !== $args['top_by'] ) {
			$spec['top_by'] = Core::as_string( $args['top_by'] );
		}
		$answer = Stats_Query::run( $spec );
		$at = \array_search( 'bucket', $answer['columns'], true );
		if ( false !== $at ) {
			foreach ( $answer['rows'] as $i => $row ) {
				$answer['rows'][ $i ][ $at ] = Bucket::key( Core::as_int( $row[ $at ] ) );
			}
		}
		return $answer + [
			'filters' => [
				'filter' => $filter,
				'bucket' => Bucket::spelling( $buckets ),
				'search' => $search,
			],
		];
	}

	/**
	 * The buckets a selection's spelling names, or the chart's when it
	 * names none.
	 *
	 * @param string $spelling A `Bucket::selection()` spelling; '' for the chart's.
	 * @param int    $now      The reply's clock.
	 * @return list<array{0:int,1:int}>
	 * @throws \InvalidArgumentException On a selection `Bucket::selection()` refuses.
	 */
	private static function window( string $spelling, int $now ): array {
		return '' === $spelling ? Bucket::last( self::CHART_BUCKETS, $now ) : Bucket::selection( $spelling, $now );
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
	 * Schema-driven dispatch: each verb is declared once in
	 * `commands[]` carrying its `handler`. The inherited Service_CI_Node ctor
	 * builds the commands table from this schema. Stats-reading verbs ask
	 * `Stats_Query`, which answers no rows when the stats file will not
	 * open. Disk-walking verbs work regardless.
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
					'description' => 'The site over the chart\'s 288 buckets: non-worker totals, the servers seen, busiest first, and the 25-hour category leaderboard. `server` scopes the totals and the leaderboard, never `servers`; chart series come from `query`.',
					'args'        => [
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				return self::answer_overview( Core::as_string( $args['server'] ), self::now() );
					},
				],
				[
					'name'        => 'urls',
					'capability'  => Capabilities::READ,
					'description' => 'Paginated, sortable URL table with exact totals and the slowest ten, every mean request-weighted. Where no timed request reached a row, its `avg_ms`, `min_ms` and `max_ms` are null, and it ranks last on those three sorts in either order.',
					'args'        => [
						[ 'name' => 'sort', 'type' => 'string', 'required' => false, 'default' => 'count' ],
						[ 'name' => 'order', 'type' => 'string', 'required' => false, 'default' => 'desc' ],
						[ 'name' => 'limit', 'type' => 'int', 'required' => false, 'default' => 50 ],
						[ 'name' => 'offset', 'type' => 'int', 'required' => false, 'default' => 0 ],
						[ 'name' => 'search', 'type' => 'string', 'required' => false ],
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						[ 'name' => 'errors_only', 'type' => 'bool', 'required' => false, 'default' => false ],
						[ 'name' => 'include_workers', 'type' => 'bool', 'required' => false, 'default' => false ],
						[ 'name' => 'bucket', 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'A selection of five-minute buckets as `Bucket::selection()` reads one: comma-separated runs, each a `Y-m-d-H-i` UTC key or `start..end` inclusive, at most 288 buckets, none later than the current one. Narrows every row, the totals and the rate to the requests completing in them; empty is the last 288. `filters.bucket` echoes the canonical spelling.' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$sort  = Core::as_string( $args['sort'] );
				$order = Core::as_string( $args['order'] );
				return self::url_page(
					Core::as_string( $args['server'] ),
					[
						'search'          => \trim( Core::as_string( $args['search'] ) ),
						'errors_only'     => true === $args['errors_only'],
						// Opts IN: the default EXCLUDES. See decision 15.
						'include_workers' => true === $args['include_workers'],
						'bucket'          => Core::as_string( $args['bucket'] ),
					],
					isset( self::URL_SORTS[ $sort ] ) ? $sort : 'count',
					'asc' === $order ? 'asc' : 'desc',
					\min( Stats_Query::MAX_OFFSET, \max( 0, Core::as_int( $args['offset'] ) ) ),
					\min( Stats_Query::MAX_LIMIT, \max( 1, Core::as_int( $args['limit'] ) ) ),
					self::now()
				);
					},
				],
				[
					'name'        => 'query',
					'capability'  => Capabilities::READ,
					'description' => 'One question over the request and timing rows, in `Stats_Query`\'s vocabulary: group by fields, filter on them and answer measures over a bucket selection. Answers `{ source, table, columns, rows, groups, filters }`, each row positional in `columns` order, each bucket spelled as its key; no rows when the stats file will not open. Worker requests count unless filtered `worker=0`. Under `top` the rows are every time row of the `top` tuples of the non-time groups `top_by` ranks highest, in rank order, and `groups` counts the tuples; with no `top`, `groups` is null.',
					'args'        => [
						[ 'name' => 'source', 'type' => 'string', 'required' => true ],
						[ 'name' => 'group', 'type' => 'string', 'required' => false, 'variadic' => true ],
						[ 'name' => 'filter', 'type' => 'string', 'required' => false, 'variadic' => true, 'description' => '`field=value`, one value a token; tokens naming one field match any of their values; a bucket by its key.' ],
						[ 'name' => 'measure', 'type' => 'string', 'required' => false, 'variadic' => true ],
						[ 'name' => 'bucket', 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'A selection as `Bucket::selection()` reads one; empty is the last 288 buckets.' ],
						[ 'name' => 'search', 'type' => 'string', 'required' => false, 'default' => '' ],
						[ 'name' => 'sort', 'type' => 'string', 'required' => false, 'default' => '' ],
						[ 'name' => 'order', 'type' => 'string', 'required' => false, 'default' => 'asc' ],
						[ 'name' => 'limit', 'type' => 'int', 'required' => false, 'description' => 'Most rows, up to `Stats_Query::MAX_LIMIT`, which is what an absent limit answers; refused under `top`.' ],
						[ 'name' => 'offset', 'type' => 'int', 'required' => false, 'description' => 'Rows to skip, none when absent; refused under `top`.' ],
						[ 'name' => 'top', 'type' => 'int', 'required' => false, 'description' => 'Rank the tuples of the non-time groups and answer the time rows of this many, at most `Stats_Query::MAX_TOP`; the group needs a time field and a non-time field.' ],
						[ 'name' => 'top_by', 'type' => 'string', 'required' => false, 'description' => 'The measure `top` ranks by, summed over the selection, highest first, ties by the tuple\'s values: one that adds across buckets — for `requests` `count`, `timed`, `sum_ms`, `sum_peak_mb`, `errors`, `count_2xx`, `count_3xx`, `count_4xx` or `count_5xx`, for `timings` `ms`, `calls` or `requests`.' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				return self::answer_query( $args, self::now() );
					},
				],
				[
					'name'        => 'dump_url',
					'capability'  => Capabilities::READ,
					'description' => 'One URL from its request rows, worker traffic counted: `stats` over the last 288 buckets or a `bucket` selection, its newest RECENT_REQUEST_LIMIT requests by `finished_at` with each one\'s start and log position, `requests_capped` true when the list holds fewer requests than the stats count (than its errors under `errors_only`), the aggregate flame rebuilt from their stored flames, and `aggregate_profiles`, its 25-hour category profile, null over no profiled request. `server` scopes the stats, the list and the profile. A stats file that will not open answers zero stats, no list and a null flame and profile. Each row\'s `duration_ms` is null where no sample was taken; `avg_ms`, `min_ms`, `max_ms` and `avg_peak_mb` are null where nothing was measured. Series come from `query` filtered to the URL.',
					'args'        => [
						[ 'name' => 'hash', 'type' => 'string', 'required' => true ],
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						[ 'name' => 'errors_only', 'type' => 'bool', 'required' => false, 'default' => false, 'description' => 'Lists only the timeouts and fatals, the newest RECENT_REQUEST_LIMIT of them, and rebuilds no flame from them; the stats stay the whole URL\'s.' ],
						[ 'name' => 'bucket', 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'A selection of five-minute buckets, as `urls` takes it: the stats and the list become the requests completing in them, zero where the URL has none, and no flame is rebuilt from the list. A selection `urls` refuses is refused.' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$hash = Core::as_string( $args['hash'] );
				if ( ! \preg_match( '/^[a-f0-9]{8,64}$/D', $hash ) ) {
					throw new \RuntimeException( 'invalid hash format' );
				}
				// @longform The row that opened this modal was the selected
				// server's, so this answers for the same server — otherwise one
				// click puts a site-wide average under a scoped count, on two
				// surfaces too far apart to compare.
				$server      = Core::as_string( $args['server'] );
				$errors_only = true === $args['errors_only'];
				$spelling    = Core::as_string( $args['bucket'] );
				$now         = self::now();
				$detail      = self::url_detail( $hash, $server, $errors_only, $spelling, $now );
				return [
					'stats'              => $detail['stats'],
					'requests'           => $detail['requests'],
					'requests_capped'    => $detail['requests_capped'],
					// A narrowed list's flame would not be the URL's.
					'aggregate_flame'    => $detail['available'] && ! $errors_only && '' === $spelling ? self::url_flame( $hash, $detail['requests'], $now ) : null,
					'aggregate_profiles' => self::url_profile( $hash, self::scope( $server, true ), $now ),
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
					'description' => 'Assemble the brief for one picker descriptor: `ask <descriptor> [<context-descriptor>…]`, outermost context last. `errors_only` narrows an `overview:` and a `url:` brief to errors, and `bucket` to a selection of stored buckets.',
					'args'        => [
						[ 'name' => 'descriptor', 'type' => 'string', 'required' => true ],
						// The trailing descriptors, or `--context=` repeated.
						[ 'name' => 'context', 'type' => 'string', 'required' => false, 'variadic' => true ],
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						// Overview briefs read all four; URL briefs two.
						[ 'name' => 'search', 'type' => 'string', 'required' => false ],
						[ 'name' => 'errors_only', 'type' => 'bool', 'required' => false, 'default' => false, 'description' => 'Narrows an `overview:` brief as `urls` narrows it, and a `url:` brief to the timeouts and fatals the URL modal lists under Errors Only: their exact count and their summary in place of the whole URL\'s stats.' ],
						[ 'name' => 'include_workers', 'type' => 'bool', 'required' => false, 'default' => false ],
						[ 'name' => 'bucket', 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'Narrows an `overview:` brief to a selection of buckets as `urls` narrows it, and a `url:` brief as `dump_url` does, to the requests completing inside them, the brief carrying the canonical spelling. Every other descriptor ignores it, and neither checks nor refuses it.' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				return self::assemble_ask(
					Core::as_string( $args['descriptor'] ),
					\array_values( \array_map( Core::as_string( ... ), Core::arr( $args['context'] ) ) ),
					Core::as_string( $args['server'] ),
					[
						'search'          => Core::as_string( $args['search'] ),
						'errors_only'     => true === $args['errors_only'],
						'include_workers' => true === $args['include_workers'],
						'bucket'          => Core::as_string( $args['bucket'] ),
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
					if ( $changed ) {
						AppConfig::reset();
					}
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
				// re-parses every .tsl for the same answer. Compare
				// in the option's own type: the options table
				// hands a bool back as '1' or ''.
				$stored = \get_option( $option, null );
				$held   = \is_scalar( $stored ) ? self::sanitize_settings_value( Core::as_string( $stored ), $type ) : $stored;
				if ( $held === $sanitized ) {
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
