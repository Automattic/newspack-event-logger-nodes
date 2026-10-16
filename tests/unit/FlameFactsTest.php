<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Worker_Should_Stop;

/**
 * The flame builder's row writes: each completed request becomes one
 * `requests` row naming its URL and user agent, with its hourly and timing
 * rollups, written in one ROWS batch per flush to the `flame-facts`
 * Row_Table that `Flame_Facts_Row_Schema` declares.
 */
#[CoversClass( Flame_Facts_Row_Schema::class )]
#[CoversClass( Flame_Builder_Node::class )]
final class FlameFactsTest extends TestCase {
	use FactsSeeder;

	public function test_a_row_table_opens_every_declared_table_at_the_schema_version(): void {
		$this->armed_flame_builder();

		$opened = \array_merge( ...$this->facts( "SELECT name FROM sqlite_master WHERE type = 'table'" ) );

		$this->assertSame( [], \array_values( \array_diff( [ 'urls', 'agents', 'requests', 'timings', 'timings_site', 'requests_hourly', 'category_window', 'window_expiry', 'urls_fts' ], $opened ) ) );
		$this->assertSame( [ [ 4 ] ], $this->facts( 'PRAGMA user_version' ) );
	}

	public function test_v4_declares_the_window_its_expiries_and_the_covering_index(): void {
		$tables = Flame_Facts_Row_Schema::tables();

		$this->assertSame( 4, Flame_Facts_Row_Schema::version() );
		$this->assertSame( [ 'bucket', 'worker', 'url_id', 'ms', 'status', 'peak_mb' ], $tables['requests']['indexes']['requests_cover'] );
		$this->assertSame( [ 'category', 'entry', 'listener', 'worker', 'ms', 'calls', 'requests', 'last_bucket' ], \array_keys( $tables['category_window']['columns'] ) );
		$this->assertSame( [ 'category', 'entry', 'worker' ], $tables['category_window']['key'] );
		$this->assertSame( [ 'ms' => 'sum', 'calls' => 'sum', 'requests' => 'sum', 'last_bucket' => 'max' ], $tables['category_window']['add'] );
		$this->assertSame( [ 'last_bucket', Flame_Facts_Row_Schema::TIMINGS_BUCKETS, Bucket::SECONDS ], $tables['category_window']['retain'] );
		$this->assertArrayNotHasKey( 'window_watermark', $tables, 'the newest expiry is the watermark' );
		$this->assertSame( [ 'bucket' ], $tables['window_expiry']['key'] );
		$this->assertSame( 288, Flame_Facts_Row_Schema::WINDOW_BUCKETS );
		$this->assertArrayNotHasKey( 'requests_bucket', $tables['requests']['indexes'] );
	}

	public function test_a_window_row_folds_its_sums_and_keeps_its_latest_bucket(): void {
		$this->activate_shipped( 'flame-builder', 1 );
		$this->write_facts(
			0,
			[
				[ 'add', 'category_window', [ 'hook:kea_init', '', 0, 0, 17.5, 3, 1, 5_966_101 ] ],
				[ 'add', 'category_window', [ 'hook:kea_init', '', 0, 0, 4.25, 2, 1, 5_966_099 ] ],
			]
		);

		$rows = Row_Table_Node::read( Flame_Facts_Row_Schema::class, static fn ( Row_Reader $r ): array => $r->rows( 'SELECT ms, calls, requests, last_bucket FROM p0.category_window', [] ) );

		$this->assertSame( [ [ 21.75, 5, 2, 5_966_101 ] ], $rows );
	}

