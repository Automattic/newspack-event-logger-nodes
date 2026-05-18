<?php
declare(strict_types=1);

namespace Newspack_Event_Logger_Nodes\Tests\Helpers;

use Newspack_Nodes\Message;

/**
 * SSE wire-frame builders for tests that drive the substrate's
 * `/messages/stream` parser via `process_sse_chunk()`. Each method
 * returns a single `event: msg\ndata: <7-field envelope JSON>\n\n`
 * frame; consumers combine them into longer chunks as needed.
 *
 * The envelope shape is the substrate Message wire format:
 *   [ TYPE, TIMESTAMP, FROM, TO, ID, KEY, VALUE ]
 *
 * A `tests/Helpers/` namespace trait so RemoteSourceTest and
 * StreamMergerTest stay byte-for-byte aligned on the wire shape they
 * test — drift between the two has caused a real regression before.
 */
trait SseFrameFactory {

	/**
	 * Wrap a firehose entry dict (`{k, ts, ...}`) in a TM_STRUCT envelope.
	 * Defaults `k` and `ts` so callers can pass `[]` and still get a
	 * forwardable entry; explicit overrides win.
	 *
	 * @param array<string,mixed> $entry Application payload.
	 * @param string              $id    Envelope ID `"seg:off"`. Consumer
	 *                                   stamps these at emit time; tests of
	 *                                   position tracking override it.
	 */
	private function entry_frame( array $entry, string $id = '0:0' ): string {
		$entry   += [ 'k' => 'render', 'ts' => 1700000000 ];
		$envelope = [
			Message::TM_STRUCT,
			(float) $entry['ts'],
			'firehose.p0',
			'',
			$id,
			(string) ( $entry['rid'] ?? '' ),
			$entry,
		];
		return "event: msg\ndata: " . \json_encode( $envelope ) . "\n\n";
	}

	/**
	 * Substrate's `connected` envelope — first frame on every stream.
	 * `dispatch_msg_envelope` captures `slot` from VALUE and never sinks it.
	 */
	private function connected_frame( int $slot ): string {
		$envelope = [
			Message::TM_INFO,
			0.0,
			'_stream',
			'',
			'',
			'connected',
			[ 'pid' => 12345, 'slot' => $slot, 'subscriptions' => [ 'firehose.p0' ], 'interval' => 500 ],
		];
		return "event: msg\ndata: " . \json_encode( $envelope ) . "\n\n";
	}

	/**
	 * Envelope whose ID is `"seg:off"`. Post-M6.7 position rides ID
	 * (Consumer stamps at emit), so any envelope with the matching ID
	 * advances the receiver's cursor. VALUE defaults to a forwardable
	 * entry; pass `$extra_value` to override or add fields.
	 */
	private function position_frame( int $seg, int $off, array $extra_value = [] ): string {
		$value = $extra_value + [ 'k' => 'render', 'ts' => 1700000000 ];
		return $this->entry_frame( $value, "{$seg}:{$off}" );
	}
}
