<?php
/**
 * SettingsCITest: unit tests for Settings_CI, the M2 service-CI that replaces
 * the legacy SettingsController.
 *
 * Asserts value-equivalence with the legacy `update_setting` writer for the
 * four substrate-owned integer keys (num_partitions, num_segments,
 * segment_size, max_lifespan), plus the additive `get` verb that returns
 * the same surface as a snapshot. Substrate config is seeded via
 * `TestCase::use_base_dir()`, mirroring DiscoveryCITest / StatusCITest.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\App\Settings_CI;
use Newspack_Event_Logger_Nodes\Tests\Helpers\VerbHarness;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Settings_CI::class )]
class SettingsCITest extends TestCase {
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		// /tmp directly to dodge symlink-resolved sys_get_temp_dir on macOS,
		// matching DiscoveryCITest / StatusCITest.
		$this->tmp = '/tmp/settings-ci-test-' . \uniqid();
		\mkdir( $this->tmp, 0755, true );
		$this->use_base_dir( $this->tmp );
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = true;
	}

	protected function tearDown(): void {
		VerbHarness::reset();
		$GLOBALS['_wp_options']       = [];
		$GLOBALS['_current_user_can'] = false;
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	public function test_get_verb_returns_current_settings_from_wp_options(): void {
		$GLOBALS['_wp_options']['newspack_nodes_num_partitions'] = 8;
		$GLOBALS['_wp_options']['newspack_nodes_num_segments']   = 4;
		$GLOBALS['_wp_options']['newspack_nodes_segment_size']   = 65536;
		$GLOBALS['_wp_options']['newspack_nodes_max_lifespan']   = 86400;
		\Newspack_Event_Logger_Nodes\Config::reset();

		$ci     = new Settings_CI();
		$result = VerbHarness::fire( $ci, 'settings', 'get' );

		$this->assertIsArray( $result );
		$this->assertSame( 8, $result['num_partitions'] );
		$this->assertSame( 4, $result['num_segments'] );
		$this->assertSame( 65536, $result['segment_size'] );
		$this->assertSame( 86400, $result['max_lifespan'] );
	}

	public function test_get_verb_falls_through_to_defaults_when_options_unset(): void {
		// No WP options set — verb should still return a 4-key shape using
		// whatever the substrate Config defaults supply (driven by the
		// per-test base_dir config file). num_partitions defaults to 1 from
		// the substrate-config-defaults overlay.
		$ci     = new Settings_CI();
		$result = VerbHarness::fire( $ci, 'settings', 'get' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'num_partitions', $result );
		$this->assertArrayHasKey( 'num_segments', $result );
		$this->assertArrayHasKey( 'segment_size', $result );
		$this->assertArrayHasKey( 'max_lifespan', $result );
		$this->assertIsInt( $result['num_partitions'] );
	}

	public function test_update_verb_applies_partial_update_and_returns_snapshot(): void {
		// Seed three of the four; the fourth comes back as its default.
		$GLOBALS['_wp_options']['newspack_nodes_num_segments'] = 2;
		$GLOBALS['_wp_options']['newspack_nodes_segment_size'] = 4096;
		$GLOBALS['_wp_options']['newspack_nodes_max_lifespan'] = 3600;
		\Newspack_Event_Logger_Nodes\Config::reset();

		$ci     = new Settings_CI();
		$result = VerbHarness::fire(
			$ci,
			'settings',
			'update',
			[ 'num_partitions' => 16 ]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 16, $result['num_partitions'] );
		$this->assertSame( 2, $result['num_segments'] );
		$this->assertSame( 4096, $result['segment_size'] );
		$this->assertSame( 3600, $result['max_lifespan'] );
		// Verify the write actually happened against WP options under the
		// substrate prefix (matches legacy SettingsController target key).
		$this->assertSame( 16, $GLOBALS['_wp_options']['newspack_nodes_num_partitions'] );
	}

	public function test_update_verb_writes_all_supplied_keys(): void {
		$ci     = new Settings_CI();
		$result = VerbHarness::fire(
			$ci,
			'settings',
			'update',
			[
				'num_partitions' => 4,
				'num_segments'   => 8,
				'segment_size'   => 32768,
				'max_lifespan'   => 7200,
			]
		);

		$this->assertSame( 4, $result['num_partitions'] );
		$this->assertSame( 8, $result['num_segments'] );
		$this->assertSame( 32768, $result['segment_size'] );
		$this->assertSame( 7200, $result['max_lifespan'] );
		$this->assertSame( 4, $GLOBALS['_wp_options']['newspack_nodes_num_partitions'] );
		$this->assertSame( 8, $GLOBALS['_wp_options']['newspack_nodes_num_segments'] );
		$this->assertSame( 32768, $GLOBALS['_wp_options']['newspack_nodes_segment_size'] );
		$this->assertSame( 7200, $GLOBALS['_wp_options']['newspack_nodes_max_lifespan'] );
	}

	public function test_update_verb_rejects_negative_int(): void {
		// Substrate CI contract: verb throws RuntimeException → interpret()
		// catches it and returns the message string as a TM_COMMAND|TM_ERROR
		// payload. VerbHarness returns the raw string (not valid JSON).
		$ci     = new Settings_CI();
		$result = VerbHarness::fire(
			$ci,
			'settings',
			'update',
			[ 'num_partitions' => -5 ]
		);
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid value', $result );
		$this->assertStringContainsString( 'num_partitions', $result );
		// And the WP option was NOT written (write happens after sanitize).
		$this->assertArrayNotHasKey( 'newspack_nodes_num_partitions', $GLOBALS['_wp_options'] );
	}

	public function test_update_verb_allows_zero_for_max_lifespan(): void {
		// Value-equivalence with legacy: max_lifespan accepts 0 (the
		// only one of the four whose min is 0 rather than 1).
		$ci     = new Settings_CI();
		$result = VerbHarness::fire(
			$ci,
			'settings',
			'update',
			[ 'max_lifespan' => 0 ]
		);
		$this->assertSame( 0, $result['max_lifespan'] );
		$this->assertSame( 0, $GLOBALS['_wp_options']['newspack_nodes_max_lifespan'] );
	}

	public function test_update_verb_rejects_zero_for_min_one_keys(): void {
		// Mirror of the above: num_partitions / num_segments / segment_size
		// all have min=1, so 0 is rejected. Value-equivalence with legacy
		// SettingsController::sanitize_value, which uses the same per-key
		// min override.
		$ci     = new Settings_CI();
		$result = VerbHarness::fire(
			$ci,
			'settings',
			'update',
			[ 'num_partitions' => 0 ]
		);
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid value', $result );
		$this->assertArrayNotHasKey( 'newspack_nodes_num_partitions', $GLOBALS['_wp_options'] );
	}

	public function test_update_verb_rejects_unknown_key(): void {
		$ci     = new Settings_CI();
		$result = VerbHarness::fire(
			$ci,
			'settings',
			'update',
			[ 'not_in_allowlist' => 42 ]
		);
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'unknown setting', $result );
		$this->assertStringContainsString( 'not_in_allowlist', $result );
	}

	public function test_update_verb_rejects_non_numeric_value(): void {
		$ci     = new Settings_CI();
		$result = VerbHarness::fire(
			$ci,
			'settings',
			'update',
			[ 'num_partitions' => 'not-an-int' ]
		);
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'invalid value', $result );
	}

	public function test_update_verb_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$ci     = new Settings_CI();
		$result = VerbHarness::fire(
			$ci,
			'settings',
			'update',
			[ 'num_partitions' => 4 ]
		);
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'permission denied', $result );
		$this->assertArrayNotHasKey( 'newspack_nodes_num_partitions', $GLOBALS['_wp_options'] );
	}

	public function test_update_verb_no_args_returns_current_snapshot(): void {
		// Empty update is a no-op that returns the current snapshot —
		// useful as a poll-after-write-fan-out idempotency check.
		$GLOBALS['_wp_options']['newspack_nodes_num_partitions'] = 2;
		\Newspack_Event_Logger_Nodes\Config::reset();

		$ci     = new Settings_CI();
		$result = VerbHarness::fire(
			$ci,
			'settings',
			'update',
			[]
		);
		$this->assertSame( 2, $result['num_partitions'] );
	}
}
