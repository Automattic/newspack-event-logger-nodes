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
// Cache_Backend::site() scopes every memcache key by database + base prefix.
// Without these the suite resolves to the shared 'unscoped' namespace, which
// proves nothing about isolation and would collide with any other install in
// the same state the moment a real server is configured.
\define( 'DB_NAME', 'newspack_event_logger_nodes_test' );
$GLOBALS['wpdb'] = new class() {
	public string $prefix      = 'wp_';
	public string $base_prefix = 'wp_';
};

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'http://localhost/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

// Overrides shared shim: add_filter records _wp_test_filters by priority.
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

// Overrides shared shims: array-params request + public-prop responses.
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
// Overrides shared shim: routes keyed by namespace.route, not appended.
if ( ! function_exists( 'register_rest_route' ) ) {
	$GLOBALS['_rest_routes'] = [];
	function register_rest_route( string $namespace, string $route, array $args ): void {
		$GLOBALS['_rest_routes'][ $namespace . $route ] = $args;
	}
}
// Overrides shared shim: single _current_user_can bool, not per-cap map.
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		return $GLOBALS['_current_user_can'] ?? false;
	}
}

// Overrides shared shim: reads _current_user_id, casts to int.
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['_current_user_id'] ?? 0 );
	}
}

// Overrides shared shim: variadic append capture, fixed return slug.
if ( ! function_exists( 'add_menu_page' ) ) {
	function add_menu_page( ...$args ): string {
		$GLOBALS['_admin_menu_pages'][] = $args;
		return 'newspack-nodes';
	}
}

// Overrides shared shim: variadic append capture, fixed return slug.
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( ...$args ): string {
		$GLOBALS['_admin_submenu_pages'][] = $args;
		return 'newspack-nodes-' . ( $args[3] ?? '' );
	}
}

// Overrides shared shim: variadic append (shared keys by handle).
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( ...$args ): void {
		$GLOBALS['_enqueued_scripts'][] = $args;
	}
}

// Overrides shared shim: variadic append (shared keys by handle).
if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( ...$args ): void {
		$GLOBALS['_enqueued_styles'][] = $args;
	}
}

// Overrides shared shim: variadic append (shared keys by handle).
if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( ...$args ): bool {
		$GLOBALS['_localized_scripts'][] = $args;
		return true;
	}
}

// Overrides shared shim: adds the _test_get_option_hook seam.
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
	function update_option( string $key, mixed $value, $autoload = null ): bool {
		$existed                        = array_key_exists( $key, $GLOBALS['_wp_options'] );
		$old                            = $GLOBALS['_wp_options'][ $key ] ?? false;
		$GLOBALS['_wp_options'][ $key ] = $value;
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
	function wp_salt( string $scheme = 'auth' ): string {
		return 'TEST_SALT_FOR_' . $scheme;
	}
}

// Overrides shared shim: 403 when logged in (shared: always 401).
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

if ( ! function_exists( 'wp_hash' ) ) {
	function wp_hash( string $data, string $scheme = 'auth' ): string {
		return \hash( 'sha256', wp_salt( $scheme ) . $data );
	}
}

// Overrides shared shim: pairs with wp_create_nonce, no nonce map.
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): bool {
		return $nonce === ( 'nonce_' . substr( md5( $action ), 0, 10 ) );
	}
}

// Overrides shared shim: derives value from action, no nonce map.
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action, string $name ): void {
		echo '<input type="hidden" name="' . \htmlspecialchars( $name, ENT_QUOTES ) . '" value="nonce_' . \htmlspecialchars( $action, ENT_QUOTES ) . '" />';
	}
}

// Overrides shared shim: throws THIS plugin's RedirectException.
if ( ! function_exists( 'wp_safe_redirect' ) ) {
	// Redirect-then-exit short-circuits the test runner. Throw a sentinel
	// exception instead so each test can catch it explicitly.
	function wp_safe_redirect( string $url ): void {
		$GLOBALS['_last_redirect'] = $url;
		throw new \Newspack_Event_Logger_Nodes\Tests\Helpers\RedirectException( $url );
	}
}

// Overrides shared shim: reads _current_user_login.
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user(): \stdClass {
		$u             = new \stdClass();
		$u->user_login = $GLOBALS['_current_user_login'] ?? '';
		return $u;
	}
}

// Kept local: shared remove_action lives inside its do_action group.
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

// Overrides shared shim: URL-keyed responses + _wp_test_remote_posts.
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

// Overrides shared shim: polymorphic (key,value,url) form like WP core.
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

// Overrides shared shim: _wp_transients store + delete_transient.
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

// Overrides shared shim: false (shared returns true for substrate suite).
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}

if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', '/tmp/test-wp-plugins' );
}

// Canonical shared WP shims from the substrate; local overrides above win.
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/wp-shims.php';

require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/newspack-nodes.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/TopologyDurability.php';
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
// ELN topologies `include` ACROSS the plugin boundary (job-router -> job-intake,
// which the substrate ships), so its topology dir must resolve here too. In
// production Bootstrap::ensure_runtime_wired() registers it; the harness bypasses
// that. Without it every include-bearing topology throws on resolution.
\Newspack_Nodes\Topology_Registry::register_builtin_dir(
	\dirname( __DIR__, 2 ) . '/newspack-nodes/topologies'
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
require_once __DIR__ . '/Helpers/TopologyLockHarness.php';
require_once __DIR__ . '/Helpers/RedirectException.php';
