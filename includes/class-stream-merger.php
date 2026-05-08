<?php
/**
 * StreamMerger: parses SSE chunks and emits TM_BYTESTREAM per data: line.
 *
 * Owns the cURL multi handle for pulling from remote SSE URLs. The
 * EventFramework drives the multi handle each event-loop iteration; per-handle
 * WRITEFUNCTION callbacks feed bytes back into process_sse_chunk() for parsing.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;
use Newspack_Nodes\EventFramework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

class StreamMerger extends Node {
	public const RECONNECT_BACKOFF_S = 5;

	private string $buffer = '';
	private ?\CurlMultiHandle $multi = null;
	/** @var array<string,array{url:string,token:string,handle:?\CurlHandle,connected_at:float,last_attempt:float}> */
	private array $remotes = [];

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

	/**
	 * Lazily create the cURL multi handle and register it with the event loop.
	 */
	public function init_curl_multi(): void {
		if ( $this->multi !== null ) {
			return;
		}
		$this->multi = \curl_multi_init();
		EventFramework::instance()->register_curl_handle( $this, $this->multi );
	}

	/**
	 * Add a remote SSE source. Opens an easy handle and attaches it to the multi handle.
	 */
	public function add_remote( string $name, string $url, string $token = '' ): void {
		$this->remotes[ $name ] = [
			'url'          => $url,
			'token'        => $token,
			'handle'       => null,
			'connected_at' => 0.0,
			'last_attempt' => 0.0,
		];
		$this->init_curl_multi();
		$this->maybe_connect( $name );
	}

	/**
	 * Open an easy handle for $name if disconnected and outside backoff window.
	 */
	private function maybe_connect( string $name ): void {
		$r = &$this->remotes[ $name ];
		if ( $r['handle'] !== null ) {
			return;
		}
		// Skip if last attempt was within backoff (but allow the first attempt at last_attempt=0).
		if ( $r['last_attempt'] > 0.0 && ( Core::$right_now - $r['last_attempt'] ) < self::RECONNECT_BACKOFF_S ) {
			return;
		}
		$ch = \curl_init();
		\curl_setopt_array(
			$ch,
			[
				\CURLOPT_URL            => $r['url'],
				\CURLOPT_HTTPHEADER     => [
					'Authorization: Bearer ' . $r['token'],
					'Accept: text/event-stream',
				],
				\CURLOPT_TIMEOUT        => 0, // long-running.
				\CURLOPT_RETURNTRANSFER => false,
				\CURLOPT_WRITEFUNCTION  => function ( $h, $bytes ) {
					$this->process_sse_chunk( $bytes );
					return \strlen( $bytes );
				},
			]
		);
		\curl_multi_add_handle( $this->multi, $ch );
		$r['handle']       = $ch;
		$r['connected_at'] = Core::$right_now;
		$r['last_attempt'] = Core::$right_now;
	}

	/**
	 * Called by EventFramework::drain_curl_multi() when curl_multi_info_read returns
	 * a CURLMSG_DONE for one of our handles. Cleans up the handle so a future tick()
	 * can reconnect.
	 */
	public function on_curl_message( array $info ): void {
		$handle = $info['handle'];
		foreach ( $this->remotes as $name => &$r ) {
			if ( $r['handle'] === $handle ) {
				if ( $info['result'] !== \CURLE_OK ) {
					Core::print_less_often( "StreamMerger: $name disconnected: " . \curl_error( $handle ) );
				}
				\curl_multi_remove_handle( $this->multi, $handle );
				\curl_close( $handle );
				$r['handle'] = null;
				// Will be retried on next maybe_connect() — caller drives via tick().
				break;
			}
		}
	}

	/**
	 * Periodic retry: reconnect any disconnected remotes whose backoff has elapsed.
	 */
	public function tick(): void {
		foreach ( \array_keys( $this->remotes ) as $name ) {
			$this->maybe_connect( $name );
		}
	}

	public function remote_count(): int {
		return \count( $this->remotes );
	}

	public function active_count(): int {
		$n = 0;
		foreach ( $this->remotes as $r ) {
			if ( $r['handle'] !== null ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Test-only inspector: returns the cURL handle for a remote (or null if disconnected).
	 *
	 * @internal
	 */
	public function test_get_handle( string $name ): ?\CurlHandle {
		return $this->remotes[ $name ]['handle'] ?? null;
	}
}
