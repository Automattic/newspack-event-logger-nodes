<?php
/**
 * Ask_Assembler: turn a picker descriptor into the brief for that ONE thing.
 *
 * The picker inverts a per-surface "Ask AI" button: you point at the problem,
 * so THE TARGET IS THE SCOPE. Each shaper below sends that thing plus enough
 * context to explain it, and nothing else — a span brief is a subtree, its
 * siblings and its parent's total, not 47 log entries and an environment block
 * in the hope the model finds the part you were looking at.
 *
 * Everything here is shaping. Loading is the caller's (`Performance_CI`), which
 * already owns every reader; that keeps this class a pure function of its
 * inputs and testable without a partition on disk.
 *
 * Three rules hold across every shaper:
 *   - URLs go through `Log_Manager::redact_url()`, the one redaction path.
 *   - The environment is dropped except an allowlist. No headers, no IPs, no
 *     user agents, no cookies — the brief leaves the site.
 *   - The caveat rides on every brief, because a model handed a
 *     profiled/duration ratio without one will invent a cause. `Findings`
 *     rides where a detector has something to say: the request and the URL.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

/**
 * Six shapers for five descriptor types — `category:` has two, one for the
 * board inside a request and one for the global board — plus the parser that
 * decides which. Every method is static and the class holds no state.
 *
 * A shaper returns null when the record does not hold what was asked about: a
 * span absent from the tree, an entry number no line carries, a category off
 * the board. `Performance_CI_Node` turns that null into the message the picker
 * shows, so no shaper here decides how a miss reads.
 */
class Ask_Assembler {

	/** Entries a request brief carries; past this it truncates and says so. */
	public const MAX_ENTRIES = 60;

	/** Entries either side of the subject an `entry:` brief carries. */
	public const NEIGHBOURS = 2;

	/** Worst recent requests a `url:` brief carries. */
	public const WORST_REQUESTS = 5;

	/**
	 * Rows any list in a brief carries: top-level and sibling spans, the
	 * parents in `elsewhere`, the rest of a category board. Past this a brief
	 * spends its budget on noise.
	 */
	public const TOP_SPANS = 6;

	/** The descriptor vocabulary. Deliberately small: it is a contract. */
	private const TYPES = [ 'url', 'request', 'span', 'entry', 'category' ];

	/**
	 * Environment fields a brief may carry. Everything else is dropped rather
	 * than filtered, so a new field on the record does not silently start
	 * leaving the site.
	 */
	private const ENV_ALLOWLIST = [ 'method', 'request_method', 'status_code', 'worker_type', 'partition', 'server_name' ];

	/**
	 * One request: what it did, how long it took, what the detector found.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @param Rule|null              $rule   The rule governing its URL.
	 * @return array<string,mixed>
	 */
	public static function for_request( array $record, ?Rule $rule ): array {
		$entries   = \is_array( $record['entries'] ?? null ) ? \array_values( $record['entries'] ) : [];
		$truncated = \count( $entries ) > self::MAX_ENTRIES;

		return [
			'subject'           => 'request',
			'url'               => self::url_of( $record ),
			'duration_ms'       => Core::num_float( $record['duration_ms'] ?? 0 ),
			'status_code'       => Core::num_int( $record['status_code'] ?? 0 ),
			'env'               => self::env_of( $record ),
			'flame'             => self::flame_summary( $record ),
			'entries'           => \array_map(
				[ self::class, 'entry_shape' ],
				\array_slice( $entries, 0, self::MAX_ENTRIES )
			),
			'entries_truncated' => $truncated,
			'rule'              => self::rule_shape( $rule ),
			'findings'          => Findings::for_request( $record, $rule ),
			'fetch'             => self::fetch(
				'performance_request_detail',
				[ 'rid' => Core::as_string( $record['rid'] ?? '' ) ]
			),
			'caveat'            => Findings::caveat(),
		];
	}

