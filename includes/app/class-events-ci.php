<?php
/**
 * Events_CI: command-dispatch for the recent-firehose / hourly-stats
 * surface that powers the `event-dashboards` React tree.
 *
 * Replaces legacy class-events-controller.php with a CommandInterpreter
 * that mounts at priority 11 alongside the rest of the M2 service CIs.
 *
 * Verbs:
 *   recent — newest-first walk of the firehose index across all partitions.
 *            Returns up to `limit` entries (default 100, clamped to 1..1000)
 *            with `rid` (pulled from Message::KEY) and `_partition` back-filled
 *            onto each entry. Capped by MAX_INDEX_ENTRIES so a missing-rid
 *            scan can't escalate.
 *   stats  — merge of per-partition hourly buckets read from Stats_Store
 *            into a single time_series array sorted by hour. Fail-soft on
 *            memcache outage (Stats_Store::get_hourly returns []).
 *
 * Value-equivalence with the legacy controller: same envelope shape
 * (`{data, meta}`), same default limit + clamp bounds, same time_series
 * payload, same fail-soft semantics on the stats path.
 *
 * Dependencies are injected via the constructor so tests can stub Cache
 * without touching the substrate's request-scope graph; this mirrors the
 * dependency-injection pattern Workers_CI and Status_CI adopted. The
 * substrate Config is a global accessed directly, matching the legacy
 * controller.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Cache_Interface;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Nodes\CommandInterpreter;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition;
use Newspack_Nodes\Service_CI;

\defined( 'ABSPATH' ) || exit;

class Events_CI extends Service_CI {

	/**
	 * Hard cap on index entries scanned per recent() call. Matches the
	 * legacy EventsController constant — prevents a missing-rid scan from
	 * walking unbounded numbers of firehose entries.
	 */
	public const MAX_INDEX_ENTRIES = 100000;

	/**
	 * Build an Events_CI bound to the supplied cache.
	 *
	 * @param Cache_Interface|null $cache Backing store for Stats_Store. Production
	 *                                     passes the shared Memcached_Cache; tests
	 *                                     pass FakeMemcached. Null still works for
	 *                                     the recent verb (which only reads disk)
	 *                                     but the stats verb will return an empty
	 *                                     time_series.
	 */
	public function __construct( ?Cache_Interface $cache = null ) {
		// Node + CommandInterpreter have no explicit __construct, so the
		// inherited no-op is implicit. Mirrors Workers_CI / Settings_CI /
		// Status_CI / Discovery_CI / Logger_CI.
		$this->commands( [
			'recent' => static function ( CommandInterpreter $self, string $args, array $envelope, mixed $payload ): array {
				$decoded        = \is_array( $payload ) ? $payload : [];
				$limit          = \max( 1, \min( 1000, (int) ( $decoded['limit'] ?? 100 ) ) );
				$config         = RuntimeConfig::load_config();
				$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
				$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
				$log_base       = $base_dir . '/logs';

				$entries = [];
				$scanned = 0;

				for ( $p = 0; $p < $num_partitions && \count( $entries ) < $limit; $p++ ) {
					$partition = new Partition( "{$log_base}/firehose.log", $p );
					$partition->scan_index(
						function ( $seg, $off ) use ( &$entries, &$scanned, $limit, $partition, $p ) {
							++$scanned;
							if ( $scanned > self::MAX_INDEX_ENTRIES ) {
								return false;
							}
							if ( \count( $entries ) >= $limit ) {
								return false;
							}
							if ( ! \is_int( $seg ) || ! \is_int( $off ) ) {
								return null;
							}
							// Read up to 4KB — enough for any PIPE_BUF-sized
							// firehose line. We don't know the exact length here.
							$bytes = $partition->read_at( $seg, $off, 4096 );
							if ( '' === $bytes ) {
								return null;
							}
							$nl   = \strpos( $bytes, "\n" );
							$line = false === $nl ? $bytes : \substr( $bytes, 0, $nl );
							if ( '' === $line ) {
								return null;
							}
							// Line is a packed Message; the entry payload lives at VALUE.
							$decoded = \json_decode( $line, true, 64 );
							$entry   = \is_array( $decoded ) ? ( $decoded[ Message::VALUE ] ?? null ) : null;
							if ( ! \is_array( $entry ) ) {
								return null;
							}
							// rid lives in Message::KEY on the wire; back-fill so the
							// response carries it. _partition is informational metadata.
							$entry['rid']        = (string) ( $decoded[ Message::KEY ] ?? '' );
							$entry['_partition'] = $p;
							$entries[]           = $entry;
							return null;
						},
						true
					);
					if ( $scanned > self::MAX_INDEX_ENTRIES ) {
						break;
					}
				}

				return [
					'data' => $entries,
					'meta' => [
						'limit'   => $limit,
						'scanned' => $scanned,
					],
				];
			},
			'stats' => static function ( CommandInterpreter $self, string $args, array $envelope, mixed $payload ) use ( $cache ): array {
				$config         = RuntimeConfig::load_config();
				$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
				$max_lifespan   = (int) ( $config['max_lifespan'] ?? 86400 );

				$merged = [];
				if ( null !== $cache ) {
					for ( $p = 0; $p < $num_partitions; $p++ ) {
						$store = new Stats_Store( $cache, $p, $max_lifespan );
						foreach ( $store->get_hourly() as $hour => $row ) {
							if ( ! isset( $merged[ $hour ] ) ) {
								$merged[ $hour ] = [
									'hour'        => $hour,
									'count'       => 0,
									'sum_ms'      => 0.0,
									'sum_peak_mb' => 0.0,
								];
							}
							$merged[ $hour ]['count']       += (int) ( $row['count'] ?? 0 );
							$merged[ $hour ]['sum_ms']      += (float) ( $row['sum_ms'] ?? 0 );
							$merged[ $hour ]['sum_peak_mb'] += (float) ( $row['sum_peak_mb'] ?? 0 );
						}
					}
					\ksort( $merged );
				}

				return [
					'data' => [
						'time_series' => \array_values( $merged ),
					],
					'meta' => [],
				];
			},
		] );
	}
}
