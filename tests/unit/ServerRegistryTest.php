<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\ServerRegistry;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ServerRegistry::class )]
class ServerRegistryTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options'] = [];
	}

	public function test_register_then_get_round_trips_config(): void {
		$reg = new ServerRegistry();
		$reg->register( 'site-a', [ 'url' => 'https://a.example', 'token' => 'secret-1' ] );
		$config = $reg->get( 'site-a' );
		$this->assertSame( 'https://a.example', $config['url'] );
		$this->assertSame( 'secret-1', $config['token'] );
	}

	public function test_stored_token_is_encrypted_at_rest(): void {
		$reg = new ServerRegistry();
		$reg->register( 'site-a', [ 'url' => 'https://a.example', 'token' => 'plaintext-secret-12345' ] );

		// Dig into the option directly.
		$raw = get_option( 'newspack_nodes_servers' );
		$serialized = json_encode( $raw );
		$this->assertStringNotContainsString( 'plaintext-secret-12345', $serialized );
	}

	public function test_get_returns_null_for_unknown(): void {
		$reg = new ServerRegistry();
		$this->assertNull( $reg->get( 'nonexistent' ) );
	}

	public function test_list_servers_returns_names(): void {
		$reg = new ServerRegistry();
		$reg->register( 'a', [ 'url' => 'https://a', 'token' => 'x' ] );
		$reg->register( 'b', [ 'url' => 'https://b', 'token' => 'y' ] );
		$names = $reg->list_servers();
		sort( $names );
		$this->assertSame( [ 'a', 'b' ], $names );
	}

	public function test_remove_deletes_entry(): void {
		$reg = new ServerRegistry();
		$reg->register( 'a', [ 'url' => 'https://a', 'token' => 'x' ] );
		$reg->remove( 'a' );
		$this->assertNull( $reg->get( 'a' ) );
	}
}
