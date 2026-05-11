<?php
namespace Newspack_Event_Logger_Nodes\Tests\Unit\Rest;

use Newspack_Event_Logger_Nodes\Rest\PerformanceControllerBase;
use Newspack_Event_Logger_Nodes\Rest\PerfRequestsController;
use Newspack_Event_Logger_Nodes\Tests\Helpers\FakeMemcached;
use Newspack_Event_Logger_Nodes\Tests\TestCase;
use Newspack_Nodes\Message;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass( PerfRequestsController::class )]
class PerfRequestsControllerTest extends TestCase {
	private FakeMemcached $cache;
	private string $tmp;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_rest_routes']      = [];
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_wp_actions']['newspack_nodes/config'] = [];
		\Newspack_Nodes\Config::reset();
		$this->cache                  = new FakeMemcached();
		PerformanceControllerBase::set_cache( $this->cache );
		$this->tmp                    = $this->make_temp_dir();
		\add_filter(
			'newspack_nodes/config',
			fn( array $cfg ): array => \array_merge( $cfg, [
				'num_partitions' => 2,
				'base_directory' => $this->tmp,
			] )
		);
	}

	protected function tearDown(): void {
		PerformanceControllerBase::set_cache( null );
		$GLOBALS['_wp_actions']['newspack_nodes/config'] = [];
		\Newspack_Nodes\Config::reset();
		$this->rmdir_recursive( $this->tmp );
		parent::tearDown();
	}

	private function rate_limit_key( PerfRequestsController $ctrl ): string {
		$ref = new \ReflectionMethod( $ctrl, 'rate_limit_key' );
		$ref->setAccessible( true );
		return (string) $ref->invoke( $ctrl );
	}

	private function trip_rate_limit( PerfRequestsController $ctrl ): void {
		$now          = \time();
		$window_start = (int) \floor( $now / 60 ) * 60;
		$key          = $this->rate_limit_key( $ctrl );
		$this->cache->set( "newspack_nodes_rate:{$key}:{$window_start}", 9999, 120 );
	}

	/**
	 * Write a v4 fixed-width index entry to {tmp}/logs/requests.log/p{partition}/0.idx,
	 * with a packed Message in the matching .log file at the recorded offset+length
	 * so `read_at()` returns valid data.
	 */
	private function write_request( int $partition, string $rid, string $url_hash, array $body ): void {
		$dir = "{$this->tmp}/logs/requests.log/p{$partition}";
		\mkdir( $dir, 0755, true );

		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = (float) ( $body['timestamp'] ?? \time() );
		$msg[ Message::KEY ]       = $body['url'] ?? '';
		$msg[ Message::VALUE ]     = $body;
		$packed                    = Message::packed( $msg ) . "\n";
		$len                       = \strlen( $packed );
		$offset                    = \file_exists( "{$dir}/0.log" ) ? (int) \filesize( "{$dir}/0.log" ) : 0;
		\file_put_contents( "{$dir}/0.log", $packed, \FILE_APPEND );

		$timestamp    = (int) ( $body['timestamp'] ?? \time() );
		$duration_ms  = (int) ( $body['duration_ms'] ?? 0 );
		$status_code  = (int) ( $body['status_code'] ?? 200 );
		$peak_mb      = (int) \round( (float) ( $body['peak_mb'] ?? 0 ) );
		$method       = ( $body['request_method'] ?? 'GET' )[0] ?? 'G';
		$error_status = (string) ( $body['error_status'] ?? '-' );

		$idx = \str_pad( \substr( $rid, 0, 32 ), 32 )
			. \str_pad( \substr( $url_hash, 0, 12 ), 12 )
			. \str_pad( (string) $timestamp, 10, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $duration_ms, 8, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $status_code, 3, '0', \STR_PAD_LEFT )
			. \str_pad( '0', 6, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $offset, 10, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $len, 8, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $peak_mb, 6, '0', \STR_PAD_LEFT )
			. $method
			. $error_status
			. "\n";
		\file_put_contents( "{$dir}/0.idx", $idx, \FILE_APPEND );
	}

	/**
	 * Write a flame-index entry to {tmp}/logs/flames.log/p{partition}/0.idx,
	 * matched by a packed Message in the .log file.
	 */
	private function write_flame( int $partition, string $rid, string $url_hash, array $flame ): void {
		$dir = "{$this->tmp}/logs/flames.log/p{$partition}";
		\mkdir( $dir, 0755, true );

		$body                      = [ 'rid' => $rid, 'url_hash' => $url_hash ] + $flame;
		$msg                       = Message::new_message();
		$msg[ Message::TYPE ]      = Message::TM_STRUCT;
		$msg[ Message::TIMESTAMP ] = (float) \time();
		$msg[ Message::KEY ]       = $url_hash;
		$msg[ Message::VALUE ]     = $body;
		$packed                    = Message::packed( $msg ) . "\n";
		$len                       = \strlen( $packed );
		$offset                    = \file_exists( "{$dir}/0.log" ) ? (int) \filesize( "{$dir}/0.log" ) : 0;
		\file_put_contents( "{$dir}/0.log", $packed, \FILE_APPEND );

		$idx = \str_pad( \substr( $rid, 0, 32 ), 32 )
			. \str_pad( \substr( $url_hash, 0, 12 ), 12 )
			. \str_pad( '0', 6, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $offset, 10, '0', \STR_PAD_LEFT )
			. \str_pad( (string) $len, 8, '0', \STR_PAD_LEFT )
			. "\n";
		\file_put_contents( "{$dir}/0.idx", $idx, \FILE_APPEND );
	}

	// ── register_routes ────────────────────────────────────────────────

	public function test_register_routes_registers_search_and_detail(): void {
		( new PerfRequestsController() )->register_routes();
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/requests/search/(?P<rid>[a-zA-Z0-9_-]{1,128})', $GLOBALS['_rest_routes'] );
		$this->assertArrayHasKey( 'newspack-nodes/v1/performance/requests/(?P<rid>[a-zA-Z0-9_-]{1,128})', $GLOBALS['_rest_routes'] );
	}

	public function test_register_routes_args_documented(): void {
		( new PerfRequestsController() )->register_routes();
		$detail_args = $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/requests/(?P<rid>[a-zA-Z0-9_-]{1,128})']['args'];
		$this->assertTrue( $detail_args['rid']['required'] );
		$this->assertTrue( $detail_args['partition']['required'] );
	}

	public function test_partition_sanitize_callback_floors_at_0(): void {
		( new PerfRequestsController() )->register_routes();
		$cb = $GLOBALS['_rest_routes']['newspack-nodes/v1/performance/requests/(?P<rid>[a-zA-Z0-9_-]{1,128})']['args']['partition']['sanitize_callback'];
		$this->assertSame( 5,  $cb( 5 ) );
		$this->assertSame( 0,  $cb( -3 ) );
		$this->assertSame( 12, $cb( '12' ) );
	}

	// ── search_request ─────────────────────────────────────────────────

	public function test_search_request_returns_404_for_unknown_rid(): void {
		$req = new \WP_REST_Request();
		$req->set_param( 'rid', 'no-such-rid' );
		$resp = ( new PerfRequestsController() )->search_request( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
	}

	public function test_search_request_finds_rid_in_partition_0(): void {
		$rid  = 'rid-search-0';
		$hash = 'urlhash00000';
		$this->write_request( 0, $rid, $hash, [
			'rid' => $rid, 'url' => '/p0', 'duration_ms' => 50, 'status_code' => 200,
		] );

		$req = new \WP_REST_Request();
		$req->set_param( 'rid', $rid );
		$resp = ( new PerfRequestsController() )->search_request( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertSame( $rid,  $body['rid'] );
		$this->assertSame( 0,     $body['partition'] );
		$this->assertSame( $hash, $body['url_hash'] );
	}

	public function test_search_request_finds_rid_in_partition_1(): void {
		$rid  = 'rid-search-1';
		$hash = 'urlhash11111';
		$this->write_request( 1, $rid, $hash, [
			'rid' => $rid, 'url' => '/p1', 'duration_ms' => 50, 'status_code' => 200,
		] );

		$req = new \WP_REST_Request();
		$req->set_param( 'rid', $rid );
		$resp = ( new PerfRequestsController() )->search_request( $req );
		$body = $resp->get_data();
		$this->assertSame( 1, $body['partition'] );
	}

	// ── get_request: invalid params + 404 ─────────────────────────────

	public function test_get_request_returns_404_for_partition_out_of_range(): void {
		$req = new \WP_REST_Request();
		$req->set_param( 'rid', 'whatever' );
		$req->set_param( 'partition', 999 );
		$resp = ( new PerfRequestsController() )->get_request( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
	}

	public function test_get_request_returns_404_for_unknown_rid(): void {
		$req = new \WP_REST_Request();
		$req->set_param( 'rid', 'rid-zzz' );
		$req->set_param( 'partition', 0 );
		$resp = ( new PerfRequestsController() )->get_request( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rest_not_found', $resp->get_error_code() );
	}

	// ── get_request: full request body + flame merge ────────────────────

	public function test_get_request_returns_request_body_from_partition(): void {
		$rid  = 'rid-detail-1';
		$hash = 'urldetail000';
		$this->write_request( 0, $rid, $hash, [
			'rid' => $rid, 'url' => '/page', 'duration_ms' => 75, 'status_code' => 200,
			'request_method' => 'GET', 'entries' => [ [ 'k' => 'init', 'm' => 'x' ] ],
		] );

		$req = new \WP_REST_Request();
		$req->set_param( 'rid', $rid );
		$req->set_param( 'partition', 0 );
		$resp = ( new PerfRequestsController() )->get_request( $req );
		$this->assertInstanceOf( \WP_REST_Response::class, $resp );
		$body = $resp->get_data();
		$this->assertSame( $rid,    $body['rid'] );
		$this->assertSame( '/page', $body['url'] );
		$this->assertSame( 200,     $body['status_code'] );
		$this->assertSame( $hash,   $body['url_hash'] ); // injected by controller
		$this->assertArrayNotHasKey( 'flame_data', $body ); // no flame seeded
	}

	public function test_get_request_attaches_flame_data_when_present(): void {
		$rid  = 'rid-flame-yes';
		$hash = 'urlflame0000';
		$this->write_request( 0, $rid, $hash, [
			'rid' => $rid, 'url' => '/x', 'duration_ms' => 50, 'status_code' => 200,
		] );
		$this->write_flame( 0, $rid, $hash, [
			'name' => 'request', 'value' => 50,
			'children' => [ [ 'name' => 'init', 'value' => 20 ] ],
		] );

		$req = new \WP_REST_Request();
		$req->set_param( 'rid', $rid );
		$req->set_param( 'partition', 0 );
		$body = ( new PerfRequestsController() )->get_request( $req )->get_data();
		$this->assertArrayHasKey( 'flame_data', $body );
		$this->assertSame( 'request', $body['flame_data']['name'] );
		$this->assertSame( 50,        $body['flame_data']['value'] );
	}

	public function test_get_request_finds_flame_in_different_partition(): void {
		// Request body lives in partition 0 but flame data was written to
		// partition 1 — the controller scans every partition for the flame.
		$rid  = 'rid-cross-part';
		$hash = 'crosspart000';
		$this->write_request( 0, $rid, $hash, [
			'rid' => $rid, 'url' => '/x', 'duration_ms' => 50, 'status_code' => 200,
		] );
		$this->write_flame( 1, $rid, $hash, [
			'name' => 'request', 'value' => 60,
		] );

		$req = new \WP_REST_Request();
		$req->set_param( 'rid', $rid );
		$req->set_param( 'partition', 0 );
		$body = ( new PerfRequestsController() )->get_request( $req )->get_data();
		$this->assertArrayHasKey( 'flame_data', $body );
		$this->assertSame( 60, $body['flame_data']['value'] );
	}

	// ── permissions + rate limiting ─────────────────────────────────────

	public function test_permission_check_rejects_unauthorized(): void {
		$GLOBALS['_current_user_can'] = false;
		$result = ( new PerfRequestsController() )->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_search_request_returns_429_when_rate_limited(): void {
		$ctrl = new PerfRequestsController();
		$this->trip_rate_limit( $ctrl );
		$req = new \WP_REST_Request();
		$req->set_param( 'rid', 'whatever' );
		$resp = $ctrl->search_request( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limit_exceeded', $resp->get_error_code() );
	}

	public function test_get_request_returns_429_when_rate_limited(): void {
		$ctrl = new PerfRequestsController();
		$this->trip_rate_limit( $ctrl );
		$req = new \WP_REST_Request();
		$req->set_param( 'rid', 'whatever' );
		$req->set_param( 'partition', 0 );
		$resp = $ctrl->get_request( $req );
		$this->assertInstanceOf( \WP_Error::class, $resp );
		$this->assertSame( 'rate_limit_exceeded', $resp->get_error_code() );
	}
}
