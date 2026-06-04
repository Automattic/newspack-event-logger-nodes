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
use Newspack_Event_Logger_Nodes\Remote_Manager;
use Newspack_Event_Logger_Nodes\Server_Registry;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

class Servers_CI_Node extends Service_CI_Node {

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
	 * Hub-side server registry the six verb handlers reach via
	 * `$self->registry`. Public so the bootstrap (or test) assigns it AFTER
	 * `new Servers_CI_Node()` — the Tachikoma uniform-construction pattern
	 * (`make_node` calls a no-arg ctor; programmatic deps come in via public
	 * properties, not constructor args, since `arguments()` only handles
	 * scalar config). node_schema() is static and can't `use` an instance
	 * field, so handlers read the assigned value off `$self` at dispatch
	 * time (legal: they're defined inside this class and so may touch its
	 * props on any instance).
	 *
	 * Nullable + default null so a freshly-constructed interpreter is in a known
	 * state until the bootstrap wires up the dep; verb handlers that
	 * dereference `$self->registry` will fail loud if the bootstrap forgot
	 * to assign it, rather than constructing into uninitialised-property UB.
	 *
	 * @var Server_Registry|null
	 */
	public ?Server_Registry $registry = null;

	public static function node_schema(): array {
		return [
			'category'    => 'Service',
			'description' => 'Hub-side server registry: list / get / add / update / delete / test spokes.',
			'arguments'        => [],
			'commands'       => [
				[
					'name'        => 'list',
					'description' => 'All registered servers as a map keyed by id.',
					'args'        => [],
					// $self is the dispatching interpreter instance — always a Servers_CI_Node
					// here (dispatch() passes $this), so it's typed concretely to read
					// the ctor-injected registry off it (node_schema is static).
					'handler'     => static function ( Servers_CI_Node $self, string $args, array $envelope = [] ): array {
						$registry = $self->require_registry();
						$registry->reset_cache();
						$out = [];
						foreach ( $registry->get_all() as $id => $config ) {
							$out[ $id ] = self::public_shape( (string) $id, $config, $registry );
						}
						return $out;
					},
				],
				[
					'name'        => 'get',
					'description' => 'A single server record by id.',
					'args'        => [
						[ 'name' => 'id', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Servers_CI_Node $self, string $args, array $envelope = [] ): array {
						$registry = $self->require_registry();
						$id       = self::positional_id( $args );
						$registry->reset_cache();
						$server = $registry->get( $id );
						if ( null === $server ) {
							throw new \RuntimeException( \esc_html( "server not found: {$id}" ) );
						}
						return self::public_shape( $id, $server, $registry );
					},
				],
				[
					'name'        => 'add',
					'description' => 'Add a new server (manage_options).',
					'args'        => [
						[ 'name' => 'id', 'type' => 'string', 'required' => true ],
						[ 'name' => 'url', 'type' => 'string', 'required' => true ],
						[ 'name' => 'auth_username', 'type' => 'string', 'required' => false ],
						[ 'name' => 'auth_password', 'type' => 'string', 'required' => false ],
						[ 'name' => 'enabled', 'type' => 'bool', 'required' => false, 'default' => true ],
						[ 'name' => 'logs', 'type' => 'json', 'required' => false, 'default' => [ 'firehose.log' ] ],
					],
					'handler'     => static function ( Servers_CI_Node $self, string $args, array $envelope = [] ): array {
						self::require_manage_options();
						$parsed = Command_Args::parse( $args );
						$opts   = $parsed['options'];
						$id     = $parsed['positional'][0] ?? '';
						// Empty + format check together: legacy controller maps both to
						// HTTP 400 with the same kind of "invalid id" message.
						if ( ! Server_Registry::is_valid_id( $id ) ) {
							throw new \RuntimeException( 'invalid server id' );
						}
						$registry = $self->require_registry();
						$registry->reset_cache();
						if ( null !== $registry->get( $id ) ) {
							throw new \RuntimeException( \esc_html( "server already exists: {$id}" ) );
						}
						$config = self::extract_server_config( $opts );
						if ( ! $registry->add( $id, $config ) ) {
							// Registry rejected on validate_config (non-HTTPS URL,
							// missing url, etc.) or hit MAX_SERVERS.
							throw new \RuntimeException( 'add failed: check URL format (must be HTTPS) and registry capacity' );
						}
						if ( true === ( $config['enabled'] ?? true ) ) {
							self::queue_settings_sync( [ $id ] );
						}
						self::request_supervisor_restart();
						return [ 'id' => $id ];
					},
				],
				[
					'name'        => 'update',
					'description' => 'Partial-update of an existing server (manage_options).',
					'args'        => [
						[ 'name' => 'id', 'type' => 'string', 'required' => true ],
						[ 'name' => 'url', 'type' => 'string', 'required' => false ],
						[ 'name' => 'auth_username', 'type' => 'string', 'required' => false ],
						[ 'name' => 'auth_password', 'type' => 'string', 'required' => false ],
						[ 'name' => 'enabled', 'type' => 'bool', 'required' => false ],
						[ 'name' => 'logs', 'type' => 'json', 'required' => false ],
					],
					'handler'     => static function ( Servers_CI_Node $self, string $args, array $envelope = [] ): array {
						self::require_manage_options();
						$parsed = Command_Args::parse( $args );
						$id     = $parsed['positional'][0] ?? '';
						if ( '' === $id ) {
							throw new \RuntimeException( 'id required' );
						}
						$registry = $self->require_registry();
						$registry->reset_cache();
						$existing = $registry->get( $id );
						if ( null === $existing ) {
							throw new \RuntimeException( \esc_html( "server not found: {$id}" ) );
						}
						// Partial update: only options actually present in the args
						// string are applied; an absent --key leaves the stored field
						// untouched. enabled/logs are typed by partial_config().
						$partial = self::partial_config( $parsed['options'] );
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
						return [ 'id' => $id ];
					},
				],
				[
					'name'        => 'delete',
					'description' => 'Remove a server (manage_options).',
					'args'        => [
						[ 'name' => 'id', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Servers_CI_Node $self, string $args, array $envelope = [] ): array {
						self::require_manage_options();
						$registry = $self->require_registry();
						$id       = self::positional_id( $args );
						$registry->reset_cache();
						if ( null === $registry->get( $id ) ) {
							throw new \RuntimeException( \esc_html( "server not found: {$id}" ) );
						}
						if ( ! $registry->remove( $id ) ) {
							// Config-file servers reach here.
							throw new \RuntimeException( 'delete failed' );
						}
						self::request_supervisor_restart();
						return [ 'id' => $id ];
					},
				],
				[
					'name'        => 'test',
					'description' => "Probe a spoke's /command discovery endpoint with stored Basic Auth (manage_options).",
					'args'        => [
						[ 'name' => 'id', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Servers_CI_Node $self, string $args, array $envelope = [] ): array {
						self::require_manage_options();
						$registry = $self->require_registry();
						$id       = self::positional_id( $args );
						$registry->reset_cache();
						$server = $registry->get( $id );
						if ( null === $server ) {
							throw new \RuntimeException( \esc_html( "server not found: {$id}" ) );
						}
						// Returns a structure. (The cross-spoke /command HTTP body
						// probe_remote() builds internally is a separate wire concern
						// and stays JSON-encoded — don't conflate the two.)
						return self::probe_remote( $id, $server );
					},
				],
			],
		];
	}

	/**
	 * Public-API view of a single server record. Strips credentials and adds
	 * computed `has_credentials` + `is_config` flags. Mirrors the legacy
	 * controller's per-server response shape exactly.
	 *
	 * @param string         $id       Server id.
	 * @param array<string, mixed>          $config   Decrypted server config from the registry.
	 * @param Server_Registry $registry Registry for the `is_config_server` lookup.
	 * @return array<string, mixed> Public-safe representation of the server.
	 */
	/**
	 * Narrow the bootstrap-injected `$registry` to non-null, failing loud when
	 * the bootstrap never wired it up (the documented contract for the
	 * registry-backed verbs).
	 */
	public function require_registry(): Server_Registry {
		if ( null === $this->registry ) {
			throw new \RuntimeException( 'server registry not wired up' );
		}
		return $this->registry;
	}

	/**
	 * Project a stored server config into its public dashboard shape.
	 *
	 * @param string               $id       Server id.
	 * @param array<string, mixed> $config   Stored server config.
	 * @param Server_Registry      $registry Backing registry.
	 * @return array<string, mixed> Public server record.
	 */
	private static function public_shape( string $id, array $config, Server_Registry $registry ): array {
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
	 * Pull the single required positional id out of the args string, throwing
	 * 'id required' when absent. Used by get/delete/test/update.
	 *
	 * @param string $args Verb arguments string.
	 * @return string Server id.
	 */
	private static function positional_id( string $args ): string {
		$id = Command_Args::parse( $args )['positional'][0] ?? '';
		if ( '' === $id ) {
			throw new \RuntimeException( 'id required' );
		}
		return $id;
	}

	/**
	 * Build the canonical full server-config blob from `add`'s parsed options,
	 * defaulting missing fields to the same shape `validate_config` expects.
	 * enabled defaults true; logs default to ['firehose.log']; a `--logs=<csv>`
	 * comma-list becomes an array.
	 *
	 * @param array<string,string|true> $opts Parsed `--key=value` options.
	 * @return array<string, mixed> Server-config blob ready for registry->add().
	 */
	private static function extract_server_config( array $opts ): array {
		return [
			'url'           => (string) ( $opts['url']           ?? '' ),
			'auth_username' => (string) ( $opts['auth_username'] ?? '' ),
			'auth_password' => (string) ( $opts['auth_password'] ?? '' ),
			'enabled'       => self::option_bool( $opts, 'enabled', true ),
			'logs'          => isset( $opts['logs'] ) ? self::option_logs( $opts['logs'] ) : [ 'firehose.log' ],
		];
	}

	/**
	 * Build the partial-update blob from `update`'s parsed options: only the
	 * keys ACTUALLY PRESENT in $opts are included, so an absent --key leaves the
	 * stored field untouched. enabled is coerced to a real bool, logs to an
	 * array; the rest stay strings.
	 *
	 * @param array<string,string|true> $opts Parsed `--key=value` options.
	 * @return array<string, mixed> Partial config for registry->update().
	 */
	private static function partial_config( array $opts ): array {
		$partial = [];
		foreach ( [ 'url', 'auth_username', 'auth_password' ] as $key ) {
			if ( isset( $opts[ $key ] ) ) {
				$partial[ $key ] = (string) $opts[ $key ];
			}
		}
		if ( isset( $opts['enabled'] ) ) {
			$partial['enabled'] = self::option_bool( $opts, 'enabled', true );
		}
		if ( isset( $opts['logs'] ) ) {
			$partial['logs'] = self::option_logs( $opts['logs'] );
		}
		return $partial;
	}

	/**
	 * Coerce a `--enabled=<true|false>` option to a real bool. A bare `--enabled`
	 * flag (parsed as `true`) reads as true; only `0`/`false` (any case) read as
	 * false; an absent key falls back to $default. Mirrors the substrate bool
	 * grammar so `(bool) 'false'` (which PHP would read as TRUE) can't slip in.
	 *
	 * @param array<string,string|true> $opts    Parsed options.
	 * @param string                    $key     Option name.
	 * @param bool                      $default Fallback when absent.
	 */
	private static function option_bool( array $opts, string $key, bool $default ): bool {
		if ( ! isset( $opts[ $key ] ) ) {
			return $default;
		}
		return ! \in_array( \strtolower( (string) $opts[ $key ] ), [ '0', 'false' ], true );
	}

	/**
	 * Split a `--logs=<csv>` comma-list option into an array of trimmed names.
	 * A bare `--logs` flag (parsed as `true`) yields an empty list.
	 *
	 * @param string|true $value Raw option value.
	 * @return list<string>
	 */
	private static function option_logs( $value ): array {
		if ( true === $value || '' === $value ) {
			return [];
		}
		return \array_values( \array_filter( \array_map( '\trim', \explode( ',', (string) $value ) ), static fn ( string $s ): bool => '' !== $s ) );
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
			Remote_Manager::queue_sync_all_settings( $server_ids );
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
			$base_dir = RuntimeConfig::get_base_directory();
			$lock_dir = $base_dir . '/locks/supervisor.lock.d';
			if ( \is_dir( $lock_dir ) ) {
				\Newspack_Nodes\Lock_Node::request_restart_at( $lock_dir );
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
	 * @param array<string, mixed>  $server Decrypted server config from the registry.
	 * @return array<string, mixed> Sanitised probe response.
	 */
	private static function probe_remote( string $id, array $server ): array {
		$app_config = AppConfig::load_config();
		$verify_ssl = ! isset( $app_config['aggregator_verify_ssl'] ) || (bool) $app_config['aggregator_verify_ssl'];

		// M5 deleted the legacy `/discovery` REST route; the discovery surface
		// is now a `discovery.get` command dispatched via `/command`. Build the
		// body through the shared RemoteManager builder so the manual Test
		// probe and the periodic health-check probe can't drift.
		$url  = \rtrim( (string) $server['url'], '/' ) . '/wp-json/newspack-nodes/v1/command';
		$args = [
			// 5s bound on a synchronous Test-button probe — admin UI blocks on
			// it. Default 1s misses real spokes on slow links (legacy parity).
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
			'timeout'             => 5,
			'sslverify'           => $verify_ssl,
			'redirection'         => 0,
			'limit_response_size' => 1048576,
			'headers'             => [ 'Content-Type' => Remote_Manager::COMMAND_CONTENT_TYPE ],
			'body'                => Remote_Manager::command_message_body( 'discovery', 'get', '' ),
		];

		$username = (string) ( $server['auth_username'] ?? '' );
		$password = (string) ( $server['auth_password'] ?? '' );
		if ( '' !== $username && '' !== $password ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth.
			$args['headers']['Authorization'] = 'Basic ' . \base64_encode( $username . ':' . $password );
		}

		$call     = self::$http_call ?? static fn( string $u, array $a ): mixed =>
			\wp_remote_post( $u, $a );
		$response = $call( $url, $args );

		if ( $response instanceof \WP_Error ) {
			throw new \RuntimeException( 'could not connect to server' );
		}

		$code = \wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new \RuntimeException( \esc_html( "HTTP {$code} response from server" ) );
		}

		// Response is a packed Message whose VALUE is the structured
		// `{name, payload}` LIVE array — the whole-Message JSON is the only
		// serialization boundary, so ONE decode of the body yields a nested
		// array and `payload` is read directly with NO second decode.
		// Mirrors CommandInterpreter::interpret() and the JS-side
		// `unwrapCommandResponse` helper.
		$envelope = \json_decode( \wp_remote_retrieve_body( $response ), true, 16 );
		if ( ! \is_array( $envelope ) || ! \array_key_exists( \Newspack_Nodes\Message::VALUE, $envelope ) ) {
			throw new \RuntimeException( 'server returned malformed command envelope' );
		}
		if ( (int) ( $envelope[ \Newspack_Nodes\Message::TYPE ] ?? 0 ) & \Newspack_Nodes\Message::TM_ERROR ) {
			throw new \RuntimeException( 'server returned TM_ERROR for discovery probe' );
		}
		$value = $envelope[ \Newspack_Nodes\Message::VALUE ];
		if ( ! \is_array( $value ) || ! \array_key_exists( 'payload', $value ) ) {
			throw new \RuntimeException( 'server returned malformed command response' );
		}
		$payload = $value['payload'];
		$body    = '' === $payload ? [] : $payload;
		if ( ! \is_array( $body ) ) {
			throw new \RuntimeException( 'server returned non-JSON discovery payload' );
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
