<?php
/**
 * Url_Snapshot: the URL table of the window's buckets, built whole by the
 * overview-prefetch job and read by the verbs until it expires.
 *
 * One SQLite file, `{base}/tables/flame-facts.job.sqlite`, holds a `servers`
 * table naming each server by an id assigned in name order; a `urls` table
 * of one row per URL, `Stats_Query`'s `url` and `page` group over every
 * partition with `Performance_CI_Node::URL_MEASURES`, its server by id, its
 * page by `page_hash` and name, and its rowids assigned in hash order; a
 * `pages` table of one row per page and server, a second only for a page
 * of both worker and visitor shapes, each URL measure folded over its URLs,
 * `views` naming the view that reads it, `shapes` counting the URLs, and
 * `hash` and `path` naming the one where there is one, its rowids in hash
 * order and indexed for each sort the `urls` verb takes once site-wide and
 * once led by the server, a nullable sort's null-last ranking taking a
 * second pair;
 * `urls_page` seeking a page's URLs; a `totals` table of one row per view
 * and scope, counting its pages; the stored answers by key; and when it was
 * built and expires. The substrate publishes it whole under `NAME` and
 * opens it `immutable` (ADR-35), so no reader sees a half-built file.
 *
 * The verbs name a server by its name, a page and a URL by their hashes,
 * and a listing answers each; the ids stay inside the file.
 *
 * A worker URL is a property of the URL, so the default view keeps the rows
 * whose `worker` is 0 and Include Workers keeps every row; of `pages`, the
 * default view keeps `views` 0 and 2 and Include Workers 0 and 1.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Nodes\Core;
use Newspack_Nodes\Driver_Error;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Table_Unavailable;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A published snapshot, open for reading.
 */
final class Url_Snapshot {

	/** The snapshot's name, which the substrate publishes it under. */
	public const NAME = 'flame-facts.job';

	/** The sorts whose key may be null, which rank a null last either way. */
	private const NULLABLE = [ 'avg_ms', 'min_ms', 'max_ms' ];

	/** The groups the `urls` table stores: each URL and the page it fixes. */
	private const GROUPED = [
		'group'   => [ 'url', 'page' ],
		'measure' => Performance_CI_Node::URL_MEASURES,
	];

	/**
	 * Each table a listing reads: the `Stats_Query` group its rows answer
	 * as, what it selects ahead of `URL_MEASURES` and after them, the sorts
	 * it takes, and the columns its name sort ranks by.
	 */
	private const LISTED = [
		'urls'  => [
			'group' => [ 'url' ],
			'ahead' => 'url, ( SELECT name FROM servers WHERE servers.id = urls.server_id ) AS server, path',
			'after' => [],
			'sorts' => Performance_CI_Node::URL_SORTS,
			'named' => [ 'server_id', 'path' ],
		],
		'pages' => [
			'group' => [ 'page', 'server' ],
			'ahead' => 'page_hash, page, ( SELECT name FROM servers WHERE servers.id = pages.server_id ) AS server',
			'after' => [ 'shapes', 'hash', 'path' ],
			'sorts' => Performance_CI_Node::PAGE_SORTS,
			'named' => [ 'page' ],
		],
	];

	/** What a scope with no URLs totals, in `URL_TOTALS` order. */
	private const NO_TOTALS = [ 0, null, null, 0, 0, 0 ];

	/** The id of the server a `?` names; null for a name `servers` lacks. */
	private const SERVER_ID = '( SELECT id FROM servers WHERE name = ? )';

	/**
	 * Each field a listing's filter names as the clause its one value binds:
	 * a server by its id, an equality the server-led index seeks; the worker
	 * flag, an integer the column's no-affinity value matches only as an
	 * integer; a page by its hash, which `urls_page` seeks; and the view a
	 * `pages` row is not of, so it keeps every row but the other view's.
	 */
	private const SCOPED = [
		'worker' => 'worker = ?',
		'server' => 'server_id = ' . self::SERVER_ID,
		'page'   => 'page_hash = ?',
		'views'  => 'views <> ?',
	];

	/** Whether a `pages` kind folds an `i` row: kind 1 each, kind 2 a visitor's. */
	private const KIND_KEEPS = 'k.kind = 1 OR i.worker = 0';

