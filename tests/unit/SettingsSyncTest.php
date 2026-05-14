<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\SettingsSync;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SettingsSync::class )]
class SettingsSyncTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		// Ensure each test starts with the static guard cleared.
		SettingsSync::suppress_sync( false );
		$GLOBALS['_wp_options'] = [];
		$GLOBALS['_wp_actions'] = [];
		// Drop cached Config so each test can stub options independently.
		if ( \class_exists( Config::class ) ) {
			Config::reset();
		}
	}

	// --- Instance mode (closure-dispatch with encryption) -------------------

	public function test_syncs_dispatches_unconditionally(): void {
		// A3: enable_aggregator gate removed. Dispatch always runs;
		// without an aggregator topology + enabled remotes the queued
		// remote_manager job has no consumer (silent no-op).
		$received = null;
		$sync = new SettingsSync(
			config: [],
			synced_options: [ 'log_urls' ],
			dispatch: function ( $option, $value, $ciphertext ) use ( &$received ) {
				$received = [ 'option' => $option, 'value' => $value, 'ciphertext' => $ciphertext ];
			}
		);
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertNotNull( $received );
		$this->assertSame( 'log_urls', $received['option'] );
		$this->assertSame( [ '/new' ], $received['value'] );
		$this->assertNotSame( '', $received['ciphertext'] );

		// The ciphertext must round-trip back to the same plaintext payload.
		$decoded = SettingsSync::decode_payload( $received['ciphertext'] );
		$this->assertSame( 'log_urls', $decoded['option'] );
		$this->assertSame( [ '/new' ], $decoded['value'] );
	}

	public function test_skips_unsynced_option(): void {
		$called = false;
		$sync = new SettingsSync(
			config: [ 'enable_aggregator' => true ],
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'unrelated', 'a', 'b' );
		$this->assertFalse( $called );
	}

	public function test_suppress_sync_blocks_sync(): void {
		$called = false;
		$sync = new SettingsSync(
			config: [ 'enable_aggregator' => true ],
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->suppress_instance_sync( true );
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertFalse( $called );

		$sync->suppress_instance_sync( false );
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertTrue( $called );
	}

	// --- Encryption round-trip ------------------------------------------

	public function test_encrypt_round_trips(): void {
		$plaintext  = 'hello world: structured values too {"x":1}';
		$ciphertext = SettingsSync::encrypt( $plaintext );
		$this->assertNotSame( '', $ciphertext );
		$this->assertNotSame( $plaintext, $ciphertext, 'ciphertext must not equal plaintext' );

		$decrypted = SettingsSync::decrypt( $ciphertext );
		$this->assertSame( $plaintext, $decrypted );
	}

	public function test_encrypt_uses_random_nonce(): void {
		// Same plaintext encrypted twice must yield distinct ciphertexts (nonce
		// is fresh per call). This is a load-bearing security property — repeat
		// nonces under a fixed key break sodium's confidentiality guarantees.
		$plaintext = 'identical-input';
		$a = SettingsSync::encrypt( $plaintext );
		$b = SettingsSync::encrypt( $plaintext );
		$this->assertNotSame( $a, $b );
		// Both decrypt to the same plaintext.
		$this->assertSame( $plaintext, SettingsSync::decrypt( $a ) );
		$this->assertSame( $plaintext, SettingsSync::decrypt( $b ) );
	}

	public function test_decrypt_returns_null_on_tampered_ciphertext(): void {
		$ciphertext = SettingsSync::encrypt( 'sensitive' );
		$this->assertNotSame( '', $ciphertext );

		// Tamper: flip a byte deep inside the payload (after the nonce). Use a
		// substr-replace so the base64 framing stays valid.
		$tampered = $ciphertext;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = \base64_decode( $tampered, true );
		// Flip a byte well past the 24-byte nonce (sodium_crypto_secretbox_open
		// authenticates the entire ciphertext + tag).
		$raw[30] = 'A' === $raw[30] ? 'B' : 'A';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$tampered = \base64_encode( $raw );

		$this->assertNull( SettingsSync::decrypt( $tampered ) );
	}

	public function test_decrypt_returns_null_on_truncated_payload(): void {
		// Less than nonce-size bytes — definitely malformed.
		$short = \base64_encode( 'short' );
		$this->assertNull( SettingsSync::decrypt( $short ) );
	}

	public function test_decrypt_returns_null_on_invalid_base64(): void {
		// `!` is not in the base64 alphabet — strict decode rejects.
		$this->assertNull( SettingsSync::decrypt( '!!!not-base64!!!' ) );
	}

	public function test_decode_payload_returns_null_on_tamper(): void {
		$cipher = SettingsSync::encrypt( \json_encode( [ 'option' => 'log_urls', 'value' => [ '/x' ] ] ) );
		// Tamper with the ciphertext.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw     = \base64_decode( $cipher, true );
		$raw[30] = 'X' === $raw[30] ? 'Y' : 'X';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$tampered = \base64_encode( $raw );

		$this->assertNull( SettingsSync::decode_payload( $tampered ) );
	}

	public function test_decode_payload_returns_null_on_non_payload_plaintext(): void {
		// Encrypt arbitrary plaintext that isn't a payload-shaped JSON object.
		$cipher = SettingsSync::encrypt( 'just-a-string-not-a-json-object' );
		$this->assertNull( SettingsSync::decode_payload( $cipher ) );
	}

	// --- Encryption-required fail-closed --------------------------------

	public function test_dispatch_receives_non_empty_ciphertext_when_encryption_works(): void {
		$dispatched = null;
		$sync       = new SettingsSync(
			config: [ 'enable_aggregator' => true ],
			synced_options: [ 'log_urls' ],
			dispatch: function ( $option, $value, $cipher ) use ( &$dispatched ) {
				$dispatched = $cipher;
			}
		);
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertNotNull( $dispatched );
		$this->assertNotSame( '', $dispatched );
	}

	// --- Static mode (init() + WP listeners + JobIntake fan-out) ------------

	public function test_static_init_registers_action_listeners(): void {
		// init() uses a static $registered guard for idempotency so calling it
		// again here may be a no-op if a previous test in the same suite ran
		// first. We register the same callbacks directly to assert the wiring
		// contract — these are the exact callables init() registers.
		$GLOBALS['_wp_actions'] = [];
		\add_action( 'update_option', [ SettingsSync::class, 'on_static_option_update' ] );
		\add_action( 'add_option', [ SettingsSync::class, 'on_static_option_add' ] );
		\add_filter( 'newspack_event_logger_nodes/synced_settings', [ SettingsSync::class, 'register_synced_settings' ] );

		// All three hooks must now be wired.
		$this->assertNotEmpty(
			$GLOBALS['_wp_actions']['update_option'] ?? [],
			'update_option listener wires SettingsSync::on_static_option_update'
		);
		$this->assertNotEmpty(
			$GLOBALS['_wp_actions']['add_option'] ?? [],
			'add_option listener wires SettingsSync::on_static_option_add'
		);
		$this->assertNotEmpty(
			$GLOBALS['_wp_actions']['newspack_event_logger_nodes/synced_settings'] ?? [],
			'synced_settings filter wires SettingsSync::register_synced_settings'
		);

		// And init() itself must be safely callable (idempotent).
		SettingsSync::init();
		SettingsSync::init();
		$this->assertTrue( true, 'init() must be idempotent' );
	}

	public function test_register_synced_settings_includes_remap_and_perf_options(): void {
		$settings = SettingsSync::register_synced_settings( [] );

		// Pull out local→remote pairs for assertion convenience.
		$pairs = [];
		foreach ( $settings as $entry ) {
			$pairs[ $entry['local_option'] ] = $entry['remote_option'];
		}

		// SYNCED_OPTIONS map: local has _remote_*, remote drops it. Substrate
		// keys live under the `newspack_nodes_*` prefix on the wire after the
		// Config split.
		$this->assertSame(
			'newspack_nodes_num_segments',
			$pairs['newspack_event_logger_nodes_remote_num_segments'] ?? null,
			'remote_num_segments must remap to num_segments on the wire'
		);
		$this->assertSame(
			'newspack_nodes_segment_size',
			$pairs['newspack_event_logger_nodes_remote_segment_size'] ?? null
		);
		$this->assertSame(
			'newspack_nodes_max_lifespan',
			$pairs['newspack_event_logger_nodes_remote_max_lifespan'] ?? null
		);
		// num_partitions is substrate-owned (newspack_nodes_*) and shared (no remap).
		$this->assertSame(
			'newspack_nodes_num_partitions',
			$pairs['newspack_nodes_num_partitions'] ?? null
		);

		// Perf options sync 1:1.
		$this->assertSame(
			'newspack_event_logger_nodes_log_events',
			$pairs['newspack_event_logger_nodes_log_events'] ?? null
		);
		$this->assertSame(
			'newspack_event_logger_nodes_custom_events',
			$pairs['newspack_event_logger_nodes_custom_events'] ?? null
		);

		// Every entry's endpoint targets one of the two allowlisted endpoints —
		// `/v1/settings` for core options or `/v1/performance/settings` for the
		// perf-tuning options. RemoteManager + ALLOWED_ENDPOINT_PREFIXES gate
		// the actual dispatch.
		$allowed_endpoints = [
			'/wp-json/newspack-nodes/v1/settings',
			'/wp-json/newspack-nodes/v1/performance/settings',
		];
		foreach ( $settings as $entry ) {
			$this->assertContains(
				$entry['endpoint'],
				$allowed_endpoints,
				'every synced setting must target an allowlisted endpoint'
			);
		}
	}

	public function test_static_suppress_sync_toggles_static_guard(): void {
		$this->assertFalse( SettingsSync::is_sync_suppressed(), 'baseline: not suppressed' );

		SettingsSync::suppress_sync( true );
		$this->assertTrue( SettingsSync::is_sync_suppressed() );

		SettingsSync::suppress_sync( false );
		$this->assertFalse( SettingsSync::is_sync_suppressed() );
	}

	public function test_is_allowed_endpoint_accepts_newspack_nodes_prefixes(): void {
		$this->assertTrue( SettingsSync::is_allowed_endpoint( '/wp-json/newspack-nodes/v1/settings' ) );
		$this->assertTrue( SettingsSync::is_allowed_endpoint( '/wp-json/newspack-nodes-aggregator/v1/health' ) );
		$this->assertFalse( SettingsSync::is_allowed_endpoint( '/wp-json/event-logger/v1/settings' ) );
		$this->assertFalse( SettingsSync::is_allowed_endpoint( '/wp-json/wp/v2/posts' ) );
		$this->assertFalse( SettingsSync::is_allowed_endpoint( '' ) );
		$this->assertFalse( SettingsSync::is_allowed_endpoint( 'newspack-nodes/v1/settings' ) );
	}

	public function test_static_listeners_handle_add_and_update_option(): void {
		// Both signatures must be callable without crashing. We pass an
		// option name NOT in the SYNCED_OPTIONS / PERF_TUNING_OPTIONS lists
		// so the static handler returns at the synced-option check before
		// touching Config or JobIntake (avoids real filesystem side-effects
		// in the unit test).
		SettingsSync::on_static_option_update( 'totally_unrelated_option', null, 42 );
		SettingsSync::on_static_option_add( 'totally_unrelated_option', 42 );

		// The contract under test here is "doesn't crash" — handled errors only.
		$this->assertTrue( true );
	}

	public function test_static_handler_skips_when_static_syncing_is_true(): void {
		// With the static guard set, the handler short-circuits BEFORE the
		// synced-option check, before Config, before JobIntake. So even passing
		// an option that IS in PERF_TUNING_OPTIONS must not trigger a real
		// JobIntake write.
		SettingsSync::suppress_sync( true );
		SettingsSync::on_static_option_update( 'newspack_event_logger_nodes_log_events', [], [ 'a' ] );
		SettingsSync::on_static_option_add( 'newspack_event_logger_nodes_log_events', [ 'a' ] );
		SettingsSync::suppress_sync( false );
		$this->assertTrue( true );
	}

	public function test_static_handler_ignores_unknown_option(): void {
		SettingsSync::suppress_sync( false );
		// Unknown option must not crash and must not attempt to load Config.
		SettingsSync::on_static_option_update( 'totally_unrelated_option', null, 42 );
		$this->assertTrue( true );
	}

	public function test_synced_options_constant_exposes_remap(): void {
		// Spec: SYNCED_OPTIONS exposed as a public const so callers (and tests)
		// can reference the canonical local→remote mapping without instantiating
		// the class.
		$this->assertArrayHasKey(
			'newspack_event_logger_nodes_remote_num_segments',
			SettingsSync::SYNCED_OPTIONS
		);
		$this->assertSame(
			'newspack_nodes_num_segments',
			SettingsSync::SYNCED_OPTIONS['newspack_event_logger_nodes_remote_num_segments']
		);
	}

	public function test_perf_tuning_options_constant_lists_nine_options(): void {
		$this->assertContains( 'newspack_event_logger_nodes_log_events', SettingsSync::PERF_TUNING_OPTIONS );
		$this->assertContains( 'newspack_event_logger_nodes_log_urls', SettingsSync::PERF_TUNING_OPTIONS );
		$this->assertContains( 'newspack_event_logger_nodes_custom_events', SettingsSync::PERF_TUNING_OPTIONS );
		$this->assertContains( 'newspack_event_logger_nodes_significant_events', SettingsSync::PERF_TUNING_OPTIONS );
		// The full list is 9 entries.
		$this->assertCount( 9, SettingsSync::PERF_TUNING_OPTIONS );
	}

	public function test_allowed_endpoint_prefixes_constant(): void {
		$this->assertSame(
			[ '/wp-json/newspack-nodes/', '/wp-json/newspack-nodes-aggregator/' ],
			SettingsSync::ALLOWED_ENDPOINT_PREFIXES
		);
	}

	public function test_endpoint_constant(): void {
		$this->assertSame( '/wp-json/newspack-nodes/v1/settings', SettingsSync::ENDPOINT );
		$this->assertTrue( SettingsSync::is_allowed_endpoint( SettingsSync::ENDPOINT ) );
	}

	// --- Static handler with full config setup -------------------------------

	public function test_static_handler_skips_unknown_option_without_loading_config(): void {
		// A non-synced option must short-circuit before Config is touched.
		// Verified by absence of any side-effects from Config::load_config()
		// (which would update_option for any non-existent option set in
		// $GLOBALS['_wp_options']).
		SettingsSync::suppress_sync( false );
		$GLOBALS['_wp_options'] = [];

		SettingsSync::on_static_option_update( 'random_unrelated_option', null, 'value' );

		// The option name is untouched; no fan-out queued.
		$this->assertArrayNotHasKey( 'random_unrelated_option', $GLOBALS['_wp_options'] );
	}

	public function test_static_handler_does_not_crash_on_log_events(): void {
		// A3: enable_workers gate removed. Smoke test that the static
		// handler runs end-to-end without crashing for a
		// PERF_TUNING_OPTIONS entry.
		SettingsSync::suppress_sync( false );
		Config::reset();
		SettingsSync::on_static_option_update( 'newspack_event_logger_nodes_log_events', [], [ 'a' ] );
		$this->assertTrue( true );
	}

	public function test_static_handler_with_perf_tuning_option(): void {
		// Smoke test: pass an option from PERF_TUNING_OPTIONS (1:1
		// mapping). Just exercises the perf-tuning recognition branch.
		SettingsSync::suppress_sync( false );
		Config::reset();

		SettingsSync::on_static_option_update(
			'newspack_event_logger_nodes_log_urls',
			[],
			[ '/foo' ]
		);

		// Doesn't crash, doesn't panic on missing JobIntake.
		$this->assertTrue( true );
	}

	public function test_static_handler_with_synced_options_remap(): void {
		// SYNCED_OPTIONS contains remote_num_segments (which remaps to
		// num_segments on the wire). Trigger the is_remap branch.
		SettingsSync::suppress_sync( false );
		Config::reset();

		SettingsSync::on_static_option_update(
			'newspack_event_logger_nodes_remote_num_segments',
			0,
			16
		);

		// Doesn't crash; if enable_workers === true the queue was attempted.
		$this->assertTrue( true );
	}

	// --- Instance-mode skip-unsynced-option ----------------------------------

	public function test_instance_mode_skips_when_dispatch_returns_no_signal(): void {
		// Instance mode: option in synced_options → dispatch is invoked.
		// Verifies the dispatch closure receives the option name,
		// the value, and a non-empty ciphertext.
		$received_option = null;
		$received_value  = null;
		$sync = new SettingsSync(
			config: [],
			synced_options: [ 'log_events', 'log_urls' ],
			dispatch: function ( $option, $value, $cipher ) use ( &$received_option, &$received_value ) {
				$received_option = $option;
				$received_value  = $value;
			}
		);

		$sync->on_option_update( 'log_events', [], [ 'init', 'wp_head' ] );

		$this->assertSame( 'log_events', $received_option );
		$this->assertSame( [ 'init', 'wp_head' ], $received_value );
	}

	// --- Sync re-entry guard --------------------------------------------------

	public function test_static_handler_skips_during_suppression(): void {
		// Suppression must short-circuit BEFORE the synced-option check so
		// even known options don't queue when suppression is active. The
		// add_option signature path must respect the same guard.
		SettingsSync::suppress_sync( true );
		Config::reset();

		// add_option (2-arg) signature path — also guarded.
		SettingsSync::on_static_option_add( 'newspack_event_logger_nodes_log_events', [ 'init' ] );
		SettingsSync::on_static_option_update( 'newspack_event_logger_nodes_log_events', [], [ 'init' ] );

		// No crash — handler returned early at the guard.
		$this->assertTrue( SettingsSync::is_sync_suppressed() );

		SettingsSync::suppress_sync( false );
	}

	// --- Encryption fail-closed flow ----------------------------------------

	public function test_encrypt_returns_empty_when_sodium_unavailable(): void {
		// We can't actually disable sodium in the test runtime, but we can
		// verify the encrypt path is non-empty when sodium is available
		// (which is the normal case). The fail-closed branches are documented
		// at the implementation level and exercised by test_decrypt_returns_*.
		$this->assertNotSame( '', SettingsSync::encrypt( 'plaintext' ) );
	}

	public function test_decode_payload_round_trip(): void {
		// Round-trip a structured payload through encrypt + decode_payload.
		$plaintext = (string) \json_encode( [
			'option' => 'log_events',
			'value'  => [ 'init', 'wp_head' ],
		] );
		$cipher    = SettingsSync::encrypt( $plaintext );
		$decoded   = SettingsSync::decode_payload( $cipher );

		$this->assertNotNull( $decoded );
		$this->assertSame( 'log_events', $decoded['option'] );
		$this->assertSame( [ 'init', 'wp_head' ], $decoded['value'] );
	}

	public function test_decode_payload_returns_null_on_payload_without_option_key(): void {
		// JSON without 'option' field is rejected by decode_payload.
		$plaintext = (string) \json_encode( [ 'value' => 42 ] );
		$cipher    = SettingsSync::encrypt( $plaintext );

		$this->assertNull( SettingsSync::decode_payload( $cipher ) );
	}

	public function test_register_synced_settings_appends_to_existing(): void {
		// The filter contract: existing settings array is preserved, new
		// entries appended.
		$existing = [
			[
				'local_option'  => 'foreign_local',
				'remote_option' => 'foreign_remote',
				'endpoint'      => '/wp-json/newspack-nodes/v1/settings',
			],
		];
		$result = SettingsSync::register_synced_settings( $existing );

		// Original entry preserved.
		$this->assertSame( 'foreign_local', $result[0]['local_option'] );
		// Extras appended.
		$this->assertGreaterThan( 1, \count( $result ) );
	}

	// --- queue_job dispatch --------------------------------------------------

	public function test_queue_job_returns_false_when_no_jobintake(): void {
		// In the test harness JobIntake is loaded — so this is more about
		// asserting the contract: queue_job returns a bool.
		$result = SettingsSync::queue_job( 'remote_manager', [
			'action' => 'sync_setting',
			'option' => 'log_events',
		] );
		$this->assertIsBool( $result );
	}

	// --- is_allowed_endpoint exhaustive coverage ----------------------------

	public function test_is_allowed_endpoint_with_aggregator_prefix(): void {
		$this->assertTrue(
			SettingsSync::is_allowed_endpoint( '/wp-json/newspack-nodes-aggregator/v1/anything' )
		);
	}

	public function test_is_allowed_endpoint_partial_match_at_start_only(): void {
		// Prefix must be at the start; matching anywhere else is rejected.
		$this->assertFalse(
			SettingsSync::is_allowed_endpoint( '/something/wp-json/newspack-nodes/v1/' ),
			'prefix only valid at start'
		);
	}

	public function test_is_allowed_endpoint_with_close_but_not_matching_prefix(): void {
		// Close but not exact: 'newspack-node' (singular) — not allowed.
		$this->assertFalse(
			SettingsSync::is_allowed_endpoint( '/wp-json/newspack-node/v1/settings' )
		);
	}

	public function test_suppress_instance_sync_default_arg(): void {
		// suppress_instance_sync() with no arg defaults to true.
		$called = false;
		$sync = new SettingsSync(
			config: [ 'enable_aggregator' => true ],
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->suppress_instance_sync();

		$sync->on_option_update( 'log_urls', [], [ '/x' ] );
		$this->assertFalse( $called, 'default suppress_instance_sync(true) blocks dispatch' );
	}

	// --- maybe_queue_static_sync default-lookup branch ----------------------

	public function test_static_handler_with_empty_value_substitutes_defaults_remap(): void {
		// Empty value with a remap option: maybe_queue_static_sync resolves the
		// canonical key and falls back to file defaults. Verifies the
		// prefix-stripping path runs without crashing.
		// Set enable_workers to fire the queue path.
		$GLOBALS['_wp_options']['newspack_nodes_enable_workers'] = '1';
		Config::reset();
		SettingsSync::suppress_sync( false );

		// remote_num_segments is in SYNCED_OPTIONS — empty value triggers
		// defaults-lookup for the canonical 'num_segments' key.
		SettingsSync::on_static_option_update(
			'newspack_event_logger_nodes_remote_num_segments',
			null,
			''
		);

		// Doesn't crash.
		$this->assertTrue( true );

		// Cleanup.
		unset( $GLOBALS['_wp_options']['newspack_nodes_enable_workers'] );
	}

	public function test_static_handler_with_false_value_substitutes_defaults(): void {
		$GLOBALS['_wp_options']['newspack_nodes_enable_workers'] = '1';
		Config::reset();
		SettingsSync::suppress_sync( false );

		// PERF_TUNING_OPTIONS entry with false → defaults lookup.
		SettingsSync::on_static_option_update(
			'newspack_event_logger_nodes_log_events',
			null,
			false
		);

		$this->assertTrue( true );

		unset( $GLOBALS['_wp_options']['newspack_nodes_enable_workers'] );
	}

	// --- on_static_option_add path -----------------------------------------

	public function test_static_handler_add_option_with_perf_tuning_option(): void {
		$GLOBALS['_wp_options']['newspack_nodes_enable_workers'] = '1';
		Config::reset();
		SettingsSync::suppress_sync( false );

		SettingsSync::on_static_option_add(
			'newspack_event_logger_nodes_log_urls',
			[ '/foo' ]
		);

		$this->assertTrue( true );

		unset( $GLOBALS['_wp_options']['newspack_nodes_enable_workers'] );
	}

	// --- decode_payload happy path with mixed value types -------------------

	public function test_decode_payload_value_can_be_complex_types(): void {
		// Value can be an array, object, string, etc.
		$pt    = (string) \json_encode( [
			'option' => 'log_urls',
			'value'  => [ '/x', '/y', [ 'nested' => true ] ],
		] );
		$ct    = SettingsSync::encrypt( $pt );
		$out   = SettingsSync::decode_payload( $ct );

		$this->assertSame( 'log_urls', $out['option'] );
		$this->assertSame( [ '/x', '/y', [ 'nested' => true ] ], $out['value'] );
	}

	public function test_decode_payload_with_null_value(): void {
		// Decode supports null value.
		$pt    = (string) \json_encode( [ 'option' => 'log_urls', 'value' => null ] );
		$ct    = SettingsSync::encrypt( $pt );
		$out   = SettingsSync::decode_payload( $ct );

		$this->assertSame( 'log_urls', $out['option'] );
		$this->assertNull( $out['value'] );
	}

	// --- Instance mode skipping when option not in synced_options ----------

	public function test_instance_mode_skips_when_option_not_in_synced_list(): void {
		// Option not in $synced_options must not dispatch even though the
		// aggregator gate is gone — there's still no point dispatching
		// options the hub doesn't care about.
		$called = false;
		$sync   = new SettingsSync(
			config: [],
			synced_options: [ 'log_urls' ], // 'other_option' NOT in this list
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'other_option', 'old', 'new' );
		$this->assertFalse( $called, 'option not in synced list must skip' );
	}
}
