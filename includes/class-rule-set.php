<?php
/**
 * The durable ruleset: load/save + two-tier hook storage.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

/**
 * Rule LIST rides an autoloaded option. A heavy rule's hooks live in a
 * NON-autoloaded durable option (system of record) mirrored into memcache
 * (warm cache, warmed on miss). INLINE_HOOK_LIMIT is the crossover (Task 0).
 */
final class Rule_Set {

	public const OPTION_RULES          = 'newspack_event_logger_nodes_rules';
	public const OPTION_HOOKS_PREFIX   = 'newspack_event_logger_nodes_rule_hooks_';
	public const OPTION_SCHEMA_VERSION = 'newspack_event_logger_nodes_rules_schema_version';
	public const MC_HOOKS_PREFIX       = 'evlog:rules:hooks:';
	public const INLINE_HOOK_LIMIT     = 100; // <-- freeze from Task 0's crossover N (>= 65).
	public const MC_TTL                = 3600;

	/** @var Rule[] */
	private array $rules;

	/**
	 * @param Rule[] $rules
	 */
	public function __construct( array $rules ) {
		$this->rules = $rules;
	}

	/**
	 * The union across every LOG rule of the hooks it instruments and the custom
	 * events it tracks. Feeds Discovery (spoke payload) and Hook_Categorizer
	 * (browse-modal selected set) now that those readers no longer consult the
	 * retired global `log_events` / `custom_events` options.
	 *
	 * @return array{hooks: string[], custom_events: string[]}
	 */
	public function instrumented_union(): array {
		$hooks  = [];
		$custom = [];
		foreach ( $this->rules as $rule ) {
			if ( ! $rule->is_log() ) {
				continue;
			}
			foreach ( self::hooks_for( $rule ) as $h ) {
				$hooks[ $h ] = true;
			}
			foreach ( $rule->custom_events as $e ) {
				$custom[ $e ] = true;
			}
		}
		return [ 'hooks' => \array_keys( $hooks ), 'custom_events' => \array_keys( $custom ) ];
	}

