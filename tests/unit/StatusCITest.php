<?php
/**
 * StatusCITest: unit tests for Status_CI, the M2 service-CI that replaces
 * the legacy StatusController.
 *
 * Asserts value-equivalence with the legacy `get_status()` payload — same
 * status / version / runtime_version / num_partitions / topologies /
 * cache_available / timestamp fields, same defaults, same Throwable-swallow
 * behavior on the cache probe. Substrate config is seeded via
 * `TestCase::use_base_dir()`, mirroring DiscoveryCITest.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Status_CI;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Status_CI::class )]
class StatusCITest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS,
		// matching DiscoveryCITest.
		$this->tmp = '/tmp/status-ci-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		$this->use_base_dir( $this->tmp );
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	public function test_get_verb_returns_canonical_status_payload(): void {
		$this->use_base_dir( $this->tmp, [
			'num_partitions' => 4,
			'topologies'     => [ 'firehose-workers-and-jobs', 'request-workers' ],
		] );
		$cache = new class {
			public function is_available(): bool { return true; }
		};
		$ci = new Status_CI( $cache );

		$before = \time();
		$result = VerbHarness::fire( $ci, 'status', 'get' );
		$after  = \time();

		$this->assertIsArray( $result );
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( \NEWSPACK_EVENT_LOGGER_NODES_VERSION, $result['version'] );
		$this->assertSame( \NEWSPACK_NODES_VERSION, $result['runtime_version'] );
		$this->assertSame( 4, $result['num_partitions'] );
		$this->assertSame( [ 'firehose-workers-and-jobs', 'request-workers' ], $result['topologies'] );
		$this->assertTrue( $result['cache_available'] );
		$this->assertIsInt( $result['timestamp'] );
		$this->assertGreaterThanOrEqual( $before, $result['timestamp'] );
		$this->assertLessThanOrEqual( $after, $result['timestamp'] );
	}

	public function test_cache_unavailable_reports_false(): void {
		$cache = new class {
			public function is_available(): bool { return false; }
		};
		$ci = new Status_CI( $cache );

		$result = VerbHarness::fire( $ci, 'status', 'get' );

		$this->assertFalse( $result['cache_available'] );
		$this->assertSame( 'ok', $result['status'] );
	}

	public function test_cache_throwable_is_swallowed_and_reports_false(): void {
		$cache = new class {
			public function is_available(): bool {
				throw new \RuntimeException( 'memcache unreachable' );
			}
		};
		$ci = new Status_CI( $cache );

		$result = VerbHarness::fire( $ci, 'status', 'get' );

		$this->assertFalse( $result['cache_available'] );
		$this->assertSame( 'ok', $result['status'] );
	}

	public function test_no_cache_injected_reports_cache_unavailable(): void {
		$ci = new Status_CI();

		$result = VerbHarness::fire( $ci, 'status', 'get' );

		$this->assertFalse( $result['cache_available'] );
		$this->assertSame( 'ok', $result['status'] );
	}

	public function test_num_partitions_defaults_to_one_when_missing(): void {
		// use_base_dir() with no extras seeds only base_directory, leaving
		// num_partitions/topologies to fall through to defaults.
		$ci = new Status_CI();

		$result = VerbHarness::fire( $ci, 'status', 'get' );

		$this->assertSame( 1, $result['num_partitions'] );
		$this->assertSame( [], $result['topologies'] );
	}
}
