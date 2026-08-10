/**
 * useHookCatalogGraph — the Performance Logger hook catalog for the fire-on-OPEN
 * selector modal, fetched through one node on the canonical rule-#2 backbone
 * (`_command_interpreter → _router`).
 *
 *   _http                (HttpOutNode — POST /command boundary)
 *   hookcatalog:request  (Request — mints `hooks_registered`, holds the reply)
 *
 * There is nothing to correlate: the command is minted FROM that node, the
 * server replies TO = FROM, and the reply lands there. The catalog it carries
 * IS the model, so it is held here rather than published into a node the modal
 * would then have to read back.
 *
 * Dashboards aren't REPLs: no transcript window, no tab-completion input, no
 * uptime display, no `cd` navigation. So `_output` / `_completion` / `_uptime` /
 * `_cwd` are NOT mounted here — they'd be dead weight and would collide with
 * the debug-overlay's REPL when it opens on this page.
 *
 * Error contract: a failure clears the spinner with an empty catalog —
 * HookSelectorModal has no error UI — while `useReconcile` keeps asking, so a
 * refused session re-establishes itself instead of showing "no hooks" forever.
 *
 * Nothing is injected: HttpOut lazily defaults its own client, and tests seam
 * at `fetch` (`installFakeCommandWire`) so the whole egress runs for real.
 * to `_http.client`) so the hook never touches the network. Production lazily
 * defaults (inside HttpOut) to the localized transport.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { mountExospine } from '@newspack-nodes/runtime';
import useReconcile from '@newspack-nodes/shared/hooks/useReconcile';
import useRequestNode from '@newspack-nodes/shared/hooks/useRequestNode';

/**
 * @param {Object}  [opts]        Options.
 * @param {boolean} [opts.isOpen] When true, fires one hook-catalog fetch.
 * @return {{ hooksByCategory: Object, descriptions: Object, loading: boolean }} The render model.
 */
export function useHookCatalogGraph( opts = {} ) {
	const { isOpen } = opts;

	const optsRef = useRef( opts );
	optsRef.current = opts;

	const [ hooksByCategory, setHooksByCategory ] = useState( {} );
	// Category one-liners travel with the taxonomy that owns them.
	const [ descriptions, setDescriptions ] = useState( {} );

	// Mount the backbone once; HttpOut defaults its own transport.
	useEffect( () => mountExospine().teardown, [] );

	const request = useRequestNode( 'hookcatalog:request', 'performance' );

	const load = useCallback( async () => {
		try {
			const payload = await request( 'hooks_registered' );
			setHooksByCategory( payload?.hooks_by_category ?? {} );
			setDescriptions( payload?.category_descriptions ?? {} );
		} catch ( e ) {
			// Clear the spinner with an empty catalog; the loop keeps asking.
			setHooksByCategory( {} );
			setDescriptions( {} );
			throw e;
		}
	}, [ request ] );

	// isOpen is a DEP too: re-opening the modal is a fresh ask.
	const { settled, error } = useReconcile( {
		load,
		enabled: isOpen,
		deps: [ isOpen ],
	} );

	return {
		hooksByCategory,
		descriptions,
		loading: Boolean( isOpen ) && ! settled && ! error,
	};
}
