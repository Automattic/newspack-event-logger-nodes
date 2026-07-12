<?php
declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Composer\Autoload\ClassLoader;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Node;

/**
 * Confirm every Newspack_Event_Logger_Nodes `*_Node` class that DECLARES its
 * own node_schema() ships a non-empty `category`. Scans the composer classmap
 * (the post-Phase-2 catalog source) scoped to app classes so the substrate's
 * own coverage doesn't bleed in.
 */
class AppNodeSchemaCoverageTest extends TestCase {
	public function test_every_app_node_class_returns_schema_with_category(): void {
		$missing = [];
		$checked = 0;
		foreach ( ClassLoader::getRegisteredLoaders() as $loader ) {
			foreach ( \array_keys( $loader->getClassMap() ) as $fqcn ) {
				if ( ! \str_starts_with( $fqcn, 'Newspack_Event_Logger_Nodes\\' ) ) {
					continue;
				}
				$short = \substr( (string) \strrchr( '\\' . $fqcn, '\\' ), 1 );
				if ( ! \str_ends_with( $short, '_Node' ) || ! \is_subclass_of( $fqcn, Node::class ) ) {
					continue;
				}
				// Only classes that DECLARE their own node_schema() opt into the
				// catalog; inherited Node defaults aren't cataloged.
				$method = new \ReflectionMethod( $fqcn, 'node_schema' );
				if ( Node::class === $method->getDeclaringClass()->getName() ) {
					continue;
				}
				++$checked;
				$shell  = \substr( $short, 0, -\strlen( '_Node' ) );
				$schema = $fqcn::node_schema();
				$cat    = $schema['category'] ?? '';
				if ( ! \is_array( $schema ) || '' === $cat ) {
					$missing[ $shell ] = 'schema missing non-empty category';
				}
			}
		}
		$this->assertGreaterThan( 0, $checked, 'expected to scan at least one app Node class' );
		$this->assertSame(
			[],
			$missing,
			'App classes without node_schema()/category: ' . \print_r( $missing, true )
		);
	}

	public function test_every_node_schema_argument_has_a_description(): void {
		// Every constructor argument surfaces in the topology console (CtorField);
		// a missing description is a blank tooltip. This gate keeps new args honest.
		$missing   = [];
		$seen_args = 0;
		foreach ( ClassLoader::getRegisteredLoaders() as $loader ) {
			foreach ( \array_keys( $loader->getClassMap() ) as $fqcn ) {
				if ( ! \str_starts_with( $fqcn, 'Newspack_Event_Logger_Nodes\\' ) ) {
					continue;
				}
				$short = \substr( (string) \strrchr( '\\' . $fqcn, '\\' ), 1 );
				if ( ! \str_ends_with( $short, '_Node' ) || ! \is_subclass_of( $fqcn, Node::class ) ) {
					continue;
				}
				$method = new \ReflectionMethod( $fqcn, 'node_schema' );
				if ( Node::class === $method->getDeclaringClass()->getName() ) {
					continue;
				}
				$args = $fqcn::node_schema()['arguments'] ?? [];
				if ( ! \is_array( $args ) ) {
					continue;
				}
				$shell = \substr( $short, 0, -\strlen( '_Node' ) );
				foreach ( $args as $arg ) {
					++$seen_args;
					$name = \is_array( $arg ) ? (string) ( $arg['name'] ?? '?' ) : '?';
					$desc = \is_array( $arg ) ? ( $arg['description'] ?? '' ) : '';
					if ( ! \is_string( $desc ) || '' === \trim( $desc ) ) {
						$missing[] = "{$shell}.{$name}";
					}
				}
			}
		}
		$this->assertGreaterThan( 0, $seen_args, 'expected to scan at least one node_schema argument' );
		$this->assertSame(
			[],
			$missing,
			'node_schema arguments missing a description: ' . \print_r( $missing, true )
		);
	}
}
