import { Core, Node, TO, VALUE } from '@newspack-nodes/runtime';

/**
 * `urls:current` — the gate on the `urls:in` Tee → `urls:view` edge that lets
 * through only the answer to a question the `urls` Fetcher is still asking.
 *
 * A reply names the arguments it answers (the server echoes them, TO = FROM
 * addressed it here), and the Fetcher's outbox holds the questions still
 * standing: the poll's live ask, or the one a search or sort change parked
 * with `send( args, subject, true )`, which supersedes every older ask. A
 * reply whose arguments no standing ask carries answers a question the
 * reader has since replaced, so it stops here and never overwrites the newer
 * page. The Fetcher is the Tee's LAST target, so the ask a reply answers is
 * still standing when this reads it.
 *
 * Its one argument names the Fetcher, resolved at each reply, since the graph
 * is rebuilt underneath it.
 */
export class UrlsCurrentNode extends Node {
	/** No Fetcher named yet: until one is, nothing passes. */
	constructor() {
		super();
		/**
		 * The Fetcher whose outbox says what is still asked.
		 *
		 * @type {string}
		 */
		this.asker = '';
	}

	/**
	 * Declared because the setter below would otherwise shadow the inherited
	 * getter away.
	 *
	 * @return {string[]} The `<fetcher>` token.
	 */
	get arguments() {
		return super.arguments;
	}

	/**
	 * @param {string[]} value `<fetcher>`, the Fetcher to read the outbox of.
	 */
	set arguments( value ) {
		super.arguments = value;
		this.asker = ( Array.isArray( value ) && value[ 0 ] ) || '';
	}

	/**
	 * Forward a reply that answers a standing ask; drop any other.
	 *
	 * @param {Array} message A `urls` reply, its VALUE `{ name, arguments, payload }`.
	 */
	fill( message ) {
		const answered = JSON.stringify( message[ VALUE ]?.arguments ?? null );
		const asks = Core.node( this.asker )?.outbox ?? [];
		if ( asks.some( ( ask ) => JSON.stringify( ask.args ) === answered ) ) {
			// The subject it names is the Fetcher's; the view takes it bare.
			message[ TO ] = '';
			super.fill( message );
			return;
		}
		this.counter++;
	}
}
