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
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Settings_Event_Writer;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Bootstrap;
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
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => 86400 ] );
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = true;
		// Reset the hook-categorizer static caches and the WP hook globals
		// so each hooks_* verb test sees a clean room. Mirrors the legacy
		// PerfHooksControllerTest / PerfHooksAvailableControllerTest setUp.
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
	 * The overview names the 288 slots it drew, so the dashboard's axis is
	 * the reply's and never the browser's clock.
	 */
	public function test_the_overview_names_its_288_slots(): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'min_lifetime' => 21600 ] );
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$slots = $reply['slots'] ?? [];
		$this->assertCount( 288, $slots, 'the chart draws 24 hours whatever the retention window' );
		$this->assertSame( [ '2026-09-29-14-35', '2026-09-29-14-30', '2026-09-28-14-40' ], [ $slots[0], $slots[1], $slots[287] ] );
		$this->assertArrayNotHasKey( 'plan', $reply );
	}

	public function test_overview_verb_rejects_unauthorized(): void {
		// Legacy controller gates every verb via read_permissions_check ==
		// manage_options. Performance_CI matches that.
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
		// Legacy controller default — `limit=50` from sanitize_callback default.
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
		$this->assertTrue( $record['flame_cut'], 'a cut lookup is no missing flame' );
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
		// whose scope is not stated is a number that will be read as the site's.
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
		// Legacy `get_url_detail` returns invalid_hash 400 when hash regex fails.
		// We surface that as a verb error string (interpreter errors are string-encoded).
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
		// Hash matches the regex but doesn't exist in the URL index — legacy
		// surfaces a 404 with "URL not found".
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
		$this->assertSame( 'https://kea.test/calendar/today', $summary['url'] );
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
	 * A request is named by its rid alone. The old `request:<rid>:<partition>`
	 * is refused, as the target and as a span's context, never half-read.
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

	/** A row whose place holds no record is no request either. */
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
		// The descriptions used to be a hand-written map in HookSelectorModal.js
		// covering 24 of the 63 categories this config declares — and users can
		// add more. They travel with the taxonomy that owns them now.
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

	public function test_list_hooks_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'list_hooks' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
	}

	// -------------------------------------------------------------------------
	// set verb (normalized positional receiver) — nine-option whitelist +
	// array/int/float/bool type-coerced sanitization. Positional
	// `set <option> <value>`.
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
		// the keys — the old csv-split flattened an assoc map to a list of "1"s.
		$decode = new \ReflectionMethod( Performance_CI_Node::class, 'decode_array_value' );
		$sanitize = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_value' );

		$decoded = $decode->invoke( null, (string) \json_encode( [ 'advancedemail' => true, 'amazons3' => true ] ) );

		$this->assertSame(
			[ 'advancedemail' => true, 'amazons3' => true ],
			$sanitize->invoke( null, $decoded, 'array' )
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
		// whenever hydrate_array() could not inline it. Dropping the null left a
		// map Rule::from_array now refuses outright, so a normal settings push
		// could only ever fail: the spoke kept its last-good ruleset and the hub
		// never converged. Null is inert; it must survive the sanitizer.
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

	public function test_set_verb_coerces_bool_option(): void {
		$interpreter = new Performance_CI_Node();
		VerbHarness::fire(
			$interpreter,
			'performance',
			'set',
			'newspack_event_logger_nodes_log_memory 1'
		);

		$this->assertTrue( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
	}

	/**
	 * A hub pushes a false bool option as a blank token, which is what
	 * `Core::as_string( false )` gives, so `set` takes its value optional and a
	 * blank turns the spoke's copy off.
	 */
	public function test_set_verb_applies_the_blank_a_hub_pushes_for_false(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_flush_every_line'] = true;

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'set',
			[ 'newspack_event_logger_nodes_flush_every_line', '' ]
		);

		$this->assertSame( [ 'option' => 'newspack_event_logger_nodes_flush_every_line', 'updated' => true ], $result );
		$this->assertFalse( $GLOBALS['_wp_options']['newspack_event_logger_nodes_flush_every_line'] );
	}

	/** A bool option reads its value as a bool word, so `false` turns it off. */
	public function test_set_verb_turns_a_bool_option_off_on_false(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] = true;

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', [ 'newspack_event_logger_nodes_log_memory', 'false' ] );

		$this->assertSame( [ 'option' => 'newspack_event_logger_nodes_log_memory', 'updated' => true ], $result );
		$this->assertFalse( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
	}

	/** A word no bool reads as is refused, never read as on. */
	public function test_set_verb_refuses_a_bool_option_value_outside_the_bool_words(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] = false;

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', [ 'newspack_event_logger_nodes_log_memory', 'kea-4471' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid value for option', $result );
		$this->assertFalse( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
	}

	/** A blank is a value; no value at all names nothing to write. */
	public function test_set_verb_refuses_an_absent_value(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] = true;

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', [ 'newspack_event_logger_nodes_log_memory' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'value required', $result );
		$this->assertTrue( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
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
	 * A bool option comes back from the options table as `'1'` while the hub
	 * pushes the word `1`, which coerces to `true`: the same value in another
	 * PHP type must write nothing, record no settings event and reset nothing.
	 */
	public function test_set_verb_pushing_the_stored_true_in_its_wire_form_writes_nothing(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] = '1';
		$seen = $this->watch_settings_writes();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', [ 'newspack_event_logger_nodes_log_memory', '1' ] );

		$this->assertSame( [ 'option' => 'newspack_event_logger_nodes_log_memory', 'updated' => false ], $result );
		$this->assertSame( '1', $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'], 'the stored row is untouched' );
		$this->assertCount( 0, $seen['events'], 'no settings event' );
		$this->assertCount( 0, $seen['resets'], 'no config reset' );
	}

	/** The options table hands a stored false back as `''`, which a hub's blank equals. */
	public function test_set_verb_pushing_the_stored_false_in_its_wire_form_writes_nothing(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_flush_every_line'] = '';
		$seen = $this->watch_settings_writes();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', [ 'newspack_event_logger_nodes_flush_every_line', '' ] );

		$this->assertSame( [ 'option' => 'newspack_event_logger_nodes_flush_every_line', 'updated' => false ], $result );
		$this->assertSame( '', $GLOBALS['_wp_options']['newspack_event_logger_nodes_flush_every_line'] );
		$this->assertCount( 0, $seen['events'] );
	}

	/** The counterpart guard: a value that moved still writes and records its event. */
	public function test_set_verb_pushing_a_changed_bool_still_writes_and_records_it(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] = '1';
		$seen = $this->watch_settings_writes();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', [ 'newspack_event_logger_nodes_log_memory', '' ] );

		$this->assertSame( [ 'option' => 'newspack_event_logger_nodes_log_memory', 'updated' => true ], $result );
		$this->assertFalse( $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] );
		$this->assertCount( 1, $seen['events'] );
	}

	/**
	 * The settings-sync loop's shape: a hub re-pushes the option on every
	 * event it reads, so two identical pushes in a row must both be no-ops.
	 */
	public function test_set_verb_two_identical_bool_pushes_in_a_row_write_nothing(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] = '1';
		$seen = $this->watch_settings_writes();

		$first  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', [ 'newspack_event_logger_nodes_log_memory', '1' ] );
		$second = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'set', [ 'newspack_event_logger_nodes_log_memory', '1' ] );

		$this->assertFalse( $first['updated'] ?? null );
		$this->assertFalse( $second['updated'] ?? null );
		$this->assertCount( 0, $seen['events'], 'neither push feeds the sync another event' );
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
		// directly — the ruleset option itself now re-tiers on set().
		$sanitize = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_value' );

		$this->assertSame(
			[ 'group' => [ 'inner' => 'val' ] ],
			$sanitize->invoke( null, [ 'group' => [ 'inner' => 'val' ] ], 'array' )
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

	public function test_sanitize_settings_value_rejects_non_array_and_unknown_type(): void {
		// Two reject paths unreachable through `set` (which always hands an array
		// for array-typed options and only ever a whitelisted type): a non-array
		// value for the array branch, and an unrecognized type.
		$ref = new \ReflectionMethod( Performance_CI_Node::class, 'sanitize_settings_value' );

		$this->assertNull( $ref->invoke( null, 'not-an-array', 'array' ) );
		$this->assertNull( $ref->invoke( null, 'whatever', 'nonexistent-type' ) );
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

	/** Each verb-first name replaces its noun-first one; the old name is refused, not aliased. */
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
			[ 'sort', 'order', 'limit', 'offset', 'search', 'server', 'filter', 'include_workers', 'bucket' ],
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
	public function test_query_description_names_available(): void {
		$this->assertStringContainsString( '`available`, false when the stats file will not open', self::verbs_by_name()['query']['description'] );
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

		$rows = Stats_Query::run( [ 'source' => 'requests', 'group' => [ 'bucket' ], 'filter' => [], 'measure' => [ 'count' ], 'buckets' => Bucket::last( 288, self::tick() ) ] )['rows'];

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
	// one slot of the 288 the charts draw.
	// -------------------------------------------------------------------------

	/** The bucket the tests narrow to: slot 7 of its hour. */
	private const BUCKET = '2026-10-04-13-35';

	/**
	 * Pin the reply at 2026-10-05 09:12 UTC, so BUCKET is about 20 hours
	 * back, inside the 288 buckets a chart draws.
	 */
	private function at_the_bucket_clock(): void {
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1 ] );
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 9, 12, 0, 10, 5, 2026 );
	}

	/** The oldest slot a chart draws is a bucket the table can narrow to. */
	public function test_the_oldest_charted_slot_is_a_bucket(): void {
		$this->at_the_bucket_clock();

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--bucket=2026-10-04-09-15' );

		$this->assertIsArray( $page );
		$this->assertSame( '2026-10-04-09-15', $page['filters']['bucket'] );
	}

	/**
	 * Every verb that reads a selection refuses a spelling naming a key
	 * that is no five-minute key or one in the future, a run ending before
	 * it starts, and more than 288 buckets, each saying which key or naming
	 * the limit.
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
				'reversed run'     => [ '2026-10-04-13-40..2026-10-04-13-35', 'bucket run 2026-10-04-13-40..2026-10-04-13-35 ends before it starts' ],
				'289 buckets'      => [ '2026-10-04-09-00..2026-10-05-09-00', 'bucket selection holds more than 288 buckets' ],
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
		return Log_Manager::url_hash( $url );
	}

	/** A `urls` reply's rows keyed by URL. */
	private static function rows_by_url( array $reply ): array {
		return \array_column( $reply['data'], null, 'url' );
	}

	public function test_overview_totals_count_every_non_worker_request(): void {
		$latest = self::latest();
		$this->seed_request( [ 'url' => 'https://seed.example/kea-14/', 'ms' => 120.5, 'peak_mb' => 30.25 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/kea-14/', 'ms' => 80.0, 'peak_mb' => 20.25, 'bucket' => $latest - 287 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/weka-3/', 'ms' => null, 'error' => 'T', 'peak_mb' => 10.25 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/cron-9/', 'ms' => 5000.0, 'peak_mb' => 99.0, 'worker' => 1 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/aged-2/', 'ms' => 7.0, 'bucket' => $latest - 288 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertSame(
			[ 'requests' => 3, 'timed' => 2, 'avg_ms' => 100.25, 'avg_peak_mb' => 20.25, 'errors' => 1, 'urls' => 2 ],
			$reply['totals']
		);
		$this->assertCount( 288, $reply['slots'] );
		$this->assertSame( Bucket::key( $latest ), $reply['slots'][0], 'newest first' );
		$this->assertSame( Bucket::key( $latest - 287 ), $reply['slots'][287] );
		$this->assertSame( [ 'as_of', 'totals', 'slots', 'servers', 'leaderboard', 'available' ], \array_keys( $reply ) );
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

	/** A hundred entries is no crowd: every one stays, in its own order. */
	public function test_a_leaderboard_keeps_a_category_at_the_trim_bound_whole(): void {
		$entries = [];
		for ( $i = 1; $i <= 100; $i++ ) {
			$entries[ "SELECT moa_{$i}" ] = [ 3.0 * $i, 1, 3 ];
		}
		$board = Performance_CI_Node::leaderboard_of( [ 'wpdb' => [ 'samples' => 3, 'sum_time' => 30.0, 'sum_count' => 300, 'entries' => $entries ] ], 3, 33.0 );

		$this->assertCount( 100, $board['categories']['wpdb']['entries'] );
		$this->assertSame( 'SELECT moa_1', \array_key_first( $board['categories']['wpdb']['entries'] ) );
	}

	public function test_overview_leaderboard_spans_the_charts_288_buckets(): void {
		$this->seed_site_timing( [ 'category' => 'sql', 'bucket' => self::latest() - 290, 'ms' => 4000.0, 'calls' => 40, 'requests' => 4 ] );
		$this->seed_site_timing( [ 'category' => 'init hook @10', 'bucket' => self::latest() - 10, 'ms' => 31.5, 'calls' => 3, 'requests' => 3 ] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['leaderboard'];

		$this->assertSame( [ 'init hook @10' ], \array_keys( $board['categories'] ) );
	}

	public function test_overview_leaderboard_excludes_listener_time_from_total_time(): void {
		$this->seed_site_timing( [ 'category' => 'sql', 'ms' => 402.0, 'calls' => 18, 'requests' => 3 ] );
		$this->seed_site_timing( [ 'category' => 'sql', 'entry' => 'SELECT kea', 'ms' => 181.0, 'calls' => 5, 'requests' => 2 ] );
		$this->seed_site_timing( [ 'category' => 'init hook @10', 'listener' => 1, 'ms' => 90.0, 'calls' => 3, 'requests' => 3 ] );
		$this->seed_site_timing( [ 'category' => 'sql', 'worker' => 1, 'ms' => 9000.0, 'calls' => 70, 'requests' => 7 ] );
		$this->seed_site_timing( [ 'category' => 'sql', 'bucket' => self::latest() - 300, 'ms' => 4000.0, 'calls' => 40, 'requests' => 4 ] );
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

	/** The entries read stops at the deepest page a query may start, and says so. */
	public function test_the_entries_read_stops_at_the_offset_bound_and_says_so(): void {
		$rows = [ [ 'category' => 'heavy', 'ms' => 900000.5, 'calls' => 9, 'requests' => 1 ] ];
		for ( $i = 0; $i <= Stats_Query::MAX_OFFSET + Stats_Query::MAX_LIMIT; $i++ ) {
			$rows[] = [ 'category' => 'heavy', 'entry' => "SELECT moa {$i}", 'ms' => 0.5 + $i, 'calls' => 1, 'requests' => 1 ];
		}
		$this->seed_site_timings( $rows );
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

	public function test_urls_totals_count_distinct_urls_exactly(): void {
		$kakapo = 'https://seed.example/kakapo/';
		$this->seed_request( [ 'url' => $kakapo, 'status' => 200, 'ms' => 100.25, 'peak_mb' => 20.25, 'finished_at' => self::latest() * 300 + 11.5 ] );
		$this->seed_request( [ 'url' => $kakapo, 'status' => 404, 'ms' => 300.25, 'peak_mb' => 40.25, 'finished_at' => self::latest() * 300 + 42.25 ] );
		$this->seed_request( [ 'url' => $kakapo, 'status' => 503, 'ms' => null, 'error' => 'T', 'peak_mb' => 30.25 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/tui/', 'status' => 301, 'ms' => 50.25, 'peak_mb' => 10.25 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( 2, $reply['totals']['urls'] );
		$this->assertSame( 2, $reply['rows'] );
		$this->assertSame( 4, $reply['totals']['requests'] );
		$this->assertSame( 150.25, $reply['totals']['avg_ms'] );
		$this->assertSame( 25.25, $reply['totals']['avg_peak_mb'] );
		$this->assertEqualsWithDelta( 4 / ( 287 * 300 + self::tick() % 300 ), $reply['totals']['requests_per_second'], 1e-9, 'over the window\'s elapsed seconds' );
		$this->assertSame( 1, $reply['totals']['errors'], 'every reply counts its timeouts and fatals' );
		$this->assertSame(
			[
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

		$this->assertSame( [ 'https://seed.example/y/', 'https://seed.example/x/', 'https://seed.example/z/' ], \array_column( $desc['data'], 'url' ), 'x averages 250 over its requests, not 490 over its buckets' );
		$this->assertSame( 250.375, $desc['data'][1]['avg_ms'] );
		$this->assertSame( \array_column( $desc['data'], 'url' ), \array_column( $desc['slowest'], 'url' ), 'the slowest ten rank by the same mean' );
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
					\array_map( static fn ( string $path ): string => "https://seed.example/{$path}/", [ ...$timed, 'untimed' ] ),
					\array_column( $reply['data'], 'url' ),
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

		$this->assertSame( [ 'https://seed.example/aaa-moa/', 'https://seed.example/kiwi/', 'https://seed.example/weka/' ], \array_column( $by_name['data'], 'url' ) );
		$this->assertSame( [ 'https://seed.example/weka/', 'https://seed.example/kiwi/', 'https://seed.example/aaa-moa/' ], \array_column( $by_seen['data'], 'url' ) );
	}

	public function test_urls_pages_with_limit_and_offset_and_counts_every_row(): void {
		foreach ( [ 'a' => 5, 'b' => 4, 'c' => 3, 'd' => 2 ] as $path => $n ) {
			for ( $i = 0; $i < $n; $i++ ) {
				$this->seed_request( [ 'url' => "https://seed.example/{$path}/" ] );
			}
		}

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--limit=2 --offset=1' );

		$this->assertSame( [ 'https://seed.example/b/', 'https://seed.example/c/' ], \array_column( $reply['data'], 'url' ) );
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

		$this->assertSame( [ 'https://seed.example/about/wombat/', 'https://seed.example/wombat-7731/' ], \array_column( $whole['data'], 'url' ), 'a whole word of the path, never the host' );
		$this->assertSame( 2, $whole['totals']['urls'] );
		$this->assertSame( 'wombat', $whole['filters']['search'] );
		$this->assertSame( [ 'https://seed.example/wombat-7731/' ], \array_column( $syntax['data'], 'url' ), 'quotes and a minus are words, not operators' );
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

		$this->assertSame( [ 'https://seed.example/huia-503/' ], \array_column( $reply['data'], 'url' ) );
		$this->assertSame( [ 1, 3, 1 ], [ $reply['totals']['urls'], $reply['totals']['requests'], $reply['rows'] ] );
		$this->assertSame( [ 'https://seed.example/huia-503/' ], \array_column( $reply['slowest'], 'url' ), 'the slowest ten are the filtered set\'s' );
		$this->assertSame( [ 'status_class=5' ], $reply['filters']['filter'] );
		$this->assertSame( 0, $reply['totals']['errors'], 'every reply counts its errors' );
	}

	public function test_urls_filter_on_worker_replaces_the_include_workers_scope(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/w?pukeko', 'ms' => 4400.5, 'worker' => 1 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/tauhou/', 'ms' => 12.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=worker=1' );

		$this->assertSame( [ 'https://seed.example/w?pukeko' ], \array_column( $reply['data'], 'url' ) );
		$this->assertFalse( $reply['filters']['include_workers'] );
	}

	public function test_urls_two_filters_on_one_field_match_either(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/ruru-503/', 'status' => 503 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/ruru-404/', 'status' => 404 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/ruru-200/', 'status' => 200 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=status_class=5 --filter=status_class=4 --sort=url --order=asc' );

		$this->assertSame( [ 'https://seed.example/ruru-404/', 'https://seed.example/ruru-503/' ], \array_column( $reply['data'], 'url' ) );
		$this->assertSame( 2, $reply['totals']['requests'] );
	}

	public function test_urls_keeps_a_url_filter(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/kakariki-in/' ] );
		$this->seed_request( [ 'url' => 'https://seed.example/kakariki-out/' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=url=' . self::hash_of( 'https://seed.example/kakariki-in/' ) );

		$this->assertSame( [ 'https://seed.example/kakariki-in/' ], \array_column( $reply['data'], 'url' ) );
	}

	public function test_urls_refuses_a_filter_with_no_field(): void {
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=kakapo' );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( 'filter kakapo is no field=value', $reply );
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
		];
	}

	#[DataProvider( 'server_filter_refusals' )]
	public function test_a_filter_naming_server_is_refused_for_server( string $verb, string $tokens ): void {
		$this->seed_request( [ 'server' => 'kahu-9.example', 'url' => 'https://kahu-9.example/hoiho/' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, $tokens );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( "filter server=kahu-9.example is --server's: name the server with --server", $reply );
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
		$bucket = "filter bucket={$key} is --bucket's: name the buckets with --bucket";
		$url    = "filter url=0ddba11c4e5f is the hash's: name the URL with the hash";
		return [
			'urls bucket'               => [ 'urls', "--filter=bucket={$key}", $bucket ],
			'urls bucket beside --bucket' => [ 'urls', "--bucket={$key} --filter=bucket={$key}", $bucket ],
			'dump_url bucket'           => [ 'dump_url', "{$hash} --filter=bucket={$key}", $bucket ],
			'dump_url url'              => [ 'dump_url', "{$hash} --filter=url=0ddba11c4e5f", $url ],
			'ask overview: bucket'      => [ 'ask', "overview:site --filter=bucket={$key}", $bucket ],
			'ask url: bucket'           => [ 'ask', "url:{$hash} --filter=bucket={$key}", $bucket ],
			'ask url: url'              => [ 'ask', "url:{$hash} --filter=url=0ddba11c4e5f", $url ],
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

		$this->assertSame( [ 'https://seed.example/piopio-f/', 'https://seed.example/piopio-t/' ], \array_column( $reply['data'], 'url' ), 'an abort and a 5xx are no errors; a fatal after headers is one' );
		$this->assertSame( [ 2, 2 ], [ $reply['totals']['requests'], $reply['totals']['errors'] ] );
	}

	public function test_status_class_unknown_finds_a_timeout(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/titi-timeout/', 'error' => 'T', 'status' => 0, 'ms' => null ] );
		$this->seed_request( [ 'url' => 'https://seed.example/titi-ok/', 'ms' => 30.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--filter=status_class=Unknown' );

		$this->assertSame( [ 'https://seed.example/titi-timeout/' ], \array_column( $reply['data'], 'url' ) );
		$this->assertSame( 1, $reply['totals']['errors'] );
	}

	public function test_urls_include_workers_false_drops_worker_urls(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/reader/', 'ms' => 12.5 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/reader/', 'ms' => 12.5 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/w?reconcile', 'ms' => 180000.0, 'worker' => 1 ] );

		$readers = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		VerbHarness::reset();
		$all = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--include_workers' );

		$this->assertSame( [ 'https://seed.example/reader/' ], \array_column( $readers['data'], 'url' ) );
		$this->assertSame( [ 1, 2, 12.5 ], [ $readers['totals']['urls'], $readers['totals']['requests'], $readers['totals']['avg_ms'] ] );
		$this->assertFalse( $readers['filters']['include_workers'] );
		$this->assertSame( [ 2, 3 ], [ $all['totals']['urls'], $all['totals']['requests'] ] );
		$this->assertSame( [ 'https://seed.example/reader/' => false, 'https://seed.example/w?reconcile' => true ], \array_column( $all['data'], 'worker', 'url' ) );
	}

	public function test_urls_server_scopes_every_row_and_total(): void {
		$this->seed_request( [ 'server' => 'alpha.example', 'url' => 'https://alpha.example/shared/', 'status' => 404 ] );
		$this->seed_request( [ 'server' => 'beta.example', 'url' => 'https://beta.example/shared/', 'status' => 200 ] );
		$this->seed_request( [ 'server' => 'beta.example', 'url' => 'https://beta.example/shared/', 'status' => 200 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=alpha.example' );

		$this->assertSame( [ 'https://alpha.example/shared/' ], \array_column( $reply['data'], 'url' ) );
		$this->assertSame( [ 1, 0, 1 ], [ $reply['data'][0]['count'], $reply['data'][0]['count_2xx'], $reply['data'][0]['count_4xx'] ] );
		$this->assertSame( 1, $reply['totals']['requests'] );
		$this->assertSame(
			[ 'server' => 'alpha.example', 'search' => '', 'filter' => [], 'include_workers' => false, 'bucket' => '' ],
			$reply['filters']
		);
	}

	public function test_urls_bucket_selection_narrows_to_its_buckets(): void {
		$latest = self::latest();
		$this->seed_request( [ 'url' => 'https://seed.example/a/', 'ms' => 40.5, 'bucket' => $latest - 10 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/b/', 'ms' => 60.5, 'bucket' => $latest - 3 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/a/', 'ms' => 500.0 ] );
		$selection = Bucket::key( $latest - 3 ) . ',' . Bucket::key( $latest - 10 ) . '..' . Bucket::key( $latest - 9 );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', "--bucket={$selection} --sort=url --order=asc" );

		$this->assertSame( [ 'https://seed.example/a/' => 40.5, 'https://seed.example/b/' => 60.5 ], \array_column( $reply['data'], 'avg_ms', 'url' ) );
		$this->assertSame( 2, $reply['totals']['requests'] );
		$this->assertEqualsWithDelta( 2 / 900, $reply['totals']['requests_per_second'], 1e-12, 'three closed buckets of 300 seconds' );
		$this->assertSame( Bucket::key( $latest - 10 ) . '..' . Bucket::key( $latest - 9 ) . ',' . Bucket::key( $latest - 3 ), $reply['filters']['bucket'], 'the canonical spelling' );
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

	public function test_ask_overview_briefs_the_urls_page_and_the_board(): void {
		$this->seed_request( [ 'server' => 'alpha.example', 'url' => 'https://alpha.example/slow/', 'ms' => 900.5, 'profiled' => 1 ] );
		$this->seed_request( [ 'server' => 'beta.example', 'url' => 'https://beta.example/fast/', 'ms' => 30.0, 'profiled' => 1 ] );
		$this->seed_site_timing( [ 'server' => 'alpha.example', 'category' => 'wpdb', 'ms' => 44.4, 'calls' => 74, 'requests' => 1 ] );

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site --server=alpha.example --search=slow --include_workers=1' );

		$this->assertSame( 'alpha.example', $brief['server'] );
		$this->assertSame( [ 'urls' => 1, 'requests' => 1, 'avg_ms' => 900.5 ], \array_intersect_key( $brief['stats'], [ 'urls' => 1, 'requests' => 1, 'avg_ms' => 1 ] ) );
		$this->assertSame( [ self::hash_of( 'https://alpha.example/slow/' ) ], \array_column( $brief['urls'], 'hash' ) );
		$this->assertSame( 'wpdb', $brief['categories'][0]['name'] );
		$this->assertEqualsWithDelta( 44.4, $brief['categories'][0]['avg_time_ms'], 1e-9 );
		$this->assertTrue( $brief['filters']['include_workers'] );
		$this->assertArrayNotHasKey( 'estimated', $brief );
		$this->assertArrayNotHasKey( 'provisional', $brief );
		$this->assertSame( 'alpha.example', $brief['fetch'][0]['arguments']['server'] );
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

		$this->assertSame( [ 'stats', 'requests', 'requests_capped', 'aggregate_flame', 'flame_cut', 'aggregate_profiles' ], \array_keys( $reply ) );
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
			[ 'hash' => self::hash_of( $url ), 'url' => $url, 'count' => 0, 'avg_ms' => null, 'min_ms' => null, 'max_ms' => null, 'avg_peak_mb' => null, 'max_peak_mb' => 0, 'last_updated' => 0, 'errors' => 0, 'requests_per_second' => 0 ],
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
		$this->seed_timing( [ 'url' => $url, 'category' => 'sql', 'bucket' => $latest - 300, 'ms' => 5000.0, 'calls' => 50, 'requests' => 5 ] );
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
				'avg_peak_mb'         => 50.5,
				'max_peak_mb'         => 60.5,
				'last_updated'        => $latest * 300 + 8.5,
				'errors'              => 1,
				'requests_per_second' => 2 / ( 287 * 300 + self::tick() % 300 ),
			],
			$detail['stats']
		);
		$this->assertCount( 2, $detail['requests'] );
		$this->assertSame( [ 1, 1 ], [ $overview['totals']['urls'], $overview['totals']['requests'] ] );
		$this->assertSame( [ 'https://seed.example/reader-5/' ], \array_column( $urls['data'], 'url' ) );
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

		$this->assertSame( Bucket::key( $latest - 2 ) . '..' . Bucket::key( $latest - 1 ), $brief['bucket'] );
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
				'stats'              => [ 'hash' => self::hash_of( $url ), 'url' => '', 'count' => 0, 'avg_ms' => null, 'min_ms' => null, 'max_ms' => null, 'avg_peak_mb' => null, 'max_peak_mb' => 0, 'last_updated' => 0, 'errors' => 0, 'requests_per_second' => 0 ],
				'requests'           => [],
				'requests_capped'    => false,
				'aggregate_flame'    => null,
				'flame_cut'          => false,
				'aggregate_profiles' => null,
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
