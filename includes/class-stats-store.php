<?php
/**
 * Stats Store
 *
 * The schema for performance stats: which Ledger each measurement lives in,
 * the key and member a row carries there, and the reads the dashboards make.
 * `flame-builder.tsl` declares the Ledgers (`LEDGER_COLUMNS`), each one
 * SQLite file every flame-builder partition appends to, and the `url` Table
 * that holds each URL's flame blob. `Flame_Builder_Node` appends a span's
 * rows and `App\Performance_CI_Node` reads them.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Durable_Arm;
use Newspack_Nodes\Table_Client;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stats as write-once rows, reached by message.
 *
 * A row is `[ t, k, x, [ columns… ] ]`: `t` the start of the five-minute
 * bucket a request completed in, `k` a whitespace-free key naming a scope,
 * `x` the member a scope holds, and the columns a Ledger declares, in its
 * declared order. A span appends its deltas and never reads first; a read
 * sums every partition's rows for a `( k, x )` over a window, `[ from, to )`
 * in epoch seconds, which the caller reads off the tick once.
 *
 * Three of the column orders are the positional constants below, so a read
 * names a column by constant and never by a bare index: a dimension value's
 * (`DIM_*`), a category's (`CAT_*`) and a URL row's (`ROW_*`).
 *
 * The store asks through its owner's `Table_Client`, in-process: the builder
 * appends to its worker graph's Ledgers, and a reader mounts them into its
 * request graph. Reads fail soft: a Ledger that refuses or does not answer
 * reads as empty, the store says so through `unanswered()`, and the
 * dashboards render "no data" rather than an error. A Ledger the store
 * was not given, because no active topology declares it, reads as empty
 * too. Keep it that way; the SSE slot pool is deliberately the opposite,
 * and unifying the two breaks its rate limit.
 */
class Stats_Store {

	/** Site request totals: k `site`, x ''. */
	public const LEDGER_TOTALS = 'stats:totals';

	/** Dimension values: k the dimension, or `{dim}:{server_key}`; x the value. */
	public const LEDGER_DIMS = 'stats:dims';

	/** Category series: k `site` or `srv:{server_key}`; x the category. */
	public const LEDGER_CATEGORIES = 'stats:categories';

	/**
	 * The leaderboard: k `site` or `srv:{server_key}`; x '' for the requests
	 * profiled, the category, or `member( category, entry )`.
	 */
	public const LEDGER_LEADERBOARD = 'stats:leaderboard';

	/** URL rows: k `r:` or `w:` and the server key; x the URL. */
	public const LEDGER_URL_ROWS = 'stats:url-rows';

	/** One URL's dimensions: k `url_dim_key()`, one a dimension; x the value. */
	public const LEDGER_URL_DIMS = 'stats:url-dims';

	/** One URL's categories: k `url_key()`; x the category. */
	public const LEDGER_URL_CATS = 'stats:url-cats';

	/**
	 * The names a hash or a scope stands for, a set: k `hash_key()` holds
	 * the URL, and `servers_key()` each server that filed rows of a family.
	 */
	public const LEDGER_NAMES = 'stats:names';

	/** The word index, a set: k a word, x a URL. */
	public const LEDGER_SEARCH = 'stats:search';

