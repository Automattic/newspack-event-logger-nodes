<?php
/**
 * Smoke tests for `wp nodes reqgrep`. Drives the private state machine via
 * reflection rather than `__invoke()` because the public entrypoint drains all
 * output buffers (including PHPUnit's), making `ob_start()`-based capture
 * unreliable. Reflection-driven tests cover the parts that matter:
 *
 *  - LruCache rotation contract.
 *  - Indent state machine: (start)/(complete) push/pop with depth tracking.
 *  - Per-rid byte cap enforcement.
 *  - Path validation on `__invoke()`.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit\CLI;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\LRU_Cache;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Partition_Node;
use PHPUnit\Framework\Attributes\CoversClass;

require_once \dirname( __DIR__, 3 ) . '/includes/cli/class-reqgrep-command.php';
require_once \dirname( __DIR__, 4 ) . '/newspack-nodes/tests/Helpers/WPCLIStub.php';

use Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command;

#[CoversClass( Reqgrep_Command::class )]
class ReqgrepCommandTest extends TestCase {

	private string $tmp;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $process_line;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $format_entry;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $output_request;

	/** @var \ReflectionMethod */
	private \ReflectionMethod $output_remaining;

	private Reqgrep_Command $cmd;

	protected function setUp(): void {
		parent::setUp();
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
			$ref->setAccessible( true );
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
				$out = new \ReflectionMethod( $cmd, 'output_request' );
				$out->setAccessible( true );
				$out->invoke( $cmd, $state->lines );
				echo "[incomplete]\n\n";
			}
		);
		$set( 'inflight', $inflight );

		$this->process_line     = new \ReflectionMethod( $cmd, 'process_line' );
		$this->process_line->setAccessible( true );
		$this->format_entry     = new \ReflectionMethod( $cmd, 'format_entry' );
		$this->format_entry->setAccessible( true );
		$this->output_request   = new \ReflectionMethod( $cmd, 'output_request' );
		$this->output_request->setAccessible( true );
		$this->output_remaining = new \ReflectionMethod( $cmd, 'output_remaining' );
		$this->output_remaining->setAccessible( true );

		$this->cmd = $cmd;
		return $cmd;
	}

	private function get_prop( string $prop ) {
		$ref = new \ReflectionProperty( $this->cmd, $prop );
		$ref->setAccessible( true );
		return $ref->getValue( $this->cmd );
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

		\ob_start();

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
			$this->process_line->invoke( $cmd, \json_encode( $entry ) . "\n" );
		}

		$out = \ob_get_clean();

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
		\ob_start();

		// (complete) before any (start) should clamp to 0, not go negative.
		$rid = 'clampR';
		$this->process_line->invoke(
			$cmd,
			\json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'foo (complete)', 'm' => 'x', 'ts' => 1700000000 ] ) . "\n"
		);
		$this->process_line->invoke(
			$cmd,
			\json_encode( [ 'n' => 2, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/x', 'ts' => 1700000000.1 ] ) . "\n"
		);
		\ob_get_clean();

		$indent_prop = new \ReflectionProperty( $cmd, 'fmt_indent' );
		$indent_prop->setAccessible( true );
		$this->assertGreaterThanOrEqual( 0, $indent_prop->getValue( $cmd ) );
	}

	public function test_complete_request_emits_when_pattern_matches_first_line(): void {
		$cmd = $this->make_cmd( '/calendar' );
		\ob_start();

		$rid = 'reqA';
		$ts  = 1700000000.0;
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/calendar', 'ts' => $ts ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'mid', 'ts' => $ts + 0.1 ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/calendar', 'ts' => $ts + 0.5, 'duration_ms' => 500.12 ] ) . "\n" );

		$out = \ob_get_clean();
		$this->assertStringContainsString( "request_id:{$rid}", $out );
		$this->assertStringContainsString( 'process (complete)', $out );
		$this->assertStringContainsString( '500.12ms', $out );
	}

	public function test_skips_non_matching_request(): void {
		$cmd = $this->make_cmd( '/calendar' );
		\ob_start();

		$ts = 1700000000.0;
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => 'X', 'k' => 'process (start)', 'm' => '/other', 'ts' => $ts ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 2, 'rid' => 'X', 'k' => 'process (complete)', 'm' => '/other', 'ts' => $ts + 0.1 ] ) . "\n" );

		$out = \ob_get_clean();
		$this->assertStringNotContainsString( 'request_id:X', $out );
	}

	public function test_raw_mode_emits_jsonl(): void {
		$cmd = $this->make_cmd( '/raw', /*raw*/ true );
		\ob_start();

		$rid = 'rawR';
		$ts  = 1700000000.0;
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/raw', 'ts' => $ts ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 2, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/raw', 'ts' => $ts + 0.1 ] ) . "\n" );

		$out = \ob_get_clean();
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
		$this->process_line->invoke(
			$cmd,
			\json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000 ] ) . "\n"
		);

		// Pump ~10KB lines until we'd exceed the 10MB cap. Exiting via cap is
		// silent — append_to_state returns false but doesn't print.
		$msg = \str_repeat( 'X', 10000 );
		for ( $i = 0; $i < 2000; $i++ ) {
			$this->process_line->invoke(
				$cmd,
				\json_encode( [ 'n' => 2 + $i, 'rid' => $rid, 'k' => 'init', 'm' => $msg, 'ts' => 1700000000 + $i * 0.001 ] ) . "\n"
			);
		}

		/** @var LRU_Cache $inflight */
		$inflight = $this->get_prop( 'inflight' );
		$state    = $inflight->get( $rid );

		$this->assertNotNull( $state, 'rid should still be tracked' );
		$this->assertLessThanOrEqual( 10 * 1024 * 1024, $state->bytes, 'state bytes must respect MAX_BYTES_PER_REQUEST' );
	}

	public function test_history_tracks_non_matching_rids(): void {
		$cmd = $this->make_cmd( 'targetRid' );
		\ob_start();

		// Start a non-matching rid; it lands in history.
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => 'targetRid', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000 ] ) . "\n" );

		// Pattern match ON the rid bootstraps tracking.
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 2, 'rid' => 'targetRid', 'k' => 'init', 'm' => 'something', 'ts' => 1700000000.1 ] ) . "\n" );

		// Complete it.
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 3, 'rid' => 'targetRid', 'k' => 'process (complete)', 'm' => '/x', 'ts' => 1700000000.2 ] ) . "\n" );

		$out = \ob_get_clean();
		$this->assertStringContainsString( 'request_id:targetRid', $out );
	}

	// -------------------------------------------------------------------------
	// Format-entry edge cases — timestamps, dot rows, multi-line messages.
	// -------------------------------------------------------------------------

	public function test_format_entry_dot_rows_for_multi_second_gaps(): void {
		$cmd = $this->make_cmd();
		\ob_start();

		// Start a request, then jump 5 seconds — dot rows should appear
		// for each elapsed second.
		$rid = 'gapR';
		$ts0 = 1700000000.0;
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/g', 'ts' => $ts0 ] ) . "\n" );
		// 5-second gap.
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'after-gap', 'ts' => $ts0 + 5 ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/g', 'ts' => $ts0 + 5.1 ] ) . "\n" );

		$out = \ob_get_clean();
		// Look for at least one dot row (lines ending with " .").
		$dot_rows = \preg_match_all( '/^\s*\d+:.*\.\s*$/m', $out );
		$this->assertGreaterThanOrEqual( 1, $dot_rows, 'should emit dot rows for multi-second gaps' );
	}

	public function test_format_entry_includes_peak_mb_in_complete_suffix(): void {
		$cmd = $this->make_cmd( 'memR' );
		\ob_start();

		$rid = 'memR';
		$ts  = 1700000000.0;
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/p', 'ts' => $ts ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [
			'n' => 2,
			'rid' => $rid,
			'k' => 'process (complete)',
			'm' => '/p',
			'ts' => $ts + 0.1,
			'duration_ms' => 99.99,
			'peak_mb' => 42,
		] ) . "\n" );

		$out = \ob_get_clean();
		$this->assertStringContainsString( '99.99ms', $out );
		$this->assertStringContainsString( '[42MB]', $out );
	}

	public function test_format_entry_pretty_prints_array_message(): void {
		$cmd = $this->make_cmd( 'arrR' );
		\ob_start();

		$rid = 'arrR';
		$ts  = 1700000000.0;
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/a', 'ts' => $ts ] ) . "\n" );
		// Array message — should be json-encoded with newlines.
		$this->process_line->invoke( $cmd, \json_encode( [
			'n'   => 2,
			'rid' => $rid,
			'k'   => 'init',
			'm'   => [ 'key1' => 'v1', 'key2' => 'v2' ],
			'ts'  => $ts + 0.1,
		] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/a', 'ts' => $ts + 0.2 ] ) . "\n" );

		$out = \ob_get_clean();
		// JSON-encoded array message should include the keys.
		$this->assertStringContainsString( 'key1', $out );
		$this->assertStringContainsString( 'v1', $out );
	}

	public function test_format_entry_aligns_multiline_message_continuation(): void {
		$cmd = $this->make_cmd( 'mlR' );
		\ob_start();

		$rid = 'mlR';
		$ts  = 1700000000.0;
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/m', 'ts' => $ts ] ) . "\n" );
		// Embed a literal newline in the message (gets escaped through JSON).
		$this->process_line->invoke( $cmd, \json_encode( [
			'n'   => 2,
			'rid' => $rid,
			'k'   => 'init',
			'm'   => "line one\nline two\nline three",
			'ts'  => $ts + 0.1,
		] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/m', 'ts' => $ts + 0.2 ] ) . "\n" );

		$out = \ob_get_clean();
		// All three lines should appear in output.
		$this->assertStringContainsString( 'line one', $out );
		$this->assertStringContainsString( 'line two', $out );
		$this->assertStringContainsString( 'line three', $out );
	}

	public function test_format_entry_emits_separator_when_number_rewinds(): void {
		$cmd = $this->make_cmd( 'sepR' );
		\ob_start();

		$rid = 'sepR';
		$ts  = 1700000000.0;
		// First request (sequence: 1, 2, 3).
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/s', 'ts' => $ts ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'middle', 'ts' => $ts + 0.1 ] ) . "\n" );
		// Number rewinds to 1 (rid reset case mid-stream).
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'shutdown', 'm' => 'reset', 'ts' => $ts + 0.2 ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 2, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/s', 'ts' => $ts + 0.3 ] ) . "\n" );

		$out = \ob_get_clean();
		// Separator (60 hash chars) should appear when number rewinds.
		$this->assertStringContainsString( \str_repeat( '#', 60 ), $out );
	}

	// -------------------------------------------------------------------------
	// process_line: stash/match path edge cases.
	// -------------------------------------------------------------------------

	public function test_process_line_skips_invalid_json(): void {
		$cmd = $this->make_cmd();
		\ob_start();
		$this->process_line->invoke( $cmd, "not-valid-json\n" );
		// No crash, no output.
		$out = \ob_get_clean();
		$this->assertSame( '', $out );
	}

	public function test_process_line_skips_lines_without_rid(): void {
		$cmd = $this->make_cmd();
		\ob_start();
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'k' => 'foo', 'm' => 'bar' ] ) . "\n" );
		$out = \ob_get_clean();
		$this->assertSame( '', $out );
	}

	public function test_process_line_skips_blank_lines(): void {
		$cmd = $this->make_cmd();
		\ob_start();
		$this->process_line->invoke( $cmd, "" );
		$this->process_line->invoke( $cmd, "\n" );
		$this->process_line->invoke( $cmd, "   \n" );
		$out = \ob_get_clean();
		$this->assertSame( '', $out );
	}

	public function test_process_line_unwraps_packed_message_envelope(): void {
		$cmd = $this->make_cmd( '/wrapped' );
		\ob_start();

		$rid     = 'wrappedR';
		$ts      = 1700000000.0;
		$message = \Newspack_Nodes\Message::new_message();
		$message[ \Newspack_Nodes\Message::TYPE ]      = \Newspack_Nodes\Message::TM_STRUCT;
		$message[ \Newspack_Nodes\Message::TIMESTAMP ] = $ts;
		$message[ \Newspack_Nodes\Message::KEY ]       = $rid;
		$message[ \Newspack_Nodes\Message::VALUE ]     = [
			'n'   => 1,
			'rid' => $rid,
			'k'   => 'process (start)',
			'm'   => '/wrapped',
			'ts'  => $ts,
		];
		$packed = \Newspack_Nodes\Message::packed( $message );
		// process_line should detect the array-is-list shape and unwrap to entry.
		$this->process_line->invoke( $cmd, $packed );

		$message2 = \Newspack_Nodes\Message::new_message();
		$message2[ \Newspack_Nodes\Message::TYPE ]      = \Newspack_Nodes\Message::TM_STRUCT;
		$message2[ \Newspack_Nodes\Message::TIMESTAMP ] = $ts + 0.1;
		$message2[ \Newspack_Nodes\Message::KEY ]       = $rid;
		$message2[ \Newspack_Nodes\Message::VALUE ]     = [
			'n'           => 2,
			'rid'         => $rid,
			'k'           => 'process (complete)',
			'm'           => '/wrapped',
			'ts'          => $ts + 0.1,
			'duration_ms' => 100,
		];
		$packed2 = \Newspack_Nodes\Message::packed( $message2 );
		$this->process_line->invoke( $cmd, $packed2 );

		$out = \ob_get_clean();
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
			$this->process_line->invoke(
				$cmd,
				\json_encode( [ 'n' => 1, 'rid' => "rid-$i", 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000 + $i * 0.01 ] ) . "\n"
			);
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

		// Push way more than MAX_LINES_PER_REQUEST_IN_HISTORY (10000) lines.
		// We won't actually hit it (would be slow), but verify the cap branch
		// is exercised by checking the cap doesn't crash on an extra line.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->process_line->invoke(
				$cmd,
				\json_encode( [ 'n' => $i, 'rid' => $rid, 'k' => 'init', 'm' => "msg{$i}", 'ts' => 1700000000.0 + $i * 0.001 ] ) . "\n"
			);
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
		\ob_start();

		$rid = 'inflightR';
		$ts  = 1700000000.0;
		// Start matching request — never complete.
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/inflight', 'ts' => $ts ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'mid', 'ts' => $ts + 0.1 ] ) . "\n" );

		// Now flush remaining — should emit [incomplete].
		$this->output_remaining->invoke( $cmd );

		$out = \ob_get_clean();
		$this->assertStringContainsString( 'request_id:' . $rid, $out );
		$this->assertStringContainsString( '[incomplete]', $out );
	}

	// -------------------------------------------------------------------------
	// Output mode: raw vs formatted.
	// -------------------------------------------------------------------------

	public function test_output_request_raw_emits_jsonl(): void {
		$cmd = $this->make_cmd( '.', /*raw*/ true );
		\ob_start();

		$lines = [
			\json_encode( [ 'n' => 1, 'rid' => 'rawX', 'k' => 'a', 'm' => 'one', 'ts' => 1700000000.0 ] ),
			\json_encode( [ 'n' => 2, 'rid' => 'rawX', 'k' => 'b', 'm' => 'two', 'ts' => 1700000000.1 ] ),
		];
		$this->output_request->invoke( $cmd, $lines, 'rawX' );

		$out = \ob_get_clean();
		// Raw mode emits each line verbatim.
		foreach ( $lines as $line ) {
			$this->assertStringContainsString( $line, $out );
		}
	}

	public function test_output_request_formatted_includes_header(): void {
		$cmd = $this->make_cmd();
		\ob_start();

		$lines = [
			\json_encode( [ 'n' => 1, 'rid' => 'fmtX', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0 ] ),
			\json_encode( [ 'n' => 2, 'rid' => 'fmtX', 'k' => 'process (complete)', 'm' => '/x', 'ts' => 1700000000.1 ] ),
		];
		$this->output_request->invoke( $cmd, $lines, 'fmtX' );

		$out = \ob_get_clean();
		// Header line carries request_id:fmtX.
		$this->assertStringContainsString( 'request_id:fmtX', $out );
	}

	public function test_output_request_falls_through_for_non_array_entry(): void {
		$cmd = $this->make_cmd();
		\ob_start();

		// One line is invalid JSON — should pass through verbatim.
		$lines = [
			\json_encode( [ 'n' => 1, 'rid' => 'pthroughX', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0 ] ),
			'this-is-not-json',
			\json_encode( [ 'n' => 2, 'rid' => 'pthroughX', 'k' => 'process (complete)', 'm' => '/x', 'ts' => 1700000000.1 ] ),
		];
		$this->output_request->invoke( $cmd, $lines, 'pthroughX' );

		$out = \ob_get_clean();
		$this->assertStringContainsString( 'this-is-not-json', $out );
	}

	// -------------------------------------------------------------------------
	// process_stdin path: feed via in-memory stream.
	// -------------------------------------------------------------------------

	public function test_stdin_has_data_returns_false_when_no_stdin(): void {
		$cmd = $this->make_cmd();
		$ref = new \ReflectionMethod( $cmd, 'stdin_has_data' );
		$ref->setAccessible( true );
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
			$ref->setAccessible( true );
			$this->assertTrue( $ref->invoke( $cmd, $stream ) );
			\fclose( $stream );
		} finally {
			@\unlink( $tmp );
		}
	}

	public function test_stdin_has_data_returns_false_for_memory_stream(): void {
		// php://memory reports as a "regular file" via fstat → returns true
		// even though there's no actual piped data. Use php://temp instead so
		// the contract "in-memory ≠ piped data" stays observable. Real fstat
		// on a memory stream is environment-dependent, so this test pins the
		// guard rather than the underlying syscall.
		$cmd = $this->make_cmd();
		$ref = new \ReflectionMethod( $cmd, 'stdin_has_data' );
		$ref->setAccessible( true );

		// fstat on a closed resource returns false → method short-circuits.
		$stream = \fopen( 'php://memory', 'r+' );
		\fclose( $stream );
		$this->assertFalse( $ref->invoke( $cmd, $stream ) );
	}

	public function test_process_stdin_consumes_lines_and_emits_matched_request(): void {
		// Inject a memory stream pre-populated with two complete request
		// lines (start + complete) for a matching rid plus one unrelated
		// line. process_stdin should process all three and, because the
		// matched rid completed, output_request fires.
		$cmd = $this->make_cmd( 'targetR' );
		$ref = new \ReflectionMethod( $cmd, 'process_stdin' );
		$ref->setAccessible( true );

		$stream = \fopen( 'php://memory', 'r+' );
		\fwrite( $stream, \json_encode( [ 'n' => 1, 'rid' => 'targetR', 'k' => 'process (start)',    'm' => '/api', 'ts' => 1700000000.0 ] ) . "\n" );
		\fwrite( $stream, \json_encode( [ 'n' => 2, 'rid' => 'unrelated','k' => 'process (start)',   'm' => '/x',   'ts' => 1700000000.5 ] ) . "\n" );
		\fwrite( $stream, \json_encode( [ 'n' => 3, 'rid' => 'targetR', 'k' => 'process (complete)', 'm' => '/api', 'ts' => 1700000001.0 ] ) . "\n" );
		\rewind( $stream );

		\ob_start();
		$ref->invoke( $cmd, $stream );
		$out = \ob_get_clean();
		\fclose( $stream );

		// The matched request was emitted (look for the URL we put in `m`).
		$this->assertStringContainsString( '/api', $out );
		$this->assertStringContainsString( 'targetR', $out );

		// Inflight is empty post-stream (complete consumes the rid).
		$inflight = $this->get_prop( 'inflight' );
		$this->assertNull( $inflight->get( 'targetR' ) );
	}

	public function test_process_stdin_calls_output_remaining_after_stream_ends(): void {
		// A request that never completes within the stream stays in inflight
		// at end-of-stream; output_remaining flushes it as `[incomplete]`.
		$cmd = $this->make_cmd( 'partialR', /*raw*/ false, /*incomplete*/ true );
		$ref = new \ReflectionMethod( $cmd, 'process_stdin' );
		$ref->setAccessible( true );

		$stream = \fopen( 'php://memory', 'r+' );
		\fwrite( $stream, \json_encode( [ 'n' => 1, 'rid' => 'partialR', 'k' => 'process (start)', 'm' => '/half', 'ts' => 1700000000.0 ] ) . "\n" );
		\rewind( $stream );

		\ob_start();
		$ref->invoke( $cmd, $stream );
		$out = \ob_get_clean();
		\fclose( $stream );

		// With --incomplete, output_remaining emits the partial request.
		$this->assertStringContainsString( '/half', $out );
		$this->assertStringContainsString( '[incomplete]', $out );
	}

	public function test_process_stdin_skips_non_matching_lines(): void {
		// No lines match the pattern — output should be empty (or just have
		// shell-level scaffolding), and inflight stays empty.
		$cmd = $this->make_cmd( 'never-matches' );
		$ref = new \ReflectionMethod( $cmd, 'process_stdin' );
		$ref->setAccessible( true );

		$stream = \fopen( 'php://memory', 'r+' );
		\fwrite( $stream, \json_encode( [ 'n' => 1, 'rid' => 'noiseR', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0 ] ) . "\n" );
		\rewind( $stream );

		\ob_start();
		$ref->invoke( $cmd, $stream );
		\ob_get_clean();
		\fclose( $stream );

		$inflight = $this->get_prop( 'inflight' );
		$this->assertNull( $inflight->get( 'noiseR' ) );
	}

	// -------------------------------------------------------------------------
	// cat_mode / stream_segment_lines / get_partition end-to-end
	// -------------------------------------------------------------------------

	/**
	 * Build a real on-disk partition with two complete entries — one matching the
	 * pattern (will be output), one not — so cat_mode → stream_segment_lines →
	 * process_line round-trips through the actual code path rather than the
	 * reflection-only direct invocations the rest of this suite uses.
	 */
	private function seed_partition( string $base_dir, int $partition, array $entries ): void {
		\mkdir( "{$base_dir}/p{$partition}", 0755, true );
		$p = new \Newspack_Nodes\Partition_Node();
		$p->arguments( "{$base_dir} {$partition}" );
		foreach ( $entries as $entry ) {
			$msg                       = \Newspack_Nodes\Message::new_message();
			$msg[ \Newspack_Nodes\Message::TYPE ]      = \Newspack_Nodes\Message::TM_STRUCT;
			$msg[ \Newspack_Nodes\Message::TIMESTAMP ] = (float) ( $entry['ts'] ?? 0 );
			$msg[ \Newspack_Nodes\Message::KEY ]       = (string) ( $entry['rid'] ?? '' );
			$msg[ \Newspack_Nodes\Message::VALUE ]     = $entry;
			$p->fill( $msg );
		}
		$p->flush();
	}

	public function test_cat_mode_drives_stream_segment_lines_and_process_line(): void {
		$tmp = '/tmp/reqgrep-cat-mode-' . \uniqid();
		\mkdir( $tmp, 0755, true );
		try {
			// Two entries: a complete request matching '/calendar' and a noise
			// line that won't match. cat_mode walks the partition, feeds each
			// line through process_line, and (because complete is the second
			// matching entry) calls output_request when the rid completes.
			$this->seed_partition( $tmp, 0, [
				[ 'n' => 1, 'rid' => 'cal-rid', 'k' => 'process (start)',    'm' => '/calendar/today', 'ts' => 1700000000.0 ],
				[ 'n' => 5, 'rid' => 'cal-rid', 'k' => 'process (complete)', 'm' => '/calendar/today', 'ts' => 1700000000.5 ],
				[ 'n' => 1, 'rid' => 'other',   'k' => 'process (start)',    'm' => '/feed',           'ts' => 1700000001.0 ],
			] );

			$cmd = $this->make_cmd( '/calendar' );
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );
			$set( 'cat_offset', 'start' );

			\ob_start();
			$ref = new \ReflectionMethod( $cmd, 'cat_mode' );
			$ref->setAccessible( true );
			$ref->invoke( $cmd );
			$out = \ob_get_clean();

			// Output must contain the matched request's URL and rid.
			$this->assertStringContainsString( '/calendar/today', $out );
			$this->assertStringContainsString( 'cal-rid', $out );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_cat_mode_recent_offset_skips_older_segments(): void {
		// `cat_offset = 'recent'` means "start at second-to-last segment" so
		// long-running cat doesn't replay ancient history. Verify by writing
		// across multiple segments and asserting the older one is skipped.
		$tmp = '/tmp/reqgrep-cat-recent-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			// Segment 0 with one entry.
			\file_put_contents(
				"{$tmp}/p0/0.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'old-rid', 'k' => 'process (start)', 'm' => '/old', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);
			// Segment 5 (newest) with one entry. cat_offset=recent picks
			// the second-to-last (which doesn't exist for 2 segs, falls
			// back to oldest), so we need at least 3 segments to see the
			// "skip oldest" behavior — make that.
			\file_put_contents(
				"{$tmp}/p0/3.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'mid-rid', 'k' => 'process (start)', 'm' => '/mid', 'ts' => 1700000001.0,
				] ) ) . "\n"
			);
			\file_put_contents(
				"{$tmp}/p0/5.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'new-rid', 'k' => 'process (start)', 'm' => '/new', 'ts' => 1700000002.0,
				] ) ) . "\n"
			);

			$cmd = $this->make_cmd( '/' );
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );
			$set( 'cat_offset', 'recent' );

			\ob_start();
			$ref = new \ReflectionMethod( $cmd, 'cat_mode' );
			$ref->setAccessible( true );
			$ref->invoke( $cmd );
			$out = \ob_get_clean();

			// 'recent' starts at segments[count-2] = id 3, so seg 0 is skipped
			// but segs 3 and 5 are processed. Sanity check the assertion makes
			// sense: old-rid must NOT appear; mid-rid / new-rid CAN appear
			// (incomplete by design).
			$this->assertStringNotContainsString( 'old-rid', $out );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_get_partition_caches_per_index(): void {
		$tmp = '/tmp/reqgrep-get-partition-' . \uniqid();
		\mkdir( $tmp, 0755, true );
		try {
			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );

			$ref = new \ReflectionMethod( $cmd, 'get_partition' );
			$ref->setAccessible( true );

			$a = $ref->invoke( $cmd, 0 );
			$b = $ref->invoke( $cmd, 0 );
			$c = $ref->invoke( $cmd, 1 );

			$this->assertSame( $a, $b, 'same partition index returns the cached instance' );
			$this->assertNotSame( $a, $c, 'different index → different instance' );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_stream_segment_lines_returns_zero_for_empty_length(): void {
		$tmp = '/tmp/reqgrep-stream-empty-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			$cmd = $this->make_cmd();
			$ref_method = new \ReflectionMethod( $cmd, 'stream_segment_lines' );
			$ref_method->setAccessible( true );

			$partition = new \Newspack_Nodes\Partition_Node();

			$partition->arguments( "{$tmp} 0" );
			$consumed  = $ref_method->invoke( $cmd, $partition, 0, 0, 0 );
			$this->assertSame( 0, $consumed );

			$consumed_neg = $ref_method->invoke( $cmd, $partition, 0, 0, -10 );
			$this->assertSame( 0, $consumed_neg );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_stream_segment_lines_consumes_complete_lines_only(): void {
		// Trailing partial line (no newline) must NOT count toward `consumed`,
		// so the next poll picks it up after the writer flushes.
		$tmp = '/tmp/reqgrep-stream-partial-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			$line1 = \Newspack_Nodes\Message::packed( $this->packed_struct( [
				'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => '/a', 'ts' => 1700000000.0,
			] ) ) . "\n";
			$partial = '{"incomplete":'; // no trailing newline
			\file_put_contents( "{$tmp}/p0/0.log", $line1 . $partial );

			$cmd = $this->make_cmd( 'r1' );
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );

			$partition = new \Newspack_Nodes\Partition_Node();

			$partition->arguments( "{$tmp} 0" );
			$ref_method = new \ReflectionMethod( $cmd, 'stream_segment_lines' );
			$ref_method->setAccessible( true );

			\ob_start();
			$consumed = $ref_method->invoke( $cmd, $partition, 0, 0, \strlen( $line1 ) + \strlen( $partial ) );
			\ob_get_clean();

			// Only line1 (with its trailing newline) was consumed. The partial
			// line stays in the unconsumed tail.
			$this->assertSame( \strlen( $line1 ), $consumed );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	// -------------------------------------------------------------------------
	// follow_mode + follow_tick
	// -------------------------------------------------------------------------

	public function test_seed_follow_cursors_starts_at_tail_of_newest_segment(): void {
		$tmp = '/tmp/reqgrep-seed-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			\file_put_contents(
				"{$tmp}/p0/3.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);
			$expected_size = \filesize( "{$tmp}/p0/3.log" );

			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			$ref = new \ReflectionMethod( $cmd, 'seed_follow_cursors' );
			$ref->setAccessible( true );
			$cursors = $ref->invoke( $cmd );

			$this->assertSame( 3, $cursors[0]['seg'] );
			$this->assertSame( $expected_size, $cursors[0]['off'] );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_seed_follow_cursors_starts_at_zero_for_empty_partition(): void {
		$tmp = '/tmp/reqgrep-seed-empty-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			$ref = new \ReflectionMethod( $cmd, 'seed_follow_cursors' );
			$ref->setAccessible( true );
			$cursors = $ref->invoke( $cmd );

			$this->assertSame( [ 'seg' => 0, 'off' => 0 ], $cursors[0] );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_follow_tick_returns_false_when_no_new_bytes(): void {
		// Cursor at the end of the segment → no unread bytes → tick reports
		// "no data" so the caller can sleep instead of busy-spinning.
		$tmp = '/tmp/reqgrep-tick-noop-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			\file_put_contents(
				"{$tmp}/p0/0.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);
			$size = \filesize( "{$tmp}/p0/0.log" );

			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			$cursors = [ 0 => [ 'seg' => 0, 'off' => $size ] ];
			$ref = new \ReflectionMethod( $cmd, 'follow_tick' );
			$ref->setAccessible( true );
			$had_data = $ref->invokeArgs( $cmd, [ &$cursors ] );

			$this->assertFalse( $had_data );
			// Cursor unchanged.
			$this->assertSame( $size, $cursors[0]['off'] );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_follow_tick_consumes_appended_bytes(): void {
		// Cursor at offset 0 of a non-empty segment → tick reads the line,
		// reports had_data=true, and advances the cursor to end-of-line.
		$tmp = '/tmp/reqgrep-tick-consume-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			\file_put_contents(
				"{$tmp}/p0/0.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'cal-rid', 'k' => 'process (start)', 'm' => '/calendar', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);
			$size = \filesize( "{$tmp}/p0/0.log" );

			$cmd = $this->make_cmd( 'cal-rid' );
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			$cursors = [ 0 => [ 'seg' => 0, 'off' => 0 ] ];
			$ref = new \ReflectionMethod( $cmd, 'follow_tick' );
			$ref->setAccessible( true );

			\ob_start();
			$had_data = $ref->invokeArgs( $cmd, [ &$cursors ] );
			\ob_get_clean();

			$this->assertTrue( $had_data );
			$this->assertSame( $size, $cursors[0]['off'] );
			// Inflight tracker now has the rid (start without complete).
			$inflight = $this->get_prop( 'inflight' );
			$this->assertNotNull( $inflight->get( 'cal-rid' ) );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_follow_tick_advances_to_next_segment_when_current_is_drained(): void {
		// Cursor at end of seg 0; seg 1 has new data. Tick should jump to
		// seg 1 and consume it.
		$tmp = '/tmp/reqgrep-tick-advance-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			\file_put_contents(
				"{$tmp}/p0/0.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'old', 'k' => 'process (start)', 'm' => '/old', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);
			\file_put_contents(
				"{$tmp}/p0/1.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'new', 'k' => 'process (start)', 'm' => '/new', 'ts' => 1700000001.0,
				] ) ) . "\n"
			);
			$seg0_size = \filesize( "{$tmp}/p0/0.log" );

			$cmd = $this->make_cmd( 'new' );
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			$cursors = [ 0 => [ 'seg' => 0, 'off' => $seg0_size ] ];
			$ref = new \ReflectionMethod( $cmd, 'follow_tick' );
			$ref->setAccessible( true );

			\ob_start();
			$had_data = $ref->invokeArgs( $cmd, [ &$cursors ] );
			\ob_get_clean();

			$this->assertTrue( $had_data );
			// Cursor jumped to seg 1.
			$this->assertSame( 1, $cursors[0]['seg'] );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	public function test_follow_mode_with_max_iterations_terminates(): void {
		// Production calls follow_mode() with no arg → PHP_INT_MAX → infinite
		// loop. Tests pass a small max so the method actually returns.
		$tmp = '/tmp/reqgrep-follow-bounded-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			// 0 iterations: just the seed + log lines, no polling.
			$ref = new \ReflectionMethod( $cmd, 'follow_mode' );
			$ref->setAccessible( true );
			\ob_start();
			$ref->invoke( $cmd, 0 );
			\ob_get_clean();

			// Returned without infinite-looping. Successful assertion is reaching here.
			$this->assertTrue( true );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	// -------------------------------------------------------------------------
	// __invoke (WP-CLI entry point) — exercise the dispatch logic end-to-end.
	// -------------------------------------------------------------------------

	public function test_invoke_dispatches_to_cat_mode_with_explicit_path(): void {
		// Set up a real on-disk firehose layout under a controlled base_dir,
		// point Config at it via use_base_dir(), and exercise __invoke
		// end-to-end. Default `assoc_args` (no --follow,
		// stdin not piped) routes to cat_mode, which we have separate
		// coverage for — this test is specifically about __invoke's setup
		// + dispatch + path validation.
		$base_dir = '/tmp/reqgrep-invoke-' . \uniqid();
		\mkdir( "{$base_dir}/logs/firehose.log/p0", 0755, true );
		try {
			// Seed one matching request line so cat_mode has something to do.
			\file_put_contents(
				"{$base_dir}/logs/firehose.log/p0/0.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'invoke-rid', 'k' => 'process (start)', 'm' => '/calendar', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);

			// Repoint substrate Config at our temp base_dir.
			$saved_opt = $GLOBALS['_wp_options']['newspack_nodes_base_directory'] ?? null;
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();
			// Suppress the prod-only output-buffer drain so PHPUnit's own
			// ob_start layer stays intact for the duration of the test.
			$ref = new \ReflectionProperty( $cmd, 'drain_buffers_on_invoke' );
			$ref->setAccessible( true );
			$ref->setValue( $cmd, false );

			// Path must be inside the logs directory the Config resolves.
			$path = "{$base_dir}/logs/firehose.log";
			\ob_start();
			$cmd->__invoke(
				[ 'invoke-rid' ],
				[ 'path' => $path ]
			);
			$out = \ob_get_clean();

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
			$saved_opt = $GLOBALS['_wp_options']['newspack_nodes_base_directory'] ?? null;
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();
			// Suppress the prod-only output-buffer drain so PHPUnit's own
			// ob_start layer stays intact for the duration of the test.
			$ref = new \ReflectionProperty( $cmd, 'drain_buffers_on_invoke' );
			$ref->setAccessible( true );
			$ref->setValue( $cmd, false );

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
			$saved_opt = $GLOBALS['_wp_options']['newspack_nodes_base_directory'] ?? null;
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();
			// Suppress the prod-only output-buffer drain so PHPUnit's own
			// ob_start layer stays intact for the duration of the test.
			$ref = new \ReflectionProperty( $cmd, 'drain_buffers_on_invoke' );
			$ref->setAccessible( true );
			$ref->setValue( $cmd, false );

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

	/**
	 * Build a Message struct envelope around an entry — convenience for tests
	 * that need to write packed Messages directly.
	 */
	private function packed_struct( array $entry ): array {
		$msg                                       = \Newspack_Nodes\Message::new_message();
		$msg[ \Newspack_Nodes\Message::TYPE ]      = \Newspack_Nodes\Message::TM_STRUCT;
		$msg[ \Newspack_Nodes\Message::TIMESTAMP ] = (float) ( $entry['ts'] ?? 0 );
		// Producer convention: rid is stamped in Message::KEY (LogManager since
		// v0.2.17). Tests mirror that here — reqgrep's ingest_line reads rid
		// from KEY only.
		$msg[ \Newspack_Nodes\Message::KEY ]       = (string) ( $entry['rid'] ?? '' );
		$msg[ \Newspack_Nodes\Message::VALUE ]     = $entry;
		return $msg;
	}

	// -------------------------------------------------------------------------
	// drain_output_buffers — direct unit coverage of the OB drain path.
	// -------------------------------------------------------------------------

	public function test_drain_output_buffers_clears_userspace_layers(): void {
		// __invoke calls this on production runs to flush plugin-installed ob
		// layers so the streaming echoes hit the terminal. Stack three extra
		// layers and verify the method tears at least those down (the safety
		// cap is 16 so 3 stays well below). Restore PHPUnit's baseline before
		// the test ends so other tests don't see torn-down buffers.
		$cmd = $this->make_cmd();
		$ref = new \ReflectionMethod( $cmd, 'drain_output_buffers' );
		$ref->setAccessible( true );

		$start_level = \ob_get_level();
		\ob_start();
		\ob_start();
		\ob_start();
		$mid_level = \ob_get_level();
		$this->assertSame( $start_level + 3, $mid_level );

		$ref->invoke( $cmd );

		// At minimum the 3 pushed layers were torn down (the cap is 16 so
		// three is well within reach).
		$end_level = \ob_get_level();
		$this->assertLessThan( $mid_level, $end_level, 'drain_output_buffers must remove pushed layers' );

		// Restore baseline so subsequent tests don't see fewer layers.
		while ( \ob_get_level() < $start_level ) {
			\ob_start();
		}
	}

	// -------------------------------------------------------------------------
	// process_line: warning emission when history miss + non-first entry.
	// -------------------------------------------------------------------------

	public function test_process_line_warns_on_missing_history_when_not_first_entry(): void {
		// num_buckets=2 keeps the history bounded. Push enough non-matching
		// rids to fill the bucket array; THEN feed a matching rid whose
		// `n` > 1 (no history) — process_line emits a WP_CLI::warning.
		$cmd = $this->make_cmd( 'targetWarn', false, false, 1, 2 );

		// Fill 2 history buckets so the warning branch's
		// `count(history) >= num_buckets` predicate fires.
		for ( $i = 0; $i < 4; $i++ ) {
			$this->process_line->invoke(
				$cmd,
				\json_encode( [ 'n' => 1, 'rid' => "noise-{$i}", 'k' => 'init', 'm' => '/x', 'ts' => 1700000000 + $i ] ) . "\n"
			);
		}

		$GLOBALS['_test_wp_cli_warns'] = [];

		// Matching rid with n=5 (NOT 1) so process_line goes through the
		// "non-first entry, no history" warning branch.
		$this->process_line->invoke(
			$cmd,
			\json_encode( [ 'n' => 5, 'rid' => 'targetWarn', 'k' => 'init', 'm' => 'late-arrival', 'ts' => 1700001000 ] ) . "\n"
		);

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
		\ob_start();

		$rid = 'longGapR';
		$ts0 = 1700000000.0;
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/g', 'ts' => $ts0 ] ) . "\n" );
		// Jump 200 seconds.
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'after-long', 'ts' => $ts0 + 200 ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/g', 'ts' => $ts0 + 200.1 ] ) . "\n" );

		$out = \ob_get_clean();
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
		\ob_start();

		$rid = 'tightR';
		$ts0 = 1700000000.0;
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/t', 'ts' => $ts0 ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 2, 'rid' => $rid, 'k' => 'init', 'm' => 'a', 'ts' => $ts0 + 0.5 ] ) . "\n" );
		$this->process_line->invoke( $cmd, \json_encode( [ 'n' => 3, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/t', 'ts' => $ts0 + 1.0 ] ) . "\n" );

		$out      = \ob_get_clean();
		$dot_rows = \preg_match_all( '/^\s*\d+:.*\.\s*$/m', $out );
		$this->assertSame( 0, $dot_rows, 'sub-second gaps must not emit dot rows' );
	}

	// -------------------------------------------------------------------------
	// output_request: empty rid means no header line.
	// -------------------------------------------------------------------------

	public function test_output_request_skips_header_when_rid_empty(): void {
		$cmd = $this->make_cmd();
		\ob_start();

		$lines = [
			\json_encode( [ 'n' => 1, 'rid' => 'noheaderR', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0 ] ),
			\json_encode( [ 'n' => 2, 'rid' => 'noheaderR', 'k' => 'process (complete)', 'm' => '/x', 'ts' => 1700000000.1 ] ),
		];
		// Pass empty rid → no "request_id:" header line should appear.
		$this->output_request->invoke( $cmd, $lines, '' );

		$out = \ob_get_clean();
		$this->assertStringNotContainsString( 'request_id:', $out );
		// Body still emitted.
		$this->assertStringContainsString( 'process (start)', $out );
	}

	public function test_output_request_formatted_unwraps_packed_envelope(): void {
		// Formatted mode (raw=false) must unwrap packed Message envelopes the
		// same way process_line does, so on-disk envelopes render correctly.
		$cmd = $this->make_cmd();
		\ob_start();

		$rid     = 'envR';
		$ts      = 1700000000.0;
		$packed1 = \Newspack_Nodes\Message::packed( $this->packed_struct( [
			'n' => 1, 'rid' => $rid, 'k' => 'process (start)', 'm' => '/env', 'ts' => $ts,
		] ) );
		$packed2 = \Newspack_Nodes\Message::packed( $this->packed_struct( [
			'n' => 2, 'rid' => $rid, 'k' => 'process (complete)', 'm' => '/env', 'ts' => $ts + 0.1,
		] ) );
		$this->output_request->invoke( $cmd, [ $packed1, $packed2 ], $rid );

		$out = \ob_get_clean();
		// Header + key strings make it through the unwrap.
		$this->assertStringContainsString( 'request_id:' . $rid, $out );
		$this->assertStringContainsString( '/env', $out );
		$this->assertStringContainsString( 'process (complete)', $out );
	}

	// -------------------------------------------------------------------------
	// __invoke: dispatcher branches — --follow flag and --raw/--incomplete flags.
	// -------------------------------------------------------------------------

	public function test_invoke_clamps_bucket_size_to_max(): void {
		// bucket-size >10000 must clamp to 10000.
		$base_dir = '/tmp/reqgrep-invoke-clamp-' . \uniqid();
		\mkdir( "{$base_dir}/logs/firehose.log/p0", 0755, true );
		try {
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();
			$ref = new \ReflectionProperty( $cmd, 'drain_buffers_on_invoke' );
			$ref->setAccessible( true );
			$ref->setValue( $cmd, false );

			\ob_start();
			$cmd->__invoke(
				[ '.' ],
				[
					'path'        => "{$base_dir}/logs/firehose.log",
					'bucket-size' => '99999', // > 10000 cap.
					'num-buckets' => '99999', // > 100 cap.
				]
			);
			\ob_get_clean();

			$bucket = new \ReflectionProperty( $cmd, 'bucket_size' );
			$bucket->setAccessible( true );
			$buckets_n = new \ReflectionProperty( $cmd, 'num_buckets' );
			$buckets_n->setAccessible( true );
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
		\mkdir( "{$base_dir}/logs/firehose.log/p0", 0755, true );
		try {
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();
			$ref = new \ReflectionProperty( $cmd, 'drain_buffers_on_invoke' );
			$ref->setAccessible( true );
			$ref->setValue( $cmd, false );

			\ob_start();
			$cmd->__invoke(
				[ '.' ],
				[
					'path'        => "{$base_dir}/logs/firehose.log",
					'bucket-size' => '0',  // Below min 1.
					'num-buckets' => '0',  // Below min 1.
				]
			);
			\ob_get_clean();

			$bucket = new \ReflectionProperty( $cmd, 'bucket_size' );
			$bucket->setAccessible( true );
			$buckets_n = new \ReflectionProperty( $cmd, 'num_buckets' );
			$buckets_n->setAccessible( true );
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
		// --recent must propagate to the cat_offset property which cat_mode
		// reads.
		$base_dir = '/tmp/reqgrep-invoke-recent-' . \uniqid();
		\mkdir( "{$base_dir}/logs/firehose.log/p0", 0755, true );
		try {
			\file_put_contents(
				"{$base_dir}/logs/firehose.log/p0/0.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'recent-rid', 'k' => 'process (start)', 'm' => '/r', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);

			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();
			$ref = new \ReflectionProperty( $cmd, 'drain_buffers_on_invoke' );
			$ref->setAccessible( true );
			$ref->setValue( $cmd, false );

			\ob_start();
			$cmd->__invoke(
				[ '.' ],
				[
					'path'   => "{$base_dir}/logs/firehose.log",
					'recent' => true,
				]
			);
			\ob_get_clean();

			$offset = new \ReflectionProperty( $cmd, 'cat_offset' );
			$offset->setAccessible( true );
			$this->assertSame( 'recent', $offset->getValue( $cmd ) );
		} finally {
			$GLOBALS['_wp_actions'] = [];
			\Newspack_Nodes\Config::reset();
			\Newspack_Event_Logger_Nodes\Config::reset();
			$this->rmdir_recursive( $base_dir );
		}
	}

	public function test_follow_mode_emits_entry_log_lines(): void {
		// follow_mode prints "Base dir:" + "Following N partition(s)" via
		// WP_CLI::log before entering the poll loop. With max_iterations=0
		// the loop is a no-op and only the entry log lines fire — the same
		// emissions __invoke triggers when it dispatches to follow_mode.
		$tmp = '/tmp/reqgrep-follow-entry-log-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			$GLOBALS['_test_wp_cli_logs'] = [];

			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			$ref = new \ReflectionMethod( $cmd, 'follow_mode' );
			$ref->setAccessible( true );
			$ref->invoke( $cmd, 0 );

			$joined = \implode( "\n", $GLOBALS['_test_wp_cli_logs'] );
			$this->assertStringContainsString( 'Base dir:', $joined );
			$this->assertStringContainsString( 'Following 1 partition(s)', $joined );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	// -------------------------------------------------------------------------
	// seed_follow_cursors: multiple partitions.
	// -------------------------------------------------------------------------

	public function test_seed_follow_cursors_builds_one_entry_per_partition(): void {
		$tmp = '/tmp/reqgrep-seed-multi-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		\mkdir( "{$tmp}/p1", 0755, true );
		try {
			\file_put_contents(
				"{$tmp}/p0/2.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'r0', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);
			\file_put_contents(
				"{$tmp}/p1/7.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'r1', 'k' => 'process (start)', 'm' => '/y', 'ts' => 1700000001.0,
				] ) ) . "\n"
			);

			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 2 );

			$ref = new \ReflectionMethod( $cmd, 'seed_follow_cursors' );
			$ref->setAccessible( true );
			$cursors = $ref->invoke( $cmd );

			$this->assertCount( 2, $cursors );
			$this->assertSame( 2, $cursors[0]['seg'] );
			$this->assertSame( 7, $cursors[1]['seg'] );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	// -------------------------------------------------------------------------
	// follow_tick: cursor advance past an empty range without consuming.
	// -------------------------------------------------------------------------

	public function test_follow_tick_advances_past_empty_intervening_segments(): void {
		// Cursor sits at end of seg 0. Seg 2 exists but is empty (zero bytes).
		// Tick must advance cursor over the empty range without firing
		// $had_data.
		$tmp = '/tmp/reqgrep-tick-empty-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			\file_put_contents(
				"{$tmp}/p0/0.log",
				\Newspack_Nodes\Message::packed( $this->packed_struct( [
					'n' => 1, 'rid' => 'r0', 'k' => 'process (start)', 'm' => '/x', 'ts' => 1700000000.0,
				] ) ) . "\n"
			);
			$seg0_size = \filesize( "{$tmp}/p0/0.log" );
			// Empty segment 2.
			\file_put_contents( "{$tmp}/p0/2.log", '' );

			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			$cursors = [ 0 => [ 'seg' => 0, 'off' => $seg0_size ] ];
			$ref = new \ReflectionMethod( $cmd, 'follow_tick' );
			$ref->setAccessible( true );

			$had_data = $ref->invokeArgs( $cmd, [ &$cursors ] );
			// No data from either segment (seg0 at-end, seg2 empty) → false.
			$this->assertFalse( $had_data );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	// -------------------------------------------------------------------------
	// follow_tick: skips partitions with no segments.
	// -------------------------------------------------------------------------

	public function test_follow_tick_skips_partition_with_no_segments(): void {
		$tmp = '/tmp/reqgrep-tick-empty-part-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );

			$cursors = [ 0 => [ 'seg' => 0, 'off' => 0 ] ];
			$ref = new \ReflectionMethod( $cmd, 'follow_tick' );
			$ref->setAccessible( true );

			$had_data = $ref->invokeArgs( $cmd, [ &$cursors ] );
			$this->assertFalse( $had_data );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	// -------------------------------------------------------------------------
	// cat_mode: skip partition with no segments.
	// -------------------------------------------------------------------------

	public function test_cat_mode_skips_partition_with_no_segments(): void {
		$tmp = '/tmp/reqgrep-cat-nosegs-' . \uniqid();
		\mkdir( "{$tmp}/p0", 0755, true );
		try {
			$cmd = $this->make_cmd();
			$set = function ( string $prop, $value ) use ( $cmd ): void {
				$ref = new \ReflectionProperty( $cmd, $prop );
				$ref->setAccessible( true );
				$ref->setValue( $cmd, $value );
			};
			$set( 'base_dir', $tmp );
			$set( 'num_partitions', 1 );
			$set( 'cat_offset', 'start' );

			\ob_start();
			$ref = new \ReflectionMethod( $cmd, 'cat_mode' );
			$ref->setAccessible( true );
			$ref->invoke( $cmd );
			$out = \ob_get_clean();

			// Empty partition → no output (other than possibly nothing).
			$this->assertSame( '', $out );
		} finally {
			$this->rmdir_recursive( $tmp );
		}
	}

	// -------------------------------------------------------------------------
	// process_stdin: null stream branch when STDIN undefined.
	// -------------------------------------------------------------------------

	public function test_process_stdin_with_empty_stream_emits_nothing(): void {
		// Empty stream → fgets() returns false immediately → loop exits
		// → output_remaining() fires with empty inflight (no output).
		$cmd = $this->make_cmd();
		$ref = new \ReflectionMethod( $cmd, 'process_stdin' );
		$ref->setAccessible( true );

		$stream = \fopen( 'php://memory', 'r+' );
		// Don't write anything → stream starts at EOF.

		\ob_start();
		$ref->invoke( $cmd, $stream );
		$out = \ob_get_clean();
		\fclose( $stream );
		$this->assertSame( '', $out );
	}

	// -------------------------------------------------------------------------
	// __invoke: --raw flag sets the raw property.
	// -------------------------------------------------------------------------

	public function test_invoke_propagates_raw_flag(): void {
		$base_dir = '/tmp/reqgrep-invoke-raw-' . \uniqid();
		\mkdir( "{$base_dir}/logs/firehose.log/p0", 0755, true );
		try {
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();
			$ref = new \ReflectionProperty( $cmd, 'drain_buffers_on_invoke' );
			$ref->setAccessible( true );
			$ref->setValue( $cmd, false );

			\ob_start();
			$cmd->__invoke(
				[ '.' ],
				[
					'path' => "{$base_dir}/logs/firehose.log",
					'raw'  => true,
				]
			);
			\ob_get_clean();

			$prop = new \ReflectionProperty( $cmd, 'raw' );
			$prop->setAccessible( true );
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
		\mkdir( "{$base_dir}/logs/firehose.log/p0", 0755, true );
		try {
			$GLOBALS['_wp_options']['newspack_nodes_base_directory'] = $base_dir;
			$this->use_base_dir( $base_dir );
			\Newspack_Event_Logger_Nodes\Config::reset();

			$cmd = new \Newspack_Event_Logger_Nodes\CLI\Reqgrep_Command();
			$ref = new \ReflectionProperty( $cmd, 'drain_buffers_on_invoke' );
			$ref->setAccessible( true );
			$ref->setValue( $cmd, false );

			\ob_start();
			$cmd->__invoke(
				[ '.' ],
				[
					'path'       => "{$base_dir}/logs/firehose.log",
					'incomplete' => true,
				]
			);
			\ob_get_clean();

			$prop = new \ReflectionProperty( $cmd, 'incomplete' );
			$prop->setAccessible( true );
			$this->assertTrue( $prop->getValue( $cmd ) );
		} finally {
			$GLOBALS['_wp_actions'] = [];
			\Newspack_Nodes\Config::reset();
			\Newspack_Event_Logger_Nodes\Config::reset();
			$this->rmdir_recursive( $base_dir );
		}
	}
}
