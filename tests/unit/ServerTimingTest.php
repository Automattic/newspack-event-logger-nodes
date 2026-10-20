<?php
/**
 * Tests for App\Server_Timing: the response header a reader holding ELN's
 * read role gets on a logged request, and nobody else does.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Server_Timing;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[CoversClass( Server_Timing::class )]
class ServerTimingTest extends TestCase {

	/** @var array<string,mixed> $_SERVER as the test found it. */
	private array $server = [];

	/** @var list<string> Header lines the seam was handed. */
	private array $sent = [];

	protected function setUp(): void {
		parent::setUp();
		$this->server             = $_SERVER;
		Server_Timing::$send_header  = function ( string $line ): void {
			$this->sent[] = $line;
		};
		Server_Timing::$headers_sent = static fn (): bool => false;
	}

	protected function tearDown(): void {
		Server_Timing::$send_header  = null;
		Server_Timing::$headers_sent = null;
		Log_Manager::reset();
		$_SERVER = $this->server;
		unset(
			$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ],
			$GLOBALS['_current_user_id'],
			$GLOBALS['_current_user_can'],
			$GLOBALS['_wp_test_current_user_can'],
			$GLOBALS['_wp_test_doing_ajax']
		);
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		Config::reset();
		parent::tearDown();
	}

	/** A logged request with two closed categories. */
	private function a_timed_request(): Log_Manager {
		$lm = $this->start_logged_request();
		$lm->start( 'kea "quoted" hook' );
		$lm->complete( 'kea "quoted" hook' );
		$lm->start( 'sql' );
		$lm->complete( 'sql' );
		return $lm;
	}

	/** Signed in as user 7746, holding the read role (and only it). */
	private function a_reader(): void {
		$GLOBALS['_current_user_id']           = 7746;
		$GLOBALS['_wp_test_current_user_can'] = [ 'manage_options' => true ];
	}

	/**
	 * The header names the total so far, the dearest categories by self time
	 * with each label as a quoted `desc`, and the request id to find it by.
	 */
	public function test_a_reader_gets_the_time_so_far_and_the_request_id(): void {
		$lm = $this->a_timed_request();
		$this->a_reader();

		( new Server_Timing() )->send();

		$this->assertCount( 1, $this->sent );
		$line = $this->sent[0];
		$this->assertMatchesRegularExpression( '/^Server-Timing: total;dur=\d+\.\d;desc="since PHP start", /', $line );
		$this->assertMatchesRegularExpression( '/, sql;dur=\d+\.\d;desc="sql"(, |$)/', $line );
		$this->assertStringContainsString( 'kea--quoted--hook;dur=', $line );
		$this->assertStringContainsString( ';desc="kea \\"quoted\\" hook"', $line );
		$this->assertStringEndsWith( ', rid;desc="' . $lm->get_request_id() . '"', $line );
		$this->assertStringNotContainsString( "\n", $line );
	}

	/** An anonymous visitor never gets the header, whatever the cap map says. */
	public function test_an_anonymous_visitor_gets_no_header(): void {
		$this->a_timed_request();
		$GLOBALS['_current_user_id']  = 0;
		$GLOBALS['_current_user_can'] = true;

		( new Server_Timing() )->send();

		$this->assertSame( [], $this->sent );
	}

	/** A signed-in user without the read role gets no header. */
	public function test_a_user_without_the_read_role_gets_no_header(): void {
		$this->a_timed_request();
		$GLOBALS['_current_user_id']           = 7746;
		$GLOBALS['_wp_test_current_user_can'] = [ 'edit_posts' => true ];

		( new Server_Timing() )->send();

		$this->assertSame( [], $this->sent );
	}

	/** A request no rule logs has no time to tell, so it gets no header. */
	public function test_an_unlogged_request_gets_no_header(): void {
		$this->a_reader();

		( new Server_Timing() )->send();

		$this->assertSame( [], $this->sent );
		$this->assertFalse( Log_Manager::has_instance() );
	}

	/**
	 * A served REST response sends it once, as the server is about to serve,
	 * and the filter's verdict passes through untouched.
	 */
	public function test_a_served_rest_response_sends_the_header(): void {
		$this->a_timed_request();
		$this->a_reader();

		$this->assertFalse( ( new Server_Timing() )->rest( false ) );

		$this->assertCount( 1, $this->sent );
		$this->assertStringStartsWith( 'Server-Timing: total;dur=', $this->sent[0] );
	}

	/**
	 * Core preloads an editor page's REST data through `rest_post_dispatch`
	 * and inlines each response's headers into the page; on the bound
	 * listeners that path carries none, and only the served one sends it.
	 */
	public function test_a_preloaded_response_carries_no_header(): void {
		$this->start_logged_request();
		$this->a_reader();
		new \Newspack_Event_Logger_Nodes\App\Core();
		$preloaded = new \WP_REST_Response( [ 'kea' => 7746 ] );

		$after = \apply_filters( 'rest_post_dispatch', $preloaded, null, null );

		$this->assertSame( [], $after->get_headers() );
		$this->assertSame( [], $this->sent );
		\apply_filters( 'rest_pre_serve_request', false, $preloaded, null, null );
		$this->assertCount( 1, $this->sent );
	}

	/**
	 * Once a response's headers have left, the line is not handed to
	 * `header()`, which would only warn.
	 */
	public function test_nothing_is_sent_once_headers_have_left(): void {
		$this->a_timed_request();
		$this->a_reader();
		Server_Timing::$headers_sent = static fn (): bool => true;

		( new Server_Timing() )->send();

		$this->assertSame( [], $this->sent );
	}

	/** A visitor's REST response is served without it, the verdict untouched. */
	public function test_a_rest_response_for_a_visitor_sends_none(): void {
		$this->a_timed_request();

		$this->assertTrue( ( new Server_Timing() )->rest( true ) );

		$this->assertSame( [], $this->sent );
	}

	/** admin-ajax sends it from admin_init, just ahead of the handler's output. */
	public function test_admin_ajax_sends_it_and_any_other_admin_page_does_not(): void {
		$this->a_timed_request();
		$this->a_reader();
		$timing = new Server_Timing();

		$timing->ajax();
		$GLOBALS['_wp_test_doing_ajax'] = true;
		$timing->ajax();

		$this->assertCount( 1, $this->sent );
	}

	/** Only the dearest few categories ride, dearest first. */
	public function test_only_the_dearest_categories_ride_dearest_first(): void {
		$lm = $this->start_logged_request();
		( new \ReflectionProperty( Log_Manager::class, 'spent' ) )->setValue(
			$lm,
			[ 'kea hook' => 3.0, 'weka hook' => 41.0, 'tui hook' => 7.0, 'ruru hook' => 19.0, 'kaka hook' => 2.0, 'hihi hook' => 29.0, 'moa hook' => 11.0 ]
		);
		$this->a_reader();

		( new Server_Timing() )->send();

		\preg_match_all( '/, ([^;]+);dur=(\d+\.\d)/', $this->sent[0], $metrics );
		$this->assertSame( [ 'weka-hook', 'hihi-hook', 'ruru-hook', 'moa-hook', 'tui-hook' ], $metrics[1] );
		$this->assertSame( [ '41.0', '29.0', '19.0', '11.0', '7.0' ], $metrics[2] );
	}

	/** A label holding CR or LF cannot split the response. */
	public function test_a_label_holding_a_line_break_cannot_split_the_header(): void {
		$lm = $this->start_logged_request();
		$lm->start( "kea\r\nX-Evil: 1 hook" );
		$lm->complete( "kea\r\nX-Evil: 1 hook" );
		$this->a_reader();

		( new Server_Timing() )->send();

		$this->assertCount( 1, $this->sent );
		$this->assertStringNotContainsString( "\r", $this->sent[0] );
		$this->assertStringNotContainsString( "\n", $this->sent[0] );
		$this->assertStringContainsString( 'kea--X-Evil--1-hook;dur=', $this->sent[0] );
	}

	/** It listens where each kind of response sends its headers. */
	public function test_it_listens_where_each_response_kind_sends_headers(): void {
		$hooks = \array_column( ( new Server_Timing() )->listeners(), 0 );

		$this->assertSame( [ 'send_headers', 'rest_pre_serve_request', 'admin_init' ], $hooks );
	}
}
