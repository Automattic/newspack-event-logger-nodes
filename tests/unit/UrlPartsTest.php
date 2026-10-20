<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Newspack_Event_Logger_Nodes\Log_Manager;
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
		$this->assertSame( '/Kakapo-4417', Url_Parts::row_path( 'https://Kea.Test/Kakapo-4417', 'kea.test' ), 'its own server in any case' );
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

	/**
	 * Every query key folds into the page, a routing key's included, so a
	 * REST call is a shape of its host's `/` page and an admin-ajax action a
	 * shape of `admin-ajax.php`.
	 */
	public function test_a_routing_key_folds_into_the_page(): void {
		$this->assertSame( 'h-6631.example/', Url_Parts::page( Url_Parts::key( 'https://H-6631.example/?rest_route=/wp/v2/posts/7&_embed=1', false ) ) );
		$this->assertSame( 'h-6631.example/wp-admin/admin-ajax.php', Url_Parts::page( Url_Parts::key( 'https://H-6631.example/wp-admin/admin-ajax.php?action=Heartbeat&_nonce=x', false ) ) );
	}

	public function test_a_page_is_the_lowercased_host_and_path(): void {
		$this->assertSame( 'www.example.com/cincinnati/movietimes', Url_Parts::page( 'https://Www.Example.com/Cincinnati/MovieTimes?film=?&show=?' ) );
		$this->assertSame( 'moa.test', Url_Parts::page( 'https://MOA.test' ) );
		$this->assertSame( 'moa.test/', Url_Parts::page( 'https://moa.test/?a=?' ) );
	}

	public function test_case_variants_share_a_page(): void {
		$this->assertSame(
			Url_Parts::page( 'https://kea.test/Kea-5521?a=?' ),
			Url_Parts::page( 'https://KEA.test/kea-5521?b=?&c=?' )
		);
	}

	public function test_a_worker_mark_and_a_query_leave_the_page_alone(): void {
		$this->assertSame( 'kea.test/wp-json/newspack-nodes/v1/command', Url_Parts::page( 'https://kea.test/wp-json/newspack-nodes/v1/command?restapi' ) );
		$this->assertSame( 'kea.test/jobs/weka', Url_Parts::page( 'https://kea.test/jobs/weka?job-worker.p0' ) );
	}

	public function test_an_http_url_and_a_cut_url_derive_a_page(): void {
		$this->assertSame( 'kea.test/pukeko', Url_Parts::page( 'http://kea.test/Pukeko?a=?' ) );
		$long = 'https://kea.test/Takahe/' . \str_repeat( 'x', 1100 );
		$this->assertSame( 'kea.test/takahe/' . \str_repeat( 'x', 1100 ), Url_Parts::page( $long ) );
		$this->assertSame( 'kea.test/takahe/…', Url_Parts::page( 'https://kea.test/Takahe/…' ) );
	}

	/**
	 * A rule matches a stored URL by its path alone: neither the masked query
	 * nor the worker mark is anything a request sent. An authority with no
	 * path has an empty one, so the host never swallows the query.
	 */
	public function test_a_rule_path_is_the_path_alone(): void {
		$this->assertSame(
			[ '/', '/wp-cron.php', '/jobs/kea', '/moa/kiwi', '', '' ],
			\array_map(
				[ Url_Parts::class, 'rule_path' ],
				[
					'https://alpha.test/?_embed=?&rest_route=/wp/v2/posts',
					'https://alpha.test/wp-cron.php?doing_wp_cron=?&cron',
					'https://alpha.test/jobs/kea?job-worker.p0',
					'https://alpha.test/moa/kiwi',
					'https://alpha.test?page=?&weka.p2',
					'https://alpha.test',
				]
			)
		);
	}

	/**
	 * The key a URL row is filed by: one row per set of keys as PHP reads
	 * them into `$_GET`, whatever their values, order or spelling, each written encoded
	 * so no key can spell a separator. A routing key keeps its value, its
	 * numeric segments masked; a tracking key is dropped.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function keys(): array {
		return [
			'order and repeats'      => [ 'https://kea.test/moa?zz=4&aa=5&zz=6', 'https://kea.test/moa?aa=?&zz=?' ],
			'tracking alone'         => [ 'https://kea.test/story-81?utm_source=kea&fbclid=x9', 'https://kea.test/story-81' ],
			'tracking beside a key'  => [ 'https://kea.test/story-81?utm_medium=e&gclid=z&page=3', 'https://kea.test/story-81?page=?' ],
			'a REST route'           => [ 'https://kea.test/?rest_route=/wp/v2/posts/8812&_embed=1', 'https://kea.test/?_embed=?&rest_route=/wp/v2/posts/?' ],
			'an encoded REST route'  => [ 'https://kea.test/?rest_route=%2Fwp%2Fv2%2Fposts%2F8812', 'https://kea.test/?rest_route=/wp/v2/posts/?' ],
			'an ajax action'         => [ 'https://kea.test/wp-admin/admin-ajax.php?action=heartbeat&_nonce=ab12', 'https://kea.test/wp-admin/admin-ajax.php?_nonce=?&action=heartbeat' ],
			'a wc-ajax endpoint'     => [ 'https://kea.test/?wc-ajax=get_refreshed_fragments&time=7', 'https://kea.test/?time=?&wc-ajax=get_refreshed_fragments' ],
			'a feed'                 => [ 'https://kea.test/?feed=rss2&cat=41', 'https://kea.test/?cat=?&feed=rss2' ],
			'a kept value escaped'   => [ 'https://kea.test/?action=a%26b%3Dc%3Fd', 'https://kea.test/?action=a%26b%3Dc%3Fd' ],
			'empty names'            => [ 'https://kea.test/weka?&&=7&k=1', 'https://kea.test/weka?k=?' ],
			'two spellings, one key' => [ 'https://kea.test/?api%5Fkey=1&api_key=2', 'https://kea.test/?api_key=?' ],
			'an array key'           => [ 'https://kea.test/?ids[0]=1&ids%5B1%5D=2', 'https://kea.test/?ids=?' ],
			'a dotted key'           => [ 'https://kea.test/?a.b=1&a_b=2', 'https://kea.test/?a_b=?' ],
			'an encoded separator'   => [ 'https://kea.test/?x%26cron=1', 'https://kea.test/?x%26cron=?' ],
			'a bare key'             => [ 'https://kea.test/kiwi?amp', 'https://kea.test/kiwi?amp=?' ],
			'a bare route'           => [ 'https://kea.test/?action', 'https://kea.test/?action=' ],
			'names sort by any case' => [ 'https://kea.test/Moa-4402?B=1&a=2', 'https://kea.test/Moa-4402?a=?&B=?' ],
		];
	}

	#[DataProvider( 'keys' )]
	public function test_a_query_keys_the_url_by_its_keys( string $request_url, string $url ): void {
		$this->assertSame( $url, Url_Parts::key( $request_url, false ) );
	}

	/**
	 * A URL row's identity: its key lowercased and an http scheme read as
	 * https, held to `urlIdentity()` in JS by the one case list, so every
	 * page that hashes a URL reaches the same row.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function identities(): array {
		$cases = \json_decode( (string) \file_get_contents( __DIR__ . '/../fixtures/url-identities.json' ), true, 512, \JSON_THROW_ON_ERROR );
		$out   = [];
		foreach ( $cases as [ $label, $url, $identity ] ) {
			$out[ $label ] = [ $url, $identity ];
		}
		return $out;
	}

	#[DataProvider( 'identities' )]
	public function test_a_urls_identity_folds_case_and_scheme( string $url, string $identity ): void {
		$this->assertSame( $identity, Url_Parts::identity( $url ) );
		$this->assertSame( Log_Manager::url_hash( $identity ), Url_Parts::hash( $url ), 'the hash its row is stored under' );
	}

	/** A trimmed line may cut its last parameter mid-name or mid-value, so that one keys nothing. */
	public function test_a_trimmed_lines_last_parameter_keys_nothing(): void {
		$this->assertSame( 'https://kea.test/moa?aa=?', Url_Parts::key( 'https://kea.test/moa?aa=1&rest_ro', true ) );
		$this->assertSame( 'https://kea.test/moa', Url_Parts::key( 'https://kea.test/moa?rest_route=/wp/v2/po', true ) );
	}

	/** Every logged URL carries its host, so a hostless one is refused, never read as a bare path. */
	public function test_a_hostless_url_is_refused(): void {
		foreach ( [ '/bare-7731', '/r?to=https://kea.test/aisle-9', 'https:///no-host-7731', '' ] as $url ) {
			foreach ( [ 'rule_path', 'server_of' ] as $reader ) {
				try {
					Url_Parts::$reader( $url );
					$this->fail( "{$reader}( '{$url}' ) answered" );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertStringContainsString( "no host: '{$url}'", $e->getMessage() );
				}
			}
		}
	}

	/** A URL's server is its host, lowercased, so its spellings are one server. */
	public function test_a_urls_server_is_its_host(): void {
		$this->assertSame( 'kea.test', Url_Parts::server_of( 'https://kea.test/sku-41?q=9' ) );
		$this->assertSame( 'example-9907.com', Url_Parts::server_of( 'https://Example-9907.COM/Moa' ) );
		$this->assertSame( 'example-9907.com', Url_Parts::server_name( 'Example-9907.COM' ) );
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
