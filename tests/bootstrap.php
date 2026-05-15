<?php
// Redirect PHP's error_log() to /dev/null so negative-path tests don't spew
// into test output. (Matches newspack-event-logger-plugins/tests/bootstrap.php:35.)
\ini_set( 'error_log', '/dev/null' );

// Point Config at the baseline test config so app + substrate `base_directory`
// lands in `/tmp/newspack-event-logger-nodes-test` for any test that doesn't
// override it. Tests that need a per-test base_dir use `TestCase::use_base_dir()`.
\putenv( 'LOCAL_NEWSPACK_NODES_CONF=' . __DIR__ . '/newspack-event-logger-nodes-test-config.php' );

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
	function update_option( string $key, mixed $value ): bool {
		$GLOBALS['_wp_options'][ $key ] = $value;
		return true;
	}
	function delete_option( string $key ): bool {
		unset( $GLOBALS['_wp_options'][ $key ] );
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

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/tmp/test-wp-plugins' );
}

require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/newspack-nodes.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/TestCase.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/CaptureSink.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/BoundedTicks.php';

require_once \dirname( __DIR__ ) . '/newspack-event-logger-nodes.php';

require_once __DIR__ . '/Helpers/TestCase.php';
require_once __DIR__ . '/Helpers/FakeMemcached.php';

// Widen the substrate Config's allowed_config_dirs so tests using
// `LOCAL_NEWSPACK_NODES_CONF=...path-inside-this-plugin/tests/configs/...php`
// validate. Production paths in `/usr/src` are covered by the default
// allowlist; this is a host-development-only nudge.
( static function (): void {
	if ( ! \class_exists( '\\Newspack_Nodes\\Config' ) ) {
		return;
	}
	$ref     = new \ReflectionProperty( \Newspack_Nodes\Config::class, 'allowed_config_dirs' );
	$ref->setAccessible( true );
	$dirs    = $ref->getValue();
	$dirs[]  = \dirname( __DIR__ );
	$ref->setValue( null, $dirs );
} )();
