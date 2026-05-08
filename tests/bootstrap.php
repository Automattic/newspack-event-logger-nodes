<?php
\define( 'ABSPATH', '/' );

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return \dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'do_action' ) ) {
	$GLOBALS['_wp_actions'] = [];
	function do_action( string $hook, ...$args ): void {
		foreach ( $GLOBALS['_wp_actions'][ $hook ] ?? [] as $cb ) {
			$cb( ...$args );
		}
	}
	function add_action( string $hook, callable $cb ): void {
		$GLOBALS['_wp_actions'][ $hook ][] = $cb;
	}
	function apply_filters( string $hook, mixed $value, ...$args ): mixed {
		foreach ( $GLOBALS['_wp_actions'][ $hook ] ?? [] as $cb ) {
			$value = $cb( $value, ...$args );
		}
		return $value;
	}
	function add_filter( string $hook, callable $cb ): void {
		$GLOBALS['_wp_actions'][ $hook ][] = $cb;
	}
}

require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/newspack-nodes.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/TestCase.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/CaptureSink.php';
require_once \dirname( __DIR__, 2 ) . '/newspack-nodes/tests/Helpers/BoundedTicks.php';

require_once \dirname( __DIR__ ) . '/newspack-event-logger-nodes.php';

require_once __DIR__ . '/Helpers/TestCase.php';
