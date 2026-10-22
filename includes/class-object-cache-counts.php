<?php
/**
 * Object_Cache_Counts — what a logged request asked of the object cache, read
 * from where each object cache this logger knows keeps the truth.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads two caches, each told by its class, and nothing else.
 *
 * Core's `WP_Object_Cache`, the class `wp-includes/class-wp-object-cache.php`
 * declares, counts true runtime-cache hits and misses in `cache_hits` and
 * `cache_misses`, so those are read as they are: the row is `<n> hit, <n>
 * miss`.
 *
 * WP Cloud's memcached drop-in (wp-ok-cache), told by the
 * `WP_Object_Cache_Stats` helper its class declares, aliases `cache_hits` to
 * its count of memcached `get` round trips and `cache_misses` to its count of
 * `add` calls, so neither is read: the row is `local`, the gets answered from
 * the request's own copy (`stats['get_local']`, which also counts a
 * non-persistent group's misses, answered there too); `get`, the single-key
 * memcached round trips (`stats['get']`); `hit` and `miss`, those round trips
 * by the comment `group_ops` records for each — `memcache` a hit,
 * `not_in_memcache` and `checkthedatabaseplease` a miss — its `slow-ops`
 * copies left out; and `multi`, the keys a `get_multiple` batch asked for,
 * which it records no per-key result for. The drop-in keeps `group_ops` on
 * every request, not under a debug flag, and empties it on
 * `flush_runtime()`, which `Job_Worker_Node` calls between jobs, so a handler
 * flushing its own runtime cache undercounts its hits and misses.
 *
 * Any other cache gets no row, never zeros.
 */
final class Object_Cache_Counts {

	/**
	 * The buckets each kind's row names, in the order it names them.
	 *
	 * @var array<string,list<string>>
	 */
	private const BUCKETS = [
		'core'      => [ 'hit', 'miss' ],
		'memcached' => [ 'local', 'get', 'hit', 'miss', 'multi' ],
	];

	/**
	 * Where each running counter of `BUCKETS` is read: core's from a property
	 * of that name, the drop-in's from its `stats` key. Every other bucket is
	 * counted from the drop-in's `group_ops` by `lookups()`.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const COUNTERS = [
		'core'      => [ 'hit' => 'cache_hits', 'miss' => 'cache_misses' ],
		'memcached' => [ 'local' => 'get_local', 'get' => 'get' ],
	];

	/** Core's cache's class name, which the drop-in reuses. */
	private const CORE_CLASS = 'WP_Object_Cache';

	/** The file core declares its cache in. */
	private const CORE_FILE = '/wp-includes/class-wp-object-cache.php';

	/** The helper the memcached drop-in's cache class declares, by type. */
	private const DROP_IN_HELPER = 'WP_Object_Cache_Stats';

	/** The `group_ops` group the drop-in copies a slow operation into. */
	private const SLOW_OPS = 'slow-ops';

	/** A memcached `get`'s recorded comment when it found the key. */
	private const FOUND = 'memcache';

	/**
	 * A baseline the cache's counts are later taken from: its kind, its
	 * counters, and for the drop-in each `group_ops` group's length. Null
	 * for a cache this does not know.
	 *
	 * @param bool $from_start Answer the process's start, every count zero.
	 * @return array{kind: string, counters: array<string,int>, positions: array<array-key,int>}|null
	 */
	public static function mark( bool $from_start = false ): ?array {
		$cache = $GLOBALS['wp_object_cache'] ?? null;
		$kind  = \is_object( $cache ) ? self::kind( $cache ) : null;
		if ( null === $cache || null === $kind ) {
			return null;
		}
		$counters  = self::counters( $cache, $kind );
		$positions = 'memcached' === $kind && ! $from_start
			? \array_map( static fn ( mixed $ops ): int => \count( Core::arr( $ops, [] ) ), Core::arr( $cache->group_ops ?? null, [] ) )
			: [];
		return [
			'kind'      => $kind,
			'counters'  => $from_start ? \array_map( static fn (): int => 0, $counters ) : $counters,
			'positions' => $positions,
		];
	}

