<?php
/**
 * Application Core — WordPress hook instrumentation for the request log.
 *
 * Binds hook_start / hook_spacer / hook_complete on only the hooks named by the
 * current request's governing rule (Log_Manager::governing_rule()), so a skip rule
 * or no match binds nothing at all. Each hook is bound individually: hook_start at
 * the configured `hook_start_priority` and hook_complete at PHP_INT_MAX - 1, so the
 * span measures the callbacks registered between them. The sacrificial hook_spacer
 * at PHP_INT_MAX - 2 absorbs the priority WP_Hook skips when a callback removes
 * itself mid-run (see hook_spacer).
 *
 * A rule's significant events additionally get per-callback profiling: hook_start
 * rewrites every callback on the hook into a timing wrapper, so the log names the
 * slow one — "Image_CDN::filter_the_content @10 (complete)" nested inside the
 * hook's own "the_content hook" span.
 *
 * Log_Manager fires `newspack_event_logger_nodes_scope_changed` whenever a job
 * context begins or ends; rebind_for_current_scope() then rebinds for whichever
 * rule governs next.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Core as RuntimeCore;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Times each bound hook — and each callback on a significant hook — into the
 * current request's log.
 */
class Core {

	/**
	 * What a hook's span is called: `<hook> hook`. This class MINTS that name,
	 * so it owns the word — `Findings` reads it to tell a hook span from a
	 * custom event, and a significant event may be written either way.
	 */
	public const HOOK_SUFFIX = ' hook';

	/**
	 * What a wrapped listener's span is called: `<callable> @<priority>`. This
	 * class mints that too (`wrap_callbacks()`), so it owns the shape — and the
	 * priority may be NEGATIVE, which a pattern without the sign silently reads
	 * as a custom event instead.
	 */
	public const LISTENER_PATTERN = '/ @-?\d+$/';

	/** Priority of the sacrificial hook_spacer; wrap_callbacks treats everything at/above it as ours. */
	private const SPACER_PRIORITY = PHP_INT_MAX - 2;

	/** @var string[] Hook names currently bound (tracked for rebind_for_current_scope). */
	private array $bound_hooks = [];

	/** @var array<string,true> Significant events that get per-callback profiling. */
	private array $significant = [];

	/** @var int Priority hook_start registers at (config key `hook_start_priority`). */
	private int $start_priority = 1;

	/** @var array<int,true> spl_object_id of wrappers we created (prevents double-wrap). */
	private array $wrapper_ids = [];

	/**
	 * Read the start priority from config, bind the current scope, and listen
	 * for scope changes.
	 *
	 * No Log_Manager instance is cached here: every bound callback resolves
	 * Log_Manager::instance() afresh, so a suspend/resume (job context) always
	 * times against the live one.
	 */
	public function __construct() {
		$this->start_priority = RuntimeCore::num_int( Config::value( 'hook_start_priority' ), 1 );

		$this->bind_current_scope();
		\add_action( 'newspack_event_logger_nodes_scope_changed', [ $this, 'rebind_for_current_scope' ] );
	}

	/**
	 * Open the hook's timing span, wrapping its callbacks when it is significant.
	 *
	 * Registered as a filter at start_priority on every bound hook. The entry
	 * records the filter value as 'm', a preview capped at 1024 bytes — strings
	 * clipped, other scalars verbatim, anything else JSON-encoded to 16 levels
	 * and dropped when the encoding overflows the cap. Its stable label 'l' is
	 * deliberately empty, which keeps flame nodes aggregating on the hook name
	 * rather than on the value.
	 *
	 * @param mixed $v Filter value (passed through).
	 * @return mixed
	 */
	public function hook_start( $v = null ) {
		// Resolve LM fresh per-call so suspend/resume gets the current scope.
		$lm = Log_Manager::instance();
		if ( ! $lm->is_started() ) {
			return $v;
		}

		$hook_name = \current_filter() ?: '';
		$category  = $hook_name . self::HOOK_SUFFIX;

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

		// Wrap significant-hook callbacks each call for late registrations.
		if ( isset( $this->significant[ $hook_name ] ) ) {
			$this->wrap_callbacks( $hook_name );
		}

		return $v;
	}