	/**
	 * The top of the flame tree: what the profiler accounted for, and the
	 * heaviest top-level spans. The whole tree is what `span:` briefs are for.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @return array<string,mixed>
	 */
	private static function flame_summary( array $record ): array {
		$flame = Findings::flame_of( $record );
		return [
			'profiled_ms' => Core::num_float( $flame['value'] ?? 0 ),
			'top_level'   => self::top_spans( $flame['children'] ?? null ),
		];
	}

	/**
	 * The environment as a brief carries it: the ENV_ALLOWLIST keys this record
	 * holds, scalar values only.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @return array<string,mixed>
	 */
	private static function env_of( array $record ): array {
		$env = [];
		foreach ( self::ENV_ALLOWLIST as $key ) {
			if ( isset( $record[ $key ] ) && \is_scalar( $record[ $key ] ) ) {
				$env[ $key ] = $record[ $key ];
			}
		}
		return $env;
	}

	/**
	 * One flame span: its subtree, its siblings, and its parent's total — the
	 * three numbers that say whether it is the problem or merely contains it.
	 *
	 * @param array<array-key,mixed> $record  A stored request record.
	 * @param string                 $name    Span name as it appears in the tree.
	 * @param Rule|null              $rule    The rule an edit would land on.
	 * @param string                 $context Descriptor of the request this span ran in, which `fetch` addresses the brief again by.
	 * @return array<string,mixed>|null Null when the tree holds no such span.
	 */
	public static function for_span( array $record, string $name, ?Rule $rule, string $context = '' ): ?array {
		$groups = self::span_groups( Findings::flame_of( $record ), $name );
		if ( [] === $groups ) {
			return null;
		}

		// @longform
		// A tree holds duplicate siblings apart, so `query hook` can be three
		// nodes under one parent, AND the same name can sit under a dozen
		// different parents. The request brief folds the first kind, so a span
		// brief reporting only one occurrence would contradict it — with the
		// rest accounted for nowhere, which reads as the SIBLING being the
		// problem. Answering for whichever parent a depth-first walk reached
		// first is the same failure across parents: on a real 3.3s record that
		// was 9ms of `pre_get_posts hook` under `process`, while the sixteen
		// under `do_blocks` held 2266ms. Report the parent whose copies hold
		// the most time, and let `elsewhere` say what that leaves out.
		\usort( $groups, static fn ( array $a, array $b ): int => $b['ms'] <=> $a['ms'] );
		$best     = \array_shift( $groups );
		$parent   = $best['parent'];
		$subject  = self::top_spans( $best['mine'] )[0] ?? self::span_shape( [] );
		$subtree  = \array_merge(
			...\array_map(
				static fn ( array $node ): array =>
					\is_array( $node['children'] ?? null ) ? $node['children'] : [],
				$best['mine']
			)
		);

		return [
			'subject'   => 'span',
			'name'      => $name,
			'ms'        => $subject['ms'],
			'count'     => $subject['count'],
			'parent'    => Core::as_string( $parent['name'] ?? 'request', 'request' ),
			'parent_ms' => Core::num_float( $parent['value'] ?? 0 ),
			...( [] === $groups ? [] : [ 'elsewhere' => self::elsewhere( $groups ) ] ),
			'siblings'  => self::top_spans( $best['siblings'] ),
			'subtree'   => self::top_spans( $subtree ),
			'url'       => self::url_of( $record ),
			'rule'      => self::rule_shape( $rule ),
			'fetch'     => self::fetch(
				'performance_ask',
				[ 'descriptor' => "span:{$name}", 'context' => $context ]
			),
			'caveat'    => Findings::caveat(),
		];
	}

	/**
	 * What choosing one parent leaves out: the same span under every OTHER
	 * parent, so a brief reporting the heaviest group cannot read as the whole
	 * tree's total.
	 *
	 * @param list<array{parent:array<array-key,mixed>,mine:list<array<array-key,mixed>>,siblings:list<array<array-key,mixed>>,ms:float}> $groups The groups not chosen.
	 * @return array{ms:float,count:int,parents:list<string>}
	 */
	private static function elsewhere( array $groups ): array {
		$ms      = 0.0;
		$count   = 0;
		$parents = [];
		foreach ( $groups as $group ) {
			$ms       += $group['ms'];
			$parents[] = Core::as_string( $group['parent']['name'] ?? 'request', 'request' );
			foreach ( $group['mine'] as $node ) {
				$count += Core::num_int( $node['count'] ?? 1, 1 );
			}
		}
		return [
			'ms'      => $ms,
			'count'   => $count,
			'parents' => \array_slice( \array_values( \array_unique( $parents ) ), 0, self::TOP_SPANS ),
		];
	}

