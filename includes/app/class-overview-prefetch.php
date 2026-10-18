<?php
/**
 * Overview_Prefetch: the URL snapshot and the Overview's other default
 * answers, built once an interval by a JobWorker job and served by the verbs
 * until the snapshot expires.
 *
 * `Overview_Prefetch_Tick_Node` queues each `eln_overview_prefetch` job, and
 * a JobWorker runs it. The job builds the window's URL table as one
 * `Url_Snapshot`, which serves `SNAPSHOT_INTERVALS` intervals
 * from the end of its build, and answers the questions in
 * `stored-answers.json` live inside it, a template naming its server
 * `SERVER` once for each server the window holds: `urls` reads any page of
 * it, `overview` and `query` the stored answer to the same bound arguments,
 * and any other question, a stale snapshot or none is answered live
 * (decision 37).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Flame_Tree;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The job and the key its answers are stored under.
 */
final class Overview_Prefetch {

	/** The job handler, queued once an interval by `Overview_Prefetch_Tick_Node`. */
	public const HANDLER = 'eln_overview_prefetch';

	/**
	 * Intervals a snapshot serves from the end of its build: a run a respawn
	 * lost and the run after it fit inside while each run publishes within
	 * two intervals of being queued.
	 */
	public const SNAPSHOT_INTERVALS = 4;

	/** The least interval, in seconds, a job names and the tick waits. */
	public const MIN_INTERVAL_S = 1;

	/** The greatest interval, in seconds, a job names and the tick waits. */
	public const MAX_INTERVAL_S = 900;

	/** Bytes of a refused value's JSON a refusal names, so a dead letter stays short. */
	private const GIVEN_BYTES = 64;

	/** The placeholder a stored question names its server by. */
	public const SERVER = '{server}';

	/**
	 * file_get_contents seam for `stored-answers.json`. Tests reassign it to
	 * seed questions the shipped file does not hold, so `stored()` runs its
	 * own checks over them.
	 *
	 * Signature: `function ( string $path ): string|false`.
	 *
	 * @var (\Closure(string): (string|false))|null
	 */
	public static ?\Closure $read_file = null;

