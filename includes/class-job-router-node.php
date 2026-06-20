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
 * Output shape (one wire form for jobs.log, regardless of source) — the kind
 * stays under `k`, the same field Job_Intake writes and Job_Worker dispatches
 * on, so nothing downstream has to rename it:
 *   { k, handler, parameters, ts }
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

class Job_Router_Node extends Node {

	public const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';
	public const MAX_JOB_SIZE         = 10485760;

	public const KIND_JOB        = 'job';
	public const KIND_REMOTE_JOB = 'remote_job';

	public function fill( array &$message ): void {
		++$this->counter;
		/** @var int $type_flags */
		$type_flags = $message[ Message::TYPE ];
		if ( ! ( $type_flags & Message::TM_STRUCT ) ) {
			return;
		}
		$entry = $message[ Message::VALUE ];
		if ( ! \is_array( $entry ) ) {
			return;
		}

		// Source disambiguation: Consumer stamps FROM with its own node name.
		// Topology names them `firehose:consumer` and `jobintake:consumer`.
		/** @var int|float|string|bool|null $raw_from */
		$raw_from     = $message[ Message::FROM ] ?? '';
		$from         = (string) $raw_from;
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

		// Resolve the kind from the entry-level `k` — the canonical dispatch
		// field. It's what LogManager::message() writes from the category
		// argument, and the field StreamMerger's rewrite filter mutates from
		// 'job' to 'remote_job' on the hub. Read it (never a body-level field)
		// so that hub rewrite is honored at dispatch. For jobintake the entry
		// is flat (no `m` wrap) so $body IS $entry; reading $entry['k'] handles
		// both branches uniformly, and `k` is carried through to jobs.log
		// unrenamed (JobWorker dispatches on the same field).
		/** @var int|float|string|bool|null $raw_type */
		$raw_type = $entry['k'] ?? '';
		$type     = (string) $raw_type;
		if ( self::KIND_JOB !== $type && self::KIND_REMOTE_JOB !== $type ) {
			return;
		}

		// jobintake is always local — never escalate to remote dispatch.
		if ( $is_jobintake && self::KIND_REMOTE_JOB === $type ) {
			$type = self::KIND_JOB;
		}

		/** @var int|float|string|bool|null $raw_handler */
		$raw_handler = $body['handler'] ?? '';
		$handler     = (string) $raw_handler;
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			$this->print_less_often( "invalid handler name: $handler" );
			$this->set_state( 'DROPPED', \implode( ' ', [ 'REASON', 'invalid_handler', 'HANDLER', $handler ] ) );
			return;
		}

		$parameters = $body['parameters'] ?? [];
		if ( ! \is_array( $parameters ) ) {
			$this->print_less_often( "$handler has non-array parameters; dropping" );
			$this->set_state( 'DROPPED', \implode( ' ', [ 'REASON', 'non_array_params', 'HANDLER', $handler ] ) );
			return;
		}

		$normalized = [
			'k'          => $type,
			'handler'    => $handler,
			'parameters' => $parameters,
			'ts'         => $body['ts'] ?? $entry['ts'] ?? \microtime( true ),
		];

		// Pre-pack size guard. Partition's MAX_LARGE_LINE_SIZE catches this
		// at write time too, but failing earlier produces a clearer error.
		$encoded = \wp_json_encode( $normalized );
		if ( false !== $encoded && \strlen( $encoded ) > self::MAX_JOB_SIZE ) {
			$this->print_less_often( "$handler entry exceeds MAX_JOB_SIZE; dropping" );
			$this->set_state(
				'DROPPED',
				\implode( ' ', [ 'REASON', 'OVERSIZE', 'HANDLER', $handler, 'SIZE', \strlen( $encoded ) ] )
			);
			return;
		}

		// Replace VALUE with the normalized entry and forward to target.
		// Node::fill stamps TO from $this->target (set by topology
		// connect_node('jobs:partition')) when TO is empty.
		$message[ Message::VALUE ] = $normalized;
		parent::fill( $message );
	}

	/** @api Used by the substrate to provide UI etc. */
	public static function node_schema(): array {
		return [
			'category'    => 'Routing',
			'description' => 'Splits firehose entries by `k` field; routes job entries to jobs:partition.',
			'arguments'        => [],
			'commands'       => [],
		];
	}
}
