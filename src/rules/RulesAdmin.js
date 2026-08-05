/**
 * RulesAdmin — the thin React view over the per-URL logging-ruleset editor node
 * graph. useRulesGraph owns the data + CRUD transport; this component reads the
 * model and renders a wp-list-table (Pattern, Action, Hooks count, Events count,
 * Auto-tune summary) with a "+ Add Rule" button and per-row Edit / Delete.
 *
 * Add / Edit open RuleEditModal (add = a blank log rule); Save → `upsert` (single
 * rule, so the editor never ships the whole list) and the table re-lists. Delete
 * opens a confirm dialog → `remove(id)`. Loading / error come from the model.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useRulesGraph } from './useRulesGraph';
import RuleEditModal from './RuleEditModal';
import { BLANK_RULE } from './constants';

// One-line auto-tune summary for the table cell (— when both are off).
function autoTuneSummary( rule ) {
	const parts = [];
	if ( rule.auto_disable_threshold ) {
		parts.push(
			sprintf(
				// translators: %d: auto-disable occurrence count.
				__( 'disable @%d', 'newspack-event-logger-nodes' ),
				rule.auto_disable_threshold
			)
		);
	}
	if ( rule.auto_protect_time_threshold ) {
		parts.push(
			sprintf(
				// translators: %s: auto-protect time threshold in ms.
				__( 'protect @%sms', 'newspack-event-logger-nodes' ),
				rule.auto_protect_time_threshold
			)
		);
	}
	return parts.length ? parts.join( ', ' ) : '—';
}

// Minimal confirm dialog for delete. The confirm button focuses on mount.
function ConfirmDeleteModal( { pattern, onCancel, onConfirm } ) {
	const confirmRef = useRef( null );
	useEffect( () => {
		confirmRef.current?.focus();
	}, [] );

	return (
		<div
			className="rules-admin__confirm-backdrop"
			role="presentation"
			onMouseDown={ ( e ) => {
				if ( e.target === e.currentTarget ) {
					onCancel();
				}
			} }
		>
			<div
				className="rules-admin__confirm newspack-nodes-modal"
				role="dialog"
				aria-modal="true"
				aria-label={ __(
					'Delete rule',
					'newspack-event-logger-nodes'
				) }
			>
				<p>
					{ sprintf(
						// translators: %s: the rule's URL pattern.
						__(
							'Are you sure you want to delete the rule for %s?',
							'newspack-event-logger-nodes'
						),
						pattern
					) }
				</p>
				<div className="rules-admin__confirm-actions">
					<button
						type="button"
						className="button"
						onClick={ onCancel }
					>
						{ __( 'Cancel', 'newspack-event-logger-nodes' ) }
					</button>
					<button
						ref={ confirmRef }
						type="button"
						className="button button-link-delete"
						onClick={ onConfirm }
					>
						{ __( 'Delete', 'newspack-event-logger-nodes' ) }
					</button>
				</div>
			</div>
		</div>
	);
}

// A single rule row — pattern / action / hooks count / auto-tune + Edit/Delete.
function RuleRow( { rule, onEdit, onDelete } ) {
	return (
		<tr data-rule-id={ rule.id }>
			<td>
				<code>{ rule.pattern }</code>
			</td>
			<td>
				<span
					className={ `rules-admin__badge newspack-nodes-badge rules-admin__badge--${ rule.action }` }
				>
					{ 'log' === rule.action
						? __( 'Log', 'newspack-event-logger-nodes' )
						: __( 'Skip', 'newspack-event-logger-nodes' ) }
				</span>
			</td>
			<td>
				{ 'log' === rule.action
					? sprintf(
							// translators: %d: number of hooks on the rule.
							_n(
								'%d hook',
								'%d hooks',
								( rule.hooks || [] ).length,
								'newspack-event-logger-nodes'
							),
							( rule.hooks || [] ).length
					  )
					: '—' }
			</td>
			<td>
				{ 'log' === rule.action
					? sprintf(
							// translators: %d: number of custom events on the rule.
							_n(
								'%d event',
								'%d events',
								( rule.custom_events || [] ).length,
								'newspack-event-logger-nodes'
							),
							( rule.custom_events || [] ).length
					  )
					: '—' }
			</td>
			<td>{ 'log' === rule.action ? autoTuneSummary( rule ) : '—' }</td>
			<td>
				<button
					type="button"
					className="button button-small"
					onClick={ () => onEdit( rule ) }
				>
					{ __( 'Edit', 'newspack-event-logger-nodes' ) }
				</button>{ ' ' }
				<button
					type="button"
					className="button button-small button-link-delete"
					onClick={ () => onDelete( rule ) }
				>
					{ __( 'Delete', 'newspack-event-logger-nodes' ) }
				</button>
			</td>
		</tr>
	);
}

/**
 * Takes no props: `useRulesGraph` mounts the graph and supplies every datum, so
 * the settings page mounts this bare.
 *
 * The table is sorted for SCANNING — log rules first, alphabetical within an
 * action — which is deliberately not the order `Rule_Matcher` evaluates in
 * (query-bearing patterns outrank exact ones, which outrank prefixes, and
 * length only breaks ties within a rank). Row order says nothing about which
 * rule wins a URL.
 *
 * @return {import('react').ReactElement} The rendered admin app.
 */