	/**
	 * The job: build the URL snapshot, which serves `SNAPSHOT_INTERVALS`
	 * intervals from the end of its build, and answer every question live
	 * inside it, a template once for each server its totals sum and the
	 * overview naming them. An answer the stats could not open for is never
	 * stored, so the verb asks again live. A job `overtaken()` names
	 * runs nothing, since a later listener can overwrite `admit()`'s
	 * decline; a job `queued()` refuses fails here, where the JobWorker
	 * records the error and its Consumer dead-letters the entry. On a
	 * started record each answer is a `prefetch answer` span labelled with
	 * its question's name.
	 *
	 * The job runs as one unit no cooperative stop splits: each span line is
	 * a Partition write, and so a point a stop could rise, and a stop inside
	 * the build would discard it. A stop that falls due raises at the first
	 * pump after the publish.
	 *
	 * @api The `eln_overview_prefetch` job handler.
	 * @param string              $id         Unused; the job names none.
	 * @param array<string,mixed> $parameters `queued_at` and `interval`.
	 * @throws \InvalidArgumentException On a job `queued()` refuses, and as
	 *                                   `Url_Snapshot::publish()`.
	 * @throws \Newspack_Nodes\Table_Unavailable As `Url_Snapshot::publish()`.
	 * @throws \RuntimeException As `Url_Snapshot::publish()`.
	 * @throws \LogicException As `stored()`.
	 */
	public static function run( string $id, array $parameters ): void {
		$queued = self::queued( $parameters );
		if ( \is_string( $queued ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \InvalidArgumentException( "overview prefetch: {$queued}" );
		}
		[ $queued_at, $interval ] = $queued;
		$now                      = (int) Core::right_now();
		if ( self::overtaken( $now, $queued_at, $interval ) ) {
			return;
		}
		$questions = self::stored();
		Event_Framework::instance()->uninterruptible(
			static function () use ( $questions, $now, $interval ): void {
				Url_Snapshot::publish( $now, self::SNAPSHOT_INTERVALS * $interval, static fn ( array $servers ): array => self::answers( $questions, $now, $servers ) );
			}
		);
	}

	/**
	 * Each of `$questions`' live answer by key, a template's once for each
	 * of `$servers`, keyed as that server's poll binds it, and an `overview`
	 * naming `$servers`; each is a `prefetch answer` span labelled with its
	 * question's name. An answer the stats could not open for is never stored.
	 *
	 * @param array<string,array{name:string,verb:string,tokens:list<string>,args:array<string,mixed>}> $questions The `stored()` questions.
	 * @param int                                                                                     $now       The job's tick.
	 * @param list<string>                                                                            $servers   The window's servers, busiest first.
	 * @return array<string,array<string,mixed>>
	 * @throws \InvalidArgumentException As `Performance_CI_Node::answer_live()`.
	 */
	private static function answers( array $questions, int $now, array $servers ): array {
		$answers = [];
		$verbs   = Command_Interpreter_Node::declared_verbs( Performance_CI_Node::class );
		foreach ( $questions as [ 'name' => $name, 'verb' => $verb, 'args' => $args, 'tokens' => $tokens ] ) {
			foreach ( self::is_template( $tokens ) ? $servers : [ null ] as $server ) {
				$bound  = null === $server ? $args : Command_Args::bind( Core::arr( $verbs[ $verb ]['args'] ), \str_replace( self::SERVER, $server, $tokens ) );
				$answer = Log_Manager::span( Flame_Tree::PREFETCH_ANSWER, static fn (): array => Performance_CI_Node::answer_live( $verb, $bound, $now, $servers ), start: [ 'l' => $name ] );
				if ( true === ( $answer['available'] ?? null ) ) {
					$answers[ self::key( $verb, $bound ) ] = $answer;
				}
			}
		}
		return $answers;
	}

	/**
	 * The questions the job answers, each its name in `stored-answers.json`,
	 * its verb and tokens, and its arguments bound as the verb binds them, by
	 * the key a question naming no `SERVER` stores its answer under. A
	 * template's key binds `SERVER` itself, the key `templated()` args match;
	 * its answers are stored under each server's own.
	 *
	 * @return array<string,array{name:string,verb:string,tokens:list<string>,args:array<string,mixed>}>
	 * @throws \InvalidArgumentException On a question naming a verb
	 *                                   `performance` does not declare.
	 * @throws \RuntimeException As `questions()`.
	 * @throws \LogicException On two questions binding to one key, which
	 *                         would store one answer for both, or a template
	 *                         naming its server where `templated()` cannot
	 *                         find it, whose answers no poll would read.
	 */
	public static function stored(): array {
		$verbs  = Command_Interpreter_Node::declared_verbs( Performance_CI_Node::class );
		$stored = [];
		foreach ( self::questions() as $name => [ 'verb' => $verb, 'tokens' => $tokens ] ) {
			$declared = Core::arr( $verbs[ $verb ]['args'] ?? throw new \InvalidArgumentException( \esc_html( "overview prefetch: performance declares no {$verb}" ) ) );
			$args     = Command_Args::bind( $declared, $tokens );
			$key      = self::key( $verb, $args );
			if ( isset( $stored[ $key ] ) ) {
				throw new \LogicException( \esc_html( "overview prefetch: {$stored[ $key ]['name']} and {$name} bind to one key" ) );
			}
			if ( self::is_template( $tokens ) && self::templated( Command_Args::bind( $declared, \str_replace( self::SERVER, 'probe.invalid', $tokens ) ) ) !== $args ) {
				throw new \LogicException( \esc_html( "overview prefetch: {$name} names its server where templated() cannot find it" ) );
			}
			$stored[ $key ] = [ 'name' => $name, 'verb' => $verb, 'tokens' => $tokens, 'args' => $args ];
		}
		return $stored;
	}

	/**
	 * `$args` with the one server they name put back as `SERVER`: an
	 * `overview`'s `server`, or a `query`'s one `server=` filter. Args naming
	 * no server, a blank one, two, or one only by negation come back
	 * unchanged, so no template's key matches them.
	 *
	 * @param array<array-key,mixed> $args Bound arguments.
	 * @return array<array-key,mixed>
	 */
	public static function templated( array $args ): array {
		if ( \is_string( $args['server'] ?? null ) && '' !== $args['server'] ) {
			return \array_replace( $args, [ 'server' => self::SERVER ] );
		}
		$filter = Core::arr( $args['filter'] ?? [] );
		$named  = \array_keys( \array_filter( $filter, static fn ( mixed $token ): bool => \str_starts_with( Core::as_string( $token ), 'server=' ) && 'server=' !== $token ) );
		return 1 === \count( $named ) ? \array_replace( $args, [ 'filter' => \array_replace( $filter, [ $named[0] => 'server=' . self::SERVER ] ) ] ) : $args;
	}

	/**
	 * Whether a question's tokens name their server by `SERVER`.
	 *
	 * @param list<string> $tokens The question's tokens.
	 */
	private static function is_template( array $tokens ): bool {
		return \str_contains( \implode( "\n", $tokens ), self::SERVER );
	}

	/**
	 * The key a verb's answer to bound arguments is stored under.
	 *
	 * @param string                 $verb The verb.
	 * @param array<array-key,mixed> $args Its bound arguments.
	 */
	public static function key( string $verb, array $args ): string {
		return $verb . ':' . \md5( (string) \wp_json_encode( $args ) );
	}

	/**
	 * The questions the job answers, by name: each verb and its tokens, as
	 * the dashboard sends them.
	 *
	 * @return array<string,array{verb:string,tokens:list<string>}>
	 * @throws \RuntimeException When the questions file will not read.
	 */
	public static function questions(): array {
		$file = self::$read_file ?? static fn ( string $path ): string|false => \file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- Local file.
		$json = $file( __DIR__ . '/stored-answers.json' );
		$read = false === $json ? null : \json_decode( $json, true );
		if ( ! \is_array( $read ) ) {
			throw new \RuntimeException( 'overview prefetch: stored-answers.json will not read' );
		}
		/** @var array<string,array{verb:string,tokens:list<string>}> $read */
		return $read;
	}

	/**
	 * The `before_job` listener: decline the job `overtaken()` names, before
	 * a context opens for it. A job
	 * `queued()` refuses passes, for its handler to refuse, rather than be
	 * declined in silence as overtaken; so do any other handler and any
	 * earlier decline.
	 *
	 * @api The `newspack_nodes/job_worker/before_job` filter.
	 * @param mixed            $run     False once a listener declined.
	 * @param string           $handler The job handler.
	 * @param string           $id      The job identity.
	 * @param array<int,mixed> $message The job message; VALUE is the entry.
	 */
	public static function admit( mixed $run, string $handler, string $id, array $message ): mixed {
		unset( $id );
		if ( self::HANDLER !== $handler || false === $run ) {
			return $run;
		}
		$entry  = Core::arr( $message[ Message::VALUE ] ?? [] );
		$queued = self::queued( Core::arr( $entry['parameters'] ?? [] ) );
		return \is_array( $queued ) && self::overtaken( (int) Core::right_now(), ...$queued ) ? false : $run;
	}

	/**
	 * Whether its successor has overtaken a job: an interval or more behind
	 * the clock. The tick queues the successor within a Router tick of that,
	 * so a job declined a second early defers to one no older, where running
	 * it would build the snapshot twice.
	 *
	 * @param int $now       The caller's one read of the clock.
	 * @param int $queued_at When the tick queued it.
	 * @param int $interval  The tick's interval.
	 */
	private static function overtaken( int $now, int $queued_at, int $interval ): bool {
		return $now - $queued_at >= $interval;
	}

	/**
	 * A job's `queued_at` and `interval`, which the tick writes as integers,
	 * or why the tick could not have queued it: its `queued_at` no integer
	 * above 0, or its interval no integer `interval_refusal()` passes, naming
	 * the value it was given.
	 *
	 * @param array<array-key,mixed> $parameters The job's parameters.
	 * @return array{0:int,1:int}|string
	 */
	private static function queued( array $parameters ): array|string {
		$queued_at = $parameters['queued_at'] ?? null;
		if ( ! \is_int( $queued_at ) || $queued_at < 1 ) {
			return 'queued_at wants an epoch second, got ' . self::given( $queued_at );
		}
		$interval = $parameters['interval'] ?? null;
		if ( ! \is_int( $interval ) ) {
			return 'interval wants an integer, got ' . self::given( $interval );
		}
		$why = self::interval_refusal( $interval );
		return null === $why ? [ $queued_at, $interval ] : "interval {$why}, got " . self::given( $interval );
	}

	/**
	 * Why `$seconds` is no interval a job names and the tick waits, the
	 * bounds it wants from `MIN_INTERVAL_S` to `MAX_INTERVAL_S`; null where
	 * it is one. The caller names the value it was given.
	 *
	 * @api Overview_Prefetch_Tick_Node, which refuses a line naming another.
	 * @param int $seconds The interval.
	 */
	public static function interval_refusal( int $seconds ): ?string {
		if ( $seconds >= self::MIN_INTERVAL_S && $seconds <= self::MAX_INTERVAL_S ) {
			return null;
		}
		return 'wants ' . self::MIN_INTERVAL_S . ' to ' . self::MAX_INTERVAL_S . ' seconds';
	}

	/**
	 * A refused job's value as JSON, which escapes every line break and
	 * non-ASCII character, cut at `GIVEN_BYTES` bytes and back to its last
	 * whole escape; its type where it will not encode.
	 *
	 * @param mixed $raw The value.
	 */
	private static function given( mixed $raw ): string {
		$given = \wp_json_encode( $raw );
		if ( ! \is_string( $given ) ) {
			return \gettype( $raw );
		}
		if ( \strlen( $given ) <= self::GIVEN_BYTES ) {
			return $given;
		}
		// An odd run of trailing backslashes opens an escape the cut split.
		return \preg_replace( '/(?<!\\\\)((?:\\\\\\\\)*)\\\\(?:u[0-9a-fA-F]{0,3})?$/', '$1', \substr( $given, 0, self::GIVEN_BYTES ) ) . '…';
	}
}
