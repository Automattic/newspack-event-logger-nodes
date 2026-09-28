<?php
/**
 * Tests for Diagnostics_Bridge — the verb wrapper that opens each dispatched
 * verb's span, and the listener that carries substrate
 * `newspack_nodes/stderr` lines into the firehose / Error Log.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Diagnostics_Bridge;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;

#[CoversClass( Diagnostics_Bridge::class )]
class DiagnosticsBridgeTest extends TestCase {

	/** @var array<string, mixed> Original $_SERVER backup. */
	private array $orig_server;

	protected function setUp(): void {
		parent::setUp();
		$this->orig_server = $_SERVER;
		$this->rmdir_recursive( self::TEST_DIR );
		@\mkdir( self::TEST_DIR . '/logs', 0755, true );
		Log_Manager::reset();
		Config::reset();
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . \dirname( __DIR__ ) . '/configs/logging-enabled.php' );
		Config::reset();
		$_SERVER['REQUEST_URI']    = '/diag/page';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['UNIQUE_ID'] );
	}

	protected function tearDown(): void {
		Log_Manager::reset();
		Config::reset();
		$_SERVER = $this->orig_server;
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		$this->rmdir_recursive( self::TEST_DIR );
		parent::tearDown();
	}

	/** A started Log_Manager whose '/' rule logs every URL. */
	private function started_log_manager(): Log_Manager {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] = [
			[ 'id' => 'root', 'pattern' => '/', 'action' => 'log' ],
		];
		Log_Manager::reset();
		Config::reset();
		$lm = Log_Manager::instance();
		$lm->start( 'diag' );
		return $lm;
	}

	// ── stderr bridge ─────────────────────────────────────────────────────────

	public function test_stderr_logs_to_the_started_log_manager(): void {
		$lm = $this->started_log_manager();
		Diagnostics_Bridge::on_stderr( 'worker spinner detected 7731' );
		$lm->finish();

		$stderr = self::entries_of( self::firehose_entries( self::TEST_DIR ), 'stderr' );
		$this->assertCount( 1, $stderr, 'stderr line logged to the active request firehose' );
		$this->assertSame( 'worker spinner detected 7731', $stderr[0]['m'] );
	}

	public function test_stderr_is_dropped_when_no_started_log_manager(): void {
		Log_Manager::reset();
		Diagnostics_Bridge::on_stderr( 'orphan diagnostic 7732' );
		$this->assertNull( Log_Manager::started_instance() );
		$this->assertDirectoryDoesNotExist( self::TEST_DIR . '/logs/firehose.p0' );
		$this->assertDirectoryDoesNotExist( self::TEST_DIR . '/logs/errors.p0' );
	}

	// ── verb spans ────────────────────────────────────────────────────────────

	/** A bare interpreter, named as a topology names one. */
	private static function interpreter( string $name, ?\Newspack_Nodes\Node $patron = null ): Command_Interpreter_Node {
		$ci = new Command_Interpreter_Node();
		if ( null !== $patron ) {
			$ci->patron( $patron );
		}
		$ci->name( $name );
		return $ci;
	}

	/** One verb run through the wrapper with no wrapper before it. */
	private static function wrapped( Command_Interpreter_Node $ci, string $verb, \Closure $run ): mixed {
		return Diagnostics_Bridge::around_dispatch( null )( $ci, $verb, $run );
	}

	public function test_a_verb_is_one_span_named_for_its_class_with_the_node_in_start(): void {
		$lm     = $this->started_log_manager();
		$result = self::wrapped( self::interpreter( 'wombat-ci' ), 'probe7731', static fn (): array => [ 'rows' => 7731 ] );
		$lm->finish();

		$this->assertSame( [ 'rows' => 7731 ], $result, 'the verb\'s result passes through' );
		$entries = self::firehose_entries( self::TEST_DIR );
		$start   = self::entries_of( $entries, 'Command_Interpreter probe7731 command (start)' );
		$done    = self::entries_of( $entries, 'Command_Interpreter probe7731 command (complete)' );
		$this->assertCount( 1, $start );
		$this->assertCount( 1, $done );
		$this->assertSame( 'wombat-ci', $start[0]['m'], 'the node name rides the start line' );
		$this->assertSame( 'ok', $done[0]['m'] );
		$this->assertArrayNotHasKey( 'l', $start[0], 'a label would split the span from the picker' );
		$this->assertArrayNotHasKey( 'l', $done[0] );
	}

	/** A config interpreter is its patron's: the span names the patron's make_node type. */
	public function test_a_patrons_interpreter_names_its_span_for_the_patron(): void {
		$lm = $this->started_log_manager();
		self::wrapped( self::interpreter( 'kakapo:config', new \Newspack_Nodes\Tee_Node() ), 'probe7735', static fn (): string => 'ok' );
		$lm->finish();

		$start = self::entries_of( self::firehose_entries( self::TEST_DIR ), 'Tee probe7735 command (start)' );
		$this->assertCount( 1, $start );
		$this->assertSame( 'kakapo:config', $start[0]['m'] );
	}

	public function test_a_service_ci_names_its_span_without_namespace_or_suffix(): void {
		$lm = $this->started_log_manager();
		$ci = new \Newspack_Event_Logger_Nodes\App\Discovery_CI_Node();
		$ci->name( 'discovery-7739' );
		self::wrapped( $ci, 'get', static fn (): string => 'ok' );
		$lm->finish();

		$start = self::entries_of( self::firehose_entries( self::TEST_DIR ), 'Discovery_CI get command (start)' );
		$this->assertCount( 1, $start );
		$this->assertSame( 'discovery-7739', $start[0]['m'] );
	}

	/**
	 * Describe what one throwable does to the verb's span.
	 *
	 * @return array{0:\Throwable,1:list<array<string,mixed>>} What propagated, and the entries written.
	 */
	private function thrown_through( \Throwable $thrown ): array {
		$lm     = $this->started_log_manager();
		$caught = null;
		try {
			self::wrapped( self::interpreter( 'wombat-ci' ), 'boom7732', static fn (): never => throw $thrown );
		} catch ( \Throwable $e ) {
			$caught = $e;
		}
		$lm->finish();
		$this->assertNotNull( $caught, 'the throwable must propagate' );
		return [ $caught, self::entries_of( self::firehose_entries( self::TEST_DIR ), 'Command_Interpreter boom7732 command (complete)' ) ];
	}

	public function test_a_throwing_verb_closes_its_span_with_the_short_class_and_rethrows(): void {
		$thrown               = new \DomainException( 'refused 7732' );
		[ $caught, $complete ] = $this->thrown_through( $thrown );

		$this->assertSame( $thrown, $caught, 'the throwable propagates untouched' );
		$this->assertCount( 1, $complete );
		$this->assertSame( 'DomainException', $complete[0]['m'] );
	}

	/** An anonymous class's name carries a file path and a NUL; the span says only that. */
	public function test_an_anonymous_throwable_closes_its_span_as_class_at_anonymous(): void {
		[ $caught, $complete ] = $this->thrown_through( new class( 'refused 7736' ) extends \DomainException {} );

		$this->assertSame( 'refused 7736', $caught->getMessage() );
		$this->assertSame( 'class@anonymous', $complete[0]['m'] );
	}

	/** A cooperative stop is signalling, not a failure: the span says `stop`. */
	public function test_a_stop_closes_its_span_as_stop_and_propagates(): void {
		$stop                  = new \Newspack_Nodes\Worker_Should_Stop();
		[ $caught, $complete ] = $this->thrown_through( $stop );

		$this->assertSame( $stop, $caught );
		$this->assertSame( 'stop', $complete[0]['m'] );
	}

	public function test_a_verb_with_no_started_logger_writes_nothing(): void {
		Log_Manager::reset();

		$result = self::wrapped( self::interpreter( 'wombat-ci' ), 'probe7733', static fn (): string => 'kea-7733' );

		$this->assertSame( 'kea-7733', $result );
		$this->assertNull( Log_Manager::started_instance() );
		$this->assertDirectoryDoesNotExist( self::TEST_DIR . '/logs/firehose.p0' );
	}

	/**
	 * Every dispatch in every worker passes through the wrapper, so with no
	 * logger it must not even name the span: an interpreter that throws when
	 * asked for its patron or its name proves nothing asked.
	 */
	public function test_a_verb_with_no_started_logger_builds_no_label(): void {
		Log_Manager::reset();
		$ci = new class() extends Command_Interpreter_Node {
			public function patron( ?\Newspack_Nodes\Node $node = null ): ?\Newspack_Nodes\Node {
				throw new \LogicException( 'the span label was built: patron() 7743' );
			}
		};
		$previous = static fn ( Command_Interpreter_Node $ci, string $verb, \Closure $run ): string => "{$verb}: " . $run();

		$this->assertSame( 'kea-7743', self::wrapped( $ci, 'probe7743', static fn (): string => 'kea-7743' ) );
		$this->assertSame(
			'probe7743: kea-7743',
			Diagnostics_Bridge::around_dispatch( $previous )( $ci, 'probe7743', static fn (): string => 'kea-7743' ),
			'the wrapper before it still runs'
		);
	}

	/**
	 * A wrapper assigned before this one runs INSIDE the span, and what it
	 * returns is what the verb returns.
	 */
	public function test_a_previous_wrapper_runs_inside_the_span_and_its_result_threads_through(): void {
		$lm       = $this->started_log_manager();
		$previous = static function ( Command_Interpreter_Node $ci, string $verb, \Closure $run ): string {
			Log_Manager::started_instance()?->message( 'moa-7738', [ 'm' => $ci->name() ] );
			return "{$verb} via moa: " . $run();
		};

		$result = Diagnostics_Bridge::around_dispatch( $previous )( self::interpreter( 'weka-ci' ), 'probe7738', static fn (): string => 'kea-7738' );
		$lm->finish();

		$this->assertSame( 'probe7738 via moa: kea-7738', $result );
		$categories = \array_column( self::firehose_entries( self::TEST_DIR ), 'k' );
		$inner      = \array_search( 'moa-7738', $categories, true );
		$this->assertIsInt( $inner, 'the previous wrapper ran' );
		$this->assertGreaterThan( \array_search( 'Command_Interpreter probe7738 command (start)', $categories, true ), $inner );
		$this->assertLessThan( \array_search( 'Command_Interpreter probe7738 command (complete)', $categories, true ), $inner );
	}

	/** Through the substrate's own dispatch: install() wraps a real verb, and keeps the wrapper before it. */
	public function test_install_wraps_a_dispatched_verb_around_the_wrapper_before_it(): void {
		$lm    = $this->started_log_manager();
		$saved = Command_Interpreter_Node::$around_dispatch;
		$seen  = [];
		Command_Interpreter_Node::$around_dispatch = static function ( Command_Interpreter_Node $ci, string $verb, \Closure $run ) use ( &$seen ): mixed {
			$seen[] = $verb;
			return $run();
		};
		try {
			Diagnostics_Bridge::install();
			$uptime = self::interpreter( 'kiwi-7734' )->dispatch( 'uptime' );
		} finally {
			Command_Interpreter_Node::$around_dispatch = $saved;
		}
		$lm->finish();

		$this->assertSame( [ 'uptime' ], $seen, 'the wrapper before it still ran' );
		$this->assertNotSame( '', $uptime );
		$lines = self::entries_of(
			self::firehose_entries( self::TEST_DIR ),
			'Command_Interpreter uptime command (start)',
			'Command_Interpreter uptime command (complete)'
		);
		$this->assertCount( 2, $lines );
		$this->assertSame( 'kiwi-7734', $lines[0]['m'] );
	}

	// ── bootstrap wiring ──────────────────────────────────────────────────────

	public function test_the_plugin_file_bridges_stderr_and_no_alert_action(): void {
		$boot = $GLOBALS['_eln_boot_actions'] ?? [];
		$this->assertArrayHasKey( 'newspack_nodes/stderr', $boot );
		$this->assertContains( [ Diagnostics_Bridge::class, 'on_stderr' ], $boot['newspack_nodes/stderr'] );
		$this->assertArrayNotHasKey(
			'newspack_nodes/alert',
			$boot,
			'the alert bridge was replaced by the substrate alerts.p0 journal'
		);
	}

	/**
	 * The deferred bootstrap is the one place the verb seam is installed, so
	 * run it: past its floor, a verb dispatched through the substrate opens
	 * its span, and a wrapper assigned before the bootstrap runs inside it.
	 * Every static the body registers into is put back afterwards.
	 */
	public function test_the_deferred_bootstrap_spans_a_dispatched_verb_around_the_wrapper_before_it(): void {
		$lm         = $this->started_log_manager();
		$formatters = new \ReflectionProperty( \Newspack_Nodes\Formatters::class, 'registry' );
		$saved      = [
			'actions'    => $GLOBALS['_wp_actions'],
			'filters'    => $GLOBALS['_wp_test_filters'] ?? [],
			'around'     => Command_Interpreter_Node::$around_dispatch,
			'resolvers'  => \Newspack_Nodes\Core::$config_resolvers,
			'formatters' => $formatters->getValue(),
		];
		Command_Interpreter_Node::$around_dispatch = static function ( Command_Interpreter_Node $ci, string $verb, \Closure $run ): mixed {
			Log_Manager::started_instance()?->message( 'moa-7747', [ 'm' => $verb ] );
			return $run();
		};
		try {
			\newspack_event_logger_nodes_boot();
			$uptime = self::interpreter( 'kiwi-7747' )->dispatch( 'uptime' );
		} finally {
			$GLOBALS['_wp_actions']                    = $saved['actions'];
			$GLOBALS['_wp_test_filters']               = $saved['filters'];
			Command_Interpreter_Node::$around_dispatch = $saved['around'];
			\Newspack_Nodes\Core::$config_resolvers   = $saved['resolvers'];
			$formatters->setValue( null, $saved['formatters'] );
		}
		$lm->finish();

		$this->assertNotSame( '', $uptime );
		$categories = \array_column( self::firehose_entries( self::TEST_DIR ), 'k' );
		$start      = \array_search( 'Command_Interpreter uptime command (start)', $categories, true );
		$sentinel   = \array_search( 'moa-7747', $categories, true );
		$complete   = \array_search( 'Command_Interpreter uptime command (complete)', $categories, true );
		$this->assertIsInt( $start, 'the verb opened its span' );
		$this->assertIsInt( $complete, 'and closed it' );
		$this->assertIsInt( $sentinel, 'the wrapper before the bootstrap still ran' );
		$this->assertGreaterThan( $start, $sentinel, 'inside the span' );
		$this->assertLessThan( $complete, $sentinel, 'inside the span' );
	}
}
