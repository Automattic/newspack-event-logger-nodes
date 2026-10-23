<?php
/**
 * FactsSeeder: rows in the Row_Table the active topologies declare with the
 * Flame_Facts schema, written as the stats builder writes them.
 *
 * Each seeder sends one `ROWS` batch through a real `Row_Table` bound to the
 * partition it names, so a seed passes the same write contract a flush does:
 * a row names its URL, server, agent, JA4, From, category and entry as text,
 * each under its `Flame_Facts_Row_Schema::NAMED_BY` column less its `_id`,
 * and the seeder files each through its lookup's `ignore`, whose id the row
 * takes by batch index.
 * Every column a test leaves out takes the seeder's default; a key naming no
 * column fails the seed.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Helpers;

use Newspack_Event_Logger_Nodes\App\Url_Snapshot;
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Stats_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;

trait FactsSeeder {

	/** The server a seed files under when it names none. */
	protected static string $seed_server = 'seed.example';

	/** @var int Rows seeded so far, for each seed's own rid. */
	private int $seeded_requests = 0;

	/**
	 * One `requests` row, with its URL and, when it names one, its agent.
	 *
	 * @param array<string,mixed> $over      Columns by name, plus `url` (a
	 *                                       logged URL, its host the server's
	 *                                       by default) and the names it files.
	 * @param int                 $partition The partition file it lands in.
	 */
	protected function seed_request( array $over = [], int $partition = 0 ): void {
		$this->seed_requests( [ $over ], $partition );
	}

	/**
	 * Many `requests` rows in one batch, each as `seed_request()` takes it.
	 *
	 * @param list<array<string,mixed>> $rows      Each row's columns by name.
	 * @param int                       $partition The partition file they land in.
	 */
	protected function seed_requests( array $rows, int $partition = 0 ): void {
		$now  = (int) Core::$now;
		$seed = [];
		foreach ( $rows as $over ) {
			$server = Core::as_string( $over['server'] ?? self::$seed_server );
			$bucket = Core::as_int( $over['bucket'] ?? Bucket::id( $now ) );
			$seed[] = [
				'ignore',
				'requests',
				$over + [
					'url'         => "https://{$server}/seed-path/",
					'rid'         => 'rid-seed-' . ( ++$this->seeded_requests ),
					'bucket'      => $bucket,
					'finished_at' => (float) ( $bucket * Bucket::SECONDS ),
					'started_at'  => $bucket * Bucket::SECONDS - 2,
					'partition'   => $partition,
					'segment'     => null,
					'offset'      => null,
					'length'      => null,
					'server'      => $server,
					'status'      => 200,
					'method'      => 'GET',
					'country'     => null,
					'ua'          => null,
					'ja4'         => null,
					'from'        => null,
					'worker'      => 0,
					'profiled'    => 0,
					'error'       => null,
					'ms'          => 100.0,
					'peak_mb'     => 24.0,
				],
			];
		}
		$this->seed_rows( $partition, $seed );
	}

	/**
	 * One `timings` row: a URL's category or entry over one bucket.
	 *
	 * @param array<string,mixed> $over      Columns by name, plus `url`.
	 * @param int                 $partition The partition file it lands in.
	 */
	protected function seed_timing( array $over = [], int $partition = 0 ): void {
		$server = Core::as_string( $over['server'] ?? self::$seed_server );
		$this->seed_rows( $partition, [ [ 'add', 'timings', $over + [ 'url' => "https://{$server}/seed-path/" ] + self::timing_defaults( $server ) ] ] );
	}

	/**
	 * One `timings_site` row: a category or entry over one bucket, no URL.
	 *
	 * @param array<string,mixed> $over      Columns by name.
	 * @param int                 $partition The partition file it lands in.
	 */
	protected function seed_site_timing( array $over = [], int $partition = 0 ): void {
		$this->seed_site_timings( [ $over ], $partition );
	}

	/**
	 * Many `timings_site` rows in one batch.
	 *
	 * @param list<array<string,mixed>> $rows      Each row's columns by name.
	 * @param int                       $partition The partition file they land in.
	 */
	protected function seed_site_timings( array $rows, int $partition = 0 ): void {
		$seed = [];
		foreach ( $rows as $over ) {
			$seed[] = [ 'add', 'timings_site', $over + self::timing_defaults( Core::as_string( $over['server'] ?? self::$seed_server ) ) + [ 'worker' => 0 ] ];
		}
		$this->seed_rows( $partition, $seed );
	}

	/**
	 * One `owner_timings` row: an owner's time on a URL over one bucket.
	 *
	 * @param array<string,mixed> $over      Columns by name, plus `url` and `owner`.
	 * @param int                 $partition The partition file it lands in.
	 */
	protected function seed_owner_timing( array $over = [], int $partition = 0 ): void {
		$server = Core::as_string( $over['server'] ?? self::$seed_server );
		$this->seed_rows( $partition, [ [ 'add', 'owner_timings', $over + [ 'url' => "https://{$server}/seed-path/" ] + self::owner_defaults( $server ) ] ] );
	}

	/**
	 * One `owner_timings_site` row: an owner's time over one bucket, no URL.
	 *
	 * @param array<string,mixed> $over      Columns by name, plus `owner`.
	 * @param int                 $partition The partition file it lands in.
	 */
	protected function seed_owner_site_timing( array $over = [], int $partition = 0 ): void {
		$server = Core::as_string( $over['server'] ?? self::$seed_server );
		$this->seed_rows( $partition, [ [ 'add', 'owner_timings_site', $over + self::owner_defaults( $server ) + [ 'worker' => 0 ] ] ] );
	}

	/**
	 * Many `owner_timings_site` rows in one write.
	 *
	 * @param list<array<string,mixed>> $rows      Each row's columns by name, plus `owner`.
	 * @param int                       $partition The partition file they land in.
	 */
	protected function seed_owner_site_timings( array $rows, int $partition = 0 ): void {
		$seed = [];
		foreach ( $rows as $over ) {
			$seed[] = [ 'add', 'owner_timings_site', $over + self::owner_defaults( Core::as_string( $over['server'] ?? self::$seed_server ) ) + [ 'worker' => 0 ] ];
		}
		$this->seed_rows( $partition, $seed );
	}

	/**
	 * The query plan SQLite chooses for a statement, one line per step.
	 *
	 * @param Row_Reader        $db    The connection that would run it.
	 * @param string            $sql   The statement.
	 * @param list<scalar|null> $binds Its binds.
	 */
	protected static function plan_text( Row_Reader $db, string $sql, array $binds ): string {
		return \implode( "\n", \array_column( $db->rows( "EXPLAIN QUERY PLAN {$sql}", $binds ), 3 ) );
	}

	/**
	 * One `requests_hourly` row.
	 *
	 * @param array<string,mixed> $over      Columns by name.
	 * @param int                 $partition The partition file it lands in.
	 */
	protected function seed_hourly( array $over = [], int $partition = 0 ): void {
		$row = $over + [
			'hour'         => Bucket::hour( (int) Core::$now ),
			'server'       => self::$seed_server,
			'status_class' => 2,
			'method'       => 'GET',
			'worker'       => 0,
			'country'      => '',
			'count'        => 1,
			'timed'        => 1,
			'sum_ms'       => 100.0,
			'max_ms'       => 100.0,
			'sum_peak_mb'  => 24.0,
			'errors'       => 0,
		];
		$this->seed_rows( $partition, [ [ 'add', 'requests_hourly', $row ] ] );
	}

	/**
	 * Rows of any tables in one batch, each `[ verb, table, columns ]` with its
	 * names spelled as text.
	 *
	 * @param int                                          $partition The partition file they land in.
	 * @param list<array{0:string,1:string,2:array<string,mixed>}> $rows The rows.
	 */
	protected function seed_rows( int $partition, array $rows ): void {
		$this->write_facts( $partition, self::facts_ops( $rows ) );
	}

	/**
	 * The ops writing `$rows`, as `seed_rows()` takes them: before each row,
	 * the `ignore` filing each name it spells, and its URL's.
	 *
	 * @param list<array{0:string,1:string,2:array<string,mixed>}> $rows The rows.
	 * @return list<array<int,mixed>>
	 */
	protected static function facts_ops( array $rows ): array {
		$batch = [];
		// One lookup op per name per batch, as the builder's `named_at()` files.
		$at    = [];
		$named = static function ( string $lookup, string $name, array $row = [ null ], array $ids = [] ) use ( &$batch, &$at ): int {
			if ( ! isset( $at[ $lookup ][ $name ] ) ) {
				$at[ $lookup ][ $name ] = \count( $batch );
				$batch[]                = [ 'ignore', $lookup, [ ...$row, $name ], [], $ids ];
			}
			return $at[ $lookup ][ $name ];
		};
		foreach ( $rows as [ $verb, $table, $values ] ) {
			$ids = [];
			// A server is stored as the builder names it.
			if ( \is_string( $values['server'] ?? null ) ) {
				$values['server'] = Url_Parts::server_name( $values['server'] );
			}
			if ( \array_key_exists( 'url', $values ) ) {
				$url    = Core::as_string( $values['url'] );
				$server = Core::as_string( $values['server'] );
				unset( $values['url'] );
				$server_at = $named( Flame_Facts_Row_Schema::NAMED_BY['server_id'], $server );
				$hash      = Url_Parts::hash( $url );
				$url_at    = $at['urls'][ $hash ] ?? null;
				if ( null === $url_at ) {
					$url_at              = \count( $batch );
					$at['urls'][ $hash ] = $url_at;
					$batch[]             = [ 'ignore', 'urls', Stats_Builder_Node::url_row( $url, $server ), [], [ Flame_Facts_Row_Schema::position( 'urls', 'server_id' ) => $server_at ] ];
				}
				$values['url_id'] = null;
				$ids[ Flame_Facts_Row_Schema::position( $table, 'url_id' ) ] = $url_at;
			}
			foreach ( \array_diff_key( Flame_Facts_Row_Schema::NAMED_BY, [ 'url_id' => true ] ) as $column => $lookup ) {
				$key = \substr( $column, 0, -3 );
				if ( ! \array_key_exists( $key, $values ) ) {
					continue;
				}
				if ( null !== $values[ $key ] ) {
					$ids[ Flame_Facts_Row_Schema::position( $table, $column ) ] = $named( $lookup, Core::as_string( $values[ $key ] ) );
				}
				unset( $values[ $key ] );
				$values[ $column ] = null;
			}
			$batch[] = [ $verb, $table, self::facts_row( $table, $values ), [], $ids ];
		}
		return $batch;
	}

	/**
	 * A non-worker `category_window` row of a category's own total.
	 *
	 * @param string $category    The category, as text.
	 * @param float  $ms          Its summed ms.
	 * @param int    $calls       Its summed calls.
	 * @param int    $requests    The requests it timed.
	 * @param int    $last_bucket The bucket it last moved in.
	 * @return array<string,mixed>
	 */
	protected static function window_row( string $category, float $ms, int $calls, int $requests, int $last_bucket ): array {
		return [
			'category'    => $category,
			'entry'       => '',
			'listener'    => 0,
			'worker'      => 0,
			'ms'          => $ms,
			'calls'       => $calls,
			'requests'    => $requests,
			'last_bucket' => $last_bucket,
		];
	}

	/**
	 * The defaults a timing row of either table takes.
	 *
	 * @param string $server The server it files under.
	 * @return array<string,mixed>
	 */
	private static function timing_defaults( string $server ): array {
		return [
			'bucket'   => Bucket::id( (int) Core::$now ),
			'server'   => $server,
			'category' => 'seed-category',
			'entry'    => '',
			'listener' => 0,
			'ms'       => 10.0,
			'calls'    => 1,
			'requests' => 1,
		];
	}

	/**
	 * The defaults an owner timing row of either table takes.
	 *
	 * @param string $server The server it files under.
	 * @return array<string,mixed>
	 */
	private static function owner_defaults( string $server ): array {
		return [
			'bucket'   => Bucket::id( (int) Core::$now ),
			'server'   => $server,
			'owner'    => 'seed-owner',
			'ms'       => 10.0,
			'calls'    => 1,
			'requests' => 1,
		];
	}

	/**
	 * A row in its table's declared column order.
	 *
	 * @param string              $table  The table.
	 * @param array<string,mixed> $values Every column by name.
	 * @return list<mixed>
	 */
	private static function facts_row( string $table, array $values ): array {
		$columns = \array_keys( Flame_Facts_Row_Schema::tables()[ $table ]['columns'] );
		$unknown = \array_diff( \array_keys( $values ), $columns );
		if ( [] !== $unknown ) {
			throw new \LogicException( "{$table} declares no column " . \implode( ', ', $unknown ) );
		}
		return \array_map( static fn ( string $column ): mixed => $values[ $column ], $columns );
	}

	/**
	 * Send one ROWS batch through a Row_Table bound to `$partition`.
	 *
	 * @param int                     $partition The partition file.
	 * @param list<array<int,mixed>> $batch     The ops.
	 */
	protected function write_facts( int $partition, array $batch ): void {
		self::with_facts_table( $partition, static fn ( Row_Table_Node $table ) => self::rows_into( $table, $batch ) );
	}

	/**
	 * Run one retention pass over `$partition`'s file, as the Table tick
	 * does at the current tick.
	 *
	 * @param int $partition The partition file.
	 * @return int Rows deleted, releases included.
	 */
	protected function purge_facts( int $partition ): int {
		return self::with_facts_table( $partition, static fn ( Row_Table_Node $table ): int => $table->purge( 1000 ) );
	}

	/**
	 * Run `$use` over a Row_Table bound to `$partition`, removed after.
	 *
	 * @template T
	 * @param int                           $partition The partition file.
	 * @param \Closure(Row_Table_Node): T $use       What to do with it.
	 * @return T
	 */
	private static function with_facts_table( int $partition, \Closure $use ): mixed {
		$bound                  = Core::$var['partition'] ?? null;
		Core::$var['partition'] = (string) $partition;
		try {
			$table = new Row_Table_Node();
			$table->name( Bootstrap::row_table( Flame_Facts_Row_Schema::class )['name'] );
			$table->arguments( [ 'Flame_Facts' ] );
		} finally {
			Core::$var['partition'] = $bound;
			if ( null === $bound ) {
				unset( Core::$var['partition'] );
			}
		}
		try {
			return $use( $table );
		} finally {
			$table->remove_node();
		}
	}

	/**
	 * The path of partition `$partition`'s file of the Row_Table the active
	 * topologies declare with the Flame_Facts schema.
	 *
	 * @param int $partition The partition.
	 */
	protected function facts_file( int $partition ): string {
		return Bootstrap::row_table( Flame_Facts_Row_Schema::class )['files'][ $partition ];
	}

	/**
	 * The file the substrate publishes the URL snapshot at, beside the facts
	 * table's partition files (ADR-35), for a test planting one that will
	 * not open there.
	 */
	protected function snapshot_file(): string {
		return \dirname( $this->facts_file( 0 ) ) . '/' . Url_Snapshot::NAME . '.sqlite';
	}

	/** Whether the substrate holds a published URL snapshot. */
	protected static function snapshot_published(): bool {
		return null !== Row_Table_Node::open_snapshot( Url_Snapshot::NAME );
	}
}
