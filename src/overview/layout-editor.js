/**
 * The Performance layout editor's bundle, which `Compositions::enqueue()`
 * loads on an edit load for a `tune` caller alone: the localized context's
 * blocks against this bundle's views, whose module declares the sources and
 * the page state the blocks read, under the heading of the layout the load
 * edits, the page's own or one of its windows'.
 */

import { useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ComposedPage } from '@newspack-nodes/shared/composition/page';
import { mountComposedEditor } from '@newspack-nodes/shared/composition/page/mountComposedEditor';
import { BLOCK_VIEWS } from './sources';
import { GROUPS } from './constants';
import { DEFAULT_REQUEST_SORT, nextSort } from './components/RequestTable';
import {
	REQUEST_WINDOW_CONTEXT,
	URL_WINDOW_CONTEXT,
} from './performancePageData';

/** Each window's editor heading and group, by its context. */
const WINDOWS = {
	[ URL_WINDOW_CONTEXT ]: {
		heading: __(
			'Edit the URL window layout',
			'newspack-event-logger-nodes'
		),
		group: GROUPS.url,
	},
	[ REQUEST_WINDOW_CONTEXT ]: {
		heading: __(
			'Edit the request window layout',
			'newspack-event-logger-nodes'
		),
		group: GROUPS.request,
	},
};

const localized =
	/** @type {{NewspackNodesComposition: import('@newspack-nodes/shared/composition/page/mountComposedEditor').LocalizedComposition}} */ (
		/** @type {unknown} */ ( window )
	).NewspackNodesComposition;

const { heading, group } = WINDOWS[ localized.context ] ?? {
	heading: __( 'Edit the Performance layout', 'newspack-event-logger-nodes' ),
	group: GROUPS.performance,
};

/**
 * The page the editor's blocks stand on, sending under the group of the
 * layout it edits, which opens and restores nothing: a preview's list
 * sorts in the editor alone, and each editor mount keeps its own reveal.
 *
 * @param {Object}                    props
 * @param {import('react').ReactNode} props.children The editor.
 * @return {import('react').ReactElement} The editor on its page.
 */
function EditorPage( { children } ) {
	const [ requestSort, setRequestSort ] = useState( DEFAULT_REQUEST_SORT );
	const revealRef = useRef( null );
	const page = useMemo(
		() => ( {
			group,
			canTune: false,
			operator: false,
			onRestored: () => {},
			openSubject: () => {},
			selectedSubject: null,
			busySubject: null,
			selectRequest: () => {},
			requestSort,
			sortRequests: ( field ) =>
				setRequestSort( ( held ) => nextSort( held, field ) ),
			revealRef,
			windowAsks: true,
		} ),
		[ requestSort ]
	);
	return (
		<ComposedPage.Provider value={ page }>
			{ children }
		</ComposedPage.Provider>
	);
}

mountComposedEditor( localized, BLOCK_VIEWS, {
	heading,
	wrap: ( children ) => <EditorPage>{ children }</EditorPage>,
} );
