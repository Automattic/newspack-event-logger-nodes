<?php
/**
 * Hook Categorizer
 *
 * Auto-categorizes WordPress hooks using patterns.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.

/**
 * Hook Categorizer class.
 */
class Hook_Categorizer {

	/**
	 * Maximum pattern length to prevent ReDoS attacks.
	 */
	const MAX_PATTERN_LENGTH = 100;

	/**
	 * Option name for user customizations.
	 */
	const OPTION_NAME = 'newspack_event_logger_nodes_hook_customizations';

	/**
	 * file_get_contents seam. Lazily-defaulted to a closure wrapping the real
	 * read of hook_categories.json. Tests reassign to return false so the
	 * read-failure guard in get_base_config() runs as production code.
	 *
	 * Signature: `function ( string $path ): string|false`.
	 *
	 * @var \Closure(string): (string|false)|null
	 */
	public static ?\Closure $read_file = null;

	/**
	 * Cached base config from JSON file.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $base_config = null;

	/**
	 * Cached merged config (base + user customizations).
	 *
	 * @var array{colors: array<string, mixed>, patterns: array<string, mixed>, overrides: array<string, mixed>}|null
	 */
	private static ?array $merged_config = null;

	/**
	 * Get registered hooks grouped by category.
	 *
	 * @return array<string, mixed> Associative array of category => [hooks...].
	 */
	public static function get_registered_hooks_by_category(): array {
		$hooks      = self::get_registered_hooks();
		$categories = self::get_categories();

		// Initialize all categories.
		$grouped = [];
		foreach ( \array_keys( $categories ) as $category ) {
			$grouped[ $category ] = [];
		}

		// Skip Event Logger's own internal filters (no-op + past reentry loop).
		foreach ( $hooks as $hook ) {
			if ( self::is_internal( $hook ) ) {
				continue;
			}
			$category                = self::categorize( $hook );
			$grouped[ $category ][] = $hook;
		}

		// Remove empty categories.
		return \array_filter( $grouped );
	}

	/**
	 * Get all registered hooks from WordPress.
	 *
	 * @return array<int, string> Array of hook names that have callbacks attached.
	 */
	public static function get_registered_hooks(): array {
		/** @var array<string, \WP_Hook> $wp_filter WordPress global. */
		global $wp_filter;

		$hooks = [];
		foreach ( $wp_filter as $hook_name => $hook_obj ) {
			if ( ! empty( $hook_obj->callbacks ) ) {
				$hooks[ $hook_name ] = true;
			}
		}

		// Include already-selected hooks so worker-only ones still show.
		foreach ( self::selected_hooks() as $hook ) {
			if ( '' !== $hook ) {
				$hooks[ $hook ] = true;
			}
		}

		// Include spoke-discovered hooks even if not registered locally.
		$discovered = \get_option( Config::OPTION_DISCOVERED_HOOKS, [] );
		if ( \is_array( $discovered ) ) {
			foreach ( \array_keys( $discovered ) as $hook ) {
				$hook = (string) $hook;
				if ( '' !== $hook ) {
					$hooks[ $hook ] = true;
				}
			}
		}

		$result = \array_keys( $hooks );
		\sort( $result );
		return $result;
	}

	/**
	 * The instrumented-hook set the operator has selected: the union of hooks
	 * across every LOG rule in the durable ruleset.
	 *
	 * @return string[]
	 */
	public static function selected_hooks(): array {
		return Rule_Set::load()->instrumented_union()['hooks'];
	}

	/**
	 * Get all categories with their colors.
	 *
	 * @return array<string, mixed> Associative array of category => color.
	 */
	public static function get_categories(): array {
		$config = self::get_merged_config();
		return $config['colors'];
	}

	/**
	 * Get merged configuration (base + user customizations).
	 *
	 * @return array{colors: array<string, mixed>, patterns: array<string, mixed>, overrides: array<string, mixed>} Merged configuration.
	 */
	public static function get_merged_config(): array {
		if ( null !== self::$merged_config ) {
			return self::$merged_config;
		}

		$base          = self::get_base_config();
		$customizations = self::get_user_customizations();

		$base_colors    = $base['_colors'] ?? [];
		$user_colors    = $customizations['colors'] ?? [];
		$base_patterns  = $base['_patterns'] ?? [];
		$user_patterns_all = $customizations['patterns'] ?? [];
		$overrides      = $customizations['overrides'] ?? [];

		// Merge colors (user overrides base).
		/** @var array<string, mixed> $colors config dynamic output. */
		$colors = \array_merge( Core::arr( $base_colors ), Core::arr( $user_colors ) );

		// Merge patterns (user patterns added to base).
		/** @var array<string, mixed> $patterns config dynamic output. */
		$patterns = Core::arr( $base_patterns );
		if ( \is_array( $user_patterns_all ) ) {
			foreach ( $user_patterns_all as $raw_category => $user_patterns ) {
				$category = (string) $raw_category;
				if ( ! isset( $patterns[ $category ] ) || ! \is_array( $patterns[ $category ] ) ) {
					$patterns[ $category ] = [];
				}
				$patterns[ $category ] = \array_merge( $patterns[ $category ], Core::arr( $user_patterns ) );
			}
		}

		/** @var array<string, mixed> $overrides_map config dynamic output. */
		$overrides_map       = Core::arr( $overrides );
		self::$merged_config = [
			'colors'    => $colors,
			'patterns'  => $patterns,
			'overrides' => $overrides_map,
		];

		return self::$merged_config;
	}

