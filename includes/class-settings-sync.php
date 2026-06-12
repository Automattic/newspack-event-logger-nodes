<?php
/**
 * Settings Sync
 *
 * Hub-side sync of WP options to remote spokes. No explicit gate —
 * runs whenever a synced option changes. Without an aggregator
 * topology + enabled remotes, the queued remote_manager jobs have
 * no consumer (silent no-op).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Sync class.
 */
class Settings_Sync {
	/**
	 * Substrate-tuning options that sync to remote servers with name remap.
	 * Maps local option name → remote option name.
	 *
	 * Local hub stores tuning under `_remote_*` so changing the remote spoke's
	 * tuning doesn't accidentally retune the hub itself; the remote receives
	 * the value under its non-remote key.
	 *
	 * Substrate options live under the `newspack_nodes_*` prefix (after the
	 * Config split). The hub-side `_remote_*` keys keep the
	 * `newspack_event_logger_nodes_remote_*` prefix because they're
	 * application-controlled overrides for the remote spoke; remote spokes
	 * receive them under the substrate's canonical `newspack_nodes_*` key.
	 *
	 * @var array<string, string>
	 */
	public const SYNCED_OPTIONS = [
		'newspack_event_logger_nodes_remote_num_segments' => 'newspack_nodes_num_segments',
		'newspack_event_logger_nodes_remote_segment_size' => 'newspack_nodes_segment_size',
		'newspack_event_logger_nodes_remote_max_lifespan' => 'newspack_nodes_max_lifespan',
		'newspack_nodes_num_partitions'                   => 'newspack_nodes_num_partitions',
	];

	/**
	 * Performance-tuning options synced 1:1 (no name remap).
	 * Registered through the `newspack_event_logger_nodes/synced_settings` filter
	 * for the periodic full-sync sweep done by RemoteManager::sync_all_settings().
	 *
	 * @var string[]
	 */
	public const PERF_TUNING_OPTIONS = [
		'newspack_event_logger_nodes_log_urls',
		'newspack_event_logger_nodes_skip_urls',
		'newspack_event_logger_nodes_log_events',
		'newspack_event_logger_nodes_custom_events',
		'newspack_event_logger_nodes_auto_disable_threshold',
		'newspack_event_logger_nodes_auto_protect_time_threshold',
		'newspack_event_logger_nodes_significant_events',
		'newspack_event_logger_nodes_log_memory',
		'newspack_event_logger_nodes_flush_every_line',
	];

	/**
	 * Category tag for syncing the four core substrate options
	 * (`newspack_nodes_num_partitions` etc.). M5.2: RemoteManager maps this
	 * tag to the `settings.update` verb and POSTs the TM_COMMAND envelope
	 * to `/wp-json/newspack-nodes/v1/command`. The string remains valid as
	 * an allowed-prefix path so existing filter-supplied entries
	 * continue to match `is_allowed_endpoint`.
	 *
	 * @var string
	 */
	public const ENDPOINT = '/wp-json/newspack-nodes/v1/settings';

	/**
	 * Category tag for syncing the nine performance-tuning options
	 * (`log_events`, `significant_events`, `auto_*_threshold`, ...). M5.2:
	 * RemoteManager maps this tag to the `performance.settings_update` verb
	 * and POSTs the TM_COMMAND envelope to
	 * `/wp-json/newspack-nodes/v1/command`. Distinct from ENDPOINT because
	 * the two verbs have different allowed-keys whitelists; using the wrong
	 * tag still misroutes to the wrong verb on the spoke.
	 *
	 * @var string
	 */
	public const PERF_ENDPOINT = '/wp-json/newspack-nodes/v1/performance/settings';

	/**
	 * Allowed endpoint prefixes for outbound requests.
	 * Matches RemoteManager::ALLOWED_ENDPOINT_PREFIXES so the two stay in
	 * lock-step; filter-supplied endpoints that don't match any prefix are
	 * silently dropped from the fan-out.
	 *
	 * @var string[]
	 */
	public const ALLOWED_ENDPOINT_PREFIXES = [
		'/wp-json/newspack-nodes/',
		'/wp-json/newspack-nodes-aggregator/',
	];

