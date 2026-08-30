<?php
/**
 * One per-URL logging rule: a URL pattern, its log/skip verdict, and the
 * instrumentation a log verdict carries.
 *
 * The ruleset replaced the seven global logging options. Three classes divide
 * the work: `Rule` is this immutable row, `Rule_Set` persists the list and
 * resolves pointer-tier hooks, and `Rule_Matcher` picks the one rule that
 * governs a request.
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

	public const ACTION_LOG   = 'log';
	public const ACTION_SKIP  = 'skip';
	public const HOOKS_INLINE = 'inline';
	public const HOOKS_MC     = 'mc';

	/**
	 * Every property is readonly; `with_id()` is the only derivation this class
	 * offers, and Rule_Set / Auto_Tuner_Node build a fresh Rule for any other
	 * change.
	 *
	 * @param string        $id                          Stable short id, the pattern's Log_Manager::url_hash(); keys the durable hooks option and its mc mirror. '' when the source map carried none — Rule_Set mints it from the pattern.
	 * @param string        $pattern                     '/prefix', exact '/path?', or exact path plus query prefix '/path?query'.
	 * @param string        $action                      self::ACTION_LOG | self::ACTION_SKIP.
	 * @param int           $auto_disable_threshold      Per-request occurrence count above which auto-tune proposes disabling a hook or custom event; 0 = off.
	 * @param float         $auto_protect_time_threshold Average ms per call at or above which auto-tune promotes a hook to significant; 0.0 = off.
	 * @param string[]      $significant_events          Hook names (a trailing ' hook' is stripped) that get per-callback profiling and are exempt from auto-disable.
	 * @param string[]      $custom_events               Categories the application logs itself; never bound as do_action hooks.
	 * @param string[]|null $hooks                       Inline list when hooks_in=inline; null when hooks_in=mc, meaning unresolved.
	 * @param string        $hooks_in                    self::HOOKS_INLINE | self::HOOKS_MC.
	 *
	 * @throws \InvalidArgumentException When the pattern is empty, or hooks and hooks_in contradict.
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
		public readonly bool $log_queries = false
	) {
		if ( '' === $pattern ) {
			throw new \InvalidArgumentException( 'rule pattern is required' );
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
	 * Keys are `to_array()`'s; anything absent is carried over. Reconstructing
	 * by hand means nine positional arguments at every call site, and a field
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
	 * @throws \InvalidArgumentException When the map carries no pattern, or a contradictory hooks/hooks_in pair.
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
			! empty( $a['log_queries'] )
		);
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