	/**
	 * Wrap each callback on a hook with timing instrumentation.
	 *
	 * Replaces each callback's function with a closure that calls start/complete
	 * around the original. Only priorities strictly between start_priority and
	 * SPACER_PRIORITY are touched; everything at or above the spacer is ours.
	 *
	 * Safe to call during hook execution at start_priority — callbacks at higher
	 * priorities haven't been iterated yet, so WordPress picks up the replacements.
	 *
	 * Each wrapper claims accepted_args = 99 so WP_Hook hands it every argument
	 * apply_filters has, then slices back to the original's count before calling
	 * it. Inflating the count on the wrapper preserves the original's contract;
	 * the original never sees an argument it didn't ask for.
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

		/** @var array<int,array<string,array{function: callable,accepted_args: int|string}>> $wp_filter_callbacks WP_Hook::$callbacks is stubbed as bare array; this annotates the by-ref iterand, not a copy. */
		$wp_filter_callbacks = &$wp_filter[ $hook_name ]->callbacks;
		foreach ( $wp_filter_callbacks as $priority => &$priority_callbacks ) {
			if ( $priority <= $min || $priority >= self::SPACER_PRIORITY ) {
				continue;
			}

			foreach ( $priority_callbacks as &$cb ) {
				$original      = $cb['function'];
				$accepted_args = (int) $cb['accepted_args'];
				$name          = self::short_name( $original );

				// Skip wrappers already made (no double-wrap on recursion).
				if ( $original instanceof \Closure && isset( $this->wrapper_ids[ \spl_object_id( $original ) ] ) ) {
					continue;
				}

				// By-ref callbacks can't be wrapped: func_get_args() drops ref.
				if ( self::callback_has_ref_param( $original ) ) {
					continue;
				}

				// Wrap timing; resolve LM per-call to survive suspend/resume.
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
			$class  = \is_object( $function[0] ) ? \get_class( $function[0] ) : RuntimeCore::str( $function[0] );
			$method = RuntimeCore::str( $function[1] );
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

	/**
	 * Remove the currently-bound hook filters and bind the current request's
	 * governing rule afresh. Public because it is the listener for
	 * `newspack_event_logger_nodes_scope_changed`, which Log_Manager fires when
	 * a job context switch changes which rule governs mid-request
	 * (begin_job_context / end_job_context).
	 *
	 * Only this class's own three filters come off. Callback wrappers already
	 * installed by wrap_callbacks() stay in $wp_filter and keep timing, and
	 * wrapper_ids keeps remembering them, so the new scope can't double-wrap.
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
	 * Bind hook_start/hook_spacer/hook_complete for the current request's
	 * governing rule only — the hot-path win: a skip rule or no match binds
	 * zero hooks instead of every hook the ruleset names.
	 *
	 * A significant event joins the bind list when it names a hook the rule
	 * doesn't already cover. One that matches a custom event stays unbound:
	 * custom events are categories the application logs itself, not do_action
	 * names, so binding them would register a filter nothing ever fires.
	 */
	private function bind_current_scope(): void {
		$rule = Log_Manager::instance()->governing_rule();
		if ( null === $rule || ! $rule->is_log() ) {
			return;
		}

		// Instrument real hooks; custom events (not do_action) are excluded.
		$hooks          = Rule_Set::hooks_for( $rule );
		$custom_set     = \array_flip( \array_filter( $rule->custom_events, 'is_string' ) );
		$log_events_set = \array_flip( \array_filter( $hooks, 'is_string' ) );

		// Significant events get per-callback profiling.
		foreach ( $rule->significant_events as $event ) {
			$hook = \str_ends_with( $event, self::HOOK_SUFFIX )
				? \substr( $event, 0, -\strlen( self::HOOK_SUFFIX ) )
				: $event;
			$this->significant[ $hook ] = true;
			if ( ! isset( $log_events_set[ $hook ] ) && ! isset( $custom_set[ $hook ] ) ) {
				$hooks[] = $hook;
			}
		}

		foreach ( $hooks as $hook_name ) {
			if ( '' === $hook_name ) {
				continue;
			}
			// Plugin-load timing lives in the 00-newspack-profiler mu-plugin.
			if ( 'plugin_loaded' === $hook_name ) {
				continue;
			}
			// Skip internal filters: instrumenting re-enters LM bootstrap.
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
	 * Whether a span name is a wrapped listener rather than a hook or one of
	 * the application's own custom events.
	 *
	 * @param string $span A span name, as the flame carries it.
	 */
	public static function is_listener_span( string $span ): bool {
		return 1 === \preg_match( self::LISTENER_PATTERN, $span );
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
	 * Close the hook's timing span. Registered at PHP_INT_MAX - 1.
	 *
	 * Needs no is_started() check, unlike hook_start: Log_Manager::complete()
	 * no-ops when no span under this label is open.
	 *
	 * @param mixed $v Filter value (passed through).
	 * @return mixed
	 */
	public function hook_complete( $v = null ) {
		$hook_name = \current_filter();
		Log_Manager::instance()->complete( $hook_name . self::HOOK_SUFFIX );
		return $v;
	}
}
