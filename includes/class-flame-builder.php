<?php
/**
 * FlameBuilder: aggregates completed-request events into flame-graph stats.
 *
 * Receives JSON-encoded completed requests (output of RequestBuilder).
 * Aggregates by hook/event name: count, sum_time. flush() returns + clears.
 *
 * Memcache integration + 5x1000 stats_cache rotation deferred.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

class FlameBuilder extends Node {
	/** @var array<string,array{count:int,sum_time:float}> */
	private array $stats = [];

	public function stats_count(): int {
		return \count( $this->stats );
	}

	public function flush(): array {
		$out         = $this->stats;
		$this->stats = [];
		return $out;
	}

	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_BYTESTREAM ) ) {
			return;
		}
		$req = \json_decode( $message[ Message::VALUE ], true );
		if ( ! \is_array( $req ) || empty( $req['events'] ) ) {
			Core::print_less_often( 'FlameBuilder: invalid completed-request line' );
			return;
		}
		foreach ( $req['events'] as $event ) {
			$name = $event['name'] ?? '';
			$time = (float) ( $event['time'] ?? 0 );
			if ( $name === '' ) {
				continue;
			}
			if ( ! isset( $this->stats[ $name ] ) ) {
				$this->stats[ $name ] = [
					'count'    => 0,
					'sum_time' => 0.0,
				];
			}
			++$this->stats[ $name ]['count'];
			$this->stats[ $name ]['sum_time'] += $time;
		}
	}
}
