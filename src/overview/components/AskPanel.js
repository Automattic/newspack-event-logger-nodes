/**
 * The `?` picker: `useAsk` holds it, <AskButton> opens it, <AskPanel> shows what
 * it assembled.
 *
 * Click a trigger, the cursor becomes a `?`, and the next thing you click
 * decides everything — a slow URL in the overview, a request in URL detail, a
 * span in the flame graph, an anomalous log line. THE TARGET IS THE SCOPE, so
 * there is no per-surface branching and no guessing what you meant.
 *
 * Cmd/Ctrl-click adds to the selection, matching the modifier that already
 * ships on these same elements. A picker click is not consent to send: the
 * assembled brief is shown here first, and copying it is a separate act.
 *
 * The three parts live together because the picker is ONE document-level mode —
 * it marks the body, retargets every `[data-ask]` element and swallows the next
 * click in the capture phase. A second `useAsk` would fight the first over that
 * one mode, so the dashboard holds one and renders <AskButton> wherever a
 * question forms: beside the overview search, beside the URL's rule control,
 * beside the request's back button.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Modal from '@newspack-nodes/shared/components/Modal';
import {
	ASK_TRIGGER_ATTR,
	useAskPicker,
} from '@newspack-nodes/shared/hooks/useAskPicker';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';
import { formatCommandArgs, nodesData } from '@newspack-nodes/runtime';
import { askClaudeUrl, briefToMarkdown } from '../askBrief';

/**
 * The picker's state, held once for the whole dashboard.
 *
 * @param {Object}   options
 * @param {Function} [options.onError]      Called with a message when an ask fails.
 * @param {string}   [options.serverFilter] Server scope a `url:` brief answers for; '' is every server.
 * @return {{ active: boolean, start: Function, cancel: Function, briefs: Array, open: boolean, close: Function }} Ask state.
 */
export function useAsk( { onError, serverFilter = '' } = {} ) {
	const [ briefs, setBriefs ] = useState( [] );

	const onErrorRef = useRef( onError );
	onErrorRef.current = onError;

	// @longform
	// NOT a retried read, though it reads: a retry SUPERSEDES, and multi-select
	// is several asks in flight at once — the earlier one's answer is wanted,
	// so superseding drops a brief the user asked for. Each ask queues and goes
	// exactly once, and every reply appends.
	const { run: ask } = useCommandOnce( {
		ci: 'performance',
		command: 'ask',
		onDone: ( { result, error } ) => {
			if ( error ) {
				onErrorRef.current?.( error );
				return;
			}
			if ( ! result || 'object' !== typeof result ) {
				onErrorRef.current?.(
					__(
						'That answered with no brief. Nothing was assembled for what you picked.',
						'newspack-event-logger-nodes'
					)
				);
				return;
			}
			setBriefs( ( prior ) => [ ...prior, result ] );
		},
	} );

	const handlePick = useCallback(
		( descriptors ) => {
			// Scoped: the facts block stamps the filters onto each surface.
			ask(
				formatCommandArgs(
					descriptors,
					serverFilter ? { server: serverFilter } : {}
				)
			);
		},
		[ ask, serverFilter ]
	);

	const { active, start, cancel } = useAskPicker( {
		onPick: handlePick,
		onAbandon: () => setBriefs( [] ),
	} );

	// @longform
	// The SELECTION belongs to one picker session: arming starts a fresh one,
	// a modified click keeps it open, and the plain click that disarms the
	// picker ends it. So the panel is not a separate piece of state — it is
	// simply "the picker is done and something arrived". Opening on each reply
	// instead put the brief in front of the next thing being Cmd-clicked, and
	// a flag read at reply time would answer for whichever pick landed last.
	const open = ! active && 0 < briefs.length;
	const discard = useCallback( () => setBriefs( [] ), [] );
	const startPicking = useCallback( () => {
		discard();
		start();
	}, [ discard, start ] );
	const cancelPicking = useCallback( () => {
		discard();
		cancel();
	}, [ discard, cancel ] );

	return {
		active,
		start: startPicking,
		cancel: cancelPicking,
		briefs,
		open,
		close: discard,
	};
}

/**
 * A door into the picker. As many as there are places worth asking from.
 *
 * @param {Object} props
 * @param {Object} props.ask The `useAsk` state.
 * @return {import('react').ReactElement} The trigger.
 */
export function AskButton( { ask } ) {
	const { active, start, cancel } = ask;
	return (
		<button
			type="button"
			className={ `button event-logger-ask__trigger${
				active ? ' is-active' : ''
			}` }
			{ ...{ [ ASK_TRIGGER_ATTR ]: '' } }
			onClick={ active ? cancel : start }
			aria-pressed={ active }
		>
			{ active
				? __(
						'Cancel — click anything to ask',
						'newspack-event-logger-nodes'
				  )
				: __( 'Ask AI', 'newspack-event-logger-nodes' ) }
		</button>
	);
}

/** Severity → the status class the shared roles already define. */
const TONE = {
	high: 'is-error',
	medium: 'is-warning',
	info: '',
};

/**
 * One finding, rendered. This is the half that is worth shipping with no model
 * involved at all: the detector computes, and the numbers speak.
 *
 * @param {Object} props
 * @param {Object} props.finding A finding from the assembler.
 * @return {import('react').ReactElement} The rendered finding.
 */
