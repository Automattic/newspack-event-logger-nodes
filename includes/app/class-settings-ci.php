<?php
/**
 * Settings_CI: command-dispatch for the substrate-level integer settings.
 *
 * Replaces legacy class-settings-controller.php with a CommandInterpreter
 * that mounts at priority 11 alongside the rest of the M2 service CIs.
 *
 * Verbs:
 *   get    — returns the four substrate-owned integer settings as a snapshot
 *            (num_partitions, num_segments, segment_size, max_lifespan).
 *            This verb is additive; the legacy controller only exposed a
 *            POST/update path, but the matching getter is what dashboards
 *            and RemoteManager fan-out diff against.
 *   update — partial-applies any subset of those four keys, writes via
 *            `update_option()` under the `newspack_nodes_*` prefix (matching
 *            legacy ALLOWED_OPTIONS targets), then returns the post-update
 *            snapshot. Resets the application Config so the snapshot rebuild
 *            sees the new value rather than the stale cache.
 *
 * Value-equivalence with the legacy controller: same allowed-keys
 * whitelist, same min/max bounds (1..2^30 for three keys, 0..2^30 for
 * max_lifespan), same `manage_options` requirement, same WP option keys.
 * Throws RuntimeException on validation / authorization failure;
 * CommandInterpreter::interpret() wraps as TM_COMMAND|TM_ERROR.
 *
 * Configuration-only verb; no service dependencies. The substrate Config
 * is a global accessed directly, matching the legacy controller and the
 * pattern in Status_CI / Discovery_CI.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Config as AppConfig;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config as RuntimeConfig;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

class Settings_CI_Node extends Service_CI_Node {

	/**
	 * Upper bound for all four integer settings (2^30 = 1 GiB). Matches
	 * legacy SettingsController::sanitize_value to keep the validator
	 * value-equivalent.
	 */
	private const MAX_INT_VALUE = 1073741824;

	/**
	 * Whitelist of {short-name => min} for the verbs. The WP option key is
	 * the short-name prefixed with `newspack_nodes_`. Three settings have
	 * min=1; max_lifespan accepts 0 (per legacy). The upper bound is
	 * shared (MAX_INT_VALUE).
	 *
	 * @var array<string,int>
	 */
	private const ALLOWED_KEYS = [
		'num_partitions' => 1,
		'num_segments'   => 1,
		'segment_size'   => 1,
		'max_lifespan'   => 0,
	];

	/**
	 * Schema-driven dispatch: each verb is declared once in `verbs[]` carrying
	 * its `handler`. The inherited Service_CI_Node ctor builds the commands
	 * table from this schema. Configuration-only verbs; no service dependencies.
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Service',
			'description' => 'Substrate-level integer settings: get / update num_partitions, num_segments, segment_size, max_lifespan.',
			'ctor'        => [],
			'verbs'       => [
				[
					'name'        => 'get',
					'description' => 'Return the four substrate-owned integer settings as a snapshot.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
						return self::snapshot();
					},
				],
				[
					'name'        => 'update',
					'description' => 'Partial-apply any subset of the four settings, then return the post-update snapshot.',
					'args'        => [
						[ 'name' => 'num_partitions', 'type' => 'int', 'required' => false ],
						[ 'name' => 'num_segments', 'type' => 'int', 'required' => false ],
						[ 'name' => 'segment_size', 'type' => 'int', 'required' => false ],
						[ 'name' => 'max_lifespan', 'type' => 'int', 'required' => false ],
					],
					'handler'     => static function ( Command_Interpreter_Node $self, string $args, array $envelope, mixed $payload ): array {
						self::require_manage_options();
						$decoded = \is_array( $payload ) ? $payload : [];

						foreach ( $decoded as $key => $value ) {
							if ( ! isset( self::ALLOWED_KEYS[ $key ] ) ) {
								throw new \RuntimeException( \esc_html( "unknown setting: {$key}" ) );
							}
							$sanitized = self::sanitize_int( $value, self::ALLOWED_KEYS[ $key ], self::MAX_INT_VALUE );
							if ( null === $sanitized ) {
								throw new \RuntimeException( \esc_html( "invalid value for setting: {$key}" ) );
							}
							// autoload=true: these substrate scalars are read on
							// every request by Newspack_Nodes\Config, so they must
							// ride the single alloptions query rather than becoming
							// N separate per-request get_option lookups.
							\update_option( "newspack_nodes_{$key}", $sanitized, true );
						}

						// Application Config::reset() cascades into the substrate
						// Config::reset(), so the snapshot rebuild reads the fresh
						// option values rather than the cached pre-update view.
						AppConfig::reset();

						return self::snapshot();
					},
				],
			],
		];
	}

	/**
	 * Build the canonical four-key snapshot from the substrate Config.
	 *
	 * @return array{num_partitions:int,num_segments:int,segment_size:int,max_lifespan:int}
	 */
	private static function snapshot(): array {
		$config = RuntimeConfig::load_config();
		return [
			'num_partitions' => (int) ( $config['num_partitions'] ?? 0 ),
			'num_segments'   => (int) ( $config['num_segments']   ?? 0 ),
			'segment_size'   => (int) ( $config['segment_size']   ?? 0 ),
			'max_lifespan'   => (int) ( $config['max_lifespan']   ?? 0 ),
		];
	}

	/**
	 * Type-coerce + bounds-check. Value-equivalent with legacy
	 * SettingsController::sanitize_value (int branch only — the legacy
	 * whitelist is int-only).
	 *
	 * @param mixed $value Raw input.
	 * @param int   $min   Per-key minimum (inclusive).
	 * @param int   $max   Shared upper bound (inclusive).
	 * @return int|null Sanitized int, or null if rejected.
	 */
	private static function sanitize_int( mixed $value, int $min, int $max ): ?int {
		if ( ! \is_numeric( $value ) ) {
			return null;
		}
		$int = (int) $value;
		if ( $int < $min || $int > $max ) {
			return null;
		}
		return $int;
	}
}
