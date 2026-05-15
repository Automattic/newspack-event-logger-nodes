<?php
/**
 * Server Registry
 *
 * Singleton that manages remote server configurations stored in WordPress options.
 * Servers are stored in the 'newspack_event_logger_nodes_aggregator_servers' option as an associative array.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server Registry class.
 */
class ServerRegistry {

	/**
	 * WP option name for storing server configurations.
	 */
	public const OPTION_KEY = 'newspack_event_logger_nodes_aggregator_servers';

	/**
	 * Maximum number of servers in the registry.
	 */
	public const MAX_SERVERS = 100;

	/**
	 * Prefix marking encrypted values (distinguishes from legacy plaintext).
	 */
	public const ENCRYPTED_PREFIX = '$enc$';

	/**
	 * Whitelisted config keys for partial-update merge.
	 */
	private const ALLOWED_KEYS = [ 'url', 'auth_username', 'auth_password', 'enabled', 'logs' ];

	/**
	 * Singleton instance.
	 *
	 * @var ServerRegistry|null
	 */
	private static ?ServerRegistry $instance = null;

	/**
	 * Cached merged servers (config-file defaults + WP option overlay).
	 *
	 * @var array<string,array>|null
	 */
	private ?array $servers = null;

	/**
	 * One-shot legacy-plaintext warning latch.
	 *
	 * @var bool
	 */
	private static bool $legacy_warned = false;

	/**
	 * Singleton accessor.
	 */
	public static function get_instance(): ServerRegistry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Public constructor — kept public for direct instantiation in tests.
	 */
	public function __construct() {
		// Intentionally empty.
	}

	/**
	 * Get all servers (synonym of get_all() for backwards-compat).
	 *
	 * @return array Associative array of server_id => config.
	 */
	public function get_servers(): array {
		return $this->get_all();
	}

	/**
	 * Numeric-keyed list view of get_all().
	 *
	 * @return array Sequential array of server configs (id keyed in field).
	 */
	public function list_servers(): array {
		$out = [];
		foreach ( $this->get_all() as $id => $cfg ) {
			$cfg['id'] = $id;
			$out[]     = $cfg;
		}
		return $out;
	}

	/**
	 * Get all servers.
	 *
	 * Merges config file defaults with WordPress option values.
	 * WordPress option values override config file defaults.
	 *
	 * @return array Associative array of server_id => config.
	 */
	public function get_all(): array {
		if ( null === $this->servers ) {
			// Read config-file defaults DIRECTLY, not via load_config().
			// load_config caches the merged config (file + WP options), and
			// since WP_OPTION_KEY === 'newspack_event_logger_nodes_aggregator_servers'
			// it ALSO lives in the option schema, so the cache's
			// `aggregator_servers` is the merged WP-option view at first read.
			// Subsequent WP-option mutations (admin add/remove) don't invalidate
			// that cache until `plugins_loaded`'s one-shot reset, so reading the
			// merged value here would resurrect-zombie servers we just deleted.
			// is_config_server() already uses this pattern for the same reason
			// (see its docblock).
			$config_defaults = Config::load_config_defaults()['aggregator_servers'] ?? [];
			if ( ! \is_array( $config_defaults ) ) {
				$config_defaults = [];
			}

			// Get WordPress option (may override config defaults).
			$option = \get_option( self::OPTION_KEY, null );

			if ( null === $option ) {
				// No option set - use config defaults.
				$this->servers = $config_defaults;
			} elseif ( \is_array( $option ) ) {
				// Merge: WordPress option takes precedence.
				$this->servers = \array_merge( $config_defaults, $option );
			} else {
				$this->servers = $config_defaults;
			}

			// Normalize: ensure all entries have required keys (config-file entries
			// bypass validate_config and may be missing 'logs', 'enabled', etc.).
			foreach ( $this->servers as $id => &$server ) {
				if ( ! \is_array( $server ) ) {
					unset( $this->servers[ $id ] );
					continue;
				}
				$server += [
					'url'           => '',
					'auth_username' => '',
					'auth_password' => '',
					'enabled'       => true,
					'logs'          => [ 'firehose.log' ],
				];
				// Decrypt credentials (handles both encrypted and legacy plaintext).
				if ( '' !== $server['auth_password'] ) {
					$server['auth_password'] = self::decrypt( $server['auth_password'] );
				}
			}
			unset( $server );
		}
		return $this->servers;
	}

