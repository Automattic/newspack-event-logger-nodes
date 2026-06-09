<?php
/**
 * Remote Manager
 *
 * Hub-side fan-out worker that reaches into remote spokes via JobIntake handlers
 * and periodic health-check sweeps.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remote Manager class.
 */
class Remote_Manager {
	/**
	 * Maximum number of servers to process in a single job. Prevents unbounded
	 * loops when a tampered registry returns thousands of entries.
	 */
	public const MAX_SERVERS = 100;

	/**
	 * HTTP request timeout in seconds.
	 */
	public const REQUEST_TIMEOUT = 15;

	/**
	 * Job staleness threshold in seconds. Must exceed health-check cadence
	 * (~300s) so a slow cron tick doesn't race-to-drop a still-relevant job.
	 */
	public const STALE_THRESHOLD = 600;

	/**
	 * Maximum number of settings to sync in a single pass. Caps unbounded
	 * iteration from filter abuse.
	 */
	public const MAX_SETTINGS = 50;

	/**
	 * REST path for the substrate's unified TM_COMMAND dispatch endpoint.
	 * Mirrors `Newspack_Nodes\Rest\HTTP_In::REST_NAMESPACE` +
	 * `HTTP_In::ROUTE`. M5.2 cutover: all settings-sync POSTs
	 * land here rather than at the legacy /settings + /performance/settings
	 * routes (deleted).
	 *
	 * @var string
	 */
	private const COMMAND_PATH = '/wp-json/newspack-nodes/v1/command';

	/**
	 * Content-Type for `/command` POSTs. The body is JSONL (one packed
	 * Message per line); WordPress's REST dispatcher 400s a JSONL body sent
	 * as `application/json` (it pre-parses the body as a single JSON
	 * document and rejects the newlines). text/plain makes WP pass the raw
	 * body through to HTTP_In's dispatch handler. Matches the
	 * browser CommandClient (src/runtime/command_client.js).
	 *
	 * Public so the other same-plugin `/command` senders
	 * (Servers_CI::probe_remote, RemoteSource::maybe_send_heartbeat) reference
	 * this one definition instead of re-hardcoding the literal.
	 *
	 * @var string
	 */
	public const COMMAND_CONTENT_TYPE = 'text/plain; charset=UTF-8';

	/**
	 * Wire RemoteManager listeners into the WP/runtime hook surface. Idempotent
	 * — safe to call multiple times.
	 */
	public static function init(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		if ( \function_exists( 'add_action' ) ) {
			\add_action( 'newspack_event_logger_nodes/health_check', [ self::class, 'health_check' ] );
		}
		if ( \function_exists( 'add_filter' ) ) {
			// Register the JobIntake handler. Two filter names are accepted so the
			// runtime can dispatch under whichever convention it ends up using.
			\add_filter( 'newspack_nodes/job_handlers', [ self::class, 'register_handler' ] );
		}
	}

	/**
	 * Register the `remote_manager` JobIntake handler.
	 *
	 * @param mixed $handlers Existing handlers (filter boundary — defensively
	 *                        coerced to array if a misbehaving caller passes
	 *                        anything else).
	 * @return array<string, mixed> Modified handlers.
	 */
	public static function register_handler( $handlers ): array {
		/** @var array<string, mixed> $handlers */
		$handlers = \is_array( $handlers ) ? $handlers : [];
		$handlers['remote_manager'] = [ self::class, 'handle_job' ];
		return $handlers;
	}