	/**
	 * The `pages` rows of each page and server, in hash order. `i` folds the
	 * URLs' partials `%1$s` by `%2$s` once per worker flag; each `kind` then
	 * folds those, its partials `%3$s` by `%4$s`: kind 1 every one, kind 2
	 * the visitors' alone, kept only where the page has both. A row's `views`
	 * says which view reads it: 0, a page of visitor shapes alone, the same
	 * in both views; 1, every shape of a page with a worker shape, which
	 * Include Workers reads; 2, the visitor shapes of a page with both, which
	 * the default view reads. So a view keeps `views <> workers + 1`.
	 * `shapes` counts the URLs a row folds, and `hash` and `path` name the one
	 * URL of a row folding one.
	 */
	private const PAGES_TABLE = 'CREATE TABLE pages AS '
		. 'WITH i AS ( SELECT page_hash, MIN( page ) AS page, server_id, %2$s, COUNT( * ) AS shapes, MIN( url ) AS hash, MIN( path ) AS path '
		. 'FROM ( SELECT u.page_hash, u.page, u.server_id, u.worker AS w, u.url, u.path, %1$s FROM urls AS u ) GROUP BY page_hash, server_id, w ), '
		. 'k ( kind ) AS ( VALUES ( 1 ), ( 2 ) ), '
		. 'r AS ( SELECT k.kind, i.page_hash, i.page, i.server_id, i.worker AS w, %3$s, '
		. 'CASE WHEN ' . self::KIND_KEEPS . ' THEN i.shapes END AS q_shapes, CASE WHEN ' . self::KIND_KEEPS . ' THEN i.hash END AS q_hash, '
		. 'CASE WHEN ' . self::KIND_KEEPS . ' THEN i.path END AS q_path FROM k JOIN i ) '
		. 'SELECT page_hash, MIN( page ) AS page, server_id, CASE kind WHEN 2 THEN 2 ELSE MAX( w ) END AS views, %4$s, '
		. 'SUM( q_shapes ) AS shapes, CASE SUM( q_shapes ) WHEN 1 THEN MIN( q_hash ) END AS hash, CASE SUM( q_shapes ) WHEN 1 THEN MIN( q_path ) END AS path '
		. 'FROM r GROUP BY page_hash, server_id, kind HAVING kind = 1 OR MIN( w ) < MAX( w ) ORDER BY page_hash, server_id, kind';

	/**
	 * One `totals` row per view and scope, every server as id 0 then each:
	 * the URLs' partials `%1$s` summed once by worker and server, then
	 * folded by the totals `%2$s`, every one a sum, so the sums compose;
	 * and `pages`, the `pages` rows of the view and scope, summed off `c`,
	 * which counts `pages` once by `views` and server.
	 */
	private const TOTALS_TABLE = 'CREATE TABLE totals AS '
		. 'WITH g AS ( SELECT u.worker, u.server_id, %1$s, COUNT( * ) AS q_urls FROM urls AS u GROUP BY u.worker, u.server_id ), '
		. 'c AS MATERIALIZED ( SELECT views, server_id, COUNT( * ) AS n FROM pages GROUP BY views, server_id ), '
		. 'v ( workers ) AS ( VALUES ( 0 ), ( 1 ) ), '
		. 'r AS ( SELECT v.workers, g.* FROM v JOIN g ON v.workers = 1 OR g.worker = 0 ) '
		. 'SELECT workers, 0 AS server_id, %2$s, ( SELECT SUM( n ) FROM c WHERE c.views <> r.workers + 1 ) AS pages FROM r GROUP BY workers '
		. 'UNION ALL SELECT workers, server_id, %2$s, ( SELECT SUM( n ) FROM c WHERE c.views <> r.workers + 1 AND c.server_id = r.server_id ) AS pages FROM r GROUP BY workers, server_id';

	/**
	 * Hold an opened snapshot.
	 *
	 * @param Row_Reader $db    The immutable connection.
	 * @param int        $as_of When the job built it.
	 */
	private function __construct( private readonly Row_Reader $db, private readonly int $as_of ) {
	}

