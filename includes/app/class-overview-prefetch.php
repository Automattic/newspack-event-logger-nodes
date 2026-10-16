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

use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
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
	 * The job: answer every question live and publish the available answers
	 * with the URL snapshot, which serves two intervals. An answer the stats
	 * could not open for is never stored, so the verb asks again live.
	 * `admit()` declines a job before it gets here.
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
		$now     = (int) Core::right_now();
		$verbs   = Command_Interpreter_Node::declared_verbs( Performance_CI_Node::class );
		$answers = [];
		foreach ( self::questions() as [ 'verb' => $verb, 'tokens' => $tokens ] ) {
			$args   = Command_Args::bind( Core::arr( $verbs[ $verb ]['args'] ?? throw new \InvalidArgumentException( \esc_html( "overview prefetch: performance declares no {$verb}" ) ) ), $tokens );
			$answer = Performance_CI_Node::answer_live( $verb, $args, $now );
			if ( true === ( $answer['available'] ?? null ) ) {
				$answers[ self::key( $verb, $args ) ] = $answer;
			}
		}
		Url_Snapshot::publish( $now, 2 * $interval, $answers );
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
		$json = \file_get_contents( __DIR__ . '/stored-answers.json' );
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
