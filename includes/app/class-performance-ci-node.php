<?php
/**
 * Performance_CI: command-dispatch for the performance-dashboard surface.
 *
 * Verbs the live surfaces drive:
 *   - overview / urls / url_detail / request_search / request_detail
 *     — the de-godded performance dashboard (usePerformanceGraph) +
 *     the current-request tab.
 *   - hooks_registered — the Settings / hook-catalog tree.
 *   - set — the spoke-side receiver of the substrate Settings_Sync_Node
 *     hub→spoke fanout (hub-control.tsl maps the nine perf-tuning options
 *     to this `performance` node).
 *
 * SSE-style stream surfaces (request-log, gyroscope, errors) consume the
 * substrate's `/messages/stream` EventSource directly — the
 * CommandInterpreter dispatch path doesn't stream.
 *
 * Cross-cutting design choices:
 *  - Auth: every verb requires `manage_options`.
 *  - Rate limit: none — interpreter dispatch fires verbs once-per-request
 *    through the worker, not from a fan-out of polling tabs.
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
use Newspack_Event_Logger_Nodes\Rule_Set;
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
	 * Hard cap on .idx entries scanned per disk-walking verb — prevents a
	 * missing-rid scan from walking unbounded numbers of firehose entries.
	 */
	public const MAX_INDEX_ENTRIES = 100000;

	/**
	 * Valid breakdown dimensions for the `overview` / `url_detail` verbs —
	 * typos fall through without surfacing arbitrary memcache reads.
	 */
	private const DIMENSIONS = [ 'status', 'method', 'server', 'country', 'from', 'ua', 'ja4' ];
	private const SETTINGS_ARRAY_DEPTH = 5;

	/**
	 * Maximum array element count + nesting depth for `set`.
	 */
	private const SETTINGS_ARRAY_MAX   = 10000;

	/**
	 * Upper bound on `set` float values (24h in seconds); values outside
	 * `0 <= $f <= 86400` are rejected.
	 */
	private const SETTINGS_FLOAT_MAX = 86400;

	/**
	 * Upper bound on `set` integer values (2^30); values outside
	 * `0 <= $int <= 1073741824` are rejected.
	 */
	private const SETTINGS_INT_MAX = 1073741824;

	/**
	 * `set` whitelist: WP option name → sanitization type.
	 * 
	 * @var array<string,string>
	 */
	private const SETTINGS_OPTIONS = [
		'newspack_event_logger_nodes_rules'            => 'array',
		'newspack_event_logger_nodes_log_memory'       => 'bool',
		'newspack_event_logger_nodes_flush_every_line' => 'bool',
	];

	/**
	 * Valid sort fields for the `urls` verb; anything outside falls back
	 * to `count`.
	 */
	private const URL_SORTS = [ 'count', 'url', 'avg_ms', 'min_ms', 'max_ms', 'p95_ms', 'avg_peak_mb', 'last_updated' ];

	/**
	 * URL-index read seam. Lazily-defaulted to the real merge-across-partitions
	 * loader (load_index_default). Tests reassign it to COUNT index reads without
	 * short-circuiting the production fan-out — the surrounding memo + the merge
	 * logic still run as real code (mirrors Insights_CI_Demo_Node::$read_items).
	 *
	 * Resolved once per request through index(); reassign in a test bootstrap,
	 * restore in a finally.
	 *
	 * Signature: `function (): array<int,array<string,mixed>>`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $load_index = null;

	/**
	 * Per-request memo of the merged URL index; null until index() reads once.
	 * Per-INSTANCE (one Performance_CI_Node == one request) so a long-lived
	 * worker never serves a stale snapshot across requests.
	 *
	 * @var array<int,array<array-key,mixed>>|null
	 */
	private ?array $index_cache = null;

	/**
	 * Merged URL index for THIS request — read at most once and memoized, so the
	 * slice handlers that each derive from it (overview, urls, url_detail's
	 * lookup, dashboard's overview + urls) share a single memcache fan-out
	 * instead of re-loading per slice. Resolves the `load_index` seam (the real
	 * loader by default) on first call.
	 *
	 * @return array<int,array<array-key,mixed>>
	 */
	private function index(): array {
		if ( null !== $this->index_cache ) {
			return $this->index_cache;
		}
		$read = self::$load_index ?? static fn (): array => self::load_index_default();
		$raw  = $read();
		$rows = [];
		foreach ( Core::arr( $raw ) as $row ) {
			if ( \is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		$this->index_cache = $rows;
		return $this->index_cache;
	}

	/**
	 * Type-coerce + bounds-check a single value for `set`. Mirrors
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
	 * Bounded-recursion array sanitizer for `set`. Mirrors
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

	/**
	 * Merged URL index across all partitions, shaped for dashboard display.
	 * Mirrors PerfOverviewController::load_index — same field set, same
	 * sort (count DESC), same fallback hashing for buckets that don't
	 * carry an embedded URL.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function load_index_default(): array {
		$buckets = self::recent_url_buckets();
		$result  = [];
		foreach ( self::stats_stores() as $store ) {
			$rows = $store->get_url_buckets( $buckets );
			foreach ( $rows as $bucket_data ) {
				if ( ! \is_array( $bucket_data ) ) {
					continue;
				}
				foreach ( $bucket_data as $hash_or_url => $stats ) {
					// Inner key is URL hash; derive one if keyed by URL string.
					$stat_arr = Core::arr( $stats );
					if ( isset( $stat_arr['url'] ) ) {
						$url  = Core::as_string( $stat_arr['url'] );
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
					$entry['count']     += Core::as_int( $stat_arr['count']     ?? 0 );
					$entry['count_2xx'] += Core::as_int( $stat_arr['count_2xx'] ?? 0 );
					$entry['count_3xx'] += Core::as_int( $stat_arr['count_3xx'] ?? 0 );
					$entry['count_4xx'] += Core::as_int( $stat_arr['count_4xx'] ?? 0 );
					$entry['count_5xx'] += Core::as_int( $stat_arr['count_5xx'] ?? 0 );
					// sum_ms (FlameBuilder) or sum_req_time secs (Aggregator).
					$entry['sum_ms']      += isset( $stat_arr['sum_ms'] )
						? Core::as_float( $stat_arr['sum_ms'] )
						: Core::as_float( $stat_arr['sum_req_time'] ?? 0 ) * 1000.0;
					$entry['sum_peak_mb'] += Core::as_float( $stat_arr['sum_peak_mb'] ?? 0 );
					// Fold min_ms only from timed buckets; skip sentinels.
					if ( isset( $stat_arr['min_ms'] ) && ( $stat_arr['timed_count'] ?? 0 ) > 0 ) {
						$stat_min        = Core::as_float( $stat_arr['min_ms'] );
						$entry['min_ms'] = isset( $entry['min_ms'] )
							? \min( Core::as_float( $entry['min_ms'] ), $stat_min )
							: $stat_min;
					}
					$entry['max_ms']      = \max( Core::as_float( $entry['max_ms'] ),      Core::as_float( $stat_arr['max_ms']      ?? 0 ) );
					$entry['max_peak_mb'] = \max( Core::as_float( $entry['max_peak_mb'] ), Core::as_float( $stat_arr['max_peak_mb'] ?? 0 ) );
					foreach ( [ 'p50_ms', 'p95_ms', 'p99_ms' ] as $k ) {
						if ( ! empty( $stat_arr[ $k ] ) ) {
							$entry[ $k ] = Core::as_float( $stat_arr[ $k ] );
						}
					}
					$entry['last_seen'] = \max(
						Core::as_int( $entry['last_seen'] ),
						Core::as_int( $stat_arr['last_seen'] ?? 0 )
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
				$stats = Core::arr( $bucket_data[ $hash ] );
				$count = Core::as_int( $stats['count'] ?? 0 );
				if ( 0 === $count ) {
					continue;
				}
				// sum_ms (FlameBuilder) or sum_req_time secs (Aggregator).
				$sum_ms = isset( $stats['sum_ms'] )
					? Core::as_float( $stats['sum_ms'] )
					: Core::as_float( $stats['sum_req_time'] ?? 0 ) * 1000.0;
				$series[ $bucket_key ] ??= [ 'count' => 0, 'sum_ms' => 0.0, 'sum_peak_mb' => 0.0 ];
				$series[ $bucket_key ]['count']       += $count;
				$series[ $bucket_key ]['sum_ms']      += $sum_ms;
				$series[ $bucket_key ]['sum_peak_mb'] += Core::as_float( $stats['sum_peak_mb'] ?? 0 );
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
				$count        += Core::as_int( $row['count'] ?? 0 );
				$sum_req_time += Core::as_float( $row['sum_req_time'] ?? 0 );
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
				$count        += Core::as_int( $row['count'] ?? 0 );
				$sum_req_time += Core::as_float( $row['sum_req_time'] ?? 0 );
				/** @var array<string,mixed> $categories -- decoded memcache leaderboard blob, keyed by category name. */
				$categories    = \is_array( $row['categories'] ?? null ) ? $row['categories'] : [];
				self::accumulate_leaderboard_categories( $sums, $categories );
			}
		}
		return Stats_Store::sums_to_display( $count, $sum_req_time, $sums );
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
	 * Sum-merge a single leaderboard bucket's categories into the running totals.
	 * Used by both global + server leaderboard builders.
	 *
	 * @param array<string,array{samples:int,sum_time:float,sum_count:float,entries:array<int, mixed>}> $sums       Running totals (mutated).
	 * @param array<string,mixed>                                                             $categories Inbound categories.
	 */
	private static function accumulate_leaderboard_categories( array &$sums, array $categories ): void {
		foreach ( $categories as $cat => $data ) {
			$data_arr = Core::arr( $data );
			$sums[ $cat ] ??= [
				'samples'   => 0,
				'sum_time'  => 0.0,
				'sum_count' => 0.0,
				'entries'   => [],
			];
			$sums[ $cat ]['samples']   += Core::as_int( $data_arr['samples'] ?? 0 );
			$sums[ $cat ]['sum_time']  += Core::as_float( $data_arr['sum_time'] ?? 0 );
			$sums[ $cat ]['sum_count'] += Core::as_float( $data_arr['sum_count'] ?? 0 );
		}
	}

	/**
	 * Sum-merge dimensional buckets across all partitions for one dim/server.
	 * The server dimension is the global routing index: Flame Builder deliberately
	 * omits its redundant per-server copy, so keep that dimension global while a
	 * server scope narrows every other dimension.
	 * Mirror of PerfOverviewController::merge_dim_across_partitions.
	 * @return array<array-key, mixed> Bucket keys derive from decoded memcache blobs.
	 */
	private static function merge_dim_across_partitions( string $dimension, string $server ): array {
		$store_server = 'server' === $dimension ? '' : $server;
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			self::merge_dim_buckets_into( $merged, $store->get_dimensional( $dimension, $store_server ) );
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
			if ( \is_array( $dim ) ) {
				self::merge_dim_buckets_into( $merged, $dim );
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
	 * Sum-merge dimensional `[bucket => [name => {c,s,m}]]` blobs into the running
	 * totals. Shared by the global (merge_dim_across_partitions) and per-URL
	 * (merge_url_dim) breakdown builders — the two iterate identically.
	 *
	 * @param array<array-key,array<array-key,array{c:int,s:float,m:float}>> $merged Mutated.
	 * @param array<array-key,mixed>                                         $rows   Inbound (key-agnostic).
	 */
	private static function merge_dim_buckets_into( array &$merged, array $rows ): void {
		foreach ( $rows as $bucket => $values ) {
			$merged[ $bucket ] ??= [];
			if ( ! \is_array( $values ) ) {
				continue;
			}
			foreach ( $values as $name => $entry ) {
				$entry_arr = Core::arr( $entry );
				$merged[ $bucket ][ $name ] ??= [ 'c' => 0, 's' => 0.0, 'm' => 0.0 ];
				$merged[ $bucket ][ $name ]['c'] += Core::as_int( $entry_arr['c'] ?? 0 );
				$merged[ $bucket ][ $name ]['s'] += Core::as_float( $entry_arr['s'] ?? 0 );
				$merged[ $bucket ][ $name ]['m'] += Core::as_float( $entry_arr['m'] ?? 0 );
			}
		}
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
				$entry_arr = Core::arr( $entry );
				$merged[ $bucket ][ $cat ] ??= [ 't' => 0.0, 'c' => 0.0, 'n' => 0 ];
				$merged[ $bucket ][ $cat ]['t'] += Core::as_float( $entry_arr['t'] ?? 0 );
				$merged[ $bucket ][ $cat ]['c'] += Core::as_float( $entry_arr['c'] ?? 0 );
				$merged[ $bucket ][ $cat ]['n'] += Core::as_int( $entry_arr['n'] ?? 0 );
			}
		}
	}

	/**
	 * Compose the overview payload shape from a pre-loaded URL index.
	 * Shared by the `overview` and `dashboard` verbs — `dashboard` wraps
	 * this alongside the same `$index` to avoid a second memcache fan-out.
	 *
	 * @param array<int,array<array-key,mixed>> $index Output of the memoized index() (load_index_default).
	 * @return array<string, mixed>
	 */
	private static function build_overview_payload( array $index ): array {
		$time_series       = self::merge_hourly_across_partitions();
		$total_requests    = 0;
		$total_sum_ms      = 0.0;
		$total_sum_peak_mb = 0.0;
		foreach ( $time_series as $row ) {
			$row_arr            = Core::arr( $row );
			$total_requests    += Core::as_int( $row_arr['count'] ?? 0 );
			$total_sum_ms      += Core::as_float( $row_arr['sum_ms'] ?? 0 );
			$total_sum_peak_mb += Core::as_float( $row_arr['sum_peak_mb'] ?? 0 );
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
	 * Sum-merge per-partition hourly buckets into one sorted time_series.
	 *
	 * @return array<int, mixed>
	 */
	private static function merge_hourly_across_partitions(): array {
		$merged = [];
		foreach ( self::stats_stores() as $store ) {
			foreach ( $store->get_hourly() as $hour => $row ) {
				$row_arr = Core::arr( $row );
				$merged[ $hour ] ??= [
					'hour'        => $hour,
					'count'       => 0,
					'sum_ms'      => 0.0,
					'sum_peak_mb' => 0.0,
				];
				$merged[ $hour ]['count']       += Core::as_int( $row_arr['count'] ?? 0 );
				$merged[ $hour ]['sum_ms']      += Core::as_float( $row_arr['sum_ms'] ?? 0 );
				$merged[ $hour ]['sum_peak_mb'] += Core::as_float( $row_arr['sum_peak_mb'] ?? 0 );
			}
		}
		\ksort( $merged );
		return \array_values( $merged );
	}

	/**
	 * Pull the per-URL aggregate stats blob (flame, profiles, last_modified).
	 * First partition with a matching blob wins.
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

	// Stats_Store helpers — fan out across partitions and merge.

	/**
	 * One Stats_Store per partition over the shared `Core::$memd` handle.
	 *
	 * @return array<int,Stats_Store>
	 */
	private static function stats_stores(): array {
		if ( null === Core::$memd ) {
			return [];
		}
		$num_partitions = Core::as_int( AppConfig::value( 'num_partitions' ), 1 );
		$max_lifespan   = Core::as_int( AppConfig::value( 'min_lifetime' ), 86400 );
		$stores         = [];
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$stores[] = new Stats_Store( $p, $max_lifespan );
		}
		return $stores;
	}

	// Disk-walking helpers — recent requests + request body lookup + flame.

	/**
	 * Walk `requests.log` partitions and collect the 500 most-recent index
	 * entries for the given url_hash. Mirror of
	 * PerfUrlsController::find_recent_requests_for_url.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function find_recent_requests_for_url( string $url_hash ): array {
		$num_partitions = Core::as_int( AppConfig::value( 'num_partitions' ), 1 );
		$base_dir       = RuntimeConfig::get_base_directory();
		$log_base       = $base_dir . '/logs';

		$requests      = [];
		$entries_count = 0;
		for ( $p = 0; $p < $num_partitions; $p++ ) {
			$partition = new Partition_Node();
			self::name_scratch_partition( $partition, 'requests', $p );
			$partition->arguments( [ "{$log_base}/requests.p{$p}" ] );
			$partition->with_index(
				static function ( array $message, array $position ): ?string {
					return Request_Builder_Node::format_index_entry( $message, $position );
				}
			);
			$partition->scan_index(
				static function ( string $line, int $segment ) use ( &$requests, &$entries_count, $url_hash, $p ): ?bool {
					++$entries_count;
					if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
						return false;
					}
					$entry = Request_Builder_Node::parse_request_index( $line );
					if ( ! \is_array( $entry ) || \trim( Core::as_string( $entry['url_hash'] ) ) !== $url_hash ) {
						return null;
					}
					$requests[] = [
						'rid'          => \trim( Core::as_string( $entry['rid'] ) ),
						'timestamp'    => $entry['timestamp'] ?? 0,
						'duration_ms'  => $entry['duration_ms'] ?? 0,
						'status_code'  => $entry['status_code'] ?? 0,
						'peak_mb'      => $entry['peak_mb'] ?? 0,
						'method'       => $entry['method'] ?? '',
						'error_status' => $entry['error_status'] ?? null,
						'segment'   => $entry['segment'] ?? $segment,
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
	 * Returns the search shape `{rid, partition, url_hash}`.
	 * @return array<string, mixed>
	 */
	private static function find_request_index_entry( string $log_base, int $partition, string $rid, int &$entries_count ): ?array {
		$result   = null;
		$requests = new Partition_Node();
		self::name_scratch_partition( $requests, 'requests', $partition );
		$requests->arguments( [ "{$log_base}/requests.p{$partition}" ] );
		$requests->with_index(
			static function ( array $message, array $position ): ?string {
				return Request_Builder_Node::format_index_entry( $message, $position );
			}
		);
		$requests->scan_index(
			static function ( string $line ) use ( &$result, &$entries_count, $partition, $rid ): ?bool {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = Request_Builder_Node::parse_request_index( $line );
				if ( ! \is_array( $entry ) || \trim( Core::as_string( $entry['rid'] ) ) !== $rid ) {
					return null;
				}
				$result = [
					'rid'       => $rid,
					'partition' => $partition,
					'url_hash'  => \trim( Core::as_string( $entry['url_hash'] ) ),
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
		$requests->arguments( [ "{$log_base}/requests.p{$partition}" ] );
		$requests->with_index(
			static function ( array $message, array $position ): ?string {
				return Request_Builder_Node::format_index_entry( $message, $position );
			}
		);
		$requests->scan_index(
			static function ( string $line ) use ( &$result, &$entries_count, $requests, $rid ): ?bool {
				++$entries_count;
				if ( $entries_count > self::MAX_INDEX_ENTRIES ) {
					return false;
				}
				$entry = Request_Builder_Node::parse_request_index( $line );
				if ( ! \is_array( $entry ) || \trim( Core::as_string( $entry['rid'] ) ) !== $rid ) {
					return null;
				}
				$message = $requests->read_message_at(
					Core::as_int( $entry['segment'] ?? 0 ),
					Core::as_int( $entry['offset'] ?? 0 ),
					Core::as_int( $entry['length'] ?? 0 )
				);
				$req = \is_array( $message ) ? ( $message[ Message::VALUE ] ?? null ) : null;
				if ( ! \is_array( $req ) ) {
					return false;
				}
				$req['url_hash'] = \trim( Core::as_string( $entry['url_hash'] ) );
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
			$flames->arguments( [ "{$log_base}/flames.p{$p}" ] );
			$flames->with_index(
				static function ( array $message, array $position ): ?string {
					return Flame_Builder_Node::format_index_entry( $message, $position );
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
					$message = $flames->read_message_at(
						$entry['segment'],
						$entry['offset'],
						$entry['length']
					);
					$flame = \is_array( $message ) ? ( $message[ Message::VALUE ] ?? null ) : null;
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

	/**
	 * Decode a synced array-option value. Settings_Sync_Node::scalarize()
	 * JSON-encodes arrays unconditionally, so the wire form is always JSON. A
	 * non-JSON value is a contract violation: reject it explicitly to [] with a
	 * rate-limited notice rather than silently mis-parsing it.
	 *
	 * @param string $raw The raw positional value off the wire.
	 * @return array<array-key,mixed>
	 */
	private static function decode_array_value( string $raw ): array {
		$decoded = \json_decode( $raw, true );
		if ( \is_array( $decoded ) ) {
			return $decoded;
		}
		Core::print_less_often( 'PerformanceCI: rejected non-JSON synced array-option value' );
		return [];
	}

	/**
	 * Resolve a Command_Args boolean flag. A bare `--flag` parses to `true`;
	 * A bare `--flag` and `--flag=1` / `--flag=true` are truthy; `--flag=0` /
	 * `--flag=false` and an absent key are false. (These are the only tokens
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
	 * Schema-driven dispatch: each verb is declared once in
	 * `commands[]` carrying its `handler`. The inherited Service_CI_Node ctor
	 * builds the commands table from this schema. Stats-reading verbs build
	 * per-partition Stats_Store off the shared `Core::$memd` handle; a null
	 * handle yields empty/zeroed shapes. Disk-walking verbs work regardless.
	 * 
	 * @api Used by substrate.
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Performance-dashboard surface: overview, URLs, requests, hooks, config, settings.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'overview',
					'description' => 'High-level performance stats across all partitions.',
					'args'        => [
						[ 'name' => 'server', 'type' => 'string', 'required' => false ],
						[ 'name' => 'breakdown', 'type' => 'string', 'required' => false ],
						[ 'name' => 'categories', 'type' => 'bool', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Optional args: server scopes; breakdown = comma-sep dim list.
				$opts       = Command_Args::parse( self::arg_strings( $args ) )['options'];
				$server     = (string) ( $opts['server'] ?? '' );
				$breakdown  = (string) ( $opts['breakdown'] ?? '' );
				$categories = self::flag( $opts, 'categories' );

				\assert( $self instanceof self );
				$payload                       = self::build_overview_payload( $self->index() );
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
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				self::require_manage_options();

				$opts    = Command_Args::parse( self::arg_strings( $args ) )['options'];
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

				\assert( $self instanceof self );
				$index = $self->index();

				if ( '' !== $server ) {
					$srv   = \strtolower( $server );
					$index = \array_values( \array_filter(
						$index,
						static fn ( $e ) => false !== \strpos( \strtolower( Core::as_string( $e['url'] ?? '' ) ), $srv )
					) );
				}
				if ( '' !== $search ) {
					$term  = \strtolower( $search );
					$index = \array_values( \array_filter(
						$index,
						static fn ( $e ) => false !== \strpos( \strtolower( Core::as_string( $e['url'] ?? '' ) ), $term )
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
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				self::require_manage_options();

				$parsed = Command_Args::parse( self::arg_strings( $args ) );
				$opts   = $parsed['options'];
				$hash   = $parsed['positional'][0] ?? '';
				if ( ! \preg_match( '/^[a-f0-9]{8,64}$/', $hash ) ) {
					throw new \RuntimeException( 'invalid hash format' );
				}

				\assert( $self instanceof self );
				$index = $self->index();
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
							// Per-URL time series (UrlDetailView + rps).
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
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				self::require_manage_options();

				$rid = Command_Args::parse( self::arg_strings( $args ) )['positional'][0] ?? '';
				if ( '' === $rid ) {
					throw new \RuntimeException( 'rid required' );
				}

				$num_partitions = Core::as_int( AppConfig::value( 'num_partitions' ), 1 );
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
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				self::require_manage_options();

				$parsed = Command_Args::parse( self::arg_strings( $args ) );
				$rid    = $parsed['positional'][0] ?? '';
				if ( '' === $rid ) {
					throw new \RuntimeException( 'rid required' );
				}
				$partition = (int) ( $parsed['options']['partition'] ?? 0 );

				$num_partitions = Core::as_int( AppConfig::value( 'num_partitions' ), 1 );
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
					'name'        => 'hooks_registered',
					'description' => 'Registered hooks grouped by category.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Recompute total_hooks so the response contract stays stable.
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
					'name'        => 'set',
					'description' => 'Normalized positional single-option perf setting write with sync guard.',
					'args'        => [
						[ 'name' => 'option', 'type' => 'string', 'required' => true ],
						[ 'name' => 'value', 'type' => 'string', 'required' => true ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, array $args, array $envelope = [] ): array {
				self::require_manage_options();

				// Positional: one option per command; Settings_Sync fans out.
				[ $option, $value_arg ] = \array_pad( Command_Args::parse( self::arg_strings( $args ) )['positional'], 2, null );

				$option = Core::str( $option );
				if ( '' === $option ) {
					throw new \RuntimeException( 'option required' );
				}
				if ( ! isset( self::SETTINGS_OPTIONS[ $option ] ) ) {
					throw new \RuntimeException( \esc_html( "unknown option: {$option}" ) );
				}

				// Wire value is string; array options carry JSON, decoded here.
				$type      = self::SETTINGS_OPTIONS[ $option ];
				$raw_value = Core::str( $value_arg );
				$value     = 'array' === $type ? self::decode_array_value( $raw_value ) : $raw_value;

				$sanitized = self::sanitize_settings_value( $value, $type );
				if ( null === $sanitized ) {
					throw new \RuntimeException( 'invalid value for option' );
				}

				// Re-tier the synced ruleset locally, not a raw update_option.
				if ( Rule_Set::OPTION_RULES === $option && \is_array( $sanitized ) ) {
					Rule_Set::apply_synced( $sanitized );
					AppConfig::reset();
					return [
						'option'  => $option,
						'updated' => true,
					];
				}

				// Autoload per Config::autoload_for; emits settings event.
				$ok = \update_option( $option, $sanitized, AppConfig::autoload_for( $option ) );
				AppConfig::reset();

				return [
					'option'  => $option,
					'updated' => $ok,
				];
					},
				],
			],
		] );
	}
}
