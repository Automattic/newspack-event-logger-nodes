<?php
/**
 * PHPUnit bootstrap for Newspack Event Logger Nodes tests.
 *
 * @package Newspack_Event_Logger_Nodes
 */

if ( \function_exists( 'posix_getuid' ) && 0 === \posix_getuid() ) {
	error_log("ERROR: refusing to test as root.");
	exit( 1 );
}

\ini_set( 'error_log', '/dev/null' );
\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . __DIR__ . '/newspack-event-logger-nodes-test-config.php' );
\define( 'NONCE_SALT', 'newspack-nodes-test-nonce-salt' );
\define( 'ABSPATH', '/' );

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return \dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'http://localhost/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'do_action' ) ) {
	$GLOBALS['_wp_actions']      = [];
	$GLOBALS['_wp_test_filters'] = [];
	function do_action( string $hook, ...$args ): void {
		foreach ( $GLOBALS['_wp_actions'][ $hook ] ?? [] as $cb ) {
			$cb( ...$args );
		}
	}
	function add_action( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['_wp_actions'][ $hook ][] = $cb;
	}
	function apply_filters( string $hook, mixed $value, ...$args ): mixed {
		foreach ( $GLOBALS['_wp_actions'][ $hook ] ?? [] as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
	function add_filter( string $hook, callable $cb, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['_wp_actions'][ $hook ][]                      = $cb;
		$GLOBALS['_wp_test_filters'][ $hook ][ $priority ][]    = $cb;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	// Reverses add_filter's `_wp_test_filters` bookkeeping so App\Core's
	// rebind_for_current_scope() (which calls the global remove_filter for each
	// bound hook before re-binding) round-trips in tests. Callbacks are
	// `[$obj, 'method']` arrays — value comparison matches on the same instance.
	function remove_filter( string $hook, $function_to_remove, int $priority = 10 ): bool {
		$bucket = &$GLOBALS['_wp_test_filters'][ $hook ][ $priority ];
		if ( isset( $bucket ) && is_array( $bucket ) ) {
			foreach ( $bucket as $i => $existing ) {
				if ( $existing === $function_to_remove ) {
					unset( $bucket[ $i ] );
				}
			}
			if ( empty( $bucket ) ) {
				unset( $GLOBALS['_wp_test_filters'][ $hook ][ $priority ] );
			}
			if ( empty( $GLOBALS['_wp_test_filters'][ $hook ] ) ) {
				unset( $GLOBALS['_wp_test_filters'][ $hook ] );
			}
		}
		return true;
	}
}

if ( ! class_exists( '\WP_Hook' ) ) {
	// Minimal WP_Hook stub: stores callbacks keyed by priority, provides
	// remove_filter() so Core::wrap_callbacks's introspection round-trips.
	// Real WP_Hook is much richer but our tests only care about the callbacks
	// array shape (`priority => callback_id => [function, accepted_args]`).
	class WP_Hook {
		public array $callbacks = [];
		public function remove_filter( string $hook, $function_to_remove, int $priority = 10 ): bool {
			unset( $this->callbacks[ $priority ][ _wp_filter_build_unique_id( $hook, $function_to_remove, $priority ) ] );
			return true;
		}
	}
	if ( ! function_exists( '_wp_filter_build_unique_id' ) ) {
		function _wp_filter_build_unique_id( $hook, $cb, $priority ) {
			if ( is_string( $cb ) ) {
				return $cb;
			}
			if ( is_object( $cb ) ) {
				return spl_object_hash( $cb );
			}
			if ( is_array( $cb ) ) {
				$obj = is_object( $cb[0] ) ? spl_object_hash( $cb[0] ) : $cb[0];
				return $obj . '::' . $cb[1];
			}
			return 'unknown';
		}
	}
}

if ( ! class_exists( '\WP_REST_Request' ) ) {
	class WP_REST_Request {
		private array $params = [];
		public function __construct( array $params = [] ) { $this->params = $params; }
		public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
		public function set_param( string $key, mixed $value ): void { $this->params[ $key ] = $value; }
	}
	class WP_REST_Response {
		public mixed $data;
		public int $status;
		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data = $data;
			$this->status = $status;
		}
		public function get_data(): mixed { return $this->data; }
		public function get_status(): int { return $this->status; }
	}
	class WP_Error {
		public string $code;
		public string $message;
		public array $data;
		public function __construct( string $code = '', string $message = '', array $data = [] ) {
			$this->code = $code;
			$this->message = $message;
			$this->data = $data;
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}
if ( ! function_exists( 'register_rest_route' ) ) {
	$GLOBALS['_rest_routes'] = [];
	function register_rest_route( string $namespace, string $route, array $args ): void {
		$GLOBALS['_rest_routes'][ $namespace . $route ] = $args;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		return $GLOBALS['_current_user_can'] ?? false;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof \WP_Error;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $v ): string {
		if ( ! is_string( $v ) ) {
			return '';
		}
		// Match WP: strip control chars (incl. NUL + newlines) + tags + collapse whitespace.
		$v = \strip_tags( $v );
		$v = \preg_replace( '/[\x00-\x1F\x7F]/', '', $v ) ?? $v;
		$v = \preg_replace( '/\s+/', ' ', $v ) ?? $v;
		return \trim( $v );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['_current_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( string $action ): string {
		return 'nonce_' . substr( md5( $action ), 0, 10 );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '' ): string {
		return 'http://localhost/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( ...$args ): string {
		$GLOBALS['_admin_menu_pages'][] = $args;
		return 'newspack-nodes';
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( ...$args ): string {
		$GLOBALS['_admin_submenu_pages'][] = $args;
		return 'newspack-nodes-' . ( $args[3] ?? '' );
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( ...$args ): void {
		$GLOBALS['_enqueued_scripts'][] = $args;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( ...$args ): bool {
		$GLOBALS['_localized_scripts'][] = $args;
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS['_wp_options'] = [];
	function get_option( string $key, mixed $default = false ): mixed {
		// Test seam: lets a test simulate the production wpdb->query → query-filter
		// → Core::hook_start chain that real get_option triggers when alloptions
		// isn't cached. The hook fires before the option lookup, mirroring the
		// real recursion window.
		if ( isset( $GLOBALS['_test_get_option_hook'] ) ) {
			( $GLOBALS['_test_get_option_hook'] )( $key );
		}
		return $GLOBALS['_wp_options'][ $key ] ?? $default;
	}
	// Records the autoload arg per option so tests can assert autoload
	// hygiene (hot-path scalars autoloaded, large/rare ones not). Mirrors
	// WP's 3-arg signature; `null` means "caller didn't specify" (WP keeps
	// the existing flag, or defaults a new option to autoloaded).
	$GLOBALS['_wp_option_autoload'] = [];
	function update_option( string $key, mixed $value, $autoload = null ): bool {
		$existed                                 = array_key_exists( $key, $GLOBALS['_wp_options'] );
		$old                                     = $GLOBALS['_wp_options'][ $key ] ?? false;
		$GLOBALS['_wp_options'][ $key ]          = $value;
		$GLOBALS['_wp_option_autoload'][ $key ]  = $autoload;
		// Opt-in seam: real WP fires add_option/update_option on a write so the
		// Settings_Event_Writer watcher runs. Off by default (other tests rely
		// on the silent shim); a test sets the flag to exercise the watcher path.
		if ( ! empty( $GLOBALS['_test_fire_option_actions'] ) ) {
			if ( $existed ) {
				do_action( 'update_option', $key, $old, $value );
			} else {
				do_action( 'add_option', $key, $value );
			}
		}
		return true;
	}
	function delete_option( string $key ): bool {
		$existed = array_key_exists( $key, $GLOBALS['_wp_options'] );
		unset( $GLOBALS['_wp_options'][ $key ] );
		if ( $existed && ! empty( $GLOBALS['_test_fire_option_actions'] ) ) {
			do_action( 'delete_option', $key );
		}
		return true;
	}
	// WP 6.6+ autoload setter — records the requested flag so the one-time
	// autoload-correction sweep can be asserted.
	$GLOBALS['_wp_set_option_autoload'] = [];
	function wp_set_option_autoload( string $option, $autoload ): bool {
		$GLOBALS['_wp_set_option_autoload'][ $option ] = $autoload;
		$GLOBALS['_wp_option_autoload'][ $option ]     = $autoload;
		return true;
	}
	function wp_salt( string $scheme = 'auth' ): string {
		return 'TEST_SALT_FOR_' . $scheme;
	}
}

if ( ! function_exists( 'rest_authorization_required_code' ) ) {
	function rest_authorization_required_code(): int {
		return ( get_current_user_id() > 0 ) ? 403 : 401;
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( mixed $response ): \WP_REST_Response|\WP_Error {
		if ( $response instanceof \WP_Error ) {
			return $response;
		}
		if ( $response instanceof \WP_REST_Response ) {
			return $response;
		}
		return new \WP_REST_Response( $response, 200 );
	}
}

if ( ! function_exists( 'rest_sanitize_boolean' ) ) {
	function rest_sanitize_boolean( mixed $value ): bool {
		if ( \is_bool( $value ) ) {
			return $value;
		}
		if ( \is_string( $value ) ) {
			$lower = \strtolower( $value );
			if ( \in_array( $lower, [ 'true', '1', 'on', 'yes' ], true ) ) {
				return true;
			}
			return false;
		}
		return (bool) $value;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $v ): int {
		return \abs( (int) $v );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		if ( \is_string( $value ) ) {
			return \stripslashes( $value );
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_hash' ) ) {
	function wp_hash( string $data, string $scheme = 'auth' ): string {
		return \hash( 'sha256', wp_salt( $scheme ) . $data );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): bool {
		return $nonce === ( 'nonce_' . substr( md5( $action ), 0, 10 ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return \filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $value ): string {
		return \htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
	}
}

// WP Settings-API / admin-form stubs shared by every suite. The recorder
// stubs write their arguments into globals; assertions read those globals.
if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( string $group, string $option, array $args = [] ): void {
		$GLOBALS['_registered_settings'][ $option ] = [
			'group' => $group,
			'args'  => $args,
		];
	}
}

if ( ! function_exists( 'add_settings_section' ) ) {
	function add_settings_section( string $id, string $title, callable $cb, string $page ): void {
		$GLOBALS['_registered_sections'][ $id ] = [
			'title'    => $title,
			'callback' => $cb,
			'page'     => $page,
		];
	}
}

if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( string $id, string $title, callable $cb, string $page, string $section ): void {
		$GLOBALS['_registered_fields'][ $id ] = [
			'title'    => $title,
			'callback' => $cb,
			'page'     => $page,
			'section'  => $section,
		];
	}
}

if ( ! function_exists( 'settings_fields' ) ) {
	function settings_fields( string $group ): void {
		echo '<input type="hidden" name="option_page" value="' . \htmlspecialchars( $group, ENT_QUOTES ) . '" />';
	}
}

if ( ! function_exists( 'do_settings_sections' ) ) {
	function do_settings_sections( string $page ): void {
		echo '<!-- do_settings_sections:' . \htmlspecialchars( $page, ENT_QUOTES ) . ' -->';
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( string $text = 'Save', string $type = 'primary', string $name = 'submit', bool $wrap = true ): void {
		echo '<input type="submit" />';
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action, string $name ): void {
		echo '<input type="hidden" name="' . \htmlspecialchars( $name, ENT_QUOTES ) . '" value="nonce_' . \htmlspecialchars( $action, ENT_QUOTES ) . '" />';
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'http://localhost/wp-admin/' . \ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	// Redirect-then-exit short-circuits the test runner. Throw a sentinel
	// exception instead so each test can catch it explicitly.
	function wp_safe_redirect( string $url ): void {
		$GLOBALS['_last_redirect'] = $url;
		throw new \Newspack_Event_Logger_Nodes\Tests\Helpers\RedirectException( $url );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( string $message ): void {
		throw new \RuntimeException( 'wp_die: ' . $message );
	}
}

if ( ! function_exists( 'add_options_page' ) ) {
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

if ( ! function_exists( 'checked' ) ) {
	function checked( mixed $checked, mixed $current = true ): string {
		$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
		echo $out;
		return $out;
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): \stdClass {
		$u             = new \stdClass();
		$u->user_login = $GLOBALS['_current_user_login'] ?? '';
		return $u;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
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

// Escaping/i18n stubs shared by every suite; per-file copies are forbidden —
// a test that defines its own hides a missing-stub failure from siblings.
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $v ): string {
		return \htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $v, string $domain = '' ): string {
		return \htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $v, string $domain = '' ): void {
		echo \htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $v, string $domain = '' ): string {
		return \htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( string $v, string $domain = '' ): void {
		echo \htmlspecialchars( $v, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $v ): string {
		return $v;
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( string $v ): string {
		return \str_replace( [ "'", '"', '<', '>' ], [ "\\'", '\\"', '\\u003c', '\\u003e' ], $v );
	}
}

if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( mixed $v ): string {
		return \htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $v, string $domain = '' ): string {
		return $v;
	}
}

if ( ! function_exists( '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = '' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return \preg_replace( '/[^A-Za-z0-9._\-]/', '', $name ) ?? '';
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = [] ): mixed {
		// Capture all calls so test_connection / discovery probes can assert
		// outbound args (sslverify, headers, timeout). Mirrors wp_remote_post.
		$GLOBALS['_wp_test_remote_gets'][] = [ 'url' => $url, 'args' => $args ];
		// Tests can override via $GLOBALS['_wp_test_remote_responses'] keyed by URL.
		if ( isset( $GLOBALS['_wp_test_remote_responses'][ $url ] ) ) {
			return $GLOBALS['_wp_test_remote_responses'][ $url ];
		}
		return new \WP_Error( 'no_stub', 'wp_remote_get default stub' );
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = [] ): mixed {
		// Capture all calls so RemoteManager tests can assert outbound traffic.
		$GLOBALS['_wp_test_remote_posts'][] = [ 'url' => $url, 'args' => $args ];
		// Tests can override via $GLOBALS['_wp_test_remote_post_response'] (single
		// global response) or $GLOBALS['_wp_test_remote_responses'] keyed by URL.
		if ( isset( $GLOBALS['_wp_test_remote_post_response'] ) ) {
			$resp = $GLOBALS['_wp_test_remote_post_response'];
			return is_callable( $resp ) ? $resp( $url, $args ) : $resp;
		}
		if ( isset( $GLOBALS['_wp_test_remote_responses'][ $url ] ) ) {
			return $GLOBALS['_wp_test_remote_responses'][ $url ];
		}
		return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
	}
}

if ( ! function_exists( 'home_url' ) ) {
	// Tests set $GLOBALS['_wp_test_home_url'] to control the site host.
	function home_url( string $path = '' ): string {
		$base = $GLOBALS['_wp_test_home_url'] ?? 'http://localhost';
		return \rtrim( $base, '/' ) . '/' . \ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	// Polymorphic like WP core: add_query_arg( array $params, string $url ) or
	// add_query_arg( string $key, string $value, string $url ).
	function add_query_arg( ...$args ): string {
		if ( \is_array( $args[0] ) ) {
			$params = $args[0];
			$url    = (string) ( $args[1] ?? '' );
		} else {
			$params = [ (string) $args[0] => (string) ( $args[1] ?? '' ) ];
			$url    = (string) ( $args[2] ?? '' );
		}
		$sep   = ( false === \strpos( $url, '?' ) ) ? '?' : '&';
		$pairs = [];
		foreach ( $params as $k => $v ) {
			$pairs[] = \rawurlencode( (string) $k ) . '=' . \rawurlencode( (string) $v );
		}
		return $url . $sep . \implode( '&', $pairs );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return \parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( mixed $response ): int {
		if ( \is_array( $response ) && isset( $response['response']['code'] ) ) {
			return (int) $response['response']['code'];
		}
		return 0;
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( mixed $response ): string {
		if ( \is_array( $response ) && isset( $response['body'] ) ) {
			return (string) $response['body'];
		}
		return '';
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	$GLOBALS['_wp_transients'] = [];
	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool {
		$GLOBALS['_wp_transients'][ $key ] = [ 'value' => $value, 'expires' => $ttl > 0 ? time() + $ttl : 0 ];
		return true;
	}
	function get_transient( string $key ): mixed {
		$entry = $GLOBALS['_wp_transients'][ $key ] ?? null;
		if ( null === $entry ) {
			return false;
		}
		if ( $entry['expires'] > 0 && time() >= $entry['expires'] ) {
			unset( $GLOBALS['_wp_transients'][ $key ] );
			return false;
		}
		return $entry['value'];
	}
	function delete_transient( string $key ): bool {
		unset( $GLOBALS['_wp_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return \bin2hex( \random_bytes( 16 ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return \json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, array $defaults = [] ): array {
		if ( is_object( $args ) ) {
			$args = (array) $args;
		}
		return \array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return \rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}

if ( ! function_exists( 'current_filter' ) ) {
	function current_filter(): string {
		return $GLOBALS['_wp_test_current_filter'] ?? '';
	}
}

if ( ! function_exists( 'status_header' ) ) {
	// Track every status_header() emission so tests can assert on the
	// IPC-202 / lazy-built `_http` paths of HTTP_In.
	// Mirrors the substrate's bootstrap; needed since
	// HTTP_In::ensure_request_graph() now installs a
	// production `_http` Node that calls \status_header() directly.
	$GLOBALS['_wp_test_status_headers'] = [];
	function status_header( int $code ): void {
		$GLOBALS['_wp_test_status_headers'][] = $code;
	}
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/tmp/test-wp-plugins' );
}

require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/newspack-nodes.php';
// The substrate no longer wires its runtime at plugin-file scope (it defers to
// Bootstrap::ensure_runtime_wired() at REST/admin/CLI/cron entry points), so
// boot it explicitly here — registers the node-class namespaces, the
// `<config:…>` token namespace, the stock-topology dir, and Core::$memd.
\Newspack_Nodes\Bootstrap::ensure_runtime_wired();
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/TestCase.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/CaptureSink.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/BoundedTicks.php';
// The substrate's in-memory `\Memcached` subclass — shared so ELN tests can
// seed `Core::$memd` deterministically without a real memcache server.
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/InMemoryMemcached.php';

require_once \dirname( __DIR__ ) . '/newspack-event-logger-nodes.php';

// Snapshot what the plugin file registered at load time: ConfigTest asserts the
// declaration hook is among them, since the eager declaration below would
// otherwise mask a missing (or renamed) DECLARE_ACTION registration.
$GLOBALS['_eln_boot_actions'] = $GLOBALS['_wp_actions'];

// Requiring the plugin file above hooks register_config_keys() to the substrate's
// DECLARE_ACTION, which is how production declares these. Declare once here too:
// many tests wipe $GLOBALS['_wp_actions'] for isolation, dropping that hook before
// the first read, and the registry is monotone — declaring now survives the wipe.
\Newspack_Event_Logger_Nodes\Config::register_config_keys();

// Register ELN's node-class namespaces + stock topology dir the same way the
// plugins_loaded pri-11 closure does in production (the harness bypasses it):
//  - the top-level prefix resolves make_node('Discovery_Collector', …) etc.
//  - the App\ prefix resolves the service CIs (make_node('Discovery_CI', …)).
// Without them make_node silently no-ops and dispatch routes NOT_AVAILABLE.
\Newspack_Nodes\Topology_Registry::register_plugin(
	'Newspack_Event_Logger_Nodes\\',
	NEWSPACK_EVENT_LOGGER_NODES_DIR . 'topologies'
);
\Newspack_Nodes\Command_Interpreter_Node::register_namespace( 'Newspack_Event_Logger_Nodes\\App\\' );

// Register the application `eln` token namespace so `<eln:…>` resolves in
// tests (mirrors the substrate bootstrap's register_token_namespace() call).
// Routes through Config::resolve_eln_token so prod + tests share derivation.
\Newspack_Nodes\Core::register_config_namespace(
	'eln',
	[ \Newspack_Event_Logger_Nodes\Config::class, 'resolve_eln_token' ]
);

require_once __DIR__ . '/Helpers/TestCase.php';
require_once __DIR__ . '/Helpers/SseFrameFactory.php';
require_once __DIR__ . '/Helpers/VerbHarness.php';
require_once __DIR__ . '/Helpers/RedirectException.php';

// Widen the substrate Config's allowed_config_dirs so tests using
// `LOCAL_NEWSPACK_NODES_CONF=...path-inside-this-plugin/tests/configs/...php`
// validate. Production paths in `/usr/src` are covered by the default
// allowlist; this is a host-development-only nudge.
( static function (): void {
	if ( ! \class_exists( '\\Newspack_Nodes\\Config' ) ) {
		return;
	}
	$ref     = new \ReflectionProperty( \Newspack_Nodes\Config::class, 'allowed_config_dirs' );
	$dirs    = $ref->getValue();
	$dirs[]  = \dirname( __DIR__ );
	$ref->setValue( null, $dirs );
} )();
