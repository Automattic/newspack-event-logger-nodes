/**
 * The Performance layout editor's bundle, which `Compositions::enqueue()`
 * loads on an edit load for a `tune` caller alone: the localized context's
 * blocks against this bundle's views, whose module declares the sources and
 * the page state the blocks read.
 */

import { __ } from '@wordpress/i18n';
import { ComposedPage } from '@newspack-nodes/shared/composition/page';
import { mountComposedEditor } from '@newspack-nodes/shared/composition/page/mountComposedEditor';
import { BLOCK_VIEWS } from './sources';
import { GROUPS } from './constants';

/** The page the editor's blocks stand on, which opens and restores nothing. */
const EDITOR_PAGE = {
	group: GROUPS.performance,
	canTune: false,
	operator: false,
	onRestored: () => {},
	openSubject: () => {},
	selectedSubject: null,
	busySubject: null,
};

mountComposedEditor(
	/** @type {{NewspackNodesComposition: import('@newspack-nodes/shared/composition/page/mountComposedEditor').LocalizedComposition}} */ (
		/** @type {unknown} */ ( window )
	).NewspackNodesComposition,
	BLOCK_VIEWS,
	{
		heading: __(
			'Edit the Performance layout',
			'newspack-event-logger-nodes'
		),
		wrap: ( children ) => (
			<ComposedPage.Provider value={ EDITOR_PAGE }>
				{ children }
			</ComposedPage.Provider>
		),
	}
);