	/**
	 * Publish the snapshot of the window ending at `$now` through
	 * `Row_Table_Node::build()`: the URLs, their pages, their totals, the
	 * answers `$answers` gives by key, and an expiry `$ttl` seconds from the
	 * end of its build, written last, ahead only of the sync and rename that
	 * publish the file. A fleet with no partition file yet publishes nothing,
	 * and a build that throws leaves the last publish.
	 *
	 * On a started record the build is a `snapshot build` span closing
	 * `published` or `no partition file`, each of its steps a span inside;
	 * the architecture guide says what the build span's own time holds. The
	 * answers are asked inside the build, after its totals, so each is a span
	 * inside `snapshot build`.
	 *
	 * @param int                                                    $now     The job's tick.
	 * @param int                                                    $ttl     Seconds the snapshot serves from the end of its build.
	 * @param \Closure(list<string>): array<string,array<string,mixed>> $answers The stored answers by key, given the window's servers busiest first.
	 * @throws \Newspack_Nodes\Table_Unavailable As `Row_Table_Node::build()`,
	 *                                           or when no active topology
	 *                                           declares a Flame_Facts table.
	 * @throws \RuntimeException When two do, the build will not publish, or
	 *                           an answer will not encode.
	 * @throws \InvalidArgumentException As `$answers`.
	 * @throws \LogicException As `$answers`.
	 */
	public static function publish( int $now, int $ttl, \Closure $answers ): void {
		Log_Manager::span(
			Flame_Tree::SNAPSHOT_BUILD,
			static fn (): mixed => Row_Table_Node::build(
				Flame_Facts_Row_Schema::class,
				self::NAME,
				static function ( Row_Reader $db ) use ( $now, $ttl, $answers ): bool {
					self::fill( $db, $now, $ttl, $answers );
					return true;
				}
			),
			static fn ( mixed $built ): string => false === $built ? 'no partition file' : 'published'
		);
	}

	/**
	 * Write the snapshot's tables into the file `$db` builds.
	 *
	 * @param Row_Reader                                             $db      The building connection.
	 * @param int                                                    $now     The job's tick.
	 * @param int                                                    $ttl     Seconds the snapshot serves from the end of its build.
	 * @param \Closure(list<string>): array<string,array<string,mixed>> $answers The stored answers by key, given the window's servers.
	 * @throws \RuntimeException When an answer will not encode.
	 */
	private static function fill( Row_Reader $db, int $now, int $ttl, \Closure $answers ): void {
		foreach ( self::url_steps( $db, $now ) as $span => $statements ) {
			self::step( $db, $span, $statements );
		}
		self::step( $db, Flame_Tree::SNAPSHOT_PAGES, [ [ self::pages_table(), [] ] ] );
		foreach ( self::indexes() as $name => [ $table, $columns ] ) {
			self::step( $db, Flame_Tree::SNAPSHOT_INDEX, [ [ "CREATE INDEX {$name} ON {$table} ( {$columns} )", [] ] ], [ 'l' => $name ] );
		}
		self::step(
			$db,
			Flame_Tree::SNAPSHOT_TOTALS,
			[
				[ self::totals_table(), [] ],
				[ 'CREATE UNIQUE INDEX totals_scope ON totals ( workers, server_id )', [] ],
			]
		);
		$db->rows( 'CREATE TABLE answers ( "key" TEXT PRIMARY KEY, value TEXT NOT NULL )', [] );
		foreach ( $answers( self::servers( $db ) ) as $key => $answer ) {
			$json = \wp_json_encode( $answer );
			if ( false === $json ) {
				throw new \RuntimeException( \esc_html( "overview prefetch: answer {$key} will not encode" ) );
			}
			$db->rows( 'INSERT INTO answers ( "key", value ) VALUES ( ?, ? )', [ $key, $json ] );
		}
		$db->rows( 'CREATE TABLE meta ( as_of INTEGER NOT NULL, expires INTEGER NOT NULL )', [] );
		$db->rows( 'INSERT INTO meta ( as_of, expires ) VALUES ( ?, ? )', [ $now, (int) Core::right_now() + $ttl ] );
	}

	/**
	 * Every server of the window, worker traffic counted, busiest first and
	 * ties as `Stats_Query::ranking()` breaks them, as many as one page of a
	 * live read names: the `overview`'s `servers`, summed off the `totals`
	 * the build just wrote.
	 *
	 * @param Row_Reader $db The building connection.
	 * @return list<string>
	 */
	private static function servers( Row_Reader $db ): array {
		$rows = $db->rows( 'SELECT s.name FROM totals AS t JOIN servers AS s ON s.id = t.server_id WHERE t.workers = 1 ORDER BY ' . Stats_Query::ranking( [ 't.count' ], [ 's.name' ], 'desc', false ) . ' LIMIT ?', [ Stats_Query::MAX_LIMIT ] );
		return \array_map( static fn ( array $row ): string => Core::as_string( $row[0] ), $rows );
	}

