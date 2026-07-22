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
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = [ 'k' => 'job', 'handler' => $handler, 'parameters' => [] ];
		return $message;
	}

	public function test_handler_sees_job_scoped_server_and_it_is_restored(): void {
		$_SERVER['REQUEST_URI'] = '/outer';

		$jw   = new Job_Worker_Node();
		$seen = null;
		$this->register_job_handler( $jw, 'h', function () use ( &$seen ) { $seen = $_SERVER['REQUEST_URI']; } );

		$message = $this->job_message( 'h' );
		$jw->fill( $message );

		$this->assertSame( '/jobs/h', $seen, 'handler runs in job-scoped request context' );
		$this->assertSame( '/outer', $_SERVER['REQUEST_URI'], '$_SERVER restored after the job' );
	}

	public function test_handler_sees_job_id_appended_to_request_uri(): void {
		// The substrate reads the entry's top-level `id` and fires before_job
		// with ( handler, id ); ELN's listener builds /jobs/{handler}/{id}.
		$_SERVER['REQUEST_URI'] = '/outer';

		$jw   = new Job_Worker_Node();
		$seen = null;
		$this->register_job_handler( $jw, 'films_import', function () use ( &$seen ) { $seen = $_SERVER['REQUEST_URI']; } );

		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = [ 'k' => 'job', 'handler' => 'films_import', 'parameters' => [], 'id' => 'films-8842' ];
		$jw->fill( $message );

		$this->assertSame( '/jobs/films_import/films-8842', $seen, 'handler runs under the id-scoped job URI' );
		$this->assertSame( '/outer', $_SERVER['REQUEST_URI'], '$_SERVER restored after the job' );
	}

	public function test_server_restored_even_when_handler_throws(): void {
		$_SERVER['REQUEST_URI'] = '/outer';

		$jw = new Job_Worker_Node();
		$this->register_job_handler( $jw, 'boom', function () { throw new \RuntimeException( 'x' ); } );

		$message = $this->job_message( 'boom' );
		// A handler throw now PROPAGATES (the driving Consumer quarantines the job,
		// dead-letter [42]) — but after_job still restores the request context first.
		try {
			$jw->fill( $message );
			$this->fail( 'a throwing handler must propagate so the Consumer can quarantine it' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'x', $e->getMessage() );
		}

		$this->assertSame( '/outer', $_SERVER['REQUEST_URI'], '$_SERVER restored by after_job even on a throw' );
	}
}
