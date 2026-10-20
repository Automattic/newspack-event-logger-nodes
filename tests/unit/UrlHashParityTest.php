<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Event_Logger_Nodes\Tests\TestCase;

/**
 * `Log_Manager::url_hash()` and the substrate's JS `fnv1a()` hash a string's
 * UTF-8 bytes to one key. Both read `newspack-nodes/tests/fixtures/
 * fnv1a-hashes.json`, which spans ASCII and two-, three- and four-byte
 * characters.
 */
#[CoversClass( Log_Manager::class )]
class UrlHashParityTest extends TestCase {

	/**
	 * @return array<string,array{string,string}>
	 */
	public static function fixture_provider(): array {
		$fixture = \json_decode( (string) \file_get_contents( \dirname( __DIR__, 3 ) . '/newspack-nodes/tests/fixtures/fnv1a-hashes.json' ), true, 512, \JSON_THROW_ON_ERROR );
		\assert( \is_array( $fixture ) && \is_array( $fixture['url_hash'] ) );
		$out = [];
		foreach ( $fixture['url_hash'] as $case ) {
			$out[ (string) $case[0] ] = [ (string) $case[1], (string) $case[2] ];
		}
		return $out;
	}

	#[DataProvider( 'fixture_provider' )]
	public function test_url_hash_matches_the_js_fnv1a( string $str, string $hash ): void {
		$this->assertSame( $hash, Log_Manager::url_hash( $str ) );
	}
}
