<?php
/**
 * The durable ruleset: load/save + two-tier hook storage.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;

/**
 * Rule LIST rides an autoloaded option. A heavy rule's hooks live in a
 * NON-autoloaded durable option (system of record) mirrored into memcache
 * (warm cache, warmed on miss). INLINE_HOOK_LIMIT is the crossover.
 */
final class Rule_Set {
	public const INLINE_HOOK_LIMIT     = 100; // crossover threshold; not below 65.
	public const MC_HOOKS_PREFIX       = 'evlog:rules:hooks:';
	public const MC_TTL                = 3600;
	public const OPTION_HOOKS_PREFIX   = 'newspack_event_logger_nodes_rule_hooks_';

	public const OPTION_RULES          = 'newspack_event_logger_nodes_rules';
	public const OPTION_SCHEMA_VERSION = 'newspack_event_logger_nodes_rules_schema_version';

	/** Current ruleset schema. v1: legacy-option migration. v2: ids are id_for(pattern). */
	public const SCHEMA_VERSION = 2;

	/** @var Rule[] */
	private array $rules;

	/**
	 * @param Rule[] $rules
	 */
	public function __construct( array $rules ) {
		$this->rules = $rules;
	}

	/**
	 * One-time, idempotent, version-gated ruleset migration, run on activation
	 * (the deploy deactivates then re-installs+activates). Steps: v0→v1 folds the
	 * seven legacy options into a ruleset; v1→v2 rekeys stored ids to id_for(pattern).
	 */
	public static function migrate_from_legacy(): void {
		$version = Core::as_int( \get_option( self::OPTION_SCHEMA_VERSION, 0 ) );
		if ( $version >= self::SCHEMA_VERSION ) {
			return;
		}
		if ( $version < 1 ) {
			self::migrate_legacy_options();
		}
		// Every sub-v2 install needs id rekey (cheap re-save on v0 fold-in).
		self::rekey_ids();
		\update_option( self::OPTION_SCHEMA_VERSION, self::SCHEMA_VERSION, true );
		return;
	}

