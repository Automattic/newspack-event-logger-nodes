<?php
/**
 * Hook Categorizer
 *
 * Auto-categorizes WordPress hooks using patterns.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.

/**
 * Hook Categorizer class.
 */
class HookCategorizer {

	/**
	 * Option name for user customizations.
	 */
	const OPTION_NAME = 'newspack_event_logger_nodes_hook_customizations';

	/**
	 * Cached base config from JSON file.
	 *
	 * @var array|null
	 */
	private static ?array $base_config = null;

	/**
	 * Cached merged config (base + user customizations).
	 *
	 * @var array|null
	 */
	private static ?array $merged_config = null;

	/**
	 * Maximum pattern length to prevent ReDoS attacks.
	 */
	const MAX_PATTERN_LENGTH = 100;

	/**
	 * Load base configuration from hook_categories.json.
	 *
	 * @return array Base configuration.
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

		$json              = \file_get_contents( $json_path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local file.
		self::$base_config = \json_decode( $json, true, 64 ) ?? [ '_colors' => [], '_patterns' => [] ];
		return self::$base_config;
	}

	/**
	 * Get user customizations from database.
	 *
	 * @return array User customizations with keys: patterns, overrides, colors.
	 */
	public static function get_user_customizations(): array {
		$defaults = [
			'patterns'  => [],  // category => [patterns...] - merged with base.
			'overrides' => [],  // hook_name => category - explicit assignments.
			'colors'    => [],  // category => color - merged with base.
		];

		$saved = \get_option( self::OPTION_NAME, [] );
		return \wp_parse_args( $saved, $defaults );
	}

	/**
	 * Get merged configuration (base + user customizations).
	 *
	 * @return array Merged configuration.
	 */
	public static function get_merged_config(): array {
		if ( null !== self::$merged_config ) {
			return self::$merged_config;
		}

		$base          = self::get_base_config();
		$customizations = self::get_user_customizations();

		// Merge colors (user overrides base).
		$colors = \array_merge( $base['_colors'] ?? [], $customizations['colors'] ?? [] );

		// Merge patterns (user patterns added to base).
		$patterns = $base['_patterns'] ?? [];
		foreach ( $customizations['patterns'] ?? [] as $category => $user_patterns ) {
			if ( ! isset( $patterns[ $category ] ) ) {
				$patterns[ $category ] = [];
			}
			$patterns[ $category ] = \array_merge( $patterns[ $category ], $user_patterns );
		}

		self::$merged_config = [
			'colors'    => $colors,
			'patterns'  => $patterns,
			'overrides' => $customizations['overrides'] ?? [],
		];

		return self::$merged_config;
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
		if ( isset( $config['overrides'][ $hook_name ] ) ) {
			return $config['overrides'][ $hook_name ];
		}

		// Check patterns.
		$prev_backtrack_limit = \ini_get( 'pcre.backtrack_limit' );
		\ini_set( 'pcre.backtrack_limit', 10000 ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		try {
			foreach ( $config['patterns'] as $category => $patterns ) {
				foreach ( $patterns as $pattern ) {
					if ( ! \is_string( $pattern ) ) {
						continue;
					}
					if ( \strlen( $pattern ) > self::MAX_PATTERN_LENGTH ) {
						continue;
					}
					// Reject patterns with nested quantifiers (ReDoS risk).
					if ( \preg_match( '/[+*?]\}?\)?[+*?{]/', $pattern ) ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						\error_log( \sprintf( '[EventLoggerNodes] Hook categorizer pattern rejected (nested quantifier): %s', \preg_replace( '/[\x00-\x1f\x7f]/', '', \substr( $pattern, 0, 100 ) ) ) );
						continue;
					}
					// Use \x01 delimiter to avoid delimiter injection/escaping issues.
					$safe_regex = "\x01" . $pattern . "\x01";
					if ( false === @\preg_match( $safe_regex, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validating user-supplied regex.
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						\error_log( \sprintf( '[EventLoggerNodes] Invalid hook categorizer pattern rejected: %s', \preg_replace( '/[\x00-\x1f\x7f]/', '', \substr( $pattern, 0, 100 ) ) ) );
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
	 * Categorize multiple hooks.
	 *
	 * @param array $hooks Array of hook names.
	 * @return array Associative array of hook_name => category.
	 */
	public static function categorize_many( array $hooks ): array {
		$result = [];
		foreach ( $hooks as $hook ) {
			$result[ $hook ] = self::categorize( $hook );
		}
		return $result;
	}

	/**
	 * Get all categories with their colors.
	 *
	 * @return array Associative array of category => color.
	 */
	public static function get_categories(): array {
		$config = self::get_merged_config();
		return $config['colors'];
	}

	/**
	 * Get color for a category.
	 *
	 * @param string $category Category name.
	 * @return string Hex color code.
	 */
	public static function get_color( string $category ): string {
		$config = self::get_merged_config();
		return $config['colors'][ $category ] ?? '#9E9E9E';
	}

	/**
	 * Get all registered hooks from WordPress.
	 *
	 * @return array Array of hook names that have callbacks attached.
	 */
	public static function get_registered_hooks(): array {
		global $wp_filter;

		$hooks = [];
		foreach ( $wp_filter as $hook_name => $hook_obj ) {
			if ( ! empty( $hook_obj->callbacks ) ) {
				$hooks[ $hook_name ] = true;
			}
		}

		// Include already-selected hooks so they appear in the browse modal
		// even if not registered on the current page (e.g. worker-only hooks).
		$selected = \get_option( 'newspack_event_logger_nodes_log_events', [] );
		if ( \is_array( $selected ) ) {
			foreach ( $selected as $hook ) {
				if ( \is_string( $hook ) && '' !== $hook ) {
					$hooks[ $hook ] = true;
				}
			}
		}

		$result = \array_keys( $hooks );
		\sort( $result );
		return $result;
	}

	/**
	 * Get registered hooks grouped by category.
	 *
	 * @return array Associative array of category => [hooks...].
	 */
	public static function get_registered_hooks_by_category(): array {
		$hooks      = self::get_registered_hooks();
		$categories = self::get_categories();

		// Initialize all categories.
		$grouped = [];
		foreach ( \array_keys( $categories ) as $category ) {
			$grouped[ $category ] = [];
		}

		// Categorize each hook, skipping Event Logger's own internal filters.
		// Instrumenting them is a no-op at best (Core::hook_start rejects the
		// prefixes) and used to cause a bootstrap reentry loop, so there's no
		// reason to surface them in the picker at all.
		foreach ( $hooks as $hook ) {
			if ( \str_starts_with( $hook, 'newspack_event_logger_nodes_' )
				|| \str_starts_with( $hook, 'newspack_nodes_' )
				|| \str_starts_with( $hook, 'newspack_event_logger_' )
				|| \str_starts_with( $hook, 'newspack_performance_logger_' )
				|| \str_starts_with( $hook, 'newspack_event_aggregator_' )
				|| \str_starts_with( $hook, 'newspack_performance_workers_' )
				|| \str_starts_with( $hook, 'newspack_performance_aggregator_' ) ) {
				continue;
			}
			$category                = self::categorize( $hook );
			$grouped[ $category ][] = $hook;
		}

		// Remove empty categories.
		return \array_filter( $grouped );
	}

	/**
	 * Clear cached configuration.
	 */
	public static function clear_cache(): void {
		self::$base_config   = null;
		self::$merged_config = null;
	}
}
