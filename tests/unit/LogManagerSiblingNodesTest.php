<?php
/**
 * Sibling-node discipline for Log_Manager.
 *
 * Rule 2 of the make_node plan: a utility node created INSIDE another
 * node/helper as internal plumbing must be NAMED, and (when an
 * `_command_interpreter` is in scope) SUNK into it. Log_Manager's firehose
 * `Topic_Node` is such a sibling — these tests pin that contract.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Topic_Node;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Log_Manager::class )]
class LogManagerSiblingNodesTest extends TestCase {

	private const TEST_DIR = '/tmp/event-logger-nodes-sibling-test';

	/** @var array Original $_SERVER backup. */
	private array $orig_server;

	protected function setUp(): void {
		parent::setUp();
		$this->orig_server = $_SERVER;

		Log_Manager::reset();
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			Config::reset();
		}

		$_SERVER['REQUEST_URI']    = '/test/page';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['SERVER_NAME']    = 'localhost';
		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'], $_SERVER['UNIQUE_ID'], $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] );

		@\mkdir( self::TEST_DIR . '/logs', 0755, true );
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
		$_SERVER = $this->orig_server;
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		$this->rmdir_recursive( self::TEST_DIR );
		parent::tearDown();
	}

	private function config_path( string $name ): string {
		return \dirname( __DIR__ ) . '/configs/' . $name . '.php';
	}

	private function require_config_or_skip(): void {
		if ( ! \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			$this->markTestSkipped( 'Config class not available.' );
		}
	}

	/** Read the private firehose Topic after init_firehose has materialized it. */
	private function topic( Log_Manager $lm ): ?Topic_Node {
		$ref = new \ReflectionProperty( Log_Manager::class, 'topic' );
		$ref->setAccessible( true );
		return $ref->getValue( $lm );
	}

	/**
	 * The firehose Topic is plumbing — Rule 2 requires it be named so it shows
	 * up in dump_metadata / `ls` rather than as an anonymous node.
	 */
	public function test_firehose_topic_is_named(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();
		$lm->start( 'init' ); // triggers ensure_started -> init_firehose.

		$topic = $this->topic( $lm );
		$this->assertInstanceOf( Topic_Node::class, $topic );
		$this->assertSame( 'firehose:topic', $topic->name(), 'firehose Topic uses the canonical bare name' );
		$this->assertSame( $topic, Core::node( 'firehose:topic' ) );
	}

	/**
	 * One firehose Topic per process: if `firehose:topic` already exists (the
	 * aggregator topology's, or a suspended parent context's), Log_Manager reuses
	 * it rather than creating a second writer over the same partition files.
	 */
	public function test_reuses_existing_firehose_topic(): void {
		$this->require_config_or_skip();

		// Pre-register a configured Topic under the canonical name (stands in for the topology's).
		$preexisting = new Topic_Node();
		$preexisting->name( 'firehose:topic' );
		$preexisting->arguments( self::TEST_DIR . '/logs/firehose.p{partition} 1' );

		$lm = Log_Manager::instance();
		$lm->start( 'init' );

		$this->assertSame( $preexisting, $this->topic( $lm ), 'Log_Manager must reuse the existing firehose:topic' );
	}

	/**
	 * The firehose Topic is plumbing — patron-linked (self, since Log_Manager is
	 * not a Node) so dump_metadata hides it from the canvas.
	 */
	public function test_firehose_topic_is_patron_linked(): void {
		$this->require_config_or_skip();
		$lm = Log_Manager::instance();
		$lm->start( 'init' );

		$topic = $this->topic( $lm );
		$this->assertInstanceOf( Topic_Node::class, $topic );
		$this->assertSame( $topic, $topic->patron(), 'firehose Topic must be self-patron-linked so dump_metadata hides it' );
	}

	/**
	 * When an `_command_interpreter` is registered (worker / graph context),
	 * the firehose Topic sibling routes its emissions into it (Rule 2 sink).
	 */
	public function test_firehose_topic_sinks_into_command_interpreter_when_present(): void {
		$this->require_config_or_skip();

		// Stand up a real interpreter under the reserved name BEFORE init.
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$this->assertSame( $interpreter, Core::node( Node_Names::COMMAND_INTERPRETER ) );

		$lm = Log_Manager::instance();
		$lm->start( 'init' );

		$topic = $this->topic( $lm );
		$this->assertInstanceOf( Topic_Node::class, $topic );
		$this->assertSame(
			$interpreter,
			$topic->sink(),
			'firehose Topic must sink into the in-scope _command_interpreter'
		);
	}
}
