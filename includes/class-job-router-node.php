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
 * Output shape (one wire form for jobs.log, regardless of source) — the kind
 * stays under `k`, the same field Job_Intake writes and Job_Worker dispatches
 * on, so nothing downstream has to rename it:
 *   { k, handler, parameters, ts }
 *
 * SECURITY:
 * - Handler name must match HANDLER_NAME_PATTERN before reaching disk.
 * - Parameters must be array-shaped or absent.
 * - Entries older than the configured stale timeout are dropped before disk.
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
	use \Newspack_Nodes\Schema_Reflection;

	public const DEFAULT_STALE_TIMEOUT = 60.0;

	public const HANDLER_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/';

	public const KIND_JOB        = 'job';
	public const KIND_REMOTE_JOB = 'remote_job';

	private float $stale_timeout = self::DEFAULT_STALE_TIMEOUT;

	/**
	 * Parse the maximum accepted job age while retaining the raw argument for
	 * topology dump round-trips.
	 *
	 * @api Used by the substrate during make_node construction.
	 * @param list<string>|null $args Maximum job age in seconds at token 0, or null to read back.
	 */
	public function arguments( ?array $args = null ): array {
		if ( null === $args ) {
			return parent::arguments();
		}
		$raw_timeout = $args[0] ?? self::DEFAULT_STALE_TIMEOUT;
		$timeout     = \is_numeric( $raw_timeout ) ? (float) $raw_timeout : null;
		if ( null === $timeout || ! \is_finite( $timeout ) || 0.0 > $timeout ) {
			throw new \InvalidArgumentException( 'stale_timeout must be numeric, finite, and non-negative' );
		}
		$this->parse_schema_args( $args );
		return $args;
	}

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

		// Pluck the job body. Firehose wraps it under `m`; jobintake is flat.
		$body = \is_array( $entry['m'] ?? null ) ? $entry['m'] : $entry;

		// Dispatch kind = entry `k` (not body) so hub job→remote_job wins.
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

		// Stale guard: fail if the entry exceeds the configured maximum age.
		$timestamp = \is_numeric( $raw_timestamp ) ? (float) $raw_timestamp : null;
		if ( null === $timestamp || ! \is_finite( $timestamp ) || Core::$now - $timestamp > $this->stale_timeout ) {
			$this->drop_message( $message, 'stale entry' );
			$this->stderr( 'stale entry: ' . \wp_json_encode( $normalized, JSON_PRETTY_PRINT ) );
			return;
		}

		// Forward normalized VALUE; Node::fill stamps TO from $this->target.
		$message[ Message::VALUE ] = $normalized;
		parent::fill( $message );
	}

	/** @api Used by the substrate to provide UI etc. */
	public static function node_schema(): array {
		return [
			'category'    => 'Routing',
			'description' => 'Normalizes nested or flat job entries and routes them to jobs:partition.',
			'arguments'   => [
				[
					'name'        => 'stale_timeout',
					'type'        => 'float',
					'default'     => self::DEFAULT_STALE_TIMEOUT,
					'description' => 'Maximum job age in seconds; entries older than this are dropped.',
				],
			],
			'commands'    => [],
		];
	}
}
