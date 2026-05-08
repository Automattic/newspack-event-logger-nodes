<?php
/**
 * Memcached_Cache: thin instance wrapper over PHP's Memcached/Memcache extensions.
 *
 * Renamed from `Memcached` (in newspack-event-logger) so the class name does not
 * collide with PHP's bundled `\Memcached`. Single-instance lifetime tied to the
 * caller (Stats_Store, SSE controllers); init() is idempotent.
 *
 * Stats path: fail-SOFT (every method returns the failure sentinel when memcache
 * is unreachable; never throws). SSE-slot path will fail-CLOSED at the caller —
 * that's the caller's policy decision, not this class's.
 *
 * Implements Cache_Interface so test doubles (FakeMemcached) and the real
 * extension wrapper share one type — no duck-typing.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Core;

interface Cache_Interface {
	public function is_available(): bool;
	public function get( string $key ): mixed;
	public function get_multi( array $keys ): array;
	public function set( string $key, mixed $value, int $ttl ): bool;
	public function add( string $key, mixed $value, int $ttl ): bool;
	public function delete( string $key ): bool;
	public function flush_all(): bool;
}

class Memcached_Cache implements Cache_Interface {

	public const DEFAULT_SERVERS = [ '127.0.0.1:11211' ];

	/** @var \Memcached|\Memcache|null */
	private mixed $memd = null;

	/** @var ?string 'memcached' or 'memcache'; null if neither extension available. */
	private ?string $extension = null;

	/**
	 * @param array<string> $servers host:port strings.
	 */
	public function __construct( array $servers = self::DEFAULT_SERVERS ) {
		if ( empty( $servers ) ) {
			return;
		}
		$parsed = [];
		foreach ( $servers as $s ) {
			$parts    = \explode( ':', $s );
			$parsed[] = [
				'host' => $parts[0] ?? '127.0.0.1',
				'port' => (int) ( $parts[1] ?? 11211 ),
			];
		}
		if ( \class_exists( '\Memcached' ) ) {
			$this->connect_memcached( $parsed );
		} elseif ( \class_exists( '\Memcache' ) ) {
			$this->connect_memcache( $parsed );
		}
	}

	private function connect_memcached( array $servers ): void {
		try {
			$memd = new \Memcached();
			foreach ( $servers as $s ) {
				$memd->addServer( $s['host'], $s['port'] );
			}
			if ( empty( $memd->getServerList() ) ) {
				return;
			}
			$this->memd      = $memd;
			$this->extension = 'memcached';
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: connect_memcached failed: ' . $e->getMessage() );
		}
	}

	private function connect_memcache( array $servers ): void {
		try {
			$memd = new \Memcache();
			foreach ( $servers as $s ) {
				$memd->addServer( $s['host'], $s['port'] );
			}
			$this->memd      = $memd;
			$this->extension = 'memcache';
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: connect_memcache failed: ' . $e->getMessage() );
		}
	}

	public function is_available(): bool {
		return $this->memd !== null;
	}

	public function get( string $key ): mixed {
		if ( $this->memd === null ) {
			return null;
		}
		try {
			$result = $this->memd->get( $key );
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: get failed: ' . $e->getMessage() );
			return null;
		}
		return $result === false ? null : $result;
	}

	public function get_multi( array $keys ): array {
		if ( $this->memd === null || empty( $keys ) ) {
			return [];
		}
		try {
			if ( $this->extension === 'memcached' ) {
				$result = $this->memd->getMulti( $keys );
				return \is_array( $result ) ? $result : [];
			}
			// Memcache extension: no native multi-get.
			$out = [];
			foreach ( $keys as $k ) {
				$v = $this->memd->get( $k );
				if ( $v !== false ) {
					$out[ $k ] = $v;
				}
			}
			return $out;
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: get_multi failed: ' . $e->getMessage() );
			return [];
		}
	}

	public function set( string $key, mixed $value, int $ttl ): bool {
		if ( $this->memd === null ) {
			return false;
		}
		try {
			if ( $this->extension === 'memcached' ) {
				return (bool) $this->memd->set( $key, $value, $ttl );
			}
			return (bool) $this->memd->set( $key, $value, 0, $ttl );
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: set failed: ' . $e->getMessage() );
			return false;
		}
	}

	public function add( string $key, mixed $value, int $ttl ): bool {
		if ( $this->memd === null ) {
			return false;
		}
		try {
			if ( $this->extension === 'memcached' ) {
				return (bool) $this->memd->add( $key, $value, $ttl );
			}
			return (bool) $this->memd->add( $key, $value, 0, $ttl );
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: add failed: ' . $e->getMessage() );
			return false;
		}
	}

	public function delete( string $key ): bool {
		if ( $this->memd === null ) {
			return false;
		}
		try {
			return (bool) $this->memd->delete( $key );
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: delete failed: ' . $e->getMessage() );
			return false;
		}
	}

	public function flush_all(): bool {
		if ( $this->memd === null ) {
			return false;
		}
		try {
			return (bool) $this->memd->flush();
		} catch ( \Throwable $e ) {
			Core::print_less_often( 'Memcached_Cache: flush failed: ' . $e->getMessage() );
			return false;
		}
	}
}
