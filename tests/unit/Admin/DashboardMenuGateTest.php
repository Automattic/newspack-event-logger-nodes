<?php
/**
 * The `allowed_users` allowlist must gate the DASHBOARDS, not just Settings.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Admin;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

class DashboardMenuGateTest extends TestCase {

	private string $config_file = '';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_admin_menu_pages']    = [];
		$GLOBALS['_admin_submenu_pages'] = [];
		$GLOBALS['_current_user_can']    = true;
	}

	protected function tearDown(): void {
		if ( '' !== $this->config_file && \file_exists( $this->config_file ) ) {
			\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
			\unlink( $this->config_file );
		}
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
}
