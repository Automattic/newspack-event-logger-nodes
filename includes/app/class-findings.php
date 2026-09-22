<?php
/**
 * Findings: what is wrong with one stored record, computed rather than inferred.
 *
 * The detector finds; a model explains. Most of "point me at the problem area"
 * is arithmetic over data already on disk — a share, a call count, a
 * subtraction — and handing a model computed findings beats asking it to infer
 * them from a flame tree it can only read as text.
 *
 * A plain class, not a Node: it is a pure function of a record, called by the
 * assembler behind the `?` picker and by any agent surface alike, so a click
 * and a query produce identical evidence.
 *
 * Two things every finding carries beyond its number. `measured` says WHERE it
 * came from, because "flame" and "subtraction" warrant different confidence.
 * `proposal` says what rule edit would act on it — and its `direction` is as
 * often `more` as `less`: an "Ask AI" that only ever suggested turning
 * monitoring off would make the system blinder every time it was used.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Flame_Fold;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Nodes\Core;
// Both plugins have a `Core`; the hook instrumentation is THIS plugin's.
use Newspack_Event_Logger_Nodes\App\Core as Hooks;

\defined( 'ABSPATH' ) || exit;

/**
 * Computes what is wrong with one request record, or with one URL nothing
 * measures, as a list of findings ordered worst first.
 *
 * @phpstan-type Flame_Entry array{name:string,value:float,self_ms:float,depth:int,count:int,parent:?int,shapes?:array<array-key,mixed>}
 */
class Findings {

	/**
	 * Share of the profiled time one span must hold to be called dominant.
	 * "Most", not "the largest": a request that splits evenly between two
	 * phases has no dominant span, and reporting one would be noise.
	 */
	public const DOMINANT_SHARE = 0.6;

	/** Calls of one span within a single request before repetition is a finding. */
	public const REPETITION_COUNT = 50;

	/** Profiled/duration ratio below which the record explains too little of itself. */
	public const UNATTRIBUTED_SHARE = 0.5;

	/** Plugin loads together past this share of the request are a finding. */
	public const PLUGIN_LOAD_SHARE = 0.25;

	/** How many of the heaviest plugin loads the finding names. */
	private const PLUGIN_LOAD_NAMED = 3;

	/** Unexplained interval between consecutive entries, in milliseconds. */
	public const GAP_MS = 250.0;

	/**
	 * Below this, a request is too short for any share to mean anything —
	 * 90% of 3ms is not a finding.
	 */
	public const MIN_DURATION_MS = 50.0;

	/**
	 * The coarse first round of the bisect: six hooks that split a request into
	 * phases at a fixed, small cost. The next round subdivides only the phase
	 * that held the time, which is binary search over the request lifecycle —
	 * and it is what stops a proposal being "here are forty hooks".
	 *
	 * @var list<string>
	 */
	public const LIFECYCLE_BRACKET = [
		'plugins_loaded',
		'init',
		'wp_loaded',
		'template_redirect',
		'wp_head',
		'shutdown',
	];

	/**
	 * Severity ranking, worst first — the order findings come back in.
	 *
	 * @var array<string,int>
	 */
	private const SEVERITY_ORDER = [ 'high' => 0, 'medium' => 1, 'info' => 2 ];

	/**
	 * What a span's kind and significance imply, in one table.
	 *
	 * `visibility_proposal()` and `interior_detail()` answer the same two
	 * questions — is this span already marked significant, and what kind of
	 * span is it. One entry per outcome makes the prose and the proposal
	 * impossible to drift apart; two parallel `if` ladders would have to be
	 * edited together. In a `why`, `%1$s` (or `%s`) takes the name a rule edit
	 * would bind, the bare hook, and `%2$s` the filter a transport span covers,
	 * from `App\Core::TRANSPORT_HOOKS`. Keys are `<kind>` and `significant:<kind>`,
	 * plus the one cell `transport:unlogged`; a second such cell makes the key a
	 * tuple and this table a matrix.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const SPAN_ADVICE = [
		'significant:hook' => [
			'detail' => 'It is already a significant event, so its listeners are logged — read those next.',
			'why'    => 'It is already a significant event; its listeners are in this record.',
		],
		'significant:transport' => [
			'detail' => 'It is already a significant event, so the listeners on the filter it runs through are logged — read those next.',
			'why'    => 'It is already a significant event; the listeners on its filter are in this record.',
		],
		'transport:unlogged' => [
			'detail' => 'The rule marks it significant but does not log this span, so the listeners on its filter are not wrapped.',
			'why'    => 'It is already a significant event; the `%2$s` filter\'s listeners are wrapped only where the rule logs the span, and this rule does not.',
		],
		'significant:custom' => [
			'detail' => 'The application logs this span itself, and marking it significant only keeps it from being auto-disabled — nothing about its interior follows from that.',
			'why'    => 'It is already a significant event, which for a custom event only keeps it from being auto-disabled; the application decides what it logs inside.',
		],
		'hook'             => [
			'detail'    => 'Its listeners are not logged, so what happens inside it is invisible.',
			'why'       => 'Marking %s a significant event logs its listeners, which is the only way to see which one is holding the time.',
			'action'    => 'mark_significant',
			'direction' => 'more',
			'field'     => 'significant_events',
		],
		'custom'           => [
			'detail'    => 'The application logs this span itself; what happens inside it appears only where the application logs it.',
			'why'       => '%s is a custom event the application logs itself, not a WordPress hook — significant events reach hooks only, so marking it does nothing. Its interior shows only where the application logs further custom events, enabled on this rule.',
			'action'    => 'add_custom_events',
			'direction' => 'more',
			'field'     => 'custom_events',
			'undo'      => 'Disable those custom events again once the interior is understood.',
		],
		'transport'        => [
			'detail'    => 'The logger times this round trip itself: its label names the calling frame, and its entries carry the statement or the URL. The listeners on the filter it runs through are not logged.',
			'why'       => 'Marking %1$s a significant event logs the listeners on the `%2$s` filter, which is where a rewrite or a short-circuit costs time ahead of the round trip; it takes effect where the span is logged.',
			'action'    => 'mark_significant',
			'direction' => 'more',
			'field'     => 'significant_events',
		],
		'plugin'           => [
			'detail' => 'This is one plugin file\'s load, timed by the profiler before any hook can run, so no rule edit reaches inside it.',
			'why'    => '%s is a plugin file\'s load: the cost is that plugin\'s bootstrap, and nothing a rule can switch on runs inside it.',
		],
		'listener'         => [
			'detail' => 'This is one listener on a significant hook — the time is inside this callback.',
			'why'    => '%s is a listener, logged because its hook is already a significant event — this is the finest grain the logger has, and the answer is inside that callback.',
		],
	];

	/**
	 * Findings for one completed request record, worst first.
	 *
	 * @param array<array-key,mixed> $record A stored request record (`requests.p*`).
	 * @param Rule|null              $rule   The rule governing this request's URL, or null when none does.
	 * @return list<array<string,mixed>>
	 */
	public static function for_request( array $record, ?Rule $rule = null ): array {
		$duration = Core::num_float( $record['duration_ms'] ?? 0 );
		$flame    = self::flame_of( $record );
		$nodes    = self::flatten( $flame, self::entry_shapes( $record ) );
		$profiled = self::profiled_ms( $flame, $nodes );
		$rule_id  = null === $rule ? null : $rule->id;

		$findings = [];
		$fatal    = self::fatal( $record, $rule_id );
		if ( null !== $fatal ) {
			$findings[] = $fatal;
		}
		$cold     = self::cold_start( $record, $rule, $nodes, $profiled, $duration );
		if ( null !== $cold ) {
			$findings[] = $cold;
		}
		foreach (
			[
				self::unattributed( $profiled, $duration, $rule_id ),
				self::dominant_span( $nodes, $profiled, $rule, $duration ),
				self::plugin_load( $nodes, $rule_id, $duration, $profiled ),
				self::repetition( $record, $rule ),
				self::entry_gap( $record, $rule_id ),
				self::truncation( $record, $rule_id ),
			] as $finding
		) {
			if ( null !== $finding ) {
				$findings[] = $finding;
			}
		}
		return self::worst_first( $findings );
	}

