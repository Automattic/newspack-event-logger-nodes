/**
 * The composed Performance page against the page it replaces: on its default
 * layout and the seeded server (`test-helpers/performanceWire`), it reads to
 * a reader of its role tree exactly as the page before it did. The snapshot
 * was recorded from that page before its conversion and is the spec the
 * default layout meets.
 */

import { Core } from '@newspack-nodes/runtime';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import { holdRouterCadence } from '@newspack-nodes/shared/test-utils/holdRouterCadence';
import { roleTree } from '@newspack-nodes/shared/test-utils/roleTree';
import { NOW_MS, replyFor } from '../../test-helpers/performanceWire';
import { act, renderComponent } from '../../test-helpers/renderHook';
import { PAGE_CONTENT_CLASS } from '@newspack-nodes/shared/components/DashboardShell';
import { localizeComposedPage } from '../../test-helpers/localizeComposedPage';
import Page from '../PerformancePage';

/**
 * A page's tree with each axis date masked, since an axis dates its ticks in
 * the host's own zone.
 *
 * @param {Element} container The page.
 * @return {string} The tree.
 */
const tree = ( container ) =>
	roleTree( container ).replace( /\d+\/\d+ \d+:\d+/g, '<date>' );

beforeEach( () => {
	Core.reset();
	holdRouterCadence();
	window.localStorage.clear();
	window.history.replaceState( null, '', '/wp-admin/admin.php?page=x' );
	jest.spyOn( Date, 'now' ).mockReturnValue( NOW_MS );
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	installFakeCommandWire( replyFor );
	localizeComposedPage( 'performance' );
} );

afterEach( () => {
	jest.restoreAllMocks();
} );

it( 'reads the composed page as the Performance page read', async () => {
	const mount = renderComponent(
		<div className={ PAGE_CONTENT_CLASS }>
			<Page onError={ () => {} } />
		</div>
	);
	const { container } = mount;
	for ( let tick = 0; tick < 4; tick++ ) {
		await act( async () => {
			Core.node( '_router' )?.fireCb();
		} );
	}
	expect( tree( container ) ).toMatchSnapshot();
	mount.unmount();
} );
