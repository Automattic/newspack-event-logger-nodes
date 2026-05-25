import {
	Callback,
	KEY,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import transformCompletedLine from '../transformCompletedLine';

/**
 * `requestlog/transform` — turn a completed-request SSE envelope into a row.
 *
 * Drops the `connected` sentinel and any envelope `transformCompletedLine`
 * rejects (no `url`), then emits a fresh TM_STRUCT row message to its sink
 * (Callback doesn't forward, so the closure pushes to `node.sink` itself).
 *
 * @param {string} name Node name.
 * @return {Callback} The transform node.
 */
export function createRequestLogTransform( name ) {
	const node = new Callback( ( envelope ) => {
		if ( 'connected' === envelope[ KEY ] ) {
			return;
		}
		const row = transformCompletedLine( envelope );
		if ( ! row ) {
			return;
		}
		if ( ! node.sink ) {
			return;
		}
		const out = newMessage();
		out[ TYPE ] = TM_STRUCT;
		out[ VALUE ] = row;
		node.sink.fill( out );
	} );
	node.setName( name );
	return node;
}
