<?php
/**
 * Job Router
 *
 * Extracts job-shaped entries from whichever ingress logs the topology tails —
 * the firehose (small runtime jobs, body nested under `m`), `jobfeed` and
 * `jobintake` (both written by `\Newspack_Nodes\Job_Intake`, body flat) —
 * normalizes them to one shape and forwards them to its target. It never runs a
 * handler: dispatch belongs to the substrate's `\Newspack_Nodes\Job_Worker_Node`,
 * which consumes jobs.log through a Consumer of its own.
 *
 * Why routing and execution are separate legs: jobs.log is a durable queue
 * between them, and the executor's Consumer carries its own offsetlog and
 * dead-letter dir, so a handler that throws quarantines its own job there and
 * leaves the ingress consumer's offset untouched. The executor also resolves
 * handlers through the `newspack_nodes/job_handlers` and
 * `newspack_nodes/remote_job_handlers` filters, which this node never reads.
 *
 * Output shape (one wire form for jobs.log, whatever the source) — the kind
 * stays under `k`, the same field Job_Intake writes and Job_Worker dispatches
 * on, so nothing downstream has to rename it:
 *   { k, handler, parameters, ts }, plus `id` when the body carries one, plus
 *   whichever of `Job_Intake::DISPATCH_FIELDS` the body carries. Those last
 *   are load-bearing, not decoration: Job_Worker reads them back to decide a
 *   retry and to settle a batch, so dropping them disables both silently.
 *
 * Two gates this node deliberately does not hold. Size belongs to the producers
 * and the Partition: `Job_Intake::MAX_JOB_SIZE` caps an entry where it is
 * written, `Log_Manager::MAX_DATA_SIZE` strips an oversize firehose job's `m`
 * before this node ever sees it, and the Partition refuses an oversize line
 * where it lands, so a limit here would be a fourth number to keep in step. A
 * stripped body arrives as the entry itself and carries no handler, so an
 * oversize firehose job reads here as an invalid-handler warning. Staleness
 * belongs to the Age_Sieve, which `job-router.tsl` wires between this node and
 * `jobs:partition`, so the router holds no age threshold and takes no arguments.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Job_Intake;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes job entries from the ingress logs onto jobs.log.
 *
 * Stateless and argument-free: every message is judged on its own contents, and
 * the sending node's name never gates the decision. That matters most on the
 * hub, where the aggregated firehose carries entries a spoke produced —
 * HANDLER_NAME_PATTERN is what stops an arbitrary remote string from reaching
 * jobs.log as a dispatch key.
 *
 * Five shapes are rejected and only two warn. An invalid handler name and
 * non-array parameters go through `drop_message()`, which prints a rate-limited
 * warning, because each is a malformed job. Wrong type flags, a non-array VALUE
 * and a kind other than `job`/`remote_job` return silently: the firehose carries
 * every log entry and a drain ends on a terminal TM_EOF, so those three are
 * ordinary traffic, and warning on them would bury the two that are faults.
 */
class Job_Router_Node extends Node {
	use \Newspack_Nodes\Schema_Reflection;

	/** Handler names: a leading letter then up to 63 word/dash characters. Mirrors `\Newspack_Nodes\Job_Worker_Node::HANDLER_NAME_PATTERN`. */
	public const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';

	/** Kind dispatched locally on every node, against `newspack_nodes/job_handlers`. */
	public const KIND_JOB = 'job';

	/** Kind dispatched on the hub only, against `newspack_nodes/remote_job_handlers`. */
	public const KIND_REMOTE_JOB = 'remote_job';

	/**
	 * Normalize one ingress entry and forward it, or drop it.
	 *
	 * Firehose entries wrap the job body under `m`; `Job_Intake` writes a flat
	 * entry, which is therefore its own body. Two fields are read from the ENTRY
	 * rather than the body, and the distinction matters: the kind comes from the
	 * entry `k` so the hub's `Remote_Job_Rewrite_Node` rewrite of `job` →
	 * `remote_job` wins over a stale `type` left in the body, and `ts` falls back
	 * to the entry timestamp only when the body carries none. The `id` is read
	 * from the body, where producers put it, and is omitted when empty so
	 * `Job_Worker_Node` keys jobstats by handler alone.
	 *
	 * The timestamp is copied verbatim, never coerced — a garbage `ts` travels
	 * through as data for the downstream reader to zero out.
	 *
	 * @api Entry point invoked by the substrate Router / upstream sink.
	 * @param array<int,mixed> $message Message whose VALUE is the ingress entry array.
	 * @throws \RuntimeException When no sink is wired, which the first forwarded job surfaces.
	 */
	public function fill( array $message ): void {
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

		// Pluck the job body. Firehose wraps it under `m`; Job_Intake is flat.
		$body = \is_array( $entry['m'] ?? null ) ? $entry['m'] : $entry;

		// Kind comes from the ENTRY, so the hub's remote_job rewrite wins.
		$type = Core::as_string( $entry['k'] ?? '' );
		if ( self::KIND_JOB !== $type && self::KIND_REMOTE_JOB !== $type ) {
			return;
		}

		$handler = Core::as_string( $body['handler'] ?? '' );
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			$this->drop_message( $message, "invalid handler name: {$handler}" );
			return;
		}

		$parameters = $body['parameters'] ?? [];
		if ( ! \is_array( $parameters ) ) {
			$this->drop_message( $message, "{$handler} has non-array parameters" );
			return;
		}

		$raw_timestamp = $body['ts'] ?? $entry['ts'] ?? null;
		$normalized    = [
			'k'          => $type,
			'handler'    => $handler,
			'parameters' => $parameters,
			'ts'         => $raw_timestamp,
		];

		// Job_Worker reads these back; dropping them disables retry.
		foreach ( Job_Intake::DISPATCH_FIELDS as $field ) {
			if ( isset( $body[ $field ] ) ) {
				$normalized[ $field ] = $body[ $field ];
			}
		}

		// Producers put the id in the BODY; Job_Worker keys jobstats on it.
		$id = Core::as_string( $body['id'] ?? '' );
		if ( '' !== $id ) {
			$normalized['id'] = $id;
		}

		// Forward normalized VALUE; Node::fill() stamps TO from $this->target.
		$message[ Message::VALUE ] = $normalized;
		parent::fill( $message );
	}

	/**
	 * Topology console manifest: a router with a sink target and no arguments.
	 *
	 * Age gating lives in the Age_Sieve downstream, so the empty `arguments` list
	 * is load-bearing — a stale-timeout argument here would be a regression.
	 *
	 * @api Used by the substrate to resolve the node + provide UI.
	 * @return array<string,mixed> Schema consumed by `Command_Interpreter_Node`.
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Routing',
			'description' => 'Normalizes nested or flat job entries and routes them to jobs:partition.',
			'arguments'   => [],
			'commands'    => [],
		];
	}
}