	/**
	 * Sort by severity, stable within a rank so the detector's own order — the
	 * biggest unexplained number first — survives.
	 *
	 * @param list<array<string,mixed>> $findings The findings in detection order.
	 * @return list<array<string,mixed>> The same findings, worst first.
	 */
	private static function worst_first( array $findings ): array {
		\usort(
			$findings,
			static fn ( array $a, array $b ): int =>
				( self::SEVERITY_ORDER[ Core::as_string( $a['severity'] ?? '' ) ] ?? 9 )
					<=> ( self::SEVERITY_ORDER[ Core::as_string( $b['severity'] ?? '' ) ] ?? 9 )
		);
		return $findings;
	}

	/**
	 * The record was folded or capped, so absence of evidence is not evidence
	 * of absence. `Request_Builder_Node` already marks these.
	 *
	 * @param array<array-key,mixed> $record  The request record.
	 * @param string|null            $rule_id The governing rule's id, or null when none governs.
	 * @return array<string,mixed>|null The finding, or null when the record arrived whole.
	 */
	private static function truncation( array $record, ?string $rule_id ): ?array {
		$markers = [];
		foreach ( \is_array( $record['entries'] ?? null ) ? $record['entries'] : [] as $entry ) {
			$key = \is_array( $entry ) ? Core::as_string( $entry['k'] ?? '' ) : '';
			if ( \in_array( $key, Request_Builder_Node::SEQUENCE_BREAK_KEYS, true ) ) {
				$markers[] = $key;
			}
		}
		if ( empty( $record['folded'] ) && [] === $markers ) {
			return null;
		}
		return [
			'kind'     => 'truncation',
			'severity' => 'info',
			'title'    => 'This record was folded under memory pressure',
			'detail'   => 'Entries were merged, so absence of evidence is not evidence of absence here.',
			'measured' => 'record markers',
			'metric'   => [
				'folded'  => ! empty( $record['folded'] ),
				'markers' => $markers,
			],
			'rule_id'  => $rule_id,
			'proposal' => [
				'action'    => 'trim_hooks',
				'direction' => 'less',
				'rule_id'   => $rule_id,
				'why'       => 'Fewer logged hooks on this rule means the next request of its kind survives whole.',
				'undo'      => '',
			],
		];
	}

	/**
	 * The widest unexplained interval between consecutive entries — where a
	 * `proc_open` or an outbound call hides, since neither is instrumented.
	 *
	 * @param array<array-key,mixed> $record  The request record.
	 * @param string|null            $rule_id The governing rule's id, or null when none governs.
	 * @return array<string,mixed>|null The finding, or null when no gap reaches `GAP_MS`.
	 */
	private static function entry_gap( array $record, ?string $rule_id ): ?array {
		$entries = \is_array( $record['entries'] ?? null ) ? \array_values( $record['entries'] ) : [];
		$worst   = null;
		$open    = [];
		for ( $i = 1; $i < \count( $entries ); $i++ ) {
			$prev = \is_array( $entries[ $i - 1 ] ) ? $entries[ $i - 1 ] : [];
			$next = \is_array( $entries[ $i ] ) ? $entries[ $i ] : [];
			$from = Core::as_string( $prev['k'] ?? '' );
			$to   = Core::as_string( $next['k'] ?? '' );
			$open = self::spans_after( $open, $from );
			// Inside any span but the request's own, the time is measured.
			if ( \count( $open ) > 1 ) {
				continue;
			}
			// The merged entries ARE this window; truncation() reports it.
			if ( \in_array( $from, Request_Builder_Node::SEQUENCE_BREAK_KEYS, true ) || \in_array( $to, Request_Builder_Node::SEQUENCE_BREAK_KEYS, true ) ) {
				continue;
			}
			$gap = ( Core::num_float( $next['ts'] ?? 0 ) - Core::num_float( $prev['ts'] ?? 0 ) ) * 1000.0;
			if ( $gap >= self::GAP_MS && ( null === $worst || $gap > $worst['gap_ms'] ) ) {
				$worst = [
					'gap_ms' => $gap,
					'after'  => $from,
					'before' => $to,
				];
			}
		}
		if ( null === $worst ) {
			return null;
		}
		return [
			'kind'     => 'entry_gap',
			'severity' => 'medium',
			'title'    => \sprintf(
				'%s passed between "%s" and "%s" with nothing logged',
				self::ms( $worst['gap_ms'] ),
				$worst['after'],
				$worst['before']
			),
			'detail'   => 'Nothing instrumented ran in that window. An outbound call, a subprocess or a slow query fits it.',
			'measured' => 'entry timestamps',
			'metric'   => $worst,
			'rule_id'  => $rule_id,
			'proposal' => [
				'action'    => 'add_hooks',
				'direction' => 'more',
				'rule_id'   => $rule_id,
				'hooks'     => [],
				'why'       => \sprintf( 'A hook between %s and %s would bracket the gap.', $worst['after'], $worst['before'] ),
				'undo'      => 'Remove it once the gap is explained.',
			],
		];
	}

