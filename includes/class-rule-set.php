<?php
/**
 * The durable per-URL logging ruleset: load, save, two-tier hook storage.
 *
 * Every ruleset write lands here — the config seed, the `rules` CI editor
 * verbs, `Auto_Tuner_Node`, and the hub→spoke settings sync — so the
 * pattern-is-identity and inline↔pointer tiering invariants hold whoever
 * writes. `Log_Manager` reads it once per request and hands the rules to a
 * `Rule_Matcher`.
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
 * (warm cache, warmed on miss). INLINE_HOOK_LIMIT is the crossover, measured
 * by `wp nodes ruleset-bench`: the largest hook count whose inline read still
 * beats a memcache fetch while the autoload tax stays negligible.
 *
 * An instance holds the rule list in the form it was last persisted in, so
 * `rules()` after `save()` matches a fresh `load()`.
 */
final class Rule_Set {
	public const INLINE_HOOK_LIMIT     = 100; // crossover threshold; not below 65.
	public const MC_HOOKS_PREFIX       = 'evlog:rules:hooks:';
	public const MC_TTL                = 3600;
	public const OPTION_HOOKS_PREFIX   = 'newspack_event_logger_nodes_rule_hooks_';
	public const OPTION_RULES          = 'newspack_event_logger_nodes_rules';

	/** @var Rule[] */
	private array $rules;

