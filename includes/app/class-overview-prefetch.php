<?php
/**
 * Overview_Prefetch: the URL snapshot and the Overview's other default
 * answers, built once an interval by a JobWorker job and served by the verbs
 * until the snapshot expires.
 *
 * `Overview_Prefetch_Tick_Node` queues each `eln_overview_prefetch` job, and
 * only partition 0's JobWorker runs it. The job builds the window's URL
 * table as one `Url_Snapshot`, which serves `Url_Snapshot::TTL_S` after it
 * publishes, and answers the questions in `stored-answers.json` live inside
 * it, a template naming its server `SERVER` once for each server the window
 * holds: `urls` reads any page of it, `overview` and `query` the stored
 * answer to the same bound arguments, and any other question, a stale
 * snapshot or none is answered live (decision 37).
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
	 * The job: build the URL snapshot, which serves `Url_Snapshot::TTL_S`
	 * after it publishes, and answer every question live inside it, a template once
	 * for each server its totals sum and the overview naming them. An answer
	 * the stats could not open for is never stored, so the verb asks again
	 * live.
	 * `admit()` declines a job before it gets here. On a started record each
	 * answer is a `prefetch answer` span labelled with its question's name.
	 *
	 * The job runs as one unit no cooperative stop splits: each span line is
	 * a Partition write, and so a point a stop could rise, and a stop inside
	 * the build would discard it. A stop that falls due raises at the first
	 * pump after the publish.
	 *
	 * @api The `eln_overview_prefetch` job handler.
	 * @param string              $id         Unused; the job names none.
	 * @param array<string,mixed> $parameters `queued_at` and `interval`.
	 * @throws \InvalidArgumentException On a job naming no `queued_at`, or an
	 *                                   `interval` below one second.
	 * @throws \Newspack_Nodes\Table_Unavailable As `Url_Snapshot::publish()`.
	 * @throws \RuntimeException As `Url_Snapshot::publish()`.
	 * @throws \LogicException As `stored()`.
	 */
	public static function run( string $id, array $parameters ): void {
		$interval = Core::num_int( $parameters['interval'] ?? null );
		if ( $interval < 1 || ! isset( $parameters['queued_at'] ) ) {
			throw new \InvalidArgumentException( 'overview prefetch: a job names its queued_at and an interval of at least 1' );
		}
		$now       = (int) Core::right_now();
		$questions = self::stored();
		Event_Framework::instance()->uninterruptible(
			static function () use ( $questions, $now ): void {
				Url_Snapshot::publish( $now, static fn ( array $servers ): array => self::answers( $questions, $now, $servers ) );
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
	 * The `before_job` listener: decline this job on a worker bound to a
	 * partition other than 0, or once its successor has overtaken it. Any
	 * other handler, and any earlier decline, passes through.
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
		if ( ! Core::owns( '', Core::bound_partition() ) ) {
			return false;
		}
		$entry      = Core::arr( $message[ Message::VALUE ] ?? [] );
		$parameters = Core::arr( $entry['parameters'] ?? [] );
		$age        = (int) Core::right_now() - Core::num_int( $parameters['queued_at'] ?? null );
		return $age >= Core::num_int( $parameters['interval'] ?? null ) ? false : $run;
	}
}
