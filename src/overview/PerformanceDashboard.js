/* global requestAnimationFrame */
/**
 * Performance Dashboard — the orchestrator over the dashboard's node graph.
 *
 * `usePerformanceGraph` mounts the graph and owns every fetch; this component
 * owns none. The graph publishes its data through FOUR independent per-slice
 * view nodes — `overview:view`, `urls:view`, `urldetail:view`,
 * `requestdetail:view`. This component reads each slice with its own
 * `useNodeState`, derives the render-time values, and hands the URL table's
 * paging back through `handleUrlParamsChange`, the one callback the hook
 * returns.
 *
 * What it does own is the UI state the graph's fetchers read at fire time: the
 * server filter, the chart metric and breakdown dimension, the refresh cadence,
 * the partition a located request was found in, the search box and its results,
 * the request-table sort, and the inline "Log this URL" rule editor. It also
 * runs every command whose reply sets that state: the `?url=` and `?request=`
 * deep-link resolvers, the title lookup for a hash the loaded catalog page does
 * not carry, the search box's exact-rid lookup and its pattern search, and the
 * ruleset reads and writes behind "Log this URL". It renders the URL / request
 * detail modal and the Ask panel over it, and preserves the modal's scroll
 * position across the URL-detail ↔ request-detail switch.
 */