/**
 * A mutation rejection as a displayable string. The graph rejects with an
 * Error, but a TM_ERROR reply can surface as a bare string too.
 *
 * @param {*} e The rejection value.
 * @return {string} Message for the error banner.
 */
function messageOf( e ) {
	if ( e && 'string' === typeof e.message && '' !== e.message ) {
		return e.message;
	}
	const text = String( e ?? '' );
	return '' !== text
		? text
		: __( 'The change could not be saved.', 'newspack-event-logger-nodes' );
}

/**
 *
 */
export default function RulesAdmin() {
	const { rules, loading, error, upsert, remove } = useRulesGraph();

	// Rule open in the editor: null = closed, object = editing/adding.
	const [ editing, setEditing ] = useState( null );
	// The rule pending delete confirmation.
	const [ deleting, setDeleting ] = useState( null );
	// A mutation's failure; useRulesGraph rejects rather than filling `error`.
	const [ mutationError, setMutationError ] = useState( null );

	const handleSave = async ( draft ) => {
		setMutationError( null );
		try {
			await upsert( draft );
		} catch ( e ) {
			setMutationError( messageOf( e ) );
			return; // Keep the editor open, with the draft intact.
		}
		setEditing( null );
	};

	const confirmDelete = async () => {
		const target = deleting;
		if ( ! target ) {
			return;
		}
		setMutationError( null );
		try {
			await remove( target.id );
		} catch ( e ) {
			// Closing first left the row, a clean banner, and no other trace.
			setMutationError( messageOf( e ) );
			return;
		}
		setDeleting( null );
	};

	// Resolve the table body once (avoids a nested ternary in JSX).
	let tableBody;
	if ( loading ) {
		tableBody = (
			<tr>
				<td colSpan={ 6 }>
					{ __( 'Loading rules…', 'newspack-event-logger-nodes' ) }
				</td>
			</tr>
		);
	} else if ( rules.length > 0 ) {
		// Grouped for scanning: log rules first, alphabetical within action.
		const sorted = [ ...rules ].sort(
			( a, b ) =>
				( a.action || '' ).localeCompare( b.action || '' ) ||
				( a.pattern || '' ).localeCompare( b.pattern || '' )
		);
		tableBody = sorted.map( ( rule ) => (
			<RuleRow
				key={ rule.id }
				rule={ rule }
				onEdit={ setEditing }
				onDelete={ setDeleting }
			/>
		) );
	} else {
		tableBody = (
			<tr>
				<td colSpan={ 6 }>
					{ __(
						'No rules configured.',
						'newspack-event-logger-nodes'
					) }
				</td>
			</tr>
		);
	}

	return (
		<div className="rules-admin">
			{ ( error || mutationError ) && (
				<div className="notice notice-error">
					<p>{ error || mutationError }</p>
				</div>
			) }

			<p>
				<button
					type="button"
					className="rules-admin__add button button-primary"
					onClick={ () => setEditing( { ...BLANK_RULE } ) }
				>
					{ __( '+ Add Rule', 'newspack-event-logger-nodes' ) }
				</button>
			</p>

			<table className="wp-list-table fixed newspack-nodes-table newspack-nodes-table--undivided">
				<thead>
					<tr>
						<th style={ { width: '32%' } }>
							{ __( 'Pattern', 'newspack-event-logger-nodes' ) }
						</th>
						<th style={ { width: '10%' } }>
							{ __( 'Action', 'newspack-event-logger-nodes' ) }
						</th>
						<th style={ { width: '12%' } }>
							{ __( 'Hooks', 'newspack-event-logger-nodes' ) }
						</th>
						<th style={ { width: '12%' } }>
							{ __( 'Events', 'newspack-event-logger-nodes' ) }
						</th>
						<th style={ { width: '20%' } }>
							{ __( 'Auto-tune', 'newspack-event-logger-nodes' ) }
						</th>
						<th style={ { width: '14%' } }>
							{ __( 'Actions', 'newspack-event-logger-nodes' ) }
						</th>
					</tr>
				</thead>
				<tbody>{ tableBody }</tbody>
			</table>

			{ editing && (
				<RuleEditModal
					rule={ editing }
					onSave={ handleSave }
					onCancel={ () => setEditing( null ) }
				/>
			) }

			{ deleting && (
				<ConfirmDeleteModal
					pattern={ deleting.pattern }
					onCancel={ () => setDeleting( null ) }
					onConfirm={ confirmDelete }
				/>
			) }
		</div>
	);
}
