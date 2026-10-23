<?php
/**
 * Flame Facts Row Schema
 *
 * The row tables the stats builder writes and every performance reader
 * queries: one `requests` row per completed request, its per-category
 * `timings` per URL and per site, and its `requests_hourly` rollup. Every
 * string a row repeats, its URL, server, agent, JA4, From, category and
 * entry, is the id of a row in `urls` or a lookup table, each released once
 * no row names it. A topology declares them as `make_node Row_Table <name>
 * Flame_Facts`, and a reader finds that name through
 * `Bootstrap::row_table()`.
 *
 * `category_window` holds each category's and entry's sums over the last
 * `Bucket::window()`, and `window_expiry` one row per bucket expired, so a
 * replayed expiry subtracts nothing twice; its newest bucket is the
 * window's watermark.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Buckets are `Bucket::id()`s and hours `Bucket::hour()`s. A key column
 * holds no NULL, so `requests_hourly` files an absent status class as 0 and
 * an absent country as '', and readers spell both `Unknown`.
 *
 * @phpstan-type Row_Spec array{
 *   columns: array<string,string>,
 *   key?: list<string>,
 *   add?: array<string,'sum'|'max'>,
 *   indexes?: array<string,list<string>>,
 *   retain?: array{0:string,1:int,2:int},
 *   retained_by?: list<array{0:string,1:string,2:string}>,
 *   autoincrement?: string,
 *   fts?: list<string>
 * }
 */
final class Flame_Facts_Row_Schema implements \Newspack_Nodes\Row_Schema {

	/** The window's watermark, the newest bucket `window_expiry` holds; `%s` is the file's alias. */
	public const WATERMARK_QUERY = 'SELECT MAX( bucket ) FROM %s.window_expiry';

	/**
	 * Each column naming a row of another table by that row's `id`, and the
	 * table it names: the one map each named table's `retained_by`, the
	 * index leading each such column, the builder's lookup ops and the test
	 * seeder all read.
	 */
	public const NAMED_BY = [
		'url_id'      => 'urls',
		'server_id'   => 'servers',
		'ua_id'       => 'agents',
		'ja4_id'      => 'ja4s',
		'from_id'     => 'froms',
		'category_id' => 'categories',
		'entry_id'    => 'entries',
		'owner_id'    => 'owners',
	];

	/** A lookup table: each name the rows repeat, filed once under its id. */
	private const LOOKUP = [
		'columns'       => [
			'id'   => 'INTEGER',
			'name' => 'TEXT NOT NULL',
		],
		'key'           => [ 'name' ],
		'autoincrement' => 'id',
	];

	/** @var array<string,array<string,int>> Each table's column positions, read once. */
	private static array $positions = [];

	/**
	 * Where a column sits in its table's rows: the index an op's `ids` map
	 * fills, read off the declaration so no caller counts columns.
	 *
	 * @param string $table  A declared table.
	 * @param string $column One of its columns.
	 * @throws \LogicException When the table declares no such column.
	 */
	public static function position( string $table, string $column ): int {
		$positions = self::$positions[ $table ] ??= \array_flip( \array_keys( self::tables()[ $table ]['columns'] ?? [] ) );
		return $positions[ $column ] ?? throw new \LogicException( \esc_html( "Flame_Facts: {$table} declares no column {$column}" ) );
	}

	/**
	 * The sixteen row tables, each column in row order, each table a
	 * `NAMED_BY` column names `retained_by` that column, and that column
	 * leading an index wherever its table's key or another index does not.
	 * Each stats table keeps `Bucket::window()` and `Bucket::RETAIN_SLACK`
	 * more, read from `min_lifetime` on every call.
	 *
	 * @api Read by the Row_Table that opens the file.
	 * @return array<string,Row_Spec> Each table's declaration, by table name.
	 * @throws \RuntimeException When `min_lifetime` is short of one bucket.
	 */
	public static function tables(): array {
		return self::referenced( self::declared( Bucket::window() + Bucket::RETAIN_SLACK ) );
	}

