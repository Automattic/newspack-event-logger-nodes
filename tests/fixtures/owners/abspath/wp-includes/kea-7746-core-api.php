<?php
/**
 * Fixture: core code, for the owner-prefix tests. It stands in for a core API
 * such as `get_option()` that a plugin calls and that reaches `wpdb`, and for
 * a core class a plugin subclasses.
 *
 * @package Newspack_Event_Logger_Nodes
 */

/** A core API reaching the stand-in `wpdb`, as `get_option()` does. */
function kea_7746_get_option( object $transport, string $sql ): mixed {
	return $transport->get_row( $sql );
}

/** A core API firing `the_content`, as a template tag does. */
function kea_7746_the_content( Newspack_Event_Logger_Nodes\App\Core $core ): mixed {
	return $core->hook_start( 'kea core body' );
}

/**
 * A core API that filters `option_home` through its `WP_Hook`, as
 * `get_option( 'home' )` does.
 */
function kea_7746_get_option_home(): mixed {
	return $GLOBALS['wp_filter']['option_home']->apply_filters( 'https://kea.example', [ 'https://kea.example' ] );
}

/**
 * Core frames between a listener and the API it reaches, as
 * `wp_default_scripts()` reaches `get_option()` through `get_rest_url()` and
 * `get_home_url()`: `$frames` of them, then `$api( ...$args )`.
 *
 * @param int    $frames How many frames of this function stand between.
 * @param string $api    The core API the last one calls.
 * @param mixed  ...$args Its arguments.
 */
function kea_7746_core_chain( int $frames, string $api, mixed ...$args ): mixed {
	return $frames > 1 ? kea_7746_core_chain( $frames - 1, $api, ...$args ) : $api( ...$args );
}

/** A core class whose methods a plugin subclass hooks. */
class Kea_7746_Core_Parent {

	/** An inherited listener. */
	public function filter_the_content( string $v ): string {
		return "{$v} core-parent";
	}

	/** An inherited static listener. */
	public static function filter_statically( string $v ): string {
		return "{$v} core-static";
	}
}
