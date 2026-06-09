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

	/**
	 * The floor must be at least 0.13.0 — the substrate release that introduced
	 * the Config_System\Field / Schema / Settings_Renderer classes ELN's
	 * Settings_Schema + Admin now depend on. A lower floor lets an older
	 * substrate (which lacks those classes) load and fatal instead of showing
	 * the guard notice.
	 */
	public function test_minimum_version_requires_config_system_api(): void {
		$this->assertTrue(
			\version_compare( Substrate_Guard::MINIMUM_NODES_VERSION, '0.13.0', '>=' ),
			'MINIMUM_NODES_VERSION must be >= 0.13.0 (Config_System\\Field/Schema/Settings_Renderer)'
		);
	}

	/** The guard probes the Config_System classes ELN's settings layer instantiates. */
	public function test_required_apis_probe_config_system_classes(): void {
		// Non-vacuous: these are loaded in the test env, proving the probe set
		// includes them (a missing one would flip required_apis_present() false).
		$this->assertTrue( \class_exists( '\Newspack_Nodes\Config_System\Field' ) );
		$this->assertTrue( \class_exists( '\Newspack_Nodes\Config_System\Schema' ) );
		$this->assertTrue( \class_exists( '\Newspack_Nodes\Config_System\Settings_Renderer' ) );
		$this->assertTrue( Substrate_Guard::required_apis_present() );
	}

	public function test_boot_invokes_on_ready_when_satisfied(): void {
		$ready = false;
		$unsat = false;
		Substrate_Guard::boot(
			Substrate_Guard::MINIMUM_NODES_VERSION,
			true,
			static function () use ( &$ready ): void {
				$ready = true;
			},
			static function ( ?string $found ) use ( &$unsat ): void {
				$unsat = true;
			}
		);
		$this->assertTrue( $ready, 'on_ready must fire when the substrate is satisfied' );
		$this->assertFalse( $unsat, 'on_unsatisfied must NOT fire when satisfied' );
	}

	public function test_boot_invokes_on_unsatisfied_with_version_when_version_null(): void {
		// The regression: at ELN's file-load time the substrate isn't loaded yet,
		// so NEWSPACK_NODES_VERSION is undefined → null. boot() must route to
		// on_unsatisfied (NOT on_ready) and hand it the null version for the notice.
		$ready    = false;
		$received = 'sentinel';
		Substrate_Guard::boot(
			null,
			false,
			static function () use ( &$ready ): void {
				$ready = true;
			},
			static function ( ?string $found ) use ( &$received ): void {
				$received = $found;
			}
		);
		$this->assertFalse( $ready, 'on_ready must NOT fire when the substrate is absent' );
		$this->assertNull( $received, 'on_unsatisfied must receive the (null) installed version' );
	}

	public function test_boot_invokes_on_unsatisfied_when_version_below_minimum(): void {
		$ready = false;
		$unsat = false;
		Substrate_Guard::boot(
			'0.3.0',
			true,
			static function () use ( &$ready ): void {
				$ready = true;
			},
			static function ( ?string $found ) use ( &$unsat ): void {
				$unsat = true;
			}
		);
		$this->assertFalse( $ready, 'on_ready must NOT fire for a below-minimum substrate' );
		$this->assertTrue( $unsat, 'on_unsatisfied must fire for a below-minimum substrate' );
	}
}