	/**
	 * The spans still open once an entry is read, outermost first — LIFO, as
	 * `Log_Manager::complete()` itself matches, so a complete closes the
	 * nearest open span of its name and everything opened inside it.
	 *
	 * @param list<string> $open    Base names open before this entry.
	 * @param string       $keyword The entry's keyword.
	 * @return list<string> Base names open after it.
	 */
	private static function spans_after( array $open, string $keyword ): array {
		if ( 1 === \preg_match( Flame_Tree::PATTERN_START, $keyword, $m ) ) {
			$open[] = $m[1];
			return $open;
		}
		if ( 1 === \preg_match( Flame_Tree::PATTERN_COMPLETE, $keyword, $m ) ) {
			$at = \array_search( $m[1], \array_reverse( $open, true ), true );
			if ( false !== $at ) {
				return \array_slice( $open, 0, $at );
			}
		}
		return $open;
	}

	/**
	 * A span fired hundreds of times in one request — the N+1 shape, and the
	 * same family as Query Monitor's duplicate queries and hooks-by-count.
	 *
	 * Counted from `profiles`, which is exclusive by construction and present
	 * whether or not the record folded; the flame's `count` is written only by
	 * `Flame_Fold`, so an unfolded tree reports every span as a single call.
	 * `Request_Builder_Node` subtracts a state's NON-CALLBACK children only, so
	 * a significant hook's number carries its listeners' — the right charge for
	 * a repeat, since dispatching them is what the repetition costs.
	 *
	 * @param array<array-key,mixed> $record The request record.
	 * @param Rule|null              $rule   The governing rule, or null when none does.
	 * @return array<string,mixed>|null The finding, or null when no span repeats enough.
	 */
	private static function repetition( array $record, ?Rule $rule ): ?array {
		$profiles = \is_array( $record['profiles'] ?? null ) ? $record['profiles'] : [];
		$worst    = null;
		foreach ( $profiles as $name => $profile ) {
			if ( ! \is_array( $profile ) ) {
				continue;
			}
			$count = Core::num_int( $profile['count'] ?? 0 );
			if ( $count < self::REPETITION_COUNT ) {
				continue;
			}
			$self = Core::num_float( $profile['time'] ?? 0 );
			// Non-positive is a record whose spans do not add up, not a cost.
			if ( $self <= 0.0 || ( null !== $worst && $self <= $worst['self_ms'] ) ) {
				continue;
			}
			$worst = [
				'name'    => Core::as_string( $name, 'unknown' ),
				'count'   => $count,
				'self_ms' => $self,
			];
		}
		if ( null === $worst ) {
			return null;
		}
		return [
			'kind'     => 'repetition',
			'severity' => 'medium',
			'title'    => \sprintf(
				'%s fired %d times in one request, holding %s',
				$worst['name'],
				$worst['count'],
				self::ms( $worst['self_ms'] )
			),
			'detail'   => 'A count this high usually means the work inside is being repeated per item rather than done once.',
			'measured' => 'profiles',
			'metric'   => [ ...$worst, 'each_ms' => $worst['self_ms'] / $worst['count'] ],
			'rule_id'  => $rule?->id,
			'proposal' => self::visibility_proposal(
				$worst['name'],
				$rule,
				'Unmark it once the caller is identified.'
			),
		];
	}

	/**
	 * Plugin files' loads, summed, when together they hold a large share of
	 * the request. The profiler times each load before any hook can run, so no
	 * rule edit reaches inside one. A single load holding `DOMINANT_SHARE` is
	 * `dominant_span()`'s to report, so it is left out here, and the loads
	 * beside it are a finding only when they reach `PLUGIN_LOAD_SHARE` alone.
	 *
	 * @param list<Flame_Entry> $nodes    Flattened flame nodes.
	 * @param string|null       $rule_id  The governing rule's id, or null when none governs.
	 * @param float             $duration Request duration in milliseconds.
	 * @param float             $profiled Profiled milliseconds.
	 * @return array<string,mixed>|null The finding, or null when the loads, less any dominant one, hold under `PLUGIN_LOAD_SHARE`.
	 */
	private static function plugin_load( array $nodes, ?string $rule_id, float $duration, float $profiled ): ?array {
		if ( $duration < self::MIN_DURATION_MS ) {
			return null;
		}
		$loads = [];
		foreach ( $nodes as $node ) {
			if ( Flame_Tree::is_plugin_load_span( $node['name'] ) ) {
				$loads[] = [
					'plugin' => \substr( $node['name'], 0, -\strlen( Flame_Tree::PLUGIN_LOAD_SUFFIX ) ),
					'ms'     => $node['value'],
				];
			}
		}
		\usort( $loads, static fn ( array $a, array $b ): int => $b['ms'] <=> $a['ms'] );
		// A dominant load is the dominant span's; count only the rest.
		if ( [] !== $loads && $profiled > 0.0 && $loads[0]['ms'] / $profiled >= self::DOMINANT_SHARE ) {
			\array_shift( $loads );
		}
		$total = \array_sum( \array_column( $loads, 'ms' ) );
		if ( $total < $duration * self::PLUGIN_LOAD_SHARE ) {
			return null;
		}
		$heaviest = \array_slice( $loads, 0, self::PLUGIN_LOAD_NAMED );
		return [
			'kind'     => 'plugin_load',
			'severity' => 'medium',
			'title'    => \sprintf(
				'Loading %d %s took %s, %d%% of the request',
				\count( $loads ),
				1 === \count( $loads ) ? 'plugin' : 'plugins',
				self::ms( $total ),
				(int) \round( 100 * $total / $duration )
			),
			'detail'   => \sprintf(
				'The heaviest: %s. Each is a plugin file\'s own load, before any hook runs, so the cost falls only when a plugin is removed or its bootstrap made cheaper.',
				\implode(
					', ',
					\array_map( static fn ( array $load ): string => $load['plugin'] . ' ' . self::ms( $load['ms'] ), $heaviest )
				)
			),
			'measured' => 'flame',
			'metric'   => [
				'ms'       => $total,
				'share'    => $total / $duration,
				'plugins'  => \count( $loads ),
				'heaviest' => $heaviest,
			],
			'rule_id'  => $rule_id,
			'proposal' => [
				'action'    => 'none',
				'direction' => 'none',
				'rule_id'   => $rule_id,
				'why'       => 'A plugin file\'s load is timed by the profiler before any rule applies; no rule edit changes it.',
				'undo'      => '',
			],
		];
	}

