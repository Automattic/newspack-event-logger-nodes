<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Job_Router_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * JobRouter is a pure router: it pulls job-shaped entries from the firehose
 * and jobintake sources, normalizes them to {k, handler, parameters, ts},
 * and forwards via parent::fill to its target (jobs:partition in topology).
 * It never dispatches handlers itself; that's JobWorker's job.
 *
 * These tests sink JobRouter into a Capture_Sink_Node so we can assert what came
 * out, then walk through the matrix of source × kind × validity.
 */
#[CoversClass( Job_Router_Node::class )]
class JobRouterTest extends TestCase {

	private Job_Router_Node $jr;
	private Capture_Sink_Node $sink;

	protected function setUp(): void {
		parent::setUp();
		$this->jr = new Job_Router_Node();
		$this->jr->name( 'job-router' );
		$this->sink = new Capture_Sink_Node();
		$this->jr->sink( $this->sink );
	}

	/** Build a Message stamped with the given FROM and VALUE. */
	private function msg( string $from, array $value ): array {
		$m                  = Message::new_message();
		$m[ Message::TYPE ] = Message::TM_STRUCT;
		$m[ Message::FROM ] = $from;
		$m[ Message::VALUE ] = $value;
		return $m;
	}

	/** Firehose entry shape: job body wrapped under `m`. */
	private function firehose_entry( string $kind, string $handler, array $parameters ): array {
		return [
			'n'   => 1,
			'rid' => 'r1',
			'k'   => $kind,
			'm'   => [
				'type'       => $kind,
				'handler'    => $handler,
				'parameters' => $parameters,
			],
			'ts'  => 1700000000.0,
		];
	}

	/** Jobintake entry shape: flat (no nested `m`). */
	private function jobintake_entry( string $kind, string $handler, array $parameters ): array {
		return [
			'k'          => $kind,
			'handler'    => $handler,
			'parameters' => $parameters,
			'ts'         => 1700000000.0,
		];
	}

	public function test_output_is_keyed_by_k_for_job_worker_dispatch(): void {
		// JobRouter output must carry the kind under `k` — the same field
		// Job_Worker dispatches on and Job_Intake writes — so a firehose job
		// round-trips to the handler with zero field renaming anywhere.
		$entry = $this->firehose_entry( 'job', 'sync_user', [ 'id' => 42 ] );
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame(
			[
				'k'          => 'job',
				'handler'    => 'sync_user',
				'parameters' => [ 'id' => 42 ],
				'ts'         => 1700000000.0,
			],
			$this->sink->captured[0][ Message::VALUE ]
		);
	}

