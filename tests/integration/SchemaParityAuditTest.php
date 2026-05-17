<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\InflightTracker;
use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Rest\RequestsStreamController;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\Group;

/**
 * Schema parity audit (M1 acceptance gate).
 *
 * For every legacy SSE controller's transform_line / Inflight payload
 * shape, verify the new compact-summary written by RequestBuilder
 * (Task 22) covers every field AND emits the same VALUE the legacy
 * emitter would have produced from the same input. If a legacy
 * controller exposes a field that the new feed lacks, OR a value
 * differs, this test MUST fail — preventing M5 deletion until
 * RequestBuilder is byte-for-byte equivalent to legacy.
 */
#[Group( 'parity' )]
class SchemaParityAuditTest extends TestCase {

	/**
	 * @return array<string,mixed> Full request envelope, matching what
	 *                              RequestBuilder hands to transform_line.
	 */
	private function sample_full_request(): array {
		return [
			'rid'            => 'rid-xyz',
			'url'            => '/some/path?q=1',
			'request_method' => 'POST',
			'timestamp'      => 1747401234.0,
			'duration_ms'    => 87,
			'status_code'    => 201,
			'error_status'   => '-',
			'remote_addr'    => '10.0.0.1',
			'user_agent'     => 'TestAgent/1.0',
		];
	}

	/**
	 * Drive RequestBuilder.fill() with the wire-shaped TM_STRUCT message a
	 * worker would emit per firehose entry. Mirrors the producer convention:
	 * KEY = rid, VALUE = entry.
	 *
	 * @param array<int,array<string,mixed>> $entries Firehose entries (Message::VALUE shape).
	 */
	private function drive_request_builder( RequestBuilder $rb, string $rid, array $entries ): void {
		foreach ( $entries as $e ) {
			$msg                   = Message::new_message();
			$msg[ Message::TYPE ]  = Message::TM_STRUCT;
			$msg[ Message::KEY ]   = $rid;
			$msg[ Message::VALUE ] = $e;
			$rb->fill( $msg );
		}
	}

	public function test_compact_summary_covers_every_legacy_transform_line_field(): void {
		$rb      = new RequestBuilder();
		$compact = $rb->build_compact_summary( (object) $this->sample_full_request() );

		// Inline copy of legacy transform shape from
		// requests-stream-controller::transform_line (preserved here as the
		// authority; the controller will be deleted in M5).
		$legacy_fields = [
			'rid', 'method', 'url', 'start_time', 'end_time',
			'duration_ms', 'status_code', 'state', 'error_status',
			'remote_addr', 'user_agent',
		];

		foreach ( $legacy_fields as $field ) {
			$this->assertArrayHasKey(
				$field,
				$compact,
				"compact summary is missing legacy field '{$field}' — fix RequestBuilder::build_compact_summary BEFORE deleting requests-stream-controller in M5"
			);
		}
	}

	public function test_compact_summary_value_equivalence_against_legacy_transform_line(): void {
		// Build the canonical wire-shaped envelope: the packed Message a worker
		// would write to requests.log, then unpacked again so PHP's native
		// types match what production assemblers see. (Without the roundtrip
		// an int-valued float in the fixture stays a float here but becomes an
		// int after JSON serialization — that asymmetry is real in production
		// too, so both legacy and new must see the same post-roundtrip values.)
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = $this->sample_full_request();
		$packed                = Message::packed( $msg );
		$unpacked              = Message::unpacked( $packed );
		$envelope_on_wire      = $unpacked[ Message::VALUE ];

		$legacy = RequestsStreamController::transform_line( $packed, 0 );
		$this->assertIsArray( $legacy, 'legacy transform_line returned null — fixture too lean' );

		$rb  = new RequestBuilder();
		$new = $rb->build_compact_summary( (object) $envelope_on_wire );

		// Every legacy field's value must match the new field's value.
		foreach ( $legacy as $field => $legacy_value ) {
			$this->assertArrayHasKey(
				$field,
				$new,
				"field '{$field}' present in legacy transform_line but missing from build_compact_summary"
			);
			$this->assertSame(
				$legacy_value,
				$new[ $field ],
				"field '{$field}': legacy=" . \var_export( $legacy_value, true )
				. ' new=' . \var_export( $new[ $field ], true )
			);
		}
	}

	public function test_inflight_snapshot_covers_legacy_inflight_fields(): void {
		$rb = new RequestBuilder();
		$rb->prime_inflight_for_testing(
			[
				'rid-1' => [
					'url'            => '/x',
					'request_method' => 'GET',
					'timestamp'      => 1747401200.0,
					'what'           => 'init',
					'last_log_ts'    => 1747401201.5,
					'tracker_ts'     => 1747401202.0,
					'remote_addr'    => '10.0.0.1',
					'user_agent'     => 'UA/1.0',
				],
			]
		);
		$snap = $rb->inflight_snapshot();
		$this->assertNotEmpty( $snap );
		$row = $snap[0];

		// Inline copy of the full 12-field legacy InflightTracker::get_active
		// shape (preserved here as the authority; the tracker + gyroscope-stream
		// controller will be deleted in M5).
		$expected_fields = [
			'rid', 'method', 'url', 'state', 'what',
			'time_ms', 'est_ms', 'start_time', 'last_log_ts', 'lag_ms',
			'remote_addr', 'user_agent',
		];
		foreach ( $expected_fields as $field ) {
			$this->assertArrayHasKey(
				$field,
				$row,
				"inflight snapshot is missing legacy field '{$field}' — fix RequestBuilder::inflight_snapshot BEFORE deleting gyroscope-stream-controller in M5"
			);
		}
	}

