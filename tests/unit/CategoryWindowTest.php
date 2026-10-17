<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Topology_Loader;
use Newspack_Nodes\Worker_Should_Stop;
use Newspack_Nodes\Tests\Capture_Sink_Node;

/**
 * The category window equals timings_site summed over the last 288 buckets,
 * across adds, expiries, replays and restarts; its watermark is the newest
 * bucket `window_expiry` holds, which a builder reads once.
 */
#[CoversClass( Flame_Builder_Node::class )]
final class CategoryWindowTest extends TestCase {

	use FactsSeeder;

	/** A tick 30 seconds into its bucket. */
	private const NOW = 1_790_006_430;

	protected function setUp(): void {
		parent::setUp();
		Core::$var['partition'] = '0';
		Core::$now              = (float) self::NOW;
		$this->use_scratch_config( $this->make_temp_dir( 'category-window-' ) );
		$this->activate_shipped( 'flame-builder', 1 );
	}

	/** The shipped builder in its own graph, writing its declared Row_Table. */
	private function builder(): Flame_Builder_Node {
		$router = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );
		Topology_Loader::load( 'flame-builder', 0, $interpreter );
		$fb = Core::node( 'flame-builder' );
		$this->assertInstanceOf( Flame_Builder_Node::class, $fb );
		return $fb;
	}

	/**
	 * A second builder over the first one's file, as a respawned worker
	 * runs: the first gone, the graph and its Row_Table kept.
	 */
	private function restarted( Flame_Builder_Node $fb ): Flame_Builder_Node {
		$sink = $fb->sink();
		$fb->remove_node();
		$next = new Flame_Builder_Node();
		$next->name( 'flame-builder' );
		$next->set_facts_target( self::FACTS_TABLE );
		$next->sink( $sink );
		return $next;
	}

	/** Mark `$bucket` expired in partition 0's file, so it is the watermark. */
	private function seed_watermark( int $bucket ): void {
		$this->write_facts( 0, [ [ 'ignore', 'window_expiry', [ $bucket ] ] ] );
	}

	/** One timed request whose profile spends `$ms` in `$category`. */
	private function fold( Flame_Builder_Node $fb, string $rid, string $category, float $ms, int $finished ): void {
		$this->fill_request_at(
			$fb,
			$this->completed_request(
				[
					'rid'         => $rid,
					'timestamp'   => $finished - 1,
					'duration_ms' => 1000.0,
					'profiles'    => [ $category => [ 'time' => $ms, 'count' => 2, 'entries' => [] ] ],
				]
			),
			''
		);
	}

	/** The window's live rows as category => [ ms, calls, requests ]. */
	private function window(): array {
		$w = Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT MAX( bucket ) FROM p0.window_expiry', [] ) );
		$rows = Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT c.name, w.ms, w.calls, w.requests FROM p0.category_window AS w JOIN p0.categories AS c ON c.id = w.category_id JOIN p0.entries AS e ON e.id = w.entry_id WHERE w.last_bucket > ? AND w.requests > 0 AND e.name = ? ORDER BY c.name', [ $w[0][0], '' ] ) );
		$out = [];
		foreach ( $rows as [ $category, $ms, $calls, $requests ] ) {
			$out[ $category ] = [ $ms, $calls, $requests ];
		}
		return $out;
	}

	/** Every window row as stored, zeroed and negative rows included. */
	private function raw_window(): array {
		return Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT w.ms, w.calls, w.requests FROM p0.category_window AS w JOIN p0.categories AS c ON c.id = w.category_id ORDER BY c.name', [] ) );
	}

	public function test_a_folded_request_adds_its_categories_to_the_window(): void {
		$fb = $this->builder();
		$this->fold( $fb, 'rid-kaka-1', 'hook:kaka_init', 12.5, self::NOW );
		$fb->flush();

		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], $this->window() );
	}

	public function test_a_bucket_leaving_the_window_is_subtracted_once_across_a_restarts_replay(): void {
		$fb    = $this->builder();
		$first = self::NOW - ( Flame_Facts_Row_Schema::WINDOW_BUCKETS - 1 ) * Bucket::SECONDS;
		$this->fold( $fb, 'rid-kaka-2', 'hook:kaka_init', 12.5, $first );
		$fb->flush();

		Core::$now += Bucket::SECONDS;
		$fb->flush();
		$next = $this->restarted( $fb );
		$this->fold( $next, 'rid-kaka-2', 'hook:kaka_init', 12.5, $first );
		$next->flush();

		$this->assertSame( [], $this->window() );
		$this->assertSame( [ [ 0.0, 0, 0 ] ], $this->raw_window(), 'the replay neither adds nor subtracts twice' );
	}

	public function test_an_idle_tick_expires_the_bucket_leaving_the_window(): void {
		$fb    = $this->builder();
		$first = self::NOW - ( Flame_Facts_Row_Schema::WINDOW_BUCKETS - 1 ) * Bucket::SECONDS;
		$this->fold( $fb, 'rid-kaka-8', 'hook:kaka_init', 12.5, $first );
		$fb->flush();

		Core::$now += Bucket::SECONDS;
		$fb->fire_cb();

		$this->assertSame( [], $this->window(), 'a tick with nothing buffered still moves the window' );
	}

	public function test_a_record_older_than_the_watermark_adds_nothing(): void {
		$fb = $this->builder();
		$this->fold( $fb, 'rid-kaka-3', 'hook:kaka_init', 12.5, self::NOW - Flame_Facts_Row_Schema::WINDOW_BUCKETS * Bucket::SECONDS );
		$fb->flush();

		$this->assertSame( [], $this->window() );
	}

	public function test_a_replayed_record_adds_nothing_twice(): void {
		$fb = $this->builder();
		$this->fold( $fb, 'rid-kaka-4', 'hook:kaka_init', 12.5, self::NOW );
		$fb->flush();
		$this->fold( $fb, 'rid-kaka-4', 'hook:kaka_init', 12.5, self::NOW );
		$fb->flush();

		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], $this->window() );
	}

	public function test_a_start_with_no_state_rebuilds_the_window_from_timings_site(): void {
		$bucket = Bucket::id( self::NOW );
		$this->seed_rows(
			0,
			[
				[ 'add', 'category_window', self::window_row( 'hook:stale_init', 999.0, 9, 9, $bucket ) ],
				[ 'add', 'timings_site', [ 'bucket' => $bucket - 3, 'server' => 'seed.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 30.5, 'calls' => 4, 'requests' => 2 ] ],
				[ 'add', 'timings_site', [ 'bucket' => $bucket - 287, 'server' => 'seed.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 5.25, 'calls' => 3, 'requests' => 1 ] ],
				[ 'add', 'timings_site', [ 'bucket' => $bucket - 288, 'server' => 'seed.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 70.0, 'calls' => 1, 'requests' => 1 ] ],
			]
		);

		$fb = $this->builder();
		$this->fold( $fb, 'rid-kaka-5', 'hook:kaka_init', 12.5, self::NOW );
		$fb->flush();

		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ], 'hook:tui_init' => [ 35.75, 7, 3 ] ], $this->window(), 'the stale row reads as gone, the bucket outside the window adds nothing' );
	}

	public function test_a_fresh_builder_resumes_at_the_files_watermark_with_one_read(): void {
		$this->seed_stale_window_row();
		$this->seed_watermark( Bucket::id( self::NOW ) - Flame_Facts_Row_Schema::WINDOW_BUCKETS );
		$fb = $this->builder();
		$this->fold( $fb, 'rid-kaka-6', 'hook:kaka_init', 12.5, self::NOW );
		$fb->flush();
		\chmod( $this->facts_file( 0 ), 0000 );
		try {
			$this->fold( $fb, 'rid-kaka-7', 'hook:kaka_init', 3.5, self::NOW );
			$fb->flush();
		} finally {
			\chmod( $this->facts_file( 0 ), 0644 );
		}

		$this->assertSame( [ 'hook:kaka_init' => [ 16.0, 4, 2 ], 'hook:stale_init' => [ 999.0, 9, 9 ] ], $this->window(), 'no rebuild negated the row timings_site does not hold, and no read followed the first' );
	}

	public function test_save_state_writes_the_buffer_and_carries_nothing(): void {
		$fb = $this->builder();
		$this->fold( $fb, 'rid-kaka-7', 'hook:kaka_init', 12.5, self::NOW );

		$this->assertSame( [], $fb->save_state() );
		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], $this->window() );
	}

	public function test_a_record_in_a_bucket_the_file_expired_adds_nothing_after_a_restart(): void {
		$fb = $this->builder();
		$this->fold( $fb, 'rid-kaka-9', 'hook:kaka_init', 12.5, self::NOW );
		$fb->flush();
		Core::$now += Bucket::SECONDS;
		$fb->flush();

		$next = $this->restarted( $fb );
		$this->fold( $next, 'rid-kaka-10', 'hook:kaka_init', 7.0, self::NOW - ( Flame_Facts_Row_Schema::WINDOW_BUCKETS - 1 ) * Bucket::SECONDS );
		$next->flush();

		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], $this->window(), 'a record in a bucket the file already expired adds nothing' );
	}

	public function test_a_rebuild_marks_its_watermark_so_a_restart_rebuilds_nothing(): void {
		$bucket = Bucket::id( self::NOW );
		$this->seed_rows(
			0,
			[
				[ 'add', 'timings_site', [ 'bucket' => $bucket - 287, 'server' => 'seed.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 30.5, 'calls' => 4, 'requests' => 2 ] ],
				[ 'add', 'timings_site', [ 'bucket' => $bucket - 288, 'server' => 'seed.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 6.0, 'calls' => 1, 'requests' => 1 ] ],
			]
		);
		$fb = $this->builder();
		$fb->flush();
		// A sum the window does not hold, which only a second rebuild would add.
		self::rows_into( Core::node( self::FACTS_TABLE ), self::facts_ops( [ [ 'add', 'timings_site', [ 'bucket' => $bucket - 287, 'server' => 'tui.example', 'worker' => 0, 'category' => 'hook:tui_init', 'entry' => '', 'listener' => 0, 'ms' => 4.0, 'calls' => 1, 'requests' => 1 ] ] ] ) );

		$this->restarted( $fb )->flush();

		$this->assertSame( [ 'hook:tui_init' => [ 30.5, 4, 2 ] ], $this->window(), 'the restart resumed at the rebuild\'s marks' );
	}

	public function test_a_watermark_301_buckets_back_rebuilds(): void {
		$this->seed_stale_window_row();
		$this->seed_watermark( Bucket::id( self::NOW ) - Flame_Facts_Row_Schema::TIMINGS_BUCKETS - 1 );
		$this->builder()->flush();

		$this->assertSame( [], $this->window(), 'the rebuild negates the row timings_site does not hold' );
	}

	public function test_a_watermark_300_buckets_back_subtracts_per_bucket(): void {
		$this->seed_stale_window_row();
		$this->seed_watermark( Bucket::id( self::NOW ) - Flame_Facts_Row_Schema::TIMINGS_BUCKETS );
		$fb = $this->builder();
		$fb->flush();

		$this->assertSame( [ 'hook:stale_init' => [ 999.0, 9, 9 ] ], $this->window(), 'the expiry touches only the buckets leaving' );
	}

	public function test_a_buffer_written_before_a_failing_expiry_stays_and_replays_once(): void {
		$fb = $this->builder();
		$this->fold( $fb, 'rid-kaka-11', 'hook:kaka_init', 12.5, self::NOW );
		Core::$now += Bucket::SECONDS;
		// The writer holds its file open; only the expiry's new reader fails.
		\chmod( $this->facts_file( 0 ), 0000 );
		$thrown = null;
		try {
			$fb->flush();
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		} finally {
			\chmod( $this->facts_file( 0 ), 0644 );
		}

		$this->assertFalse( null === $thrown || Worker_Should_Stop::is_clean( $thrown ), 'a plain stop, so the reader replays' );
		$this->assertInstanceOf( \PDOException::class, $thrown->getPrevious(), 'the expiry\'s read failed, not the write' );
		$this->assertSame( [ [ 1 ] ], $this->request_count(), 'the buffer was written before the expiry failed' );
		$this->assertNull( $fb->idle_since(), 'the buffer is kept' );

		$this->fold( $fb, 'rid-kaka-11', 'hook:kaka_init', 12.5, self::NOW );
		$fb->flush();

		$this->assertNotNull( $fb->idle_since(), 'the kept batch and the replay are written' );
		$this->assertSame( [ [ 1 ] ], $this->request_count(), 'the request row exists once' );
		$this->assertSame( [ 'hook:kaka_init' => [ 12.5, 2, 1 ] ], $this->window(), 'the replay adds nothing twice' );
	}

	/** The `requests` rows partition 0's file holds. */
	private function request_count(): array {
		return Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT count(*) FROM p0.requests', [] ) );
	}

	public function test_a_fill_whose_sync_fails_raises_a_plain_stop_and_buffers_nothing(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb-kaka' );
		$fb->sink( new Capture_Sink_Node() );

		$thrown = null;
		try {
			$this->fold( $fb, 'rid-kaka-12', 'hook:kaka_init', 12.5, self::NOW );
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertFalse( null === $thrown || Worker_Should_Stop::is_clean( $thrown ), 'a plain stop, so the reader replays' );
		$this->assertStringContainsString( 'stats window failed', $thrown->getMessage() );
		$this->assertStringContainsString( 'set_facts_target', (string) $thrown->getPrevious()?->getMessage(), 'no Row_Table was named to read the watermark from' );
		$this->assertNotNull( $fb->idle_since(), 'nothing is buffered' );
	}

	public function test_a_due_expiry_with_no_row_table_named_fails_naming_the_verb(): void {
		$fb = new Flame_Builder_Node();
		$fb->name( 'fb-kaka' );
		$fb->sink( new Capture_Sink_Node() );

		$thrown = null;
		try {
			$fb->flush();
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown );
		$this->assertStringContainsString( 'set_facts_target', (string) $thrown->getPrevious()?->getMessage() );
	}

	/** A window row timings_site holds nothing for, newer than any watermark. */
	private function seed_stale_window_row(): void {
		$this->seed_rows( 0, [ [ 'add', 'category_window', self::window_row( 'hook:stale_init', 999.0, 9, 9, Bucket::id( self::NOW ) ) ] ] );
	}
}
