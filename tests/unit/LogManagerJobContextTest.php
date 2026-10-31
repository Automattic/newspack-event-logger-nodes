<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\Fixtures\Kea_7746_Caller;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;

require_once \dirname( __DIR__ ) . '/fixtures/owners/abspath/wp-includes/kea-7746-core-api.php';
require_once \dirname( __DIR__ ) . '/fixtures/owners/plugins/kea-7746/class-kea-7746-caller.php';

/**
 * Job request-context glue on Log_Manager, which the substrate's Job_Worker_Node
 * drives. begin/end_job_context suspend the parent LogManager and rewrite
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
		unset( $GLOBALS['_wp_options'][ \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES ] );
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
		@\mkdir( self::test_dir() . '/logs', 0755, true );
		foreach ( \glob( self::test_dir() . '/logs/firehose.p*/*.log' ) ?: [] as $stale ) {
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
		// dashboards render, rather than a compound handler string.
		Log_Manager::begin_job_context( 'films_import', 'films-8842' );
		$this->assertSame( '/jobs/films_import/films-8842', $_SERVER['REQUEST_URI'] );
		Log_Manager::end_job_context();
	}

	public function test_absolute_url_job_id_folds_to_its_template_path(): void {
		// evtemplate ids carry the target host so the worker can route on it.
		// Spliced raw, that nests a URL inside a path and reads as though the
		// executing host served it; log_request_details() already supplies the
		// real host, so only the template path belongs here.
		Log_Manager::begin_job_context( 'evtemplate', 'https://hub/Tools/UpdateSite.html' );
		$this->assertSame( '/jobs/evtemplate/Tools/UpdateSite.html', $_SERVER['REQUEST_URI'] );
		Log_Manager::end_job_context();
	}

	public function test_pathless_url_job_id_names_no_template_segment(): void {
		// Nothing left to name a template with, and the handler returns early on
		// exactly this input — so the URI is the bare handler, not a hostname
		// sitting where a template path goes, and not a dangling slash.
		Log_Manager::begin_job_context( 'evtemplate', 'https://hub' );
		$this->assertSame( '/jobs/evtemplate', $_SERVER['REQUEST_URI'] );
		Log_Manager::end_job_context();
	}

	public function test_url_job_id_with_only_a_root_path_names_no_template_segment(): void {
		Log_Manager::begin_job_context( 'evtemplate', 'https://hub/' );
		$this->assertSame( '/jobs/evtemplate', $_SERVER['REQUEST_URI'] );
		Log_Manager::end_job_context();
	}

	public function test_relative_job_id_is_left_alone(): void {
		// The common shape: Job_Intake producers already pass a bare path.
		Log_Manager::begin_job_context( 'evtemplate', 'Tools/ImportFilmTimes.html' );
		$this->assertSame( '/jobs/evtemplate/Tools/ImportFilmTimes.html', $_SERVER['REQUEST_URI'] );
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
		// CONTENT_TYPE / CONTENT_LENGTH describe the outer request's body and
		// must not bleed into the job context; they're restored on end. The
		// edge header names no identity, so it rides through untouched and
		// still correlates the job with the request that queued it. A fresh
		// UNIQUE_ID is what gives the job its own request id.
		$_SERVER['CONTENT_TYPE']          = 'application/json';
		$_SERVER['CONTENT_LENGTH']        = '42';
		$_SERVER['HTTP_X_A8C_REQUEST_ID'] = 'inherited-id';
		$_SERVER['UNIQUE_ID']             = 'outer-request-unique-id';

		Log_Manager::begin_job_context( 'job/test' );
		$this->assertArrayNotHasKey( 'CONTENT_TYPE', $_SERVER );
		$this->assertArrayNotHasKey( 'CONTENT_LENGTH', $_SERVER );
		$this->assertArrayNotHasKey( 'HTTP_X_A8C_REQUEST_ID', $_SERVER, 'a job has no edge id: the spawn request\'s must not ride into its environment line' );
		$this->assertNotSame( 'outer-request-unique-id', $_SERVER['UNIQUE_ID'] );

		Log_Manager::end_job_context();
		$this->assertSame( 'application/json', $_SERVER['CONTENT_TYPE'] );
		$this->assertSame( '42', $_SERVER['CONTENT_LENGTH'] );
		$this->assertSame( 'inherited-id', $_SERVER['HTTP_X_A8C_REQUEST_ID'] );
		$this->assertSame( 'outer-request-unique-id', $_SERVER['UNIQUE_ID'] );

		unset( $_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH'], $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['UNIQUE_ID'] );
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

		Log_Manager::end_job_context( 'slow_handler', '', null );

		$categories = \array_column( self::firehose_entries( self::test_dir() ), 'k' );
		$this->assertNotEmpty( $categories, 'the job context wrote to the firehose' );
		$this->assertContains( 'process (aborted)', $categories );
		$this->assertNotContains( 'process (complete)', $categories );
	}

	/**
	 * A job's object-cache counts are its own: the worker's process ran other
	 * jobs before it, so its `objectcache` row is what moved while it ran.
	 */
	public function test_a_jobs_object_cache_row_is_what_moved_while_it_ran(): void {
		$this->arrange_logging();
		$GLOBALS['_wp_options'][ \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES ] = [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'log_cache' => true ] ];
		require_once \dirname( __DIR__ ) . '/fixtures/object-cache/wp-includes/class-wp-object-cache.php';
		$saved               = $GLOBALS['wp_object_cache'] ?? null;
		$cache               = new \WP_Object_Cache();
		$cache->cache_hits   = 100;
		$cache->cache_misses = 10;

		$GLOBALS['wp_object_cache'] = $cache;
		Log_Manager::begin_job_context( 'counted_handler' );
		Log_Manager::instance()->message( 'work', [ 'm' => 'counted' ] );
		$cache->cache_hits   = 4915;
		$cache->cache_misses = 172;
		Log_Manager::end_job_context();
		$GLOBALS['wp_object_cache'] = $saved;

		$rows = self::entries_of( self::firehose_entries( self::test_dir() ), Log_Manager::OBJECT_CACHE );
		$this->assertSame( [ '4815 hit, 162 miss' ], \array_column( $rows, 'm' ) );
	}

	/**
	 * A job's record follows the `/jobs/` rule and the enclosing request's
	 * follows its own, each switch read from the rule governing that record.
	 */
	public function test_each_record_follows_its_own_rules_switches(): void {
		$this->arrange_logging();
		$GLOBALS['_wp_options'][ \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES ] = [
			[ 'id' => 'page', 'pattern' => '/kea-5821', 'action' => 'log', 'custom_events' => [ 'kea_work' ], 'log_resources' => true, 'log_memory' => false ],
			[ 'id' => 'jobs', 'pattern' => '/jobs/', 'action' => 'log', 'custom_events' => [ 'kea_work' ], 'log_resources' => false, 'log_memory' => true ],
		];
		$server                 = $_SERVER;
		$_SERVER['REQUEST_URI'] = '/kea-5821';
		unset( $_SERVER['UNIQUE_ID'], $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
		try {
			$page = Log_Manager::instance();
			$this->assertTrue( $page->is_started(), 'the page rule logs the request' );
			$page->start( 'kea_work' );
			$page->complete( 'kea_work' );
			Log_Manager::begin_job_context( 'kea_handler', 'run-5821' );
			$job = Log_Manager::instance();
			$job->start( 'kea_work' );
			$job->complete( 'kea_work' );
			Log_Manager::end_job_context();
			$page->finish();
		} finally {
			$_SERVER = $server;
		}

		$entries = self::firehose_entries( self::test_dir(), true );
		$by_rid  = [];
		foreach ( $entries as $entry ) {
			$by_rid[ $entry['rid'] ][] = $entry;
		}
		$this->assertCount( 2, $by_rid, 'two records' );
		$records = \array_values( $by_rid );
		$is_page = static fn ( array $record ): bool => \str_contains( (string) ( self::last_entry_of( $record, 'request' )['m'] ?? '' ), '/kea-5821' );
		[ $page_record ] = \array_values( \array_filter( $records, $is_page ) );
		[ $job_record ]  = \array_values( \array_filter( $records, static fn ( array $r ): bool => ! $is_page( $r ) ) );

		$this->assertCount( 2, self::entries_of( $page_record, 'resources' ), 'the page rule samples resources' );
		$this->assertArrayNotHasKey( 'peak_mb', self::last_entry_of( $page_record, 'kea_work (complete)' ) ?? [], 'the page rule puts no peak on spans' );
		$this->assertSame( [], self::entries_of( $job_record, 'resources' ), 'the job rule samples none' );
		$this->assertArrayHasKey( 'peak_mb', self::last_entry_of( $job_record, 'kea_work (complete)' ) ?? [], 'the job rule puts the peak on spans' );
	}

	/**
	 * A job's switchless record: its worker's type, no partition, and the
	 * message that caused it between the request line and the environment.
	 */
	public function test_a_jobs_switchless_record_names_its_worker_and_its_cause(): void {
		$this->arrange_logging();
		$GLOBALS['_wp_options'][ \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES ] = [ [ 'id' => 'jobs', 'pattern' => '/jobs/', 'action' => 'log' ] ];
		$server                                     = $_SERVER;
		$_SERVER['HTTP_USER_AGENT']                 = 'kea-5821';
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE']      = 'kea-worker';
		$_SERVER['NEWSPACK_NODES_WORKER_PARTITION'] = '2';
		try {
			Log_Manager::begin_job_context( 'kea_handler', 'run-5822', [ \Newspack_Nodes\Message::FROM => 'jobs.p2', \Newspack_Nodes\Message::ID => '1:2:3', \Newspack_Nodes\Message::KEY => 'kea' ] );
			Log_Manager::instance();
			Log_Manager::end_job_context();
		} finally {
			$_SERVER = $server;
		}

		$this->assertSame(
			[ Log_Manager::REQUEST_START, Log_Manager::WORKER_TYPE, Log_Manager::REQUEST_LINE, Log_Manager::JOB_MESSAGE, Log_Manager::ENVIRONMENT, Log_Manager::REQUEST_COMPLETE ],
			\array_column( self::firehose_entries( self::test_dir() ), 'k' )
		);
	}

	/** A job context is what `in_job_context()` reports, and only while open. */
	public function test_in_job_context_holds_while_a_job_context_is_open(): void {
		$this->assertFalse( Log_Manager::in_job_context() );
		Log_Manager::begin_job_context( 'kea_7746' );
		$inside = Log_Manager::in_job_context();
		Log_Manager::end_job_context();

		$this->assertTrue( $inside );
		$this->assertFalse( Log_Manager::in_job_context() );
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

		$this->assertSame(
			[ [ 'FROM' => 'jobs.p3', 'ID' => '0:58746220:127', 'KEY' => 'affinity-8842' ] ],
			\array_column( self::entries_of( self::firehose_entries( self::test_dir() ), 'message' ), 'm' )
		);
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

		$messages = \array_column( self::entries_of( self::firehose_entries( self::test_dir() ), 'message' ), 'm' );
		$this->assertSame(
			[ '4:117:63', '4:117:63' ],
			\array_column( $messages, 'ID' ),
			'both the outer job and the nested render name the causing record'
		);
	}

	/**
	 * A nested context that brings its own message names that one, and the
	 * outer job's stands again once it ends.
	 */
	public function test_a_nested_context_with_its_own_message_leaves_the_outer_ones_in_force(): void {
		$this->arrange_logging();
		Log_Manager::begin_job_context( 'outer_handler', '', [ \Newspack_Nodes\Message::FROM => 'jobs.p1', \Newspack_Nodes\Message::ID => '5:212:71' ] );
		Log_Manager::instance()->message( 'work', [ 'm' => 'outer' ] );
		Log_Manager::begin_job_context( 'inner_handler', '', [ \Newspack_Nodes\Message::FROM => 'jobs.p4', \Newspack_Nodes\Message::ID => '9:330:58' ] );
		Log_Manager::instance()->message( 'work', [ 'm' => 'inner' ] );
		Log_Manager::end_job_context();
		Log_Manager::begin_job_context( 'sibling_template' );
		Log_Manager::instance()->message( 'work', [ 'm' => 'sibling' ] );
		Log_Manager::end_job_context();
		Log_Manager::end_job_context();

		$this->assertSame(
			[ '5:212:71', '9:330:58', '5:212:71' ],
			\array_column( \array_column( self::entries_of( self::firehose_entries( self::test_dir() ), 'message' ), 'm' ), 'ID' ),
			'the render after the inner job names the outer job\'s record'
		);
	}

	/** A job that finished keeps the ordinary completion line. */
	public function test_a_completed_job_still_logs_process_complete(): void {
		$this->arrange_logging();
		Log_Manager::begin_job_context( 'quick_handler' );
		Log_Manager::instance()->message( 'work', [ 'm' => 'done' ] );

		Log_Manager::end_job_context(
			'quick_handler',
			'',
			[ 'status' => 'ok', 'message' => '', 'items_ok' => 1, 'items_err' => 0 ]
		);

		$categories = \array_column( self::firehose_entries( self::test_dir() ), 'k' );
		$this->assertContains( 'process (complete)', $categories );
		$this->assertNotContains( 'process (aborted)', $categories );
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
		Log_Manager::end_job_context( 'stopped_handler', '', null );

		// A fresh, entirely successful job in the same process.
		Log_Manager::begin_job_context( 'good_handler' );
		Log_Manager::instance()->message( 'work', [ 'm' => 'fine' ] );
		Log_Manager::end_job_context(
			'good_handler',
			'',
			[ 'status' => 'ok', 'message' => '', 'items_ok' => 3, 'items_err' => 0 ]
		);

		$entries = self::firehose_entries( self::test_dir() );
		$this->assertCount(
			1,
			self::entries_of( $entries, 'process (aborted)' ),
			'only the job that did not finish may be aborted'
		);
		$this->assertNotSame( [], self::entries_of( $entries, 'process (complete)' ) );
	}

	/** A context opened after a job ended names no record that job carried in. */
	public function test_a_context_after_a_job_names_no_message(): void {
		$this->arrange_logging();
		$message                                  = \Newspack_Nodes\Message::new_message();
		$message[ \Newspack_Nodes\Message::FROM ] = 'jobs:consumer-6613';
		Log_Manager::begin_job_context( 'kea_handler', '', $message );
		Log_Manager::instance()->message( 'work', [ 'm' => 'kea 6613' ] );
		Log_Manager::end_job_context( 'kea_handler', '', [ 'status' => 'ok', 'message' => '', 'items_ok' => 1, 'items_err' => 0 ] );

		Log_Manager::begin_job_context( 'borrowed' );
		Log_Manager::instance()->message( 'work', [ 'm' => 'weka 6613' ] );
		Log_Manager::end_job_context();

		$this->assertCount( 1, self::entries_of( self::firehose_entries( self::test_dir() ), 'message' ), 'only the job that carried a message names one' );
	}

	/** A plain request after a job ended names no record that job carried in. */
	public function test_a_request_after_a_job_names_no_message(): void {
		$this->arrange_logging();
		$message                                = \Newspack_Nodes\Message::new_message();
		$message[ \Newspack_Nodes\Message::FROM ] = 'jobs:consumer-7731';
		Log_Manager::begin_job_context( 'kea_handler', '', $message );
		Log_Manager::instance()->message( 'work', [ 'm' => 'kea 7731' ] );
		Log_Manager::end_job_context( 'kea_handler', '', [ 'status' => 'ok', 'message' => '', 'items_ok' => 1, 'items_err' => 0 ] );
		$this->assertCount( 1, self::entries_of( self::firehose_entries( self::test_dir() ), 'message' ), 'the job names its record' );

		$this->arrange_logging();
		Log_Manager::instance()->message( 'work', [ 'm' => 'weka 7731' ] );
		Log_Manager::reset();

		$this->assertSame( [], self::entries_of( self::firehose_entries( self::test_dir() ), 'message' ) );
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

		$this->assertSame( [], self::entries_of( self::firehose_entries( self::test_dir() ), 'process (aborted)' ) );
	}

	/**
	 * A job's `process (start)` names the plugin whose handler it runs, so
	 * every span the job leaves unlabelled is that plugin's time.
	 */
	public function test_a_jobs_process_start_names_its_handlers_owner(): void {
		$this->arrange_logging();
		self::own_the_owner_fixtures();
		\add_filter( 'newspack_nodes/job_handlers', static fn ( array $handlers ): array => $handlers + [ 'kea-7746-sweep' => [ Kea_7746_Caller::class, 'filter_the_content' ] ] );

		Log_Manager::begin_job_context_filter( true, 'kea-7746-sweep', 'run-7746', self::job_message_of( 'job' ) );
		Log_Manager::instance();
		Log_Manager::end_job_context();

		$this->assertSame( [ '[kea-7746] kea-7746-sweep' ], self::process_labels() );
	}

	/** A `remote_job` names the owner of the handler the hub's map holds. */
	public function test_a_remote_jobs_process_start_names_its_remote_handlers_owner(): void {
		$this->arrange_logging();
		self::own_the_owner_fixtures();
		\add_filter( 'newspack_nodes/job_handlers', static fn ( array $handlers ): array => $handlers + [ 'kea-7746-relay' => 'strlen' ] );
		\add_filter( 'newspack_nodes/remote_job_handlers', static fn ( array $handlers ): array => $handlers + [ 'kea-7746-relay' => [ Kea_7746_Caller::class, 'filter_the_content' ] ] );

		Log_Manager::begin_job_context( 'kea-7746-relay', '', self::job_message_of( 'remote_job' ) );
		Log_Manager::instance();
		Log_Manager::end_job_context();

		$this->assertSame( [ '[kea-7746] kea-7746-relay' ], self::process_labels() );
	}

	/** A web request's `process (start)` label is empty. */
	public function test_a_web_requests_process_start_names_no_owner(): void {
		$this->arrange_logging();
		self::own_the_owner_fixtures();
		Log_Manager::instance();
		Log_Manager::reset();

		$this->assertSame( [ '' ], self::process_labels() );
	}

	/**
	 * A context whose handler neither map lists — a render nested in a job —
	 * runs as the enclosing job's owner; outside any job its label is empty.
	 */
	public function test_an_unmapped_handler_takes_the_enclosing_jobs_owner(): void {
		$this->arrange_logging();
		self::own_the_owner_fixtures();
		\add_filter( 'newspack_nodes/job_handlers', static fn ( array $handlers ): array => $handlers + [ 'kea-7746-outer' => [ Kea_7746_Caller::class, 'filter_the_content' ] ] );

		Log_Manager::begin_job_context( 'kea-7746-outer', '', self::job_message_of( 'job' ) );
		Log_Manager::instance();
		Log_Manager::begin_job_context( 'Tools/Kea7746.html' );
		Log_Manager::instance();
		Log_Manager::end_job_context();
		Log_Manager::end_job_context();
		Log_Manager::begin_job_context( 'weka-7746-unmapped' );
		Log_Manager::instance();
		Log_Manager::end_job_context();

		$this->assertSame( [ '[kea-7746] kea-7746-outer', '[kea-7746] Tools/Kea7746.html', '' ], self::process_labels() );
	}

	/** A context nested in a `remote_job` reads the remote map for its handler. */
	public function test_a_context_nested_in_a_remote_job_reads_the_remote_map(): void {
		$this->arrange_logging();
		self::own_the_owner_fixtures();
		\add_filter( 'newspack_nodes/job_handlers', static fn ( array $handlers ): array => $handlers + [ 'kea-7746-inner' => 'strlen' ] );
		\add_filter( 'newspack_nodes/remote_job_handlers', static fn ( array $handlers ): array => $handlers + [ 'kea-7746-inner' => [ Kea_7746_Caller::class, 'filter_the_content' ] ] );

		Log_Manager::begin_job_context( 'weka-7746-hub', '', self::job_message_of( 'remote_job' ) );
		Log_Manager::begin_job_context( 'kea-7746-inner' );
		Log_Manager::instance();
		Log_Manager::end_job_context();
		Log_Manager::end_job_context();

		$this->assertSame( [ '[kea-7746] kea-7746-inner' ], self::process_labels() );
	}

	/** A worker asks the handler map once per handler, however many jobs run. */
	public function test_a_handlers_owner_is_resolved_once(): void {
		self::own_the_owner_fixtures();
		$asked = 0;
		\add_filter(
			'newspack_nodes/job_handlers',
			static function ( array $handlers ) use ( &$asked ): array {
				++$asked;
				return $handlers + [ 'kea-7746-tally' => [ Kea_7746_Caller::class, 'filter_the_content' ] ];
			}
		);

		foreach ( [ 'run-1', 'run-2' ] as $id ) {
			Log_Manager::begin_job_context_filter( true, 'kea-7746-tally', $id, self::job_message_of( 'job' ) );
			Log_Manager::end_job_context();
		}

		$this->assertSame( 1, $asked );
	}

	/**
	 * A job message as the substrate's worker hands it to `before_job`.
	 *
	 * @param string $kind The VALUE's `k`: `job` or `remote_job`.
	 * @return array<int,mixed>
	 */
	private static function job_message_of( string $kind ): array {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::FROM ]  = 'jobs.p0';
		$message[ Message::VALUE ] = [ 'k' => $kind ];
		return $message;
	}

	/**
	 * Every `process (start)` label the firehose holds, in order.
	 *
	 * @return list<mixed>
	 */
	private static function process_labels(): array {
		return \array_column( self::entries_of( self::firehose_entries( self::test_dir() ), Log_Manager::REQUEST_START ), 'l' );
	}
}
