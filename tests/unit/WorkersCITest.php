<?php
/**
 * WorkersCITest: unit tests for Workers_CI, the M2 service-CI that
 * replaces the legacy WorkersController + FirehoseController::heartbeat.
 *
 * These three tests establish the pattern every other M2 CI test will
 * follow: instantiate the CI with stubbed dependencies, fire a verb
 * through VerbHarness, assert on the decoded payload.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Workers_CI;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Workers_CI::class )]
class WorkersCITest extends TestCase {

	protected function tearDown(): void {
		VerbHarness::reset();
		parent::tearDown();
	}

	public function test_list_verb_returns_workers_from_cli(): void {
		$fake_cli = new class {
			public function ls_workers(): array {
				return [
					[ 'type' => 'firehose-workers-and-jobs', 'partition' => 0, 'live' => true ],
				];
			}
			public function live_position( $cache, string $type, int $partition ): ?array {
				return [ 'seg' => 0, 'off' => 100, 'ts' => 1747000000 ];
			}
			public function restart_workers( array $workers, array $filter = [], int $partition = -1 ): int { return 0; }
		};
		$ci = new Workers_CI( $fake_cli );

		$result = VerbHarness::fire( $ci, 'workers', 'list' );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertSame( 'firehose-workers-and-jobs', $result[0]['type'] );
	}

	public function test_restart_verb_calls_cli_and_returns_count(): void {
		$fake_cli = new class {
			public ?array $called_with = null;
			public function ls_workers(): array {
				return [
					[ 'type' => 'firehose-workers-and-jobs', 'partition' => 0 ],
					[ 'type' => 'job-workers',                'partition' => 0 ],
				];
			}
			public function live_position( $cache, string $type, int $partition ): ?array { return null; }
			public function restart_workers( array $workers, array $filter = [], int $partition = -1 ): int {
				$this->called_with = [ 'workers' => $workers, 'filter' => $filter, 'partition' => $partition ];
				return \count( $workers );
			}
		};
		$ci = new Workers_CI( $fake_cli );

		$result = VerbHarness::fire( $ci, 'workers', 'restart', \wp_json_encode( [ 'types' => [ 'firehose-workers-and-jobs' ] ] ) );

		$this->assertSame( [ 'restarted' => 2 ], $result );
		$this->assertSame( [ 'firehose-workers-and-jobs' => true ], $fake_cli->called_with['filter'] );
	}

	public function test_heartbeat_verb_records_slot_via_cache(): void {
		// touch_sse_slot is the real Cache_Interface method (legacy
		// FirehoseController::heartbeat calls it directly). The plan's
		// stub of `heartbeat_sse_slot` doesn't exist on Cache_Interface;
		// aligning here per the plan's "fix divergences" guidance.
		$fake_cache = new class {
			public ?array $recorded = null;
			public function touch_sse_slot( int $user_id, string $ip_hash, int $slot, int $ttl, int $partition = -1 ): bool {
				$this->recorded = [ 'slot' => $slot, 'ttl' => $ttl, 'partition' => $partition ];
				return true;
			}
		};
		$ci = new Workers_CI( $this->stub_cli(), $fake_cache );

		$result = VerbHarness::fire( $ci, 'workers', 'heartbeat', \wp_json_encode( [ 'slot' => 7 ] ) );

		$this->assertSame( [ 'success' => true, 'slot' => 7 ], $result );
		$this->assertSame( 7, $fake_cache->recorded['slot'] );
	}

	private function stub_cli(): object {
		return new class {
			public function ls_workers(): array { return []; }
			public function live_position( $cache, string $type, int $partition ): ?array { return null; }
			public function restart_workers( array $workers, array $filter = [], int $partition = -1 ): int { return 0; }
		};
	}
}
