/* eslint-env jest */
/**
 * The Performance layout editor's bundle mounts the localized composition's
 * editor against this bundle's block views on import, its blocks standing on
 * a page that opens and restores nothing.
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
} );
