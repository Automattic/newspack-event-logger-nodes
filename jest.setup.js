/* eslint-env jest */
// Jest setup — suppress ONLY the substrate's own stderr noise (mirrors the
// sibling newspack-nodes setup).
//
// The substrate's `Core.stderr()` / `printLessOften()` / `printLeastOften()`
// (newspack-nodes/src/runtime/core.js) route node faults, rate-limited logs, and
// dropped-message notices to console.warn (not console.error, to skip devtools'
// error counter), each line stamped `YYYY-MM-DD HH:MM:SS UTC <argv0>: …`. Those
// are expected output spam on any test exercising a fault path. Suppress ONLY
// lines matching that signature — every other console.warn (third-party
// deprecations like @wordpress/components' 36px notice, and anything
// unexpected) passes through so real problems still surface. console.error is
// left fully intact, so React `act(...)` warnings and genuine errors surface.

const realWarn = console.warn.bind( console );
// The Core.stderr() line prefix: ISO-ish date + " UTC <argv0>: ".
const SUBSTRATE_STDERR = /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC \S+: /;

beforeEach( () => {
	jest.spyOn( console, 'warn' ).mockImplementation( ( ...args ) => {
		if (
			'string' === typeof args[ 0 ] &&
			SUBSTRATE_STDERR.test( args[ 0 ] )
		) {
			return;
		}
		realWarn( ...args );
	} );
} );

afterEach( () => {
	if ( jest.isMockFunction( console.warn ) ) {
		console.warn.mockRestore();
	}
} );