	/**
	 * A span list as a brief carries it: same-name spans folded into one row,
	 * slowest first, capped.
	 *
	 * A tree holds duplicate siblings apart with a hidden suffix, so four
	 * `query hook` children arrive as four rows saying nothing individually.
	 * Folding is what makes the cap spend its slots on spans that took time.
	 *
	 * @param mixed $nodes Flame nodes, as the tree stores them.
	 * @return list<array{name:string,ms:float,count:int}>
	 */
	private static function top_spans( mixed $nodes ): array {
		$folded = [];
		foreach ( \is_array( $nodes ) ? $nodes : [] as $node ) {
			if ( ! \is_array( $node ) ) {
				continue;
			}
			$span = self::span_shape( $node );
			$name = $span['name'];
			if ( isset( $folded[ $name ] ) ) {
				$folded[ $name ]['ms']    += $span['ms'];
				$folded[ $name ]['count'] += $span['count'];
				continue;
			}
			$folded[ $name ] = $span;
		}
		$spans = \array_values( $folded );
		\usort( $spans, static fn ( array $a, array $b ): int => $b['ms'] <=> $a['ms'] );
		return \array_slice( $spans, 0, self::TOP_SPANS );
	}

	/**
	 * One span, without its children — the shape siblings and subtree rows use.
	 *
	 * @param array<array-key,mixed> $node A flame node.
	 * @return array{name:string,ms:float,count:int}
	 */
	private static function span_shape( array $node ): array {
		return [
			'name'  => Core::as_string( $node['name'] ?? 'unknown', 'unknown' ),
			'ms'    => Core::num_float( $node['value'] ?? 0 ),
			'count' => Core::num_int( $node['count'] ?? 1, 1 ),
		];
	}

	/**
	 * Every parent in the tree holding children of this name, each with those
	 * children, that parent's other children, and what the group cost.
	 *
	 * @param array<array-key,mixed> $node Subtree root.
	 * @param string                 $name Span name to collect.
	 * @return list<array{parent:array<array-key,mixed>,mine:list<array<array-key,mixed>>,siblings:list<array<array-key,mixed>>,ms:float}>
	 */
	private static function span_groups( array $node, string $name ): array {
		$mine     = [];
		$siblings = [];
		$children = \is_array( $node['children'] ?? null ) ? $node['children'] : [];
		foreach ( $children as $child ) {
			if ( ! \is_array( $child ) ) {
				continue;
			}
			if ( Core::as_string( $child['name'] ?? '' ) === $name ) {
				$mine[] = $child;
				continue;
			}
			$siblings[] = $child;
		}
		$groups = [] === $mine
			? []
			: [ [
				'parent'   => $node,
				'mine'     => $mine,
				'siblings' => $siblings,
				'ms'       => \array_sum( \array_map( static fn ( array $n ): float => Core::num_float( $n['value'] ?? 0 ), $mine ) ),
			] ];
		foreach ( $children as $child ) {
			if ( \is_array( $child ) ) {
				$groups = \array_merge( $groups, self::span_groups( $child, $name ) );
			}
		}
		return $groups;
	}

