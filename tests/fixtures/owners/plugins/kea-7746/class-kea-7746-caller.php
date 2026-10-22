<?php
/**
 * Fixture: code a plugin named `kea-7746` owns, for the owner-prefix tests.
 * Its methods are called from AppCoreTest.php, whose own file has no owner,
 * so a label prefixed from the wrong frame's file comes out bare.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Fixtures;

use Newspack_Event_Logger_Nodes\App\Core;

/** A plugin class that asks the transport for work and binds listeners. */
class Kea_7746_Caller {

	/** Run a query through a stand-in `wpdb`. */
	public function ask_for_articles( object $transport, string $sql ): mixed {
		return $transport->get_row( $sql );
	}

	/** Run a query through a core API, as a plugin's `get_option()` does. */
	public function ask_through_core( object $transport, string $sql ): mixed {
		return \kea_7746_get_option( $transport, $sql );
	}

	/** A caller whose label runs past the cap, in two-byte characters. */
	public function ask_ōōōōōōōōōōōōōōōōōōōōōōōōōōōōōō( object $transport, string $sql ): mixed {
		return $transport->get_row( $sql );
	}

	/** Fetch a URL through a stand-in `WP_Http`. */
	public function fetch_feed( object $transport, string $url ): mixed {
		return $transport->dispatch( $url );
	}

	/** Fire `the_content` the way a template does. */
	public function render_content( Core $core ): mixed {
		return $core->hook_start( 'kea body' );
	}

	/** A listener this plugin binds. */
	public static function filter_the_content( string $v ): string {
		return "{$v} kea-7746";
	}

	/** A closure listener this plugin binds. */
	public static function closure_listener(): \Closure {
		return static fn ( string $v ): string => "{$v} closure-7746";
	}
}

/** A plugin subclass of a core class, hooked by its inherited methods. */
class Kea_7746_Subclass extends \Kea_7746_Core_Parent {
}