	/**
	 * Job dispatcher. Routes by `action`:
	 *   - sync_setting  → POST {option,value} to every enabled spoke.
	 *   - health_check  → run the periodic sweep (idempotent shortcut for
	 *                     dispatchers that prefer queueing over add_action).
	 *   - default       → look up the action in the
	 *                     `newspack_event_logger_nodes/remote_actions` filter
	 *                     for plugin extension. Unknown actions are dropped
	 *                     with a rate-limited error log.
	 *
	 * Wraps every dispatch in Log_Manager::begin/end_job_context so each remote
	 * action runs under its own `/jobs/remote_manager/{action}` request scope with
	 * a fresh request_id (the substrate Job_Worker already wrapped the outer job;
	 * this nests a per-action scope for finer log correlation).
	 *
	 * @param array<string, mixed> $parameters Job parameters.
	 */
	public static function handle_job( array $parameters ): void {
		$action = $parameters['action'] ?? '';
		if ( ! \is_string( $action ) || '' === $action ) {
			return;
		}

		// Sanitize action for use in $_SERVER superglobals via begin_job_context().
		$safe_action = \preg_replace( '/[^a-zA-Z0-9_-]/', '', \substr( $action, 0, 128 ) );

		Log_Manager::begin_job_context( 'remote_manager/' . $safe_action );

		try {
			switch ( $action ) {
				case 'sync_setting':
					$option   = $parameters['option'] ?? '';
					$value    = $parameters['value'] ?? null;
					$endpoint = $parameters['endpoint'] ?? Settings_Sync::ENDPOINT;
					if ( ! Settings_Sync::is_allowed_endpoint( self::to_string( $endpoint ) ) ) {
						$endpoint = Settings_Sync::ENDPOINT;
					}

					// Skip stale jobs (older than sync interval).
					$queued_at = self::to_int( $parameters['queued_at'] ?? 0 );
					if ( $queued_at > 0 && ( \time() - $queued_at ) > self::STALE_THRESHOLD ) {
						self::log_stale_drop( $action, \time() - $queued_at );
						return;
					}

					// Optional targeted-server list for post-add fan-out: a
					// freshly-added or re-enabled spoke gets its full settings
					// blob without blocking the admin REST response.
					$servers = $parameters['servers'] ?? null;
					if ( null !== $servers ) {
						if ( ! \is_array( $servers ) ) {
							$servers = null;
						} else {
							$servers = \array_values( \array_filter( $servers, 'is_string' ) );
							if ( empty( $servers ) ) {
								$servers = null;
							}
						}
					}

					if ( \is_string( $option ) && '' !== $option ) {
						self::sync_setting( $option, $value, self::to_string( $endpoint ), $servers );
					}
					return;

				case 'health_check':
					$queued_at = self::to_int( $parameters['queued_at'] ?? 0 );
					if ( $queued_at > 0 && ( \time() - $queued_at ) > self::STALE_THRESHOLD ) {
						self::log_stale_drop( $action, \time() - $queued_at );
						return;
					}
					self::health_check();
					return;

				default:
					$handlers = \function_exists( 'apply_filters' )
						? \apply_filters( 'newspack_event_logger_nodes/remote_actions', [] )
						: [];
					if ( \is_array( $handlers ) && isset( $handlers[ $action ] ) && \is_callable( $handlers[ $action ] ) ) {
						\call_user_func( $handlers[ $action ], self::sanitize_handler_parameters( $parameters ) );
					} else {
						$safe_logged = \function_exists( 'sanitize_text_field' )
							? \sanitize_text_field( $action )
							: \preg_replace( '/[\x00-\x1f\x7f]/', '', $action );
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						\error_log( \sprintf( '[EventLoggerNodes] Unknown remote action: %s', $safe_logged ) );
					}
					return;
			}
		} finally {
			Log_Manager::end_job_context();
		}
	}

	/**
	 * Sync a single setting to all enabled (or filtered) servers. POSTs
	 * `{option,value}` to `$endpoint` on each spoke. On 200 the request is
	 * silent; on error / non-200 a `sync_error` line is logged via the
	 * LogManager so dashboards can surface the failure.
	 *
	 * @param string     $option   Option name (already mapped by SettingsSync).
	 * @param mixed      $value    Option value.
	 * @param string     $endpoint REST endpoint to POST to.
	 * @param array<int, mixed>|null $servers  Optional list of server IDs (null = all enabled).
	 */
	public static function sync_setting( string $option, $value, string $endpoint = '/wp-json/newspack-nodes/v1/settings', ?array $servers = null ): void {
		self::reset_config_snapshots();

		$registry    = self::registry();
		$server_ids  = $servers ?? \array_keys( $registry->get_all() );

		$count = 0;
		foreach ( $server_ids as $server_id ) {
			if ( $count >= self::MAX_SERVERS ) {
				break;
			}
			// Server ids are scalar (a string, or an int when a numeric id was
			// stored as an int array key); skip anything else and normalize to the
			// string identity get()/HMAC/logging use. ($servers values are mixed.)
			if ( ! \is_scalar( $server_id ) ) {
				continue;
			}
			$server_id = (string) $server_id;

			$server = $registry->get( $server_id );
			if ( null === $server ) {
				continue;
			}
			// Honour the registry-stored enabled flag where available;
			// older registries (legacy ServerRegistry) lack it, so default
			// "no flag = enabled" — list_servers() already filtered them.
			if ( isset( $server['enabled'] ) && false === (bool) $server['enabled'] ) {
				continue;
			}

			$response = self::post_to_server(
				$server,
				$endpoint,
				[
					'option' => $option,
					'value'  => $value,
				]
			);

			if ( \function_exists( 'is_wp_error' ) && \is_wp_error( $response ) ) {
				self::log_status( $server_id, 'sync_error', $response->get_error_message() );
			} else {
				$code = self::response_code( $response );
				if ( 200 !== $code ) {
					self::log_status( $server_id, 'sync_error', "HTTP {$code} syncing {$option}" );
				}
			}

			++$count;
		}
	}

