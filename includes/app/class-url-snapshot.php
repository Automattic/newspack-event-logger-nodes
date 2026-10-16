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
 * job builds it under its own name and renames it over the last, so no
 * reader sees a half-built file, and a reader opens it `immutable`.
 *
 * A worker URL is a property of the URL, so the default view keeps the rows
 * whose `worker` is 0 and Include Workers keeps every row.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Sqlite_Arm;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A published snapshot, open for reading.
 */
final class Url_Snapshot {

	/** The published file, under the base's `tables` directory. */
	public const FILE = 'flame-facts.job.sqlite';

	/** The sorts whose key may be null, which rank a null last either way. */
	private const NULLABLE = [ 'avg_ms', 'min_ms', 'max_ms' ];

	/** The columns of a `totals()` answer, as `Stats_Query` names them. */
	private const TOTALS = [ 'count', 'avg_ms', 'avg_peak_mb', 'urls', 'errors' ];

	/** What a scope with no URLs totals. */
	private const NO_TOTALS = [ 0, null, null, 0, 0 ];

	/**
	 * The `urls` indexes by name: one per sort, each ending in `url` so a
	 * backward scan serves the other order, and a second for each nullable
	 * key ranking its nulls last. `url_name`'s leads with `server`, so it
	 * also serves a server scope.
	 */
	private const INDEXES = [
		'count'        => 'count, url',
		'url_name'     => 'server, path, url',
		'avg_peak_mb'  => 'avg_peak_mb, url',
		'last_seen'    => 'last_seen, url',
		'avg_ms'       => 'avg_ms, url',
		'avg_ms_null'  => 'avg_ms IS NULL, avg_ms, url',
		'min_ms'       => 'min_ms, url',
		'min_ms_null'  => 'min_ms IS NULL, min_ms, url',
		'max_ms'       => 'max_ms, url',
		'max_ms_null'  => 'max_ms IS NULL, max_ms, url',
	];

	/** One `totals` row per view and scope: every server, then each. */
	private const TOTALS_TABLE = 'CREATE TABLE totals AS '
		. 'WITH v ( workers ) AS ( VALUES ( 0 ), ( 1 ) ), '
		. 'r AS ( SELECT v.workers, u.* FROM v JOIN urls AS u ON v.workers = 1 OR u.worker = 0 ) '
		. "SELECT workers, '' AS server, %1\$s FROM r GROUP BY workers "
		. 'UNION ALL SELECT workers, server, %1$s FROM r GROUP BY workers, server';

	/** The sums a `totals` row holds. */
	private const SUMS = 'COUNT( * ) AS urls, SUM( count ) AS count, SUM( timed ) AS timed, TOTAL( sum_ms ) AS sum_ms, '
		. 'TOTAL( sum_peak_mb ) AS sum_peak_mb, SUM( errors ) AS errors';

	/**
	 * Hold an opened snapshot.
	 *
	 * @param Row_Reader $db    The immutable connection.
	 * @param int        $as_of When the job built it.
	 */
	private function __construct( private readonly Row_Reader $db, private readonly int $as_of ) {
	}

