<?php
namespace Newspack_Event_Logger_Nodes\Tests;

use Newspack_Nodes\Tests\TestCase as RuntimeTestCase;
use Newspack_Event_Logger_Nodes\Stats_Store;

abstract class TestCase extends RuntimeTestCase {

	/**
	 * Scratch tree the `tests/configs/logging-*.php` configs point at.
	 *
	 * MUST match their `base_directory`: storage nodes refuse a path outside the
	 * runtime tree, and the logging suites write `logs/` under it.
	 *
	 * MUST NOT be the BASELINE config's `base_directory`. The logging suites
	 * `rmdir_recursive()` this path ~40 times mid-run, and the substrate's
	 * `make_temp_dir()` hands out dirs INSIDE the configured base — so sharing
	 * one path puts every other test's live scratch tree under a recursive
	 * delete. `ConfigParityTest` pins both halves.
	 */
	protected const TEST_DIR = '/tmp/newspack-event-logger-nodes-test-logging';

	/**
	 * Path to a pre-written config file in `tests/configs/`.
	 *
	 * @param string $name Basename without the extension.
	 */
	protected function config_path( string $name ): string {
		return \dirname( __DIR__ ) . '/configs/' . $name . '.php';
	}

	/**
	 * URL rows per bucket, across the shapes `url_row_sources()` returns.
	 *
	 * It returns one pair per shard, and only the OVERFLOW rows are folded here,
	 * because `fold_url_rows()` keeps only the fields that add — a real hash
	 * put through it would come back without `url` or the extremes, and a
	 * later assertion on those would read as a data bug
	 * rather than as the helper. A real straddling hash needs the reader's full
	 * arithmetic, so it FAILS here instead of being quietly halved.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store   The store to read.
	 * @param array<int,string>                        $buckets Bucket keys.
	 * @return array<string,array<array-key,mixed>>
	 */
	protected function url_rows_by_bucket( \Newspack_Event_Logger_Nodes\Stats_Store $store, array $buckets ): array {
		// Both populations in ONE round trip, folded back together the way one
		// row used to be: a reader and a worker row for one hash is not a
		// straddle, and reading them shard by shard is the latency cliff.
		$out = [];
		foreach ( $store->url_row_sources( $buckets, null, true ) as [ $bucket, $rows ] ) {
			foreach ( $rows as $hash => $row ) {
				$out[ $bucket ][ $hash ] = isset( $out[ $bucket ][ $hash ] )
					? \Newspack_Event_Logger_Nodes\Stats_Store::fold_url_rows(
						\Newspack_Nodes\Core::arr( $out[ $bucket ][ $hash ] ),
						\Newspack_Nodes\Core::arr( $row )
					)
					: $row;
			}
		}
		// Named on the way out: a test asserting on a row should read
		// `['count']`. The stored SHAPE is pinned by the one test that reads a
		// shard raw, not by every test that happens to touch a row.
		foreach ( $out as $bucket => $rows ) {
			$out[ $bucket ] = self::named_url_rows( $rows );
		}
		return $out;
	}

	/**
	 * Every URL row of one bucket, merged across shards.
	 *
	 * A test convenience, deliberately not on `Stats_Store`: production reads a
	 * window through `url_row_sources()` and writes one shard at a time, so a
	 * bucket-level accessor there would be API kept alive only by its tests.
	 *
	 * One bucket of the sibling above, rather than its own shard walk: the
	 * overflow rows carry the SAME key in every shard they were folded in, and
	 * a walk that unions instead of folding keeps whichever shard it reached
	 * first and silently under-reports the rest of the tail.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store  The store to read.
	 * @param string                                   $bucket Bucket key.
	 * @return array<array-key,mixed>
	 */
	protected function url_bucket_rows( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $bucket ): array {
		return \Newspack_Nodes\Core::arr( $this->url_rows_by_bucket( $store, [ $bucket ] )[ $bucket ] ?? [] );
	}