	public function test_one_request_writes_its_row_and_rollups(): void {
		$fb  = $this->armed_flame_builder();
		$url = 'https://kea.example/egret-6617/';
		$this->fill_request_at(
			$fb,
			$this->completed_request(
				[
					'rid'          => 'rid-3301',
					'url'          => $url,
					'status_code'  => 503,
					'duration_ms'  => 842.5,
					'peak_mb'      => 61,
					'country_code' => 'NZ',
					'user_agent'   => 'Takahe/4.2',
					'profiles'     => [ 'sql' => [ 'time' => 120.25, 'count' => 9, 'entries' => [ 'SELECT kea' => [ 80.5, 4 ] ] ] ],
				]
			),
			'81:4096:300'
		);
		$fb->flush();

		$this->assertSame( [ [ 'rid-3301', 503, 'NZ', 842.5, 61.0, 81, 4096, 300, 0, 'GET', null, 1 ] ], $this->facts( 'SELECT rid, status, country, ms, peak_mb, segment, "offset", length, worker, method, error, profiled FROM requests' ), 'a record with no error files none; its timing rows mark it profiled' );
		$this->assertSame( [ [ Log_Manager::url_hash( $url ), 'kea.example', '/egret-6617/' ] ], $this->facts( 'SELECT hash, server, path FROM urls' ) );
		$this->assertSame( [ [ 'Takahe/4.2' ] ], $this->facts( 'SELECT ua FROM agents' ) );
		$this->assertSame( [ [ 1 ] ], $this->facts( 'SELECT count(*) FROM requests r JOIN urls u ON u.id = r.url_id JOIN agents a ON a.id = r.ua_id' ), 'the row names its URL and agent by id' );
		$this->assertSame( [ [ 'sql', '', 120.25, 9, 1 ], [ 'sql', 'SELECT kea', 80.5, 4, 1 ] ], $this->facts( 'SELECT category, entry, ms, calls, requests FROM timings ORDER BY entry' ) );
		$this->assertSame( [ [ 5, 1, 1, 842.5, 842.5, 61.0, 0 ] ], $this->facts( 'SELECT status_class, count, timed, sum_ms, max_ms, sum_peak_mb, errors FROM requests_hourly' ), 'a 503 is no error: errors are T and F' );
		$this->assertSame( [ [ Bucket::hour( self::tick() ), 'kea.example', 'GET', 0, 'NZ' ] ], $this->facts( 'SELECT hour, server, method, worker, country FROM requests_hourly' ) );
	}

	/**
	 * A record carries what the builder files it under: its bucket, its
	 * partition, its error kind, and a NULL for each field it left empty.
	 */
	public function test_a_request_row_files_its_place_and_leaves_absent_fields_null(): void {
		$fb = $this->armed_flame_builder( '5' );
		$this->fill_request_at(
			$fb,
			$this->completed_request(
				[
					'rid'          => 'rid-9902',
					'url'          => 'https://kea.example/tui-5521',
					'status_code'  => null,
					'error_status' => 'T',
					'duration_ms'  => 30_000.0,
					'timestamp'    => self::tick() - 100,
					'country_code' => '',
					'user_agent'   => '',
					'ja4_hash'     => 't13d1516h2',
					'http_from'    => 'bot@kea.example',
					'peak_mb'      => -3,
				]
			),
			''
		);
		$fb->flush();

		$this->assertSame(
			[ [ Bucket::id( self::tick() - 70 ), (float) ( self::tick() - 70 ), self::tick() - 100, 5, null, null, null, null, null, 't13d1516h2', 'bot@kea.example', 'T', 0.0 ] ],
			$this->facts( 'SELECT bucket, finished_at, started_at, partition, segment, status, country, ua_id, ms, ja4, from_header, error, peak_mb FROM requests', '5' ),
			'a timeout keeps the start its record carries, though its duration is no sample'
		);
		$this->assertSame( [ [ 0, '', 1, 0, 0.0, 0.0, 1 ] ], $this->facts( 'SELECT status_class, country, count, timed, sum_ms, max_ms, errors FROM requests_hourly', '5' ), 'a timeout is counted, untimed, and an error' );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM agents', '5' ) );
	}

	/**
	 * A completion past the tick that brought the record is clamped to it;
	 * the start is the record's own.
	 */
	public function test_a_clamped_row_keeps_its_records_own_start(): void {
		$fb = $this->armed_flame_builder();
		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-6614', 'timestamp' => self::tick() - 17, 'duration_ms' => 58_400.0 ] ), '' );
		$fb->flush();

		$this->assertSame( [ [ (float) self::tick(), self::tick() - 17, 58_400.0 ] ], $this->facts( 'SELECT finished_at, started_at, ms FROM requests' ) );
	}

