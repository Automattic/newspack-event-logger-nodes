/**
 * The URL window's Recent Requests: the subject's requests under the order
 * a sortable heading names, each row opening its request, with the Time
 * field holding the buckets the list narrows to.
 *
 * Footgun: the list virtualizes against the window's scroll container
 * (`.components-modal__content`). Mounted outside a `Modal`,
 * `useVirtualization` finds no such ancestor and throws — which is why the
 * tests mock that hook.
 */

import { useRef, memo, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	BAR_CELL,
	CELL_BASE,
	barStyle,
	cellClass,
	wholeMs,
} from '../constants';
import { errorStatus } from '../../components/errorStatus';
import SortHeaderButton from './SortHeaderButton';
import BucketField from './BucketField';
import useVirtualization from '@newspack-nodes/shared/hooks/useVirtualization';

/**
 * Request-row height in pixels.
 *
 * The virtualizer's arithmetic and each row's inline style both read this one
 * constant. Let them disagree and the padding spacers mis-size the runway,
 * which drifts the visible window away from the scroll position.
 */
const ROW_HEIGHT = 40;

/**
 * What a row's bar measures: its peak memory under the memory metric, else
 * its duration, which `dump_url` nulls where nothing was measured (decision 24).
 *
 * @param {Object} req    A request row.
 * @param {string} metric The chart metric.
 * @return {number} The bar's value; 0 draws no bar.
 */
const barValue = ( req, metric ) => {
	if ( 'memory' === metric ) {
		return req.peak_mb || 0;
	}
	return req.duration_ms ?? 0;
};

/**
 * The URL modal's Recent Requests columns, in order: its header and every
 * row render from this. A `sortable` column's heading is a sort button
 * aligned by `variant`, the rest plain headings. A cell takes its `kind`'s
 * classes (`cellClass()`), the `tone` classes a column adds, the
 * content `render` draws, and the `style` and `dataStatus` a column declares;
 * each reads the row's `barPct` and its `errorStatus()` reading.
 *
 * @type {Array<{field: string, label: string, sortable?: boolean, variant?: string, kind?: string, render: Function, tone?: Function, style?: Function, dataStatus?: Function}>}
 */
const REQUEST_COLUMNS = [
	{
		field: 'timestamp',
		label: __( 'Time', 'newspack-event-logger-nodes' ),
		sortable: true,
		render: ( req ) => new Date( req.timestamp * 1000 ).toLocaleString(),
	},
	{
		field: 'method',
		label: __( 'Method', 'newspack-event-logger-nodes' ),
		render: ( req ) => req.method,
	},
	{
		field: 'rid',
		label: __( 'Request ID', 'newspack-event-logger-nodes' ),
		kind: 'mono',
		render: ( req ) => <code>{ req.rid }</code>,
		tone: () => BAR_CELL,
		style: ( req, { barPct } ) => barStyle( barPct ),
	},
	{
		field: 'status_code',
		label: __( 'Status', 'newspack-event-logger-nodes' ),
		sortable: true,
		variant: 'center',
		kind: 'status',
		render: ( req, { status } ) =>
			status ? (
				<span title={ status.label }>{ req.error_status }</span>
			) : (
				req.status_code || '-'
			),
		tone: ( req, { status } ) =>
			status ? `newspack-nodes-status ${ status.tone }` : null,
		dataStatus: ( req, { status } ) =>
			status ? undefined : req.status_code,
	},
	{
		field: 'duration_ms',
		label: __( 'Duration', 'newspack-event-logger-nodes' ),
		sortable: true,
		variant: 'numeric',
		kind: 'numeric',
		render: ( req ) =>
			'number' === typeof req.duration_ms
				? wholeMs( req.duration_ms )
				: '—',
	},
	{
		field: 'peak_mb',
		label: __( 'Mem', 'newspack-event-logger-nodes' ),
		sortable: true,
		variant: 'numeric',
		kind: 'numeric',
		render: ( req ) => ( req.peak_mb > 0 ? `${ req.peak_mb }MB` : '-' ),
	},
];

/** The fields a request header sorts on, and so the `?url_sort=` whitelist. */
export const REQUEST_SORT_FIELDS = REQUEST_COLUMNS.filter(
	( col ) => col.sortable
).map( ( col ) => col.field );

/** The URL modal's request order until a header is clicked: newest first. */
export const DEFAULT_REQUEST_SORT = { field: 'timestamp', dir: 'desc' };

