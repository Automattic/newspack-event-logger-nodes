<?php
/**
 * RemoteSource unit tests.
 *
 * RemoteSource is a single SSE-pulled spoke firehose, modeled as a Node. It
 * owns one cURL multi handle, one cURL easy handle, an in-memory cursor, and
 * an SSE parser. StreamMerger instantiates one RemoteSource per
 * ServerRegistry entry and drives lifecycle via `tick()`.
 *
 * These tests construct RemoteSource directly (without StreamMerger) so the
 * class can be exercised in isolation. The cURL machinery is driven through
 * the public test seams: `process_sse_chunk()` feeds the parser without a
 * live transfer, and `on_curl_data()` / `on_curl_message()` simulate cURL
 * callbacks once a handle has been opened via `maybe_connect()`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Remote_Source_Node;
use Newspack_Event_Logger_Nodes\Tests\Helpers\SseFrameFactory;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Remote_Source_Node::class )]
class RemoteSourceTest extends TestCase {

	use SseFrameFactory;

	protected function setUp(): void {
		parent::setUp();
		Event_Framework::reset();
		// Drop any ingest-filter callbacks left over from previous tests so
		// drain_test_queue() / forward_entry() see a clean filter chain.
		unset( $GLOBALS['_wp_actions']['newspack_nodes/aggregator_ingest_line'] );
		$GLOBALS['_wp_test_remote_posts']     = [];
		$GLOBALS['_wp_test_remote_responses'] = [];
		unset( $GLOBALS['_wp_test_remote_post_response'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wp_actions']['newspack_nodes/aggregator_ingest_line'] );
		Event_Framework::reset();
		parent::tearDown();
	}

	/**
	 * Construct a named RemoteSource with require_https disabled — almost
	 * every test wants the http:// shortcut so a real connect attempt can be
	 * driven via test seams without HTTPS plumbing.
	 */
	private function make_remote( string $server_id = 'siteA', string $url = 'http://siteA.test', int $partition = 0 ): Remote_Source_Node {
		$remote = new Remote_Source_Node( $server_id, $url, '', '', 'tok', 'firehose', $partition );
		$remote->name( "remote:{$server_id}" );
		$remote->set_require_https( false );
		return $remote;
	}

	private function peek( Remote_Source_Node $remote, string $field ): mixed {
		$ref = new \ReflectionProperty( Remote_Source_Node::class, $field );
		$ref->setAccessible( true );
		return $ref->getValue( $remote );
	}

	private function poke( Remote_Source_Node $remote, string $field, mixed $value ): void {
		$ref = new \ReflectionProperty( Remote_Source_Node::class, $field );
		$ref->setAccessible( true );
		$ref->setValue( $remote, $value );
	}

	private function invoke( Remote_Source_Node $remote, string $method, array $args = [] ): mixed {
		$m = new \ReflectionMethod( Remote_Source_Node::class, $method );
		$m->setAccessible( true );
		return $m->invoke( $remote, ...$args );
	}


	// =========================================================================
	// Constants
	// =========================================================================

	public function test_class_constants_match_upstream_contract(): void {
		$this->assertSame( 30, Remote_Source_Node::MAX_BACKOFF );
		$this->assertSame( 1, Remote_Source_Node::INITIAL_BACKOFF );
		$this->assertSame( 5, Remote_Source_Node::CONNECT_TIMEOUT );
		$this->assertSame( 45, Remote_Source_Node::HEARTBEAT_TIMEOUT );
		$this->assertSame( 15, Remote_Source_Node::HEARTBEAT_INTERVAL );
		$this->assertSame( 10485760, Remote_Source_Node::MAX_BUFFER_SIZE );
		$this->assertSame( 10485760, Remote_Source_Node::MAX_EVENT_SIZE );
		$this->assertSame( 10000, Remote_Source_Node::MAX_QUEUE_SIZE );
		$this->assertSame( 3900, Remote_Source_Node::MAX_LINE_BYTES );
		$this->assertSame( 300, Remote_Source_Node::STATUS_TTL );
	}

	// =========================================================================
	// Constructor / configuration
	// =========================================================================

	public function test_constructor_strips_trailing_slash_from_url(): void {
		$remote = new Remote_Source_Node( 'siteA', 'https://siteA.test/', '', '', 'tok', 'firehose', 0 );
		$this->assertSame( 'https://siteA.test', $remote->url() );
	}

	public function test_constructor_stores_server_id(): void {
		$remote = new Remote_Source_Node( 'siteB', 'https://siteB.test', '', '', 'tok', 'firehose', 0 );
		$this->assertSame( 'siteB', $remote->server_id() );
	}

	public function test_constructor_clamps_negative_partition_to_zero(): void {
		$remote = new Remote_Source_Node( 'siteA', 'https://siteA.test', '', '', 'tok', 'firehose', -7 );
		// Negative partition -> 0; we can read it through current_status's URL params,
		// but here just confirm the constructor accepted it and didn't crash.
		$this->assertSame( 'siteA', $remote->server_id() );
		$this->assertSame( 0, $this->peek( $remote, 'partition' ) );
	}

	public function test_constructor_sets_arguments_for_dump_config(): void {
		// `arguments` is set in the ctor so `dump_config()` can round-trip
		// the make_node line. Verify all six positional args are joined,
		// with the two credential slots scrubbed to `[REDACTED]` so a
		// saved topology TSL never contains live passwords.
		$remote = new Remote_Source_Node( 'siteA', 'https://siteA.test', 'admin', 'pw', 'tok', 'firehose', 3 );
		$args   = $remote->arguments();
		$this->assertStringContainsString( 'siteA', $args );
		$this->assertStringContainsString( 'https://siteA.test', $args );
		$this->assertStringContainsString( 'admin', $args );
		$this->assertStringContainsString( '[REDACTED]', $args );
		$this->assertStringContainsString( '3', $args );
		$this->assertStringNotContainsString( 'pw', $args, 'auth_password must not appear in arguments' );
		$this->assertStringNotContainsString( 'tok', $args, 'auth_token must not appear in arguments' );
	}

	public function test_dump_node_redacts_auth_password_and_token(): void {
		// `dump_node my_remote` from the REPL used to print raw passwords
		// because Node::dump_node reflects every property. RemoteSource
		// overrides it to scrub the two credential slots; both must
		// survive in `[REDACTED]` form so the operator can tell whether
		// they were set, but the raw secret must NOT leak.
		$remote   = new Remote_Source_Node( 'siteA', 'https://siteA.test', 'admin', 'secret-pw', 'secret-tok', 'firehose', 0 );
		$snapshot = $remote->dump_node();
		$this->assertSame( '[REDACTED]', $snapshot['auth_password'] );
		$this->assertSame( '[REDACTED]', $snapshot['auth_token'] );
		$encoded = (string) \wp_json_encode( $snapshot );
		$this->assertStringNotContainsString( 'secret-pw', $encoded );
		$this->assertStringNotContainsString( 'secret-tok', $encoded );
	}

	public function test_dump_node_leaves_empty_credentials_alone(): void {
		// Empty-string credentials stay empty (not redacted to "[REDACTED]")
		// so the operator can tell at a glance which auth mode is in use.
		$remote   = new Remote_Source_Node( 'siteA', 'https://siteA.test', '', '', '', 'firehose', 0 );
		$snapshot = $remote->dump_node();
		$this->assertSame( '', $snapshot['auth_password'] );
		$this->assertSame( '', $snapshot['auth_token'] );
	}

	public function test_fill_is_no_op_and_increments_counter(): void {
		// RemoteSource is a *source*; fill() ignores input but increments counter.
		$remote = $this->make_remote();
		$msg    = Message::new_message();
		$before = $remote->counter();
		$remote->fill( $msg );
		$this->assertSame( $before + 1, $remote->counter() );
	}

	public function test_status_write_reaches_shared_memd(): void {
		$remote     = $this->make_remote();
		$cache      = new InMemoryMemcached();
		Core::$memd = $cache;
		// Triggering a status write reaches into the shared Core::$memd handle.
		$this->invoke( $remote, 'update_connection_status', [ 'connecting', null, null, 1 ] );
		$key = 'aggregator_status:siteA:p0';
		$this->assertIsArray( $cache->get( $key ) );
	}

	public function test_status_write_is_noop_when_memd_null(): void {
		// No shared handle → status writes are no-ops. The key invariant: no crash.
		$remote     = $this->make_remote();
		Core::$memd = null;
		$this->invoke( $remote, 'update_connection_status', [ 'connecting', null, null, 1 ] );
		$this->addToAssertionCount( 1 );
	}

	public function test_set_verify_ssl_propagates(): void {
		// We can't easily introspect downstream curl_setopt without a real
		// transfer, but the setter must not crash. Trigger maybe_connect to
		// ensure the option is consumed.
		$remote = $this->make_remote();
		$remote->set_verify_ssl( false );
		// Drive maybe_connect — it must use the new value to set CURLOPT_SSL_*.
		$this->invoke( $remote, 'maybe_connect' );
		$this->addToAssertionCount( 1 );
	}

	public function test_set_require_https_warns_on_disable(): void {
		// Capture stderr to confirm the warning is emitted.
		$captured = [];
		Core::set_stderr_handler( static function ( string $msg ) use ( &$captured ): void {
			$captured[] = $msg;
		} );

		$remote = new Remote_Source_Node( 'siteA', 'https://siteA.test', '', '', 'tok', 'firehose', 0 );
		$remote->set_require_https( false );

		$concat = \implode( ' ', $captured );
		$this->assertStringContainsString( 'require_https=false', $concat );
	}

	public function test_set_require_https_re_disabling_does_not_re_warn(): void {
		// Disable twice — only the first call should print the warning.
		$captured = [];
		Core::set_stderr_handler( static function ( string $msg ) use ( &$captured ): void {
			$captured[] = $msg;
		} );

		$remote = new Remote_Source_Node( 'siteA', 'https://siteA.test', '', '', 'tok', 'firehose', 0 );
		$remote->set_require_https( false );
		$first_count = \count( $captured );
		$remote->set_require_https( false );
		$this->assertCount( $first_count, $captured );
	}

	// =========================================================================
	// Position state
	// =========================================================================

	public function test_position_default_is_zero(): void {
		$remote = $this->make_remote();
		$this->assertSame( [ 'segment_id' => 0, 'offset' => 0 ], $remote->position() );
	}

	public function test_restore_position_stores_segment_and_offset(): void {
		$remote = $this->make_remote();
		$remote->restore_position( 5, 200 );
		$this->assertSame( [ 'segment_id' => 5, 'offset' => 200 ], $remote->position() );
	}

	public function test_restore_position_clamps_negative_to_zero(): void {
		$remote = $this->make_remote();
		$remote->restore_position( -3, -100 );
		$this->assertSame( [ 'segment_id' => 0, 'offset' => 0 ], $remote->position() );
	}

	// =========================================================================
	// current_status
	// =========================================================================

	public function test_current_status_returns_full_snapshot(): void {
		$remote = $this->make_remote();
		Core::$now = 1000.0;

		$status = $remote->current_status();
		$this->assertArrayHasKey( 'connected', $status );
		$this->assertArrayHasKey( 'last_error', $status );
		$this->assertArrayHasKey( 'last_http_code', $status );
		$this->assertArrayHasKey( 'position', $status );
		$this->assertArrayHasKey( 'last_event_age_s', $status );
		$this->assertArrayHasKey( 'current_backoff', $status );
		$this->assertArrayHasKey( 'slot', $status );
		// Fresh remote: not connected, no error, no http code, no slot.
		$this->assertFalse( $status['connected'] );
		$this->assertNull( $status['last_error'] );
		$this->assertNull( $status['last_http_code'] );
		$this->assertNull( $status['slot'] );
		$this->assertSame( [ 'segment_id' => 0, 'offset' => 0 ], $status['position'] );
		$this->assertSame( Remote_Source_Node::INITIAL_BACKOFF, $status['current_backoff'] );
		// last_event_time defaults to 0.0, so age is null.
		$this->assertNull( $status['last_event_age_s'] );
	}

	public function test_current_status_computes_last_event_age(): void {
		$remote = $this->make_remote();
		// Seed last_event_time to 100s ago.
		Core::$now = 1000.0;
		$this->poke( $remote, 'last_event_time', 900.0 );
		$status = $remote->current_status();
		$this->assertSame( 100, $status['last_event_age_s'] );
	}

	// =========================================================================
	// Connection lifecycle (maybe_connect)
	// =========================================================================

	public function test_maybe_connect_refuses_non_https_when_required(): void {
		Core::$now = 1000.0;
		// Default require_https=true and a plain http:// URL.
		$remote = new Remote_Source_Node( 'insecure', 'http://insecure.test', '', '', 'tok', 'firehose', 0 );
		$opened = $this->invoke( $remote, 'maybe_connect' );

		$this->assertFalse( $opened );
		$this->assertSame( 'refusing non-HTTPS URL', $remote->get_last_error() );
		$this->assertNull( $remote->test_get_handle() );
		// Backoff was bumped.
		$this->assertSame( 2, $remote->get_backoff() );
	}

	public function test_maybe_connect_opens_handle_when_eligible(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$opened = $this->invoke( $remote, 'maybe_connect' );

		$this->assertTrue( $opened );
		$this->assertNotNull( $remote->test_get_handle() );
		$this->assertTrue( $remote->is_connected() );
	}

	public function test_maybe_connect_no_op_when_already_connected(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$first = $remote->test_get_handle();
		$this->assertNotNull( $first );

		$opened_again = $this->invoke( $remote, 'maybe_connect' );
		$this->assertFalse( $opened_again );
		$this->assertSame( $first, $remote->test_get_handle() );
	}

	public function test_maybe_connect_respects_backoff_window(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->poke( $remote, 'last_attempt', 999.5 );
		$this->poke( $remote, 'current_backoff', 5 );

		$opened = $this->invoke( $remote, 'maybe_connect' );
		$this->assertFalse( $opened, 'attempt within backoff window must be denied' );
		$this->assertNull( $remote->test_get_handle() );
	}

	public function test_maybe_connect_reattempts_after_backoff_window(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->poke( $remote, 'last_attempt', 990.0 );
		$this->poke( $remote, 'current_backoff', 5 );

		Core::$now = 1010.0; // 20s after last_attempt > backoff=5
		$opened = $this->invoke( $remote, 'maybe_connect' );
		$this->assertTrue( $opened );
	}

	public function test_maybe_connect_with_basic_auth_sets_authorization_header(): void {
		Core::$now = 1000.0;
		// We can't introspect cURL headers directly without a real transfer,
		// but we can verify the path runs cleanly with credentials.
		$remote = new Remote_Source_Node( 'siteAuth', 'http://siteAuth.test', 'admin', 'pw', '', 'firehose', 0 );
		$remote->set_require_https( false );
		$opened = $this->invoke( $remote, 'maybe_connect' );
		$this->assertTrue( $opened );
	}

	public function test_maybe_connect_with_bearer_token_only(): void {
		Core::$now = 1000.0;
		$remote = new Remote_Source_Node( 'siteTok', 'http://siteTok.test', '', '', 'tok-only', 'firehose', 0 );
		$remote->set_require_https( false );
		$opened = $this->invoke( $remote, 'maybe_connect' );
		$this->assertTrue( $opened );
	}

	public function test_maybe_connect_includes_position_when_nonzero(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$remote->restore_position( 7, 250 );
		// Just verify maybe_connect proceeds when position is non-default; the
		// segment_id/offset show up as URL params on the actual endpoint.
		$opened = $this->invoke( $remote, 'maybe_connect' );
		$this->assertTrue( $opened );
	}

	public function test_maybe_connect_resets_per_connection_state(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		// Pre-populate stale buffer / events.
		$this->poke( $remote, 'buffer', 'old garbage' );
		$this->poke( $remote, 'current_event', [ 'event' => 'leftover', 'data' => 'stuff' ] );

		$this->invoke( $remote, 'maybe_connect' );
		$this->assertSame( '', $this->peek( $remote, 'buffer' ) );
		$this->assertSame( [ 'event' => '', 'data' => '' ], $this->peek( $remote, 'current_event' ) );
	}

	public function test_disconnect_closes_handle_and_updates_status(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$this->assertNotNull( $remote->test_get_handle() );

		$remote->disconnect();
		$this->assertNull( $remote->test_get_handle() );
		$this->assertFalse( $remote->is_connected() );
	}

	public function test_disconnect_idempotent_when_already_disconnected(): void {
		$remote = $this->make_remote();
		// Disconnect on a fresh remote — must not crash.
		$remote->disconnect();
		$remote->disconnect();
		$this->assertNull( $remote->test_get_handle() );
	}

	// =========================================================================
	// cURL multi handle lifecycle
	// =========================================================================

	public function test_ensure_multi_idempotent(): void {
		$remote = $this->make_remote();
		$this->invoke( $remote, 'ensure_multi' );
		$first = $this->peek( $remote, 'multi' );
		$this->assertNotNull( $first );
		// Second call must not replace the handle.
		$this->invoke( $remote, 'ensure_multi' );
		$this->assertSame( $first, $this->peek( $remote, 'multi' ) );
	}

	public function test_remove_node_closes_curl_multi_and_unregisters(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$this->assertNotNull( $remote->test_get_handle() );
		$this->assertNotNull( $this->peek( $remote, 'multi' ) );

		$remote->remove_node();

		$this->assertNull( $remote->test_get_handle() );
		$this->assertNull( $this->peek( $remote, 'multi' ) );
	}

	public function test_remove_node_safe_without_multi(): void {
		// remove_node on a remote that never connected — no multi to close.
		$remote = $this->make_remote();
		$remote->remove_node();
		$this->assertNull( $this->peek( $remote, 'multi' ) );
	}

	// =========================================================================
	// on_curl_message
	// =========================================================================

	public function test_on_curl_message_curl_error_classifies_disconnect(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$handle = $remote->test_get_handle();
		$this->assertNotNull( $handle );

		$remote->on_curl_message( [
			'msg'    => \CURLMSG_DONE,
			'result' => \CURLE_COULDNT_CONNECT,
			'handle' => $handle,
		] );

		$this->assertNull( $remote->test_get_handle() );
		$this->assertStringContainsString( 'cURL error', (string) $remote->get_last_error() );
		// Backoff doubled from 1 to 2.
		$this->assertSame( 2, $remote->get_backoff() );
	}

	public function test_on_curl_message_clean_close_records_default_error(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$handle = $remote->test_get_handle();

		$remote->on_curl_message( [
			'msg'    => \CURLMSG_DONE,
			'result' => \CURLE_OK,
			'handle' => $handle,
		] );

		$this->assertSame( 'Connection closed by server', $remote->get_last_error() );
	}

	public function test_on_curl_message_ignored_for_non_done_messages(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$handle = $remote->test_get_handle();

		$remote->on_curl_message( [
			'msg'    => 999, // not CURLMSG_DONE
			'result' => \CURLE_OK,
			'handle' => $handle,
		] );

		$this->assertSame( $handle, $remote->test_get_handle(), 'non-DONE msg must not tear down handle' );
	}

	public function test_on_curl_message_unknown_handle_cleaned_up(): void {
		// CURLMSG_DONE for a foreign handle — best-effort cleanup, no crash.
		$remote = $this->make_remote();
		$rogue  = \curl_init();

		$remote->on_curl_message( [
			'msg'    => \CURLMSG_DONE,
			'result' => \CURLE_OK,
			'handle' => $rogue,
		] );
		$this->addToAssertionCount( 1 );
	}

	public function test_on_curl_message_handles_missing_handle_in_info(): void {
		// info array with no 'handle' key — must short-circuit, not crash.
		$remote = $this->make_remote();
		$remote->on_curl_message( [
			'msg'    => \CURLMSG_DONE,
			'result' => \CURLE_OK,
		] );
		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// SSE parser — process_sse_chunk
	// =========================================================================

	public function test_process_sse_chunk_concatenates_multiline_data(): void {
		// Multi-line `data:` fields are joined with "\n" inside the parser;
		// JSON tolerates whitespace between tokens, so a JSON object split
		// across multiple lines (at pretty-print boundaries — whitespace is
		// legal inside the envelope array) parses back identically.
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$envelope = \json_encode(
			[
				Message::TM_STRUCT,
				1700000000.0,
				'firehose.p0',
				'',
				'0:0',
				'',
				[ 'k' => 'render', 'ts' => 1700000000 ],
			],
			\JSON_PRETTY_PRINT
		);
		// Prefix each line with `data: ` so the parser sees them as
		// continuation fields, then terminate with a blank line.
		$wire = "event: msg\n";
		foreach ( \explode( "\n", $envelope ) as $line ) {
			$wire .= "data: {$line}\n";
		}
		$wire .= "\n";
		$remote->process_sse_chunk( $wire );
		$this->assertCount( 1, $capture->captured );
		$this->assertSame( 'render', $capture->captured[0][ Message::VALUE ]['k'] );
	}

	public function test_process_sse_chunk_handles_partial_chunks(): void {
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$wire    = $this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000 ] );
		$cut     = (int) ( \strlen( $wire ) / 2 );
		// First half: no terminator yet — sink still empty.
		$remote->process_sse_chunk( \substr( $wire, 0, $cut ) );
		$this->assertCount( 0, $capture->captured );
		// Second half completes the event.
		$remote->process_sse_chunk( \substr( $wire, $cut ) );
		$this->assertCount( 1, $capture->captured );
	}

	public function test_process_sse_chunk_strips_carriage_return(): void {
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$wire = $this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000 ] );
		// Inject CRs in the SSE framing — the parser must rtrim them per spec.
		$wire = \str_replace( "\n", "\r\n", $wire );
		$remote->process_sse_chunk( $wire );
		$this->assertCount( 1, $capture->captured );
	}

	public function test_process_sse_chunk_ignores_comment_lines(): void {
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$remote->process_sse_chunk(
			": keepalive\n: another comment\n"
			. $this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000 ] )
		);
		$this->assertCount( 1, $capture->captured );
	}

	public function test_process_sse_chunk_field_without_leading_space(): void {
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		// Drop the spaces after `event:` and `data:` (legal per SSE spec).
		$wire = $this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000 ] );
		$wire = \str_replace( [ 'event: ', 'data: ' ], [ 'event:', 'data:' ], $wire );
		$remote->process_sse_chunk( $wire );
		$this->assertCount( 1, $capture->captured );
	}

	public function test_process_sse_chunk_unknown_fields_ignored(): void {
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$remote->process_sse_chunk(
			"id: 123\nretry: 5000\n"
			. $this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000 ] )
		);
		$this->assertCount( 1, $capture->captured );
	}

	public function test_process_sse_chunk_buffer_overflow_returns_false(): void {
		$remote = $this->make_remote();
		// Feed > MAX_BUFFER_SIZE bytes with no newline.
		$big   = \str_repeat( 'x', Remote_Source_Node::MAX_BUFFER_SIZE + 1 );
		$ok    = $remote->process_sse_chunk( $big );
		$this->assertFalse( $ok );
		$this->assertStringContainsString( 'Buffer overflow', (string) $remote->get_last_error() );
	}

	public function test_process_sse_chunk_event_overflow_returns_false(): void {
		$remote = $this->make_remote();
		// One data: line whose concatenated content exceeds MAX_EVENT_SIZE. Feed
		// the data in two chunks separated by a newline so the parser commits
		// the first into current_event['data'], then the second tips it past
		// the cap. Using two halves around MAX_EVENT_SIZE/2 keeps memory bounded
		// to ~20MB total transient.
		$half  = (int) ( Remote_Source_Node::MAX_EVENT_SIZE / 2 ) + 100;
		$chunk = 'data: ' . \str_repeat( 'A', $half ) . "\n";
		$remote->process_sse_chunk( $chunk );
		$ok = $remote->process_sse_chunk( $chunk );
		$this->assertFalse( $ok );
		$this->assertStringContainsString( 'overflow', (string) $remote->get_last_error() );
	}

	public function test_process_sse_chunk_empty_returns_true(): void {
		// Empty input is a valid no-op.
		$remote = $this->make_remote();
		$this->assertTrue( $remote->process_sse_chunk( '' ) );
	}

	public function test_process_sse_chunk_empty_event_block_dropped(): void {
		// `\n\n` with no preceding field — empty dispatch, sink never called.
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$remote->process_sse_chunk( "\n\n" );
		$this->assertCount( 0, $capture->captured );
	}

	// =========================================================================
	// dispatch_event paths — happy paths in the `test_msg_envelope_*` block
	// below; tests here cover orthogonal edge / error behaviors.
	// =========================================================================

	public function test_connected_event_resets_backoff(): void {
		// Set backoff above default; verify a connected envelope drops it
		// back to INITIAL_BACKOFF via record_successful_heartbeat().
		$remote = $this->make_remote();
		$this->poke( $remote, 'current_backoff', 16 );
		$envelope = [
			Message::TM_INFO, 0.0, '_stream', '', '', 'connected',
			[ 'pid' => 1, 'slot' => 0, 'subscriptions' => [ 'firehose.p0' ], 'interval' => 500 ],
		];
		$remote->process_sse_chunk( "event: msg\ndata: " . \json_encode( $envelope ) . "\n\n" );
		$this->assertSame( Remote_Source_Node::INITIAL_BACKOFF, $remote->get_backoff() );
	}

	public function test_malformed_msg_envelope_dropped_silently(): void {
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		// Invalid JSON in a `msg` event — dispatch_event drops it; sink unchanged.
		$remote->process_sse_chunk( "event: msg\ndata: not-json-at-all\n\n" );
		$this->assertCount( 0, $capture->captured );
	}

	public function test_heartbeat_event_records_server_sse_heartbeat(): void {
		// The spoke's messages-stream emits periodic `heartbeat` SSE events;
		// receiving one records last_sse_heartbeat so the aggregator dashboard's
		// "Server HB" reflects spoke liveness (previously dropped → always "–").
		$cache  = new InMemoryMemcached();
		$remote = $this->make_remote();
		Core::$memd = $cache;
		Core::$now = 1234.0;

		$remote->process_sse_chunk( "event: heartbeat\ndata: {\"ts\":1234}\n\n" );

		$status = $cache->get( 'aggregator_status:siteA:p0' );
		$this->assertIsArray( $status );
		$this->assertSame( 1234, $status['last_sse_heartbeat'] );
	}

	// =========================================================================
	// forward_entry — drop / clip / filter paths. The happy-path sink
	// assertion lives in `test_msg_envelope_with_entry_value_forwards_to_sink`.
	// =========================================================================

	public function test_forward_envelope_passes_through_arbitrary_dict_shape(): void {
		// RemoteSource is generic cross-server transport: it forwards
		// whatever the spoke publishes. No firehose-shape validation
		// (`k` / `ts` required), no rid back-fill — those concerns live
		// in downstream nodes. A spoke could publish gyroscope.log or
		// completed.log payloads and they should pass through identically.
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$envelope = [
			Message::TM_STRUCT, 1700000000.0, 'firehose.p0', '', '0:0', 'rid-1',
			// Deliberately NOT firehose-shape — no `k`, no `ts`.
			[ 'arbitrary' => 'payload', 'state' => 'whatever' ],
		];
		$remote->process_sse_chunk( "event: msg\ndata: " . \json_encode( $envelope ) . "\n\n" );

		$this->assertCount( 1, $capture->captured );
		$msg = $capture->captured[0];
		$this->assertSame( 'rid-1', $msg[ Message::KEY ], 'KEY preserved from envelope verbatim' );
		$this->assertSame( 'payload', $msg[ Message::VALUE ]['arbitrary'] );
		$this->assertSame( 'siteA', $msg[ Message::VALUE ]['_source'], 'hub-side attribution stamped onto dict VALUE' );
	}

	public function test_forward_entry_drops_oversized_line(): void {
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$big_field = \str_repeat( 'A', Remote_Source_Node::MAX_LINE_BYTES + 100 );
		$remote->process_sse_chunk(
			$this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000, 'big' => $big_field ] )
		);
		$this->assertCount( 0, $capture->captured );
	}

	public function test_forward_entry_filter_drop_with_null_return(): void {
		\add_filter( 'newspack_nodes/aggregator_ingest_line', static fn () => null );
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$remote->process_sse_chunk( $this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000 ] ) );
		$this->assertCount( 0, $capture->captured );
	}

	public function test_forward_entry_filter_drop_with_false_return(): void {
		\add_filter( 'newspack_nodes/aggregator_ingest_line', static fn () => false );
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$remote->process_sse_chunk( $this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000 ] ) );
		$this->assertCount( 0, $capture->captured );
	}

	public function test_forward_entry_filter_drop_with_empty_string_return(): void {
		\add_filter( 'newspack_nodes/aggregator_ingest_line', static fn () => '' );
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$remote->process_sse_chunk( $this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000 ] ) );
		$this->assertCount( 0, $capture->captured );
	}

	public function test_forward_entry_filter_drop_with_non_string_return(): void {
		// Filter returns an array — silent drop (forward_entry only accepts strings).
		\add_filter( 'newspack_nodes/aggregator_ingest_line', static fn () => [ 'not', 'a', 'string' ] );
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$remote->process_sse_chunk( $this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000 ] ) );
		$this->assertCount( 0, $capture->captured );
	}

	public function test_forward_entry_post_filter_oversize_dropped(): void {
		// Filter inflates the line past MAX_LINE_BYTES post-stamp.
		\add_filter(
			'newspack_nodes/aggregator_ingest_line',
			static fn ( $line ) => $line . \str_repeat( 'B', Remote_Source_Node::MAX_LINE_BYTES )
		);
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$remote->process_sse_chunk(
			$this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000, 'url' => '/x' ] )
		);
		$this->assertCount( 0, $capture->captured );
	}

	public function test_forward_entry_receives_three_arg_filter_signature(): void {
		$captured_args = [];
		\add_filter(
			'newspack_nodes/aggregator_ingest_line',
			static function ( $line, $server_id, $partition ) use ( &$captured_args ): string {
				$captured_args = [ $line, $server_id, $partition ];
				return (string) $line;
			}
		);

		$remote = new Remote_Source_Node( 'siteX', 'http://siteX.test', '', '', 'tok', 'firehose', 4 );
		$remote->name( 'remote:siteX' );
		$remote->set_require_https( false );
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );
		$remote->process_sse_chunk( $this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000 ] ) );

		$this->assertCount( 3, $captured_args );
		$this->assertIsString( $captured_args[0] );
		$this->assertSame( 'siteX', $captured_args[1] );
		$this->assertSame( 4, $captured_args[2] );
	}

	public function test_forward_entry_filter_drops_post_filter_non_array_decode(): void {
		// Filter returns a non-JSON-array string -> json_decode returns non-array -> drop.
		\add_filter( 'newspack_nodes/aggregator_ingest_line', static fn () => '"plain string"' );

		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );

		$remote->process_sse_chunk( $this->entry_frame( [ 'k' => 'render', 'ts' => 1700000000 ] ) );

		$this->assertCount( 0, $capture->captured );
	}

	// =========================================================================
	// on_curl_data
	// =========================================================================

	public function test_on_curl_data_returns_byte_count(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$handle = $remote->test_get_handle();

		$bytes = "data: hi\n\n";
		$ret   = $remote->on_curl_data( $handle, $bytes );
		$this->assertSame( \strlen( $bytes ), $ret );
	}

	public function test_on_curl_data_returns_zero_on_overflow(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$handle = $remote->test_get_handle();

		// Buffer overflow returns 0 — cURL aborts.
		$ret = $remote->on_curl_data( $handle, \str_repeat( 'x', Remote_Source_Node::MAX_BUFFER_SIZE + 1 ) );
		$this->assertSame( 0, $ret );
	}

	public function test_on_curl_data_returns_byte_count_on_empty(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$handle = $remote->test_get_handle();

		// Zero-byte input: per CURL contract returns 0 (which is also strlen('')).
		$ret = $remote->on_curl_data( $handle, '' );
		$this->assertSame( 0, $ret );
	}

	public function test_on_curl_data_returns_byte_count_for_stale_handle(): void {
		// Foreign handle — must short-circuit returning byte-count so cURL
		// doesn't abort on its own transfer machinery.
		$remote = $this->make_remote();
		$foreign = \curl_init();
		$ret     = $remote->on_curl_data( $foreign, 'some-bytes' );
		$this->assertSame( \strlen( 'some-bytes' ), $ret );
	}

	// =========================================================================
	// tick (the orchestrator entry point)
	// =========================================================================

	public function test_tick_idempotent_when_already_connected(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$first = $remote->test_get_handle();

		$remote->tick();
		$this->assertSame( $first, $remote->test_get_handle() );
	}

	public function test_tick_opens_handle_on_first_call(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->assertNull( $remote->test_get_handle() );
		$remote->tick();
		$this->assertNotNull( $remote->test_get_handle() );
	}

	public function test_tick_kills_stale_connection(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		// Force last_event_time far in the past.
		$this->poke( $remote, 'last_event_time', 1000.0 - Remote_Source_Node::HEARTBEAT_TIMEOUT - 10 );

		$first = $remote->test_get_handle();
		$this->assertNotNull( $first );

		// Advance time past stale threshold AND past the backoff window so the
		// next tick reconnects with a fresh handle.
		Core::$now = 1000.0 + 1; // negligible bump — last_event_time is already stale
		$remote->tick();
		// At least the stale handle must be replaced (either with a new handle
		// after the immediate reconnect or null if backoff prevents it).
		$this->assertNotSame( $first, $remote->test_get_handle(), 'stale handle must be replaced' );
	}

	// =========================================================================
	// Backoff math
	// =========================================================================

	public function test_increase_backoff_doubles_up_to_max(): void {
		$remote = $this->make_remote();
		$this->assertSame( 1, $remote->get_backoff() );
		$this->invoke( $remote, 'increase_backoff' );
		$this->assertSame( 2, $remote->get_backoff() );
		$this->invoke( $remote, 'increase_backoff' );
		$this->assertSame( 4, $remote->get_backoff() );
		$this->invoke( $remote, 'increase_backoff' );
		$this->assertSame( 8, $remote->get_backoff() );
		$this->invoke( $remote, 'increase_backoff' );
		$this->assertSame( 16, $remote->get_backoff() );
		$this->invoke( $remote, 'increase_backoff' );
		$this->assertSame( Remote_Source_Node::MAX_BACKOFF, $remote->get_backoff() );
		// Further increases capped.
		$this->invoke( $remote, 'increase_backoff' );
		$this->assertSame( Remote_Source_Node::MAX_BACKOFF, $remote->get_backoff() );
	}

	// =========================================================================
	// Memcache status writers (cache-down + happy path)
	// =========================================================================

	public function test_update_connection_status_writes_partial_to_cache(): void {
		$cache  = new InMemoryMemcached();
		$remote = $this->make_remote();
		Core::$memd = $cache;
		Core::$now = 1000.0;

		$this->invoke( $remote, 'update_connection_status', [ 'connecting', null, null, 2 ] );

		$status = $cache->get( 'aggregator_status:siteA:p0' );
		$this->assertIsArray( $status );
		$this->assertSame( 'connecting', $status['last_connection_status'] );
		$this->assertSame( 2, $status['current_backoff'] );
		$this->assertSame( 1000, $status['last_connection_attempt'] );
	}

	public function test_update_connection_status_merges_with_existing_entry(): void {
		$cache  = new InMemoryMemcached();
		$remote = $this->make_remote();
		Core::$memd = $cache;
		Core::$now = 1000.0;

		// First write sets some fields.
		$this->invoke( $remote, 'update_connection_status', [ 'connecting', null, null, 1 ] );
		// Second write should preserve previous fields not overwritten.
		$this->invoke( $remote, 'update_connection_status', [ 'connected', 200, null, null ] );

		$status = $cache->get( 'aggregator_status:siteA:p0' );
		$this->assertSame( 'connected', $status['last_connection_status'] );
		$this->assertSame( 200, $status['last_connection_response'] );
		// current_backoff from first call must survive.
		$this->assertSame( 1, $status['current_backoff'] );
	}

	public function test_update_connection_status_no_cache_is_noop(): void {
		// Failing cache → no write attempted.
				$remote  = $this->make_remote();
		Core::$memd = null;
		$this->invoke( $remote, 'update_connection_status', [ 'connecting', null, null, 1 ] );
		$this->addToAssertionCount( 1 );
	}

	public function test_record_successful_heartbeat_writes_cache(): void {
		$cache  = new InMemoryMemcached();
		$remote = $this->make_remote();
		Core::$memd = $cache;
		Core::$now = 1000.0;

		$this->invoke( $remote, 'record_successful_heartbeat' );
		$status = $cache->get( 'aggregator_status:siteA:p0' );
		$this->assertSame( 'success', $status['last_heartbeat_response_status'] );
		$this->assertSame( 1000, $status['last_heartbeat_sent'] );
		$this->assertNull( $status['last_heartbeat_error'] );
	}

	public function test_record_successful_heartbeat_no_cache_noop(): void {
				$remote  = $this->make_remote();
		Core::$memd = null;
		$this->invoke( $remote, 'record_successful_heartbeat' );
		$this->addToAssertionCount( 1 );
	}

	public function test_clear_heartbeat_status_zeros_fields(): void {
		$cache  = new InMemoryMemcached();
		$remote = $this->make_remote();
		Core::$memd = $cache;

		// Pre-seed some fields.
		$this->invoke( $remote, 'record_successful_heartbeat' );
		$this->invoke( $remote, 'clear_heartbeat_status' );

		$status = $cache->get( 'aggregator_status:siteA:p0' );
		$this->assertNull( $status['last_heartbeat_sent'] );
		$this->assertNull( $status['last_heartbeat_response'] );
		$this->assertNull( $status['last_heartbeat_rtt'] );
		$this->assertSame( 'pending', $status['last_heartbeat_response_status'] );
		$this->assertNull( $status['last_sse_heartbeat'] );
	}

	public function test_clear_heartbeat_status_no_cache_noop(): void {
				$remote  = $this->make_remote();
		Core::$memd = null;
		$this->invoke( $remote, 'clear_heartbeat_status' );
		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// update_heartbeat_status — classifies wp_remote_post responses
	// =========================================================================

	public function test_update_heartbeat_status_success_response(): void {
		$cache  = new InMemoryMemcached();
		$remote = $this->make_remote();
		Core::$memd = $cache;
		Core::$now = 1000.0;

		$response = [
			'response' => [ 'code' => 200 ],
			'body'     => \json_encode( [ 'success' => true ] ),
		];
		$this->invoke( $remote, 'update_heartbeat_status', [ $response, 12.5, 999 ] );

		$status = $cache->get( 'aggregator_status:siteA:p0' );
		$this->assertSame( 'success', $status['last_heartbeat_response_status'] );
		$this->assertSame( 12.5, $status['last_heartbeat_rtt'] );
		$this->assertNull( $status['last_heartbeat_error'] );
		$this->assertSame( 999, $status['last_heartbeat_sent'] );
	}

	public function test_update_heartbeat_status_wp_error_response(): void {
		$cache  = new InMemoryMemcached();
		$remote = $this->make_remote();
		Core::$memd = $cache;
		Core::$now = 1000.0;

		$wpe = new \WP_Error( 'timeout', 'Connection timed out' );
		$this->invoke( $remote, 'update_heartbeat_status', [ $wpe, 5000.0, 999 ] );

		$status = $cache->get( 'aggregator_status:siteA:p0' );
		$this->assertSame( 'error', $status['last_heartbeat_response_status'] );
		$this->assertSame( 'Connection timed out', $status['last_heartbeat_error'] );
	}

	public function test_update_heartbeat_status_http_error_code(): void {
		$cache  = new InMemoryMemcached();
		$remote = $this->make_remote();
		Core::$memd = $cache;
		Core::$now = 1000.0;

		$response = [
			'response' => [ 'code' => 500 ],
			'body'     => 'Internal Server Error',
		];
		$this->invoke( $remote, 'update_heartbeat_status', [ $response, 50.0, 999 ] );

		$status = $cache->get( 'aggregator_status:siteA:p0' );
		$this->assertSame( 'error', $status['last_heartbeat_response_status'] );
		$this->assertSame( 'HTTP 500', $status['last_heartbeat_error'] );
	}

	public function test_update_heartbeat_status_slot_expired(): void {
		$cache  = new InMemoryMemcached();
		$remote = $this->make_remote();
		Core::$memd = $cache;
		Core::$now = 1000.0;

		$response = [
			'response' => [ 'code' => 200 ],
			'body'     => \json_encode( [ 'success' => false, 'error' => 'Slot not found' ] ),
		];
		$this->invoke( $remote, 'update_heartbeat_status', [ $response, 25.0, 999 ] );

		$status = $cache->get( 'aggregator_status:siteA:p0' );
		$this->assertSame( 'slot_expired', $status['last_heartbeat_response_status'] );
		$this->assertSame( 'Slot not found', $status['last_heartbeat_error'] );
	}

	public function test_update_heartbeat_status_unexpected_response_shape(): void {
		$cache  = new InMemoryMemcached();
		$remote = $this->make_remote();
		Core::$memd = $cache;
		Core::$now = 1000.0;

		$this->invoke( $remote, 'update_heartbeat_status', [ 'plain string', 0.0, 999 ] );
		$status = $cache->get( 'aggregator_status:siteA:p0' );
		$this->assertSame( 'error', $status['last_heartbeat_response_status'] );
		$this->assertStringContainsString( 'Unexpected', $status['last_heartbeat_error'] );
	}

	public function test_update_heartbeat_status_no_cache_noop(): void {
				$remote  = $this->make_remote();
		Core::$memd = null;
		Core::$now = 1000.0;

		$this->invoke(
			$remote,
			'update_heartbeat_status',
			[ [ 'response' => [ 'code' => 200 ], 'body' => '' ], 0.0, 999 ]
		);
		$this->addToAssertionCount( 1 );
	}

	// =========================================================================
	// maybe_send_heartbeat — POST via wp_remote_post
	// =========================================================================

	public function test_maybe_send_heartbeat_skipped_when_disconnected(): void {
		$remote = $this->make_remote();
		$this->poke( $remote, 'connected', false );
		$this->poke( $remote, 'slot', 3 );
		$this->poke( $remote, 'last_heartbeat', 0 );

		Core::$now = 1000.0;
		$this->invoke( $remote, 'maybe_send_heartbeat' );

		// No outbound POST.
		$this->assertEmpty( $GLOBALS['_wp_test_remote_posts'] );
		// last_heartbeat NOT updated.
		$this->assertSame( 0, $this->peek( $remote, 'last_heartbeat' ) );
	}

	public function test_maybe_send_heartbeat_skipped_when_no_slot(): void {
		$remote = $this->make_remote();
		$this->poke( $remote, 'connected', true );
		$this->poke( $remote, 'slot', null );
		$this->poke( $remote, 'last_heartbeat', 0 );

		Core::$now = 1000.0;
		$this->invoke( $remote, 'maybe_send_heartbeat' );

		$this->assertEmpty( $GLOBALS['_wp_test_remote_posts'] );
		$this->assertSame( 0, $this->peek( $remote, 'last_heartbeat' ) );
	}

	public function test_maybe_send_heartbeat_skipped_when_recent(): void {
		$remote = $this->make_remote();
		$this->poke( $remote, 'connected', true );
		$this->poke( $remote, 'slot', 0 );
		$this->poke( $remote, 'last_heartbeat', 1000 );

		Core::$now = 1005.0; // 5s after — under HEARTBEAT_INTERVAL=15s
		$this->invoke( $remote, 'maybe_send_heartbeat' );

		$this->assertEmpty( $GLOBALS['_wp_test_remote_posts'] );
		$this->assertSame( 1000, $this->peek( $remote, 'last_heartbeat' ) );
	}

	public function test_maybe_send_heartbeat_refuses_non_https_when_required(): void {
		// require_https=true + http:// URL: the heartbeat endpoint check fails.
		$cache = new InMemoryMemcached();
		$remote = new Remote_Source_Node( 'http-remote', 'http://insecure.test', '', '', 'tok', 'firehose', 0 );
		$remote->name( 'remote:http-remote' );
		$remote->set_require_https( true );
		Core::$memd = $cache;
		$this->poke( $remote, 'connected', true );
		$this->poke( $remote, 'slot', 5 );

		Core::$now = 1000.0;
		$this->invoke( $remote, 'maybe_send_heartbeat' );

		$this->assertSame( 'heartbeat endpoint not HTTPS', $remote->get_last_error() );
		$this->assertEmpty( $GLOBALS['_wp_test_remote_posts'] );
	}

	public function test_maybe_send_heartbeat_posts_with_basic_auth(): void {
		$remote = new Remote_Source_Node( 'siteHB', 'http://siteHB.test', 'admin', 'pw', '', 'firehose', 0 );
		$remote->name( 'remote:siteHB' );
		$remote->set_require_https( false );
		$this->poke( $remote, 'connected', true );
		$this->poke( $remote, 'slot', 2 );
		$this->poke( $remote, 'last_heartbeat', 0 );

		Core::$now = 1000.0;
		$this->invoke( $remote, 'maybe_send_heartbeat' );

		$this->assertCount( 1, $GLOBALS['_wp_test_remote_posts'] );
		$post = $GLOBALS['_wp_test_remote_posts'][0];
		$this->assertStringContainsString( '/wp-json/newspack-nodes/v1/command', $post['url'] );
		$auth = $post['args']['headers']['Authorization'] ?? '';
		$this->assertStringStartsWith( 'Basic ', $auth );
		// Body is a single packed Message (positional 7-field array)
		// dispatching workers.heartbeat — NOT the legacy keyed
		// `{type,to,from,value:"<json>"}` object. Content-Type is text/plain
		// because the body is JSONL (WP REST 400s a JSONL application/json
		// body). VALUE is the structured command LIVE array; slot + partition
		// + ttl ride in the verb's `payload` field (Tachikoma contract:
		// arguments is the literal CLI tail, payload is for structured data).
		$this->assertSame( 'text/plain; charset=UTF-8', $post['args']['headers']['Content-Type'] ?? '' );
		$message = Message::unpacked( $post['args']['body'] );
		$this->assertSame( Message::TM_COMMAND, $message[ Message::TYPE ] );
		$this->assertSame( '_http', $message[ Message::FROM ] );
		$this->assertSame( 'workers', $message[ Message::TO ] );
		$value = $message[ Message::VALUE ];
		$this->assertIsArray( $value, 'VALUE must be the structured command array, not a JSON string' );
		$this->assertSame( 'heartbeat', $value['name'] );
		$this->assertSame( 2, $value['payload']['slot'] );
		$this->assertSame( 0, $value['payload']['partition'] );
		// INVARIANT: the slot TTL is refreshed ONLY by this client heartbeat
		// (the server no longer refresh-on-checks), so it MUST outlive the
		// heartbeat interval or the slot dies in the gap between pokes.
		$this->assertSame(
			Remote_Source_Node::HEARTBEAT_INTERVAL * 4,
			$value['payload']['ttl']
		);
		$this->assertGreaterThan(
			Remote_Source_Node::HEARTBEAT_INTERVAL,
			$value['payload']['ttl'],
			'heartbeat ttl must exceed HEARTBEAT_INTERVAL so the slot survives between pokes'
		);
	}

	public function test_maybe_send_heartbeat_posts_with_bearer_token(): void {
		$remote = new Remote_Source_Node( 'siteTok', 'http://siteTok.test', '', '', 'bearer-tok', 'firehose', 0 );
		$remote->name( 'remote:siteTok' );
		$remote->set_require_https( false );
		$this->poke( $remote, 'connected', true );
		$this->poke( $remote, 'slot', 1 );

		Core::$now = 1000.0;
		$this->invoke( $remote, 'maybe_send_heartbeat' );

		$this->assertCount( 1, $GLOBALS['_wp_test_remote_posts'] );
		$post = $GLOBALS['_wp_test_remote_posts'][0];
		$this->assertSame( 'Bearer bearer-tok', $post['args']['headers']['Authorization'] ?? '' );
	}

	// =========================================================================
	// check_stale — short-circuits & disconnects on timeout
	// =========================================================================

	public function test_check_stale_noop_when_not_connected(): void {
		$remote = $this->make_remote();
		$this->poke( $remote, 'connected', false );
		// Should not crash, no state change.
		$this->invoke( $remote, 'check_stale' );
		$this->assertNull( $remote->test_get_handle() );
	}

	public function test_check_stale_noop_within_timeout(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$first = $remote->test_get_handle();

		// last_event_time is `now` at maybe_connect — under HEARTBEAT_TIMEOUT.
		Core::$now = 1000.0 + Remote_Source_Node::HEARTBEAT_TIMEOUT - 1;
		$this->invoke( $remote, 'check_stale' );

		$this->assertSame( $first, $remote->test_get_handle() );
	}

	public function test_check_stale_disconnects_when_stale(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$first = $remote->test_get_handle();
		$this->assertNotNull( $first );

		// Push time past timeout.
		Core::$now = 1000.0 + Remote_Source_Node::HEARTBEAT_TIMEOUT + 1;
		$this->invoke( $remote, 'check_stale' );

		$this->assertNull( $remote->test_get_handle() );
		$this->assertStringContainsString( 'Stale connection', (string) $remote->get_last_error() );
		// Backoff bumped.
		$this->assertSame( 2, $remote->get_backoff() );
	}

	// =========================================================================
	// node_schema
	// =========================================================================

	public function test_node_schema_returns_full_descriptor(): void {
		$schema = Remote_Source_Node::node_schema();
		$this->assertIsArray( $schema );
		$this->assertSame( 'I/O', $schema['category'] );
		$this->assertNotEmpty( $schema['description'] );
		$this->assertIsArray( $schema['ctor'] );
		// 7 ctor params: server_id, url, auth_username, auth_password, auth_token, remote_topic, partition.
		$this->assertCount( 7, $schema['ctor'] );
		$names = \array_column( $schema['ctor'], 'name' );
		$this->assertSame(
			[ 'server_id', 'url', 'auth_username', 'auth_password', 'auth_token', 'remote_topic', 'partition' ],
			$names
		);
		// server_id and url are required.
		$this->assertTrue( $schema['ctor'][0]['required'] );
		$this->assertTrue( $schema['ctor'][1]['required'] );
		// partition has default.
		$this->assertSame( 0, $schema['ctor'][6]['default'] );
	}

	// =========================================================================
	// Introspection accessors
	// =========================================================================

	public function test_test_get_handle_returns_null_when_disconnected(): void {
		$remote = $this->make_remote();
		$this->assertNull( $remote->test_get_handle() );
	}

	public function test_get_last_http_code_default_null(): void {
		$remote = $this->make_remote();
		$this->assertNull( $remote->get_last_http_code() );
	}

	public function test_get_last_error_default_null(): void {
		$remote = $this->make_remote();
		$this->assertNull( $remote->get_last_error() );
	}

	public function test_get_backoff_default_initial(): void {
		$remote = $this->make_remote();
		$this->assertSame( Remote_Source_Node::INITIAL_BACKOFF, $remote->get_backoff() );
	}

	public function test_get_slot_default_null(): void {
		$remote = $this->make_remote();
		$this->assertNull( $remote->get_slot() );
	}

	public function test_is_connected_default_false(): void {
		$remote = $this->make_remote();
		$this->assertFalse( $remote->is_connected() );
	}

	public function test_is_connected_true_after_connect(): void {
		Core::$now = 1000.0;
		$remote = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$this->assertTrue( $remote->is_connected() );
	}

	// =========================================================================
	// /messages/stream endpoint contract + msg-envelope dispatch
	// =========================================================================

	public function test_maybe_connect_targets_messages_stream_endpoint(): void {
		Core::$now = 1000.0;
		$remote    = $this->make_remote();
		$this->invoke( $remote, 'maybe_connect' );
		$handle = $remote->test_get_handle();
		$this->assertNotNull( $handle );
		$url = \curl_getinfo( $handle, \CURLINFO_EFFECTIVE_URL );
		$this->assertStringContainsString(
			'/wp-json/newspack-nodes/v1/messages/stream',
			$url,
			'RemoteSource must consume the substrate unified SSE controller'
		);
		$this->assertStringNotContainsString(
			'/firehose/stream',
			$url,
			'Legacy per-feed controller URL must not appear'
		);
	}

	public function test_maybe_connect_uses_subscribe_query_for_partition(): void {
		Core::$now = 1000.0;
		$remote    = $this->make_remote( 'siteA', 'http://siteA.test', 3 );
		$this->invoke( $remote, 'maybe_connect' );
		$handle = $remote->test_get_handle();
		$url    = \curl_getinfo( $handle, \CURLINFO_EFFECTIVE_URL );
		// `subscribe=firehose.p3` is the substrate's IPC-style shape — partition
		// number flows through \Newspack_Nodes\Sse_Slot_Pool's partition arg so the per-partition
		// aggregator slot pool (60s TTL) is what we hit, not the shared browser
		// pool (30s TTL).
		$this->assertStringContainsString( 'subscribe=firehose.p3', $url );
		$this->assertStringNotContainsString( 'partition=', $url );
		$this->assertStringNotContainsString( 'aggregator=', $url );
	}

	public function test_maybe_connect_emits_positions_json_when_position_nonzero(): void {
		Core::$now = 1000.0;
		$remote    = $this->make_remote( 'siteA', 'http://siteA.test', 2 );
		$this->poke(
			$remote,
			'position',
			[ 'segment_id' => 42, 'offset' => 1024 ]
		);
		$this->invoke( $remote, 'maybe_connect' );
		$handle = $remote->test_get_handle();
		$url    = \curl_getinfo( $handle, \CURLINFO_EFFECTIVE_URL );
		// `positions` is JSON-encoded then URL-encoded by http_build_query.
		// The substrate's `parse_positions` JSON-decodes to a `{sub: {N: ...}}` shape.
		$this->assertStringContainsString( 'positions=', $url );
		// Round-trip: decode the positions param and verify shape.
		\preg_match( '/positions=([^&]+)/', $url, $m );
		$decoded = \json_decode( \urldecode( $m[1] ?? '' ), true );
		$this->assertIsArray( $decoded );
		$this->assertArrayHasKey( 'firehose', $decoded );
		$this->assertSame( 42, $decoded['firehose'][2]['seg'] ?? null );
		$this->assertSame( 1024, $decoded['firehose'][2]['off'] ?? null );
	}

	public function test_msg_envelope_with_entry_value_forwards_to_sink(): void {
		// New wire shape: `event: msg\ndata: <7-field envelope JSON>\n\n`.
		// The application entry lives at envelope[VALUE] (= index 6); the
		// substrate-side Consumer stamps FROM=`firehose.pN` and packs the
		// firehose-entry dict into VALUE.
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );

		$envelope = [
			Message::TM_STRUCT,           // TYPE
			1700000000.5,                 // TIMESTAMP
			'firehose.p0',                // FROM
			'',                           // TO
			'42:1024',                    // ID (seg:off)
			'abc-rid',                    // KEY (rid)
			[ 'k' => 'render', 'ts' => 1700000000, 'rid' => 'abc-rid' ], // VALUE
		];
		$wire = "event: msg\ndata: " . \json_encode( $envelope ) . "\n\n";
		$ok   = $remote->process_sse_chunk( $wire );
		$this->assertTrue( $ok );
		$this->assertCount( 1, $capture->captured );
		$this->assertSame(
			'render',
			$capture->captured[0][ Message::VALUE ]['k'],
			'envelope[VALUE] should land at the sink as the firehose entry'
		);
	}

	public function test_msg_envelope_connected_records_slot(): void {
		// The substrate's `connected` envelope arrives as a `msg` event with
		// KEY=`connected` and VALUE=`{pid, slot, subscriptions, interval}`.
		// RemoteSource must capture the slot for the heartbeat POST loop.
		$remote = $this->make_remote();
		$envelope = [
			Message::TM_INFO,
			1700000000.0,
			'_stream',
			'',
			'',
			'connected',
			[ 'pid' => 12345, 'slot' => 4, 'subscriptions' => [ 'firehose.p0' ], 'interval' => 500 ],
		];
		$wire = "event: msg\ndata: " . \json_encode( $envelope ) . "\n\n";
		$remote->process_sse_chunk( $wire );
		$this->assertSame( 4, $remote->get_slot() );
	}

	public function test_msg_envelope_does_not_forward_connected_to_sink(): void {
		// `connected` is bookkeeping — must not land at the sink as a fake
		// firehose entry.
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );

		$envelope = [
			Message::TM_INFO,
			1700000000.0,
			'_stream',
			'',
			'',
			'connected',
			[ 'pid' => 1, 'slot' => 0, 'subscriptions' => [ 'firehose.p0' ], 'interval' => 500 ],
		];
		$remote->process_sse_chunk( "event: msg\ndata: " . \json_encode( $envelope ) . "\n\n" );
		$this->assertCount( 0, $capture->captured );
	}

	public function test_msg_envelope_updates_position_from_envelope_id(): void {
		// Each entry's ID = "seg:off" (set by Consumer at emit time). RemoteSource
		// must parse + store so the next reconnect rides the same position.
		$remote = $this->make_remote();
		$envelope = [
			Message::TM_STRUCT,
			1700000000.0,
			'firehose.p0',
			'',
			'7:512',
			'rid',
			[ 'k' => 'render', 'ts' => 1700000000 ],
		];
		$remote->process_sse_chunk( "event: msg\ndata: " . \json_encode( $envelope ) . "\n\n" );

		$pos = $this->peek( $remote, 'position' );
		$this->assertSame( 7, $pos['segment_id'] );
		$this->assertSame( 512, $pos['offset'] );
	}

	public function test_msg_envelope_scalar_value_passes_through(): void {
		// VALUE is a bare string (e.g. a TM_BYTESTREAM log line). RemoteSource
		// is transport — it forwards whatever the spoke publishes, without
		// peeking inside VALUE. The aggregator-ingest filter + `_source`
		// stamping only fire when VALUE is a dict; scalar payloads bypass
		// the firehose-shape rewrites and reach the sink unchanged.
		$remote  = $this->make_remote();
		$capture = new Capture_Sink_Node();
		$remote->sink( $capture );

		$envelope = [
			Message::TM_BYTESTREAM,
			1700000000.0,
			'firehose.p0',
			'',
			'0:0',
			'rid-2',
			'just a string',
		];
		$ok = $remote->process_sse_chunk( "event: msg\ndata: " . \json_encode( $envelope ) . "\n\n" );
		$this->assertTrue( $ok );
		$this->assertCount( 1, $capture->captured );
		$this->assertSame( 'just a string', $capture->captured[0][ Message::VALUE ] );
		$this->assertSame( 'rid-2', $capture->captured[0][ Message::KEY ] );
	}
}