	/**
	 * The `objectcache` row's `m`: what moved since `$mark`, `<n> <bucket>`
	 * per bucket joined by `, `, or null when there was no baseline or the
	 * cache is not its kind now.
	 *
	 * @param array{kind: string, counters: array<string,int>, positions: array<array-key,int>}|null $mark What `mark()` answered.
	 */
	public static function row( ?array $mark ): ?string {
		$cache = $GLOBALS['wp_object_cache'] ?? null;
		if ( null === $mark || ! \is_object( $cache ) || self::kind( $cache ) !== $mark['kind'] ) {
			return null;
		}
		$now     = self::counters( $cache, $mark['kind'] );
		$lookups = 'memcached' === $mark['kind'] ? self::lookups( Core::arr( $cache->group_ops ?? null, [] ), $mark['positions'] ) : [];
		$parts   = [];
		foreach ( self::BUCKETS[ $mark['kind'] ] as $bucket ) {
			$moved   = isset( $now[ $bucket ] ) ? $now[ $bucket ] - ( $mark['counters'][ $bucket ] ?? 0 ) : ( $lookups[ $bucket ] ?? 0 );
			$parts[] = "{$moved} {$bucket}";
		}
		return \implode( ', ', $parts );
	}

	/**
	 * The drop-in's memcached hits, misses and multi-get keys recorded past
	 * each group's mark; a group shorter than its mark was flushed, and is
	 * read from its start.
	 *
	 * @param array<array-key,mixed> $group_ops The drop-in's `group_ops`.
	 * @param array<array-key,int>   $positions Each group's length at the mark.
	 * @return array<string,int> Each bucket of `BUCKETS` the counters do not hold.
	 */
	private static function lookups( array $group_ops, array $positions ): array {
		$counts = \array_fill_keys( \array_diff( self::BUCKETS['memcached'], \array_keys( self::COUNTERS['memcached'] ) ), 0 );
		foreach ( $group_ops as $group => $ops ) {
			$ops = Core::arr( $ops, [] );
			if ( self::SLOW_OPS === $group ) {
				continue;
			}
			$from = $positions[ $group ] ?? 0;
			foreach ( \array_slice( $ops, $from > \count( $ops ) ? 0 : $from ) as $op ) {
				$op = Core::arr( $op, [] );
				if ( 'get' === ( $op[0] ?? null ) ) {
					++$counts[ self::FOUND === ( $op[4] ?? null ) ? 'hit' : 'miss' ];
				} elseif ( 'get_multiple' === ( $op[0] ?? null ) ) {
					$counts['multi'] += \count( Core::arr( $op[1] ?? null, [] ) );
				}
			}
		}
		return $counts;
	}

	/**
	 * The running counters a kind keeps.
	 *
	 * @param object $cache The installed object cache.
	 * @param string $kind  Its kind.
	 * @return array<string,int>
	 */
	private static function counters( object $cache, string $kind ): array {
		$stats    = Core::arr( $cache->stats ?? null, [] );
		$counters = [];
		foreach ( self::COUNTERS[ $kind ] as $bucket => $source ) {
			$counters[ $bucket ] = Core::as_int( 'core' === $kind ? ( $cache->{$source} ?? 0 ) : ( $stats[ $source ] ?? 0 ) );
		}
		return $counters;
	}

	/**
	 * Which known cache this is, by its class: core's by name and file, the
	 * drop-in's by the type of the helper it declares.
	 *
	 * @param object $cache The installed object cache.
	 * @return 'core'|'memcached'|null
	 */
	private static function kind( object $cache ): ?string {
		$class = new \ReflectionObject( $cache );
		if ( $class->hasProperty( 'stats_helper' ) ) {
			$type = $class->getProperty( 'stats_helper' )->getType();
			return $type instanceof \ReflectionNamedType && self::DROP_IN_HELPER === $type->getName() ? 'memcached' : null;
		}
		$file = \str_replace( '\\', '/', (string) $class->getFileName() );
		return self::CORE_CLASS === $class->getName() && \str_ends_with( $file, self::CORE_FILE ) ? 'core' : null;
	}
}
