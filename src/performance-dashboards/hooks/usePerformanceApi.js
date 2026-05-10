/**
 * Performance API Hook
 *
 * Custom hook for fetching performance data from the Event Logger REST API.
 */

import { useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Validate URL hash format (lowercase hex).
 *
 * @param {string} hash Hash to validate.
 * @return {boolean} True if valid.
 */
const isValidHash = ( hash ) =>
	typeof hash === 'string' && /^[a-f0-9]+$/.test( hash );

/**
 * Validate request ID format.
 *
 * @param {string} rid Request ID to validate.
 * @return {boolean} True if valid.
 */
const isValidRequestId = ( rid ) =>
	typeof rid === 'string' && /^[a-zA-Z0-9_-]+$/.test( rid );

/**
 * Validate partition number.
 *
 * @param {number} partition Partition to validate.
 * @return {boolean} True if valid.
 */
const isValidPartition = ( partition ) =>
	Number.isInteger( partition ) && partition >= 0;

/**
 * Hook providing API fetch functions for performance data.
 *
 * @param {Function} onError Error handler callback.
 * @return {Object} API fetch functions.
 */
const usePerformanceApi = ( onError ) => {
	/**
	 * Fetch performance overview data (always includes category time series).
	 *
	 * @param {string} server Optional server name for per-server leaderboard.
	 * @return {Promise<Object|null>} Overview data or null on error.
	 */
	const fetchOverview = useCallback(
		async ( server = '', breakdowns = [] ) => {
			try {
				let path =
					'/newspack-nodes/v1/performance/overview?categories=1';
				if ( server ) {
					path += `&server=${ encodeURIComponent( server ) }`;
				}
				if ( Array.isArray( breakdowns ) && breakdowns.length > 0 ) {
					path += `&breakdown=${ encodeURIComponent(
						breakdowns.join( ',' )
					) }`;
				}
				const data = await apiFetch( { path } );
				return data;
			} catch ( err ) {
				onError( err );
				return null;
			}
		},
		[ onError ]
	);

	/**
	 * Fetch URL performance list.
	 *
	 * @param {Object} params        Optional query parameters.
	 * @param {string} params.search Search term to filter URLs.
	 * @param {string} params.sort   Sort field (count, url, avg_ms, etc).
	 * @param {string} params.order  Sort order (asc, desc).
	 * @param {number} params.offset Pagination offset.
	 * @return {Promise<Object|null>} Response with data, total, limit, offset — or null on error.
	 */
	const fetchUrls = useCallback(
		async ( params = {} ) => {
			try {
				const query = new URLSearchParams( { limit: '100' } );
				if ( params.search ) {
					query.set( 'search', params.search );
				}
				if ( params.sort ) {
					query.set( 'sort', params.sort );
				}
				if ( params.order ) {
					query.set( 'order', params.order );
				}
				if ( params.offset ) {
					query.set( 'offset', String( params.offset ) );
				}
				if ( params.server ) {
					query.set( 'server', params.server );
				}
				const data = await apiFetch( {
					path: `/newspack-nodes/v1/performance/urls?${ query.toString() }`,
				} );
				return data;
			} catch ( err ) {
				onError( err );
				return null;
			}
		},
		[ onError ]
	);

	/**
	 * Fetch detail for a specific URL.
	 *
	 * @param {string} hash URL hash identifier.
	 * @return {Promise<Object|null>} URL detail or null on error.
	 */
	const fetchUrlDetail = useCallback(
		async ( hash ) => {
			if ( ! isValidHash( hash ) ) {
				onError( new Error( 'Invalid URL hash format' ) );
				return null;
			}
			try {
				const data = await apiFetch( {
					path: `/newspack-nodes/v1/performance/urls/${ hash }?categories=1`,
				} );
				return data;
			} catch ( err ) {
				onError( err );
				return null;
			}
		},
		[ onError ]
	);

	/**
	 * Fetch detail for a specific request.
	 *
	 * @param {string} rid       Request ID.
	 * @param {number} partition Partition number.
	 * @return {Promise<Object|null>} Request detail or null on error.
	 */
	const fetchRequestDetail = useCallback(
		async ( rid, partition ) => {
			if ( ! isValidRequestId( rid ) ) {
				onError( new Error( 'Invalid request ID format' ) );
				return null;
			}
			if ( ! isValidPartition( partition ) ) {
				onError( new Error( 'Invalid partition number' ) );
				return null;
			}
			try {
				const data = await apiFetch( {
					path: `/newspack-nodes/v1/performance/requests/${ rid }?partition=${ partition }`,
				} );
				return data;
			} catch ( err ) {
				onError( err );
				return null;
			}
		},
		[ onError ]
	);

	/**
	 * Fetch dimensional breakdown data for a given breakdown type.
	 *
	 * @param {string} breakdown Breakdown dimension (method, server, country, from, ua, ja4).
	 * @param {string} server    Optional server name to filter by.
	 * @return {Promise<Object|null>} Breakdown time series data or null.
	 */
	const fetchBreakdown = useCallback(
		async ( breakdown, server = '' ) => {
			try {
				let path = `/newspack-nodes/v1/performance/overview?breakdown=${ encodeURIComponent(
					breakdown
				) }`;
				if ( server ) {
					path += `&server=${ encodeURIComponent( server ) }`;
				}
				const data = await apiFetch( { path } );
				return data.breakdown_time_series || null;
			} catch ( err ) {
				onError( err );
				return null;
			}
		},
		[ onError ]
	);

	/**
	 * Fetch per-URL dimensional breakdown data.
	 *
	 * @param {string} hash      URL hash identifier.
	 * @param {string} breakdown Breakdown dimension.
	 * @return {Promise<Object|null>} Breakdown time series data or null.
	 */
	const fetchUrlBreakdown = useCallback(
		async ( hash, breakdown ) => {
			if ( ! isValidHash( hash ) ) {
				return null;
			}
			try {
				const data = await apiFetch( {
					path: `/newspack-nodes/v1/performance/urls/${ hash }?breakdown=${ encodeURIComponent(
						breakdown
					) }`,
				} );
				return data.breakdown_time_series || null;
			} catch ( err ) {
				onError( err );
				return null;
			}
		},
		[ onError ]
	);

	/**
	 * Fetch per-URL category time series.
	 *
	 * @param {string} hash URL hash.
	 * @return {Promise<Object|null>} Category time series data or null.
	 */
	const fetchUrlCategories = useCallback(
		async ( hash ) => {
			if ( ! isValidHash( hash ) ) {
				return null;
			}
			try {
				const data = await apiFetch( {
					path: `/newspack-nodes/v1/performance/urls/${ hash }?categories=1`,
				} );
				return data.category_time_series || null;
			} catch ( err ) {
				onError( err );
				return null;
			}
		},
		[ onError ]
	);

	return {
		fetchOverview,
		fetchUrls,
		fetchUrlDetail,
		fetchRequestDetail,
		fetchBreakdown,
		fetchUrlBreakdown,
		fetchUrlCategories,
	};
};

export default usePerformanceApi;
