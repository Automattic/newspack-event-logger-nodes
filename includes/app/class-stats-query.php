<?php
/**
 * Stats_Query: the fixed vocabulary every performance reader asks the
 * Flame_Facts rows in.
 *
 * A spec names a source, the fields to group and filter by, the measures to
 * answer and the buckets to read. `table_for()` picks the cheapest table that
 * answers it exactly, and `run()` builds one statement over every
 * partition's file: a `UNION ALL` of one arm per attached alias, each joining
 * its own `urls`, `agents` and name lookups before the union, since their
 * ids are partition-local. When every measure the spec names composes, each arm
 * groups its own rows and the union merges those partials; a percentile or a
 * distinct count reads every arm's rows whole. Field and measure names reach
 * the SQL only through this class's tables; every value binds.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Stats_Builder_Node;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Table_Unavailable;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An absent status, country, agent, JA4 or From answers `Unknown`, as does a
 * status outside the builder's status bounds; a request with no error answers
 * `-`, the record's own spelling. A filter matches what a group answers,
 * `Unknown` included, and a filter keyed `<field>!` keeps the rows matching
 * none of its values, ANDed with the field's own. Worker requests count
 * unless a filter leaves them out. A sort ranks a null last in either order
 * and breaks its ties by the groups in its own direction; a `url` group
 * also sorts by its `url_name`, server then path, and a `page` group by its
 * `page_name`. Under `top`, the tuples of the group's non-time fields rank
 * by the sum over their time rows of `top_by`, a measure that adds across
 * buckets, highest first and ties by their values, and every time row of the
 * top ones answers, in rank order, with `groups` the tuples the selection
 * holds. Under `also`, a second page of the same groups in
 * an order of its own answers beside the first, from the one statement.
 *
 * @phpstan-type Also array{sort:string, order:string, limit:int}
 * @phpstan-type Measured array{measures:array<string,array{0:string,1:list<string>}>, folds:array<string,array{0:string,1:list<string>}>, ids?:array<string,array{0:string,1:string}>}
 * @phpstan-type Spec array{source:string, group:list<string>, filter:array<string,list<scalar|null>>, measure:list<string>, buckets:list<array{0:int,1:int}>, sort:string, order:string, limit:int, offset:int, search:string, match:string, top:?int, top_by:string, also:?Also, now:?int}
 */
final class Stats_Query {

	/** Group/filter fields per source; each maps to a SQL expression over the source's columns. */
	public const FIELDS = [
		'requests' => [ 'bucket', 'hour', 'day', 'server', 'url', 'page', 'status', 'status_class', 'method', 'country', 'ua', 'ja4', 'from', 'worker', 'profiled', 'error' ],
		'timings'  => [ 'bucket', 'hour', 'server', 'url', 'page', 'worker', 'category', 'entry', 'listener' ],
		'owners'   => [ 'bucket', 'hour', 'server', 'url', 'page', 'worker', 'owner' ],
	];

