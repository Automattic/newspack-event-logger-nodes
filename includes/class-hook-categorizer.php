<?php
/**
 * Hook Categorizer
 *
 * Sorts WordPress hook names into human-readable, colored categories so the
 * settings page's hook picker can offer thousands of hooks as labeled sections
 * instead of one flat list. The categories, their colors, and the regular
 * expressions that assign hooks to them ship in `hook_categories.json` at the
 * plugin root; a site adds to or overrides any of it through the
 * `newspack_event_logger_nodes_hook_customizations` option.
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
 *
 * Static throughout, with both configuration sources memoized for the life of
 * the process: the JSON file and the customizations option each load once, and
 * `clear_cache()` drops both.
 *
 * The hook list it reports is a union of three sources — hooks WordPress
 * currently has callbacks on, hooks the durable ruleset instruments, and hooks
 * a spoke reported through discovery — so a hook that fires only inside a
 * worker, or only on another site, still reaches the picker.
 *
 * Patterns arrive from a database option rather than from code, so
 * `categorize()` treats them as untrusted: it caps their length, rejects nested
 * quantifiers, and lowers `pcre.backtrack_limit` while they run.
 *
 * Consumers: the `hooks_registered` verb of `App\Performance_CI_Node`, which
 * feeds the settings page's `HookSelectorModal`, and
 * `App\Core::bind_current_scope()`, which calls `is_internal()`.
 */
class Hook_Categorizer {

	/**
	 * Maximum pattern length to prevent ReDoS attacks.
	 *
	 * A longer pattern is skipped whole, never truncated — half a regex is not
	 * the operator's intent.
	 */
	const MAX_PATTERN_LENGTH = 100;

	/**
	 * Option name for user customizations.
	 *
	 * Nothing in the plugin writes this row; it exists for a site that wants
	 * categories, patterns, or per-hook assignments of its own. Uninstall
	 * clears it along with every other `newspack_event_logger_nodes_` option.
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
	 * Cached base config from JSON file. Every failure path caches the empty
	 * shape too, so a missing or unreadable file costs one read per process.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $base_config = null;

	/**
	 * Cached merged config (base + user customizations). Null until the first
	 * `get_merged_config()`, and `clear_cache()` puts it back there.
	 *
	 * @var array{colors: array<string, mixed>, descriptions: array<string, mixed>, patterns: array<string, mixed>, overrides: array<string, mixed>}|null
	 */
	private static ?array $merged_config = null;

	/**
	 * Get registered hooks grouped by category.
	 *
	 * Drops the plugin's own hooks, sends anything matching no pattern to
	 * `Other`, and omits the categories that ended up empty.
	 *
	 * @return array<string, mixed> Associative array of category => [hooks...].
	 */
	public static function get_registered_hooks_by_category(): array {
		$hooks      = self::get_registered_hooks();
		$categories = self::get_categories();

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
	 * Three sources, deduplicated and sorted: hooks with callbacks in
	 * `$wp_filter`, hooks the durable ruleset instruments, and hooks a spoke
	 * reported into `Config::OPTION_DISCOVERED_HOOKS`. The last two matter
	 * because a hook bound only inside a worker — or only on another site —
	 * never appears in this request's `$wp_filter`.
	 *
	 * @return array<int, string> Sorted, deduplicated hook names.
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
	 * @return array<string, mixed> Associative array of category => color, base
	 *                              colors with the user's merged over them.
	 */
	public static function get_categories(): array {
		$config = self::get_merged_config();
		return $config['colors'];
	}

	/**
	 * Get merged configuration (base + user customizations).
	 *
	 * Colors merge with the user's winning. Patterns only ever accumulate: a
	 * user pattern appends to its category and displaces nothing. Since
	 * `categorize()` returns on the first match and walks categories in JSON
	 * order, a base pattern in an earlier category still beats a user pattern
	 * in a later one — `overrides` is the way to pin a specific hook.
	 *
	 * @return array{colors: array<string, mixed>, descriptions: array<string, mixed>, patterns: array<string, mixed>, overrides: array<string, mixed>} Merged configuration.
	 */
	public static function get_merged_config(): array {
		if ( null !== self::$merged_config ) {
			return self::$merged_config;
		}

		$base          = self::get_base_config();
		$customizations = self::get_user_customizations();

		$base_colors    = $base['_colors'] ?? [];
		$user_colors    = $customizations['colors'] ?? [];
		$base_descs     = $base['_descriptions'] ?? [];
		$user_descs     = $customizations['descriptions'] ?? [];
		$base_patterns  = $base['_patterns'] ?? [];
		$user_patterns_all = $customizations['patterns'] ?? [];
		$overrides      = $customizations['overrides'] ?? [];

		// Merge colors (user overrides base).
		/** @var array<string, mixed> $colors config dynamic output. */
		$colors = \array_merge( Core::arr( $base_colors ), Core::arr( $user_colors ) );

		// Same precedence as colors: a user description wins.
		/** @var array<string, mixed> $descriptions config dynamic output. */
		$descriptions = \array_merge( Core::arr( $base_descs ), Core::arr( $user_descs ) );

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
			'colors'       => $colors,
			'descriptions' => $descriptions,
			'patterns'     => $patterns,
			'overrides'    => $overrides_map,
		];

		return self::$merged_config;
	}

