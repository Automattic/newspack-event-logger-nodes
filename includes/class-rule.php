<?php
/**
 * One per-URL logging rule: a URL pattern, its log/skip verdict, and the
 * instrumentation a log verdict carries.
 *
 * Three classes divide the ruleset: `Rule` is this immutable row, `Rule_Set`
 * persists the list and resolves pointer-tier hooks, and `Rule_Matcher` picks
 * the one rule that governs a request.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object. A light rule's hooks ride inline in the autoloaded
 * rule list; a heavy rule's live in a per-rule durable option mirrored into
 * memcache. This object records only which tier, via `hooks_in` —
 * `Rule_Set::hooks_for()` resolves the actual list.
 */
final class Rule {

	/** Verdict: instrument this request. */
	public const ACTION_LOG = 'log';

	/** Verdict: log nothing. Every action but ACTION_LOG reads as this one. */
	public const ACTION_SKIP = 'skip';

	/**
	 * Longest pattern a rule may group its paths under, in bytes. The logger
	 * writes it on every request line it governs, beside a URL that already
	 * holds it, so a longer one would crowd the URL out of the line.
	 */
	public const GROUP_PATTERN_MAX_BYTES = 512;

	/** Hook tier: the list rides in `hooks`, inside the autoloaded ruleset. */
	public const HOOKS_INLINE = 'inline';

	/**
	 * Hook tier: `hooks` is null and the list lives in a per-rule durable
	 * option mirrored into memcache, which `Rule_Set::hooks_for()` resolves.
	 */
	public const HOOKS_MC = 'mc';

