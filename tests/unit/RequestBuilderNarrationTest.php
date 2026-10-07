<?php
/**
 * The request builder's narration on its worker's own record: the checkpoint,
 * the restore and each fold as spans, the operator's purge as a point, and a
 * once-a-minute rollup of what it assembled, wrote and let go.
 *
 * @package Newspack_Event_Logger_Nodes\Tests\Unit
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Narration;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Worker_Should_Stop;

#[CoversClass( Request_Builder_Node::class )]
#[CoversClass( Narration::class )]
class RequestBuilderNarrationTest extends TestCase {

	/** A lifetime's first tick, a minute boundary in September 2026. */
	private const FROM = 1_790_000_040;

	protected function setUp(): void {
		parent::setUp();
		( new Router_Node() )->name( Node_Names::ROUTER );
		// The cache lays its rotation grid from the clock it is built at.
		Core::$now = self::FROM;
	}

	/**
	 * A builder over a capture sink, its completed and error targets named.
	 *
	 * @param list<string> $args Positional arguments.
	 */
	private function builder( array $args = [ '100', '3' ], string $name = 'request-builder' ): Request_Builder_Node {
		$rb = new Request_Builder_Node();
		$rb->name( $name );
		$rb->sink( new Capture_Sink_Node() );
		$rb->arguments( $args );
		$rb->set_completed_target( 'completed-7731' );
		$rb->set_errors_target( 'errors-7731' );
		$rb->set_alerts_target( 'alerts-7731' );
		return $rb;
	}

	/**
	 * Feed one firehose line.
	 *
	 * @param array<string,mixed> $extra Additional entry fields.
	 */
	private static function line( Request_Builder_Node $rb, int $n, string $rid, string $k, array $extra = [] ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::KEY ]   = $rid;
		$message[ Message::VALUE ] = \array_merge( [ 'n' => $n, 'rid' => $rid, 'k' => $k, 'ts' => (float) Core::$now ], $extra );
		$rb->fill( $message );
	}

	/**
	 * Open a request, log `$pairs` spans into it, and complete it unless told not to.
	 *
	 * @return int The next unused line number.
	 */
	private static function request( Request_Builder_Node $rb, string $rid, int $pairs, bool $complete = true ): int {
		$n = 1;
		self::line( $rb, $n++, $rid, 'process (start)' );
		self::line( $rb, $n++, $rid, 'request', [ 'm' => "GET https://kea.test/{$rid}" ] );
		for ( $i = 0; $i < $pairs; $i++ ) {
			self::line( $rb, $n++, $rid, 'save (start)' );
			self::line( $rb, $n++, $rid, 'save (complete)', [ 'duration_ms' => 1 + $i ] );
		}
		if ( $complete ) {
			self::line( $rb, $n++, $rid, 'process (complete)', [ 'duration_ms' => 12, 'status_code' => 200 ] );
		}
		return $n;
	}

	/** Run the builder's router tick. */
	private static function router_tick( Request_Builder_Node $rb ): void {
		( new \ReflectionMethod( $rb, 'fire' ) )->invoke( $rb );
	}

	/**
	 * Run `$work` with the clock pinned to `$t`, logged, and return the narration.
	 *
	 * @param int $t Seconds the clock reads, by reference so `$work` can move it.
	 * @return list<array<string,mixed>>
	 */
	private function narrated( int &$t, \Closure $work, ?string $base = null ): array {
		$previous    = Core::$clock;
		Core::$clock = static function () use ( &$t ): float {
			return (float) $t;
		};
		try {
			$entries = $this->logged_in( $base ?? $this->make_temp_dir(), $work );
		} finally {
			Core::$clock = $previous;
		}
		// Every line the request builder tells is named `requests …`.
		return \array_values( \array_filter( $entries, static fn ( array $entry ): bool => \str_starts_with( (string) ( $entry['k'] ?? '' ), 'requests ' ) ) );
	}

	/**
	 * A 595-second worker lifetime at five-second ticks: restore, `$each`
	 * feeding every tick, a checkpoint every thirty seconds, then the stop's
	 * sweep and last checkpoint.
	 *
	 * @param \Closure(int): void $each What one tick feeds, given its clock.
	 */
	private static function lifetime( Request_Builder_Node $rb, int &$t, \Closure $each ): void {
		$rb->restore_state( [] );
		for ( $t = self::FROM; $t < self::FROM + 595; $t += 5 ) {
			Core::$now = $t;
			$each( $t );
			self::router_tick( $rb );
			if ( 0 === ( $t - self::FROM ) % 30 ) {
				$rb->save_state();
			}
		}
		Core::$now = $t;
		$rb->shutdown_sweep();
		$rb->save_state();
	}

	/** @return array<string,int> Category => lines, a span counted once, by its close. */
	private static function lines_by_category( array $narration ): array {
		$told = \array_filter( $narration, static fn ( array $entry ): bool => ! \str_ends_with( (string) $entry['k'], ' (start)' ) );
		return \array_count_values( \array_map( static fn ( array $entry ): string => \str_replace( ' (complete)', '', (string) $entry['k'] ), $told ) );
	}

	/** The `(complete)` `m` of each of one span's closes. */
	private static function closes_of( array $narration, string $span ): array {
		return \array_column( self::entries_of( $narration, "{$span} (complete)" ), 'm' );
	}

	public function test_a_steady_lifetime_narrates_about_thirty_lines(): void {
		$rb = $this->builder();
		$t  = self::FROM;

		$narration = $this->narrated(
			$t,
			function () use ( $rb, &$t ): void {
				self::lifetime( $rb, $t, static fn ( int $at ) => self::request( $rb, "steady-{$at}", 3 ) );
			}
		);

		$lines = self::lines_by_category( $narration );
		$this->assertSame( 11, $lines[ Flame_Tree::REQUESTS_WRITES ] ?? 0, 'one a minute, and the stop\'s' );
		$this->assertSame( 21, $lines[ Flame_Tree::REQUESTS_CHECKPOINT ] ?? 0, 'every thirty seconds, and the stop\'s' );
		$this->assertSame( 1, $lines[ Flame_Tree::REQUESTS_RESTORE ] ?? 0 );
		$this->assertEqualsCanonicalizing( [ Flame_Tree::REQUESTS_RESTORE, Flame_Tree::REQUESTS_CHECKPOINT, Flame_Tree::REQUESTS_WRITES ], \array_keys( $lines ), 'no purge, and nothing near a bound' );
		$writes = self::entries_of( $narration, Flame_Tree::REQUESTS_WRITES );
		$this->assertSame( '9 lines · 1 records · 1 summaries', $writes[0]['m'], 'the first tick\'s request' );
		$this->assertSame( '108 lines · 12 records · 12 summaries', $writes[1]['m'], 'a minute of them' );
		$this->assertSame( 1, $writes[0]['keep'] );
		$this->assertSame( '0 envelopes · 0 entries', self::closes_of( $narration, Flame_Tree::REQUESTS_CHECKPOINT )[0] );
		foreach ( $narration as $entry ) {
			$this->assertSame( 1, $entry['keep'] ?? null, "{$entry['k']} survives a fold" );
		}
	}

	public function test_a_fold_heavy_lifetime_counts_each_folds_trigger_in_the_rollup(): void {
		// Distinct from the 50000 and 20000 defaults, and from each other.
		$rb = $this->builder( [ '100', '3', '22', '14' ] );
		$t  = self::FROM;

		$narration = $this->narrated(
			$t,
			function () use ( $rb, &$t ): void {
				self::lifetime(
					$rb,
					$t,
					static function ( int $at ) use ( $rb ): void {
						// Past the per-request cap alone.
						self::request( $rb, "runaway-{$at}", 6 );
						// Two under the cap whose sum crosses the pool budget.
						$a = self::request( $rb, "pool-a-{$at}", 5, false );
						$b = self::request( $rb, "pool-b-{$at}", 5, false );
						self::line( $rb, $a, "pool-a-{$at}", 'process (complete)', [ 'duration_ms' => 9, 'status_code' => 200 ] );
						self::line( $rb, $b, "pool-b-{$at}", 'process (complete)', [ 'duration_ms' => 9, 'status_code' => 200 ] );
					}
				);
			}
		);

		$lines = self::lines_by_category( $narration );
		$this->assertEqualsCanonicalizing( [ Flame_Tree::REQUESTS_RESTORE, Flame_Tree::REQUESTS_CHECKPOINT, Flame_Tree::REQUESTS_WRITES ], \array_keys( $lines ), 'a fold is no line of its own' );
		$this->assertSame( 33, \array_sum( $lines ), 'as many lines as a steady lifetime' );
		$writes = \array_column( self::entries_of( $narration, Flame_Tree::REQUESTS_WRITES ), 'm' );
		$sum    = static fn ( string $counter ): int => \array_sum( \array_map( static fn ( string $m ): int => \preg_match( "/(?:^| · )(\\d+) {$counter}(?: · |$)/", $m, $hit ) ? (int) $hit[1] : 0, $writes ) );
		$this->assertSame( 119, $sum( 'folded max_entries_per_request' ), 'one a tick' );
		$this->assertSame( 119, $sum( 'folded entry_budget' ), 'one a tick' );
		// Each runaway folds at 14 entries, keeping 10; each pool fold keeps 10 of 12.
		$this->assertSame(
			'492 lines · 36 records · 36 summaries · 12 folded max_entries_per_request · 48 reclaimed max_entries_per_request · 12 folded entry_budget · 24 reclaimed entry_budget',
			$writes[1],
			'a minute of them'
		);
	}

	/** Each envelope let go is a count in the rollup, never a line of its own. */
	public function test_each_envelope_let_go_is_counted_by_why(): void {
		$rb = $this->builder( [ '2', '2' ] );
		$t  = self::FROM;

		$narration = $this->narrated(
			$t,
			function () use ( $rb, &$t ): void {
				Core::$now = $t;
				self::request( $rb, 'stalled-4471', 2, false );
				self::line( $rb, 1, 'nourl-5519', 'process (start)' );
				// Two more crowd the oldest bucket out.
				self::request( $rb, 'crowd-6613', 0, false );
				self::request( $rb, 'crowd-6614', 0, false );
				$t        += 3 * 360;
				Core::$now = $t;
				self::router_tick( $rb );
			}
		);

		$this->assertSame( [], self::entries_of( $narration, Flame_Tree::REQUESTS_EXPIRE ), 'no line per envelope' );
		$this->assertSame(
			'11 lines · 3 records · 3 summaries · 2 crowded out · 1 dropped · 2 timed out',
			self::entries_of( $narration, Flame_Tree::REQUESTS_WRITES )[0]['m'] ?? null
		);
	}

	/**
	 * The rollup is a Partition write, so it can raise the stop: the request
	 * the tick's rotation evicted still goes out, and the stop leaves clean.
	 */
	public function test_a_stop_the_rollup_raises_still_emits_the_evicted_record(): void {
		$rb   = $this->builder( [ '2', '2' ] );
		$sink = $rb->sink();
		\assert( $sink instanceof Capture_Sink_Node );
		$t    = self::FROM;
		$stop = null;

		$narration = $this->narrated(
			$t,
			function () use ( $rb, &$t, &$stop ): void {
				Core::$now = $t;
				self::request( $rb, 'stalled-4481', 2, false );
				$t        += 3 * 360;
				Core::$now = $t;
				$disarm    = self::arm_stop_on_next_write();
				try {
					self::router_tick( $rb );
				} catch ( Worker_Should_Stop $e ) {
					$stop = $e;
				} finally {
					$disarm();
				}
			}
		);

		$this->assertNotNull( $stop, 'the stop still leaves' );
		$this->assertTrue( Worker_Should_Stop::is_clean( $stop ), 'once the tick is done' );
		$records = \array_filter( $sink->captured, static fn ( array $m ): bool => 'stalled-4481' === $m[ Message::KEY ] && '' === $m[ Message::TO ] );
		$this->assertCount( 1, $records, 'the timed-out request is written' );
		$this->assertSame( 'T', \array_values( $records )[0][ Message::VALUE ]['error_status'] ?? null );
		$this->assertSame( [ '6 lines · 1 records · 1 summaries · 1 timed out' ], \array_column( self::entries_of( $narration, Flame_Tree::REQUESTS_WRITES ), 'm' ) );
	}

	public function test_a_purge_says_how_many_it_dropped(): void {
		$rb = $this->builder();
		$t  = self::FROM;

		$narration = $this->narrated(
			$t,
			function () use ( $rb, &$t ): void {
				Core::$now = $t;
				foreach ( [ 'wedged-1', 'wedged-2', 'wedged-3' ] as $rid ) {
					self::request( $rb, $rid, 1, false );
				}
				$rb->purge_cache();
			}
		);

		$this->assertSame( [ 'purged · 3 requests' ], \array_column( self::entries_of( $narration, Flame_Tree::REQUESTS_EXPIRE ), 'm' ) );
	}

	public function test_a_checkpoint_counts_what_it_carries(): void {
		$rb = $this->builder( [ '100', '3', '50000', '24' ] );
		$t  = self::FROM;

		$narration = $this->narrated(
			$t,
			function () use ( $rb, &$t ): void {
				Core::$now = $t;
				self::request( $rb, 'open-2201', 3, false );
				self::request( $rb, 'folded-2202', 15, false );
				$rb->save_state();
			}
		);

		// Eight raw; the folded one keeps its ten-entry head and a tail of eight.
		$this->assertSame( [ '2 envelopes · 26 entries · 1 folded' ], self::closes_of( $narration, Flame_Tree::REQUESTS_CHECKPOINT ) );
	}

	/**
	 * A checkpoint is told only when what it carries moved since the last one
	 * told: a quiet builder's second checkpoint runs bare, and the next line
	 * folded in makes the one after it news again.
	 */
	public function test_a_checkpoint_carrying_nothing_new_is_not_told_again(): void {
		$rb = $this->builder();
		$t  = self::FROM;

		$narration = $this->narrated(
			$t,
			function () use ( $rb, &$t ): void {
				Core::$now = $t;
				$n = self::request( $rb, 'quiet-5501', 2, false );
				$rb->save_state();
				$rb->save_state();
				self::line( $rb, $n, 'quiet-5501', 'save (start)' );
				$rb->save_state();
			}
		);

		$this->assertSame(
			[ '1 envelopes · 6 entries', '1 envelopes · 7 entries' ],
			self::closes_of( $narration, Flame_Tree::REQUESTS_CHECKPOINT )
		);
	}

	/** A checkpoint's counts come from the walk its save makes, not a second one. */
	public function test_a_checkpoint_walks_the_cache_once(): void {
		$rb        = $this->builder();
		$rb->cache = new Iterate_Counting_LRU_Cache( 100, 3 );
		$t         = self::FROM;

		$narration = $this->narrated(
			$t,
			function () use ( $rb, &$t ): void {
				Core::$now = $t;
				self::request( $rb, 'walked-5502', 3, false );
				$rb->save_state();
			}
		);

		$this->assertSame( [ '1 envelopes · 8 entries' ], self::closes_of( $narration, Flame_Tree::REQUESTS_CHECKPOINT ) );
		$this->assertSame( 0, $rb->cache->iterated, 'the save\'s own conversion counted them' );
	}

	public function test_a_restore_counts_what_came_back(): void {
		$from = $this->builder();
		Core::$now = self::FROM;
		self::request( $from, 'carried-3301', 2, false );
		self::request( $from, 'carried-3302', 4, false );
		$saved = $from->save_state();
		$rb    = $this->builder( [ '100', '3' ], 'request-builder-respawned' );
		$t     = self::FROM;

		$narration = $this->narrated( $t, static fn () => $rb->restore_state( $saved ) );

		$this->assertSame( [ 'restored · 2 envelopes · 16 entries' ], self::closes_of( $narration, Flame_Tree::REQUESTS_RESTORE ) );
	}

	/**
	 * A restore runs with no bracket open — the reader restores before its
	 * first message — yet a stop its `(start)` raises still waits for the
	 * restore: every envelope comes back, the span closes, then the stop.
	 */
	public function test_a_stop_the_restores_start_raises_still_restores_every_envelope(): void {
		$from = $this->builder();
		Core::$now = self::FROM;
		self::request( $from, 'carried-4401', 2, false );
		self::request( $from, 'carried-4402', 4, false );
		$saved = $from->save_state();
		$rb    = $this->builder( [ '100', '3' ], 'request-builder-respawned' );
		$t     = self::FROM;
		$stop  = null;

		$narration = $this->narrated(
			$t,
			static function () use ( $rb, $saved, &$stop ): void {
				$disarm = self::arm_stop_on_next_write();
				try {
					$rb->restore_state( $saved );
				} catch ( Worker_Should_Stop $e ) {
					$stop = $e;
				} finally {
					$disarm();
				}
			}
		);

		$this->assertNotNull( $stop, 'the stop still leaves' );
		$this->assertSame( 2, \iterator_count( $rb->cache->iterate() ), 'once every envelope is back' );
		$this->assertSame( [ 'restored · 2 envelopes · 16 entries' ], self::closes_of( $narration, Flame_Tree::REQUESTS_RESTORE ) );
	}

	/**
	 * A step that fails inside a bracket, after its `(start)` raised the stop,
	 * still ends the work that called it: the failure rides the stop out,
	 * and nothing after the span runs as though the step had succeeded.
	 */
	public function test_a_step_failing_after_its_start_raised_the_stop_ends_the_caller(): void {
		$node   = new Narrating_Test_Node();
		$after  = [];
		$thrown = null;
		$t      = self::FROM;

		$this->narrated(
			$t,
			static function () use ( $node, &$after, &$thrown ): void {
				$disarm = self::arm_stop_on_next_write();
				try {
					$node->span_in_bracket(
						static function (): never {
							throw new \RuntimeException( 'kea 6613' );
						},
						$after
					);
				} catch ( \Throwable $e ) {
					$thrown = $e;
				} finally {
					$disarm();
				}
			}
		);

		$this->assertSame( [], $after, 'nothing after the failed step ran' );
		$this->assertInstanceOf( Worker_Should_Stop::class, $thrown, 'the stop still leaves' );
		$this->assertSame( 'kea 6613', $thrown->getPrevious()?->getMessage(), 'carrying the failure' );
	}

	public function test_the_rollup_counts_every_line_it_dropped_and_every_line_it_forwarded(): void {
		$rb = $this->builder();
		$t  = self::FROM;

		$narration = $this->narrated(
			$t,
			function () use ( $rb, &$t ): void {
				Core::$now = $t;
				self::line( $rb, 4, 'orphan-8801', 'save (start)' );
				$n = self::request( $rb, 'noisy-8802', 1, false );
				self::line( $rb, $n - 1, 'noisy-8802', 'save (complete)' );
				self::line( $rb, $n, 'noisy-8802', 'error', [ 'm' => 'kea 8802' ] );
				self::line( $rb, $n + 1, 'noisy-8802', 'alert', [ 'm' => 'weka 8802' ] );
				self::line( $rb, $n + 5, 'noisy-8802', 'save (start)' );
				self::line( $rb, 1, 'nourl-8803', 'process (start)' );
				self::line( $rb, 2, 'nourl-8803', 'process (complete)', [ 'duration_ms' => 3, 'status_code' => 200 ] );
				self::line( $rb, 1, 'deep-8804', 'process (start)' );
				for ( $i = 2; $i < 60; $i++ ) {
					self::line( $rb, $i, 'deep-8804', "depth{$i} (start)" );
				}
				self::router_tick( $rb );
			}
		);

		$this->assertSame(
			'70 lines · 1 errors · 1 alerts · 1 orphan lines · 1 duplicate lines · 1 gap lines · 1 no-url records · 10 runaway lines',
			self::entries_of( $narration, Flame_Tree::REQUESTS_WRITES )[0]['m'] ?? null
		);
	}

	/**
	 * The worker's record flows back through the firehose into the builder,
	 * its narration included. Narration is no traffic, so a builder with
	 * nothing else to assemble tells one checkpoint and one rollup, for the
	 * one visit and the record's opening lines, then nothing, the stop's
	 * sweep and last checkpoint included.
	 */
	public function test_an_idle_builder_reading_its_own_narration_back_goes_quiet(): void {
		$narration = $this->read_back_lifetime( null );

		// The record's four opening lines and the restore's span, carried once.
		$this->assertSame( [ '1 envelopes · 6 entries' ], self::closes_of( $narration, Flame_Tree::REQUESTS_CHECKPOINT ) );
		// The visit's nine lines and the record's four opening ones.
		$this->assertSame( [ '13 lines · 1 records · 1 summaries' ], \array_column( self::entries_of( $narration, Flame_Tree::REQUESTS_WRITES ), 'm' ) );
	}

	/**
	 * A sibling builder's narration is no traffic either: counted, two builders
	 * each reading the other's record would keep each other telling. Its
	 * record's own opening lines are lines, and count once.
	 */
	public function test_a_siblings_narration_read_in_moves_nothing(): void {
		$narration = $this->read_back_lifetime( 'sibling-builder-3317' );

		// The record's four opening lines and the restore's span, carried once.
		$this->assertSame( [ '1 envelopes · 6 entries' ], self::closes_of( $narration, Flame_Tree::REQUESTS_CHECKPOINT ) );
		// The visit's nine lines and the record's four opening ones.
		$this->assertSame( [ '13 lines · 1 records · 1 summaries' ], \array_column( self::entries_of( $narration, Flame_Tree::REQUESTS_WRITES ), 'm' ) );
	}

	/**
	 * A logged lifetime with one visit at its first tick, every firehose line
	 * the worker's record gained fed back in at each tick, as the firehose
	 * Consumer would, under `$rid`, or the record's own when null.
	 *
	 * @return list<array<string,mixed>> The narration.
	 */
	private function read_back_lifetime( ?string $rid ): array {
		$rb   = $this->builder();
		$t    = self::FROM;
		$base = $this->make_temp_dir();
		$fed  = 0;
		$back = static function () use ( $rb, $base, $rid, &$fed ): void {
			$entries = self::firehose_entries( $base, true );
			foreach ( \array_slice( $entries, $fed ) as $entry ) {
				$message                   = Message::new_message();
				$message[ Message::TYPE ]  = Message::TM_STRUCT;
				$message[ Message::KEY ]   = $rid ?? $entry['rid'];
				unset( $entry['rid'] );
				$message[ Message::VALUE ] = $entry;
				$rb->fill( $message );
			}
			$fed = \count( $entries );
		};

		return $this->narrated(
			$t,
			function () use ( $rb, &$t, $back ): void {
				self::lifetime(
					$rb,
					$t,
					static function ( int $at ) use ( $rb, $back ): void {
						if ( self::FROM === $at ) {
							self::request( $rb, 'visit-6621', 3 );
						}
						$back();
					}
				);
			},
			$base
		);
	}

	public function test_an_unlogged_lifetime_builds_nothing_and_still_resets(): void {
		$rb = $this->builder( [ '100', '3', '46', '24' ] );
		$t  = self::FROM;

		self::lifetime( $rb, $t, static fn ( int $at ) => self::request( $rb, "unlogged-{$at}", 15 ) );

		$this->assertNull( Log_Manager::started_instance() );
		$this->assertSame( [], ( new \ReflectionProperty( $rb, 'tally' ) )->getValue( $rb ) );
		$this->assertSame(
			( new \ReflectionProperty( $rb, 'line_counter' ) )->getValue( $rb ),
			( new \ReflectionProperty( $rb, 'lines_told' ) )->getValue( $rb ),
			'the stop told the last lines, to no one'
		);
	}
}

/** An LRU cache counting the walks `iterate()` makes. */
class Iterate_Counting_LRU_Cache extends \Newspack_Nodes\LRU_Cache {
	/** @var int Walks begun. */
	public int $iterated = 0;

	public function iterate(): \Generator {
		++$this->iterated;
		yield from parent::iterate();
	}
}

/** A bare node narrating one span inside a bracket, as a builder's message does. */
class Narrating_Test_Node extends \Newspack_Nodes\Node {
	use \Newspack_Nodes\Deferred_Clean_Stop;
	use Narration;

	/**
	 * Run `$run` as a `requests restore` span inside a bracket, then note it.
	 *
	 * @param \Closure(): mixed $run   The step.
	 * @param list<string>      $after What ran after the span.
	 */
	public function span_in_bracket( \Closure $run, array &$after ): void {
		$this->deferring(
			function () use ( $run, &$after ): void {
				$this->spanned( Flame_Tree::REQUESTS_RESTORE, $run, static fn (): array => [ '', [] ] );
				$after[] = 'ran on';
			}
		);
	}
}
