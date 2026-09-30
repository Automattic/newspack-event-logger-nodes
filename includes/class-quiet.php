<?php
/**
 * Quiet
 *
 * When a builder has consumed nothing for long enough to take the wall clock.
 *
 * Both builders run on STREAM time, the stamps of what they consume, so a
 * reprocess of the firehose files what live traffic did: the request builder
 * times a request out on the newest entry stamp it has read, and the flame
 * builder folds an hour once its data clock has left it. A stream that stops
 * leaves that clock standing, so a quiet builder takes the wall instead: a
 * request whose worker died still times out, and the hours the wall closes
 * still fold.
 *
 * Quiet is a duration inside one process, so it is measured on `hrtime()`,
 * never the wall: a wall clock stepped forward is no quiet, and a minute of
 * monotonic silence is, whatever the wall says.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one quiet rule both builders read.
 */
final class Quiet {

	/**
	 * Seconds a builder consumes nothing before it is quiet: twelve of the
	 * flame builder's flushes, a minute. A replay drains at every flush and
	 * every checkpoint, and its slowest measured flush, 15.8 s, fits three
	 * times over, while a builder quiet from an hour's close still folds it
	 * inside the next hour's first bucket, the lag a reader forgives
	 * (`lagging()`). The request builder consumes on every tick of a replay,
	 * and its window is six minutes, so a minute costs a dead worker's
	 * request no whole window.
	 */
	public const AFTER_SEC = 12 * Flame_Builder_Node::FLUSH_INTERVAL_SEC;

	/**
	 * Monotonic clock seam, replacing `hrtime( true )` where a builder times
	 * its own quiet or a wait. Tests reassign it to step the monotonic clock apart from
	 * the wall one. Signature: `function (): int`, nanoseconds.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $hrtime_fn = null;

	/**
	 * Whether `AFTER_SEC` of monotonic time has passed since `$mark`.
	 *
	 * @param int $mark A `mark()` the builder stamped when it last worked.
	 */
	public static function since( int $mark ): bool {
		return self::mark() - $mark >= self::AFTER_SEC * 1_000_000_000;
	}

	/**
	 * The monotonic clock in ns: the mark a builder stamps when it works, and
	 * the clock any wait inside a builder is timed on.
	 */
	public static function mark(): int {
		return Core::num_int( ( self::$hrtime_fn ?? static fn (): int => (int) \hrtime( true ) )() );
	}
}