	/**
	 * Run the health check on every enabled server, fire the discovery action,
	 * then sync all settings to ensure servers are up to date.
	 *
	 * Called either by the periodic action
	 * (`newspack_event_logger_nodes/health_check`) or by a queued health_check
	 * job.
	 */
	public static function health_check(): void {
		self::reset_config_snapshots();

		$registry   = self::registry();
		$server_ids = \array_keys( $registry->get_all() );

		$all_discovery = [];
		$count         = 0;

		foreach ( $server_ids as $server_id ) {
			if ( $count >= self::MAX_SERVERS ) {
				break;
			}
			// A numeric server id is stored as an int array key; normalize back
			// to its string form so get()/HMAC/logging use the real identity.
			$server_id = (string) $server_id;
			$server    = $registry->get( $server_id );
			if ( null === $server ) {
				continue;
			}
			if ( isset( $server['enabled'] ) && false === (bool) $server['enabled'] ) {
				continue;
			}

			$data = self::check_server( $server_id, $server );
			if ( null !== $data ) {
				$all_discovery[ $server_id ] = $data;
			}
			++$count;
		}

		// Discovery is one-firer / one-listener intra-process work — call the
		// merger directly instead of routing through a WP action. We still
		// fire the `health_check_discovery` action so external plugins
		// (pyrobase, etc.) can subscribe to discovered data.
		Health_Check_Extensions::process_discovery( $all_discovery );
		if ( \function_exists( 'do_action' ) ) {
			\do_action( 'newspack_event_logger_nodes/health_check_discovery', $all_discovery );
		}

		// Enqueue per-option sync_setting jobs (via JobIntake) instead of
		// POSTing inline. Each setting becomes its own visible jobs.log
		// entry that JobWorker dispatches independently — so a slow or
		// failing spoke for one option doesn't block the rest of the
		// sweep, every POST gets its own request_id / STALE_THRESHOLD,
		// and operators can grep jobs.log to see exactly what got queued.
		// Filter to the enabled server set we just probed; servers that
		// failed health_check aren't worth fanning settings to right now.
		$enabled_ids = \array_keys( $all_discovery );
		if ( ! empty( $enabled_ids ) ) {
			self::queue_sync_all_settings( $enabled_ids );
		}
	}

	/**
	 * Sync every registered setting (via the synced_settings filter) to every
	 * enabled (or filtered) server.
	 *
	 * @param array<int, mixed>|null $server_ids Optional list of server IDs to sync to (null = all enabled).
	 */
	public static function sync_all_settings( ?array $server_ids = null ): void {
		self::reset_config_snapshots();

		$settings = \function_exists( 'apply_filters' )
			? \apply_filters( 'newspack_event_logger_nodes/synced_settings', [] )
			: [];
		if ( ! \is_array( $settings ) ) {
			return;
		}
		if ( \count( $settings ) > self::MAX_SETTINGS ) {
			$settings = \array_slice( $settings, 0, self::MAX_SETTINGS );
		}

		// Single load of the full config; every entry just looks up its key.
		$config = Config::load_config();

		foreach ( $settings as $setting ) {
			if ( ! \is_array( $setting ) ) {
				continue;
			}
			$local_option  = $setting['local_option'] ?? '';
			$remote_option = $setting['remote_option'] ?? $local_option;
			$endpoint      = $setting['endpoint'] ?? Settings_Sync::ENDPOINT;

			if ( '' === $local_option ) {
				continue;
			}
			if ( ! Settings_Sync::is_allowed_endpoint( self::to_string( $endpoint ) ) ) {
				continue;
			}

			// Strip both `newspack_event_logger_nodes_` and the synthetic
			// `remote_` prefix that SettingsSync uses for hub-local tuning.
			// Strip the longer prefix first so e.g.
			// `newspack_event_logger_nodes_remote_num_segments` flattens to
			// `num_segments` (matches substrate config key) and
			// `newspack_nodes_num_partitions` flattens to `num_partitions`.
			// Mirrors SettingsSync::maybe_queue_static_sync — both must use
			// the same logic or one path silently skips substrate keys.
			$config_key = self::to_string( $local_option );
			foreach ( [ 'newspack_event_logger_nodes_', 'newspack_nodes_' ] as $prefix ) {
				if ( 0 === \strpos( $config_key, $prefix ) ) {
					$config_key = \substr( $config_key, \strlen( $prefix ) );
					break;
				}
			}
			$config_key = \preg_replace( '/^remote_/', '', $config_key );
			if ( ! isset( $config[ $config_key ] ) ) {
				continue;
			}

			self::sync_setting( self::to_string( $remote_option ), $config[ $config_key ], self::to_string( $endpoint ), $server_ids );
		}
	}

