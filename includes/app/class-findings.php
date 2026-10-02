<?php
/**
 * Findings: what is wrong with one stored record, computed rather than inferred.
 *
 * The detector finds; a model explains. Most of "point me at the problem area"
 * is arithmetic over data already on disk — a share, a call count, a
 * timestamp difference — and handing a model computed findings beats asking it to infer
 * them from a flame tree it can only read as text.
 *
 * A plain class, not a Node: it is a pure function of a record, called by the
 * assembler behind the `?` picker and by any agent surface alike, so a click
 * and a query produce identical evidence.
 *
 * Two things every finding carries beyond its number. `measured` says WHERE it
 * came from, because "flame" and "entry timestamps" warrant different confidence.
 * `proposal` says what rule edit would act on it — and its `direction` is as
 * often `more` as `less`: an "Ask AI" that only ever suggested turning
 * monitoring off would make the system blinder every time it was used.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Flame_Fold;
use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Nodes\Core;
// Both plugins have a `Core`; the hook instrumentation is THIS plugin's.
use Newspack_Event_Logger_Nodes\App\Core as Hooks;

\defined( 'ABSPATH' ) || exit;

/**
 * Computes what is wrong with one request record, or with one URL nothing
 * measures, as a list of findings ordered worst first.
 *
 * @phpstan-type Flame_Entry array{name:string,value:float,self_ms:float,max:float,depth:int,count:int,parent:?int,i?:int,shapes?:array<array-key,mixed>}
 * @phpstan-type Gap_Window array{gap_ms:float,after:string,before:string,from_i:int,to_i:int,at_ms:float,on_cpu_min_ms?:float,on_cpu_max_ms?:float,inside?:string}
 * @phpstan-type Open_Span array{0:string,1:string,2:bool,3:?Gap_Window}
 */
class Findings {

	/**
	 * Share of the request one span must hold to be called dominant.
	 * "Most", not "the largest": a request that splits evenly between two
	 * phases has no dominant span, and reporting one would be noise.
	 */
	public const DOMINANT_SHARE = 0.6;

	/** Calls of one span in one request that, holding `REPETITION_SHARE`, repeat. */
	public const REPETITION_COUNT = 50;

	/** Share of the request `REPETITION_COUNT` calls must hold between them to be a finding. */
	private const REPETITION_SHARE = 0.05;

	/** Share of the request a span called more than once holds to repeat at any count. */
	private const HEAVY_REPEAT_SHARE = 0.25;

	/** Share of a span its children must hold to explain it. */
	public const EXPLAINED_SHARE = 0.5;

	/** Plugin loads together past this share of the request are a finding. */
	public const PLUGIN_LOAD_SHARE = 0.25;

	/** How many of the heaviest plugin loads the finding names. */
	private const PLUGIN_LOAD_NAMED = 3;