	/**
	 * The tables as declared, before `referenced()` adds what `NAMED_BY`
	 * implies.
	 *
	 * @param int $kept Buckets each stats table keeps; `requests_hourly` keeps the hours covering them.
	 * @return array<string,Row_Spec>
	 */
	private static function declared( int $kept ): array {
		$hours = (int) \ceil( $kept / ( Bucket::HOUR_SECONDS / Bucket::SECONDS ) );
		return [
			'urls'            => [
				'columns'       => [
					'id'        => 'INTEGER',
					'hash'      => 'TEXT NOT NULL',
					'server_id' => 'INTEGER NOT NULL',
					'path'      => 'TEXT NOT NULL',
					'page'      => 'TEXT NOT NULL',
					'page_hash' => 'TEXT NOT NULL',
				],
				'key'           => [ 'hash' ],
				'indexes'       => [ 'urls_page' => [ 'page_hash', 'server_id' ] ],
				'autoincrement' => 'id',
				'fts'           => [ 'path' ],
			],
			'servers'         => self::LOOKUP,
			'agents'          => self::LOOKUP,
			'ja4s'            => self::LOOKUP,
			'froms'           => self::LOOKUP,
			'categories'      => self::LOOKUP,
			'entries'         => self::LOOKUP,
			'owners'          => self::LOOKUP,
			'requests'        => [
				'columns' => [
					'rid'         => 'TEXT NOT NULL',
					'bucket'      => 'INTEGER NOT NULL',
					'finished_at' => 'REAL NOT NULL',
					'started_at'  => 'INTEGER NOT NULL',
					'partition'   => 'INTEGER NOT NULL',
					'segment'     => 'INTEGER',
					'offset'      => 'INTEGER',
					'length'      => 'INTEGER',
					'server_id'   => 'INTEGER NOT NULL',
					'url_id'      => 'INTEGER NOT NULL',
					'status'      => 'INTEGER',
					'method'      => 'TEXT NOT NULL',
					'country'     => 'TEXT',
					'ua_id'       => 'INTEGER',
					'ja4_id'      => 'INTEGER',
					'from_id'     => 'INTEGER',
					'worker'      => 'INTEGER NOT NULL',
					'profiled'    => 'INTEGER NOT NULL',
					'error'       => 'TEXT',
					'ms'          => 'REAL',
					'peak_mb'     => 'REAL NOT NULL',
				],
				'key'     => [ 'rid' ],
				'indexes' => [
					'requests_url'    => [ 'url_id', 'bucket' ],
					'requests_cover'  => [ 'bucket', 'worker', 'url_id', 'ms', 'status', 'peak_mb', 'profiled', 'error', 'finished_at' ],
					'requests_server' => [ 'server_id', 'bucket', 'worker', 'url_id', 'ms', 'status', 'peak_mb', 'profiled', 'error' ],
				],
				'retain'  => [ 'bucket', $kept, Bucket::SECONDS ],
			],
			'timings'         => [
				'columns' => [
					'bucket'      => 'INTEGER NOT NULL',
					'server_id'   => 'INTEGER NOT NULL',
					'url_id'      => 'INTEGER NOT NULL',
					'category_id' => 'INTEGER NOT NULL',
					'entry_id'    => 'INTEGER NOT NULL',
					'listener'    => 'INTEGER NOT NULL',
					'ms'          => 'REAL NOT NULL',
					'calls'       => 'INTEGER NOT NULL',
					'requests'    => 'INTEGER NOT NULL',
				],
				'key'     => [ 'bucket', 'server_id', 'url_id', 'category_id', 'entry_id' ],
				'add'     => [
					'ms'       => 'sum',
					'calls'    => 'sum',
					'requests' => 'sum',
				],
				'indexes' => [ 'timings_url' => [ 'url_id', 'bucket' ] ],
				'retain'  => [ 'bucket', $kept, Bucket::SECONDS ],
			],
			'timings_site'    => [
				'columns' => [
					'bucket'      => 'INTEGER NOT NULL',
					'server_id'   => 'INTEGER NOT NULL',
					'worker'      => 'INTEGER NOT NULL',
					'category_id' => 'INTEGER NOT NULL',
					'entry_id'    => 'INTEGER NOT NULL',
					'listener'    => 'INTEGER NOT NULL',
					'ms'          => 'REAL NOT NULL',
					'calls'       => 'INTEGER NOT NULL',
					'requests'    => 'INTEGER NOT NULL',
				],
				'key'     => [ 'server_id', 'bucket', 'worker', 'category_id', 'entry_id' ],
				'add'     => [
					'ms'       => 'sum',
					'calls'    => 'sum',
					'requests' => 'sum',
				],
				'indexes' => [ 'timings_site_bucket' => [ 'bucket' ] ],
				'retain'  => [ 'bucket', $kept, Bucket::SECONDS ],
			],
			'owner_timings'  => [
				'columns' => [
					'bucket'    => 'INTEGER NOT NULL',
					'server_id' => 'INTEGER NOT NULL',
					'url_id'    => 'INTEGER NOT NULL',
					'owner_id'  => 'INTEGER NOT NULL',
					'ms'        => 'REAL NOT NULL',
					'calls'     => 'INTEGER NOT NULL',
					'requests'  => 'INTEGER NOT NULL',
				],
				'key'     => [ 'bucket', 'server_id', 'url_id', 'owner_id' ],
				'add'     => [
					'ms'       => 'sum',
					'calls'    => 'sum',
					'requests' => 'sum',
				],
				'indexes' => [ 'owner_timings_url' => [ 'url_id', 'bucket' ] ],
				'retain'  => [ 'bucket', $kept, Bucket::SECONDS ],
			],
			'owner_timings_site' => [
				'columns' => [
					'bucket'    => 'INTEGER NOT NULL',
					'server_id' => 'INTEGER NOT NULL',
					'worker'    => 'INTEGER NOT NULL',
					'owner_id'  => 'INTEGER NOT NULL',
					'ms'        => 'REAL NOT NULL',
					'calls'     => 'INTEGER NOT NULL',
					'requests'  => 'INTEGER NOT NULL',
				],
				'key'     => [ 'server_id', 'bucket', 'worker', 'owner_id' ],
				'add'     => [
					'ms'       => 'sum',
					'calls'    => 'sum',
					'requests' => 'sum',
				],
				'indexes' => [ 'owner_timings_site_bucket' => [ 'bucket' ] ],
				'retain'  => [ 'bucket', $kept, Bucket::SECONDS ],
			],
			'category_window' => [
				'columns' => [
					'category_id' => 'INTEGER NOT NULL',
					'entry_id'    => 'INTEGER NOT NULL',
					'listener'    => 'INTEGER NOT NULL',
					'worker'      => 'INTEGER NOT NULL',
					'ms'          => 'REAL NOT NULL',
					'calls'       => 'INTEGER NOT NULL',
					'requests'    => 'INTEGER NOT NULL',
					'last_bucket' => 'INTEGER NOT NULL',
				],
				'key'     => [ 'category_id', 'entry_id', 'worker' ],
				'add'     => [
					'ms'          => 'sum',
					'calls'       => 'sum',
					'requests'    => 'sum',
					'last_bucket' => 'max',
				],
				'indexes' => [ 'category_window_last' => [ 'last_bucket' ] ],
				'retain'  => [ 'last_bucket', $kept, Bucket::SECONDS ],
			],
			'window_expiry'   => [
				'columns' => [ 'bucket' => 'INTEGER NOT NULL' ],
				'key'     => [ 'bucket' ],
				'retain'  => [ 'bucket', $kept, Bucket::SECONDS ],
			],
			'requests_hourly' => [
				'columns' => [
					'hour'         => 'INTEGER NOT NULL',
					'server_id'    => 'INTEGER NOT NULL',
					'status_class' => 'INTEGER NOT NULL',
					'method'       => 'TEXT NOT NULL',
					'worker'       => 'INTEGER NOT NULL',
					'country'      => 'TEXT NOT NULL',
					'count'        => 'INTEGER NOT NULL',
					'timed'        => 'INTEGER NOT NULL',
					'sum_ms'       => 'REAL NOT NULL',
					'max_ms'       => 'REAL NOT NULL',
					'sum_peak_mb'  => 'REAL NOT NULL',
					'errors'       => 'INTEGER NOT NULL',
				],
				'key'     => [ 'hour', 'server_id', 'status_class', 'method', 'worker', 'country' ],
				'add'     => [
					'count'       => 'sum',
					'timed'       => 'sum',
					'sum_ms'      => 'sum',
					'max_ms'      => 'max',
					'sum_peak_mb' => 'sum',
					'errors'      => 'sum',
				],
				'retain'  => [ 'hour', $hours, Bucket::HOUR_SECONDS ],
			],
		];
	}

