<?php
/**
 * Tests for Partition_Reader streaming reader.
 *
 * Direct ancestor: newspack-event-logger-plugins FirehoseReaderTest. The reader
 * sits on top of a `Newspack_Nodes\Partition` and tails its segment files.
 *
 * Strategy: tests bypass the Partition write API and drop raw newline-delimited
 * bytes straight into `{partition_dir}/{segment_id}.log`. The reader doesn't
 * unpack message envelopes — it returns whatever line it sees on disk — so this
 * decouples the read-path tests from the write-path machinery (batching, lock
 * acquisition, heartbeat timers) and lets us exercise rotation / catch-up /
 * recovery branches deterministically.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Partition_Reader;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Partition;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Partition_Reader::class )]
class PartitionReaderTest extends TestCase {

	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = $this->make_temp_dir( 'partition-reader-test-' );
	}

	protected function tearDown(): void {
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	/**
	 * Build a Partition rooted at $base_dir and write the given segment files
	 * with the supplied raw content. Returns the Partition.
	 *
	 * Each entry in $segments maps `segment_id => raw_bytes`. Bytes are written
	 * verbatim — caller controls newlines.
	 */
	private function make_partition_with_segments( string $name, array $segments ): Partition {
		$base = "{$this->tmp}/{$name}";
		$pdir = "{$base}/p0";
		\mkdir( $pdir, 0755, true );
		foreach ( $segments as $seg_id => $bytes ) {
			\file_put_contents( "{$pdir}/{$seg_id}.log", $bytes );
		}
		// Force the segment cache to populate so reader's first refresh sees them.
		$p = new Partition( $base, 0 );
		$p->get_segments( true );
		return $p;
	}

	/** Convenience: one segment, lines joined with "\n" + trailing "\n". */
	private function make_partition_with_lines( string $name, array $lines, int $segment_id = 0 ): Partition {
		$bytes = empty( $lines ) ? '' : ( \implode( "\n", $lines ) . "\n" );
		return $this->make_partition_with_segments( $name, [ $segment_id => $bytes ] );
	}

	// =========================================================================
	// next_offset positioning.
	// =========================================================================

	public function test_default_offset_is_start(): void {
		$p      = $this->make_partition_with_lines( 'def', [ 'a', 'b' ] );
		$reader = new Partition_Reader( $p );

		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_next_offset_start(): void {
		$p      = $this->make_partition_with_lines( 'start', [ 'l1', 'l2', 'l3' ] );
		$reader = new Partition_Reader( $p, 'start' );

		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_next_offset_end_lands_on_newest_segment_size(): void {
		$bytes = "alpha\nbravo\n"; // 12 bytes.
		$p     = $this->make_partition_with_segments( 'end', [ 0 => $bytes ] );
		$reader = new Partition_Reader( $p, 'end' );

		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 12, $pos['offset'] );
	}

	public function test_next_offset_end_with_multiple_segments_picks_newest(): void {
		$p = $this->make_partition_with_segments(
			'end-multi',
			[
				0 => "old\n",
				1 => "newer\n",
				2 => "newest-here\n",
			]
		);
		$reader = new Partition_Reader( $p, 'end' );

		$pos = $reader->get_position();
		$this->assertSame( 2, $pos['segment_id'] );
		$this->assertSame( 12, $pos['offset'] );
	}

	public function test_next_offset_end_on_empty_partition(): void {
		// No segment files: next_offset('end') leaves segment_id=0, offset=0.
		$p      = new Partition( "{$this->tmp}/empty-end", 0 );
		$reader = new Partition_Reader( $p, 'end' );

		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_next_offset_recent_single_segment(): void {
		$p      = $this->make_partition_with_lines( 'recent1', [ 'one' ] );
		$reader = new Partition_Reader( $p, 'recent' );

		// Only one segment: 'recent' lands on it (segment[0]).
		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_next_offset_recent_multiple_segments(): void {
		$p = $this->make_partition_with_segments(
			'recent-multi',
			[
				0 => "old\n",
				1 => "second-newest\n",
				2 => "newest\n",
			]
		);
		$reader = new Partition_Reader( $p, 'recent' );

		$pos = $reader->get_position();
		$this->assertSame( 1, $pos['segment_id'], "'recent' should pick second-to-last segment" );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_next_offset_recent_on_empty_partition(): void {
		$p      = new Partition( "{$this->tmp}/empty-recent", 0 );
		$reader = new Partition_Reader( $p, 'recent' );

		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_next_offset_unknown_string_falls_through_to_start(): void {
		// Anything outside { 'start','end','recent' } hits the default branch.
		$p      = $this->make_partition_with_lines( 'unknown', [ 'a', 'b' ] );
		$reader = new Partition_Reader( $p, 'totally-bogus' );

		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_next_offset_explicit_position_array(): void {
		$p      = $this->make_partition_with_lines( 'explicit', [ 'data' ] );
		$reader = new Partition_Reader( $p );

		$reader->next_offset( [ 'segment_id' => 5, 'offset' => 100 ] );

		$pos = $reader->get_position();
		$this->assertSame( 5, $pos['segment_id'] );
		$this->assertSame( 100, $pos['offset'] );
	}

	public function test_next_offset_position_array_missing_keys_defaults_to_zero(): void {
		$p      = $this->make_partition_with_lines( 'partial', [ 'data' ] );
		$reader = new Partition_Reader( $p );

		$reader->next_offset( [] );
		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );
	}

	public function test_next_offset_clamps_negative_offset_below_minus_one(): void {
		$p      = $this->make_partition_with_lines( 'neg-clamp', [ 'data' ] );
		$reader = new Partition_Reader( $p );

		$reader->next_offset( [ 'segment_id' => 0, 'offset' => -100 ] );
		$pos = $reader->get_position();
		$this->assertSame( -1, $pos['offset'], 'Offset below -1 must be clamped to -1' );
	}

	public function test_next_offset_allows_minus_one_sentinel(): void {
		$p      = $this->make_partition_with_lines( 'neg1', [ 'data' ] );
		$reader = new Partition_Reader( $p );

		$reader->next_offset( [ 'segment_id' => 0, 'offset' => -1 ] );
		$pos = $reader->get_position();
		$this->assertSame( -1, $pos['offset'] );
	}

	public function test_next_offset_resets_buffer_and_eof_state(): void {
		$p      = $this->make_partition_with_lines( 'reset-state', [ 'l1', 'l2' ] );
		$reader = new Partition_Reader( $p, 'start' );

		$reader->open();
		$reader->read_line();
		$reader->mark_eof();
		$this->assertTrue( $reader->is_caught_up() );

		// Reset to start: should drop EOF flag, clear line buffer, close handle.
		$reader->next_offset( 'start' );

		$pos = $reader->get_position();
		$this->assertSame( 0, $pos['segment_id'] );
		$this->assertSame( 0, $pos['offset'] );

		$reader->open();
		$line = $reader->read_line();
		$this->assertSame( "l1\n", $line, 'After next_offset(start) the reader rewinds' );
	}

	// =========================================================================
	// open(): segment selection + reuse.
	// =========================================================================

	public function test_open_returns_handle_for_existing_segment(): void {
		$p      = $this->make_partition_with_lines( 'open-ok', [ 'data' ] );
		$reader = new Partition_Reader( $p, 'start' );

		$fh = $reader->open();
		$this->assertIsResource( $fh );
	}

	public function test_open_returns_null_for_empty_partition(): void {
		$p      = new Partition( "{$this->tmp}/open-empty", 0 );
		$reader = new Partition_Reader( $p, 'start' );

		$this->assertNull( $reader->open() );
	}

	public function test_open_reuses_handle_for_same_segment(): void {
		$p      = $this->make_partition_with_lines( 'reuse', [ 'a', 'b' ] );
		$reader = new Partition_Reader( $p, 'start' );

		$h1 = $reader->open();
		$h2 = $reader->open();
		$this->assertSame( $h1, $h2, 'open() on same segment must hand back the cached fh' );
	}

	public function test_open_reuses_handle_after_partial_read(): void {
		$p      = $this->make_partition_with_lines( 'reuse-after-read', [ 'a', 'b' ] );
		$reader = new Partition_Reader( $p, 'start' );

		$h1 = $reader->open();
		$reader->read_line();
		$h2 = $reader->open();
		$this->assertSame( $h1, $h2 );
	}

	public function test_open_seeks_to_offset(): void {
		// "alpha\nbravo\n" — open at offset 6 should land us on "bravo\n".
		$bytes = "alpha\nbravo\n";
		$p     = $this->make_partition_with_segments( 'seek', [ 0 => $bytes ] );

		$reader = new Partition_Reader( $p );
		$reader->next_offset( [ 'segment_id' => 0, 'offset' => 6 ] );
		$reader->open();

		$line = $reader->read_line();
		$this->assertSame( "bravo\n", $line );
	}

	public function test_open_jumps_to_oldest_when_segment_id_not_found(): void {
		// Segments 5,6 exist; reader is told to start at 999 which isn't there.
		$p = $this->make_partition_with_segments(
			'jump',
			[
				5 => "from-five\n",
				6 => "from-six\n",
			]
		);
		$reader = new Partition_Reader( $p );
		$reader->next_offset( [ 'segment_id' => 999, 'offset' => 50 ] );

		$fh = $reader->open();
		$this->assertIsResource( $fh );

		// Reader must have rolled back to the oldest segment with offset reset.
		$this->assertSame( 5, $reader->get_segment_id() );
		$this->assertSame( 0, $reader->get_position()['offset'] );

		$line = $reader->read_line();
		$this->assertSame( "from-five\n", $line );
	}

	public function test_open_returns_null_when_partition_dir_missing(): void {
		// Partition pointed at a directory that doesn't exist — scandir returns
		// false, get_segments returns [], open() short-circuits to null.
		$p      = new Partition( "{$this->tmp}/never-created", 0 );
		$reader = new Partition_Reader( $p, 'start' );

		$this->assertNull( $reader->open() );
	}

	// =========================================================================
	// read_line(): basic + offset tracking + multi-line.
	// =========================================================================

	public function test_read_line_returns_lines_in_order(): void {
		$p      = $this->make_partition_with_lines( 'order', [ 'first', 'second', 'third' ] );
		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		$this->assertSame( "first\n", $reader->read_line() );
		$this->assertSame( "second\n", $reader->read_line() );
		$this->assertSame( "third\n", $reader->read_line() );
	}

	public function test_read_line_returns_null_at_eof(): void {
		$p      = $this->make_partition_with_lines( 'eof', [ 'only' ] );
		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		$this->assertSame( "only\n", $reader->read_line() );
		$this->assertNull( $reader->read_line() );
	}

	public function test_read_line_returns_null_when_not_open(): void {
		$p      = $this->make_partition_with_lines( 'noopen', [ 'data' ] );
		$reader = new Partition_Reader( $p, 'start' );

		$this->assertNull( $reader->read_line(), 'read_line() without open() must return null' );
	}

	public function test_read_line_tracks_offset_byte_accurately(): void {
		// "alpha\nbeta\ngamma\n": 6 + 5 + 6 = 17 bytes.
		$p      = $this->make_partition_with_lines( 'offset', [ 'alpha', 'beta', 'gamma' ] );
		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		$this->assertSame( 0, $reader->get_position()['offset'] );

		$reader->read_line();
		$this->assertSame( 6, $reader->get_position()['offset'] );

		$reader->read_line();
		$this->assertSame( 11, $reader->get_position()['offset'] );

		$reader->read_line();
		$this->assertSame( 17, $reader->get_position()['offset'] );
	}

	public function test_read_line_handles_empty_line(): void {
		// Three records: "before", "", "after" — so the on-disk bytes are
		// "before\n\nafter\n".
		$p      = $this->make_partition_with_lines( 'emptyline', [ 'before', '', 'after' ] );
		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		$this->assertSame( "before\n", $reader->read_line() );
		$this->assertSame( "\n", $reader->read_line() );
		$this->assertSame( "after\n", $reader->read_line() );
	}

	public function test_read_line_returns_null_for_partial_line_without_newline(): void {
		// Bytes without a trailing newline: read_line() can't return a line
		// because there's no terminator yet (writer is mid-flight).
		$p      = $this->make_partition_with_segments( 'partial', [ 0 => 'no-newline-yet' ] );
		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		$this->assertNull( $reader->read_line() );
	}

	public function test_read_line_buffer_then_drains(): void {
		// All three lines arrive in a single fread() call, so subsequent
		// read_line()s pull from the in-memory line buffer rather than re-reading.
		$p      = $this->make_partition_with_lines( 'prebuffered', [ 'aaa', 'bbb', 'ccc' ] );
		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		$this->assertSame( "aaa\n", $reader->read_line() );
		$this->assertSame( "bbb\n", $reader->read_line() );
		$this->assertSame( "ccc\n", $reader->read_line() );
		$this->assertNull( $reader->read_line() );
	}

	public function test_read_line_handles_long_line(): void {
		$long_line = \str_repeat( 'A', 5000 );
		$p         = $this->make_partition_with_lines( 'long', [ $long_line, 'after' ] );
		$reader    = new Partition_Reader( $p, 'start' );
		$reader->open();

		$this->assertSame( $long_line . "\n", $reader->read_line() );
		$this->assertSame( "after\n", $reader->read_line() );
		$this->assertNull( $reader->read_line() );
	}

	public function test_read_line_buffer_overflow_discards_and_resyncs_on_newline(): void {
		// MAX_LINE_BUFFER_SIZE = 20MB. Inject an oversized buffer so the next
		// fread() pushes the accumulator past the cap, exercising the overflow
		// branch in read_line().
		// The next fread of a few bytes containing a newline pushes us over,
		// triggering the discard + resync-on-newline path.
		$p = $this->make_partition_with_segments(
			'dos',
			[ 0 => 'X' . "\n" . "recovered\n" ]
		);

		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		$buf_ref = new \ReflectionProperty( Partition_Reader::class, 'line_buffer' );
		$buf_ref->setAccessible( true );
		// Just shy of the 20MB cap so the next read overflows.
		$buf_ref->setValue( $reader, \str_repeat( 'X', 20971515 ) );

		$line = $reader->read_line();
		$this->assertNull( $line, 'Overflow must return null and discard the buffer' );

		$buf_after = $buf_ref->getValue( $reader );
		$this->assertLessThan( 20971520, \strlen( $buf_after ) );

		// After resync the next call returns the line that landed AFTER the
		// resync newline (the "recovered" record).
		$line2 = $reader->read_line();
		$this->assertSame( "recovered\n", $line2 );
	}

	public function test_read_line_buffer_overflow_no_newline_advances_offset(): void {
		// Same overflow path but the chunk has no newline at all — we discard
		// and bump offset by full chunk length without emitting a line.
		$p = $this->make_partition_with_segments(
			'dos-nonl',
			[ 0 => \str_repeat( 'A', 10 ) ]
		);

		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		$buf_ref = new \ReflectionProperty( Partition_Reader::class, 'line_buffer' );
		$buf_ref->setAccessible( true );
		$buf_ref->setValue( $reader, \str_repeat( 'X', 20971515 ) );

		$line = $reader->read_line();
		$this->assertNull( $line );

		$buf_after = $buf_ref->getValue( $reader );
		$this->assertSame( '', $buf_after, 'Without a newline in the chunk, buffer is fully drained' );
	}

	// =========================================================================
	// EOF + caught-up semantics.
	// =========================================================================

	public function test_is_caught_up_false_before_eof(): void {
		$p      = $this->make_partition_with_lines( 'notyet', [ 'one' ] );
		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		$this->assertFalse( $reader->is_caught_up(), 'Before reaching EOF, is_caught_up must be false' );
	}

	public function test_is_caught_up_after_draining_all_lines(): void {
		$p      = $this->make_partition_with_lines( 'drain', [ 'a', 'b' ] );
		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		while ( null !== $reader->read_line() ) {
			// Drain.
		}
		$this->assertTrue( $reader->is_caught_up() );
	}

	public function test_is_caught_up_on_empty_partition_after_mark_eof(): void {
		$p      = new Partition( "{$this->tmp}/iscu-empty", 0 );
		$reader = new Partition_Reader( $p, 'start' );

		$reader->mark_eof();
		$this->assertTrue( $reader->is_caught_up() );
	}

	public function test_is_caught_up_false_when_newer_segment_exists(): void {
		// Create only segment 0, build a reader pointed at it, then add segment 1.
		$p = $this->make_partition_with_segments( 'newer', [ 0 => "old\n" ] );

		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();
		while ( null !== $reader->read_line() ) {
			// Drain.
		}
		$this->assertTrue( $reader->is_caught_up() );

		// Now write segment 1: caught_up flips false because segment_id (0) <
		// newest['id'] (1).
		\file_put_contents( "{$this->tmp}/newer/p0/1.log", "fresh\n" );
		$p->get_segments( true );

		$this->assertFalse( $reader->is_caught_up() );
	}

	public function test_mark_eof_makes_reader_caught_up_at_end(): void {
		$p      = $this->make_partition_with_lines( 'markeof', [ 'data' ] );
		$reader = new Partition_Reader( $p, 'end' );

		$reader->mark_eof();
		$this->assertTrue( $reader->is_caught_up() );
	}

	// =========================================================================
	// next_segment() — rotation, freshness, reset detection.
	// =========================================================================

	public function test_next_segment_returns_null_on_empty_partition(): void {
		$p      = new Partition( "{$this->tmp}/ns-empty", 0 );
		$reader = new Partition_Reader( $p, 'start' );

		$this->assertNull( $reader->next_segment() );
	}

	public function test_next_segment_advances_when_current_is_stale(): void {
		$p = $this->make_partition_with_segments(
			'advance',
			[
				0 => "first\n",
				1 => "second\n",
			]
		);

		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();
		while ( null !== $reader->read_line() ) {
			// Drain segment 0.
		}

		// Make segment 0 look stale: mtime > 5 seconds in the past.
		$old_path = $p->get_segment_path( 0 );
		\touch( $old_path, \time() - 30 );
		\clearstatcache( true, $old_path );

		$fh = $reader->next_segment();
		$this->assertIsResource( $fh );
		$this->assertSame( 1, $reader->get_segment_id() );

		// New segment open at offset 0 — first line should be "second".
		$this->assertSame( "second\n", $reader->read_line() );
	}

	public function test_next_segment_stays_when_current_is_fresh(): void {
		$p = $this->make_partition_with_segments(
			'stay',
			[
				0 => "old\n",
				1 => "newer\n",
			]
		);

		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();
		// Drain segment 0.
		while ( null !== $reader->read_line() ) {
		}

		// Touch segment 0 to make it look fresh (mtime = now). The "fresh
		// segment, don't advance yet" branch: stale_secs < 1.
		\touch( $p->get_segment_path( 0 ) );
		\clearstatcache( true, $p->get_segment_path( 0 ) );

		$reader->next_segment();
		$this->assertSame( 0, $reader->get_segment_id(), 'Fresh current segment must not advance' );
	}

	public function test_next_segment_stays_when_current_has_unread_and_recent(): void {
		// Two segments. read_line() does 65536-byte fread chunks, so to leave
		// real unread data on disk (file_size > ftell) we need segment 0 to be
		// larger than the read buffer. 200KB of bytes split into ~2KB lines.
		$lines = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$lines[] = \str_repeat( \chr( 97 + ( $i % 26 ) ), 2000 );
		}
		$p = $this->make_partition_with_segments(
			'unread-recent',
			[
				0 => \implode( "\n", $lines ) . "\n",
				1 => "charlie\n",
			]
		);

		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();
		$reader->read_line(); // First fread loaded 65536 bytes; 200KB - 65KB unread on disk.

		// Make segment 0 mtime 2s in the past — recent enough that the
		// "<5s stale + unread bytes" branch defers rotation.
		\touch( $p->get_segment_path( 0 ), \time() - 2 );
		\clearstatcache( true, $p->get_segment_path( 0 ) );

		$reader->next_segment();
		$this->assertSame( 0, $reader->get_segment_id(), 'Recent segment with unread bytes must not advance' );
	}

	public function test_next_segment_advances_when_unread_but_very_stale(): void {
		// Mirror of test_next_segment_stays_when_current_has_unread_and_recent
		// but with a very-stale (>5s) mtime. The "<5s + read_pos<file_size"
		// guard's threshold is bypassed once mtime ages past 5 seconds, so the
		// reader rotates forward even though there are unread bytes on disk.
		$lines = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$lines[] = \str_repeat( \chr( 97 + ( $i % 26 ) ), 2000 );
		}
		$p = $this->make_partition_with_segments(
			'unread-stale',
			[
				0 => \implode( "\n", $lines ) . "\n",
				1 => "charlie\n",
			]
		);

		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();
		$reader->read_line(); // First fread loaded 64KB; ~135KB unread on disk.

		\touch( $p->get_segment_path( 0 ), \time() - 30 );
		\clearstatcache( true, $p->get_segment_path( 0 ) );

		$fh = $reader->next_segment();
		$this->assertIsResource( $fh );
		$this->assertSame( 1, $reader->get_segment_id(), '>5s stale must override the unread-bytes guard' );
	}

	public function test_next_segment_returns_existing_handle_when_no_advance(): void {
		// Single segment: next_segment() should not change segment_id, and
		// must return the same fh resource that open() returned.
		$p = $this->make_partition_with_segments( 'noadv', [ 0 => "only\n" ] );

		$reader = new Partition_Reader( $p, 'start' );
		$h1 = $reader->open();
		$h2 = $reader->next_segment();
		$this->assertSame( $h1, $h2 );
		$this->assertSame( 0, $reader->get_segment_id() );
	}

	public function test_next_segment_does_not_clear_at_eof_on_newest(): void {
		// Regression for SSE heartbeat path: after draining the newest segment,
		// is_caught_up() must remain true after a no-op next_segment() call.
		$p = $this->make_partition_with_segments( 'eof-stable', [ 0 => "line1\nline2\n" ] );

		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();
		while ( null !== $reader->read_line() ) {
			// Drain.
		}
		$this->assertTrue( $reader->is_caught_up() );

		$reader->next_segment();
		$this->assertTrue( $reader->is_caught_up(), 'next_segment() with no successor must not clear at_eof' );
	}

	public function test_next_segment_handles_partition_reset(): void {
		// Reader is on segment 7 (which doesn't exist — simulating a reset
		// where the writer wiped everything and started fresh). Only segments
		// 0 and 1 exist on disk.
		$p = $this->make_partition_with_segments(
			'reset',
			[
				0 => "after-reset\n",
				1 => "second\n",
			]
		);

		$reader = new Partition_Reader( $p );
		$reader->next_offset( [ 'segment_id' => 7, 'offset' => 100 ] );

		// First open() repositions us onto the oldest available since 7 isn't
		// found. Then advance the segment_id to BEYOND the highest, so
		// next_segment() hits the "current segment is gone AND no successor"
		// reset branch.
		$ref = new \ReflectionProperty( Partition_Reader::class, 'segment_id' );
		$ref->setAccessible( true );
		$ref->setValue( $reader, 99 );
		$cur_ref = new \ReflectionProperty( Partition_Reader::class, 'current_segment' );
		$cur_ref->setAccessible( true );
		$cur_ref->setValue( $reader, [ 'id' => 99, 'size' => 0 ] );

		$fh = $reader->next_segment();
		$this->assertIsResource( $fh );
		// Reset jumps to oldest segment (id 0) with offset 0.
		$this->assertSame( 0, $reader->get_segment_id() );
		$this->assertSame( 0, $reader->get_position()['offset'] );
		$this->assertSame( "after-reset\n", $reader->read_line() );
	}

	// =========================================================================
	// update_offset, get_segment_id, get_position, refresh_segments, close.
	// =========================================================================

	public function test_get_segment_id_returns_current(): void {
		$p      = $this->make_partition_with_lines( 'getid', [ 'x' ] );
		$reader = new Partition_Reader( $p, 'start' );

		$this->assertSame( 0, $reader->get_segment_id() );

		$reader->next_offset( [ 'segment_id' => 12, 'offset' => 0 ] );
		$this->assertSame( 12, $reader->get_segment_id() );
	}

	public function test_get_position_returns_struct(): void {
		$p      = $this->make_partition_with_lines( 'getpos', [ 'x' ] );
		$reader = new Partition_Reader( $p, 'start' );

		$reader->next_offset( [ 'segment_id' => 3, 'offset' => 42 ] );
		$pos = $reader->get_position();

		$this->assertSame( [ 'segment_id' => 3, 'offset' => 42 ], $pos );
	}

	public function test_update_offset_syncs_to_ftell(): void {
		$p      = $this->make_partition_with_lines( 'upd', [ 'alpha', 'beta' ] );
		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		$reader->read_line();
		$pos_before = $reader->get_position();

		$reader->update_offset();
		$pos_after = $reader->get_position();

		// update_offset() syncs to ftell, which sits at where read pointer
		// physically is. Must never go backwards relative to read_line offset.
		$this->assertGreaterThanOrEqual( $pos_before['offset'], $pos_after['offset'] );
	}

	public function test_update_offset_no_op_without_open_handle(): void {
		$p      = $this->make_partition_with_lines( 'upd-noop', [ 'data' ] );
		$reader = new Partition_Reader( $p, 'start' );

		$reader->next_offset( [ 'segment_id' => 0, 'offset' => 100 ] );
		$reader->update_offset();

		// With no open handle, update_offset() leaves the field alone.
		$this->assertSame( 100, $reader->get_position()['offset'] );
	}

	public function test_refresh_segments_observes_new_segments(): void {
		// Pre-populate reader's segments cache via construction, then drop a
		// new segment file and verify the explicit refresh_segments() call
		// updates the reader's internal list. Asserts via reflection because
		// the goal is to verify refresh_segments() does what its name says
		// independently of any other code path that also refreshes.
		$p      = $this->make_partition_with_segments( 'refresh', [ 0 => "first\n" ] );
		$reader = new Partition_Reader( $p, 'start' );

		$segs_ref = new \ReflectionProperty( Partition_Reader::class, 'segments' );
		$segs_ref->setAccessible( true );
		$this->assertCount( 1, $segs_ref->getValue( $reader ) );

		\file_put_contents( "{$this->tmp}/refresh/p0/1.log", "second\n" );
		$p->get_segments( true );  // Bust the partition's 0.25s segment cache.

		$reader->refresh_segments();
		$this->assertCount( 2, $segs_ref->getValue( $reader ) );
	}

	public function test_close_safe_to_call_multiple_times(): void {
		$p      = $this->make_partition_with_lines( 'multiclose', [ 'data' ] );
		$reader = new Partition_Reader( $p, 'start' );

		$reader->open();
		$reader->close();
		$reader->close();
		$reader->close(); // Should not throw.

		$this->assertNull( $reader->read_line(), 'After close, read_line must return null' );
	}

	public function test_close_then_reopen_at_saved_position(): void {
		$p      = $this->make_partition_with_lines( 'reopen', [ 'first', 'second', 'third' ] );
		$reader = new Partition_Reader( $p, 'start' );

		$reader->open();
		$this->assertSame( "first\n", $reader->read_line() );

		$saved = $reader->get_position();
		$reader->close();

		$this->assertNull( $reader->read_line(), 'No reads while closed' );

		$reader->next_offset( $saved );
		$reader->open();
		$this->assertSame( "second\n", $reader->read_line() );
	}

	public function test_destructor_does_not_throw(): void {
		$p      = $this->make_partition_with_lines( 'destruct', [ 'data' ] );
		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		// Force garbage collection of the reader.
		unset( $reader );

		// Reaching here means __destruct ran cleanly.
		$this->assertTrue( true );
	}

	// =========================================================================
	// Position save/restore round-trip.
	// =========================================================================

	public function test_position_save_and_restore_across_readers(): void {
		$p = $this->make_partition_with_lines( 'savepos', [ 'l1', 'l2', 'l3' ] );

		$r1 = new Partition_Reader( $p, 'start' );
		$r1->open();
		$r1->read_line(); // Past l1.

		$saved = $r1->get_position();
		$this->assertSame( 0, $saved['segment_id'] );
		$this->assertSame( 3, $saved['offset'] );  // "l1\n" = 3 bytes.

		// New reader resumes from saved position.
		$r2 = new Partition_Reader( $p, 'start' );
		$r2->next_offset( $saved );
		$r2->open();

		$this->assertSame( "l2\n", $r2->read_line() );
		$this->assertSame( "l3\n", $r2->read_line() );
		$this->assertNull( $r2->read_line() );
	}

	public function test_read_across_multiple_segments_via_next_segment(): void {
		$p = $this->make_partition_with_segments(
			'multi',
			[
				0 => "seg0-line0\nseg0-line1\n",
				1 => "seg1-line0\nseg1-line1\n",
				2 => "seg2-line0\n",
			]
		);

		$reader = new Partition_Reader( $p, 'start' );
		$reader->open();

		$collected = [];

		// Drain three segments by hand. Touch the segment we just finished
		// so next_segment() considers it stale.
		for ( $seg = 0; $seg < 3; $seg++ ) {
			while ( null !== ( $line = $reader->read_line() ) ) {
				$collected[] = \rtrim( $line, "\n" );
			}
			\touch( $p->get_segment_path( $seg ), \time() - 30 );
			\clearstatcache( true, $p->get_segment_path( $seg ) );
			$reader->next_segment();
		}

		$this->assertSame(
			[ 'seg0-line0', 'seg0-line1', 'seg1-line0', 'seg1-line1', 'seg2-line0' ],
			$collected
		);
	}
}