	/**
	 * Every property is readonly. `with()` derives a changed copy and
	 * `with_id()` narrows that to the id, so nothing mutates a rule in place.
	 *
	 * @param string        $id                          Stable short id, the pattern's Log_Manager::url_hash(); keys the durable hooks option and its mc mirror. '' when the source map carried none — Rule_Set mints it from the pattern.
	 * @param string        $pattern                     '/prefix', exact '/path?', or exact path plus query prefix '/path?query'.
	 * @param string        $action                      self::ACTION_LOG | self::ACTION_SKIP.
	 * @param int           $auto_disable_threshold      Per-request occurrence count above which auto-tune proposes disabling a hook or custom event; 0 = off.
	 * @param float         $auto_protect_time_threshold Average ms per call at or above which auto-tune promotes a hook to significant; 0.0 = off.
	 * @param string[]      $significant_events          Hook names, spelled bare, that get per-callback profiling and are exempt from auto-disable; `sql` and `http` name the `query` and `pre_http_request` filters their spans cover, and take effect under `log_queries` and `log_http`.
	 * @param string[]      $custom_events               Categories the application logs itself; never bound as do_action hooks.
	 * @param string[]|null $hooks                       Inline list when hooks_in=inline; null when hooks_in=mc, meaning unresolved.
	 * @param string        $hooks_in                    self::HOOKS_INLINE | self::HOOKS_MC.
	 * @param bool          $log_queries                 Time every SQL query as its own span; needs SAVEQUERIES and costs two entries per query.
	 * @param bool          $log_http                    Time every outbound HTTP request as its own span, between `pre_http_request` and `http_api_debug`. Off unless set; on, a request making no remote calls pays two add_filter() calls and nothing else.
	 * @param bool          $log_plugin_loads            Write the profiler mu-plugin's per-plugin load timings as spans. Off unless set; the mu-plugin measures either way, so this decides only whether the request's record carries two entries per site-activated plugin.
	 * @param bool          $trace_hooks                 Name the calling frame on each hook entry's aggregation label, so one hook firing sixteen times splits into a flame node per caller. Costs one shallow backtrace per firing.
	 * @param int           $trace_callers               Deep caller chains recorded per request on a span's start entry as `caller`, budgeted per CALLER of each hook, query statement shape and outbound URL, so a hook asked for from three places traces three times; 0 = off, and a stored `true` is a count of 1.
	 * @param bool          $log_memory                  Append `peak_mb` to every `(complete)` entry, so memory growth reads across the timeline. The terminal carries it whatever this says.
	 * @param bool          $flush_every_line            Flush the firehose after every entry, so the last line before a crash or an OOM kill is on disk. Costs one write per entry.
	 * @param bool          $log_context                 Write the close's `request_context` entry: the template, rewrite rule, query vars and conditionals of a web request.
	 * @param bool          $log_cache                   Write the close's `objectcache` row: what the request asked of the object cache.
	 * @param bool          $log_resources               Write the two `resources` getrusage() samples, at the open and at the close, which the CPU-time findings difference.
	 * @param bool          $log_php_errors              Record PHP's warnings, notices and deprecations, and WordPress's own deprecations and misuse, one row per distinct error and a count of the repeats at the close. Off unless set; off, the request installs no error handler.
	 * @param float         $sample_rate                 The probability, from 0 to 1, that a log rule logs a request it governs; 1.0 logs every one, and 0.1 roughly one in ten.
	 * @param bool          $group_paths                 Key every path under a prefix pattern with the rest of the path as its query, so it is a URL of its own in the pattern's page; off unless set.
	 *
	 * @throws \InvalidArgumentException When the pattern is empty, hooks and hooks_in contradict, the sample rate is not a probability, or a pattern with a `?`, the catch-all `/` or one longer than GROUP_PATTERN_MAX_BYTES groups its paths.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $pattern,
		public readonly string $action,
		public readonly int $auto_disable_threshold = 0,
		public readonly float $auto_protect_time_threshold = 0.0,
		public readonly array $significant_events = [],
		public readonly array $custom_events = [],
		public readonly ?array $hooks = [],
		public readonly string $hooks_in = self::HOOKS_INLINE,
		public readonly bool $log_queries = false,
		public readonly bool $log_http = false,
		public readonly bool $log_plugin_loads = false,
		public readonly bool $trace_hooks = false,
		public readonly int $trace_callers = 0,
		public readonly bool $log_memory = false,
		public readonly bool $flush_every_line = false,
		public readonly bool $log_context = false,
		public readonly bool $log_cache = false,
		public readonly bool $log_resources = false,
		public readonly bool $log_php_errors = false,
		public readonly float $sample_rate = 1.0,
		public readonly bool $group_paths = false
	) {
		if ( '' === $pattern ) {
			throw new \InvalidArgumentException( 'rule pattern is required' );
		}
		if ( ! ( $sample_rate >= 0.0 && $sample_rate <= 1.0 ) ) {
			throw new \InvalidArgumentException( 'rule sample_rate must be a probability from 0 to 1' );
		}
		if ( $group_paths && ! self::is_prefix_pattern( $pattern ) ) {
			throw new \InvalidArgumentException( 'rule group_paths needs a prefix pattern, with no ?' );
		}
		if ( $group_paths && '/' === $pattern ) {
			throw new \InvalidArgumentException( 'rule group_paths cannot fold every path on the site into one page: the pattern is /' );
		}
		if ( $group_paths && \strlen( $pattern ) > self::GROUP_PATTERN_MAX_BYTES ) {
			throw new \InvalidArgumentException( 'rule group_paths needs a pattern of at most ' . self::GROUP_PATTERN_MAX_BYTES . ' bytes' );
		}
		if ( ( null === $hooks ) !== ( self::HOOKS_MC === $hooks_in ) ) {
			throw new \InvalidArgumentException( 'rule hooks contradict hooks_in: null hooks means the mc tier, a list means inline' );
		}
	}

	/**
	 * Whether this rule suppresses logging. Every action but ACTION_LOG skips.
	 *
	 * @return bool
	 */
	public function is_skip(): bool {
		return ! $this->is_log();
	}

	/**
	 * Whether this rule logs.
	 *
	 * @return bool
	 */
	public function is_log(): bool {
		return self::ACTION_LOG === $this->action;
	}

