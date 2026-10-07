/**
 * The `?` picker, end to end without a wire: `useAsk` holds the mode, a click on
 * anything carrying `data-ask` becomes an `ask` verb, and each reply fills the
 * pick its address names with a brief the panel shows before a single byte
 * leaves the page. Taking a pick back takes its brief with it.
 *
 * The verb is doubled at the hook boundary — the test fires the same `onDone`
 * the real reply lands in, so the paths under test are the production ones and
 * only the wire is stood in for.
 */

import { renderComponent, act } from '../../test-helpers/renderHook';
import AskPanel, { AskButton, useAsk } from '../components/AskPanel';

let askOpts;
const sent = [];
jest.mock( '@newspack-nodes/shared/hooks/useCommandOnce', () => ( {
	__esModule: true,
	useCommandOnce: ( opts ) => {
		askOpts = opts;
		// Stable identity, as the real useCallback one has: an unstable `run`
		// re-runs every effect that lists it as a dep.
		return { run: ( args ) => sent.push( args ) };
	},
} ) );

jest.mock( '@newspack-nodes/runtime', () => ( {
	__esModule: true,
	formatCommandArgs: ( args, options ) => [ ...args, options ?? {} ],
	nodesData: () => ( { restUrl: '/wp-json/', nonce: 'NONCE' } ),
} ) );

const BRIEF = {
	subject: 'span',
	findings: [
		{
			kind: 'dominant',
			severity: 'high',
			title: 'wp_loaded hook holds 79% of the request',
			detail: '791.5ms of 1004.0ms',
			measured: 'the flame graph',
			proposal: {
				action: 'add_hooks',
				direction: 'more',
				why: 'nothing inside it is instrumented',
			},
		},
	],
	caveat: 'It does not see SQL.',
};

// Past the 128 encoded characters a reply address may carry as its subject.
const LONG_SPAN = `span:${ 'wp_loaded > Newspack\\Some_Class::a_long_method '.repeat(
	4
) }`;

// The dashboard shape: one picker, one panel, and something askable.
function Harness( {
	onError,
	serverFilter = '',
	urlFilters = null,
	pageScope = true,
} ) {
	const ask = useAsk( { onError, serverFilter, urlFilters } );
	return (
		<div data-ask={ pageScope ? 'overview:site' : undefined }>
			<AskButton ask={ ask } />
			<div data-ask="span:wp_loaded hook">
				<span data-ask="request:abc123" id="target">
					791ms
				</span>
			</div>
			<span data-ask="url:/shop-7" id="other">
				/shop-7
			</span>
			<span data-ask={ LONG_SPAN } id="long">
				long span
			</span>
			<p id="nothing">nothing askable here</p>
			<AskPanel ask={ ask } />
		</div>
	);
}

let view;
// The panel's Modal portals to the body, so the dialog sits beside the
// harness's own container rather than inside it. `renderComponent` is this
// repo's hand-rolled helper, with no `baseElement` to reach for, and it
// appends that container to the body — so the body holds both.
const render = ( props = {} ) => {
	const rendered = renderComponent( <Harness { ...props } /> );
	view = { ...rendered, container: document.body };
	return view;
};

const arm = () =>
	act( () => {
		view.container.querySelector( '[data-ask-trigger]' ).click();
	} );

// Click the askable element — the picker reads the modifier on mousedown, so
// both events are dispatched.
const clickTarget = ( { additive = false, id = 'target' } = {} ) => {
	const target = view.container.querySelector( `#${ id }` );
	act( () => {
		target.dispatchEvent(
			new window.MouseEvent( 'mousedown', {
				bubbles: true,
				metaKey: additive,
			} )
		);
		target.dispatchEvent(
			new window.MouseEvent( 'click', {
				bubbles: true,
				metaKey: additive,
			} )
		);
	} );
};

// Arm, then take one thing — what a single pick has always been.
const pick = ( options = {} ) => {
	arm();
	clickTarget( options );
};

const dialog = () => view.container.querySelector( '[role="dialog"]' );

// @longform What a real reply carries: no subject, because `useAsk` sends
// none, and the tokens it answered echoed back as `args`, the descriptor
// first. The mock's trailing options object is no token, so it is not echoed.
const answer = ( payload, descriptor = 'request:abc123' ) => {
	const tokens = sent.findLast( ( args ) => args[ 0 ] === descriptor ) ?? [
		descriptor,
	];
	act( () => {
		askOpts.onDone( {
			subject: null,
			args: tokens.filter( ( token ) => 'string' === typeof token ),
			...payload,
		} );
	} );
};

beforeEach( () => {
	sent.length = 0;
	askOpts = undefined;
} );

