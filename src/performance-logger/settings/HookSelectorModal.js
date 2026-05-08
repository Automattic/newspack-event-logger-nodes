/**
 * Hook Selector Modal Component
 *
 * A modal for browsing and selecting WordPress hooks organized by category.
 * Fetches registered hooks from $wp_filter via REST API.
 */

import { useState, useMemo, useEffect } from '@wordpress/element';
import {
	Modal,
	Button,
	SearchControl,
	CheckboxControl,
	Spinner,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import '../styles/hook-selector.scss';

/**
 * Recommended hooks from config file (source of truth).
 */
const RECOMMENDED_HOOKS = new Set( window.eventLoggerRecommendedHooks || [] );

/**
 * Category metadata with descriptions.
 */
const CATEGORY_META = {
	Lifecycle: { description: 'Core request lifecycle' },
	'Content Rendering': { description: 'Block & content output' },
	'Query & Posts': { description: 'Database queries & post ops' },
	'Taxonomies & Terms': { description: 'Categories & tags' },
	'Users & Auth': { description: 'Authentication & caps' },
	'Options & Settings': { description: 'Options API (high volume)' },
	'REST API': { description: 'REST endpoints' },
	Admin: { description: 'Admin screens & updates' },
	'Scripts & Styles': { description: 'Asset loading' },
	Media: { description: 'Uploads & attachments' },
	Comments: { description: 'Comments & pingbacks' },
	URLs: { description: 'URL generation & links' },
	Cron: { description: 'Scheduled & future posts' },
	Widgets: { description: 'Widget areas' },
	Theme: { description: 'Theme & customizer' },
	Localization: { description: 'Translations (noisy)' },
	Sanitization: { description: 'Input escaping (noisy)' },
	HTTP: { description: 'Remote requests & mail' },
	AJAX: { description: 'AJAX & heartbeat' },
	'Block Editor': { description: 'Blocks & TinyMCE' },
	Metadata: { description: 'Meta operations' },
	Feeds: { description: 'RSS, Atom, RDF' },
	Menus: { description: 'Navigation menus' },
	Other: { description: 'Uncategorized hooks' },
};

/**
 * Hook Selector Modal component.
 *
 * @param {Object}   props          Component props.
 * @param {boolean}  props.isOpen   Whether the modal is open.
 * @param {Function} props.onClose  Close callback.
 * @param {Array}    props.selected Currently selected hooks.
 * @param {Function} props.onSelect Callback when hooks are selected.
 * @param {string}   props.mode     'include' or 'exclude' mode.
 * @return {JSX.Element|null} Rendered component.
 */
export default function HookSelectorModal( {
	isOpen,
	onClose,
	selected = [],
	onSelect,
	mode = 'exclude',
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ expandedCategories, setExpandedCategories ] = useState( new Set() );
	const [ localSelected, setLocalSelected ] = useState( new Set( selected ) );
	const [ hookCategories, setHookCategories ] = useState( {} );
	const [ loading, setLoading ] = useState( false );

	// Fetch registered hooks when modal opens.
	useEffect( () => {
		if ( isOpen ) {
			setLoading( true );
			apiFetch( {
				path: '/newspack-nodes/v1/performance/registered-hooks',
			} )
				.then( ( data ) => {
					// API returns { hooks_by_category: { Category: [hook1, hook2, ...] } }
					setHookCategories( data.hooks_by_category || {} );
				} )
				.catch( () => setHookCategories( {} ) )
				.finally( () => setLoading( false ) );
		}
	}, [ isOpen ] );

	// Reset local state when modal opens.
	useEffect( () => {
		if ( isOpen ) {
			setLocalSelected( new Set( selected ) );
		}
	}, [ isOpen, selected ] );

	// Filter hooks by search.
	const filteredCategories = useMemo( () => {
		const searchLower = search.toLowerCase();
		const result = {};

		Object.entries( hookCategories ).forEach( ( [ category, hooks ] ) => {
			if ( ! Array.isArray( hooks ) ) {
				return;
			}
			const filtered = hooks.filter( ( hook ) =>
				hook.toLowerCase().includes( searchLower )
			);
			if ( filtered.length > 0 ) {
				result[ category ] = filtered;
			}
		} );

		return result;
	}, [ search, hookCategories ] );

	// Count selected and recommended in each category.
	const categoryCounts = useMemo( () => {
		const counts = {};
		Object.entries( hookCategories ).forEach( ( [ category, hooks ] ) => {
			if ( ! Array.isArray( hooks ) ) {
				return;
			}
			const selectedInCategory = hooks.filter( ( h ) =>
				localSelected.has( h )
			).length;
			const recommendedInCategory = hooks.filter( ( h ) =>
				RECOMMENDED_HOOKS.has( h )
			).length;
			counts[ category ] = {
				selected: selectedInCategory,
				total: hooks.length,
				recommended: recommendedInCategory,
			};
		} );
		return counts;
	}, [ localSelected, hookCategories ] );

	/**
	 * Toggle a hook selection.
	 *
	 * @param {string} hook Hook name.
	 */
	const toggleHook = ( hook ) => {
		const newSelected = new Set( localSelected );
		if ( newSelected.has( hook ) ) {
			newSelected.delete( hook );
		} else {
			newSelected.add( hook );
		}
		setLocalSelected( newSelected );
	};

	/**
	 * Toggle all hooks in a category.
	 *
	 * @param {string} category Category name.
	 */
	const toggleCategory = ( category ) => {
		const hooks = hookCategories[ category ] || [];
		const allSelected = hooks.every( ( h ) => localSelected.has( h ) );
		const newSelected = new Set( localSelected );

		if ( allSelected ) {
			hooks.forEach( ( h ) => newSelected.delete( h ) );
		} else {
			hooks.forEach( ( h ) => newSelected.add( h ) );
		}
		setLocalSelected( newSelected );
	};

	/**
	 * Toggle category expansion.
	 *
	 * @param {string} category Category name.
	 */
	const toggleExpanded = ( category ) => {
		const newExpanded = new Set( expandedCategories );
		if ( newExpanded.has( category ) ) {
			newExpanded.delete( category );
		} else {
			newExpanded.add( category );
		}
		setExpandedCategories( newExpanded );
	};

	/**
	 * Apply selection and close.
	 */
	const handleApply = () => {
		onSelect( Array.from( localSelected ) );
		onClose();
	};

	/**
	 * Get all hooks currently visible (respects search filter).
	 *
	 * @return {Array} Array of visible hook names.
	 */
	const getVisibleHooks = () => {
		const visible = [];
		Object.values( filteredCategories ).forEach( ( hooks ) => {
			if ( Array.isArray( hooks ) ) {
				hooks.forEach( ( h ) => visible.push( h ) );
			}
		} );
		return visible;
	};

	/**
	 * Select all visible hooks (adds to existing selection).
	 */
	const selectAll = () => {
		const newSelected = new Set( localSelected );
		getVisibleHooks().forEach( ( h ) => newSelected.add( h ) );
		setLocalSelected( newSelected );
	};

	/**
	 * Select recommended hooks (from config file).
	 */
	const selectRecommended = () => {
		setLocalSelected( new Set( RECOMMENDED_HOOKS ) );
	};

	/**
	 * Clear visible hooks from selection.
	 */
	const clearAll = () => {
		const newSelected = new Set( localSelected );
		getVisibleHooks().forEach( ( h ) => newSelected.delete( h ) );
		setLocalSelected( newSelected );
	};

	if ( ! isOpen ) {
		return null;
	}

	const totalSelected = localSelected.size;
	const visibleHooks = getVisibleHooks();
	const totalVisible = visibleHooks.length;
	const isFiltered = search.length > 0;
	const categoryOrder = Object.keys( filteredCategories ).sort();

	return (
		<Modal
			title={
				mode === 'exclude'
					? 'Select Hooks to Skip'
					: 'Select Hooks to Log'
			}
			onRequestClose={ onClose }
			className="event-logger-hook-selector-modal"
			style={ { width: '800px', maxWidth: '90vw' } }
		>
			<div className="hook-selector-header">
				<SearchControl
					value={ search }
					onChange={ setSearch }
					placeholder="Search hooks..."
				/>
				<div className="hook-selector-actions">
					<Button variant="tertiary" onClick={ selectAll }>
						{ isFiltered
							? `Select Matches (${ totalVisible })`
							: 'Select All' }
					</Button>
					<Button variant="tertiary" onClick={ selectRecommended }>
						Recommended
					</Button>
					<Button variant="tertiary" onClick={ clearAll }>
						{ isFiltered ? 'Clear Matches' : 'Clear All' }
					</Button>
					<Button variant="primary" onClick={ handleApply }>
						Apply ({ totalSelected })
					</Button>
				</div>
			</div>

			<div className="hook-selector-categories">
				{ loading && (
					<div
						style={ {
							padding: '12px 16px',
							display: 'flex',
							alignItems: 'center',
							gap: '8px',
							color: '#757575',
						} }
					>
						<Spinner />
						<span>Loading registered hooks...</span>
					</div>
				) }
				{ categoryOrder
					.filter( ( cat ) => filteredCategories[ cat ] )
					.map( ( category ) => {
						const hooks = filteredCategories[ category ];
						const meta = CATEGORY_META[ category ] || {};
						const counts = categoryCounts[ category ] || {
							selected: 0,
							total: 0,
						};
						const isExpanded = expandedCategories.has( category );
						const allSelected =
							counts.selected === counts.total &&
							counts.total > 0;
						const someSelected =
							counts.selected > 0 &&
							counts.selected < counts.total;

						return (
							<div
								key={ category }
								className="hook-selector-category"
							>
								<div
									className="hook-selector-category-header"
									role="button"
									tabIndex={ 0 }
									onClick={ () => toggleExpanded( category ) }
									onKeyDown={ ( e ) => {
										if (
											e.key === 'Enter' ||
											e.key === ' '
										) {
											e.preventDefault();
											toggleExpanded( category );
										}
									} }
								>
									<span className="hook-selector-expand">
										{ isExpanded ? '▼' : '▶' }
									</span>
									<CheckboxControl
										checked={ allSelected }
										indeterminate={ someSelected }
										onChange={ () =>
											toggleCategory( category )
										}
										onClick={ ( e ) => e.stopPropagation() }
									/>
									<span className="hook-selector-category-name">
										{ category }
										{ counts.recommended > 0 && (
											<span className="hook-selector-recommended">
												★{ counts.recommended }
											</span>
										) }
									</span>
									<span className="hook-selector-category-desc">
										{ meta.description }
									</span>
									<span className="hook-selector-category-count">
										{ counts.selected }/{ counts.total }
									</span>
								</div>

								{ isExpanded && (
									<div className="hook-selector-hooks">
										{ hooks.map( ( hook ) => {
											const isRecommended =
												RECOMMENDED_HOOKS.has( hook );
											const hookId = `hook-${ hook.replace(
												/[^a-z0-9]/gi,
												'-'
											) }`;
											return (
												<label
													key={ hook }
													htmlFor={ hookId }
													className={ `hook-selector-hook${
														isRecommended
															? ' hook-selector-hook-recommended'
															: ''
													}` }
												>
													<input
														id={ hookId }
														type="checkbox"
														checked={ localSelected.has(
															hook
														) }
														onChange={ () =>
															toggleHook( hook )
														}
													/>
													<span className="hook-selector-hook-name">
														{ hook }
														{ isRecommended && (
															<span className="hook-selector-recommended-star">
																★
															</span>
														) }
													</span>
												</label>
											);
										} ) }
									</div>
								) }
							</div>
						);
					} ) }
			</div>
		</Modal>
	);
}
