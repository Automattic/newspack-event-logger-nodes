<?php
/**
 * ServerRegistry: encrypted remote-server config storage.
 *
 * Tokens are encrypted at rest via sodium-secretbox. Key derived from wp_salt('auth').
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

\defined( 'ABSPATH' ) || exit;

class ServerRegistry {
	public const OPTION_KEY = 'newspack_nodes_servers';

	private function key(): string {
		return \substr(
			\hash( 'sha256', \wp_salt( 'auth' ), true ),
			0,
			\SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}

	public function register( string $name, array $config ): void {
		$servers = (array) \get_option( self::OPTION_KEY, [] );
		$nonce = \random_bytes( \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext = \json_encode( $config );
		$ct = \sodium_crypto_secretbox( $plaintext, $nonce, $this->key() );
		$servers[ $name ] = [
			'nonce' => \base64_encode( $nonce ),
			'ct'    => \base64_encode( $ct ),
		];
		\update_option( self::OPTION_KEY, $servers );
	}

	public function get( string $name ): ?array {
		$servers = (array) \get_option( self::OPTION_KEY, [] );
		if ( ! isset( $servers[ $name ] ) ) {
			return null;
		}
		$entry = $servers[ $name ];
		$nonce = \base64_decode( $entry['nonce'] );
		$ct    = \base64_decode( $entry['ct'] );
		$pt    = \sodium_crypto_secretbox_open( $ct, $nonce, $this->key() );
		if ( $pt === false ) {
			return null;
		}
		return \json_decode( $pt, true );
	}

	public function list_servers(): array {
		$servers = (array) \get_option( self::OPTION_KEY, [] );
		return \array_keys( $servers );
	}

	public function remove( string $name ): void {
		$servers = (array) \get_option( self::OPTION_KEY, [] );
		unset( $servers[ $name ] );
		\update_option( self::OPTION_KEY, $servers );
	}
}
