/* eslint-env jest */
/**
 * The Performance layout editor's bundle mounts the localized composition's
 * editor against this bundle's block views on import, its blocks standing on
 * a page that opens and restores nothing, under the heading of whichever of
 * the page's layouts the load edits.
 */

const mockViews = {
	'newspack-event-logger-nodes/kea-7990': { View: () => null },
};
jest.mock( '../sources', () => ( { BLOCK_VIEWS: mockViews } ) );
jest.mock(
	'@newspack-nodes/shared/composition/page/mountComposedEditor',
	() => ( { mountComposedEditor: jest.fn() } )
);

import { usePage } from '@newspack-nodes/shared/composition/page';
import { mountComposedEditor } from '@newspack-nodes/shared/composition/page/mountComposedEditor';
import { GROUPS } from '../constants';
import { renderComponent } from '../../test-helpers/renderHook';

it( 'mounts the editor on the localized composition and this bundle’s views', () => {
	const localized = { context: 'newspack-event-logger-nodes/performance' };
	window.NewspackNodesComposition = localized;
	require( '../layout-editor' );
	expect( mountComposedEditor ).toHaveBeenCalledTimes( 1 );
	const [ composition, views, options ] = mountComposedEditor.mock.calls[ 0 ];
	expect( composition ).toBe( localized );
	expect( views ).toBe( mockViews );
	expect( options.heading ).toBe( 'Edit the Performance layout' );

	let page = null;
	const Reader = () => {
		page = usePage();
		return null;
	};
	const wrapped = options.wrap( <Reader /> );
	renderComponent( wrapped ).unmount();
	expect( page.group ).toBe( GROUPS.performance );
	expect( page.canTune ).toBe( false );
	expect( page.operator ).toBe( false );
	expect( page.selectedSubject ).toBeNull();
	expect( page.busySubject ).toBeNull();
	expect( page.onRestored() ).toBeUndefined();
	expect( page.openSubject() ).toBeUndefined();
	expect( page.selectRequest( 'rid-kea-7928' ) ).toBeUndefined();
	expect( page.revealRef ).toEqual( { current: null } );
} );

it( 'sorts the preview’s list on a heading, and gives each editor mount a reveal of its own', () => {
	mountComposedEditor.mockClear();
	window.NewspackNodesComposition = {
		context: 'newspack-event-logger-nodes/url-detail',
	};
	let page = null;
	// One registry for the bundle, React and the renderer alike.
	jest.isolateModules( () => {
		require( '../layout-editor' );
		const {
			usePage: isolatedUsePage,
		} = require( '@newspack-nodes/shared/composition/page' );
		const {
			act: isolatedAct,
			renderComponent: isolatedRender,
		} = require( '../../test-helpers/renderHook' );
		const Reader = () => {
			page = isolatedUsePage();
			return null;
		};
		const { wrap } = mountComposedEditor.mock.calls[ 0 ][ 2 ];
		const first = isolatedRender( wrap( <Reader /> ) );
		expect( page.requestSort ).toEqual( {
			field: 'timestamp',
			dir: 'desc',
		} );
		isolatedAct( () => page.sortRequests( 'duration_ms' ) );
		expect( page.requestSort ).toEqual( {
			field: 'duration_ms',
			dir: 'desc',
		} );
		isolatedAct( () => page.sortRequests( 'duration_ms' ) );
		expect( page.requestSort.dir ).toBe( 'asc' );
		const reveal = page.revealRef;
		first.unmount();
		const second = isolatedRender( wrap( <Reader /> ) );
		expect( page.revealRef ).not.toBe( reveal );
		second.unmount();
	} );
} );

it.each( [
	[ 'url-detail', 'Edit the URL window layout', GROUPS.url ],
	[ 'request-detail', 'Edit the request window layout', GROUPS.request ],
] )(
	'heads the %s editor with its window, sending under its group',
	( layout, heading, group ) => {
		mountComposedEditor.mockClear();
		window.NewspackNodesComposition = {
			context: `newspack-event-logger-nodes/${ layout }`,
		};
		let page = null;
		jest.isolateModules( () => {
			require( '../layout-editor' );
			const {
				usePage: isolatedUsePage,
			} = require( '@newspack-nodes/shared/composition/page' );
			const {
				renderComponent: isolatedRender,
			} = require( '../../test-helpers/renderHook' );
			const Reader = () => {
				page = isolatedUsePage();
				return null;
			};
			const [ , , options ] = mountComposedEditor.mock.calls[ 0 ];
			expect( options.heading ).toBe( heading );
			isolatedRender( options.wrap( <Reader /> ) ).unmount();
		} );
		expect( page.group ).toBe( group );
	}
);
