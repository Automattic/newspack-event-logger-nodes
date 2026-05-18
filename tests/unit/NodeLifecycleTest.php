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

use Newspack_Event_Logger_Nodes\AutoTuner;
use Newspack_Event_Logger_Nodes\FlameBuilder;
use Newspack_Event_Logger_Nodes\HealthCheckTick;
use Newspack_Event_Logger_Nodes\JobRouter;
use Newspack_Event_Logger_Nodes\JobWorker;
use Newspack_Event_Logger_Nodes\RemoteSource;
use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\StreamMerger;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\CaptureSink;
use PHPUnit\Framework\Attributes\DataProvider;

class NodeLifecycleTest extends TestCase {

	/**
	 * @return array<string, array{0: \Closure}>
	 */
	public static function node_factories(): array {
		return [
			'AutoTuner'       => [ static fn () => new AutoTuner() ],
			'FlameBuilder'    => [ static fn () => new FlameBuilder() ],
			'HealthCheckTick' => [ static fn () => new HealthCheckTick() ],
			'JobRouter'       => [ static fn () => new JobRouter() ],
			'JobWorker'       => [ static fn () => new JobWorker() ],
			'RequestBuilder'  => [ static fn () => new RequestBuilder() ],
			'RemoteSource'    => [ static fn () => new RemoteSource( 'site', 'https://example.test' ) ],
			'StreamMerger'    => [ static fn () => new StreamMerger( "firehose" ) ],
		];
	}

	#[DataProvider( 'node_factories' )]
	public function test_remove_node_releases_all_refs_and_destructs( \Closure $factory ): void {
		$node = $factory();
		$weak = \WeakReference::create( $node );

		// remove_node cascades sibling-CI cleanup + close_handle + (for
		// Timer-bearing subclasses) stop_timer deferred onto Core's
		// closing queue. run_closing drains the queue → EventFramework
		// $timers drops its back-ref. unset releases the last local
		// ref → refcount=0 → __destruct fires synchronously.
		$node->remove_node();
		Core::run_closing();
		$node = null;
		\gc_collect_cycles();

		$this->assertNull(
			$weak->get(),
			'Node must reach refcount=0 after remove_node() + Core::run_closing() + unset()'
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
		$capture = new CaptureSink();
		$node->sink( $capture );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_ERROR;
		$msg[ Message::FROM ]  = 'upstream';
		$msg[ Message::TO ]    = 'downstream';
		$msg[ Message::VALUE ] = "NOT_AVAILABLE\n";

		$node->fill( $msg );

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
		$capture = new CaptureSink();
		$node->sink( $capture );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_EOF;
		$msg[ Message::FROM ]  = 'upstream';
		$msg[ Message::TO ]    = 'downstream';
		$msg[ Message::VALUE ] = '';

		$node->fill( $msg );

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
	 * + process_sse_chunk, not via fill). HealthCheckTick is timer-
	 * driven: fill() only acts on TM_INFO+KEY=TIMER and ignores the
	 * rest. AutoTuner only acts on TM_STRUCT (drops every other type by
	 * design). Sending TM_ERROR / TM_EOF through these doesn't exercise
	 * an error-propagation trail — skip rather than assert on a no-op.
	 */
	private function is_transit_node( object $node ): bool {
		return ! (
			$node instanceof RemoteSource
			|| $node instanceof HealthCheckTick
			|| $node instanceof AutoTuner
		);
	}
}
