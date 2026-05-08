<?php
/**
 * JobRouter: dispatches `k:"job"` lines to registered handlers.
 *
 * Validation per spec:
 *  - HANDLER_NAME_PATTERN = /^[a-z][a-z0-9_]*$/
 *  - MAX_JOB_SIZE = 10MB (10485760 bytes)
 *
 * Multi-input behavior (firehose vs jobintake) and remote_job dispatch deferred.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

class JobRouter extends Node {
	public const HANDLER_NAME_PATTERN = '/^[a-z][a-z0-9_]*$/';
	public const MAX_JOB_SIZE = 10485760;

	/** @var array<string,callable> */
	private array $handlers = [];

	public function register_handler( string $name, callable $cb ): void {
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $name ) ) {
			throw new \InvalidArgumentException( "invalid handler name: $name" );
		}
		$this->handlers[ $name ] = $cb;
	}

	public function has_handler( string $name ): bool {
		return isset( $this->handlers[ $name ] );
	}

	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_BYTESTREAM ) ) {
			return;
		}
		$line = $message[ Message::VALUE ];
		if ( \strlen( $line ) > self::MAX_JOB_SIZE ) {
			Core::print_less_often( 'JobRouter: oversized line, skipping' );
			return;
		}
		$entry = \json_decode( $line, true );
		if ( ! \is_array( $entry ) || ( $entry['k'] ?? '' ) !== 'job' ) {
			return;
		}
		$handler = $entry['handler'] ?? '';
		if ( ! \preg_match( self::HANDLER_NAME_PATTERN, $handler ) ) {
			Core::print_less_often( "JobRouter: invalid handler name: $handler" );
			return;
		}
		if ( ! isset( $this->handlers[ $handler ] ) ) {
			Core::print_less_often( "JobRouter: unknown handler: $handler" );
			return;
		}
		try {
			( $this->handlers[ $handler ] )( $entry['payload'] ?? null );
		} catch ( \Throwable $e ) {
			Core::print_less_often( "JobRouter: handler $handler threw: " . $e->getMessage() );
		}
	}
}
