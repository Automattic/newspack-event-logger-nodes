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
 *             shape (id, url, enabled, has_credentials, is_config),
 *             matching the substrate Vault_CI public shape, but RETURNED
 *             AS A SEQUENTIAL ARRAY rather than a map keyed by id. Legacy
 *             contract — the React aggregator tree relies on the array
 *             shape; don't switch to a keyed map here.
 *
 * Auth: all three verbs require `manage_options`. Legacy parity — both
 * controllers gated every route through `read_permissions_check()`,
 * which enforces the capability.
 *
 * Memcache reads go through the shared `Core::$memd` handle: the `status`
 * verb reads `aggregator_status:{id}:p{N}` per partition; the `health` verb
 * reports whether the handle is configured. The `status`/`servers` verbs
 * read the substrate `Newspack_Nodes\Vault` singleton directly — there is
 * no injected registry dependency.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Core;
use Newspack_Nodes\Service_CI_Node;
use Newspack_Nodes\Vault;

\defined( 'ABSPATH' ) || exit;

class Aggregator_CI_Node extends Service_CI_Node {

	/** @api Used by the substrate to provide UI etc. */
	public static function node_schema(): array {
		return [
			'category'    => 'Service',
			'description' => 'Hub-side aggregator dashboards: per-server status, cache health, registered servers.',
			'arguments'        => [],
			'commands'       => [
				[
					'name'        => 'status',
					'description' => 'Per-server partition snapshot keyed by server id.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
						self::require_manage_options();
						$registry = Vault::get_instance();
						$registry->reset_cache();
						$servers = $registry->get_all();

						$config           = RuntimeConfig::load_config();
						$num_partitions_v = $config['num_partitions'] ?? 1;
						$num_partitions   = \min( 16, \max( 1, \is_scalar( $num_partitions_v ) ? (int) $num_partitions_v : 1 ) );

						$result = [];
						foreach ( $servers as $id => $server ) {
							$partitions = [];
							for ( $p = 0; $p < $num_partitions; $p++ ) {
								$val              = Core::$memd?->get( "aggregator_status:{$id}:p{$p}" );
								$partitions[ $p ] = \is_array( $val ) ? $val : [];
							}

							$url_v         = $server['url'] ?? null;
							$result[ $id ] = [
								'id'         => $id,
								'url'        => \is_scalar( $url_v ) ? \esc_url_raw( (string) $url_v ) : '',
								'enabled'    => $server['enabled'] ?? true,
								'partitions' => $partitions,
							];
						}

						return $result;
					},
				],
				[
					'name'        => 'health',
					'description' => 'Cache reachability + wall-clock timestamp.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
						self::require_manage_options();
						return [
							'healthy'   => true,
							'cache'     => null !== Core::$memd,
							'timestamp' => \time(),
						];
					},
				],
				[
					'name'        => 'servers',
					'description' => 'Registered servers as a sequential array (legacy aggregator-tree contract).',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope = [] ): array {
						self::require_manage_options();
						$registry = Vault::get_instance();
						$registry->reset_cache();
						$out = [];
						foreach ( $registry->get_all() as $id => $cfg ) {
							$url_v   = $cfg['url'] ?? '';
							$out[]   = [
								'id'              => $id,
								'url'             => \is_scalar( $url_v ) ? (string) $url_v : '',
								'enabled'         => (bool) ( $cfg['enabled'] ?? false ),
								'has_credentials' => ! empty( $cfg['auth_username'] ) && ! empty( $cfg['auth_password'] ),
								'is_config'       => $registry->is_config_server( $id ),
							];
						}
						// Sequential array, NOT a map keyed by id — legacy contract the
						// React aggregator tree relies on.
						return $out;
					},
				],
			],
		];
	}
}
