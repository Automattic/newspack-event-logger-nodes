<?php
/**
 * The 00-newspack-profiler mu-plugin only measures and buffers: the event
 * logger's deferred bootstrap writes what it collected, so the drop-in
 * registers no `plugins_loaded` flush of its own, and the PHP errors it
 * catches before the logger boots wait in its buffer.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class ProfilerMuPluginTest extends TestCase {

	/** @var array<string,mixed> */
	private array $actions;

	/** @var callable|null The error handler the test began under. */
	private $handler;

	/** The directory WordPress's layouts stand in under, for the class. */
	private static string $base = '';

	/** `ABSPATH` for the standard layout, and the files its front controller loaded. */
	private static array $front = [];

	public static function setUpBeforeClass(): void {
		self::$base = \sys_get_temp_dir() . '/kea-5108-' . \bin2hex( \random_bytes( 4 ) );
		\mkdir( self::$base . '/core', 0700, true );
		\mkdir( self::$base . '/htdocs' );
		\mkdir( self::$base . '/site/wp', 0700, true );
		\symlink( '../core', self::$base . '/htdocs/__wp__' );
		$core        = \realpath( self::$base . '/core' );
		self::$front = [ self::$base . '/core/', static fn (): array => [ "{$core}/index.php", "{$core}/wp-blog-header.php" ] ];
	}

	public static function tearDownAfterClass(): void {
		\unlink( self::$base . '/htdocs/__wp__' );
		foreach ( [ 'site/wp', 'site', 'htdocs', 'core', '' ] as $dir ) {
			\rmdir( self::$base . '/' . $dir );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']['permalink_structure'] = '/%year%/%postname%/';
		$GLOBALS['_wp_options']['home']                = 'https://kea-5108.test';
		$this->actions = $GLOBALS['_wp_actions'];
		$this->handler = self::top_handler();
		// Beneath the drop-in's, so no error it chains reaches PHPUnit's.
		\set_error_handler( static fn (): bool => true );
	}

	protected function tearDown(): void {
		for ( $i = 0; $i < 8 && self::top_handler() !== $this->handler; $i++ ) {
			\restore_error_handler();
		}
		$GLOBALS['_wp_actions'] = $this->actions;
		unset( $GLOBALS['newspack_profiler'], $GLOBALS['newspack_profiler_body'], $GLOBALS['newspack_profiler_loaded'], $GLOBALS['_wp_options']['permalink_structure'], $GLOBALS['_wp_options']['home'] );
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		unset( $GLOBALS['_wp_options'][ \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES ] );
		parent::tearDown();
	}

	public function test_the_drop_in_collects_and_writes_nothing_itself(): void {
		$GLOBALS['_wp_actions'] = [];
		self::load_drop_in();
		$profiler = $GLOBALS['newspack_profiler'];

		$this->assertCount( 1, $GLOBALS['_wp_actions']['plugins_loaded'] ?? [], 'only the stand-down, which writes nothing' );
		$this->assertSame( [ 'request_time', 'request_ts', 'plugins', 'php_errors', 'php_errors_dropped', 'php_error_handler' ], \array_keys( $profiler ) );
		$this->assertSame( $profiler['php_error_handler'], self::top_handler(), 'the drop-in catches errors from its own load on' );
	}

	/** The drop-in counts repeats, flags what `@` hid, and chains. */
	public function test_the_drop_in_buffers_each_distinct_error_once(): void {
		$seen = [];
		\set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$seen ): bool {
				$seen[] = $errstr;
				return false;
			}
		);
		self::load_drop_in();
		$handler = $GLOBALS['newspack_profiler']['php_error_handler'];

		$level = \error_reporting( \E_ALL );
		try {
			$verdict = $handler( \E_USER_DEPRECATED, 'kea 7746 loaded early', '/kea/7746.php', 21 );
			$handler( \E_USER_DEPRECATED, 'kea 7746 loaded early', '/kea/7746.php', 21 );
			\error_reporting( 0 );
			$handler( \E_WARNING, 'kea 7746 hushed', '/kea/7746.php', 22 );
		} finally {
			\error_reporting( $level );
		}

		$this->assertFalse( $verdict );
		$this->assertSame( [ 'kea 7746 loaded early', 'kea 7746 loaded early', 'kea 7746 hushed' ], $seen );
		$buffered = \array_values( $GLOBALS['newspack_profiler']['php_errors'] );
		$this->assertSame(
			[ [ \E_USER_DEPRECATED, 'kea 7746 loaded early', '/kea/7746.php', 21, false, 2 ], [ \E_WARNING, 'kea 7746 hushed', '/kea/7746.php', 22, true, 1 ] ],
			\array_map( static fn ( array $row ): array => [ $row['errno'], $row['message'], $row['file'], $row['line'], $row['silenced'], $row['times'] ], $buffered )
		);
		$this->assertIsFloat( $buffered[0]['ts'] );
	}

	/** One key per level, file and line: the first message stands for the rest. */
	public function test_the_drop_in_keys_an_error_by_its_level_file_and_line(): void {
		self::load_drop_in();
		$handler = $GLOBALS['newspack_profiler']['php_error_handler'];

		$handler( \E_WARNING, 'kea 7746 first value', '/kea/7746.php', 26 );
		$handler( \E_WARNING, 'kea 7746 second value', '/kea/7746.php', 26 );

		$this->assertSame(
			[ [ 'kea 7746 first value', 2 ] ],
			\array_map( static fn ( array $row ): array => [ $row['message'], $row['times'] ], \array_values( $GLOBALS['newspack_profiler']['php_errors'] ) )
		);
	}

	/** Past a hundred keys the drop-in only counts, and the close says how many. */
	public function test_the_drop_in_buffers_a_hundred_keys_and_counts_the_rest(): void {
		self::load_drop_in();
		$handler = $GLOBALS['newspack_profiler']['php_error_handler'];
		for ( $line = 1; $line <= 100; $line++ ) {
			$handler( \E_USER_NOTICE, "kea 7746 line {$line}", '/kea/7746.php', $line );
		}
		$handler( \E_USER_NOTICE, 'kea 7746 line 1', '/kea/7746.php', 1 );
		for ( $i = 0; $i < 3; $i++ ) {
			$handler( \E_USER_NOTICE, 'kea 7746 line 101', '/kea/7746.php', 101 );
		}
		$handler( \E_USER_NOTICE, 'kea 7746 line 102', '/kea/7746.php', 102 );

		$this->assertCount( 100, $GLOBALS['newspack_profiler']['php_errors'] );
		$this->assertSame( 4, $GLOBALS['newspack_profiler']['php_errors_dropped'] );

		$lm = $this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		$lm->flush_php_errors();
		$entries = $this->logged_entries();
		$this->assertCount( 100, self::entries_of( $entries, 'php (notice)' ) );
		$this->assertSame( [ "2 notice /kea/7746.php:1\n4 dropped before boot" ], \array_column( self::entries_of( $entries, Log_Manager::PHP_ERRORS ), 'm' ) );
	}

	/** Unclaimed by the logger at `plugins_loaded`'s end, the drop-in stands down. */
	public function test_the_drop_in_stands_down_when_no_logger_claims_it(): void {
		$GLOBALS['_wp_actions'] = [];
		$beneath = self::top_handler();
		self::load_drop_in();
		$handler = $GLOBALS['newspack_profiler']['php_error_handler'];
		$handler( \E_USER_NOTICE, 'kea 7746 unclaimed', '/kea/7746.php', 27 );

		\do_action( 'plugins_loaded' );
		$handler( \E_USER_NOTICE, 'kea 7746 after the stand-down', '/kea/7746.php', 28 );

		$this->assertNull( $GLOBALS['newspack_profiler']['php_errors'] );
		$this->assertSame( $beneath, self::top_handler() );
	}

	/** A collector installed over the drop-in keeps its place at the stand-down. */
	public function test_the_stand_down_leaves_a_handler_installed_over_the_drop_in(): void {
		$GLOBALS['_wp_actions'] = [];
		self::load_drop_in();
		$over = static fn (): bool => false;
		\set_error_handler( $over );

		\do_action( 'plugins_loaded' );

		$this->assertNull( $GLOBALS['newspack_profiler']['php_errors'] );
		$this->assertSame( $over, self::top_handler() );
	}

	/** The handler beneath is called whatever buffering threw, and the throw escapes. */
	public function test_the_drop_in_chains_when_buffering_throws(): void {
		$seen = [];
		\set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$seen ): bool {
				$seen[] = $errstr;
				return true;
			}
		);
		self::load_drop_in();
		$handler = $GLOBALS['newspack_profiler']['php_error_handler'];
		$handler( \E_WARNING, 'kea 7746 buffered', '/kea/7746.php', 29 );
		$key = \array_key_first( $GLOBALS['newspack_profiler']['php_errors'] );
		$GLOBALS['newspack_profiler']['php_errors'][ $key ]['times'] = [];

		try {
			$handler( \E_WARNING, 'kea 7746 buffered again', '/kea/7746.php', 29 );
			$this->fail( 'the throw escapes' );
		} catch ( \TypeError $e ) {
			$this->assertStringContainsString( 'increment', $e->getMessage() );
		}
		$this->assertSame( [ 'kea 7746 buffered', 'kea 7746 buffered again' ], $seen );
	}

	/** At boot the buffer replays into a rule that logs PHP errors, stamped when caught. */
	public function test_the_buffer_replays_when_the_rule_logs_php_errors(): void {
		$beneath = self::top_handler();
		self::load_drop_in();
		$handler = $GLOBALS['newspack_profiler']['php_error_handler'];
		$handler( \E_USER_DEPRECATED, 'kea 7746 loaded early', '/kea/7746.php', 21 );
		$handler( \E_USER_DEPRECATED, 'kea 7746 loaded early', '/kea/7746.php', 21 );
		$handler( \E_USER_ERROR, 'kea 7746 not ours', '/kea/7746.php', 23 );
		$caught = \array_values( $GLOBALS['newspack_profiler']['php_errors'] )[0]['ts'];

		$lm = $this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		$lm->flush_php_errors();

		$this->assertSame( $beneath, self::top_handler(), 'the drop-in steps aside for the logger' );
		$this->assertNull( $GLOBALS['newspack_profiler']['php_errors'] );
		$entries = $this->logged_entries();
		$rows    = self::entries_of( $entries, 'php (deprecated)', 'php (warning)', 'php (notice)' );
		$this->assertSame( [ [ 'php (deprecated)', 'kea 7746 loaded early', '/kea/7746.php', 21, $caught ] ], \array_map( static fn ( array $e ): array => [ $e['k'], $e['m'], $e['file'], $e['line'], $e['ts'] ], $rows ) );
		$this->assertSame( [ '2 deprecated /kea/7746.php:21' ], \array_column( self::entries_of( $entries, Log_Manager::PHP_ERRORS ), 'm' ) );
	}

	/** A rule without the switch discards the buffer, and the handler steps aside. */
	public function test_the_buffer_is_discarded_when_the_rule_does_not_log_php_errors(): void {
		$beneath = self::top_handler();
		self::load_drop_in();
		$GLOBALS['newspack_profiler']['php_error_handler']( \E_USER_DEPRECATED, 'kea 7746 discarded', '/kea/7746.php', 21 );

		$lm = $this->start_logged_request( '/kea/7746' );
		$lm->flush_php_errors();
		$GLOBALS['newspack_profiler']['php_error_handler']( \E_USER_DEPRECATED, 'kea 7746 after boot', '/kea/7746.php', 24 );

		$this->assertSame( $beneath, self::top_handler() );
		$this->assertNull( $GLOBALS['newspack_profiler']['php_errors'], 'nothing buffers once the logger booted' );
		$this->assertSame( [], self::entries_of( $this->logged_entries(), 'php (deprecated)', Log_Manager::PHP_ERRORS ) );
	}

	/** The deferred bootstrap is what takes the buffer over. */
	public function test_the_bootstrap_replays_the_buffer(): void {
		self::load_drop_in();
		$GLOBALS['newspack_profiler']['php_error_handler']( \E_USER_NOTICE, 'kea 7746 before boot', '/kea/7746.php', 25 );
		$this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );

		self::with_deferred_bootstrap( static function (): void {} );

		$this->assertSame( [ 'kea 7746 before boot' ], \array_column( self::entries_of( $this->logged_entries(), 'php (notice)' ), 'm' ) );
	}

	private const SITE_PLUGINS = [
		'akismet/akismet.php',
		'newspack-nodes/newspack-nodes.php',
		'woocommerce/woocommerce.php',
		'newspack-event-logger-nodes/newspack-event-logger-nodes.php',
		'hello.php',
	];

	/** One packed command line to `$to`, as a hub's batch carries it. */
	private static function command_to( string $to, string $verb ): string {
		$m                   = \Newspack_Nodes\Message::new_message();
		$m[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_COMMAND;
		$m[ \Newspack_Nodes\Message::FROM ]  = '_http';
		$m[ \Newspack_Nodes\Message::TO ]    = $to;
		$m[ \Newspack_Nodes\Message::VALUE ] = [ 'name' => $verb, 'arguments' => [ 'firehose.p0', '6797:29360128' ] ];
		return \Newspack_Nodes\Message::packed( $m );
	}

	/** The request a hub's fetch arrives as, through WordPress's front controller. */
	private static function fetch_server( array $overrides = [] ): array {
		return [
			'REQUEST_METHOD' => 'POST',
			'REQUEST_URI'    => '/wp-json/newspack-nodes/v1/command',
			'CONTENT_TYPE'   => 'text/plain; charset=UTF-8',
			...$overrides,
		];
	}

	/** A hub's fetch loads the substrate and the logger alone, in the site's order. */
	public function test_a_raw_logs_batch_loads_the_substrate_and_the_logger_alone(): void {
		self::load_drop_in();
		$body = self::command_to( 'raw-logs', 'read_block' ) . "\n" . self::command_to( 'raw-logs', 'list_logs' ) . "\n";
		$lean = [ 'newspack-nodes/newspack-nodes.php', 'newspack-event-logger-nodes/newspack-event-logger-nodes.php' ];
		$cases = [
			'the pretty route'      => [ 'https://kea-5108.test', [] ],
			'the query route'       => [ 'https://kea-5108.test', [ 'REQUEST_URI' => '/?rest_route=/newspack-nodes/v1/command' ] ],
			'a site in a directory' => [ 'https://kea-5108.test/blog', [ 'REQUEST_URI' => '/blog/wp-json/newspack-nodes/v1/command' ] ],
		];
		foreach ( $cases as $case => [ $home, $overrides ] ) {
			$GLOBALS['_wp_options']['home'] = $home;
			$this->assertSame( $lean, \newspack_profiler_lean_plugins( self::SITE_PLUGINS, self::fetch_server( $overrides ), static fn (): string => $body, ...self::$front ), $case );
		}
	}

	/**
	 * Every other request loads the whole site. That includes a request that
	 * names the route but that another script would serve, and one whose
	 * body PHP would parse as a form.
	 */
	public function test_every_other_request_loads_every_plugin(): void {
		self::load_drop_in();
		$fetch = self::command_to( 'raw-logs', 'read_block' ) . "\n";
		$cases = [
			'another service in the batch'      => [ [], $fetch . self::command_to( 'performance', 'set' ) . "\n" ],
			'a GET'                             => [ [ 'REQUEST_METHOD' => 'GET' ], $fetch ],
			'another route'                     => [ [ 'REQUEST_URI' => '/wp-json/newspack-nodes/v1/auth' ], $fetch ],
			'a route it only begins'            => [ [ 'REQUEST_URI' => '/wp-json/newspack-nodes/v1/commands' ], $fetch ],
			'the route under another path'      => [ [ 'REQUEST_URI' => '/kea/wp-json/newspack-nodes/v1/command' ], $fetch ],
			'the query route on another page'   => [ [ 'REQUEST_URI' => '/kea-5108/?rest_route=/newspack-nodes/v1/command' ], $fetch ],
			'the route with another rest_route' => [ [ 'REQUEST_URI' => '/wp-json/newspack-nodes/v1/command?rest_route=/wp/v2/users' ], $fetch ],
			'another script, by its path info'  => [ [ 'REQUEST_URI' => '/wp-login.php/wp-json/newspack-nodes/v1/command', 'PATH_INFO' => '/wp-json/newspack-nodes/v1/command' ], $fetch ],
			'path info on the front controller' => [ [ 'PATH_INFO' => '/wp-json/newspack-nodes/v1/command' ], $fetch ],
			'the admin index, by rest_route'    => [ [ 'REQUEST_URI' => '/wp-admin/index.php?rest_route=/newspack-nodes/v1/command' ], $fetch ],
			'another directory\'s index.php'    => [ [ 'REQUEST_URI' => '/kea/index.php?rest_route=/newspack-nodes/v1/command' ], $fetch ],
			'a form body'                       => [ [ 'CONTENT_TYPE' => 'application/x-www-form-urlencoded' ], $fetch ],
			'a multipart body'                  => [ [ 'CONTENT_TYPE' => 'multipart/form-data; boundary=kea' ], $fetch ],
			'no content type'                   => [ [ 'CONTENT_TYPE' => '' ], $fetch ],
			'an empty body'                     => [ [], '' ],
			'a body that is not a batch'        => [ [], "kea-5108\n" ],
		];
		foreach ( $cases as $case => [ $overrides, $body ] ) {
			$this->assertSame( self::SITE_PLUGINS, \newspack_profiler_lean_plugins( self::SITE_PLUGINS, self::fetch_server( $overrides ), static fn (): string => $body, ...self::$front ), $case );
		}
	}

	/**
	 * WordPress's front controller has loaded `wp-blog-header.php` from
	 * `ABSPATH` by the plugin loop, wherever the server says the script sits: WP Cloud's core `index.php` behind the docroot's `__wp__`
	 * symlink, or the `index.php` at the home path of a site that gives
	 * WordPress its own directory. A request that loaded no such file runs
	 * another script, and loads every plugin.
	 */
	public function test_the_front_controller_has_loaded_wp_blog_header_by_the_plugin_loop(): void {
		self::load_drop_in();
		$body  = static fn (): string => self::command_to( 'raw-logs', 'read_block' ) . "\n";
		$core  = \realpath( self::$base . '/core' );
		$own   = \realpath( self::$base . '/site' );
		$lean  = [ 'newspack-nodes/newspack-nodes.php', 'newspack-event-logger-nodes/newspack-event-logger-nodes.php' ];
		$cases = [
			'WP Cloud, through the __wp__ symlink' => [ self::$base . '/htdocs/__wp__/', [ "{$core}/index.php", "{$core}/wp-blog-header.php" ], $lean ],
			'WordPress in its own directory'       => [ self::$base . '/site/wp/', [ "{$own}/index.php", "{$own}/wp/wp-blog-header.php" ], $lean ],
			'a script that loads no header'        => [ self::$base . '/core/', [ "{$core}/wp-login.php", "{$core}/wp-load.php" ], self::SITE_PLUGINS ],
			'another install\'s header'            => [ self::$base . '/core/', [ "{$own}/index.php", "{$own}/wp/wp-blog-header.php" ], self::SITE_PLUGINS ],
			'an ABSPATH that resolves nowhere'     => [ self::$base . '/kea/', [ "{$core}/index.php", "{$core}/wp-blog-header.php" ], self::SITE_PLUGINS ],
		];
		foreach ( $cases as $case => [ $abspath, $loaded, $expected ] ) {
			$this->assertSame( $expected, \newspack_profiler_lean_plugins( self::SITE_PLUGINS, self::fetch_server(), $body, $abspath, static fn (): array => $loaded ), $case );
		}
	}

	/**
	 * Under plain permalinks, WordPress does not route a `/wp-json` path to
	 * REST and renders a page for it instead, so only the query form narrows
	 * the plugins.
	 */
	public function test_plain_permalinks_narrow_the_query_route_alone(): void {
		$GLOBALS['_wp_options']['permalink_structure'] = '';
		self::load_drop_in();
		$body = static fn (): string => self::command_to( 'raw-logs', 'read_block' ) . "\n";

		$this->assertSame( self::SITE_PLUGINS, \newspack_profiler_lean_plugins( self::SITE_PLUGINS, self::fetch_server(), $body, ...self::$front ), 'the pretty route' );
		$this->assertSame(
			[ 'newspack-nodes/newspack-nodes.php', 'newspack-event-logger-nodes/newspack-event-logger-nodes.php' ],
			\newspack_profiler_lean_plugins( self::SITE_PLUGINS, self::fetch_server( [ 'REQUEST_URI' => '/?rest_route=/newspack-nodes/v1/command' ] ), $body, ...self::$front ),
			'the query route'
		);
	}

	/**
	 * A fetch keeps whichever of the two plugins the site has, each matched by
	 * its file name whatever directory holds it. The substrate alone serves
	 * the fetch, the event logger alone boots silently, and when the site has
	 * neither, no plugin loads.
	 */
	public function test_a_fetch_keeps_whichever_of_the_two_the_site_has(): void {
		self::load_drop_in();
		$body  = static fn (): string => self::command_to( 'raw-logs', 'read_block' ) . "\n";
		$cases = [
			'the substrate under another directory' => [ [ 'akismet/akismet.php', 'newspack-nodes-main/newspack-nodes.php', 'newspack-event-logger-nodes/newspack-event-logger-nodes.php' ], [ 'newspack-nodes-main/newspack-nodes.php', 'newspack-event-logger-nodes/newspack-event-logger-nodes.php' ] ],
			'the substrate alone'                   => [ [ 'akismet/akismet.php', 'newspack-nodes/newspack-nodes.php' ], [ 'newspack-nodes/newspack-nodes.php' ] ],
			'the logger alone'                      => [ [ 'newspack-event-logger-nodes/newspack-event-logger-nodes.php', 'woocommerce/woocommerce.php' ], [ 'newspack-event-logger-nodes/newspack-event-logger-nodes.php' ] ],
			'neither'                               => [ [ 'akismet/akismet.php', 'woocommerce/woocommerce.php' ], [] ],
		];
		foreach ( $cases as $case => [ $site, $lean ] ) {
			$this->assertSame( $lean, \newspack_profiler_lean_plugins( $site, self::fetch_server(), $body, ...self::$front ), $case );
		}
	}

	/**
	 * Through the filter, a hub's fetch narrows only the read the plugin loop
	 * makes, from inside `wp_get_active_and_valid_plugins()`. A read made
	 * anywhere else, before the loop or after it, returns the stored list, so
	 * a write built from one keeps every plugin.
	 */
	public function test_the_filter_narrows_the_plugin_loops_read_alone(): void {
		require_once \dirname( __DIR__ ) . '/fixtures/wp-plugin-loop.php';
		$GLOBALS['_wp_actions']              = [];
		$GLOBALS['newspack_profiler_body']   = static fn (): string => self::command_to( 'raw-logs', 'read_block' ) . "\n";
		$GLOBALS['newspack_profiler_loaded'] = static fn (): array => [ '/index.php', '/wp-blog-header.php' ];
		$server                              = $_SERVER;
		$_SERVER                             = self::fetch_server();
		try {
			self::load_drop_in();
			$before = \apply_filters( 'option_active_plugins', self::SITE_PLUGINS );
			$loop   = \wp_get_active_and_valid_plugins( self::SITE_PLUGINS );
			$after  = \apply_filters( 'option_active_plugins', self::SITE_PLUGINS );
		} finally {
			$_SERVER = $server;
		}

		$this->assertSame( self::SITE_PLUGINS, $before, 'an mu-plugin reads the stored list' );
		$this->assertSame( [ 'newspack-nodes/newspack-nodes.php', 'newspack-event-logger-nodes/newspack-event-logger-nodes.php' ], $loop );
		$this->assertSame( self::SITE_PLUGINS, $after, 'a plugin reads, and writes, the stored list' );
	}

	/** Through the filter, each read of `active_plugins` comes back as written on an ordinary request. */
	public function test_the_filter_reads_each_value_as_written(): void {
		$GLOBALS['_wp_actions'] = [];
		$server                 = $_SERVER;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/cincinnati/kea-5108/';
		try {
			self::load_drop_in();
			$first  = \apply_filters( 'option_active_plugins', self::SITE_PLUGINS );
			$second = \apply_filters( 'option_active_plugins', [ 'woocommerce/woocommerce.php' ] );
		} finally {
			$_SERVER = $server;
		}

		$this->assertSame( self::SITE_PLUGINS, $first );
		$this->assertSame( [ 'woocommerce/woocommerce.php' ], $second, 'a plugin activated mid-request reads back' );
	}

	/** A request on any other route never reads its body. */
	public function test_another_route_never_reads_the_body(): void {
		self::load_drop_in();
		$read = 0;
		\newspack_profiler_lean_plugins(
			self::SITE_PLUGINS,
			self::fetch_server( [ 'REQUEST_URI' => '/wp-json/newspack/v1/reader' ] ),
			static function () use ( &$read ): string {
				++$read;
				return '';
			},
			...self::$front
		);

		$this->assertSame( 0, $read );
	}

	/** Load the drop-in at file scope, as WordPress includes it. */
	private static function load_drop_in(): void {
		require \dirname( __DIR__, 2 ) . '/mu-plugins/00-newspack-profiler.php';
	}

	/**
	 * The top of the error-handler stack, read without changing it.
	 *
	 * @return callable|null
	 */
	private static function top_handler(): ?callable {
		$top = \set_error_handler( static fn (): bool => false );
		\restore_error_handler();
		return $top;
	}
}
