<?php
/**
 * Overview_Prefetch: the Overview's default answers, computed once an interval
 * by a JobWorker job and served by the verbs until they expire.
 *
 * `Overview_Prefetch_Tick_Node` queues each `eln_overview_prefetch` job, and
 * only partition 0's JobWorker runs it, so that one worker is the writer of
 * `flame-facts.job`, a SQLite Table it builds outside a topology and names
 * into its own graph, whose Router tick then purges and checkpoints it. Each
 * answer lives two intervals. A verb asked the same bound arguments serves
 * the stored answer, mounting the file read-only; any other question, an
 * expired answer, or no file yet is answered live (decision 37).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Table_Unavailable;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The job and the lookup.
 */
final class Overview_Prefetch {

	/** The Table the job writes: `{base}/tables/flame-facts.job.p0.sqlite`. */
	public const TABLE = 'flame-facts.job';

	/** The Table's backend, which the writer builds and the reader mounts. */
	private const BACKEND = 'sqlite';

	/** The Table's one partition, partition 0's JobWorker its writer. */
	private const PARTITION = 0;

	/** The job handler, queued once an interval by `Overview_Prefetch_Tick_Node`. */
	public const HANDLER = 'eln_overview_prefetch';

	/**
	 * The job: answer every question live and store each available answer for
	 * two intervals. A worker bound to a partition other than 0 declines, as
	 * does a job its successor has overtaken. An answer the stats could not
	 * open for is never stored, so the verb asks again live.
	 *
	 * @api The `eln_overview_prefetch` job handler.
	 * @param string              $id         Unused; the job names none.
	 * @param array<string,mixed> $parameters `queued_at` and `interval`.
	 * @throws \InvalidArgumentException On a job naming no `queued_at`, or an
	 *                                   `interval` below one second.
	 * @throws \RuntimeException When an answer will not store.
	 */
	public static function run( string $id, array $parameters ): void {
		$interval = Core::num_int( $parameters['interval'] ?? null );
		if ( $interval < 1 || ! isset( $parameters['queued_at'] ) ) {
			throw new \InvalidArgumentException( 'overview prefetch: a job names its queued_at and an interval of at least 1' );
		}
		if ( ! Core::owns( '', Core::bound_partition() ) ) {
			return;
		}
		$now = (int) Core::right_now();
		if ( $now - Core::num_int( $parameters['queued_at'] ) >= $interval ) {
			return;
		}
		$writer = self::writer( 2 * $interval );
		$verbs  = Command_Interpreter_Node::declared_verbs( Performance_CI_Node::class );
		foreach ( self::questions() as $name => [ 'verb' => $verb, 'tokens' => $tokens ] ) {
			$args   = Command_Args::bind( Core::arr( $verbs[ $verb ]['args'] ?? throw new \InvalidArgumentException( \esc_html( "overview prefetch: performance declares no {$verb}" ) ) ), $tokens );
			$answer = Performance_CI_Node::answer_live( $verb, $args, $now );
			if ( true !== ( $answer['available'] ?? null ) ) {
				continue;
			}
			if ( ! $writer->store( self::key( $verb, $args ), $answer ) ) {
				throw new \RuntimeException( \esc_html( "overview prefetch: {$name} did not store: " . $writer->last_failure() ) );
			}
		}
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
	 * The job's Table, built by the first job a process runs and named into
	 * its graph, so the worker's Router tick purges and checkpoints it. Its
	 * lifetime is that first job's, and a changed interval, which only a
	 * changed `.tsl` makes, takes effect when the worker restarts for it.
	 *
	 * @param int $ttl Seconds an answer lives.
	 */
	private static function writer( int $ttl ): Table_Node {
		$table = Core::node( self::TABLE );
		if ( $table instanceof Table_Node ) {
			return $table;
		}
		$table = Table_Node::table( self::TABLE, $ttl, self::BACKEND );
		$table->name( self::TABLE );
		return $table;
	}

	/**
	 * The stored answer to a verb's bound arguments, or null for none live.
	 * The file mounts read-only once a request; a store that will not open
	 * answers null, and the verb runs live (decision 3).
	 *
	 * @param string                 $verb The verb.
	 * @param array<array-key,mixed> $args Its bound arguments.
	 * @return array<array-key,mixed>|null
	 * @throws \RuntimeException With no request graph to mount into.
	 */
	public static function lookup( string $verb, array $args ): ?array {
		$stem = Table_Node::stem( self::TABLE, self::PARTITION );
		try {
			$table = Core::node( $stem );
			if ( ! $table instanceof Table_Node ) {
				$ci    = Core::node( Node_Names::COMMAND_INTERPRETER ) ?? throw new \RuntimeException( 'overview prefetch: no request graph to mount into' );
				$table = Table_Node::mount( self::TABLE, self::PARTITION, [ 'namespace' => self::TABLE, 'ttl' => 1, 'backend' => self::BACKEND ], $ci );
			}
			$answer = $table->lookup( self::key( $verb, $args ) );
		} catch ( Table_Unavailable $e ) {
			Core::print_less_often( 'performance: stored answers did not open', ' — ' . $e->getMessage() );
			return null;
		}
		return \is_array( $answer ) ? $answer : null;
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
}
