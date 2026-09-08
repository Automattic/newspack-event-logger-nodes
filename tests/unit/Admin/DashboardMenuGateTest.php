<?php
/**
 * The dashboard menu is gated by the substrate's `Capabilities::can( MANAGE )`,
 * which resolves the MANAGE role through the capability map and then narrows it
 * by the substrate's own `allowed_users` allowlist. This plugin declares neither
 * a list nor a gate of its own.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Admin;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Roles;

class DashboardMenuGateTest extends TestCase {

	private string $config_file = '';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_admin_menu_pages']       = [];
		$GLOBALS['_admin_submenu_pages']    = [];
		$GLOBALS['_current_user_can']       = true;
		$GLOBALS['_wp_test_current_user_can'] = [];
		\delete_option( Roles::OPTION );
		\delete_option( 'newspack_nodes_allowed_users' );
	}

	protected function tearDown(): void {
		if ( '' !== $this->config_file && \file_exists( $this->config_file ) ) {
			\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
			\unlink( $this->config_file );
		}
		$GLOBALS['_wp_test_current_user_can'] = [];
		\delete_option( Roles::OPTION );
		\delete_option( 'newspack_nodes_allowed_users' );
		Config::reset();
		parent::tearDown();
	}

	/**
	 * Seed an allowlist that names neither of the logins the tests use.
	 */
	private function restrict_to( string ...$logins ): void {
		$this->config_file = '/tmp/dashboard-gate-' . \uniqid() . '.php';
		\file_put_contents(
			$this->config_file,
			'<?php return [ "allowed_users" => ' . \var_export( $logins, true ) . ' ];'
		);
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $this->config_file );
		Config::reset();
	}

	/**
	 * @return list<string> Every menu slug the dashboards registered.
	 */
	private function registered_slugs(): array {
		$slugs = [];
		foreach ( (array) ( $GLOBALS['_admin_menu_pages'] ?? [] ) as $args ) {
			$slugs[] = (string) ( $args[3] ?? '' );
		}
		foreach ( (array) ( $GLOBALS['_admin_submenu_pages'] ?? [] ) as $args ) {
			$slugs[] = (string) ( $args[4] ?? '' );
		}
		return $slugs;
	}

	public function test_an_admin_outside_the_allowlist_gets_no_dashboard_menu(): void {
		// `manage_options` alone is not the gate the operator configured: the
		// config comment says "restrict admin UI to these usernames", and the
		// dashboards ARE the admin UI.
		$this->restrict_to( 'adminnewspack', 'dispatch' );
		$GLOBALS['_current_user_login'] = 'chris-newspack';

		\do_action( 'admin_menu' );

		$this->assertSame(
			[],
			\array_filter(
				$this->registered_slugs(),
				static fn ( string $slug ): bool => \str_starts_with( $slug, 'event-logger-' )
			),
			'A user outside allowed_users must see no Event Logger dashboard.'
		);
	}

	public function test_an_admin_on_the_allowlist_still_gets_every_dashboard(): void {
		$this->restrict_to( 'adminnewspack', 'dispatch' );
		$GLOBALS['_current_user_login'] = 'dispatch';

		\do_action( 'admin_menu' );

		$slugs = $this->registered_slugs();
		foreach ( [ 'event-logger-overview', 'event-logger-errors', 'event-logger-gyroscope', 'event-logger-requests' ] as $slug ) {
			$this->assertContains( $slug, $slugs );
		}
	}

	/**
	 * @return array<string,string> Dashboard slug => the capability it registered under.
	 */
	private function registered_capabilities(): array {
		$caps = [];
		foreach ( (array) ( $GLOBALS['_admin_menu_pages'] ?? [] ) as $args ) {
			$caps[ (string) ( $args[3] ?? '' ) ] = (string) ( $args[2] ?? '' );
		}
		foreach ( (array) ( $GLOBALS['_admin_submenu_pages'] ?? [] ) as $args ) {
			$caps[ (string) ( $args[4] ?? '' ) ] = (string) ( $args[3] ?? '' );
		}
		return $caps;
	}

	/**
	 * The bug the merge fixes. After `wp nodes caps install` the substrate
	 * resolves MANAGE to `newspack_nodes_manage`, and a user holding that and
	 * not `manage_options` is admitted everywhere the substrate gates — while
	 * this plugin asked for the WordPress capability by name and refused them.
	 */
	public function test_granular_manage_capability_reaches_the_dashboard_menu(): void {
		\update_option( Roles::OPTION, true );
		$GLOBALS['_wp_test_current_user_can'] = [
			Roles::CAP_MANAGE => true,
			'manage_options'  => false,
		];
		$GLOBALS['_current_user_login'] = 'granular-operator-8841';

		\do_action( 'admin_menu' );

		$caps = $this->registered_capabilities();
		foreach ( [ 'event-logger-overview', 'event-logger-errors', 'event-logger-gyroscope', 'event-logger-requests' ] as $slug ) {
			$this->assertArrayHasKey( $slug, $caps, "granular manage must reach {$slug}" );
			$this->assertSame( Roles::CAP_MANAGE, $caps[ $slug ] );
		}
	}

	/**
	 * The list this plugin now inherits is the SUBSTRATE's, so its own option
	 * row governs — the one ELN used to strip out of the merged config as a
	 * name collision.
	 */
	public function test_a_login_the_substrate_allowlist_excludes_gets_no_dashboard_menu(): void {
		\update_option( 'newspack_nodes_allowed_users', [ 'substrate-operator-9207' ] );
		$GLOBALS['_current_user_login'] = 'interloper-4416';
		Config::reset();

		\do_action( 'admin_menu' );

		$this->assertSame(
			[],
			\array_filter(
				$this->registered_slugs(),
				static fn ( string $slug ): bool => \str_starts_with( $slug, 'event-logger-' )
			),
			"A login outside the substrate's allowed_users must see no Event Logger dashboard."
		);
	}
}
