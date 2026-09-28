<?php
/**
 * Narration: a builder node telling its own upkeep on its worker's record.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

/**
 * What a builder did, told on the worker's own request record rather than
 * through `print_less_often()` or stderr, which land in the Error Log.
 *
 * A step that takes time is a span, `spanned()`; a summary or a decision is
 * a point line, `narrate()`, and a decision made inside a step sits between
 * its span's halves. Everything a line says rides its `m`, because the stored
 * record keeps an entry's `m` and drops every other producer field: `told()`
 * of a head, then each counter as `<n> <name>`, joined by ` · `, zeros left
 * out. Counters accumulate in `$tally` by name until the line or span of that
 * name carries and resets them. With no record started nothing is built — a
 * line is a closure never called, a step runs bare — and the tally resets.
 *
 * Each line is a Partition write, so each can raise the cooperative stop,
 * and every one goes through the node's `guarded()`: the stop waits for the
 * message or tick the node is in the middle of, rather than cutting it off
 * between a write and the bookkeeping that follows. So the trait composes
 * only with the substrate's `Deferred_Clean_Stop`.
 */
trait Narration {

	/** Seconds between a builder's rollups of its routine work. */
	private const ROLLUP_EVERY_S = 60;

	/**
	 * The narration not yet told, by name: the counters each line or span of
	 * that name carries, reset as it is written.
	 *
	 * @var array<string,array<string,int>>
	 */
	private array $tally = [];

	/** When the rollup was last told; 0 before the first. */
	private int $rollup_told_at = 0;

	/**
	 * Add to a counter the next line or span of `$name` carries.
	 *
	 * @param string $name    A `Flame_Tree` narration name.
	 * @param string $counter The field it lands under.
	 * @param int    $by      How much.
	 */
	private function tally( string $name, string $counter, int $by = 1 ): void {
		if ( 0 !== $by ) {
			$this->tally[ $name ][ $counter ] = ( $this->tally[ $name ][ $counter ] ?? 0 ) + $by;
		}
	}

	/**
	 * Whether the rollup is due at `$now`, once a `ROLLUP_EVERY_S`, the first
	 * ask included; a yes starts the next wait.
	 *
	 * @param int $now The tick.
	 */
	private function rollup_due( int $now ): bool {
		if ( $now - $this->rollup_told_at < self::ROLLUP_EVERY_S ) {
			return false;
		}
		$this->rollup_told_at = $now;
		return true;
	}

	/**
	 * One point line: `$line` builds the head and the counters it leads
	 * with, given the tally, and runs only where a record is started.
	 *
	 * @param string                                                                                            $category A `Flame_Tree` narration name.
	 * @param \Closure(array<string,int>): array{0: string, 1: array<string,int|float|bool>, 2?: list<string>} $line     The head, the counters it
	 *                                                                                                                    leads with, and any it
	 *                                                                                                                    tells even at zero.
	 */
	private function narrate( string $category, \Closure $line ): void {
		$tally = $this->tally[ $category ] ?? [];
		unset( $this->tally[ $category ] );
		$lm = Log_Manager::started_instance();
		if ( null === $lm ) {
			return;
		}
		$said = $line( $tally );
		$this->guarded( static fn () => $lm->message( $category, [ 'm' => self::told( $said[0], $said[1] + $tally, $said[2] ?? [] ), 'keep' => 1 ] ) );
	}

