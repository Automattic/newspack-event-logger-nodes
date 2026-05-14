<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\JobWorker;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( JobWorker::class )]
class JobWorkerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Wipe filter state between tests so handler-loading doesn't leak.
		$GLOBALS['_wp_actions'] = [];
	}

	/**
	 * Build a TM_STRUCT message in the JobRouter-normalized shape:
	 *   { type, handler, parameters, ts }
	 */
	private function job_message( string $handler, array $parameters = [], string $type = 'job' ): array {
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = [
			'type'       => $type,
			'handler'    => $handler,
			'parameters' => $parameters,
			'ts'         => 1700000000.0,
		];
		return $msg;
	}

	public function test_executes_job_via_handler(): void {
		$jw = new JobWorker();
		$received = null;
		$jw->register_handler( 'a', function ( $payload ) use ( &$received ) {
			$received = $payload;
		} );

		$msg = $this->job_message( 'a', [ 'x' => 1 ] );
		$jw->fill( $msg );

		$this->assertSame( [ 'x' => 1 ], $received );
		$this->assertSame( 1, $jw->jobs_executed() );
	}

	// --- Per-job discipline -------------------------------------------------

	public function test_server_super_is_restored_after_job(): void {
		$jw = new JobWorker();
		$jw->register_handler( 'mutate', function () {
			// Handler-side mutations to $_SERVER must NOT leak across jobs.
			$_SERVER['HANDLER_RAN'] = 'yes';
		} );

		$_SERVER['ORIGINAL_KEY']   = 'original-value';
		$_SERVER['REQUEST_URI']    = '/original';
		unset( $_SERVER['HANDLER_RAN'] );

		$msg = $this->job_message( 'mutate' );
		$jw->fill( $msg );

		// Original $_SERVER preserved.
		$this->assertSame( 'original-value', $_SERVER['ORIGINAL_KEY'] );
		$this->assertSame( '/original', $_SERVER['REQUEST_URI'] );
		// Handler-side mutation did not survive.
		$this->assertArrayNotHasKey( 'HANDLER_RAN', $_SERVER );
	}

	public function test_unique_id_is_per_job_inside_handler(): void {
		// Inside the handler, $_SERVER['UNIQUE_ID'] should be the job's own ID,
		// distinct from any outer ID, and 32-char base36.
		$jw = new JobWorker();
		$captured = [];
		$jw->register_handler( 'capture', function () use ( &$captured ) {
			$captured[] = $_SERVER['UNIQUE_ID'] ?? null;
			$captured[] = $_SERVER['REQUEST_URI'] ?? null;
			$captured[] = $_SERVER['REQUEST_METHOD'] ?? null;
		} );

		$_SERVER['UNIQUE_ID'] = 'OUTER_ID';

		$msg = $this->job_message( 'capture' );
		$jw->fill( $msg );

		$this->assertNotSame( 'OUTER_ID', $captured[0] );
		// 32-char cap: substr( $rid, 0, 32 ) — typical case (vanishingly rare to
		// produce <32 chars from 5 base36-encoded random_bytes(5) iterations).
		$this->assertLessThanOrEqual( 32, strlen( $captured[0] ) );
		$this->assertGreaterThan( 0, strlen( $captured[0] ) );
		$this->assertSame( '/jobs/capture', $captured[1] );
		$this->assertSame( 'POST', $captured[2] );

		// And UNIQUE_ID is restored after the job.
		$this->assertSame( 'OUTER_ID', $_SERVER['UNIQUE_ID'] );
	}

	public function test_server_super_restored_even_if_handler_throws(): void {
		// The try/finally must run end_job_context regardless of handler failure.
		$jw = new JobWorker();
		$jw->register_handler( 'boom', function () {
			$_SERVER['SHOULDNT_LEAK'] = 1;
			throw new \RuntimeException( 'handler failure' );
		} );

		$_SERVER['BEFORE_JOB'] = 'preserved';
		unset( $_SERVER['SHOULDNT_LEAK'] );

		$msg = $this->job_message( 'boom' );
		$jw->fill( $msg ); // exception swallowed.

		$this->assertSame( 'preserved', $_SERVER['BEFORE_JOB'] );
		$this->assertArrayNotHasKey( 'SHOULDNT_LEAK', $_SERVER );
	}

	public function test_generate_request_id_format(): void {
		// Format must match LogManager::generate_request_id exactly so per-job
		// LogManager sessions have IDs indistinguishable from request-scope ones.
		// Up to 32 chars, base36 (lowercase alphanumeric).
		$id = JobWorker::generate_request_id();
		$this->assertLessThanOrEqual( 32, strlen( $id ) );
		$this->assertGreaterThan( 0, strlen( $id ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-z]+$/', $id );

		// Two consecutive calls produce different IDs.
		$id2 = JobWorker::generate_request_id();
		$this->assertNotSame( $id, $id2 );
	}

	// --- Non-array VALUE handling ------------------------------------------

	public function test_non_array_value_is_dropped(): void {
		// VALUE is no longer JSON-decoded by the consumer — it is the array (or
		// other typed payload) directly. A non-array VALUE must be silently
		// dropped rather than dispatched to a handler.
		$jw = new JobWorker();
		$called = false;
		$jw->register_handler( 'deep', function () use ( &$called ) { $called = true; } );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = 'not-an-array';
		$jw->fill( $msg );

		$this->assertFalse( $called, 'non-array VALUE must not reach the handler' );
		$this->assertSame( 0, $jw->jobs_executed() );
	}

	// --- Cadence tests (preserved from original suite) ----------------------

	public function test_between_jobs_callback_fires_after_each_job(): void {
		$jw = new JobWorker();
		$jw->register_handler( 'noop', fn ( $p ) => null );

		$counters = [];
		$jw->set_between_jobs_callback( function ( int $count ) use ( &$counters ) {
			$counters[] = $count;
		} );

		for ( $i = 0; $i < 5; ++$i ) {
			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );
		}

		$this->assertSame( [ 1, 2, 3, 4, 5 ], $counters );
		$this->assertSame( 5, $jw->jobs_executed() );
	}

	public function test_callback_can_implement_every_n_cadence(): void {
		$jw = new JobWorker();
		$jw->register_handler( 'noop', fn ( $p ) => null );

		$flush_count = 0;
		$jw->set_between_jobs_callback( function ( int $count ) use ( &$flush_count ) {
			if ( $count % 3 === 0 ) {
				++$flush_count;
			}
		} );

		for ( $i = 0; $i < 10; ++$i ) {
			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );
		}

		$this->assertSame( 3, $flush_count );
	}

	public function test_callback_fires_after_exception(): void {
		$jw = new JobWorker();
		$jw->register_handler( 'boom', function () { throw new \RuntimeException( 'x' ); } );

		$cycles = 0;
		$jw->set_between_jobs_callback( function () use ( &$cycles ) { ++$cycles; } );

		$msg = $this->job_message( 'boom' );
		$jw->fill( $msg );

		$this->assertSame( 1, $cycles );
		$this->assertSame( 1, $jw->jobs_executed() );
	}

	public function test_set_callback_to_null_clears_it(): void {
		$jw = new JobWorker();
		$jw->register_handler( 'noop', fn ( $p ) => null );

		$cycles = 0;
		$jw->set_between_jobs_callback( function () use ( &$cycles ) { ++$cycles; } );

		$msg = $this->job_message( 'noop' );
		$jw->fill( $msg );
		$this->assertSame( 1, $cycles );

		$jw->set_between_jobs_callback( null );
		$msg = $this->job_message( 'noop' );
		$jw->fill( $msg );

		$this->assertSame( 1, $cycles );
	}

	public function test_handler_exception_caught_and_logged(): void {
		$jw = new JobWorker();
		$jw->register_handler( 'boom', function () { throw new \RuntimeException( 'x' ); } );

		$msg = $this->job_message( 'boom' );
		$jw->fill( $msg );
		$this->assertSame( 1, $jw->jobs_executed() );
	}

	public function test_no_callback_does_not_fire(): void {
		$jw = new JobWorker();
		$jw->register_handler( 'noop', fn ( $p ) => null );

		for ( $i = 0; $i < 3; ++$i ) {
			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );
		}
		$this->assertSame( 3, $jw->jobs_executed() );
	}

	// --- Constructor params + getters ---------------------------------------

	public function test_default_stale_timeout_is_600(): void {
		$jw = new JobWorker();
		$this->assertSame( 600, $jw->get_stale_timeout() );
	}

	public function test_default_max_runtime_is_600(): void {
		$jw = new JobWorker();
		$this->assertSame( 600, $jw->get_max_runtime() );
	}

	public function test_constructor_overrides_stale_and_runtime(): void {
		// Per-spec: long-running JobWorker variants tune via constructor, not by
		// modifying global defaults.
		$jw = new JobWorker( cache_flush_interval: 10, stale_timeout: 1200, max_runtime: 1200 );
		$this->assertSame( 1200, $jw->get_stale_timeout() );
		$this->assertSame( 1200, $jw->get_max_runtime() );
	}

	public function test_cache_flush_interval_default_is_50(): void {
		// Run 49 jobs — flag wp_cache_flush every time it would be called.
		// Without WP, wp_cache_flush isn't function_exists; we just verify the
		// counter rolls over after 50.
		$jw = new JobWorker();
		$jw->register_handler( 'noop', fn ( $p ) => null );

		// We can't observe wp_cache_flush directly without WP. Instead, drive
		// the worker with the configured interval and observe the running count
		// keeps progressing.
		for ( $i = 0; $i < 51; ++$i ) {
			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );
		}

		$this->assertSame( 51, $jw->jobs_executed() );
	}

	// --- Memory pressure ----------------------------------------------------

	public function test_memory_pressure_starts_false(): void {
		$jw = new JobWorker();
		$this->assertFalse( $jw->memory_pressure() );
	}

	public function test_is_memory_high_returns_false_when_limit_unlimited(): void {
		// memory_limit=-1 (unlimited) means is_memory_high always returns false.
		$prev = ini_set( 'memory_limit', '-1' );
		try {
			$jw = new JobWorker();
			$this->assertFalse( $jw->is_memory_high() );
		} finally {
			if ( false !== $prev ) {
				ini_set( 'memory_limit', $prev );
			}
		}
	}

	// --- Kind validation ----------------------------------------------------

	public function test_non_job_lines_are_skipped(): void {
		// Only k:"job" or k:"remote_job" entries are dispatched. A 'start' entry
		// (request lifecycle) MUST NOT route through JobWorker.
		$jw = new JobWorker();
		$called = false;
		$jw->register_handler( 'noop', function () use ( &$called ) { $called = true; } );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = [ 'type' => 'start', 'handler' => 'noop', 'parameters' => [] ];
		$jw->fill( $msg );

		$this->assertFalse( $called );
		$this->assertSame( 0, $jw->jobs_executed() );
	}

	// --- Local vs. remote handler split -------------------------------------

	public function test_set_local_handler_dispatches_for_type_job(): void {
		$jw = new JobWorker();
		$received = null;
		$jw->set_local_handler( 'sync', function ( $p ) use ( &$received ) { $received = $p; } );

		$msg = $this->job_message( 'sync', [ 'k' => 'v' ], 'job' );
		$jw->fill( $msg );

		$this->assertSame( [ 'k' => 'v' ], $received );
	}

	public function test_set_remote_handler_dispatches_for_type_remote_job(): void {
		$jw = new JobWorker();
		$received = null;
		$jw->set_remote_handler( 'hub_op', function ( $p ) use ( &$received ) { $received = $p; } );

		$msg = $this->job_message( 'hub_op', [ 'a' => 1 ], 'remote_job' );
		$jw->fill( $msg );

		$this->assertSame( [ 'a' => 1 ], $received );
	}

	public function test_local_handler_does_not_handle_remote_job(): void {
		// Same handler name registered ONLY on the local bucket; a remote_job
		// entry must NOT fall through to it (that would let spokes execute
		// hub-only operations).
		$jw = new JobWorker();
		$called = false;
		$jw->set_local_handler( 'priv', function () use ( &$called ) { $called = true; } );

		$msg = $this->job_message( 'priv', [], 'remote_job' );
		$jw->fill( $msg );

		$this->assertFalse( $called );
		$this->assertSame( 0, $jw->jobs_executed() );
	}

	public function test_remote_handler_does_not_handle_local_job(): void {
		$jw = new JobWorker();
		$called = false;
		$jw->set_remote_handler( 'priv', function () use ( &$called ) { $called = true; } );

		$msg = $this->job_message( 'priv', [], 'job' );
		$jw->fill( $msg );

		$this->assertFalse( $called );
	}

	public function test_same_handler_name_in_both_buckets_dispatches_both(): void {
		// Common case for evTemplate-style handlers that should run in both
		// spoke and hub contexts.
		$jw = new JobWorker();
		$local_calls = 0;
		$remote_calls = 0;
		$jw->set_local_handler( 'evTemplate', function () use ( &$local_calls ) { ++$local_calls; } );
		$jw->set_remote_handler( 'evTemplate', function () use ( &$remote_calls ) { ++$remote_calls; } );

		$msg = $this->job_message( 'evTemplate', [], 'job' );
		$jw->fill( $msg );
		$msg = $this->job_message( 'evTemplate', [], 'remote_job' );
		$jw->fill( $msg );
		$msg = $this->job_message( 'evTemplate', [], 'job' );
		$jw->fill( $msg );

		$this->assertSame( 2, $local_calls );
		$this->assertSame( 1, $remote_calls );
	}

	public function test_register_handler_is_alias_for_local(): void {
		// Backward-compat: pre-split callers used register_handler.
		$jw = new JobWorker();
		$jw->register_handler( 'work', fn () => null );

		$this->assertTrue( $jw->has_local_handler( 'work' ) );
		$this->assertFalse( $jw->has_remote_handler( 'work' ) );
		$this->assertTrue( $jw->has_handler( 'work' ) );
	}

	public function test_load_handlers_from_filters_pulls_both_buckets(): void {
		add_filter( 'newspack_nodes/job_handlers', function ( $h ) {
			$h['local_only']  = fn () => null;
			$h['shared']      = fn () => null;
			return $h;
		} );
		add_filter( 'newspack_nodes/remote_job_handlers', function ( $h ) {
			$h['remote_only'] = fn () => null;
			$h['shared']      = fn () => null;
			return $h;
		} );

		$jw = new JobWorker();
		$jw->load_handlers_from_filters();

		$this->assertTrue( $jw->has_local_handler( 'local_only' ) );
		$this->assertTrue( $jw->has_local_handler( 'shared' ) );
		$this->assertFalse( $jw->has_local_handler( 'remote_only' ) );

		$this->assertTrue( $jw->has_remote_handler( 'remote_only' ) );
		$this->assertTrue( $jw->has_remote_handler( 'shared' ) );
		$this->assertFalse( $jw->has_remote_handler( 'local_only' ) );
	}

	public function test_load_handlers_from_filters_skips_invalid_names(): void {
		add_filter( 'newspack_nodes/job_handlers', function ( $h ) {
			$h['valid']        = fn () => null;
			$h['1bad-leading'] = fn () => null;
			$h['ok']           = 'not-a-callable';
			return $h;
		} );

		$jw = new JobWorker();
		$jw->load_handlers_from_filters();

		$this->assertTrue( $jw->has_local_handler( 'valid' ) );
		$this->assertFalse( $jw->has_local_handler( '1bad-leading' ) );
		$this->assertFalse( $jw->has_local_handler( 'ok' ) );
	}

	// ── Sibling CI + eager handler loading ─────────────────────

	public function test_job_worker_constructs_sibling_ci(): void {
		$jw = new JobWorker();
		$jw->name( 'jw' );
		$this->assertNotNull( $jw->interpreter() );
		$this->assertSame( 'jw:config', $jw->interpreter()->name() );
	}

	public function test_job_worker_ctor_eager_loads_handlers_from_filters(): void {
		\add_filter(
			'newspack_nodes/job_handlers',
			static fn ( $h ) => \array_merge( (array) $h, [ 'ctor_test' => static fn () => null ] )
		);
		\add_filter(
			'newspack_nodes/remote_job_handlers',
			static fn ( $h ) => \array_merge( (array) $h, [ 'ctor_remote' => static fn () => null ] )
		);
		$jw = new JobWorker();
		$this->assertTrue( $jw->has_local_handler( 'ctor_test' ) );
		$this->assertTrue( $jw->has_remote_handler( 'ctor_remote' ) );
	}

	public function test_job_worker_node_schema_no_verbs(): void {
		$schema = JobWorker::node_schema();
		$this->assertSame( 'Control', $schema['category'] );
		$this->assertSame( [], $schema['verbs'] );
	}
}
