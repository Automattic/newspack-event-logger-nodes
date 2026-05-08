<?php
/**
 * StreamMerger: parses SSE chunks and emits TM_BYTESTREAM per data: line.
 *
 * Real cURL connection lives in caller (EventFramework's curl_multi_handle).
 * This class just handles the SSE protocol parsing + filter dispatch.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

class StreamMerger extends Node {
	private string $buffer = '';

	public function process_sse_chunk( string $chunk ): void {
		$this->buffer .= $chunk;

		// SSE messages are separated by blank lines.
		while ( ( $pos = \strpos( $this->buffer, "\n\n" ) ) !== false ) {
			$event        = \substr( $this->buffer, 0, $pos );
			$this->buffer = \substr( $this->buffer, $pos + 2 );
			$this->process_event( $event );
		}
	}

	private function process_event( string $event ): void {
		$data_lines = [];
		foreach ( \explode( "\n", $event ) as $line ) {
			if ( \str_starts_with( $line, 'data:' ) ) {
				$data_lines[] = \ltrim( \substr( $line, 5 ) );
			}
		}
		if ( empty( $data_lines ) ) {
			return;
		}
		$payload = \implode( "\n", $data_lines );

		// Apply ingest filter (e.g., k:"job" -> k:"remote_job" rewrite).
		$payload = (string) \apply_filters( 'newspack_nodes/aggregator_ingest_line', $payload );

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_BYTESTREAM;
		$msg[ Message::TIMESTAMP ] = Core::$right_now;
		$msg[ Message::FROM ]      = $this->name;
		$msg[ Message::VALUE ]     = $payload;
		$this->sink?->fill( $msg );
	}

	public function fill( array &$message ): void {
		++$this->counter;
		$this->sink?->fill( $message );
	}
}
