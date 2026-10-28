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
 * Its fields and actions are one form, so Enter in the pattern or a threshold
 * saves, as Save rule does. Enter on a typed significant event commits the tag
 * instead, its box preventing the key; an empty box lets Enter save. The two
 * pickers sit outside that form, portalled and beside it in the tree, so
 * Enter in a picker's search saves nothing.
 *
 * The draft's id round-trips untouched. `Rules_CI_Node` mints a rule's id from
 * its pattern, so an edit that moves the pattern arrives carrying the OLD id,
 * which is the only thing telling `upsert` which entry this edit replaces.
 */

import { useId, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	BaseControl,
	useBaseControlProps,
	Modal,
	TextControl,
	SelectControl,
	CheckboxControl,
	VisuallyHidden,
} from '@wordpress/components';

import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import HookSelectorModal, {
	PLUGIN_TIME_HOOKS,
} from '../settings/settings/HookSelectorModal';

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
 * A logging checkbox whose description is the native title of the checkbox
 * and its label. The input names the same text through `aria-describedby`,
 * so a screen reader reads it on focus.
 *
 * @param {Object}                     props          Component props.
 * @param {string}                     props.name     The input's name.
 * @param {string}                     props.label    The checkbox label.
 * @param {string}                     props.help     What the switch does and costs.
 * @param {boolean}                    props.checked  Whether it is on.
 * @param {(checked: boolean) => void} props.onChange Change handler.
 * @return {import('react').ReactElement} The checkbox and its description.
 */