	/**
	 * The statement creating `totals`: each of `URL_TOTALS` but `urls` and
	 * `pages` folded from the URLs' partials as `Stats_Query` merges a
	 * query's partitions, `urls` the URL count, and `pages` as
	 * `TOTALS_TABLE` counts it, one row each.
	 */
	private static function totals_table(): string {
		[ $partials, $totals ] = self::totals_of( \array_diff( Performance_CI_Node::URL_TOTALS, [ 'pages' ] ), 'SUM( u.%s )', [ 'urls' => 'SUM( q_urls )' ] );
		return \sprintf( self::TOTALS_TABLE, \implode( ', ', $partials ), \implode( ', ', $totals ) );
	}

	/**
	 * Every index by name, as its table and its columns. `pages` takes, per
	 * sort, its keys for the site-wide listing as `pages_<sort>` and, as
	 * `pages_server_<sort>`, `server_id` then its keys for one server's, a
	 * nullable key adding a `_null` pair ranking its nulls last; the view is
	 * a filter on the scan. Each ends at its keys, because the rowid every
	 * index carries breaks a tie as the hash does, and a backward scan serves
	 * the other order. `urls_page` seeks a page's URLs, the worker flag after
	 * the hash, so the default view seeks it whole and Include Workers by
	 * hash, and a page's few shapes sort in memory.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function indexes(): array {
		$indexes = [];
		foreach ( self::LISTED['pages']['sorts'] as $sort ) {
			$keys = [ $sort => self::sorted( 'pages', $sort ) ] + ( \in_array( $sort, self::NULLABLE, true ) ? [ "{$sort}_null" => [ "{$sort} IS NULL", $sort ] ] : [] );
			foreach ( $keys as $name => $columns ) {
				$indexes[ "pages_{$name}" ]        = [ 'pages', \implode( ', ', $columns ) ];
				$indexes[ "pages_server_{$name}" ] = [ 'pages', \implode( ', ', [ 'server_id', ...$columns ] ) ];
			}
		}
		return $indexes + [ 'urls_page' => [ 'urls', 'page_hash, worker' ] ];
	}

	/**
	 * The statement creating `pages`: each of `URL_MEASURES` folded over the
	 * URLs of a page as `Stats_Query` merges a query's partitions, first by
	 * worker flag, then by kind.
	 */
	private static function pages_table(): string {
		[ $by_flag, $flagged ] = self::folded( Performance_CI_Node::URL_MEASURES, 'u.%s' );
		[ $by_kind, $kinded ]  = self::folded( Performance_CI_Node::URL_MEASURES, 'CASE WHEN ' . self::KIND_KEEPS . ' THEN i.%s END' );
		return \sprintf( self::PAGES_TABLE, \implode( ', ', $by_flag ), \implode( ', ', $flagged ), \implode( ', ', $by_kind ), \implode( ', ', $kinded ) );
	}

	/**
	 * Run one step's statements in order, as a `$span` span.
	 *
	 * @param Row_Reader                                $db         The building connection.
	 * @param string                                    $span       A `Flame_Tree` snapshot step.
	 * @param list<array{0:string,1:list<scalar|null>}> $statements Each statement and its binds.
	 * @param array<string,mixed>                       $start      Extra keys for the `(start)` entry.
	 */
	private static function step( Row_Reader $db, string $span, array $statements, array $start = [] ): void {
		Log_Manager::span(
			$span,
			static function () use ( $db, $statements ): void {
				foreach ( $statements as [ $sql, $binds ] ) {
					$db->rows( $sql, $binds );
				}
			},
			start: $start
		);
	}