	/**
	 * v0→v1: synthesize a ruleset from the seven legacy options and delete them.
	 * Returns whether a skip/log prefix overlap was detected.
	 */
	private static function migrate_legacy_options(): bool {
		$p            = 'newspack_event_logger_nodes_';
		$legacy_keys  = [ 'log_urls', 'skip_urls', 'log_events', 'custom_events', 'significant_events', 'auto_disable_threshold', 'auto_protect_time_threshold' ];
		$absent       = "\0__eln_absent__\0";
		$any_present  = false;
		foreach ( $legacy_keys as $short ) {
			if ( $absent !== \get_option( $p . $short, $absent ) ) {
				$any_present = true;
				break;
			}
		}
		if ( ! $any_present ) {
			// Nothing to migrate: leave rules absent; file-config seed owns it.
			return false;
		}

		$log_urls = self::string_list( \get_option( $p . 'log_urls', [] ) );
		$skip     = self::string_list( \get_option( $p . 'skip_urls', [] ) );
		$bundle   = [
			'auto_disable_threshold'      => Core::as_int( \get_option( $p . 'auto_disable_threshold', 0 ) ),
			'auto_protect_time_threshold' => Core::as_float( \get_option( $p . 'auto_protect_time_threshold', 0.0 ) ),
			'significant_events'          => self::string_list( \get_option( $p . 'significant_events', [] ) ),
			'custom_events'               => self::string_list( \get_option( $p . 'custom_events', [] ) ),
			'hooks'                       => self::string_list( \get_option( $p . 'log_events', [] ) ),
		];

		// Key by id so pattern in both lists collapses to one rule; skip wins.
		$rules = [];
		foreach ( $skip as $pattern ) {
			$id           = self::id_for( $pattern );
			$rules[ $id ] ??= new Rule( $id, $pattern, Rule::ACTION_SKIP );
		}
		$log_patterns = empty( $log_urls ) ? [ '/' ] : $log_urls;
		foreach ( $log_patterns as $pattern ) {
			$id           = self::id_for( $pattern );
			$rules[ $id ] ??= new Rule(
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
		$rules = \array_values( $rules );

		$overlap = self::detect_prefix_overlap( $skip, $log_urls );

		( new self( [] ) )->save( $rules );

		foreach ( $legacy_keys as $short ) {
			\delete_option( $p . $short );
		}

		return $overlap;
	}

	/**
	 * @param mixed $value Raw option value, expected to be a list of strings.
	 * @return string[]
	 */
	private static function string_list( mixed $value ): array {
		return \is_array( $value ) ? \array_values( \array_filter( $value, 'is_string' ) ) : [];
	}

	/**
	 * A rule's id is the shared 12-char url_hash of its pattern. The pattern IS
	 * the identity, so there is exactly one id per pattern — you can never end up
	 * with two differently-configured rules for the same URL. See Log_Manager::url_hash.
	 */
	public static function id_for( string $pattern ): string {
		return Log_Manager::url_hash( $pattern );
	}

	/**
	 * True when any skip pattern is a strict prefix of a log pattern, or vice-versa.
	 *
	 * @param string[] $skip
	 * @param string[] $log
	 */
	private static function detect_prefix_overlap( array $skip, array $log ): bool {
		// Case-insensitive to match Rule_Matcher (case-diff overlap flips it).
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

	/**
	 * v1→v2: rekey each stored rule to id_for(pattern). Resolves a rule's hooks
	 * under its CURRENT (positional/idless) id first, then re-saves them inline so
	 * save() re-tiers under the new id and reconciles the old durable option away —
	 * no pointer-tier hooks are lost. Dedupes any same-pattern collisions.
	 */
	private static function rekey_ids(): void {
		$raw = \get_option( self::OPTION_RULES, null );
		if ( ! \is_array( $raw ) ) {
			return;
		}
		$rekeyed = [];
		foreach ( $raw as $entry ) {
			if ( ! \is_array( $entry ) ) {
				continue;
			}
			/** @var array<string, mixed> $entry stored rule shape (Rule::to_array()). */
			$rule      = Rule::from_array( $entry );
			$id        = self::id_for( $rule->pattern );
			$candidate = new Rule(
				$id, $rule->pattern, $rule->action,
				$rule->auto_disable_threshold, $rule->auto_protect_time_threshold,
				$rule->significant_events, $rule->custom_events,
				self::hooks_for( $rule ), Rule::HOOKS_INLINE
			);
			// Same-pattern collision: skip wins regardless of stored order.
			$existing = $rekeyed[ $id ] ?? null;
			if ( null === $existing || ( $existing->is_log() && $candidate->is_skip() ) ) {
				$rekeyed[ $id ] = $candidate;
			}
		}
		( new self( [] ) )->save( \array_values( $rekeyed ) );
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
		Core::print_less_often( \sprintf( 'Newspack ELN: hooks missing for pointer rule "%s" (mc + durable option both absent).', $rule->id ) );
		return [];
	}

	/**
	 * Inline every pointer entry's hooks in a stored/synced rule-map list, resolving
	 * each from its durable option (or mc). The transport-safe form of a ruleset:
	 * self-contained, no dangling durable-option references. Non-pointer entries
	 * (and non-array junk) pass through untouched. Used by to_sync_array() and by
	 * the settings-sync value filter so a hub's ruleset reaches spokes hook-complete.
	 *
	 * @param array<int|string, mixed> $rules_array Stored rule maps (Rule::to_array()).
	 * @return array<int, mixed>
	 */
	public static function hydrate_array( array $rules_array ): array {
		$out = [];
		foreach ( $rules_array as $entry ) {
			if ( \is_array( $entry ) && Rule::HOOKS_MC === ( $entry['hooks_in'] ?? '' ) ) {
				/** @var array<string, mixed> $entry pointer rule map. */
				$hooks = self::hooks_for( Rule::from_array( $entry ) );
				// Stay a pointer on []: inlining empty wipes the spoke's hooks.
				if ( [] !== $hooks ) {
					$entry['hooks']    = $hooks;
					$entry['hooks_in'] = Rule::HOOKS_INLINE;
				}
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Apply a synced (hydrated) ruleset on a spoke: rebuild Rule objects and
	 * route them through save(), which RE-TIERS locally — heavy rules' inline
	 * hooks are written back out to this site's own durable option + mc mirror,
	 * keeping OPTION_RULES small. The inverse of to_sync_array().
	 *
	 * @param array<int|string, mixed> $rules_array Hydrated rule maps off the wire.
	 */
	public static function apply_synced( array $rules_array ): void {
		$rules = [];
		foreach ( $rules_array as $entry ) {
			if ( \is_array( $entry ) ) {
				/** @var array<string, mixed> $entry hydrated rule shape (Rule::to_array()). */
				$rules[] = Rule::from_array( $entry );
			}
		}
		( new self( [] ) )->save( $rules );
	}

	public static function mc_key( string $id ): string {
		return self::MC_HOOKS_PREFIX . $id;
	}

	public static function hooks_option_name( string $id ): string {
		return self::OPTION_HOOKS_PREFIX . $id;
	}

	public static function load(): self {
		$raw = \get_option( self::OPTION_RULES, null );
		if ( null === $raw ) {
			return self::seed_from_config();
		}
		if ( ! \is_array( $raw ) ) {
			Core::stderr( 'Newspack ELN: corrupt rules option; seeding from config.' );
			return self::seed_from_config();
		}
		$rules = [];
		foreach ( $raw as $entry ) {
			if ( \is_array( $entry ) ) {
				/** @var array<string, mixed> $entry stored rule shape (Rule::to_array()). */
				$rule = Rule::from_array( $entry );
				// Mint id for idless stored rule; avoids collision on '' key.
				$rules[] = '' === $rule->id ? $rule->with_id( self::id_for( $rule->pattern ) ) : $rule;
			}
		}
		return new self( $rules );
	}

	/**
	 * Read-time default when the option is absent (or corrupt): build the ruleset
	 * from the file config's `rules` list, minting ids for entries that omit one.
	 * Empty means empty — config `rules => []` (or no rules key) yields a zero-rule
	 * set (log nothing), the same as a stored `[]`; there is no implicit log-all
	 * baseline. Does NOT persist — the file value stands in until the editor writes
	 * the option.
	 */
	private static function seed_from_config(): self {
		$raw = Config::value( 'rules' );
		return new self( \is_array( $raw ) ? self::rules_from_config( $raw ) : [] );
	}

	/**
	 * Turn config rule maps into Rule objects, deriving each id from its pattern
	 * (a config-supplied id is ignored — the pattern is the identity) and
	 * collapsing duplicate patterns to one rule, last entry wins.
	 *
	 * @param array<array-key, mixed> $entries
	 * @return Rule[]
	 */
	private static function rules_from_config( array $entries ): array {
		$by_id = [];
		foreach ( $entries as $entry ) {
			if ( ! \is_array( $entry ) ) {
				continue;
			}
			/** @var array<string, mixed> $entry config rule shape (Rule::from_array()). */
			$rule                = Rule::from_array( $entry );
			$rule                = $rule->with_id( self::id_for( $rule->pattern ) );
			$by_id[ $rule->id ]  = $rule;
		}
		return \array_values( $by_id );
	}

	/**
	 * The union across every LOG rule of the hooks it instruments and the custom
	 * events it tracks. Feeds Discovery (spoke payload) and Hook_Categorizer
	 * (browse-modal selected set).
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
				// Rehydrate hooks=null pointer rule so tiering keeps option.
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
			// Pointer: durable option is source of record; mc is warm mirror.
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
		// Keep in-memory list in persisted re-tiered form so rules()==load().
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
