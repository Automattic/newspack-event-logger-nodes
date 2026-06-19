<?php
/**
 * Tests for Remote_Job_Rewrite_Node — the hub-side `k:"job"` -> `k:"remote_job"`
 * rewrite on aggregated firehose entries (relocated from the deleted
 * Stream_Merger's `newspack_nodes/aggregator_ingest_line` filter).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Remote_Job_Rewrite_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Remote_Job_Rewrite_Node::class )]
class RemoteJobRewriteTest extends TestCase {

	private Remote_Job_Rewrite_Node $node;
	private Capture_Sink_Node $sink;

	protected function setUp(): void {
		parent::setUp();
		$this->node = new Remote_Job_Rewrite_Node();
		$this->node->name( 'remote-job-rewrite' );
		$this->sink = new Capture_Sink_Node();
		$this->node->sink( $this->sink );
		$this->node->connect_node( 'jobs:partition' );
	}

	/** Build a TM_STRUCT Message whose VALUE is the firehose entry array. */
	private function msg( $value ): array {
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_STRUCT;
		$m[ Message::VALUE ] = $value;
		return $m;
	}

	public function test_job_entry_is_rewritten_to_remote_job_and_forwarded(): void {
		$m = $this->msg( [ 'k' => 'job', 'm' => 'x' ] );

		$this->node->fill( $m );

		$this->assertCount( 1, $this->sink->captured );
		$out = $this->sink->captured[0];
		$this->assertSame( 'remote_job', $out[ Message::VALUE ]['k'] );
		$this->assertSame( 'x', $out[ Message::VALUE ]['m'] );
		$this->assertSame( 'jobs:partition', $out[ Message::TO ] );
	}

	public function test_non_job_entry_is_forwarded_unchanged(): void {
		$m = $this->msg( [ 'k' => 'flame', 'm' => 'y' ] );

		$this->node->fill( $m );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( [ 'k' => 'flame', 'm' => 'y' ], $this->sink->captured[0][ Message::VALUE ] );
	}

	public function test_non_array_value_passes_through_unchanged(): void {
		$m = $this->msg( 'not-an-array' );

		$this->node->fill( $m );

		$this->assertCount( 1, $this->sink->captured );
		$this->assertSame( 'not-an-array', $this->sink->captured[0][ Message::VALUE ] );
	}

	public function test_oversized_post_rewrite_message_is_dropped(): void {
		// Pad so the packed message exceeds the PIPE_BUF cap after rewrite.
		$big = \str_repeat( 'x', Partition_Node::MAX_LINE_SIZE + 1024 );
		$m   = $this->msg( [ 'k' => 'job', 'm' => $big ] );

		$this->assertGreaterThan( Partition_Node::MAX_LINE_SIZE, Message::packed_size( $m ) );

		$this->node->fill( $m );

		$this->assertCount( 0, $this->sink->captured );
	}
}
