<?php
/**
 * StatsAggregator: pushes completed-request data into Stats_Store.
 *
 * fill() receives a TM_BYTESTREAM whose VALUE is a JSON-encoded request
 * record from RequestBuilder, and increments:
 *   - URL counter (NS_URLS)
 *   - leaderboard sum (NS_LB)
 *   - hourly bucket (NS_HOURLY)
 *   - dimensional sums (NS_DIM, one per known dim found in the record)
 *   - per-server leaderboard (NS_LB_S) when a server name is present
 *   - per-URL stats (NS_URL_DIM / NS_URL_CAT) when url_hash present
 *
 * Optional Stats_Store: when null, the node falls back to a tiny in-memory
 * map (matches the legacy skeleton API; tests that drove the node before
 * the schema rewrite still pass). When a Stats_Store is injected, every
 * fill() also pushes through the 9-namespace memcache schema.
 *
 * Memcache failure is fail-SOFT: Stats_Store swallows errors silently;
 * counter() always reflects the message count regardless.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

class StatsAggregator extends Node {

	/** Dimensions we automatically extract from the RequestBuilder record. */
	private const DIMENSIONS = [ 'status', 'method', 'server', 'country', 'from', 'ua', 'ja4' ];

	private ?Stats_Store $store;

	/** @var array<string,array{count:int,sum_req_time:float}> Legacy in-memory mode. */
	private array $url_stats = [];

	public function __construct( ?Stats_Store $store = null ) {
		$this->store = $store;
	}

	public function url_count(): int {
		return \count( $this->url_stats );
	}

	public function flush(): array {
		$out             = $this->url_stats;
		$this->url_stats = [];
		return $out;
	}

	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_BYTESTREAM ) ) {
			return;
		}
		$entry = \json_decode( (string) $message[ Message::VALUE ], true );
		if ( ! \is_array( $entry ) || empty( $entry['url'] ) ) {
			return;
		}

		$url      = (string) $entry['url'];
		$req_time = (float) ( $entry['req_time'] ?? 0 );

		// Legacy in-memory tracking kept for callers that flush() rather than read memcache.
		$this->url_stats[ $url ] ??= [ 'count' => 0, 'sum_req_time' => 0.0 ];
		++$this->url_stats[ $url ]['count'];
		$this->url_stats[ $url ]['sum_req_time'] += $req_time;

		if ( $this->store === null ) {
			return;
		}

		// 9-namespace memcache push. Each call is fail-SOFT inside Stats_Store.
		$categories = \is_array( $entry['categories'] ?? null ) ? $entry['categories'] : [];
		$peak_mb    = (float) ( $entry['peak_mb'] ?? 0 );
		$server     = isset( $entry['server'] ) ? (string) $entry['server'] : '';
		$url_hash   = isset( $entry['url_hash'] ) ? (string) $entry['url_hash'] : '';

		$this->store->bump_url( $url, $req_time );
		$this->store->bump_leaderboard( $req_time, $categories );
		$this->store->bump_hourly( $req_time, $peak_mb );

		if ( $server !== '' ) {
			$this->store->bump_server_leaderboard( $server, $req_time, $categories );
		}

		// Dimensional counters — only for fields present on the record.
		foreach ( self::DIMENSIONS as $dim ) {
			if ( ! isset( $entry[ $dim ] ) ) {
				continue;
			}
			$value = (string) $entry[ $dim ];
			if ( $value === '' ) {
				continue;
			}
			$this->store->bump_dimensional( $dim, $value, $req_time );
			if ( $url_hash !== '' ) {
				$this->store->bump_url_dimensional( $url_hash, $dim, $value, $req_time );
			}
		}

		// Per-category time series — both global and per-URL.
		foreach ( $categories as $cat => $data ) {
			$cat_time   = (float) ( $data['time']  ?? 0 );
			$cat_invocs = (int)   ( $data['count'] ?? 0 );
			$this->store->bump_category( (string) $cat, $cat_time, $cat_invocs );
			if ( $url_hash !== '' ) {
				$this->store->bump_url_category( $url_hash, (string) $cat, $cat_time, $cat_invocs );
			}
		}
	}
}
