<?php
/**
 * Query Monitor's actions — its timers and its PSR-3 logger — as entries on
 * the logged request.
 *
 * Code instrumented for Query Monitor calls `do_action( 'qm/start', $name )`,
 * `qm/lap` and `qm/stop` for a timer, and `qm/debug` through `qm/emergency`
 * with a message and a context array for a log line, whether QM is installed
 * or not. `App\Core` binds these listeners on any request a rule logs, so that
 * instrumentation reaches the firehose without a rule naming it.
 *
 * Mirrors `QM_Collector_Timing` and `QM_Collector_Logger`: the same actions at
 * the same arity, the same "Timer not started" and "Timer not stopped"
 * warnings, a restart starting a timer over, and the logger's message
 * conversion and `{placeholder}` interpolation — except that a context value
 * is shaped as a hook argument is before it is interpolated, because the
 * firehose replicates to the hub.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Nodes\Core as RuntimeCore;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query Monitor's timing and logging actions, written into the started record.
 *
 * A stopped timer is one `qm` span labelled with the timer's name. Its pair is
 * written at the STOP, its start back-dated to the moment the timer started
 * and its laps between, because a timer may straddle any hook: written at its
 * start, the open span would sit on the stack under every span opened after
 * it, and the first of those to close would unwind it.
 *
 * Written at the stop, the pair nests inside whatever is open then. A timer
 * stopped while another that started before it runs is held in that one and
 * written inside it at its stop, so nested timers come out nested. One that
 * started before the span it is written in is CLAMPED to that span's start,
 * so no child opens before its parent; its close says how long it really ran
 * and how much of that came first. Its time is charged to no category of
 * `Log_Manager::timing_so_far()`, and `Request_Builder_Node` takes it from no
 * ancestor: it overlays time the spans around it already count.
 */
class Query_Monitor_Actions {

	/** The state a timer's span is logged under; its `l` is the timer's name. */
	public const STATE = Flame_Tree::QM_STATE;

	/** Category of a lap, an entry inside its timer's span; `m` is its name. */
	public const LAP = 'qm lap';

	/** The PSR-3 levels, in QM's order, most severe first. */
	public const LEVELS = [ 'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug' ];

	/**
	 * Laps and stopped inner timers a timer holds until its stop; later ones
	 * are counted on its close, not kept.
	 */
	public const MAX_ITEMS = 50;

	/** Timers open at once; a start past it is refused, as a stop finds out. */
	public const MAX_OPEN = 100;

	/** Characters kept from a timer's or a lap's name. */
	private const NAME_MAX = 128;

	/**
	 * Open timers by name: the wall-clock start the span's `(start)` carries,
	 * the monotonic one its extent is read from, the laps and stopped inner
	 * timers held for its stop, and those past `MAX_ITEMS`.
	 *
	 * A name of digits is an int key, so a key is cast back to its name.
	 *
	 * @var array<array-key,array{ts: float, hr: int|float, items: list<array<string,mixed>>, dropped: int}>
	 */
	private array $open = [];

	/**
	 * The listener per level, built once so `App\Core` removes the very
	 * closure it added.
	 *
	 * @var array<string,\Closure(mixed, mixed=): void>
	 */
	private array $levels = [];

	/**
	 * Build the per-level listeners.
	 */
	public function __construct() {
		foreach ( self::LEVELS as $level ) {
			$this->levels[ $level ] = function ( mixed $message, mixed $context = [] ) use ( $level ): void {
				$this->log( $level, $message, RuntimeCore::arr( $context, [] ) );
			};
		}
	}

	/**
	 * `qm/start`: start a timer, or start a running one over.
	 *
	 * @param mixed $name The timer's name.
	 */
	public function start( mixed $name ): void {
		if ( null === Log_Manager::started_instance() ) {
			return;
		}
		$name = self::name_of( $name );
		if ( ! isset( $this->open[ $name ] ) && \count( $this->open ) >= self::MAX_OPEN ) {
			return;
		}
		$this->open[ $name ] = [
			'ts'      => RuntimeCore::right_now(),
			'hr'      => \hrtime( true ),
			'items'   => [],
			'dropped' => 0,
		];
	}

	/**
	 * `qm/stop`: hold the timer in the running one it started inside, or
	 * write it now, inside whatever span is open.
	 *
	 * @param mixed $name The timer's name.
	 */
	public function stop( mixed $name ): void {
		$lm = Log_Manager::started_instance();
		if ( null === $lm ) {
			return;
		}
		$name  = self::name_of( $name );
		$timer = $this->open[ $name ] ?? null;
		if ( null === $timer ) {
			$lm->message( self::STATE . ' (warning)', [ 'm' => 'Timer not started', 'l' => $name ] );
			return;
		}
		unset( $this->open[ $name ] );
		$record = [ 'name' => $name, 'stop' => \hrtime( true ) ] + $timer;
		$outer  = $this->outer_of( $timer['hr'] );
		if ( null !== $outer ) {
			$this->hold( $outer, [ 'timer' => $record ] );
			return;
		}
		$this->write( $lm, $record, $lm->open_since() ?? $timer );
	}

