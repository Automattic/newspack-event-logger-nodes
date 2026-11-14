<?php
/**
 * Compositions: this plugin's one declaration on the substrate's composition
 * layer.
 *
 * `declare()`, on `Composition::FILTER`, declares the plugin's namespace
 * whole: the blocks the Performance page owns, and every composed page's
 * contexts, each page class supplying its own as data and every context
 * admitting the substrate's shared blocks.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Admin;

\defined( 'ABSPATH' ) || exit;

/**
 * The plugin's composition namespace and its one declaration.
 */
final class Compositions {

	/** The composition namespace this plugin declares under. */
	public const NAMESPACE = 'newspack-event-logger-nodes';

	/** The shared blocks every page of this plugin admits. */
	private const ADMITS = [
		'newspack-nodes/stats',
		'newspack-nodes/time-chart',
		'newspack-nodes/table',
		'newspack-nodes/log-stream',
		'newspack-nodes/sparkline',
		'newspack-nodes/section',
	];

	/**
	 * Declare the namespace on `Composition::FILTER`: the Performance page's
	 * blocks, and the Performance and log pages' contexts.
	 *
	 * @param array<mixed> $declared What the filter holds so far.
	 * @return array<mixed>
	 */
	public static function declare( array $declared ): array {
		$contexts = [];
		foreach ( Performance_Page::contexts() + Log_Pages::contexts() as $name => $context ) {
			$contexts[ $name ] = $context + [ 'admits' => self::ADMITS ];
		}
		$declared[ self::NAMESPACE ] = [
			'blocks'   => Performance_Page::blocks(),
			'contexts' => $contexts,
		];
		return $declared;
	}
}
