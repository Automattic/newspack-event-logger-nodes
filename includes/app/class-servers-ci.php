<?php
/**
 * Servers_CI: command-dispatch for the hub-side server registry.
 *
 * Replaces legacy class-servers-controller.php with a CommandInterpreter
 * that mounts at priority 11 alongside the rest of the M2 service CIs.
 *
 * Verbs:
 *   list   — all registered servers as a map keyed by id.
 *   get    — a single server record by id.
 *   add    — add a new server. Auth-gated on manage_options.
 *   update — partial-update of an existing server. Auth-gated on manage_options.
 *   delete — remove a server. Auth-gated on manage_options.
 *   test   — probe the remote server's /discovery endpoint with stored Basic
 *            Auth, return a sanitised subset of the response. Auth-gated on
 *            manage_options.
 *
 * Value-equivalence with the legacy controller: same `{id, url, enabled,
 * logs, has_credentials, is_config}` shape on list/get, same id-format
 * validation through ServerRegistry::is_valid_id, same auth requirement
 * (manage_options) on mutating verbs, same HTTPS-only URL constraint
 * (enforced inside ServerRegistry::validate_config).
 *
 * Mutations trigger a supervisor-restart flag write so the new/changed
 * server is picked up without waiting for the worker's natural respawn
 * (~10 min). When a new server is added enabled, OR an existing server
 * flips enabled false → true, RemoteManager::queue_sync_all_settings()
 * is fired so the spoke catches up on the hub's current settings without
 * waiting for the next HealthCheckTick. Both side-effects are best-effort
 * — failures are swallowed (legacy parity: the REST endpoint never failed
 * on those).
 *
 * Test seam: `Servers_CI::$http_call` is a static `\Closure` that defaults
 * to `\wp_remote_get` at the call site. Tests reassign in their bootstrap
 * to capture without short-circuiting the rest of the URL composition +
 * response-classification path, so the suite exercises the actual
 * production header / response-code / JSON-decode logic. See
 * `~/.claude/rules/test-seams.md` for the canonical pattern.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Config as AppConfig;
use Newspack_Event_Logger_Nodes\RemoteManager;
use Newspack_Event_Logger_Nodes\ServerRegistry;
use Newspack_Nodes\CommandInterpreter;
use Newspack_Nodes\Config as RuntimeConfig;

\defined( 'ABSPATH' ) || exit;

class Servers_CI extends CommandInterpreter {

	/**
	 * `wp_remote_get` seam used by the `test` verb. Lazily-defaulted to a
	 * closure that wraps the real WordPress call (can't default a Closure
	 * on a class property — must be a constant expression). Tests reassign
	 * to capture outbound args + inject canned responses.
	 *
	 * Signature: `function ( string $url, array $args ): array|\WP_Error`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $http_call = null;

	/**
	 * Build a Servers_CI bound to the supplied registry.
	 *
	 * @param ServerRegistry $registry Hub-side server registry. Tests pass a
	 *                                  fresh instance per test so they don't
	 *                                  share state.
	 */
	public function __construct( ServerRegistry $registry ) {
		// Node + CommandInterpreter have no explicit __construct, so the
		// inherited no-op is implicit. Mirrors Workers_CI / Settings_CI /
		// Status_CI / Discovery_CI / Logger_CI / Events_CI.
		$this->commands( $this->verb_table( $registry ) );
	}

	private function verb_table( ServerRegistry $registry ): array {
		return [
			'list'   => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $registry ): string {
				$registry->reset_cache();
				$out = [];
				foreach ( $registry->get_all() as $id => $config ) {
					$out[ $id ] = self::public_shape( (string) $id, $config, $registry );
				}
				return (string) \wp_json_encode( $out );
			},
			'get'    => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $registry ): string {
				$id = self::decoded_id( $args );
				$registry->reset_cache();
				$server = $registry->get( $id );
				if ( null === $server ) {
					throw new \RuntimeException( \esc_html( "server not found: {$id}" ) );
				}
				return (string) \wp_json_encode( self::public_shape( $id, $server, $registry ) );
			},
			'add'    => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $registry ): string {
				self::require_manage_options();
				$decoded = self::decoded_args( $args );
				$id      = (string) ( $decoded['id'] ?? '' );
				// Empty + format check together: legacy controller maps both to
				// HTTP 400 with the same kind of "invalid id" message.
				if ( ! ServerRegistry::is_valid_id( $id ) ) {
					throw new \RuntimeException( 'invalid server id' );
				}
				$registry->reset_cache();
				if ( null !== $registry->get( $id ) ) {
					throw new \RuntimeException( \esc_html( "server already exists: {$id}" ) );
				}
				$config = self::extract_server_config( $decoded );
				if ( ! $registry->add( $id, $config ) ) {
					// Registry rejected on validate_config (non-HTTPS URL,
					// missing url, etc.) or hit MAX_SERVERS.
					throw new \RuntimeException( 'add failed: check URL format (must be HTTPS) and registry capacity' );
				}
				if ( true === ( $config['enabled'] ?? true ) ) {
					self::queue_settings_sync( [ $id ] );
				}
				self::request_supervisor_restart();
				return (string) \wp_json_encode( [ 'id' => $id ] );
			},
			'update' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $registry ): string {
				self::require_manage_options();
				$decoded = self::decoded_args( $args );
				$id      = self::require_id( $decoded );
				$registry->reset_cache();
				$existing = $registry->get( $id );
				if ( null === $existing ) {
					throw new \RuntimeException( \esc_html( "server not found: {$id}" ) );
				}
				$partial = \array_intersect_key(
					$decoded,
					\array_flip( [ 'url', 'auth_username', 'auth_password', 'enabled', 'logs' ] )
				);
				if ( ! $registry->update( $id, $partial ) ) {
					throw new \RuntimeException( 'update failed' );
				}
				// Targeted full-settings sweep when `enabled` flips false → true.
				$was_enabled = true === ( $existing['enabled'] ?? false );
				$now_enabled = isset( $partial['enabled'] ) && true === $partial['enabled'];
				if ( ! $was_enabled && $now_enabled ) {
					self::queue_settings_sync( [ $id ] );
				}
				self::request_supervisor_restart();
				return (string) \wp_json_encode( [ 'id' => $id ] );
			},
			'delete' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $registry ): string {
				self::require_manage_options();
				$id = self::decoded_id( $args );
				$registry->reset_cache();
				if ( null === $registry->get( $id ) ) {
					throw new \RuntimeException( \esc_html( "server not found: {$id}" ) );
				}
				if ( ! $registry->remove( $id ) ) {
					// Config-file servers reach here.
					throw new \RuntimeException( 'delete failed' );
				}
				self::request_supervisor_restart();
				return (string) \wp_json_encode( [ 'id' => $id ] );
			},
			'test'   => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $registry ): string {
				self::require_manage_options();
				$id = self::decoded_id( $args );
				$registry->reset_cache();
				$server = $registry->get( $id );
				if ( null === $server ) {
					throw new \RuntimeException( \esc_html( "server not found: {$id}" ) );
				}
				return (string) \wp_json_encode( self::probe_remote( $id, $server ) );
			},
		];
	}

	/**
	 * Public-API view of a single server record. Strips credentials and adds
	 * computed `has_credentials` + `is_config` flags. Mirrors the legacy
	 * controller's per-server response shape exactly.
	 *
	 * @param string         $id       Server id.
	 * @param array          $config   Decrypted server config from the registry.
	 * @param ServerRegistry $registry Registry for the `is_config_server` lookup.
	 * @return array Public-safe representation of the server.
	 */
	private static function public_shape( string $id, array $config, ServerRegistry $registry ): array {
		return [
			'id'              => $id,
			'url'             => (string) ( $config['url'] ?? '' ),
			'enabled'         => (bool) ( $config['enabled'] ?? false ),
			'logs'            => $config['logs'] ?? [],
			'has_credentials' => ! empty( $config['auth_username'] ) && ! empty( $config['auth_password'] ),
			'is_config'       => $registry->is_config_server( $id ),
		];
	}

	/**
	 * Decode a verb's JSON args; tolerates empty/malformed input by returning
	 * an empty array (matches the rest of the M2 CIs).
	 *
	 * @param string $args Raw JSON argument blob from the wire.
	 * @return array Decoded arguments.
	 */
	private static function decoded_args( string $args ): array {
		if ( '' === $args ) {
			return [];
		}
		$decoded = \json_decode( $args, true );
		return \is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Pull the `id` field out of a verb's args, throwing if missing.
	 *
	 * @param string $args Raw JSON argument blob from the wire.
	 * @return string Server id.
	 */
	private static function decoded_id( string $args ): string {
		return self::require_id( self::decoded_args( $args ) );
	}

	/**
	 * Pull the `id` field out of an already-decoded argument array, throwing
	 * if missing. Used by verbs that need the rest of the decoded args too
	 * (`update`), so they don't decode twice.
	 *
	 * @param array $decoded Decoded verb arguments.
	 * @return string Server id.
	 */
	private static function require_id( array $decoded ): string {
		$id = (string) ( $decoded['id'] ?? '' );
		if ( '' === $id ) {
			throw new \RuntimeException( 'id required' );
		}
		return $id;
	}

	/**
	 * Pull the canonical server-config keys out of a verb's args, defaulting
	 * missing fields to the same shape `validate_config` expects.
	 *
	 * @param array $decoded Decoded verb arguments.
	 * @return array Server-config blob ready for registry->add().
	 */
	private static function extract_server_config( array $decoded ): array {
		return [
			'url'           => (string) ( $decoded['url']           ?? '' ),
			'auth_username' => (string) ( $decoded['auth_username'] ?? '' ),
			'auth_password' => (string) ( $decoded['auth_password'] ?? '' ),
			'enabled'       => $decoded['enabled'] ?? true,
			'logs'          => $decoded['logs']    ?? [ 'firehose.log' ],
		];
	}

	/**
	 * Authorisation gate for the four mutating verbs. Matches the legacy
	 * controller's `admin_permissions_check`. Thrown errors are caught by
	 * `CommandInterpreter::interpret()` and turned into TM_COMMAND|TM_ERROR.
	 */
	private static function require_manage_options(): void {
		if ( \function_exists( 'current_user_can' ) && ! \current_user_can( 'manage_options' ) ) {
			throw new \RuntimeException( 'permission denied: manage_options required' );
		}
	}

	/**
	 * Best-effort fan-out trigger: queue a targeted full-settings sync for
	 * the given spoke ids. Wrapped in a Throwable catch so a missing-class
	 * or transient SettingsSync failure doesn't fail the verb (matches the
	 * legacy controller's silent best-effort behaviour).
	 *
	 * @param string[] $server_ids Spoke ids to sync to.
	 */
	private static function queue_settings_sync( array $server_ids ): void {
		try {
			RemoteManager::queue_sync_all_settings( $server_ids );
		} catch ( \Throwable $e ) {
			// Swallow — legacy parity: the REST endpoint never failed on this.
		}
	}

	/**
	 * Best-effort: trip the supervisor restart flag if a Lock dir exists for
	 * it. Wrapped in a Throwable catch so a missing supervisor or a stripped
	 * runtime doesn't fail the verb (matches the legacy controller).
	 */
	private static function request_supervisor_restart(): void {
		try {
			$config   = RuntimeConfig::load_config();
			$base_dir = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
			$lock_dir = $base_dir . '/locks/supervisor.lock.d';
			if ( \is_dir( $lock_dir ) ) {
				\Newspack_Nodes\Lock::request_restart_at( $lock_dir );
			}
		} catch ( \Throwable $e ) {
			// Swallow — best-effort signalling.
		}
	}

	/**
	 * HTTP probe of a remote spoke's /discovery endpoint with stored Basic
	 * Auth. Returns the legacy controller's response shape:
	 *   { id, status: 'connected', response: {registered_hooks, custom_events, lag} }
	 * Throws a RuntimeException with a short error string on any failure
	 * (WP_Error, non-200, non-JSON body).
	 *
	 * @param string $id     Server id.
	 * @param array  $server Decrypted server config from the registry.
	 * @return array Sanitised probe response.
	 */
	private static function probe_remote( string $id, array $server ): array {
		$app_config = AppConfig::load_config();
		$verify_ssl = ! isset( $app_config['aggregator_verify_ssl'] ) || (bool) $app_config['aggregator_verify_ssl'];

		$url  = \rtrim( (string) $server['url'], '/' ) . '/wp-json/newspack-nodes/v1/discovery';
		$args = [
			// 5s bound on a synchronous Test-button probe — admin UI blocks on
			// it. Default 1s misses real spokes on slow links (legacy parity).
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
			$args['headers']['Authorization'] = 'Basic ' . \base64_encode( $username . ':' . $password );
		}

		$call     = self::$http_call ?? static fn( string $u, array $a ): mixed =>
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Hub legitimately probes internal endpoints (legacy parity).
			\wp_remote_get( $u, $a );
		$response = $call( $url, $args );

		if ( $response instanceof \WP_Error ) {
			throw new \RuntimeException( 'could not connect to server' );
		}

		$code = \wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new \RuntimeException( \esc_html( "HTTP {$code} response from server" ) );
		}

		$body = \json_decode( \wp_remote_retrieve_body( $response ), true, 16 );
		if ( ! \is_array( $body ) ) {
			throw new \RuntimeException( 'server returned non-JSON response' );
		}

		// Whitelist what we surface so we never proxy arbitrary remote JSON.
		// Same fields the legacy controller exposed.
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

		return [
			'id'       => $id,
			'status'   => 'connected',
			'response' => $safe,
		];
	}
}