	/**
	 * Run one step as a span, its `(complete)` `m` told as a line is, the
	 * label's tally after the counters `$describe` leads with. Both halves are
	 * kept, so a fold keeps the step, and each is guarded on its own, so a
	 * stop the `(start)` raises still runs the step and closes it. The span
	 * runs in a `deferring()` bracket of its own, so that holds where no
	 * bracket is open — a restore the reader runs before its first message —
	 * and the stop leaves once the span has closed. The clean stop that
	 * bracket raises is then one `guarded()` forward, so inside a message's
	 * or a tick's bracket it waits for that work as any forward's does. Any
	 * other stop — one carrying a failure, the step's or a write's, or one the
	 * step let escape — is not held: it ends the caller at once. The tally
	 * resets whatever `$run` does. An untold step runs bare and leaves the
	 * tally to the next told one.
	 *
	 * @template T
	 * @param string         $label    A `Flame_Tree` narration name.
	 * @param \Closure(): T  $run      The step.
	 * @param \Closure(T): array{0: string, 1: array<string,int|float|bool>, 2?: list<string>} $describe The head, the
	 *                                                                                              counters it leads
	 *                                                                                              with, and any it
	 *                                                                                              tells at zero.
	 * @param bool           $told     Whether this step is a span at all.
	 * @return T What `$run` returned.
	 */
	private function spanned( string $label, \Closure $run, \Closure $describe, bool $told = true ): mixed {
		if ( ! $told ) {
			return $run();
		}
		$lm = Log_Manager::started_instance();
		try {
			if ( null === $lm ) {
				return $run();
			}
			$ran  = null;
			$span = function () use ( $lm, $label, $run, $describe, &$ran ): void {
				$ran = [
					$lm->timed(
						$label,
						$run,
						function ( mixed $done ) use ( $label, $describe ): string {
							$said = $describe( $done );
							return self::told( $said[0], $said[1] + ( $this->tally[ $label ] ?? [] ), $said[2] ?? [] );
						},
						[ 'keep' => 1 ],
						write: $this->guarded( ... )
					),
				];
			};
			try {
				$this->deferring( $span );
			} catch ( Worker_Should_Stop $stop ) {
				// Only a clean stop waits; any other ends the caller.
				if ( ! Worker_Should_Stop::is_clean( $stop ) ) {
					throw $stop;
				}
				$this->guarded( static fn () => throw $stop );
			}
			return ( $ran ?? throw new \LogicException( "{$label}: the step never returned" ) )[0];
		} finally {
			unset( $this->tally[ $label ] );
		}
	}

	/**
	 * Tell a rollup line of `$name`: `$extra`, then the `$lead` counters, zero
	 * where the tally holds none, then the rest of the tally. With nothing
	 * tallied and every `$extra` zero, no line.
	 *
	 * @param string            $name  A `Flame_Tree` narration name.
	 * @param list<string>      $lead  The tally's counters it leads with.
	 * @param array<string,int> $extra Counters the builder keeps itself.
	 */
	private function rollup( string $name, array $lead, array $extra = [] ): void {
		if ( ! isset( $this->tally[ $name ] ) && 0 === \array_sum( $extra ) ) {
			return;
		}
		$this->narrate(
			$name,
			static fn ( array $tally ): array => [ '', $extra + \array_replace( \array_fill_keys( $lead, 0 ), $tally ) ]
		);
	}

	/**
	 * Run a downstream write, holding a stop it raises for the open bracket:
	 * `Deferred_Clean_Stop::guarded()`.
	 *
	 * @param \Closure $forward The write, as `function(): void`.
	 */
	abstract protected function guarded( \Closure $forward ): void;

	/**
	 * Run work in a bracket of its own, raising what it held once the work is
	 * done: `Deferred_Clean_Stop::deferring()`.
	 *
	 * @param \Closure $body The work, as `function(): void`.
	 */
	abstract protected function deferring( \Closure $body ): void;

	/**
	 * A narration `m`: the head, then each counter, zeros left out but for
	 * `$kept` — `12 flushes · 120 writes · 3 refused urlrank_s`.
	 *
	 * @param string                       $head   What the line says first; '' for nothing.
	 * @param array<string,int|float|bool> $counts Counter => value; a true one is told by name.
	 * @param list<string>                 $kept   Counters told even at zero.
	 */
	private static function told( string $head, array $counts, array $kept = [] ): string {
		$told = '' === $head ? [] : [ $head ];
		foreach ( $counts as $name => $n ) {
			if ( true === $n ) {
				$told[] = $name;
			} elseif ( \is_int( $n ) || \is_float( $n ) ) {
				// Rounded to a tenth first: what would show as 0 says nothing.
				$n = \is_float( $n ) ? \round( $n, 1 ) : $n;
				if ( 0.0 !== (float) $n || \in_array( $name, $kept, true ) ) {
					$told[] = "{$n} {$name}";
				}
			}
		}
		return \implode( ' · ', $told );
	}
}
