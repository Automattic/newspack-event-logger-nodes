<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

/**
 * Job request-context glue that used to live on Job_Worker_Node (now in the
 * substrate). begin/end_job_context suspend the parent LogManager and rewrite
 * $_SERVER to a synthetic /jobs/{handler} request scope; they are stack-based
 * so the substrate Job_Worker can drive them via the before/after-job actions
 * (which thread no state) and so handlers can nest their own sub-scopes.
 */
#[CoversClass( Log_Manager::class )]
class LogManagerJobContextTest extends TestCase {

	protected function tearDown(): void {
		// Listeners registered via `\add_action` in a test leak across tests
		// through the bootstrap's global `_wp_actions` shim otherwise.
		$GLOBALS['_wp_actions'] = [];
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		Log_Manager::reset();
		\Newspack_Event_Logger_Nodes\Config::reset();
		parent::tearDown();
	}

	/**
	 * Turn logging on against the shared test base dir. This file otherwise
	 * inherits whatever the previous test left, which is fine for the scope
	 * assertions but not for anything reading the firehose off disk.
	 */
	private function arrange_logging(): void {
		@\mkdir( '/tmp/event-logger-nodes-test/logs', 0755, true );
		foreach ( \glob( '/tmp/event-logger-nodes-test/logs/firehose.p*/*.log' ) ?: [] as $stale ) {
			@\unlink( $stale );
		}
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . \dirname( __DIR__ ) . '/configs/logging-enabled.php' );
		Log_Manager::reset();
		\Newspack_Event_Logger_Nodes\Config::reset();
	}

	public function test_scope_change_action_fires_on_begin_and_end_job_context(): void {
		$fired = 0;
		\add_action( 'newspack_event_logger_nodes_scope_changed', function () use ( &$fired ): void {
			$fired++;
		} );
		Log_Manager::begin_job_context( 'my_handler' );
		Log_Manager::end_job_context();
		$this->assertSame( 2, $fired );
	}

	/**
	 * A caller describing a synthetic request other than POST /jobs/{handler}
	 * has to be heard before the scope action fires: App\Core answers it by
	 * calling Log_Manager::instance(), whose constructor picks the governing
	 * rule off REQUEST_URI and writes the `request` line from REQUEST_METHOD.
	 * Overrides applied after begin_job_context() returns are already too late.
	 */
	public function test_server_overrides_land_before_the_scope_action_fires(): void {
		$seen = [];
		// First firing only; end_job_context() fires it again on the restore.
		\add_action( 'newspack_event_logger_nodes_scope_changed', function () use ( &$seen ): void {
			if ( [] !== $seen ) {
				return;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- test observation.
			$seen = [
				'method' => $_SERVER['REQUEST_METHOD'] ?? '',
				'uri'    => $_SERVER['REQUEST_URI'] ?? '',
				'query'  => $_SERVER['QUERY_STRING'] ?? '',
			];
		} );

		Log_Manager::begin_job_context( 'relay', '', server: [
			'REQUEST_METHOD' => 'GET',
			'REQUEST_URI'    => '/Admin/Zarquon.html',
			'QUERY_STRING'   => 'Site=7391&Action=Refresh',
		] );
		Log_Manager::end_job_context();

		$this->assertSame( 'GET', $seen['method'] );
		$this->assertSame( '/Admin/Zarquon.html', $seen['uri'] );
		$this->assertSame( 'Site=7391&Action=Refresh', $seen['query'] );
	}

	public function test_begin_rewrites_server_and_end_restores(): void {
		$_SERVER['ORIGINAL_KEY'] = 'original';
		$_SERVER['REQUEST_URI']  = '/original';
		$_SERVER['UNIQUE_ID']    = 'OUTER';

		Log_Manager::begin_job_context( 'capture' );
		$this->assertSame( '/jobs/capture', $_SERVER['REQUEST_URI'] );
		$this->assertSame( 'POST', $_SERVER['REQUEST_METHOD'] );
		$this->assertNotSame( 'OUTER', $_SERVER['UNIQUE_ID'] );
		$this->assertLessThanOrEqual( 32, \strlen( (string) $_SERVER['UNIQUE_ID'] ) );
		$this->assertGreaterThan( 0, \strlen( (string) $_SERVER['UNIQUE_ID'] ) );

		Log_Manager::end_job_context();
		$this->assertSame( 'original', $_SERVER['ORIGINAL_KEY'] );
		$this->assertSame( '/original', $_SERVER['REQUEST_URI'] );
		$this->assertSame( 'OUTER', $_SERVER['UNIQUE_ID'] );
	}

	public function test_handler_side_server_mutations_do_not_leak(): void {
		$_SERVER['BEFORE_JOB'] = 'preserved';
		unset( $_SERVER['LEAKED'] );

		Log_Manager::begin_job_context( 'mutate' );
		$_SERVER['LEAKED'] = 'yes'; // simulate a handler mutating $_SERVER
		Log_Manager::end_job_context();

		$this->assertSame( 'preserved', $_SERVER['BEFORE_JOB'] );
		$this->assertArrayNotHasKey( 'LEAKED', $_SERVER );
	}

	public function test_nested_contexts_unwind_in_lifo_order(): void {
		$_SERVER['REQUEST_URI'] = '/root';

		Log_Manager::begin_job_context( 'outer' );
		Log_Manager::begin_job_context( 'inner' );
		$this->assertSame( '/jobs/inner', $_SERVER['REQUEST_URI'] );

		Log_Manager::end_job_context();
		$this->assertSame( '/jobs/outer', $_SERVER['REQUEST_URI'] );

		Log_Manager::end_job_context();
		$this->assertSame( '/root', $_SERVER['REQUEST_URI'] );
	}

	public function test_end_without_begin_is_safe(): void {
		// Defensive: an unpaired end (e.g. a before_job listener threw before
		// pushing) must not fatal on an empty stack.
		$_SERVER['REQUEST_URI'] = '/unpaired';
		Log_Manager::end_job_context();
		$this->assertSame( '/unpaired', $_SERVER['REQUEST_URI'] );
	}

	public function test_request_uri_is_slash_normalized(): void {
		// A handler name with a leading slash must not produce a //jobs path.
		Log_Manager::begin_job_context( '/leading' );
		$this->assertSame( '/jobs/leading', $_SERVER['REQUEST_URI'] );
		Log_Manager::end_job_context();
	}

	public function test_empty_handler_yields_bare_jobs_uri(): void {
		Log_Manager::begin_job_context( '' );
		$this->assertSame( '/jobs/', $_SERVER['REQUEST_URI'] );
		Log_Manager::end_job_context();
	}

	public function test_job_id_appends_to_request_uri(): void {
		// The action-provided id builds the /jobs/{handler}/{id} URL the
		// dashboards render — no longer baked into a compound handler string.
		Log_Manager::begin_job_context( 'films_import', 'films-8842' );
		$this->assertSame( '/jobs/films_import/films-8842', $_SERVER['REQUEST_URI'] );
		Log_Manager::end_job_context();
	}

	public function test_empty_job_id_yields_plain_handler_uri(): void {
		Log_Manager::begin_job_context( 'films_import', '' );
		$this->assertSame( '/jobs/films_import', $_SERVER['REQUEST_URI'] );
		Log_Manager::end_job_context();
	}

	public function test_bare_handler_job_context_is_newspack_nodes(): void {
		// A handler with no id yields /jobs/newspack-nodes; the worker_type
		// supplies the ?<type> suffix downstream, not a second path segment.
		Log_Manager::begin_job_context( 'newspack-nodes' );
		$this->assertSame( '/jobs/newspack-nodes', $_SERVER['REQUEST_URI'] );
		Log_Manager::end_job_context();
	}

	public function test_begin_clears_inherited_content_headers_and_end_restores(): void {
		// CONTENT_TYPE / CONTENT_LENGTH / HTTP_X_A8C_REQUEST_ID must not bleed
		// from the outer request into the job context; they're restored on end.
		$_SERVER['CONTENT_TYPE']          = 'application/json';
		$_SERVER['CONTENT_LENGTH']        = '42';
		$_SERVER['HTTP_X_A8C_REQUEST_ID'] = 'inherited-id';

		Log_Manager::begin_job_context( 'job/test' );
		$this->assertArrayNotHasKey( 'CONTENT_TYPE', $_SERVER );
		$this->assertArrayNotHasKey( 'CONTENT_LENGTH', $_SERVER );
		$this->assertArrayNotHasKey( 'HTTP_X_A8C_REQUEST_ID', $_SERVER );

		Log_Manager::end_job_context();
		$this->assertSame( 'application/json', $_SERVER['CONTENT_TYPE'] );
		$this->assertSame( '42', $_SERVER['CONTENT_LENGTH'] );
		$this->assertSame( 'inherited-id', $_SERVER['HTTP_X_A8C_REQUEST_ID'] );

		unset( $_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_X_A8C_REQUEST_ID'] );
	}

	/**
	 * A cooperative stop rethrows without ever setting `$outcome`, so a null
	 * outcome at `after_job` means the job did NOT finish. Emitting the usual
	 * `process (complete)` there marks a killed job as a clean one — the
	 * duration is a fragment, and Flame_Builder would count it as a real
	 * sample. `process (aborted)` is terminal for Request_Builder just the same,
	 * so the half-built request still leaves the cache at once.
	 */
	public function test_a_job_that_did_not_complete_logs_process_aborted(): void {
		$this->arrange_logging();
		Log_Manager::begin_job_context( 'slow_handler' );
		Log_Manager::instance()->message( 'work', [ 'm' => 'partway' ] );

		Log_Manager::end_job_context( 'slow_handler', null );

		$lines = $this->firehose_lines();
		$this->assertNotEmpty( $lines, 'the job context wrote to the firehose' );
		$this->assertStringContainsString( 'process (aborted)', $lines );
		$this->assertStringNotContainsString( 'process (complete)', $lines );
	}

	/**
	 * A job's trace has no way back to the record that caused it unless the
	 * message travels with the context: FROM names the producer, ID is the
	 * `segment:offset:length` the Consumer stamped, KEY the partition key. That
	 * ID is what turns "this job misbehaved" into a seek onto the log.
	 */
	public function test_a_job_context_logs_the_message_that_caused_it(): void {
		$this->arrange_logging();
		Log_Manager::begin_job_context(
			'traced_handler',
			'run-7719',
			[
				\Newspack_Nodes\Message::FROM => 'jobs.p3',
				\Newspack_Nodes\Message::ID   => '0:58746220:127',
				\Newspack_Nodes\Message::KEY  => 'affinity-8842',
			]
		);
		Log_Manager::instance()->message( 'work', [ 'm' => 'traced' ] );
		Log_Manager::end_job_context();

		$lines = $this->firehose_lines();
		$this->assertStringContainsString( 'jobs.p3', $lines );
		$this->assertStringContainsString( '0:58746220:127', $lines );
		$this->assertStringContainsString( 'affinity-8842', $lines );
	}

	/**
	 * A nested context — evTemplate rendering inside a job — names no message
	 * of its own, and must not lose the outer job's. That is what makes the
	 * causing record reachable from the innermost trace.
	 */
	public function test_a_nested_context_keeps_the_outer_jobs_message(): void {
		$this->arrange_logging();
		Log_Manager::begin_job_context(
			'outer_handler',
			'',
			[
				\Newspack_Nodes\Message::FROM => 'jobs.p1',
				\Newspack_Nodes\Message::ID   => '4:117:63',
				\Newspack_Nodes\Message::KEY  => 'affinity-9001',
			]
		);
		Log_Manager::instance()->message( 'work', [ 'm' => 'outer' ] );
		Log_Manager::begin_job_context( 'inner_template' );
		Log_Manager::instance()->message( 'work', [ 'm' => 'inner' ] );
		Log_Manager::end_job_context();
		Log_Manager::end_job_context();

		$lines = $this->firehose_lines();
		$this->assertSame(
			2,
			\substr_count( $lines, '4:117:63' ),
			'both the outer job and the nested render name the causing record'
		);
	}

	/** A job that finished keeps the ordinary completion line. */
	public function test_a_completed_job_still_logs_process_complete(): void {
		$this->arrange_logging();
		Log_Manager::begin_job_context( 'quick_handler' );
		Log_Manager::instance()->message( 'work', [ 'm' => 'done' ] );

		Log_Manager::end_job_context(
			'quick_handler',
			[ 'status' => 'ok', 'message' => '', 'items_ok' => 1, 'items_err' => 0 ]
		);

		$lines = $this->firehose_lines();
		$this->assertStringContainsString( 'process (complete)', $lines );
		$this->assertStringNotContainsString( 'process (aborted)', $lines );
	}

	/**
	 * The abort marker is per-request. It was set and never cleared, so one
	 * unfinished job latched the shared instance and every LATER request in that
	 * worker process reported `process (aborted)` — including ones that plainly
	 * succeeded. Observed live: one pid completed its first job, then marked
	 * every subsequent render aborted despite the render logging "It works!".
	 */
	public function test_an_abort_does_not_leak_into_the_next_request(): void {
		$this->arrange_logging();
		Log_Manager::begin_job_context( 'stopped_handler' );
		Log_Manager::instance()->message( 'work', [ 'm' => 'partway' ] );
		Log_Manager::end_job_context( 'stopped_handler', null );

		// A fresh, entirely successful job in the same process.
		Log_Manager::begin_job_context( 'good_handler' );
		Log_Manager::instance()->message( 'work', [ 'm' => 'fine' ] );
		Log_Manager::end_job_context(
			'good_handler',
			[ 'status' => 'ok', 'message' => '', 'items_ok' => 3, 'items_err' => 0 ]
		);

		$lines = $this->firehose_lines();
		$this->assertSame(
			1,
			\substr_count( $lines, 'process (aborted)' ),
			'only the job that did not finish may be aborted'
		);
		$this->assertStringContainsString( 'process (complete)', $lines );
	}

	/**
	 * A bare `end_job_context()` is a context RESTORE, not an abort signal. The
	 * reconcile bridge calls it with no arguments around every WP-Cron pass, so
	 * treating the defaulted null outcome as "the job died" marked the whole
	 * process aborted once a minute.
	 */
	public function test_a_bare_end_job_context_is_not_an_abort(): void {
		$this->arrange_logging();
		Log_Manager::begin_job_context( 'newspack-nodes' );
		Log_Manager::instance()->message( 'work', [ 'm' => 'reconciled' ] );

		Log_Manager::end_job_context();

		$this->assertStringNotContainsString( 'process (aborted)', $this->firehose_lines() );
	}

	/** Every firehose segment under the test base dir, concatenated. */
	private function firehose_lines(): string {
		$out = '';
		foreach ( \glob( '/tmp/event-logger-nodes-test/logs/firehose.p*/*.log' ) ?: [] as $path ) {
			$out .= (string) \file_get_contents( $path );
		}
		return $out;
	}
}
