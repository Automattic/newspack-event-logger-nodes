<?php
/**
 * AdminTest: unit tests for the WP-Settings-API admin surface.
 *
 * The WP-Settings-API / admin-form / escaping stubs these tests drive live in
 * tests/bootstrap.php (global namespace, function_exists-guarded) so every
 * suite — including AdminEffectiveConfigTest run in isolation — sees the same
 * definitions. Per-file stub copies are forbidden: a test defining its own
 * hides a missing-stub failure from sibling suites.
 */

namespace {
	// Admin class is normally required by the main plugin file's deferred loader,
	// but that loader doesn't yet include includes/admin/class-admin.php — until
	// the main file is updated to wire it, require it here so this test runs.
	require_once \dirname( __DIR__, 3 ) . '/includes/admin/class-admin.php';
}

// -- Test class -------------------------------------------------------------

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Admin {

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Admin\Admin;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Tests\Helpers\RedirectException;
use Newspack_Event_Logger_Nodes\Tests\Helpers\TopologyLockHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Config_System\Field_Reset_Assets;
use Newspack_Nodes\Config_System\Reset_Gate;
use Newspack_Nodes\Lock_Node;

#[CoversClass( Admin::class )]
class AdminTest extends TestCase {
	use TopologyLockHarness;

	private string $base_dir;

	protected function setUp(): void {
		parent::setUp();
		// Reset state.
		$GLOBALS['_registered_settings'] = [];
		$GLOBALS['_registered_sections'] = [];
		$GLOBALS['_registered_fields']   = [];
		$GLOBALS['_options_pages']       = [];
		$GLOBALS['_wp_options']          = [];
		$GLOBALS['_wp_actions']          = [];
		$GLOBALS['_current_user_can']    = true;
		$GLOBALS['_current_user_login']  = '';
		$GLOBALS['_last_redirect']       = null;

		// Use /tmp directly to dodge realpath/symlink mismatches on hosts whose
		// sys_get_temp_dir() returns a symlinked path (e.g. macOS /var → /private/var).
		$this->base_dir = '/tmp/newspack-event-logger-nodes-admin-test-' . \uniqid();
		\mkdir( $this->base_dir, 0755, true );

		$this->use_base_dir( $this->base_dir );
	}

	protected function tearDown(): void {
		Config::reset();
		$this->reset_topology_fixtures();
		$this->rmdir_recursive( $this->base_dir );
		parent::tearDown();
	}

	// ---- register_settings ------------------------------------------------

	public function test_register_settings_registers_application_core_options(): void {
		$admin = new Admin();
		$admin->register_settings();

		// Substrate options (base_directory / partitioning / memcache_servers /
		// enable_workers / aggregator_servers) are NOT registered here — they
		// live on `\Newspack_Nodes\Admin\Admin`.
		$expected = [
			'newspack_event_logger_nodes_enable_logging',
			'newspack_event_logger_nodes_log_memory',
			'newspack_event_logger_nodes_flush_every_line',
		];
		foreach ( $expected as $option ) {
			$this->assertArrayHasKey( $option, $GLOBALS['_registered_settings'], "missing option: $option" );
			$this->assertSame(
				Admin::OPTIONS_GROUP,
				$GLOBALS['_registered_settings'][ $option ]['group'],
				"option $option must be registered under the canonical group"
			);
			$this->assertArrayHasKey(
				'sanitize_callback',
				$GLOBALS['_registered_settings'][ $option ]['args'],
				"option $option must have a sanitize_callback"
			);
		}
	}

	public function test_register_settings_does_not_register_substrate_options(): void {
		$admin = new Admin();
		$admin->register_settings();

		// Substrate-only — must NOT appear in the application's settings group.
		$substrate_options = [
			'newspack_event_logger_nodes_base_directory',
			'newspack_event_logger_nodes_num_partitions',
			'newspack_event_logger_nodes_num_segments',
			'newspack_event_logger_nodes_segment_size',
			'newspack_event_logger_nodes_max_lifespan',
			'newspack_event_logger_nodes_memcache_servers',
		];
		foreach ( $substrate_options as $option ) {
			$this->assertArrayNotHasKey(
				$option,
				$GLOBALS['_registered_settings'],
				"substrate option $option must not be owned by the application Admin"
			);
		}
	}

	public function test_register_settings_adds_general_section(): void {
		$admin = new Admin();
		$admin->register_settings();

		$this->assertArrayHasKey( 'newspack_event_logger_nodes_general_section', $GLOBALS['_registered_sections'] );

		// The surviving application settings fields (the seven ruleset-absorbed
		// fields were retired in Task 10). Storage fields (substrate) are NOT
		// asserted here.
		foreach ( [ 'enable_logging', 'log_memory', 'flush_every_line' ] as $field ) {
			$this->assertArrayHasKey( $field, $GLOBALS['_registered_fields'], "field $field not registered" );
			$this->assertSame( Admin::SETTINGS_PAGE, $GLOBALS['_registered_fields'][ $field ]['page'] );
		}
	}

	// ---- current_user_allowed --------------------------------------------

	public function test_current_user_allowed_requires_manage_options(): void {
		$GLOBALS['_current_user_can'] = false;
		$this->assertFalse( Admin::current_user_allowed() );
	}

	public function test_current_user_allowed_empty_whitelist_means_all_admins(): void {
		$GLOBALS['_current_user_can']   = true;
		$GLOBALS['_current_user_login'] = 'someone';

		// Empty allowed_users → allow.
		\add_filter(
			'newspack_event_logger_nodes_option_schema_core',
			function ( $schema ) {
				return $schema; // no-op
			}
		);
		Config::reset();
		$this->assertTrue( Admin::current_user_allowed() );
	}

	public function test_current_user_allowed_respects_allowed_users_whitelist(): void {
		$GLOBALS['_current_user_can'] = true;

		// Inject allowed_users via the LOCAL_NEWSPACK_NODES_CONF env override.
		$config_file = '/tmp/admin-test-conf-' . \uniqid() . '.php';
		\file_put_contents(
			$config_file,
			'<?php return [ "allowed_users" => [ "alice", "bob" ] ];'
		);
		\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . $config_file );
		Config::reset();

		try {
			// User alice is on the list → allowed.
			$GLOBALS['_current_user_login'] = 'alice';
			$this->assertTrue( Admin::current_user_allowed() );

			// User mallory is not → denied even though manage_options is true.
			$GLOBALS['_current_user_login'] = 'mallory';
			$this->assertFalse( Admin::current_user_allowed() );
		} finally {
			\putenv( 'LOCAL_NEWSPACK_NODES_CONF' );
			\unlink( $config_file );
		}
	}

	// ---- handle_reset_settings -------------------------------------------

	public function test_handle_reset_settings_rejects_missing_nonce(): void {
		$_POST = [];
		$admin = new Admin();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Security check failed' );
		$admin->handle_reset_settings();
	}

	public function test_handle_reset_settings_rejects_invalid_nonce(): void {
		$_POST = [ Admin::RESET_NONCE => 'wrong_value' ];
		$admin = new Admin();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Security check failed' );
		$admin->handle_reset_settings();
	}

	public function test_handle_reset_settings_rejects_unauthorized_user(): void {
		$_POST                          = [ Admin::RESET_NONCE => wp_create_nonce( Admin::RESET_ACTION ) ];
		$GLOBALS['_current_user_can']   = false;
		$admin                          = new Admin();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'You do not have permission' );
		$admin->handle_reset_settings();
	}

	public function test_handle_reset_settings_clears_options_and_redirects(): void {
		$_POST = [ Admin::RESET_NONCE => wp_create_nonce( Admin::RESET_ACTION ) ];

		// Seed application-level options. Substrate options (e.g.
		// num_partitions) are now owned by `\Newspack_Nodes\Admin` and reset
		// via its own handler.
		\update_option( 'newspack_event_logger_nodes_enable_logging', 0 );
		\update_option( 'newspack_event_logger_nodes_log_memory', 1 );
		\update_option( 'unrelated_option', 'survives' );

		$admin = new Admin();
		try {
			$admin->handle_reset_settings();
			$this->fail( 'expected RedirectException from wp_safe_redirect()' );
		} catch ( RedirectException $e ) {
			// Expected — the handler completes via redirect.
		}

		$this->assertFalse( \get_option( 'newspack_event_logger_nodes_enable_logging' ) );
		$this->assertFalse( \get_option( 'newspack_event_logger_nodes_log_memory' ) );
		$this->assertSame( 'survives', \get_option( 'unrelated_option' ) ); // unrelated options untouched
		$this->assertNotNull( $GLOBALS['_last_redirect'] );
		$this->assertStringContainsString( Admin::MENU_SLUG, $GLOBALS['_last_redirect'] );
		$this->assertStringContainsString( 'reset=1', $GLOBALS['_last_redirect'] );
	}

	// ---- maybe_request_worker_restart ------------------------------------

	public function test_maybe_request_worker_restart_no_op_for_unrelated_option(): void {
		$admin = new Admin();
		$admin->maybe_request_worker_restart( 'completely_unrelated_option' );

		// No lock dir created — nothing happened.
		$this->assertFalse( \is_dir( $this->base_dir . '/locks' ) );
	}

	public function test_maybe_request_worker_restart_no_op_for_no_impact_options(): void {
		$this->register_topologies();
		$this->prepare_lock_dir( 'combined', 0 );
		$admin = new Admin();
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_log_urls' );
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_skip_urls' );

		// log_urls / skip_urls are read per-request in the web process — no
		// long-lived worker caches them, so nothing restarts.
		$this->assertRestartNotFlagged( 'combined', 0 );
	}

	public function test_saving_a_restartless_setting_still_asks_every_worker_to_re_read(): void {
		// log_urls restarts nothing, and a running worker's option cache is
		// frozen at boot — so with no reload signal the new value waits out a
		// whole ~595s worker lifetime instead of landing on the next 15s window.
		$this->register_topologies();
		$this->prepare_lock_dir( 'combined', 0 );
		$this->prepare_lock_dir( 'aggregator', 0 );

		( new Admin() )->maybe_request_worker_restart( 'newspack_event_logger_nodes_log_urls' );

		$this->assertFileExists( $this->base_dir . '/locks/combined.p0.lock.d/' . Lock_Node::RELOAD_FLAG );
		$this->assertFileExists( $this->base_dir . '/locks/aggregator.p0.lock.d/' . Lock_Node::RELOAD_FLAG );
		$this->assertRestartNotFlagged( 'combined', 0 );
	}

	public function test_maybe_request_worker_restart_supervisor_only_enable_logging_restarts_all_live_topologies(): void {
		$this->register_topologies();
		$this->prepare_lock_dir( 'combined', 0 );
		$this->prepare_lock_dir( 'aggregator', 0 );
		$admin = new Admin();
		// enable_logging is cached in the Log_Manager per-process singleton,
		// which every long-lived worker holds → 'all' → restart every live topology.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_enable_logging' );
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartFlagged( 'aggregator', 0 );
	}

	public function test_maybe_request_worker_restart_all_class_restarts_all_live_topologies(): void {
		$this->register_topologies();
		$this->prepare_lock_dir( 'combined', 0 );
		$this->prepare_lock_dir( 'aggregator', 0 );
		// Phantom worker-group lock dir that matches NO live topology — the bug
		// touched this and silently restarted nothing real.
		$this->prepare_lock_dir( 'request-workers', 0 );

		$admin = new Admin();
		// log_memory is cached in the Log_Manager per-process singleton (every
		// worker) → 'all' → restart every live topology, combined included.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_log_memory' );

		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartFlagged( 'aggregator', 0 );
		// The phantom worker-group dir must NOT be touched.
		$this->assertFileDoesNotExist( $this->base_dir . '/locks/request-workers.p0.lock.d/' . Lock_Node::RESTART_FLAG );
	}

	public function test_maybe_request_worker_restart_iterates_all_partitions(): void {
		// Force num_partitions=4 via the substrate WP option (substrate owns
		// num_partitions). The flame-builder topology fans out over every partition.
		$this->register_topologies();
		\update_option( 'newspack_nodes_num_partitions', 4 );
		Config::reset();
		RuntimeConfig::reset();
		for ( $p = 0; $p < 4; $p++ ) {
			$this->prepare_lock_dir( 'flame-builder', $p );
		}

		$admin = new Admin();
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_log_memory' );

		for ( $p = 0; $p < 4; $p++ ) {
			$this->assertRestartFlagged( 'flame-builder', $p );
		}
	}

	// ---- render_settings_page --------------------------------------------

	public function test_render_settings_page_outputs_settings_fields_markup(): void {
		$admin = new Admin();
		$admin->register_settings();

		\ob_start();
		$admin->render_settings_page();
		$html = \ob_get_clean();

		// Settings-API plumbing rendered.
		$this->assertStringContainsString( 'option_page', $html );
		$this->assertStringContainsString( Admin::OPTIONS_GROUP, $html );
		$this->assertStringContainsString( 'do_settings_sections:' . Admin::SETTINGS_PAGE, $html );

		// Reset form is wired.
		$this->assertStringContainsString( Admin::RESET_ACTION, $html );
		$this->assertStringContainsString( Admin::RESET_NONCE, $html );

		// Submit + reset buttons present.
		$this->assertStringContainsString( '<input type="submit"', $html );

		// Per-field reset toggle highlight style is injected on the page.
		$this->assertStringContainsString( '.is-marked [data-nn-reset-toggle]', $html );
	}

	public function test_render_settings_page_shows_flush_success_notice(): void {
		// The `?flushed=1&restarted=N` query params render the "Cache flushed"
		// admin notice with the pluralized worker-restart count.
		$_GET['flushed']   = '1';
		$_GET['restarted'] = '3';
		try {
			$admin = new Admin();
			$admin->register_settings();
			\ob_start();
			$admin->render_settings_page();
			$html = \ob_get_clean();
		} finally {
			unset( $_GET['flushed'], $_GET['restarted'] );
		}

		$this->assertStringContainsString( 'notice notice-success', $html );
		$this->assertStringContainsString( 'Cache flushed.', $html );
		// restarted=3 → plural form ("workers"), with the count substituted.
		$this->assertStringContainsString( '3 workers restart requested', $html );
	}

	public function test_render_settings_page_flush_notice_defaults_restarted_to_zero(): void {
		// `flushed` present but `restarted` absent → the count coerces to 0
		// (plural branch of the notice), proving the is_numeric guard fallback.
		$_GET['flushed'] = '1';
		try {
			$admin = new Admin();
			$admin->register_settings();
			\ob_start();
			$admin->render_settings_page();
			$html = \ob_get_clean();
		} finally {
			unset( $_GET['flushed'] );
		}

		$this->assertStringContainsString( 'Cache flushed.', $html );
		$this->assertStringContainsString( '0 workers restart requested', $html );
	}

	public function test_render_settings_page_shows_reset_success_notice(): void {
		// The `?reset=1` query param renders the "Settings reset to defaults" notice.
		$_GET['reset'] = '1';
		try {
			$admin = new Admin();
			$admin->register_settings();
			\ob_start();
			$admin->render_settings_page();
			$html = \ob_get_clean();
		} finally {
			unset( $_GET['reset'] );
		}

		$this->assertStringContainsString( 'notice notice-success', $html );
		$this->assertStringContainsString( 'Settings reset to defaults.', $html );
	}

	public function test_render_settings_page_wrap_has_exact_standalone_product_root_classes(): void {
		$admin = new Admin();
		$admin->register_settings();

		\ob_start();
		$admin->render_settings_page();
		$html = \ob_get_clean();

		$this->assertSame(
			1,
			\preg_match( '/<div class="([^"]*event-logger-settings-wrap[^"]*)">/', $html, $matches )
		);
		$this->assertSame(
			'wrap event-logger-settings-wrap newspack-nodes-theme newspack-nodes-ui',
			$matches[1]
		);
	}

	public function test_render_settings_page_fires_settings_after_form_action(): void {
		$called = 0;
		\add_action(
			'newspack_event_logger_nodes/settings_after_form',
			function () use ( &$called ) {
				++$called;
			}
		);

		$admin = new Admin();
		$admin->register_settings();
		\ob_start();
		$admin->render_settings_page();
		\ob_end_clean();

		$this->assertSame( 1, $called, 'settings_after_form action must fire once per render' );
	}

	public function test_render_settings_page_blocks_unauthorized_user(): void {
		$GLOBALS['_current_user_can'] = false;
		$admin                        = new Admin();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'You do not have permission' );
		$admin->render_settings_page();
	}

	public function test_add_admin_menu_registers_options_page(): void {
		$admin = new Admin();
		$admin->add_admin_menu();
		$this->assertArrayHasKey( Admin::MENU_SLUG, $GLOBALS['_options_pages'] );
		$this->assertSame( 'manage_options', $GLOBALS['_options_pages'][ Admin::MENU_SLUG ]['capability'] );
	}

	public function test_add_admin_menu_skips_unauthorized_user(): void {
		$GLOBALS['_current_user_can'] = false;
		$admin                        = new Admin();
		$admin->add_admin_menu();
		$this->assertArrayNotHasKey( Admin::MENU_SLUG, $GLOBALS['_options_pages'] );
	}

	// ---- Rules editor mount ----------------------------------------------

	public function test_render_rules_editor_section_outputs_react_container(): void {
		$admin = new Admin();
		\ob_start();
		$admin->render_rules_editor_section();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'id="event-logger-rules-editor"', $out );
		$this->assertStringContainsString( 'Logging Rules', $out );
	}

	public function test_render_settings_page_renders_rules_editor_container(): void {
		$admin = new Admin();
		$admin->register_settings();
		\ob_start();
		$admin->render_settings_page();
		$html = \ob_get_clean();
		// The rules editor container must land on the settings page so the
		// `settings` React bundle can mount RulesAdmin into it.
		$this->assertStringContainsString( 'id="event-logger-rules-editor"', $html );
	}

	// ---- Section callbacks (output structured help text) ------------------

	public function test_general_section_callback_renders_explainer(): void {
		$admin = new Admin();
		\ob_start();
		$admin->general_section_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( '<p>', $out );
		$this->assertStringContainsString( 'logging', $out );
	}

	public function test_instrumentation_section_callback_mentions_filters_and_events(): void {
		$admin = new Admin();
		\ob_start();
		$admin->instrumentation_section_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( '<p>', $out );
		$this->assertStringContainsString( 'URL', $out );
	}

	public function test_workers_section_callback_describes_workers(): void {
		$admin = new Admin();
		\ob_start();
		$admin->workers_section_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( '<p>', $out );
	}

	public function test_debugging_section_callback_warns_about_overhead(): void {
		$admin = new Admin();
		\ob_start();
		$admin->debugging_section_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( '<p>', $out );
	}

	// ---- Field callbacks: enable_logging / enable_jobs ----------------------

	public function test_enable_logging_callback_renders_checkbox_with_default(): void {
		$admin = new Admin();
		// No option set → default is 1 (checked).
		\ob_start();
		$admin->enable_logging_callback();
		$out = \ob_get_clean();
		// Hidden zero-value sentinel + main checkbox.
		$this->assertStringContainsString( 'name="newspack_event_logger_nodes_enable_logging"', $out );
		$this->assertStringContainsString( 'type="hidden"', $out );
		$this->assertStringContainsString( 'type="checkbox"', $out );
		$this->assertStringContainsString( 'checked="checked"', $out );
		// Per-field reset toggle wraps the checkbox.
		$this->assertStringContainsString( 'data-nn-reset="newspack_event_logger_nodes_reset[newspack_event_logger_nodes_enable_logging]"', $out );
		$this->assertStringContainsString( 'data-nn-reset-toggle', $out );
	}

	public function test_enable_logging_callback_unchecked_when_disabled(): void {
		\update_option( 'newspack_event_logger_nodes_enable_logging', 0 );
		$admin = new Admin();
		\ob_start();
		$admin->enable_logging_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'type="checkbox"', $out );
		$this->assertStringNotContainsString( 'checked="checked"', $out );
	}

	/**
	 * Every option exposed by the settings form must be in the bulk-reset list,
	 * so "Reset to Defaults" clears all of them (booleans + multi-selects too).
	 */
	public function test_reset_list_covers_every_settings_form_option(): void {
		$admin = new Admin();
		$admin->register_settings();

		$ref = new \ReflectionProperty( Admin::class, 'option_names' );
		$reset_list = $ref->getValue();

		foreach ( \array_keys( $GLOBALS['_registered_settings'] ) as $registered ) {
			$this->assertContains(
				$registered,
				$reset_list,
				"registered settings-form option $registered is missing from the bulk-reset list"
			);
		}
	}

	public function test_log_memory_callback_renders_checkbox(): void {
		\update_option( 'newspack_event_logger_nodes_log_memory', 1 );
		$admin = new Admin();
		\ob_start();
		$admin->log_memory_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'name="newspack_event_logger_nodes_log_memory"', $out );
		$this->assertStringContainsString( 'type="checkbox"', $out );
		$this->assertStringContainsString( 'checked="checked"', $out );
		$this->assertStringContainsString( 'data-nn-reset="newspack_event_logger_nodes_reset[newspack_event_logger_nodes_log_memory]"', $out );
		$this->assertStringContainsString( 'data-nn-reset-toggle', $out );
	}

	public function test_log_memory_callback_unchecked_when_disabled(): void {
		\update_option( 'newspack_event_logger_nodes_log_memory', 0 );
		$admin = new Admin();
		\ob_start();
		$admin->log_memory_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'type="checkbox"', $out );
		$this->assertStringNotContainsString( 'checked="checked"', $out );
	}

	public function test_flush_every_line_callback_renders_checkbox(): void {
		$admin = new Admin();
		\ob_start();
		$admin->flush_every_line_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'name="newspack_event_logger_nodes_flush_every_line"', $out );
		$this->assertStringContainsString( 'type="checkbox"', $out );
		$this->assertStringContainsString( 'data-nn-reset="newspack_event_logger_nodes_reset[newspack_event_logger_nodes_flush_every_line]"', $out );
		$this->assertStringContainsString( 'data-nn-reset-toggle', $out );
	}

	public function test_flush_every_line_callback_checked_when_enabled(): void {
		\update_option( 'newspack_event_logger_nodes_flush_every_line', 1 );
		$admin = new Admin();
		\ob_start();
		$admin->flush_every_line_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'checked="checked"', $out );
	}

	// ---- data-nn-reset-default on checkboxes -----------------------------

	/**
	 * Each checkbox must advertise its FILE default via data-nn-reset-default so
	 * the shared reset-toggle JS previews the real restored state ("1" if the
	 * file default is truthy, "0" if falsy) instead of blanket-unchecking.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string}>
	 */
	public static function reset_default_provider(): array {
		return [
			'enable_logging default true'     => [ 'enable_logging_callback', [ 'enable_logging' => true ], '1' ],
			'log_memory default false'        => [ 'log_memory_callback', [ 'log_memory' => false ], '0' ],
			'flush_every_line default false'  => [ 'flush_every_line_callback', [ 'flush_every_line' => false ], '0' ],
		];
	}

	/**
	 * @dataProvider reset_default_provider
	 *
	 * @param string              $callback  Admin method to invoke.
	 * @param array<string,mixed> $defaults  File-config defaults to inject.
	 * @param string              $expected  Expected data-nn-reset-default value.
	 */
	public function test_checkbox_carries_file_default_reset_attribute( string $callback, array $defaults, string $expected ): void {
		Config::reset();
		$ref = new \ReflectionProperty( Config::class, 'config_defaults' );
		$ref->setValue( null, $defaults );

		try {
			$admin = new Admin();
			\ob_start();
			$admin->$callback();
			$out = \ob_get_clean();
			$this->assertStringContainsString( 'data-nn-reset-default="' . $expected . '"', $out );
		} finally {
			Config::reset();
		}
	}

	/**
	 * The attribute must reflect the FILE default a reset restores, NOT the
	 * current stored option: a default-enabled box must still advertise "1"
	 * even after the operator has stored 0.
	 */
	public function test_reset_default_attribute_ignores_stored_value(): void {
		Config::reset();
		$ref = new \ReflectionProperty( Config::class, 'config_defaults' );
		$ref->setValue( null, [ 'enable_logging' => true ] );
		\update_option( 'newspack_event_logger_nodes_enable_logging', 0 );

		try {
			$admin = new Admin();
			\ob_start();
			$admin->enable_logging_callback();
			$out = \ob_get_clean();
			$this->assertStringNotContainsString( 'checked="checked"', $out, 'stored 0 should render unchecked' );
			$this->assertStringContainsString( 'data-nn-reset-default="1"', $out, 'reset default must mirror the file default, not the stored value' );
		} finally {
			Config::reset();
		}
	}

	// ---- maybe_request_worker_restart additional branches ---------------

	public function test_maybe_request_worker_restart_log_memory_restarts_all_live_topologies(): void {
		$this->register_topologies();
		$this->prepare_lock_dir( 'combined', 0 );
		$this->prepare_lock_dir( 'aggregator', 0 );
		$admin = new Admin();
		// log_memory is cached in the Log_Manager singleton → 'all'.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_log_memory' );
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartFlagged( 'aggregator', 0 );
	}

	public function test_maybe_request_worker_restart_flush_every_line_restarts_all_live_topologies(): void {
		$this->register_topologies();
		$this->prepare_lock_dir( 'combined', 0 );
		$this->prepare_lock_dir( 'aggregator', 0 );
		$admin = new Admin();
		// flush_every_line is cached in the Log_Manager singleton → 'all'.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_flush_every_line' );
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartFlagged( 'aggregator', 0 );
	}

	public function test_maybe_request_worker_restart_stats_salt_targets_flame_builder(): void {
		$this->register_topologies();
		$this->prepare_lock_dir( 'combined', 0 );
		$this->prepare_lock_dir( 'aggregator', 0 );
		$admin = new Admin();
		// stats_salt is rotated by the flush handler (not a settings Field); its
		// stats producer is Stats_Store, which runs inside Flame_Builder.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_stats_salt' );
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartNotFlagged( 'aggregator', 0 );
	}

	public function test_maybe_request_worker_restart_skips_unknown_application_option(): void {
		$this->register_topologies();
		$this->prepare_lock_dir( 'combined', 0 );
		$admin = new Admin();
		// An option in the prefix but not in any Field — must be a no-op.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_unknown_option' );
		$this->assertRestartNotFlagged( 'combined', 0 );
	}

	// ---- Constructor wires the hooks --------------------------------------

	public function test_constructor_hooks_admin_menu_and_admin_init(): void {
		$GLOBALS['_wp_actions'] = [];
		$admin                  = new Admin();
		// Constructor should have registered hooks via add_action.
		$this->assertNotEmpty( $GLOBALS['_wp_actions']['admin_menu'] ?? [] );
		$this->assertNotEmpty( $GLOBALS['_wp_actions']['admin_init'] ?? [] );
		$this->assertNotEmpty( $GLOBALS['_wp_actions']['admin_post_' . Admin::RESET_ACTION] ?? [] );
		$this->assertNotEmpty( $GLOBALS['_wp_actions']['updated_option'] ?? [] );
		$this->assertNotEmpty( $GLOBALS['_wp_actions']['added_option'] ?? [] );
	}

	// ---- skip_default_writes ---------------------------------------------

	public function test_skip_default_writes_passes_through_non_prefixed_option(): void {
		$admin = new Admin();
		// Option name without the canonical prefix — filter returns the value
		// unchanged (no defaults lookup, no delete).
		$this->assertSame( 'submitted', $admin->skip_default_writes( 'submitted', 'some_other_option', 'old' ) );
	}

	public function test_skip_default_writes_passes_through_when_key_not_in_defaults(): void {
		$admin = new Admin();
		// Prefixed option whose suffix is not a recognized config-default key.
		$this->assertSame( 'submitted', $admin->skip_default_writes( 'submitted', 'newspack_event_logger_nodes_bogus_field', 'old' ) );
	}

	public function test_skip_default_writes_passes_through_when_value_differs_from_default(): void {
		// Inject a known default via the test config-file override.
		$this->use_base_dir( $this->base_dir, [ 'flush_every_line' => true ] );

		$admin = new Admin();
		// Value 1 (truthy) differs from bool-default `true` only after normalization to int 1.
		// Submit value 5 which differs from default 1 → passes through.
		$this->assertSame( 5, $admin->skip_default_writes( 5, 'newspack_event_logger_nodes_flush_every_line', 0 ) );
	}

	public function test_skip_default_writes_deletes_option_when_user_changes_back_to_default(): void {
		// flush_every_line default is `true` (bool); normalizer turns it into int 1.
		$this->use_base_dir( $this->base_dir, [ 'flush_every_line' => true ] );
		\update_option( 'newspack_event_logger_nodes_flush_every_line', 0 );

		$admin    = new Admin();
		$returned = $admin->skip_default_writes( 1, 'newspack_event_logger_nodes_flush_every_line', 0 );
		// Returns the OLD value so update_option treats the write as a no-op.
		$this->assertSame( 0, $returned );
		// The row is deleted so the file-default kicks in on next read.
		$this->assertFalse( \get_option( 'newspack_event_logger_nodes_flush_every_line' ) );
	}

	public function test_skip_default_writes_ignores_a_foreign_option_whose_tail_matches_a_key(): void {
		$this->use_base_dir( $this->base_dir, [ 'flush_every_line' => true ] );
		// Exactly the prefix LENGTH of foreign characters, then a real key of
		// ours. Stripping by length judges another plugin's option against our
		// defaults, and deletes their row when the values happen to agree.
		$foreign = \str_repeat( 'z', 28 ) . 'flush_every_line';
		\update_option( $foreign, 0 );

		$admin = new Admin();

		$this->assertSame( 1, $admin->skip_default_writes( 1, $foreign, 0 ) );
		$this->assertNotFalse( \get_option( $foreign ) );
	}

	public function test_skip_default_writes_returns_old_value_when_already_equal_to_default(): void {
		// Default `true` → normalized to int 1.
		$this->use_base_dir( $this->base_dir, [ 'flush_every_line' => true ] );

		$admin = new Admin();
		// Submitted value already equals old value and the default — short-circuits
		// to old_value with no delete (avoids touching the row needlessly).
		$returned = $admin->skip_default_writes( 1, 'newspack_event_logger_nodes_flush_every_line', 1 );
		$this->assertSame( 1, $returned );
	}

	public function test_skip_default_writes_compares_int_defaults_directly(): void {
		// Non-bool default — int 42 should be compared as-is (no normalization).
		$this->use_base_dir( $this->base_dir, [ 'auto_disable_threshold' => 42 ] );
		\update_option( 'newspack_event_logger_nodes_auto_disable_threshold', 100 );

		$admin = new Admin();
		// User reverts to default 42 → filter returns OLD value (100), deletes the row.
		$returned = $admin->skip_default_writes( 42, 'newspack_event_logger_nodes_auto_disable_threshold', 100 );
		$this->assertSame( 100, $returned );
		$this->assertFalse( \get_option( 'newspack_event_logger_nodes_auto_disable_threshold' ) );
	}

	// ---- delete_on_blank (blank text-like saves delete the row) ----------

	public function test_nonblank_text_like_option_save_passes_through(): void {
		$admin = new Admin();
		$admin->register_settings();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_disable_threshold'] = 100;

		$result = \apply_filters(
			'pre_update_option_newspack_event_logger_nodes_auto_disable_threshold',
			250,
			100,
			'newspack_event_logger_nodes_auto_disable_threshold'
		);

		$this->assertSame( 250, $result, 'a real value must persist' );
	}

	public function test_empty_selection_option_save_is_not_deleted(): void {
		// Selection field (log_events): an empty array is a deliberate override
		// and must NOT trigger delete-on-blank — no filter is attached to it.
		$admin = new Admin();
		$admin->register_settings();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init' ];

		$result = \apply_filters(
			'pre_update_option_newspack_event_logger_nodes_log_events',
			[],
			[ 'init' ],
			'newspack_event_logger_nodes_log_events'
		);

		$this->assertSame( [], $result, 'empty selection is an override, not a reset' );
		$this->assertArrayHasKey( 'newspack_event_logger_nodes_log_events', $GLOBALS['_wp_options'] );
	}

	public function test_enable_logging_checkbox_is_not_delete_on_blank(): void {
		// Checkbox boolean: an unchecked box (0) is a real "off" override and
		// must NOT be deleted — delete_on_blank is not attached to it.
		$admin = new Admin();
		$admin->register_settings();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_enable_logging'] = 1;

		$result = \apply_filters(
			'pre_update_option_newspack_event_logger_nodes_enable_logging',
			0,
			1,
			'newspack_event_logger_nodes_enable_logging'
		);

		$this->assertSame( 0, $result, 'unchecked checkbox is an override, not a reset' );
		$this->assertArrayHasKey( 'newspack_event_logger_nodes_enable_logging', $GLOBALS['_wp_options'] );
	}

	// ---- Reset_Gate: per-field reset toggle (any field type) ---------------

	public function test_reset_marked_checkbox_field_is_deleted(): void {
		// A per-field reset toggle marks the option; on save it must delete the
		// row even for a checkbox bool (excluded from delete-on-blank).
		$admin = new Admin();
		$admin->register_settings();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_enable_logging'] = 1;
		$_POST[ Admin::RESET_MARK_FIELD ]                                    = [ 'newspack_event_logger_nodes_enable_logging' => '1' ];

		try {
			$result = \apply_filters(
				'pre_update_option_newspack_event_logger_nodes_enable_logging',
				0,
				1,
				'newspack_event_logger_nodes_enable_logging'
			);
			$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_enable_logging', $GLOBALS['_wp_options'], 'reset-marked field must be deleted' );
			$this->assertSame( 1, $result, 'short-circuit returns old value' );
		} finally {
			unset( $_POST[ Admin::RESET_MARK_FIELD ] );
		}
	}

	public function test_unmarked_empty_checkbox_is_not_deleted(): void {
		// No reset mark + a checkbox bool is not text-like: the unchecked (0)
		// value must persist as a real override, not be deleted.
		$admin = new Admin();
		$admin->register_settings();
		unset( $_POST[ Admin::RESET_MARK_FIELD ] );
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_memory'] = 1;

		$result = \apply_filters(
			'pre_update_option_newspack_event_logger_nodes_log_memory',
			0,
			1,
			'newspack_event_logger_nodes_log_memory'
		);

		$this->assertSame( 0, $result, 'unmarked unchecked checkbox is an override, not a reset' );
		$this->assertArrayHasKey( 'newspack_event_logger_nodes_log_memory', $GLOBALS['_wp_options'] );
	}

	// ---- Shared Config_System wiring (Reset_Gate / Field_Reset_Assets) ----

	public function test_reset_gate_registers_pre_update_filter_for_every_resettable_option(): void {
		// Reset_Gate::register must attach a pre_update_option_{$option} gate for
		// EVERY resettable option (booleans + multi-selects included), so a reset
		// toggle clears them all.
		$admin = new Admin();
		$admin->register_settings();

		$expected = [
			'newspack_event_logger_nodes_enable_logging',
			'newspack_event_logger_nodes_log_memory',
			'newspack_event_logger_nodes_flush_every_line',
		];
		foreach ( $expected as $option ) {
			$this->assertNotEmpty(
				$GLOBALS['_wp_actions'][ "pre_update_option_{$option}" ] ?? [],
				"Reset_Gate must register a pre_update_option gate for {$option}"
			);
		}
	}

	public function test_reset_mark_name_matches_shared_reset_gate(): void {
		$method = new \ReflectionMethod( Admin::class, 'reset_mark_name' );
		$got = $method->invoke( new Admin(), 'enable_logging' );

		$this->assertSame(
			Reset_Gate::mark_name( Admin::RESET_MARK_FIELD, 'newspack_event_logger_nodes_enable_logging' ),
			$got
		);
	}

	public function test_render_settings_page_enqueues_shared_nodes_field_reset_bundle(): void {
		$GLOBALS['_enqueued_scripts'] = [];
		$admin                        = new Admin();
		$admin->register_settings();

		\ob_start();
		$admin->render_settings_page();
		\ob_end_clean();

		$handles = \array_map( static fn ( $args ) => $args[0] ?? '', $GLOBALS['_enqueued_scripts'] );
		$this->assertContains(
			'newspack-nodes-field-reset',
			$handles,
			'settings page must enqueue the shared nodes-built field-reset bundle'
		);
		$this->assertNotContains(
			'newspack-event-logger-nodes-field-reset',
			$handles,
			'the ELN-local field-reset enqueue must be gone (shared bundle only)'
		);
	}

	public function test_render_settings_page_highlight_style_comes_from_shared_assets(): void {
		$admin = new Admin();
		$admin->register_settings();

		\ob_start();
		$admin->render_settings_page();
		$html = \ob_get_clean();

		$this->assertStringContainsString( Field_Reset_Assets::highlight_style(), $html );
	}

	// ---- render_maintenance_section --------------------------------------

	public function test_render_maintenance_section_outputs_flush_form(): void {
		$admin = new Admin();
		\ob_start();
		$admin->render_maintenance_section();
		$out = \ob_get_clean();

		// Maintenance header.
		$this->assertStringContainsString( 'Maintenance', $out );
		// Flush button + hidden form posting back to admin-post with nonce.
		$this->assertStringContainsString( 'Flush Cache', $out );
		$this->assertStringContainsString( 'newspack-event-logger-nodes-flush-form', $out );
		$this->assertStringContainsString( Admin::FLUSH_STATS_ACTION, $out );
		$this->assertStringContainsString( Admin::FLUSH_STATS_NONCE, $out );
		// The confirm() message references key copy.
		$this->assertStringContainsString( 'Flush all performance stats', $out );
	}

	// ---- handle_flush_stats ----------------------------------------------

	public function test_handle_flush_stats_rejects_missing_nonce(): void {
		$_POST = [];
		$admin = new Admin();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Security check failed' );
		$admin->handle_flush_stats();
	}

	public function test_handle_flush_stats_rejects_invalid_nonce(): void {
		$_POST = [ Admin::FLUSH_STATS_NONCE => 'bogus' ];
		$admin = new Admin();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Security check failed' );
		$admin->handle_flush_stats();
	}

	public function test_handle_flush_stats_rejects_unauthorized_user(): void {
		$_POST                        = [ Admin::FLUSH_STATS_NONCE => wp_create_nonce( Admin::FLUSH_STATS_ACTION ) ];
		$GLOBALS['_current_user_can'] = false;
		$admin                        = new Admin();
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'You do not have permission' );
		$admin->handle_flush_stats();
	}

	public function test_handle_flush_stats_rotates_salt_and_redirects(): void {
		$_POST = [ Admin::FLUSH_STATS_NONCE => wp_create_nonce( Admin::FLUSH_STATS_ACTION ) ];
		// Seed an old salt — flush_all() must overwrite it.
		\update_option( 'newspack_event_logger_nodes_stats_salt', 'old_salt' );

		$admin = new Admin();
		try {
			$admin->handle_flush_stats();
			$this->fail( 'expected RedirectException from wp_safe_redirect()' );
		} catch ( RedirectException $e ) {
			// Expected: handler completes via redirect.
		}

		// A new salt was written (Stats_Store::flush_all overwrites unconditionally).
		$new_salt = \get_option( 'newspack_event_logger_nodes_stats_salt' );
		$this->assertNotSame( 'old_salt', $new_salt );
		$this->assertIsString( $new_salt );

		// Redirect URL carries the flushed flag + restart count.
		$this->assertNotNull( $GLOBALS['_last_redirect'] );
		$this->assertStringContainsString( 'flushed=1', $GLOBALS['_last_redirect'] );
		$this->assertStringContainsString( 'restarted=', $GLOBALS['_last_redirect'] );
		$this->assertStringContainsString( Admin::MENU_SLUG, $GLOBALS['_last_redirect'] );
	}

	public function test_handle_flush_stats_swallows_restart_throwable(): void {
		// The worker-restart step is best-effort: if expand_workers / restart_workers
		// throws (here forced via an uncreatable base_directory so the locks path
		// blows up), the catch swallows it and the handler still redirects.
		$this->use_base_dir( $this->base_dir, [ 'base_directory' => '/proc/this/cannot/be/created' ] );
		$_POST = [ Admin::FLUSH_STATS_NONCE => wp_create_nonce( Admin::FLUSH_STATS_ACTION ) ];

		$admin = new Admin();
		try {
			$admin->handle_flush_stats();
			$this->fail( 'expected RedirectException even when the restart step throws' );
		} catch ( RedirectException $e ) {
			// Expected — the catch swallowed the restart failure and redirected.
		}

		// The salt still rotated (flush_all runs before the restart attempt).
		$this->assertNotNull( \get_option( 'newspack_event_logger_nodes_stats_salt' ) );
		$this->assertNotNull( $GLOBALS['_last_redirect'] );
		$this->assertStringContainsString( 'flushed=1', $GLOBALS['_last_redirect'] );
	}

	public function test_handle_flush_stats_rotates_salt(): void {
		// flush_all() only rotates the salt option — it doesn't touch memcache,
		// so the handler runs regardless of memcache configuration.
		$this->use_base_dir(
			$this->base_dir,
			[ 'memcache_servers' => [ '127.0.0.1:11211', '127.0.0.1:11212' ] ]
		);
		$_POST = [ Admin::FLUSH_STATS_NONCE => wp_create_nonce( Admin::FLUSH_STATS_ACTION ) ];

		$admin = new Admin();
		try {
			$admin->handle_flush_stats();
			$this->fail( 'expected RedirectException' );
		} catch ( RedirectException $e ) {
			// Expected.
		}
		$this->assertNotNull( \get_option( 'newspack_event_logger_nodes_stats_salt' ) );
	}

	public function test_maybe_request_worker_restart_swallows_throwables_in_worker_groups_path(): void {
		// Same defensive path on the regular (request-workers / job-workers)
		// branch — Config::load_config() failing is caught and the handler
		// silently returns rather than fatal-erroring on a save.
		$this->use_base_dir( $this->base_dir, [ 'base_directory' => '/proc/this/cannot/be/created' ] );

		$admin = new Admin();
		// Must not throw.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_significant_events' );
		$this->addToAssertionCount( 1 );
	}

	// ---- additional edge cases for higher coverage --------------------------

	public function test_skip_default_writes_returns_value_when_key_strips_to_empty(): void {
		// When the option name is EXACTLY the prefix itself, `substr` returns
		// `''` and the guard `'' === $key` short-circuits with the input value.
		$admin = new Admin();
		$this->assertSame( 'anything', $admin->skip_default_writes( 'anything', 'newspack_event_logger_nodes_', 'old' ) );
	}

	public function test_skip_default_writes_passes_through_complex_array_default(): void {
		// `log_events` default is an array — the filter must compare arrays
		// directly without bool-normalization.
		$this->use_base_dir( $this->base_dir, [ 'log_events' => [ 'init', 'shutdown' ] ] );

		$admin = new Admin();
		// Submitting a different array passes through unchanged.
		$result = $admin->skip_default_writes(
			[ 'init', 'other' ],
			'newspack_event_logger_nodes_log_events',
			[]
		);
		$this->assertSame( [ 'init', 'other' ], $result );
	}

	public function test_handle_flush_stats_with_default_memcache_when_unset(): void {
		// No memcache_servers configured — handler falls back to DEFAULT_SERVERS.
		$this->use_base_dir( $this->base_dir, [] );
		$_POST = [ Admin::FLUSH_STATS_NONCE => wp_create_nonce( Admin::FLUSH_STATS_ACTION ) ];

		$admin = new Admin();
		try {
			$admin->handle_flush_stats();
			$this->fail( 'expected RedirectException' );
		} catch ( RedirectException $e ) {
			// Expected.
		}
		$this->assertNotNull( \get_option( 'newspack_event_logger_nodes_stats_salt' ) );
	}

	public function test_constructor_registers_admin_post_flush_action(): void {
		$GLOBALS['_wp_actions'] = [];
		$admin                  = new Admin();
		$this->assertNotEmpty(
			$GLOBALS['_wp_actions']['admin_post_' . Admin::FLUSH_STATS_ACTION] ?? [],
			'admin_post hook for flush_stats must be wired'
		);
		$this->assertNotEmpty(
			$GLOBALS['_wp_actions']['newspack_event_logger_nodes/settings_after_form'] ?? [],
			'settings_after_form hook must be wired'
		);
		$this->assertNotEmpty(
			$GLOBALS['_wp_actions']['pre_update_option'] ?? [],
			'pre_update_option filter must be wired (skip_default_writes)'
		);
	}

	public function test_render_maintenance_section_contains_flush_button(): void {
		$admin = new Admin();
		\ob_start();
		$admin->render_maintenance_section();
		$out = \ob_get_clean();
		// The "Rotates the stats-salt" description string is present below the button.
		$this->assertStringContainsString( 'stats-salt', $out );
	}

	public function test_register_settings_does_not_register_remote_settings(): void {
		// The three remote-spoke geometry settings moved to the substrate; ELN
		// no longer registers the options, the section, or the fields.
		$admin = new Admin();
		$admin->register_settings();

		foreach ( [
			'newspack_event_logger_nodes_remote_num_segments',
			'newspack_event_logger_nodes_remote_segment_size',
			'newspack_event_logger_nodes_remote_max_lifespan',
		] as $option ) {
			$this->assertArrayNotHasKey( $option, $GLOBALS['_registered_settings'] );
		}
		$this->assertArrayNotHasKey(
			'newspack_event_logger_nodes_remote_settings_section',
			$GLOBALS['_registered_sections']
		);
		foreach ( [ 'remote_num_segments', 'remote_segment_size', 'remote_max_lifespan' ] as $f ) {
			$this->assertArrayNotHasKey( $f, $GLOBALS['_registered_fields'] );
		}
	}

	public function test_register_settings_registers_debugging_fields(): void {
		$admin = new Admin();
		$admin->register_settings();

		foreach ( [ 'log_memory', 'flush_every_line' ] as $f ) {
			$this->assertArrayHasKey( $f, $GLOBALS['_registered_fields'] );
		}
	}

}

} // close namespace Newspack_Event_Logger_Nodes\Tests\Unit\Admin