	/**
	 * Resolve a rule's hooks. Inline is free; pointer reads mc, then the durable
	 * option (warming mc), then gives up to [] with a single notice.
	 *
	 * Stateless (consults only $rule + Core::$memd + the durable option), so it's
	 * static — Log_Manager already loaded the ruleset once per request; callers
	 * must NOT re-`load()` a whole second Rule_Set just to reach this.
	 *
	 * @return string[]
	 */
	public static function hooks_for( Rule $rule ): array {
		if ( Rule::HOOKS_INLINE === $rule->hooks_in ) {
			return $rule->hooks ?? [];
		}
		$memd = Core::$memd ?? null;
		if ( null !== $memd ) {
			$cached = $memd->get( self::mc_key( $rule->id ) );
			if ( \is_array( $cached ) ) {
				/** @var string[] $cached mc mirror of a durable hooks option. */
				return $cached;
			}
		}
		$durable = \get_option( self::hooks_option_name( $rule->id ), null );
		if ( \is_array( $durable ) ) {
			if ( null !== $memd ) {
				$memd->set( self::mc_key( $rule->id ), $durable, self::MC_TTL );
			}
			/** @var string[] $durable hooks list persisted by save(). */
			return $durable;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		\error_log( \sprintf( 'Newspack ELN: hooks missing for pointer rule "%s" (mc + durable option both absent).', $rule->id ) );
		return [];
	}

	public static function mc_key( string $id ): string {
		return self::MC_HOOKS_PREFIX . $id;
	}

	public static function hooks_option_name( string $id ): string {
		return self::OPTION_HOOKS_PREFIX . $id;
	}

	/**
	 * Persist a rule list: apply the inline↔pointer threshold, write durable
	 * options + warm mc for pointer rules, reconcile orphans, store the list.
	 *
	 * @param Rule[] $rules
	 */
	public function save( array $rules ): void {
		$tiered        = [];
		$stored        = [];
		$live_pointers = [];
		$memd          = Core::$memd ?? null;

		foreach ( $rules as $rule ) {
			if ( $rule->is_skip() ) {
				$tiered[] = $rule;
				$stored[] = $rule->to_array();
				continue;
			}
			$hooks = $rule->hooks;
			if ( null === $hooks && Rule::HOOKS_MC === $rule->hooks_in ) {
				// Unchanged pointer rule loaded with hooks=null: rehydrate the real
				// list so tiering below doesn't mistake it for an empty rule and
				// re-inline it to [], wiping its durable option.
				$hooks = self::hooks_for( $rule );
			}
			$hooks = $hooks ?? [];
			if ( \count( $hooks ) <= self::INLINE_HOOK_LIMIT ) {
				// Inline: strip any prior durable/mc footprint.
				\delete_option( self::hooks_option_name( $rule->id ) );
				if ( null !== $memd ) {
					$memd->delete( self::mc_key( $rule->id ) );
				}
				$inline   = new Rule(
					$rule->id, $rule->pattern, $rule->action,
					$rule->auto_disable_threshold, $rule->auto_protect_time_threshold,
					$rule->significant_events, $rule->custom_events,
					\array_values( $hooks ), Rule::HOOKS_INLINE
				);
				$tiered[] = $inline;
				$stored[] = $inline->to_array();
				continue;
			}
			// Pointer: durable option is the system of record; mc is a warm mirror.
			\update_option( self::hooks_option_name( $rule->id ), \array_values( $hooks ), false );
			if ( null !== $memd ) {
				$memd->set( self::mc_key( $rule->id ), \array_values( $hooks ), self::MC_TTL );
			}
			$live_pointers[ $rule->id ] = true;
			$pointer                    = new Rule(
				$rule->id, $rule->pattern, $rule->action,
				$rule->auto_disable_threshold, $rule->auto_protect_time_threshold,
				$rule->significant_events, $rule->custom_events,
				null, Rule::HOOKS_MC
			);
			$tiered[]                   = $pointer;
			$stored[]                   = $pointer->to_array();
		}

		$this->reconcile_orphans( $live_pointers );
		\update_option( self::OPTION_RULES, $stored, true );
		// Keep the in-memory list in the SAME re-tiered form we persisted, so
		// rules() after save() matches a fresh load() (a just-pointered rule
		// reports hooks=null/mc, not its stale inline list).
		$this->rules = $tiered;
	}

	/**
	 * Delete every durable hook option whose id is not a currently-live pointer.
	 * Covers rule deletes AND rules that shrank back inline.
	 *
	 * @param array<string, true> $live_pointer_ids
	 */
	private function reconcile_orphans( array $live_pointer_ids ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		/** @var \wpdb $wpdb */
		$like = $wpdb->esc_like( self::OPTION_HOOKS_PREFIX );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '{$like}%'" );
		foreach ( $names as $name ) {
			if ( ! \is_string( $name ) ) {
				continue;
			}
			$id = \substr( $name, \strlen( self::OPTION_HOOKS_PREFIX ) );
			if ( ! isset( $live_pointer_ids[ $id ] ) ) {
				\delete_option( $name );
			}
		}
	}

	/**
	 * One-time, idempotent, behavior-preserving migration from the seven legacy
	 * options. Returns whether it ran and whether a skip/log prefix overlap was
	 * detected (the documented semantics-flip caveat).
	 *
	 * @return array{migrated: bool, overlap: bool}
	 */
	public static function migrate_from_legacy(): array {
		if ( false !== \get_option( self::OPTION_SCHEMA_VERSION, false ) ) {
			return [
				'migrated' => false,
				'overlap'  => false,
			];
		}

		$p        = 'newspack_event_logger_nodes_';
		$log_urls = self::string_list( \get_option( $p . 'log_urls', [] ) );
		$skip     = self::string_list( \get_option( $p . 'skip_urls', [] ) );
		$bundle   = [
			'auto_disable_threshold'      => self::to_int( \get_option( $p . 'auto_disable_threshold', 0 ) ),
			'auto_protect_time_threshold' => self::to_float( \get_option( $p . 'auto_protect_time_threshold', 0.0 ) ),
			'significant_events'          => self::string_list( \get_option( $p . 'significant_events', [] ) ),
			'custom_events'               => self::string_list( \get_option( $p . 'custom_events', [] ) ),
			'hooks'                       => self::string_list( \get_option( $p . 'log_events', [] ) ),
		];

		$rules        = [];
		$existing_ids = [];
		foreach ( $skip as $pattern ) {
			$id             = self::generate_rule_id( $existing_ids );
			$existing_ids[] = $id;
			$rules[]        = new Rule( $id, $pattern, Rule::ACTION_SKIP );
		}
		$log_patterns = empty( $log_urls ) ? [ '/' ] : $log_urls;
		foreach ( $log_patterns as $pattern ) {
			$id             = self::generate_rule_id( $existing_ids );
			$existing_ids[] = $id;
			$rules[]        = new Rule(
				$id,
				$pattern,
				Rule::ACTION_LOG,
				$bundle['auto_disable_threshold'],
				$bundle['auto_protect_time_threshold'],
				$bundle['significant_events'],
				$bundle['custom_events'],
				$bundle['hooks']
			);
		}

		$overlap = self::detect_prefix_overlap( $skip, $log_urls );

		( new self( [] ) )->save( $rules );

		foreach ( [ 'log_urls', 'skip_urls', 'log_events', 'custom_events', 'significant_events', 'auto_disable_threshold', 'auto_protect_time_threshold' ] as $short ) {
			\delete_option( $p . $short );
		}
		\update_option( self::OPTION_SCHEMA_VERSION, 1, true );

		return [
			'migrated' => true,
			'overlap'  => $overlap,
		];
	}

	/**
	 * @param mixed $value Raw option value, expected to be a list of strings.
	 * @return string[]
	 */
	private static function string_list( mixed $value ): array {
		return \is_array( $value ) ? \array_values( \array_filter( $value, 'is_string' ) ) : [];
	}

	private static function to_int( mixed $value ): int {
		return \is_scalar( $value ) ? (int) $value : 0;
	}

	private static function to_float( mixed $value ): float {
		return \is_scalar( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Mint a short id not already present in $existing_ids. Walks the same
	 * deterministic md5-substr scheme migrate_from_legacy seeded with, skipping
	 * any candidate that collides with an id already in use.
	 *
	 * @param string[] $existing_ids Ids already assigned in the set being built.
	 */
	public static function generate_rule_id( array $existing_ids ): string {
		$n = 1;
		do {
			$id = self::gen_id( $n );
			++$n;
		} while ( \in_array( $id, $existing_ids, true ) );
		return $id;
	}

	private static function gen_id( int $n ): string {
		return \substr( \md5( 'eln_rule_' . $n ), 0, 8 );
	}

	/**
	 * True when any skip pattern is a strict prefix of a log pattern, or vice-versa.
	 *
	 * @param string[] $skip
	 * @param string[] $log
	 */
	private static function detect_prefix_overlap( array $skip, array $log ): bool {
		// Case-insensitive to match Rule_Matcher (a case-differing overlap still flips a decision).
		foreach ( $skip as $s ) {
			$sl = \strtolower( $s );
			foreach ( $log as $l ) {
				$ll = \strtolower( $l );
				if ( $sl !== $ll && ( \str_starts_with( $ll, $sl ) || \str_starts_with( $sl, $ll ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function load(): self {
		$raw = \get_option( self::OPTION_RULES, null );
		if ( null === $raw ) {
			return new self( [ Rule::minimal( '/' ) ] );
		}
		if ( ! \is_array( $raw ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			\error_log( 'Newspack ELN: corrupt rules option; falling back to minimal log-all rule.' );
			return new self( [ Rule::minimal( '/' ) ] );
		}
		$rules = [];
		foreach ( $raw as $entry ) {
			if ( \is_array( $entry ) ) {
				/** @var array<string, mixed> $entry stored rule shape (Rule::to_array()). */
				$rules[] = Rule::from_array( $entry );
			}
		}
		return new self( $rules );
	}

	/**
	 * @return Rule[]
	 */
	public function rules(): array {
		return $this->rules;
	}

	public function rule_by_id( string $id ): ?Rule {
		foreach ( $this->rules as $rule ) {
			if ( $id === $rule->id ) {
				return $rule;
			}
		}
		return null;
	}

	public function matcher(): Rule_Matcher {
		return new Rule_Matcher( $this->rules );
	}
}
