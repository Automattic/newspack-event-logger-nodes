<?php
/**
 * Bucket
 *
 * The five-minute bucket and the hour: ids from an epoch, the `Y-m-d-H-i`
 * key a reader spells a bucket with, and the selections a reader names.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A bucket id is `floor( epoch / 300 )` and an hour id `floor( epoch / 3600 )`.
 * Ids are integers so a range scans; `key()` spells one for a reader, and
 * lexical order of keys is chronological order.
 */
final class Bucket {

	/** Seconds a bucket spans; the geometry every rate over buckets divides by. */
	public const SECONDS = 300;

	/** Seconds an hour spans. */
	public const HOUR_SECONDS = 3600;

	/** Most buckets a selection names. */
	public const MAX_SELECTION = 288;

	/**
	 * The buckets a selection's spelling names, as ascending ranges of
	 * bucket ids that neither touch nor overlap.
	 *
	 * A spelling is comma-separated runs, each `start` or `start..end` over
	 * `key()`s; `spelling()` writes the canonical one, which the dashboard's
	 * address bar, `--bucket` and the MCP `bucket` argument all carry. ''
	 * selects nothing. Runs are counted, never walked, so no spelling
	 * enumerates more than it names.
	 *
	 * @param string $spelling A selection's spelling.
	 * @param int    $now      The clock; a bucket after the one it falls in holds no rows.
	 * @return list<array{0:int,1:int}> First and last bucket id of each run.
	 * @throws \InvalidArgumentException On a piece that is no five-minute key, a run
	 *                                   ending before it starts, a bucket after
	 *                                   `$now`'s, or more than `MAX_SELECTION` buckets.
	 */
	public static function selection( string $spelling, int $now ): array {
		if ( '' === $spelling ) {
			return [];
		}
		$latest = self::id( $now );
		$runs   = [];
		foreach ( \explode( ',', $spelling ) as $run ) {
			$ends  = \explode( '..', $run, 2 );
			$first = self::of_key( $ends[0] );
			$last  = self::of_key( $ends[1] ?? $ends[0] );
			if ( $last < $first ) {
				throw new \InvalidArgumentException( "bucket run {$run} ends before it starts" );
			}
			if ( $last > $latest ) {
				throw new \InvalidArgumentException( 'bucket ' . self::key( $last ) . ' is in the future' );
			}
			$runs[] = [ $first, $last ];
		}
		$ranges = self::merged( $runs );
		$count  = \array_sum( \array_map( static fn ( array $range ): int => $range[1] - $range[0] + 1, $ranges ) );
		if ( $count > self::MAX_SELECTION ) {
			throw new \InvalidArgumentException( 'bucket selection holds more than ' . self::MAX_SELECTION . ' buckets' );
		}
		return $ranges;
	}

	/**
	 * Sort runs and join the ones that overlap or touch.
	 *
	 * @param list<array{0:int,1:int}> $runs First and last bucket id of each run.
	 * @return list<array{0:int,1:int}>
	 */
	private static function merged( array $runs ): array {
		\usort( $runs, static fn ( array $a, array $b ): int => $a[0] <=> $b[0] );
		$ranges = [];
		foreach ( $runs as $run ) {
			$at = \array_key_last( $ranges );
			if ( null !== $at && $run[0] <= $ranges[ $at ][1] + 1 ) {
				$ranges[ $at ][1] = \max( $ranges[ $at ][1], $run[1] );
			} else {
				$ranges[] = $run;
			}
		}
		return $ranges;
	}

	/**
	 * The bucket a key spells, read in UTC.
	 *
	 * @param string $key A `key()`.
	 * @throws \InvalidArgumentException On a key that does not parse or sits off the five-minute grid.
	 */
	public static function of_key( string $key ): int {
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d-H-i', $key, new \DateTimeZone( 'UTC' ) );
		$id    = false === $start ? null : self::id( $start->getTimestamp() );
		if ( null === $id || self::key( $id ) !== $key ) {
			throw new \InvalidArgumentException( "bucket {$key} must be a five-minute key, Y-m-d-H-i in UTC" );
		}
		return $id;
	}

	/**
	 * The `$n` buckets ending with the one `$now` falls in, as the one
	 * range a selection answers.
	 *
	 * @param int $n   How many buckets.
	 * @param int $now The clock.
	 * @return list<array{0:int,1:int}>
	 */
	public static function last( int $n, int $now ): array {
		$latest = self::id( $now );
		return [ [ $latest - $n + 1, $latest ] ];
	}

	/**
	 * The bucket an epoch falls in.
	 *
	 * @param int $epoch Unix timestamp.
	 */
	public static function id( int $epoch ): int {
		return \intdiv( $epoch, self::SECONDS );
	}

	/**
	 * A selection's canonical spelling: its ranges as runs, a lone bucket
	 * spelled alone.
	 *
	 * @param list<array{0:int,1:int}> $ranges Ascending, as `selection()` answers.
	 */
	public static function spelling( array $ranges ): string {
		return \implode(
			',',
			\array_map(
				static fn ( array $range ): string => $range[0] === $range[1] ? self::key( $range[0] ) : self::key( $range[0] ) . '..' . self::key( $range[1] ),
				$ranges
			)
		);
	}

	/**
	 * A bucket's key: the `Y-m-d-H-i` UTC minute it opens.
	 *
	 * @param int $bucket A bucket id.
	 */
	public static function key( int $bucket ): string {
		return \gmdate( 'Y-m-d-H-i', $bucket * self::SECONDS );
	}

	/**
	 * The hour an epoch falls in.
	 *
	 * @param int $epoch Unix timestamp.
	 */
	public static function hour( int $epoch ): int {
		return \intdiv( $epoch, self::HOUR_SECONDS );
	}
}