	/**
	 * One log entry, its neighbours, and the gap either side — which is where
	 * an uninstrumented call shows up as nothing at all.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @param int                    $n      The entry's own sequence number.
	 * @return array<string,mixed>|null Null when the record holds no such entry.
	 */
	public static function for_entry( array $record, int $n ): ?array {
		$entries = \is_array( $record['entries'] ?? null ) ? \array_values( $record['entries'] ) : [];
		$index   = null;
		foreach ( $entries as $i => $entry ) {
			if ( \is_array( $entry ) && Core::num_int( $entry['n'] ?? -1, -1 ) === $n ) {
				$index = $i;
				break;
			}
		}
		if ( null === $index ) {
			return null;
		}

		$neighbours = [];
		for ( $i = \max( 0, $index - self::NEIGHBOURS ); $i <= \min( \count( $entries ) - 1, $index + self::NEIGHBOURS ); $i++ ) {
			if ( $i !== $index && \is_array( $entries[ $i ] ) ) {
				$neighbours[] = self::entry_shape( $entries[ $i ] );
			}
		}

		return [
			'subject'       => 'entry',
			'entry'         => self::entry_shape( $entries[ $index ] ),
			'neighbours'    => $neighbours,
			'gap_before_ms' => self::gap( $entries, $index - 1, $index ),
			'gap_after_ms'  => self::gap( $entries, $index, $index + 1 ),
			'url'           => self::url_of( $record ),
			'caveat'        => Findings::caveat(),
		];
	}

	/**
	 * The milliseconds between two entries, or null when either end is missing.
	 *
	 * @param list<mixed> $entries The entry list.
	 * @param int         $from    Index of the earlier entry.
	 * @param int         $to      Index of the later entry.
	 * @return float|null
	 */
	private static function gap( array $entries, int $from, int $to ): ?float {
		if ( ! isset( $entries[ $from ], $entries[ $to ] )
				|| ! \is_array( $entries[ $from ] ) || ! \is_array( $entries[ $to ] ) ) {
			return null;
		}
		return ( Core::num_float( $entries[ $to ]['ts'] ?? 0 ) - Core::num_float( $entries[ $from ]['ts'] ?? 0 ) ) * 1000.0;
	}

	/**
	 * One entry, shaped for the brief. The payload is whatever survived the
	 * firehose; `MAX_ENTRIES` bounds the brief, not the entry.
	 *
	 * @param mixed $entry A raw entry.
	 * @return array<string,mixed>
	 */
	private static function entry_shape( mixed $entry ): array {
		$entry   = \is_array( $entry ) ? $entry : [];
		$message = \is_array( $entry['m'] ?? null )
			? Core::as_string( \wp_json_encode( $entry['m'] ) )
			: Core::as_string( $entry['m'] ?? '' );
		return [
			'n'  => Core::num_int( $entry['n'] ?? 0 ),
			'ts' => Core::num_float( $entry['ts'] ?? 0 ),
			'k'  => Core::as_string( $entry['k'] ?? '' ),
			'm'  => $message,
		];
	}

	/**
	 * One URL: its stats and its worst recent requests — plus, when nothing
	 * governs it, the cold-start finding that says which instrumentation to
	 * switch on. The dimensional breakdown is `url_detail`'s to return; a
	 * brief names that verb rather than carrying an unbounded series.
	 *
	 * The requests are drawn from a WINDOW, and both facts about that walk are
	 * required arguments: how it ended, and what it was of. A narrower number
	 * that does not say so reads as the site's.
	 *
	 * @param array<array-key,mixed>            $stats             A URL index row.
	 * @param array<int,array<array-key,mixed>> $requests          Recent requests for that URL.
	 * @param Rule|null                         $rule              The governing rule, or null.
	 * @param string                            $server            Server the numbers are of; '' is site-wide.
	 * @param bool                              $scan_stopped_early Whether the walk behind `$requests` ran out of budget.
	 * @param int                               $requests_window_start Unix time the window those requests were drawn from opens.
	 * @return array<string,mixed>
	 */
	public static function for_url( array $stats, array $requests, ?Rule $rule, string $server, bool $scan_stopped_early, int $requests_window_start ): array {
		\usort(
			$requests,
			static fn ( array $a, array $b ): int =>
				Core::num_float( $b['duration_ms'] ?? 0 ) <=> Core::num_float( $a['duration_ms'] ?? 0 )
		);

		return [
			'subject'            => 'url',
			'url'                => Log_Manager::redact_url( Core::as_string( $stats['url'] ?? '' ) ),
			'hash'               => Core::as_string( $stats['hash'] ?? '' ),
			// What these numbers are OF; the pointer below carries it too.
			'server'             => $server,
			'stats'              => [
				'count'       => Core::num_int( $stats['count'] ?? 0 ),
				'avg_ms'      => Core::num_float( $stats['avg_ms'] ?? 0 ),
				'max_ms'      => Core::num_float( $stats['max_ms'] ?? 0 ),
				'max_peak_mb' => Core::num_float( $stats['max_peak_mb'] ?? 0 ),
			],
			'worst_requests'     => \array_map(
				[ self::class, 'worst_request_shape' ],
				\array_slice( $requests, 0, self::WORST_REQUESTS )
			),
			// The WALK, not the five-row slice above it, which always cuts.
			'scan_stopped_early' => $scan_stopped_early,
			// What the walk is OF: an empty list is empty of this window only.
			'requests_window_start' => $requests_window_start,
			'rule'               => self::rule_shape( $rule ),
			'findings'           => Findings::for_url( $stats, $rule ),
			'fetch'              => self::fetch(
				'performance_url_detail',
				[ 'hash' => Core::as_string( $stats['hash'] ?? '' ), 'server' => $server ]
			),
			'caveat'             => Findings::caveat(),
		];
	}

