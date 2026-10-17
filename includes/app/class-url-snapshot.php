<?php
/**
 * Url_Snapshot: the URL table of the window's buckets, built whole by the
 * overview-prefetch job and read by the verbs until it expires.
 *
 * One SQLite file, `{base}/tables/flame-facts.job.sqlite`, holds a `urls`
 * table of one row per URL, `Stats_Query`'s `url` group over every
 * partition with `Performance_CI_Node::URL_MEASURES`, indexed once per
 * sort the `urls` verb takes; a `totals` table of one row per view and
 * scope; the stored answers by key; and when it was built and expires. The
 * substrate publishes it whole under `NAME` and opens it `immutable`
 * (ADR-35), so no reader sees a half-built file.
 *
 * A worker URL is a property of the URL, so the default view keeps the rows
 * whose `worker` is 0 and Include Workers keeps every row.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
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

	/**
	 * One `totals` row per view and scope, every server then each: the URLs'
	 * partials `%1$s` summed once by worker and server, then folded by the
	 * totals `%2$s`, every one a sum, so the sums compose.
	 */
	private const TOTALS_TABLE = 'CREATE TABLE totals AS '
		. 'WITH g AS ( SELECT u.worker, u.server, %1$s, COUNT( * ) AS q_urls FROM urls AS u GROUP BY u.worker, u.server ), '
		. 'v ( workers ) AS ( VALUES ( 0 ), ( 1 ) ), '
		. 'r AS ( SELECT v.workers, g.* FROM v JOIN g ON v.workers = 1 OR g.worker = 0 ) '
		. "SELECT workers, '' AS server, %2\$s FROM r GROUP BY workers "
		. 'UNION ALL SELECT workers, server, %2$s FROM r GROUP BY workers, server';

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
	 * `Row_Table_Node::build()`: the URLs, their totals, `$answers` by key,
	 * and an expiry `$ttl` seconds on. A fleet with no partition file yet
	 * publishes nothing, and a build that throws leaves the last publish.
	 *
	 * @param int                                $now     The job's tick.
	 * @param int                                $ttl     Seconds the snapshot serves.
	 * @param array<string,array<string,mixed>> $answers The stored answers by key.
	 * @throws \Newspack_Nodes\Table_Unavailable As `Row_Table_Node::build()`,
	 *                                           or when no active topology
	 *                                           declares a Flame_Facts table.
	 * @throws \RuntimeException When two do, the build will not publish, or
	 *                           an answer will not encode.
	 */
	public static function publish( int $now, int $ttl, array $answers ): void {
		Row_Table_Node::build(
			Flame_Facts_Row_Schema::class,
			self::NAME,
			static function ( Row_Reader $db ) use ( $now, $ttl, $answers ): bool {
				self::fill( $db, $now, $ttl, $answers );
				return true;
			}
		);
	}

	/**
	 * Write the snapshot's tables into the file `$db` builds.
	 *
	 * @param Row_Reader                         $db      The building connection.
	 * @param int                                $now     The job's tick.
	 * @param int                                $ttl     Seconds the snapshot serves.
	 * @param array<string,array<string,mixed>> $answers The stored answers by key.
	 * @throws \RuntimeException When an answer will not encode.
	 */
	private static function fill( Row_Reader $db, int $now, int $ttl, array $answers ): void {
		Stats_Query::into(
			$db,
			'urls',
			[
				'source'  => 'requests',
				'filter'  => [],
				'buckets' => Bucket::last( Flame_Facts_Row_Schema::WINDOW_BUCKETS, $now ),
			] + self::GROUPED
		);
		foreach ( self::indexes() as $name => $columns ) {
			$db->rows( "CREATE INDEX urls_{$name} ON urls ( {$columns} )", [] );
		}
		$db->rows( self::totals_table(), [] );
		$db->rows( 'CREATE UNIQUE INDEX totals_scope ON totals ( workers, server )', [] );
		$db->rows( 'CREATE TABLE answers ( "key" TEXT PRIMARY KEY, value TEXT NOT NULL )', [] );
		foreach ( $answers as $key => $answer ) {
			$json = \wp_json_encode( $answer );
			if ( false === $json ) {
				throw new \RuntimeException( \esc_html( "overview prefetch: answer {$key} will not encode" ) );
			}
			$db->rows( 'INSERT INTO answers ( "key", value ) VALUES ( ?, ? )', [ $key, $json ] );
		}
		$db->rows( 'CREATE TABLE meta ( as_of INTEGER NOT NULL, expires INTEGER NOT NULL )', [] );
		$db->rows( 'INSERT INTO meta ( as_of, expires ) VALUES ( ?, ? )', [ $now, $now + $ttl ] );
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
	 * The `urls` indexes by name: one per sort, each ending in `url` so a
	 * backward scan serves the other order, and a second for each nullable
	 * key ranking its nulls last. `url_name`'s leads with `server`, so it
	 * also serves a server scope.
	 *
	 * @return array<string,string>
	 */
	private static function indexes(): array {
		$indexes = [ 'url_name' => 'server, path, url' ];
		foreach ( \array_diff( Performance_CI_Node::URL_SORTS, [ 'url_name' ] ) as $sort ) {
			$indexes[ $sort ] = "{$sort}, url";
			if ( \in_array( $sort, self::NULLABLE, true ) ) {
				$indexes[ "{$sort}_null" ] = "{$sort} IS NULL, {$sort}, url";
			}
		}
		return $indexes;
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
		return [ 'SELECT url, server, path, ' . \implode( ', ', self::GROUPED['measure'] ) . " FROM urls{$where} ORDER BY " . self::order( $sort, $order ), $binds ];
	}

	/**
	 * The ORDER BY a sort and an order name, as `Stats_Query::ranking()`
	 * ranks a sort, its ties broken by the hash.
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
		$sorted = 'url_name' === $sort ? [ 'server', 'path' ] : [ $sort ];
		return Stats_Query::ranking( $sorted, [ 'url' ], $order, \in_array( $sort, self::NULLABLE, true ) );
	}

	/**
	 * The WHERE clause a scope filter names over the `urls` columns of its
	 * fields, each field matching any of its values, and its binds.
	 *
	 * @param array<string,list<scalar>> $filter A `Performance_CI_Node::scope()` filter.
	 * @return array{0:string, 1:list<scalar>}
	 */
	private static function where( array $filter ): array {
		$clauses = [];
		$binds   = [];
		foreach ( $filter as $field => $values ) {
			$clauses[] = "{$field} IN ( " . \implode( ', ', \array_fill( 0, \count( $values ), '?' ) ) . ' )';
			\array_push( $binds, ...$values );
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
		$rows = $this->db->rows(
			'SELECT ' . \implode( ', ', Performance_CI_Node::URL_TOTALS ) . ' FROM totals WHERE workers = ? AND server = ?',
			[ $workers ? 1 : 0, $server ]
		);
		return [
			'columns'   => Performance_CI_Node::URL_TOTALS,
			'rows'      => [ $rows[0] ?? self::NO_TOTALS ],
			'available' => true,
		];
	}
}
