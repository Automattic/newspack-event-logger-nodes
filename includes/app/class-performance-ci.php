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
 *   - class-gyroscope-controller.php            (gyroscope_timeline)
 *                                               (SSE method stays as REST controller)
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
 *    polling; CI dispatch fires verbs once-per-request through the worker,
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
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
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
			'ctor'        => [],
			'verbs'       => [
				[
					'name'        => 'overview',
					'description' => 'High-level performance stats across all partitions.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				// Optional args mirror the legacy PerfOverviewController query
				// params: `server` scopes the leaderboard / breakdown /
				// categories; `breakdown` is a comma-separated dim list
				// (single-dim → flat `breakdown_time_series`; multi-dim →
				// nested `breakdowns: {dim => series}`); `categories=true`
				// adds `category_time_series` (global or per-server).
				$decoded    = \is_array( $payload ) ? $payload : [];
				$server     = (string) ( $decoded['server'] ?? '' );
				$breakdown  = (string) ( $decoded['breakdown'] ?? '' );
				$categories = ! empty( $decoded['categories'] );

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
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				$decoded = \is_array( $payload ) ? $payload : [];
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

				$index = self::load_index();

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
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				$decoded = \is_array( $payload ) ? $payload : [];
				$hash    = (string) ( $decoded['hash'] ?? '' );
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

				$breakdown = (string) ( $decoded['breakdown'] ?? '' );
				if ( '' !== $breakdown && \in_array( $breakdown, self::DIMENSIONS, true ) ) {
					$payload['breakdown_time_series'] = self::merge_url_dim( $hash, $breakdown );
				}

				if ( ! empty( $decoded['categories'] ) ) {
					$payload['category_time_series'] = self::merge_url_categories( $hash );
				}

				return $payload;
					},
				],
				[
					'name'        => 'request_search',
					'description' => 'Locate a request by rid across partitions.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				$decoded = \is_array( $payload ) ? $payload : [];
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
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				$decoded = \is_array( $payload ) ? $payload : [];
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
				return $result;
					},
				],
				[
					'name'        => 'timing',
					'description' => 'Merged hourly timing buckets across partitions.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				// Lifted from legacy PerformanceController::get_timing — merged
				// hourly buckets across partitions. The legacy "data + meta"
				// wrapper is dropped (REST artifact); CI returns the inner
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
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
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
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
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
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
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
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
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
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				$decoded       = \is_array( $payload ) ? $payload : [];
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
					\update_option( 'newspack_event_logger_nodes_log_events', $flat, AppConfig::autoload_for( 'newspack_event_logger_nodes_log_events' ) );
					$configured += \count( $flat );
				}

				if ( \is_array( $custom_events ) && [] !== $custom_events ) {
					$assoc = [];
					foreach ( $custom_events as $event ) {
						if ( \is_string( $event ) && '' !== $event ) {
							$assoc[ \sanitize_text_field( $event ) ] = true;
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
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
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
						'auto_disable_threshold'      => (int) ( $cfg['auto_disable_threshold']      ?? 0 ),
						'auto_protect_time_threshold' => (float) ( $cfg['auto_protect_time_threshold'] ?? 0.0 ),
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
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				// Lifted from legacy PerfConfigController::update_config — the
				// bulk write path for the nine perf-tuning options. Keys absent
				// from the request body are untouched (partial update). Unknown
				// keys are silently ignored to match the legacy whitelist sweep.
				$decoded = \is_array( $payload ) ? $payload : [];
				$updated = [];
				foreach ( self::CONFIG_MAP as $param => $cfg ) {
					// Skip missing keys AND explicit-null values (legacy parity:
					// PerfConfigController::update_config uses `$request->get_param()`
					// which returns null for both). `??` collapses both cases into
					// the same continue.
					$value = $decoded[ $param ] ?? null;
					if ( null === $value ) {
						continue;
					}
					\update_option( $cfg['option'], self::coerce_config_value( $value, $cfg['type'] ), AppConfig::autoload_for( $cfg['option'] ) );
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
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				// Lifted from legacy PerfSettingsController::update_setting —
				// single-option write path with the suppress_sync guard so a
				// remotely-synced setting applied on a spoke doesn't bounce
				// back as a re-sync (mirrors the inbound REST polarity).
				$decoded = \is_array( $payload ) ? $payload : [];
				$option  = (string) ( $decoded['option'] ?? '' );
				if ( '' === $option ) {
					throw new \RuntimeException( 'option required' );
				}
				if ( ! isset( self::SETTINGS_OPTIONS[ $option ] ) ) {
					throw new \RuntimeException( \esc_html( "unknown option: {$option}" ) );
				}
				if ( ! \array_key_exists( 'value', $decoded ) ) {
					throw new \RuntimeException( 'value required' );
				}

				$sanitized = self::sanitize_settings_value( $decoded['value'], self::SETTINGS_OPTIONS[ $option ] );
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
					'updated' => (bool) $ok,
				];
					},
				],
				[
					'name'        => 'gyroscope_timeline',
					'description' => 'Per-request event timeline from requests.log.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				// Lifted from legacy GyroscopeController::get_timeline.
				// Empty rid → canonical initial-state shape (matches the
				// legacy stub so the React tree mounts cleanly before a
				// request is selected). Otherwise: walk requests.log,
				// fan out across partitions, return `events[]` from the
				// matched envelope (or wrap the whole body as a single
				// event when no `events` key is present).
				$decoded = \is_array( $payload ) ? $payload : [];
				$rid     = (string) ( $decoded['request_id'] ?? '' );
				if ( '' === $rid ) {
					return [
						'data' => [ 'events' => [] ],
						'meta' => [],
					];
				}

				[ $events, $scanned ] = self::scan_requests_for_events( $rid );

				return [
					'data' => [
						'request_id' => $rid,
						'events'     => $events,
					],
					'meta' => [ 'scanned' => $scanned ],
				];
					},
				],
				[
					'name'        => 'request_log_list',
					'description' => 'Recent request list across partitions.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				// Lifted from legacy RequestLogController::get_list.
				// Limit clamped 1..1000 (default 100); fan out across
				// partitions; sort by timestamp DESC; slice to limit.
				$decoded = \is_array( $payload ) ? $payload : [];
				$limit   = isset( $decoded['limit'] )
					? \min( self::REQUEST_LIST_MAX_LIMIT, \max( 1, (int) $decoded['limit'] ) )
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
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
				self::require_manage_options();

				// Lifted from legacy RequestLogController::get_detail.
				// Empty id is a real usage error → throw so the central
				// catch surfaces TM_COMMAND|TM_ERROR. Unknown-but-non-empty
				// id returns the legacy stub-compatible empty-entries shape
				// (NOT 404 — the React tree polls these and `expected to
				// exist soon` is a normal state).
				$decoded = \is_array( $payload ) ? $payload : [];
				$rid     = (string) ( $decoded['id'] ?? '' );
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
				return (int) $value;
			case 'float':
				return (float) $value;
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
			$safe_key = \is_int( $key ) ? $key : \sanitize_text_field( (string) $key );
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
					'count'    => (int) $count,
				];
			}
		}

		if ( isset( $wp_filter ) && ( \is_array( $wp_filter ) || $wp_filter instanceof \Traversable ) ) {
			foreach ( $wp_filter as $hook_name => $callbacks ) {
				$name = (string) $hook_name;
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
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$max_lifespan   = (int) ( $config['max_lifespan'] ?? 86400 );
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
					if ( \is_array( $stats ) && isset( $stats['url'] ) ) {
						$url  = (string) $stats['url'];
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
					// `min_ms` is optional on the entry — only seeded once a
					// stat-with-min_ms arrives, so the missing-key path stays
					// distinguishable from a legitimate 0.0 min.
					if ( isset( $stats['min_ms'] ) ) {
						$stat_min        = (float) $stats['min_ms'];
						$entry['min_ms'] = isset( $entry['min_ms'] )
							? \min( (float) $entry['min_ms'], $stat_min )
							: $stat_min;
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
					$result[ $hash ] = $entry;
				}
			}
		}

		// Convert into the display shape the React tree expects.
		$out = [];
		foreach ( $result as $entry ) {
			$count = (int) $entry['count'];
			$denom = \max( 1, $count );
			$out[] = [
				'hash'         => $entry['hash'],
				'url'          => $entry['url'],
				'count'        => $count,
				'count_2xx'    => (int) $entry['count_2xx'],
				'count_3xx'    => (int) $entry['count_3xx'],
				'count_4xx'    => (int) $entry['count_4xx'],
				'count_5xx'    => (int) $entry['count_5xx'],
				'avg_ms'       => $entry['sum_ms'] / $denom,
				'min_ms'       => (float) ( $entry['min_ms'] ?? 0 ),
				'max_ms'       => $entry['max_ms'],
				'p50_ms'       => $entry['p50_ms'],
				'p95_ms'       => $entry['p95_ms'],
				'p99_ms'       => $entry['p99_ms'],
				'avg_peak_mb'  => $entry['sum_peak_mb'] / $denom,
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
	private static function merge_hourly_across_partitions(): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
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
	 * Walk every recent URL bucket for the given hash and emit a per-bucket
	 * `{count, sum_ms, sum_peak_mb}` time series. Mirrors
	 * PerfUrlsController::build_url_time_series.
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
				$stats = $bucket_data[ $hash ];
				$count = (int) ( $stats['count'] ?? 0 );
				if ( 0 === $count ) {
					continue;
				}
				// FlameBuilder buckets carry `sum_ms` directly; StatsAggregator
				// buckets carry `sum_req_time` in seconds — accept either.
				$sum_ms = isset( $stats['sum_ms'] )
					? (float) $stats['sum_ms']
					: (float) ( $stats['sum_req_time'] ?? 0 ) * 1000.0;
				$series[ $bucket_key ] ??= [ 'count' => 0, 'sum_ms' => 0.0, 'sum_peak_mb' => 0.0 ];
				$series[ $bucket_key ]['count']       += $count;
				$series[ $bucket_key ]['sum_ms']      += $sum_ms;
				$series[ $bucket_key ]['sum_peak_mb'] += (float) ( $stats['sum_peak_mb'] ?? 0 );
			}
		}
		\ksort( $series );
		return $series;
	}

	/**
	 * Build the merged global category leaderboard for the recent window.
	 * Mirror of PerfOverviewController::build_global_leaderboard.
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
				$count        += (int) ( $row['count'] ?? 0 );
				$sum_req_time += (float) ( $row['sum_req_time'] ?? 0 );
				self::accumulate_leaderboard_categories( $sums, $row['categories'] ?? [] );
			}
		}
		return Stats_Store::sums_to_display( $count, $sum_req_time, $sums );
	}

	/**
	 * Build the per-server category leaderboard for the recent window.
	 * Mirror of PerfOverviewController::build_server_leaderboard.
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
				$count        += (int) ( $row['count'] ?? 0 );
				$sum_req_time += (float) ( $row['sum_req_time'] ?? 0 );
				self::accumulate_leaderboard_categories( $sums, $row['categories'] ?? [] );
			}
		}
		return Stats_Store::sums_to_display( $count, $sum_req_time, $sums );
	}

	/**
	 * Sum-merge a single leaderboard bucket's categories into the running totals.
	 * Used by both global + server leaderboard builders.
	 *
	 * @param array<string,array{samples:int,sum_time:float,sum_count:float,entries:array}> $sums       Running totals (mutated).
	 * @param array<string,array<string,mixed>>                                              $categories Inbound categories.
	 */
	private static function accumulate_leaderboard_categories( array &$sums, array $categories ): void {
		foreach ( $categories as $cat => $data ) {
			$sums[ $cat ] ??= [
				'samples'   => 0,
				'sum_time'  => 0.0,
				'sum_count' => 0.0,
				'entries'   => [],
			];
			$sums[ $cat ]['samples']   += (int) ( $data['samples'] ?? 0 );
			$sums[ $cat ]['sum_time']  += (float) ( $data['sum_time'] ?? 0 );
			$sums[ $cat ]['sum_count'] += (float) ( $data['sum_count'] ?? 0 );
		}
	}

	/**
	 * Sum-merge dimensional buckets across all partitions for one dim/server.
	 * Mirror of PerfOverviewController::merge_dim_across_partitions.
	 */
	private static function merge_dim_across_partitions( string $dimension, string $server ): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			foreach ( $store->get_dimensional( $dimension, $server ) as $bucket => $values ) {
				$merged[ $bucket ] ??= [];
				foreach ( $values as $name => $entry ) {
					$merged[ $bucket ][ $name ] ??= [ 'c' => 0, 's' => 0.0, 'm' => 0.0 ];
					$merged[ $bucket ][ $name ]['c'] += (int) ( $entry['c'] ?? 0 );
					$merged[ $bucket ][ $name ]['s'] += (float) ( $entry['s'] ?? 0 );
					$merged[ $bucket ][ $name ]['m'] += (float) ( $entry['m'] ?? 0 );
				}
			}
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge category buckets across all partitions (global scope).
	 * Mirror of PerfOverviewController::merge_categories_across_partitions.
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
	 */
	private static function merge_url_dim( string $hash, string $dimension ): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			$rows = $store->get_url_dimensional( $hash );
			$dim  = $rows[ $dimension ] ?? [];
			foreach ( $dim as $bucket => $values ) {
				$merged[ $bucket ] ??= [];
				foreach ( $values as $name => $entry ) {
					$merged[ $bucket ][ $name ] ??= [ 'c' => 0, 's' => 0.0, 'm' => 0.0 ];
					$merged[ $bucket ][ $name ]['c'] += (int) ( $entry['c'] ?? 0 );
					$merged[ $bucket ][ $name ]['s'] += (float) ( $entry['s'] ?? 0 );
					$merged[ $bucket ][ $name ]['m'] += (float) ( $entry['m'] ?? 0 );
				}
			}
		}
		\ksort( $merged );
		return $merged;
	}

	/**
	 * Sum-merge per-URL category buckets for one hash.
	 * Mirror of PerfUrlsController::merge_url_categories.
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
	 * @param array<string,array<string,array<string,mixed>>>           $rows   Inbound.
	 */
	private static function merge_category_buckets_into( array &$merged, array $rows ): void {
		foreach ( $rows as $bucket => $values ) {
			$merged[ $bucket ] ??= [];
			foreach ( $values as $cat => $entry ) {
				$merged[ $bucket ][ $cat ] ??= [ 't' => 0.0, 'c' => 0.0, 'n' => 0 ];
				$merged[ $bucket ][ $cat ]['t'] += (float) ( $entry['t'] ?? 0 );
				$merged[ $bucket ][ $cat ]['c'] += (float) ( $entry['c'] ?? 0 );
				$merged[ $bucket ][ $cat ]['n'] += (int) ( $entry['n'] ?? 0 );
			}
		}
	}

	/**
	 * Compose the overview payload shape from a pre-loaded URL index.
	 * Shared by the `overview` and `dashboard` verbs — `dashboard` wraps
	 * this alongside the same `$index` to avoid a second memcache fan-out.
	 *
	 * @param array<int,array<string,mixed>> $index Output of self::load_index().
	 */
	private static function build_overview_payload( array $index ): array {
		$time_series       = self::merge_hourly_across_partitions();
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
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';

		$requests      = [];
		$entries_count = 0;
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$partition = ( new Partition_Node( "{$log_base}/requests.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => Request_Builder_Node::format_index_entry( $line, $position, $data )
			);
			$partition->scan_index(
				static function ( string $line, int $segment_id ) use ( &$requests, &$entries_count, $url_hash, $p ): ?bool {
					++$entries_count;
					if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = Request_Builder_Node::parse_request_index( $line );
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
		$requests = ( new Partition_Node( "{$log_base}/requests.log", $partition ) )->with_index(
			static fn ( $line, $position, &$data = null ) => Request_Builder_Node::format_index_entry( $line, $position, $data )
		);
		$requests->scan_index(
			static function ( string $line ) use ( &$result, &$entries_count, $partition, $rid ): ?bool {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = Request_Builder_Node::parse_request_index( $line );
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
		$requests      = ( new Partition_Node( "{$log_base}/requests.log", $partition ) )->with_index(
			static fn ( $line, $position, &$data = null ) => Request_Builder_Node::format_index_entry( $line, $position, $data )
		);
		$requests->scan_index(
			static function ( string $line ) use ( &$result, &$entries_count, $requests, $rid ): ?bool {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = Request_Builder_Node::parse_request_index( $line );
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
	 * Walk every requests.log partition looking for the given rid; on hit,
	 * return its `events[]` (or the body itself wrapped as one event when no
	 * `events` key exists). Mirrors GyroscopeController::get_timeline.
	 *
	 * @param string $rid Request id to look up.
	 * @return array{0:array<int,mixed>,1:int} Tuple of events array + entries scanned.
	 */
	private static function scan_requests_for_events( string $rid ): array {
		$config         = RuntimeConfig::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';

		$events  = [];
		$scanned = 0;

		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$partition = ( new Partition_Node( "{$log_base}/requests.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => Request_Builder_Node::format_index_entry( $line, $position, $data )
			);
			$found     = false;
			$partition->scan_index(
				static function ( string $line ) use ( &$events, &$scanned, &$found, $partition, $rid ): ?bool {
					++$scanned;
					if ( $scanned > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = Request_Builder_Node::parse_request_index( $line );
					if ( ! \is_array( $entry ) || \trim( (string) $entry['rid'] ) !== $rid ) {
						return null;
					}
					$bytes = $partition->read_at(
						(int) ( $entry['segment_id'] ?? 0 ),
						(int) ( $entry['offset'] ?? 0 ),
						(int) ( $entry['length'] ?? 0 )
					);
					if ( '' === $bytes ) {
						return false;
					}
					// Bytes are a packed Message; body lives at VALUE.
					$decoded = \json_decode( \trim( $bytes ), true, 64 );
					$body    = \is_array( $decoded ) ? ( $decoded[ Message::VALUE ] ?? null ) : null;
					if ( \is_array( $body ) ) {
						if ( isset( $body['events'] ) && \is_array( $body['events'] ) ) {
							$events = $body['events'];
						} else {
							// Treat request as a single envelope.
							$events[] = $body;
						}
					}
					$found = true;
					return false;
				},
				true
			);
			if ( $found || $scanned > self::MAX_INDEX_ENTRIES ) {
				break;
			}
		}

		return [ $events, $scanned ];
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
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';

		$entries = [];
		$scanned = 0;

		for ( $p = 0; $p < $num_partitions && \count( $entries ) < $limit; $p++ ) {
			$partition = ( new Partition_Node( "{$log_base}/requests.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => Request_Builder_Node::format_index_entry( $line, $position, $data )
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
						'rid'          => \trim( (string) ( $parsed['rid'] ?? '' ) ),
						'url_hash'     => \trim( (string) ( $parsed['url_hash'] ?? '' ) ),
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
		}

		return [ $entries, $scanned ];
	}

	/**
	 * Fan out across every requests.log partition looking for one rid; the
	 * first hit wins. Returns the decoded request body (with `_partition`
	 * stamped on it). Mirrors RequestLogController::get_detail's scan.
	 *
	 * @return array{0:?array<string,mixed>,1:int} Tuple of result + scanned.
	 */
	private static function find_request_envelope( string $rid ): array {
		$config         = RuntimeConfig::load_config();
		$num_partitions = (int) ( $config['num_partitions'] ?? 1 );
		$base_dir       = (string) ( $config['base_directory'] ?? '/tmp/newspack-nodes' );
		$log_base       = $base_dir . '/logs';

		$result  = null;
		$scanned = 0;

		for ( $p = 0; $p < $num_partitions && null === $result; $p++ ) {
			$partition = ( new Partition_Node( "{$log_base}/requests.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => Request_Builder_Node::format_index_entry( $line, $position, $data )
			);
			$partition->scan_index(
				static function ( string $line ) use ( &$result, &$scanned, $partition, $rid, $p ): ?bool {
					++$scanned;
					if ( $scanned > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = Request_Builder_Node::parse_request_index( $line );
					if ( ! \is_array( $entry ) || \trim( (string) $entry['rid'] ) !== $rid ) {
						return null;
					}
					$bytes = $partition->read_at(
						(int) ( $entry['segment_id'] ?? 0 ),
						(int) ( $entry['offset'] ?? 0 ),
						(int) ( $entry['length'] ?? 0 )
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
		}

		return [ $result, $scanned ];
	}

	/**
	 * Search every flame partition for a flame entry matching the rid; the
	 * first hit wins. FlameBuilder writes to whatever partition it's wired
	 * into, so a per-rid lookup has to fan out across all of them.
	 */
	private static function find_flame_for_rid( string $log_base, string $rid, int $num_partitions ): ?array {
		$entries_count = 0;
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$flames = ( new Partition_Node( "{$log_base}/flames.log", $p ) )->with_index(
				static fn ( $line, $position, &$data = null ) => Flame_Builder_Node::format_index_entry( $line, $position, $data )
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
