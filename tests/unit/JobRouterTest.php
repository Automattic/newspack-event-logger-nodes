<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Job_Router_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;

/**
 * JobRouter is a pure router: it pulls job-shaped entries from the firehose
 * and jobintake sources, normalizes them to {k, handler, parameters, ts},
 * and forwards via parent::fill to its target (jobs:partition in topology).
 * It never dispatches handlers itself; that's JobWorker's job.
 *
 * These tests sink JobRouter into a Capture_Sink_Node so we can assert what came
 * out, then walk through the matrix of body shape × kind × validity.
 */
#[CoversClass( Job_Router_Node::class )]
class JobRouterTest extends TestCase {
	private const FRESH_AGE_SECONDS = 12.5;

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
	private function firehose_entry( string $kind, string $handler, array $parameters, ?float $timestamp = null ): array {
		$timestamp = null === $timestamp ? Core::$now - self::FRESH_AGE_SECONDS : $timestamp;
		return [
			'n'   => 1,
			'rid' => 'r1',
			'k'   => $kind,
			'm'   => [
				'type'       => $kind,
				'handler'    => $handler,
				'parameters' => $parameters,
			],
			'ts'  => $timestamp,
		];
	}

	/** Jobintake entry shape: flat (no nested `m`). */
	private function jobintake_entry( string $kind, string $handler, array $parameters, ?float $timestamp = null ): array {
		$timestamp = null === $timestamp ? Core::$now - self::FRESH_AGE_SECONDS : $timestamp;
		return [
			'k'          => $kind,
			'handler'    => $handler,
			'parameters' => $parameters,
			'ts'         => $timestamp,
		];
	}

