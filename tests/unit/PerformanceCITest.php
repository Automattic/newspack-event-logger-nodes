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
 * stats live in SQLite Ledgers under that scratch base, appended through a
 * partition's writer as a settle appends them (`append()`, `seed_urls()`);
 * the shared `Core::$memd` handle is an in-memory `\Memcached` for the URL
 * page cache alone.
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

	/** The server a seeded URL row files under unless it names its own. */
	private const SEED_SERVER = 'example.com';

	/**
	 * Append `$rows` to the stats Ledgers as partition `$partition`'s settle
	 * does, and fail the test on a Ledger that refused or dropped any.
	 *
	 * @param array<string,list<array{0:int,1:string,2:string,3:list<int|float|null>}>> $rows Ledger => rows.
	 */
	private function append( array $rows, int $partition = 0 ): void {
		foreach ( $this->stats_store( $partition )->append_span( $rows ) as $ledger => $reply ) {
			$this->assertSame( 0, $reply['dropped'] ?? null, "{$ledger} kept every seeded row" );
		}
	}

	/**
	 * Append URL rows in the bucket starting at `$t`, as a settle files
	 * them: each under its server's family key, beside its name, its
	 * server's and its path's words. A field a row leaves out takes what
	 * `count` alike requests give — every one timed and 2xx at
	 * `sum_ms / count`, seen at `$t`.
	 *
	 * @param array<string,array<string,mixed>> $urls URL => `Stats_Store::ROW_FIELD_NAMES` fields, plus `server` and `worker`.
	 */
	private function seed_urls( array $urls, ?int $t = null, int $partition = 0 ): void {
		$t  ??= Stats_Store::bucket_start( self::tick() );
		$hour = $t - $t % Stats_Store::HOUR_SECONDS;
		$rows = [];
		foreach ( $urls as $url => $fields ) {
			$server = (string) ( $fields['server'] ?? self::SEED_SERVER );
			$worker = ! empty( $fields['worker'] );
			$rows[ Stats_Store::LEDGER_URL_ROWS ][] = [ $t, Stats_Store::url_rows_key( $server, $worker ), $url, self::url_columns( $fields, $t ) ];
			$rows[ Stats_Store::LEDGER_NAMES ][]    = [ $hour, Stats_Store::hash_key( Log_Manager::url_hash( $url ) ), $url, [] ];
			$rows[ Stats_Store::LEDGER_NAMES ][]    = [ $hour, Stats_Store::servers_key( $worker ), $server, [] ];
			foreach ( Stats_Store::url_words( $url ) as $word ) {
				$rows[ Stats_Store::LEDGER_SEARCH ][] = [ $hour, $word, $url, [] ];
			}
		}
		$this->append( $rows, $partition );
	}

	/**
	 * A URL row's columns, in `Stats_Store::ROW_FIELD_NAMES` order.
	 *
	 * @param array<string,mixed> $fields Named fields; see seed_urls().
	 * @return list<int|float|null>
	 */
	private static function url_columns( array $fields, int $t ): array {
		$count  = (int) ( $fields['count'] ?? 1 );
		$sum_ms = (float) ( $fields['sum_ms'] ?? 0.0 );
		$timed  = (int) ( $fields['timed_count'] ?? $count );
		$mean   = $timed > 0 ? $sum_ms / $timed : 0.0;
		$named  = $fields + [
			'count'       => $count,
			'timed_count' => $timed,
			'sum_ms'      => $sum_ms,
			'sum_peak_mb' => 0.0,
			'count_2xx'   => $count,
			'count_3xx'   => 0,
			'count_4xx'   => 0,
			'count_5xx'   => 0,
			'errors'      => 0,
			'min_ms'      => $timed > 0 ? $mean : null,
			'max_ms'      => $timed > 0 ? $mean : null,
			'max_peak_mb' => 0.0,
			'last_seen'   => $t,
		];
		return \array_map( static fn ( string $name ): int|float|null => $named[ $name ], \array_values( Stats_Store::ROW_FIELD_NAMES ) );
	}

	/** A URL the chart tests seed. */
	private const PLAN_URL = 'https://example.com/plan-4471';

	/** The start of the bucket `Stats_Store::bucket_key()` names `$key`. */
	private static function t( string $key ): int {
		[ $year, $month, $day, $hour, $minute ] = \array_map( 'intval', \explode( '-', $key ) );
		return (int) \gmmktime( $hour, $minute, 0, $month, $day, $year );
	}

	/** A `stats:totals` row: `count` and `sum_ms` the timed requests', the rest every request's. */
	private static function total( int $t, int $count, float $sum_ms, int $requests, float $sum_peak_mb ): array {
		return [ $t, Stats_Store::SITE, '', [ $count, $sum_ms, $requests, $sum_peak_mb ] ];
	}

	/** A `stats:dims` row: `$value` on axis `$dim`, the site's or `$server`'s; every request timed unless `$timed` says. */
	private static function dim( int $t, string $dim, string $value, int $count, float $sum_ms, float $sum_peak_mb, ?int $timed = null, string $server = '' ): array {
		return [ $t, Stats_Store::dim_key( $dim, $server ), $value, [ $count, $sum_ms, $sum_peak_mb, $timed ?? $count ] ];
	}

	/** A `stats:categories` row, the site's or `$server`'s. */
	private static function cat( int $t, string $category, float $sum_time, int $sum_count, int $samples, string $server = '' ): array {
		return [ $t, Stats_Store::server_scope( $server ), $category, [ $sum_time, $sum_count, $samples ] ];
	}

	/**
	 * One scope's `stats:leaderboard` rows: the requests it profiled, then
	 * each category's `[ samples, sum_time, sum_count ]`.
	 *
	 * @param array<string,array{0:int,1:float,2:int}> $categories Category => its columns.
	 * @return list<array{0:int,1:string,2:string,3:list<int|float>}>
	 */
	private static function board( int $t, int $count, float $sum_req_time, array $categories, string $server = '' ): array {
		$scope = Stats_Store::server_scope( $server );
		$rows  = [ [ $t, $scope, '', [ $count, $sum_req_time, 0 ] ] ];
		foreach ( $categories as $category => $columns ) {
			$rows[] = [ $t, $scope, $category, $columns ];
		}
		return $rows;
	}

	/**
	 * A verb's mount lives for the rest of the request, and a later verb in
	 * the same POST reads through it rather than mounting again (ADR-23).
	 */
	public function test_a_verbs_mount_lives_for_the_request_and_the_next_verb_reuses_it(): void {
		$this->assertIsArray( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' ) );
		$mount = Core::node( Stats_Store::LEDGER_DIMS );
		$this->assertInstanceOf( \Newspack_Nodes\Ledger_Node::class, $mount, 'the mount outlives the verb that made it' );

		$this->assertIsArray( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=status' ) );

		$this->assertSame( $mount, Core::node( Stats_Store::LEDGER_DIMS ), 'the next verb reads through the same mount' );
	}

	public function test_a_verb_reached_through_dispatch_leaves_its_mount_for_the_next(): void {
		// MCP mounts the request graph and calls the CI's dispatch(), never its fill().
		VerbHarness::request_graph();
		$ci = Core::node( 'performance' );
		$this->assertInstanceOf( Performance_CI_Node::class, $ci );

		$this->assertIsArray( $ci->dispatch( 'urls' ) );
		$mount = Core::node( Stats_Store::LEDGER_NAMES );
		$this->assertInstanceOf( \Newspack_Nodes\Ledger_Node::class, $mount );
		$this->assertIsArray( $ci->dispatch( 'url_breakdown', [ 'a4471ab0c0de', '--breakdown=method' ] ) );
		$this->assertSame( $mount, Core::node( Stats_Store::LEDGER_NAMES ) );
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
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'url Tables did not mount' ) ),
			'a root refusal is no backend that cannot open'
		);
	}

	public function test_stats_fail_soft_when_a_table_cannot_open(): void {
		$this->seed_urls( [ 'https://kea.test/sku-4471' => [ 'count' => 7, 'sum_ms' => 77.0 ] ] );
		\Newspack_Nodes\Sqlite_Arm::$available = static fn (): bool => false;
		try {
			$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( 'https://kea.test/sku-4471' ) );
		} finally {
			\Newspack_Nodes\Sqlite_Arm::$available = null;
		}
		$this->assertIsArray( $reply, 'no blob, no throw' );
		$this->assertSame( 7, $reply['stats']['count'] );
		$this->assertNull( Core::node( Stats_Store::TABLE_URL . '.p0' ), 'a mount that threw leaves nothing mounted' );
		$this->assertNotEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'url Tables did not mount' ) && \str_contains( $line, 'pdo_sqlite' ) ),
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
		$reply = $this->fire_with_topology( 'stats-kea-4471', 'make_node Table flame-stats:url ' . \str_repeat( 'k', 204 ) . ' 777 wpdb', [], 'dump_url', 'a4471ab0c0de' );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'Table flame-stats:url: wpdb backend cannot hold namespace', $reply );
	}

	public function test_a_tables_dir_that_is_a_symlink_fails_the_verb_loud(): void {
		// A path the runtime refuses to adopt is the operator's to fix, not a
		// backend that cannot open, though its refusal names the Table too.
		\mkdir( $this->tmp . '/tables-kea-5510' );
		\symlink( $this->tmp . '/tables-kea-5510', $this->tmp . '/tables' );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'a4471ab0c0de' );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'symlink or path traversal detected', $reply );
	}

	public function test_a_table_declared_with_a_zero_ttl_fails_the_verb_loud(): void {
		$reply = $this->fire_with_topology( 'stats-kea-3717', 'make_node Table flame-stats:url evlog:p<partition> 0 sqlite', [], 'dump_url', 'a4471ab0c0de' );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'TTL', $reply );
	}

	public function test_a_ledger_two_topologies_declare_differently_fails_the_verb_loud(): void {
		// Operator misconfiguration is no unreachable backend (decision 3): it says so.
		$reply = $this->fire_with_topology( 'stats-kea-7731', 'make_node Ledger ' . Stats_Store::LEDGER_TOTALS . ' 3600 41 count', [ 'performance' ], 'overview', '' );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'declared differently', $reply );
	}

	/**
	 * Fire `$verb` with a one-partition user topology holding `$line`
	 * active, beside the `$beside` topologies.
	 *
	 * @param string       $name   The user topology's name.
	 * @param string       $line   Its one `make_node` line.
	 * @param list<string> $beside Other active topologies.
	 * @param string       $verb   The verb fired.
	 * @param string       $args   Its arguments.
	 * @return mixed The verb's reply.
	 */
	private function fire_with_topology( string $name, string $line, array $beside, string $verb, string $args ): mixed {
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
			return VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, $args );
		} finally {
			\Newspack_Nodes\Topology_Registry::register_user_dir( $previous );
		}
	}

	public function test_url_breakdown_mounts_only_the_ledgers_it_reads(): void {
		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'a4471ab0c0de --breakdown=status' );

		$this->assertIsArray( $reply );
		$this->assertInstanceOf( \Newspack_Nodes\Ledger_Node::class, Core::node( Stats_Store::LEDGER_URL_DIMS ) );
		$this->assertNull( Core::node( Stats_Store::LEDGER_URL_ROWS ), 'the URL rows are not mounted' );
		$this->assertNull( Core::node( Stats_Store::TABLE_URL . '.p0' ), 'nor the url Table' );
		$this->assertSame( [], \Newspack_Nodes\Ledger_Node::partition_files( Stats_Store::LEDGER_URL_DIMS ), 'a mount creates no file its writer has not' );
	}

	public function test_overview_verb_returns_empty_shape_when_no_data(): void {
		// No URL buckets seeded — verb still returns the canonical envelope
		// with zeroed totals + empty leaderboard.
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'overview' );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['total_requests'] );
		$this->assertNull( $result['global_avg_ms'], 'the mean of no timed request is not measured' );
		$this->assertNull( $result['global_avg_peak_mb'] );
		$this->assertNull( $result['global_leaderboard']['avg_ms'] );
		$this->assertCount( 288, $result['slots'] );
		// The URL-set facts belong to the `urls` verb now.
		$this->assertArrayNotHasKey( 'total_urls', $result );
		$this->assertArrayNotHasKey( 'slowest_urls', $result );
		$this->assertArrayNotHasKey( 'most_requested', $result );
	}

	/** A window whose every request timed out has no mean duration, and its peak mean stays. */
	public function test_an_overview_of_timeouts_alone_has_no_mean_duration(): void {
		$this->append( [ Stats_Store::LEDGER_TOTALS => [ self::total( Stats_Store::bucket_start( self::tick() ), 0, 0.0, 7, 35.0 ) ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertSame( 0, $result['total_requests'] );
		$this->assertNull( $result['global_avg_ms'] );
		$this->assertNull( $result['global_leaderboard']['avg_ms'] );
		$this->assertEqualsWithDelta( 5.0, $result['global_avg_peak_mb'], 1e-9, 'seven requests\' peaks over seven requests' );
	}

	/** A server none of whose requests timed has a null board mean too. */
	public function test_a_server_with_no_timed_request_has_a_null_board_mean(): void {
		$this->append( [ Stats_Store::LEDGER_DIMS => [ self::dim( Stats_Store::bucket_start( self::tick() ), 'server', 'edge-takahe.test', 7, 0.0, 3.5, 0 ) ] ] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--server=edge-takahe.test' )['global_leaderboard'];

		$this->assertNull( $board['avg_ms'] );
	}

	public function test_overview_verb_sums_the_totals(): void {
		$this->append( [ Stats_Store::LEDGER_TOTALS => [ self::total( Stats_Store::bucket_start( self::tick() ), 4, 2000.0, 4, 40.0 ) ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertSame( 4, $result['total_requests'] );
		$this->assertEquals( 500.0, $result['global_avg_ms'] );
		$this->assertEquals( 10.0, $result['global_avg_peak_mb'] );
	}

	/**
	 * At 14:37 a chart draws the 288 slots from 2026-09-28-14-40 to
	 * 2026-09-29-14-35: a slot before them, and one past now, are not drawn.
	 */
	public function test_a_chart_draws_the_trailing_288_slots(): void {
		self::clock_at( \gmmktime( 14, 37, 11, 9, 29, 2026 ) );
		$rows = [];
		foreach ( [
			'2026-09-28-14-35' => 41, // One slot before the window.
			'2026-09-28-14-40' => 43, // The oldest slot drawn.
			'2026-09-29-14-35' => 37, // The current bucket.
			'2026-09-29-14-45' => 47, // Past now.
		] as $bucket => $count ) {
			$rows[] = self::dim( self::t( $bucket ), 'ua', 'kea-ua/7', $count, 4.3, 0.4 );
		}
		$this->append( [ Stats_Store::LEDGER_DIMS => $rows ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=ua' );

		$this->assertCount( 288, $reply['slots'] );
		$this->assertSame( [ '2026-09-29-14-35', '2026-09-28-14-40' ], [ $reply['slots'][0], $reply['slots'][287] ] );
		$this->assertEquals(
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
		self::clock_at( \gmmktime( 14, 37, 11, 9, 29, 2026 ) );
		$this->seed_urls( [ self::PLAN_URL => [ 'count' => 6 ] ], self::t( '2026-09-29-14-35' ) );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, \str_replace( '{hash}', Log_Manager::url_hash( self::PLAN_URL ), $args ) );

		$slots = $reply['slots'] ?? [];
		$this->assertCount( 288, $slots, 'the chart draws 24 hours whatever the retention window' );
		$this->assertSame( [ '2026-09-29-14-35', '2026-09-29-14-30', '2026-09-28-14-40' ], [ $slots[0], $slots[1], $slots[287] ] );
		$this->assertArrayNotHasKey( 'plan', $reply );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function chart_replies(): array {
		return [
			'overview'      => [ 'overview', '' ],
			'dump_url'      => [ 'dump_url', '{hash}' ],
			'url_breakdown' => [ 'url_breakdown', '{hash} --breakdown=status' ],
		];
	}

	/**
	 * The totals and the board cover what the charts draw: every slot of the
	 * 288, and none before them or past now, whatever the retention window.
	 */
	public function test_overview_totals_and_board_sum_the_slots_the_charts_draw(): void {
		$this->use_base_dir( $this->make_temp_dir(), [ 'min_lifetime' => 21600 ] );
		$this->activate_shipped( 'performance', 1 );
		self::clock_at( \gmmktime( 14, 37, 11, 9, 29, 2026 ) );
		$totals = [];
		$board  = [];
		foreach ( [
			'2026-09-28-14-35' => [ 41, 410.0, 4.1 ],
			'2026-09-28-14-40' => [ 43, 860.0, 8.6 ],
			'2026-09-29-08-50' => [ 37, 740.0, 7.4 ],
			'2026-09-29-14-45' => [ 47, 470.0, 4.7 ],
		] as $bucket => [ $count, $sum_ms, $peak ] ) {
			$totals[] = self::total( self::t( $bucket ), $count, $sum_ms, $count, $peak );
			$board    = [ ...$board, ...self::board( self::t( $bucket ), $count, $sum_ms, [] ) ];
		}
		$this->append( [ Stats_Store::LEDGER_TOTALS => $totals, Stats_Store::LEDGER_LEADERBOARD => $board ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertSame( 80, $reply['total_requests'], 'the oldest drawn slot and one behind the six-hour window' );
		$this->assertEqualsWithDelta( 20.0, $reply['global_avg_ms'], 1e-9 );
		$this->assertEqualsWithDelta( 0.2, $reply['global_avg_peak_mb'], 1e-9 );
		$this->assertSame( 80, $reply['global_leaderboard']['count'], 'the board reads the same slots' );
		$this->assertEqualsWithDelta( 20.0, $reply['global_leaderboard']['avg_ms'], 1e-9, 'the site board\'s average is the totals\'' );
		$this->assertArrayNotHasKey( 'aggregate_time_series', $reply );
	}

	/**
	 * The average peak divides every request the peak sum covers, the
	 * untimed ones included: a timeout's peak memory is still measured.
	 */
	public function test_the_average_peak_divides_every_request_it_sums(): void {
		$this->append( [ Stats_Store::LEDGER_TOTALS => [ self::total( Stats_Store::bucket_start( self::tick() ), 2, 100.0, 3, 61.5 ) ] ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertSame( 2, $reply['total_requests'], 'the timed requests, as decision 24 counts them' );
		$this->assertEqualsWithDelta( 50.0, $reply['global_avg_ms'], 1e-9 );
		$this->assertEqualsWithDelta( 20.5, $reply['global_avg_peak_mb'], 1e-9, 'three requests\' peaks over three requests' );
	}

	/** `overview` reads no `server` dimension unless a server scopes it. */
	public function test_an_unscoped_overview_reads_no_dimension(): void {
		$this->append( [ Stats_Store::LEDGER_TOTALS => [ self::total( Stats_Store::bucket_start( self::tick() ), 41, 410.0, 41, 4.1 ) ] ] );
		$this->forget_stats_asks();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertEqualsWithDelta( 10.0, $reply['global_leaderboard']['avg_ms'], 1e-9 );
		$this->assertCount( 1, $this->ledger_asks( Stats_Store::LEDGER_TOTALS ), 'one totals read serves both' );
		$this->assertSame( [], $this->ledger_asks( Stats_Store::LEDGER_DIMS ), 'no server row without a server scope' );
	}

	/** An ask reads the board's categories and never its average. */
	public function test_an_ask_reads_no_average(): void {
		$this->append( [ Stats_Store::LEDGER_LEADERBOARD => self::board( Stats_Store::bucket_start( self::tick() ), 41, 4.1, [ 'wpdb' => [ 41, 44.4, 82 ] ], 'edge-kea.test' ) ] );
		$this->forget_stats_asks();

		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site --server=edge-kea.test' );
		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'category:wpdb --server=edge-kea.test' );

		$this->assertSame( [], [ ...$this->ledger_asks( Stats_Store::LEDGER_TOTALS ), ...$this->ledger_asks( Stats_Store::LEDGER_DIMS ) ] );
	}

	/**
	 * Under a server scope the board's average is that server's timed mean
	 * over the drawn slots, from the `server` dimension.
	 */
	public function test_a_server_boards_average_is_that_servers_timed_mean(): void {
		self::clock_at( \gmmktime( 14, 37, 11, 9, 29, 2026 ) );
		$this->append( [
			Stats_Store::LEDGER_DIMS => [
				self::dim( self::t( '2026-09-28-14-40' ), 'server', 'edge-kea.test', 3, 100.0, 0.9, 2 ),
				self::dim( self::t( '2026-09-28-14-40' ), 'server', 'edge-weka.test', 7, 7000.0, 0.7 ),
				self::dim( self::t( '2026-09-29-14-35' ), 'server', 'edge-kea.test', 5, 440.0, 0.5, 4 ),
				self::dim( self::t( '2026-09-28-14-35' ), 'server', 'edge-kea.test', 9, 9000.0, 0.9 ),
			],
		] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--server=edge-kea.test' )['global_leaderboard'];

		$this->assertEqualsWithDelta( 540.0 / 6, $board['avg_ms'], 1e-9, 'timed requests alone, over the drawn slots' );
	}

	/**
	 * A dimension row carries its timed count, so an average divides the
	 * requests whose duration was a sample (decision 24).
	 */
	public function test_a_breakdown_row_carries_its_timed_count(): void {
		self::clock_at( \gmmktime( 14, 37, 11, 9, 29, 2026 ) );
		$this->append( [ Stats_Store::LEDGER_DIMS => [ self::dim( self::t( '2026-09-29-14-35' ), 'ua', 'kea-ua/7', 3, 100.5, 0.9, 2 ) ] ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=ua' );

		$this->assertEquals( [ '2026-09-29-14-35' => [ [ 0, 3, 100.5, 0.9, 2 ] ] ], $reply['breakdowns']['ua']['buckets'] );
	}

	/**
	 * The chart window ends on the tick's bucket and starts 287 before it.
	 *
	 * @param int                      $minute Minute past 14:00 on 2026-09-29.
	 * @param array{0:string,1:string} $ends   The newest and oldest slot drawn.
	 * @param list<string>             $drawn  Seeded buckets the chart draws.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'window_edges' )]
	public function test_the_chart_window_trails_the_tick_by_288_slots( int $minute, array $ends, array $drawn ): void {
		self::clock_at( \gmmktime( 14, $minute, 11, 9, 29, 2026 ) );
		$rows = [];
		foreach ( [ '2026-09-28-14-00', '2026-09-28-14-05', '2026-09-28-14-55', '2026-09-28-15-00' ] as $n => $bucket ) {
			$rows[] = self::dim( self::t( $bucket ), 'ua', 'kea-ua/7', 41 + $n, 4.1, 0.4 );
		}
		$this->append( [ Stats_Store::LEDGER_DIMS => $rows ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=ua' );

		$this->assertSame( $ends, [ $reply['slots'][0], $reply['slots'][287] ] );
		$this->assertSame( $drawn, \array_keys( $reply['breakdowns']['ua']['buckets'] ) );
	}

	/** @return array<string,array{0:int,1:array{0:string,1:string},2:list<string>}> */
	public static function window_edges(): array {
		return [
			'14:02' => [ 2, [ '2026-09-29-14-00', '2026-09-28-14-05' ], [ '2026-09-28-14-05', '2026-09-28-14-55', '2026-09-28-15-00' ] ],
			'14:57' => [ 57, [ '2026-09-29-14-55', '2026-09-28-15-00' ], [ '2026-09-28-15-00' ] ],
		];
	}

	public function test_url_breakdown_reads_its_one_dimension_as_a_name_table(): void {
		self::clock_at( \gmmktime( 14, 37, 11, 9, 29, 2026 ) );
		$t = self::t( '2026-09-29-14-35' );
		$this->seed_urls( [ self::PLAN_URL => [ 'count' => 84 ] ], $t );
		$this->append( [
			Stats_Store::LEDGER_URL_DIMS => [
				[ $t, Stats_Store::url_dim_key( 'ua', self::PLAN_URL ), 'kea-ua/7', [ 41, 4.1, 0.4, 41 ] ],
				[ $t, Stats_Store::url_dim_key( 'status', self::PLAN_URL ), '5xx', [ 43, 4.3, 0.4, 43 ] ],
			],
		] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', Log_Manager::url_hash( self::PLAN_URL ) . ' --breakdown=ua' );

		$this->assertEquals(
			[ 'names' => [ 'kea-ua/7' ], 'buckets' => [ '2026-09-29-14-35' => [ [ 0, 41, 4.1, 0.4, 41 ] ] ] ],
			$reply['breakdown_time_series']
		);
	}

	/**
	 * Every dimension asked answers a key, an empty one included (decision
	 * 16), and a name PHP would key as an integer reaches the wire a string.
	 */
	public function test_every_breakdown_asked_answers_a_name_table_of_strings(): void {
		self::clock_at( \gmmktime( 14, 37, 11, 9, 29, 2026 ) );
		$this->append( [ Stats_Store::LEDGER_DIMS => [ self::dim( self::t( '2026-09-29-14-35' ), 'from', '4471', 41, 4.1, 0.4 ) ] ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=from,ja4' );

		$this->assertSame( [ 'names' => [], 'buckets' => [] ], $reply['breakdowns']['ja4'] );
		$this->assertSame( '{"names":["4471"],"buckets":{"2026-09-29-14-35":[[0,41,4.1,0.4,41]]}}', \wp_json_encode( $reply['breakdowns']['from'] ) );
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
		// OverviewSection (React) renders `overview.global_leaderboard.{categories,total_time,count}`.
		$this->append( [ Stats_Store::LEDGER_LEADERBOARD => self::board( Stats_Store::bucket_start( self::tick() ), 4, 0.8, [ 'db' => [ 4, 0.4, 10 ] ] ) ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' );

		$this->assertArrayHasKey( 'global_leaderboard', $result );
		$this->assertSame( 4, $result['global_leaderboard']['count'] );
		$this->assertArrayHasKey( 'categories', $result['global_leaderboard'] );
		$this->assertArrayHasKey( 'db', $result['global_leaderboard']['categories'] );
		$this->assertArrayHasKey( 'total_time', $result['global_leaderboard'] );
	}

	public function test_overview_verb_uses_server_leaderboard_when_server_arg_set(): void {
		// Only the server's board is seeded: the scope reroutes the read.
		$this->append( [ Stats_Store::LEDGER_LEADERBOARD => self::board( Stats_Store::bucket_start( self::tick() ), 2, 0.2, [ 'db' => [ 2, 0.1, 4 ] ], 'web01' ) ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--server=web01' );

		$this->assertSame( 2, $result['global_leaderboard']['count'] );
	}

	public function test_the_leaderboard_divides_each_category_by_the_requests_it_sums(): void {
		$this->append( [ Stats_Store::LEDGER_LEADERBOARD => self::board( Stats_Store::bucket_start( self::tick() - 7200 ), 9, 27.0, [ 'wpdb' => [ 9, 45.0, 18 ] ] ) ] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['global_leaderboard'];

		$this->assertSame( 9, $board['count'], 'an older bucket inside the window counts' );
		$this->assertEqualsWithDelta( 5.0, $board['categories']['wpdb']['time'], 1e-9 );
	}

	public function test_overview_verb_includes_category_time_series_when_categories_arg_set(): void {
		// The dashboard always passes `categories=1` and reads `category_time_series`.
		$bucket = $this->current_url_bucket();
		$this->append( [ Stats_Store::LEDGER_CATEGORIES => [ self::cat( Stats_Store::bucket_start( self::tick() ), 'db', 0.5, 4, 4 ) ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--categories' );

		// The NAME rides once in a table, not in all 288 buckets, and each
		// entry is the positional triple the Ledger holds.
		$series = $result['category_time_series'];
		$this->assertSame( [ 'db' ], $series['names'] );
		$this->assertEquals( [ [ 0, 0.5, 4, 4 ] ], $series['buckets'][ $bucket ] );
	}

	public function test_category_series_rounds_a_sum_across_partitions_to_display_precision(): void {
		// A sum of doubles is not itself rounded: 0.1 + 0.2 serializes as
		// 0.30000000000000004, nineteen characters for a number the chart
		// draws as three.
		$this->activate_shipped( 'performance', 2 );
		$bucket = $this->current_url_bucket();
		$t      = Stats_Store::bucket_start( self::tick() );
		$this->append( [ Stats_Store::LEDGER_CATEGORIES => [ self::cat( $t, 'zither render', 0.1, 3, 1 ) ] ], 0 );
		$this->append( [ Stats_Store::LEDGER_CATEGORIES => [ self::cat( $t, 'zither render', 0.2, 4, 1 ) ] ], 1 );

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
		$bucket = $this->current_url_bucket();
		$this->append( [ Stats_Store::LEDGER_DIMS => [ self::dim( Stats_Store::bucket_start( self::tick() ), 'country', 'PT', 23, 2.3, 0.7 ) ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=country' );

		$this->assertSame( 23, self::wire_series( $result['breakdowns']['country'] )[ $bucket ]['PT'][ Stats_Store::DIM_COUNT ] );
		$this->assertArrayNotHasKey( 'breakdown_time_series', $result );
	}

	public function test_overview_verb_includes_breakdowns_map_for_multi_dim(): void {
		// Comma-separated dims return nested `breakdowns: { dim => series }`.
		$bucket = $this->current_url_bucket();
		$t      = Stats_Store::bucket_start( self::tick() );
		$this->append( [ Stats_Store::LEDGER_DIMS => [ self::dim( $t, 'server', 'web01', 5, 0.5, 0.1 ), self::dim( $t, 'status', '200', 4, 0.4, 0.1 ) ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=server,status' );

		$this->assertArrayHasKey( 'breakdowns', $result );
		$this->assertSame( 5, self::wire_series( $result['breakdowns']['server'] )[ $bucket ]['web01'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( 4, self::wire_series( $result['breakdowns']['status'] )[ $bucket ]['200'][ Stats_Store::DIM_COUNT ] );
	}

	public function test_overview_server_scope_keeps_the_global_server_dimension(): void {
		$bucket = $this->current_url_bucket();
		$t      = Stats_Store::bucket_start( self::tick() );
		$this->append( [
			Stats_Store::LEDGER_DIMS => [
				self::dim( $t, 'server', 'edge-amber.example', 37, 3700.0, 259.0 ),
				self::dim( $t, 'server', 'edge-violet.example', 11, 1430.0, 99.0 ),
				self::dim( $t, 'status', '2xx', 37, 3700.0, 259.0, null, 'edge-amber.example' ),
				self::dim( $t, 'status', '2xx', 11, 1430.0, 99.0, null, 'edge-violet.example' ),
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--server=edge-amber.example --breakdown=server,status' );

		$this->assertSame( 37, self::wire_series( $result['breakdowns']['server'] )[ $bucket ]['edge-amber.example'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( 11, self::wire_series( $result['breakdowns']['server'] )[ $bucket ]['edge-violet.example'][ Stats_Store::DIM_COUNT ] );
		$this->assertSame( 37, self::wire_series( $result['breakdowns']['status'] )[ $bucket ]['2xx'][ Stats_Store::DIM_COUNT ], 'the status axis is the server\'s' );
	}

	public function test_overview_verb_refuses_an_unknown_breakdown_dimension(): void {
		// A chart option added without its dimension has to say so.
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--breakdown=server,viewport' );

		$this->assertStringContainsString( 'invalid breakdown dimension: viewport', $result );
	}

	public function test_overview_verb_server_scoped_categories_when_both_args(): void {
		$bucket = $this->current_url_bucket();
		$t      = Stats_Store::bucket_start( self::tick() );
		$this->append( [ Stats_Store::LEDGER_CATEGORIES => [ self::cat( $t, 'db', 0.2, 2, 2, 'web01' ), self::cat( $t, 'db', 9.9, 99, 99 ) ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--server=web01 --categories' );

		// Server-scoped data, not the global ones, in the compact wire shape.
		$this->assertSame( [ 'db' ], $result['category_time_series']['names'] );
		$this->assertEquals( 0.2, $result['category_time_series']['buckets'][ $bucket ][0][1] );
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
		$this->seed_urls( [
			'https://example.com/a' => [ 'count' => 1, 'sum_ms' => 100.0 ],
			'https://example.com/b' => [ 'count' => 5, 'sum_ms' => 500.0 ],
			'https://example.com/c' => [ 'count' => 3, 'sum_ms' => 300.0 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--sort=count --order=desc --limit=2 --offset=0' );

		$this->assertSame( 3, $result['rows'] );
		$this->assertCount( 2, $result['data'] );
		// Desc by count: /b first (5), /c second (3).
		$this->assertSame( 'https://example.com/b', $result['data'][0]['url'] );
		$this->assertSame( 'https://example.com/c', $result['data'][1]['url'] );
	}

	public function test_a_search_does_not_match_the_host(): void {
		// The host is the server's, and the dropdown answers about servers:
		// left in the searchable text, on a hub every row matches the busiest host.
		$this->seed_urls( [
			'https://alpha.test/reports' => [ 'count' => 7 ],
			'https://alpha.test/notes'   => [ 'count' => 9 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--search=alpha.test' );

		$this->assertSame( 0, $result['rows'] );
	}

	public function test_a_search_matches_the_path_and_the_row_still_displays_whole(): void {
		$this->seed_urls( [
			'https://alpha.test/reports' => [ 'count' => 7 ],
			'https://alpha.test/notes'   => [ 'count' => 9 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--search=reports' );

		$this->assertSame( 1, $result['rows'] );
		$this->assertSame( 'https://alpha.test/reports', $result['data'][0]['url'] );
	}

	public function test_urls_verb_filters_by_search_term(): void {
		$this->seed_urls( [
			'https://example.com/articles/123' => [ 'count' => 1, 'sum_ms' => 50.0 ],
			'https://example.com/home'         => [ 'count' => 2, 'sum_ms' => 100.0 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--search=articles' );

		$this->assertSame( 1, $result['rows'] );
		$this->assertSame( 'https://example.com/articles/123', $result['data'][0]['url'] );
		$this->assertSame( 1, $result['totals']['requests'], 'the totals are the matched set\'s' );
	}

	/** A search finds a whole word of the path and not a part of one. */
	public function test_a_search_finds_a_whole_word_and_not_a_part_of_one(): void {
		$this->seed_urls( [ 'https://alpha.test/wombat-7731/ox' => [ 'count' => 3 ] ] );

		foreach ( [ 'wombat', 'ox', '7731', 'wombat ox', 'WOMBAT ' ] as $whole ) {
			$this->assertSame( 1, VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ "--search={$whole}" ] )['rows'], $whole );
		}
		foreach ( [ 'wom', 'womb', 'mbat', '773' ] as $part ) {
			$this->assertSame( 0, VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ "--search={$part}" ] )['rows'], $part );
		}
	}

	/**
	 * The hash gate is `D`-anchored: `$` matches before a trailing newline.
	 *
	 * The hash is client-supplied and becomes a Ledger key through `row()`,
	 * which holds no whitespace — the same defect as the substrate's
	 * `HANDLER_NAME_PATTERN`, on a different gate.
	 */
	public function test_dump_url_refuses_a_hash_with_a_trailing_newline(): void {
		// An explicit token array: the harness whitespace-SPLITS a string arg,
		// which would eat the newline before the gate ever sees it.
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ "a1b2c3d4\n" ] );

		$this->assertSame( "invalid hash format\n", $result );
	}

	/**
	 * `dump_url` asks about ONE URL: it resolves the hash through its name
	 * and reads that URL's rows by key, never the scope's whole set.
	 */
	public function test_dump_url_reads_its_row_by_key(): void {
		$wombat = 'https://example.com/wombat-4471';
		$this->seed_urls( [
			$wombat                          => [ 'count' => 31, 'sum_ms' => 992.0 ],
			'https://example.com/quokka-8823' => [ 'count' => 17, 'sum_ms' => 411.0 ],
		] );
		$this->forget_stats_asks();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( $wombat ) );

		$this->assertSame( $wombat, $result['stats']['url'] );
		$this->assertSame( 31, $result['stats']['count'] );
		$reads = $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS );
		$this->assertNotEmpty( $reads );
		foreach ( $reads as [ $verb, $query ] ) {
			$this->assertSame( [ 'SUM', [ $wombat ] ], [ $verb, $query['xs'] ?? null ], 'every read names the one URL' );
		}
	}

	/**
	 * An unscoped `dump_url` reads every server the window names, and finds
	 * the row where it is: the same row a scope to its server reads.
	 */
	public function test_an_unscoped_dump_url_finds_its_row_among_every_server(): void {
		$kea = 'https://kea.example/wombat-4471';
		$this->seed_urls( [
			$kea                             => [ 'count' => 31, 'sum_ms' => 992.0, 'server' => 'kea.example' ],
			'https://moa.example/quokka-8823' => [ 'count' => 17, 'sum_ms' => 411.0, 'server' => 'moa.example' ],
		] );

		$scoped   = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( $kea ) . ' --server=kea.example' );
		$unscoped = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( $kea ) );
		$elsewhere = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( $kea ) . ' --server=moa.example' );

		$this->assertSame( $kea, $unscoped['stats']['url'] );
		$this->assertSame( 31, $unscoped['stats']['count'] );
		$this->assertSame( $scoped['stats'], $unscoped['stats'] );
		$this->assertSame( 'URL not found: ' . Log_Manager::url_hash( $kea ) . "\n", $elsewhere, 'a server holding no row for it' );
	}

	public function test_dump_url_returns_recent_matching_requests(): void {
		// dump_url's `requests` slice walks requests.log for entries whose
		// url_hash matches. Seed the URL's row AND two on-disk requests so
		// the collect + dedup walk runs (not the empty-result skip).
		// Timestamps ride the clock: the walk stops at the retention floor, so a
		// fixed epoch would put the whole fixture behind it.
		$url  = 'https://kea-7713.test/recent-list';
		$hash = Log_Manager::url_hash( $url );
		$now  = self::tick();
		$this->seed_urls( [ $url => [ 'count' => 2, 'sum_ms' => 32.0, 'last_seen' => $now - 623 ] ] );
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
		$this->seed_urls( [ $url => [ 'count' => $count, 'sum_ms' => 37.0 * $count, 'last_seen' => self::tick() - 1 ] ] );
		return Log_Manager::url_hash( $url );
	}

	/**
	 * One URL at one request a second over four partitions, request `i` on
	 * partition `i % 4`, the newest on p3. Partition 0 alone holds 600, more
	 * than one reply lists, so a walk capped across the fan-out never opens p1.
	 */
	public function test_a_four_partition_full_read_lists_the_newest_across_every_partition(): void {
		$this->activate_shipped( 'performance', 4 );
		$hash = $this->seed_listed_url( 'https://kea-7713.test/spread-over-four', 2400 );
		$now  = self::tick();
		for ( $i = 0; $i < 2400; $i++ ) {
			$this->write_request( [
				'rid'            => \sprintf( 'rid-spread-%021d', $i ),
				'url'            => 'https://kea-7713.test/spread-over-four',
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
		$url  = 'https://kea-7713.test/three-of-four';
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
		$url  = 'https://kea-7713.test/indexed-late';
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
		$hash = $this->seed_listed_url( 'https://kea-7713.test/bad-cursor', 1 );

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
		$url    = 'https://kea-7713.test/buried-under-neighbours';
		$hash   = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 1, 'sum_ms' => 37.0, 'last_seen' => self::tick() - 2311 ] ] );
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
		$url   = 'https://kea-7713.test/at-the-request-cap';
		$hash  = Log_Manager::url_hash( $url );
		$limit = (int) ( new \ReflectionClassConstant( Performance_CI_Node::class, 'RECENT_REQUEST_LIMIT' ) )->getValue();
		$now   = self::tick();
		$this->seed_urls( [ $url => [ 'count' => $limit + 1, 'sum_ms' => 64.0, 'last_seen' => $now - 907 ] ] );
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
		$url  = 'https://kea-7713.test/named-window-6205';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 1, 'sum_ms' => 47.0, 'last_seen' => $now - 62 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertSame(
			Stats_Store::bucket_start( $now ) - self::SCAN_RETENTION,
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
		$url  = 'https://kea-7713.test/behind-a-long-runner-5182';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 1, 'sum_ms' => 58.0, 'last_seen' => $now - 211 ] ] );
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
		$url  = 'https://kea-7713.test/behind-the-retention-edge-8813';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 1, 'sum_ms' => 71.0, 'last_seen' => $now - 137 ] ] );
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
		$url  = 'https://kea-7713.test/behind-a-replayed-spoke-4409';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 1, 'sum_ms' => 33.0, 'last_seen' => $now - 96 ] ] );
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
		$url  = 'https://kea-7713.test/behind-an-unreadable-line-9047';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 1, 'sum_ms' => 84.0, 'last_seen' => $now - 319 ] ] );
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
		$url  = 'https://kea-7713.test/behind-a-short-line-9047';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 1, 'sum_ms' => 84.0, 'last_seen' => $now - 319 ] ] );
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
		$url  = 'https://kea-7713.test/wholly-inside-the-window-3352';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 3, 'sum_ms' => 96.0, 'last_seen' => $now - 43 ] ] );
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
		$url  = 'https://kea-7713.test/column-lookalike-4417';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 1, 'sum_ms' => 19.0, 'last_seen' => 1700005000 ] ] );
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
			'url'         => 'https://kea-7713.test/flame-column',
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
		$url  = 'https://kea-7713.test/under-a-deep-index';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 1, 'sum_ms' => 29.0, 'last_seen' => self::tick() - 1777 ] ] );
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
		// Each server's rows are its own key, so a scoped table reads that
		// server's key: only URLs it served survive, with its own counts.
		$this->seed_urls( [
			'https://alpha.example/reviews/941' => [ 'count' => 2, 'sum_ms' => 260.0, 'sum_peak_mb' => 8.0, 'server' => 'alpha.example' ],
			'https://beta.example/reviews/941'  => [ 'count' => 7, 'sum_ms' => 640.0, 'sum_peak_mb' => 21.0, 'server' => 'beta.example' ],
			'https://beta.example/events/88'    => [ 'count' => 4, 'sum_ms' => 122.0, 'sum_peak_mb' => 12.0, 'server' => 'beta.example' ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=alpha.example' );

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertSame( 'https://alpha.example/reviews/941', $result['data'][0]['url'] );
		$this->assertSame( 2, $result['data'][0]['count'] );
		$this->assertEqualsWithDelta( 130.0, $result['data'][0]['avg_ms'], 1e-6 );
	}

	public function test_urls_verb_scopes_the_status_counts_too(): void {
		// Scoping `count` alone would leave `count_2xx..5xx` describing every
		// server, so a scoped row could report more classified requests than
		// it had. A server's key carries all of its row.
		$this->seed_urls( [ 'https://kea-7713.test/mixed' => [ 'count' => 2, 'count_2xx' => 1, 'count_5xx' => 1, 'sum_ms' => 260.0, 'server' => 'alpha.example' ] ] );
		$this->seed_urls( [ 'https://kea-7713.test/mixed' => [ 'count' => 7, 'count_2xx' => 5, 'count_5xx' => 2, 'sum_ms' => 640.0, 'server' => 'beta.example' ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=alpha.example' );

		$this->assertSame( 2, $result['data'][0]['count'] );
		$this->assertSame( 1, $result['data'][0]['count_2xx'] );
		$this->assertSame( 1, $result['data'][0]['count_5xx'] );
	}

	public function test_urls_verb_totals_answer_for_the_filtered_set(): void {
		// The Overview header renders these numbers, so they must describe the
		// set the table lists — under the server AND search filters both.
		$this->seed_urls( [
			'https://alpha.example/reviews/941' => [ 'count' => 2, 'sum_ms' => 260.0, 'sum_peak_mb' => 9.0, 'server' => 'alpha.example' ],
			'https://alpha.example/reviews/88'  => [ 'count' => 3, 'sum_ms' => 90.0, 'sum_peak_mb' => 7.5, 'server' => 'alpha.example' ],
			'https://alpha.example/events/7'    => [ 'count' => 5, 'sum_ms' => 500.0, 'sum_peak_mb' => 20.0, 'server' => 'alpha.example' ],
		] );
		$this->seed_urls( [
			'https://beta.example/reviews/941' => [ 'count' => 7, 'sum_ms' => 640.0, 'sum_peak_mb' => 27.0, 'server' => 'beta.example' ],
			'https://beta.example/reviews/88'  => [ 'count' => 1, 'sum_ms' => 32.0, 'sum_peak_mb' => 4.5, 'server' => 'beta.example' ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=alpha.example --search=/reviews/' );

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
		$this->append( [ Stats_Store::LEDGER_LEADERBOARD => self::board( Stats_Store::bucket_start( self::tick() ), 37, 3.7, [ 'wpdb' => [ 37, 44.4, 74 ] ], 'alpha.example' ) ] );

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site --server=alpha.example' );

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
		// The Time Breakdown an operator clicks Ask from renders the server's
		// board, so the brief behind it has to read the same board.
		$t = Stats_Store::bucket_start( self::tick() );
		$this->append( [
			Stats_Store::LEDGER_LEADERBOARD => [
				...self::board( $t, 4, 1.0, [ 'wpdb' => [ 4, 4.0, 8 ] ], 'alpha.example' ),
				...self::board( $t, 40, 10.0, [ 'wpdb' => [ 40, 400.0, 80 ] ] ),
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'category:wpdb --server=alpha.example' );

		$this->assertEqualsWithDelta( 1.0, $result['avg_time_ms'], 1e-6 );
		$this->assertSame( 'recent window on alpha.example', $result['scope'] );
	}

	public function test_ask_url_brief_honours_the_server_scope(): void {
		// A brief that answered site-wide would hand an agent unscoped numbers
		// labelled as one server's, and the label makes them quotable.
		$this->seed_urls( [ 'https://kea-7713.test/asked' => [ 'count' => 2, 'sum_ms' => 500.0, 'server' => 'alpha.example' ] ] );
		$this->seed_urls( [ 'https://kea-7713.test/asked' => [ 'count' => 7, 'sum_ms' => 1300.0, 'server' => 'beta.example' ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:' . Log_Manager::url_hash( 'https://kea-7713.test/asked' ) . ' --server=alpha.example' );

		$this->assertSame( 2, $result['stats']['count'] );
		$this->assertEqualsWithDelta( 250.0, $result['stats']['avg_ms'], 1e-6 );
	}

	/** A URL brief resolves its rule from the stored URL's path and query, as a request would. */
	public function test_ask_url_brief_matches_its_rule_by_path_and_query(): void {
		( new Rule_Set( [] ) )->save( [
			new Rule( Rule_Set::id_for( '/pelican-6184' ), '/pelican-6184', Rule::ACTION_LOG ),
			new Rule( Rule_Set::id_for( '/pelican-6184?kakapo' ), '/pelican-6184?kakapo', Rule::ACTION_LOG ),
		] );
		$this->seed_urls( [
			'https://kea-7713.test/pelican-6184?kakapo-3' => [ 'count' => 1 ],
			'https://kea-7713.test/pelican-6184/nest'     => [ 'count' => 1 ],
		] );

		$with_query = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:' . Log_Manager::url_hash( 'https://kea-7713.test/pelican-6184?kakapo-3' ) );
		$path_only  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:' . Log_Manager::url_hash( 'https://kea-7713.test/pelican-6184/nest' ) );

		$this->assertSame( '/pelican-6184?kakapo', $with_query['rule']['pattern'] ?? null );
		$this->assertSame( '/pelican-6184', $path_only['rule']['pattern'] ?? null );
	}

	public function test_ask_url_brief_carries_the_measured_average(): void {
		// The brief is the number an agent quotes, so it reads a DISPLAY row,
		// the mean projected from the sums.
		$this->seed_urls( [ 'https://kea-7713.test/asked' => [ 'count' => 4, 'sum_ms' => 1000.0 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:' . Log_Manager::url_hash( 'https://kea-7713.test/asked' ) );

		$this->assertEqualsWithDelta( 250.0, $result['stats']['avg_ms'], 1e-6 );
	}

	/**
	 * A URL whose only requests timed out has no measured extreme, and every
	 * surface says so with null: the url brief, its finding, the overview
	 * brief's row and the detail modal never read it as a 0 ms worst.
	 */
	public function test_an_untimed_url_carries_no_extreme_to_any_surface(): void {
		$url  = 'https://kea.test/ruru-6603';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 6, 'timed_count' => 0, 'count_2xx' => 0, 'errors' => 6, 'sum_peak_mb' => 42.0 ] ] );

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hash}" );
		VerbHarness::reset();
		$overview = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site' );
		VerbHarness::reset();
		$dump = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

		$this->assertSame( 6, $brief['stats']['count'] );
		$this->assertNull( $brief['stats']['max_ms'], 'the url brief' );
		$this->assertNull( $brief['stats']['avg_ms'], 'its mean' );
		$this->assertSame( 'insufficient_instrumentation', $brief['findings'][0]['kind'] );
		$this->assertNull( $brief['findings'][0]['metric']['max_ms'], 'its finding' );
		$this->assertNull( $brief['findings'][0]['metric']['avg_ms'], 'its finding\'s mean' );
		$this->assertSame( [ $hash ], \array_column( $overview['urls'], 'hash' ) );
		$this->assertNull( $overview['urls'][0]['max_ms'], 'the overview brief' );
		$this->assertNull( $overview['urls'][0]['avg_ms'], 'its row\'s mean' );
		$this->assertNull( $overview['stats']['avg_ms'], 'its totals\' mean' );
		VerbHarness::reset();
		$unmatched = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'overview:site --search=nomatchwordkea' );
		$this->assertSame( 0, $unmatched['stats']['requests'] );
		$this->assertSame( [ null, null ], [ $unmatched['stats']['avg_ms'], $unmatched['stats']['avg_peak_mb'] ], 'a search that matches nothing has no means'  );
		$this->assertSame( [ null, null, null ], [ $dump['stats']['avg_ms'], $dump['stats']['min_ms'], $dump['stats']['max_ms'] ], 'the detail modal' );
	}

	/**
	 * On every timed sort a URL no timed request reached ranks last, both
	 * ways, on an unsearched page ranked in the Ledger and a searched page
	 * ranked here alike.
	 */
	public function test_an_untimed_url_ranks_last_on_every_timed_sort(): void {
		$this->seed_urls( [
			'https://kea.test/moa-31/ruru'  => [ 'count' => 7, 'timed_count' => 0, 'count_2xx' => 0, 'errors' => 7 ],
			'https://kea.test/moa-31/tui'   => [ 'count' => 3, 'sum_ms' => 90.0 ],
			'https://kea.test/moa-31/kokako' => [ 'count' => 2, 'sum_ms' => 710.0 ],
		] );
		$timed = [ 'asc' => [ 'https://kea.test/moa-31/tui', 'https://kea.test/moa-31/kokako' ], 'desc' => [ 'https://kea.test/moa-31/kokako', 'https://kea.test/moa-31/tui' ] ];

		foreach ( [ 'avg_ms', 'min_ms', 'max_ms' ] as $sort ) {
			foreach ( $timed as $order => $urls ) {
				foreach ( [ [], [ '--search=moa' ] ] as $search ) {
					Core::$memd = new InMemoryMemcached();
					VerbHarness::reset();
					$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ "--sort={$sort}", "--order={$order}", ...$search ] );
					$this->assertSame( [ ...$urls, 'https://kea.test/moa-31/ruru' ], \array_column( $page['data'], 'url' ), "{$sort} {$order} " . \implode( '', $search ) );
					$this->assertSame( [ true, true, false ], \array_map( static fn ( array $row ): bool => null !== $row['avg_ms'], $page['data'] ), "{$sort} {$order} carries the unmeasured mean as null" );
				}
			}
		}
	}

	public function test_dump_url_scopes_to_the_selected_server(): void {
		// The row that opens this modal is the selected server's; the modal has
		// to answer for the same server.
		$this->seed_urls( [ 'https://kea-7713.test/mixed' => [ 'count' => 2, 'sum_ms' => 260.0, 'server' => 'alpha.example' ] ] );
		$this->seed_urls( [ 'https://kea-7713.test/mixed' => [ 'count' => 7, 'sum_ms' => 640.0, 'server' => 'beta.example' ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( 'https://kea-7713.test/mixed' ) . ' --server=alpha.example' );

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
		// One long-running job would otherwise dominate the averages, and the
		// table hides workers too, or the header stops describing what is listed.
		$this->seed_urls( [
			'https://example.com/reader'       => [ 'count' => 4, 'sum_ms' => 48.0 ],
			'https://example.com/w?reconcile' => [ 'count' => 2, 'sum_ms' => 180000.0, 'worker' => true ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertSame( 4, $result['totals']['requests'] );
		$this->assertEqualsWithDelta( 12.0, $result['totals']['avg_ms'], 1e-6 );
		$this->assertSame( [ 'https://example.com/reader' ], \array_column( $result['data'], 'url' ) );
	}

	public function test_urls_verb_shows_worker_traffic_when_asked(): void {
		$this->seed_urls( [
			'https://example.com/reader'       => [ 'count' => 4, 'sum_ms' => 48.0 ],
			'https://example.com/w?reconcile' => [ 'count' => 2, 'sum_ms' => 180000.0, 'worker' => true ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--include_workers' );

		$this->assertSame( 2, $result['totals']['urls'] );
		$this->assertSame( 6, $result['totals']['requests'] );
		$this->assertSame( 2, $result['rows'] );
		$this->assertTrue( $result['filters']['include_workers'] );
	}

	public function test_urls_verb_rate_skips_the_still_filling_bucket(): void {
		// Req/s averages the current hour's COMPLETE buckets: at :37, 2,100
		// requests over seven of them is 1/s. The newest bucket is still
		// accumulating, so counting it would drag every rate down.
		$now = self::tick();
		$this->seed_urls( [ 'https://kea-7713.test/rate' => [ 'count' => 2100, 'sum_ms' => 2100.0 ] ], Stats_Store::bucket_start( $now - 600 ) );
		$this->seed_urls( [ 'https://kea-7713.test/rate' => [ 'count' => 99000, 'sum_ms' => 99000.0 ] ], Stats_Store::bucket_start( $now ) );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( 101100, $result['totals']['requests'] );
		$this->assertEqualsWithDelta( 1.0, $result['totals']['requests_per_second'], 1e-9 );
	}

	public function test_urls_verb_filters_errors_only_and_totals_match(): void {
		// "Errors" = timeouts and fatals; a 5xx alone is a response, not one.
		$this->seed_urls( [
			'https://example.com/clean'      => [ 'count' => 9, 'sum_ms' => 90.0, 'count_2xx' => 6, 'count_3xx' => 1, 'count_4xx' => 1, 'count_5xx' => 1 ],
			'https://example.com/timeouts'   => [ 'count' => 6, 'timed_count' => 5, 'sum_ms' => 60.0, 'count_2xx' => 2, 'count_5xx' => 3, 'errors' => 4 ],
			'https://example.com/also-clean' => [ 'count' => 4, 'sum_ms' => 40.0 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--errors_only=1' );

		// The footer reads `total`; it must count what is actually rendered.
		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertSame( 1, $result['rows'] );
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
		$this->seed_urls( [
			'https://example.com/busy'  => [ 'count' => 300, 'timed_count' => 299, 'sum_ms' => 900.0, 'count_2xx' => 299, 'errors' => 1 ],
			'https://example.com/quiet' => [ 'count' => 12, 'timed_count' => 7, 'sum_ms' => 70.0, 'count_2xx' => 7, 'errors' => 5 ],
			'https://example.com/calm'  => [ 'count' => 900, 'sum_ms' => 900.0 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--errors_only=1 --sort=count --order=desc' );

		$this->assertSame( [ 'https://example.com/quiet', 'https://example.com/busy' ], \array_column( $result['data'], 'url' ) );
		$this->assertSame( 2, $result['rows'], 'an unerring URL is no row' );
	}

	/** A URL no timed request reached has no minimum to show. */
	public function test_an_untimed_urls_minimum_is_null(): void {
		$this->seed_urls( [ 'https://kea-7713.test/worker-only' => [ 'count' => 7, 'timed_count' => 0 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertNull( $result['data'][0]['min_ms'], 'not measured' );
		$this->assertNull( $result['data'][0]['max_ms'], 'not measured' );
	}

	public function test_urls_verb_min_ms_unaffected_by_untimed_sibling_bucket(): void {
		// An untimed bucket files its minimum as not measured, so the
		// Ledger's minimum is the timed bucket's, never clamped to 0.
		$this->seed_urls( [ 'https://kea-7713.test/mixed' => [ 'count' => 3, 'timed_count' => 0 ] ], Stats_Store::bucket_start( self::tick() - 600 ) );
		$this->seed_urls( [ 'https://kea-7713.test/mixed' => [ 'count' => 5, 'sum_ms' => 500.0, 'min_ms' => 42.0, 'max_ms' => 120.0 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--sort=min_ms' );

		$this->assertSame( 1, $result['totals']['urls'] );
		$this->assertEquals( 42, $result['data'][0]['min_ms'] );
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

	/** Seed one URL two servers served, each under its own key. */
	private function seed_split_row(): void {
		$this->seed_urls( [ 'https://edge.example/split-3907' => [ 'count' => 3, 'sum_ms' => 390.0, 'server' => 'edge-3907.example' ] ] );
		$this->seed_urls( [ 'https://edge.example/split-3907' => [ 'count' => 2, 'sum_ms' => 260.0, 'server' => 'edge-8823.example' ] ] );
	}

	/**
	 * Decision 18 rests the positional row on it never crossing to the wire:
	 * integer keys are what the browser cannot read, and JSON takes them
	 * happily — no assertion elsewhere would notice.
	 */
	public function test_an_unscoped_reply_row_is_named_and_sums_every_server(): void {
		$this->seed_split_row();

		$row = ( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' )['data'][0] ) ?? [];

		$this->assertSame( 'https://edge.example/split-3907', $row['url'] ?? '' );
		$this->assertSame( 5, $row['count'] ?? -1, 'one URL two servers served folds into one row' );
		$this->assertSame( [], \array_values( \array_filter( \array_keys( $row ), '\is_int' ) ), 'no positional key rides out to the wire' );
	}

	/** And the scoped read, which reads one server's key. */
	public function test_a_scoped_reply_row_is_named_and_carries_that_server_alone(): void {
		$this->seed_split_row();

		$row = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=edge-3907.example' )['data'][0] ?? [];

		$this->assertSame( 'https://edge.example/split-3907', $row['url'] ?? '' );
		$this->assertSame( 3, $row['count'] ?? -1, 'the scoped sums arrive under NAMES' );
		$this->assertEqualsWithDelta( 130.0, $row['avg_ms'] ?? -1.0, 1e-6 );
		$this->assertSame( [], \array_values( \array_filter( \array_keys( $row ), '\is_int' ) ), 'no positional key rides out to the wire' );
	}

	public function test_a_row_with_no_timed_request_has_no_mean(): void {
		// Dividing by the whole count would invent a mean from requests that
		// recorded no duration.
		$this->seed_urls( [ 'https://kea-7713.test/untimed' => [ 'count' => 5, 'timed_count' => 0, 'sum_ms' => 750.0 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertNull( $result['totals']['avg_ms'] );
		$this->assertNull( $result['data'][0]['avg_ms'] );
	}

	public function test_the_mean_divides_by_timed_requests_not_by_every_request(): void {
		// 400ms over the 4 requests that recorded a duration is 100, over all 10 it is 40.
		$this->seed_urls( [ 'https://kea-7713.test/mostly-timed-out' => [ 'count' => 10, 'timed_count' => 4, 'sum_ms' => 400.0 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertEqualsWithDelta( 100.0, $result['totals']['avg_ms'], 0.01 );
		$this->assertEqualsWithDelta( 100.0, $result['data'][0]['avg_ms'], 0.01 );
		$this->assertSame( 10, $result['totals']['requests'] );
	}

	/** Write `$hash`'s URL blob to partition `$partition`'s url Table, as a settle does. */
	private function set_url_stats( int $partition, string $hash, array $blob ): void {
		$this->assertSame( [ $hash ], $this->stats_store( $partition )->set_url_aggregates( [ $hash => $blob ] ), 'the blob landed' );
	}

	public function test_dump_url_verb_returns_stats_and_default_flame(): void {
		$url = 'https://example.com/articles/777';
		$this->seed_urls( [ $url => [ 'count' => 9, 'sum_ms' => 450.0 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( $url ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'stats', $result );
		$this->assertArrayHasKey( 'requests', $result );
		$this->assertArrayHasKey( 'aggregate_flame', $result );
		$this->assertSame( $url, $result['stats']['url'] );
		$this->assertSame( 9, $result['stats']['count'] );
		// No flame seeded → default empty-tree shape.
		$this->assertSame( 'aggregate', $result['aggregate_flame']['name'] );
		$this->assertSame( 0, $result['aggregate_flame']['value'] );
		$this->assertSame( [], $result['aggregate_flame']['children'] );
		$this->assertSame( [], $result['requests'] );
	}

	public function test_dump_url_verb_includes_aggregate_flame_when_seeded(): void {
		$hash = Log_Manager::url_hash( 'https://kea-7713.test/x' );
		$this->seed_urls( [ 'https://kea-7713.test/x' => [ 'count' => 1, 'sum_ms' => 10.0 ] ] );
		$this->set_url_stats( 0, $hash, [
			'flame_raw'     => [ 'name' => 'aggregate', 'sum_value' => 200.0, 'count' => 2, 'children' => [ [ 'name' => 'a', 'sum_value' => 100.0, 'ts' => 1700001000, 'children' => [] ] ] ],
			'last_modified' => 1700001111,
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

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
		$hash = $this->seed_listed_url( 'https://kea-7713.test/summed-blobs', 5 );
		$now  = self::tick();
		$this->set_url_stats( 0, $hash, [
			'flame_raw'     => [ 'name' => 'aggregate', 'sum_value' => 300.0, 'count' => 3, 'children' => [
				[ 'name' => 'init hook', 'sum_value' => 90.0, 'ts' => $now - 41, 'children' => [] ],
			] ],
			'profiles'      => [ 'count' => 3, 'sum_req_time' => 300.0, 'categories' => [
				'render' => [ 'samples' => 3, 'sum_time' => 60.0, 'sum_count' => 6, 'entries' => [] ],
			] ],
			'last_modified' => $now - 7,
		] );
		$this->set_url_stats( 2, $hash, [
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
		$this->assertSame( $now - 3, $result['last_modified'], 'the newest partition\'s settle' );
	}

	/**
	 * Seed a URL whose flame blob has expired: its row, and two requests whose
	 * stored flames each carry one `init hook` span.
	 *
	 * @return array{0:string,1:int} The URL hash and the newer request's start.
	 */
	private function seed_a_cold_url_with_two_flames(): array {
		$url   = 'https://kea-7713.test/cold-flame';
		$hash  = Log_Manager::url_hash( $url );
		$now   = self::tick();
		$this->seed_urls( [ $url => [ 'count' => 2, 'timed_count' => 2, 'sum_ms' => 100.0, 'last_seen' => $now - 311 ] ] );
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
		$url   = 'https://kea-7713.test/cold-in-p1';
		$hash  = Log_Manager::url_hash( $url );
		$now   = self::tick();
		$this->seed_urls( [ $url => [ 'count' => 1, 'timed_count' => 1, 'sum_ms' => 70.0, 'last_seen' => $now - 522 ] ] );
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
		// dropdown always asks for, so the stats carry no series of their own.
		$url = 'https://example.com/reviews/first';
		$this->seed_urls( [ $url => [ 'count' => 7, 'sum_ms' => 917.0 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( $url ) );

		$this->assertArrayNotHasKey( 'time_series', $result['stats'] );
		$this->assertSame( $url, $result['stats']['url'] );
		$this->assertSame( 7, $result['stats']['count'] );
		$this->assertEqualsWithDelta( 131.0, $result['stats']['avg_ms'], 1e-6 );
	}

	/** Append one URL's dimension rows at the tick's bucket. */
	private function seed_url_dim( string $url, string $dim, string $value, int $count, float $sum_ms, float $sum_peak_mb, ?int $t = null ): void {
		$this->append( [ Stats_Store::LEDGER_URL_DIMS => [ [ $t ?? Stats_Store::bucket_start( self::tick() ), Stats_Store::url_dim_key( $dim, $url ), $value, [ $count, $sum_ms, $sum_peak_mb, $count ] ] ] ] );
	}

	public function test_dump_url_verb_includes_breakdown_time_series_when_arg_set(): void {
		$bucket = $this->current_url_bucket();
		$this->seed_urls( [ 'https://kea-7713.test/x' => [ 'count' => 1, 'sum_ms' => 10.0 ] ] );
		$this->seed_url_dim( 'https://kea-7713.test/x', 'method', 'GET', 3, 0.3, 0.1 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( 'https://kea-7713.test/x' ) . ' --breakdown=method' );

		$this->assertArrayHasKey( 'breakdown_time_series', $result );
		$this->assertSame( 3, self::wire_series( $result['breakdown_time_series'] )[ $bucket ]['GET'][ Stats_Store::DIM_COUNT ] );
	}

	public function test_url_breakdown_answers_the_series_alone(): void {
		// The chart polls this every five minutes and keeps only the series, so
		// the verb behind it must not drag the index walk `dump_url` runs.
		$bucket = $this->current_url_bucket();
		$hash   = Log_Manager::url_hash( 'https://kea-7713.test/breakdown-only' );
		$this->seed_urls( [ 'https://kea-7713.test/breakdown-only' => [ 'count' => 6, 'sum_ms' => 84.0 ] ] );
		$this->seed_url_dim( 'https://kea-7713.test/breakdown-only', 'status', '503', 9, 1.7, 0.4 );

		$detail = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', "{$hash} --breakdown=status" );
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', "{$hash} --breakdown=status" );

		$this->assertSame( $detail['breakdown_time_series'], $result['breakdown_time_series'] );
		$this->assertSame( 9, self::wire_series( $result['breakdown_time_series'] )[ $bucket ]['503'][ Stats_Store::DIM_COUNT ] );
		$this->assertArrayNotHasKey( 'requests', $result );
		$this->assertArrayNotHasKey( 'stats', $result );
		$this->assertArrayNotHasKey( 'aggregate_flame', $result );
	}

	public function test_url_breakdown_refuses_a_dimension_it_cannot_answer(): void {
		// A required argument that silently answers nothing leaves the chart
		// spinning; named, because the caller's own spelling is what it fixes.
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'e71b04ac9d33 --breakdown=nosuchdim' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid breakdown dimension: nosuchdim', $result );
	}

	public function test_url_breakdown_refuses_the_server_axis(): void {
		// One URL is one server's, so a server axis would draw a single line.
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'e71b04ac9d33 --breakdown=server' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid breakdown dimension: server', $result );
	}

	public function test_dump_url_refuses_the_server_axis(): void {
		// The same refusal `url_breakdown` gives, not a reply missing a key.
		$this->seed_urls( [ 'https://kea-7713.test/wombat-7731' => [ 'count' => 3 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( 'https://kea-7713.test/wombat-7731' ) . ' --breakdown=server' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid breakdown dimension: server', $result );
	}

	public function test_dump_url_verb_includes_category_time_series_when_arg_set(): void {
		$bucket = $this->current_url_bucket();
		$this->seed_urls( [ 'https://kea-7713.test/x' => [ 'count' => 1, 'sum_ms' => 10.0 ] ] );
		$this->append( [ Stats_Store::LEDGER_URL_CATS => [ [ Stats_Store::bucket_start( self::tick() ), Stats_Store::url_key( 'https://kea-7713.test/x' ), 'db', [ 0.2, 2, 1 ] ] ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( 'https://kea-7713.test/x' ) . ' --categories' );

		$this->assertArrayHasKey( 'category_time_series', $result );
		// The modal reads the same compact shape the overview card does.
		$this->assertSame( [ 'db' ], $result['category_time_series']['names'] );
		$this->assertEquals( 0.2, $result['category_time_series']['buckets'][ $bucket ][0][1] );
	}

	/**
	 * Both URL series cover the whole read window, not the reader row's
	 * `last_seen`: worker traffic files a URL's categories and dimensions
	 * after the reader row's last request.
	 */
	public function test_dump_url_series_reach_past_the_reader_rows_last_seen(): void {
		$old     = Stats_Store::bucket_start( self::tick() - 3 * Stats_Store::BUCKET_SECONDS );
		$current = $this->current_url_bucket();
		$url     = 'https://kea-7713.test/quokka-5a1b';
		$this->seed_urls( [ $url => [ 'count' => 4, 'sum_ms' => 52.0, 'last_seen' => $old ] ], $old );
		$this->append( [ Stats_Store::LEDGER_URL_CATS => [ [ Stats_Store::bucket_start( self::tick() ), Stats_Store::url_key( $url ), 'wpdb', [ 41.5, 6, 3 ] ] ] ] );
		$this->seed_url_dim( $url, 'status', '418', 7, 2.9, 0.6 );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( $url ) . ' --breakdown=status --categories' );

		$this->assertIsArray( $result );
		$this->assertSame( 7, self::wire_series( $result['breakdown_time_series'] )[ $current ]['418'][ Stats_Store::DIM_COUNT ] ?? null, 'the dimension bucket after last_seen' );
		$this->assertEquals( 41.5, $result['category_time_series']['buckets'][ $current ][0][1] ?? null, 'the category bucket after last_seen' );
	}

	public function test_dump_url_verb_refuses_an_unknown_dim(): void {
		// An unknown dim is refused, as `url_breakdown` refuses it, rather
		// than answered without the series it asked for.
		$this->seed_urls( [ 'https://kea-7713.test/x' => [ 'count' => 1, 'sum_ms' => 10.0 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', Log_Manager::url_hash( 'https://kea-7713.test/x' ) . ' --breakdown=nosuchdim' );

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

	public function test_every_partitions_rows_reach_the_overview(): void {
		// Every flame-builder partition appends to the one Ledger, so a read
		// sums what each of the topology's workers wrote.
		$this->activate_shipped( 'performance', 3 );
		foreach ( [ 0 => 3, 1 => 5, 2 => 7 ] as $p => $count ) {
			$this->append( [ Stats_Store::LEDGER_TOTALS => [ self::total( Stats_Store::bucket_start( self::tick() ), $count, 1.0, $count, 1.0 ) ] ], $p );
		}

		$this->assertSame( 15, VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['total_requests'], 'every partition\'s rows are read' );
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
			[ 'rid' => 'grepR1', 'k' => 'request', 'm' => 'GET https://kea-7713.test/calendar/today?x=1', 'ts' => 1700000000.0, 'n' => 2 ],
			[ 'rid' => 'grepR1', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.4, 'n' => 3, 'duration_ms' => 400 ],
			[ 'rid' => 'grepNoise', 'k' => 'request', 'm' => 'GET https://kea-7713.test/feed', 'ts' => 1700000001.0, 'n' => 1 ],
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
		$this->assertSame( 'https://kea-7713.test/calendar/today', $summary['url'] );
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
			$entries[] = [ 'rid' => "noise{$i}", 'k' => 'request', 'm' => 'GET https://kea-7713.test/feed', 'ts' => 1700000000.0, 'n' => 1 ];
		}
		$this->write_firehose( 0, $entries );
		$this->write_firehose( 1, [
			[ 'rid' => 'lateMatch', 'k' => 'request', 'm' => 'GET https://kea-7713.test/past-the-budget-4471', 'ts' => 1700000900.0, 'n' => 1 ],
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
			$entries[] = [ 'rid' => $rid, 'k' => 'request', 'm' => "GET https://kea-7713.test/match/{$rid}", 'ts' => 1700000000.0 + $i, 'n' => 1 ];
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
			[ 'rid' => 'lim1', 'k' => 'request', 'm' => 'GET https://kea-7713.test/match/a', 'ts' => 1700000000.0, 'n' => 1 ],
			[ 'rid' => 'lim1', 'k' => 'process (complete)', 'm' => '(done)', 'ts' => 1700000000.5, 'n' => 2 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'grep_requests', '/match --limit=abc' );

		$this->assertIsString( $result, 'a malformed --limit must not silently return one row' );
		$this->assertStringContainsString( 'limit', $result );
	}

	public function test_grep_requests_empty_when_no_match(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'z1', 'k' => 'request', 'm' => 'GET https://kea-7713.test/other', 'ts' => 1700000000.0, 'n' => 1 ],
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
			[ 'rid' => 'oakum7', 'k' => 'request', 'm' => 'GET https://kea-7713.test/oakum-3391', 'ts' => 1700000000.0, 'n' => 1 ],
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
			[ 'rid' => 'clean1', 'k' => 'request', 'm' => 'GET https://kea-7713.test/bilge-812', 'ts' => 1700000000.0, 'n' => 1 ],
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
		$result                       = VerbHarness::fire( $interpreter, 'performance', 'grep_requests', 'https://kea-7713.test/x' );

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
			'url'            => 'https://kea-7713.test/elsewhere',
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
		$this->assertSame( 'https://kea-7713.test/elsewhere', $result['url'] );
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
			'url'            => 'https://kea-7713.test/detailed',
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
		$this->assertSame( 'https://kea-7713.test/detailed', $result['url'] );
		$this->assertSame( 201, $result['status_code'] );
		$this->assertNotEmpty( $result['url_hash'] );
		$this->assertArrayHasKey( 'events', $result );
		$this->assertCount( 1, $result['events'] );
	}

	public function test_dump_request_carries_the_findings_for_that_record(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-findings-1234567890123456789',
			'url'            => 'https://kea-7713.test/slow-thing',
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

	/** A request brief names the rule the record stamped, the one the request itself resolved. */
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
		$this->assertNotNull( $result['rule'], 'the record named its rule' );
		$this->assertSame( '/', $result['rule']['pattern'] );
	}

	/**
	 * A record whose stamped rule is gone stays unresolved even when its URL
	 * matches a live rule: the brief carries the stamp as `resolved: false`
	 * and one `unresolved_rule` finding, through `ask` and `dump_request`.
	 */
	public function test_a_record_whose_stamped_rule_is_gone_stays_unresolved(): void {
		( new Rule_Set( [] ) )->save( [ new Rule( Rule_Set::id_for( '/moa-4402' ), '/moa-4402', Rule::ACTION_LOG ) ] );
		$rid = $this->write_request( [
			'rid'            => 'rid-moa-4402-0123456789012345678',
			'url'            => 'https://kea-7713.test/moa-4402/egg',
			'rule_id'        => 'gone-4402',
			'timestamp'      => 1700001000,
			'duration_ms'    => 90,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$brief  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}:0" );
		$dumped = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );

		$this->assertIsArray( $brief, \is_string( $brief ) ? $brief : '' );
		$this->assertSame( [ 'id' => 'gone-4402', 'resolved' => false ], $brief['rule'] );
		$this->assertIsArray( $dumped, \is_string( $dumped ) ? $dumped : '' );
		foreach ( [ 'ask' => $brief, 'dump_request' => $dumped ] as $surface => $reply ) {
			$unresolved = \array_values( \array_filter( $reply['findings'], static fn ( array $f ): bool => 'unresolved_rule' === $f['kind'] ) );
			$this->assertCount( 1, $unresolved, $surface );
			$this->assertSame( 'gone-4402', $unresolved[0]['rule_id'], $surface );
		}
	}

	public function test_ask_assembles_a_request_brief(): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-req-123456789012345678',
			'url'            => 'https://kea-7713.test/asked-about',
			'timestamp'      => 1700000800,
			'duration_ms'    => 120,
			'status_code'    => 200,
			'peak_mb'        => 2,
			'request_method' => 'GET',
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}:0" );

		$this->assertIsArray( $result );
		$this->assertSame( 'request', $result['subject'] );
		$this->assertSame( 'https://kea-7713.test/asked-about', $result['url'] );
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
			'url'            => 'https://kea-7713.test/asked-span',
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
		// The name comes from the names Ledger and the tree from the url
		// Table, one key each; no index row is walked for it.
		$url  = 'https://example.test/asked-agg';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 3, 'server' => 'example.test' ] ] );
		// As the flame builder stores it: sums, the count on the root alone.
		$this->set_url_stats( 0, $hash, [
			'flame_raw' => [
				'name'      => 'aggregate',
				'sum_value' => 900.0,
				'count'     => 3,
				'children'  => [ [ 'name' => 'wp_loaded', 'sum_value' => 720.0, 'ts' => self::tick(), 'children' => [] ] ],
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', "url:{$hash}" ] );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertSame( 'span', $result['subject'] );
		$this->assertEquals( 240.0, $result['ms'] );
		$this->assertArrayNotHasKey( 'count', $result );
		$this->assertSame( 'mean per request over 3 requests, every server', $result['scope'] );
		$this->assertSame( $url, $result['url'] );
	}

	/** A URL whose name the names Ledger does not hold still answers a span brief, governed by no rule. */
	public function test_ask_resolves_a_span_under_a_url_the_names_ledger_misses(): void {
		( new Rule_Set( [] ) )->save( [ new Rule( Rule_Set::id_for( '/' ), '/', Rule::ACTION_LOG ) ] );
		$hash = Log_Manager::url_hash( 'https://kea-7713.test/unnamed-5582' );
		$this->set_url_stats( 0, $hash, [
			'flame_raw' => [
				'name'      => 'aggregate',
				'sum_value' => 300.0,
				'count'     => 2,
				'children'  => [ [ 'name' => 'wp_loaded', 'sum_value' => 180.0, 'ts' => self::tick(), 'children' => [] ] ],
			],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', "url:{$hash}" ] );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertEquals( 90.0, $result['ms'] );
		$this->assertNull( $result['rule'], 'a missed name matches no rule' );
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

	/** @return array<string,array{0:string}> */
	public static function url_briefs(): array {
		return [
			'span'     => [ 'span:wp_loaded' ],
			'category' => [ 'category:render' ],
		];
	}

	/**
	 * A span or category brief under a URL whose name the names Ledger left
	 * unanswered refuses, naming the Ledger, rather than brief a blank URL.
	 */
	#[DataProvider( 'url_briefs' )]
	public function test_a_url_brief_its_names_read_left_unanswered_refuses( string $descriptor ): void {
		$url  = 'https://tui.test/kereru-61';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 61, 'server' => 'tui.test' ] ] );
		$this->set_url_stats( 0, $hash, [
			'flame_raw' => [
				'name'      => 'aggregate',
				'sum_value' => 610.0,
				'count'     => 2,
				'children'  => [ [ 'name' => 'wp_loaded', 'sum_value' => 366.0, 'ts' => self::tick(), 'children' => [] ] ],
			],
			'profiles'  => $this->stored_profiles(),
		] );
		$this->refuse_stats_reads( '/^' . \preg_quote( Stats_Store::LEDGER_NAMES, '/' ) . '$/' );
		$refused = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ $descriptor, "url:{$hash}" ] );
		$this->refuse_stats_reads( '' );
		$answered = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ $descriptor, "url:{$hash}" ] );

		$this->assertIsString( $refused, 'the brief refuses' );
		$this->assertStringContainsString( Stats_Store::LEDGER_NAMES . " did not answer which URL {$hash} names", $refused );
		$this->assertIsArray( $answered, \is_string( $answered ) ? $answered : '' );
	}

	public function test_ask_resolves_a_category_through_its_url_context(): void {
		$hash = Log_Manager::url_hash( 'https://kea-7713.test/asked-cat' );
		$this->seed_urls( [ 'https://kea-7713.test/asked-cat' => [ 'count' => 5 ] ] );
		$this->set_url_stats( 0, $hash, [ 'profiles' => $this->stored_profiles() ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:render', "url:{$hash}" ] );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertSame( 'category', $result['subject'] );
		$this->assertSame( 'mean per request over 5 requests, every server', $result['scope'] );
		$this->assertSame( 'https://kea-7713.test/asked-cat', $result['url'] );
		$this->assertEqualsWithDelta( 60.0, $result['avg_time_ms'], 1e-6 );
		$this->assertEqualsWithDelta( 2.0, $result['avg_count'], 1e-6 );
		$this->assertEqualsWithDelta( 0.75, $result['share'], 1e-6 );
	}

	public function test_ask_category_under_a_url_whose_aggregate_expired_answers_from_the_leaderboard(): void {
		// The per-URL blob lives a 24th of the window; the row lives the whole
		// window. A row with no blob answers from the board the panel beside
		// it draws.
		$hash = Log_Manager::url_hash( 'https://kea-7713.test/asked-stale' );
		$this->seed_urls( [ 'https://kea-7713.test/asked-stale' => [ 'count' => 2, 'sum_ms' => 20.0 ] ] );
		$this->append( [ Stats_Store::LEDGER_LEADERBOARD => self::board( Stats_Store::bucket_start( self::tick() ), 40, 10.0, [ 'wpdb' => [ 40, 400.0, 80 ] ] ) ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:wpdb', "url:{$hash}" ] );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertSame( 'recent window', $result['scope'] );
		$this->assertEqualsWithDelta( 10.0, $result['avg_time_ms'], 1e-6 );
	}

	public function test_dump_url_serves_the_aggregate_profile_as_per_request_means(): void {
		// The modal's "Average breakdown across N requests" panel reads
		// `time` and `count` off each row and drops a row carrying neither, so
		// the stored sums have to be divided before they leave the verb.
		$hash = Log_Manager::url_hash( 'https://kea-7713.test/asked-panel' );
		$this->seed_urls( [ 'https://kea-7713.test/asked-panel' => [ 'count' => 5, 'sum_ms' => 500.0 ] ] );
		$this->set_url_stats( 0, $hash, [ 'profiles' => $this->stored_profiles() ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );

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
			'url'            => 'https://kea-7713.test/asked-under',
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
		$hash = Log_Manager::url_hash( 'https://kea-7713.test/asked-half' );
		$this->seed_urls( [ 'https://kea-7713.test/asked-half' => [ 'count' => 1 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'span:wp_loaded', "url:{$hash}" ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( "no aggregate for url {$hash}", \strtolower( $result ) );
	}

	public function test_a_span_absent_from_a_urls_aggregate_says_so(): void {
		$this->set_url_stats( 0, 'cafebabe1357', [
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
		$url  = 'https://example.com/asked-url';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 7, 'sum_ms' => 6300.0 ] ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hash}" );

		$this->assertIsArray( $result );
		$this->assertSame( 'url', $result['subject'] );
		$this->assertSame( $url, $result['url'] );
	}

	public function test_dump_request_verb_merges_flame_data_when_present(): void {
		// Rid must be ≤32 chars (fixed-width .idx field) so the lookup matches.
		$rid = $this->write_request( [
			'rid'            => 'rid-flame-123456789012345678901',
			'url'            => 'https://kea-7713.test/with-flame',
			'timestamp'      => 1700000600,
			'duration_ms'    => 12,
			'status_code'    => 200,
			'peak_mb'        => 1,
			'request_method' => 'GET',
		] );
		// Flame entry indexed by rid + url_hash; FlameBuilder writes the
		// flame body at Message::VALUE alongside the index entry.
		$url_hash = Log_Manager::url_hash( 'https://kea-7713.test/with-flame' );
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
			'url'            => 'https://kea-7713.test/with-deep-flame',
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
		$flame['url_hash'] = Log_Manager::url_hash( 'https://kea-7713.test/with-deep-flame' );
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
		$this->seed_urls( [ 'https://example.com/only-host' => [ 'count' => 3, 'sum_ms' => 30.0 ] ] );

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
		$this->seed_urls( [
			'https://example.com/a' => [ 'count' => 5, 'sum_ms' => 500.0 ],
			'https://example.com/b' => [ 'count' => 1, 'sum_ms' => 100.0 ],
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

	// ── verb grammar: verb first ────────────────────────────────────────────

	public function test_search_requests_answers_a_rid_lookup(): void {
		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', 'rid-renamed-7d3a' );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not found', \strtolower( $result ) );
	}

	public function test_grep_requests_answers_a_pattern_scan(): void {
		$this->write_firehose( 0, [
			[ 'rid' => 'g7', 'k' => 'request', 'm' => 'GET https://kea-7713.test/elsewhere', 'ts' => 1700000000.0, 'n' => 1 ],
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
			'url'            => 'https://kea-7713.test/flame-in-p2',
			'timestamp'      => 1700003100,
			'duration_ms'    => 27,
			'status_code'    => 200,
			'peak_mb'        => 4,
			'request_method' => 'GET',
		] );
		$this->write_flame(
			[
				'rid'      => $rid,
				'url_hash' => Log_Manager::url_hash( 'https://kea-7713.test/flame-in-p2' ),
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
		$url   = 'https://kea-7713.test/spread-across-partitions';
		$hash  = Log_Manager::url_hash( $url );
		$now   = self::tick();
		$this->seed_urls( [ $url => [ 'count' => 2, 'timed_count' => 2, 'sum_ms' => 61.0, 'last_seen' => $now - 742 ] ] );
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
	 * Readers and workers across two partitions and two buckets, every
	 * figure differing by URL on every sort so no tie decides an order, and
	 * three URLs that errored.
	 *
	 * @return array<string,array<string,int|float|bool>> Each URL's summed row, as a page shows it.
	 */
	private function seed_family_window(): array {
		$this->activate_shipped( 'performance', 2 );
		$now   = self::tick();
		$open  = Stats_Store::bucket_start( $now );
		$older = Stats_Store::bucket_start( $now - 3000 );
		$this->seed_urls( [ 'https://example.com/kakapo-4417' => [ 'count' => 30, 'timed_count' => 27, 'sum_ms' => 1100.0, 'sum_peak_mb' => 60.0, 'min_ms' => 12.5, 'max_ms' => 97.0, 'last_seen' => $now - 340 ] ], $open, 0 );
		$this->seed_urls( [ 'https://example.com/kakapo-4417' => [ 'count' => 11, 'timed_count' => 10, 'sum_ms' => 417.0, 'sum_peak_mb' => 22.0, 'min_ms' => 20.0, 'max_ms' => 60.0, 'errors' => 1, 'last_seen' => $now - 40 ] ], $open, 1 );
		$this->seed_urls( [ 'https://example.com/weka-1308' => [ 'count' => 13, 'timed_count' => 11, 'sum_ms' => 2860.0, 'sum_peak_mb' => 91.0, 'min_ms' => 55.0, 'max_ms' => 480.0, 'errors' => 2, 'last_seen' => $now - 25 ] ], $open, 1 );
		$this->seed_urls( [ 'https://example.com/takahe-9920' => [ 'count' => 211, 'timed_count' => 200, 'sum_ms' => 7000.0, 'sum_peak_mb' => 633.0, 'min_ms' => 4.0, 'max_ms' => 350.0, 'last_seen' => $now - 3000 ] ], $older, 0 );
		$this->seed_urls( [ 'https://example.com/jobs/cron-sweep/7?job' => [ 'count' => 29, 'sum_ms' => 8700.0, 'sum_peak_mb' => 435.0, 'min_ms' => 120.0, 'max_ms' => 910.0, 'errors' => 3, 'last_seen' => $now - 10, 'worker' => true ] ], $open, 0 );
		$this->seed_urls( [ 'https://example.com/jobs/digest/5?job' => [ 'count' => 67, 'timed_count' => 60, 'sum_ms' => 42000.0, 'sum_peak_mb' => 1072.0, 'min_ms' => 260.0, 'max_ms' => 1500.0, 'last_seen' => $now - 1300, 'worker' => true ] ], $older, 1 );
		return [
			'https://example.com/kakapo-4417'           => [ 'count' => 41, 'errors' => 1, 'avg_ms' => 1517.0 / 37, 'min_ms' => 12.5, 'max_ms' => 97.0, 'avg_peak_mb' => 82.0 / 41, 'last_updated' => $now - 40, 'worker' => false ],
			'https://example.com/weka-1308'             => [ 'count' => 13, 'errors' => 2, 'avg_ms' => 260.0, 'min_ms' => 55.0, 'max_ms' => 480.0, 'avg_peak_mb' => 7.0, 'last_updated' => $now - 25, 'worker' => false ],
			'https://example.com/takahe-9920'           => [ 'count' => 211, 'errors' => 0, 'avg_ms' => 35.0, 'min_ms' => 4.0, 'max_ms' => 350.0, 'avg_peak_mb' => 3.0, 'last_updated' => $now - 3000, 'worker' => false ],
			'https://example.com/jobs/cron-sweep/7?job' => [ 'count' => 29, 'errors' => 3, 'avg_ms' => 300.0, 'min_ms' => 120.0, 'max_ms' => 910.0, 'avg_peak_mb' => 15.0, 'last_updated' => $now - 10, 'worker' => true ],
			'https://example.com/jobs/digest/5?job'     => [ 'count' => 67, 'errors' => 0, 'avg_ms' => 700.0, 'min_ms' => 260.0, 'max_ms' => 1500.0, 'avg_peak_mb' => 16.0, 'last_updated' => $now - 1300, 'worker' => true ],
		];
	}

	/** @return array<string,array{0:string,1:string,2:bool,3:bool}> */
	public static function every_sort_and_filter(): array {
		$out = [];
		foreach ( Stats_Store::URL_SORTS as $sort ) {
			foreach ( Stats_Store::URL_ORDERS as $order ) {
				foreach ( [ 'readers' => [ false, false ], 'with workers' => [ true, false ], 'errors only' => [ false, true ], 'workers\' errors' => [ true, true ] ] as $name => [ $workers, $errors ] ) {
					$out[ "{$sort} {$order}, {$name}" ] = [ $sort, $order, $workers, $errors ];
				}
			}
		}
		return $out;
	}

	/**
	 * Every sort pages the one filtered set, in the order its field gives,
	 * under one header: the sorts the Ledger ranks (`TOP`) and the sorts
	 * read whole and ordered here answer the same rows and the same header.
	 */
	#[DataProvider( 'every_sort_and_filter' )]
	public function test_every_sort_pages_the_one_set_under_one_header( string $sort, string $order, bool $workers, bool $errors ): void {
		$expected = \array_filter( $this->seed_family_window(), static fn ( array $row ): bool => ( $workers || ! $row['worker'] ) && ( ! $errors || $row['errors'] > 0 ) );
		$field    = $errors && 'count' === $sort ? 'errors' : $sort;
		$urls     = \array_keys( $expected );
		\usort(
			$urls,
			static function ( string $a, string $b ) use ( $expected, $field, $order ): int {
				$by = 'url' === $field ? \strcmp( $a, $b ) : $expected[ $a ][ $field ] <=> $expected[ $b ][ $field ];
				return 'asc' === $order ? $by : -$by;
			}
		);
		$filters = [ ...( $workers ? [ '--include_workers=1' ] : [] ), ...( $errors ? [ '--errors_only=1' ] : [] ) ];

		$page   = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ "--sort={$sort}", "--order={$order}", '--limit=100', ...$filters ] );
		$paged  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ "--sort={$sort}", "--order={$order}", '--limit=2', '--offset=1', ...$filters ] );
		$whole  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--sort=avg_ms', '--order=desc', '--limit=100', ...$filters ] );

		$this->assertSame( $urls, \array_column( $page['data'], 'url' ) );
		$this->assertSame( \array_slice( $urls, 1, 2 ), \array_column( $paged['data'], 'url' ) );
		$this->assertSame( \count( $urls ), $page['rows'] );
		$this->assertSame( $whole['rows'], $paged['rows'] );
		$this->assertEqualsWithDelta( $whole['totals'], $page['totals'], 1e-9 );
		$this->assertEqualsWithDelta( $whole['slowest'], $page['slowest'], 1e-9 );
		$this->assertEqualsWithDelta( \array_column( $whole['data'], null, 'url' ), \array_column( $page['data'], null, 'url' ), 1e-9, 'row for row' );
		foreach ( $page['data'] as $row ) {
			$this->assertEqualsWithDelta( $expected[ $row['url'] ]['avg_ms'], $row['avg_ms'], 1e-9, $row['url'] );
			$this->assertEqualsWithDelta( $expected[ $row['url'] ]['min_ms'], $row['min_ms'], 1e-9, $row['url'] );
			$this->assertSame( $expected[ $row['url'] ]['last_updated'], $row['last_updated'], $row['url'] );
		}
	}

	/**
	 * Errors-only counts a URL's traffic in the buckets it errored in alone:
	 * `/weka` errs in the open bucket and runs clean, and far bigger, in an
	 * older one, so its errored row is the open bucket's 13 requests and its
	 * 480 ms, never the clean bucket's 500 and 9000 ms. A search reads it the
	 * same way.
	 */
	public function test_an_errors_only_page_counts_only_the_buckets_each_url_errored_in(): void {
		$open  = Stats_Store::bucket_start( self::tick() );
		$older = Stats_Store::bucket_start( self::tick() - 3000 );
		$this->seed_urls( [
			'https://kea.test/weka-1308' => [ 'count' => 13, 'timed_count' => 11, 'sum_ms' => 2860.0, 'max_ms' => 480.0, 'count_2xx' => 11, 'errors' => 2 ],
			'https://kea.test/tui-9913'  => [ 'count' => 4, 'timed_count' => 0, 'count_2xx' => 0, 'errors' => 4 ],
			'https://kea.test/clean-50'  => [ 'count' => 50, 'sum_ms' => 500.0 ],
		], $open );
		$this->seed_urls( [ 'https://kea.test/weka-1308' => [ 'count' => 500, 'sum_ms' => 5000.0, 'max_ms' => 9000.0 ] ], $older );

		$page     = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--errors_only=1', '--sort=count', '--order=desc' ] );
		$searched = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--errors_only=1', '--search=weka' ] );

		$this->assertSame( [ 'https://kea.test/tui-9913', 'https://kea.test/weka-1308' ], \array_column( $page['data'], 'url' ), 'ranked by errors' );
		$this->assertSame( 13, $page['data'][1]['count'], 'the clean bucket\'s 500 requests are not errored traffic' );
		$this->assertEqualsWithDelta( 480.0, $page['data'][1]['max_ms'], 1e-9, 'nor its 9000 ms' );
		$this->assertSame( 2, $page['rows'] );
		$this->assertSame( 17, $page['totals']['requests'] );
		$this->assertSame( 6, $page['totals']['errors'] );
		$this->assertEqualsWithDelta( 2860.0 / 11, $page['totals']['avg_ms'], 1e-9, 'the timeouts count toward requests, not toward the mean' );
		$this->assertSame( 13, $searched['data'][0]['count'] ?? null, 'a search reads the errored buckets alone too' );
	}

	/**
	 * A URL counts only under the key and the bucket it errored in: `/kaka`
	 * errs in its reader traffic and runs clean, and bigger, as a worker in
	 * the same bucket, so with workers included its errored row is the
	 * reader's 4 requests, never the worker's 90, on the page, in the
	 * totals and under a search.
	 */
	public function test_an_errors_only_url_counts_only_the_family_it_errored_in(): void {
		$url = 'https://kea.test/kaka-4471';
		$this->seed_urls( [ $url => [ 'count' => 4, 'timed_count' => 3, 'sum_ms' => 300.0, 'count_2xx' => 3, 'errors' => 1 ] ] );
		$this->seed_urls( [ $url => [ 'count' => 90, 'sum_ms' => 900.0, 'worker' => true ] ] );

		$page     = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--errors_only=1', '--include_workers=1' ] );
		$searched = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--errors_only=1', '--include_workers=1', '--search=kaka' ] );

		$this->assertSame( [ [ $url, 4 ] ], \array_map( static fn ( array $row ): array => [ $row['url'], $row['count'] ], $page['data'] ) );
		$this->assertSame( [ 4, 1 ], [ $page['totals']['requests'], $page['totals']['errors'] ] );
		$this->assertEqualsWithDelta( 100.0, $page['totals']['avg_ms'], 1e-9 );
		$this->assertSame( 4, $searched['data'][0]['count'] ?? null );
		$this->assertSame( 4, $searched['totals']['requests'] );
	}

	/**
	 * An unsearched page is ranked and totalled inside the Ledger, whatever
	 * its sort: a `TOP` ranking by the sort's column, its ratio or the URL,
	 * and a `SUM` grouped by key for the totals. No read carries the scope's
	 * rows one by one.
	 */
	public function test_an_unsearched_page_is_ranked_and_totalled_in_the_ledger(): void {
		$this->seed_urls( [
			'https://kea.test/weka-1308' => [ 'count' => 13, 'sum_ms' => 2860.0 ],
			'https://kea.test/kiwi-8842' => [ 'count' => 3, 'sum_ms' => 90.0 ],
		] );
		$ranks = [
			'count'        => 'count',
			'url'          => 'x',
			'avg_ms'       => [ 'sum_ms', 'timed_count' ],
			'min_ms'       => 'min_ms',
			'max_ms'       => 'max_ms',
			'avg_peak_mb'  => [ 'sum_peak_mb', 'count' ],
			'last_updated' => 'last_seen',
		];
		$this->assertSame( Stats_Store::URL_SORTS, \array_keys( $ranks ) );

		foreach ( $ranks as $sort => $rank ) {
			Core::$memd = new InMemoryMemcached();
			$this->forget_stats_asks();
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ "--sort={$sort}" ] );

			$orders = [];
			foreach ( $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS ) as [ $verb, $query ] ) {
				if ( 'TOP' === $verb ) {
					$orders[] = $query['order_by'];
					continue;
				}
				$this->assertSame( [ 'SUM', 'k' ], [ $verb, $query['group'] ?? null ], "{$sort}: a SUM totals by key" );
			}
			$this->assertContains( $rank, $orders, "{$sort}: the page ranks in the Ledger" );
		}

		// Errors-only: the page and the slowest are two TOPs, and every SUM
		// keeps each URL's errored buckets in the Ledger; nothing pages.
		Core::$memd = new InMemoryMemcached();
		$this->forget_stats_asks();
		VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--errors_only=1' ] );
		$asks = $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS );
		$this->assertCount( 2, \array_filter( $asks, static fn ( array $ask ): bool => 'TOP' === $ask[0] ) );
		foreach ( \array_filter( $asks, static fn ( array $ask ): bool => 'SUM' === $ask[0] ) as [ , $query ] ) {
			$this->assertSame( [ 'k', 'errors', true ], [ $query['group'] ?? null, $query['positive'] ?? null, $query['positive_each_t'] ?? null ], 'the Ledger totals the errored rows by key' );
		}
	}

	/**
	 * A request 23h50m behind a tick at :37:40 falls in the window's oldest,
	 * partial hour, whose names are filed at the hour's start, before the
	 * window opens: the table lists it, and a hash finds it.
	 */
	public function test_a_request_in_the_oldest_partial_hour_is_named(): void {
		$url  = 'https://kea.test/oldest-hour-4471';
		$hash = Log_Manager::url_hash( $url );
		$t    = Stats_Store::bucket_start( self::tick() - 85800 );
		$this->assertSame( self::INTO_HOUR, self::tick() % Stats_Store::HOUR_SECONDS );
		$this->seed_urls( [ $url => [ 'count' => 7, 'sum_ms' => 70.0, 'server' => 'kea.test' ] ], $t );
		$this->append( [ Stats_Store::LEDGER_URL_DIMS => [ [ $t, Stats_Store::url_dim_key( 'status', $url ), '2xx', [ 7, 70.0, 0.0, 7 ] ] ] ] );

		$page      = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );
		$dump      = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );
		$breakdown = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', "{$hash} --breakdown=status" );
		$ask       = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hash}" );

		$this->assertSame( [ $url ], \array_column( $page['data'], 'url' ), 'the site scope names its server' );
		$this->assertSame( 7, $page['totals']['requests'] );
		$this->assertSame( 7, $dump['stats']['count'] ?? null, (string) \json_encode( $dump ) );
		$this->assertSame( 7, self::wire_series( $breakdown['breakdown_time_series'] )[ Stats_Store::bucket_key( $t ) ]['2xx'][ Stats_Store::DIM_COUNT ] ?? null );
		$this->assertSame( 7, $ask['stats']['count'] ?? null );
	}

	public function test_a_repeated_urls_page_is_served_from_its_cache(): void {
		$this->seed_urls( [
			'https://kea.test/wombat-7731' => [ 'count' => 5 ],
			'https://kea.test/kiwi-8842'   => [ 'count' => 3 ],
		] );
		$fire  = static fn ( string ...$args ): array => VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', $args );
		$reads = fn (): int => \count( $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS ) );

		$first = $fire( '--sort=count', '--order=desc', '--limit=100' );
		$built = $reads();
		$again = $fire( '--sort=count', '--order=desc', '--limit=100' );

		$this->assertGreaterThan( 0, $built, 'the first page reads the Ledger' );
		$this->assertSame( $built, $reads(), 'the second page reads nothing' );
		$this->assertSame( $first['data'], $again['data'] );
		$this->assertSame( 2, $first['rows'] );
		$fire( '--sort=max_ms', '--order=desc', '--limit=100' );
		$this->assertGreaterThan( $built, $reads(), 'another sort is another page' );
		$searched = $fire( '--sort=count', '--order=desc', '--limit=100', '--search=wombat-7731' );
		$this->assertSame( 1, $searched['rows'], 'a filter is another page, and a hit must be THAT page' );
	}

	public function test_the_widest_cached_urls_page_fits_the_item_budget(): void {
		// The widest page the cache stores, of the widest rows a reply can
		// name: a 259-byte host, a 1,024-byte path, every number at length.
		$server = \str_pad( 'srv.', 259, 'x' );
		$path   = 1024;
		$wide   = -1.2345678901234567E+300;
		$rows   = [];
		for ( $i = 0; $i < 300; $i++ ) {
			$count = 999_999_999_999 - $i;
			$rows[ "https://{$server}" . \str_pad( "/{$i}/", $path, 'x' ) ] = [
				'count' => $count, 'timed_count' => $count, 'count_2xx' => $count, 'count_3xx' => $count,
				'count_4xx' => $count, 'count_5xx' => $count, 'sum_ms' => $wide, 'sum_peak_mb' => $wide,
				'min_ms' => $wide, 'max_ms' => $wide, 'max_peak_mb' => $wide, 'server' => $server,
			];
		}
		$this->seed_urls( $rows );

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

	public function test_a_urls_reply_dates_itself_from_the_substrate_clock(): void {
		// A second already PAST, and a stride no fallback here uses: the real
		// clock never returns it again, so a wall read left anywhere on this
		// path dates the reply as something else.
		$pinned = self::tick() - 1234;
		self::clock_at( $pinned );
		$this->seed_urls( [ 'https://kea.test/wombat-7731' => [ 'count' => 5 ] ], Stats_Store::bucket_start( $pinned ) );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--limit=100' ] );

		$this->assertIsArray( $reply );
		$this->assertSame( $pinned, $reply['as_of'] );
		$this->assertSame( 1, $reply['rows'] );
	}

	/**
	 * A page a Ledger left unanswered says so and is never cached: the next
	 * poll reads the Ledger again and answers whole.
	 */
	public function test_a_page_a_ledger_left_unanswered_is_provisional_and_never_cached(): void {
		$this->seed_urls( [ 'https://tui.test/kereru-43' => [ 'count' => 43, 'server' => 'tui.test' ] ] );
		$this->refuse_stats_reads( '/^' . \preg_quote( Stats_Store::LEDGER_URL_ROWS, '/' ) . '$/' );

		$short = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--server=tui.test' ] );
		$this->refuse_stats_reads( '' );
		$whole = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--server=tui.test' ] );

		$this->assertTrue( $short['provisional'] );
		$this->assertSame( 0, $short['totals']['requests'] );
		$this->assertFalse( $whole['provisional'] );
		$this->assertSame( 43, $whole['totals']['requests'], 'read again, not served from a cache' );
	}

	/**
	 * A names read the Ledger left unanswered is not a missing URL: the
	 * URL modal and the `url:` brief say which Ledger did not answer.
	 */
	public function test_an_unanswered_names_read_is_no_missing_url(): void {
		$url  = 'https://tui.test/kereru-47';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 47, 'server' => 'tui.test' ] ] );
		$this->refuse_stats_reads( '/^' . \preg_quote( Stats_Store::LEDGER_NAMES, '/' ) . '$/' );

		$replies = [
			'dump_url' => VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash ),
			'ask'      => VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hash}" ),
		];
		$this->refuse_stats_reads( '' );

		foreach ( $replies as $verb => $reply ) {
			$this->assertIsString( $reply, "{$verb} refuses" );
			$this->assertStringContainsString( Stats_Store::LEDGER_NAMES, $reply, "{$verb} names the Ledger" );
			$this->assertStringNotContainsString( 'not found', $reply, "{$verb} does not call the URL missing" );
		}
		$this->assertSame( 47, VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash )['stats']['count'], 'answered, the URL is there' );
	}

	/** A breakdown whose names read went unanswered says so, and reads whole once it answers. */
	public function test_a_url_breakdown_left_unanswered_is_provisional(): void {
		$url  = 'https://tui.test/kereru-53';
		$hash = Log_Manager::url_hash( $url );
		$this->seed_urls( [ $url => [ 'count' => 53, 'server' => 'tui.test' ] ] );
		$this->refuse_stats_reads( '/^' . \preg_quote( Stats_Store::LEDGER_NAMES, '/' ) . '$/' );
		$short = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', "{$hash} --breakdown=status" );
		$this->refuse_stats_reads( '' );
		$whole = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', "{$hash} --breakdown=status" );

		$this->assertTrue( $short['provisional'] ?? null );
		$this->assertFalse( $whole['provisional'] ?? null );
	}

	public function test_the_leaderboard_sums_each_category_and_its_entries_across_buckets(): void {
		$now = self::tick();
		$this->append( [
			Stats_Store::LEDGER_LEADERBOARD => [
				...self::board( Stats_Store::bucket_start( $now - 7200 ), 4, 8.0, [ 'db' => [ 3, 1.5, 6 ] ] ),
				[ Stats_Store::bucket_start( $now - 7200 ), Stats_Store::SITE, Stats_Store::member( 'db', 'wpdb::query' ), [ 3, 0.9, 3 ] ],
				...self::board( Stats_Store::bucket_start( $now ), 6, 12.0, [ 'db' => [ 4, 2.5, 10 ] ] ),
				[ Stats_Store::bucket_start( $now ), Stats_Store::SITE, Stats_Store::member( 'db', 'wpdb::query' ), [ 1, 0.3, 2 ] ],
				[ Stats_Store::bucket_start( $now ), Stats_Store::SITE, Stats_Store::member( 'db', 'wpdb::get_row' ), [ 4, 1.1, 4 ] ],
			],
		] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview' )['global_leaderboard'];

		$this->assertSame( 10, $board['count'] );
		$this->assertSame( 7, $board['categories']['db']['samples'] );
		$this->assertEqualsWithDelta( 0.4, $board['categories']['db']['time'], 0.0001 );
		$this->assertEqualsWithDelta( 1.6, $board['categories']['db']['count'], 0.0001 );
		$this->assertSame( [ 'wpdb::get_row', 'wpdb::query' ], \array_keys( $board['categories']['db']['entries'] ) );
	}

	public function test_a_scoped_search_that_names_nothing_answers_zero_rather_than_null(): void {
		$this->seed_urls( [ 'https://moa.test/kakapo-3310' => [ 'count' => 4, 'sum_ms' => 80.0, 'server' => 'moa.test' ] ] );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=moa.test --search=nomatch' );

		$this->assertIsArray( $page['totals'], 'a term nothing matches is zero requests, not an unanswerable scope' );
		$this->assertSame( 0, $page['totals']['requests'] );
		$this->assertSame( 0, $page['rows'] );
	}

	public function test_a_whitespace_only_search_is_the_same_page_as_no_search(): void {
		$this->seed_urls( [ 'https://kea.test/wombat-7731' => [ 'count' => 5 ] ] );

		// An ARRAY: `fire()` would split the string on the very space at issue.
		$blank = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search= ' ] );
		$this->forget_stats_asks();
		$none  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls' );

		$this->assertSame( $none['data'], $blank['data'] );
		$this->assertSame( [], $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS ), 'one cache entry answers both' );
		$this->assertSame( ' ', $blank['filters']['search'], 'the echo still says what was typed' );
	}

	public function test_one_page_cache_entry_serves_a_term_however_it_was_typed(): void {
		$this->seed_urls( [ 'https://kea.test/weka-4410' => [ 'count' => 7 ] ] );

		$first = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=weka' ] );
		$this->forget_stats_asks();
		$again = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=WEKA ' ] );

		$this->assertSame( [], $this->ledger_asks( Stats_Store::LEDGER_URL_ROWS ), 'case and trailing space are the same search' );
		$this->assertSame( $first['data'], $again['data'] );
		$this->assertSame( 1, $again['rows'] );
		$this->assertSame( 'WEKA ', $again['filters']['search'], 'the echo still says what was typed' );
	}

	public function test_a_search_keeps_only_the_urls_carrying_every_word_of_the_term(): void {
		$this->seed_urls( [
			'https://kea.test/category/shoes'  => [ 'count' => 9 ],
			'https://kea.test/shoes/sale-4471' => [ 'count' => 7 ],
			'https://kea.test/category/news'   => [ 'count' => 5 ],
		] );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=category shoes' ] );

		$this->assertSame( [ 'https://kea.test/category/shoes' ], \array_column( $page['data'], 'url' ) );
		$this->assertSame( 1, $page['rows'] );
	}

	/** `Other` files servers past the cap, each URL under its own host, so its scope filters no host. */
	public function test_a_search_scoped_to_other_keeps_the_hosts_filed_under_it(): void {
		$this->seed_urls( [
			'https://kea.test/aisle-9'  => [ 'count' => 4, 'server' => Stats_Store::OTHER_KEY ],
			'https://wren.test/aisle-12' => [ 'count' => 6, 'server' => 'wren.test' ],
		] );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--server=' . Stats_Store::OTHER_KEY, '--search=aisle' ] );

		$this->assertSame( [ 'https://kea.test/aisle-9' ], \array_column( $page['data'], 'url' ) );
	}

	/** A site whose servers outnumber what one page names is refused, asking for a server. */
	public function test_a_site_page_past_the_servers_it_names_is_refused(): void {
		$names = [];
		for ( $i = 0; $i <= Stats_Store::SERVERS_READ_MAX; $i++ ) {
			$names[] = [ Stats_Store::bucket_start( self::tick() ), Stats_Store::servers_key( false ), \sprintf( 'srv-%03d.test', $i ), [] ];
		}
		$this->append( [ Stats_Store::LEDGER_NAMES => $names ] );

		$refused = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--sort=count' ] );

		$this->assertIsString( $refused );
		$this->assertStringContainsString( 'more than ' . Stats_Store::SERVERS_READ_MAX . ' servers filed rows in the window; pick one', $refused );
		$scoped = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--server=srv-007.test' ] );
		$this->assertIsArray( $scoped, 'one server is read' );
	}

	/** A term of no word names nothing while the name scan is off, as it ships. */
	public function test_with_names_off_a_term_of_no_word_names_nothing(): void {
		$this->seed_urls( [ 'https://kea.test/wombat-7731' => [ 'count' => 5 ] ] );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=7' ] );

		$this->assertFalse( Performance_CI_Node::$match_names );
		$this->assertSame( 0, $page['rows'], 'no word to look up, and no name scan to fall to' );
	}

	/** The name scan takes a term of no word as a substring of every path. */
	public function test_the_name_scan_takes_a_term_of_no_word(): void {
		Performance_CI_Node::$match_names = true;
		$this->seed_urls( [
			'https://kea.test/wombat-7731' => [ 'count' => 5 ],
			'https://kea.test/kiwi-8842'   => [ 'count' => 3 ],
		] );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=7' ] );

		$this->assertSame( [ 'https://kea.test/wombat-7731' ], \array_column( $page['data'], 'url' ) );
	}

	/** A term with words is the word lookup's, whole words alone, name scan or not. */
	public function test_a_term_with_words_never_reaches_the_name_scan(): void {
		Performance_CI_Node::$match_names = true;
		$this->seed_urls( [ 'https://kea.test/wombat-7731' => [ 'count' => 5 ] ] );

		$part  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=wom' ] );
		$whole = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=wombat' ] );

		$this->assertSame( 0, $part['rows'], 'a part of a word is no word of the path' );
		$this->assertSame( 1, $whole['rows'] );
	}

	/** A server's rows are its own key, so a server with none answers zero beside another's traffic. */
	public function test_a_scoped_page_for_a_server_with_no_rows_answers_zero_totals(): void {
		$this->seed_urls( [ 'https://kea.test/takahe-6620' => [ 'count' => 11, 'server' => 'kea.test' ] ] );

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--server=moa.test' );

		$this->assertSame( 0, $page['totals']['requests'], 'moa.test served none of kea.test\'s 11' );
		$this->assertSame( [], $page['data'] );
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

	/**
	 * An unsearched page logs its caches built then hit; a search logs its
	 * `url search read`, the index lookup and its rows, with the rows it kept.
	 */
	public function test_a_url_page_logs_its_caches_and_a_search_its_search_read(): void {
		$this->seed_urls( [
			'https://kea.test/wombat-7731' => [ 'count' => 5 ],
			'https://kea.test/kiwi-8842'   => [ 'count' => 3 ],
			'https://kea.test/weka-9953'   => [ 'count' => 2 ],
		] );

		$entries = $this->logged_in( $this->tmp, static function (): void {
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--sort=count', '--order=desc', '--limit=100' ] );
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--sort=count', '--order=desc', '--limit=100' ] );
			VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', [ '--search=kiwi' ] );
		} );

		$this->assertSame( [ 'built', 'hit', 'built' ], self::completed( $entries, Flame_Tree::URL_PAGE_CACHE ) );
		$this->assertSame( [ 'built' ], self::completed( $entries, Flame_Tree::URL_HEADER_CACHE ), 'the hit reads no header, and a search builds its own' );
		$this->assertSame( [ 1 ], self::completed( $entries, Flame_Tree::URL_SEARCH_READ ), 'the search alone looks its words up, and says the rows it kept' );
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
}
