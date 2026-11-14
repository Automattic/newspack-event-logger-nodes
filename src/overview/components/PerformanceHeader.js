/**
 * The Performance page's header controls — the Ask trigger, the request-ID
 * or `/url` pattern search and the refresh cadence every poll asks at — and,
 * as `SearchFindings`, what a pattern search matched and the lines it
 * skipped. Every value and setter arrives from the page, which runs the
 * searches; the page's frame puts the controls in the shell header.
 */

import { SearchControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import RefreshPicker from '@newspack-nodes/shared/components/RefreshPicker';
import UnparseableLinesNotice from '@newspack-nodes/shared/components/UnparseableLinesNotice';
import { writeStorage } from '@newspack-nodes/shared/utils/storage';
import { AskButton } from './AskPanel';
import { DASHBOARD_REFRESH_OPTIONS } from '../constants';
import { REFRESH_KEY } from '../sources';
import { requestedUrl } from '../../log-table/urlIdentity';

/**
 * The page's header controls: the Ask trigger, the request search, the
 * refresh cadence every poll asks at.
 *
 * @param {Object}                  props                Component props.
 * @param {Object}                  props.ask            The `useAsk` state.
 * @param {string}                  props.searchQuery    The search box's text.
 * @param {(query: string) => void} props.setSearchQuery Takes its new text.
 * @param {(query: string) => void} props.onSearch       Runs a search.
 * @param {boolean}                 props.searchLoading  Whether a search is out.
 * @param {?string}                 props.searchError    Its refusal, or null.
 * @return {import('react').ReactElement} The controls.
 */
function HeaderControls( {
	ask,
	searchQuery,
	setSearchQuery,
	onSearch,
	searchLoading,
	searchError,
} ) {
	const [ refresh, setRefresh ] = usePageState( 'refresh', {
		writes: true,
	} );
	return (
		<div
			style={ {
				display: 'flex',
				flexWrap: 'wrap',
				justifyContent: 'flex-end',
				alignItems: 'center',
				gap: '16px',
			} }
		>
			<AskButton ask={ ask } />
			<div
				style={ { display: 'flex', alignItems: 'center', gap: '8px' } }
			>
				<SearchControl
					label={ __(
						'Request ID or /url pattern',
						'newspack-event-logger-nodes'
					) }
					placeholder={ __(
						'Request ID or /url pattern…',
						'newspack-event-logger-nodes'
					) }
					value={ searchQuery }
					onChange={ setSearchQuery }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' ) {
							onSearch( searchQuery );
						}
					} }
					style={ { width: '250px' } }
				/>
			</div>
			{ searchLoading && (
				<span
					className="newspack-nodes-status is-muted"
					style={ { fontSize: '12px' } }
				>
					{ __( 'Searching…', 'newspack-event-logger-nodes' ) }
				</span>
			) }
			{ searchError && (
				<span
					className="newspack-nodes-status is-error"
					style={ { fontSize: '12px' } }
				>
					{ searchError }
				</span>
			) }
			<div
				style={ { display: 'flex', alignItems: 'center', gap: '8px' } }
			>
				<span
					className="newspack-nodes-status"
					style={ { fontSize: '13px' } }
				>
					{ __( 'Refresh:', 'newspack-event-logger-nodes' ) }
				</span>
				<RefreshPicker
					value={ refresh }
					options={ DASHBOARD_REFRESH_OPTIONS }
					onChange={ ( next ) => {
						writeStorage( REFRESH_KEY, String( next ) );
						setRefresh( next );
					} }
				/>
			</div>
		</div>
	);
}

/**
 * What a pattern search matched in recent traffic, each match opening its
 * request.
 *
 * @param {Object}                props           Component props.
 * @param {?Array}                props.results   The matched requests, or null.
 * @param {boolean}               props.truncated Whether the server capped them.
 * @param {(rid: string) => void} props.onSelect  Opens one.
 * @return {?import('react').ReactElement} The list, or nothing.
 */
function SearchResults( { results, truncated, onSelect } ) {
	if ( ! Array.isArray( results ) || 0 === results.length ) {
		return null;
	}
	return (
		<div className="event-logger-search-results">
			<p className="event-logger-search-results-caption newspack-nodes-status">
				{ __(
					'Matches in recent traffic',
					'newspack-event-logger-nodes'
				) }
			</p>
			<ul>
				{ results.map( ( result ) => (
					<li key={ result.rid }>
						<button
							type="button"
							className="button button-small event-logger-search-result"
							onClick={ () => onSelect( result.rid ) }
						>
							<span className="event-logger-search-result-method newspack-nodes-status">
								{ result.method }
							</span>
							<span className="event-logger-search-result-url">
								{ requestedUrl( result.url ) || result.rid }
							</span>
							<span className="event-logger-search-result-count newspack-nodes-status">
								{ sprintf(
									// translators: %d: number of matching lines in the request.
									_n(
										'%d match',
										'%d matches',
										result.match_count || 0,
										'newspack-event-logger-nodes'
									),
									result.match_count || 0
								) }
							</span>
						</button>
					</li>
				) ) }
			</ul>
			{ truncated && (
				<p className="event-logger-search-results-note newspack-nodes-status">
					{ __(
						'Showing first results — narrow your search for more.',
						'newspack-event-logger-nodes'
					) }
				</p>
			) }
		</div>
	);
}

/**
 * What a pattern search matched in recent traffic, and the lines it skipped.
 *
 * @param {Object}                props                  Component props.
 * @param {?Array}                props.results          The matched requests, or null.
 * @param {boolean}               props.truncated        Whether the server capped them.
 * @param {number|undefined}      props.unparseableLines Lines the last search skipped; undefined before one answers.
 * @param {(rid: string) => void} props.onSelect         Opens a matched request by its rid.
 * @return {import('react').ReactElement} The findings.
 */
export function SearchFindings( {
	results,
	truncated,
	unparseableLines,
	onSelect,
} ) {
	return (
		<>
			<SearchResults
				results={ results }
				truncated={ truncated }
				onSelect={ onSelect }
			/>
			<UnparseableLinesNotice count={ unparseableLines } />
		</>
	);
}

/**
 * The header controls.
 *
 * @param {Object}                  props                Component props.
 * @param {Object}                  props.ask            The `useAsk` state.
 * @param {string}                  props.searchQuery    The search box's text.
 * @param {(query: string) => void} props.setSearchQuery Takes its new text.
 * @param {boolean}                 props.searchLoading  Whether a search is out.
 * @param {?string}                 props.searchError    Its refusal, already translated, or null.
 * @param {(query: string) => void} props.onSearch       Runs a search, given the raw query.
 * @return {import('react').ReactElement} The controls.
 */
export default function PerformanceHeader( {
	ask,
	searchQuery,
	setSearchQuery,
	searchLoading,
	searchError,
	onSearch,
} ) {
	return (
		<HeaderControls
			ask={ ask }
			searchQuery={ searchQuery }
			setSearchQuery={ setSearchQuery }
			searchLoading={ searchLoading }
			searchError={ searchError }
			onSearch={ onSearch }
		/>
	);
}