	/**
	 * Static re-entrancy guard. Used by HealthCheckExtensions and inbound REST
	 * handlers to apply a remote-sourced setting without re-queueing the sync.
	 *
	 * @var bool
	 */
	private static bool $static_syncing = false;

	/** @var array<int, string> Option names to mirror to remotes. */
	private array $synced_options;
	/** @var callable */
	private $dispatch;
	private bool $syncing = false;

	/**
	 * @param array<int, string> $synced_options
	 */
	public function __construct(
		array $synced_options,
		callable $dispatch
	) {
		$this->synced_options = $synced_options;
		$this->dispatch       = $dispatch;
	}

	/**
	 * WP `update_option` listener (3-arg signature: option, old, new).
	 *
	 * @param string $option    Option name.
	 * @param mixed  $old_value Old value (unused).
	 * @param mixed  $new_value New value.
	 */
	public static function on_static_option_update( string $option, $old_value, $new_value ): void {
		self::maybe_queue_static_sync( $option, $new_value );
	}

	/**
	 * Queue a static-mode sync via JobIntake when the option is in our list.
	 *
	 * Routes through JobIntake (jobintake.log) because settings payloads can
	 * exceed the 4KB PIPE_BUF atomic write limit. Always queues; without an
	 * aggregator topology + enabled remotes, the queued remote_manager
	 * job has no consumer (silent no-op).
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 */
	private static function maybe_queue_static_sync( string $option, $value ): void {
		// Re-entrancy guard.
		if ( self::$static_syncing ) {
			return;
		}

		$is_remap = isset( self::SYNCED_OPTIONS[ $option ] );
		$is_perf  = \in_array( $option, self::PERF_TUNING_OPTIONS, true );
		if ( ! $is_remap && ! $is_perf ) {
			return;
		}

		// No gate: this dispatch is a write to RemoteManager / JobIntake
		// addressed at remote spokes. With no aggregator topology
		// running and no enabled remotes registered, the downstream
		// JobWorker has no remote_manager handler to invoke and the
		// jobs silently no-op. Any topology that DOES run aggregator
		// machinery picks them up automatically.

		// Map local → remote option name.
		$remote_option = $is_remap ? self::SYNCED_OPTIONS[ $option ] : $option;

		// Resolve empty/false to file-backed defaults so blanking a field syncs
		// the *default* rather than '' (which would fail remote sanitization
		// for typed options like int).
		if ( '' === $value || false === $value ) {
			// Strip both prefixes (substrate `newspack_nodes_` and application
			// `newspack_event_logger_nodes_`), then drop the optional "remote_"
			// segment so defaults are looked up under the canonical key
			// (num_segments, segment_size, ...).
			$config_key = $option;
			foreach ( [ 'newspack_event_logger_nodes_', 'newspack_nodes_' ] as $prefix ) {
				if ( 0 === \strpos( $config_key, $prefix ) ) {
					$config_key = \substr( $config_key, \strlen( $prefix ) );
					break;
				}
			}
			$config_key = \preg_replace( '/^remote_/', '', $config_key );
			$defaults   = Config::load_config_defaults();
			if ( isset( $defaults[ $config_key ] ) ) {
				$value = $defaults[ $config_key ];
			}
		}

		// SettingsController (/settings) accepts the substrate-remap keys;
		// PerfSettingsController (/performance/settings) accepts the
		// PERF_TUNING_OPTIONS list. Send each option to the matching one.
		$endpoint = $is_perf ? self::PERF_ENDPOINT : self::ENDPOINT;

		self::queue_job(
			'remote_manager',
			[
				'action'    => 'sync_setting',
				'option'    => $remote_option,
				'value'     => $value,
				'endpoint'  => $endpoint,
				'queued_at' => \time(),
			]
		);
	}

