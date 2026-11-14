<?php
/**
 * Log_Pages: the Request Log, Error Log and Gyroscope as composed pages on
 * the substrate's composition layer.
 *
 * Each page lays out a context of its own, `newspack-event-logger-nodes/<page>`,
 * whose default layout is `layouts/<page>.html`, one log-stream block over the
 * page's stream, which `contexts()` answers for `Compositions::declare()`, the
 * plugin's one declaration. `menu()` names each page's admin slug and titles,
 * which the plugin's menu and its enqueue read. The three pages share one
 * bundle, `build/log-pages`, and one layout editor: a page's load sets
 * `page_data()` before that bundle, as an inline script of its own, and loads
 * the editor on a load whose `?edit=` names the page, for a `tune` caller
 * alone.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Admin;

\defined( 'ABSPATH' ) || exit;

use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Composition;
use Newspack_Nodes\Composition_Store;

/**
 * The log pages' contexts, their menu entries and their page data.
 */
final class Log_Pages {

	/** The global a log page's load sets to `page_data()`. */
	public const GLOBAL = 'NewspackEventLoggerLogPage';

	/** The layout editor's script handle. */
	public const EDITOR = 'newspack-event-logger-nodes-log-pages-editor';

	/**
	 * Each page, as `?edit=` and its context name it => its admin slug, its
	 * page title and its menu title.
	 */
	private const PAGES = [
		'request-log' => [ 'event-logger-requests', 'Request Log', 'Request Log' ],
		'error-log'   => [ 'event-logger-errors', 'Error Log', 'Errors' ],
		'gyroscope'   => [ 'event-logger-gyroscope', 'Gyroscope', 'Gyroscope' ],
	];

	/**
	 * The most blocks a log page holds: its stream and a curator's notes and
	 * figures around it. Each log-stream block on another source opens a
	 * stream of its own on the page's one link, which this keeps few.
	 */
	private const MAX_BLOCKS = 16;

	/**
	 * The pages' contexts, as `Compositions::declare()` reads them.
	 *
	 * @return array<string,array{default: string, max_blocks: int}>
	 */
	public static function contexts(): array {
		$contexts = [];
		foreach ( \array_keys( self::PAGES ) as $page ) {
			$contexts[ self::context( $page ) ] = [
				'default'    => \dirname( __DIR__, 2 ) . "/layouts/{$page}.html",
				'max_blocks' => self::MAX_BLOCKS,
			];
		}
		return $contexts;
	}

	/**
	 * Set the page data of the page at `$slug` ahead of the bundle `$handle`,
	 * escaped for a script element, then load the layout editor when `?edit=`
	 * names the page, for a `tune` caller alone.
	 *
	 * @param string $slug      The admin page's slug.
	 * @param string $handle    The bundle's script handle.
	 * @param string $build_dir The layout editor's build directory.
	 * @param string $build_url Its URL.
	 * @throws \LogicException On a slug this class lays out no page for, or through `Composition`, on a declaration that breaks a rule.
	 * @throws \RuntimeException When the page data will not encode.
	 * @throws \InvalidArgumentException Through `Capabilities::can()`, on a broken capability map.
	 */
	public static function enqueue( string $slug, string $handle, string $build_dir, string $build_url ): void {
		$page = self::page_of( $slug );
		$json = \wp_json_encode( self::page_data( $page ), \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new \RuntimeException( "the {$slug} page data will not encode as JSON" );
		}
		\wp_add_inline_script( $handle, 'window.' . self::GLOBAL . " = {$json};", 'before' );
		Composition::enqueue_editor( [ $page => self::context( $page ) ], self::EDITOR, $build_dir, $build_url );
	}

	/**
	 * What a page reads: which page it is, whether the caller may curate its
	 * layout and read a refusal's own words, and its context's layout or its
	 * refusal.
	 *
	 * @param string $page The page, as `?edit=` names it.
	 * @return array{page: string, canTune: bool, canManage: bool, compositions: array<string,mixed>}
	 * @throws \LogicException When the page's context is not declared.
	 * @throws \InvalidArgumentException Through `Capabilities::can()`, on a broken capability map.
	 */
	public static function page_data( string $page ): array {
		return [
			'page'         => $page,
			'canTune'      => Capabilities::can( Capabilities::TUNE ),
			'canManage'    => Capabilities::can( Capabilities::MANAGE ),
			'compositions' => Composition_Store::page_data( [ self::context( $page ) ] ),
		];
	}

	/**
	 * A page's context.
	 *
	 * @param string $page The page.
	 * @return string Its context.
	 */
	private static function context( string $page ): string {
		return Compositions::NAMESPACE . "/{$page}";
	}

	/**
	 * The page an admin slug shows.
	 *
	 * @param string $slug The admin page's slug.
	 * @return string The page.
	 * @throws \LogicException When this class lays out no page there.
	 */
	private static function page_of( string $slug ): string {
		foreach ( self::PAGES as $page => [ $at ] ) {
			if ( $slug === $at ) {
				return $page;
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers.
		throw new \LogicException( "no log page is laid out at {$slug}" );
	}

	/**
	 * Each page's admin slug => its page title and menu title.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function menu(): array {
		$menu = [];
		foreach ( self::PAGES as [ $slug, $title, $menu_title ] ) {
			$menu[ $slug ] = [ $title, $menu_title ];
		}
		return $menu;
	}
}
