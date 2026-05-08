<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use Newspack_Event_Logger_Nodes\SettingsSync;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( SettingsSync::class )]
class SettingsSyncTest extends TestCase {
	public function test_skips_when_enable_workers_unset(): void {
		$called = false;
		$sync = new SettingsSync(
			config: [ /* enable_workers absent */ ],
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertFalse( $called, 'fail-closed: missing enable_workers must skip sync' );
	}

	public function test_skips_when_enable_workers_false(): void {
		$called = false;
		$sync = new SettingsSync(
			config: [ 'enable_workers' => false ],
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertFalse( $called );
	}

	public function test_skips_when_enable_workers_truthy_but_not_true(): void {
		$called = false;
		$sync = new SettingsSync(
			config: [ 'enable_workers' => 1 ], // truthy but !== true
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertFalse( $called, 'strict === true required, not just truthy' );
	}

	public function test_syncs_when_enable_workers_strictly_true(): void {
		$received = null;
		$sync = new SettingsSync(
			config: [ 'enable_workers' => true ],
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
			config: [ 'enable_workers' => true ],
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->on_option_update( 'unrelated', 'a', 'b' );
		$this->assertFalse( $called );
	}

	public function test_suppress_sync_blocks_sync(): void {
		$called = false;
		$sync = new SettingsSync(
			config: [ 'enable_workers' => true ],
			synced_options: [ 'log_urls' ],
			dispatch: function () use ( &$called ) { $called = true; }
		);
		$sync->suppress_sync( true );
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertFalse( $called );

		$sync->suppress_sync( false );
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
		$raw[30] = $raw[30] === 'A' ? 'B' : 'A';
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
		$raw[30] = $raw[30] === 'X' ? 'Y' : 'X';
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
		// Positive-case contract: when sodium is available (as it is in this
		// test environment, since SODIUM_CRYPTO_SECRETBOX_KEYBYTES is a
		// PHP-bundled constant), the dispatch MUST be invoked with a non-empty
		// ciphertext. The fail-closed branch (encrypt() returns '' → skip
		// dispatch) is enforced by the same `if ( '' === $ciphertext ) return`
		// check; that branch isn't reachable here without un-loading sodium.
		$dispatched = null;
		$sync       = new SettingsSync(
			config: [ 'enable_workers' => true ],
			synced_options: [ 'log_urls' ],
			dispatch: function ( $option, $value, $cipher ) use ( &$dispatched ) {
				$dispatched = $cipher;
			}
		);
		$sync->on_option_update( 'log_urls', [ '/old' ], [ '/new' ] );
		$this->assertNotNull( $dispatched );
		$this->assertNotSame( '', $dispatched );
	}
}
