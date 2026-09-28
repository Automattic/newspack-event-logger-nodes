<?php
declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Composer\Autoload\ClassLoader;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Node;

/**
 * Confirm every Newspack_Event_Logger_Nodes `*_Node` class that DECLARES its
 * own node_schema() ships a non-empty `category`, describes every argument,
 * and answers every request it declares. Scans the composer classmap (the
 * catalog source) scoped to app classes so the substrate's own coverage
 * doesn't bleed in.
 */
class AppNodeSchemaCoverageTest extends TestCase {
	public function test_every_app_node_class_returns_schema_with_category(): void {
		$missing = [];
		foreach ( $this->app_node_schemas() as $shell => $schema ) {
			if ( '' === ( $schema['category'] ?? '' ) ) {
				$missing[ $shell ] = 'schema missing non-empty category';
			}
		}
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
		foreach ( $this->app_node_schemas() as $shell => $schema ) {
			$args = $schema['arguments'] ?? [];
			foreach ( \is_array( $args ) ? $args : [] as $arg ) {
				++$seen_args;
				$name = \is_array( $arg ) ? (string) ( $arg['name'] ?? '?' ) : '?';
				$desc = \is_array( $arg ) ? ( $arg['description'] ?? '' ) : '';
				if ( ! \is_string( $desc ) || '' === \trim( $desc ) ) {
					$missing[] = "{$shell}.{$name}";
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

	public function test_every_declared_request_carries_a_callable_handler(): void {
		// `answer_request()` answers a handlerless entry as an unknown verb, so a
		// request the Inspector offers must be one the node can answer.
		$missing       = [];
		$seen_requests = 0;
		foreach ( $this->app_node_schemas() as $shell => $schema ) {
			$requests = $schema['requests'] ?? [];
			foreach ( \is_array( $requests ) ? $requests : [] as $request ) {
				++$seen_requests;
				if ( ! \is_array( $request ) || ! \is_callable( $request['handler'] ?? null ) ) {
					$missing[] = $shell . '.' . ( \is_array( $request ) ? (string) ( $request['name'] ?? '?' ) : '?' );
				}
			}
		}
		$this->assertGreaterThan( 0, $seen_requests, 'expected to scan at least one declared request' );
		$this->assertSame( [], $missing, 'requests without a callable handler: ' . \print_r( $missing, true ) );
	}

	/**
	 * Every app `*_Node` class declaring its own node_schema(), by shell name.
	 * Inherited Node defaults aren't cataloged, so they are skipped.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function app_node_schemas(): array {
		$schemas = [];
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
				$schemas[ \substr( $short, 0, -\strlen( '_Node' ) ) ] = $fqcn::node_schema();
			}
		}
		$this->assertNotEmpty( $schemas, 'expected to scan at least one app Node class' );
		return $schemas;
	}
}