	/**
	 * Every Ledger `flame-builder.tsl` declares, with its columns as the
	 * `make_node` line spells them. The line is the declaration; this is
	 * its one spelling in PHP, which `TopologyShapeTest` holds to it.
	 */
	public const LEDGER_COLUMNS = [
		self::LEDGER_TOTALS      => [ 'count', 'sum_ms', 'requests', 'sum_peak_mb' ],
		self::LEDGER_DIMS        => [ 'count', 'sum_ms', 'sum_peak_mb', 'timed' ],
		self::LEDGER_CATEGORIES  => [ 'sum_time', 'sum_count', 'samples' ],
		self::LEDGER_LEADERBOARD => [ 'samples', 'sum_time', 'sum_count' ],
		self::LEDGER_URL_ROWS    => [ 'count', 'timed_count', 'sum_ms', 'sum_peak_mb', 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx', 'errors', 'min_ms:min', 'max_ms:max', 'max_peak_mb:max', 'last_seen:max' ],
		self::LEDGER_URL_DIMS    => [ 'count', 'sum_ms', 'sum_peak_mb', 'timed' ],
		self::LEDGER_URL_CATS    => [ 'sum_time', 'sum_count', 'samples' ],
		self::LEDGER_NAMES       => [],
		self::LEDGER_SEARCH      => [],
	];

	/** Seconds one Ledger segment spans, as every declaration spells it. */
	public const SEGMENT_SECONDS = self::HOUR_SECONDS;

	/** Fewest segments a Ledger keeps: the 24 hours a chart draws, and the hour it is in. */
	public const MIN_SEGMENTS = 25;

	/** The key of a site-wide scope. */
	public const SITE = 'site';

	/** What joins two parts of one member, `member()`. */
	private const MEMBER_SEPARATOR = "\t";

	/** The per-URL blob Table: each URL's flame tree and profile, by hash. */
	public const TABLE_URL = 'flame-stats:url';

	/** Categories a URL's profile keeps, the slowest first, the rest folded into "Other". */
	public const MAX_LB_CATEGORIES = 200;

	/**
	 * Bytes a URL blob may take, and what every byte cap derives from. It
	 * bounds a worker's memory while its settle writes the blob and the
	 * unserialize every reader pays. It sits under memcached's 1,048,576-byte
	 * item, less a margin for the key and the framing, because `Rule_Set`'s
	 * hook list rides memcached through an `auto` Table and caps to it too.
	 * Uncompressed, because nothing guarantees production compresses. A
	 * producer caps before it writes, against an estimate `overhead()` makes
	 * for the serializer `Durable_Arm::serializer()` names.
	 */
	public const ITEM_BUDGET = 900000;

	/**
	 * Bytes each stored part costs under each serializer, before the strings
	 * it carries: every byte cap estimates a value as these plus `strlen()`.
	 * Measured with twelve-digit counts, doubles at their longest spelling
	 * and no string repeated, which igbinary would store once; the rest is
	 * margin. `flame_node` includes its key in its parent's list, and `hook`
	 * one name's framing in a rule's hook list.
	 */
	private const OVERHEADS = [
		self::SERIALIZER_PHP      => [
			'lb_category' => 180,
			'lb_entry'    => 100,
			'flame_node'  => 130,
			'hook'        => 20,
		],
		self::SERIALIZER_IGBINARY => [
			'lb_category' => 56,
			'lb_entry'    => 44,
			'flame_node'  => 36,
			'hook'        => 8,
		],
	];

	/** PHP's own `serialize()`: the larger, and what a handle-less estimate assumes. */
	public const SERIALIZER_PHP = 'php';

	/** igbinary: what a stored value takes where the memcached handle uses it. */
	public const SERIALIZER_IGBINARY = 'igbinary';

	/** The dimension naming the reporting server — the axis the picker is built from. */
	public const DIM_SERVER = 'server';

	/** The server a request whose producer named none is filed under. */
	public const UNKNOWN_SERVER = 'Unknown';

	/**
	 * The name a capped profile folds its tail categories into, and the
	 * server every server past `MAX_SERVER_VALUES` files under.
	 */
	public const OTHER_KEY = 'Other';

	/**
	 * Servers a reader window names at most. `server_name` is the client's
	 * Host header under Apache's default `UseCanonicalName Off`, so it is
	 * visitor input: past this many a server's rows file under `OTHER_KEY`,
	 * which keeps a site page's `TOP` inside its 500 keys, two a server.
	 */
	public const MAX_SERVER_VALUES = 128;

	/** Longest word the search matches; a longer one is cut to it on both sides. */
	public const TERM_WORD_MAX = 12;

	/** Shortest run of characters that counts as a WORD. */
	public const TERM_WORD_MIN = 2;

	/** What separates two words, as a character class. */
	private const TOKEN_SEP = '[^a-z0-9]';

	/**
	 * How long a URL page, and a scope's header, is cached: every tab
	 * polling the same page reads the Ledger once a refresh, and a searched
	 * page, which reads the scope's rows whole, pays that once a refresh.
	 */
	public const URL_PAGE_REFRESH_S = 60;

	/** The `--sort` values `urls` accepts. */
	public const URL_SORTS = [ 'count', 'url', 'avg_ms', 'min_ms', 'max_ms', 'avg_peak_mb', 'last_updated' ];

	/** The `--order` values `urls` accepts. */
	public const URL_ORDERS = [ 'asc', 'desc' ];

	/** The sort the URL header's `slowest` ranks by, `[ sort, order ]`. */
	public const SLOWEST_LIST = [ 'avg_ms', 'desc' ];

	/** Shortest retention window the stats work with, in seconds. */
	public const MIN_RETENTION_SECONDS = 3600;

	/**
	 * A dimension value's columns, `stats:dims` and `stats:url-dims` alike,
	 * named as a URL row's are because they are the same measurements: every
	 * request counts for volume, and an average divides the timed ones alone
	 * (decision 24).
	 */
	public const DIM_COUNT       = 0;
	public const DIM_SUM_MS      = 1;
	public const DIM_SUM_PEAK_MB = 2;
	public const DIM_TIMED       = 3;

	/** A dimension value's columns => whether each is a whole count. */
	public const DIM_SUMS = [
		self::DIM_COUNT       => true,
		self::DIM_SUM_MS      => false,
		self::DIM_SUM_PEAK_MB => false,
		self::DIM_TIMED       => true,
	];

	/**
	 * A category's columns, `stats:categories` and `stats:url-cats` alike:
	 * milliseconds of wall time, the events fired, and the requests the
	 * category appeared in. The reserved `total` row holds the request's own
	 * wall time instead, so its `CAT_REQUESTS` is a request count.
	 */
	public const CAT_MS       = 0;
	public const CAT_CALLS    = 1;
	public const CAT_REQUESTS = 2;

	/**
	 * Decimal places the category series goes out at: milliseconds a chart
	 * draws as seconds-per-second or as a mean, so a microsecond is already
	 * past anything rendered.
	 */
	public const CAT_MS_DECIMALS = 3;

	/** A category's columns => whether each is a whole count. */
	public const CAT_SUMS = [ self::CAT_MS => false, self::CAT_CALLS => true, self::CAT_REQUESTS => true ];

	/**
	 * A URL row's columns, `stats:url-rows` in its declared order: the nine
	 * that ADD first, in `ROW_SUMS` order, then the three extremes and the
	 * last completion. `ROW_TIMED_COUNT` counts only the requests whose
	 * duration was measured, which is what `min_ms` folds from; `ROW_ERRORS`
	 * counts only the requests that timed out or fataled
	 * (`Flame_Builder_Node::error_counts()`), whatever status they answered.
	 */
	public const ROW_COUNT       = 0;
	public const ROW_TIMED_COUNT = 1;
	public const ROW_SUM_MS      = 2;
	public const ROW_SUM_PEAK_MB = 3;
	public const ROW_COUNT_2XX   = 4;
	public const ROW_COUNT_3XX   = 5;
	public const ROW_COUNT_4XX   = 6;
	public const ROW_COUNT_5XX   = 7;
	public const ROW_ERRORS      = 8;
	public const ROW_MIN_MS      = 9;
	public const ROW_MAX_MS      = 10;
	public const ROW_MAX_PEAK_MB = 11;
	public const ROW_LAST_SEEN   = 12;

	/** A URL row's columns that ADD => whether each is a whole count. */
	public const ROW_SUMS = [
		self::ROW_COUNT       => true,
		self::ROW_TIMED_COUNT => true,
		self::ROW_SUM_MS      => false,
		self::ROW_SUM_PEAK_MB => false,
		self::ROW_COUNT_2XX   => true,
		self::ROW_COUNT_3XX   => true,
		self::ROW_COUNT_4XX   => true,
		self::ROW_COUNT_5XX   => true,
		self::ROW_ERRORS      => true,
	];

	/**
	 * Every URL row column and its name: the name the row carries once read,
	 * and the column's name in `LEDGER_COLUMNS`.
	 */
	public const ROW_FIELD_NAMES = [
		self::ROW_COUNT       => 'count',
		self::ROW_TIMED_COUNT => 'timed_count',
		self::ROW_SUM_MS      => 'sum_ms',
		self::ROW_SUM_PEAK_MB => 'sum_peak_mb',
		self::ROW_COUNT_2XX   => 'count_2xx',
		self::ROW_COUNT_3XX   => 'count_3xx',
		self::ROW_COUNT_4XX   => 'count_4xx',
		self::ROW_COUNT_5XX   => 'count_5xx',
		self::ROW_ERRORS      => 'errors',
		self::ROW_MIN_MS      => 'min_ms',
		self::ROW_MAX_MS      => 'max_ms',
		self::ROW_MAX_PEAK_MB => 'max_peak_mb',
		self::ROW_LAST_SEEN   => 'last_seen',
	];

	/** Status class (2..5) to the row column counting it. */
	public const ROW_STATUS_COUNTS = [
		2 => self::ROW_COUNT_2XX,
		3 => self::ROW_COUNT_3XX,
		4 => self::ROW_COUNT_4XX,
		5 => self::ROW_COUNT_5XX,
	];

	/**
	 * A URL row's extremes, each with its aggregate. A `min` or `max` is
	 * null where nothing was measured, as for the durations of a row no
	 * timed request reached, and the Ledger ranks a null last either way.
	 */
	private const ROW_EXTREMES = [
		self::ROW_MIN_MS      => 'min',
		self::ROW_MAX_MS      => 'max',
		self::ROW_MAX_PEAK_MB => 'max',
		self::ROW_LAST_SEEN   => 'max',
	];

	/** Bucket width in seconds: the `t` every row is filed at is a multiple of it. */
	public const BUCKET_SECONDS = 300;

	/** Seconds an hour spans. */
	public const HOUR_SECONDS = 3600;

	/** Buckets a chart draws: a day at the bucket width. */
	public const MAX_READ_BUCKETS = 288;

	/** The site totals' columns, by name => whether each is a whole count. */
	private const TOTAL_SUMS = [ 'count' => true, 'sum_ms' => false, 'requests' => true, 'sum_peak_mb' => false ];

	/** A leaderboard or profile's own summed fields. */
	private const LB_SUMS = [ 'count' => true, 'sum_req_time' => false ];

	/** One leaderboard category's summed fields. */
	public const LB_CAT_SUMS = [ 'samples' => true, 'sum_time' => false, 'sum_count' => false ];

	/**
	 * One leaderboard entry's positional triple: time, count, samples.
	 * A closed 3-tuple read by `sums_to_display()` beside it and by
	 * `RequestProfile.js`, the one carve-out from decision 18.
	 */
	private const LB_ENTRY_SUMS = [ 0 => false, 1 => false, 2 => true ];

	/** Whether a read this store made went unanswered. */
	private bool $unanswered = false;

	/**
	 * @param Table_Client         $client     The owner's asker; every exchange is in-process.
	 * @param array<string,string> $ledgers    Each Ledger => the node answering for it.
	 * @param list<string>         $url_tables The `url` Table nodes: a writer's own partition's,
	 *                                         or every partition's for a reader.
	 */
	public function __construct( private readonly Table_Client $client, private readonly array $ledgers, private readonly array $url_tables ) {
	}

	/**
	 * Append a span's rows, one APPEND per Ledger holding any, each its own
	 * transaction. A Ledger that refuses answers null; the rows it held are
	 * the span's loss (decision 3), which the caller counts.
	 *
	 * @param array<string,list<array{0:int,1:string,2:string,3:list<int|float|null>}>> $rows_by_ledger Ledger => rows; a `min` or `max` column may be null, a sum column never.
	 * @return array<string,array<array-key,mixed>|null> Ledger => `{ stored, dropped }`, or null.
	 */
	public function append_span( array $rows_by_ledger ): array {
		$out = [];
		foreach ( $rows_by_ledger as $ledger => $rows ) {
			if ( [] !== $rows ) {
				$out[ $ledger ] = $this->client->append( $this->node( $ledger ), $rows );
			}
		}
		return $out;
	}

	/**
	 * The node answering for a Ledger a span appends to.
	 *
	 * @param string $ledger The Ledger.
	 * @throws \LogicException When the store was not given it, which would
	 *                         take its rows to no node at all.
	 */
	private function node( string $ledger ): string {
		return $this->ledgers[ $ledger ] ?? throw new \LogicException( "Stats_Store was given no {$ledger}" );
	}

	/**
	 * The site's request totals over the window: `count` and `sum_ms` the
	 * timed requests' (decision 24), `requests` and `sum_peak_mb` every
	 * request's.
	 *
	 * @param int  $from      First second read.
	 * @param int  $to        The second the read stops short of.
	 * @param bool $by_bucket One total per bucket, by its start, oldest first.
	 * @return array<array-key,mixed> The totals, or bucket => totals.
	 */
	public function totals( int $from, int $to, bool $by_bucket ): array {
		$sums = $this->members( self::LEDGER_TOTALS, [ self::SITE ], $from, $to, $by_bucket, self::TOTAL_SUMS );
		if ( $by_bucket ) {
			return \array_map( static fn ( array $at ): array => Core::arr( $at[''] ?? null ), $sums );
		}
		return $sums[''] ?? \array_map( static fn ( bool $whole ): int|float => $whole ? 0 : 0.0, self::TOTAL_SUMS );
	}

	/**
	 * One dimension's values over the window, the site's or one server's.
	 *
	 * @param string $dim       The dimension, e.g. `status`.
	 * @param string $server    Reporting server; '' is the site.
	 * @param int    $from      First second read.
	 * @param int    $to        The second the read stops short of.
	 * @param bool   $by_bucket Per bucket, by its start, oldest first.
	 * @return array<array-key,mixed> value => `DIM_*` columns, or bucket => that.
	 */
	public function dimension( string $dim, string $server, int $from, int $to, bool $by_bucket ): array {
		return $this->members( self::LEDGER_DIMS, [ self::dim_key( $dim, $server ) ], $from, $to, $by_bucket, self::DIM_SUMS );
	}

	/**
	 * The key a dimension's values file under.
	 *
	 * @param string $dim    The dimension.
	 * @param string $server Reporting server; '' is the site.
	 */
	public static function dim_key( string $dim, string $server ): string {
		return '' === $server ? $dim : "{$dim}:" . self::server_key( $server );
	}

	/**
	 * The category series over the window, the site's or one server's.
	 *
	 * @param string $server    Reporting server; '' is the site.
	 * @param int    $from      First second read.
	 * @param int    $to        The second the read stops short of.
	 * @param bool   $by_bucket Per bucket, by its start, oldest first.
	 * @return array<array-key,mixed> category => `CAT_*` columns, or bucket => that.
	 */
	public function categories( string $server, int $from, int $to, bool $by_bucket ): array {
		return $this->members( self::LEDGER_CATEGORIES, [ self::server_scope( $server ) ], $from, $to, $by_bucket, self::CAT_SUMS );
	}

	/**
	 * The category leaderboard over the window, the site's or one
	 * server's, in its display shape (`sums_to_display()`).
	 *
	 * @param string $server Reporting server; '' is the site.
	 * @param int    $from   First second read.
	 * @param int    $to     The second the read stops short of.
	 * @return array<string,mixed>
	 */
	public function leaderboard( string $server, int $from, int $to ): array {
		$count      = 0;
		$time       = 0.0;
		$categories = [];
		foreach ( $this->sum( self::LEDGER_LEADERBOARD, [ self::server_scope( $server ) ], null, false, $from, $to ) as $row ) {
			[ , $member, , $samples, $sum_time, $sum_count ] = $row;
			$parts = \explode( self::MEMBER_SEPARATOR, Core::as_string( $member ), 2 );
			if ( '' === $parts[0] ) {
				$count += Core::num_int( $samples );
				$time  += Core::num_float( $sum_time );
				continue;
			}
			$categories[ $parts[0] ] ??= [ 'samples' => 0, 'sum_time' => 0.0, 'sum_count' => 0.0, 'entries' => [] ];
			if ( isset( $parts[1] ) ) {
				$categories[ $parts[0] ]['entries'][ $parts[1] ] = [ Core::num_float( $sum_time ), Core::num_float( $sum_count ), Core::num_int( $samples ) ];
				continue;
			}
			$categories[ $parts[0] ] = [
				'samples'   => Core::num_int( $samples ),
				'sum_time'  => Core::num_float( $sum_time ),
				'sum_count' => Core::num_float( $sum_count ),
			] + $categories[ $parts[0] ];
		}
		return self::sums_to_display( $count, $time, $categories );
	}

	/**
	 * The key of a scope the categories and the leaderboard file under.
	 *
	 * @param string $server Reporting server; '' is the site.
	 */
	public static function server_scope( string $server ): string {
		return '' === $server ? self::SITE : 'srv:' . self::server_key( $server );
	}

	/**
	 * One page of a scope's URLs over the window, ranked in the Ledger by
	 * `$order_by` and then the URL: the URLs of `$servers`' reader traffic,
	 * and their worker traffic with `$workers`, a URL served both ways one
	 * row. With `$errors` a URL counts only in the key and bucket in which
	 * it errored, and a URL that never errored is no row.
	 *
	 * @param list<string>              $servers  Server names.
	 * @param bool                      $workers  Include the worker traffic.
	 * @param string|array{0:string,1:string} $order_by A `stats:url-rows` column, `x` for the URL, or `[ numerator, denominator ]` of two sum columns.
	 * @param string                    $order    `asc` or `desc`.
	 * @param int                       $limit    Rows, at most `Ledger_Node::TOP_LIMIT_MAX`.
	 * @param int                       $offset   Rows ranked ahead of the page.
	 * @param bool                      $errors   Only the key and bucket in which each URL errored.
	 * @param int                       $from     First second read.
	 * @param int                       $to       The second the read stops short of.
	 * @return array{total: int, rows: list<array<string,mixed>>} The URLs the scope ranks, and the page.
	 */
	public function url_page( array $servers, bool $workers, string|array $order_by, string $order, int $limit, int $offset, bool $errors, int $from, int $to ): array {
		$ks   = self::url_scope( $servers, $workers );
		$node = $this->ledgers[ self::LEDGER_URL_ROWS ] ?? null;
		if ( [] === $ks || null === $node ) {
			return [ 'total' => 0, 'rows' => [] ];
		}
		$query = [
			'from'     => $from,
			'to'       => $to,
			'ks'       => $ks,
			'order_by' => $order_by,
			'order'    => $order,
			'limit'    => $limit,
			'offset'   => $offset,
		] + ( $errors ? [ 'positive' => self::ROW_FIELD_NAMES[ self::ROW_ERRORS ], 'positive_each_t' => true ] : [] );
		$page = $this->answered( $this->client->top( $node, $query ) );
		$rows = [];
		foreach ( Core::arr( $page['rows'] ?? null ) as $row ) {
			$row    = Core::arr( $row );
			$rows[] = self::url_row( Core::as_string( $row[0] ?? '' ), \array_values( \array_slice( $row, 1 ) ) );
		}
		return [ 'total' => Core::num_int( $page['total'] ?? null ), 'rows' => $rows ];
	}

	/**
	 * A scope's URL rows summed whole over the window, totalled in the
	 * Ledger key by key: `count`, `timed_count`, `sum_ms`, `sum_peak_mb`,
	 * the status counts and `errors`, every URL of the scope together.
	 *
	 * With `$errors` the Ledger keeps each URL's row only in the key and
	 * bucket in which it errored before it totals the key.
	 *
	 * @param list<string> $servers Server names.
	 * @param bool         $workers Include the worker traffic.
	 * @param bool         $errors  Only the key and bucket in which each URL errored.
	 * @param int          $from    First second read.
	 * @param int          $to      The second the read stops short of.
	 * @return array<string,mixed> The summed row, under `ROW_FIELD_NAMES`.
	 */
	public function scope_totals( array $servers, bool $workers, bool $errors, int $from, int $to ): array {
		$sum = null;
		foreach ( $this->sum( self::LEDGER_URL_ROWS, self::url_scope( $servers, $workers ), null, false, $from, $to, 'k', $errors ) as $row ) {
			$columns = \array_slice( $row, 3 );
			$sum     = null === $sum ? $columns : self::add_row( $sum, $columns );
		}
		return self::url_row( '', $sum ?? [] );
	}

	/**
	 * The named URLs' rows over the window, each summed across every server
	 * and partition that filed it, or every URL of the scope.
	 *
	 * With `$errors` the Ledger keeps a URL's row only in the key and bucket
	 * in which it errored, as `url_page()` keeps it.
	 *
	 * @param list<string>      $servers Server names.
	 * @param bool              $workers Include the worker traffic.
	 * @param list<string>|null $urls    The URLs; null reads every URL of the scope.
	 * @param bool              $errors  Only the key and bucket in which each URL errored.
	 * @param int               $from    First second read.
	 * @param int               $to      The second the read stops short of.
	 * @return array<string,array<string,mixed>> URL => row, in URL order.
	 */
	public function url_rows( array $servers, bool $workers, ?array $urls, bool $errors, int $from, int $to ): array {
		$merged = [];
		foreach ( $this->sum( self::LEDGER_URL_ROWS, self::url_scope( $servers, $workers ), $urls, false, $from, $to, 'x', $errors ) as $row ) {
			$url            = Core::as_string( $row[1] );
			$columns        = \array_slice( $row, 3 );
			$merged[ $url ] = isset( $merged[ $url ] ) ? self::add_row( $merged[ $url ], $columns ) : $columns;
		}
		\ksort( $merged, \SORT_STRING );
		$out = [];
		foreach ( $merged as $url => $columns ) {
			$out[ Core::as_string( $url ) ] = self::url_row( Core::as_string( $url ), $columns );
		}
		return $out;
	}

	/**
	 * A URL row's columns, named and typed: `min_ms` and `max_ms` null where
	 * no timed request reached it.
	 *
	 * @param string      $url     The URL.
	 * @param list<mixed> $columns `ROW_*` columns.
	 * @return array<string,mixed>
	 */
	private static function url_row( string $url, array $columns ): array {
		$row = [ 'url' => $url ];
		foreach ( self::ROW_FIELD_NAMES as $i => $name ) {
			$value = $columns[ $i ] ?? null;
			if ( isset( self::ROW_EXTREMES[ $i ] ) && null === $value ) {
				$row[ $name ] = null;
				continue;
			}
			$row[ $name ] = ( self::ROW_SUMS[ $i ] ?? false ) || self::ROW_LAST_SEEN === $i ? Core::num_int( $value ) : Core::num_float( $value );
		}
		return $row;
	}

	/**
	 * Two sums of one URL's row columns, as one: sums add, the extremes and
	 * the last completion take theirs.
	 *
	 * @param list<mixed> $into    Columns so far.
	 * @param list<mixed> $columns Another key's columns.
	 * @return list<mixed>
	 */
	private static function add_row( array $into, array $columns ): array {
		foreach ( \array_keys( self::ROW_SUMS ) as $i ) {
			$into[ $i ] = Core::num_float( $into[ $i ] ) + Core::num_float( $columns[ $i ] );
		}
		foreach ( self::ROW_EXTREMES as $i => $aggregate ) {
			$measured   = \array_map( Core::num_float( ... ), \array_filter( [ $into[ $i ] ?? null, $columns[ $i ] ?? null ], \is_numeric( ... ) ) );
			$into[ $i ] = [] === $measured ? null : ( 'min' === $aggregate ? \min( $measured ) : \max( $measured ) );
		}
		return $into;
	}

	/**
	 * The url-rows keys of a scope: each server's reader key, and its worker
	 * key beside it with `$workers`.
	 *
	 * @param list<string> $servers Server names.
	 * @param bool         $workers Include the worker keys.
	 * @return list<string>
	 */
	private static function url_scope( array $servers, bool $workers ): array {
		$ks = [];
		foreach ( $servers as $server ) {
			$ks[] = self::url_rows_key( $server, false );
			if ( $workers ) {
				$ks[] = self::url_rows_key( $server, true );
			}
		}
		return $ks;
	}

	/**
	 * The key one server's URL rows file under, a family apiece: reader
	 * traffic under `r:`, worker traffic under `w:`.
	 *
	 * @param string $server The server.
	 * @param bool   $worker The worker family.
	 */
	public static function url_rows_key( string $server, bool $worker ): string {
		return ( $worker ? 'w:' : 'r:' ) . self::server_key( $server );
	}

	/**
	 * Hash a server name to a key-safe ASCII token (FNV-1a 32-bit hex), so
	 * a server name cannot put whitespace or bytes of its own into a key.
	 *
	 * @param string $server Server name; '' hashes to ''.
	 * @return string Eight hex digits, or ''.
	 */
	public static function server_key( string $server ): string {
		return '' === $server ? '' : \hash( 'fnv1a32', $server );
	}

	/**
	 * One URL's values of one dimension, per bucket.
	 *
	 * @param string $url  The URL.
	 * @param string $dim  The dimension.
	 * @param int    $from First second read.
	 * @param int    $to   The second the read stops short of.
	 * @return array<array-key,array<array-key,mixed>> bucket => value => `DIM_*` columns.
	 */
	public function url_breakdown( string $url, string $dim, int $from, int $to ): array {
		return $this->members( self::LEDGER_URL_DIMS, [ self::url_dim_key( $dim, $url ) ], $from, $to, true, self::DIM_SUMS );
	}

	/**
	 * The key one dimension of one URL files under, so a breakdown reads
	 * one key: the dimension, a colon no dimension name holds, the URL.
	 *
	 * @param string $dim The dimension.
	 * @param string $url The URL.
	 */
	public static function url_dim_key( string $dim, string $url ): string {
		return "{$dim}:" . self::url_key( $url );
	}

	/**
	 * One URL's category series, per bucket.
	 *
	 * @param string $url  The URL.
	 * @param int    $from First second read.
	 * @param int    $to   The second the read stops short of.
	 * @return array<array-key,array<array-key,mixed>> bucket => category => `CAT_*` columns.
	 */
	public function url_categories( string $url, int $from, int $to ): array {
		return $this->members( self::LEDGER_URL_CATS, [ self::url_key( $url ) ], $from, $to, true, self::CAT_SUMS );
	}

	/**
	 * A URL as a Ledger key: whitespace percent-encoded, since a key holds
	 * none, and nothing else changed.
	 *
	 * @param string $url The URL.
	 */
	public static function url_key( string $url ): string {
		return \preg_replace_callback( '/\s/', static fn ( array $m ): string => \rawurlencode( $m[0] ), $url ) ?? $url;
	}

	/**
	 * The members of `$ks` over the window, each member's columns typed by
	 * `$fields` and summed across the keys: member => columns, or bucket =>
	 * that, oldest bucket first.
	 *
	 * @param string                $ledger    The Ledger.
	 * @param list<string>          $ks        Keys.
	 * @param int                   $from      First second read.
	 * @param int                   $to        The second the read stops short of.
	 * @param bool                  $by_bucket Per bucket.
	 * @param array<array-key,bool> $fields    Column => whether it is a whole count, in column order.
	 * @return array<array-key,array<array-key,mixed>>
	 */
	private function members( string $ledger, array $ks, int $from, int $to, bool $by_bucket, array $fields ): array {
		$out = [];
		foreach ( $this->sum( $ledger, $ks, null, $by_bucket, $from, $to ) as $row ) {
			$member  = Core::as_string( $row[1] );
			$columns = \array_combine( \array_keys( $fields ), \array_slice( $row, 3 ) );
			if ( $by_bucket ) {
				$out[ Core::num_int( $row[2] ) ][ $member ] = self::sum_entry( Core::arr( $out[ Core::num_int( $row[2] ) ][ $member ] ?? null ), $columns, $fields );
			} else {
				$out[ $member ] = self::sum_entry( Core::arr( $out[ $member ] ?? null ), $columns, $fields );
			}
		}
		if ( $by_bucket ) {
			\ksort( $out );
		}
		return $out;
	}

	/**
	 * `SUM` over the window, chunked by the Ledger: every `( k, x )` group,
	 * or each `k` whole with `$group` k, split by `t` with `$by_t`, as
	 * `[ k, x|null, t|null, columns… ]` rows.
	 *
	 * With `$errors` the query carries `positive: errors` and
	 * `positive_each_t`, so the Ledger drops every stored `( t, k, x )` row
	 * whose `errors` is not positive before it groups: a member counts only
	 * in the key and bucket in which it errored.
	 *
	 * @param string            $ledger The Ledger.
	 * @param list<string>      $ks     Keys.
	 * @param list<string>|null $xs     Members; null reads every member.
	 * @param bool              $by_t   Group by bucket too.
	 * @param int               $from   First second read.
	 * @param int               $to     The second the read stops short of.
	 * @param string            $group  `x` sums each member; `k` totals each key across its members.
	 * @param bool              $errors Only the rows whose `errors` is positive.
	 * @return list<list<mixed>>
	 */
	private function sum( string $ledger, array $ks, ?array $xs, bool $by_t, int $from, int $to, string $group = 'x', bool $errors = false ): array {
		$node = $this->ledgers[ $ledger ] ?? null;
		if ( [] === $ks || [] === $xs || null === $node ) {
			return [];
		}
		$query = [
			'from' => $from,
			'to'   => $to,
			'ks'   => $ks,
			'by_t' => $by_t,
		] + ( null === $xs ? [] : [ 'xs' => $xs ] ) + ( 'k' === $group ? [ 'group' => 'k' ] : [] ) + ( $errors ? [ 'positive' => self::ROW_FIELD_NAMES[ self::ROW_ERRORS ], 'positive_each_t' => true ] : [] );
		$rows = [];
		foreach ( $this->answered( $this->client->sum( $node, $query ) ) as $row ) {
			if ( \is_array( $row ) ) {
				$rows[] = \array_values( $row );
			}
		}
		return $rows;
	}

	/**
	 * The URL a hash names, filed in the window, or null when none was.
	 *
	 * @param string $url_hash `Log_Manager::url_hash()` of the URL.
	 * @param int    $from     First second read.
	 * @param int    $to       The second the read stops short of.
	 */
	public function url_of( string $url_hash, int $from, int $to ): ?string {
		$urls = $this->members_of( self::hash_key( $url_hash ), $from, $to );
		return [] === $urls ? null : Core::as_string( \reset( $urls ) );
	}

	/**
	 * The names key a URL's hash files under.
	 *
	 * @param string $url_hash `Log_Manager::url_hash()` of the URL.
	 */
	public static function hash_key( string $url_hash ): string {
		return "url:{$url_hash}";
	}

	/**
	 * Every server that filed reader rows in the window, and worker rows
	 * too with `$workers`, in name order.
	 *
	 * @param bool $workers Include the servers of worker traffic.
	 * @param int  $from    First second read.
	 * @param int  $to      The second the read stops short of.
	 * @return list<string>
	 */
	public function servers( bool $workers, int $from, int $to ): array {
		$names = [];
		foreach ( $workers ? [ false, true ] : [ false ] as $worker ) {
			foreach ( $this->members_of( self::servers_key( $worker ), $from, $to ) as $name ) {
				$names[ Core::as_string( $name ) ] = true;
			}
		}
		\ksort( $names, \SORT_STRING );
		return \array_map( 'strval', \array_keys( $names ) );
	}

	/**
	 * The names key the servers of one family file under.
	 *
	 * @param bool $worker The worker family.
	 */
	public static function servers_key( bool $worker ): string {
		return $worker ? 'servers:w' : 'servers:r';
	}

	/**
	 * The names Ledger's members of one key over the window. A name is filed
	 * at its hour's start, so the read opens on the hour `$from` falls in.
	 *
	 * @param string $k    The key.
	 * @param int    $from First second read.
	 * @param int    $to   The second the read stops short of.
	 * @return array<array-key,mixed>
	 */
	private function members_of( string $k, int $from, int $to ): array {
		$node = $this->ledgers[ self::LEDGER_NAMES ] ?? null;
		return null === $node ? [] : $this->answered( $this->client->ledger_members( $node, $from - $from % self::HOUR_SECONDS, $to, $k ) );
	}

	/**
	 * A reply's data, or [] where the Ledger refused or never answered,
	 * which the store remembers.
	 *
	 * @param array<array-key,mixed>|null $data A Ledger reply's data.
	 * @return array<array-key,mixed>
	 */
	private function answered( ?array $data ): array {
		if ( null === $data ) {
			$this->unanswered = true;
			return [];
		}
		return $data;
	}

	/**
	 * One URL's aggregate across every partition's blob: each partition
	 * writes the share of the URL's traffic it saw, so the sums add
	 * (decision 2) — the running flames through `merge_url_flames()`, the
	 * profiles as a leaderboard merges — and the reader divides them once.
	 * `last_modified` is the newest partition's settle.
	 *
	 * @param string $url_hash 12-char URL hash.
	 * @return array{last_modified:int, flame?:array<array-key,mixed>, profiles?:array<string,mixed>}|null Null when no partition holds one.
	 */
	public function url_stats( string $url_hash ): ?array {
		$found         = false;
		$last_modified = 0;
		$flame         = null;
		$profiles      = null;
		foreach ( $this->url_tables as $table ) {
			$blob = $this->client->get_multi( $table, [ $url_hash ] )[ $url_hash ] ?? null;
			if ( ! \is_array( $blob ) ) {
				continue;
			}
			$found         = true;
			$last_modified = \max( $last_modified, Core::num_int( $blob['last_modified'] ?? null ) );
			if ( \is_array( $blob['flame_raw'] ?? null ) ) {
				$flame = null === $flame ? $blob['flame_raw'] : Flame_Builder_Node::merge_url_flames( $flame, $blob['flame_raw'] );
			}
			if ( \is_array( $blob['profiles'] ?? null ) ) {
				$sums = self::string_keys( $blob['profiles'] );
				if ( null === $profiles ) {
					$profiles = $sums;
				} else {
					self::merge_leaderboard_bucket( $profiles, $sums );
				}
			}
		}
		if ( ! $found ) {
			return null;
		}
		$stats = [ 'last_modified' => $last_modified ];
		if ( null !== $flame ) {
			$stats['flame'] = Flame_Builder_Node::url_flame_for_display( $flame )[1];
		}
		if ( null !== $profiles ) {
			$stats['profiles'] = self::sums_to_display(
				Core::num_int( $profiles['count'] ?? null ),
				Core::num_float( $profiles['sum_req_time'] ?? null ),
				self::string_keys( Core::arr( $profiles['categories'] ?? null ) )
			);
		}
		return $stats;
	}

	/**
	 * Convert summed leaderboard data to the display shape the frontend reads.
	 *
	 *  - 'time'    = sum_time  / total_count — avg exclusive cat time per request.
	 *  - 'count'   = sum_count / total_count — avg invocation count per request.
	 *  - entries   are per-appearance averages (sum / samples).
	 *
	 * An entry whose sample count is zero is dropped rather than divided. Past a
	 * hundred entries a category keeps only its fifty slowest, ranked by average
	 * exclusive time, so one pathological category cannot flood a payload.
	 *
	 * @param int                 $total_count  Total profiled requests.
	 * @param float               $sum_req_time Sum of per-request $req_time values.
	 * @param array<string,mixed> $sums         Per-category sums keyed by category name.
	 * @return array<string,mixed> Display-shaped leaderboard data.
	 */
	public static function sums_to_display( int $total_count, float $sum_req_time, array $sums ): array {
		$display_cats = [];
		foreach ( $sums as $cat => $data ) {
			$data      = Core::arr( $data );
			$samples   = Core::num_int( $data['samples'] ?? null );
			$sum_time  = Core::num_float( $data['sum_time'] ?? null );
			$sum_count = Core::num_float( $data['sum_count'] ?? null );

			$entries_out = [];
			$entries     = ( isset( $data['entries'] ) && \is_array( $data['entries'] ) ) ? $data['entries'] : [];
			foreach ( $entries as $name => $entry ) {
				$entry     = Core::arr( $entry );
				$e_samples = Core::num_int( $entry[2] ?? null );
				if ( $e_samples > 0 ) {
					$entries_out[ $name ] = [
						Core::num_float( $entry[0] ?? null ) / $e_samples,
						Core::num_float( $entry[1] ?? null ) / $e_samples,
						$e_samples,
					];
				}
			}

			if ( \count( $entries_out ) > 100 ) {
				\uasort( $entries_out, fn( $a, $b ) => $b[0] <=> $a[0] );
				$entries_out = \array_slice( $entries_out, 0, 50, true );
			}

			$display_cats[ $cat ] = [
				'time'    => $total_count > 0 ? $sum_time / $total_count : 0.0,
				'count'   => $total_count > 0 ? $sum_count / $total_count : 0.0,
				'samples' => $samples,
				'entries' => $entries_out,
			];
		}

		return [
			'count'      => $total_count,
			'total_time' => $total_count > 0 ? $sum_req_time / $total_count : 0.0,
			'categories' => $display_cats,
		];
	}

	/**
	 * Merge one profile's sums into another, in place, as `url_stats()`
	 * merges two partitions' blobs. Three shapes nest here and each has its
	 * own field table: the profile (`LB_SUMS`), a category inside it
	 * (`LB_CAT_SUMS`) and one of that category's entries (`LB_ENTRY_SUMS`).
	 *
	 * @param array<string,mixed> $dst The profile so far; rewritten in place.
	 * @param array<string,mixed> $src The profile being merged in.
	 */
	public static function merge_leaderboard_bucket( array &$dst, array $src ): void {
		// Read BEFORE the sum: `sum_entry()` keeps only what LB_SUMS names.
		$cats = Core::arr( $dst['categories'] ?? null );
		$dst  = self::string_keys( self::sum_entry( $dst, $src, self::LB_SUMS ) );
		foreach ( Core::arr( $src['categories'] ?? null ) as $cat => $data ) {
			$data    = Core::arr( $data );
			$current = Core::arr( $cats[ $cat ] ?? null );
			$entries = Core::arr( $current['entries'] ?? null );
			foreach ( Core::arr( $data['entries'] ?? null ) as $name => $entry ) {
				$entries[ $name ] = self::sum_entry(
					Core::arr( $entries[ $name ] ?? null ),
					Core::arr( $entry ),
					self::LB_ENTRY_SUMS
				);
			}
			$cats[ $cat ]            = self::sum_entry( $current, $data, self::LB_CAT_SUMS );
			$cats[ $cat ]['entries'] = $entries;
		}
		$dst['categories'] = $cats;
	}

	/**
	 * A bucket as a chart names it: `Y-m-d-H-i` UTC of its start.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	public static function bucket_key( int $timestamp ): string {
		return \gmdate( 'Y-m-d-H-i', self::bucket_start( $timestamp ) );
	}

	/**
	 * The start of the bucket a timestamp falls in: the `t` it is filed at.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	public static function bucket_start( int $timestamp ): int {
		return $timestamp - $timestamp % self::BUCKET_SECONDS;
	}

	/**
	 * Whether a path answers a term: every word of the term is a word of it,
	 * or the whole term appears when the term has no word at all.
	 *
	 * @param string       $name   The URL's path.
	 * @param string       $term   The lowercased search term.
	 * @param list<string> $tokens The term's words, as `term_tokens()` spells them.
	 */
	public static function term_matches( string $name, string $term, array $tokens ): bool {
		$name = \strtolower( $name );
		if ( [] === $tokens ) {
			return \str_contains( $name, $term );
		}
		return [] === \array_diff( $tokens, self::term_tokens( $name ) );
	}

	/**
	 * A search term's words, or a path's: distinct lowercase alphanumeric
	 * runs of `TERM_WORD_MIN` characters or more, cut to `TERM_WORD_MAX`,
	 * in source order.
	 *
	 * @param string $text Search term or path.
	 * @return list<string>
	 */
	public static function term_tokens( string $text ): array {
		$out = [];
		foreach ( \preg_split( '/' . self::TOKEN_SEP . '+/', \strtolower( $text ) ) ?: [] as $token ) {
			if ( \strlen( $token ) >= self::TERM_WORD_MIN ) {
				$out[ \substr( $token, 0, self::TERM_WORD_MAX ) ] = true;
			}
		}
		// An all-digit token is an INT key; every reader promises a string.
		return \array_map( 'strval', \array_keys( $out ) );
	}

	/**
	 * Sum `$fields` from `$incoming` into `$into`, entry by entry, reading a
	 * field key rather than a name, so a positional table works as a named
	 * one does. Only `$fields` survive.
	 *
	 * @param array<array-key,mixed> $into     Running totals.
	 * @param array<array-key,mixed> $incoming Inbound entries.
	 * @param array<array-key,bool>  $fields   Field key => is a whole count.
	 * @return array<string,mixed> The totals, string-keyed.
	 */
	public static function sum_fields( array $into, array $incoming, array $fields ): array {
		$out = self::string_keys( $into );
		foreach ( $incoming as $key => $stats ) {
			if ( \is_array( $stats ) ) {
				$out[ (string) $key ] = self::sum_entry( Core::arr( $out[ (string) $key ] ?? null ), $stats, $fields );
			}
		}
		return $out;
	}

	/**
	 * Sum `$fields` from one entry into another. The entry it returns is
	 * built from `$fields` and nothing else, so a key either side carries
	 * outside the table is DISCARDED.
	 *
	 * @param array<array-key,mixed> $into   The entry so far.
	 * @param array<array-key,mixed> $from   The entry being added.
	 * @param array<array-key,bool>  $fields Field => whether it is a whole count.
	 * @return array<array-key,mixed> The `$fields` keys, summed.
	 */
	public static function sum_entry( array $into, array $from, array $fields ): array {
		$out = [];
		foreach ( $fields as $field => $is_count ) {
			$out[ $field ] = $is_count
				? Core::num_int( $into[ $field ] ?? null ) + Core::num_int( $from[ $field ] ?? null )
				: Core::num_float( $into[ $field ] ?? null ) + Core::num_float( $from[ $field ] ?? null );
		}
		return $out;
	}

	/**
	 * Re-key a decoded map with string keys: PHP casts numeric-looking keys
	 * to int on decode, and the merge helpers want the string back.
	 *
	 * @param array<array-key,mixed> $map Decoded value.
	 * @return array<string,mixed>
	 */
	public static function string_keys( array $map ): array {
		$out = [];
		foreach ( $map as $key => $value ) {
			$out[ (string) $key ] = $value;
		}
		return $out;
	}

	/**
	 * A row's `min_ms` or `max_ms` as every surface carries it: the measured
	 * milliseconds, or null where no timed request reached the row, which a
	 * reader shows as unmeasured and never as 0.
	 *
	 * @param mixed $value The extreme a row or a brief holds.
	 */
	public static function extreme( mixed $value ): ?float {
		return null === $value ? null : Core::num_float( $value );
	}

	/** Whether a read this store made went unanswered, so what it read is short. */
	public function unanswered(): bool {
		return $this->unanswered;
	}

	/**
	 * One URL's blob as its writer merges onto it: the sums the settle
	 * wrote, where `url_stats()` answers display means. Read from the
	 * store's own partition's Table.
	 *
	 * @param string    $url_hash 12-char URL hash.
	 * @param-out bool  $failed
	 * @param ?bool     $failed   Set true when the Table did not answer: then the
	 *                            null is no absence, and nothing may replace it.
	 * @return array<array-key,mixed>|null The blob, or null on a miss.
	 */
	public function url_aggregate( string $url_hash, ?bool &$failed = null ): ?array {
		$value = $this->client->get_multi( $this->url_tables[0] ?? throw new \LogicException( 'Stats_Store was given no url Table' ), [ $url_hash ], $failed )[ $url_hash ] ?? null;
		return \is_array( $value ) ? $value : null;
	}

	/**
	 * Write URL blobs to the store's own partition's Table, one `MSET`.
	 *
	 * @param array<array-key,array<array-key,mixed>> $blobs hash => blob.
	 * @return list<string> The hashes that landed.
	 */
	public function set_url_aggregates( array $blobs ): array {
		if ( [] === $blobs ) {
			return [];
		}
		$items = [];
		foreach ( $blobs as $url_hash => $blob ) {
			$items[ (string) $url_hash ] = [ $blob ];
		}
		return $this->client->set_multi( $this->url_tables[0] ?? throw new \LogicException( 'Stats_Store was given no url Table' ), $items );
	}

	/**
	 * Segments a Ledger keeps for a retention window: the window in hours,
	 * rounded up, and never fewer than `MIN_SEGMENTS`.
	 *
	 * @param int $retention_seconds The retention window.
	 */
	public static function ledger_segments( int $retention_seconds ): int {
		return \max( self::MIN_SEGMENTS, (int) \ceil( $retention_seconds / self::SEGMENT_SECONDS ) );
	}

	/**
	 * One member of two parts, a dimension and its value or a category and
	 * its entry. A reader splits at the first separator, so the second part
	 * may hold one.
	 *
	 * @param string $first  The first part, which holds no separator.
	 * @param string $second The second.
	 */
	public static function member( string $first, string $second ): string {
		return $first . self::MEMBER_SEPARATOR . $second;
	}

	/**
	 * The PATH of a URL: what a search matches, with no scheme or host.
	 *
	 * The server is the picker's question, so a term matching the host would
	 * make one box ask the dropdown's. A URL carrying no scheme is all path,
	 * which is what a producer with no `SERVER_NAME` writes. The authority
	 * ends at whichever delimiter comes first, so an authority with no path
	 * keeps its query on the path.
	 *
	 * @param string $url A URL.
	 */
	public static function path_of( string $url ): string {
		$at = \strpos( $url, '://' );
		if ( false === $at ) {
			return $url;
		}
		$host = $at + 3;
		return \substr( $url, $host + \strcspn( $url, '/?#', $host ) );
	}

	/**
	 * What one stored part costs before its strings, under the serializer a
	 * blob stores with — the one place a cap learns it.
	 *
	 * @param string $part An `OVERHEADS` part: `lb_category`, `lb_entry`,
	 *                     `flame_node` or `hook`.
	 * @throws \LogicException When no estimate names the part.
	 */
	public static function overhead( string $part ): int {
		return self::OVERHEADS[ Durable_Arm::serializer() ][ $part ] ?? throw new \LogicException( "no size estimate for a stored {$part}" );
	}
}
