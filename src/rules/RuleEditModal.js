/**
 * RuleEditModal — edits ONE logging-rule draft in a @wordpress/components Modal.
 *
 * Fields: pattern (TextControl), action (log/skip SelectControl), and — only for
 * `log` rules — a Hooks field (opens the reused HookSelectorModal, shows a
 * count), a Custom Events field (opens the reused CustomEventSelectorModal), a
 * Significant Events tag-pill input (TagInputField), and two number inputs
 * (auto_disable_threshold int, auto_protect_time_threshold float ms). Skip rules
 * hide the log-only fields entirely.
 *
 * `onSave(draft)` emits the assembled rule object (the id round-trips so the
 * parent's upsert can preserve it); the parent decides upsert vs save. Pattern
 * must be non-empty — an empty pattern blocks save with an inline message.
 */

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	BaseControl,
	Modal,
	TextControl,
	SelectControl,
	CheckboxControl,
} from '@wordpress/components';

import HookSelectorModal from '../settings/settings/HookSelectorModal';

/**
 * Every skin class this modal's own stylesheet needs.
 *
 * `rule-edit-modal.scss` gates a whole layout block on
 * `.newspack-nodes-skin-root` — flex column, 600px wide, scrolling content —
 * so it is not decoration a caller opts into. Leaving that one class to an
 * optional `className` gave the same modal two layouts: the Performance
 * dashboard passed it, the rules admin did not.
 */
const SKIN_CLASSES =
	'event-logger-rule-edit-modal newspack-nodes-modal newspack-nodes-theme newspack-nodes-ui newspack-nodes-skin-root';
import CustomEventSelectorModal from '../settings/settings/CustomEventSelectorModal';
import TagInputField from '../settings/settings/TagInputField';

import './rule-edit-modal.scss';

// Number input via parse (blank→0), held as a string so the caret is stable.
function toNumber( value, parse ) {
	const n = parse( value );
	return Number.isFinite( n ) ? n : 0;
}

/**
 * Rule edit modal.
 *
 * Delete is the owner's call: RulesAdmin deletes from its table rows and passes
 * no handler, so the modal shows no delete button; the performance dashboard
 * passes one only once the draft has an id.
 *
 * @param {Object}                  props             Component props.
 * @param {Object}                  props.rule        The rule draft to edit (wire shape from Rules_CI).
 * @param {(draft: Object) => void} props.onSave      Called with the edited draft on Save.
 * @param {() => void}              props.onCancel    Dismiss handler (Cancel / ESC / backdrop).
 * @param {(() => void)|undefined}  [props.onDelete]  Delete handler; omitted hides the delete button.
 * @param {string}                  [props.className] EXTRA class names; the skin classes are the modal's own.
 * @return {import('react').ReactElement} The modal.
 */
