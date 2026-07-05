<?php
/**
 * Application Core - Tracks request lifecycle, hook timing, plugin performance.
 *
 * Binds only the current request's governing rule's hooks (Log_Manager::governing_rule())
 * individually at priority 1 (start) and PHP_INT_MAX-1 (complete) to measure actual
 * execution time of callbacks registered between those priorities, plus a sacrificial
 * spacer at PHP_INT_MAX-2 (see hook_spacer) so a self-removing callback can't make
 * WP_Hook's iteration skip the complete. A skip rule or no match binds zero hooks.
 *
 * For significant events, wraps each individual callback with timing so the log shows
 * exactly which callback is slow (e.g. "photon_subsizes_filter_the_content (complete): 5000ms"
 * nested inside "the_content hook").
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Rule_Set;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core class - WordPress hook instrumentation.
 */
class Core {

	/** Priority of the sacrificial hook_spacer; wrap_callbacks treats everything at/above it as ours. */
	private const SPACER_PRIORITY = PHP_INT_MAX - 2;

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
			$class  = \is_object( $function[0] ) ? \get_class( $function[0] ) : ( \is_string( $function[0] ) ? $function[0] : '' );
			$method = \is_string( $function[1] ) ? $function[1] : '';
			$pos    = \strrpos( $class, '\\' );
			if ( false !== $pos ) {
				$class = \substr( $class, $pos + 1 );
			}
			return "{$class}::{$method}";
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

	/** @var string[] Hook names currently bound (tracked for rebind_for_current_scope). */
	private array $bound_hooks = [];

	public function __construct() {
		// Hooks are registered unconditionally. The enabled check happens
		// inside each callback against LogManager::instance() — when JobWorker
		// suspends the parent LM and creates a fresh one for a /jobs/{handler}
		// scope, that fresh LM may be enabled even though the parent (worker
		// spawn URL, skip-listed) wasn't. Caching the parent's `enabled = false`
		// at construction would silently disable all hook instrumentation
		// inside the job too. Hence: no $log_manager property.

		$config               = Config::load_config();
		$start_priority       = $config['hook_start_priority'] ?? 1;
		$this->start_priority = \is_numeric( $start_priority ) ? (int) $start_priority : 1;

		$this->bind_current_scope();
		\add_action( 'newspack_event_logger_nodes_scope_changed', [ $this, 'rebind_for_current_scope' ] );
	}

	/**
	 * Remove the currently-bound hook filters and bind the current request's
	 * governing rule afresh. Used when a job context switch changes which
	 * rule governs mid-request (JobWorker's begin/end_job_context).
	 */
	public function rebind_for_current_scope(): void {
		foreach ( $this->bound_hooks as $hook_name ) {
			\remove_filter( $hook_name, [ $this, 'hook_start' ], $this->start_priority );
			\remove_filter( $hook_name, [ $this, 'hook_spacer' ], self::SPACER_PRIORITY );
			\remove_filter( $hook_name, [ $this, 'hook_complete' ], PHP_INT_MAX - 1 );
		}
		$this->bound_hooks = [];
		$this->significant = [];
		$this->bind_current_scope();
	}

