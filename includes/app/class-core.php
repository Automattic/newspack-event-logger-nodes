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
 * A log rule also times every outbound HTTP request, which no hook can reach:
 * `pre_http_request` opens the span and `http_api_debug` closes it. See
 * `http_start()` for why a short-circuited request opens nothing.
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

	/**
	 * Frames kept from a caller trace, NEAREST first.
	 *
	 * Eight cut a real stack one frame short of `rest_preload_api_request` —
	 * the frame that explained a 41s request — so the answer had to be read out
	 * of core instead. `Log_Manager::MAX_DATA_SIZE` is the only bound.
	 */
	private const CALLER_FRAMES = 20;

	/**
	 * Frames `debug_backtrace()` walks to find the ONE origin frame.
	 *
	 * Ours, `WP_Hook->apply_filters` and `apply_filters()` sit between, so the
	 * origin is the fifth; eight is margin. The LIMIT is what makes this cheap:
	 * 0.9us against 2.9us unlimited and 12.7us through core's summary helper,
	 * which formats every frame before all but one is discarded.
	 */
	private const ORIGIN_DEPTH = 8;

	/**
	 * Depth when climbing past a transport. `SHOW FULL COLUMNS` reaches the
	 * `query` filter through four wpdb frames — update, process_fields,
	 * get_table_charset, get_results — so ORIGIN_DEPTH's hook-shaped budget
	 * runs out mid-climb and the span is labelled with nothing at all.
	 */
	private const TRANSPORT_ORIGIN_DEPTH = 16;

	/** Origin label kept on a span; it rides EVERY traced entry. */
	private const ORIGIN_MAX = 128;

	/**
	 * The STATE these two span families carry, one each.
	 *
	 * Every other span in the schema is a bounded state plus a separate `l`,
	 * and `Flame_Tree` composes `state: label` for the graph. Spelling the
	 * table or the host into the state instead made a profile category per
	 * table and per host — the axis `Stats_Store::MAX_CAT_VALUES` bounds — and
	 * left `l`, whose job this is, empty.
	 */
	private const SQL_STATE  = 'sql';
	private const HTTP_STATE = 'http';

	/** Dispatchers between a hook and its caller; flipped for O(1) lookup. */
	private const HOOK_DISPATCHERS = [
		'apply_filters'           => true,
		'apply_filters_ref_array' => true,
		'do_action'               => true,
		'do_action_ref_array'     => true,
	];

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

	/** @var string[] Labels of outbound HTTP spans currently in flight. */
	private array $http_spans = [];

	/** @var string[] Labels of query spans currently in flight. */
	private array $query_spans = [];

	/** @var array<string,int> Caller traces already spent, by hook name. */
	private array $traced = [];

	/** @var bool Whether the governing rule labels a span with its caller. */
	private bool $trace_hooks = false;

	/** @var int Backtraces the governing rule allows per hook; 0 is off. */
	private int $trace_callers = 0;

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
		if ( isset( $v ) && \is_scalar( $v ) ) {
			$m = $v;
		} elseif ( isset( $v ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() infinite-loops on circular refs (Core_Upgrader).
			$encoded = \json_encode( $v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES, 16 );
			if ( false !== $encoded ) {
				$m = $encoded;
			}
		}
		// `l` aggregates the flame, so the origin SPLITS the node by caller.
		$data = [ 'm' => $m, 'l' => $this->trace_hooks ? self::origin_frame() : '' ];
		$caller = $this->caller_of( $hook_name );
		if ( '' !== $caller ) {
			$data['caller'] = $caller;
		}
		$lm->start( $category, $data );

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
	 * Who called this hook, once the rule asks and while the budget lasts.
	 *
	 * A span says how long a pass took and nothing about who asked for it, so a
	 * hook that fires sixteen times reads as sixteen identical mysteries. The
	 * summary names the NEAREST frames instead, on the entry's `caller` field — not
	 * `c`, which already means COUNT everywhere else in this schema. It is
	 * capped per hook — by the RULE's own number, because what a diagnostic run
	 * wants is not what steady state wants — because the same
	 * question on `render_block` would be 2,601 backtraces, and it ignores this
	 * class so the top frame is the caller rather than the instrumentation.
	 *
	 * @param string $hook_name The hook being opened.
	 * @return string The caller summary, or '' when not tracing.
	 */
	private function caller_of( string $hook_name ): string {
		$spent = $this->traced[ $hook_name ] ?? 0;
		if ( $spent >= $this->trace_callers ) {
			return '';
		}
		$this->traced[ $hook_name ] = $spent + 1;
		// @longform The ARRAY form, because core hands back the frames nearest
		// first and the pretty string is that array REVERSED. Capping the
		// string kept the bootstrap and cut the caller — the whole answer.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_wp_debug_backtrace_summary -- The caller summary IS the diagnostic; counted per hook and gated per rule.
		$frames = \wp_debug_backtrace_summary( self::class, 0, false );
		$near   = \array_slice( $frames, 0, self::CALLER_FRAMES );
		return \implode( ', ', $near );
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
		\remove_filter( 'pre_http_request', [ $this, 'http_start' ], PHP_INT_MAX );
		\remove_action( 'http_api_debug', [ $this, 'http_end' ], PHP_INT_MIN );
		$this->bound_hooks = [];
		$this->significant = [];
		$this->traced      = [];
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
		$this->trace_hooks   = $rule->trace_hooks;
		$this->trace_callers = $rule->trace_callers;
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

		// Outbound HTTP blocks below userland, where no hook reaches.
		if ( $rule->log_http ) {
			\add_filter( 'pre_http_request', [ $this, 'http_start' ], PHP_INT_MAX, 3 );
			\add_action( 'http_api_debug', [ $this, 'http_end' ], PHP_INT_MIN, 5 );
		}

		if ( ! $rule->log_queries ) {
			return;
		}
		// @longform `wpdb` fires no post-query hook at all unless SAVEQUERIES is
		// on: `_do_query()` gates the `log_query()` call — and so this pair's
		// close — on it. A constant cannot be withdrawn, so a long-running
		// worker keeps it for its life; `query_end()` drains `$wpdb->queries`
		// to keep that from growing without bound.
		if ( ! \defined( 'SAVEQUERIES' ) ) {
			\define( 'SAVEQUERIES', true );
		}
		\add_filter( 'query', [ $this, 'query_start' ], PHP_INT_MAX );
		\add_filter( 'log_query_custom_data', [ $this, 'query_end' ], PHP_INT_MIN, 5 );
	}

	/**
	 * Open a span around one outbound HTTP request. Registered on
	 * `pre_http_request` at PHP_INT_MAX, so every short-circuiting filter has
	 * already run and `$preempt` carries their verdict.
	 *
	 * A short-circuited request opens NOTHING. `WP_Http::request()` returns it
	 * with a bare `return $pre;` and never fires `http_api_debug`, so a span
	 * opened here would never close and would adopt every row after it — and a
	 * short-circuit is a cache hit with no I/O to time in the first place.
	 *
	 * The span is named for its CALLER, one frame beyond `WP_Http`, which is
	 * what applies this filter: naming the transport would name the same string
	 * every time. The redacted URL rides the entry instead.
	 *
	 * @param mixed                 $preempt Short-circuit value, false to proceed.
	 * @param array<string,mixed>   $args    Request arguments (unused).
	 * @param string                $url     Request URL.
	 * @return mixed The unmodified $preempt.
	 */
	public function http_start( $preempt = false, array $args = [], string $url = '' ) {
		if ( false !== $preempt ) {
			return $preempt;
		}
		if ( ! Log_Manager::has_instance() ) {
			return $preempt;
		}
		$lm = Log_Manager::instance();
		if ( ! $lm->is_started() ) {
			return $preempt;
		}
		$this->http_spans[] = self::HTTP_STATE;
		$lm->start(
			self::HTTP_STATE,
			// Backtraces already answer who asked; don't pay for it twice.
			[
				'm' => Log_Manager::redact_url( $url ),
				'l' => self::origin_frame( 0 === $this->trace_callers ),
			]
		);
		return $preempt;
	}

	/**
	 * Open a span around one query. Registered on `query` at PHP_INT_MAX, so
	 * every filter that rewrites the SQL has already run and the span names
	 * what the database is actually asked.
	 *
	 * @param mixed $query The SQL, passed through untouched.
	 * @return mixed
	 */
	public function query_start( $query = '' ) {
		if ( ! Log_Manager::has_instance() ) {
			return $query;
		}
		$lm = Log_Manager::instance();
		if ( ! $lm->is_started() ) {
			return $query;
		}
		$sql                 = self::without_host_annotation( RuntimeCore::as_string( $query, '' ) );
		$this->query_spans[] = self::SQL_STATE;
		$lm->start(
			self::SQL_STATE,
			// Backtraces already answer who asked; don't pay for it twice.
			[ 'm' => $sql, 'l' => self::origin_frame( 0 === $this->trace_callers ) ]
		);
		return $query;
	}

	/**
	 * The one frame worth a label: who called this hook.
	 *
	 * Deliberately NOT `wp_debug_backtrace_summary()`, which walks and formats
	 * the whole stack — 12.7us measured, against 0.9us here — to produce a
	 * string all but one frame of which is discarded. Bounded by
	 * `ORIGIN_DEPTH`, so it is cheap enough to run on every firing rather than
	 * out of a budget, which is what lets `l` split a flame node completely.
	 *
	 * @return string `Class->method`, a function name, or '' when only
	 *                machinery is on the stack.
	 */
	private static function origin_frame( bool $past_transport = false ): string {
		$transport = null;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- The caller IS the diagnostic; depth-bounded and gated per rule.
		$frames = \debug_backtrace(
			\DEBUG_BACKTRACE_IGNORE_ARGS,
			$past_transport ? self::TRANSPORT_ORIGIN_DEPTH : self::ORIGIN_DEPTH
		);
		foreach ( $frames as $frame ) {
			$function = $frame['function'];
			$class    = $frame['class'] ?? '';
			if ( '' === $function || self::class === $class || 'WP_Hook' === $class ) {
				continue;
			}
			if ( isset( self::HOOK_DISPATCHERS[ $function ] ) ) {
				continue;
			}
			// @longform Climb past the class that applied the filter, however
			// many frames of it there are: `wpdb::get_row()` reaches `query`
			// through `wpdb::query()`, so both a fixed skip and a hardcoded
			// class name land inside wpdb rather than on the code that asked.
			// The transport is simply the first non-machinery frame, so this
			// needs no names and covers WP_Http the same way.
			if ( $past_transport && '' !== $class ) {
				if ( null === $transport ) {
					$transport = $class;
					continue;
				}
				if ( $class === $transport ) {
					continue;
				}
			}
			$name = '' === $class ? $function : $class . ( $frame['type'] ?? '::' ) . $function;
			return \substr( $name, 0, self::ORIGIN_MAX );
		}
		return '';
	}

	/**
	 * Drop the host's trailing `/* <uri> request_id: <hex> *' . '/` annotation.
	 *
	 * The platform appends it so a DB-side slow log can be traced back to a
	 * request. The record already carries both facts — the URL is the row and
	 * the rid is `Message::KEY` — so on a 958-query request it is ~100KB of
	 * duplication. Anchored at the end, so a comment inside the query stays.
	 *
	 * @param string $sql The query as the database was asked it.
	 * @return string The query without its trailing comment.
	 */
	private static function without_host_annotation( string $sql ): string {
		return \rtrim( (string) \preg_replace( '#\s*/\*[^*]*\*+([^/*][^*]*\*+)*/\s*$#', '', $sql ) );
	}

	/**
	 * Close the span `query_start` opened, and DRAIN `$wpdb->queries`.
	 *
	 * SAVEQUERIES makes `wpdb` retain every query it runs — 217 bytes each,
	 * measured — which is the memory pressure that folds these records in the
	 * first place. Draining here bounds it to what one query holds. Anything
	 * else reading that array (Query Monitor) sees an empty one, which is why
	 * this rides a per-rule opt-in rather than being always on.
	 *
	 * @param mixed  $data       Custom query data, passed through untouched.
	 * @param string $query      The SQL (unused; the label came from the open).
	 * @param float  $query_time Seconds the query took.
	 * @param string $callstack  Calling functions (unused).
	 * @param float  $query_start Unix timestamp the query started (unused).
	 * @return mixed
	 */
	public function query_end( $data = null, string $query = '', float $query_time = 0.0, string $callstack = '', float $query_start = 0.0 ) {
		$label = \array_pop( $this->query_spans );
		if ( null === $label || ! Log_Manager::has_instance() ) {
			return $data;
		}
		Log_Manager::instance()->complete( $label );
		// `property_exists`: never CREATE it on a double that has none.
		if ( isset( $GLOBALS['wpdb'] ) && \is_object( $GLOBALS['wpdb'] )
			&& \property_exists( $GLOBALS['wpdb'], 'queries' ) ) {
			$GLOBALS['wpdb']->queries = [];
		}
		return $data;
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
	 * Close the span `http_start` opened. Registered on `http_api_debug` at
	 * PHP_INT_MIN so the span covers the request and not the other listeners
	 * on that action.
	 *
	 * Closes the label it OPENED rather than one derived from `$url` again: a
	 * redirect hands this action the final URL, and a label that no longer
	 * matches leaves the span open.
	 *
	 * @param mixed               $response Response array or WP_Error.
	 * @param string              $context  Always 'response'.
	 * @param string              $class    Transport class (unused).
	 * @param array<string,mixed> $args     Request arguments (unused).
	 * @param string              $url      Request URL (unused).
	 */
	public function http_end( $response = null, string $context = '', string $class = '', array $args = [], string $url = '' ): void {
		$label = \array_pop( $this->http_spans );
		if ( null === $label || ! Log_Manager::has_instance() ) {
			return;
		}
		$inner = \is_array( $response ) ? RuntimeCore::arr( $response['response'] ?? null, [] ) : [];
		$code  = RuntimeCore::as_string( $inner['code'] ?? '', '' );
		Log_Manager::instance()->complete( $label, '' === $code ? [] : [ 'm' => $code ] );
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
