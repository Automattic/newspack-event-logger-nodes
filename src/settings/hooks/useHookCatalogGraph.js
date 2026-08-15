/**
 * useHookCatalogGraph — the registered-hook taxonomy behind the hook picker, as
 * a batched-poll slice.
 *
 * It used to be a reconciled load over its own Request node: one POST per open,
 * minted from a React effect and outside the router's lock/flush bracket. The
 * ask rides the tick now, so it travels in the same request as everything else
 * that tick, and `enabled` means a picker that is never opened costs nothing.
 *
 * HookSelectorModal has no error UI, which is why a refusal must not be the end
 * of the story: it is one tick that published nothing, and the next fills the
 * picker.
 */

import { useNodeState } from '@newspack-nodes/runtime';
import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import '../nodes/register';

const FETCHER = 'hookcatalog:fetch';
const RECEIVER = 'hookcatalog:in';
const VIEW = 'hookcatalog:view';

/** The taxonomy is near-static; the picker does not need every tick. */
const POLL_INTERVAL_MS = 10000;

const EMPTY = { hooksByCategory: null, descriptions: {}, error: null };

/**
 * @param {Object}  [opts]        Options.
 * @param {boolean} [opts.isOpen] Gate — a picker that is never opened asks for
 *                                nothing at all.
 * @return {{hooksByCategory: Object, descriptions: Object, loading: boolean}}
 *   The taxonomy for the picker, its category one-liners, and whether the first
 *   reply is still outstanding.
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
				viewClass: 'HookCatalogView',
				tee,
				target: '_shell/_http/performance',
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
