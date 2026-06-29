<?php
namespace Newspack_Event_Logger_Nodes\Tests;

use Newspack_Nodes\Tests\TestCase as RuntimeTestCase;

abstract class TestCase extends RuntimeTestCase {

	/**
	 * ELN-specific default prefix so app temp dirs live in their OWN namespace,
	 * not the substrate's `newspack-nodes-test-`. Under parallel run-coverage the
	 * nodes and ELN suites each `rm -rf` their prefix; sharing one prefix had each
	 * suite deleting the other's LIVE temp dirs mid-run. Inherits the parent's
	 * PID + more-entropy uniqueness and auto-cleanup.
	 */
	protected function make_temp_dir( string $prefix = 'newspack-event-logger-nodes-test-' ): string {
		return parent::make_temp_dir( $prefix );
	}

	/**
	 * Same as the substrate helper but also resets the application Config
	 * cache so its merged result picks up the new file.
	 */
	protected function use_base_dir( string $dir, array $extras = [] ): void {
		parent::use_base_dir( $dir, $extras );
		if ( \class_exists( '\\Newspack_Event_Logger_Nodes\\Config' ) ) {
			// Mirror the substrate helper's whitelist trick. The app's
			// Config::validate_config_path() permits only /usr/src + the
			// plugin dir by default, so without this the per-test config
			// file under /tmp is rejected — load_config_defaults() falls
			// back to the bundled plugin defaults and tests that depend
			// on the `$extras` override silently see the wrong defaults.
			$ref  = new \ReflectionProperty( \Newspack_Event_Logger_Nodes\Config::class, 'allowed_config_dirs' );
			$dirs = $ref->getValue();
			if ( ! \in_array( $dir, $dirs, true ) ) {
				$dirs[] = $dir;
				$ref->setValue( null, $dirs );
			}
			\Newspack_Event_Logger_Nodes\Config::reset();
		}
	}
}
