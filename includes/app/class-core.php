<?php
/**
 * Application Core - Tracks request lifecycle, hook timing, plugin performance.
 *
 * Binds configured hooks individually at priority 1 (start) and PHP_INT_MAX-1 (complete)
 * to measure actual execution time of callbacks registered between those priorities.
 *
 * For significant events, wraps each individual callback with timing so the log shows
 * exactly which callback is slow (e.g. "photon_subsizes_filter_the_content (complete): 5000ms"
 * nested inside "the_content hook").
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\HookCategorizer;
use Newspack_Event_Logger_Nodes\LogManager;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core class - WordPress hook instrumentation.
 */
class Core {
	/**
	 * Short name for a callback (no namespace, no priority).
	 *
	 * @param mixed $function Callback.
	 * @return string e.g. "do_blocks" or "Image_CDN::filter_the_content".
	 */
	private static function short_name( $function ): string {
		if ( \is_string( $function ) ) {
			$pos = \strrpos( $function, '\\' );
			return false !== $pos ? \substr( $function, $pos + 1 ) : $function;
		}
		if ( \is_array( $function ) && \count( $function ) === 2 ) {
			$class = \is_object( $function[0] ) ? \get_class( $function[0] ) : $function[0];
			$pos   = \strrpos( $class, '\\' );
			if ( false !== $pos ) {
				$class = \substr( $class, $pos + 1 );
			}
			return "{$class}::{$function[1]}";
		}
		if ( $function instanceof \Closure ) {
			$ref  = new \ReflectionFunction( $function );
			$file = $ref->getFileName();
			$line = $ref->getStartLine();
			if ( $file ) {
				$file = \basename( $file );
				return "{closure}:{$file}:{$line}";
			}
			return '{closure}';
		}
		if ( \is_object( $function ) ) {
			$class = \get_class( $function );
			$pos   = \strrpos( $class, '\\' );
			return ( false !== $pos ? \substr( $class, $pos + 1 ) : $class ) . '::__invoke';
		}
		return '{unknown}';
	}

	/** @var array<int, true> spl_object_id of wrappers we created (prevents double-wrap). */
	private array $wrapper_ids = [];

	/** @var array<string, true> Significant events that get per-callback profiling. */
	private array $significant = [];

	/** @var int Priority used for hook_start registration. */
	private int $start_priority = 1;

	public function __construct() {
		// Hooks are registered unconditionally. The enabled check happens
		// inside each callback against LogManager::instance() — when JobWorker
		// suspends the parent LM and creates a fresh one for a /jobs/{handler}
		// scope, that fresh LM may be enabled even though the parent (worker
		// spawn URL, skip-listed) wasn't. Caching the parent's `enabled = false`
		// at construction would silently disable all hook instrumentation
		// inside the job too. Hence: no $log_manager property.

		// Load event filters from config.
		$config            = Config::load_config();
		$config_log_events = $config['log_events'] ?? [];

		$this->start_priority = (int) ( $config['hook_start_priority'] ?? 1 );

		// Significant events get per-callback profiling.
		// Also ensure real hooks are in log_events so they get instrumented.
		// Custom events (logged via LogManager::message, not do_action) are excluded
		// — instrumenting them with add_filter is pointless and pollutes the hook selector.
		$sig             = $config['significant_events'] ?? [];
		$custom_events   = $config['custom_events'] ?? [];
		$custom_set      = \is_array( $custom_events ) ? $custom_events : [];
		$log_events_set  = \array_flip( $config_log_events );
		if ( \is_array( $sig ) ) {
			foreach ( $sig as $event ) {
				$hook = \str_ends_with( $event, ' hook' ) ? \substr( $event, 0, -5 ) : $event;
				$this->significant[ $hook ] = true;
				if ( ! isset( $log_events_set[ $hook ] ) && ! isset( $custom_set[ $hook ] ) ) {
					$config_log_events[] = $hook;
				}
			}
		}

		// Plugin load timing is handled by the 00-newspack-profiler mu-plugin
		// which loads early enough to capture all plugins. See: mu-plugins/00-newspack-profiler.php

		// Bind each configured hook individually for proper timing.
		foreach ( $config_log_events as $hook_name ) {
			if ( ! \is_string( $hook_name ) || '' === $hook_name ) {
				continue;
			}
			if ( 'plugin_loaded' === $hook_name ) {
				continue;
			}
			// Skip Event Logger / Nodes internal filters — instrumenting
			// them creates a re-entry loop via Config::load_config during
			// LogManager bootstrap. HookCategorizer::is_internal covers
			// both slash and underscore prefix styles.
			if ( HookCategorizer::is_internal( $hook_name ) ) {
				continue;
			}
			\add_filter( $hook_name, [ $this, 'hook_start' ], $this->start_priority );
			\add_filter( $hook_name, [ $this, 'hook_complete' ], PHP_INT_MAX - 1 );
		}
	}