export default function RuleEditModal( {
	rule,
	onSave,
	onCancel,
	onDelete,
	className = '',
} ) {
	const [ pattern, setPattern ] = useState( rule?.pattern ?? '' );
	const [ action, setAction ] = useState(
		/** @type {'log'|'skip'} */ ( 'skip' === rule?.action ? 'skip' : 'log' )
	);
	const [ hooks, setHooks ] = useState(
		Array.isArray( rule?.hooks ) ? rule.hooks : []
	);
	const [ customEvents, setCustomEvents ] = useState(
		Array.isArray( rule?.custom_events ) ? rule.custom_events : []
	);
	const [ significant, setSignificant ] = useState(
		Array.isArray( rule?.significant_events ) ? rule.significant_events : []
	);
	const [ autoDisable, setAutoDisable ] = useState(
		String( rule?.auto_disable_threshold ?? 0 )
	);
	const [ autoProtect, setAutoProtect ] = useState(
		String( rule?.auto_protect_time_threshold ?? 0 )
	);
	const [ logQueries, setLogQueries ] = useState( !! rule?.log_queries );
	const [ traceHooks, setTraceHooks ] = useState( !! rule?.trace_hooks );
	const [ error, setError ] = useState( '' );
	const [ isHooksOpen, setIsHooksOpen ] = useState( false );
	const [ isCustomOpen, setIsCustomOpen ] = useState( false );
	const [ confirmDelete, setConfirmDelete ] = useState( false );

	const isLog = 'log' === action;

	const handleSave = () => {
		const trimmed = pattern.trim();
		if ( ! trimmed ) {
			setError(
				__( 'Pattern is required.', 'newspack-event-logger-nodes' )
			);
			return;
		}
		// Skip rules carry no payload; emit empty fields, no stale hooks leak.
		const draft = {
			id: rule?.id ?? '',
			pattern: trimmed,
			action,
			auto_disable_threshold: isLog
				? toNumber( autoDisable, ( v ) => parseInt( v, 10 ) )
				: 0,
			auto_protect_time_threshold: isLog
				? toNumber( autoProtect, parseFloat )
				: 0,
			significant_events: isLog ? significant : [],
			custom_events: isLog ? customEvents : [],
			hooks: isLog ? hooks : [],
			hooks_in: 'inline',
			log_queries: isLog && logQueries,
			trace_hooks: isLog && traceHooks,
			// The deep budget is API-set; an edit must not zero a tuned run.
			trace_callers: isLog ? Number( rule?.trace_callers ?? 0 ) : 0,
		};
		onSave( draft );
	};

	return (
		<Modal
			title={
				rule?.id
					? __( 'Edit rule', 'newspack-event-logger-nodes' )
					: __( 'Add rule', 'newspack-event-logger-nodes' )
			}
			onRequestClose={ onCancel }
			className={ `${ SKIN_CLASSES } ${ className }`.trim() }
		>
			<div className="rule-edit-body">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'URL pattern', 'newspack-event-logger-nodes' ) }
					help={ __(
						'A prefix like /blog, or an exact match ending in ? (e.g. /about?).',
						'newspack-event-logger-nodes'
					) }
					name="rule-pattern"
					value={ pattern }
					onChange={ setPattern }
				/>

				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Action', 'newspack-event-logger-nodes' ) }
					name="rule-action"
					value={ action }
					options={ [
						{
							label: __( 'Log', 'newspack-event-logger-nodes' ),
							value: 'log',
						},
						{
							label: __( 'Skip', 'newspack-event-logger-nodes' ),
							value: 'skip',
						},
					] }
					onChange={ setAction }
				/>

				{ isLog && (
					<>
						<div className="rule-edit-hooks-field">
							<BaseControl.VisualLabel className="rule-edit-field-label">
								{ __( 'Hooks', 'newspack-event-logger-nodes' ) }
							</BaseControl.VisualLabel>
							<button
								type="button"
								className="button"
								onClick={ () => setIsHooksOpen( true ) }
							>
								{ __(
									'Select Hooks',
									'newspack-event-logger-nodes'
								) }
							</button>
							<span className="rule-edit-field-count newspack-nodes-status">
								{ sprintf(
									// translators: %d: number of selected hooks.
									_n(
										'%d hook',
										'%d hooks',
										hooks.length,
										'newspack-event-logger-nodes'
									),
									hooks.length
								) }
							</span>
						</div>

						<div className="rule-edit-custom-field">
							<BaseControl.VisualLabel className="rule-edit-field-label">
								{ __(
									'Custom events',
									'newspack-event-logger-nodes'
								) }
							</BaseControl.VisualLabel>
							<button
								type="button"
								className="button"
								onClick={ () => setIsCustomOpen( true ) }
							>
								{ __(
									'Select Events',
									'newspack-event-logger-nodes'
								) }
							</button>
							<span className="rule-edit-field-count newspack-nodes-status">
								{ sprintf(
									// translators: %d: number of selected custom events.
									_n(
										'%d event',
										'%d events',
										customEvents.length,
										'newspack-event-logger-nodes'
									),
									customEvents.length
								) }
							</span>
						</div>

						<div className="rule-edit-tag-field components-base-control">
							<BaseControl.VisualLabel className="rule-edit-field-label">
								{ __(
									'Significant events',
									'newspack-event-logger-nodes'
								) }
							</BaseControl.VisualLabel>
							<TagInputField
								initialValues={ significant }
								onChange={ setSignificant }
								horizontal
							/>
							<p className="components-base-control__help">
								{ __(
									'Events/hooks protected from auto-disable.',
									'newspack-event-logger-nodes'
								) }
							</p>
						</div>

						<div className="rule-edit-threshold-row">
							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								type="number"
								label={ __(
									'Auto-disable threshold',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									'Occurrence count before an event auto-disables. 0 = off.',
									'newspack-event-logger-nodes'
								) }
								name="rule-auto-disable-threshold"
								value={ autoDisable }
								onChange={ setAutoDisable }
							/>

							<TextControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								type="number"
								label={ __(
									'Auto-protect time threshold (ms)',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									'Duration in ms above which a slow event is protected. 0 = off.',
									'newspack-event-logger-nodes'
								) }
								name="rule-auto-protect-time-threshold"
								value={ autoProtect }
								onChange={ setAutoProtect }
							/>
						</div>

						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Log database queries',
								'newspack-event-logger-nodes'
							) }
							help={ __(
								'Times every query as its own flame span. Two log entries per query, so a query-heavy request gets much slower.',
								'newspack-event-logger-nodes'
							) }
							checked={ logQueries }
							onChange={ setLogQueries }
						/>

						<CheckboxControl
							__nextHasNoMarginBottom
							label={ __(
								'Trace hook callers',
								'newspack-event-logger-nodes'
							) }
							help={ __(
								'Labels every span with who called it, so a hook that runs many times splits by caller in the flame graph.',
								'newspack-event-logger-nodes'
							) }
							checked={ traceHooks }
							onChange={ setTraceHooks }
						/>
					</>
				) }

				{ error && (
					<p className="rule-edit-error newspack-nodes-error-banner">
						{ error }
					</p>
				) }
			</div>

			<div className="rule-edit-actions">
				{ onDelete && (
					<button
						type="button"
						className="button button-link-delete rule-edit-actions__delete"
						onClick={ () =>
							confirmDelete
								? onDelete()
								: setConfirmDelete( true )
						}
					>
						{ confirmDelete
							? __(
									'Confirm delete',
									'newspack-event-logger-nodes'
							  )
							: __(
									'Delete rule',
									'newspack-event-logger-nodes'
							  ) }
					</button>
				) }
				<button type="button" className="button" onClick={ onCancel }>
					{ __( 'Cancel', 'newspack-event-logger-nodes' ) }
				</button>
				<button
					type="button"
					className="button button-primary"
					onClick={ handleSave }
				>
					{ __( 'Save rule', 'newspack-event-logger-nodes' ) }
				</button>
			</div>

			<HookSelectorModal
				isOpen={ isHooksOpen }
				onClose={ () => setIsHooksOpen( false ) }
				selected={ hooks }
				onSelect={ setHooks }
				mode="include"
				className={ `newspack-nodes-skin-root ${ className }`.trim() }
			/>
			<CustomEventSelectorModal
				isOpen={ isCustomOpen }
				onClose={ () => setIsCustomOpen( false ) }
				selected={ customEvents }
				onSelect={ setCustomEvents }
				className={ `newspack-nodes-skin-root ${ className }`.trim() }
			/>
		</Modal>
	);
}
