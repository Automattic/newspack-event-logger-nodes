<?php
/**
 * Server-Timing — the logged request's time so far, as a response header for
 * a reader holding ELN's read role, so a browser's network panel shows where
 * the time went and names the request to find it by.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes\App;

use Newspack_Event_Logger_Nodes\Log_Manager;
use Newspack_Nodes\Capabilities;

if ( ! \defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits `Server-Timing` where each kind of response sends its headers.
 *
 * An HTML page's leave at `send_headers`, so its numbers cover only the time
 * until then; a served REST response takes them at `rest_pre_serve_request`,
 * which fires once per response the server serves, so a batch's or an
 * embed's sub-response and an editor page's preloaded data, which pass
 * through `rest_post_dispatch` alone, carry none; an admin-ajax response
 * takes them at `admin_init`, the last hook before its handler writes.
 *
 * The header goes only to a signed-in user holding `Capabilities::READ`, the
 * role every dashboard and the firehose itself are gated on, and only on a
 * request a rule logs. A visitor is refused by the login test alone, so a
 * capability map granting `read` to everyone still sends them nothing.
 *
 * The value is `total;dur=<ms>;desc="since PHP start"` — the record's root
 * frame, which the profiler drop-in dates from PHP's start where it is
 * installed — then the `TOP` dearest categories by self time, each named by
 * its label made a token with the label itself as `desc`, then
 * `rid;desc="<request id>"`. Every `desc` is printable ASCII, escaped as a
 * quoted-string and cut at `DESC_MAX`, so no label can split the header.
 */
class Server_Timing {

	/** Categories the header names, dearest first. */
	public const TOP = 5;

	/** Characters a `desc` keeps. */
	private const DESC_MAX = 64;

	/**
	 * Header-send seam: replaces the `header()` call, which a CLI test run
	 * cannot observe. Tests reassign it to capture the line.
	 * Signature: `function ( string $line ): void`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $send_header = null;

	/**
	 * Headers-sent seam: replaces the `headers_sent()` call, which a CLI
	 * test run cannot steer. Tests reassign it to stand in either state.
	 * Signature: `function (): bool`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $headers_sent = null;

	/**
	 * `rest_pre_serve_request`: send the header as the server serves the
	 * response, ahead of any listener that serves it by hand.
	 *
	 * @param mixed $served Whether a listener has served the request.
	 * @return mixed The same verdict.
	 */
	public function rest( mixed $served ): mixed {
		$this->send();
		return $served;
	}

	/**
	 * `admin_init`: send the header ahead of an admin-ajax handler's output;
	 * any other admin page is not one this emits on.
	 */
	public function ajax(): void {
		if ( \wp_doing_ajax() ) {
			$this->send();
		}
	}

	/**
	 * `send_headers`: send the header with the page's.
	 */
	public function send(): void {
		$value = self::value();
		if ( null === $value ) {
			return;
		}
		$sent = self::$headers_sent ?? static fn (): bool => \headers_sent();
		if ( true === $sent() ) {
			return;
		}
		$emit = self::$send_header ?? static fn ( string $line ) => \header( $line );
		$emit( "Server-Timing: {$value}" );
	}

	/**
	 * The header's value, or null for anyone not owed it.
	 */
	private static function value(): ?string {
		if ( 0 === \get_current_user_id() || ! Capabilities::can( Capabilities::READ ) ) {
			return null;
		}
		$lm     = Log_Manager::started_instance();
		$so_far = $lm?->timing_so_far();
		if ( null === $lm || null === $so_far ) {
			return null;
		}
		$spent = $so_far['spent'];
		\arsort( $spent, \SORT_NUMERIC );
		$metrics = [ \sprintf( 'total;dur=%.1F;desc="since PHP start"', $so_far['total_ms'] ) ];
		foreach ( \array_slice( $spent, 0, self::TOP, true ) as $label => $ms ) {
			$metrics[] = \sprintf( '%s;dur=%.1F;desc=%s', self::token( $label ), \max( 0, $ms ), self::quoted( $label ) );
		}
		$metrics[] = 'rid;desc=' . self::quoted( $lm->get_request_id() );
		return \implode( ', ', $metrics );
	}

	/**
	 * A value as a quoted-string: printable ASCII only, `\` and `"` escaped.
	 *
	 * @param string $text The value.
	 */
	private static function quoted( string $text ): string {
		$ascii = (string) \preg_replace( '/[^\x20-\x7E]/', '?', \substr( $text, 0, self::DESC_MAX ) );
		return '"' . \addcslashes( $ascii, '"\\' ) . '"';
	}

	/**
	 * A label as a metric name: every character a header token may not hold
	 * read as `-`.
	 *
	 * @param string $label The category's label.
	 */
	private static function token( string $label ): string {
		$token = (string) \preg_replace( "/[^!#$%&'*+\\-.^_`|~0-9A-Za-z]/", '-', \substr( $label, 0, self::DESC_MAX ) );
		return '' === $token ? '-' : $token;
	}

	/**
	 * What `App\Core` binds on a logged request. `admin_init` runs at its
	 * last priority, so the header counts every other listener's time;
	 * `rest_pre_serve_request` at its first, ahead of a listener serving by
	 * hand.
	 *
	 * @return list<array{0: string, 1: callable, 2: int, 3: int}> Hook, callback, priority, accepted args.
	 */
	public function listeners(): array {
		return [
			[ 'send_headers', [ $this, 'send' ], PHP_INT_MAX, 0 ],
			[ 'rest_pre_serve_request', [ $this, 'rest' ], PHP_INT_MIN, 1 ],
			[ 'admin_init', [ $this, 'ajax' ], PHP_INT_MAX, 0 ],
		];
	}
}
