/**
 * The URL and request windows against the windows they replace: on their
 * default layouts and the seeded server (`test-helpers/performanceWire`),
 * each reads to a reader of its role tree exactly as the fixed window
 * before it did. The snapshots were recorded from those windows before
 * their conversion and are the spec the default layouts meet.
 */

import { Core } from '@newspack-nodes/runtime';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import { holdRouterCadence } from '@newspack-nodes/shared/test-utils/holdRouterCadence';
import { roleTree } from '@newspack-nodes/shared/test-utils/roleTree';
import { PAGE_CONTENT_CLASS } from '@newspack-nodes/shared/components/DashboardShell';
import {
	NOW_MS,
	RID,
	windowReplyFor,
} from '../../test-helpers/performanceWire';
import { act, renderComponent } from '../../test-helpers/renderHook';
import { localizeComposedPage } from '../../test-helpers/localizeComposedPage';
import Page from '../PerformancePage';

/**
 * A window's tree with each date and clock time masked, since both read in
 * the host's own zone.
 *
 * @param {Element} modal The window.
 * @return {string} The tree.
 */
const tree = ( modal ) =>
	roleTree( modal )
		.replace( /\d+\/\d+\/\d+,?/g, '<date>' )
		.replace( /\d+\/\d+/g, '<date>' )
		.replace( /\d{1,2}:\d{2}(:\d{2})?(\.\d+)?( ?[AP]M)?/g, '<time>' );

/** Every page a test mounted, so none outlives its test. */
const mounted = [];

/**
 * Mount the page on a link, answer its asks and read its window.
 *
 * @param {string} search The link's query.
 * @return {Promise<string>} The window's tree.
 */
async function windowOn( search ) {
	window.history.replaceState(
		null,
		'',
		`/wp-admin/admin.php?page=x&${ search }`
	);
	const mount = renderComponent(
		<div className={ PAGE_CONTENT_CLASS }>
			<Page onError={ () => {} } />
		</div>
	);
	mounted.push( mount );
	for ( let tick = 0; tick < 6; tick++ ) {
		await act( async () => {
			Core.node( '_router' )?.fireCb();
		} );
	}
	// The flame graph is a lazy module, which a cold transform resolves late.
	await act( () => new Promise( ( resolve ) => setTimeout( resolve, 50 ) ) );
	const modal = document.body.querySelector(
		'.event-logger-performance-modal'
	);
	return tree( modal );
}

beforeEach( () => {
	Core.reset();
	holdRouterCadence();
	window.localStorage.clear();
	jest.spyOn( Date, 'now' ).mockReturnValue( NOW_MS );
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	installFakeCommandWire( windowReplyFor );
	localizeComposedPage( 'performance' );
} );

afterEach( () => {
	mounted.splice( 0 ).forEach( ( mount ) => mount.unmount() );
	document.body.innerHTML = '';
	jest.restoreAllMocks();
} );

it( 'reads the URL window as the fixed URL window read', async () => {
	expect( await windowOn( 'url=a17e' ) ).toMatchSnapshot();
} );

it( 'reads the request window as the fixed request window read', async () => {
	expect( await windowOn( `url=a17e&request=${ RID }` ) ).toMatchSnapshot();
} );
