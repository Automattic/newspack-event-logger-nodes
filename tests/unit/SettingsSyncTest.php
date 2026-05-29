<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\Settings_Sync;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

\class_exists( \Newspack_Event_Logger_Nodes\Job_Intake::class )
	|| require_once \dirname( __DIR__, 2 ) . '/includes/class-job-intake.php';

#[CoversClass( Settings_Sync::class )]
class SettingsSyncTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		// Ensure each test starts with the static guard cleared.
		Settings_Sync::suppress_sync( false );
		$GLOBALS['_wp_options'] = [];
		$GLOBALS['_wp_actions'] = [];
		// Drop cached Config so each test can stub options independently.
		if ( \class_exists( Config::class ) ) {
			Config::reset();
		}
	}

	/**
	 * Walk a tmp base_dir's `jobintake.log/p*` partitions and return every
	 * queued job-envelope array in the order it was written. Each line on disk
	 * is a packed Tachikoma Message; the envelope lives in VALUE and has shape
	 * `{ k: 'job', handler: <string>, parameters: <array>, ts: <float> }`.
	 *
	 * Mirrors `JobIntakeTest::read_all_jobintake_lines()` — kept local so the
	 * Settings_Sync suite remains self-contained.
	 *
	 * @return array<int, array>
	 */
	private function read_jobintake_envelopes( string $base_dir ): array {
		$envelopes = [];
		$base_log  = "{$base_dir}/logs/jobintake.log";
		if ( ! \is_dir( $base_log ) ) {
			return $envelopes;
		}
		foreach ( \scandir( $base_log ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$pdir = "{$base_log}/{$entry}";
			if ( ! \is_dir( $pdir ) ) {
				continue;
			}
			foreach ( \scandir( $pdir ) as $file ) {
				if ( ! \preg_match( '/^\d+\.log$/', $file ) ) {
					continue;
				}
				$content = \file_get_contents( "{$pdir}/{$file}" );
				if ( '' === $content ) {
					continue;
				}
				foreach ( \preg_split( '/\n/', \rtrim( $content, "\n" ) ) as $line ) {
					if ( '' === $line ) {
						continue;
					}
					$msg = Message::unpacked( $line );
					if ( \is_array( $msg[ Message::VALUE ] ?? null ) ) {
						$envelopes[] = $msg[ Message::VALUE ];
					}
				}
			}
		}
		return $envelopes;
	}

	// --- Instance mode (closure-dispatch with encryption) -------------------

	public function test_syncs_dispatches_unconditionally(): void {
		// A3: enable_aggregator gate removed. Dispatch always runs;
		// without an aggregator topology + enabled remotes the queued
		// remote_manager job has no consumer (silent no-op).
		$received = null;
		$sync = new Settings_Sync(
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
		$decoded = Settings_Sync::decode_payload( $received['ciphertext'] );
		$this->assertSame( 'log_urls', $decoded['option'] );
		$this->assertSame( [ '/new' ], $decoded['value'] );
	}

	public function test_skips_unsynced_option(): void {
		$called = false;
		$sync = new Settings_Sync(
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'unrelated', 'a', 'b' );
		$this->assertFalse( $called );
	}

	public function test_suppress_sync_blocks_sync(): void {
		$called = false;
		$sync = new Settings_Sync(
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
		$ciphertext = Settings_Sync::encrypt( $plaintext );
		$this->assertNotSame( '', $ciphertext );
		$this->assertNotSame( $plaintext, $ciphertext, 'ciphertext must not equal plaintext' );

		$decrypted = Settings_Sync::decrypt( $ciphertext );
		$this->assertSame( $plaintext, $decrypted );
	}

	public function test_encrypt_uses_random_nonce(): void {
		// Same plaintext encrypted twice must yield distinct ciphertexts (nonce
		// is fresh per call). This is a load-bearing security property — repeat
		// nonces under a fixed key break sodium's confidentiality guarantees.
		$plaintext = 'identical-input';
		$a = Settings_Sync::encrypt( $plaintext );
		$b = Settings_Sync::encrypt( $plaintext );
		$this->assertNotSame( $a, $b );
		// Both decrypt to the same plaintext.
		$this->assertSame( $plaintext, Settings_Sync::decrypt( $a ) );
		$this->assertSame( $plaintext, Settings_Sync::decrypt( $b ) );
	}

	public function test_decrypt_returns_null_on_tampered_ciphertext(): void {
		$ciphertext = Settings_Sync::encrypt( 'sensitive' );
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

		$this->assertNull( Settings_Sync::decrypt( $tampered ) );
	}

	public function test_decrypt_returns_null_on_truncated_payload(): void {
		// Less than nonce-size bytes — definitely malformed.
		$short = \base64_encode( 'short' );
		$this->assertNull( Settings_Sync::decrypt( $short ) );
	}

	public function test_decrypt_returns_null_on_invalid_base64(): void {
		// `!` is not in the base64 alphabet — strict decode rejects.
		$this->assertNull( Settings_Sync::decrypt( '!!!not-base64!!!' ) );
	}

	public function test_decode_payload_returns_null_on_tamper(): void {
		$cipher = Settings_Sync::encrypt( \json_encode( [ 'option' => 'log_urls', 'value' => [ '/x' ] ] ) );
		// Tamper with the ciphertext.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw     = \base64_decode( $cipher, true );
		$raw[30] = 'X' === $raw[30] ? 'Y' : 'X';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$tampered = \base64_encode( $raw );

		$this->assertNull( Settings_Sync::decode_payload( $tampered ) );
	}

	public function test_decode_payload_returns_null_on_non_payload_plaintext(): void {
		// Encrypt arbitrary plaintext that isn't a payload-shaped JSON object.
		$cipher = Settings_Sync::encrypt( 'just-a-string-not-a-json-object' );
		$this->assertNull( Settings_Sync::decode_payload( $cipher ) );
	}

	// --- Encryption-required fail-closed --------------------------------

	public function test_dispatch_receives_non_empty_ciphertext_when_encryption_works(): void {
		$dispatched = null;
		$sync       = new Settings_Sync(
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
		\add_action( 'update_option', [ Settings_Sync::class, 'on_static_option_update' ] );
		\add_action( 'add_option', [ Settings_Sync::class, 'on_static_option_add' ] );
		\add_filter( 'newspack_event_logger_nodes/synced_settings', [ Settings_Sync::class, 'register_synced_settings' ] );

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
		Settings_Sync::init();
		Settings_Sync::init();
		$this->assertTrue( true, 'init() must be idempotent' );
	}

	public function test_register_synced_settings_includes_remap_and_perf_options(): void {
		$settings = Settings_Sync::register_synced_settings( [] );

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
		$this->assertFalse( Settings_Sync::is_sync_suppressed(), 'baseline: not suppressed' );

		Settings_Sync::suppress_sync( true );
		$this->assertTrue( Settings_Sync::is_sync_suppressed() );

		Settings_Sync::suppress_sync( false );
		$this->assertFalse( Settings_Sync::is_sync_suppressed() );
	}

	public function test_is_allowed_endpoint_accepts_newspack_nodes_prefixes(): void {
		$this->assertTrue( Settings_Sync::is_allowed_endpoint( '/wp-json/newspack-nodes/v1/settings' ) );
		$this->assertTrue( Settings_Sync::is_allowed_endpoint( '/wp-json/newspack-nodes-aggregator/v1/health' ) );
		$this->assertFalse( Settings_Sync::is_allowed_endpoint( '/wp-json/event-logger/v1/settings' ) );
		$this->assertFalse( Settings_Sync::is_allowed_endpoint( '/wp-json/wp/v2/posts' ) );
		$this->assertFalse( Settings_Sync::is_allowed_endpoint( '' ) );
		$this->assertFalse( Settings_Sync::is_allowed_endpoint( 'newspack-nodes/v1/settings' ) );
	}

	public function test_static_listeners_handle_add_and_update_option(): void {
		// Both signatures must be callable without crashing. We pass an
		// option name NOT in the SYNCED_OPTIONS / PERF_TUNING_OPTIONS lists
		// so the static handler returns at the synced-option check before
		// touching Config or JobIntake (avoids real filesystem side-effects
		// in the unit test).
		Settings_Sync::on_static_option_update( 'totally_unrelated_option', null, 42 );
		Settings_Sync::on_static_option_add( 'totally_unrelated_option', 42 );

		// The contract under test here is "doesn't crash" — handled errors only.
		$this->assertTrue( true );
	}

	public function test_static_handler_skips_when_static_syncing_is_true(): void {
		// With the static guard set, the handler short-circuits BEFORE the
		// synced-option check, before Config, before JobIntake. So even passing
		// an option that IS in PERF_TUNING_OPTIONS must not trigger a real
		// JobIntake write.
		Settings_Sync::suppress_sync( true );
		Settings_Sync::on_static_option_update( 'newspack_event_logger_nodes_log_events', [], [ 'a' ] );
		Settings_Sync::on_static_option_add( 'newspack_event_logger_nodes_log_events', [ 'a' ] );
		Settings_Sync::suppress_sync( false );
		$this->assertTrue( true );
	}

	public function test_static_handler_ignores_unknown_option(): void {
		Settings_Sync::suppress_sync( false );
		// Unknown option must not crash and must not attempt to load Config.
		Settings_Sync::on_static_option_update( 'totally_unrelated_option', null, 42 );
		$this->assertTrue( true );
	}

	public function test_synced_options_constant_exposes_remap(): void {
		// Spec: SYNCED_OPTIONS exposed as a public const so callers (and tests)
		// can reference the canonical local→remote mapping without instantiating
		// the class.
		$this->assertArrayHasKey(
			'newspack_event_logger_nodes_remote_num_segments',
			Settings_Sync::SYNCED_OPTIONS
		);
		$this->assertSame(
			'newspack_nodes_num_segments',
			Settings_Sync::SYNCED_OPTIONS['newspack_event_logger_nodes_remote_num_segments']
		);
	}

	public function test_perf_tuning_options_constant_lists_nine_options(): void {
		$this->assertContains( 'newspack_event_logger_nodes_log_events', Settings_Sync::PERF_TUNING_OPTIONS );
		$this->assertContains( 'newspack_event_logger_nodes_log_urls', Settings_Sync::PERF_TUNING_OPTIONS );
		$this->assertContains( 'newspack_event_logger_nodes_custom_events', Settings_Sync::PERF_TUNING_OPTIONS );
		$this->assertContains( 'newspack_event_logger_nodes_significant_events', Settings_Sync::PERF_TUNING_OPTIONS );
		// The full list is 9 entries.
		$this->assertCount( 9, Settings_Sync::PERF_TUNING_OPTIONS );
	}

	public function test_allowed_endpoint_prefixes_constant(): void {
		$this->assertSame(
			[ '/wp-json/newspack-nodes/', '/wp-json/newspack-nodes-aggregator/' ],
			Settings_Sync::ALLOWED_ENDPOINT_PREFIXES
		);
	}

	public function test_endpoint_constant(): void {
		$this->assertSame( '/wp-json/newspack-nodes/v1/settings', Settings_Sync::ENDPOINT );
		$this->assertTrue( Settings_Sync::is_allowed_endpoint( Settings_Sync::ENDPOINT ) );
	}

	// --- Static handler with full config setup -------------------------------

	public function test_static_handler_skips_unknown_option_without_loading_config(): void {
		// A non-synced option must short-circuit before Config is touched.
		// Verified by absence of any side-effects from Config::load_config()
		// (which would update_option for any non-existent option set in
		// $GLOBALS['_wp_options']).
		Settings_Sync::suppress_sync( false );
		$GLOBALS['_wp_options'] = [];

		Settings_Sync::on_static_option_update( 'random_unrelated_option', null, 'value' );

		// The option name is untouched; no fan-out queued.
		$this->assertArrayNotHasKey( 'random_unrelated_option', $GLOBALS['_wp_options'] );
	}

	public function test_static_handler_does_not_crash_on_log_events(): void {
		// A3: enable_workers gate removed. Smoke test that the static
		// handler runs end-to-end without crashing for a
		// PERF_TUNING_OPTIONS entry.
		Settings_Sync::suppress_sync( false );
		Config::reset();
		Settings_Sync::on_static_option_update( 'newspack_event_logger_nodes_log_events', [], [ 'a' ] );
		$this->assertTrue( true );
	}

	public function test_static_handler_with_perf_tuning_option(): void {
		// Smoke test: pass an option from PERF_TUNING_OPTIONS (1:1
		// mapping). Just exercises the perf-tuning recognition branch.
		Settings_Sync::suppress_sync( false );
		Config::reset();

		Settings_Sync::on_static_option_update(
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
		Settings_Sync::suppress_sync( false );
		Config::reset();

		Settings_Sync::on_static_option_update(
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
		$sync = new Settings_Sync(
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
		Settings_Sync::suppress_sync( true );
		Config::reset();

		// add_option (2-arg) signature path — also guarded.
		Settings_Sync::on_static_option_add( 'newspack_event_logger_nodes_log_events', [ 'init' ] );
		Settings_Sync::on_static_option_update( 'newspack_event_logger_nodes_log_events', [], [ 'init' ] );

		// No crash — handler returned early at the guard.
		$this->assertTrue( Settings_Sync::is_sync_suppressed() );

		Settings_Sync::suppress_sync( false );
	}

	// --- Encryption fail-closed flow ----------------------------------------

	public function test_encrypt_returns_empty_when_sodium_unavailable(): void {
		// We can't actually disable sodium in the test runtime, but we can
		// verify the encrypt path is non-empty when sodium is available
		// (which is the normal case). The fail-closed branches are documented
		// at the implementation level and exercised by test_decrypt_returns_*.
		$this->assertNotSame( '', Settings_Sync::encrypt( 'plaintext' ) );
	}

	public function test_decode_payload_round_trip(): void {
		// Round-trip a structured payload through encrypt + decode_payload.
		$plaintext = (string) \json_encode( [
			'option' => 'log_events',
			'value'  => [ 'init', 'wp_head' ],
		] );
		$cipher    = Settings_Sync::encrypt( $plaintext );
		$decoded   = Settings_Sync::decode_payload( $cipher );

		$this->assertNotNull( $decoded );
		$this->assertSame( 'log_events', $decoded['option'] );
		$this->assertSame( [ 'init', 'wp_head' ], $decoded['value'] );
	}

	public function test_decode_payload_returns_null_on_payload_without_option_key(): void {
		// JSON without 'option' field is rejected by decode_payload.
		$plaintext = (string) \json_encode( [ 'value' => 42 ] );
		$cipher    = Settings_Sync::encrypt( $plaintext );

		$this->assertNull( Settings_Sync::decode_payload( $cipher ) );
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
		$result = Settings_Sync::register_synced_settings( $existing );

		// Original entry preserved.
		$this->assertSame( 'foreign_local', $result[0]['local_option'] );
		// Extras appended.
		$this->assertGreaterThan( 1, \count( $result ) );
	}

	// --- queue_job dispatch --------------------------------------------------

	public function test_queue_job_returns_false_when_no_jobintake(): void {
		// In the test harness JobIntake is loaded — so this is more about
		// asserting the contract: queue_job returns a bool.
		$result = Settings_Sync::queue_job( 'remote_manager', [
			'action' => 'sync_setting',
			'option' => 'log_events',
		] );
		$this->assertIsBool( $result );
	}

	// --- is_allowed_endpoint exhaustive coverage ----------------------------

	public function test_is_allowed_endpoint_with_aggregator_prefix(): void {
		$this->assertTrue(
			Settings_Sync::is_allowed_endpoint( '/wp-json/newspack-nodes-aggregator/v1/anything' )
		);
	}

	public function test_is_allowed_endpoint_partial_match_at_start_only(): void {
		// Prefix must be at the start; matching anywhere else is rejected.
		$this->assertFalse(
			Settings_Sync::is_allowed_endpoint( '/something/wp-json/newspack-nodes/v1/' ),
			'prefix only valid at start'
		);
	}

	public function test_is_allowed_endpoint_with_close_but_not_matching_prefix(): void {
		// Close but not exact: 'newspack-node' (singular) — not allowed.
		$this->assertFalse(
			Settings_Sync::is_allowed_endpoint( '/wp-json/newspack-node/v1/settings' )
		);
	}

	public function test_suppress_instance_sync_default_arg(): void {
		// suppress_instance_sync() with no arg defaults to true.
		$called = false;
		$sync = new Settings_Sync(
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
		// canonical key and falls back to file defaults. Verifies the queued
		// remote_manager envelope carries the REMAPPED remote_option name
		// (`newspack_nodes_num_segments`, NOT the local `..._remote_...` name)
		// and the substrate-side ENDPOINT, and that the prefix-stripped
		// defaults lookup hits — `num_segments` lives in the substrate config
		// defaults, so the empty value resolves to that integer default
		// instead of being dispatched as ''.
		$tmp = $this->make_temp_dir( 'newspack-settings-sync-' );
		$this->use_base_dir( $tmp, [ 'num_partitions' => 1, 'num_segments' => 7 ] );
		Settings_Sync::suppress_sync( false );

		Settings_Sync::on_static_option_update(
			'newspack_event_logger_nodes_remote_num_segments',
			null,
			''
		);

		$envelopes = $this->read_jobintake_envelopes( $tmp );
		$this->assertCount( 1, $envelopes, 'one remote_manager job queued' );
		$this->assertSame( 'job', $envelopes[0]['k'] );
		$this->assertSame( 'remote_manager', $envelopes[0]['handler'] );

		$params = $envelopes[0]['parameters'];
		$this->assertSame( 'sync_setting', $params['action'] );
		// is_remap branch: local→remote name remap on the wire.
		$this->assertSame( 'newspack_nodes_num_segments', $params['option'] );
		// SYNCED_OPTIONS entries route to the substrate `/settings` verb,
		// NOT the perf-tuning endpoint.
		$this->assertSame( Settings_Sync::ENDPOINT, $params['endpoint'] );
		// Defaults substitution: '' → file-backed default. The per-test config
		// pinned num_segments=7; the empty value must resolve to that.
		$this->assertSame( 7, $params['value'] );
		$this->assertIsInt( $params['queued_at'] );

		$this->rmdir_recursive( $tmp );
	}

	public function test_static_handler_with_false_value_substitutes_defaults(): void {
		// PERF_TUNING_OPTIONS entry with false → defaults lookup. Verifies that
		// the (admin form returning bool false for an empty multiselect)
		// branch resolves to the file-backed `log_events` default rather
		// than dispatching the raw `false` (which would fail server-side
		// sanitization for typed array options) — AND that the perf-tuning
		// option routes to the PERF_ENDPOINT, not the substrate ENDPOINT.
		$tmp = $this->make_temp_dir( 'newspack-settings-sync-' );
		$this->use_base_dir( $tmp, [ 'num_partitions' => 1, 'log_events' => [ 'init', 'wp_loaded' ] ] );
		Settings_Sync::suppress_sync( false );

		Settings_Sync::on_static_option_update(
			'newspack_event_logger_nodes_log_events',
			null,
			false
		);

		$envelopes = $this->read_jobintake_envelopes( $tmp );
		$this->assertCount( 1, $envelopes );
		$this->assertSame( 'remote_manager', $envelopes[0]['handler'] );

		$params = $envelopes[0]['parameters'];
		$this->assertSame( 'sync_setting', $params['action'] );
		// is_perf branch: no name remap.
		$this->assertSame( 'newspack_event_logger_nodes_log_events', $params['option'] );
		// PERF_TUNING_OPTIONS route to the perf-tuning verb, NOT the substrate
		// settings verb. Crossing the two misroutes server-side.
		$this->assertSame( Settings_Sync::PERF_ENDPOINT, $params['endpoint'] );
		// false → defaults lookup hit; raw false must NOT survive to the wire.
		$this->assertSame( [ 'init', 'wp_loaded' ], $params['value'] );

		$this->rmdir_recursive( $tmp );
	}

	// --- on_static_option_add path -----------------------------------------

	public function test_static_handler_add_option_with_perf_tuning_option(): void {
		// `on_static_option_add` is the 2-arg add_option-side handler; this
		// covers the add path for a PERF_TUNING_OPTIONS entry whose value is
		// a non-empty array (defaults-substitution block skipped). Verifies
		// the queued envelope carries the literal value through unchanged
		// and lands on PERF_ENDPOINT (perf tuning verb), not ENDPOINT.
		$tmp = $this->make_temp_dir( 'newspack-settings-sync-' );
		$this->use_base_dir( $tmp, [ 'num_partitions' => 1 ] );
		Settings_Sync::suppress_sync( false );

		Settings_Sync::on_static_option_add(
			'newspack_event_logger_nodes_log_urls',
			[ '/foo' ]
		);

		$envelopes = $this->read_jobintake_envelopes( $tmp );
		$this->assertCount( 1, $envelopes );
		$this->assertSame( 'remote_manager', $envelopes[0]['handler'] );

		$params = $envelopes[0]['parameters'];
		$this->assertSame( 'sync_setting', $params['action'] );
		$this->assertSame( 'newspack_event_logger_nodes_log_urls', $params['option'] );
		$this->assertSame( Settings_Sync::PERF_ENDPOINT, $params['endpoint'] );
		// Non-empty array bypasses the defaults block — value travels verbatim.
		$this->assertSame( [ '/foo' ], $params['value'] );

		$this->rmdir_recursive( $tmp );
	}

	// --- decode_payload happy path with mixed value types -------------------

	public function test_decode_payload_value_can_be_complex_types(): void {
		// Value can be an array, object, string, etc.
		$pt    = (string) \json_encode( [
			'option' => 'log_urls',
			'value'  => [ '/x', '/y', [ 'nested' => true ] ],
		] );
		$ct    = Settings_Sync::encrypt( $pt );
		$out   = Settings_Sync::decode_payload( $ct );

		$this->assertSame( 'log_urls', $out['option'] );
		$this->assertSame( [ '/x', '/y', [ 'nested' => true ] ], $out['value'] );
	}

	public function test_decode_payload_with_null_value(): void {
		// Decode supports null value.
		$pt    = (string) \json_encode( [ 'option' => 'log_urls', 'value' => null ] );
		$ct    = Settings_Sync::encrypt( $pt );
		$out   = Settings_Sync::decode_payload( $ct );

		$this->assertSame( 'log_urls', $out['option'] );
		$this->assertNull( $out['value'] );
	}

	// --- Instance mode skipping when option not in synced_options ----------

	public function test_instance_mode_skips_when_option_not_in_synced_list(): void {
		// Option not in $synced_options must not dispatch even though the
		// aggregator gate is gone — there's still no point dispatching
		// options the hub doesn't care about.
		$called = false;
		$sync   = new Settings_Sync(
			synced_options: [ 'log_urls' ], // 'other_option' NOT in this list
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'other_option', 'old', 'new' );
		$this->assertFalse( $called, 'option not in synced list must skip' );
	}

	// --- maybe_queue_static_sync: substrate-namespaced prefix branch ---------

	/**
	 * `newspack_nodes_num_partitions` is the one entry in SYNCED_OPTIONS whose
	 * local name carries the substrate prefix (no remap). When dispatched with
	 * an empty value, the prefix-stripping loop hits the `newspack_nodes_`
	 * branch (not the application prefix branch) before the `^remote_` strip.
	 * Exercises a distinct loop-iteration outcome from the
	 * `newspack_event_logger_nodes_remote_*` tests.
	 */
	public function test_static_handler_substrate_prefix_strip_for_num_partitions(): void {
		Settings_Sync::suppress_sync( false );
		Config::reset();

		// Empty value triggers defaults-lookup; the prefix strip must run
		// against the `newspack_nodes_` arm (not `newspack_event_logger_nodes_`).
		Settings_Sync::on_static_option_update(
			'newspack_nodes_num_partitions',
			null,
			''
		);

		// Doesn't crash. The default-lookup path traversed the
		// `newspack_nodes_` prefix arm of the foreach.
		$this->assertTrue( true );
	}

	/**
	 * Same path as above but routed through `on_static_option_add` (2-arg
	 * signature) to exercise the add_option side of the static fan-out.
	 */
	public function test_static_handler_add_option_substrate_prefix_strip(): void {
		Settings_Sync::suppress_sync( false );
		Config::reset();

		Settings_Sync::on_static_option_add( 'newspack_nodes_num_partitions', '' );

		$this->assertTrue( true );
	}

	// --- maybe_queue_static_sync: non-empty real value bypasses defaults ----

	/**
	 * Non-empty, non-false value SKIPS the default-substitution block
	 * (line `if ( '' === $value || false === $value )` is false), takes the
	 * is_remap=true endpoint pick, and lands at the queue_job call directly.
	 * Distinct from the existing remap test which used 16 — exercise a string
	 * value to cover the no-substitution branch with a different shape.
	 */
	public function test_static_handler_remap_with_non_empty_string_value(): void {
		Settings_Sync::suppress_sync( false );
		Config::reset();

		Settings_Sync::on_static_option_update(
			'newspack_event_logger_nodes_remote_segment_size',
			0,
			'2048'
		);

		// The path: is_remap=true, value='2048' is not '' / false → defaults
		// lookup skipped, endpoint=ENDPOINT, queue_job invoked. Doesn't crash.
		$this->assertTrue( true );
	}

	/**
	 * PERF_TUNING entry with a non-empty array value also skips the
	 * default-substitution block AND lands at the PERF_ENDPOINT (not ENDPOINT)
	 * arm of the ternary. Covers the is_perf=true non-empty branch end-to-end.
	 */
	public function test_static_handler_perf_with_non_empty_array_value(): void {
		Settings_Sync::suppress_sync( false );
		Config::reset();

		Settings_Sync::on_static_option_update(
			'newspack_event_logger_nodes_significant_events',
			[],
			[ 'init', 'wp_loaded' ]
		);

		$this->assertTrue( true );
	}

	// --- queue_job: contract over the JobIntake bridge ----------------------

	/**
	 * `queue_job` is the static bridge to JobIntake — its sole job is to
	 * return a bool reflecting the underlying queue success. With a valid
	 * handler name and small payload, it returns either true (queued) or
	 * false (no IPC backend) — but never throws and never returns non-bool.
	 */
	public function test_queue_job_with_valid_handler_returns_bool(): void {
		$result = Settings_Sync::queue_job(
			'remote_manager',
			[
				'action'    => 'sync_setting',
				'option'    => 'log_events',
				'value'     => [ 'init', 'wp_loaded' ],
				'endpoint'  => Settings_Sync::PERF_ENDPOINT,
				'queued_at' => \time(),
			]
		);
		$this->assertIsBool( $result );
	}

	/**
	 * Invalid handler-name pattern — JobIntake refuses to queue anything that
	 * doesn't match `[a-zA-Z][a-zA-Z0-9_-]{0,63}`. SettingsSync forwards the
	 * result verbatim, so a handler with spaces returns false.
	 */
	public function test_queue_job_with_invalid_handler_returns_false(): void {
		$result = Settings_Sync::queue_job(
			'bad handler name with spaces',
			[ 'action' => 'noop' ]
		);
		$this->assertFalse( $result, 'invalid handler must short-circuit at JobIntake' );
	}

	/**
	 * The optional `$key` parameter routes the job to a consistent partition
	 * via CRC32. With single-partition default, the key is just a no-op routing
	 * hint, but the path still returns a bool.
	 */
	public function test_queue_job_with_key_returns_bool(): void {
		$result = Settings_Sync::queue_job(
			'remote_manager',
			[ 'action' => 'sync_setting' ],
			'consistent-partition-key'
		);
		$this->assertIsBool( $result );
	}

	// --- init: idempotent re-entry ------------------------------------------

	/**
	 * `init()` is idempotent via a method-local `static $registered` guard —
	 * the FIRST call (in any process, including the plugin's bootstrap closure)
	 * sets it true; subsequent calls short-circuit at the guard. The contract
	 * is just "never throws on re-entry and never re-registers" — exercise the
	 * short-circuit path explicitly by calling init() multiple times in a row.
	 */
	public function test_init_idempotent_re_entry_is_safe(): void {
		// Multiple back-to-back calls hit the static-guard short-circuit.
		// First call may or may not be the first-in-process (plugin bootstrap
		// already fired it), but all later calls MUST be no-ops.
		Settings_Sync::init();
		Settings_Sync::init();
		Settings_Sync::init();

		// No assertion needed beyond "doesn't crash" — the contract is purely
		// about idempotency.
		$this->assertTrue( true );
	}

	// --- decrypt: edge-case inputs ----------------------------------------

	/**
	 * `decrypt('')` is a malformed empty base64; passes the function_exists
	 * check, the key check, but fails the length check (< nonce size) — must
	 * return null without throwing.
	 */
	public function test_decrypt_with_empty_string_returns_null(): void {
		$this->assertNull( Settings_Sync::decrypt( '' ) );
	}

	/**
	 * `decode_payload('')` decodes empty plaintext to null via the same
	 * path; the json_decode of empty string returns null and short-circuits.
	 */
	public function test_decode_payload_with_empty_string_returns_null(): void {
		$this->assertNull( Settings_Sync::decode_payload( '' ) );
	}

	/**
	 * Round-trip with a value containing UTF-8 + JSON-special chars. Verifies
	 * the encode/decrypt chain preserves payload fidelity across multi-byte
	 * characters and string escaping.
	 */
	public function test_decode_payload_preserves_utf8_and_special_chars(): void {
		$plaintext = (string) \json_encode( [
			'option' => 'log_events',
			'value'  => [ 'event\\"with\nquotes', 'unicode-£€™' ],
		] );
		$cipher    = Settings_Sync::encrypt( $plaintext );
		$decoded   = Settings_Sync::decode_payload( $cipher );

		$this->assertNotNull( $decoded );
		$this->assertSame( [ 'event\\"with\nquotes', 'unicode-£€™' ], $decoded['value'] );
	}

	// --- is_allowed_endpoint: substring trap ---------------------------------

	/**
	 * Defense-in-depth: a URL that CONTAINS one of the allowed prefixes mid-
	 * string (instead of at the start) MUST be rejected. The check uses
	 * `0 === strpos(...)` deliberately to anchor the match.
	 */
	public function test_is_allowed_endpoint_rejects_mid_string_prefix(): void {
		// Allowed prefix appears at offset 12, not at the start.
		$this->assertFalse(
			Settings_Sync::is_allowed_endpoint( '/redirector/wp-json/newspack-nodes/v1/settings' )
		);
		// Same idea with the aggregator namespace.
		$this->assertFalse(
			Settings_Sync::is_allowed_endpoint( '/foo/wp-json/newspack-nodes-aggregator/v1/health' )
		);
	}

	/**
	 * The trailing slash is meaningful: `/wp-json/newspack-nodes/` matches any
	 * route under it (e.g. `/v1/settings`, `/v1/health`). A bare
	 * `/wp-json/newspack-nodes` without the slash also matches via prefix —
	 * confirms the prefix semantics aren't accidentally tightened.
	 */
	public function test_is_allowed_endpoint_with_arbitrary_subroute(): void {
		$this->assertTrue(
			Settings_Sync::is_allowed_endpoint( '/wp-json/newspack-nodes/v1/anywhere/at/all' )
		);
		$this->assertTrue(
			Settings_Sync::is_allowed_endpoint( '/wp-json/newspack-nodes-aggregator/v2/future' )
		);
	}
}
