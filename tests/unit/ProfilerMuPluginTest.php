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

	protected function setUp(): void {
		parent::setUp();
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
		unset( $GLOBALS['newspack_profiler'] );
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
		$this->assertSame( [ '2 notice /kea/7746.php:1, 4 dropped before boot' ], \array_column( self::entries_of( $entries, Log_Manager::PHP_ERRORS ), 'm' ) );
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
