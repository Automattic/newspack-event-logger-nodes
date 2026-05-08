<?php
/**
 * RequestBuilder: assembles request lifecycles from firehose entries.
 *
 * Lifts the core LRU-bucket-cache pattern from class-request-builder.php.
 * Each request has a unique `rid`. 'start' adds to cache; 'complete' evicts
 * the assembled request and emits as TM_BYTESTREAM downstream.
 *
 * Bucket rotation (3 buckets, 200s) + eviction (600s) deferred to subsequent task.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

class RequestBuilder extends Node {
	/** @var array<string,array> Cache: rid → assembled request data. */
	private array $cache = [];

	public function cache_size(): int {
		return \count( $this->cache );
	}

	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_BYTESTREAM ) ) {
			return;
		}
		$line  = $message[ Message::VALUE ];
		$entry = \json_decode( $line, true );
		if ( ! \is_array( $entry ) || ! isset( $entry['rid'] ) ) {
			Core::print_less_often( "RequestBuilder: invalid line: $line" );
			return;
		}
		$rid  = $entry['rid'];
		$kind = $entry['k'] ?? '';

		if ( $kind === 'start' ) {
			$this->cache[ $rid ] = [
				'rid'    => $rid,
				'url'    => $entry['url'] ?? '',
				'events' => [],
			];
		} elseif ( $kind === 'complete' && isset( $this->cache[ $rid ] ) ) {
			$assembled = $this->cache[ $rid ];
			unset( $this->cache[ $rid ] );
			$out                   = Message::new_message();
			$out[ Message::TYPE ]  = Message::TM_BYTESTREAM;
			$out[ Message::FROM ]  = $this->name;
			$out[ Message::VALUE ] = \json_encode( $assembled );
			$this->sink?->fill( $out );
		} elseif ( isset( $this->cache[ $rid ] ) ) {
			// Intermediate event: append.
			$this->cache[ $rid ]['events'][] = $entry;
		}
	}
}