	/**
	 * One span holding most of the profiled time. The DEEPEST qualifying node
	 * wins: it is the most specific thing that still dominates, and therefore
	 * the one worth being able to see inside.
	 *
	 * The finding also names the REPEAT when one exists — the outermost span on
	 * the way up that both dominates and ran more than once. A query holding
	 * 80% of a request because the content around it rendered ten times is ten
	 * renders to explain, not one slow query, and the leaf alone never says so.
	 *
	 * @param list<Flame_Entry> $nodes    Flattened flame nodes.
	 * @param float                                                        $profiled Profiled milliseconds.
	 * @param Rule|null                                                    $rule     The governing rule, or null when none does.
	 * @param float                                                        $duration Request duration in milliseconds.
	 * @return array<string,mixed>|null The finding, or null when no span holds `DOMINANT_SHARE`.
	 */
	private static function dominant_span( array $nodes, float $profiled, ?Rule $rule, float $duration ): ?array {
		if ( $profiled <= 0.0 || $duration < self::MIN_DURATION_MS ) {
			return null;
		}
		$best_index = null;
		foreach ( $nodes as $index => $node ) {
			if ( $node['value'] / $profiled < self::DOMINANT_SHARE || self::is_request_frame( $node ) ) {
				continue;
			}
			$best = null === $best_index ? null : $nodes[ $best_index ];
			if ( null === $best
					|| $node['depth'] > $best['depth']
					|| ( $node['depth'] === $best['depth'] && $node['value'] > $best['value'] ) ) {
				$best_index = $index;
			}
		}
		if ( null === $best_index ) {
			return null;
		}
		$best       = $nodes[ $best_index ];
		$share      = $best['value'] / $profiled;
		$self_share = $best['self_ms'] / $profiled;
		$repeat     = self::repeat_of( $nodes, $best_index, $profiled );
		$metric     = [
			'name'       => $best['name'],
			'ms'         => $best['value'],
			'share'      => $share,
			'self_ms'    => $best['self_ms'],
			'self_share' => $self_share,
			'depth'      => $best['depth'],
		];
		if ( null !== $repeat ) {
			$metric['repeat'] = $repeat;
		}
		$metric += self::worst_shape( $best );
		return [
			'kind'     => 'dominant_span',
			'severity' => 'high',
			'title'    => self::dominant_title( $best['name'], $share, $repeat ),
			'detail'   => \implode(
				' ',
				\array_filter(
					[
						self::repeat_detail( $repeat ),
						self::spent_detail( $best, $self_share ),
						self::interior_detail( $best['name'], $rule ),
					],
					static fn ( string $sentence ): bool => '' !== $sentence
				)
			),
			'measured' => 'flame',
			'metric'   => $metric,
			'rule_id'  => $rule?->id,
			'proposal' => self::visibility_proposal(
				$best['name'],
				$rule,
				'Unmark it once the responsible listener is known; per-callback profiling is the expensive kind.'
			),
		];
	}

	/**
	 * What rule edit would make this span's interior visible, and what it costs.
	 *
	 * @param string    $span The span's name, as the flame carries it.
	 * @param Rule|null $rule The governing rule, or null when none does.
	 * @param string    $undo The undo sentence for a `more` proposal.
	 * @return array<string,mixed>
	 */
	private static function visibility_proposal( string $span, ?Rule $rule, string $undo ): array {
		$base   = Flame_Tree::base_name( $span );
		$advice = self::span_advice( $base, $rule );
		$binds  = isset( $advice['field'] );
		// The bare hook, which is what a rule binds and what every row names.
		$named = Flame_Tree::hook_name( $base );
		$out   = [
			'action'    => $advice['action'] ?? 'none',
			'direction' => $advice['direction'] ?? 'none',
			'rule_id'   => $rule?->id,
			'why'       => \sprintf( $advice['why'], $named, Hooks::TRANSPORT_HOOKS[ $base ] ?? '' ),
			'undo'      => $binds ? ( $advice['undo'] ?? $undo ) : '',
		];
		if ( $binds ) {
			$out['field'] = $advice['field'];
			$out['value'] = $named;
		}
		return $out;
	}

	/**
	 * Why the inside of this span is or is not visible, in its own terms — only
	 * a hook has listeners to speak of.
	 *
	 * @param string    $span The span's name, as the flame carries it.
	 * @param Rule|null $rule The governing rule, or null when none does.
	 * @return string One sentence, true for this kind of span.
	 */
	private static function interior_detail( string $span, ?Rule $rule ): string {
		return self::span_advice( Flame_Tree::base_name( $span ), $rule )['detail'];
	}

	/**
	 * The `SPAN_ADVICE` row governing one span.
	 *
	 * @param string    $base The span's base name, per `Flame_Tree::base_name()`.
	 * @param Rule|null $rule The governing rule, or null when none does.
	 * @return array<string,string>
	 */
	private static function span_advice( string $base, ?Rule $rule ): array {
		$kind = self::span_kind( $base );
		// A listener or a plugin load has no significant row.
		$row = "significant:{$kind}";
		if ( ! isset( self::SPAN_ADVICE[ $row ] ) || null === $rule || ! $rule->marks_significant( Flame_Tree::hook_name( $base ) ) ) {
			return self::SPAN_ADVICE[ $kind ];
		}
		return 'transport' === $kind && ! $rule->logs_transport( $base )
			? self::SPAN_ADVICE['transport:unlogged']
			: self::SPAN_ADVICE[ $row ];
	}