import {
	useState,
	useEffect,
	useMemo,
	useCallback,
	useRef,
} from '@wordpress/element';
import {
	Spinner,
	Card,
	CardBody,
	CardHeader,
	Modal,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { useNodeState, formatCommandArgs } from '@newspack-nodes/runtime';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';
import {
	computeIndentedEntries,
	spliceFoldedSpans,
} from './utils/logEntryUtils';
import {
	DASHBOARD_REFRESH_OPTIONS,
	DEFAULT_CHART_BREAKDOWN,
} from './constants';
import {
	usePerformanceGraph,
	SERVER,
	RULES_CI,
	GREP_RESULT_LIMIT,
} from './hooks/usePerformanceGraph';
import useUrlNavigation from './hooks/useUrlNavigation';
import { usePersistedChoice } from '@newspack-nodes/shared/hooks/usePersistedState';
import OverviewSection from './components/OverviewSection';
import UrlDetailView from './components/UrlDetailView';
import RequestDetailView from './components/RequestDetailView';
import AskPanel, { AskButton, useAsk } from './components/AskPanel';
import { pageFacts, factsJson } from './pageFacts';
import RuleEditModal from '../rules/RuleEditModal';
import { BLANK_RULE } from '../rules/constants';

import UrlTable from './UrlTable';

/**
 * The stand-in title for a URL whose hash is selected but whose name has not
 * arrived. `canLogUrl` compares against this exact string, so it stays the one
 * place the placeholder is spelled: put the hash here instead and the modal
 * offers a logging rule for a URL nobody has named.
 *
 * @return {string} The translated placeholder.
 */
const UNKNOWN_URL = () => __( 'Unknown URL', 'newspack-event-logger-nodes' );

/**
 * The URL modal's header stats as `[ text, label ]` pairs, in display order.
 * Memory comes last and only when something measured a peak.
 *
 * @param {?Object} stats The URL detail's stats block.
 * @return {Array<Array<string>>} Each stat's rendered text and its label.
 */
const headerStats = ( stats ) => [
	[ ( stats?.requests_per_second ?? 0 ).toFixed( 2 ), 'req/s' ],
	[ `${ stats?.avg_ms?.toFixed( 0 ) || 0 }ms`, 'avg' ],
	...( ( stats?.avg_peak_mb || 0 ) > 0
		? [ [ `${ stats.avg_peak_mb.toFixed( 1 ) }MB`, 'mem' ] ]
		: [] ),
];

import './styles/modal.scss';
import './styles/tables.scss';
import './styles/charts.scss';

/**
 * The dashboard's one component: the overview card, the URL table, and the
 * detail modal over them.
 *
 * It renders a spinner until the `overview:view` slice resolves — until then the
 * graph may not even be mounted, and an empty dashboard would read as no data.
 *
 * @param {Object}                    props         Component props.
 * @param {(message: string) => void} props.onError Ask-failure reporter, handed
 *                                                  straight to `useAsk`, which
 *                                                  calls it with the reason the
 *                                                  ask failed. The page renders
 *                                                  that as a dismissible notice.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function PerformanceDashboard( { onError } ) {
	// UI and control state only; the four view-node slices own every datum.
	const [ requestSort, setRequestSort ] = useState( {
		field: 'timestamp',
		dir: 'desc',
	} );
	const [ chartMetric, setChartMetric ] = useState( 'volume' );

	// The server filter is page-wide scope, not the overview card's own.
	const [ serverFilter, setServerFilter ] = useState( '' );

	// The breakdown dimension rides the `overview` fetch, so it lives here.
	const [ chartBreakdown, setChartBreakdown ] = useState(
		DEFAULT_CHART_BREAKDOWN
	);

	const [ searchQuery, setSearchQuery ] = useState( '' );
	const [ searchError, setSearchError ] = useState( null );
	const [ searchLoading, setSearchLoading ] = useState( false );
	// request_grep result rows, and whether the server capped them.
	const [ searchResults, setSearchResults ] = useState( null );
	const [ searchResultsTruncated, setSearchResultsTruncated ] =
		useState( false );
	const [ requestPartition, setRequestPartition ] = useState( null );
	const [ refreshInterval, setRefreshInterval ] = usePersistedChoice(
		'event-logger-refresh-interval',
		DASHBOARD_REFRESH_OPTIONS,
		'15000'
	);

	// Read, never depended on: these keep the callbacks below stable.
	const selectUrlRef = useRef(
		/** @type {( url: Object|null ) => void} */ ( () => {} )
	);
	const selectRequestRef = useRef(
		/** @type {( rid: string|null ) => void} */ ( () => {} )
	);
	// The catalog is a fresh array on every poll; `applyFoundRequest` is not.
	const urlsRef = useRef( [] );

	// Read each slice from its own per-slice view node (null until mounted).
	const overviewSlice = useNodeState( 'overview:view', 'view' );
	const urlsSlice = useNodeState( 'urls:view', 'view' );
	const urlDetailSlice = useNodeState( 'urldetail:view', 'view' );
	const requestDetailSlice = useNodeState( 'requestdetail:view', 'view' );

	const overview = overviewSlice?.data ?? null;
	const urls = useMemo( () => urlsSlice?.data ?? [], [ urlsSlice?.data ] );
	// The filtered set's own numbers, computed once, server-side.
	const urlTotals = urlsSlice?.totals ?? null;
	// The same set's slowest, and what the set is, as the server applied it.
	const urlSlowest = urlsSlice?.slowest ?? null;
	const urlFilters = urlsSlice?.filters ?? null;
	const urlDetail = urlDetailSlice?.data ?? null;
	const requestDetail = requestDetailSlice?.data ?? null;
	urlsRef.current = urls;

	// The overview reply's own series, read straight off the slice.
	const categoryData = useMemo(
		() => overview?.category_time_series ?? null,
		[ overview ]
	);
	const serverBreakdownData = useMemo(
		() => overview?.breakdowns?.server ?? null,
		[ overview ]
	);
	// Sticky across a scoped reply, and null until the first one lands.
	const [ serverNames, setServerNames ] = useState( null );
	// @longform Read, never depended on: keying the effect on the filter
	// re-derives names from the reply fetched UNDER it, collapsing a hub to
	// the one server it was scoped to the moment that filter clears.
	const serverFilterRef = useRef( serverFilter );
	serverFilterRef.current = serverFilter;
	useEffect( () => {
		if ( ! serverBreakdownData || serverFilterRef.current ) {
			return;
		}
		const names = new Set();
		Object.values( serverBreakdownData ).forEach( ( bucket ) =>
			Object.keys( bucket ).forEach( ( n ) => names.add( n ) )
		);
		// The overflow fold, not a server: a read scoped to it matches nothing.
		names.delete( 'Other' );
		setServerNames( Array.from( names ).sort() );
	}, [ serverBreakdownData ] );

	// @longform One server draws a single bar, and a server filter draws that
	// server against itself, so neither can chart this axis. Derived rather
	// than written back: a reply carrying one server before its sibling
	// reports would otherwise strand the choice for the session. `null` is a
	// reply not yet landed, which is neither. The selector shows this derived
	// value while the state keeps the operator's own choice, so a fallback is
	// undone by the axis returning rather than by them choosing again.
	const canBreakDownByServer =
		! serverFilter && ( serverNames === null || serverNames.length >= 2 );
	const activeBreakdown =
		'server' === chartBreakdown && ! canBreakDownByServer
			? 'status'
			: chartBreakdown;

	/**
	 * The drawn dimension's series, or null while none is in hand.
	 *
	 * Keyed off `activeBreakdown` rather than the operator's choice, so the
	 * chart and the dropdown never disagree when `server` falls back. An absent
	 * key is a dropdown switch the reply has not caught up with, which
	 * `breakdownState` reads as `pending` instead of as an empty dimension.
	 */
	const chartBreakdownData = useMemo( () => {
		if ( ! overview?.breakdowns ) {
			return null;
		}
		return overview.breakdowns[ activeBreakdown ] ?? null;
	}, [ overview, activeBreakdown ] );

	const {
		selectedUrl,
		selectedRequest,
		selectUrl,
		selectRequest: baseSelectRequest,
		initialSearchQuery,
		setInitialSearchQuery,
		updateBrowserUrl,
		deepLink,
		clearDeepLink,
	} = useUrlNavigation( urls );

	selectUrlRef.current = selectUrl;
	selectRequestRef.current = baseSelectRequest;
	// @longform What is open RIGHT NOW, for the reply handlers below: a reply
	// must not yank the operator back to a URL they have already left. Written
	// where the selection is MADE as well as on render, because a reply can
	// land before React has re-rendered the selection that preceded it.
	const selectedUrlRef = useRef( selectedUrl );
	selectedUrlRef.current = selectedUrl;
	/**
	 * Select a URL, recording it in `selectedUrlRef` in the same call.
	 *
	 * @param {?Object} urlObj The `{hash, url}` entry to open, or null to close.
	 */
	const selectUrlNow = useCallback( ( urlObj ) => {
		selectedUrlRef.current = urlObj;
		selectUrlRef.current( urlObj );
	}, [] );

	// The graph polls and publishes; this page's own verbs are below.
	const { handleUrlParamsChange } = usePerformanceGraph( {
		serverFilter,
		chartBreakdown: activeBreakdown,
		refreshInterval,
		requestPartition,
		selectedUrl,
		selectedRequest,
	} );

	// @longform One picker, several doors. Live scope, like every other FETCH:
	// `urls` and `url_detail` both ask for what is selected now, and a brief
	// assembled for the previous one would contradict the modal it was asked
	// from. The echoed `urlFilters` labels what is on screen, never steers.
	const ask = useAsk( { onError, serverFilter } );

	// Reset the search-sourced partition when leaving request detail.
	useEffect( () => {
		if ( ! selectedRequest ) {
			setRequestPartition( null );
		}
	}, [ selectedRequest ] );

	// Where the URL detail was scrolled to, restored when the request closes.
	const urlDetailScrollRef = useRef( 0 );

	/**
	 * Open one of the URL's requests inside the modal, or return to the URL
	 * detail.
	 *
	 * @param {?string} rid         The request id to open, or null to go back.
	 * @param {number}  [partition] The partition the rid was located in. Omit it
	 *                              to keep the partition already selected.
	 */
	const selectRequest = useCallback(
		( rid, partition ) => {
			if ( rid ) {
				// Entering request detail — save current scroll position.
				const modalContent = document.querySelector(
					'.event-logger-performance-modal .components-modal__content'
				);
				if ( modalContent ) {
					urlDetailScrollRef.current = modalContent.scrollTop;
				}
			}
			// The partition rides WITH the selection, never recovered later.
			if ( undefined !== partition ) {
				setRequestPartition( partition );
			}
			baseSelectRequest( rid );
		},
		[ baseSelectRequest ]
	);

	/**
	 * Open a URL's modal, leaving behind whatever request was open inside the
	 * previous one.
	 *
	 * @param {Object} url The `{hash, url}` catalog entry to open.
	 */
	const openUrl = useCallback(
		( url ) => {
			selectRequest( null );
			selectUrlNow( url );
		},
		[ selectRequest, selectUrlNow ]
	);

	/**
	 * Sort the URL modal's request table by one column. Clicking the column
	 * already sorted descending flips it to ascending; every other click sorts
	 * descending.
	 *
	 * @param {string} field Field key to sort by.
	 */
	const handleRequestSort = useCallback( ( field ) => {
		setRequestSort( ( prev ) => ( {
			field,
			dir: prev.field === field && prev.dir === 'desc' ? 'asc' : 'desc',
		} ) );
	}, [] );

	/**
	 * Ask `url_detail` for the title of a hash the loaded catalog page does not
	 * carry.
	 *
	 * A hash becomes a title in two steps that need no pairing: select what is
	 * already known, then ask for the name. The reply names the hash it
	 * answered, which is how the guard below tells whose title it is.
	 */
	const { run: lookupUrl } = useCommandOnce( {
		ci: SERVER,
		command: 'url_detail',
		scope: `${ SERVER }:url_detail:lookup`,
		retry: true,
		onDone: ( { result, args } ) => {
			const url = result?.stats?.url;
			// @longform Only the selection this answers: the operator may
			// have opened another URL while it was in flight, and yanking
			// them back to the one they left beats an untitled hash.
			if ( url && selectedUrlRef.current?.hash === args[ 0 ] ) {
				selectUrlNow( { hash: args[ 0 ], url } );
			}
		},
	} );

	/**
	 * Open a request the server has just located, showing it now and letting the
	 * title fill in when `lookupUrl` answers.
	 *
	 * @param {string} rid  The request id that was found.
	 * @param {Object} data The `request_search` reply, carrying `url_hash` and
	 *                      `partition`.
	 */
	const applyFoundRequest = useCallback(
		( rid, data ) => {
			const known = urlsRef.current.find(
				( u ) => u.hash === data.url_hash
			);
			// @longform A rid names ONE request; the server filter is a
			// browsing scope, and `url_detail` honours it. A search landing
			// outside it would ask for a row the scope excludes and answer
			// "URL not found" for a URL plainly on screen. The navigation
			// wins, and the select resets so nothing is hidden.
			setServerFilter( '' );
			setRequestPartition( data.partition );
			selectUrlNow(
				known ?? { hash: data.url_hash, url: UNKNOWN_URL() }
			);
			selectRequestRef.current( rid );
			if ( ! known ) {
				lookupUrl( formatCommandArgs( [ data.url_hash ] ) );
			}
		},
		[ lookupUrl, selectUrlNow, setServerFilter ]
	);

	/**
	 * Whether a `request_search` reply located the request.
	 *
	 * @param {?Object} data The reply payload.
	 * @return {boolean} True when it carries both a URL hash and a partition.
	 */
	const found = ( data ) =>
		data && data.url_hash && undefined !== data.partition;

	/**
	 * Resolve a `?request=` deep link. Only the server can answer for a rid,
	 * whose partition nothing on the page knows.
	 *
	 * A RETRIED read: an undelivered send is asked again while the link still
	 * stands, and a not-found is an answer that ends it.
	 */
	const { run: askDeepLinkRequest } = useCommandOnce( {
		ci: SERVER,
		command: 'request_search',
		scope: `${ SERVER }:request_search:deeplink`,
		retry: true,
		onDone: ( { result, args } ) => {
			if ( deepLinkRef.current.requestId !== args[ 0 ] ) {
				return;
			}
			if ( found( result ) ) {
				applyFoundRequest( args[ 0 ], result );
				clearDeepLinkRef.current();
				return;
			}
			// Not-found is an ANSWER; let the ?url= hash have its turn.
			clearDeepLinkRef.current( 'request' );
		},
	} );

	/**
	 * Resolve a `?url=` deep link, which needs the server whenever the hash
	 * falls outside the loaded catalog page and so carries no title.
	 */
	const { run: askDeepLinkUrl } = useCommandOnce( {
		ci: SERVER,
		command: 'url_detail',
		scope: `${ SERVER }:url_detail:deeplink`,
		retry: true,
		onDone: ( { result, args } ) => {
			if ( deepLinkRef.current.urlHash !== args[ 0 ] ) {
				return;
			}
			selectUrlNow( {
				hash: args[ 0 ],
				url: result?.stats?.url || UNKNOWN_URL(),
			} );
			clearDeepLinkRef.current();
		},
	} );

	const clearDeepLinkRef = useRef( clearDeepLink );
	clearDeepLinkRef.current = clearDeepLink;
	// @longform Apply only what is STILL asked for. Both resolvers are retried
	// reads, so their reply lands well after the operator may have closed the
	// modal or walked Back — and an answer to a cancelled question reopened
	// what they left, pushing a history entry over the one they walked to.
	const deepLinkRef = useRef( deepLink );
	deepLinkRef.current = deepLink;

	// The rid answers the hash AND the partition; `?url=` is the fallback.
	useEffect( () => {
		if ( deepLink.requestId ) {
			askDeepLinkRequest( formatCommandArgs( [ deepLink.requestId ] ) );
			return;
		}
		if ( deepLink.urlHash ) {
			askDeepLinkUrl( formatCommandArgs( [ deepLink.urlHash ] ) );
		}
	}, [ deepLink, askDeepLinkRequest, askDeepLinkUrl ] );

	/**
	 * Look one exact request id up in the request index and open it. One ask per
	 * submit, and a miss is an answer: it fills the search box's error instead
	 * of being re-asked.
	 */
	const { run: searchForRequest } = useCommandOnce( {
		ci: SERVER,
		command: 'request_search',
		scope: `${ SERVER }:request_search:search`,
		onDone: ( { result, args } ) => {
			setSearchLoading( false );
			if ( ! found( result ) ) {
				setSearchError(
					sprintf(
						// translators: %s: the request ID that was searched for.
						__(
							'Request "%s" not found — prefix with / to search recent traffic',
							'newspack-event-logger-nodes'
						),
						args[ 0 ]
					)
				);
				return;
			}
			applyFoundRequest( args[ 0 ], result );
			setSearchQuery( '' );
			updateBrowserUrl( {
				search: null,
				url: result.url_hash,
				request: args[ 0 ],
			} );
		},
	} );

	/**
	 * Run the exact-id search, clearing the previous answer first so a stale
	 * error or result list never sits under a search still in flight.
	 *
	 * @param {string} rid The request id to look up.
	 */
	const searchRequest = useCallback(
		( rid ) => {
			if ( ! rid || ! rid.trim() ) {
				return;
			}
			setSearchLoading( true );
			setSearchError( null );
			setSearchResults( null );
			searchForRequest( formatCommandArgs( [ rid.trim() ] ) );
		},
		[ searchForRequest ]
	);

	/**
	 * Scan the recent firehose window for a pattern, capped at
	 * `GREP_RESULT_LIMIT` matching requests. No match reports through the search
	 * box's error line, so an empty list never sits there unexplained.
	 */
	const { run: requestGrep } = useCommandOnce( {
		ci: SERVER,
		command: 'request_grep',
		// A search pattern is free text the operator typed, not an identity.
		subjectOf: () => null,
		onDone: ( { result, error } ) => {
			setSearchLoading( false );
			const results = result?.results ?? [];
			if ( results.length > 0 ) {
				setSearchResults( results );
				setSearchResultsTruncated( !! result?.truncated );
				return;
			}
			setSearchError(
				error ||
					__(
						'No matches in recent traffic',
						'newspack-event-logger-nodes'
					)
			);
		},
	} );

	/**
	 * Pattern-search recent firehose traffic and render the matching-request
	 * list.
	 *
	 * @param {string} pattern The search pattern.
	 */
	const patternSearch = useCallback(
		( pattern ) => {
			setSearchLoading( true );
			setSearchError( null );
			setSearchResults( null );
			setSearchResultsTruncated( false );
			requestGrep(
				formatCommandArgs( [ pattern.trim() ], {
					limit: GREP_RESULT_LIMIT,
				} )
			);
		},
		[ requestGrep ]
	);

	/**
	 * Route the search box's submit. A rid-shaped token is an exact request
	 * lookup against the index; anything else — a URL or free text — is a
	 * pattern search over recent traffic.
	 *
	 * @param {string} query The raw search input.
	 */
	const handleSearch = useCallback(
		( query ) => {
			const trimmed = ( query || '' ).trim();
			if ( ! trimmed ) {
				return;
			}
			if ( /^[a-zA-Z0-9_-]+$/.test( trimmed ) ) {
				searchRequest( trimmed );
			} else {
				patternSearch( trimmed );
			}
		},
		[ searchRequest, patternSearch ]
	);

	/**
	 * Open a pattern-search result by re-running the exact-rid path. A grep row
	 * carries the rid alone, and the URL hash and partition the modal needs come
	 * only from `request_search`.
	 *
	 * @param {string} rid The request id of the clicked row.
	 */
	const selectSearchResult = useCallback(
		( rid ) => {
			setSearchResults( null );
			setSearchResultsTruncated( false );
			searchRequest( rid );
		},
		[ searchRequest ]
	);

	/**
	 * The URL's requests in the order the modal's table shows them.
	 *
	 * Sorting happens here rather than on the server because the modal already
	 * holds the rows `url_detail` returned, so a column click costs no fetch. A
	 * row missing the sort field counts as 0 and still takes a position.
	 */
	const sortedRequests = useMemo( () => {
		if ( ! urlDetail?.requests ) {
			return [];
		}
		const sorted = [ ...urlDetail.requests ];
		sorted.sort( ( a, b ) => {
			const aVal = a[ requestSort.field ] ?? 0;
			const bVal = b[ requestSort.field ] ?? 0;
			if ( requestSort.dir === 'asc' ) {
				return aVal > bVal ? 1 : -1;
			}
			return aVal < bVal ? 1 : -1;
		} );
		return sorted;
	}, [ urlDetail?.requests, requestSort ] );

	// `Flame_Builder_Node` builds the tree; nothing here derives one.
	const requestFlameData = requestDetail?.flame_data ?? null;

	/**
	 * The entry rows the table renders, and how many of them are real.
	 *
	 * A folded request's merged tree splices in where its entries were, so the
	 * indent walk reads one list either way. `realEntryCount` excludes the
	 * placeholder rows `computeIndentedEntries` inserts to span time gaps,
	 * which is what the header counts.
	 */
	const { entries: indentedEntries, realCount: realEntryCount } = useMemo(
		() =>
			computeIndentedEntries(
				spliceFoldedSpans(
					requestDetail?.entries,
					requestDetail?.flame
				)
			),
		[ requestDetail?.entries, requestDetail?.flame ]
	);

	/**
	 * The wall clock the Time Breakdown divides by.
	 *
	 * Its categories come from `build_leaderboard( server )`, so the average has
	 * to be that server's — not the site's, which is the wider question, and not
	 * the filtered URL set's, which is a narrower one. Unfiltered the first two
	 * are the same number.
	 */
	const breakdownAvgMs = useMemo( () => {
		if ( ! serverFilter || ! serverBreakdownData ) {
			return overview?.global_avg_ms ?? 0;
		}
		let totalC = 0;
		let totalS = 0;
		for ( const bucket of Object.values( serverBreakdownData ) ) {
			const entry = bucket?.[ serverFilter ];
			if ( entry ) {
				totalC += entry.c || 0;
				totalS += entry.s || 0;
			}
		}
		return totalC > 0 ? totalS / totalC : 0;
	}, [ serverFilter, serverBreakdownData, overview?.global_avg_ms ] );

	// Inline "Log this URL" state: the open draft, the ruleset, and the error.
	const [ ruleDraft, setRuleDraft ] = useState( null );
	// The whole ruleset as the server last reported it; null until it answers.
	const [ rules, setRules ] = useState( null );
	const [ ruleError, setRuleError ] = useState( null );

	const ruleUrl = selectedUrl?.url;
	const canLogUrl = !! ruleUrl && UNKNOWN_URL() !== ruleUrl;
	// Strip origin so exact rule matches REQUEST_URI ('?' = match sentinel).
	const rulePath = ruleUrl ? ruleUrl.replace( /^https?:\/\/[^/]+/, '' ) : '';
	// A path already carrying a query is the exact-plus-query-prefix form.
	const needsSentinel = ruleUrl && ! rulePath.includes( '?' );
	const exactPattern = needsSentinel ? `${ rulePath }?` : rulePath;

	/**
	 * Read the whole ruleset. A retried READ, and `existingRule` is DERIVED from
	 * the answer on every render rather than copied into state, so the button's
	 * label and the draft it opens always agree with the ruleset last read.
	 */
	const { run: listRules } = useCommandOnce( {
		ci: RULES_CI,
		command: 'list',
		retry: true,
		onDone: ( { result } ) => setRules( result?.rules ?? null ),
	} );
	const existingRule =
		canLogUrl && ! selectedRequest && rules
			? rules.find( ( r ) => r.pattern === exactPattern ) ?? null
			: null;

	// A new URL is a new rule question: drop the old draft and re-read.
	useEffect( () => {
		setRuleError( null );
		setRuleDraft( null );
		if ( canLogUrl && ! selectedRequest ) {
			listRules( [] );
		}
	}, [ ruleUrl, exactPattern, canLogUrl, selectedRequest, listRules ] );

	/**
	 * Open `RuleEditModal` on the exact rule for this URL, or on a blank draft
	 * seeded with its pattern when no rule matches.
	 */
	const openRuleEditor = useCallback( () => {
		if ( ! canLogUrl ) {
			return;
		}
		setRuleError( null );
		setRuleDraft(
			existingRule ?? {
				...BLANK_RULE,
				pattern: exactPattern,
				action: 'log',
			}
		);
	}, [ canLogUrl, existingRule, exactPattern ] );

	/**
	 * Write one rule. The reply closes the editor or fills the banner, and a
	 * success re-reads the ruleset rather than patching the copy in hand.
	 */
	const { run: upsertRule } = useCommandOnce( {
		ci: RULES_CI,
		command: 'upsert',
		// The rule DOCUMENT is the first token; the rule it names is the id.
		subjectOf: ( [ rule ] ) => JSON.parse( rule ).id ?? null,
		onDone: ( { result, error } ) => {
			setRuleDraft( null );
			if ( result ) {
				listRules( [] );
				return;
			}
			setRuleError(
				error ||
					__(
						'Could not save the logging rule.',
						'newspack-event-logger-nodes'
					)
			);
		},
	} );
	/**
	 * Save the open draft, sending the rule as one JSON document.
	 *
	 * @param {Object} draft The rule as `RuleEditModal` hands it back.
	 */
	const saveRule = useCallback(
		( draft ) => upsertRule( [ JSON.stringify( draft ) ] ),
		[ upsertRule ]
	);

	/**
	 * Delete one rule by id, on the same reply contract as `upsertRule`.
	 */
	const { run: removeRule } = useCommandOnce( {
		ci: RULES_CI,
		command: 'delete',
		onDone: ( { result, error } ) => {
			setRuleDraft( null );
			if ( result ) {
				listRules( [] );
				return;
			}
			setRuleError(
				error ||
					__(
						'Could not delete the logging rule.',
						'newspack-event-logger-nodes'
					)
			);
		},
	} );
	/**
	 * Delete the rule the open draft names. The editor offers this only for a
	 * draft that already has an id, so a blank "Log this URL" draft cannot.
	 */
	const deleteRule = useCallback(
		() => removeRule( [ ruleDraft?.id ] ),
		[ removeRule, ruleDraft ]
	);

	// Handle initial search query from URL parameter.
	useEffect( () => {
		if ( initialSearchQuery ) {
			searchRequest( initialSearchQuery );
			setInitialSearchQuery( null );
		}
	}, [ initialSearchQuery, searchRequest, setInitialSearchQuery ] );

	// Manage modal scroll when switching URL detail ↔ request detail.
	useEffect( () => {
		const modalContent = document.querySelector(
			'.event-logger-performance-modal .components-modal__content'
		);
		if ( ! modalContent ) {
			return;
		}
		if ( selectedRequest ) {
			modalContent.scrollTop = 0;
		} else {
			requestAnimationFrame( () => {
				modalContent.scrollTop = urlDetailScrollRef.current;
			} );
		}
	}, [ selectedRequest ] );

	// Initial load pending: no data, no error, and no fetch started yet.
	const overviewResolved =
		!! overviewSlice &&
		( null !== overviewSlice.data ||
			!! overviewSlice.error ||
			overviewSlice.loading );
	if ( ! overviewResolved ) {
		return (
			<div className="newspack-nodes-performance-loading">
				<Spinner />
				<p>
					{ __(
						'Loading performance data…',
						'newspack-event-logger-nodes'
					) }
				</p>
			</div>
		);
	}

	return (
		<div className="event-logger-performance-dashboard">
			{ /* Facts only, no instructions: anything reading this page gets
			     clean numbers instead of a scraped table. Rendered only for
			     someone who can already see the dashboard. */ }
			<script
				type="application/json"
				id="newspack-nodes-page-facts"
				// eslint-disable-next-line react/no-danger
				dangerouslySetInnerHTML={ {
					__html: factsJson(
						pageFacts( {
							urlTotals,
							urlSlowest,
							urlFilters,
							selectedUrl,
							urlDetail,
							selectedRequest,
							requestPartition,
							requestDetail,
						} )
					),
				} }
			/>

			{ /* Overview Stats */ }
			<OverviewSection
				ask={ ask }
				overview={ overview }
				urlTotals={ urlTotals }
				breakdownAvgMs={ breakdownAvgMs }
				serverFilter={ serverFilter }
				setServerFilter={ setServerFilter }
				serverNames={ serverNames }
				searchQuery={ searchQuery }
				setSearchQuery={ setSearchQuery }
				searchLoading={ searchLoading }
				searchError={ searchError }
				onSearch={ handleSearch }
				searchResults={ searchResults }
				searchResultsTruncated={ searchResultsTruncated }
				onSelectResult={ selectSearchResult }
				refreshInterval={ refreshInterval }
				setRefreshInterval={ setRefreshInterval }
				chartMetric={ chartMetric }
				setChartMetric={ setChartMetric }
				chartBreakdown={ activeBreakdown }
				canBreakDownByServer={ canBreakDownByServer }
				setChartBreakdown={ setChartBreakdown }
				breakdownData={ chartBreakdownData }
				categoryData={ categoryData }
			/>

			{ /* Main Content */ }
			<div className="event-logger-performance-content">
				{ /* URL List */ }
				<div className="event-logger-performance-urls">
					<Card>
						<CardHeader>
							<h2>
								{ __(
									'URLs by Request Count',
									'newspack-event-logger-nodes'
								) }
							</h2>
						</CardHeader>
						<CardBody>
							<UrlTable
								urls={ urls }
								selectedUrl={ selectedUrl }
								onSelect={ openUrl }
								onParamsChange={ handleUrlParamsChange }
								totalUrls={ urlsSlice?.rows ?? 0 }
								metric={ chartMetric }
							/>
						</CardBody>
					</Card>
				</div>
			</div>

			{ /* URL/Request Detail Modal */ }
			{ /* The SELECTION opens this, never a slice: each pane below owns
			     its own empty state, and a modal that can close is the only way
			     back out of one. */ }
			{ selectedUrl && (
				<Modal
					title={
						selectedRequest && requestDetail
							? sprintf(
									// translators: %s: the request ID.
									__(
										'Request: %s',
										'newspack-event-logger-nodes'
									),
									selectedRequest
							  )
							: selectedUrl.url
					}
					onRequestClose={ () => {
						// Keep URL modal open while the nested editor is open.
						if ( ruleDraft ) {
							return;
						}
						selectUrl( null );
						selectRequest( null );
					} }
					className="event-logger-performance-modal newspack-nodes-modal newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui"
					headerActions={
						selectedRequest ? (
							<>
								<AskButton ask={ ask } />
								<button
									type="button"
									className="button is-plain event-logger-modal-back-button"
									onClick={ () => selectRequest( null ) }
									aria-label={ __(
										'Back to URL details',
										'newspack-event-logger-nodes'
									) }
								>
									&larr;
								</button>
							</>
						) : (
							<>
								{ urlDetail && (
									<div className="event-logger-header-stats newspack-nodes-stats-grid">
										{ headerStats( urlDetail.stats ).map(
											( [ text, label ] ) => (
												<span
													key={ label }
													className="newspack-nodes-stat"
												>
													{ text }
													<small className="newspack-nodes-stat-label">
														{ label }
													</small>
												</span>
											)
										) }
									</div>
								) }
								<AskButton ask={ ask } />
								<div className="event-logger-rule-control">
									<button
										type="button"
										className="button"
										// @longform Disabled until the
										// ruleset is KNOWN: with `rules`
										// still null the button reads "Log
										// this URL" and opens a blank draft,
										// and an id-less upsert matches by
										// PATTERN — so saving it would
										// replace a configured rule's hooks
										// and thresholds with nothing.
										disabled={
											! canLogUrl || null === rules
										}
										onClick={ openRuleEditor }
									>
										{ existingRule
											? __(
													'Edit logging rule',
													'newspack-event-logger-nodes'
											  )
											: __(
													'Log this URL',
													'newspack-event-logger-nodes'
											  ) }
									</button>
									{ ruleError && (
										<span className="event-logger-rule-error newspack-nodes-status is-error">
											{ ruleError }
										</span>
									) }
								</div>
							</>
						)
					}
				>
					{ /* URL Details View */ }
					{ ! selectedRequest && ! urlDetail && (
						<p
							className={ `newspack-nodes-status${
								urlDetailSlice?.error ? ' is-error' : ''
							}` }
						>
							{ urlDetailSlice?.error
								? sprintf(
										// translators: %s: the error message.
										__(
											'Could not load this URL: %s',
											'newspack-event-logger-nodes'
										),
										urlDetailSlice.error
								  )
								: __(
										'Loading URL…',
										'newspack-event-logger-nodes'
								  ) }
						</p>
					) }

					{ ! selectedRequest && urlDetail && (
						<UrlDetailView
							urlDetail={ urlDetail }
							sortedRequests={ sortedRequests }
							requestSort={ requestSort }
							onRequestSort={ handleRequestSort }
							onSelectRequest={ selectRequest }
							urlHash={ selectedUrl.hash }
						/>
					) }

					{ /* Request Details View */ }
					{ selectedRequest && requestDetail && (
						<RequestDetailView
							requestDetail={ requestDetail }
							flameData={ requestFlameData }
							indentedEntries={ indentedEntries }
							realEntryCount={ realEntryCount }
							rid={ selectedRequest }
							partition={ requestPartition }
						/>
					) }

					{ /* A selected request with nothing to show is a state,
					     never a blank panel: both sections gate on the pair. */ }
					{ selectedRequest && ! requestDetail && (
						<p
							className={ `newspack-nodes-status${
								requestDetailSlice?.error ? ' is-error' : ''
							}` }
						>
							{ requestDetailSlice?.error
								? sprintf(
										// translators: %s: the error message.
										__(
											'Could not load this request: %s',
											'newspack-event-logger-nodes'
										),
										requestDetailSlice.error
								  )
								: __(
										'Loading request…',
										'newspack-event-logger-nodes'
								  ) }
						</p>
					) }
				</Modal>
			) }

			{ /* Inline rule editor for "Log this URL" (URL-detail view only). */ }
			{ selectedUrl && ! selectedRequest && ruleDraft && (
				<RuleEditModal
					rule={ ruleDraft }
					onSave={ saveRule }
					onCancel={ () => setRuleDraft( null ) }
					onDelete={ ruleDraft.id ? deleteRule : undefined }
				/>
			) }

			{ /* The `?` picker's answer, last: it is summoned from the modals
			     above and has to paint over whichever one is open. */ }
			<AskPanel ask={ ask } />
		</div>
	);
}