	/**
	 * JobIntake bridge. Resolves the base directory from Config and dispatches
	 * to the local JobIntake (preferred) or, if the runtime ever exposes a
	 * shared JobIntake under \Newspack_Nodes, falls back to that.
	 *
	 * Signature mirrors the dndocker JobIntake (which takes a leading
	 * `$base_dir`); the upstream legacy plugin used a 2-arg static helper.
	 *
	 * @param string      $handler Job handler name.
	 * @param array<string, mixed>       $params  Job parameters.
	 * @param string|null $key     Optional partition key.
	 * @return bool True on success.
	 */
	public static function queue_job( string $handler, array $params, ?string $key = null ): bool {
		// Resolve base_dir + num_partitions from Config (cheap; cached).
		// JobIntake auto-resolves base_dir + num_partitions from substrate Config.
		return Job_Intake::queue( $handler, $params, $key );
	}

	/**
	 * WP `add_option` listener (2-arg signature: option, value).
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 */
	public static function on_static_option_add( string $option, $value ): void {
		self::maybe_queue_static_sync( $option, $value );
	}

	// (Instance suppress_sync alias removed — collided with static method;
	// callers use suppress_instance_sync directly.)

	/**
	 * @param mixed $old Previous option value.
	 * @param mixed $new New option value.
	 */
	public function on_option_update( string $option, $old, $new ): void {
		if ( $this->syncing ) {
			return; // Re-entrancy guard.
		}
		// No aggregator gate: a hub topology (or any topology that
		// dispatches remote_manager jobs) consumes this; without one,
		// the encrypted payload queues a job nobody picks up. Silent.
		if ( ! \in_array( $option, $this->synced_options, true ) ) {
			return;
		}

		// Encrypt the payload before dispatching. JSON-encode first so structured
		// values survive the wire (encrypt operates on strings).
		$plaintext  = (string) \wp_json_encode( [ 'option' => $option, 'value' => $new ] );
		$ciphertext = self::encrypt( $plaintext );
		if ( '' === $ciphertext ) {
			// Encryption-required fail-closed: never dispatch plaintext on the wire.
			// (Sodium missing, key derivation failed, or random_bytes blew up.)
			return;
		}

		( $this->dispatch )( $option, $new, $ciphertext );
	}

