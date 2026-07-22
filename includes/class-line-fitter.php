<?php
/**
 * Line_Fitter
 *
 * The shared packed-size fit for ELN log emits whose partition doesn't lift the
 * PIPE_BUF cap (errors / completed / gyroscope — all uniformly ≤PIPE_BUF atomic).
 * A character clip is only a proxy for the byte boundary (a multibyte char
 * JSON-escapes to up to 6 bytes), so callers that clip for display still route
 * the packed message through here before writing.
 *
 * @package Newspack_Event_Logger_Nodes
 */

namespace Newspack_Event_Logger_Nodes;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;

\defined( 'ABSPATH' ) || exit;

final class Line_Fitter {

	/**
	 * Fit a message's PACKED line (+ newline) under Partition_Node::MAX_LINE_SIZE
	 * by halving each trimmable VALUE string field in `$fields` order until it fits
	 * (mb-aware). Returns the fitting message, or null when no listed field is left
	 * to cut — callers drop that loud (print_less_often), never emitting oversize.
	 *
	 * @param array<int, mixed> $message The minted message.
	 * @param list<string>      $fields  Trimmable VALUE keys, in halving order.
	 * @return array<int, mixed>|null The fitting message, or null to drop.
	 */
	public static function fit( array $message, array $fields ): ?array {
		foreach ( $fields as $field ) {
			while ( Message::packed_size( $message ) + 1 > Partition_Node::MAX_LINE_SIZE ) {
				$value = $message[ Message::VALUE ];
				if ( ! \is_array( $value ) ) {
					return null;
				}
				$s = Core::as_string( $value[ $field ] ?? '' );
				if ( '' === $s ) {
					break;
				}
				$value[ $field ]           = \mb_substr( $s, 0, \intdiv( \mb_strlen( $s ), 2 ) );
				$message[ Message::VALUE ] = $value;
			}
			if ( Message::packed_size( $message ) + 1 <= Partition_Node::MAX_LINE_SIZE ) {
				return $message;
			}
		}
		return null;
	}
}
