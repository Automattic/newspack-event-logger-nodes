/**
 * The log pages' layout editor, which `Log_Pages::enqueue()` loads on an edit
 * load for a `tune` caller alone: the localized context's blocks against
 * this bundle's views, whose module declares the sources and the page state
 * the blocks read.
 */

import { sprintf, __ } from '@wordpress/i18n';
import { ComposedPage } from '@newspack-nodes/shared/composition/page';
import { mountComposedEditor } from '@newspack-nodes/shared/composition/page/mountComposedEditor';
import { BLOCK_VIEWS } from './sources';
import { LOG_PAGES, logPage } from './logPageData';

const { subtitle, group } = LOG_PAGES[ logPage().page ];

mountComposedEditor(
	/** @type {{NewspackNodesComposition: import('@newspack-nodes/shared/composition/page/mountComposedEditor').LocalizedComposition}} */ (
		/** @type {unknown} */ ( window )
	).NewspackNodesComposition,
	BLOCK_VIEWS,
	{
		heading: sprintf(
			// translators: %s: the page's name, such as Request Log.
			__( 'Edit the %s layout', 'newspack-event-logger-nodes' ),
			subtitle
		),
		wrap: ( children ) => (
			<ComposedPage.Provider
				value={ {
					group,
					canTune: false,
					operator: false,
					onRestored: () => {},
				} }
			>
				{ children }
			</ComposedPage.Provider>
		),
	}
);