	public function test_firehose_job_forwards_normalized(): void {
		$entry = $this->firehose_entry( 'job', 'sync_user', [ 'id' => 42 ] );
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 1, $this->sink->captured );
		$out = $this->sink->captured[0];
		$this->assertSame(
			[
				'k'          => 'job',
				'handler'    => 'sync_user',
				'parameters' => [ 'id' => 42 ],
				'ts'         => 1700000000.0,
			],
			$out[ Message::VALUE ]
		);
	}

	public function test_firehose_remote_job_forwards_with_k_remote_job(): void {
		$entry = $this->firehose_entry( 'remote_job', 'hub_op', [ 'k' => 'v' ] );
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( 'remote_job', $this->sink->captured[0][ Message::VALUE ]['k'] );
		$this->assertSame( 'hub_op', $this->sink->captured[0][ Message::VALUE ]['handler'] );
	}

	public function test_jobintake_job_forwards_normalized(): void {
		$entry = $this->jobintake_entry( 'job', 'process_image', [ 'url' => '/x.jpg' ] );
		$message = $this->msg( 'jobintake:consumer', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame(
			[
				'k'          => 'job',
				'handler'    => 'process_image',
				'parameters' => [ 'url' => '/x.jpg' ],
				'ts'         => 1700000000.0,
			],
			$this->sink->captured[0][ Message::VALUE ]
		);
	}

	public function test_jobintake_remote_kind_is_downgraded_to_local(): void {
		// Defense-in-depth: a misbehaving producer writing `k:"remote_job"`
		// to jobintake.log MUST NOT be allowed to escalate to remote dispatch
		// (that would let spokes inject hub-only operations).
		$entry = $this->jobintake_entry( 'remote_job', 'priv_op', [] );
		$message = $this->msg( 'jobintake:consumer', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( 'job', $this->sink->captured[0][ Message::VALUE ]['k'] );
	}

	public function test_unknown_source_dropped_silently(): void {
		// FROM not stamped by a known Consumer → drop.
		$entry = $this->firehose_entry( 'job', 'work', [] );
		$message = $this->msg( '', $entry );
		$this->jr->fill( $message );
		$this->assertCount( 0, $this->sink->captured );

		$message = $this->msg( 'random-node-name', $entry );
		$this->jr->fill( $message );
		$this->assertCount( 0, $this->sink->captured );
	}

	public function test_non_job_entry_dropped(): void {
		$entry = [ 'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => 'just a message', 'ts' => 1 ];
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );
		$this->assertCount( 0, $this->sink->captured );
	}

	public function test_firehose_entry_without_m_dropped(): void {
		$entry = [ 'n' => 1, 'rid' => 'r1', 'k' => 'job', 'ts' => 1 ];
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );
		$this->assertCount( 0, $this->sink->captured );
	}

	public function test_invalid_handler_name_dropped(): void {
		$entry = $this->firehose_entry( 'job', '1bad-leading-digit', [] );
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );
		$this->assertCount( 0, $this->sink->captured );
	}

	public function test_non_array_parameters_dropped(): void {
		$entry = [
			'n'   => 1, 'rid' => 'r1', 'k' => 'job', 'ts' => 1,
			'm'   => [ 'type' => 'job', 'handler' => 'work', 'parameters' => 'not an array' ],
		];
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );
		$this->assertCount( 0, $this->sink->captured );
	}

	public function test_oversized_entry_dropped(): void {
		// 33MB entry > MAX_JOB_SIZE 32MB.
		$entry = $this->firehose_entry( 'job', 'big_job', [ 'data' => str_repeat( 'x', 33 * 1024 * 1024 ) ] );
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );
		$this->assertCount( 0, $this->sink->captured );
	}

	public function test_non_struct_message_dropped(): void {
		$m                  = Message::new_message();
		$m[ Message::TYPE ] = Message::TM_BYTESTREAM;
		$m[ Message::FROM ] = 'firehose:consumer';
		$m[ Message::VALUE ] = $this->firehose_entry( 'job', 'work', [] );
		$this->jr->fill( $m );
		$this->assertCount( 0, $this->sink->captured );
	}

	public function test_non_array_value_dropped(): void {
		$m                  = Message::new_message();
		$m[ Message::TYPE ] = Message::TM_STRUCT;
		$m[ Message::FROM ] = 'firehose:consumer';
		$m[ Message::VALUE ] = 'not an array';
		$this->jr->fill( $m );
		$this->assertCount( 0, $this->sink->captured );
	}

	public function test_parameters_default_to_empty_array(): void {
		$entry = [
			'n'   => 1, 'rid' => 'r1', 'k' => 'job', 'ts' => 1,
			'm'   => [ 'type' => 'job', 'handler' => 'work' ], // no parameters key
		];
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( [], $this->sink->captured[0][ Message::VALUE ]['parameters'] );
	}

	public function test_ts_falls_back_to_entry_ts(): void {
		$entry = [
			'n'   => 1, 'rid' => 'r1', 'k' => 'job', 'ts' => 1700000123.0,
			'm'   => [ 'type' => 'job', 'handler' => 'work', 'parameters' => [] ],
			// inner body has no `ts` — JobRouter should fall back to entry.ts
		];
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( 1700000123.0, $this->sink->captured[0][ Message::VALUE ]['ts'] );
	}
}