afterEach( () => {
	view?.unmount();
	view = null;
} );

test( 'a pick sends the innermost descriptor chain to the ask verb', () => {
	render();

	pick();

	expect( sent ).toEqual( [
		[ 'request:abc123', 'span:wp_loaded hook', 'overview:site', {} ],
	] );
} );

// The page's own brief answers for the page as it is READ: the same server and
// the same url filters. Sent site-wide under a filter, it would quote numbers
// the reader cannot see, which is the one thing a brief must never do.
test( 'a pick carries the scope the page is showing', () => {
	// Two different servers: the live pick and the one the visible rows were
	// fetched under. A brief must quote the set on screen, so the echo wins.
	render( {
		serverFilter: 'beta.example',
		urlFilters: {
			server: 'alpha.example',
			search: 'wp-admin',
			errors_only: false,
			include_workers: true,
			bucket: '2026-10-04-13-35..2026-10-04-13-45,2026-10-04-14-10',
		},
	} );

	act( () => {
		view.container.querySelector( '[data-ask-trigger]' ).click();
	} );
	act( () => {
		const page = view.container.querySelector(
			'[data-ask="overview:site"]'
		);
		page.dispatchEvent(
			new window.MouseEvent( 'mousedown', { bubbles: true } )
		);
		page.dispatchEvent(
			new window.MouseEvent( 'click', { bubbles: true } )
		);
	} );

	expect( sent ).toEqual( [
		[
			'overview:site',
			{
				server: 'alpha.example',
				search: 'wp-admin',
				include_workers: '1',
				bucket: '2026-10-04-13-35..2026-10-04-13-45,2026-10-04-14-10',
			},
		],
	] );
} );

// A brief with an empty `findings` list looked and found nothing. One with no
// `findings` key at all had no detector run over it — saying "nothing stands
// out" there is a claim nobody made.
test( 'the panel makes no finding claim for a brief that ran no detector', () => {
	render();
	pick();

	answer( {
		result: {
			subject: 'overview',
			scope: 'every server',
			stats: { requests: 7 },
			caveat: 'c',
		},
	} );

	expect( view.container.textContent ).not.toContain( 'Nothing stands out' );
	expect( view.container.textContent ).toContain( 'every server' );
} );

test( 'a brief whose detector found nothing still says so', () => {
	render();
	pick();

	answer( { result: { ...BRIEF, findings: [] } } );

	expect( view.container.textContent ).toContain( 'Nothing stands out' );
} );

test( 'the panel shows the finding, where it was measured, and the proposal', () => {
	render();
	pick();

	answer( { result: BRIEF } );

	const text = view.container.textContent;
	expect( text ).toContain( 'wp_loaded hook holds 79% of the request' );
	expect( text ).toContain( 'measured: the flame graph' );
	expect( text ).toContain( 'add_hooks' );
	expect( text ).toContain( 'more visibility' );
	expect( text ).toContain( 'nothing inside it is instrumented' );
	// The severity drives the shared status role, not a bespoke class.
	expect(
		view.container.querySelector( '.newspack-nodes-status.is-error' )
	).not.toBeNull();
	// And the MCP endpoint is named under this site's REST root.
	expect( text ).toContain( '/wp-json/newspack-event-logger-nodes/v1/mcp' );
} );

test( 'a brief with no findings says so rather than rendering an empty list', () => {
	render();
	pick();

	answer( { result: { subject: 'url', findings: [], caveat: 'c' } } );

	expect( view.container.textContent ).toContain( 'Nothing stands out' );
	expect(
		view.container.querySelector( '.event-logger-findings' )
	).toBeNull();
} );

