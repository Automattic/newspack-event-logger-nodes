<?php
/**
 * Benchmark of the ruleset's two hook-storage tiers: autoloaded-inline against
 * memcache-pointer.
 *
 * `Rule_Set` keeps a rule's hooks inline in the autoloaded rules option up to
 * `Rule_Set::INLINE_HOOK_LIMIT`, and behind a memcache-mirrored non-autoloaded
 * option above it. This command measures the three per-request costs that
 * crossover trades off — the alloptions unserialize tax, an inline read plus
 * bind, and a memcache fetch plus bind — over a hooks-per-rule × rule-count
 * grid, then prints the guidance for reading the table. The limit is already
 * fixed at 100; rerun the sweep to re-validate it on new hardware.
 *
 * The sweep never touches the live ruleset. It builds synthetic hook lists in
 * memory and writes only its own short-lived `evlog:bench:hooks:*` memcache
 * keys, which it deletes per grid cell.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\CLI;

\defined( 'ABSPATH' ) || exit;

/**
 * `wp nodes ruleset-bench` — measurement only. Not on the request hot path.
 *
 * Registered by `newspack-event-logger-nodes.php` under the substrate's `nodes`
 * command namespace. The grid is `HOOKS_PER_RULE` × `RULE_COUNTS`.
 */
class Ruleset_Bench_Command {

	/** Hooks per rule, swept per row. `Rule_Set::INLINE_HOOK_LIMIT` (100) sits inside this range. */
	private const HOOKS_PER_RULE = [ 50, 65, 100, 250, 500, 1000, 2500, 5000 ];

	/** Rules in the autoloaded option. Only the autoload column varies with this. */
	private const RULE_COUNTS    = [ 1, 10, 50 ];

