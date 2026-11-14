/**
 * The Performance page's header controls sit in the shell header, whose
 * subtitle never yields width: the controls' own box wraps instead, so a
 * narrow header stacks them rather than drawing them over the page's name.
 * The page state and the Ask trigger are mocked; only the box is asserted.
 */

import * as React from 'react';
import PerformanceHeader from '../PerformanceHeader';
import { renderComponent } from '../../../test-helpers/renderHook';

jest.mock( '@newspack-nodes/shared/composition/page/pageState', () => ( {
	...jest.requireActual(
		'@newspack-nodes/shared/composition/page/pageState'
	),
	usePageState: () => [ 7, () => {} ],
} ) );
jest.mock( '../AskPanel', () => ( {
	AskButton: () => null,
} ) );

describe( 'PerformanceHeader', () => {
	it( 'wraps its controls, packed to the end, rather than overflowing', () => {
		const { container, unmount } = renderComponent(
			React.createElement( PerformanceHeader, {
				ask: {},
				searchQuery: 'okapi',
				setSearchQuery: () => {},
				searchLoading: false,
				searchError: null,
				onSearch: () => {},
			} )
		);

		const box = container.firstElementChild;
		expect( box.style.flexWrap ).toBe( 'wrap' );
		expect( box.style.justifyContent ).toBe( 'flex-end' );
		unmount();
	} );
} );