	/**
	 * `$tables` with every reference `NAMED_BY` reads in a table's columns
	 * declared on both sides: its column leading an index, and the named
	 * table `retained_by` it.
	 *
	 * @param array<string,Row_Spec> $tables The declared tables.
	 * @return array<string,Row_Spec>
	 * @throws \LogicException When a column names a table declared nowhere.
	 */
	private static function referenced( array $tables ): array {
		$referenced = [];
		foreach ( $tables as $table => $spec ) {
			$leading = [ $spec['key'][0] ?? null, ...\array_column( $spec['indexes'] ?? [], 0 ) ];
			foreach ( \array_diff( \array_keys( \array_intersect_key( self::NAMED_BY, $spec['columns'] ) ), $leading ) as $column ) {
				$spec['indexes'][ "{$table}_{$column}" ] = [ $column ];
			}
			$referenced[ $table ] = $spec;
		}
		foreach ( $tables as $table => $spec ) {
			foreach ( \array_intersect_key( self::NAMED_BY, $spec['columns'] ) as $column => $named ) {
				$names                  = $referenced[ $named ] ?? throw new \LogicException( \esc_html( "Flame_Facts: {$table}.{$column} names {$named}, which is declared nowhere" ) );
				$names['retained_by'][] = [ $table, $column, 'id' ];
				$referenced[ $named ]   = $names;
			}
		}
		return $referenced;
	}

	/**
	 * The declared shape's version.
	 *
	 * @api Read by the Row_Table that opens the file.
	 */
	public static function version(): int {
		return 9;
	}
}
