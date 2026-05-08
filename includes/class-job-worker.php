<?php
/**
 * JobWorker: executes registered handlers; fires a between-jobs callback after
 * every job so the caller can drive periodic maintenance.
 *
 * The between-jobs callback is the hook for spec-mandated per-job operations:
 *   - LogManager::suspend()/resume() for re-entrancy
 *   - gc_collect_cycles() after every job
 *   - wp_cache_flush() every 50 jobs (memory headroom against the 80% watermark)
 *
 * JobWorker does NOT hard-code those — it provides the cadence point and the
 * caller decides what to do. The callback receives the running jobs_executed
 * counter as a single argument so cadence is callback-side.
 *
 * The callback fires after both success AND exception paths (matching real
 * Tachikoma's "every job" semantics — you want gc to run even after a handler
 * blew up, otherwise leaked objects pile up faster than under nominal load).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

class JobWorker extends Node {
	public const HANDLER_NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';
	public const MAX_JOB_SIZE         = 10485760;

	/** @var array<string,callable> */
	private array $handlers = [];
	private int $jobs_executed = 0;
	/** @var callable|null */
	private $between_jobs_cb = null;

	public function register_handler( string $name, callable $cb ): void {
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $name ) ) {
			throw new \InvalidArgumentException( "invalid handler name: $name" );
		}
		$this->handlers[ $name ] = $cb;
	}

	/**
	 * Register a between-jobs callback that fires after every job. Pass null to
	 * clear. The callback receives the jobs_executed counter as its single arg
	 * so it can decide its own cadence (e.g., gc every job, wp_cache_flush every
	 * 50 jobs, restart-after-watermark every 1000 jobs).
	 */
	public function set_between_jobs_callback( ?callable $cb ): void {
		$this->between_jobs_cb = $cb;
	}

	public function jobs_executed(): int {
		return $this->jobs_executed;
	}

	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_BYTESTREAM ) ) {
			return;
		}
		$line = $message[ Message::VALUE ];
		// Fail-early: oversized lines never get parsed.
		if ( \strlen( $line ) > self::MAX_JOB_SIZE ) {
			return;
		}
		$entry = \json_decode( $line, true );
		if ( ! \is_array( $entry ) || ( $entry['k'] ?? '' ) !== 'job' ) {
			return;
		}
		$handler = $entry['handler'] ?? '';
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) || ! isset( $this->handlers[ $handler ] ) ) {
			Core::print_less_often( "JobWorker: missing or invalid handler: $handler" );
			return;
		}

		// Job execution — exceptions are caught so the worker survives a bad
		// handler. The between-jobs callback fires either way (we always count
		// the slot, and the caller's gc/cache flush should run after a crashed
		// handler too — that's when leaks accumulate fastest).
		try {
			( $this->handlers[ $handler ] )( $entry['payload'] ?? null );
		} catch ( \Throwable $e ) {
			Core::print_less_often( "JobWorker: handler $handler threw: " . $e->getMessage() );
		}
		++$this->jobs_executed;

		if ( $this->between_jobs_cb !== null ) {
			// Pass the counter so the callback owns cadence decisions.
			( $this->between_jobs_cb )( $this->jobs_executed );
		}
	}
}