	/**
	 * Queue async sync_setting jobs targeting a freshly-added (or re-enabled)
	 * server so the admin REST response doesn't block on outbound HTTP.
	 *
	 * @param string[] $server_ids Server IDs to sync to.
	 * @return int Number of jobs queued.
	 */
	public static function queue_sync_all_settings( array $server_ids ): int {
		if ( empty( $server_ids ) ) {
			return 0;
		}

		self::reset_config_snapshots();

		$settings = \function_exists( 'apply_filters' )
			? \apply_filters( 'newspack_event_logger_nodes/synced_settings', [] )
			: [];
		if ( ! \is_array( $settings ) ) {
			return 0;
		}
		if ( \count( $settings ) > self::MAX_SETTINGS ) {
			$settings = \array_slice( $settings, 0, self::MAX_SETTINGS );
		}

		$config = Config::load_config();
		$queued = 0;
		$now    = \time();

		foreach ( $settings as $setting ) {
			if ( ! \is_array( $setting ) ) {
				continue;
			}
			$local_option  = $setting['local_option'] ?? '';
			$remote_option = $setting['remote_option'] ?? $local_option;
			$endpoint      = $setting['endpoint'] ?? Settings_Sync::ENDPOINT;

			if ( '' === $local_option ) {
				continue;
			}
			if ( ! Settings_Sync::is_allowed_endpoint( self::to_string( $endpoint ) ) ) {
				continue;
			}

			// Strip the longer prefix first so e.g.
			// `newspack_event_logger_nodes_remote_num_segments` flattens to
			// `num_segments` (matches substrate config key) and
			// `newspack_nodes_num_partitions` flattens to `num_partitions`.
			// Mirrors SettingsSync::maybe_queue_static_sync — both must use
			// the same logic or one path silently skips substrate keys.
			$config_key = self::to_string( $local_option );
			foreach ( [ 'newspack_event_logger_nodes_', 'newspack_nodes_' ] as $prefix ) {
				if ( 0 === \strpos( $config_key, $prefix ) ) {
					$config_key = \substr( $config_key, \strlen( $prefix ) );
					break;
				}
			}
			$config_key = \preg_replace( '/^remote_/', '', $config_key );
			if ( ! isset( $config[ $config_key ] ) ) {
				continue;
			}

			$ok = Settings_Sync::queue_job(
				'remote_manager',
				[
					'action'    => 'sync_setting',
					'option'    => self::to_string( $remote_option ),
					'value'     => $config[ $config_key ],
					'endpoint'  => self::to_string( $endpoint ),
					'servers'   => \array_values( $server_ids ),
					'queued_at' => $now,
				]
			);
			if ( $ok ) {
				++$queued;
			}
		}
		return $queued;
	}

	/**
	 * Drop every per-process cache the fan-out entrypoints depend on so
	 * a long-lived JobWorker (~595s) sees admin option changes on its
	 * next dispatch instead of waiting for respawn. Three layers:
	 * WP's `alloptions` snapshot, the application `Config` static cache
	 * (which transitively resets the substrate), and the `ServerRegistry`
	 * singleton's in-memory copy of `aggregator_servers`.
	 */
	private static function reset_config_snapshots(): void {
		\Newspack_Nodes\Config::invalidate_options_cache();
		Config::reset();
		Server_Registry::get_instance()->reset_cache();
	}

	/**
	 * Probe a single server's `/discovery` endpoint. Returns the validated
	 * (whitelisted-fields-only) discovery payload or null on error.
	 *
	 * @param string $server_id Server ID.
	 * @param array<string, mixed>  $server    Server config.
	 * @return array<string, mixed>|null Discovery data or null on error.
	 */
	private static function check_server( string $server_id, array $server ): ?array {
		$data = self::discover_from_server( $server, $server_id );
		if ( null === $data ) {
			return null;
		}

		$lag = self::to_int( $data['lag'] ?? 0 );
		self::log_status( $server_id, 'ok', null, $lag );

		$validated = [];
		if ( isset( $data['registered_hooks'] ) && \is_array( $data['registered_hooks'] ) ) {
			$validated['registered_hooks'] = \array_slice( $data['registered_hooks'], 0, 500 );
		}
		if ( isset( $data['custom_events'] ) && \is_array( $data['custom_events'] ) ) {
			$validated['custom_events'] = \array_slice( $data['custom_events'], 0, 500 );
		}
		if ( isset( $data['lag'] ) ) {
			$validated['lag'] = self::to_int( $data['lag'] );
		}

		return $validated;
	}

	/**
	 * Probe a single server for its discovery payload (registered_hooks +
	 * custom_events). Dispatches the request as a `discovery.get` command
	 * via `/wp-json/newspack-nodes/v1/command` — the legacy `/discovery`
	 * REST route was deleted in M5 in favor of the unified command path.
	 *
	 * Returns the unwrapped discovery payload (associative array), or null
	 * on any error path (network, HTTP, malformed envelope, TM_ERROR
	 * response). Logs an error status with `log_status()` so the dashboard's
	 * test-button surface can surface the failure to the operator.
	 *
	 * Exposed as `public` so `Servers_CI::probe_remote` (admin "test"
	 * button) reuses the same dispatch + parse logic the periodic health
	 * check uses — keeps the two surfaces in lock-step.
	 *
	 * @param array<string, mixed>  $server    Server config (url, auth_username, auth_password).
	 * @param string $server_id Server ID, used only for log_status calls.
	 * @return array<array-key, mixed>|null Decoded discovery payload, or null on error.
	 */
	public static function discover_from_server( array $server, string $server_id ): ?array {
		$url  = \rtrim( self::to_string( $server['url'] ?? '' ), '/' ) . self::COMMAND_PATH;
		$args = self::request_args( $server, [
			'headers' => [ 'Content-Type' => self::COMMAND_CONTENT_TYPE ],
			'body'    => self::command_message_body( 'discovery', 'get', '' ),
		] );

		$response = \function_exists( 'wp_remote_post' )
			? \wp_remote_post( $url, $args )
			: self::wp_error_or_array( 'no_http', 'wp_remote_post unavailable' );

		if ( \function_exists( 'is_wp_error' ) && \is_wp_error( $response ) ) {
			self::log_status( $server_id, 'error', $response->get_error_message() );
			return null;
		}
		$code = self::response_code( $response );
		if ( 200 !== $code ) {
			self::log_status( $server_id, 'error', "HTTP {$code}" );
			return null;
		}

		// Response is a packed Message whose VALUE is the structured
		// `{name, payload}` LIVE array — the whole-Message JSON is the only
		// serialization boundary, so a single decode of the body yields a
		// nested array; `payload` is read directly with NO second decode.
		// Mirrors CommandInterpreter::interpret() and the JS-side
		// `unwrapCommandResponse` helper.
		$payload = self::unwrap_command_payload( self::response_body( $response ), $server_id );
		if ( null === $payload ) {
			return null;
		}
		// Empty payload (verb returned '') → no discovery data, not an error.
		if ( '' === $payload ) {
			return [];
		}
		if ( ! \is_array( $payload ) ) {
			self::log_status( $server_id, 'error', 'Invalid discovery payload' );
			return null;
		}
		return $payload;
	}

