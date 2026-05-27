import {
	Callback,
	TO,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import transformGyroscopeLine from '../transformGyroscopeLine';

/**
 * `gyroscope:transform` — turn a gyroscope SSE envelope into a dispatch object.
 *
 * Wraps the existing `transformGyroscopeLine`, which returns the dispatch shape
 * the in-flight model consumes — `{ type: 'inflight', requests }` for a periodic
 * snapshot, `{ type: 'complete', request }` for a completion, or `null` for the
 * `connected` sentinel and any non-gyroscope / unrecognized line. Null results are
 * dropped; everything else is emitted as a fresh TM_STRUCT message through its
 * sink (the exospine CI) stamped `TO = target` (→ `gyroscope:view`). Callback
 * doesn't forward, so the closure stamps + pushes to `node.sink` itself.
 *
 * @param {string} name Node name.
 * @return {Callback} The transform node.
 */
export function createGyroscopeTransform( name ) {
	const node = new Callback( ( envelope ) => {
		const out = transformGyroscopeLine( envelope );
		if ( ! out ) {
			return;
		}
		if ( ! node.sink ) {
			return;
		}
		const msg = newMessage();
		msg[ TYPE ] = TM_STRUCT;
		msg[ TO ] = node.target;
		msg[ VALUE ] = out;
		node.sink.fill( msg );
	} );
	node.setName( name );
	return node;
}
