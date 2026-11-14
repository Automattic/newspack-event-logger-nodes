/**
 * A log page: the Request Log, the Error Log or the Gyroscope, each a composed
 * page whose default layout is one log-stream block over the page's stream,
 * its toolbar in the page header, in the one frame every composed page
 * wears, the ⋯ menu offering **Edit layout** to a `tune` reader. A page's
 * entry names what its header and its blocks stand beside the frame's own:
 * the Gyroscope's legend, and the cadence its snapshot is taken at.
 */

import ComposedPageFrame from '@newspack-nodes/shared/composition/page/ComposedPageFrame';
import { BLOCK_VIEWS } from './sources';
import { composedPage } from '../composedPageData';
import { LOG_PAGES } from './logPageData';

/**
 * @param {Object}   props
 * @param {string}   props.page                 The page, as `?edit=` names it.
 * @param {?Element} [props.headerControlsSlot] The shell header's slot; null while it is pending, undefined renders the controls inline.
 * @return {import('react').ReactElement} The page.
 * @throws {Error} On a page `LOG_PAGES` does not name.
 */
export default function LogPage( { page, headerControlsSlot } ) {
	const entry = LOG_PAGES[ page ];
	if ( ! entry ) {
		throw new Error( `no log page is named '${ page }'` );
	}
	const { canTune, canManage, compositions } = composedPage();
	return (
		<ComposedPageFrame
			context={ entry.context }
			page={ page }
			pages={ { [ page ]: { view: null, subject: null } } }
			group={ entry.group }
			canTune={ canTune }
			canManage={ canManage }
			layout={ compositions[ entry.context ] }
			views={ BLOCK_VIEWS }
			headerControlsSlot={ headerControlsSlot }
			controls={ entry.controls ?? null }
			standing={ entry.standing }
		/>
	);
}
