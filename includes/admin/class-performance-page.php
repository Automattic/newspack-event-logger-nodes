<?php
/**
 * Performance_Page: the Performance dashboard as a composed page on the
 * substrate's composition layer.
 *
 * The page lays out `newspack-event-logger-nodes/performance`, whose default
 * layout is `layouts/performance.html` and which admits every shared block,
 * beside the five blocks this plugin owns for what no shared block shows: the
 * breakdown bar, the aggregate and category charts, the profile with its
 * owners, and the URL table. `declare()` declares them on
 * `Composition::FILTER`. A Performance load sets `page_data()` before the
 * page's bundle, as an inline script of its own, and loads the layout editor
 * on a load whose `?edit=` names the page, for a `tune` caller alone.
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
 * The Performance page's context, its blocks and its page data.
 */
final class Performance_Page {

	/** The global a Performance load sets to `page_data()`. */
	public const GLOBAL = 'NewspackEventLoggerPerformancePage';

	/** The layout editor's script handle. */
	public const EDITOR = 'newspack-event-logger-nodes-overview-editor';

	/** The composition namespace this plugin declares under. */
	private const NAMESPACE = 'newspack-event-logger-nodes';

	/** The page slug `?edit=` names => the context it lays out. */
	private const PAGES = [
		'performance' => 'newspack-event-logger-nodes/performance',
	];

	/**
	 * The most blocks the layout holds: the default's six, room to spare for
	 * a curator's own, each block reading a source the page shares with every
	 * block on that source.
	 */
	private const MAX_BLOCKS = 64;

	/** The shared blocks the page admits. */
	private const ADMITS = [
		'newspack-nodes/stats',
		'newspack-nodes/time-chart',
		'newspack-nodes/table',
		'newspack-nodes/log-stream',
		'newspack-nodes/sparkline',
		'newspack-nodes/section',
	];

	/** Each block this plugin owns => its title; each stands once. */
	private const BLOCKS = [
		'breakdown-bar'   => 'Breakdown bar',
		'aggregate-chart' => 'Aggregate chart',
		'category-chart'  => 'Category charts',
		'profile'         => 'Time breakdown',
		'url-table'       => 'URL table',
	];

	/**
	 * Set the page data ahead of the page's bundle `$handle`, escaped for a
	 * script element, then load the layout editor when `?edit=` names the
	 * page, for a `tune` caller alone.
	 *
	 * @param string $handle    The page bundle's script handle.
	 * @param string $build_dir The layout editor's build directory.
	 * @param string $build_url Its URL.
	 * @throws \LogicException Through `Composition`, on a declaration that breaks a rule.
	 * @throws \RuntimeException When the page data will not encode.
	 * @throws \InvalidArgumentException Through `Capabilities::can()`, on a broken capability map.
	 */
	public static function enqueue( string $handle, string $build_dir, string $build_url ): void {
		$json = \wp_json_encode( self::page_data(), \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new \RuntimeException( 'the Performance page data will not encode as JSON' );
		}
		\wp_add_inline_script( $handle, 'window.' . self::GLOBAL . " = {$json};", 'before' );
		Composition::enqueue_editor( self::PAGES, self::EDITOR, $build_dir, $build_url );
	}

	/**
	 * What the page reads: whether the caller may curate its layout and read
	 * a refusal's own words, the context's layout or its refusal, and the
	 * stats window in buckets, which the headline's caption names, or
	 * `{ error }` where `min_lifetime` is short of one bucket, so the page
	 * still loads.
	 *
	 * @return array{canTune: bool, canManage: bool, compositions: array<string,mixed>, window: int|array{error: string}}
	 * @throws \LogicException When the context is not declared.
	 * @throws \InvalidArgumentException Through `Capabilities::can()`, on a broken capability map.
	 */
	public static function page_data(): array {
		return [
			'canTune'      => Capabilities::can( Capabilities::TUNE ),
			'canManage'    => Capabilities::can( Capabilities::MANAGE ),
			'compositions' => Composition_Store::page_data( \array_values( self::PAGES ) ),
			'window'       => self::window(),
		];
	}

	/**
	 * The stats window in buckets, or its refusal in the words a caller may
	 * read.
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

	/**
	 * Declare the page's context and blocks on `Composition::FILTER`.
	 *
	 * @param array<mixed> $declared What the filter holds so far.
	 * @return array<mixed>
	 */
	public static function declare( array $declared ): array {
		$context  = self::PAGES['performance'];
		$blocks   = [];
		foreach ( self::BLOCKS as $name => $title ) {
			$blocks[ self::NAMESPACE . "/{$name}" ] = [
				'title'      => $title,
				'contexts'   => [ $context ],
				'attributes' => [],
				'multiple'   => false,
			];
		}
		$declared[ self::NAMESPACE ] = [
			'blocks'   => $blocks,
			'contexts' => [
				$context => [
					'default'    => \dirname( __DIR__, 2 ) . '/layouts/performance.html',
					'max_blocks' => self::MAX_BLOCKS,
					'admits'     => self::ADMITS,
				],
			],
		];
		return $declared;
	}
}
