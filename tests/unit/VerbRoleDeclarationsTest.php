<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\App\Discovery_CI_Node;
use Newspack_Event_Logger_Nodes\App\Performance_CI_Node;
use Newspack_Event_Logger_Nodes\App\Rules_CI_Node;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Service_CI_Node;

/**
 * Every application verb states its role.
 *
 * `Service_CI_Node` defaults an undeclared verb to MANAGE, so this whole
 * surface was manage BY OMISSION — which is what forced the hub to hold an
 * administrator's application password on every spoke just to pull logs and
 * push settings. The ruleset is `tune`: the payoff loop is diagnose, then
 * narrow the rule, and an agent that could restart the fleet to do it would be
 * holding far more than that needs.
 */
#[CoversClass( Performance_CI_Node::class )]
#[CoversClass( Rules_CI_Node::class )]
#[CoversClass( Discovery_CI_Node::class )]
class VerbRoleDeclarationsTest extends TestCase {

	/** @return array<string,array{0:class-string<Service_CI_Node>,1:array<string,string>}> */
	public static function role_map(): array {
		$read = Capabilities::READ;
		$tune = Capabilities::TUNE;
		return [
			'performance' => [
				Performance_CI_Node::class,
				[
					'overview'         => $read,
					'urls'             => $read,
					'url_detail'       => $read,
					'request_search'   => $read,
					'request_grep'     => $read,
					'request_detail'   => $read,
					'hooks_registered' => $read,
					'set'              => $tune,
				],
			],
			'rules'       => [
				Rules_CI_Node::class,
				[
					'list'   => $read,
					'save'   => $tune,
					'upsert' => $tune,
					'delete' => $tune,
					'reset'  => $tune,
				],
			],
			'discovery'   => [ Discovery_CI_Node::class, [ 'get' => $read ] ],
		];
	}

	/**
	 * @param class-string<Service_CI_Node> $class
	 * @param array<string,string>          $expected
	 */
	#[DataProvider( 'role_map' )]
	public function test_each_verb_declares_its_role( string $class, array $expected ): void {
		$seen = [];
		foreach ( $class::node_schema()['commands'] as $verb ) {
			$seen[ $verb['name'] ] = $verb['capability'] ?? Capabilities::MANAGE;
		}
		foreach ( $expected as $name => $role ) {
			$this->assertArrayHasKey( $name, $seen, "{$class} lost its `{$name}` verb" );
			$this->assertSame( $role, $seen[ $name ], "{$class}::{$name} must be `{$role}`" );
		}
	}

	/**
	 * The declaration has to BITE. Every performance handler opened with its own
	 * `require_manage_options()`, so declaring `read` alone would have changed
	 * nothing at all.
	 */
	public function test_no_handler_re_gates_itself_at_manage(): void {
		$source = \file_get_contents( \dirname( __DIR__, 2 ) . '/includes/app/class-performance-ci-node.php' );

		$this->assertStringNotContainsString(
			'require_manage_options()',
			(string) $source,
			'a hard-coded manage gate silently overrides the declared role'
		);
	}

	/** A read-only caller reaches `overview`, and cannot write the ruleset. */
	public function test_a_read_only_caller_reaches_overview_and_not_the_ruleset(): void {
		\add_filter(
			'newspack_nodes/capability_map',
			static fn ( array $map ): array => [ 'read' => 'edit_pages' ] + $map
		);
		$GLOBALS['_wp_test_current_user_can'] = [ 'edit_pages' => true, 'manage_options' => false ];

		$performance = new Performance_CI_Node();
		$performance->name( 'performance' );
		$payload = $performance->commands()['overview']( $performance, [], [] );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'total_requests', $payload );

		$rules = new Rules_CI_Node();
		$rules->name( 'rules' );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/permission denied/' );
		$rules->commands()['reset']( $rules, [], [] );
	}

	protected function tearDown(): void {
		$GLOBALS['_wp_test_current_user_can'] = [];
		$GLOBALS['_wp_actions']               = [];
		parent::tearDown();
	}
}