	/**
	 * What each source measures over the rows a spec selects. `errors` counts
	 * the requests ending in a timeout or fatal (`ERROR_KINDS`), and `error_rate`
	 * is `errors` over `count`, null where nothing was counted.
	 */
	public const MEASURES = [
		'requests' => [ 'count', 'timed', 'sum_ms', 'avg_ms', 'max_ms', 'min_ms', 'p50_ms', 'p95_ms', 'p99_ms', 'sum_peak_mb', 'avg_peak_mb', 'max_peak_mb', 'errors', 'error_rate', 'urls', 'pages', 'last_seen', 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx', 'worker' ],
		'timings'  => [ 'ms', 'calls', 'requests', 'avg_ms', 'avg_calls' ],
		'owners'   => [ 'ms', 'calls', 'requests' ],
	];

	/** Fields a source filters by and never groups: a key, not a dimension. */
	private const KEYS = [
		'requests' => [ 'rid' ],
		'timings'  => [],
		'owners'   => [],
	];

	/** Most rows one answer holds. */
	public const MAX_LIMIT = 1000;

	/** The columns each row `newest_requests()` lists carries, in order. */
	public const LISTED = [ 'rid', 'url', 'started_at', 'finished_at', 'partition', 'segment', 'offset', 'length', 'status', 'method', 'error', 'ms', 'peak_mb' ];

	/** The farthest row a page may start at. */
	public const MAX_OFFSET = 10000;

	/** Most tuples a `top` answer ranks in: the series one chart draws. */
	public const MAX_TOP = 20;

	/** The fields that place a row in time; `top` ranks the other fields. */
	private const TIME_FIELDS = [ 'bucket', 'hour', 'day' ];

	/** The measures `top` ranks by: those whose buckets sum to the tuple's. */
	private const ADDITIVE = [
		'requests' => [ 'count', 'timed', 'sum_ms', 'sum_peak_mb', 'errors', 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx' ],
		'timings'  => [ 'ms', 'calls', 'requests' ],
		'owners'   => [ 'ms', 'calls', 'requests' ],
	];

	/** Fields holding integers, so a filter's digits bind as one. */
	private const INTEGER_FIELDS = [ 'bucket', 'hour', 'day', 'status', 'status_class', 'worker', 'profiled', 'listener' ];

	/** Buckets an hour holds. */
	private const BUCKETS_PER_HOUR = Bucket::HOUR_SECONDS / Bucket::SECONDS;

	/** Buckets a UTC day holds. */
	private const BUCKETS_PER_DAY = 24 * self::BUCKETS_PER_HOUR;

	/** What an absent value answers. */
	private const UNKNOWN = 'Unknown';

	/** A status code the builder reads a status class from, as SQL. */
	private const KNOWN_STATUS = 't.status BETWEEN ' . Stats_Builder_Node::STATUS_MIN . ' AND ' . Stats_Builder_Node::STATUS_MAX;

	/**
	 * A measure's expression: in its `measures` tuple, its aggregate over the
	 * arm inputs; in its `folds` tuple, its fold over the per-partition
	 * partials `q_<name>`.
	 */
	private const EXPRESSION = 0;

	/** What that expression reads: arm inputs, or the measures folded. */
	private const READS = 1;

	/** The measures reading each group's timed requests ranked by `ms`. */
	private const RANKED = [ 'p50_ms', 'p95_ms', 'p99_ms' ];

	/**
	 * The fields a partition sums by id when its measures compose, each its
	 * id column and the table naming it: the sum then names each group once
	 * rather than joining a name onto every row. A page sums by its URLs'
	 * ids, and the union merges the URLs of one page by its hash, except
	 * where a table declares its own `ids`, as `timings_page` does its page.
	 */
	private const BY_ID = [
		'url'      => [ 't.url_id', 'urls' ],
		'page'     => [ 't.url_id', 'urls' ],
		'category' => [ 't.category_id', 'categories' ],
		'entry'    => [ 't.entry_id', 'entries' ],
	];

	/** The join a URL field, input or search needs. */
	private const URL_JOIN = 'JOIN %1$s.urls AS u ON u.id = t.url_id';

	/** The join a page rollup's page needs: `pages` as `u`, columns as `urls` names them. */
	private const PAGE_JOIN = 'JOIN %1$s.pages AS u ON u.id = t.page_id';

	/** The join a URL's server needs, after `urls`. */
	private const URL_SERVER_JOIN = 'JOIN %1$s.servers AS us ON us.id = u.server_id';

	/**
	 * The fields naming a URL, each a hash a group answers a name beside:
	 * its `hash` over the URL `u`; the `name` column; its `parts`, each keyed
	 * by the column `into_statement()` stores it in and valued by its
	 * expression over `u`; and the `joins` those parts need after `urls`. A
	 * two-part name is `Url_Parts::join_url()` of its server and path.
	 */
	private const URL_FIELDS = [
		'url'  => [
			'hash'  => 'u.hash',
			'name'  => 'url_name',
			'parts' => [
				'server' => 'us.name',
				'path'   => 'u.path',
			],
			'joins' => [ self::URL_SERVER_JOIN ],
		],
		'page' => [
			'hash'  => 'u.page_hash',
			'name'  => 'page_name',
			'parts' => [ 'page_name' => 'u.page' ],
			'joins' => [],
		],
	];

	/**
	 * The join a row's server needs. This and `TIMING_JOINS` read the row's
	 * id through a unary `+`, which keeps SQLite off the index leading it:
	 * that index serves a lookup row's release, and a read driven through it
	 * walks every row the name has, past the window the question asks for.
	 */
	private const SERVER_JOIN = 'JOIN %1$s.servers AS sv ON sv.id = +t.server_id';

	/**
	 * A row's server, read plainly, for a table whose key or an index leads
	 * with `server_id`, so a server filter seeks that server's rows: inside
	 * the window where `bucket` follows it, as on `timings_site`, and the
	 * server's whole window on `category_window`. `arm()` reads `SERVER_JOIN`
	 * in its place under a filter naming a URL field, whose url-led index
	 * seeks fewer rows than the server's.
	 */
	private const SEEKING_SERVER_JOIN = 'JOIN %1$s.servers AS sv ON sv.id = t.server_id';

	/** The joins a timing row's category and entry need. */
	private const TIMING_JOINS = [
		'category' => 'JOIN %1$s.categories AS c ON c.id = +t.category_id',
		'entry'    => 'JOIN %1$s.entries AS e ON e.id = +t.entry_id',
	];

	/** The join an owner field needs. */
	private const OWNER_JOIN = 'JOIN %1$s.owners AS o ON o.id = +t.owner_id';

	/** A search: the arm's URL among those its path index matches. */
	private const SEARCH = 'u.id IN ( SELECT rowid FROM %1$s.urls_fts WHERE urls_fts MATCH ? )';

	/** The timings measures, over either timings or either owner table's arm inputs. */
	private const TIMING_MEASURES = [
		'ms'        => [ 'TOTAL( m_ms )', [ 'ms' ] ],
		'calls'     => [ 'COALESCE( SUM( m_calls ), 0 )', [ 'calls' ] ],
		'requests'  => [ 'COALESCE( SUM( m_requests ), 0 )', [ 'requests' ] ],
		'avg_ms'    => [ 'SUM( m_ms ) / NULLIF( SUM( m_requests ), 0 )', [ 'ms', 'requests' ] ],
		'avg_calls' => [ 'CAST( SUM( m_calls ) AS REAL ) / NULLIF( SUM( m_requests ), 0 )', [ 'calls', 'requests' ] ],
	];

	/** The timings measures' folds, for every timings and owner table. */
	private const TIMING_FOLDS = [
		'ms'        => [ 'TOTAL( q_ms )', [ 'ms' ] ],
		'calls'     => [ 'COALESCE( SUM( q_calls ), 0 )', [ 'calls' ] ],
		'requests'  => [ 'COALESCE( SUM( q_requests ), 0 )', [ 'requests' ] ],
		'avg_ms'    => [ 'SUM( q_ms ) / NULLIF( SUM( q_requests ), 0 )', [ 'ms', 'requests' ] ],
		'avg_calls' => [ 'CAST( SUM( q_calls ) AS REAL ) / NULLIF( SUM( q_requests ), 0 )', [ 'calls', 'requests' ] ],
	];

	/** The timing inputs either timings table's arm projects. */
	private const TIMING_INPUTS = [
		'ms'       => 't.ms',
		'calls'    => 't.calls',
		'requests' => 't.requests',
	];

	/**
	 * What `timings`, `timings_page` and `timings_site` share; each adds the
	 * fields and joins of its subject, the URL, the page or the worker.
	 */
	private const TIMING_TABLE = [
		'range'    => [ 't.bucket', 1 ],
		'fields'   => [
			'bucket'   => 't.bucket',
			'hour'     => 't.bucket / ' . self::BUCKETS_PER_HOUR,
			'server'   => 'sv.name',
			'category' => 'c.name',
			'entry'    => 'e.name',
			'listener' => 't.listener',
		],
		'inputs'   => self::TIMING_INPUTS,
		'measures' => self::TIMING_MEASURES,
		'folds'    => self::TIMING_FOLDS,
	];

	/**
	 * What a table of stored request sums answers from them, per hour on
	 * `requests_hourly` and per URL on `urls_window`; `max_ms` stands null
	 * where nothing was timed, as `requests` answers it.
	 */
	private const SUMMED = [
		'count'       => [ 'COALESCE( SUM( m_count ), 0 )', [ 'count' ] ],
		'timed'       => [ 'COALESCE( SUM( m_timed ), 0 )', [ 'timed' ] ],
		'sum_ms'      => [ 'TOTAL( m_sum_ms )', [ 'sum_ms' ] ],
		'avg_ms'      => [ 'SUM( m_sum_ms ) / NULLIF( SUM( m_timed ), 0 )', [ 'sum_ms', 'timed' ] ],
		'max_ms'      => [ 'CASE WHEN SUM( m_timed ) > 0 THEN MAX( m_max_ms ) END', [ 'max_ms', 'timed' ] ],
		'sum_peak_mb' => [ 'TOTAL( m_sum_peak_mb )', [ 'sum_peak_mb' ] ],
		'avg_peak_mb' => [ 'SUM( m_sum_peak_mb ) / NULLIF( SUM( m_count ), 0 )', [ 'sum_peak_mb', 'count' ] ],
		'errors'      => [ 'COALESCE( SUM( m_errors ), 0 )', [ 'errors' ] ],
	];

	/**
	 * The distinct URLs and pages a selection holds, counted by the URL's
	 * hash and the page's hash with its server: a partition files a URL
	 * under its own id, so no partial composes and every arm's rows read
	 * whole.
	 */
	private const DISTINCT = [
		'urls'  => [ 'COUNT( DISTINCT m_url )', [ 'url' ] ],
		'pages' => [ "COUNT( DISTINCT m_page || ' ' || m_server )", [ 'page', 'server' ] ],
	];

	/**
	 * Each request measure's fold, which depends on the measure alone, so
	 * one table serves `requests`, `requests_hourly` and `urls_window`; a
	 * measure it lacks composes from no partials.
	 */
	private const REQUEST_FOLDS = [
		'count'       => [ 'COALESCE( SUM( q_count ), 0 )', [ 'count' ] ],
		'timed'       => [ 'COALESCE( SUM( q_timed ), 0 )', [ 'timed' ] ],
		'sum_ms'      => [ 'TOTAL( q_sum_ms )', [ 'sum_ms' ] ],
		'avg_ms'      => [ 'SUM( q_sum_ms ) / NULLIF( SUM( q_timed ), 0 )', [ 'sum_ms', 'timed' ] ],
		'max_ms'      => [ 'MAX( q_max_ms )', [ 'max_ms' ] ],
		'min_ms'      => [ 'MIN( q_min_ms )', [ 'min_ms' ] ],
		'sum_peak_mb' => [ 'TOTAL( q_sum_peak_mb )', [ 'sum_peak_mb' ] ],
		'avg_peak_mb' => [ 'SUM( q_sum_peak_mb ) / NULLIF( SUM( q_count ), 0 )', [ 'sum_peak_mb', 'count' ] ],
		'max_peak_mb' => [ 'MAX( q_max_peak_mb )', [ 'max_peak_mb' ] ],
		'errors'      => [ 'COALESCE( SUM( q_errors ), 0 )', [ 'errors' ] ],
		'error_rate'  => [ 'SUM( q_errors ) * 1.0 / NULLIF( SUM( q_count ), 0 )', [ 'errors', 'count' ] ],
		'last_seen'   => [ 'MAX( q_last_seen )', [ 'last_seen' ] ],
		'count_2xx'   => [ 'COALESCE( SUM( q_count_2xx ), 0 )', [ 'count_2xx' ] ],
		'count_3xx'   => [ 'COALESCE( SUM( q_count_3xx ), 0 )', [ 'count_3xx' ] ],
		'count_4xx'   => [ 'COALESCE( SUM( q_count_4xx ), 0 )', [ 'count_4xx' ] ],
		'count_5xx'   => [ 'COALESCE( SUM( q_count_5xx ), 0 )', [ 'count_5xx' ] ],
		'worker'      => [ 'MAX( q_worker )', [ 'worker' ] ],
	];

	/** The stored sums `SUMMED` reads, as an arm projects them. */
	private const SUMMED_INPUTS = [
		'count'       => 't.count',
		'timed'       => 't.timed',
		'sum_ms'      => 't.sum_ms',
		'max_ms'      => 't.max_ms',
		'sum_peak_mb' => 't.sum_peak_mb',
		'errors'      => 't.errors',
	];

	/**
	 * Each live window, and the table answering its question while its
	 * watermark lags the window's start.
	 */
	private const WINDOW_FALLBACK = [
		'category_window' => 'timings_site',
		'urls_window'     => 'requests',
	];

	/**
	 * One partition's watermark, `%1$s` its `WATERMARK_QUERY`, and whether
	 * its window `%2$s.%3$s` holds a row that last moved past the bucket `?`,
	 * a seek of its `last_bucket`.
	 */
	private const WINDOW_CHECK = 'SELECT ( %1$s ), EXISTS ( SELECT 1 FROM %2$s.%3$s WHERE last_bucket > ? )';

	/**
	 * Each table's SQL over its row `t`, its URL `u` and the lookups naming
	 * its server `sv`, agent `a`, JA4 `j`, From `fr`, category `c` and entry
	 * `e`: the column its bucket ranges read and how many buckets one of its
	 * units holds; each field's expression; the join a field or input needs, by
	 * name, or the joins in order, as `urls_window`'s server reads its URL's;
	 * each input an arm projects as `m_<name>`; each measure's aggregate
	 * over those inputs, with the inputs it reads, its `{error_kinds}` the
	 * builder's `ERROR_KINDS`; and the `folds` its measures compose by.
	 */
	private const TABLES = [
		'requests'           => [
			'range'    => [ 't.bucket', 1 ],
			'fields'   => [
				'bucket'       => 't.bucket',
				'hour'         => 't.bucket / ' . self::BUCKETS_PER_HOUR,
				'day'          => 't.bucket / ' . self::BUCKETS_PER_DAY,
				'server'       => 'sv.name',
				'url'          => self::URL_FIELDS['url']['hash'],
				'page'         => self::URL_FIELDS['page']['hash'],
				'status'       => 'CASE WHEN ' . self::KNOWN_STATUS . " THEN t.status ELSE '" . self::UNKNOWN . "' END",
				'status_class' => 'CASE WHEN ' . self::KNOWN_STATUS . " THEN t.status / 100 ELSE '" . self::UNKNOWN . "' END",
				'method'       => 't.method',
				'country'      => "COALESCE( t.country, '" . self::UNKNOWN . "' )",
				'ua'           => "COALESCE( a.name, '" . self::UNKNOWN . "' )",
				'ja4'          => "COALESCE( j.name, '" . self::UNKNOWN . "' )",
				'from'         => "COALESCE( fr.name, '" . self::UNKNOWN . "' )",
				'worker'       => 't.worker',
				'profiled'     => 't.profiled',
				'error'        => "COALESCE( t.error, '-' )",
				'rid'          => 't.rid',
			],
			'joins'    => [
				'server' => self::SEEKING_SERVER_JOIN,
				'url'    => self::URL_JOIN,
				'page'   => self::URL_JOIN,
				'ua'     => 'LEFT JOIN %1$s.agents AS a ON a.id = t.ua_id',
				'ja4'    => 'LEFT JOIN %1$s.ja4s AS j ON j.id = t.ja4_id',
				'from'   => 'LEFT JOIN %1$s.froms AS fr ON fr.id = t.from_id',
			],
			'inputs'   => [
				'ms'          => 't.ms',
				'peak_mb'     => 't.peak_mb',
				'error'       => 't.error',
				'finished_at' => 't.finished_at',
				'started_at'  => 't.started_at',
				'url'         => 'u.hash',
				'page'        => 'u.page_hash',
				'server'      => 'sv.name',
				'class'       => 'CASE WHEN ' . self::KNOWN_STATUS . ' THEN t.status / 100 END',
				'worker'      => 't.worker',
				'rid'         => 't.rid',
				'partition'   => 't."partition"',
				'segment'     => 't.segment',
				'offset'      => 't."offset"',
				'length'      => 't.length',
				'status'      => 't.status',
				'method'      => 't.method',
			],
			'measures' => [
				'count'       => [ 'COUNT( * )', [] ],
				'timed'       => [ 'COUNT( m_ms )', [ 'ms' ] ],
				'sum_ms'      => [ 'TOTAL( m_ms )', [ 'ms' ] ],
				'avg_ms'      => [ 'AVG( m_ms )', [ 'ms' ] ],
				'max_ms'      => [ 'MAX( m_ms )', [ 'ms' ] ],
				'min_ms'      => [ 'MIN( m_ms )', [ 'ms' ] ],
				'p50_ms'      => [ 'MAX( CASE WHEN m_rank = ( m_ranked * 50 + 99 ) / 100 THEN m_ms END )', [ 'ms' ] ],
				'p95_ms'      => [ 'MAX( CASE WHEN m_rank = ( m_ranked * 95 + 99 ) / 100 THEN m_ms END )', [ 'ms' ] ],
				'p99_ms'      => [ 'MAX( CASE WHEN m_rank = ( m_ranked * 99 + 99 ) / 100 THEN m_ms END )', [ 'ms' ] ],
				'sum_peak_mb' => [ 'TOTAL( m_peak_mb )', [ 'peak_mb' ] ],
				'avg_peak_mb' => [ 'AVG( m_peak_mb )', [ 'peak_mb' ] ],
				'max_peak_mb' => [ 'MAX( m_peak_mb )', [ 'peak_mb' ] ],
				'errors'      => [ 'COUNT( CASE WHEN m_error IN ( {error_kinds} ) THEN 1 END )', [ 'error' ] ],
				'error_rate'  => [ 'COUNT( CASE WHEN m_error IN ( {error_kinds} ) THEN 1 END ) * 1.0 / NULLIF( COUNT( * ), 0 )', [ 'error' ] ],
				'urls'        => self::DISTINCT['urls'],
				'pages'       => self::DISTINCT['pages'],
				'last_seen'   => [ 'MAX( m_finished_at )', [ 'finished_at' ] ],
				'count_2xx'   => [ 'COUNT( CASE WHEN m_class = 2 THEN 1 END )', [ 'class' ] ],
				'count_3xx'   => [ 'COUNT( CASE WHEN m_class = 3 THEN 1 END )', [ 'class' ] ],
				'count_4xx'   => [ 'COUNT( CASE WHEN m_class = 4 THEN 1 END )', [ 'class' ] ],
				'count_5xx'   => [ 'COUNT( CASE WHEN m_class = 5 THEN 1 END )', [ 'class' ] ],
				'worker'      => [ 'MAX( m_worker )', [ 'worker' ] ],
			],
			'folds'    => self::REQUEST_FOLDS,
		],
		'requests_hourly'    => [
			'range'    => [ 't.hour', self::BUCKETS_PER_HOUR ],
			'fields'   => [
				'hour'         => 't.hour',
				'day'          => 't.hour / 24',
				'server'       => 'sv.name',
				'status_class' => "CASE WHEN t.status_class = 0 THEN '" . self::UNKNOWN . "' ELSE t.status_class END",
				'method'       => 't.method',
				'worker'       => 't.worker',
				'country'      => "CASE WHEN t.country = '' THEN '" . self::UNKNOWN . "' ELSE t.country END",
			],
			'joins'    => [ 'server' => self::SERVER_JOIN ],
			'inputs'   => self::SUMMED_INPUTS,
			'measures' => self::SUMMED + [
				'error_rate' => [ 'SUM( m_errors ) * 1.0 / NULLIF( SUM( m_count ), 0 )', [ 'errors', 'count' ] ],
			],
			'folds'    => self::REQUEST_FOLDS,
		],
		'timings'            => [
			'fields' => [
				'url'  => self::URL_FIELDS['url']['hash'],
				'page' => self::URL_FIELDS['page']['hash'],
			] + self::TIMING_TABLE['fields'],
			'joins'  => [
				'server' => self::SERVER_JOIN,
				'url'    => self::URL_JOIN,
				'page'   => self::URL_JOIN,
			] + self::TIMING_JOINS,
		] + self::TIMING_TABLE,
		'timings_page'       => [
			'fields' => [ 'page' => self::URL_FIELDS['page']['hash'] ] + self::TIMING_TABLE['fields'],
			'joins'  => [
				'server' => self::SERVER_JOIN,
				'page'   => self::PAGE_JOIN,
			] + self::TIMING_JOINS,
			'ids'    => [
				'page'     => [ 't.page_id', 'pages' ],
				'category' => self::BY_ID['category'],
				'entry'    => self::BY_ID['entry'],
			],
		] + self::TIMING_TABLE,
		'timings_site'       => [
			'fields' => [ 'worker' => 't.worker' ] + self::TIMING_TABLE['fields'],
			'joins'  => [ 'server' => self::SEEKING_SERVER_JOIN ] + self::TIMING_JOINS,
		] + self::TIMING_TABLE,
		'owner_timings'      => [
			'range'    => [ 't.bucket', 1 ],
			'fields'   => [
				'bucket' => 't.bucket',
				'hour'   => 't.bucket / ' . self::BUCKETS_PER_HOUR,
				'server' => 'sv.name',
				'url'    => self::URL_FIELDS['url']['hash'],
				'page'   => self::URL_FIELDS['page']['hash'],
				'owner'  => 'o.name',
			],
			'joins'    => [
				'server' => self::SERVER_JOIN,
				'url'    => self::URL_JOIN,
				'page'   => self::URL_JOIN,
				'owner'  => self::OWNER_JOIN,
			],
			'inputs'   => self::TIMING_INPUTS,
			'measures' => self::TIMING_MEASURES,
			'folds'    => self::TIMING_FOLDS,
		],
		'owner_timings_site' => [
			'range'    => [ 't.bucket', 1 ],
			'fields'   => [
				'bucket' => 't.bucket',
				'hour'   => 't.bucket / ' . self::BUCKETS_PER_HOUR,
				'server' => 'sv.name',
				'worker' => 't.worker',
				'owner'  => 'o.name',
			],
			'joins'    => [
				'server' => self::SEEKING_SERVER_JOIN,
				'owner'  => self::OWNER_JOIN,
			],
			'inputs'   => self::TIMING_INPUTS,
			'measures' => self::TIMING_MEASURES,
			'folds'    => self::TIMING_FOLDS,
		],
		'category_window'    => [
			'range'    => [ 't.last_bucket', 1 ],
			'fields'   => [
				'server'   => 'sv.name',
				'category' => 'c.name',
				'entry'    => 'e.name',
				'listener' => 't.listener',
				'worker'   => 't.worker',
			],
			'joins'    => [ 'server' => self::SEEKING_SERVER_JOIN ] + self::TIMING_JOINS,
			'inputs'   => self::TIMING_INPUTS,
			'measures' => self::TIMING_MEASURES,
			'folds'    => self::TIMING_FOLDS,
		],
		'urls_window'        => [
			'range'    => [ 't.last_bucket', 1 ],
			'fields'   => [
				'server' => self::URL_FIELDS['url']['parts']['server'],
				'url'    => self::URL_FIELDS['url']['hash'],
				'page'   => self::URL_FIELDS['page']['hash'],
				'worker' => 't.worker',
			],
			'joins'    => [
				'server' => [ self::URL_JOIN, self::URL_SERVER_JOIN ],
				'url'    => self::URL_JOIN,
				'page'   => self::URL_JOIN,
			],
			'inputs'   => self::SUMMED_INPUTS + [
				'count_2xx'   => 't.count_2xx',
				'count_3xx'   => 't.count_3xx',
				'count_4xx'   => 't.count_4xx',
				'count_5xx'   => 't.count_5xx',
				'min_ms'      => 't.min_ms',
				'max_peak_mb' => 't.max_peak_mb',
				'last_seen'   => 't.last_seen',
				'worker'      => 't.worker',
				'url'         => self::URL_FIELDS['url']['hash'],
				'page'        => self::URL_FIELDS['page']['hash'],
				'server'      => self::URL_FIELDS['url']['parts']['server'],
			],
			'measures' => self::SUMMED + [
				'count_2xx'   => [ 'COALESCE( SUM( m_count_2xx ), 0 )', [ 'count_2xx' ] ],
				'count_3xx'   => [ 'COALESCE( SUM( m_count_3xx ), 0 )', [ 'count_3xx' ] ],
				'count_4xx'   => [ 'COALESCE( SUM( m_count_4xx ), 0 )', [ 'count_4xx' ] ],
				'count_5xx'   => [ 'COALESCE( SUM( m_count_5xx ), 0 )', [ 'count_5xx' ] ],
				'min_ms'      => [ 'MIN( m_min_ms )', [ 'min_ms' ] ],
				'max_peak_mb' => [ 'MAX( m_max_peak_mb )', [ 'max_peak_mb' ] ],
				'last_seen'   => [ 'MAX( m_last_seen )', [ 'last_seen' ] ],
				'worker'      => [ 'MAX( m_worker )', [ 'worker' ] ],
				'urls'        => self::DISTINCT['urls'],
				'pages'       => self::DISTINCT['pages'],
			],
			'folds'    => self::REQUEST_FOLDS,
		],
	];

	/**
	 * Row-read seam. Replaces the one `rows()` call `run()` makes after its
	 * window check, inside the read transaction both share. Tests reassign it
	 * to write a flush between the check and the read, then call `rows()`.
	 * Signature: `function (Row_Reader $reader, non-empty-list<string> $aliases, Spec $spec, string $table): list<list<mixed>>`
	 *
	 * @var (\Closure(Row_Reader, non-empty-list<string>, Spec, string): list<list<mixed>>)|null
	 */
	public static ?\Closure $read_rows = null;

	/**
	 * Answer a spec over every partition's rows. A fleet whose stats file
	 * will not open says so once a window and answers no rows, `available`
	 * false (decision 3). A spec naming `also` answers that page as `also`,
	 * read from the same grouped rows as `rows`, so the union is scanned once.
	 * `now` is the reply's clock the caller read (decision 29); a spec naming
	 * none never reads `category_window` or `urls_window`.
	 *
	 * @param array{source:string, group:list<string>, filter:array<string,list<scalar|null>>, measure:list<string>,
	 *              buckets:list<array{0:int,1:int}>, sort?:string, order?:string, limit?:int, offset?:int, search?:string,
	 *              top?:int, top_by?:string, also?:Also, now?:int} $spec
	 * @return array{source:string, table:string, columns:list<string>, rows:list<list<mixed>>, groups:?int, available:bool, also?:list<list<mixed>>}
	 * @throws \InvalidArgumentException naming an unknown source, field, measure
	 *                                   or sort, a field the table read lacks,
	 *                                   or a `top` it cannot rank.
	 */
	public static function run( array $spec ): array {
		$spec   = self::checked( $spec );
		$read   = self::table( $spec );
		$rows   = self::read(
			self::label( $spec ),
			static function ( Row_Reader $reader, array $aliases ) use ( $spec, &$read ): array {
				// The window check and the rows read one snapshot of each file.
				$reader->rows( 'BEGIN', [] );
				try {
					// Sets the table run() reports.
					$read = self::readable( $reader, $aliases, $spec, $read );
					$rows_of = self::$read_rows ?? self::rows( ... );
					return $rows_of( $reader, $aliases, $spec, $read );
				} finally {
					$reader->rows( 'COMMIT', [] );
				}
			}
		);
		$groups = null;
		if ( null !== $spec['top'] && null !== $rows ) {
			$groups = [] === $rows ? 0 : Core::num_int( \array_slice( $rows[0], -1 )[0] ?? null );
			$rows   = \array_map( static fn ( array $row ): array => \array_slice( $row, 0, -1 ), $rows );
		}
		$pages = [ $rows ?? [], [] ];
		if ( null !== $spec['also'] ) {
			$pages = [ [], [] ];
			foreach ( $rows ?? [] as $row ) {
				$pages[ Core::num_int( $row[ \count( $row ) - 1 ] ) ][] = \array_slice( $row, 0, -2 );
			}
		}
		return [
			'source'    => $spec['source'],
			'table'     => $read,
			'columns'   => self::columns( $spec ),
			'rows'      => $pages[0],
			'groups'    => $groups,
			'available' => null !== $rows,
		] + ( null === $spec['also'] ? [] : [ 'also' => $pages[1] ] );
	}

	/**
	 * The spec's rows across the attached partitions, as `named()` answers.
	 *
	 * @param Row_Reader             $reader  The attached files.
	 * @param non-empty-list<string> $aliases Their aliases.
	 * @param Spec                   $spec    The checked spec.
	 * @param string                 $table   The table answering it.
	 * @return list<list<mixed>>
	 */
	private static function rows( Row_Reader $reader, array $aliases, array $spec, string $table ): array {
		[ $sql, $binds ] = self::statement( $spec, $table, $aliases, true );
		return self::named( $spec['group'], $reader->rows( $sql, $binds ) );
	}

	/**
	 * Rows read in a statement's or `into()`'s layout as an answer's
	 * `columns()`: each `url` or `page` group's hash, then its name read
	 * from its parts, a URL's server and path joined into its `url_name`.
	 *
	 * @param list<string>      $group The spec's group fields.
	 * @param list<list<mixed>> $rows  The rows read.
	 * @return list<list<mixed>>
	 */
	public static function named( array $group, array $rows ): array {
		if ( [] === \array_intersect( $group, \array_keys( self::URL_FIELDS ) ) ) {
			return $rows;
		}
		return \array_map(
			static function ( array $row ) use ( $group ): array {
				$named = [];
				foreach ( $group as $field ) {
					$named[] = \array_shift( $row );
					if ( isset( self::URL_FIELDS[ $field ] ) ) {
						$parts   = \array_map( Core::as_string( ... ), \array_splice( $row, 0, \count( self::URL_FIELDS[ $field ]['parts'] ) ) );
						$named[] = 1 === \count( $parts ) ? $parts[0] : Url_Parts::join_url( ...$parts );
					}
				}
				return [ ...$named, ...$row ];
			},
			$rows
		);
	}

	/**
	 * A spec as its read's span names it, so the reads of one reply tell
	 * apart in a flame: the source, then `by` its groups, then a page's
	 * offset — `timings by category,listener offset 1000`.
	 *
	 * @param Spec $spec The checked spec.
	 */
	private static function label( array $spec ): string {
		$label = $spec['source'];
		if ( [] !== $spec['group'] ) {
			$label .= ' by ' . \implode( ',', $spec['group'] );
		}
		return 0 === $spec['offset'] ? $label : "{$label} offset {$spec['offset']}";
	}

	/**
	 * The statement storing every group `run()` would answer for `$spec`
	 * over the builder's attached partitions as the table `$table` of the
	 * file it writes, and its binds, unpaged: each group field under its own
	 * name, a `url` group as its hash `url` and its `server` and `path`, a
	 * `page` group as its hash `page` and its `page_name`, then each measure
	 * under its own. A sort naming a group field orders the rows by it, its
	 * ties by the other group fields in their order, so a sort by the first
	 * is the order the GROUP BY already emits and SQLite sorts once; with no
	 * sort they come in no order. An empty window stores an empty table.
	 * The caller runs the statement inside a read transaction begun before
	 * this call, so the statement reads the files the window check read.
	 *
	 * @param Row_Reader          $builder The connection building the file.
	 * @param string              $table   The table to create, of `a-z` and `_`.
	 * @param array<string,mixed> $spec    As `run()` takes it, with no `top` and no `also`.
	 * @return array{0:string, 1:list<scalar|null>}
	 * @throws \InvalidArgumentException As `run()`, on a `top`, an `also` or
	 *                                   a sort naming no group field, or on a
	 *                                   table name outside `a-z` and `_`.
	 * @throws \LogicException When the builder attached no partition.
	 * @throws \PDOException When SQLite refuses to read the partitions.
	 */
	public static function into_statement( Row_Reader $builder, string $table, array $spec ): array {
		if ( 1 !== \preg_match( '/^[a-z_]+$/D', $table ) ) {
			throw new \InvalidArgumentException( \esc_html( "into names a table of a-z and _, not {$table}" ) );
		}
		$spec = self::checked( $spec );
		if ( null !== $spec['top'] || null !== $spec['also'] ) {
			throw new \InvalidArgumentException( 'into stores whole groups: no top and no also' );
		}
		$sorted = '' === $spec['sort'] ? null : \array_search( $spec['sort'], $spec['group'], true );
		if ( false === $sorted ) {
			throw new \InvalidArgumentException( \esc_html( "into orders by a group field, not {$spec['sort']}" ) );
		}
		$aliases = $builder->aliases();
		if ( [] === $aliases ) {
			throw new \LogicException( 'into reads at least one partition' );
		}
		$read    = self::table( $spec );
		$columns = [];
		foreach ( $spec['group'] as $i => $field ) {
			$columns[ "f{$i}" ] = $field;
			if ( isset( self::URL_FIELDS[ $field ] ) ) {
				$columns += \array_combine( self::parts( 'x', $i, $field ), \array_keys( self::URL_FIELDS[ $field ]['parts'] ) );
			}
		}
		foreach ( $spec['measure'] as $j => $name ) {
			$columns[ "v{$j}" ] = $name;
		}
		[ $sql, $binds ] = self::statement( $spec, self::readable( $builder, $aliases, $spec, $read ), $aliases, false );
		$projected       = \array_map( static fn ( string $column, string $name ): string => "{$column} AS \"{$name}\"", \array_keys( $columns ), $columns );
		// The bare group keys, the sorted one first.
		$keys    = null === $sorted ? [] : [ $sorted, ...\array_diff( \array_keys( $spec['group'] ), [ $sorted ] ) ];
		$ordered = [] === $keys ? '' : ' ORDER BY ' . \implode( ', ', \array_map( static fn ( int $i ): string => "f{$i}" . ( 'desc' === $spec['order'] ? ' DESC' : '' ), $keys ) );
		return [ "CREATE TABLE {$table} AS SELECT " . \implode( ', ', $projected ) . " FROM ( {$sql}{$ordered} )", $binds ];
	}

	/**
	 * The table the attached partitions answer `$table`'s question from: a
	 * live window only while it holds the spec's range, else the table its
	 * `WINDOW_FALLBACK` names. The caller reads the rows inside the read
	 * transaction this check ran in, so a flush between them cannot move it.
	 *
	 * @param Row_Reader             $reader  The attached files.
	 * @param non-empty-list<string> $aliases Their aliases.
	 * @param Spec                   $spec    The checked spec.
	 * @param string                 $table   The table `table()` chose.
	 */
	private static function readable( Row_Reader $reader, array $aliases, array $spec, string $table ): string {
		return isset( self::WINDOW_FALLBACK[ $table ] ) && ! self::window_holds( $reader, $aliases, $table, $spec['buckets'][0] ) ? self::WINDOW_FALLBACK[ $table ] : $table;
	}

	/**
	 * Whether every attached partition's `$window` holds exactly `$range`:
	 * expired through the bucket before it, its newest `window_expiry`
	 * bucket, and holding no row that last moved past its end, as a builder
	 * a bucket ahead holds until it expires that bucket. A partition that
	 * has expired none does not hold.
	 *
	 * @param Row_Reader             $reader  The attached files.
	 * @param non-empty-list<string> $aliases Their aliases.
	 * @param string                 $window  A `WINDOW_FALLBACK` table.
	 * @param array{0:int,1:int}     $range   The spec's one bucket range.
	 */
	private static function window_holds( Row_Reader $reader, array $aliases, string $window, array $range ): bool {
		$marks = $reader->rows(
			\implode(
				' UNION ALL ',
				\array_map( static fn ( string $alias ): string => \sprintf( self::WINDOW_CHECK, \sprintf( Flame_Facts_Row_Schema::WATERMARK_QUERY, $alias ), $alias, $window ), $aliases )
			),
			\array_fill( 0, \count( $aliases ), $range[1] )
		);
		return [] === \array_filter( $marks, static fn ( array $mark ): bool => null === $mark[0] || $range[0] - 1 !== Core::as_int( $mark[0] ) || 0 !== Core::as_int( $mark[1] ) );
	}

	/**
	 * One statement answering the spec over `$aliases`, and its binds in
	 * the order its placeholders appear: paged, or every group unordered.
	 *
	 * @param Spec                   $spec    The checked spec.
	 * @param string                 $table   The table answering it.
	 * @param non-empty-list<string> $aliases The attached partitions.
	 * @param bool                   $paged   Whether to sort and page it.
	 * @return array{0:string, 1:list<scalar|null>}
	 */
	private static function statement( array $spec, string $table, array $aliases, bool $paged ): array {
		$def      = self::TABLES[ $table ];
		$fields   = \array_values( \array_unique( [ ...$spec['group'], ...self::filtered( $spec['filter'] ) ] ) );
		$named    = null === $spec['top'] ? $spec['measure'] : [ ...$spec['measure'], $spec['top_by'] ];
		$composes = self::composes( $def, $named );
		$folded   = $composes ? self::gathered( $def['folds'], $named ) : [];
		$arm      = self::arm( $spec, $table, $fields, self::gathered( $def['measures'], $composes ? $folded : $named ), $composes );

		[ $from, $binds ] = self::union( $composes ? self::grouped( $arm, $spec, $def, $folded ) : $arm, $aliases );

		$groups  = [];
		$keys    = [];
		$select  = [];
		$columns = [];
		$sorts   = [];
		foreach ( $spec['group'] as $i => $field ) {
			$groups[]        = "f{$i}";
			$select[]        = "f{$i}";
			$columns[]       = "f{$i}";
			$sorts[ $field ] = [ "f{$i}" ];
			if ( ! \in_array( $field, self::TIME_FIELDS, true ) ) {
				$keys[] = "f{$i}";
			}
			if ( isset( self::URL_FIELDS[ $field ] ) ) {
				$parts = self::parts( 'x', $i, $field );
				\array_push( $select, ...\array_map( static fn ( string $part, string $read ): string => "MIN( {$read} ) AS {$part}", $parts, self::parts( 'n', $i, $field ) ) );
				\array_push( $columns, ...$parts );
				$sorts[ self::URL_FIELDS[ $field ]['name'] ] = $parts;
			}
		}
		if ( [] !== \array_intersect( $spec['measure'], self::RANKED ) ) {
			$from = self::with_ranks( $from, $groups );
		}
		foreach ( $spec['measure'] as $j => $name ) {
			$select[]       = self::outer( $def, $name, $composes ) . " AS v{$j}";
			$columns[]      = "v{$j}";
			$sorts[ $name ] = [ "v{$j}" ];
		}
		if ( null !== $spec['top'] ) {
			$select[] = self::outer( $def, $spec['top_by'], $composes ) . ' AS t';
		}
		$grouped = 'SELECT ' . \implode( ', ', $select ) . " FROM {$from}" . ( [] === $groups ? '' : ' GROUP BY ' . \implode( ', ', $groups ) );
		if ( ! $paged ) {
			return [ $grouped, $binds ];
		}
		if ( null !== $spec['top'] ) {
			return [ self::ranked( $grouped, $columns, $groups, $keys ), [ ...$binds, $spec['top'] ] ];
		}
		$order_by = static function ( string $sort, string $order ) use ( $groups, $sorts ): string {
			if ( '' === $sort ) {
				return [] === $groups ? '' : ' ORDER BY ' . \implode( ', ', $groups );
			}
			$sorted = $sorts[ $sort ] ?? throw new \LogicException( \esc_html( "sort {$sort} names no group or measure" ) );
			return ' ORDER BY ' . self::ranking( $sorted, $groups, $order, true );
		};
		$page = $order_by( $spec['sort'], $spec['order'] );
		if ( null === $spec['also'] ) {
			return [ "{$grouped}{$page} LIMIT ? OFFSET ?", [ ...$binds, $spec['limit'], $spec['offset'] ] ];
		}
		return [
			self::paged_twice( $grouped, $columns, $page, $order_by( $spec['also']['sort'], $spec['also']['order'] ) ),
			[ ...$binds, $spec['offset'], $spec['offset'] + $spec['limit'], $spec['also']['limit'] ],
		];
	}

	/**
	 * Two pages of one grouped SELECT, read once: the grouped rows held as a
	 * CTE, each page numbered in its own order and cut to its bounds, every
	 * row carrying its number and its page, 0 or 1, last. Binds the first
	 * page's offset and end, then the second's size.
	 *
	 * @param string       $grouped The grouped SELECT.
	 * @param list<string> $columns The answer's columns, as it names them.
	 * @param string       $first   The first page's ORDER BY clause, or ''.
	 * @param string       $second  The second's.
	 */
	private static function paged_twice( string $grouped, array $columns, string $first, string $second ): string {
		$listed = \implode( ', ', $columns );
		return "WITH g AS MATERIALIZED ( {$grouped} ) "
			. "SELECT {$listed}, r, 0 AS q FROM ( SELECT *, ROW_NUMBER() OVER ({$first} ) AS r FROM g ) WHERE r > ? AND r <= ? "
			. "UNION ALL SELECT {$listed}, r, 1 AS q FROM ( SELECT *, ROW_NUMBER() OVER ({$second} ) AS r FROM g ) WHERE r <= ? "
			. 'ORDER BY q, r';
	}

	/**
	 * The ORDER BY terms ranking by `$sorted` in `$order`, a null last in
	 * either order, its ties broken by `$ties` in the same direction: the one
	 * rule every sort follows. Ascending, a nullable column ranks as
	 * `k IS NULL, k ASC`, which an index on `( k IS NULL, k, … )` serves.
	 * Descending, SQLite ranks a null last already, so the bare `k DESC`
	 * stands, as a column that is never null does in either order.
	 *
	 * @param list<string> $sorted   The sort's columns.
	 * @param list<string> $ties     The columns breaking its ties.
	 * @param string       $order    'asc' or 'desc'.
	 * @param bool         $nullable Whether a sorted column may hold a null.
	 */
	public static function ranking( array $sorted, array $ties, string $order, bool $nullable ): string {
		$direction = ' ' . \strtoupper( $order );
		$terms     = [];
		foreach ( $sorted as $column ) {
			if ( $nullable && 'asc' === $order ) {
				$terms[] = "{$column} IS NULL";
			}
			$terms[] = $column . $direction;
		}
		foreach ( $ties as $column ) {
			$terms[] = $column . $direction;
		}
		return \implode( ', ', $terms );
	}

	/**
	 * A `top` statement over the grouped rows, the union read once: each
	 * tuple of `$keys` ranked by the sum over its time rows of `t`, highest
	 * first and ties by the tuple's values, and the rows of the top ones in
	 * rank order, each carrying the tuples' count last. SQLite evaluates one
	 * window level per SELECT, so each level nests the one before.
	 *
	 * @param string       $grouped The grouped SELECT, its `top_by` aggregate named `t`.
	 * @param list<string> $columns The answer's columns, as the grouped SELECT names them.
	 * @param list<string> $groups  Every group column.
	 * @param list<string> $keys    The non-time group columns.
	 */
	private static function ranked( string $grouped, array $columns, array $groups, array $keys ): string {
		$tuple   = \implode( ', ', $keys );
		$summed  = "SELECT *, SUM( t ) OVER ( PARTITION BY {$tuple} ) AS r_t FROM ( {$grouped} )";
		$ranked  = "SELECT *, DENSE_RANK() OVER ( ORDER BY r_t DESC NULLS LAST, {$tuple} ) AS r_pos FROM ( {$summed} )";
		$counted = "SELECT *, MAX( r_pos ) OVER () AS r_groups FROM ( {$ranked} )";
		return 'SELECT ' . \implode( ', ', $columns ) . ", r_groups FROM ( {$counted} ) WHERE r_pos <= ? ORDER BY r_pos, " . \implode( ', ', $groups );
	}

	/**
	 * `$from` with each row's rank among the timed requests of its group, as
	 * `m_rank`, and how many of them were timed, as `m_ranked`, which the
	 * percentiles read.
	 *
	 * @param string       $from   A row source.
	 * @param list<string> $groups The columns a group is.
	 */
	private static function with_ranks( string $from, array $groups ): string {
		$over = [] === $groups ? '' : 'PARTITION BY ' . \implode( ', ', $groups ) . ' ';
		return "( SELECT *, ROW_NUMBER() OVER ( {$over}ORDER BY m_ms IS NULL, m_ms ) AS m_rank, COUNT( m_ms ) OVER ( {$over}) AS m_ranked FROM {$from} )";
	}

	/**
	 * An arm grouped inside its partition: each group field as `f<i>` and each
	 * folded measure's row aggregate as `q_<name>`. Each group the table sums
	 * by id (`ids()`) joins its partition's table after grouping for its
	 * name: a URL field its hash and its name parts, from one join of the
	 * table naming it, `urls` or a page rollup's `pages`, however many URL
	 * fields the group names, since each is the same id.
	 *
	 * @param array{0:string, 1:list<scalar|null>} $arm    The arm, its `BY_ID` groups by id.
	 * @param Spec                                 $spec   The checked spec.
	 * @param Measured                             $def    The table answering it.
	 * @param list<string>                         $folded The measures whose partials it carries.
	 * @return array{0:string, 1:list<scalar|null>}
	 */
	private static function grouped( array $arm, array $spec, array $def, array $folded ): array {
		$groups   = \array_map( static fn ( int $i ): string => "f{$i}", \array_keys( $spec['group'] ) );
		$partials = \array_map( static fn ( string $name ): string => "q_{$name}", $folded );
		$select   = \array_map( static fn ( string $name ): string => self::aggregate( $def, $name ) . " AS q_{$name}", $folded );
		$sql      = 'SELECT ' . \implode( ', ', [ ...$groups, ...$select ] ) . " FROM ( {$arm[0]} )" . ( [] === $groups ? '' : ' GROUP BY ' . \implode( ', ', $groups ) );
		$outer    = \array_map( static fn ( string $column ): string => "g.{$column}", [ ...$groups, ...$partials ] );
		$ids      = self::ids( $def );
		$urls     = [];
		$joins    = [];
		foreach ( $spec['group'] as $i => $field ) {
			if ( isset( self::URL_FIELDS[ $field ] ) ) {
				$outer[ $i ] = \implode( ', ', [ self::URL_FIELDS[ $field ]['hash'] . " AS f{$i}", ...self::named_parts( $i, $field ) ] );
				if ( [] === $urls ) {
					$urls[] = "JOIN %1\$s.{$ids[ $field ][1]} AS u ON u.id = g.f{$i}";
				}
				\array_push( $urls, ...self::URL_FIELDS[ $field ]['joins'] );
			} elseif ( isset( $ids[ $field ] ) ) {
				$outer[ $i ] = "l{$i}.name AS f{$i}";
				$joins[]     = 'JOIN %1$s.' . $ids[ $field ][1] . " AS l{$i} ON l{$i}.id = g.f{$i}";
			}
		}
		$joins = [ ...\array_unique( $urls ), ...$joins ];
		if ( [] !== $joins ) {
			$sql = 'SELECT ' . \implode( ', ', $outer ) . " FROM ( {$sql} ) AS g " . \implode( ' ', $joins );
		}
		return [ $sql, $arm[1] ];
	}

	/**
	 * The table a checked spec reads, refusing a spec naming no measure or a
	 * field that table lacks.
	 *
	 * @param Spec $spec The checked spec.
	 * @throws \InvalidArgumentException Naming what it refuses.
	 */
	private static function table( array $spec ): string {
		if ( [] === $spec['measure'] ) {
			throw new \InvalidArgumentException( 'a query names at least one measure' );
		}
		$table = self::table_for( $spec );
		foreach ( [ ...$spec['group'], ...self::filtered( $spec['filter'] ) ] as $field ) {
			if ( ! isset( self::TABLES[ $table ]['fields'][ $field ] ) ) {
				throw new \InvalidArgumentException( \esc_html( "a {$spec['source']} query naming these fields reads {$table}, which holds no {$field}" ) );
			}
		}
		return $table;
	}

	/**
	 * The table that answers $spec exactly at least cost: category_window | timings_site | timings_page | timings | owner_timings_site | owner_timings | requests_hourly | urls_window | requests.
	 *
	 * A timings spec over exactly the `Bucket::window()` buckets ending at the
	 * bucket of its `now`, naming only the window's fields, reads
	 * `category_window`; with `readable()` holding the watermark to its
	 * start, the builder and the reader then keep one span. Any other reads
	 * `timings_site` unless a URL or a page is grouped or filtered, or a URL
	 * searched: then `timings_page` when it names a page and no URL and
	 * searches none, else `timings`. A requests spec
	 * reads `requests_hourly` when the rollup keeps
	 * every field and measure it names, its `top_by` among them, and every
	 * bucket range covers whole hours; else `urls_window` when it reads the
	 * same whole window, searches nothing, and names only the fields and
	 * measures a URL's window row keeps or the URLs and pages its rows count;
	 * else `requests`.
	 *
	 * @param array{source:string, group:list<string>, filter:array<string,mixed>, measure:list<string>, buckets:list<array{0:int,1:int}>, search?:string, top_by?:string, now?:int|null} $spec
	 * @throws \RuntimeException When `min_lifetime` is short of one bucket.
	 */
	public static function table_for( array $spec ): string {
		$named    = [ ...$spec['group'], ...self::filtered( $spec['filter'] ) ];
		$measures = '' === ( $spec['top_by'] ?? '' ) ? $spec['measure'] : [ ...$spec['measure'], $spec['top_by'] ];
		$by_url   = [] !== \array_intersect( $named, \array_keys( self::URL_FIELDS ) ) || '' !== ( $spec['search'] ?? '' );
		if ( 'owners' === $spec['source'] ) {
			return $by_url ? 'owner_timings' : 'owner_timings_site';
		}
		if ( 'timings' === $spec['source'] ) {
			if ( $by_url ) {
				$by_page = ! \in_array( 'url', $named, true ) && '' === ( $spec['search'] ?? '' );
				return $by_page ? 'timings_page' : 'timings';
			}
			return self::whole_window( $spec ) && [] === \array_diff( $named, \array_keys( self::TABLES['category_window']['fields'] ) ) ? 'category_window' : 'timings_site';
		}
		$hourly = ! $by_url
			&& [] === \array_diff( $named, \array_keys( self::TABLES['requests_hourly']['fields'] ) )
			&& [] === \array_diff( $measures, \array_keys( self::TABLES['requests_hourly']['measures'] ) )
			&& [] === \array_filter( $spec['buckets'], static fn ( array $range ): bool => 0 !== $range[0] % self::BUCKETS_PER_HOUR || 0 !== ( $range[1] + 1 ) % self::BUCKETS_PER_HOUR );
		if ( $hourly ) {
			return 'requests_hourly';
		}
		$windowed = self::whole_window( $spec )
			&& '' === ( $spec['search'] ?? '' )
			&& [] === \array_diff( $named, \array_keys( self::TABLES['urls_window']['fields'] ) )
			&& [] === \array_diff( $measures, \array_keys( self::TABLES['urls_window']['measures'] ) );
		return $windowed ? 'urls_window' : 'requests';
	}

	/**
	 * Whether a spec reads exactly the `Bucket::window()` buckets ending at
	 * the bucket of its `now`: the span the live windows keep.
	 *
	 * @param array{buckets:list<array{0:int,1:int}>, now?:int|null} $spec
	 */
	private static function whole_window( array $spec ): bool {
		$range = 1 === \count( $spec['buckets'] ) ? $spec['buckets'][0] : null;
		$now   = $spec['now'] ?? null;
		return null !== $range && null !== $now && Bucket::id( $now ) === $range[1] && Bucket::window() === $range[1] - $range[0] + 1;
	}

	/**
	 * A measure's fold over per-partition partials `q_<name>`, as `run()`
	 * merges the partitions, and the measures whose partials it reads: the
	 * one definition a table storing those measures by URL re-derives its
	 * totals from.
	 *
	 * @param string $table A table `run()` reads.
	 * @param string $name  One of its measures.
	 * @return array{0:string, 1:list<string>}
	 * @throws \InvalidArgumentException On a measure the table folds from no partials.
	 */
	public static function fold( string $table, string $name ): array {
		$def = self::TABLES[ $table ] ?? null;
		if ( null === $def || ! isset( $def['measures'][ $name ] ) || ! self::composes( $def, [ $name ] ) ) {
			throw new \InvalidArgumentException( \esc_html( "{$table} folds no {$name} from partials" ) );
		}
		return [ self::outer( $def, $name, true ), self::gathered( $def['folds'], [ $name ] ) ];
	}

	/**
	 * What each named measure's expression reads, each name once: from its
	 * `measures` tuple, its arm inputs; from its `folds` tuple, the measures
	 * its fold reads.
	 *
	 * @param array<string,array{0:string,1:list<string>}> $tuples The table's `measures` or `folds`.
	 * @param list<string>                                 $named  The measures.
	 * @return list<string>
	 */
	private static function gathered( array $tuples, array $named ): array {
		$names = [];
		foreach ( $named as $name ) {
			\array_push( $names, ...$tuples[ $name ][ self::READS ] );
		}
		return \array_values( \array_unique( $names ) );
	}

	/**
	 * A measure's aggregate in the outer statement: its fold over the
	 * partitions' partials when the spec composes, else over the arm's rows.
	 *
	 * @param Measured $def      The table answering the spec.
	 * @param string   $name     The measure.
	 * @param bool     $composes Whether the spec reads partials.
	 */
	private static function outer( array $def, string $name, bool $composes ): string {
		return $composes ? $def['folds'][ $name ][ self::EXPRESSION ] : self::aggregate( $def, $name );
	}

	/**
	 * A measure's aggregate over the arm inputs, its error kinds spelled.
	 *
	 * @param Measured $def  The table answering the spec.
	 * @param string   $name The measure.
	 */
	private static function aggregate( array $def, string $name ): string {
		return \str_replace( '{error_kinds}', "'" . \implode( "', '", Stats_Builder_Node::ERROR_KINDS ) . "'", $def['measures'][ $name ][ self::EXPRESSION ] );
	}

	/**
	 * Whether every measure a spec names, its top_by included, folds from
	 * per-partition partials.
	 *
	 * @param Measured     $def   The table answering it.
	 * @param list<string> $named The measures, top_by included.
	 */
	private static function composes( array $def, array $named ): bool {
		foreach ( $named as $name ) {
			if ( ! isset( $def['folds'][ $name ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The newest `requests` rows `$filter` selects over `$buckets`, by
	 * `finished_at`, at most `$limit` of them, each keyed by `LISTED`: one
	 * fixed statement, the union of every partition's arm, every value bound.
	 * A stats file that will not open lists none.
	 *
	 * @param array<string,list<scalar|null>> $filter  Requests fields to the values each matches.
	 * @param list<array{0:int,1:int}>        $buckets The bucket ranges to read.
	 * @param int                             $limit   Most rows listed, up to `MAX_LIMIT`.
	 * @return list<array<string,mixed>>
	 * @throws \InvalidArgumentException Naming a field, a bucket range or a limit it refuses.
	 */
	public static function newest_requests( array $filter, array $buckets, int $limit ): array {
		return self::listed( 'requests newest', $filter, $buckets, $limit ) ?? [];
	}

	/**
	 * The `requests` row a rid keys, every bucket read, as `newest_requests()`
	 * lists it: `row` null where none names it, and `available` false, `row`
	 * null, where the stats file will not open, so an outage is never read
	 * as an absence.
	 *
	 * @param string $rid Request id.
	 * @return array{available: bool, row: array<string,mixed>|null}
	 */
	public static function request_row( string $rid ): array {
		$rows = self::listed( 'requests by rid', [ 'rid' => [ $rid ] ], [], 1 );
		return [
			'available' => null !== $rows,
			'row'       => $rows[0] ?? null,
		];
	}

	/**
	 * The `LISTED` columns of the `requests` rows `$filter` selects over
	 * `$buckets`, newest `finished_at` first, at most `$limit`; null where the
	 * stats file will not open.
	 *
	 * @param string                          $label   What the listing is, the label of its read's span.
	 * @param array<string,list<scalar|null>> $filter  Requests fields to the values each matches.
	 * @param list<array{0:int,1:int}>        $buckets The bucket ranges to read; none for a key.
	 * @param int                             $limit   Most rows listed.
	 * @return list<array<string,mixed>>|null
	 * @throws \InvalidArgumentException Naming what `checked()` refuses.
	 */
	private static function listed( string $label, array $filter, array $buckets, int $limit ): ?array {
		$spec = self::checked(
			[
				'source'  => 'requests',
				'filter'  => $filter,
				'buckets' => $buckets,
				'limit'   => $limit,
			]
		);
		$rows = self::read(
			$label,
			static function ( Row_Reader $reader, array $aliases ) use ( $spec ): array {
				[ $from, $binds ] = self::union( self::arm( $spec, 'requests', self::filtered( $spec['filter'] ), self::LISTED ), $aliases );
				$listed           = \implode( ', ', \array_map( static fn ( string $column ): string => "m_{$column}", self::LISTED ) );
				return $reader->rows( "SELECT {$listed} FROM {$from} ORDER BY m_finished_at DESC, m_rid LIMIT ?", [ ...$binds, $spec['limit'] ] );
			}
		);
		return null === $rows ? null : \array_map( static fn ( array $row ): array => \array_combine( self::LISTED, $row ), $rows );
	}

	/**
	 * One partition's arm, its alias a `%1$s` placeholder: each group field
	 * as `f<i>`, a URL field's name parts beside it as `n<i>_<k>`, and
	 * each input as `m_<name>`, over the bucket ranges, the filters and the
	 * search. Grouped by id, an `ids()` field is its id alone, and joins its
	 * table only for a filter on it or, for a URL, a search; a URL never
	 * joins `servers`. A filter naming a URL field joins the server through
	 * `SERVER_JOIN`. Each join is made once, however many fields need it.
	 *
	 * @param Spec         $spec      The checked spec.
	 * @param string       $table     The table answering it.
	 * @param list<string> $fields    Every field the spec groups or filters.
	 * @param list<string> $inputs    Every input its measures read.
	 * @param bool         $by_id     Whether a `BY_ID` group projects its id.
	 * @return array{0:string, 1:list<scalar|null>}
	 */
	private static function arm( array $spec, string $table, array $fields, array $inputs, bool $by_id = false ): array {
		$def    = self::TABLES[ $table ];
		$expr   = $def['fields'];
		$by     = self::ids( $def );
		$select = [];
		$after  = [];
		foreach ( $spec['group'] as $i => $field ) {
			if ( $by_id && isset( $by[ $field ] ) ) {
				$select[] = $by[ $field ][0] . " AS f{$i}";
				continue;
			}
			$select[] = Core::as_string( $expr[ $field ] ) . " AS f{$i}";
			if ( isset( self::URL_FIELDS[ $field ] ) ) {
				\array_push( $select, ...self::named_parts( $i, $field ) );
				\array_push( $after, ...self::URL_FIELDS[ $field ]['joins'] );
			}
		}
		foreach ( $inputs as $input ) {
			$select[] = Core::as_string( Core::arr( $def['inputs'] )[ $input ] ) . " AS m_{$input}";
		}
		if ( [] === $select ) {
			$select[] = '1 AS m_row';
		}

		[ $column, $per ] = $def['range'];
		$where            = [];
		$binds            = [];
		foreach ( $spec['buckets'] as [ $first, $last ] ) {
			$where[] = "{$column} BETWEEN ? AND ?";
			\array_push( $binds, \intdiv( $first, $per ), \intdiv( $last + 1, $per ) - 1 );
		}
		$clauses = [] === $where ? [] : [ '( ' . \implode( ' OR ', $where ) . ' )' ];
		foreach ( $spec['filter'] as $key => $values ) {
			$field     = self::field_of( $key );
			$in        = $field === $key ? ' IN ( ' : ' NOT IN ( ';
			$clauses[] = Core::as_string( $expr[ $field ] ) . $in . \implode( ', ', \array_fill( 0, \count( $values ), '?' ) ) . ' )';
			\array_push( $binds, ...$values );
		}
		$ids    = \array_keys( $by );
		$joined = $by_id ? [ ...\array_diff( $fields, $ids ), ...\array_intersect( self::filtered( $spec['filter'] ), $ids ) ] : $fields;
		if ( '' !== $spec['match'] ) {
			$clauses[] = self::SEARCH;
			$binds[]   = $spec['match'];
			$joined[]  = 'url';
		}
		$joins   = [];
		$one_url = [] !== \array_intersect_key( $spec['filter'], self::URL_FIELDS );
		foreach ( Core::arr( $def['joins'] ) as $name => $named ) {
			if ( \in_array( $name, [ ...$joined, ...$inputs ], true ) ) {
				foreach ( (array) $named as $join ) {
					$joins[] = $one_url && self::SEEKING_SERVER_JOIN === $join ? self::SERVER_JOIN : Core::as_string( $join );
				}
			}
		}
		$sql = 'SELECT ' . \implode( ', ', $select ) . " FROM %1\$s.{$table} AS t "
			. \implode( ' ', \array_unique( [ ...$joins, ...$after ] ) ) . ' WHERE ' . \implode( ' AND ', $clauses );
		return [ $sql, $binds ];
	}

	/**
	 * A URL field's name parts as an arm reads them over its URL `u`.
	 *
	 * @param int    $i     The field's place in the group.
	 * @param string $field A `URL_FIELDS` field.
	 * @return list<string>
	 */
	private static function named_parts( int $i, string $field ): array {
		return \array_map( static fn ( string $read, string $part ): string => "{$read} AS {$part}", \array_values( self::URL_FIELDS[ $field ]['parts'] ), self::parts( 'n', $i, $field ) );
	}

	/**
	 * The columns holding a URL field's name parts in one layer of a
	 * statement, `<prefix><i>_<k>` for its `k`th part: `n` up to the union,
	 * `x` past it.
	 *
	 * @param string $prefix `n` or `x`.
	 * @param int    $i      The field's place in the group.
	 * @param string $field  A `URL_FIELDS` field.
	 * @return list<string>
	 */
	private static function parts( string $prefix, int $i, string $field ): array {
		return \array_map( static fn ( int $k ): string => "{$prefix}{$i}_{$k}", \array_keys( \array_values( self::URL_FIELDS[ $field ]['parts'] ) ) );
	}

	/**
	 * The fields a table sums by id: its own `ids`, else `BY_ID`.
	 *
	 * @param Measured $def The table.
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function ids( array $def ): array {
		return $def['ids'] ?? self::BY_ID;
	}

	/**
	 * One arm over every alias: the `UNION ALL` of its copies, and its
	 * binds once per copy.
	 *
	 * @param array{0:string, 1:list<scalar|null>} $arm     An arm and its binds, its alias a `%1$s` placeholder.
	 * @param non-empty-list<string>               $aliases The attached partitions.
	 * @return array{0:string, 1:list<scalar|null>}
	 */
	private static function union( array $arm, array $aliases ): array {
		return [
			'( ' . \implode( ' UNION ALL ', \array_map( static fn ( string $alias ): string => \sprintf( $arm[0], $alias ), $aliases ) ) . ' )',
			\array_merge( ...\array_fill( 0, \count( $aliases ), $arm[1] ) ),
		];
	}

	/**
	 * What `$read` answers over every attached partition of the Row_Table
	 * the active topologies declare with the Flame_Facts schema, none when no
	 * partition has written a file. A fleet whose stats file will not open,
	 * or that declares no such table, says so once a window and answers null
	 * (decision 3); one declaring two is refused.
	 *
	 * On a started record the read is a `stats query` span labelled `$label`,
	 * closing on the rows it read, or `unavailable`.
	 *
	 * @param string                                                          $label What the read is, as `label()` names a spec.
	 * @param \Closure(Row_Reader, non-empty-list<string>): list<list<mixed>> $read  The read, over the reader and its aliases.
	 * @return list<list<mixed>>|null
	 * @throws \RuntimeException When the active topologies declare two such tables.
	 */
	private static function read( string $label, \Closure $read ): ?array {
		return Log_Manager::span(
			Flame_Tree::STATS_QUERY,
			static function () use ( $read ): ?array {
				try {
					return Row_Table_Node::read(
						Flame_Facts_Row_Schema::class,
						static function ( Row_Reader $reader ) use ( $read ): array {
							$aliases = $reader->aliases();
							return [] === $aliases ? [] : $read( $reader, $aliases );
						}
					);
				} catch ( Table_Unavailable $e ) {
					Core::print_less_often( 'performance: stats rows did not open', ' — ' . $e->getMessage() );
					return null;
				}
			},
			static fn ( ?array $rows ): string => null === $rows ? 'unavailable' : \count( $rows ) . ' rows',
			start: [ 'l' => $label ]
		);
	}

	/**
	 * A spec with every key present, each name checked against the
	 * vocabulary, each integer field's digits read as integers, and its
	 * search as the FTS5 query an arm matches.
	 *
	 * @param array<string,mixed> $spec As `run()` takes it.
	 * @return Spec
	 * @throws \InvalidArgumentException Naming what it refuses.
	 */
	private static function checked( array $spec ): array {
		$source = Core::as_string( $spec['source'] ?? null );
		$fields = self::FIELDS[ $source ] ?? throw self::unknown_source( $source );
		$group  = self::strings( $spec['group'] ?? null );
		$filter = [];
		foreach ( Core::arr( $spec['filter'] ?? null ) as $key => $values ) {
			$field                   = self::field_of( (string) $key );
			$filter[ (string) $key ] = \array_values( \array_map( static fn ( mixed $value ): mixed => self::bindable( $field, $value ), Core::arr( $values ) ) );
		}
		foreach ( $group as $field ) {
			if ( ! \in_array( $field, $fields, true ) ) {
				throw new \InvalidArgumentException( \esc_html( "unknown {$source} field {$field}: one of " . \implode( ', ', $fields ) ) );
			}
		}
		$filters = [ ...$fields, ...self::KEYS[ $source ] ];
		foreach ( self::filtered( $filter ) as $field ) {
			if ( ! \in_array( $field, $filters, true ) ) {
				throw new \InvalidArgumentException( \esc_html( "unknown {$source} field {$field}: one of " . \implode( ', ', $filters ) ) );
			}
		}
		$measure = self::strings( $spec['measure'] ?? null );
		foreach ( $measure as $name ) {
			if ( ! \in_array( $name, self::MEASURES[ $source ], true ) ) {
				throw new \InvalidArgumentException( \esc_html( "unknown {$source} measure {$name}: one of " . \implode( ', ', self::MEASURES[ $source ] ) ) );
			}
		}
		$checked = [
			'source'  => $source,
			'group'   => $group,
			'filter'  => $filter,
			'measure' => $measure,
			'buckets' => \array_values( \array_map( static fn ( mixed $range ): array => [ Core::as_int( Core::arr( $range )[0] ?? null ), Core::as_int( Core::arr( $range )[1] ?? null ) ], Core::arr( $spec['buckets'] ?? null ) ) ),
			'sort'    => Core::as_string( $spec['sort'] ?? '' ),
			'order'   => Core::as_string( $spec['order'] ?? 'asc' ),
			'limit'   => Core::as_int( $spec['limit'] ?? self::MAX_LIMIT ),
			'offset'  => Core::as_int( $spec['offset'] ?? 0 ),
			'search'  => Core::as_string( $spec['search'] ?? '' ),
			'now'     => isset( $spec['now'] ) ? Core::as_int( $spec['now'] ) : null,
		];
		$checked['match']                     = '' === $checked['search'] ? '' : self::match( $checked['search'] );
		[ $checked['top'], $checked['top_by'] ] = self::top( $spec, $source, $group );
		$checked['also']                      = null;
		if ( [] === $checked['buckets'] && [] === \array_intersect_key( $filter, \array_flip( self::KEYS[ $source ] ) ) ) {
			throw new \InvalidArgumentException( 'a query names at least one bucket range, or a key to look up' );
		}
		foreach ( \array_diff_assoc( $group, \array_unique( $group ) ) as $name ) {
			throw new \InvalidArgumentException( \esc_html( "{$name} is grouped twice" ) );
		}
		foreach ( \array_intersect( $measure, $group ) as $name ) {
			throw new \InvalidArgumentException( \esc_html( "{$name} names both a group and a measure" ) );
		}
		$sortable = self::columns(
			[
				'group'   => $group,
				'measure' => $measure,
			]
		);
		if ( '' !== $checked['sort'] && ! \in_array( $checked['sort'], $sortable, true ) ) {
			throw new \InvalidArgumentException( \esc_html( "unknown sort {$checked['sort']}: one of the groups and measures, " . \implode( ', ', $sortable ) ) );
		}
		if ( 'asc' !== $checked['order'] && 'desc' !== $checked['order'] ) {
			throw new \InvalidArgumentException( \esc_html( "unknown order {$checked['order']}: asc or desc" ) );
		}
		if ( $checked['limit'] < 1 || $checked['limit'] > self::MAX_LIMIT ) {
			throw new \InvalidArgumentException( \esc_html( "limit {$checked['limit']} is outside 1.." . self::MAX_LIMIT ) );
		}
		if ( $checked['offset'] < 0 || $checked['offset'] > self::MAX_OFFSET ) {
			throw new \InvalidArgumentException( \esc_html( "offset {$checked['offset']} is outside 0.." . self::MAX_OFFSET ) );
		}
		$checked['also'] = self::also( $spec, $sortable, null !== $checked['top'] );
		return $checked;
	}

	/**
	 * A spec's `also`, checked: a sort among the groups and measures, an
	 * order and a page size, refused beside a `top`, whose rows come in rank
	 * order. No `also` answers null.
	 *
	 * @param array<string,mixed> $spec     As `run()` takes it.
	 * @param list<string>        $sortable What the spec may sort by.
	 * @param bool                $top      Whether it names a top.
	 * @return Also|null
	 * @throws \InvalidArgumentException Naming what it refuses.
	 */
	private static function also( array $spec, array $sortable, bool $top ): ?array {
		if ( null === ( $spec['also'] ?? null ) ) {
			return null;
		}
		if ( $top ) {
			throw new \InvalidArgumentException( 'top takes no also: its rows come in rank order' );
		}
		$also  = Core::arr( $spec['also'] );
		$sort  = Core::as_string( $also['sort'] ?? '' );
		$order = Core::as_string( $also['order'] ?? 'asc' );
		$limit = Core::as_int( $also['limit'] ?? self::MAX_LIMIT );
		if ( ! \in_array( $sort, $sortable, true ) ) {
			throw new \InvalidArgumentException( \esc_html( "unknown also sort {$sort}: one of the groups and measures, " . \implode( ', ', $sortable ) ) );
		}
		if ( 'asc' !== $order && 'desc' !== $order ) {
			throw new \InvalidArgumentException( \esc_html( "unknown also order {$order}: asc or desc" ) );
		}
		if ( $limit < 1 || $limit > self::MAX_LIMIT ) {
			throw new \InvalidArgumentException( \esc_html( "also limit {$limit} is outside 1.." . self::MAX_LIMIT ) );
		}
		return [
			'sort'  => $sort,
			'order' => $order,
			'limit' => $limit,
		];
	}

	/**
	 * The answer's columns: each group field, a `url` or a `page` followed
	 * by its name, then each measure.
	 *
	 * @param array{group:list<string>, measure:list<string>} $spec
	 * @return list<string>
	 */
	public static function columns( array $spec ): array {
		$columns = [];
		foreach ( $spec['group'] as $field ) {
			$columns[] = $field;
			if ( isset( self::URL_FIELDS[ $field ] ) ) {
				$columns[] = self::URL_FIELDS[ $field ]['name'];
			}
		}
		return [ ...$columns, ...$spec['measure'] ];
	}

	/**
	 * A spec's `top` and `top_by`, checked: a top names a measure of its
	 * source to rank by, a time field to answer and a non-time field to rank,
	 * and takes no page, since its rows are bounded by its buckets times its
	 * top. No top answers null and ''.
	 *
	 * @param array<string,mixed> $spec   As `run()` takes it.
	 * @param string              $source Its source.
	 * @param list<string>        $group  Its group fields.
	 * @return array{0:?int, 1:string}
	 * @throws \InvalidArgumentException Naming what it refuses.
	 */
	private static function top( array $spec, string $source, array $group ): array {
		$top_by = Core::as_string( $spec['top_by'] ?? '' );
		if ( null === ( $spec['top'] ?? null ) ) {
			if ( '' !== $top_by ) {
				throw new \InvalidArgumentException( \esc_html( "top_by {$top_by} needs a top" ) );
			}
			return [ null, '' ];
		}
		$top = Core::as_int( $spec['top'] );
		if ( $top < 1 || $top > self::MAX_TOP ) {
			throw new \InvalidArgumentException( \esc_html( "top {$top} is outside 1.." . self::MAX_TOP ) );
		}
		if ( '' === $top_by ) {
			throw new \InvalidArgumentException( \esc_html( "top {$top} names no top_by measure to rank by" ) );
		}
		if ( ! \in_array( $top_by, self::ADDITIVE[ $source ], true ) ) {
			throw new \InvalidArgumentException( \esc_html( "top_by {$top_by} is no {$source} measure that adds across buckets: one of " . \implode( ', ', self::ADDITIVE[ $source ] ) ) );
		}
		if ( [] === \array_intersect( $group, self::TIME_FIELDS ) ) {
			throw new \InvalidArgumentException( \esc_html( 'top needs a time field in its group: ' . \implode( ', ', self::TIME_FIELDS ) ) );
		}
		if ( [] === \array_diff( $group, self::TIME_FIELDS ) ) {
			throw new \InvalidArgumentException( 'top needs a non-time field in its group to rank' );
		}
		foreach ( [ 'limit', 'offset' ] as $page ) {
			if ( \array_key_exists( $page, $spec ) ) {
				throw new \InvalidArgumentException( \esc_html( "top takes no {$page}: its rows are its top tuples' buckets" ) );
			}
		}
		if ( '' !== Core::as_string( $spec['sort'] ?? '' ) ) {
			throw new \InvalidArgumentException( 'top takes no sort: its rows come in rank order' );
		}
		return [ $top, $top_by ];
	}

	/**
	 * The fields a spec's filter names, a key `<field>!` naming its field.
	 *
	 * @param array<array-key,mixed> $filter A spec's filter.
	 * @return list<string>
	 */
	public static function filtered( array $filter ): array {
		return \array_values( \array_unique( \array_map( static fn ( int|string $key ): string => self::field_of( (string) $key ), \array_keys( $filter ) ) ) );
	}

	/**
	 * The field a filter key names: itself, or a negated key's field.
	 *
	 * @param string $key A filter key, `<field>` or `<field>!`.
	 */
	public static function field_of( string $key ): string {
		return \str_ends_with( $key, '!' ) ? \substr( $key, 0, -1 ) : $key;
	}

	/**
	 * A filter value as it binds: an integer field's digits as an integer,
	 * so it compares equal to the column, a status class spelled `5xx` in any
	 * case as its digit, a server as `Url_Parts::server_name()` stores it;
	 * anything else as it came.
	 *
	 * @param string $field The field it filters.
	 * @param mixed  $value The value.
	 * @return scalar|null
	 */
	private static function bindable( string $field, mixed $value ): mixed {
		if ( 'server' === $field && \is_string( $value ) ) {
			return Url_Parts::server_name( $value );
		}
		if ( 'status_class' === $field && \is_string( $value ) && 1 === \preg_match( '/^(\d)xx$/iD', $value, $class ) ) {
			$value = $class[1];
		}
		if ( \is_string( $value ) && \in_array( $field, self::INTEGER_FIELDS, true ) ) {
			return Core::canonical_decimal( $value ) ?? $value;
		}
		return \is_scalar( $value ) ? $value : null;
	}

	/**
	 * A list of names or tokens as strings: a spec's groups and measures, or
	 * a variadic verb arg, null when absent.
	 *
	 * @param mixed $value The list.
	 * @return list<string>
	 */
	public static function strings( mixed $value ): array {
		return \array_values( \array_map( Core::as_string( ... ), Core::arr( $value ) ) );
	}

	/**
	 * The most buckets a selection over `$source` may name: as many as its
	 * rows reach back, the span and `Bucket::RETAIN_SLACK` for either source.
	 *
	 * @param string $source A source of `FIELDS`.
	 * @throws \InvalidArgumentException On a source the vocabulary lacks.
	 * @throws \RuntimeException When `min_lifetime` is short of one bucket.
	 */
	public static function retention( string $source ): int {
		return isset( self::FIELDS[ $source ] ) ? Bucket::window() + Bucket::RETAIN_SLACK : throw self::unknown_source( $source );
	}

	/**
	 * The refusal a source the vocabulary lacks answers.
	 *
	 * @param string $source The source asked for.
	 */
	private static function unknown_source( string $source ): \InvalidArgumentException {
		return new \InvalidArgumentException( \esc_html( "unknown source {$source}: one of " . \implode( ', ', \array_keys( self::FIELDS ) ) ) );
	}

	/**
	 * A search term as an FTS5 query: each word of it quoted and the words
	 * joined by spaces, so every word must match and no operator in the
	 * term acts as one.
	 *
	 * @param string $term The search term.
	 * @throws \InvalidArgumentException When the term holds no word.
	 */
	private static function match( string $term ): string {
		$words = \preg_split( '/[^\p{L}\p{N}]+/u', $term, -1, \PREG_SPLIT_NO_EMPTY ) ?: [];
		if ( [] === $words ) {
			throw new \InvalidArgumentException( \esc_html( "search {$term} holds no word" ) );
		}
		return \implode( ' ', \array_map( static fn ( string $word ): string => "\"{$word}\"", $words ) );
	}
}
