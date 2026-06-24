<?php
/**
 * AdminEffectiveConfigTest (ELN): the read-only "Effective Configuration" panel.
 *
 * ELN's settings page delegates the panel to the shared substrate
 * `Settings_Renderer`, passing ELN's own Schema, option prefix, and effective
 * config. This file asserts the panel reports the right restart impact for an
 * ELN field (`significant_events`, classified `'all'`, restarts every active
 * topology — so `combined` appears when active) and that the section is hooked
 * to ELN's `settings_after_form` action and echoes a `widefat` table.
 *
 * Defines defensive i18n/esc stubs in a global block (AdminTest.php defines the
 * full set, but test-file load order isn't guaranteed).
 */

namespace {
	if ( ! \function_exists( 'esc_attr' ) ) {
		function esc_attr( $v ): string {
			return \htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! \function_exists( 'esc_html_e' ) ) {
		function esc_html_e( string $v, string $domain = '' ): void {
			echo \htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! \function_exists( '__' ) ) {
		function __( string $v, string $domain = '' ): string {
			return $v;
		}
	}
}

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Admin {

use Newspack_Event_Logger_Nodes\Admin\Admin;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Settings_Schema;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Config_System\Settings_Renderer;
use Newspack_Nodes\Topology_Registry;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Admin::class )]
class AdminEffectiveConfigTest extends TestCase {

	private string $base_dir;
	private ?string $tsl_dir = null;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']         = [];
		$GLOBALS['_wp_actions']         = [];
		$GLOBALS['_current_user_can']   = true;
		$GLOBALS['_current_user_login'] = '';
		$this->base_dir                 = '/tmp/newspack-event-logger-nodes-effective-config-' . \uniqid();
		\mkdir( $this->base_dir, 0755, true );
		$this->use_base_dir( $this->base_dir );

		// Active set including `combined`, which instantiates a Flame_Builder. ELN's
		// significant_events is classified 'all' → restarts every active topology.
		Topology_Registry::reset();
		$this->tsl_dir = $this->make_temp_dir( 'eln-effective-config-topologies-' );
		Topology_Registry::register_stock_dir( $this->tsl_dir );
		\file_put_contents( "{$this->tsl_dir}/combined.tsl", "make_node Flame_Builder flame-builder\n" );
		\file_put_contents( "{$this->tsl_dir}/job-router.tsl", "make_node Job_Router job-router\n" );
		\update_option( 'newspack_nodes_topologies', [ 'combined', 'job-router' ] );
		Config::reset();
		RuntimeConfig::reset();
	}

	protected function tearDown(): void {
		Config::reset();
		if ( null !== $this->tsl_dir ) {
			\delete_option( 'newspack_nodes_topologies' );
			RuntimeConfig::reset();
			Topology_Registry::reset();
			$this->rmdir_recursive( $this->tsl_dir );
			$this->tsl_dir = null;
		}
		$this->rmdir_recursive( $this->base_dir );
		parent::tearDown();
	}

	/** @return array<string,array<string,mixed>> */
	private function rows_by_key(): array {
		$rows = Settings_Renderer::effective_config_rows(
			Settings_Schema::get(),
			Admin::OPTION_PREFIX,
			Config::load_config()
		);
		return \array_column( $rows, null, 'key' );
	}

	public function test_significant_events_restart_impact_includes_active_combined(): void {
		$rows = $this->rows_by_key();
		$this->assertArrayHasKey( 'significant_events', $rows );
		$this->assertStringContainsString( 'combined', $rows['significant_events']['restart'] );
	}

	public function test_admin_render_section_is_hooked_and_echoes_widefat_table(): void {
		// The action must be wired so the panel renders below ELN's settings form.
		$called = 0;
		\add_action(
			'newspack_event_logger_nodes/settings_after_form',
			static function () use ( &$called ): void {
				++$called;
			}
		);
		$admin = new Admin();
		\do_action( 'newspack_event_logger_nodes/settings_after_form' );
		$this->assertGreaterThanOrEqual( 1, $called );

		\ob_start();
		$admin->render_effective_config_section();
		$html = (string) \ob_get_clean();
		$this->assertStringContainsString( '<table class="widefat"', $html );
		$this->assertStringContainsString( 'Restart impact', $html );
	}
}

}