	/** Joins a span path's names, outermost first, in every metric that carries one. */
	private const PATH_SEPARATOR = ' › ';

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
	 * and it is what stops a proposal being "here are forty hooks". It opens
	 * on `setup_theme` because the logger binds its rule inside
	 * `plugins_loaded`, too late to time that hook.
	 *
	 * @var list<string>
	 */
	public const LIFECYCLE_BRACKET = [
		'setup_theme',
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
	 * The way each proposal's action moves visibility, read by `proposal()`.
	 *
	 * @var array<string,'more'|'less'|'none'>
	 */
	private const DIRECTION = [
		'none'             => 'none',
		'trim_hooks'       => 'less',
		'add_hooks'        => 'more',
		'create_rule'      => 'more',
		'mark_significant' => 'more',
		'log_transport'    => 'more',
	];

	/**
	 * What a span's kind and significance imply, in one table.
	 *
	 * `visibility_proposal()` and `dominant_span()`'s detail answer the same two
	 * questions — is this span already marked significant, and what kind of
	 * span is it. One entry per outcome makes the prose and the proposal
	 * impossible to drift apart; two parallel `if` ladders would have to be
	 * edited together. In a `why`, `%1$s` (or `%s`) takes the name a rule edit
	 * would bind, the bare hook, `%2$s` the filter a transport span covers,
	 * from `App\Core::TRANSPORT_HOOKS`, and `%3$s` the flag that logs it. A
	 * row whose `field` is `transport` binds that flag rather than the span.
	 * Keys are `<kind>` and `significant:<kind>`,
	 * plus the one cell `transport:unlogged` for a rule that does not log the
	 * span; a second such cell makes the key a tuple and this table a matrix.
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
			'detail'    => 'The rule does not log this span, so the listeners on the filter it runs through are not wrapped.',
			'why'       => 'The `%2$s` filter\'s listeners are wrapped only where the rule logs the span, and this rule does not: `%3$s` comes first, and marking %1$s significant after that.',
			'action'    => 'log_transport',
			'field'     => 'transport',
			'undo'      => 'Turn the flag off again once the span is understood; it costs two entries per round trip.',
		],
		'significant:custom' => [
			'detail' => 'The application logs this span itself, and marking it significant only keeps it from being auto-disabled — nothing about its interior follows from that.',
			'why'    => 'It is already a significant event, which for a custom event only keeps it from being auto-disabled; the application decides what it logs inside.',
		],
		'hook'             => [
			'detail'    => 'Its listeners are not logged, so what happens inside it is invisible.',
			'why'       => 'Marking %s a significant event logs its listeners, which is the only way to see which one is holding the time.',
			'action'    => 'mark_significant',
			'field'     => 'significant_events',
		],
		'custom'           => [
			'detail' => 'The application logs this span itself, so no rule edit reaches inside it.',
			'why'    => '%s is a custom event the application logs itself, not a WordPress hook, so no rule edit reaches inside it.',
		],
		'transport'        => [
			'detail'    => 'The logger times this round trip itself: its label names the calling frame, and its entries carry the statement or the URL. The listeners on the filter it runs through are not logged.',
			'why'       => 'Marking %1$s a significant event logs the listeners on the `%2$s` filter, which is where a rewrite or a short-circuit costs time ahead of the round trip; it takes effect where the span is logged.',
			'action'    => 'mark_significant',
			'field'     => 'significant_events',
		],
		'plugin'           => [
			'detail' => 'This is one plugin file\'s load, timed by the profiler before any hook can run, so no rule edit reaches inside it.',
			'why'    => '%s is a plugin file\'s load: the cost is that plugin\'s bootstrap, and nothing a rule can switch on runs inside it.',
		],
		'command'          => [
			'detail' => 'This is one command verb\'s handler: its time is that verb\'s, and the SQL, HTTP, hook and URL-read spans it ran nest inside it.',
			'why'    => '%s is a command verb, timed whatever the rule says; the time is inside that verb, so read its children — no rule edit reaches the handler itself.',
		],
		'url_read'         => [
			'detail' => 'The event logger times this step of the URL read itself, whatever the rule says, so no rule edit reaches inside it.',
			'why'    => '%s is a step of `Performance_CI_Node`\'s URL read, whose `(complete)` line says whether its cache hit or how many rows it folded. Look there — nothing a rule can switch on runs inside it.',
		],
		'upkeep'           => [
			'detail' => 'This is a builder\'s own work, told whatever the rule says, so no rule edit reaches inside it.',
			'why'    => '%s is a step of a builder\'s upkeep on its worker, whose `(complete)` line counts what it wrote, folded or healed. Look there — nothing a rule can switch on runs inside it.',
		],
		'listener'         => [
			'detail' => 'This is one listener on a significant hook — the time is inside this callback.',
			'why'    => '%s is a listener, logged because its hook is already a significant event — this is the finest grain the logger has, and the answer is inside that callback.',
		],
	];

	/**
	 * What a span's `l` label names, by `span_kind()`, which decides what a
	 * repeat is. A custom event's label is the WORK — the function, include
	 * or macro the application names — so each label repeats on its own. A
	 * hook's is the caller `App\Core::origin_frame()` finds under
	 * `trace_hooks`, and a transport's the code that ran the query or the
	 * call, so the span repeats whoever called it. A listener's is empty,
	 * and the command, URL-read, upkeep and plugin spans write none.
	 *
	 * @var array<string,'work'|'caller'|'none'>
	 */
	private const LABEL_NAMES = [
		'custom'    => 'work',
		'hook'      => 'caller',
		'transport' => 'caller',
		'listener'  => 'none',
		'command'   => 'none',
		'url_read'  => 'none',
		'upkeep'    => 'none',
		'plugin'    => 'none',
	];

	/**
	 * Findings for one completed request record, worst first.
	 *
	 * The record's own `rule_id` is the stamp the site wrote; `$rule` is that
	 * stamp resolved in THIS ruleset, and null when it is not there. Every
	 * finding then carries the stamp, so one record names one rule.
	 *
	 * @param array<array-key,mixed> $record A stored request record (`requests.p*`).
	 * @param Rule|null              $rule   The rule governing this request's URL, or null when none resolves.
	 * @return list<array<string,mixed>>
	 */
	public static function for_request( array $record, ?Rule $rule = null ): array {
		$duration = Core::num_float( $record['duration_ms'] ?? 0 );
		$entries  = \array_values( Core::arr( $record['entries'] ?? null ) );
		$flame    = self::flame_of( $record );
		$nodes    = [] === Core::arr( $flame['children'] ?? null ) ? [] : self::flatten( $flame, empty( $flame['folded'] ) ? self::entry_shapes( $record ) : [] );
		$stamped  = self::rule_stamp( $record );
		$missing  = null === $rule && '' !== $stamped;
		$rule_id  = $rule?->id;

		// A share needs a timing sample (decision 24) long enough to divide.
		$shares   = Flame_Builder_Node::timing_counts( $duration, $record['error_status'] ?? '-' ) && $duration >= self::MIN_DURATION_MS;
		$stopped  = self::stopped( $record, $entries, $rule );
		$cold     = $missing ? null : self::cold_start( $record, $rule, $nodes, null === $stopped ? $duration : null );
		$dominant = $shares ? self::dominant_span( $nodes, $rule, $duration, ! $missing, $entries ) : null;
		$findings = \array_values(
			\array_filter(
				[
					self::fatal( $record, $rule_id ),
					$stopped,
					$missing ? self::unresolved_rule( $stamped ) : null,
					$cold,
					$dominant,
					$shares ? self::plugin_load( $nodes, $rule_id, $duration ) : null,
					$shares ? self::repetition( $record, $rule, $duration, $dominant ) : null,
					self::entry_gap( $entries, $rule, $cold, $dominant ),
					self::truncation( $record, $entries, $rule_id ),
				],
				static fn ( ?array $finding ): bool => null !== $finding
			)
		);
		if ( $missing ) {
			// One record, one rule id; no edit here reaches it, so no proposal.
			$findings = \array_map(
				static function ( array $finding ) use ( $stamped ): array {
					unset( $finding['proposal'] );
					$finding['rule_id'] = $stamped;
					return $finding;
				},
				$findings
			);
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
	 * @param list<mixed>            $entries The record's entries.
	 * @param string|null            $rule_id The governing rule's id, or null when none governs.
	 * @return array<string,mixed>|null The finding, or null when the record arrived whole.
	 */
	private static function truncation( array $record, array $entries, ?string $rule_id ): ?array {
		$markers = [];
		foreach ( $entries as $entry ) {
			$key = Core::as_string( Core::arr( $entry )['k'] ?? '' );
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
			'proposal' => self::proposal( 'trim_hooks', $rule_id, 'Fewer logged hooks on this rule means the next request of its kind survives whole.' ),
		];
	}

	/**
	 * The widest unexplained interval between consecutive entries — where a
	 * `proc_open` hides, and an outbound call on a rule without HTTP logging.
	 *
	 * With only the request's own span open, any wide window counts. Inside
	 * any other span a window counts when that span logged child spans and is
	 * no transport — before its first child, between children or after its
	 * last — judged when the span closes, or when the record ends for a span
	 * a timed-out record never closed. A span holding only point entries is a
	 * leaf its duration measures whole, and a transport's self time IS its
	 * round trip, so neither holds a gap. Past the fold marker the record is
	 * spliced from a longer run, so only rows whose `n` steps by one are
	 * neighbours there.
	 *
	 * The metric names the window by `from_i` and `to_i`, the positions of the
	 * rows either side of it in the full `entries`, environment row included —
	 * the positions an `entry:` ask names — and by `at_ms`, where it opens,
	 * measured from the record's first entry. The proposal is the one edit
	 * known to light the window, made once.
	 *
	 * @param list<mixed>              $entries  The record's entries.
	 * @param Rule|null                $rule     The governing rule, or null when none does.
	 * @param array<string,mixed>|null $cold     The cold-start finding, when there is one.
	 * @param array<string,mixed>|null $dominant The dominant-span finding, when there is one.
	 * @return array<string,mixed>|null The finding, or null when no gap reaches `GAP_MS`.
	 */
	private static function entry_gap( array $entries, ?Rule $rule, ?array $cold, ?array $dominant ): ?array {
		$rule_id = $rule?->id;
		$cpu     = false;
		$origin  = Core::num_float( Core::arr( $entries[0] ?? null )['ts'] ?? 0 );
		$worst   = null;
		$open    = [];
		$prev    = null;
		$latest  = null;
		$spliced = false;
		foreach ( $entries as $at => $entry ) {
			$next = Core::arr( $entry );
			$to   = Core::num_float( $next['ts'] ?? 0 );
			if ( null !== $prev ) {
				// From the latest stamp: a back-dated row stretches nothing.
				[ $latest_at, $latest_row ] = $latest;
				$from = Core::num_float( $latest_row['ts'] ?? 0 );
				$gap  = ( $to - $from ) * 1000.0;
				if ( $gap >= self::GAP_MS && ( null === $worst || $gap > $worst['gap_ms'] ) && self::adjacent( $prev, $next, $spliced ) ) {
					$window = [
						'gap_ms' => $gap,
						'after'  => Core::as_string( $latest_row['k'] ?? '' ),
						'before' => Core::as_string( $next['k'] ?? '' ),
						'from_i' => $latest_at,
						'to_i'   => $at,
						'at_ms'  => ( $from - $origin ) * 1000.0,
					];
					// The engine's CPU is a child's, which no sample counts.
					if ( ! \in_array( Request_Builder_Node::NESTED_PRODUCER, \array_column( $open, 0 ), true ) ) {
						$cpu      = false === $cpu ? Log_Manager::cpu_between_samples( $entries ) : $cpu;
						$window  += self::on_cpu( $cpu, $from, $to, $gap );
					}
					$top = \array_key_last( $open );
					if ( null === $top || Log_Manager::REQUEST_LABEL === $open[ $top ][0] ) {
						$worst = $window;
					} elseif ( $gap > ( $open[ $top ][3]['gap_ms'] ?? 0.0 ) ) {
						$open[ $top ][3] = $window + [ 'inside' => $open[ $top ][0] ];
					}
				}
			}
			foreach ( self::track( $open, $next ) as $closed ) {
				$worst = self::widest( $worst, $closed );
			}
			$spliced = $spliced || Request_Builder_Node::FOLD_MARKER_KEY === ( $next['k'] ?? null );
			$latest  = null === $latest || $to >= Core::num_float( $latest[1]['ts'] ?? 0 ) ? [ $at, $next ] : $latest;
			$prev    = $next;
		}
		foreach ( \array_reverse( $open ) as $closed ) {
			$worst = self::widest( $worst, $closed );
		}
		if ( null === $worst ) {
			return null;
		}
		$inside = $worst['inside'] ?? null;
		$edge   = self::engine_edge( $worst['after'], $worst['before'] );
		return [
			'kind'     => 'entry_gap',
			'severity' => 'medium',
			'title'    => \sprintf(
				'%s passed between "%s" and "%s"%s with nothing logged',
				self::ms( $worst['gap_ms'] ),
				$worst['after'],
				$worst['before'],
				null === $inside ? '' : " inside {$inside}"
			),
			'detail'   => self::gap_detail( $worst, $edge ),
			'measured' => 'entry timestamps',
			'metric'   => $worst,
			'rule_id'  => $rule_id,
			'proposal' => match ( true ) {
				null !== $edge                       => self::proposal( 'none', $rule_id, "The window is the nested engine's {$edge}, which no rule edit reaches." ),
				self::proposes( $cold )              => self::proposal( 'none', $rule_id, 'The lifecycle bracket proposed for this rule splits the request first.' ),
				null === $inside                     => self::proposal( 'none', $rule_id, 'Only the request\'s own frame is open there, so no hook the rule could add is known to run in the window.' ),
				self::proposes( $dominant, $inside ) => self::proposal( 'none', $rule_id, "The dominant span is {$inside}, and its finding proposes the edit that shows inside it." ),
				default                              => self::visibility_proposal( $inside, $rule, 'Unmark it once the gap is explained; per-callback profiling is the expensive kind.' ),
			},
		];
	}

	/**
	 * Whether a finding proposes a rule edit, for this span when one is named.
	 *
	 * @param array<string,mixed>|null $finding The finding, when there is one.
	 * @param string|null              $base    The span's base name, or null for any.
	 * @return bool
	 */
	private static function proposes( ?array $finding, ?string $base = null ): bool {
		return null !== $finding
			&& 'none' !== ( Core::arr( $finding['proposal'] ?? null )['action'] ?? 'none' )
			&& ( null === $base || Flame_Tree::base_name( Core::as_string( Core::arr( $finding['metric'] ?? null )['name'] ?? '' ) ) === $base );
	}

	/**
	 * What a gap's numbers say, about this process alone: getrusage() counts
	 * no child, so a child's CPU reads as waiting. A window at the nested
	 * engine's edge says which edge, and that the PHP around the spawn shares
	 * it, so none of it is pinned on the engine alone.
	 *
	 * @param Gap_Window             $window The gap, CPU bounds included where known.
	 * @param 'start-up'|'exit'|null $edge   The nested engine's edge the gap sits at.
	 * @return string
	 */
	private static function gap_detail( array $window, ?string $edge ): string {
		$said = match ( $edge ) {
			'start-up' => 'The window ends where the nested engine opened its own log, so it holds the engine\'s start-up and whatever this process ran before spawning it.',
			'exit'     => 'The window opens where the nested engine closed its own log, so it holds the engine\'s exit and whatever this process ran after it returned.',
			default    => 'Nothing instrumented ran in that window.',
		};
		if ( ! isset( $window['on_cpu_min_ms'], $window['on_cpu_max_ms'] ) ) {
			return null === $edge ? "{$said} An outbound call, a subprocess or a slow query fits it." : $said;
		}
		$least_off = $window['gap_ms'] - $window['on_cpu_max_ms'];
		$bounds    = \array_filter(
			[
				$window['on_cpu_min_ms'] > 0.0 ? 'was on CPU for at least ' . self::ms( $window['on_cpu_min_ms'] ) . ' of it' : '',
				$least_off > 0.0 ? 'was not on CPU for at least ' . self::ms( $least_off ) . ' of it' : '',
			],
			static fn ( string $bound ): bool => '' !== $bound
		);
		if ( [] !== $bounds ) {
			$said .= ' By its resources samples, this process ' . \implode( ' and ', $bounds ) . '.';
		}
		return $least_off > 0.0
			? "{$said} A query, a call or a child process fits the waiting, and a child's own CPU counts as waiting here."
			: $said;
	}

	/**
	 * The wider of the widest gap so far and a closed span's own, which
	 * counts only where the span held spans and is no transport.
	 *
	 * @param Gap_Window|null $worst The widest gap so far.
	 * @param Open_Span       $frame The span that closed.
	 * @return Gap_Window|null
	 */
	private static function widest( ?array $worst, array $frame ): ?array {
		[ $base, , $holds_spans, $window ] = $frame;
		return null !== $window && $holds_spans && ! Flame_Tree::is_transport_span( $base )
			&& ( null === $worst || $window['gap_ms'] > $worst['gap_ms'] ) ? $window : $worst;
	}

	/**
	 * How much of a gap the process spent on CPU, bounded by the CPU time its
	 * two `resources` samples bracket and assuming nothing about where inside
	 * the bracket it fell: at least what the rest of the bracket cannot hold,
	 * at most the gap itself. Nothing for a gap the samples do not bracket.
	 *
	 * @param array{from:float,to:float,cpu_ms:float}|null $cpu    Per `Log_Manager::cpu_between_samples()`.
	 * @param float                                        $from   The gap's opening stamp.
	 * @param float                                        $to     Its closing stamp.
	 * @param float                                        $gap_ms Its width.
	 * @return array{on_cpu_min_ms?:float,on_cpu_max_ms?:float}
	 */
	private static function on_cpu( ?array $cpu, float $from, float $to, float $gap_ms ): array {
		if ( null === $cpu || $from < $cpu['from'] || $to > $cpu['to'] ) {
			return [];
		}
		$bracket_ms = ( $cpu['to'] - $cpu['from'] ) * 1000.0;
		return [
			'on_cpu_min_ms' => \max( 0.0, $cpu['cpu_ms'] - ( $bracket_ms - $gap_ms ) ),
			'on_cpu_max_ms' => \min( $cpu['cpu_ms'], $gap_ms ),
		];
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
	 * What repeated is ranked per name, as `repeats_in()` reads a profile. A
	 * name repeats when `REPETITION_COUNT` calls hold `REPETITION_SHARE` of
	 * the request, or when more than one call holds `HEAVY_REPEAT_SHARE` of
	 * it; a name the dominant span already names is that finding's to tell.
	 *
	 * @param array<array-key,mixed>   $record   The request record.
	 * @param Rule|null                $rule     The governing rule, or null when none does.
	 * @param float                    $duration Request duration in milliseconds.
	 * @param array<string,mixed>|null $dominant The dominant-span finding, when there is one.
	 * @return array<string,mixed>|null The finding, or null when no span repeats enough.
	 */
	private static function repetition( array $record, ?Rule $rule, float $duration, ?array $dominant ): ?array {
		$profiles = \is_array( $record['profiles'] ?? null ) ? $record['profiles'] : [];
		$metric   = Core::arr( $dominant['metric'] ?? null );
		$named    = [ $metric['name'] ?? null, Core::arr( $metric['repeat'] ?? null )['name'] ?? null ];
		$worst    = null;
		foreach ( $profiles as $state => $profile ) {
			if ( ! \is_array( $profile ) ) {
				continue;
			}
			foreach ( self::repeats_in( Core::as_string( $state, 'unknown' ), $profile ) as [ $name, $self, $count ] ) {
				$share   = $self / $duration;
				$repeats = ( $count >= self::REPETITION_COUNT && $share >= self::REPETITION_SHARE )
					|| ( $count > 1 && $share >= self::HEAVY_REPEAT_SHARE );
				if ( ! $repeats || \in_array( $name, $named, true ) || ( null !== $worst && $self <= $worst['self_ms'] ) ) {
					continue;
				}
				$worst = [
					'name'    => $name,
					'count'   => $count,
					'self_ms' => $self,
				];
			}
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
	 * Each thing one profile counts, as `[ name, exclusive ms, calls ]` under
	 * the name the flame gives it.
	 *
	 * A profile is keyed by state and tallies each `l` label in `entries`;
	 * `LABEL_NAMES` says whether a label is the work that repeats. Where it
	 * is, each label is its own `<state>: <label>`, and the calls no label
	 * holds — unlabelled, or past the builder's label cap — stay under the
	 * bare state at the profile's totals less the labels', so none drop out.
	 * Where it is not, the state is what repeated.
	 *
	 * @param string                 $state   The profile's key.
	 * @param array<array-key,mixed> $profile `{ entries, count, time }`, per `Request_Builder_Node::push_stack()`.
	 * @return list<array{0:string,1:float,2:int}>
	 */
	private static function repeats_in( string $state, array $profile ): array {
		$time  = Core::num_float( $profile['time'] ?? 0 );
		$count = Core::num_int( $profile['count'] ?? 0 );
		if ( 'work' !== self::LABEL_NAMES[ self::span_kind( $state ) ] ) {
			return [ [ $state, $time, $count ] ];
		}
		$out = [];
		foreach ( Core::arr( $profile['entries'] ?? null ) as $label => $seen ) {
			$seen   = Core::arr( $seen );
			$ms     = Core::num_float( $seen[0] ?? 0 );
			$calls  = Core::num_int( $seen[1] ?? 0 );
			$out[]  = [ Flame_Tree::node_name( $state, (string) $label ), $ms, $calls ];
			$time  -= $ms;
			$count -= $calls;
		}
		if ( $count > 0 ) {
			$out[] = [ $state, $time, $count ];
		}
		return $out;
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
	 * @return array<string,mixed>|null The finding, or null when the loads, less any dominant one, hold under `PLUGIN_LOAD_SHARE`.
	 */
	private static function plugin_load( array $nodes, ?string $rule_id, float $duration ): ?array {
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
		if ( [] !== $loads && $loads[0]['ms'] / $duration >= self::DOMINANT_SHARE ) {
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
			'proposal' => self::proposal( 'none', $rule_id, 'A plugin file\'s load is timed by the profiler before any rule applies; no rule edit changes it.' ),
		];
	}

	/**
	 * The record names a rule this ruleset does not hold. The site ran a
	 * ruleset this hub never pushed, or the rule's pattern changed since —
	 * the id is the pattern's hash, so an edit re-mints it — or the rule was
	 * deleted; nothing on the record tells the three apart. Like the fatal it
	 * carries no proposal: no edit here reaches the rule that governed it.
	 *
	 * @param string $stamped The rule id the record carries.
	 * @return array<string,mixed>
	 */
	private static function unresolved_rule( string $stamped ): array {
		return [
			'kind'     => 'unresolved_rule',
			'severity' => 'high',
			'title'    => "Rule {$stamped} governed this request, and this ruleset does not hold it",
			'detail'   => 'The site ran a ruleset this hub never pushed, or the rule\'s pattern changed or the rule was deleted after the request was logged. No edit here reaches the rule that governed it.',
			'measured' => 'record',
			'metric'   => [ 'rule_id' => $stamped ],
			'rule_id'  => $stamped,
		];
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
	 * One span holding most of the request. The DEEPEST qualifying node
	 * wins: it is the most specific thing that still dominates, and therefore
	 * the one worth being able to see inside.
	 *
	 * The finding also names the REPEAT when one exists — the outermost span on
	 * the way up that both dominates and ran more than once. A query holding
	 * 80% of a request because the content around it rendered ten times is ten
	 * renders to explain, not one slow query, and the leaf alone never says so.
	 *
	 * @param list<Flame_Entry> $nodes      Flattened flame nodes.
	 * @param Rule|null         $rule       The governing rule, or null when none does.
	 * @param float             $duration   Request duration in milliseconds.
	 * @param bool              $rule_known Whether `$rule` is the record's resolution; false when the record's stamp did not resolve, and what the rule logged inside the span is not known here.
	 * @param list<mixed>       $entries    The record's entries, which time the nested engine's edges.
	 * @return array<string,mixed>|null The finding, or null when no span holds `DOMINANT_SHARE`.
	 */
	private static function dominant_span( array $nodes, ?Rule $rule, float $duration, bool $rule_known, array $entries ): ?array {
		$best_index = null;
		foreach ( $nodes as $index => $node ) {
			if ( $node['value'] / $duration < self::DOMINANT_SHARE || self::is_request_frame( $node ) ) {
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
		$share      = $best['value'] / $duration;
		$repeat     = self::repeat_of( $nodes, $best_index, $duration );
		$metric     = [
			'name'       => $best['name'],
			'ms'         => $best['value'],
			'share'      => $share,
			'self_ms'    => $best['self_ms'],
			'self_share' => $best['self_ms'] / $duration,
			'path'       => self::node_path( $nodes, $best_index ),
		];
		if ( isset( $best['i'] ) ) {
			$metric['i'] = $best['i'];
		}
		if ( null !== $repeat ) {
			$metric['repeat'] = $repeat;
		}
		$contained   = $best['value'] - $best['self_ms'];
		$child       = self::explaining_child( $nodes, $best_index, $contained );
		$child_share = null === $child ? 0.0 : $child['value'] / $duration;
		if ( null !== $child ) {
			$metric['child'] = [
				'name'  => $child['name'],
				'ms'    => $child['value'],
				'share' => $child_share,
			];
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
						self::spent_detail( $best, $duration ),
						match ( true ) {
							null !== $child => \sprintf(
								'%s holds %d%% of the request, the most of anything inside it.',
								$child['name'],
								(int) \round( 100 * $child_share )
							),
							$rule_known     => self::span_advice( Flame_Tree::base_name( $best['name'] ), $rule )['detail'],
							default         => '',
						},
					],
					static fn ( string $sentence ): bool => '' !== $sentence
				)
			),
			'measured' => 'flame',
			'metric'   => $metric,
			'rule_id'  => $rule?->id,
			'proposal' => match ( true ) {
				null !== $child => self::proposal( 'none', $rule?->id, "Its interior is already in this record, and {$child['name']} holds the most of it; read that before changing the rule." ),
				self::own_time_is_engine( $best['self_ms'], $metric['path'], $entries ) => self::proposal( 'none', $rule?->id, 'Its own time is the nested engine\'s start-up and exit, which no rule edit reaches; marking it significant re-times the one listener that spawned the engine.' ),
				default         => self::visibility_proposal(
					$best['name'],
					$rule,
					'Unmark it once the responsible listener is known; per-callback profiling is the expensive kind.'
				),
			},
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
		// The bare hook, which is what a rule binds and what every row names.
		$named = Flame_Tree::hook_name( $base );
		// A transport row binds the flag that logs the span, not the span.
		$flag = 'transport' === ( $advice['field'] ?? '' ) ? Rule::transport_flag( $base ) : '';
		$why  = \sprintf( $advice['why'], $named, Hooks::TRANSPORT_HOOKS[ $base ] ?? '', $flag );
		if ( ! isset( $advice['field'], $advice['action'] ) ) {
			return self::proposal( 'none', $rule?->id, $why );
		}
		return self::proposal(
			$advice['action'],
			$rule?->id,
			$why,
			$advice['undo'] ?? $undo,
			[
				'field' => '' === $flag ? $advice['field'] : $flag,
				'value' => '' === $flag ? $named : $flag,
			]
		);
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
		if ( 'transport' === $kind && null !== $rule && ! $rule->logs_transport( $base ) ) {
			return self::SPAN_ADVICE['transport:unlogged'];
		}
		$marked = isset( self::SPAN_ADVICE[ $row ] ) && null !== $rule && $rule->marks_significant( Flame_Tree::hook_name( $base ) );
		return self::SPAN_ADVICE[ $marked ? $row : $kind ];
	}

	/**
	 * The eight kinds of span the flame carries, classified once so every
	 * caller reaches the same `SPAN_ADVICE` row. A custom event has no
	 * listeners, and prose crediting it with any sends the reader hunting a
	 * callback that does not exist. A query or HTTP span is the logger's own,
	 * a plugin span is one plugin file's load, a command span is the frame of
	 * one verb, a URL-read span is one step inside such a verb, and an upkeep
	 * span is a builder's own work; the last three are logged whatever
	 * the rule says, and calling any of these a custom event proposes a rule
	 * edit that changes nothing. A hook is known by its BASE name: with hook
	 * tracing on, the frame carries the caller too.
	 *
	 * @param string $base The span's base name, per `Flame_Tree::base_name()`.
	 * @return 'transport'|'plugin'|'command'|'url_read'|'upkeep'|'hook'|'listener'|'custom'
	 */
	private static function span_kind( string $base ): string {
		if ( Flame_Tree::is_transport_span( $base ) ) {
			return 'transport';
		}
		if ( Flame_Tree::is_plugin_load_span( $base ) ) {
			return 'plugin';
		}
		if ( Flame_Tree::is_command_span( $base ) ) {
			return 'command';
		}
		$platform = Flame_Tree::platform_span_kind( $base );
		if ( null !== $platform ) {
			return $platform;
		}
		if ( Flame_Tree::is_hook_span( $base ) ) {
			return 'hook';
		}
		return Flame_Tree::is_listener_span( $base ) ? 'listener' : 'custom';
	}

	/**
	 * Whether a span's own time is the nested engine's start-up and exit: an
	 * engine ran directly inside it, and what its body spends beyond those
	 * edges is short of `GAP_MS`, too little to be a finding of its own.
	 *
	 * @param float       $self_ms Its own body's time.
	 * @param string      $path    Its path, per `node_path()`.
	 * @param list<mixed> $entries The record's entries.
	 * @return bool
	 */
	private static function own_time_is_engine( float $self_ms, string $path, array $entries ): bool {
		$edges_ms = null;
		$open     = [];
		$prev     = null;
		$spliced  = false;
		foreach ( $entries as $entry ) {
			$next = Core::arr( $entry );
			if ( null !== $prev && null !== self::engine_edge( Core::as_string( $prev['k'] ?? '' ), Core::as_string( $next['k'] ?? '' ) )
					&& self::adjacent( $prev, $next, $spliced ) && self::span_path( $open ) === $path ) {
				$edges_ms = ( $edges_ms ?? 0.0 ) + ( Core::num_float( $next['ts'] ?? 0 ) - Core::num_float( $prev['ts'] ?? 0 ) ) * 1000.0;
			}
			self::track( $open, $next );
			$spliced = $spliced || Request_Builder_Node::FOLD_MARKER_KEY === ( $next['k'] ?? null );
			$prev    = $next;
		}
		return null !== $edges_ms && $self_ms - $edges_ms < self::GAP_MS;
	}

	/**
	 * Whether two consecutive rows were consecutive when logged. A marker
	 * stands in for the window beside it, which `truncation()` reports, and
	 * past the fold marker only rows whose `n` steps by one were neighbours.
	 *
	 * @param array<array-key,mixed> $prev    The earlier row.
	 * @param array<array-key,mixed> $next    The later row.
	 * @param bool                   $spliced Whether the fold marker came before them.
	 * @return bool
	 */
	private static function adjacent( array $prev, array $next, bool $spliced ): bool {
		foreach ( [ $prev, $next ] as $row ) {
			if ( \in_array( Core::as_string( $row['k'] ?? '' ), Request_Builder_Node::SEQUENCE_BREAK_KEYS, true ) ) {
				return false;
			}
		}
		return ! $spliced || Core::num_int( $next['n'] ?? 0 ) === Core::num_int( $prev['n'] ?? 0 ) + 1;
	}

	/**
	 * Which edge of the nested engine's render a window between two rows is.
	 *
	 * @param string $after  The keyword of the row the window opens after.
	 * @param string $before The keyword of the row it closes before.
	 * @return 'start-up'|'exit'|null
	 */
	private static function engine_edge( string $after, string $before ): ?string {
		return match ( true ) {
			Request_Builder_Node::NESTED_START === $before   => 'start-up',
			Request_Builder_Node::NESTED_COMPLETE === $after => 'exit',
			default                                          => null,
		};
	}

	/**
	 * How much of the request a span actually SPENDS, said only where it has
	 * children to hide behind and its body is not all of it. A wrapper reads
	 * as 100% and sends a reader inside the one span guaranteed to contain
	 * everything — a `pyrobase` span holds 100% of the request and spends
	 * 9.5% of it in its own body.
	 *
	 * @param Flame_Entry $node     The dominant node.
	 * @param float       $duration Request duration in milliseconds.
	 * @return string A leading sentence, or ''.
	 */
	private static function spent_detail( array $node, float $duration ): string {
		if ( $node['value'] <= $node['self_ms'] || 100 === (int) \round( 100 * $node['self_ms'] / $node['value'] ) ) {
			return '';
		}
		return \sprintf(
			'It spends %d%% of the request in its own body; the rest is inside what it contains.',
			(int) \round( 100 * $node['self_ms'] / $duration )
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
	 * @param float                                                      $share  Its share of the request.
	 * @param array{name:string,count:int,ms:float,each_ms:float,own:bool}|null $repeat What `repeat_of()` found.
	 * @return string
	 */
	private static function dominant_title( string $name, float $share, ?array $repeat ): string {
		$title = \sprintf( '%s holds %d%% of the request', $name, (int) \round( $share * 100 ) );
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
	 * The heaviest child of a node whose children explain it — they hold at
	 * least `EXPLAINED_SHARE` of its time — or null when its own body holds
	 * more, and what it does there is what the reader cannot yet see.
	 *
	 * @param list<Flame_Entry> $nodes     Flattened flame nodes.
	 * @param int               $index     The node's index.
	 * @param float             $contained What its children hold, in milliseconds.
	 * @return Flame_Entry|null
	 */
	private static function explaining_child( array $nodes, int $index, float $contained ): ?array {
		if ( $contained < $nodes[ $index ]['value'] * self::EXPLAINED_SHARE ) {
			return null;
		}
		$heaviest = null;
		foreach ( $nodes as $child ) {
			if ( $index === $child['parent'] && ( null === $heaviest || $child['value'] > $heaviest['value'] ) ) {
				$heaviest = $child;
			}
		}
		return $heaviest;
	}

	/**
	 * A flattened node's path: its name and its ancestors', outermost first,
	 * spelled as `span_path()` spells the spans open at an entry.
	 *
	 * @param list<Flame_Entry> $nodes Flattened flame nodes.
	 * @param int               $index The node's index.
	 * @return string
	 */
	private static function node_path( array $nodes, int $index ): string {
		$names = [];
		for ( $at = $index; null !== $at; $at = $nodes[ $at ]['parent'] ) {
			if ( ! self::is_request_frame( $nodes[ $at ] ) ) {
				\array_unshift( $names, $nodes[ $at ]['name'] );
			}
		}
		return \implode( self::PATH_SEPARATOR, $names );
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
	 * The outermost span on the way up from `$index` — itself included — that
	 * dominates the request and ran more than once, or null when every
	 * dominating span ran once. A run whose slowest call alone holds
	 * `DOMINANT_SHARE` is one slow call beside quick ones, not a repeat.
	 * `own` says whether that span IS the dominant one, decided by index: a
	 * span nested in a same-name ancestor is not it.
	 *
	 * @param list<Flame_Entry> $nodes    Flattened flame nodes.
	 * @param int               $index    The dominant node's index.
	 * @param float             $duration Request duration in milliseconds.
	 * @return array{name:string,count:int,ms:float,each_ms:float,own:bool}|null
	 */
	private static function repeat_of( array $nodes, int $index, float $duration ): ?array {
		$repeat = null;
		for ( $at = $index; null !== $at; $at = $nodes[ $at ]['parent'] ) {
			$node = $nodes[ $at ];
			if ( $node['count'] > 1 && $node['value'] / $duration >= self::DOMINANT_SHARE && $node['max'] / $duration < self::DOMINANT_SHARE ) {
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
	 * Insufficient instrumentation, if it applies: no rule governs the URL, the
	 * governing rule registers no hooks, or the record holds no span at all.
	 *
	 * @param array<array-key,mixed> $record   The request record.
	 * @param Rule|null              $rule     The governing rule, or null when none does.
	 * @param list<Flame_Entry>      $nodes    Flattened flame nodes.
	 * @param float|null             $duration Request duration in milliseconds, or null where `stopped()` reports where it ends.
	 * @return array<string,mixed>|null The finding, or null when the rule and the record between them measure enough.
	 */
	private static function cold_start( array $record, ?Rule $rule, array $nodes, ?float $duration ): ?array {
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
			( null === $duration ? [] : [ 'duration_ms' => $duration ] ) + [
				'spans' => \count( $nodes ),
				'hooks' => \count( $hooks ),
			],
			'rule + record',
			[] === $hooks
		);
	}

	/**
	 * The request stopped logging before it ended: timed out, or stopped by
	 * its worker. The finding is its last stored entry, the spans open there
	 * and the last line the builder saw, each at its own time, since a
	 * runaway's builder sees lines it no longer stores. It names no cause and
	 * proposes nothing, and with nothing past the opening rows, no stop.
	 *
	 * @param array<array-key,mixed> $record  The request record.
	 * @param list<mixed>            $entries The record's entries.
	 * @param Rule|null              $rule    The governing rule, or null when none resolves.
	 * @return array<string,mixed>|null The finding, or null when the request ran to its end.
	 * @throws \UnexpectedValueException When the record carries no `last_log_ts` or `timestamp`.
	 */
	private static function stopped( array $record, array $entries, ?Rule $rule ): ?array {
		$status = $record['error_status'] ?? '-';
		if ( ! \in_array( $status, Flame_Builder_Node::UNTIMED_STATUSES, true ) ) {
			return null;
		}
		if ( ! \is_numeric( $record['last_log_ts'] ?? null ) || ! \is_numeric( $record['timestamp'] ?? null ) ) {
			throw new \UnexpectedValueException( "Findings: a {$status} record carries no last_log_ts or timestamp to place its stop" );
		}
		$origin                 = (float) $record['timestamp'];
		[ $end, $known, $open ] = self::stop_point( $entries );
		$stop                   = null === $end ? [] : Core::arr( $entries[ $end ] );
		$last_ms                = ( Core::num_float( $stop['ts'] ?? 0 ) - $origin ) * 1000.0;
		$last                   = Core::as_string( $stop['k'] ?? '' );
		$duration               = Core::num_float( $record['duration_ms'] ?? 0 );
		[ $cause, $ending ]     = 'T' === $status
			? [ \sprintf( 'The builder timed it out at %s. A killed or hung process and a lost log tail look the same from here.', self::ms( $duration ) ), [ 'evicted_after_ms' => $duration ] ]
			: [ 'Its worker stopped it before its work finished, and the spans open then were closed for it.', [ 'aborted_after_ms' => $duration ] ];
		return [
			'kind'     => 'stopped',
			'severity' => 'high',
			'title'    => $known
				? \sprintf( 'The request stopped logging %s in, after "%s"', self::ms( $last_ms ), $last )
				: 'The request stopped logging, and where it stopped is unknown',
			'detail'   => ( $known ? ( '' === $open ? '' : "Still open: {$open}. " ) : 'Nothing past the request\'s opening rows was logged, so the silence after them places no stop. ' ) . $cause,
			'measured' => 'entry timestamps',
			'metric'   => ( [] === $stop ? [] : [ 'last_entry_ms' => $last_ms ] )
				+ ( $known ? [ 'last_entry' => $last ] : [] )
				+ ( $known && '' !== $open ? [ 'open' => $open ] : [] )
				+ [ 'last_line_ms' => ( (float) $record['last_log_ts'] - $origin ) * 1000.0 ]
				+ $ending,
			'rule_id'  => $rule?->id,
		];
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
	 * Where a record's logging stopped: its last entry ahead of the drain a
	 * producer writes on its way out, and stamped no earlier than the
	 * `request` line, as the profiler's later-written plugin rows are not.
	 *
	 * @param list<mixed> $entries The record's entries.
	 * @return array{0:?int,1:bool,2:string} Stop index (null when empty), whether it places a stop, the open spans.
	 */
	private static function stop_point( array $entries ): array {
		$opener = -1;
		$floor  = -\INF;
		foreach ( $entries as $at => $entry ) {
			$entry = Core::arr( $entry );
			if ( Log_Manager::REQUEST_LINE === ( $entry['k'] ?? null ) ) {
				$floor = Core::num_float( $entry['ts'] ?? 0 );
			}
			if ( Log_Manager::RESOURCES === ( $entry['k'] ?? null ) ) {
				$opener = $at;
				break;
			}
		}
		$end = \array_key_last( $entries );
		while ( null !== $end && $end > $opener
				&& ( self::is_drain( Core::arr( $entries[ $end ] ) ) || Core::num_float( Core::arr( $entries[ $end ] )['ts'] ?? 0 ) < $floor ) ) {
			$end = 0 === $end ? null : $end - 1;
		}
		$open = [];
		for ( $at = 0; null !== $end && $at <= $end; $at++ ) {
			self::track( $open, Core::arr( $entries[ $at ] ) );
		}
		return [ $end, null !== $end && $end > $opener, self::span_path( $open ) ];
	}

	/**
	 * Open spans as a path: their flame names, outermost first, joined by
	 * `PATH_SEPARATOR`, the request's own frame left out.
	 *
	 * @param list<Open_Span> $open The open spans, outermost first.
	 * @return string The path, or '' when only the request's frame is open.
	 */
	private static function span_path( array $open ): string {
		$names = [];
		foreach ( $open as $frame ) {
			if ( Log_Manager::REQUEST_LABEL !== $frame[0] ) {
				$names[] = $frame[1];
			}
		}
		return \implode( self::PATH_SEPARATOR, $names );
	}

	/**
	 * Apply one entry to the open spans, LIFO as `Log_Manager::complete()`
	 * matches: a start opens a span and marks its parent as holding one, and
	 * a complete closes the nearest span of its name and every span still
	 * open inside it.
	 *
	 * @param list<Open_Span>        $open  Base name, flame name, whether it holds a span, its widest gap.
	 * @param array<array-key,mixed> $entry One entry.
	 * @return list<Open_Span> The spans it closed, innermost first.
	 */
	private static function track( array &$open, array $entry ): array {
		$keyword = Core::as_string( $entry['k'] ?? '' );
		if ( 1 === \preg_match( Flame_Tree::PATTERN_START, $keyword, $m ) ) {
			$top = \array_key_last( $open );
			if ( null !== $top ) {
				$open[ $top ][2] = true;
			}
			$open[] = [ $m[1], Flame_Tree::node_name( $m[1], Core::as_string( $entry['l'] ?? '' ) ), false, null ];
			return [];
		}
		if ( 1 === \preg_match( Flame_Tree::PATTERN_COMPLETE, $keyword, $m ) ) {
			for ( $at = \count( $open ) - 1; $at >= 0; $at-- ) {
				if ( $open[ $at ][0] === $m[1] ) {
					return \array_reverse( \array_splice( $open, $at ) );
				}
			}
		}
		return [];
	}

	/**
	 * Whether an entry is part of the drain a producer writes on its way out.
	 *
	 * @param array<array-key,mixed> $entry One entry.
	 * @return bool
	 */
	private static function is_drain( array $entry ): bool {
		$keyword = Core::as_string( $entry['k'] ?? '' );
		return isset( Request_Builder_Node::TERMINAL_KEYWORDS[ $keyword ] )
			|| Log_Manager::MEMORY === $keyword
			|| Log_Manager::RESOURCES === $keyword
			|| ( Log_Manager::ORPHANED === ( $entry['m'] ?? null ) && 1 === \preg_match( Flame_Tree::PATTERN_COMPLETE, $keyword ) );
	}

	/**
	 * The rule id the site stamped on a record, or '' when it carries none.
	 * The stamp is what the site's own ruleset answered; resolving it in THIS
	 * ruleset is the caller's step, and a miss is a finding in its own right.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 */
	public static function rule_stamp( array $record ): string {
		return Core::as_string( $record['rule_id'] ?? '' );
	}

	/**
	 * The statement tables an UNFOLDED record would have had, keyed by the
	 * name path of the node each belongs to.
	 *
	 * A folded record's nodes carry their own; an unfolded one's flame keeps
	 * each span as its own node, and its statements live in the entries — which
	 * a brief ships sixty of, so the finding is the only place the answer can
	 * reach the reader. `entry_fold()` runs the one set of rules `Flame_Fold`
	 * already holds — which half names the statement, the caps, the bucket —
	 * rather than a second copy of them.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @return array<string,array<array-key,mixed>> Path, names joined by U+001F, to its table.
	 */
	private static function entry_shapes( array $record ): array {
		if ( [] !== Core::arr( $record['flame'] ?? null ) ) {
			return [];
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
		$walk( self::entry_fold( $record ), '' );
		return $out;
	}

	/**
	 * Flatten a flame tree into a list of `{name, value, self_ms, max, depth,
	 * count, parent}`, root excluded — the root IS the request, so it can never be the
	 * span holding most of the request. `parent` is the index of the entry this
	 * one sits inside, null at the top level.
	 *
	 * Spans sharing a name under the same parent are ONE entry, summed, at every
	 * depth. A stored per-request tree keeps each firing as its own sibling, so
	 * ten renders of the same content are ten nodes none of which holds much;
	 * grouped, they are one repeat that dominates, which is what a folded tree
	 * already says with its `count`. A folded node's `count` is carried; an
	 * unfolded node counts once. `i` is the earliest position a member names
	 * of the entry that opened it, where the tree carries one. `max` is the
	 * group's slowest call: a folded
	 * node's own `max`, an unfolded node's value, and an older folded node
	 * carrying no `max` its value, which can only withhold a repeat.
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
			$value  = 0.0;
			$self   = 0.0;
			$max    = 0.0;
			$count  = 0;
			$first  = null;
			$shapes = [];
			foreach ( $group['members'] as $member ) {
				if ( \is_int( $member['i'] ?? null ) ) {
					$first = \min( $first ?? $member['i'], $member['i'] );
				}
				$member_value = Core::num_float( $member['value'] ?? 0 );
				$value       += $member_value;
				$self        += $member_value - \array_sum( \array_map( static fn ( mixed $child ): float => Core::num_float( Core::arr( $child )['value'] ?? 0 ), Core::arr( $member['children'] ?? null ) ) );
				$max          = \max( $max, Core::num_float( $member['max'] ?? $member_value ) );
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
				'max'     => $max,
				'depth'   => $depth + 1,
				'count'   => $count,
				'parent'  => $parent,
			];
			if ( null !== $first ) {
				$entry['i'] = $first;
			}
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
	 * A record's flame tree, under whichever key it arrived by.
	 *
	 * A LOADED record carries it at `flame_data` — `Performance_CI` merges the
	 * flames partition in under that name — while only a FOLDED record ever
	 * carries `flame`, which `Request_Builder_Node` writes as part of the fold.
	 * Reading one key alone makes every ordinary request look wholly unmeasured.
	 * A record carrying neither is folded from its entries, so its spans still
	 * reach the findings and the briefs; a `flame_data` already resolved, even
	 * to no tree, is not folded again.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @return array<array-key,mixed> The flame tree root, or [] when the record holds no span.
	 */
	public static function flame_of( array $record ): array {
		if ( \is_array( $record['flame_data'] ?? null ) ) {
			return $record['flame_data'];
		}
		if ( [] !== Core::arr( $record['flame'] ?? null ) ) {
			return Core::arr( $record['flame'] );
		}
		$tree = self::entry_fold( $record );
		return [] === Core::arr( $tree['children'] ?? null ) ? [] : $tree;
	}

	/**
	 * A record's entries folded into a tree, transiently, and stored nowhere.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @return array<string,mixed> The tree `Flame_Fold::tree()` builds, `folded` set.
	 */
	private static function entry_fold( array $record ): array {
		// Built, read and dropped: no in-flight pool to hold a budget against.
		$state = Flame_Fold::start( null, \PHP_INT_MAX );
		foreach ( Core::arr( $record['entries'] ?? null ) as $entry ) {
			if ( \is_array( $entry ) ) {
				Flame_Fold::add( $state, $entry );
			}
		}
		return Flame_Fold::tree( $state );
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
			'avg_ms'      => Stats_Store::measured_mean( $stats['avg_ms'] ?? null ),
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
	 * @param string                 $url      The URL, whose path a `create_rule` proposal takes as its pattern.
	 * @param Rule|null              $rule     The governing rule, or null when none does.
	 * @param array<array-key,mixed> $metric   The numbers that ARE known.
	 * @param string                 $measured Where they came from.
	 * @param bool                   $hookless Whether the rule registers no hooks.
	 * @return array<string,mixed>
	 */
	private static function insufficient( string $url, ?Rule $rule, array $metric, string $measured, bool $hookless = true ): array {
		[ $title, $proposal ] = match ( true ) {
			null === $rule => [
				'No rule governs this URL, so nothing is measured',
				self::proposal(
					'create_rule',
					null,
					'No rule governs this URL, so nothing about it is logged at all.',
					'Delete the rule, or set its action to skip, once the question is answered.',
					[ 'pattern' => Stats_Store::path_of( $url ), 'hooks' => self::LIFECYCLE_BRACKET ]
				),
			],
			$hookless      => [
				'The governing rule registers no hooks, so nothing inside the request is measured',
				self::proposal(
					'add_hooks',
					$rule->id,
					'The governing rule registers no hooks, so the request has no interior. '
						. 'These six split it into phases at a fixed, small cost; the next round subdivides only the phase that held the time.',
					'Trim the hook list back to the phase that mattered once it is identified — '
						. 'every enabled hook costs overhead on every request this rule matches.',
					[ 'pattern' => $rule->pattern, 'hooks' => self::LIFECYCLE_BRACKET ]
				),
			],
			default        => [
				'None of the rule\'s hooks ran in this request',
				self::proposal(
					'none',
					$rule->id,
					'The rule registers hooks, but none of them ran in this request — it ended before they fired, or nothing matched.',
					'',
					[ 'pattern' => $rule->pattern ]
				),
			],
		};
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
	 * A rule edit, its direction read off `DIRECTION`. Action `none` says why
	 * no edit is the step.
	 *
	 * @param string              $action  What the edit does.
	 * @param string|null         $rule_id The rule it lands on, or null when none governs.
	 * @param string              $why     Why it acts on the finding, or why nothing does.
	 * @param string              $undo    How to take it back.
	 * @param array<string,mixed> $extra   What the action needs: a pattern, hooks, a field and value.
	 * @return array<string,mixed>
	 * @throws \LogicException For an action `DIRECTION` does not name.
	 */
	private static function proposal( string $action, ?string $rule_id, string $why, string $undo = '', array $extra = [] ): array {
		return [
			'action'    => $action,
			'direction' => self::DIRECTION[ $action ] ?? throw new \LogicException( "Findings: no direction for {$action}" ),
			'rule_id'   => $rule_id,
			'why'       => $why,
			'undo'      => $undo,
		] + $extra;
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

	/**
	 * What we do not measure. This rides in every brief and every tool
	 * description, because a model handed a duration with nothing logged
	 * across it will invent a cause for the silence.
	 *
	 * @return string The caveat, as one paragraph of prose.
	 */
	public static function caveat(): string {
		return 'The logger times ONLY the hooks the URL\'s governing rule names, the custom events '
			. 'the application logs itself, outbound HTTP requests under the rule\'s HTTP logging, '
			. 'and database queries under its query logging — nothing else is instrumented, so an '
			. 'absence here is as often an unbound hook as an idle one. Without HTTP logging it '
			. 'sees no outbound calls, without query logging no SQL, and below PHP userland it '
			. 'sees only those calls and queries. Unlogged time is unmeasured, not idle.';
	}
}
