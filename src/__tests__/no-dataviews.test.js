/* eslint-env jest */
/* global NEWSPACK_NODES_SRC */
/**
 * No source on a Performance or log page feeds a table, so the pages admit
 * none and their bundles never import `@wordpress/dataviews`, whose handle
 * `newspack-nodes-dataviews` would otherwise join each bundle's
 * `index.asset.php` and load on every one of those screens. Each entry and
 * the page it lazily imports load here under a DataViews that refuses to
 * load at all.
 */

const REFUSED = 'kea-7991: @wordpress/dataviews loaded';

/** The DataViews the substrate's shared blocks would import, as jest resolves it. */
const DATAVIEWS = require.resolve( '@wordpress/dataviews', {
	paths: [ `${ NEWSPACK_NODES_SRC }/shared/blocks` ],
} );

/** Each bundle's entry, beside the page it imports lazily. */
const ENTRIES = {
	overview: [ '../overview', '../overview/PerformancePage' ],
	'log-pages': [ '../log-pages', '../log-pages/LogPage' ],
};

test.each( Object.entries( ENTRIES ) )(
	'the %s bundle imports no DataViews',
	( bundle, modules ) => {
		jest.isolateModules( () => {
			jest.doMock( DATAVIEWS, () => {
				throw new Error( REFUSED );
			} );
			for ( const module of modules ) {
				expect( () => require( module ) ).not.toThrow();
			}
		} );
	}
);
