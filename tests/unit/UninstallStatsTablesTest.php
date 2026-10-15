<?php
/**
 * Uninstall removes the flame-facts Row_Table's files, and only this
 * install's.
 *
 * @package Newspack_Event_Logger_Nodes\Tests\Unit
 */

declare( strict_types = 1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Flame_Facts_Row_Schema;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Spawn_Coordinator;
use Newspack_Nodes\Table_Node;

require_once \dirname( __DIR__, 2 ) . '/includes/uninstall-cleanup.php';

final class UninstallStatsTablesTest extends TestCase {

	public function test_uninstall_deletes_every_flame_facts_partition_file(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-' ) );
		$doomed = [];
		for ( $partition = 0; $partition < Spawn_Coordinator::MAX_PARTITIONS; $partition++ ) {
			$file = Table_Node::file( Flame_Facts_Row_Schema::TABLE, $partition );
			\array_push( $doomed, $file, "{$file}-wal", "{$file}-shm" );
		}
		$kept = [
			Table_Node::file( 'eln-rule-hooks', 0 ),
			Table_Node::file( 'kea-ledger-7713', 3 ),
		];
		foreach ( [ ...$doomed, ...$kept ] as $file ) {
			\is_dir( \dirname( $file ) ) || \mkdir( \dirname( $file ), 0700, true );
			\file_put_contents( $file, 'kea-7713' );
		}

		$deleted = \Newspack_Event_Logger_Nodes\delete_stats_tables();

		$this->assertSame( 24, $deleted, 'three files for each of the eight partitions' );
		foreach ( $doomed as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
		foreach ( $kept as $file ) {
			$this->assertFileExists( $file, 'another Table\'s file stands' );
		}
	}

	public function test_uninstall_leaves_the_base_every_unconfigured_install_shares(): void {
		// The default base is every unconfigured install's, and so its files.
		$default = \Newspack_Nodes\Settings_Schema::get()->defaults()['base_directory'];
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-stats-default-' ) );
		\file_put_contents( \getenv( 'LOCAL_NEWSPACK_NODES_CONF' ), "<?php\nreturn [ 'base_directory' => '{$default}' ];\n" );
		\Newspack_Nodes\Config::reset();
		$file = Table_Node::file( Flame_Facts_Row_Schema::TABLE, 0 );
		$made = ! \is_file( $file );
		\is_dir( \dirname( $file ) ) || \mkdir( \dirname( $file ), 0700, true );
		\file_put_contents( $file, 'moa-3307', \FILE_APPEND );
		try {
			$this->assertSame( 0, \Newspack_Event_Logger_Nodes\delete_stats_tables() );
			$this->assertFileExists( $file );
		} finally {
			if ( $made ) {
				\unlink( $file );
			}
		}
	}
}
