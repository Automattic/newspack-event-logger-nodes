/**
 * The log pages' shell: each page in one exact skinned provider, never
 * repeated on the page root, heading the page by its own name.
 *
 * The page is mocked so the wrapper renders without the stream graph.
 */

jest.mock( '../log-pages/LogPage', () => ( {
	__esModule: true,
	default: ( { page } ) => `LOG_PAGE[${ page }]`,
} ) );

import { LogPageShell } from '../log-pages';
import { act, renderComponent } from '../test-helpers/renderHook';

const TOKEN_ROOT_CLASSES = [
	'topology-app',
	'newspack-nodes-skin-root',
	'newspack-nodes-theme',
	'newspack-nodes-ui',
];

function expectNoTokenRoot( element ) {
	for ( const className of TOKEN_ROOT_CLASSES ) {
		expect( element.classList.contains( className ) ).toBe( false );
	}
}

describe( 'the log page shell', () => {
	it.each( [
		[ 'request-log', 'Request Log' ],
		[ 'error-log', 'Error Log' ],
		[ 'gyroscope', 'Gyroscope' ],
	] )(
		'mounts the %s page under its own heading',
		async ( page, heading ) => {
			const { container, unmount } = renderComponent(
				<LogPageShell page={ page } />
			);
			await act( async () => {} );
			expect( container.textContent ).toContain( `LOG_PAGE[${ page }]` );
			expect( container.textContent ).toContain( heading );
			unmount();
		}
	);

	it( 'uses one exact skinned provider without repeating it on the page root', () => {
		const { container, unmount } = renderComponent(
			<LogPageShell page="error-log" />
		);
		const provider = container.firstElementChild;
		expect( provider.className ).toBe(
			'newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui'
		);
		expect( provider.style.display ).toBe( 'contents' );
		expectNoTokenRoot( provider.firstElementChild );
		unmount();
	} );
} );