	/**
	 * Get only enabled servers.
	 *
	 * @return array<string,array>
	 */
	public function get_enabled(): array {
		return \array_filter(
			$this->get_all(),
			static function ( $config ) {
				return ! empty( $config['enabled'] );
			}
		);
	}

	/**
	 * Get a specific server by ID.
	 *
	 * @param string $id Server ID.
	 * @return array|null Server config or null if not found.
	 */
	public function get( string $id ): ?array {
		$servers = $this->get_all();
		return $servers[ $id ] ?? null;
	}

	/**
	 * Check whether a server ID originates from the config file.
	 *
	 * Reads file-only defaults via `Config::load_config_defaults()` to avoid the
	 * circular case where `load_config()` would merge the WP option into
	 * `aggregator_servers` and make every WP-option server look like a config
	 * server.
	 *
	 * @param string $id Server ID.
	 * @return bool True if the server is defined in the config file.
	 */
	public function is_config_server( string $id ): bool {
		$defaults = Config::load_config_defaults();
		$file     = $defaults['aggregator_servers'] ?? [];
		return \is_array( $file ) && isset( $file[ $id ] );
	}

	/**
	 * Add a new server (full overwrite if the caller supplies a complete config).
	 *
	 * Returns false if:
	 *  - id format invalid
	 *  - id already exists in the merged view
	 *  - registry at capacity
	 *  - validation fails (URL, credentials, logs)
	 *
	 * @param string $id     Server ID (alphanumeric, hyphen, underscore; 1-64 chars).
	 * @param array  $config Server configuration.
	 */
	public function add( string $id, array $config ): bool {
		if ( ! self::is_valid_id( $id ) ) {
			return false;
		}
		$all = $this->get_all();
		if ( isset( $all[ $id ] ) ) {
			return false;
		}
		if ( \count( $all ) >= self::MAX_SERVERS ) {
			return false;
		}
		$validated = $this->validate_config( $config );
		if ( null === $validated ) {
			return false;
		}

		$wp_servers        = $this->get_wp_servers();
		$wp_servers[ $id ] = $validated;

		// update_option() returns false on both failure and no-op — verify by re-read.
		self::write_option( $wp_servers );
		$this->servers = null;

		$verify = $this->get_wp_servers();
		if ( ! isset( $verify[ $id ] ) ) {
			return false;
		}

		$this->audit( 'added', $id, \array_keys( $validated ) );
		return true;
	}

	/**
	 * Register a server (full overwrite — same key path as `add()` but allows
	 * overwriting an existing entry). Mirrors the prototype's `register()`
	 * verb. Used by callers that don't distinguish add-vs-update at their level.
	 *
	 * @param string $id     Server ID.
	 * @param array  $config Full server configuration.
	 */
	public function register( string $id, array $config ): bool {
		if ( ! self::is_valid_id( $id ) ) {
			return false;
		}
		$all = $this->get_all();
		if ( ! isset( $all[ $id ] ) && \count( $all ) >= self::MAX_SERVERS ) {
			return false;
		}
		$validated = $this->validate_config( $config );
		if ( null === $validated ) {
			return false;
		}

		$wp_servers        = $this->get_wp_servers();
		$wp_servers[ $id ] = $validated;

		self::write_option( $wp_servers );
		$this->servers = null;

		$verify = $this->get_wp_servers();
		if ( ! isset( $verify[ $id ] ) ) {
			return false;
		}

		$this->audit( 'registered', $id, \array_keys( $validated ) );
		return true;
	}

	/**
	 * Partial-update an existing server. Whitelists keys, merges with current
	 * config, then validates the result.
	 *
	 * Config-file servers (immutable) only allow toggling `enabled` — URL and
	 * credentials are pinned. Other fields in the partial are silently dropped
	 * for those entries.
	 *
	 * @param string $id      Server ID.
	 * @param array  $partial Partial configuration to merge.
	 */
	public function update( string $id, array $partial ): bool {
		if ( ! self::is_valid_id( $id ) ) {
			return false;
		}
		$all = $this->get_all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}

		// Config-file servers — only `enabled` is editable.
		if ( $this->is_config_server( $id ) ) {
			if ( ! \array_key_exists( 'enabled', $partial ) ) {
				return false;
			}
			$partial = [ 'enabled' => $partial['enabled'] ];
		}

		// Whitelist keys before merge.
		$partial = \array_intersect_key( $partial, \array_flip( self::ALLOWED_KEYS ) );