	/**
	 * The five kinds of span the flame carries, classified once so every caller
	 * reaches the same `SPAN_ADVICE` row. A custom event has no listeners, and
	 * prose crediting it with any sends the reader hunting a callback that does
	 * not exist. A query or HTTP span is the logger's own, and a plugin span is
	 * one plugin file's load; calling either a custom event proposes a rule
	 * edit that changes nothing. A hook is known by its BASE name: with hook
	 * tracing on, the frame carries the caller too.
	 *
	 * @param string $base The span's base name, per `Flame_Tree::base_name()`.
	 * @return string `transport`, `plugin`, `hook`, `listener` or `custom`.
	 */
	private static function span_kind( string $base ): string {
		if ( Flame_Tree::is_transport_span( $base ) ) {
			return 'transport';
		}
		if ( Flame_Tree::is_plugin_load_span( $base ) ) {
			return 'plugin';
		}
		if ( Flame_Tree::is_hook_span( $base ) ) {
			return 'hook';
		}
		return Hooks::is_listener_span( $base ) ? 'listener' : 'custom';
	}

	/**
	 * How much of the time a span holds it actually SPENDS, said only where it
	 * has children to hide behind. A wrapper reads as 100% and sends a reader
	 * inside the one span guaranteed to contain everything — a `pyrobase` span
	 * holds 100% of the profiled time and spends 9.5% of it in its own body.
	 *
	 * @param Flame_Entry $node       The dominant node.
	 * @param float                                                  $self_share Its own body's share of the profiled time.
	 * @return string A leading sentence, or '' where nothing is contained.
	 */
	private static function spent_detail( array $node, float $self_share ): string {
		$contained = $node['value'] - $node['self_ms'];
		if ( $contained <= 0.0 ) {
			return '';
		}
		return \sprintf(
			'It spends %d%% of the profiled time in its own body; the rest is inside what it contains.',
			(int) \round( $self_share * 100 )
		);
	}

	/**
	 * The sentence that makes the repeat the first question, or '' without one.
	 *
	 * @param array{name:string,count:int,ms:float,each_ms:float,own:bool}|null $repeat What `repeat_of()` found.
	 * @return string
	 */
	private static function repeat_detail( ?array $repeat ): string {
		if ( null === $repeat ) {
			return '';
		}
		if ( $repeat['own'] ) {
			return \sprintf(
				'It ran %d times at %s each, so why it runs that often comes before why each run is slow.',
				$repeat['count'],
				self::ms( $repeat['each_ms'] )
			);
		}
		return \sprintf(
			'It runs inside %s, which ran %d times at %s each; the repeat multiplies everything inside it, so why that runs %d times comes before why this is slow.',
			$repeat['name'],
			$repeat['count'],
			self::ms( $repeat['each_ms'] ),
			$repeat['count']
		);
	}

	/**
	 * The dominant-span headline, carrying the repeat where there is one, since
	 * a reader who stops at the title should still learn what multiplied it.
	 *
	 * @param string                                                     $name   The dominant span.
	 * @param float                                                      $share  Its share of the profiled time.
	 * @param array{name:string,count:int,ms:float,each_ms:float,own:bool}|null $repeat What `repeat_of()` found.
	 * @return string
	 */
	private static function dominant_title( string $name, float $share, ?array $repeat ): string {
		$title = \sprintf( '%s holds %d%% of the profiled time', $name, (int) \round( $share * 100 ) );
		if ( null === $repeat ) {
			return $title;
		}
		return $repeat['own']
			? \sprintf( '%s across %d calls', $title, $repeat['count'] )
			: \sprintf( '%s, inside %s ×%d', $title, $repeat['name'], $repeat['count'] );
	}

	/**
	 * The statement or URL a transport span spent most of its time on, as the
	 * metric's own fields, or nothing where the span carries no table — a hook,
	 * an unfolded record, or a query the fold never merged.
	 *
	 * Worst by TIME, not by calls: one statement run four times for 41ms and
	 * another run 663 times for 331ms are different findings, and the second is
	 * the one the repeat is about.
	 *
	 * @param array<string,mixed> $node A flattened flame node.
	 * @return array<string,mixed> `shape`, `shape_calls` and `shape_ms`, or [].
	 */
	private static function worst_shape( array $node ): array {
		$worst = [];
		$most  = 0.0;
		foreach ( Flame_Fold::shape_table( $node ) as $shape => $seen ) {
			// The bucket is what the table gave up, never a statement to name.
			if ( Flame_Fold::SHAPES_OVERFLOW === $shape || ( [] !== $worst && $seen[1] <= $most ) ) {
				continue;
			}
			$most  = $seen[1];
			$worst = [
				'shape'       => (string) $shape,
				'shape_calls' => $seen[0],
				'shape_ms'    => $seen[1],
			];
		}
		return $worst;
	}

	/**
	 * The outermost span on the way up from `$index` — itself included — that
	 * dominates the profiled time and ran more than once, or null when every
	 * dominating span ran once. `own` says whether that span IS the dominant
	 * one, decided by index: a span nested in a same-name ancestor is not it.
	 *
	 * @param list<Flame_Entry> $nodes    Flattened flame nodes.
	 * @param int                                                                                  $index    The dominant node's index.
	 * @param float                                                                                $profiled Profiled milliseconds.
	 * @return array{name:string,count:int,ms:float,each_ms:float,own:bool}|null
	 */
	private static function repeat_of( array $nodes, int $index, float $profiled ): ?array {
		$repeat = null;
		for ( $at = $index; null !== $at; $at = $nodes[ $at ]['parent'] ) {
			$node = $nodes[ $at ];
			if ( $node['count'] > 1 && $node['value'] / $profiled >= self::DOMINANT_SHARE ) {
				$repeat = [
					'name'    => $node['name'],
					'count'   => $node['count'],
					'ms'      => $node['value'],
					'each_ms' => $node['value'] / $node['count'],
					'own'     => $at === $index,
				];
			}
		}
		return $repeat;
	}

	/**
	 * Whether a node is the request's own frame, which holds all of every
	 * request and so can never be the span that explains one.
	 *
	 * @param Flame_Entry $node A flattened node.
	 * @return bool
	 */
	private static function is_request_frame( array $node ): bool {
		return 1 === $node['depth'] && Log_Manager::REQUEST_LABEL === $node['name'];
	}