	/**
	 * Load base configuration from hook_categories.json.
	 *
	 * @return array<string, mixed> Base configuration.
	 */
	public static function get_base_config(): array {
		if ( null !== self::$base_config ) {
			return self::$base_config;
		}

		$json_path = \dirname( __DIR__ ) . '/hook_categories.json';
		if ( ! \file_exists( $json_path ) ) {
			self::$base_config = [ '_colors' => [], '_patterns' => [] ];
			return self::$base_config;
		}

		$read = self::$read_file ?? static fn( string $path ) => \file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local file.
		$json = $read( $json_path );
		if ( false === $json ) {
			self::$base_config = [ '_colors' => [], '_patterns' => [] ];
			return self::$base_config;
		}
		$decoded = \json_decode( $json, true, 64 );
		/** @var array<string, mixed> $config json_decode dynamic output. */
		$config            = Core::arr( $decoded, [ '_colors' => [], '_patterns' => [] ] );
		self::$base_config = $config;
		return self::$base_config;
	}

	/**
	 * Get user customizations from database.
	 *
	 * @return array<string, mixed> User customizations with keys: patterns, overrides, colors.
	 */
	public static function get_user_customizations(): array {
		$defaults = [
			'patterns'  => [],  // category => [patterns...] - merged with base.
			'overrides' => [],  // hook_name => category - explicit assignments.
			'colors'    => [],  // category => color - merged with base.
		];

		$saved = \get_option( self::OPTION_NAME, [] );
		if ( ! \is_array( $saved ) && ! \is_object( $saved ) && ! \is_string( $saved ) ) {
			$saved = [];
		}
		/** @var array<string, mixed> $merged wp_parse_args boundary output. */
		$merged = \wp_parse_args( $saved, $defaults );
		return $merged;
	}

	/**
	 * Is this hook one of our own internal filters/actions?
	 *
	 * Used everywhere a list of hooks is presented to the operator or
	 * instrumented by `Core::hook_start`. Nodes uses two naming styles —
	 * slash for actions (`newspack_nodes/spawn_worker`,
	 * `newspack_event_logger_nodes/sse_connected`) and underscore for
	 * schema/option filters (`newspack_nodes_option_schema_core`) — so the
	 * prefix list covers both. Instrumenting our own filters is never an
	 * answer to a real "where is time going" question, and binding
	 * `hook_start`/`hook_complete` to substrate filters can loop via
	 * `Config::load_config` during LogManager bootstrap.
	 *
	 * @param string $hook_name Hook to test.
	 * @return bool True if the hook belongs to Event Logger / Nodes itself.
	 */
	public static function is_internal( string $hook_name ): bool {
		/** @var array<int, string> $prefixes */
		static $prefixes = [
			'newspack_event_logger_nodes_',
			'newspack_event_logger_nodes/',
			'newspack_nodes_',
			'newspack_nodes/',
		];
		foreach ( $prefixes as $prefix ) {
			if ( \str_starts_with( $hook_name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Categorize a single hook.
	 *
	 * @param string $hook_name Hook name.
	 * @return string Category name.
	 */
	public static function categorize( string $hook_name ): string {
		$config = self::get_merged_config();

		// Check explicit overrides first.
		if ( isset( $config['overrides'][ $hook_name ] ) && \is_string( $config['overrides'][ $hook_name ] ) ) {
			return $config['overrides'][ $hook_name ];
		}

		// Check patterns.
		$prev_backtrack_limit = \ini_get( 'pcre.backtrack_limit' );
		\ini_set( 'pcre.backtrack_limit', 10000 ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		try {
			foreach ( $config['patterns'] as $category => $patterns ) {
				if ( ! \is_array( $patterns ) ) {
					continue;
				}
				foreach ( $patterns as $pattern ) {
					if ( ! \is_string( $pattern ) ) {
						continue;
					}
					if ( \strlen( $pattern ) > self::MAX_PATTERN_LENGTH ) {
						continue;
					}
					// Reject patterns with nested quantifiers (ReDoS risk).
					if ( \preg_match( '/[+*?]\}?\)?[+*?{]/', $pattern ) ) {
						Core::print_less_often( '[EventLoggerNodes] Hook categorizer pattern rejected (nested quantifier): ', (string) \preg_replace( '/[\x00-\x1f\x7f]/', '', \substr( $pattern, 0, 100 ) ) );
						continue;
					}
					// Use \x01 delimiter to avoid injection/escaping issues.
					$safe_regex = "\x01" . $pattern . "\x01";
					if ( false === @\preg_match( $safe_regex, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validating user-supplied regex.
						Core::print_less_often( '[EventLoggerNodes] Invalid hook categorizer pattern rejected: ', (string) \preg_replace( '/[\x00-\x1f\x7f]/', '', \substr( $pattern, 0, 100 ) ) );
						continue;
					}
					if ( \preg_match( $safe_regex, $hook_name ) ) {
						return $category;
					}
				}
			}
		} finally {
			\ini_set( 'pcre.backtrack_limit', $prev_backtrack_limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		return 'Other';
	}

	/**
	 * Clear cached configuration.
	 *
	 * @api Used by tests.
	 */
	public static function clear_cache(): void {
		self::$base_config   = null;
		self::$merged_config = null;
	}
}