	/**
	 * The running timer a timer started at `$hr` sits inside: the latest one
	 * started no later than it, or null when none is running.
	 *
	 * @param int|float $hr The timer's monotonic start.
	 */
	private function outer_of( int|float $hr ): ?string {
		$outer = null;
		foreach ( $this->open as $name => $timer ) {
			if ( $timer['hr'] <= $hr && ( null === $outer || $timer['hr'] >= $this->open[ $outer ]['hr'] ) ) {
				$outer = (string) $name;
			}
		}
		return $outer;
	}

	/**
	 * `qm/lap`: mark a moment inside a running timer.
	 *
	 * @param mixed $name The timer's name.
	 * @param mixed $lap  The lap's name; QM's default is none.
	 */
	public function lap( mixed $name, mixed $lap = null ): void {
		$lm = Log_Manager::started_instance();
		if ( null === $lm ) {
			return;
		}
		$name = self::name_of( $name );
		if ( ! isset( $this->open[ $name ] ) ) {
			$lm->message( self::STATE . ' (warning)', [ 'm' => 'Timer not started', 'l' => $name ] );
			return;
		}
		$this->hold( $name, [ 'lap' => self::name_of( $lap ), 'ts' => RuntimeCore::right_now() ] );
	}

	/**
	 * Hold a lap or a stopped inner timer for a running timer's stop, or
	 * count it past `MAX_ITEMS`.
	 *
	 * @param string               $name The running timer.
	 * @param array<string,mixed> $item The lap or the timer.
	 */
	private function hold( string $name, array $item ): void {
		if ( \count( $this->open[ $name ]['items'] ) >= self::MAX_ITEMS ) {
			++$this->open[ $name ]['dropped'];
			return;
		}
		$this->open[ $name ]['items'][] = $item;
	}

	/**
	 * A timer's or a lap's name: the scalar as text, control characters
	 * stripped, cut at `NAME_MAX`.
	 *
	 * @param mixed $name What the caller passed.
	 */
	private static function name_of( mixed $name ): string {
		$text = \is_scalar( $name ) ? (string) $name : '';
		return \mb_substr( (string) \preg_replace( '/[\x00-\x1F\x7F]/', '', $text ), 0, self::NAME_MAX );
	}

	/**
	 * `shutdown`: a timer still open is dropped with QM's warning, as QM's
	 * own `process()` drops it, and the timers stopped inside it are written
	 * where they stand. Nothing was written at its start, so nothing is left
	 * open in the record.
	 */
	public function finish(): void {
		$lm         = Log_Manager::started_instance();
		$open       = $this->open;
		$this->open = [];
		if ( null === $lm ) {
			return;
		}
		foreach ( $open as $name => $timer ) {
			foreach ( $timer['items'] as $item ) {
				if ( isset( $item['timer'] ) ) {
					$this->write( $lm, RuntimeCore::arr( $item['timer'], [] ), $lm->open_since() ?? $timer );
				}
			}
			$lm->message( self::STATE . ' (warning)', [ 'm' => 'Timer not stopped', 'l' => (string) $name ] );
		}
	}

	/**
	 * Write a stopped timer's span inside the span that opened at `$floor`,
	 * clamped to it, its laps and inner timers between.
	 *
	 * @param Log_Manager            $lm     The started record.
	 * @param array<array-key,mixed> $record The stopped timer.
	 * @param array<array-key,mixed> $floor  The `hr` and `ts` it may not start before.
	 */
	private function write( Log_Manager $lm, array $record, array $floor ): void {
		$name     = RuntimeCore::as_string( $record['name'] ?? '', '' );
		$began_hr = RuntimeCore::num_float( $record['hr'] ?? 0 );
		$floor_hr = RuntimeCore::num_float( $floor['hr'] ?? 0 );
		$clamped  = $floor_hr > $began_hr;
		$from_hr  = $clamped ? $floor_hr : $began_hr;
		$from_ts  = RuntimeCore::num_float( $clamped ? ( $floor['ts'] ?? 0 ) : ( $record['ts'] ?? 0 ) );
		$stop_hr  = RuntimeCore::num_float( $record['stop'] ?? 0 );
		$lm->message( self::STATE . ' (start)', [ 'l' => $name, 'ts' => $from_ts ] );
		foreach ( RuntimeCore::arr( $record['items'] ?? null, [] ) as $item ) {
			$item = RuntimeCore::arr( $item, [] );
			if ( isset( $item['timer'] ) ) {
				$this->write( $lm, RuntimeCore::arr( $item['timer'], [] ), [ 'hr' => $from_hr, 'ts' => $from_ts ] );
				continue;
			}
			$lm->message( self::LAP, [ 'm' => $item['lap'] ?? '', 'l' => $name, 'ts' => \max( $from_ts, RuntimeCore::num_float( $item['ts'] ?? 0 ) ) ] );
		}
		$notes = [];
		if ( $clamped ) {
			$notes[] = \sprintf( 'ran %.3Fms, %.3Fms of it before its enclosing span', ( $stop_hr - $began_hr ) / 1_000_000, ( $from_hr - $began_hr ) / 1_000_000 );
		}
		$dropped = RuntimeCore::num_int( $record['dropped'] ?? 0 );
		if ( $dropped > 0 ) {
			$notes[] = "{$dropped} laps or timers not recorded";
		}
		$done = [ 'l' => $name, 'duration_ms' => \max( 0, ( $stop_hr - $from_hr ) / 1_000_000 ) ];
		if ( [] !== $notes ) {
			$done['m'] = \implode( '; ', $notes );
		}
		$lm->message( self::STATE . ' (complete)', $done );
	}