	/**
	 * The statements building `servers` and `urls`, and their binds, by the
	 * step that runs them, in the order they run: the window's URLs grouped
	 * with their pages into a scratch table in hash order; then the servers
	 * named by ids in name order, the URLs copied in the scratch table's
	 * order, so a rowid ranks as the hash, and the scratch table dropped
	 * ahead of the indexes, which reuse the space it frees.
	 *
	 * @param Row_Reader $db  The building connection.
	 * @param int        $now The job's tick.
	 * @return array<string,list<array{0:string,1:list<scalar|null>}>>
	 */
	private static function url_steps( Row_Reader $db, int $now ): array {
		$grouped  = [
			'source'  => 'requests',
			'filter'  => [],
			'buckets' => Bucket::last( Bucket::window(), $now ),
			'sort'    => 'url',
		] + self::GROUPED;
		$measures = \implode( ', ', \array_map( static fn ( string $name ): string => "g.{$name}", self::GROUPED['measure'] ) );
		return [
			Flame_Tree::SNAPSHOT_GROUP => [ Stats_Query::into_statement( $db, 'grouped_urls', $grouped ) ],
			Flame_Tree::SNAPSHOT_COPY  => [
				[ 'CREATE TABLE servers ( id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE )', [] ],
				[ 'INSERT INTO servers ( name ) SELECT server FROM grouped_urls GROUP BY server ORDER BY server', [] ],
				[ "CREATE TABLE urls AS SELECT g.url, s.id AS server_id, g.path, g.page AS page_hash, g.page_name AS page, {$measures} FROM grouped_urls AS g JOIN servers AS s ON s.name = g.server ORDER BY g.rowid", [] ],
				[ 'DROP TABLE grouped_urls', [] ],
			],
		];
	}

	/**
	 * One listing of the pages a view and scope keep, as `Stats_Query`
	 * answers a `page` and `server` group with `URL_MEASURES` sorted by
	 * `$sort`, each row's `shapes`, `hash` and `url_name` after them, the one
	 * URL's name as a live `url` group names it, and beside it as `also` the
	 * slowest `SLOWEST_ROWS` by `avg_ms`.
	 *
	 * @param string $server  Server to scope to; '' is every server.
	 * @param bool   $workers Read Include Workers' rows; false reads the default view's.
	 * @param string $sort    One of `Performance_CI_Node::PAGE_SORTS`' values.
	 * @param string $order   'asc' or 'desc'.
	 * @param int    $offset  Rows skipped, 0 to `Stats_Query::MAX_OFFSET`.
	 * @param int    $limit   Rows answered, 1 to `Stats_Query::MAX_LIMIT`.
	 * @return array{columns:list<string>, rows:list<list<mixed>>, available:true, also:list<list<mixed>>}
	 * @throws \InvalidArgumentException On a sort, an order or a bound the
	 *                                   live listing refuses.
	 */
	public function pages( string $server, bool $workers, string $sort, string $order, int $offset, int $limit ): array {
		$listed = $this->listed( 'pages', self::viewed( $server, $workers ), $sort, $order, $offset, $limit );
		return [
			'columns' => [ ...\array_slice( $listed['columns'], 0, -1 ), 'url_name' ],
			'rows'    => self::url_named( $listed['rows'] ),
			'also'    => self::url_named( $listed['also'] ),
		] + $listed;
	}

	/**
	 * Page rows, their server third, with the stored path that ends each
	 * joined to that server as `Url_Parts::join_url()` names a URL; null where
	 * the row folds more than one URL.
	 *
	 * @param list<list<mixed>> $rows Page rows ending in their one URL's path.
	 * @return list<list<mixed>>
	 */
	private static function url_named( array $rows ): array {
		return \array_map(
			static function ( array $row ): array {
				$path = \array_pop( $row );
				return [ ...$row, null === $path ? null : Url_Parts::join_url( Core::as_string( $row[2] ), Core::as_string( $path ) ) ];
			},
			$rows
		);
	}

	/**
	 * The `pages` filter keeping a view over a scope: every row but the
	 * other view's, the default view's `views` 2 or Include Workers' 1.
	 *
	 * @param string $server  Server to scope to; '' is every server.
	 * @param bool   $workers Include Workers; false is the default view.
	 * @return array<string,list<scalar>>
	 */
	private static function viewed( string $server, bool $workers ): array {
		return [ 'views' => [ $workers ? 2 : 1 ] ] + Performance_CI_Node::scope( $server, true );
	}

