/**
 * The request window's blocks, laid out as the window's default layout
 * reads, over their components. The data seam is the source: `mockDetail`
 * is what `dump_request` answered for the request page state names, its
 * `entries` the stored log and its `flame_data` the built flame.
 *
 * Children mocked at the module boundary:
 *   - FlameGraph (lazy)
 *   - RequestProfile
 *   - LogEntriesTable, in the first suite; the folded suite reads the real one
 *
 * Each mock renders a marker element so we can assert sections appear /
 * are omitted based on the request payload shape.
 */
jest.mock( '../../FlameGraph', () => ( {
	__esModule: true,
	default: ( props ) => {
		global.__requestDetailFlameProps = props;
		return 'FLAME_GRAPH';
	},
} ) );
jest.mock( '../../RequestProfile', () => ( {
	...jest.requireActual( '../../RequestProfile' ),
	__esModule: true,
	default: () => 'REQUEST_PROFILE',
} ) );
// On, the log is the real one, as the folded suite reads it.
let mockRealLog = false;
jest.mock( '../../components/LogEntriesTable', () => ( {
	__esModule: true,
	default: function MockLogEntriesTable( props ) {
		const { revealRef } = props;
		const React = jest.requireActual( 'react' );
		React.useEffect( () => {
			global.__requestDetailTableMounts =
				( global.__requestDetailTableMounts || 0 ) + 1;
		}, [] );
		if ( mockRealLog ) {
			const Real = jest.requireActual(
				'../../components/LogEntriesTable'
			).default;
			return <Real { ...props } />;
		}
		global.__requestDetailReveal = jest.fn();
		if ( revealRef ) {
			revealRef.current = global.__requestDetailReveal;
		}
		return 'LOG_ENTRIES_TABLE';
	},
} ) );

/** What `dump_request` answered, or null before it does. */
let mockDetail = null;
/** The request page state names. */
let mockRequest = '';

jest.mock( '@newspack-nodes/shared/sources', () => ( {
	...jest.requireActual( '@newspack-nodes/shared/sources' ),
	useSource: () => ( {
		model: mockDetail,
		loading: null === mockDetail,
		error: null,
		stale: false,
	} ),
} ) );

jest.mock( '@newspack-nodes/shared/composition/page/pageState', () => ( {
	...jest.requireActual(
		'@newspack-nodes/shared/composition/page/pageState'
	),
	usePageState: ( key ) => [ 'request' === key ? mockRequest : '', () => {} ],
} ) );

import * as React from 'react';
import { ComposedLayout } from '@newspack-nodes/shared/composition/page';
import { BLOCK_VIEWS } from '../../sources';
import { renderComponent, act } from '../../../test-helpers/renderHook';
import { compileLocal, resolved } from '../../../test-helpers/cascade';
import PERFORMANCE_LAYOUTS from '../../../../tests/fixtures/performance-layout.json';

/** The request window's default layout. */
const REQUEST_WINDOW = {
	context: 'newspack-event-logger-nodes/request-detail',
	source: 'default',
	blocks: PERFORMANCE_LAYOUTS[ 'newspack-event-logger-nodes/request-detail' ],
	saved_ts: null,
	actor: null,
};

/** The ref the log publishes its `reveal` on, as the page hands it. */
const revealRef = { current: null };

afterEach( () => {
	mockRealLog = false;
	revealRef.current = null;
} );

/**
 * The window's layout on one answer, in the shape the window view once took
 * it: the record, its flame and its log entries.
 *
 * @param {Object}  props                 The window's answer.
 * @param {Object}  props.requestDetail   The record.
 * @param {?Object} props.flameData       Its flame, or null.
 * @param {Array}   props.indentedEntries Its stored log.
 * @param {string}  [props.rid]           The request page state names.
 * @return {import('react').ReactElement} The layout.
 */
