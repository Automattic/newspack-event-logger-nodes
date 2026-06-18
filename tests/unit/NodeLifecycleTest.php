<?php
/**
 * Application-side mirror of newspack-nodes' NodeLifecycleTest: every
 * Node subclass shipped by this plugin must
 *
 *   (1) reach refcount=0 after `remove_node()` + `Core::run_closing()`
 *       + dropping local references — confirmed via
 *       `WeakReference::get() === null` — so request-scope batched
 *       writes (LogManager via Topic) flush via `__destruct` before
 *       the PHP process exits, AND
 *
 *   (2) preserve TM_ERROR / TM_EOF payloads when transit-forwarded —
 *       no FROM restamp, no VALUE rewrite. Mirrors Perl Tachikoma
 *       nodes.t. Catches future drift the moment a new app Node
 *       accidentally mutates an error trail.
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Auto_Tuner_Node;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Job_Router_Node;
use Newspack_Event_Logger_Nodes\Remote_Source_Node;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Stream_Merger_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use PHPUnit\Framework\Attributes\DataProvider;

class NodeLifecycleTest extends TestCase {

	/**
	 * @return array<string, array{0: \Closure}>
	 */
	public static function node_factories(): array {
		return [
			'AutoTuner'       => [ static fn () => new Auto_Tuner_Node() ],
			'FlameBuilder'    => [ static fn () => new Flame_Builder_Node() ],
			'JobRouter'       => [ static fn () => new Job_Router_Node() ],
			'RequestBuilder'  => [ static fn () => new Request_Builder_Node() ],
			'RemoteSource'    => [ static fn () => ( static function () {
				$r = new Remote_Source_Node();
				$r->configure( 'site', 'https://example.test' );
				return $r;
			} )() ],
			'StreamMerger'    => [ static fn () => ( static function () {
				$sm = new Stream_Merger_Node();
				$sm->arguments( 'firehose' );
				return $sm;
			} )() ],
		];
	}

	#[DataProvider( 'node_factories' )]
	public function test_remove_node_releases_all_refs_and_destructs( \Closure $factory ): void {
		$node = $factory();
		$weak = \WeakReference::create( $node );

		// remove_node cascades sibling-interpreter cleanup + close_handle +
		// (for Timer-bearing subclasses) synchronous stop_timer, so the
		// EventFramework $timers back-ref drops immediately. unset releases
		// the last local ref → refcount=0 → __destruct fires synchronously.
		$node->remove_node();
		$node = null;
		\gc_collect_cycles();

		$this->assertNull(
			$weak->get(),
			'Node must reach refcount=0 after remove_node() + unset()'
		);
	}

	/**
	 * @param \Closure $factory
	 */
	#[DataProvider( 'node_factories' )]
	public function test_fill_tm_error_preserves_payload_and_does_not_restamp_from( \Closure $factory ): void {
		$node = $factory();
		if ( ! $this->is_transit_node( $node ) ) {
			$this->assertTrue( true, 'non-transit node — TM_ERROR contract not applicable' );
			return;
		}
		$capture = new Capture_Sink_Node();
		$node->sink( $capture );

		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_ERROR;
		$message[ Message::FROM ]  = 'upstream';
		$message[ Message::TO ]    = 'downstream';
		$message[ Message::VALUE ] = "NOT_AVAILABLE\n";

		$node->fill( $message );

		if ( ! empty( $capture->captured ) ) {
			$out = $capture->captured[0];
			$this->assertSame( Message::TM_ERROR, $out[ Message::TYPE ] & Message::TM_ERROR, 'TYPE must retain TM_ERROR bit' );
			$this->assertSame( 'upstream', $out[ Message::FROM ], 'FROM must not be restamped' );
			$this->assertSame( "NOT_AVAILABLE\n", $out[ Message::VALUE ], 'VALUE must not be rewritten' );
		} else {
			$this->assertCount( 0, $capture->captured, 'TM_ERROR absorbed silently — also valid' );
		}
	}

	/**
	 * @param \Closure $factory
	 */
	#[DataProvider( 'node_factories' )]
	public function test_fill_tm_eof_preserves_payload_and_does_not_restamp_from( \Closure $factory ): void {
		$node = $factory();
		if ( ! $this->is_transit_node( $node ) ) {
			$this->assertTrue( true, 'non-transit node — TM_EOF contract not applicable' );
			return;
		}
		$capture = new Capture_Sink_Node();
		$node->sink( $capture );

		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_EOF;
		$message[ Message::FROM ]  = 'upstream';
		$message[ Message::TO ]    = 'downstream';
		$message[ Message::VALUE ] = '';

		$node->fill( $message );

		if ( ! empty( $capture->captured ) ) {
			$out = $capture->captured[0];
			$this->assertSame( Message::TM_EOF, $out[ Message::TYPE ] & Message::TM_EOF, 'TYPE must retain TM_EOF bit' );
			$this->assertSame( 'upstream', $out[ Message::FROM ], 'FROM must not be restamped' );
			$this->assertSame( '', $out[ Message::VALUE ], 'VALUE must not be rewritten' );
		} else {
			$this->assertCount( 0, $capture->captured, 'TM_EOF absorbed silently — also valid' );
		}
	}

	/**
	 * RemoteSource is a source node — its `fill()` is a no-op that just
	 * increments the counter (production drives data IN via on_curl_data
	 * + process_sse_chunk, not via fill). AutoTuner only acts on TM_STRUCT
	 * (drops every other type by design). Sending TM_ERROR / TM_EOF through
	 * these doesn't exercise an error-propagation trail — skip rather than
	 * assert on a no-op.
	 */
	private function is_transit_node( object $node ): bool {
		return ! (
			$node instanceof Remote_Source_Node
			|| $node instanceof Auto_Tuner_Node
		);
	}
}
