<?php
/**
 * Tests for App\Request_Context: the one entry naming what WordPress made
 * of a logged web request.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\App\Core;
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

	/** @var array<string,mixed> The globals as found: WordPress's and the hook harness's. */
	private array $globals = [];

	protected function setUp(): void {
		parent::setUp();
		$this->server  = $_SERVER;
		$this->globals = [];
		foreach ( [ 'wp', 'wp_query', '_wp_actions', '_wp_test_filters' ] as $name ) {
			$this->globals[ $name ] = $GLOBALS[ $name ] ?? null;
		}
	}

	protected function tearDown(): void {
		Log_Manager::reset();
		Request_Context::template( '' );
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
	public function test_the_close_writes_what_wordpress_made_of_the_request(): void {
		$this->start_logged_request( rule: [ 'log_context' => true ] );
		$this->a_single_post();

		$this->assertSame( WP_CONTENT_DIR . '/themes/kea/single.php', Request_Context::template( WP_CONTENT_DIR . '/themes/kea/single.php' ) );

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

	/** A rule that does not ask for the context gets no entry. */
	public function test_a_rule_without_log_context_writes_no_entry(): void {
		$this->start_logged_request();
		$this->a_single_post();

		$this->assertNull( $this->written_context() );
	}

	/** A template outside wp-content is named from the install's root. */
	public function test_a_core_template_is_named_from_the_install_root(): void {
		$this->start_logged_request( rule: [ 'log_context' => true ] );

		Request_Context::template( ABSPATH . 'wp-includes/template-canvas.php' );

		$this->assertSame( 'wp-includes/template-canvas.php', $this->written_context()['template'] ?? null );
	}

	/** A request WordPress routed nowhere — a CLI run, a worker — writes no entry. */
	public function test_a_request_with_no_context_writes_no_entry(): void {
		$this->start_logged_request( rule: [ 'log_context' => true ] );
		$GLOBALS['wp']       = null;
		$GLOBALS['wp_query'] = null;

		$this->assertNull( $this->written_context() );
	}

	/** A job context's record is not the web request's, so it gets none. */
	public function test_a_job_context_writes_no_entry(): void {
		$this->start_logged_request( rule: [ 'log_context' => true ] );
		$this->a_single_post();
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][] = ( new Rule( 'b2c3d4e5f6a1', '/jobs/', Rule::ACTION_LOG, log_context: true ) )->to_array();
		Log_Manager::begin_job_context( 'kea_7746' );
		$this->assertTrue( Log_Manager::instance()->is_started(), 'the job context logs too' );

		Log_Manager::end_job_context();
		$GLOBALS['wp']       = null;
		$GLOBALS['wp_query'] = null;

		$this->assertNull( $this->written_context() );
	}

	/**
	 * The entry fits PIPE_BUF by its own hand: query vars go first, then the
	 * strings are halved, so `message()` never has to drop the whole map.
	 */
	public function test_an_oversized_context_is_trimmed_to_fit(): void {
		$this->start_logged_request( rule: [ 'log_context' => true ] );
		$vars = [];
		for ( $i = 0; $i < 400; $i++ ) {
			$vars[ "kea_var_{$i}" ] = "value {$i}";
		}
		$GLOBALS['wp']       = (object) [ 'matched_rule' => \str_repeat( '😀', 300 ), 'matched_query' => \str_repeat( 'q', 600 ) ];
		$GLOBALS['wp_query'] = (object) [ 'query_vars' => $vars, 'is_home' => true ];

		$entry = self::entries_of( $this->logged_entries(), Log_Manager::REQUEST_CONTEXT )[0] ?? [];
		$this->assertArrayNotHasKey( 'truncated', $entry, 'the producer fitted it; message() never trimmed' );
		$this->assertTrue( $entry['m']['trimmed'] ?? false );
		$this->assertSame( [ 'is_home' ], $entry['m']['conditionals'] ?? null );
		$this->assertLessThanOrEqual( 3840, \strlen( (string) \wp_json_encode( $entry ) ) );
	}

	/** A main query flagging more than one line holds loses its last flags too. */
	public function test_conditionals_past_the_budget_are_dropped_from_the_end(): void {
		$this->start_logged_request( rule: [ 'log_context' => true ] );
		$query = new \stdClass();
		for ( $i = 0; $i < 400; $i++ ) {
			$query->{"is_kea_{$i}"} = true;
		}
		$GLOBALS['wp']       = null;
		$GLOBALS['wp_query'] = $query;

		$m = self::entries_of( $this->logged_entries(), Log_Manager::REQUEST_CONTEXT )[0]['m'] ?? [];
		$this->assertTrue( $m['trimmed'] ?? false );
		$this->assertSame( 'is_kea_0', $m['conditionals'][0] ?? null );
		$this->assertLessThan( 400, \count( $m['conditionals'] ?? [] ) );
		$this->assertLessThanOrEqual( 3600, \strlen( (string) \wp_json_encode( $m ) ) );
	}

	/** It listens for the final template alone, at the last priority. */
	public function test_it_listens_for_the_final_template_alone(): void {
		$listeners = Request_Context::listeners();

		$this->assertSame( [ [ 'template_include', PHP_INT_MAX ] ], \array_map( static fn ( array $l ): array => [ $l[0], $l[2] ], $listeners ) );
	}

	/** The template is read once: the next record does not inherit it. */
	public function test_the_template_is_read_once(): void {
		$this->start_logged_request( rule: [ 'log_context' => true ] );
		Request_Context::template( WP_CONTENT_DIR . '/themes/kea/single.php' );
		$this->logged_entries();
		$this->start_logged_request( rule: [ 'log_context' => true ] );

		$this->assertNull( $this->written_context() );
	}

	/** A template left in the slot before the record opened is not its own. */
	public function test_a_reset_empties_the_template_slot(): void {
		Request_Context::template( WP_CONTENT_DIR . '/themes/kea/stale-7746.php' );
		Log_Manager::reset();
		$this->start_logged_request( rule: [ 'log_context' => true ] );
		$GLOBALS['wp'] = (object) [ 'matched_rule' => 'kea-7746/?$', 'matched_query' => '' ];

		$this->assertSame( [ 'matched_rule' => 'kea-7746/?$' ], $this->written_context() );
	}

	/**
	 * A template WordPress includes inside a job context belongs to the job,
	 * so the enclosing record keeps the one its own request included.
	 */
	public function test_a_template_seen_inside_a_job_stays_off_the_parents_record(): void {
		$this->start_logged_request( rule: [ 'log_context' => true ] );
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][] = ( new Rule( 'b2c3d4e5f6a1', '/jobs/', Rule::ACTION_LOG, log_context: true ) )->to_array();
		$GLOBALS['_wp_actions']      = [];
		$GLOBALS['_wp_test_filters'] = [];
		new Core();
		$included = static function ( string $template ): void {
			foreach ( $GLOBALS['_wp_test_filters']['template_include'] ?? [] as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$callback( $template );
				}
			}
		};

		$included( WP_CONTENT_DIR . '/themes/kea/single.php' );
		Log_Manager::begin_job_context( 'kea_7746' );
		$included( WP_CONTENT_DIR . '/themes/kea/job-weka.php' );
		Log_Manager::end_job_context();

		$this->assertSame( 'themes/kea/single.php', $this->written_context()['template'] ?? null );
	}

	/** Reading the map builds no logger. */
	public function test_the_map_builds_no_logger(): void {
		$this->a_single_post();

		$this->assertSame( [ 'is_single', 'is_singular' ], Request_Context::entry()['conditionals'] ?? null );
		$this->assertFalse( Log_Manager::has_instance() );
	}
}
