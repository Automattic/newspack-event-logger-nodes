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
