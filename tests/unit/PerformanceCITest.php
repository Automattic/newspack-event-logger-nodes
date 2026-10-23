<?php
/**
 * PerformanceCITest: the `performance` service CI's verbs.
 *
 * Substrate config (num_partitions, min_lifetime, base_directory) is seeded
 * via TestCase::use_base_dir(). `overview`, `urls`, `dump_url` and the URL
 * briefs read the `flame-facts` rows `FactsSeeder` writes.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\App\Stats_Query;
use Newspack_Event_Logger_Nodes\App\Url_Snapshot;
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Settings_Event_Writer;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;

/**
 * The `performance` service CI's verbs and loaders.
 */
#[CoversClass( Performance_CI_Node::class )]
// Under coverage the urls fan-out and the first, class-loading test cost the most here.
class PerformanceCITest extends TestCase {

	use FactsSeeder;

	private string $tmp;

	/** Seconds into its hour every reply's tick falls: seven buckets have closed. */
	private const INTO_HOUR = 37 * 60 + 40;

	protected function setUp(): void {
		parent::setUp();
		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS,
		// matching SettingsCITest / EventsCITest.
		$this->tmp  = '/tmp/performance-ci-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => 7200 ] );
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = true;
		// Reset the hook-categorizer static caches and the WP hook globals
		// so each hooks_* verb test sees a clean room.
		Hook_Categorizer::clear_cache();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_actions, $wp_filter;
		$wp_actions = [];
		$wp_filter  = [];
		// The disk verbs resolve their partitions from the ACTIVE topology's
		// declaration, so the tests need one active. One worker keeps every
		// existing partition-0 assertion true. AFTER the $wp_filter reset above
		// — it registers the catalog filter.
		$this->activate_shipped( 'performance', 1 );
		// A reply reads the current hour in buckets: pin one well inside it.
		$at          = (int) \microtime( true ) - (int) \microtime( true ) % Bucket::HOUR_SECONDS + self::INTO_HOUR;
		Core::$clock = static fn (): float => (float) $at;
		Core::right_now();
	}