	/**
	 * A copy of this rule under a different id. The id is a pure function of
	 * the pattern, so callers pass what `Rule_Set::id_for()` derives.
	 *
	 * @param string $id Pattern-derived id.
	 * @return self
	 */
	public function with_id( string $id ): self {
		return $this->with( [ 'id' => $id ] );
	}

	/**
	 * A copy of this rule with some fields replaced, the object being immutable.
	 *
	 * Keys come from `to_array()`; anything absent is carried over. Reconstructing
	 * by hand means twenty positional arguments at every call site, and a field
	 * added to the constructor silently drops out of the ones that were missed.
	 *
	 * @param array<string,mixed> $overrides Fields to replace.
	 * @return self
	 */
	public function with( array $overrides ): self {
		return self::from_array( \array_merge( $this->to_array(), $overrides ) );
	}

	/**
	 * The persisted and wire shape: what `Rule_Set::save()` stores, what the
	 * hub syncs to spokes, and what `from_array()` reads back.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return [
			'id'                          => $this->id,
			'pattern'                     => $this->pattern,
			'action'                      => $this->action,
			'auto_disable_threshold'      => $this->auto_disable_threshold,
			'auto_protect_time_threshold' => $this->auto_protect_time_threshold,
			'significant_events'          => $this->significant_events,
			'custom_events'               => $this->custom_events,
			'hooks'                       => $this->hooks,
			'hooks_in'                    => $this->hooks_in,
			'log_queries'                 => $this->log_queries,
			'log_http'                    => $this->log_http,
			'log_plugin_loads'            => $this->log_plugin_loads,
			'trace_hooks'                 => $this->trace_hooks,
			'trace_callers'               => $this->trace_callers,
			'log_memory'                  => $this->log_memory,
			'flush_every_line'            => $this->flush_every_line,
			'log_context'                 => $this->log_context,
			'log_cache'                   => $this->log_cache,
			'log_resources'               => $this->log_resources,
			'log_php_errors'              => $this->log_php_errors,
			'sample_rate'                 => $this->sample_rate,
			'group_paths'                 => $this->group_paths,
		];
	}

	/**
	 * Decode a stored, config-seeded, or wire rule map. Scalars coerce through
	 * Core::as_* and anything but 'log' reads as skip, but a map the rest of the
	 * system cannot represent is REFUSED rather than aliased onto a shape it can.
	 *
	 * The `hooks` key carries the tier, and only an EXPLICIT null names the
	 * pointer tier — `hooks_in` must then say so. Absent, or any other non-list,
	 * is an inline rule with no hooks. The constructor holds both invariants.
	 *
	 * @param array<string,mixed> $a Stored rule shape, as produced by to_array().
	 * @return self
	 * @throws \InvalidArgumentException When the map carries no pattern, a contradictory hooks/hooks_in pair, a sample rate that is not a probability, or grouped paths on a pattern with a `?`, on `/` or on one longer than GROUP_PATTERN_MAX_BYTES.
	 */
	public static function from_array( array $a ): self {
		$action = ( self::ACTION_LOG === ( $a['action'] ?? '' ) ) ? self::ACTION_LOG : self::ACTION_SKIP;
		$hooks  = \array_key_exists( 'hooks', $a ) ? $a['hooks'] : [];
		return new self(
			Core::as_string( $a['id'] ?? null, '' ),
			Core::as_string( $a['pattern'] ?? null, '' ),
			$action,
			Core::as_int( $a['auto_disable_threshold'] ?? null ),
			Core::as_float( $a['auto_protect_time_threshold'] ?? null ),
			self::to_string_list( $a['significant_events'] ?? null ),
			self::to_string_list( $a['custom_events'] ?? null ),
			null === $hooks ? null : self::to_string_list( $hooks ),
			( self::HOOKS_MC === ( $a['hooks_in'] ?? '' ) ) ? self::HOOKS_MC : self::HOOKS_INLINE,
			! empty( $a['log_queries'] ),
			! empty( $a['log_http'] ),
			! empty( $a['log_plugin_loads'] ),
			! empty( $a['trace_hooks'] ),
			self::to_trace_count( $a['trace_callers'] ?? 0 ),
			! empty( $a['log_memory'] ),
			! empty( $a['flush_every_line'] ),
			! empty( $a['log_context'] ),
			! empty( $a['log_cache'] ),
			! empty( $a['log_resources'] ),
			! empty( $a['log_php_errors'] ),
			Core::num_float( $a['sample_rate'] ?? 1.0, \NAN ),
			! empty( $a['group_paths'] )
		);
	}

