<?php
/**
 * StatsAggregator: per-URL counter aggregation.
 *
 * Skeleton — full 9-namespace memcache schema from spec deferred. flush() returns
 * sums-not-means aggregates so cross-instance merge is exact addition.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

class StatsAggregator extends Node {
	/** @var array<string,array{count:int,sum_req_time:float}> */
	private array $url_stats = [];

	public function url_count(): int {
		return \count( $this->url_stats );
	}

	public function flush(): array {
		$out = $this->url_stats;
		$this->url_stats = [];
		return $out;
	}

	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_BYTESTREAM ) ) {
			return;
		}
		$entry = \json_decode( $message[ Message::VALUE ], true );
		if ( ! \is_array( $entry ) || empty( $entry['url'] ) ) {
			return;
		}
		$url = $entry['url'];
		$req_time = (float) ( $entry['req_time'] ?? 0 );
		if ( ! isset( $this->url_stats[ $url ] ) ) {
			$this->url_stats[ $url ] = [ 'count' => 0, 'sum_req_time' => 0.0 ];
		}
		++$this->url_stats[ $url ]['count'];
		$this->url_stats[ $url ]['sum_req_time'] += $req_time;
	}
}