	protected function tearDown(): void {
		\Newspack_Nodes\Topology_Registry::reset_basename_cache();
		VerbHarness::reset();
		Core::$clock                        = null;
		Settings_Event_Writer::$append_seam = null;
		unset( $GLOBALS['_test_fire_option_actions'] );
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = false;
		Hook_Categorizer::clear_cache();
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_actions, $wp_filter;
		$wp_actions = [];
		$wp_filter  = [];
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Test helpers — disk-seeded request and flame records, their index
	// entries, and each request's row naming where its record sits.
	// -------------------------------------------------------------------------

	/** Bytes already appended per scratch segment path — the append offset. */
	private array $segment_bytes = [];
	/**
	 * Append one record to a scratch `{log}.p{N}` segment, and its index line
	 * where the log keeps one, and return where it sits. Requests keep none;
	 * flames keep the `flame-index` line.
	 *
	 * APPENDS. Rewriting the whole segment per record made seeding O(n^2) in
	 * bytes, which the cap tests pay 500 times over; the offset ledger is what
	 * lets the write be an append without re-reading to find the end.
	 *
	 * @param string   $log       Log basename ('requests' | 'flames').
	 * @param ?callable $format   `format_index_entry( array $message, array $position ): ?string`, or null for no index.
	 * @param array    $body      Record VALUE.
	 * @param int      $partition Partition index.
	 * @return array{segment:int, offset:int, length:int} The record's place.
	 */
	private function write_indexed( string $log, ?callable $format, array $body, int $partition ): array {
		$segment_dir = $this->tmp . "/logs/{$log}.p{$partition}";
		if ( ! \is_dir( $segment_dir ) ) {
			\mkdir( $segment_dir, 0755, true );
		}

		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = (float) ( $body['timestamp'] ?? self::tick() );
		$message[ Message::KEY ]       = Core::as_string( $body['rid'] ?? '' );
		$message[ Message::VALUE ]     = $body;
		$packed                        = Message::packed( $message );

		$seg_path = "{$segment_dir}/0.log";
		$offset   = $this->segment_bytes[ $seg_path ] ?? 0;
		\file_put_contents( $seg_path, $packed, FILE_APPEND | LOCK_EX );
		$this->segment_bytes[ $seg_path ] = $offset + \strlen( $packed );

		$position   = [ 'segment' => 0, 'offset' => $offset, 'length' => \strlen( $packed ) ];
		$index_line = null === $format ? null : $format( $message, $position );
		if ( null !== $index_line && '' !== $index_line ) {
			\file_put_contents( "{$segment_dir}/0.idx", $index_line . "\n", FILE_APPEND | LOCK_EX );
			self::appended_at_the_tick( "{$segment_dir}/0.idx" );
		}
		return $position;
	}

	/**
	 * Date an index's last append at the reply's tick, as the builder appends
	 * a flame no earlier than the tick its request's row finished by.
	 */
	private static function appended_at_the_tick( string $index ): void {
		\touch( $index, (int) Core::$now );
	}

	/**
	 * Lines no lookup matches, appended to a flame index at the tick: enough
	 * that a walk reading them all runs past MAX_SCAN_S on a stepping clock.
	 */
	private function pad_flame_index( int $partition = 0 ): void {
		$index = $this->tmp . "/logs/flames.p{$partition}/0.idx";
		\file_put_contents( $index, \str_repeat( "x\n", ( Performance_CI_Node::MAX_SCAN_S + 3 ) * self::clock_stride() ), FILE_APPEND | LOCK_EX );
		self::appended_at_the_tick( $index );
	}

	/**
	 * A completed request as production stores it: its record in
	 * `requests.p{N}`, keyed by its rid, and its row naming where it sits.
	 */
	private function write_request( array $body, int $partition = 0 ): string {
		$this->seed_request( [ 'rid' => $body['rid'] ] + $this->write_record( $body, $partition ), $partition );
		return Core::as_string( $body['rid'] );
	}

	/**
	 * A request's record with no row, as one the rows have not reached or
	 * have aged past.
	 *
	 * @return array{segment:int, offset:int, length:int} The record's place.
	 */
	private function write_record( array $body, int $partition = 0 ): array {
		// The request line that gives a record its url gives it a method too.
		return $this->write_indexed( 'requests', null, $body + [ 'request_method' => 'GET' ], $partition );
	}

	private function write_flame( array $body, int $partition = 0 ): string {
		$this->write_indexed( 'flames', Flame_Builder_Node::format_index_entry( ... ), $body, $partition );
		return Core::as_string( $body['rid'] );
	}

	/**
	 * Seed a firehose partition segment with packed Message envelopes (rid at
	 * Message::KEY, entry hash at Message::VALUE) — newline-delimited, the layout
	 * the substrate Consumer line-splits. Mirrors Log_Manager's on-disk shape.
	 *
	 * @param int                              $partition Partition index.
	 * @param array<int, array<string, mixed>> $entries   Firehose entry hashes (each carries `rid`).
	 */
	private function write_firehose( int $partition, array $entries ): void {
		$dir = $this->tmp . "/logs/firehose.p{$partition}";
		if ( ! \is_dir( $dir ) ) {
			\mkdir( $dir, 0755, true );
		}
		$buffer = '';
		foreach ( $entries as $entry ) {
			$message                       = Message::new_message();
			$message[ Message::TYPE ]      = Message::TM_STRUCT;
			$message[ Message::TIMESTAMP ] = (float) ( $entry['ts'] ?? self::tick() );
			$message[ Message::KEY ]       = (string) ( $entry['rid'] ?? '' );
			$message[ Message::VALUE ]     = $entry;
			$buffer                       .= Message::packed( $message ) . "\n";
		}
		\file_put_contents( "{$dir}/0.log", $buffer, LOCK_EX );
	}

	/** A server none of whose requests timed has a null board mean too. */
	public function test_a_server_with_no_timed_request_has_a_null_board_mean(): void {
		$this->seed_request( [ 'server' => 'edge-takahe.test', 'ms' => null, 'error' => 'T' ] );
		$this->seed_request( [ 'server' => 'edge-kea.test', 'ms' => 41.5 ] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--server=edge-takahe.test' )['leaderboard'];

		$this->assertNull( $board['avg_ms'] );
	}

	/**
	 * The overview names the slots it drew, the `min_lifetime` span rounded
	 * up to whole buckets, so the dashboard's axis comes from the reply and
	 * never from the browser's clock.
	 */
	public function test_the_overview_names_the_spans_slots(): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'min_lifetime' => 3900 ] );
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$slots = $reply['slots'] ?? [];
		$this->assertCount( 13, $slots, '3,900 seconds is 13 buckets, the last one part-filled' );
		$this->assertSame( [ '2026-09-29-14-35', '2026-09-29-14-30', '2026-09-29-13-35' ], [ $slots[0], $slots[1], $slots[12] ] );
		$this->assertArrayNotHasKey( 'plan', $reply );
	}

	/** A selection holds at most the span: 13 buckets parse, 14 are refused. */
	public function test_a_selection_past_the_span_is_refused(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/kea/' ] );
		$this->use_min_lifetime( 3900 );
		$latest = Bucket::id( self::tick() );
		$thirteen = '--bucket=' . Bucket::key( $latest - 12 ) . '..' . Bucket::key( $latest + 1 );
		$fourteen = '--bucket=' . Bucket::key( $latest - 13 ) . '..' . Bucket::key( $latest + 1 );

		$this->assertIsArray( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ $thirteen ] ) );
		foreach ( [ 'urls' => [ $fourteen ], 'dump_url' => [ Url_Parts::hash( 'https://seed.example/kea/' ), $fourteen ] ] as $verb => $args ) {
			$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, $args );
			$this->assertSame( 'bucket selection holds more than 13 buckets', \trim( Core::as_string( $reply ) ), $verb );
		}
	}

	/**
	 * A `min_lifetime` short of one bucket makes every stats read answer an
	 * error naming the key, never a span of its own.
	 *
	 * @param int $seconds The refused lifetime.
	 */
	#[DataProvider( 'spans_short_of_a_bucket' )]
	public function test_a_span_short_of_a_bucket_is_an_error_naming_the_key( int $seconds ): void {
		$this->seed_request( [ 'url' => 'https://seed.example/kea/' ] );
		$this->use_min_lifetime( $seconds );

		foreach ( [ 'overview' => [], 'urls' => [], 'query' => [ '--source=requests', '--measure=count' ] ] as $verb => $args ) {
			$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, $args );
			$this->assertIsString( $reply, $verb );
			$this->assertStringContainsString( "min_lifetime {$seconds} is below the 300-second floor of one stats bucket", $reply, $verb );
		}
	}

	/** @return array<string,array{0:int}> */
	public static function spans_short_of_a_bucket(): array {
		return [
			'zero'           => [ 0 ],
			'a second short' => [ 299 ],
		];
	}

	/**
	 * Every description naming a stats span names the one `min_lifetime`
	 * sets: 13 buckets at 3,900 seconds, kept 25.
	 */
	public function test_the_verb_descriptions_name_the_span_min_lifetime_sets(): void {
		$this->use_min_lifetime( 3900 );
		$verbs = \array_column( Performance_CI_Node::node_schema()['commands'], null, 'name' );
		$arg   = static fn ( string $verb, string $name ): string => \array_column( $verbs[ $verb ]['args'], 'description', 'name' )[ $name ];

		$this->assertStringContainsString( 'The site over the last 13 buckets', $verbs['overview']['description'] );
		$this->assertStringContainsString( 'at most 13 buckets', $arg( 'urls', 'bucket' ) );
		$this->assertStringContainsString( 'empty is the last 13', $arg( 'urls', 'bucket' ) );
		$this->assertStringContainsString( '25 buckets for either source; empty is the last 13', $arg( 'query', 'bucket' ) );
		$this->assertStringContainsString( '`stats` over the last 13 buckets', $verbs['dump_url']['description'] );
		$this->assertStringContainsString( 'its category profile over the 25 buckets the timings keep', $verbs['dump_url']['description'] );
		$this->assertStringContainsString( 'aged past the 25 buckets the rows keep', $verbs['dump_request']['description'] );
		$this->assertStringContainsString( 'at least `min_lifetime` unless `max_segments` prunes it sooner', $verbs['dump_request']['description'] );
		$this->assertDoesNotMatchRegularExpression( '/\b(288|2,016|300)\b|24 hours|24-hour|25 hours|25-hour|seven days/', (string) \wp_json_encode( $verbs ) );
	}

	public function test_overview_verb_rejects_unauthorized(): void {
		// A caller without the capability is refused.
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	public function test_urls_verb_returns_envelope_when_empty(): void {
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'rows', $result );
		$this->assertArrayHasKey( 'limit', $result );
		$this->assertArrayHasKey( 'offset', $result );
		$this->assertSame( [], $result['data'] );
		$this->assertSame( 0, $result['rows'] );
		$this->assertTrue( $result['available'] );
	}

	public function test_urls_verb_default_limit_is_50(): void {
		// The default `limit` is 50.
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertSame( 50, $result['limit'] );
	}

	/**
	 * The hash gate is `D`-anchored: `$` matches before a trailing newline.
	 *
	 * The hash is client-supplied and becomes a memcache key through `row()`,
	 * so a name carrying a newline reaches a protocol-oriented sink — the same
	 * defect as the substrate's `HANDLER_NAME_PATTERN`, on a different gate.
	 */
	public function test_dump_url_refuses_a_hash_with_a_trailing_newline(): void {
		// An explicit token array: the harness whitespace-SPLITS a string arg,
		// which would eat the newline before the gate ever sees it.
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ "a1b2c3d4\n" ] );

		$this->assertSame( "invalid hash format\n", $result );
	}

	/**
	 * The owners ride the record, as the request builder measured them on
	 * its span stack: a flame whose raised values would say otherwise is not
	 * read (decision 12).
	 */
	public function test_dump_request_answers_each_owners_time_off_the_record(): void {
		$rid = $this->write_request(
			[
				'rid'         => 'rid-owners-5530917',
				'url'         => 'https://example.com/owners',
				'timestamp'   => 1700005100,
				'duration_ms' => 47.5,
				'owners'      => [ 'core' => [ 9.0, 2 ], 'kea-7746' => [ 24.0, 1 ], '(unmeasured)' => [ 14.5, 0 ] ],
			]
		);
		$this->write_flame( [ 'rid' => $rid, 'name' => 'request', 'value' => 725.0, 'children' => [ [ 'name' => 'the_content hook: [kea-7746] Kea->render', 'value' => 700.0, 'children' => [] ] ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );

		$this->assertEquals(
			[
				[ 'owner' => 'kea-7746', 'ms' => 24.0, 'share' => 24.0 / 47.5, 'calls' => 1 ],
				[ 'owner' => 'core', 'ms' => 9.0, 'share' => 9.0 / 47.5, 'calls' => 2 ],
			],
			$result['owners']
		);
	}

	/** A timed-out request's duration is not a sample, so it answers no owners (decision 24). */
	public function test_dump_request_answers_no_owners_for_an_untimed_request(): void {
		$rid = $this->write_request(
			[
				'rid'          => 'rid-owners-timed-out-6641',
				'url'          => 'https://example.com/owners',
				'timestamp'    => 1700005100,
				'duration_ms'  => 360000.0,
				'error_status' => 'T',
				'last_log_ts'  => 1700005124.5,
				'owners'       => [ 'kea-7746' => [ 24.0, 1 ], '(unmeasured)' => [ 359976.0, 0 ] ],
			]
		);

		$this->assertSame( [], VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid )['owners'] );
	}

	public function test_the_flame_scan_matches_the_rid_column_and_only_that_rid(): void {
		// Same pre-filter, the other index format: its own writer owns the
		// offsets, so a flame belonging to a neighbouring rid stays unmatched.
		$rid = $this->write_request( [
			'rid'         => 'rid-flame-column-773311',
			'url'         => 'https://example.com/flame-column',
			'timestamp'   => 1700005100,
			'duration_ms' => 41,
		] );
		$this->write_flame( [
			'rid'   => 'rid-flame-column-OTHER1',
			'flame' => [ 'name' => 'wrong', 'value' => 3, 'children' => [] ],
		] );
		$this->write_flame( [
			'rid'   => $rid,
			'flame' => [ 'name' => 'right', 'value' => 41, 'children' => [] ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );

		$this->assertSame( 'right', $result['flame_data']['flame']['name'] );
		$this->assertFalse( $result['flame_cut'] );
	}

	/**
	 * Each `$span` span `$work` writes on a started record over this test's
	 * tree: its category, its `l` and its `m`, and the entries before it.
	 *
	 * @param string              $span   A `Flame_Tree` span name.
	 * @param \Closure(): void    $work   What to log.
	 * @param array<string,mixed> $extras Config beside logging.
	 * @return array{0: list<array{0:mixed,1:mixed,2:mixed}>, 1: list<array<string,mixed>>}
	 */
	private function logged_spans( string $span, \Closure $work, array $extras = [] ): array {
		$entries = $this->logged_in( $this->tmp, $work, $extras );
		return [ self::spans_of( $entries, $span ), $entries ];
	}

	/** A flame lookup is a span naming its partition, closing on how many of its rids it found. */
	public function test_a_logged_flame_lookup_names_its_partition_and_rids_and_what_it_found(): void {
		[ $spans ] = $this->logged_spans(
			Flame_Tree::FLAME_LOOKUP,
			function (): void {
				$rid = $this->write_request( [ 'rid' => 'rid-kokako-lookup-4417', 'url' => 'https://example.com/kokako-4417', 'timestamp' => 1700005100, 'duration_ms' => 41 ] );
				$this->write_flame( [ 'rid' => $rid, 'flame' => [ 'name' => 'kokako', 'value' => 41, 'children' => [] ] ] );
				VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );
			}
		);

		$this->assertSame( [ [ 'flame lookup (start)', 'flames.p0', null ], [ 'flame lookup (complete)', null, '1 of 1 found' ] ], $spans );
	}

	/** A lookup the deadline cut closes `cut`, never as a count of what it had found. */
	public function test_a_logged_flame_lookup_the_deadline_cut_closes_cut(): void {
		[ $spans ] = $this->logged_spans(
			Flame_Tree::FLAME_LOOKUP,
			function (): void {
				$rid = $this->write_request( [ 'rid' => 'rid-hihi-cut-4418', 'url' => 'https://example.com/hihi-4418', 'timestamp' => 1700006300, 'duration_ms' => 23 ] );
				$this->write_flame( [ 'rid' => $rid, 'flame' => [ 'name' => 'hihi', 'value' => 23, 'children' => [] ] ] );
				$this->pad_flame_index();
				$reads       = 0;
				Core::$clock = static function () use ( &$reads ): float {
					return (float) ++$reads;
				};
				VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );
			}
		);

		$this->assertSame( [ [ 'flame lookup (start)', 'flames.p0', null ], [ 'flame lookup (complete)', null, 'cut' ] ], $spans );
	}

	/** Each firehose partition `grep_requests` drains is a span naming it, closing on the lines it read. */
	public function test_a_logged_grep_scans_each_firehose_partition_as_a_span(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'grepKaka1', 'k' => 'request', 'm' => 'GET https://kea.test/kaka-6612', 'ts' => 1700000000.0, 'n' => 1 ],
			[ 'rid' => 'grepKaka1', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.4, 'n' => 2 ],
			[ 'rid' => 'grepKaka2', 'k' => 'request', 'm' => 'GET https://kea.test/feed', 'ts' => 1700000001.0, 'n' => 1 ],
		] );

		[ $spans, $entries ] = $this->logged_spans(
			Flame_Tree::GREP_SCAN,
			static function (): void {
				VerbHarness::fire( new Performance_CI_Node(), 'performance', 'grep_requests', '/kaka-6612' );
			}
		);

		$read = \array_search( 'grep scan (complete)', \array_column( $entries, 'k' ), true );
		$this->assertSame( [ [ 'grep scan (start)', 'firehose.p0', null ], [ 'grep scan (complete)', null, "{$read} lines" ] ], $spans, 'every line ahead of the close is read' );
	}

	/** The partition the budget ran out in closes `cut`, and the next is never opened. */
	public function test_a_logged_grep_the_budget_cut_closes_cut_and_opens_no_further_partition(): void {
		$entries = [];
		for ( $i = 0; $i < ( Performance_CI_Node::MAX_SCAN_S + 3 ) * self::clock_stride(); $i++ ) {
			$entries[] = [ 'rid' => "noise{$i}", 'k' => 'request', 'm' => 'GET https://kea.test/feed', 'ts' => 1700000000.0, 'n' => 1 ];
		}
		$this->write_firehose( 0, $entries );
		$this->write_firehose( 1, [ [ 'rid' => 'lateKaka', 'k' => 'request', 'm' => 'GET https://kea.test/kaka-late-6613', 'ts' => 1700000900.0, 'n' => 1 ] ] );

		[ $spans ] = $this->logged_spans(
			Flame_Tree::GREP_SCAN,
			static function (): void {
				$seconds     = 0.0;
				Core::$clock = static function () use ( &$seconds ): float {
					return $seconds += 1.0;
				};
				VerbHarness::fire( new Performance_CI_Node(), 'performance', 'grep_requests', '/kaka-late-6613' );
			},
			[ 'num_partitions' => 2 ]
		);

		$this->assertCount( 2, $spans );
		$this->assertSame( [ 'grep scan (start)', 'firehose.p0', null ], $spans[0] );
		$this->assertMatchesRegularExpression( '/^\d+ lines · cut$/', Core::as_string( $spans[1][2] ) );
	}

	/** A rid is looked up in its row; no index is walked. */
	public function test_search_requests_answers_from_the_row(): void {
		$place = $this->write_record( [ 'rid' => 'rid-row-kea-5513', 'url' => 'https://example.com/row-kea-5513' ] );
		$this->seed_request( [ 'rid' => 'rid-row-kea-5513', 'url' => 'https://seed.example/row-kea-5513/' ] + $place );

		$found = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', 'rid-row-kea-5513' );

		$this->assertSame( [ 'rid' => 'rid-row-kea-5513', 'url_hash' => Log_Manager::url_hash( 'https://seed.example/row-kea-5513/' ) ], $found );
	}

	/** What search finds, `dump_request` opens: a rid no row names is not found. */
	public function test_search_requests_finds_no_rid_no_row_names(): void {
		$this->write_record( [ 'rid' => 'rid-rowless-5514', 'url' => 'https://example.com/rowless-5514' ] );

		$searched = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', 'rid-rowless-5514' );

		$this->assertSame( 'Request not found: rid=rid-rowless-5514', \trim( Core::as_string( $searched ) ) );
	}

	/** An outage is named as one, never read as an absent request. */
	public function test_search_requests_names_an_outage_rather_than_an_absence(): void {
		$rid = $this->write_request( [ 'rid' => 'rid-dark-5515', 'url' => 'https://example.com/dark-5515' ] );
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Nodes\Config::reset();

		$searched = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', $rid );

		$this->assertSame( 'no request rid-dark-5515: the stats rows did not open', \trim( Core::as_string( $searched ) ) );
	}

	/** Lines the index walk reads between two looks at the clock. */
	private static function clock_stride(): int {
		return (int) ( new \ReflectionClassConstant( Performance_CI_Node::class, 'SCAN_CLOCK_STRIDE' ) )->getValue();
	}

	/** The flame lookup spends MAX_SCAN_S at most; the record answers without it. */
	public function test_dump_request_a_flame_lookup_past_max_scan_s_answers_no_flame(): void {
		$this->write_request( [
			'rid'         => 'rid-deep-flame-73920184665021',
			'url'         => 'https://example.com/deep-flame-here',
			'timestamp'   => 1700006300,
			'duration_ms' => 23,
		] );
		$this->write_flame( [
			'rid'   => 'rid-deep-flame-73920184665021',
			'flame' => [ 'name' => 'deep', 'value' => 23, 'children' => [] ],
		] );
		$this->pad_flame_index();
		$reads       = 0;
		Core::$clock = static function () use ( &$reads ): float {
			return (float) ++$reads;
		};

		$record = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-deep-flame-73920184665021' );

		$this->assertSame( 'https://example.com/deep-flame-here', $record['url'] );
		$this->assertArrayNotHasKey( 'flame_data', $record );
		$this->assertTrue( $record['flame_cut'], 'a cut lookup is not a missing flame' );
	}

	/**
	 * A flame segment whose index took its last line before the request
	 * finished cannot hold its flame, so the lookup ends there uncut.
	 */
	public function test_a_flame_lookup_ends_at_a_segment_closed_before_the_request_finished(): void {
		$rid = $this->write_request( [ 'rid' => 'rid-tui-closed-6621', 'url' => 'https://example.com/tui-closed-6621' ] );
		$this->write_flame( [ 'rid' => 'rid-tui-other-6621', 'flame' => [ 'name' => 'other', 'value' => 7, 'children' => [] ] ] );
		$this->pad_flame_index();
		\touch( $this->tmp . '/logs/flames.p0/0.idx', (int) Core::$now - 3 * Bucket::HOUR_SECONDS );
		$reads       = 0;
		Core::$clock = static function () use ( &$reads ): float {
			return (float) ++$reads;
		};

		$record = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );

		$this->assertArrayNotHasKey( 'flame_data', $record );
		$this->assertFalse( $record['flame_cut'] );
		$this->assertLessThan( 3, $reads, 'no line of the closed segment was timed' );
	}

	/** A row whose place holds another request's record names no request. */
	public function test_dump_request_refuses_a_place_holding_another_rids_record(): void {
		$place = $this->write_record( [ 'rid' => 'rid-weka-other-6620', 'url' => 'https://example.com/weka-other-6620' ] );
		$this->seed_request( [ 'rid' => 'rid-weka-asked-6620' ] + $place );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-weka-asked-6620' );

		$this->assertSame( 'Request not found: rid=rid-weka-asked-6620', \trim( Core::as_string( $reply ) ) );
	}

	/** A span under a request whose flame lookup was cut names the timeout. */
	public function test_a_span_under_a_request_whose_flame_lookup_was_cut_says_so(): void {
		$rid = $this->write_request( [ 'rid' => 'rid-ruru-cut-6622', 'url' => 'https://example.com/ruru-cut-6622', 'request_url' => 'https://example.com/ruru-cut-6622' ] );
		$this->write_flame( [ 'rid' => $rid, 'name' => 'request', 'value' => 9, 'children' => [ [ 'name' => 'wp_loaded', 'value' => 9, 'children' => [] ] ] ] );
		$this->pad_flame_index();
		$seconds     = 0.0;
		Core::$clock = static function () use ( &$seconds ): float {
			return $seconds += 1.0;
		};

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', "request:{$rid}" ] );

		$this->assertSame( "no span 'wp_loaded' in this request: its flame lookup ran out of time", \trim( Core::as_string( $reply ) ) );
	}

	/**
	 * The page's own brief answers for the page as it is being read: the same
	 * server, the same url filters. Asked with none, it answers for the fleet.
	 */
	public function test_ask_overview_answers_for_the_scope_it_is_given(): void {
		$this->write_request( [
			'rid' => 'ovrid00000000001', 'url' => 'https://example.test/slow',
			'duration_ms' => 900.0, 'server_name' => 'alpha.example',
		] );

		$fleet = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site' );

		$this->assertSame( 'overview', $fleet['subject'] );
		$this->assertSame( 'every server', $fleet['scope'] );
		$this->assertSame( '', $fleet['filters']['search'] );

		// One graph per fire: the harness registers `_router` each time.
		VerbHarness::reset();
		$scoped = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			'overview:site --server=alpha.example --search=slow --include_workers=1'
		);

		$this->assertSame( 'alpha.example', $scoped['server'] );
		$this->assertSame( 'slow', $scoped['filters']['search'] );
		$this->assertTrue( $scoped['filters']['include_workers'] );
		// The pointer widens to the same set, or it widens to another site.
		$this->assertSame( 'alpha.example', $scoped['fetch'][0]['arguments']['server'] );
	}

	public function test_ask_accepts_the_context_it_declares_as_an_option(): void {
		// The verb declares `context`, so a caller writing `--context=` is
		// following the schema. Reading context only from the positionals meant
		// that caller got "missing context" for doing exactly what it said.
		$rid  = 'ctxrid0000000000';
		$this->write_request( [
			'rid' => $rid, 'url' => 'https://example.test/x', 'request_url' => 'https://example.test/x', 'duration_ms' => 10.0,
			'entries' => [ [ 'k' => 'span', 'n' => 'wp_loaded', 'd' => 5.0 ] ],
			'profiles' => [ 'wpdb' => [ 'time' => 0.004, 'count' => 2, 'entries' => [] ] ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			"category:wpdb --context=request:{$rid}"
		);

		// 'request', not 'recent window': only the context path reaches the
		// per-request board, so this distinguishes the two.
		$this->assertSame( 'request', $result['scope'] );
	}

	public function test_ask_category_refuses_a_callback_row_before_reading_any_board(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:hooks @10', 'request:nosuchrid' ] );
		$this->assertIsString( $result );
		$this->assertStringContainsString( "'hooks @10' is a callback row", $result );
	}

	public function test_urls_verb_echoes_the_filters_it_applied(): void {
		// The totals beside these are narrower than the site, and a number
		// whose scope is not stated is a number that will be read as the site's total.
		// The verb echoes what it actually applied, so the Ask brief describes
		// the panel rather than guessing at it.
		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'urls',
			'--server=alpha.example --search=/reviews/ --filter=method=POST'
		);

		$this->assertSame(
			[
				'server'          => 'alpha.example',
				'page'            => '',
				'search'          => '/reviews/',
				'filter'          => [ 'method=POST' ],
				'include_workers' => false,
				'bucket'          => '',
			],
			$result['filters']
		);
	}

	public function test_urls_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// dump_url verb
	// -------------------------------------------------------------------------

	public function test_dump_url_verb_rejects_invalid_hash(): void {
		// An invalid hash surfaces as a verb error string (interpreter errors are
		// string-encoded).
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_url',
			'not-a-hash'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid', \strtolower( $result ) );
	}

	public function test_dump_url_verb_returns_not_found_when_unknown_hash(): void {
		// Hash matches the regex but doesn't exist in the URL index — the verb
		// answers "not found".
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_url',
			'deadbeefcafe'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	/** A URL no timed request reached briefs its mean as null, in its brief and its finding. */
	public function test_an_untimed_url_briefs_its_mean_as_null(): void {
		$url = 'https://seed.example/kea-timeouts/';
		$this->seed_requests( \array_fill( 0, 6, [ 'url' => $url, 'ms' => null, 'error' => 'T' ] ) );

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:' . self::hash_of( $url ) );

		$this->assertSame( 6, $brief['stats']['count'] );
		$this->assertNull( $brief['stats']['avg_ms'], 'the url brief' );
		$this->assertSame( 'insufficient_instrumentation', $brief['findings'][0]['kind'] );
		$this->assertNull( $brief['findings'][0]['metric']['avg_ms'], 'its finding' );
	}

	/**
	 * A URL row no timed request reached has a null `max_ms` in `dump_url`,
	 * the url: brief and its finding, whatever its stored maximum.
	 */
	public function test_an_untimed_url_reports_a_null_max_ms_everywhere(): void {
		$url = 'https://seed.example/hoiho-8812/';
		$this->seed_requests( \array_fill( 0, 5, [ 'url' => $url, 'ms' => null, 'error' => 'T', 'peak_mb' => 3.5 ] ) );
		$this->seed_request( [ 'url' => 'https://seed.example/kaka-1093/', 'ms' => 40.5 ] );

		$dump  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) );
		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:' . self::hash_of( $url ) );

		$this->assertNull( $dump['stats']['max_ms'], 'dump_url' );
		$this->assertNull( $brief['stats']['max_ms'], 'the url: brief' );
		$this->assertNull( $brief['findings'][0]['metric']['max_ms'], 'its finding' );
	}

	public function test_dump_url_stats_carry_the_percentiles_and_the_error_rate(): void {
		$url = 'https://seed.example/tui-4471/';
		foreach ( \range( 1, 40 ) as $i ) {
			$this->seed_request( [ 'url' => $url, 'ms' => $i * 7.5, 'error' => 0 === $i % 8 ? 'T' : null ] );
		}

		$dump = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) );

		$this->assertEquals( [ 150.0, 285.0, 300.0 ], [ $dump['stats']['p50_ms'], $dump['stats']['p95_ms'], $dump['stats']['p99_ms'] ] );
		$this->assertSame( [ 40, 5 ], [ $dump['stats']['count'], $dump['stats']['errors'] ] );
		$this->assertEqualsWithDelta( 0.125, $dump['stats']['error_rate'], 1e-9 );
	}

	public function test_a_rebuild_reads_only_the_partitions_its_requests_are_in(): void {
		// Flames partition like the requests they came from; p0 holds none.
		$this->activate_shipped( 'performance', 2 );
		$url  = 'https://example.com/cold-in-p1';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-cold-p1-5530981', 'ms' => 70.0 ], 1 );
		$this->write_flame( [ 'rid' => 'rid-cold-p1-5530981', 'url_hash' => $hash, 'name' => 'request', 'value' => 70, 'children' => [] ], 1 );
		$this->write_flame( [ 'rid' => 'rid-cold-p1-5530981', 'url_hash' => $hash, 'name' => 'request', 'value' => 9000.5, 'children' => [ [ 'name' => 'stray-p0', 'value' => 9000.5, 'children' => [] ] ] ], 0 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertEqualsWithDelta( 70.0, $result['aggregate_flame']['value'], 0.001 );
		$this->assertSame( [], $result['aggregate_flame']['children'], "p0's flame index was read" );
	}

	public function test_dump_url_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_url',
			'abc123def456'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	/** A context naming no descriptor type is refused by name. */
	public function test_ask_refuses_a_context_naming_no_descriptor_type(): void {
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', 'kea-4471' ] );

		$this->assertSame( 'unknown descriptor: kea-4471', \trim( Core::as_string( $reply ) ) );
	}

	/** A stats file that will not open is an outage, never an absent request. */
	public function test_dump_request_names_an_outage_rather_than_an_absence(): void {
		$rid = $this->write_request( [ 'rid' => 'rid-dark-8812', 'url' => 'https://example.com/dark-8812' ] );
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Nodes\Config::reset();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );

		$this->assertSame( 'no request rid-dark-8812: the stats rows did not open', \trim( Core::as_string( $result ) ) );
	}

	// -------------------------------------------------------------------------
	// search_requests verb
	// -------------------------------------------------------------------------

	public function test_search_requests_verb_returns_not_found_when_missing(): void {
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'search_requests',
			'no-such-rid'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_search_requests_verb_locates_known_rid(): void {
		// Seed one request — search should return {rid, url_hash}.
		$rid = $this->write_request( [
			'rid'            => 'rid-search-12345678901234567890',
			'url'            => 'https://example.com/searchable',
			'timestamp'      => 1700000400,
			'duration_ms'    => 20,
			'status_code'    => 200,
			'peak_mb'        => 3,
			'request_method' => 'GET',
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'search_requests',
			$rid
		);

		$this->assertSame( [ 'rid', 'url_hash' ], \array_keys( $result ) );
		$this->assertSame( $rid, $result['rid'] );
	}

	/**
	 * The binder hands a handler its args by name however they arrived, so a
	 * caller naming the positional one — the MCP door names every arg — reads
	 * the same answer as a caller placing it.
	 */
	public function test_search_requests_takes_its_rid_by_name(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-named-kea-4417',
			'url'            => 'https://example.com/named-kea-4417',
			'timestamp'      => 1700000400,
			'duration_ms'    => 20,
			'status_code'    => 200,
			'peak_mb'        => 3,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', [ '--rid=' . $rid ] );

		$this->assertIsArray( $result );
		$this->assertSame( 'rid-named-kea-4417', $result['rid'] );
	}

	public function test_search_requests_verb_requires_rid(): void {
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'search_requests' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'missing required argument: rid', $result );
	}

	public function test_search_requests_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'search_requests',
			'whatever'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// grep_requests verb (server-side recent-firehose pattern search)
	// -------------------------------------------------------------------------

	public function test_grep_requests_returns_matching_request_summary(): void {
		// One completed request whose URL matches, plus a non-matching one.
		$this->write_firehose( 0, [
			[ 'rid' => 'grepR1', 'k' => 'process (start)', 'm' => '12345 on host', 'ts' => 1700000000.0, 'n' => 1 ],
			[ 'rid' => 'grepR1', 'k' => 'request', 'm' => 'PROPFIND https://kea.test/calendar/today?x=1', 'ts' => 1700000000.0, 'n' => 2 ],
			[ 'rid' => 'grepR1', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.4, 'n' => 3, 'duration_ms' => 400 ],
			[ 'rid' => 'grepNoise', 'k' => 'request', 'm' => 'GET https://kea.test/feed', 'ts' => 1700000001.0, 'n' => 1 ],
			[ 'rid' => 'grepNoise', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000001.2, 'n' => 2 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'grep_requests', '/calendar' );

		$this->assertIsArray( $result );
		$this->assertSame( 'recent', $result['scope'] );
		$this->assertSame( '/calendar', $result['pattern'] );
		$this->assertSame( 1, $result['scanned_partitions'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertCount( 1, $result['results'] );

		$summary = $result['results'][0];
		$this->assertSame( 'grepR1', $summary['rid'] );
		$this->assertSame( 'https://kea.test/calendar/today?x=?', $summary['url'] );
		$this->assertSame( 'PROPFIND', $summary['method'] );
		$this->assertGreaterThanOrEqual( 1, $summary['match_count'] );
		$this->assertStringContainsString( '/calendar', $summary['first_match_excerpt'] );
	}

	public function test_grep_requests_stops_when_its_time_runs_out(): void {
		// p0 outlasts the clock; the only match waits in p1, never entered.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 2 ] );
		$entries = [];
		$lines   = ( Performance_CI_Node::MAX_SCAN_S + 3 ) * self::clock_stride();
		for ( $i = 0; $i < $lines; $i++ ) {
			$entries[] = [ 'rid' => "noise{$i}", 'k' => 'request', 'm' => 'GET https://kea.test/feed', 'ts' => 1700000000.0, 'n' => 1 ];
		}
		$this->write_firehose( 0, $entries );
		$this->write_firehose( 1, [
			[ 'rid' => 'lateMatch', 'k' => 'request', 'm' => 'GET https://kea.test/past-the-budget-4471', 'ts' => 1700000900.0, 'n' => 1 ],
			[ 'rid' => 'lateMatch', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000900.5, 'n' => 2 ],
		] );
		$seconds      = 0.0;
		Core::$clock = static function () use ( &$seconds ): float {
			return $seconds += 1.0;
		};

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'grep_requests', '/past-the-budget-4471' );

		$this->assertSame( [], $result['results'] );
		$this->assertTrue( $result['truncated'] );
		$this->assertSame( 1, $result['scanned_partitions'] );
	}

	public function test_grep_requests_truncates_at_result_limit(): void {
		// Three matching completed requests; --limit=2 → 2 results + truncated.
		$entries = [];
		foreach ( [ 'gA', 'gB', 'gC' ] as $i => $rid ) {
			$entries[] = [ 'rid' => $rid, 'k' => 'request', 'm' => "GET https://kea.test/match/{$rid}", 'ts' => 1700000000.0 + $i, 'n' => 1 ];
			$entries[] = [ 'rid' => $rid, 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.5 + $i, 'n' => 2 ];
		}
		$this->write_firehose( 0, $entries );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'grep_requests', '/match --limit=2' );

		$this->assertCount( 2, $result['results'] );
		$this->assertTrue( $result['truncated'] );
	}

	/** `max(1, (int) 'abc')` answers one result and calls it the whole match set. */
	public function test_grep_requests_refuses_a_malformed_limit(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'lim1', 'k' => 'request', 'm' => 'GET https://kea.test/match/a', 'ts' => 1700000000.0, 'n' => 1 ],
			[ 'rid' => 'lim1', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.5, 'n' => 2 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'grep_requests', '/match --limit=abc' );

		$this->assertIsString( $result, 'a malformed --limit must not silently return one row' );
		$this->assertStringContainsString( 'limit', $result );
	}

	public function test_grep_requests_empty_when_no_match(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'z1', 'k' => 'request', 'm' => 'GET https://kea.test/other', 'ts' => 1700000000.0, 'n' => 1 ],
			[ 'rid' => 'z1', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.2, 'n' => 2 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'grep_requests', '/nonexistent' );

		$this->assertSame( [], $result['results'] );
		$this->assertFalse( $result['truncated'] );
		$this->assertSame( 1, $result['scanned_partitions'] );
	}

	public function test_grep_requests_skips_and_counts_torn_lines(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'oakum7', 'k' => 'request', 'm' => 'GET https://kea.test/oakum-3391', 'ts' => 1700000000.0, 'n' => 1 ],
			[ 'rid' => 'oakum7', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.2, 'n' => 2 ],
		] );
		$log = $this->tmp . '/logs/firehose.p0/0.log';
		\file_put_contents( $log, "{\"torn\n[1,2]\nnot json\n" . \file_get_contents( $log ) );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'grep_requests', '/oakum-3391' );

		$this->assertIsArray( $result, 'a torn line no longer fails the scan' );
		$this->assertSame( 3, $result['unparseable_lines'] );
		$this->assertSame( 'oakum7', $result['results'][0]['rid'] );
	}

	public function test_grep_requests_reports_no_unparseable_lines_on_a_clean_log(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'clean1', 'k' => 'request', 'm' => 'GET https://kea.test/bilge-812', 'ts' => 1700000000.0, 'n' => 1 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'grep_requests', '/bilge-812' );

		$this->assertSame( 0, $result['unparseable_lines'] );
	}

	public function test_grep_requests_requires_pattern(): void {
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'grep_requests' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'missing required argument: pattern', $result );
	}

	/** The binder takes whitespace as a value; as a pattern it matches all. */
	public function test_grep_requests_refuses_a_whitespace_pattern(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'grep_requests', [ '   ' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'pattern required', $result );
	}

	public function test_grep_requests_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter                  = new Performance_CI_Node();
		$result                       = VerbHarness::fire( $interpreter, 'performance', 'grep_requests', '/x' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// dump_request verb
	// -------------------------------------------------------------------------

	public function test_dump_request_verb_returns_not_found_when_missing(): void {
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_request',
			'no-such-rid'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_dump_request_finds_a_rid_no_partition_named(): void {
		// Seeded in p2 of 4, and the rid hashes to p3, so neither the default
		// nor the hash-first partition holds it: only a full walk finds it,
		// which is what `search_requests` already does with the same rid.
		$this->activate_shipped( 'performance', 4 );
		$body = [
			'rid'            => 'rid-detail-cross-partition-0001',
			'url'            => 'https://example.com/elsewhere',
			'timestamp'      => 1700000700,
			'duration_ms'    => 41,
			'status_code'    => 200,
			'peak_mb'        => 3,
			'request_method' => 'GET',
		];
		$this->assertSame( 3, \Newspack_Nodes\Partition_Node::hash_to_partition( $body['rid'], 4 ) );
		$rid = $this->write_request( $body, 2 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );

		$this->assertIsArray( $result, 'dump_request must resolve a rid search_requests can find' );
		$this->assertSame( 'https://example.com/elsewhere', $result['url'] );
	}

	/** The row names the record's partition; a caller names none. */
	public function test_dump_request_refuses_a_partition(): void {
		$rid = $this->write_request( [ 'rid' => 'rid-no-hint-6612', 'url' => 'https://example.com/no-hint-6612' ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid . ' --partition=0' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'partition', $result );
	}

	public function test_dump_request_verb_returns_body_for_known_rid(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-detail-12345678901234567890',
			'url'            => 'https://example.com/detailed',
			'timestamp'      => 1700000500,
			'duration_ms'    => 33,
			'status_code'    => 201,
			'peak_mb'        => 4,
			'request_method' => 'POST',
			'events'         => [
				[ 'k' => 'process (start)', 'm' => '/detailed', 'ts' => 1700000500.0 ],
			],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_request',
			$rid
		);

		$this->assertIsArray( $result );
		$this->assertSame( $rid, $result['rid'] );
		$this->assertSame( 'https://example.com/detailed', $result['url'] );
		$this->assertSame( 201, $result['status_code'] );
		$this->assertNotEmpty( $result['url_hash'] );
		$this->assertArrayHasKey( 'events', $result );
		$this->assertCount( 1, $result['events'] );
	}

	public function test_dump_request_carries_the_findings_for_that_record(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-findings-1234567890123456789',
			'url'            => 'https://example.com/slow-thing',
			'timestamp'      => 1700000700,
			'duration_ms'    => 9000,
			'status_code'    => 200,
			'peak_mb'        => 4,
			'request_method' => 'GET',
			'flame'          => [
				'name'     => 'request',
				'value'    => 9000.0,
				'children' => [ [ 'name' => 'init', 'value' => 8100.0, 'children' => [] ] ],
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );

		$this->assertIsArray( $result );
		$this->assertContains( 'dominant_span', \array_column( $result['findings'], 'kind' ) );
		$this->assertStringContainsString( 'SQL', $result['caveat'] );
	}

	// -------------------------------------------------------------------------
	// ask verb
	// -------------------------------------------------------------------------

	public function test_ask_refuses_a_descriptor_outside_the_vocabulary(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'wizard:x' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'unknown descriptor', \strtolower( $result ) );
	}

	public function test_ask_requires_a_descriptor(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', '' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'missing required argument: descriptor', $result );
	}

	/**
	 * A stored record's url is ABSOLUTE and query-stripped, while rules are
	 * path patterns — so re-deriving the rule by matching that url found
	 * nothing, not even a catch-all `/`. The record already carries the answer
	 * the request itself resolved.
	 */
	public function test_dump_request_resolves_the_rule_the_record_recorded(): void {
		\update_option(
			Rule_Set::OPTION_RULES,
			[
				[
					'id'      => Rule_Set::id_for( '/' ),
					'pattern' => '/',
					'action'  => 'log',
					'hooks'   => [ 'init' ],
				],
			]
		);
		Rule_Set::reset();

		$rid = $this->write_request( [
			'rid'            => 'rid-rule-12345678901234567890123',
			'url'            => 'https://example.test/some/path',
			'request_url'    => 'https://example.test/some/path',
			'rule_id'        => Rule_Set::id_for( '/' ),
			'timestamp'      => 1700001000,
			'duration_ms'    => 90,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}" );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertNotNull( $result['rule'], 'the record named its rule; nothing had to be re-derived' );
		$this->assertSame( '/', $result['rule']['pattern'] );
	}

	public function test_ask_assembles_a_request_brief(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-req-123456789012345678',
			'url'            => 'https://example.com/asked-about',
			'request_url'    => 'https://example.com/asked-about?rest_route=%2Fkea%2Fv4&view=4471',
			'timestamp'      => 1700000800,
			'duration_ms'    => 120,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}" );

		$this->assertIsArray( $result );
		$this->assertSame( 'request', $result['subject'] );
		$this->assertSame( 'https://example.com/asked-about?rest_route=%2Fkea%2Fv4&view=4471', $result['url'], 'the URL the request fetched, not the per-path row' );
		$this->assertEquals( 120.0, $result['duration_ms'] );
	}

	/**
	 * A request is named by its rid alone. A `request:<rid>:<partition>`
	 * descriptor is refused, as the target and as a span's context, never half-read.
	 */
	public function test_ask_refuses_a_request_descriptor_naming_a_partition(): void {
		$rid = $this->write_request( [ 'rid' => 'rid-ask-part-5521', 'url' => 'https://example.com/asked-partition', 'duration_ms' => 500 ] );

		$target  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}:2" );
		$context = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', "request:{$rid}:2" ] );

		$this->assertSame( "unknown descriptor: request:{$rid}:2", \trim( Core::as_string( $target ) ) );
		$this->assertSame( "unknown descriptor: request:{$rid}:2", \trim( Core::as_string( $context ) ) );
	}

	public function test_ask_resolves_a_span_through_its_request_context(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-span-12345678901234567',
			'url'            => 'https://example.com/asked-span',
			'request_url'    => 'https://example.com/asked-span',
			'timestamp'      => 1700000900,
			'duration_ms'    => 500,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
			'flame'          => [
				'name'     => 'request',
				'value'    => 500.0,
				'children' => [ [ 'name' => 'wp_loaded', 'value' => 480.0, 'children' => [] ] ],
			],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			[ 'span:wp_loaded', "request:{$rid}" ]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'span', $result['subject'] );
		$this->assertSame( 'wp_loaded', $result['name'] );
		$this->assertEquals( 480.0, $result['ms'] );
	}

	public function test_ask_resolves_an_entry_by_position_and_refuses_one_that_names_none(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-entry-1234567890123456',
			'url'            => 'https://example.com/asked-entry',
			'request_url'    => 'https://example.com/asked-entry',
			'timestamp'      => 1700000900,
			'duration_ms'    => 500,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
			'entries'        => [
				[ 'n' => 5, 'ts' => 1700000900.0, 'k' => 'gyrobase (start)', 'm' => 'first' ],
				[ 'n' => 5, 'ts' => 1700000900.2, 'k' => 'publication', 'm' => 'second' ],
			],
		] );

		$ask = static function ( string $descriptor ) use ( $rid ): mixed {
			VerbHarness::reset();
			return VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ $descriptor, "request:{$rid}" ] );
		};

		$result = $ask( 'entry:1' );
		$this->assertIsArray( $result );
		$this->assertSame( 'second', $result['entry']['m'] );

		// An id naming no position is refused, never read as row 0.
		foreach ( [ 'entry:abc', 'entry:1x', 'entry:-1', 'entry:01' ] as $descriptor ) {
			$this->assertStringContainsString( 'no entry', Core::as_string( $ask( $descriptor ) ), $descriptor );
		}
	}

	public function test_ask_carries_the_url_a_request_was_picked_under(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-under-url-12345678901234',
			'url'            => 'https://example.com/asked-under',
			'request_url'    => 'https://example.com/asked-under',
			'timestamp'      => 1700000800,
			'duration_ms'    => 120,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ "request:{$rid}", 'url:cafebabe3456', '--server=alpha.example' ] );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertSame( 'dump_url', $result['fetch'][1]['tool'] );
		$this->assertSame( [ 'hash' => 'cafebabe3456', 'server' => 'alpha.example' ], $result['fetch'][1]['arguments'] );
	}

	public function test_a_span_without_its_request_context_is_refused(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'span:wp_loaded' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'needs its request or its url', \strtolower( $result ) );
	}

	/**
	 * The flame builder on partition N writes flames.pN and the row naming
	 * requests.pN, so a rid's flame is read from its row's partition alone.
	 */
	public function test_the_flame_lookup_reads_its_rows_partition(): void {
		$this->activate_shipped( 'performance', 4 );
		$rid = 'rid-kakapo-hashed-elsewhere-1';
		$this->assertNotSame( 2, Partition_Node::hash_to_partition( $rid, 4 ) );
		$this->write_request( [ 'rid' => $rid, 'url' => 'https://example.com/kakapo', 'timestamp' => 1700000611, 'duration_ms' => 19 ], 2 );
		$this->write_flame( [ 'rid' => $rid, 'flame' => [ 'name' => 'stray', 'value' => 3, 'children' => [] ] ], Partition_Node::hash_to_partition( $rid, 4 ) );
		$this->write_flame( [ 'rid' => $rid, 'flame' => [ 'name' => 'home', 'value' => 19, 'children' => [] ] ], 2 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );

		$this->assertSame( 'home', $result['flame_data']['flame']['name'] );
	}

	/** The record is read once, at the place its row names; no index is walked. */
	public function test_dump_request_reads_the_record_at_its_rows_position(): void {
		$this->write_record( [ 'rid' => 'rid-ahead-of-it-6620', 'url' => 'https://example.com/ahead-6620' ] );
		$place = $this->write_record( [ 'rid' => 'rid-row-placed-6620', 'url' => 'https://example.com/row-placed-6620', 'duration_ms' => 19 ] );
		$this->seed_request( [ 'rid' => 'rid-row-placed-6620' ] + $place );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-row-placed-6620' );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertSame( 'https://example.com/row-placed-6620', $result['url'] );
		$this->assertSame( Log_Manager::url_hash( 'https://seed.example/seed-path/' ), $result['url_hash'], 'the hash its row names' );
	}

	/** An index is read only where this process registered its writer. */
	public function test_an_index_read_refuses_an_unregistered_formatter(): void {
		$rid = $this->write_request( [ 'rid' => 'rid-unformatted-3318', 'url' => 'https://example.com/unformatted-3318' ] );
		$registry = new \ReflectionProperty( \Newspack_Nodes\Formatters::class, 'registry' );
		$saved    = $registry->getValue();
		\Newspack_Nodes\Formatters::reset();
		try {
			$dumped = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );
		} finally {
			$registry->setValue( null, $saved );
		}

		$this->assertSame( 'index formatter not registered: flame-index', \trim( Core::as_string( $dumped ) ) );
	}

	/** A record no row names, aged past or not yet flushed, is not found. */
	public function test_dump_request_answers_not_found_for_a_record_no_row_names(): void {
		$this->write_record( [ 'rid' => 'rid-rowless-4410', 'url' => 'https://example.com/rowless-4410', 'duration_ms' => 23 ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-rowless-4410' );

		$this->assertSame( 'Request not found: rid=rid-rowless-4410', \trim( Core::as_string( $result ) ) );
	}

	/** A row whose place holds no record is not a request either. */
	public function test_dump_request_answers_not_found_where_the_row_points_at_nothing(): void {
		$this->write_record( [ 'rid' => 'rid-real-7781', 'url' => 'https://example.com/real-7781' ] );
		$this->seed_request( [ 'rid' => 'rid-gone-7781', 'segment' => 0, 'offset' => 900000, 'length' => 311 ] );
		$this->seed_request( [ 'rid' => 'rid-placeless-7781' ] );

		$gone      = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-gone-7781' );
		$placeless = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-placeless-7781' );

		$this->assertSame( 'Request not found: rid=rid-gone-7781', \trim( Core::as_string( $gone ) ) );
		$this->assertSame( 'Request not found: rid=rid-placeless-7781', \trim( Core::as_string( $placeless ) ) );
	}

	public function test_dump_request_verb_merges_flame_data_when_present(): void {
		// The flame index keeps 32 chars of a rid; a longer one never matches.
		$rid = $this->write_request( [
			'rid'            => 'rid-flame-123456789012345678901',
			'url'            => 'https://example.com/with-flame',
			'timestamp'      => 1700000600,
			'duration_ms'    => 12,
			'status_code'    => 200,
			'peak_mb'        => 1,
			'request_method' => 'GET',
		] );
		// Flame entry indexed by rid + url_hash; FlameBuilder writes the
		// flame body at Message::VALUE alongside the index entry.
		$url_hash = Log_Manager::url_hash( 'https://example.com/with-flame' );
		$this->write_flame( [
			'rid'      => $rid,
			'url_hash' => $url_hash,
			'flame'    => [ 'name' => 'request', 'value' => 12, 'children' => [] ],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_request',
			$rid
		);

		$this->assertArrayHasKey( 'flame_data', $result );
	}

	public function test_dump_request_verb_merges_flame_data_at_max_stack_depth(): void {
		// A MAX_STACK_DEPTH (50) flame nests ~2 JSON levels per span. Both the
		// write-side index formatter and this read path used depth-64 decodes,
		// so deep flames were written but never indexed or returned.
		$rid = $this->write_request( [
			'rid'            => 'rid-deep-flame-12345678901234567',
			'url'            => 'https://example.com/with-deep-flame',
			'timestamp'      => 1700000700,
			'duration_ms'    => 12,
			'status_code'    => 200,
			'peak_mb'        => 1,
			'request_method' => 'GET',
		] );

		$flame = [ 'name' => 'leaf', 'value' => 1, 'children' => [] ];
		for ( $i = 0; $i < 49; $i++ ) {
			$flame = [ 'name' => "level{$i}", 'value' => 1, 'children' => [ $flame ] ];
		}
		$flame['rid']      = $rid;
		$flame['url_hash'] = Log_Manager::url_hash( 'https://example.com/with-deep-flame' );
		$this->write_flame( $flame );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_request',
			$rid
		);

		$this->assertArrayHasKey( 'flame_data', $result );
		$node = $result['flame_data'];
		for ( $depth = 0; \is_array( $node ) && ! empty( $node['children'] ); $depth++ ) {
			$node = $node['children'][0];
		}
		$this->assertSame( 49, $depth, 'deep flame should round-trip intact' );
	}

	public function test_dump_request_verb_requires_rid(): void {
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_request'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'missing required argument: rid', $result );
	}

	public function test_dump_request_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_request',
			'whatever'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// list_hooks verb — replaces PerfHooksController::get_registered_hooks.
	// -------------------------------------------------------------------------

	/**
	 * Seed $wp_filter with three known hooks so HookCategorizer has something
	 * to walk. Shared across the list_hooks cluster — the categorizer
	 * cares about the hook NAMES, not the callback shapes, so a minimal stub
	 * object with a non-empty `callbacks` array is enough.
	 */
	private function seed_wp_filter_with_known_hooks(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_filter;
		$wp_filter = [
			'init'       => new class { public array $callbacks = [ 0 => [ 'cb' => 'do_init' ] ]; },
			'wp_loaded'  => new class { public array $callbacks = [ 0 => [ 'cb' => 'do_wp_loaded' ] ]; },
			'admin_menu' => new class { public array $callbacks = [ 0 => [ 'cb' => 'do_admin_menu' ] ]; },
		];
	}

	public function test_list_hooks_verb_returns_canonical_shape(): void {
		$this->seed_wp_filter_with_known_hooks();

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'list_hooks' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'total_hooks', $result );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertArrayHasKey( 'hooks_by_category', $result );
	}

	public function test_list_hooks_ships_category_descriptions(): void {
		// The descriptions travel with the taxonomy that owns them, so every
		// category this config declares can carry one — and users can add more.
		$this->seed_wp_filter_with_known_hooks();

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'list_hooks' );

		$this->assertArrayHasKey( 'category_descriptions', $result );
		$this->assertSame(
			'Core request lifecycle',
			$result['category_descriptions']['Lifecycle'] ?? null
		);
		// Every described category is a real one.
		foreach ( \array_keys( $result['category_descriptions'] ) as $category ) {
			$this->assertArrayHasKey( $category, $result['categories'] );
		}
	}

	public function test_list_hooks_verb_total_matches_summed_buckets(): void {
		$this->seed_wp_filter_with_known_hooks();

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'list_hooks' );

		$summed = 0;
		foreach ( $result['hooks_by_category'] as $bucket ) {
			$summed += \count( $bucket );
		}
		$this->assertSame( $result['total_hooks'], $summed );
	}

	public function test_list_hooks_verb_includes_seeded_hooks(): void {
		$this->seed_wp_filter_with_known_hooks();

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'list_hooks' );

		$all = [];
		foreach ( $result['hooks_by_category'] as $bucket ) {
			$all = \array_merge( $all, $bucket );
		}
		$this->assertContains( 'init', $all );
		$this->assertContains( 'wp_loaded', $all );
		$this->assertContains( 'admin_menu', $all );
	}

	/** `noisy` names the catalog's hooks a `noisy_log_events` pattern matches. */
	public function test_list_hooks_answers_the_noisy_names_of_its_catalog(): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'noisy_log_events' => [ '^kea_noisy', 'ruru$' ] ] );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_filter;
		$wp_filter = [];
		foreach ( [ 'kea_noisy_boot', 'kea_quiet', 'tui_ruru', 'gettext' ] as $hook ) {
			$wp_filter[ $hook ] = new class { public array $callbacks = [ 0 => [ 'cb' => 'do_it' ] ]; };
		}

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'list_hooks' );

		$this->assertEqualsCanonicalizing( [ 'kea_noisy_boot', 'tui_ruru' ], $result['noisy'] );
	}

	/** A `noisy_log_events` that is not a list of strings fails the verb, naming the key. */
	public function test_list_hooks_refuses_a_malformed_noisy_list(): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'noisy_log_events' => '^kea_noisy' ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'list_hooks' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( "config key 'noisy_log_events' must be a list of strings, not string", \html_entity_decode( $result, ENT_QUOTES ) );
	}

	public function test_list_hooks_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'list_hooks' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// set verb — the ruleset option alone, its JSON value decoded and
	// sanitized. Positional `set <option> <value>`.
	// -------------------------------------------------------------------------

	public function test_a_non_json_ruleset_push_is_refused_and_saves_nothing(): void {
		// A synced array-option value is JSON on the wire — Settings_Sync_Node
		// scalarizes arrays via wp_json_encode unconditionally. Decoded to [],
		// a malformed push reached apply_synced( [] ) and SAVED an empty
		// ruleset, clearing the spoke's rules; it must refuse instead.
		( new Rule_Set( [] ) )->save( [ new Rule( 'ignored', '/marmot/', Rule::ACTION_LOG, hooks: [ 'init' ] ) ] );
		$args = \Newspack_Nodes\Command_Args::format( [ Rule_Set::OPTION_RULES, 'zebra.example,quux.example' ], [] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', $args );

		$this->assertIsString( $result, 'the verb answers with its refusal' );
		$this->assertStringContainsString( 'JSON', $result );
		$this->assertSame( '/marmot/', $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][0]['pattern'] ?? null, 'the stored ruleset stands' );
	}

	public function test_array_option_json_preserves_associative_keys(): void {
		// A JSON array-option value is json_decoded and sanitize_settings_array keeps
		// the keys — a csv split would flatten an assoc map to a list of "1"s.
		$decode   = new \ReflectionMethod( Performance_CI_Node::class, 'decode_array_value' );
		$sanitize = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_array' );

		$decoded = $decode->invoke( null, (string) \json_encode( [ 'advancedemail' => true, 'amazons3' => true ] ) );

		$this->assertSame(
			[ 'advancedemail' => true, 'amazons3' => true ],
			$sanitize->invoke( null, $decoded )
		);
	}

	public function test_set_verb_re_tiers_a_synced_heavy_ruleset(): void {
		// The synced ruleset arrives hook-hydrated; set() must route it through
		// Rule_Set::apply_synced so a heavy rule re-tiers to THIS site's durable
		// option locally, not a raw update_option that bloats autoloaded OPTION_RULES.
		$big  = \array_map( fn( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$rule = ( new Rule( 'big', '/heavy/', Rule::ACTION_LOG, hooks: $big ) )->to_array();
		$args = \Newspack_Nodes\Command_Args::format(
			[ 'newspack_event_logger_nodes_rules', (string) \json_encode( [ $rule ] ) ],
			[]
		);

		$interpreter = new Performance_CI_Node();
		VerbHarness::fire( $interpreter, 'performance', 'set', $args );

		// Keyed by the PATTERN-derived id, not the 'big' the wire supplied.
		$option = Rule_Set::hooks_option_name( Rule_Set::id_for( '/heavy/' ) );
		$this->assertSame( $big, $GLOBALS['_wp_options'][ $option ], 'the heavy rule\'s hooks must land in a local durable option' );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][0];
		$this->assertSame( 'mc', $stored['hooks_in'], 'OPTION_RULES must hold a small pointer, not the inline blob' );
		$this->assertNull( $stored['hooks'] );
	}

	public function test_set_verb_carries_a_pointer_rules_null_hooks_through_sanitizing(): void {
		// A hub sends a heavy rule as a POINTER — `hooks: null`, `hooks_in: mc` —
		// whenever hydrate_array() could not inline it. Dropping the null leaves a
		// map Rule::from_array refuses outright, so every settings push fails:
		// the spoke keeps its last-good ruleset and the hub never converges.
		// Null is inert; it must survive the sanitizer.
		$big = \array_map( fn( $i ) => "hook_dowser_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 4 ) );
		( new Rule_Set( [] ) )->save( [ new Rule( Rule_Set::id_for( '/dowser/' ), '/dowser/', Rule::ACTION_LOG, hooks: $big ) ] );
		$option = Rule_Set::hooks_option_name( Rule_Set::id_for( '/dowser/' ) );

		$pointer = [
			'id'                     => Rule_Set::id_for( '/dowser/' ),
			'pattern'                => '/dowser/',
			'action'                 => 'log',
			'auto_disable_threshold' => 7331,
			'hooks'                  => null,
			'hooks_in'               => 'mc',
		];
		$args = \Newspack_Nodes\Command_Args::format(
			[ 'newspack_event_logger_nodes_rules', (string) \json_encode( [ $pointer ] ) ],
			[]
		);

		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', $args );

		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][0];
		$this->assertSame( 7331, $stored['auto_disable_threshold'], 'the push must APPLY, not be refused' );
		$this->assertSame( 'mc', $stored['hooks_in'] );
		$this->assertSame( $big, $GLOBALS['_wp_options'][ $option ], 'and the spoke keeps its durable hooks' );
	}

	public function test_sanitize_settings_array_keeps_an_explicit_null(): void {
		$ref = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_array' );

		$this->assertSame(
			[ 'hooks' => null, 'pattern' => '/dowser/' ],
			$ref->invoke( null, [ 'hooks' => null, 'pattern' => '/dowser/' ] )
		);
	}

	public function test_sanitize_settings_array_still_drops_an_object(): void {
		$ref = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_array' );

		$this->assertSame( [ 'pattern' => '/dowser/' ], $ref->invoke( null, [ 'blob' => new \stdClass(), 'pattern' => '/dowser/' ] ) );
	}

	public function test_set_verb_rejects_unknown_option(): void {
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'arbitrary_option x'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'unknown', \strtolower( $result ) );
		$this->assertArrayNotHasKey( 'arbitrary_option', $GLOBALS['_wp_options'] );
	}

	/** The two debugging switches are a rule's, so a hub pushing either is refused. */
	public function test_set_verb_refuses_the_retired_debugging_options(): void {
		foreach ( [ 'newspack_event_logger_nodes_log_memory', 'newspack_event_logger_nodes_flush_every_line' ] as $option ) {
			$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', [ $option, '1' ] );

			$this->assertIsString( $result );
			$this->assertStringContainsString( "unknown option: {$option}", $result );
			$this->assertArrayNotHasKey( $option, $GLOBALS['_wp_options'] );
		}
	}

	/** A blank is a value; no value at all names nothing to write. */
	public function test_set_verb_refuses_an_absent_value(): void {
		( new Rule_Set( [] ) )->save( [ new Rule( 'ignored', '/marmot/', Rule::ACTION_LOG ) ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', [ Rule_Set::OPTION_RULES ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'value required', $result );
		$this->assertSame( '/marmot/', $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ][0]['pattern'] ?? null );
	}

	/**
	 * Fire the option actions the production settings watcher listens on, as a
	 * live request does, spy on config resets, and hand back both captures.
	 *
	 * @return array{events: \ArrayObject<int,mixed>, resets: \ArrayObject<int,mixed>}
	 */
	private function watch_settings_writes(): array {
		$events = new \ArrayObject();
		$resets = new \ArrayObject();
		Settings_Event_Writer::$append_seam = static function ( array $m ) use ( $events ): void {
			$events[] = $m;
		};
		$GLOBALS['_test_fire_option_actions'] = true;
		\add_action( \Newspack_Nodes\Config::RESET_ACTION, static function () use ( $resets ): void {
			$resets[] = true;
		} );
		return [ 'events' => $events, 'resets' => $resets ];
	}

	/**
	 * A ruleset re-pushed as the hub sends it — hydrated, JSON on the wire,
	 * decoded and run through sanitize_text_field — equals the stored one, so
	 * it saves nothing, records no event and resets nothing.
	 */
	public function test_set_verb_pushing_the_stored_ruleset_in_its_wire_form_writes_nothing(): void {
		( new Rule_Set( [] ) )->save( [
			new Rule(
				Rule_Set::id_for( '/marmot-9142/' ),
				'/marmot-9142/',
				Rule::ACTION_LOG,
				auto_disable_threshold: 7331,
				auto_protect_time_threshold: 2.5,
				significant_events: [ 'kea_landing' ],
				custom_events: [ 'kea_flight' ],
				hooks: [ 'wp_loaded', 'init' ],
				log_http: true,
				trace_callers: 3
			),
			new Rule( Rule_Set::id_for( '/dowser?' ), '/dowser?', Rule::ACTION_SKIP ),
		] );
		$stored = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];
		$wire   = (string) \wp_json_encode( Rule_Set::hydrate_array( $stored ) );
		$seen   = $this->watch_settings_writes();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', [ Rule_Set::OPTION_RULES, $wire ] );

		$this->assertSame( [ 'option' => Rule_Set::OPTION_RULES, 'updated' => false ], $result );
		$this->assertSame( $stored, $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] );
		$this->assertCount( 0, $seen['events'], 'no settings event' );
		$this->assertCount( 0, $seen['resets'], 'no config reset' );
	}

	public function test_array_option_json_preserves_nested_structure(): void {
		// A nested map recurses one level and keeps the structure (sanitize_array
		// recursion branch), not flattened or rejected. Tested on the sanitizer
		// directly — the ruleset option itself re-tiers on set().
		$sanitize = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_array' );

		$this->assertSame(
			[ 'group' => [ 'inner' => 'val' ] ],
			$sanitize->invoke( null, [ 'group' => [ 'inner' => 'val' ] ] )
		);
	}

	public function test_set_verb_rejects_array_nested_too_deep(): void {
		// SETTINGS_ARRAY_DEPTH is 5; a 7-level-deep map blows the depth cap and the
		// whole sanitize returns null → rejected.
		$deep = 'leaf';
		for ( $i = 0; $i < 7; $i++ ) {
			$deep = [ 'n' => $deep ];
		}
		$args = \Newspack_Nodes\Command_Args::format(
			[ 'newspack_event_logger_nodes_rules', (string) \json_encode( $deep ) ],
			[]
		);

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'set', $args );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid value', \strtolower( $result ) );
	}

	public function test_set_verb_requires_option(): void {
		// No positional args → the binder's refusal for the required option.
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'set' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'missing required argument: option', $result );
	}

	public function test_set_verb_refuses_an_empty_array_value(): void {
		// An empty value is not JSON (json_decode('') is null): refused, so it
		// never saves an empty ruleset over the one the spoke holds.
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_rules ""'
		);

		$this->assertIsString( $result );
		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_rules', $GLOBALS['_wp_options'] );
	}

	// ── verb grammar: verb first ────────────────────────────────────────────

	public function test_search_requests_answers_a_rid_lookup(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', 'rid-renamed-7d3a' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_grep_requests_answers_a_pattern_scan(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'g7', 'k' => 'request', 'm' => 'GET https://kea.test/elsewhere', 'ts' => 1700000000.0, 'n' => 1 ],
			[ 'rid' => 'g7', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.2, 'n' => 2 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'grep_requests', '/renamed-9e1c' );

		$this->assertIsArray( $result );
		$this->assertSame( [], $result['results'] );
		$this->assertSame( 1, $result['scanned_partitions'] );
	}

	public function test_dump_request_answers_a_rid_read(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-renamed-4b2f' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_dump_url_answers_a_hash_read(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'deadbeef7d3a' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_list_hooks_answers_the_hook_catalog(): void {
		$this->seed_wp_filter_with_known_hooks();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'list_hooks' );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['total_hooks'] );
	}

	/** A noun-first verb name is refused, not aliased to its verb-first spelling. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'noun_first_verbs' )]
	public function test_a_noun_first_verb_is_refused_as_an_unknown_command( string $old ): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', $old, 'renamed-arg' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( "unknown command: {$old}", $result );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function noun_first_verbs(): array {
		return [
			'request_search'   => [ 'request_search' ],
			'request_grep'     => [ 'request_grep' ],
			'request_detail'   => [ 'request_detail' ],
			'url_detail'       => [ 'url_detail' ],
			'hooks_registered' => [ 'hooks_registered' ],
		];
	}

	// ── schema-driven dispatch ──────────────────────────────────────────────

	public function test_node_schema_lists_all_verbs_with_handlers(): void {
		$expected = [
			'overview', 'urls', 'dump_url', 'search_requests',
			'dump_request', 'list_hooks', 'set',
		];

		$verbs = [];
		foreach ( Performance_CI_Node::node_schema()['commands'] as $verb ) {
			$verbs[ $verb['name'] ] = $verb;
		}

		foreach ( $expected as $name ) {
			$this->assertArrayHasKey( $name, $verbs, "node_schema must list the '{$name}' verb" );
			$this->assertIsCallable( $verbs[ $name ]['handler'], "the '{$name}' verb must carry a callable handler" );
		}
	}

	public function test_no_input_verbs_declare_no_args(): void {
		// These verbs read no $payload/$args — they return a fixed shape, so the
		// Inspector fires them immediately with no arg modal.
		$verbs = self::verbs_by_name();
		foreach ( [ 'list_hooks' ] as $name ) {
			$this->assertSame( [], $verbs[ $name ]['args'], "'{$name}' must declare no args" );
		}
	}

	public function test_ask_verb_declares_its_context_the_tail_of_the_descriptors(): void {
		// `ask <descriptor> [<context-descriptor>…]`: every positional after
		// the first binds to `context`, so the page's scope rides by name.
		$args = self::args_by_name( 'ask' );
		$this->assertSame( [ 'descriptor', 'context', 'server', 'search', 'filter', 'include_workers', 'bucket' ], \array_keys( $args ) );
		$this->assertTrue( $args['descriptor']['required'] );
		$this->assertTrue( $args['context']['variadic'] );
		$this->assertSame( self::args_by_name( 'query' )['filter'], $args['filter'], 'declared as query declares it' );
		$this->assertFalse( $args['include_workers']['default'] );
	}

	public function test_urls_verb_declares_sort_paging_filter_args(): void {
		// urls reads sort/order/limit/offset/search/server/filter and
		// include_workers — all optional. `include_workers` opts IN because its
		// default EXCLUDES.
		$args = self::args_by_name( 'urls' );
		$this->assertSame(
			[ 'sort', 'order', 'limit', 'offset', 'search', 'server', 'page', 'filter', 'include_workers', 'bucket' ],
			\array_keys( $args )
		);
		$this->assertSame( 'string', $args['bucket']['type'] );
		$this->assertSame( '', $args['bucket']['default'] );
		$this->assertSame( 'bool', $args['include_workers']['type'] );
		$this->assertFalse( $args['include_workers']['default'] );
		$this->assertSame( self::args_by_name( 'query' )['filter'], $args['filter'], 'declared as query declares it' );
		$this->assertSame( 'string', $args['sort']['type'] );
		$this->assertSame( 'string', $args['order']['type'] );
		$this->assertSame( 'int', $args['limit']['type'] );
		$this->assertSame( 50, $args['limit']['default'] );
		$this->assertSame( 'int', $args['offset']['type'] );
		$this->assertSame( 0, $args['offset']['default'] );
		$this->assertSame( 'string', $args['search']['type'] );
		$this->assertSame( 'string', $args['server']['type'] );
		foreach ( $args as $arg ) {
			$this->assertFalse( $arg['required'] );
		}
	}

	public function test_search_requests_verb_declares_required_rid(): void {
		// search_requests needs a rid to look up → required string.
		$args = self::args_by_name( 'search_requests' );
		$this->assertSame( [ 'rid' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['rid']['type'] );
		$this->assertTrue( $args['rid']['required'] );
	}

	public function test_dump_request_verb_declares_a_required_rid_alone(): void {
		$args = self::args_by_name( 'dump_request' );
		$this->assertSame( [ 'rid' ], \array_keys( $args ) );
		$this->assertTrue( $args['rid']['required'] );
	}

	public function test_set_verb_declares_a_required_option_and_an_optional_value(): void {
		// A hub pushes a false bool as a blank value, so value is optional.
		$args = self::args_by_name( 'set' );
		$this->assertSame( [ 'option', 'value' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['option']['type'] );
		$this->assertTrue( $args['option']['required'] );
		// $value is mixed (int|float|bool|array depending on the option) — string
		// is the renderable catch-all the Inspector can collect.
		$this->assertSame( 'string', $args['value']['type'] );
		$this->assertFalse( $args['value']['required'] );
	}

	/** `urls`' description names every row stat that reads null untimed. */
	public function test_urls_description_names_each_null_untimed_stat(): void {
		$this->assertStringContainsString( 'its `avg_ms`, `min_ms` and `max_ms` are null', self::verbs_by_name()['urls']['description'] );
	}

	/** `query`'s description names the `available` flag its answer carries. */
	/** A page's `worker` is any shape's, which a reader must not take for a worker page. */
	public function test_urls_description_says_a_pages_worker_is_any_shapes(): void {
		$description = self::verbs_by_name()['urls']['description'];

		$this->assertStringContainsString( '`worker` is true where any shape folded is a worker\'s', $description );
		$this->assertStringContainsString( '`--page=<page hash>` answers that page\'s URLs', $description );
		$this->assertStringContainsString( '`--filter=page=<page hash>` keeps that one page row', $description );
	}

	public function test_query_description_names_available(): void {
		$this->assertStringContainsString( '`available`, false when the stats file will not open', self::verbs_by_name()['query']['description'] );
	}

	/** The three verbs a snapshot answers name its shipped life. */
	public function test_the_snapshot_verbs_name_its_life(): void {
		$life = 'serves 4 of its tick\'s intervals, 120 s each by default';
		foreach ( [ 'overview', 'urls', 'query' ] as $verb ) {
			$this->assertStringContainsString( $life, self::verbs_by_name()[ $verb ]['description'], $verb );
		}
	}

	/** `dump_url`'s description names every stat that reads null unmeasured. */
	public function test_dump_url_description_names_each_null_unmeasured_stat(): void {
		$this->assertStringContainsString( '`avg_ms`, `min_ms`, `max_ms` and `avg_peak_mb` are null', self::verbs_by_name()['dump_url']['description'] );
	}

	/**
	 * node_schema()['commands'] indexed by verb name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function verbs_by_name(): array {
		$verbs = [];
		foreach ( Performance_CI_Node::node_schema()['commands'] as $verb ) {
			$verbs[ $verb['name'] ] = $verb;
		}
		return $verbs;
	}

	/**
	 * A verb's args[] indexed by arg name.
	 *
	 * @param string $verb Verb name.
	 * @return array<string,array<string,mixed>>
	 */
	private static function args_by_name( string $verb ): array {
		$out = [];
		foreach ( self::verbs_by_name()[ $verb ]['args'] as $arg ) {
			$out[ $arg['name'] ] = $arg;
		}
		return $out;
	}

	// ── disk-walk fan-out, index-row shape, leaderboard arithmetic ───────────

	public function test_the_reply_clock_is_the_ticks_own_rather_than_the_wall(): void {
		// Inside the drain the tick refreshes `Core::$now`, and every reader on
		// this path takes THAT: a bare `right_now()` re-reads the wall mid-fold,
		// so two panels of one reply can date from either side of a second.
		$ticked    = 1_600_000_000;
		$previous  = Core::$now;
		Core::$now = (float) $ticked + 0.75;
		try {
			$this->assertSame(
				$ticked,
				( new \ReflectionMethod( Performance_CI_Node::class, 'now' ) )->invoke( null ),
				'the tick\'s clock, not the wall'
			);
		} finally {
			Core::$now = $previous;
		}
	}

	/**
	 * A grep excerpt is the matching entry, not a 200-byte prefix of it.
	 * 350 is distinct from every cap this method has held.
	 */
	public function test_a_grep_excerpt_carries_the_whole_entry(): void {
		$message = \str_repeat( 'q', 350 );
		$method  = new \ReflectionMethod( Performance_CI_Node::class, 'grep_excerpt' );
		$method->setAccessible( true );

		$this->assertSame( "sql: {$message}", $method->invoke( null, 'sql', $message ) );
	}

	public function test_a_scoped_search_that_names_nothing_answers_zero_rather_than_null(): void {
		// A search with no candidates walks nothing, and nothing is zero
		// requests: a total the page answers, never an unanswerable scope.
		$this->seed_request( [ 'server' => 'moa.test', 'url' => 'https://moa.test/kakapo-3310/' ] );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=moa.test --search=nomatch' );

		$this->assertIsArray( $page['totals'], 'a term nothing matches is zero requests, not an unanswerable scope' );
		$this->assertSame( 0, $page['totals']['requests'] );
		$this->assertSame( 0, $page['rows'] );
	}

	public function test_the_seeds_date_from_the_tick_the_reply_reads(): void {
		// The reply dates itself from `Core::$now`, stamped at setUp; a seed
		// dated from the wall lands a bucket later whenever a five-minute
		// boundary falls between the two, and the page it seeded reads empty.
		$this->seed_request( [ 'url' => 'https://seed.example/tick-1/' ] );

		$rows = Stats_Query::run( [ 'source' => 'requests', 'group' => [ 'bucket' ], 'filter' => [], 'measure' => [ 'count' ], 'buckets' => Bucket::last( Bucket::window(), self::tick() ) ] )['rows'];

		$this->assertSame( [ [ Bucket::id( self::tick() ), 1 ] ], $rows );
	}

	public function test_a_fresh_graph_keeps_the_tick_its_test_dates_from(): void {
		// A test fires several replies, each on a fresh graph; re-reading the
		// wall between them splits one test across two buckets at a boundary.
		$previous  = Core::$now;
		Core::$now = (float) ( self::tick() + Bucket::SECONDS );
		$tick      = Core::$now;
		try {
			VerbHarness::reset();
			$this->assertSame( $tick, Core::$now );
		} finally {
			Core::$now = $previous;
		}
	}

	// -------------------------------------------------------------------------
	// A five-minute bucket: `--bucket` narrows `urls`, `dump_url` and `ask` to
	// one slot of the span the charts draw.
	// -------------------------------------------------------------------------

	/** The bucket the tests narrow to: slot 7 of its hour. */
	private const BUCKET = '2026-10-04-13-35';

	/**
	 * Pin the reply at 2026-10-05 09:12 UTC, so BUCKET is about 20 hours
	 * back, inside the 264 buckets a chart draws at a `min_lifetime` of
	 * 79,200 seconds.
	 */
	private function at_the_bucket_clock(): void {
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => 79200 ] );
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 9, 12, 0, 10, 5, 2026 );
	}

	/** The oldest slot a chart draws is a bucket the table can narrow to. */
	public function test_the_oldest_charted_slot_is_a_bucket(): void {
		$this->at_the_bucket_clock();

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--bucket=2026-10-04-11-15' );

		$this->assertIsArray( $page );
		$this->assertSame( '2026-10-04-11-15', $page['filters']['bucket'] );
	}

	/**
	 * Every verb that reads a selection refuses a spelling naming a key
	 * that is not a five-minute key, or one in the future, a run ending before
	 * it starts, and more buckets than the span, each saying which key or
	 * naming the limit.
	 *
	 * @param string $verb    The verb.
	 * @param string $args    Its arguments before the bucket.
	 * @param string $bucket  The refused key.
	 * @param string $message What the refusal says.
	 */
	#[DataProvider( 'refused_buckets' )]
	public function test_a_bucket_whose_rows_are_not_stored_is_refused( string $verb, string $args, string $bucket, string $message ): void {
		$this->at_the_bucket_clock();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, \trim( "{$args} --bucket={$bucket}" ) );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( $message, $reply );
	}

	/** @return array<string,array{0:string,1:string,2:string,3:string}> */
	public static function refused_buckets(): array {
		$out = [];
		foreach ( [ 'urls' => '', 'dump_url' => 'c0ffee7731ab', 'ask' => 'overview:site', 'ask url:' => 'url:c0ffee7731ab' ] as $verb => $args ) {
			foreach ( [
				'off the grid'     => [ '2026-10-04-13-37', 'bucket 2026-10-04-13-37 must be a five-minute key, Y-m-d-H-i in UTC' ],
				'garbage'          => [ 'junk', 'bucket junk must be a five-minute key, Y-m-d-H-i in UTC' ],
				'overflowing'      => [ '2026-10-04-25-00', 'bucket 2026-10-04-25-00 must be a five-minute key, Y-m-d-H-i in UTC' ],
				'future'           => [ '2026-10-05-09-15', 'bucket 2026-10-05-09-15 is in the future' ],
				'one key off grid' => [ '2026-10-04-13-35,2026-10-04-13-41', 'bucket 2026-10-04-13-41 must be a five-minute key, Y-m-d-H-i in UTC' ],
				'reversed run'     => [ '2026-10-04-13-40..2026-10-04-13-35', 'bucket run 2026-10-04-13-40..2026-10-04-13-35 does not end after it starts' ],
				'265 buckets'      => [ '2026-10-04-11-10..2026-10-05-09-15', 'bucket selection holds more than 264 buckets' ],
			] as $why => [ $bucket, $message ] ) {
				$out[ "{$verb}, {$why}" ] = [ \strtok( $verb, ' ' ), $args, $bucket, $message ];
			}
		}
		return $out;
	}

	/** A server that filed nothing in the bucket answers an empty page. */
	public function test_a_bucket_scoped_to_a_server_with_no_set_is_empty(): void {
		$this->at_the_bucket_clock();
		$this->seed_request( [ 'server' => 'kea.example', 'url' => 'https://kea.example/egret/', 'bucket' => Bucket::of_key( self::BUCKET ) ] );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=kaka.example --bucket=' . self::BUCKET );

		$this->assertSame( [ [], 0 ], [ $page['data'], $page['rows'] ] );
		$this->assertSame( 'kaka.example', $page['filters']['server'] );
	}

	/** A hash no URL carries is not found, under a bucket or not. */
	public function test_a_bucketed_read_of_an_unknown_hash_is_not_found(): void {
		$this->at_the_bucket_clock();

		$detail = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ 'c0ffee7731ab', '--bucket=' . self::BUCKET ] );
		$brief  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:c0ffee7731ab --bucket=' . self::BUCKET );

		$this->assertStringContainsString( 'URL not found: c0ffee7731ab', $detail );
		$this->assertStringContainsString( 'URL not found: c0ffee7731ab', $brief );
	}

	/**
	 * `ask` reads the bucket only for the briefs it narrows: a `request:`
	 * brief under a bucket that has aged out still answers.
	 */
	public function test_a_request_ask_ignores_a_bucket_it_does_not_read(): void {
		$this->at_the_bucket_clock();
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-aged-12345678901234567',
			'url'            => 'https://example.com/kea-4410',
			'request_url'    => 'https://example.com/kea-4410',
			'timestamp'      => \gmmktime( 8, 59, 0, 10, 5, 2026 ),
			'duration_ms'    => 120,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid} --bucket=2026-10-01-00-00" );

		$this->assertIsArray( $brief, \is_string( $brief ) ? $brief : '' );
		$this->assertSame( 'request', $brief['subject'] );
	}

	// -------------------------------------------------------------------------
	// `overview` and `urls` over the `flame-facts` rows.
	// -------------------------------------------------------------------------

	/** The bucket the reply's tick falls in. */
	private static function latest(): int {
		return Bucket::id( self::tick() );
	}

	/** One seeded URL's hash. */
	private static function hash_of( string $url ): string {
		return Url_Parts::hash( $url );
	}

	public function test_overview_totals_count_every_non_worker_request(): void {
		$latest = self::latest();
		$this->seed_request( [ 'url' => 'https://seed.example/kea-14/', 'ms' => 120.5, 'peak_mb' => 30.25 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/kea-14/', 'ms' => 80.0, 'peak_mb' => 20.25, 'bucket' => $latest - Bucket::window() + 1 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/weka-3/', 'ms' => null, 'error' => 'T', 'peak_mb' => 10.25 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/cron-9/', 'ms' => 5000.0, 'peak_mb' => 99.0, 'worker' => 1 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/aged-2/', 'ms' => 7.0, 'bucket' => $latest - Bucket::window() ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertSame(
			[ 'requests' => 3, 'timed' => 2, 'avg_ms' => 100.25, 'avg_peak_mb' => 20.25, 'errors' => 1, 'urls' => 2 ],
			$reply['totals']
		);
		$this->assertCount( 24, $reply['slots'], '7,200 seconds is 24 buckets' );
		$this->assertSame( Bucket::key( $latest ), $reply['slots'][0], 'newest first' );
		$this->assertSame( Bucket::key( $latest - 23 ), $reply['slots'][23] );
		$this->assertSame( [ 'as_of', 'totals', 'slots', 'servers', 'leaderboard', 'owners', 'available' ], \array_keys( $reply ) );
		$this->assertTrue( $reply['available'] );
	}

	public function test_a_leaderboard_of_no_profiled_request_has_no_means(): void {
		$board = Performance_CI_Node::leaderboard_of( [ 'wpdb' => [ 'sum_time' => 9.0, 'sum_count' => 4.0, 'samples' => 3 ] ], 0, 0.0 );

		$this->assertNull( $board['total_time'] );
		$this->assertNull( $board['categories']['wpdb']['time'] );
		$this->assertNull( $board['categories']['wpdb']['count'] );
		$this->assertEqualsWithDelta( 9.0, Performance_CI_Node::leaderboard_of( [], 3, 27.0 )['total_time'], 1e-9 );
	}

	public function test_a_leaderboard_turns_running_sums_into_means(): void {
		$sums  = [
			'wpdb' => [
				'samples'   => 4,
				'sum_time'  => 1.6,
				'sum_count' => 16,
				'entries'   => [ 'SELECT' => [ 1.2, 12, 4 ] ],
			],
		];
		$board = Performance_CI_Node::leaderboard_of( $sums, 4, 4.0 );

		$this->assertSame( 4, $board['count'] );
		$this->assertEqualsWithDelta( 1.0, $board['total_time'], 1e-9 );
		// A category's means divide by the profiled requests: 1.6 / 4.
		$this->assertEqualsWithDelta( 0.4, $board['categories']['wpdb']['time'], 1e-9 );
		$this->assertEqualsWithDelta( 4.0, $board['categories']['wpdb']['count'], 1e-9 );
		// An entry's means divide by its own samples: 1.2 / 4 and 12 / 4.
		$this->assertEqualsWithDelta( 0.3, $board['categories']['wpdb']['entries']['SELECT'][0], 1e-9 );
		$this->assertEqualsWithDelta( 3.0, $board['categories']['wpdb']['entries']['SELECT'][1], 1e-9 );
	}

	public function test_a_leaderboard_drops_an_entry_with_no_samples(): void {
		$sums  = [
			'wpdb' => [
				'samples'   => 2,
				'sum_time'  => '1.0',
				'sum_count' => null,
				'entries'   => [
					'ZERO' => [ 5.0, 5.0, 0 ],
					'NAN'  => [ 'x', 'y', 3 ],
				],
			],
		];
		$board = Performance_CI_Node::leaderboard_of( $sums, 2, 2.0 );

		$this->assertEqualsWithDelta( 0.5, $board['categories']['wpdb']['time'], 1e-9 );
		$this->assertEqualsWithDelta( 0.0, $board['categories']['wpdb']['count'], 1e-9 );
		$this->assertArrayNotHasKey( 'ZERO', $board['categories']['wpdb']['entries'] );
		$this->assertEqualsWithDelta( 0.0, $board['categories']['wpdb']['entries']['NAN'][0], 1e-9 );
		$this->assertSame( 3, $board['categories']['wpdb']['entries']['NAN'][2] );
	}

	/** Past a hundred entries a category keeps its fifty slowest by mean time. */
	public function test_a_leaderboard_trims_a_crowded_category_to_its_slowest_entries(): void {
		$entries = [];
		for ( $i = 1; $i <= 101; $i++ ) {
			$entries[ "SELECT kea_{$i}" ] = [ 7.0 * $i, 1, 7 ];
		}
		$board = Performance_CI_Node::leaderboard_of( [ 'wpdb' => [ 'samples' => 7, 'sum_time' => 70.0, 'sum_count' => 707, 'entries' => $entries ] ], 7, 77.0 );

		$kept = $board['categories']['wpdb']['entries'];
		$this->assertCount( 50, $kept );
		$this->assertSame( 'SELECT kea_101', \array_key_first( $kept ), 'slowest first' );
		$this->assertSame( 'SELECT kea_52', \array_key_last( $kept ) );
		$this->assertEqualsWithDelta( 101.0, $kept['SELECT kea_101'][0], 1e-9 );
	}

	/** A hundred entries is not a crowd: every one stays, in its own order. */
	public function test_a_leaderboard_keeps_a_category_at_the_trim_bound_whole(): void {
		$entries = [];
		for ( $i = 1; $i <= 100; $i++ ) {
			$entries[ "SELECT moa_{$i}" ] = [ 3.0 * $i, 1, 3 ];
		}
		$board = Performance_CI_Node::leaderboard_of( [ 'wpdb' => [ 'samples' => 3, 'sum_time' => 30.0, 'sum_count' => 300, 'entries' => $entries ] ], 3, 33.0 );

		$this->assertCount( 100, $board['categories']['wpdb']['entries'] );
		$this->assertSame( 'SELECT moa_1', \array_key_first( $board['categories']['wpdb']['entries'] ) );
	}

	public function test_overview_leaderboard_spans_the_charts_buckets(): void {
		$this->seed_site_timing( [ 'category' => 'sql', 'bucket' => self::latest() - Bucket::window(), 'ms' => 4000.0, 'calls' => 40, 'requests' => 4 ] );
		$this->seed_site_timing( [ 'category' => 'init hook @10', 'bucket' => self::latest() - 10, 'ms' => 31.5, 'calls' => 3, 'requests' => 3 ] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['leaderboard'];

		$this->assertSame( [ 'init hook @10' ], \array_keys( $board['categories'] ) );
	}

	public function test_overview_leaderboard_excludes_listener_time_from_total_time(): void {
		$this->seed_site_timing( [ 'category' => 'sql', 'ms' => 402.0, 'calls' => 18, 'requests' => 3 ] );
		$this->seed_site_timing( [ 'category' => 'sql', 'entry' => 'SELECT kea', 'ms' => 181.0, 'calls' => 5, 'requests' => 2 ] );
		$this->seed_site_timing( [ 'category' => 'init hook @10', 'listener' => 1, 'ms' => 90.0, 'calls' => 3, 'requests' => 3 ] );
		$this->seed_site_timing( [ 'category' => 'sql', 'worker' => 1, 'ms' => 9000.0, 'calls' => 70, 'requests' => 7 ] );
		$this->seed_site_timing( [ 'category' => 'sql', 'bucket' => self::latest() - Bucket::window(), 'ms' => 4000.0, 'calls' => 40, 'requests' => 4 ] );
		foreach ( [ 200.5, 400.5, 300.5, 100.5 ] as $ms ) {
			$this->seed_request( [ 'ms' => $ms, 'profiled' => 1 ] );
		}
		$this->seed_request( [ 'ms' => 1000.5, 'profiled' => 0 ] );
		$this->seed_request( [ 'ms' => 8000.0, 'profiled' => 1, 'worker' => 1 ] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['leaderboard'];

		$this->assertSame( 4, $board['count'], 'the profiled non-worker requests' );
		$this->assertSame( 100.5, $board['total_time'], 'the listener ran inside its hook: 402 over 4, not 492' );
		$this->assertSame( 400.5, $board['avg_ms'], 'every timed non-worker request over the board window' );
		$this->assertSame( [ 'time' => 100.5, 'count' => 4.5, 'samples' => 3, 'entries' => [ 'SELECT kea' => [ 90.5, 2.5, 2 ] ] ], $board['categories']['sql'] );
		$this->assertSame( 22.5, $board['categories']['init hook @10']['time'], 'the listener still has its own row' );
	}

	/** A cheap category keeps its entries however many dearer entry rows the board holds. */
	public function test_a_cheap_category_keeps_its_entries_past_a_full_page_of_dearer_ones(): void {
		$rows = [ [ 'category' => 'heavy', 'ms' => 900000.5, 'calls' => 9, 'requests' => 1 ] ];
		for ( $i = 0; $i <= Stats_Query::MAX_LIMIT; $i++ ) {
			$rows[] = [ 'category' => 'heavy', 'entry' => "SELECT kea {$i}", 'ms' => 500.5 + $i, 'calls' => 1, 'requests' => 1 ];
		}
		$rows[] = [ 'category' => 'cheap', 'ms' => 1.5, 'calls' => 3, 'requests' => 2 ];
		$rows[] = [ 'category' => 'cheap', 'entry' => 'tiny', 'ms' => 0.75, 'calls' => 3, 'requests' => 2 ];
		$this->seed_site_timings( $rows );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['leaderboard'];

		$this->assertSame( [ 'tiny' => [ 0.375, 1.5, 2 ] ], $board['categories']['cheap']['entries'] );
		$this->assertCount( 50, $board['categories']['heavy']['entries'], 'a category past a hundred keeps its fifty slowest' );
	}

	/** The cheapest category stays on the board however many dearer categories precede it. */
	public function test_the_cheapest_category_survives_past_a_full_page_of_categories(): void {
		$rows = [];
		for ( $i = 0; $i <= Stats_Query::MAX_LIMIT; $i++ ) {
			$rows[] = [ 'category' => \sprintf( 'hook-%04d', $i ), 'ms' => 50.5 + $i, 'calls' => 1, 'requests' => 1 ];
		}
		$rows[] = [ 'category' => 'zz-kaka', 'ms' => 0.25, 'calls' => 7, 'requests' => 3 ];
		$this->seed_site_timings( $rows );
		$this->seed_request( [ 'profiled' => 1 ] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['leaderboard'];

		$this->assertCount( Stats_Query::MAX_LIMIT + 2, $board['categories'] );
		$this->assertSame( [ 'time' => 0.25, 'count' => 7, 'samples' => 3, 'entries' => [] ], $board['categories']['zz-kaka'] );
	}

	/**
	 * The entries read stops at the deepest page a query may start, and says
	 * so. The category row files through the writer; the 11,001 entries past
	 * the bound are bulk-filled in SQL beside it, ids by join, so the test
	 * spends its budget on the read it is about.
	 */
	public function test_the_entries_read_stops_at_the_offset_bound_and_says_so(): void {
		$this->seed_site_timings( [ [ 'category' => 'heavy', 'ms' => 900000.5, 'calls' => 9, 'requests' => 1 ] ] );
		$db   = new \PDO( 'sqlite:' . $this->facts_file( 0 ), null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$last = Stats_Query::MAX_OFFSET + Stats_Query::MAX_LIMIT;
		$db->exec( "WITH RECURSIVE n ( i ) AS ( SELECT 0 UNION ALL SELECT i + 1 FROM n WHERE i < {$last} ) INSERT INTO entries ( name ) SELECT 'SELECT moa ' || i FROM n" );
		$db->exec( "INSERT INTO timings_site ( bucket, server_id, worker, category_id, entry_id, listener, ms, calls, requests ) SELECT t.bucket, t.server_id, t.worker, t.category_id, e.id, 0, 0.5 + CAST( substr( e.name, 12 ) AS INTEGER ), 1, 1 FROM entries AS e, ( SELECT * FROM timings_site LIMIT 1 ) AS t WHERE e.name LIKE 'SELECT moa %'" );
		$db     = null;
		$logged = \count( Core::$recent_log );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['leaderboard'];

		$this->assertCount( 50, $board['categories']['heavy']['entries'] );
		$lines = \array_filter( \array_slice( Core::$recent_log, $logged ), static fn ( string $line ): bool => \str_contains( $line, 'performance: leaderboard rows past the offset bound' ) );
		$this->assertCount( 1, $lines );
	}

	/**
	 * The picker lists every server with traffic in the chart's window, a
	 * server that serves only cron or job requests among them.
	 */
	public function test_overview_servers_lists_each_server_by_count(): void {
		foreach ( [ 'kea.example' => 3, 'egret.example' => 1, 'weka.example' => 2 ] as $server => $n ) {
			for ( $i = 0; $i < $n; $i++ ) {
				$this->seed_request( [ 'server' => $server ] );
			}
		}
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed_request( [ 'server' => 'cron.example', 'worker' => 1 ] );
		}

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--server=egret.example' );

		$this->assertSame( [ 'cron.example', 'kea.example', 'weka.example', 'egret.example' ], $reply['servers'], 'a worker-only server is listed, and a server filter leaves the list whole' );
		$this->assertSame( 1, $reply['totals']['requests'], 'and scopes the totals' );
	}

	public function test_overview_server_scopes_the_leaderboard(): void {
		$this->seed_site_timing( [ 'server' => 'kea.example', 'category' => 'sql', 'ms' => 65.0, 'calls' => 8, 'requests' => 2 ] );
		$this->seed_site_timing( [ 'server' => 'egret.example', 'category' => 'sql', 'ms' => 7000.0, 'calls' => 90, 'requests' => 9 ] );
		$this->seed_request( [ 'server' => 'kea.example', 'profiled' => 1, 'ms' => 50.5 ] );
		$this->seed_request( [ 'server' => 'kea.example', 'profiled' => 1, 'ms' => 70.5 ] );
		$this->seed_request( [ 'server' => 'egret.example', 'profiled' => 1, 'ms' => 9000.0 ] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--server=kea.example' )['leaderboard'];

		$this->assertSame( 2, $board['count'] );
		$this->assertSame( 60.5, $board['avg_ms'] );
		$this->assertSame( 32.5, $board['categories']['sql']['time'] );
	}

	public function test_overview_answers_no_breakdowns_and_declares_no_breakdown_args(): void {
		$declared = [];
		foreach ( Performance_CI_Node::node_schema()['commands'] as $command ) {
			if ( 'overview' === $command['name'] ) {
				$declared = \array_column( $command['args'], 'name' );
			}
		}

		$this->assertSame( [ 'server' ], $declared, 'chart series come from `query`' );
	}

	/**
	 * A page asked two ways by visitors and once by a worker, and a page
	 * asked one way, each request finishing at its own second of the newest
	 * bucket.
	 */
	private function seed_kotuku(): void {
		$at = self::latest() * 300;
		$this->seed_requests(
			[
				[ 'url' => 'https://seed.example/Kotuku-6604/?a=?', 'ms' => 40.5, 'peak_mb' => 20.5, 'finished_at' => $at + 11.5 ],
				[ 'url' => 'https://seed.example/Kotuku-6604/?a=?', 'ms' => 60.75, 'peak_mb' => 30.5, 'finished_at' => $at + 12.5 ],
				[ 'url' => 'https://seed.example/kotuku-6604/?b=?', 'ms' => 100.5, 'peak_mb' => 40.5, 'status' => 404, 'finished_at' => $at + 13.5 ],
				[ 'url' => 'https://seed.example/kotuku-6604/?cron=?', 'ms' => 900.5, 'peak_mb' => 50.5, 'worker' => 1, 'finished_at' => $at + 14.5 ],
				[ 'url' => 'https://seed.example/tieke-6604/', 'ms' => 12.5, 'peak_mb' => 10.5, 'finished_at' => $at + 9.5 ],
			]
		);
	}

	/**
	 * A `urls` row is a page: its lowercased host and path, its server and
	 * the path under it, every shape of it folded, a one-shape page naming
	 * its URL by the URL's hash and a page of more naming none. The default
	 * view folds the visitor shapes; Include Workers folds every shape, and a
	 * page holding any worker shape reads `worker` true.
	 */
	public function test_urls_answers_pages(): void {
		$this->seed_kotuku();
		$at = self::latest() * 300;

		$readers = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--sort=url --order=asc' );
		VerbHarness::reset();
		$all = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--sort=url --order=asc --include_workers' );

		$this->assertSame(
			[
				[
					'page_hash'    => self::hash_of( 'seed.example/kotuku-6604/' ),
					'page'         => 'seed.example/kotuku-6604/',
					'server'       => 'seed.example',
					'shapes'       => 2,
					'hash'         => null,
					'url'          => 'https://seed.example/kotuku-6604/',
					'count'        => 3,
					'timed_count'  => 3,
					'count_2xx'    => 2,
					'count_3xx'    => 0,
					'count_4xx'    => 1,
					'count_5xx'    => 0,
					'errors'       => 0,
					'sum_ms'       => 201.75,
					'min_ms'       => 40.5,
					'max_ms'       => 100.5,
					'sum_peak_mb'  => 91.5,
					'max_peak_mb'  => 40.5,
					'worker'       => false,
					'last_updated' => $at + 13.5,
					'avg_ms'       => 67.25,
					'avg_peak_mb'  => 30.5,
				],
				[
					'page_hash'    => self::hash_of( 'seed.example/tieke-6604/' ),
					'page'         => 'seed.example/tieke-6604/',
					'server'       => 'seed.example',
					'shapes'       => 1,
					'hash'         => self::hash_of( 'https://seed.example/tieke-6604/' ),
					'url'          => 'https://seed.example/tieke-6604/',
					'count'        => 1,
					'timed_count'  => 1,
					'count_2xx'    => 1,
					'count_3xx'    => 0,
					'count_4xx'    => 0,
					'count_5xx'    => 0,
					'errors'       => 0,
					'sum_ms'       => 12.5,
					'min_ms'       => 12.5,
					'max_ms'       => 12.5,
					'sum_peak_mb'  => 10.5,
					'max_peak_mb'  => 10.5,
					'worker'       => false,
					'last_updated' => $at + 9.5,
					'avg_ms'       => 12.5,
					'avg_peak_mb'  => 10.5,
				],
			],
			$readers['data']
		);
		$this->assertSame( [ 2, 2, 3, 4 ], [ $readers['rows'], $readers['totals']['pages'], $readers['totals']['urls'], $readers['totals']['requests'] ] );
		$this->assertSame( [ 'seed.example/kotuku-6604/', 'seed.example/tieke-6604/' ], \array_column( $readers['slowest'], 'page' ), 'the slowest ten are pages' );
		$this->assertSame( [ 3, null, 4, true, $at + 14.5 ], [ $all['data'][0]['shapes'], $all['data'][0]['hash'], $all['data'][0]['count'], $all['data'][0]['worker'], $all['data'][0]['last_updated'] ] );
		$this->assertSame( [ 2, 2, 4, 5 ], [ $all['rows'], $all['totals']['pages'], $all['totals']['urls'], $all['totals']['requests'] ] );
	}

	/**
	 * `--page=<page hash>` answers that page's URLs, each a row as a URL row
	 * has always been, live and, for a question naming nothing else, off the
	 * snapshot, in the view asked for.
	 */
	public function test_page_answers_its_shapes(): void {
		$this->seed_kotuku();
		$page   = self::hash_of( 'seed.example/kotuku-6604/' );
		$tokens = "--page={$page} --sort=url --order=asc";

		$readers = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', $tokens );
		VerbHarness::reset();
		$all = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', "{$tokens} --include_workers" );

		$this->assertSame( [ 'https://seed.example/Kotuku-6604/?a=?', 'https://seed.example/kotuku-6604/?b=?' ], \array_column( $readers['data'], 'url' ) );
		$this->assertSame( [ self::hash_of( 'https://seed.example/Kotuku-6604/?a=?' ), self::hash_of( 'https://seed.example/kotuku-6604/?b=?' ) ], \array_column( $readers['data'], 'hash' ) );
		$this->assertSame( [ 'hash', 'url', 'count', 'timed_count', 'count_2xx', 'count_3xx', 'count_4xx', 'count_5xx', 'errors', 'sum_ms', 'min_ms', 'max_ms', 'sum_peak_mb', 'max_peak_mb', 'worker', 'last_updated', 'avg_ms', 'avg_peak_mb' ], \array_keys( $readers['data'][0] ) );
		$this->assertSame( [ 2, 2, 1, 3 ], [ $readers['rows'], $readers['totals']['urls'], $readers['totals']['pages'], $readers['totals']['requests'] ] );
		$this->assertSame( [ [], $page ], [ $readers['filters']['filter'], $readers['filters']['page'] ] );
		$this->assertSame( [ 'https://seed.example/Kotuku-6604/?a=?', 'https://seed.example/kotuku-6604/?b=?', 'https://seed.example/kotuku-6604/?cron=?' ], \array_column( $all['data'], 'url' ) );

		Url_Snapshot::publish( self::tick(), 600, static fn (): array => [] );
		$this->seed_request( [ 'url' => 'https://seed.example/KOTUKU-6604/?late=?', 'ms' => 5.5 ] );
		VerbHarness::reset();
		$served = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', $tokens );
		VerbHarness::reset();
		$served_all = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', "{$tokens} --include_workers" );

		$this->assertSame( $readers, $served, 'the snapshot, which predates the late shape' );
		$this->assertSame( $all, $served_all );
	}

	/**
	 * `--page` beside a filter token answers live: the snapshot reads one
	 * page and nothing else, so serving this question from it would drop the
	 * token in silence.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function page_filters_beside(): array {
		return [
			'a status class' => [ 'status_class=2' ],
			'a method'       => [ 'method=GET' ],
		];
	}

	#[DataProvider( 'page_filters_beside' )]
	public function test_a_page_beside_a_filter_is_answered_live( string $beside ): void {
		$this->seed_kotuku();
		Url_Snapshot::publish( self::tick(), 600, static fn (): array => [] );
		$this->seed_request( [ 'url' => 'https://seed.example/KOTUKU-6604/?late=?', 'ms' => 5.5 ] );
		$later       = self::tick() + 9;
		Core::$clock = static fn (): float => (float) $later;
		Core::right_now();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--page=' . self::hash_of( 'seed.example/kotuku-6604/' ), "--filter={$beside}" ] );

		$this->assertSame( $later, $reply['as_of'], 'dated by the live read, not the job' );
		$this->assertContains( 'https://seed.example/KOTUKU-6604/?late=?', \array_column( $reply['data'], 'url' ), 'the shape asked after the job ran' );
	}

	/**
	 * A `page=` filter narrows pages as any filter does: the listing is that
	 * one page's row, never its URLs.
	 */
	public function test_a_page_filter_lists_its_one_page_row(): void {
		$this->seed_kotuku();
		$page = self::hash_of( 'seed.example/kotuku-6604/' );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', "--filter=page={$page}" );

		$this->assertSame( [ [ $page, 2, 3 ] ], \array_map( static fn ( array $row ): array => [ $row['page_hash'], $row['shapes'], $row['count'] ], $reply['data'] ) );
		$this->assertSame( [ 1, 1 ], [ $reply['rows'], $reply['totals']['pages'] ] );
		$this->assertSame( '', $reply['filters']['page'] );
	}

	/**
	 * `--page` owns the page field: a `page=` or `page!=` filter beside it is
	 * refused, naming its owner, whatever page it names.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function page_tokens_beside_page(): array {
		return [
			'the same page'    => [ 'page=' . self::hash_of( 'seed.example/kotuku-6604/' ) ],
			'another page'     => [ 'page=' . self::hash_of( 'seed.example/tieke-6604/' ) ],
			'a page left out'  => [ 'page!=' . self::hash_of( 'seed.example/tieke-6604/' ) ],
		];
	}

	#[DataProvider( 'page_tokens_beside_page' )]
	public function test_a_page_filter_beside_page_is_refused( string $token ): void {
		$this->seed_kotuku();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--page=' . self::hash_of( 'seed.example/kotuku-6604/' ), "--filter={$token}" ] );

		$this->assertStringContainsString( 'belongs to --page: name the page with --page', Core::as_string( $reply ) );
	}

	/**
	 * One page logged under two spellings of its server is one row on one
	 * server, live and off the snapshot: a server is its host lowercased.
	 */
	public function test_a_page_under_two_server_spellings_is_one_row(): void {
		$this->seed_requests(
			[
				[ 'server' => 'kea-8812.example', 'url' => 'https://kea-8812.example/Hihi-8812/?a=?', 'ms' => 31.5 ],
				[ 'server' => 'kea-8812.example', 'url' => 'https://kea-8812.example/hihi-8812/?b=?', 'ms' => 42.5 ],
				[ 'server' => 'KEA-8812.example', 'url' => 'https://KEA-8812.example/HIHI-8812/', 'ms' => 53.5 ],
			]
		);
		$expected = [ [ 'kea-8812.example', 'kea-8812.example/hihi-8812/', 3, null, 3 ] ];
		$shape    = static fn ( array $reply ): array => \array_map( static fn ( array $row ): array => [ $row['server'], $row['page'], $row['shapes'], $row['hash'], $row['count'] ], $reply['data'] );

		$live = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		Url_Snapshot::publish( self::tick(), 600, static fn (): array => [] );
		VerbHarness::reset();
		$stored = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( self::tick(), $stored['as_of'], 'the snapshot answers' );
		$this->assertSame( $expected, $shape( $live ) );
		$this->assertSame( $expected, $shape( $stored ) );
	}

	/** A `--server` in any case names the server its host is, live and stored. */
	public function test_a_mixed_case_server_finds_its_rows(): void {
		$this->seed_request( [ 'server' => 'Kea-9907.example', 'url' => 'https://Kea-9907.example/Toroa-9907/', 'ms' => 21.5 ] );
		$this->seed_request( [ 'server' => 'moa-9907.example', 'url' => 'https://moa-9907.example/other/', 'ms' => 1.5 ] );

		$live = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=KEA-9907.Example' );
		Url_Snapshot::publish( self::tick(), 600, static fn (): array => [] );
		VerbHarness::reset();
		$stored = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=KEA-9907.Example' );

		foreach ( [ 'live' => $live, 'stored' => $stored ] as $read => $reply ) {
			$this->assertSame( [ 'kea-9907.example/toroa-9907/' ], \array_column( $reply['data'], 'page' ), $read );
			$this->assertSame( 1, $reply['totals']['requests'], $read );
		}
		$this->assertSame( self::tick(), $stored['as_of'], 'the snapshot answers' );
	}

	/**
	 * A one-shape page row names its URL as that URL's own row does, its
	 * case, its query and its scheme kept, an http one included, live and
	 * off the snapshot alike.
	 */
	public function test_a_one_shape_page_rows_url_keeps_its_urls_case_and_query(): void {
		$this->seed_requests(
			[
				[ 'url' => 'https://seed.example/Hoiho-3317/?kiwi=?', 'ms' => 21.5 ],
				[ 'url' => 'http://seed.example/Kaka-3317/', 'ms' => 32.5 ],
			]
		);
		$question = '--sort=url --order=asc';

		$live = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', $question );
		Url_Snapshot::publish( self::tick(), 600, static fn (): array => [] );
		VerbHarness::reset();
		$stored = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', $question );

		foreach ( [ $live, $stored ] as $reply ) {
			$this->assertSame(
				[
					[ 'seed.example/hoiho-3317/', 1, 'https://seed.example/Hoiho-3317/?kiwi=?' ],
					[ 'seed.example/kaka-3317/', 1, 'http://seed.example/Kaka-3317/' ],
				],
				\array_map( static fn ( array $row ): array => [ $row['page'], $row['shapes'], $row['url'] ], $reply['data'] )
			);
		}
	}

	/**
	 * A page of several shapes is named `https://` and its page, whatever
	 * scheme its shapes were asked under: an http and an https shape of one
	 * address fold into one page of two shapes.
	 */
	public function test_a_page_of_several_shapes_is_named_https_and_its_page(): void {
		$this->seed_requests(
			[
				[ 'url' => 'http://seed.example/Kea-3317/', 'ms' => 43.5 ],
				[ 'url' => 'https://seed.example/kea-3317/?ruru=?', 'ms' => 54.5 ],
			]
		);

		$live = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		Url_Snapshot::publish( self::tick(), 600, static fn (): array => [] );
		VerbHarness::reset();
		$stored = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		foreach ( [ $live, $stored ] as $reply ) {
			$this->assertSame(
				[ [ 'seed.example/kea-3317/', 2, null, 'https://seed.example/kea-3317/' ] ],
				\array_map( static fn ( array $row ): array => [ $row['page'], $row['shapes'], $row['hash'], $row['url'] ], $reply['data'] )
			);
		}
	}

	/**
	 * The traffic the live and stored answers are held together over: two
	 * servers of more than one page, a third whose capital sorts it first
	 * by name and last by page, a page of visitor and worker shapes, a
	 * worker-only page, an untimed page, a timeout, and a one-shape http page
	 * whose URL keeps its case and query.
	 */
	private function seed_page_parity(): void {
		$this->seed_kotuku();
		$at = self::latest() * 300;
		$this->seed_requests(
			[
				[ 'server' => 'kahu-6604.example', 'url' => 'https://kahu-6604.example/Ruru-6604/?q=?', 'ms' => null, 'error' => 'T', 'status' => 0, 'peak_mb' => 15.25, 'finished_at' => $at + 21.0 ],
				[ 'server' => 'kahu-6604.example', 'url' => 'https://kahu-6604.example/ruru-6604/', 'ms' => 30.25, 'peak_mb' => 25.75, 'finished_at' => $at + 22.0 ],
				[ 'server' => 'kahu-6604.example', 'url' => 'https://kahu-6604.example/titi-6604/', 'ms' => 55.0, 'peak_mb' => 18.5, 'finished_at' => $at + 25.0 ],
				[ 'server' => 'kahu-6604.example', 'url' => 'http://kahu-6604.example/Pipi-6604/?tuatua=?', 'ms' => 88.25, 'peak_mb' => 12.5, 'finished_at' => $at + 27.0 ],
				[ 'server' => 'Weka-6604.example', 'url' => 'https://Weka-6604.example/Moa-6604/', 'ms' => 66.5, 'peak_mb' => 19.5, 'finished_at' => $at + 26.0 ],
				[ 'url' => 'https://seed.example/jobs/weka-6604', 'ms' => 77.5, 'peak_mb' => 35.25, 'worker' => 1, 'finished_at' => $at + 23.0 ],
				[ 'url' => 'https://seed.example/huia-6604/', 'ms' => null, 'peak_mb' => 45.75, 'finished_at' => $at + 24.0 ],
			],
			0
		);
	}

	/**
	 * Every sort, both orders, both scopes and both views, of pages and of a
	 * page's shapes.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:bool,4:bool}>
	 */
	public static function page_questions(): array {
		$cases = [];
		foreach ( \array_keys( Performance_CI_Node::URL_SORTS ) as $sort ) {
			foreach ( [ 'asc', 'desc' ] as $order ) {
				foreach ( [ '', 'kahu-6604.example' ] as $server ) {
					foreach ( [ false, true ] as $workers ) {
						foreach ( [ false, true ] as $shapes ) {
							$cases[ "{$sort} {$order} '{$server}' " . ( $workers ? 'workers' : 'no workers' ) . ( $shapes ? ' shapes' : ' pages' ) ] = [ $sort, $order, $server, $workers, $shapes ];
						}
					}
				}
			}
		}
		return $cases;
	}

	/**
	 * A live answer gives the snapshot's figures for the same question,
	 * pages or a page's shapes, in every sort, order, scope and view.
	 */
	#[DataProvider( 'page_questions' )]
	public function test_live_and_snapshot_pages_agree( string $sort, string $order, string $server, bool $workers, bool $shapes ): void {
		$this->seed_page_parity();
		$page   = '' === $server ? 'seed.example/kotuku-6604/' : 'kahu-6604.example/ruru-6604/';
		$tokens = [ "--sort={$sort}", "--order={$order}", '--limit=3', $shapes ? '--page=' . self::hash_of( $page ) : '--offset=1' ];
		if ( '' !== $server ) {
			$tokens[] = "--server={$server}";
		}
		if ( $workers ) {
			$tokens[] = '--include_workers';
		}
		Url_Snapshot::publish( self::tick(), 600, static fn (): array => [] );
		$live = Performance_CI_Node::answer_live( 'urls', Command_Args::bind( Command_Interpreter_Node::declared_verbs( Performance_CI_Node::class )['urls']['args'], $tokens ), self::tick(), null );
		$this->seed_requests(
			[
				[ 'url' => 'https://seed.example/KOTUKU-6604/?late=?', 'ms' => 3.5 ],
				[ 'server' => 'kahu-6604.example', 'url' => 'https://kahu-6604.example/RURU-6604/?late=?', 'ms' => 4.5 ],
			]
		);

		$served = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', $tokens );

		$this->assertNotSame( [], $live['data'], 'the question lists real rows' );
		if ( ! $shapes && '' !== $server ) {
			$this->assertContains( 'http://kahu-6604.example/Pipi-6604/?tuatua=?', \array_column( $live['slowest'], 'url' ), 'a one-shape page names its URL' );
		}
		// The reply crosses the wire as JSON, as a whole float reads back an int.
		$this->assertSame( \json_decode( (string) \wp_json_encode( $live ), true ), $served );
	}

	public function test_urls_totals_count_distinct_urls_exactly(): void {
		$kakapo = 'https://seed.example/kakapo/';
		$this->seed_request( [ 'url' => $kakapo, 'status' => 200, 'ms' => 100.25, 'peak_mb' => 20.25, 'finished_at' => self::latest() * 300 + 11.5 ] );
		$this->seed_request( [ 'url' => $kakapo, 'status' => 404, 'ms' => 300.25, 'peak_mb' => 40.25, 'finished_at' => self::latest() * 300 + 42.25 ] );
		$this->seed_request( [ 'url' => $kakapo, 'status' => 503, 'ms' => null, 'error' => 'T', 'peak_mb' => 30.25 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/tui/', 'status' => 301, 'ms' => 50.25, 'peak_mb' => 10.25 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( 2, $reply['totals']['urls'] );
		$this->assertSame( 2, $reply['totals']['pages'] );
		$this->assertSame( 2, $reply['rows'] );
		$this->assertSame( 4, $reply['totals']['requests'] );
		$this->assertSame( 150.25, $reply['totals']['avg_ms'] );
		$this->assertSame( 25.25, $reply['totals']['avg_peak_mb'] );
		$this->assertEqualsWithDelta( 4 / ( ( Bucket::window() - 1 ) * Bucket::SECONDS + self::tick() % Bucket::SECONDS ), $reply['totals']['requests_per_second'], 1e-9, 'over the window\'s elapsed seconds' );
		$this->assertSame( 1, $reply['totals']['errors'], 'every reply counts its timeouts and fatals' );
		$this->assertSame(
			[
				'page_hash'    => self::hash_of( 'seed.example/kakapo/' ),
				'page'         => 'seed.example/kakapo/',
				'server'       => 'seed.example',
				'shapes'       => 1,
				'hash'         => self::hash_of( $kakapo ),
				'url'          => $kakapo,
				'count'        => 3,
				'timed_count'  => 2,
				'count_2xx'    => 1,
				'count_3xx'    => 0,
				'count_4xx'    => 1,
				'count_5xx'    => 1,
				'errors'       => 1,
				'sum_ms'       => 400.5,
				'min_ms'       => 100.25,
				'max_ms'       => 300.25,
				'sum_peak_mb'  => 90.75,
				'max_peak_mb'  => 40.25,
				'worker'       => false,
				'last_updated' => self::latest() * 300 + 42.25,
				'avg_ms'       => 200.25,
				'avg_peak_mb'  => 30.25,
			],
			$reply['data'][0]
		);
		$this->assertSame( [ 'as_of', 'data', 'rows', 'totals', 'slowest', 'filters', 'limit', 'offset', 'available' ], \array_keys( $reply ) );
	}

	public function test_urls_sorts_by_avg_ms_request_weighted(): void {
		$latest = self::latest();
		foreach ( [ 10.5, 10.5, 10.5 ] as $ms ) {
			$this->seed_request( [ 'url' => 'https://seed.example/x/', 'ms' => $ms ] );
		}
		$this->seed_request( [ 'url' => 'https://seed.example/x/', 'ms' => 970.0, 'bucket' => $latest - 1 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/y/', 'ms' => 300.0 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/z/', 'ms' => 200.0 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/z/', 'ms' => 200.0 ] );

		$desc = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--sort=avg_ms --order=desc' );

		$this->assertSame( [ 'seed.example/y/', 'seed.example/x/', 'seed.example/z/' ], \array_column( $desc['data'], 'page' ), 'x averages 250 over its requests, not 490 over its buckets' );
		$this->assertSame( 250.375, $desc['data'][1]['avg_ms'] );
		$this->assertSame( \array_column( $desc['data'], 'page' ), \array_column( $desc['slowest'], 'page' ), 'the slowest ten rank by the same mean' );
	}

	public function test_an_untimed_url_ranks_last_on_a_timed_sort_in_either_order(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/timed-a/', 'ms' => 40.0 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/untimed/', 'ms' => null, 'error' => 'T' ] );
		$this->seed_request( [ 'url' => 'https://seed.example/timed-b/', 'ms' => 90.0 ] );

		foreach ( [ 'asc' => [ 'timed-a', 'timed-b' ], 'desc' => [ 'timed-b', 'timed-a' ] ] as $order => $timed ) {
			foreach ( [ 'avg_ms', 'min_ms', 'max_ms' ] as $sort ) {
				VerbHarness::reset();
				$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', "--sort={$sort} --order={$order}" );
				$this->assertSame(
					\array_map( static fn ( string $path ): string => "seed.example/{$path}/", [ ...$timed, 'untimed' ] ),
					\array_column( $reply['data'], 'page' ),
					"{$sort} {$order}"
				);
			}
		}
		$this->assertNull( $reply['data'][2]['avg_ms'] );
		$this->assertNull( $reply['data'][2]['min_ms'] );
		$this->assertNull( $reply['data'][2]['max_ms'] );
	}

	public function test_urls_sorts_by_the_displayed_url_and_by_last_seen(): void {
		$latest = self::latest();
		$this->seed_request( [ 'url' => 'https://seed.example/weka/', 'finished_at' => $latest * 300 + 3.0 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/aaa-moa/', 'finished_at' => $latest * 300 + 1.0 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/kiwi/', 'finished_at' => $latest * 300 + 2.0 ] );

		$by_name = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--sort=url --order=asc' );
		VerbHarness::reset();
		$by_seen = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--sort=last_updated --order=desc' );

		$this->assertSame( [ 'seed.example/aaa-moa/', 'seed.example/kiwi/', 'seed.example/weka/' ], \array_column( $by_name['data'], 'page' ) );
		$this->assertSame( [ 'seed.example/weka/', 'seed.example/kiwi/', 'seed.example/aaa-moa/' ], \array_column( $by_seen['data'], 'page' ) );
	}

	public function test_urls_pages_with_limit_and_offset_and_counts_every_row(): void {
		foreach ( [ 'a' => 5, 'b' => 4, 'c' => 3, 'd' => 2 ] as $path => $n ) {
			for ( $i = 0; $i < $n; $i++ ) {
				$this->seed_request( [ 'url' => "https://seed.example/{$path}/" ] );
			}
		}

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--limit=2 --offset=1' );

		$this->assertSame( [ 'seed.example/b/', 'seed.example/c/' ], \array_column( $reply['data'], 'page' ) );
		$this->assertSame( 4, $reply['rows'] );
		$this->assertSame( 2, $reply['limit'] );
		$this->assertSame( 1, $reply['offset'] );
	}

	public function test_urls_clamps_its_limit_and_offset(): void {
		$high = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--limit=73100 --offset=73100' );
		VerbHarness::reset();
		$low = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--limit=0' );

		$this->assertSame( 1000, $high['limit'] );
		$this->assertSame( 10000, $high['offset'] );
		$this->assertSame( 1, $low['limit'] );
	}

	public function test_urls_search_matches_path_words(): void {
		foreach ( [ 'https://seed.example/wombat-7731/', 'https://seed.example/wombats/', 'https://seed.example/about/wombat/' ] as $url ) {
			$this->seed_request( [ 'url' => $url ] );
		}
		$this->seed_request( [ 'url' => 'https://wombat.example/kea/', 'server' => 'wombat.example' ] );

		$whole = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--search=wombat --sort=url --order=asc' );
		VerbHarness::reset();
		$syntax = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search="wombat" -7731' ] );

		$this->assertSame( [ 'seed.example/about/wombat/', 'seed.example/wombat-7731/' ], \array_column( $whole['data'], 'page' ), 'a whole word of the path, never the host' );
		$this->assertSame( 2, $whole['totals']['urls'] );
		$this->assertSame( 'wombat', $whole['filters']['search'] );
		$this->assertSame( [ 'seed.example/wombat-7731/' ], \array_column( $syntax['data'], 'page' ), 'quotes and a minus are words, not operators' );
	}

	public function test_urls_refuses_a_search_with_no_word(): void {
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=*-"' ] );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'holds no word', $reply );
	}

	public function test_urls_filter_narrows_rows_totals_and_the_slowest_ten(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/huia-503/', 'status' => 503, 'ms' => 120.5 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/huia-503/', 'status' => 503, 'ms' => 140.5 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/huia-503/', 'status' => 503, 'ms' => 160.5 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/kahu-200/', 'ms' => 9000.5 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/kahu-200/', 'ms' => 9100.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=status_class=5' );

		$this->assertSame( [ 'seed.example/huia-503/' ], \array_column( $reply['data'], 'page' ) );
		$this->assertSame( [ 1, 3, 1 ], [ $reply['totals']['urls'], $reply['totals']['requests'], $reply['rows'] ] );
		$this->assertSame( [ 'seed.example/huia-503/' ], \array_column( $reply['slowest'], 'page' ), 'the slowest ten are the filtered set\'s' );
		$this->assertSame( [ 'status_class=5' ], $reply['filters']['filter'] );
		$this->assertSame( 0, $reply['totals']['errors'], 'every reply counts its errors' );
	}

	public function test_urls_filter_on_worker_replaces_the_include_workers_scope(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/w?pukeko', 'ms' => 4400.5, 'worker' => 1 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/tauhou/', 'ms' => 12.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=worker=1' );

		$this->assertSame( [ 'seed.example/w' ], \array_column( $reply['data'], 'page' ) );
		$this->assertFalse( $reply['filters']['include_workers'] );
	}

	public function test_urls_two_filters_on_one_field_match_either(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/ruru-503/', 'status' => 503 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/ruru-404/', 'status' => 404 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/ruru-200/', 'status' => 200 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=status_class=5 --filter=status_class=4 --sort=url --order=asc' );

		$this->assertSame( [ 'seed.example/ruru-404/', 'seed.example/ruru-503/' ], \array_column( $reply['data'], 'page' ) );
		$this->assertSame( 2, $reply['totals']['requests'] );
	}

	public function test_urls_keeps_a_url_filter(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/kakariki-in/' ] );
		$this->seed_request( [ 'url' => 'https://seed.example/kakariki-out/' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=url=' . self::hash_of( 'https://seed.example/kakariki-in/' ) );

		$this->assertSame( [ 'seed.example/kakariki-in/' ], \array_column( $reply['data'], 'page' ) );
	}

	public function test_urls_refuses_a_filter_with_no_field(): void {
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=kakapo' );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'filter kakapo is not a field=value pair', $reply );
	}

	/**
	 * `--server` owns the server: a filter naming one is refused, with or
	 * without `--server` beside it.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function server_filter_refusals(): array {
		$hash = Log_Manager::url_hash( 'https://kahu-9.example/hoiho/' );
		return [
			'urls'                    => [ 'urls', '--filter=server=kahu-9.example' ],
			'urls beside --server'    => [ 'urls', '--server=kahu-9.example --filter=server=kahu-9.example' ],
			'dump_url'                => [ 'dump_url', $hash . ' --filter=server=kahu-9.example' ],
			'dump_url beside --server' => [ 'dump_url', $hash . ' --server=ruru-4.example --filter=server=kahu-9.example' ],
			'ask overview:'           => [ 'ask', 'overview:site --filter=server=kahu-9.example' ],
			'ask url:'                => [ 'ask', 'url:' . $hash . ' --server=kahu-9.example --filter=server=kahu-9.example' ],
			'urls, field capitalised' => [ 'urls', '--filter=Server=kahu-9.example' ],
			'ask url:, field capitalised' => [ 'ask', 'url:' . $hash . ' --filter=SERVER=kahu-9.example' ],
		];
	}

	#[DataProvider( 'server_filter_refusals' )]
	public function test_a_filter_naming_server_is_refused_for_server( string $verb, string $tokens ): void {
		$this->seed_request( [ 'server' => 'kahu-9.example', 'url' => 'https://kahu-9.example/hoiho/' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, $tokens );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( "filter server=kahu-9.example belongs to --server: name the server with --server", $reply );
	}

	/**
	 * `--bucket` owns the buckets, and on a one-URL verb the hash owns the
	 * URL: a filter naming either is refused, naming its owner.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function owned_filter_refusals(): array {
		$hash   = Log_Manager::url_hash( 'https://kahu-9.example/hoiho/' );
		$key    = '2026-09-21-04-35';
		$bucket = "filter bucket={$key} belongs to --bucket: name the buckets with --bucket";
		$url    = "filter url=0ddba11c4e5f belongs to the hash: name the URL with the hash";
		return [
			'urls bucket'               => [ 'urls', "--filter=bucket={$key}", $bucket ],
			'urls bucket beside --bucket' => [ 'urls', "--bucket={$key} --filter=bucket={$key}", $bucket ],
			'dump_url bucket'           => [ 'dump_url', "{$hash} --filter=bucket={$key}", $bucket ],
			'dump_url url'              => [ 'dump_url', "{$hash} --filter=url=0ddba11c4e5f", $url ],
			'ask overview: bucket'      => [ 'ask', "overview:site --filter=bucket={$key}", $bucket ],
			'ask url: bucket'           => [ 'ask', "url:{$hash} --filter=bucket={$key}", $bucket ],
			'ask url: url'              => [ 'ask', "url:{$hash} --filter=url=0ddba11c4e5f", $url ],
			'dump_url url capitalised'  => [ 'dump_url', "{$hash} --filter=URL=0ddba11c4e5f", $url ],
			'urls server negated'       => [ 'urls', '--filter=server!=kahu-9.example', "filter server!=kahu-9.example belongs to --server: name the server with --server" ],
			'urls bucket negated'       => [ 'urls', "--filter=bucket!={$key}", "filter bucket!={$key} belongs to --bucket: name the buckets with --bucket" ],
			'dump_url url negated'      => [ 'dump_url', "{$hash} --filter=url!=0ddba11c4e5f", "filter url!=0ddba11c4e5f belongs to the hash: name the URL with the hash" ],
			'ask overview: server negated' => [ 'ask', 'overview:site --filter=server!=kahu-9.example', "filter server!=kahu-9.example belongs to --server: name the server with --server" ],
		];
	}

	#[DataProvider( 'owned_filter_refusals' )]
	public function test_a_filter_naming_an_owned_field_is_refused_for_its_owner( string $verb, string $tokens, string $refusal ): void {
		$this->seed_request( [ 'server' => 'kahu-9.example', 'url' => 'https://kahu-9.example/hoiho/' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, $tokens );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( $refusal, $reply );
	}

	public function test_query_keeps_a_server_filter(): void {
		$this->seed_request( [ 'server' => 'kahu-9.example', 'url' => 'https://kahu-9.example/hoiho/' ] );
		$this->seed_request( [ 'server' => 'ruru-4.example', 'url' => 'https://ruru-4.example/hoiho/' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', 'requests --measure=count --filter=server=kahu-9.example' );

		$this->assertSame( [ [ 1 ] ], $reply['rows'] );
	}

	public function test_a_filter_field_matches_in_any_case_and_a_repeated_token_counts_once(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/tui-sg/', 'country' => 'SG' ] );
		$this->seed_request( [ 'url' => 'https://seed.example/tui-us/', 'country' => 'US' ] );
		$this->seed_request( [ 'url' => 'https://seed.example/tui-nz/', 'country' => 'NZ' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', 'requests --measure=count --filter=Country=SG --filter=country=US --filter=COUNTRY=US' );

		$this->assertSame( [ [ 2 ] ], $reply['rows'] );
		$this->assertSame( [ 'country' => [ 'SG', 'US' ] ], $reply['filters']['filter'] );
	}

	public function test_urls_filter_field_matches_in_any_case_and_echoes_each_token_once(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/weka-sg/', 'country' => 'SG' ] );
		$this->seed_request( [ 'url' => 'https://seed.example/weka-nz/', 'country' => 'NZ' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=Country=SG --filter=country=SG' );

		$this->assertSame( [ 'seed.example/weka-sg/' ], \array_column( $reply['data'], 'page' ) );
		$this->assertSame( [ 'country=SG' ], $reply['filters']['filter'] );
	}

	public function test_urls_negated_filter_keeps_the_urls_matching_none_of_its_values(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/toroa-nz/', 'country' => 'NZ' ] );
		$this->seed_request( [ 'url' => 'https://seed.example/toroa-sg/', 'country' => 'SG' ] );
		$this->seed_request( [ 'url' => 'https://seed.example/toroa-xx/', 'country' => null ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=Country!=NZ --filter=country!=SG --filter=country!=SG' );

		$this->assertSame( [ 'seed.example/toroa-xx/' ], \array_column( $reply['data'], 'page' ) );
		$this->assertSame( [ 'country!=NZ', 'country!=SG' ], $reply['filters']['filter'] );
	}

	public function test_urls_negated_worker_filter_replaces_the_include_workers_scope(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/w?kotare', 'worker' => 1 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/kotare/' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=worker!=0' );

		$this->assertSame( [ 'seed.example/w' ], \array_column( $reply['data'], 'page' ) );
	}

	public function test_dump_url_negated_filter_narrows_its_stats(): void {
		$url = 'https://seed.example/kahu-neg/';
		$this->seed_request( [ 'url' => $url, 'status' => 200 ] );
		$this->seed_request( [ 'url' => $url, 'status' => 503 ] );
		$this->seed_request( [ 'url' => $url, 'status' => 404 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) . ' --filter=status_class!=2' );

		$this->assertSame( 2, $reply['stats']['count'] );
	}

	public function test_query_negated_bucket_filter_takes_a_key(): void {
		$latest = self::latest();
		$this->seed_request( [ 'bucket' => $latest - 3 ] );
		$this->seed_request( [ 'bucket' => $latest - 4 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'query', 'requests --measure=count --filter=bucket!=' . Bucket::key( $latest - 3 ) );

		$this->assertSame( [ [ 1 ] ], $reply['rows'] );
	}

	public function test_dump_url_filter_field_matches_in_any_case(): void {
		$url = 'https://seed.example/kereru/';
		$this->seed_request( [ 'url' => $url, 'country' => 'SG' ] );
		$this->seed_request( [ 'url' => $url, 'country' => 'NZ' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) . ' --filter=Country=SG' );

		$this->assertSame( 1, $reply['stats']['count'] );
	}

	public function test_urls_refuses_errors_only(): void {
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--errors_only' );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'unknown option --errors_only', $reply );
	}

	public function test_urls_error_filters_keep_exactly_the_timeouts_and_fatals(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/piopio-t/', 'error' => 'T', 'status' => 0, 'ms' => null ] );
		$this->seed_request( [ 'url' => 'https://seed.example/piopio-f/', 'error' => 'F', 'status' => 200, 'ms' => 61.5 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/piopio-a/', 'error' => 'A', 'status' => 0, 'ms' => null ] );
		$this->seed_request( [ 'url' => 'https://seed.example/piopio-503/', 'status' => 503, 'ms' => 41.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=error=T --filter=error=F --sort=url --order=asc' );

		$this->assertSame( [ 'seed.example/piopio-f/', 'seed.example/piopio-t/' ], \array_column( $reply['data'], 'page' ), 'an abort and a 5xx are not errors; a fatal after headers is one' );
		$this->assertSame( [ 2, 2 ], [ $reply['totals']['requests'], $reply['totals']['errors'] ] );
	}

	public function test_status_class_unknown_finds_a_timeout(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/titi-timeout/', 'error' => 'T', 'status' => 0, 'ms' => null ] );
		$this->seed_request( [ 'url' => 'https://seed.example/titi-ok/', 'ms' => 30.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=status_class=Unknown' );

		$this->assertSame( [ 'seed.example/titi-timeout/' ], \array_column( $reply['data'], 'page' ) );
		$this->assertSame( 1, $reply['totals']['errors'] );
	}

	public function test_urls_include_workers_false_drops_worker_urls(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/reader/', 'ms' => 12.5 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/reader/', 'ms' => 12.5 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/w?reconcile', 'ms' => 180000.0, 'worker' => 1 ] );

		$readers = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		VerbHarness::reset();
		$all = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--include_workers' );

		$this->assertSame( [ 'seed.example/reader/' ], \array_column( $readers['data'], 'page' ) );
		$this->assertSame( [ 1, 2, 12.5 ], [ $readers['totals']['urls'], $readers['totals']['requests'], $readers['totals']['avg_ms'] ] );
		$this->assertFalse( $readers['filters']['include_workers'] );
		$this->assertSame( [ 2, 3 ], [ $all['totals']['urls'], $all['totals']['requests'] ] );
		$this->assertSame( [ 'seed.example/reader/' => false, 'seed.example/w' => true ], \array_column( $all['data'], 'worker', 'page' ) );
	}

	public function test_urls_server_scopes_every_row_and_total(): void {
		$this->seed_request( [ 'server' => 'alpha.example', 'url' => 'https://alpha.example/shared/', 'status' => 404 ] );
		$this->seed_request( [ 'server' => 'beta.example', 'url' => 'https://beta.example/shared/', 'status' => 200 ] );
		$this->seed_request( [ 'server' => 'beta.example', 'url' => 'https://beta.example/shared/', 'status' => 200 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=alpha.example' );

		$this->assertSame( [ 'alpha.example/shared/' ], \array_column( $reply['data'], 'page' ) );
		$this->assertSame( [ 1, 0, 1 ], [ $reply['data'][0]['count'], $reply['data'][0]['count_2xx'], $reply['data'][0]['count_4xx'] ] );
		$this->assertSame( 1, $reply['totals']['requests'] );
		$this->assertSame(
			[ 'server' => 'alpha.example', 'page' => '', 'search' => '', 'filter' => [], 'include_workers' => false, 'bucket' => '' ],
			$reply['filters']
		);
	}

	public function test_urls_bucket_selection_narrows_to_its_buckets(): void {
		$latest = self::latest();
		$this->seed_request( [ 'url' => 'https://seed.example/a/', 'ms' => 40.5, 'bucket' => $latest - 10 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/b/', 'ms' => 60.5, 'bucket' => $latest - 3 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/a/', 'ms' => 500.0 ] );
		$selection = Bucket::key( $latest - 3 ) . ',' . Bucket::key( $latest - 10 ) . '..' . Bucket::key( $latest - 8 );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', "--bucket={$selection} --sort=url --order=asc" );

		$this->assertSame( [ 'seed.example/a/' => 40.5, 'seed.example/b/' => 60.5 ], \array_column( $reply['data'], 'avg_ms', 'page' ) );
		$this->assertSame( 2, $reply['totals']['requests'] );
		$this->assertEqualsWithDelta( 2 / 900, $reply['totals']['requests_per_second'], 1e-12, 'three closed buckets of 300 seconds' );
		$this->assertSame( Bucket::key( $latest - 10 ) . '..' . Bucket::key( $latest - 8 ) . ',' . Bucket::key( $latest - 3 ), $reply['filters']['bucket'], 'the canonical spelling' );
	}

	public function test_urls_refuses_a_bucket_in_the_future(): void {
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--bucket=' . Bucket::key( self::latest() + 1 ) );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'is in the future', $reply );
	}

	public function test_stats_rows_that_will_not_open_answer_no_stats(): void {
		$this->seed_request( [ 'ms' => 88.0 ] );
		$this->seed_site_timing();
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Event_Logger_Nodes\Config::reset();

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );
		VerbHarness::reset();
		$urls = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertNull( $overview['totals'], 'no totals, never zeroes' );
		$this->assertFalse( $overview['available'] );
		$this->assertSame( [], $overview['servers'] );
		$this->assertSame( [ 'count' => 0, 'total_time' => null, 'avg_ms' => null, 'categories' => [] ], $overview['leaderboard'] );
		$this->assertSame( [], $urls['data'] );
		$this->assertSame( 0, $urls['rows'] );
		$this->assertNull( $urls['totals'], 'no totals, never zeroes' );
		$this->assertFalse( $urls['available'] );
		$this->assertNotEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'performance: stats rows did not open' ) ),
			'the reader says so'
		);
	}

	/**
	 * The overview brief lists the table's pages: each names its shapes and
	 * the hash of its busiest one, so a page of several still reaches a URL.
	 */
	public function test_ask_overview_briefs_the_pages_and_the_board(): void {
		$this->seed_requests(
			[
				[ 'server' => 'alpha.example', 'url' => 'https://alpha.example/Ruru-4471/?a=?', 'ms' => 900.5 ],
				[ 'server' => 'alpha.example', 'url' => 'https://alpha.example/ruru-4471/?b=?', 'ms' => 300.5 ],
				[ 'server' => 'alpha.example', 'url' => 'https://alpha.example/ruru-4471/?b=?', 'ms' => 300.5 ],
				[ 'server' => 'alpha.example', 'url' => 'https://alpha.example/ruru-4471/?b=?', 'ms' => 300.5 ],
				[ 'server' => 'alpha.example', 'url' => 'https://alpha.example/ruru-4471/', 'ms' => 120.5 ],
				[ 'server' => 'alpha.example', 'url' => 'https://alpha.example/tui-4471/', 'ms' => 40.5, 'profiled' => 1 ],
				[ 'server' => 'beta.example', 'url' => 'https://beta.example/fast/', 'ms' => 30.0, 'profiled' => 1 ],
			]
		);
		$this->seed_site_timing( [ 'server' => 'alpha.example', 'category' => 'wpdb', 'ms' => 44.4, 'calls' => 74, 'requests' => 1 ] );

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site --server=alpha.example --include_workers=1' );

		$this->assertSame( 'alpha.example', $brief['server'] );
		$this->assertSame( [ 'urls' => 4, 'pages' => 2, 'requests' => 6 ], \array_intersect_key( $brief['stats'], [ 'urls' => 1, 'pages' => 1, 'requests' => 1 ] ) );
		$this->assertSame(
			[
				[ self::hash_of( 'alpha.example/ruru-4471/' ), 'alpha.example/ruru-4471/', 'alpha.example', 3, self::hash_of( 'https://alpha.example/ruru-4471/?b=?' ), 5 ],
				[ self::hash_of( 'alpha.example/tui-4471/' ), 'alpha.example/tui-4471/', 'alpha.example', 1, self::hash_of( 'https://alpha.example/tui-4471/' ), 1 ],
			],
			\array_map( static fn ( array $p ): array => [ $p['page_hash'], $p['page'], $p['server'], $p['shapes'], $p['busiest_hash'], $p['count'] ], $brief['pages'] ),
			'busiest first; the busiest shape by count, not the slowest'
		);
		$this->assertSame( 'wpdb', $brief['categories'][0]['name'] );
		$this->assertEqualsWithDelta( 44.4, $brief['categories'][0]['avg_time_ms'], 1e-9 );
		$this->assertTrue( $brief['filters']['include_workers'] );
		$this->assertArrayNotHasKey( 'estimated', $brief );
		$this->assertArrayNotHasKey( 'provisional', $brief );
		$this->assertSame( 'alpha.example', $brief['fetch'][0]['arguments']['server'] );
	}

	/**
	 * A busiest shape is the busiest of the set the brief describes, under
	 * the brief's filter, and a page whose server is logged in two spellings
	 * is one page on one server.
	 */
	public function test_an_overview_briefs_busiest_shape_is_of_its_filtered_set(): void {
		$rows = [];
		foreach ( [ [ 'kaka-5309.example', 'Weta-5309/?a=?', 503, 1 ], [ 'kaka-5309.example', 'weta-5309/?b=?', 502, 2 ], [ 'kaka-5309.example', 'weta-5309/?c=?', 200, 5 ], [ 'KAKA-5309.example', 'WETA-5309/?d=?', 504, 4 ], [ 'KAKA-5309.example', 'WETA-5309/?e=?', 500, 1 ] ] as [ $server, $path, $status, $times ] ) {
			for ( $i = 0; $i < $times; $i++ ) {
				$rows[] = [ 'server' => $server, 'url' => "https://{$server}/{$path}", 'status' => $status ];
			}
		}
		$this->seed_requests( $rows );

		$pages = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site --filter=status_class=5' )['pages'];

		$this->assertSame(
			[ [ 'kaka-5309.example', 4, self::hash_of( 'https://KAKA-5309.example/WETA-5309/?d=?' ) ] ],
			\array_map( static fn ( array $page ): array => [ $page['server'], $page['shapes'], $page['busiest_hash'] ], $pages ),
			'the 5xx shapes alone, the busiest of them by count'
		);
	}

	/**
	 * Under a `page=` chip the brief lists that one page and reads its
	 * busiest URL through that page's `--page` question, which owns the page
	 * field, so the chip does not ride along.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function page_chips(): array {
		return [
			'the page kept'      => [ 'page=' . self::hash_of( 'seed.example/kotuku-6604/' ) ],
			'another left out'   => [ 'page!=' . self::hash_of( 'seed.example/tieke-6604/' ) ],
		];
	}

	#[DataProvider( 'page_chips' )]
	public function test_an_overview_brief_under_a_page_chip_names_its_busiest_shape( string $chip ): void {
		$this->seed_kotuku();

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "overview:site --filter={$chip}" );

		$this->assertIsArray( $brief, \is_string( $brief ) ? $brief : 'the brief answered no structure' );
		$this->assertSame(
			[ [ self::hash_of( 'seed.example/kotuku-6604/' ), 2, self::hash_of( 'https://seed.example/Kotuku-6604/?a=?' ) ] ],
			\array_map( static fn ( array $page ): array => [ $page['page_hash'], $page['shapes'], $page['busiest_hash'] ], $brief['pages'] )
		);
	}

	/**
	 * Seeds `pateke-7720` with `?a=?` its busiest shape, publishes the
	 * snapshot, then makes `?b=?` the busiest and adds a busier page the
	 * snapshot never saw, nine seconds on.
	 *
	 * @return int When the snapshot was built.
	 */
	private function seed_pateke_behind_its_snapshot(): int {
		$this->seed_requests(
			[
				[ 'url' => 'https://seed.example/Pateke-7720/?a=?', 'ms' => 70.5 ],
				[ 'url' => 'https://seed.example/pateke-7720/?a=?', 'ms' => 70.5 ],
				[ 'url' => 'https://seed.example/pateke-7720/?b=?', 'ms' => 20.5 ],
				[ 'url' => 'https://seed.example/toroa-7720/', 'ms' => 10.5 ],
			]
		);
		$built = self::tick();
		Url_Snapshot::publish( $built, 600, static fn (): array => [] );
		$late = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$late[] = [ 'url' => 'https://seed.example/pateke-7720/?b=?', 'ms' => 20.5 ];
		}
		for ( $i = 0; $i < 9; $i++ ) {
			$late[] = [ 'url' => 'https://seed.example/hoiho-7720/', 'ms' => 5.5 ];
		}
		$this->seed_requests( $late );
		$later       = $built + 9;
		Core::$clock = static fn (): float => (float) $later;
		Core::right_now();
		return $built;
	}

	/**
	 * The default brief lists the pages the default table shows, off the
	 * same snapshot, and names the busiest shape the snapshot holds.
	 */
	public function test_a_stored_overview_brief_lists_the_stored_tables_pages(): void {
		$built = $this->seed_pateke_behind_its_snapshot();

		$table = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		VerbHarness::reset();
		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site' );

		$this->assertSame( $built, $table['as_of'], 'the table reads the snapshot' );
		$this->assertSame( $built, $brief['as_of'], 'the brief is as old as the snapshot' );
		$this->assertSame( \array_column( $table['data'], 'count', 'page_hash' ), \array_column( $brief['pages'], 'count', 'page_hash' ) );
		$this->assertSame( $table['totals']['pages'], $brief['stats']['pages'] );
		$this->assertSame(
			[ self::hash_of( 'https://seed.example/pateke-7720/?a=?' ), self::hash_of( 'https://seed.example/toroa-7720/' ) ],
			\array_column( $brief['pages'], 'busiest_hash' ),
			'the busiest shape as the snapshot holds it'
		);
	}

	/**
	 * A brief the snapshot cannot answer reads live, as the table does under
	 * the same question, busiest shape included.
	 */
	public function test_a_live_overview_brief_lists_the_live_tables_pages(): void {
		$built = $this->seed_pateke_behind_its_snapshot();

		$table = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=status_class=2' );
		VerbHarness::reset();
		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site --filter=status_class=2' );

		$this->assertSame( $built + 9, $table['as_of'], 'the table reads live' );
		$this->assertSame( $built + 9, $brief['as_of'], 'the brief is dated by the live read' );
		$this->assertSame( \array_column( $table['data'], 'count', 'page_hash' ), \array_column( $brief['pages'], 'count', 'page_hash' ) );
		$this->assertSame(
			[ self::hash_of( 'https://seed.example/hoiho-7720/' ), self::hash_of( 'https://seed.example/pateke-7720/?b=?' ), self::hash_of( 'https://seed.example/toroa-7720/' ) ],
			\array_column( $brief['pages'], 'busiest_hash' ),
			'the busiest shape as the rows hold it now'
		);
	}

	/**
	 * A stats read fails soft: a snapshot whose `urls` table will not read
	 * answers the brief live, though its `pages` listing reads.
	 */
	public function test_a_brief_whose_stored_shapes_will_not_read_answers_live(): void {
		$built = $this->seed_pateke_behind_its_snapshot();
		$file  = \dirname( $this->facts_file( 0 ) ) . '/' . Url_Snapshot::NAME . '.sqlite';
		$this->assertFileExists( $file );
		( new \PDO( 'sqlite:' . $file, null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] ) )->exec( 'ALTER TABLE urls RENAME TO urls_moved_7720' );

		$table = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		VerbHarness::reset();
		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site' );

		$this->assertSame( $built, $table['as_of'], 'the listing alone still reads the snapshot' );
		$this->assertIsArray( $brief, \is_string( $brief ) ? $brief : 'the brief answered no structure' );
		$this->assertSame( $built + 9, $brief['as_of'], 'the brief answered live' );
		$this->assertContains( self::hash_of( 'https://seed.example/pateke-7720/?b=?' ), \array_column( $brief['pages'], 'busiest_hash' ) );
		$this->assertNotEmpty( \array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'performance: the URL snapshot did not read' ) ) );
	}

	/**
	 * Two shapes of one page tied on count: the busiest is the one the
	 * sort's own direction ranks first, the higher hash, live and stored.
	 */
	public function test_an_overview_briefs_busiest_shape_breaks_a_tie_by_the_higher_hash(): void {
		$urls = [ 'https://seed.example/kotare-9051/?a=?', 'https://seed.example/kotare-9051/?b=?' ];
		$this->seed_requests( [ [ 'url' => $urls[0] ], [ 'url' => $urls[1] ] ] );
		$hashes = \array_map( self::hash_of( ... ), $urls );
		\rsort( $hashes );

		$live = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site' );
		Url_Snapshot::publish( self::tick(), 600, static fn (): array => [] );
		VerbHarness::reset();
		$stored = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site' );

		$this->assertSame( [ $hashes[0], $hashes[0] ], [ $live['pages'][0]['busiest_hash'], $stored['pages'][0]['busiest_hash'] ] );
	}

	public function test_ask_category_with_no_request_or_url_answers_from_the_scoped_leaderboard(): void {
		$this->seed_site_timing( [ 'server' => 'alpha.example', 'category' => 'wpdb', 'ms' => 4.0, 'calls' => 8, 'requests' => 4 ] );
		$this->seed_site_timing( [ 'server' => 'beta.example', 'category' => 'wpdb', 'ms' => 400.0, 'calls' => 80, 'requests' => 40 ] );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->seed_request( [ 'server' => 'alpha.example', 'profiled' => 1 ] );
		}

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'category:wpdb --server=alpha.example' );

		$this->assertEqualsWithDelta( 1.0, $result['avg_time_ms'], 1e-9 );
		$this->assertSame( 'recent window on alpha.example', $result['scope'] );
	}

	// -------------------------------------------------------------------------
	// `dump_url` and the URL briefs over the `flame-facts` rows.
	// -------------------------------------------------------------------------

	public function test_dump_url_lists_the_newest_requests_with_their_log_position(): void {
		$this->activate_shipped( 'performance', 2 );
		$url    = 'https://seed.example/takahe-5512/';
		$latest = self::latest();
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-takahe-old', 'bucket' => $latest - 4, 'finished_at' => ( $latest - 4 ) * 300 + 7.5, 'started_at' => ( $latest - 4 ) * 300 - 31, 'segment' => 3, 'offset' => 7104, 'length' => 911, 'ms' => 2412.5, 'peak_mb' => 31.25, 'status' => 201, 'method' => 'POST' ] );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-takahe-new', 'bucket' => $latest, 'finished_at' => $latest * 300 + 19.5, 'started_at' => $latest * 300 - 46, 'segment' => 5, 'offset' => 2218, 'length' => 640, 'ms' => null, 'error' => 'T', 'peak_mb' => 12.75, 'status' => 504 ], 1 );
		$this->seed_request( [ 'url' => 'https://seed.example/other-7/', 'rid' => 'rid-other-7' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) );

		$this->assertSame( [ 'stats', 'requests', 'requests_capped', 'aggregate_flame', 'flame_cut', 'aggregate_profiles', 'aggregate_owners' ], \array_keys( $reply ) );
		$this->assertSame(
			[
				[ 'rid' => 'rid-takahe-new', 'timestamp' => $latest * 300 - 46, 'duration_ms' => null, 'finished_at' => $latest * 300 + 19.5, 'status_code' => 504, 'peak_mb' => 12.75, 'method' => 'GET', 'error_status' => 'T' ],
				[ 'rid' => 'rid-takahe-old', 'timestamp' => ( $latest - 4 ) * 300 - 31, 'duration_ms' => 2412.5, 'finished_at' => ( $latest - 4 ) * 300 + 7.5, 'status_code' => 201, 'peak_mb' => 31.25, 'method' => 'POST', 'error_status' => '-' ],
			],
			$reply['requests']
		);
		$this->assertFalse( $reply['requests_capped'] );
	}

	public function test_a_list_of_exactly_five_hundred_is_not_capped(): void {
		$url = 'https://seed.example/kokako-500/';
		$this->seed_requests( \array_fill( 0, Performance_CI_Node::RECENT_REQUEST_LIMIT, [ 'url' => $url ] ) );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) );

		$this->assertCount( 500, $reply['requests'] );
		$this->assertFalse( $reply['requests_capped'], 'the list holds every request the stats count' );
	}

	public function test_a_filtered_list_is_capped_against_its_filtered_count(): void {
		$url = 'https://seed.example/kokako-filtered/';
		$this->seed_requests( \array_fill( 0, Performance_CI_Node::RECENT_REQUEST_LIMIT, [ 'url' => $url, 'status' => 503 ] ) );
		$this->seed_requests( \array_fill( 0, 40, [ 'url' => $url ] ) );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) . ' --filter=status_class=5' );

		$this->assertSame( Performance_CI_Node::RECENT_REQUEST_LIMIT, $reply['stats']['count'] );
		$this->assertFalse( $reply['requests_capped'], 'the list holds all 500 the filtered stats count' );
	}

	public function test_dump_url_caps_its_list_at_the_newest_five_hundred(): void {
		$url  = 'https://seed.example/kokako-71/';
		$rows = [];
		for ( $i = 0; $i <= Performance_CI_Node::RECENT_REQUEST_LIMIT; $i++ ) {
			$rows[] = [ 'url' => $url, 'rid' => \sprintf( 'rid-kokako-%04d', $i ), 'finished_at' => self::latest() * 300 + $i / 1000 ];
		}
		$this->seed_requests( $rows );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) );

		$this->assertCount( Performance_CI_Node::RECENT_REQUEST_LIMIT, $reply['requests'] );
		$this->assertSame( 'rid-kokako-0500', $reply['requests'][0]['rid'] );
		$this->assertSame( 'rid-kokako-0001', $reply['requests'][499]['rid'] );
		$this->assertTrue( $reply['requests_capped'] );
		$this->assertSame( 501, $reply['stats']['count'], 'the stats count every request the list leaves out' );
	}

	public function test_dump_url_filter_narrows_stats_and_list_and_rebuilds_no_flame(): void {
		$url    = 'https://seed.example/tieke-208/';
		$latest = self::latest();
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-tieke-503', 'status' => 503, 'ms' => 900.5, 'finished_at' => $latest * 300 + 2.5 ] );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-tieke-404', 'status' => 404, 'ms' => 30.5, 'finished_at' => $latest * 300 + 3.5 ] );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-tieke-ok', 'ms' => 40.5, 'finished_at' => $latest * 300 + 5.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) . ' --filter=status_class=5' );

		$this->assertSame( [ 'rid-tieke-503' ], \array_column( $reply['requests'], 'rid' ) );
		$this->assertSame( [ 1, 900.5 ], [ $reply['stats']['count'], $reply['stats']['avg_ms'] ], 'the stats are the filtered requests\'' );
		$this->assertFalse( $reply['requests_capped'] );
		$this->assertNull( $reply['aggregate_flame'], 'no flame is rebuilt from a filtered list' );
		$this->assertFalse( $reply['flame_cut'] );
	}

	/** A filter matching none of a URL's requests narrows it to nothing, never to not-found. */
	public function test_a_filter_matching_none_of_a_url_answers_zero_not_not_found(): void {
		$url = 'https://seed.example/hihi-4417/';
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-hihi-ok', 'status' => 200, 'ms' => 41.5 ] );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-hihi-moved', 'status' => 301, 'ms' => 12.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) . ' --filter=status_class=5' );
		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:' . self::hash_of( $url ) . ' --filter=status_class=5' );

		$this->assertIsArray( $reply, \is_string( $reply ) ? $reply : 'dump_url answered no structure' );
		$this->assertSame( [ $url, 0, 0 ], [ $reply['stats']['url'], $reply['stats']['count'], $reply['stats']['errors'] ] );
		$this->assertSame( [], $reply['requests'] );
		$this->assertIsArray( $brief, \is_string( $brief ) ? $brief : 'ask answered no brief' );
	}

	public function test_dump_url_bucket_selection_narrows_its_stats_and_list(): void {
		$url    = 'https://seed.example/kereru-64/';
		$latest = self::latest();
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-kereru-in', 'bucket' => $latest - 9, 'ms' => 70.5 ] );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-kereru-out', 'ms' => 7000.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) . ' --bucket=' . Bucket::key( $latest - 9 ) );

		$this->assertSame( [ 'rid-kereru-in' ], \array_column( $reply['requests'], 'rid' ) );
		$this->assertSame( [ 1, 70.5 ], [ $reply['stats']['count'], $reply['stats']['avg_ms'] ] );
		$this->assertEqualsWithDelta( 1 / 300, $reply['stats']['requests_per_second'], 1e-12 );
		$this->assertNull( $reply['aggregate_flame'], 'no flame is rebuilt from a selection' );
	}

	public function test_dump_url_answers_zero_for_a_named_url_with_no_request_in_its_selection(): void {
		$url    = 'https://seed.example/toutouwai-9/';
		$latest = self::latest();
		$this->seed_request( [ 'url' => $url, 'ms' => 33.5, 'peak_mb' => 9.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) . ' --bucket=' . Bucket::key( $latest - 20 ) );

		$this->assertSame(
			[ 'hash' => self::hash_of( $url ), 'url' => $url, 'count' => 0, 'avg_ms' => null, 'min_ms' => null, 'max_ms' => null, 'p50_ms' => null, 'p95_ms' => null, 'p99_ms' => null, 'avg_peak_mb' => null, 'max_peak_mb' => 0, 'last_updated' => 0, 'errors' => 0, 'error_rate' => null, 'requests_per_second' => 0 ],
			$reply['stats']
		);
		$this->assertSame( [], $reply['requests'] );
	}

	public function test_dump_url_profile_sums_this_urls_timings(): void {
		$url    = 'https://seed.example/pateke-31/';
		$latest = self::latest();
		$this->seed_timing( [ 'url' => $url, 'category' => 'sql', 'ms' => 90.5, 'calls' => 6, 'requests' => 2 ] );
		$this->seed_timing( [ 'url' => $url, 'category' => 'sql', 'entry' => 'SELECT pateke', 'ms' => 40.25, 'calls' => 2, 'requests' => 2 ] );
		$this->seed_timing( [ 'url' => $url, 'category' => 'init hook @10', 'listener' => 1, 'ms' => 12.5, 'calls' => 2, 'requests' => 2 ] );
		$this->seed_timing( [ 'url' => $url, 'category' => 'sql', 'bucket' => $latest - Bucket::window() - Bucket::RETAIN_SLACK, 'ms' => 5000.0, 'calls' => 50, 'requests' => 5 ] );
		$this->seed_timing( [ 'url' => 'https://seed.example/elsewhere-31/', 'category' => 'sql', 'ms' => 9000.0, 'calls' => 90, 'requests' => 9 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/elsewhere-31/', 'ms' => 7000.5, 'profiled' => 1 ] );
		$this->seed_request( [ 'url' => $url, 'ms' => 120.5, 'profiled' => 1 ] );
		$this->seed_request( [ 'url' => $url, 'ms' => 80.5, 'profiled' => 1 ] );
		$this->seed_request( [ 'url' => $url, 'ms' => 20.5 ] );

		$profiles = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) )['aggregate_profiles'];

		$this->assertSame( 2, $profiles['count'] );
		$this->assertSame( 45.25, $profiles['total_time'], 'the listener ran inside its hook' );
		$this->assertSame( 73.833333333333, \round( $profiles['avg_ms'], 12 ) );
		$this->assertSame( [ 'time' => 45.25, 'count' => 3, 'samples' => 2, 'entries' => [ 'SELECT pateke' => [ 20.125, 1, 2 ] ] ], $profiles['categories']['sql'] );
		$this->assertSame( 6.25, $profiles['categories']['init hook @10']['time'] );
	}

	/** Seeds three requests' owner time: kea 60 ms, core 30 ms, 9 ms unmeasured. */
	private function seed_owner_board( string $url ): void {
		$this->seed_owner_timing( [ 'url' => $url, 'owner' => 'core', 'ms' => 30.0, 'calls' => 9, 'requests' => 3 ] );
		$this->seed_owner_timing( [ 'url' => $url, 'owner' => 'kea-7746', 'ms' => 60.0, 'calls' => 4, 'requests' => 3 ] );
		$this->seed_owner_timing( [ 'url' => $url, 'owner' => '(unmeasured)', 'ms' => 9.0, 'calls' => 0, 'requests' => 3 ] );
		$this->seed_owner_site_timings(
			[
				[ 'owner' => 'core', 'ms' => 30.0, 'calls' => 9, 'requests' => 3 ],
				[ 'owner' => 'kea-7746', 'ms' => 60.0, 'calls' => 4, 'requests' => 3 ],
				[ 'owner' => '(unmeasured)', 'ms' => 9.0, 'calls' => 0, 'requests' => 3 ],
			]
		);
	}

	public function test_overview_answers_each_owners_time_per_request(): void {
		$url = 'https://seed.example/rimu-5/';
		$this->seed_owner_board( $url );
		$this->seed_owner_site_timing( [ 'owner' => 'kea-7746', 'worker' => 1, 'ms' => 7000.0, 'calls' => 70, 'requests' => 70 ] );
		$this->seed_owner_site_timing( [ 'owner' => 'kea-7746', 'bucket' => self::latest() - Bucket::window(), 'ms' => 5000.0, 'calls' => 50, 'requests' => 50 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertSame( [ 'as_of', 'totals', 'slots', 'servers', 'leaderboard', 'owners', 'available' ], \array_keys( $reply ) );
		$this->assertSame( [ 'kea-7746', 'core' ], \array_column( $reply['owners'], 'owner' ) );
		$this->assertEqualsWithDelta( [ 20.0, 10.0 ], \array_column( $reply['owners'], 'ms_per_request' ), 1e-9 );
		$this->assertEqualsWithDelta( [ 60 / 99, 30 / 99 ], \array_column( $reply['owners'], 'share' ), 1e-9 );
		$this->assertEqualsWithDelta( [ 4 / 3, 3.0 ], \array_column( $reply['owners'], 'calls_per_request' ), 1e-9 );
		$this->assertSame( [ 'owner', 'ms_per_request', 'share', 'calls_per_request' ], \array_keys( $reply['owners'][0] ) );
	}

	/** The `(unmeasured)` row is the denominator wherever it sorts, and is never listed. */
	public function test_overview_owners_find_unmeasured_past_a_full_page_of_owners(): void {
		$rows = [ [ 'owner' => '(unmeasured)', 'ms' => 0.25, 'calls' => 0, 'requests' => 5 ] ];
		for ( $i = 0; $i <= Stats_Query::MAX_LIMIT; $i++ ) {
			$rows[] = [ 'owner' => \sprintf( 'plugin-%04d', $i ), 'ms' => 50.5 + $i, 'calls' => 1, 'requests' => 1 ];
		}
		$this->seed_owner_site_timings( $rows );

		$owners = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['owners'];

		$this->assertCount( Stats_Query::MAX_LIMIT + 1, $owners );
		$this->assertEqualsWithDelta( ( 50.5 + Stats_Query::MAX_LIMIT ) / 5, $owners[0]['ms_per_request'], 1e-9 );
		$this->assertNotContains( '(unmeasured)', \array_column( $owners, 'owner' ) );
	}

	public function test_overview_owners_are_empty_over_no_measured_request(): void {
		$this->seed_owner_site_timing( [ 'owner' => 'kea-7746', 'ms' => 60.0, 'calls' => 4, 'requests' => 3 ] );

		$this->assertSame( [], VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['owners'] );
	}

	public function test_dump_url_answers_the_same_owners_for_its_url(): void {
		$url = 'https://seed.example/rimu-5/';
		$this->seed_owner_board( $url );
		$this->seed_owner_timing( [ 'url' => 'https://seed.example/elsewhere-5/', 'owner' => 'kea-7746', 'ms' => 9000.0, 'calls' => 90, 'requests' => 90 ] );
		$this->seed_request( [ 'url' => $url, 'ms' => 40.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) );

		$this->assertSame( [ 'stats', 'requests', 'requests_capped', 'aggregate_flame', 'flame_cut', 'aggregate_profiles', 'aggregate_owners' ], \array_keys( $reply ) );
		$this->assertSame( [ 'kea-7746', 'core' ], \array_column( $reply['aggregate_owners'], 'owner' ) );
		$this->assertEqualsWithDelta( [ 20.0, 10.0 ], \array_column( $reply['aggregate_owners'], 'ms_per_request' ), 1e-9 );
		$this->assertEqualsWithDelta( [ 60 / 99, 30 / 99 ], \array_column( $reply['aggregate_owners'], 'share' ), 1e-9 );
		$this->assertEqualsWithDelta( [ 4 / 3, 3.0 ], \array_column( $reply['aggregate_owners'], 'calls_per_request' ), 1e-9 );
	}

	/**
	 * A bucket selection narrows the profile and the owners as it narrows
	 * the stats and the list, so a window holding one request reads as it.
	 */
	public function test_dump_url_profile_and_owners_follow_a_bucket_selection(): void {
		$url    = 'https://seed.example/kokako-41/';
		$picked = self::latest() - 3;
		$this->seed_request( [ 'url' => $url, 'bucket' => $picked, 'ms' => 60.5, 'profiled' => 1 ] );
		$this->seed_request( [ 'url' => $url, 'ms' => 700.5, 'profiled' => 1 ] );
		$this->seed_timing( [ 'url' => $url, 'bucket' => $picked, 'category' => 'sql', 'ms' => 30.5, 'calls' => 3, 'requests' => 1 ] );
		$this->seed_timing( [ 'url' => $url, 'category' => 'sql', 'ms' => 900.0, 'calls' => 9, 'requests' => 1 ] );
		$this->seed_owner_timing( [ 'url' => $url, 'bucket' => $picked, 'owner' => 'kea-7746', 'ms' => 30.5, 'calls' => 3, 'requests' => 1 ] );
		$this->seed_owner_timing( [ 'url' => $url, 'bucket' => $picked, 'owner' => '(unmeasured)', 'ms' => 30.0, 'calls' => 0, 'requests' => 1 ] );
		$this->seed_owner_timing( [ 'url' => $url, 'owner' => 'kea-7746', 'ms' => 900.0, 'calls' => 9, 'requests' => 1 ] );
		$this->seed_owner_timing( [ 'url' => $url, 'owner' => '(unmeasured)', 'ms' => 0.0, 'calls' => 0, 'requests' => 1 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ self::hash_of( $url ), '--bucket=' . Bucket::key( $picked ) ] );

		$this->assertSame( 1, $reply['aggregate_profiles']['count'] );
		$this->assertSame( 30.5, $reply['aggregate_profiles']['categories']['sql']['time'] );
		$this->assertSame( [ 'kea-7746' ], \array_column( $reply['aggregate_owners'], 'owner' ) );
		$this->assertEqualsWithDelta( 30.5, $reply['aggregate_owners'][0]['ms_per_request'], 1e-9 );
	}

	public function test_dump_url_profile_is_null_over_no_profiled_request(): void {
		$url = 'https://seed.example/whio-12/';
		$this->seed_request( [ 'url' => $url, 'ms' => 50.5 ] );

		$this->assertNull( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) )['aggregate_profiles'] );
	}

	/**
	 * Two requests of one URL, each with its flame, the second partition's
	 * flame index holding another URL's beside them.
	 *
	 * @return string The URL's hash.
	 */
	private function seed_a_url_with_two_flames(): string {
		$url  = 'https://seed.example/kakariki-8841/';
		$hash = self::hash_of( $url );
		foreach ( [ [ 'rid-kakariki-a-8841', 40.5, 30.5 ], [ 'rid-kakariki-b-8841', 60.5, 10.5 ] ] as [ $rid, $ms, $span ] ) {
			$this->seed_request( [ 'url' => $url, 'rid' => $rid, 'ms' => $ms ] );
			$this->write_flame( [
				'rid'      => $rid,
				'url_hash' => $hash,
				'name'     => 'request',
				'value'    => $ms,
				'children' => [ [ 'name' => 'wp_loaded', 'value' => $span, 'children' => [] ] ],
			] );
		}
		$this->write_flame( [ 'rid' => 'rid-elsewhere-8841', 'url_hash' => 'f00f00f00f00', 'name' => 'request', 'value' => 9, 'children' => [] ] );
		return $hash;
	}

	public function test_dump_url_flame_is_rebuilt_from_flames(): void {
		$hash = $this->seed_a_url_with_two_flames();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );
		$flame = $reply['aggregate_flame'];

		$this->assertFalse( $reply['flame_cut'] );
		$this->assertSame( 'aggregate', $flame['name'] );
		$this->assertSame( 50.5, $flame['value'], 'per-request means: (40.5 + 60.5) / 2' );
		$this->assertSame( 'wp_loaded', $flame['children'][0]['name'] );
		$this->assertSame( 20.5, $flame['children'][0]['value'] );
	}

	/**
	 * One more flamed request than `FLAME_REQUEST_LIMIT` for one URL, each
	 * holding an `init` span but the oldest, which holds `oldest_only`.
	 *
	 * @return string The URL's hash.
	 */
	private function seed_one_past_the_flame_limit(): string {
		$url  = 'https://seed.example/hihi-7316/';
		$hash = self::hash_of( $url );
		$at   = (float) ( Bucket::id( (int) Core::$now ) * Bucket::SECONDS );
		$rows = [];
		for ( $i = 0; $i <= Performance_CI_Node::FLAME_REQUEST_LIMIT; $i++ ) {
			$rid    = "rid-hihi-{$i}";
			$rows[] = [ 'url' => $url, 'rid' => $rid, 'ms' => 50.5, 'finished_at' => $at + $i * 0.25 ];
			$this->write_flame( [
				'rid'      => $rid,
				'url_hash' => $hash,
				'name'     => 'request',
				'value'    => 50.5,
				'children' => 0 === $i
					? [ [ 'name' => 'oldest_only', 'value' => 40.5, 'children' => [] ] ]
					: [ [ 'name' => 'init', 'value' => 20.5, 'children' => [] ] ],
			] );
		}
		$this->seed_requests( $rows );
		return $hash;
	}

	/** The aggregate folds the newest `FLAME_REQUEST_LIMIT` listed requests: the oldest adds nothing. */
	public function test_dump_url_flame_folds_the_newest_hundred(): void {
		$hash = $this->seed_one_past_the_flame_limit();

		$flame = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash )['aggregate_flame'];

		$this->assertSame( Performance_CI_Node::FLAME_REQUEST_LIMIT, $flame['count'] );
		$this->assertSame( [ 'init' ], \array_column( $flame['children'], 'name' ) );
	}

	/** An Ask span under a URL reads the same newest `FLAME_REQUEST_LIMIT`. */
	public function test_ask_span_under_a_url_folds_the_newest_hundred(): void {
		$hash = $this->seed_one_past_the_flame_limit();
		$ask  = static fn ( string $span ) => VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ "span:{$span}", "url:{$hash}" ] );

		$this->assertStringContainsString( 'over ' . Performance_CI_Node::FLAME_REQUEST_LIMIT . ' requests', $ask( 'init' )['scope'] );
		$this->assertStringContainsString( "no span 'oldest_only' in this URL's aggregate", $ask( 'oldest_only' ) );
	}

	/**
	 * An untimed request counts toward the means but adds neither its
	 * duration nor its tree: (120.5 + 80.5) / 3, and two 30.5 spans over 3.
	 */
	public function test_dump_url_flame_counts_an_untimed_request_but_not_its_tree(): void {
		$url  = 'https://seed.example/toutouwai-5512/';
		$hash = self::hash_of( $url );
		foreach ( [ [ 'rid-toutouwai-a', 120.5 ], [ 'rid-toutouwai-b', null ], [ 'rid-toutouwai-c', 80.5 ] ] as [ $rid, $ms ] ) {
			$this->seed_request( [ 'url' => $url, 'rid' => $rid, 'ms' => $ms ] + ( null === $ms ? [ 'error' => 'T' ] : [] ) );
			$this->write_flame( [
				'rid'      => $rid,
				'url_hash' => $hash,
				'name'     => 'request',
				'value'    => $ms ?? 9000.5,
				'children' => [ [ 'name' => 'init', 'value' => 30.5, 'children' => [] ] ],
			] );
		}

		$flame = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash )['aggregate_flame'];

		$this->assertEqualsWithDelta( 67.0, $flame['value'], 1e-9 );
		$this->assertEqualsWithDelta( 61.0 / 3, $flame['children'][0]['value'], 1e-9 );
	}

	/**
	 * A URL's flame displays at most 1,730 nodes, its root included, its
	 * lightest leaves dropped first: of 2,000 children, the 1,729 heaviest.
	 */
	public function test_dump_url_flame_is_pruned_to_its_node_cap(): void {
		$url      = 'https://seed.example/kokako-2000/';
		$hash     = self::hash_of( $url );
		$children = [];
		for ( $i = 1; $i <= 2000; $i++ ) {
			$children[] = [ 'name' => "kokako {$i}", 'value' => 0.5 * $i, 'children' => [] ];
		}
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-kokako-2000', 'ms' => 4410.5 ] );
		$this->write_flame( [ 'rid' => 'rid-kokako-2000', 'url_hash' => $hash, 'name' => 'request', 'value' => 4410.5, 'children' => $children ] );

		$flame = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash )['aggregate_flame'];

		$this->assertCount( 1729, $flame['children'] );
		$this->assertSame( [ 'kokako 272', 'kokako 2000' ], [ $flame['children'][0]['name'], \end( $flame['children'] )['name'] ] );
	}

	/**
	 * Each partition's walk ends once it holds its listed requests' flames,
	 * and a flame of the URL whose request is not listed, or a line that only
	 * carries the URL's column, folds nothing.
	 */
	public function test_a_flame_rebuild_folds_only_its_listed_requests_flames(): void {
		$this->activate_shipped( 'performance', 2 );
		$url  = 'https://seed.example/tauhou-two-77/';
		$hash = self::hash_of( $url );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-tauhou-p0', 'ms' => 30.5 ] );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-tauhou-p1', 'ms' => 50.5 ], 1 );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-tauhou-old', 'ms' => 9000.5, 'bucket' => self::latest() - 400 ] );
		foreach ( [ [ 'rid-tauhou-p0', 30.5, 0 ], [ 'rid-tauhou-old', 9000.5, 0 ], [ 'rid-tauhou-p1', 50.5, 1 ] ] as [ $rid, $ms, $partition ] ) {
			$this->write_flame( [ 'rid' => $rid, 'url_hash' => $hash, 'name' => 'request', 'value' => $ms, 'children' => [] ], $partition );
		}
		\file_put_contents( $this->tmp . '/logs/flames.p1/0.idx', \str_pad( 'rid-imposter-9931', 32 ) . $hash . "\n", FILE_APPEND | LOCK_EX );
		self::appended_at_the_tick( $this->tmp . '/logs/flames.p1/0.idx' );

		$flame = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash )['aggregate_flame'];

		$this->assertSame( 40.5, $flame['value'], 'the two listed requests, not the one aged out of the list' );
	}

	public function test_dump_url_flame_of_a_url_with_no_flame_is_the_empty_tree(): void {
		$url = 'https://seed.example/hoiho-77/';
		$this->seed_request( [ 'url' => $url ] );

		$this->assertSame(
			[ 'name' => 'aggregate', 'value' => 0, 'children' => [] ],
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) )['aggregate_flame']
		);
	}

	/** A rebuild the clock cuts short answers no flame, never a partial one. */
	public function test_a_flame_rebuild_the_clock_cuts_short_answers_no_flame(): void {
		$hash = $this->seed_a_url_with_two_flames();
		$this->pad_flame_index();
		$seconds     = 0.0;
		Core::$clock = static function () use ( &$seconds ): float {
			return $seconds += 1.0;
		};

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertNull( $reply['aggregate_flame'] );
		$this->assertTrue( $reply['flame_cut'] );
	}

	/** A flame segment closed before the oldest listed request finished is never read. */
	public function test_a_flame_rebuild_ends_at_a_segment_closed_before_its_requests_finished(): void {
		$url = 'https://seed.example/pukeko-closed-6623/';
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-pukeko-6623', 'ms' => 21.5 ] );
		$this->write_flame( [ 'rid' => 'rid-pukeko-other-6623', 'url_hash' => 'f00f00f00f00', 'name' => 'request', 'value' => 1, 'children' => [] ] );
		$this->pad_flame_index();
		\touch( $this->tmp . '/logs/flames.p0/0.idx', (int) Core::$now - 3 * Bucket::HOUR_SECONDS );
		$seconds     = 0.0;
		Core::$clock = static function () use ( &$seconds ): float {
			return $seconds += 1.0;
		};

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) );

		$this->assertSame( [ 'name' => 'aggregate', 'value' => 0, 'children' => [] ], $reply['aggregate_flame'] );
		$this->assertFalse( $reply['flame_cut'] );
	}

	/**
	 * A listed rid whose flame was never stored makes the lookup read its
	 * partition's whole index; the budget bounds that walk on every poll.
	 */
	public function test_a_listed_rid_with_no_stored_flame_spends_the_budget_and_no_more(): void {
		$url  = 'https://seed.example/hihi-missing-5520/';
		$hash = self::hash_of( $url );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-hihi-unflamed-5520', 'ms' => 31.5 ] );
		for ( $i = 0; $i < ( Performance_CI_Node::MAX_SCAN_S + 3 ) * self::clock_stride(); $i++ ) {
			$this->write_flame( [ 'rid' => \sprintf( 'rid-other-%05d', $i ), 'url_hash' => 'f00f00f00f00', 'name' => 'request', 'value' => 1, 'children' => [] ] );
		}
		$reads       = 0;
		Core::$clock = static function () use ( &$reads ): float {
			return (float) ++$reads;
		};

		$this->assertNull( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash )['aggregate_flame'] );
		$this->assertLessThan( Performance_CI_Node::MAX_SCAN_S + 20, $reads, 'the clock is read once a stride, so the walk stopped near the budget' );
	}

	/** A span under a URL whose rebuild ran out of time says so. */
	public function test_a_span_under_a_url_whose_rebuild_ran_out_of_time_says_so(): void {
		$hash = $this->seed_a_url_with_two_flames();
		$this->pad_flame_index();
		$seconds     = 0.0;
		Core::$clock = static function () use ( &$seconds ): float {
			return $seconds += 1.0;
		};

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', "url:{$hash}" ] );

		$this->assertSame( "no aggregate for URL {$hash}: its flame rebuild ran out of time", \trim( Core::as_string( $reply ) ) );
	}

	public function test_a_worker_only_url_answers_its_detail_and_is_absent_from_site_totals(): void {
		$worker = 'https://seed.example/wp-cron.php?kahu-sync';
		$latest = self::latest();
		$this->seed_request( [ 'url' => $worker, 'worker' => 1, 'ms' => 3000.5, 'peak_mb' => 60.5, 'finished_at' => $latest * 300 + 8.5 ] );
		$this->seed_request( [ 'url' => $worker, 'worker' => 1, 'ms' => 1500.5, 'peak_mb' => 40.5, 'error' => 'F', 'finished_at' => $latest * 300 + 6.5 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/reader-5/', 'ms' => 12.5 ] );

		$detail = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $worker ) );
		VerbHarness::reset();
		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );
		VerbHarness::reset();
		$urls = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame(
			[
				'hash'                => self::hash_of( $worker ),
				'url'                 => $worker,
				'count'               => 2,
				'avg_ms'              => 2250.5,
				'min_ms'              => 1500.5,
				'max_ms'              => 3000.5,
				'p50_ms'              => 1500.5,
				'p95_ms'              => 3000.5,
				'p99_ms'              => 3000.5,
				'avg_peak_mb'         => 50.5,
				'max_peak_mb'         => 60.5,
				'last_updated'        => $latest * 300 + 8.5,
				'errors'              => 1,
				'error_rate'          => 0.5,
				'requests_per_second' => 2 / ( ( Bucket::window() - 1 ) * Bucket::SECONDS + self::tick() % Bucket::SECONDS ),
			],
			$detail['stats']
		);
		$this->assertCount( 2, $detail['requests'] );
		$this->assertSame( [ 1, 1 ], [ $overview['totals']['urls'], $overview['totals']['requests'] ] );
		$this->assertSame( [ 'seed.example/reader-5/' ], \array_column( $urls['data'], 'page' ) );
	}

	public function test_dump_url_scopes_its_stats_and_list_to_the_server(): void {
		$this->seed_request( [ 'server' => 'alpha.example', 'url' => 'https://alpha.example/kea-2/', 'rid' => 'rid-alpha-kea', 'ms' => 130.5 ] );
		$this->seed_request( [ 'server' => 'beta.example', 'url' => 'https://alpha.example/kea-2/', 'rid' => 'rid-beta-kea', 'ms' => 9000.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( 'https://alpha.example/kea-2/' ) . ' --server=alpha.example' );

		$this->assertSame( [ 1, 130.5 ], [ $reply['stats']['count'], $reply['stats']['avg_ms'] ] );
		$this->assertSame( [ 'rid-alpha-kea' ], \array_column( $reply['requests'], 'rid' ) );
	}

	public function test_dump_url_of_a_hash_no_row_names_is_not_found(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/known-1/' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'deadbeefcafe' );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'URL not found: deadbeefcafe', $reply );
	}

	public function test_dump_url_declares_its_hash_server_filter_and_bucket(): void {
		$args = self::args_by_name( 'dump_url' );

		$this->assertSame( [ 'hash', 'server', 'filter', 'bucket' ], \array_keys( $args ) );
		$this->assertTrue( $args['hash']['required'] );
		$this->assertSame( self::args_by_name( 'query' )['filter'], $args['filter'], 'declared as query declares it' );
		$this->assertSame( [ 'string', 'string' ], [ $args['server']['type'], $args['bucket']['type'] ] );
	}

	public function test_ask_url_brief_reads_the_facts(): void {
		$url = 'https://seed.example/kotuku-410/';
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-kotuku-a', 'ms' => 300.5 ] );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-kotuku-b', 'ms' => 2900.5, 'status' => 500, 'peak_mb' => 77.5, 'error' => 'F' ] );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-kotuku-c', 'ms' => 1400.5 ] );

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:' . self::hash_of( $url ) . ' --server=seed.example' );

		$this->assertSame( $url, $brief['url'] );
		$this->assertSame( [ 'count' => 3, 'errors' => 1, 'avg_ms' => 1533.8333333333333, 'max_ms' => 2900.5, 'max_peak_mb' => 77.5 ], $brief['stats'] );
		$this->assertSame( [ 'rid-kotuku-b', 'rid-kotuku-c', 'rid-kotuku-a' ], \array_column( $brief['worst_requests'], 'rid' ) );
		$this->assertFalse( $brief['requests_capped'] );
		$this->assertArrayNotHasKey( 'scan_stopped_early', $brief );
		$this->assertArrayNotHasKey( 'requests_window_start', $brief );
		$this->assertSame( [ 'hash' => self::hash_of( $url ), 'server' => 'seed.example' ], $brief['fetch'][0]['arguments'] );
	}

	public function test_ask_overview_and_url_briefs_carry_the_filter(): void {
		$url = 'https://seed.example/korimako-5/';
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-korimako-503', 'status' => 503, 'ms' => 610.5, 'peak_mb' => 44.5 ] );
		$this->seed_request( [ 'url' => $url, 'rid' => 'rid-korimako-ok', 'ms' => 20.5, 'peak_mb' => 88.5 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/hihi-ok/', 'ms' => 25.5 ] );

		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site --filter=status_class=5' );
		VerbHarness::reset();
		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:' . self::hash_of( $url ) . ' --filter=status_class=5' );

		$this->assertSame( [ 'status_class=5' ], $overview['filters']['filter'] );
		$this->assertSame( [ 1, 1 ], [ $overview['stats']['urls'], $overview['stats']['requests'] ] );
		$this->assertSame( [ 'status_class=5' ], $overview['fetch'][0]['arguments']['filter'] );
		$this->assertSame( [ 'status_class=5' ], $brief['filter'] );
		$this->assertSame( [ 'count' => 1, 'errors' => 0, 'avg_ms' => 610.5, 'max_ms' => 610.5, 'max_peak_mb' => 44.5 ], $brief['stats'] );
		$this->assertSame( [ 'rid-korimako-503' ], \array_column( $brief['worst_requests'], 'rid' ) );
		$this->assertSame( [ 'hash' => self::hash_of( $url ), 'filter' => [ 'status_class=5' ] ], $brief['fetch'][0]['arguments'] );
	}

	public function test_a_url_brief_carries_its_selections_spelling(): void {
		$url    = 'https://seed.example/miromiro-2/';
		$latest = self::latest();
		$this->seed_request( [ 'url' => $url, 'bucket' => $latest - 2, 'ms' => 44.5 ] );
		$this->seed_request( [ 'url' => $url, 'ms' => 4400.5 ] );

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:' . self::hash_of( $url ) . ' --bucket=' . Bucket::key( $latest - 1 ) . ',' . Bucket::key( $latest - 2 ) );

		$this->assertSame( Bucket::key( $latest - 2 ) . '..' . Bucket::key( $latest ), $brief['bucket'] );
		$this->assertSame( [ 1, 44.5 ], [ $brief['stats']['count'], $brief['stats']['avg_ms'] ] );
	}

	public function test_ask_category_under_a_url_reads_timings(): void {
		$url = 'https://seed.example/tauhou-904/';
		$this->seed_timing( [ 'url' => $url, 'category' => 'render', 'ms' => 301.5, 'calls' => 10, 'requests' => 5 ] );
		$this->seed_timing( [ 'url' => $url, 'category' => 'sql', 'ms' => 100.5, 'calls' => 35, 'requests' => 5 ] );
		$this->seed_site_timing( [ 'category' => 'render', 'ms' => 9000.0, 'calls' => 90, 'requests' => 9 ] );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed_request( [ 'url' => $url, 'profiled' => 1 ] );
		}

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:render', 'url:' . self::hash_of( $url ) ] );

		$this->assertSame( 'mean per request over 5 requests, every server', $brief['scope'] );
		$this->assertSame( $url, $brief['url'] );
		$this->assertSame( 60.3, $brief['avg_time_ms'] );
		$this->assertSame( 2, $brief['avg_count'] );
		$this->assertEqualsWithDelta( 0.75, $brief['share'], 1e-12 );
	}

	public function test_ask_category_under_a_url_with_no_profile_answers_from_the_leaderboard(): void {
		$url = 'https://seed.example/pukeko-6/';
		$this->seed_request( [ 'url' => $url, 'ms' => 20.5 ] );
		$this->seed_site_timing( [ 'category' => 'wpdb', 'ms' => 40.5, 'calls' => 8, 'requests' => 4 ] );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->seed_request( [ 'profiled' => 1 ] );
		}

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:wpdb', 'url:' . self::hash_of( $url ) ] );

		$this->assertSame( 'recent window', $brief['scope'] );
		$this->assertSame( 10.125, $brief['avg_time_ms'] );
	}

	public function test_ask_span_under_a_url_reads_its_rebuilt_flame(): void {
		$hash = $this->seed_a_url_with_two_flames();

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', "url:{$hash}" ] );

		$this->assertSame( 'span', $brief['subject'] );
		$this->assertSame( 20.5, $brief['ms'] );
		$this->assertSame( 'mean per request over 2 requests, every server', $brief['scope'] );
		$this->assertSame( 'https://seed.example/kakariki-8841/', $brief['url'] );
	}

	public function test_a_span_absent_from_a_urls_rebuilt_flame_says_so(): void {
		$hash = $this->seed_a_url_with_two_flames();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:shutdown', "url:{$hash}" ] );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( "no span 'shutdown' in this URL's aggregate", $reply );
	}

	public function test_a_span_under_an_unknown_url_is_not_found(): void {
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', 'url:cafebabe1357' ] );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'URL not found: cafebabe1357', $reply );
	}

	/** A stats file that will not open answers the no-stats shape (decision 3). */
	public function test_dump_url_over_stats_that_will_not_open_answers_no_stats(): void {
		$url = 'https://seed.example/kawau-41/';
		$this->seed_request( [ 'url' => $url, 'ms' => 88.5 ] );
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Event_Logger_Nodes\Config::reset();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', self::hash_of( $url ) );

		$this->assertSame(
			[
				'stats'              => [ 'hash' => self::hash_of( $url ), 'url' => '', 'count' => 0, 'avg_ms' => null, 'min_ms' => null, 'max_ms' => null, 'p50_ms' => null, 'p95_ms' => null, 'p99_ms' => null, 'avg_peak_mb' => null, 'max_peak_mb' => 0, 'last_updated' => 0, 'errors' => 0, 'error_rate' => null, 'requests_per_second' => 0 ],
				'requests'           => [],
				'requests_capped'    => false,
				'aggregate_flame'    => null,
				'flame_cut'          => false,
				'aggregate_profiles' => null,
				'aggregate_owners'   => [],
			],
			$reply
		);
	}

	public function test_a_url_brief_over_stats_that_will_not_open_briefs_no_stats(): void {
		$url = 'https://seed.example/kawau-42/';
		$this->seed_request( [ 'url' => $url, 'ms' => 88.5 ] );
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Event_Logger_Nodes\Config::reset();

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:' . self::hash_of( $url ) );

		$this->assertIsArray( $brief, \is_string( $brief ) ? $brief : '' );
		$this->assertSame( [ 'count' => 0, 'errors' => 0, 'avg_ms' => null, 'max_ms' => null, 'max_peak_mb' => 0 ], $brief['stats'] );
		$this->assertSame( [], $brief['worst_requests'] );
		$this->assertFalse( $brief['requests_capped'] );
	}

	public function test_a_span_under_a_url_reads_the_servers_flame_it_is_asked_for(): void {
		$url  = 'https://seed.example/kahu-span-3/';
		$hash = self::hash_of( $url );
		foreach ( [ [ 'rid-kahu-alpha', 'alpha.example', 40.5, 30.5 ], [ 'rid-kahu-beta', 'beta.example', 900.5, 800.5 ] ] as [ $rid, $server, $ms, $span ] ) {
			$this->seed_request( [ 'url' => $url, 'server' => $server, 'rid' => $rid, 'ms' => $ms ] );
			$this->write_flame( [ 'rid' => $rid, 'url_hash' => $hash, 'name' => 'request', 'value' => $ms, 'children' => [ [ 'name' => 'wp_loaded', 'value' => $span, 'children' => [] ] ] ] );
		}

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', "url:{$hash}", '--server=alpha.example' ] );

		$this->assertSame( 30.5, $brief['ms'] );
		$this->assertSame( 'mean per request over 1 requests on alpha.example', $brief['scope'] );
	}

	public function test_a_category_under_a_url_reads_the_servers_profile_it_is_asked_for(): void {
		$url = 'https://seed.example/kahu-cat-4/';
		$this->seed_timing( [ 'url' => $url, 'server' => 'alpha.example', 'category' => 'render', 'ms' => 61.5, 'calls' => 3, 'requests' => 3 ] );
		$this->seed_timing( [ 'url' => $url, 'server' => 'beta.example', 'category' => 'render', 'ms' => 9000.5, 'calls' => 90, 'requests' => 9 ] );
		$this->seed_requests( \array_fill( 0, 3, [ 'url' => $url, 'server' => 'alpha.example', 'profiled' => 1 ] ) );
		$this->seed_requests( \array_fill( 0, 9, [ 'url' => $url, 'server' => 'beta.example', 'profiled' => 1 ] ) );

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:render', 'url:' . self::hash_of( $url ), '--server=alpha.example' ] );

		$this->assertSame( 20.5, $brief['avg_time_ms'] );
		$this->assertSame( 'mean per request over 3 requests on alpha.example', $brief['scope'] );
	}

	public function test_a_span_under_a_url_over_stats_that_will_not_open_says_so(): void {
		$url = 'https://seed.example/kawau-43/';
		$this->seed_request( [ 'url' => $url ] );
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Event_Logger_Nodes\Config::reset();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', 'url:' . self::hash_of( $url ) ] );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'no aggregate for URL ' . self::hash_of( $url ) . ': the stats rows did not open', $reply );
	}

	public function test_a_category_under_a_url_over_stats_that_will_not_open_says_so(): void {
		$url = 'https://seed.example/kawau-44/';
		$this->seed_request( [ 'url' => $url, 'profiled' => 1 ] );
		$this->seed_timing( [ 'url' => $url, 'category' => 'render', 'ms' => 31.5 ] );
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Event_Logger_Nodes\Config::reset();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:render', 'url:' . self::hash_of( $url ) ] );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'no aggregate for URL ' . self::hash_of( $url ) . ': the stats rows did not open', $reply );
	}
}
