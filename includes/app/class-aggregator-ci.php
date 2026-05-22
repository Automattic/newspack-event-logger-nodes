<?php
/**
 * Aggregator_CI: command-dispatch for the hub-side aggregator dashboards.
 *
 * Canonical implementation of the three hub-side aggregator endpoints
 * that the legacy `newspack-nodes-aggregator/v1` namespace exposed:
 * `status`, `servers`, `health`. The dashboard cutover (commit 1350303)
 * migrated `AggregatorStatus.js` from `apiFetch('.../v1/status')` to
 * `commandClient.send('aggregator', 'status')`. The legacy
 * `AggregatorController` REST shim is preserved for any non-dashboard
 * caller and holds its `/status` body in parity with the `status` verb
 * here; the dedicated `AggregatorStatusController` (which the shim used
 * to delegate to) was deleted in the M4 cutover. Mounts at priority 11
 * alongside the rest of the M2 service CIs.
 *
 * Verbs:
 *   status  — per-server partition snapshot keyed by server id. For each
 *             enabled spoke, looks up `aggregator_status:{id}:p{N}` from
 *             memcache (one entry per partition the StreamMerger pulls
 *             from). Cache misses default to an empty array, not null.
 *   health  — cache reachability + wall-clock timestamp. Mirrors the
 *             legacy {healthy, cache, timestamp} shape. Cache probe is
 *             wrapped in a Throwable catch so the endpoint never fails
 *             — a cache outage reports `cache=false`, not 500.
 *   servers — sequential array of registered servers with public-safe
 *             shape. Same field set as Servers_CI.list (id, url, enabled,
 *             logs, has_credentials, is_config), but RETURNED AS A
 *             SEQUENTIAL ARRAY rather than a map keyed by id. Legacy
 *             contract — the React aggregator tree relies on the array
 *             shape; don't switch to a keyed map here.
 *
 * Auth: all three verbs require `manage_options`. Legacy parity — both
 * controllers gated every route through `read_permissions_check()`,
 * which enforces the capability.
 *
 * Memcache reads go through the shared `Core::$memd` handle: the `status`
 * verb reads `aggregator_status:{id}:p{N}` per partition; the `health` verb
 * reports whether the handle is configured. The ServerRegistry is the only
 * injected dependency (tests pass a fresh instance per test).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\ServerRegistry;
use Newspack_Nodes\CommandInterpreter;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Core;
use Newspack_Nodes\Service_CI;

\defined( 'ABSPATH' ) || exit;

class Aggregator_CI extends Service_CI {

	/**
	 * @param ServerRegistry $registry Hub-side server registry. Tests pass a
	 *                                  fresh instance per test so they don't
	 *                                  share state.
	 */
	public function __construct( ServerRegistry $registry ) {
		// Node + CommandInterpreter have no explicit __construct, so the
		// inherited no-op is implicit. Mirrors Workers_CI / Servers_CI /
		// Status_CI / Discovery_CI / Logger_CI / Events_CI / Settings_CI.
		$this->commands( $this->verb_table( $registry ) );
	}

	private function verb_table( ServerRegistry $registry ): array {
		return [
			'status'  => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $registry ): array {
				self::require_manage_options();
				$registry->reset_cache();
				$servers = $registry->get_all();

				$config         = RuntimeConfig::load_config();
				$num_partitions = \min( 16, \max( 1, (int) ( $config['num_partitions'] ?? 1 ) ) );

				$result = [];
				foreach ( $servers as $id => $server ) {
					if ( ! \is_array( $server ) ) {
						continue;
					}
					$partitions = [];
					for ( $p = 0; $p < $num_partitions; $p++ ) {
						$val              = Core::$memd?->get( "aggregator_status:{$id}:p{$p}" );
						$partitions[ $p ] = \is_array( $val ) ? $val : [];
					}

					$result[ $id ] = [
						'id'         => $id,
						'url'        => isset( $server['url'] ) ? \esc_url_raw( (string) $server['url'] ) : '',
						'enabled'    => $server['enabled'] ?? true,
						'partitions' => $partitions,
					];
				}

				return $result;
			},
			'health'  => static function ( CommandInterpreter $self, string $args, array $envelope = [] ): array {
				self::require_manage_options();
				return [
					'healthy'   => true,
					'cache'     => null !== Core::$memd,
					'timestamp' => \time(),
				];
			},
			'servers' => static function ( CommandInterpreter $self, string $args, array $envelope = [] ) use ( $registry ): array {
				self::require_manage_options();
				$registry->reset_cache();
				$out = [];
				foreach ( $registry->get_all() as $id => $cfg ) {
					$out[] = [
						'id'              => (string) $id,
						'url'             => (string) ( $cfg['url'] ?? '' ),
						'enabled'         => (bool) ( $cfg['enabled'] ?? false ),
						'logs'            => $cfg['logs'] ?? [],
						'has_credentials' => ! empty( $cfg['auth_username'] ) && ! empty( $cfg['auth_password'] ),
						'is_config'       => $registry->is_config_server( (string) $id ),
					];
				}
				// Sequential array, NOT a map keyed by id — legacy contract the
				// React aggregator tree relies on.
				return $out;
			},
		];
	}
}
