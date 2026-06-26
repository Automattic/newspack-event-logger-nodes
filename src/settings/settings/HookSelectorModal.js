/**
 * Hook Selector Modal Component
 *
 * A modal for browsing and selecting WordPress hooks organized by category.
 * Fetches registered hooks from $wp_filter via REST API.
 */

import { useState, useMemo, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Modal,
	Button,
	SearchControl,
	CheckboxControl,
	Spinner,
} from '@wordpress/components';

import { useHookCatalogGraph } from '../hooks/useHookCatalogGraph';
import '../styles/hook-selector.scss';

/**
 * Recommended hooks from config file (source of truth).
 */
const RECOMMENDED_HOOKS = new Set( window.newspackNodesRecommendedHooks || [] );

/**
 * Category metadata with descriptions.
 */
const CATEGORY_META = {
	Lifecycle: {
		description: __(
			'Core request lifecycle',
			'newspack-event-logger-nodes'
		),
	},
	'Content Rendering': {
		description: __(
			'Block & content output',
			'newspack-event-logger-nodes'
		),
	},
	'Query & Posts': {
		description: __(
			'Database queries & post ops',
			'newspack-event-logger-nodes'
		),
	},
	'Taxonomies & Terms': {
		description: __( 'Categories & tags', 'newspack-event-logger-nodes' ),
	},
	'Users & Auth': {
		description: __(
			'Authentication & caps',
			'newspack-event-logger-nodes'
		),
	},
	'Options & Settings': {
		description: __(
			'Options API (high volume)',
			'newspack-event-logger-nodes'
		),
	},
	'REST API': {
		description: __( 'REST endpoints', 'newspack-event-logger-nodes' ),
	},
	Admin: {
		description: __(
			'Admin screens & updates',
			'newspack-event-logger-nodes'
		),
	},
	'Scripts & Styles': {
		description: __( 'Asset loading', 'newspack-event-logger-nodes' ),
	},
	Media: {
		description: __(
			'Uploads & attachments',
			'newspack-event-logger-nodes'
		),
	},
	Comments: {
		description: __(
			'Comments & pingbacks',
			'newspack-event-logger-nodes'
		),
	},
	URLs: {
		description: __(
			'URL generation & links',
			'newspack-event-logger-nodes'
		),
	},
	Cron: {
		description: __(
			'Scheduled & future posts',
			'newspack-event-logger-nodes'
		),
	},
	Widgets: {
		description: __( 'Widget areas', 'newspack-event-logger-nodes' ),
	},
	Theme: {
		description: __( 'Theme & customizer', 'newspack-event-logger-nodes' ),
	},
	Localization: {
		description: __(
			'Translations (noisy)',
			'newspack-event-logger-nodes'
		),
	},
	Sanitization: {
		description: __(
			'Input escaping (noisy)',
			'newspack-event-logger-nodes'
		),
	},
	HTTP: {
		description: __(
			'Remote requests & mail',
			'newspack-event-logger-nodes'
		),
	},
	AJAX: {
		description: __( 'AJAX & heartbeat', 'newspack-event-logger-nodes' ),
	},
	'Block Editor': {
		description: __( 'Blocks & TinyMCE', 'newspack-event-logger-nodes' ),
	},
	Metadata: {
		description: __( 'Meta operations', 'newspack-event-logger-nodes' ),
	},
	Feeds: {
		description: __( 'RSS, Atom, RDF', 'newspack-event-logger-nodes' ),
	},
	Menus: {
		description: __( 'Navigation menus', 'newspack-event-logger-nodes' ),
	},
	Other: {
		description: __( 'Uncategorized hooks', 'newspack-event-logger-nodes' ),
	},
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
 * @return {import('react').ReactElement|null} Rendered component.
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

	// The hook-catalog fetch lives in a JS-node graph; the hook fires it on open
	// and returns the render model (production uses the default command client).
	const { hooksByCategory, loading } = useHookCatalogGraph( { isOpen } );

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

		Object.entries( hooksByCategory ).forEach( ( [ category, hooks ] ) => {
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
	}, [ search, hooksByCategory ] );

	// Count selected and recommended in each category.
	const categoryCounts = useMemo( () => {
		const counts = {};
		Object.entries( hooksByCategory ).forEach( ( [ category, hooks ] ) => {
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
	}, [ localSelected, hooksByCategory ] );

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
		const hooks = hooksByCategory[ category ] || [];
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
					? __(
							'Select Hooks to Skip',
							'newspack-event-logger-nodes'
					  )
					: __( 'Select Hooks to Log', 'newspack-event-logger-nodes' )
			}
			onRequestClose={ onClose }
			className="event-logger-hook-selector-modal newspack-nodes-theme"
			style={ { width: '800px', maxWidth: '90vw' } }
		>
			<div className="hook-selector-header">
				<SearchControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					value={ search }
					onChange={ setSearch }
					placeholder={ __(
						'Search hooks…',
						'newspack-event-logger-nodes'
					) }
				/>
				<div className="hook-selector-actions">
					<Button variant="tertiary" onClick={ selectAll }>
						{ isFiltered
							? sprintf(
									// translators: %d: number of hooks matching the current search filter.
									__(
										'Select Matches (%d)',
										'newspack-event-logger-nodes'
									),
									totalVisible
							  )
							: __(
									'Select All',
									'newspack-event-logger-nodes'
							  ) }
					</Button>
					<Button variant="tertiary" onClick={ selectRecommended }>
						{ __( 'Recommended', 'newspack-event-logger-nodes' ) }
					</Button>
					<Button variant="tertiary" onClick={ clearAll }>
						{ isFiltered
							? __(
									'Clear Matches',
									'newspack-event-logger-nodes'
							  )
							: __( 'Clear All', 'newspack-event-logger-nodes' ) }
					</Button>
					<Button variant="primary" onClick={ handleApply }>
						{ sprintf(
							// translators: %d: number of currently selected hooks.
							__( 'Apply (%d)', 'newspack-event-logger-nodes' ),
							totalSelected
						) }
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
						<span>
							{ __(
								'Loading registered hooks…',
								'newspack-event-logger-nodes'
							) }
						</span>
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
										__nextHasNoMarginBottom
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