	/**
	 * Profiled total far below the request duration. Pure subtraction, and the
	 * finding says so — this is the one most likely to be over-read.
	 *
	 * @param float       $profiled Profiled milliseconds.
	 * @param float       $duration Request duration in milliseconds.
	 * @param string|null $rule_id  The governing rule's id, or null when none governs.
	 * @return array<string,mixed>|null The finding, or null when the record accounts for itself.
	 */
	private static function unattributed( float $profiled, float $duration, ?string $rule_id ): ?array {
		if ( $duration < self::MIN_DURATION_MS || $profiled >= $duration * self::UNATTRIBUTED_SHARE ) {
			return null;
		}
		$missing = $duration - $profiled;
		return [
			'kind'     => 'unattributed',
			'severity' => 'high',
			'title'    => \sprintf(
				'%s of %s went unmeasured',
				self::ms( $missing ),
				self::ms( $duration )
			),
			'detail'   => self::caveat(),
			'measured' => 'subtraction',
			'metric'   => [
				'duration_ms' => $duration,
				'profiled_ms' => $profiled,
				'missing_ms'  => $missing,
				'share'       => $duration > 0 ? $profiled / $duration : 0.0,
			],
			'rule_id'  => $rule_id,
			'proposal' => [
				'action'    => 'add_hooks',
				'direction' => 'more',
				'rule_id'   => $rule_id,
				'hooks'     => self::LIFECYCLE_BRACKET,
				'why'       => 'The measured spans do not account for the request, so the time is somewhere nothing is watching.',
				'undo'      => 'Remove the bracket hooks once the phase is located.',
			],
		];
	}

	/**
	 * What we do not measure. This rides in every brief and every tool
	 * description, because a model handed `175.6ms profiled / 420000ms
	 * duration` with no caveat will invent a cause for the difference.
	 *
	 * @return string The caveat, as one paragraph of prose.
	 */
	public static function caveat(): string {
		return 'The logger times ONLY the hooks the URL\'s governing rule names, the custom events '
			. 'the application logs itself, every outbound HTTP request, and every database query '
			. 'on a rule that turns on query logging — nothing else is instrumented, so an absence '
			. 'here is as often an unbound hook as an idle one. Without query logging it sees no '
			. 'SQL, and below PHP userland it sees only those HTTP calls and queries. '
			. 'Unattributed time means unmeasured, not idle.';
	}

	/**
	 * A duration a human reads at a glance: ms under a second, else seconds.
	 *
	 * @param float $ms Milliseconds.
	 * @return string One decimal place, with its unit.
	 */
	private static function ms( float $ms ): string {
		return $ms >= 1000.0
			? \sprintf( '%.1fs', $ms / 1000.0 )
			: \sprintf( '%.1fms', $ms );
	}

	/**
	 * Insufficient instrumentation, if it applies: no rule governs the URL, the
	 * governing rule registers no hooks, or the record profiled so little of
	 * its own duration that nothing can be concluded from what IS there.
	 *
	 * @param array<array-key,mixed>                                       $record   The request record.
	 * @param Rule|null                                                    $rule     The governing rule, or null when none does.
	 * @param list<Flame_Entry> $nodes    Flattened flame nodes.
	 * @param float                                                        $profiled Profiled milliseconds.
	 * @param float                                                        $duration Request duration in milliseconds.
	 * @return array<string,mixed>|null The finding, or null when the rule and the record between them measure enough.
	 */
	private static function cold_start( array $record, ?Rule $rule, array $nodes, float $profiled, float $duration ): ?array {
		$has_spans = [] !== $nodes;
		$hooks     = null === $rule ? [] : self::hooks_of( $rule );
		// Significant and custom events instrument an interior too.
		$declares  = [] !== $hooks || ( null !== $rule
			&& ( [] !== $rule->significant_events || [] !== $rule->custom_events ) );
		if ( null !== $rule && $declares && $has_spans ) {
			return null;
		}
		return self::insufficient(
			Core::as_string( $record['url'] ?? '' ),
			$rule,
			[
				'duration_ms' => $duration,
				'profiled_ms' => $profiled,
				'spans'       => \count( $nodes ),
				'hooks'       => \count( $hooks ),
			],
			'rule + record',
			[] === $hooks
		);
	}

	/**
	 * The request DIED. The one finding that needs no arithmetic: PHP knew the
	 * message, file, line and offending plugin at the moment it stopped, and
	 * `Log_Manager` wrote them down. Stating them here is the difference
	 * between "somewhere in plugins_loaded" and a file and a line.
	 *
	 * It carries no proposal: no rule edit fixes a fatal.
	 *
	 * @param array<array-key,mixed> $record  The request record.
	 * @param string|null            $rule_id The governing rule's id, or null when none governs.
	 * @return array<string,mixed>|null The finding, or null when the request did not die.
	 */
	private static function fatal( array $record, ?string $rule_id ): ?array {
		$message = Core::as_string( $record['fatal_error'] ?? '' );
		if ( '' === $message ) {
			return null;
		}
		$plugin = Core::as_string( $record['fatal_plugin'] ?? '' );
		$file   = Core::as_string( $record['fatal_file'] ?? '' );
		$line   = Core::num_int( $record['fatal_line'] ?? 0 );
		$where  = '' === $plugin ? 'outside any plugin' : "in the {$plugin} plugin";
		return [
			'kind'     => 'fatal',
			'severity' => 'high',
			'title'    => "The request died {$where}",
			'detail'   => '' === $file ? $message : "{$message} — {$file}:{$line}",
			'measured' => 'php fatal',
			'metric'   => [
				'plugin' => $plugin,
				'file'   => $file,
				'line'   => $line,
			],
			'rule_id'  => $rule_id,
		];
	}

	/**
	 * Profiled milliseconds: the root's own value when it carries one, else the
	 * sum of the top-level spans. A tree built by `Flame_Fold` sets the root;
	 * one assembled span-by-span may not.
	 *
	 * @param array<array-key,mixed>                                       $flame The flame tree root.
	 * @param list<Flame_Entry> $nodes Flattened nodes.
	 * @return float Milliseconds.
	 */
	private static function profiled_ms( array $flame, array $nodes ): float {
		$root = Core::num_float( $flame['value'] ?? 0 );
		if ( $root > 0.0 ) {
			return $root;
		}
		$sum = 0.0;
		foreach ( $nodes as $node ) {
			if ( 1 === $node['depth'] ) {
				$sum += $node['value'];
			}
		}
		return $sum;
	}

