<?php
declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\CommandInterpreter;

/**
 * Confirm every Newspack_Event_Logger_Nodes class registered in the
 * substrate's class_map ships a non-empty `category` in its
 * `node_schema()`. Mirrors the substrate-side coverage test, but
 * scoped to app-registered classes so the substrate's own coverage
 * doesn't bleed in (and vice versa).
 */
class AppNodeSchemaCoverageTest extends TestCase {
	public function test_every_app_registered_class_returns_schema_with_category(): void {
		$missing = [];
		foreach ( CommandInterpreter::class_map() as $shell_name => $fqcn ) {
			if ( ! \str_starts_with( $fqcn, 'Newspack_Event_Logger_Nodes\\' ) ) {
				continue;
			}
			if ( ! \method_exists( $fqcn, 'node_schema' ) ) {
				$missing[ $shell_name ] = 'no node_schema() method';
				continue;
			}
			$schema = $fqcn::node_schema();
			$cat    = $schema['category'] ?? '';
			if ( ! \is_array( $schema ) || '' === $cat ) {
				$missing[ $shell_name ] = 'schema missing non-empty category';
			}
		}
		$this->assertSame(
			[],
			$missing,
			'App classes without node_schema()/category: ' . \print_r( $missing, true )
		);
	}
}
