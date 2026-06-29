<?php
/**
 * AdminTest: unit tests for the WP-Settings-API admin surface.
 *
 * Owns its own minimal WP-Settings-API stubs so the bootstrap stays focused on
 * REST + general admin scaffolding. The stubs intentionally just record their
 * arguments into globals — assertions read those globals to verify the admin
 * registered the right keys with the right callbacks.
 *
 * Stubs MUST live in the global namespace because the Admin class calls them
 * with a leading backslash (e.g. `\register_setting(...)` resolves to
 * `\register_setting`, not `Newspack_Event_Logger_Nodes\Admin\register_setting`).
 * This file therefore opens with a global-namespace `namespace { … }` block for
 * the stubs, then declares the test-class namespace below it.
 */

// -- WP Settings-API stubs (global namespace) -------------------------------

namespace {
	if ( ! \function_exists( 'register_setting' ) ) {
		function register_setting( string $group, string $option, array $args = [] ): void {
			$GLOBALS['_registered_settings'][ $option ] = [
				'group' => $group,
				'args'  => $args,
			];
		}
	}
	if ( ! \function_exists( 'add_settings_section' ) ) {
		function add_settings_section( string $id, string $title, callable $cb, string $page ): void {
			$GLOBALS['_registered_sections'][ $id ] = [
				'title'    => $title,
				'callback' => $cb,
				'page'     => $page,
			];
		}
	}
	if ( ! \function_exists( 'add_settings_field' ) ) {
		function add_settings_field( string $id, string $title, callable $cb, string $page, string $section ): void {
			$GLOBALS['_registered_fields'][ $id ] = [
				'title'    => $title,
				'callback' => $cb,
				'page'     => $page,
				'section'  => $section,
			];
		}
	}
	if ( ! \function_exists( 'settings_fields' ) ) {
		function settings_fields( string $group ): void {
			echo '<input type="hidden" name="option_page" value="' . \htmlspecialchars( $group, ENT_QUOTES ) . '" />';
		}
	}
	if ( ! \function_exists( 'do_settings_sections' ) ) {
		function do_settings_sections( string $page ): void {
			echo '<!-- do_settings_sections:' . \htmlspecialchars( $page, ENT_QUOTES ) . ' -->';
		}
	}
	if ( ! \function_exists( 'submit_button' ) ) {
		function submit_button( string $text = 'Save', string $type = 'primary', string $name = 'submit', bool $wrap = true ): void {
			echo '<input type="submit" />';
		}
	}
	if ( ! \function_exists( 'wp_nonce_field' ) ) {
		function wp_nonce_field( string $action, string $name ): void {
			echo '<input type="hidden" name="' . \htmlspecialchars( $name, ENT_QUOTES ) . '" value="nonce_' . \htmlspecialchars( $action, ENT_QUOTES ) . '" />';
		}
	}
	if ( ! \function_exists( 'wp_verify_nonce' ) ) {
		function wp_verify_nonce( string $nonce, string $action ): bool {
			return 'nonce_' . $action === $nonce;
		}
	}
	if ( ! \function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return $value;
		}
	}
	if ( ! \function_exists( 'absint' ) ) {
		function absint( $v ): int {
			return \abs( (int) $v );
		}
	}
	if ( ! \function_exists( 'admin_url' ) ) {
		function admin_url( string $path = '' ): string {
			return 'http://localhost/wp-admin/' . \ltrim( $path, '/' );
		}
	}
	if ( ! \function_exists( 'add_query_arg' ) ) {
		function add_query_arg( array $args, string $url ): string {
			$sep = false === \strpos( $url, '?' ) ? '?' : '&';
			$kv  = [];
			foreach ( $args as $k => $v ) {
				$kv[] = \rawurlencode( (string) $k ) . '=' . \rawurlencode( (string) $v );
			}
			return $url . $sep . \implode( '&', $kv );
		}
	}
	if ( ! \function_exists( 'wp_safe_redirect' ) ) {
		// Redirect-then-exit short-circuits the test runner. Throw a sentinel
		// exception instead so each test can catch it explicitly.
		function wp_safe_redirect( string $url ): void {
			$GLOBALS['_last_redirect'] = $url;
			throw new \Newspack_Event_Logger_Nodes\Tests\Unit\Admin\RedirectException( $url );
		}
	}
	if ( ! \function_exists( 'wp_die' ) ) {
		function wp_die( string $message ): void {
			throw new \RuntimeException( 'wp_die: ' . $message );
		}
	}
	if ( ! \function_exists( 'add_options_page' ) ) {
		function add_options_page( string $page_title, string $menu_title, string $cap, string $slug, callable $cb ): string {
			$GLOBALS['_options_pages'][ $slug ] = [
				'page_title' => $page_title,
				'menu_title' => $menu_title,
				'capability' => $cap,
				'callback'   => $cb,
			];
			return 'settings_page_' . $slug;
		}
	}
	if ( ! \function_exists( 'esc_textarea' ) ) {
		function esc_textarea( $v ): string {
			return \htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! \function_exists( 'checked' ) ) {
		function checked( $checked, $current = true ): string {
			$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
			echo $out;
			return $out;
		}
	}
	if ( ! \function_exists( 'esc_attr' ) ) {
		function esc_attr( $v ): string {
			return \htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! \function_exists( 'esc_html' ) ) {
		function esc_html( $v ): string {
			return \htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! \function_exists( 'esc_html__' ) ) {
		function esc_html__( string $v, string $domain = '' ): string {
			return \htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! \function_exists( 'esc_html_e' ) ) {
		function esc_html_e( string $v, string $domain = '' ): void {
			echo \htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! \function_exists( 'esc_attr__' ) ) {
		function esc_attr__( string $v, string $domain = '' ): string {
			return \htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! \function_exists( 'esc_attr_e' ) ) {
		function esc_attr_e( string $v, string $domain = '' ): void {
			echo \htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! \function_exists( 'esc_url' ) ) {
		function esc_url( string $v ): string {
			return $v;
		}
	}
	if ( ! \function_exists( 'esc_url_raw' ) ) {
		function esc_url_raw( string $v ): string {
			return $v;
		}
	}
	if ( ! \function_exists( 'esc_js' ) ) {
		function esc_js( string $v ): string {
			return \str_replace( [ "'", '"', '<', '>' ], [ "\\'", '\\"', '\\u003c', '\\u003e' ], $v );
		}
	}
	if ( ! \function_exists( '__' ) ) {
		function __( string $v, string $domain = '' ): string {
			return $v;
		}
	}
	if ( ! \function_exists( 'wp_get_current_user' ) ) {
		function wp_get_current_user() {
			$u             = new \stdClass();
			$u->user_login = $GLOBALS['_current_user_login'] ?? '';
			return $u;
		}
	}
	if ( ! \function_exists( 'remove_action' ) ) {
		function remove_action( string $hook, $cb, int $priority = 10 ): bool {
			if ( ! isset( $GLOBALS['_wp_actions'][ $hook ] ) ) {
				return false;
			}
			foreach ( $GLOBALS['_wp_actions'][ $hook ] as $i => $existing ) {
				if ( $existing === $cb ) {
					unset( $GLOBALS['_wp_actions'][ $hook ][ $i ] );
					return true;
				}
			}
			return false;
		}
	}

	// Admin class is normally required by the main plugin file's deferred loader,
	// but that loader doesn't yet include includes/admin/class-admin.php — until
	// the main file is updated to wire it, require it here so this test runs.
	require_once \dirname( __DIR__, 3 ) . '/includes/admin/class-admin.php';
}

// -- Test class -------------------------------------------------------------

namespace Newspack_Event_Logger_Nodes\Tests\Unit\Admin {

use Newspack_Event_Logger_Nodes\Admin\Admin;
use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Config_System\Field_Reset_Assets;
use Newspack_Nodes\Config_System\Reset_Gate;
use Newspack_Nodes\Lock_Node;
use Newspack_Nodes\Topology_Registry;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Sentinel: thrown by the wp_safe_redirect stub so tests can intercept the
 * redirect-then-exit flow without actually killing the PHP process.
 */
class RedirectException extends \RuntimeException {
}

#[CoversClass( Admin::class )]
class AdminTest extends TestCase {

	private string $base_dir;

	/** Temp dir holding the per-test topology `.tsl` fixtures (null until register_topologies()). */
	private ?string $tsl_dir = null;

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
		if ( null !== $this->tsl_dir ) {
			\delete_option( 'newspack_nodes_topologies' );
			\delete_option( 'newspack_nodes_num_partitions' );
			RuntimeConfig::reset();
			Topology_Registry::reset();
			$this->rmdir_recursive( $this->tsl_dir );
			$this->tsl_dir = null;
		}
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
			'newspack_event_logger_nodes_log_urls',
			'newspack_event_logger_nodes_skip_urls',
			'newspack_event_logger_nodes_log_events',
			'newspack_event_logger_nodes_custom_events',
			'newspack_event_logger_nodes_significant_events',
			'newspack_event_logger_nodes_auto_disable_threshold',
			'newspack_event_logger_nodes_auto_protect_time_threshold',
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

	public function test_register_settings_uses_sanitize_int_or_empty_for_int_options(): void {
		$admin = new Admin();
		$admin->register_settings();

		// Application-side int options. Substrate ints (num_partitions etc.)
		// are owned by the substrate Admin; not asserted here.
		$int_options = [
			'newspack_event_logger_nodes_auto_disable_threshold',
		];
		foreach ( $int_options as $option ) {
			$cb = $GLOBALS['_registered_settings'][ $option ]['args']['sanitize_callback'];
			$this->assertIsArray( $cb );
			$this->assertSame( 'sanitize_int_or_empty', $cb[1] );
			// Empty stays empty.
			$this->assertSame( '', \call_user_func( $cb, '' ) );
			// Coerces numeric.
			$this->assertSame( 42, \call_user_func( $cb, '42' ) );
		}
	}

	public function test_register_settings_adds_general_section(): void {
		$admin = new Admin();
		$admin->register_settings();

		$this->assertArrayHasKey( 'newspack_event_logger_nodes_general_section', $GLOBALS['_registered_sections'] );

		// Application-side fields populated under the right page. Storage
		// fields (substrate) are NOT asserted here.
		foreach ( [ 'enable_logging', 'log_urls', 'skip_urls', 'log_events', 'custom_events', 'significant_events' ] as $field ) {
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
		// Config::validate_config_path() restricts overrides to allowed dirs;
		// add /tmp via reflection for the duration of the test.
		$ref           = new \ReflectionProperty( Config::class, 'allowed_config_dirs' );
		$saved_allowed = $ref->getValue();
		$ref->setValue( null, [ ...$saved_allowed, '/tmp' ] );

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
			@\unlink( $config_file );
			$ref->setValue( null, $saved_allowed );
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
		\update_option( 'newspack_event_logger_nodes_auto_disable_threshold', 1234 );
		\update_option( 'unrelated_option', 'survives' );

		$admin = new Admin();
		try {
			$admin->handle_reset_settings();
			$this->fail( 'expected RedirectException from wp_safe_redirect()' );
		} catch ( RedirectException $e ) {
			// Expected — the handler completes via redirect.
		}

		$this->assertFalse( \get_option( 'newspack_event_logger_nodes_enable_logging' ) );
		$this->assertFalse( \get_option( 'newspack_event_logger_nodes_auto_disable_threshold' ) );
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
		$this->prepare_topology_lock_dir( 'combined', 0 );
		$admin = new Admin();
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_log_urls' );
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_skip_urls' );

		// log_urls / skip_urls are read per-request in the web process — no
		// long-lived worker caches them, so nothing restarts.
		$this->assertRestartNotFlagged( 'combined', 0 );
	}

	public function test_maybe_request_worker_restart_supervisor_only_enable_logging_restarts_all_live_topologies(): void {
		$this->register_topologies();
		$this->prepare_topology_lock_dir( 'combined', 0 );
		$this->prepare_topology_lock_dir( 'aggregator', 0 );
		$admin = new Admin();
		// enable_logging is cached in the Log_Manager per-process singleton,
		// which every long-lived worker holds → 'all' → restart every live topology.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_enable_logging' );
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartFlagged( 'aggregator', 0 );
	}

	public function test_maybe_request_worker_restart_significant_events_restarts_all_live_topologies(): void {
		$this->register_topologies();
		$this->prepare_topology_lock_dir( 'combined', 0 );
		$this->prepare_topology_lock_dir( 'aggregator', 0 );
		// Phantom worker-group lock dir that matches NO live topology — the bug
		// touched this and silently restarted nothing real.
		$this->prepare_lock_dir( 'request-workers', 0 );

		$admin = new Admin();
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_significant_events' );

		// significant_events is cached at construction by App\Core (built in
		// EVERY worker) → 'all' → restart every live topology, Flame_Builder's
		// combined included.
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartFlagged( 'aggregator', 0 );
		// The phantom worker-group dir must NOT be touched.
		$this->assertFileDoesNotExist( $this->base_dir . '/locks/request-workers.p0.lock.d/' . Lock_Node::RESTART_FLAG );
	}

	public function test_maybe_request_worker_restart_log_events_restarts_all_live_topologies(): void {
		$this->register_topologies();
		$this->prepare_topology_lock_dir( 'combined', 0 );
		$this->prepare_topology_lock_dir( 'hub-control', 0 );
		$this->prepare_topology_lock_dir( 'job-router', 0 );

		$admin = new Admin();
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_log_events' );

		// App\Core binds the log_events hook set at construction in every worker → 'all'.
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartFlagged( 'hub-control', 0 );
		$this->assertRestartFlagged( 'job-router', 0 );
	}

	public function test_maybe_request_worker_restart_log_events_flags_combined_on_combined_only_deployment(): void {
		// Regression for the under-restart bug: a combined-ONLY active set used to
		// resolve log_events to Discovery_Collector (only in the inactive
		// hub-control) and restart NOTHING. App\Core caches log_events in the
		// combined worker too, so 'all' must flag combined here.
		$this->register_topologies( [ 'combined' ] );
		$this->prepare_topology_lock_dir( 'combined', 0 );

		$admin = new Admin();
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_log_events' );

		$this->assertRestartFlagged( 'combined', 0 );
	}

	public function test_maybe_request_worker_restart_iterates_all_partitions(): void {
		// Force num_partitions=4 via the substrate WP option (substrate owns
		// num_partitions). The flame-builder topology fans out over every partition.
		$this->register_topologies();
		\update_option( 'newspack_nodes_num_partitions', 4 );
		Config::reset();
		RuntimeConfig::reset();
		for ( $p = 0; $p < 4; $p++ ) {
			$this->prepare_topology_lock_dir( 'flame-builder', $p );
		}

		$admin = new Admin();
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_significant_events' );

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

	public function test_render_settings_page_wrap_carries_newspack_theme_class(): void {
		// The settings chrome references var(--np-*) tokens (Newspack reskin), so
		// the page wrap must carry the .newspack-nodes-theme class that defines
		// them; without it every token falls back to its CSS default.
		$admin = new Admin();
		$admin->register_settings();

		\ob_start();
		$admin->render_settings_page();
		$html = \ob_get_clean();

		$this->assertStringContainsString(
			'wrap event-logger-settings-wrap newspack-nodes-theme',
			$html
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

	// ---- Sanitizers -------------------------------------------------------

	public function test_sanitize_int_or_empty_handles_empty_and_null(): void {
		$admin = new Admin();
		$this->assertSame( '', $admin->sanitize_int_or_empty( '' ) );
		$this->assertSame( '', $admin->sanitize_int_or_empty( null ) );
	}

	public function test_sanitize_int_or_empty_coerces_numeric(): void {
		$admin = new Admin();
		$this->assertSame( 42, $admin->sanitize_int_or_empty( '42' ) );
		$this->assertSame( 42, $admin->sanitize_int_or_empty( 42 ) );
		// absint coerces negatives to abs.
		$this->assertSame( 42, $admin->sanitize_int_or_empty( '-42' ) );
	}

	public function test_sanitize_float_or_empty_handles_empty_and_null(): void {
		$admin = new Admin();
		$this->assertSame( '', $admin->sanitize_float_or_empty( '' ) );
		$this->assertSame( '', $admin->sanitize_float_or_empty( null ) );
	}

	public function test_sanitize_float_or_empty_returns_empty_for_non_numeric(): void {
		$admin = new Admin();
		$this->assertSame( '', $admin->sanitize_float_or_empty( 'abc' ) );
		$this->assertSame( '', $admin->sanitize_float_or_empty( 'not-a-number' ) );
	}

	public function test_sanitize_float_or_empty_returns_empty_for_negative(): void {
		$admin = new Admin();
		// Negative floats are normalized to empty (use-default).
		$this->assertSame( '', $admin->sanitize_float_or_empty( '-2.5' ) );
		$this->assertSame( '', $admin->sanitize_float_or_empty( -10 ) );
	}

	public function test_sanitize_float_or_empty_accepts_positive(): void {
		$admin = new Admin();
		$this->assertSame( 1.5, $admin->sanitize_float_or_empty( '1.5' ) );
		$this->assertSame( 100.0, $admin->sanitize_float_or_empty( 100 ) );
		$this->assertSame( 0.0, $admin->sanitize_float_or_empty( '0' ) );
	}

	public function test_sanitize_array_strings_decodes_json_input(): void {
		$admin = new Admin();
		$result = $admin->sanitize_array_strings( '["init","shutdown"]' );
		$this->assertSame( [ 'init', 'shutdown' ], $result );
	}

	public function test_sanitize_array_strings_handles_array_input(): void {
		$admin = new Admin();
		$result = $admin->sanitize_array_strings( [ 'a', 'b', 'c' ] );
		$this->assertSame( [ 'a', 'b', 'c' ], $result );
	}

	public function test_sanitize_array_strings_returns_empty_for_empty_string(): void {
		$admin = new Admin();
		$this->assertSame( [], $admin->sanitize_array_strings( '' ) );
		$this->assertSame( [], $admin->sanitize_array_strings( '   ' ) );
	}

	public function test_sanitize_array_strings_returns_empty_for_non_array(): void {
		$admin = new Admin();
		$this->assertSame( [], $admin->sanitize_array_strings( 42 ) );
		$this->assertSame( [], $admin->sanitize_array_strings( null ) );
		$this->assertSame( [], $admin->sanitize_array_strings( true ) );
	}

	public function test_sanitize_array_strings_falls_back_to_newline_split(): void {
		$admin = new Admin();
		// Not JSON — falls back to newline-separated parsing.
		$result = $admin->sanitize_array_strings( "first\nsecond\nthird" );
		$this->assertSame( [ 'first', 'second', 'third' ], $result );
	}

	public function test_sanitize_array_strings_drops_non_scalar_values(): void {
		$admin = new Admin();
		$result = $admin->sanitize_array_strings( [ 'good', [ 'nested' ], 'fine', null, 42 ] );
		// Non-scalar dropped; numbers coerce to strings.
		$this->assertSame( [ 'good', 'fine', '42' ], $result );
	}

	public function test_sanitize_array_strings_dedupes_and_drops_empty(): void {
		$admin = new Admin();
		$result = $admin->sanitize_array_strings( [ 'a', '', 'a', '   ', 'b' ] );
		$this->assertSame( [ 'a', 'b' ], $result );
	}

	public function test_sanitize_custom_events_converts_list_to_assoc(): void {
		$admin = new Admin();
		$result = $admin->sanitize_custom_events( [ 'evt_a', 'evt_b' ] );
		$this->assertSame( [ 'evt_a' => true, 'evt_b' => true ], $result );
	}

	public function test_sanitize_custom_events_decodes_json(): void {
		$admin = new Admin();
		$result = $admin->sanitize_custom_events( '["evt_x","evt_y"]' );
		$this->assertSame( [ 'evt_x' => true, 'evt_y' => true ], $result );
	}

	public function test_sanitize_custom_events_preserves_assoc_idempotently(): void {
		$admin = new Admin();
		// Already-assoc input → preserved (idempotent re-save).
		$input  = [ 'foo' => true, 'bar' => true ];
		$result = $admin->sanitize_custom_events( $input );
		$this->assertSame( [ 'foo' => true, 'bar' => true ], $result );
	}

	public function test_sanitize_custom_events_empty_returns_empty(): void {
		$admin = new Admin();
		$this->assertSame( [], $admin->sanitize_custom_events( '' ) );
		$this->assertSame( [], $admin->sanitize_custom_events( [] ) );
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

	// ---- Field callbacks: array fields (log_urls, skip_urls, etc.) -------

	public function test_log_urls_callback_renders_tag_input_field_markup(): void {
		\update_option( 'newspack_event_logger_nodes_log_urls', [ '/calendar', '/events' ] );
		$admin = new Admin();
		\ob_start();
		$admin->log_urls_callback();
		$out = \ob_get_clean();
		// React mount marker.
		$this->assertStringContainsString( 'event-logger-log_urls', $out );
		$this->assertStringContainsString( 'event-logger-tag-input', $out );
		// Hidden JSON value carrying the current values.
		$this->assertStringContainsString( '/calendar', $out );
		$this->assertStringContainsString( '/events', $out );
	}

	public function test_log_urls_callback_with_empty_default(): void {
		$admin = new Admin();
		\ob_start();
		$admin->log_urls_callback();
		$out = \ob_get_clean();
		// Even with no values, the mount and per-field reset toggle should render.
		$this->assertStringContainsString( 'event-logger-log_urls', $out );
		$this->assertStringContainsString( 'data-nn-reset="newspack_event_logger_nodes_reset[newspack_event_logger_nodes_log_urls]"', $out );
		$this->assertStringContainsString( 'data-nn-reset-toggle', $out );
	}

	public function test_skip_urls_callback_uses_config_default_when_unset(): void {
		// Inject default skip_urls via per-test config file.
		$this->use_base_dir( $this->base_dir, [ 'skip_urls' => [ '/wp-cron.php' ] ] );

		$admin = new Admin();
		\ob_start();
		$admin->skip_urls_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'skip_urls', $out );
	}

	public function test_log_events_callback_renders_field(): void {
		\update_option( 'newspack_event_logger_nodes_log_events', [ 'init', 'wp_loaded' ] );
		$admin = new Admin();
		\ob_start();
		$admin->log_events_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'event-logger-log_events', $out );
		$this->assertStringContainsString( 'init', $out );
	}

	public function test_custom_events_callback_renders_keys_as_list(): void {
		\update_option(
			'newspack_event_logger_nodes_custom_events',
			[ 'event_a' => true, 'event_b' => true ]
		);
		$admin = new Admin();
		\ob_start();
		$admin->custom_events_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'event-logger-custom_events', $out );
		$this->assertStringContainsString( 'event_a', $out );
		$this->assertStringContainsString( 'event_b', $out );
	}

	public function test_custom_events_callback_handles_non_array(): void {
		\update_option( 'newspack_event_logger_nodes_custom_events', 'not-an-array' );
		$admin = new Admin();
		\ob_start();
		$admin->custom_events_callback();
		$out = \ob_get_clean();
		// Non-array option: treated as empty list.
		$this->assertStringContainsString( 'event-logger-custom_events', $out );
	}

	public function test_significant_events_callback_sorts_alphabetically(): void {
		\update_option(
			'newspack_event_logger_nodes_significant_events',
			[ 'zhook', 'a_hook', 'middle' ]
		);
		$admin = new Admin();
		\ob_start();
		$admin->significant_events_callback();
		$out = \ob_get_clean();
		// All names appear, and they should be in sorted order in the JSON.
		$this->assertStringContainsString( 'a_hook', $out );
		$this->assertStringContainsString( 'middle', $out );
		$this->assertStringContainsString( 'zhook', $out );
		// Position check: a_hook precedes zhook in the rendered JSON.
		$pos_a = \strpos( $out, 'a_hook' );
		$pos_z = \strpos( $out, 'zhook' );
		$this->assertNotFalse( $pos_a );
		$this->assertNotFalse( $pos_z );
		$this->assertLessThan( $pos_z, $pos_a, 'sort failed' );
	}

	// ---- Field callback: Auto-Tune (combined number inputs) --------------

	public function test_auto_tune_callback_renders_both_threshold_inputs(): void {
		$admin = new Admin();
		\ob_start();
		$admin->auto_tune_callback();
		$out = \ob_get_clean();
		// Both inputs on one row, mirroring the legacy plugin's combined UI.
		$this->assertStringContainsString( 'name="newspack_event_logger_nodes_auto_disable_threshold"', $out );
		$this->assertStringContainsString( 'name="newspack_event_logger_nodes_auto_protect_time_threshold"', $out );
		$this->assertStringContainsString( 'max="10000"', $out );  // count cap
		$this->assertStringContainsString( 'max="1000"', $out );   // ms cap (matches legacy)
		$this->assertStringContainsString( 'step="0.1"', $out );   // ms granularity
		$this->assertStringContainsString( 'event-logger-auto-disable-row', $out );
		$this->assertStringContainsString( 'event-logger-auto-disable-label', $out );
		// Combined description + reset button covering both fields.
		$this->assertStringContainsString( 'Noisy events', $out );
		$this->assertStringContainsString( 'Significant events', $out );
		// Per-field reset toggle wraps both threshold inputs (marker deletes the
		// count option; the blanked time option deletes via the shared Reset_Gate).
		$this->assertStringContainsString( 'data-nn-reset="newspack_event_logger_nodes_reset[newspack_event_logger_nodes_auto_disable_threshold]"', $out );
		$this->assertStringContainsString( 'data-nn-reset-toggle', $out );
	}

	public function test_auto_tune_callback_renders_stored_values(): void {
		\update_option( 'newspack_event_logger_nodes_auto_disable_threshold', 7777 );
		\update_option( 'newspack_event_logger_nodes_auto_protect_time_threshold', '12.5' );
		$admin = new Admin();
		\ob_start();
		$admin->auto_tune_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'value="7777"', $out );
		$this->assertStringContainsString( '12.5', $out );
	}

	public function test_auto_tune_callback_blanks_zero_values(): void {
		// Both options stored as 0 → both inputs render empty (placeholder
		// shows through) so operators don't see "0" as an active threshold.
		\update_option( 'newspack_event_logger_nodes_auto_disable_threshold', 0 );
		\update_option( 'newspack_event_logger_nodes_auto_protect_time_threshold', '0' );
		$admin = new Admin();
		\ob_start();
		$admin->auto_tune_callback();
		$out = \ob_get_clean();
		// Both inputs end up empty; we can't easily assert per-input emptiness
		// since both share placeholder="0", so check that neither has a
		// non-empty value attribute.
		$this->assertStringNotContainsString( 'value="0"', $out );
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

	public function test_maybe_request_worker_restart_auto_disable_threshold_targets_flame_builder(): void {
		$this->register_topologies();
		$this->prepare_topology_lock_dir( 'combined', 0 );
		$this->prepare_topology_lock_dir( 'aggregator', 0 );
		$admin = new Admin();
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_auto_disable_threshold' );
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartNotFlagged( 'aggregator', 0 );
	}

	public function test_maybe_request_worker_restart_auto_protect_time_threshold_targets_flame_builder(): void {
		$this->register_topologies();
		$this->prepare_topology_lock_dir( 'combined', 0 );
		$admin = new Admin();
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_auto_protect_time_threshold' );
		$this->assertRestartFlagged( 'combined', 0 );
	}

	public function test_maybe_request_worker_restart_custom_events_restarts_all_live_topologies(): void {
		$this->register_topologies();
		$this->prepare_topology_lock_dir( 'combined', 0 );
		$this->prepare_topology_lock_dir( 'hub-control', 0 );
		$this->prepare_topology_lock_dir( 'aggregator', 0 );
		$admin = new Admin();
		// custom_events is cached at construction by App\Core (built in every
		// worker) → 'all' → restart every live topology, aggregator included.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_custom_events' );
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartFlagged( 'hub-control', 0 );
		$this->assertRestartFlagged( 'aggregator', 0 );
	}

	public function test_maybe_request_worker_restart_log_memory_restarts_all_live_topologies(): void {
		$this->register_topologies();
		$this->prepare_topology_lock_dir( 'combined', 0 );
		$this->prepare_topology_lock_dir( 'aggregator', 0 );
		$admin = new Admin();
		// log_memory is cached in the Log_Manager singleton → 'all'.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_log_memory' );
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartFlagged( 'aggregator', 0 );
	}

	public function test_maybe_request_worker_restart_flush_every_line_restarts_all_live_topologies(): void {
		$this->register_topologies();
		$this->prepare_topology_lock_dir( 'combined', 0 );
		$this->prepare_topology_lock_dir( 'aggregator', 0 );
		$admin = new Admin();
		// flush_every_line is cached in the Log_Manager singleton → 'all'.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_flush_every_line' );
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartFlagged( 'aggregator', 0 );
	}

	public function test_maybe_request_worker_restart_stats_salt_targets_flame_builder(): void {
		$this->register_topologies();
		$this->prepare_topology_lock_dir( 'combined', 0 );
		$this->prepare_topology_lock_dir( 'aggregator', 0 );
		$admin = new Admin();
		// stats_salt is rotated by the flush handler (not a settings Field); its
		// stats producer is Stats_Store, which runs inside Flame_Builder.
		$admin->maybe_request_worker_restart( 'newspack_event_logger_nodes_stats_salt' );
		$this->assertRestartFlagged( 'combined', 0 );
		$this->assertRestartNotFlagged( 'aggregator', 0 );
	}

	public function test_maybe_request_worker_restart_skips_unknown_application_option(): void {
		$this->register_topologies();
		$this->prepare_topology_lock_dir( 'combined', 0 );
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

	public function test_blank_text_like_option_save_deletes_row_instead_of_storing_empty(): void {
		// A blank submission for a text-like key means "use the file default",
		// which under presence-based Config means DELETE the row — not store ''
		// (which would override the default).
		$admin = new Admin();
		$admin->register_settings();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_disable_threshold'] = 100;

		$result = \apply_filters(
			'pre_update_option_newspack_event_logger_nodes_auto_disable_threshold',
			'',
			100,
			'newspack_event_logger_nodes_auto_disable_threshold'
		);

		$this->assertArrayNotHasKey(
			'newspack_event_logger_nodes_auto_disable_threshold',
			$GLOBALS['_wp_options'],
			'blank save must delete the row so the file default resurfaces'
		);
		$this->assertSame( 100, $result, 'returns old value so update_option skips the write' );
	}

	public function test_blank_float_threshold_save_deletes_row(): void {
		$admin = new Admin();
		$admin->register_settings();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_protect_time_threshold'] = 1.5;

		$result = \apply_filters(
			'pre_update_option_newspack_event_logger_nodes_auto_protect_time_threshold',
			'',
			1.5,
			'newspack_event_logger_nodes_auto_protect_time_threshold'
		);

		$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_auto_protect_time_threshold', $GLOBALS['_wp_options'] );
		$this->assertSame( 1.5, $result );
	}

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

	public function test_reset_marked_selection_field_is_deleted(): void {
		// Selection key (log_events) is excluded from delete-on-blank, but a
		// reset mark deletes it anyway via the full-list gate.
		$admin = new Admin();
		$admin->register_settings();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init' ];
		$_POST[ Admin::RESET_MARK_FIELD ]                                = [ 'newspack_event_logger_nodes_log_events' => '1' ];

		try {
			$result = \apply_filters(
				'pre_update_option_newspack_event_logger_nodes_log_events',
				[ 'init' ],
				[ 'init' ],
				'newspack_event_logger_nodes_log_events'
			);
			$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_log_events', $GLOBALS['_wp_options'] );
			$this->assertSame( [ 'init' ], $result );
		} finally {
			unset( $_POST[ Admin::RESET_MARK_FIELD ] );
		}
	}

	public function test_reset_marked_text_field_is_deleted_even_when_value_nonblank(): void {
		// Reset wins over a non-blank submitted value (toggle marked, field not
		// yet cleared on the server side).
		$admin = new Admin();
		$admin->register_settings();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_auto_disable_threshold'] = 100;
		$_POST[ Admin::RESET_MARK_FIELD ]                                            = [ 'newspack_event_logger_nodes_auto_disable_threshold' => '1' ];

		try {
			$result = \apply_filters(
				'pre_update_option_newspack_event_logger_nodes_auto_disable_threshold',
				250,
				100,
				'newspack_event_logger_nodes_auto_disable_threshold'
			);
			$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_auto_disable_threshold', $GLOBALS['_wp_options'] );
			$this->assertSame( 100, $result );
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
			'newspack_event_logger_nodes_log_urls',
			'newspack_event_logger_nodes_skip_urls',
			'newspack_event_logger_nodes_log_events',
			'newspack_event_logger_nodes_custom_events',
			'newspack_event_logger_nodes_significant_events',
			'newspack_event_logger_nodes_auto_disable_threshold',
			'newspack_event_logger_nodes_auto_protect_time_threshold',
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

	public function test_reset_marked_multiselect_field_is_deleted_via_shared_gate(): void {
		// A reset-marked multi-select (log_urls) — excluded from delete-on-blank —
		// must still be deleted by the shared Reset_Gate.
		$admin = new Admin();
		$admin->register_settings();
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_urls'] = [ '/foo' ];
		$_POST[ Admin::RESET_MARK_FIELD ]                              = [ 'newspack_event_logger_nodes_log_urls' => '1' ];

		try {
			$result = \apply_filters(
				'pre_update_option_newspack_event_logger_nodes_log_urls',
				[ '/foo' ],
				[ '/foo' ],
				'newspack_event_logger_nodes_log_urls'
			);
			$this->assertArrayNotHasKey( 'newspack_event_logger_nodes_log_urls', $GLOBALS['_wp_options'] );
			$this->assertSame( [ '/foo' ], $result );
		} finally {
			unset( $_POST[ Admin::RESET_MARK_FIELD ] );
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

	public function test_sanitize_array_strings_handles_unicode(): void {
		// Defensive: unicode characters in the input list survive
		// sanitize_text_field (which strips control chars but not unicode).
		$admin = new Admin();
		$result = $admin->sanitize_array_strings( [ 'café', 'éclair', 'plain' ] );
		$this->assertContains( 'café', $result );
		$this->assertContains( 'éclair', $result );
		$this->assertContains( 'plain', $result );
	}

	public function test_sanitize_array_strings_strips_control_chars(): void {
		// Control characters are stripped by sanitize_text_field.
		$admin = new Admin();
		$result = $admin->sanitize_array_strings( [ "with\x00null", 'normal' ] );
		// Control chars stripped → 'withnull' remains.
		$this->assertContains( 'withnull', $result );
		$this->assertContains( 'normal', $result );
	}

	public function test_sanitize_custom_events_drops_empty_keys_in_assoc(): void {
		// Assoc input with empty keys → those entries dropped.
		$admin = new Admin();
		$result = $admin->sanitize_custom_events(
			[ '' => true, 'valid_hook' => true ]
		);
		$this->assertArrayHasKey( 'valid_hook', $result );
		$this->assertArrayNotHasKey( '', $result );
	}

	public function test_log_urls_callback_handles_assoc_input(): void {
		// `normalize_string_list` accepts both flat lists and assoc maps;
		// when stored as assoc, keys become the values.
		\update_option(
			'newspack_event_logger_nodes_log_urls',
			[ '/calendar' => true, '/events' => true ]
		);
		$admin = new Admin();
		\ob_start();
		$admin->log_urls_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( '/calendar', $out );
		$this->assertStringContainsString( '/events', $out );
	}

	public function test_log_urls_callback_handles_non_array_option(): void {
		// `normalize_string_list` returns [] for non-array; renders empty mount.
		\update_option( 'newspack_event_logger_nodes_log_urls', 'not-an-array' );
		$admin = new Admin();
		\ob_start();
		$admin->log_urls_callback();
		$out = \ob_get_clean();
		// Mount marker still present even with empty values.
		$this->assertStringContainsString( 'event-logger-log_urls', $out );
	}

	public function test_log_events_callback_handles_empty_default(): void {
		// When defaults `log_events` is empty array, the reset chip's data-default
		// is `[]`.
		$this->use_base_dir( $this->base_dir, [ 'log_events' => [] ] );
		$admin = new Admin();
		\ob_start();
		$admin->log_events_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'event-logger-log_events', $out );
	}

	public function test_significant_events_callback_empty_renders_mount(): void {
		// No stored option → no values → mount still renders.
		$admin = new Admin();
		\ob_start();
		$admin->significant_events_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'event-logger-significant_events', $out );
	}

	public function test_auto_tune_callback_renders_when_only_count_set(): void {
		// One option set, one unset — verify both inputs render correctly.
		\update_option( 'newspack_event_logger_nodes_auto_disable_threshold', 100 );
		$admin = new Admin();
		\ob_start();
		$admin->auto_tune_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( 'value="100"', $out );
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

	public function test_skip_urls_callback_renders_with_stored_value(): void {
		// Stored value overrides defaults.
		\update_option( 'newspack_event_logger_nodes_skip_urls', [ '/admin-ajax', '/heartbeat' ] );
		$admin = new Admin();
		\ob_start();
		$admin->skip_urls_callback();
		$out = \ob_get_clean();
		$this->assertStringContainsString( '/admin-ajax', $out );
		$this->assertStringContainsString( '/heartbeat', $out );
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

	// ---- helpers ----------------------------------------------------------

	private function prepare_lock_dir( string $group, int $partition ): string {
		$dir = "{$this->base_dir}/locks/{$group}.p{$partition}.lock.d";
		\mkdir( $dir, 0755, true );
		// Lock::request_restart_at() requires the dir to already exist; we
		// don't create the heartbeat file because the restart channel doesn't
		// require an active holder.
		return $dir;
	}

	/**
	 * Register a stock-topology dir of minimal `.tsl` fixtures that mirror the
	 * real ELN topologies' node CONSUMERS, and mark the named set active. This is
	 * what lets resolve_class('Flame_Builder') map a save to a live topology's
	 * lock dirs (combined/flame-builder have Flame_Builder; hub-control has
	 * Discovery_Collector; aggregator has neither).
	 *
	 * @param array<int,string> $active Active topology names (default: all fixtures).
	 */
	private function register_topologies( array $active = [ 'combined', 'aggregator', 'hub-control', 'flame-builder', 'job-router' ] ): void {
		Topology_Registry::reset();
		$this->tsl_dir = $this->make_temp_dir( 'eln-admin-topologies-' );
		Topology_Registry::register_stock_dir( $this->tsl_dir );
		\file_put_contents( "{$this->tsl_dir}/combined.tsl", "make_node Flame_Builder flame-builder\n" );
		\file_put_contents( "{$this->tsl_dir}/flame-builder.tsl", "make_node Flame_Builder flame-builder\n" );
		\file_put_contents( "{$this->tsl_dir}/hub-control.tsl", "make_node Discovery_Collector discovery-collector 300\n" );
		\file_put_contents( "{$this->tsl_dir}/aggregator.tsl", "make_node Remote_Job_Rewrite remote-job-rewrite\n" );
		// A topology with no event-list / Flame_Builder consumer at all, so an
		// 'all' classification is the only thing that reaches it.
		\file_put_contents( "{$this->tsl_dir}/job-router.tsl", "make_node Job_Router job-router\n" );
		\update_option( 'newspack_nodes_topologies', $active );
		Config::reset();
		RuntimeConfig::reset();
	}

	/** Create the partition lock dir for a live topology under the configured locks dir. */
	private function prepare_topology_lock_dir( string $topology, int $partition ): string {
		$dir = "{$this->base_dir}/locks/{$topology}.p{$partition}.lock.d";
		\mkdir( $dir, 0755, true );
		return $dir;
	}

	private function assertRestartFlagged( string $topology, int $partition ): void {
		$this->assertFileExists( "{$this->base_dir}/locks/{$topology}.p{$partition}.lock.d/" . Lock_Node::RESTART_FLAG );
	}

	private function assertRestartNotFlagged( string $topology, int $partition ): void {
		$this->assertFileDoesNotExist( "{$this->base_dir}/locks/{$topology}.p{$partition}.lock.d/" . Lock_Node::RESTART_FLAG );
	}
}

} // close namespace Newspack_Event_Logger_Nodes\Tests\Unit\Admin
