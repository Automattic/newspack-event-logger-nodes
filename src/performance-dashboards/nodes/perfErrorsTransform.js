import {
	Callback,
	KEY,
	TO,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import transformErrorLine from '../transformErrorLine';

/**
 * `perferrors:transform` — turn an errors-feed SSE envelope into a row.
 *
 * Drops the `connected` sentinel and any envelope `transformErrorLine`
 * rejects (no rid), then emits a fresh TM_STRUCT row message through its sink
 * (the exospine CI) stamped `TO = target` (→ `perferrors:view`). Callback doesn't
 * forward, so the closure stamps + pushes to `node.sink` itself.
 *
 * @param {string} name Node name.
 * @return {Callback} The transform node.
 */
export function createPerfErrorsTransform( name ) {
	const node = new Callback( ( envelope ) => {
		if ( 'connected' === envelope[ KEY ] ) {
			return;
		}
		const row = transformErrorLine( envelope );
		if ( ! row ) {
			return;
		}
		if ( ! node.sink ) {
			return;
		}
		const out = newMessage();
		out[ TYPE ] = TM_STRUCT;
		out[ TO ] = node.target;
		out[ VALUE ] = row;
		node.sink.fill( out );
	} );
	node.setName( name );
	return node;
}
