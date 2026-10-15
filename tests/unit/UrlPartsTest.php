<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Event_Logger_Nodes\Url_Parts;

#[CoversClass( Url_Parts::class )]
class UrlPartsTest extends TestCase {

	public function test_a_row_path_drops_the_https_origin_of_its_own_server(): void {
		// The server is in the KEY, so a value repeating it pays for nothing.
		// Only an https origin naming the row's own server is implied: any
		// other scheme, or a host the key does not name, is kept whole, so a
		// reader rebuilding `https://{server}{path}` never shows a wrong URL.
		$this->assertSame( '/kakapo-4417?q=9', Url_Parts::row_path( 'https://kea.test/kakapo-4417?q=9', 'kea.test' ) );
		$this->assertSame( 'http://kea.test/kakapo-4417', Url_Parts::row_path( 'http://kea.test/kakapo-4417', 'kea.test' ) );
		$this->assertSame( 'https://moa.test/weka-1308', Url_Parts::row_path( 'https://moa.test/weka-1308', 'kea.test' ) );
		$this->assertSame( 'https://kea.test?q=3', Url_Parts::row_path( 'https://kea.test?q=3', 'kea.test' ), 'no path to stand alone' );
	}

	public function test_a_row_path_is_capped_with_an_ellipsis(): void {
		// The hash is the identity and the path is display, so a query-string
		// flood cannot grow one row past the cap.
		$long = 'https://kea.test/' . \str_repeat( 'takahe-', 400 );
		$path = Url_Parts::row_path( $long, 'kea.test' );
		$this->assertSame( 1024, Url_Parts::MAX_PATH_BYTES );
		$this->assertSame( Url_Parts::MAX_PATH_BYTES, \strlen( $path ) );
		$this->assertStringEndsWith( '…', $path );
		$this->assertStringStartsWith( '/takahe-takahe-', $path );
		$this->assertSame( '/short-5521', Url_Parts::row_path( 'https://kea.test/short-5521', 'kea.test' ) );
	}

	public function test_a_urls_path_drops_its_scheme_and_host_alone(): void {
		// An authority with no path is the case that bites: everything after
		// the host is the path, or the host swallows a query.
		$this->assertSame(
			[ '/reports', '/?cache-cozy', '?q=1', '' ],
			\array_map(
				[ Url_Parts::class, 'path_of' ],
				[ 'https://alpha.test/reports', 'https://alpha.test/?cache-cozy', 'https://alpha.test?q=1', 'https://alpha.test' ]
			)
		);
	}

	/** Every logged URL carries its host, so a hostless one is refused, never read as a bare path. */
	public function test_a_hostless_url_is_refused(): void {
		foreach ( [ '/bare-7731', '/r?to=https://kea.test/aisle-9', 'https:///no-host-7731', '' ] as $url ) {
			foreach ( [ 'path_of', 'server_of' ] as $reader ) {
				try {
					Url_Parts::$reader( $url );
					$this->fail( "{$reader}( '{$url}' ) answered" );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertStringContainsString( "no host: '{$url}'", $e->getMessage() );
				}
			}
		}
	}

	/** A URL's server is its host. */
	public function test_a_urls_server_is_its_host(): void {
		$this->assertSame( 'kea.test', Url_Parts::server_of( 'https://kea.test/sku-41?q=9' ) );
		$this->assertSame( 'wren.test', Url_Parts::server_of( 'http://wren.test?q=3' ) );
		$this->assertSame( 'kea.test', Url_Parts::server_of( 'https://kea.test' ) );
	}

	public function test_a_row_path_joins_back_to_its_url(): void {
		$this->assertSame( 'https://kea.test/kakapo-4417?q=9', Url_Parts::join_url( 'kea.test', '/kakapo-4417?q=9' ) );
		$this->assertSame( 'http://kea.test/kakapo-4417', Url_Parts::join_url( 'kea.test', 'http://kea.test/kakapo-4417' ), 'a whole URL comes back as it is' );
		$this->assertSame( 'https://moa.test/weka-1308', Url_Parts::join_url( '', 'https://moa.test/weka-1308' ) );
	}

	public function test_a_server_relative_path_with_no_server_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '/takahe-9' );

		Url_Parts::join_url( '', '/takahe-9' );
	}
}
