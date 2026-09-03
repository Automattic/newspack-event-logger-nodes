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

/**
 * Computes what is wrong with one request record, or with one URL nothing
 * measures, as a list of findings ordered worst first.
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
	 * edited together. A `%s` in a `why` takes the span's name.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const SPAN_ADVICE = [
		'significant:hook' => [
			'detail' => 'It is already a significant event, so its listeners are logged — read those next.',
			'why'    => 'It is already a significant event; its listeners are in this record.',
		],
		'significant'      => [
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
		$nodes    = self::flatten( $flame );
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
		for ( $i = 1; $i < \count( $entries ); $i++ ) {
			$prev = \is_array( $entries[ $i - 1 ] ) ? $entries[ $i - 1 ] : [];
			$next = \is_array( $entries[ $i ] ) ? $entries[ $i ] : [];
			$from = Core::as_string( $prev['k'] ?? '' );
			$to   = Core::as_string( $next['k'] ?? '' );
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
	 * One span holding most of the profiled time. The DEEPEST qualifying node
	 * wins: it is the most specific thing that still dominates, and therefore
	 * the one worth being able to see inside.
	 *
	 * @param list<array{name:string,value:float,self_ms:float,depth:int}> $nodes    Flattened flame nodes.
	 * @param float                                                        $profiled Profiled milliseconds.
	 * @param Rule|null                                                    $rule     The governing rule, or null when none does.
	 * @param float                                                        $duration Request duration in milliseconds.
	 * @return array<string,mixed>|null The finding, or null when no span holds `DOMINANT_SHARE`.
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
		$share      = $best['value'] / $profiled;
		$self_share = $best['self_ms'] / $profiled;
		return [
			'kind'     => 'dominant_span',
			'severity' => 'high',
			'title'    => \sprintf(
				'%s holds %d%% of the profiled time',
				$best['name'],
				(int) \round( $share * 100 )
			),
			'detail'   => \implode(
				' ',
				\array_filter(
					[ self::spent_detail( $best, $self_share ), self::interior_detail( $best['name'], $rule ) ],
					static fn ( string $sentence ): bool => '' !== $sentence
				)
			),
			'measured' => 'flame',
			'metric'   => [
				'name'       => $best['name'],
				'ms'         => $best['value'],
				'share'      => $share,
				'self_ms'    => $best['self_ms'],
				'self_share' => $self_share,
				'depth'      => $best['depth'],
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
	 * What rule edit would make this span's interior visible, and what it costs.
	 *
	 * @param string    $span The span's name, as the flame carries it.
	 * @param Rule|null $rule The governing rule, or null when none does.
	 * @param string    $undo The undo sentence for a `more` proposal.
	 * @return array<string,mixed>
	 */
	private static function visibility_proposal( string $span, ?Rule $rule, string $undo ): array {
		$advice = self::span_advice( $span, $rule );
		$out    = [
			'action'    => $advice['action'] ?? 'none',
			'direction' => $advice['direction'] ?? 'none',
			'rule_id'   => $rule?->id,
			'why'       => \sprintf( $advice['why'], $span ),
			'undo'      => isset( $advice['action'] ) ? ( $advice['undo'] ?? $undo ) : '',
		];
		if ( isset( $advice['field'] ) ) {
			$out['field'] = $advice['field'];
			$out['value'] = $span;
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
		return self::span_advice( $span, $rule )['detail'];
	}

	/**
	 * The `SPAN_ADVICE` row governing one span.
	 *
	 * @param string    $span The span's name, as the flame carries it.
	 * @param Rule|null $rule The governing rule, or null when none does.
	 * @return array<string,string>
	 */
	private static function span_advice( string $span, ?Rule $rule ): array {
		$kind = self::span_kind( $span );
		if ( self::is_significant( $span, $rule ) ) {
			return self::SPAN_ADVICE[ 'hook' === $kind ? 'significant:hook' : 'significant' ];
		}
		return self::SPAN_ADVICE[ $kind ];
	}

	/**
	 * Whether the rule already marks this span significant. `bind_current_scope()`
	 * accepts an event with or without the ` hook` suffix, so both spellings name
	 * the same hook and a comparison that misses one proposes a no-op edit.
	 *
	 * @param string    $span The span's name, as the flame carries it.
	 * @param Rule|null $rule The governing rule, or null when none does.
	 * @return bool True when the rule names the span under either spelling.
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
	 * The only three kinds of span the flame carries, classified once so every
	 * caller reaches the same `SPAN_ADVICE` row. A custom event has no
	 * listeners, and prose crediting it with any sends the reader hunting a
	 * callback that does not exist.
	 *
	 * @param string $span The span's name, as the flame carries it.
	 * @return string `hook`, `listener` or `custom`.
	 */
	private static function span_kind( string $span ): string {
		if ( \str_ends_with( $span, Hooks::HOOK_SUFFIX ) ) {
			return 'hook';
		}
		return Hooks::is_listener_span( $span ) ? 'listener' : 'custom';
	}

	/**
	 * How much of the time a span holds it actually SPENDS, said only where it
	 * has children to hide behind. A wrapper reads as 100% and sends a reader
	 * inside the one span guaranteed to contain everything — a `pyrobase` span
	 * holds 100% of the profiled time and spends 9.5% of it in its own body.
	 *
	 * @param array{name:string,value:float,self_ms:float,depth:int} $node       The dominant node.
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
			. 'the application logs itself, and every outbound HTTP request — nothing else is '
			. 'instrumented, so an absence here is as often an unbound hook as an idle one. It does '
			. 'not see SQL, or time spent below PHP userland, unless the application logs it. '
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
	 * @param list<array{name:string,value:float,self_ms:float,depth:int}> $nodes    Flattened flame nodes.
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
	 * @param list<array{name:string,value:float,self_ms:float,depth:int}> $nodes Flattened nodes.
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
	 * Flatten a flame tree into a list of `{name, value, self_ms, depth}`, root
	 * excluded — the root IS the request, so it can never be the span holding
	 * most of the request.
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
	 * @param array<array-key,mixed> $flame The flame tree root.
	 * @param int                    $depth The depth of `$flame` itself; its children come back one deeper.
	 * @return list<array{name:string,value:float,self_ms:float,depth:int}>
	 */
	private static function flatten( array $flame, int $depth = 0 ): array {
		$out      = [];
		$children = \is_array( $flame['children'] ?? null ) ? $flame['children'] : [];
		foreach ( $children as $child ) {
			if ( ! \is_array( $child ) ) {
				continue;
			}
			$value = Core::num_float( $child['value'] ?? 0 );
			$out[] = [
				'name'    => Core::as_string( $child['name'] ?? 'unknown', 'unknown' ),
				'value'   => $value,
				'self_ms' => $value - self::children_value( $child ),
				'depth'   => $depth + 1,
			];
			$out = \array_merge( $out, self::flatten( $child, $depth + 1 ) );
		}
		return $out;
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
