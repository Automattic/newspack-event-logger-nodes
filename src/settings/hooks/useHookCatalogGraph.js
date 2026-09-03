/**
 * useHookCatalogGraph — the registered-hook taxonomy behind the hook picker, as
 * a batched-poll slice.
 *
 * The ask rides the router's tick, so it travels in the same POST as everything
 * else due that tick, and `isOpen` gates the whole graph: a picker nobody opens
 * mounts no nodes and sends no request.
 *
 * An open picker keeps asking, which is what a modal carrying no error UI
 * needs. A refusal publishes an error rather than a taxonomy, so it clears the
 * spinner and leaves the next tick to fill the picker, and a plugin activated
 * while the picker is on screen reaches the taxonomy on the following tick.
 */

import { useNodeState } from '@newspack-nodes/runtime';
import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import { views } from '../nodes/register';
import { egressPath } from '@newspack-nodes/shared/helpers/egressPath';

/** Fetcher node: turns each tick into one `hooks_registered` command. */
const FETCHER = 'hookcatalog:fetch';

/** Receiver Tee: the Fetcher's FROM, so the CI's reply routes back here. */
const RECEIVER = 'hookcatalog:in';

/** View node: parses the reply and publishes the slice `useNodeState` reads. */
const VIEW = 'hookcatalog:view';

/**
 * Poll cadence. The taxonomy moves when a plugin registers a hook rather than
 * with traffic, so the picker asks on a slow retry instead of every tick.
 */
const POLL_INTERVAL_MS = 10000;

/**
 * The model read while no view node exists: a closed picker mounts none, and
 * the first render of an open one precedes the mount. It matches the view
 * class's own empty slice, where `hooksByCategory: null` marks a taxonomy that
 * has not arrived.
 */
const EMPTY = { hooksByCategory: null, descriptions: {}, error: null };

/**
 * Poll the `performance` CI's `hooks_registered` verb while the picker is open,
 * and hand back the taxonomy it publishes.
 *
 * @param {Object}  [opts]        Options.
 * @param {boolean} [opts.isOpen] Gate — a picker that is never opened asks for
 *                                nothing at all.
 * @return {{hooksByCategory: Object, descriptions: Object, loading: boolean}}
 *   The taxonomy as a map the modal subscripts before it lands, its category
 *   one-liners, and whether the picker is still waiting — true until either a
 *   taxonomy or a refusal arrives.
 */
export function useHookCatalogGraph( opts = {} ) {
	const { isOpen } = opts;

	useBatchedPoll( {
		build: ( { interpreter, tee } ) =>
			addSliceFetcher( interpreter, {
				fetcher: FETCHER,
				receiver: RECEIVER,
				command: 'hooks_registered',
				view: VIEW,
				viewClass: views.HookCatalogView,
				tee,
				target: egressPath( 'performance' ),
			} ),
		timerName: 'hookcatalog:timer',
		teeName: 'hookcatalog:tee',
		enabled: Boolean( isOpen ),
		intervalMs: POLL_INTERVAL_MS,
	} );

	const model = useNodeState( VIEW, 'view' ) ?? EMPTY;

	return {
		hooksByCategory: model.hooksByCategory ?? {},
		descriptions: model.descriptions,
		// A refusal is an answer too: clear the spinner, keep asking.
		loading:
			Boolean( isOpen ) &&
			null === model.hooksByCategory &&
			! model.error,
	};
}