	public function test_inflight_snapshot_value_equivalence_against_legacy_get_active(): void {
		// Drive both the legacy InflightTracker and the new RequestBuilder
		// through the same lifecycle of firehose entries; then assert their
		// active-snapshot outputs match per-field.
		//
		// Each entry is a Message::VALUE (the entry array). For the new
		// RequestBuilder.fill() path, KEY = rid (the wire convention) and the
		// Message is TM_STRUCT.
		$rid       = 'rid-active';
		$start_ts  = 1747401234.0;
		$req_ts    = 1747401234.2;
		$env_ts    = 1747401234.5;
		$hook_ts   = 1747401234.7;

		// Lifecycle: process (start) to seed RequestBuilder; request to bind
		// method+url for both; environment_v2 for remote_addr+user_agent; a
		// hook start to populate the stack (so legacy `state`/`what` aren't
		// the default 'process'/'').
		$entries = [
			[ 'n' => 1, 'k' => 'process (start)', 'm' => '99 on host', 'ts' => $start_ts ],
			[ 'n' => 2, 'k' => 'request',         'm' => 'GET /x',    'ts' => $req_ts ],
			[ 'n' => 3, 'k' => 'environment_v2',  'm' => 'REMOTE_ADDR => "10.0.0.1"', 'ts' => $env_ts ],
			[ 'n' => 4, 'k' => 'environment_v2',  'm' => 'HTTP_USER_AGENT => "TestAgent/1.0"', 'ts' => $env_ts ],
			[ 'n' => 5, 'k' => 'render (start)',  'm' => 'index.html', 'l' => 'index.html', 'ts' => $hook_ts ],
		];

		$tracker = new InflightTracker();
		foreach ( $entries as $e ) {
			$tracker->process( [ 'rid' => $rid ] + $e );
		}

		$rb = new RequestBuilder();
		$this->drive_request_builder( $rb, $rid, $entries );

		$legacy_active = $tracker->get_active();
		$new_snap      = $rb->inflight_snapshot();

		$this->assertCount( 1, $legacy_active, 'legacy InflightTracker should have one active rid' );
		$this->assertCount( 1, $new_snap, 'new inflight_snapshot should have one active rid' );

		$legacy_row = $legacy_active[0];
		$new_row    = $new_snap[0];

		// Derived numeric fields (time_ms, est_ms, lag_ms) depend on
		// microtime(true) which differs slightly between the two pipelines.
		// 50ms tolerance comfortably covers the wall-clock skew while still
		// catching real arithmetic drift.
		$tolerance_fields = [ 'time_ms', 'est_ms', 'lag_ms' ];

		foreach ( $legacy_row as $field => $legacy_value ) {
			$this->assertArrayHasKey(
				$field,
				$new_row,
				"field '{$field}' present in legacy InflightTracker::get_active but missing from RequestBuilder::inflight_snapshot"
			);
			if ( \in_array( $field, $tolerance_fields, true ) ) {
				$this->assertEqualsWithDelta(
					$legacy_value,
					$new_row[ $field ],
					50.0,
					"field '{$field}': legacy={$legacy_value} new={$new_row[$field]} (tolerance 50ms)"
				);
			} else {
				$this->assertSame(
					$legacy_value,
					$new_row[ $field ],
					"field '{$field}': legacy=" . \var_export( $legacy_value, true )
					. ' new=' . \var_export( $new_row[ $field ], true )
				);
			}
		}
	}

	public function test_inflight_snapshot_surfaces_runaway_requests_like_legacy(): void {
		// The Perl gyroscope shows runaway requests reliably — surfacing
		// them is a feature, not a bug. Both legacy InflightTracker
		// (MAX_STACK_DEPTH=100) and RequestBuilder (MAX_STACK_DEPTH=50)
		// cap the stack but keep the request visible. This test pins
		// that contract so a future change can't regress runaway visibility
		// without breaking the parity gate.
		$rid      = 'rid-runaway';
		$start_ts = 1747401234.0;

		// Drive both with a deep chain of `start` keywords that exceeds
		// RequestBuilder's stack-depth cap. Legacy caps at 100 frames; new
		// caps at 50 and flags is_runaway — but neither evicts the request.
		$entries = [
			[ 'n' => 1, 'k' => 'process (start)', 'm' => '99 on host', 'ts' => $start_ts ],
			[ 'n' => 2, 'k' => 'request',         'm' => 'GET /runaway', 'ts' => $start_ts ],
		];
		for ( $i = 1; $i <= 60; $i++ ) {
			$entries[] = [
				'n'  => 2 + $i,
				'k'  => "f{$i} (start)",
				'm'  => "frame{$i}",
				'l'  => "frame{$i}",
				'ts' => $start_ts + ( $i / 1000.0 ),
			];
		}

		$tracker = new InflightTracker();
		foreach ( $entries as $e ) {
			$tracker->process( [ 'rid' => $rid ] + $e );
		}

		$rb = new RequestBuilder();
		$this->drive_request_builder( $rb, $rid, $entries );

		$legacy_active = $tracker->get_active();
		$new_snap      = $rb->inflight_snapshot();

		$this->assertCount(
			1,
			$legacy_active,
			'legacy InflightTracker keeps runaway requests visible — by design'
		);
		$this->assertCount(
			\count( $legacy_active ),
			$new_snap,
			'RequestBuilder::inflight_snapshot must retain runaway requests so the gyroscope '
			. 'dashboard can surface them — matches legacy InflightTracker and the Perl gyroscope.'
		);
	}
}