	/**
	 * Build the body for an outbound `/command` POST: a single packed Message
	 * (JSONL line). TYPE=TM_COMMAND, FROM=`_http`, TO=$to, VALUE is the LIVE
	 * structured command array `{name, arguments}` — NOT a separately
	 * `wp_json_encode`'d string. Matches the browser CommandClient wire shape
	 * (src/runtime/command_client.js) and what HTTP_In decodes.
	 *
	 * Public so the admin Test button (Servers_CI::probe_remote) builds its
	 * `discovery.get` body through this one builder — keeps the manual-probe
	 * wire in lock-step with the periodic health-check probe.
	 *
	 * @param string $to   Target node path (the command's TO field).
	 * @param string $verb Command verb name.
	 * @param string $args Literal-string argument tail the remote verb parses (Command_Args grammar).
	 * @return string Packed Message JSONL line.
	 */
	public static function command_message_body( string $to, string $verb, string $args = '' ): string {
		$msg                                   = \Newspack_Nodes\Message::new_message();
		$msg[ \Newspack_Nodes\Message::TYPE ]  = \Newspack_Nodes\Message::TM_COMMAND;
		$msg[ \Newspack_Nodes\Message::FROM ]  = \Newspack_Nodes\Node_Names::HTTP;
		$msg[ \Newspack_Nodes\Message::TO ]    = $to;
		$msg[ \Newspack_Nodes\Message::VALUE ] = [
			'name'      => $verb,
			'arguments' => $args,
		];
		return \Newspack_Nodes\Message::packed( $msg );
	}

	/**
	 * Unwrap a `/command` HTTP response body (a packed Message) into the
	 * verb's structured payload. One decode of the whole body yields the
	 * 7-field positional array; VALUE is the structured `{name, payload}`
	 * LIVE array and `payload` is read directly (no second decode). Logs an
	 * error status and returns null on a malformed envelope or a TM_ERROR
	 * response. Mirrors the JS `unwrapCommandResponse` helper.
	 *
	 * @param string $body      Raw HTTP response body.
	 * @param string $server_id Server ID for log_status calls.
	 * @return mixed The verb payload, or null on error.
	 */
	private static function unwrap_command_payload( string $body, string $server_id ): mixed {
		$message = \json_decode( $body, true, 16 );
		if ( ! \is_array( $message ) || ! \array_key_exists( \Newspack_Nodes\Message::VALUE, $message ) ) {
			self::log_status( $server_id, 'error', 'Invalid command envelope' );
			return null;
		}
		$type = self::to_int( $message[ \Newspack_Nodes\Message::TYPE ] ?? 0 );
		if ( $type & \Newspack_Nodes\Message::TM_ERROR ) {
			self::log_status( $server_id, 'error', 'Spoke returned TM_ERROR' );
			return null;
		}
		$value = $message[ \Newspack_Nodes\Message::VALUE ];
		if ( ! \is_array( $value ) || ! \array_key_exists( 'payload', $value ) ) {
			self::log_status( $server_id, 'error', 'Invalid command inner envelope' );
			return null;
		}
		return $value['payload'];
	}

