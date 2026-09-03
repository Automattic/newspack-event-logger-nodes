/**
 * The editor for ONE logging rule.
 *
 * Every rule an operator writes comes through here. The settings page's ruleset
 * table and the performance dashboard's "Log this URL" each open it on the
 * stored rule when the pattern already has one, and on a `BLANK_RULE` draft
 * when it does not — seeded with that URL's pattern, in the dashboard's case.
 * The modal owns the draft and nothing else: `onSave( draft )` hands the
 * assembled rule back in `Rule::to_array()`'s wire shape and the parent writes
 * it through the `rules` CI's `upsert`, so one editor serves both trees while
 * owning neither's transport.
 *
 * Pattern and action apply to every rule; the rest belong to `log` rules, which
 * is why a `skip` rule hides them AND saves them empty. Emitting the state as it
 * stands would keep a rule's hooks and thresholds alive under an action that
 * logs nothing.
 *
 * The draft's id round-trips untouched. `Rules_CI_Node` mints a rule's id from
 * its pattern, so an edit that moves the pattern arrives carrying the OLD id,
 * which is the only thing telling `upsert` which entry this edit replaces.
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
 * `.newspack-nodes-skin-root` — flex column, 600px wide, scrolling content — so
 * that class is structure, not decoration a caller opts into. Leaving it to the
 * optional `className` gives the same modal two layouts, one per tree that
 * mounts it.
 */
const SKIN_CLASSES =
	'event-logger-rule-edit-modal newspack-nodes-modal newspack-nodes-theme newspack-nodes-ui newspack-nodes-skin-root';
import CustomEventSelectorModal from '../settings/settings/CustomEventSelectorModal';
import TagInputField from '../settings/settings/TagInputField';

import './rule-edit-modal.scss';

/**
 * Parse one numeric field, reading a blank or unparseable value as 0.
 *
 * The three number inputs hold their value as a string, so parsing waits for
 * save: round-tripping each keystroke through `Number` swallows a half-typed
 * `250.` and takes the caret with it. Zero is what every numeric rule field
 * reads as off, so a cleared field turns its feature off rather than refusing
 * the save.
 *
 * @param {string}                  value Raw field value.
 * @param {(raw: string) => number} parse `parseInt` or `parseFloat`, per field.
 * @return {number} The parsed value, or 0 when the parse is not finite.
 */
function toNumber( value, parse ) {
	const n = parse( value );
	return Number.isFinite( n ) ? n : 0;
}

/**
 * Rule edit modal.
 *
 * Delete is the owner's call. RulesAdmin deletes from its table rows and passes
 * no handler, so the modal shows no delete button; the performance dashboard
 * passes one only once the draft has an id. The button that appears arms a
 * confirm on its first click rather than stacking a second modal over this one.
 *
 * @param {Object}                  props             Component props.
 * @param {Object}                  props.rule        The rule draft to edit, in `Rule::to_array()`'s wire shape; an empty `id` titles the modal "Add rule".
 * @param {(draft: Object) => void} props.onSave      Called with the assembled draft once the pattern validates.
 * @param {() => void}              props.onCancel    Dismiss handler (Cancel / ESC / backdrop).
 * @param {(() => void)|undefined}  [props.onDelete]  Delete handler; omitting it hides the delete button.
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
	// Absent means ON: only an explicit false retires the HTTP spans.
	const [ logHttp, setLogHttp ] = useState( rule?.log_http ?? true );
	const [ traceHooks, setTraceHooks ] = useState( !! rule?.trace_hooks );
	const [ traceCallers, setTraceCallers ] = useState(
		String( rule?.trace_callers ?? 0 )
	);
	const [ error, setError ] = useState( '' );
	const [ isHooksOpen, setIsHooksOpen ] = useState( false );
	const [ isCustomOpen, setIsCustomOpen ] = useState( false );
	const [ confirmDelete, setConfirmDelete ] = useState( false );

	const isLog = 'log' === action;

	/**
	 * Assemble the draft and hand it to `onSave`, refusing an empty pattern.
	 *
	 * The pattern is trimmed before it is checked and before it is emitted, so
	 * surrounding whitespace never reaches `Rules_CI_Node`, which mints the id
	 * from the pattern text and would key the rule to a string no URL matches.
	 */
	const handleSave = () => {
		const trimmed = pattern.trim();
		if ( ! trimmed ) {
			setError(
				__( 'Pattern is required.', 'newspack-event-logger-nodes' )
			);
			return;
		}
		// A skip rule carries no payload, so emit the log-only fields empty.
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
			// The editor holds resolved hooks; Rule_Set re-tiers on save.
			hooks_in: 'inline',
			log_queries: isLog && logQueries,
			log_http: isLog && !! logHttp,
			trace_hooks: isLog && traceHooks,
			// The count refines the caller label, so unticking retires both.
			trace_callers:
				isLog && traceHooks
					? toNumber( traceCallers, ( v ) => parseInt( v, 10 ) )
					: 0,
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
							name="rule-log-http"
							label={ __(
								'Log HTTP requests',
								'newspack-event-logger-nodes'
							) }
							help={ __(
								'Times every outbound HTTP request as its own flame span. Two log entries per request, so a request that calls many APIs gets a little slower.',
								'newspack-event-logger-nodes'
							) }
							checked={ !! logHttp }
							onChange={ setLogHttp }
						/>

						<div className="rule-edit-trace-row">
							<CheckboxControl
								__nextHasNoMarginBottom
								name="rule-trace-hooks"
								label={ __(
									'Trace hook callers',
									'newspack-event-logger-nodes'
								) }
								help={
									traceHooks
										? __(
												'Labels every span with who called it, so a hook that runs many times splits by caller in the flame graph. The count is how many firings of each hook also record a full backtrace — expensive; 0 = labels only.',
												'newspack-event-logger-nodes'
										  )
										: __(
												'Labels every span with who called it, so a hook that runs many times splits by caller in the flame graph.',
												'newspack-event-logger-nodes'
										  )
								}
								checked={ traceHooks }
								onChange={ setTraceHooks }
							/>

							{ traceHooks && (
								<div className="rule-edit-trace-count">
									<TextControl
										__next40pxDefaultSize
										__nextHasNoMarginBottom
										hideLabelFromVision
										type="number"
										label={ __(
											'Backtraces per hook',
											'newspack-event-logger-nodes'
										) }
										name="rule-trace-callers"
										value={ traceCallers }
										onChange={ setTraceCallers }
									/>
									<span className="newspack-nodes-status is-muted">
										{ __(
											'backtraces per hook',
											'newspack-event-logger-nodes'
										) }
									</span>
								</div>
							) }
						</div>
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