	/**
	 * The statement tables an UNFOLDED record would have had, keyed by the
	 * name path of the node each belongs to.
	 *
	 * A folded record's nodes carry their own; an unfolded one's flame keeps
	 * each span as its own node, and its statements live in the entries — which
	 * a brief ships sixty of, so the finding is the only place the answer can
	 * reach the reader. Folding those entries here, transiently, runs the one
	 * set of rules `Flame_Fold` already holds — which half names the statement,
	 * the caps, the bucket — rather than a second copy of them, and stores
	 * nothing.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @return array<string,array<array-key,mixed>> Path, names joined by U+001F, to its table.
	 */
	private static function entry_shapes( array $record ): array {
		if ( [] !== Core::arr( $record['flame'] ?? null ) ) {
			return [];
		}
		// Built, read and dropped: no in-flight pool to hold a budget against.
		$state = Flame_Fold::start( null, \PHP_INT_MAX );
		foreach ( Core::arr( $record['entries'] ?? null ) as $entry ) {
			if ( \is_array( $entry ) ) {
				Flame_Fold::add( $state, $entry );
			}
		}
		$out  = [];
		$walk = static function ( array $node, string $prefix ) use ( &$walk, &$out ): void {
			foreach ( Core::arr( $node['children'] ?? null ) as $child ) {
				$child = Core::arr( $child );
				$path  = '' === $prefix ? Core::as_string( $child['name'] ?? '' ) : $prefix . "\x1f" . Core::as_string( $child['name'] ?? '' );
				if ( [] !== Core::arr( $child['shapes'] ?? null ) ) {
					$out[ $path ] = Core::arr( $child['shapes'] );
				}
				$walk( $child, $path );
			}
		};
		$walk( Flame_Fold::tree( $state ), '' );
		return $out;
	}

	/**
	 * Flatten a flame tree into a list of `{name, value, self_ms, depth, count,
	 * parent}`, root excluded — the root IS the request, so it can never be the
	 * span holding most of the request. `parent` is the index of the entry this
	 * one sits inside, null at the top level.
	 *
	 * Spans sharing a name under the same parent are ONE entry, summed, at every
	 * depth. A stored per-request tree keeps each firing as its own sibling, so
	 * ten renders of the same content are ten nodes none of which holds much;
	 * grouped, they are one repeat that dominates, which is what a folded tree
	 * already says with its `count`. A folded node's `count` is carried; an
	 * unfolded node counts once.
	 *
	 * `self_ms` is what the span spent in its OWN body: its value less what its
	 * children hold. That is the number that separates a span doing work from
	 * one merely containing it, and summed by name it reproduces `profiles`
	 * exactly wherever no callback state exists: `profiles` does not subtract a
	 * callback (` @N`) from its parent hook, and this subtracts every child.
	 *
	 * It stays non-negative on the raise every producer applies — a parent
	 * covers at least its children's sum, through `max( value, needed )` in
	 * `Flame_Tree::cover_children()` and `Flame_Fold::flatten()`. Where that
	 * raise is what SET the value, for a span that never reported a duration,
	 * `self_ms` is the gaps between its children.
	 *
	 * @param array<array-key,mixed>               $flame   The flame tree root.
	 * @param array<string,array<array-key,mixed>> $by_path Tables an unfolded record's entries hold, by name path; see entry_shapes().
	 * @return list<Flame_Entry>
	 */
	private static function flatten( array $flame, array $by_path = [] ): array {
		$out = [];
		self::flatten_children( $out, [ $flame ], 0, null, $by_path, [] );
		return $out;
	}

	/**
	 * Append the children of every node in `$parents`, grouped by name, then
	 * each group's own children beneath it.
	 *
	 * @param list<Flame_Entry> $out     The flattened list, extended in place.
	 * @param list<array<array-key,mixed>>                                                         $parents Nodes whose children form this level.
	 * @param int                                                                                  $depth   The depth of `$parents`.
	 * @param int|null                                                                             $parent  The index of the entry `$parents` flattened to.
	 * @param array<string,array<array-key,mixed>>                                                 $by_path Tables an unfolded record's entries hold, by name path.
	 * @param list<string>                                                                         $prefix  The name path of `$parents`.
	 */
	private static function flatten_children( array &$out, array $parents, int $depth, ?int $parent, array $by_path, array $prefix ): void {
		$groups = [];
		foreach ( $parents as $node ) {
			foreach ( \is_array( $node['children'] ?? null ) ? $node['children'] : [] as $child ) {
				if ( \is_array( $child ) ) {
					// A key like '404' turns int; keep the name in the value.
					$name                         = Core::as_string( $child['name'] ?? 'unknown', 'unknown' );
					$groups[ $name ]['name']      = $name;
					$groups[ $name ]['members'][] = $child;
				}
			}
		}
		foreach ( $groups as $group ) {
			$value = 0.0;
			$self  = 0.0;
			$count  = 0;
			$shapes = [];
			foreach ( $group['members'] as $member ) {
				$member_value = Core::num_float( $member['value'] ?? 0 );
				$value       += $member_value;
				$self        += $member_value - self::children_value( $member );
				$count       += \max( 1, Core::num_int( $member['count'] ?? 0 ) );
				$shapes       = self::merge_shapes( $shapes, $member );
			}
			$path = [ ...$prefix, $group['name'] ];
			if ( [] === $shapes ) {
				$shapes = $by_path[ \implode( "\x1f", $path ) ] ?? [];
			}
			$entry = [
				'name'    => $group['name'],
				'value'   => $value,
				'self_ms' => $self,
				'depth'   => $depth + 1,
				'count'   => $count,
				'parent'  => $parent,
			];
			if ( [] !== $shapes ) {
				$entry['shapes'] = $shapes;
			}
			$out[] = $entry;
			self::flatten_children( $out, $group['members'], $depth + 1, \array_key_last( $out ), $by_path, $path );
		}
	}