	/**
	 * A crash between a flush and the next checkpoint replays records whose
	 * rows already landed: their request rows are kept, and no rollup sums
	 * them twice.
	 */
	public function test_a_replay_of_flushed_records_adds_nothing(): void {
		$records = [
			'4:100:61' => $this->completed_request( [ 'rid' => 'rid-weka-1', 'url' => 'https://kea.example/weka', 'duration_ms' => 61.0, 'profiles' => [ 'sql' => [ 'time' => 7.5, 'count' => 3, 'entries' => [ 'SELECT weka' => [ 5.5, 2 ] ] ] ] ] ),
			'4:161:44' => $this->completed_request( [ 'rid' => 'rid-weka-2', 'url' => 'https://kea.example/weka', 'duration_ms' => 44.0, 'profiles' => [ 'sql' => [ 'time' => 2.25, 'count' => 1, 'entries' => [] ] ] ] ),
		];
		$first = $this->armed_flame_builder();
		foreach ( $records as $crumb => $record ) {
			$this->fill_request_at( $first, $record, $crumb );
		}
		$first->flush();
		$first->remove_node();

		$successor = $this->armed_flame_builder();
		foreach ( $records as $crumb => $record ) {
			$this->fill_request_at( $successor, $record, $crumb );
		}
		$successor->flush();

		$this->assertSame( [ [ 2 ] ], $this->facts( 'SELECT count(*) FROM requests' ) );
		$this->assertSame( [ [ 1 ] ], $this->facts( 'SELECT count(*) FROM urls' ) );
		$this->assertSame( [ [ '', 9.75, 4, 2 ], [ 'SELECT weka', 5.5, 2, 1 ] ], $this->facts( "SELECT entry, ms, calls, requests FROM timings WHERE category = 'sql' ORDER BY entry" ) );
		$this->assertSame( [ [ '', 9.75, 4, 2 ], [ 'SELECT weka', 5.5, 2, 1 ] ], $this->facts( "SELECT entry, ms, calls, requests FROM timings_site WHERE category = 'sql' ORDER BY entry" ) );
		$this->assertSame( [ [ 2, 2, 105.0, 61.0 ] ], $this->facts( 'SELECT count, timed, sum_ms, max_ms FROM requests_hourly' ) );
	}

	public function test_a_worker_request_files_worker_one_in_timings_site(): void {
		$fb = $this->armed_flame_builder();
		$this->fill_request_at( $fb, $this->completed_request( [ 'url' => 'https://kea.example/jobs/kiwi-7', 'is_worker' => true, 'duration_ms' => 912.0, 'profiles' => [ 'cron' => [ 'time' => 410.5, 'count' => 6, 'entries' => [] ] ] ] ), '' );
		$this->fill_request_at( $fb, $this->completed_request( [ 'url' => 'https://kea.example/kiwi-8', 'duration_ms' => 33.0, 'profiles' => [ 'cron' => [ 'time' => 1.5, 'count' => 2, 'entries' => [] ] ] ] ), '' );
		$fb->flush();

		$this->assertSame( [ [ 0, 'cron', 1.5, 2, 1 ], [ 1, 'cron', 410.5, 6, 1 ] ], $this->facts( 'SELECT worker, category, ms, calls, requests FROM timings_site ORDER BY worker' ) );
		$this->assertSame( [ [ 0 ], [ 1 ] ], $this->facts( 'SELECT worker FROM requests ORDER BY worker' ) );
		$this->assertSame( [ [ 0, 1 ], [ 1, 1 ] ], $this->facts( 'SELECT worker, count FROM requests_hourly ORDER BY worker' ) );
	}

