<?php
/**
 * Smoke test for the one-release Job_Intake BC alias.
 *
 * Job_Intake moved to the substrate (`Newspack_Nodes\Job_Intake`). The deferred
 * loader keeps the old FQN alive as a class_alias for one release so out-of-tree
 * callers survive the rename. This pins that the old name still resolves — to the
 * exact same class.
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Tests\TestCase;

class JobIntakeAliasTest extends TestCase {

	public function test_old_fqn_still_resolves(): void {
		$this->assertTrue(
			\class_exists( 'Newspack_Event_Logger_Nodes\\Job_Intake' ),
			'the one-release BC alias must keep the old FQN loadable'
		);
	}

	public function test_old_fqn_is_the_substrate_class(): void {
		$reflection = new \ReflectionClass( 'Newspack_Event_Logger_Nodes\\Job_Intake' );
		$this->assertSame( \Newspack_Nodes\Job_Intake::class, $reflection->getName() );
	}
}