	/**
	 * Re-assert the topology registration bootstrap.php makes. Sibling tests call
	 * Topology_Registry::reset(), which strands every later test that reads a
	 * topology — and ELN topologies `include` ACROSS the plugin boundary
	 * (job-router -> job-intake, which the substrate ships), so BOTH dirs must
	 * resolve. An unresolvable include now throws by design: an empty write set
	 * would read as "no conflict" to the gate and "these logs are orphans" to the
	 * GC. register_plugin()/register_builtin_dir() are idempotent.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Newspack_Nodes\Topology_Registry::register_plugin(
			'Newspack_Event_Logger_Nodes\\',
			NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
		);
		\Newspack_Nodes\Topology_Registry::register_builtin_dir(
			\dirname( __DIR__, 3 ) . '/newspack-nodes/topologies'
		);
	}

	/**
	 * ELN-specific default prefix so app temp dirs live in their OWN namespace,
	 * not the substrate's `newspack-nodes-test-`. Under parallel run-coverage the
	 * nodes and ELN suites each `rm -rf` their prefix; sharing one prefix had each
	 * suite deleting the other's LIVE temp dirs mid-run. Inherits the parent's
	 * PID + more-entropy uniqueness and auto-cleanup.
	 */
	protected function make_temp_dir( string $prefix = 'newspack-event-logger-nodes-test-' ): string {
		return parent::make_temp_dir( $prefix );
	}

	/**
	 * Point the `config` token namespace at a per-test scratch tree.
	 *
	 * Topology TSL resolves `<config:KEY>` through the substrate's registered
	 * `config` namespace. Tests that load a topology in-process override it so
	 * the Consumer/Partition nodes open under their own scratch dir instead of
	 * the shared base directory, where orphan lock dirs from prior runs burn
	 * ORPHAN_GRACE_S * partitions seconds in `Lock::try_steal_orphan_or_stale()`.
	 *
	 * Only the three directory keys are replaced; everything else defers to the
	 * substrate resolver, which also answers the `<config:KEY>` tokens node
	 * schemas carry as argument DEFAULTS — a hand-listed key set goes stale the
	 * next time a schema grows one. An unanswerable key throws rather than
	 * resolving empty: a null there silently built `/combined.firehose.p0` out
	 * of a missing `deadletter_dir`.
	 *
	 * Callers must restore `Core::$config_resolvers` in tearDown.
	 *
	 * @param string                $tmp       Scratch dir, inside the configured base.
	 * @param array<string, string> $overrides Per-test values for any config key.
	 */
	protected function use_scratch_config( string $tmp, array $overrides = [] ): void {
		\Newspack_Nodes\Config::register_token_namespace();
		$substrate = \Newspack_Nodes\Core::$config_resolvers['config'];
		$values    = \array_merge(
			[
				'logs_dir'       => $tmp . '/logs',
				'offsets_dir'    => $tmp . '/offsets',
				'deadletter_dir' => $tmp . '/deadletter',
			],
			$overrides
		);
		\Newspack_Nodes\Core::register_config_namespace(
			'config',
			static function ( string $key ) use ( $values, $substrate ): string {
				$value = $values[ $key ] ?? $substrate( $key );
				if ( null === $value ) {
					throw new \RuntimeException(
						\sprintf( 'test config namespace cannot resolve %s', $key )
					);
				}
				return (string) $value;
			}
		);
	}

