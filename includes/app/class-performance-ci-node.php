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
 *     hub→spoke fanout. `hub-control.tsl` maps the ruleset option to this
 *     `performance` node, and `set` refuses any other.
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
 *  - Stats come from the stats builder's Flame_Facts rows alone.
 *  - Disk reads: a request is found by its row, its record read at the
 *    place the row names, and flames located by rid through
 *    `Partition_Node::locate_by()`, which stops once it holds every rid or
 *    reaches a segment closed before they finished (`index_mtimes()`).
 *    The flame lookups and `grep_requests` stop after MAX_SCAN_S of reading,
 *    and a cut flame lookup answers `flame_cut` true.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Config as AppConfig;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Overview_Prefetch_Tick_Node;
use Newspack_Event_Logger_Nodes\Owner;
use Newspack_Event_Logger_Nodes\Reqgrep_Core;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Callback_Node;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Driver_Error;
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
 *     as `Stats_Query` specs over every partition's Flame_Facts rows;
 *   - disk walkers, which construct throwaway Partition/Consumer nodes over
 *     the DECLARED node dirs and remove them again;
 *   - `set` sanitizers, which bound the values arriving from the hub.
 *
 * @phpstan-import-type Url_Filters from Ask_Assembler
 * @phpstan-type Context_Entry array{type:string, id:string, descriptor:string}
 */
class Performance_CI_Node extends Service_CI_Node {

	/**
	 * Seconds one budgeted walk may spend reading: the flame index a URL's
	 * rebuild or a request's flame lookup reads back to the segment closed
	 * before its requests finished, which on a busy partition holds more
	 * than a reply has time for, and the firehose lines `run_grep_requests()`
	 * groups, whose `recent` window can hold more than that too. Time,
	 * not a line count, because partitions are read one after another: a
	 * count sized to one host's traffic ends a busier hub's read inside its
	 * first partitions. A walk that spends it says so: a null flame, or
	 * `truncated`.
	 */
	public const MAX_SCAN_S = 10;

	/**
	 * Lines a budgeted walk reads between two looks at the clock, which keeps
	 * the clock off the walk's cost, far below anything a 10-second budget
	 * can notice.
	 */
	private const SCAN_CLOCK_STRIDE = 100;

	/**
	 * Page rows an `overview:` brief lists: exactly what the brief keeps, so
	 * raising one raises both, and each page of several shapes costs one read
	 * for its busiest. The rest is a pointer to `performance_urls`.
	 */
	private const OVERVIEW_BRIEF_PAGES = Ask_Assembler::TOP_SPANS;

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

	/** Most listed requests a URL's aggregate flame folds, newest first. */
	public const FLAME_REQUEST_LIMIT = 100;

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
	public const SLOWEST_ROWS = 10;

	/** Each `urls` sort, as the `Stats_Query` sort a `url` group answers it by. */
	public const URL_SORTS = [
		'count'        => 'count',
		'url'          => 'url_name',
		'avg_ms'       => 'avg_ms',
		'min_ms'       => 'min_ms',
		'max_ms'       => 'max_ms',
		'avg_peak_mb'  => 'avg_peak_mb',
		'last_updated' => 'last_seen',
	];

	/** Each `urls` sort, as the `Stats_Query` sort a `page` group answers it by. */
	public const PAGE_SORTS = [ 'url' => 'page_name' ] + self::URL_SORTS;

	/** The `urls` args a snapshot listing varies; every other stays at its default. */
	private const SNAPSHOT_VARIES = [ 'server', 'page', 'include_workers', 'sort', 'order', 'offset', 'limit' ];

	/** What a `urls` listing totals, ungrouped, live and in the snapshot. */
	public const URL_TOTALS = [ 'count', 'avg_ms', 'avg_peak_mb', 'urls', 'errors', 'pages' ];

	/** What one URL's stats read beyond `URL_MEASURES`, which no snapshot keeps. */
	private const URL_DETAIL_EXTRAS = [ 'p50_ms', 'p95_ms', 'p99_ms', 'error_rate' ];

	/** What a `urls` row measures. */
	public const URL_MEASURES = [ 'count', 'timed', 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx', 'errors', 'sum_ms', 'min_ms', 'max_ms', 'sum_peak_mb', 'max_peak_mb', 'worker', 'last_seen', 'avg_ms', 'avg_peak_mb' ];

	/** The `filter` arg `query`, `urls`, `dump_url` and `ask` each declare. */
	private const FILTER_ARG = [ 'name' => 'filter', 'type' => 'string', 'required' => false, 'variadic' => true, 'description' => '`field=value`, one value a token; tokens naming one field match any of their values, and `field!=value` keeps the rows matching none of their values. A field matches in any case, and a repeated token counts once. A field another argument owns is refused; `query`, where none does, takes a bucket by its key.' ];

	/**
	 * The fields a URL-set filter may not name, each by the argument owning
	 * it and what that argument names, which the refusal points to.
	 */
	private const URL_SET_OWNERS = [
		'server' => [ '--server', 'server' ],
		'bucket' => [ '--bucket', 'buckets' ],
	];

	/** The field `--page` owns while it names a page. */
	private const PAGE_OWNER = [ 'page' => [ '--page', 'page' ] ];

	/** The fields a one-URL filter may not name: a URL-set's, and the hash's. */
	private const ONE_URL_OWNERS = self::URL_SET_OWNERS + [ 'url' => [ 'the hash', 'URL' ] ];

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
	 * The overview-prefetch job's questions by stored key, read on this
	 * node's first `overview` or `query`: the node lives as long as the
	 * request graph that mounted it, so a request reads them once.
	 *
	 * @var array<string,array{name:string,verb:string,tokens:list<string>,args:array<string,mixed>}>|null
	 */
	private ?array $stored = null;

	/**
	 * Pattern-search the RECENT firehose window; return a bounded summary of matching
	 * REQUESTS (grouped by rid). Reuses the shared Reqgrep_Core grouping/matching
	 * engine so the dashboard and `wp nodes reqgrep` agree byte-for-byte on what
	 * matches. Each firehose partition is drained by an EPHEMERAL request-scope
	 * Consumer (no offsetlog/deadletter, seeded at `recent`) removed in a finally, so
	 * the workers' durable cursor dirs are never touched. Bounded three ways: a
	 * per-request byte/line cap (in the engine), MAX_SCAN_S of reading — which
	 * stops the drain itself, so the partitions after it go unread — and a
	 * result cap. Every limit is reported honestly in `truncated`. On a
	 * started record each partition's drain is a `grep scan` span labelled
	 * with its dir, closing on the lines it read, its own `(start)` lines
	 * among them, and `cut` where the budget ran out.
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
				Log_Manager::span(
					Flame_Tree::GREP_SCAN,
					static function () use ( $consumer, &$spent, &$lines ): array {
						$from = $lines;
						// By reference: an arrow fn would freeze $spent.
						$consumer->drain(
							static function () use ( &$spent ): bool {
								return $spent;
							}
						);
						return [ $lines - $from, $spent ];
					},
					/** @param array{0:int,1:bool} $scan Lines read, and whether the budget cut them. */
					static fn ( array $scan ): string => $scan[0] . ' lines' . ( $scan[1] ? ' · cut' : '' ),
					start: [ 'l' => \basename( $source_dir ) ]
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
			$request_line = Log_Manager::REQUEST_LINE === $key && '' === $method ? Log_Manager::parse_request_line( $entry ) : null;
			if ( null !== $request_line ) {
				// A grep row names the keyed URL, less any worker mark.
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
	 * @param Url_Filters         $filters    The url filters in force, which `overview:` reads whole and `url:` reads for `filter` and `bucket`, the list the URL modal holds: every other descriptor names one thing, and a filtered view of one thing is the same thing, so only those two read the selection, or refuse it, and carry its canonical spelling.
	 * @param int                 $now        The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 * @throws \RuntimeException On an unknown descriptor, a missing context, or an absent subject.
	 * @throws \InvalidArgumentException On a selection `overview:` or `url:` refuses.
	 */
	private static function assemble_ask( string $descriptor, array $context, string $server, array $filters, int $now ): array {
		$target = Ask_Assembler::parse_descriptor( $descriptor )
			?? throw new \RuntimeException( \esc_html( "unknown descriptor: {$descriptor}" ) );
		$chain  = [];
		foreach ( $context as $container ) {
			$chain[] = ( Ask_Assembler::parse_descriptor( $container )
				?? throw new \RuntimeException( \esc_html( "unknown descriptor: {$container}" ) ) ) + [ 'descriptor' => $container ];
		}

		switch ( $target['type'] ) {
			case 'overview':
				return self::ask_overview( $server, $filters, $now );
			case 'url':
				return self::ask_url( $target['id'], $server, $filters, $now );
			case 'request':
				return self::ask_request( $target['id'], $chain, $server );
			case 'span':
				return self::ask_span( $target['id'], $chain, $server, $now );
			case 'entry':
				return self::ask_entry( $target['id'], $chain );
			case 'category':
				return self::ask_category( $target['id'], $chain, $server, $now );
		}
		throw new \RuntimeException( \esc_html( 'unknown descriptor: ' . $target['type'] ) );
	}

	/**
	 * The `overview:` brief — the dashboard as it is being read: the pages
	 * the table lists, busiest first, under the same filters, with the
	 * filtered set's totals and the scoped leaderboard beside them. It asks
	 * the table's own `urls` question, so a question the snapshot answers
	 * reads it, as the table does, and each page of several shapes takes its
	 * `busiest_hash` from that page's `--page` question off the same source;
	 * any other reads both live. `answer_overview()`'s totals are
	 * deliberately NOT used: they ignore the URL filters, so under a search
	 * they would describe a different set than the one on screen.
	 *
	 * @param string      $server  Server the page is scoped to; '' is every server.
	 * @param Url_Filters $filters The url filters, as the page has them.
	 * @param int         $now     The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 * @throws \LogicException When a listed page holds no URL of the set.
	 * @throws \InvalidArgumentException On a selection `Bucket::selection()` refuses.
	 */
	private static function ask_overview( string $server, array $filters, int $now ): array {
		$question = self::brief_question( $server, $filters );
		$listing  = self::snapshot_urls(
			$question,
			$now,
			static fn ( Url_Snapshot $snapshot, array $args ): array => self::with_busiest( self::stored_urls( $snapshot, $args ), static fn ( array $row ): array => self::stored_urls( $snapshot, self::busiest_question( $args, $row ) ) )
		) ?? self::with_busiest( self::answer_live( 'urls', $question, $now, null ), static fn ( array $row ): array => self::answer_live( 'urls', self::busiest_question( $question, $row ), $now, null ) );

		$filters['bucket'] = Core::as_string( Core::arr( $listing['filters'] )['bucket'] );
		return Ask_Assembler::for_overview( $listing, self::site_leaderboard( $server, $now ), $server, $filters );
	}

	/**
	 * A page listing with each row's `busiest_hash`: a one-shape page's own
	 * `hash`, and the first URL row `$shapes` answers for any other.
	 *
	 * @param array<string,mixed>                                     $listing A `urls` reply of pages.
	 * @param \Closure(array<array-key,mixed>): array<string,mixed> $shapes  A page row to the `urls` reply of its busiest URL.
	 * @return array<string,mixed>
	 * @throws \LogicException On a listed page holding no URL.
	 */
	private static function with_busiest( array $listing, \Closure $shapes ): array {
		$listing['data'] = \array_map(
			static function ( mixed $row ) use ( $shapes ): array {
				$row = Core::arr( $row );
				return $row + [ 'busiest_hash' => 1 === Core::num_int( $row['shapes'] ) ? $row['hash'] : Core::arr( Core::arr( $shapes( $row )['data'] )[0] ?? throw new \LogicException( \esc_html( 'listed page ' . Core::as_string( $row['page_hash'] ) . ' holds no URL' ) ) )['hash'] ];
			},
			Core::arr( $listing['data'] )
		);
		return $listing;
	}

	/**
	 * The `urls` question an `overview:` brief lists: the busiest
	 * `OVERVIEW_BRIEF_PAGES` pages under the brief's filters, every other
	 * arg bound at its default, so it is the table's question.
	 *
	 * @param string      $server  Server the page is scoped to; '' is every server.
	 * @param Url_Filters $filters The url filters, as the page has them.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException As `Command_Args::bind()`.
	 */
	private static function brief_question( string $server, array $filters ): array {
		$bound = self::url_defaults();
		return \array_replace(
			$bound,
			[
				'server'          => $server,
				'search'          => '' === $filters['search'] ? $bound['search'] : $filters['search'],
				'filter'          => $filters['filter'],
				'include_workers' => $filters['include_workers'],
				'bucket'          => $filters['bucket'],
				'limit'           => self::OVERVIEW_BRIEF_PAGES,
			]
		);
	}

	/**
	 * The `--page` question naming a listed page's busiest URL: the
	 * listing's question on that page and that row's server, its one URL by
	 * count, ties going to the higher hash as a descending sort breaks them.
	 * `--page` owns the page field, so a `page` token the listing narrowed by
	 * goes; the row is that page already.
	 *
	 * @param array<array-key,mixed> $args The listing's bound `urls` args.
	 * @param array<array-key,mixed> $row  A page row it listed.
	 * @return array<array-key,mixed>
	 */
	private static function busiest_question( array $args, array $row ): array {
		return \array_replace(
			$args,
			[
				'page'   => Core::as_string( $row['page_hash'] ),
				'server' => Core::as_string( $row['server'] ),
				'filter' => \array_values( \array_filter( Stats_Query::strings( $args['filter'] ), static fn ( string $token ): bool => 'page' !== Stats_Query::field_of( \explode( '=', $token, 2 )[0] ) ) ),
				'sort'   => 'count',
				'order'  => 'desc',
				'offset' => 0,
				'limit'  => 1,
			]
		);
	}

	/**
	 * The `url:` brief — stats, worst recent requests, and the cold-start
	 * finding when nothing governs it, of the requests `dump_url` reads
	 * under the same server, `filter` and `bucket`.
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
		$detail            = self::url_detail( $hash, $server, $filters['filter'], $filters['bucket'], $now, self::RECENT_REQUEST_LIMIT );
		$filters['bucket'] = $detail['bucket'];
		return Ask_Assembler::for_url(
			$detail['stats'],
			self::url_requests( $detail['listed'] ),
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
	 * @param string       $rid     Request id the descriptor names.
	 * @param list<Context_Entry> $context The parsed containers, outermost last.
	 * @param string       $server  Server the modal is scoped to; rides the URL pointer.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When no row names the rid, or the rows did not open.
	 */
	private static function ask_request( string $rid, array $context, string $server ): array {
		$record = self::load_request( $rid );
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
	 * @param list<Context_Entry> $context The parsed containers, outermost last.
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
				$cut = true === $record['flame_cut'] ? ': its flame lookup ran out of time' : '';
				throw new \RuntimeException( \esc_html( "no span '{$name}' in this request{$cut}" ) );
			}
			return $brief;
		}
		$url = self::context_entry( $context, 'url' );
		if ( null === $url ) {
			throw new \RuntimeException( \esc_html( 'a span needs its request or its URL for context' ) );
		}
		$detail = self::url_detail( $url['id'], $server, [], '', $now, self::FLAME_REQUEST_LIMIT );
		if ( ! $detail['available'] ) {
			throw self::stats_outage( "no aggregate for URL {$url['id']}" );
		}
		$flame = self::url_flame( $detail['listed'] ) ?? throw new \RuntimeException( \esc_html( "no aggregate for URL {$url['id']}: its flame rebuild ran out of time" ) );
		$named = $detail['stats']['url'];
		$brief = Ask_Assembler::for_url_span( $flame, $name, $named, Rule_Set::load()->for_url( $named ), $url['descriptor'], $server );
		if ( null === $brief ) {
			throw new \RuntimeException( \esc_html( "no span '{$name}' in this URL's aggregate" ) );
		}
		return $brief;
	}

