<?php
/**
 * Url_Snapshot: the URL table of the window's buckets, built whole by the
 * overview-prefetch job and read by the verbs until it expires.
 *
 * One SQLite file, `{base}/tables/flame-facts.job.sqlite`, holds a `servers`
 * table naming each server by an id assigned in name order; a `urls` table
 * of one row per URL, `Stats_Query`'s `url` group over every partition
 * with `Performance_CI_Node::URL_MEASURES`, its server by id and its
 * rowids assigned in hash order, indexed for each sort the `urls` verb
 * takes once site-wide and once led by the server, a nullable sort's
 * null-last ranking taking a second pair; a `totals` table of
 * one row per view and scope; the stored answers by key; and when it was
 * built and expires. The substrate publishes it whole under `NAME` and
 * opens it `immutable` (ADR-35), so no reader sees a half-built file.
 *
 * The verbs name a server by its name and a URL by its hash, and a page
 * answers both; the ids stay inside the file.
 *
 * A worker URL is a property of the URL, so the default view keeps the rows
 * whose `worker` is 0 and Include Workers keeps every row.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Nodes\Core;
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

	/** The `url` group the `urls` table stores and a page answers. */
	private const GROUPED = [
		'group'   => [ 'url' ],
		'measure' => Performance_CI_Node::URL_MEASURES,
	];

	/** What a scope with no URLs totals, in `URL_TOTALS` order. */
	private const NO_TOTALS = [ 0, null, null, 0, 0 ];

	/** The id of the server a `?` names; null for a name `servers` lacks. */
	private const SERVER_ID = '( SELECT id FROM servers WHERE name = ? )';

	/**
	 * Each `Performance_CI_Node::scope()` field as the `urls` clause matching
	 * its one value, bound as given: a server by its id, an equality the
	 * server-led index seeks, and the worker flag, an integer the column's
	 * no-affinity value matches only as an integer.
	 */
	private const SCOPED = [
		'worker' => 'worker = ?',
		'server' => 'server_id = ' . self::SERVER_ID,
	];

	/**
	 * One `totals` row per view and scope, every server as id 0 then each:
	 * the URLs' partials `%1$s` summed once by worker and server, then
	 * folded by the totals `%2$s`, every one a sum, so the sums compose.
	 */
	private const TOTALS_TABLE = 'CREATE TABLE totals AS '
		. 'WITH g AS ( SELECT u.worker, u.server_id, %1$s, COUNT( * ) AS q_urls FROM urls AS u GROUP BY u.worker, u.server_id ), '
		. 'v ( workers ) AS ( VALUES ( 0 ), ( 1 ) ), '
		. 'r AS ( SELECT v.workers, g.* FROM v JOIN g ON v.workers = 1 OR g.worker = 0 ) '
		. 'SELECT workers, 0 AS server_id, %2$s FROM r GROUP BY workers '
		. 'UNION ALL SELECT workers, server_id, %2$s FROM r GROUP BY workers, server_id';

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
	 * `Row_Table_Node::build()`: the URLs, their totals, the answers
	 * `$answers` gives by key, and an expiry `$ttl` seconds from the end of
	 * its build, written last, ahead only of the sync and rename that
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
		foreach ( self::indexes() as $name => $columns ) {
			self::step( $db, Flame_Tree::SNAPSHOT_INDEX, [ [ "CREATE INDEX urls_{$name} ON urls ( {$columns} )", [] ] ], [ 'l' => "urls_{$name}" ] );
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
	 * The statement creating `totals`: each of `URL_TOTALS` but `urls`
	 * folded from the URLs' partials as `Stats_Query` merges a query's
	 * partitions, and `urls` the URL count, one row each.
	 */
	private static function totals_table(): string {
		$partials = [];
		$totals   = [];
		foreach ( Performance_CI_Node::URL_TOTALS as $name ) {
			if ( 'urls' === $name ) {
				$totals[ $name ] = 'SUM( q_urls ) AS urls';
				continue;
			}
			[ $fold, $reads ] = Stats_Query::fold( 'requests', $name );
			$totals[ $name ]  = "{$fold} AS {$name}";
			foreach ( $reads as $read ) {
				$partials[ $read ] = "SUM( u.{$read} ) AS q_{$read}";
			}
		}
		return \sprintf( self::TOTALS_TABLE, \implode( ', ', $partials ), \implode( ', ', $totals ) );
	}

	/**
	 * The `urls` indexes by name: per sort its keys for the site-wide page
	 * and, as `server_<sort>`, `server_id` then its keys for one server's, a
	 * nullable key adding a `_null` pair ranking its nulls last. Each ends at
	 * its keys, because the rowid every index carries breaks a tie as the
	 * hash does, and a backward scan serves the other order. `url_name`'s
	 * leads with `server_id`, so it serves both scopes.
	 *
	 * @return array<string,string>
	 */
	private static function indexes(): array {
		$indexes = [ 'url_name' => 'server_id, path' ];
		foreach ( \array_diff( Performance_CI_Node::URL_SORTS, [ 'url_name' ] ) as $sort ) {
			$keys = [ $sort => $sort ] + ( \in_array( $sort, self::NULLABLE, true ) ? [ "{$sort}_null" => "{$sort} IS NULL, {$sort}" ] : [] );
			foreach ( $keys as $name => $columns ) {
				$indexes[ $name ]            = $columns;
				$indexes[ "server_{$name}" ] = "server_id, {$columns}";
			}
		}
		return $indexes;
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
	 * into a scratch table in hash order; then the servers named by ids in
	 * name order, the URLs copied in the scratch table's order, so a rowid
	 * ranks as the hash, and the scratch table dropped ahead of the indexes,
	 * which reuse its pages.
	 *
	 * @param Row_Reader $db  The building connection.
	 * @param int        $now The job's tick.
	 * @return array<string,list<array{0:string,1:list<scalar|null>}>>
	 */
	private static function url_steps( Row_Reader $db, int $now ): array {
		$grouped  = [
			'source'  => 'requests',
			'filter'  => [],
			'buckets' => Bucket::last( Flame_Facts_Row_Schema::WINDOW_BUCKETS, $now ),
			'sort'    => 'url',
		] + self::GROUPED;
		$measures = \implode( ', ', \array_map( static fn ( string $name ): string => "g.{$name}", self::GROUPED['measure'] ) );
		return [
			Flame_Tree::SNAPSHOT_GROUP => [ Stats_Query::into_statement( $db, 'grouped_urls', $grouped ) ],
			Flame_Tree::SNAPSHOT_COPY  => [
				[ 'CREATE TABLE servers ( id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE )', [] ],
				[ 'INSERT INTO servers ( name ) SELECT server FROM grouped_urls GROUP BY server ORDER BY server', [] ],
				[ "CREATE TABLE urls AS SELECT g.url, s.id AS server_id, g.path, {$measures} FROM grouped_urls AS g JOIN servers AS s ON s.name = g.server ORDER BY g.rowid", [] ],
				[ 'DROP TABLE grouped_urls', [] ],
			],
		];
	}

	/**
	 * One page of the URLs a view and scope keep, as `Stats_Query` answers a
	 * `url` group with `URL_MEASURES` sorted by `$sort`, and beside it as
	 * `also` the slowest `SLOWEST_ROWS` by `avg_ms`.
	 *
	 * @param string $server  Server to scope to; '' is every server.
	 * @param bool   $workers Keep worker URLs; false leaves them out.
	 * @param string $sort    One of `Performance_CI_Node::URL_SORTS`' values.
	 * @param string $order   'asc' or 'desc'.
	 * @param int    $offset  Rows skipped, 0 to `Stats_Query::MAX_OFFSET`.
	 * @param int    $limit   Rows answered, 1 to `Stats_Query::MAX_LIMIT`.
	 * @return array{columns:list<string>, rows:list<list<mixed>>, available:true, also:list<list<mixed>>}
	 * @throws \InvalidArgumentException On a sort, an order or a bound the
	 *                                   live page refuses.
	 */
	public function page( string $server, bool $workers, string $sort, string $order, int $offset, int $limit ): array {
		if ( $limit < 1 || $limit > Stats_Query::MAX_LIMIT ) {
			throw new \InvalidArgumentException( \esc_html( "limit {$limit} is outside 1.." . Stats_Query::MAX_LIMIT ) );
		}
		if ( $offset < 0 || $offset > Stats_Query::MAX_OFFSET ) {
			throw new \InvalidArgumentException( \esc_html( "offset {$offset} is outside 0.." . Stats_Query::MAX_OFFSET ) );
		}
		[ $page, $binds ] = self::listing( $server, $workers, $sort, $order );
		[ $slow ]         = self::listing( $server, $workers, 'avg_ms', 'desc' );
		return [
			'columns'   => Stats_Query::columns( self::GROUPED ),
			'rows'      => Stats_Query::named( self::GROUPED['group'], $this->db->rows( "{$page} LIMIT ? OFFSET ?", [ ...$binds, $limit, $offset ] ) ),
			'available' => true,
			'also'      => Stats_Query::named( self::GROUPED['group'], $this->db->rows( "{$slow} LIMIT ?", [ ...$binds, Performance_CI_Node::SLOWEST_ROWS ] ) ),
		];
	}

	/**
	 * The unpaged SELECT of the URLs a view and scope keep in a sort's order,
	 * and its binds.
	 *
	 * @param string $server  Server to scope to; '' is every server.
	 * @param bool   $workers Keep worker URLs; false leaves them out.
	 * @param string $sort    One of `Performance_CI_Node::URL_SORTS`' values.
	 * @param string $order   'asc' or 'desc'.
	 * @return array{0:string, 1:list<scalar>}
	 * @throws \InvalidArgumentException On a sort or an order `urls` does not take.
	 */
	private static function listing( string $server, bool $workers, string $sort, string $order ): array {
		[ $where, $binds ] = self::where( Performance_CI_Node::scope( $server, $workers ) );
		$named             = 'SELECT url, ( SELECT name FROM servers WHERE servers.id = urls.server_id ) AS server, path, ';
		return [ $named . \implode( ', ', self::GROUPED['measure'] ) . " FROM urls{$where} ORDER BY " . self::order( $sort, $order ), $binds ];
	}

	/**
	 * The ORDER BY a sort and an order name, as `Stats_Query::ranking()`
	 * ranks a sort, its ties broken by the rowid, which ranks as the hash.
	 *
	 * @param string $sort  One of `Performance_CI_Node::URL_SORTS`' values.
	 * @param string $order 'asc' or 'desc'.
	 * @throws \InvalidArgumentException On a sort or an order `urls` does not take.
	 */
	private static function order( string $sort, string $order ): string {
		if ( ! \in_array( $sort, Performance_CI_Node::URL_SORTS, true ) ) {
			throw new \InvalidArgumentException( \esc_html( "unknown URL sort {$sort}: one of " . \implode( ', ', Performance_CI_Node::URL_SORTS ) ) );
		}
		if ( 'asc' !== $order && 'desc' !== $order ) {
			throw new \InvalidArgumentException( \esc_html( "unknown order {$order}: asc or desc" ) );
		}
		$sorted = 'url_name' === $sort ? [ 'server_id', 'path' ] : [ $sort ];
		return Stats_Query::ranking( $sorted, [ 'rowid' ], $order, \in_array( $sort, self::NULLABLE, true ) );
	}

	/**
	 * The WHERE clause a scope filter names over `urls` through `SCOPED`,
	 * and its binds; a server `servers` lacks keeps no URL.
	 *
	 * @param array<string,list<scalar>> $filter A `Performance_CI_Node::scope()` filter.
	 * @return array{0:string, 1:list<scalar>}
	 * @throws \LogicException On a field `SCOPED` maps no clause for, or one
	 *                         naming other than one value, which a scope the
	 *                         snapshot ignored would leave a page unfiltered.
	 */
	private static function where( array $filter ): array {
		$clauses = [];
		$binds   = [];
		foreach ( $filter as $field => $values ) {
			$clauses[] = self::SCOPED[ $field ] ?? throw new \LogicException( \esc_html( "the URL snapshot scopes no {$field}" ) );
			if ( 1 !== \count( $values ) ) {
				throw new \LogicException( \esc_html( "the URL snapshot scopes one {$field}, not " . \count( $values ) ) );
			}
			$binds[] = $values[0];
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
			$why = $e->getMessage();
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
		$scope = '' === $server ? [ '0', [] ] : [ self::SERVER_ID, [ $server ] ];
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
