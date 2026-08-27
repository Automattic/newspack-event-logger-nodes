/**
 * jsdom ships no TextEncoder; @noble/hashes (the substrate's command signer)
 * needs one. Node's real implementation, so the suite exercises what the
 * browser will. Mirrors newspack-nodes/jest.setup.js.
 */
const { TextEncoder, TextDecoder } = require( 'util' );
global.TextEncoder = global.TextEncoder || TextEncoder;
global.TextDecoder = global.TextDecoder || TextDecoder;

/* eslint-env jest */
// Jest setup — FAIL any test that emits an UNEXPECTED console.warn/error, and
// fail any test that DECLARED an expected console message that never fired.
// Mirrors the sibling newspack-nodes setup.
//
// The substrate's `Core.stderr()` and `printLessOften()` (src/runtime/core.js)
// route node faults, rate-limited logs, and dropped-message notices through
// console.warn (never console.error, to skip devtools' error counter), each line
// stamped `YYYY-MM-DD HH:MM:SS <zone> <argv0>: `. A test that legitimately exercises
// a fault path must DECLARE the message it expects:
//
//     expectConsoleWarn( 'Job_Worker: ...' );
//
// The declared text is matched against the warn line with the substrate `stderr`
// prefix stripped, by START-OF-STRING — so a test asserts the stable part of the
// message and ignores only the trailing dynamic data. Anything not declared —
// every other console.warn, EVERY console.error (React `act(...)` warnings,
// third-party deprecations like @wordpress/components' 36px notice, genuine
// errors) — is recorded and re-thrown in afterEach, failing the test. Throwing in
// afterEach (not inside the mock) keeps React's render/commit from swallowing the
// throw, and the captured Error preserves the call site.
//
// Tests that prefer their own `jest.spyOn( console, … )` still can; that shadows
// the recorder and the afterEach restore unwinds both.

// The Core.stderr() line prefix: ISO-ish date + " <zone> <argv0>: ".
// The zone token is constrained to the shapes Intl actually emits — a bare
// `\S+` there matches any `<date> <time> <word> <word>: ` warning text and
// strips it, which is the gate swallowing the very lines it exists to report.
const SUBSTRATE_STDERR =
	/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d (?:UTC|GMT[+-][\d:]+|[A-Z]{2,5}) \S+: /;

let violations = [];
let expectedWarns = [];

// Declare a console.warn a test legitimately produces. The actual warn line, with
// the substrate `stderr` timestamp prefix stripped, must START WITH the declared
// text. Suppresses exactly the declared warnings and fails afterEach if a declared
// message never fires.
global.expectConsoleWarn = ( message ) => {
	expectedWarns.push( { message: String( message ).trim(), matched: false } );
};

const bareLine = ( arg ) =>
	'string' === typeof arg ? arg.replace( SUBSTRATE_STDERR, '' ).trim() : '';

const record =
	( channel ) =>
	( ...args ) => {
		if ( 'warn' === channel ) {
			const bare = bareLine( args[ 0 ] );
			const exp = expectedWarns.find(
				( e ) => ! e.matched && bare.startsWith( e.message )
			);
			if ( exp ) {
				exp.matched = true;
				return;
			}
		}
		violations.push(
			new Error(
				`Unexpected console.${ channel }: ${ args
					.map( String )
					.join( ' ' ) }`
			)
		);
	};

beforeEach( () => {
	violations = [];
	expectedWarns = [];
	jest.spyOn( console, 'warn' ).mockImplementation( record( 'warn' ) );
	jest.spyOn( console, 'error' ).mockImplementation( record( 'error' ) );
} );

afterEach( () => {
	const captured = violations;
	const unmet = expectedWarns.filter( ( e ) => ! e.matched );
	violations = [];
	expectedWarns = [];
	if ( jest.isMockFunction( console.warn ) ) {
		console.warn.mockRestore();
	}
	if ( jest.isMockFunction( console.error ) ) {
		console.error.mockRestore();
	}
	if ( captured.length ) {
		throw captured[ 0 ];
	}
	if ( unmet.length ) {
		throw new Error(
			`Declared console.warn never emitted: ${ unmet[ 0 ].message }`
		);
	}
} );

// @longform
// The substrate's emitters hold until authenticated, and this plugin inlines
// that runtime — so the harness authenticates too, or every poll test asserts
// silence. Guarded on `window`: node-environment suites must not pull in the
// browser runtime graph. Mirrors newspack-nodes/jest.setup.js.
if ( 'undefined' !== typeof window ) {
	const auth = require( '@newspack-nodes/runtime' );
	beforeEach( async () => {
		auth.forgetSession();
		auth.__setAuthFetch( async () => ( {
			handle: 'e2e11111e2e22222e2e33333e2e44444',
			key: 'jest-harness-session-key',
			expires_in: 3600,
			now: Math.floor( Date.now() / 1000 ),
		} ) );
		await auth.ensureSession();
	} );
}