test( 'a pick under a server filter asks about that server', () => {
	// The facts block stamps the active filters onto every surface, so a brief
	// answered site-wide would be unscoped numbers under a server's name —
	// quotable, and wrong.
	sent.length = 0;
	const { container, unmount } = renderComponent(
		<Harness onError={ jest.fn() } serverFilter="edge-01" />
	);
	act( () => {
		container
			.querySelector( '[data-ask-trigger]' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
	} );
	act( () => {
		container
			.querySelector( '#target' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
	} );

	expect( sent[ 0 ][ sent[ 0 ].length - 1 ] ).toEqual( {
		server: 'edge-01',
	} );
	unmount();
} );

// A pick is not consent to send: the brief is shown, and copying is its own act.
test( 'copy writes the markdown to the clipboard and says so', async () => {
	const writeText = jest.fn( () => Promise.resolve() );
	Object.defineProperty( window.navigator, 'clipboard', {
		value: { writeText },
		configurable: true,
	} );
	render();
	pick();
	answer( { result: BRIEF } );

	await act( async () => {
		Array.from( view.container.querySelectorAll( 'button' ) )
			.find( ( b ) => 'Copy brief' === b.textContent )
			.click();
	} );

	expect( writeText ).toHaveBeenCalledTimes( 1 );
	expect( writeText.mock.calls[ 0 ][ 0 ] ).toContain( '## span' );
	expect( view.container.textContent ).toContain( 'Copied.' );
} );

test( 'the copy is agent-ready: the fetch call and the endpoint ride along', async () => {
	const writeText = jest.fn( () => Promise.resolve() );
	Object.defineProperty( window.navigator, 'clipboard', {
		value: { writeText },
		configurable: true,
	} );
	render();
	pick();
	answer( {
		result: {
			...BRIEF,
			fetch: [
				{
					tool: 'performance_ask',
					arguments: { descriptor: 'span:wp_loaded hook' },
				},
			],
		},
	} );

	await act( async () => {
		Array.from( view.container.querySelectorAll( 'button' ) )
			.find( ( b ) => 'Copy brief' === b.textContent )
			.click();
	} );

	const copied = writeText.mock.calls[ 0 ][ 0 ];
	expect( copied ).toContain(
		'performance_ask descriptor="span:wp_loaded hook"'
	);
	expect( copied ).toContain( '/wp-json/newspack-event-logger-nodes/v1/mcp' );
} );

// Worth having wherever the site is publicly reachable; on a local install the
// chat can read the brief but cannot reach the endpoint it names.
test( 'the panel offers a claude.ai link carrying the brief', () => {
	render();
	pick();
	answer( { result: BRIEF } );

	const link = view.container.querySelector(
		'a[href^="https://claude.ai/new"]'
	);
	expect( link ).not.toBeNull();
	expect( link.target ).toBe( '_blank' );
	expect( link.rel ).toContain( 'noopener' );
	expect( decodeURIComponent( link.href ) ).toContain( '## span' );
} );

/**
 * Past the URL budget the link carries only the ask, so the brief has to be
 * somewhere the user can paste from — otherwise "I will paste it next" is a
 * promise the UI never kept.
 */
test( 'the Claude link also puts the brief on the clipboard', async () => {
	const writeText = jest.fn( () => Promise.resolve() );
	Object.defineProperty( window.navigator, 'clipboard', {
		value: { writeText },
		configurable: true,
	} );
	render();
	pick();
	answer( { result: BRIEF } );

	await act( async () => {
		view.container
			.querySelector( 'a[href^="https://claude.ai/new"]' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
	} );

	expect( writeText ).toHaveBeenCalledTimes( 1 );
	expect( writeText.mock.calls[ 0 ][ 0 ] ).toContain( '## span' );
} );

test( 'closing the panel leaves the briefs alone but takes the dialog away', () => {
	render();
	pick();
	answer( { result: BRIEF } );

	act( () => {
		Array.from( view.container.querySelectorAll( 'button' ) )
			.find( ( b ) => 'Close' === b.textContent )
			.click();
	} );

	expect( view.container.querySelector( '[role="dialog"]' ) ).toBeNull();
} );

/**
 * A modified click means "and this one too" — the selection is not finished,
 * so the panel must not jump in front of the next thing being picked.
 */
test( 'a modified pick queues without opening the panel', () => {
	render();
	arm();

	clickTarget( { additive: true } );
	answer( { result: BRIEF } );

	expect( dialog() ).toBeNull();
} );

test( 'the plain pick that ends the selection opens it with everything queued', () => {
	render();
	arm();
	clickTarget( { additive: true } );
	answer( { result: BRIEF } );

	clickTarget( { id: 'other' } );
	answer(
		{ result: { ...BRIEF, subject: 'entry', findings: [] } },
		'url:/shop-7'
	);

	expect( view.container.textContent ).toContain( 'About 2 selected things' );
} );

// Arming again is what starts a new selection; nothing else clears it.
test( 'a fresh pick starts a fresh selection', () => {
	render();
	arm();
	clickTarget( { additive: true } );
	answer( { result: BRIEF } );
	clickTarget( { id: 'other' } );
	answer( { result: { ...BRIEF, subject: 'url' } }, 'url:/shop-7' );
	expect( view.container.textContent ).toContain( 'About 2' );

	pick();
	answer( { result: BRIEF } );

	expect( view.container.textContent ).toContain( 'About this span' );
	expect( view.container.textContent ).not.toContain( 'About 2' );
} );

test( 'cancelling discards what was queued', () => {
	render();
	arm();
	clickTarget( { additive: true } );
	answer( { result: BRIEF } );

	act( () => {
		document.dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key: 'Escape',
				bubbles: true,
			} )
		);
	} );

	expect( dialog() ).toBeNull();
} );