function RuleSwitch( { name, label, help, checked, onChange } ) {
	const helpId = useId();
	return (
		<div className="rule-edit-switch" title={ help }>
			<CheckboxControl
				name={ name }
				label={ label }
				checked={ checked }
				onChange={ onChange }
				aria-describedby={ helpId }
			/>
			<VisuallyHidden id={ helpId }>{ help }</VisuallyHidden>
		</div>
	);
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
 * @param {string}                  [props.className] EXTRA class names; the modal supplies its own skin classes.
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
	// The box gets a real label, and its help as its description.
	const significantBase = useBaseControlProps( {
		help: __(
			'Press Enter to add one. Each is timed per callback, and auto-disable never removes it.',
			'newspack-event-logger-nodes'
		),
	} );
	const [ autoDisable, setAutoDisable ] = useState(
		String( rule?.auto_disable_threshold ?? 0 )
	);
	const [ autoProtect, setAutoProtect ] = useState(
		String( rule?.auto_protect_time_threshold ?? 0 )
	);
	const [ sampleRate, setSampleRate ] = useState(
		String( rule?.sample_rate ?? 1 )
	);
	const [ groupPaths, setGroupPaths ] = useState( !! rule?.group_paths );
	const [ logQueries, setLogQueries ] = useState( !! rule?.log_queries );
	const [ logHttp, setLogHttp ] = useState( !! rule?.log_http );
	const [ logPluginLoads, setLogPluginLoads ] = useState(
		!! rule?.log_plugin_loads
	);
	const [ logMemory, setLogMemory ] = useState( !! rule?.log_memory );
	const [ flushEveryLine, setFlushEveryLine ] = useState(
		!! rule?.flush_every_line
	);
	const [ logContext, setLogContext ] = useState( !! rule?.log_context );
	const [ logCache, setLogCache ] = useState( !! rule?.log_cache );
	const [ logResources, setLogResources ] = useState(
		!! rule?.log_resources
	);
	const [ logPhpErrors, setLogPhpErrors ] = useState(
		!! rule?.log_php_errors
	);
	const [ traceHooks, setTraceHooks ] = useState( !! rule?.trace_hooks );
	const [ traceCallers, setTraceCallers ] = useState(
		String( rule?.trace_callers ?? 0 )
	);
	const [ significantKey, setSignificantKey ] = useState( 0 );
	const [ error, setError ] = useState( '' );
	const [ isHooksOpen, setIsHooksOpen ] = useState( false );
	const [ isCustomOpen, setIsCustomOpen ] = useState( false );
	const [ confirmDelete, setConfirmDelete ] = useState( false );

	const isLog = 'log' === action;

	/**
	 * Stage everything that times time by plugin owner.
	 *
	 * Adds the lifecycle hooks to both lists, `sql` and `http` to the
	 * significant events, and turns the three span switches on. It edits the
	 * pending draft only; Save writes it.
	 */
	const measurePluginTime = () => {
		const union = ( list, add ) => [ ...new Set( [ ...list, ...add ] ) ];
		setHooks( union( hooks, PLUGIN_TIME_HOOKS ) );
		setSignificant(
			union( significant, [ ...PLUGIN_TIME_HOOKS, 'sql', 'http' ] )
		);
		setSignificantKey( ( key ) => key + 1 );
		setLogQueries( true );
		setLogHttp( true );
		setLogPluginLoads( true );
	};

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
		const rate = '' === sampleRate.trim() ? NaN : Number( sampleRate );
		if ( isLog && ! ( rate >= 0 && rate <= 1 ) ) {
			setError(
				__(
					'Sample frequency must be a number from 0 to 1.',
					'newspack-event-logger-nodes'
				)
			);
			return;
		}
		if ( isLog && groupPaths && trimmed.includes( '?' ) ) {
			setError(
				__(
					'Group paths needs a prefix pattern, with no ?.',
					'newspack-event-logger-nodes'
				)
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
			log_http: isLog && logHttp,
			log_plugin_loads: isLog && logPluginLoads,
			log_memory: isLog && logMemory,
			flush_every_line: isLog && flushEveryLine,
			log_context: isLog && logContext,
			log_cache: isLog && logCache,
			log_resources: isLog && logResources,
			log_php_errors: isLog && logPhpErrors,
			trace_hooks: isLog && traceHooks,
			// The count refines the caller label, so unticking retires both.
			trace_callers:
				isLog && traceHooks
					? toNumber( traceCallers, ( v ) => parseInt( v, 10 ) )
					: 0,
			sample_rate: isLog ? rate : 1,
			group_paths: isLog && groupPaths,
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
			<form { ...submitProps( handleSave ) }>
				<div className="rule-edit-body">
					<TextControl
						__next40pxDefaultSize
						label={ __(
							'URL pattern',
							'newspack-event-logger-nodes'
						) }
						help={ __(
							'A prefix like /blog, or an exact match ending in ? (e.g. /about?).',
							'newspack-event-logger-nodes'
						) }
						name="rule-pattern"
						value={ pattern }
						onChange={ setPattern }
					/>

					{ isLog && (
						<RuleSwitch
							name="rule-group-paths"
							label={ __(
								'Group paths',
								'newspack-event-logger-nodes'
							) }
							help={ __(
								"Lists every path under this prefix under the prefix's page, as though the rest of the path were its query string, so /author/sociales/page/2 is a shape of /author/sociales. Prefix patterns only.",
								'newspack-event-logger-nodes'
							) }
							checked={ groupPaths }
							onChange={ setGroupPaths }
						/>
					) }

					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Action', 'newspack-event-logger-nodes' ) }
						name="rule-action"
						value={ action }
						options={ [
							{
								label: __(
									'Log',
									'newspack-event-logger-nodes'
								),
								value: 'log',
							},
							{
								label: __(
									'Skip',
									'newspack-event-logger-nodes'
								),
								value: 'skip',
							},
						] }
						onChange={ setAction }
					/>

					{ isLog && (
						<>
							<TextControl
								__next40pxDefaultSize
								type="number"
								min={ 0 }
								max={ 1 }
								step="any"
								label={ __(
									'Sample frequency',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									'The share of matching requests this rule logs, from 0 to 1. 0.1 logs about 1 in 10; 1 logs every one.',
									'newspack-event-logger-nodes'
								) }
								name="rule-sample-rate"
								value={ sampleRate }
								onChange={ setSampleRate }
							/>

							<div className="rule-edit-selectors">
								<div className="rule-edit-hooks-field">
									<BaseControl.VisualLabel className="rule-edit-field-label">
										{ __(
											'Hooks',
											'newspack-event-logger-nodes'
										) }
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
										onClick={ () =>
											setIsCustomOpen( true )
										}
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

								<div className="rule-edit-plugin-time-field">
									<BaseControl.VisualLabel className="rule-edit-field-label">
										{ __(
											'Plugin time',
											'newspack-event-logger-nodes'
										) }
									</BaseControl.VisualLabel>
									<button
										type="button"
										className="button"
										onClick={ measurePluginTime }
									>
										{ __(
											'Measure plugin time',
											'newspack-event-logger-nodes'
										) }
									</button>
									<span className="rule-edit-field-note newspack-nodes-status">
										{ __(
											'Adds the lifecycle hooks, sql and http',
											'newspack-event-logger-nodes'
										) }
									</span>
								</div>
							</div>

							<BaseControl
								{ ...significantBase.baseControlProps }
								id={ significantBase.baseControlProps.id }
								className="rule-edit-tag-field"
								label={ __(
									'Significant events',
									'newspack-event-logger-nodes'
								) }
							>
								<TagInputField
									key={ significantKey }
									{ ...significantBase.controlProps }
									initialValues={ significant }
									onChange={ setSignificant }
								/>
							</BaseControl>

							<div className="rule-edit-threshold-row">
								<TextControl
									__next40pxDefaultSize
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

							<RuleSwitch
								name="rule-log-queries"
								label={ __(
									'Log database queries',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									"Times every query as its own flame span. Each query adds two entries to the request's log, so a request running hundreds of them leaves a large record, which may be folded under memory pressure.",
									'newspack-event-logger-nodes'
								) }
								checked={ logQueries }
								onChange={ setLogQueries }
							/>

							<RuleSwitch
								name="rule-log-http"
								label={ __(
									'Log HTTP requests',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									"Times every outbound HTTP request as its own flame span. Each outbound call adds two entries to the request's log.",
									'newspack-event-logger-nodes'
								) }
								checked={ logHttp }
								onChange={ setLogHttp }
							/>

							<RuleSwitch
								name="rule-log-plugin-loads"
								label={ __(
									'Log plugin load time',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									'Times each site-activated plugin as its own flame span. The measuring happens either way; this is two log entries per plugin, before the request does any work.',
									'newspack-event-logger-nodes'
								) }
								checked={ logPluginLoads }
								onChange={ setLogPluginLoads }
							/>

							<RuleSwitch
								name="rule-log-memory"
								label={ __(
									'Log peak memory on every span',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									"Adds the process's peak memory to every span's close, so memory growth reads across the request's timeline. The request's own close carries it either way.",
									'newspack-event-logger-nodes'
								) }
								checked={ logMemory }
								onChange={ setLogMemory }
							/>

							<RuleSwitch
								name="rule-log-context"
								label={ __(
									'Log request context',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									"Adds one entry to the request's log naming the template WordPress included, the rewrite rule and query it matched, its query vars and the conditionals the main query answers true.",
									'newspack-event-logger-nodes'
								) }
								checked={ logContext }
								onChange={ setLogContext }
							/>

							<RuleSwitch
								name="rule-log-cache"
								label={ __(
									'Log object cache counts',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									"Adds one entry to the request's log counting its object-cache hits and misses.",
									'newspack-event-logger-nodes'
								) }
								checked={ logCache }
								onChange={ setLogCache }
							/>

							<RuleSwitch
								name="rule-log-resources"
								label={ __(
									'Log resource usage',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									"Adds two entries to the request's log, its CPU time, page faults, I/O blocks and context switches at the start and at the end. The CPU figures beside a finding's gaps come from these.",
									'newspack-event-logger-nodes'
								) }
								checked={ logResources }
								onChange={ setLogResources }
							/>

							<RuleSwitch
								name="rule-log-php-errors"
								label={ __(
									'Log PHP errors',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									"Adds one entry to the request's log for each distinct PHP warning, notice or deprecation, and for each of WordPress's own deprecation and misuse notices, naming the file, the line and the plugin or theme that owns it. A repeat is counted, not logged, in one entry at the request's end. Errors PHP or WordPress would have hidden are logged too, and marked silenced.",
									'newspack-event-logger-nodes'
								) }
								checked={ logPhpErrors }
								onChange={ setLogPhpErrors }
							/>

							<RuleSwitch
								name="rule-flush-every-line"
								label={ __(
									'Flush every line',
									'newspack-event-logger-nodes'
								) }
								help={ __(
									'Writes each entry to disk as it is logged, so the last line before a crash or an out-of-memory kill survives. Costs one write per entry.',
									'newspack-event-logger-nodes'
								) }
								checked={ flushEveryLine }
								onChange={ setFlushEveryLine }
							/>

							<div className="rule-edit-trace-row">
								<RuleSwitch
									name="rule-trace-hooks"
									label={ __(
										'Trace hook callers',
										'newspack-event-logger-nodes'
									) }
									help={
										traceHooks
											? __(
													'Labels every hook span with who called it, so a hook that runs many times splits by caller in the flame graph. The number traces that many calls from each CALLER of a hook, query shape or outbound URL further, recording the twenty frames above each — expensive, so 1 already reaches every caller. At 0 every span still carries its label and none records a backtrace.',
													'newspack-event-logger-nodes'
											  )
											: __(
													'Labels every hook span with who called it, so a hook that runs many times splits by caller in the flame graph.',
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
											hideLabelFromVision
											type="number"
											label={ __(
												'Calls traced per caller of each hook, query shape and URL',
												'newspack-event-logger-nodes'
											) }
											name="rule-trace-callers"
											value={ traceCallers }
											onChange={ setTraceCallers }
										/>
										<span className="newspack-nodes-status is-muted">
											{ __(
												'traced per caller of each hook, query shape and URL',
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
					<button
						type="button"
						className="button"
						onClick={ onCancel }
					>
						{ __( 'Cancel', 'newspack-event-logger-nodes' ) }
					</button>
					<button type="submit" className="button button-primary">
						{ __( 'Save rule', 'newspack-event-logger-nodes' ) }
					</button>
				</div>
			</form>

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
