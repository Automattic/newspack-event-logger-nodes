<?php
/**
 * PHP's non-fatal errors and WordPress's deprecations, recorded behind a
 * rule's `log_php_errors`: one row per distinct error, a count at the close.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\App\Core;
use Newspack_Event_Logger_Nodes\App\Php_Errors;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Owner;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

require_once \dirname( __DIR__ ) . '/fixtures/owners/abspath/wp-includes/functions.php';
require_once \dirname( __DIR__ ) . '/fixtures/owners/plugins/kea-7746/kea-7746-misuse.php';

#[CoversClass( Php_Errors::class )]
#[CoversClass( Log_Manager::class )]
final class PhpErrorsTest extends TestCase {

	/** The categories a PHP error row is written under. */
	private const ROWS = [ 'php (warning)', 'php (notice)', 'php (deprecated)' ];

	/** @var array<string,mixed> */
	private array $server;

	/** @var array<string,mixed> */
	private array $actions;

	/** @var callable|null The error handler the test began under. */
	private $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->server  = $_SERVER;
		$this->actions = $GLOBALS['_wp_actions'];
		$this->handler = self::top_handler();
		$fixtures      = \dirname( __DIR__ ) . '/fixtures/owners';
		self::own_files_under(
			[
				'plugins'    => "{$fixtures}/plugins",
				'mu_plugins' => "{$fixtures}/mu-plugins",
				'themes'     => [ "{$fixtures}/themes" ],
				'content'    => "{$fixtures}/content",
				'abspath'    => "{$fixtures}/abspath/",
				'managed'    => "{$fixtures}/managed",
			]
		);
	}

	protected function tearDown(): void {
		// A failed assertion strands a handler; never let it reach the next test.
		for ( $i = 0; $i < 8 && self::top_handler() !== $this->handler; $i++ ) {
			\restore_error_handler();
		}
		unset( $GLOBALS['kea_7746_wp_debug'], $GLOBALS['wp_version'], $GLOBALS['newspack_profiler'] );
		Log_Manager::reset();
		$GLOBALS['_wp_actions'] = $this->actions;
		$_SERVER                = $this->server;
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		unset( $GLOBALS['_wp_options'][ \Newspack_Event_Logger_Nodes\Rule_Set::OPTION_RULES ] );
		parent::tearDown();
	}

	public function test_each_level_writes_its_row_and_marks_the_silenced_ones(): void {
		$lm = $this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );

		$lm->record_php_error( 'warning', 'kea 7746 undefined array key', self::plugin_file(), 41, false );
		$lm->record_php_error( 'notice', 'kea 7746 notice', self::plugin_file(), 42, true );
		$lm->record_php_error( 'deprecated', 'kea 7746 deprecated', self::plugin_file(), 43, true );

		$this->assertSame(
			[
				[ 'php (warning)', 'kea 7746 undefined array key', self::plugin_file(), 41, 'kea-7746', null ],
				[ 'php (notice)', 'kea 7746 notice', self::plugin_file(), 42, 'kea-7746', true ],
				[ 'php (deprecated)', 'kea 7746 deprecated', self::plugin_file(), 43, 'kea-7746', true ],
			],
			self::rows( $this->logged_entries() )
		);
	}

	public function test_a_level_it_does_not_record_is_refused(): void {
		$lm = $this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );

		$this->expectException( \InvalidArgumentException::class );
		$lm->record_php_error( 'error', 'kea 7746 fatal', self::plugin_file(), 5, false );
	}

	/** The handler maps each PHP level, and reads `silenced` off `error_reporting`. */
	public function test_the_handler_records_each_php_level_and_what_error_reporting_hid(): void {
		$this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		\set_error_handler( static fn (): bool => true );
		( new Php_Errors() )->install();
		$handler = self::top_handler();
		$level   = \error_reporting( \E_ALL & ~\E_DEPRECATED & ~\E_USER_DEPRECATED );
		try {
			foreach ( [ \E_WARNING, \E_USER_WARNING, \E_NOTICE, \E_USER_NOTICE, \E_DEPRECATED, \E_USER_DEPRECATED, \E_USER_ERROR, \E_RECOVERABLE_ERROR ] as $i => $errno ) {
				$handler( $errno, "kea 7746 errno {$errno}", self::plugin_file(), 100 + $i );
			}
		} finally {
			\error_reporting( $level );
		}

		$this->assertSame(
			[
				[ 'php (warning)', 'kea 7746 errno ' . \E_WARNING, self::plugin_file(), 100, 'kea-7746', null ],
				[ 'php (warning)', 'kea 7746 errno ' . \E_USER_WARNING, self::plugin_file(), 101, 'kea-7746', null ],
				[ 'php (notice)', 'kea 7746 errno ' . \E_NOTICE, self::plugin_file(), 102, 'kea-7746', null ],
				[ 'php (notice)', 'kea 7746 errno ' . \E_USER_NOTICE, self::plugin_file(), 103, 'kea-7746', null ],
				[ 'php (deprecated)', 'kea 7746 errno ' . \E_DEPRECATED, self::plugin_file(), 104, 'kea-7746', true ],
				[ 'php (deprecated)', 'kea 7746 errno ' . \E_USER_DEPRECATED, self::plugin_file(), 105, 'kea-7746', true ],
			],
			self::rows( $this->logged_entries() )
		);
	}

	/**
	 * Each misuse WordPress reports through an action is recorded at the
	 * plugin line that misused core, with `WP_DEBUG` off.
	 *
	 * @param string $api     The misused core function.
	 * @param string $k       The row's category.
	 * @param string $message The row's `m`.
	 */
	#[DataProvider( 'wordpress_misuse' )]
	public function test_each_wordpress_action_records_its_row_with_wp_debug_off( string $api, string $k, string $message ): void {
		$this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		new Core();

		\kea_7746_misuse( $api );

		$this->assertSame( [ [ $k, $message, self::misuse_file(), self::misuse_line(), 'kea-7746', true ] ], self::rows( $this->logged_entries() ) );
	}

	/** @return array<string,array{0:string,1:string,2:string}> */
	public static function wordpress_misuse(): array {
		return [
			'deprecated_function_run'     => [ 'kea_7746_deprecated_api', 'php (deprecated)', 'deprecated_function kea_7746_deprecated_api since 6.4.0, use kea_7746_current_api' ],
			'deprecated_constructor_run'  => [ 'kea_7746_old_constructor', 'php (deprecated)', 'deprecated_constructor Kea_7746_Widget in Kea_7746_Parent since 4.3.0' ],
			'deprecated_class_run'        => [ 'kea_7746_old_class', 'php (deprecated)', 'deprecated_class Kea_7746_Legacy since 6.4.0, use Kea_7746_Modern' ],
			'deprecated_file_included'    => [ 'kea_7746_old_file', 'php (deprecated)', 'deprecated_file kea-7746-legacy.php since 6.4.0, use kea-7746-modern.php: kea moved' ],
			'deprecated_argument_run'     => [ 'kea_7746_old_argument', 'php (deprecated)', 'deprecated_argument kea_7746_old_argument since 6.4.0: kea $flag is ignored' ],
			'deprecated_hook_run'         => [ 'kea_7746_old_hook', 'php (deprecated)', 'deprecated_hook kea_7746_old_hook since 6.4.0, use kea_7746_new_hook: kea hook moved' ],
			'doing_it_wrong_run'          => [ 'kea_7746_checked_api', 'php (notice)', 'doing_it_wrong kea_7746_checked_api: kea 7746 called too early (6.5.0)' ],
			'wp_trigger_error_always_run' => [ 'kea_7746_triggering_api', 'php (warning)', 'wp_trigger_error kea_7746_triggering_api: kea 7746 trigger' ],
		];
	}

	/**
	 * Under `WP_DEBUG` a helper also calls `wp_trigger_error()`, which fires
	 * two more actions and raises the error itself. Each misuse still writes
	 * one row and counts once.
	 */
	public function test_wp_debug_neither_doubles_a_row_nor_counts_it_twice(): void {
		$GLOBALS['kea_7746_wp_debug'] = true;
		// Beneath ours, so the raised error never reaches PHPUnit's handler.
		\set_error_handler( static fn (): bool => true );
		$this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		new Core();

		\kea_7746_misuse( 'kea_7746_deprecated_api' );
		\kea_7746_misuse( 'kea_7746_triggering_api' );

		$entries = $this->logged_entries();
		$this->assertSame( [ 'php (deprecated)', 'php (warning)' ], \array_column( self::rows( $entries ), 0 ) );
		$this->assertSame( [], self::entries_of( $entries, Log_Manager::PHP_ERRORS ) );
	}

	/**
	 * Before 7.0 `wp_trigger_error()` fires its debug action alone. The
	 * harness defines no `WP_DEBUG`, so the row still reads as silenced.
	 */
	public function test_wp_trigger_error_run_records_where_the_always_action_never_fires(): void {
		$GLOBALS['kea_7746_wp_debug'] = true;
		$GLOBALS['wp_version']        = '6.9.1';
		\set_error_handler( static fn (): bool => true );
		$this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		new Core();

		\kea_7746_misuse( 'kea_7746_triggering_api' );

		$this->assertSame(
			[ [ 'php (warning)', 'wp_trigger_error kea_7746_triggering_api: kea 7746 trigger', self::misuse_file(), self::misuse_line(), 'kea-7746', true ] ],
			self::rows( $this->logged_entries() )
		);
	}

	/**
	 * The debug action is bound only on a WordPress that lacks the
	 * always-run one, so a newer one never hears a misuse twice.
	 *
	 * @param string $version What `get_bloginfo( 'version' )` answers.
	 * @param bool   $bound   Whether `wp_trigger_error_run` is listened to.
	 */
	#[DataProvider( 'wordpress_versions' )]
	public function test_the_debug_action_is_bound_only_before_7_0( string $version, bool $bound ): void {
		$GLOBALS['wp_version'] = $version;

		$hooks = \array_column( ( new Php_Errors() )->listeners(), 0 );

		$this->assertSame(
			[ $bound ? 'wp_trigger_error_run' : 'wp_trigger_error_always_run' ],
			\array_values( \array_intersect( $hooks, [ 'wp_trigger_error_run', 'wp_trigger_error_always_run' ] ) )
		);
	}

	/** @return array<string,array{0:string,1:bool}> */
	public static function wordpress_versions(): array {
		return [
			'6.9.1' => [ '6.9.1', true ],
			'7.0'   => [ '7.0', false ],
			'7.1.3' => [ '7.1.3', false ],
		];
	}

	/**
	 * A row names the nearest code outward that neither core nor an unknown
	 * file owns: a listener raising `wp_trigger_error()` itself, a plugin
	 * calling an API whose helper sits a core frame further down, and one
	 * three core frames down reached through a wrapped listener.
	 *
	 * @param string $call   What the test calls.
	 * @param string $k      The row's category.
	 * @param string $m      The row's `m`.
	 * @param string $needle The plugin line the row names.
	 */
	#[DataProvider( 'misuse_sites' )]
	public function test_a_wordpress_row_names_the_first_plugin_frame_outward( string $call, string $k, string $m, string $needle ): void {
		$lm = $this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		new Core();
		// As App\Core wraps a significant hook's listener, with WP_Hook's two frames.
		foreach ( $GLOBALS['_wp_actions'] as $hook => $callbacks ) {
			foreach ( $callbacks as $i => $original ) {
				$GLOBALS['_wp_actions'][ $hook ][ $i ] = static fn ( mixed ...$args ): mixed => ( static fn ( mixed ...$a ): mixed => $lm->timed( 'kea 7746 wrapped', $original, null, [], $a ) )( ...$args );
			}
		}

		if ( 'callback' === $call ) {
			\add_action( 'kea_7746_event', 'kea_7746_callback' );
			\do_action( 'kea_7746_event' );
		} else {
			\kea_7746_misuse( $call );
		}

		$this->assertSame( [ [ $k, $m, self::misuse_file(), self::misuse_line( $needle ), 'kea-7746', true ] ], self::rows( $this->logged_entries() ) );
	}

	/** @return array<string,array{0:string,1:string,2:string,3:string}> */
	public static function misuse_sites(): array {
		return [
			'a listener calling wp_trigger_error()' => [ 'callback', 'php (warning)', 'wp_trigger_error kea_7746_callback: kea 7746 direct', "\twp_trigger_error( __FUNCTION__, 'kea 7746 direct', E_USER_WARNING );\n" ],
			'a too-early enqueue'                   => [ 'kea_7746_enqueue_script', 'php (notice)', 'doing_it_wrong kea_7746_enqueue_script: kea 7746 enqueued too early (3.3.0)', "\t\$api();\n" ],
			'a misuse three core frames down'       => [ 'kea_7746_deep_api', 'php (notice)', 'doing_it_wrong kea_7746_deep_api: kea 7746 deep (6.6.0)', "\t\$api();\n" ],
		];
	}

	/**
	 * Review Focus 2: ten thousand raises at one line are one row and one
	 * count, however their messages vary; the row keeps the first.
	 */
	public function test_repeats_only_count_and_the_count_row_closes_the_record(): void {
		$lm   = $this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		$file = self::plugin_file();
		for ( $i = 0; $i < 10000; $i++ ) {
			$lm->record_php_error( 'deprecated', "kea 7746 deprecated key {$i}", $file, 7, false );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$lm->record_php_error( 'warning', 'kea 7746 thrice', $file, 8, false );
		}
		$lm->record_php_error( 'notice', 'kea 7746 once', $file, 9, false );

		$entries = $this->logged_entries();
		$this->assertSame( [ 'php (deprecated)', 'php (warning)', 'php (notice)' ], \array_column( self::rows( $entries ), 0 ) );
		$this->assertSame( 'kea 7746 deprecated key 0', self::rows( $entries )[0][1] );
		$this->assertSame( [ "10000 deprecated {$file}:7, 3 warning {$file}:8" ], \array_column( self::entries_of( $entries, Log_Manager::PHP_ERRORS ), 'm' ) );
		$kinds = \array_column( $entries, 'k' );
		$this->assertSame( [ Log_Manager::PHP_ERRORS, Log_Manager::REQUEST_COMPLETE ], \array_slice( $kinds, -2 ), 'the count row is the close, ahead of the terminal' );
		$this->assertArrayHasKey( Log_Manager::PHP_ERRORS, Log_Manager::CLOSE_ROWS );
	}

	/** The count row keeps the most-repeated keys and says how many it left out. */
	public function test_the_count_row_fits_one_entry(): void {
		$lm = $this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		for ( $line = 1; $line <= 400; $line++ ) {
			for ( $i = 0; $i < 2 + ( 400 === $line ? 5 : 0 ); $i++ ) {
				$lm->record_php_error( 'notice', "kea 7746 line {$line}", self::plugin_file(), $line, false );
			}
		}

		$row = self::entries_of( $this->logged_entries(), Log_Manager::PHP_ERRORS )[0];
		$this->assertArrayNotHasKey( 'truncated', $row );
		$this->assertStringStartsWith( '7 notice ' . self::plugin_file() . ':400, 2 notice ', $row['m'] );
		$this->assertMatchesRegularExpression( '/, \d+ more$/', $row['m'] );
	}

	/**
	 * A database driver's warning keeps its code and its text up to the
	 * first quote, where the values it failed on begin.
	 *
	 * @param string $raised What the driver raised.
	 * @param string $m      The row's `m`.
	 */
	#[DataProvider( 'driver_warnings' )]
	public function test_a_driver_warning_is_cut_before_its_values( string $raised, string $m ): void {
		$lm = $this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );

		$lm->record_php_error( 'warning', $raised, self::plugin_file(), 61, false );

		$this->assertSame( [ $m ], \array_column( self::rows( $this->logged_entries() ), 1 ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function driver_warnings(): array {
		return [
			'a syntax error'   => [
				"mysqli_query(): (42000/1064): You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near 'password = 'hunter2'' at line 1",
				'[1064] mysqli_query(): (42000/1064): You have an error in your SQL syntax; check the manual that corresponds to your MariaDB server version for the right syntax to use near',
			],
			'access denied'    => [
				"mysqli_real_connect(): (HY000/1045): Access denied for user 'kea_7746'@'10.7.7.46' (using password: YES)",
				'[1045] mysqli_real_connect(): (HY000/1045): Access denied for user',
			],
			'a PDO statement'  => [
				"PDOStatement::execute(): SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'kea7746@example.test' for key 'email'",
				'[23000] PDOStatement::execute(): SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry',
			],
			'a URL in the way' => [
				'mysqli_real_connect(): (HY000/2002): getaddrinfo for db.kea.test/?api_key=kea7746secret failed: Name does not resolve',
				'[2002] mysqli_real_connect(): (HY000/2002): getaddrinfo for db.kea.test/?api_key=[REDACTED]',
			],
			'not a driver'     => [
				"Undefined array key 'kea 7746'",
				"Undefined array key 'kea 7746'",
			],
		];
	}

	/** The handler beneath is called whatever recording threw, and the throw escapes. */
	public function test_the_handler_chains_when_recording_throws(): void {
		$this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		$seen = [];
		\set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$seen ): bool {
				$seen[] = $errstr;
				return true;
			}
		);
		( new Php_Errors() )->install();
		Owner::$roots = static function (): array {
			throw new \RuntimeException( 'kea 7746 roots unread' );
		};

		try {
			self::top_handler()( \E_USER_WARNING, 'kea 7746 thrown through', self::plugin_file(), 62 );
			$this->fail( 'the throw escapes' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'kea 7746 roots unread', $e->getMessage() );
		}
		$this->assertSame( [ 'kea 7746 thrown through' ], $seen );
	}

	/** Both handlers register for one mask, Query Monitor's. */
	public function test_both_handlers_share_one_mask(): void {
		\set_error_handler( static fn (): bool => true );
		require \dirname( __DIR__, 2 ) . '/mu-plugins/00-newspack-profiler.php';

		$this->assertSame( \E_ALL & ~( \E_ERROR | \E_PARSE | \E_COMPILE_ERROR | \E_USER_ERROR | \E_RECOVERABLE_ERROR ), Php_Errors::MASK );
		$this->assertSame( Php_Errors::MASK, \NEWSPACK_PROFILER_PHP_ERROR_MASK );
	}

	/**
	 * A fatal level reaches neither handler, so PHP's own ends the request
	 * even over a handler beneath that would have absorbed it.
	 *
	 * @param string $install The PHP that puts the handler on the stack.
	 */
	#[DataProvider( 'installers' )]
	public function test_a_fatal_level_bypasses_the_handler( string $install ): void {
		$script = 'require ' . \var_export( \dirname( __DIR__ ) . '/bootstrap.php', true ) . ';'
			. ' \set_error_handler( static fn (): bool => true );'
			. " {$install}"
			. ' \trigger_error( "kea 7746 fatal", \E_USER_ERROR );'
			. ' echo "kea 7746 survived";';
		$env = \getenv();
		unset( $env['NEWSPACK_TEST_BASE_DIR'], $env['LOCAL_NEWSPACK_NODES_CONF'] );
		$process = \proc_open( [ \PHP_BINARY, '-d', 'display_errors=stderr', '-r', $script ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, $env );
		$this->assertIsResource( $process );
		$out = (string) \stream_get_contents( $pipes[1] );
		$err = (string) \stream_get_contents( $pipes[2] );
		\fclose( $pipes[1] );
		\fclose( $pipes[2] );
		\proc_close( $process );

		$this->assertStringNotContainsString( 'kea 7746 survived', $out, $err );
		$this->assertStringContainsString( 'kea 7746 fatal', $out . $err );
	}

	/** @return array<string,array{0:string}> */
	public static function installers(): array {
		return [
			'Php_Errors' => [ '( new \\Newspack_Event_Logger_Nodes\\App\\Php_Errors() )->install();' ],
			'the drop-in' => [ 'require ' . \var_export( \dirname( __DIR__, 2 ) . '/mu-plugins/00-newspack-profiler.php', true ) . ';' ],
		];
	}

	/** Review Focus 1: a collector registered first keeps every error. */
	public function test_the_handler_chains_to_one_that_never_chains_and_returns_false(): void {
		$this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		$seen = [];
		// Query Monitor's shape: it records, never chains, and returns false.
		\set_error_handler(
			static function ( int $errno, string $errstr, string $errfile = '', int $errline = 0 ) use ( &$seen ): bool {
				$seen[] = [ $errno, $errstr, $errfile, $errline ];
				return false;
			}
		);
		( new Php_Errors() )->install();

		$verdict = self::top_handler()( \E_USER_WARNING, 'kea 7746 chained', self::plugin_file(), 12 );

		$this->assertFalse( $verdict );
		$this->assertSame( [ [ \E_USER_WARNING, 'kea 7746 chained', self::plugin_file(), 12 ] ], $seen );
		$this->assertSame( [ 'kea 7746 chained' ], \array_column( self::rows( $this->logged_entries() ), 1 ) );
	}

	/** A handler beneath that took the error over keeps PHP's own from running. */
	public function test_the_handler_returns_what_the_handler_beneath_answered(): void {
		$this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		\set_error_handler( static fn (): bool => true );
		( new Php_Errors() )->install();

		$this->assertTrue( self::top_handler()( \E_USER_NOTICE, 'kea 7746 handled', self::plugin_file(), 14 ) );
	}

	/** With no handler beneath, PHP's own still runs. */
	public function test_the_handler_alone_returns_false(): void {
		$this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		\set_error_handler( null );
		( new Php_Errors() )->install();

		$this->assertFalse( self::top_handler()( \E_USER_NOTICE, 'kea 7746 alone', self::plugin_file(), 15 ) );
		$this->assertSame( [ 'kea 7746 alone' ], \array_column( self::rows( $this->logged_entries() ), 1 ) );
	}

	/** Review Focus 5: an error raised while one is written is ignored. */
	public function test_an_error_raised_while_writing_one_is_ignored(): void {
		$lm    = $this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		$roots = Owner::$roots;
		self::assertNotNull( $roots );
		Owner::$roots = static function () use ( $lm, $roots ): array {
			$lm->record_php_error( 'warning', 'kea 7746 raised while writing', self::plugin_file(), 99, false );
			return $roots();
		};

		$lm->record_php_error( 'deprecated', 'kea 7746 outer', self::plugin_file(), 98, false );
		$lm->record_php_error( 'warning', 'kea 7746 raised while writing', self::plugin_file(), 99, false );

		$this->assertSame( [ 'kea 7746 outer', 'kea 7746 raised while writing' ], \array_column( self::rows( $this->logged_entries() ), 1 ) );
	}

	public function test_with_the_flag_off_no_handler_is_installed_and_nothing_recorded(): void {
		$lm = $this->start_logged_request( '/kea/7746' );
		new Core();

		$this->assertSame( $this->handler, self::top_handler() );
		$this->assertArrayNotHasKey( 'deprecated_function_run', $GLOBALS['_wp_test_filters'] );
		\kea_7746_misuse( 'kea_7746_deprecated_api' );
		$lm->record_php_error( 'warning', 'kea 7746 unrecorded', self::plugin_file(), 3, false );
		$lm->record_php_error( 'warning', 'kea 7746 unrecorded', self::plugin_file(), 3, false );

		$entries = $this->logged_entries();
		$this->assertSame( [], self::rows( $entries ) );
		$this->assertSame( [], self::entries_of( $entries, Log_Manager::PHP_ERRORS ) );
	}

	/** The handler and the actions follow the rule of each scope a request enters. */
	public function test_a_scope_without_the_flag_unbinds_the_handler_and_the_actions(): void {
		$this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		new Core();
		$ours = self::top_handler();
		$this->assertNotSame( $this->handler, $ours );
		$this->assertArrayHasKey( 'doing_it_wrong_run', $GLOBALS['_wp_test_filters'] );

		Log_Manager::begin_job_context( 'kea-7746-job' );
		$this->assertSame( $this->handler, self::top_handler(), 'the job is governed by no rule' );
		$this->assertArrayNotHasKey( 'doing_it_wrong_run', $GLOBALS['_wp_test_filters'] );

		Log_Manager::end_job_context();
		$this->assertSame( $ours, self::top_handler() );
		$this->assertArrayHasKey( 'doing_it_wrong_run', $GLOBALS['_wp_test_filters'] );
	}

	/** Review Focus 1: a collector installed over the drop-in's handler is chained to. */
	public function test_a_collector_installed_over_the_drop_in_is_chained_to_at_boot(): void {
		\set_error_handler( static fn (): bool => true );
		require \dirname( __DIR__, 2 ) . '/mu-plugins/00-newspack-profiler.php';
		$seen = [];
		\set_error_handler(
			static function ( int $errno, string $errstr ) use ( &$seen ): bool {
				$seen[] = $errstr;
				return false;
			}
		);
		$lm = $this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		$lm->flush_php_errors();
		new Core();

		self::top_handler()( \E_USER_NOTICE, 'kea 7746 after the collector', self::plugin_file(), 16 );

		$this->assertSame( [ 'kea 7746 after the collector' ], $seen );
		$this->assertSame( [ 'kea 7746 after the collector' ], \array_column( self::rows( $this->logged_entries() ), 1 ) );
	}

	/**
	 * A misuse at plugin load, before the logger boots, reaches the drop-in
	 * only as the error `wp_trigger_error()` raises under `WP_DEBUG`; the
	 * replay walks the frames it kept out to the plugin line.
	 */
	public function test_a_load_time_misuse_replays_at_the_plugin_line(): void {
		$GLOBALS['kea_7746_wp_debug'] = true;
		\set_error_handler( static fn (): bool => true );
		require \dirname( __DIR__, 2 ) . '/mu-plugins/00-newspack-profiler.php';

		$level = \error_reporting( \E_ALL );
		try {
			\kea_7746_misuse( 'kea_7746_enqueue_script' );
		} finally {
			\error_reporting( $level );
		}
		$lm = $this->start_logged_request( '/kea/7746', [ 'log_php_errors' => true ] );
		$lm->flush_php_errors();

		$this->assertSame(
			[ [ 'php (notice)', 'kea_7746_enqueue_script(): kea 7746 enqueued too early', self::misuse_file(), self::misuse_line(), 'kea-7746', null ] ],
			self::rows( $this->logged_entries() )
		);
	}

	/**
	 * The handler's own top of stack, read without changing it.
	 *
	 * @return callable|null
	 */
	private static function top_handler(): ?callable {
		$top = \set_error_handler( static fn (): bool => false );
		\restore_error_handler();
		return $top;
	}

	/**
	 * Each PHP error row as its category, `m`, file, line, owner and `silenced`.
	 *
	 * @param list<array<string,mixed>> $entries What the request wrote.
	 * @return list<array{0:mixed,1:mixed,2:mixed,3:mixed,4:mixed,5:mixed}>
	 */
	private static function rows( array $entries ): array {
		return \array_map(
			static fn ( array $e ): array => [ $e['k'], $e['m'] ?? null, $e['file'] ?? null, $e['line'] ?? null, $e['owner'] ?? null, $e['silenced'] ?? null ],
			self::entries_of( $entries, ...self::ROWS )
		);
	}

	/** A file the `kea-7746` plugin owns. */
	private static function plugin_file(): string {
		return \dirname( __DIR__ ) . '/fixtures/owners/plugins/kea-7746/class-kea-7746-caller.php';
	}

	/** The plugin file that misuses core. */
	private static function misuse_file(): string {
		return \dirname( __DIR__ ) . '/fixtures/owners/plugins/kea-7746/kea-7746-misuse.php';
	}

	/**
	 * The line of that file that makes the call.
	 *
	 * @param string $needle The line's text.
	 */
	private static function misuse_line( string $needle = "\t\$api();\n" ): int {
		$lines = \file( self::misuse_file() );
		self::assertIsArray( $lines );
		$at = \array_search( $needle, $lines, true );
		self::assertIsInt( $at );
		return $at + 1;
	}
}