	/**
	 * Same as the substrate helper but also resets the application Config
	 * cache so its merged result picks up the new file.
	 */
	protected function use_base_dir( string $dir, array $extras = [] ): void {
		parent::use_base_dir( $dir, $extras );
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			\Newspack_Event_Logger_Nodes\Config::reset();
		}
	}

	/**
	 * The install-scoped address of a logical memcache key.
	 *
	 * Tests assert on real keys, and the scope is not theirs to spell — deriving
	 * it here is what stops a prefix change from needing 30 edits, and what
	 * keeps a test from passing on a prefix mismatch instead of the thing it
	 * means to check.
	 */
	protected static function scoped( string $logical ): string {
		return \Newspack_Nodes\Cache_Backend::site_key( $logical );
	}
	/**
	 * The inverse of `positional_url_row()`, for assertions.
	 *
	 * The read helpers below map through it, so a test asserting on a stored
	 * row reads `['count']` rather than counting indexes. The SHAPE is pinned
	 * separately, by the one test that reads a shard raw.
	 *
	 * A collapsed split value stays null: it means "this host served every
	 * request the row counted", and naming it would invent eight fields the
	 * store deliberately did not write.
	 *
	 * @param array<array-key,mixed> $row Stored positional row.
	 * @return array<string,mixed>
	 */
	protected static function named_url_row( array $row ): array {
		$names = \Newspack_Event_Logger_Nodes\Stats_Store::ROW_FIELD_NAMES;
		$out   = [];
		foreach ( $row as $index => $value ) {
			$name = \is_int( $index ) ? ( $names[ $index ] ?? $index ) : $index;
			$out[ $name ] = \Newspack_Event_Logger_Nodes\Stats_Store::URL_SRV_FIELD === $name
				? \array_map(
					static fn ( $sums ) => null === $sums ? null : self::named_url_row( \Newspack_Nodes\Core::arr( $sums ) ),
					\Newspack_Nodes\Core::arr( $value )
				)
				: $value;
		}
		return $out;
	}

	/**
	 * A map of stored rows, named for assertions. See `named_url_row()`.
	 *
	 * @param array<array-key,mixed> $rows Stored rows by hash.
	 * @return array<array-key,array<string,mixed>>
	 */
	protected static function named_url_rows( array $rows ): array {
		return \array_map(
			static fn ( $row ): array => self::named_url_row( \Newspack_Nodes\Core::arr( $row ) ),
			$rows
		);
	}

	/**
	 * Seed one shard of a bucket from NAMED rows.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store  Destination.
	 * @param string                                   $bucket Bucket key.
	 * @param string                                   $shard  Shard name.
	 * @param array<array-key,mixed>                   $rows   Named rows by hash.
	 */
	protected function seed_url_shard( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $bucket, string $shard, array $rows ): bool {
		return $this->set_url_shard( $store, $bucket, $shard, self::store_url_names( $store, $rows ) );
	}

	/**
	 * Seed one shard of a coarse hour from NAMED rows.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store Destination.
	 * @param string                                   $hour  Hour key.
	 * @param string                                   $shard Shard name.
	 * @param array<array-key,mixed>                   $rows  Named rows by hash.
	 */
	protected function seed_url_hour( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $hour, string $shard, array $rows ): bool {
		return $store->set_url_hour( $hour, $shard, self::store_url_names( $store, $rows ) );
	}

	/**
	 * Route each named row's `url` to the name table and store the rest.
	 *
	 * A stored row carries the hash alone; the name lives once in
	 * `Stats_Store::NS_URLMAP`. A test still says what it means — `'url' => …`
	 * beside the counts — and this puts each half where production does.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store Destination.
	 * @param array<array-key,mixed>                   $rows  Named rows by hash.
	 * @return array<array-key,array<int,mixed>>
	 */
	private static function store_url_names( \Newspack_Event_Logger_Nodes\Stats_Store $store, array $rows ): array {
		$names = [];
		$out   = [];
		foreach ( $rows as $hash => $row ) {
			$row = \Newspack_Nodes\Core::arr( $row );
			if ( isset( $row['url'] ) ) {
				$names[ (string) $hash ] = \Newspack_Nodes\Core::str( $row['url'] );
				unset( $row['url'] );
			}
			$out[ $hash ] = self::positional_url_row( $row );
		}
		$store->set_url_names( $names );
		return $out;
	}

	/**
	 * A stored URL row from a NAMED one, so a test can say what it means.
	 *
	 * Storage is positional (`Stats_Store::ROW_*`) because `serialize()` writes
	 * every key name into every row; a test seeding one should not have to
	 * count indexes to stay readable. Reverses `ROW_FIELD_NAMES`, so it cannot
	 * drift from the shape it seeds. A row already positional passes through.
	 *
	 * A split VALUE of null is the collapse — the host served every request the
	 * row counted — and passes through as null rather than recursing.
	 *
	 * @param array<array-key,mixed> $row Named row, or an already-stored one.
	 * @return array<int,mixed>
	 */
	protected static function positional_url_row( array $row ): array {
		$index = \array_flip( \Newspack_Event_Logger_Nodes\Stats_Store::ROW_FIELD_NAMES );
		$out   = [];
		foreach ( $row as $field => $value ) {
			if ( \is_int( $field ) ) {
				$out[ $field ] = $value;
				continue;
			}
			// The name is not part of the row, and passes through under its own
			// key so `store_url_names()` can file it however deep the call is.
			if ( 'url' === $field ) {
				$out['url'] = $value;
				continue;
			}
			if ( ! isset( $index[ $field ] ) ) {
				throw new \RuntimeException( "no such URL row field: {$field}" );
			}
			$out[ $index[ $field ] ] = \Newspack_Event_Logger_Nodes\Stats_Store::URL_SRV_FIELD === $field
				? \array_map(
					static fn ( $sums ) => null === $sums ? null : self::positional_url_row( \Newspack_Nodes\Core::arr( $sums ) ),
					\Newspack_Nodes\Core::arr( $value )
				)
				: $value;
		}
		return $out;
	}

	/**
	 * Seed a whole URL bucket, routing each row to the shard its hash names.
	 *
	 * A test convenience: production writes one shard at a time, which is the
	 * point of sharding. Writes EVERY shard, because "replace the bucket" has
	 * to clear the ones this data does not reach.
	 *
	 * @param \Newspack_Event_Logger_Nodes\Stats_Store $store  Destination.
	 * @param string                                   $bucket Bucket key.
	 * @param array<array-key,mixed>                   $data   Whole bucket.
	 * @return bool True when every shard's set landed.
	 */
	protected function set_url_bucket( \Newspack_Event_Logger_Nodes\Stats_Store $store, string $bucket, array $data ): bool {
		$data = self::store_url_names( $store, $data );
		// Worker rows go to the worker shard family, as the writer files them.
		$split = [ false => [], true => [] ];
		foreach ( $data as $hash => $row ) {
			$row_arr = \Newspack_Nodes\Core::arr( $row );
			$split[ ! empty( $row_arr[ \Newspack_Event_Logger_Nodes\Stats_Store::ROW_WORKER ] ) ][ $hash ] = $row;
		}
		$ok = true;
		foreach ( [ false, true ] as $worker ) {
			$by_shard = \Newspack_Event_Logger_Nodes\Stats_Store::rows_by_shard( $split[ $worker ], $worker );
			foreach ( \Newspack_Event_Logger_Nodes\Stats_Store::url_shards( $worker ) as $shard ) {
				$ok = $this->set_url_shard( $store, $bucket, $shard, \Newspack_Nodes\Core::arr( $by_shard[ $shard ] ?? null ) ) && $ok;
			}
		}
		return $ok;
	}

	// ── Stats_Store named bucket access ─────────────────────────────────────
	//
	// The per-namespace accessors the flush used to own. Batching its writes
	// left them with no production caller, so they live here, where their 160
	// call sites already are — named and readable for a test, over the parts
	// the batch pair takes.

	/** @param array<string,mixed> $data */
	protected function set_hourly_bucket( Stats_Store $store, string $bucket, array $data ): bool {
		return $store->bucket_set_multi( [ [ Stats_Store::hourly_parts(), $bucket, $data ] ] )[0];
	}

	/** @return array<string,mixed> */
	protected function get_hourly_bucket( Stats_Store $store, string $bucket ): array {
		return $store->bucket_get_multi( [ [ Stats_Store::hourly_parts(), $bucket ] ] )[0];
	}

	/** @return array<string,mixed> */
	protected function get_url_hour( Stats_Store $store, string $hour, string $shard ): array {
		return $store->bucket_get_multi( [ [ Stats_Store::url_hour_parts( $shard ), $hour ] ] )[0];
	}

	/** @return array<string,mixed> */
	protected function get_category_bucket( Stats_Store $store, string $bucket, string $server = '' ): array {
		return $store->bucket_get_multi( [ [ Stats_Store::cat_parts( $server ), $bucket ] ] )[0];
	}

	/** @param array<string,mixed> $data */
	protected function set_category_bucket( Stats_Store $store, string $bucket, array $data, string $server = '' ): bool {
		return $store->bucket_set_multi( [ [ Stats_Store::cat_parts( $server ), $bucket, $data ] ] )[0];
	}

	/** @return array<string,mixed> */
	protected function get_dimensional_bucket( Stats_Store $store, string $dimension, string $bucket, string $server = '' ): array {
		return $store->bucket_get_multi( [ [ Stats_Store::dim_parts( $dimension, $server ), $bucket ] ] )[0];
	}

	/** @param array<string,mixed> $data */
	protected function set_dimensional_bucket( Stats_Store $store, string $dimension, string $bucket, array $data, string $server = '' ): bool {
		return $store->bucket_set_multi( [ [ Stats_Store::dim_parts( $dimension, $server ), $bucket, $data ] ] )[0];
	}

	/** @return array<string,mixed> */
	protected function get_leaderboard_bucket( Stats_Store $store, string $bucket, string $server = '' ): array {
		return $store->bucket_get_multi( [ [ Stats_Store::lb_parts( $server ), $bucket ] ] )[0];
	}

	/** @param array<string,mixed> $data */
	protected function set_leaderboard_bucket( Stats_Store $store, string $bucket, array $data, string $server = '' ): bool {
		return $store->bucket_set_multi( [ [ Stats_Store::lb_parts( $server ), $bucket, $data ] ] )[0];
	}

	/** @return array<string,mixed> */
	protected function get_url_category_bucket( Stats_Store $store, string $url_hash, string $bucket ): array {
		return $store->bucket_get_multi( [ [ Stats_Store::url_cat_parts( $url_hash ), $bucket ] ] )[0];
	}

	/** @param array<string,mixed> $data */
	protected function set_url_category_bucket( Stats_Store $store, string $url_hash, string $bucket, array $data ): bool {
		return $store->bucket_set_multi( [ [ Stats_Store::url_cat_parts( $url_hash ), $bucket, $data ] ] )[0];
	}

	/** @return array<string,mixed> */
	protected function get_url_dimensional_bucket( Stats_Store $store, string $url_hash, string $bucket ): array {
		return $store->bucket_get_multi( [ [ Stats_Store::url_dim_parts( $url_hash ), $bucket ] ] )[0];
	}

	/** @param array<string,mixed> $data */
	protected function set_url_dimensional_bucket( Stats_Store $store, string $url_hash, string $bucket, array $data ): bool {
		return $store->bucket_set_multi( [ [ Stats_Store::url_dim_parts( $url_hash ), $bucket, $data ] ] )[0];
	}

	/** @return array<string,mixed> */
	protected function get_url_shard( Stats_Store $store, string $bucket, string $shard ): array {
		return $store->bucket_get_multi( [ [ Stats_Store::url_shard_parts( $shard ), $bucket ] ] )[0];
	}

	/** @param array<array-key,mixed> $rows */
	protected function set_url_shard( Stats_Store $store, string $bucket, string $shard, array $rows ): bool {
		return $store->bucket_set_multi( [ [ Stats_Store::url_shard_parts( $shard ), $bucket, $rows ] ] )[0];
	}
}
