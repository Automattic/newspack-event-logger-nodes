/**
 * `gyroscope:route` — the classifier that makes the data/control split a
 * first-class, inspectable node instead of a bespoke `controlSink` edge.
 *
 * It receives everything `gyroscope:stream` emits (sink = the CI, like every
 * exospine node) and stamps `TO` per class: a connection-status control (the
 * stream stamps `KEY = 'connection'`) → the view, every other (data) envelope →
 * the transform. Classification keys off the stream-set KEY, NOT VALUE content —
 * an arbitrary streamed gyroscope envelope may legitimately carry a `VALUE.action`
 * field, so keying on VALUE would misroute real data. Its data target is the node
 * `target` (so the hop shows in `ls -t`); the control target is a sibling field.
 */

import { Node, TO, KEY } from '@newspack-nodes/runtime';

// The KEY marker the stream stamps on a synthesized connection-status control.
// Wire gyroscope envelopes carry a snapshot/rid KEY (e.g. 'inflight', 'rid-1'),
// never this.
const CONTROL_KEY = 'connection';

class GyroscopeRouteNode extends Node {
	constructor( dataTarget, controlTarget ) {
		super();
		// Data → transform via the normal target mechanism (visible in `ls -t`).
		this.target = dataTarget;
		// Control (connection status) → view, skipping the transform.
		this.controlTarget = controlTarget;
	}

	fill( message ) {
		this.counter += 1;
		const isControl = CONTROL_KEY === message[ KEY ];
		message[ TO ] = isControl ? this.controlTarget : this.target;
		if ( this.sink ) {
			this.sink.fill( message );
		}
	}
}

/**
 * Create and register the Gyroscope route node.
 *
 * @param {string} name                  Node name.
 * @param {Object} targets               Class targets.
 * @param {string} targets.dataTarget    Where data envelopes route.
 * @param {string} targets.controlTarget Where connection-status controls route.
 * @return {GyroscopeRouteNode} The route node.
 */
export function createGyroscopeRoute( name, { dataTarget, controlTarget } ) {
	const node = new GyroscopeRouteNode( dataTarget, controlTarget );
	node.setName( name );
	return node;
}
