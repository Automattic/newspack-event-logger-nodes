<?php
/**
 * ServerRegistry tests.
 *
 * Covers encryption round-trip, tampering rejection, legacy fallback, write
 * verification, config-file merging, validation rules, and the audit trail.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\Config;
use Newspack_Event_Logger_Nodes\ServerRegistry;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( ServerRegistry::class )]
class ServerRegistryTest extends TestCase {

	/**
	 * Captured error_log lines for the current test.
	 *
	 * @var list<string>
	 */
	private array $captured_logs = [];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wp_options']      = [];
		$GLOBALS['_wp_actions']      = [];
		$GLOBALS['_current_user_id'] = 42;

		// Reset Config cache so each test starts from a clean filter chain.
		Config::reset();

		// Capture error_log via a temp file. ini_set returns the previous
		// value so we can restore it in tearDown.
		$this->captured_logs = [];
		$tmp                 = \tmpfile();
		$meta                = \stream_get_meta_data( $tmp );
		$GLOBALS['_test_error_log_path']   = $meta['uri'];
		$GLOBALS['_test_error_log_handle'] = $tmp;
		$GLOBALS['_test_prev_error_log']   = \ini_set( 'error_log', $meta['uri'] );
	}

	protected function tearDown(): void {
		if ( isset( $GLOBALS['_test_prev_error_log'] ) && false !== $GLOBALS['_test_prev_error_log'] ) {
			\ini_set( 'error_log', (string) $GLOBALS['_test_prev_error_log'] );
		}
		if ( isset( $GLOBALS['_test_error_log_handle'] ) && \is_resource( $GLOBALS['_test_error_log_handle'] ) ) {
			\fclose( $GLOBALS['_test_error_log_handle'] );
		}
		unset(
			$GLOBALS['_test_error_log_path'],
			$GLOBALS['_test_error_log_handle'],
			$GLOBALS['_test_prev_error_log']
		);
		Config::reset();
		parent::tearDown();
	}

	/**
	 * Read the captured error_log buffer.
	 */
	private function read_error_log(): string {
		$path = $GLOBALS['_test_error_log_path'] ?? null;
		if ( ! $path || ! \file_exists( $path ) ) {
			return '';
		}
		return (string) \file_get_contents( $path );
	}

	// ---------------------------------------------------------------------
	// Encryption round-trip + tamper rejection.
	// ---------------------------------------------------------------------

	public function test_add_then_get_round_trips_credentials(): void {
		$reg = new ServerRegistry();
		$ok  = $reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_username' => 'admin',
				'auth_password' => 'app-pass-12345',
			]
		);
		$this->assertTrue( $ok );

		$reg->reset_cache();
		$entry = $reg->get( 'site-a' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'https://a.example.com', $entry['url'] );
		$this->assertSame( 'admin', $entry['auth_username'] );
		$this->assertSame( 'app-pass-12345', $entry['auth_password'] );
	}

	public function test_stored_password_is_encrypted_at_rest(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_username' => 'admin',
				'auth_password' => 'plaintext-secret-XYZ',
			]
		);
		$raw = \get_option( ServerRegistry::OPTION_KEY );
		$this->assertIsArray( $raw );
		$serialized = (string) \json_encode( $raw );
		$this->assertStringNotContainsString( 'plaintext-secret-XYZ', $serialized );
		$this->assertStringStartsWith( ServerRegistry::ENCRYPTED_PREFIX, $raw['site-a']['auth_password'] );
	}

	public function test_tampered_ciphertext_decrypts_to_empty(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_password' => 'real-secret',
			]
		);

		// Corrupt the ciphertext at rest by flipping a byte after the prefix.
		$opt = \get_option( ServerRegistry::OPTION_KEY );
		$enc = $opt['site-a']['auth_password'];
		$body         = \substr( $enc, \strlen( ServerRegistry::ENCRYPTED_PREFIX ) );
		$decoded      = \base64_decode( $body, true );
		$decoded[20]  = \chr( \ord( $decoded[20] ) ^ 0xFF );
		$opt['site-a']['auth_password'] = ServerRegistry::ENCRYPTED_PREFIX . \base64_encode( $decoded );
		\update_option( ServerRegistry::OPTION_KEY, $opt );

		$reg->reset_cache();
		$entry = $reg->get( 'site-a' );
		$this->assertNotNull( $entry );
		$this->assertSame( '', $entry['auth_password'] );
	}

	public function test_truncated_ciphertext_decrypts_to_empty(): void {
		$reg = new ServerRegistry();
		// Hand-craft an encrypted-looking value that is shorter than the nonce.
		$opt = [
			'site-x' => [
				'url'           => 'https://x.example.com',
				'auth_username' => 'u',
				'auth_password' => ServerRegistry::ENCRYPTED_PREFIX . \base64_encode( 'short' ),
				'enabled'       => true,
				'logs'          => [ 'firehose.log' ],
			],
		];
		\update_option( ServerRegistry::OPTION_KEY, $opt );

		$reg->reset_cache();
		$entry = $reg->get( 'site-x' );
		$this->assertNotNull( $entry );
		$this->assertSame( '', $entry['auth_password'] );
	}

	// ---------------------------------------------------------------------
	// Write verification (re-read after update_option).
	// ---------------------------------------------------------------------

	public function test_add_returns_true_when_entry_lands_in_option(): void {
		$reg = new ServerRegistry();
		$ok  = $reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_password' => 'p',
			]
		);
		$this->assertTrue( $ok );
		$opt = \get_option( ServerRegistry::OPTION_KEY );
		$this->assertArrayHasKey( 'site-a', $opt );
	}

	public function test_remove_verifies_via_re_read(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_password' => 'p',
			]
		);
		$ok = $reg->remove( 'site-a' );
		$this->assertTrue( $ok );
		$opt = \get_option( ServerRegistry::OPTION_KEY, [] );
		$this->assertArrayNotHasKey( 'site-a', $opt );
	}

	public function test_update_returns_true_on_no_op_via_re_read(): void {
		// Pattern note: WP's update_option() returns false when the new value
		// equals the old (no DB write needed). The verify-by-re-read pattern
		// makes the registry's update() return true regardless, because the
		// post-condition is what matters, not the return code.
		//
		// We use a config without auth_password so re-encrypt-with-new-nonce
		// can't perturb the stored value between calls — second update() is a
		// genuine no-op against the option array.
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url' => 'https://a.example.com',
			]
		);
		$first_opt = \get_option( ServerRegistry::OPTION_KEY );

		// Apply the same partial twice — second call is a no-op at the WP layer.
		$first_call  = $reg->update( 'site-a', [ 'enabled' => false ] );
		$mid_opt     = \get_option( ServerRegistry::OPTION_KEY );
		$second_call = $reg->update( 'site-a', [ 'enabled' => false ] );
		$final_opt   = \get_option( ServerRegistry::OPTION_KEY );

		$this->assertTrue( $first_call );
		$this->assertTrue( $second_call );
		// The two updates produce identical option arrays — the no-op case.
		$this->assertSame( $mid_opt, $final_opt );
	}

	// ---------------------------------------------------------------------
	// Config-file defaults + WP option overlay merging.
	// ---------------------------------------------------------------------

	public function test_get_all_merges_config_defaults_with_wp_option(): void {
		// Inject an `aggregator_servers` entry into Config defaults via the
		// option-schema route: register the schema key, then set a WP option
		// that load_config will pull in. We simulate the file-defaults path
		// by overriding load_config_defaults via reflection.
		$defaults_ref = new \ReflectionProperty( Config::class, 'config_defaults' );
		$defaults_ref->setAccessible( true );
		$defaults_ref->setValue(
			null,
			[
				'base_directory'     => '/tmp/newspack-nodes',
				'aggregator_servers' => [
					'config-only' => [
						'url'           => 'https://config.example.com',
						'auth_username' => 'config-admin',
						'auth_password' => '',
						'enabled'       => true,
						'logs'          => [ 'firehose.log' ],
					],
				],
			]
		);
		// Also seed both cached configs so load_config('full') returns the
		// same shape — get_all() reads load_config('full').
		$full_ref = new \ReflectionProperty( Config::class, 'config_full' );
		$full_ref->setAccessible( true );
		$full_ref->setValue(
			null,
			[
				'aggregator_servers' => [
					'config-only' => [
						'url'           => 'https://config.example.com',
						'auth_username' => 'config-admin',
						'auth_password' => '',
						'enabled'       => true,
						'logs'          => [ 'firehose.log' ],
					],
				],
			]
		);

		// Add a WP-option-managed entry too.
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'wp-only' => [
					'url'           => 'https://wp.example.com',
					'auth_username' => 'wp-admin',
					'auth_password' => '',
					'enabled'       => true,
					'logs'          => [ 'firehose.log' ],
				],
			]
		);

		$reg = new ServerRegistry();
		$all = $reg->get_all();

		$this->assertArrayHasKey( 'config-only', $all );
		$this->assertArrayHasKey( 'wp-only', $all );
		$this->assertSame( 'https://config.example.com', $all['config-only']['url'] );
		$this->assertSame( 'https://wp.example.com', $all['wp-only']['url'] );
	}

	public function test_wp_option_overrides_config_default_on_collision(): void {
		$full_ref = new \ReflectionProperty( Config::class, 'config_full' );
		$full_ref->setAccessible( true );
		$full_ref->setValue(
			null,
			[
				'aggregator_servers' => [
					'shared-id' => [
						'url'     => 'https://config.example.com',
						'enabled' => true,
					],
				],
			]
		);

		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'shared-id' => [
					'url'     => 'https://override.example.com',
					'enabled' => false,
					'logs'    => [ 'firehose.log' ],
				],
			]
		);

		$reg   = new ServerRegistry();
		$entry = $reg->get( 'shared-id' );
		$this->assertSame( 'https://override.example.com', $entry['url'] );
		$this->assertFalse( $entry['enabled'] );
	}

	public function test_is_config_server_distinguishes_file_from_option(): void {
		// File-only defaults — bypass the merged 'full' view that would conflate
		// WP-option entries with file ones.
		$defaults_ref = new \ReflectionProperty( Config::class, 'config_defaults' );
		$defaults_ref->setAccessible( true );
		$defaults_ref->setValue(
			null,
			[
				'aggregator_servers' => [
					'from-file' => [
						'url' => 'https://file.example.com',
					],
				],
			]
		);
		// Also pre-populate 'full' so get_all() doesn't trigger a fresh load.
		$full_ref = new \ReflectionProperty( Config::class, 'config_full' );
		$full_ref->setAccessible( true );
		$full_ref->setValue(
			null,
			[
				'aggregator_servers' => [
					'from-file' => [
						'url' => 'https://file.example.com',
					],
				],
			]
		);

		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'from-option' => [
					'url'     => 'https://option.example.com',
					'enabled' => true,
				],
			]
		);

		$reg = new ServerRegistry();
		$this->assertTrue( $reg->is_config_server( 'from-file' ) );
		$this->assertFalse( $reg->is_config_server( 'from-option' ) );
		$this->assertFalse( $reg->is_config_server( 'unknown-id' ) );
	}

	public function test_config_server_only_enabled_is_editable(): void {
		// File-defined entry.
		$defaults_ref = new \ReflectionProperty( Config::class, 'config_defaults' );
		$defaults_ref->setAccessible( true );
		$defaults_ref->setValue(
			null,
			[
				'aggregator_servers' => [
					'pinned' => [
						'url'           => 'https://pinned.example.com',
						'auth_username' => 'pinned-user',
						'auth_password' => '',
						'enabled'       => true,
						'logs'          => [ 'firehose.log' ],
					],
				],
			]
		);
		$full_ref = new \ReflectionProperty( Config::class, 'config_full' );
		$full_ref->setAccessible( true );
		$full_ref->setValue(
			null,
			[
				'aggregator_servers' => [
					'pinned' => [
						'url'           => 'https://pinned.example.com',
						'auth_username' => 'pinned-user',
						'auth_password' => '',
						'enabled'       => true,
						'logs'          => [ 'firehose.log' ],
					],
				],
			]
		);

		$reg = new ServerRegistry();

		// URL change should be ignored on config server.
		$ok = $reg->update( 'pinned', [ 'url' => 'https://hacked.example.com' ] );
		// The partial is rewritten to ['enabled' => ...] only when 'enabled' is
		// in the partial; without 'enabled' present the call returns false.
		$this->assertFalse( $ok );

		// enabled toggle is allowed.
		$ok = $reg->update( 'pinned', [ 'enabled' => false ] );
		$this->assertTrue( $ok );
		$reg->reset_cache();
		$entry = $reg->get( 'pinned' );
		$this->assertFalse( $entry['enabled'] );
		$this->assertSame( 'https://pinned.example.com', $entry['url'] ); // url unchanged.
	}

	public function test_config_server_cannot_be_removed(): void {
		$defaults_ref = new \ReflectionProperty( Config::class, 'config_defaults' );
		$defaults_ref->setAccessible( true );
		$defaults_ref->setValue(
			null,
			[
				'aggregator_servers' => [
					'pinned' => [
						'url' => 'https://pinned.example.com',
					],
				],
			]
		);
		$full_ref = new \ReflectionProperty( Config::class, 'config_full' );
		$full_ref->setAccessible( true );
		$full_ref->setValue(
			null,
			[
				'aggregator_servers' => [
					'pinned' => [
						'url' => 'https://pinned.example.com',
					],
				],
			]
		);

		$reg = new ServerRegistry();
		$this->assertFalse( $reg->remove( 'pinned' ) );
	}

	// ---------------------------------------------------------------------
	// Validation: HTTPS, byte caps, allowlists.
	// ---------------------------------------------------------------------

	public function test_validate_rejects_http_url(): void {
		$reg = new ServerRegistry();
		$ok  = $reg->add(
			'site-a',
			[
				'url'           => 'http://insecure.example.com',
				'auth_password' => 'x',
			]
		);
		$this->assertFalse( $ok );
	}

	public function test_validate_rejects_missing_url(): void {
		$reg = new ServerRegistry();
		$this->assertFalse( $reg->add( 'site-a', [ 'auth_password' => 'x' ] ) );
	}

	public function test_validate_rejects_non_string_url(): void {
		$reg = new ServerRegistry();
		$this->assertFalse( $reg->add( 'site-a', [ 'url' => 12345 ] ) );
	}

	public function test_username_capped_at_256_bytes(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_username' => \str_repeat( 'a', 500 ),
				'auth_password' => 'p',
			]
		);
		$entry = $reg->get( 'site-a' );
		$this->assertSame( 256, \strlen( $entry['auth_username'] ) );
	}

	public function test_password_capped_at_256_bytes(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_password' => \str_repeat( 'p', 500 ),
			]
		);
		$reg->reset_cache();
		$entry = $reg->get( 'site-a' );
		$this->assertSame( 256, \strlen( $entry['auth_password'] ) );
	}

	public function test_logs_filename_allowlist_filters_invalid_entries(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_password' => 'p',
				'logs'          => [
					'firehose.log',     // valid
					'jobs.log',         // valid
					'../etc/passwd',    // path traversal — rejected
					'firehose',         // missing .log — rejected
					'has spaces.log',   // space — rejected
					'a/b.log',          // slash — rejected
				],
			]
		);
		$entry = $reg->get( 'site-a' );
		$this->assertSame( [ 'firehose.log', 'jobs.log' ], $entry['logs'] );
	}

	public function test_logs_falls_back_to_firehose_when_all_filtered(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_password' => 'p',
				'logs'          => [ 'invalid', '../escape', 'no-extension' ],
			]
		);
		$entry = $reg->get( 'site-a' );
		$this->assertSame( [ 'firehose.log' ], $entry['logs'] );
	}

	public function test_id_regex_rejects_invalid_characters(): void {
		$reg = new ServerRegistry();
		$cases = [
			'',                     // empty
			'a b',                  // space
			'a.b',                  // dot
			'a/b',                  // slash
			'a@b',                  // @
			'../escape',            // path traversal
			\str_repeat( 'a', 65 ), // 65 chars (over 64-cap)
		];
		foreach ( $cases as $bad_id ) {
			$ok = $reg->add( $bad_id, [ 'url' => 'https://a.example.com' ] );
			$this->assertFalse( $ok, "id '$bad_id' should be rejected" );
		}
	}

	public function test_id_regex_accepts_valid_characters(): void {
		$cases = [
			'a',
			'A',
			'1',
			'site-a',
			'site_b',
			'Site-1_x',
			\str_repeat( 'a', 64 ), // exactly 64 chars
		];
		foreach ( $cases as $good_id ) {
			$reg = new ServerRegistry();
			$reg->reset_cache();
			$GLOBALS['_wp_options'] = [];
			$ok = $reg->add( $good_id, [ 'url' => 'https://a.example.com' ] );
			$this->assertTrue( $ok, "id '$good_id' should be accepted" );
		}
	}

	public function test_static_is_valid_id_helper(): void {
		$this->assertTrue( ServerRegistry::is_valid_id( 'site-a' ) );
		$this->assertFalse( ServerRegistry::is_valid_id( 'site a' ) );
		$this->assertFalse( ServerRegistry::is_valid_id( '' ) );
	}

	// ---------------------------------------------------------------------
	// reset_cache + MAX_SERVERS cap + partial-update semantics.
	// ---------------------------------------------------------------------

	public function test_reset_cache_picks_up_external_writes(): void {
		$reg = new ServerRegistry();
		$reg->get_all(); // prime the cache.

		// Mutate option directly (simulating another worker / admin write).
		\update_option(
			ServerRegistry::OPTION_KEY,
			[
				'external' => [
					'url'     => 'https://external.example.com',
					'enabled' => true,
				],
			]
		);

		// Without reset, the cache hides the external write.
		$this->assertNull( $reg->get( 'external' ) );

		$reg->reset_cache();
		$entry = $reg->get( 'external' );
		$this->assertNotNull( $entry );
		$this->assertSame( 'https://external.example.com', $entry['url'] );
	}

	public function test_max_servers_cap_blocks_101st_add(): void {
		$reg     = new ServerRegistry();
		$option  = [];
		for ( $i = 0; $i < ServerRegistry::MAX_SERVERS; $i++ ) {
			$option[ "site-$i" ] = [
				'url'           => "https://site-$i.example.com",
				'auth_username' => '',
				'auth_password' => '',
				'enabled'       => true,
				'logs'          => [ 'firehose.log' ],
			];
		}
		\update_option( ServerRegistry::OPTION_KEY, $option );
		$reg->reset_cache();

		$this->assertCount( ServerRegistry::MAX_SERVERS, $reg->get_all() );
		$ok = $reg->add(
			'overflow',
			[
				'url'           => 'https://overflow.example.com',
				'auth_password' => 'p',
			]
		);
		$this->assertFalse( $ok );
	}

	public function test_partial_update_preserves_untouched_fields(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_username' => 'admin',
				'auth_password' => 'original-pass',
				'logs'          => [ 'firehose.log', 'jobs.log' ],
			]
		);

		// Partial update: only flip enabled.
		$ok = $reg->update( 'site-a', [ 'enabled' => false ] );
		$this->assertTrue( $ok );

		$reg->reset_cache();
		$entry = $reg->get( 'site-a' );
		$this->assertSame( 'https://a.example.com', $entry['url'] );
		$this->assertSame( 'admin', $entry['auth_username'] );
		$this->assertSame( 'original-pass', $entry['auth_password'] );
		$this->assertSame( [ 'firehose.log', 'jobs.log' ], $entry['logs'] );
		$this->assertFalse( $entry['enabled'] );
	}

	public function test_partial_update_drops_non_whitelisted_keys(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_password' => 'p',
			]
		);

		$ok = $reg->update(
			'site-a',
			[
				'enabled'         => false,
				'malicious_field' => 'should be ignored',
				'__proto__'       => 'no-op',
			]
		);
		$this->assertTrue( $ok );

		$reg->reset_cache();
		$opt = \get_option( ServerRegistry::OPTION_KEY );
		$this->assertArrayNotHasKey( 'malicious_field', $opt['site-a'] );
		$this->assertArrayNotHasKey( '__proto__', $opt['site-a'] );
	}

	public function test_get_enabled_filters_disabled_entries(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'enabled-1',
			[
				'url'           => 'https://e1.example.com',
				'auth_password' => 'p',
			]
		);
		$reg->add(
			'enabled-2',
			[
				'url'           => 'https://e2.example.com',
				'auth_password' => 'p',
			]
		);
		$reg->add(
			'disabled-1',
			[
				'url'           => 'https://d1.example.com',
				'auth_password' => 'p',
				'enabled'       => false,
			]
		);

		$enabled = $reg->get_enabled();
		$this->assertCount( 2, $enabled );
		$this->assertArrayHasKey( 'enabled-1', $enabled );
		$this->assertArrayHasKey( 'enabled-2', $enabled );
		$this->assertArrayNotHasKey( 'disabled-1', $enabled );
	}

	public function test_register_overwrites_existing_entry(): void {
		$reg = new ServerRegistry();
		$reg->register(
			'site-a',
			[
				'url'           => 'https://first.example.com',
				'auth_username' => 'first-user',
				'auth_password' => 'first-pass',
			]
		);
		// Second register with same id — full overwrite.
		$ok = $reg->register(
			'site-a',
			[
				'url'           => 'https://second.example.com',
				'auth_username' => 'second-user',
				'auth_password' => 'second-pass',
			]
		);
		$this->assertTrue( $ok );

		$reg->reset_cache();
		$entry = $reg->get( 'site-a' );
		$this->assertSame( 'https://second.example.com', $entry['url'] );
		$this->assertSame( 'second-user', $entry['auth_username'] );
		$this->assertSame( 'second-pass', $entry['auth_password'] );
	}

	public function test_add_returns_false_for_duplicate_id(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_password' => 'p',
			]
		);
		$ok = $reg->add(
			'site-a',
			[
				'url'           => 'https://other.example.com',
				'auth_password' => 'p',
			]
		);
		$this->assertFalse( $ok );
	}

	public function test_remove_returns_false_for_unknown_id(): void {
		$this->assertFalse( ( new ServerRegistry() )->remove( 'never-existed' ) );
	}

	public function test_update_returns_false_for_unknown_id(): void {
		$this->assertFalse(
			( new ServerRegistry() )->update( 'never-existed', [ 'enabled' => false ] )
		);
	}

	public function test_url_trailing_slash_is_stripped(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com/',
				'auth_password' => 'p',
			]
		);
		$entry = $reg->get( 'site-a' );
		$this->assertSame( 'https://a.example.com', $entry['url'] );
	}

	// ---------------------------------------------------------------------
	// Audit log entry format.
	// ---------------------------------------------------------------------

	public function test_audit_log_emitted_on_add(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_password' => 'p',
			]
		);
		$log = $this->read_error_log();
		$this->assertStringContainsString( 'ServerRegistry added id=site-a', $log );
		$this->assertStringContainsString( 'user=42', $log );
		// Ensure the password VALUE is never logged — only field names.
		$this->assertStringNotContainsString( 'auth_password=p', $log );
		$this->assertStringContainsString( 'fields=', $log );
	}

	public function test_audit_log_emitted_on_update(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_password' => 'p',
			]
		);
		$reg->update( 'site-a', [ 'enabled' => false ] );
		$log = $this->read_error_log();
		$this->assertStringContainsString( 'ServerRegistry updated id=site-a', $log );
		$this->assertStringContainsString( 'fields=enabled', $log );
	}

	public function test_audit_log_emitted_on_remove(): void {
		$reg = new ServerRegistry();
		$reg->add(
			'site-a',
			[
				'url'           => 'https://a.example.com',
				'auth_password' => 'p',
			]
		);
		$reg->remove( 'site-a' );
		$log = $this->read_error_log();
		$this->assertStringContainsString( 'ServerRegistry removed id=site-a', $log );
	}

	public function test_audit_log_includes_user_id_and_timestamp(): void {
		$GLOBALS['_current_user_id'] = 17;
		$reg                         = new ServerRegistry();
		$reg->add(
			'site-z',
			[
				'url'           => 'https://z.example.com',
				'auth_password' => 'p',
			]
		);
		$log = $this->read_error_log();
		$this->assertStringContainsString( 'user=17', $log );
		// ISO-8601 UTC timestamp produced by gmdate('c').
		$this->assertMatchesRegularExpression( '/ts=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00/', $log );
	}

	public function test_singleton_returns_same_instance(): void {
		$a = ServerRegistry::get_instance();
		$b = ServerRegistry::get_instance();
		$this->assertSame( $a, $b );
	}
}