	/**
	 * @param Rule[] $rules Rules in persisted form; `load()` is the usual source.
	 */
	public function __construct( array $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Read the persisted ruleset, falling back to the file config.
	 *
	 * An absent option seeds from config; a corrupt (non-array) one seeds too,
	 * after a stderr notice. Non-array entries are skipped. Stored ids stand as
	 * written — only an entry stored without one gets an id minted — because
	 * every write path already rekeyed by pattern. This is the read side.
	 */
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
	 * from the file config's `rules` list, rekeyed by pattern — a config entry's
	 * own `id`, if it declares one, is ignored.
	 *
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
	 * Turn config rule maps into Rule objects, rekeyed by pattern.
	 *
	 * @param array<array-key, mixed> $entries Config `rules` list.
	 * @return Rule[]
	 */
	private static function rules_from_config( array $entries ): array {
		return self::rekey_by_pattern( self::rules_from_maps( $entries ) );
	}

	/**
	 * Rekey every rule to its pattern-derived id, ignoring any id it arrived
	 * with, and collapse duplicate patterns to one rule (last entry wins).
	 *
	 * The pattern IS the identity, so this is what makes "one rule per URL"
	 * true rather than merely conventional. Every write path that accepts
	 * outside rules runs through it: the config seed, the editor's `save` verb,
	 * and `apply_synced()` off the wire. (The editor's `upsert` verb reaches the
	 * same result by minting the id with `id_for()` and dropping the entry that
	 * already holds that pattern.) Two entries that kept differing ids for one
	 * pattern would both persist and race in the matcher; two that kept a SHARED
	 * id would alias one durable hooks option, and the inline one's delete_option
	 * would wipe the pointer one's list.
	 *
	 * @param Rule[] $rules Rules carrying arbitrary (or absent) ids.
	 * @return Rule[]
	 */
	public static function rekey_by_pattern( array $rules ): array {
		$by_id = [];
		foreach ( $rules as $rule ) {
			$id           = self::id_for( $rule->pattern );
			$by_id[ $id ] = $rule->with_id( $id );
		}
		return \array_values( $by_id );
	}

	/**
	 * A rule's id is the shared 12-char url_hash of its pattern. The pattern IS
	 * the identity, so there is exactly one id per pattern — you can never end up
	 * with two differently-configured rules for the same URL. See Log_Manager::url_hash.
	 *
	 * @param string $pattern Rule pattern: '/prefix', exact '/x?', or '/x?query'.
	 * @return string 12-character hex id.
	 */
	public static function id_for( string $pattern ): string {
		return Log_Manager::url_hash( $pattern );
	}

	/**
	 * Decode a list of stored/wire rule maps, skipping non-array junk.
	 *
	 * @param array<array-key, mixed> $entries Rule maps (Rule::to_array() shape).
	 * @return Rule[]
	 */
	private static function rules_from_maps( array $entries ): array {
		$rules = [];
		foreach ( $entries as $entry ) {
			if ( \is_array( $entry ) ) {
				/** @var array<string, mixed> $entry rule shape (Rule::to_array()). */
				$rules[] = Rule::from_array( $entry );
			}
		}
		return $rules;
	}

	/**
	 * Inline every pointer entry's hooks in a stored/synced rule-map list, resolving
	 * each from its durable option (or mc). The transport-safe form of a ruleset:
	 * self-contained, no dangling durable-option references. Non-pointer entries
	 * (and non-array junk) pass through untouched. The settings-sync value filter
	 * (`newspack_nodes/settings_sync/value`) runs the hub's rule list through this
	 * so the ruleset reaches spokes hook-complete; `apply_synced()` is the inverse.
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
	 * Resolve a rule's hooks. Inline is free; pointer reads mc, then the durable
	 * option (warming mc), then gives up to [] with a single notice.
	 *
	 * Stateless (consults only $rule + Core::$memd + the durable option), so it's
	 * static — Log_Manager already loaded the ruleset once per request; callers
	 * must NOT re-`load()` a whole second Rule_Set just to reach this.
	 *
	 * @param Rule $rule Rule of either tier.
	 * @return string[] Hook names; [] when a pointer rule's hooks are unresolvable.
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
		Core::print_less_often( 'Newspack ELN: hooks missing for pointer rule "', $rule->id, '" (mc + durable option both absent).' );
		return [];
	}

	/**
	 * The memcache key mirroring a pointer rule's durable hooks option.
	 *
	 * @param string $id Rule id.
	 * @return string Memcache key.
	 */
	public static function mc_key( string $id ): string {
		return self::MC_HOOKS_PREFIX . $id;
	}

	/**
	 * The non-autoloaded option holding a pointer rule's hooks — the system of
	 * record for that tier. `reconcile_orphans()` sweeps this namespace, and
	 * uninstall cleanup deletes it by the same prefix.
	 *
	 * @param string $id Rule id.
	 * @return string Option name.
	 */
	public static function hooks_option_name( string $id ): string {
		return self::OPTION_HOOKS_PREFIX . $id;
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
	 * Skip rules bypass tiering and persist exactly as given — they instrument
	 * nothing. save() also takes ids as it finds them, which is what lets
	 * `Auto_Tuner_Node` mutate one loaded rule in place; a caller handling
	 * untrusted rules must run them through rekey_by_pattern() first.
	 *
	 * @param Rule[] $rules Replaces the whole list — anything omitted is deleted.
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
	 * Covers rule deletes AND rules that shrank back inline. No-ops without a
	 * $wpdb, which is how the unit suite runs with no database.
	 *
	 * @global \wpdb $wpdb
	 *
	 * @param array<string, true> $live_pointer_ids Ids just written as pointers.
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
	 * Apply a synced (hydrated) ruleset on a spoke: rebuild Rule objects and
	 * route them through save(), which RE-TIERS locally — heavy rules' inline
	 * hooks are written back out to this site's own durable option + mc mirror,
	 * keeping OPTION_RULES small. The inverse of hydrate_array(); the
	 * `performance` CI's `settings_set` verb calls it for OPTION_RULES instead
	 * of writing the option raw.
	 *
	 * @param array<int|string, mixed> $rules_array Hydrated rule maps off the wire.
	 */
	public static function apply_synced( array $rules_array ): void {
		( new self( [] ) )->save( self::rekey_by_pattern( self::rules_from_maps( $rules_array ) ) );
	}

	/**
	 * The rule list in its last-persisted form.
	 *
	 * @return Rule[]
	 */
	public function rules(): array {
		return $this->rules;
	}

	/**
	 * @param string $id Rule id.
	 * @return Rule|null The rule, or null when no rule carries that id.
	 */
	public function rule_by_id( string $id ): ?Rule {
		foreach ( $this->rules as $rule ) {
			if ( $id === $rule->id ) {
				return $rule;
			}
		}
		return null;
	}

	/** A matcher over these rules; Rule_Matcher owns the specificity order. */
	public function matcher(): Rule_Matcher {
		return new Rule_Matcher( $this->rules );
	}
}