	/**
	 * Add one node's shape table to the group's, through `Flame_Fold`'s own
	 * accumulator so the group can never hold more rows than a node may. A
	 * folded tree keys its children by `node_name()`, so today every group is
	 * one member and this copies its table; the accumulator is what keeps that
	 * true if grouping ever gathers two.
	 *
	 * The table stays raw, as `Flame_Fold` writes it: `worst_shape()` is the
	 * one reader and normalizes what it reads.
	 *
	 * @param array<array-key,mixed> $into   The group's table so far.
	 * @param array<array-key,mixed> $member One flame node.
	 * @return array<array-key,mixed> The table with this node folded in.
	 */
	private static function merge_shapes( array $into, array $member ): array {
		foreach ( Flame_Fold::shape_table( $member ) as $shape => $seen ) {
			Flame_Fold::fold_shape( $into, (string) $shape, $seen[0], $seen[1] );
		}
		return $into;
	}

	/**
	 * What a node's direct children hold between them.
	 *
	 * @param array<array-key,mixed> $node A flame node.
	 * @return float Milliseconds.
	 */
	private static function children_value( array $node ): float {
		$total    = 0.0;
		$children = \is_array( $node['children'] ?? null ) ? $node['children'] : [];
		foreach ( $children as $child ) {
			if ( \is_array( $child ) ) {
				$total += Core::num_float( $child['value'] ?? 0 );
			}
		}
		return $total;
	}

	/**
	 * A record's flame tree, under whichever key it arrived by.
	 *
	 * A LOADED record carries it at `flame_data` — `Performance_CI` merges the
	 * flames partition in under that name — while only a FOLDED record ever
	 * carries `flame`, which `Request_Builder_Node` writes as part of the fold.
	 * Reading one key alone makes every ordinary request look wholly unmeasured.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @return array<array-key,mixed> The flame tree root, or [] when the record carries none.
	 */
	public static function flame_of( array $record ): array {
		foreach ( [ 'flame_data', 'flame' ] as $key ) {
			if ( \is_array( $record[ $key ] ?? null ) && [] !== $record[ $key ] ) {
				return $record[ $key ];
			}
		}
		return [];
	}

	/**
	 * Findings for one URL, from its aggregate stats alone. This is the cold
	 * start: the common first ask is about a slow URL with no flame graph, no
	 * spans and no entries — only a total. The useful answer is not an
	 * explanation but WHICH INSTRUMENTATION TO SWITCH ON.
	 *
	 * @param array<array-key,mixed> $stats A URL index row (`hash`, `url`, `count`, `avg_ms`, `max_ms`, …).
	 * @param Rule|null              $rule  The rule governing that URL, or null when none does.
	 * @return list<array<string,mixed>> One finding, or [] when the rule already registers hooks.
	 */
	public static function for_url( array $stats, ?Rule $rule = null ): array {
		$url = Core::as_string( $stats['url'] ?? '' );
		if ( null !== $rule && [] !== self::hooks_of( $rule ) ) {
			return [];
		}
		$metric = [
			'count'       => Core::num_int( $stats['count'] ?? 0 ),
			'avg_ms'      => Core::num_float( $stats['avg_ms'] ?? 0 ),
			'max_ms'      => Core::num_float( $stats['max_ms'] ?? 0 ),
			'max_peak_mb' => Core::num_float( $stats['max_peak_mb'] ?? 0 ),
		];
		return [ self::insufficient( $url, $rule, $metric, 'url stats' ) ];
	}

	/**
	 * The insufficient-instrumentation finding, in either flavour: create a
	 * rule for a URL nothing governs, or bracket the lifecycle on a rule that
	 * registers nothing. Both propose MORE, and both name their own removal.
	 *
	 * @param string                 $url      The URL, which a `create_rule` proposal needs as its pattern.
	 * @param Rule|null              $rule     The governing rule, or null when none does.
	 * @param array<array-key,mixed> $metric   The numbers that ARE known.
	 * @param string                 $measured Where they came from.
	 * @param bool                   $hookless Whether the rule registers no hooks.
	 * @return array<string,mixed>
	 */
	private static function insufficient( string $url, ?Rule $rule, array $metric, string $measured, bool $hookless = true ): array {
		$proposal = null === $rule
			? [
				'action'    => 'create_rule',
				'direction' => 'more',
				'pattern'   => $url,
				'hooks'     => self::LIFECYCLE_BRACKET,
				'why'       => 'No rule governs this URL, so nothing about it is logged at all.',
				'undo'      => 'Delete the rule, or set its action to skip, once the question is answered.',
			]
			: [
				'action'    => $hookless ? 'add_hooks' : 'none',
				'direction' => $hookless ? 'more' : 'none',
				'rule_id'   => $rule->id,
				'pattern'   => $rule->pattern,
				'hooks'     => $hookless ? self::LIFECYCLE_BRACKET : [],
				'why'       => $hookless
					? 'The governing rule registers no hooks, so the request has no interior. '
						. 'These six split it into phases at a fixed, small cost; the next round subdivides only the phase that held the time.'
					: 'The rule registers hooks, but none of them ran in this request — it ended before they fired, or nothing matched.',
				'undo'      => $hookless
					? 'Trim the hook list back to the phase that mattered once it is identified — '
						. 'every enabled hook costs overhead on every request this rule matches.'
					: '',
			];

		if ( null === $rule ) {
			$title = 'No rule governs this URL, so nothing is measured';
		} elseif ( $hookless ) {
			$title = 'The governing rule registers no hooks, so nothing inside the request is measured';
		} else {
			$title = 'None of the rule\'s hooks ran in this request';
		}

		return [
			'kind'     => 'insufficient_instrumentation',
			'severity' => $hookless ? 'high' : 'info',
			'title'    => $title,
			'detail'   => 'What is known is reported above; the rest is unmeasured, not idle.',
			'measured' => $measured,
			'metric'   => $metric,
			'rule_id'  => $rule?->id,
			'proposal' => $proposal,
		];
	}

	/**
	 * The rule's hook list, or [] when it is unresolved (`hooks_in = mc`). An
	 * unresolved list is deliberately NOT treated as empty-and-therefore-bare:
	 * a pointer-tier rule has more hooks than fit inline, never none.
	 *
	 * @param Rule $rule The governing rule.
	 * @return list<string>
	 */
	private static function hooks_of( Rule $rule ): array {
		if ( null === $rule->hooks ) {
			// Unresolved means "too many to inline", not none.
			return [ '(unresolved)' ];
		}
		return \array_values( $rule->hooks );
	}
}
