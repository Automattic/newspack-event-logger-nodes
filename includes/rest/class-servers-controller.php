<?php
/**
 * ServersController:
 *   GET    /servers                  — list configured remote spokes.
 *   POST   /servers                  — register a new remote spoke.
 *   GET    /servers/{id}             — fetch one spoke.
 *   PUT    /servers/{id}             — partial-update one spoke.
 *   DELETE /servers/{id}             — remove a spoke from the registry.
 *   POST   /servers/{id}/test        — probe `/discovery` with stored Basic Auth.
 *
 * IDs validated against `[a-zA-Z0-9_-]{1,64}`, URLs must be HTTPS,
 * credentials capped at 256 bytes, log filenames must match the
 * allowlist `[a-zA-Z0-9_.-]+\.log$`. Storage is delegated to the local
 * ServerRegistry — sodium-secretbox encryption at rest, file-overlay
 * defaults respected (config-file servers can only toggle `enabled`).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Rest;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\ServerRegistry;

class ServersController extends PerformanceControllerBase {
	public const NAMESPACE = 'newspack-nodes/v1';

	public function register_routes(): void {
		\register_rest_route(
			self::NAMESPACE,
			'/servers',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
					'args'                => $this->get_create_args(),
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/servers/(?P<id>[a-zA-Z0-9_-]{1,64})',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
				[
					'methods'             => 'PUT, PATCH',
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
					'args'                => $this->get_update_args(),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'admin_permissions_check' ],
				],
			]
		);

		\register_rest_route(
			self::NAMESPACE,
			'/servers/(?P<id>[a-zA-Z0-9_-]{1,64})/test',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'test_connection' ],
				'permission_callback' => [ $this, 'admin_permissions_check' ],
			]
		);
	}

	public function admin_permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! \function_exists( 'current_user_can' ) || ! \current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'You do not have permission to access this resource.',
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	public function get_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$registry = ServerRegistry::get_instance();
		$registry->reset_cache();

		$response = [];
		foreach ( $registry->get_all() as $id => $config ) {
			$response[ $id ] = [
				'id'              => $id,
				'url'             => (string) ( $config['url'] ?? '' ),
				'enabled'         => (bool) ( $config['enabled'] ?? false ),
				'logs'            => $config['logs'] ?? [],
				'has_credentials' => ! empty( $config['auth_username'] ) && ! empty( $config['auth_password'] ),
				'is_config'       => $registry->is_config_server( (string) $id ),
			];
		}
		return new \WP_REST_Response( $response, 200 );
	}

	public function get_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id       = (string) $request->get_param( 'id' );
		$registry = ServerRegistry::get_instance();
		$registry->reset_cache();
		$server   = $registry->get( $id );
		if ( null === $server ) {
			return $this->not_found_error( "Server not found: {$id}" );
		}
		return new \WP_REST_Response(
			[
				'id'              => $id,
				'url'             => (string) ( $server['url'] ?? '' ),
				'enabled'         => (bool) ( $server['enabled'] ?? false ),
				'logs'            => $server['logs'] ?? [],
				'has_credentials' => ! empty( $server['auth_username'] ) && ! empty( $server['auth_password'] ),
				'is_config'       => $registry->is_config_server( $id ),
			],
			200
		);
	}

	public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (string) $request->get_param( 'id' );
		if ( ! ServerRegistry::is_valid_id( $id ) ) {
			return new \WP_Error( 'invalid_id', 'Invalid server ID format.', [ 'status' => 400 ] );
		}

		$registry = ServerRegistry::get_instance();
		$registry->reset_cache();
		if ( null !== $registry->get( $id ) ) {
			return new \WP_Error( 'already_exists', 'Server with this ID already exists.', [ 'status' => 409 ] );
		}

		$config = [
			'url'           => $request->get_param( 'url' ),
			'auth_username' => $request->get_param( 'auth_username' ),
			'auth_password' => $request->get_param( 'auth_password' ),
			'enabled'       => $request->get_param( 'enabled' ),
			'logs'          => $request->get_param( 'logs' ),
		];

		if ( ! $registry->add( $id, $config ) ) {
			return new \WP_Error(
				'create_failed',
				'Failed to create server. Check URL format (must be HTTPS).',
				[ 'status' => 400 ]
			);
		}

		// Request supervisor restart to pick up new server.
		$this->request_supervisor_restart();

		return new \WP_REST_Response(
			[
				'id'      => $id,
				'message' => 'Server created successfully.',
			],
			201
		);
	}

	public function update_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id       = (string) $request->get_param( 'id' );
		$registry = ServerRegistry::get_instance();
		$registry->reset_cache();
		if ( null === $registry->get( $id ) ) {
			return $this->not_found_error( "Server not found: {$id}" );
		}

		$partial = [];
		foreach ( [ 'url', 'auth_username', 'auth_password', 'enabled', 'logs' ] as $key ) {
			$val = $request->get_param( $key );
			if ( null !== $val ) {
				$partial[ $key ] = $val;
			}
		}

		if ( ! $registry->update( $id, $partial ) ) {
			return new \WP_Error( 'update_failed', 'Failed to update server.', [ 'status' => 400 ] );
		}

		$this->request_supervisor_restart();

		return new \WP_REST_Response(
			[
				'id'      => $id,
				'message' => 'Server updated successfully.',
			],
			200
		);
	}

	public function delete_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id       = (string) $request->get_param( 'id' );
		$registry = ServerRegistry::get_instance();
		$registry->reset_cache();
		if ( null === $registry->get( $id ) ) {
			return $this->not_found_error( "Server not found: {$id}" );
		}
		if ( ! $registry->remove( $id ) ) {
			return new \WP_Error( 'delete_failed', 'Failed to delete server.', [ 'status' => 500 ] );
		}

		$this->request_supervisor_restart();

		return new \WP_REST_Response(
			[
				'id'      => $id,
				'message' => 'Server deleted successfully.',
			],
			200
		);
	}

	public function test_connection( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id       = (string) $request->get_param( 'id' );
		$registry = ServerRegistry::get_instance();
		$registry->reset_cache();
		$server   = $registry->get( $id );
		if ( null === $server ) {
			return $this->not_found_error( "Server not found: {$id}" );
		}

		// Application Config — `self::load_config()` (PerformanceControllerBase)
		// only sees substrate keys, so aggregator_verify_ssl / aggregator_require_https
		// from the application config file would never reach this call. The
		// settings UI's "Test" probe has to honour the same SSL policy as the
		// running StreamMerger or operators get false negatives.
		$config       = Config::load_config( 'full' );
		$verify_ssl   = ! isset( $config['aggregator_verify_ssl'] ) || (bool) $config['aggregator_verify_ssl'];
		$url          = \rtrim( (string) $server['url'], '/' ) . '/wp-json/newspack-nodes/v1/discovery';
		$request_args = [
			// 5s is the bound on a synchronous Test-button probe — the admin
			// UI blocks on it. Default 1s misses real spokes on slow links.
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			'timeout'             => 5,
			'sslverify'           => $verify_ssl,
			'redirection'         => 0,
			'limit_response_size' => 1048576,
		];

		$username = (string) ( $server['auth_username'] ?? '' );
		$password = (string) ( $server['auth_password'] ?? '' );
		if ( '' !== $username && '' !== $password ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth.
			$request_args['headers']['Authorization'] = 'Basic ' . \base64_encode( $username . ':' . $password );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Aggregators legitimately probe internal endpoints.
		$response = \wp_remote_get( $url, $request_args );

		if ( $response instanceof \WP_Error ) {
			return new \WP_Error( 'connection_failed', 'Could not connect to server.', [ 'status' => 502 ] );
		}

		$code = \wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'connection_failed', "HTTP {$code} response.", [ 'status' => 502 ] );
		}

		$body = \json_decode( \wp_remote_retrieve_body( $response ), true, 16 );
		if ( ! \is_array( $body ) ) {
			return new \WP_Error( 'invalid_response', 'Server returned non-JSON response.', [ 'status' => 502 ] );
		}

		// Whitelist what we surface so we never proxy arbitrary remote JSON.
		$safe = [];
		if ( isset( $body['registered_hooks'] ) && \is_array( $body['registered_hooks'] ) ) {
			$safe['registered_hooks'] = \array_values(
				\array_map( 'sanitize_text_field', \array_filter( $body['registered_hooks'], 'is_string' ) )
			);
		}
		if ( isset( $body['custom_events'] ) && \is_array( $body['custom_events'] ) ) {
			$safe['custom_events'] = \array_values(
				\array_map( 'sanitize_text_field', \array_filter( $body['custom_events'], 'is_string' ) )
			);
		}
		if ( isset( $body['lag'] ) ) {
			$safe['lag'] = (int) $body['lag'];
		}

		return new \WP_REST_Response(
			[
				'id'       => $id,
				'status'   => 'connected',
				'response' => $safe,
			],
			200
		);
	}

	// ---------------------------------------------------------------------
	// Args / validation
	// ---------------------------------------------------------------------

	private function get_create_args(): array {
		return [
			'id'            => [
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'url'           => [
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => [ $this, 'validate_url_required' ],
				'sanitize_callback' => 'esc_url_raw',
			],
			'auth_username' => [
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'auth_password' => [
				'required'          => false,
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => [ $this, 'sanitize_password' ],
			],
			'enabled'       => [
				'required' => false,
				'type'     => 'boolean',
				'default'  => true,
			],
			'logs'          => [
				'required'          => false,
				'type'              => 'array',
				'default'           => [ 'firehose.log' ],
				'validate_callback' => [ $this, 'validate_logs' ],
			],
		];
	}

	private function get_update_args(): array {
		return [
			'url'           => [
				'required'          => false,
				'type'              => 'string',
				'validate_callback' => [ $this, 'validate_url_optional' ],
				'sanitize_callback' => 'esc_url_raw',
			],
			'auth_username' => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'auth_password' => [
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_password' ],
			],
			'enabled'       => [
				'required' => false,
				'type'     => 'boolean',
			],
			'logs'          => [
				'required'          => false,
				'type'              => 'array',
				'validate_callback' => [ $this, 'validate_logs' ],
			],
		];
	}

	public function validate_url_required( mixed $value ): bool|\WP_Error {
		if ( empty( $value ) ) {
			return new \WP_Error( 'rest_invalid_param', 'URL is required.', [ 'status' => 400 ] );
		}
		return $this->check_url_format( (string) $value );
	}

	public function validate_url_optional( mixed $value ): bool|\WP_Error {
		if ( empty( $value ) ) {
			return true;
		}
		return $this->check_url_format( (string) $value );
	}

	private function check_url_format( string $value ): bool|\WP_Error {
		if ( 0 !== \strpos( $value, 'https://' ) ) {
			return new \WP_Error( 'rest_invalid_param', 'URL must use HTTPS.', [ 'status' => 400 ] );
		}
		$sanitized = \esc_url_raw( $value );
		if ( '' === $sanitized ) {
			return new \WP_Error( 'rest_invalid_param', 'Invalid URL format.', [ 'status' => 400 ] );
		}
		return true;
	}

	public function validate_logs( mixed $logs ): bool {
		if ( ! \is_array( $logs ) ) {
			return false;
		}
		foreach ( $logs as $log ) {
			if ( ! \is_string( $log ) || 1 !== \preg_match( '/^[a-zA-Z0-9_.-]+\.log$/', $log ) ) {
				return false;
			}
		}
		return true;
	}

	public function sanitize_password( mixed $value ): string {
		if ( ! \is_string( $value ) ) {
			return '';
		}
		$value = \preg_replace( '/[\x00-\x1f\x7f]/', '', $value ) ?? '';
		if ( \strlen( $value ) > 256 ) {
			$value = \substr( $value, 0, 256 );
		}
		return $value;
	}

	/**
	 * Trip the supervisor restart flag if a Lock dir exists for it. Best-effort —
	 * a missing supervisor doesn't fail the request.
	 */
	private function request_supervisor_restart(): void {
		try {
			$config     = self::load_config();
			$base_dir   = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
			$lock_dir   = $base_dir . '/locks/supervisor.lock.d';
			if ( \is_dir( $lock_dir ) ) {
				\Newspack_Nodes\Lock::request_restart_at( $lock_dir );
			}
		} catch ( \Throwable $e ) {
			// Best-effort — log handled at higher levels.
		}
	}
}