	/**
	 * How an agent fetches this thing again, as an MCP tool call. What a brief
	 * trims stays reachable: the pointer is the address of the rest.
	 *
	 * @param string               $tool      The MCP tool name.
	 * @param array<string,string> $arguments Its named arguments, absent ones dropped.
	 * @return list<array<string,mixed>>
	 */
	private static function fetch( string $tool, array $arguments ): array {
		return [
			[
				'tool'      => $tool,
				'arguments' => \array_filter( $arguments, static fn ( string $v ): bool => '' !== $v ),
			],
		];
	}

	/**
	 * A rule as the brief carries it: what an edit would land on, never a
	 * roster of names — neither the hooks (hundreds) nor the custom events
	 * (dozens), which no consumer renders and a model cannot act on.
	 *
	 * @param Rule|null $rule The rule governing the subject's URL.
	 * @return array<string,mixed>|null Null when no rule governs it.
	 */
	private static function rule_shape( ?Rule $rule ): ?array {
		if ( null === $rule ) {
			return null;
		}
		return [
			'id'                 => $rule->id,
			'pattern'            => $rule->pattern,
			'action'             => $rule->action,
			'hook_count'         => null === $rule->hooks ? null : \count( $rule->hooks ),
			'custom_event_count' => \count( $rule->custom_events ),
			'significant_events' => $rule->significant_events,
		];
	}

	/**
	 * One breakdown row INSIDE a request — the shape `RequestProfile` renders,
	 * which is that request's own per-category profile.
	 *
	 * Answering such a click from the global leaderboard describes a different
	 * thing entirely, with nothing in the payload saying so, and a category
	 * present here but absent from the recent global window made a visible row
	 * a dead click.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @param string                 $name   The category clicked.
	 * @return array<string,mixed>|null Null when this request holds no such category.
	 */
	public static function for_request_category( array $record, string $name ): ?array {
		$profiles = \is_array( $record['profiles'] ?? null ) ? $record['profiles'] : [];
		if ( ! isset( $profiles[ $name ] ) || ! \is_array( $profiles[ $name ] ) ) {
			return null;
		}
		return self::category_brief( $profiles, $name, 'request', 1, [ 'url' => self::url_of( $record ) ] );
	}

	/**
	 * The record's URL, redacted through the one path the firehose uses.
	 *
	 * @param array<array-key,mixed> $record A stored request record.
	 * @return string
	 */
	private static function url_of( array $record ): string {
		return Log_Manager::redact_url( Core::as_string( $record['url'] ?? '' ) );
	}

