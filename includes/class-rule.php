<?php
/**
 * One per-URL logging rule.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

/**
 * Immutable value object. Hooks are either inlined (small rules) or held in
 * memcache behind a durable option (heavy rules) — this object only records
 * which tier via `hooks_in`; Rule_Set resolves the actual list.
 */
final class Rule {

	public const ACTION_LOG   = 'log';
	public const ACTION_SKIP  = 'skip';
	public const HOOKS_INLINE = 'inline';
	public const HOOKS_MC     = 'mc';

	/**
	 * @param string        $id                          Stable short id (keys durable option + mc mirror). '' for the synthetic baseline.
	 * @param string        $pattern                     '/prefix' or exact '/x?'.
	 * @param string        $action                      self::ACTION_LOG | self::ACTION_SKIP.
	 * @param int           $auto_disable_threshold      Count; 0 = off.
	 * @param float         $auto_protect_time_threshold Ms; 0.0 = off.
	 * @param string[]      $significant_events          Tag list.
	 * @param string[]      $custom_events               Category list.
	 * @param string[]|null $hooks                       Inline list when hooks_in=inline; null when hooks_in=mc.
	 * @param string        $hooks_in                    self::HOOKS_INLINE | self::HOOKS_MC.
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
		public readonly string $hooks_in = self::HOOKS_INLINE
	) {}

	/**
	 * @param array<string, mixed> $a Stored rule shape.
	 */
	public static function from_array( array $a ): self {
		$action = ( self::ACTION_LOG === ( $a['action'] ?? '' ) ) ? self::ACTION_LOG : self::ACTION_SKIP;
		$hooks  = \array_key_exists( 'hooks', $a ) ? $a['hooks'] : [];
		return new self(
			Core::as_string( $a['id'] ?? null, '' ),
			Core::as_string( $a['pattern'] ?? null, '/' ),
			$action,
			Core::as_int( $a['auto_disable_threshold'] ?? null ),
			Core::as_float( $a['auto_protect_time_threshold'] ?? null ),
			self::to_string_list( $a['significant_events'] ?? null ),
			self::to_string_list( $a['custom_events'] ?? null ),
			\is_array( $hooks ) ? self::to_string_list( $hooks ) : null,
			( self::HOOKS_MC === ( $a['hooks_in'] ?? '' ) ) ? self::HOOKS_MC : self::HOOKS_INLINE
		);
	}

	/**
	 * @return string[]
	 */
	private static function to_string_list( mixed $v ): array {
		if ( ! \is_array( $v ) ) {
			return [];
		}
		return \array_values( \array_map( static fn ( mixed $item ): string => Core::as_string( $item, '' ), $v ) );
	}

	public function is_skip(): bool {
		return ! $this->is_log();
	}

	public function is_log(): bool {
		return self::ACTION_LOG === $this->action;
	}

	/** A copy of this rule under a different id (immutable — the id is a pure function of the pattern; see Rule_Set::id_for). */
	public function with_id( string $id ): self {
		return new self(
			$id, $this->pattern, $this->action,
			$this->auto_disable_threshold, $this->auto_protect_time_threshold,
			$this->significant_events, $this->custom_events,
			$this->hooks, $this->hooks_in
		);
	}

	public function is_exact(): bool {
		return \str_ends_with( $this->pattern, '?' );
	}

	/**
	 * @return array<string, mixed>
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
		];
	}
}
