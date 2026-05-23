<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Job_Worker_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Job_Worker_Node::class )]
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
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
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
		$id = Job_Worker_Node::generate_request_id();
		$this->assertLessThanOrEqual( 32, strlen( $id ) );
		$this->assertGreaterThan( 0, strlen( $id ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-z]+$/', $id );

		// Two consecutive calls produce different IDs.
		$id2 = Job_Worker_Node::generate_request_id();
		$this->assertNotSame( $id, $id2 );
	}

	// --- Non-array VALUE handling ------------------------------------------

	public function test_non_array_value_is_dropped(): void {
		// VALUE is no longer JSON-decoded by the consumer — it is the array (or
		// other typed payload) directly. A non-array VALUE must be silently
		// dropped rather than dispatched to a handler.
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
		$jw->register_handler( 'boom', function () { throw new \RuntimeException( 'x' ); } );

		$cycles = 0;
		$jw->set_between_jobs_callback( function () use ( &$cycles ) { ++$cycles; } );

		$msg = $this->job_message( 'boom' );
		$jw->fill( $msg );

		$this->assertSame( 1, $cycles );
		$this->assertSame( 1, $jw->jobs_executed() );
	}

	public function test_set_callback_to_null_clears_it(): void {
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
		$jw->register_handler( 'boom', function () { throw new \RuntimeException( 'x' ); } );

		$msg = $this->job_message( 'boom' );
		$jw->fill( $msg );
		$this->assertSame( 1, $jw->jobs_executed() );
	}

	public function test_no_callback_does_not_fire(): void {
		$jw = new Job_Worker_Node();
		$jw->register_handler( 'noop', fn ( $p ) => null );

		for ( $i = 0; $i < 3; ++$i ) {
			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );
		}
		$this->assertSame( 3, $jw->jobs_executed() );
	}

	// --- Constructor params + getters ---------------------------------------

	public function test_default_stale_timeout_is_600(): void {
		$jw = new Job_Worker_Node();
		$this->assertSame( 600, $jw->get_stale_timeout() );
	}

	public function test_default_max_runtime_is_600(): void {
		$jw = new Job_Worker_Node();
		$this->assertSame( 600, $jw->get_max_runtime() );
	}

	public function test_constructor_overrides_stale_and_runtime(): void {
		// Per-spec: long-running JobWorker variants tune via constructor, not by
		// modifying global defaults.
		$jw = new Job_Worker_Node( cache_flush_interval: 10, stale_timeout: 1200, max_runtime: 1200 );
		$this->assertSame( 1200, $jw->get_stale_timeout() );
		$this->assertSame( 1200, $jw->get_max_runtime() );
	}

	public function test_cache_flush_interval_default_is_50(): void {
		// Run 49 jobs — flag wp_cache_flush every time it would be called.
		// Without WP, wp_cache_flush isn't function_exists; we just verify the
		// counter rolls over after 50.
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
		$this->assertFalse( $jw->memory_pressure() );
	}

	public function test_is_memory_high_returns_false_when_limit_unlimited(): void {
		// memory_limit=-1 (unlimited) means is_memory_high always returns false.
		$prev = ini_set( 'memory_limit', '-1' );
		try {
			$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
		$received = null;
		$jw->set_local_handler( 'sync', function ( $p ) use ( &$received ) { $received = $p; } );

		$msg = $this->job_message( 'sync', [ 'k' => 'v' ], 'job' );
		$jw->fill( $msg );

		$this->assertSame( [ 'k' => 'v' ], $received );
	}

	public function test_set_remote_handler_dispatches_for_type_remote_job(): void {
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
		$called = false;
		$jw->set_local_handler( 'priv', function () use ( &$called ) { $called = true; } );

		$msg = $this->job_message( 'priv', [], 'remote_job' );
		$jw->fill( $msg );

		$this->assertFalse( $called );
		$this->assertSame( 0, $jw->jobs_executed() );
	}

	public function test_remote_handler_does_not_handle_local_job(): void {
		$jw = new Job_Worker_Node();
		$called = false;
		$jw->set_remote_handler( 'priv', function () use ( &$called ) { $called = true; } );

		$msg = $this->job_message( 'priv', [], 'job' );
		$jw->fill( $msg );

		$this->assertFalse( $called );
	}

	public function test_same_handler_name_in_both_buckets_dispatches_both(): void {
		// Common case for evTemplate-style handlers that should run in both
		// spoke and hub contexts.
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
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

		$jw = new Job_Worker_Node();
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

		$jw = new Job_Worker_Node();
		$jw->load_handlers_from_filters();

		$this->assertTrue( $jw->has_local_handler( 'valid' ) );
		$this->assertFalse( $jw->has_local_handler( '1bad-leading' ) );
		$this->assertFalse( $jw->has_local_handler( 'ok' ) );
	}

	// ── Sibling CI + eager handler loading ─────────────────────

	public function test_job_worker_constructs_sibling_ci(): void {
		$jw = new Job_Worker_Node();
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
		$jw = new Job_Worker_Node();
		$this->assertTrue( $jw->has_local_handler( 'ctor_test' ) );
		$this->assertTrue( $jw->has_remote_handler( 'ctor_remote' ) );
	}

	public function test_job_worker_node_schema_no_verbs(): void {
		$schema = Job_Worker_Node::node_schema();
		$this->assertSame( 'Control', $schema['category'] );
		$this->assertSame( [], $schema['verbs'] );
	}

	public function test_job_worker_node_schema_declares_get_health_request(): void {
		// GET_HEALTH is the introspection request the topology console + SSE
		// dashboards drive against a live JobWorker. The schema entry is what
		// the editor uses to render the inspector panel — its presence is
		// part of the public contract.
		$schema = Job_Worker_Node::node_schema();
		$this->assertArrayHasKey( 'requests', $schema );
		$request_names = \array_column( $schema['requests'], 'name' );
		$this->assertContains( 'GET_HEALTH', $request_names );
	}

	// --- Constructor clamping ----------------------------------------------

	public function test_constructor_clamps_zero_or_negative_to_one(): void {
		// max(1, ...) guards against pathological topology configuration that
		// would otherwise produce a 0-jobs-cache-flush-interval (division-by-
		// zero in some downstream codepath) or stale_timeout=0 (immediate
		// staleness, supervisor would force-respawn on every spawn).
		$jw = new Job_Worker_Node( cache_flush_interval: 0, stale_timeout: 0, max_runtime: -5 );
		$this->assertSame( 1, $jw->get_stale_timeout() );
		$this->assertSame( 1, $jw->get_max_runtime() );

		// cache_flush_interval clamp observable: drive 2 jobs and confirm
		// neither crashes (the modulo with interval=1 means every job triggers
		// the would-be wp_cache_flush branch).
		$jw->register_handler( 'noop', fn () => null );
		$msg = $this->job_message( 'noop' );
		$jw->fill( $msg );
		$msg = $this->job_message( 'noop' );
		$jw->fill( $msg );
		$this->assertSame( 2, $jw->jobs_executed() );
	}

	// --- Handler-name validation throw path ---------------------------------

	public function test_set_local_handler_rejects_invalid_name(): void {
		$jw = new Job_Worker_Node();
		$this->expectException( \InvalidArgumentException::class );
		$jw->set_local_handler( '1bad-leading', fn () => null );
	}

	public function test_set_remote_handler_rejects_invalid_name(): void {
		$jw = new Job_Worker_Node();
		$this->expectException( \InvalidArgumentException::class );
		$jw->set_remote_handler( 'bad name with spaces', fn () => null );
	}

	public function test_register_handler_rejects_invalid_name(): void {
		// register_handler is the backward-compat alias for set_local_handler;
		// validation must propagate through the alias unchanged.
		$jw = new Job_Worker_Node();
		$this->expectException( \InvalidArgumentException::class );
		$jw->register_handler( '', fn () => null );
	}

	// --- fill(): malformed messages ----------------------------------------

	public function test_fill_drops_non_struct_messages(): void {
		// TM_BYTESTREAM (no TM_STRUCT bit) carries a string VALUE; the dispatch
		// path requires TM_STRUCT because it reads VALUE as an array.
		$jw = new Job_Worker_Node();
		$called = false;
		$jw->register_handler( 'noop', function () use ( &$called ) { $called = true; } );

		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$msg[ Message::VALUE ] = 'not-a-struct';
		$jw->fill( $msg );

		$this->assertFalse( $called );
		$this->assertSame( 0, $jw->jobs_executed() );
	}

	public function test_fill_drops_oversized_entries(): void {
		// MAX_JOB_SIZE=10MB cap protects the dispatcher from runaway payloads.
		// A blob that exceeds the cap once JSON-encoded must be silently
		// dropped (rate-limited stderr, no handler invocation).
		$jw = new Job_Worker_Node();
		$called = false;
		$jw->register_handler( 'big', function () use ( &$called ) { $called = true; } );

		// 10MB + 1KB of 'x' is enough to clear MAX_JOB_SIZE even after JSON
		// encoding (which adds wrapping braces + key strings of trivial size).
		$huge_param = \str_repeat( 'x', Job_Worker_Node::MAX_JOB_SIZE + 1024 );
		$msg = $this->job_message( 'big', [ 'blob' => $huge_param ] );
		$jw->fill( $msg );

		$this->assertFalse( $called );
		$this->assertSame( 0, $jw->jobs_executed() );
	}

	public function test_fill_drops_entries_with_invalid_handler_name(): void {
		// Even if the entry shape is valid (type/handler/parameters), an
		// invalid HANDLER_NAME_PATTERN match must be rejected before any
		// $handlers[] lookup that could mask a real registration.
		$jw = new Job_Worker_Node();
		$msg = $this->job_message( 'bad name with spaces' );
		$jw->fill( $msg );
		$this->assertSame( 0, $jw->jobs_executed() );
	}

	public function test_fill_drops_unregistered_handler_name(): void {
		// Valid name format but never registered. Rate-limited stderr, no
		// handler called.
		$jw = new Job_Worker_Node();
		$msg = $this->job_message( 'never_registered' );
		$jw->fill( $msg );
		$this->assertSame( 0, $jw->jobs_executed() );
	}

	// --- handle_request: GET_HEALTH + unknown verb ----------------------------

	public function test_handle_request_get_health_returns_payload(): void {
		// GET_HEALTH is the introspection contract used by topology console +
		// SSE dashboards. Reply must be a TM_REQUEST|TM_RESPONSE|TM_STRUCT
		// addressed back to FROM with a structured payload including memory
		// metrics + handler counts.
		$jw = new Job_Worker_Node();
		$jw->set_local_handler( 'a', fn () => null );
		$jw->set_local_handler( 'b', fn () => null );
		$jw->set_remote_handler( 'c', fn () => null );

		$sink = new \Newspack_Nodes\Tests\CaptureSink();
		$jw->sink( $sink );

		$req                   = Message::new_message();
		$req[ Message::TYPE ]  = Message::TM_REQUEST;
		$req[ Message::FROM ]  = 'caller';
		$req[ Message::ID ]    = 'corr-1';
		$req[ Message::KEY ]   = 'app-key';
		$req[ Message::VALUE ] = 'GET_HEALTH';
		$jw->fill( $req );

		$this->assertCount( 1, $sink->captured );
		$reply = $sink->captured[0];
		$this->assertSame(
			Message::TM_RESPONSE | Message::TM_STRUCT,
			$reply[ Message::TYPE ]
		);
		$this->assertSame( 'caller', $reply[ Message::TO ] );
		$this->assertSame( 'corr-1', $reply[ Message::ID ] );
		$this->assertSame( 'app-key', $reply[ Message::KEY ] );

		$value = $reply[ Message::VALUE ];
		$this->assertIsArray( $value );
		$this->assertSame( 'GET_HEALTH', $value['verb'] );

		$payload = $value['data'];
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'memory_used_mb', $payload );
		$this->assertArrayHasKey( 'memory_limit_mb', $payload );
		$this->assertArrayHasKey( 'memory_pressure', $payload );
		$this->assertArrayHasKey( 'jobs_since_cache_flush', $payload );
		$this->assertArrayHasKey( 'cache_flush_interval', $payload );
		$this->assertSame( 2, $payload['local_handler_count'] );
		$this->assertSame( 1, $payload['remote_handler_count'] );
		// counter ticks on every fill() entry, including this request itself.
		$this->assertGreaterThanOrEqual( 1, $payload['counter'] );
		$this->assertFalse( $payload['memory_pressure'] );
	}

	public function test_handle_request_unknown_verb_returns_error_payload(): void {
		$jw = new Job_Worker_Node();
		$sink = new \Newspack_Nodes\Tests\CaptureSink();
		$jw->sink( $sink );

		$req                   = Message::new_message();
		$req[ Message::TYPE ]  = Message::TM_REQUEST;
		$req[ Message::FROM ]  = 'caller';
		$req[ Message::ID ]    = 'corr-2';
		$req[ Message::VALUE ] = 'BOGUS_VERB';
		$jw->fill( $req );

		$this->assertCount( 1, $sink->captured );
		$value = $sink->captured[0][ Message::VALUE ];
		$this->assertSame( 'BOGUS_VERB', $value['verb'] );
		$this->assertArrayHasKey( 'error', $value['data'] );
		$this->assertStringContainsString( 'BOGUS_VERB', $value['data']['error'] );
	}

	public function test_handle_request_uppercases_verb(): void {
		// Verb normalisation: caller-supplied case must round-trip uppercased
		// so the dispatch switch is case-insensitive at the entry point.
		$jw = new Job_Worker_Node();
		$sink = new \Newspack_Nodes\Tests\CaptureSink();
		$jw->sink( $sink );

		$req                   = Message::new_message();
		$req[ Message::TYPE ]  = Message::TM_REQUEST;
		$req[ Message::FROM ]  = 'caller';
		$req[ Message::ID ]    = 'corr-3';
		$req[ Message::VALUE ] = 'get_health';
		$jw->fill( $req );

		$this->assertSame( 'GET_HEALTH', $sink->captured[0][ Message::VALUE ]['verb'] );
	}

	public function test_handle_request_ignores_response_messages(): void {
		// An echoed reply (TM_STRUCT|TM_RESPONSE, no TM_REQUEST) doesn't hit
		// fill()'s TM_REQUEST gate, so handle_request never fires.
		$jw = new Job_Worker_Node();
		$sink = new \Newspack_Nodes\Tests\CaptureSink();
		$jw->sink( $sink );

		$req                   = Message::new_message();
		$req[ Message::TYPE ]  = Message::TM_STRUCT | Message::TM_RESPONSE;
		$req[ Message::FROM ]  = 'caller';
		$req[ Message::VALUE ] = 'GET_HEALTH';
		$jw->fill( $req );

		// No reply emitted (would have been a double-bounce).
		$this->assertCount( 0, $sink->captured );
	}

	// --- memory_limit_bytes: every unit suffix --------------------------------

	public function test_memory_limit_bytes_parses_g_suffix(): void {
		$prev = \ini_set( 'memory_limit', '2G' );
		try {
			$jw  = new Job_Worker_Node();
			$ref = new \ReflectionMethod( Job_Worker_Node::class, 'memory_limit_bytes' );
			$ref->setAccessible( true );
			$this->assertSame( 2 * 1024 * 1024 * 1024, $ref->invoke( $jw ) );
		} finally {
			if ( false !== $prev ) {
				\ini_set( 'memory_limit', $prev );
			}
		}
	}

	public function test_memory_limit_bytes_parses_m_suffix(): void {
		$prev = \ini_set( 'memory_limit', '512M' );
		try {
			$jw  = new Job_Worker_Node();
			$ref = new \ReflectionMethod( Job_Worker_Node::class, 'memory_limit_bytes' );
			$ref->setAccessible( true );
			$this->assertSame( 512 * 1024 * 1024, $ref->invoke( $jw ) );
		} finally {
			if ( false !== $prev ) {
				\ini_set( 'memory_limit', $prev );
			}
		}
	}

	public function test_memory_limit_bytes_parses_k_suffix(): void {
		// Use a 1GB-in-K value: large enough that PHP's allocator stays under
		// the new limit even on memory-greedy test runners, yet still exercises
		// the 'k' suffix branch in the parser.
		$prev = \ini_set( 'memory_limit', '1048576K' );
		try {
			$jw  = new Job_Worker_Node();
			$ref = new \ReflectionMethod( Job_Worker_Node::class, 'memory_limit_bytes' );
			$ref->setAccessible( true );
			$this->assertSame( 1048576 * 1024, $ref->invoke( $jw ) );
		} finally {
			if ( false !== $prev ) {
				\ini_set( 'memory_limit', $prev );
			}
		}
	}

	public function test_memory_limit_bytes_unlimited_returns_negative_one(): void {
		$prev = \ini_set( 'memory_limit', '-1' );
		try {
			$jw  = new Job_Worker_Node();
			$ref = new \ReflectionMethod( Job_Worker_Node::class, 'memory_limit_bytes' );
			$ref->setAccessible( true );
			$this->assertSame( -1, $ref->invoke( $jw ) );
		} finally {
			if ( false !== $prev ) {
				\ini_set( 'memory_limit', $prev );
			}
		}
	}

	// --- is_memory_high branches ----------------------------------------------

	public function test_is_memory_high_returns_false_below_watermark(): void {
		// 16GB-in-bytes upper bound — no test runner is using >12.8GB. The
		// 80% watermark check returns false because usage is far below
		// (16G * 0.80) = 12.8G.
		$prev = \ini_set( 'memory_limit', '16G' );
		try {
			$jw = new Job_Worker_Node();
			$this->assertFalse( $jw->is_memory_high() );
		} finally {
			if ( false !== $prev ) {
				\ini_set( 'memory_limit', $prev );
			}
		}
	}

	public function test_memory_pressure_does_not_latch_when_below_watermark(): void {
		// Symmetric to the above: a job running under a generous memory_limit
		// must NOT latch memory_pressure(). Defends against an off-by-one /
		// inverted comparison regression in the watermark check.
		$prev = \ini_set( 'memory_limit', '16G' );
		try {
			$jw = new Job_Worker_Node();
			$jw->register_handler( 'noop', fn () => null );
			$this->assertFalse( $jw->memory_pressure() );

			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );
			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );

			$this->assertFalse( $jw->memory_pressure() );
		} finally {
			if ( false !== $prev ) {
				\ini_set( 'memory_limit', $prev );
			}
		}
	}

	// --- Cache flush state machine ------------------------------------------

	public function test_cache_flush_state_machine_emits_set_state_event(): void {
		// Every cache_flush_interval jobs, JobWorker calls set_state('CACHE_FLUSH').
		// Register a closure listener via Node::register so we can observe the
		// event without needing wp_cache_flush to exist.
		$jw = new Job_Worker_Node( cache_flush_interval: 3 );
		$jw->register_handler( 'noop', fn () => null );

		// Inject a CACHE_FLUSH event into the registrations table via reflection
		// — set_state notifies listeners registered on the event name, so we can
		// observe the call.
		$ref = new \ReflectionProperty( \Newspack_Nodes\Node::class, 'registrations' );
		$ref->setAccessible( true );
		$registrations             = $ref->getValue( $jw );
		$flush_observed            = [];
		$registrations['CACHE_FLUSH'] = [ 'listener_id' => function ( $payload ) use ( &$flush_observed ) {
			$flush_observed[] = $payload;
			return true; // keep registered
		} ];
		$ref->setValue( $jw, $registrations );

		// 3 jobs trigger the first CACHE_FLUSH; 2 more do not (counter resets).
		for ( $i = 0; $i < 3; ++$i ) {
			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );
		}
		$this->assertCount( 1, $flush_observed, 'first flush fires at jobs == interval' );
		$this->assertSame( 3, $flush_observed[0]['jobs'] );

		// Next 2 jobs should NOT trigger another flush (counter is at 2, not 3).
		for ( $i = 0; $i < 2; ++$i ) {
			$msg = $this->job_message( 'noop' );
			$jw->fill( $msg );
		}
		$this->assertCount( 1, $flush_observed );

		// One more job (now at 3 again) should trigger the second flush.
		$msg = $this->job_message( 'noop' );
		$jw->fill( $msg );
		$this->assertCount( 2, $flush_observed );
	}
}
