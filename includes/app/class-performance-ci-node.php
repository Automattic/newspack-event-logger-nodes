<?php
/**
 * Performance_CI: command-dispatch for the performance-dashboard surface.
 *
 * All 19 planned verbs. Replaces:
 *   - class-perf-overview-controller.php       (overview)
 *   - class-perf-urls-controller.php            (urls, url_detail)
 *   - class-perf-requests-controller.php        (request_search, request_detail)
 *   - class-performance-controller.php          (timing, dashboard)
 *   - class-perf-hooks-controller.php           (hooks_registered, hooks_categories)
 *   - class-perf-hooks-available-controller.php (hooks_available, hooks_configure)
 *   - class-perf-config-controller.php          (config_get, config_update)
 *   - class-perf-settings-controller.php        (settings_update)
 *   - class-gyroscope-controller.php            (SSE method stays as REST controller)
 *   - class-request-log-controller.php          (request_log_list, request_log_detail)
 *
 * SSE-style stream controllers (firehose-stream, gyroscope-stream,
 * errors-stream, requests-stream) stay as REST controllers — the
 * CommandInterpreter dispatch path doesn't stream.
 *
 * Cross-cutting design choices:
 *  - Auth: every verb requires `manage_options`. Legacy parity — every
 *    replaced controller gated through `PerformanceControllerBase::read_permissions_check`
 *    (or its `admin_permissions_check` cousin on the writers), which
 *    enforces the capability.
 *  - Rate limit: dropped. The legacy rate-limit was an artifact of REST
 *    polling; interpreter dispatch fires verbs once-per-request through the worker,
 *    not from a fan-out of polling tabs.
 *  - Stats reads fail-soft (matches Stats_Store + dashboards "no data" UX).
 *  - Disk scans capped at MAX_INDEX_ENTRIES so a missing-rid lookup can't
 *    escalate into a partition-wide segment walk.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Config as AppConfig;
use Newspack_Event_Logger_Nodes\Flame_Builder_Node;
use Newspack_Event_Logger_Nodes\Hook_Categorizer;
use Newspack_Event_Logger_Nodes\Request_Builder_Node;
use Newspack_Event_Logger_Nodes\Settings_Sync;
use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

class Performance_CI_Node extends Service_CI_Node {

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
	 * Valid breakdown dimensions for the `overview` / `url_detail` verbs.
	 * Echoes the legacy PerfOverviewController::DIMENSIONS whitelist — typos
	 * fall through without surfacing arbitrary memcache reads.
	 */
	private const DIMENSIONS = [ 'status', 'method', 'server', 'country', 'from', 'ua', 'ja4' ];

	/**
	 * `config_get` / `config_update` map: response-key → {option, type}.
	 * Mirrors PerfConfigController::CONFIG_MAP. Each `type` selects a
	 * coercion branch in the `config_update` verb (array_assoc flattens
	 * `{val:''}` into `[val]`; array_bool turns indexed lists into
	 * `{val:true}` maps; int/float/bool hard-cast).
	 *
	 * @var array<string,array{option:string,type:string}>
	 */
	private const CONFIG_MAP = [
		'log_events'                  => [ 'option' => 'newspack_event_logger_nodes_log_events',                  'type' => 'array_assoc' ],
		'custom_events'               => [ 'option' => 'newspack_event_logger_nodes_custom_events',               'type' => 'array_bool' ],
		'log_urls'                    => [ 'option' => 'newspack_event_logger_nodes_log_urls',                    'type' => 'array_assoc' ],
		'skip_urls'                   => [ 'option' => 'newspack_event_logger_nodes_skip_urls',                   'type' => 'array_assoc' ],
		'auto_disable_threshold'      => [ 'option' => 'newspack_event_logger_nodes_auto_disable_threshold',      'type' => 'int' ],
		'auto_protect_time_threshold' => [ 'option' => 'newspack_event_logger_nodes_auto_protect_time_threshold', 'type' => 'float' ],
		'significant_events'          => [ 'option' => 'newspack_event_logger_nodes_significant_events',          'type' => 'array_assoc' ],
		'log_memory'                  => [ 'option' => 'newspack_event_logger_nodes_log_memory',                  'type' => 'bool' ],
		'flush_every_line'            => [ 'option' => 'newspack_event_logger_nodes_flush_every_line',            'type' => 'bool' ],
	];

	/**
	 * `settings_update` whitelist: WP option name → sanitization type.
	 * Mirrors PerfSettingsController::ALLOWED_OPTIONS. The same nine
	 * perf-tuning options as CONFIG_MAP, keyed by the on-disk option name
	 * rather than the response shape — the settings verb takes a single
	 * {option, value} pair while config_update takes the response shape.
	 *
	 * @var array<string,string>
	 */
	private const SETTINGS_OPTIONS = [
		'newspack_event_logger_nodes_log_urls'                    => 'array',
		'newspack_event_logger_nodes_skip_urls'                   => 'array',
		'newspack_event_logger_nodes_log_events'                  => 'array',
		'newspack_event_logger_nodes_custom_events'               => 'array',
		'newspack_event_logger_nodes_auto_disable_threshold'      => 'int',
		'newspack_event_logger_nodes_auto_protect_time_threshold' => 'float',
		'newspack_event_logger_nodes_significant_events'          => 'array',
		'newspack_event_logger_nodes_log_memory'                  => 'bool',
		'newspack_event_logger_nodes_flush_every_line'            => 'bool',
	];

	/**
	 * Upper bound on settings_update integer values (2^30). Mirrors
	 * PerfSettingsController::sanitize_value `$int < 0 || $int > 1073741824`.
	 */
	private const SETTINGS_INT_MAX = 1073741824;

	/**
	 * Upper bound on settings_update float values (24h in seconds). Mirrors
	 * PerfSettingsController::sanitize_value `$f < 0 || $f > 86400`.
	 */
	private const SETTINGS_FLOAT_MAX = 86400;

	/**
	 * Maximum array element count + nesting depth for settings_update.
	 * Mirrors PerfSettingsController::MAX_EVENTS / sanitize_array depth cap.
	 */
	private const SETTINGS_ARRAY_MAX   = 10000;
	private const SETTINGS_ARRAY_DEPTH = 5;

	/**
	 * Default page size for `request_log_list`. Mirrors the legacy
	 * RequestLogController `limit` sanitize default.
	 */
	private const REQUEST_LIST_DEFAULT_LIMIT = 100;

	/**
	 * Upper bound on `request_log_list` page size. Mirrors the legacy
	 * RequestLogController sanitize_callback `min(1000, max(1, (int)$v))`.
	 */
	private const REQUEST_LIST_MAX_LIMIT = 1000;

	/**
	 * Resolve a Command_Args boolean flag. A bare `--flag` parses to `true`;
	 * A bare `--flag` and `--flag=1` / `--flag=true` are truthy; `--flag=0` /
	 * `--flag=false` and an absent key are false. (The matching false-set is the
	 * same one Servers_CI::option_bool uses, since those are the only tokens
	 * formatCommandArgs / the forwarder ever emit for a boolean.)
	 *
	 * @param array<string,string|true> $options Parsed options.
	 * @param string                    $key     Flag name.
	 */
	private static function flag( array $options, string $key ): bool {
		if ( ! \array_key_exists( $key, $options ) ) {
			return false;
		}
		$value = $options[ $key ];
		if ( true === $value ) {
			return true;
		}
		return ! \in_array( \strtolower( $value ), [ '0', 'false' ], true );
	}

	/**
	 * Split a Command_Args comma-list option into a trimmed, non-empty string
	 * array. An absent key or an empty/flag value yields `[]`.
	 *
	 * @param array<string,string|true> $options Parsed options.
	 * @param string                    $key     Option name.
	 * @return array<int,string>
	 */
	private static function csv( array $options, string $key ): array {
		$raw = $options[ $key ] ?? '';
		if ( true === $raw || '' === $raw ) {
			return [];
		}
		$out = [];
		foreach ( \explode( ',', $raw ) as $item ) {
			$item = \trim( $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Name (`{log}.{token}.p{N}`, unique per scan), self-patron, and Rule-4 interpreter-sink
	 * a transient scratch Partition. Callers remove_node() it after use.
	 *
	 * @param Partition_Node $partition Freshly-constructed scratch Partition.
	 * @param string         $log       Log basename ('requests' | 'flames').
	 * @param int            $index     Partition index.
	 */
	private static function name_scratch_partition( Partition_Node $partition, string $log, int $index ): void {
		$token = \getmypid() . '-' . \spl_object_id( $partition );
		$partition->name( "{$log}.{$token}.p{$index}" );
		$partition->patron( $partition );
		$ci = Core::node( Node_Names::COMMAND_INTERPRETER );
		if ( null === $partition->sink() && null !== $ci ) {
			$partition->sink( $ci );
		}
	}

	// -------------------------------------------------------------------------
	// Scalar coercion — decoded JSON / memcache blobs carry `mixed` leaf values;
	// these reproduce PHP's int/float/string cast for the scalar+null domain those
	// blobs actually hold, narrowing without changing any reachable value.
	// -------------------------------------------------------------------------

	/**
	 * Coerce a mixed leaf to int, reproducing `(int)` for scalar/null inputs.
	 *
	 * @param mixed $value Raw leaf value.
	 */
	private static function to_int( mixed $value ): int {
		return \is_scalar( $value ) ? (int) $value : 0;
	}

	/**
	 * Coerce a mixed leaf to float, reproducing `(float)` for scalar/null inputs.
	 *
	 * @param mixed $value Raw leaf value.
	 */
	private static function to_float( mixed $value ): float {
		return \is_scalar( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Coerce a mixed leaf to string, reproducing `(string)` for scalar/null inputs.
	 *
	 * @param mixed $value Raw leaf value.
	 */
	private static function to_string( mixed $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}

	// -------------------------------------------------------------------------
	// Config + settings value coercion — shared by config_update + settings_update.
	// Lifted from legacy PerfConfigController + PerfSettingsController.
	// -------------------------------------------------------------------------

	/**
	 * Coerce a CONFIG_MAP value to the on-disk shape. Used by `config_update`.
	 * Branch logic mirrors PerfConfigController::update_config:
	 *  - array_assoc: flatten `{val:''}` / indexed string list → unique value list
	 *  - array_bool:  indexed string list → `{val:true}` map; assoc → cast bools
	 *  - int / float / bool: hard cast
	 *
	 * @param mixed  $value Raw input.
	 * @param string $type  CONFIG_MAP type tag.
	 * @return mixed Coerced value.
	 */
	private static function coerce_config_value( mixed $value, string $type ): mixed {
		switch ( $type ) {
			case 'array_assoc':
				if ( ! \is_array( $value ) ) {
					return $value;
				}
				$flat = [];
				foreach ( $value as $k => $v ) {
					if ( \is_string( $v ) && '' !== $v ) {
						$flat[] = $v;
					} elseif ( \is_string( $k ) && '' !== $k ) {
						$flat[] = $k;
					}
				}
				return \array_values( \array_unique( $flat ) );

			case 'array_bool':
				if ( ! \is_array( $value ) ) {
					return $value;
				}
				$assoc = [];
				foreach ( $value as $k => $v ) {
					if ( \is_int( $k ) && \is_string( $v ) ) {
						$assoc[ $v ] = true;
					} elseif ( \is_string( $k ) && '' !== $k ) {
						$assoc[ $k ] = (bool) $v;
					}
				}
				return $assoc;

			case 'int':
				return self::to_int( $value );
			case 'float':
				return self::to_float( $value );
			case 'bool':
				return (bool) $value;
		}
		return $value;
	}

	/**
	 * Type-coerce + bounds-check a single value for `settings_update`. Mirrors
	 * PerfSettingsController::sanitize_value — returns null when rejected.
	 *
	 * @param mixed  $value Raw input.
	 * @param string $type  One of int|float|bool|array.
	 * @return mixed|null Sanitized value, or null to reject.
	 */
	private static function sanitize_settings_value( mixed $value, string $type ): mixed {
		switch ( $type ) {
			case 'int':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				$int = (int) $value;
				if ( $int < 0 || $int > self::SETTINGS_INT_MAX ) {
					return null;
				}
				return $int;
			case 'float':
				if ( ! \is_numeric( $value ) ) {
					return null;
				}
				$f = (float) $value;
				if ( $f < 0 || $f > self::SETTINGS_FLOAT_MAX ) {
					return null;
				}
				return $f;
			case 'bool':
				return (bool) $value;
			case 'array':
				if ( ! \is_array( $value ) ) {
					return null;
				}
				return self::sanitize_settings_array( $value );
		}
		return null;
	}

	/**
	 * Bounded-recursion array sanitizer for `settings_update`. Mirrors
	 * PerfSettingsController::sanitize_array — depth cap SETTINGS_ARRAY_DEPTH,
	 * size cap SETTINGS_ARRAY_MAX, text fields run through sanitize_text_field.
	 *
	 * @param array<mixed,mixed> $arr   Input array.
	 * @param int                $depth Current recursion depth.
	 * @return array<mixed,mixed>|null Sanitized array, or null if too deep/large.
	 */
	private static function sanitize_settings_array( array $arr, int $depth = 0 ): ?array {
		if ( $depth > self::SETTINGS_ARRAY_DEPTH ) {
			return null;
		}
		if ( \count( $arr ) > self::SETTINGS_ARRAY_MAX ) {
			return null;
		}
		$out = [];
		foreach ( $arr as $key => $value ) {
			$safe_key = \is_int( $key ) ? $key : \sanitize_text_field( $key );
			if ( \is_string( $value ) ) {
				$out[ $safe_key ] = \sanitize_text_field( $value );
			} elseif ( \is_bool( $value ) || \is_int( $value ) || \is_float( $value ) ) {
				$out[ $safe_key ] = $value;
			} elseif ( \is_array( $value ) ) {
				$nested = self::sanitize_settings_array( $value, $depth + 1 );
				if ( null === $nested ) {
					return null;
				}
				$out[ $safe_key ] = $nested;
			}
		}
		return $out;
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
				if ( Hook_Categorizer::is_internal( $name ) ) {
					continue;
				}
				$hooks[ $name ] = [
					'name'     => $name,
					'category' => Hook_Categorizer::categorize( $name ),
					'count'    => self::to_int( $count ),
				];
			}
		}

		if ( isset( $wp_filter ) && ( \is_array( $wp_filter ) || $wp_filter instanceof \Traversable ) ) {
			foreach ( $wp_filter as $hook_name => $callbacks ) {
				$name = self::to_string( $hook_name );
				if ( Hook_Categorizer::is_internal( $name ) ) {
					continue;
				}
				// $wp_actions count takes precedence — only add if missing.
				if ( ! isset( $hooks[ $name ] ) ) {
					$hooks[ $name ] = [
						'name'     => $name,
						'category' => Hook_Categorizer::categorize( $name ),
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
	 * One Stats_Store per partition over the shared `Core::$memd` handle.
	 *
	 * @return array<int,Stats_Store>
	 */
	private static function stats_stores(): array {
		if ( null === Core::$memd ) {
			return [];
		}
		$config         = RuntimeConfig::load_config();
		$num_partitions = self::to_int( $config['num_partitions'] ?? 1 );
		$max_lifespan   = self::to_int( $config['max_lifespan'] ?? 86400 );
		$stores         = [];
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$stores[] = new Stats_Store( $p, $max_lifespan );
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
	private static function load_index(): array {
		$buckets = self::recent_url_buckets();
		$result  = [];
		foreach ( self::stats_stores() as $store ) {
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
					$stat_arr = \is_array( $stats ) ? $stats : [];
					if ( isset( $stat_arr['url'] ) ) {
						$url  = self::to_string( $stat_arr['url'] );
						$hash = (string) $hash_or_url;
					} else {
						$url  = (string) $hash_or_url;
						$hash = \substr( \hash( 'sha256', $url ), 0, 12 );
					}
					if ( ! isset( $result[ $hash ] ) ) {
						$result[ $hash ] = [
							'hash'        => $hash,
							'url'         => $url,
							'count'       => 0,
							'count_2xx'   => 0,
							'count_3xx'   => 0,
							'count_4xx'   => 0,
							'count_5xx'   => 0,
							'sum_ms'      => 0.0,
							'max_ms'      => 0.0,
							'p50_ms'      => 0.0,
							'p95_ms'      => 0.0,
							'p99_ms'      => 0.0,
							'sum_peak_mb' => 0.0,
							'max_peak_mb' => 0.0,
							'last_seen'   => 0,
						];
					}
					$entry              = $result[ $hash ];
					$entry['count']     += self::to_int( $stat_arr['count']     ?? 0 );
					$entry['count_2xx'] += self::to_int( $stat_arr['count_2xx'] ?? 0 );
					$entry['count_3xx'] += self::to_int( $stat_arr['count_3xx'] ?? 0 );
					$entry['count_4xx'] += self::to_int( $stat_arr['count_4xx'] ?? 0 );
					$entry['count_5xx'] += self::to_int( $stat_arr['count_5xx'] ?? 0 );
					// FlameBuilder bucket has `sum_ms` directly; StatsAggregator
					// bucket has `sum_req_time` in seconds — accept either.
					$entry['sum_ms']      += isset( $stat_arr['sum_ms'] )
						? self::to_float( $stat_arr['sum_ms'] )
						: self::to_float( $stat_arr['sum_req_time'] ?? 0 ) * 1000.0;
					$entry['sum_peak_mb'] += self::to_float( $stat_arr['sum_peak_mb'] ?? 0 );
					// `min_ms` is optional on the entry — only seeded once a
					// stat-with-min_ms arrives, so the missing-key path stays
					// distinguishable from a legitimate 0.0 min. Mirror the write
					// side: fold only from buckets that actually have timing.
					// Untimed-only buckets (timed_count 0, min_ms 0 or a poisoned
					// PHP_INT_MAX) are skipped, so neither clamps the merged min
					// and old poison heals to 0 at display.
					if ( isset( $stat_arr['min_ms'] ) && ( $stat_arr['timed_count'] ?? 0 ) > 0 ) {
						$stat_min        = self::to_float( $stat_arr['min_ms'] );
						$entry['min_ms'] = isset( $entry['min_ms'] )
							? \min( self::to_float( $entry['min_ms'] ), $stat_min )
							: $stat_min;
					}
					$entry['max_ms']      = \max( self::to_float( $entry['max_ms'] ),      self::to_float( $stat_arr['max_ms']      ?? 0 ) );
					$entry['max_peak_mb'] = \max( self::to_float( $entry['max_peak_mb'] ), self::to_float( $stat_arr['max_peak_mb'] ?? 0 ) );
					foreach ( [ 'p50_ms', 'p95_ms', 'p99_ms' ] as $k ) {
						if ( ! empty( $stat_arr[ $k ] ) ) {
							$entry[ $k ] = self::to_float( $stat_arr[ $k ] );
						}
					}
					$entry['last_seen'] = \max(
						self::to_int( $entry['last_seen'] ),
						self::to_int( $stat_arr['last_seen'] ?? 0 )
					);
					$result[ $hash ] = $entry;
				}
			}
		}

		// Convert into the display shape the React tree expects.
		$out = [];
		foreach ( $result as $entry ) {
			$count = $entry['count'];
			$denom = \max( 1, $count );
			$out[] = [
				'hash'         => $entry['hash'],
				'url'          => $entry['url'],
				'count'        => $count,
				'count_2xx'    => $entry['count_2xx'],
				'count_3xx'    => $entry['count_3xx'],
				'count_4xx'    => $entry['count_4xx'],
				'count_5xx'    => $entry['count_5xx'],
				'avg_ms'       => $entry['sum_ms'] / $denom,
				'min_ms'       => (float) ( $entry['min_ms'] ?? 0 ),
				'max_ms'       => $entry['max_ms'],
				'p50_ms'       => $entry['p50_ms'],
				'p95_ms'       => $entry['p95_ms'],
				'p99_ms'       => $entry['p99_ms'],
				'avg_peak_mb'  => $entry['sum_peak_mb'] / $denom,
				'max_peak_mb'  => $entry['max_peak_mb'],
				'last_updated' => $entry['last_seen'],
			];
		}
		\usort( $out, static fn ( $a, $b ) => $b['count'] <=> $a['count'] );
		return $out;
	}

	/**
	 * Sum-merge per-partition hourly buckets into one sorted time_series.
	 * Same contract as Events_CI's stats verb.
	 *
	 * @return array<int, mixed>
	 */
	private static function merge_hourly_across_partitions(): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			foreach ( $store->get_hourly() as $hour => $row ) {
				$row_arr = \is_array( $row ) ? $row : [];
				$merged[ $hour ] ??= [
					'hour'        => $hour,
					'count'       => 0,
					'sum_ms'      => 0.0,
					'sum_peak_mb' => 0.0,
				];
				$merged[ $hour ]['count']       += self::to_int( $row_arr['count'] ?? 0 );
				$merged[ $hour ]['sum_ms']      += self::to_float( $row_arr['sum_ms'] ?? 0 );
				$merged[ $hour ]['sum_peak_mb'] += self::to_float( $row_arr['sum_peak_mb'] ?? 0 );
			}
		}
		\ksort( $merged );
		return \array_values( $merged );
	}

	/**
	 * Walk every recent URL bucket for the given hash and emit a per-bucket
	 * `{count, sum_ms, sum_peak_mb}` time series. Mirrors
	 * PerfUrlsController::build_url_time_series.
	 * @return array<string, mixed>
	 */
	private static function build_url_time_series( string $hash ): array {
		$buckets = self::recent_url_buckets();
		$series  = [];
		foreach ( self::stats_stores() as $store ) {
			$rows = $store->get_url_buckets( $buckets );
			foreach ( $rows as $bucket_key => $bucket_data ) {
				if ( ! \is_array( $bucket_data ) || ! isset( $bucket_data[ $hash ] ) ) {
					continue;
				}
				$stats = \is_array( $bucket_data[ $hash ] ) ? $bucket_data[ $hash ] : [];
				$count = self::to_int( $stats['count'] ?? 0 );
				if ( 0 === $count ) {
					continue;
				}
				// FlameBuilder buckets carry `sum_ms` directly; StatsAggregator
				// buckets carry `sum_req_time` in seconds — accept either.
				$sum_ms = isset( $stats['sum_ms'] )
					? self::to_float( $stats['sum_ms'] )
					: self::to_float( $stats['sum_req_time'] ?? 0 ) * 1000.0;
				$series[ $bucket_key ] ??= [ 'count' => 0, 'sum_ms' => 0.0, 'sum_peak_mb' => 0.0 ];
				$series[ $bucket_key ]['count']       += $count;
				$series[ $bucket_key ]['sum_ms']      += $sum_ms;
				$series[ $bucket_key ]['sum_peak_mb'] += self::to_float( $stats['sum_peak_mb'] ?? 0 );
			}
		}
		\ksort( $series );
		return $series;
	}

	/**
	 * Build the merged global category leaderboard for the recent window.
	 * Mirror of PerfOverviewController::build_global_leaderboard.
	 * @return array<string, mixed>
	 */
	private static function build_global_leaderboard(): array {
		$count        = 0;
		$sum_req_time = 0.0;
		$sums         = [];
		$buckets      = self::recent_url_buckets();
		foreach ( self::stats_stores() as $store ) {
			foreach ( $buckets as $b ) {
				$row = $store->get_leaderboard_bucket( $b );
				if ( empty( $row ) ) {
					continue;
				}
				$count        += self::to_int( $row['count'] ?? 0 );
				$sum_req_time += self::to_float( $row['sum_req_time'] ?? 0 );
				/** @var array<string,mixed> $categories -- decoded memcache leaderboard blob, keyed by category name. */
				$categories    = \is_array( $row['categories'] ?? null ) ? $row['categories'] : [];
				self::accumulate_leaderboard_categories( $sums, $categories );
			}
		}
		return Stats_Store::sums_to_display( $count, $sum_req_time, $sums );
	}

	/**
	 * Build the per-server category leaderboard for the recent window.
	 * Mirror of PerfOverviewController::build_server_leaderboard.
	 * @return array<string, mixed>
	 */
	private static function build_server_leaderboard( string $server ): array {
		$count        = 0;
		$sum_req_time = 0.0;
		$sums         = [];
		$buckets      = self::recent_url_buckets();
		foreach ( self::stats_stores() as $store ) {
			foreach ( $buckets as $b ) {
				$row = $store->get_server_leaderboard_bucket( $server, $b );
				if ( empty( $row ) ) {
					continue;
				}
				$count        += self::to_int( $row['count'] ?? 0 );
				$sum_req_time += self::to_float( $row['sum_req_time'] ?? 0 );
				/** @var array<string,mixed> $categories -- decoded memcache leaderboard blob, keyed by category name. */
				$categories    = \is_array( $row['categories'] ?? null ) ? $row['categories'] : [];
				self::accumulate_leaderboard_categories( $sums, $categories );
			}
		}
		return Stats_Store::sums_to_display( $count, $sum_req_time, $sums );
	}

	/**
	 * Sum-merge a single leaderboard bucket's categories into the running totals.
	 * Used by both global + server leaderboard builders.
	 *
	 * @param array<string,array{samples:int,sum_time:float,sum_count:float,entries:array<int, mixed>}> $sums       Running totals (mutated).
	 * @param array<string,mixed>                                                             $categories Inbound categories.
	 */
	private static function accumulate_leaderboard_categories( array &$sums, array $categories ): void {
		foreach ( $categories as $cat => $data ) {
			$data_arr = \is_array( $data ) ? $data : [];
			$sums[ $cat ] ??= [
				'samples'   => 0,
				'sum_time'  => 0.0,
				'sum_count' => 0.0,
				'entries'   => [],
			];
			$sums[ $cat ]['samples']   += self::to_int( $data_arr['samples'] ?? 0 );
			$sums[ $cat ]['sum_time']  += self::to_float( $data_arr['sum_time'] ?? 0 );
			$sums[ $cat ]['sum_count'] += self::to_float( $data_arr['sum_count'] ?? 0 );
		}
	}

	/**
	 * Sum-merge dimensional buckets across all partitions for one dim/server.
	 * Mirror of PerfOverviewController::merge_dim_across_partitions.
	 * @return array<string, mixed>
	 */
	private static function merge_dim_across_partitions( string $dimension, string $server ): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			foreach ( $store->get_dimensional( $dimension, $server ) as $bucket => $values ) {
				$merged[ $bucket ] ??= [];
				if ( ! \is_array( $values ) ) {
					continue;
				}
				foreach ( $values as $name => $entry ) {
					$entry_arr = \is_array( $entry ) ? $entry : [];
					$merged[ $bucket ][ $name ] ??= [ 'c' => 0, 's' => 0.0, 'm' => 0.0 ];
					$merged[ $bucket ][ $name ]['c'] += self::to_int( $entry_arr['c'] ?? 0 );
					$merged[ $bucket ][ $name ]['s'] += self::to_float( $entry_arr['s'] ?? 0 );
					$merged[ $bucket ][ $name ]['m'] += self::to_float( $entry_arr['m'] ?? 0 );
				}
			}
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge category buckets across all partitions (global scope).
	 * Mirror of PerfOverviewController::merge_categories_across_partitions.
	 * @return array<string, mixed>
	 */
	private static function merge_categories_across_partitions(): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			self::merge_category_buckets_into( $merged, $store->get_categories() );
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge per-server category buckets across all partitions.
	 * Mirror of PerfOverviewController::merge_server_categories_across_partitions.
	 * @return array<string, mixed>
	 */
	private static function merge_server_categories_across_partitions( string $server ): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			self::merge_category_buckets_into( $merged, $store->get_server_categories( $server ) );
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge per-URL dimensional buckets for one dim/hash.
	 * Mirror of PerfUrlsController::merge_url_dim.
	 * @return array<array-key, mixed> Bucket keys derive from decoded memcache blobs.
	 */
	private static function merge_url_dim( string $hash, string $dimension ): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			$rows = $store->get_url_dimensional( $hash );
			$dim  = $rows[ $dimension ] ?? [];
			if ( ! \is_array( $dim ) ) {
				continue;
			}
			foreach ( $dim as $bucket => $values ) {
				$merged[ $bucket ] ??= [];
				if ( ! \is_array( $values ) ) {
					continue;
				}
				foreach ( $values as $name => $entry ) {
					$entry_arr = \is_array( $entry ) ? $entry : [];
					$merged[ $bucket ][ $name ] ??= [ 'c' => 0, 's' => 0.0, 'm' => 0.0 ];
					$merged[ $bucket ][ $name ]['c'] += self::to_int( $entry_arr['c'] ?? 0 );
					$merged[ $bucket ][ $name ]['s'] += self::to_float( $entry_arr['s'] ?? 0 );
					$merged[ $bucket ][ $name ]['m'] += self::to_float( $entry_arr['m'] ?? 0 );
				}
			}
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge per-URL category buckets for one hash.
	 * Mirror of PerfUrlsController::merge_url_categories.
	 * @return array<string, mixed>
	 */
	private static function merge_url_categories( string $hash ): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			self::merge_category_buckets_into( $merged, $store->get_url_categories( $hash ) );
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Helper for the four category-merge variants (global / server / url + the
	 * url_detail call). All four iterate `[bucket => [cat => {t,c,n}]]` shaped
	 * blobs the exact same way.
	 *
	 * @param array<string,array<string,array{t:float,c:float,n:int}>> $merged Mutated.
	 * @param array<string,mixed>                                       $rows   Inbound.
	 */
	private static function merge_category_buckets_into( array &$merged, array $rows ): void {
		foreach ( $rows as $bucket => $values ) {
			$merged[ $bucket ] ??= [];
			if ( ! \is_array( $values ) ) {
				continue;
			}
			foreach ( $values as $cat => $entry ) {
				$entry_arr = \is_array( $entry ) ? $entry : [];
				$merged[ $bucket ][ $cat ] ??= [ 't' => 0.0, 'c' => 0.0, 'n' => 0 ];
				$merged[ $bucket ][ $cat ]['t'] += self::to_float( $entry_arr['t'] ?? 0 );
				$merged[ $bucket ][ $cat ]['c'] += self::to_float( $entry_arr['c'] ?? 0 );
				$merged[ $bucket ][ $cat ]['n'] += self::to_int( $entry_arr['n'] ?? 0 );
			}
		}
	}

	/**
	 * Compose the overview payload shape from a pre-loaded URL index.
	 * Shared by the `overview` and `dashboard` verbs — `dashboard` wraps
	 * this alongside the same `$index` to avoid a second memcache fan-out.
	 *
	 * @param array<int,array<string,mixed>> $index Output of self::load_index().
	 * @return array<string, mixed>
	 */
	private static function build_overview_payload( array $index ): array {
		$time_series       = self::merge_hourly_across_partitions();
		$total_requests    = 0;
		$total_sum_ms      = 0.0;
		$total_sum_peak_mb = 0.0;
		foreach ( $time_series as $row ) {
			$row_arr            = \is_array( $row ) ? $row : [];
			$total_requests    += self::to_int( $row_arr['count'] ?? 0 );
			$total_sum_ms      += self::to_float( $row_arr['sum_ms'] ?? 0 );
			$total_sum_peak_mb += self::to_float( $row_arr['sum_peak_mb'] ?? 0 );
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
	 * @return array<array-key, mixed>|null Decoded per-URL stats blob from get_url_stats().
	 */
	private static function find_url_aggregate( string $hash ): ?array {
		foreach ( self::stats_stores() as $store ) {
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
		$num_partitions = self::to_int( $config['num_partitions'] ?? 1 );
		$base_dir       = RuntimeConfig::get_base_directory();
		$log_base       = $base_dir . '/logs';

		$requests      = [];
		$entries_count = 0;
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$partition = new Partition_Node();
			self::name_scratch_partition( $partition, 'requests', $p );
			$partition->arguments( "{$log_base}/requests.p{$p}" );
			$partition->with_index(
				static function ( string $line, array $position, &$data = null ): ?string {
					/** @var array<string,int> $position -- with_index() callback contract; the substrate always passes {segment_id,offset,length}. */
					/** @var array<string,mixed>|\stdClass|null $data -- by-ref pre-decoded payload from the formatter. */
					return Request_Builder_Node::format_index_entry( $line, $position, $data );
				}
			);
			$partition->scan_index(
				static function ( string $line, int $segment_id ) use ( &$requests, &$entries_count, $url_hash, $p ): ?bool {
					++$entries_count;
					if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = Request_Builder_Node::parse_request_index( $line );
					if ( ! \is_array( $entry ) || \trim( self::to_string( $entry['url_hash'] ) ) !== $url_hash ) {
						return null;
					}
					$requests[] = [
						'rid'          => \trim( self::to_string( $entry['rid'] ) ),
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
			$partition->remove_node();
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
	 * @return array<string, mixed>
	 */
	private static function find_request_index_entry( string $log_base, int $partition, string $rid, int &$entries_count ): ?array {
		$result   = null;
		$requests = new Partition_Node();
		self::name_scratch_partition( $requests, 'requests', $partition );
		$requests->arguments( "{$log_base}/requests.p{$partition}" );
		$requests->with_index(
			static function ( string $line, array $position, &$data = null ): ?string {
				/** @var array<string,int> $position -- with_index() callback contract; the substrate always passes {segment_id,offset,length}. */
				/** @var array<string,mixed>|\stdClass|null $data -- by-ref pre-decoded payload from the formatter. */
				return Request_Builder_Node::format_index_entry( $line, $position, $data );
			}
		);
		$requests->scan_index(
			static function ( string $line ) use ( &$result, &$entries_count, $partition, $rid ): ?bool {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = Request_Builder_Node::parse_request_index( $line );
				if ( ! \is_array( $entry ) || \trim( self::to_string( $entry['rid'] ) ) !== $rid ) {
					return null;
				}
				$result = [
					'rid'       => $rid,
					'partition' => $partition,
					'url_hash'  => \trim( self::to_string( $entry['url_hash'] ) ),
				];
				return false;
			},
			true
		);
		$requests->remove_node();
		return $result;
	}

	/**
	 * Read the full request body from a known partition + optionally merge
	 * any matching flame_data. Mirror of
	 * PerfRequestsController::find_request_in_partition.
	 * @return array<array-key, mixed>|null Decoded request body (keys come from the JSON envelope).
	 */
	private static function find_request_in_partition( string $log_base, int $partition, string $rid, int $num_partitions ): ?array {
		$result        = null;
		$entries_count = 0;
		$requests = new Partition_Node();
		self::name_scratch_partition( $requests, 'requests', $partition );
		$requests->arguments( "{$log_base}/requests.p{$partition}" );
		$requests->with_index(
			static function ( string $line, array $position, &$data = null ): ?string {
				/** @var array<string,int> $position -- with_index() callback contract; the substrate always passes {segment_id,offset,length}. */
				/** @var array<string,mixed>|\stdClass|null $data -- by-ref pre-decoded payload from the formatter. */
				return Request_Builder_Node::format_index_entry( $line, $position, $data );
			}
		);
		$requests->scan_index(
			static function ( string $line ) use ( &$result, &$entries_count, $requests, $rid ): ?bool {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = Request_Builder_Node::parse_request_index( $line );
				if ( ! \is_array( $entry ) || \trim( self::to_string( $entry['rid'] ) ) !== $rid ) {
					return null;
				}
				$data = $requests->read_at(
					self::to_int( $entry['segment_id'] ?? 0 ),
					self::to_int( $entry['offset'] ?? 0 ),
					self::to_int( $entry['length'] ?? 0 )
				);
				if ( '' === $data ) {
					return false;
				}
				$decoded = \json_decode( \trim( $data ), true, 64 );
				$req     = \is_array( $decoded ) ? ( $decoded[ Message::VALUE ] ?? null ) : null;
				if ( ! \is_array( $req ) ) {
					return false;
				}
				$req['url_hash'] = \trim( self::to_string( $entry['url_hash'] ) );
				$result          = $req;
				return false;
			},
			true
		);
		$requests->remove_node();

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
	 * Collect index entries across all requests.log partitions up to the
	 * supplied limit, capped at MAX_INDEX_ENTRIES per partition. Mirrors
	 * RequestLogController::get_list.
	 *
	 * @param int $limit Soft cap; the caller sorts + slices after.
	 * @return array{0:array<int,array<string,mixed>>,1:int} Tuple of entries + scanned.
	 */
	private static function collect_request_list( int $limit ): array {
		$config         = RuntimeConfig::load_config();
		$num_partitions = self::to_int( $config['num_partitions'] ?? 1 );
		$base_dir       = RuntimeConfig::get_base_directory();
		$log_base       = $base_dir . '/logs';

		$entries = [];
		$scanned = 0;

		for ( $p = 0; $p < $num_partitions && \count( $entries ) < $limit; $p++ ) {
			$partition = new Partition_Node();
			self::name_scratch_partition( $partition, 'requests', $p );
			$partition->arguments( "{$log_base}/requests.p{$p}" );
			$partition->with_index(
				static function ( string $line, array $position, &$data = null ): ?string {
				/** @var array<string,int> $position -- with_index() callback contract; the substrate always passes {segment_id,offset,length}. */
				/** @var array<string,mixed>|\stdClass|null $data -- by-ref pre-decoded payload from the formatter. */
				return Request_Builder_Node::format_index_entry( $line, $position, $data );
			}
			);
			$partition->scan_index(
				static function ( string $line ) use ( &$entries, &$scanned, $limit, $p ): ?bool {
					++$scanned;
					if ( $scanned > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					if ( \count( $entries ) >= $limit ) {
						return false;
					}
					$parsed = Request_Builder_Node::parse_request_index( $line );
					if ( ! \is_array( $parsed ) ) {
						return null;
					}
					$entries[] = [
						'rid'          => \trim( self::to_string( $parsed['rid'] ?? '' ) ),
						'url_hash'     => \trim( self::to_string( $parsed['url_hash'] ?? '' ) ),
						'timestamp'    => $parsed['timestamp']    ?? 0,
						'duration_ms'  => $parsed['duration_ms']  ?? 0,
						'status_code'  => $parsed['status_code']  ?? 0,
						'peak_mb'      => $parsed['peak_mb']      ?? 0,
						'method'       => $parsed['method']       ?? '',
						'error_status' => $parsed['error_status'] ?? null,
						'partition'    => $p,
					];
					return null;
				},
				true
			);
			$partition->remove_node();
		}

		return [ $entries, $scanned ];
	}

	/**
	 * Fan out across every requests.log partition looking for one rid; the
	 * first hit wins. Returns the decoded request body (with `_partition`
	 * stamped on it). Mirrors RequestLogController::get_detail's scan.
	 *
	 * @return array{0:?array<array-key,mixed>,1:int} Tuple of result + scanned (decoded body keys come from the JSON envelope).
	 */
	private static function find_request_envelope( string $rid ): array {
		$config         = RuntimeConfig::load_config();
		$num_partitions = self::to_int( $config['num_partitions'] ?? 1 );
		$base_dir       = RuntimeConfig::get_base_directory();
		$log_base       = $base_dir . '/logs';

		$result  = null;
		$scanned = 0;

		for ( $p = 0; $p < $num_partitions && null === $result; $p++ ) {
			$partition = new Partition_Node();
			self::name_scratch_partition( $partition, 'requests', $p );
			$partition->arguments( "{$log_base}/requests.p{$p}" );
			$partition->with_index(
				static function ( string $line, array $position, &$data = null ): ?string {
				/** @var array<string,int> $position -- with_index() callback contract; the substrate always passes {segment_id,offset,length}. */
				/** @var array<string,mixed>|\stdClass|null $data -- by-ref pre-decoded payload from the formatter. */
				return Request_Builder_Node::format_index_entry( $line, $position, $data );
			}
			);
			$partition->scan_index(
				static function ( string $line ) use ( &$result, &$scanned, $partition, $rid, $p ): ?bool {
					++$scanned;
					if ( $scanned > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = Request_Builder_Node::parse_request_index( $line );
					if ( ! \is_array( $entry ) || \trim( self::to_string( $entry['rid'] ) ) !== $rid ) {
						return null;
					}
					$bytes = $partition->read_at(
						self::to_int( $entry['segment_id'] ?? 0 ),
						self::to_int( $entry['offset'] ?? 0 ),
						self::to_int( $entry['length'] ?? 0 )
					);
					if ( '' === $bytes ) {
						return false;
					}
					$decoded = \json_decode( \trim( $bytes ), true, 64 );
					$req     = \is_array( $decoded ) ? ( $decoded[ Message::VALUE ] ?? null ) : null;
					if ( \is_array( $req ) ) {
						$req['_partition'] = $p;
						$result            = $req;
					}
					return false;
				},
				true
			);
			$partition->remove_node();
		}

		return [ $result, $scanned ];
	}

	/**
	 * Search every flame partition for a flame entry matching the rid; the
	 * first hit wins. FlameBuilder writes to whatever partition it's wired
	 * into, so a per-rid lookup has to fan out across all of them.
	 * @return array<array-key, mixed>|null Decoded flame blob (keys come from the JSON envelope).
	 */
	private static function find_flame_for_rid( string $log_base, string $rid, int $num_partitions ): ?array {
		$entries_count = 0;
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$flames = new Partition_Node();
			self::name_scratch_partition( $flames, 'flames', $p );
			$flames->arguments( "{$log_base}/flames.p{$p}" );
			$flames->with_index(
				static function ( string $line, array $position, ?array &$data = null ): ?string {
					/** @var array<string,int> $position -- with_index() callback contract; the substrate always passes {segment_id,offset,length}. */
					/** @var array<string,mixed>|null $data -- by-ref pre-decoded payload from the formatter. */
					return Flame_Builder_Node::format_index_entry( $line, $position, $data );
				}
			);
			$result = null;
			$flames->scan_index(
				static function ( string $line ) use ( &$result, &$entries_count, $flames, $rid ): ?bool {
					++$entries_count;
					if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = Flame_Builder_Node::parse_flame_index( $line );
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
					$decoded = \json_decode( \trim( $data ), true, Flame_Builder_Node::FLAME_JSON_DEPTH );
					$flame   = \is_array( $decoded ) ? ( $decoded[ Message::VALUE ] ?? null ) : null;
					if ( \is_array( $flame ) ) {
						$result = $flame;
					}
					return false;
				},
				true
			);
			$flames->remove_node();
			if ( null !== $result ) {
				return $result;
			}
			if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
				break;
			}
		}
		return null;
	}

	/**
	 * Schema-driven dispatch: each of the 17 verbs is declared once in
	 * `verbs[]` carrying its `handler`. The inherited Service_CI_Node ctor
	 * builds the commands table from this schema. Stats-reading verbs build
	 * per-partition Stats_Store off the shared `Core::$memd` handle; a null
	 * handle yields empty/zeroed shapes. Disk-walking verbs work regardless.
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Service',
			'description' => 'Performance-dashboard surface: overview, URLs, requests, hooks, config, settings.',
			'arguments'        => [],
			'commands'       => [
				[
					'name'        => 'overview',
					'description' => 'High-level performance stats across all partitions.',
					'args'        => [
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						[ 'name' => 'breakdown', 'type' => 'string', 'required' => false ],
						[ 'name' => 'categories', 'type' => 'bool', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Optional args mirror the legacy PerfOverviewController query
				// params: `server` scopes the leaderboard / breakdown /
				// categories; `breakdown` is a comma-separated dim list
				// (single-dim → flat `breakdown_time_series`; multi-dim →
				// nested `breakdowns: {dim => series}`); `--categories`
				// adds `category_time_series` (global or per-server).
				$opts       = Command_Args::parse( $args )['options'];
				$server     = (string) ( $opts['server'] ?? '' );
				$breakdown  = (string) ( $opts['breakdown'] ?? '' );
				$categories = self::flag( $opts, 'categories' );

				$payload                       = self::build_overview_payload( self::load_index() );
				$payload['global_leaderboard'] = '' === $server
					? self::build_global_leaderboard()
					: self::build_server_leaderboard( $server );

				if ( '' !== $breakdown ) {
					$dims = \array_values(
						\array_filter(
							\array_map( 'trim', \explode( ',', $breakdown ) ),
							static fn ( $d ) => \in_array( $d, self::DIMENSIONS, true )
						)
					);
					if ( 1 === \count( $dims ) ) {
						$payload['breakdown_time_series'] = self::merge_dim_across_partitions( $dims[0], $server );
					} elseif ( ! empty( $dims ) ) {
						$payload['breakdowns'] = [];
						foreach ( $dims as $dim ) {
							$payload['breakdowns'][ $dim ] = self::merge_dim_across_partitions( $dim, $server );
						}
					}
				}

				if ( $categories ) {
					$payload['category_time_series'] = '' === $server
						? self::merge_categories_across_partitions()
						: self::merge_server_categories_across_partitions( $server );
				}

				return $payload;
					},
				],
				[
					'name'        => 'urls',
					'description' => 'Paginated/sortable URL leaderboard.',
					'args'        => [
						[ 'name' => 'sort', 'type' => 'string', 'required' => false, 'default' => 'count' ],
						[ 'name' => 'order', 'type' => 'string', 'required' => false, 'default' => 'desc' ],
						[ 'name' => 'limit', 'type' => 'int', 'required' => false, 'default' => 50 ],
						[ 'name' => 'offset', 'type' => 'int', 'required' => false, 'default' => 0 ],
						[ 'name' => 'search', 'type' => 'string', 'required' => false ],
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				$opts    = Command_Args::parse( $args )['options'];
				$sort    = (string) ( $opts['sort']   ?? 'count' );
				$order   = (string) ( $opts['order']  ?? 'desc' );
				$limit   = \min( 1000, \max( 1, (int) ( $opts['limit']  ?? 50 ) ) );
				$offset  = \min( 10000, \max( 0, (int) ( $opts['offset'] ?? 0 ) ) );
				$search  = (string) ( $opts['search'] ?? '' );
				$server  = (string) ( $opts['server'] ?? '' );

				if ( ! \in_array( $sort, self::URL_SORTS, true ) ) {
					$sort = 'count';
				}
				if ( 'asc' !== $order && 'desc' !== $order ) {
					$order = 'desc';
				}

				$index = self::load_index();

				if ( '' !== $server ) {
					$srv   = \strtolower( $server );
					$index = \array_values( \array_filter(
						$index,
						static fn ( $e ) => false !== \strpos( \strtolower( self::to_string( $e['url'] ?? '' ) ), $srv )
					) );
				}
				if ( '' !== $search ) {
					$term  = \strtolower( $search );
					$index = \array_values( \array_filter(
						$index,
						static fn ( $e ) => false !== \strpos( \strtolower( self::to_string( $e['url'] ?? '' ) ), $term )
					) );
				}

				$total = \count( $index );

				\usort(
					$index,
					static fn ( $a, $b ) => 'asc' === $order
						? ( $a[ $sort ] ?? 0 ) <=> ( $b[ $sort ] ?? 0 )
						: ( $b[ $sort ] ?? 0 ) <=> ( $a[ $sort ] ?? 0 )
				);

				return [
					'data'   => \array_slice( $index, $offset, $limit ),
					'total'  => $total,
					'limit'  => $limit,
					'offset' => $offset,
				];
					},
				],
				[
					'name'        => 'url_detail',
					'description' => 'Single-URL detail incl. aggregate flame data.',
					'args'        => [
						[ 'name' => 'hash', 'type' => 'string', 'required' => true ],
						[ 'name' => 'breakdown', 'type' => 'string', 'required' => false ],
						[ 'name' => 'categories', 'type' => 'bool', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				$parsed = Command_Args::parse( $args );
				$opts   = $parsed['options'];
				$hash   = $parsed['positional'][0] ?? '';
				if ( ! \preg_match( '/^[a-f0-9]{8,64}$/', $hash ) ) {
					throw new \RuntimeException( 'invalid hash format' );
				}

				$index = self::load_index();
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
							// Per-URL time series (consumed by UrlDetailView +
							// urlRequestsPerSecond). Matches legacy
							// PerfUrlsController::build_url_time_series.
							'time_series'  => self::build_url_time_series( $hash ),
						];
						break;
					}
				}
				if ( null === $stats ) {
					throw new \RuntimeException( \esc_html( "URL not found: {$hash}" ) );
				}

				$aggregate = self::find_url_aggregate( $hash );
				$flame     = $aggregate['flame']
					?? [ 'name' => 'aggregate', 'value' => 0, 'children' => [] ];

				$payload = [
					'stats'              => $stats,
					'requests'           => self::find_recent_requests_for_url( $hash ),
					'aggregate_flame'    => $flame,
					'aggregate_profiles' => $aggregate['profiles'] ?? null,
					'last_modified'      => $aggregate['last_modified'] ?? 0,
				];

				$breakdown = (string) ( $opts['breakdown'] ?? '' );
				if ( '' !== $breakdown && \in_array( $breakdown, self::DIMENSIONS, true ) ) {
					$payload['breakdown_time_series'] = self::merge_url_dim( $hash, $breakdown );
				}

				if ( self::flag( $opts, 'categories' ) ) {
					$payload['category_time_series'] = self::merge_url_categories( $hash );
				}

				return $payload;
					},
				],
				[
					'name'        => 'request_search',
					'description' => 'Locate a request by rid across partitions.',
					'args'        => [
						[ 'name' => 'rid', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				$rid = Command_Args::parse( $args )['positional'][0] ?? '';
				if ( '' === $rid ) {
					throw new \RuntimeException( 'rid required' );
				}

				$config         = RuntimeConfig::load_config();
				$num_partitions = self::to_int( $config['num_partitions'] ?? 1 );
				$base_dir       = RuntimeConfig::get_base_directory();
				$log_base       = $base_dir . '/logs';
				$scanned        = 0;

				for ( $p = 0; $p < $num_partitions; $p++ ) {
					$found = self::find_request_index_entry( $log_base, $p, $rid, $scanned );
					if ( null !== $found ) {
						return $found;
					}
					if ( $scanned > self::MAX_INDEX_ENTRIES ) {
						break;
					}
				}

				throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
					},
				],
				[
					'name'        => 'request_detail',
					'description' => 'Full request + flame data for a known {rid, partition}.',
					'args'        => [
						[ 'name' => 'rid', 'type' => 'string', 'required' => true ],
						[ 'name' => 'partition', 'type' => 'int', 'required' => false, 'default' => 0 ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				$parsed = Command_Args::parse( $args );
				$rid    = $parsed['positional'][0] ?? '';
				if ( '' === $rid ) {
					throw new \RuntimeException( 'rid required' );
				}
				$partition = (int) ( $parsed['options']['partition'] ?? 0 );

				$config         = RuntimeConfig::load_config();
				$num_partitions = self::to_int( $config['num_partitions'] ?? 1 );
				$base_dir       = RuntimeConfig::get_base_directory();
				$log_base       = $base_dir . '/logs';

				if ( $partition < 0 || $partition >= $num_partitions ) {
					throw new \RuntimeException( 'invalid partition' );
				}

				$result = self::find_request_in_partition( $log_base, $partition, $rid, $num_partitions );
				if ( null === $result ) {
					throw new \RuntimeException( \esc_html( "Request not found: rid={$rid}" ) );
				}
				return $result;
					},
				],
				[
					'name'        => 'timing',
					'description' => 'Merged hourly timing buckets across partitions.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Lifted from legacy PerformanceController::get_timing — merged
				// hourly buckets across partitions. The legacy "data + meta"
				// wrapper is dropped (REST artifact); the interpreter returns the inner
				// payload directly.
				return [
					'time_series' => self::merge_hourly_across_partitions(),
				];
					},
				],
				[
					'name'        => 'dashboard',
					'description' => 'Overview payload + full URL index in one round-trip.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Lifted from legacy PerformanceController::get_dashboard:
				// nest the overview payload alongside the full URL index so
				// the dashboard tree fans in with one round-trip. `load_index`
				// is the heavy memcache fan-out — share it across both keys.
				$index = self::load_index();
				return [
					'overview' => self::build_overview_payload( $index ),
					'urls'     => $index,
				];
					},
				],
				[
					'name'        => 'hooks_registered',
					'description' => 'Registered hooks grouped by category.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Lifted from legacy PerfHooksController::get_registered_hooks.
				// The legacy controller also returned `total_hooks` as the sum
				// of all category buckets; recomputing here keeps the contract
				// identical without trusting the categorizer to sum for us.
				$by_category = Hook_Categorizer::get_registered_hooks_by_category();
				$total       = 0;
				foreach ( $by_category as $list ) {
					$total += \is_array( $list ) ? \count( $list ) : 0;
				}
				return [
					'total_hooks'       => $total,
					'categories'        => Hook_Categorizer::get_categories(),
					'hooks_by_category' => $by_category,
				];
					},
				],
				[
					'name'        => 'hooks_categories',
					'description' => 'Hook categories + merged config.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Lifted from legacy PerfHooksController::get_hook_categories
				// — same shape the React tree consumes.
				return [
					'categories' => Hook_Categorizer::get_categories(),
					'config'     => Hook_Categorizer::get_merged_config(),
				];
					},
				],
				[
					'name'        => 'hooks_available',
					'description' => 'All runtime hooks for the picker UI.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Lifted from legacy PerfHooksAvailableController::get_available_hooks.
				// Walks $wp_actions (fired hooks) and $wp_filter (registered
				// but never-fired hooks), excludes Event Logger's own internal
				// hooks (instrumenting them loops via Config::load_config),
				// and removes anything the operator has marked as a custom
				// event so the picker doesn't double-list it.
				return [
					'hooks' => self::collect_available_hooks(),
				];
					},
				],
				[
					'name'        => 'hooks_configure',
					'description' => 'Persist selected hooks / custom events.',
					'args'        => [
						[ 'name' => 'hooks', 'type' => 'json', 'required' => false ],
						[ 'name' => 'custom_events', 'type' => 'json', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				$opts          = Command_Args::parse( $args )['options'];
				$hooks         = self::csv( $opts, 'hooks' );
				$custom_events = self::csv( $opts, 'custom_events' );
				$configured    = 0;

				if ( [] !== $hooks ) {
					$flat = [];
					foreach ( $hooks as $h ) {
						$h = \sanitize_text_field( $h );
						if ( '' !== $h ) {
							$flat[] = $h;
						}
					}
					\update_option( 'newspack_event_logger_nodes_log_events', $flat, AppConfig::autoload_for( 'newspack_event_logger_nodes_log_events' ) );
					$configured += \count( $flat );
				}

				if ( [] !== $custom_events ) {
					$assoc = [];
					foreach ( $custom_events as $event ) {
						$event = \sanitize_text_field( $event );
						if ( '' !== $event ) {
							$assoc[ $event ] = true;
						}
					}
					\update_option( 'newspack_event_logger_nodes_custom_events', $assoc, AppConfig::autoload_for( 'newspack_event_logger_nodes_custom_events' ) );
					$configured += \count( $assoc );
				}

				// Application Config caches the merged custom_events / log_events;
				// reset so the very next verb call (e.g. hooks_available) re-reads
				// the freshly-written WP options.
				AppConfig::reset();

				return [
					'success'          => true,
					'hooks_configured' => $configured,
				];
					},
				],
				[
					'name'        => 'config_get',
					'description' => 'Read the nine perf-tuning options.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Lifted from legacy PerfConfigController::get_config, with one
				// fix: the legacy controller called `RuntimeConfig::load_config()`
				// which reads `newspack_nodes_` options — but the perf-tuning
				// keys live under the `newspack_event_logger_nodes_` prefix on
				// the application Config. AppConfig::load_config() is the right
				// source. The legacy bug was masked because the legacy test
				// asserted on key presence only, never on the actual values.
				$cfg = AppConfig::load_config();
				return [
					'config' => [
						'log_events'                  => $cfg['log_events']    ?? [],
						'custom_events'               => $cfg['custom_events'] ?? [],
						'log_urls'                    => $cfg['log_urls']      ?? [],
						'skip_urls'                   => $cfg['skip_urls']     ?? [],
						'auto_disable_threshold'      => self::to_int( $cfg['auto_disable_threshold']      ?? 0 ),
						'auto_protect_time_threshold' => self::to_float( $cfg['auto_protect_time_threshold'] ?? 0.0 ),
						'significant_events'          => $cfg['significant_events'] ?? [],
						'log_memory'                  => ! empty( $cfg['log_memory'] ),
						'flush_every_line'            => ! empty( $cfg['flush_every_line'] ),
					],
				];
					},
				],
				[
					'name'        => 'config_update',
					'description' => 'Bulk-update the nine perf-tuning options.',
					'args'        => [
						[ 'name' => 'log_events', 'type' => 'json', 'required' => false ],
						[ 'name' => 'custom_events', 'type' => 'json', 'required' => false ],
						[ 'name' => 'log_urls', 'type' => 'json', 'required' => false ],
						[ 'name' => 'skip_urls', 'type' => 'json', 'required' => false ],
						[ 'name' => 'auto_disable_threshold', 'type' => 'int', 'required' => false ],
						[ 'name' => 'auto_protect_time_threshold', 'type' => 'float', 'required' => false ],
						[ 'name' => 'significant_events', 'type' => 'json', 'required' => false ],
						[ 'name' => 'log_memory', 'type' => 'bool', 'required' => false ],
						[ 'name' => 'flush_every_line', 'type' => 'bool', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Lifted from legacy PerfConfigController::update_config — the
				// bulk write path for the nine perf-tuning options. Options absent
				// from the args string are untouched (partial update). Unknown
				// options are silently ignored to match the legacy whitelist sweep.
				$opts    = Command_Args::parse( $args )['options'];
				$updated = [];
				foreach ( self::CONFIG_MAP as $param => $cfg ) {
					// Only options actually present in the args string are applied;
					// a missing `--param` means "leave that setting untouched".
					if ( ! \array_key_exists( $param, $opts ) ) {
						continue;
					}
					// *_events / *_urls arrive as comma-lists; bool flags resolve via
					// flag() so `--param=0`/`--param=false` map to false; int/float
					// hard-cast through coerce_config_value on the raw option string.
					switch ( $cfg['type'] ) {
						case 'array_assoc':
						case 'array_bool':
							$value = self::coerce_config_value( self::csv( $opts, $param ), $cfg['type'] );
							break;
						case 'bool':
							$value = self::flag( $opts, $param );
							break;
						default:
							$value = self::coerce_config_value( $opts[ $param ], $cfg['type'] );
					}
					\update_option( $cfg['option'], $value, AppConfig::autoload_for( $cfg['option'] ) );
					$updated[] = $param;
				}

				if ( ! empty( $updated ) ) {
					AppConfig::reset();
				}

				return [
					'success' => true,
					'updated' => $updated,
				];
					},
				],
				[
					'name'        => 'settings_update',
					'description' => 'Single-option perf setting write with sync guard.',
					'args'        => [
						[ 'name' => 'option', 'type' => 'string', 'required' => true ],
						[ 'name' => 'value', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Lifted from legacy PerfSettingsController::update_setting —
				// single-option write path with the suppress_sync guard so a
				// remotely-synced setting applied on a spoke doesn't bounce
				// back as a re-sync (mirrors the inbound REST polarity). This
				// `--option=<opt> --value="<v>"` grammar is the exact shape the
				// hub→spoke forwarder emits, so it must not drift.
				$opts   = Command_Args::parse( $args )['options'];
				$option = (string) ( $opts['option'] ?? '' );
				if ( '' === $option ) {
					throw new \RuntimeException( 'option required' );
				}
				if ( ! isset( self::SETTINGS_OPTIONS[ $option ] ) ) {
					throw new \RuntimeException( \esc_html( "unknown option: {$option}" ) );
				}
				if ( ! \array_key_exists( 'value', $opts ) ) {
					throw new \RuntimeException( 'value required' );
				}

				// The value rides the wire as a string; array-typed options carry
				// it as a comma-list that the array sanitizer expects pre-split.
				// Drop empty tokens so a cleared/empty value (`--value=""`, or the
				// forwarder's empty list) yields [] not [''] (which would otherwise
				// survive into add_action('') downstream).
				$type     = self::SETTINGS_OPTIONS[ $option ];
				$raw_value = true === $opts['value'] ? '' : $opts['value'];
				$value    = 'array' === $type ? self::csv( [ 'v' => $raw_value ], 'v' ) : $raw_value;

				$sanitized = self::sanitize_settings_value( $value, $type );
				if ( null === $sanitized ) {
					throw new \RuntimeException( 'invalid value for option' );
				}

				// suppress_sync guard + try/finally so the flag is restored on
				// update_option failure. Autoload follows the central policy
				// (Config::autoload_for): hot-path scalars autoloaded, large
				// list options (log_events / custom_events) kept off the
				// per-request alloptions blob.
				Settings_Sync::suppress_sync( true );
				try {
					$ok = \update_option( $option, $sanitized, AppConfig::autoload_for( $option ) );
				} finally {
					Settings_Sync::suppress_sync( false );
				}

				AppConfig::reset();

				return [
					'option'  => $option,
					'updated' => $ok,
				];
					},
				],
				[
					'name'        => 'request_log_list',
					'description' => 'Recent request list across partitions.',
					'args'        => [
						[ 'name' => 'limit', 'type' => 'int', 'required' => false, 'default' => 100 ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Lifted from legacy RequestLogController::get_list.
				// Limit clamped 1..1000 (default 100); fan out across
				// partitions; sort by timestamp DESC; slice to limit.
				$opts  = Command_Args::parse( $args )['options'];
				$limit = isset( $opts['limit'] )
					? \min( self::REQUEST_LIST_MAX_LIMIT, \max( 1, (int) $opts['limit'] ) )
					: self::REQUEST_LIST_DEFAULT_LIMIT;

				[ $entries, $scanned ] = self::collect_request_list( $limit );

				\usort( $entries, static fn ( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
				$entries = \array_slice( $entries, 0, $limit );

				return [
					'data' => $entries,
					'meta' => [
						'limit'   => $limit,
						'scanned' => $scanned,
					],
				];
					},
				],
				[
					'name'        => 'request_log_detail',
					'description' => 'Full request envelope for one request id.',
					'args'        => [
						[ 'name' => 'id', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Lifted from legacy RequestLogController::get_detail.
				// Empty id is a real usage error → throw so the central
				// catch surfaces TM_COMMAND|TM_ERROR. Unknown-but-non-empty
				// id returns the legacy stub-compatible empty-entries shape
				// (NOT 404 — the React tree polls these and `expected to
				// exist soon` is a normal state).
				$rid = Command_Args::parse( $args )['positional'][0] ?? '';
				if ( '' === $rid ) {
					throw new \RuntimeException( 'id required' );
				}

				[ $result, $scanned ] = self::find_request_envelope( $rid );

				if ( null === $result ) {
					return [
						'data' => [
							'request_id' => $rid,
							'entries'    => [],
						],
						'meta' => [ 'scanned' => $scanned ],
					];
				}

				// Normalize the entries shape — React tree expects `entries[]`.
				// Body with no `events` key is wrapped as a single entry; the
				// _partition marker lets the tree key on partition.
				$entries = $result['events'] ?? [ $result ];
				return [
					'data' => [
						'request_id' => $rid,
						'entries'    => $entries,
					],
					'meta' => [ 'scanned' => $scanned ],
				];
					},
				],
			],
		];
	}
}