	/**
	 * One URL over a selection's buckets, or the chart's: its stats and the
	 * rows of its newest `$limit` requests, worker traffic counted, which
	 * `url_requests()` shapes as `dump_url` lists them and `url_flame()` reads
	 * each request's partition off, and whether the list holds fewer than the
	 * stats count. A filter narrows the stats and the list together, and a
	 * filter on `worker` replaces the scope's. A stats file that will not
	 * open answers zero stats and no list, `available` false (decision 3).
	 *
	 * @param string       $hash     12-char URL hash.
	 * @param string       $server   Server to scope to; '' is every server.
	 * @param list<string> $tokens   `field=value` filter tokens.
	 * @param string       $spelling A `Bucket::selection()` spelling; '' for the chart's.
	 * @param int          $now      The reply's clock.
	 * @param int          $limit    Most requests listed, newest first.
	 * @return array{stats: array{hash:string, url:string, count:mixed, avg_ms:?float, min_ms:?float, max_ms:?float, p50_ms:?float, p95_ms:?float, p99_ms:?float, avg_peak_mb:?float, max_peak_mb:mixed, last_updated:mixed, errors:mixed, error_rate:?float, requests_per_second:float|int}, listed: list<array<string,mixed>>, requests_capped: bool, bucket: string, available: bool}
	 * @throws \RuntimeException When no request row names the hash.
	 * @throws \InvalidArgumentException On a selection `Bucket::selection()` refuses, or a filter `url_filter_of()` refuses.
	 */
	private static function url_detail( string $hash, string $server, array $tokens, string $spelling, int $now, int $limit ): array {
		$buckets = self::buckets_of( $spelling, $now );
		$filter  = [ 'url' => [ $hash ] ] + self::url_filter_of( $tokens, self::ONE_URL_OWNERS ) + self::scope( $server, true );
		$answer  = Stats_Query::run(
			[
				'source'  => 'requests',
				'group'   => [ 'url' ],
				'filter'  => $filter,
				'measure' => [ ...self::URL_MEASURES, ...self::URL_DETAIL_EXTRAS ],
				'buckets' => $buckets,
			]
		);
		$stats  = self::url_stats( $hash, $server, $answer, $buckets, $now );
		$listed = $answer['available'] ? Stats_Query::newest_requests( $filter, $buckets, $limit ) : [];
		return [
			'stats'           => $stats,
			'listed'          => $listed,
			'requests_capped' => \count( $listed ) < Core::num_int( $stats['count'] ),
			'bucket'          => '' === $spelling ? '' : Bucket::spelling( $buckets ),
			'available'       => $answer['available'],
		];
	}

	/**
	 * A URL's stats out of its `url`-grouped `URL_MEASURES` answer, as the
	 * `urls` row carries them, with its rate over the seconds the buckets
	 * have run. A URL with no request there answers zero, its means and
	 * extremes null, named by `url_name()` on its server alone, so a filter
	 * matching none of its requests answers zero rather than not-found.
	 *
	 * @param string                                                                   $hash    12-char URL hash.
	 * @param string                                                                   $server  Server the URL is named on; '' is every server.
	 * @param array{columns: list<string>, rows: list<list<mixed>>, available: bool} $answer  The URL's answer over `$buckets`.
	 * @param list<array{0:int,1:int}>                                                 $buckets The buckets read.
	 * @param int                                                                      $now     The reply's clock.
	 * @return array{hash:string, url:string, count:mixed, avg_ms:?float, min_ms:?float, max_ms:?float, p50_ms:?float, p95_ms:?float, p99_ms:?float, avg_peak_mb:?float, max_peak_mb:mixed, last_updated:mixed, errors:mixed, error_rate:?float, requests_per_second:float|int}
	 * @throws \RuntimeException When no request row names the hash.
	 */
	private static function url_stats( string $hash, string $server, array $answer, array $buckets, int $now ): array {
		$rows = self::url_rows( $answer );
		$row  = [] !== $rows ? $rows[0] : [
			'url'          => $answer['available'] ? self::url_name( $hash, $server, $now ) : '',
			'count'        => 0,
			'avg_ms'       => null,
			'min_ms'       => null,
			'max_ms'       => null,
			'avg_peak_mb'  => null,
			'max_peak_mb'  => 0.0,
			'last_updated' => 0,
			'errors'       => 0,
		];
		$extra = \array_fill_keys( self::URL_DETAIL_EXTRAS, null );
		if ( [] !== $rows ) {
			$extra = \array_intersect_key( \array_combine( $answer['columns'], $answer['rows'][0] ), $extra );
		}
		return [
			'hash'                => $hash,
			'url'                 => Core::as_string( $row['url'] ),
			'count'               => $row['count'],
			'avg_ms'              => $row['avg_ms'],
			'min_ms'              => $row['min_ms'],
			'max_ms'              => $row['max_ms'],
			'p50_ms'              => Ask_Assembler::measured_figure( $extra['p50_ms'] ),
			'p95_ms'              => Ask_Assembler::measured_figure( $extra['p95_ms'] ),
			'p99_ms'              => Ask_Assembler::measured_figure( $extra['p99_ms'] ),
			'avg_peak_mb'         => $row['avg_peak_mb'],
			'max_peak_mb'         => $row['max_peak_mb'],
			'last_updated'        => $row['last_updated'],
			'errors'              => $row['errors'],
			'error_rate'          => Ask_Assembler::measured_figure( $extra['error_rate'] ),
			'requests_per_second' => Core::num_int( $row['count'] ) / self::elapsed_seconds( $buckets, $now ),
		];
	}