	/**
	 * Bind hook_start/hook_spacer/hook_complete for only the current
	 * request's governing rule — the hot-path win: skip/no-match binds
	 * zero hooks instead of the entire global log_events list.
	 */
	private function bind_current_scope(): void {
		$rule = Log_Manager::instance()->governing_rule();
		if ( null === $rule || ! $rule->is_log() ) {
			return;
		}

		// Also ensure real hooks are in the hook list so they get instrumented.
		// Custom events (logged via LogManager::message, not do_action) are excluded
		// — instrumenting them with add_filter is pointless and pollutes the hook selector.
		$hooks          = Rule_Set::hooks_for( $rule );
		$custom_set     = \array_flip( \array_filter( $rule->custom_events, 'is_string' ) );
		$log_events_set = \array_flip( \array_filter( $hooks, 'is_string' ) );

		// Significant events get per-callback profiling.
		foreach ( $rule->significant_events as $event ) {
			$hook = \str_ends_with( $event, ' hook' ) ? \substr( $event, 0, -5 ) : $event;
			$this->significant[ $hook ] = true;
			if ( ! isset( $log_events_set[ $hook ] ) && ! isset( $custom_set[ $hook ] ) ) {
				$hooks[] = $hook;
			}
		}

		// Plugin load timing is handled by the 00-newspack-profiler mu-plugin
		// which loads early enough to capture all plugins. See: mu-plugins/00-newspack-profiler.php

		// Bind each hook individually for proper timing.
		foreach ( $hooks as $hook_name ) {
			if ( '' === $hook_name ) {
				continue;
			}
			if ( 'plugin_loaded' === $hook_name ) {
				continue;
			}
			// Skip Event Logger / Nodes internal filters — instrumenting
			// them creates a re-entry loop via Config::load_config during
			// LogManager bootstrap. HookCategorizer::is_internal covers
			// both slash and underscore prefix styles.
			if ( Hook_Categorizer::is_internal( $hook_name ) ) {
				continue;
			}
			\add_filter( $hook_name, [ $this, 'hook_start' ], $this->start_priority );
			\add_filter( $hook_name, [ $this, 'hook_spacer' ], self::SPACER_PRIORITY );
			\add_filter( $hook_name, [ $this, 'hook_complete' ], PHP_INT_MAX - 1 );
			$this->bound_hooks[] = $hook_name;
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
		$lm = Log_Manager::instance();
		if ( ! $lm->enabled ) {
			return $v;
		}

		$hook_name = \current_filter() ?: '';
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
	 * Whether a callback declares a by-reference parameter.
	 *
	 * Such a callback can't be timing-wrapped: the wrapper passes args via
	 * func_get_args() + call_user_func_array(), which copy, so a by-ref param
	 * receives a value (PHP warning + lost mutation). Reflect once; on any
	 * reflection failure, fall through to wrapping (prior behavior).
	 *
	 * @param mixed $function Callback (string|array|Closure|invokable object).
	 * @return bool
	 */
	private static function callback_has_ref_param( $function ): bool {
		try {
			if ( \is_array( $function ) && \count( $function ) === 2 ) {
				$target = $function[0];
				$method = $function[1];
				if ( ( ! \is_object( $target ) && ! \is_string( $target ) ) || ! \is_string( $method ) ) {
					return false;
				}
				$ref = new \ReflectionMethod( $target, $method );
			} elseif ( \is_string( $function ) && \str_contains( $function, '::' ) ) {
				$ref = new \ReflectionMethod( $function );
			} elseif ( \is_object( $function ) && ! ( $function instanceof \Closure ) && \method_exists( $function, '__invoke' ) ) {
				$ref = new \ReflectionMethod( $function, '__invoke' );
			} elseif ( $function instanceof \Closure || \is_string( $function ) ) {
				$ref = new \ReflectionFunction( $function );
			} else {
				return false;
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		foreach ( $ref->getParameters() as $param ) {
			if ( $param->isPassedByReference() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Wrap each callback on a hook with timing instrumentation.
	 *
	 * Replaces each callback's function with a closure that calls start/complete
	 * around the original. Only wraps priorities > start_priority and < PHP_INT_MAX-2
	 * (skips our own hook_start/hook_spacer/hook_complete).
	 *
	 * Safe to call during hook execution at start_priority — callbacks at higher
	 * priorities haven't been iterated yet, so WordPress picks up the replacements.
	 *
	 * @param string $hook_name Hook to wrap.
	 */
	private function wrap_callbacks( string $hook_name ): void {
		/** @var \WP_Hook[] $wp_filter PHPStan drops the @global WP_Hook[] from WP docblocks on a bare global. */
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return;
		}

		$min = $this->start_priority;

		/** @var array<int, array<string, array{function: callable, accepted_args: int|string}>> $wp_filter_callbacks WP_Hook::$callbacks is stubbed as bare array; this annotates the by-ref iterand, not a copy. */
		$wp_filter_callbacks = &$wp_filter[ $hook_name ]->callbacks;
		foreach ( $wp_filter_callbacks as $priority => &$priority_callbacks ) {
			if ( $priority <= $min || $priority >= self::SPACER_PRIORITY ) {
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

				// A by-reference callback (e.g. a `pre_get_posts` $query mutator
				// like vip_es_disable_advanced_post_cache) can't be wrapped: the
				// wrapper reads args via func_get_args(), which drops the
				// reference, so the original would be handed a value and PHP warns.
				if ( self::callback_has_ref_param( $original ) ) {
					continue;
				}

				// Wrap the original with timing instrumentation. Resolve LM
				// per-call inside the wrapper so the wrapper survives across
				// suspend/resume boundaries (a wrapper installed during a
				// worker request shouldn't pin to the worker's LM when later
				// invoked inside a /jobs/* scope).
				$label   = "{$name} @{$priority}";
				$wrapper = function () use ( $original, $accepted_args, $label ) {
					$lm   = Log_Manager::instance();
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
	 * Sacrificial no-op registered at PHP_INT_MAX - 2.
	 *
	 * When a callback removes itself mid-run (es-wp-query's Shoehorn run-once
	 * filters), WP_Hook::resort_active_iterations() parks the iteration
	 * pointer on the next surviving priority and apply_filters' `next()` then
	 * skips it. Without this spacer the skipped priority is hook_complete's,
	 * losing the `(complete)` entry; with it, the spacer is consumed instead.
	 *
	 * @param mixed $v Filter value (passed through).
	 * @return mixed
	 */
	public function hook_spacer( $v = null ) {
		return $v;
	}

	/**
	 * Complete timing for a hook. Registered at PHP_INT_MAX - 1.
	 *
	 * @param mixed $v Filter value (passed through).
	 * @return mixed
	 */
	public function hook_complete( $v = null ) {
		$hook_name = \current_filter();
		Log_Manager::instance()->complete( $hook_name . ' hook' );
		return $v;
	}
}