	/**
	 * Load base configuration from hook_categories.json.
	 *
	 * The file sits at the plugin root, beside `includes/`. A missing file, an
	 * unreadable one, and JSON decoding to anything but an array all yield the
	 * empty `_colors` / `_patterns` shape: categorization degrades to `Other`
	 * instead of failing the request.
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
	 * `wp_parse_args()` accepts an array, an object, or a query string; a
	 * stored value of any other type is discarded before the merge, so the
	 * three default keys are always present.
	 *
	 * @return array<string, mixed> User customizations: patterns, overrides, colors, descriptions.
	 */
	public static function get_user_customizations(): array {
		$defaults = [
			'patterns'     => [],  // category => [patterns...] - merged with base.
			'overrides'    => [],  // hook_name => category - explicit assignments.
			'colors'       => [],  // category => color - merged with base.
			'descriptions' => [],  // category => one-liner - merged with base.
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
	 * Used everywhere a list of hooks is presented to the operator, and by
	 * `App\Core::bind_current_scope()` before it binds `hook_start` /
	 * `hook_complete` to a hook. Nodes uses two naming styles —
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
	 * An explicit override wins outright. Otherwise the first pattern that
	 * matches decides, in merged-config order, and a hook matching nothing
	 * lands in `Other`.
	 *
	 * Patterns come from the database, so three guards bound the work: one
	 * longer than MAX_PATTERN_LENGTH is skipped, one carrying a nested
	 * quantifier is skipped and logged, and `pcre.backtrack_limit` drops to
	 * 10000 for the scan — restored in `finally`, including on a throw. A
	 * pattern PCRE refuses to compile is skipped rather than aborting the scan.
	 * Both rejections report through `Core::print_less_often`, whose throttle
	 * key is the message prefix alone, so one rejected pattern per window
	 * prints and the rest stay silent.
	 *
	 * @param string $hook_name Hook name.
	 * @return string Category name, or `Other` when nothing matches.
	 */
	public static function categorize( string $hook_name ): string {
		$config = self::get_merged_config();

		// Check explicit overrides first.
		if ( isset( $config['overrides'][ $hook_name ] ) && \is_string( $config['overrides'][ $hook_name ] ) ) {
			return $config['overrides'][ $hook_name ];
		}

		// Cap backtracking for the scan; finally restores the old limit.
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
	 * One-line descriptions for the categories, keyed by category name.
	 *
	 * These live beside the taxonomy they describe. The hook picker used to
	 * carry its own hand-written map covering 24 of the 63 categories this
	 * config declares — and a user can add more — so the rest silently rendered
	 * blank.
	 *
	 * @return array<string, mixed> Category name => description.
	 */
	public static function get_descriptions(): array {
		$config = self::get_merged_config();
		return $config['descriptions'];
	}

	/**
	 * Clear cached configuration: the next read reloads the JSON file and the
	 * customizations option.
	 *
	 * @api Used by tests.
	 */
	public static function clear_cache(): void {
		self::$base_config   = null;
		self::$merged_config = null;
	}
}