function Finding( { finding } ) {
	const proposal = finding.proposal;
	return (
		<li className="event-logger-ask__finding">
			<strong
				className={ `newspack-nodes-status ${
					TONE[ finding.severity ] ?? ''
				}` }
			>
				{ finding.title }
			</strong>
			{ finding.detail && <p>{ finding.detail }</p> }
			<p className="event-logger-ask__measured">
				{ sprintf(
					// translators: %s: where the finding was measured.
					__( 'measured: %s', 'newspack-event-logger-nodes' ),
					finding.measured
				) }
			</p>
			{ proposal && 'none' !== proposal.action && (
				<p className="event-logger-ask__proposal">
					<code>{ proposal.action }</code>{ ' ' }
					{ 'more' === proposal.direction
						? __(
								'— more visibility',
								'newspack-event-logger-nodes'
						  )
						: __( '— less noise', 'newspack-event-logger-nodes' ) }
					{ proposal.why && <span> — { proposal.why }</span> }
				</p>
			) }
		</li>
	);
}

/**
 * The brief preview.
 *
 * @param {Object} props
 * @param {Object} props.ask The `useAsk` state.
 * @return {import('react').ReactElement} The panel.
 */
export default function AskPanel( { ask } ) {
	const { briefs, open, close } = ask;
	const [ copied, setCopied ] = useState( false );

	// Each fresh answer is its own thing to copy.
	useEffect( () => {
		setCopied( false );
	}, [ briefs ] );

	const endpoint = `${
		nodesData().restUrl
	}newspack-event-logger-nodes/v1/mcp`;
	const markdown = briefToMarkdown( briefs, endpoint );

	// The Claude link's too: past the URL budget it only asks for a paste.
	const copy = useCallback( () => {
		window.navigator?.clipboard
			?.writeText( markdown )
			.then( () => setCopied( true ) );
	}, [ markdown ] );

	if ( ! open || 0 === briefs.length ) {
		return null;
	}

	// @longform Summoned FROM the URL and request detail views, which are
	// `@wordpress/components` modals: those portal to the body on the same
	// z-index layer, so document order decides and this one — rendered inside
	// the dashboard's own root — loses however late it opens. The backdrop is
	// where that layer lives, so it is raised there.
	return (
		<Modal
			ariaLabel={ __( 'Assembled brief', 'newspack-event-logger-nodes' ) }
			backdropClassName="event-logger-ask__backdrop"
			onClose={ close }
		>
			<h4 className="newspack-nodes-modal__title">
				{ 1 === briefs.length
					? sprintf(
							// translators: %s: the subject asked about.
							__(
								'About this %s',
								'newspack-event-logger-nodes'
							),
							briefs[ 0 ].subject
					  )
					: sprintf(
							// translators: %d: how many things were selected.
							__(
								'About %d selected things',
								'newspack-event-logger-nodes'
							),
							briefs.length
					  ) }
			</h4>

			{ briefs.map( ( brief, i ) => (
				<div
					key={ `${ brief.subject }-${ i }` }
					className="event-logger-ask__section"
				>
					{ ( brief.findings ?? [] ).length > 0 ? (
						<ul className="event-logger-ask__findings">
							{ brief.findings.map( ( finding, j ) => (
								<Finding
									key={ `${ finding.kind }-${ j }` }
									finding={ finding }
								/>
							) ) }
						</ul>
					) : (
						<p className="newspack-nodes-status">
							{ __(
								'Nothing stands out in the numbers here.',
								'newspack-event-logger-nodes'
							) }
						</p>
					) }
				</div>
			) ) }

			<p className="event-logger-ask__leaves-page">
				{ __(
					'Copying puts this on your clipboard; Ask Claude carries it in the link, which browser history and any URL-logging proxy will keep. Both are a deliberate act — the picker click was not.',
					'newspack-event-logger-nodes'
				) }
			</p>

			<p className="event-logger-ask__mcp">
				{ sprintf(
					// translators: %s: the MCP endpoint URL.
					__(
						'This site can also expose these numbers to an agent over MCP, at %s. Connecting one is a deliberate act: issue a scoped session under Nodes → Sessions and configure your client with it.',
						'newspack-event-logger-nodes'
					),
					endpoint
				) }
			</p>

			<details className="event-logger-ask__raw">
				<summary>
					{ __(
						'The brief that leaves this page',
						'newspack-event-logger-nodes'
					) }
				</summary>
				<pre>{ markdown }</pre>
			</details>

			<div className="newspack-nodes-modal__actions">
				<span className="newspack-nodes-status">
					{ copied
						? __( 'Copied.', 'newspack-event-logger-nodes' )
						: '' }
				</span>
				<button type="button" className="button" onClick={ close }>
					{ __( 'Close', 'newspack-event-logger-nodes' ) }
				</button>
				<a
					className="button"
					href={ askClaudeUrl( markdown ) }
					target="_blank"
					rel="noopener noreferrer"
					onClick={ copy }
				>
					{ __( 'Ask Claude', 'newspack-event-logger-nodes' ) }
				</a>
				<button
					type="button"
					className="button button-primary"
					onClick={ copy }
				>
					{ __( 'Copy brief', 'newspack-event-logger-nodes' ) }
				</button>
			</div>
		</Modal>
	);
}
