<?php
\define( 'ABSPATH', '/' );

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return \dirname( $file ) . '/';
	}
}

require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/newspack-nodes.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/TestCase.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/CaptureSink.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/BoundedTicks.php';

require_once \dirname( __DIR__ ) . '/newspack-event-logger-nodes.php';

require_once __DIR__ . '/Helpers/TestCase.php';
