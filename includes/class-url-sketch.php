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
 * as the raw deflate of the binary string of those bytes. That one form is
 * what every reader and writer holds: the registers are inflated inside a
 * union or an estimate and never leave it. A small set's registers are
 * nearly all empty, so its sketch is a few hundred bytes where the registers
 * are 16 KiB, and a dense one about 7.5 KiB.
 */
final class Url_Sketch {

	/** Register-index bits: 16,384 registers, ~0.8% standard error. */
	public const PRECISION = 14;

	/** Bytes a sketch's registers inflate to: one a register. */
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
	 * The sketch of every set: each register's largest value; of none, the
	 * empty sketch.
	 *
	 * Eight registers a word rather than one a step, a quarter of the time
	 * over a reply's hundred-odd unions, and the words stay unpacked across
	 * the whole fold, packed once at the end. It needs every register under
	 * 64, which a rank of at most `RANK_BITS + 1` (35) keeps: `$lanes |
	 * LANE_TOP` less the other word then borrows across no lane, and each
	 * lane's bit 6 says whether this word's register is the larger.
	 *
	 * @param string ...$sketches Sketches.
	 */
	public static function union( string ...$sketches ): string {
		/** @var array<int,int> $ours `P*` unpacks to ints alone. */
		$ours = \unpack( 'P*', self::registers( \array_shift( $sketches ) ?? self::of( [] ) ) ) ?: [];
		foreach ( $sketches as $sketch ) {
			/** @var array<int,int> $theirs */
			$theirs = \unpack( 'P*', self::registers( $sketch ) ) ?: [];
			foreach ( $ours as $at => $lanes ) {
				$other       = $theirs[ $at ];
				$larger      = ( ( ( $lanes | self::LANE_TOP ) - $other ) & self::LANE_TOP ) >> 6;
				$mask        = $larger * self::LANE_MASK;
				$ours[ $at ] = ( $lanes & $mask ) | ( $other & ~$mask );
			}
		}
		return self::stored( \pack( 'P*', ...$ours ) );
	}

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
		return self::stored( $registers );
	}

	/**
	 * The stored form of a sketch's registers, at zlib's fastest level: a
	 * header record is under 1% of a ranking's bytes, and the default level
	 * costs up to twenty times the time for about a fifth fewer of them.
	 *
	 * @param string $registers `BYTES` registers.
	 */
	private static function stored( string $registers ): string {
		return (string) \gzdeflate( $registers, 1 );
	}

	/**
	 * How many distinct hashes a sketch holds, estimated.
	 *
	 * @param string $sketch A sketch.
	 */
	public static function estimate( string $sketch ): int {
		$m     = self::BYTES;
		$sum   = 0.0;
		$zeros = 0;
		foreach ( \count_chars( self::registers( $sketch ), 1 ) as $rank => $registers ) {
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

	/**
	 * A sketch's registers, refusing a value that is not one.
	 *
	 * @param string $sketch A sketch.
	 * @throws \InvalidArgumentException When `$sketch` is not a sketch.
	 */
	private static function registers( string $sketch ): string {
		return self::inflated( $sketch ) ?? throw new \InvalidArgumentException( 'not a URL sketch' );
	}

	/**
	 * Whether a stored value is a sketch: a raw deflate stream that inflates
	 * to exactly `BYTES` registers.
	 *
	 * @param string $sketch A stored value.
	 */
	public static function is_sketch( string $sketch ): bool {
		return null !== self::inflated( $sketch );
	}

	/**
	 * The registers a stored value inflates to, or null where it is no
	 * sketch: not a deflate stream, or one of another length. Inflating
	 * stops at `BYTES` plus one, so no value inflates past what a sketch is.
	 *
	 * @param string $sketch A stored value.
	 */
	private static function inflated( string $sketch ): ?string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- zlib reports a stream that is not one only as a warning; here that is a miss.
		\set_error_handler( static fn (): bool => true );
		try {
			$registers = \gzinflate( $sketch, self::BYTES + 1 );
		} finally {
			\restore_error_handler();
		}
		return \is_string( $registers ) && self::BYTES === \strlen( $registers ) ? $registers : null;
	}
}