	/**
	 * The listed requests as `dump_url` answers them, each named by its rid
	 * alone, which is all `dump_request` is asked with. A row's
	 * `duration_ms` is null where no sample was taken (decision 24), and its
	 * `timestamp` is the second its record says it started.
	 *
	 * @param list<array<string,mixed>> $listed The rows `Stats_Query::newest_requests()` listed.
	 * @return list<array<string,mixed>>
	 */
	private static function url_requests( array $listed ): array {
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
			],
			$listed
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
	 * @param list<Context_Entry> $context The parsed containers, outermost last.
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
	 * @param list<Context_Entry> $context The parsed containers, outermost last.
	 * @param string       $server  Server the URL's profile and the leaderboard
	 *                              fallback answer for; '' is every server.
	 * @param int          $now     The reply's clock, read once at its entry.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When no board holds the category, the name is a callback row, or a URL's stats did not open or name it.
	 */
	private static function ask_category( string $name, array $context, string $server, int $now ): array {
		// A callback row is not a board; its time counts inside its hook.
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
		$url = self::context_entry( $context, 'url' );
		if ( null !== $url ) {
			$scope = self::scope( $server, true );
			$named = self::url_name( $url['id'], $server, $now );
			if ( '' === $named ) {
				throw self::stats_outage( "no aggregate for URL {$url['id']}" );
			}
			$profile = self::url_profile( $url['id'], $scope, $now );
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
	 * The URL a request row on `$server` in the buckets the rows keep names
	 * `$hash` by, server and path joined, worker traffic counted; '' when the
	 * stats file will not open, which means no stats rather than no URL
	 * (decision 3). No filter narrows it: a URL exists or it does not.
	 *
	 * @param string $hash   12-char URL hash.
	 * @param string $server Server to scope to; '' is every server.
	 * @param int    $now    The reply's clock.
	 * @throws \RuntimeException When the rows open and none names it.
	 */
	private static function url_name( string $hash, string $server, int $now ): string {
		$answer = Stats_Query::run(
			[
				'source'  => 'requests',
				'group'   => [ 'url' ],
				'filter'  => [ 'url' => [ $hash ] ] + self::scope( $server, true ),
				'measure' => [ 'count' ],
				'buckets' => Bucket::last( Stats_Query::retention( 'requests' ), $now ),
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
	 * A URL's category profile over every bucket the timings keep, in the
	 * leaderboard's shape, or null over no profiled request.
	 *
	 * @param string                     $hash  12-char URL hash.
	 * @param array<string,list<scalar>> $scope The server scope; worker traffic counts.
	 * @param int                        $now   The reply's clock.
	 * @return array{count:int, total_time:?float, avg_ms:?float, categories:array<array-key,mixed>}|null
	 */
	private static function url_profile( string $hash, array $scope, int $now ): ?array {
		$board = self::leaderboard( $scope + [ 'url' => [ $hash ] ], Bucket::last( Stats_Query::retention( 'timings' ), $now ), $now );
		return 0 === $board['count'] ? null : $board;
	}

	/**
	 * A URL's aggregate flame, folded from the flames its newest
	 * FLAME_REQUEST_LIMIT listed requests stored, in the display shape: the
	 * empty tree over no stored flame.
	 *
	 * Each partition those requests sit in is asked once for their
	 * rids' flames, no further back than the oldest one's `finished_at`,
	 * since a flame lands in the partition its request did, and each is
	 * folded as the builder folds it, its timing counted where its duration
	 * is. The lookups share MAX_SCAN_S, and one it cuts answers null, because
	 * a partial fold would read as the URL's whole flame. A
	 * stored flame has lost the suffixes that hold a request's same-named
	 * siblings apart, so those merge into one node here.
	 *
	 * @param list<array<string,mixed>> $listed The listed rows, as `url_detail()` keeps them.
	 * @return array<array-key,mixed>|null
	 */
	private static function url_flame( array $listed ): ?array {
		$listed    = \array_slice( $listed, 0, self::FLAME_REQUEST_LIMIT );
		$durations = [];
		$since     = [];
		foreach ( $listed as $row ) {
			$partition                                                 = Core::num_int( $row['partition'] );
			$durations[ $partition ][ Core::as_string( $row['rid'] ) ] = $row['ms'];
			$since[ $partition ]                                       = \min( $since[ $partition ] ?? \INF, Core::num_float( $row['finished_at'] ) );
		}
		$flame = [
			'name'      => 'aggregate',
			'sum_value' => 0.0,
			'count'     => 0,
			'children'  => [],
		];
		$deadline = self::scan_deadline();
		foreach ( $durations as $partition => $by_rid ) {
			$flames = self::stored_flames( $partition, \array_map( 'strval', \array_keys( $by_rid ) ), $since[ $partition ], $deadline );
			if ( null === $flames ) {
				return null;
			}
			foreach ( $flames as $rid => $tree ) {
				$ms    = $by_rid[ $rid ];
				$flame = self::fold_flame( $flame, $tree, Core::num_float( $ms ), null !== $ms );
			}
		}
		return 0 === Core::num_int( $flame['count'] ) ? self::EMPTY_FLAME : self::display_flame( $flame );
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
	 * @return array<array-key,mixed>
	 */
	private static function fold_flame( array $flame, array $flame_data, float $duration_ms, bool $record_timing ): array {
		$flame['count'] = Core::num_int( $flame['count'] ?? null ) + 1;
		if ( $record_timing ) {
			$flame['sum_value'] = Core::num_float( $flame['sum_value'] ?? null ) + $duration_ms;
			$flame['children']  = Flame_Tree::merge_flame_children_incremental( Core::arr( $flame['children'] ?? null ), Core::arr( $flame_data['children'] ?? null ) );
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
	 * @param list<Context_Entry> $context The parsed containers.
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
	 * @param list<Context_Entry> $context The parsed containers.
	 * @return array<array-key,mixed>|null
	 */
	private static function request_in_context( array $context ): ?array {
		$request = self::context_entry( $context, 'request' );
		return null === $request ? null : self::load_request( $request['id'] );
	}

	/**
	 * The descriptor of a given type in a context chain, verbatim. A brief's
	 * `fetch` pointer has to name what an agent would ASK, which is the
	 * descriptor as the picker wrote it — not the record it resolves to.
	 *
	 * @param list<Context_Entry> $context The parsed containers.
	 * @param string       $type    Descriptor type to look for.
	 * @return string The descriptor, or '' when the chain names none.
	 */
	private static function descriptor_of( array $context, string $type ): string {
		return self::context_entry( $context, $type )['descriptor'] ?? '';
	}

	/**
	 * The first container of a given type in a context chain — for a span or
	 * a category picked inside the URL modal, the `url:` the chain names
	 * beside no request — or null when it names none.
	 *
	 * @param list<Context_Entry> $context The parsed containers.
	 * @param string              $type    Descriptor type to look for.
	 * @return Context_Entry|null
	 */
	private static function context_entry( array $context, string $type ): ?array {
		foreach ( $context as $entry ) {
			if ( $type === $entry['type'] ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * One request record by rid, read once at the place its `requests` row
	 * names, with its flame merged in as `flame_data`, the row's URL hash as
	 * `url_hash`, and `flame_cut` true when MAX_SCAN_S ended the flame lookup
	 * first. The flame builder on partition N writes flames.pN, and the stats
	 * builder there writes the row naming requests.pN only after reading that
	 * flame, so the flame is located on the row's partition alone, no further
	 * back than the row's `finished_at`; a miss there means none was stored,
	 * and leaves the body intact. No row means no request, whether retention
	 * took it or the stats builder has not written it yet, and a
	 * place holding another request's record, keyed by its rid, means the
	 * same; rows that did not open are an outage, refused as one.
	 *
	 * @param string $rid Request id to load.
	 * @return array<array-key,mixed>
	 * @throws \RuntimeException When the rows did not open, no row names the rid, or its place holds no record of it.
	 */
	private static function load_request( string $rid ): array {
		$row       = self::row_of( $rid );
		$partition = Core::num_int( $row['partition'] );
		$dir       = Bootstrap::node_dirs( self::NODE_REQUESTS )[ $partition ] ?? null;
		$message   = null === $row['segment'] || null === $dir ? null : self::on_partition(
			'requests',
			$partition,
			$dir,
			static fn ( Partition_Node $node ): ?array => $node->read_message_at( Core::as_int( $row['segment'] ), Core::as_int( $row['offset'] ), Core::as_int( $row['length'] ) )
		);
		$record    = $message[ Message::VALUE ] ?? null;
		if ( ! \is_array( $record ) || $rid !== ( $message[ Message::KEY ] ?? null ) ) {
			throw self::not_found( $rid );
		}
		$record['url_hash'] = $row['url'];
		$flames             = self::stored_flames( $partition, [ $rid ], Core::num_float( $row['finished_at'] ), self::scan_deadline() );
		$record['flame_cut'] = null === $flames;
		if ( isset( $flames[ $rid ] ) ) {
			$record['flame_data'] = $flames[ $rid ];
		}
		return $record;
	}
	/**
	 * The `requests` row a rid keys, as `Stats_Query::request_row()` reads it.
	 *
	 * @param string $rid Request id.
	 * @return array<string,mixed>
	 * @throws \RuntimeException When the rows did not open, or none names the rid.
	 */
	private static function row_of( string $rid ): array {
		$found = Stats_Query::request_row( $rid );
		if ( ! $found['available'] ) {
			throw self::stats_outage( "no request {$rid}" );
		}
		return $found['row'] ?? throw self::not_found( $rid );
	}

	/**
	 * The refusal a brief or a request answers when the stats rows did not
	 * open: an outage, never an absent span, category or request.
	 *
	 * @param string $what What could not be answered.
	 */
	private static function stats_outage( string $what ): \RuntimeException {
		return new \RuntimeException( \esc_html( "{$what}: the stats rows did not open" ) );
	}

	/**
	 * The refusal a request no row names answers.
	 *
	 * @param string $rid Request id.
	 */
	private static function not_found( string $rid ): \RuntimeException {
		return new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
	}

	/**
	 * The flames stored on one partition for `$rids`, by rid: one
	 * `locate_by()` over its flame index, newest first, then one
	 * `read_many()`. A rid with no stored flame is absent. The walk ends once
	 * it holds every rid, or at the first segment whose index took its last
	 * line before `$since`: the builder appends a request's flame no earlier
	 * than the tick its row's `finished_at` is clamped to, on this host's
	 * clock, so no older segment holds one. `$deadline` bounds the rest, and
	 * a walk it cuts answers null rather than the part it read. On a started
	 * record the walk is a `flame lookup` span labelled with the partition,
	 * closing on how many of its rids it found, or `cut`.
	 *
	 * @param int          $partition The flames partition.
	 * @param list<string> $rids      Request ids.
	 * @param float        $since     The earliest `finished_at` among them.
	 * @param float        $deadline  A `scan_deadline()` the walk stops by.
	 * @return array<string,array<array-key,mixed>>|null Each flame tree, by rid; null when the deadline cut the walk.
	 * @throws \RuntimeException When the flame index formatter is not registered.
	 */
	private static function stored_flames( int $partition, array $rids, float $since, float $deadline ): ?array {
		self::require_index_formatter( 'flame-index' );
		$dir = Bootstrap::node_dirs( self::NODE_FLAMES )[ $partition ] ?? null;
		if ( null === $dir ) {
			return [];
		}
		$clock    = self::scan_clock();
		$walk     = static function ( Partition_Node $node ) use ( $rids, $since, $deadline, $clock ): ?array {
			$closed = \array_filter( $node->index_mtimes(), static fn ( int $mtime ): bool => $mtime < $since );
			$lines  = 0;
			$found  = $node->locate_by(
				static function ( string $line, int $segment ) use ( $closed, $deadline, $clock, &$lines ): array|bool|null {
					if ( isset( $closed[ $segment ] ) ) {
						return true;
					}
					if ( 0 === ++$lines % self::SCAN_CLOCK_STRIDE && $clock() > $deadline ) {
						return false;
					}
					return self::locate_flame( $line );
				},
				$rids
			);
			return null === $found ? null : $node->read_many( $found );
		};
		$messages = Log_Manager::span(
			Flame_Tree::FLAME_LOOKUP,
			static fn (): ?array => self::on_partition( 'flames', $partition, $dir, $walk ),
			static fn ( ?array $found ): string => null === $found ? 'cut' : \count( $found ) . ' of ' . \count( $rids ) . ' found',
			start: [ 'l' => \basename( $dir ) ]
		);
		if ( null === $messages ) {
			return null;
		}
		$flames = [];
		foreach ( $messages as $rid => $message ) {
			$tree = $message[ Message::VALUE ] ?? null;
			if ( \is_array( $tree ) ) {
				$flames[ (string) $rid ] = $tree;
			}
		}
		return $flames;
	}

	/**
	 * Refuse to read an index whose formatter this process never registered:
	 * the writer and the reader are halves of one format.
	 *
	 * @param string $formatter The index formatter's name.
	 * @throws \RuntimeException When it is not registered.
	 */
	private static function require_index_formatter( string $formatter ): void {
		if ( null === \Newspack_Nodes\Formatters::resolve( $formatter ) ) {
			throw new \RuntimeException( \esc_html( "index formatter not registered: {$formatter}" ) );
		}
	}

	/**
	 * What `$read` answers over a scratch Partition on one partition's dir,
	 * removed afterwards whatever `$read` does.
	 *
	 * @template T
	 * @param string                     $log       Log basename ('requests' | 'flames').
	 * @param int                        $partition Partition index.
	 * @param string                     $dir       Its declared dir.
	 * @param \Closure(Partition_Node): T $read      The read.
	 * @return T
	 */
	private static function on_partition( string $log, int $partition, string $dir, \Closure $read ): mixed {
		$node = new Partition_Node();
		self::name_scratch_partition( $node, $log, $partition );
		$node->arguments( [ $dir ] );
		try {
			return $read( $node );
		} finally {
			$node->remove_node();
		}
	}

	/**
	 * A flame index line as `locate_by()` reads it: the rid it keys, and its
	 * record's offset and length; null for a line that is not an index entry.
	 *
	 * @param string $line Index line.
	 * @return array{key: string, offset: int, length: int}|null
	 */
	private static function locate_flame( string $line ): ?array {
		$entry = Flame_Builder_Node::parse_flame_index( $line );
		return null === $entry ? null : [
			'key'    => $entry['rid'],
			'offset' => $entry['offset'],
			'length' => $entry['length'],
		];
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
	 * The answer to `overview`, `urls` or `query` for bound arguments: off
	 * the URL snapshot the overview-prefetch job published, where it answers
	 * them, else the live one. A question naming one server is served where
	 * the job stored that server's answer, which it does for every server its
	 * list names. A scoped `overview` it stored none for reads its totals and
	 * leaderboard live and takes the stored unscoped overview's `servers`,
	 * which no scope changes, as `answer_overview()` admits them. A blank
	 * server scopes nothing, so it is the unscoped question. A `urls` question
	 * the snapshot cannot answer, and a question no stored question or
	 * template keys, never open it. A snapshot that opens but will not read, a
	 * stored answer that will not decode, and a stored overview naming no
	 * `servers` each say so once a window and answer live.
	 *
	 * @param string                 $verb `overview`, `urls` or `query`.
	 * @param array<array-key,mixed> $args Its bound arguments.
	 * @return array<array-key,mixed>
	 * @throws \InvalidArgumentException As `answer_live()` and `Overview_Prefetch::stored()`.
	 * @throws \RuntimeException As `Overview_Prefetch::stored()`.
	 */
	private function answered( string $verb, array $args ): array {
		if ( \array_key_exists( 'filter', $args ) ) {
			$args['filter'] = self::filter_tokens( $args['filter'] );
		}
		// The stored answers name each server as the rows store it.
		if ( \is_string( $args['server'] ?? null ) ) {
			$args['server'] = Url_Parts::server_name( $args['server'] );
		}
		if ( 'overview' === $verb && '' === $args['server'] ) {
			$args['server'] = null;
		}
		$now = self::now();
		if ( 'urls' === $verb ) {
			return self::snapshot_urls( $args, $now, self::stored_urls( ... ) ) ?? self::answer_live( $verb, $args, $now, null );
		}
		$server   = 'overview' === $verb ? Core::as_string( $args['server'] ) : '';
		$key      = Overview_Prefetch::key( $verb, $args );
		$snapshot = isset( $this->stored_questions()[ Overview_Prefetch::key( $verb, Overview_Prefetch::templated( $args ) ) ] ) ? Url_Snapshot::open( $now ) : null;
		try {
			$stored = $snapshot?->answer( $key );
			// The overview whose servers this reply names.
			$listed = null === $stored && '' !== $server && null !== $snapshot ? $snapshot->answer( Overview_Prefetch::key( $verb, \array_replace( $args, [ 'server' => null ] ) ) ) : $stored;
		} catch ( \PDOException | \JsonException $e ) {
			Core::print_less_often( 'performance: the URL snapshot did not read', ' — ' . Driver_Error::of( $e ) );
			$stored = null;
			$listed = null;
		}
		if ( 'overview' === $verb && null !== $listed && ! \is_array( $listed['servers'] ?? null ) ) {
			Core::print_less_often( 'performance: the stored overview names no servers' );
			$stored = null;
			$listed = null;
		}
		if ( null !== $stored ) {
			return $stored;
		}
		if ( '' === $server ) {
			return self::answer_live( $verb, $args, $now, null );
		}
		return [ 'as_of' => $now ] + self::answer_overview( $server, $now, null === $listed ? null : \array_values( Core::arr( $listed['servers'] ) ) );
	}

	/**
	 * The overview-prefetch job's questions by stored key, bound as the job
	 * binds them, read once a node.
	 *
	 * @return array<string,array{name:string,verb:string,tokens:list<string>,args:array<string,mixed>}>
	 * @throws \InvalidArgumentException As `Overview_Prefetch::stored()`.
	 * @throws \RuntimeException As `Overview_Prefetch::stored()`.
	 */
	private function stored_questions(): array {
		return $this->stored ??= Overview_Prefetch::stored();
	}

	/**
	 * What `$read` answers off the snapshot for a `urls` question the
	 * snapshot answers; null where the question answers live: one
	 * `snapshot_answers()` refuses, no snapshot, or one that opens but will
	 * not read for `$read`, which is said once a window. Only a
	 * `PDOException` falls back; anything else `$read` raises propagates.
	 *
	 * @param array<array-key,mixed>                                                            $args The bound verb args.
	 * @param int                                                                               $now  The reply's clock.
	 * @param \Closure(Url_Snapshot, array<array-key,mixed>): array<string,mixed>               $read The answer off the opened snapshot, as `stored_urls()` gives one.
	 * @return array<string,mixed>|null
	 */
	private static function snapshot_urls( array $args, int $now, \Closure $read ): ?array {
		$snapshot = self::snapshot_answers( $args ) ? Url_Snapshot::open( $now ) : null;
		if ( null === $snapshot ) {
			return null;
		}
		try {
			return $read( $snapshot, $args );
		} catch ( \PDOException $e ) {
			Core::print_less_often( 'performance: the URL snapshot did not read', ' — ' . Driver_Error::of( $e ) );
			return null;
		}
	}

	/**
	 * Whether the snapshot answers a `urls` question: every arg outside
	 * `SNAPSHOT_VARIES` at the default the binder gives it, so a search, a
	 * bucket selection, a filter or any arg added later answers live.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 */
	private static function snapshot_answers( array $args ): bool {
		$defaults = \array_diff_key( self::url_defaults(), \array_flip( self::SNAPSHOT_VARIES ) );
		foreach ( $defaults as $name => $default ) {
			if ( $args[ $name ] !== $default ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The `urls` verb's args as the binder gives them unasked.
	 *
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException As `Command_Args::bind()`.
	 */
	private static function url_defaults(): array {
		return Command_Args::bind( Core::arr( Command_Interpreter_Node::declared_verbs( self::class )['urls']['args'] ?? throw new \LogicException( 'urls declares its args' ) ), [] );
	}

	/**
	 * The `urls` answer off the snapshot, dated when the job built it, to a
	 * question `snapshot_answers()` admits: its pages, or under `--page`
	 * that page's URLs.
	 *
	 * @param Url_Snapshot           $snapshot The published snapshot.
	 * @param array<array-key,mixed> $args     The bound verb args.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException As `Url_Snapshot::pages()` and `shapes()`.
	 * @throws \PDOException When the snapshot opens but will not read.
	 */
	private static function stored_urls( Url_Snapshot $snapshot, array $args ): array {
		[ $page, $server, $filters, $sort, $order, $offset, $limit ] = self::url_question( $args );
		$now     = $snapshot->as_of();
		$workers = $filters['include_workers'];
		return [ 'as_of' => $now ] + self::url_reply(
			$page,
			$server,
			$filters,
			'' === $page ? $snapshot->totals( $server, $workers ) : $snapshot->shape_totals( $page, $server, $workers ),
			'' === $page ? $snapshot->pages( $server, $workers, self::PAGE_SORTS[ $sort ], $order, $offset, $limit ) : $snapshot->shapes( $page, $server, $workers, self::URL_SORTS[ $sort ], $order, $offset, $limit ),
			self::buckets_of( '', $now ),
			$offset,
			$limit,
			$now
		);
	}

	/**
	 * The live answer to `overview`, `urls` or `query` for bound arguments,
	 * dated `$now`.
	 *
	 * @api Overview_Prefetch, which stores these.
	 * @param string                 $verb    `overview`, `urls` or `query`.
	 * @param array<array-key,mixed> $args    Its bound arguments.
	 * @param int                    $now     The reply's clock.
	 * @param list<mixed>|null       $servers The servers an `overview` names; null reads them.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a verb no answer is stored for, or what the verb refuses.
	 */
	public static function answer_live( string $verb, array $args, int $now, ?array $servers ): array {
		return [ 'as_of' => $now ] + match ( $verb ) {
			'overview' => self::answer_overview( Core::as_string( $args['server'] ), $now, $servers ),
			'urls'     => self::url_page( ...self::url_question( $args ), now: $now ),
			'query'    => self::answer_query( $args, $now ),
			default    => throw new \InvalidArgumentException( \esc_html( "no stored answer answers {$verb}" ) ),
		};
	}

	/**
	 * The `overview` verb's answer over the chart's `Bucket::window()`: the
	 * non-worker requests' totals, their exact distinct URLs, every server
	 * with traffic there, worker traffic counted, busiest first, the
	 * leaderboard and each owner's time. A server scopes the totals, the
	 * leaderboard and the owners; `servers`
	 * stays whole, so a scoped dashboard can still name the rest. A list
	 * handed in stands in for it, unless a scoped server has traffic the list
	 * lacks, worker traffic counted as the list counts it; only a live read
	 * names that server, and a server the list lacks costs one scoped count
	 * a poll to rule it out. Stats that will not open answer `available`
	 * false and null totals, never zeroes.
	 *
	 * @param string           $server  Server to scope to; '' is every server.
	 * @param int              $now     The reply's clock.
	 * @param list<mixed>|null $servers The `servers` to name; null reads them.
	 * @return array{totals: array<string,mixed>|null, slots: list<string>, servers: list<mixed>, leaderboard: array<string,mixed>, owners: list<array{owner:string,ms_per_request:float,share:float,calls_per_request:float}>, available: bool}
	 */
	private static function answer_overview( string $server, int $now, ?array $servers ): array {
		$chart   = Bucket::last( Bucket::window(), $now );
		$answer  = Stats_Query::run(
			[
				'source'  => 'requests',
				'group'   => [],
				'filter'  => self::scope( $server, false ),
				'measure' => [ 'count', 'timed', 'avg_ms', 'avg_peak_mb', 'errors', 'urls' ],
				'buckets' => $chart,
			]
		);
		$totals  = self::measures( $answer );
		if ( null === $servers || ( '' !== $server && ! \in_array( $server, $servers, true ) && ( 0 < Core::num_int( $totals['count'] ) || self::has_traffic( $server, $chart ) ) ) ) {
			$servers = \array_column(
				Stats_Query::run(
					[
						'source'  => 'requests',
						'group'   => [ 'server' ],
						'filter'  => self::scope( '', true ),
						'measure' => [ 'count' ],
						'buckets' => $chart,
						'sort'    => 'count',
						'order'   => 'desc',
					]
				)['rows'],
				0
			);
		}
		return [
			'totals'      => $answer['available'] ? [
				'requests'    => Core::num_int( $totals['count'] ),
				'timed'       => Core::num_int( $totals['timed'] ),
				'avg_ms'      => Ask_Assembler::measured_figure( $totals['avg_ms'] ),
				'avg_peak_mb' => Ask_Assembler::measured_figure( $totals['avg_peak_mb'] ),
				'errors'      => Core::num_int( $totals['errors'] ),
				'urls'        => Core::num_int( $totals['urls'] ),
			] : null,
			'slots'       => \array_map( Bucket::key( ... ), \range( $chart[0][1], $chart[0][0] ) ),
			'servers'     => $servers,
			'leaderboard' => self::site_leaderboard( $server, $now ),
			'owners'      => self::owner_board( self::scope( $server, false ), $chart, $now ),
			'available'   => $answer['available'],
		];
	}

	/**
	 * Each owner's time over `$buckets` of the owner rows `$filter` selects,
	 * dearest per request first: `ms_per_request` and `calls_per_request`
	 * are its summed `ms` and `calls` over the `requests` of the
	 * `(unmeasured)` row, which counts every request folded, and `share` its
	 * `ms` over every row's. That row is no owner and is not listed. Empty
	 * over no folded request.
	 *
	 * @param array<string,list<scalar>> $filter  Fields the `owners` source holds.
	 * @param list<array{0:int,1:int}>   $buckets The board's buckets.
	 * @param int                        $now     The reply's clock.
	 * @return list<array{owner:string,ms_per_request:float,share:float,calls_per_request:float}>
	 */
	private static function owner_board( array $filter, array $buckets, int $now ): array {
		$rows     = self::timing_rows(
			[ 'owner' ],
			[
				'source'  => 'owners',
				'filter'  => $filter,
				'measure' => [ 'ms', 'calls', 'requests' ],
				'buckets' => $buckets,
				'now'     => $now,
			]
		);
		$requests = Core::num_int( \array_column( $rows, 3, 0 )[ Owner::UNMEASURED ] ?? null );
		$total    = Core::num_float( \array_sum( \array_column( $rows, 1 ) ) );
		if ( 0 === $requests ) {
			return [];
		}
		$board = [];
		foreach ( $rows as [ $owner, $ms, $calls ] ) {
			if ( Owner::UNMEASURED === $owner ) {
				continue;
			}
			$board[] = [
				'owner'             => Core::as_string( $owner ),
				'ms_per_request'    => Core::num_float( $ms ) / $requests,
				'share'             => 0.0 < $total ? Core::num_float( $ms ) / $total : 0.0,
				'calls_per_request' => Core::num_float( $calls ) / $requests,
			];
		}
		return $board;
	}

	/**
	 * Whether `$server` has any request over `$buckets`, worker traffic
	 * counted: one scoped count.
	 *
	 * @param string                   $server  The scoped server.
	 * @param list<array{0:int,1:int}> $buckets The buckets.
	 */
	private static function has_traffic( string $server, array $buckets ): bool {
		$answer = Stats_Query::run(
			[
				'source'  => 'requests',
				'group'   => [],
				'filter'  => self::scope( $server, true ),
				'measure' => [ 'count' ],
				'buckets' => $buckets,
			]
		);
		return 0 < Core::num_int( self::measures( $answer )['count'] );
	}

	/**
	 * The non-worker leaderboard over the chart's buckets, scoped to `$server`
	 * when one is named.
	 *
	 * @param string $server Server to scope to; '' is every server.
	 * @param int    $now    The reply's clock.
	 * @return array{count:int, total_time:?float, avg_ms:?float, categories:array<array-key,mixed>}
	 */
	private static function site_leaderboard( string $server, int $now ): array {
		return self::leaderboard( self::scope( $server, false ), Bucket::last( Bucket::window(), $now ), $now );
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
	 * @param int                         $now     The reply's clock.
	 * @return array{count:int, total_time:?float, avg_ms:?float, categories:array<array-key,mixed>}
	 */
	private static function leaderboard( array $filter, array $buckets, int $now ): array {
		$timings      = [
			'source'  => 'timings',
			'filter'  => $filter,
			'measure' => [ 'ms', 'calls', 'requests' ],
			'buckets' => $buckets,
			'now'     => $now,
		];
		$sums         = [];
		$sum_req_time = 0.0;
		$categories   = self::timing_rows( [ 'category', 'listener' ], [ 'filter' => $filter + [ 'entry' => [ '' ] ] ] + $timings );
		foreach ( $categories as [ $category, $listener, $ms, $calls, $requests ] ) {
			// An overlay's time (a listener's, a QM timer's) counts already.
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
			'avg_ms'     => self::mean( $ms, $timed ),
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
	 * @param array{source:string, filter:array<string,list<scalar|null>>, measure:list<string>, buckets:list<array{0:int,1:int}>, now:int} $timings The timings spec, less its group, sort and page.
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
	 * Over no profiled request each mean is null, as `mean()` answers. An
	 * entry whose sample count is zero is dropped rather than divided. Past `ENTRY_TRIM_ABOVE` entries a category keeps only its
	 * `ENTRY_TRIM_TO` slowest, ranked by average exclusive time, so one pathological
	 * category cannot flood a payload.
	 *
	 * @param array<array-key,mixed> $sums         Per category: `samples`, `sum_time`, `sum_count`, and `entries` of `[ ms, calls, samples ]`.
	 * @param int                    $count        Profiled requests.
	 * @param float                  $sum_req_time Their summed profiled time, overlays (listeners, QM timers) left out.
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
				'time'    => self::mean( Core::num_float( $data['sum_time'] ?? null ), $count ),
				'count'   => self::mean( Core::num_float( $data['sum_count'] ?? null ), $count ),
				'samples' => Core::num_int( $data['samples'] ?? null ),
				'entries' => $entries,
			];
		}
		return [
			'count'      => $count,
			'total_time' => self::mean( $sum_req_time, $count ),
			'categories' => $categories,
		];
	}

	/**
	 * A mean over the things that HAVE a value. Dividing by every request
	 * instead would understate it by the unmeasured fraction, and the mean
	 * of none is null: not measured, never 0.
	 *
	 * @param float $sum Summed values.
	 * @param int   $n   How many contributed one.
	 */
	private static function mean( float $sum, int $n ): ?float {
		return $n > 0 ? $sum / $n : null;
	}

	/**
	 * The `urls` question bound arguments ask, as `url_page()` takes it: the
	 * page whose URLs it lists, '' for pages, then the scope and filters; an
	 * unknown sort reads as `count`, any order but `asc` as `desc`, and the
	 * listing's limit and offset clamp inside `Stats_Query`'s bounds.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @return array{0:string, 1:string, 2:Url_Filters, 3:string, 4:string, 5:int, 6:int}
	 */
	private static function url_question( array $args ): array {
		$sort  = Core::as_string( $args['sort'] );
		$order = Core::as_string( $args['order'] );
		return [
			Core::as_string( $args['page'] ),
			Core::as_string( $args['server'] ),
			[
				'search'          => \trim( Core::as_string( $args['search'] ) ),
				'filter'          => Stats_Query::strings( $args['filter'] ),
				// Opts IN: the default EXCLUDES. See decision 15.
				'include_workers' => true === $args['include_workers'],
				'bucket'          => Core::as_string( $args['bucket'] ),
			],
			isset( self::URL_SORTS[ $sort ] ) ? $sort : 'count',
			'asc' === $order ? 'asc' : 'desc',
			\min( Stats_Query::MAX_OFFSET, \max( 0, Core::as_int( $args['offset'] ) ) ),
			\min( Stats_Query::MAX_LIMIT, \max( 1, Core::as_int( $args['limit'] ) ) ),
		];
	}

	/**
	 * One listing of the URL set the filters leave, over the chart's buckets
	 * or the selected ones, read live: its totals, and its rows,
	 * pages or one page's URLs, with the slowest ten beside them, shaped by
	 * `url_reply()`. Worker traffic is left out unless asked for. A filter
	 * narrows the rows, the totals and the slowest ten together, and a filter
	 * on `worker`, negated or not, replaces the scope's.
	 *
	 * @param string       $page    The page whose URLs to list, by its hash; '' lists pages.
	 * @param string       $server  Server to scope to; '' is every server.
	 * @param Url_Filters  $filters The url filters in force.
	 * @param string       $sort    A `URL_SORTS` key.
	 * @param string       $order   'asc' or 'desc'.
	 * @param int          $offset  Listing offset, inside `Stats_Query`'s bounds.
	 * @param int          $limit   Listing size, inside `Stats_Query`'s bounds.
	 * @param int          $now     The reply's clock.
	 * @return array{data: list<array<string,mixed>>, rows: int, totals: array<string,mixed>|null, slowest: list<array<string,mixed>>, filters: array<string,mixed>, limit: int, offset: int, available: bool}
	 * @throws \InvalidArgumentException On a selection `Bucket::selection()` refuses, a filter `url_filter_of()` refuses or a search with no word.
	 */
	private static function url_page( string $page, string $server, array $filters, string $sort, string $order, int $offset, int $limit, int $now ): array {
		$base   = self::url_set( $page, $server, $filters, $now );
		$answer = self::url_totals( $base );
		$rows   = '' === $page ? self::page_listing( $base, $sort, $order, $offset, $limit ) : self::url_listing( $base, $sort, $order, $offset, $limit );
		return self::url_reply( $page, $server, $filters, $answer, $rows, $base['buckets'], $offset, $limit, $now );
	}

	/**
	 * The requests a `urls` question reads, as the spec its totals and its
	 * listing share: the filter, the page `--page` names, the scope, the
	 * buckets and the search. A named page owns the page field, so a filter
	 * token naming it is refused beside one.
	 *
	 * @param string      $page    The page `--page` names, by its hash; '' names none.
	 * @param string      $server  Server to scope to; '' is every server.
	 * @param Url_Filters $filters The url filters in force.
	 * @param int         $now     The reply's clock.
	 * @return array{source:string, filter:array<string,list<scalar>>, buckets:list<array{0:int,1:int}>, search:string}
	 * @throws \InvalidArgumentException On a selection `Bucket::selection()` refuses or a filter `url_filter_of()` refuses.
	 */
	private static function url_set( string $page, string $server, array $filters, int $now ): array {
		$filter  = self::url_filter_of( $filters['filter'], '' === $page ? self::URL_SET_OWNERS : self::URL_SET_OWNERS + self::PAGE_OWNER );
		$filter += '' === $page ? [] : [ 'page' => [ $page ] ];
		$filter += self::scope( $server, $filters['include_workers'] || \in_array( 'worker', Stats_Query::filtered( $filter ), true ) );
		return [
			'source'  => 'requests',
			'filter'  => $filter,
			'buckets' => self::buckets_of( $filters['bucket'], $now ),
			'search'  => $filters['search'],
		];
	}

	/**
	 * A URL set's totals: `URL_TOTALS`, ungrouped.
	 *
	 * @param array{source:string, filter:array<string,list<scalar>>, buckets:list<array{0:int,1:int}>, search:string} $base A `url_set()`.
	 * @return array{columns: list<string>, rows: list<list<mixed>>, available: bool}
	 */
	private static function url_totals( array $base ): array {
		return Stats_Query::run( $base + [ 'group' => [], 'measure' => self::URL_TOTALS ] );
	}

	/**
	 * One listing of a URL set's URLs, `url`-grouped over `URL_MEASURES`,
	 * the slowest ten as `also`.
	 *
	 * @param array{source:string, filter:array<string,list<scalar>>, buckets:list<array{0:int,1:int}>, search:string} $base A `url_set()`.
	 * @param string $sort   A `URL_SORTS` key.
	 * @param string $order  'asc' or 'desc'.
	 * @param int    $offset Listing offset.
	 * @param int    $limit  Listing size.
	 * @return array{columns: list<string>, rows: list<list<mixed>>, also?: list<list<mixed>>}
	 */
	private static function url_listing( array $base, string $sort, string $order, int $offset, int $limit ): array {
		return Stats_Query::run( [ 'group' => [ 'url' ], 'sort' => self::URL_SORTS[ $sort ] ] + self::listing_spec( $order, $offset, $limit ) + $base );
	}

	/**
	 * One listing of a URL set's pages, `page`- and `server`-grouped over
	 * `URL_MEASURES` as the snapshot keeps them, the slowest ten as `also`,
	 * each row's `shapes` and `hash` after its measures.
	 *
	 * @param array{source:string, filter:array<string,list<scalar>>, buckets:list<array{0:int,1:int}>, search:string} $base A `url_set()` naming no page.
	 * @param string $sort   A `URL_SORTS` key.
	 * @param string $order  'asc' or 'desc'.
	 * @param int    $offset Listing offset.
	 * @param int    $limit  Listing size.
	 * @return array{columns: list<string>, rows: list<list<mixed>>, also: list<list<mixed>>}
	 * @throws \LogicException When a listed page holds no URL of the set.
	 */
	private static function page_listing( array $base, string $sort, string $order, int $offset, int $limit ): array {
		$listed = Stats_Query::run( [ 'group' => [ 'page', 'server' ], 'sort' => self::PAGE_SORTS[ $sort ] ] + self::listing_spec( $order, $offset, $limit ) + $base );
		$also   = $listed['also'] ?? throw new \LogicException( 'a spec naming also answers it' );
		$shapes = self::shapes_of( $base, [ ...$listed['rows'], ...$also ] );
		return [
			'columns' => [ ...$listed['columns'], 'shapes', 'hash', 'url_name' ],
			'rows'    => self::shaped( $listed['rows'], $shapes ),
			'also'    => self::shaped( $also, $shapes ),
		];
	}

	/**
	 * What a listing of `URL_MEASURES` sorts, pages and ranks its slowest by.
	 *
	 * @param string $order  'asc' or 'desc'.
	 * @param int    $offset Listing offset.
	 * @param int    $limit  Listing size.
	 * @return array{measure: list<string>, order: string, limit: int, offset: int, also: array{sort: string, order: string, limit: int}}
	 */
	private static function listing_spec( string $order, int $offset, int $limit ): array {
		return [
			'measure' => self::URL_MEASURES,
			'order'   => $order,
			'limit'   => $limit,
			'offset'  => $offset,
			'also'    => [
				'sort'  => 'avg_ms',
				'order' => 'desc',
				'limit' => self::SLOWEST_ROWS,
			],
		];
	}

	/**
	 * Each listed page's shapes in the set, by page hash then server: the
	 * URLs it holds, and the one URL's hash and name where it holds one, else
	 * null.
	 *
	 * @param array{source:string, filter:array<string,list<scalar>>, buckets:list<array{0:int,1:int}>, search:string} $base A `url_set()` naming no page.
	 * @param list<list<mixed>> $rows Page rows, each its hash, name and server first.
	 * @return array<string,array<string,array{0:int,1:?string,2:?string}>>
	 */
	private static function shapes_of( array $base, array $rows ): array {
		$shapes = [];
		foreach ( self::every_row( $base, $rows, [ 'page', 'server' ], 'urls' ) as [ $page, , $server, $urls ] ) {
			$shapes[ Core::as_string( $page ) ][ Core::as_string( $server ) ] = [ Core::num_int( $urls ), null, null ];
		}
		$single = \array_filter( $rows, static fn ( array $row ): bool => 1 === ( $shapes[ Core::as_string( $row[0] ) ][ Core::as_string( $row[2] ) ][0] ?? null ) );
		foreach ( self::every_row( $base, $single, [ 'page', 'server', 'url' ], 'count' ) as [ $page, , $server, $url, $name ] ) {
			if ( 1 === ( $shapes[ Core::as_string( $page ) ][ Core::as_string( $server ) ][0] ?? null ) ) {
				$shapes[ Core::as_string( $page ) ][ Core::as_string( $server ) ] = [ 1, Core::as_string( $url ), Core::as_string( $name ) ];
			}
		}
		return $shapes;
	}

	/**
	 * Every row of a URL set's requests on the pages `$rows` name, grouped by
	 * `$group` over one measure, `Stats_Query::MAX_LIMIT` at a time until a
	 * short read; past `Stats_Query::MAX_OFFSET` the query refuses.
	 *
	 * @param array{source:string, filter:array<string,list<scalar>>, buckets:list<array{0:int,1:int}>, search:string} $base A `url_set()` naming no page.
	 * @param array<list<mixed>> $rows    Page rows, each its hash first.
	 * @param list<string>       $group   The fields to group by, `page` first.
	 * @param string             $measure The measure.
	 * @return list<list<mixed>>
	 */
	private static function every_row( array $base, array $rows, array $group, string $measure ): array {
		$pages = \array_values( \array_unique( \array_map( static fn ( array $row ): string => Core::as_string( $row[0] ), $rows ) ) );
		$found = [];
		for ( $offset = 0; [] !== $pages; $offset += Stats_Query::MAX_LIMIT ) {
			$read = Stats_Query::run(
				[
					'group'   => $group,
					'filter'  => [ 'page' => $pages ] + $base['filter'],
					'measure' => [ $measure ],
					'sort'    => 'page',
					'order'   => 'asc',
					'limit'   => Stats_Query::MAX_LIMIT,
					'offset'  => $offset,
				] + $base
			)['rows'];
			\array_push( $found, ...$read );
			if ( \count( $read ) < Stats_Query::MAX_LIMIT ) {
				break;
			}
		}
		return $found;
	}

	/**
	 * Page rows with each one's `shapes`, `hash` and `url_name` after its
	 * measures.
	 *
	 * @param list<list<mixed>>                                           $rows   Page rows, each its hash, name and server first.
	 * @param array<string,array<string,array{0:int,1:?string,2:?string}>> $shapes `shapes_of()` the rows.
	 * @return list<list<mixed>>
	 * @throws \LogicException When a row's page holds no URL of the set.
	 */
	private static function shaped( array $rows, array $shapes ): array {
		$shaped = [];
		foreach ( $rows as $row ) {
			$page     = Core::as_string( $row[0] );
			$shaped[] = [ ...$row, ...( $shapes[ $page ][ Core::as_string( $row[2] ) ] ?? throw new \LogicException( \esc_html( "listed page {$page} holds no URL" ) ) ) ];
		}
		return $shaped;
	}

	/**
	 * The `urls` reply a listing and its totals make, live or off the
	 * snapshot: its rows, its exact totals and its slowest ten, every mean
	 * request-weighted. A listing of pages answers page rows and counts its
	 * pages as `rows`, the pager's; a listing of one page's URLs answers URL
	 * rows and counts its URLs. The totals carry the errors counted.
	 *
	 * @param string                                                                     $page    The page whose URLs are listed, by its hash; '' for a listing of pages.
	 * @param string                                                                     $server  Server scoped to; '' is every server.
	 * @param Url_Filters                                                                $filters The url filters in force.
	 * @param array{columns: list<string>, rows: list<list<mixed>>, available: bool}     $answer  The totals: `URL_TOTALS`, ungrouped.
	 * @param array{columns: list<string>, rows: list<list<mixed>>, also?: list<list<mixed>>} $rows The page- or URL-grouped listing over `URL_MEASURES`, the slowest as `also`.
	 * @param list<array{0:int,1:int}>                                                   $buckets The buckets read.
	 * @param int                                                                        $offset  Listing offset.
	 * @param int                                                                        $limit   Listing size.
	 * @param int                                                                        $now     The reply's clock.
	 * @return array{data: list<array<string,mixed>>, rows: int, totals: array<string,mixed>|null, slowest: list<array<string,mixed>>, filters: array<string,mixed>, limit: int, offset: int, available: bool}
	 */
	private static function url_reply( string $page, string $server, array $filters, array $answer, array $rows, array $buckets, int $offset, int $limit, int $now ): array {
		$totals            = self::measures( $answer );
		$count             = Core::num_int( $totals['count'] );
		[ $shape, $pager ] = '' === $page ? [ self::page_rows( ... ), 'pages' ] : [ self::url_rows( ... ), 'urls' ];
		$slowest           = [
			'columns' => $rows['columns'],
			'rows'    => $rows['also'] ?? throw new \LogicException( 'a spec naming also answers it' ),
		];
		return [
			'data'    => $shape( $rows ),
			'rows'    => Core::num_int( $totals[ $pager ] ),
			'totals'  => $answer['available'] ? [
				'urls'                => Core::num_int( $totals['urls'] ),
				'pages'               => Core::num_int( $totals['pages'] ),
				'requests'            => $count,
				'avg_ms'              => Ask_Assembler::measured_figure( $totals['avg_ms'] ),
				'avg_peak_mb'         => Ask_Assembler::measured_figure( $totals['avg_peak_mb'] ),
				'requests_per_second' => $count / self::elapsed_seconds( $buckets, $now ),
				'errors'              => Core::num_int( $totals['errors'] ),
			] : null,
			'slowest' => $shape( $slowest ),
			'filters' => [
				'server'          => $server,
				'page'            => $page,
				'search'          => $filters['search'],
				'filter'          => $filters['filter'],
				'include_workers' => $filters['include_workers'],
				'bucket'          => '' === $filters['bucket'] ? '' : Bucket::spelling( $buckets ),
			],
			'limit'   => $limit,
			'offset'  => $offset,
			'available' => $answer['available'],
		];
	}

	/**
	 * The filter a server scope and the worker choice name, which the live
	 * reads apply to requests.
	 *
	 * @param string $server  Server to scope to; '' is every server.
	 * @param bool   $workers Keep worker requests; false leaves them out.
	 * @return array<string,list<scalar>>
	 */
	public static function scope( string $server, bool $workers ): array {
		return ( $workers ? [] : [ 'worker' => [ 0 ] ] ) + ( '' === $server ? [] : [ 'server' => [ $server ] ] );
	}

	/**
	 * A `page`- and `server`-grouped listing's rows as the `urls` reply
	 * carries them: the page by its hash and its name, its server, its
	 * `shapes`, the URLs folded, `hash`, the one URL's
	 * hash where it folds one, else null, and `url`, the name the row shows:
	 * the one URL's own name, its case, query and scheme kept, or for a page
	 * of more `https://` and the page; then its measures, as `measured()`
	 * shapes them. A page's `worker` is true where any shape folded is a
	 * worker's.
	 *
	 * @param array{columns: list<string>, rows: list<list<mixed>>} $answer A page listing over `URL_MEASURES`, `shapes`, `hash` and `url_name`.
	 * @return list<array<string,mixed>>
	 */
	private static function page_rows( array $answer ): array {
		$rows = [];
		foreach ( $answer['rows'] as $values ) {
			$row    = \array_combine( $answer['columns'], $values );
			$rows[] = [
				'page_hash' => $row['page'],
				'page'      => $row['page_name'],
				'server'    => $row['server'],
				'shapes'    => $row['shapes'],
				'hash'      => $row['hash'],
				'url'       => 1 === Core::num_int( $row['shapes'] ) ? $row['url_name'] : 'https://' . Core::as_string( $row['page_name'] ),
			] + self::measured( $row );
		}
		return $rows;
	}

	/**
	 * A `url`-grouped answer's rows as the `urls` reply carries them: the
	 * URL's hash and its name, then its measures.
	 *
	 * @param array{columns: list<string>, rows: list<list<mixed>>} $answer A `Stats_Query::run()` answer grouped by `url` over `URL_MEASURES`.
	 * @return list<array{hash:mixed,url:mixed,count:mixed,timed_count:mixed,count_2xx:mixed,count_3xx:mixed,count_4xx:mixed,count_5xx:mixed,errors:mixed,sum_ms:mixed,min_ms:?float,max_ms:?float,sum_peak_mb:mixed,max_peak_mb:mixed,worker:bool,last_updated:mixed,avg_ms:?float,avg_peak_mb:?float}>
	 */
	private static function url_rows( array $answer ): array {
		$rows = [];
		foreach ( $answer['rows'] as $values ) {
			$row    = \array_combine( $answer['columns'], $values );
			$rows[] = [
				'hash' => $row['url'],
				'url'  => $row['url_name'],
			] + self::measured( $row );
		}
		return $rows;
	}

	/**
	 * A listed row's `URL_MEASURES` as a `urls` row carries them, means
	 * null where nothing was measured.
	 *
	 * @param array<string,mixed> $row A listing's row by column.
	 * @return array{count:mixed,timed_count:mixed,count_2xx:mixed,count_3xx:mixed,count_4xx:mixed,count_5xx:mixed,errors:mixed,sum_ms:mixed,min_ms:?float,max_ms:?float,sum_peak_mb:mixed,max_peak_mb:mixed,worker:bool,last_updated:mixed,avg_ms:?float,avg_peak_mb:?float}
	 */
	private static function measured( array $row ): array {
		return [
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
	 *                                   refuses, a filter that is not a
	 *                                   `field=value` pair, or a spec `run()` refuses.
	 */
	private static function answer_query( array $args, int $now ): array {
		$spelling = Core::as_string( $args['bucket'] );
		$buckets  = self::buckets_of( $spelling, $now, Stats_Query::retention( Core::as_string( $args['source'] ) ) );
		$filter   = self::filter_of( Stats_Query::strings( $args['filter'] ) );
		$search   = Core::as_string( $args['search'] );
		$spec     = [
			'source'  => Core::as_string( $args['source'] ),
			'group'   => Stats_Query::strings( $args['group'] ),
			'filter'  => self::by_id( $filter ),
			'measure' => Stats_Query::strings( $args['measure'] ),
			'buckets' => $buckets,
			'sort'    => Core::as_string( $args['sort'] ),
			'order'   => Core::as_string( $args['order'] ),
			'search'  => $search,
			'now'     => $now,
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
	 * The filter `urls`, `dump_url` and `ask` bind, as `Stats_Query` binds
	 * it. One argument owns each field, so a token naming a field `$owners`
	 * lists is refused, naming its owner.
	 *
	 * @param list<string>                                 $tokens The filter tokens.
	 * @param array<string,array{0:string,1:string}> $owners `URL_SET_OWNERS` or `ONE_URL_OWNERS`.
	 * @return array<string,list<string>>
	 * @throws \InvalidArgumentException On a token `filter_of()` refuses, or one naming an owned field.
	 */
	private static function url_filter_of( array $tokens, array $owners ): array {
		$filter = self::filter_of( $tokens );
		foreach ( $filter as $key => $values ) {
			if ( isset( $owners[ Stats_Query::field_of( $key ) ] ) ) {
				[ $owner, $names ] = $owners[ Stats_Query::field_of( $key ) ];
				throw new \InvalidArgumentException( \esc_html( "filter {$key}={$values[0]} belongs to {$owner}: name the {$names} with {$owner}" ) );
			}
		}
		return $filter;
	}

	/**
	 * The `filter` arg's tokens in one spelling, each field lowercase, a
	 * server as `Url_Parts::server_name()` stores it, and each token once, so
	 * a field matches in any case and a repeated token counts once: what
	 * `query`, `urls`, `dump_url` and `ask` read and echo, and what a stored
	 * answer's key is taken over.
	 *
	 * @param mixed $arg The bound `filter` arg.
	 * @return list<string>
	 */
	private static function filter_tokens( mixed $arg ): array {
		$tokens = [];
		foreach ( Stats_Query::strings( $arg ) as $token ) {
			$parts = \explode( '=', $token, 2 );
			if ( 2 !== \count( $parts ) ) {
				$tokens[] = $token;
				continue;
			}
			$field    = \strtolower( $parts[0] );
			$tokens[] = $field . '=' . ( 'server' === Stats_Query::field_of( $field ) ? Url_Parts::server_name( $parts[1] ) : $parts[1] );
		}
		return \array_values( \array_unique( $tokens ) );
	}

	/**
	 * `field=value` tokens as a filter, each token one value and a field's
	 * tokens ORed, and `field!=value` tokens keyed `field!`, their values
	 * excluded: the one parse `query`, `urls`, `dump_url` and `ask` share.
	 *
	 * @param list<string> $tokens The `filter_tokens()` tokens.
	 * @return array<string,list<string>>
	 * @throws \InvalidArgumentException On a token that is not a `field=value` pair.
	 */
	private static function filter_of( array $tokens ): array {
		$filter = [];
		foreach ( $tokens as $token ) {
			$parts = \explode( '=', $token, 2 );
			if ( 2 !== \count( $parts ) ) {
				throw new \InvalidArgumentException( \esc_html( "filter {$token} is not a field=value pair" ) );
			}
			$filter[ $parts[0] ][] = $parts[1];
		}
		return $filter;
	}

	/**
	 * A filter as `Stats_Query` binds it: each bucket key as its id.
	 *
	 * @param array<string,list<string>> $filter A `filter_of()` filter.
	 * @return array<string,list<scalar>>
	 * @throws \InvalidArgumentException On a bucket key `Bucket::of_key()` refuses.
	 */
	private static function by_id( array $filter ): array {
		foreach ( $filter as $key => $values ) {
			if ( 'bucket' === Stats_Query::field_of( $key ) ) {
				$filter[ $key ] = \array_map( Bucket::of_key( ... ), $values );
			}
		}
		return $filter;
	}

	/**
	 * The buckets a selection's spelling names, or the chart's when it
	 * names none.
	 *
	 * @param string   $spelling A `Bucket::selection()` spelling; '' for the chart's.
	 * @param int      $now      The reply's clock.
	 * @param int|null $max      Most buckets the spelling may name; null is the chart's span.
	 * @return list<array{0:int,1:int}>
	 * @throws \InvalidArgumentException On a selection `Bucket::selection()` refuses.
	 * @throws \RuntimeException When `min_lifetime` is short of one bucket.
	 */
	private static function buckets_of( string $spelling, int $now, ?int $max = null ): array {
		return '' === $spelling ? Bucket::last( Bucket::window(), $now ) : Bucket::selection( $spelling, $now, $max ?? Bucket::window() );
	}

	/**
	 * Epoch seconds from `Core::$now`, read once per verb and passed down,
	 * per ELN decision 29.
	 */
	private static function now(): int {
		return (int) Core::$now;
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
		$life = 'serves ' . Overview_Prefetch::SNAPSHOT_INTERVALS . ' of its tick\'s intervals, ' . Overview_Prefetch_Tick_Node::INTERVAL_S . ' s each by default';
		[ 'window' => $span, 'kept' => $kept ] = Bucket::span_words();
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Performance-dashboard surface: overview, URLs, requests, hooks, config, settings.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'overview',
					'capability'  => Capabilities::READ,
					'description' => 'The site over the last ' . $span . ' buckets, the chart\'s: non-worker totals, every server with traffic there, worker traffic counted, busiest first, and the category leaderboard over them. `server` scopes the totals and the leaderboard, never `servers`; chart series come from `query`. Stats that will not open answer `available` false and `totals` null. A default Overview question is answered from the overview-prefetch job\'s last run, which ' . $life . ', from the end of its build; a scoped one is answered from that run where it listed the server; one it did not list names the `servers` that run stored, read live where none is stored or the scoped server has traffic they lack, and a blank `server` is the default question; `as_of` dates every answer.',
					'args'        => [
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
					],
					'handler'     => static fn ( self $self, array $args, array $envelope = [] ): array => $self->answered( 'overview', $args ),
				],
				[
					'name'        => 'urls',
					'capability'  => Capabilities::READ,
					'description' => 'Paginated, sortable table of pages, a page being a URL\'s lowercased host and path, with exact totals and the slowest ten, every mean request-weighted; stats that will not open answer `available` false and `totals` null. A page row folds the URLs the set holds of that page on one server, its case spellings and its query shapes: `page_hash`, `page`, `server`, `shapes` (the URLs folded), `hash` (the one URL\'s hash, null where it folds more), `url` (the name the row shows: the one URL\'s own name, its case, query and scheme kept, or for a page of more `https://` and the page), then the measures a URL row carries; its `worker` is true where any shape folded is a worker\'s, so under `include_workers` a page with one worker shape among visitor shapes reads true. The `url` sort orders pages by `page`. `--page=<page hash>` answers that page\'s URLs instead, each a URL row: `hash`, `url` and the measures; it owns the page field, so a `page=` or `page!=` filter beside it is refused. `--filter=page=<page hash>` keeps that one page row, as any filter narrows the pages. `rows` is the exact count the pager lists, the pages or under `--page` that page\'s URLs, and `totals` counts both `urls` and `pages`. Where no timed request reached a row, its `avg_ms`, `min_ms` and `max_ms` are null, and it ranks last on those three sorts in either order. A question setting no arg but `sort`, `order`, `limit`, `offset`, `server`, `page` and `include_workers` is answered from the overview-prefetch job\'s URL snapshot, which ' . $life . ', from the end of its build; any other answers live without opening it; `as_of` dates every answer.',
					'args'        => [
						[ 'name' => 'sort', 'type' => 'string', 'required' => false, 'default' => 'count' ],
						[ 'name' => 'order', 'type' => 'string', 'required' => false, 'default' => 'desc' ],
						[ 'name' => 'limit', 'type' => 'int', 'required' => false, 'default' => 50 ],
						[ 'name' => 'offset', 'type' => 'int', 'required' => false, 'default' => 0 ],
						[ 'name' => 'search', 'type' => 'string', 'required' => false ],
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						[ 'name' => 'page', 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'A page\'s hash, `page_hash`: answers that page\'s URL rows in place of pages. It owns the page field, so a `page=` or `page!=` filter beside it is refused.' ],
						self::FILTER_ARG,
						[ 'name' => 'include_workers', 'type' => 'bool', 'required' => false, 'default' => false ],
						[ 'name' => 'bucket', 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'A selection of five-minute buckets as `Bucket::selection()` reads one: comma-separated runs, each a `Y-m-d-H-i` UTC key or `start..end`, the buckets from `start` up to the key that closes it, `end` not among them, at most ' . $span . ' buckets, none later than the current one. Narrows every row, the totals and the rate to the requests completing in them; empty is the last ' . $span . '. `filters.bucket` echoes the canonical spelling.' ],
					],
					'handler'     => static fn ( self $self, array $args, array $envelope = [] ): array => $self->answered( 'urls', $args ),
				],
				[
					'name'        => 'query',
					'capability'  => Capabilities::READ,
					'description' => 'One question over the request and timing rows, in `Stats_Query`\'s vocabulary: group by fields, filter on them and answer measures over a bucket selection. Answers `{ as_of, source, table, columns, rows, groups, available, filters }`, each row positional in `columns` order, each bucket spelled as its key; no rows and `available`, false when the stats file will not open. Worker requests count unless filtered `worker=0`. Under `top` the rows are every time row of the `top` tuples of the non-time groups `top_by` ranks highest, in rank order, and `groups` counts the tuples; with no `top`, `groups` is null. A default Overview question is answered from the overview-prefetch job\'s last run, which ' . $life . ', from the end of its build; `as_of` dates every answer.',
					'args'        => [
						[ 'name' => 'source', 'type' => 'string', 'required' => true ],
						[ 'name' => 'group', 'type' => 'string', 'required' => false, 'variadic' => true ],
						self::FILTER_ARG,
						[ 'name' => 'measure', 'type' => 'string', 'required' => false, 'variadic' => true ],
						[ 'name' => 'bucket', 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'A selection as `Bucket::selection()` reads one, at most as many buckets as the source keeps, `Stats_Query::retention()`: ' . $kept . ' buckets for either source; empty is the last ' . $span . ' buckets.' ],
						[ 'name' => 'search', 'type' => 'string', 'required' => false, 'default' => '' ],
						[ 'name' => 'sort', 'type' => 'string', 'required' => false, 'default' => '' ],
						[ 'name' => 'order', 'type' => 'string', 'required' => false, 'default' => 'asc' ],
						[ 'name' => 'limit', 'type' => 'int', 'required' => false, 'description' => 'Most rows, up to `Stats_Query::MAX_LIMIT`, which is what an absent limit answers; refused under `top`.' ],
						[ 'name' => 'offset', 'type' => 'int', 'required' => false, 'description' => 'Rows to skip, none when absent; refused under `top`.' ],
						[ 'name' => 'top', 'type' => 'int', 'required' => false, 'description' => 'Rank the tuples of the non-time groups and answer the time rows of this many, at most `Stats_Query::MAX_TOP`; the group needs a time field and a non-time field.' ],
						[ 'name' => 'top_by', 'type' => 'string', 'required' => false, 'description' => 'The measure `top` ranks by, summed over the selection, highest first, ties by the tuple\'s values: one that adds across buckets — for `requests` `count`, `timed`, `sum_ms`, `sum_peak_mb`, `errors`, `count_2xx`, `count_3xx`, `count_4xx` or `count_5xx`, for `timings` `ms`, `calls` or `requests`.' ],
					],
					'handler'     => static fn ( self $self, array $args, array $envelope = [] ): array => $self->answered( 'query', $args ),
				],
				[
					'name'        => 'dump_url',
					'capability'  => Capabilities::READ,
					'description' => 'One URL from its request rows, worker traffic counted: `stats` over the last ' . $span . ' buckets or a `bucket` selection, its newest RECENT_REQUEST_LIMIT requests by `finished_at` with each one\'s start, `requests_capped` true when the list holds fewer requests than the stats count, the aggregate flame rebuilt from the stored flames of the newest FLAME_REQUEST_LIMIT of them when no filter or bucket narrows the list, null with `flame_cut` true when MAX_SCAN_S ended the rebuild first, and `aggregate_profiles`, its category profile over the ' . $kept . ' buckets the timings keep, null over no profiled request. `server` scopes the stats, the list and the profile, and `filter` narrows the stats and the list. A stats file that will not open answers zero stats, no list and a null flame and profile. Each row\'s `duration_ms` is null where no sample was taken; `avg_ms`, `min_ms`, `max_ms` and `avg_peak_mb` are null where nothing was measured, as are the nearest-rank `p50_ms`, `p95_ms` and `p99_ms` in `stats`, which also carries `error_rate`, `errors` over `count`. Series come from `query` filtered to the URL.',
					'args'        => [
						[ 'name' => 'hash', 'type' => 'string', 'required' => true ],
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						self::FILTER_ARG,
						[ 'name' => 'bucket', 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'A selection of five-minute buckets, as `urls` takes it: the stats and the list become the requests completing in them, zero where the URL has none, and no flame is rebuilt from the list. A selection `urls` refuses is refused.' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$hash = Core::as_string( $args['hash'] );
				if ( ! \preg_match( '/^[a-f0-9]{8,64}$/D', $hash ) ) {
					throw new \RuntimeException( 'invalid hash format' );
				}
				// @longform The row that opened this modal belonged to the selected
				// server, so this answers for the same server — otherwise one
				// click puts a site-wide average under a scoped count, on two
				// surfaces too far apart to compare.
				$server   = Core::as_string( $args['server'] );
				$tokens   = self::filter_tokens( $args['filter'] );
				$spelling = Core::as_string( $args['bucket'] );
				$now      = self::now();
				$detail   = self::url_detail( $hash, $server, $tokens, $spelling, $now, self::RECENT_REQUEST_LIMIT );
				// A narrowed list's flame would not describe the URL.
				$rebuilt = $detail['available'] && [] === $tokens && '' === $spelling;
				$flame   = $rebuilt ? self::url_flame( $detail['listed'] ) : null;
				return [
					'stats'              => $detail['stats'],
					'requests'           => self::url_requests( $detail['listed'] ),
					'requests_capped'    => $detail['requests_capped'],
					'aggregate_flame'    => $flame,
					'flame_cut'          => $rebuilt && null === $flame,
					'aggregate_profiles' => self::url_profile( $hash, self::scope( $server, true ), $now ),
					'aggregate_owners'   => self::owner_board( self::scope( $server, true ) + [ 'url' => [ $hash ] ], Bucket::last( Stats_Query::retention( 'owners' ), $now ), $now ),
				];
					},
				],
				[
					'name'        => 'search_requests',
					'capability'  => Capabilities::READ,
					'description' => 'Locate a request by rid: {rid, url_hash} from its request row.',
					'args'        => [
						[ 'name' => 'rid', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$rid = Core::as_string( $args['rid'] );
				return [
					'rid'      => $rid,
					'url_hash' => self::row_of( $rid )['url'],
				];
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
					'description' => 'Full request + flame data for a rid, read at the place its request row names, with `flame_cut` true when MAX_SCAN_S ended the flame lookup before it found `flame_data`; is not found when no row names the rid (aged past the ' . $kept . ' buckets the rows keep, or not yet written) or when its record has aged out of the raw logs, which keep it at least `min_lifetime` unless `max_segments` prunes it sooner, though the request still counts in every aggregate.',
					'args'        => [
						[ 'name' => 'rid', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$result = self::load_request( Core::as_string( $args['rid'] ) );
				// Findings ride the record; no model is involved in them.
				$rule               = self::rule_for_record( $result );
				$result['findings'] = Findings::for_request( $result, $rule );
				$result['caveat']   = Findings::caveat();
				$result['owners']   = Findings::owners( $result );
				return $result;
					},
				],
				[
					'name'        => 'ask',
					'capability'  => Capabilities::READ,
					'description' => 'Assemble the brief for one picker descriptor: `ask <descriptor> [<context-descriptor>…]`, outermost context last. `filter` narrows an `overview:` and a `url:` brief as `urls` and `dump_url` narrow them, and `bucket` to a selection of stored buckets.',
					'args'        => [
						[ 'name' => 'descriptor', 'type' => 'string', 'required' => true ],
						// The trailing descriptors, or `--context=` repeated.
						[ 'name' => 'context', 'type' => 'string', 'required' => false, 'variadic' => true ],
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						// Overview briefs read all four; URL briefs two.
						[ 'name' => 'search', 'type' => 'string', 'required' => false ],
						self::FILTER_ARG,
						[ 'name' => 'include_workers', 'type' => 'bool', 'required' => false, 'default' => false ],
						[ 'name' => 'bucket', 'type' => 'string', 'required' => false, 'default' => '', 'description' => 'Narrows an `overview:` brief to a selection of buckets as `urls` narrows it, and a `url:` brief as `dump_url` does, to the requests completing inside them, the brief carrying the canonical spelling. Every other descriptor ignores it, and neither checks nor refuses it.' ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				return self::assemble_ask(
					Core::as_string( $args['descriptor'] ),
					Stats_Query::strings( $args['context'] ),
					Core::as_string( $args['server'] ),
					[
						'search'          => Core::as_string( $args['search'] ),
						'filter'          => self::filter_tokens( $args['filter'] ),
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
				$by_category = Hook_Categorizer::get_registered_hooks_by_category();
				$names       = \array_merge( ...\array_values( $by_category ) );
				return [
					'total_hooks'           => \count( $names ),
					'categories'            => Hook_Categorizer::get_categories(),
					'category_descriptions' => Hook_Categorizer::get_descriptions(),
					'hooks_by_category'     => $by_category,
					'noisy'                 => AppConfig::noisy_hooks( $names ),
				];
					},
				],
				[
					'name'        => 'set',
					'capability'  => Capabilities::TUNE,
					'description' => 'The spoke side of a hub\'s ruleset push: write `newspack_event_logger_nodes_rules` from its JSON, the one option `hub-control.tsl` syncs here. Any other option is refused.',
					'args'        => [
						[ 'name' => 'option', 'type' => 'string', 'required' => true ],
						[ 'name' => 'value', 'type' => 'string', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				$option = Core::as_string( $args['option'] );
				if ( Rule_Set::OPTION_RULES !== $option ) {
					throw new \RuntimeException( \esc_html( "unknown option: {$option}" ) );
				}
				if ( null === $args['value'] ) {
					throw new \RuntimeException( 'value required' );
				}
				$sanitized = self::sanitize_settings_array( self::decode_array_value( Core::as_string( $args['value'] ) ) );
				if ( null === $sanitized ) {
					throw new \RuntimeException( 'invalid value for option' );
				}
				// apply_synced re-tiers and holds its own no-op gate.
				$changed = Rule_Set::apply_synced( $sanitized );
				if ( $changed ) {
					AppConfig::reset();
				}
				return [
					'option'  => $option,
					'updated' => $changed,
				];
					},
				],
			],
		] );
	}
}