	/**
	 * How many caller traces a stored value asks for.
	 *
	 * `true` means the default count, a numeric value is the count itself, and
	 * everything else is off. A negative is off, not a cap that never trips.
	 *
	 * @param mixed $v Stored value.
	 * @return int Caller chains per hook; 0 when off.
	 */
	private static function to_trace_count( mixed $v ): int {
		// `true` is a count of ONE: the budget keys on the caller.
		if ( true === $v ) {
			return 1;
		}
		return \max( 0, Core::num_int( $v, 0 ) );
	}

	/**
	 * Coerce a mixed field into a list of strings, discarding keys. Non-arrays
	 * yield [].
	 *
	 * @param mixed $v Candidate list.
	 * @return string[]
	 */
	private static function to_string_list( mixed $v ): array {
		if ( ! \is_array( $v ) ) {
			return [];
		}
		return \array_values( \array_map( static fn ( mixed $item ): string => Core::as_string( $item, '' ), $v ) );
	}

	/**
	 * Whether this rule logs a transport's span at all — the gate on marking
	 * that transport significant, read by the binder and by `Findings` alike.
	 *
	 * @param string $state `Flame_Tree::SQL_STATE` or `Flame_Tree::HTTP_STATE`.
	 * @return bool
	 */
	public function logs_transport( string $state ): bool {
		return 'log_queries' === self::transport_flag( $state ) ? $this->log_queries : $this->log_http;
	}

	/**
	 * The name of the flag that logs a transport's span — what a proposal
	 * points an operator at when the rule lacks it.
	 *
	 * @param string $state `Flame_Tree::SQL_STATE` or `Flame_Tree::HTTP_STATE`.
	 * @return string `log_queries` or `log_http`.
	 */
	public static function transport_flag( string $state ): string {
		return match ( $state ) {
			Flame_Tree::SQL_STATE  => 'log_queries',
			Flame_Tree::HTTP_STATE => 'log_http',
			default                => throw new \InvalidArgumentException( "Not a transport span: {$state}" ),
		};
	}

	/**
	 * Whether a pattern is a prefix: one with no `?`, which matches every
	 * path that begins with it and so has paths under it to group.
	 *
	 * @param string $pattern A rule pattern, or a `group` a request line names.
	 */
	public static function is_prefix_pattern( string $pattern ): bool {
		return ! \str_contains( $pattern, '?' );
	}

	/**
	 * Whether this rule lists a name among its significant events — the one
	 * comparison the binder's consumers make, under the one spelling a rule
	 * stores.
	 *
	 * @param string $name A hook or event name, as a rule stores it.
	 * @return bool
	 */
	public function marks_significant( string $name ): bool {
		return \in_array( $name, $this->significant_events, true );
	}

	/**
	 * Whether the pattern matches a path exactly rather than as a prefix — the
	 * trailing '?' says "path ends here".
	 *
	 * A query-bearing pattern such as '/jobs/x?job-work' also matches its path
	 * exactly, yet answers false here because the '?' is not last;
	 * `Rule_Matcher` ranks that form on its query part instead.
	 *
	 * @return bool
	 */
	public function is_exact(): bool {
		return \str_ends_with( $this->pattern, '?' );
	}
}
