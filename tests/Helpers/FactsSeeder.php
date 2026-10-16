<?php
/**
 * FactsSeeder: rows in the Row_Table the active topologies declare with the
 * Flame_Facts schema, written as the flame builder writes them.
 *
 * Each seeder sends one `ROWS` batch through a real `Row_Table` bound to the
 * partition it names, so a seed passes the same write contract a flush does:
 * the URL and agent `ignore`s whose ids the row takes by batch index, then
 * the row's own op. Every column a test leaves out takes the seeder's
 * default; a key naming no column fails the seed.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Helpers;

use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Core;
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
	 *                                       by default) and `ua`.
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
		$now   = (int) Core::$now;
		$batch = [];
		foreach ( $rows as $over ) {
			$server = Core::as_string( $over['server'] ?? self::$seed_server );
			$url    = Core::as_string( $over['url'] ?? "https://{$server}/seed-path/" );
			$ua     = $over['ua'] ?? null;
			unset( $over['url'], $over['ua'] );
			$bucket = Core::as_int( $over['bucket'] ?? Bucket::id( $now ) );
			$row    = self::facts_row(
				'requests',
				$over + [
					'rid'         => 'rid-seed-' . ( ++$this->seeded_requests ),
					'bucket'      => $bucket,
					'finished_at' => (float) ( $bucket * Bucket::SECONDS ),
					'started_at'  => $bucket * Bucket::SECONDS - 2,
					'partition'   => $partition,
					'segment'     => null,
					'offset'      => null,
					'length'      => null,
					'server'      => $server,
					'url_id'      => null,
					'status'      => 200,
					'method'      => 'GET',
					'country'     => null,
					'ua_id'       => null,
					'ja4'         => null,
					'from_header' => null,
					'worker'      => 0,
					'profiled'    => 0,
					'error'       => null,
					'ms'          => 100.0,
					'peak_mb'     => 24.0,
				]
			);
			$ids     = [ Flame_Facts_Row_Schema::position( 'requests', 'url_id' ) => \count( $batch ) ];
			$batch[] = self::url_op( $url, $server );
			if ( null !== $ua ) {
				$ids[ Flame_Facts_Row_Schema::position( 'requests', 'ua_id' ) ] = \count( $batch );
				$batch[] = [ 'ignore', 'agents', [ null, $ua ] ];
			}
			$batch[] = [ 'ignore', 'requests', $row, [], $ids ];
		}
		$this->write_facts( $partition, $batch );
	}

	/**
	 * One `timings` row: a URL's category or entry over one bucket.
	 *
	 * @param array<string,mixed> $over      Columns by name, plus `url`.
	 * @param int                 $partition The partition file it lands in.
	 */
	protected function seed_timing( array $over = [], int $partition = 0 ): void {
		$server = Core::as_string( $over['server'] ?? self::$seed_server );
		$url    = Core::as_string( $over['url'] ?? "https://{$server}/seed-path/" );
		unset( $over['url'] );
		$row = self::facts_row( 'timings', $over + self::timing_defaults( $server ) + [ 'url_id' => null ] );
		$this->write_facts( $partition, [ self::url_op( $url, $server ), [ 'add', 'timings', $row, [], [ Flame_Facts_Row_Schema::position( 'timings', 'url_id' ) => 0 ] ] ] );
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
		$ops = [];
		foreach ( $rows as $over ) {
			$server = Core::as_string( $over['server'] ?? self::$seed_server );
			$ops[]  = [ 'add', 'timings_site', self::facts_row( 'timings_site', $over + self::timing_defaults( $server ) + [ 'worker' => 0 ] ) ];
		}
		$this->write_facts( $partition, $ops );
	}

	/**
	 * One `requests_hourly` row.
	 *
	 * @param array<string,mixed> $over      Columns by name.
	 * @param int                 $partition The partition file it lands in.
	 */
	protected function seed_hourly( array $over = [], int $partition = 0 ): void {
		$row = self::facts_row(
			'requests_hourly',
			$over + [
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
			]
		);
		$this->write_facts( $partition, [ [ 'add', 'requests_hourly', $row ] ] );
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
	 * The `ignore` that files a URL, its id the row's by batch index.
	 *
	 * @param string $url    A logged URL.
	 * @param string $server The server its row files under.
	 * @return array<int,mixed>
	 */
	private static function url_op( string $url, string $server ): array {
		return [ 'ignore', 'urls', [ null, Log_Manager::url_hash( $url ), $server, Url_Parts::row_path( $url, $server ) ] ];
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
			self::rows_into( $table, $batch );
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
}