	/**
	 * What `App\Core` binds on a logged request: each action at QM's own
	 * arity, and the shutdown sweep, at the last priority so it runs after a
	 * rule's `shutdown` span has closed.
	 *
	 * @return list<array{0: string, 1: callable, 2: int, 3: int}> Hook, callback, priority, accepted args.
	 */
	public function listeners(): array {
		$listeners = [
			[ 'qm/start', [ $this, 'start' ], 10, 1 ],
			[ 'qm/stop', [ $this, 'stop' ], 10, 1 ],
			[ 'qm/lap', [ $this, 'lap' ], 10, 2 ],
		];
		foreach ( $this->levels as $level => $listener ) {
			$listeners[] = [ "qm/{$level}", $listener, 10, 2 ];
		}
		$listeners[] = [ 'shutdown', [ $this, 'finish' ], PHP_INT_MAX, 0 ];
		return $listeners;
	}

	/**
	 * Forget every open timer: a scope change opens a record their stop
	 * does not belong to.
	 */
	public function reset(): void {
		$this->open = [];
	}

	/**
	 * Write one log line at its level, as `qm (<level>)`: the `(warning)`
	 * and more severe suffixes reach the Errors stream, as `Log_Manager`'s
	 * `warning()` and `error()` do. `message()` redacts the line's URLs.
	 *
	 * @param string                 $level   One of `LEVELS`.
	 * @param mixed                  $message What the caller logged.
	 * @param array<array-key,mixed> $context Placeholder values.
	 */
	private function log( string $level, mixed $message, array $context ): void {
		Log_Manager::started_instance()?->message(
			self::STATE . " ({$level})",
			[ 'm' => self::interpolate( self::text_of( $message ), $context ) ]
		);
	}

	/**
	 * A logged message as text, by `QM_Collector_Logger::store()`'s rules,
	 * except that data QM would `print_r()` is shaped as a hook argument is,
	 * and a throwable is `Log_Manager::thrown()`'s line, so a driver's never
	 * carries the values it quoted.
	 *
	 * @param mixed $message What the caller logged.
	 */
	private static function text_of( mixed $message ): string {
		if ( $message instanceof \WP_Error ) {
			return \sprintf( 'WP_Error: %s (%s)', $message->get_error_message(), RuntimeCore::as_string( $message->get_error_code(), '' ) );
		}
		if ( $message instanceof \Throwable ) {
			return Log_Manager::thrown( $message );
		}
		if ( null === $message ) {
			return 'null';
		}
		if ( \is_bool( $message ) ) {
			return $message ? 'true' : 'false';
		}
		if ( \is_array( $message ) || \is_object( $message ) ) {
			return (string) \wp_json_encode( Core::shaped_argument( $message ), JSON_UNESCAPED_SLASHES, 16 );
		}
		$text = RuntimeCore::as_string( $message, '' );
		return '' === \trim( $text ) ? '(Empty string)' : $text;
	}

	/**
	 * QM's `{key}` interpolation, each value shaped first: a scalar under an
	 * allowlisted key as itself, under any other as `?`, an object as its
	 * class and anything else as its type. A placeholder the context lacks
	 * stays as written.
	 *
	 * @param string                 $message The message text.
	 * @param array<array-key,mixed> $context Placeholder values.
	 */
	private static function interpolate( string $message, array $context ): string {
		$replace = [];
		foreach ( $context as $key => $value ) {
			$replace[ '{' . $key . '}' ] = match ( true ) {
				\is_object( $value )    => '[' . \get_class( $value ) . ']',
				! \is_scalar( $value )  => '[' . \gettype( $value ) . ']',
				default                 => self::shaped_scalar( (string) $key, $value ),
			};
		}
		return \strtr( $message, $replace );
	}

	/**
	 * One scalar context value as a hook argument's leaf would read.
	 *
	 * @param string           $key   Its context key.
	 * @param bool|int|float|string $value The value.
	 */
	private static function shaped_scalar( string $key, bool|int|float|string $value ): string {
		$shaped = RuntimeCore::arr( Core::shaped_argument( [ $key => $value ] ), [] )[ $key ] ?? '?';
		return \is_bool( $shaped ) ? ( $shaped ? 'true' : 'false' ) : RuntimeCore::as_string( $shaped, '?' );
	}
}
