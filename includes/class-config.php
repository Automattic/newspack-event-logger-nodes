<?php
/**
 * Event Logger Configuration
 *
 * Owns application-level keys (logging toggles, URL filters, hook lists, etc.).
 * Substrate keys (base_directory, partitioning, memcache_servers,
 * enable_workers, aggregator_servers) live on `\Newspack_Nodes\Config`.
 *
 * `load_config()` merges substrate values into the returned array so existing
 * callers reading e.g. `$config['base_directory']` continue to work without
 * having to know which Config to ask. Path-resolution helpers delegate to the
 * substrate Config — only one place owns the realpath/symlink check.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Config as RuntimeConfig;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration management class.
 */
class Config {
	/**
	 * Cached config (file defaults + WordPress options + substrate values).
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

	/**
	 * Get core option schema - loaded on every request (autoloaded).
	 *
	 * Plugins can add their core options via the
	 * 'newspack_event_logger_nodes_option_schema_core' filter. Core options
	 * are needed for request logging and hook timing.
	 *
	 * @return array Associative array of option_name => type.
	 */
	private static function get_option_schema_core(): array {
		// Application core options (substrate keys live on RuntimeConfig).
		$schema = [
			'enable_logging'              => 'bool',
			'enable_jobs'                 => 'bool',
			'log_urls'                    => 'array_strings',
			'skip_urls'                   => 'array_strings',
			'log_events'                  => 'array_strings',
			'custom_events'               => 'array_strings',
			'log_memory'                  => 'bool',
			'flush_every_line'            => 'bool',
			'significant_events'          => 'array_strings',
			'hook_start_priority'         => 'int',
			'allowed_users'               => 'array_strings',
			'auto_disable_threshold'      => 'int',
			'auto_protect_time_threshold' => 'float',
		];

		// Allow plugins to add their core options.
		if ( \function_exists( 'apply_filters' ) ) {
			$schema = \apply_filters( 'newspack_event_logger_nodes_option_schema_core', $schema );
		}

		return \is_array( $schema ) ? $schema : [];
	}

	/**
	 * Get extended option schema - only loaded for workers/admin (not autoloaded).
	 *
	 * Plugins can add their extended options via the
	 * 'newspack_event_logger_nodes_option_schema_extended' filter. Extended
	 * options are only needed by workers (FlameBuilder) and admin settings.
	 *
	 * @return array Associative array of option_name => type.
	 */
	private static function get_option_schema_extended(): array {
		// Reserved for future application-level extended options. Empty by
		// default; substrate-level extended options (memcache_servers,
		// aggregator_servers) live on RuntimeConfig and are merged into the
		// returned config via load_config().
		$schema = [];

		// Allow plugins to add their extended options.
		if ( \function_exists( 'apply_filters' ) ) {
			$schema = \apply_filters( 'newspack_event_logger_nodes_option_schema_extended', $schema );
		}

		return \is_array( $schema ) ? $schema : [];
	}

	/**
	 * Allowed directories for local config override files.
	 *
	 * Only config files within these directories (or subdirectories) are allowed.
	 */
	private static $allowed_config_dirs = [
		'/usr/src',
	];

	/**
	 * Load configuration from disk + WordPress options.
	 *
	 * Merges the substrate config (`Newspack_Nodes\Config::load_config`) so
	 * callers that read substrate keys (`base_directory`, `num_partitions`,
	 * `memcache_servers`, etc.) keep working without having to know about the
	 * layering split.
	 *
	 * @param string $mode 'core' (default) loads only options needed for request logging.
	 *                     'full' loads all options including worker/admin settings.
	 * @return array Configuration array.
	 */
	public static function load_config( string $mode = 'core' ): array {
		$is_full = 'full' === $mode;

		// Return cached config if available.
		if ( $is_full && null !== self::$config_full ) {
			return self::$config_full;
		}
		if ( ! $is_full && null !== self::$config ) {
			return self::$config;
		}

		// Layer substrate config first; application values win on key
		// collisions. Substrate `load_config()` already handles the
		// `newspack_nodes/base_dir` filter and the substrate sample overlay.
		$substrate = \class_exists( RuntimeConfig::class ) ? RuntimeConfig::load_config( $mode ) : [];

		// Load application defaults from disk.
		$config = \array_merge( $substrate, self::load_config_defaults() );

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
		// the `newspack_event_logger_nodes_option_schema_core` filter AFTER this
		// call, so the main plugin file hooks a one-shot cache reset on
		// `plugins_loaded` at priority PHP_INT_MIN — see the
		// `register_cache_invalidation` static initializer below. That
		// guarantees post-plugins_loaded reads pick up the full schema
		// without forcing every pre-plugins_loaded caller to re-run the
		// full filter chain.
		if ( $is_full ) {
			self::$config_full = $config;
		} else {
			self::$config = $config;
		}

		return $config;
	}

	/**
	 * Invalidate cached config so the next load_config() call rebuilds with
	 * the complete schema. Called once on plugins_loaded (see register_cache_invalidation).
	 */
	public static function invalidate_cache(): void {
		self::$config          = null;
		self::$config_full     = null;
		self::$config_defaults = null;
	}

