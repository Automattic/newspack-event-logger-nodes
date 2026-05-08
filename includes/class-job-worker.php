<?php
/**
 * JobWorker: executes registered handlers; periodic between-jobs callback.
 *
 * Per-job LogManager::suspend/resume + gc_collect_cycles + wp_cache_flush every 50
 * deferred — caller registers via set_between_jobs_callback().
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
	public const MAX_JOB_SIZE = 10485760;

	/** @var array<string,callable> */
	private array $handlers = [];
	private int $jobs_executed = 0;
	private int $between_jobs_every;
	/** @var callable|null */
	private $between_jobs_cb = null;

	public function __construct( int $between_jobs_every = 50 ) {
		$this->between_jobs_every = \max( 1, $between_jobs_every );
	}

	public function register_handler( string $name, callable $cb ): void {
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $name ) ) {
			throw new \InvalidArgumentException( "invalid handler name: $name" );
		}
		$this->handlers[ $name ] = $cb;
	}

	public function set_between_jobs_callback( callable $cb ): void {
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
		try {
			( $this->handlers[ $handler ] )( $entry['payload'] ?? null );
		} catch ( \Throwable $e ) {
			Core::print_less_often( "JobWorker: handler $handler threw: " . $e->getMessage() );
		}
		++$this->jobs_executed;

		if ( $this->between_jobs_cb !== null && ( $this->jobs_executed % $this->between_jobs_every ) === 0 ) {
			( $this->between_jobs_cb )();
		}
	}
}
