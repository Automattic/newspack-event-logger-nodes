/**
 * <AskPanel> — the `?` picker and the brief it assembles.
 *
 * One button. Click it, the cursor becomes a `?`, and the next thing you click
 * decides everything: a slow URL in the overview, a request in URL detail, a
 * span in the flame graph, an anomalous log line. THE TARGET IS THE SCOPE, so
 * there is no per-surface branching and no guessing what you meant.
 *
 * Cmd/Ctrl-click adds to the selection, matching the modifier that already
 * ships on these same elements. A picker click is not consent to send: the
 * assembled brief is shown here first, and copying it is a separate act.
 */

import { useCallback, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useAskPicker } from '@newspack-nodes/shared/hooks/useAskPicker';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';
import Modal from '@newspack-nodes/shared/components/Modal';
import { formatCommandArgs, nodesData } from '@newspack-nodes/runtime';
import { briefToMarkdown } from '../askBrief';

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
 * The picker trigger plus the brief preview.
 *
 * @param {Object}   props
 * @param {Function} [props.onError] Called with a message when an ask fails.
 * @return {import('react').ReactElement} The panel.
 */
export default function AskPanel( { onError } ) {
	const [ briefs, setBriefs ] = useState( [] );
	const [ open, setOpen ] = useState( false );
	const [ copied, setCopied ] = useState( false );

	// A property of the PICK; a click cannot beat the answer to the last one.
	const additiveRef = useRef( false );
	const onErrorRef = useRef( onError );
	onErrorRef.current = onError;

	const { run: ask } = useCommandOnce( {
		scope: 'performance:ask',
		target: '_shell/_http/performance',
		command: 'ask',
		retry: true,
		onDone: ( { result, error } ) => {
			if ( error ) {
				onErrorRef.current?.( error );
				return;
			}
			if ( ! result || 'object' !== typeof result ) {
				return;
			}
			setBriefs( ( prior ) =>
				additiveRef.current ? [ ...prior, result ] : [ result ]
			);
			setCopied( false );
			setOpen( true );
		},
	} );

	const handlePick = useCallback(
		( descriptors, { additive } ) => {
			additiveRef.current = additive;
			ask( formatCommandArgs( descriptors ) );
		},
		[ ask ]
	);

	const { active, start, cancel } = useAskPicker( { onPick: handlePick } );

	const markdown = briefToMarkdown( briefs );

	const copy = useCallback( () => {
		window.navigator?.clipboard
			?.writeText( markdown )
			.then( () => setCopied( true ) );
	}, [ markdown ] );

	return (
		<>
			<button
				type="button"
				className={ `button event-logger-ask__trigger${
					active ? ' is-active' : ''
				}` }
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

			{ open && briefs.length > 0 && (
				<Modal
					ariaLabel={ __(
						'Assembled brief',
						'newspack-event-logger-nodes'
					) }
					onClose={ () => setOpen( false ) }
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

					<p className="event-logger-ask__mcp">
						{ sprintf(
							// translators: %s: the MCP endpoint URL.
							__(
								'This site can also expose these numbers to an agent over MCP, at %s. Connecting one is a deliberate act: issue a scoped session under Nodes → Sessions and configure your client with it.',
								'newspack-event-logger-nodes'
							),
							`${
								nodesData().restUrl
							}newspack-event-logger-nodes/v1/mcp`
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
						<button
							type="button"
							className="button"
							onClick={ () => setOpen( false ) }
						>
							{ __( 'Close', 'newspack-event-logger-nodes' ) }
						</button>
						<button
							type="button"
							className="button button-primary"
							onClick={ copy }
						>
							{ __(
								'Copy brief',
								'newspack-event-logger-nodes'
							) }
						</button>
					</div>
				</Modal>
			) }
		</>
	);
}
