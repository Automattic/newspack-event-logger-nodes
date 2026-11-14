/* eslint-env jest */
/**
 * A log page hands its frame the header controls and the standing its
 * `LOG_PAGES` entry carries, and nothing its name alone decides.
 */

import { localizeComposedPage } from '../../test-helpers/localizeComposedPage';
import { renderComponent } from '../../test-helpers/renderHook';

/** The props the frame was last handed. */
let framed = null;

jest.mock(
	'@newspack-nodes/shared/composition/page/ComposedPageFrame',
	() => ( props ) => {
		framed = props;
		return null;
	}
);

jest.mock( '../logPageData', () => {
	const actual = jest.requireActual( '../logPageData' );
	return {
		LOG_PAGES: {
			...actual.LOG_PAGES,
			'error-log': {
				...actual.LOG_PAGES[ 'error-log' ],
				controls: 'kea-7990 controls',
				standing: { sourceControls: { 'eln/errors': 'kea-7990' } },
			},
		},
	};
} );

// eslint-disable-next-line import/first -- the mocks above precede it.
import LogPage from '../LogPage';
// eslint-disable-next-line import/first
import { LOG_PAGES } from '../logPageData';

afterEach( () => {
	framed = null;
	delete window.NewspackEventLoggerComposedPage;
} );

test( 'a page takes its header controls and standing from its entry', () => {
	localizeComposedPage( 'error-log' );
	renderComponent( <LogPage page="error-log" /> ).unmount();
	expect( framed.controls ).toBe( 'kea-7990 controls' );
	expect( framed.standing ).toEqual( {
		sourceControls: { 'eln/errors': 'kea-7990' },
	} );
} );

test( 'a page whose entry carries neither hands the frame neither', () => {
	localizeComposedPage( 'request-log' );
	renderComponent( <LogPage page="request-log" /> ).unmount();
	expect( framed.controls ).toBeNull();
	expect( framed.standing ).toBeUndefined();
} );

test( "the Gyroscope's entry carries its legend and its cadence picker", () => {
	expect( LOG_PAGES.gyroscope.controls ).toBeTruthy();
	expect(
		Object.keys( LOG_PAGES.gyroscope.standing.sourceControls )
	).toEqual( [ 'eln/gyroscope' ] );
} );
