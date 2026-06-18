<?php
/**
 * StreamMerger unit tests.
 *
 * StreamMerger is now the hub-side orchestrator: it owns RemoteSource children,
 * loads enabled remotes from ServerRegistry, fans periodic ticks to children,
 * and writes the shared offsetlog. RemoteSourceTest covers the SSE parser,
 * cURL callbacks, heartbeat, and ingest-filter data path in isolation.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Remote_Source_Node;
use Newspack_Event_Logger_Nodes\Stream_Merger_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Vault;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Stream_Merger_Node::class )]
class StreamMergerTest extends TestCase {

	private string $tmp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		Event_Framework::reset();
		unset( $GLOBALS['_wp_actions']['newspack_nodes/aggregator_ingest_line'] );

		$this->tmp_dir = $this->make_temp_dir( 'stream-merger-' );
		$this->use_base_dir( $this->tmp_dir );
		Core::$memd = new InMemoryMemcached();
		Core::$now  = 1000.0;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_wp_actions']['newspack_nodes/aggregator_ingest_line'],
			$GLOBALS['_wp_options'][ Vault::OPTION_KEY ]
		);
		Vault::get_instance()->reset_cache();
		Event_Framework::reset();
		Config::reset();
		parent::tearDown();
	}

	private function make_merger( string $name = 'test-stream-merger' ): Stream_Merger_Node {
		$merger = new Stream_Merger_Node();
		$merger->name( $name );
		$merger->arguments( 'firehose 2' );
		$merger->set_require_https( false );
		return $merger;
	}

	/**
	 * @param array<string,array<string,mixed>> $servers
	 */
	private function seed_registry( array $servers ): void {
		$GLOBALS['_wp_options'][ Vault::OPTION_KEY ] = $servers;
		Vault::get_instance()->reset_cache();
	}

	private function make_server( string $url, bool $enabled = true ): array {
		return [
			'url'           => $url,
			'auth_username' => 'admin',
			'auth_password' => 'application password',
			'enabled'       => $enabled,
			'logs'          => [ 'firehose.log' ],
		];
	}

	/**
	 * @return array<string,Remote_Source_Node>
	 */
	private function remotes( Stream_Merger_Node $merger ): array {
		return $merger->remote_nodes();
	}

	private function request_message( string $value ): array {
		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_REQUEST;
		$message[ Message::FROM ]      = 'client';
		$message[ Message::TO ]        = '';
		$message[ Message::ID ]        = 'req-1';
		$message[ Message::KEY ]       = 'status';
		$message[ Message::VALUE ]     = $value;
		return $message;
	}

	private function command_interpreter(): Command_Interpreter_Node {
		$router = new Router_Node();
		$router->name( Node_Names::ROUTER );

		$ci = new Command_Interpreter_Node();
		$ci->name( Node_Names::COMMAND_INTERPRETER );
		$ci->sink( $router );
		return $ci;
	}

	private function offsetlog( Stream_Merger_Node $merger ): ?Partition_Node {
		$ref = new \ReflectionProperty( Stream_Merger_Node::class, 'offsetlog' );
		$ref->setAccessible( true );
		$value = $ref->getValue( $merger );
		return $value instanceof Partition_Node ? $value : null;
	}

	private function read_bool( object $object, string $property ): bool {
		return (bool) $this->read_private( $object, $property );
	}

	public function test_arguments_parse_topic_and_clamp_partition(): void {
		$merger = new Stream_Merger_Node();
		$merger->arguments( 'requests -5' );

		$this->assertSame( 'requests', $this->read_private( $merger, 'remote_topic' ) );
		$this->assertSame( 0, $this->read_private( $merger, 'partition' ) );
		$this->assertSame( 'requests -5', $merger->arguments() );
	}

	public function test_node_schema_exposes_current_orchestrator_contract(): void {
		$schema = Stream_Merger_Node::node_schema();

		$this->assertFalse( $schema['accepts_fill'] );
		$this->assertSame( 'remote_topic', $schema['arguments'][0]['name'] );
		$this->assertSame( 'partition', $schema['arguments'][1]['name'] );

		$commands = \array_column( $schema['commands'], 'name' );
		$this->assertSame( [ 'set_verify_ssl', 'set_require_https' ], $commands );

		$requests = \array_column( $schema['requests'], 'name' );
		$this->assertSame( [ 'GET_REMOTES' ], $requests );
	}

	public function test_dump_config_only_emits_non_default_security_toggles(): void {
		$merger = new Stream_Merger_Node();
		$merger->name( 'aggregator' );

		$this->assertStringNotContainsString( 'set_verify_ssl', $merger->dump_config() );
		$this->assertStringNotContainsString( 'set_require_https', $merger->dump_config() );

		$merger->set_verify_ssl( false );
		$merger->set_require_https( false );

		$config = $merger->dump_config();
		$this->assertStringContainsString( "cmd aggregator:config set_verify_ssl false\n", $config );
		$this->assertStringContainsString( "cmd aggregator:config set_require_https false\n", $config );
	}

	public function test_setters_propagate_to_existing_children(): void {
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://site-a.test' ) ] );
		$merger = $this->make_merger();
		$merger->add_remote( 'site-a' );
		$remote = $this->remotes( $merger )['site-a'];

		$merger->set_verify_ssl( false );
		$merger->set_require_https( true );

		$this->assertFalse( $this->read_bool( $remote, 'verify_ssl' ) );
		$this->assertTrue( $this->read_bool( $remote, 'require_https' ) );
	}

	public function test_setters_apply_to_children_added_later(): void {
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://site-a.test' ) ] );
		$merger = $this->make_merger();
		$merger->set_verify_ssl( false );
		$merger->set_require_https( true );
		$merger->add_remote( 'site-a' );

		$remote = $this->remotes( $merger )['site-a'];
		$this->assertFalse( $this->read_bool( $remote, 'verify_ssl' ) );
		$this->assertTrue( $this->read_bool( $remote, 'require_https' ) );
	}

	public function test_add_remote_reads_registry_and_registers_child(): void {
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://site-a.test/' ) ] );
		$capture = new Capture_Sink_Node();
		$merger  = $this->make_merger();
		$merger->sink( $capture );
		$merger->connect_node( 'firehose:topic' );

		$remote = $this->remotes( $merger )['site-a'];
		$this->assertInstanceOf( Remote_Source_Node::class, $remote );
		$this->assertSame( 'site-a', $remote->server_id() );
		$this->assertSame( 'https://site-a.test', $remote->url() );
		$this->assertSame( 'test-stream-merger:remote:site-a', $remote->name() );
		$this->assertSame( $capture, $remote->sink() );
		$this->assertSame( 'firehose:topic', $remote->target() );
		$this->assertSame( $remote, Core::node( 'test-stream-merger:remote:site-a' ) );
	}

	public function test_add_remote_uses_stable_fallback_name_when_merger_is_unnamed(): void {
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://site-a.test' ) ] );
		$merger = new Stream_Merger_Node();
		$merger->arguments( 'firehose 0' );
		$merger->set_require_https( false );
		$merger->add_remote( 'site-a' );

		$remote = $this->remotes( $merger )['site-a'];
		$this->assertSame( 'stream-merger:remote:site-a', $remote->name() );
		$this->assertSame( $remote, Core::node( 'stream-merger:remote:site-a' ) );
	}

	public function test_add_remote_skips_missing_disabled_missing_url_and_non_https_entries(): void {
		$this->seed_registry(
			[
				'disabled' => $this->make_server( 'https://disabled.test', false ),
				'empty'    => [ 'url' => '', 'enabled' => true ],
				'plain'    => $this->make_server( 'http://plain.test' ),
			]
		);

		$merger = new Stream_Merger_Node();
		$merger->name( 'aggregator' );
		$merger->arguments( 'firehose 0' );

		$merger->add_remote( 'missing' );
		$merger->add_remote( 'disabled' );
		$merger->add_remote( 'empty' );
		$merger->add_remote( 'plain' );

		$this->assertSame( [], $this->remotes( $merger ) );
	}

	public function test_add_remote_replaces_existing_child_for_same_server(): void {
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://first.test' ) ] );
		$merger = $this->make_merger();
		$merger->add_remote( 'site-a' );
		$first = $this->remotes( $merger )['site-a'];

		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://second.test' ) ] );
		$merger->add_remote( 'site-a' );
		$second = $this->remotes( $merger )['site-a'];

		$this->assertNotSame( $first, $second );
		$this->assertSame( 'https://second.test', $second->url() );
		$this->assertNull( $first->test_get_handle() );
		$this->assertSame( $second, Core::node( 'test-stream-merger:remote:site-a' ) );
	}

	public function test_load_remotes_from_registry_only_loads_enabled_servers(): void {
		$this->seed_registry(
			[
				'site-a'   => $this->make_server( 'https://site-a.test' ),
				'disabled' => $this->make_server( 'https://disabled.test', false ),
				'site-b'   => $this->make_server( 'https://site-b.test' ),
			]
		);

		$merger = $this->make_merger();
		$merger->load_remotes_from_registry();

		$this->assertSame( [ 'site-a', 'site-b' ], \array_keys( $this->remotes( $merger ) ) );
	}

	public function test_connect_node_loads_registry_once(): void {
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://site-a.test' ) ] );

		$merger = $this->make_merger();
		$merger->connect_node( 'firehose:topic' );
		$this->assertSame( [ 'site-a' ], \array_keys( $this->remotes( $merger ) ) );

		$this->seed_registry(
			[
				'site-a' => $this->make_server( 'https://site-a.test' ),
				'site-b' => $this->make_server( 'https://site-b.test' ),
			]
		);
		$merger->connect_node( 'firehose:other' );

		$this->assertSame( [ 'site-a' ], \array_keys( $this->remotes( $merger ) ) );
	}

	public function test_get_remotes_request_returns_child_statuses(): void {
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://site-a.test' ) ] );
		$capture = new Capture_Sink_Node();
		$merger  = $this->make_merger();
		$merger->sink( $capture );
		$merger->add_remote( 'site-a' );

		$message = $this->request_message( 'GET_REMOTES' );
		$merger->fill( $message );

		$this->assertCount( 1, $capture->captured );
		$reply = $capture->captured[0];
		$this->assertSame( Message::TM_STRUCT | Message::TM_RESPONSE, $reply[ Message::TYPE ] );
		$this->assertSame( 'test-stream-merger', $reply[ Message::FROM ] );
		$this->assertSame( 'client', $reply[ Message::TO ] );
		$this->assertSame( 'req-1', $reply[ Message::ID ] );
		$this->assertSame( 1, $reply[ Message::VALUE ]['count'] );
		$this->assertArrayHasKey( 'site-a', $reply[ Message::VALUE ]['remotes'] );
		$this->assertSame( [ 'segment_id' => 0, 'offset' => 0 ], $reply[ Message::VALUE ]['remotes']['site-a']['position'] );
	}

	public function test_unknown_request_returns_error_response(): void {
		$capture = new Capture_Sink_Node();
		$merger  = $this->make_merger();
		$merger->sink( $capture );

		$message = $this->request_message( 'NOPE now' );
		$merger->fill( $message );

		$this->assertSame( [ 'error' => 'unknown request verb: NOPE' ], $capture->captured[0][ Message::VALUE ] );
	}

	public function test_non_request_fill_does_not_emit_response(): void {
		$capture = new Capture_Sink_Node();
		$merger  = $this->make_merger();
		$merger->sink( $capture );

		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::VALUE ]     = [ 'ignored' => true ];
		$merger->fill( $message );

		$this->assertSame( [], $capture->captured );
		$this->assertSame( 1, $merger->counter() );
	}

	public function test_fill_requires_sink(): void {
		$merger  = $this->make_merger();
		$message = $this->request_message( 'GET_REMOTES' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Stream_Merger::fill requires a wired sink' );

		$merger->fill( $message );
	}

	public function test_commit_all_writes_combined_offsetlog_entry(): void {
		$this->command_interpreter();
		$this->seed_registry(
			[
				'site-a' => $this->make_server( 'https://site-a.test' ),
				'site-b' => $this->make_server( 'https://site-b.test' ),
			]
		);

		$merger = $this->make_merger();
		$merger->add_remote( 'site-a' );
		$merger->add_remote( 'site-b' );
		$this->remotes( $merger )['site-a']->restore_position( 3, 40 );
		$this->remotes( $merger )['site-b']->restore_position( 4, 50 );

		Core::$now = 1234.0;
		$merger->commit_all();

		$offsetlog = $this->offsetlog( $merger );
		$this->assertInstanceOf( Partition_Node::class, $offsetlog );
		$this->assertSame( 'test-stream-merger:offsetlog', $offsetlog->name() );
		$this->assertSame( $merger, $offsetlog->patron() );
		$this->assertSame( $this->tmp_dir . '/offsets/aggregator.p2', $offsetlog->partition_dir() );

		$values = $this->read_partition_values( $offsetlog );
		$this->assertCount( 1, $values );
		$this->assertSame( [ 'seg' => 3, 'off' => 40 ], $values[0]['site-a'] );
		$this->assertSame( [ 'seg' => 4, 'off' => 50 ], $values[0]['site-b'] );
		$this->assertSame( 1234, $values[0]['_ts'] );
	}

	public function test_commit_all_skips_test_only_remote_key(): void {
		$merger = $this->make_merger();
		$remote = new Remote_Source_Node();
		$remote->configure( '__test__', 'https://test.test', '', '', '', 'firehose', 2 );
		$remote->restore_position( 9, 90 );

		$ref = new \ReflectionProperty( Stream_Merger_Node::class, 'remote_nodes' );
		$ref->setAccessible( true );
		$ref->setValue( $merger, [ '__test__' => $remote ] );

		$merger->commit_all();

		$offsetlog = $this->offsetlog( $merger );
		$this->assertInstanceOf( Partition_Node::class, $offsetlog );
		$this->assertSame( [], $this->read_partition_values( $offsetlog ) );
	}

	public function test_commit_all_noops_when_no_remotes_exist(): void {
		$merger = $this->make_merger();
		$merger->commit_all();

		$this->assertNull( $this->offsetlog( $merger ) );
		$this->assertDirectoryDoesNotExist( $this->tmp_dir . '/offsets/aggregator.p2' );
	}

	public function test_add_remote_restores_latest_committed_position(): void {
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://site-a.test' ) ] );

		$first = $this->make_merger();
		$first->add_remote( 'site-a' );
		$this->remotes( $first )['site-a']->restore_position( 7, 88 );
		Core::$now = 1234.0;
		$first->commit_all();
		$first->remove_node();

		$second = $this->make_merger();
		$second->add_remote( 'site-a' );

		$this->assertSame( [ 'segment_id' => 7, 'offset' => 88 ], $second->get_position( 'site-a' ) );
	}

	public function test_fire_ticks_children_and_commits_after_interval(): void {
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://site-a.test' ) ] );
		$merger = $this->make_merger();
		$merger->add_remote( 'site-a' );
		$this->remotes( $merger )['site-a']->restore_position( 1, 25 );

		Core::$now = 1001.0;
		$merger->fire();
		$offsetlog = $this->offsetlog( $merger );
		$this->assertInstanceOf( Partition_Node::class, $offsetlog );
		$values = $this->read_partition_values( $offsetlog );
		$this->assertSame( [ 'seg' => 1, 'off' => 25 ], $values[0]['site-a'] );

		$this->remotes( $merger )['site-a']->restore_position( 2, 50 );
		Core::$now = 1004.0;
		$merger->fire();
		$this->assertCount( 1, $this->read_partition_values( $offsetlog ) );

		Core::$now = 1006.0;
		$merger->fire();
		$values = $this->read_partition_values( $offsetlog );
		$this->assertCount( 2, $values );
		$this->assertSame( [ 'seg' => 2, 'off' => 50 ], $values[1]['site-a'] );
	}

	public function test_offsetlog_sinks_to_command_interpreter_when_available(): void {
		$ci = $this->command_interpreter();
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://site-a.test' ) ] );
		$merger = $this->make_merger();
		$merger->add_remote( 'site-a' );
		$this->remotes( $merger )['site-a']->restore_position( 1, 1 );

		$merger->commit_all();

		$offsetlog = $this->offsetlog( $merger );
		$this->assertInstanceOf( Partition_Node::class, $offsetlog );
		$this->assertSame( $ci, $offsetlog->sink() );
	}

	public function test_accessors_delegate_to_child_or_return_defaults(): void {
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://site-a.test' ) ] );
		$merger = $this->make_merger();
		$merger->add_remote( 'site-a' );
		$this->remotes( $merger )['site-a']->restore_position( 5, 60 );

		$this->assertInstanceOf( \CurlHandle::class, $merger->test_get_handle( 'site-a' ) );
		$this->assertNull( $merger->get_last_http_code( 'site-a' ) );
		$this->assertNull( $merger->get_last_error( 'site-a' ) );
		$this->assertSame( Remote_Source_Node::INITIAL_BACKOFF, $merger->get_backoff( 'site-a' ) );
		$this->assertNull( $merger->get_slot( 'site-a' ) );
		$this->assertSame( [ 'segment_id' => 5, 'offset' => 60 ], $merger->get_position( 'site-a' ) );

		$this->assertNull( $merger->test_get_handle( 'missing' ) );
		$this->assertNull( $merger->get_last_http_code( 'missing' ) );
		$this->assertNull( $merger->get_last_error( 'missing' ) );
		$this->assertSame( Remote_Source_Node::INITIAL_BACKOFF, $merger->get_backoff( 'missing' ) );
		$this->assertNull( $merger->get_slot( 'missing' ) );
		$this->assertSame( [ 'segment_id' => 0, 'offset' => 0 ], $merger->get_position( 'missing' ) );
	}

	public function test_register_remote_job_rewrite_filter_rewrites_jobs_and_leaves_other_lines(): void {
		Stream_Merger_Node::register_remote_job_rewrite_filter();

		$line = \wp_json_encode( [ 'k' => 'job', 'handler' => 'remote_manager' ] );
		$out  = \apply_filters( 'newspack_nodes/aggregator_ingest_line', $line, 'site-a', 2 );

		$this->assertIsString( $out );
		$decoded = \json_decode( $out, true );
		$this->assertSame( 'remote_job', $decoded['k'] );
		$this->assertSame( 'remote_manager', $decoded['handler'] );

		$request = \wp_json_encode( [ 'k' => 'request', 'url' => '/' ] );
		$this->assertSame( $request, \apply_filters( 'newspack_nodes/aggregator_ingest_line', $request, 'site-a', 2 ) );
		$this->assertSame( 'not-json', \apply_filters( 'newspack_nodes/aggregator_ingest_line', 'not-json', 'site-a', 2 ) );
		$this->assertSame( '', \apply_filters( 'newspack_nodes/aggregator_ingest_line', '', 'site-a', 2 ) );
	}

	public function test_remove_node_tears_down_children_remotes_and_offsetlog(): void {
		$this->seed_registry( [ 'site-a' => $this->make_server( 'https://site-a.test' ) ] );
		$merger = $this->make_merger( 'aggregator' );
		$merger->add_remote( 'site-a' );
		$this->remotes( $merger )['site-a']->restore_position( 1, 1 );
		$merger->commit_all();

		$this->assertNotNull( Core::node( 'aggregator' ) );
		$this->assertNotNull( Core::node( 'aggregator:remote:site-a' ) );
		$this->assertNotNull( Core::node( 'aggregator:offsetlog' ) );

		$merger->remove_node();

		$this->assertSame( [], $this->remotes( $merger ) );
		$this->assertNull( Core::node( 'aggregator' ) );
		$this->assertNull( Core::node( 'aggregator:remote:site-a' ) );
		$this->assertNull( Core::node( 'aggregator:offsetlog' ) );
	}
}
