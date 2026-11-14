/**
 * The substrate's shared blocks every composed page here admits, by block
 * name, imported one module each, as `Admin\Compositions::ADMITS` lists
 * them: every shared block a source here feeds. The table is not among
 * them, so no page bundle imports `@wordpress/dataviews`.
 */

import logStream from '@newspack-nodes/shared/blocks/log-stream';
import section from '@newspack-nodes/shared/blocks/section';
import sparkline from '@newspack-nodes/shared/blocks/sparkline';
import stats from '@newspack-nodes/shared/blocks/stats';
import timeChart from '@newspack-nodes/shared/blocks/time-chart';

/**
 * Block name => its View and inspector Controls.
 *
 * @type {Object<string,import('@newspack-nodes/shared/composition/CompositionView').CompositionBlockView>}
 */
export const ADMITTED_VIEWS = {
	'newspack-nodes/stats': stats,
	'newspack-nodes/time-chart': timeChart,
	'newspack-nodes/log-stream': logStream,
	'newspack-nodes/sparkline': sparkline,
	'newspack-nodes/section': section,
};
