<?php
/**
 * PerformanceCITest: the `performance` service CI's verbs.
 *
 * Substrate config (num_partitions, min_lifetime, base_directory) is seeded
 * via TestCase::use_base_dir(). `overview` and `urls` read the `flame-facts`
 * rows `FactsSeeder` writes; `dump_url`, `url_breakdown` and the URL briefs
 * read the stats Tables, seeded through the harness stores.
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
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Settings_Event_Writer;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Event_Logger_Nodes\Url_Parts;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;

/**
 * The `performance` service CI's verbs and loaders.
 *
 * @phpstan-import-type Read_Plan from Performance_CI_Node
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
		$this->activate_shipped_with_stats_tables( 'performance', 1 );
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
		// The request line that gives a record its url gives it a method too.
		return $this->write_indexed( 'requests', Request_Builder_Node::format_index_entry( ... ), $body + [ 'request_method' => 'GET' ], $partition );
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
		$this->assertIsArray( VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'a4471ab0c0de --breakdown=method' ) );
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

		$this->assertIsArray( $ci->dispatch( 'url_breakdown', [ 'a4471ab0c0de', '--breakdown=status' ] ) );
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

	public function test_stats_fail_soft_when_the_tables_dir_cannot_be_made(): void {
		// A file where the directory belongs: every Table's file fails to open.
		\file_put_contents( $this->tmp . '/tables', 'not a directory' );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'a4471ab0c0de --breakdown=status' );

		$this->assertIsArray( $reply, 'no data, no throw' );
		$this->assertSame( [ 'names' => [], 'buckets' => [] ], $reply['breakdown_time_series'] );
	}

	public function test_stats_fail_soft_when_a_table_cannot_open(): void {
		\Newspack_Nodes\Sqlite_Arm::$available = static fn (): bool => false;
		try {
			$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'a4471ab0c0de --breakdown=status' );
		} finally {
			\Newspack_Nodes\Sqlite_Arm::$available = null;
		}
		$this->assertIsArray( $reply, 'no data, no throw' );
		$this->assertSame( [ 'names' => [], 'buckets' => [] ], $reply['breakdown_time_series'] );
		$this->assertNull( Core::node( 'flame-stats:aggregate.p0' ), 'a mount that threw leaves nothing mounted' );
		$this->assertNotEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'stats Tables did not mount' ) && \str_contains( $line, 'pdo_sqlite' ) ),
			'the refusal is said, naming its cause'
		);
	}

	public function test_a_declaration_an_arm_refuses_fails_the_verb_loud(): void {
		// A namespace too long for the wpdb column is the declaration's fault,
		// though its refusal names the Table and chains the arm's beneath it.
		$reply = $this->fire_url_breakdown_with_topology( 'stats-kea-4471', 'make_node Table flame-stats:aggregate ' . \str_repeat( 'k', 204 ) . ' 777 wpdb' );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'Table flame-stats:aggregate: wpdb backend cannot hold namespace', $reply );
	}

	public function test_a_tables_dir_that_is_a_symlink_fails_the_verb_loud(): void {
		// A path the runtime refuses to adopt is the operator's to fix, not a
		// backend that cannot open, though its refusal names the Table too.
		\mkdir( $this->tmp . '/tables-kea-5510' );
		\symlink( $this->tmp . '/tables-kea-5510', $this->tmp . '/tables' );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'a4471ab0c0de --breakdown=status' );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'symlink or path traversal detected', $reply );
	}

	public function test_a_table_declared_with_a_zero_ttl_fails_the_verb_loud(): void {
		$reply = $this->fire_url_breakdown_with_topology( 'stats-kea-3717', 'make_node Table flame-stats:aggregate evlog:p{partition} 0 sqlite' );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'TTL', $reply );
	}

	public function test_a_table_two_topologies_declare_differently_fails_the_verb_loud(): void {
		// Operator misconfiguration is no unreachable backend (decision 3): it says so.
		$reply = $this->fire_url_breakdown_with_topology( 'stats-kea-7731', 'make_node Table flame-stats:aggregate evlog:p{partition} 777 sqlite', [ 'performance', 'stats-tables' ] );

		$this->assertIsString( $reply, 'a refusal, not a zeroed dashboard' );
		$this->assertStringContainsString( 'declared differently', $reply );
	}

	/**
	 * Fire `url_breakdown` with a one-partition user topology holding `$line`
	 * active, beside the `$beside` topologies.
	 *
	 * @param string       $name   The user topology's name.
	 * @param string       $line   Its one `make_node` line.
	 * @param list<string> $beside Other active topologies.
	 * @return mixed The verb's reply.
	 */
	private function fire_url_breakdown_with_topology( string $name, string $line, array $beside = [] ): mixed {
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
			return VerbHarness::fire( new Performance_CI_Node(), 'performance', 'url_breakdown', 'a4471ab0c0de --breakdown=status' );
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

	/** A server none of whose requests timed has a null board mean too. */
	public function test_a_server_with_no_timed_request_has_a_null_board_mean(): void {
		$this->seed_request( [ 'server' => 'edge-takahe.test', 'ms' => null, 'error' => 'T' ] );
		$this->seed_request( [ 'server' => 'edge-kea.test', 'ms' => 41.5 ] );

		$board = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'overview', '--server=edge-takahe.test' )['leaderboard'];

		$this->assertNull( $board['avg_ms'] );
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
		$this->activate_shipped_with_stats_tables( 'performance', 1 );
		Core::$now = (float) \gmmktime( 14, 37, 11, 9, 29, 2026 );
		$store     = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, '2026-09-29-14-35', [ 'e71b04ac9d33' => [ 'url' => 'https://example.com/plan-4471', 'count' => 6, 'last_seen' => (int) Core::$now ] ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', $verb, $args );

		$slots = $reply['slots'] ?? [];
		$this->assertCount( 288, $slots, 'the chart draws 24 hours whatever the retention window' );
		$this->assertSame( [ '2026-09-29-14-35', '2026-09-29-14-30', '2026-09-28-14-40' ], [ $slots[0], $slots[1], $slots[287] ] );
		$this->assertArrayNotHasKey( 'plan', $reply );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function chart_replies(): array {
		return [
			'overview'      => [ 'overview', '' ],
			'dump_url'      => [ 'dump_url', 'e71b04ac9d33' ],
			'url_breakdown' => [ 'url_breakdown', 'e71b04ac9d33 --breakdown=status' ],
		];
	}

	public function test_url_breakdown_reads_its_one_dimension_as_a_name_table(): void {
		$this->activate_shipped_with_stats_tables( 'performance', 1 );
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
	}

	public function test_urls_verb_default_limit_is_50(): void {
		// Legacy controller default — `limit=50` from sanitize_callback default.
		$interpreter     = new Performance_CI_Node();
		$result = VerbHarness::fire( $interpreter, 'performance', 'urls' );

		$this->assertSame( 50, $result['limit'] );
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
			'a4471ab0c0de' => [ 'url' => 'https://example.com/wombat-4471', 'count' => 31, 'sum_ms' => 992.0, 'timed_count' => 31 ],
			'a8823bc1d2ef' => [ 'url' => 'https://example.com/quokka-8823', 'count' => 17, 'sum_ms' => 411.0, 'timed_count' => 17 ],
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
		$this->assertSame( 'https://kea.example/wombat-4471', $expired['stats']['url'], 'the row\'s own URL until a name returns' );
	}

	/**
	 * A `urlmap` entry in the old `[ path, origin ]` shape is a miss, never a
	 * name: read as `[ server, path ]` it would show the bare origin.
	 */
	public function test_an_old_shape_url_name_reads_as_no_name(): void {
		$store      = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'a4471ab0c0de' => [ 'url' => 'https://example.com/a', 'count' => 31, 'sum_ms' => 992.0, 'timed_count' => 31 ],
		] );
		$store->bucket_forget_multi( [ [ [ Stats_Store::NS_URLMAP ], 'a4471ab0c0de' ] ] );
		$absent = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'a4471ab0c0de' );
		$store->bucket_set_multi( [ [ [ Stats_Store::NS_URLMAP ], 'a4471ab0c0de', [ '/a', 'https://example.com' ] ] ] );

		$this->assertSame( [], $store->get_url_names( [ 'a4471ab0c0de' ] ) );
		$detail = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'a4471ab0c0de' );
		$this->assertSame( $absent['stats'], $detail['stats'], 'resolved as with no name at all' );
	}

	/**
	 * A modal costs its own row's keys, never the index fan-out: `load_row()`
	 * reads the one URL's row by key, in the server `urlmap` locates.
	 */
	public function test_one_row_costs_its_keys_not_the_whole_index(): void {
		$store = $this->stats_store( 0, 86400 );
		// Three shards; the modal reads its own row's keys.
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'a4471ab0c0de' => [ 'url' => 'https://example.com/wombat-4471', 'count' => 31, 'sum_ms' => 992.0, 'timed_count' => 31 ],
			'b8823bc1d2ef' => [ 'url' => 'https://example.com/quokka-8823', 'count' => 17, 'sum_ms' => 411.0, 'timed_count' => 17 ],
			'c3309cd4e5f6' => [ 'url' => 'https://example.com/numbat-3309', 'count' => 9, 'sum_ms' => 90.0, 'timed_count' => 9 ],
		] );
		$detail = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'a4471ab0c0de' );

		$this->assertSame( 'https://example.com/wombat-4471', $detail['stats']['url'] );
		$this->assertSame( [], $this->asked_keys( Stats_Store::NS_URLS ), 'a modal must not pay the index fan-out' );
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
			$hash => [ 'url' => 'https://example.com/wombat-4471', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 70.0 ],
		] );
		$unfolded = Stats_Store::buckets_in_hour( $plan['hours'][0] );
		$this->seed_url_shard( $store, (string) \end( $unfolded ), $shard, [
			$hash => [ 'url' => 'https://example.com/wombat-4471', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 50.0 ],
		] );

		$row = Performance_CI_Node::load_row( $hash, '', self::window_plan( (int) Core::$now ), $this->live_stores() );

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
			$hash => [ 'url' => 'https://example.com/wombat-4471', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 50.0 ],
		] );
		$this->seed_url_hour( $store, $hour, $shard, [
			$hash => [ 'url' => 'https://example.com/wombat-4471', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 50.0 ],
		] );

		$row = Performance_CI_Node::load_row( $hash, '', self::window_plan( (int) Core::$now ), $this->live_stores() );

		$this->assertSame( 5, $row['count'], 'the fold replaces its buckets, it does not add to them' );
	}

	public function test_dump_url_returns_recent_matching_requests(): void {
		// dump_url's `requests` slice walks requests.log for entries whose
		// url_hash matches. Seed the URL in the memcache index AND two on-disk
		// requests so the collect + dedup walk runs (not the empty-result skip).
		// Timestamps ride the clock: the walk stops at the retention floor, so a
		// fixed epoch would put the whole fixture behind it.
		$url    = 'https://example.com/recent-list';
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
		$this->activate_shipped_with_stats_tables( 'performance', 4 );
		$hash = $this->seed_listed_url( 'https://example.com/spread-over-four', 2400 );
		$now  = self::tick();
		for ( $i = 0; $i < 2400; $i++ ) {
			$this->write_request( [
				'rid'            => \sprintf( 'rid-spread-%021d', $i ),
				'url'            => 'https://example.com/spread-over-four',
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
		$this->assertFalse( $result['scan_stopped_early'], 'a capped partition has handed over its newest' );
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
		$this->activate_shipped_with_stats_tables( 'performance', 4 );
		$url  = 'https://example.com/three-of-four';
		$hash = $this->seed_listed_url( $url, 4 );
		$now  = self::tick();
		foreach ( [ [ 'rid-p0-older-0000000000000871', 71, 0 ], [ 'rid-p0-newer-0000000000000872', 29, 0 ], [ 'rid-p1-only-00000000000000873', 43, 1 ], [ 'rid-p3-only-00000000000000874', 17, 3 ] ] as [ $rid, $ago, $partition ] ) {
			$this->write_request( [ 'rid' => $rid, 'url' => $url, 'timestamp' => $now - $ago, 'duration_ms' => 19, 'status_code' => 200, 'peak_mb' => 2, 'request_method' => 'GET' ], $partition );
		}
		for ( $i = 0; $i < 150; $i++ ) {
			$this->write_request( [ 'rid' => \sprintf( 'rid-elsewhere-%018d', $i ), 'url' => 'https://example.com/elsewhere-on-p2', 'timestamp' => $now - 600 + $i, 'duration_ms' => 23, 'status_code' => 200, 'peak_mb' => 2, 'request_method' => 'GET' ], 2 );
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
		$this->activate_shipped_with_stats_tables( 'performance', 4 );
		$url  = 'https://example.com/indexed-late';
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
		$hash = $this->seed_listed_url( 'https://example.com/bad-cursor', 1 );

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
		$url    = 'https://example.com/buried-under-neighbours';
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

	public function test_dump_url_calls_a_capped_request_list_complete(): void {
		// One past the cap: each partition walks newest-first, so the list it
		// stops at the cap is the newest RECENT_REQUEST_LIMIT, exactly.
		$url   = 'https://example.com/at-the-request-cap';
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
		$this->assertFalse( $result['scan_stopped_early'], 'the cap keeps the newest, so only the time budget stops a walk short' );
		// And it keeps the NEWEST end. Walking forward from the oldest, the cap
		// fires on the oldest matches, so the panel would show the start of a
		// busy URL's history sorted descending to look convincing.
		$rids = \array_column( $result['requests'], 'rid' );
		$this->assertContains( \sprintf( 'rid-cap-%024d', $limit ), $rids, 'the newest request is missing' );
		$this->assertNotContains( \sprintf( 'rid-cap-%024d', 0 ), $rids, 'the oldest should have fallen off' );
	}

	public function test_an_errors_only_dump_url_reaches_an_error_behind_the_request_cap(): void {
		// A busy URL's newest RECENT_REQUEST_LIMIT clean requests bury its
		// errors; asked for errors alone, the walk passes them to reach those.
		$url   = 'https://example.com/errors-behind-the-cap';
		$hash  = Log_Manager::url_hash( $url );
		$limit = (int) ( new \ReflectionClassConstant( Performance_CI_Node::class, 'RECENT_REQUEST_LIMIT' ) )->getValue();
		$now   = self::tick();
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => $limit + 4, 'sum_ms' => 73.0, 'last_seen' => $now - 911 ],
		] );
		$request = static fn ( string $rid, int $ago, string $error_status ): array => [
			'rid'            => $rid,
			'url'            => $url,
			'timestamp'      => $now - $ago,
			'duration_ms'    => 73,
			'status_code'    => 'T' === $error_status ? 0 : 217,
			'peak_mb'        => 9,
			'request_method' => 'GET',
			'error_status'   => $error_status,
		];
		$this->write_request( $request( 'rid-buried-timeout-0000000000001', 1907, 'T' ) );
		$this->write_request( $request( 'rid-buried-fatal-00000000000002', 1906, 'F' ) );
		$this->write_request( $request( 'rid-buried-abort-00000000000003', 1905, 'A' ) );
		$this->write_request( $request( 'rid-buried-gap-0000000000000004', 1904, 'I' ) );
		for ( $i = 0; $i < $limit; $i++ ) {
			$this->write_request( $request( \sprintf( 'rid-clean-%022d', $i ), 1408 - $i, '-' ) );
		}

		$all    = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash );
		$errors = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, '--errors_only=1' ] );

		$this->assertNotContains( 'rid-buried-timeout-0000000000001', \array_column( $all['requests'], 'rid' ), 'the cap buries it' );
		$this->assertSame(
			[ 'rid-buried-fatal-00000000000002', 'rid-buried-timeout-0000000000001' ],
			\array_column( $errors['requests'], 'rid' ),
			'a timeout and a fatal are errors; an abort and a gap are not'
		);
		$this->assertFalse( $errors['scan_stopped_early'], 'the errors walk read its whole window' );
	}

	/**
	 * A row carries a null duration where `timing_counts()` takes no sample
	 * (decision 24), and its completion as `finished_at`, the list's order.
	 */
	public function test_dump_url_lists_an_unmeasured_duration_as_null_and_orders_by_completion(): void {
		$url  = 'https://example.com/hoiho-durations';
		$hash = Log_Manager::url_hash( $url );
		$now  = self::tick();
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 3, 'timed_count' => 1, 'sum_ms' => 2417.0, 'errors' => 2, 'last_seen' => $now - 37 ],
		] );
		$request = static fn ( string $rid, int $ago, int $ms, string $error_status ): array => [
			'rid'            => $rid,
			'url'            => $url,
			'timestamp'      => $now - $ago,
			'duration_ms'    => $ms,
			'status_code'    => 'T' === $error_status ? 0 : 500,
			'peak_mb'        => 11,
			'request_method' => 'GET',
			'error_status'   => $error_status,
		];
		$this->write_request( $request( 'rid-hoiho-timeout-00000000000001', 900, 864000, 'T' ) );
		$this->write_request( $request( 'rid-hoiho-fatal-0000000000000002', 100, 2417, 'F' ) );
		$this->write_request( $request( 'rid-hoiho-zero-00000000000000003', 50, 0, 'F' ) );

		$rows = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', $hash )['requests'];

		$this->assertSame(
			[ 'rid-hoiho-timeout-00000000000001', 'rid-hoiho-zero-00000000000000003', 'rid-hoiho-fatal-0000000000000002' ],
			\array_column( $rows, 'rid' )
		);
		$this->assertSame( [ null, null, 2417 ], \array_column( $rows, 'duration_ms' ) );
		$this->assertEqualsWithDelta(
			[ $now - 900 + 864.0, $now - 50 + 0.0, $now - 100 + 2.417 ],
			\array_column( $rows, 'finished_at' ),
			0.0001
		);
	}

	/** The header's error count is the row's, exact and uncapped by the walk. */
	public function test_dump_url_stats_carry_the_urls_exact_errors(): void {
		$url  = 'https://example.com/kaka-errors';
		$hash = Log_Manager::url_hash( $url );
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 611, 'timed_count' => 590, 'sum_ms' => 41300.0, 'errors' => 23, 'last_seen' => self::tick() - 37 ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, '--errors_only=1' ] );

		$this->assertSame( 23, $result['stats']['errors'] );
	}

	/**
	 * Under errors_only a `url:` brief describes the errors the modal lists:
	 * the walk lists them alone, and the brief counts them exactly.
	 */
	public function test_an_errors_only_url_ask_briefs_the_errors_alone(): void {
		$url  = 'https://example.com/weka-errors';
		$hash = Log_Manager::url_hash( $url );
		$now  = self::tick();
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $this->current_url_bucket(), [
			$hash => [ 'url' => $url, 'count' => 412, 'timed_count' => 411, 'sum_ms' => 30140.0, 'errors' => 29, 'last_seen' => $now - 41 ],
		] );
		$request = static fn ( string $rid, int $ago, int $ms, int $code, string $error_status ): array => [
			'rid'            => $rid,
			'url'            => $url,
			'timestamp'      => $now - $ago,
			'duration_ms'    => $ms,
			'status_code'    => $code,
			'peak_mb'        => 13,
			'request_method' => 'GET',
			'error_status'   => $error_status,
		];
		$this->write_request( $request( 'rid-weka-clean-00000000000000001', 900, 9999, 200, '-' ) );
		$this->write_request( $request( 'rid-weka-timeout-000000000000002', 880, 864000, 0, 'T' ) );
		$this->write_request( $request( 'rid-weka-fatal-00000000000000003', 860, 2417, 500, 'F' ) );

		$errors = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hash} --errors_only=1" );
		$full   = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hash}" );

		$this->assertTrue( $errors['errors_only'] );
		$this->assertSame( [ 'errors' => 29 ], $errors['stats'] );
		$this->assertSame( [ 2, 1, 1 ], [ $errors['error_summary']['listed'], $errors['error_summary']['timeouts'], $errors['error_summary']['fatals'] ] );
		$this->assertSame(
			[ 'rid-weka-fatal-00000000000000003', 'rid-weka-timeout-000000000000002' ],
			\array_column( $errors['worst_requests'], 'rid' )
		);
		$this->assertNull( $errors['worst_requests'][1]['duration_ms'], 'a timeout ranks unmeasured' );
		$this->assertFalse( $full['errors_only'] );
		$this->assertSame( 412, $full['stats']['count'] );
		$this->assertSame( 'rid-weka-clean-00000000000000001', $full['worst_requests'][0]['rid'] );
	}

	public function test_an_errors_only_dump_url_rebuilds_no_flame_from_its_list(): void {
		// An error list is not the URL's traffic; the held flame stands instead.
		[ $hash ] = $this->seed_a_cold_url_with_two_flames();

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, '--errors_only=1' ] );

		$this->assertNull( $result['aggregate_flame'] );
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
		$url  = 'https://example.com/named-window-6205';
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

	/**
	 * The window plan's floor is the start of the oldest hour it reads, the
	 * instant `Stats_Store::window_start()` names for the same clock.
	 */
	public function test_the_window_plans_floor_is_the_windows_start(): void {
		$retention = 18_000;
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => $retention ] );
		// 27 minutes 27 seconds into its hour, off every bucket boundary.
		$now = 1_759_652_847;

		$this->assertSame( Stats_Store::window_start( $retention, $now ), self::window_plan( $now )['floor'] );
	}

	public function test_a_long_running_request_does_not_end_the_url_walk(): void {
		// A request logging every few minutes stays in flight indefinitely and
		// appends at completion, so its START can precede the window by hours.
		// Completion is what the window asks about, and this one completed
		// inside it — the matching entry behind it is still reachable.
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => self::SCAN_RETENTION ] );
		$now  = self::tick();
		$url  = 'https://example.com/behind-a-long-runner-5182';
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
			'url'            => 'https://example.com/a-long-running-job-6624',
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
		$url  = 'https://example.com/behind-the-retention-edge-8813';
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
			'url'            => 'https://example.com/an-unrelated-neighbour-2277',
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
		$url  = 'https://example.com/behind-a-replayed-spoke-4409';
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
			'url'            => 'https://example.com/a-lagging-spoke-3318',
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
		$url  = 'https://example.com/behind-an-unreadable-line-9047';
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
		$url  = 'https://example.com/behind-a-short-line-9047';
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
		$url  = 'https://example.com/wholly-inside-the-window-3352';
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
		$url  = 'https://example.com/column-lookalike-4417';
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
	}

	/**
	 * A rid lookup walks unbudgeted (MAX_SCAN_S bounds the URL walks alone),
	 * so a rid behind more lines than that budget covers is still found.
	 */
	public function test_the_rid_lookups_find_a_rid_past_max_scan_s(): void {
		$this->fill_request_index_past_the_budget();

		$found  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', 'rid-buried-under-the-budget' );
		$record = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-buried-under-the-budget' );

		$this->assertIsArray( $found, \is_string( $found ) ? $found : '' );
		$this->assertSame( 0, $found['partition'] );
		$this->assertIsArray( $record, \is_string( $record ) ? $record : '' );
		$this->assertSame( 'https://example.com/buried-under-the-budget', $record['url'] );
	}

	/** An unknown rid is a definite answer: every index line was read. */
	public function test_an_unknown_rid_is_not_found_however_long_the_walk(): void {
		$this->fill_request_index_past_the_budget();

		$searched = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'search_requests', 'rid-never-filed-6f21' );
		$dumped   = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-never-filed-8c04' );

		$this->assertSame( 'Request not found: rid=rid-never-filed-6f21', \trim( Core::as_string( $searched ) ) );
		$this->assertSame( 'Request not found: rid=rid-never-filed-8c04', \trim( Core::as_string( $dumped ) ) );
	}

	/**
	 * Bury p0's request index under more lines than the scan's clock allows.
	 */
	private function fill_request_index_past_the_budget(): void {
		// One real record first: a segment with no `.log` is no segment.
		$this->write_request( [
			'rid'         => 'rid-buried-under-the-budget',
			'url'         => 'https://example.com/buried-under-the-budget',
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

	public function test_dump_request_finds_a_flame_past_max_scan_s(): void {
		// Both walks are unbudgeted, so a flame behind a deep index is found.
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
		\file_put_contents(
			$this->tmp . '/logs/flames.p0/0.idx',
			\str_repeat( "x\n", ( Performance_CI_Node::MAX_SCAN_S + 3 ) * self::clock_stride() ),
			FILE_APPEND | LOCK_EX
		);
		$reads       = 0;
		Core::$clock = static function () use ( &$reads ): float {
			return (float) ++$reads;
		};

		$record = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', 'rid-deep-flame-73920184665021' );

		$this->assertSame( 'deep', $record['flame_data']['flame']['name'] ?? null );
	}

	public function test_dump_url_walks_any_number_of_lines_inside_the_time_cap(): void {
		// The cap is time, not lines: a stopped clock reads the whole window.
		$url  = 'https://example.com/under-a-deep-index';
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

	public function test_ask_url_brief_honours_the_server_scope(): void {
		// `pageFacts` stamps the active filters onto every surface it emits, so
		// a brief that answered site-wide would hand an agent unscoped numbers
		// labelled as one server's — a worse failure than not scoping at all,
		// because the label makes it quotable.
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => 'https://alpha.example/asked', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 500.0, 'last_seen' => 1700000003 ],
		], 'alpha.example' );
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => 'https://beta.example/asked', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 1300.0, 'last_seen' => 1700000003 ],
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
			'cccccccccccc' => [ 'url' => 'https://example.com/asked', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 1000.0, 'last_seen' => 1700000003 ],
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
			'cccccccccccc' => [ 'url' => 'https://alpha.example/mixed', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 260.0, 'last_seen' => 1700000003 ],
		], 'alpha.example' );
		$this->set_url_bucket( $store, $bucket, [
			'cccccccccccc' => [ 'url' => 'https://beta.example/mixed', 'count' => 7, 'timed_count' => 7, 'sum_ms' => 640.0, 'last_seen' => 1700000003 ],
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
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'a7a7a7a7a7a7' => [ 'url' => 'https://example.com/kea-timeouts', 'count' => 6, 'timed_count' => 0, 'errors' => 6, 'last_seen' => 1700000006 ],
		] );

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:a7a7a7a7a7a7' );

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
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$rows   = [
			'e4e4e4e4e4e4' => [ 'url' => 'https://example.com/hoiho-8812', 'count' => 5, 'timed_count' => 0, 'errors' => 5, 'max_ms' => 4321.0, 'max_peak_mb' => 3.5, 'last_seen' => 1700000007 ],
			'f5f5f5f5f5f5' => [ 'url' => 'https://example.com/kaka-1093', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 70.0, 'min_ms' => 30.0, 'max_ms' => 40.0, 'last_seen' => 1700000008 ],
		];
		$this->set_url_bucket( $store, $bucket, $rows );

		$dump  = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', 'e4e4e4e4e4e4' );
		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', 'url:e4e4e4e4e4e4' );

		$this->assertNull( $dump['stats']['max_ms'], 'dump_url' );
		$this->assertNull( $brief['stats']['max_ms'], 'the url: brief' );
		$this->assertNull( $brief['findings'][0]['metric']['max_ms'], 'its finding' );
	}

	public function test_dump_url_verb_returns_stats_and_default_flame(): void {
		$store  = $this->stats_store( 0, 86400 );
		$bucket = $this->current_url_bucket();
		$this->set_url_bucket( $store, $bucket, [
			'abc123def456' => [
				'url'       => 'https://example.com/articles/777',
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
				'url'       => 'https://example.com/x',
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
		$this->activate_shipped_with_stats_tables( 'performance', 3 );
		$hash = $this->seed_listed_url( 'https://example.com/summed-blobs', 5 );
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
		$url   = 'https://example.com/cold-flame';
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
		$this->activate_shipped_with_stats_tables( 'performance', 2 );
		$url   = 'https://example.com/cold-in-p1';
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
				'url'         => 'https://example.com/reviews/first',
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
				'url'       => 'https://example.com/x',
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
			'e71b04ac9d33' => [ 'url' => 'https://example.com/breakdown-only', 'count' => 6, 'timed_count' => 6, 'sum_ms' => 84.0, 'last_seen' => 1700006000 ],
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
			'b7731ce0fa11' => [ 'url' => 'https://example.com/wombat-7731', 'count' => 3, 'last_seen' => self::tick() ],
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
				'url'       => 'https://example.com/x',
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
			'd06e5a1b7c43' => [ 'url' => 'https://example.com/quokka-5a1b', 'count' => 4, 'timed_count' => 4, 'sum_ms' => 52.0, 'last_seen' => $old ],
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
			'abc123def456' => [ 'url' => 'https://example.com/x', 'count' => 1, 'timed_count' => 1, 'sum_ms' => 10.0, 'last_seen' => 1700001000 ],
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

	public function test_search_requests_spans_the_topologys_own_worker_count(): void {
		// Global num_partitions stays 1; performance runs 4. A rid living in p2 is
		// invisible to a reader that loops to the global.
		$this->activate_shipped_with_stats_tables( 'performance', 4 );
		$rid = $this->write_request(
			[
				'rid'            => 'rid-high-partition-000000000001',
				'url'            => 'https://example.com/deep',
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
		$this->activate_shipped_with_stats_tables( 'performance', 4 );
		$body = [
			'rid'            => 'rid-hash-order-0000000000000001',
			'url'            => 'https://example.com/hashed',
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
		$this->activate_shipped_with_stats_tables( 'performance', 4 );
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

	/**
	 * A malformed --partition casts to 0, so the verb answers for a partition
	 * the operator never named — and where the rid is absent, blames the rid.
	 */
	public function test_dump_request_verb_refuses_a_malformed_partition(): void {
		$rid = $this->write_request( [
			'rid'         => 'rid-malformed-partition-flag',
			'url'         => 'https://example.com/p0-only',
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

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}:0" );

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

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}:0" );

		$this->assertIsArray( $result );
		$this->assertSame( 'request', $result['subject'] );
		$this->assertSame( 'https://example.com/asked-about?rest_route=%2Fkea%2Fv4&view=4471', $result['url'], 'the URL the request fetched, not the per-path row' );
		$this->assertEquals( 120.0, $result['duration_ms'] );
	}

	/**
	 * A partition that is not a canonical decimal is refused: cast, `abc`
	 * named p0 and `-3` p-3, and the record was found anyway.
	 */
	#[DataProvider( 'non_canonical_partitions' )]
	public function test_ask_refuses_a_request_descriptor_whose_partition_is_not_canonical( string $partition, bool $as_context ): void {
		$rid = $this->write_request( [
			'rid'            => 'rid-ask-part-1234567890123456',
			'url'            => 'https://example.com/asked-partition',
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
		$store->set_url_names( [ self::SEED_SERVER => [ 'cafebabe9012' => 'https://example.com/asked-cat' ] ] );
		$this->set_url_stats( $store, 'cafebabe9012', [ 'profiles' => $this->stored_profiles() ] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:render', 'url:cafebabe9012' ] );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertSame( 'category', $result['subject'] );
		$this->assertSame( 'mean per request over 5 requests, every server', $result['scope'] );
		$this->assertSame( 'https://example.com/asked-cat', $result['url'] );
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
			'cafebabe7890' => [ 'url' => 'https://example.com/asked-stale', 'count' => 2, 'timed_count' => 2, 'sum_ms' => 20.0, 'last_seen' => 1700001000 ],
		] );
		$this->seed_site_timing( [ 'category' => 'wpdb', 'ms' => 40.5, 'calls' => 8, 'requests' => 4 ] );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->seed_request( [ 'profiled' => 1 ] );
		}

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', [ 'category:wpdb', 'url:cafebabe7890' ] );

		$this->assertIsArray( $result, \is_string( $result ) ? $result : '' );
		$this->assertSame( 'recent window', $result['scope'] );
		$this->assertEqualsWithDelta( 10.125, $result['avg_time_ms'], 1e-9 );
	}

	public function test_dump_url_serves_the_aggregate_profile_as_per_request_means(): void {
		// The modal's "Average breakdown across N requests" panel reads
		// `time` and `count` off each row and drops a row carrying neither, so
		// the stored sums have to be divided before they leave the verb.
		$store = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			'cafebabe2468' => [ 'url' => 'https://example.com/asked-panel', 'count' => 5, 'timed_count' => 5, 'sum_ms' => 500.0, 'last_seen' => 1700001000 ],
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
			'url'            => 'https://example.com/asked-under',
			'request_url'    => 'https://example.com/asked-under',
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
		$store->set_url_names( [ self::SEED_SERVER => [ 'cafebabe1357' => 'https://example.com/asked-half' ] ] );

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
		$hash  = Log_Manager::url_hash( 'https://example.com/asked-url' );
		$store = $this->stats_store( 0, 86400 );
		$this->set_url_bucket( $store, $this->current_url_bucket(), [
			$hash => [
				'url'       => 'https://example.com/asked-url',
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

	/**
	 * The flame builder on partition N writes flames.pN from requests.pN, so a
	 * rid's flame sits on its request's hashed partition, which is read first.
	 */
	public function test_the_flame_lookup_reads_the_hashed_partition_first(): void {
		$this->activate_shipped_with_stats_tables( 'performance', 4 );
		$count = \count( Bootstrap::node_dirs( 'requests:partition' ) );
		$this->assertSame( 4, $count );
		$this->assertSame( $count, \count( Bootstrap::node_dirs( 'flames:partition' ) ) );
		$rid = '';
		for ( $i = 0; '' === $rid; $i++ ) {
			$candidate = \sprintf( 'rid-kakapo-%06d', $i );
			if ( 0 !== Partition_Node::hash_to_partition( $candidate, $count ) ) {
				$rid = $candidate;
			}
		}
		$home = Partition_Node::hash_to_partition( $rid, $count );
		$this->write_request( [ 'rid' => $rid, 'url' => 'https://example.com/kakapo', 'timestamp' => 1700000611, 'duration_ms' => 19 ], $home );
		$this->write_flame( [ 'rid' => $rid, 'flame' => [ 'name' => 'stray', 'value' => 3, 'children' => [] ] ], 0 );
		$this->write_flame( [ 'rid' => $rid, 'flame' => [ 'name' => 'home', 'value' => 19, 'children' => [] ] ], $home );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_request', $rid );

		$this->assertSame( 'home', $result['flame_data']['flame']['name'] );
	}

	public function test_dump_request_verb_merges_flame_data_when_present(): void {
		// Rid must be ≤32 chars (fixed-width .idx field) so the lookup matches.
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

	public function test_ask_verb_declares_its_context_the_tail_of_the_descriptors(): void {
		// `ask <descriptor> [<context-descriptor>…]`: every positional after
		// the first binds to `context`, so the page's scope rides by name.
		$args = self::args_by_name( 'ask' );
		$this->assertSame( [ 'descriptor', 'context', 'server', 'search', 'errors_only', 'include_workers', 'bucket' ], \array_keys( $args ) );
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
			[ 'sort', 'order', 'limit', 'offset', 'search', 'server', 'errors_only', 'include_workers', 'bucket' ],
			\array_keys( $args )
		);
		$this->assertSame( 'string', $args['bucket']['type'] );
		$this->assertSame( '', $args['bucket']['default'] );
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
		$this->assertSame( [ 'hash', 'breakdown', 'server', 'categories', 'after', 'errors_only', 'bucket' ], \array_keys( $args ) );
		$this->assertSame( 'string', $args['bucket']['type'] );
		$this->assertFalse( $args['bucket']['required'] );
		$this->assertSame( 'string', $args['hash']['type'] );
		$this->assertTrue( $args['hash']['required'] );
		$this->assertFalse( $args['breakdown']['required'] );
		$this->assertSame( 'string', $args['server']['type'] );
		$this->assertFalse( $args['server']['required'] );
		$this->assertSame( 'bool', $args['categories']['type'] );
		$this->assertFalse( $args['categories']['required'] );
		$this->assertSame( 'json', $args['after']['type'] );
		$this->assertFalse( $args['after']['required'] );
		$this->assertSame( 'bool', $args['errors_only']['type'] );
		$this->assertFalse( $args['errors_only']['required'] );
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

	/** `urls`' description names every row stat that reads null untimed. */
	public function test_urls_description_names_each_null_untimed_stat(): void {
		$this->assertStringContainsString( 'its `avg_ms`, `min_ms` and `max_ms` are null', self::verbs_by_name()['urls']['description'] );
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

	/**
	 * The flame lookup fans across EVERY flame partition: the builder writes to
	 * whichever one it is wired into, so a flame for a p0 request can land in p2.
	 */
	public function test_dump_request_merges_a_flame_written_to_another_partition(): void {
		$this->activate_shipped_with_stats_tables( 'performance', 3 );
		$rid = $this->write_request( [
			'rid'            => 'rid-flame-elsewhere-00000000001',
			'url'            => 'https://example.com/flame-in-p2',
			'timestamp'      => 1700003100,
			'duration_ms'    => 27,
			'status_code'    => 200,
			'peak_mb'        => 4,
			'request_method' => 'GET',
		] );
		$this->write_flame(
			[
				'rid'      => $rid,
				'url_hash' => Log_Manager::url_hash( 'https://example.com/flame-in-p2' ),
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
		$this->activate_shipped_with_stats_tables( 'performance', 3 );
		$url   = 'https://example.com/spread-across-partitions';
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
	 * The window's read plan at `$now`, as a verb builds it at its entry.
	 *
	 * @return Read_Plan
	 */
	private static function window_plan( int $now ): array {
		/** @var Read_Plan */
		return ( new \ReflectionMethod( Performance_CI_Node::class, 'read_plan' ) )->invoke( null, $now );
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

	/**
	 * A later partition is asked only for the hashes still unnamed, and none
	 * once every one is named: each hash it asks for is a read for a name an
	 * earlier partition already gave.
	 */
	public function test_url_names_asks_each_later_store_only_for_what_is_unnamed(): void {
		$this->activate_shipped_with_stats_tables( 'performance', 3 );
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

	// -------------------------------------------------------------------------
	// The URL read's own record: what each verb's firehose lines say.
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// A five-minute bucket: `--bucket` narrows `urls`, `dump_url` and `ask` to
	// one slot the charts draw, however far behind the URL window it falls.
	// -------------------------------------------------------------------------

	/** The bucket the tests narrow to: slot 7 of its hour. */
	private const BUCKET = '2026-10-04-13-35';

	/**
	 * Pin the reply at 2026-10-05 09:12 UTC under a 12-hour window, so BUCKET
	 * is about 20 hours back: charted, and outside the URL window.
	 */
	private function at_the_bucket_clock(): Stats_Store {
		$this->use_base_dir( $this->tmp, [ 'num_partitions' => 1, 'min_lifetime' => 43200 ] );
		$this->activate_shipped_with_stats_tables( 'performance', 1 );
		Core::$now = (float) \gmmktime( 9, 12, 0, 10, 5, 2026 );
		return $this->stats_store( 0, 43200 );
	}

	/**
	 * File URLs' `url_row_h` slots for one hour of a server, their names, the
	 * hour's server index and each slot's bucket set: what an hour a chart
	 * still draws holds once its fine tier has expired.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $urls   URL => slot => named row.
	 * @param string                                       $server The server filing them.
	 * @param list<int>|null                               $filed  The slots whose bucket sets are filed; null files every one.
	 * @return array<string,string> URL => hash.
	 */
	private function seed_slots( Stats_Store $store, string $hour, array $urls, string $server = self::SEED_SERVER, ?array $filed = null ): array {
		$key    = Stats_Store::server_key( $server );
		$hashes = [];
		$sets   = [];
		$writes = [];
		foreach ( $urls as $url => $slots ) {
			$hash           = Log_Manager::url_hash( $url );
			$hashes[ $url ] = $hash;
			$writes[]       = [
				Stats_Store::url_row_parts( $key, $hash ),
				$hour,
				[
					Stats_Store::URL_ROW_PATH                => Stats_Store::row_path( $url, $server ),
					Stats_Store::url_row_family( false ) => \array_map( self::positional_url_row( ... ), $slots ),
				],
			];
			$store->set_url_names( [ $server => [ $hash => $url ] ] );
			$this->set_url_hour( $store, $hour, Stats_Store::url_shard( $hash, false ), [], $server );
			foreach ( $filed ?? \array_keys( $slots ) as $slot ) {
				$sets[ $slot ][] = $hash;
			}
		}
		$this->assertNotContains( false, $store->bucket_set_multi( $writes ) );
		foreach ( $sets as $slot => $in ) {
			$this->assertSame( [ true ], $store->add_url_buckets( [ [ Stats_Store::buckets_in_hour( $hour )[ $slot ], $key, $in ] ], self::tick() ) );
		}
		return $hashes;
	}

	/**
	 * Three URLs in hour 13: kea and weka in slot 7, BUCKET, with numbers
	 * apart from their slot 8, and tui in slot 8 alone. By the hour's sums
	 * kea leads; by BUCKET's, weka does.
	 *
	 * @return array<string,string> Name => hash.
	 */
	private function seed_the_bucket( Stats_Store $store ): array {
		$hashes = $this->seed_slots(
			$store,
			'2026-10-04-13',
			[
				'https://example.com/kea-4410'  => [
					7 => [ 'count' => 7, 'timed_count' => 6, 'sum_ms' => 420.0, 'sum_peak_mb' => 14.0, 'errors' => 1, 'min_ms' => 12.0, 'max_ms' => 133.0, 'max_peak_mb' => 3.5, 'last_seen' => 1791207390 ],
					8 => [ 'count' => 50, 'timed_count' => 50, 'sum_ms' => 9000.0, 'sum_peak_mb' => 100.0, 'errors' => 0, 'min_ms' => 4.0, 'max_ms' => 911.0, 'max_peak_mb' => 9.5, 'last_seen' => 1791207690 ],
				],
				'https://example.com/weka-2271' => [
					7 => [ 'count' => 11, 'timed_count' => 11, 'sum_ms' => 330.0, 'sum_peak_mb' => 22.0, 'errors' => 0, 'min_ms' => 9.0, 'max_ms' => 61.0, 'max_peak_mb' => 2.5, 'last_seen' => 1791207391 ],
					8 => [ 'count' => 4, 'timed_count' => 1, 'sum_ms' => 70.0, 'sum_peak_mb' => 8.0, 'errors' => 3, 'min_ms' => 70.0, 'max_ms' => 70.0, 'max_peak_mb' => 2.0, 'last_seen' => 1791207691 ],
				],
				'https://example.com/tui-6620'  => [
					8 => [ 'count' => 31, 'timed_count' => 31, 'sum_ms' => 310.0, 'sum_peak_mb' => 31.0, 'errors' => 0, 'min_ms' => 5.0, 'max_ms' => 41.0, 'max_peak_mb' => 1.5, 'last_seen' => 1791207692 ],
				],
			]
		);
		return [
			'kea'  => $hashes['https://example.com/kea-4410'],
			'weka' => $hashes['https://example.com/weka-2271'],
			'tui'  => $hashes['https://example.com/tui-6620'],
		];
	}

	/** The oldest slot a chart draws is a bucket the table can narrow to. */
	public function test_the_oldest_charted_slot_is_a_bucket(): void {
		$this->at_the_bucket_clock();

		$page = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--bucket=2026-10-04-09-15' );

		$this->assertIsArray( $page );
		$this->assertSame( '2026-10-04-09-15', $page['filters']['bucket'] );
	}

	/** A `url:` brief answers from the selection and carries its canonical spelling. */
	public function test_a_url_brief_answers_a_selection_and_carries_its_spelling(): void {
		$hashes = $this->seed_the_bucket( $this->at_the_bucket_clock() );
		$this->seed_kea_requests();

		$url      = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hashes['kea']} --bucket=2026-10-04-13-40,2026-10-04-13-30" );

		$this->assertSame( '2026-10-04-13-30,2026-10-04-13-40', $url['bucket'] );
		$this->assertSame( 50, $url['stats']['count'] );
		$this->assertEqualsCanonicalizing( [ 'rid-kea-early-0000000000000001', 'rid-kea-late-00000000000000003' ], \array_column( $url['worst_requests'], 'rid' ) );
		$this->assertSame( '2026-10-04-13-30,2026-10-04-13-40', $url['fetch'][0]['arguments']['bucket'] );
	}

	/**
	 * Every verb that reads a selection refuses a spelling naming a key
	 * that is no five-minute key, one in the future or one whose rows have
	 * aged out, a run ending before it starts, and more than 288 buckets,
	 * each saying which key or naming the limit.
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
				'aged out'         => [ '2026-10-03-00-00', 'bucket 2026-10-03-00-00 has aged out of the stored hours' ],
				'one key aged out' => [ '2026-10-03-00-00,2026-10-04-13-35', 'bucket 2026-10-03-00-00 has aged out of the stored hours' ],
				'one key off grid' => [ '2026-10-04-13-35,2026-10-04-13-41', 'bucket 2026-10-04-13-41 must be a five-minute key, Y-m-d-H-i in UTC' ],
				'reversed run'     => [ '2026-10-04-13-40..2026-10-04-13-35', 'bucket run 2026-10-04-13-40..2026-10-04-13-35 ends before it starts' ],
				'289 buckets'      => [ '2026-10-04-09-00..2026-10-05-09-00', 'bucket selection holds more than 288 buckets' ],
			] as $why => [ $bucket, $message ] ) {
				// Requests are kept seven days, so `urls` names no bucket aged out.
				if ( \str_contains( $why, 'aged out' ) && ( 'urls' === $verb || 'overview:site' === $args ) ) {
					continue;
				}
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

	/**
	 * Requests to kea finishing at 13:34:59, 13:37:00 and 13:40:00: only the
	 * middle one finishes inside BUCKET. Each finishes two seconds after it
	 * starts.
	 *
	 * @param array<string,string> $errors Finish time => error status.
	 */
	private function seed_kea_requests( array $errors = [] ): void {
		foreach ( [ '13:34:59' => 'rid-kea-early-0000000000000001', '13:37:00' => 'rid-kea-inside-000000000000002', '13:40:00' => 'rid-kea-late-00000000000000003', '13:38:30' => 'rid-kea-fatal-00000000000000004' ] as $finish => $rid ) {
			if ( '13:38:30' === $finish && [] === $errors ) {
				continue;
			}
			$this->write_request( [
				'rid'            => $rid,
				'url'            => 'https://example.com/kea-4410',
				'timestamp'      => (int) \strtotime( "2026-10-04 {$finish} UTC" ) - 2,
				'duration_ms'    => 2000,
				'status_code'    => isset( $errors[ $finish ] ) ? 500 : 200,
				'peak_mb'        => 5,
				'request_method' => 'GET',
				'error_status'   => $errors[ $finish ] ?? '-',
			] );
		}
	}

	/**
	 * `dump_url` under a bucket: the header is the slot, the list the
	 * requests finishing inside it, and the window it was drawn from opens
	 * at the bucket's start.
	 */
	public function test_a_bucketed_dump_url_answers_the_slot_and_the_requests_inside_it(): void {
		$hashes = $this->seed_the_bucket( $this->at_the_bucket_clock() );
		$this->seed_kea_requests();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hashes['kea'], '--bucket=' . self::BUCKET ] );

		$this->assertEquals( [ 7, 1, 70.0 ], [ $reply['stats']['count'], $reply['stats']['errors'], $reply['stats']['avg_ms'] ] );
		$this->assertEqualsWithDelta( 7 / 300, $reply['stats']['requests_per_second'], 1e-9 );
		$this->assertSame( [ 'rid-kea-inside-000000000000002' ], \array_column( $reply['requests'], 'rid' ) );
		$this->assertSame( \gmmktime( 13, 35, 0, 10, 4, 2026 ), $reply['requests_window_start'] );
		$this->assertArrayNotHasKey( 'filters', $reply, 'the reply envelope already echoes the arguments' );
	}

	/**
	 * Under a bucket the profile stays the whole URL's, so it carries the
	 * stored aggregate's request count, not the slot's.
	 */
	public function test_a_bucketed_dump_url_carries_the_stored_profiles_count(): void {
		$store  = $this->at_the_bucket_clock();
		$hashes = $this->seed_the_bucket( $store );
		$this->seed_kea_requests();
		$this->set_url_stats( $store, $hashes['kea'], [
			'profiles' => [ 'count' => 23, 'sum_req_time' => 920.0, 'categories' => [
				'render' => [ 'samples' => 23, 'sum_time' => 690.0, 'sum_count' => 46, 'entries' => [] ],
			] ],
		] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hashes['kea'], '--bucket=' . self::BUCKET ] );

		$this->assertSame( 7, $reply['stats']['count'], 'the header is the slot' );
		$this->assertSame( 23, $reply['aggregate_profiles']['count'], 'the profile is the URL' );
		$this->assertEqualsWithDelta( 30.0, $reply['aggregate_profiles']['categories']['render']['time'], 1e-6 );
	}

	/**
	 * A stored aggregate that folded no profiled request answers no profile,
	 * so the modal captions no breakdown over 0 requests above a listed one.
	 */
	public function test_a_bucketed_dump_url_serves_no_profile_over_no_profiled_request(): void {
		$store  = $this->at_the_bucket_clock();
		$hashes = $this->seed_the_bucket( $store );
		$this->seed_kea_requests();
		$this->set_url_stats( $store, $hashes['kea'], [
			'profiles'      => [ 'count' => 0, 'sum_req_time' => 0.0, 'categories' => [] ],
			'last_modified' => 1791207417,
		] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hashes['kea'], '--bucket=' . self::BUCKET ] );

		$this->assertSame( [ 'rid-kea-inside-000000000000002' ], \array_column( $reply['requests'], 'rid' ) );
		$this->assertNull( $reply['aggregate_profiles'] );
		$this->assertSame( 1791207417, $reply['last_modified'], 'the blob still answers its stamp' );
	}

	/** Errors Only and a bucket together list the errors inside the bucket. */
	public function test_a_bucketed_errors_only_dump_url_lists_the_buckets_errors(): void {
		$hashes = $this->seed_the_bucket( $this->at_the_bucket_clock() );
		$this->seed_kea_requests( [ '13:38:30' => 'F' ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hashes['kea'], '--errors_only=1', '--bucket=' . self::BUCKET ] );

		$this->assertSame( [ 'rid-kea-fatal-00000000000000004' ], \array_column( $reply['requests'], 'rid' ) );
	}

	/** A bucket's list is not the URL's traffic, so no flame is rebuilt from it. */
	public function test_a_bucketed_dump_url_rebuilds_no_flame_from_its_list(): void {
		[ $hash, $newest ] = $this->seed_a_cold_url_with_two_flames();
		$bucket            = Stats_Store::bucket_key( $newest );
		$this->set_url_bucket( $this->stats_store( 0, 86400 ), $bucket, [
			$hash => [ 'url' => 'https://example.com/cold-flame', 'count' => 1, 'timed_count' => 1, 'sum_ms' => 60.0, 'last_seen' => $newest ],
		] );

		$result = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, "--bucket={$bucket}" ] );

		$this->assertSame( [ 'rid-cold-b-8841902' ], \array_column( $result['requests'], 'rid' ) );
		$this->assertNull( $result['aggregate_flame'] );
	}

	/** A `url:` brief under a bucket is the slot's stats and the bucket's requests. */
	public function test_a_bucketed_url_ask_briefs_the_slot_and_its_requests(): void {
		$hashes = $this->seed_the_bucket( $this->at_the_bucket_clock() );
		$this->seed_kea_requests();

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hashes['kea']} --bucket=" . self::BUCKET );

		$this->assertSame( self::BUCKET, $brief['bucket'] );
		$this->assertSame( 7, $brief['stats']['count'] );
		$this->assertSame( [ 'rid-kea-inside-000000000000002' ], \array_column( $brief['worst_requests'], 'rid' ) );
		$this->assertSame( self::BUCKET, $brief['fetch'][0]['arguments']['bucket'] );
	}

	/**
	 * The modal's list places a request in a bucket as the builder files it,
	 * start plus duration rounded to the second: a request starting
	 * 13:39:59 that ran 700 ms lists under 13:40, not 13:35.
	 */
	public function test_a_request_lists_under_the_bucket_its_stats_were_filed_in(): void {
		$hashes = $this->seed_the_bucket( $this->at_the_bucket_clock() );
		$this->write_request( [
			'rid'            => 'rid-kea-edge-00000000000000005',
			'url'            => 'https://example.com/kea-4410',
			'timestamp'      => \gmmktime( 13, 39, 59, 10, 4, 2026 ),
			'duration_ms'    => 700,
			'status_code'    => 200,
			'peak_mb'        => 5,
			'request_method' => 'GET',
		] );

		$at_40 = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hashes['kea'], '--bucket=2026-10-04-13-40' ] );
		$at_35 = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hashes['kea'], '--bucket=' . self::BUCKET ] );

		$this->assertSame( [ 'rid-kea-edge-00000000000000005' ], \array_column( $at_40['requests'], 'rid' ) );
		$this->assertSame( [], $at_35['requests'] );
	}

	/**
	 * Kea in hour 08 of 2026-10-05, inside the 12-hour window, and in no
	 * slot of hour 13 the day before.
	 */
	private function seed_kea_in_the_window( Stats_Store $store ): string {
		return $this->seed_slots( $store, '2026-10-05-08', [
			'https://example.com/kea-4410' => [ 3 => [ 'count' => 9, 'timed_count' => 9, 'sum_ms' => 270.0, 'sum_peak_mb' => 18.0, 'min_ms' => 11.0, 'max_ms' => 61.0, 'max_peak_mb' => 2.5, 'last_seen' => 1791188100 ] ],
		] )['https://example.com/kea-4410'];
	}

	/**
	 * A URL the window holds but the bucket does not answers zero there:
	 * no requests, the means unmeasured, and an empty list.
	 */
	public function test_a_bucketed_dump_url_of_a_url_with_no_slot_there_answers_zero(): void {
		$hash = $this->seed_kea_in_the_window( $this->at_the_bucket_clock() );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, '--bucket=' . self::BUCKET ] );

		$this->assertIsArray( $reply, \is_string( $reply ) ? $reply : '' );
		$this->assertSame( 'https://example.com/kea-4410', $reply['stats']['url'] );
		$this->assertSame( [ 0, 0 ], [ $reply['stats']['count'], $reply['stats']['errors'] ] );
		$this->assertSame( [ null, null, null ], [ $reply['stats']['avg_ms'], $reply['stats']['avg_peak_mb'], $reply['stats']['min_ms'] ] );
		$this->assertEquals( 0, $reply['stats']['requests_per_second'] );
		$this->assertSame( [], $reply['requests'] );
	}

	/** A `url:` brief under a bucket the URL has no slot in briefs zero. */
	public function test_a_bucketed_url_ask_of_a_url_with_no_slot_there_briefs_zero(): void {
		$hash = $this->seed_kea_in_the_window( $this->at_the_bucket_clock() );

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hash} --bucket=" . self::BUCKET );

		$this->assertIsArray( $brief, \is_string( $brief ) ? $brief : '' );
		$this->assertSame( 0, $brief['stats']['count'] );
		$this->assertSame( [], $brief['worst_requests'] );
	}

	/** A hash no URL carries is not found, under a bucket or not. */
	public function test_a_bucketed_read_of_an_unknown_hash_is_not_found(): void {
		$this->seed_the_bucket( $this->at_the_bucket_clock() );

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

		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "request:{$rid}:0 --bucket=2026-10-01-00-00" );

		$this->assertIsArray( $brief, \is_string( $brief ) ? $brief : '' );
		$this->assertSame( 'request', $brief['subject'] );
	}

	/**
	 * A URL's row read under an unanswered server index is refused, never
	 * the zero a bucket the URL has no slot in answers.
	 */
	public function test_a_url_read_under_an_unanswered_index_is_refused_not_zero(): void {
		$store  = $this->at_the_bucket_clock();
		$hashes = $this->seed_the_bucket( $store );
		$this->seed_kea_in_the_window( $store );
		$this->refuse_stats_reads( '/^' . Stats_Store::NS_URLSRV_HOUR . ':2026-10-04-13$/' );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hashes['kea'], '--bucket=' . self::BUCKET ] );

		$this->assertIsString( $reply );
		$this->assertStringContainsString( "URL stats went unanswered: {$hashes['kea']} cannot be read now", $reply );
	}

	/**
	 * A URL row read the Table left unanswered is refused, under a bucket
	 * and under the window alike, never the zero or the `URL not found` a
	 * missing row answers.
	 */
	public function test_a_url_whose_row_read_went_unanswered_is_refused_not_zero(): void {
		$store  = $this->at_the_bucket_clock();
		$hashes = $this->seed_the_bucket( $store );
		$recent = $this->seed_kea_in_the_window( $store );
		$this->refuse_stats_reads( '/^' . Stats_Store::NS_URL_ROW_HOUR . ':/' );

		$bucketed = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hashes['kea'], '--bucket=' . self::BUCKET ] );
		$windowed = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $recent ] );

		$this->assertIsString( $bucketed );
		$this->assertStringContainsString( "URL stats went unanswered: {$hashes['kea']} cannot be read now", $bucketed );
		$this->assertIsString( $windowed );
		$this->assertStringContainsString( "URL stats went unanswered: {$recent} cannot be read now", $windowed );
	}

	/**
	 * A URL's stored name outlives the window, so a bucket older than the
	 * window that the URL has no slot in answers zero from the name: kea's
	 * only traffic, 20 hours back, under a 12-hour window, asked at a bucket
	 * 19 hours back.
	 */
	public function test_a_named_url_answers_zero_at_a_bucket_older_than_the_window(): void {
		$store = $this->at_the_bucket_clock();
		$hash  = $this->seed_slots( $store, '2026-10-04-13', [
			'https://example.com/kea-4410' => [ 2 => [ 'count' => 13, 'timed_count' => 13, 'sum_ms' => 390.0, 'sum_peak_mb' => 26.0, 'min_ms' => 17.0, 'max_ms' => 71.0, 'max_peak_mb' => 2.5, 'last_seen' => \gmmktime( 13, 12, 0, 10, 4, 2026 ) ] ],
		] )['https://example.com/kea-4410'];

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, '--bucket=2026-10-04-14-10' ] );

		$this->assertIsArray( $reply, \is_string( $reply ) ? $reply : '' );
		$this->assertSame( 'https://example.com/kea-4410', $reply['stats']['url'] );
		$this->assertSame( [ 0, 0, null, null ], [ $reply['stats']['count'], $reply['stats']['errors'], $reply['stats']['avg_ms'], $reply['stats']['max_ms'] ] );
	}

	/**
	 * A slot no timed request reached has no maximum: `max_ms` is null, as
	 * the means beside it are (decision 24).
	 */
	public function test_an_untimed_slot_has_a_null_max_ms(): void {
		$store = $this->at_the_bucket_clock();
		$hash  = $this->seed_slots( $store, '2026-10-04-13', [
			'https://example.com/moa-5521' => [ 7 => [ 'count' => 3, 'timed_count' => 0, 'errors' => 3, 'sum_peak_mb' => 6.0, 'max_peak_mb' => 2.5, 'last_seen' => 1791207390 ] ],
		] )['https://example.com/moa-5521'];

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hash, '--bucket=' . self::BUCKET ] );
		$brief = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'ask', "url:{$hash} --bucket=" . self::BUCKET );

		$this->assertIsArray( $reply, \is_string( $reply ) ? $reply : '' );
		$this->assertSame( [ 3, 3, null, 2.5 ], [ $reply['stats']['count'], $reply['stats']['errors'], $reply['stats']['max_ms'], $reply['stats']['max_peak_mb'] ] );
		$this->assertNull( $brief['stats']['max_ms'], 'the url: brief reads the same row' );
	}

	// -------------------------------------------------------------------------
	// A selection: `--bucket` takes several buckets as comma-separated runs,
	// `Stats_Store::bucket_spelling()`'s canonical form.
	// -------------------------------------------------------------------------

	/** BUCKET and the bucket after it, hour 13's slots 7 and 8. */
	private const PAIR = '2026-10-04-13-35..2026-10-04-13-40';

	/**
	 * Two buckets apart, 13:30 and 13:40: the modal's header folds the
	 * URL's slots in both, and its list keeps the requests finishing inside
	 * either, never the one in the bucket between, from the earlier start.
	 */
	public function test_a_selection_of_two_buckets_apart_lists_the_requests_inside_either(): void {
		$hashes = $this->seed_the_bucket( $this->at_the_bucket_clock() );
		$this->seed_kea_requests();

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'dump_url', [ $hashes['kea'], '--bucket=2026-10-04-13-30,2026-10-04-13-40' ] );

		$this->assertIsArray( $reply, \is_string( $reply ) ? $reply : '' );
		$this->assertSame( [ 'rid-kea-late-00000000000000003', 'rid-kea-early-0000000000000001' ], \array_column( $reply['requests'], 'rid' ) );
		$this->assertSame( 50, $reply['stats']['count'] );
		$this->assertEqualsWithDelta( 50 / 600, $reply['stats']['requests_per_second'], 1e-9 );
		$this->assertSame( \gmmktime( 13, 30, 0, 10, 4, 2026 ), $reply['requests_window_start'] );
	}

	/**
	 * A candidate's servers come out in the INDEX's order, the order the
	 * shard walk folds them in, whatever order the sets named them: the
	 * first server folded joins its path into the row's URL.
	 */
	public function test_a_candidates_servers_keep_the_index_order(): void {
		$moa   = Stats_Store::server_key( 'moa.example' );
		$kea   = Stats_Store::server_key( 'kea.example' );
		$index = [
			'2026-10-04-14-00' => [ $moa => [ 'moa.example', 3 ], $kea => [ 'kea.example', 5 ] ],
			'2026-10-04-13-40' => [ $kea => [ 'kea.example', 9 ], $moa => [ 'moa.example', 6 ] ],
			'2026-10-04-13-35' => [ $moa => [ 'moa.example', 1 ] ],
		];
		$named = ( new \ReflectionMethod( Performance_CI_Node::class, 'named_index' ) )->invoke( null, $index, [ $kea => [ '2026-10-04-14', '2026-10-04-13' ], $moa => [ '2026-10-04-14' ] ] );

		$this->assertSame(
			[
				'2026-10-04-14-00' => [ $moa => [ 'moa.example', 3 ], $kea => [ 'kea.example', 5 ] ],
				'2026-10-04-13-40' => [ $kea => [ 'kea.example', 9 ] ],
			],
			$named
		);
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
		$this->assertSame( [ 'totals', 'slots', 'servers', 'leaderboard' ], \array_keys( $reply ) );
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

		$this->assertSame( [ 'kea.example', 'weka.example', 'egret.example' ], $reply['servers'], 'a server filter leaves the list whole' );
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
		$this->assertArrayNotHasKey( 'errors', $reply['totals'], 'only an errors-only reply counts them' );
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
		$this->assertSame( [ 'data', 'rows', 'totals', 'slowest', 'filters', 'limit', 'offset' ], \array_keys( $reply ) );
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

	public function test_urls_errors_only_keeps_urls_with_timeouts_or_fatals(): void {
		$this->seed_request( [ 'url' => 'https://seed.example/a/', 'error' => 'T', 'ms' => null ] );
		$this->seed_request( [ 'url' => 'https://seed.example/a/', 'ms' => 30.0 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/b/', 'error' => 'F', 'ms' => 900.5, 'status' => 500 ] );
		$this->seed_request( [ 'url' => 'https://seed.example/c/', 'error' => 'A', 'ms' => null ] );
		$this->seed_request( [ 'url' => 'https://seed.example/c/', 'status' => 502, 'ms' => 75.0 ] );

		$reply = VerbHarness::fire( new Performance_CI_Node(), 'performance', 'urls', '--errors_only --sort=url --order=asc' );

		$rows = self::rows_by_url( $reply );
		$this->assertSame( [ 'https://seed.example/a/', 'https://seed.example/b/' ], \array_keys( $rows ), 'an abort and a 5xx are no errors' );
		$this->assertSame( [ 1, 1, 0, null ], [ $rows['https://seed.example/a/']['count'], $rows['https://seed.example/a/']['errors'], $rows['https://seed.example/a/']['timed_count'], $rows['https://seed.example/a/']['avg_ms'] ] );
		$this->assertSame( 900.5, $rows['https://seed.example/b/']['avg_ms'] );
		$this->assertSame( [ 'urls' => 2, 'requests' => 2, 'avg_ms' => 900.5, 'errors' => 2 ], \array_intersect_key( $reply['totals'], [ 'urls' => 1, 'requests' => 1, 'avg_ms' => 1, 'errors' => 1 ] ) );
		$this->assertTrue( $reply['filters']['errors_only'] );
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
			[ 'server' => 'alpha.example', 'search' => '', 'errors_only' => false, 'include_workers' => false, 'bucket' => '' ],
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

		$this->assertSame( [ 'requests' => 0, 'timed' => 0, 'avg_ms' => null, 'avg_peak_mb' => null, 'errors' => 0, 'urls' => 0 ], $overview['totals'] );
		$this->assertSame( [], $overview['servers'] );
		$this->assertSame( [ 'count' => 0, 'total_time' => null, 'avg_ms' => null, 'categories' => [] ], $overview['leaderboard'] );
		$this->assertSame( [], $urls['data'] );
		$this->assertSame( 0, $urls['rows'] );
		$this->assertSame( [ 'urls' => 0, 'requests' => 0, 'avg_ms' => null, 'avg_peak_mb' => null, 'requests_per_second' => 0 ], $urls['totals'], 'a whole rate crosses the wire as an integer' );
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
}
