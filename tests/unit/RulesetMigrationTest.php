<?php
/**
 * Tests for the one-time legacy-option -> Rule_Set migration.
 *
 * @package Newspack_Event_Logger_Nodes\Tests\Unit
 */

declare( strict_types=1 );

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Log_Manager;
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

		Rule_Set::migrate_from_legacy();

		$this->assertSame( Rule_Set::SCHEMA_VERSION, (int) \get_option( Rule_Set::OPTION_SCHEMA_VERSION, 0 ), 'migration ran + stamped the version' );
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

	public function test_rekey_collapse_skip_wins_even_when_the_log_rule_is_stored_first(): void {
		// Log FIRST, skip LAST — the order that drives the replace branch
		// ($existing is a log rule, $candidate is skip). Skip must still win, so
		// precedence is order-independent (not just first-insert-wins).
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [
			[ 'id' => 'aaaaaaaa', 'pattern' => '/x', 'action' => 'log' ],
			[ 'id' => 'bbbbbbbb', 'pattern' => '/x', 'action' => 'skip' ],
		];
		\update_option( Rule_Set::OPTION_SCHEMA_VERSION, 1, true );

		Rule_Set::migrate_from_legacy();

		$rules = Rule_Set::load()->rules();
		$this->assertCount( 1, $rules );
		$this->assertTrue( $rules[0]->is_skip(), 'skip wins even when stored after the log rule' );
	}

	public function test_fresh_install_with_no_legacy_options_leaves_the_rules_option_absent(): void {
		// Nothing to migrate: the config seed owns the ruleset, so migration must
		// not fabricate + persist a '/' rule that would shadow the config baseline.
		Rule_Set::migrate_from_legacy();

		$this->assertArrayNotHasKey( Rule_Set::OPTION_RULES, $GLOBALS['_wp_options'], 'a no-op migration must not write the rules option' );
		$this->assertSame( 2, (int) \get_option( Rule_Set::OPTION_SCHEMA_VERSION, 0 ), 'the version still advances so it runs once' );
	}

	public function test_rekey_migration_normalizes_a_positional_id_and_preserves_pointer_hooks(): void {
		// A v1 install: a heavy rule stored under an OLD positional id, its hooks in
		// a durable option keyed by that id. The v1->v2 rekey must move it to the
		// pattern-hash id without losing the hooks.
		$big     = \array_map( static fn ( $i ) => "hook_$i", \range( 1, Rule_Set::INLINE_HOOK_LIMIT + 1 ) );
		$old_id  = 'faed26b5';
		$GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( $old_id ) ] = $big;
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ]                 = [
			[ 'id' => $old_id, 'pattern' => '/shop/', 'action' => 'log', 'hooks' => null, 'hooks_in' => 'mc' ],
		];
		\update_option( Rule_Set::OPTION_SCHEMA_VERSION, 1, true );

		Rule_Set::migrate_from_legacy();

		$new_id = Log_Manager::url_hash( '/shop/' );
		$rules  = Rule_Set::load()->rules();
		$this->assertCount( 1, $rules );
		$this->assertSame( $new_id, $rules[0]->id, 'positional id rekeyed to the pattern hash' );
		$this->assertSame( $big, Rule_Set::hooks_for( $rules[0] ), 'pointer hooks survive the rekey' );
		$this->assertArrayNotHasKey( Rule_Set::hooks_option_name( $old_id ), $GLOBALS['_wp_options'], 'old durable option reconciled away' );
		$this->assertSame( $big, $GLOBALS['_wp_options'][ Rule_Set::hooks_option_name( $new_id ) ] );
	}

	public function test_rekey_migration_is_idempotent_and_stamps_the_current_version(): void {
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [
			[ 'id' => 'a4c8c1c1', 'pattern' => '/', 'action' => 'log' ],
		];
		\update_option( Rule_Set::OPTION_SCHEMA_VERSION, 1, true );

		Rule_Set::migrate_from_legacy();
		$this->assertSame( Rule_Set::SCHEMA_VERSION, (int) \get_option( Rule_Set::OPTION_SCHEMA_VERSION, 0 ), 'first run stamps the version' );
		$after_first = $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ];

		Rule_Set::migrate_from_legacy();
		$this->assertSame( $after_first, $GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ], 'a second run is a no-op' );
		$this->assertSame( Log_Manager::url_hash( '/' ), Rule_Set::load()->rules()[0]->id );
	}

	public function test_rekey_collapses_same_pattern_collision_with_skip_precedence(): void {
		// A v1 install that stored two rules for the same pattern (different
		// positional ids) — skip first, log LAST. The rekey collapses them to one
		// id; skip must win regardless of stored order, matching migrate_legacy_options.
		$GLOBALS['_wp_options'][ Rule_Set::OPTION_RULES ] = [
			[ 'id' => 'aaaaaaaa', 'pattern' => '/x', 'action' => 'skip' ],
			[ 'id' => 'bbbbbbbb', 'pattern' => '/x', 'action' => 'log' ],
		];
		\update_option( Rule_Set::OPTION_SCHEMA_VERSION, 1, true );

		Rule_Set::migrate_from_legacy();

		$rules = Rule_Set::load()->rules();
		$this->assertCount( 1, $rules );
		$this->assertTrue( $rules[0]->is_skip(), 'skip wins the collapse even when the log rule is stored last' );
	}

	public function test_migration_deletes_legacy_options_and_is_idempotent(): void {
		$this->seed_legacy( [ 'log_urls' => [], 'log_events' => [ 'wp' ] ] );

		Rule_Set::migrate_from_legacy();
		$this->assertSame( Rule_Set::SCHEMA_VERSION, (int) \get_option( Rule_Set::OPTION_SCHEMA_VERSION, 0 ), 'migration ran + stamped the version' );
		$this->assertOptionAbsent( 'newspack_event_logger_nodes_log_events' );

		// A re-seeded legacy option must survive a second run — the version gate
		// makes it a no-op, so a re-consumed option would prove the gate broke.
		$GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'] = [ 'init' ];
		Rule_Set::migrate_from_legacy();
		$this->assertSame( [ 'init' ], $GLOBALS['_wp_options']['newspack_event_logger_nodes_log_events'], 'a second run does not re-migrate' );
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
