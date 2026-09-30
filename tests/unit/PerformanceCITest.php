<?php
/**
 * PerformanceCITest: unit tests for Performance_CI, the M2 service-CI that
 * replaces the legacy performance dashboard REST controllers.
 *
 * Task 8 covers the first 5 of 19 planned verbs — the dashboard cluster:
 *   overview        — high-level stats across all partitions (lifted from
 *                     PerfOverviewController::get_overview).
 *   urls            — paginated/sortable URL list (lifted from
 *                     PerfUrlsController::get_urls).
 *   dump_url      — single-URL detail including aggregate flame data
 *                     (lifted from PerfUrlsController::get_url_detail).
 *   search_requests  — locate a request by rid across partitions (lifted from
 *                     PerfRequestsController::search_request).
 *   dump_request  — full request + flame data for a rid; its partition is a
 *                     search-order hint (lifted from
 *                     PerfRequestsController::get_request).
 *
 * Substrate config (num_partitions, min_lifetime, base_directory) is seeded
 * via TestCase::use_base_dir(), matching SettingsCITest / EventsCITest. The
 * stats live in SQLite Tables under that scratch base, seeded through the
 * harness stores; the shared `Core::$memd` handle is an in-memory `\Memcached`
 * for the URL page cache alone.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Settings_Event_Writer;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;

#[CoversClass( Performance_CI_Node::class )]
// Under coverage the urls fan-out and the first, class-loading test cost the most here.
class PerformanceCITest extends TestCase {
	private string $tmp;

	/** Seconds into its hour every reply's tick falls: seven buckets have closed. */
	private const INTO_HOUR = 37 * 60 + 40;

	protected function setUp(): void {
		parent::setUp();
		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS,
		// matching SettingsCITest / EventsCITest.
		$this->tmp  = '/tmp/performance-ci-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		Core::$memd = new InMemoryMemcached();
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
		$at          = (int) \microtime( true ) - (int) \microtime( true ) % Stats_Store::HOUR_SECONDS + self::INTO_HOUR;
		Core::$clock = static fn (): float => (float) $at;
		Core::right_now();
	}

	protected function tearDown(): void {
		\Newspack_Nodes\Topology_Registry::reset_basename_cache();
		VerbHarness::reset();
		Core::$clock                        = null;
		Settings_Event_Writer::$append_seam = null;
		Performance_CI_Node::$match_names   = false;
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
	// Test helpers — disk-seeded request + flame index entries.
	//
	// Mirror RequestLogControllerTest's `write_request` and the FlameBuilder
	// index layout so the verb's scan_index walk picks up our seeded data.
	// -------------------------------------------------------------------------

	private function current_url_bucket(): string {
		return Stats_Store::bucket_key( self::tick() );
	}

	/** Bytes already appended per scratch segment path — the append offset. */
	private array $segment_bytes = [];
	/**
	 * Append one record to a scratch `{log}.p{N}` segment plus its index, and
	 * return its rid. Requests and flames differ only in the log name and the
	 * formatter, whose signatures match.
	 *
	 * APPENDS. Rewriting the whole segment per record made seeding O(n^2) in
	 * bytes, which the cap tests pay 500 times over; the offset ledger is what
	 * lets the write be an append without re-reading to find the end.
	 *
	 * @param string   $log       Log basename ('requests' | 'flames').
	 * @param callable $format    `format_index_entry( array $message, array $position ): ?string`.
	 * @param array    $body      Record VALUE.
	 * @param int      $partition Partition index.
	 * @return string The record's rid.
	 */
	private function write_indexed( string $log, callable $format, array $body, int $partition ): string {
		$segment_dir = $this->tmp . "/logs/{$log}.p{$partition}";
		if ( ! \is_dir( $segment_dir ) ) {
			\mkdir( $segment_dir, 0755, true );
		}

		$message                       = Message::new_message();
		$message[ Message::TYPE ]      = Message::TM_STRUCT;
		$message[ Message::TIMESTAMP ] = (float) ( $body['timestamp'] ?? self::tick() );
		$message[ Message::VALUE ]     = $body;
		$packed                        = Message::packed( $message );

		$seg_path = "{$segment_dir}/0.log";
		$offset   = $this->segment_bytes[ $seg_path ] ?? 0;
		\file_put_contents( $seg_path, $packed, FILE_APPEND | LOCK_EX );
		$this->segment_bytes[ $seg_path ] = $offset + \strlen( $packed );

		$index_line = $format(
			$message,
			[ 'segment' => 0, 'offset' => $offset, 'length' => \strlen( $packed ) ]
		);
		if ( null !== $index_line && '' !== $index_line ) {
			\file_put_contents( "{$segment_dir}/0.idx", $index_line . "\n", FILE_APPEND | LOCK_EX );
		}
		return Core::as_string( $body['rid'] ?? '' );
	}

	private function write_request( array $body, int $partition = 0 ): string {
		return $this->write_indexed( 'requests', Request_Builder_Node::format_index_entry( ... ), $body, $partition );
	}

	private function write_flame( array $body, int $partition = 0 ): string {
		return $this->write_indexed( 'flames', Flame_Builder_Node::format_index_entry( ... ), $body, $partition );
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

	/**
	 * A verb's mount lives for the rest of the request, and a later verb in
	 * the same POST reads through it rather than mounting again (ADR-23).
	 */
	public function test_a_verbs_mount_lives_for_the_request_and_the_next_verb_reuses_it(): void {
		$this->assertIsArray( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' ) );
		$mount = Core::node( 'flame-stats:aggregate.p0' );
		$this->assertInstanceOf( \Newspack_Nodes\Table_Node::class, $mount, 'the mount outlives the verb that made it' );

		$this->assertIsArray( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'a4471ab0c0de --breakdown=status' ) );

		$this->assertSame( $mount, Core::node( 'flame-stats:aggregate.p0' ), 'the next verb reads through the same mount' );
	}

	public function test_a_verb_reached_through_dispatch_leaves_its_mount_for_the_next(): void {
		// MCP mounts the request graph and calls the CI's dispatch(), never its fill().
		VerbHarness::request_graph();
		$ci = Core::node( 'performance' );
		$this->assertInstanceOf( Performance_CI_Node::class, $ci );

		$this->assertIsArray( $ci->dispatch( 'overview' ) );
		$mount = Core::node( 'flame-stats:aggregate.p0' );
		$this->assertInstanceOf( \Newspack_Nodes\Table_Node::class, $mount );
		$this->assertIsArray( $ci->dispatch( 'url_breakdown', [ 'a4471ab0c0de', '--breakdown=method' ] ) );
		$this->assertSame( $mount, Core::node( 'flame-stats:aggregate.p0' ) );
	}

	/**
	 * A mount in a process running as root is refused, and the verb says so
	 * rather than answering a zeroed dashboard: a root reader would leave
	 * `-wal` and `-shm` files its worker cannot open.
	 */
	public function test_a_mount_as_root_fails_the_verb_loud(): void {
		\Newspack_Nodes\CLI::$uid_provider = static fn (): int => 0;
		try {
			$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'a4471ab0c0de --breakdown=status' );
		} finally {
			\Newspack_Nodes\CLI::$uid_provider = null;
		}

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'refuses to run as root', $reply );
		$this->assertEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'stats Tables did not mount' ) ),
			'a root refusal is no backend that cannot open'
		);
	}

	public function test_stats_fail_soft_when_a_table_cannot_open(): void {
		\Newspack_Nodes\Sqlite_Arm::$available = static fn (): bool => false;
		try {
			$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		} finally {
			\Newspack_Nodes\Sqlite_Arm::$available = null;
		}
		$this->assertIsArray( $reply, 'no data, no throw' );
		$this->assertArrayHasKey( 'requests', $reply['totals'] );
		$this->assertSame( 0, $reply['totals']['requests'] );
		$this->assertNull( Core::node( 'flame-stats:aggregate.p0' ), 'a mount that threw leaves nothing mounted' );
		$this->assertNotEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'stats Tables did not mount' ) && \str_contains( $line, 'pdo_sqlite' ) ),
			'the refusal is said, naming its cause'
		);
	}

	public function test_stats_fail_soft_when_the_tables_dir_cannot_be_made(): void {
		// A file where the directory belongs: every Table's file fails to open.
		\file_put_contents( $this->tmp . '/tables', 'not a directory' );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertIsArray( $reply, 'no data, no throw' );
		$this->assertSame( 0, $reply['totals']['requests'] );
	}

	public function test_a_declaration_an_arm_refuses_fails_the_verb_loud(): void {
		// A namespace too long for the wpdb column is the declaration's fault,
		// though its refusal names the Table and chains the arm's beneath it.
		$reply = $this->fire_overview_with_topology( 'stats-kea-4471', 'make_node Table flame-stats:aggregate ' . \str_repeat( 'k', 204 ) . ' 777 wpdb' );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'Table flame-stats:aggregate: wpdb backend cannot hold namespace', $reply );
	}

	public function test_a_tables_dir_that_is_a_symlink_fails_the_verb_loud(): void {
		// A path the runtime refuses to adopt is the operator's to fix, not a
		// backend that cannot open, though its refusal names the Table too.
		\mkdir( $this->tmp . '/tables-kea-5510' );
		\symlink( $this->tmp . '/tables-kea-5510', $this->tmp . '/tables' );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'symlink or path traversal detected', $reply );
	}

	public function test_a_table_declared_with_a_zero_ttl_fails_the_verb_loud(): void {
		$reply = $this->fire_overview_with_topology( 'stats-kea-3717', 'make_node Table flame-stats:aggregate evlog:p<partition> 0 sqlite' );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'TTL', $reply );
	}

	public function test_a_table_two_topologies_declare_differently_fails_the_verb_loud(): void {
		// Operator misconfiguration is no unreachable backend (decision 3): it says so.
		$reply = $this->fire_overview_with_topology( 'stats-kea-7731', 'make_node Table flame-stats:aggregate evlog:p<partition> 777 sqlite', [ 'performance' ] );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'declared differently', $reply );
	}

	/**
	 * Fire `overview` with a one-partition user topology holding `$line`
	 * active, beside the `$beside` topologies.
	 *
	 * @param string       $name   The user topology's name.
	 * @param string       $line   Its one `make_node` line.
	 * @param list<string> $beside Other active topologies.
	 * @return mixed The verb's reply.
	 */
	private function fire_overview_with_topology( string $name, string $line, array $beside = [] ): mixed {
		$dir = $this->tmp . '/user-topologies';
		\mkdir( $dir );
		\file_put_contents( "{$dir}/{$name}.tsl", "{$line}\nsecure\n" );
		$previous = \Newspack_Nodes\Topology_Registry::user_dir();
		\Newspack_Nodes\Topology_Registry::register_user_dir( $dir );
		\add_filter(
			'newspack_nodes/topologies',
			static function ( array $topologies ) use ( $name ): array {
				$topologies[ $name ] = [ 'topology' => $name, 'num_partitions' => 1, 'stale_timeout' => 60 ];
				return $topologies;
			}
		);
		$GLOBALS['_wp_options']['newspack_nodes_topologies'] = [ ...$beside, $name ];
		try {
			return VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );
		} finally {
			\Newspack_Nodes\Topology_Registry::register_user_dir( $previous );
		}
	}

	public function test_url_breakdown_mounts_only_the_table_it_reads(): void {
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'a4471ab0c0de --breakdown=status' );

		$this->assertIsArray( $reply );
		$this->assertInstanceOf( \Newspack_Nodes\Table_Node::class, Core::node( 'flame-stats:aggregate.p0' ) );
		$this->assertNull( Core::node( 'flame-stats:url.p0' ), 'the per-URL Table is not mounted' );
		$this->assertNull( Core::node( 'flame-stats:url-fine.p0' ), 'nor the fine Table' );
		$this->assertFileDoesNotExist( \Newspack_Nodes\Table_Node::file( Stats_Store::TABLE_AGGREGATE, 0 ), 'a mount creates no file its worker has not' );
	}

	public function test_overview_verb_returns_empty_shape_when_no_data(): void {
		// No URL buckets seeded — verb still returns the canonical envelope
		// with zeroed totals + empty leaderboard.
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total_requests'] );
		$this->assertEquals( 0.0, $result['global_avg_ms'] );
		$this->assertEquals( 0.0, $result['global_avg_peak_mb'] );
		$this->assertCount( 288, $result['slots'] );
		// The URL-set facts belong to the `urls` verb now.
		$this->assertArrayNotHasKey( 'total_urls', $result );
		$this->assertArrayNotHasKey( 'slowest_urls', $result );
		$this->assertArrayNotHasKey( 'most_requested', $result );
	}

	public function test_overview_verb_aggregates_hourly_totals(): void {
		// Seed an hourly bucket — verb totals should add up across the
		// merged time_series array.
		$store = $this->stats_store( 0, 86400 );
		// Inside the reader's enumerated window: buckets are keys now, not a blob.
		$now = self::tick();
		$bucket = Stats_Store::bucket_key( $now );
		$this->set_hour_slot( $store, Stats_Store::hourly_parts(), $bucket, [ 'count' => 4, 'sum_ms' => 2000.0, 'requests' => 4, 'sum_peak_mb' => 40.0 ] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

		$this->assertSame( 4, $result['total_requests'] );
		$this->assertEquals( 500.0, $result['global_avg_ms'] );
		$this->assertEquals( 10.0, $result['global_avg_peak_mb'] );
	}

	/**
	 * At 14:37 a chart reads the current hour and the 24 before it, and
	 * draws the 288 slots from 2026-09-28-14-40 to 2026-09-29-14-35: the
	 * oldest hour's earlier slots, and a slot past now, are not drawn.
	 */
	public function test_a_chart_reads_twenty_five_hours_and_draws_the_trailing_288_slots(): void {
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$store     = $this->stats_store( 0, 86400 );
		foreach ( [
			'2026-09-28-14-35' => 41, // One slot before the window.
			'2026-09-28-14-40' => 43, // The oldest slot drawn.
			'2026-09-29-14-35' => 37, // The current bucket.
			'2026-09-29-14-45' => 47, // Past now.
		] as $bucket => $count ) {
			$this->set_hour_slot( $store, Stats_Store::dim_parts( 'ua', '' ), $bucket, [ 'kea-ua/7' => self::dim_entry( $count, 4.3, 0.4 ) ] );
		}

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=ua' );

		$this->assertCount( 288, $reply['slots'] );
		$this->assertSame( [ '2026-09-29-14-35', '2026-09-28-14-40' ], [ $reply['slots'][0], $reply['slots'][287] ] );
		$this->assertSame(
			[
				'names'   => [ 'kea-ua/7' ],
				'buckets' => [ '2026-09-28-14-40' => [ [ 0, 43, 4.3, 0.4, 43 ] ], '2026-09-29-14-35' => [ [ 0, 37, 4.3, 0.4, 37 ] ] ],
			],
			$reply['breakdowns']['ua']
		);
	}

	/**
	 * Every chart reply names the 288 slots it drew, so the dashboard's axis
	 * is the reply's and never the browser's clock.
	 *
	 * @param string $verb The verb.
	 * @param string $args Its arguments.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'chart_replies' )]
	public function test_a_chart_reply_names_its_288_slots( string $verb, string $args ): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'min_lifetime' => 21600 ] );
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$store     = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, '2026-09-29-14-35', [ 'e71b04ac9d33' => [ 'url' => '/plan-4471', 'count' => 6, 'last_seen' => (int) Core::$now ] ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, $args );

		$slots = $reply['slots'] ?? [];
		$this->assertCount( 288, $slots, 'the chart draws 24 hours whatever the retention window' );
		$this->assertSame( [ '2026-09-29-14-35', '2026-09-29-14-30', '2026-09-28-14-40' ], [ $slots[0], $slots[1], $slots[287] ] );
		$this->assertArrayNotHasKey( 'plan', $reply );
	}

	/**
	 * The totals cover what the charts draw: every slot of the 288, and none
	 * before them or past now, whatever the retention window.
	 */
	public function test_overview_totals_sum_the_slots_the_charts_draw(): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'min_lifetime' => 21600 ] );
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$store     = $this->stats_store( 0, 86400 );
		$this->set_hour_slot( $store, Stats_Store::hourly_parts(), '2026-09-28-14-35', [ 'count' => 41, 'requests' => 41, 'sum_ms' => 410.0, 'sum_peak_mb' => 4.1 ] );
		$this->set_hour_slot( $store, Stats_Store::hourly_parts(), '2026-09-28-14-40', [ 'count' => 43, 'requests' => 43, 'sum_ms' => 860.0, 'sum_peak_mb' => 8.6 ] );
		$this->set_hour_slot( $store, Stats_Store::hourly_parts(), '2026-09-29-08-50', [ 'count' => 37, 'requests' => 37, 'sum_ms' => 740.0, 'sum_peak_mb' => 7.4 ] );
		$this->set_hour_slot( $store, Stats_Store::hourly_parts(), '2026-09-29-14-45', [ 'count' => 47, 'requests' => 47, 'sum_ms' => 470.0, 'sum_peak_mb' => 4.7 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertSame( 80, $reply['total_requests'], 'the oldest drawn slot and one behind the six-hour window' );
		$this->assertEqualsWithDelta( 20.0, $reply['global_avg_ms'], 1e-9 );
		$this->assertEqualsWithDelta( 0.2, $reply['global_avg_peak_mb'], 1e-9 );
		$this->assertArrayNotHasKey( 'aggregate_time_series', $reply );
	}

	/**
	 * The leaderboard sums the current hour's key and the 24 before it: an
	 * hour key is one sum, so it cannot trim to the 288 slots.
	 */
	public function test_the_leaderboard_sums_the_current_hour_and_the_24_before_it(): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'min_lifetime' => 21600 ] );
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$store     = $this->stats_store( 0, 86400 );
		$this->set_leaderboard_hour( $store, '2026-09-29-14', [ 'count' => 41, 'sum_req_time' => 4.1, 'categories' => [] ] );
		$this->set_leaderboard_hour( $store, '2026-09-28-14', [ 'count' => 43, 'sum_req_time' => 4.3, 'categories' => [] ] );
		$this->set_leaderboard_hour( $store, '2026-09-28-13', [ 'count' => 47, 'sum_req_time' => 4.7, 'categories' => [] ] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['global_leaderboard'];

		$this->assertSame( 84, $board['count'], 'the 25 hour keys, and not the one before them' );
	}

	/**
	 * The board's average divides the same 25 hour keys its categories sum:
	 * every `hourly_h` slot of them, the oldest hour's undrawn head included,
	 * and nothing before them.
	 */
	public function test_the_leaderboard_average_covers_exactly_its_hour_keys(): void {
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$store     = $this->stats_store( 0, 86400 );
		$this->set_hour_slot( $store, Stats_Store::hourly_parts(), '2026-09-28-13-55', [ 'count' => 47, 'sum_ms' => 47000.0, 'sum_peak_mb' => 4.7 ] );
		$this->set_hour_slot( $store, Stats_Store::hourly_parts(), '2026-09-28-14-05', [ 'count' => 41, 'sum_ms' => 4100.0, 'sum_peak_mb' => 4.1 ] );
		$this->set_hour_slot( $store, Stats_Store::hourly_parts(), '2026-09-29-14-35', [ 'count' => 43, 'sum_ms' => 860.0, 'sum_peak_mb' => 4.3 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertEqualsWithDelta( 4960.0 / 84, $reply['global_leaderboard']['avg_ms'], 1e-9, 'the 25th hour\'s head counts, the hour before it does not' );
		$this->assertEqualsWithDelta( 20.0, $reply['global_avg_ms'], 1e-9, 'the charts\' average stays on the 288 slots' );
	}

	/**
	 * The average peak divides every request the peak sum covers, the
	 * untimed ones included: a timeout's peak memory is still measured.
	 */
	public function test_the_average_peak_divides_every_request_it_sums(): void {
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$this->set_hour_slot( $this->stats_store( 0, 86400 ), Stats_Store::hourly_parts(), '2026-09-29-14-35', [ 'count' => 2, 'requests' => 3, 'sum_ms' => 100.0, 'sum_peak_mb' => 61.5 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertSame( 2, $reply['total_requests'], 'the timed requests, as decision 24 counts them' );
		$this->assertEqualsWithDelta( 50.0, $reply['global_avg_ms'], 1e-9 );
		$this->assertEqualsWithDelta( 20.5, $reply['global_avg_peak_mb'], 1e-9, 'three requests\' peaks over three requests' );
	}

	/**
	 * `overview` reads the 25 `hourly_h` keys once, for its totals and the
	 * board's average alike, and reads no `server` dimension unless scoped.
	 */
	public function test_overview_reads_the_hourly_keys_once(): void {
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$this->set_hour_slot( $this->stats_store( 0, 86400 ), Stats_Store::hourly_parts(), '2026-09-29-14-35', [ 'count' => 41, 'requests' => 41, 'sum_ms' => 410.0, 'sum_peak_mb' => 4.1 ] );
		$this->forget_stats_asks();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertEqualsWithDelta( 10.0, $reply['global_leaderboard']['avg_ms'], 1e-9 );
		$this->assertCount( 1, $this->asked_batches( Stats_Store::NS_HOURLY_HOUR ), 'one hourly read serves both' );
		$this->assertSame( [], $this->asked_batches( Stats_Store::NS_DIM_HOUR ), 'no server row without a server scope' );
	}

	/**
	 * An ask reads the board's categories and never its average, so it
	 * spends no read on one.
	 */
	public function test_an_ask_reads_no_average(): void {
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$this->set_leaderboard_hour( $this->stats_store( 0, 86400 ), '2026-09-29-14', [
			'count'        => 41,
			'sum_req_time' => 4.1,
			'categories'   => [ 'wpdb' => [ 'samples' => 41, 'sum_time' => 44.4, 'sum_count' => 82, 'entries' => [] ] ],
		], 'edge-kea.test' );
		$this->forget_stats_asks();

		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site --server=edge-kea.test' );
		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'category:wpdb --server=edge-kea.test' );

		$this->assertSame( [], $this->asked_batches( Stats_Store::NS_HOURLY_HOUR, Stats_Store::NS_DIM_HOUR ) );
	}

	/**
	 * Under a server scope the board's average is that server's timed mean
	 * over the board's own 25 hour keys, from the `server` dimension's slots.
	 */
	public function test_a_server_boards_average_is_that_servers_timed_mean_over_its_hour_keys(): void {
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$store     = $this->stats_store( 0, 86400 );
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'server', '' ), '2026-09-28-14-05', [
			'edge-kea.test'  => self::dim_entry( 3, 100.0, 0.9, 2 ),
			'edge-weka.test' => self::dim_entry( 7, 7000.0, 0.7 ),
		] );
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'server', '' ), '2026-09-29-14-35', [ 'edge-kea.test' => self::dim_entry( 5, 440.0, 0.5, 4 ) ] );
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'server', '' ), '2026-09-28-13-55', [ 'edge-kea.test' => self::dim_entry( 9, 9000.0, 0.9 ) ] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--server=edge-kea.test' )['global_leaderboard'];

		$this->assertEqualsWithDelta( 540.0 / 6, $board['avg_ms'], 1e-9, 'timed requests alone, over the 25 hour keys' );
	}

	/**
	 * A dimension row carries its timed count, so an average divides the
	 * requests whose duration was a sample (decision 24).
	 */
	public function test_a_breakdown_row_carries_its_timed_count(): void {
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$this->set_hour_slot( $this->stats_store( 0, 86400 ), Stats_Store::dim_parts( 'ua', '' ), '2026-09-29-14-35', [ 'kea-ua/7' => self::dim_entry( 3, 100.5, 0.9, 2 ) ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=ua' );

		$this->assertSame( [ '2026-09-29-14-35' => [ [ 0, 3, 100.5, 0.9, 2 ] ] ], $reply['breakdowns']['ua']['buckets'] );
	}

	/**
	 * The current bucket at slot 0: the 25th hour key gives slots 1 to 11.
	 * At slot 11 it gives none, and the window starts on the next hour.
	 *
	 * @param int                 $minute  Minute past 14:00 on 2026-09-29.
	 * @param array{0:string,1:string} $ends    The newest and oldest slot drawn.
	 * @param list<string>        $drawn   Seeded buckets the chart draws.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'hour_edges' )]
	public function test_the_oldest_hour_key_gives_the_slots_after_the_current_ones( int $minute, array $ends, array $drawn ): void {
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, $minute, 11, 9, 29, 2026 );
		$store     = $this->stats_store( 0, 86400 );
		foreach ( [ '2026-09-28-14-00', '2026-09-28-14-05', '2026-09-28-14-55', '2026-09-28-15-00' ] as $n => $bucket ) {
			$this->set_hour_slot( $store, Stats_Store::dim_parts( 'ua', '' ), $bucket, [ 'kea-ua/7' => self::dim_entry( 41 + $n, 4.1, 0.4 ) ] );
		}

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=ua' );

		$this->assertSame( $ends, [ $reply['slots'][0], $reply['slots'][287] ] );
		$this->assertSame( $drawn, \array_keys( $reply['breakdowns']['ua']['buckets'] ) );
	}

	/** @return array<string,array{0:int,1:array{0:string,1:string},2:list<string>}> */
	public static function hour_edges(): array {
		return [
			'14:02, slot 0'  => [ 2, [ '2026-09-29-14-00', '2026-09-28-14-05' ], [ '2026-09-28-14-05', '2026-09-28-14-55', '2026-09-28-15-00' ] ],
			'14:57, slot 11' => [ 57, [ '2026-09-29-14-55', '2026-09-28-15-00' ], [ '2026-09-28-15-00' ] ],
		];
	}

	public function test_url_breakdown_reads_its_one_dimension_as_a_name_table(): void {
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$store     = $this->stats_store( 0, 86400 );
		$this->set_hour_slot( $store, Stats_Store::url_dim_parts( 'c0ffee7731ab' ), '2026-09-29-14-35', [ 'kea-ua/7' => self::dim_entry( 41, 4.1, 0.4 ) ], 'ua' );
		$this->set_hour_slot( $store, Stats_Store::url_dim_parts( 'c0ffee7731ab' ), '2026-09-29-14-35', [ '5xx' => self::dim_entry( 43, 4.3, 0.4 ) ], 'status' );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'c0ffee7731ab --breakdown=ua' );

		$this->assertSame(
			[ 'names' => [ 'kea-ua/7' ], 'buckets' => [ '2026-09-29-14-35' => [ [ 0, 41, 4.1, 0.4, 41 ] ] ] ],
			$reply['breakdown_time_series']
		);
	}

	/**
	 * Every dimension asked answers a key, an empty one included (decision
	 * 16), and a name PHP would key as an integer reaches the wire a string.
	 */
	public function test_every_breakdown_asked_answers_a_name_table_of_strings(): void {
		$this->activate_shipped( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$this->set_hour_slot( $this->stats_store( 0, 86400 ), Stats_Store::dim_parts( 'from', '' ), '2026-09-29-14-35', [ '4471' => self::dim_entry( 41, 4.1, 0.4 ) ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=from,ja4' );

		$this->assertSame( [ 'names' => [], 'buckets' => [] ], $reply['breakdowns']['ja4'] );
		$this->assertSame( '{"names":["4471"],"buckets":{"2026-09-29-14-35":[[0,41,4.1,0.4,41]]}}', \wp_json_encode( $reply['breakdowns']['from'] ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function chart_replies(): array {
		return [
			'overview'      => [ 'overview', '' ],
			'dump_url'      => [ 'dump_url', 'e71b04ac9d33' ],
			'url_breakdown' => [ 'url_breakdown', 'e71b04ac9d33 --breakdown=status' ],
		];
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

	public function test_overview_verb_includes_global_leaderboard_by_default(): void {
		// OverviewSection (React) renders `overview.global_leaderboard.{categories,total_time,count}`
		// (see components/OverviewSection.js L330-358). Legacy PerfOverviewController::get_overview
		// emits `global_leaderboard` unconditionally (L95-97). The interpreter verb must match.
		$store   = $this->stats_store( 0, 86400 );
		// Seed the most-recent bucket so the leaderboard fan-out picks it up.
		$this->set_leaderboard_hour( $store, Stats_Store::hour_of( $this->current_url_bucket() ), [
			'count'        => 4,
			'sum_req_time' => 0.8,
			'categories'   => [
				'db' => [ 'samples' => 4, 'sum_time' => 0.4, 'sum_count' => 10 ],
			],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

		$this->assertArrayHasKey( 'global_leaderboard', $result );
		$this->assertSame( 4, $result['global_leaderboard']['count'] );
		$this->assertArrayHasKey( 'categories', $result['global_leaderboard'] );
		$this->assertArrayHasKey( 'db', $result['global_leaderboard']['categories'] );
		$this->assertArrayHasKey( 'total_time', $result['global_leaderboard'] );
	}

	public function test_overview_verb_uses_server_leaderboard_when_server_arg_set(): void {
		// `server` arg scopes the leaderboard to that server (legacy L95-97
		// switches to `build_server_leaderboard`). The interpreter verb must reroute.
		$store   = $this->stats_store( 0, 86400 );
		$this->set_leaderboard_hour( $store, Stats_Store::hour_of( $this->current_url_bucket() ), [
			'count'        => 2,
			'sum_req_time' => 0.2,
			'categories'   => [
				'db' => [ 'samples' => 2, 'sum_time' => 0.1, 'sum_count' => 4 ],
			],
		], 'web01' );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--server=web01'
		);

		// Only the server-scoped leaderboard was seeded — global is empty.
		// `count` should reflect the server-scoped data.
		$this->assertSame( 2, $result['global_leaderboard']['count'] );
	}

	public function test_the_leaderboard_reads_an_older_hours_key(): void {
		// An hour's key is its whole board: the reader walks no bucket.
		$store = $this->stats_store( 0, 86400 );
		$hour  = Stats_Store::hour_of( Stats_Store::bucket_key( self::tick() - 7200 ) );
		$this->set_leaderboard_hour( $store, $hour, [
			'count'        => 9,
			'sum_req_time' => 27.0,
			'categories'   => [ 'wpdb' => [ 'samples' => 9, 'sum_time' => 45.0, 'sum_count' => 18, 'entries' => [] ] ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$board = $result['global_leaderboard'];
		$this->assertSame( 9, $board['count'], 'the hour key answers for its buckets' );
		$this->assertEqualsWithDelta( 5.0, $board['categories']['wpdb']['time'], 1e-9 );
	}

	public function test_overview_verb_includes_category_time_series_when_categories_arg_set(): void {
		// Legacy `?categories=1` (L121-125) adds `category_time_series` to the
		// response — global or server-scoped. The dashboard always passes
		// `categories=1` (see usePerformanceApi.js L54) and reads
		// `overviewData.category_time_series` (PerformanceDashboard.js L391).
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_hour_slot( $store, Stats_Store::cat_parts( '' ), $bucket, [ 'db' => self::cat_entry( 0.5, 4, 4 ) ] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--categories'
		);

		// The NAME rides once in a table, not in all 288 buckets, and each
		// entry is the positional triple the store already holds. Lossless:
		// every category and every bucket survives — what goes is the ~30 bytes
		// of key names JSON spends on each of ~14,400 entries.
		$series = $result['category_time_series'];
		$this->assertSame( [ 'db' ], $series['names'] );
		$this->assertSame( [ [ 0, 0.5, 4, 4 ] ], $series['buckets'][ $bucket ] );
	}

	public function test_category_series_rounds_a_sum_across_partitions_to_display_precision(): void {
		// The STORE rounds what it writes, so one partition's entry is already
		// at display precision. This series is a SUM across one store per
		// flame-builder partition, and a sum of rounded doubles is not itself
		// rounded: 0.1 + 0.2 serializes as 0.30000000000000004, nineteen
		// characters for a number the chart draws as three. Rounding at the
		// wire is what keeps the frame change from being undone on the reply.
		$this->activate_shipped( 'performance', 2 );
		$bucket = $this->current_url_bucket();
		$this->set_hour_slot( $this->stats_store( 0, 86400 ), Stats_Store::cat_parts( '' ), $bucket, [ 'zither render' => self::cat_entry( 0.1, 3, 1 ) ] );
		$this->set_hour_slot( $this->stats_store( 1, 86400 ), Stats_Store::cat_parts( '' ), $bucket, [ 'zither render' => self::cat_entry( 0.2, 4, 1 ) ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--categories' );

		$this->assertSame(
			'[[0,0.3,7,2]]',
			\wp_json_encode( $result['category_time_series']['buckets'][ $bucket ] ),
			'the summed milliseconds reach the wire at display precision'
		);
	}

	public function test_overview_verb_answers_one_dimension_in_the_breakdowns_map(): void {
		// One dimension is the same shape as five. Answered flat instead, the
		// key the reader looks the dimension up by is absent, and a reader
		// that reads an absent key as "still in flight" waits forever.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'country', '' ), $bucket, [ 'PT' => self::dim_entry( 23, 2.3, 0.7 ) ] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--breakdown=country'
		);

		$this->assertSame( 23, self::wire_series( $result['breakdowns']['country'] )[ $bucket ]['PT'][ Stats_Store::DIM_COUNT ] );
		$this->assertArrayNotHasKey( 'breakdown_time_series', $result );
	}

	/**
	 * A frame written before the positional shape sums to a zero count under
	 * `DIM_SUMS`; the reader drops it as the writer does, so it is never a
	 * legend row with a flat zero line until the flush ages it out.
	 */
	public function test_overview_breakdown_drops_a_value_nothing_measured(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'country', '' ), $bucket, [
			'PT' => self::dim_entry( 23, 2.3, 0.7 ),
			'FR' => [ 'c' => 9, 's' => 1.0, 'm' => 1.0 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=country' );

		$this->assertSame( [ 'PT' ], \array_keys( self::wire_series( $result['breakdowns']['country'] )[ $bucket ] ) );
	}

	public function test_overview_verb_includes_breakdowns_map_for_multi_dim(): void {
		// Comma-separated dims return nested `breakdowns: { dim => series }`
		// (legacy L113-118). Used by the dashboard's `breakdownsFor` deduper
		// (PerformanceDashboard.js L342-374), which always sends ≥2 dims so it
		// can rely on the nested shape.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'server', '' ), $bucket, [ 'web01' => self::dim_entry( 5, 0.5, 0.1 ) ] );
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'status', '' ), $bucket, [ '200' => self::dim_entry( 4, 0.4, 0.1 ) ] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--breakdown=server,status'
		);

		$this->assertArrayHasKey( 'breakdowns', $result );
		$this->assertArrayHasKey( 'server', $result['breakdowns'] );
		$this->assertArrayHasKey( 'status', $result['breakdowns'] );
		$this->assertSame( 5, self::wire_series( $result['breakdowns']['server'] )[ $bucket ]['web01'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( 4, self::wire_series( $result['breakdowns']['status'] )[ $bucket ]['200'][ Stats_Store::DIM_COUNT ] );
	}

	public function test_overview_server_scope_keeps_the_global_server_dimension(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'server', '' ), $bucket, [
			'edge-amber.example'  => self::dim_entry( 37, 3700.0, 259.0 ),
			'edge-violet.example' => self::dim_entry( 11, 1430.0, 99.0 ),
		] );
		$this->set_hour_slot( $store, Stats_Store::dim_parts( 'status', 'edge-amber.example' ), $bucket, [ '2xx' => self::dim_entry( 37, 3700.0, 259.0 ) ] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--server=edge-amber.example --breakdown=server,status'
		);

		$this->assertSame( 37, self::wire_series( $result['breakdowns']['server'] )[ $bucket ]['edge-amber.example'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( 11, self::wire_series( $result['breakdowns']['server'] )[ $bucket ]['edge-violet.example'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( 37, self::wire_series( $result['breakdowns']['status'] )[ $bucket ]['2xx'][ Stats_Store::DIM_COUNT ] );
	}

	public function test_overview_verb_refuses_an_unknown_breakdown_dimension(): void {
		// An unknown dimension still never reaches a memcache key. Dropped
		// silently it answered about the dimensions it recognized instead,
		// which reads on the client as a dimension that never arrives; a
		// chart option added without its dimension has to say so.
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--breakdown=server,viewport'
		);

		$this->assertStringContainsString( 'invalid breakdown dimension: viewport', $result );
	}

	public function test_overview_verb_server_scoped_categories_when_both_args(): void {
		// `?server=X&categories=1` should use the per-server categories
		// blob (legacy L122-124 `merge_server_categories_across_partitions`).
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_hour_slot( $store, Stats_Store::cat_parts( 'web01' ), $bucket, [ 'db' => self::cat_entry( 0.2, 2, 2 ) ] );
		$this->set_hour_slot( $store, Stats_Store::cat_parts( '' ), $bucket, [ 'db' => self::cat_entry( 9.9, 99, 99 ) ] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--server=web01 --categories'
		);

		// Server-scoped data, not the global ones, in the compact wire shape.
		$this->assertSame( [ 'db' ], $result['category_time_series']['names'] );
		$this->assertSame( 0.2, $result['category_time_series']['buckets'][ $bucket ][0][1] );
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
	}

	public function test_urls_verb_default_limit_is_50(): void {
		// Legacy controller default — `limit=50` from sanitize_callback default.
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertSame( 50, $result['limit'] );
	}

	public function test_urls_verb_clamps_limit_high(): void {
		// Mirrors `min(1000, max(1, (int)$v))` from legacy sanitize_callback.
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--limit=5000'
		);
		$this->assertSame( 1000, $result['limit'] );
	}

	public function test_urls_verb_paginates_and_sorts(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/a', 'count' => 1, 'sum_ms' => 100.0, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => '/b', 'count' => 5, 'sum_ms' => 500.0, 'last_seen' => 1700000002 ],
			'cccccccccccc' => [ 'url' => '/c', 'count' => 3, 'sum_ms' => 300.0, 'last_seen' => 1700000003 ],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--sort=count --order=desc --limit=2 --offset=0'
		);

		$this->assertSame( 3, $result['rows'] );
		$this->assertCount( 2, $result['data'] );
		// Desc by count: /b first (5), /c second (3).
		$this->assertSame( 'https://example.com/b', $result['data'][0]['url'] );
		$this->assertSame( 'https://example.com/c', $result['data'][1]['url'] );
	}

	public function test_a_search_asks_urlmap_for_its_candidates_not_for_the_index(): void {
		// `resolve_urls()` builds one `urlmap:{hash}` key per row, and a search
		// used to hand it the WHOLE shard index rather than the page it
		// documents. On the hub that is 668,918 hashes x 4 partitions in one
		// poll, which is what runs the verb to 290 seconds. The token index
		// names the term's candidates first, and only those are named.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		// 137 URLs, all in shard `a`, distinct from every cap in this schema.
		$seeded = [];
		for ( $i = 0; $i < 137; $i++ ) {
			$seeded[ \sprintf( 'a%011x', $i ) ] = [
				'url'       => \sprintf( 'https://alpha.test/page-%d', $i ),
				'count'     => $i + 1,
				'last_seen' => 1700000000 + $i,
			];
		}
		$this->set_url_bucket( $store, $bucket, $seeded );
		$this->forget_stats_asks();

		// `131` is a token of `/page-131` alone; `page` is a token of all 137.
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--search=page-131' );

		$this->assertSame( 1, $result['rows'], 'the intersection is one URL' );
		$this->assertSame( 'https://alpha.test/page-131', $result['data'][0]['url'] );
		$per_hash = \count( $this->asked_keys( Stats_Store::NS_URLMAP ) );
		$this->assertLessThan( 10, $per_hash, 'a search names its candidates, not the index' );
	}

	public function test_a_search_does_not_match_the_host(): void {
		// The host is already the split's KEY on every row, and the dropdown is
		// built from the `server` dimension — two indexes that answer about a
		// server. Leave it in the searchable text and one box asks the
		// dropdown's question: on a hub every row matches the busiest host.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => 'https://alpha.test/reports', 'count' => 7, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => 'https://alpha.test/notes',   'count' => 9, 'last_seen' => 1700000002 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--search=alpha.test' );

		$this->assertSame( 0, $result['rows'] );
	}

	public function test_a_search_matches_the_path_and_the_row_still_displays_whole(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => 'https://alpha.test/reports', 'count' => 7, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => 'https://alpha.test/notes',   'count' => 9, 'last_seen' => 1700000002 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--search=reports' );

		$this->assertSame( 1, $result['rows'] );
		// Stored apart, joined for display: the operator still reads a URL.
		$this->assertSame( 'https://alpha.test/reports', $result['data'][0]['url'] );
	}

	public function test_urls_verb_filters_by_search_term(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/articles/123', 'count' => 1, 'sum_ms' => 50.0, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => '/home', 'count' => 2, 'sum_ms' => 100.0, 'last_seen' => 1700000002 ],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--search=articles'
		);

		$this->assertSame( 1, $result['rows'] );
		$this->assertSame( 'https://example.com/articles/123', $result['data'][0]['url'] );
	}

	/** The index files whole words, so a search finds a word and not a part of one. */
	public function test_a_search_finds_a_whole_word_and_not_a_part_of_one(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'a1b2c3d4e5f6' => [ 'url' => 'https://alpha.test/wombat-7731/ox', 'count' => 3, 'last_seen' => 1700000001 ],
		] );

		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			foreach ( [ 'wombat', 'ox', '7731', 'wombat ox' ] as $whole ) {
				$this->assertSame( 1, $fire( "--search={$whole}" )['rows'], $whole );
			}
			foreach ( [ 'wom', 'womb', 'mbat', '773' ] as $part ) {
				$this->assertSame( 0, $fire( "--search={$part}" )['rows'], $part );
			}
		} finally {
			$restore();
		}
	}

	public function test_a_search_for_wombat_finds_wombat_7731_and_wom_does_not(): void {
		$this->activate_shipped( 'performance', 1 );
		$store = $this->stats_store( 0, 86400 );
		$this->seed_url_shard( $store, $this->current_url_bucket(), Stats_Store::url_shard( 'c0ffee7731ab' ), [ 'c0ffee7731ab' => [ 'url' => 'https://example.com/wombat-7731', 'count' => 3 ] ] );
		$this->set_url_tokens( $store, [ 'c0ffee7731ab' => '/wombat-7731' ] );
		$this->forget_stats_asks();

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=wombat' ] );
		$this->assertSame( [ '/wombat-7731' ], \array_map( static fn ( array $row ): string => Stats_Store::path_of( $row['url'] ), $page['data'] ) );
		$this->assertFalse( $page['estimated'] );
		$this->assertSame( [ 'SMEMBERS' ], \array_keys( $this->asked_verbs( Stats_Store::NS_URLTOKEN ) ), 'the word\'s members name it' );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=wom' ] );
		$this->assertSame( [], $page['data'], 'a word is matched whole, never by its start' );
	}

	public function test_a_later_bucket_supplies_the_url_an_earlier_one_omitted(): void {
		$url    = 'https://okgazette.example/jobs/filmtimes/import-film-times';
		$hash   = Log_Manager::url_hash( $url );
		$store  = $this->stats_store( 0, 86400 );
		$older  = Stats_Store::bucket_key( self::tick() - 600 );
		$newer  = $this->current_url_bucket();
		// Buckets merge newest-first, so the one REACHED FIRST is the one
		// without a URL — the order that pins the row blank.
		$this->set_url_bucket( $store, $newer, [
			$hash => [ 'count' => 2, 'sum_ms' => 40.0, 'last_seen' => 2 ],
		] );
		$this->set_url_bucket( $store, $older, [
			$hash => [ 'url' => $url, 'count' => 5, 'sum_ms' => 100.0, 'last_seen' => 1 ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'dump_url',
			$hash
		);

		$this->assertSame( $url, $result['stats']['url'] );
		$this->assertSame( 7, $result['stats']['count'] );
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
	 * `dump_url` asks about ONE URL, and reads its row BY KEY: one
	 * `url_row_h` key per planned hour its server's index names, and no
	 * shard of the index, which holds every URL of the digit beside it.
	 */
	public function test_dump_url_reads_its_row_by_key(): void {
		$store = $this->stats_store( 0, 86400 );
		// Two rows sharing a shard: the row read must not carry its neighbour.
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'a4471ab0c0de' => [ 'url' => '/wombat-4471', 'count' => 31, 'sum_ms' => 992.0, 'timed_count' => 31 ],
			'a8823bc1d2ef' => [ 'url' => '/quokka-8823', 'count' => 17, 'sum_ms' => 411.0, 'timed_count' => 17 ],
		] );
		$this->forget_stats_asks();
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'a4471ab0c0de' );

		$this->assertSame( 'https://example.com/wombat-4471', $result['stats']['url'] );
		$this->assertSame( 31, $result['stats']['count'] );
		$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URLS ), 'no fine shard' );
		$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URLS_HOUR ), 'no hour shard' );
		$this->assertSame(
			[ Stats_Store::key_at( Stats_Store::url_row_parts( Stats_Store::server_key( self::SEED_SERVER ), 'a4471ab0c0de' ), Stats_Store::hour_of( $this->current_url_bucket() ) ) ],
			$this->asked_keys( Stats_Store::NS_URL_ROW_HOUR ),
			'the one hour its server holds rows in'
		);
	}

	/**
	 * An unscoped `dump_url` finds its hash's server through `urlmap` and
	 * reads that server's keys alone, as a scoped one does: a hub's other
	 * servers hold nothing for a URL whose host is not theirs.
	 */
	public function test_an_unscoped_dump_url_reads_only_the_server_its_name_locates(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'a4471ab0c0de' => [ 'url' => 'https://kea.example/wombat-4471', 'count' => 31, 'sum_ms' => 992.0, 'timed_count' => 31 ],
		], 'kea.example' );
		$this->set_url_bucket( $store, $bucket, [
			'a8823bc1d2ef' => [ 'url' => 'https://moa.example/quokka-8823', 'count' => 17, 'sum_ms' => 411.0, 'timed_count' => 17 ],
		], 'moa.example' );

		$this->forget_stats_asks();
		$scoped         = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'a4471ab0c0de --server=kea.example' );
		$scoped_keys    = $this->asked_keys( Stats_Store::NS_URL_ROW_HOUR );
		$this->forget_stats_asks();
		$unscoped       = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'a4471ab0c0de' );

		$this->assertSame( 'https://kea.example/wombat-4471', $unscoped['stats']['url'] );
		$this->assertSame( 31, $unscoped['stats']['count'] );
		$this->assertSame( $scoped['stats'], $unscoped['stats'] );
		$this->assertCount( 1, $scoped_keys );
		$this->assertSame( $scoped_keys, $this->asked_keys( Stats_Store::NS_URL_ROW_HOUR ), 'the other server\'s keys are never read' );
	}

	/**
	 * A name that has expired, or that locates a server holding no row for
	 * the hash, leaves the unscoped read to walk every server's keys.
	 */
	public function test_an_unscoped_dump_url_without_a_usable_name_reads_every_server(): void {
		$store      = $this->stats_store( 0, 86400 );
		$bucket     = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'a4471ab0c0de' => [ 'url' => 'https://kea.example/wombat-4471', 'count' => 31, 'sum_ms' => 992.0, 'timed_count' => 31 ],
		], 'kea.example' );
		$store->set_url_names( [ 'moa.example' => [ 'a4471ab0c0de' => 'https://moa.example/wombat-4471' ] ] );

		$located = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'a4471ab0c0de' );
		$store->bucket_forget_multi( [ [ [ Stats_Store::NS_URLMAP ], 'a4471ab0c0de' ] ] );
		$expired = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'a4471ab0c0de' );

		$this->assertSame( 31, $located['stats']['count'] ?? null, 'the named server holds no row' );
		$this->assertSame( 31, $expired['stats']['count'] ?? null, 'no name at all' );
		$this->assertSame( '/wombat-4471', $expired['stats']['url'], 'the row\'s own path until a name returns' );
	}

	/**
	 * A `urlmap` entry in the old `[ path, origin ]` shape is a miss, never a
	 * name: read as `[ server, path ]` it would show the bare origin.
	 */
	public function test_an_old_shape_url_name_reads_as_no_name(): void {
		$store      = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'a4471ab0c0de' => [ 'url' => '/a', 'count' => 31, 'sum_ms' => 992.0, 'timed_count' => 31 ],
		] );
		$store->bucket_forget_multi( [ [ [ Stats_Store::NS_URLMAP ], 'a4471ab0c0de' ] ] );
		$absent = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'a4471ab0c0de' );
		$store->bucket_set_multi( [ [ [ Stats_Store::NS_URLMAP ], 'a4471ab0c0de', [ '/a', 'https://example.com' ] ] ] );

		$this->assertSame( [], $store->get_url_names( [ 'a4471ab0c0de' ] ) );
		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		$this->assertSame( [ '/a' ], \array_column( $page['data'], 'url' ), 'never the bare origin' );
		$detail = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'a4471ab0c0de' );
		$this->assertSame( $absent['stats'], $detail['stats'], 'resolved as with no name at all' );
	}

	/**
	 * A modal costs its own row's keys, never the table's fan-out. The table is
	 * read in the server `urlmap` locates, the scope the modal reads.
	 *
	 * Decision 14 held the whole unscoped index for the request so a modal
	 * opened from the table answered from the read the table already paid for.
	 * That memo is what could not fit — the merged index is the count of
	 * distinct URLs in the window, and a production hub exhausted 512MB inside
	 * the fold. `load_row()` reads the one URL's row by key.
	 */
	public function test_one_row_costs_its_keys_not_the_whole_index(): void {
		$store = $this->stats_store( 0, 86400 );
		// Three shards: the table reads each, the modal one.
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'a4471ab0c0de' => [ 'url' => '/wombat-4471', 'count' => 31, 'sum_ms' => 992.0, 'timed_count' => 31 ],
			'b8823bc1d2ef' => [ 'url' => '/quokka-8823', 'count' => 17, 'sum_ms' => 411.0, 'timed_count' => 17 ],
			'c3309cd4e5f6' => [ 'url' => '/numbat-3309', 'count' => 9, 'sum_ms' => 90.0, 'timed_count' => 9 ],
		] );
		$node = new Performance_CI_Node();

		VerbHarness::fire( $node, 'performance', 'urls', '--server=' . self::SEED_SERVER );
		$table = \count( $this->asked_keys( Stats_Store::NS_URLS ) );
		$this->forget_stats_asks();
		$detail         = VerbHarness::fire( $node, 'performance', 'dump_url', 'a4471ab0c0de' );
		$modal          = \count( $this->asked_keys( Stats_Store::NS_URL_ROW_HOUR ) );

		$this->assertSame( 'https://example.com/wombat-4471', $detail['stats']['url'] );
		$this->assertSame( 3, $table );
		$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URLS ), 'a modal must not pay the table fan-out' );
		$this->assertSame( 1, $modal, 'the row is read, not remembered' );
	}

	/**
	 * The reader takes the coarse hour where one has been folded, and never
	 * the buckets of an hour that is not: that hour is the flame builder's
	 * to fold, and until it does the table is short there.
	 */
	public function test_the_index_reads_a_folded_hour_and_no_bucket_of_an_unfolded_one(): void {
		$store = $this->stats_store( 0, 86400 );
		$hash  = 'a4471ab0c0de';
		$shard = Stats_Store::url_shard( $hash );
		$plan  = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) );
		// The hour before the last is folded; the last never was.
		$this->seed_url_hour( $store, $plan['hours'][1], $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 70.0 ],
		] );
		$unfolded = Stats_Store::buckets_in_hour( $plan['hours'][0] );
		$this->seed_url_shard( $store, (string) \end( $unfolded ), $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 50.0 ],
		] );

		$row = Performance_CI_Node::load_row( $hash, '', $this->live_stores(), (int) Core::$now );

		$this->assertNotNull( $row );
		$this->assertSame( 7, $row['count'], 'the folded hour alone' );
	}

	/**
	 * And a folded hour must not be counted TWICE — once coarse, once from the
	 * fine buckets it was folded from. Those buckets outlive the fold.
	 */
	public function test_a_folded_hour_is_not_counted_again_from_its_fine_buckets(): void {
		$store = $this->stats_store( 0, 86400 );
		$hash  = 'a4471ab0c0de';
		$shard = Stats_Store::url_shard( $hash );
		$hour  = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) )['hours'][0];
		$this->seed_url_shard( $store, Stats_Store::buckets_in_hour( $hour )[2], $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 50.0 ],
		] );
		$this->seed_url_hour( $store, $hour, $shard, [
			$hash => [ 'url' => '/wombat-4471', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 50.0 ],
		] );

		$row = Performance_CI_Node::load_row( $hash, '', $this->live_stores(), (int) Core::$now );

		$this->assertSame( 5, $row['count'], 'the fold replaces its buckets, it does not add to them' );
	}

	/**
	 * The whole point, as a number: with every closed hour folded, a read of
	 * the URL index costs `fine + hours` keys per server per shard — between
	 * 36 and 47 depending on where the clock sits in the hour, against the
	 * 288 five-minute buckets it used to enumerate — plus the server index,
	 * read once per reply rather than once per shard.
	 *
	 * The hours name a server and hold no header record, so the header
	 * reads one record an hour, finds the hole, and `urls`' default page
	 * folds — this same per-shard walk — reading no ranked list at all.
	 */
	public function test_a_folded_window_reads_two_tiers_not_every_bucket(): void {
		$plan  = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) );
		$store = $this->stats_store( 0, 86400 );
		foreach ( $plan['hours'] as $hour ) {
			foreach ( Stats_Store::url_shards() as $shard ) {
				$this->seed_url_hour( $store, $hour, $shard, [] );
			}
			self::mark_done( $store, $hour );
		}

		$this->forget_stats_asks();
		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$per_shard = \count( $plan['fine'] ) + \count( $plan['hours'] );
		$this->assertLessThan( 48, $per_shard, 'two tiers, not 288 buckets' );
		// Only the hours name a server, so only they hold row keys to read.
		$this->assertSame(
			\count( $plan['hours'] ) * ( Stats_Store::URL_SHARDS + 2 ) + $per_shard,
			\count( $this->asked_keys() ),
			'a folded window reads no fine bucket behind the recent tail, no list, a record and a marker an hour, and each index once'
		);
	}

	public function test_dump_url_returns_recent_matching_requests(): void {
		// dump_url's `requests` slice walks requests.log for entries whose
		// url_hash matches. Seed the URL in the memcache index AND two on-disk
		// requests so the collect + dedup walk runs (not the empty-result skip).
		// Timestamps ride the clock: the walk stops at the retention floor, so a
		// fixed epoch would put the whole fixture behind it.
		$url    = '/recent-list';
		$hash   = Log_Manager::url_hash( $url );
		$now    = self::tick();
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			$hash => [ 'url' => $url, 'count' => 2, 'sum_ms' => 32.0, 'last_seen' => $now - 623 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-recent-a-1234567890123456',
			'url'            => $url,
			'timestamp'      => $now - 1817,
			'duration_ms'    => 12,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );
		$this->write_request( [
			'rid'            => 'rid-recent-b-1234567890123456',
			'url'            => $url,
			'timestamp'      => $now - 623,
			'duration_ms'    => 20,
			'status_code'    => 500,
			'peak_mb'        => 3,
			'request_method' => 'POST',
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'dump_url', $hash );

		$this->assertCount( 2, $result['requests'] );
		// Sorted by timestamp DESC → the newest (b) leads.
		$this->assertSame( 'rid-recent-b-1234567890123456', $result['requests'][0]['rid'] );
	}

	/**
	 * An `--after` value holding each row's own position, the newest per
	 * partition: a caller that has seen those rows and nothing past them.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows a reply carried.
	 * @return string The `--after` token's JSON value.
	 */
	private static function after_rows( array $rows ): string {
		$after = [];
		foreach ( $rows as $row ) {
			$at   = [ 'segment' => $row['segment'], 'offset' => $row['offset'] ];
			$held = $after[ $row['partition'] ] ?? null;
			if ( null === $held || [ $at['segment'], $at['offset'] ] > [ $held['segment'], $held['offset'] ] ) {
				$after[ $row['partition'] ] = $at;
			}
		}
		return (string) \json_encode( (object) $after );
	}

	/**
	 * The `--after` value a caller sends back from a reply: its `positions`,
	 * which reach this harness as a PHP array however the wire spelled them.
	 *
	 * @param array<string,mixed> $reply A `dump_url` reply.
	 */
	private static function after_reply( array $reply ): string {
		return (string) \json_encode( (object) (array) $reply['positions'] );
	}

	/**
	 * The position of a partition's newest request index line: where a walk
	 * of the whole partition begins.
	 *
	 * @return array{segment:int,offset:int}
	 */
	private function index_head( int $partition ): array {
		$lines = \file( $this->tmp . "/logs/requests.p{$partition}/0.idx", FILE_IGNORE_NEW_LINES );
		$entry = Request_Builder_Node::parse_request_index( (string) \end( $lines ) );
		return [ 'segment' => $entry['segment'], 'offset' => $entry['offset'] ];
	}

	/**
	 * Pin the walk's clock so its first reading inside a walk, at the
	 * SCAN_CLOCK_STRIDE'th index line, is past the deadline: a walk that reads
	 * that many lines comes back `scan_stopped_early`.
	 */
	private static function spend_the_budget_at_the_first_stride(): void {
		$seconds      = 1790757000.0;
		Core::$clock = static function () use ( &$seconds ): float {
			return $seconds += 23.0;
		};
	}

	/** Seed the URL's row, so `dump_url` finds the URL it lists. */
	private function seed_listed_url( string $url, int $count ): string {
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => $count, 'timed_count' => $count, 'sum_ms' => 37.0 * $count, 'last_seen' => self::tick() - 1 ],
		] );
		return $hash;
	}

	/**
	 * One URL at one request a second over four partitions, request `i` on
	 * partition `i % 4`, the newest on p3. Partition 0 alone holds 600, more
	 * than one reply lists, so a walk capped across the fan-out never opens p1.
	 */
	public function test_a_four_partition_full_read_lists_the_newest_across_every_partition(): void {
		$this->activate_shipped( 'performance', 4 );
		$hash = $this->seed_listed_url( '/spread-over-four', 2400 );
		$now  = self::tick();
		for ( $i = 0; $i < 2400; $i++ ) {
			$this->write_request( [
				'rid'            => \sprintf( 'rid-spread-%021d', $i ),
				'url'            => '/spread-over-four',
				'timestamp'      => $now - 2400 + $i,
				'duration_ms'    => 37,
				'status_code'    => 200,
				'peak_mb'        => 3,
				'request_method' => 'GET',
			], $i % 4 );
		}

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$rids = \array_column( $result['requests'], 'rid' );
		$this->assertSame(
			\array_map( static fn ( int $i ): string => \sprintf( 'rid-spread-%021d', $i ), \range( 2399, 1900 ) ),
			$rids,
			'the newest 500 across the four partitions, newest first'
		);
		$by_partition = \array_count_values( \array_column( $result['requests'], 'partition' ) );
		\ksort( $by_partition );
		$this->assertSame( [ 0 => 125, 1 => 125, 2 => 125, 3 => 125 ], $by_partition );
		$this->assertTrue( $result['scan_stopped_early'], 'a capped partition leaves its older requests unlisted' );
		foreach ( [ 0, 1, 2, 3 ] as $p ) {
			$this->assertSame( $this->index_head( $p ), ( (array) $result['positions'] )[ $p ], "a capped partition's position is its newest entry, p{$p}" );
		}

		$tail = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, '--after=' . self::after_reply( $result ) ] );

		$this->assertIsArray( $tail, \is_string( $tail ) ? $tail : '' );
		$this->assertSame( [], $tail['requests'], 'a capped partition\'s older entries are not fetched again' );
		$this->assertFalse( $tail['scan_stopped_early'] );
	}

	/**
	 * A partition holding nothing for the URL still reports the position it
	 * read to, so the next refresh stops at its first line rather than
	 * walking it to the window floor. p2 holds 150 lines of another URL, more
	 * than one clock stride, so a walk through them spends the pinned budget.
	 */
	public function test_a_refresh_reads_no_index_of_a_partition_holding_nothing_for_the_url(): void {
		$this->activate_shipped( 'performance', 4 );
		$url  = '/three-of-four';
		$hash = $this->seed_listed_url( $url, 4 );
		$now  = self::tick();
		foreach ( [ [ 'rid-p0-older-0000000000000871', 71, 0 ], [ 'rid-p0-newer-0000000000000872', 29, 0 ], [ 'rid-p1-only-00000000000000873', 43, 1 ], [ 'rid-p3-only-00000000000000874', 17, 3 ] ] as [ $rid, $ago, $partition ] ) {
			$this->write_request( [ 'rid' => $rid, 'url' => $url, 'timestamp' => $now - $ago, 'duration_ms' => 19, 'status_code' => 200, 'peak_mb' => 2, 'request_method' => 'GET' ], $partition );
		}
		for ( $i = 0; $i < 150; $i++ ) {
			$this->write_request( [ 'rid' => \sprintf( 'rid-elsewhere-%018d', $i ), 'url' => '/elsewhere-on-p2', 'timestamp' => $now - 600 + $i, 'duration_ms' => 23, 'status_code' => 200, 'peak_mb' => 2, 'request_method' => 'GET' ], 2 );
		}
		$opened    = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );
		$positions = (array) $opened['positions'];
		$this->assertSame( $this->index_head( 2 ), $positions[2], 'a partition with no rows still reports where it read to' );

		self::spend_the_budget_at_the_first_stride();
		$tail = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, '--after=' . self::after_reply( $opened ) ] );

		$this->assertFalse( $tail['scan_stopped_early'], "p2's index was walked" );
		$this->assertSame( [], $tail['requests'] );
		$this->assertEquals( $positions, (array) $tail['positions'], 'a partition read to its position stays there' );

		// The control: without p2's position, the same refresh walks p2's lines.
		unset( $positions[2] );
		$blind = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, '--after=' . \json_encode( (object) $positions ) ] );
		$this->assertTrue( $blind['scan_stopped_early'] );
		$this->assertSame( [ 0, 1 ], \array_keys( (array) $blind['positions'] ), 'a partition the budget cut, and one never reached, keep the caller\'s position' );
	}

	/**
	 * A partition that indexes late: p1's request finished before the newest
	 * row the browser held, but reached p1's index only after the first read.
	 * A time watermark stops p1 on it for good; a position does not.
	 */
	public function test_a_tail_returns_a_request_its_partition_indexed_late(): void {
		$this->activate_shipped( 'performance', 4 );
		$url  = '/indexed-late';
		$hash = $this->seed_listed_url( $url, 5 );
		$now  = self::tick();
		$seed = function ( string $rid, int $ago, int $ms, int $partition ) use ( $url, $now ): void {
			$this->write_request( [ 'rid' => $rid, 'url' => $url, 'timestamp' => $now - $ago, 'duration_ms' => $ms, 'status_code' => 200, 'peak_mb' => 2, 'request_method' => 'GET' ], $partition );
		};
		$seed( 'rid-late-p1-held-000000001', 31, 14, 1 );
		$seed( 'rid-late-p0-older-00000001', 23, 11, 0 );
		$seed( 'rid-late-p0-newer-00000001', 9, 11, 0 );
		$opened = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );
		$this->assertCount( 3, $opened['requests'] );

		$seed( 'rid-late-p1-late-000000001', 13, 300, 1 );
		$seed( 'rid-late-p2-new-0000000001', 5, 11, 2 );
		$tail = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, '--after=' . self::after_reply( $opened ) ] );

		$this->assertSame(
			[ 'rid-late-p2-new-0000000001', 'rid-late-p1-late-000000001' ],
			\array_column( $tail['requests'], 'rid' ),
			'each partition past its own position, and nothing the browser holds'
		);
		$this->assertFalse( $tail['scan_stopped_early'] );
	}

	public function test_a_cursor_that_is_not_partition_positions_is_refused(): void {
		$hash = $this->seed_listed_url( '/bad-cursor', 1 );

		foreach ( [ '[1,2]', '{"0":{"segment":3}}', '{"x":{"segment":3,"offset":7}}', '{"0":{"segment":"3","offset":7}}', 'not json' ] as $after ) {
			$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, "--after={$after}" ] );
			$this->assertIsString( $result, $after );
			$this->assertStringContainsString( 'after wants partition positions', $result, $after );
		}
	}

	public function test_dump_url_reports_a_scan_that_stopped_before_reaching_the_url(): void {
		// A low-traffic URL among high-traffic neighbours: the index walk spends
		// its whole time budget on the newer lines and never reaches the one
		// matching entry. An empty list then says "no requests", which is a lie
		// — the truth is that the scan stopped, and the payload has to say so.
		$url    = '/buried-under-neighbours';
		$hash   = Log_Manager::url_hash( $url );
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 37.0, 'last_seen' => self::tick() - 2311 ],
		] );
		// Inside the window, so the BUDGET is the only thing that can stop the walk.
		$this->write_request( [
			'rid'            => 'rid-buried-1234567890123456789',
			'url'            => $url,
			'timestamp'      => self::tick() - 2311,
			'duration_ms'    => 37,
			'status_code'    => 418,
			'peak_mb'        => 5,
			'request_method' => 'GET',
		] );
		// Newer than that entry, and more than the clock allows. Short lines:
		// a line under the fixed width parses as no entry.
		$this->run_out_the_scan_clock_over_newer_lines();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertSame( [], $result['requests'], 'the budget ran out before the matching entry' );
		$this->assertTrue( $result['scan_stopped_early'], 'a stopped scan is not an empty result' );
	}

	public function test_dump_url_calls_a_capped_request_list_short_of_its_window(): void {
		// One past the cap: the list stops short of `requests_window_start`,
		// and the reply says so rather than claiming the whole window.
		$url   = '/at-the-request-cap';
		$hash  = Log_Manager::url_hash( $url );
		$limit = (int) ( new \ReflectionClassConstant( Performance_CI_Node::class, 'RECENT_REQUEST_LIMIT' ) )->getValue();
		$now   = self::tick();
		$store = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => $limit + 1, 'sum_ms' => 64.0, 'last_seen' => $now - 907 ],
		] );
		for ( $i = 0; $i <= $limit; $i++ ) {
			$this->write_request( [
				'rid'            => \sprintf( 'rid-cap-%024d', $i ),
				'url'            => $url,
				'timestamp'      => $now - 1408 + $i,
				'duration_ms'    => 64,
				'status_code'    => 203,
				'peak_mb'        => 7,
				'request_method' => 'GET',
			] );
		}

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertCount( $limit, $result['requests'] );
		$this->assertTrue( $result['scan_stopped_early'], 'a capped partition leaves its oldest request unlisted' );
		// And it keeps the NEWEST end. Walking forward from the oldest, the cap
		// fires on the oldest matches, so the panel would show the start of a
		// busy URL's history sorted descending to look convincing.
		$rids = \array_column( $result['requests'], 'rid' );
		$this->assertContains( \sprintf( 'rid-cap-%024d', $limit ), $rids, 'the newest request is missing' );
		$this->assertNotContains( \sprintf( 'rid-cap-%024d', 0 ), $rids, 'the oldest should have fallen off' );
	}

	// -------------------------------------------------------------------------
	// Retention edge: the walk stops where an answer could no longer be.
	//
	// An index line is appended at its request's COMPLETION, so completion is
	// what the stop compares — `timestamp` is the START, and a long request
	// carries one from far outside the window. The line alone cannot end the
	// walk either: on a hub, append order is the spokes' arrival order, so the
	// stop also needs the segment's index to have gone untouched since the
	// window opened, which is the one clock the reader owns.
	// -------------------------------------------------------------------------

	/** A retention window narrow enough to place seeded entries either side of it. */
	private const SCAN_RETENTION = 7200;

	/** Backdate a seeded segment's index, the way a segment closed hours ago reads. */
	private function close_segment_index( int $seconds_ago, int $partition = 0, int $segment = 0 ): void {
		$path = $this->tmp . "/logs/requests.p{$partition}/{$segment}.idx";
		\touch( $path, self::tick() - $seconds_ago );
		\clearstatcache( true, $path );
	}

	public function test_dump_url_names_the_window_its_request_list_was_drawn_from(): void {
		// The list stops at the window, so an empty one is only empty OF that
		// window — a reply that does not say which reads as the site's whole
		// record. The number is the walk's own floor, not a rounded hour.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = self::tick();
		$url  = '/named-window-6205';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( $this->stats_store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 47.0, 'last_seen' => $now - 62 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertSame(
			Stats_Store::window_start( self::SCAN_RETENTION, $now ),
			$result['requests_window_start'],
			'the reply has to name the window its list is of'
		);
	}

	public function test_a_long_running_request_does_not_end_the_url_walk(): void {
		// A request logging every few minutes stays in flight indefinitely and
		// appends at completion, so its START can precede the window by hours.
		// Completion is what the window asks about, and this one completed
		// inside it — the matching entry behind it is still reachable.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = self::tick();
		$url  = '/behind-a-long-runner-5182';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( $this->stats_store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 58.0, 'last_seen' => $now - 211 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-inside-the-window-771300000',
			'url'            => $url,
			'timestamp'      => $now - 211,
			'duration_ms'    => 58,
			'status_code'    => 206,
			'peak_mb'        => 9,
			'request_method' => 'GET',
		] );
		// Started 3h ago, ran for 2h46m — the shape of a cron job, and far
		// longer than any bucket rotation would have held it in flight.
		$this->write_request( [
			'rid'            => 'rid-the-long-runner-883100000000',
			'url'            => '/a-long-running-job-6624',
			'timestamp'      => $now - 10800,
			'duration_ms'    => 10000000,
			'status_code'    => 201,
			'peak_mb'        => 4,
			'request_method' => 'GET',
		] );
		$this->close_segment_index( 60 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertCount( 1, $result['requests'], 'a start time outside the window is not an ending' );
		$this->assertSame( 'rid-inside-the-window-771300000', $result['requests'][0]['rid'] );
	}

	public function test_the_url_walk_stops_at_a_closed_segment_whose_newest_line_completed_first(): void {
		// Nothing has been appended to this segment since the window opened,
		// and its newest line completed before it — so every line behind it
		// completed earlier still, and the walk ends rather than reading them.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = self::tick();
		$url  = '/behind-the-retention-edge-8813';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( $this->stats_store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 71.0, 'last_seen' => $now - 137 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-past-the-edge-55190000000000',
			'url'            => $url,
			'timestamp'      => $now - 137,
			'duration_ms'    => 71,
			'status_code'    => 207,
			'peak_mb'        => 11,
			'request_method' => 'GET',
		] );
		// Appended after it, and finished well before the window opened.
		$this->write_request( [
			'rid'            => 'rid-the-edge-marker-661900000000',
			'url'            => '/an-unrelated-neighbour-2277',
			'timestamp'      => $now - 9413,
			'duration_ms'    => 12,
			'status_code'    => 204,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );
		$this->close_segment_index( 8000 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertSame( [], $result['requests'], 'the walk read past the retention edge' );
		$this->assertFalse( $result['scan_stopped_early'], 'the retention edge is not a spent budget' );
	}

	public function test_an_out_of_window_line_in_a_live_segment_does_not_end_the_url_walk(): void {
		// The hub's route: every spoke's requests land in ONE partition in
		// ARRIVAL order, so a spoke reconnecting after a lag replays hours-old
		// lines between live ones. A segment still being appended to can hold
		// an in-window row behind any of them.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = self::tick();
		$url  = '/behind-a-replayed-spoke-4409';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( $this->stats_store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 33.0, 'last_seen' => $now - 96 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-live-traffic-4471000000000',
			'url'            => $url,
			'timestamp'      => $now - 96,
			'duration_ms'    => 33,
			'status_code'    => 208,
			'peak_mb'        => 3,
			'request_method' => 'GET',
		] );
		$this->write_request( [
			'rid'            => 'rid-the-replayed-line-2960000000',
			'url'            => '/a-lagging-spoke-3318',
			'timestamp'      => $now - 21600,
			'duration_ms'    => 17,
			'status_code'    => 204,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertCount( 1, $result['requests'], 'arrival order is no ordering of completions' );
		$this->assertSame( 'rid-live-traffic-4471000000000', $result['requests'][0]['rid'] );
	}

	public function test_a_line_carrying_no_readable_time_does_not_end_the_url_walk(): void {
		// A zero or non-numeric time column casts to 0, older than every floor.
		// Only a line that parses as a completion may end a walk — otherwise one
		// unreadable line ends a whole partition, silently and totally.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = self::tick();
		$url  = '/behind-an-unreadable-line-9047';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( $this->stats_store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 84.0, 'last_seen' => $now - 319 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-behind-the-junk-line-99310',
			'url'            => $url,
			'timestamp'      => $now - 319,
			'duration_ms'    => 84,
			'status_code'    => 205,
			'peak_mb'        => 13,
			'request_method' => 'GET',
		] );
		// Full width, so the length check passes and the time column reads 0.
		\file_put_contents(
			$this->tmp . '/logs/requests.p0/0.idx',
			\str_repeat( '0', 97 ) . "\n",
			FILE_APPEND | LOCK_EX
		);
		$this->close_segment_index( 8000 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertCount( 1, $result['requests'], 'an unreadable time is no completion' );
	}

	public function test_a_line_too_short_to_carry_a_time_does_not_end_the_url_walk(): void {
		// The floor slices the raw column in place, and a slice off the end of a
		// short line reads as 0 — the oldest time there is. One malformed line
		// must not truncate the walk behind it; only the budget bounds junk.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = self::tick();
		$url  = '/behind-a-short-line-9047';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( $this->stats_store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 84.0, 'last_seen' => $now - 319 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-behind-the-short-line-99310',
			'url'            => $url,
			'timestamp'      => $now - 319,
			'duration_ms'    => 84,
			'status_code'    => 205,
			'peak_mb'        => 13,
			'request_method' => 'GET',
		] );
		\file_put_contents( $this->tmp . '/logs/requests.p0/0.idx', "zz\n", FILE_APPEND | LOCK_EX );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertCount( 1, $result['requests'], 'a malformed line is no retention edge' );
	}

	public function test_the_url_walk_returns_every_entry_inside_the_window(): void {
		// Nothing reaches the edge, so the bound is invisible.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = self::tick();
		$url  = '/wholly-inside-the-window-3352';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( $this->stats_store( 0, self::SCAN_RETENTION ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 3, 'sum_ms' => 96.0, 'last_seen' => $now - 43 ],
		] );
		foreach ( [ 6011, 2903, 43 ] as $i => $ago ) {
			$this->write_request( [
				'rid'            => \sprintf( 'rid-inside-%020d', $i ),
				'url'            => $url,
				'timestamp'      => $now - $ago,
				'duration_ms'    => 32,
				'status_code'    => 202,
				'peak_mb'        => 6,
				'request_method' => 'GET',
			] );
		}

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertCount( 3, $result['requests'] );
		$this->assertFalse( $result['scan_stopped_early'] );
	}

	public function test_the_url_scan_refuses_a_line_that_only_carries_the_matched_column(): void {
		// The walk compares the raw url_hash column before parsing. A line that
		// carries the column but is too short to be an index entry is not a
		// request, and the pre-filter must not turn it into one.
		$url  = '/column-lookalike-4417';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 19.0, 'last_seen' => 1700005000 ],
		] );
		$dir = $this->tmp . '/logs/requests.p0';
		if ( ! \is_dir( $dir ) ) {
			\mkdir( $dir, 0755, true );
		}
		\file_put_contents(
			"{$dir}/0.idx",
			\str_pad( 'rid-imposter-9931', 32 ) . \str_pad( $hash, 12 ) . "\n",
			FILE_APPEND | LOCK_EX
		);

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertSame( [], $result['requests'], 'a short line is no entry, whatever its first 44 bytes read' );
		$this->assertFalse( $result['scan_stopped_early'] );
	}

	public function test_the_flame_scan_matches_the_rid_column_and_only_that_rid(): void {
		// Same pre-filter, the other index format: its own writer owns the
		// offsets, so a flame belonging to a neighbouring rid stays unmatched.
		$rid = $this->write_request( [
			'rid'         => 'rid-flame-column-773311',
			'url'         => '/flame-column',
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
	}

	public function test_search_requests_names_a_spent_budget_rather_than_a_missing_rid(): void {
		// An incomplete search reported as a definite negative sends an
		// operator after a retention bug that does not exist.
		$this->fill_request_index_past_the_budget();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', 'rid-never-reached-6f21' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'budget spent', \strtolower( $result ) );
	}

	public function test_dump_request_names_a_spent_budget_rather_than_a_missing_rid(): void {
		$this->fill_request_index_past_the_budget();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-never-reached-8c04' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'budget spent', \strtolower( $result ) );
	}

	/**
	 * Bury p0's request index under more lines than the scan's clock allows.
	 */
	private function fill_request_index_past_the_budget(): void {
		// One real record first: a segment with no `.log` is no segment.
		$this->write_request( [
			'rid'         => 'rid-buried-under-the-budget',
			'url'         => '/buried-under-the-budget',
			'timestamp'   => 1700005200,
			'duration_ms' => 15,
		] );
		$this->run_out_the_scan_clock_over_newer_lines();
	}

	/**
	 * Advance the scan's clock one second per reading and append more lines to
	 * p0's request index than MAX_SCAN_S of those readings cover, at one
	 * reading per SCAN_CLOCK_STRIDE lines.
	 */
	private function run_out_the_scan_clock_over_newer_lines(): void {
		$seconds      = 0.0;
		Core::$clock = static function () use ( &$seconds ): float {
			return $seconds += 1.0;
		};
		\file_put_contents(
			$this->tmp . '/logs/requests.p0/0.idx',
			\str_repeat( "x\n", ( Performance_CI_Node::MAX_SCAN_S + 3 ) * self::clock_stride() ),
			FILE_APPEND | LOCK_EX
		);
	}

	/** Lines the index walk reads between two looks at the clock. */
	private static function clock_stride(): int {
		return (int) ( new \ReflectionClassConstant( Performance_CI_Node::class, 'SCAN_CLOCK_STRIDE' ) )->getValue();
	}

	public function test_dump_request_spends_one_time_budget_across_both_walks(): void {
		// An unprofiled rid's flame lookup misses over the whole flame index;
		// it must share the request walk's deadline, not start a second one.
		$this->write_request( [
			'rid'         => 'rid-unprofiled-73920184665021',
			'url'         => '/no-flame-here',
			'timestamp'   => 1700006300,
			'duration_ms' => 23,
		] );
		$this->write_flame( [
			'rid'   => 'rid-some-other-profiled-one-5530',
			'flame' => [ 'name' => 'other', 'value' => 9, 'children' => [] ],
		] );
		\file_put_contents(
			$this->tmp . '/logs/flames.p0/0.idx',
			\str_repeat( "x\n", ( Performance_CI_Node::MAX_SCAN_S + 3 ) * self::clock_stride() ),
			FILE_APPEND | LOCK_EX
		);
		$reads        = 0;
		Core::$clock = static function () use ( &$reads ): float {
			return (float) ++$reads;
		};

		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-unprofiled-73920184665021' );

		$this->assertSame( Performance_CI_Node::MAX_SCAN_S + 2, $reads );
	}

	public function test_dump_url_walks_any_number_of_lines_inside_the_time_cap(): void {
		// The cap is time, not lines: a stopped clock reads the whole window.
		$url  = '/under-a-deep-index';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'sum_ms' => 29.0, 'last_seen' => self::tick() - 1777 ],
		] );
		$this->write_request( [
			'rid'            => 'rid-deep-index-58213409761234',
			'url'            => $url,
			'timestamp'      => self::tick() - 1777,
			'duration_ms'    => 29,
			'status_code'    => 203,
			'peak_mb'        => 6,
			'request_method' => 'GET',
		] );
		$reads       = 0;
		Core::$clock = static function () use ( &$reads ): float {
			++$reads;
			return 4242.0;
		};
		// The stats Tables read the clock too, once a statement, alike both times.
		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );
		$shallow = $reads;
		\file_put_contents( $this->tmp . '/logs/requests.p0/0.idx', \str_repeat( "x\n", 5000 ), FILE_APPEND | LOCK_EX );
		$reads = 0;

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertSame( 'rid-deep-index-58213409761234', $result['requests'][0]['rid'] ?? null );
		$this->assertFalse( $result['scan_stopped_early'] );
		// One reading per stride of lines, not one per line.
		$this->assertLessThanOrEqual( 1 + \intdiv( 5000, self::clock_stride() ), $reads - $shallow );
	}

	public function test_urls_verb_scopes_rows_to_the_selected_server(): void {
		// Each server's rows are its own keys, so a scoped table reads that
		// server's keys: only URLs it served survive, with its own counts.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/reviews/941', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 260.0, 'sum_peak_mb' => 8.0, 'last_seen' => 1700000003 ],
		], 'alpha.example' );
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/reviews/941', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 640.0, 'sum_peak_mb' => 21.0, 'last_seen' => 1700000003 ],
			'dddddddddddd' => [ 'url' => '/events/88', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 122.0, 'sum_peak_mb' => 12.0, 'last_seen' => 1700000004 ],
		], 'beta.example' );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--server=alpha.example'
		);

		$this->assertSame( 1, $result['totals']['urls'] );
		// One name per hash: the server filed last names it for both.
		$this->assertSame( 'https://beta.example/reviews/941', $result['data'][0]['url'] );
		$this->assertSame( 2, $result['data'][0]['count'] );
		$this->assertEqualsWithDelta( 130.0, $result['data'][0]['avg_ms'], 1e-6 );
	}

	public function test_urls_verb_scopes_the_status_counts_too(): void {
		// Scoping `count` alone would leave `count_2xx..5xx` describing every
		// server, so a scoped row could report more classified requests than it
		// had — and `errors_only`, which is `count` minus those four, would read
		// negative and hide the row. A server's key carries all of its row.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/mixed', 'count' => 2, 'timed_count' => 2, 'count_2xx' => 1, 'count_5xx' => 1, 'sum_ms' => 260.0, 'last_seen' => 1700000003 ],
		], 'alpha.example' );
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/mixed', 'count' => 7, 'count_2xx' => 5, 'count_5xx' => 2, 'sum_ms' => 640.0, 'last_seen' => 1700000003 ],
		], 'beta.example' );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--server=alpha.example'
		);

		$this->assertSame( 2, $result['data'][0]['count'] );
		$this->assertSame( 1, $result['data'][0]['count_2xx'] );
		$this->assertSame( 1, $result['data'][0]['count_5xx'] );
	}

	public function test_urls_verb_totals_answer_for_the_filtered_set(): void {
		// The Overview header renders these numbers, so they must describe the
		// set the table lists — under the server AND search filters both.
		// Reading a global total beside a filtered table is what put
		// `0 Unique URLs` next to 33,049 requests.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/reviews/941', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 260.0, 'sum_peak_mb' => 9.0, 'last_seen' => 1700000003 ],
			'dddddddddddd' => [ 'url' => '/reviews/88', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 90.0, 'sum_peak_mb' => 7.5, 'last_seen' => 1700000004 ],
			'eeeeeeeeeeee' => [ 'url' => '/events/7', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 500.0, 'sum_peak_mb' => 20.0, 'last_seen' => 1700000005 ],
		], 'alpha.example' );
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/reviews/941', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 640.0, 'sum_peak_mb' => 27.0, 'last_seen' => 1700000003 ],
			'dddddddddddd' => [ 'url' => '/reviews/88', 'count' => 1, 'timed_count' => 1, 'sum_ms' => 32.0, 'sum_peak_mb' => 4.5, 'last_seen' => 1700000004 ],
		], 'beta.example' );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--server=alpha.example --search=/reviews/'
		);

		$this->assertSame( 2, $result['totals']['urls'] );
		$this->assertSame( 5, $result['totals']['requests'] );
		$this->assertEqualsWithDelta( 70.0, $result['totals']['avg_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 3.3, $result['totals']['avg_peak_mb'], 1e-6 );
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

	/**
	 * The brief's category rows come off `sums_to_display()`, so they are read
	 * with the keys that producer emits. Seeded 44.4ms over 37 requests and 74
	 * calls, the board reads 1.2ms and 2 calls a request.
	 */
	public function test_ask_overview_reads_the_board_the_producer_writes(): void {
		$this->set_leaderboard_hour(
			$this->stats_store( 0, 86400 ),
			Stats_Store::hour_of( $this->current_url_bucket() ),
			[
				'count'        => 37,
				'sum_req_time' => 3.7,
				'categories'   => [
					'wpdb' => [ 'samples' => 37, 'sum_time' => 44.4, 'sum_count' => 74 ],
				],
			],
			'alpha.example'
		);

		$brief = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			'overview:site --server=alpha.example'
		);

		$this->assertSame( 'wpdb', $brief['categories'][0]['name'] );
		$this->assertEqualsWithDelta( 1.2, $brief['categories'][0]['avg_time_ms'], 0.001 );
		$this->assertEqualsWithDelta( 2.0, $brief['categories'][0]['avg_count'], 0.001 );
	}

	public function test_ask_accepts_the_context_it_declares_as_an_option(): void {
		// The verb declares `context`, so a caller writing `--context=` is
		// following the schema. Reading context only from the positionals meant
		// that caller got "missing context" for doing exactly what it said.
		$rid  = 'ctxrid0000000000';
		$this->write_request( [
			'rid' => $rid, 'url' => 'https://example.test/x', 'duration_ms' => 10.0,
			'entries' => [ [ 'k' => 'span', 'n' => 'wp_loaded', 'd' => 5.0 ] ],
			'profiles' => [ 'wpdb' => [ 'time' => 0.004, 'count' => 2, 'entries' => [] ] ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			"category:wpdb --context=request:{$rid}:0"
		);

		// 'request', not 'recent window': only the context path reaches the
		// per-request board, so this distinguishes the two.
		$this->assertSame( 'request', $result['scope'] );
	}

	public function test_ask_category_refuses_a_callback_row_before_reading_any_board(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:hooks @10', 'request:nosuchrid:0' ] );
		$this->assertIsString( $result );
		$this->assertStringContainsString( "'hooks @10' is a callback row", $result );
	}

	public function test_ask_category_brief_honours_the_server_scope(): void {
		// The Time Breakdown an operator clicks Ask from renders
		// `build_leaderboard( $server )`, so the brief behind it has to read the
		// same leaderboard. Quoting the whole site's category time under a
		// surface stamped with one server's name is the defect this fixed for
		// `url:` — it has to hold for every descriptor that reads a scoped set.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_leaderboard_hour( $store, Stats_Store::hour_of( $bucket ), [
			'count'        => 4,
			'sum_req_time' => 1.0,
			'categories'   => [ 'wpdb' => [ 'samples' => 4, 'sum_time' => 4.0, 'sum_count' => 8, 'entries' => [] ] ],
		], 'alpha.example' );
		$this->set_leaderboard_hour( $store, Stats_Store::hour_of( $bucket ), [
			'count'        => 40,
			'sum_req_time' => 10.0,
			'categories'   => [ 'wpdb' => [ 'samples' => 40, 'sum_time' => 400.0, 'sum_count' => 80, 'entries' => [] ] ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			'category:wpdb --server=alpha.example'
		);

		$this->assertEqualsWithDelta( 1.0, $result['avg_time_ms'], 1e-6 );
		$this->assertSame( 'recent window on alpha.example', $result['scope'] );
	}

	public function test_ask_url_brief_honours_the_server_scope(): void {
		// `pageFacts` stamps the active filters onto every surface it emits, so
		// a brief that answered site-wide would hand an agent unscoped numbers
		// labelled as one server's — a worse failure than not scoping at all,
		// because the label makes it quotable.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/asked', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 500.0, 'last_seen' => 1700000003 ],
		], 'alpha.example' );
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/asked', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 1300.0, 'last_seen' => 1700000003 ],
		], 'beta.example' );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			'url:cccccccccccc --server=alpha.example'
		);

		$this->assertSame( 2, $result['stats']['count'] );
		$this->assertEqualsWithDelta( 250.0, $result['stats']['avg_ms'], 1e-6 );
	}

	public function test_ask_url_brief_carries_the_measured_average(): void {
		// The brief is the number an agent quotes, so it reads a DISPLAY row —
		// the loader emits sums and leaves the means to the projection, and a
		// reader that takes the loader's output raw reports a confident 0.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/asked', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 1000.0, 'last_seen' => 1700000003 ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'ask',
			'url:cccccccccccc'
		);

		$this->assertEqualsWithDelta( 250.0, $result['stats']['avg_ms'], 1e-6 );
	}

	public function test_dump_url_scopes_to_the_selected_server(): void {
		// The row that opens this modal is the selected server's; the modal has
		// to answer for the same server, or one click turns a scoped table into
		// a site-wide average under the same URL and the same instant, on two
		// surfaces too far apart to notice the disagreement.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/mixed', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 260.0, 'last_seen' => 1700000003 ],
		], 'alpha.example' );
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/mixed', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 640.0, 'last_seen' => 1700000003 ],
		], 'beta.example' );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'dump_url',
			'cccccccccccc --server=alpha.example'
		);

		$this->assertSame( 2, $result['stats']['count'] );
		$this->assertEqualsWithDelta( 130.0, $result['stats']['avg_ms'], 1e-6 );
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
			'--server=alpha.example --search=/reviews/ --errors_only'
		);

		$this->assertSame(
			[
				'server'          => 'alpha.example',
				'search'          => '/reviews/',
				'errors_only'     => true,
				'include_workers' => false,
			],
			$result['filters']
		);
	}

	public function test_urls_verb_leaves_worker_traffic_out_by_default(): void {
		// `$count_global` keeps workers out of every site-wide aggregate — one
		// long-running job would otherwise dominate the averages — and the
		// header sums THIS index, so the default view has to agree. The table
		// hides them too, or the header stops describing what is listed.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/reader', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 48.0, 'last_seen' => 1700000003 ],
			'dddddddddddd' => [ 'url' => '/w?reconcile', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 180000.0, 'worker' => true, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertSame( 4, $result['totals']['requests'] );
		$this->assertEqualsWithDelta( 12.0, $result['totals']['avg_ms'], 1e-6 );
		$this->assertSame( [ 'https://example.com/reader' ], \array_column( $result['data'], 'url' ) );
	}

	public function test_urls_verb_shows_worker_traffic_when_asked(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => '/reader', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 48.0, 'last_seen' => 1700000003 ],
			'dddddddddddd' => [ 'url' => '/w?reconcile', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 180000.0, 'worker' => true, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'urls',
			'--include_workers'
		);

		$this->assertSame( 2, $result['totals']['urls'] );
		$this->assertSame( 6, $result['totals']['requests'] );
		$this->assertTrue( $result['filters']['include_workers'] );
	}

	public function test_urls_verb_rate_skips_the_still_filling_bucket(): void {
		// Req/s averages the current hour's COMPLETE buckets: at :37, 2,100
		// requests over seven of them is 1/s. The newest bucket is still
		// accumulating, so counting it would drag every rate down — the
		// dashboard's own rates have always dropped it, and the server has to
		// drop the same one to answer the same question.
		$store = $this->stats_store( 0, 86400 );
		$now   = self::tick();
		$this->set_url_bucket( $store, Stats_Store::bucket_key( $now - 600 ), [
			'cccccccccccc' => [ 'url' => '/rate', 'count' => 2100, 'timed_count' => 2100, 'sum_ms' => 2100.0, 'last_seen' => $now - 600 ],
		] );
		$this->set_url_bucket( $store, Stats_Store::bucket_key( $now ), [
			'cccccccccccc' => [ 'url' => '/rate', 'count' => 99000, 'timed_count' => 99000, 'sum_ms' => 99000.0, 'last_seen' => $now ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( 101100, $result['totals']['requests'] );
		$this->assertEqualsWithDelta( 1.0, $result['totals']['requests_per_second'], 1e-9 );
	}

	public function test_urls_verb_filters_errors_only_and_totals_match(): void {
		// "Errors" = timeouts and fatals; a 5xx alone is a response, not one.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [
				'url' => '/clean', 'count' => 9, 'timed_count' => 9, 'sum_ms' => 90.0, 'last_seen' => 1700000001,
				'count_2xx' => 6, 'count_3xx' => 1, 'count_4xx' => 1, 'count_5xx' => 1,
			],
			'bbbbbbbbbbbb' => [
				'url' => '/timeouts', 'count' => 6, 'timed_count' => 5, 'sum_ms' => 60.0, 'last_seen' => 1700000002,
				'count_2xx' => 2, 'count_3xx' => 0, 'count_4xx' => 0, 'count_5xx' => 3, 'errors' => 4,
			],
			'cccccccccccc' => [
				'url' => '/also-clean', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 40.0, 'last_seen' => 1700000003,
				'count_2xx' => 4, 'count_3xx' => 0, 'count_4xx' => 0, 'count_5xx' => 0,
			],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--errors_only=1'
		);

		// The footer reads `total`; it must count what is actually rendered.
		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'https://example.com/timeouts', $result['data'][0]['url'] );
		// Its errors beside its traffic: six requests, four timed out or fataled.
		$this->assertSame( 6, $result['data'][0]['count'] );
		$this->assertSame( 4, $result['data'][0]['errors'] );
		$this->assertSame( 6, $result['totals']['requests'] );
		$this->assertSame( 4, $result['totals']['errors'] );
	}

	public function test_errors_only_ranks_a_count_sort_by_errors(): void {
		// Busy with one timeout against quiet with five: errors decide.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'dddddddddddd' => [
				'url' => '/busy', 'count' => 300, 'timed_count' => 299, 'sum_ms' => 900.0, 'last_seen' => 1700000011,
				'count_2xx' => 299, 'count_3xx' => 0, 'count_4xx' => 0, 'count_5xx' => 0, 'errors' => 1,
			],
			'eeeeeeeeeeee' => [
				'url' => '/quiet', 'count' => 12, 'timed_count' => 7, 'sum_ms' => 70.0, 'last_seen' => 1700000012,
				'count_2xx' => 7, 'count_3xx' => 0, 'count_4xx' => 0, 'count_5xx' => 0, 'errors' => 5,
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--errors_only=1 --sort=count --order=desc' );

		$this->assertSame( [ 'https://example.com/quiet', 'https://example.com/busy' ], \array_column( $result['data'], 'url' ) );
	}

	public function test_urls_verb_search_drops_the_folded_aggregate_row(): void {
		// The folded row stands for many URLs, so a search cannot know whether
		// its contents match. It is identified by `aggregate` — seeded here
		// with matching url text, which must NOT be enough to include it.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [
				'url' => '/reviews/spring', 'count' => 11, 'timed_count' => 11, 'sum_ms' => 220.0,
				'last_seen' => 1700000401, 'count_2xx' => 11,
			],
			'bbbbbbbbbbbb' => [
				'url' => '/archive/2019', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 75.0,
				'last_seen' => 1700000402, 'count_2xx' => 5,
			],
			Stats_Store::OTHER_KEY => [
				'url' => '/reviews/folded', 'count' => 613, 'timed_count' => 613, 'sum_ms' => 9195.0,
				'last_seen' => 1700000403, 'count_2xx' => 613,
			],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire( $interpreter, 'performance', 'urls', '--search=/reviews/' );

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertCount( 1, $result['data'] );
		$this->assertSame( 'https://example.com/reviews/spring', $result['data'][0]['url'] );
		// The 613 folded requests must not reach a scoped total.
		$this->assertSame( 11, $result['totals']['requests'] );
	}

	public function test_urls_verb_heals_poisoned_min_ms_sentinel(): void {
		// A URL whose every persisted bucket is untimed carries the
		// PHP_INT_MAX sentinel as min_ms (worker / timed-out requests). The
		// display must never surface the sentinel — it heals to 0.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [
				'url'         => '/worker-only',
				'count'       => 7,
				'timed_count' => 0,
				'sum_ms'      => 0.0,
				'min_ms'      => PHP_INT_MAX,
				'max_ms'      => 0.0,
				'last_seen'   => 1700000001,
			],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertSame( 1, $result['totals']['urls'] );
		// JSON round-trip in the verb harness collapses 0.0 → int 0; the
		// poisoned value would survive as a huge number, so a 0 here proves
		// the sentinel was rejected.
		$this->assertSame( 0, $result['data'][0]['min_ms'] );
	}

	public function test_urls_verb_min_ms_unaffected_by_untimed_sibling_bucket(): void {
		// Same URL hash across two buckets: one untimed-only (timed_count 0,
		// min_ms 0 from the write-side guard) and one timed (timed_count 5,
		// min_ms 42). The read merge must fold only the timed bucket so the
		// real minimum survives — an untimed-only sibling must not clamp it to 0.
		$store    = $this->stats_store( 0, 86400 );
		$bucket_b = $this->current_url_bucket();
		$bucket_a = Stats_Store::bucket_key( self::tick() - 600 );

		$this->set_url_bucket( $store, $bucket_a, [
			'bbbbbbbbbbbb' => [
				'url'         => '/mixed',
				'count'       => 3,
				'timed_count' => 0,
				'sum_ms'      => 0.0,
				'min_ms'      => 0,
				'max_ms'      => 0.0,
				'last_seen'   => 1700000001,
			],
		] );
		$this->set_url_bucket( $store, $bucket_b, [
			'bbbbbbbbbbbb' => [
				'url'         => '/mixed',
				'count'       => 5,
				'timed_count' => 5,
				'sum_ms'      => 500.0,
				'min_ms'      => 42,
				'max_ms'      => 120.0,
				'last_seen'   => 1700000002,
			],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertSame( 42, $result['data'][0]['min_ms'] );
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

	/** Every shard keeps an overflow row of its own; the fold sums each field of them by name. */
	public function test_the_folded_other_row_sums_every_shards_status_counts_and_errors(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		foreach ( [ '3' => [ 7, 2, 1 ], 'a' => [ 11, 5, 3 ] ] as $shard => [ $ok, $five, $errors ] ) {
			$this->seed_url_shard( $store, $bucket, $shard, [
				Stats_Store::OTHER_KEY => [ 'count' => $ok + $five, 'timed_count' => $ok + $five, 'sum_ms' => 40.0, 'count_2xx' => $ok, 'count_5xx' => $five, 'errors' => $errors, 'last_seen' => 1700000004 ],
			] );
		}

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--limit=' . ( Stats_Store::URL_RANK_N + 1 ) );

		$other = \array_column( $result['data'], null, 'hash' )[ Stats_Store::OTHER_KEY ];
		$this->assertSame( [ 18, 7, 4 ], [ $other['count_2xx'], $other['count_5xx'], $other['errors'] ] );
		$this->assertSame( [], \array_filter( \array_keys( $other ), 'is_int' ), 'no field rides under its index' );
	}

	public function test_the_other_row_is_marked_as_an_aggregate(): void {
		// It stands for many URLs, so it is not one: its key is not a url_hash
		// and `dump_url` cannot answer for it. The row says so rather than
		// leaving the table to offer a link that errors.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc'         => [ 'url' => '/real', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 300.0, 'last_seen' => 1700000003 ],
			Stats_Store::OTHER_KEY => [ 'count' => 40, 'timed_count' => 40, 'sum_ms' => 4000.0, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$rows = [];
		foreach ( $result['data'] as $row ) {
			$rows[ $row['hash'] ] = $row;
		}
		$this->assertTrue( $rows[ Stats_Store::OTHER_KEY ]['aggregate'] );
		$this->assertFalse( $rows['cccccccccccc']['aggregate'] );
		// Its REQUESTS count — that is the point of folding rather than
		// dropping — but it is not itself a unique URL.
		$this->assertSame( 43, $result['totals']['requests'] );
		$this->assertSame( 1, $result['totals']['urls'] );
	}

	public function test_the_worker_overflow_row_is_an_aggregate_too(): void {
		// The fold emits TWO overflow rows, because one cannot answer a filter
		// about a set that mixes worker and reader traffic. A reader testing
		// only the first key renders the second as an ordinary clickable row —
		// with no URL, counted as a unique URL, and answering a click with
		// "invalid hash format".
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'dddddddddddd'                => [ 'url' => '/real', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 700.0, 'last_seen' => 1700000003 ],
			Stats_Store::OTHER_WORKER_KEY => [ 'count' => 91, 'timed_count' => 91, 'sum_ms' => 9100.0, 'worker' => true, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--include_workers=1' );

		$rows = [];
		foreach ( $result['data'] as $row ) {
			$rows[ $row['hash'] ] = $row;
		}
		$this->assertTrue( $rows[ Stats_Store::OTHER_WORKER_KEY ]['aggregate'] );
		$this->assertSame( 98, $result['totals']['requests'] );
		$this->assertSame( 1, $result['totals']['urls'] );
	}

	public function test_the_errors_filter_does_not_admit_the_whole_folded_tail(): void {
		// The overflow row stands for hundreds of URLs, and the predicate is a
		// ROW test: one timeout anywhere in that tail makes the row look like
		// an erroring URL, and every non-error request it folded then lands in
		// an errors-only total. A folded row cannot answer a per-URL question.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'0badc0de1234'         => [ 'url' => '/erroring', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 50.0, 'count_2xx' => 4, 'errors' => 1, 'last_seen' => 1700000003 ],
			Stats_Store::OTHER_KEY => [ 'count' => 900, 'timed_count' => 900, 'sum_ms' => 9000.0, 'count_2xx' => 899, 'errors' => 1, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--errors_only=1' );

		// One error among its five requests, and none from the folded tail.
		$this->assertSame( 5, $result['totals']['requests'] );
		$this->assertSame( 1, $result['totals']['errors'] );
		$this->assertSame( 1, $result['totals']['urls'] );
	}

	/**
	 * A stored row carrying an index this version does not know is IGNORED,
	 * never read as something else. It replaces a guard against reading the
	 * leaderboard's `sum_req_time` — a SECONDS field — as a URL row's `sum_ms`
	 * and multiplying it onto a mean: a positional row has no room for a
	 * foreign name, but a newer writer's extra index is the same hazard.
	 */
	public function test_a_url_row_ignores_an_index_it_does_not_know(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( '5ec0d5fa11ba' ), [
			'5ec0d5fa11ba' => self::positional_url_row(
				[ 'count' => 4, 'last_seen' => 1700000003 ]
			) + [ 99 => 8.0, 'url' => '/seconds-era' ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$row = $result['data'][0] ?? [];
		$this->assertSame( 'https://example.com/seconds-era', $row['url'] ?? '' );
		$this->assertSame( 0.0, (float) ( $row['avg_ms'] ?? -1 ), 'no milliseconds are invented from it' );
	}

	/** Seed one URL two servers served, each under its own key. */
	private function seed_split_row(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( '5p117c0de991' ), [
			'5p117c0de991' => [ 'url' => '/split-3907', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 390.0, 'last_seen' => 1700000007 ],
		], 'edge-3907.example' );
		$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( '5p117c0de991' ), [
			'5p117c0de991' => [ 'url' => '/split-3907', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 260.0, 'last_seen' => 1700000007 ],
		], 'edge-8823.example' );
	}

	/**
	 * Decision 18 rests the positional row on it never crossing to the wire:
	 * integer keys are what the browser cannot read, and JSON takes them
	 * happily — no assertion elsewhere would notice.
	 */
	public function test_an_unscoped_reply_row_is_named_and_sums_every_server(): void {
		$this->seed_split_row();

		$row = ( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' )['data'][0] ) ?? [];

		// One name per hash: the server filed last names it for both.
		$this->assertSame( 'https://edge-8823.example/split-3907', $row['url'] ?? '' );
		$this->assertSame( 5, $row['count'] ?? -1, 'one hash two servers served folds into one row' );
		$this->assertSame(
			[],
			\array_values( \array_filter( \array_keys( $row ), '\is_int' ) ),
			'no positional key rides out to the wire'
		);
	}

	/** And the scoped read, which reads one server's keys. */
	public function test_a_scoped_reply_row_is_named_and_carries_that_server_alone(): void {
		$this->seed_split_row();

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'urls',
			'--server=edge-3907.example'
		);
		$row = $result['data'][0] ?? [];

		// One name per hash: the server filed last names it for both.
		$this->assertSame( 'https://edge-8823.example/split-3907', $row['url'] ?? '' );
		$this->assertSame( 3, $row['count'] ?? -1, 'the scoped sums arrive under NAMES' );
		$this->assertEqualsWithDelta( 130.0, $row['avg_ms'] ?? -1.0, 1e-6 );
		$this->assertSame(
			[],
			\array_values( \array_filter( \array_keys( $row ), '\is_int' ) ),
			'no positional key rides out to the wire'
		);
	}

	public function test_a_bool_at_a_count_index_contributes_nothing(): void {
		// A stored row carries a BOOL at ROW_WORKER, so a shifted index puts one
		// where a count is read. The lenient family folds `true` as 1 and the
		// number is wrong with nothing to show for it; the validated family
		// takes the default, which is what `Core`'s own rule asks for on an
		// arithmetic path. Seeded at 2, distinct from the 0 default and from
		// the 1 the lenient cast would produce.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->seed_url_shard( $store, $bucket, Stats_Store::url_shard( 'b001a7c0un7' ), [
			'b001a7c0un7' => self::positional_url_row(
				[ 'url' => '/bool-at-a-count', 'count' => 2, 'last_seen' => 1700000005 ]
			) + [ Stats_Store::ROW_COUNT_4XX => true ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$row = $result['data'][0] ?? [];
		$this->assertSame( 'https://example.com/bool-at-a-count', $row['url'] ?? '' );
		$this->assertSame( 2, $row['count'] ?? -1, 'the real count is untouched' );
		$this->assertSame( 0, $row['count_4xx'] ?? -1, 'and a bool adds nothing to a count' );
	}

	public function test_a_row_with_no_timed_count_divides_by_nothing(): void {
		// `timed_count` has ridden every URL row for releases. Falling back to
		// the whole count for a row without it invents a denominator from a
		// shape nothing writes.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'facade0ffee1' => [ 'url' => '/untimed', 'count' => 5, 'sum_ms' => 750.0, 'last_seen' => 1700000004 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( 0.0, (float) ( $result['totals']['avg_ms'] ?? -1 ) );
	}

	public function test_the_mean_divides_by_timed_requests_not_by_every_request(): void {
		// Every other fixture seeds timed_count === count, so both denominators
		// agree and neither discriminates. Here they differ: 400ms over the 4
		// requests that recorded a duration is 100, over all 10 it is 40.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'd1ff3d3n0m1n' => [
				'url'         => '/mostly-timed-out',
				'count'       => 10,
				'timed_count' => 4,
				'sum_ms'      => 400.0,
				'last_seen'   => 1700000005,
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertEqualsWithDelta( 100.0, $result['totals']['avg_ms'], 0.01 );
		$this->assertEqualsWithDelta( 100.0, $result['data'][0]['avg_ms'], 0.01 );
		$this->assertSame( 10, $result['totals']['requests'] );
	}

	public function test_dump_url_verb_returns_stats_and_default_flame(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'abc123def456' => [
				'url'       => '/articles/777',
				'count'     => 9,
				'timed_count' => 9, 'sum_ms'    => 450.0,
				'last_seen' => 1700000999,
			],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_url',
			'abc123def456'
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'stats', $result );
		$this->assertArrayHasKey( 'requests', $result );
		$this->assertArrayHasKey( 'aggregate_flame', $result );
		$this->assertSame( 'https://example.com/articles/777', $result['stats']['url'] );
		$this->assertSame( 9, $result['stats']['count'] );
		// No flame seeded → default empty-tree shape.
		$this->assertSame( 'aggregate', $result['aggregate_flame']['name'] );
		$this->assertSame( 0, $result['aggregate_flame']['value'] );
		$this->assertSame( [], $result['aggregate_flame']['children'] );
		$this->assertSame( [], $result['requests'] );
	}

	public function test_dump_url_verb_includes_aggregate_flame_when_seeded(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cafebabe1234' => [
				'url'       => '/x',
				'count'     => 1,
				'timed_count' => 1, 'sum_ms'    => 10.0,
				'last_seen' => 1700001000,
			],
		] );
		// Per-URL flame stats blob lives at NS_URL keyed by url_hash.
		$this->set_url_stats( $store, 'cafebabe1234', [
			'flame_raw'     => [ 'name' => 'aggregate', 'sum_value' => 200.0, 'count' => 2, 'children' => [ [ 'name' => 'a', 'sum_value' => 100.0, 'ts' => 1700001000, 'children' => [] ] ] ],
			'last_modified' => 1700001111,
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_url',
			'cafebabe1234'
		);

		$this->assertSame( 100, $result['aggregate_flame']['value'] );
		$this->assertSame( 1700001111, $result['last_modified'] );
	}

	/**
	 * Each flame-builder partition writes its own share of a URL's traffic to
	 * its own blob, so the modal's aggregate is every partition's summed, as
	 * the rows are (decision 2), never partition 0's quarter.
	 */
	public function test_dump_url_sums_every_partitions_url_blob(): void {
		$this->activate_shipped( 'performance', 3 );
		$hash = $this->seed_listed_url( '/summed-blobs', 5 );
		$now  = self::tick();
		$this->set_url_stats( $this->stats_store( 0, 86400 ), $hash, [
			'flame_raw'     => [ 'name' => 'aggregate', 'sum_value' => 300.0, 'count' => 3, 'children' => [
				[ 'name' => 'init hook', 'sum_value' => 90.0, 'ts' => $now - 41, 'children' => [] ],
			] ],
			'profiles'      => [ 'count' => 3, 'sum_req_time' => 300.0, 'categories' => [
				'render' => [ 'samples' => 3, 'sum_time' => 60.0, 'sum_count' => 6, 'entries' => [] ],
			] ],
			'last_modified' => $now - 7,
		] );
		$this->set_url_stats( $this->stats_store( 2, 86400 ), $hash, [
			'flame_raw'     => [ 'name' => 'aggregate', 'sum_value' => 500.0, 'count' => 2, 'children' => [
				[ 'name' => 'init hook', 'sum_value' => 110.0, 'ts' => $now - 19, 'children' => [] ],
				[ 'name' => 'wp_loaded hook', 'sum_value' => 40.0, 'ts' => $now - 19, 'children' => [] ],
			] ],
			'profiles'      => [ 'count' => 2, 'sum_req_time' => 500.0, 'categories' => [
				'render' => [ 'samples' => 2, 'sum_time' => 40.0, 'sum_count' => 4, 'entries' => [] ],
				'sql'    => [ 'samples' => 2, 'sum_time' => 20.0, 'sum_count' => 10, 'entries' => [] ],
			] ],
			'last_modified' => $now - 3,
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$flame    = $result['aggregate_flame'];
		$children = \array_column( $flame['children'], 'value', 'name' );
		$this->assertSame( 5, $flame['count'], 'every partition\'s requests' );
		$this->assertEqualsWithDelta( 160.0, $flame['value'], 1e-6, '800 ms over 5 requests' );
		$this->assertEqualsWithDelta( 40.0, $children['init hook'], 1e-6, '200 ms over 5 requests' );
		$this->assertEqualsWithDelta( 8.0, $children['wp_loaded hook'], 1e-6, 'a span one partition alone saw' );
		$profiles = $result['aggregate_profiles'];
		$this->assertSame( 5, $profiles['count'] );
		$this->assertEqualsWithDelta( 160.0, $profiles['total_time'], 1e-6 );
		$this->assertEqualsWithDelta( 20.0, $profiles['categories']['render']['time'], 1e-6 );
		$this->assertEqualsWithDelta( 2.0, $profiles['categories']['sql']['count'], 1e-6 );
		$this->assertSame( $now - 3, $result['last_modified'], 'the newest partition\'s flush' );
	}

	/**
	 * Seed a URL whose flame blob has expired: its row, and two requests whose
	 * stored flames each carry one `init hook` span.
	 *
	 * @return array{0:string,1:int} The URL hash and the newer request's start.
	 */
	private function seed_a_cold_url_with_two_flames(): array {
		$url   = '/cold-flame';
		$hash  = Log_Manager::url_hash( $url );
		$now   = self::tick();
		$store = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 2, 'timed_count' => 2, 'sum_ms' => 100.0, 'last_seen' => $now - 311 ],
		] );
		foreach ( [ [ 'rid-cold-a-8841902', $now - 947, 40, 30 ], [ 'rid-cold-b-8841902', $now - 311, 60, 10 ] ] as [ $rid, $at, $ms, $span ] ) {
			$this->write_request( [
				'rid'            => $rid,
				'url'            => $url,
				'timestamp'      => $at,
				'duration_ms'    => $ms,
				'status_code'    => 200,
				'request_method' => 'GET',
			] );
			$this->write_flame( [
				'rid'      => $rid,
				'url_hash' => $hash,
				'name'     => 'request',
				'value'    => $ms,
				'children' => [ [ 'name' => 'init hook', 'value' => $span, 'children' => [] ] ],
			] );
		}
		return [ $hash, $now - 311 ];
	}

	public function test_dump_url_rebuilds_the_flame_a_cold_url_lost(): void {
		[ $hash, $newest ] = $this->seed_a_cold_url_with_two_flames();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		// Per-request means: (40 + 60) / 2 at the root, (30 + 10) / 2 below it.
		$this->assertEqualsWithDelta( 50.0, $result['aggregate_flame']['value'], 0.001 );
		$this->assertSame( 'init hook', $result['aggregate_flame']['children'][0]['name'] );
		$this->assertEqualsWithDelta( 20.0, $result['aggregate_flame']['children'][0]['value'], 0.001 );
		$this->assertSame( $newest, $result['last_modified'] );
	}

	public function test_a_rebuild_the_clock_cut_short_answers_no_flame(): void {
		// A partial fold would read as the URL's whole flame, and be kept.
		[ $hash ] = $this->seed_a_cold_url_with_two_flames();
		\file_put_contents(
			$this->tmp . '/logs/flames.p0/0.idx',
			\str_repeat( "x\n", ( Performance_CI_Node::MAX_SCAN_S + 3 ) * self::clock_stride() ),
			FILE_APPEND | LOCK_EX
		);
		$seconds      = 0.0;
		Core::$clock = static function () use ( &$seconds ): float {
			return $seconds += 1.0;
		};

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertNull( $result['aggregate_flame'] );
		$this->assertTrue( $result['scan_stopped_early'] );
	}

	public function test_a_rebuild_reads_only_the_partitions_its_requests_are_in(): void {
		// Flames partition like the requests they came from; p0 holds none.
		$this->activate_shipped( 'performance', 2 );
		$url   = '/cold-in-p1';
		$hash  = Log_Manager::url_hash( $url );
		$now   = self::tick();
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 1, 'timed_count' => 1, 'sum_ms' => 70.0, 'last_seen' => $now - 522 ],
		] );
		$this->write_request( [ 'rid' => 'rid-cold-p1-5530981', 'url' => $url, 'timestamp' => $now - 522, 'duration_ms' => 70, 'status_code' => 200, 'request_method' => 'GET' ], 1 );
		$this->write_flame( [ 'rid' => 'rid-cold-p1-5530981', 'url_hash' => $hash, 'name' => 'request', 'value' => 70, 'children' => [] ], 1 );
		$this->write_flame( [ 'rid' => 'rid-elsewhere-000001', 'url_hash' => 'f00f00f00f00', 'name' => 'request', 'value' => 1, 'children' => [] ], 0 );
		$reads       = 0;
		Core::$clock = static function () use ( &$reads ): float {
			++$reads;
			return 8080.0;
		};
		// The stats Tables read the clock too, once a statement, alike both times.
		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );
		$shallow = $reads;
		\file_put_contents( $this->tmp . '/logs/flames.p0/0.idx', \str_repeat( "x\n", 50 * self::clock_stride() ), FILE_APPEND | LOCK_EX );
		$reads = 0;

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertEqualsWithDelta( 70.0, $result['aggregate_flame']['value'], 0.001 );
		$this->assertLessThan( 10, $reads - $shallow, "p0's flame index was walked" );
	}

	public function test_a_tailing_dump_url_rebuilds_nothing_from_its_partial_list(): void {
		// An `--after` list is the newest few; the held flame stands instead.
		[ $hash, $newest ] = $this->seed_a_cold_url_with_two_flames();
		$opened = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );
		$older  = \array_values( \array_filter( $opened['requests'], static fn ( array $r ): bool => 'rid-cold-a-8841902' === $r['rid'] ) );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, '--after=' . self::after_rows( $older ) ] );

		$this->assertSame( [ 'rid-cold-b-8841902' ], \array_column( $result['requests'], 'rid' ) );

		$this->assertNull( $result['aggregate_flame'] );
		$this->assertSame( $newest, $result['last_modified'] );
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

	public function test_dump_url_stats_carry_the_header_figures_and_no_series(): void {
		// The modal's chart is drawn from `breakdown_time_series`, which the
		// dropdown always asks for, so a second undifferentiated series bought
		// a first paint nobody chose at the price of a full shard scan.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'a5c9e30b1f42' => [
				'url'         => '/reviews/first',
				'count'       => 7,
				'timed_count' => 7,
				'sum_ms'      => 917.0,
				'last_seen'   => 1700001000,
			],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'dump_url',
			'a5c9e30b1f42'
		);

		$this->assertArrayNotHasKey( 'time_series', $result['stats'] );
		$this->assertSame( 'https://example.com/reviews/first', $result['stats']['url'] );
		$this->assertSame( 7, $result['stats']['count'] );
		$this->assertEqualsWithDelta( 131.0, $result['stats']['avg_ms'], 1e-6 );
	}

	public function test_dump_url_verb_includes_breakdown_time_series_when_arg_set(): void {
		// `?breakdown=method` on /urls/{hash} emits `breakdown_time_series`
		// (legacy L195, L177-181). Consumed by fetchUrlBreakdown L213.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'abc123def456' => [
				'url'       => '/x',
				'count'     => 1,
				'timed_count' => 1, 'sum_ms'    => 10.0,
				'last_seen' => 1700001000,
			],
		] );
		$bucket = $this->current_url_bucket();
		$this->set_hour_slot( $store, Stats_Store::url_dim_parts( 'abc123def456' ), $bucket, [ 'GET' => self::dim_entry( 3, 0.3, 0.1 ) ], 'method' );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_url',
			'abc123def456 --breakdown=method'
		);

		$this->assertArrayHasKey( 'breakdown_time_series', $result );
		$this->assertSame( 3, self::wire_series( $result['breakdown_time_series'] )[ $bucket ]['GET'][ Stats_Store::DIM_COUNT ] );
	}

	public function test_url_breakdown_answers_the_series_alone(): void {
		// The chart polls this every five minutes and keeps only the series, so
		// the verb behind it must not drag the index walk `dump_url` runs.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'e71b04ac9d33' => [ 'url' => '/breakdown-only', 'count' => 6, 'timed_count' => 6, 'sum_ms' => 84.0, 'last_seen' => 1700006000 ],
		] );
		$this->set_hour_slot( $store, Stats_Store::url_dim_parts( 'e71b04ac9d33' ), $bucket, [ '503' => self::dim_entry( 9, 1.7, 0.4 ) ], 'status' );
		$detail = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'dump_url',
			'e71b04ac9d33 --breakdown=status'
		);
		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_breakdown',
			'e71b04ac9d33 --breakdown=status'
		);

		$this->assertSame( $detail['breakdown_time_series'], $result['breakdown_time_series'] );
		$this->assertSame( 9, self::wire_series( $result['breakdown_time_series'] )[ $bucket ]['503'][ Stats_Store::DIM_COUNT ] );
		$this->assertArrayNotHasKey( 'requests', $result );
		$this->assertArrayNotHasKey( 'stats', $result );
		$this->assertArrayNotHasKey( 'aggregate_flame', $result );
	}

	public function test_url_breakdown_refuses_a_dimension_it_cannot_answer(): void {
		// A required argument that silently answers nothing leaves the chart
		// spinning; dump_url can drop the key because it has a payload.
		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_breakdown',
			'e71b04ac9d33 --breakdown=nosuchdim'
		);

		$this->assertIsString( $result );
		// Named, because the caller's own spelling is what it has to fix.
		$this->assertStringContainsString( 'invalid breakdown dimension: nosuchdim', $result );
	}

	public function test_url_breakdown_refuses_the_server_axis(): void {
		// One URL is one server's, so a server axis would draw a single line.
		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'url_breakdown',
			'e71b04ac9d33 --breakdown=server'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid breakdown dimension: server', $result );
	}

	public function test_dump_url_refuses_the_server_axis(): void {
		// The same refusal `url_breakdown` gives, not a reply missing a key.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'b7731ce0fa11' => [ 'url' => '/wombat-7731', 'count' => 3, 'last_seen' => self::tick() ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'b7731ce0fa11 --breakdown=server' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid breakdown dimension: server', $result );
	}

	public function test_dump_url_verb_includes_category_time_series_when_arg_set(): void {
		// `?categories=1` on /urls/{hash} emits `category_time_series`
		// (legacy L196, L184-186). Consumed by UrlDetailView L282-295 +
		// fetchUrlCategories L237.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'abc123def456' => [
				'url'       => '/x',
				'count'     => 1,
				'timed_count' => 1, 'sum_ms'    => 10.0,
				'last_seen' => 1700001000,
			],
		] );
		$bucket = $this->current_url_bucket();
		$this->set_hour_slot( $store, Stats_Store::url_cat_parts( 'abc123def456' ), $bucket, [ 'db' => self::cat_entry( 0.2, 2, 1 ) ] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_url',
			'abc123def456 --categories'
		);

		$this->assertArrayHasKey( 'category_time_series', $result );
		// The modal reads the same compact shape the overview card does.
		$this->assertSame( [ 'db' ], $result['category_time_series']['names'] );
		$this->assertSame( 0.2, $result['category_time_series']['buckets'][ $bucket ][0][1] );
	}

	/**
	 * Both URL series cover the whole read window, not the reader row's
	 * `last_seen`: worker traffic files url_cat and url_dim buckets for the
	 * hash after the reader row's last request.
	 */
	public function test_dump_url_series_reach_past_the_reader_rows_last_seen(): void {
		$store   = $this->stats_store( 0, 86400 );
		$old     = self::tick() - 3 * Stats_Store::BUCKET_SECONDS;
		$current = $this->current_url_bucket();
		$this->set_url_bucket( $store, Stats_Store::bucket_key( $old ), [
			'd06e5a1b7c43' => [ 'url' => '/quokka-5a1b', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 52.0, 'last_seen' => $old ],
		] );
		$this->set_hour_slot( $store, Stats_Store::url_cat_parts( 'd06e5a1b7c43' ), $current, [ 'wpdb' => self::cat_entry( 41.5, 6, 3 ) ] );
		$this->set_hour_slot( $store, Stats_Store::url_dim_parts( 'd06e5a1b7c43' ), $current, [ '418' => self::dim_entry( 7, 2.9, 0.6 ) ], 'status' );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'd06e5a1b7c43 --breakdown=status --categories' );

		$this->assertIsArray( $result );
		$this->assertSame( 7, self::wire_series( $result['breakdown_time_series'] )[ $current ]['418'][ Stats_Store::DIM_COUNT ] ?? null, 'the url_dim bucket after last_seen' );
		$this->assertSame( 41.5, $result['category_time_series']['buckets'][ $current ][0][1] ?? null, 'the url_cat bucket after last_seen' );
	}

	public function test_dump_url_verb_refuses_an_unknown_dim(): void {
		// An unknown dim is refused, as `url_breakdown` refuses it, rather
		// than answered without the series it asked for.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'abc123def456' => [ 'url' => '/x', 'count' => 1, 'timed_count' => 1, 'sum_ms' => 10.0, 'last_seen' => 1700001000 ],
		] );

		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_url',
			'abc123def456 --breakdown=nosuchdim'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid breakdown dimension: nosuchdim', $result );
	}

	// -------------------------------------------------------------------------
	// Partition span: the dashboard reads a topology's OWN worker count, not
	// the global `num_partitions`. The hub runs four workers on a global 1, so
	// a reader looping to the global sees a quarter of the fleet. Both tests
	// activate the SHIPPED topologies so a renamed node fails them.
	// -------------------------------------------------------------------------

	/**
	 * One `overview` builds the partitions' stores once, whatever panels it
	 * is asked for. The substrate keeps a resolved Table for the process, so
	 * each call starts cold and counts the resolutions the verb itself makes.
	 */
	public function test_overview_builds_its_stores_once(): void {
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => 86400 ] );
		$this->activate_shipped( 'performance', 3 );
		$reads = self::count_catalog_reads();
		// Each call mounts its request graph afresh, as a request does.
		$catalog_reads_of = static function ( array $args ) use ( $reads ): int {
			VerbHarness::reset();
			\Newspack_Nodes\Bootstrap::forget_node_stores();
			$before = $reads();
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', $args );
			return $reads() - $before;
		};

		$bare = $catalog_reads_of( [] );

		$this->assertSame( 1, $bare, 'one store build resolves the catalog once' );
		$this->assertSame(
			$bare,
			$catalog_reads_of( [ '--server=web07', '--breakdown=status,method,server', '--categories' ] ),
			'three breakdowns and the categories cost no further store build'
		);
	}

	public function test_search_requests_spans_the_topologys_own_worker_count(): void {
		// Global num_partitions stays 1; performance runs 4. A rid living in p2 is
		// invisible to a reader that loops to the global.
		$this->activate_shipped( 'performance', 4 );
		$rid = $this->write_request(
			[
				'rid'            => 'rid-high-partition-000000000001',
				'url'            => '/deep',
				'timestamp'      => 1700000400,
				'duration_ms'    => 20,
				'status_code'    => 200,
				'peak_mb'        => 3,
				'request_method' => 'GET',
			],
			2
		);

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', $rid );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['partition'] );
	}

	public function test_search_requests_tries_the_rids_own_partition_first(): void {
		// This rid hashes to 3 of 4. Seeded in BOTH 3 and 0, an ascending scan
		// returns 0 — only hash-first returns 3.
		$this->activate_shipped( 'performance', 4 );
		$body = [
			'rid'            => 'rid-hash-order-0000000000000001',
			'url'            => '/hashed',
			'timestamp'      => 1700000400,
			'duration_ms'    => 20,
			'status_code'    => 200,
			'peak_mb'        => 3,
			'request_method' => 'GET',
		];
		$this->assertSame( 3, \Newspack_Nodes\Partition_Node::hash_to_partition( $body['rid'], 4 ) );
		$this->write_request( $body, 0 );
		$rid = $this->write_request( $body, 3 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', $rid );

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['partition'] );
	}

	public function test_dump_request_says_not_found_when_no_topology_is_active(): void {
		// Nothing declares requests:partition, so there is no partition set to
		// be outside of. "invalid partition" blames the caller for a request
		// that is merely unfindable.
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Nodes\Config::reset();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'some-rid' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_stats_stores_span_the_topologys_own_worker_count(): void {
		// flame-builder writes its stats to Tables keyed by worker index, so
		// the store fan-out has to follow the topology count, not the global.
		$this->activate_shipped( 'performance', 3 );
		$bucket = Stats_Store::bucket_key( self::tick() );
		foreach ( [ 0 => 3, 1 => 5, 2 => 7 ] as $p => $count ) {
			$this->set_hour_slot( $this->stats_store( $p ), Stats_Store::hourly_parts(), $bucket, [ 'count' => $count, 'sum_ms' => 1.0, 'sum_peak_mb' => 1.0 ] );
		}

		$this->assertSame( 15, VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['total_requests'], 'every partition\'s store is read' );
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
		// Seed one request — search should return {rid, partition, url_hash}.
		// Rid must be ≤32 chars or it gets truncated when written into the
		// fixed-width .idx field and the round-trip lookup fails.
		$rid = $this->write_request( [
			'rid'            => 'rid-search-12345678901234567890',
			'url'            => '/searchable',
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

		$this->assertIsArray( $result );
		$this->assertSame( $rid, $result['rid'] );
		$this->assertSame( 0, $result['partition'] );
		$this->assertNotEmpty( $result['url_hash'] );
	}

	/**
	 * The binder hands a handler its args by name however they arrived, so a
	 * caller naming the positional one — the MCP door names every arg — reads
	 * the same answer as a caller placing it.
	 */
	public function test_search_requests_takes_its_rid_by_name(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-named-kea-4417',
			'url'            => '/named-kea-4417',
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
			[ 'rid' => 'grepR1', 'k' => 'request', 'm' => 'GET /calendar/today?x=1', 'ts' => 1700000000.0, 'n' => 2 ],
			[ 'rid' => 'grepR1', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.4, 'n' => 3, 'duration_ms' => 400 ],
			[ 'rid' => 'grepNoise', 'k' => 'request', 'm' => 'GET /feed', 'ts' => 1700000001.0, 'n' => 1 ],
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
		$this->assertSame( '/calendar/today', $summary['url'] );
		$this->assertSame( 'GET', $summary['method'] );
		$this->assertGreaterThanOrEqual( 1, $summary['match_count'] );
		$this->assertStringContainsString( '/calendar', $summary['first_match_excerpt'] );
	}

	public function test_grep_requests_stops_when_its_time_runs_out(): void {
		// p0 outlasts the clock; the only match waits in p1, never entered.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 2 ] );
		$entries = [];
		$lines   = ( Performance_CI_Node::MAX_SCAN_S + 3 ) * self::clock_stride();
		for ( $i = 0; $i < $lines; $i++ ) {
			$entries[] = [ 'rid' => "noise{$i}", 'k' => 'request', 'm' => 'GET /feed', 'ts' => 1700000000.0, 'n' => 1 ];
		}
		$this->write_firehose( 0, $entries );
		$this->write_firehose( 1, [
			[ 'rid' => 'lateMatch', 'k' => 'request', 'm' => 'GET /past-the-budget-4471', 'ts' => 1700000900.0, 'n' => 1 ],
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
			$entries[] = [ 'rid' => $rid, 'k' => 'request', 'm' => "GET /match/{$rid}", 'ts' => 1700000000.0 + $i, 'n' => 1 ];
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
			[ 'rid' => 'lim1', 'k' => 'request', 'm' => 'GET /match/a', 'ts' => 1700000000.0, 'n' => 1 ],
			[ 'rid' => 'lim1', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.5, 'n' => 2 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'grep_requests', '/match --limit=abc' );

		$this->assertIsString( $result, 'a malformed --limit must not silently return one row' );
		$this->assertStringContainsString( 'limit', $result );
	}

	public function test_grep_requests_empty_when_no_match(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'z1', 'k' => 'request', 'm' => 'GET /other', 'ts' => 1700000000.0, 'n' => 1 ],
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
			[ 'rid' => 'oakum7', 'k' => 'request', 'm' => 'GET /oakum-3391', 'ts' => 1700000000.0, 'n' => 1 ],
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
			[ 'rid' => 'clean1', 'k' => 'request', 'm' => 'GET /bilge-812', 'ts' => 1700000000.0, 'n' => 1 ],
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
			'url'            => '/elsewhere',
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
		$this->assertSame( '/elsewhere', $result['url'] );
	}

	/**
	 * A malformed --partition casts to 0, so the verb answers for a partition
	 * the operator never named — and where the rid is absent, blames the rid.
	 */
	public function test_dump_request_verb_refuses_a_malformed_partition(): void {
		$rid = $this->write_request( [
			'rid'         => 'rid-malformed-partition-flag',
			'url'         => '/p0-only',
			'timestamp'   => 1700000600,
			'duration_ms' => 12,
			'events'      => [ [ 'k' => 'process (start)', 'm' => '/p0-only', 'ts' => 1700000600.0 ] ],
		] );

		$result = VerbHarness::fire(
			new Performance_CI_Node(),
			'performance',
			'dump_request',
			$rid . ' --partition=abc'
		);

		$this->assertIsString( $result, 'a malformed --partition must not answer for p0' );
		$this->assertStringContainsString( 'partition', \strtolower( $result ) );
	}

	public function test_dump_request_verb_rejects_invalid_partition(): void {
		// num_partitions = 1 (test setUp), partition = 5 is out of range.
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire(
			$interpreter,
			'performance',
			'dump_request',
			'whatever --partition=5'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid partition', \strtolower( $result ) );
	}

	public function test_dump_request_verb_returns_body_for_known_rid(): void {
		// Rid must be ≤32 chars so the round-trip through the .idx fixed-width
		// field doesn't drop characters and break the lookup.
		$rid = $this->write_request( [
			'rid'            => 'rid-detail-12345678901234567890',
			'url'            => '/detailed',
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
		$this->assertSame( '/detailed', $result['url'] );
		$this->assertSame( 201, $result['status_code'] );
		$this->assertNotEmpty( $result['url_hash'] );
		$this->assertArrayHasKey( 'events', $result );
		$this->assertCount( 1, $result['events'] );
	}

	public function test_dump_request_carries_the_findings_for_that_record(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-findings-1234567890123456789',
			'url'            => '/slow-thing',
			'timestamp'      => 1700000700,
			'duration_ms'    => 9000,
			'status_code'    => 200,
			'peak_mb'        => 4,
			'request_method' => 'GET',
			'flame'          => [
				'name'     => 'request',
				'value'    => 40.0,
				'children' => [ [ 'name' => 'init', 'value' => 40.0, 'children' => [] ] ],
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );

		$this->assertIsArray( $result );
		$this->assertContains(
			'unattributed',
			\array_column( $result['findings'], 'kind' ),
			'40ms profiled of a 9-second request is subtraction, not inference'
		);
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
			'rule_id'        => Rule_Set::id_for( '/' ),
			'timestamp'      => 1700001000,
			'duration_ms'    => 90,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}:0" );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertNotNull( $result['rule'], 'the record named its rule; nothing had to be re-derived' );
		$this->assertSame( '/', $result['rule']['pattern'] );
	}

	public function test_ask_assembles_a_request_brief(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-req-123456789012345678',
			'url'            => '/asked-about',
			'timestamp'      => 1700000800,
			'duration_ms'    => 120,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}:0" );

		$this->assertIsArray( $result );
		$this->assertSame( 'request', $result['subject'] );
		$this->assertSame( '/asked-about', $result['url'] );
		$this->assertEquals( 120.0, $result['duration_ms'] );
	}

	/**
	 * Request descriptors whose partition is no canonical decimal, as the
	 * target and as a span's context.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function non_canonical_partitions(): array {
		$cases = [];
		foreach ( [ 'abc', '-3', '', '07' ] as $partition ) {
			$cases[ "target '{$partition}'" ]  = [ $partition, false ];
			$cases[ "context '{$partition}'" ] = [ $partition, true ];
		}
		return $cases;
	}

	/**
	 * A partition that is not a canonical decimal is refused: cast, `abc`
	 * named p0 and `-3` p-3, and the record was found anyway.
	 */
	#[DataProvider( 'non_canonical_partitions' )]
	public function test_ask_refuses_a_request_descriptor_whose_partition_is_not_canonical( string $partition, bool $as_context ): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-part-1234567890123456',
			'url'            => '/asked-partition',
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
		$request = "request:{$rid}:{$partition}";

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', $as_context ? [ 'span:wp_loaded', $request ] : [ $request ] );

		$this->assertIsString( $result );
		$this->assertSame( "invalid partition in {$request}", \trim( $result ) );
	}

	public function test_ask_resolves_a_span_through_its_request_context(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-span-12345678901234567',
			'url'            => '/asked-span',
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
			[ 'span:wp_loaded', "request:{$rid}:0" ]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'span', $result['subject'] );
		$this->assertSame( 'wp_loaded', $result['name'] );
		$this->assertEquals( 480.0, $result['ms'] );
	}

	public function test_ask_resolves_an_entry_by_position_and_refuses_one_that_names_none(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-entry-1234567890123456',
			'url'            => '/asked-entry',
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
			return VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ $descriptor, "request:{$rid}:0" ] );
		};

		$result = $ask( 'entry:1' );
		$this->assertIsArray( $result );
		$this->assertSame( 'second', $result['entry']['m'] );

		// An id naming no position is refused, never read as row 0.
		foreach ( [ 'entry:abc', 'entry:1x', 'entry:-1', 'entry:01' ] as $descriptor ) {
			$this->assertStringContainsString( 'no entry', Core::as_string( $ask( $descriptor ) ), $descriptor );
		}
	}

	public function test_ask_resolves_a_span_through_its_url_context(): void {
		// The name comes from the name table and the tree from the aggregate,
		// one key each; no index row is walked for it.
		$store = $this->stats_store( 0, 86400 );
		$store->set_url_names( [ 'example.test' => [ 'cafebabe5678' => 'https://example.test/asked-agg' ] ] );
		// As the flame builder stores it: sums, the count on the root alone.
		$this->set_url_stats( $store, 'cafebabe5678', [
			'flame_raw' => [
				'name'      => 'aggregate',
				'sum_value' => 900.0,
				'count'     => 3,
				'children'  => [ [ 'name' => 'wp_loaded', 'sum_value' => 720.0, 'ts' => self::tick(), 'children' => [] ] ],
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', 'url:cafebabe5678' ] );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertSame( 'span', $result['subject'] );
		$this->assertEquals( 240.0, $result['ms'] );
		$this->assertArrayNotHasKey( 'count', $result );
		$this->assertSame( 'mean per request over 3 requests, every server', $result['scope'] );
		$this->assertSame( 'https://example.test/asked-agg', $result['url'] );
	}

	/** The stored per-URL profile: sums the reader divides, never means. */
	private function stored_profiles(): array {
		return [
			'count'        => 5,
			'sum_req_time' => 500.0,
			'categories'   => [
				'render' => [ 'samples' => 5, 'sum_time' => 300.0, 'sum_count' => 10, 'entries' => [] ],
				'sql'    => [ 'samples' => 5, 'sum_time' => 100.0, 'sum_count' => 35, 'entries' => [] ],
			],
		];
	}

	public function test_ask_resolves_a_category_through_its_url_context(): void {
		$store = $this->stats_store( 0, 86400 );
		$store->set_url_names( [ Stats_Store::UNKNOWN_SERVER => [ 'cafebabe9012' => '/asked-cat' ] ] );
		$this->set_url_stats( $store, 'cafebabe9012', [ 'profiles' => $this->stored_profiles() ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:render', 'url:cafebabe9012' ] );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertSame( 'category', $result['subject'] );
		$this->assertSame( 'mean per request over 5 requests, every server', $result['scope'] );
		$this->assertSame( '/asked-cat', $result['url'] );
		$this->assertEqualsWithDelta( 60.0, $result['avg_time_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 2.0, $result['avg_count'], 1e-6 );
		$this->assertEqualsWithDelta( 0.75, $result['share'], 1e-6 );
	}

	public function test_ask_category_under_a_url_whose_aggregate_expired_answers_from_the_leaderboard(): void {
		// The per-URL blob lives a 24th of the window; the row lives the whole
		// window. A row with no blob answers from the board the panel beside
		// it draws.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cafebabe7890' => [ 'url' => '/asked-stale', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 20.0, 'last_seen' => 1700001000 ],
		] );
		$this->set_leaderboard_hour( $store, Stats_Store::hour_of( $bucket ), [
			'count'        => 40,
			'sum_req_time' => 10.0,
			'categories'   => [ 'wpdb' => [ 'samples' => 40, 'sum_time' => 400.0, 'sum_count' => 80, 'entries' => [] ] ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:wpdb', 'url:cafebabe7890' ] );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertSame( 'recent window', $result['scope'] );
		$this->assertEqualsWithDelta( 10.0, $result['avg_time_ms'], 1e-6 );
	}

	public function test_dump_url_serves_the_aggregate_profile_as_per_request_means(): void {
		// The modal's "Average breakdown across N requests" panel reads
		// `time` and `count` off each row and drops a row carrying neither, so
		// the stored sums have to be divided before they leave the verb.
		$store = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'cafebabe2468' => [ 'url' => '/asked-panel', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 500.0, 'last_seen' => 1700001000 ],
		] );
		$this->set_url_stats( $store, 'cafebabe2468', [ 'profiles' => $this->stored_profiles() ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'cafebabe2468' );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$profiles = $result['aggregate_profiles'];
		$this->assertSame( 5, $profiles['count'] );
		$this->assertEqualsWithDelta( 100.0, $profiles['total_time'], 1e-6 );
		$this->assertEqualsWithDelta( 60.0, $profiles['categories']['render']['time'], 1e-6 );
		$this->assertEqualsWithDelta( 7.0, $profiles['categories']['sql']['count'], 1e-6 );
	}

	public function test_ask_carries_the_url_a_request_was_picked_under(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-under-url-12345678901234',
			'url'            => '/asked-under',
			'timestamp'      => 1700000800,
			'duration_ms'    => 120,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ "request:{$rid}:0", 'url:cafebabe3456', '--server=alpha.example' ] );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertSame( 'dump_url', $result['fetch'][1]['tool'] );
		$this->assertSame( [ 'hash' => 'cafebabe3456', 'server' => 'alpha.example' ], $result['fetch'][1]['arguments'] );
	}

	public function test_a_span_under_a_url_with_no_aggregate_says_so(): void {
		$store = $this->stats_store( 0, 86400 );
		$store->set_url_names( [ Stats_Store::UNKNOWN_SERVER => [ 'cafebabe1357' => '/asked-half' ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', 'url:cafebabe1357' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'no aggregate for url cafebabe1357', \strtolower( $result ) );
	}

	public function test_a_span_absent_from_a_urls_aggregate_says_so(): void {
		$store = $this->stats_store( 0, 86400 );
		$this->set_url_stats( $store, 'cafebabe1357', [
			'flame' => [ 'name' => 'aggregate', 'value' => 300, 'count' => 3, 'children' => [ [ 'name' => 'init', 'value' => 10, 'children' => [] ] ] ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', 'url:cafebabe1357' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( "no span 'wp_loaded' in this url's aggregate", \strtolower( $result ) );
	}

	public function test_a_span_without_its_request_context_is_refused(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'span:wp_loaded' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'needs its request or its url', \strtolower( $result ) );
	}

	public function test_ask_assembles_a_url_brief(): void {
		$hash  = Log_Manager::url_hash( '/asked-url' );
		$store = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			$hash => [
				'url'       => '/asked-url',
				'count'     => 7,
				'timed_count' => 7, 'sum_ms'    => 6300.0,
				'last_seen' => 1700000000,
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hash}" );

		$this->assertIsArray( $result );
		$this->assertSame( 'url', $result['subject'] );
		$this->assertSame( 'https://example.com/asked-url', $result['url'] );
	}

	public function test_dump_request_verb_merges_flame_data_when_present(): void {
		// Rid must be ≤32 chars (fixed-width .idx field) so the lookup matches.
		$rid = $this->write_request( [
			'rid'            => 'rid-flame-123456789012345678901',
			'url'            => '/with-flame',
			'timestamp'      => 1700000600,
			'duration_ms'    => 12,
			'status_code'    => 200,
			'peak_mb'        => 1,
			'request_method' => 'GET',
		] );
		// Flame entry indexed by rid + url_hash; FlameBuilder writes the
		// flame body at Message::VALUE alongside the index entry.
		$url_hash = Log_Manager::url_hash( '/with-flame' );
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
			'url'            => '/with-deep-flame',
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
		$flame['url_hash'] = Log_Manager::url_hash( '/with-deep-flame' );
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
			'dump_request',
			'--partition=0'
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

	// ── urls verb fallbacks + server filter ─────────────────────────────────

	public function test_urls_verb_falls_back_to_defaults_on_invalid_sort_and_order(): void {
		// Out-of-whitelist sort/order silently fall back to count/desc.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/only-host', 'count' => 3, 'timed_count' => 3, 'sum_ms' => 30.0, 'last_seen' => 1700000001 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--sort=bogus --order=bogus'
		);

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertSame( 'https://example.com/only-host', $result['data'][0]['url'] );
	}

	public function test_urls_verb_sorts_ascending(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'aaaaaaaaaaaa' => [ 'url' => '/a', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 500.0, 'last_seen' => 1700000001 ],
			'bbbbbbbbbbbb' => [ 'url' => '/b', 'count' => 1, 'timed_count' => 1, 'sum_ms' => 100.0, 'last_seen' => 1700000002 ],
		] );

		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'urls',
			'--sort=count --order=asc'
		);

		// Ascending by count: /b (1) before /a (5).
		$this->assertSame( 'https://example.com/b', $result['data'][0]['url'] );
		$this->assertSame( 'https://example.com/a', $result['data'][1]['url'] );
	}

	// ── overview categories flag (string-valued flag) ───────────────────────

	public function test_overview_categories_flag_accepts_explicit_truthy_value(): void {
		// `--categories=1` exercises the string-valued flag resolution (not the
		// bare `--categories` true). The categories slice is added even with no data.
		$interpreter = new Performance_CI_Node();
		$result      = VerbHarness::fire(
			$interpreter,
			'performance',
			'overview',
			'--categories=1'
		);

		$this->assertArrayHasKey( 'category_time_series', $result );
	}

	// ── load_index bucket contract ──────────────────────────────────────────

	/**
	 * Each request reads the index for itself: two dispatches on two nodes read
	 * it twice over, once per shard each. Guards a PHP-static memo leaking stale
	 * stats across requests; the page cache is the one deliberate share, held
	 * in the cache backend, which `VerbHarness::reset()` drops between the two
	 * dispatches here.
	 */
	public function test_the_index_is_read_per_request_not_shared_across_instances(): void {
		$calls    = 0;
		$original = Performance_CI_Node::$load_index;
		Performance_CI_Node::$load_index = static function ( string $shard, string $server, array $stores, int $now, bool $errored ) use ( &$calls, $original ): array {
			++$calls;
			return ( $original ?? [ Performance_CI_Node::class, 'load_index_default' ] )( $shard, $server, $stores, $now, $errored );
		};

		try {
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
			VerbHarness::reset();
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		} finally {
			Performance_CI_Node::$load_index = $original;
			VerbHarness::reset();
		}

		$this->assertSame( \count( Stats_Store::url_shards() ) * 2, $calls );
	}

	// ── verb grammar: verb first ────────────────────────────────────────────

	public function test_search_requests_answers_a_rid_lookup(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', 'rid-renamed-7d3a' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_grep_requests_answers_a_pattern_scan(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'g7', 'k' => 'request', 'm' => 'GET /elsewhere', 'ts' => 1700000000.0, 'n' => 1 ],
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

	/** Each verb-first name replaces its noun-first one; the old name is refused, not aliased. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'noun_first_verbs' )]
	public function test_a_noun_first_verb_is_refused_as_an_unknown_command( string $old ): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', $old, 'renamed-arg' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( "unknown command: {$old}", $result );
	}

	// ── schema-driven dispatch ──────────────────────────────────────────────

	public function test_node_schema_lists_all_verbs_with_handlers(): void {
		$expected = [
			'overview', 'urls', 'dump_url', 'url_breakdown', 'search_requests',
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

	public function test_overview_verb_declares_optional_filters(): void {
		// overview reads server / breakdown / categories (all optional).
		$args = self::args_by_name( 'overview' );
		$this->assertSame( [ 'server', 'breakdown', 'categories' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['server']['type'] );
		$this->assertSame( 'string', $args['breakdown']['type'] );
		$this->assertSame( 'bool', $args['categories']['type'] );
		foreach ( $args as $arg ) {
			$this->assertFalse( $arg['required'] );
		}
	}

	public function test_ask_verb_declares_its_context_the_tail_of_the_descriptors(): void {
		// `ask <descriptor> [<context-descriptor>…]`: every positional after
		// the first binds to `context`, so the page's scope rides by name.
		$args = self::args_by_name( 'ask' );
		$this->assertSame( [ 'descriptor', 'context', 'server', 'search', 'errors_only', 'include_workers' ], \array_keys( $args ) );
		$this->assertTrue( $args['descriptor']['required'] );
		$this->assertTrue( $args['context']['variadic'] );
		$this->assertFalse( $args['errors_only']['default'] );
		$this->assertFalse( $args['include_workers']['default'] );
	}

	public function test_urls_verb_declares_sort_paging_filter_args(): void {
		// urls reads sort/order/limit/offset/search/server/errors_only and
		// include_workers — all optional. `include_workers` opts IN because its
		// default EXCLUDES, unlike `errors_only`, which opts in to narrow.
		$args = self::args_by_name( 'urls' );
		$this->assertSame(
			[ 'sort', 'order', 'limit', 'offset', 'search', 'server', 'errors_only', 'include_workers' ],
			\array_keys( $args )
		);
		$this->assertSame( 'bool', $args['include_workers']['type'] );
		$this->assertFalse( $args['include_workers']['default'] );
		$this->assertSame( 'bool', $args['errors_only']['type'] );
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

	public function test_dump_url_verb_declares_required_hash_plus_filters(): void {
		// dump_url requires hash (regex check throws on empty/bad) + optional
		// breakdown/server/categories/after. `server` scopes it the way it
		// scopes the table this modal opens from; `after` tails the request
		// list. A read-but-undeclared option is absent from `help`, from the
		// palette and from the MCP tools/list schema, so the list is pinned.
		$args = self::args_by_name( 'dump_url' );
		$this->assertSame( [ 'hash', 'breakdown', 'server', 'categories', 'after' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['hash']['type'] );
		$this->assertTrue( $args['hash']['required'] );
		$this->assertFalse( $args['breakdown']['required'] );
		$this->assertSame( 'string', $args['server']['type'] );
		$this->assertFalse( $args['server']['required'] );
		$this->assertSame( 'bool', $args['categories']['type'] );
		$this->assertFalse( $args['categories']['required'] );
		$this->assertSame( 'json', $args['after']['type'] );
		$this->assertFalse( $args['after']['required'] );
	}

	public function test_url_breakdown_verb_declares_both_of_its_arguments_required(): void {
		// Its whole answer is one dimension of one URL; neither has a default.
		$args = self::args_by_name( 'url_breakdown' );
		$this->assertSame( [ 'hash', 'breakdown' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['hash']['type'] );
		$this->assertTrue( $args['hash']['required'] );
		$this->assertSame( 'string', $args['breakdown']['type'] );
		$this->assertTrue( $args['breakdown']['required'] );
	}

	public function test_search_requests_verb_declares_required_rid(): void {
		// search_requests needs a rid to look up → required string.
		$args = self::args_by_name( 'search_requests' );
		$this->assertSame( [ 'rid' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['rid']['type'] );
		$this->assertTrue( $args['rid']['required'] );
	}

	public function test_dump_request_verb_declares_required_rid_optional_partition(): void {
		// dump_request needs a rid; partition defaults to 0.
		$args = self::args_by_name( 'dump_request' );
		$this->assertSame( [ 'rid', 'partition' ], \array_keys( $args ) );
		$this->assertTrue( $args['rid']['required'] );
		$this->assertSame( 'int', $args['partition']['type'] );
		$this->assertFalse( $args['partition']['required'] );
		$this->assertSame( 0, $args['partition']['default'] );
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

	/**
	 * The flame lookup fans across EVERY flame partition: the builder writes to
	 * whichever one it is wired into, so a flame for a p0 request can land in p2.
	 */
	public function test_dump_request_merges_a_flame_written_to_another_partition(): void {
		$this->activate_shipped( 'performance', 3 );
		$rid = $this->write_request( [
			'rid'            => 'rid-flame-elsewhere-00000000001',
			'url'            => '/flame-in-p2',
			'timestamp'      => 1700003100,
			'duration_ms'    => 27,
			'status_code'    => 200,
			'peak_mb'        => 4,
			'request_method' => 'GET',
		] );
		$this->write_flame(
			[
				'rid'      => $rid,
				'url_hash' => Log_Manager::url_hash( '/flame-in-p2' ),
				'flame'    => [ 'name' => 'request', 'value' => 27, 'children' => [] ],
			],
			2
		);

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );

		$this->assertIsArray( $result );
		$this->assertSame( 27, $result['flame_data']['flame']['value'] );
	}

	/**
	 * The recent-requests walk collects across every request partition, and the
	 * partition index it stamps is the one the entry was READ from.
	 */
	public function test_dump_url_collects_recent_requests_from_every_partition(): void {
		$this->activate_shipped( 'performance', 3 );
		$url   = '/spread-across-partitions';
		$hash  = Log_Manager::url_hash( $url );
		$now   = self::tick();
		$store = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 2, 'timed_count' => 2, 'sum_ms' => 61.0, 'last_seen' => $now - 742 ],
		] );
		$this->write_request(
			[
				'rid'            => 'rid-spread-p0-000000000000001',
				'url'            => $url,
				'timestamp'      => $now - 1409,
				'duration_ms'    => 29,
				'status_code'    => 200,
				'peak_mb'        => 2,
				'request_method' => 'GET',
			],
			0
		);
		$this->write_request(
			[
				'rid'            => 'rid-spread-p2-000000000000001',
				'url'            => $url,
				'timestamp'      => $now - 742,
				'duration_ms'    => 32,
				'status_code'    => 503,
				'peak_mb'        => 6,
				'request_method' => 'POST',
			],
			2
		);

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertCount( 2, $result['requests'] );
		$this->assertSame( 'rid-spread-p2-000000000000001', $result['requests'][0]['rid'] );
		$this->assertSame( 2, $result['requests'][0]['partition'] );
		$this->assertSame( 0, $result['requests'][1]['partition'] );
	}

	/**
	 * The loader emits the recency fields under the names the projection and the
	 * dashboard read — `recent_count` / `last_updated`, never the bucket's own
	 * `recent` / `last_seen` — plus the `aggregate` flag and a defaulted min_ms.
	 */
	public function test_the_index_row_carries_the_projections_field_names(): void {
		$recent_bucket = Stats_Store::retention_buckets( 86400, self::tick() )[1];
		$store         = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, $recent_bucket, [
			'c0ffee123456' => [
				'url'         => '/named-fields',
				'count'       => 9,
				'timed_count' => 9,
				'sum_ms'      => 333.0,
				'min_ms'      => 17,
				'last_seen'   => 1711111111,
			],
		] );

		$rows = Performance_CI_Node::load_index_default( Stats_Store::url_shard( 'c0ffee123456' ), '', $this->live_stores(), (int) Core::$now, false );

		$row = \array_values( \array_filter( $rows, static fn ( $r ) => 'c0ffee123456' === $r['hash'] ) )[0] ?? null;
		$this->assertIsArray( $row );
		$this->assertSame( 1711111111, $row['last_updated'] );
		$this->assertSame( 9, $row['recent_count'] );
		$this->assertSame( 17.0, $row['min_ms'] );
		$this->assertFalse( $row['aggregate'] );
		$this->assertArrayNotHasKey( 'last_seen', $row, 'the bucket name must not reach the projection' );
		$this->assertArrayNotHasKey( 'recent', $row, 'the bucket name must not reach the projection' );
	}

	/**
	 * One harness store per partition the active topology declares, the
	 * stores a verb would mount, for a test calling the loader directly.
	 *
	 * @return list<Stats_Store>
	 */
	private function live_stores(): array {
		$stores = [];
		foreach ( \array_keys( \Newspack_Nodes\Bootstrap::node_tables( Stats_Store::TABLE_AGGREGATE )[ Stats_Store::TABLE_AGGREGATE ] ) as $p ) {
			$stores[] = $this->stats_store( $p, \Newspack_Event_Logger_Nodes\Config::stats_retention_seconds() );
		}
		return $stores;
	}

	/** The read window the loader plans over. */
	private static function read_window_for_test(): array {
		$m = new \ReflectionMethod( Performance_CI_Node::class, 'read_window' );
		return (array) $m->invoke( null, (int) Core::$now );
	}

	/**
	 * Every shard's overflow row shares ONE key, so they collapse into one row.
	 *
	 * `Stats_Store::other_key()` is `Other` / `Other:worker` for every shard, so
	 * the old whole-index fold merged all sixteen implicitly — decision 17 says
	 * so: "a merge keyed on the url_hash would collapse sixteen of them into
	 * one". Folding per shard loses that unless the overflow is accumulated
	 * across shards, and the table showed fourteen identical rows.
	 */
	public function test_the_overflow_rows_collapse_across_shards(): void {
		$original = Performance_CI_Node::$load_index;
		$row      = [
			'hash'         => Stats_Store::OTHER_KEY,
			'url'          => '',
			'aggregate'    => true,
			'worker'       => false,
			'count'        => 10,
			'timed_count'  => 10,
			'count_2xx'    => 10,
			'count_3xx'    => 0,
			'count_4xx'    => 0,
			'count_5xx'    => 0,
			'sum_ms'       => 100.0,
			'min_ms'       => 2.0,
			'max_ms'       => 9.0,
			'sum_peak_mb'  => 1.0,
			'max_peak_mb'  => 1.0,
			'recent_count' => 2,
			'last_updated' => 1711111111,
		];

		Performance_CI_Node::$load_index = static function ( string $shard ) use ( $row ): array {
			return \in_array( $shard, [ '0', '1', '3' ], true ) ? [ $row ] : [];
		};
		try {
			$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		} finally {
			Performance_CI_Node::$load_index = $original;
			VerbHarness::reset();
		}

		$others = \array_values( \array_filter(
			$reply['data'],
			static fn ( array $r ): bool => ! empty( $r['aggregate'] )
		) );

		$this->assertCount( 1, $others, 'the overflow rows are one row, not one per shard' );
		$this->assertSame( 30, $others[0]['count'], 'and it carries every shard it stood for' );
		$this->assertSame( 1, $reply['rows'], 'the pager counts it once' );
	}

	/**
	 * The `urls` verb folds ONE SHARD AT A TIME and never the whole index.
	 *
	 * A url_hash's shard is its first hex digit, so shards are disjoint and a
	 * per-shard fold is complete for every URL it holds. The merged index is
	 * otherwise the count of distinct URLs across the retention window, which
	 * nothing bounds — a production hub exhausted 512MB inside the fold itself,
	 * after three releases had removed three real duplicate COPIES.
	 */
	public function test_the_urls_verb_folds_one_shard_at_a_time(): void {
		$seen     = [];
		$original = Performance_CI_Node::$load_index;

		Performance_CI_Node::$load_index = static function ( string $shard, string $server, array $stores, int $now, bool $errored ) use ( &$seen, $original ): array {
			$seen[] = $shard;
			return ( $original ?? [ Performance_CI_Node::class, 'load_index_default' ] )( $shard, $server, $stores, $now, $errored );
		};
		try {
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		} finally {
			Performance_CI_Node::$load_index = $original;
			VerbHarness::reset();
		}

		$this->assertNotContains( null, $seen, 'the whole index must never be read at once' );
		$this->assertSame(
			Stats_Store::url_shards(),
			\array_values( \array_unique( $seen ) ),
			'every shard is folded, one at a time'
		);
	}

	/**
	 * One `urls` call resolves its stats stores ONCE, not once per shard.
	 *
	 * Each resolution asks the substrate for the flame-builder workers, which
	 * builds the whole topology catalog; sixteen shards asked sixteen times,
	 * and every dashboard poll paid for it. Counted at the catalog filter, so
	 * a regression in either repo shows here: one, for the worker set.
	 */
	public function test_the_urls_verb_does_not_rebuild_the_catalog_per_shard(): void {
		[ $builds, $read, , $store_sets ] = $this->count_catalog_builds_for_urls();

		$this->assertSame( 1, $builds );
		$this->assertSame( \array_fill( 0, \count( Stats_Store::url_shards() ), 3 ), $read );
		$this->assertSame( 1, $store_sets, 'every shard reads through the stores the verb built once' );
	}

	/**
	 * A search, sorted by URL, names every candidate the token index gave it
	 * and then names the page it returns. Both reads take the stores the verb
	 * already resolved, so the catalog is still built once.
	 */
	public function test_a_url_sorted_search_does_not_rebuild_the_catalog_per_shard(): void {
		[ $builds, , $reply ] = $this->count_catalog_builds_for_urls( '--search=wombat-7731', '--sort=url' );

		$this->assertSame( 1, $reply['rows'], 'the search reached the seeded row' );
		$this->assertSame( 'https://kea.test/wombat-7731', $reply['data'][0]['url'] );
		$this->assertSame( 1, $builds );
	}

	/**
	 * One fold per page per minute, however many tabs poll: a second `urls`
	 * call carrying the same filters inside the cache's life reads no shard,
	 * and a call carrying different ones folds again.
	 */
	public function test_a_repeated_urls_page_is_served_without_a_fold(): void {
		$this->activate_shipped( 'performance', 3 );
		$this->seed_folded_hours();
		$this->set_url_bucket( $this->stats_store( 1, 86400 ), $this->current_url_bucket(), [
			'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
			'c8842df1ab90' => [ 'url' => 'https://kea.test/kiwi-8842', 'count' => 3, 'last_seen' => self::tick() ],
		] );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$first  = $fire( '--sort=count', '--order=desc', '--limit=100' );
			$folded = $reads();
			$second = $fire( '--sort=count', '--order=desc', '--limit=100' );
			$this->assertGreaterThan( 0, $folded, 'the first page folds the index' );
			$this->assertSame( $folded, $reads(), 'the second page folds nothing' );
			$this->assertSame( $first['data'], $second['data'] );
			$this->assertSame( 2, $first['rows'] );
			$fire( '--sort=avg_ms', '--order=desc', '--limit=100' );
			$this->assertGreaterThan( $folded, $reads(), 'another sort is another page' );
			// A filter is another page too, and a hit must be THAT page.
			$this->forget_stats_asks();
			$searched = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=wombat-7731' );
			$this->assertNotSame( [], $this->asked_keys( Stats_Store::NS_URL_ROW_HOUR ), 'a search reads its rows' );
			$this->assertSame( 1, $searched['rows'], 'and answers the search, not the cached page' );
		} finally {
			$restore();
		}
	}

	public function test_the_widest_cached_urls_page_fits_the_item_budget(): void {
		// The widest page the cache stores, of the widest rows a reply can
		// name: a 259-byte host, a 1,024-byte path, every number at length.
		$server = \str_pad( 'srv.', 259, 'x' );
		$wide   = -1.2345678901234567E+300;
		$rows   = [];
		for ( $i = 0; $i < 300; $i++ ) {
			$count = 999_999_999_999 - $i;
			$rows[ \sprintf( '%012x', $i ) ] = [
				'url'   => "https://{$server}" . \str_pad( "/{$i}/", Stats_Store::MAX_PATH_BYTES, 'x' ),
				'count' => $count, 'timed_count' => $count, 'count_2xx' => $count, 'count_3xx' => $count,
				'count_4xx' => $count, 'count_5xx' => $count, 'sum_ms' => $wide, 'sum_peak_mb' => $wide,
				'min_ms' => $wide, 'max_ms' => $wide, 'max_peak_mb' => $wide, 'last_seen' => self::tick(),
			];
		}
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $this->current_url_bucket(), $rows, $server );
		$this->seed_folded_hours();

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--sort=count --order=desc --limit=250' );

		$this->assertCount( 250, $page['data'] );
		$cached = 0;
		foreach ( Core::$memd->keys() as $key ) {
			if ( ! \str_contains( $key, 'eln-urls-page' ) ) {
				continue;
			}
			$value = Core::$memd->get( $key );
			++$cached;
			$this->assertLessThanOrEqual( Stats_Store::ITEM_BUDGET, \strlen( \serialize( $value ) ), "{$key}, serialize()" );
			if ( \function_exists( 'igbinary_serialize' ) ) {
				$this->assertLessThanOrEqual( Stats_Store::ITEM_BUDGET, \strlen( (string) \igbinary_serialize( $value ) ), "{$key}, igbinary" );
			}
		}
		$this->assertGreaterThan( 0, $cached, 'the page was cached' );
	}

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

	public function test_a_urls_reply_dates_itself_from_the_substrate_clock(): void {
		$this->activate_shipped( 'performance', 3 );
		// A second already PAST, and a stride no fallback here uses: the real
		// clock never returns it again, so a `self::tick()` left anywhere on this
		// path dates the reply as something else.
		$pinned   = self::tick() - 1234;
		$previous = Core::$clock;
		$ticked   = Core::$now;
		// The substrate's own clock, pinned the way a drain tick pins it:
		// `right_now()` is what WRITES `Core::$now`, and the reader takes that.
		Core::$clock = static fn (): float => (float) $pinned;
		Core::right_now();
		try {
			$this->set_url_bucket( $this->stats_store( 1, 86400 ), Stats_Store::bucket_key( $pinned ), [
				'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => $pinned ],
			] );
			$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--limit=100' ] );
			$this->assertIsArray( $reply );
			$this->assertSame( $pinned, $reply['as_of'] );
		} finally {
			Core::$clock = $previous;
			Core::$now   = $ticked;
		}
	}

	public function test_a_header_hole_answers_from_its_fold_and_the_next_page_reads_the_lists(): void {
		$this->activate_shipped( 'performance', 3 );
		// Two commands date themselves independently, so pin the clock both
		// read: unpinned, a page built either side of a second reports two.
		$now      = self::tick();
		$previous = Core::$clock;
		$ticked   = Core::$now;
		Core::$clock = static fn (): float => (float) $now;
		Core::right_now();
		try {
			$store  = $this->stats_store( 1, 86400 );
			// Older than the bucket just closed, whose missing record is lag.
			$bucket = Stats_Store::bucket_key( $now - 2 * Stats_Store::BUCKET_SECONDS );
			$this->set_url_bucket( $store, $bucket, [
				'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => $now ],
				'c8842df1ab90' => [ 'url' => 'https://kea.test/kiwi-8842', 'count' => 3, 'last_seen' => $now ],
			] );
			// The list deliberately disagrees with the rows, so the reply says which it read.
			$this->set_url_rank_lists( $store, $bucket, [
				'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 9, 'last_seen' => $now ],
			] );
			$this->seed_hour_lists();
			// The bucket's lists stand and its site record is gone: a hole.
			$store->bucket_forget_multi( [ [ Stats_Store::url_header_parts( '', false ), $bucket ] ] );
			[ $fire, $reads, $restore ] = $this->counting_urls_fire();
			try {
				// The header had to be folded, and that fold IS this page: the
				// poll that pays for the walk answers with what it walked.
				$first = $fire( '--sort=count', '--order=desc', '--limit=100' );
				$this->assertFalse( $first['ranked'] );
				$this->assertSame( 5, $first['data'][0]['count'], 'the rows, not the lists' );
				$this->assertSame( 8, $first['totals']['requests'] );
				$this->assertSame( 2, $first['rows'] );
				$this->assertCount( 2, $first['slowest'] );
				$this->assertSame( $now, $first['as_of'] );
				$header_reads = $reads();
				$this->assertGreaterThan( 0, $header_reads );

				// The header holds for the bucket; the next page's key differs, so it is no cache hit.
				$second = $fire( '--sort=count', '--order=desc', '--limit=50' );
				$this->assertTrue( $second['ranked'] );
				$this->assertSame( [ 'b7731ce0fa11' ], \array_column( $second['data'], 'hash' ) );
				$this->assertSame( 9, $second['data'][0]['count'], 'the lists answer the table' );
				$this->assertSame( 'https://kea.test/wombat-7731', $second['data'][0]['url'] );
				$this->assertSame( 8, $second['totals']['requests'], 'the header is still the fold' );
				$this->assertSame( 2, $second['rows'] );
				$this->assertSame( $header_reads, $reads(), 'the ranked page folds nothing' );
				$this->assertSame( $first['as_of'], $second['as_of'] );

				$searched = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=wombat' );
				$this->assertFalse( $searched['ranked'] );
				$this->assertSame( 5, $searched['data'][0]['count'], 'a search reads the rows' );
			} finally {
				$restore();
			}
		} finally {
			Core::$clock = $previous;
			Core::$now   = $ticked;
		}
	}

	/**
	 * Seed two buckets as the writer leaves them — rows, index, lists and
	 * header records — the older one inside the last-hour rate, plus every
	 * planned hour, and pin the clock both commands read.
	 *
	 * @param int|null $now The clock to pin; null pins the tick.
	 * @return array{0: Stats_Store, 1: string, 2: \Closure(): void} The store,
	 *         the older bucket, and the clock's restore.
	 */
	private function seed_recorded_window( ?int $now = null ): array {
		$this->activate_shipped( 'performance', 3 );
		$now    ??= self::tick();
		$previous = Core::$clock;
		$ticked   = Core::$now;
		Core::$clock = static fn (): float => (float) $now;
		Core::right_now();
		$store   = $this->stats_store( 1, 86400 );
		$recent  = Stats_Store::bucket_key( $now - Stats_Store::BUCKET_SECONDS );
		$wombat  = [ 'url' => 'https://kea.test/wombat-7731', 'last_seen' => $now ];
		$buckets = [
			$recent                         => [
				'b7731ce0fa11'         => $wombat + [ 'count' => 17, 'timed_count' => 13, 'sum_ms' => 390.0, 'sum_peak_mb' => 51.0 ],
				Stats_Store::OTHER_KEY => [ 'count' => 6, 'timed_count' => 4, 'sum_ms' => 20.0, 'sum_peak_mb' => 3.0, 'last_seen' => $now ],
			],
			Stats_Store::bucket_key( $now ) => [
				'c8842df1ab90' => [ 'url' => 'https://kea.test/kiwi-8842', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 1000.0, 'sum_peak_mb' => 10.0, 'last_seen' => $now ],
				'b7731ce0fa11' => $wombat + [ 'count' => 2, 'timed_count' => 2, 'sum_ms' => 60.0, 'sum_peak_mb' => 4.0 ],
			],
		];
		foreach ( $buckets as $bucket => $rows ) {
			$this->set_url_bucket( $store, $bucket, $rows );
			$this->set_url_rank_lists( $store, $bucket, $rows );
		}
		$this->seed_hour_lists();
		return [
			$store,
			$recent,
			static function () use ( $previous, $ticked ): void {
				Core::$clock = $previous;
				Core::$now   = $ticked;
			},
		];
	}

	public function test_the_url_header_sums_the_writer_s_records_and_folds_nothing(): void {
		[ , , $unpin ]              = $this->seed_recorded_window();
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=50' );

			$this->assertSame( 0, $reads(), 'no shard of the index was read' );
			$this->assertTrue( $page['ranked'] );
			$this->assertTrue( $page['estimated'], 'the URL count is the sketch\'s' );
			$this->assertFalse( $page['provisional'], 'every key\'s record stood' );
			$this->assertSame( 2, $page['totals']['urls'], 'wombat-7731 counts once across its two buckets' );
			$this->assertSame( 3, $page['rows'], 'and the Other row is a row' );
			$this->assertSame( 30, $page['totals']['requests'] );
			$this->assertEqualsWithDelta( 1470.0 / 24, $page['totals']['avg_ms'], 0.0001 );
			$this->assertEqualsWithDelta( 68.0 / 30, $page['totals']['avg_peak_mb'], 0.0001 );
			$this->assertEqualsWithDelta( 23 / ( 7 * Stats_Store::BUCKET_SECONDS ), $page['totals']['requests_per_second'], 0.0001, 'the closed bucket alone is inside the rate' );
			$this->assertSame( [ 'c8842df1ab90', 'b7731ce0fa11' ], \array_column( $page['slowest'], 'hash' ) );
			$this->assertEqualsWithDelta( 450.0 / 15, $page['slowest'][1]['avg_ms'], 0.0001, 'weighted by request across the lists it made' );
			$this->assertSame( 'https://kea.test/kiwi-8842', $page['slowest'][0]['url'] );
		} finally {
			$restore();
			$unpin();
		}
	}

	/**
	 * The tick's bucket, `$seconds` into it: a clock a test pins either side
	 * of the writer's first flush after a close.
	 */
	private static function into_bucket( int $seconds ): int {
		return self::tick() - self::tick() % Stats_Store::BUCKET_SECONDS + $seconds;
	}

	/** @return array<string,array{0:int}> */
	public static function buckets_behind_the_lag(): array {
		return [
			'fine[2]' => [ 2 ],
			'fine[3]' => [ 3 ],
		];
	}

	#[DataProvider( 'buckets_behind_the_lag' )]
	public function test_a_record_missing_from_an_older_bucket_takes_the_header_back_to_the_fold( int $back ): void {
		// Behind the bucket just closed the writer owes no ranking: a hole.
		[ $store, , $unpin ] = $this->seed_recorded_window();
		$older = Stats_Store::bucket_key( self::tick() - $back * Stats_Store::BUCKET_SECONDS );
		$tuatara = [ 'd6d6d6d6d6d6' => [ 'url' => 'https://kea.test/tuatara-19', 'count' => 19, 'last_seen' => self::tick() ] ];
		$this->set_url_bucket( $store, $older, $tuatara );
		$this->set_url_rank_lists( $store, $older, $tuatara );
		$store->bucket_forget_multi( [ [ Stats_Store::url_header_parts( '', false ), $older ] ] );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=50' );

			$this->assertGreaterThan( 0, $reads(), 'a window summed short would read as the site\'s' );
			$this->assertFalse( $page['ranked'] );
			$this->assertFalse( $page['estimated'], 'the fold counts exactly' );
			$this->assertFalse( $page['provisional'] );
			$this->assertSame( 3, $page['totals']['urls'] );
			$this->assertSame( 30 + 19, $page['totals']['requests'] );
		} finally {
			$restore();
			$unpin();
		}
	}

	public function test_a_record_missing_from_the_bucket_just_closed_is_provisional_and_never_cached(): void {
		// However late the writer's ranking of a close runs, a poll 30 seconds
		// in is served without the record, says so, and stores nothing.
		[ $store, $recent, $unpin ] = $this->seed_recorded_window( self::into_bucket( 30 ) );
		$kereru = [ 'e9e9e9e9e9e9' => [ 'url' => 'https://tui.test/kereru-43', 'count' => 43, 'last_seen' => self::tick() ] ];
		$this->set_url_bucket( $store, $recent, $kereru, 'tui.test' );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=50', '--server=tui.test' );
			$this->assertSame( 0, $reads(), 'no fold' );
			$this->assertTrue( $page['provisional'] );
			$this->assertSame( 0, $page['totals']['requests'] );

			// The ranking lands; the next poll reads it rather than a cache.
			$this->set_url_rank_lists( $store, $recent, $kereru, false, 'tui.test' );
			$page = $fire( '--sort=count', '--order=desc', '--limit=50', '--server=tui.test' );
			$this->assertFalse( $page['provisional'] );
			$this->assertSame( 43, $page['totals']['requests'] );
			$this->assertSame( 0, $reads() );
		} finally {
			$restore();
			$unpin();
		}
	}

	public function test_a_server_the_site_record_has_yet_to_sum_leaves_the_header_exact_for_a_minute(): void {
		// Tui files into the open bucket after its ranking; the site record
		// still stands, so the header is no lag, and lives one refresh.
		[ $store, , $unpin ] = $this->seed_recorded_window();
		$this->set_url_bucket( $store, Stats_Store::bucket_key( self::tick() ), [
			'e9e9e9e9e9e9' => [ 'url' => 'https://tui.test/kereru-43', 'count' => 43, 'last_seen' => self::tick() ],
		], 'tui.test' );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=50' );

			$this->assertSame( 0, $reads(), 'no fold' );
			$this->assertFalse( $page['provisional'] );
			$this->assertSame( 30, $page['totals']['requests'], 'short by tui until the next ranking' );
			$memd  = Core::$memd;
			$lives = [];
			foreach ( $memd instanceof InMemoryMemcached ? $memd->expiries() : [] as $key => $expires ) {
				if ( \str_contains( (string) $key, 'eln-urls-page' ) ) {
					$lives[] = $expires - \time();
				}
			}
			$this->assertNotEmpty( $lives, 'the header was stored' );
			$this->assertLessThanOrEqual( Stats_Store::URL_PAGE_REFRESH_S, \max( $lives ), 'for one refresh, not the bucket' );
		} finally {
			$restore();
			$unpin();
		}
	}

	public function test_a_header_reads_each_store_s_server_index_once(): void {
		// The records and the slowest lists all ask the same keys' index; one
		// reply reads it once a store, one ask per Table its tiers live in.
		[ , , $unpin ]        = $this->seed_recorded_window();
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$this->forget_stats_asks();
			$page = $fire( '--sort=count', '--order=desc', '--limit=50' );

			$this->assertTrue( $page['ranked'] );
			$this->assertSame( 3 * 2, \count( $this->asked_batches( Stats_Store::NS_URLSRV, Stats_Store::NS_URLSRV_HOUR ) ), 'the plan\'s keys a store; the lists re-read nothing' );
		} finally {
			$restore();
			$unpin();
		}
	}

	public function test_a_server_new_to_the_open_bucket_is_ranking_lag_not_a_hole(): void {
		// Its rows name it in the open bucket's index, and its record waits
		// for a ranking that runs once a minute; the lists wait alike.
		[ $store, , $unpin ] = $this->seed_recorded_window();
		$this->set_url_bucket( $store, Stats_Store::bucket_key( self::tick() ), [
			'e9e9e9e9e9e9' => [ 'url' => 'https://tui.test/kereru-41', 'count' => 41, 'last_seen' => self::tick() ],
		], 'tui.test' );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=50', '--server=tui.test' );

			$this->assertSame( 0, $reads(), 'no fold, and no fold cached for the bucket' );
			$this->assertTrue( $page['estimated'] );
			$this->assertTrue( $page['provisional'], 'short by what the writer has yet to rank' );
			$this->assertTrue( $page['ranked'] );
			$this->assertSame( 0, $page['totals']['requests'], 'lagging the open bucket, as the lists do' );
		} finally {
			$restore();
			$unpin();
		}
	}

	public function test_the_overview_brief_says_its_url_count_is_an_estimate(): void {
		[ , , $unpin ] = $this->seed_recorded_window();
		try {
			$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site' );

			$this->assertSame( 2, $brief['stats']['urls'] );
			$this->assertTrue( $brief['estimated'] ?? null, 'the sketch\'s count, which the brief must not state as exact' );
			$this->assertFalse( $brief['provisional'] ?? null );
		} finally {
			$unpin();
		}
	}

	public function test_a_page_past_the_list_depth_folds(): void {
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ] ] );
		$this->set_url_rank_lists( $store, $bucket, [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 9, 'last_seen' => self::tick() ] ] );
		$this->seed_hour_lists();
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$this->warm_url_header( $fire );
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--offset=' . ( Stats_Store::URL_RANK_N - 100 ) );
			$this->assertTrue( $page['ranked'] );
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--offset=' . ( Stats_Store::URL_RANK_N - 99 ) );
			$this->assertFalse( $page['ranked'] );
		} finally {
			$restore();
		}
	}

	public function test_a_ranked_page_presents_the_mean_of_bucket_averages(): void {
		$this->activate_shipped( 'performance', 3 );
		$store = $this->stats_store( 1, 86400 );
		$now   = self::tick();
		$newer = Stats_Store::bucket_key( $now );
		$older = Stats_Store::bucket_key( $now - 300 );
		$row   = static fn ( int $count, float $sum_ms ): array => [
			'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => $count, 'timed_count' => $count, 'sum_ms' => $sum_ms, 'last_seen' => 1758500000 ],
		];
		// One slow request, then nine fast ones: request-weighted 19 ms, bucket-weighted 55 ms.
		$this->set_url_bucket( $store, $older, $row( 1, 100.0 ) );
		$this->set_url_bucket( $store, $newer, $row( 9, 90.0 ) );
		$this->set_url_rank_lists( $store, $older, $row( 1, 100.0 ) );
		$this->set_url_rank_lists( $store, $newer, $row( 9, 90.0 ) );
		$this->seed_hour_lists();
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$this->warm_url_header( $fire );
			$page = $fire( '--sort=avg_ms', '--order=desc', '--limit=100' );
			$this->assertTrue( $page['ranked'] );
			$this->assertSame( 10, $page['data'][0]['count'], 'sums still sum' );
			$this->assertEqualsWithDelta( 55.0, $page['data'][0]['avg_ms'], 0.001 );
			$this->assertEqualsWithDelta( 19.0, $page['totals']['avg_ms'], 0.001, 'the header stays request-weighted' );
		} finally {
			$restore();
		}
	}

	public function test_an_hour_list_stands_in_for_its_buckets(): void {
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$folded = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) )['hours'][1];
		// Every other store gets the writer's empty list for the folded hour;
		// this one's is overwritten with content below.
		$this->seed_hour_lists();
		$this->set_url_rank_lists( $store, $folded, [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 6, 'last_seen' => 1758500000 ] ], true );
		// A bucket inside the folded hour must NOT count twice.
		$this->set_url_rank_lists( $store, Stats_Store::buckets_in_hour( $folded )[3], [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 6, 'last_seen' => 1758500000 ] ] );
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$this->warm_url_header( $fire );
			$page = $fire( '--sort=count', '--order=desc', '--limit=100' );
			$this->assertTrue( $page['ranked'] );
			$this->assertSame( [ 'b7731ce0fa11' => 6 ], \array_column( $page['data'], 'count', 'hash' ) );
		} finally {
			$restore();
		}
	}

	public function test_a_scoped_ranked_page_reads_the_servers_lists(): void {
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		// Two servers on one hash: the site's count (4) is not moa.test's (3),
		// so a page that read the site's list would give this a false pass.
		$kea = [
			'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
			'c8842df1ab90' => [ 'url' => 'https://moa.test/kiwi-8842', 'count' => 1, 'last_seen' => self::tick() ],
		];
		$moa = [ 'c8842df1ab90' => [ 'url' => 'https://moa.test/kiwi-8842', 'count' => 3, 'last_seen' => self::tick() ] ];
		$this->set_url_bucket( $store, $bucket, $kea, 'kea.test' );
		$this->set_url_bucket( $store, $bucket, $moa, 'moa.test' );
		$this->set_url_rank_lists( $store, $bucket, [ 'b7731ce0fa11' => $kea['b7731ce0fa11'], 'c8842df1ab90' => [ 'url' => 'https://moa.test/kiwi-8842', 'count' => 4, 'last_seen' => self::tick() ] ] );
		$this->set_url_rank_lists( $store, $bucket, $moa, false, 'moa.test' );
		$this->seed_hour_lists( [], 'moa.test' );
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$this->warm_url_header( $fire, '--server=moa.test' );
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--server=moa.test' );
			$this->assertTrue( $page['ranked'] );
			$this->assertSame( [ 'c8842df1ab90' ], \array_column( $page['data'], 'hash' ) );
			$this->assertSame( 3, $page['data'][0]['count'], 'the server\'s own list, not the site\'s count' );
			$this->assertSame( 3, $page['totals']['requests'] );
			// No index names tui.test: idle in every key, lists and records alike.
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--server=tui.test' );
			$this->assertTrue( $page['ranked'] );
			$this->assertSame( [], $page['data'] );
			$this->assertSame( 0, $page['totals']['requests'] );
		} finally {
			$restore();
		}
	}

	public function test_a_scoped_ranked_page_is_served_over_hours_that_name_other_servers(): void {
		// Every hour names moa.test alone. kea.test served none of them, so
		// its lists there are empty rather than missing, and its page ranks.
		$this->activate_shipped( 'performance', 1 );
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$kea    = [ 'a4410ce0fa19' => [ 'url' => 'https://kea.test/kereru-41', 'count' => 41, 'last_seen' => self::tick() ] ];
		$this->set_url_bucket( $store, $bucket, $kea, 'kea.test' );
		$this->set_url_rank_lists( $store, $bucket, $kea, false, 'kea.test' );
		$this->seed_hour_lists( [], 'moa.test', 1 );
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$this->warm_url_header( $fire, '--server=kea.test' );
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--server=kea.test' );
			$this->assertTrue( $page['ranked'], 'served ranked, not folded' );
			$this->assertSame( [ 'a4410ce0fa19' ], \array_column( $page['data'], 'hash' ) );
		} finally {
			$restore();
		}
	}

	public function test_the_site_ranked_page_is_what_one_list_over_every_server_gives(): void {
		// The writer merges the servers' lists into the site's, and has to
		// cut each bucket where a list over all of them would. 301 URLs in
		// the older bucket: moa.test's list keeps its heaviest, the one a
		// site-wide `count asc` list cuts, so a union left uncut would add
		// its 9999 requests to the newer bucket's one.
		$this->activate_shipped( 'performance', 3 );
		$store = $this->stats_store( 1, 86400 );
		$now   = self::tick();
		$older = Stats_Store::bucket_key( $now - 300 );
		$newer = Stats_Store::bucket_key( $now );
		$heavy = 'f0f0f0f0f0f0';
		$kea   = [];
		$moa   = [ $heavy => [ 'url' => 'https://moa.test/heavy-9999', 'count' => 9999, 'last_seen' => $now - 300 ] ];
		for ( $i = 0; $i < 150; $i++ ) {
			$kea[ \sprintf( 'a%011x', $i ) ] = [ 'url' => "https://kea.test/k-{$i}", 'count' => 100 + $i, 'last_seen' => $now - 300 ];
			$moa[ \sprintf( 'b%011x', $i ) ] = [ 'url' => "https://moa.test/m-{$i}", 'count' => 100 + $i, 'last_seen' => $now - 300 ];
		}
		$this->set_url_rank_lists_of( $store, $older, [ 'kea.test' => $kea, 'moa.test' => $moa ] );
		$this->set_url_rank_lists( $store, $newer, [ $heavy => [ 'url' => 'https://moa.test/heavy-9999', 'count' => 1, 'last_seen' => $now ] ], false, 'moa.test' );
		$this->set_url_bucket( $store, $newer, [ $heavy => [ 'url' => 'https://moa.test/heavy-9999', 'count' => 1, 'last_seen' => $now ] ], 'moa.test' );
		$this->seed_hour_lists();
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$this->warm_url_header( $fire );
			$page = $fire( '--sort=count', '--order=asc', '--limit=3' );
			$this->assertTrue( $page['ranked'] );
			$this->assertSame(
				[ $heavy => 1, 'a00000000000' => 100, 'b00000000000' => 100 ],
				\array_column( $page['data'], 'count', 'hash' ),
				'the older bucket\'s heaviest is cut, and a tie breaks by hash'
			);
		} finally {
			$restore();
		}
	}

	public function test_a_site_search_reads_the_tokens_of_every_server_the_plan_names(): void {
		// Each server files its own token rows, so a site search that read
		// one server's would miss the rest.
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'a1ce0fa11b77' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
		], 'kea.test' );
		$this->set_url_bucket( $store, $bucket, [
			'b2df1ab90c88' => [ 'url' => 'https://moa.test/wombat-8842', 'count' => 3, 'last_seen' => self::tick() ],
		], 'moa.test' );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--search=wombat' );
		$this->assertSame( [ 'a1ce0fa11b77', 'b2df1ab90c88' ], \array_column( $page['data'], 'hash' ) );
	}

	public function test_a_scoped_search_reads_that_servers_tokens_alone(): void {
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'a1ce0fa11b77' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
		], 'kea.test' );
		$this->set_url_bucket( $store, $bucket, [
			'b2df1ab90c88' => [ 'url' => 'https://moa.test/wombat-8842', 'count' => 3, 'last_seen' => self::tick() ],
		], 'moa.test' );
		$this->forget_stats_asks();

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--search=wombat --server=moa.test' );

		$this->assertSame( [ 'b2df1ab90c88' ], \array_column( $page['data'], 'hash' ) );
		$tokens = \array_merge( ...$this->asked_verbs( Stats_Store::NS_URLTOKEN )['SMEMBERS'] ?? [ [] ] );
		$this->assertNotEmpty( $tokens );
		foreach ( $tokens as $key ) {
			$this->assertMatchesRegularExpression( '/^' . Stats_Store::NS_URLTOKEN . ':\d{4}-\d\d-\d\d-\d\d:' . Stats_Store::server_key( 'moa.test' ) . ':/', $key );
		}
	}

	public function test_a_site_wide_search_asks_one_members_exchange_per_store_for_every_word_of_every_server(): void {
		// Each server files its own sets, but one store's are one Table:
		// two words of two servers are four keys in a single SMEMBERS, and a
		// second partition's store asks its own server's in one more.
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$other  = $this->stats_store( 2, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'a1ce0fa11b77' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
		], 'kea.test' );
		$this->set_url_bucket( $store, $bucket, [
			'b2df1ab90c88' => [ 'url' => 'https://moa.test/wombat-7731', 'count' => 3, 'last_seen' => self::tick() ],
		], 'moa.test' );
		$this->set_url_bucket( $other, $bucket, [
			'c3ea2bc01d99' => [ 'url' => 'https://tui.test/wombat-7731', 'count' => 2, 'last_seen' => self::tick() ],
		], 'tui.test' );
		$this->forget_stats_asks();

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=wombat 7731' ] );

		$this->assertEqualsCanonicalizing( [ 'a1ce0fa11b77', 'b2df1ab90c88', 'c3ea2bc01d99' ], \array_column( $page['data'], 'hash' ) );
		$verbs = $this->asked_verbs( Stats_Store::NS_URLTOKEN );
		$this->assertSame( [ 'SMEMBERS' ], \array_keys( $verbs ) );
		$key     = static fn ( string $server, string $word ): array => \array_map(
			static fn ( string $bucket ): string => Stats_Store::key( Stats_Store::NS_URLTOKEN, $bucket, Stats_Store::server_key( $server ), $word ),
			$store->token_buckets( self::tick() )
		);
		$batches = \array_map(
			static function ( array $keys ): array {
				\sort( $keys );
				return $keys;
			},
			$verbs['SMEMBERS']
		);
		\usort( $batches, static fn ( array $a, array $b ): int => \count( $a ) <=> \count( $b ) );
		$expected = [
			[ ...$key( 'tui.test', 'wombat' ), ...$key( 'tui.test', '7731' ) ],
			[ ...$key( 'kea.test', 'wombat' ), ...$key( 'kea.test', '7731' ), ...$key( 'moa.test', 'wombat' ), ...$key( 'moa.test', '7731' ) ],
		];
		foreach ( $expected as &$keys ) {
			\sort( $keys );
		}
		$this->assertSame( $expected, $batches, 'one request a store, never one a server' );
	}

	/**
	 * A term reads the sets of its `SEARCH_WORDS_READ` longest words alone,
	 * so a caller's term cannot multiply the read, and the walk still holds
	 * every candidate to every word: the unread `ox` still decides the page.
	 */
	public function test_a_long_term_reads_its_longest_words_and_still_matches_every_word(): void {
		$this->activate_shipped( 'performance', 3 );
		$store = $this->stats_store( 1, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'd1ce0fa11b41' => [ 'url' => 'https://kea.test/kakapo-takahe-kiwi-ox', 'count' => 6, 'last_seen' => self::tick() ],
			'd2df1ab90c42' => [ 'url' => 'https://kea.test/kakapo-takahe-kiwi-emu', 'count' => 4, 'last_seen' => self::tick() ],
		] );
		$this->forget_stats_asks();

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=ox kiwi takahe kakapo' ] );

		$this->assertSame( [ 'd1ce0fa11b41' ], \array_column( $page['data'], 'hash' ) );
		$asked = \array_map( static fn ( string $key ): string => \substr( $key, \strrpos( $key, ':' ) + 1 ), $this->asked_verbs( Stats_Store::NS_URLTOKEN )['SMEMBERS'][0] ?? [] );
		$this->assertSame( [ 'takahe', 'kakapo', 'kiwi' ], \array_values( \array_unique( $asked ) ), 'the three longest words, ties in term order, and never `ox`' );
	}

	/** A term whose three longest words are all too common narrows on its next word. */
	public function test_a_term_whose_longest_words_are_too_common_narrows_on_the_next(): void {
		$this->activate_shipped( 'performance', 3 );
		$store = $this->stats_store( 1, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'e1ce0fa11b51' => [ 'url' => 'https://kea.test/blog/2026/category/post', 'count' => 8, 'last_seen' => self::tick() ],
			'e2df1ab90c52' => [ 'url' => 'https://kea.test/blog/2026/category/news', 'count' => 5, 'last_seen' => self::tick() ],
		] );
		foreach ( [ 'category', 'blog', '2026' ] as $word ) {
			$this->saturate_url_token( $store, $word );
		}

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=blog 2026 category post' ] );

		$this->assertSame( [ 'e1ce0fa11b51' ], \array_column( $page['data'], 'hash' ) );
	}

	/**
	 * Whether a group narrows is the site's answer, not one store's: a word
	 * one store holds over the limit narrows nowhere, so a store where it
	 * would have narrowed still reads the next group, and `post` narrows both.
	 */
	public function test_a_group_one_store_holds_over_the_limit_reads_the_next_group_in_every_store(): void {
		$this->activate_shipped( 'performance', 3 );
		$kea    = $this->stats_store( 1, 86400 );
		$moa    = $this->stats_store( 2, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $kea, $bucket, [
			'f1ce0fa11b61' => [ 'url' => 'https://kea.test/blog/2026/category/post', 'count' => 8, 'last_seen' => self::tick() ],
		], 'kea.test' );
		$this->set_url_bucket( $moa, $bucket, [
			'f2df1ab90c62' => [ 'url' => 'https://moa.test/blog/2026/category/post', 'count' => 3, 'last_seen' => self::tick() ],
			'f3df1ab90c63' => [ 'url' => 'https://moa.test/blog/2026/category/news', 'count' => 2, 'last_seen' => self::tick() ],
		], 'moa.test' );
		foreach ( [ 'category', 'blog', '2026' ] as $word ) {
			$this->saturate_url_token( $kea, $word, 'kea.test' );
		}

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=blog 2026 category post' ] );

		$this->assertEqualsCanonicalizing( [ 'f1ce0fa11b61', 'f2df1ab90c62' ], \array_column( $page['data'], 'hash' ) );
	}

	public function test_a_token_read_that_goes_unanswered_says_its_page_is_short(): void {
		// Fail soft (decision 3): an unanswered read answers no word, so the
		// page is empty rather than an error, and `provisional` says it is
		// short of what the index holds, so no cache keeps it.
		$this->activate_shipped( 'performance', 3 );
		$store = $this->stats_store( 1, 86400 );
		// Files the row's token sets beside it.
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'a1ce0fa11b77' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
		] );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$healthy = $fire( '--search=wombat', '--limit=41' );
			$this->assertSame( [ 'a1ce0fa11b77' ], \array_column( $healthy['data'], 'hash' ), 'a read that answers finds the row' );

			$this->refuse_stats_reads( '/^' . Stats_Store::NS_URLTOKEN . ':/' );
			$shards = $reads();
			// A different page, so the answer is read rather than cached.
			$page = $fire( '--search=wombat', '--limit=37' );
			$this->assertSame( $shards, $reads(), 'no shard is walked for names' );
		} finally {
			$restore();
		}

		$this->assertSame( [], $page['data'] );
		$this->assertSame( 0, $page['rows'] );
		$this->assertTrue( $page['provisional'] );
		$this->assertFalse( $healthy['provisional'], 'a page the index answered is whole' );
	}

	public function test_a_term_of_no_word_asks_no_set_and_names_nothing(): void {
		// One character is no word, so the index has no set to read for it.
		$this->activate_shipped( 'performance', 3 );
		$store = $this->stats_store( 1, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'a1ce0fa11b77' => [ 'url' => 'https://kea.test/w/7', 'count' => 5, 'last_seen' => self::tick() ],
		] );
		$this->forget_stats_asks();

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=w' ] );

		$this->assertSame( [], $this->asked_verbs( Stats_Store::NS_URLTOKEN ) );
		$this->assertSame( 0, $page['rows'] );
	}

	public function test_a_folded_hour_with_no_list_is_not_served_as_ranked(): void {
		// A folded hour behind the leading one has no fine buckets left to
		// read, so a missing list there is a hole nothing can fill — and a
		// page served `ranked` over it drops that hour's traffic unsaid.
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$rows   = [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/weka-3308', 'count' => 5, 'last_seen' => self::tick() ] ];
		$this->set_url_bucket( $store, $bucket, $rows );
		$this->set_url_rank_lists( $store, $bucket, [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/weka-3308', 'count' => 9, 'last_seen' => self::tick() ] ] );
		$plan = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) );
		$this->assertGreaterThanOrEqual( 3, \count( $plan['hours'] ) );
		$gap = $plan['hours'][2];
		$this->seed_hour_lists();
		foreach ( \range( 0, 2 ) as $partition ) {
			( $this->stats_store( $partition, 86400 ) )->bucket_forget_multi( [ [ Stats_Store::url_rank_parts( 'count', 'desc', '', true ), $gap ] ] );
		}
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100' );
			$this->assertFalse( $page['ranked'], 'one hour short of the window is not a ranked page' );
			$this->assertSame( 5, $page['data'][0]['count'], 'the fold answers instead' );

			$this->seed_hour_lists();
			$page = $fire( '--sort=count', '--order=desc', '--limit=50' );
			$this->assertTrue( $page['ranked'], 'the window is whole again' );
			$this->assertSame( 9, $page['data'][0]['count'] );
		} finally {
			$restore();
		}
	}

	public function test_with_no_lists_at_all_the_page_folds(): void {
		$this->activate_shipped( 'performance', 3 );
		$this->set_url_bucket( $this->stats_store( 1, 86400 ), $this->current_url_bucket(), [
			'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
		] );
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100' );
			$this->assertFalse( $page['ranked'] );
			$this->assertSame( 5, $page['data'][0]['count'] );
		} finally {
			$restore();
		}
	}

	/**
	 * Seed the empty ranked list the writer leaves behind for a closed hour
	 * holding nothing, for every hour of the read plan but the ones named.
	 *
	 * Every partition the reader resolves, because one store missing an
	 * hour is the hole the ranked page refuses to serve over.
	 *
	 * @param list<string> $skip       Hours to leave without a list.
	 * @param string       $server     The server whose lists are seeded.
	 * @param int          $partitions Stores the active topology declares.
	 */
	private function seed_hour_lists( array $skip = [], string $server = self::SEED_SERVER, int $partitions = 3 ): void {
		$plan = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) );
		for ( $partition = 0; $partition < $partitions; ++$partition ) {
			$store = $this->stats_store( $partition, 86400 );
			foreach ( $plan['hours'] as $hour ) {
				if ( ! \in_array( $hour, $skip, true ) ) {
					$this->set_url_rank_lists( $store, $hour, [], true, $server );
				}
			}
		}
	}

	/**
	 * Every planned hour folded idle on every partition, as the writer leaves
	 * one: an index naming the seed server, no rows, and its DONE marker. A
	 * fold short of any hour is provisional and never cached.
	 */
	private function seed_folded_hours(): void {
		foreach ( \array_keys( \Newspack_Nodes\Bootstrap::node_tables( Stats_Store::TABLE_AGGREGATE )[ Stats_Store::TABLE_AGGREGATE ] ) as $partition ) {
			foreach ( Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) )['hours'] as $hour ) {
				$store = $this->stats_store( $partition, 86400 );
				$this->seed_url_hour( $store, $hour, '0', [] );
				self::mark_done( $store, $hour );
			}
		}
	}

	/** The DONE marker the writer's fold leaves once every write of it landed. */
	private static function mark_done( Stats_Store $store, string $hour ): void {
		$store->bucket_set_multi( [ [ Stats_Store::url_rank_done_parts( Stats_Store::server_key( self::SEED_SERVER ) ), $hour, [] ] ] );
	}

	/**
	 * Build the header a scope's ranked pages take their totals from, so the
	 * poll after it reads the lists: from the records, or from the fold where
	 * one is missing, whose poll answers with the page it folded. That page
	 * is thrown away here.
	 *
	 * @param \Closure(string ...$args): array<string,mixed> $fire  The runner `counting_urls_fire()` returns.
	 * @param string                                        ...$args Options naming the scope.
	 */
	private function warm_url_header( \Closure $fire, string ...$args ): void {
		$fire( '--sort=count', '--order=desc', '--limit=1', ...$args );
	}

	/**
	 * Fire `urls` with a fresh CI each time, over the one request graph and
	 * backend, and count the shard reads the folds make.
	 *
	 * @return array{0: \Closure(string ...): array<string,mixed>, 1: \Closure(): int, 2: \Closure(): void}
	 */
	private function counting_urls_fire(): array {
		$reads    = 0;
		$original = Performance_CI_Node::$load_index;
		Performance_CI_Node::$load_index = static function ( string $shard, string $server, array $stores, int $now, bool $errored ) use ( &$reads, $original ): array {
			++$reads;
			return ( $original ?? [ Performance_CI_Node::class, 'load_index_default' ] )( $shard, $server, $stores, $now, $errored );
		};
		return [
			static fn ( string ...$args ): array => VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', $args ),
			static function () use ( &$reads ): int {
				return $reads;
			},
			static function () use ( $original ): void {
				Performance_CI_Node::$load_index = $original;
			},
		];
	}

	/**
	 * Fire `urls` over three flame-builder workers, one URL seeded in the
	 * second, and count the topology-catalog builds, the stores each shard
	 * read was handed, and the distinct sets of them.
	 *
	 * @param string ...$args Verb options.
	 * @return array{0:int,1:list<int>,2:array<string,mixed>,3:int}
	 */
	private function count_catalog_builds_for_urls( string ...$args ): array {
		// Three workers: distinct from the one setUp activates, so the stores
		// resolved once must still be all three.
		$this->activate_shipped( 'performance', 3 );
		$this->set_url_bucket( $this->stats_store( 1, 86400 ), $this->current_url_bucket(), [
			'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
		] );
		// The seed resolved the Tables; count what the verb resolves itself.
		\Newspack_Nodes\Bootstrap::forget_node_stores();
		$builds   = self::count_catalog_reads();
		$read     = [];
		$original = Performance_CI_Node::$load_index;
		$sets     = [];
		Performance_CI_Node::$load_index = static function ( string $shard, string $server, array $stores, int $now, bool $errored ) use ( &$read, &$sets, $original ): array {
			$read[] = \count( $stores );
			$sets[ \implode( ',', \array_map( 'spl_object_id', $stores ) ) ] = true;
			return ( $original ?? [ Performance_CI_Node::class, 'load_index_default' ] )( $shard, $server, $stores, $now, $errored );
		};
		try {
			$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', ...$args );
		} finally {
			Performance_CI_Node::$load_index = $original;
			VerbHarness::reset();
		}
		return [ $builds(), $read, $reply, \count( $sets ) ];
	}

	/**
	 * The shard walk reads BOTH tiers in `INDEX_READ_CHUNK` batches.
	 *
	 * The bound is what keeps a window of buckets from going resident before
	 * the first fold — decision 6's batching, bounded. A plan of 23 coarse
	 * hours is two chunks, and 25 fine buckets three; an hour no coarse key
	 * answered for adds none.
	 */
	public function test_the_shard_walk_reads_each_tier_in_bounded_chunks(): void {
		$plan    = self::walk_plan( 23, 25 );
		$coarse  = [];
		$fine    = [];
		$folded  = 0;

		self::walk_shard_tiers(
			$plan,
			static function ( array $hours ) use ( &$coarse ): array {
				$coarse[] = \count( $hours );
				return [];
			},
			static function ( array $buckets ) use ( &$fine ): array {
				$fine[] = \count( $buckets );
				return [];
			},
			static function ( string $bucket, array $data ) use ( &$folded ): void {
				++$folded;
			}
		);

		$this->assertSame( [ 12, 11 ], $coarse, '23 hours is ceil(23/12) coarse reads' );
		$this->assertSame( [ 12, 12, 1 ], $fine, '25 fine buckets, and no hour\'s buckets' );
		$this->assertSame( 0, $folded, 'nothing answered, so nothing folds' );
	}

	/**
	 * Each chunk is folded and DROPPED before the next is read.
	 *
	 * Reading the whole window first and folding after is what exhausted a
	 * production hub's 512MB: the buckets and the index they build are
	 * resident together. Asserted on the ORDER of the reads and folds, which
	 * is the only observable difference between the two shapes.
	 */
	public function test_the_shard_walk_folds_each_chunk_before_reading_the_next(): void {
		$plan  = self::walk_plan( 23, 0 );
		$trace = [];

		self::walk_shard_tiers(
			$plan,
			static function ( array $hours ) use ( &$trace ): array {
				$trace[] = 'read';
				$pairs   = [];
				foreach ( $hours as $hour ) {
					$pairs[] = [ $hour, [ 'seen' => $hour ] ];
				}
				return $pairs;
			},
			static fn ( array $buckets ): array => [],
			static function ( string $bucket, array $data ) use ( &$trace ): void {
				$trace[] = "fold:{$bucket}";
			}
		);

		$this->assertCount( 25, $trace, 'two reads and twenty-three folds' );
		$this->assertSame( 'read', $trace[0] );
		$this->assertSame( 'read', $trace[13], 'the first chunk folded before the second was read' );
	}

	/**
	 * A plan for the walker: `$hours` coarse hours newest first, `$fine` fine
	 * buckets. Neither count matches `INDEX_READ_CHUNK` or a multiple of it.
	 *
	 * @param int $hours Coarse hour count.
	 * @param int $fine  Fine bucket count.
	 * @return array{fine: list<string>, hours: list<string>}
	 */
	private static function walk_plan( int $hours, int $fine ): array {
		$hour_keys = [];
		for ( $i = $hours; $i >= 1; --$i ) {
			$hour_keys[] = \sprintf( '2026-03-04-%02d', $i );
		}
		$fine_keys = [];
		for ( $i = 0; $i < $fine; ++$i ) {
			$fine_keys[] = \sprintf( '2026-03-05-00-%02d', $i );
		}
		return [ 'fine' => $fine_keys, 'hours' => $hour_keys ];
	}

	/**
	 * Drive the private shard walk.
	 *
	 * @param array{fine: list<string>, hours: list<string>} $plan   The read plan.
	 * @param \Closure                                       $coarse Coarse-tier reader.
	 * @param \Closure                                       $fine   Fine-tier reader.
	 * @param \Closure                                       $fold   Pair sink.
	 */
	private static function walk_shard_tiers( array $plan, \Closure $coarse, \Closure $fine, \Closure $fold ): void {
		$m = new \ReflectionMethod( Performance_CI_Node::class, 'walk_shard_tiers' );
		$m->invoke( null, $plan, $coarse, $fine, $fold );
	}

	/**
	 * The loader must read its buckets in BOUNDED batches.
	 *
	 * `url_row_sources()` issued ONE `lookup_multi` across all sixteen shards
	 * and returned every bucket's rows, so the whole window was resident before
	 * the first fold — on top of the index being built. At a full cache
	 * item per key that is hundreds of MB, and it is what
	 * exhausted 512MB on a production hub after both duplicate copies were
	 * already gone. Decision 6's batching stays; the batch is just bounded.
	 *
	 * Asserted on the multi-get WIDTH rather than on memory: the in-memory
	 * double hands back references instead of unserializing, so it cannot
	 * reproduce the per-key allocation that makes this expensive in production.
	 */
	public function test_the_index_loader_reads_in_bounded_batches(): void {
		$store   = $this->stats_store( 0, 86400 );
		$buckets = \array_slice( self::read_window_for_test(), 0, 24 );
		$rows    = [];
		for ( $i = 0; $i < 40; $i++ ) {
			$rows[ \sprintf( '%012x', $i ) ] = [
				'url'         => "/streamed/{$i}",
				'count'       => 3,
				'timed_count' => 3,
				'sum_ms'      => 90.0,
				'min_ms'      => 5,
				'last_seen'   => 1711111111,
			];
		}
		foreach ( $buckets as $bucket ) {
			$this->set_url_bucket( $store, $bucket, $rows );
		}

		$this->forget_stats_asks();

		// Every hash is `%012x`, so all forty land in shard 0.
		$out = Performance_CI_Node::load_index_default( '0', '', $this->live_stores(), (int) Core::$now, false );

		$this->assertCount( 40, $out, 'every seeded URL still folds' );
		$this->assertGreaterThan( 1, $this->stats_reads(), 'the window must not be one read' );
		$widest = (int) \ceil( \count( $this->asked_keys() ) / \max( 1, $this->stats_reads() ) );
		$this->assertLessThanOrEqual(
			Performance_CI_Node::INDEX_READ_CHUNK,
			$widest,
			\sprintf( 'average multi-get width %d exceeds one chunk', $widest )
		);
	}

	/**
	 * The `urls` verb must not materialise a SECOND complete index.
	 *
	 * `raw_index()` is memoized for the request (decision 14), so a projection
	 * that builds a display row for every URL leaves two full indexes resident.
	 * On a production hub that exhausted 512MB. The verb needs whole rows only
	 * for the page it returns and the ten slowest; everything else it reads per
	 * row is summed, compared and discarded.
	 */
	public function test_the_urls_verb_does_not_hold_a_second_full_index(): void {
		$base = \memory_get_usage();
		$rows = [];
		for ( $i = 0; $i < 10000; $i++ ) {
			$rows[] = [
				// Vary the FIRST hex digit: that is the shard, and `%012x` of a
				// small int is all leading zeros, so every row would be shard 0.
				'hash'         => \sprintf( '%x%011x', $i % 16, $i ),
				'url'          => "/page/{$i}",
				'count'        => $i + 1,
				'timed_count'  => $i + 1,
				'sum_ms'       => 1.5 * $i,
				'sum_peak_mb'  => 0.5,
				'min_ms'       => 1.0,
				'max_ms'       => 9.0,
				'recent_count' => 1,
				'last_updated' => 1711111111,
				'worker'       => false,
				'aggregate'    => false,
				'count_2xx'    => $i + 1,
				'count_3xx'    => 0,
				'count_4xx'    => 0,
				'count_5xx'    => 0,
			];
		}
		$index_bytes = \memory_get_usage() - $base;

		// Each shard answers for its OWN rows, as the real loader does: a
		// url_hash lives in exactly one shard (its first hex digit).
		$by_shard = [];
		foreach ( $rows as $row ) {
			$by_shard[ Stats_Store::url_shard( $row['hash'] ) ][] = $row;
		}
		$original                        = Performance_CI_Node::$load_index;
		Performance_CI_Node::$load_index = static fn ( string $shard ): array => $by_shard[ $shard ] ?? [];
		try {
			$before = \memory_get_usage();
			\memory_reset_peak_usage();
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
			$peak = \memory_get_peak_usage() - $before;
		} finally {
			Performance_CI_Node::$load_index = $original;
			VerbHarness::reset();
		}

		$this->assertLessThan(
			(int) ( $index_bytes * 0.5 ),
			// Reported either way, so a regression names its own number.
			$peak,
			\sprintf(
				'the verb allocated %.1f MB over an index of %.1f MB; a shard is a sixteenth',
				$peak / 1048576,
				$index_bytes / 1048576
			)
		);
	}

	/**
	 * The fold must mutate the merged index IN PLACE.
	 *
	 * Taking it by value and returning it copies the whole index on the first
	 * write inside the callee — the caller still holds a reference during the
	 * call — and `load_index_default()` does that once per bucket source, of
	 * which there are thousands. Paired with a display loop that built a second
	 * complete copy, a production hub exhausted 512MB every fifteen seconds:
	 * `Allowed memory size of 536870912 bytes exhausted` at the copy.
	 */
	public function test_the_bucket_fold_mutates_the_merged_index_in_place(): void {
		$fold   = new \ReflectionMethod( Performance_CI_Node::class, 'fold_bucket' );
		$result = [];
		$data   = [
			'ab12cd34ef56' => [
				'url'         => '/in-place',
				'count'       => 7,
				'timed_count' => 7,
				'sum_ms'      => 210.0,
				'min_ms'      => 13,
				'last_seen'   => 1711111333,
			],
		];

		$fold->invokeArgs( null, [ &$result, $data, false ] );

		$this->assertArrayHasKey(
			'ab12cd34ef56',
			$result,
			'the caller\'s array must carry the fold without an assignment back'
		);
	}

	/** A row with no timed bucket to fold reports min_ms 0 rather than a missing key. */
	public function test_the_index_row_defaults_min_ms_when_nothing_timed_folded(): void {
		$store = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'facade000777' => [
				'url'         => '/never-timed',
				'count'       => 6,
				'timed_count' => 0,
				'min_ms'      => 91,
				'last_seen'   => 1711111222,
			],
		] );

		$rows = Performance_CI_Node::load_index_default( Stats_Store::url_shard( 'facade000777' ), '', $this->live_stores(), (int) Core::$now, false );

		$row = \array_values( \array_filter( $rows, static fn ( $r ) => 'facade000777' === $r['hash'] ) )[0] ?? null;
		$this->assertIsArray( $row );
		$this->assertSame( 0.0, $row['min_ms'], 'an untimed bucket must not fold its min_ms sentinel' );
	}

	/**
	 * The leaderboard reader sums each category's own totals across buckets and
	 * leaves `entries` alone — the dashboard never reads a global category's
	 * entries, and folding them would carry every appearance in the window.
	 */
	public function test_the_leaderboard_sums_categories_without_folding_entries(): void {
		$window = Stats_Store::retention_buckets( 86400, self::tick() );
		$store  = $this->stats_store( 0, 86400 );
		$this->set_leaderboard_hour( $store, Stats_Store::hour_of( $window[0] ), [
			'count'        => 4,
			'sum_req_time' => 8.0,
			'categories'   => [
				'db' => [
					'samples'   => 3,
					'sum_time'  => 1.5,
					'sum_count' => 6.0,
					'entries'   => [ 'wpdb::query' => [ 0.9, 3.0, 3 ] ],
				],
			],
		] );
		$this->set_leaderboard_hour( $store, Stats_Store::hour_of( Stats_Store::bucket_key( self::tick() - Stats_Store::HOUR_SECONDS ) ), [
			'count'        => 6,
			'sum_req_time' => 12.0,
			'categories'   => [
				'db' => [
					'samples'   => 4,
					'sum_time'  => 2.5,
					'sum_count' => 10.0,
					'entries'   => [ 'wpdb::get_row' => [ 1.1, 4.0, 4 ] ],
				],
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$board = $result['global_leaderboard'];
		$this->assertSame( 10, $board['count'] );
		$this->assertSame( 7, $board['categories']['db']['samples'] );
		$this->assertEqualsWithDelta( 0.4, $board['categories']['db']['time'], 0.0001 );
		$this->assertEqualsWithDelta( 1.6, $board['categories']['db']['count'], 0.0001 );
		$this->assertSame( [], $board['categories']['db']['entries'], 'the reader must not fold per-entry appearances' );
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

	/**
	 * Settle records through a real builder at the moments they finished,
	 * one settle a moment, oldest first, then one at the tick, which folds
	 * every hour the wall has closed: the stored state a
	 * reader meets, fine buckets and folded hours alike, written by the
	 * writer rather than by a seed helper.
	 *
	 * @param array<int,list<array<string,mixed>>> $at Seconds before the tick => records finishing then.
	 */
	private function flush_window( array $at ): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] = [ [ 'id' => 'r', 'pattern' => '/', 'action' => 'log' ] ];
		$tick = self::tick();
		$fb   = new Flame_Builder_Node();
		$fb->name( 'window-fb' );
		$fb->sink( new \Newspack_Nodes\Tests\Capture_Sink_Node() );
		$fb->set_stats_store( $this->stats_store( 0, 86400 ) );
		\krsort( $at );
		try {
			foreach ( $at as $ago => $records ) {
				Core::$now = (float) ( $tick - $ago );
				foreach ( $records as $record ) {
					$message                   = Message::new_message();
					$message[ Message::TYPE ]  = Message::TM_STRUCT;
					$message[ Message::VALUE ] = \array_replace( [
						'rid'            => 'r' . \uniqid(),
						'rule_id'        => 'r',
						'duration_ms'    => 100.0,
						'status_code'    => 200,
						'error_status'   => '-',
						'peak_mb'        => 32.0,
						'request_method' => 'GET',
						'server_name'    => self::SEED_SERVER,
						'is_worker'      => false,
						'entries'        => [],
						'profiles'       => [],
					], $record, [ 'timestamp' => $tick - $ago ] );
					$fb->fill( $message );
				}
				$fb->settle();
			}
			Core::$now = (float) $tick;
			$fb->settle();
		} finally {
			Core::$now = (float) $tick;
			$fb->remove_node();
		}
	}

	/**
	 * A search reads its candidates' rows BY KEY and no shard of the index:
	 * over an index whose URLs cover all sixteen hash digits, in folded
	 * hours and in the current hour's buckets, `takahe` asks the per-URL
	 * row of its two candidates alone, one key per planned hour holding it.
	 */
	public function test_a_search_reads_no_shard_and_keys_only_for_its_candidates(): void {
		$records = [];
		$held    = [];
		for ( $i = 0; \count( $records ) < 3 * Stats_Store::URL_SHARDS; $i++ ) {
			$url   = "/kokako-{$i}";
			$digit = \substr( Log_Manager::url_hash( $url ), 0, 1 );
			if ( ( $held[ $digit ] = ( $held[ $digit ] ?? 0 ) + 1 ) <= 3 ) {
				$records[] = [ 'url' => $url ];
			}
		}
		$candidates = [ '/takahe/kea-41', '/tui/takahe-77' ];
		foreach ( $candidates as $url ) {
			$records[] = [ 'url' => $url, 'duration_ms' => 413.0 ];
		}
		$digits = \array_unique( \array_map( static fn ( array $r ): string => \substr( Log_Manager::url_hash( $r['url'] ), 0, 1 ), $records ) );
		$this->assertCount( Stats_Store::URL_SHARDS, $digits, 'the index covers every shard' );
		// Two hours back, which folds, and seven minutes into the current hour.
		$this->flush_window( [ 7200 + 600 => $records, self::INTO_HOUR - 420 => $records ] );
		$hashes = \array_map( Log_Manager::url_hash( ... ), $candidates );
		$this->forget_stats_asks();
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--search=takahe', '--limit=100' );
			$this->assertSame( 0, $reads(), 'no shard is read' );
		} finally {
			$restore();
		}

		$this->assertEqualsCanonicalizing( $hashes, \array_column( $page['data'], 'hash' ) );
		$this->assertSame( 2, $page['data'][0]['count'], 'one request in the folded hour and one this hour' );
		$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URLS ), 'no fine shard' );
		$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URLS_HOUR ), 'no hour shard' );
		$rows = $this->asked_keys( Stats_Store::NS_URL_ROW_HOUR );
		foreach ( $rows as $key ) {
			$this->assertContains( \substr( $key, -12 ), $hashes, "{$key} names a candidate" );
		}
		$this->assertCount( 4, $rows, 'two candidates, each in the folded hour and the current one' );
	}

	/**
	 * A searched row is the row the table shows for that URL, read by key
	 * over a window of folded hours and the current hour's buckets: equal
	 * to the fold's row whole, errored rows and worker traffic included,
	 * and to the ranked page's row on everything but its bucket-weighted
	 * means (decision 28).
	 */
	public function test_a_searched_row_is_the_unsearched_row_over_fine_buckets_and_hours(): void {
		$kokako = [ 'url' => '/kea/kokako', 'duration_ms' => 211.0, 'peak_mb' => 17.5, 'status_code' => 404 ];
		$tui    = [ 'url' => '/kea/tui', 'duration_ms' => 97.0, 'peak_mb' => 41.0 ];
		$cron   = [ 'url' => '/kea/cron', 'duration_ms' => 1873.0, 'peak_mb' => 66.0, 'is_worker' => true ];
		$moa    = [ 'url' => '/moa/kiwi', 'duration_ms' => 59.0 ];
		$this->flush_window( [
			3 * 3600 + 900         => [ $kokako, $tui, $moa, $cron ],
			3600 + 1500            => [ [ 'duration_ms' => 4400.0, 'error_status' => 'T' ] + $kokako, $cron ],
			3600 + 300             => [ $kokako, $cron, $moa ],
			self::INTO_HOUR - 180  => [ $kokako, [ 'duration_ms' => 733.0, 'error_status' => 'F', 'status_code' => 500 ] + $tui, $cron ],
			self::INTO_HOUR - 1500 => [ $tui, $moa ],
			60                     => [ $kokako, $cron ],
		] );
		$by_hash = static fn ( array $page ): array => \array_column( $page['data'], null, 'hash' );
		foreach ( [ [], [ '--errors_only=1' ], [ '--include_workers=1' ] ] as $filters ) {
			$folded = $by_hash( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--limit=201', ...$filters ] ) );
			$this->forget_stats_asks();
			$search = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=kea', '--limit=201', ...$filters ] );
			$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URLS ), 'no fine shard' );
			$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URLS_HOUR ), 'no hour shard' );
			$this->assertNotSame( [], $search['data'], \implode( ' ', $filters ) . \wp_json_encode( [ $folded, $search ] ) );
			foreach ( $by_hash( $search ) as $hash => $row ) {
				$this->assertEquals( $folded[ $hash ] ?? null, $row, \implode( ' ', $filters ) . ": {$row['url']}" );
			}
		}
		$ranked = $by_hash( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--limit=100' ] ) );
		$search = $by_hash( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=kea', '--limit=100' ] ) );
		$this->assertNotSame( [], $search );
		foreach ( $search as $hash => $row ) {
			unset( $row['avg_ms'], $row['avg_peak_mb'], $ranked[ $hash ]['avg_ms'], $ranked[ $hash ]['avg_peak_mb'] );
			$this->assertEquals( $ranked[ $hash ], $row, "ranked: {$row['url']}" );
		}
	}

	/**
	 * A term every URL carries is the case the index does not narrow, and the
	 * contract has to hold there too: the term matches the path each row
	 * carries, so naming costs ONE `urlmap` batch per store over the rows the
	 * page shows rather than every candidate, and each candidate's row is
	 * read by key, no shard.
	 */
	public function test_a_broad_search_names_only_the_rows_it_shows(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		// 137 URLs, all in shard `a`, distinct from every cap in this schema.
		$seeded = [];
		for ( $i = 0; $i < 137; $i++ ) {
			$seeded[ \sprintf( 'a%011x', $i ) ] = [
				'url'       => \sprintf( 'https://alpha.test/page-%d', $i ),
				'count'     => $i + 1,
				'last_seen' => 1700000000 + $i,
			];
		}
		$this->set_url_bucket( $store, $bucket, $seeded );
		$this->forget_stats_asks();

		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=page' );
			$this->assertSame( 137, $page['rows'], 'every URL carries the term' );
			$this->assertSame( 0, $reads(), 'no shard is read' );
			$this->assertCount( 137, $this->asked_keys( Stats_Store::NS_URL_ROW_HOUR ), 'one key a candidate' );
		} finally {
			$restore();
		}
		$shown = \array_unique( \array_column( [ ...$page['data'], ...$page['slowest'] ], 'hash' ) );
		$batches = $this->asked_batches( Stats_Store::NS_URLMAP );
		$this->assertCount( 1, $batches, 'the rows shown are named once' );
		$this->assertCount( \count( $shown ), $batches[0], 'the rows shown, not every candidate' );
	}

	/**
	 * `URL_SEARCH_MAX` bounds what the reader will TAKE from one word's set:
	 * at the ceiling the index serves by key, and with the name scan on, one
	 * hash past it the fold does.
	 */
	public function test_a_term_past_the_candidate_ceiling_falls_through_to_the_fold(): void {
		Performance_CI_Node::$match_names = true;
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
		] );
		$at_ceiling = [ 'b7731ce0fa11' ];
		for ( $i = 0; \count( $at_ceiling ) < Stats_Store::URL_SEARCH_MAX; $i++ ) {
			$at_ceiling[] = \sprintf( 'd%011x', $i );
		}
		$write = function ( array $hashes ) use ( $store ): void {
			$this->file_url_token( $store, 'wombat', $hashes );
		};
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$write( $at_ceiling );
			$page   = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=wombat' );
			$served = $reads();
			$this->assertSame( 0, $served, 'at the ceiling the candidates are read by key' );
			$this->assertSame( [ 'b7731ce0fa11' ], \array_column( $page['data'], 'hash' ) );

			// A different page, so the answer is folded rather than cached.
			$write( [ 'd99999999999' ] );
			$page = $fire( '--sort=count', '--order=desc', '--limit=99', '--search=wombat' );
			$this->assertSame( $served + \count( Stats_Store::url_shards() ), $reads(), 'one past it, every shard' );
			$this->assertSame( [ 'b7731ce0fa11' ], \array_column( $page['data'], 'hash' ) );
		} finally {
			$restore();
		}
	}

	public function test_a_candidate_whose_name_expired_still_reaches_the_page(): void {
		// A name can expire or be refused while its rows are still in the
		// window. The index already said this hash carries the token, so
		// dropping it for want of a name hides a row the term really names.
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'e5510ab77c01' => [ 'url' => 'https://kea.test/hihi-5510', 'count' => 6, 'last_seen' => self::tick() ],
		] );
		// The tokens outlive the name: drop the `urlmap` entry alone.
		$store->bucket_forget_multi( [ [ [ Stats_Store::NS_URLMAP ], 'e5510ab77c01' ] ] );
		$this->assertSame( [], $store->get_url_names( [ 'e5510ab77c01' ] ), 'the name is gone; the rows and the tokens are not' );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--search=hihi' );

		$this->assertSame( 1, $page['rows'], 'the index named it, so it is on the page' );
		$this->assertSame( [ 'e5510ab77c01' ], \array_column( $page['data'], 'hash' ) );
	}

	public function test_the_fold_matches_a_term_token_as_a_whole_word(): void {
		// The index files whole words, so the fold it falls back to must too:
		// one reading of `77` cannot mean two things.
		Performance_CI_Node::$match_names = true;
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'a7700ce0fa11' => [ 'url' => 'https://kea.test/wombat-77', 'count' => 5, 'last_seen' => self::tick() ],
			'b1177df1ab90' => [ 'url' => 'https://kea.test/wombat-7700', 'count' => 3, 'last_seen' => self::tick() ],
			'c7711df1ab91' => [ 'url' => 'https://kea.test/wombat-1177', 'count' => 2, 'last_seen' => self::tick() ],
		] );
		// Saturate both of the term's tokens, so the fold answers.
		$this->saturate_url_token( $store, 'wombat' );
		$this->saturate_url_token( $store, '77' );

		// An ARRAY: `fire()` splits a string on whitespace, and this term has some.
		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=wombat 77' ] );

		$this->assertSame( [ 'a7700ce0fa11' ], \array_column( $page['data'], 'hash' ), '77 is a word of /wombat-77 alone' );
	}

	public function test_a_scoped_search_that_names_nothing_answers_zero_rather_than_null(): void {
		// A search with no candidates walks nothing, and nothing is zero
		// requests: a total the page answers, never an unanswerable scope.
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'c3310ab77c02' => [ 'url' => 'https://moa.test/kakapo-3310', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 80.0, 'sum_peak_mb' => 4.0, 'last_seen' => self::tick() ],
		], 'moa.test' );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=moa.test --search=nomatch' );

		$this->assertIsArray( $page['totals'], 'a term nothing matches is zero requests, not an unanswerable scope' );
		$this->assertSame( 0, $page['totals']['requests'] );
		$this->assertSame( 0, $page['rows'] );
	}

	public function test_the_seeds_date_from_the_tick_the_reply_reads(): void {
		// The reply dates itself from `Core::$now`, stamped at setUp; a seed
		// dated from the wall lands a bucket later whenever a five-minute
		// boundary falls between the two, and the page it seeded reads empty.
		$previous  = Core::$now;
		Core::$now = 1_600_000_517.25;
		try {
			$this->assertSame( Stats_Store::bucket_key( (int) Core::$now ), $this->current_url_bucket() );
		} finally {
			Core::$now = $previous;
		}
	}

	public function test_a_fresh_graph_keeps_the_tick_its_test_dates_from(): void {
		// A test fires several replies, each on a fresh graph; re-reading the
		// wall between them splits one test across two buckets at a boundary.
		$previous  = Core::$now;
		Core::$now = (float) ( self::tick() + Stats_Store::BUCKET_SECONDS );
		$tick      = Core::$now;
		try {
			VerbHarness::reset();
			$this->assertSame( $tick, Core::$now );
		} finally {
			Core::$now = $previous;
		}
	}

	public function test_a_whitespace_only_search_is_the_same_page_as_no_search(): void {
		// The cache key normalizes the term, so a blank search and no search
		// are one entry — and they have to be one ANSWER too, or whichever
		// ran first decides whether the other is ranked or folded.
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$rows   = [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ] ];
		$this->set_url_bucket( $store, $bucket, $rows );
		$this->set_url_rank_lists( $store, $bucket, $rows );
		$this->seed_hour_lists();

		// The header's own miss folds, and that fold is the page it answers
		// with, so warm it under a key neither call below shares.
		$memd = Core::$memd;
		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--limit=1' ] );
		VerbHarness::reset();
		Core::$memd = $memd;

		// An ARRAY: `fire()` would split the string on the very space at issue.
		$blank = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search= ' ] );
		$memd  = Core::$memd;
		VerbHarness::reset();
		// The same cache, so the second call is the one the first left behind.
		Core::$memd = $memd;
		$none       = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertTrue( $blank['ranked'], 'whitespace is not a filter' );
		$this->assertTrue( $none['ranked'] );
		$this->assertSame( $none['data'], $blank['data'] );
		$this->assertSame( ' ', $blank['filters']['search'], 'the echo still says what was typed' );
	}

	public function test_one_page_cache_entry_serves_a_term_however_it_was_typed(): void {
		$this->activate_shipped( 'performance', 3 );
		$this->seed_folded_hours();
		$store = $this->stats_store( 1, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'd4410ab77c03' => [ 'url' => 'https://kea.test/weka-4410', 'count' => 7, 'last_seen' => self::tick() ],
		] );
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$this->forget_stats_asks();
			$first = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=weka' );
			$this->assertNotSame( [], $this->asked_keys( Stats_Store::NS_URL_ROW_HOUR ) );
			$this->forget_stats_asks();
			$again = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=WEKA ' );
			$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URL_ROW_HOUR ), 'case and trailing space are the same search' );
			$this->assertSame( $first['data'], $again['data'] );
			$this->assertSame( 'WEKA ', $again['filters']['search'], 'the echo still says what was typed' );
		} finally {
			$restore();
		}
	}

	/**
	 * A later partition is asked only for the hashes still unnamed, and none
	 * once every one is named: each hash it asks for is a read for a name an
	 * earlier partition already gave.
	 */
	public function test_url_names_asks_each_later_store_only_for_what_is_unnamed(): void {
		$this->activate_shipped( 'performance', 3 );
		$first  = $this->stats_store( 0, 86400 );
		$second = $this->stats_store( 1, 86400 );
		$third  = $this->stats_store( 2, 86400 );
		$first->set_url_names( [ 'kea.example' => [ 'a7713ab0c0de' => 'https://kea.example/wombat-7713' ] ] );
		$second->set_url_names( [ 'moa.example' => [ 'b7714ab0c0de' => 'https://moa.example/wombat-7714' ] ] );
		$this->forget_stats_asks();

		$names = ( new \ReflectionMethod( Performance_CI_Node::class, 'url_names' ) )
			->invoke( null, [ 'a7713ab0c0de', 'b7714ab0c0de' ], [ $first, $second, $third ] );

		$asked_of = fn ( int $p ): array => $this->asked_keys( Stats_Store::NS_URLMAP, self::stats_writer( Stats_Store::TABLE_AGGREGATE, $p ) );
		$this->assertSame( 'https://kea.example/wombat-7713', $names['a7713ab0c0de']['url'] ?? null );
		$this->assertSame( 'https://moa.example/wombat-7714', $names['b7714ab0c0de']['url'] ?? null );
		$this->assertSame( [ Stats_Store::NS_URLMAP . ':b7714ab0c0de' ], $asked_of( 1 ), 'the second store is asked for the one name the first lacked' );
		$this->assertSame( [], $asked_of( 2 ), 'and once every hash is named, no store is asked again' );
	}

	public function test_a_saturated_token_falls_through_to_the_fold(): void {
		Performance_CI_Node::$match_names = true;
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
		] );
		$this->saturate_url_token( $store, 'wombat' );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=wombat' );
			$this->assertSame( \count( Stats_Store::url_shards() ), $reads(), 'every shard: the index cannot serve the term' );
			$this->assertSame( [ 'b7731ce0fa11' ], \array_column( $page['data'], 'hash' ) );
		} finally {
			$restore();
		}
	}

	/**
	 * With the name match off, search is the token index alone: a term whose
	 * only word is too common to narrow is refused, naming the limit, and
	 * walks no shard for names.
	 */
	public function test_with_names_off_a_term_too_common_to_narrow_is_refused(): void {
		Performance_CI_Node::$match_names = false;
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
		] );
		$this->saturate_url_token( $store, 'wombat' );
		$this->forget_stats_asks();

		$refused = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--limit=100', '--search=wombat' ] );

		$this->assertIsString( $refused );
		$this->assertStringContainsString( 'past the ' . Stats_Store::URL_SEARCH_MAX, $refused );
		$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URLS ), 'no shard is walked for names' );
		$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URL_ROW_HOUR ) );
	}

	/**
	 * With the name match off, a token whose set the Table does not hold names
	 * nothing, and the rest of the term does not reach the names to make up
	 * for it.
	 */
	public function test_with_names_off_a_missing_token_set_answers_from_the_tokens_it_has(): void {
		Performance_CI_Node::$match_names = false;
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		// The row alone: no word of its path is filed.
		$this->seed_url_shard( $store, $this->current_url_bucket(), Stats_Store::url_shard( 'b7731ce0fa11' ), [
			'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
		] );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=wombat' );
			$this->assertSame( 0, $reads(), 'no shard is walked for names' );
			$this->assertSame( [], $page['data'] );
		} finally {
			$restore();
		}
	}

	/**
	 * Whatever the sets resolve to, each candidate is checked against the
	 * whole term: a saturated word narrows nothing, yet every URL returned
	 * carries it.
	 */
	public function test_a_candidate_must_carry_every_word_of_the_term(): void {
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'a1c2e3a4b5c6' => [ 'url' => 'https://kea.test/category/shoes', 'count' => 9, 'last_seen' => self::tick() ],
			'b1c2e3a4b5c6' => [ 'url' => 'https://kea.test/shoes/sale-4471', 'count' => 7, 'last_seen' => self::tick() ],
			'c1c2e3a4b5c6' => [ 'url' => 'https://kea.test/category/news', 'count' => 5, 'last_seen' => self::tick() ],
		] );
		$this->saturate_url_token( $store, 'category' );
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=category shoes' );
			$this->assertSame( [ 'a1c2e3a4b5c6' ], \array_column( $page['data'], 'hash' ) );
			$this->assertSame( 1, $page['rows'] );
		} finally {
			$restore();
		}
	}

	/** A row's path cut at `MAX_PATH_BYTES` cannot show a word past the cut, so the index is trusted for it. */
	public function test_a_word_past_a_rows_truncated_path_is_found(): void {
		$this->activate_shipped( 'performance', 3 );
		$store = $this->stats_store( 1, 86400 );
		$url   = 'https://' . self::SEED_SERVER . '/' . \str_repeat( 'kakapo-', 160 ) . 'wombat-7731';
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'e1c2e3a4b5c6' => [ 'url' => $url, 'count' => 4, 'last_seen' => self::tick() ],
		] );
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=wombat' );
			$this->assertSame( [ 'e1c2e3a4b5c6' ], \array_column( $page['data'], 'hash' ) );
			$this->assertStringEndsWith( '…', $page['data'][0]['url'], 'the stored path is the cut one' );
		} finally {
			$restore();
		}
	}

	/** A prefix set an older flush left behind names URLs the term's word is no word of, and the check drops them. */
	public function test_a_leftover_prefix_set_names_nothing_the_term_does_not(): void {
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'c1c2e3a4b5c6' => [ 'url' => 'https://kea.test/category/news', 'count' => 5, 'last_seen' => self::tick() ],
		] );
		$this->file_url_token( $store, 'cat', [ 'c1c2e3a4b5c6' ] );
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=cat' );
			$this->assertSame( [], $page['data'] );
			$this->assertSame( 0, $page['rows'] );
		} finally {
			$restore();
		}
	}

	/**
	 * A search reads at most `URL_SEARCH_MAX` candidates by key. Two
	 * partitions naming one word's URLs, each set inside the read limit,
	 * are served at the limit, each candidate read only where its server's
	 * index names its shard; one URL past it refuses the term, naming the
	 * limit, and reads no row.
	 */
	public function test_a_term_past_url_search_max_is_refused(): void {
		$this->activate_shipped( 'performance', 2 );
		$per    = \intdiv( Stats_Store::URL_SEARCH_MAX, 2 );
		$stores = [ $this->stats_store( 0, 86400 ), $this->stats_store( 1, 86400 ) ];
		foreach ( $stores as $p => $store ) {
			$hash = \sprintf( '%x00000000041', $p + 10 );
			$this->set_url_shard( $store, $this->current_url_bucket(), Stats_Store::url_shard( $hash ), [
				$hash => self::positional_url_row( [ 'path' => "/news/kea-{$p}", 'count' => 3, 'last_seen' => self::tick() ] ),
			] );
			// The rest of the word's set names URLs in shards no index holds.
			$named = [ $hash ];
			for ( $i = 1; $i < $per; $i++ ) {
				$named[] = \sprintf( '%x%011x', $p + 12, $i );
			}
			$this->file_url_token( $store, 'news', $named );
		}
		$this->forget_stats_asks();
		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--limit=10', '--search=news' ] );

		$this->assertIsArray( $page, \is_string( $page ) ? $page : '' );
		$this->assertSame( 2, $page['rows'], 'at the limit the term is served' );
		$this->assertSame( 6, $page['totals']['requests'] );
		$this->assertCount( 2, $this->asked_keys( Stats_Store::NS_URL_ROW_HOUR ), 'a key only where an index names the shard' );

		$this->file_url_token( $stores[1], 'news', [ 'e00000000077' ] );
		$this->forget_stats_asks();
		$refused = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--limit=11', '--search=news' ] );

		$this->assertIsString( $refused );
		$this->assertStringContainsString( 'past the ' . Stats_Store::URL_SEARCH_MAX, $refused );
		$this->assertStringContainsString( 'add a word', $refused );
		$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URL_ROW_HOUR ), 'a refused term reads no row' );
		$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URLS ), 'nor any shard' );
	}

	/**
	 * A term of several tokens, one of them saturated: the index cannot answer
	 * that one, and folding the whole site for it throws away the narrowing
	 * the others are holding. The fold still applies the saturated token to
	 * the names, so the answer is the same page for less work.
	 */
	public function test_a_token_the_index_cannot_serve_still_lets_the_others_narrow(): void {
		Performance_CI_Node::$match_names = true;
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'a4410ce0fa19' => [ 'url' => 'https://kea.test/kereru-41', 'count' => 12, 'last_seen' => self::tick() ],
			'a9920ce0fa77' => [ 'url' => 'https://kea.test/kereru-99', 'count' => 7, 'last_seen' => self::tick() ],
			'c8842df1ab90' => [ 'url' => 'https://kea.test/kiwi-8842', 'count' => 3, 'last_seen' => self::tick() ],
		] );
		$this->saturate_url_token( $store, '41' );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=kereru 41' );
			$this->assertSame( 0, $reads(), 'the servable token named the candidates, read by key' );
			$this->assertSame( [ 'a4410ce0fa19' ], \array_column( $page['data'], 'hash' ) );
		} finally {
			$restore();
		}
	}

	/**
	 * Both tiers of a ranked page are known before the first read, so they go
	 * out together: a round trip per store, not one per tier per store.
	 */
	public function test_a_ranked_page_reads_both_tiers_in_one_round_trip_per_store_and_table(): void {
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$rows   = [ 'a5540ce0fa17' => [ 'url' => 'https://kea.test/kereru-5540', 'count' => 17, 'last_seen' => self::tick() ] ];
		$this->set_url_bucket( $store, $bucket, $rows );
		$this->set_url_rank_lists( $store, $bucket, $rows );
		$this->seed_hour_lists();
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$this->warm_url_header( $fire );
			$this->forget_stats_asks();
			$page = $fire( '--sort=count', '--order=desc', '--limit=50' );
			$this->assertTrue( $page['ranked'] );
			// The fine lists live in one Table, the hours' in another; the
			// seeded bucket is store 1's alone, the hours every store's.
			$this->assertCount( 1 + 2 + 1, $this->asked_batches( Stats_Store::NS_URLRANK_S, Stats_Store::NS_URLRANK_HOUR_S ), 'one round trip per store and Table' );
		} finally {
			$restore();
		}
	}

	/**
	 * A page reading two list sets reads both in the round trip one set
	 * takes: the same batches as a reader page, for its lists and for its
	 * header records, each carrying both sets' keys.
	 */
	public function test_an_include_workers_page_reads_both_sets_in_one_round_trip_per_store_and_table(): void {
		$this->seed_family_window();
		[ $fire, , $restore ] = $this->counting_urls_fire();
		$asked                = function ( string ...$args ) use ( $fire ): array {
			$this->forget_stats_asks();
			$this->assertTrue( $fire( '--sort=max_ms', '--order=desc', '--limit=40', ...$args )['ranked'] );
			return [
				$this->asked_batches( Stats_Store::NS_URLRANK_S, Stats_Store::NS_URLRANK_HOUR_S ),
				$this->asked_batches( Stats_Store::NS_URLHDR, Stats_Store::NS_URLHDR_HOUR ),
			];
		};
		try {
			[ $reader_lists, $reader_records ] = $asked();
			[ $lists, $records ]               = $asked( '--include_workers=1' );
		} finally {
			$restore();
		}

		$this->assertCount( \count( $reader_records ), $records, 'the reader page\'s round trips for both sets\' records' );
		$this->assertCount( \count( $reader_lists ), $lists, 'and for both sets\' lists, the slowest and the page\'s' );
		// A DONE marker shares the hour lists' namespace and belongs to no set.
		$ranked = static fn ( array $batch ): array => \array_values( \array_filter( $batch, static fn ( string $key ): bool => ! \in_array( 'done', \explode( ':', $key ), true ) ) );
		foreach ( \array_filter( \array_map( $ranked, [ ...$records, ...$lists ] ) ) as $batch ) {
			$worker = \array_filter( $batch, static fn ( string $key ): bool => \in_array( Stats_Store::WORKER_SHARD_PREFIX, \explode( ':', $key ), true ) );
			$this->assertNotSame( [], $worker, 'each batch carries the worker set' );
			$this->assertNotSame( $batch, \array_values( $worker ), 'beside the reader set' );
		}
	}

	/**
	 * A reply reads its clock once. The firehose logger advances the tick
	 * while a long reply runs, so a page keyed under one bucket must not be
	 * built from, or dated by, the next bucket's window. Driven through the
	 * verb, so a handler reading its clock twice fails here. Seeds distinct
	 * from every default: one URL of count 29, stored only in the later
	 * bucket, and a page of 43 rows, a size no other test caches a page under.
	 */
	public function test_a_url_page_is_built_from_the_bucket_it_is_keyed_under(): void {
		$next = \intdiv( self::tick(), Stats_Store::BUCKET_SECONDS ) * Stats_Store::BUCKET_SECONDS;
		$at   = $next - 1;
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), Stats_Store::bucket_key( $next ), [
			'd7a2e91c4b30' => [ 'url' => 'https://kea.test/weka-2931', 'count' => 29, 'last_seen' => $next ],
		] );
		$repinned                        = false;
		$original                        = Performance_CI_Node::$load_index;
		Performance_CI_Node::$load_index = static function ( string $shard, string $server, array $stores, int $now, bool $errored ) use ( $next, $original, &$repinned ): array {
			// A firehose line logged mid-reply re-pins the tick.
			Core::$now = $next;
			$repinned  = true;
			return ( $original ?? Performance_CI_Node::load_index_default( ... ) )( $shard, $server, $stores, $now, $errored );
		};
		try {
			Core::$now = $at;
			$reply     = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--sort=count', '--order=desc', '--limit=43' ] );
		} finally {
			Performance_CI_Node::$load_index = $original;
			VerbHarness::reset();
		}
		$this->assertTrue( $repinned, 'the fold re-pinned the tick inside the reply' );
		$this->assertIsArray( $reply );
		$this->assertSame( [], $reply['data'], 'the later bucket is outside the keyed window' );
		$this->assertSame( $at, $reply['as_of'], 'and the page dates from the keyed instant' );
	}

	/**
	 * A server's rows are its own keys, so any scope answers: a server with
	 * no rows is zero requests beside another server's traffic.
	 */
	public function test_a_scoped_page_for_a_server_with_no_rows_answers_zero_totals(): void {
		$this->activate_shipped( 'performance', 1 );
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $this->current_url_bucket(), [
			'f6620ab77c04' => [ 'url' => 'https://kea.test/takahe-6620', 'count' => 11, 'last_seen' => self::tick() ],
		], 'kea.test' );
		$page = ( new \ReflectionMethod( Performance_CI_Node::class, 'url_page' ) )->invoke(
			new Performance_CI_Node(),
			'moa.test',
			'',
			false,
			false,
			'count',
			'desc',
			0,
			50,
			$this->live_stores(),
			(int) Core::$now
		);
		$this->assertIsArray( $page );
		$this->assertSame( 0, $page['totals']['requests'], 'moa.test served none of kea.test\'s 11' );
		$this->assertSame( [], $page['data'] );
	}

	// -------------------------------------------------------------------------
	// The URL read's own record: what each verb's firehose lines say.
	// -------------------------------------------------------------------------

	/**
	 * The `m` each `(complete)` of one span carried, in order.
	 *
	 * @param list<array<string,mixed>> $entries What `logged_in()` returned.
	 * @return list<mixed>
	 */
	private static function completed( array $entries, string $span ): array {
		return \array_column( self::entries_of( $entries, "{$span} (complete)" ), 'm' );
	}

	public function test_a_url_page_logs_its_caches_built_then_hit_and_its_fold_rows(): void {
		$this->activate_shipped( 'performance', 3 );
		$this->seed_folded_hours();
		$this->set_url_bucket( $this->stats_store( 1, 86400 ), $this->current_url_bucket(), [
			'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ],
			'c8842df1ab90' => [ 'url' => 'https://kea.test/kiwi-8842', 'count' => 3, 'last_seen' => self::tick() ],
			'd9953e02bc01' => [ 'url' => 'https://kea.test/weka-9953', 'count' => 2, 'last_seen' => self::tick() ],
		] );
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$entries = $this->logged_in( $this->tmp, static function () use ( $fire ): void {
				$fire( '--sort=count', '--order=desc', '--limit=100' );
				$fire( '--sort=count', '--order=desc', '--limit=100' );
			} );
		} finally {
			$restore();
		}

		$this->assertSame( [ 'built', 'hit' ], self::completed( $entries, Flame_Tree::URL_PAGE_CACHE ) );
		$this->assertSame( [ 'built' ], self::completed( $entries, Flame_Tree::URL_HEADER_CACHE ), 'the hit reads no header' );
		$this->assertSame( [ 3 ], self::completed( $entries, Flame_Tree::URL_FOLD ), 'the fold says the rows it folded' );
		$this->assertSame( [], self::entries_of( $entries, Flame_Tree::URL_RANK_LISTS ), 'a header miss answers before the lists' );
	}

	public function test_a_page_with_no_cache_backend_logs_built_not_stored(): void {
		$this->activate_shipped( 'performance', 1 );
		Core::$memd                                 = null;
		\Newspack_Nodes\Cache_Backend::$apcu_usable = static fn (): bool => false;
		try {
			$entries = $this->logged_in(
				$this->tmp,
				static fn (): mixed => VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--sort=count', '--order=desc', '--limit=100' ] )
			);
		} finally {
			\Newspack_Nodes\Cache_Backend::$apcu_usable = null;
		}

		$this->assertSame( [ 'built, not stored' ], self::completed( $entries, Flame_Tree::URL_PAGE_CACHE ) );
		$this->assertSame( [ 'built, not stored' ], self::completed( $entries, Flame_Tree::URL_HEADER_CACHE ) );
	}

	/**
	 * Read one page whose build throws `$thrown`, and return what was logged.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function logged_throwing_page_read( \Throwable $thrown ): array {
		return $this->logged_in(
			$this->tmp,
			static function () use ( $thrown ): void {
				try {
					( new \ReflectionMethod( Performance_CI_Node::class, 'read_through_page' ) )->invoke(
						null,
						Flame_Tree::URL_PAGE_CACHE,
						[ 'kea-7737' ],
						[ 'data' ],
						60,
						static fn (): never => throw $thrown
					);
				} catch ( \Throwable $e ) {
					if ( $thrown === $e ) {
						return;
					}
					throw $e;
				}
				throw new \LogicException( 'the build\'s throwable must propagate' );
			}
		);
	}

	/** A build that throws closes the span with what it threw, in the verb span's words. */
	public function test_a_page_build_that_throws_closes_its_span_with_what_it_threw(): void {
		$this->assertSame(
			[ 'DomainException: kea-7737' ],
			self::completed( $this->logged_throwing_page_read( new \DomainException( 'kea-7737' ) ), Flame_Tree::URL_PAGE_CACHE )
		);
	}

	public function test_a_stop_during_a_page_build_closes_its_span_as_stop(): void {
		$this->assertSame(
			[ 'stop' ],
			self::completed( $this->logged_throwing_page_read( new \Newspack_Nodes\Worker_Should_Stop() ), Flame_Tree::URL_PAGE_CACHE )
		);
	}

	public function test_a_page_build_that_throws_with_no_cache_backend_closes_its_span_with_what_it_threw(): void {
		Core::$memd                                 = null;
		\Newspack_Nodes\Cache_Backend::$apcu_usable = static fn (): bool => false;
		try {
			$entries = $this->logged_throwing_page_read( new \DomainException( 'kea-7744' ) );
		} finally {
			\Newspack_Nodes\Cache_Backend::$apcu_usable = null;
		}

		$this->assertSame( [ 'DomainException: kea-7744' ], self::completed( $entries, Flame_Tree::URL_PAGE_CACHE ) );
	}

	/** A fold that throws still closes its span, saying so, and the throwable propagates. */
	public function test_a_fold_that_throws_closes_its_span_with_what_it_threw(): void {
		$thrown  = null;
		$entries = $this->logged_in(
			$this->tmp,
			function () use ( &$thrown ): void {
				try {
					( new \ReflectionMethod( Performance_CI_Node::class, 'fold_page' ) )->invoke(
						new Performance_CI_Node(),
						'',
						'',
						false,
						false,
						'count',
						'desc',
						0,
						100,
						[ new \stdClass() ],
						self::tick()
					);
				} catch ( \Error $e ) {
					$thrown = $e;
					return;
				}
				$this->fail( 'the fold\'s throwable must propagate' );
			}
		);

		$this->assertNotSame( '', $thrown?->getMessage() ?? '' );
		$this->assertSame( [ 'Error: ' . $thrown?->getMessage() ], self::completed( $entries, Flame_Tree::URL_FOLD ) );
	}

	public function test_a_ranked_page_logs_that_the_lists_served(): void {
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 5, 'last_seen' => self::tick() ] ] );
		$this->set_url_rank_lists( $store, $bucket, [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/wombat-7731', 'count' => 9, 'last_seen' => self::tick() ] ] );
		$this->seed_hour_lists();
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$entries = $this->logged_in( $this->tmp, function () use ( $fire ): void {
				$this->assertTrue( $fire( '--sort=count', '--order=desc', '--limit=100' )['ranked'] );
			} );
		} finally {
			$restore();
		}

		$lists = self::entries_of( $entries, Flame_Tree::URL_RANK_LISTS );
		$this->assertCount( 1, $lists, 'once per attempt that reached the lists' );
		$this->assertSame( 'ranked', $lists[0]['m'] );
		$this->assertGreaterThan( 0, $lists[0]['found'] );
		$this->assertSame( 0, $lists[0]['holes'] );
		$this->assertSame( 1, $lists[0]['keep'], 'kept through a folded record' );
	}

	public function test_a_page_over_a_hole_logs_the_hours_the_fold_answers_for(): void {
		$this->activate_shipped( 'performance', 3 );
		$store  = $this->stats_store( 1, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/weka-3308', 'count' => 5, 'last_seen' => self::tick() ] ] );
		$plan = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) );
		$gap  = $plan['hours'][2];
		$this->seed_hour_lists();
		// Its records and index stand, so the header does; its list is gone.
		foreach ( \range( 0, 2 ) as $partition ) {
			( $this->stats_store( $partition, 86400 ) )->bucket_forget_multi( [ [ Stats_Store::url_rank_parts( 'count', 'desc', '', true ), $gap ] ] );
		}
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$entries = $this->logged_in( $this->tmp, function () use ( $fire ): void {
				$this->assertFalse( $fire( '--sort=count', '--order=desc', '--limit=100' )['ranked'] );
			} );
		} finally {
			$restore();
		}

		$lists = self::entries_of( $entries, Flame_Tree::URL_RANK_LISTS );
		$this->assertCount( 1, $lists );
		$this->assertSame( "fold: holes {$gap}", $lists[0]['m'] );
		$this->assertSame( 1, $lists[0]['holes'] );
		$this->assertSame( 1, $lists[0]['keep'] );
	}

	/**
	 * Reader and worker rows in the open bucket and in one folded hour, as
	 * the writer files them: the rows the fold walks, the lists and records
	 * the ranked page reads, every other planned hour folded idle. Each URL
	 * sits in one key alone, so the lists hold every row whole and the two
	 * paths must answer the same numbers on every sort. Every value differs
	 * on every sort, so no tie decides an order.
	 *
	 * @param array<string,array<string,mixed>> $overflow More named rows for the open bucket.
	 * @return string The folded hour.
	 */
	private function seed_family_window( array $overflow = [] ): string {
		$this->activate_shipped( 'performance', 3 );
		$now    = self::tick();
		$hour   = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) )['hours'][2];
		$at     = (int) \strtotime( \str_replace( '-', ' ', \substr( $hour, 0, 10 ) ) . ' ' . \substr( $hour, 11, 2 ) . ':00 UTC' );
		$url    = static fn ( string $path, int $count, int $timed, float $sum_ms, float $peak, float $min, float $max, int $seen, bool $worker = false ): array => [
			'url'         => 'https://' . self::SEED_SERVER . $path,
			'count'       => $count,
			'timed_count' => $timed,
			'sum_ms'      => $sum_ms,
			'sum_peak_mb' => $peak,
			'min_ms'      => $min,
			'max_ms'      => $max,
			'max_peak_mb' => $peak / $count + 1.5,
			'count_2xx'   => $count - 1,
			'last_seen'   => $seen,
			'worker'      => $worker,
		];
		$fine   = [
			'a4417ce0fa11' => $url( '/kakapo-4417', 41, 37, 1517.0, 82.0, 12.5, 97.0, $now - 40 ),
			'b1308df1ab90' => $url( '/weka-1308', 13, 11, 2860.0, 91.0, 55.0, 480.0, $now - 25 ),
			'c0007e02bc01' => $url( '/jobs/cron-sweep/7?job', 29, 29, 8700.0, 435.0, 120.0, 910.0, $now - 10, true ),
			'd0031f13cd12' => $url( '/jobs/reindex/31?job', 3, 2, 5000.0, 96.0, 1900.0, 3100.0, $now - 55, true ),
		];
		$coarse = [
			'e9920a24de23' => $url( '/takahe-9920', 211, 200, 7000.0, 633.0, 4.0, 350.0, $at + 600 ),
			'f0005b35ef34' => $url( '/jobs/digest/5?job', 67, 60, 42000.0, 1072.0, 260.0, 1500.0, $at + 1300, true ),
		];
		$store  = $this->stats_store( 1, 86400 );
		$bucket = Stats_Store::bucket_key( $now );
		$this->set_url_bucket( $store, $bucket, $fine + $overflow );
		$this->set_url_rank_lists( $store, $bucket, $fine + $overflow );
		foreach ( $coarse as $hash => $row ) {
			$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $hash, $row['worker'] ), [ $hash => $row ] );
		}
		$this->set_url_rank_lists( $store, $hour, $coarse, true );
		foreach ( [ 0, 2 ] as $partition ) {
			$this->set_url_rank_lists( $this->stats_store( $partition, 86400 ), $hour, [], true );
		}
		$this->seed_hour_lists( [ $hour ] );
		return $hour;
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function every_ranked_sort(): array {
		$out = [];
		foreach ( Stats_Store::URL_SORTS as $sort ) {
			foreach ( Stats_Store::URL_ORDERS as $order ) {
				$out[ "{$sort} {$order}" ] = [ $sort, $order ];
			}
		}
		return $out;
	}

	#[DataProvider( 'every_ranked_sort' )]
	public function test_an_include_workers_ranked_page_is_the_fold_s_page( string $sort, string $order ): void {
		$this->seed_family_window();
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$ranked = $fire( "--sort={$sort}", "--order={$order}", '--limit=100', '--include_workers=1' );
			$this->assertSame( 0, $reads(), 'the ranked page walked no shard' );
			// One past the list depth: the page the fold answers.
			$fold = $fire( "--sort={$sort}", "--order={$order}", '--limit=' . ( Stats_Store::URL_RANK_N + 1 ), '--include_workers=1' );
		} finally {
			$restore();
		}

		$this->assertTrue( $ranked['ranked'] );
		$this->assertFalse( $fold['ranked'] );
		$this->assertCount( 6, $fold['data'], 'both families, both tiers' );
		$this->assertEqualsWithDelta( $fold['data'], $ranked['data'], 1e-9, 'row for row' );
		$this->assertSame( $fold['rows'], $ranked['rows'] );
		$this->assertEqualsWithDelta( $fold['totals'], $ranked['totals'], 1e-9 );
		$this->assertEqualsWithDelta( $fold['slowest'], $ranked['slowest'], 1e-9 );
	}

	public function test_an_include_workers_page_runs_no_fold(): void {
		$this->seed_family_window();
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$entries = $this->logged_in( $this->tmp, static function () use ( $fire ): void {
				$fire( '--sort=avg_ms', '--order=desc', '--limit=100', '--include_workers=1' );
			} );
		} finally {
			$restore();
		}

		$this->assertSame( [], self::completed( $entries, Flame_Tree::URL_FOLD ), 'no fold span ran' );
		$this->assertSame( 0, $reads() );
		$this->assertSame( [ 'ranked' ], \array_column( self::entries_of( $entries, Flame_Tree::URL_RANK_LISTS ), 'm' ) );
	}

	public function test_an_include_workers_header_counts_each_family_s_overflow_row(): void {
		// The fold holds `Other` and `Other:worker` as two rows, one a family.
		$this->seed_family_window( [
			Stats_Store::OTHER_KEY        => [ 'count' => 17, 'timed_count' => 16, 'sum_ms' => 816.0, 'sum_peak_mb' => 34.0, 'last_seen' => self::tick() - 5 ],
			Stats_Store::OTHER_WORKER_KEY => [ 'count' => 19, 'timed_count' => 19, 'sum_ms' => 1938.0, 'sum_peak_mb' => 57.0, 'worker' => true, 'last_seen' => self::tick() - 5 ],
		] );
		[ $fire, , $restore ] = $this->counting_urls_fire();
		try {
			$ranked = $fire( '--sort=count', '--order=desc', '--limit=100', '--include_workers=1' );
			$fold   = $fire( '--sort=count', '--order=desc', '--limit=' . ( Stats_Store::URL_RANK_N + 1 ), '--include_workers=1' );
		} finally {
			$restore();
		}

		$this->assertTrue( $ranked['ranked'] );
		$this->assertSame( 8, $fold['rows'], 'six URLs and two overflow rows' );
		$this->assertSame( $fold['rows'], $ranked['rows'] );
		$this->assertEqualsWithDelta( $fold['totals'], $ranked['totals'], 1e-9 );
	}

	public function test_an_include_workers_hour_without_its_done_marker_reads_as_provisional(): void {
		$hour = $this->seed_family_window();
		( $this->stats_store( 1, 86400 ) )->bucket_forget_multi( [ [ Stats_Store::url_rank_done_parts( Stats_Store::server_key( self::SEED_SERVER ) ), $hour ] ] );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--include_workers=1' );
		} finally {
			$restore();
		}

		$this->assertTrue( $page['ranked'] );
		$this->assertTrue( $page['provisional'], 'the waiting hour is owed, not a hole' );
		$this->assertSame( 0, $reads(), 'no walk of the index' );
		$this->assertSame( [ 'a4417ce0fa11', 'c0007e02bc01', 'b1308df1ab90', 'd0031f13cd12' ], \array_column( $page['data'], 'hash' ), 'the waiting hour adds neither family\'s rows' );
		$this->assertSame( 86, $page['totals']['requests'] ?? null, 'nor either family\'s record' );
	}

	/**
	 * Errored and clean rows in the open bucket and one folded hour, as the
	 * writer files them, every other planned hour folded idle. `/weka-1308`
	 * errors in the open bucket and runs clean, and far bigger, in the hour;
	 * `/tui-9913` and `/jobs/purge/2?job` time out and carry no duration;
	 * `/clean-5050` never errs. Two ties decide an order, both by hash: the
	 * two rows of 2 errors, and the two timeouts at 0 on the timed sorts.
	 *
	 * @return string The folded hour.
	 */
	private function seed_errored_window(): string {
		$this->activate_shipped( 'performance', 3 );
		$now  = self::tick();
		$hour = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, $now ) )['hours'][2];
		$at   = (int) \strtotime( \str_replace( '-', ' ', \substr( $hour, 0, 10 ) ) . ' ' . \substr( $hour, 11, 2 ) . ':00 UTC' );
		$url  = static fn ( string $path, int $count, int $ok, int $timed, float $sum_ms, float $peak, float $min, float $max, int $seen, bool $worker = false ): array => [
			'url'         => 'https://' . self::SEED_SERVER . $path,
			'count'       => $count,
			'count_2xx'   => $ok,
			'errors'      => $count - $ok,
			'timed_count' => $timed,
			'sum_ms'      => $sum_ms,
			'sum_peak_mb' => $peak,
			'min_ms'      => $min,
			'max_ms'      => $max,
			'max_peak_mb' => $peak / $count + 0.5,
			'last_seen'   => $seen,
			'worker'      => $worker,
		];
		$fine   = [
			'b2308df1ab90' => $url( '/weka-1308', 13, 11, 11, 2860.0, 26.0, 55.0, 480.0, $now - 25 ),
			'c3913e02bc01' => $url( '/tui-9913', 4, 0, 0, 0.0, 12.0, 0.0, 0.0, $now - 40 ),
			'e5050c1ea000' => $url( '/clean-5050', 50, 50, 50, 500.0, 50.0, 5.0, 20.0, $now - 5 ),
			'a0002f13cd12' => $url( '/jobs/purge/2?job', 2, 0, 0, 0.0, 10.0, 0.0, 0.0, $now - 15, true ),
		];
		$coarse = [
			'b2308df1ab90' => $url( '/weka-1308', 500, 500, 500, 5000.0, 1000.0, 1.0, 9000.0, $at + 600 ),
			'f0009d35ef34' => $url( '/jobs/digest/9?job', 9, 6, 9, 810.0, 63.0, 30.0, 200.0, $at + 1300, true ),
		];
		$store  = $this->stats_store( 1, 86400 );
		$bucket = Stats_Store::bucket_key( $now );
		$this->set_url_bucket( $store, $bucket, $fine );
		$this->set_url_rank_lists( $store, $bucket, $fine );
		foreach ( $coarse as $hash => $row ) {
			$this->seed_url_hour( $store, $hour, Stats_Store::url_shard( $hash, $row['worker'] ), [ $hash => $row ] );
		}
		$this->set_url_rank_lists( $store, $hour, $coarse, true );
		foreach ( [ 0, 2 ] as $partition ) {
			$this->set_url_rank_lists( $this->stats_store( $partition, 86400 ), $hour, [], true );
		}
		$this->seed_hour_lists( [ $hour ] );
		return $hour;
	}

	/** @return array<string,array{0:string,1:string,2:bool}> */
	public static function every_errored_sort(): array {
		$out = [];
		foreach ( self::every_ranked_sort() as $name => [ $sort, $order ] ) {
			$out[ "{$name}, readers" ]       = [ $sort, $order, false ];
			$out[ "{$name}, with workers" ] = [ $sort, $order, true ];
		}
		return $out;
	}

	#[DataProvider( 'every_errored_sort' )]
	public function test_an_errors_only_ranked_page_is_the_fold_s_page( string $sort, string $order, bool $workers ): void {
		$this->seed_errored_window();
		$args = [ "--sort={$sort}", "--order={$order}", '--errors_only=1', ...( $workers ? [ '--include_workers=1' ] : [] ) ];
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$ranked = $fire( ...$args, ...[ '--limit=100' ] );
			$this->assertSame( 0, $reads(), 'the ranked page walked no shard' );
			$fold = $fire( ...$args, ...[ '--limit=' . ( Stats_Store::URL_RANK_N + 1 ) ] );
		} finally {
			$restore();
		}

		$this->assertTrue( $ranked['ranked'] );
		$this->assertFalse( $fold['ranked'] );
		$this->assertCount( $workers ? 4 : 2, $fold['data'], 'the URLs that errored, and none that did not' );
		$this->assertEqualsWithDelta( $fold['data'], $ranked['data'], 1e-9, 'row for row' );
		$this->assertSame( $fold['rows'], $ranked['rows'] );
		$this->assertEqualsWithDelta( $fold['totals'], $ranked['totals'], 1e-9 );
		$this->assertEqualsWithDelta( $fold['slowest'], $ranked['slowest'], 1e-9 );
	}

	public function test_an_errors_only_page_counts_only_the_buckets_each_url_errored_in(): void {
		$this->seed_errored_window();
		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--sort=count', '--order=desc', '--errors_only=1', '--limit=100' ] );

		$this->assertTrue( $page['ranked'] );
		$this->assertSame( [ 'c3913e02bc01' => 4, 'b2308df1ab90' => 2 ], \array_column( $page['data'], 'errors', 'hash' ), 'ranked by errors' );
		$this->assertSame( 13, $page['data'][1]['count'], 'the open bucket alone: the clean hour\'s 500 requests are not errored traffic' );
		$this->assertEqualsWithDelta( 480.0, $page['data'][1]['max_ms'], 1e-9, 'nor its 9000 ms' );
		$this->assertSame( 17, $page['totals']['requests'] );
		$this->assertSame( 6, $page['totals']['errors'] );
		$this->assertEqualsWithDelta( 0.0, $page['data'][0]['avg_ms'], 1e-9, 'a timeout counts, and has no duration' );
		$this->assertEqualsWithDelta( 2860.0 / 11, $page['totals']['avg_ms'], 1e-9, 'the timeouts count toward requests, not toward the mean' );
	}

	public function test_an_errors_only_page_runs_no_fold(): void {
		$this->seed_errored_window();
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$entries = $this->logged_in( $this->tmp, static function () use ( $fire ): void {
				$fire( '--sort=avg_ms', '--order=desc', '--limit=100', '--errors_only=1', '--include_workers=1' );
			} );
		} finally {
			$restore();
		}

		$this->assertSame( [], self::completed( $entries, Flame_Tree::URL_FOLD ), 'no fold span ran' );
		$this->assertSame( 0, $reads() );
		$this->assertSame( [ 'ranked' ], \array_column( self::entries_of( $entries, Flame_Tree::URL_RANK_LISTS ), 'm' ) );
	}

	public function test_an_errors_only_hour_without_its_done_marker_reads_as_provisional(): void {
		$hour = $this->seed_errored_window();
		( $this->stats_store( 1, 86400 ) )->bucket_forget_multi( [ [ Stats_Store::url_rank_done_parts( Stats_Store::server_key( self::SEED_SERVER ) ), $hour ] ] );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100', '--errors_only=1', '--include_workers=1' );
		} finally {
			$restore();
		}

		$this->assertTrue( $page['ranked'] );
		$this->assertTrue( $page['provisional'], 'the waiting hour is owed, not a hole' );
		$this->assertSame( 0, $reads() );
		$this->assertSame( [ 'c3913e02bc01', 'a0002f13cd12', 'b2308df1ab90' ], \array_column( $page['data'], 'hash' ), 'the waiting hour adds nothing' );
		$this->assertSame( 8, $page['totals']['errors'] ?? null );
	}

	/**
	 * Two open-hour buckets of `URL_RANK_N + 5` rows each, the clock twenty
	 * minutes into the hour so both are fine keys, over URLs spread across
	 * both, every figure differing by URL and by bucket. Five URLs time out
	 * in the earlier bucket, where they rank at 0, and run slowest in the
	 * later one, whose `min_ms asc` list cuts them: `min_ms asc` is not
	 * exact, and a row listed by its timeouts alone carries no minimum.
	 *
	 * @return array{0: array<string,list<array<string,mixed>>>, 1: \Closure(): void} Each URL's
	 *         named rows by bucket, and the clock's restore.
	 */
	private function seed_spread_window(): array {
		$this->activate_shipped( 'performance', 3 );
		$previous    = Core::$clock;
		$ticked      = Core::$now;
		$now         = self::tick() - self::tick() % 3600 + 20 * 60 + 30;
		Core::$clock = static fn (): float => (float) $now;
		Core::right_now();
		$store   = $this->stats_store( 1, 86400 );
		$earlier = Stats_Store::bucket_key( $now - Stats_Store::BUCKET_SECONDS );
		$later   = Stats_Store::bucket_key( $now );
		$row     = static fn ( string $path, int $count, int $timed, float $avg, float $min, float $max, float $peak, int $seen ): array => [
			'url'         => 'https://' . self::SEED_SERVER . $path,
			'count'       => $count,
			'count_2xx'   => $count,
			'timed_count' => $timed,
			'sum_ms'      => $avg * $timed,
			'min_ms'      => $min,
			'max_ms'      => $max,
			'sum_peak_mb' => $peak * $count,
			'max_peak_mb' => $peak + 1.5,
			'last_seen'   => $seen,
		];
		$buckets = [ $earlier => [], $later => [] ];
		for ( $i = 0; $i < Stats_Store::URL_RANK_N + 5; $i++ ) {
			$hash = \sprintf( 'a%011x', $i );
			$path = "/spread-{$i}";
			$buckets[ $earlier ][ $hash ] = $row( $path, 10 + $i, 10 + $i, 100.0 + 3 * $i, 1000.0 + 11 * $i, 3000.0 + 13 * $i, 2.0 + $i / 100, $now - 400 - $i );
			if ( $i >= 5 ) {
				$j = Stats_Store::URL_RANK_N + 4 - $i;
				$buckets[ $later ][ $hash ] = $row( $path, 300 - $i, 290 - $i, 700.0 - 2 * $i, 1005.0 + 11 * $j, 3007.0 + 13 * $j, 9.0 - $i / 100, $now - 10 - $i );
			}
		}
		for ( $k = 0; $k < 5; $k++ ) {
			$hash = \sprintf( 'b%011x', $k );
			$path = "/timeout-{$k}";
			$buckets[ $earlier ][ $hash ] = $row( $path, 3 + $k, 0, 0.0, 0.0, 0.0, 30.0 + $k, $now - 700 - $k );
			$buckets[ $later ][ $hash ]   = $row( $path, 4 + $k, 4 + $k, 9200.0 + $k, 9000.0 + $k, 9500.0 + $k, 31.0 + $k, $now - 5 - $k );
		}
		$by_url = [];
		foreach ( $buckets as $bucket => $rows ) {
			$this->set_url_bucket( $store, $bucket, $rows );
			$this->set_url_rank_lists( $store, $bucket, $rows );
			foreach ( $rows as $hash => $named ) {
				$by_url[ (string) $hash ][] = $named;
			}
		}
		$this->seed_hour_lists();
		return [
			$by_url,
			static function () use ( $previous, $ticked ): void {
				Core::$clock = $previous;
				Core::$now   = $ticked;
			},
		];
	}

	/** @return array<string,array{0:string,1:string,2:bool}> Sort, order, and whether decision 28 calls it exact. */
	public static function every_sort_and_its_exactness(): array {
		$exact = [ 'url asc', 'url desc', 'max_ms desc', 'last_updated desc' ];
		$out   = [];
		foreach ( self::every_ranked_sort() as $name => [ $sort, $order ] ) {
			$out[ $name ] = [ $sort, $order, \in_array( $name, $exact, true ) ];
		}
		return $out;
	}

	#[DataProvider( 'every_sort_and_its_exactness' )]
	public function test_a_page_over_urls_spread_across_cut_lists_is_exact_or_bounded( string $sort, string $order, bool $exact ): void {
		[ $by_url, $unpin ]         = $this->seed_spread_window();
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$ranked = $fire( "--sort={$sort}", "--order={$order}", '--limit=100' );
			$this->assertSame( 0, $reads(), 'the ranked page walked no shard' );
			$fold = $fire( "--sort={$sort}", "--order={$order}", '--limit=' . ( Stats_Store::URL_RANK_N + 10 ) );
		} finally {
			$restore();
			$unpin();
		}

		$this->assertTrue( $ranked['ranked'] );
		$this->assertCount( Stats_Store::URL_RANK_N + 10, $fold['data'], 'the fold holds every URL' );
		if ( $exact ) {
			$top = \array_slice( $fold['data'], 0, 100 );
			$this->assertSame( \array_column( $top, 'hash' ), \array_column( $ranked['data'], 'hash' ), 'exact: the order' );
			$this->assertEqualsWithDelta( \array_column( $top, $sort ), \array_column( $ranked['data'], $sort ), 1e-9, 'exact: the sort key' );
		}
		// Every ranked row is the fold's, restricted to the keys it was listed in.
		$folded = \array_column( $fold['data'], null, 'hash' );
		foreach ( $ranked['data'] as $row ) {
			$whole = $folded[ $row['hash'] ];
			$means = \array_map( static fn ( array $named ): float => $named['sum_ms'] / \max( 1, $named['timed_count'] ), \array_filter( $by_url[ $row['hash'] ], static fn ( array $named ): bool => $named['timed_count'] > 0 ) );
			$this->assertLessThanOrEqual( $whole['count'], $row['count'], "{$row['hash']}: a sum never over the fold's" );
			$this->assertLessThanOrEqual( $whole['max_ms'], $row['max_ms'], "{$row['hash']}: a maximum never over the fold's" );
			$this->assertLessThanOrEqual( $whole['last_updated'], $row['last_updated'] );
			if ( $row['timed_count'] > 0 ) {
				$this->assertGreaterThanOrEqual( $whole['min_ms'], $row['min_ms'], "{$row['hash']}: a minimum never under the fold's" );
				$this->assertGreaterThanOrEqual( \min( $means ) - 1e-9, $row['avg_ms'], "{$row['hash']}: a mean within its buckets'" );
				$this->assertLessThanOrEqual( \max( $means ) + 1e-9, $row['avg_ms'] );
			}
		}
	}

	/**
	 * An hour whose DONE markers are gone is one the writer still owes:
	 * waiting on its first settle after the close, or re-ranking a late write. Its lists stand
	 * but it contributes nothing, and the page is served ranked,
	 * provisional, with no walk of the index.
	 */
	public function test_an_hour_without_its_done_markers_reads_as_provisional_empty(): void {
		$this->activate_shipped( 'performance', 3 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $this->stats_store( 1, 86400 ), $bucket, [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/tuatara-6120', 'count' => 5, 'last_seen' => self::tick() ] ] );
		$this->set_url_rank_lists( $this->stats_store( 1, 86400 ), $bucket, [ 'b7731ce0fa11' => [ 'url' => 'https://kea.test/tuatara-6120', 'count' => 5, 'last_seen' => self::tick() ] ] );
		$waiting = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) )['hours'][3];
		$this->seed_hour_lists();
		$this->set_url_rank_lists( $this->stats_store( 1, 86400 ), $waiting, [ 'c4417de0ab29' => [ 'url' => 'https://kea.test/kiwi-4417', 'count' => 83, 'last_seen' => self::tick() - 3 * 3600 ] ], true );
		foreach ( \range( 0, 2 ) as $partition ) {
			( $this->stats_store( $partition, 86400 ) )->bucket_forget_multi( [ [ Stats_Store::url_rank_done_parts( Stats_Store::server_key( self::SEED_SERVER ) ), $waiting ] ] );
		}
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100' );
		} finally {
			$restore();
		}

		$this->assertTrue( $page['ranked'], 'served from the lists' );
		$this->assertTrue( $page['provisional'], 'and never cached as the whole answer' );
		$this->assertSame( 0, $reads(), 'no walk of the index' );
		$this->assertSame( [ 'b7731ce0fa11' ], \array_column( $page['data'], 'hash' ), 'the waiting hour adds nothing' );
		$this->assertSame( 5, $page['totals']['requests'] ?? null, 'nor its header record' );
	}

	/**
	 * An hour no fold has reached holds no index at all. Its header and its
	 * lists read as waiting, not as holes, so no poll walks the index for it.
	 */
	public function test_an_hour_no_fold_reached_never_walks_the_index(): void {
		$this->activate_shipped( 'performance', 3 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $this->stats_store( 2, 86400 ), $bucket, [ 'd9920fa1bc37' => [ 'url' => 'https://kea.test/takahe-9920', 'count' => 7, 'last_seen' => self::tick() ] ] );
		$this->set_url_rank_lists( $this->stats_store( 2, 86400 ), $bucket, [ 'd9920fa1bc37' => [ 'url' => 'https://kea.test/takahe-9920', 'count' => 7, 'last_seen' => self::tick() ] ] );
		$plan = Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) );
		$this->seed_hour_lists( [ $plan['hours'][4], $plan['hours'][5] ] );
		[ $fire, $reads, $restore ] = $this->counting_urls_fire();
		try {
			$page = $fire( '--sort=count', '--order=desc', '--limit=100' );
		} finally {
			$restore();
		}

		$this->assertTrue( $page['ranked'] );
		$this->assertTrue( $page['provisional'] );
		$this->assertSame( 0, $reads() );
		$this->assertSame( 7, $page['totals']['requests'] ?? null, 'the header sums what stands' );
	}

	/**
	 * One page build reads each server's DONE marker for each hour once per
	 * store, though the header, its `slowest` lists and the ranked page all
	 * ask whether the hour is owed: the reply's stores memoize the markers.
	 */
	public function test_a_page_build_reads_each_done_marker_once_per_store(): void {
		$this->activate_shipped( 'performance', 3 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $bucket, [ 'a5518bd2ce07' => [ 'url' => 'https://kea.test/pukeko-5518', 'count' => 13, 'last_seen' => self::tick() ] ] );
		$this->set_url_rank_lists( $this->stats_store( 0, 86400 ), $bucket, [ 'a5518bd2ce07' => [ 'url' => 'https://kea.test/pukeko-5518', 'count' => 13, 'last_seen' => self::tick() ] ] );
		$this->seed_hour_lists();
		$hours = \count( Stats_Store::read_plan( Stats_Store::retention_buckets( 86400, self::tick() ) )['hours'] );
		$this->forget_stats_asks();

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--sort=count', '--order=desc', '--limit=100' ] );

		$this->assertTrue( $page['ranked'], 'the build reached the lists' );
		$markers = \array_filter( $this->asked_keys( Stats_Store::NS_URLRANK_HOUR_S ), static fn ( string $key ): bool => \str_contains( $key, ':done:' ) );
		$this->assertCount( $hours * 3, $markers, 'one marker an hour a store' );
	}

}