	/**
	 * One breakdown row: its own numbers, its share, and what it is competing
	 * with — a category means nothing without the rest of the board.
	 *
	 * @param array<array-key,mixed> $categories The display-shaped category map.
	 * @param string                 $name       The category clicked.
	 * @param string                 $server     Server the means are of; '' is every server.
	 * @return array<string,mixed>|null Null when the board holds no such row.
	 */
	public static function for_category( array $categories, string $name, string $server = '' ): ?array {
		if ( ! isset( $categories[ $name ] ) || ! \is_array( $categories[ $name ] ) ) {
			return null;
		}
		// Stats_Store hands these over as per-request means: time and count.
		$scope = '' === $server ? 'recent window' : "recent window on {$server}";
		$mine  = Core::arr( $categories[ $name ] );
		return self::category_brief( $categories, $name, $scope, Core::num_int( $mine['samples'] ?? 0 ) );
	}

	/**
	 * The shape both category briefs return: this row's numbers, its share of
	 * the board, and the rest of the board sorted against it.
	 *
	 * @param array<array-key,mixed> $rows    Every row of the board, this one included.
	 * @param string                 $name    The row asked about.
	 * @param string                 $scope   Names the set these means were taken over.
	 * @param int                    $samples Requests behind them.
	 * @param array<string,mixed>    $extra   Keys only one caller carries.
	 * @return array<string,mixed>
	 */
	private static function category_brief( array $rows, string $name, string $scope, int $samples, array $extra = [] ): array {
		$total  = 0.0;
		$others = [];
		foreach ( $rows as $key => $row ) {
			$time   = \is_array( $row ) ? Core::num_float( $row['time'] ?? 0 ) : 0.0;
			$total += $time;
			if ( (string) $key !== $name ) {
				$others[] = [
					'name'        => (string) $key,
					'avg_time_ms' => $time,
					'avg_count'   => \is_array( $row ) ? Core::num_float( $row['count'] ?? 0 ) : 0.0,
				];
			}
		}
		\usort( $others, static fn ( array $a, array $b ): int => $b['avg_time_ms'] <=> $a['avg_time_ms'] );

		$mine = Core::arr( $rows[ $name ] ?? null );
		$time = Core::num_float( $mine['time'] ?? 0 );
		return \array_merge(
			[
				'subject'     => 'category',
				'scope'       => $scope,
				'name'        => $name,
				'avg_time_ms' => $time,
				'avg_count'   => Core::num_float( $mine['count'] ?? 0 ),
				'samples'     => $samples,
				'share'       => $total > 0.0 ? $time / $total : 0.0,
				'others'      => \array_slice( $others, 0, self::TOP_SPANS ),
				'caveat'      => Findings::caveat(),
			],
			$extra
		);
	}

	/**
	 * Split `type:id[:qualifier]`. Null on anything outside the vocabulary, so
	 * a hand-typed or stale descriptor is refused rather than half-honoured.
	 *
	 * @param string $descriptor `type:id[:qualifier]`, as a `data-ask` attribute carries it.
	 * @return array{type:string,id:string,qualifier:string}|null
	 */
	public static function parse_descriptor( string $descriptor ): ?array {
		$parts = \explode( ':', $descriptor, 3 );
		if ( \count( $parts ) < 2 || ! \in_array( $parts[0], self::TYPES, true ) || '' === $parts[1] ) {
			return null;
		}
		return [
			'type'      => $parts[0],
			'id'        => $parts[1],
			'qualifier' => $parts[2] ?? '',
		];
	}

	/**
	 * One recent request, named rather than located: enough to ask about it
	 * (`request:<rid>:<partition>`) and to see why it is on this list.
	 *
	 * @param mixed $request A stored index row.
	 * @return array<string,mixed>
	 */
	private static function worst_request_shape( mixed $request ): array {
		$request = \is_array( $request ) ? $request : [];
		return [
			'rid'          => Core::as_string( $request['rid'] ?? '' ),
			'partition'    => Core::num_int( $request['partition'] ?? 0 ),
			'duration_ms'  => Core::num_float( $request['duration_ms'] ?? 0 ),
			'status_code'  => Core::num_int( $request['status_code'] ?? 0 ),
			'error_status' => Core::as_string( $request['error_status'] ?? '' ),
		];
	}
}