	/**
	 * Hook a one-shot cache invalidation on plugins_loaded so that any
	 * schema additions registered by late-loading plugins are picked up by
	 * the next load_config() call. Invoked from the plugin main file.
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
				// Reject null bytes and directory traversal.
				if ( false !== \strpos( $path, "\0" ) || false !== \strpos( $path, '..' ) ) {
					return null;
				}
				// Must be absolute path.
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
					// Must match host:port pattern.
					if ( \preg_match( '/^[a-zA-Z0-9.\-]+:\d{1,5}$/', $server ) ) {
						$validated[] = $server;
					}
				}
				return empty( $validated ) ? null : $validated;

			case 'array_strings':
				// Sanitize array of strings.
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
						'url'           => \esc_url_raw( $url ),
						'auth_username' => self::sanitize_string( $config['auth_username'] ?? '' ),
						'auth_password' => self::sanitize_string( $config['auth_password'] ?? '' ),
						'enabled'       => (bool) ( $config['enabled'] ?? true ),
					];
				}
				return $result;

			default:
				// Unknown type - reject.
				return null;
		}
	}

	/**
	 * Sanitize a string value.
	 *
	 * Uses WordPress sanitize_text_field if available.
	 * Throws RuntimeException if WordPress unavailable (fail-fast pattern).
	 *
	 * @param mixed $value The value to sanitize.
	 * @return string Sanitized string.
	 * @throws \RuntimeException If sanitize_text_field unavailable.
	 */
	private static function sanitize_string( $value ): string {
		$value = (string) $value;
		if ( ! \function_exists( 'sanitize_text_field' ) ) {
			throw new \RuntimeException( 'sanitize_text_field unavailable - WordPress required for sanitization' );
		}
		return \sanitize_text_field( $value );
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

		$config = [];

		// Load main config file.
		$config_path = \dirname( __DIR__ ) . '/newspack-event-logger-nodes-config.php';
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

		// Check if path is within allowed directories (is_within returns canonical path or null).
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
	 * This method applies the newspack_event_logger_nodes_custom_colors filter lazily,
	 * allowing plugins that load after Event Logger to register their events.
	 *
	 * @return array Associative array of event_name => hex_color.
	 */
	public static function get_custom_colors(): array {
		$config = self::load_config();
		$colors = $config['custom_colors'] ?? [];

		// Apply filter to allow plugins to register custom events.
		if ( \function_exists( 'apply_filters' ) ) {
			$colors = \apply_filters( 'newspack_event_logger_nodes_custom_colors', $colors );
			// Validate filter return type (may return any type).
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
	 *
	 * Resets the substrate Config too so the layered view rebuilds from scratch.
	 */
	public static function reset(): void {
		self::$config          = null;
		self::$config_full     = null;
		self::$config_defaults = null;
		if ( \class_exists( RuntimeConfig::class ) ) {
			RuntimeConfig::reset();
		}
	}

	/**
	 * Ensure a directory path exists and is canonical.
	 *
	 * Delegates to the substrate Config so realpath/symlink validation lives
	 * in one place.
	 *
	 * @param string $path Directory path to ensure.
	 * @return string Validated canonical path.
	 * @throws \RuntimeException If path cannot be created or is not canonical.
	 */
	public static function ensure_path( string $path ): string {
		return RuntimeConfig::ensure_path( $path );
	}

	/**
	 * Get the validated base directory path.
	 *
	 * Delegates to the substrate Config — `base_directory` is a substrate key.
	 *
	 * @return string Validated absolute path to base directory.
	 */
	public static function get_base_directory(): string {
		return RuntimeConfig::get_base_directory();
	}

	/**
	 * Get the logs directory path ({base}/logs).
	 *
	 * @return string Validated absolute path to logs directory.
	 */
	public static function get_logs_directory(): string {
		return RuntimeConfig::get_logs_directory();
	}

	/**
	 * Get the locks directory path ({base}/locks).
	 *
	 * @return string Validated absolute path to locks directory.
	 */
	public static function get_locks_directory(): string {
		return RuntimeConfig::get_locks_directory();
	}

	/**
	 * Get the offsets directory path ({base}/offsets).
	 *
	 * @return string Validated absolute path to offsets directory.
	 */
	public static function get_offsets_directory(): string {
		return RuntimeConfig::get_offsets_directory();
	}

	/**
	 * Force-restart any worker locks whose names start with one of the given
	 * group prefixes. Delegates to the substrate Config.
	 *
	 * @param string[] $groups Group-name prefixes to match against lock-dir basenames.
	 */
	public static function kill_readers( array $groups ): void {
		RuntimeConfig::kill_readers( $groups );
	}

	/**
	 * Load a PHP config file.
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
		// Note: This executes PHP code. Allowed directories should be tightly controlled.
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
	 * Security: Rejects objects, closures, and resources that could execute code
	 * or leak sensitive data when the config is serialized or accessed.
	 *
	 * @param mixed $value Value to validate.
	 * @param int   $depth Current recursion depth.
	 * @return bool True if value contains only safe types.
	 */
	private static function validate_config_values( $value, int $depth = 0 ): bool {
		// Prevent excessive recursion.
		if ( $depth > 10 ) {
			return false;
		}

		// Allow scalars (string, int, float, bool) and null.
		if ( \is_scalar( $value ) || null === $value ) {
			return true;
		}

		// Allow arrays, validate contents recursively.
		if ( \is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( ! self::validate_config_values( $v, $depth + 1 ) ) {
					return false;
				}
			}
			return true;
		}

		// Reject objects, closures, resources, etc.
		return false;
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

		// Must be within base directory.
		$real_base = \rtrim( $real_base, '/' ) . '/';
		$within    = 0 === \strpos( $real_path, $real_base ) || $real_path === \rtrim( $real_base, '/' );

		return $within ? $real_path : null;
	}
}