	/**
	 * One listing of a page's URLs a view and scope keep, as `Stats_Query`
	 * answers a `url` group with `URL_MEASURES` filtered to the page, sorted
	 * by `$sort`, and beside it as `also` the slowest `SLOWEST_ROWS` by
	 * `avg_ms`.
	 *
	 * @param string $page    The page's hash.
	 * @param string $server  Server to scope to; '' is every server.
	 * @param bool   $workers Keep worker URLs; false leaves them out.
	 * @param string $sort    One of `Performance_CI_Node::URL_SORTS`' values.
	 * @param string $order   'asc' or 'desc'.
	 * @param int    $offset  Rows skipped, 0 to `Stats_Query::MAX_OFFSET`.
	 * @param int    $limit   Rows answered, 1 to `Stats_Query::MAX_LIMIT`.
	 * @return array{columns:list<string>, rows:list<list<mixed>>, available:true, also:list<list<mixed>>}
	 * @throws \InvalidArgumentException On a sort, an order or a bound the
	 *                                   live listing refuses.
	 */
	public function shapes( string $page, string $server, bool $workers, string $sort, string $order, int $offset, int $limit ): array {
		return $this->listed( 'urls', [ 'page' => [ $page ] ] + Performance_CI_Node::scope( $server, $workers ), $sort, $order, $offset, $limit );
	}

	/**
	 * One listing of `$table`'s rows `$filter` keeps, in `Stats_Query`'s
	 * columns for the table's group, and its slowest as `also`.
	 *
	 * @param string                     $table  A `LISTED` table.
	 * @param array<string,list<scalar>> $filter Fields `SCOPED` maps, one value each.
	 * @param string                     $sort   One of the table's sorts.
	 * @param string                     $order  'asc' or 'desc'.
	 * @param int                        $offset Rows skipped, 0 to `Stats_Query::MAX_OFFSET`.
	 * @param int                        $limit  Rows answered, 1 to `Stats_Query::MAX_LIMIT`.
	 * @return array{columns:list<string>, rows:list<list<mixed>>, available:true, also:list<list<mixed>>}
	 * @throws \InvalidArgumentException On a sort, an order or a bound the
	 *                                   live listing refuses.
	 */
	private function listed( string $table, array $filter, string $sort, string $order, int $offset, int $limit ): array {
		if ( $limit < 1 || $limit > Stats_Query::MAX_LIMIT ) {
			throw new \InvalidArgumentException( \esc_html( "limit {$limit} is outside 1.." . Stats_Query::MAX_LIMIT ) );
		}
		if ( $offset < 0 || $offset > Stats_Query::MAX_OFFSET ) {
			throw new \InvalidArgumentException( \esc_html( "offset {$offset} is outside 0.." . Stats_Query::MAX_OFFSET ) );
		}
		[ $sql, $binds ] = self::listing( $table, $filter, $sort, $order );
		[ $slow ]        = self::listing( $table, $filter, 'avg_ms', 'desc' );
		$group           = self::LISTED[ $table ]['group'];
		return [
			'columns'   => Stats_Query::columns(
				[
					'group'   => $group,
					'measure' => [ ...Performance_CI_Node::URL_MEASURES, ...self::LISTED[ $table ]['after'] ],
				]
			),
			'rows'      => Stats_Query::named( $group, $this->db->rows( "{$sql} LIMIT ? OFFSET ?", [ ...$binds, $limit, $offset ] ) ),
			'available' => true,
			'also'      => Stats_Query::named( $group, $this->db->rows( "{$slow} LIMIT ?", [ ...$binds, Performance_CI_Node::SLOWEST_ROWS ] ) ),
		];
	}

	/**
	 * The unpaged SELECT of `$table`'s rows `$filter` keeps in a sort's
	 * order, and its binds.
	 *
	 * @param string                     $table  A `LISTED` table.
	 * @param array<string,list<scalar>> $filter Fields `SCOPED` maps, one value each.
	 * @param string                     $sort   One of the table's sorts.
	 * @param string                     $order  'asc' or 'desc'.
	 * @return array{0:string, 1:list<scalar>}
	 * @throws \InvalidArgumentException On a sort or an order the table does not take.
	 */
	private static function listing( string $table, array $filter, string $sort, string $order ): array {
		[ $where, $binds ] = self::where( $filter );
		$selected          = [ self::LISTED[ $table ]['ahead'], ...Performance_CI_Node::URL_MEASURES, ...self::LISTED[ $table ]['after'] ];
		return [ 'SELECT ' . \implode( ', ', $selected ) . " FROM {$table}{$where} ORDER BY " . self::order( $table, $sort, $order ), $binds ];
	}

