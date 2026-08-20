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

use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Nodes\Core;
// Both plugins have a `Core`; the hook instrumentation is THIS plugin's.
use Newspack_Event_Logger_Nodes\App\Core as Hooks;

\defined( 'ABSPATH' ) || exit;

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

	/** Unexplained interval between consecutive entries, in milliseconds. */
	public const GAP_MS = 250.0;

	/** The interval one of these covers is missing DETAIL, never idle time. */
	private const FOLD_MARKERS = Request_Builder_Node::SEQUENCE_BREAK_KEYS;

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
	 */
	public const LIFECYCLE_BRACKET = [
		'plugins_loaded',
		'init',
		'wp_loaded',
		'template_redirect',
		'wp_head',
		'shutdown',
	];

	/** Severity ranking, worst first — the order findings come back in. */
	private const SEVERITY_ORDER = [ 'high' => 0, 'medium' => 1, 'info' => 2 ];

	/**
	 * Findings for one completed request record, worst first.
	 *
	 * @param array<array-key,mixed> $record A stored request record (`requests.p*`).
	 * @param Rule|null           $rule   The rule governing this request's URL, or null when none does.
	 * @return list<array<string,mixed>>
	 */
	public static function for_request( array $record, ?Rule $rule = null ): array {
		$duration = Core::num_float( $record['duration_ms'] ?? 0 );
		$flame    = self::flame_of( $record );
		$nodes    = self::flatten( $flame );
		$profiled = self::profiled_ms( $flame, $nodes );
		$rule_id  = null === $rule ? null : $rule->id;

		$findings = [];
		$cold     = self::cold_start( $record, $rule, $nodes, $profiled, $duration );
		if ( null !== $cold ) {
			$findings[] = $cold;
		}
		foreach (
			[
				self::unattributed( $profiled, $duration, $rule_id ),
				self::dominant_span( $nodes, $profiled, $rule, $duration ),
				self::repetition( $nodes, $rule ),
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
	 * @param list<array<string,mixed>> $findings
	 * @return list<array<string,mixed>>
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
	 * @param array<array-key,mixed> $record The request record.
	 * @return array<string,mixed>|null
	 */
	private static function truncation( array $record, ?string $rule_id ): ?array {
		$markers = [];
		foreach ( \is_array( $record['entries'] ?? null ) ? $record['entries'] : [] as $entry ) {
			$key = \is_array( $entry ) ? Core::as_string( $entry['k'] ?? '' ) : '';
			if ( \in_array( $key, self::FOLD_MARKERS, true ) ) {
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
			'detail'   => 'Entries were merged into the flame graph, so absence of evidence is not evidence of absence here.',
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
	 * @param array<array-key,mixed> $record The request record.
	 * @return array<string,mixed>|null
	 */
	private static function entry_gap( array $record, ?string $rule_id ): ?array {
		$entries = \is_array( $record['entries'] ?? null ) ? \array_values( $record['entries'] ) : [];
		$worst   = null;
		for ( $i = 1; $i < \count( $entries ); $i++ ) {
			$prev = \is_array( $entries[ $i - 1 ] ) ? $entries[ $i - 1 ] : [];
			$next = \is_array( $entries[ $i ] ) ? $entries[ $i ] : [];
			$from = Core::as_string( $prev['k'] ?? '' );
			$to   = Core::as_string( $next['k'] ?? '' );
			// The merged entries ARE this window; truncation() reports it.
			if ( \in_array( $from, self::FOLD_MARKERS, true ) || \in_array( $to, self::FOLD_MARKERS, true ) ) {
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
	 * A span fired hundreds of times in one request — the N+1 shape, and the
	 * same family as Query Monitor's duplicate queries and hooks-by-count.
	 * `Flame_Fold` already collapses same-name siblings with a `count`, so this
	 * is a read rather than a scan.
	 *
	 * @param list<array{name:string,value:float,count:int,depth:int}> $nodes Flattened flame nodes.
	 * @return array<string,mixed>|null
	 */
	private static function repetition( array $nodes, ?Rule $rule ): ?array {
		$worst = null;
		foreach ( $nodes as $node ) {
			if ( $node['count'] >= self::REPETITION_COUNT
					&& ( null === $worst || $node['count'] > $worst['count'] ) ) {
				$worst = $node;
			}
		}
		if ( null === $worst ) {
			return null;
		}
		return [
			'kind'     => 'repetition',
			'severity' => 'medium',
			'title'    => \sprintf( '%s fired %d times in one request', $worst['name'], $worst['count'] ),
			'detail'   => 'A count this high usually means the work inside is being repeated per item rather than done once.',
			'measured' => 'flame',
			'metric'   => [
				'name'     => $worst['name'],
				'count'    => $worst['count'],
				'total_ms' => $worst['value'],
				'each_ms'  => $worst['value'] / $worst['count'],
			],
			'rule_id'  => $rule?->id,
			'proposal' => self::visibility_proposal(
				$worst['name'],
				$rule,
				'Unmark it once the caller is identified.'
			),
		];
	}

	/**
	 * One span holding most of the profiled time. The DEEPEST qualifying node
	 * wins: it is the most specific thing that still dominates, and therefore
	 * the one worth being able to see inside.
	 *
	 * @param list<array{name:string,value:float,count:int,depth:int}> $nodes Flattened flame nodes.
	 * @return array<string,mixed>|null
	 */
	private static function dominant_span( array $nodes, float $profiled, ?Rule $rule, float $duration ): ?array {
		if ( $profiled <= 0.0 || $duration < self::MIN_DURATION_MS ) {
			return null;
		}
		$best = null;
		foreach ( $nodes as $node ) {
			if ( $node['value'] / $profiled < self::DOMINANT_SHARE ) {
				continue;
			}
			if ( null === $best
					|| $node['depth'] > $best['depth']
					|| ( $node['depth'] === $best['depth'] && $node['value'] > $best['value'] ) ) {
				$best = $node;
			}
		}
		if ( null === $best ) {
			return null;
		}
		$share   = $best['value'] / $profiled;
		$already = self::is_significant( $best['name'], $rule );
		return [
			'kind'     => 'dominant_span',
			'severity' => 'high',
			'title'    => \sprintf(
				'%s holds %d%% of the profiled time',
				$best['name'],
				(int) \round( $share * 100 )
			),
			'detail'   => self::interior_detail( $best['name'], $already ),
			'measured' => 'flame',
			'metric'   => [
				'name'  => $best['name'],
				'ms'    => $best['value'],
				'share' => $share,
				'count' => $best['count'],
				'depth' => $best['depth'],
			],
			'rule_id'  => $rule?->id,
			'proposal' => self::visibility_proposal(
				$best['name'],
				$rule,
				'Unmark it once the responsible listener is known; per-callback profiling is the expensive kind.'
			),
		];
	}

	/**
	 * What would make this span's interior visible, said accurately for what
	 * KIND of span it is. Only a hook has listeners to log; a custom event's
	 * interior belongs to the application that logged it, and a wrapped
	 * listener is already the finest grain there is.
	 *
	 * @param string    $span The span's name, as the flame carries it.
	 * @param Rule|null $rule The governing rule, or null when none does.
	 * @param string    $undo What removes the instrumentation again.
	 * @return array<string,mixed> The proposal that is true for this kind of span.
	 */
	private static function visibility_proposal( string $span, ?Rule $rule, string $undo ): array {
		$rule_id = $rule?->id;
		// Already marked: its listeners are here, so there is nothing to add.
		if ( self::is_significant( $span, $rule ) ) {
			return [
				'action'    => 'none',
				'direction' => 'none',
				'rule_id'   => $rule_id,
				'why'       => 'It is already a significant event; its listeners are in this record.',
				'undo'      => '',
			];
		}
		if ( self::is_hook( $span ) ) {
			return [
				'action'    => 'mark_significant',
				'direction' => 'more',
				'field'     => 'significant_events',
				'value'     => $span,
				'rule_id'   => $rule_id,
				'why'       => \sprintf(
					'Marking %s a significant event logs its listeners, which is the only way to see which one is holding the time.',
					$span
				),
				'undo'      => $undo,
			];
		}
		if ( self::is_custom_event( $span ) ) {
			return [
				'action'    => 'add_custom_events',
				'direction' => 'more',
				'field'     => 'custom_events',
				'value'     => $span,
				'rule_id'   => $rule_id,
				'why'       => \sprintf(
					'%s is a custom event the application logs itself, not a WordPress hook — significant events reach hooks only, so marking it does nothing. Its interior shows only where the application logs further custom events, enabled on this rule.',
					$span
				),
				'undo'      => 'Disable those custom events again once the interior is understood.',
			];
		}
		return [
			'action'    => 'none',
			'direction' => 'none',
			'rule_id'   => $rule_id,
			'why'       => \sprintf(
				'%s is a listener, logged because its hook is already a significant event — this is the finest grain the logger has, and the answer is inside that callback.',
				$span
			),
			'undo'      => '',
		];
	}

	/**
	 * Whether the rule already marks this span significant. `bind_current_scope()`
	 * accepts an event with or without the ` hook` suffix, so both spellings name
	 * the same hook and a comparison that misses one proposes a no-op edit.
	 */
	private static function is_significant( string $span, ?Rule $rule ): bool {
		if ( null === $rule ) {
			return false;
		}
		$bare = \str_ends_with( $span, Hooks::HOOK_SUFFIX )
			? \substr( $span, 0, -\strlen( Hooks::HOOK_SUFFIX ) )
			: $span;
		foreach ( $rule->significant_events as $event ) {
			if ( $event === $span || $event === $bare ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Why the inside of this span is or is not visible, in its own terms — only
	 * a hook has listeners to speak of.
	 */
	private static function interior_detail( string $span, bool $already ): string {
		if ( $already ) {
			return 'It is already a significant event, so its listeners are logged — read those next.';
		}
		if ( self::is_hook( $span ) ) {
			return 'Its listeners are not logged, so what happens inside it is invisible.';
		}
		if ( self::is_custom_event( $span ) ) {
			return 'The application logs this span itself; what happens inside it appears only where the application logs it.';
		}
		return 'This is one listener on a significant hook — the time is inside this callback.';
	}

	/** A span the application logged itself — an include, a function, a query. */
	private static function is_custom_event( string $span ): bool {
		return ! self::is_hook( $span ) && ! Hooks::is_listener_span( $span );
	}

	/**
	 * Whether a span is a WordPress hook, which is the only kind of span a
	 * rule's significant events can reach.
	 */
	private static function is_hook( string $span ): bool {
		return \str_ends_with( $span, Hooks::HOOK_SUFFIX );
	}

	/**
	 * Profiled total far below the request duration. Pure subtraction, and the
	 * finding says so — this is the one most likely to be over-read.
	 *
	 * @return array<string,mixed>|null
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
	 */
	public static function caveat(): string {
		return 'The logger times ONLY the hooks the URL\'s governing rule names, plus the custom events '
			. 'the application logs itself — nothing else is instrumented, so an absence here is as often '
			. 'an unbound hook as an idle one. It does not see SQL, outbound HTTP, or time spent below '
			. 'PHP userland unless the application logs it. Unattributed time means unmeasured, not idle.';
	}

	/** A duration a human reads at a glance: ms under a second, else seconds. */
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
	 * @param array<array-key,mixed>    $record   The request record.
	 * @param Rule|null                 $rule     The governing rule.
	 * @param list<array{name:string,value:float,count:int,depth:int}> $nodes    Flattened flame nodes.
	 * @param float                     $profiled Profiled milliseconds.
	 * @param float                     $duration Request duration in milliseconds.
	 * @return array<string,mixed>|null
	 */
	private static function cold_start( array $record, ?Rule $rule, array $nodes, float $profiled, float $duration ): ?array {
		$has_spans = [] !== $nodes;
		$hooks     = null === $rule ? [] : self::hooks_of( $rule );
		if ( null !== $rule && [] !== $hooks && $has_spans ) {
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
	 * Profiled milliseconds: the root's own value when it carries one, else the
	 * sum of the top-level spans. A tree built by `Flame_Fold` sets the root;
	 * one assembled span-by-span may not.
	 *
	 * @param array<array-key,mixed>    $flame The flame tree root.
	 * @param list<array{name:string,value:float,count:int,depth:int}> $nodes Flattened nodes.
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
	 * Flatten a flame tree into a list of `{name, value, count, depth}`, root
	 * excluded — the root IS the request, so it can never be the span holding
	 * most of the request.
	 *
	 * @param array<array-key,mixed> $flame The flame tree root.
	 * @return list<array{name:string,value:float,count:int,depth:int}>
	 */
	private static function flatten( array $flame, int $depth = 0 ): array {
		$out      = [];
		$children = \is_array( $flame['children'] ?? null ) ? $flame['children'] : [];
		foreach ( $children as $child ) {
			if ( ! \is_array( $child ) ) {
				continue;
			}
			$out[] = [
				'name'  => Core::as_string( $child['name'] ?? 'unknown', 'unknown' ),
				'value' => Core::num_float( $child['value'] ?? 0 ),
				'count' => Core::num_int( $child['count'] ?? 1, 1 ),
				'depth' => $depth + 1,
			];
			$out = \array_merge( $out, self::flatten( $child, $depth + 1 ) );
		}
		return $out;
	}

	/**
	 * A record's flame tree, under whichever key it arrived by.
	 *
	 * A LOADED record carries it at `flame_data` — `Performance_CI` merges the
	 * flames partition in under that name — while only a FOLDED record ever
	 * carries `flame`, which `Request_Builder_Node` writes as part of the fold.
	 * Reading one key alone made every ordinary request look wholly unmeasured.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @return array<array-key,mixed>
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
	 * @param array<array-key,mixed> $stats A URL index row (`hash`, `url`, `count`, `avg_ms`, `p95_ms`, …).
	 * @param Rule|null           $rule  The rule governing that URL, or null.
	 * @return list<array<string,mixed>>
	 */
	public static function for_url( array $stats, ?Rule $rule = null ): array {
		$url = Core::as_string( $stats['url'] ?? '' );
		if ( null !== $rule && [] !== self::hooks_of( $rule ) ) {
			return [];
		}
		$metric = [
			'count'       => Core::num_int( $stats['count'] ?? 0 ),
			'avg_ms'      => Core::num_float( $stats['avg_ms'] ?? 0 ),
			'p95_ms'      => Core::num_float( $stats['p95_ms'] ?? 0 ),
			'max_peak_mb' => Core::num_float( $stats['max_peak_mb'] ?? 0 ),
		];
		return [ self::insufficient( $url, $rule, $metric, 'url stats' ) ];
	}

	/**
	 * The insufficient-instrumentation finding, in either flavour: create a
	 * rule for a URL nothing governs, or bracket the lifecycle on a rule that
	 * registers nothing. Both propose MORE, and both name their own removal.
	 *
	 * @param array<array-key,mixed> $metric    The numbers that ARE known.
	 * @param string                 $measured  Where they came from.
	 * @param bool                   $hookless  Whether the rule registers no hooks.
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
