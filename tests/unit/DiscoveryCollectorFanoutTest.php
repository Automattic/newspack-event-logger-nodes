<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Discovery_Collector_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Command_Auth;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\HTTP_Out_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Vault;

/**
 * Discovery_Collector is the second minter on the hub's fan-out. Like
 * Settings_Sync it mints and signs one command per spoke, because a signature
 * under one spoke's key verifies only there — a Tee re-addressing the command
 * after the mint would produce something no spoke can verify.
 */
#[CoversClass( Discovery_Collector_Node::class )]
class DiscoveryCollectorFanoutTest extends TestCase {

	/** Distinct per spoke, so a signature under the wrong key is visible. */
	private const HANDLE_A = 'aaaa1111bbbb2222cccc3333dddd4444';
	private const HANDLE_B = 'eeee5555ffff6666aaaa7777bbbb8888';

	protected function setUp(): void {
		parent::setUp();
		\update_option(
			Vault::OPTION_KEY,
			[
				'tw0' => [ 'url' => 'https://tw0.example' ],
				'tw1' => [ 'url' => 'https://tw1.example' ],
			]
		);
		Vault::get_instance()->reset_cache();
	}

	protected function tearDown(): void {
		HTTP_Out_Node::$curl_dispatch = null;
		Command_Auth::forget_session( 'tw0' );
		Command_Auth::forget_session( 'tw1' );
		Command_Auth::forget_session( 'tw9' );
		Command_Auth::forget_session( 'lone' );
		Command_Auth::forget_session( 'aux' );
		Vault::get_instance()->reset_cache();
		parent::tearDown();
	}

	private function egress( string $node_name, string $vault_id ): void {
		$node = new HTTP_Out_Node();
		$node->name( $node_name );
		$node->arguments( [ $vault_id ] );
	}

	private function collector( Capture_Sink_Node $sink ): Discovery_Collector_Node {
		$sink->name( '_command_interpreter' );
		$node = new Discovery_Collector_Node();
		$node->name( 'discovery-collector' );
		$node->sink( $sink );
		return $node;
	}

	public function test_one_signed_discovery_probe_is_minted_per_spoke(): void {
		$this->egress( 'spokes:tw0', 'tw0' );
		$this->egress( 'spokes:tw1', 'tw1' );
		// Client-side session only: the minter signs, the verifier lives elsewhere.
		Command_Auth::remember_session( 'tw0', self::HANDLE_A, 'key-tw0-4242' );
		Command_Auth::remember_session( 'tw1', self::HANDLE_B, 'key-tw1-9999' );

		$sink = new Capture_Sink_Node();
		$node = $this->collector( $sink );
		$node->connect_node( 'spokes:tw0' );
		$node->connect_node( 'spokes:tw1' );

		$node->fire();

		$this->assertCount( 2, $sink->captured, 'one probe per spoke, not one to a Tee' );
		$this->assertSame( 'spokes:tw0/discovery', $sink->captured[0][ Message::TO ] );
		$this->assertSame( 'spokes:tw1/discovery', $sink->captured[1][ Message::TO ] );
		$this->assertSame( self::HANDLE_A, $sink->captured[0][ Message::VALUE ]['auth']['handle'] );
		$this->assertSame( self::HANDLE_B, $sink->captured[1][ Message::VALUE ]['auth']['handle'] );
	}

	public function test_a_spoke_without_a_session_is_skipped(): void {
		$this->egress( 'spokes:tw0', 'tw0' );
		$this->egress( 'spokes:tw1', 'tw1' );
		Command_Auth::remember_session( 'tw0', self::HANDLE_A, 'key-tw0-4242' );

		$sink = new Capture_Sink_Node();
		$node = $this->collector( $sink );
		$node->connect_node( 'spokes:tw0' );
		$node->connect_node( 'spokes:tw1' );

		$node->fire();

		$this->assertCount( 1, $sink->captured );
		$this->assertSame( 'spokes:tw0/discovery', $sink->captured[0][ Message::TO ] );
	}