	/**
	 * Start timing for a hook. Registered at start_priority.
	 *
	 * @param mixed $v Filter value (passed through).
	 * @return mixed
	 */
	public function hook_start( $v = null ) {
		// Resolve fresh per-call so JobWorker suspend/resume picks up the
		// current LogManager scope. Caching at construction would pin us to
		// whichever LM existed at App\Core load time (typically the worker's
		// disabled spawn-URL scope).
		$lm = LogManager::instance();
		if ( ! $lm->enabled ) {
			return $v;
		}

		$hook_name = \current_filter();
		$category  = $hook_name . ' hook';

		// Log filter value as 'm', truncated to keep firehose lines small.
		// Full content is in the filter itself — this is just a preview.
		$m = '';
		if ( isset( $v ) && \is_string( $v ) ) {
			$m = \strlen( $v ) > 1024 ? \substr( $v, 0, 1024 ) : $v;
		} elseif ( isset( $v ) && \is_scalar( $v ) ) {
			$m = $v;
		} elseif ( isset( $v ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() infinite-loops on circular refs (Core_Upgrader).
			$encoded = \json_encode( $v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES, 16 );
			if ( false !== $encoded && \strlen( $encoded ) <= 1024 ) {
				$m = $encoded;
			}
		}
		$lm->start( $category, [ 'm' => $m, 'l' => '' ] );

		// Wrap callbacks for significant hooks. Runs every invocation to catch
		// late-registered callbacks. wrapper_ids prevents double-wrapping.
		if ( isset( $this->significant[ $hook_name ] ) ) {
			$this->wrap_callbacks( $hook_name );
		}

		return $v;
	}

	/**
	 * Wrap each callback on a hook with timing instrumentation.
	 *
	 * Replaces each callback's function with a closure that calls start/complete
	 * around the original. Only wraps priorities > start_priority and < PHP_INT_MAX-1
	 * (skips our own hook_start/hook_complete).
	 *
	 * Safe to call during hook execution at start_priority — callbacks at higher
	 * priorities haven't been iterated yet, so WordPress picks up the replacements.
	 *
	 * @param string $hook_name Hook to wrap.
	 */
	private function wrap_callbacks( string $hook_name ): void {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return;
		}

		$min = $this->start_priority;

		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => &$priority_callbacks ) {
			if ( $priority <= $min || $priority >= PHP_INT_MAX - 1 ) {
				continue;
			}

			foreach ( $priority_callbacks as $id => &$cb ) {
				$original      = $cb['function'];
				$accepted_args = (int) $cb['accepted_args'];
				$name          = self::short_name( $original );

				// Skip wrappers we already created (prevents double-wrap on recursion).
				if ( $original instanceof \Closure && isset( $this->wrapper_ids[ \spl_object_id( $original ) ] ) ) {
					continue;
				}

				// Wrap the original with timing instrumentation. Resolve LM
				// per-call inside the wrapper so the wrapper survives across
				// suspend/resume boundaries (a wrapper installed during a
				// worker request shouldn't pin to the worker's LM when later
				// invoked inside a /jobs/* scope).
				$label   = "{$name} @{$priority}";
				$wrapper = function () use ( $original, $accepted_args, $label ) {
					$lm   = LogManager::instance();
					$args = \array_slice( \func_get_args(), 0, $accepted_args );
					$lm->start( $label, [ 'l' => '' ] );
					try {
						$result = \call_user_func_array( $original, $args );
					} finally {
						$lm->complete( $label );
					}
					return $result;
				};

				$this->wrapper_ids[ \spl_object_id( $wrapper ) ] = true;
				$cb['function']      = $wrapper;
				$cb['accepted_args'] = 99;
			}
			unset( $cb );
		}
		unset( $priority_callbacks );
	}

	/**
	 * Complete timing for a hook. Registered at PHP_INT_MAX - 1.
	 *
	 * @param mixed $v Filter value (passed through).
	 * @return mixed
	 */
	public function hook_complete( $v = null ) {
		$hook_name = \current_filter();
		LogManager::instance()->complete( $hook_name . ' hook' );
		return $v;
	}
}
