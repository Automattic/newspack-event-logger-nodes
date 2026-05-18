/**
 * Performance API Hook
 *
 * Custom hook for fetching performance data from the Performance_CI verbs
 * via the substrate CommandClient. Routes every call through the
 * `performance` node — same return shapes as the legacy REST controllers
 * so component consumers are unchanged; only the transport differs.
 */

import { useCallback } from '@wordpress/element';

import { getCommandClient } from '../../shared/utils/commandClient';
import unwrapCommandResponse from '../../shared/utils/unwrapCommandResponse';

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
 * Send a Performance_CI verb call and unwrap the response.
 *
 * @param {string} verb Performance_CI verb name.
 * @param {Object} args JSON-decoded args the verb closure receives.
 * @return {Promise<*>} Parsed payload.
 */
const sendPerformance = async ( verb, args = {} ) => {
	const message = await getCommandClient().send( {
		to: 'performance',
		verb,
		args,
	} );
	return unwrapCommandResponse( message );
};

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
	 * @param {string}   server     Optional server name for per-server leaderboard.
	 * @param {string[]} breakdowns Optional breakdown dimensions to include.
	 * @return {Promise<Object|null>} Overview data or null on error.
	 */
	const fetchOverview = useCallback(
		async ( server = '', breakdowns = [] ) => {
			try {
				const args = { categories: true };
				if ( server ) {
					args.server = server;
				}
				if ( Array.isArray( breakdowns ) && breakdowns.length > 0 ) {
					args.breakdown = breakdowns.join( ',' );
				}
				return await sendPerformance( 'overview', args );
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
	 * @param {string} params.server Server filter (URL substring match).
	 * @return {Promise<Object|null>} Response with data, total, limit, offset — or null on error.
	 */
	const fetchUrls = useCallback(
		async ( params = {} ) => {
			try {
				const args = { limit: 100 };
				for ( const key of [
					'search',
					'sort',
					'order',
					'offset',
					'server',
				] ) {
					if ( params[ key ] ) {
						args[ key ] = params[ key ];
					}
				}
				return await sendPerformance( 'urls', args );
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
				return await sendPerformance( 'url_detail', {
					hash,
					categories: true,
				} );
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
				return await sendPerformance( 'request_detail', {
					rid,
					partition,
				} );
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
				const args = { breakdown };
				if ( server ) {
					args.server = server;
				}
				const data = await sendPerformance( 'overview', args );
				return data?.breakdown_time_series || null;
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
				const data = await sendPerformance( 'url_detail', {
					hash,
					breakdown,
				} );
				return data?.breakdown_time_series || null;
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
				const data = await sendPerformance( 'url_detail', {
					hash,
					categories: true,
				} );
				return data?.category_time_series || null;
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
