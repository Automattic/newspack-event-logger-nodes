/* eslint-env jest */
/**
 * The log pages' layout editor mounts the localized composition's editor on
 * import, headed by the name of the page `?edit=` chose, its blocks standing
 * on that page's group.
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
import { GROUPS } from '../../overview/constants';
import { localizeComposedPage } from '../../test-helpers/localizeComposedPage';
import { renderComponent } from '../../test-helpers/renderHook';

it( 'mounts the editor for the chosen page, on its group and its name', () => {
	localizeComposedPage( 'error-log' );
	const localized = { context: 'newspack-event-logger-nodes/error-log' };
	window.NewspackNodesComposition = localized;
	require( '../layout-editor' );
	expect( mountComposedEditor ).toHaveBeenCalledTimes( 1 );
	const [ composition, views, options ] = mountComposedEditor.mock.calls[ 0 ];
	expect( composition ).toBe( localized );
	expect( views ).toBe( mockViews );
	expect( options.heading ).toBe( 'Edit the Error Log layout' );

	let page = null;
	const Reader = () => {
		page = usePage();
		return null;
	};
	renderComponent( options.wrap( <Reader /> ) ).unmount();
	expect( page.group ).toBe( GROUPS.errorLog );
	expect( page.canTune ).toBe( false );
	expect( page.operator ).toBe( false );
	expect( page.onRestored() ).toBeUndefined();
} );
