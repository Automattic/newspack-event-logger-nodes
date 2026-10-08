<?php
/**
 * Tests for LogManager (per-request JSONL writer atop Newspack_Nodes\Topic).
 *
 * Mirrors the upstream Newspack_Performance_Logger\LogManagerTest with adaptations
 * for the new namespace and Topic-backed storage. Tests that require real
 * `Topic`/`Partition` round-trips construct the Config via env-var convention
 * (`LOCAL_NEWSPACK_NODES_CONF`) — see Newspack_Event_Logger_Nodes\Config for the
 * canonical override path; tests that don't need disk I/O exercise the
 * singleton lifecycle and config gating directly.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Worker_Should_Stop;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Topic_Node;

#[CoversClass( Log_Manager::class )]
class LogManagerTest extends TestCase {

	/** @var array Original $_SERVER backup. */
	private array $orig_server;

	protected function setUp(): void {
		parent::setUp();

		// Save original $_SERVER.
		$this->orig_server = $_SERVER;

		// Reset singleton so each test starts fresh.
		Log_Manager::reset();
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			Config::reset();
		}

		// Set required $_SERVER vars.
		$_SERVER['REQUEST_URI']    = '/test/page';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['SERVER_NAME']    = 'localhost';
		$_SERVER['HTTP_HOST']      = 'localhost';
		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['UNIQUE_ID'], $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );

		// Point config to pre-written test config.
		@\mkdir( self::test_dir() . '/logs', 0755, true );
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			Config::reset();
		}
	}

	protected function tearDown(): void {
		Log_Manager::reset();
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			Config::reset();
		}

		// Restore original $_SERVER.
		$_SERVER = $this->orig_server;

		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		$this->rmdir_recursive( self::test_dir() );
		// Rules option is a fake-store global, not scoped to $_SERVER — drain it
		// so it doesn't leak into other tests/suites.
		unset( $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] );
		parent::tearDown();
	}

	/**
	 * Seed the durable rules option in the fake option store.
	 *
	 * @param array<int, array<string, mixed>> $rules Rule shapes (Rule::from_array()).
	 */
	private function set_rules_option( array $rules ): void {
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = $rules;
	}

	/**
	 * The CPU a record's process spent between its first and last `resources`
	 * samples, from either producer's spelling: this class's `%F` and the
	 * Perl engine's plain number.
	 */
	public function test_cpu_between_samples_differences_the_first_and_last(): void {
		$cpu = Log_Manager::cpu_between_samples(
			[
				[ 'k' => 'process (start)', 'ts' => 10.0 ],
				[ 'k' => 'resources', 'ts' => 10.25, 'm' => 'utime => 0.040000, stime => 0.010000, maxrss => 1' ],
				[ 'k' => 'resources', 'ts' => 11.0, 'm' => 'utime => 0.5, stime => 0.02' ],
				[ 'k' => 'resources', 'ts' => 12.5, 'm' => 'utime => 1.25, stime => 0.11, maxrss => 9' ],
			]
		);

		$this->assertSame( 10.25, $cpu['from'] );
		$this->assertSame( 12.5, $cpu['to'] );
		$this->assertEqualsWithDelta( 1310.0, $cpu['cpu_ms'], 1e-6 );
	}

	/** A number is a number however it is spelled: getrusage() can print 4e-05. */
	public function test_cpu_between_samples_reads_exponent_notation(): void {
		$cpu = Log_Manager::cpu_between_samples(
			[
				[ 'k' => 'resources', 'ts' => 1.0, 'm' => 'utime => 4e-05, stime => 0' ],
				[ 'k' => 'resources', 'ts' => 2.0, 'm' => 'utime => 0.25, stime => 1.5E-2' ],
			]
		);

		$this->assertEqualsWithDelta( 264.96, $cpu['cpu_ms'], 1e-6 );
	}

	/** A comma decimal is a locale leaking into the sample, and is refused. */
	public function test_cpu_between_samples_refuses_a_comma_decimal(): void {
		$this->expectException( \UnexpectedValueException::class );
		Log_Manager::cpu_between_samples(
			[
				[ 'k' => 'resources', 'ts' => 1.0, 'm' => 'utime => 0,128355, stime => 0,000000' ],
				[ 'k' => 'resources', 'ts' => 2.0, 'm' => 'utime => 0.5, stime => 0.0' ],
			]
		);
	}

	public function test_cpu_between_samples_needs_two(): void {
		$this->assertNull( Log_Manager::cpu_between_samples( [ [ 'k' => 'resources', 'ts' => 1.0, 'm' => 'utime => 1.0, stime => 0.0' ] ] ) );
	}

	/** A sample is the whole evidence, so one that does not parse is refused. */
	public function test_cpu_between_samples_refuses_a_sample_it_cannot_read(): void {
		$this->expectException( \UnexpectedValueException::class );
		Log_Manager::cpu_between_samples(
			[
				[ 'k' => 'resources', 'ts' => 1.0, 'm' => 'utime => 1.0, stime => 0.0' ],
				[ 'k' => 'resources', 'ts' => 2.0, 'm' => 'maxrss => 9' ],
			]
		);
	}

	public function test_url_hash_is_a_stable_12_char_fnv1a_hash(): void {
		$this->assertSame( '13ead606a028', Log_Manager::url_hash( '/wp-cron.php' ) );
		$this->assertSame( '2a0c975ed95c', Log_Manager::url_hash( '/' ) );
		$this->assertSame( 12, \strlen( Log_Manager::url_hash( '/anything/at/all' ) ) );
	}

	public function test_url_hash_keeps_the_worker_type_marker_distinct(): void {
		$this->assertNotSame(
			Log_Manager::url_hash( '/' ),
			Log_Manager::url_hash( '/?worker_type=combined' )
		);
	}

	/**
	 * Load the `logging-enabled` config (enable_logging=true) and construct a
	 * fresh Log_Manager against the current REQUEST_URI + rules option.
	 */
	private function fresh_log_manager(): Log_Manager {
		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();
		return Log_Manager::instance();
	}

	// rmdir_recursive() is inherited from RuntimeTestCase (newspack-nodes/tests/Helpers/TestCase.php).

	/**
	 * Skip a test if the parallel-ported Config class isn't available yet.
	 * Tests in this suite that *write* through Topic need real Config; tests
	 * that exercise pure singleton/state behavior don't.
	 */
	private function require_config_or_skip(): void {
		if ( ! \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			$this->markTestSkipped( 'Config class not yet available (parallel agent porting).' );
		}
		// LogManager uses wp_json_encode for line serialization. The bootstrap
		// doesn't currently stub it — the bootstrap-owning agent needs to
		// add it (REPORTED in agent report).
		foreach ( [ 'wp_json_encode' ] as $fn ) {
			if ( ! \function_exists( $fn ) ) {
				$this->markTestSkipped( "WP function stub `{$fn}` not yet in tests/bootstrap.php; see agent report." );
			}
		}
	}

	// ── Singleton lifecycle ────────────────────────────────────────────────

	public function test_singleton_instance(): void {
		$this->require_config_or_skip();
		$instance1 = Log_Manager::instance();
		$instance2 = Log_Manager::instance();
		$this->assertSame( $instance1, $instance2 );
	}

	public function test_reset_clears_singleton(): void {
		$this->require_config_or_skip();
		$instance1 = Log_Manager::instance();
		Log_Manager::reset();
		$instance2 = Log_Manager::instance();
		$this->assertNotSame( $instance1, $instance2 );
	}

	/**
	 * Reentrant instance() during __construct() must return the partial $this,
	 * not create a second LogManager.
	 *
	 * Production trigger: Config::load_config() calls get_option(), which (when
	 * alloptions isn't cached) calls wpdb->query() → apply_filters('query', ...)
	 * → Core::hook_start → LogManager::instance(). Without assigning
	 * self::$instance = $this at the top of __construct, this recurses until
	 * xdebug's 512-frame limit kills the request.
	 *
	 * @see LogManager::__construct()
	 */
	public function test_construct_blocks_reentrant_instance(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		$reentrant_instance = null;
		$reentry_count      = 0;
		// Mute after first re-entry so the test exits even when the production
		// bug is reintroduced — assertNotSame below catches the regression
		// without the stack-overflow risk.
		$GLOBALS['_test_get_option_hook'] = function () use ( &$reentrant_instance, &$reentry_count ): void {
			if ( $reentry_count++ > 0 ) {
				return;
			}
			$reentrant_instance = Log_Manager::instance();
		};

		try {
			$top_instance = Log_Manager::instance();
			$this->assertSame(
				$top_instance,
				$reentrant_instance,
				'Reentrant instance() during construct must return the partial $this, not a new LogManager.'
			);
		} finally {
			unset( $GLOBALS['_test_get_option_hook'] );
		}
	}

	public function test_constructor_starts_when_logging_enabled(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();
		$this->assertTrue( $lm->is_started() );
	}

	public function test_constructor_does_not_start_when_config_disables_logging(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-disabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$this->assertFalse( $lm->is_started() );
	}

	// ── Request ID ─────────────────────────────────────────────────────────

	public function test_generate_request_id_format(): void {
		$rid = Log_Manager::generate_request_id();
		$this->assertIsString( $rid );
		$this->assertSame( 32, \strlen( $rid ) );
		// Should be alphanumeric (base36).
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+$/', $rid );
	}

	public function test_generate_request_id_uniqueness(): void {
		$ids = [];
		for ( $i = 0; $i < 50; $i++ ) {
			$ids[] = Log_Manager::generate_request_id();
		}
		$unique = \array_unique( $ids );
		$this->assertCount( 50, $unique, 'All generated request IDs should be unique' );
	}

	// ── message() / error() / warning() / info() ───────────────────────────

	public function test_message_returns_true(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'test_category' ] ] ] );
		$lm     = Log_Manager::instance();
		$result = $lm->message( 'test_category', [ 'm' => 'hello world' ] );
		$this->assertTrue( $result );
	}

	public function test_message_truncates_large_data(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'big_data' ] ] ] );
		$lm = Log_Manager::instance();

		// Create data larger than MAX_DATA_SIZE (3840 bytes).
		$large  = [ 'm' => \str_repeat( 'x', 4000 ) ];
		$result = $lm->message( 'big_data', $large );
		$this->assertTrue( $result );
		// If data exceeded limit, the entry would have 'truncated' => true.
		// The method should succeed regardless.
	}

	/**
	 * The size guard must fire on invalid-UTF8 data too: `wp_json_encode` without
	 * substitute flags returns false there, so the old guard SKIPPED truncation —
	 * then Message::packed substitutes U+FFFD (6 escaped bytes/bad byte) and the
	 * oversized record is silently dropped by the Partition. The stderr bridge now
	 * routes arbitrary substrate bytes through here.
	 */
	public function test_message_truncates_oversized_invalid_utf8_data(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'work', 'binstderr' ] ] ] );
		$lm = $this->fresh_log_manager();
		$lm->start( 'work' );
		// 4000 lone \xB1 bytes: invalid UTF-8, well over MAX_DATA_SIZE once escaped.
		$lm->message( 'binstderr', [ 'm' => \str_repeat( "\xB1", 4000 ) ] );
		$lm->finish();

		$this->assertNotNull(
			self::last_entry_of( $this->written_entries(), 'binstderr' ),
			'oversized invalid-UTF8 data must hit the truncation branch, not be dropped'
		);
	}

	// ── firehose ≤PIPE_BUF invariant (nothing writes >4KB into the firehose) ──

	public function test_firehose_partition_never_lifts_the_pipe_buf_cap(): void {
		// The firehose is multi-writer; the Partition drops oversize records WHOLE
		// so atomic appends stay safe. That safety depends on the cap never being
		// lifted — assert the materialized partition keeps large writes DISABLED.
		$this->require_config_or_skip();
		$lm = $this->fresh_log_manager();
		$lm->start( 'work' );
		$lm->message( 'materialize', [ 'm' => 'partition-8801' ] );

		$topic_prop = new \ReflectionProperty( Log_Manager::class, 'topic' );
		$firehose = $topic_prop->getValue( $lm );
		$this->assertInstanceOf( Topic_Node::class, $firehose );

		$parts_prop = new \ReflectionProperty( Topic_Node::class, 'partitions' );
		$partitions = $parts_prop->getValue( $firehose );
		$this->assertNotEmpty( $partitions, 'a partition materialized on the first message' );

		$mode = new \ReflectionProperty( Partition_Node::class, 'large_write_mode' );
		foreach ( $partitions as $partition ) {
			$this->assertSame(
				'',
				$mode->getValue( $partition ),
				'firehose partition must keep the PIPE_BUF cap (large writes disabled)'
			);
		}
	}

	public function test_firehose_topic_receives_renamed_retention_axes(): void {
		// The substrate renamed the retention axes: num_segments (count target),
		// lifetime (age rule), and a NEW trailing max_segments (hard cap). Seed
		// distinct values for all three — none equal to any default — and prove
		// Log_Manager reads the new keys and passes them into the firehose tail in
		// the correct positions. Against the old key reads (`max_segments` as the
		// count target, `max_lifetime` for age) this fails: `max_lifetime` no
		// longer resolves and the values land in the wrong slots.
		$this->require_config_or_skip();

		$config_path = self::test_dir() . '/retention-config.php';
		\file_put_contents(
			$config_path,
			'<?php return ' . \var_export(
				[
					'base_directory'   => self::test_dir(),
					'num_partitions'   => 1,
					'segment_size'     => 4096,
					'min_segments'     => 3,
					'num_segments'     => 6,
					'min_lifetime'     => 7,
					'lifetime'         => 999,
					'max_segments'     => 13,
					'enable_logging'   => true,
					'memcache_servers' => [],
					'allowed_users'    => [],
					'custom_colors'    => [],
					'log_memory'       => false,
					'flush_every_line' => true,
				],
				true
			) . ';'
		);

		Log_Manager::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $config_path );
		Config::reset();

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'work' ] ] ] );
		$lm = Log_Manager::instance();
		$lm->start( 'work' );
		$lm->message( 'work', [ 'm' => 'materialize' ] );

		$topic_prop = new \ReflectionProperty( Log_Manager::class, 'topic' );
		$firehose = $topic_prop->getValue( $lm );
		$this->assertInstanceOf( Topic_Node::class, $firehose );

		foreach ( [ 'num_segments' => 6, 'lifetime' => 999, 'max_segments' => 13, 'min_segments' => 3, 'min_lifetime' => 7 ] as $axis => $expected ) {
			$prop = new \ReflectionProperty( Topic_Node::class, $axis );
			$this->assertSame( $expected, $prop->getValue( $firehose ), "firehose Topic {$axis} must come from the renamed config key" );
		}
	}

	public function test_worst_case_envelope_fits_a_full_data_payload_under_pipe_buf(): void {
		// The MAX_DATA_SIZE (3840) ↔ PIPE_BUF (4096) gap must absorb the WHOLE
		// positional envelope at the extremes: an ENTRY fitted right up to
		// MAX_DATA_SIZE plus a maximum-length request-id KEY. The cap bounds the
		// entry, so `n`, `k` and `ts` are inside it and the gap is the envelope's
		// alone. If it doesn't fit, the Partition drops the record whole and this
		// test sees no full-size line.
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );

		// Max-length rid: init_firehose caps UNIQUE_ID at 64 chars.
		$_SERVER['UNIQUE_ID'] = \str_repeat( 'R', 70 );

		// Adversarial data over MAX_DATA_SIZE: multibyte (6-byte \uXXXX escapes) +
		// quote/backslash escapes, then ASCII pad — not plain padding, so escape
		// expansion is exercised on the way down to the cap.
		$m  = \str_repeat( '错', 300 ) . \str_repeat( '"', 100 ) . \str_repeat( '\\', 100 );
		$m .= \str_repeat( 'x', 3840 - \strlen( (string) \wp_json_encode( [ 'm' => $m ] ) ) );
		$this->assertSame(
			3840,
			\strlen( (string) \wp_json_encode( [ 'm' => $m ] ) ),
			'the data payload sits exactly at MAX_DATA_SIZE'
		);

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'work', 'e' ] ] ] );
		$lm = $this->fresh_log_manager();
		$lm->start( 'work' );
		$lm->message( 'e', [ 'm' => $m ] );
		$lm->finish();

		$max = 0;
		foreach ( \glob( self::test_dir() . '/logs/firehose.p*/*.log' ) ?: [] as $file ) {
			foreach ( \array_filter( \explode( "\n", (string) \file_get_contents( $file ) ) ) as $line ) {
				$max = \max( $max, \strlen( $line ) );
			}
		}
		// 3500 is far above any routine firehose line, so only the worst-case
		// record can reach it: a drop would leave $max at the ordinary lines.
		$this->assertGreaterThan( 3500, $max, 'the full worst-case record survived — not dropped as oversize' );
		$this->assertLessThanOrEqual( 4096, $max + 1, 'packed record + newline fits PIPE_BUF at the extremes' );
	}

	public function test_no_shipped_topology_lifts_the_firehose_pipe_buf_cap(): void {
		// A void_warranty / allow_large_writes grant on a firehose partition would
		// let a >PIPE_BUF append tear a peer's atomic write on the multi-writer
		// firehose. Pin that no shipped topology ever grants it.
		$dir   = \dirname( __DIR__, 2 ) . '/topologies';
		$files = \glob( $dir . '/*.tsl' ) ?: [];
		$this->assertNotEmpty( $files, 'the topologies dir resolved to .tsl files' );
		foreach ( $files as $file ) {
			foreach ( \file( $file ) ?: [] as $line ) {
				if ( \preg_match( '/void_warranty|allow_large_writes/', $line ) ) {
					$this->assertStringNotContainsString(
						'firehose',
						$line,
						\basename( $file ) . ': the multi-writer firehose must never lift the PIPE_BUF cap'
					);
				}
			}
		}
	}

	public function test_error_convenience_method(): void {
		$this->require_config_or_skip();
		$lm     = Log_Manager::instance();
		$result = $lm->error( 'Something went wrong' );
		$this->assertTrue( $result );
	}

	public function test_warning_convenience_method(): void {
		$this->require_config_or_skip();
		$lm     = Log_Manager::instance();
		$result = $lm->warning( 'Watch out' );
		$this->assertTrue( $result );
	}

	public function test_info_convenience_method(): void {
		$this->require_config_or_skip();
		$lm     = Log_Manager::instance();
		$result = $lm->info( 'FYI' );
		$this->assertTrue( $result );
	}

	public function test_alert_convenience_method(): void {
		$this->require_config_or_skip();
		$lm     = Log_Manager::instance();
		$result = $lm->alert( 'Fleet is degraded' );
		$this->assertTrue( $result );
	}

	public function test_alert_writes_a_k_alert_entry_to_the_firehose(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm = $this->fresh_log_manager();
		$lm->start( 'work' );
		$lm->alert( 'fleet sentinel 8842' );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'alert' );
		$this->assertNotNull( $entry, 'alert() must write a k=alert firehose entry' );
		$this->assertSame( 'fleet sentinel 8842', $entry['m'] );
	}

	// ── started_instance() (bridge seam) ─────────────────────────────────────

	public function test_started_instance_is_null_when_no_instance(): void {
		Log_Manager::reset();
		$this->assertNull( Log_Manager::started_instance() );
	}

	public function test_matched_request_is_started_at_construction(): void {
		// A request the ruleset classifies as logged must appear in the
		// firehose even if nothing ever calls message() — the old lazy start
		// left matched-but-quiet requests invisible.
		$this->require_config_or_skip();
		$lm = $this->fresh_log_manager();
		$this->assertTrue( $lm->is_started(), 'precondition: this request is logged' );
		$this->assertSame( $lm, Log_Manager::started_instance(), 'matched => started at construction' );
	}

	public function test_is_started_goes_false_after_finish(): void {
		// The window closes at finish(): callers that gate instrumentation on
		// is_started() must stop instrumenting once the request is closed out,
		// even though the governing rule still says `log`.
		$this->require_config_or_skip();
		$lm = $this->fresh_log_manager();
		$this->assertTrue( $lm->is_started(), 'precondition: this request is logged' );

		$lm->finish();

		$this->assertFalse( $lm->is_started(), 'finish() closes the logging window' );
		$this->assertTrue( $lm->governing_rule()->is_log(), 'the governing rule still says log' );
	}

	public function test_started_instance_returns_the_started_instance(): void {
		$this->require_config_or_skip();
		$lm = $this->fresh_log_manager();
		$lm->start( 'work' );
		$this->assertSame( $lm, Log_Manager::started_instance() );
	}

	public function test_started_instance_is_null_after_finish(): void {
		$this->require_config_or_skip();
		$lm = $this->fresh_log_manager();
		$lm->start( 'work' );
		$lm->finish();
		$this->assertNull( Log_Manager::started_instance() );
	}

	// ── start() / complete() ───────────────────────────────────────────────

	public function test_start_complete_timing(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();

		// Construction already attached the firehose Topic start() writes to.
		$lm->start( 'test_op', [ 'm' => 'starting operation' ] );
		\usleep( 10000 ); // 10ms
		$lm->complete( 'test_op' );

		// Verify the timer stack only has the root 'process' entry left.
		$ref = new \ReflectionProperty( Log_Manager::class, 'times' );
		$times = $ref->getValue( $lm );
		$this->assertCount( 1, $times, 'Timer stack should have only root entry after complete' );
	}

	public function test_complete_without_start_is_noop(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();
		// complete() without matching start() should not throw.
		$lm->complete( 'nonexistent_label' );
		$this->assertTrue( true );
	}

	public function test_nested_start_complete(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();

		$lm->start( 'outer' );
		$lm->start( 'inner' );
		$lm->complete( 'inner' );
		$lm->complete( 'outer' );

		$this->assertTrue( true );
	}

	public function test_finish_writes_the_terminal_when_a_stop_arrives(): void {
		// The cooperative stop lands on a WRITE, and finish() writes twice before
		// its terminal. Landing on the memory line meant complete() was never
		// reached — and `finished` latches on entry, so nothing retried it. The
		// record then stranded in flight until eviction, on any job whose lock
		// went away mid-request, not just a gyrobase render past its lease.
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm = $this->fresh_log_manager();
		$lm->start( 'work' );
		$lm->message( 'work', [ 'm' => 'before the stop' ] );

		// Arm the framework's own stop seam; last_pump = 0 forces the check.
		$framework = Event_Framework::instance();
		$predicate = new \ReflectionProperty( Event_Framework::class, 'continue_predicate' );
		$last_pump = new \ReflectionProperty( Event_Framework::class, 'last_pump' );
		$predicate->setValue( $framework, static fn (): bool => false );
		$last_pump->setValue( $framework, 0.0 );

		$raised = false;
		try {
			$lm->finish();
		} catch ( Worker_Should_Stop $e ) {
			$raised = true;
		} finally {
			$predicate->setValue( $framework, null );
		}

		// ADR-14: the stop is signalling, not an error. It still propagates —
		// after the terminal, the way Tap re-throws after its passthrough.
		$this->assertTrue( $raised, 'the cooperative stop still reaches the worker' );
		$this->assertNotNull(
			self::last_entry_of( $this->written_entries(), 'process (aborted)' ),
			'the record still gets its end, and says it was cut short'
		);
		$this->assertNull(
			self::last_entry_of( $this->written_entries(), 'process (complete)' ),
			'a request the stop cut short never reads as a clean finish'
		);
	}

	/**
	 * Swap the manager's firehose Topic for one that refuses the entries
	 * `$refuse` names, throwing `$failure`, and passes every other through.
	 *
	 * @param \Closure(array<string,mixed>): bool $refuse  Which entries to refuse.
	 * @param \Throwable                          $failure What a refusal throws.
	 */
	private static function refuse_entries( Log_Manager $lm, \Closure $refuse, \Throwable $failure ): void {
		$topic = new \ReflectionProperty( Log_Manager::class, 'topic' );
		$topic->setValue(
			$lm,
			new class( $topic->getValue( $lm ), $refuse, $failure ) extends \Newspack_Nodes\Node {
				public function __construct( private object $real, private \Closure $refuse, private \Throwable $failure ) {
					parent::__construct();
				}
				public function fill( array $message ): void {
					if ( ( $this->refuse )( $message[ Message::VALUE ] ) ) {
						throw $this->failure;
					}
					$this->real->fill( $message );
				}
				public function flush(): void {
					$this->real->flush();
				}
			}
		);
	}

	public function test_finish_writes_the_terminal_past_a_failed_drain_write_then_raises_it(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm      = $this->fresh_log_manager();
		$refusal = new \RuntimeException( 'firehose append refused 6612' );
		self::refuse_entries( $lm, static fn ( array $entry ): bool => 'memory' === $entry['k'], $refusal );

		$thrown = null;
		try {
			$lm->finish();
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}

		$this->assertSame( $refusal, $thrown, 'the failed write escapes' );
		$this->assertNotNull(
			self::last_entry_of( $this->written_entries(), 'process (complete)' ),
			'the terminal still lands, and a write failure is no abort'
		);
		$this->assertFalse( $lm->is_started() );
	}

	public function test_finish_keeps_an_abort_its_caller_declared_past_a_failed_drain_write(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm      = $this->fresh_log_manager();
		$refusal = new \RuntimeException( 'firehose append refused 6614' );
		self::refuse_entries( $lm, static fn ( array $entry ): bool => 'memory' === $entry['k'], $refusal );
		( new \ReflectionProperty( Log_Manager::class, 'aborted' ) )->setValue( $lm, true );

		$thrown = null;
		try {
			$lm->finish();
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}

		$this->assertSame( $refusal, $thrown );
		$this->assertNotNull( self::last_entry_of( $this->written_entries(), Log_Manager::REQUEST_ABORTED ), 'a drain failure does not make an aborted job complete' );
	}

	public function test_finish_raises_a_plain_stop_carrying_the_terminal_write_failure(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm      = $this->fresh_log_manager();
		$refusal = new \RuntimeException( 'terminal append refused 6613' );
		self::refuse_entries( $lm, static fn ( array $entry ): bool => \str_starts_with( $entry['k'], Log_Manager::REQUEST_LABEL . ' (' ), $refusal );
		$disarm = self::arm_stop_on_next_write();

		$thrown = null;
		try {
			$lm->finish();
		} catch ( \Throwable $e ) {
			$thrown = $e;
		} finally {
			$disarm();
		}

		$this->assertInstanceOf( Worker_Should_Stop::class, $thrown, 'the drain stop leaves' );
		$this->assertFalse( Worker_Should_Stop::is_clean( $thrown ) );
		$this->assertSame( $refusal, $thrown->getPrevious(), 'carrying the terminal it could not write' );
	}

	public function test_a_start_line_whose_stop_carries_a_failure_opens_no_span(): void {
		// A stop carrying a failure says the line never became durable, so no
		// span opened on the record and the drain has nothing to close.
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm = $this->fresh_log_manager();
		self::refuse_entries(
			$lm,
			static fn ( array $entry ): bool => 'takahe 6614 (start)' === $entry['k'],
			new Worker_Should_Stop( 'deadline', 0, new \RuntimeException( 'flush failed 6614' ) )
		);

		try {
			$lm->start( 'takahe 6614' );
		} catch ( Worker_Should_Stop ) {
			$this->addToAssertionCount( 1 );
		}
		$lm->finish();

		$this->assertSame( [], self::entries_of( self::firehose_entries( self::test_dir() ), 'takahe 6614 (complete)' ) );
	}

	public function test_finish_writes_the_terminal_when_the_stop_lands_on_it(): void {
		// Guarding only the trimmings moves the window rather than closing it:
		// complete() writes too, through the same Topic -> Partition -> pump.
		// The predicate re-arms itself so the check falls on a LATER write than
		// the first, which is the case a single guard around the drain misses.
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm = $this->fresh_log_manager();
		$lm->start( 'work' );
		$lm->message( 'work', [ 'm' => 'before the stop' ] );

		$framework = Event_Framework::instance();
		$predicate = new \ReflectionProperty( Event_Framework::class, 'continue_predicate' );
		$last_pump = new \ReflectionProperty( Event_Framework::class, 'last_pump' );
		$calls     = 0;
		$predicate->setValue(
			$framework,
			static function () use ( &$calls, $framework, $last_pump ): bool {
				++$calls;
				// Un-throttle so the NEXT write checks too.
				$last_pump->setValue( $framework, 0.0 );
				return $calls < 3;
			}
		);
		$last_pump->setValue( $framework, 0.0 );

		$raised = false;
		try {
			$lm->finish();
		} catch ( Worker_Should_Stop $e ) {
			$raised = true;
		} finally {
			$predicate->setValue( $framework, null );
		}

		$this->assertTrue( $raised, 'the cooperative stop still reaches the worker' );
		$this->assertNotNull(
			self::last_entry_of( $this->written_entries(), 'process (aborted)' ),
			'the terminal survives a stop that lands on the terminal write'
		);
		$this->assertFalse( $lm->is_started(), 'and the request state is still reset' );

		// The line number is stamped BEFORE the write and advanced after, so a
		// stop raised inside fill() burns neither: the entry is durable
		// (maybe_stop flushes before re-raising) but the counter never moved,
		// and the next entry reused the number. Request_Builder_Node drops a
		// duplicate outright — terminal included — so the record stranded
		// until its trace timed out, having written the terminal.
		$numbers = \array_column( $this->written_entries(), 'n' );
		$this->assertSame(
			\count( $numbers ),
			\count( \array_unique( $numbers ) ),
			'no two entries share a sequence number'
		);
	}

	/** The reader orders partitions and segments by number, not by string. */
	public function test_firehose_entries_read_in_numeric_partition_and_segment_order(): void {
		$base = self::test_dir() . '/order-7742';
		foreach ( [ [ 10, 1, 'p10-s1' ], [ 2, 1000, 'p2-s1000' ], [ 2, 999, 'p2-s999' ] ] as [ $partition, $segment, $mark ] ) {
			@\mkdir( "{$base}/logs/firehose.p{$partition}", 0755, true );
			$message                  = Message::new_message();
			$message[ Message::VALUE ] = [ 'k' => $mark ];
			\file_put_contents( "{$base}/logs/firehose.p{$partition}/{$segment}.log", Message::packed( $message ) . "\n" );
		}

		$this->assertSame( [ 'p2-s999', 'p2-s1000', 'p10-s1' ], \array_column( self::firehose_entries( $base ), 'k' ) );
	}

	/** A line whose VALUE is no entry is a producer regression, and the reader says so. */
	public function test_firehose_entries_fail_on_a_line_that_carries_no_entry(): void {
		$base = self::test_dir() . '/bytestream-7748';
		@\mkdir( "{$base}/logs/firehose.p0", 0755, true );
		$message                   = Message::new_message();
		$message[ Message::VALUE ] = 'kea-7748 raw bytes';
		\file_put_contents( "{$base}/logs/firehose.p0/1.log", Message::packed( $message ) . "\n" );

		try {
			self::firehose_entries( $base );
		} catch ( \PHPUnit\Framework\AssertionFailedError $e ) {
			$this->assertStringContainsString( 'firehose.p0/1.log', $e->getMessage() );
			return;
		}
		$this->fail( 'a line carrying no entry must fail the read' );
	}

	// ── timed(): one span around a closure ────────────────────────────────

	/** Describe a result the way a caller does, so the test reads what `m` got. */
	private static function describe_result( mixed $result ): string {
		return 'returned ' . \json_encode( $result );
	}

	public function test_timed_passes_the_result_through_one_span(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm = $this->fresh_log_manager();

		$result = $lm->timed( 'kea 4417', static fn (): array => [ 'rows' => 4417 ], self::describe_result( ... ), [ 'm' => 'kea-ci' ] );
		$lm->finish();

		$this->assertSame( [ 'rows' => 4417 ], $result );
		$entries = self::firehose_entries( self::test_dir() );
		$this->assertSame( 'kea-ci', self::last_entry_of( $entries, 'kea 4417 (start)' )['m'] ?? null );
		$this->assertSame( 'returned {"rows":4417}', self::last_entry_of( $entries, 'kea 4417 (complete)' )['m'] ?? null );
	}

	/** A span the producer keeps through a fold keeps both halves, or it severs. */
	public function test_timed_keeps_the_close_of_a_kept_start(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm = $this->fresh_log_manager();

		$lm->timed( 'kea 4426', static fn (): int => 4426, self::describe_result( ... ), [ 'keep' => 1 ] );
		$lm->timed( 'weka 4426', static fn (): int => 4426, self::describe_result( ... ) );
		$lm->finish();

		$entries = self::firehose_entries( self::test_dir() );
		$this->assertSame( 1, self::last_entry_of( $entries, 'kea 4426 (start)' )['keep'] ?? null );
		$this->assertSame( 1, self::last_entry_of( $entries, 'kea 4426 (complete)' )['keep'] ?? null );
		$this->assertArrayNotHasKey( 'keep', self::last_entry_of( $entries, 'weka 4426 (complete)' ) ?? [] );
	}

	/** A kept frame keeps its close however it closes: by label or drained. */
	public function test_a_kept_start_keeps_every_close_of_its_frame(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm = $this->fresh_log_manager();

		$lm->start( 'kea 4432', [ 'keep' => 3 ] );
		$lm->complete( 'kea 4432' );
		$lm->start( 'ruru 4432', [ 'keep' => 5 ] );
		$lm->start( 'weka 4432' );
		$lm->finish();

		$entries = self::firehose_entries( self::test_dir() );
		$this->assertSame( 3, self::last_entry_of( $entries, 'kea 4432 (complete)' )['keep'] ?? null );
		$orphan = self::last_entry_of( $entries, 'ruru 4432 (complete)' ) ?? [];
		$this->assertSame( '(orphaned)', $orphan['m'] ?? null );
		$this->assertSame( 5, $orphan['keep'] ?? null );
		$this->assertArrayNotHasKey( 'keep', self::last_entry_of( $entries, 'weka 4432 (complete)' ) ?? [] );
	}

	/**
	 * What a closure can throw, and the `(complete)` `m` it closes with.
	 *
	 * @return array<string,array{0:\Throwable,1:string}>
	 */
	public static function thrown_outcomes(): array {
		return [
			'a class'                => [ new \DomainException( 'weka 4418' ), 'DomainException: weka 4418' ],
			'a class with no message' => [ new \DomainException(), 'DomainException' ],
			'an anonymous one'       => [ new class( 'weka 4424' ) extends \DomainException {}, 'class@anonymous: weka 4424' ],
			'a cooperative stop'     => [ new Worker_Should_Stop( 'kakapo 4418' ), 'stop' ],
		];
	}

	/** One vocabulary for every span: timed() names what was thrown, never the caller. */
	#[DataProvider( 'thrown_outcomes' )]
	public function test_timed_names_what_the_closure_threw_and_propagates_it( \Throwable $thrown, string $outcome ): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm        = $this->fresh_log_manager();
		$described = false;
		$caught    = null;

		try {
			$lm->timed(
				'kea 4418',
				static fn (): never => throw $thrown,
				static function () use ( &$described ): string {
					$described = true;
					return 'returned';
				}
			);
		} catch ( \PHPUnit\Exception | \SebastianBergmann\Invoker\Exception $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			$caught = $e;
		}
		$this->assertSame( $thrown, $caught, 'the closure\'s throwable must propagate' );
		$lm->finish();

		$this->assertFalse( $described, 'the description covers a result, and there was none' );
		$this->assertSame( $outcome, self::last_entry_of( self::firehose_entries( self::test_dir() ), 'kea 4418 (complete)' )['m'] ?? null );
	}

	/** A thrown message too long for one firehose line clips; its class leads. */
	public function test_timed_clips_a_long_thrown_message_to_fit_the_line(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm = $this->fresh_log_manager();

		try {
			$lm->timed(
				'kea 4419',
				static fn (): never => throw new \DomainException( \str_repeat( 'takahe 4419 ', 900 ) ),
				static fn (): string => 'returned'
			);
		} catch ( \DomainException ) {
			$lm->finish();
		}

		$complete = self::last_entry_of( self::firehose_entries( self::test_dir() ), 'kea 4419 (complete)' ) ?? [];
		$this->assertStringStartsWith( 'DomainException: takahe 4419 takahe', (string) ( $complete['m'] ?? '' ) );
		$this->assertTrue( $complete['truncated'] ?? false );
		$this->assertArrayHasKey( 'duration_ms', $complete );
		$this->assertLessThanOrEqual( 3840, \strlen( (string) \wp_json_encode( $complete ) ) );
	}

	/** With no description the span carries no outcome at all, thrown or not. */
	public function test_timed_without_a_description_writes_no_outcome(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm = $this->fresh_log_manager();

		$lm->timed( 'kea 4425', static fn (): string => 'moa-4425' );
		try {
			$lm->timed( 'weka 4425', static fn (): never => throw new \DomainException( 'weka 4425' ) );
		} catch ( \DomainException ) {
			$lm->finish();
		}

		$entries = self::firehose_entries( self::test_dir() );
		foreach ( [ 'kea 4425 (complete)', 'weka 4425 (complete)' ] as $category ) {
			$complete = self::last_entry_of( $entries, $category );
			$this->assertNotNull( $complete, $category );
			$this->assertArrayNotHasKey( 'm', $complete, $category );
			$this->assertArrayHasKey( 'duration_ms', $complete, $category );
		}
	}

	public function test_timed_start_that_throws_propagates_and_skips_the_closure(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm     = $this->fresh_log_manager();
		$ran    = false;
		$disarm = self::arm_stop_on_next_write();

		try {
			$lm->timed(
				'kea 4419',
				static function () use ( &$ran ): string {
					$ran = true;
					return 'ran';
				},
				self::describe_result( ... )
			);
			$this->fail( 'the start write\'s throwable must propagate' );
		} catch ( Worker_Should_Stop ) {
			$this->assertFalse( $ran, 'the closure never ran' );
		} finally {
			$disarm();
		}
	}

	/**
	 * The Partition flushes the start line to disk before it raises the stop,
	 * so the span is open on the record: the frame must be pushed, or nothing
	 * ever closes it and every later entry nests under it.
	 */
	public function test_a_stop_raised_by_the_start_write_leaves_the_span_for_the_drain_to_close(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm     = $this->fresh_log_manager();
		$disarm = self::arm_stop_on_next_write();

		try {
			$lm->start( 'kea 4423' );
			$this->fail( 'the stop must propagate' );
		} catch ( Worker_Should_Stop ) {
			$disarm();
		}
		$lm->finish();

		$entries = self::firehose_entries( self::test_dir() );
		$this->assertCount( 1, self::entries_of( $entries, 'kea 4423 (start)' ), 'the start line landed before the stop' );
		$this->assertSame(
			[ '(orphaned)' ],
			\array_column( self::entries_of( $entries, 'kea 4423 (complete)' ), 'm' ),
			'the drain closes the span the stop left open'
		);
	}

	public function test_timed_complete_that_throws_propagates_after_a_normal_return(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm     = $this->fresh_log_manager();
		$disarm = null;

		try {
			$lm->timed(
				'kea 4420',
				static function () use ( &$disarm ): string {
					$disarm = self::arm_stop_on_next_write();
					return 'kakapo-4420';
				},
				self::describe_result( ... )
			);
			$this->fail( 'the complete write\'s throwable must propagate' );
		} catch ( Worker_Should_Stop $e ) {
			$this->assertNull( $e->getPrevious(), 'nothing else was in flight' );
		} finally {
			$disarm && $disarm();
		}
	}

	/**
	 * Both of a span's writes run through `$write`, so a node whose `guarded()`
	 * holds the stop the start write raises still runs the step and closes it.
	 */
	public function test_timed_runs_both_writes_through_the_write_wrapper(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm      = $this->fresh_log_manager();
		$held    = [];
		$wrapped = [];
		$write   = static function ( \Closure $one ) use ( &$held, &$wrapped ): void {
			$wrapped[] = 'write';
			try {
				$one();
			} catch ( Worker_Should_Stop $stop ) {
				$held[] = $stop;
			}
		};
		$disarm = self::arm_stop_on_next_write();

		try {
			$result = $lm->timed( 'kea 4431', static fn (): int => 4431, self::describe_result( ... ), [ 'keep' => 1 ], [], $write );
		} finally {
			$disarm();
		}
		$lm->finish();

		$this->assertSame( 4431, $result, 'the step ran past the held stop' );
		$this->assertSame( [ 'write', 'write' ], $wrapped, 'the start and the close' );
		$this->assertCount( 1, $held, 'the start write raised it' );
		$this->assertSame( 'returned 4431', self::last_entry_of( self::firehose_entries( self::test_dir() ), 'kea 4431 (complete)' )['m'] ?? null );
	}

	public function test_timed_complete_that_throws_keeps_the_closures_throwable_as_previous(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$lm     = $this->fresh_log_manager();
		$thrown = new \DomainException( 'weka 4421' );
		$disarm = null;

		try {
			$lm->timed(
				'kea 4421',
				static function () use ( &$disarm, $thrown ): never {
					$disarm = self::arm_stop_on_next_write();
					throw $thrown;
				},
				self::describe_result( ... )
			);
			$this->fail( 'the complete write\'s throwable must propagate' );
		} catch ( Worker_Should_Stop $e ) {
			$this->assertSame( $thrown, $e->getPrevious(), 'PHP chains the closure\'s throwable under the one finally raised' );
		} finally {
			$disarm && $disarm();
		}
	}

	public function test_get_request_id_returns_string(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$rid = $lm->get_request_id();
		$this->assertIsString( $rid );
		$this->assertNotEmpty( $rid );
	}

	/** A worker process names itself and its partition as two entries of their own, not as environment. */
	public function test_a_worker_process_logs_its_type_and_partition_as_entries(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$this->set_rules_option( [ [ 'id' => 'all', 'pattern' => '/', 'action' => 'log', 'hooks' => [ 'wp' ] ] ] );
		$_SERVER['REQUEST_URI']                     = '/wp-cron.php';
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE']      = 'reconcile-731';
		$_SERVER['NEWSPACK_NODES_WORKER_PARTITION'] = '2';
		$lm = $this->fresh_log_manager();
		$lm->start( 'noop' );
		$lm->finish();
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'], $_SERVER['NEWSPACK_NODES_WORKER_PARTITION'] );

		$this->assertSame( 'reconcile-731', self::last_entry_of( $this->written_entries(), 'worker_type' )['m'] ?? null, 'the env var outranks the path' );
		$this->assertSame( 2, self::last_entry_of( $this->written_entries(), 'worker_partition' )['m'] ?? null );
		$this->assertArrayNotHasKey( 'worker_type', self::last_entry_of( $this->written_entries(), 'process (start)' ) );
	}

	/**
	 * A spawn request names itself `restapi` at its start, before REST
	 * dispatch reaches the controller. The substrate's `worker_identified`
	 * then names the worker it became, through the listener the deferred
	 * bootstrap registers, and the later pair of entries wins.
	 */
	public function test_the_substrates_identity_announcement_renames_a_spawned_worker(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$this->set_rules_option( [ [ 'id' => 'all', 'pattern' => '/', 'action' => 'log', 'hooks' => [ 'wp' ] ] ] );
		$_SERVER['REQUEST_URI'] = '/wp-json/newspack-nodes/v1/workers/spawn';
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'], $_SERVER['NEWSPACK_NODES_WORKER_PARTITION'] );
		$lm = $this->fresh_log_manager();
		$lm->start( 'noop' );
		self::with_deferred_bootstrap(
			static fn () => \do_action( 'newspack_nodes/worker_identified', 'kea-7713', 3 )
		);
		$lm->finish();

		$entries = $this->written_entries();
		$types   = \array_column(
			\array_filter( $entries, static fn ( array $e ): bool => 'worker_type' === ( $e['k'] ?? null ) ),
			'm'
		);
		$this->assertSame( [ 'restapi', 'kea-7713' ], $types );
		$this->assertSame( 3, self::last_entry_of( $entries, 'worker_partition' )['m'] ?? null );
	}

	/**
	 * A job is a run inside a worker, not the worker: its record names the
	 * worker type, which gives it the `/jobs/{handler}/{id}?<type>` row, and no
	 * partition, which only the worker's own record carries. The process keeps
	 * its partition throughout, since a job handler reads it.
	 */
	public function test_a_job_inside_a_worker_partition_names_the_type_and_no_partition(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$this->set_rules_option( [ [ 'id' => 'all', 'pattern' => '/', 'action' => 'log', 'hooks' => [ 'wp' ] ] ] );
		$_SERVER['REQUEST_URI']                     = '/wp-json/newspack-nodes/v1/workers/spawn';
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE']      = 'kea-7713';
		$_SERVER['NEWSPACK_NODES_WORKER_PARTITION'] = '3';
		$lm = $this->fresh_log_manager();
		$lm->start( 'noop' );
		Log_Manager::begin_job_context( 'kea-probe', 'job-4431' );
		$partition_in_job = $_SERVER['NEWSPACK_NODES_WORKER_PARTITION'] ?? null;
		Log_Manager::instance()->start( 'noop' );
		Log_Manager::end_job_context( 'kea-probe', 'job-4431', [ 'status' => 'ok' ] );
		$lm->finish();
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'], $_SERVER['NEWSPACK_NODES_WORKER_PARTITION'] );

		$entries = $this->written_entries();
		$job_rid = null;
		foreach ( $entries as $entry ) {
			if ( 'request' === ( $entry['k'] ?? null ) && \str_contains( (string) ( $entry['m'] ?? '' ), '/jobs/kea-probe/job-4431' ) ) {
				$job_rid = $entry['rid'];
			}
		}
		$this->assertNotNull( $job_rid, 'the job wrote a record of its own' );
		$job    = \array_values( \array_filter( $entries, static fn ( array $e ): bool => $job_rid === $e['rid'] ) );
		$worker = \array_values( \array_filter( $entries, static fn ( array $e ): bool => $job_rid !== $e['rid'] ) );

		$this->assertSame( '3', $partition_in_job, 'the process keeps its partition inside the job' );
		$this->assertSame( 'kea-7713', self::last_entry_of( $job, 'worker_type' )['m'] ?? null );
		$this->assertNull( self::last_entry_of( $job, 'worker_partition' ), 'a job carries no partition' );
		$this->assertSame( 3, self::last_entry_of( $worker, 'worker_partition' )['m'] ?? null, 'the worker record keeps it' );
	}

	/** With no logging request open, the announcement builds no logger. */
	public function test_an_identity_announcement_outside_a_logged_request_builds_no_logger(): void {
		Log_Manager::identify_worker( 'kea-7713', 3 );

		$this->assertFalse( Log_Manager::has_instance() );
	}

	// ── Governing rule resolution ────────────────────────────────────────────

	public function test_governing_rule_is_the_matched_log_rule_and_enables_logging(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [
			[ 'id' => 'root', 'pattern' => '/', 'action' => 'log' ],
			[ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'wp' ] ],
		] );
		$_SERVER['REQUEST_URI'] = '/shop/cart';
		$lm = $this->fresh_log_manager();
		$this->assertTrue( $lm->is_started() );
		$this->assertSame( 'shop', $lm->governing_rule()->id );
		$this->assertSame( 'shop', $lm->governing_rule_id() );
	}

	public function test_skip_rule_disables_logging_and_yields_a_skip_governing_rule(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [
			[ 'id' => 'root', 'pattern' => '/', 'action' => 'log' ],
			[ 'id' => 'cron', 'pattern' => '/wp-cron', 'action' => 'skip' ],
		] );
		$_SERVER['REQUEST_URI'] = '/wp-cron.php';
		$lm = $this->fresh_log_manager();
		$this->assertFalse( $lm->is_started() );
		$this->assertTrue( $lm->governing_rule()->is_skip() );
	}

	public function test_no_matching_rule_disables_logging_with_null_governing_rule(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log' ] ] );
		$_SERVER['REQUEST_URI'] = '/about';
		$lm = $this->fresh_log_manager();
		$this->assertFalse( $lm->is_started() );
		$this->assertNull( $lm->governing_rule() );
		$this->assertSame( '', $lm->governing_rule_id() );
	}

	/** @return array<string,array{string,string}> Request URI, the worker type its process (start) carries. */
	public static function platform_endpoints(): array {
		return [
			'cron'     => [ '/wp-cron.php?doing_wp_cron=1790000000.5', 'cron' ],
			'command'  => [ '/wp-json/newspack-nodes/v1/command', 'restapi' ],
			'messages' => [ '/wp-json/newspack-nodes/v1/messages/stream', 'restapi' ],
			'spawn'    => [ '/wp-json/newspack-nodes/v1/workers/spawn', 'restapi' ],
			'auth'     => [ '/wp-json/newspack-nodes/v1/auth', 'restapi' ],
			'mcp'      => [ '/wp-json/newspack-event-logger-nodes/v1/mcp', 'restapi' ],
			'health'   => [ '/wp-json/newspack-nodes/v1/health/runtime', 'restapi' ],
		];
	}

	/**
	 * `PLATFORM_WORKERS` spells its REST paths by hand, because two of the
	 * substrate's controllers name their routes as literals. Registering every
	 * route the platform serves is the drift guard: a route added, renamed or
	 * removed on either side fails here.
	 */
	public function test_platform_rest_paths_are_the_routes_the_platform_registers(): void {
		$saved                   = $GLOBALS['_rest_routes'] ?? [];
		$GLOBALS['_rest_routes'] = [];
		try {
			\Newspack_Nodes\Bootstrap::register_rest_routes();
			( new \Newspack_Event_Logger_Nodes\App\MCP_Controller() )->register_routes();
			$registered = \array_map( static fn ( string $route ): string => "/wp-json/{$route}", \array_keys( $GLOBALS['_rest_routes'] ) );
		} finally {
			$GLOBALS['_rest_routes'] = $saved;
		}
		$workers = ( new \ReflectionClassConstant( Log_Manager::class, 'PLATFORM_WORKERS' ) )->getValue();
		$rest    = \array_keys( \array_filter( $workers, static fn ( string $type ): bool => 'restapi' === $type ) );

		\sort( $registered );
		\sort( $rest );
		$this->assertSame( $registered, $rest );
	}

	/**
	 * The platform's own requests to itself — the cron loopback and the
	 * substrate's endpoints — are worker traffic whether or not the substrate
	 * set its env var, so a rule that logs them keeps them off the global rows.
	 */
	#[DataProvider( 'platform_endpoints' )]
	public function test_the_platforms_own_endpoints_log_as_worker_traffic( string $uri, string $worker_type ): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$this->set_rules_option( [ [ 'id' => 'all', 'pattern' => '/', 'action' => 'log', 'hooks' => [ 'wp' ] ] ] );
		$_SERVER['REQUEST_URI'] = $uri;
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
		$lm = $this->fresh_log_manager();
		$lm->start( 'noop' );
		$lm->finish();

		$this->assertSame( $worker_type, self::last_entry_of( $this->written_entries(), 'worker_type' )['m'] ?? null );
		$this->assertNull( self::last_entry_of( $this->written_entries(), 'worker_partition' ) );
	}

	public function test_an_ordinary_page_carries_no_worker_type(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$this->set_rules_option( [ [ 'id' => 'all', 'pattern' => '/', 'action' => 'log', 'hooks' => [ 'wp' ] ] ] );
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/pages/867431';
		unset( $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );
		$lm = $this->fresh_log_manager();
		$lm->start( 'noop' );
		$lm->finish();

		$this->assertNull( self::last_entry_of( $this->written_entries(), 'worker_type' ) );
	}

	/**
	 * The process (start) firehose frame must carry the governing rule id so
	 * downstream consumers (Flame_Builder) can apply that rule's thresholds by id.
	 */
	public function test_process_start_frame_carries_the_governing_rule_id(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'wp' ] ] ] );
		$_SERVER['REQUEST_URI'] = '/shop/cart';
		$lm = $this->fresh_log_manager();
		$lm->start( 'noop' );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'process (start)' );
		$this->assertNotNull( $entry, 'Should have a process (start) entry' );
		$this->assertSame( 'shop', $entry['rule'] );
	}

	/**
	 * A trace is read months later against a core version nobody recorded.
	 * `process (start)` already names the pid and the host; the WordPress
	 * version belongs beside them, not in the environment allowlist.
	 */
	public function test_process_start_frame_names_the_wordpress_version(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$this->set_rules_option( [ [ 'id' => 'shop', 'pattern' => '/shop/', 'action' => 'log', 'hooks' => [ 'wp' ] ] ] );
		$_SERVER['REQUEST_URI'] = '/shop/cart';
		$lm = $this->fresh_log_manager();
		$lm->start( 'noop' );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'process (start)' );
		$this->assertNotNull( $entry, 'Should have a process (start) entry' );
		$this->assertMatchesRegularExpression(
			'/^\d+ on \S+, WordPress 9\.9\.9$/',
			(string) $entry['m'],
			'process (start) should read "<pid> on <host>, WordPress <version>"'
		);
	}

	// ── URL filter ─────────────────────────────────────────────────────────

	public function test_matches_url_filter_with_skip_urls(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [
			[ 'id' => 'root', 'pattern' => '/', 'action' => 'log' ],
			[ 'id' => 'health', 'pattern' => '/health', 'action' => 'skip' ],
			[ 'id' => 'cron', 'pattern' => '/wp-cron', 'action' => 'skip' ],
		] );

		$_SERVER['REQUEST_URI'] = '/health';
		$lm = $this->fresh_log_manager();
		$this->assertFalse( $lm->is_started(), 'Skip rule should disable logging' );

		// Skip rule patterns are prefixes (no trailing '?'), so a sub-path is skipped too.
		$_SERVER['REQUEST_URI'] = '/health/check';
		$this->assertFalse( $this->fresh_log_manager()->is_started(), 'a sub-path of a skip prefix is skipped' );
	}

	public function test_matches_url_filter_with_log_urls(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'api', 'pattern' => '/api/', 'action' => 'log' ] ] );

		$_SERVER['REQUEST_URI'] = '/other/page';
		$lm = $this->fresh_log_manager();
		$this->assertFalse( $lm->is_started(), 'Non-matching URL should be disabled when a log rule is set' );
	}

	public function test_matches_url_filter_accepts_matching_url(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'api', 'pattern' => '/api/', 'action' => 'log' ] ] );

		// A prefix rule (no trailing '?'); a matching path enables logging.
		$_SERVER['REQUEST_URI'] = '/api/';
		$lm = $this->fresh_log_manager();
		$this->assertTrue( $lm->is_started(), 'Matching URL should be enabled' );
	}

	// ── No emission gate: rule selections drive instrumentation at the ──
	// ── producers; any category an active producer emits is written.   ──

	public function test_producer_categories_log_without_rule_selection(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'jobs', 'pattern' => '/jobs/', 'action' => 'log' ] ] );
		$_SERVER['REQUEST_URI'] = '/jobs/image-to-wordpress?job-worker';
		$lm                     = $this->fresh_log_manager();
		$this->assertTrue( $lm->is_started() );
		$this->assertTrue( $lm->message( 'pyrobase (start)', [ 'm' => '/x.html' ] ) );
		$this->assertTrue( $lm->message( 'metadatacache', [ 'm' => '731 l1, 25 apcu' ] ) );
		$this->assertTrue( $lm->message( 'query hook', [ 'm' => 'q-9021' ] ) );
		$this->assertTrue( $lm->message( 'error', [ 'm' => 'boom-9021' ] ) );
		// Landed in the firehose, not just a truthy return.
		$lm->finish();
		$this->assertNotNull( self::last_entry_of( $this->written_entries(), 'metadatacache' ) );
		$this->assertNotNull( self::last_entry_of( $this->written_entries(), 'query hook' ) );
	}

	public function test_matches_url_filter_directly(): void {
		$this->require_config_or_skip();
		// A '/' log rule matches every URL (there is no implicit log-all default).
		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log' ] ] );
		$lm = Log_Manager::instance();
		$this->assertTrue( $lm->matches_url_filter( '/anything' ), 'a / log rule matches any URL' );
	}

	// ── URL filter: prefix match with a '?' terminator ─────────────────────
	// Rule patterns prefix-match the request path (query string removed) with
	// a '?' appended, so a pattern ending in '?' is an EXACT match and one
	// without is a PREFIX: '/?' = home page only, '/news?' = exactly /news,
	// '/news' = anything under /news. (see Rule_Matcher::match)

	public function test_matches_url_filter_log_urls_prefix(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'api', 'pattern' => '/api/', 'action' => 'log' ] ] );

		// A rule pattern (no trailing '?') matches anything starting with it.
		$_SERVER['REQUEST_URI'] = '/api/data';
		$this->assertTrue( $this->fresh_log_manager()->is_started(), 'a path under the prefix matches' );

		// ...but only at the START, not a pattern appearing mid-URL.
		$_SERVER['REQUEST_URI'] = '/v2/api/';
		$this->assertFalse( $this->fresh_log_manager()->is_started(), 'the prefix must match at the start, not mid-URL' );
	}

	public function test_matches_url_filter_log_urls_trailing_question_mark_is_exact(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'news', 'pattern' => '/news?', 'action' => 'log' ] ] );

		// The trailing '?' makes the pattern match ONLY '/news'.
		$_SERVER['REQUEST_URI'] = '/news';
		$this->assertTrue( $this->fresh_log_manager()->is_started(), "'/news?' matches '/news' exactly" );

		$_SERVER['REQUEST_URI'] = '/news/123';
		$this->assertFalse( $this->fresh_log_manager()->is_started(), "'/news?' must not match a sub-path" );

		$_SERVER['REQUEST_URI'] = '/newsletter';
		$this->assertFalse( $this->fresh_log_manager()->is_started(), "'/news?' must not match a longer sibling" );
	}

	public function test_matches_url_filter_log_urls_home_page(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'home', 'pattern' => '/?', 'action' => 'log' ] ] );

		// '/?' logs ONLY the home page.
		$_SERVER['REQUEST_URI'] = '/';
		$this->assertTrue( $this->fresh_log_manager()->is_started(), "'/?' matches the home page" );

		$_SERVER['REQUEST_URI'] = '/about';
		$this->assertFalse( $this->fresh_log_manager()->is_started(), "'/?' must not match any other page" );
	}

	public function test_matches_url_filter_log_urls_strips_query_string(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'news', 'pattern' => '/news?', 'action' => 'log' ] ] );

		// The query string is removed before matching, so '/news?…' still matches '/news?'.
		$_SERVER['REQUEST_URI'] = '/news?ref=newsletter';
		$this->assertTrue( $this->fresh_log_manager()->is_started(), 'the query string is stripped before matching' );
	}

	public function test_matches_url_filter_log_urls_multi_pattern_grouped(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [
			[ 'id' => 'foo', 'pattern' => '/foo/', 'action' => 'log' ],
			[ 'id' => 'bar', 'pattern' => '/bar/', 'action' => 'log' ],
		] );

		$_SERVER['REQUEST_URI'] = '/foo/x';
		$this->assertTrue( $this->fresh_log_manager()->is_started(), 'the first rule matches' );

		$_SERVER['REQUEST_URI'] = '/bar/x';
		$this->assertTrue( $this->fresh_log_manager()->is_started(), 'the second rule matches' );

		$_SERVER['REQUEST_URI'] = '/other';
		$this->assertFalse( $this->fresh_log_manager()->is_started(), 'a non-matching URL is disabled' );
	}

	public function test_matches_url_filter_skip_urls_use_the_same_scheme(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [
			[ 'id' => 'root', 'pattern' => '/', 'action' => 'log' ],
			[ 'id' => 'health', 'pattern' => '/health?', 'action' => 'skip' ],
		] );

		// The trailing '?' skip rule matches ONLY '/health' exactly.
		$_SERVER['REQUEST_URI'] = '/health';
		$this->assertFalse( $this->fresh_log_manager()->is_started(), "skip '/health?' skips '/health' exactly" );

		// A sub-path is NOT matched by the exact skip rule, so the '/' log rule governs.
		$_SERVER['REQUEST_URI'] = '/health/check';
		$this->assertTrue( $this->fresh_log_manager()->is_started(), "skip '/health?' must not skip a sub-path" );
	}

	public function test_log_memory_config_flag(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-memory' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$this->assertTrue( $lm->is_started() );

		// Verify log_memory flag is set via reflection.
		$ref = new \ReflectionProperty( Log_Manager::class, 'log_memory' );
		$this->assertTrue( $ref->getValue( $lm ), 'log_memory should be true with logging-memory config' );

		// start/complete with log_memory should add peak_mb to complete entry.
		$lm->start( 'memory_test' );
		$lm->complete( 'memory_test' );
		$this->assertTrue( true );
	}

	// ── Real round-trip: write through Topic, read back from disk ──────────

	public function test_finish_computes_real_duration(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$lm->start( 'custom_event', [ 'm' => 'tracked with start' ] );

		// Brief sleep to ensure non-zero duration.
		\usleep( 5000 );
		$lm->finish();

		$complete_entry = self::last_entry_of( $this->written_entries(), 'process (complete)' );

		$this->assertNotNull( $complete_entry, 'Should have a process (complete) entry' );
		$this->assertArrayHasKey( 'duration_ms', $complete_entry );
		$this->assertGreaterThan( 0, $complete_entry['duration_ms'], 'Duration should be > 0ms' );
	}

	public function test_message_m_field_url_redaction(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'redaction_test', 'test' ] ] ] );
		$lm = Log_Manager::instance();
		$lm->start( 'redaction_test' );
		$lm->message( 'test', [ 'm' => 'https://example.com?client_secret=SECRET&id=123' ] );
		$lm->finish();

		$log_dir = self::test_dir() . '/logs/firehose.p0';
		$this->assertDirectoryExists( $log_dir );
		$files = \glob( $log_dir . '/*.log' );
		$this->assertNotEmpty( $files );

		$all_content = '';
		foreach ( $files as $file ) {
			$all_content .= \file_get_contents( $file );
		}
		$this->assertStringNotContainsString( 'SECRET', $all_content, 'Secret value should be redacted' );
		$this->assertStringContainsString( 'client_secret=[REDACTED]', $all_content, 'Should show redacted placeholder' );
		$this->assertStringContainsString( 'id=123', $all_content, 'Non-sensitive params should be preserved' );
	}

	/**
	 * Every firehose entry under the test base, each with its rid; the test
	 * wrote at least one, or there is nothing its assertions could read.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function written_entries(): array {
		$entries = self::firehose_entries( self::test_dir(), true );
		$this->assertNotEmpty( $entries, 'Firehose should have written data' );
		return $entries;
	}

	/**
	 * The curated environment_v3 map (the single `m` map) from a firehose read.
	 *
	 * @param array<int, array<string, mixed>> $entries Decoded firehose entries.
	 * @return array<string, mixed>
	 */
	private function env_map( array $entries ): array {
		foreach ( $entries as $entry ) {
			if ( Log_Manager::ENVIRONMENT === ( $entry['k'] ?? '' ) && \is_array( $entry['m'] ?? null ) ) {
				return $entry['m'];
			}
		}
		return [];
	}

	/**
	 * Regression: 'k' field must come from $category, not $data.
	 */
	public function test_message_k_field_not_overridable_by_data(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$lm->start( 'k_override_test' );
		$lm->message( 'job', [ 'k' => 'discovery', 'm' => 'test' ] );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'job' );
		$this->assertNotNull( $entry, 'Should find an entry with k=job' );
		$this->assertSame( 'job', $entry['k'], 'Category must come from $category param, not $data' );

		$bad_entry = self::last_entry_of( $this->written_entries(), 'discovery' );
		$this->assertNull( $bad_entry, 'Data array must not be able to override k field' );
	}

	/**
	 * Regression: 'ts' field CAN be overridden (profiler use case).
	 */
	public function test_message_ts_field_overridable_by_data(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'ts_override_test', 'test' ] ] ] );
		$lm = Log_Manager::instance();
		$lm->start( 'ts_override_test' );
		$lm->message( 'test', [ 'ts' => 12345.678, 'm' => 'hello' ] );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'test' );
		$this->assertNotNull( $entry, 'Should find an entry with k=test' );
		$this->assertEqualsWithDelta( 12345.678, $entry['ts'], 0.001, 'ts field must be overridable by $data for profiler use' );
	}

	/**
	 * Regression: 'rid' field must come from request_id, not $data.
	 */
	public function test_message_rid_field_not_overridable_by_data(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'rid_override_test', 'test' ] ] ] );
		$lm = Log_Manager::instance();
		$lm->start( 'rid_override_test' );
		$lm->message( 'test', [ 'rid' => 'fake_id', 'm' => 'hello' ] );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'test' );
		$this->assertNotNull( $entry, 'Should find an entry with k=test' );
		$this->assertNotSame( 'fake_id', $entry['rid'], 'rid must not be overridable by $data' );
		$this->assertSame( $lm->get_request_id(), $entry['rid'], 'rid must be the real request ID' );
	}

	/**
	 * Regression: 'n' field must come from line_number, not $data.
	 */
	public function test_message_n_field_not_overridable_by_data(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		// Set both possible env-var names — Config (parallel agent) may either
		// keep the legacy name or rename to match the new namespace.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'n_override_test', 'test' ] ] ] );
		$lm = Log_Manager::instance();
		$lm->start( 'n_override_test' );
		$lm->message( 'test', [ 'n' => 99999, 'm' => 'hello' ] );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'test' );
		$this->assertNotNull( $entry, 'Should find an entry with k=test' );
		$this->assertNotSame( 99999, $entry['n'], 'n must not be overridable by $data' );
		$this->assertIsInt( $entry['n'] );
		$this->assertGreaterThan( 0, $entry['n'] );
	}

	public function test_unique_id_server_var_used_when_set(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		$_SERVER['UNIQUE_ID'] = 'test-unique-id-123';
		$lm = Log_Manager::instance();
		// Trigger initialization.
		$lm->start( 'test' );
		$lm->complete( 'test' );

		$rid = $lm->get_request_id();
		$this->assertSame( 'test-unique-id-123', $rid );

		unset( $_SERVER['UNIQUE_ID'] );
	}

	// ── Constants preserved verbatim ───────────────────────────────────────

	public function test_max_timer_depth_constant_preserved(): void {
		$ref = new \ReflectionClassConstant( Log_Manager::class, 'MAX_TIMER_DEPTH' );
		$this->assertSame( 100, $ref->getValue() );
	}

	public function test_max_data_size_constant_preserved(): void {
		$ref = new \ReflectionClassConstant( Log_Manager::class, 'MAX_DATA_SIZE' );
		$this->assertSame( 3840, $ref->getValue() );
	}

	public function test_fatal_types_constant_preserved(): void {
		$this->assertSame(
			[ E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR ],
			Log_Manager::FATAL_TYPES
		);
	}

	// -- suspend / resume context stack --------------------------------------

	/**
	 * Read the static context stack via reflection.
	 *
	 * @return array
	 */
	private function read_context_stack(): array {
		$ref = new \ReflectionProperty( Log_Manager::class, 'context_stack' );
		return $ref->getValue();
	}

	/**
	 * Empty the static context stack via reflection. Tests share class state
	 * (context_stack is private static) and reset()/setUp() don't drain it
	 * automatically — a previous suspend that didn't resume would leak into
	 * the next test.
	 */
	private function clear_context_stack(): void {
		$ref = new \ReflectionProperty( Log_Manager::class, 'context_stack' );
		$ref->setValue( null, [] );
	}

	public function test_suspend_pushes_current_instance_onto_stack(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		$parent = Log_Manager::instance();
		// Trigger started state so suspend exercises the flush path.
		$parent->start( 'parent_op' );

		$this->assertCount( 0, $this->read_context_stack() );

		Log_Manager::suspend();

		$stack = $this->read_context_stack();
		$this->assertCount( 1, $stack, 'suspend() must push the instance onto the stack' );
		$this->assertSame( $parent, $stack[0] );

		// After suspend, instance() returns a NEW LogManager.
		$child = Log_Manager::instance();
		$this->assertNotSame( $parent, $child );

		// Drain so the static stack doesn't leak into the next test.
		$this->clear_context_stack();
	}

	public function test_suspend_when_no_instance_is_noop(): void {
		$this->clear_context_stack();
		Log_Manager::reset();
		// reset() finishes the singleton then nulls it. suspend() with null
		// instance should be a no-op (no fatal, no stack growth).
		Log_Manager::suspend();
		$this->assertCount( 0, $this->read_context_stack() );
	}

	public function test_resume_restores_parent_from_stack(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		$parent = Log_Manager::instance();
		$parent->start( 'parent_op' );
		Log_Manager::suspend();

		$child = Log_Manager::instance();
		$this->assertNotSame( $parent, $child );

		Log_Manager::resume();

		$this->assertSame( $parent, Log_Manager::instance(), 'resume() should restore the parent context' );
		$this->assertCount( 0, $this->read_context_stack(), 'stack should be empty after resume' );
	}

	public function test_resume_with_empty_stack_clears_instance(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		// No suspend before resume — should still finish current and null out.
		$lm = Log_Manager::instance();
		$lm->start( 'work' );

		Log_Manager::resume();

		// instance() now creates a fresh one (different identity).
		$fresh = Log_Manager::instance();
		$this->assertNotSame( $lm, $fresh );
	}

	public function test_suspend_resume_restores_unique_id(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		Log_Manager::reset();
		Config::reset();
		$_SERVER['UNIQUE_ID'] = 'parent-rid-abc';

		$parent = Log_Manager::instance();
		$parent->start( 'init' );
		$this->assertSame( 'parent-rid-abc', $parent->get_request_id() );

		Log_Manager::suspend();

		// Child overwrites UNIQUE_ID with its own.
		$_SERVER['UNIQUE_ID'] = 'child-rid-def';
		$child                = Log_Manager::instance();
		$child->start( 'child_work' );
		$this->assertSame( 'child-rid-def', $child->get_request_id() );

		Log_Manager::resume();

		$this->assertSame( 'parent-rid-abc', $_SERVER['UNIQUE_ID'], 'resume() must restore parent UNIQUE_ID' );
	}

	public function test_suspend_resume_nested_three_levels(): void {
		$this->require_config_or_skip();
		$this->clear_context_stack();

		$lm1 = Log_Manager::instance();
		$lm1->start( 'lm1' );
		Log_Manager::suspend();

		$lm2 = Log_Manager::instance();
		$lm2->start( 'lm2' );
		Log_Manager::suspend();

		$lm3 = Log_Manager::instance();
		$lm3->start( 'lm3' );

		$this->assertCount( 2, $this->read_context_stack() );
		$this->assertSame( $lm3, Log_Manager::instance() );

		Log_Manager::resume();
		$this->assertSame( $lm2, Log_Manager::instance() );

		Log_Manager::resume();
		$this->assertSame( $lm1, Log_Manager::instance() );

		$this->assertCount( 0, $this->read_context_stack() );
	}

	// -- flush() / refresh_firehose() ----------------------------------------

	public function test_flush_before_any_start_does_not_throw(): void {
		$this->require_config_or_skip();

		$lm = Log_Manager::instance();
		$lm->flush();
		$this->assertTrue( true );
	}

	public function test_flush_no_topic_is_noop(): void {
		$this->require_config_or_skip();
		// A declined request never ran init_firehose, so there is no Topic.
		$this->set_rules_option( [ [ 'id' => 'api', 'pattern' => '/api/', 'action' => 'log' ] ] );
		$_SERVER['REQUEST_URI'] = '/test/page';
		$lm                     = $this->fresh_log_manager();
		$this->assertFalse( $lm->is_started(), 'precondition: nothing matched' );

		$lm->flush();
		$this->assertTrue( true );
	}

	public function test_flush_calls_topic_flush_after_start(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );

		$lm = Log_Manager::instance();
		$lm->start( 'work' );
		$lm->message( 'before_flush', [ 'm' => 'data1' ] );
		$lm->flush();

		// After flush, contents should be visible on disk for any future read.
		$log_dir = self::test_dir() . '/logs/firehose.p0';
		$this->assertDirectoryExists( $log_dir );
		$files = \glob( $log_dir . '/*.log' );
		$this->assertNotEmpty( $files, 'flush() must drain the buffered batch to disk' );
	}

	public function test_refresh_firehose_no_topic_is_noop(): void {
		$this->require_config_or_skip();

		$lm = Log_Manager::instance();
		// No exception when topic is null.
		$lm->refresh_firehose();
		$this->assertTrue( true );
	}

	public function test_refresh_firehose_after_start_succeeds(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );

		$lm = Log_Manager::instance();
		$lm->start( 'work' );
		$lm->message( 'pre_refresh', [ 'm' => 'data' ] );
		$lm->flush();

		// Refresh should not throw even after a flush has materialized the segment.
		$lm->refresh_firehose();
		$this->assertTrue( true );

		// Subsequent writes should still land.
		$lm->message( 'post_refresh', [ 'm' => 'data2' ] );
		$lm->flush();
	}

	// -- log_environment / log_resources / log_process -----------------------

	public function test_finish_emits_environment_resources_memory_and_process_complete(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['HTTP_REFERER']     = 'https://example.com/page?token=SHHH&id=ok';
		$_SERVER['REMOTE_ADDR']      = '1.2.3.4';
		$_SERVER['DB_PASSWORD']      = 'do-not-log';
		$_SERVER['SOME_API_KEY']     = 'k-do-not-log';
		$_SERVER['CUSTOM_NICE_VAR']  = 'not-curated';
		$_SERVER['NEWSPACK_NODES_WORKER_TYPE']      = 'combined';
		$_SERVER['NEWSPACK_NODES_WORKER_PARTITION'] = '0';

		$lm = Log_Manager::instance();
		$lm->start( 'unit' );
		$lm->complete( 'unit' );
		$lm->finish();

		$entries = $this->written_entries();
		$kinds   = \array_column( $entries, 'k' );

		// log_environment emits exactly ONE curated environment_v3 message (not one-per-key).
		$env_entries = \array_values( \array_filter( $entries, static fn( $e ) => Log_Manager::ENVIRONMENT === ( $e['k'] ?? '' ) ) );
		$this->assertCount( 1, $env_entries, 'log_environment must emit exactly one curated environment_v3 entry' );

		// log_resources emits k=resources.
		$this->assertContains( 'resources', $kinds, 'log_resources must emit a resources entry' );
		// Its open and close samples read back through the one parser.
		$this->assertGreaterThanOrEqual( 0.0, Log_Manager::cpu_between_samples( $entries )['cpu_ms'] ?? -1.0 );

		// finish() emits k=memory and k=process (complete).
		$this->assertContains( 'memory', $kinds, 'finish must emit a memory entry' );
		$this->assertContains( 'process (complete)', $kinds, 'finish must emit process (complete)' );

		// The curated payload is a structured map under `m`.
		$env = $env_entries[0]['m'] ?? null;
		$this->assertIsArray( $env, 'environment_v3 must carry a structured map under m' );

		// Curated (allowlisted) keys present; sensitive + non-curated keys absent.
		$this->assertArrayHasKey( 'REMOTE_ADDR', $env, 'allowlisted REMOTE_ADDR must be present' );
		$this->assertSame( '1.2.3.4', $env['REMOTE_ADDR'] );
		$this->assertArrayNotHasKey( 'DB_PASSWORD', $env, 'DB_PASSWORD must be filtered out' );
		$this->assertArrayNotHasKey( 'SOME_API_KEY', $env, 'KEY-substring keys must be filtered' );
		$this->assertArrayNotHasKey( 'CUSTOM_NICE_VAR', $env, 'non-curated keys must be dropped' );

		// Worker identity is its own pair of entries, not environment.
		$this->assertArrayNotHasKey( 'NEWSPACK_NODES_WORKER_TYPE', $env );
		$this->assertArrayNotHasKey( 'NEWSPACK_NODES_WORKER_PARTITION', $env );

		// HTTP_REFERER present and redacted at the value layer.
		$this->assertArrayHasKey( 'HTTP_REFERER', $env );
		$this->assertStringNotContainsString( 'token=SHHH', $env['HTTP_REFERER'], 'URL-value sensitive params must be redacted' );
		$this->assertStringContainsString( 'token=[REDACTED]', $env['HTTP_REFERER'] );

		// Curated payload stays well under the firehose MAX_DATA_SIZE (3840) cap.
		$this->assertLessThan( 3840, \strlen( (string) \wp_json_encode( [ 'm' => $env ] ) ), 'curated env payload must stay under the size cap' );

		unset( $_SERVER['HTTP_REFERER'], $_SERVER['REMOTE_ADDR'], $_SERVER['DB_PASSWORD'], $_SERVER['SOME_API_KEY'], $_SERVER['CUSTOM_NICE_VAR'], $_SERVER['NEWSPACK_NODES_WORKER_TYPE'], $_SERVER['NEWSPACK_NODES_WORKER_PARTITION'] );
	}

	/**
	 * No ENV_ALLOWLIST entry may look like a secret.
	 *
	 * The allowlist is hand-edited and is the only key source `log_environment()`
	 * reads, so this is the one place the "nothing sensitive is logged" invariant
	 * can be enforced without a per-request check that would drop the key in
	 * silence. Adding `HTTP_X_AUTH_TOKEN` to the allowlist fails here, by name.
	 */
	public function test_no_allowlisted_environment_key_looks_like_a_secret(): void {
		$allowlist = ( new \ReflectionClass( Log_Manager::class ) )->getConstant( 'ENV_ALLOWLIST' );
		$this->assertIsArray( $allowlist );
		$this->assertNotEmpty( $allowlist, 'an empty allowlist would make this vacuously green' );

		$forbidden = [
			'AUTH', 'BEARER', 'CREDENTIAL', 'DSN', 'KEY', 'NONCE', 'PASS', 'PASSWD',
			'PASSWORD', 'PRIVATE', 'SALT', 'SECRET', 'TOKEN', '_URL',
		];

		$offenders = [];
		foreach ( $allowlist as $key ) {
			foreach ( $forbidden as $pattern ) {
				if ( false !== \strpos( \strtoupper( (string) $key ), $pattern ) ) {
					$offenders[] = "{$key} (matches {$pattern})";
				}
			}
		}

		$this->assertSame( [], $offenders, 'an allowlisted key that reads as a secret must not be logged' );
	}

	/**
	 * With every mandated allowlist key present at a realistic length, the
	 * single environment_v3 map stays comfortably under MAX_DATA_SIZE (3840),
	 * carries each present key, and redacts secrets in the URL-valued ones.
	 */
	public function test_environment_v3_full_allowlist_stays_under_cap_and_redacts(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$allowlist = [
			'A8C_PROXIED_REQUEST', 'ATOMIC_SITE_OPCACHE_MEMORY_MB', 'CONTENT_LENGTH', 'CONTENT_TYPE',
			'GEOIP_COUNTRY_CODE', 'HTTP_FROM', 'HTTP_HOST', 'HTTP_USER_AGENT', 'HTTP_X_A8C_EDGE_DC',
			'HTTP_X_A8C_REQUEST_ID', 'HTTP_X_EDGE_BLACKBOX_SCORE', 'HTTP_X_FORWARDED_FOR',
			'HTTP_X_IP_PROXY_TYPE', 'HTTP_X_JA3_HASH', 'HTTP_X_JA4T_HASH', 'HTTP_X_JA4T_LITE_HASH',
			'HTTP_X_JA4_HASH', 'HTTP_X_OPENAI_HOST_HASH', 'HTTP_X_REQUESTED_WITH', 'HTTP_X_SUPPORTLOGIN',
			'HTTP_X_TCP_RTT_AVG', 'HTTP_X_TCP_RTT_MIN', 'HTTP_X_VALID_CERTIFICATE', 'HTTP_X_WPLOGIN',
			'REMOTE_ADDR', 'REMOTE_PORT', 'REQUEST_SCHEME', 'REQUEST_TIME',
			'REQUEST_TIME_FLOAT', 'SERVER_NAME', 'UNIQUE_ID',
		];
		foreach ( $allowlist as $key ) {
			$_SERVER[ $key ] = \str_repeat( 'v', 40 );
		}
		// Worst case: several client-controllable values are pathologically long
		// (a 5KB user agent, a many-hop XFF, a 5KB proxied-request), plus the
		// URL-valued referer carrying a secret. The per-value cap must keep each
		// bounded so no single value blows the whole map past MAX_DATA_SIZE.
		$_SERVER['HTTP_USER_AGENT']      = \str_repeat( 'A', 5000 );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = \str_repeat( '1.2.3.4, ', 700 );
		$_SERVER['A8C_PROXIED_REQUEST']  = '/proxy?secret=NOPE&' . \str_repeat( 'x', 5000 );
		$_SERVER['HTTP_REFERER']         = 'https://example.com/prev?token=SEEKRIT&ok=1';

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries     = $this->written_entries();
		$env_entries = \array_values( \array_filter( $entries, static fn( $e ) => Log_Manager::ENVIRONMENT === ( $e['k'] ?? '' ) ) );
		$this->assertCount( 1, $env_entries, 'exactly one curated environment_v3 entry' );
		$env = $env_entries[0]['m'];
		$this->assertIsArray( $env );

		$this->assertArrayHasKey( 'REMOTE_ADDR', $env );
		$this->assertArrayHasKey( 'SERVER_NAME', $env );
		$this->assertArrayHasKey( 'UNIQUE_ID', $env );
		$this->assertArrayHasKey( 'HTTP_X_JA4_HASH', $env );
		// Request-line duplicates are intentionally absent.
		$this->assertArrayNotHasKey( 'REQUEST_METHOD', $env );
		$this->assertArrayNotHasKey( 'REQUEST_URI', $env );
		$this->assertArrayNotHasKey( 'QUERY_STRING', $env );

		// Secret redacted in the URL-valued referer AND in any other value that
		// carries a URL query (A8C_PROXIED_REQUEST), not just HTTP_REFERER.
		$this->assertStringContainsString( 'token=[REDACTED]', $env['HTTP_REFERER'] );
		$this->assertStringContainsString( 'secret=[REDACTED]', $env['A8C_PROXIED_REQUEST'] );
		$this->assertStringNotContainsString( 'SEEKRIT', (string) \wp_json_encode( $env ) );
		$this->assertStringNotContainsString( 'NOPE', (string) \wp_json_encode( $env ) );

		// Each oversized value is per-value capped (elided), so a single long
		// value can't push the encoded map over MAX_DATA_SIZE and drop the map.
		$cap = ( new \ReflectionClassConstant( Log_Manager::class, 'ENV_VALUE_MAX' ) )->getValue();
		foreach ( [ 'HTTP_USER_AGENT', 'HTTP_X_FORWARDED_FOR', 'A8C_PROXIED_REQUEST' ] as $long_key ) {
			$this->assertStringEndsWith( '…', $env[ $long_key ], "{$long_key} must be elided when over the cap" );
			$this->assertSame( $cap, \strlen( \substr( $env[ $long_key ], 0, -\strlen( '…' ) ) ), "{$long_key} capped to ENV_VALUE_MAX bytes" );
		}

		$encoded_size = \strlen( (string) \wp_json_encode( [ 'm' => $env ] ) );
		$this->assertLessThan( 3840, $encoded_size, "full curated env payload ({$encoded_size}B) must stay under the size cap" );

		foreach ( $allowlist as $key ) {
			unset( $_SERVER[ $key ] );
		}
	}

	/**
	 * Bug 2: a per-value length cap elides an oversized value so one long
	 * allowlisted value can't blow the whole environment_v3 map.
	 */
	public function test_environment_v3_caps_oversized_value(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['HTTP_USER_AGENT'] = \str_repeat( 'A', 4096 );

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$env = $this->env_map( $this->written_entries() );
		$this->assertArrayHasKey( 'HTTP_USER_AGENT', $env );
		$ua  = $env['HTTP_USER_AGENT'];
		$this->assertStringEndsWith( '…', $ua, 'oversized value must be ellipsis-elided' );
		$cap = ( new \ReflectionClassConstant( Log_Manager::class, 'ENV_VALUE_MAX' ) )->getValue();
		$this->assertSame( $cap, \strlen( \substr( $ua, 0, -\strlen( '…' ) ) ), 'value capped to ENV_VALUE_MAX bytes before the ellipsis' );

		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	/**
	 * Bug 3: URL-secret redaction is a catch-all over every allowlisted value
	 * that carries a URL query — not just HTTP_REFERER. A value like
	 * A8C_PROXIED_REQUEST=/x?token=SHHH must be redacted.
	 */
	public function test_environment_v3_redacts_url_secrets_in_any_value(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['A8C_PROXIED_REQUEST'] = '/x?token=SHHH&ok=1';

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$env = $this->env_map( $this->written_entries() );
		$this->assertArrayHasKey( 'A8C_PROXIED_REQUEST', $env );
		$this->assertStringContainsString( 'token=[REDACTED]', $env['A8C_PROXIED_REQUEST'] );
		$this->assertStringNotContainsString( 'SHHH', $env['A8C_PROXIED_REQUEST'] );

		unset( $_SERVER['A8C_PROXIED_REQUEST'] );
	}

	public function test_log_process_uses_mu_profiler_request_ts_for_process_start(): void {
		// With 00-newspack-profiler.php installed, $newspack_profiler carries
		// `request_ts` — a wall-clock microtime captured at mu-plugin load,
		// before any regular plugin runs. LogManager must stamp the firehose
		// `process (start)` entry with that ts (not the LogManager-emit-time
		// microtime, which lands deep in WP bootstrap) so RequestBuilder's
		// inflight_snapshot.start_time reflects the real PHP-request start.
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$early_ts                            = 1700000000.5;
		$GLOBALS['newspack_profiler']        = [
			'request_time' => \hrtime( true ),
			'request_ts'   => $early_ts,
			'plugins'      => [],
		];

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries       = $this->written_entries();
		$process_start = null;
		foreach ( $entries as $entry ) {
			if ( 'process (start)' === ( $entry['k'] ?? '' ) ) {
				$process_start = $entry;
				break;
			}
		}

		unset( $GLOBALS['newspack_profiler'] );

		$this->assertNotNull( $process_start, 'expected a process (start) firehose entry' );
		$this->assertSame( $early_ts, $process_start['ts'] );
	}

	/**
	 * Run the profiler mu-plugin's `plugins_loaded` flush with one plugin row,
	 * against whatever ruleset the caller seeded.
	 *
	 * @param array<string,mixed> $rule The single rule governing the request.
	 * @return list<array<string,mixed>> The firehose entries it produced.
	 */
	private function flush_plugin_row_under_rule( array $rule ): array {
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] = [ $rule ];

		$saved_actions          = $GLOBALS['_wp_actions'] ?? [];
		$GLOBALS['_wp_actions'] = [];
		try {
			$lm = Log_Manager::instance();
			$lm->start( 'init' );
			require \dirname( __DIR__, 2 ) . '/mu-plugins/00-newspack-profiler.php';
			$GLOBALS['newspack_profiler']['plugins'] = [
				[ 'slug' => 'zither', 'start_ts' => 1.0, 'duration_ns' => 1000, 'new_classes' => 0, 'new_files' => 0 ],
			];
			\do_action( 'plugins_loaded' );
			$lm->finish();
			return $this->written_entries();
		} finally {
			$GLOBALS['_wp_actions'] = $saved_actions;
			unset( $GLOBALS['newspack_profiler'], $GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] );
			Log_Manager::reset();
		}
	}

	/** Whether the entries open a plugin-load span for the seeded row. */
	private function opened_a_plugin_span( array $entries ): bool {
		foreach ( $entries as $entry ) {
			if ( 'zither plugin (start)' === ( $entry['k'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	public function test_a_rule_can_keep_plugin_loads_out_of_the_record(): void {
		// The mu-plugin measures either way; the rule decides only whether the
		// record carries two entries per site-activated plugin, which on a
		// forty-plugin site is eighty before the request does anything.
		$this->require_config_or_skip();
		$this->assertFalse(
			$this->opened_a_plugin_span( $this->flush_plugin_row_under_rule(
				[ 'id' => 'r', 'pattern' => '/', 'action' => 'log', 'log_plugin_loads' => false ]
			) ),
			'the rule said no plugin loads'
		);
	}

	public function test_a_rule_silent_about_plugin_loads_logs_none(): void {
		// Every diagnostic is an opt-in; a rule that says nothing has it off.
		$this->require_config_or_skip();
		$this->assertFalse(
			$this->opened_a_plugin_span( $this->flush_plugin_row_under_rule(
				[ 'id' => 'r', 'pattern' => '/', 'action' => 'log' ]
			) ),
			'silence logs none'
		);
	}

	public function test_log_process_records_method_and_full_url(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['SERVER_NAME']    = 'example.test';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['REQUEST_URI']    = '/api/work?key=hidden&q=visible';

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries = $this->written_entries();
		$request = null;
		foreach ( $entries as $entry ) {
			if ( 'request' === ( $entry['k'] ?? '' ) ) {
				$request = $entry;
			}
		}
		$this->assertNotNull( $request, 'process should log a request entry' );
		$this->assertSame( 'POST https://example.test/api/work?key=[REDACTED]&q=visible', $request['m'] ?? null, 'the request line is redacted' );
	}

	/** The request line carries REQUEST_URI's percent-encoded octets as sent. */
	public function test_log_process_keeps_percent_encoded_octets(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['SERVER_NAME']    = 'octet-6613.test';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['REQUEST_URI']    = '/?rest_route=%2Fzz%2Fv9%2Fqq&fields=a%2Cb';

		$this->fresh_log_manager()->finish();

		$lines = \array_column( \array_filter( $this->written_entries(), static fn ( array $e ): bool => 'request' === ( $e['k'] ?? '' ) ), 'm' );
		$this->assertSame( [ 'GET https://octet-6613.test/?rest_route=%2Fzz%2Fv9%2Fqq&fields=a%2Cb' ], \array_values( $lines ) );
	}

	/** Control characters never reach the request line; the rest of the URI does. */
	public function test_log_process_strips_control_characters_from_the_url(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['SERVER_NAME']    = 'ctl-3391.test';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['REQUEST_URI']    = "/ctl\x07-path\x7F?k[]=1";

		$this->fresh_log_manager()->finish();

		$lines = \array_column( \array_filter( $this->written_entries(), static fn ( array $e ): bool => 'request' === ( $e['k'] ?? '' ) ), 'm' );
		$this->assertSame( [ 'GET https://ctl-3391.test/ctl-path?k[]=1' ], \array_values( $lines ) );
	}

	/**
	 * The request line keeps REQUEST_URI's backslashes as sent: the logger
	 * reads it before `wp_magic_quotes()` slashes `$_SERVER`, so there is
	 * nothing to unslash.
	 */
	public function test_log_process_keeps_backslashes_in_the_url(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['SERVER_NAME']    = 'slash-5527.test';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['REQUEST_URI']    = '/a\\b?x=1';

		$this->fresh_log_manager()->finish();

		$lines = \array_column( \array_filter( $this->written_entries(), static fn ( array $e ): bool => 'request' === ( $e['k'] ?? '' ) ), 'm' );
		$this->assertSame( [ 'GET https://slash-5527.test/a\\b?x=1' ], \array_values( $lines ) );
	}

	/**
	 * The request line keeps a method's percent octets and loses only its
	 * control characters, so the line still parses and the request keeps
	 * its record.
	 */
	public function test_log_process_keeps_a_percent_octet_method(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$_SERVER['REQUEST_METHOD'] = "%47%45\x07%54";
		$_SERVER['SERVER_NAME']    = 'verb-8821.test';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['REQUEST_URI']    = '/thistle';

		$this->fresh_log_manager()->finish();

		$lines = \array_column( \array_filter( $this->written_entries(), static fn ( array $e ): bool => 'request' === ( $e['k'] ?? '' ) ), 'm' );
		$this->assertSame( [ '%47%45%54 https://verb-8821.test/thistle' ], \array_values( $lines ) );
		$this->assertSame(
			[ '%47%45%54', 'https://verb-8821.test/thistle', 'https://verb-8821.test/thistle' ],
			Log_Manager::parse_request_line( $lines[ \array_key_first( $lines ) ] )
		);
	}

	/**
	 * A method long enough to fill the entry is cut to its first 32 bytes, so
	 * the trim that would otherwise take the URL leaves the line whole.
	 */
	public function test_a_long_method_cannot_push_the_url_out_of_the_request_line(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$_SERVER['REQUEST_METHOD'] = \str_repeat( 'PROPFIND', 475 );
		$_SERVER['SERVER_NAME']    = 'verb-6093.test';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['REQUEST_URI']    = '/sorrel?n=4';

		$this->fresh_log_manager()->finish();

		$requests = \array_values( \array_filter( $this->written_entries(), static fn ( array $e ): bool => 'request' === ( $e['k'] ?? '' ) ) );
		$this->assertCount( 1, $requests );
		$this->assertSame(
			[ \str_repeat( 'PROPFIND', 4 ), 'https://verb-6093.test/sorrel?n=4', 'https://verb-6093.test/sorrel' ],
			Log_Manager::parse_request_line( $requests[0]['m'] )
		);
	}

	/**
	 * A method that is empty, or nothing but control characters, logs as
	 * `CLI`, as an absent one does, so the line still parses.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function blank_methods(): array {
		return [
			'empty'              => [ '' ],
			'control characters' => [ "\x07\x7F" ],
		];
	}

	#[DataProvider( 'blank_methods' )]
	public function test_a_blank_method_logs_as_cli( string $method ): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['SERVER_NAME']    = 'verb-7154.test';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['REQUEST_URI']    = '/yarrow';

		$this->fresh_log_manager()->finish();

		$lines = \array_column( \array_filter( $this->written_entries(), static fn ( array $e ): bool => 'request' === ( $e['k'] ?? '' ) ), 'm' );
		$this->assertSame( [ 'CLI https://verb-7154.test/yarrow' ], \array_values( $lines ) );
	}

	/**
	 * A method holding whitespace, `&` or `?` logs each as `_`, so the line
	 * parses with its URL and the redactor opens no parameter in the method.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function separator_methods(): array {
		return [
			'trailing space' => [ 'GET ', 'GET_' ],
			'inner space'    => [ 'A B', 'A_B' ],
			'ampersand'      => [ 'X&TOKEN', 'X_TOKEN' ],
			'question mark'  => [ 'Y?KEY', 'Y_KEY' ],
		];
	}

	#[DataProvider( 'separator_methods' )]
	public function test_a_method_logs_its_separators_as_underscores( string $method, string $logged ): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['SERVER_NAME']    = 'verb-2286.test';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['REQUEST_URI']    = '/p?a=1&b=2';

		$this->fresh_log_manager()->finish();

		$lines = \array_column( \array_filter( $this->written_entries(), static fn ( array $e ): bool => 'request' === ( $e['k'] ?? '' ) ), 'm' );
		$this->assertSame( [ "{$logged} https://verb-2286.test/p?a=1&b=2" ], \array_values( $lines ) );
		$this->assertSame(
			[ $logged, 'https://verb-2286.test/p?a=1&b=2', 'https://verb-2286.test/p' ],
			Log_Manager::parse_request_line( $lines[ \array_key_first( $lines ) ] )
		);
	}

	/**
	 * A request line too long for the entry is trimmed, still parses, and
	 * its `request_url` is the trimmed prefix.
	 */
	public function test_a_trimmed_request_line_parses_as_its_prefix(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['SERVER_NAME']    = 'long-3307.test';
		$_SERVER['HTTPS']          = 'on';
		$_SERVER['REQUEST_URI']    = '/burdock?q=' . \str_repeat( 'w', 5000 );

		$this->fresh_log_manager()->finish();

		$requests = \array_values( \array_filter( $this->written_entries(), static fn ( array $e ): bool => 'request' === ( $e['k'] ?? '' ) ) );
		$this->assertCount( 1, $requests );
		$this->assertTrue( $requests[0]['truncated'] ?? false, 'the entry says it was trimmed' );
		$line = Log_Manager::parse_request_line( $requests[0]['m'] );
		$this->assertNotNull( $line, 'the trimmed line still parses' );
		[ $method, $request_url, $url ] = $line;
		$this->assertSame( 'GET', $method );
		$this->assertStringStartsWith( 'https://long-3307.test/burdock?q=www', $request_url );
		$this->assertLessThan( \strlen( 'https://long-3307.test/burdock?q=' ) + 5000, \strlen( $request_url ) );
		$this->assertSame( 'https://long-3307.test/burdock', $url );
	}

	/** A rule matches a percent-encoded URI on its encoded form. */
	public function test_matches_url_filter_sees_percent_encoded_octets(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'rr', 'pattern' => '/?rest_route=%2fzz%2f', 'action' => 'log' ] ] );

		$_SERVER['REQUEST_URI'] = '/?rest_route=%2Fzz%2Fv9%2Fqq';
		$this->assertTrue( $this->fresh_log_manager()->is_started(), 'the encoded query prefix matches' );
	}

	public function test_log_process_https_off_uses_http_scheme(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$_SERVER['SERVER_NAME'] = 'plain.test';
		$_SERVER['HTTPS']       = 'off';

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries = $this->written_entries();
		$request = null;
		foreach ( $entries as $entry ) {
			if ( 'request' === ( $entry['k'] ?? '' ) ) {
				$request = $entry;
			}
		}
		$this->assertNotNull( $request );
		$this->assertStringContainsString( 'http://plain.test', (string) ( $request['m'] ?? '' ) );
		$this->assertStringNotContainsString( 'https://plain.test', (string) ( $request['m'] ?? '' ) );
	}

	/** A web request with no SERVER_NAME never starts, whatever the site's home URL. */
	public function test_log_process_without_server_name_throws_naming_it(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		unset( $_SERVER['SERVER_NAME'], $_SERVER['HTTPS'] );
		$_SERVER['REQUEST_URI']       = '/cli/path-7731';
		$_SERVER['REQUEST_METHOD']    = 'GET';
		$GLOBALS['_wp_test_home_url'] = 'https://wren-5521.test';

		$thrown = null;
		try {
			Log_Manager::instance();
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		} finally {
			unset( $GLOBALS['_wp_test_home_url'] );
		}
		$this->assertStringContainsString( 'SERVER_NAME', $thrown?->getMessage() ?? '' );
		$this->assertNull( Log_Manager::started_instance(), 'a request with no host never starts' );
	}

	/**
	 * Under WP-CLI a request logs under the site's own scheme and host, which
	 * home_url() names; WP-CLI sets no SERVER_NAME and no HTTPS.
	 */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function test_cli_request_logs_under_the_home_url_origin(): void {
		$this->require_config_or_skip();
		\define( 'WP_CLI', true );
		unset( $_SERVER['SERVER_NAME'], $_SERVER['HTTPS'] );
		$_SERVER['REQUEST_URI']       = '/jobs/import-film-times-6602';
		$_SERVER['REQUEST_METHOD']    = 'POST';
		$GLOBALS['_wp_test_home_url'] = 'https://quokka-4417.example';

		$lm = Log_Manager::instance();
		$lm->finish();

		$this->assertSame( 'POST https://quokka-4417.example/jobs/import-film-times-6602', $this->request_line() );
	}

	/** Under WP-CLI a SERVER_NAME the process set still names the host. */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function test_cli_request_with_a_server_name_logs_under_it(): void {
		$this->require_config_or_skip();
		\define( 'WP_CLI', true );
		$_SERVER['SERVER_NAME']       = 'wombat-2281.test';
		$_SERVER['HTTPS']             = 'on';
		$_SERVER['REQUEST_URI']       = '/cli/numbat-3390';
		$_SERVER['REQUEST_METHOD']    = 'GET';
		$GLOBALS['_wp_test_home_url'] = 'http://numbat-3390.example';

		$lm = Log_Manager::instance();
		$lm->finish();

		$this->assertSame( 'GET https://wombat-2281.test/cli/numbat-3390', $this->request_line() );
	}

	/** Under WP-CLI a home_url() naming no host still refuses to start. */
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	public function test_cli_request_with_a_hostless_home_url_throws(): void {
		$this->require_config_or_skip();
		\define( 'WP_CLI', true );
		unset( $_SERVER['SERVER_NAME'], $_SERVER['HTTPS'] );
		$_SERVER['REQUEST_URI']       = '/cli/path-8812';
		$GLOBALS['_wp_test_home_url'] = '';

		$thrown = null;
		try {
			Log_Manager::instance();
		} catch ( \RuntimeException $e ) {
			$thrown = $e;
		}
		$this->assertStringContainsString( 'home_url()', $thrown?->getMessage() ?? '' );
		$this->assertNull( Log_Manager::started_instance(), 'a request with no host never starts' );
	}

	/** The `m` of the one `request` line the firehose holds. */
	private function request_line(): string {
		$requests = \array_values( \array_filter( $this->written_entries(), static fn ( array $e ): bool => Log_Manager::REQUEST_LINE === ( $e['k'] ?? '' ) ) );
		$this->assertCount( 1, $requests );
		return (string) $requests[0]['m'];
	}

	// -- finish() orphan handling --------------------------------------------

	public function test_finish_emits_orphaned_complete_for_unclosed_starts(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'outer', 'middle', 'inner' ] ] ] );
		$lm = Log_Manager::instance();
		// Three unclosed starts on top of the root 'process' entry.
		$lm->start( 'outer' );
		$lm->start( 'middle' );
		$lm->start( 'inner' );

		$lm->finish();

		$entries  = $this->written_entries();
		$orphaned = [];
		foreach ( $entries as $entry ) {
			$k = $entry['k'] ?? '';
			$m = $entry['m'] ?? '';
			if ( '(orphaned)' === $m && \str_ends_with( $k, ' (complete)' ) ) {
				$orphaned[] = $k;
			}
		}
		$this->assertContains( 'outer (complete)', $orphaned );
		$this->assertContains( 'middle (complete)', $orphaned );
		$this->assertContains( 'inner (complete)', $orphaned );
		// Each orphan must carry a duration_ms key.
		foreach ( $entries as $entry ) {
			if ( '(orphaned)' === ( $entry['m'] ?? '' ) ) {
				$this->assertArrayHasKey( 'duration_ms', $entry );
			}
		}
	}

	public function test_finish_second_call_is_idempotent(): void {
		$this->require_config_or_skip();

		$lm = Log_Manager::instance();
		$lm->start( 'work' );
		$lm->finish();

		// Read property to confirm finished latch.
		$ref = new \ReflectionProperty( Log_Manager::class, 'finished' );
		$this->assertTrue( $ref->getValue( $lm ) );

		// Second finish must be a no-op (no exception, no extra writes).
		$lm->finish();
		$this->assertTrue( true );
	}

	public function test_finish_without_a_url_match_is_noop(): void {
		$this->require_config_or_skip();

		$lm = Log_Manager::instance();
		// Not matched (no request_url ruleset hit in this harness path) and
		// never started — finish must bail without emitting entries.
		( new \ReflectionProperty( Log_Manager::class, 'started' ) )->setValue( $lm, false );
		$lm->finish();

		$ref = new \ReflectionProperty( Log_Manager::class, 'finished' );
		$this->assertFalse( $ref->getValue( $lm ) );
	}

	// -- request_id sources: server-set only, never a request header ---------

	/**
	 * A client-suppliable header can never become the request id.
	 *
	 * The id is every firehose line's Message KEY, the identity
	 * Request_Builder_Node groups by, and the input to
	 * Partition_Node::hash_to_partition(). Adopting the header would let a
	 * visitor file lines under another request's id and choose their partition.
	 */
	public function test_forged_request_id_header_is_never_adopted(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		$_SERVER['HTTP_X_A8C_REQUEST_ID'] = 'a8c-forged-rid';
		unset( $_SERVER['UNIQUE_ID'] );

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$rid = $lm->get_request_id();

		$this->assertNotSame( 'a8c-forged-rid', $rid, 'a request header must never become the request id' );
		$this->assertSame( 32, \strlen( $rid ), 'with no UNIQUE_ID the id is generated, at 32 chars' );
		$this->assertMatchesRegularExpression( '/^[a-z0-9]+$/', $rid );
		$this->assertSame( $rid, $_SERVER['UNIQUE_ID'] ?? null, 'the generated id is published into UNIQUE_ID' );

		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'] );
	}

	public function test_unique_id_wins_over_a_forged_header(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		$_SERVER['UNIQUE_ID']             = 'apache-unique-id-9zq4';
		$_SERVER['HTTP_X_A8C_REQUEST_ID'] = 'a8c-forged-rid';

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$this->assertSame( 'apache-unique-id-9zq4', $lm->get_request_id() );

		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'] );
	}

	public function test_request_id_from_unique_id_capped_at_64_chars(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();

		$_SERVER['UNIQUE_ID'] = \str_repeat( 'U', 200 );

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$this->assertSame( 64, \strlen( $lm->get_request_id() ), 'Request id from UNIQUE_ID must be capped at 64 chars' );
	}

	/**
	 * The header value survives in environment_v3, which is the correlation
	 * path: `wp nodes reqgrep <edge id>` still finds the request through it.
	 */
	public function test_forged_header_still_reaches_environment_v3_for_correlation(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );

		$_SERVER['HTTP_X_A8C_REQUEST_ID'] = 'a8c-forged-rid';
		unset( $_SERVER['UNIQUE_ID'] );

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'init' ] ] ] );
		$lm = $this->fresh_log_manager();
		$lm->start( 'init' );
		$lm->finish();

		$env = $this->env_map( $this->written_entries() );
		$this->assertSame( 'a8c-forged-rid', $env['HTTP_X_A8C_REQUEST_ID'] ?? null );
		$this->assertNotSame( 'a8c-forged-rid', $lm->get_request_id() );

		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'] );
	}

	// -- message() guards ----------------------------------------------------

	public function test_message_returns_false_when_logging_disabled(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-disabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$this->assertFalse( $lm->is_started() );
		// message() is gated on `started`; a context that never started
		// returns false without writing.
		$this->assertFalse( $lm->message( 'never', [ 'm' => 'no-go' ] ) );
	}

	public function test_start_short_circuits_when_message_returns_false(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-disabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		// Disabled logger: start() should NOT push onto $times because
		// message() fails. Confirm via reflection.
		$lm->start( 'dud' );

		$ref = new \ReflectionProperty( Log_Manager::class, 'times' );
		$this->assertSame( [], $ref->getValue( $lm ), 'Disabled logger must not accumulate timer entries' );
	}

	public function test_start_blocks_at_max_timer_depth(): void {
		$this->require_config_or_skip();

		$cap_ref = new \ReflectionClassConstant( Log_Manager::class, 'MAX_TIMER_DEPTH' );
		$cap     = (int) $cap_ref->getValue();

		$lm = Log_Manager::instance();
		// Seed the timer stack at the cap via reflection — saves doing 100
		// real start() calls.
		$ref = new \ReflectionProperty( Log_Manager::class, 'times' );
		$seeded = [];
		for ( $i = 0; $i < $cap; $i++ ) {
			$seeded[] = [ 'label' => "seed_$i", 'ts' => \hrtime( true ) ];
		}
		$ref->setValue( $lm, $seeded );

		$lm->start( 'overflow' );
		$stack_after = $ref->getValue( $lm );
		$this->assertCount( $cap, $stack_after, 'start() must refuse to grow past MAX_TIMER_DEPTH' );

		// Reset for tearDown sanity.
		$ref->setValue( $lm, [] );
	}

	// ── message() oversized-data truncation path ───────────────────────────

	/**
	 * When the JSON-encoded `$data` exceeds MAX_DATA_SIZE (3840 bytes), `message()`
	 * trims `m` until the map fits, leaving the category alone so the readers
	 * still pair it. The exact knob that keeps firehose lines under PIPE_BUF.
	 */
	public function test_message_emits_truncated_entry_when_data_exceeds_max_size(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'truncation_test', 'oversized_event' ] ] ] );
		$lm = Log_Manager::instance();
		$lm->start( 'truncation_test' );
		// 5000 bytes — well over MAX_DATA_SIZE (3840).
		$lm->message( 'oversized_event', [ 'm' => \str_repeat( 'A', 5000 ) ] );
		$lm->finish();

		$entries = $this->written_entries();
		$truncated_entry = null;
		foreach ( $entries as $entry ) {
			if ( isset( $entry['k'] ) && 'oversized_event' === $entry['k'] ) {
				$truncated_entry = $entry;
				break;
			}
		}
		$this->assertNotNull( $truncated_entry, 'Oversized data must emit under its own category' );
		$this->assertArrayHasKey( 'm', $truncated_entry );
		// `m` is a real prefix of the value; the category carries the marker.
		$this->assertStringStartsWith( 'AAAA', (string) $truncated_entry['m'] );
		$this->assertLessThanOrEqual( 3840, \strlen( (string) \wp_json_encode( $truncated_entry ) ) );
	}

	/**
	 * An oversized STRING `m` is trimmed to fit; every other key survives.
	 * 6000 is distinct from both MAX_DATA_SIZE (3840) and the old 1000-char
	 * replacement, and `l` is what the flame aggregates on — losing it is what
	 * made a truncated span unopenable.
	 */
	public function test_message_trims_an_oversized_string_and_keeps_the_other_keys(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'truncation_test', 'oversized_event' ] ] ] );
		$lm = Log_Manager::instance();
		$lm->start( 'truncation_test' );
		$long = \str_repeat( 'A', 6000 );
		$lm->message( 'oversized_event', [ 'm' => $long, 'l' => 'Newspack_Blocks::build_articles_query' ] );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'oversized_event' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'Newspack_Blocks::build_articles_query', $entry['l'] ?? null );
		$this->assertStringStartsWith( 'AAAA', (string) $entry['m'] );
		$this->assertTrue( $entry['truncated'] ?? false, 'the wire says it was trimmed' );
		$this->assertLessThanOrEqual( 3840, \strlen( (string) \wp_json_encode( $entry ) ) );
	}

	/**
	 * The cap must bound what the WIRE carries. `n`, `k` and `ts` are stamped on
	 * after the caller's data, so a payload that clears the cap on its own can
	 * still put the entry over it. 3800 is chosen to encode just under 3840 as
	 * `$data` and just over it as an entry — distinct from the cap, from the
	 * 1000-char floor and from every other seed in this file.
	 */
	public function test_the_cap_bounds_the_entry_including_n_k_and_ts(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'truncation_test', 'edge_event' ] ] ] );
		$lm = Log_Manager::instance();
		$lm->start( 'truncation_test' );
		$lm->message( 'edge_event', [ 'm' => \str_repeat( 'A', 3800 ) ] );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'edge_event' );
		$this->assertNotNull( $entry );
		$this->assertLessThanOrEqual(
			3840,
			\strlen( (string) \wp_json_encode( $entry ) ),
			'the entry on the wire — not just the caller data — fits the cap'
		);
	}

	/** An oversized ARRAY `m` is dropped; the other keys still ride. */
	public function test_message_drops_an_oversized_array_and_keeps_the_other_keys(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'truncation_test', 'oversized_event' ] ] ] );
		$lm = Log_Manager::instance();
		$lm->start( 'truncation_test' );
		$lm->message( 'oversized_event', [ 'm' => \array_fill( 0, 400, \str_repeat( 'B', 40 ) ), 'l' => 'the_content' ] );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'oversized_event' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'the_content', $entry['l'] ?? null );
		$this->assertArrayNotHasKey( 'm', $entry );
		$this->assertTrue( $entry['truncated'] ?? false );
	}

	// ── message(): started without a topic ─────────────────────────────────

	/**
	 * `started` and the Topic are set together at construction, so the only
	 * way to reach the topic-null guard is to force `started=true` by
	 * reflection on a context the ruleset declined. message() must return
	 * false there rather than write through a null topic.
	 */
	public function test_message_short_circuits_when_started_set_externally(): void {
		$this->require_config_or_skip();
		// Non-matching URL: no eager start at construction, so topic is null.
		$this->set_rules_option( [ [ 'id' => 'api', 'pattern' => '/api/', 'action' => 'log' ] ] );
		$_SERVER['REQUEST_URI'] = '/other/page';
		$lm                     = $this->fresh_log_manager();
		// Force-start without calling start() — Topic remains null because
		// init_firehose was never invoked.
		$started_ref = new \ReflectionProperty( Log_Manager::class, 'started' );
		$started_ref->setValue( $lm, true );

		// Topic null → message() returns false at the topic-null guard.
		$result = $lm->message( 'orphan', [ 'm' => 'will-not-land' ] );
		$this->assertFalse( $result, 'message() must return false when topic is null' );

		// Cleanup.
		$started_ref->setValue( $lm, false );
	}

	// ── start(): graceful degradation when the timer stack is full ──────────

	/**
	 * Drive `start()` past MAX_TIMER_DEPTH through the public entry. After
	 * seeding the stack at the cap, the next call must short-circuit at the
	 * top guard (`count($this->times) >= self::MAX_TIMER_DEPTH`) without
	 * emitting OR mutating the stack. Two snapshots verify both invariants.
	 */
	public function test_start_silently_drops_past_max_timer_depth_via_public_api(): void {
		$this->require_config_or_skip();
		$cap_ref = new \ReflectionClassConstant( Log_Manager::class, 'MAX_TIMER_DEPTH' );
		$cap     = (int) $cap_ref->getValue();

		$lm  = Log_Manager::instance();
		$ref = new \ReflectionProperty( Log_Manager::class, 'times' );

		// Now overwrite to exactly cap entries (well past the seeded root).
		$seeded = [];
		for ( $i = 0; $i < $cap; $i++ ) {
			$seeded[] = [ 'label' => "fill_$i", 'ts' => \hrtime( true ) ];
		}
		$ref->setValue( $lm, $seeded );
		$this->assertCount( $cap, $ref->getValue( $lm ), 'pre-condition: stack at cap' );

		// This call MUST be a no-op — top guard short-circuits before message().
		$lm->start( 'should_drop' );

		$after = $ref->getValue( $lm );
		$this->assertCount( $cap, $after, 'start() at cap must NOT push' );
		// And the latest entry's label is still the fill marker, not 'should_drop'.
		$this->assertSame( 'fill_' . ( $cap - 1 ), \end( $after )['label'] );

		// Cleanup.
		$ref->setValue( $lm, [] );
	}

	// ── flush_every_line path ──────────────────────────────────────────────

	/**
	 * The `flush_every_line` config flag drains the Topic batch after every
	 * `message()` — verified by checking the logging-enabled config sets it
	 * AND a single message lands on disk before any explicit flush.
	 */
	public function test_message_flushes_immediately_when_flush_every_line_enabled(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		// logging-enabled config has flush_every_line=true.
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->message( 'visible_immediately', [ 'm' => 'no explicit flush' ] );

		// Without finish() AND without explicit $lm->flush(), the entry must
		// already be on disk because flush_every_line forced a drain.
		$log_dir = self::test_dir() . '/logs/firehose.p0';
		$this->assertDirectoryExists( $log_dir );
		$files = \glob( $log_dir . '/*.log' );
		$this->assertNotEmpty( $files, 'flush_every_line=true must drain on every message' );

		// Verify the flush_every_line property was actually set from config.
		$ref = new \ReflectionProperty( Log_Manager::class, 'flush_every_line' );
		$this->assertTrue( $ref->getValue( $lm ) );

		// Drain the singleton before tearDown.
		$lm->finish();
	}

	// ── matches_url_filter: most-specific-rule-wins composition ─────────────

	/**
	 * The ruleset matcher is longest-prefix / most-specific-wins, NOT global
	 * skip-priority: whichever rule's pattern is the more specific match
	 * governs, regardless of whether it's a log or a skip rule. Verifies both
	 * directions of that composition.
	 */
	public function test_most_specific_rule_wins_between_skip_and_log(): void {
		$this->require_config_or_skip();

		// A skip rule more specific than the '/' log baseline wins.
		$this->set_rules_option( [
			[ 'id' => 'root', 'pattern' => '/', 'action' => 'log' ],
			[ 'id' => 'health', 'pattern' => '/health', 'action' => 'skip' ],
		] );
		$_SERVER['REQUEST_URI'] = '/health';
		$this->assertFalse(
			$this->fresh_log_manager()->is_started(),
			'the more specific skip rule wins over the shorter log rule'
		);

		// A log rule more specific than a shorter skip rule wins.
		$this->set_rules_option( [
			[ 'id' => 'wp', 'pattern' => '/wp', 'action' => 'skip' ],
			[ 'id' => 'wp-admin', 'pattern' => '/wp-admin/', 'action' => 'log' ],
		] );
		$_SERVER['REQUEST_URI'] = '/wp-admin/edit';
		$this->assertTrue(
			$this->fresh_log_manager()->is_started(),
			'the more specific log rule wins over the shorter skip rule'
		);
	}

	/**
	 * URL with an explicit log rule and a NON-matching request —
	 * `matches_url_filter` returns false and leaves no governing rule
	 * behind (no rule matches ⇒ skip).
	 */
	public function test_matches_url_filter_returns_false_when_no_rule_matches(): void {
		$this->require_config_or_skip();
		$this->set_rules_option( [ [ 'id' => 'api', 'pattern' => '/api/', 'action' => 'log' ] ] );

		$lm     = $this->fresh_log_manager();
		$result = $lm->matches_url_filter( '/totally/not/matching' );
		$this->assertFalse( $result );
		$this->assertNull( $lm->governing_rule() );
	}

	// ── refresh_firehose: post-init delegation via reflection ───────────────

	/**
	 * `refresh_firehose()` reflects on Topic+Partition internals — covering
	 * this path requires a fully-initialized LogManager whose topic was
	 * materialized via `init_firehose`. After start() + flush(), reflection
	 * lookups succeed and `init_current_segment` invokes without throwing.
	 * The existing test asserts no-throw; this adds the no-segment-rotation
	 * invariant: the subsequent message lands under the same segment.
	 */
	public function test_refresh_firehose_preserves_segment_layout_after_no_writes(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$lm->start( 'pre' );
		$lm->flush();

		// Snapshot segment count.
		$log_dir   = self::test_dir() . '/logs/firehose.p0';
		$before    = \glob( $log_dir . '/*.log' );
		$before_n  = \count( $before );

		$lm->refresh_firehose();
		// Refresh shouldn't materialize a new segment when nothing wrote.
		$after   = \glob( $log_dir . '/*.log' );
		$after_n = \count( $after );
		$this->assertSame( $before_n, $after_n, 'refresh_firehose without writes must NOT rotate segments' );

		$lm->finish();
	}

	// ── log_environment: SERVER_NAME with control chars stripped on values ──

	/**
	 * Control characters in non-sensitive $_SERVER values are stripped before
	 * the environment_v3 line is emitted. The existing finish() test verifies
	 * \x07; this widens to a multi-byte control range.
	 */
	public function test_log_environment_strips_full_control_char_range(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		// SERVER_NAME is allowlisted; control bytes in its value are stripped.
		$_SERVER['SERVER_NAME'] = "before\x00\x01\x02\x1F\x7Fafter";

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries = $this->written_entries();
		$env     = $this->env_map( $entries );
		$this->assertArrayHasKey( 'SERVER_NAME', $env, 'SERVER_NAME must surface in the curated env map' );
		$value = (string) $env['SERVER_NAME'];
		// Control bytes removed; surrounding characters intact.
		$this->assertSame( 'beforeafter', $value );
		$this->assertStringNotContainsString( "\x00", $value );
		$this->assertStringNotContainsString( "\x1F", $value );
		$this->assertStringNotContainsString( "\x7F", $value );
	}

	/**
	 * Array-valued $_SERVER entries (rare in practice but possible via
	 * deserialization edge cases) are silently skipped — `log_environment`
	 * `continue`s past anything `is_array`. Confirms the guard runs without
	 * stringifying the array.
	 */
	public function test_log_environment_silently_skips_array_server_values(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		// An allowlisted key whose value is (pathologically) an array is skipped.
		$_SERVER['REMOTE_ADDR'] = [ 'nested', 'value' ];

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		$entries = $this->written_entries();
		$env     = $this->env_map( $entries );
		$this->assertArrayNotHasKey( 'REMOTE_ADDR', $env, 'array-valued env keys must be skipped' );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	// ── complete(): orphaned-inner with valid outer ─────────────────────────

	/**
	 * Started "outer" then "inner" then "deeper" — complete("outer") closes
	 * all three. The two inner stacks (`inner`, `deeper`) come back as
	 * orphaned `(complete)` entries with `m: (orphaned)`. The outer one
	 * gets a normal complete entry. Exercises the mid-stack splice branch.
	 */
	public function test_complete_emits_orphaned_for_nested_unfinished_starts(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$this->set_rules_option( [ [ 'id' => 'root', 'pattern' => '/', 'action' => 'log', 'custom_events' => [ 'outer', 'inner', 'deeper' ] ] ] );
		$lm = Log_Manager::instance();
		$lm->start( 'outer' );
		$lm->start( 'inner' );
		$lm->start( 'deeper' );
		$lm->complete( 'outer' ); // Splices all three off; inner+deeper are orphaned.
		$lm->finish();

		$entries = $this->written_entries();
		$orphan_labels = [];
		$normal_completes = [];
		foreach ( $entries as $entry ) {
			$k = $entry['k'] ?? '';
			$m = $entry['m'] ?? '';
			if ( '(orphaned)' === $m && \str_ends_with( $k, ' (complete)' ) ) {
				$orphan_labels[] = $k;
			} elseif ( \str_ends_with( $k, ' (complete)' ) ) {
				$normal_completes[] = $k;
			}
		}
		// inner + deeper come back orphaned (in stack-pop order).
		$this->assertContains( 'inner (complete)', $orphan_labels );
		$this->assertContains( 'deeper (complete)', $orphan_labels );
		// outer gets a normal complete entry.
		$this->assertContains( 'outer (complete)', $normal_completes );
	}

	// ── finish(): fatal-error tagging ───────────────────────────────────────

	/**
	 * `finish()` always evaluates `error_get_last()` and checks the type
	 * against FATAL_TYPES. In a normal test run, there's typically no
	 * fatal-type error, so the tagging block must skip cleanly without
	 * adding `fatal_error` / `error_status` keys to the final
	 * `process (complete)` entry. Confirms the no-fatal pathway end-to-end.
	 */
	public function test_finish_omits_fatal_tagging_when_no_fatal_error(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$lm = Log_Manager::instance();
		$lm->start( 'init' );
		$lm->finish();

		// Without an injected fatal, the tagging block writes neither
		// `fatal_error` nor `error_status` onto process (complete).
		$entries       = $this->written_entries();
		$process_entry = null;
		foreach ( $entries as $entry ) {
			if ( 'process (complete)' === ( $entry['k'] ?? '' ) ) {
				$process_entry = $entry;
			}
		}
		$this->assertNotNull( $process_entry );
		// status_code is always present; fatal-specific keys are not.
		$this->assertArrayHasKey( 'status_code', $process_entry );
	}

	// ── instance(): re-entrant call returns the SAME partial $this ──────────

	/**
	 * The construct guard's contract: while __construct is running, a second
	 * `instance()` call must return the SAME partial object — never a second
	 * LogManager. Verified by stashing $this into a static at the moment
	 * Config::load_config() would re-enter (via the bootstrap get_option
	 * hook seam). Already covered by `test_construct_blocks_reentrant_instance`,
	 * but this adds the after-construction invariant — instance() returns the
	 * canonical singleton.
	 */
	public function test_instance_returns_canonical_singleton_post_construction(): void {
		$this->require_config_or_skip();
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$a = Log_Manager::instance();
		$b = Log_Manager::instance();
		$c = Log_Manager::instance();
		$this->assertSame( $a, $b );
		$this->assertSame( $b, $c );
	}

	// =========================================================================
	// extract_plugin_slug: private static helper invoked from the fatal-tagging
	// path of finish(). Exercise its branches via reflection so we don't need
	// to actually crash PHP to populate error_get_last().
	// =========================================================================

	private function invoke_extract_plugin_slug( string $file ): ?string {
		$ref = new \ReflectionMethod( Log_Manager::class, 'extract_plugin_slug' );
		return $ref->invoke( null, $file );
	}

	public function test_extract_plugin_slug_returns_null_for_file_outside_plugins_dir(): void {
		// File path that does not start with WP_PLUGIN_DIR returns null.
		// (WP_PLUGIN_DIR defaults to /tmp/test-wp-plugins in the bootstrap.)
		$this->assertNull( $this->invoke_extract_plugin_slug( '/var/www/wp-includes/wp-db.php' ) );
		$this->assertNull( $this->invoke_extract_plugin_slug( '/etc/passwd' ) );
		$this->assertNull( $this->invoke_extract_plugin_slug( '' ) );
	}

	public function test_extract_plugin_slug_returns_directory_slug_for_subdirectory_plugin(): void {
		// File inside a plugin subdirectory — slug is the first path segment
		// after WP_PLUGIN_DIR.
		$path = \WP_PLUGIN_DIR . '/akismet/akismet.php';
		$this->assertSame( 'akismet', $this->invoke_extract_plugin_slug( $path ) );

		// Nested file under the same plugin returns the same slug.
		$nested = \WP_PLUGIN_DIR . '/akismet/views/admin.php';
		$this->assertSame( 'akismet', $this->invoke_extract_plugin_slug( $nested ) );
	}

	// -------------------------------------------------------------------------
	// firehose_dirs — the one owner of the firehose layout. Readers (dashboard
	// grep, reqgrep) resolve their partitions through it rather than each
	// rebuilding `.p{N}` and looping to the global count.
	// -------------------------------------------------------------------------

	public function test_firehose_dirs_span_the_global_count_when_no_topology_declares_one(): void {
		// 3 is distinct from the 1 a missing key would fall back to.
		$this->use_base_dir( self::test_dir(), [ 'num_partitions' => 3 ] );

		$dirs = Log_Manager::firehose_dirs();

		$logs = \Newspack_Nodes\Core::resolve_config_token( 'config', 'logs_dir' );
		$this->assertSame(
			[ "{$logs}/firehose.p0", "{$logs}/firehose.p1", "{$logs}/firehose.p2" ],
			\array_values( $dirs )
		);
	}

	public function test_firehose_dirs_ignore_a_narrower_topology_declaration(): void {
		// The writer hashes the rid over the config count on every request, so
		// a topology pinning 1 worker must not shrink the reader's span to 1
		// and hide two thirds of the data.
		$this->use_base_dir( self::test_dir(), [ 'num_partitions' => 3 ] );
		$stock = self::test_dir() . '/tsl';
		\mkdir( $stock, 0755, true );
		\file_put_contents(
			"{$stock}/narrow.tsl",
			"make_node Topic firehose:topic <config:logs_dir>/firehose.p{partition} 1\n"
		);
		\Newspack_Nodes\Topology_Registry::reset_basename_cache();
		\Newspack_Nodes\Topology_Registry::register_stock_dir( $stock );
		\add_filter(
			'newspack_nodes/topologies',
			static function ( array $topologies ): array {
				$topologies['narrow'] = [ 'topology' => 'narrow', 'num_partitions' => 1, 'stale_timeout' => 60 ];
				return $topologies;
			}
		);
		\update_option( 'newspack_nodes_topologies', [ 'narrow' ] );
		\Newspack_Nodes\Config::reset();

		try {
			$dirs = Log_Manager::firehose_dirs();

			$logs = \Newspack_Nodes\Core::resolve_config_token( 'config', 'logs_dir' );
			$this->assertSame(
				[ "{$logs}/firehose.p0", "{$logs}/firehose.p1", "{$logs}/firehose.p2" ],
				\array_values( $dirs )
			);
		} finally {
			\Newspack_Nodes\Topology_Registry::reset_basename_cache();
			\delete_option( 'newspack_nodes_topologies' );
			\Newspack_Nodes\Config::reset();
		}
	}

	public function test_firehose_dirs_ignore_a_wider_topology_declaration(): void {
		// `var num_partitions` is a topology's WORKER count. It says nothing
		// about how many firehose partitions exist — the writer hashes the rid
		// over the config count and nothing else, so the reader spans that and
		// nothing else. A topology pinning 5 does not conjure two more.
		$this->use_base_dir( self::test_dir(), [ 'num_partitions' => 3 ] );
		$stock = self::test_dir() . '/tsl-wide';
		\mkdir( $stock, 0755, true );
		\file_put_contents(
			"{$stock}/wide.tsl",
			"make_node Topic firehose:topic <config:logs_dir>/firehose.p{partition} 5\n"
		);
		\Newspack_Nodes\Topology_Registry::reset_basename_cache();
		\Newspack_Nodes\Topology_Registry::register_stock_dir( $stock );
		\add_filter(
			'newspack_nodes/topologies',
			static function ( array $topologies ): array {
				$topologies['wide'] = [ 'topology' => 'wide', 'num_partitions' => 5, 'stale_timeout' => 60 ];
				return $topologies;
			}
		);
		\update_option( 'newspack_nodes_topologies', [ 'wide' ] );
		\Newspack_Nodes\Config::reset();

		try {
			$dirs = Log_Manager::firehose_dirs();

			$logs = \Newspack_Nodes\Core::resolve_config_token( 'config', 'logs_dir' );
			$this->assertSame(
				[ "{$logs}/firehose.p0", "{$logs}/firehose.p1", "{$logs}/firehose.p2" ],
				\array_values( $dirs )
			);
		} finally {
			\Newspack_Nodes\Topology_Registry::reset_basename_cache();
			\delete_option( 'newspack_nodes_topologies' );
			\Newspack_Nodes\Config::reset();
		}
	}

	public function test_firehose_dirs_override_strips_the_log_suffix(): void {
		$this->use_base_dir( self::test_dir(), [ 'num_partitions' => 2 ] );

		$dirs = Log_Manager::firehose_dirs( '/somewhere/else/firehose' );

		$this->assertSame(
			[ '/somewhere/else/firehose.p0', '/somewhere/else/firehose.p1' ],
			\array_values( $dirs )
		);
	}

	/**
	 * A bare base is a hint about where the logs live, so it spans. A path that
	 * already names a partition is an instruction, and answers for that
	 * partition alone — keyed by the index it names, not re-based to 0.
	 */
	public function test_firehose_dirs_override_naming_a_partition_answers_for_it_alone(): void {
		$this->use_base_dir( self::test_dir(), [ 'num_partitions' => 4 ] );

		$dirs = Log_Manager::firehose_dirs( '/somewhere/else/firehose.p2' );

		$this->assertSame( [ 2 => '/somewhere/else/firehose.p2' ], $dirs );
	}

	public function test_extract_plugin_slug_strips_php_suffix_for_single_file_plugin(): void {
		// Single-file plugin directly under WP_PLUGIN_DIR — slug strips `.php`.
		$path = \WP_PLUGIN_DIR . '/hello.php';
		$this->assertSame( 'hello', $this->invoke_extract_plugin_slug( $path ) );

		// A weird file with no .php extension at the top level returns it raw.
		$path2 = \WP_PLUGIN_DIR . '/raw-segment';
		$this->assertSame( 'raw-segment', $this->invoke_extract_plugin_slug( $path2 ) );
	}

	/** A plugin swapped in as a release runs from that release, which a fatal names. */
	public function test_extract_plugin_slug_reads_the_slug_from_a_release_path(): void {
		$path = \WP_CONTENT_DIR . '/plugin-releases/kereru-9182/20261008223021288837917-61651-v4.2.0/kereru-9182/includes/class-x.php';
		$this->assertSame( 'kereru-9182', $this->invoke_extract_plugin_slug( $path ) );
	}

	/** PHP names a fatal's file with every symlink resolved, the content dir's included. */
	public function test_extract_plugin_slug_matches_the_resolved_content_dir(): void {
		$real = \sys_get_temp_dir() . '/eln-real-content-' . \getmypid();
		\mkdir( $real . '/plugin-releases', 0777, true );
		\symlink( $real, \WP_CONTENT_DIR );
		try {
			$path = \realpath( $real ) . '/plugin-releases/tui-5520/20261008223021288837917-61651-v1.9.0/tui-5520/tui-5520.php';
			$this->assertSame( 'tui-5520', $this->invoke_extract_plugin_slug( $path ) );
		} finally {
			\unlink( \WP_CONTENT_DIR );
			\exec( 'rm -rf ' . \escapeshellarg( $real ) );
		}
	}

	/** A file directly in a plugin's release root sits in no release, so names no plugin. */
	public function test_extract_plugin_slug_returns_null_for_a_release_path_without_a_plugin_dir(): void {
		$this->assertNull( $this->invoke_extract_plugin_slug( \WP_CONTENT_DIR . '/plugin-releases/kereru-9182/stray.php' ) );
	}

	/** The wire string readers group by; the JS fold exemption spells it too. */
	public function test_the_environment_category_is_the_wire_string_readers_expect(): void {
		$this->assertSame( 'environment_v3', Log_Manager::ENVIRONMENT );
	}

	/**
	 * Each case in `tests/fixtures/url-redaction.json` redacts as written.
	 *
	 * `Gyrobase::Log`'s suite reads its own copy, and dndocker's
	 * `tools/check-firehose-parity.py` holds the two copies identical and runs
	 * both redactors over them, so a case added here binds both producers.
	 */
	#[DataProvider( 'url_redaction_provider' )]
	public function test_redact_url_gives_the_shared_verdict( string $url, string $expected ): void {
		$this->assertSame( $expected, Log_Manager::redact_url( $url ) );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function url_redaction_provider(): array {
		$cases = \json_decode( (string) \file_get_contents( __DIR__ . '/../fixtures/url-redaction.json' ), true );
		\assert( \is_array( $cases ) );
		$out = [];
		foreach ( $cases as $case ) {
			$out[ (string) $case[0] ] = [ (string) $case[1], (string) $case[2] ];
		}
		return $out;
	}

	/** A PCRE failure redacts the whole query rather than logging it as sent. */
	public function test_redact_url_fails_closed_when_pcre_fails(): void {
		$jit   = (string) \ini_get( 'pcre.jit' );
		$limit = (string) \ini_get( 'pcre.backtrack_limit' );
		\ini_set( 'pcre.jit', '0' );
		\ini_set( 'pcre.backtrack_limit', '1' );
		try {
			$redacted = Log_Manager::redact_url( 'https://pcre.test/a&b?apikey=lemongrass88&p=3' );
		} finally {
			\ini_set( 'pcre.jit', $jit );
			\ini_set( 'pcre.backtrack_limit', $limit );
		}
		$this->assertSame( 'https://pcre.test/a?[REDACTED]', $redacted );
	}

	/**
	 * A string holding neither `?` nor `&` returns as written without a PCRE
	 * call: the error a forced failure left stays unread and uncleared.
	 */
	public function test_redact_url_spends_no_pcre_call_on_a_plain_line(): void {
		$jit   = (string) \ini_get( 'pcre.jit' );
		$limit = (string) \ini_get( 'pcre.backtrack_limit' );
		\ini_set( 'pcre.jit', '0' );
		\ini_set( 'pcre.backtrack_limit', '1' );
		try {
			\preg_match( '/(a|aa)+$/', \str_repeat( 'a', 24 ) . 'c' );
		} finally {
			\ini_set( 'pcre.jit', $jit );
			\ini_set( 'pcre.backtrack_limit', $limit );
		}
		$armed    = \preg_last_error();
		$redacted = Log_Manager::redact_url( 'template_redirect hook (start)' );
		$after    = \preg_last_error();

		$this->assertSame( \PREG_BACKTRACK_LIMIT_ERROR, $armed, 'the failure is armed' );
		$this->assertSame( 'template_redirect hook (start)', $redacted );
		$this->assertSame( \PREG_BACKTRACK_LIMIT_ERROR, $after, 'no PCRE call cleared it' );
	}

	/**
	 * A request line splits into the record's `request_method`, its
	 * `request_url` with the query, and its `url`, that URL less its query.
	 * The method is whatever token the producer wrote, because both producers
	 * write `REQUEST_METHOD` as the client sent it.
	 *
	 * @return array<string,array{string,array{string,string,string}}>
	 */
	public static function request_lines(): array {
		return [
			'a query'          => [ 'PATCH https://kea.test/?rest_route=%2Fzz%2Fv9&fields=a%2Cb', [ 'PATCH', 'https://kea.test/?rest_route=%2Fzz%2Fv9&fields=a%2Cb', 'https://kea.test/' ] ],
			'a CLI job'        => [ 'CLI http://kea.test/jobs/kea-sync/4471', [ 'CLI', 'http://kea.test/jobs/kea-sync/4471', 'http://kea.test/jobs/kea-sync/4471' ] ],
			'a WebDAV method'  => [ 'PROPFIND https://kea.test/dav?depth=1', [ 'PROPFIND', 'https://kea.test/dav?depth=1', 'https://kea.test/dav' ] ],
			'a cache method'   => [ 'PURGE https://kea.test/feed/', [ 'PURGE', 'https://kea.test/feed/', 'https://kea.test/feed/' ] ],
			'a lowercase verb' => [ 'get https://kea.test/x?y=2', [ 'get', 'https://kea.test/x?y=2', 'https://kea.test/x' ] ],
		];
	}

	/** @param array{string,string,string} $parsed */
	#[DataProvider( 'request_lines' )]
	public function test_parse_request_line_splits_method_request_url_and_url( string $message, array $parsed ): void {
		$this->assertSame( $parsed, Log_Manager::parse_request_line( $message ) );
	}

	/**
	 * What holds no method and absolute URL parses as none.
	 *
	 * @return array<string,array{mixed}>
	 */
	public static function non_request_lines(): array {
		return [
			'no url'         => [ 'GET' ],
			'empty url'      => [ 'GET ' ],
			'array body'     => [ [ 'GET', 'https://kea.test/' ] ],
			'absent'         => [ null ],
			'overflow stub'  => [ '(truncated, original 4213 bytes)' ],
			'a bare path'    => [ 'GET /jobs/kea-sync/4471' ],
			'a scheme alone' => [ 'GET https://' ],
		];
	}

	#[DataProvider( 'non_request_lines' )]
	public function test_parse_request_line_refuses_what_is_no_request_line( mixed $message ): void {
		$this->assertNull( Log_Manager::parse_request_line( $message ) );
	}

	/**
	 * A `job` entry's `m` is the TRANSPORT `Job_Router_Node` dispatches from,
	 * not a message, and `message()` redacts a string `m` alone: an array
	 * body passes untouched, or the worker receives a literal `[REDACTED]`.
	 */
	public function test_message_leaves_a_job_transport_body_intact(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		$url = 'https://site.test/feed?consumer_key=ck_7f3a91c2e4';
		$lm  = Log_Manager::instance();
		$lm->start( 'job_transport_test' );
		$lm->message( 'job', [
			'm' => [
				'handler'    => 'whack-cdn',
				'id'         => 'transport-1',
				'parameters' => [ 'urls' => [ $url ] ],
			],
		] );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'job' );
		$this->assertNotNull( $entry, 'Should find an entry with k=job' );
		$this->assertSame( $url, $entry['m']['parameters']['urls'][0] );
	}

	/**
	 * A query's `m` is a SHAPE: `App\Core::without_literals()` has already
	 * replaced every literal and stripped every comment, so every `?` left in
	 * it is a placeholder. The URL redactor reads one as a query delimiter,
	 * matches `post_password` on its `passw` token, and — SQL carrying no `&`
	 * to stop at — eats the statement from that `=` to the end, taking the
	 * ORDER BY and LIMIT a slow query is read from.
	 */
	public function test_a_query_shape_survives_a_credential_shaped_column_name(): void {
		$this->require_config_or_skip();
		$this->rmdir_recursive( self::test_dir() );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_path( 'logging-enabled' ) );
		Config::reset();

		// A placeholder ahead of the column is what arms it: the redactor reads
		// that `?` as the delimiter and `post_password` as the parameter name.
		$shape = 'SELECT wp_posts.ID FROM wp_posts WHERE wp_posts.ID NOT IN (?)'
			. ' AND wp_posts.post_password = ? AND wp_posts.post_type = ?'
			. ' ORDER BY wp_posts.menu_order DESC LIMIT ?, ?';
		$lm    = Log_Manager::instance();
		$lm->start( 'sql', [ 'l' => 'WP_Query->get_posts' ] );
		$lm->complete( 'sql', [ 'm' => $shape ], 'complete', true );
		$lm->finish();

		$entry = self::last_entry_of( $this->written_entries(), 'sql (complete)' );
		$this->assertNotNull( $entry, 'Should find an entry with k=sql (complete)' );
		$this->assertSame( $shape, $entry['m'] );
	}

}
