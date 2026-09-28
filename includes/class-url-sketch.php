<?php
/**
 * Url Sketch
 *
 * A HyperLogLog over url_hashes: the distinct-URL count a header record
 * carries, at a fixed size, mergeable by union.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A distinct count of url_hashes in `2^PRECISION` one-byte registers, stored
 * as the binary string of those bytes.
 */
final class Url_Sketch {

	/** Register-index bits: 16,384 registers, 16KB, ~0.8% standard error. */
	public const PRECISION = 14;

	/** Bytes a sketch takes: one a register. */
	public const BYTES = 1 << self::PRECISION;

	/** Bits of a url_hash: twelve hex digits. */
	private const HASH_BITS = 48;

	/** Bits the rank is taken over, below the register index. */
	private const RANK_BITS = self::HASH_BITS - self::PRECISION;

	/** Bit 6 of each of a word's eight register lanes. */
	private const LANE_TOP = 0x4040404040404040;

	/** The six bits a register can use, in each lane. */
	private const LANE_MASK = 0x3F;

	/**
	 * A sketch of `$hashes`.
	 *
	 * Each hash is mixed through xxh64 before its bits are read. A url_hash
	 * is FNV-1a, whose top bits barely move between URLs that differ only at
	 * the end, `/p/41` and `/p/42`, so the register index read straight off
	 * it clusters, and a sketch of sequential post URLs reads 19% short.
	 *
	 * @param iterable<string> $hashes url_hashes.
	 */
	public static function of( iterable $hashes ): string {
		$registers = \str_repeat( "\0", self::BYTES );
		foreach ( $hashes as $hash ) {
			$bits = (int) \hexdec( \substr( \hash( 'xxh64', $hash ), 0, self::HASH_BITS / 4 ) );
			$at   = $bits >> self::RANK_BITS;
			$rest = $bits & ( ( 1 << self::RANK_BITS ) - 1 );
			// The leading zeros of the rest, plus one; all zeros ranks highest.
			$rank = self::RANK_BITS + 1 - ( 0 === $rest ? 0 : \strlen( \decbin( $rest ) ) );
			if ( $rank > \ord( $registers[ $at ] ) ) {
				$registers[ $at ] = \chr( $rank );
			}
		}
		return $registers;
	}

	/**
	 * The sketch of both sets: each register's larger value.
	 *
	 * Eight registers a word rather than one a step, a quarter of the time
	 * over a reply's hundred-odd unions. It needs every register under 64,
	 * which a rank of at most `RANK_BITS + 1` (35) keeps: `$lanes | LANE_TOP`
	 * less the other word then borrows across no lane, and each lane's bit 6
	 * says whether this word's register is the larger.
	 *
	 * @param string $a A sketch.
	 * @param string $b Another.
	 */
	public static function union( string $a, string $b ): string {
		$ours   = \array_filter( \unpack( 'P*', $a ) ?: [], 'is_int' );
		$theirs = \array_filter( \unpack( 'P*', $b ) ?: [], 'is_int' );
		foreach ( $ours as $at => $lanes ) {
			$other       = $theirs[ $at ];
			$larger      = ( ( ( $lanes | self::LANE_TOP ) - $other ) & self::LANE_TOP ) >> 6;
			$mask        = $larger * self::LANE_MASK;
			$ours[ $at ] = ( $lanes & $mask ) | ( $other & ~$mask );
		}
		return \pack( 'P*', ...$ours );
	}

	/**
	 * How many distinct hashes a sketch holds, estimated.
	 *
	 * @param string $sketch A sketch.
	 */
	public static function estimate( string $sketch ): int {
		$m     = \strlen( $sketch );
		$sum   = 0.0;
		$zeros = 0;
		foreach ( \count_chars( $sketch, 1 ) as $rank => $registers ) {
			$sum += $registers * 2 ** -$rank;
			if ( 0 === $rank ) {
				$zeros = $registers;
			}
		}
		$estimate = 0.7213 / ( 1 + 1.079 / $m ) * $m * $m / $sum;
		// Linear counting is the better estimate while registers stand empty.
		if ( $estimate <= 2.5 * $m && $zeros > 0 ) {
			$estimate = $m * \log( $m / $zeros );
		}
		return (int) \round( $estimate );
	}
}
