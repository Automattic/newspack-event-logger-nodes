<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

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
		parent::tearDown();
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

	public function test_supervisor_job_context_is_newspack_nodes(): void {
		// The supervisor wrap passes 'newspack-nodes' so its row is
		// /jobs/newspack-nodes (worker_type='supervisor' supplies the
		// ?supervisor suffix downstream) — not /jobs/newspack-nodes/supervisor.
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
}