function windowOf( { requestDetail, flameData, indentedEntries, rid } ) {
	mockDetail = {
		...requestDetail,
		flame_data: flameData,
		entries: indentedEntries,
	};
	mockRequest = rid ?? 'rid-kea-7929';
	return (
		<div className="event-logger-request-detail event-logger-sections">
			<ComposedLayout
				layout={ REQUEST_WINDOW }
				views={ BLOCK_VIEWS }
				page={ {
					group: 'request',
					canTune: false,
					onRestored: () => {},
					selectRequest: () => {},
					revealRef,
				} }
			/>
		</div>
	);
}

const baseRequest = {
	request_method: 'GET',
	url: '/foo',
	request_url: '/foo?kea=4417',
	timestamp: 1748960000, // 2025-06-03 14:13:20 UTC.
	duration_ms: 123.456,
	peak_mb: 4,
	status_code: 200,
};

async function renderAsync( element ) {
	const result = renderComponent( element );
	// Suspense-children won't appear synchronously; flush microtasks.
	await act( async () => {} );
	return result;
}

describe( 'the request window blocks', () => {
	it( 'hands the flame graph one order per breakdown, however often it re-renders', async () => {
		const props = {
			requestDetail: {
				...baseRequest,
				profiles: { 'kea-7075': { time: 2, count: 1 } },
			},
			flameData: { children: [ { name: 'kea-7075' } ] },
			indentedEntries: [ { k: 'leaf', ts: 1 } ],
			realEntryCount: 1,
		};
		const view = await renderAsync( windowOf( props ) );
		const first = global.__requestDetailFlameProps.order;

		act( () =>
			view.rerender(
				windowOf( {
					...props,
					rid: 'kea-7076',
				} )
			)
		);

		expect( global.__requestDetailFlameProps.order ).toBe( first );
		view.unmount();
	} );

	it( 'mounts a fresh log table for each request, so a fold state never carries over', () => {
		global.__requestDetailTableMounts = 0;
		const props = ( rid ) =>
			windowOf( {
				requestDetail: baseRequest,
				flameData: null,
				indentedEntries: [ { k: 'leaf', ts: 1 } ],
				realEntryCount: 1,
				rid,
			} );
		const { rerender, unmount } = renderComponent( props( 'r-417' ) );
		rerender( props( 'r-417' ) );
		expect( global.__requestDetailTableMounts ).toBe( 1 );
		rerender( props( 'r-928' ) );
		expect( global.__requestDetailTableMounts ).toBe( 2 );
		unmount();
	} );

	it( 'says the flame lookup ran out of time, never that the request is unprofiled', () => {
		const view = ( flameCut ) =>
			renderComponent(
				windowOf( {
					requestDetail: { ...baseRequest, flame_cut: flameCut },
					flameData: null,
					indentedEntries: [ { k: 'leaf', ts: 1 } ],
					realEntryCount: 1,
				} )
			);
		const cut = view( true );
		expect( cut.container.textContent ).toContain(
			'The flame lookup ran out of time, so the trace is not shown here; one may still have been recorded.'
		);
		cut.unmount();
		const whole = view( false );
		expect( whole.container.textContent ).not.toContain(
			'ran out of time'
		);
		whole.unmount();
	} );

	it( 'renders URL, time, duration, memory, status', () => {
		const { container, unmount } = renderComponent(
			windowOf( {
				requestDetail: baseRequest,
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		const text = container.textContent;
		expect( text ).toContain( 'GET /foo?kea=4417' );
		expect( text ).toContain( '123.46' );
		expect( text ).toContain( '4 MB' );
		expect( text ).toContain( '200' );
		unmount();
	} );

	it( 'shows the findings the record carries, with the hooks a proposal names', () => {
		const { container, unmount } = renderComponent(
			windowOf( {
				requestDetail: {
					...baseRequest,
					findings: [
						{
							kind: 'insufficient_instrumentation',
							severity: 'high',
							title: 'The governing rule registers no hooks, so nothing inside the request is measured',
							measured: 'rule + record',
							proposal: {
								action: 'add_hooks',
								direction: 'more',
								hooks: [ 'init', 'template_redirect' ],
								why: 'Nothing watches the slow part.',
							},
						},
						{
							kind: 'truncation',
							severity: 'info',
							title: 'This record was folded under memory pressure',
							detail: 'Repeated spans were merged.',
							measured: 'record markers',
							proposal: {
								action: 'trim_hooks',
								direction: 'less',
							},
						},
						{
							kind: 'fatal',
							severity: 'high',
							title: 'The request died in the sample plugin',
							measured: 'php fatal',
							proposal: { action: 'none' },
						},
					],
				},
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		const text = container.textContent;
		expect( text ).toContain( 'Findings' );
		expect( text ).toContain( 'Repeated spans were merged.' );
		expect( text ).toContain( 'less noise' );
		// A fatal proposes nothing: no rule edit fixes a crash.
		expect( text ).not.toContain( 'none' );
		expect( text ).toContain( 'nothing inside the request is measured' );
		expect( text ).toContain( 'init, template_redirect' );
		unmount();
	} );

	it( 'names what a proposal acts on, and how to take it back', () => {
		const { container, unmount } = renderComponent(
			windowOf( {
				requestDetail: {
					...baseRequest,
					findings: [
						{
							kind: 'dominant_span',
							severity: 'high',
							title: 'the_content hook holds 81% of the request',
							measured: 'profiles',
							proposal: {
								action: 'mark_significant',
								direction: 'more',
								value: 'the_content',
								why: 'Per-callback timing names the callback.',
								undo: 'Remove the_content from significant events.',
							},
						},
					],
				},
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		const proposal = container.querySelector(
			'.event-logger-findings__proposal'
		).textContent;
		expect( proposal ).toContain( 'the_content' );
		expect( container.textContent ).toContain(
			'Remove the_content from significant events.'
		);
		unmount();
	} );

	it( 'says a fold once, as a finding, when the record carries findings', () => {
		const { container, unmount } = renderComponent(
			windowOf( {
				requestDetail: {
					...baseRequest,
					folded: true,
					findings: [
						{
							kind: 'truncation',
							severity: 'info',
							title: 'This record was folded under memory pressure',
							measured: 'record markers',
							proposal: { action: 'none' },
						},
					],
				},
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).not.toContain(
			'Aggregated under load'
		);
		unmount();
	} );

	it( 'says nothing stands out when the detector found nothing', () => {
		const { container, unmount } = renderComponent(
			windowOf( {
				requestDetail: { ...baseRequest, findings: [] },
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).toContain( 'Nothing stands out' );
		unmount();
	} );

	it( 'draws no findings section for a record without a detector run', () => {
		const { container, unmount } = renderComponent(
			windowOf( {
				requestDetail: baseRequest,
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).not.toContain( 'Findings' );
		unmount();
	} );

	it( 'omits memory + status when both are zero', () => {
		const { container, unmount } = renderComponent(
			windowOf( {
				requestDetail: {
					...baseRequest,
					peak_mb: 0,
					status_code: 0,
				},
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).not.toContain( 'Memory' );
		expect( container.textContent ).not.toContain( 'Status' );
		unmount();
	} );

	it( 'shows a "Timed out" error for error_status=T', () => {
		const { container, unmount } = renderComponent(
			windowOf( {
				requestDetail: { ...baseRequest, error_status: 'T' },
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).toContain( 'Timed out' );
		const badge = container.querySelector( '.newspack-nodes-badge' );
		expect( badge.className ).toBe(
			'newspack-nodes-badge newspack-nodes-status is-warning'
		);
		expect( badge.style.color ).toBe( '' );
		unmount();
	} );

	it( 'shows a "Fatal error" for error_status=F', () => {
		const { container, unmount } = renderComponent(
			windowOf( {
				requestDetail: { ...baseRequest, error_status: 'F' },
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).toContain( 'Fatal error' );
		const badge = container.querySelector( '.newspack-nodes-badge' );
		expect( badge.className ).toBe(
			'newspack-nodes-badge newspack-nodes-status is-error'
		);
		expect( badge.style.color ).toBe( '' );
		unmount();
	} );

	it( 'shows an "Incomplete" error for error_status=I', () => {
		const { container, unmount } = renderComponent(
			windowOf( {
				requestDetail: { ...baseRequest, error_status: 'I' },
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).toContain( 'Incomplete' );
		const badge = container.querySelector( '.newspack-nodes-badge' );
		expect( badge.className ).toBe(
			'newspack-nodes-badge newspack-nodes-status is-warning'
		);
		unmount();
	} );

	it( 'renders the "no log entries" hint when there is nothing to show', () => {
		const { container, unmount } = renderComponent(
			windowOf( {
				requestDetail: baseRequest,
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).toContain( 'No log entries available' );
		unmount();
	} );

	it( 'hands the flame graph the breakdown order, so the two share CRT greens', async () => {
		const { unmount } = await renderAsync(
			windowOf( {
				requestDetail: {
					...baseRequest,
					profiles: {
						'kea-7066': { time: 2, count: 1 },
						'kea-7067': { time: 8, count: 1 },
					},
				},
				flameData: { children: [ { name: 'kea-7066' } ] },
				indentedEntries: [ { k: 'leaf', ts: 1 } ],
				realEntryCount: 1,
			} )
		);
		expect( global.__requestDetailFlameProps.order ).toEqual( [
			'kea-7067',
			'kea-7066',
		] );
		unmount();
	} );

	it( 'renders FlameGraph + RequestProfile + LogEntriesTable when all sources present', async () => {
		const { container, unmount } = await renderAsync(
			windowOf( {
				requestDetail: { ...baseRequest, profiles: { foo: 1 } },
				flameData: { children: [ { name: 'x' } ] },
				indentedEntries: [ { k: 'leaf', ts: 1 } ],
				realEntryCount: 1,
			} )
		);
		expect( container.textContent ).toContain( 'FLAME_GRAPH' );
		expect( container.textContent ).toContain( 'REQUEST_PROFILE' );
		expect( container.textContent ).toContain( 'LOG_ENTRIES_TABLE' );
		act( () => {
			global.__requestDetailFlameProps.onRevealEntry( 233, [
				'process',
				'db',
			] );
		} );
		expect( global.__requestDetailReveal ).toHaveBeenCalledWith( 233, [
			'process',
			'db',
		] );
		unmount();
	} );

	it( "leaves no request's reveal behind for the next request's flame", async () => {
		mockRealLog = true;
		const a = await renderAsync(
			windowOf( {
				requestDetail: baseRequest,
				flameData: null,
				indentedEntries: [
					{ n: 1, k: 'process (start)', ts: 1748960000 },
					{ n: 2, k: 'process (complete)', ts: 1748960001 },
				],
				rid: 'rid-kea-7931',
			} )
		);
		expect( revealRef.current ).toBeInstanceOf( Function );

		await act( async () =>
			a.rerender(
				windowOf( {
					requestDetail: baseRequest,
					flameData: { children: [ { name: 'kea-7932' } ] },
					indentedEntries: [],
					rid: 'rid-kea-7932',
				} )
			)
		);
		expect( revealRef.current ).toBeNull();
		expect( () =>
			global.__requestDetailFlameProps.onRevealEntry( 7932, [
				'kea-7932',
			] )
		).not.toThrow();
		a.unmount();
	} );

	it( 'stacks its sections on one gap, none carrying a margin of its own', async () => {
		const { container, unmount } = await renderAsync(
			windowOf( {
				requestDetail: {
					...baseRequest,
					profiles: { 'kea-7301': { count: 2, time: 40 } },
					owners: [
						{ owner: 'kea-7301', ms: 40, share: 0.3, calls: 2 },
					],
				},
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		const stack = container.querySelector(
			'.event-logger-sections > .newspack-nodes-composed-layout'
		);
		expect( stack ).not.toBeNull();
		const owner = [ ...stack.children ].find( ( child ) =>
			child.textContent.includes( 'Time By Owner' )
		);
		expect( owner ).toBeDefined();
		for ( const child of stack.children ) {
			expect( child.style.marginTop ).toBe( '' );
			expect( child.style.marginBottom ).toBe( '' );
		}
		const sheet = compileLocal( 'overview/styles/base.scss' );
		// The layout's own box gives way to the window's stack beneath it.
		expect( resolved( stack, [ sheet ] ).display ).toBe( 'contents' );
		const won = resolved( stack.parentElement, [ sheet ] );
		expect( won.display ).toBe( 'flex' );
		expect( won[ 'flex-direction' ] ).toBe( 'column' );
		expect( won.gap ).toBe( '32px' );
		const ownerWon = resolved( owner, [ sheet ] );
		expect( [
			ownerWon[ 'margin-top' ],
			ownerWon[ 'margin-bottom' ],
		] ).toEqual( [ '0', '0' ] );
		unmount();
	} );

	it( "closes the breakdown and the owner table as one section, on the categories' total", async () => {
		const { container, unmount } = await renderAsync(
			windowOf( {
				requestDetail: {
					...baseRequest,
					profiles: {
						'kea-7301 hook': { count: 2, time: 40 },
						'emu-41 hook': { count: 1, time: 23 },
					},
					owners: [
						{ owner: 'kea-7301', ms: 17, share: 0.3, calls: 2 },
					],
				},
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		const stack = container.querySelector(
			'.event-logger-sections > .newspack-nodes-composed-layout'
		);
		const sections = [ ...stack.children ].filter(
			( child ) =>
				child.textContent.includes( 'REQUEST_PROFILE' ) ||
				child.textContent.includes( 'Time By Owner' )
		);
		expect( sections ).toHaveLength( 1 );
		const text = sections[ 0 ].textContent;
		expect( text.indexOf( 'REQUEST_PROFILE' ) ).toBeLessThan(
			text.indexOf( 'Time By Owner' )
		);
		const totals = container.querySelectorAll(
			'.newspack-nodes-table__summary'
		);
		expect( totals ).toHaveLength( 1 );
		expect( totals[ 0 ].textContent ).toMatch(
			/^Total Profiled63(\.0)?ms/
		);
		unmount();
	} );

	it( 'mounts the owner table from the request owners', async () => {
		const { container, unmount } = await renderAsync(
			windowOf( {
				requestDetail: {
					...baseRequest,
					owners: [
						{
							owner: 'plugin:ibis-forms',
							ms: 61.5,
							share: 0.3,
							calls: 9,
						},
					],
				},
				flameData: null,
				indentedEntries: [],
				realEntryCount: 0,
			} )
		);
		expect( container.textContent ).toContain( 'Time By Owner' );
		expect( container.textContent ).toContain( 'plugin:ibis-forms' );
		expect( container.textContent ).toContain( '61.5ms' );
		expect( container.textContent ).not.toContain( 'per request' );
		unmount();
	} );
} );

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
		windowOf( {
			requestDetail: detail,
			flameData: null,
			indentedEntries: entries,
		} )
	);

describe( 'the request window on a folded request', () => {
	beforeEach( () => {
		mockRealLog = true;
	} );

	afterEach( () => {
		mockRealLog = false;
	} );

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

	it( "names the record's fatal on its kept terminal row", () => {
		const { container } = render(
			{
				...FOLDED,
				error_status: 'F',
				fatal_error: 'Call to undefined method Kea_4434::takahe()',
				fatal_file: '/srv/wp-content/plugins/kea-4434/kea.php',
				fatal_line: 4434,
				fatal_plugin: 'kea-4434',
			},
			KEPT
		);
		const fatal =
			container.querySelector( '.log-entries-fatal' ).textContent;
		expect( fatal ).toContain( 'Kea_4434::takahe()' );
		expect( fatal ).toContain(
			'/srv/wp-content/plugins/kea-4434/kea.php:4434'
		);
	} );

	it( 'leaves an ordinary request untouched', () => {
		const { container } = render( { ...FOLDED, folded: false } );
		expect( container.textContent ).not.toMatch( /Aggregated under load/ );
	} );
} );
