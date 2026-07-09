<?php
/**
 * Tests for the one-time legacy-option -> Rule_Set migration.
 *
 * @package Newspack_Event_Logger_Nodes\Tests\Unit
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Rule;
use Newspack_Event_Logger_Nodes\Rule_Set;
use Newspack_Nodes\Core;
use PHPUnit\Framework\TestCase;

final class RulesetMigrationTest extends TestCase {

	/** Minimal wpdb double: get_col resolves a prefix LIKE against _wp_options (mirrors RuleSetTest). */
	private function wpdb_double(): object {
		return new class() {
			public string $options = 'wp_options';
			public function esc_like( string $text ): string {
				return $text;
			}
			public function get_col( string $sql ): array {
				\preg_match( "/LIKE '([^']+)%'/", $sql, $m );
				$prefix = $m[1] ?? '';
				return \array_values( \array_filter(
					\array_keys( $GLOBALS['_wp_options'] ),
					static fn ( string $name ): bool => \str_starts_with( $name, $prefix )
				) );
			}
		};
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options'] = [];
		global $wpdb;
		$wpdb       = $this->wpdb_double();
		Core::$memd = null;
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb       = null;
		Core::$memd = null;
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function seed_legacy( array $overrides ): void {
		$defaults = [
			'log_urls'                    => [],
			'skip_urls'                   => [],
			'log_events'                  => [],
			'custom_events'               => [],
			'significant_events'          => [],
			'auto_disable_threshold'      => 0,
			'auto_protect_time_threshold' => 0.0,
		];
		foreach ( \array_merge( $defaults, $overrides ) as $short => $value ) {
			$GLOBALS['_wp_options'][ 'newspack_event_logger_nodes_' . $short ] = $value;
		}
	}

	/**
	 * @param Rule[] $rules
	 */
	private function rule_with_pattern( array $rules, string $pattern ): ?Rule {
		foreach ( $rules as $rule ) {
			if ( $pattern === $rule->pattern ) {
				return $rule;
			}
		}
		return null;
	}

	private function assertOptionAbsent( string $name ): void {
		$this->assertArrayNotHasKey( $name, $GLOBALS['_wp_options'] );
	}

	public function test_empty_log_urls_yields_a_root_log_rule_with_the_global_bundle(): void {
		$this->seed_legacy( [
			'log_urls'                    => [],
			'skip_urls'                   => [ '/wp-cron' ],
			'log_events'                  => [ 'init', 'wp' ],
			'custom_events'               => [ 'cache_hit' ],
			'significant_events'          => [ 'template_redirect' ],
			'auto_disable_threshold'      => 5000,
			'auto_protect_time_threshold' => 2.0,
		] );

		$result = Rule_Set::migrate_from_legacy();

		$this->assertTrue( $result['migrated'] );
		$rules = Rule_Set::load()->rules();
		$root  = $this->rule_with_pattern( $rules, '/' );
		$this->assertNotNull( $root );
		$this->assertTrue( $root->is_log() );
		$this->assertSame( [ 'init', 'wp' ], $root->hooks );
		$this->assertSame( [ 'cache_hit' ], $root->custom_events );
		$this->assertSame( [ 'template_redirect' ], $root->significant_events );
		$this->assertSame( 5000, $root->auto_disable_threshold );
		$this->assertSame( 2.0, $root->auto_protect_time_threshold );
		$skip = $this->rule_with_pattern( $rules, '/wp-cron' );
		$this->assertNotNull( $skip );
		$this->assertTrue( $skip->is_skip() );
	}

	public function test_non_empty_log_urls_yields_per_pattern_rules_and_no_root(): void {
		$this->seed_legacy( [ 'log_urls' => [ '/shop/', '/blog/' ], 'log_events' => [ 'wp' ] ] );

		Rule_Set::migrate_from_legacy();

		$rules = Rule_Set::load()->rules();
		$this->assertNotNull( $this->rule_with_pattern( $rules, '/shop/' ) );
		$this->assertNotNull( $this->rule_with_pattern( $rules, '/blog/' ) );
		$this->assertNull( $this->rule_with_pattern( $rules, '/' ) );
	}

	public function test_same_pattern_in_skip_and_log_collapses_to_one_skip_rule(): void {
		// Since a rule id is now id_for(pattern), a URL in BOTH lists would mint two
		// rules with the same id. Dedupe with skip precedence (the old flat
		// skip-wins semantics) instead of persisting a colliding pair.
		$this->seed_legacy( [ 'skip_urls' => [ '/x' ], 'log_urls' => [ '/x' ], 'log_events' => [] ] );

		Rule_Set::migrate_from_legacy();

		$rules = Rule_Set::load()->rules();
		$this->assertCount( 1, $rules );
		$this->assertSame( '/x', $rules[0]->pattern );
		$this->assertTrue( $rules[0]->is_skip(), 'skip wins for a contradictory same-pattern config' );
	}

	public function test_migration_deletes_legacy_options_and_is_idempotent(): void {
		$this->seed_legacy( [ 'log_urls' => [], 'log_events' => [ 'wp' ] ] );

		$this->assertTrue( Rule_Set::migrate_from_legacy()['migrated'] );
		$this->assertOptionAbsent( 'newspack_event_logger_nodes_log_events' );
		$this->assertFalse( Rule_Set::migrate_from_legacy()['migrated'] );
	}

	public function test_prefix_overlap_is_reported(): void {
		$this->seed_legacy( [ 'skip_urls' => [ '/wp' ], 'log_urls' => [ '/wp-admin' ], 'log_events' => [] ] );

		$this->assertTrue( Rule_Set::migrate_from_legacy()['overlap'] );
	}

	public function test_disjoint_lists_report_no_overlap(): void {
		$this->seed_legacy( [ 'skip_urls' => [ '/wp-cron' ], 'log_urls' => [ '/shop/' ], 'log_events' => [] ] );

		$this->assertFalse( Rule_Set::migrate_from_legacy()['overlap'] );
	}

	public function test_hooks_over_inline_limit_migrate_to_a_durable_pointer_rule(): void {
		$big = \array_map( static fn ( int $i ): string => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$this->seed_legacy( [ 'log_urls' => [], 'log_events' => $big ] );

		Rule_Set::migrate_from_legacy();

		$rules = Rule_Set::load()->rules();
		$root  = $this->rule_with_pattern( $rules, '/' );
		$this->assertNotNull( $root );
		$this->assertSame( Rule::HOOKS_MC, $root->hooks_in );
		$this->assertSame( $big, $GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( $root->id ) ] );
	}
}