	/**
	 * Encrypt a plaintext for wire transmission. Returns '' on failure (sodium
	 * unavailable, key derivation failed, etc.) — caller treats empty as
	 * fail-closed and skips the dispatch.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string base64(nonce . ciphertext), or '' on failure.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( ! \function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		$key = self::encryption_key();
		if ( '' === $key ) {
			return '';
		}
		try {
			$nonce      = \random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = \sodium_crypto_secretbox( $plaintext, $nonce, $key );
		} catch ( \Throwable $e ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- wire format requires base64.
		return \base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Derive a 32-byte key from wp_salt('auth') via generic hash. Returns ''
	 * if sodium hash isn't available — callers treat that as fail-closed.
	 */
	private static function encryption_key(): string {
		if ( ! \function_exists( 'sodium_crypto_generichash' ) ) {
			return '';
		}
		if ( ! \function_exists( 'wp_salt' ) ) {
			return '';
		}
		$salt = \wp_salt( 'auth' );
		if ( '' === $salt ) {
			return '';
		}
		try {
			return \sodium_crypto_generichash( $salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Apply an inbound encrypted settings payload. Returns the decoded payload
	 * (`['option' => string, 'value' => mixed]`) on success, null on tamper /
	 * malformed input.
	 *
	 * @param string $ciphertext base64(nonce . ciphertext) from a sync sender.
	 * @return array{option:string,value:mixed}|null
	 */
	public static function decode_payload( string $ciphertext ): ?array {
		$plaintext = self::decrypt( $ciphertext );
		if ( null === $plaintext ) {
			return null;
		}
		$decoded = \json_decode( $plaintext, true );
		if ( ! \is_array( $decoded ) || ! isset( $decoded['option'] ) ) {
			return null;
		}
		/** @var int|float|string|bool|null $raw_option */
		$raw_option = $decoded['option'];
		return [
			'option' => (string) $raw_option,
			'value'  => $decoded['value'] ?? null,
		];
	}

	/**
	 * Decrypt a wire payload. Returns null on:
	 *   - malformed base64
	 *   - too-short payload (< nonce size)
	 *   - bad-MAC (sodium_crypto_secretbox_open returns false on tamper)
	 *   - sodium missing or key derivation failed
	 *
	 * @param string $ciphertext base64(nonce . ciphertext)
	 * @return string|null Plaintext, or null on failure.
	 */
	public static function decrypt( string $ciphertext ): ?string {
		if ( ! \function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		$key = self::encryption_key();
		if ( '' === $key ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- wire format requires base64.
		$decoded = \base64_decode( $ciphertext, true );
		if ( false === $decoded || \strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = \substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$body  = \substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plaintext = \sodium_crypto_secretbox_open( $body, $nonce, $key );
		} catch ( \Throwable $e ) {
			return null;
		}
		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Wire static listeners on the WP option update / add hooks. Idempotent —
	 * safe to call multiple times (uses a static guard so multiple plugin
	 * activation paths don't double-register).
	 */
	public static function init(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		if ( \function_exists( 'add_action' ) ) {
			\add_action( 'update_option', [ self::class, 'on_static_option_update' ], 10, 3 );
			\add_action( 'add_option', [ self::class, 'on_static_option_add' ], 10, 2 );
		}
		if ( \function_exists( 'add_filter' ) ) {
			\add_filter( 'newspack_event_logger_nodes/synced_settings', [ self::class, 'register_synced_settings' ] );
		}
	}

	/**
	 * Register the local→remote settings list for the periodic full-sync sweep.
	 *
	 * Returned array entries each carry `{local_option, remote_option, endpoint}`
	 * so RemoteManager::sync_all_settings() can resolve the local config value
	 * and POST it to the right remote name without re-traversing the option
	 * schema.
	 *
	 * @param array<string, mixed> $settings Existing settings.
	 * @return array<int|string, mixed> Modified settings (list entries appended).
	 */
	public static function register_synced_settings( array $settings ): array {
		// Core options with name remap.
		foreach ( self::SYNCED_OPTIONS as $local => $remote ) {
			$settings[] = [
				'local_option'  => $local,
				'remote_option' => $remote,
				'endpoint'      => self::ENDPOINT,
			];
		}
		// Performance-tuning options synced 1:1, posted to PerfSettingsController.
		foreach ( self::PERF_TUNING_OPTIONS as $option ) {
			$settings[] = [
				'local_option'  => $option,
				'remote_option' => $option,
				'endpoint'      => self::PERF_ENDPOINT,
			];
		}
		return $settings;
	}

	/**
	 * Suppress static-mode sync fan-out during inbound setting updates.
	 *
	 * Call this before update_option() when applying a remotely-synced setting
	 * to prevent the update_option hook from re-queuing the sync. Used by
	 * HealthCheckExtensions::merge_hooks() and the inbound REST settings
	 * controller.
	 *
	 * @param bool $suppress True to suppress, false to re-enable.
	 */
	public static function suppress_sync( bool $suppress = true ): void {
		self::$static_syncing = $suppress;
	}

	/**
	 * Whether static-mode sync is currently suppressed. Test helper.
	 */
	public static function is_sync_suppressed(): bool {
		return self::$static_syncing;
	}

	/**
	 * Validate that an endpoint starts with one of the allowed prefixes.
	 * Public so RemoteManager and tests can share the check.
	 */
	public static function is_allowed_endpoint( string $endpoint ): bool {
		foreach ( self::ALLOWED_ENDPOINT_PREFIXES as $prefix ) {
			if ( 0 === \strpos( $endpoint, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	// --- Instance-mode (legacy closure-dispatch with encryption) -------------

	public function suppress_instance_sync( bool $suppress = true ): void {
		$this->syncing = $suppress;
	}
}