	/**
	 * Run the sweep.
	 *
	 * Prints one row per grid cell — the median autoload, inline, and pointer
	 * cost in microseconds — followed by the rule for reading the table. Higher
	 * iteration counts buy a steadier median at the cost of runtime.
	 *
	 * ## OPTIONS
	 *
	 * [--iterations=<n>]
	 * : Timed iterations per grid cell. Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nodes ruleset-bench --iterations=500
	 *
	 * @when after_wp_load
	 *
	 * @param array<int,string>    $args       Positional (unused).
	 * @param array<string,mixed>  $assoc_args Flags. A non-numeric or missing
	 *                                          `iterations` falls back to 200;
	 *                                          anything below 1 clamps to 1.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$raw        = $assoc_args['iterations'] ?? null;
		$iterations = ( \is_string( $raw ) || \is_int( $raw ) ) && \is_numeric( $raw ) ? \max( 1, (int) $raw ) : 200;

		\WP_CLI::log( \sprintf( 'hooks/rule x #rules  |  autoload_us  inline_us  pointer_us  (median, %d iters)', $iterations ) );
		\WP_CLI::log( \str_repeat( '-', 72 ) );

		foreach ( self::RULE_COUNTS as $rule_count ) {
			foreach ( self::HOOKS_PER_RULE as $k ) {
				$row = $this->measure_cell( $k, $rule_count, $iterations );
				\WP_CLI::log( \sprintf(
					'%5d x %-3d          |  %9.1f  %8.1f  %9.1f',
					$k,
					$rule_count,
					$row['autoload']['median'],
					$row['inline']['median'],
					$row['pointer']['median']
				) );
			}
		}
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Pick INLINE_HOOK_LIMIT as the largest K where inline_us stays < the pointer_us floor AND autoload tax stays negligible, and which is >= 65.' );
	}

	/**
	 * Measure one grid cell: time the three hook-access paths over a synthetic
	 * hook list of $k names.
	 *
	 * - `autoload` — unserialize a blob of $k × $rule_count hook names, standing
	 *   in for the alloptions cost every request pays for inline storage.
	 * - `inline` — walk the already-in-memory list, standing in for the bind
	 *   loop that inline storage runs per request.
	 * - `pointer` — one memcache get plus the same bind loop.
	 *
	 * Only the pointer path touches memcache, under a bench-private key it sets
	 * with a 300-second TTL and deletes afterwards. With no `Core::$memd` handle
	 * the path degrades to the in-memory array, so the pointer column then
	 * reports the bind loop alone and understates the real cost — read it as a
	 * floor, not a measurement.
	 *
	 * Every path is summarized to median, p95, and n; the sweep prints medians.
	 *
	 * @param int $k          Hooks per rule.
	 * @param int $rule_count Number of rules.
	 * @param int $iterations Timed iterations.
	 * @return array{
	 *   autoload: array{median: float, p95: float, n: int},
	 *   inline: array{median: float, p95: float, n: int},
	 *   pointer: array{median: float, p95: float, n: int}
	 * }
	 */
	private function measure_cell( int $k, int $rule_count, int $iterations ): array {
		$hooks = self::synthetic_hooks( $k );

		// Autoload tax: one blob of K x M hook names; time the unserialize.
		$inline_blob = [];
		for ( $r = 0; $r < $rule_count; $r++ ) {
			$inline_blob[ 'bench_rule_' . $r ] = $hooks;
		}
		$serialized      = \serialize( $inline_blob ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- benchmark of the alloptions serialize/unserialize cost; data is our own string array.
		$autoload_samps  = [];
		for ( $i = 0; $i < $iterations; $i++ ) {
			$t = \hrtime( true );
			\unserialize( $serialized ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- benchmark of the alloptions serialize/unserialize cost; data is our own string array.
			$autoload_samps[] = ( \hrtime( true ) - $t ) / 1000.0;
		}

		// Inline path: the bind loop alone, without real add_filter calls.
		$inline_samps = [];
		for ( $i = 0; $i < $iterations; $i++ ) {
			$t = \hrtime( true );
			// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- the accumulator IS the measurement; dropping it empties the loop.
			$bound = 0;
			foreach ( $hooks as $h ) {
				$bound += \strlen( $h ); // stand-in for 3x add_filter dispatch.
			}
			// phpcs:enable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			$inline_samps[] = ( \hrtime( true ) - $t ) / 1000.0;
		}

		// Pointer path: memcache get + bind.
		$memd    = \Newspack_Nodes\Core::$memd ?? null;
		$mc_key  = 'evlog:bench:hooks:' . $k;
		if ( null !== $memd ) {
			$memd->set( $mc_key, $hooks, 300 );
		}
		$pointer_samps = [];
		for ( $i = 0; $i < $iterations; $i++ ) {
			$t = \hrtime( true );
			$fetched = null !== $memd ? $memd->get( $mc_key ) : $hooks;
			if ( \is_array( $fetched ) ) {
				// phpcs:disable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- the accumulator IS the measurement; dropping it empties the loop.
				$bound = 0;
				foreach ( $fetched as $h ) {
					$bound += \is_string( $h ) ? \strlen( $h ) : 0;
				}
				// phpcs:enable VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
			}
			$pointer_samps[] = ( \hrtime( true ) - $t ) / 1000.0;
		}
		if ( null !== $memd ) {
			$memd->delete( $mc_key );
		}

		return [
			'autoload' => self::summarize( $autoload_samps ),
			'inline'   => self::summarize( $inline_samps ),
			'pointer'  => self::summarize( $pointer_samps ),
		];
	}

	/**
	 * Reduce a sample list (microseconds) to median / p95 / n.
	 *
	 * Both quantiles are nearest-rank picks from the sorted samples, never
	 * interpolated: an even-sized list takes the lower of the two middles. An
	 * empty list reports zeros rather than dividing by nothing. `$samples` is
	 * taken by value, so the caller's order survives the sort.
	 *
	 * @param float[] $samples Raw timings.
	 * @return array{median: float, p95: float, n: int}
	 */
	public static function summarize( array $samples ): array {
		$n = \count( $samples );
		if ( 0 === $n ) {
			return [ 'median' => 0.0, 'p95' => 0.0, 'n' => 0 ];
		}
		\sort( $samples );
		$median = $samples[ (int) \floor( ( $n - 1 ) / 2 ) ];
		$p95    = $samples[ (int) \ceil( 0.95 * ( $n - 1 ) ) ];
		return [ 'median' => $median, 'p95' => $p95, 'n' => $n ];
	}

	/**
	 * Deterministic synthetic hook-name list: `bench_hook_0` … `bench_hook_N-1`.
	 *
	 * Names are unique and realistically sized, so repeated runs of the same
	 * grid cell measure the same work.
	 *
	 * @param int $count How many.
	 * @return string[]
	 */
	public static function synthetic_hooks( int $count ): array {
		$hooks = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$hooks[] = 'bench_hook_' . $i;
		}
		return $hooks;
	}
}