	/**
	 * Same deadlock Settings_Sync had: a probe is this collector's only traffic to
	 * a spoke, so skipping for want of a session leaves nothing to ask for one.
	 * The target may also be a PATH, so the egress is resolved by its HEAD.
	 */
	public function test_a_path_form_target_without_a_session_kicks_the_handshake(): void {
		$this->egress( 'spokes:tw0', 'tw0' );
		$posts                        = 0;
		HTTP_Out_Node::$curl_dispatch = static function ( array $opts ) use ( &$posts ): \CurlHandle {
			++$posts;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
			return \curl_init();
		};

		$sink = new Capture_Sink_Node();
		$node = $this->collector( $sink );
		$node->connect_node( 'spokes:tw0/discovery' );

		$node->fire();

		$this->assertSame( [], $sink->captured, 'no session: nothing may be minted' );
		$this->assertGreaterThan( 0, $posts, 'the skip path must ask for a handshake' );
	}

	public function test_a_vanished_egress_node_is_pruned_from_the_fan_out(): void {
		$this->egress( 'spokes:tw0', 'tw0' );
		$doomed = new HTTP_Out_Node();
		$doomed->name( 'spokes:tw1' );
		$doomed->arguments( [ 'tw1' ] );
		// Client-side session only: the minter signs, the verifier lives elsewhere.
		Command_Auth::remember_session( 'tw0', self::HANDLE_A, 'key-tw0-4242' );
		Command_Auth::remember_session( 'tw1', self::HANDLE_B, 'key-tw1-9999' );

		$sink = new Capture_Sink_Node();
		$node = $this->collector( $sink );
		$node->connect_node( 'spokes:tw0' );
		$node->connect_node( 'spokes:tw1' );

		$doomed->remove_node();
		$node->fire();

		$this->assertCount( 1, $sink->captured );
		$this->assertSame( 'spokes:tw0/discovery', $sink->captured[0][ Message::TO ] );
	}

	/**
	 * Characterization test: a discovery-collector connected to a `Vault_Group`
	 * signs one probe per group member through the trait's own `egress_for()` —
	 * the substrate expands the group before the mint, and this plugin's copy
	 * of that method, since deleted, was byte-identical. Every non-member
	 * (`lone`, and `aux` in another group) carries a session too, so a leaked
	 * stray would emit its own probe rather than being silently skipped for
	 * want of one.
	 */
	public function test_a_vault_group_target_signs_one_probe_per_member(): void {
		\update_option(
			Vault::OPTION_KEY,
			[
				'tw0'  => [ 'url' => 'https://tw0.example', 'group' => 'tw-edge' ],
				'tw9'  => [ 'url' => 'https://tw9.example', 'group' => 'tw-edge' ],
				'lone' => [ 'url' => 'https://lone.example' ],
				'aux'  => [ 'url' => 'https://aux.example', 'group' => 'llm' ],
			]
		);
		Vault::get_instance()->reset_cache();
		( new Command_Interpreter_Node() )->make_node( 'Vault_Group', 'egress', 'HTTP_Out', 'tw-edge' );
		Command_Auth::remember_session( 'tw0', self::HANDLE_A, 'key-tw0-4242' );
		Command_Auth::remember_session( 'tw9', self::HANDLE_B, 'key-tw9-9999' );
		// Sessions on the non-members too: a leaked stray would otherwise be
		// silently skipped for want of one, hiding a broken group expansion.
		Command_Auth::remember_session( 'lone', 'ffff1111aaaa2222bbbb3333cccc4444', 'key-lone-1111' );
		Command_Auth::remember_session( 'aux', 'ffff9999aaaa8888bbbb7777cccc6666', 'key-aux-2222' );

		$sink = new Capture_Sink_Node();
		$node = $this->collector( $sink );
		$node->connect_node( 'egress' );

		$node->fire();

		$this->assertCount( 2, $sink->captured, 'one signed probe per group member, not per Vault entry' );
		$this->assertSame( 'egress:tw0/discovery', $sink->captured[0][ Message::TO ] );
		$this->assertSame( 'egress:tw9/discovery', $sink->captured[1][ Message::TO ] );
		$this->assertSame( self::HANDLE_A, $sink->captured[0][ Message::VALUE ]['auth']['handle'] );
		$this->assertSame( self::HANDLE_B, $sink->captured[1][ Message::VALUE ]['auth']['handle'] );
	}
}
