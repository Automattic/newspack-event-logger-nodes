<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\SettingsSync;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SettingsSync::class )]
class SettingsSyncTest extends TestCase {
	public function test_skips_when_enable_workers_unset(): void {
		$called = false;
		$sync = new SettingsSync(
			config: [ /* enable_workers absent */ ],
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertFalse( $called, 'fail-closed: missing enable_workers must skip sync' );
	}

	public function test_skips_when_enable_workers_false(): void {
		$called = false;
		$sync = new SettingsSync(
			config: [ 'enable_workers' => false ],
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertFalse( $called );
	}

	public function test_skips_when_enable_workers_truthy_but_not_true(): void {
		$called = false;
		$sync = new SettingsSync(
			config: [ 'enable_workers' => 1 ], // truthy but !== true
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertFalse( $called, 'strict === true required, not just truthy' );
	}

	public function test_syncs_when_enable_workers_strictly_true(): void {
		$received = null;
		$sync = new SettingsSync(
			config: [ 'enable_workers' => true ],
			synced_options: [ 'log_urls' ],
			dispatch: function ( $option, $value ) use ( &$received ) {
				$received = [ 'option' => $option, 'value' => $value ];
			}
		);
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertNotNull( $received );
		$this->assertSame( 'log_urls', $received['option'] );
		$this->assertSame( [ '/new' ], $received['value'] );
	}

	public function test_skips_unsynced_option(): void {
		$called = false;
		$sync = new SettingsSync(
			config: [ 'enable_workers' => true ],
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'unrelated', 'a', 'b' );
		$this->assertFalse( $called );
	}

	public function test_suppress_sync_blocks_sync(): void {
		$called = false;
		$sync = new SettingsSync(
			config: [ 'enable_workers' => true ],
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->suppress_sync( true );
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertFalse( $called );

		$sync->suppress_sync( false );
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertTrue( $called );
	}
}