	public function test_age_is_not_job_routers_concern(): void {
		// Staleness moved to the Age_Sieve after job-router in the topology;
		// an ancient body ts routes through untouched.
		$entry       = $this->jobintake_entry( 'job', 'old_job', [] );
		$entry['ts'] = Core::$now - 86400.0;
		$this->jr->fill( $this->msg( 'jobintake:consumer', $entry ) );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( 'old_job', $this->sink->captured[0][ Message::VALUE ]['handler'] );
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
				'ts'         => $entry['ts'],
			],
			$this->sink->captured[0][ Message::VALUE ]
		);
	}

	public function test_firehose_top_level_id_survives_into_normalized_record(): void {
		// First-class job identity: the top-level `id` (sibling of `m`) must
		// reach the written jobs entry so Job_Worker keys jobstats by handler:id.
		$entry            = $this->firehose_entry( 'job', 'films_import', [ 'stage' => 'films' ] );
		$entry['m']['id'] = 'films-8842'; // Producers put the id IN the job body.
		$this->jr->fill( $this->msg( 'firehose:consumer', $entry ) );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( 'films-8842', $this->sink->captured[0][ Message::VALUE ]['id'] );
	}

	public function test_jobintake_top_level_id_survives_into_normalized_record(): void {
		$entry       = $this->jobintake_entry( 'job', 'process_image', [ 'url' => '/x.jpg' ] );
		$entry['id'] = 'img-5309';
		$this->jr->fill( $this->msg( 'jobintake:consumer', $entry ) );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( 'img-5309', $this->sink->captured[0][ Message::VALUE ]['id'] );
	}

	/**
	 * `Job_Worker_Node::schedule_retry()` reads `retries`/`attempt` off the
	 * jobs.log entry, and `Job_Intake::write_job()` writes them there — so a
	 * normalizer that rebuilds a fixed record instead of overlaying drops the
	 * opt-in between the two. A retried job then re-enters with retries=0 and
	 * goes straight to the poison path on its next throw, capping every
	 * configured retry budget at one attempt wherever this plugin is active.
	 *
	 * Seeded distinct from the 0 defaults, and from each other, so a fix that
	 * carries only one field or reads the wrong one still fails.
	 */
	public function test_retry_and_batch_fields_survive_normalization(): void {
		$entry              = $this->jobintake_entry( 'job', 'films_import', [ 'stage' => 'films' ] );
		$entry['retries']   = 7;
		$entry['attempt']   = 3;
		$entry['batch']     = 'batch-4417';
		$entry['key']       = 'films:eu-west';
		$this->jr->fill( $this->msg( 'jobintake:consumer', $entry ) );

		$this->assertCount( 1, $this->sink->captured );
		$out = $this->sink->captured[0][ Message::VALUE ];
		$this->assertSame( 7, $out['retries'] );
		$this->assertSame( 3, $out['attempt'] );
		$this->assertSame( 'batch-4417', $out['batch'] );
		$this->assertSame( 'films:eu-west', $out['key'] );
	}

	/** The same fields ride a firehose-wrapped body, under `m`. */
	public function test_retry_fields_survive_from_a_firehose_wrapped_body(): void {
		$entry                   = $this->firehose_entry( 'job', 'films_import', [] );
		$entry['m']['retries']   = 5;
		$entry['m']['batch']     = 'batch-9021';
		$this->jr->fill( $this->msg( 'firehose:consumer', $entry ) );

		$this->assertCount( 1, $this->sink->captured );
		$out = $this->sink->captured[0][ Message::VALUE ];
		$this->assertSame( 5, $out['retries'] );
		$this->assertSame( 'batch-9021', $out['batch'] );
	}

	/** Absent optional fields stay absent — no zero-valued keys invented. */
	public function test_absent_retry_fields_are_not_invented(): void {
		$entry = $this->jobintake_entry( 'job', 'plain_job', [] );
		$this->jr->fill( $this->msg( 'jobintake:consumer', $entry ) );

		$this->assertCount( 1, $this->sink->captured );
		$out = $this->sink->captured[0][ Message::VALUE ];
		$this->assertArrayNotHasKey( 'retries', $out );
		$this->assertArrayNotHasKey( 'attempt', $out );
		$this->assertArrayNotHasKey( 'batch', $out );
		$this->assertArrayNotHasKey( 'key', $out );
	}

	public function test_absent_id_omits_id_key_from_normalized_record(): void {
		$entry = $this->firehose_entry( 'job', 'plain_job', [] );
		$this->jr->fill( $this->msg( 'firehose:consumer', $entry ) );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertArrayNotHasKey( 'id', $this->sink->captured[0][ Message::VALUE ] );
	}

	public function test_nested_body_timestamp_takes_precedence_over_entry_timestamp(): void {
		$entry = $this->firehose_entry( 'job', 'sync_user', [ 'id' => 42 ], Core::$now - 24.5 );
		$entry['m']['ts'] = Core::$now - 5.25;
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 1, $this->sink->captured );
		$out = $this->sink->captured[0];
		$this->assertSame(
			[
				'k'          => 'job',
				'handler'    => 'sync_user',
				'parameters' => [ 'id' => 42 ],
				'ts'         => $entry['m']['ts'],
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
				'ts'         => $entry['ts'],
			],
			$this->sink->captured[0][ Message::VALUE ]
		);
	}

	public function test_flat_remote_kind_is_preserved(): void {
		$entry = $this->jobintake_entry( 'remote_job', 'priv_op', [] );
		$message = $this->msg( 'jobintake:consumer', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( 'remote_job', $this->sink->captured[0][ Message::VALUE ]['k'] );
	}

	public function test_source_name_does_not_gate_valid_job(): void {
		$entry = $this->firehose_entry( 'job', 'work', [] );
		$message = $this->msg( '', $entry );
		$this->jr->fill( $message );

		$message = $this->msg( 'random-node-name', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 2, $this->sink->captured );
		$this->assertSame( 'work', $this->sink->captured[0][ Message::VALUE ]['handler'] );
		$this->assertSame( 'work', $this->sink->captured[1][ Message::VALUE ]['handler'] );
	}

	public function test_non_job_entry_dropped(): void {
		$entry = [ 'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => 'just a message', 'ts' => Core::$now - 11.25 ];
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );
		$this->assertCount( 0, $this->sink->captured );
	}

	public function test_job_without_handler_dropped(): void {
		$entry = [ 'n' => 1, 'rid' => 'r1', 'k' => 'job', 'ts' => Core::$now - 10.75 ];
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
			'n'   => 1, 'rid' => 'r1', 'k' => 'job', 'ts' => Core::$now - 9.75,
			'm'   => [ 'type' => 'job', 'handler' => 'work', 'parameters' => 'not an array' ],
		];
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
			'n'   => 1, 'rid' => 'r1', 'k' => 'job', 'ts' => Core::$now - 8.25,
			'm'   => [ 'type' => 'job', 'handler' => 'work' ], // no parameters key
		];
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( [], $this->sink->captured[0][ Message::VALUE ]['parameters'] );
	}

	public function test_ts_falls_back_to_entry_ts(): void {
		$entry_timestamp = Core::$now - 23.75;
		$entry = [
			'n'   => 1, 'rid' => 'r1', 'k' => 'job', 'ts' => $entry_timestamp,
			'm'   => [ 'type' => 'job', 'handler' => 'work', 'parameters' => [] ],
			// inner body has no `ts` — JobRouter should fall back to entry.ts
		];
		$message = $this->msg( 'firehose:consumer', $entry );
		$this->jr->fill( $message );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( $entry_timestamp, $this->sink->captured[0][ Message::VALUE ]['ts'] );
	}





	public function test_garbage_body_timestamps_route_through_as_data(): void {
		// The envelope TIMESTAMP (Age_Sieve's criterion) gates age; a garbage
		// body ts is just data for downstream coercion to zero out.
		$entry       = $this->jobintake_entry( 'job', 'weird_ts_job', [] );
		$entry['ts'] = 'not-a-timestamp-913';
		$this->jr->fill( $this->msg( 'jobintake:consumer', $entry ) );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( 'not-a-timestamp-913', $this->sink->captured[0][ Message::VALUE ]['ts'] );
	}

	public function test_node_schema_declares_no_arguments(): void {
		$this->assertSame( [], Job_Router_Node::node_schema()['arguments'], 'staleness moved to the Age_Sieve; Job_Router takes no arguments' );
	}
}