/**
 * The order a heading's click asks for: the column already sorted
 * descending flips to ascending, every other click sorts descending.
 *
 * @param {{field: string, dir: string}} sort  The order held.
 * @param {string}                       field The column clicked.
 * @return {{field: string, dir: string}} The order asked for.
 */
export const nextSort = ( sort, field ) => ( {
	field,
	dir: sort.field === field && 'desc' === sort.dir ? 'asc' : 'desc',
} );

// JSDoc rides the inner function: on the const, memo() infers props as `{}`.
const RequestRow = memo(
	/**
	 * One row of the recent-requests table, memoized so scrolling re-renders
	 * only the rows that entered the window.
	 *
	 * The row is a button: click or Enter/Space hands its rid to
	 * `onSelect`. It also carries the `?` picker's `request:` descriptor as
	 * `data-ask`, so a picker click on a row asks about that request rather
	 * than about the URL the modal is open on. Its request-id cell carries a
	 * bar background whose width is the row's value as a fraction of `maxBar`,
	 * and its status cell reads `error_status` through `errorStatus()`, falling
	 * back to the HTTP status code for a request that ended nominally. An
	 * unmeasured duration shows as a dash and draws no bar.
	 *
	 * @param {Object}                props          Component props.
	 * @param {Object}                props.req      Request row: rid, timestamp, method (`Other` for an undeclared one), status_code, error_status, duration_ms, peak_mb.
	 * @param {(rid: string) => void} props.onSelect Receives the row's rid on click or keyboard activation.
	 * @param {number}                props.maxBar   Largest bar value across the filtered rows; 0 draws no bar.
	 * @param {string}                props.metric   Chart metric; 'memory' bars peak_mb, every other value bars duration_ms.
	 * @return {import('react').ReactElement} Rendered row.
	 */
	function RequestRow( { req, onSelect, maxBar, metric } ) {
		const row = {
			barPct: maxBar > 0 ? ( barValue( req, metric ) / maxBar ) * 100 : 0,
			status: errorStatus( req.error_status ),
		};
		const handleKeyDown = ( e ) => {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				onSelect( req.rid );
			}
		};

		return (
			<div
				role="button"
				tabIndex={ 0 }
				data-ask={ `request:${ req.rid }` }
				className="event-logger-table__row newspack-nodes-table__row"
				style={ { height: ROW_HEIGHT } }
				onClick={ () => onSelect( req.rid ) }
				onKeyDown={ handleKeyDown }
			>
				{ REQUEST_COLUMNS.map( ( col ) => (
					<div
						key={ col.field }
						className={ [ cellClass( col ), col.tone?.( req, row ) ]
							.filter( Boolean )
							.join( ' ' ) }
						style={ col.style?.( req, row ) }
						data-status={ col.dataStatus?.( req, row ) }
					>
						{ col.render( req, row ) }
					</div>
				) ) }
			</div>
		);
	}
);

/**
 * Order two request rows by one column in one direction. A null duration,
 * one `dump_url` measured none of (decision 24), ranks LAST either way, as
 * the `urls` command ranks a URL without a timed request; such rows keep their
 * order among themselves. A row missing any other field counts as 0.
 *
 * @param {Object}                       a    A `dump_url` request row.
 * @param {Object}                       b    Another.
 * @param {{field: string, dir: string}} sort The column and `asc` or `desc`.
 * @return {number} The comparator's answer.
 */
const compareRequests = ( a, b, { field, dir } ) => {
	if ( 'duration_ms' === field ) {
		const aMeasured = 'number' === typeof a.duration_ms;
		if ( aMeasured !== ( 'number' === typeof b.duration_ms ) ) {
			return aMeasured ? -1 : 1;
		}
		if ( ! aMeasured ) {
			return 0;
		}
	}
	const aVal = a[ field ] ?? 0;
	const bVal = b[ field ] ?? 0;
	if ( aVal === bVal ) {
		return 0;
	}
	return ( aVal > bVal ? 1 : -1 ) * ( 'asc' === dir ? 1 : -1 );
};

