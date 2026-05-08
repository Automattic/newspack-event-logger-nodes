<?php
/**
 * Config: file overlay + WordPress-option configuration for newspack-event-logger-nodes.
 *
 * Port of `\Newspack_Event_Logger\Config` from the legacy `newspack-event-logger`
 * plugin, adapted for the nodes runtime:
 *  - Two-tier load schema: `core` (request-path, cheap) and `full` (worker/admin).
 *  - File overlay loaded from the plugin root (`newspack-nodes-config.php`) plus
 *    optional env-var override (`LOCAL_NEWSPACK_NODES_CONF`).
 *  - WP-option overrides keyed by `newspack_event_logger_nodes_*`.
 *  - Default `base_directory` flows from the runtime's `newspack_nodes/base_dir`
 *    filter (same path the runtime's Bootstrap, WorkerBase, and Cli rely on),
 *    so this Config layers cleanly over the runtime substrate.
 *  - `get_logs_directory()` / `get_locks_directory()` / `get_offsets_directory()`
 *    return `{base}/logs|locks|offsets` and ensure_path them once (cached).
 *  - `kill_readers([groups])` — plugin-deactivation helper. Walks
 *    `{base_dir}/locks/*.lock.d/`, finds dirs whose name starts with one of the
 *    given group names, and writes the restart flag via a Lock instance per dir.
 *
 * Cache lifecycle: schema additions from late-loading plugins (alphabetically
 * after this one) won't be visible if `load_config()` runs before `plugins_loaded`.
 * `register_cache_invalidation()` hooks a one-shot reset on `plugins_loaded` at
 * `PHP_INT_MIN` so the next read picks up the full filter chain.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Lock;

class Config {
	/**
	 * Cached config (file defaults + WordPress options).
	 *
	 * @var array|null
	 */
	private static $config = null;

	/**
	 * Cached full config (includes extended options).
	 *
	 * @var array|null
	 */
	private static $config_full = null;

	/**
	 * Cached config defaults from files.
	 *
	 * @var array|null
	 */
	private static $config_defaults = null;

	/** Cached validated base directory. */
	private static ?string $validated_base_directory = null;

	/** Cached validated logs directory. */
	private static ?string $validated_logs_directory = null;

	/** Cached validated locks directory. */
	private static ?string $validated_locks_directory = null;

	/** Cached validated offsets directory. */
	private static ?string $validated_offsets_directory = null;

	/**
	 * Allowed directories for local config override files.
	 *
	 * Only config files within these directories (or subdirectories) are
	 * allowed to be loaded via the `LOCAL_NEWSPACK_NODES_CONF` env var. The
	 * plugin's own directory is appended in `validate_config_path()` so it's
	 * always permitted.
	 *
	 * @var string[]
	 */
	private static $allowed_config_dirs = [
		'/usr/src',
	];

	/**
	 * Get core option schema - loaded on every request (autoloaded).
	 *
	 * Plugins can add their core options via the
	 * `newspack_event_logger_nodes_option_schema_core` filter. Core options are
	 * needed for request logging and hook timing.
	 *
	 * @return array Associative array of `option_name => type`.
	 */
	private static function get_option_schema_core(): array {
		$schema = [
			'enable_logging' => 'bool',
			'enable_jobs'    => 'bool',
			'base_directory' => 'path',
			'num_partitions' => 'int',
			'num_segments'   => 'int',
			'segment_size'   => 'int',
			'max_lifespan'   => 'int',
		];

		if ( \function_exists( 'apply_filters' ) ) {
			$schema = \apply_filters( 'newspack_event_logger_nodes_option_schema_core', $schema );
		}

		return \is_array( $schema ) ? $schema : [];
	}

	/**
	 * Get extended option schema - only loaded for workers/admin (not autoloaded).
	 *
	 * Plugins can add their extended options via the
	 * `newspack_event_logger_nodes_option_schema_extended` filter. Extended
	 * options are only needed by workers (FlameBuilder) and admin settings.
	 *
	 * @return array Associative array of `option_name => type`.
	 */
	private static function get_option_schema_extended(): array {
		$schema = [
			'memcache_servers' => 'memcache_servers',
		];

		if ( \function_exists( 'apply_filters' ) ) {
			$schema = \apply_filters( 'newspack_event_logger_nodes_option_schema_extended', $schema );
		}

		return \is_array( $schema ) ? $schema : [];
	}

	/**
	 * Load configuration from disk + WordPress options.
	 *
	 * @param string $mode 'core' (default) loads only options needed for request
	 *                     logging. 'full' loads all options including
	 *                     worker/admin settings.
	 * @return array Configuration array.
	 */
	public static function load_config( string $mode = 'core' ): array {
		$is_full = 'full' === $mode;

		if ( $is_full && null !== self::$config_full ) {
			return self::$config_full;
		}
		if ( ! $is_full && null !== self::$config ) {
			return self::$config;
		}

		// Load from disk.
		$config = self::load_config_defaults();

		// Override with WordPress options (with sanitization).
		if ( \defined( 'ABSPATH' ) && \function_exists( 'get_option' ) ) {
			// Always load core options.
			foreach ( self::get_option_schema_core() as $key => $type ) {
				$value = \get_option( "newspack_event_logger_nodes_{$key}" );
				if ( false !== $value && '' !== $value ) {
					$sanitized = self::sanitize_option( $value, $type );
					if ( null !== $sanitized ) {
						$config[ $key ] = $sanitized;
					}
				}
			}

			// Load extended options only for 'full' mode.
			if ( $is_full ) {
				foreach ( self::get_option_schema_extended() as $key => $type ) {
					$value = \get_option( "newspack_event_logger_nodes_{$key}" );
					if ( false !== $value && '' !== $value ) {
						$sanitized = self::sanitize_option( $value, $type );
						if ( null !== $sanitized ) {
							$config[ $key ] = $sanitized;
						}
					}
				}
			}
		}

		// Cache the computed config. Late-loading plugins (alphabetically
		// later in the plugin load order) may add to the option schema via
		// the `newspack_event_logger_nodes_option_schema_core` filter AFTER
		// this call, so the main plugin file should hook a one-shot cache
		// reset on `plugins_loaded` at priority PHP_INT_MIN — see
		// `register_cache_invalidation()` below. That guarantees post-
		// plugins_loaded reads pick up the full schema without forcing every
		// pre-plugins_loaded caller to re-run the full filter chain.
		if ( $is_full ) {
			self::$config_full = $config;
		} else {
			self::$config = $config;
		}

		return $config;
	}

	/**
	 * Invalidate cached config so the next load_config() call rebuilds with
	 * the complete schema. Called once on plugins_loaded (see
	 * `register_cache_invalidation()`).
	 */
	public static function invalidate_cache(): void {
		self::$config                      = null;
		self::$config_full                 = null;
		self::$config_defaults             = null;
		self::$validated_base_directory    = null;
		self::$validated_logs_directory    = null;
		self::$validated_locks_directory   = null;
		self::$validated_offsets_directory = null;
	}

	/**
	 * Hook a one-shot cache invalidation on plugins_loaded so that any schema
	 * additions registered by late-loading plugins are picked up by the next
	 * `load_config()` call. Invoked from the plugin main file.
	 */
	public static function register_cache_invalidation(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;
		if ( \function_exists( 'add_action' ) ) {
			\add_action( 'plugins_loaded', [ self::class, 'invalidate_cache' ], PHP_INT_MIN );
		}
	}

	/**
	 * Sanitize an option value based on its type.
	 *
	 * @param mixed  $value The value to sanitize.
	 * @param string $type  The type of sanitization to apply.
	 * @return mixed|null Sanitized value, or null if invalid.
	 */
	private static function sanitize_option( $value, string $type ) {
		switch ( $type ) {
			case 'bool':
				return (bool) $value;

			case 'int':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				return (int) $value;

			case 'float':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				return (float) $value;

			case 'path':
				// Sanitize path: no null bytes, no .., must be absolute.
				if ( ! \is_string( $value ) ) {
					return null;
				}
				$path = \trim( $value );
				if ( false !== \strpos( $path, "\0" ) || false !== \strpos( $path, '..' ) ) {
					return null;
				}
				if ( 0 !== \strpos( $path, '/' ) ) {
					return null;
				}
				return $path;

			case 'memcache_servers':
				// Newline-separated host:port list.
				if ( ! \is_string( $value ) ) {
					return null;
				}
				$servers = \array_filter( \array_map( 'trim', \explode( "\n", $value ) ) );
				if ( empty( $servers ) ) {
					return null;
				}
				$validated = [];
				foreach ( $servers as $server ) {
					if ( \preg_match( '/^[a-zA-Z0-9.\-]+:\d{1,5}$/', $server ) ) {
						$validated[] = $server;
					}
				}
				return empty( $validated ) ? null : $validated;

			case 'array_strings':
				if ( ! \is_array( $value ) ) {
					return null;
				}
				$result = [];
				foreach ( $value as $k => $v ) {
					if ( \is_string( $v ) ) {
						$result[ self::sanitize_string( $k ) ] = self::sanitize_string( $v );
					} elseif ( \is_bool( $v ) || \is_int( $v ) ) {
						// custom_events uses assoc array with true values.
						$result[ self::sanitize_string( $k ) ] = $v;
					}
				}
				return $result;

			case 'aggregator_servers':
				// Sanitize aggregator server configs (keyed by server ID).
				if ( ! \is_array( $value ) ) {
					return null;
				}
				$result = [];
				foreach ( $value as $server_id => $config ) {
					if ( ! \is_array( $config ) ) {
						continue;
					}
					$server_id = self::sanitize_string( $server_id );
					if ( empty( $server_id ) ) {
						continue;
					}
					$url = $config['url'] ?? '';
					// URL must be https.
					if ( ! \is_string( $url ) || 0 !== \strpos( $url, 'https://' ) ) {
						continue;
					}
					$result[ $server_id ] = [
						'url'           => \function_exists( 'esc_url_raw' ) ? \esc_url_raw( $url ) : $url,
						'auth_username' => self::sanitize_string( $config['auth_username'] ?? '' ),
						'auth_password' => self::sanitize_string( $config['auth_password'] ?? '' ),
						'enabled'       => (bool) ( $config['enabled'] ?? true ),
					];
				}
				return $result;

			default:
				return null;
		}
	}

	/**
	 * Sanitize a string value.
	 *
	 * Uses WordPress `sanitize_text_field` if available. Falls back to a basic
	 * coercion when WP isn't loaded (test bootstrap scenarios). Throws only
	 * when the value cannot be coerced to a string.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string Sanitized string.
	 */
	private static function sanitize_string( $value ): string {
		$value = (string) $value;
		if ( \function_exists( 'sanitize_text_field' ) ) {
			return \sanitize_text_field( $value );
		}
		// Minimal fallback: strip control chars + trim. Non-WP unit-test path only.
		return \trim( \preg_replace( '/[\x00-\x1f\x7f]/', '', $value ) ?? '' );
	}

	/**
	 * Load configuration defaults from file only (no WordPress options).
	 *
	 * @return array Configuration defaults from file.
	 */
	public static function load_config_defaults(): array {
		if ( null !== self::$config_defaults ) {
			return self::$config_defaults;
		}

		// Seed with the runtime's base_dir filter, so callers always see a
		// non-empty base_directory even before any file overlay loads.
		$config = [
			'base_directory' => \function_exists( 'apply_filters' )
				? (string) \apply_filters( 'newspack_nodes/base_dir', '/tmp/newspack-nodes' )
				: '/tmp/newspack-nodes',
		];

		// Load main config file.
		$config_path = \dirname( __DIR__ ) . '/newspack-nodes-config.php';
		if ( \file_exists( $config_path ) ) {
			$config = self::load_config_file( $config, $config_path );
		}

		// Load local override if specified (for CLI/testing).
		$local_config_file = \getenv( 'LOCAL_NEWSPACK_NODES_CONF' );
		if ( $local_config_file ) {
			$validated_path = self::validate_config_path( $local_config_file );
			if ( $validated_path && \file_exists( $validated_path ) ) {
				$config = self::load_config_file( $config, $validated_path );
			}
		}

		self::$config_defaults = $config;

		return self::$config_defaults;
	}

	/**
	 * Validate that a config file path is within allowed directories.
	 *
	 * Security: Prevents arbitrary file include via environment variable.
	 *
	 * @param string $path The path to validate.
	 * @return string|null The validated real path, or null if invalid.
	 */
	private static function validate_config_path( string $path ): ?string {
		// Reject null bytes (path injection).
		if ( false !== \strpos( $path, "\0" ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( 'Config::validate_config_path() failed: null byte in path' );
			return null;
		}

		// Must be a .php file.
		if ( '.php' !== \substr( $path, -4 ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf( 'Config::validate_config_path() failed: not .php file (%s)', \preg_replace( '/[\x00-\x1f\x7f]/', '', $path ) ) );
			return null;
		}

		// Check if path is within allowed directories.
		$real_path = null;
		foreach ( self::$allowed_config_dirs as $allowed_dir ) {
			$real_path = self::is_within( $path, $allowed_dir );
			if ( $real_path ) {
				break;
			}
		}

		// Also allow plugin directory itself.
		if ( ! $real_path ) {
			$plugin_dir = \dirname( __DIR__ );
			$real_path  = self::is_within( $path, $plugin_dir );
		}

		if ( ! $real_path ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( \sprintf( 'Config::validate_config_path() failed: path not found or not in allowed directories (%s)', \preg_replace( '/[\x00-\x1f\x7f]/', '', $path ) ) );
		}

		return $real_path;
	}

	/**
	 * Get custom colors with filter applied (for admin UI).
	 *
	 * Applies the `newspack_event_logger_nodes_custom_colors` filter lazily,
	 * allowing plugins that load after this one to register their events. Also
	 * merges discovered events from remote spokes (option:
	 * `newspack_event_logger_nodes_discovered_events`).
	 *
	 * @return array Associative array of `event_name => hex_color`.
	 */
	public static function get_custom_colors(): array {
		$config = self::load_config();
		$colors = $config['custom_colors'] ?? [];

		if ( \function_exists( 'apply_filters' ) ) {
			$colors = \apply_filters( 'newspack_event_logger_nodes_custom_colors', $colors );
			if ( ! \is_array( $colors ) ) {
				$colors = [];
			}
		}

		// Merge discovered events from remote spokes (available but not selected).
		if ( \function_exists( 'get_option' ) ) {
			$discovered = \get_option( 'newspack_event_logger_nodes_discovered_events', [] );
			if ( \is_array( $discovered ) ) {
				foreach ( $discovered as $event => $color ) {
					if ( ! isset( $colors[ $event ] ) ) {
						$colors[ $event ] = \is_string( $color ) ? $color : '#ffa726';
					}
				}
			}
		}

		// Sort alphabetically so events are easier to find in the UI.
		\ksort( $colors, SORT_NATURAL | SORT_FLAG_CASE );

		return $colors;
	}

	/**
	 * Reset cached config - call before load_config() to get fresh values.
	 */
	public static function reset(): void {
		self::$config                      = null;
		self::$config_full                 = null;
		self::$config_defaults             = null;
		self::$validated_base_directory    = null;
		self::$validated_logs_directory    = null;
		self::$validated_locks_directory   = null;
		self::$validated_offsets_directory = null;
	}

	/**
	 * Ensure a directory path exists and is canonical.
	 *
	 * Creates the directory if it doesn't exist, then validates that
	 * `realpath()` matches the input (detects symlink attacks).
	 *
	 * @param string $path Directory path to ensure.
	 * @return string Validated canonical path.
	 * @throws \RuntimeException If path cannot be created or is not canonical.
	 */
	public static function ensure_path( string $path ): string {
		// Reject null bytes before any filesystem operations.
		if ( false !== \strpos( $path, "\0" ) ) {
			throw new \RuntimeException( 'Path contains null byte' );
		}

		$path = \rtrim( $path, '/' );

		if ( ! \is_dir( $path ) ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.directory_mkdir
			@\mkdir( $path, 0755, true );
		}

		$real = \realpath( $path );
		if ( false === $real ) {
			throw new \RuntimeException(
				\sprintf( 'Failed to create directory: %s', self::escape( $path ) )
			);
		}

		// Canonical path must match input (prevents symlink attacks).
		if ( $real !== $path ) {
			throw new \RuntimeException(
				\sprintf(
					'Path %s resolves to %s - symlink or path traversal detected',
					self::escape( $path ),
					self::escape( $real )
				)
			);
		}

		return $real;
	}

	/**
	 * Tiny escape helper used inside exception messages so we don't depend on
	 * `esc_html` being loaded in CLI / unit-test contexts.
	 */
	private static function escape( string $s ): string {
		return \function_exists( 'esc_html' )
			? \esc_html( $s )
			: \htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Check if a path is within a base directory.
	 *
	 * Resolves the path to its canonical form and checks containment.
	 * Returns the canonical path on success, null on failure.
	 *
	 * @param string $path Path to check.
	 * @param string $base Base directory that path must be within.
	 * @return string|null Canonical path if within base, null otherwise.
	 */
	private static function is_within( string $path, string $base ): ?string {
		$real_path = \realpath( $path );
		$real_base = \realpath( $base );

		if ( false === $real_path || false === $real_base ) {
			return null;
		}

		$real_base = \rtrim( $real_base, '/' ) . '/';
		$within    = 0 === \strpos( $real_path, $real_base ) || $real_path === \rtrim( $real_base, '/' );

		return $within ? $real_path : null;
	}

	/**
	 * Get the validated base directory path.
	 *
	 * Returns the configured base_directory after validating that `realpath()`
	 * matches. Creates the directory if it doesn't exist. If realpath differs
	 * from the configured path, a symlink attack may be in progress.
	 *
	 * @return string Validated absolute path to base directory.
	 * @throws \RuntimeException If directory cannot be created or realpath doesn't match.
	 */
	public static function get_base_directory(): string {
		if ( null !== self::$validated_base_directory ) {
			return self::$validated_base_directory;
		}

		$config = self::load_config();
		if ( empty( $config['base_directory'] ) ) {
			throw new \RuntimeException( 'base_directory not configured' );
		}

		self::$validated_base_directory = self::ensure_path( $config['base_directory'] );
		return self::$validated_base_directory;
	}

	/**
	 * Get the logs directory path (`{base}/logs`).
	 */
	public static function get_logs_directory(): string {
		if ( null !== self::$validated_logs_directory ) {
			return self::$validated_logs_directory;
		}
		self::$validated_logs_directory = self::ensure_path( self::get_base_directory() . '/logs' );
		return self::$validated_logs_directory;
	}

	/**
	 * Get the locks directory path (`{base}/locks`).
	 */
	public static function get_locks_directory(): string {
		if ( null !== self::$validated_locks_directory ) {
			return self::$validated_locks_directory;
		}
		self::$validated_locks_directory = self::ensure_path( self::get_base_directory() . '/locks' );
		return self::$validated_locks_directory;
	}

	/**
	 * Get the offsets directory path (`{base}/offsets`).
	 */
	public static function get_offsets_directory(): string {
		if ( null !== self::$validated_offsets_directory ) {
			return self::$validated_offsets_directory;
		}
		self::$validated_offsets_directory = self::ensure_path( self::get_base_directory() . '/offsets' );
		return self::$validated_offsets_directory;
	}

	/**
	 * Force-restart any worker locks whose names start with one of the given
	 * group prefixes.
	 *
	 * Called on plugin deactivation. Walks `{base_dir}/locks/*.lock.d/`,
	 * matches each directory name against the supplied group names, and
	 * fires `Lock::request_restart()` per match. The current lock holder
	 * polls `should_restart()` from its drain loop and exits cleanly the
	 * next tick — no SIGTERM, no force-kill, no race with active writes.
	 *
	 * Lock dir naming convention: `{group}.p{N}.lock.d` (per-partition) or
	 * `{group}.lock.d` (singleton). Both forms are handled by the prefix
	 * match.
	 *
	 * Failures (missing locks dir, unreadable entries, Lock instantiation
	 * problems) are swallowed: deactivation is best-effort, never fatal.
	 *
	 * @param string[] $groups Group-name prefixes to match against lock-dir basenames.
	 */
	public static function kill_readers( array $groups ): void {
		if ( empty( $groups ) ) {
			return;
		}

		try {
			$locks_dir = self::get_locks_directory();
		} catch ( \Throwable $e ) {
			return;
		}

		$entries = @\scandir( $locks_dir );
		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			// Only act on lock dirs.
			if ( '.lock.d' !== \substr( $entry, -7 ) ) {
				continue;
			}
			$path = "{$locks_dir}/{$entry}";
			if ( ! \is_dir( $path ) ) {
				continue;
			}

			// Match by group prefix: `{group}.lock.d` (singleton) OR
			// `{group}.p{N}.lock.d` (partitioned). Either form must begin
			// with `{group}` followed by either `.p` or `.lock.d`.
			$matched = false;
			foreach ( $groups as $group ) {
				$group = (string) $group;
				if ( '' === $group ) {
					continue;
				}
				if ( $entry === "{$group}.lock.d"
					|| 0 === \strpos( $entry, "{$group}.p" )
				) {
					$matched = true;
					break;
				}
			}
			if ( ! $matched ) {
				continue;
			}

			try {
				( new Lock( $path ) )->request_restart();
			} catch ( \Throwable $e ) {
				// Best-effort; carry on with the next dir.
				continue;
			}
		}
	}

	/**
	 * Load a PHP config file.
	 *
	 * Note: This `require`s a PHP file. Only paths within the allowed_config_dirs
	 * whitelist (or the plugin's own dir) reach this code path.
	 *
	 * @param array  $config      Hash of config options.
	 * @param string $config_file Path to config file.
	 * @return array
	 */
	private static function load_config_file( array $config, string $config_file ): array {
		if ( ! \file_exists( $config_file ) ) {
			return $config;
		}

		// Load PHP config file (returns array).
		$parsed_config = require $config_file;
		if ( \is_array( $parsed_config ) && self::validate_config_values( $parsed_config ) ) {
			$config = [ ...$config, ...$parsed_config ];
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( 'Config::load_config_file() rejected: config must return array of scalar/array values only' );
		}

		return $config;
	}

	/**
	 * Validate that config values contain only safe types (scalars and arrays).
	 *
	 * Security: Rejects objects, closures, and resources that could execute
	 * code or leak sensitive data when the config is serialized or accessed.
	 *
	 * @param mixed $value Value to validate.
	 * @param int   $depth Current recursion depth.
	 * @return bool True if value contains only safe types.
	 */
	private static function validate_config_values( $value, int $depth = 0 ): bool {
		if ( $depth > 10 ) {
			return false;
		}

		if ( \is_scalar( $value ) || null === $value ) {
			return true;
		}

		if ( \is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( ! self::validate_config_values( $v, $depth + 1 ) ) {
					return false;
				}
			}
			return true;
		}

		return false;
	}
}
