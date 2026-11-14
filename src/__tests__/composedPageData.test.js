/* eslint-env jest */
/**
 * The one reader of the page data every composed page's load sets, and the
 * one helper that sets it for a test, which every page's tests share.
 */

import { composedPage } from '../composedPageData';
import { localizeComposedPage } from '../test-helpers/localizeComposedPage';

afterEach( () => {
	delete window.NewspackEventLoggerComposedPage;
} );

test( 'it reads what the load set', () => {
	window.NewspackEventLoggerComposedPage = {
		page: 'error-log',
		canTune: true,
		canManage: false,
		compositions: { kea: { blocks: [] } },
	};
	expect( composedPage().compositions ).toEqual( { kea: { blocks: [] } } );
} );

test( 'it refuses a page whose load set none, naming the global', () => {
	expect( () => composedPage() ).toThrow( 'NewspackEventLoggerComposedPage' );
} );

test( 'a log page holds its own default layout and no stats window', () => {
	localizeComposedPage( 'gyroscope', { canTune: true } );
	const data = composedPage();
	expect( data.page ).toBe( 'gyroscope' );
	expect( data.canTune ).toBe( true );
	expect( Object.keys( data.compositions ) ).toEqual( [
		'newspack-event-logger-nodes/gyroscope',
	] );
	expect(
		data.compositions[ 'newspack-event-logger-nodes/gyroscope' ].blocks[ 0 ]
			.name
	).toBe( 'newspack-nodes/log-stream' );
	expect( data ).not.toHaveProperty( 'window' );
} );

test( 'the Performance page holds the window it is given, else 24 buckets', () => {
	localizeComposedPage( 'performance', { window: 18 } );
	expect( composedPage().window ).toBe( 18 );
	localizeComposedPage( 'performance' );
	expect( composedPage().window ).toBe( 24 );
	expect(
		composedPage().compositions[ 'newspack-event-logger-nodes/performance' ]
			.blocks[ 0 ].name
	).toBe( 'newspack-nodes/stats' );
} );
