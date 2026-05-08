<?php
/**
 * SettingsSync: hub-side sync of WP options to remote spokes with sodium-secretbox
 * encryption on the wire.
 *
 * Critical invariants (do not regress):
 *  1. Fail-closed hub-check polarity. Only sync if `enable_workers === true` (strict).
 *     Missing or non-true means "not a hub" — no fan-out. The bug fixed in
 *     event-logger 2.4.42 (silent fan-out from non-hubs) lives here.
 *  2. Encryption-required fail-closed. If sodium is unavailable or encryption
 *     returns empty, NO dispatch happens — we do not fall back to plaintext
 *     transmission. Settings often contain auth credentials; plaintext on the
 *     wire is unacceptable.
 *
 * Encryption details (per spec section 4):
 *  - Key:   sodium_crypto_generichash(wp_salt('auth'), '', 32) — derives a
 *           32-byte key (SODIUM_CRYPTO_SECRETBOX_KEYBYTES) from the WP auth salt.
 *  - Nonce: 24 random bytes per encrypt call (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES).
 *  - Wire:  base64(nonce . ciphertext). Base64 keeps the payload JSON-safe;
 *           appending nonce in front means we don't need a separate field.
 *  - decrypt() returns null on malformed input OR sodium_crypto_secretbox_open()
 *           failure (bad-MAC = tamper); callers handle null by skipping the apply.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

class SettingsSync {
	private array $config;
	private array $synced_options;
	/** @var callable */
	private $dispatch;
	private bool $syncing = false;

	public function __construct(
		array $config,
		array $synced_options,
		callable $dispatch
	) {
		$this->config         = $config;
		$this->synced_options = $synced_options;
		$this->dispatch       = $dispatch;
	}

	public function suppress_sync( bool $suppress = true ): void {
		$this->syncing = $suppress;
	}

	public function on_option_update( string $option, $old, $new ): void {
		if ( $this->syncing ) {
			return; // Re-entrancy guard.
		}
		// Fail-closed: strict === true. Anything else (missing, false, 1, "1", etc.) skips.
		if ( ! isset( $this->config['enable_workers'] ) || true !== $this->config['enable_workers'] ) {
			return;
		}
		if ( ! \in_array( $option, $this->synced_options, true ) ) {
			return;
		}

		// Encrypt the payload before dispatching. JSON-encode first so structured
		// values survive the wire (encrypt operates on strings).
		$plaintext  = (string) \json_encode( [ 'option' => $option, 'value' => $new ] );
		$ciphertext = self::encrypt( $plaintext );
		if ( '' === $ciphertext ) {
			// Encryption-required fail-closed: never dispatch plaintext on the wire.
			// (Sodium missing, key derivation failed, or random_bytes blew up.)
			return;
		}

		( $this->dispatch )( $option, $new, $ciphertext );
	}

	/**
	 * Apply an inbound encrypted settings payload. Returns the decoded payload
	 * (`['option' => string, 'value' => mixed]`) on success, null on tamper /
	 * malformed input.
	 *
	 * @param string $ciphertext base64(nonce . ciphertext) from a sync sender.
	 * @return array{option:string,value:mixed}|null
	 */
	public static function decode_payload( string $ciphertext ): ?array {
		$plaintext = self::decrypt( $ciphertext );
		if ( null === $plaintext ) {
			return null;
		}
		$decoded = \json_decode( $plaintext, true );
		if ( ! \is_array( $decoded ) || ! isset( $decoded['option'] ) ) {
			return null;
		}
		return [
			'option' => (string) $decoded['option'],
			'value'  => $decoded['value'] ?? null,
		];
	}

	/**
	 * Encrypt a plaintext for wire transmission. Returns '' on failure (sodium
	 * unavailable, key derivation failed, etc.) — caller treats empty as
	 * fail-closed and skips the dispatch.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string base64(nonce . ciphertext), or '' on failure.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( ! \function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		$key = self::encryption_key();
		if ( '' === $key ) {
			return '';
		}
		try {
			$nonce      = \random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = \sodium_crypto_secretbox( $plaintext, $nonce, $key );
		} catch ( \Throwable $e ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- wire format requires base64.
		return \base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a wire payload. Returns null on:
	 *   - malformed base64
	 *   - too-short payload (< nonce size)
	 *   - bad-MAC (sodium_crypto_secretbox_open returns false on tamper)
	 *   - sodium missing or key derivation failed
	 *
	 * @param string $ciphertext base64(nonce . ciphertext)
	 * @return string|null Plaintext, or null on failure.
	 */
	public static function decrypt( string $ciphertext ): ?string {
		if ( ! \function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		$key = self::encryption_key();
		if ( '' === $key ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- wire format requires base64.
		$decoded = \base64_decode( $ciphertext, true );
		if ( false === $decoded || \strlen( $decoded ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = \substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$body  = \substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		try {
			$plaintext = \sodium_crypto_secretbox_open( $body, $nonce, $key );
		} catch ( \Throwable $e ) {
			return null;
		}
		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * Derive a 32-byte key from wp_salt('auth') via generic hash. Returns ''
	 * if sodium hash isn't available — callers treat that as fail-closed.
	 */
	private static function encryption_key(): string {
		if ( ! \function_exists( 'sodium_crypto_generichash' ) ) {
			return '';
		}
		if ( ! \function_exists( 'wp_salt' ) ) {
			return '';
		}
		$salt = (string) \wp_salt( 'auth' );
		if ( '' === $salt ) {
			return '';
		}
		try {
			return \sodium_crypto_generichash( $salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
