<?php
/**
 * Overview_Prefetch: the URL snapshot and the Overview's other default
 * answers, built once an interval by a JobWorker job and served by the verbs
 * until the snapshot expires.
 *
 * `Overview_Prefetch_Tick_Node` queues each `eln_overview_prefetch` job, and
 * only partition 0's JobWorker runs it. The job answers the questions in
 * `stored-answers.json` live and publishes them with the window's URL table
 * as one `Url_Snapshot`, which serves two intervals: `urls` reads any page of
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
	 * The job: answer every question live and publish the available answers
	 * with the URL snapshot, which serves two intervals. An answer the stats
	 * could not open for is never stored, so the verb asks again live.
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
	 */
	public static function run( string $id, array $parameters ): void {
		$interval = Core::num_int( $parameters['interval'] ?? null );
		if ( $interval < 1 || ! isset( $parameters['queued_at'] ) ) {
			throw new \InvalidArgumentException( 'overview prefetch: a job names its queued_at and an interval of at least 1' );
		}
		$now = (int) Core::right_now();
		Event_Framework::instance()->uninterruptible(
			static function () use ( $now, $interval ): void {
				$answers = [];
				foreach ( self::stored() as $key => [ 'name' => $name, 'verb' => $verb, 'args' => $args ] ) {
					$answer = Log_Manager::span( Flame_Tree::PREFETCH_ANSWER, static fn (): array => Performance_CI_Node::answer_live( $verb, $args, $now ), start: [ 'l' => $name ] );
					if ( true === ( $answer['available'] ?? null ) ) {
						$answers[ $key ] = $answer;
					}
				}
				Url_Snapshot::publish( $now, 2 * $interval, $answers );
			}
		);
	}

	/**
	 * The questions the job answers, each its name in `stored-answers.json`
	 * and its verb and arguments bound as the verb binds them, by the key its
	 * answer is stored under.
	 *
	 * @return array<string,array{name:string,verb:string,args:array<string,mixed>}>
	 * @throws \InvalidArgumentException On a question naming a verb
	 *                                   `performance` does not declare.
	 * @throws \RuntimeException As `questions()`.
	 * @throws \LogicException On two questions binding to one key, which
	 *                         would store one answer for both.
	 */
	public static function stored(): array {
		$verbs  = Command_Interpreter_Node::declared_verbs( Performance_CI_Node::class );
		$stored = [];
		foreach ( self::questions() as $name => [ 'verb' => $verb, 'tokens' => $tokens ] ) {
			$args = Command_Args::bind( Core::arr( $verbs[ $verb ]['args'] ?? throw new \InvalidArgumentException( \esc_html( "overview prefetch: performance declares no {$verb}" ) ) ), $tokens );
			$key  = self::key( $verb, $args );
			if ( isset( $stored[ $key ] ) ) {
				throw new \LogicException( \esc_html( "overview prefetch: {$stored[ $key ]['name']} and {$name} bind to one key" ) );
			}
			$stored[ $key ] = [ 'name' => $name, 'verb' => $verb, 'args' => $args ];
		}
		return $stored;
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
