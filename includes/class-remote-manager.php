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
class RemoteManager {
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
			\add_filter( 'newspack_event_logger_nodes/job_handlers', [ self::class, 'register_handler' ] );
		}
	}

	/**
	 * Register the `remote_manager` JobIntake handler.
	 *
	 * @param mixed $handlers Existing handlers (filter boundary — defensively
	 *                        coerced to array if a misbehaving caller passes
	 *                        anything else).
	 * @return array Modified handlers.
	 */
	public static function register_handler( $handlers ): array {
		if ( ! \is_array( $handlers ) ) {
			$handlers = [];
		}
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
	 * Wraps every dispatch in begin_job_context / end_job_context so request_id
	 * correlation flows into LogManager (per-job request_id is rotated even
	 * across many handler invocations on a long-running worker).
	 *
	 * @param array $parameters Job parameters.
	 */
	public static function handle_job( array $parameters ): void {
		$action = $parameters['action'] ?? '';
		if ( ! \is_string( $action ) || '' === $action ) {
			return;
		}

		// Sanitize action for use in $_SERVER superglobals via begin_job_context().
		$safe_action = \preg_replace( '/[^a-zA-Z0-9_-]/', '', \substr( $action, 0, 128 ) );

		$orig_server = self::begin_job_context( 'remote_manager/' . $safe_action );

		try {
			switch ( $action ) {
				case 'sync_setting':
					$option   = $parameters['option'] ?? '';
					$value    = $parameters['value'] ?? null;
					$endpoint = $parameters['endpoint'] ?? SettingsSync::ENDPOINT;
					if ( ! SettingsSync::is_allowed_endpoint( (string) $endpoint ) ) {
						$endpoint = SettingsSync::ENDPOINT;
					}

					// Skip stale jobs (older than sync interval).
					$queued_at = (int) ( $parameters['queued_at'] ?? 0 );
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
						self::sync_setting( $option, $value, (string) $endpoint, $servers );
					}
					return;

				case 'health_check':
					$queued_at = (int) ( $parameters['queued_at'] ?? 0 );
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
			self::end_job_context( $orig_server );
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
	 * @param array|null $servers  Optional list of server IDs (null = all enabled).
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
			if ( ! \is_string( $server_id ) ) {
				continue;
			}

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
				self::log_status( $server_id, 'sync_error', (string) $response->get_error_message() );
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
			if ( ! \is_string( $server_id ) ) {
				continue;
			}
			$server = $registry->get( $server_id );
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
		HealthCheckExtensions::process_discovery( $all_discovery );
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
	 * @param array|null $server_ids Optional list of server IDs to sync to (null = all enabled).
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
			$endpoint      = $setting['endpoint'] ?? SettingsSync::ENDPOINT;

			if ( '' === $local_option ) {
				continue;
			}
			if ( ! SettingsSync::is_allowed_endpoint( (string) $endpoint ) ) {
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
			$config_key = (string) $local_option;
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

			self::sync_setting( (string) $remote_option, $config[ $config_key ], (string) $endpoint, $server_ids );
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
			$endpoint      = $setting['endpoint'] ?? SettingsSync::ENDPOINT;

			if ( '' === $local_option ) {
				continue;
			}
			if ( ! SettingsSync::is_allowed_endpoint( (string) $endpoint ) ) {
				continue;
			}

			// Strip the longer prefix first so e.g.
			// `newspack_event_logger_nodes_remote_num_segments` flattens to
			// `num_segments` (matches substrate config key) and
			// `newspack_nodes_num_partitions` flattens to `num_partitions`.
			// Mirrors SettingsSync::maybe_queue_static_sync — both must use
			// the same logic or one path silently skips substrate keys.
			$config_key = (string) $local_option;
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

			$ok = SettingsSync::queue_job(
				'remote_manager',
				[
					'action'    => 'sync_setting',
					'option'    => (string) $remote_option,
					'value'     => $config[ $config_key ],
					'endpoint'  => (string) $endpoint,
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
		ServerRegistry::get_instance()->reset_cache();
	}

	/**
	 * Probe a single server's `/discovery` endpoint. Returns the validated
	 * (whitelisted-fields-only) discovery payload or null on error.
	 *
	 * @param string $server_id Server ID.
	 * @param array  $server    Server config.
	 * @return array|null Discovery data or null on error.
	 */
	private static function check_server( string $server_id, array $server ): ?array {
		$response = self::get_from_server( $server, '/wp-json/newspack-nodes/v1/discovery' );

		if ( \function_exists( 'is_wp_error' ) && \is_wp_error( $response ) ) {
			self::log_status( $server_id, 'error', (string) $response->get_error_message() );
			return null;
		}
		$code = self::response_code( $response );
		if ( 200 !== $code ) {
			self::log_status( $server_id, 'error', "HTTP {$code}" );
			return null;
		}

		$body = self::response_body( $response );
		$data = \json_decode( $body, true, 16 );
		if ( ! \is_array( $data ) ) {
			self::log_status( $server_id, 'error', 'Invalid JSON response' );
			return null;
		}

		$lag = (int) ( $data['lag'] ?? 0 );
		self::log_status( $server_id, 'ok', null, $lag );

		$validated = [];
		if ( isset( $data['registered_hooks'] ) && \is_array( $data['registered_hooks'] ) ) {
			$validated['registered_hooks'] = \array_slice( $data['registered_hooks'], 0, 500 );
		}
		if ( isset( $data['custom_events'] ) && \is_array( $data['custom_events'] ) ) {
			$validated['custom_events'] = \array_slice( $data['custom_events'], 0, 500 );
		}
		if ( isset( $data['lag'] ) ) {
			$validated['lag'] = (int) $data['lag'];
		}

		return $validated;
	}

	/**
	 * POST a JSON payload to a remote server's REST endpoint.
	 *
	 * Accepts both Application-Password Basic Auth (preferred) and a legacy
	 * `token` field for compatibility with the simpler ServerRegistry that
	 * stored `{url, token}` pairs.
	 *
	 * @param array  $server   Server config.
	 * @param string $endpoint API endpoint.
	 * @param array  $body     Request body.
	 * @return array|\WP_Error Response or error.
	 */
	public static function post_to_server( array $server, string $endpoint, array $body ) {
		if ( ! SettingsSync::is_allowed_endpoint( $endpoint ) ) {
			return self::wp_error_or_array( 'disallowed_endpoint', 'Endpoint not in allowed prefixes' );
		}

		$url  = \rtrim( (string) ( $server['url'] ?? '' ), '/' ) . $endpoint;
		$args = self::request_args( $server, [
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => \wp_json_encode( $body ),
		] );

		if ( \function_exists( 'wp_remote_post' ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Intentional: must allow private IPs.
			return \wp_remote_post( $url, $args );
		}
		return self::wp_error_or_array( 'no_http', 'wp_remote_post unavailable' );
	}

	/**
	 * GET from a remote server's REST endpoint.
	 *
	 * @param array  $server   Server config.
	 * @param string $endpoint API endpoint.
	 * @return array|\WP_Error Response or error.
	 */
	public static function get_from_server( array $server, string $endpoint ) {
		if ( ! SettingsSync::is_allowed_endpoint( $endpoint ) ) {
			return self::wp_error_or_array( 'disallowed_endpoint', 'Endpoint not in allowed prefixes' );
		}

		$url  = \rtrim( (string) ( $server['url'] ?? '' ), '/' ) . $endpoint;
		$args = self::request_args( $server, [] );

		if ( \function_exists( 'wp_remote_get' ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Intentional: must allow private IPs.
			return \wp_remote_get( $url, $args );
		}
		return self::wp_error_or_array( 'no_http', 'wp_remote_get unavailable' );
	}

	// --- Helpers -------------------------------------------------------------

	/**
	 * Construct request args for wp_remote_*. Mirrors the legacy plugin's
	 * defaults (timeout, ssl-verify, redirection, response-size cap) and adds
	 * Basic-Auth headers when Application Password creds are present.
	 *
	 * @param array $server Server config (auth_username, auth_password, token, url).
	 * @param array $extra  Args to merge in (headers, body, ...).
	 * @return array
	 */
	private static function request_args( array $server, array $extra ): array {
		$config = Config::load_config();

		$args = [
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- Remote server sync needs reasonable timeout.
			'timeout'             => self::REQUEST_TIMEOUT,
			'sslverify'           => $config['aggregator_verify_ssl'] ?? true,
			'redirection'         => 0,
			'limit_response_size' => 1048576,
		];
		if ( isset( $extra['headers'] ) && \is_array( $extra['headers'] ) ) {
			$args['headers'] = $extra['headers'];
			unset( $extra['headers'] );
		}
		foreach ( $extra as $k => $v ) {
			$args[ $k ] = $v;
		}

		// Auth: prefer Basic Auth (Application Passwords); fall back to a
		// `token` field if the legacy registry stored one.
		$username = (string) ( $server['auth_username'] ?? '' );
		$password = (string) ( $server['auth_password'] ?? '' );
		if ( '' !== $username && '' !== $password ) {
			if ( ! isset( $args['headers'] ) || ! \is_array( $args['headers'] ) ) {
				$args['headers'] = [];
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for HTTP Basic Auth.
			$args['headers']['Authorization'] = 'Basic ' . \base64_encode( $username . ':' . $password );
		} elseif ( '' !== (string) ( $server['token'] ?? '' ) ) {
			if ( ! isset( $args['headers'] ) || ! \is_array( $args['headers'] ) ) {
				$args['headers'] = [];
			}
			$args['headers']['Authorization'] = 'Bearer ' . (string) $server['token'];
		}

		return $args;
	}

	/**
	 * Sanitize parameters before passing to filter-registered handlers. Strips
	 * keys that could be used to bypass endpoint validation if a handler
	 * naively forwards parameters to post/get_to_server.
	 *
	 * @param array $parameters Raw job parameters.
	 * @return array Sanitized parameters with only safe keys.
	 */
	private static function sanitize_handler_parameters( array $parameters ): array {
		$safe = [];
		foreach ( $parameters as $key => $value ) {
			if ( 'endpoint' === $key ) {
				if ( \is_string( $value ) && SettingsSync::is_allowed_endpoint( $value ) ) {
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
			$lm = LogManager::instance();
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
		static $last = 0;
		if ( \time() - $last < 60 ) {
			return;
		}
		$last = \time();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		\error_log( \sprintf( '[EventLoggerNodes] Stale %s job dropped (age=%ds)', $action, $age ) );
	}

	/**
	 * Wrap LogManager-context $_SERVER setup. Mirrors the upstream
	 * JobWorker::begin_job_context behaviour so request-id correlation flows
	 * through worker-to-worker dispatches.
	 *
	 * @param string $name Job name (used as request URI suffix).
	 * @return array Original $_SERVER snapshot for end_job_context.
	 */
	public static function begin_job_context( string $name ): array {
		// Preserve the original $_SERVER for restoration.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Saved verbatim for restore.
		$orig_server = $_SERVER;

		$name      = \ltrim( $name, '/' );
		$path_info = '/' . $name;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only context lookup.
		$server_name = $_SERVER['SERVER_NAME'] ?? '';

		// Generate a fresh request id.
		$rid = '';
		try {
			$rid = LogManager::generate_request_id();
		} catch ( \Throwable $e ) {
			$rid = '';
		}

		$_SERVER['UNIQUE_ID']       = $rid;
		$_SERVER['REQUEST_URI']     = '/jobs/' . $name;
		$_SERVER['REQUEST_METHOD']  = 'POST';
		$_SERVER['PATH_INFO']       = $path_info;
		$_SERVER['SCRIPT_NAME']     = $path_info;
		$_SERVER['SCRIPT_URL']      = $path_info;
		$_SERVER['SCRIPT_URI']      = 'https://' . $server_name . $path_info;
		$_SERVER['QUERY_STRING']    = '';
		unset( $_SERVER['CONTENT_TYPE'], $_SERVER['CONTENT_LENGTH'] );
		unset( $_SERVER['HTTP_X_A8C_REQUEST_ID'] );

		return $orig_server;
	}

	/**
	 * Restore $_SERVER and pop the LogManager context stack. Counterpart to
	 * begin_job_context().
	 *
	 * @param array $orig_server Snapshot returned by begin_job_context.
	 */
	public static function end_job_context( array $orig_server ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Restoring previously-saved value.
		$_SERVER = $orig_server;
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
	 * @param array $segments Map of partition_id => list of segment metadata
	 *                        objects (`size`, `id`).
	 * @param array $cursor   Map of partition_id => `{segment_id, offset}`.
	 * @return int Total byte lag.
	 */
	public static function calculate_lag( array $segments, array $cursor ): int {
		$total = 0;
		foreach ( $segments as $partition_id => $segs ) {
			if ( ! \is_array( $segs ) ) {
				continue;
			}
			$cur_seg    = (string) ( $cursor[ $partition_id ]['segment_id'] ?? '' );
			$cur_offset = (int) ( $cursor[ $partition_id ]['offset'] ?? 0 );

			$found_current = false;
			foreach ( $segs as $seg ) {
				if ( ! \is_array( $seg ) ) {
					continue;
				}
				$seg_id   = (string) ( $seg['id'] ?? '' );
				$seg_size = (int) ( $seg['size'] ?? 0 );

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
						$total += (int) ( $seg['size'] ?? 0 );
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
	 * @param mixed $response Response from wp_remote_*.
	 * @return int HTTP status code.
	 */
	private static function response_code( $response ): int {
		if ( \function_exists( 'wp_remote_retrieve_response_code' ) ) {
			return (int) \wp_remote_retrieve_response_code( $response );
		}
		if ( \is_array( $response ) && isset( $response['response']['code'] ) ) {
			return (int) $response['response']['code'];
		}
		return 0;
	}

	/**
	 * Resolve the response body from a wp_remote_* response array.
	 */
	private static function response_body( $response ): string {
		if ( \function_exists( 'wp_remote_retrieve_body' ) ) {
			return (string) \wp_remote_retrieve_body( $response );
		}
		if ( \is_array( $response ) && isset( $response['body'] ) ) {
			return (string) $response['body'];
		}
		return '';
	}

	/**
	 * Construct a WP_Error if available, else a structured array. Lets tests
	 * assert error returns without WordPress loaded.
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
	private static function registry(): ServerRegistry {
		static $registry = null;
		if ( $registry instanceof ServerRegistry ) {
			// Reset cache before reuse — long-running workers may have
			// cached enabled-list state.
			$registry->reset_cache();
			return $registry;
		}
		$registry = ServerRegistry::get_instance();
		return $registry;
	}
}
