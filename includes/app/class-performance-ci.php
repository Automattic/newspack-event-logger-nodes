<?php
/**
 * Performance_CI: command-dispatch for the performance-dashboard surface.
 *
 * 11 of 19 planned verbs. Replaces:
 *   - class-perf-overview-controller.php       (overview)
 *   - class-perf-urls-controller.php            (urls, url_detail)
 *   - class-perf-requests-controller.php        (request_search, request_detail)
 *   - class-performance-controller.php          (timing, dashboard)
 *   - class-perf-hooks-controller.php           (hooks_registered, hooks_categories)
 *   - class-perf-hooks-available-controller.php (hooks_available, hooks_configure)
 *
 * Tasks 11-12 grow this CI with the remaining 8 verbs (config, settings,
 * status sub-surfaces). Verb table is structured per-verb in `verb_table()`
 * so each follow-up task adds a sibling closure without touching the
 * existing surface.
 *
 * Cross-cutting design choices:
 *  - Auth: every verb requires `manage_options`. Legacy parity — all five
 *    legacy controllers gated through `PerformanceControllerBase::read_permissions_check`,
 *    which enforces the capability.
 *  - Rate limit: dropped. The legacy rate-limit was an artifact of REST
 *    polling; CI dispatch fires verbs once-per-request through the worker,
 *    not from a fan-out of polling tabs.
 *  - Stats reads fail-soft (matches Stats_Store + dashboards "no data" UX).
 *  - Disk scans capped at MAX_INDEX_ENTRIES so a missing-rid lookup can't
 *    escalate into a partition-wide segment walk.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Cache_Interface;
use Newspack_Event_Logger_Nodes\Config as AppConfig;
use Newspack_Event_Logger_Nodes\FlameBuilder;
use Newspack_Event_Logger_Nodes\HookCategorizer;
use Newspack_Event_Logger_Nodes\RequestBuilder;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Nodes\CommandInterpreter;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition;

\defined( 'ABSPATH' ) || exit;

class Performance_CI extends CommandInterpreter {

	/**
	 * Hard cap on .idx entries scanned per disk-walking verb. Matches the
	 * legacy controllers' MAX_INDEX_ENTRIES — prevents a missing-rid scan
	 * from walking unbounded numbers of firehose entries.
	 */
	public const MAX_INDEX_ENTRIES = 100000;

	/**
	 * Valid sort fields for the `urls` verb. Echoes the legacy
	 * PerfUrlsController whitelist; anything outside falls back to `count`.
	 */
	private const URL_SORTS = [ 'count', 'url', 'avg_ms', 'min_ms', 'max_ms', 'p95_ms', 'avg_peak_mb', 'last_updated' ];

	/**
	 * Build a Performance_CI bound to the supplied cache.
	 *
	 * @param Cache_Interface|null $cache Backing store for Stats_Store. Production
	 *                                     passes the shared Memcached_Cache; tests
	 *                                     pass FakeMemcached. Null still works for
	 *                                     disk-walking verbs (request_search /
	 *                                     request_detail), but stats-reading verbs
	 *                                     return empty/zeroed shapes.
	 */
	public function __construct( ?Cache_Interface $cache = null ) {
		// Node + CommandInterpreter have no explicit __construct, so the
		// inherited no-op is implicit. Mirrors Aggregator_CI / Servers_CI /
		// Events_CI / Settings_CI / Status_CI / Discovery_CI / Logger_CI /
		// Workers_CI.
		$this->commands( $this->verb_table( $cache ) );
	}

	/**
	 * Verb-to-closure map. Each verb is a self-contained closure so
	 * Tasks 10-12 can add siblings without disturbing existing entries.
	 */
	private function verb_table( ?Cache_Interface $cache ): array {
		return [
			'overview'       => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $cache ): string {
				self::require_manage_options();
				return (string) \wp_json_encode( self::build_overview_payload( self::load_index( $cache ), $cache ) );
			},
			'urls'           => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $cache ): string {
				self::require_manage_options();

				$decoded = self::decoded_args( $args );
				$sort    = (string) ( $decoded['sort']   ?? 'count' );
				$order   = (string) ( $decoded['order']  ?? 'desc' );
				$limit   = \min( 1000, \max( 1, (int) ( $decoded['limit']  ?? 50 ) ) );
				$offset  = \min( 10000, \max( 0, (int) ( $decoded['offset'] ?? 0 ) ) );
				$search  = (string) ( $decoded['search'] ?? '' );
				$server  = (string) ( $decoded['server'] ?? '' );

				if ( ! \in_array( $sort, self::URL_SORTS, true ) ) {
					$sort = 'count';
				}
				if ( 'asc' !== $order && 'desc' !== $order ) {
					$order = 'desc';
				}

				$index = self::load_index( $cache );

				if ( '' !== $server ) {
					$srv   = \strtolower( $server );
					$index = \array_values( \array_filter(
						$index,
						static fn ( $e ) => false !== \strpos( \strtolower( (string) ( $e['url'] ?? '' ) ), $srv )
					) );
				}
				if ( '' !== $search ) {
					$term  = \strtolower( $search );
					$index = \array_values( \array_filter(
						$index,
						static fn ( $e ) => false !== \strpos( \strtolower( (string) ( $e['url'] ?? '' ) ), $term )
					) );
				}

				$total = \count( $index );

				\usort(
					$index,
					static fn ( $a, $b ) => 'asc' === $order
						? ( $a[ $sort ] ?? 0 ) <=> ( $b[ $sort ] ?? 0 )
						: ( $b[ $sort ] ?? 0 ) <=> ( $a[ $sort ] ?? 0 )
				);

				return (string) \wp_json_encode( [
					'data'   => \array_slice( $index, $offset, $limit ),
					'total'  => $total,
					'limit'  => $limit,
					'offset' => $offset,
				] );
			},
			'url_detail'     => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $cache ): string {
				self::require_manage_options();

				$decoded = self::decoded_args( $args );
				$hash    = (string) ( $decoded['hash'] ?? '' );
				if ( ! \preg_match( '/^[a-f0-9]{8,64}$/', $hash ) ) {
					throw new \RuntimeException( 'invalid hash format' );
				}

				$index = self::load_index( $cache );
				$stats = null;
				foreach ( $index as $entry ) {
					if ( ( $entry['hash'] ?? '' ) === $hash ) {
						$stats = [
							'hash'         => $hash,
							'url'          => $entry['url'] ?? '',
							'count'        => $entry['count'] ?? 0,
							'avg_ms'       => $entry['avg_ms'] ?? 0,
							'min_ms'       => $entry['min_ms'] ?? 0,
							'max_ms'       => $entry['max_ms'] ?? 0,
							'p50_ms'       => $entry['p50_ms'] ?? 0,
							'p95_ms'       => $entry['p95_ms'] ?? 0,
							'p99_ms'       => $entry['p99_ms'] ?? 0,
							'avg_peak_mb'  => $entry['avg_peak_mb'] ?? 0,
							'max_peak_mb'  => $entry['max_peak_mb'] ?? 0,
							'last_updated' => $entry['last_updated'] ?? 0,
						];
						break;
					}
				}
				if ( null === $stats ) {
					throw new \RuntimeException( \esc_html( "URL not found: {$hash}" ) );
				}

				$aggregate = self::find_url_aggregate( $hash, $cache );
				$flame     = $aggregate['flame']
					?? [ 'name' => 'aggregate', 'value' => 0, 'children' => [] ];

				return (string) \wp_json_encode( [
					'stats'              => $stats,
					'requests'           => self::find_recent_requests_for_url( $hash ),
					'aggregate_flame'    => $flame,
					'aggregate_profiles' => $aggregate['profiles'] ?? null,
					'last_modified'      => $aggregate['last_modified'] ?? 0,
				] );
			},
			'request_search' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ): string {
				self::require_manage_options();

				$decoded = self::decoded_args( $args );
				$rid     = (string) ( $decoded['rid'] ?? '' );
				if ( '' === $rid ) {
					throw new \RuntimeException( 'rid required' );
				}

				$config         = RuntimeConfig::load_config();
				$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
				$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
				$log_base       = $base_dir . '/logs';
				$scanned        = 0;

				for ( $p = 0; $p < $num_partitions; $p++ ) {
					$found = self::find_request_index_entry( $log_base, $p, $rid, $scanned );
					if ( null !== $found ) {
						return (string) \wp_json_encode( $found );
					}
					if ( $scanned > self::MAX_INDEX_ENTRIES ) {
						break;
					}
				}

				throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
			},
			'request_detail' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ): string {
				self::require_manage_options();

				$decoded = self::decoded_args( $args );
				$rid     = (string) ( $decoded['rid'] ?? '' );
				if ( '' === $rid ) {
					throw new \RuntimeException( 'rid required' );
				}
				$partition = (int) ( $decoded['partition'] ?? 0 );

				$config         = RuntimeConfig::load_config();
				$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
				$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
				$log_base       = $base_dir . '/logs';

				if ( $partition < 0 || $partition >= $num_partitions ) {
					throw new \RuntimeException( 'invalid partition' );
				}

				$result = self::find_request_in_partition( $log_base, $partition, $rid, $num_partitions );
				if ( null === $result ) {
					throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
				}
				return (string) \wp_json_encode( $result );
			},
			'timing'         => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $cache ): string {
				self::require_manage_options();

				// Lifted from legacy PerformanceController::get_timing — merged
				// hourly buckets across partitions. The legacy "data + meta"
				// wrapper is dropped (REST artifact); CI returns the inner
				// payload directly.
				return (string) \wp_json_encode( [
					'time_series' => self::merge_hourly_across_partitions( $cache ),
				] );
			},
			'dashboard'      => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $cache ): string {
				self::require_manage_options();

				// Lifted from legacy PerformanceController::get_dashboard:
				// nest the overview payload alongside the full URL index so
				// the dashboard tree fans in with one round-trip. `load_index`
				// is the heavy memcache fan-out — share it across both keys.
				$index = self::load_index( $cache );
				return (string) \wp_json_encode( [
					'overview' => self::build_overview_payload( $index, $cache ),
					'urls'     => $index,
				] );
			},
			'hooks_registered' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ): string {
				self::require_manage_options();

				// Lifted from legacy PerfHooksController::get_registered_hooks.
				// The legacy controller also returned `total_hooks` as the sum
				// of all category buckets; recomputing here keeps the contract
				// identical without trusting the categorizer to sum for us.
				$by_category = HookCategorizer::get_registered_hooks_by_category();
				$total       = 0;
				foreach ( $by_category as $list ) {
					$total += \is_array( $list ) ? \count( $list ) : 0;
				}
				return (string) \wp_json_encode( [
					'total_hooks'       => $total,
					'categories'        => HookCategorizer::get_categories(),
					'hooks_by_category' => $by_category,
				] );
			},
			'hooks_categories' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ): string {
				self::require_manage_options();

				// Lifted from legacy PerfHooksController::get_hook_categories
				// — same shape the React tree consumes.
				return (string) \wp_json_encode( [
					'categories' => HookCategorizer::get_categories(),
					'config'     => HookCategorizer::get_merged_config(),
				] );
			},
			'hooks_available' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ): string {
				self::require_manage_options();

				// Lifted from legacy PerfHooksAvailableController::get_available_hooks.
				// Walks $wp_actions (fired hooks) and $wp_filter (registered
				// but never-fired hooks), excludes Event Logger's own internal
				// hooks (instrumenting them loops via Config::load_config),
				// and removes anything the operator has marked as a custom
				// event so the picker doesn't double-list it.
				return (string) \wp_json_encode( [
					'hooks' => self::collect_available_hooks(),
				] );
			},
			'hooks_configure' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ): string {
				self::require_manage_options();

				$decoded       = self::decoded_args( $args );
				$hooks         = $decoded['hooks']         ?? null;
				$custom_events = $decoded['custom_events'] ?? null;
				$configured    = 0;

				if ( \is_array( $hooks ) && [] !== $hooks ) {
					$flat = [];
					foreach ( $hooks as $h ) {
						if ( \is_string( $h ) && '' !== $h ) {
							$flat[] = \sanitize_text_field( $h );
						}
					}
					\update_option( 'newspack_event_logger_nodes_log_events', $flat );
					$configured += \count( $flat );
				}

				if ( \is_array( $custom_events ) && [] !== $custom_events ) {
					$assoc = [];
					foreach ( $custom_events as $event ) {
						if ( \is_string( $event ) && '' !== $event ) {
							$assoc[ \sanitize_text_field( $event ) ] = true;
						}
					}
					\update_option( 'newspack_event_logger_nodes_custom_events', $assoc );
					$configured += \count( $assoc );
				}

				// Application Config caches the merged custom_events / log_events;
				// reset so the very next verb call (e.g. hooks_available) re-reads
				// the freshly-written WP options.
				AppConfig::reset();

				return (string) \wp_json_encode( [
					'success'          => true,
					'hooks_configured' => $configured,
				] );
			},
		];
	}

	// -------------------------------------------------------------------------
	// Shared helpers — auth + arg decoding (mirror Servers_CI's helpers).
	// -------------------------------------------------------------------------

	/**
	 * Authorisation gate for every verb. Matches the legacy controllers'
	 * `read_permissions_check`. Thrown errors are caught by
	 * `CommandInterpreter::interpret()` and turned into TM_COMMAND|TM_ERROR.
	 */
	private static function require_manage_options(): void {
		if ( \function_exists( 'current_user_can' ) && ! \current_user_can( 'manage_options' ) ) {
			throw new \RuntimeException( 'permission denied: manage_options required' );
		}
	}

	/**
	 * Decode a verb's JSON args; tolerates empty/malformed input by returning
	 * an empty array. Matches Servers_CI / Settings_CI.
	 */
	private static function decoded_args( string $args ): array {
		if ( '' === $args ) {
			return [];
		}
		$decoded = \json_decode( $args, true );
		return \is_array( $decoded ) ? $decoded : [];
	}

	// -------------------------------------------------------------------------
	// Hook discovery — walk $wp_actions + $wp_filter for the picker UI.
	// -------------------------------------------------------------------------

	/**
	 * Collect every WordPress hook known to the runtime, categorize it, and
	 * strip out (a) Event Logger's own internal hooks and (b) anything the
	 * operator has flagged as a custom event (so the custom-events tab owns
	 * those). Sorted by name. Mirror of
	 * PerfHooksAvailableController::get_available_hooks.
	 *
	 * @return array<int,array{name:string,category:string,count:int}>
	 */
	private static function collect_available_hooks(): array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP globals.
		global $wp_actions, $wp_filter;

		$hooks = [];

		if ( isset( $wp_actions ) && \is_array( $wp_actions ) ) {
			foreach ( $wp_actions as $hook_name => $count ) {
				$name = (string) $hook_name;
				if ( HookCategorizer::is_internal( $name ) ) {
					continue;
				}
				$hooks[ $name ] = [
					'name'     => $name,
					'category' => HookCategorizer::categorize( $name ),
					'count'    => (int) $count,
				];
			}
		}

		if ( isset( $wp_filter ) && ( \is_array( $wp_filter ) || $wp_filter instanceof \Traversable ) ) {
			foreach ( $wp_filter as $hook_name => $callbacks ) {
				$name = (string) $hook_name;
				if ( HookCategorizer::is_internal( $name ) ) {
					continue;
				}
				// $wp_actions count takes precedence — only add if missing.
				if ( ! isset( $hooks[ $name ] ) ) {
					$hooks[ $name ] = [
						'name'     => $name,
						'category' => HookCategorizer::categorize( $name ),
						'count'    => 0,
					];
				}
			}
		}

		// Filter out custom events — they're managed via the custom-events tab.
		$cfg           = RuntimeConfig::load_config();
		$custom_events = $cfg['custom_events'] ?? [];
		if ( \is_array( $custom_events ) ) {
			foreach ( $custom_events as $key => $value ) {
				// Indexed array form (`['event_a', 'event_b']`) puts the name
				// in the value; associative form (`['event_a' => true]`) puts
				// it in the key. Match both — same as the legacy controller.
				$name = ( \is_string( $key ) && '' !== $key && ! \is_numeric( $key ) ) ? $key : $value;
				if ( \is_string( $name ) ) {
					unset( $hooks[ $name ] );
				}
			}
		}

		\ksort( $hooks );
		return \array_values( $hooks );
	}

	// -------------------------------------------------------------------------
	// Stats_Store helpers — fan out across partitions and merge.
	// -------------------------------------------------------------------------

	/**
	 * One Stats_Store per partition over the supplied cache.
	 *
	 * @return array<int,Stats_Store>
	 */
	private static function stats_stores( ?Cache_Interface $cache ): array {
		if ( null === $cache ) {
			return [];
		}
		$config         = RuntimeConfig::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$max_lifespan   = (int) ( $config['max_lifespan'] ?? 86400 );
		$stores         = [];
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$stores[] = new Stats_Store( $cache, $p, $max_lifespan );
		}
		return $stores;
	}

	/**
	 * Build a list of recent 5-min bucket keys spanning the retention window.
	 * Capped at 288 (24h × 12 buckets/h) so memcache get_multi stays bounded.
	 * Matches PerfOverviewController::recent_url_buckets.
	 *
	 * @return array<int,string>
	 */
	private static function recent_url_buckets(): array {
		$now = \time();
		$out = [];
		for ( $i = 0; $i < 288; $i++ ) {
			$ts         = $now - ( $i * 300 );
			$min        = (int) \gmdate( 'i', $ts );
			$bucket_min = \str_pad( (string) ( (int) \floor( $min / 5 ) * 5 ), 2, '0', \STR_PAD_LEFT );
			$out[]      = \gmdate( 'Y-m-d-H', $ts ) . '-' . $bucket_min;
		}
		return \array_unique( $out );
	}

	/**
	 * Merged URL index across all partitions, shaped for dashboard display.
	 * Mirrors PerfOverviewController::load_index — same field set, same
	 * sort (count DESC), same fallback hashing for buckets that don't
	 * carry an embedded URL.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function load_index( ?Cache_Interface $cache ): array {
		$buckets = self::recent_url_buckets();
		$result  = [];
		foreach ( self::stats_stores( $cache ) as $store ) {
			$rows = $store->get_url_buckets( $buckets );
			foreach ( $rows as $bucket_data ) {
				if ( ! \is_array( $bucket_data ) ) {
					continue;
				}
				foreach ( $bucket_data as $hash_or_url => $stats ) {
					// FlameBuilder writes `[bucket => [hash => {url, count, ...}]]`
					// — inner key is the URL hash, URL string lives in `$stats['url']`.
					// StatsAggregator buckets may key by URL string directly; fall back
					// to a derived hash in that case.
					if ( \is_array( $stats ) && isset( $stats['url'] ) ) {
						$url  = (string) $stats['url'];
						$hash = (string) $hash_or_url;
					} else {
						$url  = (string) $hash_or_url;
						$hash = \substr( \hash( 'sha256', $url ), 0, 12 );
					}
					$result[ $hash ] ??= [
						'hash'        => $hash,
						'url'         => $url,
						'count'       => 0,
						'count_2xx'   => 0,
						'count_3xx'   => 0,
						'count_4xx'   => 0,
						'count_5xx'   => 0,
						'sum_ms'      => 0.0,
						'min_ms'      => 0.0,
						'max_ms'      => 0.0,
						'p50_ms'      => 0.0,
						'p95_ms'      => 0.0,
						'p99_ms'      => 0.0,
						'sum_peak_mb' => 0.0,
						'max_peak_mb' => 0.0,
						'last_seen'   => 0,
					];
					$entry           = &$result[ $hash ];
					$entry['count']     += (int) ( $stats['count']     ?? 0 );
					$entry['count_2xx'] += (int) ( $stats['count_2xx'] ?? 0 );
					$entry['count_3xx'] += (int) ( $stats['count_3xx'] ?? 0 );
					$entry['count_4xx'] += (int) ( $stats['count_4xx'] ?? 0 );
					$entry['count_5xx'] += (int) ( $stats['count_5xx'] ?? 0 );
					// FlameBuilder bucket has `sum_ms` directly; StatsAggregator
					// bucket has `sum_req_time` in seconds — accept either.
					$entry['sum_ms']      += isset( $stats['sum_ms'] )
						? (float) $stats['sum_ms']
						: (float) ( $stats['sum_req_time'] ?? 0 ) * 1000.0;
					$entry['sum_peak_mb'] += (float) ( $stats['sum_peak_mb'] ?? 0 );
					if ( isset( $stats['min_ms'] ) ) {
						$entry['min_ms'] = 0.0 === $entry['min_ms']
							? (float) $stats['min_ms']
							: \min( $entry['min_ms'], (float) $stats['min_ms'] );
					}
					$entry['max_ms']      = \max( (float) $entry['max_ms'],      (float) ( $stats['max_ms']      ?? 0 ) );
					$entry['max_peak_mb'] = \max( (float) $entry['max_peak_mb'], (float) ( $stats['max_peak_mb'] ?? 0 ) );
					foreach ( [ 'p50_ms', 'p95_ms', 'p99_ms' ] as $k ) {
						if ( ! empty( $stats[ $k ] ) ) {
							$entry[ $k ] = (float) $stats[ $k ];
						}
					}
					$entry['last_seen'] = \max(
						(int) $entry['last_seen'],
						(int) ( $stats['last_seen'] ?? 0 )
					);
					unset( $entry );
				}
			}
		}

		// Convert into the display shape the React tree expects.
		$out = [];
		foreach ( $result as $entry ) {
			$count = (int) $entry['count'];
			$out[] = [
				'hash'         => $entry['hash'],
				'url'          => $entry['url'],
				'count'        => $count,
				'count_2xx'    => (int) $entry['count_2xx'],
				'count_3xx'    => (int) $entry['count_3xx'],
				'count_4xx'    => (int) $entry['count_4xx'],
				'count_5xx'    => (int) $entry['count_5xx'],
				'avg_ms'       => $count > 0 ? $entry['sum_ms'] / $count : 0.0,
				'min_ms'       => $entry['min_ms'],
				'max_ms'       => $entry['max_ms'],
				'p50_ms'       => $entry['p50_ms'],
				'p95_ms'       => $entry['p95_ms'],
				'p99_ms'       => $entry['p99_ms'],
				'avg_peak_mb'  => $count > 0 ? $entry['sum_peak_mb'] / $count : 0.0,
				'max_peak_mb'  => $entry['max_peak_mb'],
				'last_updated' => (int) $entry['last_seen'],
			];
		}
		\usort( $out, static fn ( $a, $b ) => $b['count'] <=> $a['count'] );
		return $out;
	}

	/**
	 * Sum-merge per-partition hourly buckets into one sorted time_series.
	 * Same contract as Events_CI's stats verb.
	 */
	private static function merge_hourly_across_partitions( ?Cache_Interface $cache ): array {
		$merged = [];
		foreach ( self::stats_stores( $cache ) as $store ) {
			foreach ( $store->get_hourly() as $hour => $row ) {
				$merged[ $hour ] ??= [
					'hour'        => $hour,
					'count'       => 0,
					'sum_ms'      => 0.0,
					'sum_peak_mb' => 0.0,
				];
				$merged[ $hour ]['count']       += (int) ( $row['count'] ?? 0 );
				$merged[ $hour ]['sum_ms']      += (float) ( $row['sum_ms'] ?? 0 );
				$merged[ $hour ]['sum_peak_mb'] += (float) ( $row['sum_peak_mb'] ?? 0 );
			}
		}
		\ksort( $merged );
		return \array_values( $merged );
	}

	/**
	 * Compose the overview payload shape from a pre-loaded URL index.
	 * Shared by the `overview` and `dashboard` verbs — `dashboard` wraps
	 * this alongside the same `$index` to avoid a second memcache fan-out.
	 *
	 * @param array<int,array<string,mixed>> $index Output of self::load_index().
	 */
	private static function build_overview_payload( array $index, ?Cache_Interface $cache ): array {
		$time_series       = self::merge_hourly_across_partitions( $cache );
		$total_requests    = 0;
		$total_sum_ms      = 0.0;
		$total_sum_peak_mb = 0.0;
		foreach ( $time_series as $row ) {
			$total_requests    += (int) ( $row['count'] ?? 0 );
			$total_sum_ms      += (float) ( $row['sum_ms'] ?? 0 );
			$total_sum_peak_mb += (float) ( $row['sum_peak_mb'] ?? 0 );
		}

		$slowest = $index;
		\usort( $slowest, static fn ( $a, $b ) => ( $b['p95_ms'] ?? 0 ) <=> ( $a['p95_ms'] ?? 0 ) );

		return [
			'total_urls'            => \count( $index ),
			'total_requests'        => $total_requests,
			'global_avg_ms'         => $total_requests > 0 ? $total_sum_ms / $total_requests : 0.0,
			'global_avg_peak_mb'    => $total_requests > 0 ? $total_sum_peak_mb / $total_requests : 0.0,
			'slowest_urls'          => \array_slice( $slowest, 0, 10 ),
			'most_requested'        => \array_slice( $index, 0, 10 ),
			'aggregate_time_series' => $time_series,
		];
	}

	/**
	 * Pull the per-URL aggregate stats blob (flame, profiles, last_modified).
	 * First partition with a matching blob wins — matches legacy
	 * PerfUrlsController::find_url_aggregate.
	 */
	private static function find_url_aggregate( string $hash, ?Cache_Interface $cache ): ?array {
		foreach ( self::stats_stores( $cache ) as $store ) {
			$stats = $store->get_url_stats( $hash );
			if ( null !== $stats ) {
				return $stats;
			}
		}
		return null;
	}

	// -------------------------------------------------------------------------
	// Disk-walking helpers — recent requests + request body lookup + flame.
	// -------------------------------------------------------------------------

	/**
	 * Walk `requests.log` partitions and collect the 500 most-recent index
	 * entries for the given url_hash. Mirror of
	 * PerfUrlsController::find_recent_requests_for_url.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function find_recent_requests_for_url( string $url_hash ): array {
		$config         = RuntimeConfig::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';

		$requests      = [];
		$entries_count = 0;
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$partition = ( new Partition( "{$log_base}/requests.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => RequestBuilder::format_index_entry( $line, $position, $data )
			);
			$partition->scan_index(
				static function ( string $line, int $segment_id ) use ( &$requests, &$entries_count, $url_hash, $p ): ?bool {
					++$entries_count;
					if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = RequestBuilder::parse_request_index( $line );
					if ( ! \is_array( $entry ) || \trim( (string) $entry['url_hash'] ) !== $url_hash ) {
						return null;
					}
					$requests[] = [
						'rid'          => \trim( (string) $entry['rid'] ),
						'timestamp'    => $entry['timestamp'] ?? 0,
						'duration_ms'  => $entry['duration_ms'] ?? 0,
						'status_code'  => $entry['status_code'] ?? 0,
						'peak_mb'      => $entry['peak_mb'] ?? 0,
						'method'       => $entry['method'] ?? '',
						'error_status' => $entry['error_status'] ?? null,
						'segment_id'   => $entry['segment_id'] ?? $segment_id,
						'offset'       => $entry['offset'] ?? 0,
						'length'       => $entry['length'] ?? 0,
						'partition'    => $p,
					];
					return \count( $requests ) >= 500 ? false : null;
				},
				true
			);
			if ( \count( $requests ) >= 500 || $entries_count > self::MAX_INDEX_ENTRIES ) {
				break;
			}
		}

		\usort( $requests, static fn ( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		$seen   = [];
		$unique = [];
		foreach ( $requests as $r ) {
			if ( ! isset( $seen[ $r['rid'] ] ) ) {
				$seen[ $r['rid'] ] = true;
				$unique[]          = $r;
				if ( \count( $unique ) >= 500 ) {
					break;
				}
			}
		}
		return $unique;
	}

	/**
	 * Locate a single request index entry by rid in one partition.
	 * Returns the legacy search shape: `{rid, partition, url_hash}`.
	 */
	private static function find_request_index_entry( string $log_base, int $partition, string $rid, int &$entries_count ): ?array {
		$result   = null;
		$requests = ( new Partition( "{$log_base}/requests.log", $partition ) )->with_index(
			static fn ( $line, $position, &$data = null ) => RequestBuilder::format_index_entry( $line, $position, $data )
		);
		$requests->scan_index(
			static function ( string $line ) use ( &$result, &$entries_count, $partition, $rid ): ?bool {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = RequestBuilder::parse_request_index( $line );
				if ( ! \is_array( $entry ) || \trim( (string) $entry['rid'] ) !== $rid ) {
					return null;
				}
				$result = [
					'rid'       => $rid,
					'partition' => $partition,
					'url_hash'  => \trim( (string) $entry['url_hash'] ),
				];
				return false;
			},
			true
		);
		return $result;
	}

	/**
	 * Read the full request body from a known partition + optionally merge
	 * any matching flame_data. Mirror of
	 * PerfRequestsController::find_request_in_partition.
	 */
	private static function find_request_in_partition( string $log_base, int $partition, string $rid, int $num_partitions ): ?array {
		$result        = null;
		$entries_count = 0;
		$requests      = ( new Partition( "{$log_base}/requests.log", $partition ) )->with_index(
			static fn ( $line, $position, &$data = null ) => RequestBuilder::format_index_entry( $line, $position, $data )
		);
		$requests->scan_index(
			static function ( string $line ) use ( &$result, &$entries_count, $requests, $rid ): ?bool {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = RequestBuilder::parse_request_index( $line );
				if ( ! \is_array( $entry ) || \trim( (string) $entry['rid'] ) !== $rid ) {
					return null;
				}
				$data = $requests->read_at(
					(int) ( $entry['segment_id'] ?? 0 ),
					(int) ( $entry['offset'] ?? 0 ),
					(int) ( $entry['length'] ?? 0 )
				);
				if ( '' === $data ) {
					return false;
				}
				$decoded = \json_decode( \trim( $data ), true, 64 );
				$req     = \is_array( $decoded ) ? ( $decoded[ Message::VALUE ] ?? null ) : null;
				if ( ! \is_array( $req ) ) {
					return false;
				}
				$req['url_hash'] = \trim( (string) $entry['url_hash'] );
				$result          = $req;
				return false;
			},
			true
		);

		if ( null === $result ) {
			return null;
		}

		$flame = self::find_flame_for_rid( $log_base, $rid, $num_partitions );
		if ( null !== $flame ) {
			$result['flame_data'] = $flame;
		}
		return $result;
	}

	/**
	 * Search every flame partition for a flame entry matching the rid; the
	 * first hit wins. FlameBuilder writes to whatever partition it's wired
	 * into, so a per-rid lookup has to fan out across all of them.
	 */
	private static function find_flame_for_rid( string $log_base, string $rid, int $num_partitions ): ?array {
		$entries_count = 0;
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$flames = ( new Partition( "{$log_base}/flames.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => FlameBuilder::format_index_entry( $line, $position, $data )
			);
			$result = null;
			$flames->scan_index(
				static function ( string $line ) use ( &$result, &$entries_count, $flames, $rid ): ?bool {
					++$entries_count;
					if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = FlameBuilder::parse_flame_index( $line );
					if ( ! \is_array( $entry ) || \trim( $entry['rid'] ) !== $rid ) {
						return null;
					}
					$data = $flames->read_at(
						$entry['segment_id'],
						$entry['offset'],
						$entry['length']
					);
					if ( '' === $data ) {
						return false;
					}
					$decoded = \json_decode( \trim( $data ), true, 64 );
					$flame   = \is_array( $decoded ) ? ( $decoded[ Message::VALUE ] ?? null ) : null;
					if ( \is_array( $flame ) ) {
						$result = $flame;
					}
					return false;
				},
				true
			);
			if ( null !== $result ) {
				return $result;
			}
			if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
				break;
			}
		}
		return null;
	}
}
