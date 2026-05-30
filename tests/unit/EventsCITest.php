<?php
/**
 * EventsCITest: unit tests for Events_CI, the M2 service-CI that replaces
 * the legacy EventsController.
 *
 * Asserts value-equivalence with the legacy controller for the verb
 * exposed to the event-dashboards React tree:
 *   stats  — merge of per-partition hourly buckets into a single time_series
 *            array.
 *
 * Substrate config (num_partitions, max_lifespan, base_directory) is seeded
 * via TestCase::use_base_dir(), matching SettingsCITest / StatusCITest. The
 * shared `Core::$memd` handle is seeded with an in-memory `\Memcached` so the
 * stats path is exercised without a real memcache server.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Events_CI_Node;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Events_CI_Node::class )]
class EventsCITest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS,
		// matching SettingsCITest / StatusCITest.
		$this->tmp  = '/tmp/events-ci-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		Core::$memd = new InMemoryMemcached();
		$this->use_base_dir( $this->tmp );
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	// ── stats verb ─────────────────────────────────────────────────────────

	public function test_stats_verb_returns_data_meta_shape_when_empty(): void {
		// No stats seeded — verb returns the canonical envelope with an
		// empty time_series array.
		$interpreter     = new Events_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'events', 'stats' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'meta', $result );
		$this->assertSame( [], $result['data']['time_series'] );
	}

	public function test_stats_verb_returns_merged_hourly_buckets(): void {
		// Seed one partition with one hourly bucket via Stats_Store.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'max_lifespan' => 86400 ] );
		$store = new Stats_Store( 0, 86400 );
		$store->set_hourly( [
			'2026-05-17-10' => [ 'count' => 3, 'sum_ms' => 1500.0, 'sum_peak_mb' => 30.0 ],
		] );

		$interpreter     = new Events_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'events', 'stats' );

		$this->assertCount( 1, $result['data']['time_series'] );
		$row = $result['data']['time_series'][0];
		$this->assertSame( '2026-05-17-10', $row['hour'] );
		$this->assertSame( 3, $row['count'] );
		// JSON round-trip drops trailing .0 — wp_json_encode( 1500.0 ) emits
		// "1500" which json_decode reads back as int. Use loose equality
		// since we care about the numeric value, not the storage type.
		$this->assertEquals( 1500.0, $row['sum_ms'] );
		$this->assertEquals( 30.0, $row['sum_peak_mb'] );
	}

	public function test_stats_verb_merges_across_partitions(): void {
		// Two partitions, same hour bucket — verb sums into one row.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 2, 'max_lifespan' => 86400 ] );
		( new Stats_Store( 0, 86400 ) )->set_hourly( [
			'2026-05-17-10' => [ 'count' => 2, 'sum_ms' => 1000.0, 'sum_peak_mb' => 20.0 ],
		] );
		( new Stats_Store( 1, 86400 ) )->set_hourly( [
			'2026-05-17-10' => [ 'count' => 5, 'sum_ms' => 2500.0, 'sum_peak_mb' => 50.0 ],
		] );

		$interpreter     = new Events_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'events', 'stats' );

		$this->assertCount( 1, $result['data']['time_series'] );
		$row = $result['data']['time_series'][0];
		$this->assertSame( 7, $row['count'] );
		$this->assertEquals( 3500.0, $row['sum_ms'] );
		$this->assertEquals( 70.0, $row['sum_peak_mb'] );
	}

	public function test_stats_verb_sorts_buckets_by_hour(): void {
		// Two hour buckets, out of order in storage — ksort puts the
		// earlier hour first in the output array.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'max_lifespan' => 86400 ] );
		$store = new Stats_Store( 0, 86400 );
		$store->set_hourly( [
			'2026-05-17-12' => [ 'count' => 1, 'sum_ms' => 100.0, 'sum_peak_mb' => 1.0 ],
			'2026-05-17-09' => [ 'count' => 1, 'sum_ms' => 100.0, 'sum_peak_mb' => 1.0 ],
		] );

		$interpreter     = new Events_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'events', 'stats' );

		$this->assertCount( 2, $result['data']['time_series'] );
		$this->assertSame( '2026-05-17-09', $result['data']['time_series'][0]['hour'] );
		$this->assertSame( '2026-05-17-12', $result['data']['time_series'][1]['hour'] );
	}

	public function test_stats_verb_fail_soft_when_cache_unavailable(): void {
		// Cache-down (Core::$memd null): Stats_Store::get_hourly returns [], the
		// verb returns the empty time_series envelope rather than throwing.
		// Matches the legacy controller's fail-soft contract for stats reads.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'max_lifespan' => 86400 ] );
		Core::$memd = null;

		$interpreter     = new Events_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'events', 'stats' );

		$this->assertSame( [], $result['data']['time_series'] );
	}

	// ── schema-driven dispatch ──────────────────────────────────────────────

	public function test_node_schema_lists_only_stats_verb_with_handler(): void {
		$verbs = [];
		foreach ( Events_CI_Node::node_schema()['commands'] as $verb ) {
			$verbs[ $verb['name'] ] = $verb;
		}

		$this->assertSame( [ 'stats' ], \array_keys( $verbs ) );
		$this->assertIsCallable( $verbs['stats']['handler'] );
	}

	public function test_stats_verb_declares_no_args(): void {
		// `stats` reads no $payload/$args — it just merges hourly buckets.
		$verbs = self::verbs_by_name();
		$this->assertSame( [], $verbs['stats']['args'] );
	}

	/**
	 * node_schema()['commands'] indexed by verb name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function verbs_by_name(): array {
		$verbs = [];
		foreach ( Events_CI_Node::node_schema()['commands'] as $verb ) {
			$verbs[ $verb['name'] ] = $verb;
		}
		return $verbs;
	}
}
