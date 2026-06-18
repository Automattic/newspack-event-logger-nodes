<?php
/**
 * Dead-code-detector bootstrap: make the newspack-nodes substrate's classes
 * real-loadable during analysis.
 *
 * The base `lint:phpstan` resolves substrate symbols via `scanDirectories`
 * (phpstan's own reflection). That is NOT enough for `lint:deadcode`:
 * shipmonk/dead-code-detector's reflection-based usage providers call Nette
 * DI `findByType()`, which triggers the REAL composer autoloader to load each
 * ELN node class — and loading e.g. `Remote_Source_Node` needs its parent
 * `\Newspack_Nodes\Node`, which ELN's own autoloader can't find (the substrate
 * is a sibling plugin loaded at runtime by WordPress, not via composer here).
 *
 * Register a minimal autoloader for the substrate's OWN classes only, sourced
 * from its composer classmap. Scoped to the `Newspack_Nodes\` prefix so this
 * pulls in zero substrate dev-dependencies (no second phpstan, etc.).
 *
 * @package Newspack_Event_Logger_Nodes
 */

$newspack_nodes_classmap = __DIR__ . '/../../newspack-nodes/vendor/composer/autoload_classmap.php';

if ( \is_file( $newspack_nodes_classmap ) ) {
	/** @var array<string,string> $classmap */
	$classmap = require $newspack_nodes_classmap;
	\spl_autoload_register(
		static function ( string $class ) use ( $classmap ): void {
			if ( \str_starts_with( $class, 'Newspack_Nodes\\' ) && isset( $classmap[ $class ] ) ) {
				require_once $classmap[ $class ];
			}
		}
	);
}
