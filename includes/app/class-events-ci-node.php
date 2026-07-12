<?php
/**
 * Events_CI: command-dispatch for the hourly-stats surface that powers
 * the `event-dashboards` React tree.
 *
 * A CommandInterpreter that mounts at priority 11 alongside the rest of
 * the M2 service CIs.
 *
 * Verbs:
 *   stats  — merge of per-partition hourly buckets read from Stats_Store
 *            into a single time_series array sorted by hour. Fail-soft on
 *            memcache outage (Stats_Store::get_hourly returns []).
 *
 * The response is a fixed contract the event-dashboards tree depends on:
 * envelope shape (`{data, meta}`), the time_series payload, and fail-soft
 * semantics on the stats path.
 *
 * Dependencies are injected via the constructor so tests can stub Cache
 * without touching the substrate's request-scope graph; this mirrors the
 * dependency-injection pattern Workers_CI and Status_CI adopted. The
 * substrate Config is a global accessed directly.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Stats_Store;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Event_Logger_Nodes\Config as AppConfig;
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
	 * 
	 * @api Used by substrate.
	 */
	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Hourly-stats surface for the event-dashboards tree.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'stats',
					'description' => 'Merge per-partition hourly buckets into one time_series.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
						$num_partitions    = Core::as_int( AppConfig::value( 'num_partitions' ), 1 );
						$max_lifespan      = Core::as_int( AppConfig::value( 'min_lifetime' ), 86400 );

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
									$merged[ $hour ]['count']       += Core::as_int( $count_v );
									$merged[ $hour ]['sum_ms']      += Core::as_float( $sum_ms_v );
									$merged[ $hour ]['sum_peak_mb'] += Core::as_float( $sum_peak_mb_v );
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
		] );
	}
}
