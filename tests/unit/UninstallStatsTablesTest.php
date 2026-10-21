<?php
/**
 * Uninstall removes the files of every Row_Table the plugin's shipped
 * topologies declare with the Flame_Facts schema, and only this install's.
 *
 * @package Newspack_Event_Logger_Nodes\Tests\Unit
 */

declare( strict_types = 1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Url_Snapshot;
use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FactsSeeder;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Spawn_Coordinator;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Topology_Registry;

require_once \dirname( __DIR__, 2 ) . '/includes/uninstall-cleanup.php';

final class UninstallStatsTablesTest extends TestCase {

	use FactsSeeder;

	/** Each case's own dirs leave the registry; the next setUp re-registers. */
	protected function tearDown(): void {
		Topology_Registry::reset();
		parent::tearDown();
	}

	public function test_uninstall_deletes_every_partition_file_of_the_shipped_facts_table(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-' ) );
		$doomed = self::partition_files( self::FACTS_TABLE );
		$kept   = [
			Table_Node::file( 'eln-rule-hooks', 0 ),
			Table_Node::file( 'kea-ledger-7713', 3 ),
		];
		self::write( ...$doomed, ...$kept );

		$deleted = \Newspack_Event_Logger_Nodes\delete_stats_tables( NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies' );

		$this->assertSame( 24, $deleted, 'three files for each of the eight partitions' );
		foreach ( $doomed as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
		foreach ( $kept as $file ) {
			$this->assertFileExists( $file, 'another Table\'s file stands' );
		}
	}

	/**
	 * Uninstall erases the published URL snapshot and a build in flight,
	 * both through the substrate, and counts them with the table's files.
	 */
	public function test_uninstall_deletes_the_url_snapshot_and_a_build_in_flight(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-job-' ) );
		$this->activate_shipped( 'stats-builder', 1 );
		$this->seed_request( [ 'url' => 'https://seed.example/kea-7561/' ] );
		$this->assertTrue( Row_Table_Node::build( Flame_Facts_Row_Schema::class, Url_Snapshot::NAME, static fn (): bool => true ) );
		$this->assertTrue( self::snapshot_published() );
		$standing = \count( \array_filter( self::partition_files( self::FACTS_TABLE ), \is_file( ... ) ) );
		$this->assertGreaterThan( 0, $standing, 'the seed wrote the table' );

		$deleted = null;
		Row_Table_Node::build(
			Flame_Facts_Row_Schema::class,
			Url_Snapshot::NAME,
			static function () use ( &$deleted ): bool {
				$deleted = \Newspack_Event_Logger_Nodes\delete_stats_tables( NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies' );
				return false;
			}
		);

		$this->assertSame( $standing + 2, $deleted, 'the table\'s files, the snapshot and the build' );
		$this->assertFalse( self::snapshot_published() );
		$this->assertSame( [], Row_Table_Node::remove_snapshot( Url_Snapshot::NAME ), 'no publish and no build stands' );
	}

	/**
	 * A snapshot that will not delete is said, as a file the unlink loop
	 * cannot delete is, and the uninstall goes on.
	 */
	public function test_uninstall_says_a_snapshot_that_will_not_delete_and_goes_on(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-stuck-' ) );
		$this->activate_shipped( 'stats-builder', 1 );
		$this->seed_request( [ 'url' => 'https://seed.example/kea-7562/' ] );
		Row_Table_Node::build( Flame_Facts_Row_Schema::class, Url_Snapshot::NAME, static fn (): bool => true );
		foreach ( self::partition_files( self::FACTS_TABLE ) as $file ) {
			\is_file( $file ) && \unlink( $file );
		}
		$tables = \dirname( $this->snapshot_file() );
		\chmod( $tables, 0555 );
		try {
			$deleted = \Newspack_Event_Logger_Nodes\delete_stats_tables( NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies' );
		} finally {
			\chmod( $tables, 0755 );
		}

		$this->assertSame( 0, $deleted );
		$this->assertTrue( self::snapshot_published(), 'the snapshot stands' );
		$this->assertNotEmpty(
			\array_filter( Core::$recent_log, static fn ( string $line ): bool => \str_contains( $line, 'uninstall: the URL snapshot did not delete' ) && \str_contains( $line, 'could not unlink' ) ),
			'the failure is said, naming the substrate\'s refusal'
		);
	}

	public function test_uninstall_finds_the_facts_table_by_schema_whatever_its_name(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-named-' ) );
		$shipped = $this->make_temp_dir( 'uninstall-stats-shipped-' );
		\file_put_contents( "{$shipped}/kea-perf-5512.tsl", "make_node Row_Table kea-facts-5512 Flame_Facts\nmake_node Table kea-cache-5512 kea:p{partition} 60 sqlite\n" );
		$doomed = self::partition_files( 'kea-facts-5512' );
		$kept   = [ Table_Node::file( 'kea-cache-5512', 0 ) ];
		self::write( ...$doomed, ...$kept );

		$deleted = \Newspack_Event_Logger_Nodes\delete_stats_tables( $shipped );

		$this->assertSame( 24, $deleted );
		foreach ( $doomed as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
		foreach ( $kept as $file ) {
			$this->assertFileExists( $file, 'a keyed Table stands' );
		}
	}

	/**
	 * Uninstall reads the plugin's own topologies alone: another plugin's
	 * broken `.tsl` cannot block it, and a table another plugin declares with
	 * this schema is for that plugin to clean up.
	 */
	public function test_uninstall_reads_its_own_topologies_and_no_others(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-foreign-' ) );
		$foreign = $this->make_temp_dir( 'uninstall-stats-foreign-dir-' );
		\file_put_contents( "{$foreign}/kea-broken-6612.tsl", "include kea-nowhere-6612\n" );
		\file_put_contents( "{$foreign}/kea-ledger-6612.tsl", "make_node Row_Table kea-foreign-6612 Flame_Facts\n" );
		Topology_Registry::register_stock_dir( $foreign );
		$doomed = self::partition_files( self::FACTS_TABLE );
		$kept   = [ Table_Node::file( 'kea-foreign-6612', 0 ), Table_Node::file( 'kea-foreign-6612', 5 ) ];
		self::write( ...$doomed, ...$kept );

		$deleted = \Newspack_Event_Logger_Nodes\delete_stats_tables( NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies' );

		$this->assertSame( 24, $deleted );
		foreach ( $doomed as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
		foreach ( $kept as $file ) {
			$this->assertFileExists( $file, 'another plugin\'s table is its own to clean up' );
		}
	}

	/**
	 * Each name the plugin ships is read from its own copy, though a stock dir
	 * registered after the plugin's ships the same name.
	 */
	public function test_uninstall_reads_its_own_copy_of_a_name_another_dir_ships(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-shadow-' ) );
		$shipped = $this->make_temp_dir( 'uninstall-stats-shadow-shipped-' );
		$later   = $this->make_temp_dir( 'uninstall-stats-shadow-later-' );
		\file_put_contents( "{$shipped}/kea-perf-6614.tsl", "make_node Row_Table kea-own-6614 Flame_Facts\n" );
		\file_put_contents( "{$later}/kea-perf-6614.tsl", "make_node Row_Table kea-later-6614 Flame_Facts\n" );
		Topology_Registry::register_stock_dir( $shipped );
		Topology_Registry::register_stock_dir( $later );
		$doomed = self::partition_files( 'kea-own-6614' );
		$kept   = [ Table_Node::file( 'kea-later-6614', 2 ) ];
		self::write( ...$doomed, ...$kept );

		$this->assertSame( 24, \Newspack_Event_Logger_Nodes\delete_stats_tables( $shipped ) );
		$this->assertFileDoesNotExist( $doomed[0] );
		$this->assertFileExists( $kept[0] );
	}

	/** The uninstall leaves the directory it read registered no longer. */
	public function test_uninstall_leaves_its_topologies_unregistered(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-unregistered-' ) );
		$shipped = $this->make_temp_dir( 'uninstall-stats-unregistered-shipped-' );
		\file_put_contents( "{$shipped}/kea-perf-6615.tsl", "make_node Row_Table kea-facts-6615 Flame_Facts\n" );

		\Newspack_Event_Logger_Nodes\delete_stats_tables( $shipped );

		$this->assertNull( Topology_Registry::resolve( 'kea-perf-6615' ) );
	}

	/** An operator's broken copy in the user dir is never read. */
	public function test_uninstall_ignores_a_broken_user_copy_of_its_topology(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-user-' ) );
		$shipped = $this->make_temp_dir( 'uninstall-stats-user-shipped-' );
		$user    = $this->make_temp_dir( 'uninstall-stats-user-dir-' );
		\file_put_contents( "{$shipped}/kea-perf-6616.tsl", "make_node Row_Table kea-facts-6616 Flame_Facts\n" );
		\file_put_contents( "{$user}/kea-perf-6616.tsl", "include kea-nowhere-6616\n" );
		Topology_Registry::register_user_dir( $user );
		$doomed = self::partition_files( 'kea-facts-6616' );
		self::write( ...$doomed );

		$this->assertSame( 24, \Newspack_Event_Logger_Nodes\delete_stats_tables( $shipped ) );
	}

	/** A broken topology the plugin ships still fails its uninstall loud. */
	public function test_uninstall_fails_loud_on_a_broken_topology_it_ships(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-broken-' ) );
		$shipped = $this->make_temp_dir( 'uninstall-stats-broken-shipped-' );
		\file_put_contents( "{$shipped}/kea-perf-6613.tsl", "include kea-gone-6613\n" );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'kea-gone-6613' );
		\Newspack_Event_Logger_Nodes\delete_stats_tables( $shipped );
	}

	public function test_uninstall_leaves_the_base_every_unconfigured_install_shares(): void {
		// Every unconfigured install shares the default base, and so its files.
		$default = \Newspack_Nodes\Settings_Schema::get()->defaults()['base_directory'];
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-default-' ) );
		\file_put_contents( \getenv( 'LOCAL_NEWSPACK_NODES_CONF' ), "<?php\nreturn [ 'base_directory' => '{$default}' ];\n" );
		\Newspack_Nodes\Config::reset();
		$file = Table_Node::file( self::FACTS_TABLE, 0 );
		$made = ! \is_file( $file );
		\is_dir( \dirname( $file ) ) || \mkdir( \dirname( $file ), 0700, true );
		\file_put_contents( $file, 'moa-3307', \FILE_APPEND );
		try {
			$this->assertSame( 0, \Newspack_Event_Logger_Nodes\delete_stats_tables( NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies' ) );
			$this->assertFileExists( $file );
		} finally {
			if ( $made ) {
				\unlink( $file );
			}
		}
	}

	/**
	 * A Row_Table's file and its WAL files on every partition a fleet runs.
	 *
	 * @return list<string>
	 */
	private static function partition_files( string $table ): array {
		$files = [];
		for ( $partition = 0; $partition < Spawn_Coordinator::MAX_PARTITIONS; $partition++ ) {
			$file = Table_Node::file( $table, $partition );
			\array_push( $files, $file, "{$file}-wal", "{$file}-shm" );
		}
		return $files;
	}

	private static function write( string ...$files ): void {
		foreach ( $files as $file ) {
			\is_dir( \dirname( $file ) ) || \mkdir( \dirname( $file ), 0700, true );
			\file_put_contents( $file, 'kea-7713' );
		}
	}
}
