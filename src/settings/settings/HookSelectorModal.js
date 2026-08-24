/**
 * Hook Selector Modal Component
 *
 * A modal for browsing and selecting WordPress hooks organized by category.
 * Fetches registered hooks from $wp_filter via REST API.
 */

import { useState, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { CheckboxControl, Spinner } from '@wordpress/components';

import { useHookCatalogGraph } from '../hooks/useHookCatalogGraph';
import '../styles/hook-selector.scss';
import { useMultiSelect } from '../../hooks/useMultiSelect';
import SelectorModal from './SelectorModal';

/**
 * Recommended hooks from config file (source of truth).
 */
const RECOMMENDED_HOOKS = new Set( window.newspackNodesRecommendedHooks || [] );

/**
 * Hook Selector Modal component.
 *
 * @param {Object}                 props             Component props.
 * @param {boolean}                props.isOpen      Whether the modal is open.
 * @param {() => void}             props.onClose     Close callback.
 * @param {Array}                  props.selected    Currently selected hooks.
 * @param {(hooks: Array) => void} props.onSelect    Called on Apply with the selected hook names.
 * @param {string}                 props.mode        'include' or 'exclude' mode.
 * @param {string}                 [props.className] Extra classes for the modal frame (skin theming).
 * @return {import('react').ReactElement|null} Rendered component.
 */
export default function HookSelectorModal( {
	isOpen,
	onClose,
	selected = [],
	onSelect,
	mode = 'exclude',
	className = '',
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ expandedCategories, setExpandedCategories ] = useState( new Set() );
	const chosen = useMultiSelect( { selected, isOpen } );

	// The hook-catalog fetch runs in a JS-node graph; hook fires it on open.
	const {
		hooksByCategory,
		descriptions = {},
		loading,
	} = useHookCatalogGraph( { isOpen } );

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
				chosen.has( h )
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
	}, [ chosen, hooksByCategory ] );

	/**
	 * Toggle every hook in a category, on unless they are already all on.
	 *
	 * @param {string} category Category name.
	 */
	const toggleCategory = ( category ) => {
		const hooks = hooksByCategory[ category ] || [];
		if ( hooks.every( ( h ) => chosen.has( h ) ) ) {
			chosen.removeAll( hooks );
		} else {
			chosen.addAll( hooks );
		}
	};

	/**
	 * Toggle category expansion.
	 *
	 * @param {string} category Category name.
	 */
	const toggleExpanded = ( category ) => {
		const newExpanded = new Set( expandedCategories );
		if ( ! newExpanded.delete( category ) ) {
			newExpanded.add( category );
		}
		setExpandedCategories( newExpanded );
	};

	/**
	 * Every hook the search filter currently leaves visible.
	 *
	 * @return {Array} Visible hook names.
	 */
	const getVisibleHooks = () =>
		Object.values( filteredCategories ).filter( Array.isArray ).flat();

	if ( ! isOpen ) {
		return null;
	}

	const totalSelected = chosen.count;
	const visibleHooks = getVisibleHooks();
	const totalVisible = visibleHooks.length;
	const isFiltered = search.length > 0;
	const categoryOrder = Object.keys( filteredCategories ).sort();

	return (
		<SelectorModal
			title={
				mode === 'exclude'
					? __(
							'Select Hooks to Skip',
							'newspack-event-logger-nodes'
					  )
					: __( 'Select Hooks to Log', 'newspack-event-logger-nodes' )
			}
			className={ `event-logger-hook-selector-modal ${ className }`.trim() }
			onClose={ onClose }
			search={ search }
			onSearch={ setSearch }
			placeholder={ __( 'Search hooks…', 'newspack-event-logger-nodes' ) }
			actions={
				<>
					<button
						type="button"
						className="button"
						onClick={ () => chosen.addAll( getVisibleHooks() ) }
					>
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
					</button>
					<button
						type="button"
						className="button"
						onClick={ () =>
							chosen.replaceWith( RECOMMENDED_HOOKS )
						}
					>
						{ __( 'Recommended', 'newspack-event-logger-nodes' ) }
					</button>
					<button
						type="button"
						className="button"
						onClick={ () => chosen.removeAll( getVisibleHooks() ) }
					>
						{ isFiltered
							? __(
									'Clear Matches',
									'newspack-event-logger-nodes'
							  )
							: __( 'Clear All', 'newspack-event-logger-nodes' ) }
					</button>
					<button
						type="button"
						className="button button-primary"
						onClick={ () => chosen.apply( onSelect, onClose ) }
					>
						{ sprintf(
							// translators: %d: number of currently selected hooks.
							__( 'Apply (%d)', 'newspack-event-logger-nodes' ),
							totalSelected
						) }
					</button>
				</>
			}
		>
			{ loading && (
				<div
					className="hook-selector-loading newspack-nodes-status"
					style={ {
						padding: '12px 16px',
						display: 'flex',
						alignItems: 'center',
						gap: '8px',
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
					const description = descriptions[ category ] || '';
					const counts = categoryCounts[ category ] || {
						selected: 0,
						total: 0,
					};
					const isExpanded = expandedCategories.has( category );
					const allSelected =
						counts.selected === counts.total && counts.total > 0;
					const someSelected =
						counts.selected > 0 && counts.selected < counts.total;

					return (
						<div
							key={ category }
							className="hook-selector-category"
						>
							<div
								className={ `hook-selector-category-header newspack-nodes-interactive-row${
									allSelected ? ' is-selected' : ''
								}` }
								role="button"
								tabIndex={ 0 }
								onClick={ () => toggleExpanded( category ) }
								onKeyDown={ ( e ) => {
									if ( e.key === 'Enter' || e.key === ' ' ) {
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
									{ description }
								</span>
								<span className="hook-selector-category-count newspack-nodes-badge">
									{ counts.selected }/{ counts.total }
								</span>
							</div>

							{ isExpanded && (
								<div className="hook-selector-hooks">
									{ hooks.map( ( hook ) => {
										const isRecommended =
											RECOMMENDED_HOOKS.has( hook );
										const isSelected = chosen.has( hook );
										const hookId = `hook-${ hook.replace(
											/[^a-z0-9]/gi,
											'-'
										) }`;
										return (
											<label
												key={ hook }
												htmlFor={ hookId }
												className={ `hook-selector-hook newspack-nodes-interactive-row${
													isSelected
														? ' is-selected'
														: ''
												}${
													isRecommended
														? ' hook-selector-hook-recommended'
														: ''
												}` }
											>
												<CheckboxControl
													id={ hookId }
													__nextHasNoMarginBottom
													checked={ chosen.has(
														hook
													) }
													onChange={ () =>
														chosen.toggle( hook )
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
		</SelectorModal>
	);
}