		$merged    = \array_merge( $all[ $id ], $partial );
		$validated = $this->validate_config( $merged );
		if ( null === $validated ) {
			return false;
		}

		$wp_servers        = $this->get_wp_servers();
		$wp_servers[ $id ] = $validated;

		self::write_option( $wp_servers );
		$this->servers = null;

		$verify = $this->get_wp_servers();
		if ( ! isset( $verify[ $id ] ) ) {
			return false;
		}

		$this->audit( 'updated', $id, \array_keys( $partial ) );
		return true;
	}

	/**
	 * Remove a server.
	 *
	 * Config-file servers can't be removed via the API — they reappear on next
	 * read. Returns false for those entries.
	 *
	 * @param string $id Server ID.
	 */
	public function remove( string $id ): bool {
		if ( ! self::is_valid_id( $id ) ) {
			return false;
		}
		$all = $this->get_all();
		if ( ! isset( $all[ $id ] ) ) {
			return false;
		}
		if ( $this->is_config_server( $id ) ) {
			return false;
		}

		$wp_servers = $this->get_wp_servers();
		unset( $wp_servers[ $id ] );

		self::write_option( $wp_servers );
		$this->servers = null;

		$verify = $this->get_wp_servers();
		if ( isset( $verify[ $id ] ) ) {
			return false;
		}

		$this->audit( 'removed', $id, [] );
		return true;
	}

	/**
	 * Reset the in-process cache so the next read rebuilds from disk + option.
	 *
	 * Long-running workers (JobWorker) call this between jobs so post-admin
	 * updates are visible without a process restart.
	 */
	public function reset_cache(): void {
		$this->servers = null;
	}

	/**
	 * Validate a server ID format.
	 *
	 * @param string $id Server ID to validate.
	 * @return bool True if valid.
	 */
	public static function is_valid_id( string $id ): bool {
		return 1 === \preg_match( '/^[a-zA-Z0-9_-]{1,64}$/', $id );
	}

	/**
	 * Get only the WP-option-managed servers (excludes config-file defaults).
	 *
	 * Write paths use this so we never accidentally persist a config-file
	 * default into the WP option (would shadow file changes forever).
	 *
	 * @return array<string,array>
	 */
	private function get_wp_servers(): array {
		$option = \get_option( self::OPTION_KEY, [] );
		return \is_array( $option ) ? $option : [];
	}

	/**
	 * Persist the WP-managed server map.
	 *
	 * Uses 3-arg form when WP's full update_option is available (the third arg
	 * marks the option as non-autoloaded so it doesn't bloat every request's
	 * option cache); falls back to 2-arg for stripped-down test stubs.
	 *
	 * @param array $wp_servers Map of id => validated config.
	 */
	private static function write_option( array $wp_servers ): void {
		$arity = self::update_option_arity();
		if ( $arity >= 3 ) {
			\update_option( self::OPTION_KEY, $wp_servers, false );
		} else {
			\update_option( self::OPTION_KEY, $wp_servers );
		}
	}

	/**
	 * Reflect on update_option once to determine its parameter count.
	 *
	 * Production WP defines a 3-arg update_option (option, value, autoload).
	 * Test bootstraps may define a 2-arg fake. Cached for the process.
	 */
	private static function update_option_arity(): int {
		static $arity = null;
		if ( null === $arity ) {
			try {
				$arity = ( new \ReflectionFunction( 'update_option' ) )->getNumberOfParameters();
			} catch ( \ReflectionException $e ) {
				$arity = 2;
			}
		}
		return $arity;
	}

	/**
	 * Validate and sanitize a full server configuration.
	 *
	 * @param array $config Raw configuration.
	 * @return array|null Validated configuration or null if invalid.
	 */
	private function validate_config( array $config ): ?array {
		// URL is required, must be string, must be HTTPS.
		if ( empty( $config['url'] ) || ! \is_string( $config['url'] ) ) {
			return null;
		}
		$url = \function_exists( 'esc_url_raw' )
			? \esc_url_raw( $config['url'] )
			: $config['url'];
		if ( empty( $url ) || ! \is_string( $url ) ) {
			return null;
		}
		if ( 0 !== \strpos( $url, 'https://' ) ) {
			return null;
		}

		$validated = [
			'url'           => \rtrim( $url, '/' ),
			'auth_username' => '',
			'auth_password' => '',
			'enabled'       => true,
			'logs'          => [ 'firehose.log' ],
		];

		// auth_username — sanitize + 256-byte cap.
		if ( ! empty( $config['auth_username'] ) && \is_string( $config['auth_username'] ) ) {
			$username = \function_exists( 'sanitize_text_field' )
				? \sanitize_text_field( $config['auth_username'] )
				: \trim( \preg_replace( '/[\x00-\x1f\x7f]/', '', $config['auth_username'] ) ?? '' );
			if ( \strlen( $username ) > 256 ) {
				$username = \substr( $username, 0, 256 );
			}
			$validated['auth_username'] = $username;
		}

		// auth_password — strip control chars, 256-byte cap, encrypt.
		if ( ! empty( $config['auth_password'] ) && \is_string( $config['auth_password'] ) ) {
			$password = $config['auth_password'];
			if ( 0 !== \strpos( $password, self::ENCRYPTED_PREFIX ) ) {
				// New plaintext password — sanitize, cap, encrypt.
				$password = \preg_replace( '/[\x00-\x1f\x7f]/', '', $password ) ?? '';
				if ( \strlen( $password ) > 256 ) {
					$password = \substr( $password, 0, 256 );
				}
				$password = self::encrypt( $password );
			} else {
				// Already encrypted — verify it actually decrypts; reject if not.
				$decrypted = self::decrypt( $password );
				if ( '' === $decrypted ) {
					$password = '';
				}
			}
			$validated['auth_password'] = $password;
		}

		// enabled flag.
		if ( \array_key_exists( 'enabled', $config ) ) {
			$validated['enabled'] = (bool) $config['enabled'];
		}

		// logs[] — filename allowlist.
		if ( ! empty( $config['logs'] ) && \is_array( $config['logs'] ) ) {
			$logs = [];
			foreach ( $config['logs'] as $log ) {
				if ( \is_string( $log ) && 1 === \preg_match( '/^[a-zA-Z0-9_.-]+\.log$/', $log ) ) {
					$logs[] = $log;
				}
			}
			if ( ! empty( $logs ) ) {
				$validated['logs'] = $logs;
			}
		}

		return $validated;
	}

	/**
	 * Derive a 32-byte encryption key from `wp_salt('auth')`.
	 *
	 * @return string 32-byte key for sodium_crypto_secretbox.
	 */
	private static function encryption_key(): string {
		return \sodium_crypto_generichash( \wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt a string for storage.
	 *
	 * Returns the wire-format string (`$enc$<base64>`) on success, or empty on
	 * failure / empty input.
	 *
	 * @param string $plaintext Value to encrypt.
	 */
	private static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! \function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		$nonce      = \random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = \sodium_crypto_secretbox( $plaintext, $nonce, self::encryption_key() );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe storage.
		return self::ENCRYPTED_PREFIX . \base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a stored value.
	 *
	 * Handles both encrypted (prefixed) and legacy plaintext values. A pre-
	 * encryption row passes through unchanged so spoke upgrades don't break.
	 *
	 * @param string $stored Stored value (may be encrypted or plaintext).
	 * @return string Decrypted plaintext, original value if not encrypted, or empty on decrypt failure.
	 */
	private static function decrypt( string $stored ): string {
		if ( ! \function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary-safe storage.
		$decoded = \base64_decode( \substr( $stored, \strlen( self::ENCRYPTED_PREFIX ) ), true );
		if ( false === $decoded || \strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce      = \substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = \substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = \sodium_crypto_secretbox_open( $ciphertext, $nonce, self::encryption_key() );
		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Append an audit-trail entry to PHP error_log.
	 *
	 * Goes to error_log (not LogManager) intentionally: avoids feedback loops if
	 * the log pipeline itself is unhealthy.
	 *
	 * @param string $action Verb: added | updated | removed | registered.
	 * @param string $id     Server ID acted upon.
	 * @param array  $fields Field names (sanitized — never values).
	 */
	private function audit( string $action, string $id, array $fields ): void {
		$user_id  = \function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0;
		$ts       = \gmdate( 'c' );
		$fieldstr = empty( $fields ) ? '' : ' fields=' . \implode( ',', $fields );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		\error_log(
			\sprintf(
				'[EventLogger] ServerRegistry %s id=%s user=%d ts=%s%s',
				$action,
				$id,
				$user_id,
				$ts,
				$fieldstr
			)
		);
	}
}
