<?php
/**
 * Compositions: this plugin's composed pages on the substrate's composition
 * layer, and its one declaration there.
 *
 * One table names every composed page — Performance, the Request Log, the
 * Error Log and the Gyroscope — by the name `?edit=` gives it: its admin
 * slug and titles, the bundle its screen loads, its block cap, and what its
 * page data adds to what every page reads. Each lays out the context
 * `newspack-event-logger-nodes/<page>`, whose default layout is
 * `layouts/<page>.html` and which admits every shared block a source here
 * feeds, which leaves out the table. `declare()`, on
 * `Composition::FILTER`, declares those contexts and the blocks the
 * Performance page owns. A page's load sets `page_data()` before its bundle,
 * as an inline script of its own, and loads its bundle's layout editor on a
 * load whose `?edit=` names the page, for a `tune` caller alone.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Admin;

\defined( 'ABSPATH' ) || exit;

use Newspack_Event_Logger_Nodes\Bucket;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Composition;
use Newspack_Nodes\Composition_Store;
use Newspack_Nodes\Core;
use Newspack_Nodes\Worker_Should_Stop;

/**
 * The plugin's composed pages, their declaration and their page data.
 */
final class Compositions {

	/** The composition namespace this plugin declares under. */
	public const NAMESPACE = 'newspack-event-logger-nodes';

	/** The global a composed page's load sets to `page_data()`. */
	public const GLOBAL = 'NewspackEventLoggerComposedPage';

	/** The shared blocks every page admits: each one a source here feeds. */
	private const ADMITS = [
		'newspack-nodes/stats',
		'newspack-nodes/time-chart',
		'newspack-nodes/log-stream',
		'newspack-nodes/sparkline',
		'newspack-nodes/section',
	];

	/** Each block the Performance page owns => its title; each stands once. */
	private const BLOCKS = [
		'breakdown-bar'   => 'Breakdown bar',
		'aggregate-chart' => 'Aggregate chart',
		'category-chart'  => 'Category charts',
		'profile'         => 'Time breakdown',
		'url-table'       => 'URL table',
	];

	/**
	 * Declare the namespace on `Composition::FILTER`: every page's context,
	 * and the blocks the Performance page owns.
	 *
	 * @param array<mixed> $declared What the filter holds so far.
	 * @return array<mixed>
	 */
	public static function declare( array $declared ): array {
		$contexts = [];
		foreach ( self::pages() as $page ) {
			$contexts[ $page['context'] ] = [
				'default'    => $page['default'],
				'max_blocks' => $page['max_blocks'],
				'admits'     => self::ADMITS,
			];
		}
		$blocks = [];
		foreach ( self::BLOCKS as $name => $title ) {
			$blocks[ self::NAMESPACE . "/{$name}" ] = [
				'title'      => $title,
				'contexts'   => [ self::pages()['performance']['context'] ],
				'attributes' => [],
				'multiple'   => false,
			];
		}
		$declared[ self::NAMESPACE ] = [
			'blocks'   => $blocks,
			'contexts' => $contexts,
		];
		return $declared;
	}

