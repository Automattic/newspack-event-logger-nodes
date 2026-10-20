<?php
/**
 * Tests for App\Request_Context: the one entry naming what WordPress made
 * of a logged web request.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Request_Context;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

#[CoversClass( Request_Context::class )]
class RequestContextTest extends TestCase {

	/** @var array<string,mixed> $_SERVER as the test found it. */
	private array $server = [];

	/** @var array<string,mixed> The `wp` and `wp_query` globals as found. */
	private array $globals = [];

	protected function setUp(): void {
		parent::setUp();
		$this->server  = $_SERVER;
		$this->globals = [ 'wp' => $GLOBALS['wp'] ?? null, 'wp_query' => $GLOBALS['wp_query'] ?? null ];
	}

	protected function tearDown(): void {
		Log_Manager::reset();
		$_SERVER = $this->server;
		foreach ( $this->globals as $name => $value ) {
			$GLOBALS[ $name ] = $value;
		}
		unset( $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] );
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		Config::reset();
		parent::tearDown();
	}

	/** A single post's main query, as WordPress leaves `$wp` and `$wp_query`. */
	private function a_single_post(): void {
		$GLOBALS['wp']       = (object) [
			'matched_rule'  => '([^/]+)(?:/([0-9]+))?/?$',
			'matched_query' => 'name=kea-story-7746&page=&token=weka-7746',
		];
		$GLOBALS['wp_query'] = (object) [
			'query_vars'  => [ 'name' => 'kea-story-7746', 'post_type' => 'post', 'paged' => 0, 'author' => '', 'tax_query' => [], 'cat' => null, 'meta' => [ 'secret' => 'weka-7746' ] ],
			'is_single'   => true,
			'is_singular' => true,
			'is_page'     => false,
			'is_404'      => false,
			'post_count'  => 1,
		];
	}

	/** The context entry the logged request wrote, or null. */
	private function written_context(): ?array {
		$entries = self::entries_of( $this->logged_entries(), Log_Manager::REQUEST_CONTEXT );
		return $entries[0]['m'] ?? null;
	}

	/**
	 * The template WordPress included, the rewrite rule and query it matched,
	 * the main query's non-empty vars shaped as hook arguments are, and the
	 * conditionals the main query answers true.
	 */
	public function test_shutdown_writes_what_wordpress_made_of_the_request(): void {
		$this->start_logged_request();
		$this->a_single_post();
		$context = new Request_Context();

		$this->assertSame( WP_CONTENT_DIR . '/themes/kea/single.php', $context->template( WP_CONTENT_DIR . '/themes/kea/single.php' ) );
		$context->write();

		$this->assertSame(
			[
				'template'      => 'themes/kea/single.php',
				'matched_rule'  => '([^/]+)(?:/([0-9]+))?/?$',
				'matched_query' => 'name=kea-story-7746&page=&token=[REDACTED]',
				'query_vars'    => [ 'name' => '?', 'post_type' => 'post', 'paged' => 0, 'meta' => [ 'secret' => '?' ] ],
				'conditionals'  => [ 'is_single', 'is_singular' ],
			],
			$this->written_context()
		);
	}

	/** A template outside wp-content is named from the install's root. */
	public function test_a_core_template_is_named_from_the_install_root(): void {
		$this->start_logged_request();
		$context = new Request_Context();

		$context->template( ABSPATH . 'wp-includes/template-canvas.php' );
		$context->write();

		$this->assertSame( 'wp-includes/template-canvas.php', $this->written_context()['template'] ?? null );
	}

	/** A request WordPress routed nowhere — a CLI run, a worker — writes no entry. */
	public function test_a_request_with_no_context_writes_no_entry(): void {
		$this->start_logged_request();
		$GLOBALS['wp']       = null;
		$GLOBALS['wp_query'] = null;

		( new Request_Context() )->write();

		$this->assertNull( $this->written_context() );
	}

	/** A job context's record is not the web request's, so it gets none. */
	public function test_a_job_context_writes_no_entry(): void {
		$this->start_logged_request();
		$this->a_single_post();
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][] = ( new Rule( 'b2c3d4e5f6a1', '/jobs/', Rule::ACTION_LOG ) )->to_array();
		Log_Manager::begin_job_context( 'kea_7746' );
		$this->assertTrue( Log_Manager::instance()->is_started(), 'the job context logs too' );

		( new Request_Context() )->write();
		Log_Manager::end_job_context();

		$this->assertNull( $this->written_context() );
	}

	/**
	 * The entry fits PIPE_BUF by its own hand: query vars go first, then the
	 * strings are halved, so `message()` never has to drop the whole map.
	 */
	public function test_an_oversized_context_is_trimmed_to_fit(): void {
		$this->start_logged_request();
		$vars = [];
		for ( $i = 0; $i < 400; $i++ ) {
			$vars[ "kea_var_{$i}" ] = "value {$i}";
		}
		$GLOBALS['wp']       = (object) [ 'matched_rule' => \str_repeat( '😀', 300 ), 'matched_query' => \str_repeat( 'q', 600 ) ];
		$GLOBALS['wp_query'] = (object) [ 'query_vars' => $vars, 'is_home' => true ];

		( new Request_Context() )->write();

		$entry = self::entries_of( $this->logged_entries(), Log_Manager::REQUEST_CONTEXT )[0] ?? [];
		$this->assertArrayNotHasKey( 'truncated', $entry, 'the producer fitted it; message() never trimmed' );
		$this->assertTrue( $entry['m']['trimmed'] ?? false );
		$this->assertSame( [ 'is_home' ], $entry['m']['conditionals'] ?? null );
		$this->assertLessThanOrEqual( 3840, \strlen( (string) \wp_json_encode( $entry ) ) );
	}

	/** A main query flagging more than one line holds loses its last flags too. */
	public function test_conditionals_past_the_budget_are_dropped_from_the_end(): void {
		$this->start_logged_request();
		$query = new \stdClass();
		for ( $i = 0; $i < 400; $i++ ) {
			$query->{"is_kea_{$i}"} = true;
		}
		$GLOBALS['wp']       = null;
		$GLOBALS['wp_query'] = $query;

		( new Request_Context() )->write();

		$m = self::entries_of( $this->logged_entries(), Log_Manager::REQUEST_CONTEXT )[0]['m'] ?? [];
		$this->assertTrue( $m['trimmed'] ?? false );
		$this->assertSame( 'is_kea_0', $m['conditionals'][0] ?? null );
		$this->assertLessThan( 400, \count( $m['conditionals'] ?? [] ) );
		$this->assertLessThanOrEqual( 3600, \strlen( (string) \wp_json_encode( $m ) ) );
	}

	/** It listens for the final template and for shutdown, at the last priority. */
	public function test_it_listens_for_the_final_template_and_shutdown(): void {
		$listeners = ( new Request_Context() )->listeners();

		$this->assertSame( [ [ 'template_include', PHP_INT_MAX ], [ 'shutdown', PHP_INT_MAX ] ], \array_map( static fn ( array $l ): array => [ $l[0], $l[2] ], $listeners ) );
	}

	/** Without a started logger nothing is written and nothing is built. */
	public function test_nothing_is_written_without_a_started_logger(): void {
		$this->a_single_post();

		( new Request_Context() )->write();

		$this->assertFalse( Log_Manager::has_instance() );
	}
}
