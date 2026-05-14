<?php
/**
 * Job Router
 *
 * Pure router: extracts job-shaped entries from firehose.log and jobintake.log,
 * normalizes them to a single shape, and forwards to its target (jobs.log).
 * The actual handler dispatch happens later in JobWorker, which consumes
 * jobs.log in a separate worker pool.
 *
 * Why split: keeping route + execute in different processes lets the
 * execute side respawn and gc/cache-flush without dropping firehose
 * throughput, and lets the executor live-load handler registrations from
 * `newspack_nodes/{job,remote_job}_handlers` filters without coordinating
 * with the firehose-workers fleet.
 *
 * Sources (disambiguated via Message::FROM, stamped by upstream Consumer):
 * - firehose:  entries the request lifecycle wrote to firehose.log via
 *              LogManager::message('job', ['m' => {type, handler, parameters}]).
 *              The job body is nested under `m`; the entry-level `k` is 'job'.
 * - jobintake: entries the JobIntake API wrote directly to jobintake.log.
 *              The job body lives at the top level; `k` carries the type.
 *
 * Output shape (one wire form for jobs.log, regardless of source):
 *   { type, handler, parameters, ts }
 *
 * SECURITY:
 * - Handler name must match HANDLER_NAME_PATTERN before reaching disk.
 * - Parameters must be array-shaped or absent.
 * - Pre-pack size guard caps oversized entries before they reach the
 *   Partition layer.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class JobRouter extends Node {
	public const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';
	public const MAX_JOB_SIZE         = 10485760;

	public const KIND_JOB        = 'job';
	public const KIND_REMOTE_JOB = 'remote_job';

	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_STRUCT ) ) {
			return;
		}
		$entry = $message[ Message::VALUE ];
		if ( ! \is_array( $entry ) ) {
			return;
		}

		// Source disambiguation: Consumer stamps FROM with its own node name.
		// Topology names them `firehose:consumer` and `jobintake:consumer`.
		$from         = (string) ( $message[ Message::FROM ] ?? '' );
		$is_firehose  = ( false !== \strpos( $from, 'firehose:consumer' ) );
		$is_jobintake = ( false !== \strpos( $from, 'jobintake:consumer' ) );
		if ( ! $is_firehose && ! $is_jobintake ) {
			return; // Not from a known job source — drop silently.
		}

		// Pluck the job body. Firehose wraps it under `m`; jobintake is flat.
		$body = $is_firehose
			? ( \is_array( $entry['m'] ?? null ) ? $entry['m'] : null )
			: $entry;
		if ( ! \is_array( $body ) ) {
			return;
		}

		// Resolve type from the entry-level `k`. This is the canonical
		// dispatch field — it's what LogManager::message() writes from the
		// category argument, and what StreamMerger's rewrite filter mutates
		// from 'job' to 'remote_job' on the hub. A redundant producer-set
		// `m.type` (PHP LogManager whack-cdn etc.) is NOT consulted: it
		// would otherwise revert the rewrite at dispatch time, since
		// StreamMerger only touches the entry-level `k`. For jobintake the
		// entry is flat (no `m` wrap) so $body IS $entry; reading
		// $entry['k'] handles both branches uniformly.
		$type = (string) ( $entry['k'] ?? '' );
		if ( self::KIND_JOB !== $type && self::KIND_REMOTE_JOB !== $type ) {
			return;
		}

		// jobintake is always local — never escalate to remote dispatch.
		if ( $is_jobintake && self::KIND_REMOTE_JOB === $type ) {
			$type = self::KIND_JOB;
		}

		$handler = (string) ( $body['handler'] ?? '' );
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			Core::print_less_often( "JobRouter: invalid handler name: $handler" );
			$this->set_state( 'DROPPED', [ 'reason' => 'invalid_handler', 'handler' => $handler ] );
			return;
		}

		$parameters = $body['parameters'] ?? [];
		if ( ! \is_array( $parameters ) ) {
			Core::print_less_often( "JobRouter: $handler has non-array parameters; dropping" );
			$this->set_state( 'DROPPED', [ 'reason' => 'non_array_params', 'handler' => $handler ] );
			return;
		}

		$normalized = [
			'type'       => $type,
			'handler'    => $handler,
			'parameters' => $parameters,
			'ts'         => $body['ts'] ?? $entry['ts'] ?? \microtime( true ),
		];

		// Pre-pack size guard. Partition's MAX_LARGE_LINE_SIZE catches this
		// at write time too, but failing earlier produces a clearer error.
		$encoded = \wp_json_encode( $normalized );
		if ( false !== $encoded && \strlen( $encoded ) > self::MAX_JOB_SIZE ) {
			Core::print_less_often( "JobRouter: $handler entry exceeds MAX_JOB_SIZE; dropping" );
			$this->set_state(
				'DROPPED',
				[ 'reason' => 'oversize', 'handler' => $handler, 'size' => \strlen( $encoded ) ]
			);
			return;
		}

		// Replace VALUE with the normalized entry and forward to target.
		// Node::fill stamps TO from $this->target (set by topology
		// connect_node('jobs:partition')) when TO is empty.
		$message[ Message::VALUE ] = $normalized;
		parent::fill( $message );
	}

	public static function node_schema(): array {
		return [
			'category'    => 'Routing',
			'description' => 'Splits firehose entries by `k` field; routes job entries to jobs:partition.',
			'ctor'        => [],
			'verbs'       => [],
		];
	}
}
