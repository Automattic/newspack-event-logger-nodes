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

use Newspack_Nodes\Cache_Backend;
use Newspack_Nodes\Config_System\Restart_Planner;
use Newspack_Nodes\Core;
use Newspack_Nodes\Table_Node;

/**
 * Rule LIST rides an autoloaded option. A heavy rule's hooks live in a
 * NON-autoloaded durable option (system of record) mirrored into the
 * substrate's `Table` (warm cache, warmed on miss), which scopes its keys per
 * install — two sites sharing one memcached derive the SAME rule id for the
 * same pattern, so an unscoped key would hand one site the other's hooks.
 * INLINE_HOOK_LIMIT is the crossover, measured by `wp nodes ruleset-bench`:
 * the largest hook count whose inline read still beats a table fetch while
 * the autoload tax stays negligible.
 *
 * An instance holds the rule list in the form it was last persisted in, so
 * `rules()` after `save()` matches a fresh `load()`.
 */
final class Rule_Set {
	/** Inline-hook crossover threshold; do not set below 65. */
	public const INLINE_HOOK_LIMIT   = 100;
	/** Substrate Table namespace mirroring the pointer tier's durable options. */
	public const TABLE_HOOKS         = 'eln-rule-hooks';
	/** Warm-mirror lifetime; the durable option outlives it and rewarms it. */
	public const TABLE_TTL           = 3600;
	public const OPTION_HOOKS_PREFIX = 'newspack_event_logger_nodes_rule_hooks_';
	public const OPTION_RULES        = 'newspack_event_logger_nodes_rules';

	/** @var Rule[] */
	private array $rules;

	/**
	 * The warm-mirror table, built once per process.
	 *
	 * Built once because a `Table_Node` constructor builds its `:config`
	 * interpreter, which realizes the whole node schema — real work to repeat
	 * per pointer rule per request when the namespace never changes. Null when
	 * this host has no cache backend at all: then there is no warm mirror and
	 * the durable option answers alone, which is what every read here already
	 * falls back to.
	 */
	private static ?Table_Node $hooks_table = null;

	/**
	 * @param Rule[] $rules Rules in persisted form; `load()` is the usual source.
	 */
	public function __construct( array $rules ) {
		$this->rules = $rules;
	}

	/**
	 * Discard the stored ruleset so the file config seeds again, and hand back
	 * the set that read now returns.
	 *
	 * DELETES the option rather than saving an empty list: presence is the
	 * override, so a stored `[]` is an explicit "log nothing" that would pin
	 * itself over the config seed forever.
	 *
	 * The row goes FIRST. Sweeping the pointer tier ahead of it would, on any
	 * failure in between, leave the ruleset live with every heavy rule's hooks
	 * gone — instrumenting nothing behind a rate-limited notice. Reversed, the
	 * same failure leaves orphan hook options the next `save()` sweeps.
	 *
	 * Config is memoized per process with the stored option already folded in,
	 * so it must be invalidated before the read-back or this returns the very
	 * ruleset it discarded (the `settings_set` verb resets it for this reason).
	 */
	public static function reset(): self {
		\delete_option( self::OPTION_RULES );
		( new self( [] ) )->reconcile_orphans( [] );
		Config::reset();
		self::request_reloads();
		return self::load();
	}