/**
 * The Recent Requests section.
 *
 * Sorting happens here rather than on the server because the window already
 * holds the rows `dump_url` returned, so a column click costs no fetch. A
 * row missing the sort field counts as 0 and still takes a position; a
 * duration that was never measured ranks last (`compareRequests()`).
 *
 * @param {Object}                  props                Component props.
 * @param {Array}                   props.requests       The subject's requests, as `dump_url` lists them.
 * @param {Object}                  props.requestSort    The order as `{ field, dir }`, which the headings draw.
 * @param {(field: string) => void} props.onRequestSort  Receives a field name when a sortable heading is clicked.
 * @param {(rid: string) => void}   props.onSelect       Receives a row's rid on click or keyboard activation.
 * @param {string}                  props.metric         The chart's metric; `memory` bars each row by its peak memory.
 * @param {string}                  props.bucket         The bucket selection's spelling the list narrows to; '' for none.
 * @param {?string[]}               props.slots          The buckets the window's chart read, which the Time field judges against.
 * @param {(update: *) => void}     props.onBucketChange Receives the Time field's selection.
 * @param {boolean}                 [props.listing]      Whether the list is being asked afresh under new filters; the rows it holds answer the old ones, so it shows loading.
 * @param {?string}                 [props.listError]    Why the last ask for the list was refused, or null; the list keeps what it held.
 * @return {import('react').ReactElement} The section.
 */
export default function RequestTable( {
	requests,
	requestSort,
	onRequestSort,
	onSelect,
	metric,
	bucket,
	slots,
	onBucketChange,
	listing = false,
	listError = null,
} ) {
	const listRef = useRef( null );
	const sortedRequests = useMemo(
		() =>
			[ ...requests ].sort( ( a, b ) =>
				compareRequests( a, b, requestSort )
			),
		[ requests, requestSort ]
	);
	const { startIndex, endIndex, paddingTop, paddingBottom } =
		useVirtualization(
			listRef,
			ROW_HEIGHT,
			sortedRequests.length,
			'.components-modal__content'
		);
	const visibleRequests = sortedRequests.slice( startIndex, endIndex );
	const maxBar = useMemo(
		() =>
			sortedRequests.reduce(
				( max, r ) => Math.max( max, barValue( r, metric ) ),
				0
			),
		[ sortedRequests, metric ]
	);

	/**
	 * The direction a column's header draws: the sort's own on the sorted
	 * column, none on every other.
	 *
	 * @param {string} field Sort field the header stands for.
	 * @return {?string} 'asc', 'desc' or null.
	 */
	const sortDir = ( field ) =>
		requestSort.field === field ? requestSort.dir : null;

	return (
		<div className="event-logger-table event-logger-table--requests">
			<div className="newspack-nodes-section-heading event-logger-heading-row">
				<h3>
					{ listing
						? __( 'Recent Requests', 'newspack-event-logger-nodes' )
						: sprintf(
								// translators: %d: number of recent requests shown.
								__(
									'Recent Requests (%d)',
									'newspack-event-logger-nodes'
								),
								sortedRequests.length
						  ) }
				</h3>
				<BucketField
					value={ bucket }
					slots={ slots }
					onChange={ onBucketChange }
				/>
			</div>
			{ listError && (
				<p className="newspack-nodes-status is-error">
					{ sprintf(
						// translators: %s: the error message.
						__(
							'Could not list requests: %s',
							'newspack-event-logger-nodes'
						),
						listError
					) }
				</p>
			) }

			{ /* Outside listRef, so it is never a virtualized row. */ }
			<div className="event-logger-table__header newspack-nodes-table__header">
				{ REQUEST_COLUMNS.map( ( col ) =>
					col.sortable ? (
						<SortHeaderButton
							key={ col.field }
							field={ col.field }
							label={ col.label }
							dir={ sortDir( col.field ) }
							variant={ col.variant }
							onSort={ onRequestSort }
						/>
					) : (
						<div key={ col.field } className={ CELL_BASE }>
							{ col.label }
						</div>
					)
				) }
			</div>

			<div
				ref={ listRef }
				className="event-logger-table__list newspack-nodes-table"
			>
				{ listing || sortedRequests.length === 0 ? (
					<div className="event-logger-table__empty newspack-nodes-empty-state">
						{ listing
							? __(
									'Loading requests…',
									'newspack-event-logger-nodes'
							  )
							: __(
									'No requests to display',
									'newspack-event-logger-nodes'
							  ) }
					</div>
				) : (
					<>
						<div style={ { height: paddingTop } } />
						{ visibleRequests.map( ( req ) => (
							<RequestRow
								key={ req.rid }
								req={ req }
								onSelect={ onSelect }
								maxBar={ maxBar }
								metric={ metric }
							/>
						) ) }
						<div style={ { height: paddingBottom } } />
					</>
				) }
			</div>
		</div>
	);
}
