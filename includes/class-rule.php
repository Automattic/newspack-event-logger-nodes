<?php
/**
 * One per-URL logging rule.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes;

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
			self::to_str( $a['id'] ?? null, '' ),
			self::to_str( $a['pattern'] ?? null, '/' ),
			$action,
			self::to_int( $a['auto_disable_threshold'] ?? null ),
			self::to_float( $a['auto_protect_time_threshold'] ?? null ),
			self::to_string_list( $a['significant_events'] ?? null ),
			self::to_string_list( $a['custom_events'] ?? null ),
			\is_array( $hooks ) ? self::to_string_list( $hooks ) : null,
			( self::HOOKS_MC === ( $a['hooks_in'] ?? '' ) ) ? self::HOOKS_MC : self::HOOKS_INLINE
		);
	}

	private static function to_str( mixed $v, string $default ): string {
		return \is_scalar( $v ) ? (string) $v : $default;
	}

	private static function to_int( mixed $v ): int {
		return \is_scalar( $v ) ? (int) $v : 0;
	}

	private static function to_float( mixed $v ): float {
		return \is_scalar( $v ) ? (float) $v : 0.0;
	}

	/**
	 * @return string[]
	 */
	private static function to_string_list( mixed $v ): array {
		if ( ! \is_array( $v ) ) {
			return [];
		}
		return \array_values( \array_map( static fn ( mixed $item ): string => self::to_str( $item, '' ), $v ) );
	}

	public function is_skip(): bool {
		return ! $this->is_log();
	}

	public function is_log(): bool {
		return self::ACTION_LOG === $this->action;
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

	/**
	 * The safe baseline: log everything at this prefix, no hook instrumentation.
	 */
	public static function minimal( string $pattern = '/' ): self {
		return new self( '', $pattern, self::ACTION_LOG );
	}
}
