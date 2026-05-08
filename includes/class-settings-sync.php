<?php
/**
 * SettingsSync: hub-side sync of WP options to remote spokes.
 *
 * Critical invariant: fail-closed hub-check polarity. Only sync if
 * enable_workers === true (strict). Missing or non-true means "not a hub."
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

class SettingsSync {
	private array $config;
	private array $synced_options;
	/** @var callable */
	private $dispatch;
	private bool $syncing = false;

	public function __construct(
		array $config,
		array $synced_options,
		callable $dispatch
	) {
		$this->config         = $config;
		$this->synced_options = $synced_options;
		$this->dispatch       = $dispatch;
	}

	public function suppress_sync( bool $suppress = true ): void {
		$this->syncing = $suppress;
	}

	public function on_option_update( string $option, $old, $new ): void {
		if ( $this->syncing ) {
			return; // Re-entrancy guard.
		}
		// Fail-closed: strict === true. Anything else (missing, false, 1, "1", etc.) skips.
		if ( ! isset( $this->config['enable_workers'] ) || true !== $this->config['enable_workers'] ) {
			return;
		}
		if ( ! \in_array( $option, $this->synced_options, true ) ) {
			return;
		}
		( $this->dispatch )( $option, $new );
	}
}
