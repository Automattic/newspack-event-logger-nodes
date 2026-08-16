/**
 * The `?` picker, end to end without a wire: `useAsk` holds the mode, a click on
 * anything carrying `data-ask` becomes an `ask` verb, and every reply appends a
 * brief the panel shows before a single byte leaves the page.
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
	formatCommandArgs: ( args ) => args,
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

// The dashboard shape: one picker, one panel, and something askable.
function Harness( { onError } ) {
	const ask = useAsk( { onError } );
	return (
		<div>
			<AskButton ask={ ask } />
			<div data-ask="span:wp_loaded hook">
				<span data-ask="request:abc123" id="target">
					791ms
				</span>
			</div>
			<p id="nothing">nothing askable here</p>
			<AskPanel ask={ ask } />
		</div>
	);
}

let view;
const render = ( props = {} ) => {
	view = renderComponent( <Harness { ...props } /> );
	return view;
};

const arm = () =>
	act( () => {
		view.container.querySelector( '.event-logger-ask__trigger' ).click();
	} );

// Click the askable element — the picker reads the modifier on mousedown, so
// both events are dispatched.
const clickTarget = ( { additive = false } = {} ) => {
	const target = view.container.querySelector( '#target' );
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

const answer = ( payload ) => {
	act( () => {
		askOpts.onDone( payload );
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

	expect( sent ).toEqual( [ [ 'request:abc123', 'span:wp_loaded hook' ] ] );
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
		view.container.querySelector( '.event-logger-ask__findings' )
	).toBeNull();
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

	clickTarget();
	answer( { result: { ...BRIEF, subject: 'entry', findings: [] } } );

	expect( view.container.textContent ).toContain( 'About 2 selected things' );
} );

// Arming again is what starts a new selection; nothing else clears it.
test( 'a fresh pick starts a fresh selection', () => {
	render();
	arm();
	clickTarget( { additive: true } );
	answer( { result: BRIEF } );

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

// A click that lands on nothing askable is the commonest way to get no brief —
// a flame-graph frame whose descriptor has not been stamped yet.
test( 'a pick that hits nothing askable says so and stays armed', () => {
	const onError = jest.fn();
	render( { onError } );

	act( () => {
		view.container.querySelector( '.event-logger-ask__trigger' ).click();
	} );
	act( () => {
		view.container
			.querySelector( '#nothing' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
	} );

	expect( sent ).toEqual( [] );
	expect( onError ).toHaveBeenCalledTimes( 1 );
	expect( document.body.classList.contains( 'newspack-nodes-asking' ) ).toBe(
		true
	);
} );