	/**
	 * Build the snapshot of the window ending at `$now` beside the published
	 * one and rename it over it: the URLs, their totals, `$answers` by key,
	 * and an expiry `$ttl` seconds on. A fleet with no partition file yet
	 * publishes nothing, and a build that throws leaves no file.
	 *
	 * @param int                                $now     The job's tick.
	 * @param int                                $ttl     Seconds the snapshot serves.
	 * @param array<string,array<string,mixed>> $answers The stored answers by key.
	 * @throws \Newspack_Nodes\Table_Unavailable As `Row_Table_Node::build()`,
	 *                                           or when no active topology
	 *                                           declares a Flame_Facts table.
	 * @throws \RuntimeException When two do, or the build will not publish.
	 */
	public static function publish( int $now, int $ttl, array $answers ): void {
		$file = self::path();
		Bootstrap::row_table( Flame_Facts_Row_Schema::class );
		if ( ! \is_dir( \dirname( $file ) ) ) {
			// No partition has written its file, so nothing would attach.
			return;
		}
		$build = \dirname( $file ) . "/flame-facts.job.{$now}.sqlite";
		// A crashed build of the same second.
		self::remove( $build );
		try {
			$built = Row_Table_Node::build(
				Flame_Facts_Row_Schema::class,
				$build,
				static function ( Row_Reader $db ) use ( $now, $ttl, $answers ): bool {
					if ( [] === $db->aliases() ) {
						return false;
					}
					self::fill( $db, $now, $ttl, $answers );
					return true;
				}
			);
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_rename -- the publish is one atomic rename inside this plugin's own tables directory.
			if ( $built && ! \rename( $build, $file ) ) {
				throw new \RuntimeException( \esc_html( "overview prefetch: {$build} would not publish" ) );
			}
		} finally {
			// Journaling is off, so the build file is all a build leaves.
			self::remove( $build );
		}
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
				'group'   => [ 'url' ],
				'filter'  => [],
				'measure' => Performance_CI_Node::URL_MEASURES,
				'buckets' => Bucket::last( Flame_Facts_Row_Schema::WINDOW_BUCKETS, $now ),
			]
		);
		foreach ( self::INDEXES as $name => $columns ) {
			$db->rows( "CREATE INDEX urls_{$name} ON urls ( {$columns} )", [] );
		}
		$db->rows( \sprintf( self::TOTALS_TABLE, self::SUMS ), [] );
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
	 * Delete `$file` when it is there.
	 *
	 * @param string $file A build file.
	 */
	private static function remove( string $file ): void {
		if ( \file_exists( $file ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- a build file this job wrote.
			\unlink( $file );
		}
	}

	/**
	 * The published snapshot, when one stands that has not expired at `$now`.
	 * A file that will not open says so once a window.
	 *
	 * @param int $now The reply's clock.
	 */
	public static function open( int $now ): ?self {
		$file = self::path();
		\clearstatcache( true, $file );
		if ( ! \file_exists( $file ) ) {
			return null;
		}
		try {
			$db   = Sqlite_Arm::open_snapshot( $file );
			$meta = $db->rows( 'SELECT as_of, expires FROM meta', [] )[0] ?? throw new \LogicException( 'it holds no meta row' );
		} catch ( \PDOException | \LogicException $e ) {
			Core::print_less_often( 'performance: the URL snapshot did not open', ' — ' . $e->getMessage() );
			return null;
		}
		return Core::as_int( $meta[1] ) <= $now ? null : new self( $db, Core::as_int( $meta[0] ) );
	}

	/** The published file: `{base}/tables/flame-facts.job.sqlite`. */
	public static function path(): string {
		return Bootstrap::base_dir() . '/tables/' . self::FILE;
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
			'columns'   => [ 'url', 'url_name', ...Performance_CI_Node::URL_MEASURES ],
			'rows'      => self::named( $this->db->rows( "{$page} LIMIT ? OFFSET ?", [ ...$binds, $limit, $offset ] ) ),
			'available' => true,
			'also'      => self::named( $this->db->rows( "{$slow} LIMIT ?", [ ...$binds, Performance_CI_Node::SLOWEST_ROWS ] ) ),
		];
	}

	/**
	 * Rows of `url, server, path, ...measures` as a `url` group answers
	 * them: the hash, its server and path joined into `url_name`, then the
	 * measures.
	 *
	 * @param list<list<mixed>> $rows The rows read.
	 * @return list<list<mixed>>
	 */
	private static function named( array $rows ): array {
		return \array_map(
			static fn ( array $row ): array => [ $row[0], Url_Parts::join_url( Core::as_string( $row[1] ), Core::as_string( $row[2] ) ), ...\array_slice( $row, 3 ) ],
			$rows
		);
	}

	/**
	 * The unpaged SELECT of the URLs a view and scope keep in a sort's order,
	 * and its binds.
	 *
	 * @param string $server  Server to scope to; '' is every server.
	 * @param bool   $workers Keep worker URLs; false leaves them out.
	 * @param string $sort    One of `Performance_CI_Node::URL_SORTS`' values.
	 * @param string $order   'asc' or 'desc'.
	 * @return array{0:string, 1:list<string>}
	 * @throws \InvalidArgumentException On a sort or an order `urls` does not take.
	 */
	private static function listing( string $server, bool $workers, string $sort, string $order ): array {
		[ $where, $binds ] = self::scope( $server, $workers );
		return [ 'SELECT url, server, path, ' . \implode( ', ', Performance_CI_Node::URL_MEASURES ) . " FROM urls{$where} ORDER BY " . self::order( $sort, $order ), $binds ];
	}

	/**
	 * The ORDER BY a sort and an order name, its ties broken by the hash in
	 * the same direction and a nullable key's nulls last either way.
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
		$direction = \strtoupper( $order );
		if ( 'url_name' === $sort ) {
			return "server {$direction}, path {$direction}, url {$direction}";
		}
		if ( 'asc' === $order && \in_array( $sort, self::NULLABLE, true ) ) {
			return "{$sort} IS NULL, {$sort}, url";
		}
		return "{$sort} {$direction}, url {$direction}";
	}

	/**
	 * The WHERE clause a view and scope name, and its binds.
	 *
	 * @param string $server Server to scope to; '' is every server.
	 * @param bool   $workers Keep worker URLs.
	 * @return array{0:string, 1:list<string>}
	 */
	private static function scope( string $server, bool $workers ): array {
		$clauses = $workers ? [] : [ 'worker = 0' ];
		$binds   = [];
		if ( '' !== $server ) {
			$clauses[] = 'server = ?';
			$binds[]   = $server;
		}
		return [ [] === $clauses ? '' : ' WHERE ' . \implode( ' AND ', $clauses ), $binds ];
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
	 * `count`, `avg_ms`, `avg_peak_mb`, `urls` and `errors` with no group:
	 * one row, zeroes and null means where the scope holds no URL.
	 *
	 * @param string $server  Server to scope to; '' is every server.
	 * @param bool   $workers Keep worker URLs; false leaves them out.
	 * @return array{columns:list<string>, rows:list<list<mixed>>, available:true}
	 */
	public function totals( string $server, bool $workers ): array {
		$rows = $this->db->rows(
			'SELECT count, sum_ms / NULLIF( timed, 0 ), sum_peak_mb / NULLIF( count, 0 ), urls, errors FROM totals WHERE workers = ? AND server = ?',
			[ $workers ? 1 : 0, $server ]
		);
		return [
			'columns'   => self::TOTALS,
			'rows'      => [ $rows[0] ?? self::NO_TOTALS ],
			'available' => true,
		];
	}
}
