<?php
namespace Newspack_Event_Logger_Nodes\Tests\Integration;

use Newspack_Nodes\Job_Worker_Node;
use Newspack_Nodes\Message;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

/**
 * End-to-end: the substrate Job_Worker fires before/after-job actions; ELN's
 * Log_Manager listeners (wired at plugin init) rewrite $_SERVER to a job-scoped
 * context inside the handler and restore it afterward.
 */
class JobWorkerContextWiringTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_actions'] = [];
		\add_action( 'newspack_nodes/job_worker/before_job', [ Log_Manager::class, 'begin_job_context' ] );
		\add_action( 'newspack_nodes/job_worker/after_job', [ Log_Manager::class, 'end_job_context' ] );
	}

	private function job_message( string $handler ): array {
		$msg                   = Message::new_message();
		$msg[ Message::TYPE ]  = Message::TM_STRUCT;
		$msg[ Message::VALUE ] = [ 'type' => 'job', 'handler' => $handler, 'parameters' => [] ];
		return $msg;
	}

	public function test_handler_sees_job_scoped_server_and_it_is_restored(): void {
		$_SERVER['REQUEST_URI'] = '/outer';

		$jw   = new Job_Worker_Node();
		$seen = null;
		$jw->register_handler( 'h', function () use ( &$seen ) { $seen = $_SERVER['REQUEST_URI']; } );

		$msg = $this->job_message( 'h' );
		$jw->fill( $msg );

		$this->assertSame( '/jobs/h', $seen, 'handler runs in job-scoped request context' );
		$this->assertSame( '/outer', $_SERVER['REQUEST_URI'], '$_SERVER restored after the job' );
	}

	public function test_server_restored_even_when_handler_throws(): void {
		$_SERVER['REQUEST_URI'] = '/outer';

		$jw = new Job_Worker_Node();
		$jw->register_handler( 'boom', function () { throw new \RuntimeException( 'x' ); } );

		$msg = $this->job_message( 'boom' );
		$jw->fill( $msg ); // swallowed

		$this->assertSame( '/outer', $_SERVER['REQUEST_URI'] );
	}
}
