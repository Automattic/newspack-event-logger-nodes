/**
 * A log page: the Request Log, the Error Log or the Gyroscope, each a composed
 * page whose default layout is one log-stream block over the page's stream,
 * its toolbar in the page header, in the one frame every composed page
 * wears, the ⋯ menu offering **Edit layout** to a `tune` reader. The
 * Gyroscope's header also holds its legend, and its stream's block the
 * cadence its snapshot is taken at.
 */

import ComposedPageFrame from '@newspack-nodes/shared/composition/page/ComposedPageFrame';
import { BLOCK_VIEWS } from './sources';
import { LOG_PAGES, logPage } from './logPageData';
import { CadencePicker, GyroscopeLegend } from './GyroscopeControls';

/** What the Gyroscope stands its blocks on: the cadence its stream's toolbar holds. */
const GYROSCOPE_STANDING = {
	sourceControls: { 'eln/gyroscope': <CadencePicker /> },
};

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
	const { canTune, canManage, compositions } = logPage();
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
			controls={ 'gyroscope' === page ? <GyroscopeLegend /> : null }
			standing={ 'gyroscope' === page ? GYROSCOPE_STANDING : undefined }
		/>
	);
}
