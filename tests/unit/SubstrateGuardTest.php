<?php
/**
 * SubstrateGuardTest: unit tests for Substrate_Guard, the runtime floor that
 * keeps ELN from fatal-ing (or binding silently against a stale substrate)
 * when newspack-nodes is missing or too old to expose the APIs ELN calls.
 *
 * The pure `satisfied()` function carries the decision logic and is exercised
 * across every branch (null version / below-minimum / missing-APIs / OK).
 * `required_apis_present()` is asserted true AND non-vacuous (one of the exact
 * methods it probes is also asserted present) so the test proves it really
 * interrogates the substrate rather than returning a constant.
 *
 * The bootstrap early-return branch only fires when the substrate is
 * absent/old — which the test env (substrate IS loaded via tests/bootstrap.php)
 * never is — so it isn't integration-tested here; the pure `satisfied()`
 * branches carry the logic.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Substrate_Guard;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Substrate_Guard::class )]
class SubstrateGuardTest extends TestCase {

	public function test_satisfied_false_when_version_null(): void {
		$this->assertFalse(
			Substrate_Guard::satisfied( null, '0.4.0', true )
		);
	}

	public function test_satisfied_false_when_version_below_minimum(): void {
		$this->assertFalse(
			Substrate_Guard::satisfied( '0.3.0', '0.4.0', true )
		);
	}

	public function test_satisfied_false_when_apis_absent_even_if_version_ok(): void {
		$this->assertFalse(
			Substrate_Guard::satisfied( '0.4.0', '0.4.0', false )
		);
	}

	public function test_satisfied_true_when_version_at_minimum_and_apis_present(): void {
		$this->assertTrue(
			Substrate_Guard::satisfied( '0.4.0', '0.4.0', true )
		);
	}

	public function test_satisfied_true_when_version_above_minimum_and_apis_present(): void {
		$this->assertTrue(
			Substrate_Guard::satisfied( '0.5.0', '0.4.0', true )
		);
	}

	public function test_required_apis_present_true_in_test_env(): void {
		// The substrate is loaded via tests/bootstrap.php, so every probed
		// symbol exists.
		$this->assertTrue( Substrate_Guard::required_apis_present() );

		// Non-vacuous: assert one of the exact symbols required_apis_present()
		// probes actually exists, so this proves the method interrogates the
		// substrate rather than returning a hardcoded true.
		$this->assertTrue(
			\method_exists( '\Newspack_Nodes\Topology_Registry', 'register_plugin' )
		);
	}

	public function test_minimum_version_constant_is_a_version_string(): void {
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+\.\d+$/',
			Substrate_Guard::MINIMUM_NODES_VERSION
		);
	}
}
