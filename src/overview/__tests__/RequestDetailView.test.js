/**
 * Tests for RequestDetailView's folded-request surface.
 *
 * A folded request keeps its head and tail and reports the middle as merged
 * spans, which `PerformanceDashboard` splices into the entry list before this
 * view sees it. So the view's whole job here is to SAY the request was
 * aggregated — otherwise a short entry list reads as "nothing happened", which
 * is the opposite of what a folded request means.
 */

jest.mock( '../FlameGraph', () => () => null );

import * as React from 'react';
import RequestDetailView from '../components/RequestDetailView';
import { renderComponent } from '../../test-helpers/renderHook';

const FOLDED = {
	url: '/periodical',
	timestamp: 1700000000,
	duration_ms: 612000,
	status_code: 200,
	error_status: '-',
	folded: true,
};

const KEPT = [
	{ n: 1, k: 'process (start)', m: '395 on host', indent: 0 },
	{ n: 2, k: 'entries (aggregated)', m: '63 entries merged', indent: 1 },
	{ n: '', k: 'pyrobase', m: '200 merged · first at +8.7ms', indent: 1 },
	{ n: 3, k: 'process (complete)', m: '(612000ms)', indent: 0 },
];

const render = ( detail, entries = [] ) =>
	renderComponent(
		React.createElement( RequestDetailView, {
			requestDetail: detail,
			flameData: null,
			indentedEntries: entries,
			realEntryCount: entries.length,
		} )
	);

describe( 'RequestDetailView folded requests', () => {
	it( 'says the request was aggregated rather than implying completeness', () => {
		const { container } = render( FOLDED, KEPT );
		expect( container.textContent ).toMatch( /Aggregated under load/ );
	} );

	it( 'does not claim there is no detail when the request folded', () => {
		// The empty state exists for a request nothing was logged for. A folded
		// request logged more than any other, which is the opposite problem.
		const { container } = render( FOLDED, KEPT );
		expect( container.textContent ).not.toMatch(
			/No log entries available/
		);
	} );

	it( 'renders the kept entries and the merged spans as one log', () => {
		// Merged spans are ordinary rows by the time they reach here — that is
		// the point of splicing them in rather than tabling them separately.
		const { container } = render( FOLDED, KEPT );

		expect( container.textContent ).toMatch( /process \(start\)/ );
		expect( container.textContent ).toMatch( /entries \(aggregated\)/ );
		expect( container.textContent ).toMatch( /200 merged/ );
		expect( container.textContent ).toMatch( /process \(complete\)/ );
	} );

	it( 'leaves an ordinary request untouched', () => {
		const { container } = render( { ...FOLDED, folded: false } );
		expect( container.textContent ).not.toMatch( /Aggregated under load/ );
	} );
} );
