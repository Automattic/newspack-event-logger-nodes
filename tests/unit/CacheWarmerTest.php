<?php
/**
 * Tests for Newspack_Cache_Warmer\Cache_Warmer (the standalone drop-in).
 *
 * The refresh-ahead warmer: keeps the homepage's caches hot out-of-band so no
 * visitor pays the cold render. Covers the host gate, cold groups, secret,
 * the drop-in-load cold-cache install, single-flight, and stats exclusion.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Cache_Warmer\Cache_Warmer;
use Newspack_Cache_Warmer\Cold_Read_Object_Cache;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( Cache_Warmer::class )]
class CacheWarmerTest extends TestCase {

	private mixed $saved_object_cache = null;

	protected function setUp(): void {
		parent::setUp();
		$this->saved_object_cache = $GLOBALS['wp_object_cache'] ?? null;
		$GLOBALS['_wp_test_remote_gets'] = [];
		$GLOBALS['_wp_transients']       = [];
		$_GET = [];
		unset(
			$GLOBALS['_wp_options']['eln_cache_warmer_secret'],
			$GLOBALS['_wp_test_home_url'],
			$_SERVER['NEWSPACK_NODES_WORKER_TYPE']
		);
	}

	protected function tearDown(): void {
		// Restore every global these tests touch so nothing bleeds into later
		// suites (e.g. $_SERVER worker-type leaking into RequestBuilderTest).
		unset(
			$GLOBALS['_wp_actions']['eln_cache_warmer_cold_groups'],
			$GLOBALS['_wp_actions']['password_protected_is_active'],
			$_SERVER['NEWSPACK_NODES_WORKER_TYPE']
		);
		$GLOBALS['wp_object_cache'] = $this->saved_object_cache;
		$GLOBALS['_wp_transients']  = [];
		$_GET                       = [];
		parent::tearDown();
	}

	/** Minimal array-backed WP_Object_Cache double (group-namespaced get/set). */
	private function fake_object_cache(): object {
		return new class() {
			public array $store = [];
			public function get( $key, $group = '', $force = false, &$found = null ) {
				$found = isset( $this->store[ $group ][ $key ] );
				return $found ? $this->store[ $group ][ $key ] : false;
			}
			public function set( $key, $data, $group = '', $expire = 0 ) {
				$this->store[ $group ][ $key ] = $data;
				return true;
			}
		};
	}

	// ── register() — drop-in bootstrap ──────────────────────────────────────

	public function test_register_hooks_the_cron_handler(): void {
		// The event is scheduled manually (`wp cron event schedule`); register()
		// must hook run_tick so the scheduled/`wp cron event run` tick is runnable.
		$saved                  = $GLOBALS['_wp_actions'] ?? [];
		$GLOBALS['_wp_actions'] = [];
		try {
			Cache_Warmer::register();
			$this->assertNotEmpty( $GLOBALS['_wp_actions'][ Cache_Warmer::CRON_HOOK ] ?? [] );
		} finally {
			$GLOBALS['_wp_actions'] = $saved;
		}
	}

	// ── Self-owned cron recurrence (so scheduling never depends on another plugin) ──

	public function test_register_cron_schedule_adds_a_minute_interval(): void {
		$schedules = Cache_Warmer::register_cron_schedule( [] );

		$this->assertArrayHasKey( Cache_Warmer::CRON_SCHEDULE, $schedules );
		$this->assertSame( 60, $schedules[ Cache_Warmer::CRON_SCHEDULE ]['interval'] );
	}

	public function test_register_cron_schedule_preserves_existing_schedules(): void {
		$existing  = [ 'hourly' => [ 'interval' => 3600, 'display' => 'Once Hourly' ] ];
		$schedules = Cache_Warmer::register_cron_schedule( $existing );

		$this->assertSame( $existing['hourly'], $schedules['hourly'] );
		$this->assertArrayHasKey( Cache_Warmer::CRON_SCHEDULE, $schedules );
	}

	public function test_register_wires_the_cron_schedule_filter(): void {
		// register() must add the cron_schedules filter so the recurrence is
		// available to `wp cron event schedule` without newspack-nodes loaded.
		$saved                  = $GLOBALS['_wp_actions'] ?? [];
		$GLOBALS['_wp_actions'] = [];
		try {
			Cache_Warmer::register();
			$schedules = apply_filters( 'cron_schedules', [] );
			$this->assertArrayHasKey( Cache_Warmer::CRON_SCHEDULE, $schedules );
		} finally {
			$GLOBALS['_wp_actions'] = $saved;
		}
	}

	// ── Cold-group allowlist ────────────────────────────────────────────────

	public function test_default_cold_groups_cover_block_cache_and_transients(): void {
		$groups = Cache_Warmer::cold_groups();

		$this->assertContains( 'newspack_blocks', $groups );
		$this->assertContains( 'transient', $groups );
		$this->assertContains( 'site-transient', $groups );
	}

	public function test_cold_groups_are_filterable(): void {
		add_filter(
			'eln_cache_warmer_cold_groups',
			static fn ( array $groups ): array => array_merge( $groups, [ 'es_query_cache' ] )
		);

		$this->assertContains( 'es_query_cache', Cache_Warmer::cold_groups() );
	}

	// ── Secret-gated warm-request detection ─────────────────────────────────

	public function test_warm_request_recognized_with_matching_secret(): void {
		$this->assertTrue(
			Cache_Warmer::is_warm_request( [ 'eln_cache_warm' => 's3cr3t' ], 's3cr3t' )
		);
	}

	public function test_warm_request_rejected_with_wrong_secret(): void {
		$this->assertFalse(
			Cache_Warmer::is_warm_request( [ 'eln_cache_warm' => 'nope' ], 's3cr3t' )
		);
	}

	public function test_warm_request_rejected_when_param_absent(): void {
		$this->assertFalse( Cache_Warmer::is_warm_request( [], 's3cr3t' ) );
	}

	public function test_warm_request_rejected_when_secret_is_empty(): void {
		// An unset/empty stored secret must never match an empty param.
		$this->assertFalse( Cache_Warmer::is_warm_request( [ 'eln_cache_warm' => '' ], '' ) );
	}

	public function test_array_param_rejected_without_a_php_warning(): void {
		// ?eln_cache_warm[]=x makes the param an array; it must be rejected
		// cleanly, not cast to string ("Array to string conversion" warning).
		$warned = false;
		set_error_handler(
			static function () use ( &$warned ): bool {
				$warned = true;
				return true;
			}
		);
		$result = Cache_Warmer::is_warm_request( [ 'eln_cache_warm' => [ 'x' ] ], 's3cr3t' );
		restore_error_handler();

		$this->assertFalse( $result );
		$this->assertFalse( $warned, 'an array param must not trigger a PHP warning' );
	}

	// ── Decorator install (the $wp_object_cache swap) ───────────────────────

	public function test_install_cold_cache_wraps_object_cache_with_cold_reads(): void {
		$real = $this->fake_object_cache();
		$real->set( 'np_cached_block_x_0', 'stale', 'newspack_blocks' );
		$real->set( 'alloptions', [ 'a' => 1 ], 'options' );
		$GLOBALS['wp_object_cache'] = $real;

		Cache_Warmer::install_cold_cache();

		$this->assertInstanceOf( Cold_Read_Object_Cache::class, $GLOBALS['wp_object_cache'] );
		// Cold group reads miss; warm group reads still pass through.
		$this->assertFalse( $GLOBALS['wp_object_cache']->get( 'np_cached_block_x_0', 'newspack_blocks' ) );
		$this->assertSame( [ 'a' => 1 ], $GLOBALS['wp_object_cache']->get( 'alloptions', 'options' ) );
	}

	public function test_install_cold_cache_is_idempotent(): void {
		$GLOBALS['wp_object_cache'] = $this->fake_object_cache();

		Cache_Warmer::install_cold_cache();
		$first = $GLOBALS['wp_object_cache'];
		Cache_Warmer::install_cold_cache();

		$this->assertSame( $first, $GLOBALS['wp_object_cache'], 'must not double-wrap the object cache' );
	}

	// ── Secret + loopback URL ───────────────────────────────────────────────

	public function test_secret_generated_once_then_persisted(): void {
		$first  = Cache_Warmer::secret();
		$second = Cache_Warmer::secret();

		$this->assertNotSame( '', $first );
		$this->assertSame( $first, $second, 'secret must persist, not regenerate per call' );
		$this->assertSame( $first, $GLOBALS['_wp_options']['eln_cache_warmer_secret'] );
	}

	public function test_secret_option_is_not_autoloaded(): void {
		Cache_Warmer::secret();
		$this->assertFalse( $GLOBALS['_wp_option_autoload']['eln_cache_warmer_secret'] );
	}

	public function test_warm_url_targets_home_with_secret_param(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';

		$url = Cache_Warmer::warm_url();

		$this->assertStringStartsWith( 'https://www.bendsource.com/', $url );
		$this->assertStringContainsString( 'eln_cache_warm=' . Cache_Warmer::secret(), $url );
	}

	// ── Cron tick (loopback) ────────────────────────────────────────────────

	public function test_run_tick_fires_the_loopback(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';

		Cache_Warmer::run_tick();

		$this->assertCount( 1, $GLOBALS['_wp_test_remote_gets'] );
		$call = $GLOBALS['_wp_test_remote_gets'][0];
		$this->assertStringContainsString( 'eln_cache_warm=', $call['url'] );
		$this->assertTrue( $call['args']['sslverify'], 'TLS verification on by default (loopback hits a public hostname)' );
		$this->assertGreaterThanOrEqual( 10, $call['args']['timeout'] );
	}

	public function test_sslverify_can_be_disabled_via_filter_for_self_signed_hosts(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		add_filter( 'eln_cache_warmer_sslverify', static fn (): bool => false );

		Cache_Warmer::run_tick();

		$this->assertFalse( $GLOBALS['_wp_test_remote_gets'][0]['args']['sslverify'] );
		unset( $GLOBALS['_wp_actions']['eln_cache_warmer_sslverify'] );
	}

	// ── maybe_install_for_request() — the drop-in-load cold-cache swap ──────

	public function test_install_happens_on_warm_loopback_request(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		$GLOBALS['wp_object_cache']   = $this->fake_object_cache();
		$_GET['eln_cache_warm']       = Cache_Warmer::secret();

		Cache_Warmer::maybe_install_for_request();

		$this->assertInstanceOf( Cold_Read_Object_Cache::class, $GLOBALS['wp_object_cache'] );
	}

	public function test_no_install_on_a_normal_request(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		$real                         = $this->fake_object_cache();
		$GLOBALS['wp_object_cache']   = $real;
		// no eln_cache_warm param

		Cache_Warmer::maybe_install_for_request();

		$this->assertSame( $real, $GLOBALS['wp_object_cache'] );
	}

	// ── #6: warm render excluded from timing stats ──────────────────────────

	public function test_warm_request_marks_worker_type_for_stats_exclusion(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		$GLOBALS['wp_object_cache']   = $this->fake_object_cache();
		$_GET['eln_cache_warm']       = Cache_Warmer::secret();

		Cache_Warmer::maybe_install_for_request();

		// LogManager reads $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] → tags the
		// request worker_type → Flame_Builder drops it from timing stats.
		$this->assertSame( 'cache-warmer', $_SERVER['NEWSPACK_NODES_WORKER_TYPE'] ?? null );
	}

	public function test_normal_request_does_not_mark_worker_type(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';

		Cache_Warmer::maybe_install_for_request();

		$this->assertArrayNotHasKey( 'NEWSPACK_NODES_WORKER_TYPE', $_SERVER );
	}

	// ── warm render bypasses access gates so the loopback reaches the page ──

	public function test_warm_request_bypasses_password_protection(): void {
		$GLOBALS['wp_object_cache'] = $this->fake_object_cache();
		$_GET['eln_cache_warm']     = Cache_Warmer::secret();

		Cache_Warmer::maybe_install_for_request();

		// The Password Protected plugin would otherwise 302 the loopback to its
		// login page; the warmer disables it for its own (secret-gated) render.
		$this->assertFalse( apply_filters( 'password_protected_is_active', true ) );
	}

	public function test_normal_request_leaves_password_protection_active(): void {
		Cache_Warmer::maybe_install_for_request(); // no secret param

		$this->assertTrue( apply_filters( 'password_protected_is_active', true ) );
	}

	// ── #7: single-flight — no overlapping warm renders ─────────────────────

	public function test_run_tick_skips_when_a_warm_render_is_already_in_flight(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';
		set_transient( 'eln_cache_warmer_lock', 1, 60 ); // prior tick holds the lock

		Cache_Warmer::run_tick();

		$this->assertCount( 0, $GLOBALS['_wp_test_remote_gets'], 'must not fire a second loopback while one is in flight' );
	}

	public function test_run_tick_takes_and_releases_the_lock_on_a_clean_run(): void {
		$GLOBALS['_wp_test_home_url'] = 'https://www.bendsource.com';

		Cache_Warmer::run_tick();

		$this->assertCount( 1, $GLOBALS['_wp_test_remote_gets'] );
		$this->assertFalse( get_transient( 'eln_cache_warmer_lock' ), 'lock must be released after the run' );
	}
}
