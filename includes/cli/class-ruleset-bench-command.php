<?php
/**
 * Phase-0 benchmark: autoloaded-inline vs memcache-pointer hook storage.
 *
 * Populates the rules option across a sweep and measures, per grid cell, the
 * three per-request costs the ruleset design trades off. Run once, in-container,
 * to pick the INLINE_HOOK_LIMIT crossover N before the engine freezes it.
 *
 * @package Newspack_Event_Logger_Nodes
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\CLI;

\defined( 'ABSPATH' ) || exit;

/**
 * `wp nodes ruleset-bench` — measurement only. Not on the request hot path.
 */
class Ruleset_Bench_Command {

	private const HOOKS_PER_RULE = [ 50, 65, 100, 250, 500, 1000, 2500, 5000 ];
	private const RULE_COUNTS    = [ 1, 10, 50 ];

	/**
	 * Run the sweep.
	 *
	 * ## OPTIONS
	 *
	 * [--iterations=<n>]
	 * : Timed iterations per grid cell. Default 200.
	 *
	 * [--path=<path>]
	 * : WordPress path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nodes ruleset-bench --iterations=500
	 *
	 * @when after_wp_load
	 *
	 * @param array<int, string>    $args       Positional (unused).
	 * @param array<string, mixed>  $assoc_args Flags.
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
	 * Measure one grid cell. Populates raw options + a memcache mirror, then
	 * times the three access paths.
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

		// Autoload tax: K×M inline hooks in one autoloaded option; time unserialize.
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

		// Inline path: read array + bind loop (no add_filter; measure loop cost).
		$inline_samps = [];
		for ( $i = 0; $i < $iterations; $i++ ) {
			$t = \hrtime( true );
			$bound = 0;
			foreach ( $hooks as $h ) {
				$bound += \strlen( $h ); // stand-in for 3x add_filter dispatch.
			}
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
				$bound = 0;
				foreach ( $fetched as $h ) {
					$bound += \is_string( $h ) ? \strlen( $h ) : 0;
				}
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
	 * Deterministic synthetic hook-name list.
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

	/**
	 * Reduce a sample list (microseconds) to median / p95 / n.
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
}