	public function test_a_listener_category_is_marked(): void {
		$fb = $this->armed_flame_builder();
		$this->fill_request_at(
			$fb,
			$this->completed_request(
				[
					'url'      => 'https://kea.example/kakapo-3',
					'profiles' => [
						'save_post'                => [ 'time' => 14.0, 'count' => 2, 'entries' => [] ],
						'Kea_Hooks::save @10'      => [ 'time' => 9.0, 'count' => 2, 'entries' => [] ],
						'Kea_Hooks::rollback @-90' => [ 'time' => 3.0, 'count' => 1, 'entries' => [] ],
					],
				]
			),
			''
		);
		$fb->flush();

		$expected = [ [ 'Kea_Hooks::rollback @-90', 1 ], [ 'Kea_Hooks::save @10', 1 ], [ 'save_post', 0 ] ];
		$this->assertSame( $expected, $this->facts( 'SELECT category, listener FROM timings ORDER BY category' ) );
		$this->assertSame( $expected, $this->facts( 'SELECT category, listener FROM timings_site ORDER BY category' ) );
	}

	/** A profile category whose name is no string or whose data is no map files no timing row. */
	public function test_a_malformed_profile_category_files_no_timing_row(): void {
		$fb = $this->armed_flame_builder();
		$this->fill_request_at(
			$fb,
			$this->completed_request(
				[
					'url'      => 'https://kea.example/tieke-9',
					'profiles' => [
						'sql' => 'seven ms',
						41    => [ 'time' => 2.0, 'count' => 1, 'entries' => [] ],
						'http' => [ 'time' => 6.5, 'count' => 3, 'entries' => [] ],
					],
				]
			),
			''
		);
		$fb->flush();

		$this->assertSame( [ [ 'http', 6.5, 3 ] ], $this->facts( 'SELECT category, ms, calls FROM timings' ) );
	}

	/** An untimed request files its request row and its hour, and no timing. */
	public function test_an_untimed_request_files_no_timing_rows(): void {
		$fb = $this->armed_flame_builder();
		$this->fill_request_at( $fb, $this->completed_request( [ 'url' => 'https://kea.example/moa', 'error_status' => 'A', 'duration_ms' => 5_000.0, 'profiles' => [ 'sql' => [ 'time' => 4.0, 'count' => 1, 'entries' => [] ] ] ] ), '' );
		$fb->flush();

		$this->assertSame( [ [ null, 0 ] ], $this->facts( 'SELECT ms, profiled FROM requests' ), 'a profile on an untimed request profiles nothing' );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM timings' ) );
		$this->assertSame( [ [ 1, 0, 0 ] ], $this->facts( 'SELECT count, timed, errors FROM requests_hourly' ), 'an abort is no error' );
	}

	/** A timed request whose profile files no timing row is no profiled request. */
	public function test_a_timed_request_with_no_timing_rows_is_not_profiled(): void {
		$fb = $this->armed_flame_builder();
		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-hoiho-1', 'url' => 'https://kea.example/hoiho', 'duration_ms' => 73.5, 'profiles' => [] ] ), '' );
		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-hoiho-2', 'url' => 'https://kea.example/hoiho', 'duration_ms' => 81.5, 'profiles' => [ 'sql' => 'nine ms' ] ] ), '' );
		$fb->flush();

		$this->assertSame( [ [ 'rid-hoiho-1', 73.5, 0 ], [ 'rid-hoiho-2', 81.5, 0 ] ], $this->facts( 'SELECT rid, ms, profiled FROM requests ORDER BY rid' ) );
	}

	/**
	 * Requests to one URL from one agent in one batch name one `urls` row and
	 * one `agents` row, and the next batch names them again for itself.
	 */
	public function test_a_batch_names_each_url_and_agent_once(): void {
		$fb       = $this->armed_flame_builder();
		$recorder = \Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness::ask_recorder();
		$fb->sink( $recorder );
		$weka = [ 'url' => 'https://kea.example/weka-3302', 'user_agent' => 'Weka/2.7' ];
		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-weka-a' ] + $weka ), '' );
		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-weka-b' ] + $weka ), '' );
		$fb->flush();
		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-weka-c' ] + $weka ), '' );
		$fb->flush();

		$tables = \array_map( static fn ( array $asked ): array => \array_column( $asked['value']['ROWS'], 1 ), $recorder->asked );
		$this->assertSame( [ [ 'urls', 'agents', 'requests', 'requests' ], [ 'urls', 'agents', 'requests' ] ], $tables );
		$this->assertSame( [ [ 3, 1, 1 ] ], $this->facts( 'SELECT count(*), count( DISTINCT url_id ), count( DISTINCT ua_id ) FROM requests' ) );
	}

