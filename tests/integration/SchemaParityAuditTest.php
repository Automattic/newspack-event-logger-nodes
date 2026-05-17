<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Schema parity audit (M1 acceptance gate).
 *
 * For every legacy SSE controller's transform_line / Inflight payload
 * shape, verify the new compact-summary written by RequestBuilder
 * (Task 22) covers every field. If a legacy controller exposes a
 * field that the new feed lacks, this test MUST fail — preventing
 * M5 deletion until the compact summary catches up.
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

	public function test_inflight_snapshot_covers_legacy_inflight_fields(): void {
		$rb = new RequestBuilder();
		$rb->prime_inflight_for_testing(
			[
				'rid-1' => [
					'url'            => '/x',
					'request_method' => 'GET',
					'timestamp'      => 0,
					'current_hook'   => 'init',
				],
			]
		);
		$snap = $rb->inflight_snapshot();
		$this->assertNotEmpty( $snap );
		$row = $snap[0];

		// Legacy InflightTracker::get_active fields used by gyroscope-stream:
		$expected_fields = [ 'rid', 'method', 'url', 'start_time', 'state', 'current' ];
		foreach ( $expected_fields as $field ) {
			$this->assertArrayHasKey(
				$field,
				$row,
				"inflight snapshot is missing legacy field '{$field}' — fix RequestBuilder::inflight_snapshot BEFORE deleting gyroscope-stream-controller in M5"
			);
		}
	}
}
