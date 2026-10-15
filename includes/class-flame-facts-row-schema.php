<?php
/**
 * Flame Facts Row Schema
 *
 * The row tables the flame builder writes and every performance reader
 * queries: one `requests` row per completed request, the `urls` and
 * `agents` it names by id, its per-category `timings` per URL and per site,
 * and its `requests_hourly` rollup. `flame-builder.tsl` declares them as
 * `make_node Row_Table flame-facts Flame_Facts`.
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
 */
final class Flame_Facts_Row_Schema implements \Newspack_Nodes\Row_Schema {

	/** The Row_Table the topologies declare with this schema. */
	public const TABLE = 'flame-facts';

	/** Five-minute buckets a `requests` row lives: seven days. */
	public const REQUESTS_BUCKETS = 2016;

	/** Five-minute buckets a timing row lives: 25 hours. */
	public const TIMINGS_BUCKETS = 300;

	/** Hours a `requests_hourly` row lives: seven days. */
	public const HOURLY_HOURS = 168;

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
	 * The six row tables, each column in row order.
	 *
	 * @api Read by the Row_Table that opens the file.
	 * @return array<string, array{
	 *   columns: array<string,string>,
	 *   key?: list<string>,
	 *   add?: array<string,'sum'|'max'>,
	 *   indexes?: array<string,list<string>>,
	 *   retain?: array{0:string,1:int,2:int},
	 *   retained_by?: array{0:string,1:string,2:string},
	 *   autoincrement?: string,
	 *   fts?: list<string>
	 * }> Each table's declaration, by table name.
	 */
	public static function tables(): array {
		return [
			'urls'            => [
				'columns'       => [
					'id'     => 'INTEGER',
					'hash'   => 'TEXT NOT NULL',
					'server' => 'TEXT NOT NULL',
					'path'   => 'TEXT NOT NULL',
				],
				'key'           => [ 'hash' ],
				'autoincrement' => 'id',
				'retained_by'   => [ 'requests', 'url_id', 'id' ],
				'fts'           => [ 'path' ],
			],
			'agents'          => [
				'columns'       => [
					'id' => 'INTEGER',
					'ua' => 'TEXT NOT NULL',
				],
				'key'           => [ 'ua' ],
				'autoincrement' => 'id',
				'retained_by'   => [ 'requests', 'ua_id', 'id' ],
			],
			'requests'        => [
				'columns' => [
					'rid'         => 'TEXT NOT NULL',
					'bucket'      => 'INTEGER NOT NULL',
					'finished_at' => 'REAL NOT NULL',
					'partition'   => 'INTEGER NOT NULL',
					'segment'     => 'INTEGER',
					'offset'      => 'INTEGER',
					'length'      => 'INTEGER',
					'server'      => 'TEXT NOT NULL',
					'url_id'      => 'INTEGER NOT NULL',
					'status'      => 'INTEGER',
					'method'      => 'TEXT NOT NULL',
					'country'     => 'TEXT',
					'ua_id'       => 'INTEGER',
					'ja4'         => 'TEXT',
					'from_header' => 'TEXT',
					'worker'      => 'INTEGER NOT NULL',
					'error'       => 'TEXT',
					'ms'          => 'REAL',
					'peak_mb'     => 'REAL NOT NULL',
				],
				'key'     => [ 'rid' ],
				'indexes' => [
					'requests_bucket' => [ 'bucket' ],
					'requests_url'    => [ 'url_id', 'bucket' ],
					'requests_ua'     => [ 'ua_id' ],
				],
				'retain'  => [ 'bucket', self::REQUESTS_BUCKETS, Bucket::SECONDS ],
			],
			'timings'         => [
				'columns' => [
					'bucket'   => 'INTEGER NOT NULL',
					'server'   => 'TEXT NOT NULL',
					'url_id'   => 'INTEGER NOT NULL',
					'category' => 'TEXT NOT NULL',
					'entry'    => 'TEXT NOT NULL',
					'listener' => 'INTEGER NOT NULL',
					'ms'       => 'REAL NOT NULL',
					'calls'    => 'INTEGER NOT NULL',
					'requests' => 'INTEGER NOT NULL',
				],
				'key'     => [ 'bucket', 'server', 'url_id', 'category', 'entry' ],
				'add'     => [
					'ms'       => 'sum',
					'calls'    => 'sum',
					'requests' => 'sum',
				],
				'indexes' => [ 'timings_url' => [ 'url_id', 'bucket' ] ],
				'retain'  => [ 'bucket', self::TIMINGS_BUCKETS, Bucket::SECONDS ],
			],
			'timings_site'    => [
				'columns' => [
					'bucket'   => 'INTEGER NOT NULL',
					'server'   => 'TEXT NOT NULL',
					'worker'   => 'INTEGER NOT NULL',
					'category' => 'TEXT NOT NULL',
					'entry'    => 'TEXT NOT NULL',
					'listener' => 'INTEGER NOT NULL',
					'ms'       => 'REAL NOT NULL',
					'calls'    => 'INTEGER NOT NULL',
					'requests' => 'INTEGER NOT NULL',
				],
				'key'     => [ 'bucket', 'server', 'worker', 'category', 'entry' ],
				'add'     => [
					'ms'       => 'sum',
					'calls'    => 'sum',
					'requests' => 'sum',
				],
				'retain'  => [ 'bucket', self::TIMINGS_BUCKETS, Bucket::SECONDS ],
			],
			'requests_hourly' => [
				'columns' => [
					'hour'         => 'INTEGER NOT NULL',
					'server'       => 'TEXT NOT NULL',
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
				'key'     => [ 'hour', 'server', 'status_class', 'method', 'worker', 'country' ],
				'add'     => [
					'count'       => 'sum',
					'timed'       => 'sum',
					'sum_ms'      => 'sum',
					'max_ms'      => 'max',
					'sum_peak_mb' => 'sum',
					'errors'      => 'sum',
				],
				'retain'  => [ 'hour', self::HOURLY_HOURS, Bucket::HOUR_SECONDS ],
			],
		];
	}

	/**
	 * The declared shape's version.
	 *
	 * @api Read by the Row_Table that opens the file.
	 */
	public static function version(): int {
		return 1;
	}
}
