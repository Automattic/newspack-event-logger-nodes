<?php
/**
 * Hub designation helper.
 *
 * Single source of truth for "is this site currently configured to act as
 * a hub?" Fail-closed: missing config, non-strict-true `enable_workers`,
 * or disabled aggregator all mean "no". Same polarity SettingsSync and
 * AutoTuner share — diverging is how legacy 2.4.42 silently turned spokes
 * into hubs. `enable_workers` is application-owned (lives in the app's
 * Config, not the substrate's).
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

class Hub {
	/**
	 * Hub designation. Strict:
	 * - `enable_workers === true` (true boolean, not 1/"1"/truthy)
	 * - `enable_aggregator` option is on (defaults to 1 if absent)
	 *
	 * Anything else fails closed. The aggregator gate covers both directions
	 * of remote-server activity — turning Enable Aggregator off must stop
	 * the StreamMerger pull AND the settings/auto-tune fanout push.
	 */
	public static function is_active(): bool {
		$config = Config::load_config();
		if ( ! isset( $config['enable_workers'] ) || true !== $config['enable_workers'] ) {
			return false;
		}
		if ( \function_exists( 'get_option' ) && ! (int) \get_option( 'newspack_event_logger_nodes_enable_aggregator', 1 ) ) {
			return false;
		}
		return true;
	}
}