	/**
	 * The ORDER BY a sort and an order name over `$table`, as
	 * `Stats_Query::ranking()` ranks a sort, its ties broken by the rowid,
	 * which ranks as the hash.
	 *
	 * @param string $table A `LISTED` table.
	 * @param string $sort  One of the table's sorts.
	 * @param string $order 'asc' or 'desc'.
	 * @throws \InvalidArgumentException On a sort or an order the table does not take.
	 */
	private static function order( string $table, string $sort, string $order ): string {
		if ( ! \in_array( $sort, self::LISTED[ $table ]['sorts'], true ) ) {
			throw new \InvalidArgumentException( \esc_html( "unknown sort {$sort} for {$table}: one of " . \implode( ', ', self::LISTED[ $table ]['sorts'] ) ) );
		}
		if ( 'asc' !== $order && 'desc' !== $order ) {
			throw new \InvalidArgumentException( \esc_html( "unknown order {$order}: asc or desc" ) );
		}
		return Stats_Query::ranking( self::sorted( $table, $sort ), [ 'rowid' ], $order, \in_array( $sort, self::NULLABLE, true ) );
	}

	/**
	 * The columns a sort of `$table` ranks by: the table's name columns for
	 * its name sort, the sort's own column for any other.
	 *
	 * @param string $table A `LISTED` table.
	 * @param string $sort  One of the table's sorts.
	 * @return list<string>
	 */
	private static function sorted( string $table, string $sort ): array {
		return self::LISTED[ $table ]['sorts']['url'] === $sort ? self::LISTED[ $table ]['named'] : [ $sort ];
	}

	/**
	 * The totals of a page's URLs a view and scope keep, as `Stats_Query`
	 * answers `Performance_CI_Node::URL_TOTALS` filtered to the page with no
	 * group, folded off the page's `urls` rows: zeroes and null means where
	 * the scope holds none of them.
	 *
	 * @param string $page    The page's hash.
	 * @param string $server  Server to scope to; '' is every server.
	 * @param bool   $workers Keep worker URLs; false leaves them out.
	 * @return array{columns:list<string>, rows:list<list<mixed>>, available:true}
	 */
	public function shape_totals( string $page, string $server, bool $workers ): array {
		[ $where, $binds ]     = self::where( [ 'page' => [ $page ] ] + Performance_CI_Node::scope( $server, $workers ) );
		[ $partials, $totals ] = self::totals_of(
			Performance_CI_Node::URL_TOTALS,
			'%s',
			[
				'urls'  => 'COUNT( * )',
				'pages' => 'COUNT( DISTINCT server_id )',
			]
		);
		return [
			'columns'   => Performance_CI_Node::URL_TOTALS,
			'rows'      => $this->db->rows( 'SELECT ' . \implode( ', ', $totals ) . ' FROM ( SELECT server_id, ' . \implode( ', ', $partials ) . " FROM urls{$where} )", $binds ),
			'available' => true,
		];
	}

	/**
	 * Each total of `$names` as its SQL, in their order: a count `$counts`
	 * names as that count, and every other folded from the partials
	 * `$partial` reads; and those partials.
	 *
	 * @param array<string>        $names   `URL_TOTALS` names, in their order.
	 * @param string               $partial A partial's expression over the column `%s`.
	 * @param array<string,string> $counts  The SQL counting each count among `$names`, by name.
	 * @return array{0:array<string,string>, 1:list<string>} The partials, then the totals.
	 */
	private static function totals_of( array $names, string $partial, array $counts ): array {
		[ $partials, $folds ] = self::folded( \array_diff( $names, \array_keys( $counts ) ), $partial );
		foreach ( $counts as $name => $count ) {
			$folds[ $name ] = "{$count} AS {$name}";
		}
		return [ $partials, \array_values( \array_map( static fn ( string $name ): string => $folds[ $name ], $names ) ) ];
	}

	/**
	 * Each `requests` measure of `$names` as its `Stats_Query::fold()` named
	 * for itself, and each column those folds read as its partial
	 * `q_<column>`, `$partial` of the column's name.
	 *
	 * @param array<string> $names   Measures that fold.
	 * @param string        $partial The partial's expression over the name `%s`.
	 * @return array{0:array<string,string>, 1:array<string,string>} The partials, then the folds, each by name.
	 */
	private static function folded( array $names, string $partial ): array {
		$partials = [];
		$folds    = [];
		foreach ( $names as $name ) {
			[ $fold, $reads ] = Stats_Query::fold( 'requests', $name );
			$folds[ $name ]   = "{$fold} AS {$name}";
			foreach ( $reads as $read ) {
				$partials[ $read ] = \sprintf( $partial, $read ) . " AS q_{$read}";
			}
		}
		return [ $partials, $folds ];
	}