test( 'a failed ask reaches onError and opens nothing', () => {
	const onError = jest.fn();
	render( { onError } );
	pick();

	answer( { error: 'no record for rid=abc123' } );

	expect( onError ).toHaveBeenCalledWith( 'no record for rid=abc123' );
	expect( view.container.querySelector( '[role="dialog"]' ) ).toBeNull();
} );

// A reply that is not a brief is not a brief; it must not open an empty panel.
test( 'a reply carrying no brief says so rather than vanishing', () => {
	const onError = jest.fn();
	render( { onError } );
	pick();

	answer( { result: 'ok' } );

	expect( view.container.querySelector( '[role="dialog"]' ) ).toBeNull();
	expect( onError ).toHaveBeenCalledTimes( 1 );
} );

// Clicking nothing is not a failure: the picker stays armed and the `?` cursor
// says so, so nothing goes to the error banner. Rendered WITHOUT the page
// descriptor, because a surface that carries one has no un-askable gap left —
// the request modal and the log table still do.
test( 'a pick that hits nothing askable stays armed and stays quiet', () => {
	const onError = jest.fn();
	render( { onError, pageScope: false } );

	act( () => {
		view.container.querySelector( '[data-ask-trigger]' ).click();
	} );
	act( () => {
		view.container
			.querySelector( '#nothing' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
	} );

	expect( sent ).toEqual( [] );
	expect( onError ).not.toHaveBeenCalled();
	expect(
		document.documentElement.classList.contains( 'newspack-nodes-asking' )
	).toBe( true );
} );

// A second modified click on a pick takes it back out, and its brief with it.
test( 'unpicking a pick drops the brief it brought', () => {
	render();
	arm();
	clickTarget( { additive: true } );
	answer( { result: BRIEF } );
	clickTarget( { additive: true } );

	clickTarget( { id: 'other' } );
	answer( { result: { ...BRIEF, subject: 'url' } }, 'url:/shop-7' );

	expect( view.container.textContent ).toContain( 'About this url' );
	expect( view.container.textContent ).not.toContain( 'About 2' );
} );

// The answer to a pick already taken back still arrives; it must not land.
test( 'a brief answering a pick already unpicked never lands', () => {
	render();
	arm();
	clickTarget( { additive: true } );
	clickTarget( { additive: true } );
	answer( { result: BRIEF } );

	clickTarget( { id: 'other' } );
	answer( { result: { ...BRIEF, subject: 'url' } }, 'url:/shop-7' );

	expect( view.container.textContent ).toContain( 'About this url' );
} );

// A plain click on what is already picked finishes the selection; asking
// about it again would show its brief twice.
test( 'finishing on a pick asks nothing more', () => {
	render();
	arm();
	clickTarget( { additive: true } );
	answer( { result: BRIEF } );

	clickTarget();

	expect( sent ).toHaveLength( 1 );
	expect( view.container.textContent ).toContain( 'About this span' );
} );

// @longform A descriptor too long to ride a reply's address still gets its
// brief: the reply is matched on the arguments it echoes, never its address.
test( 'a pick whose descriptor is too long for an address still gets its brief', () => {
	render();
	expect( encodeURIComponent( LONG_SPAN ).length ).toBeGreaterThan( 128 );
	expect( askOpts.subjectOf( [ LONG_SPAN ] ) ).toBeNull();
	arm();

	clickTarget( { id: 'long' } );
	answer( { result: BRIEF }, LONG_SPAN );

	expect( view.container.textContent ).toContain( 'About this span' );
} );

// Replies land in whatever order the server answers; the panel keeps the
// order the reader picked in.
test( 'briefs show in pick order whatever order they are answered in', () => {
	render();
	arm();
	clickTarget( { additive: true } );
	clickTarget( { additive: true, id: 'other' } );

	answer(
		{
			result: {
				...BRIEF,
				subject: 'url',
				findings: [
					{ ...BRIEF.findings[ 0 ], title: 'second-pick-73' },
				],
			},
		},
		'url:/shop-7'
	);
	answer( {
		result: {
			...BRIEF,
			findings: [ { ...BRIEF.findings[ 0 ], title: 'first-pick-41' } ],
		},
	} );
	clickTarget( { id: 'other' } );

	const text = view.container.textContent;
	expect( text ).toContain( 'About 2 selected things' );
	expect( text.indexOf( 'first-pick-41' ) ).toBeLessThan(
		text.indexOf( 'second-pick-73' )
	);
} );