	/**
	 * Each page's admin slug => its page title, its menu title and the bundle
	 * its screen loads, in menu order.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public static function menu(): array {
		$menu = [];
		foreach ( self::pages() as $page ) {
			$menu[ $page['slug'] ] = [ $page['title'], $page['menu_title'], $page['bundle'] ];
		}
		return $menu;
	}

	/**
	 * Set the page data of the page at `$slug` ahead of its bundle `$handle`,
	 * escaped for a script element, then load its bundle's layout editor when
	 * `?edit=` names the page, for a `tune` caller alone.
	 *
	 * @param string $slug      The admin page's slug.
	 * @param string $handle    The page bundle's script handle.
	 * @param string $build_dir The plugin's build directory.
	 * @param string $build_url Its URL.
	 * @throws \LogicException On a slug outside the table.
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
		$row    = self::pages()[ $page ];
		$editor = "{$row['bundle']}-editor";
		Composition::enqueue_editor( [ $page => $row['context'] ], self::NAMESPACE . "-{$editor}", "{$build_dir}/{$editor}", "{$build_url}/{$editor}" );
	}

	/**
	 * What a page reads: which page it is, whether the caller may curate its
	 * layout and read a refusal's own words, its context's layout or its
	 * refusal, and what its row adds — the Performance page's stats window.
	 *
	 * @param string $page The page, as `?edit=` names it.
	 * @return array<string,mixed>
	 * @throws \LogicException When the page is not in the table.
	 * @throws \InvalidArgumentException Through `Capabilities::can()`, on a broken capability map.
	 */
	public static function page_data( string $page ): array {
		$row = self::pages()[ $page ] ?? null;
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers.
			throw new \LogicException( "no composed page is named {$page}" );
		}
		return [
			'page'         => $page,
			'canTune'      => Capabilities::can( Capabilities::TUNE ),
			'canManage'    => Capabilities::can( Capabilities::MANAGE ),
			'compositions' => Composition_Store::page_data( [ $row['context'] ] ),
		] + ( null === $row['data'] ? [] : ( $row['data'] )() );
	}

	/**
	 * The page an admin slug shows.
	 *
	 * @param string $slug The admin page's slug.
	 * @return string The page.
	 * @throws \LogicException On a slug outside the table.
	 */
	private static function page_of( string $slug ): string {
		foreach ( self::pages() as $page => $row ) {
			if ( $slug === $row['slug'] ) {
				return $page;
			}
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers.
		throw new \LogicException( "no composed page is laid out at {$slug}" );
	}

	/**
	 * Each page, as `?edit=` names it => its row: its admin slug and titles;
	 * the bundle its screen loads, whose `<bundle>-editor` is its layout
	 * editor; its block cap; what its page data adds, or null; and the
	 * context it lays out and that context's default layout.
	 *
	 * The Performance page holds 64 blocks, its default's six with room for a
	 * curator's own; a log page 16, its stream and the notes and figures
	 * around it, because each log-stream block on another source opens a
	 * stream of its own on the page's one link.
	 *
	 * @return array<string,array{slug: string, title: string, menu_title: string, bundle: string, max_blocks: int, data: ?\Closure(): array<string,mixed>, context: string, default: string}>
	 */
	private static function pages(): array {
		$rows  = [
			'performance' => [ 'event-logger-overview', 'Performance Dashboard', 'Performance', 'overview', 64, static fn (): array => [ 'window' => self::window() ] ],
			'request-log' => [ 'event-logger-requests', 'Request Log', 'Request Log', 'log-pages', 16, null ],
			'error-log'   => [ 'event-logger-errors', 'Error Log', 'Errors', 'log-pages', 16, null ],
			'gyroscope'   => [ 'event-logger-gyroscope', 'Gyroscope', 'Gyroscope', 'log-pages', 16, null ],
		];
		$pages = [];
		foreach ( $rows as $page => [ $slug, $title, $menu_title, $bundle, $max_blocks, $data ] ) {
			$pages[ $page ] = [
				'slug'       => $slug,
				'title'      => $title,
				'menu_title' => $menu_title,
				'bundle'     => $bundle,
				'max_blocks' => $max_blocks,
				'data'       => $data,
				'context'    => self::NAMESPACE . "/{$page}",
				'default'    => \dirname( __DIR__, 2 ) . "/layouts/{$page}.html",
			];
		}
		return $pages;
	}

	/**
	 * The stats window in buckets, or its refusal in the words a caller may
	 * read, so the Performance page still loads where `min_lifetime` is
	 * short of one bucket.
	 *
	 * @return int|array{error: string}
	 */
	private static function window(): int|array {
		try {
			return Bucket::window();
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \RuntimeException $refused ) {
			return [ 'error' => Core::caller_message( $refused, 'performance window' ) ];
		}
	}
}