	/**
	 * Inline every pointer entry's hooks in a stored/synced rule-map list, resolving
	 * each from its durable option (or the table). The transport-safe form of a ruleset:
	 * self-contained, no dangling durable-option references. Non-pointer entries
	 * (and non-array junk) pass through untouched. The settings-sync value filter
	 * (`newspack_nodes/settings_sync/value`) runs the hub's rule list through this
	 * so the ruleset reaches spokes hook-complete; `apply_synced()` is the inverse.
	 *
	 * @param array<int|string,mixed> $rules_array Stored rule maps (Rule::to_array()).
	 * @return array<int,mixed>
	 */
	public static function hydrate_array( array $rules_array ): array {
		$out = [];
		foreach ( $rules_array as $entry ) {
			if ( \is_array( $entry ) && Rule::HOOKS_MC === ( $entry['hooks_in'] ?? '' ) ) {
				try {
					/** @var array<string,mixed> $entry pointer rule map. */
					$hooks = self::hooks_for( Rule::from_array( $entry ) );
				} catch ( \InvalidArgumentException $e ) {
					// Unrepresentable at rest — load() skips it; ship as found.
					Core::print_less_often( 'Newspack ELN: cannot inline hooks for stored rule: ', $e->getMessage() );
					$hooks = [];
				}
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
	 * options + warm the table for pointer rules, reconcile orphans, store the list.
	 *
	 * Skip rules bypass tiering and persist exactly as given — they instrument
	 * nothing. save() also takes ids as it finds them, which is what lets
	 * `Auto_Tuner_Node` mutate one loaded rule in place; a caller handling
	 * untrusted rules must run them through rekey_by_pattern() first.
	 *
	 * Closes by asking the live fleet to re-read; see `request_reloads()`.
	 *
	 * @param Rule[] $rules Replaces the whole list — anything omitted is deleted.
	 */
	public function save( array $rules ): void {
		$tiered        = [];
		$stored        = [];
		$live_pointers = [];

		foreach ( $rules as $rule ) {
			if ( $rule->is_skip() ) {
				$tiered[] = $rule;
				$stored[] = $rule->to_array();
				continue;
			}
			// Null hooks IS the pointer tier; rehydrate to count the real list.
			$hooks = $rule->hooks ?? self::hooks_for( $rule );
			if ( \count( $hooks ) <= self::INLINE_HOOK_LIMIT ) {
				// Inline: strip any prior durable/table footprint.
				\delete_option( self::hooks_option_name( $rule->id ) );
				self::hooks_table()?->forget( $rule->id );
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
			// Pointer: the option is the record; the table is a warm mirror.
			\update_option( self::hooks_option_name( $rule->id ), \array_values( $hooks ), false );
			self::hooks_table()?->store( $rule->id, \array_values( $hooks ) );
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
		self::request_reloads();
	}

	/**
	 * Ask every live worker to re-read its boot-frozen option cache.
	 *
	 * Signalled from `save()` rather than its callers because save() is the one
	 * origin every ruleset write passes through — `Rules_CI_Node`, the synced
	 * `Rule_Set::apply_synced()` receive path and `Auto_Tuner_Node` — so a fourth
	 * caller cannot forget it. Two of those run INSIDE a worker, so that worker
	 * signals itself along with its peers; intended, because a reload also purges
	 * the option cache its own later reads go through.
	 *
	 * Best-effort: the next worker generation loads the new ruleset regardless,
	 * so an unresolvable locks directory must not fail the write.
	 */
	private static function request_reloads(): void {
		try {
			Restart_Planner::request_reloads( Config::get_locks_directory() );
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'rules: reload signalling failed: ', $e->getMessage() );
		}
	}

	/**
	 * Delete every durable hook option whose id is not a currently-live pointer.
	 * Covers rule deletes AND rules that shrank back inline. No-ops without a
	 * $wpdb, which is how the unit suite runs with no database.
	 *
	 * @global \wpdb $wpdb
	 *
	 * @param array<string,true> $live_pointer_ids Ids just written as pointers.
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
	 * Resolve a rule's hooks. Inline is free; pointer reads the table, then the
	 * durable option (warming the table), then gives up to [] with a notice.
	 *
	 * Stateless (consults only $rule + the table + the durable option), so it's
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
		$table = self::hooks_table();
		// No cache, no table to read through; the record still reads directly.
		$hooks = null !== $table ? $table->lookup( $rule->id ) : self::durable_hooks( $rule->id );
		if ( \is_array( $hooks ) ) {
			/** @var string[] $hooks Warm mirror, or the durable option behind it. */
			return $hooks;
		}
		Core::print_less_often( 'Newspack ELN: hooks missing for pointer rule "', $rule->id, '" (table + durable option both absent).' );
		return [];
	}

	/**
	 * A pointer rule's hooks straight from the system of record, or null when
	 * that option is absent. The Table's backing and the no-cache path share it.
	 *
	 * @return string[]|null
	 */
	private static function durable_hooks( string $id ): ?array {
		$hooks = \get_option( self::hooks_option_name( $id ), null );
		/** @var string[]|null $hooks hooks list persisted by save(). */
		return \is_array( $hooks ) ? $hooks : null;
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

	/** The warm-mirror table, or null on a host with no cache backend at all. */
	private static function hooks_table(): ?Table_Node {
		if ( null === self::$hooks_table && null !== Cache_Backend::shared_first() ) {
			// The option is the system of record; the table is its warm mirror.
			self::$hooks_table = Table_Node::table( self::TABLE_HOOKS, self::TABLE_TTL )
				->backed_by(
					static function ( array $ids ): array {
						$found = [];
						foreach ( $ids as $id ) {
							$hooks = self::durable_hooks( Core::as_string( $id, '' ) );
							if ( null !== $hooks ) {
								$found[ $id ] = [ 'value' => $hooks ];
							}
						}
						return $found;
					}
				);
		}
		return self::$hooks_table;
	}

	/**
	 * Apply a synced (hydrated) ruleset on a spoke: rebuild Rule objects and
	 * route them through save(), which RE-TIERS locally — heavy rules' inline
	 * hooks are written back out to this site's own durable option + table mirror,
	 * keeping OPTION_RULES small. The inverse of hydrate_array(); the
	 * `performance` CI's `settings_set` verb calls it for OPTION_RULES instead
	 * of writing the option raw.
	 *
	 * A push that changes nothing does nothing. The hub re-sends every synced
	 * option on its periodic sweep whether or not it moved — that is what makes
	 * a freshly-connected spoke converge — and `set` gates the other options on
	 * a value comparison; this is the same gate for the one option that cannot
	 * be compared raw. Re-saving is not free: it rewrites every pointer rule's
	 * durable option and asks every worker to re-read its config.
	 *
	 * @param array<int|string,mixed> $rules_array Hydrated rule maps off the wire.
	 * @return bool Whether the ruleset actually moved.
	 */
	public static function apply_synced( array $rules_array ): bool {
		$incoming = self::rekey_by_pattern( self::rules_from_maps( $rules_array ) );
		if ( self::resolved_maps( $incoming ) === self::resolved_maps( self::load()->rules() ) ) {
			return false;
		}
		( new self( [] ) )->save( $incoming );
		return true;
	}

	/**
	 * Read the persisted ruleset, falling back to the file config.
	 *
	 * An absent option seeds from config; a corrupt (non-array) one seeds too,
	 * after a stderr notice. Non-array entries are skipped. Stored ids stand as
	 * written — only an entry stored without one gets an id minted — because
	 * every write path already rekeyed by pattern. This is the read side, so an
	 * unrepresentable row is skipped with a notice rather than thrown: one
	 * hand-edited option must not fatal every request that resolves a rule.
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
		foreach ( self::rules_from_maps( $raw, true ) as $rule ) {
			// Mint id for idless stored rule; avoids collision on '' key.
			$rules[] = '' === $rule->id ? $rule->with_id( self::id_for( $rule->pattern ) ) : $rule;
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
	 * Turn config rule maps into Rule objects, rekeyed by pattern. An operator
	 * typo skips that entry — a throw here would white-screen the site.
	 *
	 * @param array<array-key,mixed> $entries Config `rules` list.
	 * @return Rule[]
	 */
	private static function rules_from_config( array $entries ): array {
		return self::rekey_by_pattern( self::rules_from_maps( $entries, true ) );
	}

	/**
	 * Decode a list of stored/wire rule maps, skipping non-array junk.
	 *
	 * `Rule::from_array()` refuses a map the rest of the system cannot represent.
	 * A WRITE caller lets that throw, rejecting the whole push and leaving the
	 * last-good ruleset in place; a READ caller (the stored option, the config
	 * seed) passes $skip_invalid so one bad row costs only itself.
	 *
	 * @param array<array-key,mixed> $entries      Rule maps (Rule::to_array() shape).
	 * @param bool                   $skip_invalid Skip an unrepresentable map with a notice.
	 * @return Rule[]
	 * @throws \InvalidArgumentException When a map is unrepresentable and $skip_invalid is false.
	 */
	private static function rules_from_maps( array $entries, bool $skip_invalid = false ): array {
		$rules = [];
		foreach ( $entries as $entry ) {
			if ( ! \is_array( $entry ) ) {
				continue;
			}
			try {
				/** @var array<string,mixed> $entry rule shape (Rule::to_array()). */
				$rules[] = Rule::from_array( $entry );
			} catch ( \InvalidArgumentException $e ) {
				if ( ! $skip_invalid ) {
					throw $e;
				}
				Core::print_less_often( 'Newspack ELN: skipping unrepresentable rule: ', $e->getMessage() );
			}
		}
		return $rules;
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
	 * A rule list rendered comparable: every rule's stored map with its hooks
	 * resolved inline and its TIER dropped. The tier is a local storage decision
	 * — the hub sends a pointer, the spoke may hold the same rule inline — so
	 * comparing the raw shapes would report a change on every sweep forever.
	 *
	 * @param Rule[] $rules Rules of either tier.
	 * @return list<array<string,mixed>>
	 */
	private static function resolved_maps( array $rules ): array {
		return \array_values( \array_map(
			static function ( Rule $rule ): array {
				$map = $rule->to_array();
				unset( $map['hooks_in'] );
				$map['hooks'] = self::hooks_for( $rule );
				return $map;
			},
			$rules
		) );
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
