<?php
/**
 * RequestBuilder: assembles request lifecycles via 3-bucket LRU cache.
 *
 * Bucket key = floor(now / 200). Rotation evicts oldest bucket; orphans emit as
 * timed-out (timeout: true). Full retention window = 3 × 200s = 600s.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;

class RequestBuilder extends Node {
	public const BUCKET_INTERVAL_S      = 200;
	public const NUM_BUCKETS            = 3;
	public const DEFAULT_MAX_PER_BUCKET = 100;

	/** @var array<int,array<string,array>> bucket_key => rid => request */
	private array $buckets = [];
	/** @var array<string,int> rid → bucket_key */
	private array $rid_to_bucket = [];

	private int $max_per_bucket;

	public function __construct( int $max_per_bucket = self::DEFAULT_MAX_PER_BUCKET ) {
		$this->max_per_bucket = $max_per_bucket;
	}

	public function cache_size(): int {
		$total = 0;
		foreach ( $this->buckets as $b ) {
			$total += \count( $b );
		}
		return $total;
	}

	private function current_bucket_key(): int {
		return (int) \floor( Core::$right_now / self::BUCKET_INTERVAL_S );
	}

	private function rotate_if_needed(): void {
		$current = $this->current_bucket_key();
		// Evict any bucket older than (current - NUM_BUCKETS + 1).
		$cutoff = $current - self::NUM_BUCKETS + 1;
		foreach ( \array_keys( $this->buckets ) as $key ) {
			if ( $key < $cutoff ) {
				$this->evict_bucket_as_timeouts( $key );
			}
		}
	}

	private function evict_bucket_as_timeouts( int $key ): void {
		if ( ! isset( $this->buckets[ $key ] ) ) {
			return;
		}
		foreach ( $this->buckets[ $key ] as $rid => $req ) {
			$req['timeout'] = true;
			$this->emit( $req );
			unset( $this->rid_to_bucket[ $rid ] );
		}
		unset( $this->buckets[ $key ] );
	}

	private function evict_oldest_overflow(): void {
		$current = $this->current_bucket_key();
		// If current bucket is at capacity, push out oldest entry from oldest bucket.
		if ( ! isset( $this->buckets[ $current ] ) ) {
			return;
		}
		while ( \count( $this->buckets[ $current ] ) > $this->max_per_bucket ) {
			\ksort( $this->buckets );
			$oldest_key = \array_key_first( $this->buckets );
			if ( $oldest_key === null || empty( $this->buckets[ $oldest_key ] ) ) {
				break;
			}
			$rid            = \array_key_first( $this->buckets[ $oldest_key ] );
			$req            = $this->buckets[ $oldest_key ][ $rid ];
			$req['timeout'] = true;
			$this->emit( $req );
			unset( $this->buckets[ $oldest_key ][ $rid ] );
			unset( $this->rid_to_bucket[ $rid ] );
			if ( empty( $this->buckets[ $oldest_key ] ) ) {
				unset( $this->buckets[ $oldest_key ] );
			}
		}
	}

	private function emit( array $assembled ): void {
		$out                       = Message::new_message();
		$out[ Message::TYPE ]      = Message::TM_BYTESTREAM;
		$out[ Message::TIMESTAMP ] = Core::$right_now;
		$out[ Message::FROM ]      = $this->name;
		$out[ Message::VALUE ]     = \json_encode( $assembled );
		$this->sink?->fill( $out );
	}

	/** Periodic maintenance — evict bucket rotations even with no inbound traffic. */
	public function maintenance(): void {
		$this->rotate_if_needed();
	}

	public function fill( array &$message ): void {
		++$this->counter;
		if ( ! ( $message[ Message::TYPE ] & Message::TM_BYTESTREAM ) ) {
			return;
		}
		$entry = \json_decode( $message[ Message::VALUE ], true );
		if ( ! \is_array( $entry ) || ! isset( $entry['rid'] ) ) {
			Core::print_less_often( 'RequestBuilder: invalid line' );
			return;
		}

		$this->rotate_if_needed();
		$bucket = $this->current_bucket_key();
		if ( ! isset( $this->buckets[ $bucket ] ) ) {
			$this->buckets[ $bucket ] = [];
		}

		$rid  = $entry['rid'];
		$kind = $entry['k'] ?? '';

		if ( $kind === 'start' ) {
			$this->buckets[ $bucket ][ $rid ] = [
				'rid'    => $rid,
				'url'    => $entry['url'] ?? '',
				'events' => [],
			];
			$this->rid_to_bucket[ $rid ]      = $bucket;
			$this->evict_oldest_overflow();
		} elseif ( $kind === 'complete' ) {
			$source_bucket = $this->rid_to_bucket[ $rid ] ?? null;
			if ( $source_bucket === null ) {
				return; // never seen
			}
			$assembled = $this->buckets[ $source_bucket ][ $rid ] ?? null;
			if ( $assembled === null ) {
				return;
			}
			unset( $this->buckets[ $source_bucket ][ $rid ] );
			unset( $this->rid_to_bucket[ $rid ] );
			$this->emit( $assembled );
		} else {
			// Intermediate: append to whichever bucket holds it.
			$source_bucket = $this->rid_to_bucket[ $rid ] ?? null;
			if ( $source_bucket !== null && isset( $this->buckets[ $source_bucket ][ $rid ] ) ) {
				$this->buckets[ $source_bucket ][ $rid ]['events'][] = $entry;
			}
		}
	}
}