	/** A record with no rid could name no request row: it is refused before anything is buffered. */
	public function test_a_record_with_no_rid_is_refused(): void {
		$fb = $this->armed_flame_builder();

		try {
			$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => '', 'url' => 'https://kea.example/hoiho-41' ] ), '6:410:77' );
			$this->fail( 'the record is refused' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'no rid', $e->getMessage() );
		}
		$this->assertSame( [], $fb->save_state(), 'the checkpoint carries nothing' );
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM urls' ) );
	}

	/**
	 * Memory scales with ops, not requests: a batch whose requests carry wide
	 * profiles is written once its ops reach the budget, long before a
	 * thousand requests.
	 */
	public function test_a_batch_reaching_the_op_budget_writes_before_the_request_limit(): void {
		$fb      = $this->armed_flame_builder();
		$entries = [];
		for ( $e = 0; $e < 37; $e++ ) {
			$entries[ "SELECT kakariki {$e}" ] = [ 0.5, 1 ];
		}
		// Its URL, its request, its hour, and a category row and 37 entry rows thrice.
		$per  = 3 + 3 * 38;
		$need = (int) \ceil( Flame_Builder_Node::BUFFERED_OPS_LIMIT / $per );
		$fill = fn ( int $i ) => $this->fill_request_at( $fb, $this->completed_request( [ 'rid' => "rid-kakariki-{$i}", 'url' => "https://kea.example/kakariki-{$i}", 'user_agent' => '', 'profiles' => [ 'sql' => [ 'time' => 18.5, 'count' => 37, 'entries' => $entries ] ] ] ), '' );
		for ( $i = 1; $i < $need; $i++ ) {
			$fill( $i );
		}
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'nothing written below the budget' );

		$fill( $need );

		$this->assertLessThan( Flame_Builder_Node::BUFFERED_REQUESTS_LIMIT, $need );
		$this->assertSame( 20_000, Flame_Builder_Node::BUFFERED_OPS_LIMIT );
		$this->assertSame( [ [ $need ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'the record reaching the budget wrote the batch' );
	}

	public function test_a_thousand_buffered_requests_flush_before_the_timer(): void {
		$fb    = $this->armed_flame_builder();
		$limit = Flame_Builder_Node::BUFFERED_REQUESTS_LIMIT;
		for ( $i = 1; $i < $limit; $i++ ) {
			$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => "rid-ruru-{$i}", 'url' => "https://kea.example/ruru-{$i}", 'duration_ms' => 23.0 ] ), '' );
		}
		$this->assertSame( [ [ 0 ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'nothing written below the limit' );

		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => "rid-ruru-{$limit}", 'url' => "https://kea.example/ruru-{$limit}", 'duration_ms' => 23.0 ] ), '' );

		$this->assertSame( 1000, $limit );
		$this->assertSame( [ [ $limit ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'the record reaching the limit wrote the batch' );
	}

	public function test_the_router_tick_writes_at_the_flush_cadence(): void {
		$fb = $this->armed_flame_builder();
		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-hihi-1', 'duration_ms' => 17.0 ] ), '' );
		$this->tick_router( 0 );
		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-hihi-2', 'duration_ms' => 19.0 ] ), '' );
		$this->tick_router( Flame_Builder_Node::FLUSH_INTERVAL_SEC - 1 );
		$between = $this->facts( 'SELECT count(*) FROM requests' );
		$this->tick_router( 1 );

		$this->assertSame( [ [ 1 ] ], $between, 'a tick inside the interval writes nothing' );
		$this->assertSame( [ [ 2 ] ], $this->facts( 'SELECT count(*) FROM requests' ), 'the tick at the interval writes the rest' );
	}

	public function test_the_checkpoint_writes_the_rows_and_carries_nothing(): void {
		$fb = $this->armed_flame_builder();
		$this->fill_request_at( $fb, $this->completed_request( [ 'rid' => 'rid-pipipi' ] ), '9:7731:212' );

		$this->assertSame( [], $fb->save_state(), 'the checkpoint carries nothing' );
		$this->assertSame( [ [ 'rid-pipipi' ] ], $this->facts( 'SELECT rid FROM requests' ), 'the checkpoint wrote what it passed' );
	}

	/**
	 * A ROWS write the Row_Table refuses stops the worker plainly, carrying
	 * the refusal: the reader commits no checkpoint past the records the
	 * buffer held, and dead-letters none of them.
	 */
	public function test_a_failed_rows_write_stops_the_worker_without_committing(): void {
		$tmp   = $this->make_temp_dir( 'flame-facts-refused-' );
		$fb    = $this->armed_flame_builder();
		$topic = new \Newspack_Nodes\Topic_Node();
		$topic->arguments( [ "{$tmp}/requests.p{partition}", '1', '1048576' ] );
		foreach ( [ 'rid-toutouwai-1', 'rid-toutouwai-2' ] as $rid ) {
			$message                                   = \Newspack_Nodes\Message::new_message();
			$message[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_STRUCT;
			$message[ \Newspack_Nodes\Message::KEY ]   = $rid;
			$message[ \Newspack_Nodes\Message::VALUE ] = $this->completed_request( [ 'rid' => $rid, 'url' => 'https://kea.example/toutouwai', 'duration_ms' => 71.0 ] );
			$topic->fill( $message );
		}
		$topic->flush();
		$consumer = new \Newspack_Nodes\Consumer_Node();
		$consumer->arguments( [ "{$tmp}/requests.p0", "{$tmp}/offsets/fb/p0", "{$tmp}/deadletter/fb/p0" ] );
		$consumer->name( 'requests:consumer' );
		$consumer->sink( $fb );
		$consumer->add_snapshot_node( 'fb' );
		$this->break_facts();

		$thrown = null;
		try {
			$this->pump_consumer( $consumer );
			$consumer->checkpoint();
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, 'the refused write stops the worker' );
		$this->assertFalse( Worker_Should_Stop::is_clean( $thrown ), 'the reader must replay the records' );
		$this->assertStringContainsString( 'stats write failed', $thrown->getMessage() );
		$this->assertStringContainsString( 'requests', (string) $thrown->getPrevious()?->getMessage() );
		$this->assertLessThanOrEqual( 0, $this->read_private( $consumer, 'checkpoint_offset' ), 'no checkpoint commits past the records' );
		$this->assertSame( 0, $consumer->list_deadletter( 10 )['total'], 'nothing is dead-lettered' );
	}

	/**
	 * The significant-event signal is the average per call of a category
	 * over every non-worker request since the last flush, as the buffer holds
	 * them: one slow call among many fast ones proposes nothing until a
	 * flush empties the window.
	 */
	public function test_a_significant_event_comes_from_the_buffer(): void {
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_rules'] = [ [ 'id' => 'r-takahe', 'pattern' => '/', 'action' => 'log', 'auto_protect_time_threshold' => 0.2 ] ];
		$fb   = $this->armed_flame_builder();
		$fill = fn ( array $sql, bool $worker = false ) => $this->fill_request_at( $fb, $this->completed_request( [ 'rule_id' => 'r-takahe', 'is_worker' => $worker, 'profiles' => [ 'sql' => $sql + [ 'entries' => [] ] ] ] ), '' );

		$fill( [ 'time' => 0.1, 'count' => 20 ] );
		$fill( [ 'time' => 90.0, 'count' => 1 ], true );
		$fill( [ 'time' => 0.5, 'count' => 1 ] );
		$diluted = $fb->get_auto_tune_state()['add_significant_events'];
		$fb->flush();
		$fill( [ 'time' => 0.5, 'count' => 1 ] );

		$this->assertSame( [], $diluted, '0.6 ms over 21 calls averages under 0.2; the worker is not counted' );
		$this->assertSame( [ 'r-takahe' => [ 'sql' ] ], $fb->get_auto_tune_state()['add_significant_events'], 'a flush starts the window again' );
	}
}
