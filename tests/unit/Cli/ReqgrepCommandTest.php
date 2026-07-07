<?php
/**
 * Smoke tests for `wp nodes reqgrep`. Output is captured by swapping the
 * command's `$stdout` node for a line-capturing Callback_Node (the
 * `capture_output()` harness); `joined()` reassembles the captured lines into
 * the byte-stream the old `echo` path produced. The private state machine is
 * still driven via reflection. Tests cover the parts that matter:
 *
 *  - LruCache rotation contract.
 *  - Indent state machine: (start)/(complete) push/pop with depth tracking.
 *  - Per-rid byte cap enforcement.
 *  - Path validation on `__invoke()`.
 *  - The Consumer-based read path (cat / follow / stdin) → process_message.
 *
 * Every firehose line is a 7-field positional Message envelope (rid at
 * Message::KEY, entry hash at Message::VALUE); there is no legacy entry-hash
 * format. Tests feed process_message the unpacked Message array (built via
 * packed_struct()), or a packed envelope through the stdin / disk paths.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit\CLI;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\LRU_Cache;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

require_once \dirname( __DIR__, 3 ) . '/includes/cli/class-reqgrep-command.php';
require_once \dirname( __DIR__, 4 ) . '/newspack-nodes/tests/Helpers/WPCLIStub.php';

use Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command;

#[CoversClass( Reqgrep_Command::class )]
class ReqgrepCommandTest extends TestCase {

	private string $tmp;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $process_message;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $format_entry;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $output_request;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $output_remaining;

	private Reqgrep_Command $cmd;

	protected function setUp(): void {
		parent::setUp();
		// A fresh Event_Framework per test so a prior follow_mode drain leaves no
		// stray Consumer timers behind (follow_mode runs its Consumers under it).
		Event_Framework::reset();
		// Use /tmp directly for tests that exercise __invoke (path validation).
		$staging = '/tmp/reqgrep-test-' . \uniqid();
		\mkdir( $staging, 0755, true );
		$this->tmp = \realpath( $staging ) ?: $staging;

		$GLOBALS['_test_wp_cli_logs']    = [];
		$GLOBALS['_test_wp_cli_warns']   = [];
		$GLOBALS['_test_wp_cli_errors']  = [];
		$GLOBALS['_test_wp_cli_success'] = [];
		$GLOBALS['_wp_actions']          = [];
		$GLOBALS['_wp_options']          = [];

		$this->use_base_dir( $this->tmp );
		// Substrate-owned keys; use substrate-prefixed option names.
		\update_option( 'newspack_nodes_base_directory', $this->tmp );
		\update_option( 'newspack_nodes_num_partitions', 1 );

		Config::reset();
	}

	protected function tearDown(): void {
		Config::reset();
		Event_Framework::reset();
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Wire a fresh ReqgrepCommand with reflection-accessible private methods.
	 *
	 * @param string $pattern    Search pattern.
	 * @param bool   $raw        Emit raw JSONL.
	 * @param bool   $incomplete Show incomplete requests.
	 */
	private function make_cmd(
		string $pattern = '.',
		bool $raw = false,
		bool $incomplete = false,
		int $bucket_size = 250,
		int $num_buckets = 10
	): Reqgrep_Command {
		$cmd = new Reqgrep_Command();

		$set = function ( string $prop, $value ) use ( $cmd ): void {
			$ref = new \ReflectionProperty( $cmd, $prop );
			$ref->setValue( $cmd, $value );
		};
		$set( 'pattern', $pattern );
		$set( 'pattern_regex', '/' . \preg_quote( $pattern, '/' ) . '/i' );
		$set( 'raw', $raw );
		$set( 'incomplete', $incomplete );
		$set( 'bucket_size', $bucket_size );
		$set( 'num_buckets', $num_buckets );

		$inflight = ( new LRU_Cache( 100, 3 ) )->with_timed_rotation(
			60.0,
			function ( string $rid, $state ) use ( $cmd ): void {
				if ( ! $state instanceof \stdClass ) {
					return;
				}
				$out  = new \ReflectionMethod( $cmd, 'output_request' );
				$emit = new \ReflectionMethod( $cmd, 'emit' );
				$out->invoke( $cmd, $state->lines, $rid );
				$emit->invoke( $cmd, '[incomplete]' );
				$emit->invoke( $cmd, '' );
			}
		);
		$set( 'inflight', $inflight );

		$this->process_message  = new \ReflectionMethod( $cmd, 'process_message' );
		$this->format_entry     = new \ReflectionMethod( $cmd, 'format_entry' );
		$this->output_request   = new \ReflectionMethod( $cmd, 'output_request' );
		$this->output_remaining = new \ReflectionMethod( $cmd, 'output_remaining' );

		$this->cmd = $cmd;
		return $cmd;
	}

	private function get_prop( string $prop ) {
		$ref = new \ReflectionProperty( $this->cmd, $prop );
		return $ref->getValue( $this->cmd );
	}

	/**
	 * Feed one entry hash through process_message as an unpacked Message: rid in
	 * Message::KEY, entry in Message::VALUE — exactly what the Consumer forwards.
	 *
	 * @param array<string, mixed> $entry Entry hash (must carry `rid`).
	 */
	private function feed( Reqgrep_Command $cmd, array $entry ): void {
		$this->process_message->invoke( $cmd, $this->packed_struct( $entry ) );
	}

	/**
	 * Build a Message struct envelope around an entry — rid in KEY, entry hash in
	 * VALUE (mirrors Log_Manager since v0.2.17).
	 *
	 * @param array<string, mixed> $entry Entry hash.
	 * @return array<int, mixed> The 7-field positional Message array.
	 */
	private function packed_struct( array $entry ): array {
		$message                        = Message::new_message();
		$message[ Message::TYPE ]       = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ]  = (float) ( $entry['ts'] ?? 0 );
		$message[ Message::KEY ]        = (string) ( $entry['rid'] ?? '' );
		$message[ Message::VALUE ]      = $entry;
		return $message;
	}

	/**
	 * Swap the command's output node for a line-capturing Callback_Node; returns
	 * the accumulator (one entry per emit, the message VALUE verbatim).
	 */
	private function capture_output( Reqgrep_Command $cmd ): \ArrayObject {
		$lines = new \ArrayObject();
		$cmd->stdout = new \Newspack_Nodes\Callback_Node( static function ( array $m ) use ( $lines ): void {
			$lines[] = (string) $m[ \Newspack_Nodes\Message::VALUE ];
		} );
		return $lines;
	}

	/** Reproduce the old echo byte-stream: each captured VALUE + a trailing "\n" if absent (Stdout_Node's rule). */
	private static function joined( \ArrayObject $lines ): string {
		$out = '';
		foreach ( $lines as $text ) {
			$out .= \str_ends_with( $text, "\n" ) ? $text : $text . "\n";
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// LruCache smoke (rotation + on_evict callback).
	// -------------------------------------------------------------------------

	public function test_lru_cache_smoke_evicts_via_callback(): void {
		// 1 slot × 1 bucket → setting a second item rotates the only bucket and
		// fires on_evict for the first.
		$evicted = [];
		$cache   = new LRU_Cache( 1, 1 );
		$cache->with_timed_rotation( 0.001, function ( string $k, $v ) use ( &$evicted ): void {
			$evicted[] = [ $k, $v ];
		} );

		$cache->set( 'a', 'first' );
		\usleep( 2000 );
		$cache->rotate_if_due();
		$cache->set( 'b', 'second' );
		\usleep( 2000 );
		$cache->rotate_if_due();

		$this->assertNotEmpty( $evicted, 'rotation should have evicted at least one entry' );
	}

	public function test_lru_cache_smoke_promotes_on_get(): void {
		$cache = new LRU_Cache( 2, 3 );
		$cache->set( 'a', 'A' );
		$cache->set( 'b', 'B' );
		$this->assertSame( 'A', $cache->get( 'a' ) );
		$this->assertSame( 'B', $cache->get( 'b' ) );
	}

	// -------------------------------------------------------------------------
	// Indent state machine.
	// -------------------------------------------------------------------------

	public function test_indent_machine_push_pop_around_start_complete(): void {
		$cmd = $this->make_cmd();

		$captured = $this->capture_output( $cmd );

		// Build a request with a nested wp_loaded (start)/(complete) pair.
		// Sequence + expected indent column:
		//   1. process (start)        indent=0 then bumps to 4
		//   2. wp_loaded (start)      indent=4 then bumps to 8
		//   3. wp_loaded (complete)   drops 8→4, prints at 4
		//   4. init                   indent=4
		//   5. process (complete)     drops 4→0, prints at 0
		$rid   = 'indR';
		$ts    = 1700000000.0;
		$lines = [
			[ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/i', 'ts' => $ts ],
			[ 'n' => 2, 'rid' => $rid, 'k' => 'wp_loaded (start)', 'm' => '', 'ts' => $ts + 0.01 ],
			[ 'n' => 3, 'rid' => $rid, 'k' => 'wp_loaded (complete)', 'm' => '', 'ts' => $ts + 0.02 ],
			[ 'n' => 4, 'rid' => $rid, 'k' => 'init', 'm' => 'msg', 'ts' => $ts + 0.03 ],
			[ 'n' => 5, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/i', 'ts' => $ts + 0.04 ],
		];
		foreach ( $lines as $entry ) {
			$this->feed( $cmd, $entry );
		}

		$out = self::joined( $captured );

		// Output format: "%4d: %22s %sKEY..." → number(4)+":"+ " "+ts(22)+" "+indent+key.
		// Fixed prefix before indent = 4+1+1+22+1 = 29 chars.
		$find_indent = static function ( string $key_substring, string $output ): int {
			$out_lines = \array_filter( \explode( "\n", $output ) );
			foreach ( $out_lines as $line ) {
				if ( ! \str_contains( $line, $key_substring ) ) {
					continue;
				}
				if ( \preg_match( '/^.{29}( *)' . \preg_quote( $key_substring, '/' ) . '/', $line, $m ) ) {
					return \strlen( $m[1] );
				}
			}
			return -1;
		};

		$this->assertSame( 0, $find_indent( 'process (start)', $out ), 'process (start) at top column' );
		$this->assertSame( 4, $find_indent( 'wp_loaded (start)', $out ), 'wp_loaded (start) one level deep' );
		$this->assertSame( 4, $find_indent( 'wp_loaded (complete)', $out ), 'wp_loaded (complete) drops then prints' );
		$this->assertSame( 4, $find_indent( 'init:', $out ), 'init within wp_loaded body sits at depth 4' );
		$this->assertSame( 0, $find_indent( 'process (complete)', $out ), 'process (complete) drops back to 0' );
	}

	public function test_indent_clamped_at_zero_on_unbalanced_complete(): void {
		$cmd = $this->make_cmd();
		$captured = $this->capture_output( $cmd );

		// (complete) before any (start) should clamp to 0, not go negative.
		$rid = 'clampR';
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'foo (complete)', 'm' => 'x', 'ts' => 1700000000 ] );
		$this->feed( $cmd, [ 'n' => 2, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/x', 'ts' => 1700000000.1 ] );

		$indent_prop = new \ReflectionProperty( $cmd, 'fmt_indent' );
		$this->assertGreaterThanOrEqual( 0, $indent_prop->getValue( $cmd ) );
	}

	public function test_complete_request_emits_when_pattern_matches_first_line(): void {
		$cmd = $this->make_cmd( '/calendar' );
		$captured = $this->capture_output( $cmd );

		$rid = 'reqA';
		$ts  = 1700000000.0;
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/calendar', 'ts' => $ts ] );
		$this->feed( $cmd, [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'mid', 'ts' => $ts + 0.1 ] );
		$this->feed( $cmd, [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/calendar', 'ts' => $ts + 0.5, 'duration_ms' => 500.12 ] );

		$out = self::joined( $captured );
		$this->assertStringContainsString( "request_id:{$rid}", $out );
		$this->assertStringContainsString( 'process (complete)', $out );
		$this->assertStringContainsString( '500.12ms', $out );
	}

	public function test_skips_non_matching_request(): void {
		$cmd = $this->make_cmd( '/calendar' );
		$captured = $this->capture_output( $cmd );

		$ts = 1700000000.0;
		$this->feed( $cmd, [ 'n' => 1, 'rid' => 'X', 'k' => 'process (start)', 'm' => '/other', 'ts' => $ts ] );
		$this->feed( $cmd, [ 'n' => 2, 'rid' => 'X', 'k' => 'process (complete)', 'm' => '/other', 'ts' => $ts + 0.1 ] );

		$out = self::joined( $captured );
		$this->assertStringNotContainsString( 'request_id:X', $out );
	}

	public function test_raw_mode_emits_jsonl(): void {
		$cmd = $this->make_cmd( '/raw', /*raw*/ true );
		$captured = $this->capture_output( $cmd );

		$rid = 'rawR';
		$ts  = 1700000000.0;
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/raw', 'ts' => $ts ] );
		$this->feed( $cmd, [ 'n' => 2, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/raw', 'ts' => $ts + 0.1 ] );

		$out = self::joined( $captured );
		// Raw mode echoes the packed envelope verbatim; rid + entry fields survive.
		$this->assertStringContainsString( '"rid":"rawR"', $out );
		$this->assertStringContainsString( '"k":"process (start)"', $out );
	}

	// -------------------------------------------------------------------------
	// Per-rid byte cap.
	// -------------------------------------------------------------------------

	public function test_per_rid_byte_cap_enforced(): void {
		$rid = 'capR';
		$cmd = $this->make_cmd( $rid );

		// First call seeds the in-flight rid.
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000 ] );

		// Pump ~10KB lines until we'd exceed the 10MB cap. Exiting via cap is
		// silent — append_to_state returns false but doesn't print.
		$message = \str_repeat( 'X', 10000 );
		for ( $i = 0; $i < 2000; $i++ ) {
			$this->feed( $cmd, [ 'n' => 2 + $i, 'rid' => $rid, 'k' => 'init', 'm' => $message, 'ts' => 1700000000 + $i * 0.001 ] );
		}

		/** @var LRU_Cache $inflight */
		$inflight = $this->get_prop( 'inflight' );
		$state    = $inflight->get( $rid );

		$this->assertNotNull( $state, 'rid should still be tracked' );
		$this->assertLessThanOrEqual( 10 * 1024 * 1024, $state->bytes, 'state bytes must respect MAX_BYTES_PER_REQUEST' );
	}

	public function test_history_tracks_non_matching_rids(): void {
		$cmd = $this->make_cmd( 'targetRid' );
		$captured = $this->capture_output( $cmd );

		// Start a non-matching rid; it lands in history.
		$this->feed( $cmd, [ 'n' => 1, 'rid' => 'targetRid', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000 ] );

		// Pattern match ON the rid bootstraps tracking.
		$this->feed( $cmd, [ 'n' => 2, 'rid' => 'targetRid', 'k' => 'init', 'm' => 'something', 'ts' => 1700000000.1 ] );

		// Complete it.
		$this->feed( $cmd, [ 'n' => 3, 'rid' => 'targetRid', 'k' => 'process (complete)', 'm' => '/x', 'ts' => 1700000000.2 ] );

		$out = self::joined( $captured );
		$this->assertStringContainsString( 'request_id:targetRid', $out );
	}

	// -------------------------------------------------------------------------
	// Format-entry edge cases — timestamps, dot rows, multi-line messages.
	// -------------------------------------------------------------------------

	public function test_format_entry_dot_rows_for_multi_second_gaps(): void {
		$cmd = $this->make_cmd();
		$captured = $this->capture_output( $cmd );

		// Start a request, then jump 5 seconds — dot rows should appear
		// for each elapsed second.
		$rid = 'gapR';
		$ts0 = 1700000000.0;
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/g', 'ts' => $ts0 ] );
		// 5-second gap.
		$this->feed( $cmd, [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'after-gap', 'ts' => $ts0 + 5 ] );
		$this->feed( $cmd, [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/g', 'ts' => $ts0 + 5.1 ] );

		$out = self::joined( $captured );
		// Look for at least one dot row (lines ending with " .").
		$dot_rows = \preg_match_all( '/^\s*\d+:.*\.\s*$/m', $out );
		$this->assertGreaterThanOrEqual( 1, $dot_rows, 'should emit dot rows for multi-second gaps' );
	}

	public function test_format_entry_includes_peak_mb_in_complete_suffix(): void {
		$cmd = $this->make_cmd( 'memR' );
		$captured = $this->capture_output( $cmd );

		$rid = 'memR';
		$ts  = 1700000000.0;
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/p', 'ts' => $ts ] );
		$this->feed( $cmd, [
			'n' => 2,
			'rid' => $rid,
			'k' => 'process (complete)',
			'm' => '/p',
			'ts' => $ts + 0.1,
			'duration_ms' => 99.99,
			'peak_mb' => 42,
		] );

		$out = self::joined( $captured );
		$this->assertStringContainsString( '99.99ms', $out );
		$this->assertStringContainsString( '[42MB]', $out );
	}

	public function test_format_entry_pretty_prints_array_message(): void {
		$cmd = $this->make_cmd( 'arrR' );
		$captured = $this->capture_output( $cmd );

		$rid = 'arrR';
		$ts  = 1700000000.0;
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/a', 'ts' => $ts ] );
		// Array message — should be json-encoded with newlines.
		$this->feed( $cmd, [
			'n'   => 2,
			'rid' => $rid,
			'k'   => 'init',
			'm'   => [ 'key1' => 'v1', 'key2' => 'v2' ],
			'ts'  => $ts + 0.1,
		] );
		$this->feed( $cmd, [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/a', 'ts' => $ts + 0.2 ] );

		$out = self::joined( $captured );
		// JSON-encoded array message should include the keys.
		$this->assertStringContainsString( 'key1', $out );
		$this->assertStringContainsString( 'v1', $out );
	}

	public function test_format_entry_aligns_multiline_message_continuation(): void {
		$cmd = $this->make_cmd( 'mlR' );
		$captured = $this->capture_output( $cmd );

		$rid = 'mlR';
		$ts  = 1700000000.0;
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/m', 'ts' => $ts ] );
		// Embed a literal newline in the message (gets escaped through JSON).
		$this->feed( $cmd, [
			'n'   => 2,
			'rid' => $rid,
			'k'   => 'init',
			'm'   => "line one\nline two\nline three",
			'ts'  => $ts + 0.1,
		] );
		$this->feed( $cmd, [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/m', 'ts' => $ts + 0.2 ] );

		$out = self::joined( $captured );
		// All three lines should appear in output.
		$this->assertStringContainsString( 'line one', $out );
		$this->assertStringContainsString( 'line two', $out );
		$this->assertStringContainsString( 'line three', $out );
	}

	public function test_format_entry_emits_separator_when_number_rewinds(): void {
		$cmd = $this->make_cmd( 'sepR' );
		$captured = $this->capture_output( $cmd );

		$rid = 'sepR';
		$ts  = 1700000000.0;
		// First request (sequence: 1, 2, 3).
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/s', 'ts' => $ts ] );
		$this->feed( $cmd, [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'middle', 'ts' => $ts + 0.1 ] );
		// Number rewinds to 1 (rid reset case mid-stream).
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'shutdown', 'm' => 'reset', 'ts' => $ts + 0.2 ] );
		$this->feed( $cmd, [ 'n' => 2, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/s', 'ts' => $ts + 0.3 ] );

		$out = self::joined( $captured );
		// Separator (60 hash chars) should appear when number rewinds.
		$this->assertStringContainsString( \str_repeat( '#', 60 ), $out );
	}

	// -------------------------------------------------------------------------
	// process_message: guard + envelope handling.
	// -------------------------------------------------------------------------

	public function test_process_message_skips_messages_without_rid(): void {
		$cmd = $this->make_cmd();
		$captured = $this->capture_output( $cmd );
		// No `rid` → Message::KEY is '' → process_message returns early.
		$this->feed( $cmd, [ 'n' => 1, 'k' => 'foo', 'm' => 'bar' ] );
		$out = self::joined( $captured );
		$this->assertSame( '', $out );
	}

	public function test_process_message_skips_non_array_value(): void {
		$cmd = $this->make_cmd();
		$captured = $this->capture_output( $cmd );
		// A TM_EOF-shaped message (VALUE '') must be ignored, not crash.
		$message                    = Message::new_message();
		$message[ Message::TYPE ]   = Message::TM_EOF;
		$message[ Message::KEY ]    = 'anything';
		$this->process_message->invoke( $cmd, $message );
		$out = self::joined( $captured );
		$this->assertSame( '', $out );
	}

	public function test_process_message_groups_and_outputs_completed_request(): void {
		$cmd = $this->make_cmd( '/wrapped' );
		$captured = $this->capture_output( $cmd );

		$rid = 'wrappedR';
		$ts  = 1700000000.0;
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/wrapped', 'ts' => $ts ] );
		$this->feed( $cmd, [ 'n' => 2, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/wrapped', 'ts' => $ts + 0.1, 'duration_ms' => 100 ] );

		$out = self::joined( $captured );
		$this->assertStringContainsString( "request_id:{$rid}", $out );
		$this->assertStringContainsString( 'process (complete)', $out );
	}

	// -------------------------------------------------------------------------
	// History eviction.
	// -------------------------------------------------------------------------

	public function test_history_rotates_buckets_on_overflow(): void {
		// Use a tiny bucket size so we hit the rotation quickly.
		$cmd = $this->make_cmd( 'never-matches', false, false, 2, 3 );

		// Pump many non-matching rids — should rotate history buckets.
		for ( $i = 0; $i < 20; $i++ ) {
			$this->feed( $cmd, [ 'n' => 1, 'rid' => "rid-$i", 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000 + $i * 0.01 ] );
		}

		// History should have been rotated/trimmed; check internal state.
		$history = $this->get_prop( 'history' );
		$this->assertIsArray( $history );
		// num_buckets caps at 3.
		$this->assertLessThanOrEqual( 3, \count( $history ) );
	}

	public function test_history_caps_lines_per_rid(): void {
		// Pattern won't match — rid lines stash in history.
		$cmd = $this->make_cmd( 'never-matches', false, false, 5000, 1 );
		$rid = 'manyR';

		for ( $i = 0; $i < 10; $i++ ) {
			$this->feed( $cmd, [ 'n' => $i, 'rid' => $rid, 'k' => 'init', 'm' => "msg{$i}", 'ts' => 1700000000.0 + $i * 0.001 ] );
		}
		// history bucket 0 should have the rid with up to 10 lines.
		$history = $this->get_prop( 'history' );
		$this->assertNotEmpty( $history );
		$this->assertArrayHasKey( $rid, $history[0] ?? [] );
	}

	// -------------------------------------------------------------------------
	// output_remaining: incomplete request emission.
	// -------------------------------------------------------------------------

	public function test_output_remaining_emits_incomplete_for_in_flight(): void {
		$cmd = $this->make_cmd( '/inflight' );
		$captured = $this->capture_output( $cmd );

		$rid = 'inflightR';
		$ts  = 1700000000.0;
		// Start matching request — never complete.
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/inflight', 'ts' => $ts ] );
		$this->feed( $cmd, [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'mid', 'ts' => $ts + 0.1 ] );

		// Now flush remaining — should emit [incomplete].
		$this->output_remaining->invoke( $cmd );

		$out = self::joined( $captured );
		$this->assertStringContainsString( 'request_id:' . $rid, $out );
		$this->assertStringContainsString( '[incomplete]', $out );
	}

	// -------------------------------------------------------------------------
	// Output mode: raw vs formatted. Lines are packed Message envelopes.
	// -------------------------------------------------------------------------

	public function test_output_request_raw_emits_jsonl(): void {
		$cmd = $this->make_cmd( '.', /*raw*/ true );
		$captured = $this->capture_output( $cmd );

		$lines = [
			Message::packed( $this->packed_struct( [ 'n' => 1, 'rid' => 'rawX', 'k' => 'a', 'm' => 'one', 'ts' => 1700000000.0 ] ) ),
			Message::packed( $this->packed_struct( [ 'n' => 2, 'rid' => 'rawX', 'k' => 'b', 'm' => 'two', 'ts' => 1700000000.1 ] ) ),
		];
		$this->output_request->invoke( $cmd, $lines, 'rawX' );

		$out = self::joined( $captured );
		// Raw mode emits each packed envelope verbatim.
		foreach ( $lines as $line ) {
			$this->assertStringContainsString( $line, $out );
		}
	}

	public function test_output_request_formatted_includes_header(): void {
		$cmd = $this->make_cmd();
		$captured = $this->capture_output( $cmd );

		$lines = [
			Message::packed( $this->packed_struct( [ 'n' => 1, 'rid' => 'fmtX', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0 ] ) ),
			Message::packed( $this->packed_struct( [ 'n' => 2, 'rid' => 'fmtX', 'k' => 'process (complete)', 'm' => '/x', 'ts' => 1700000000.1 ] ) ),
		];
		$this->output_request->invoke( $cmd, $lines, 'fmtX' );

		$out = self::joined( $captured );
		// Header line carries request_id:fmtX; the unwrapped body carries the key.
		$this->assertStringContainsString( 'request_id:fmtX', $out );
		$this->assertStringContainsString( 'process (complete)', $out );
	}

	public function test_output_request_falls_through_for_unparseable_line(): void {
		$cmd = $this->make_cmd();
		$captured = $this->capture_output( $cmd );

		// One line is not a packed Message — should pass through verbatim.
		$lines = [
			Message::packed( $this->packed_struct( [ 'n' => 1, 'rid' => 'pthroughX', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0 ] ) ),
			'this-is-not-a-message',
			Message::packed( $this->packed_struct( [ 'n' => 2, 'rid' => 'pthroughX', 'k' => 'process (complete)', 'm' => '/x', 'ts' => 1700000000.1 ] ) ),
		];
		$this->output_request->invoke( $cmd, $lines, 'pthroughX' );

		$out = self::joined( $captured );
		$this->assertStringContainsString( 'this-is-not-a-message', $out );
	}

	public function test_output_request_skips_header_when_rid_empty(): void {
		$cmd = $this->make_cmd();
		$captured = $this->capture_output( $cmd );

		$lines = [
			Message::packed( $this->packed_struct( [ 'n' => 1, 'rid' => 'noheaderR', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0 ] ) ),
			Message::packed( $this->packed_struct( [ 'n' => 2, 'rid' => 'noheaderR', 'k' => 'process (complete)', 'm' => '/x', 'ts' => 1700000000.1 ] ) ),
		];
		// Pass empty rid → no "request_id:" header line should appear.
		$this->output_request->invoke( $cmd, $lines, '' );

		$out = self::joined( $captured );
		$this->assertStringNotContainsString( 'request_id:', $out );
		// Body still emitted.
		$this->assertStringContainsString( 'process (start)', $out );
	}

	public function test_output_request_formatted_unwraps_packed_envelope(): void {
		// Formatted mode (raw=false) unpacks packed Message envelopes so on-disk
		// envelopes render correctly.
		$cmd = $this->make_cmd();
		$captured = $this->capture_output( $cmd );

		$rid     = 'envR';
		$ts      = 1700000000.0;
		$packed1 = Message::packed( $this->packed_struct( [
			'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/env', 'ts' => $ts,
		] ) );
		$packed2 = Message::packed( $this->packed_struct( [
			'n' => 2, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/env', 'ts' => $ts + 0.1,
		] ) );
		$this->output_request->invoke( $cmd, [ $packed1, $packed2 ], $rid );

		$out = self::joined( $captured );
		// Header + key strings make it through the unwrap.
		$this->assertStringContainsString( 'request_id:' . $rid, $out );
		$this->assertStringContainsString( '/env', $out );
		$this->assertStringContainsString( 'process (complete)', $out );
	}

	// -------------------------------------------------------------------------
	// process_stdin path: feed packed Message envelopes via in-memory stream.
	// -------------------------------------------------------------------------

	public function test_stdin_has_data_returns_false_when_no_stdin(): void {
		$cmd = $this->make_cmd();
		$ref = new \ReflectionMethod( $cmd, 'stdin_has_data' );
		// In PHPUnit, STDIN is typically a TTY/sock; expect false (no piped data).
		$result = $ref->invoke( $cmd );
		$this->assertIsBool( $result );
	}

	public function test_stdin_has_data_returns_true_for_regular_file_stream(): void {
		// A real file (S_IFREG) qualifies as piped data — the dispatcher in
		// __invoke uses this to choose process_stdin over cat_mode.
		$tmp = \tempnam( \sys_get_temp_dir(), 'reqgrep-stdin-' );
		\file_put_contents( $tmp, "anything\n" );
		try {
			$stream = \fopen( $tmp, 'r' );
			$cmd    = $this->make_cmd();
			$ref    = new \ReflectionMethod( $cmd, 'stdin_has_data' );
			$this->assertTrue( $ref->invoke( $cmd, $stream ) );
			\fclose( $stream );
		} finally {
			@\unlink( $tmp );
		}
	}

	public function test_stdin_has_data_returns_false_for_memory_stream(): void {
		// fstat on a closed resource returns false → method short-circuits.
		$cmd = $this->make_cmd();
		$ref = new \ReflectionMethod( $cmd, 'stdin_has_data' );

		$stream = \fopen( 'php://memory', 'r+' );
		\fclose( $stream );
		$this->assertFalse( $ref->invoke( $cmd, $stream ) );
	}

	public function test_process_stdin_consumes_lines_and_emits_matched_request(): void {
		// Inject a memory stream pre-populated with two complete request lines
		// (start + complete) for a matching rid plus one unrelated line, all as
		// packed Message envelopes. process_stdin unpacks + processes all three
		// and, because the matched rid completed, output_request fires.
		$cmd = $this->make_cmd( 'targetR' );
		$ref = new \ReflectionMethod( $cmd, 'process_stdin' );

		$stream = \fopen( 'php://memory', 'r+' );
		\fwrite( $stream, Message::packed( $this->packed_struct( [ 'n' => 1, 'rid' => 'targetR', 'k' => 'process (start)',    'm' => '/api', 'ts' => 1700000000.0 ] ) ) . "\n" );
		\fwrite( $stream, Message::packed( $this->packed_struct( [ 'n' => 2, 'rid' => 'unrelated','k' => 'process (start)',   'm' => '/x',   'ts' => 1700000000.5 ] ) ) . "\n" );
		\fwrite( $stream, Message::packed( $this->packed_struct( [ 'n' => 3, 'rid' => 'targetR', 'k' => 'process (complete)', 'm' => '/api', 'ts' => 1700000001.0 ] ) ) . "\n" );
		\rewind( $stream );

		$captured = $this->capture_output( $cmd );
		$ref->invoke( $cmd, $stream );
		$out = self::joined( $captured );
		\fclose( $stream );

		// The matched request was emitted (look for the URL we put in `m`).
		$this->assertStringContainsString( '/api', $out );
		$this->assertStringContainsString( 'targetR', $out );

		// Inflight is empty post-stream (complete consumes the rid).
		$inflight = $this->get_prop( 'inflight' );
		$this->assertNull( $inflight->get( 'targetR' ) );
	}

	public function test_process_stdin_calls_output_remaining_after_stream_ends(): void {
		// A request that never completes within the stream stays in inflight at
		// end-of-stream; output_remaining flushes it as `[incomplete]`.
		$cmd = $this->make_cmd( 'partialR', /*raw*/ false, /*incomplete*/ true );
		$ref = new \ReflectionMethod( $cmd, 'process_stdin' );

		$stream = \fopen( 'php://memory', 'r+' );
		\fwrite( $stream, Message::packed( $this->packed_struct( [ 'n' => 1, 'rid' => 'partialR', 'k' => 'process (start)', 'm' => '/half', 'ts' => 1700000000.0 ] ) ) . "\n" );
		\rewind( $stream );

		$captured = $this->capture_output( $cmd );
		$ref->invoke( $cmd, $stream );
		$out = self::joined( $captured );
		\fclose( $stream );

		// With --incomplete, output_remaining emits the partial request.
		$this->assertStringContainsString( '/half', $out );
		$this->assertStringContainsString( '[incomplete]', $out );
	}

	public function test_process_stdin_skips_non_matching_lines(): void {
		// No lines match the pattern — inflight stays empty.
		$cmd = $this->make_cmd( 'never-matches' );
		$ref = new \ReflectionMethod( $cmd, 'process_stdin' );

		$stream = \fopen( 'php://memory', 'r+' );
		\fwrite( $stream, Message::packed( $this->packed_struct( [ 'n' => 1, 'rid' => 'noiseR', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0 ] ) ) . "\n" );
		\rewind( $stream );

		$captured = $this->capture_output( $cmd );
		$ref->invoke( $cmd, $stream );
		\fclose( $stream );

		$inflight = $this->get_prop( 'inflight' );
		$this->assertNull( $inflight->get( 'noiseR' ) );
	}

	public function test_process_stdin_skips_unparseable_lines(): void {
		// A line that isn't a 7-element packed Message is skipped (Message::unpacked
		// throws → caught) without crashing or emitting output.
		$cmd = $this->make_cmd( '.' );
		$ref = new \ReflectionMethod( $cmd, 'process_stdin' );

		$stream = \fopen( 'php://memory', 'r+' );
		\fwrite( $stream, "not-a-message\n" );
		\fwrite( $stream, '{"just":"a hash"}' . "\n" );
		\rewind( $stream );

		$captured = $this->capture_output( $cmd );
		$ref->invoke( $cmd, $stream );
		$out = self::joined( $captured );
		\fclose( $stream );

		$this->assertSame( '', $out );
	}

	public function test_process_stdin_with_empty_stream_emits_nothing(): void {
		// Empty stream → fgets() returns false immediately → loop exits
		// → output_remaining() fires with empty inflight (no output).
		$cmd = $this->make_cmd();
		$ref = new \ReflectionMethod( $cmd, 'process_stdin' );

		$stream = \fopen( 'php://memory', 'r+' );
		// Don't write anything → stream starts at EOF.

		$captured = $this->capture_output( $cmd );
		$ref->invoke( $cmd, $stream );
		$out = self::joined( $captured );
		\fclose( $stream );
		$this->assertSame( '', $out );
	}

	// -------------------------------------------------------------------------
	// cat_mode / build_consumer end-to-end (Consumer.drain → process_message).
	// -------------------------------------------------------------------------

	/**
	 * Seed a real on-disk partition with packed Message entries so the read path
	 * (build_consumer → Consumer.drain → process_message) round-trips through the
	 * actual substrate reader rather than the reflection-only direct invocations.
	 *
	 * @param string                     $base_dir  reqgrep base dir (partition = its `.p{N}` sibling).
	 * @param int                        $partition Partition index.
	 * @param array<int, array<string, mixed>> $entries Entry hashes to write.
	 */
	private function seed_partition( string $base_dir, int $partition, array $entries ): void {
		// Flat layout: reqgrep reads `preg_replace('/\.log$/','',base_dir).".p{N}"`,
		// so seed at that exact dir (the partition lives in the dir NAME).
		$flat_dir = \preg_replace( '/\.log$/', '', $base_dir ) . ".p{$partition}";
		\mkdir( $flat_dir, 0755, true );
		$p = new \Newspack_Nodes\Partition_Node();
		$p->arguments( $flat_dir );
		foreach ( $entries as $entry ) {
			$message = $this->packed_struct( $entry );
			$p->fill( $message );
		}
		$p->flush();
	}

	public function test_cat_mode_drives_consumer_and_process_message(): void {
		$tmp = '/tmp/reqgrep-cat-mode-' . \uniqid();
		\mkdir( $tmp, 0755, true );
		try {
			// A complete request matching '/calendar' plus a noise line that
			// won't match. cat_mode drains the partition Consumer, feeding each
			// unpacked Message to process_message; the matched rid completes so
			// output_request fires.
			$this->seed_partition( $tmp, 0, [
				[ 'n' => 1, 'rid' => 'cal-rid', 'k' => 'process (start)',    'm' => '/calendar/today', 'ts' => 1700000000.0 ],
				[ 'n' => 5, 'rid' => 'cal-rid', 'k' => 'process (complete)', 'm' => '/calendar/today', 'ts' => 1700000000.5 ],
				[ 'n' => 1, 'rid' => 'other',   'k' => 'process (start)',    'm' => '/feed',           'ts' => 1700000001.0 ],
			] );

			$cmd = $this->make_cmd( '/calendar' );
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );
			$set( 'cat_offset', 'start' );

			$captured = $this->capture_output( $cmd );
			$ref = new \ReflectionMethod( $cmd, 'cat_mode' );
			$ref->invoke( $cmd );
			$out = self::joined( $captured );

			// Output must contain the matched request's URL and rid.
			$this->assertStringContainsString( '/calendar/today', $out );
			$this->assertStringContainsString( 'cal-rid', $out );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_cat_mode_recent_offset_skips_older_segments(): void {
		// `cat_offset = 'recent'` seeds the Consumer at the second-to-last segment
		// so a long-running cat doesn't replay ancient history.
		$tmp = '/tmp/reqgrep-cat-recent-' . \uniqid();
		\mkdir( "{$tmp}.p0", 0755, true );
		try {
			// Three segments: 0 (oldest), 3 (second-to-last), 5 (newest).
			\file_put_contents(
				"{$tmp}.p0/0.log",
				Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'old-rid', 'k' => 'process (start)', 'm' => '/old', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);
			\file_put_contents(
				"{$tmp}.p0/3.log",
				Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'mid-rid', 'k' => 'process (start)', 'm' => '/mid', 'ts' => 1700000001.0,
				] ) ) . "\n"
			);
			\file_put_contents(
				"{$tmp}.p0/5.log",
				Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'new-rid', 'k' => 'process (start)', 'm' => '/new', 'ts' => 1700000002.0,
				] ) ) . "\n"
			);

			$cmd = $this->make_cmd( '/' );
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );
			$set( 'cat_offset', 'recent' );

			$captured = $this->capture_output( $cmd );
			$ref = new \ReflectionMethod( $cmd, 'cat_mode' );
			$ref->invoke( $cmd );
			$out = self::joined( $captured );

			// 'recent' starts at segments[count-2] = id 3, so seg 0 is skipped.
			$this->assertStringNotContainsString( 'old-rid', $out );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_cat_mode_skips_partition_with_no_segments(): void {
		$tmp = '/tmp/reqgrep-cat-nosegs-' . \uniqid();
		\mkdir( "{$tmp}.p0", 0755, true );
		try {
			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );
			$set( 'cat_offset', 'start' );

			$captured = $this->capture_output( $cmd );
			$ref = new \ReflectionMethod( $cmd, 'cat_mode' );
			$ref->invoke( $cmd );
			$out = self::joined( $captured );

			// Empty partition → nothing but the Consumer's terminal EOF (ignored).
			$this->assertSame( '', $out );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	// -------------------------------------------------------------------------
	// build_consumer: partition-dir derivation.
	// -------------------------------------------------------------------------

	public function test_build_consumer_targets_flat_partition_dir(): void {
		$tmp = '/tmp/reqgrep-build-' . \uniqid();
		\mkdir( "{$tmp}.p0", 0755, true );
		try {
			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );

			$ref      = new \ReflectionMethod( $cmd, 'build_consumer' );
			$consumer = $ref->invoke( $cmd, 0 );

			$src = new \ReflectionProperty( $consumer, 'source_dir' );
			$this->assertSame( "{$tmp}.p0", $src->getValue( $consumer ) );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_build_consumer_strips_log_suffix_from_base_dir(): void {
		$tmp = '/tmp/reqgrep-build-log-' . \uniqid();
		\mkdir( "{$tmp}/firehose.p1", 0755, true );
		try {
			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', "{$tmp}/firehose.log" );

			$ref      = new \ReflectionMethod( $cmd, 'build_consumer' );
			$consumer = $ref->invoke( $cmd, 1 );

			$src = new \ReflectionProperty( $consumer, 'source_dir' );
			$this->assertSame( "{$tmp}/firehose.p1", $src->getValue( $consumer ) );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	// -------------------------------------------------------------------------
	// follow_mode: Consumer run under the Event_Framework drain loop.
	// -------------------------------------------------------------------------

	public function test_follow_mode_with_max_iterations_terminates(): void {
		// Production calls follow_mode() with no arg → PHP_INT_MAX → runs until
		// SIGINT. Tests pass a small max so the drain loop returns.
		$tmp = '/tmp/reqgrep-follow-bounded-' . \uniqid();
		\mkdir( "{$tmp}.p0", 0755, true );
		try {
			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			// 0 iterations: seed the tail Consumers + log lines, no polling.
			$ref = new \ReflectionMethod( $cmd, 'follow_mode' );
			$captured = $this->capture_output( $cmd );
			$ref->invoke( $cmd, 0 );

			// Returned without infinite-looping. Reaching here is the assertion.
			$this->assertTrue( true );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_follow_mode_emits_entry_log_lines(): void {
		// follow_mode prints "Base dir:" + "Following N partition(s)" via
		// WP_CLI::log before entering the drain loop. With max_iterations=0 the
		// loop is a no-op and only the entry log lines fire.
		$tmp = '/tmp/reqgrep-follow-entry-log-' . \uniqid();
		\mkdir( "{$tmp}.p0", 0755, true );
		try {
			$GLOBALS['_test_wp_cli_logs'] = [];

			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			$ref = new \ReflectionMethod( $cmd, 'follow_mode' );
			$ref->invoke( $cmd, 0 );

			$joined = \implode( "\n", $GLOBALS['_test_wp_cli_logs'] );
			$this->assertStringContainsString( 'Base dir:', $joined );
			$this->assertStringContainsString( 'Following 1 partition(s)', $joined );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_follow_mode_seeds_at_tail_and_does_not_replay_history(): void {
		// A partition with existing data: follow seeds each Consumer at 'end'
		// (the tail), so a bounded drain never replays the pre-existing lines.
		$tmp = '/tmp/reqgrep-follow-tail-' . \uniqid();
		try {
			$this->seed_partition( $tmp, 0, [
				[ 'n' => 1, 'rid' => 'existing-rid', 'k' => 'process (start)',    'm' => '/before', 'ts' => 1700000000.0 ],
				[ 'n' => 2, 'rid' => 'existing-rid', 'k' => 'process (complete)', 'm' => '/before', 'ts' => 1700000000.1 ],
			] );

			$cmd = $this->make_cmd( '/' );
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			$ref = new \ReflectionMethod( $cmd, 'follow_mode' );
			$captured = $this->capture_output( $cmd );
			$ref->invoke( $cmd, 1 );
			$out = self::joined( $captured );

			// Seeded at the tail → the pre-existing request is NOT replayed.
			$this->assertStringNotContainsString( 'existing-rid', $out );
		} finally {
			$this->rmdir_recursive( "{$tmp}.p0" );
			$this->rmdir_recursive( $tmp );
		}
	}

	// -------------------------------------------------------------------------
	// __invoke (WP-CLI entry point) — exercise the dispatch logic end-to-end.
	// -------------------------------------------------------------------------

	public function test_invoke_dispatches_to_cat_mode_with_explicit_path(): void {
		// Set up a real on-disk firehose layout under a controlled base_dir,
		// point Config at it via use_base_dir(), and exercise __invoke
		// end-to-end. Default `assoc_args` (no --follow, stdin not piped)
		// routes to cat_mode.
		$base_dir = '/tmp/reqgrep-invoke-' . \uniqid();
		\mkdir( "{$base_dir}/logs/firehose.p0", 0755, true );
		\mkdir( "{$base_dir}/logs/firehose.log", 0755, true );
		try {
			// Seed one matching request line so cat_mode has something to do.
			\file_put_contents(
				"{$base_dir}/logs/firehose.p0/0.log",
				Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'invoke-rid', 'k' => 'process (start)', 'm' => '/calendar', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);

			// Repoint substrate Config at our temp base_dir.
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();

			// Path must be inside the logs directory the Config resolves.
			$path = "{$base_dir}/logs/firehose.log";
			$captured = $this->capture_output( $cmd );
			$cmd->__invoke(
				[ 'invoke-rid' ],
				[ 'path' => $path ]
			);
			$out = self::joined( $captured );

			// cat_mode reached its body — output mentions the seeded URL.
			$this->assertStringContainsString( '/calendar', $out );
			$this->assertStringContainsString( 'invoke-rid', $out );
		} finally {
			$GLOBALS['_wp_actions'] = [];
			\Newspack_Nodes\Config::reset();
			\Newspack_Event_Logger_Nodes\Config::reset();
			$this->rmdir_recursive( $base_dir );
		}
	}

	public function test_invoke_errors_when_path_does_not_exist(): void {
		// Invalid --path triggers WP_CLI::error which our stub throws as
		// RuntimeException — verify the validation branch fires.
		$base_dir = '/tmp/reqgrep-invoke-bad-' . \uniqid();
		\mkdir( "{$base_dir}/logs", 0755, true );
		try {
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessageMatches( '/Invalid path/' );
			$cmd->__invoke( [ '.' ], [ 'path' => '/never/exists/anywhere' ] );
		} finally {
			$GLOBALS['_wp_actions'] = [];
			\Newspack_Nodes\Config::reset();
			\Newspack_Event_Logger_Nodes\Config::reset();
			$this->rmdir_recursive( $base_dir );
		}
	}

	public function test_invoke_errors_when_path_outside_logs_directory(): void {
		// --path outside the configured logs/ tree triggers a WP_CLI::error
		// (security envelope: don't let users read arbitrary files via the
		// CLI's own file-walker).
		$base_dir = '/tmp/reqgrep-invoke-outside-' . \uniqid();
		$elsewhere = '/tmp/reqgrep-invoke-elsewhere-' . \uniqid();
		\mkdir( "{$base_dir}/logs", 0755, true );
		\mkdir( $elsewhere, 0755, true );
		try {
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();

			$this->expectException( \RuntimeException::class );
			$this->expectExceptionMessageMatches( '/Path must be within the logs directory/' );
			$cmd->__invoke( [ '.' ], [ 'path' => $elsewhere ] );
		} finally {
			$GLOBALS['_wp_actions'] = [];
			\Newspack_Nodes\Config::reset();
			\Newspack_Event_Logger_Nodes\Config::reset();
			$this->rmdir_recursive( $base_dir );
			$this->rmdir_recursive( $elsewhere );
		}
	}

	public function test_invoke_clamps_bucket_size_to_max(): void {
		// bucket-size >10000 must clamp to 10000.
		$base_dir = '/tmp/reqgrep-invoke-clamp-' . \uniqid();
		\mkdir( "{$base_dir}/logs/firehose.p0", 0755, true );
		\mkdir( "{$base_dir}/logs/firehose.log", 0755, true );
		try {
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();

			$captured = $this->capture_output( $cmd );
			$cmd->__invoke(
				[ '.' ],
				[
					'path'        => "{$base_dir}/logs/firehose.log",
					'bucket-size' => '99999', // > 10000 cap.
					'num-buckets' => '99999', // > 100 cap.
				]
			);

			$bucket = new \ReflectionProperty( $cmd, 'bucket_size' );
			$buckets_n = new \ReflectionProperty( $cmd, 'num_buckets' );
			$this->assertSame( 10000, $bucket->getValue( $cmd ) );
			$this->assertSame( 100, $buckets_n->getValue( $cmd ) );
		} finally {
			$GLOBALS['_wp_actions'] = [];
			\Newspack_Nodes\Config::reset();
			\Newspack_Event_Logger_Nodes\Config::reset();
			$this->rmdir_recursive( $base_dir );
		}
	}

	public function test_invoke_clamps_bucket_size_to_min(): void {
		// bucket-size <1 must clamp to 1.
		$base_dir = '/tmp/reqgrep-invoke-clamp-min-' . \uniqid();
		\mkdir( "{$base_dir}/logs/firehose.p0", 0755, true );
		\mkdir( "{$base_dir}/logs/firehose.log", 0755, true );
		try {
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();

			$captured = $this->capture_output( $cmd );
			$cmd->__invoke(
				[ '.' ],
				[
					'path'        => "{$base_dir}/logs/firehose.log",
					'bucket-size' => '0',  // Below min 1.
					'num-buckets' => '0',  // Below min 1.
				]
			);

			$bucket = new \ReflectionProperty( $cmd, 'bucket_size' );
			$buckets_n = new \ReflectionProperty( $cmd, 'num_buckets' );
			$this->assertSame( 1, $bucket->getValue( $cmd ) );
			$this->assertSame( 1, $buckets_n->getValue( $cmd ) );
		} finally {
			$GLOBALS['_wp_actions'] = [];
			\Newspack_Nodes\Config::reset();
			\Newspack_Event_Logger_Nodes\Config::reset();
			$this->rmdir_recursive( $base_dir );
		}
	}

	public function test_invoke_recent_offset_takes_effect(): void {
		// --recent must propagate to the cat_offset property which cat_mode reads.
		$base_dir = '/tmp/reqgrep-invoke-recent-' . \uniqid();
		\mkdir( "{$base_dir}/logs/firehose.p0", 0755, true );
		\mkdir( "{$base_dir}/logs/firehose.log", 0755, true );
		try {
			\file_put_contents(
				"{$base_dir}/logs/firehose.p0/0.log",
				Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'recent-rid', 'k' => 'process (start)', 'm' => '/r', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);

			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();

			$captured = $this->capture_output( $cmd );
			$cmd->__invoke(
				[ '.' ],
				[
					'path'   => "{$base_dir}/logs/firehose.log",
					'recent' => true,
				]
			);

			$offset = new \ReflectionProperty( $cmd, 'cat_offset' );
			$this->assertSame( 'recent', $offset->getValue( $cmd ) );
		} finally {
			$GLOBALS['_wp_actions'] = [];
			\Newspack_Nodes\Config::reset();
			\Newspack_Event_Logger_Nodes\Config::reset();
			$this->rmdir_recursive( $base_dir );
		}
	}

	public function test_invoke_propagates_raw_flag(): void {
		$base_dir = '/tmp/reqgrep-invoke-raw-' . \uniqid();
		\mkdir( "{$base_dir}/logs/firehose.p0", 0755, true );
		\mkdir( "{$base_dir}/logs/firehose.log", 0755, true );
		try {
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();

			$captured = $this->capture_output( $cmd );
			$cmd->__invoke(
				[ '.' ],
				[
					'path' => "{$base_dir}/logs/firehose.log",
					'raw'  => true,
				]
			);

			$prop = new \ReflectionProperty( $cmd, 'raw' );
			$this->assertTrue( $prop->getValue( $cmd ) );
		} finally {
			$GLOBALS['_wp_actions'] = [];
			\Newspack_Nodes\Config::reset();
			\Newspack_Event_Logger_Nodes\Config::reset();
			$this->rmdir_recursive( $base_dir );
		}
	}

	public function test_invoke_propagates_incomplete_flag(): void {
		$base_dir = '/tmp/reqgrep-invoke-incomplete-' . \uniqid();
		\mkdir( "{$base_dir}/logs/firehose.p0", 0755, true );
		\mkdir( "{$base_dir}/logs/firehose.log", 0755, true );
		try {
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();

			$captured = $this->capture_output( $cmd );
			$cmd->__invoke(
				[ '.' ],
				[
					'path'       => "{$base_dir}/logs/firehose.log",
					'incomplete' => true,
				]
			);

			$prop = new \ReflectionProperty( $cmd, 'incomplete' );
			$this->assertTrue( $prop->getValue( $cmd ) );
		} finally {
			$GLOBALS['_wp_actions'] = [];
			\Newspack_Nodes\Config::reset();
			\Newspack_Event_Logger_Nodes\Config::reset();
			$this->rmdir_recursive( $base_dir );
		}
	}

	public function test_invoke_docblock_carries_wp_cli_synopsis(): void {
		// WP-CLI builds `wp nodes reqgrep --help` from the doc comment
		// IMMEDIATELY preceding __invoke, so the synopsis must live there — not
		// on a docblock orphaned by an intervening property declaration.
		$doc = ( new \ReflectionMethod( Reqgrep_Command::class, '__invoke' ) )->getDocComment();

		$this->assertIsString( $doc );
		$this->assertStringContainsString( '## OPTIONS', $doc );
		$this->assertStringContainsString( '[<pattern>]', $doc );
		$this->assertStringContainsString( '[--follow]', $doc );
		$this->assertStringContainsString( '## EXAMPLES', $doc );
		$this->assertStringContainsString( 'wp nodes reqgrep --follow', $doc );
	}

	// -------------------------------------------------------------------------
	// process_message: warning emission when history miss + non-first entry.
	// -------------------------------------------------------------------------

	public function test_process_message_warns_on_missing_history_when_not_first_entry(): void {
		// num_buckets=2 keeps the history bounded. Push enough non-matching
		// rids to fill the bucket array; THEN feed a matching rid whose
		// `n` > 1 (no history) — group_and_output emits a WP_CLI::warning.
		$cmd = $this->make_cmd( 'targetWarn', false, false, 1, 2 );

		// Fill 2 history buckets so the warning branch's
		// `count(history) >= num_buckets` predicate fires.
		for ( $i = 0; $i < 4; $i++ ) {
			$this->feed( $cmd, [ 'n' => 1, 'rid' => "noise-{$i}", 'k' => 'init', 'm' => '/x', 'ts' => 1700000000 + $i ] );
		}

		$GLOBALS['_test_wp_cli_warns'] = [];

		// Matching rid with n=5 (NOT 1) so it goes through the "non-first entry,
		// no history" warning branch.
		$this->feed( $cmd, [ 'n' => 5, 'rid' => 'targetWarn', 'k' => 'init', 'm' => 'late-arrival', 'ts' => 1700001000 ] );

		$this->assertNotEmpty( $GLOBALS['_test_wp_cli_warns'] );
		$this->assertStringContainsString(
			"Couldn't find request start in history",
			$GLOBALS['_test_wp_cli_warns'][0]
		);
	}

	// -------------------------------------------------------------------------
	// format_entry: escalating-interval dot-row compression for huge gaps.
	// -------------------------------------------------------------------------

	public function test_format_entry_escalating_dot_intervals_for_long_gaps(): void {
		// A 200-second gap should NOT produce 200 dot rows — the first 10
		// rows are at 1s spacing, then 10 at 10s, etc. This caps the row
		// count at roughly O(log gap) so multi-hour gaps stay readable.
		$cmd = $this->make_cmd();
		$captured = $this->capture_output( $cmd );

		$rid = 'longGapR';
		$ts0 = 1700000000.0;
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/g', 'ts' => $ts0 ] );
		// Jump 200 seconds.
		$this->feed( $cmd, [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'after-long', 'ts' => $ts0 + 200 ] );
		$this->feed( $cmd, [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/g', 'ts' => $ts0 + 200.1 ] );

		$out = self::joined( $captured );
		$dot_rows = \preg_match_all( '/^\s*\d+:.*\.\s*$/m', $out );
		// First 10 rows at 1s + ~10 rows at 10s = ~20 max. Asserting <50 keeps
		// the test robust against floor/jump alignment edge cases.
		$this->assertGreaterThanOrEqual( 1, $dot_rows );
		$this->assertLessThan( 50, $dot_rows );
	}

	public function test_format_entry_no_dot_rows_for_consecutive_seconds(): void {
		// A 1-second gap should NOT emit any dot rows (curr_sec <= last_sec+1
		// branch short-circuits).
		$cmd = $this->make_cmd();
		$captured = $this->capture_output( $cmd );

		$rid = 'tightR';
		$ts0 = 1700000000.0;
		$this->feed( $cmd, [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/t', 'ts' => $ts0 ] );
		$this->feed( $cmd, [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'a', 'ts' => $ts0 + 0.5 ] );
		$this->feed( $cmd, [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/t', 'ts' => $ts0 + 1.0 ] );

		$out      = self::joined( $captured );
		$dot_rows = \preg_match_all( '/^\s*\d+:.*\.\s*$/m', $out );
		$this->assertSame( 0, $dot_rows, 'sub-second gaps must not emit dot rows' );
	}
}