	/**
	 * POST a settings-sync payload to a remote spoke.
	 *
	 * M5.2: the legacy `/settings` and `/performance/settings` REST routes
	 * are deleted. SettingsSync still passes its endpoint constants as
	 * category tags (substrate-keys vs perf-tuning), and this method maps
	 * each tag to the equivalent service-CI verb (`Settings_CI.update`
	 * or `Performance_CI.settings_update`) and POSTs the TM_COMMAND
	 * envelope to `/wp-json/newspack-nodes/v1/command`. Basic Auth (the
	 * spoke-side authentication mechanism) is independent of the dispatch
	 * path and is preserved unchanged.
	 *
	 * Accepts both Application-Password Basic Auth (preferred) and a legacy
	 * `token` field for compatibility with the simpler ServerRegistry that
	 * stored `{url, token}` pairs.
	 *
	 * @param array<string, mixed>  $server   Server config.
	 * @param string $endpoint Category tag — one of SettingsSync::ENDPOINT,
	 *                          SettingsSync::PERF_ENDPOINT. Validated against
	 *                          ALLOWED_ENDPOINT_PREFIXES before dispatch.
	 * @param array<string, mixed>  $body     {option, value} settings-update payload.
	 * @return array<string, mixed>|\WP_Error Response or error.
	 */
	public static function post_to_server( array $server, string $endpoint, array $body ) {
		if ( ! Settings_Sync::is_allowed_endpoint( $endpoint ) ) {
			return self::wp_error_or_array( 'disallowed_endpoint', 'Endpoint not in allowed prefixes' );
		}

		$url  = \rtrim( self::to_string( $server['url'] ?? '' ), '/' ) . self::COMMAND_PATH;
		$args = self::request_args( $server, [
			'headers' => [ 'Content-Type' => self::COMMAND_CONTENT_TYPE ],
			'body'    => self::build_command_envelope( $endpoint, $body ),
		] );

		if ( \function_exists( 'wp_remote_post' ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Intentional: must allow private IPs.
			return \wp_remote_post( $url, $args );
		}
		return self::wp_error_or_array( 'no_http', 'wp_remote_post unavailable' );
	}

	/**
	 * Build the packed-Message body for a settings-sync `/command` POST.
	 *
	 * Wire format (mirrors the JS CommandClient in src/runtime/command_client.js):
	 *   a single packed Message (positional 7-field array) — JSONL.
	 *   VALUE = { name, arguments, payload } as a LIVE array (NOT a nested
	 *   JSON string). The whole-Message JSON is the only encode boundary;
	 *   CommandInterpreter::interpret() reads VALUE directly with no decode.
	 *
	 * Substrate-keys (`ENDPOINT`) flow to `settings.update` as `--<short>=<value>`
	 * (`newspack_nodes_` prefix stripped — the verb's whitelist is short-name-keyed:
	 * num_partitions, num_segments, segment_size, max_lifespan). Perf-keys
	 * (`PERF_ENDPOINT`) flow to `performance.settings_update` as
	 * `--option=<opt> --value=<v>`. Both arg strings are built from the named-arg
	 * map via Command_Args::format, so they round-trip through the remote verb's
	 * Command_Args::parse.
	 *
	 * @param string $endpoint Tag selecting the verb target.
	 * @param array<string, mixed>  $body     Settings payload `{option, value}` from sync_setting.
	 * @return string Packed Message JSONL line ready for `wp_remote_post` `body`.
	 */
	private static function build_command_envelope( string $endpoint, array $body ): string {
		[ $to, $verb, $named_args ] = self::resolve_command_target( $endpoint, $body );

		// The structured update map becomes the `--key=value` arguments tail the
		// remote verb parses — no separate payload channel.
		$args = \Newspack_Nodes\Command_Args::format( [], $named_args );
		return self::command_message_body( $to, $verb, $args );
	}

	/**
	 * Resolve a category-tag endpoint to its `(to, verb, named_args)` triple. The
	 * named-args map is rendered to a `--key=value` arguments string by the caller.
	 *
	 * @param string $endpoint One of SettingsSync::ENDPOINT or PERF_ENDPOINT.
	 * @param array<string, mixed>  $body     `{option, value}` pair.
	 * @return array{0: string, 1: string, 2: array<string, string|int|float|bool|array<mixed>>} `[to, verb, named_args]`.
	 */
	private static function resolve_command_target( string $endpoint, array $body ): array {
		if ( Settings_Sync::PERF_ENDPOINT === $endpoint ) {
			// Performance_CI.settings_update keeps the full WP option name —
			// its whitelist matches PerfSettingsController::ALLOWED_OPTIONS
			// 1:1 (newspack_event_logger_nodes_log_events etc.). Emits
			// `--option=<opt> --value=<v>`.
			return [ 'performance', 'settings_update', [
				'option' => self::to_string( $body['option'] ?? '' ),
				'value'  => self::to_arg_value( $body['value'] ?? '' ),
			] ];
		}

		// Substrate-keys (Settings_CI.update). The verb whitelist is
		// short-name-keyed, so strip the `newspack_nodes_` prefix here on the
		// wire — keeps the verb stable for callers that don't know that
		// prefix history (legacy /settings did the same strip server-side).
		// Emits `--<short>=<value>`.
		$option = self::to_string( $body['option'] ?? '' );
		$short  = 0 === \strpos( $option, 'newspack_nodes_' )
			? \substr( $option, \strlen( 'newspack_nodes_' ) )
			: $option;
		return [ 'settings', 'update', [ $short => self::to_arg_value( $body['value'] ?? '' ) ] ];
	}

	/**
	 * GET from a remote server's REST endpoint.
	 *
	 * @param array<string, mixed>  $server   Server config.
	 * @param string $endpoint API endpoint.
	 * @return array<string, mixed>|\WP_Error Response or error.
	 */
	public static function get_from_server( array $server, string $endpoint ) {
		if ( ! Settings_Sync::is_allowed_endpoint( $endpoint ) ) {
			return self::wp_error_or_array( 'disallowed_endpoint', 'Endpoint not in allowed prefixes' );
		}

		$url  = \rtrim( self::to_string( $server['url'] ?? '' ), '/' ) . $endpoint;
		$args = self::request_args( $server, [] );

		if ( \function_exists( 'wp_remote_get' ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Intentional: must allow private IPs.
			return \wp_remote_get( $url, $args );
		}
		return self::wp_error_or_array( 'no_http', 'wp_remote_get unavailable' );
	}

	// --- Helpers -------------------------------------------------------------

	/**
	 * Narrow a mixed config/server/job value to a string, reproducing the
	 * `(string)` coercion the surrounding code already applies to scalars
	 * (these values are always scalar in practice).
	 *
	 * @param mixed $value Value to coerce.
	 */
	private static function to_string( $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Narrow a mixed config/server/job value to an int, reproducing the
	 * `(int)` coercion the surrounding code already applies to scalars
	 * (these values are always scalar in practice).
	 *
	 * @param mixed $value Value to coerce.
	 */
	private static function to_int( $value ): int {
		return \is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * Narrow a mixed settings value to the type set Command_Args::format
	 * accepts (scalar or array). Option values are always scalar/array in
	 * practice; anything else falls back to an empty string.
	 *
	 * @param mixed $value Value to coerce.
	 * @return string|int|float|bool|array<mixed>
	 */
	private static function to_arg_value( $value ) {
		return ( \is_scalar( $value ) || \is_array( $value ) ) ? $value : '';
	}

	/**
	 * Construct request args for wp_remote_*. Mirrors the legacy plugin's
	 * defaults (timeout, ssl-verify, redirection, response-size cap) and adds
	 * Basic-Auth headers when Application Password creds are present.
	 *
	 * @param array<string, mixed> $server Server config (auth_username, auth_password, token, url).
	 * @param array{headers?: array<string, string>, body?: string} $extra  Args to merge in (headers, body, ...).
	 * @return array{timeout: int, sslverify: bool, redirection: int, limit_response_size: int, headers?: array<string, string>, body?: string}
	 */
	private static function request_args( array $server, array $extra ): array {
		$config = Config::load_config();

		$args = [
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Remote server sync needs reasonable timeout.
			'timeout'             => self::REQUEST_TIMEOUT,
			'sslverify'           => (bool) ( $config['aggregator_verify_ssl'] ?? true ),
			'redirection'         => 0,
			'limit_response_size' => 1048576,
		];
		if ( isset( $extra['headers'] ) ) {
			$args['headers'] = $extra['headers'];
			unset( $extra['headers'] );
		}
		foreach ( $extra as $k => $v ) {
			$args[ $k ] = $v;
		}

		// Auth: prefer Basic Auth (Application Passwords); fall back to a
		// `token` field if the legacy registry stored one.
		$username = self::to_string( $server['auth_username'] ?? '' );
		$password = self::to_string( $server['auth_password'] ?? '' );
		if ( '' !== $username && '' !== $password ) {
			if ( ! isset( $args['headers'] ) ) {
				$args['headers'] = [];
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth.
			$args['headers']['Authorization'] = 'Basic ' . \base64_encode( $username . ':' . $password );
		} elseif ( '' !== self::to_string( $server['token'] ?? '' ) ) {
			if ( ! isset( $args['headers'] ) ) {
				$args['headers'] = [];
			}
			$args['headers']['Authorization'] = 'Bearer ' . self::to_string( $server['token'] ?? '' );
		}

		return $args;
	}

	/**
	 * Sanitize parameters before passing to filter-registered handlers. Strips
	 * keys that could be used to bypass endpoint validation if a handler
	 * naively forwards parameters to post/get_to_server.
	 *
	 * @param array<string, mixed> $parameters Raw job parameters.
	 * @return array<string, mixed> Sanitized parameters with only safe keys.
	 */
	private static function sanitize_handler_parameters( array $parameters ): array {
		$safe = [];
		foreach ( $parameters as $key => $value ) {
			if ( 'endpoint' === $key ) {
				if ( \is_string( $value ) && Settings_Sync::is_allowed_endpoint( $value ) ) {
					$safe[ $key ] = $value;
				}
				continue;
			}
			$safe[ $key ] = $value;
		}
		return $safe;
	}

	/**
	 * Log server health/sync status via LogManager so dashboards can surface
	 * the result. Best-effort: silently bails if LogManager isn't loaded.
	 *
	 * @param string      $server_id Server ID.
	 * @param string      $status    Status ('ok', 'error', 'sync_error').
	 * @param string|null $message   Optional error / status message.
	 * @param int         $lag       Optional lag in seconds (only for 'ok').
	 */
	private static function log_status( string $server_id, string $status, ?string $message, int $lag = 0 ): void {
		try {
			$lm = Log_Manager::instance();
			if ( ! $lm->enabled ) {
				return;
			}
			$data = [
				'm' => [
					'server' => $server_id,
					'status' => $status,
				],
			];
			if ( null !== $message ) {
				$data['m']['message'] = $message;
			}
			if ( $lag > 0 ) {
				$data['m']['lag'] = $lag;
			}
			$lm->message( 'remote_health', $data );
		} catch ( \Throwable $e ) {
			// Best-effort logging; don't propagate failures back into the sync.
		}
	}

	/**
	 * Rate-limited stale-job log. Avoids spamming the error log when a backed-up
	 * cron tick blows through dozens of stale jobs.
	 */
	private static function log_stale_drop( string $action, int $age ): void {
		/** @var int $last */
		static $last = 0;
		if ( \time() - $last < 60 ) {
			return;
		}
		$last = \time();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		\error_log( \sprintf( '[EventLoggerNodes] Stale %s job dropped (age=%ds)', $action, $age ) );
	}

	/**
	 * Calculate aggregate per-partition lag from the spoke's segments + cursor.
	 * Returns total bytes the consumer is behind across all partitions.
	 *
	 * Used by the discovery endpoint on remote spokes — but exposed here so
	 * the hub can compute the same number against locally-cached segment
	 * info if it ever needs to. Returns 0 when no segments / cursor info is
	 * present, matching "no lag known" semantics.
	 *
	 * @param array<array-key, mixed> $segments Map of partition_id => list of segment metadata
	 *                        objects (`size`, `id`).
	 * @param array<array-key, mixed> $cursor   Map of partition_id => `{segment_id, offset}`.
	 * @return int Total byte lag.
	 */
	public static function calculate_lag( array $segments, array $cursor ): int {
		$total = 0;
		foreach ( $segments as $partition_id => $segs ) {
			if ( ! \is_array( $segs ) ) {
				continue;
			}
			$cur        = $cursor[ $partition_id ] ?? null;
			$cur_seg    = \is_array( $cur ) ? self::to_string( $cur['segment_id'] ?? '' ) : '';
			$cur_offset = \is_array( $cur ) ? self::to_int( $cur['offset'] ?? 0 ) : 0;

			$found_current = false;
			foreach ( $segs as $seg ) {
				if ( ! \is_array( $seg ) ) {
					continue;
				}
				$seg_id   = self::to_string( $seg['id'] ?? '' );
				$seg_size = self::to_int( $seg['size'] ?? 0 );

				if ( ! $found_current ) {
					if ( $seg_id === $cur_seg ) {
						$total        += \max( 0, $seg_size - $cur_offset );
						$found_current = true;
					}
				} else {
					$total += $seg_size;
				}
			}
			// If we never found the current segment, all segments are ahead.
			if ( ! $found_current && '' === $cur_seg ) {
				foreach ( $segs as $seg ) {
					if ( \is_array( $seg ) ) {
						$total += self::to_int( $seg['size'] ?? 0 );
					}
				}
			}
		}
		return $total;
	}

	// --- WP-API thin wrappers (test seams) ----------------------------------

	/**
	 * Resolve the response code from a wp_remote_* response array. Returns 0
	 * if WP isn't available or the response is malformed.
	 *
	 * @param array<string, mixed>|\WP_Error $response Response from wp_remote_*.
	 * @return int HTTP status code.
	 */
	private static function response_code( $response ): int {
		if ( \function_exists( 'wp_remote_retrieve_response_code' ) ) {
			return (int) \wp_remote_retrieve_response_code( $response );
		}
		if ( \is_array( $response ) && isset( $response['response'] ) && \is_array( $response['response'] ) && isset( $response['response']['code'] ) ) {
			return self::to_int( $response['response']['code'] );
		}
		return 0;
	}

	/**
	 * Resolve the response body from a wp_remote_* response array.
	 *
	 * @param array<string, mixed>|\WP_Error $response wp_remote_* response.
	 */
	private static function response_body( $response ): string {
		if ( \function_exists( 'wp_remote_retrieve_body' ) ) {
			return \wp_remote_retrieve_body( $response );
		}
		if ( \is_array( $response ) && isset( $response['body'] ) ) {
			return self::to_string( $response['body'] );
		}
		return '';
	}

	/**
	 * Construct a WP_Error if available, else a structured array. Lets tests
	 * assert error returns without WordPress loaded.
	 *
	 * @return \WP_Error|array{error: string, message: string}
	 */
	private static function wp_error_or_array( string $code, string $message ) {
		if ( \class_exists( '\\WP_Error' ) ) {
			return new \WP_Error( $code, $message );
		}
		return [ 'error' => $code, 'message' => $message ];
	}

	/**
	 * Resolve the ServerRegistry instance to use. The legacy ServerRegistry in
	 * this plugin uses constructor-based instantiation; if a future
	 * ServerRegistry exposes a static get_instance/reset_cache pair, we'll
	 * pick it up via the new methods automatically.
	 */
	private static function registry(): Server_Registry {
		static $registry = null;
		if ( $registry instanceof Server_Registry ) {
			// Reset cache before reuse — long-running workers may have
			// cached enabled-list state.
			$registry->reset_cache();
			return $registry;
		}
		$registry = Server_Registry::get_instance();
		return $registry;
	}
}
