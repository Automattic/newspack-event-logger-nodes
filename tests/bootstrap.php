<?php
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
	$GLOBALS['_wp_actions'] = [];
	function do_action( string $hook, ...$args ): void {
		foreach ( $GLOBALS['_wp_actions'][ $hook ] ?? [] as $cb ) {
			$cb( ...$args );
		}
	}
	function add_action( string $hook, callable $cb ): void {
		$GLOBALS['_wp_actions'][ $hook ][] = $cb;
	}
	function apply_filters( string $hook, mixed $value, ...$args ): mixed {
		foreach ( $GLOBALS['_wp_actions'][ $hook ] ?? [] as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
	function add_filter( string $hook, callable $cb ): void {
		$GLOBALS['_wp_actions'][ $hook ][] = $cb;
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

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return \preg_replace( '/[^A-Za-z0-9._\-]/', '', $name ) ?? '';
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = [] ): mixed {
		// Tests can override via $GLOBALS['_wp_test_remote_responses'] keyed by URL.
		if ( isset( $GLOBALS['_wp_test_remote_responses'][ $url ] ) ) {
			return $GLOBALS['_wp_test_remote_responses'][ $url ];
		}
		return new \WP_Error( 'no_stub', 'wp_remote_get default stub' );
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