	/**
	 * The WHERE clause a filter names through `SCOPED`, and its binds, a
	 * server as `Url_Parts::server_name()` stores it; a server `servers`
	 * lacks keeps no row.
	 *
	 * @param array<string,list<scalar>> $filter Fields `SCOPED` maps.
	 * @return array{0:string, 1:list<scalar>}
	 * @throws \LogicException On a field `SCOPED` maps no clause for, or one
	 *                         naming other than one value, which a filter the
	 *                         snapshot ignored would leave a listing unfiltered.
	 */
	private static function where( array $filter ): array {
		$clauses = [];
		$binds   = [];
		foreach ( $filter as $field => $values ) {
			$clauses[] = self::SCOPED[ $field ] ?? throw new \LogicException( \esc_html( "the URL snapshot scopes no {$field}" ) );
			if ( 1 !== \count( $values ) ) {
				throw new \LogicException( \esc_html( "the URL snapshot scopes one {$field}, not " . \count( $values ) ) );
			}
			$binds[] = 'server' === $field ? Url_Parts::server_name( Core::as_string( $values[0] ) ) : $values[0];
		}
		return [ [] === $clauses ? '' : ' WHERE ' . \implode( ' AND ', $clauses ), $binds ];
	}

	/**
	 * The published snapshot, when one stands that has not expired at `$now`.
	 * A file that will not open says so once a window.
	 *
	 * @param int $now The reply's clock.
	 */
	public static function open( int $now ): ?self {
		$why = 'it holds no meta row';
		try {
			$db = Row_Table_Node::open_snapshot( self::NAME );
			if ( null === $db ) {
				return null;
			}
			$meta = $db->rows( 'SELECT as_of, expires FROM meta', [] )[0] ?? null;
			if ( null !== $meta ) {
				return Core::as_int( $meta[1] ) <= $now ? null : new self( $db, Core::as_int( $meta[0] ) );
			}
		} catch ( Table_Unavailable | \PDOException $e ) {
			// The stderr line reaches the firehose: a driver's text is shaped.
			$why = Driver_Error::of( $e );
		}
		Core::print_less_often( 'performance: the URL snapshot did not open', ' — ' . $why );
		return null;
	}

	/** When the job built the snapshot. */
	public function as_of(): int {
		return $this->as_of;
	}

	/**
	 * The answer the job stored under `$key`, or null where it stored none.
	 *
	 * @param string $key The answer's key.
	 * @return array<array-key,mixed>|null
	 */
	public function answer( string $key ): ?array {
		$rows = $this->db->rows( 'SELECT value FROM answers WHERE "key" = ?', [ $key ] );
		return [] === $rows ? null : Core::arr( \json_decode( Core::as_string( $rows[0][0] ), true, 512, \JSON_THROW_ON_ERROR ) );
	}

	/**
	 * The totals of the URLs a view and scope keep, as `Stats_Query` answers
	 * `Performance_CI_Node::URL_TOTALS` with no group: one row, zeroes and
	 * null means where the scope holds no URL.
	 *
	 * @param string $server  Server to scope to; '' is every server.
	 * @param bool   $workers Keep worker URLs; false leaves them out.
	 * @return array{columns:list<string>, rows:list<list<mixed>>, available:true}
	 */
	public function totals( string $server, bool $workers ): array {
		$scope = '' === $server ? [ '0', [] ] : [ self::SERVER_ID, [ Url_Parts::server_name( $server ) ] ];
		$rows  = $this->db->rows(
			'SELECT ' . \implode( ', ', Performance_CI_Node::URL_TOTALS ) . " FROM totals WHERE workers = ? AND server_id = {$scope[0]}",
			[ $workers ? 1 : 0, ...$scope[1] ]
		);
		return [
			'columns'   => Performance_CI_Node::URL_TOTALS,
			'rows'      => [ $rows[0] ?? self::NO_TOTALS ],
			'available' => true,
		];
	}
}
