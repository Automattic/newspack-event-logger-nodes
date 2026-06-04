<?php
/**
 * Events_CI: command-dispatch for the hourly-stats surface that powers
 * the `event-dashboards` React tree.
 *
 * Replaces legacy class-events-controller.php with a CommandInterpreter
 * that mounts at priority 11 alongside the rest of the M2 service CIs.
 *
 * Verbs:
 *   stats  — merge of per-partition hourly buckets read from Stats_Store
 *            into a single time_series array sorted by hour. Fail-soft on
 *            memcache outage (Stats_Store::get_hourly returns []).
 *
 * Value-equivalence with the legacy controller: same envelope shape
 * (`{data, meta}`), same time_series payload, same fail-soft semantics
 * on the stats path.
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

use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Core;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

class Events_CI_Node extends Service_CI_Node {

	/**
	 * Schema-driven dispatch: each verb is declared once in `verbs[]` carrying
	 * its `handler`. The inherited Service_CI_Node ctor builds the commands
	 * table from this schema, so no per-class ctor is needed.
	 *
	 * The `stats` verb reads per-partition Stats_Store off the shared
	 * `Core::$memd` handle; a null handle yields an empty time_series.
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Service',
			'description' => 'Hourly-stats surface for the event-dashboards tree.',
			'arguments'        => [],
			'commands'       => [
				[
					'name'        => 'stats',
					'description' => 'Merge per-partition hourly buckets into one time_series.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
						$config            = RuntimeConfig::load_config();
						$num_partitions_v  = $config['num_partitions'] ?? 1;
						$max_lifespan_v    = $config['max_lifespan'] ?? 86400;
						$num_partitions    = \is_scalar( $num_partitions_v ) ? (int) $num_partitions_v : 1;
						$max_lifespan      = \is_scalar( $max_lifespan_v ) ? (int) $max_lifespan_v : 86400;

						$merged = [];
						if ( null !== Core::$memd ) {
							for ( $p = 0; $p < $num_partitions; $p++ ) {
								$store = new Stats_Store( $p, $max_lifespan );
								foreach ( $store->get_hourly() as $hour => $row ) {
									if ( ! isset( $merged[ $hour ] ) ) {
										$merged[ $hour ] = [
											'hour'        => $hour,
											'count'       => 0,
											'sum_ms'      => 0.0,
											'sum_peak_mb' => 0.0,
										];
									}
									if ( ! \is_array( $row ) ) {
										continue;
									}
									$count_v       = $row['count'] ?? 0;
									$sum_ms_v      = $row['sum_ms'] ?? 0;
									$sum_peak_mb_v = $row['sum_peak_mb'] ?? 0;
									$merged[ $hour ]['count']       += \is_scalar( $count_v ) ? (int) $count_v : 0;
									$merged[ $hour ]['sum_ms']      += \is_scalar( $sum_ms_v ) ? (float) $sum_ms_v : 0.0;
									$merged[ $hour ]['sum_peak_mb'] += \is_scalar( $sum_peak_mb_v ) ? (float) $sum_peak_mb_v : 0.0;
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
				],
			],
		];
	}
}
