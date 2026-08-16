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
			<AskPanel ask={ ask } />
		</div>
	);
}

let view;
const render = ( props = {} ) => {
	view = renderComponent( <Harness { ...props } /> );
	return view;
};

// Arm the picker, then click the askable element — the picker reads the
// modifier on mousedown, so both events are dispatched.
const pick = ( { additive = false } = {} ) => {
	act( () => {
		view.container.querySelector( '.event-logger-ask__trigger' ).click();
	} );
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

// Additive is what multi-select IS: the earlier brief is still wanted.
test( 'a plain pick clears prior briefs; a modified one appends', () => {
	render();
	pick();
	answer( { result: BRIEF } );
	answer( { result: { ...BRIEF, subject: 'entry', findings: [] } } );
	expect( view.container.textContent ).toContain( 'About 2 selected things' );

	pick();

	expect( view.container.textContent ).not.toContain( 'About 2' );
	answer( { result: BRIEF } );
	expect( view.container.textContent ).toContain( 'About this span' );

	pick( { additive: true } );
	answer( { result: { ...BRIEF, subject: 'entry', findings: [] } } );
	expect( view.container.textContent ).toContain( 'About 2 selected things' );
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
test( 'a non-object result is ignored', () => {
	render();
	pick();

	answer( { result: 'ok' } );

	expect( view.container.querySelector( '[role="dialog"]' ) ).toBeNull();
} );
