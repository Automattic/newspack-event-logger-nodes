/**
 * REPL footer — collapsible transcript + prompt + command input + status.
 *
 * Transcript surfaces worker output: command echoes (kind='sent'),
 * responses (kind='recv'), errors (kind='error'), info lines
 * (kind='info'). Expanded by default; the ▼ toggle minimizes back to
 * the bare 38px bar so the user can reclaim canvas real estate.
 * Auto-scrolls to the latest entry when growing.
 */

import { useEffect, useRef, useState } from '@wordpress/element';

const STATUS_LABELS = {
	connecting: 'CONNECTING',
	open: 'CONNECTED',
	error: 'DISCONNECTED',
	closed: 'CLOSED',
};

export default function ReplFooter( {
	topology,
	partition,
	streamStatus,
	canSend,
	onSubmit,
	onClear,
	transcript = [],
	expanded,
	onExpandedChange,
} ) {
	const [ value, setValue ] = useState( '' );
	const setExpanded = ( next ) => {
		if ( onExpandedChange ) {
			onExpandedChange(
				typeof next === 'function' ? next( expanded ) : next
			);
		}
	};
	const logRef = useRef( null );

	const statusLabel =
		STATUS_LABELS[ streamStatus ] || streamStatus.toUpperCase();

	// Auto-scroll to the newest entry when the transcript grows. Only
	// runs when the panel is open; collapsed panel just shows the most
	// recent line as a peek above the input.
	useEffect( () => {
		if ( expanded && logRef.current ) {
			logRef.current.scrollTop = logRef.current.scrollHeight;
		}
	}, [ transcript, expanded ] );

	// Esc minimizes the transcript when it's open. Document-level so
	// it works whether the user is focused on the input, the canvas,
	// or anywhere else on the page. Listener only attaches while
	// expanded — no cost when minimized.
	useEffect( () => {
		if ( ! expanded ) {
			return undefined;
		}
		const handler = ( ev ) => {
			if ( ev.key === 'Escape' ) {
				ev.preventDefault();
				setExpanded( false );
			}
		};
		document.addEventListener( 'keydown', handler );
		return () => document.removeEventListener( 'keydown', handler );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ expanded ] );

	function handleKeyDown( ev ) {
		// Ctrl+L (or Cmd+L on macOS): clear the transcript, terminal-style.
		// The cli's readline binding does the same.
		if (
			( ev.ctrlKey || ev.metaKey ) &&
			( ev.key === 'l' || ev.key === 'L' )
		) {
			ev.preventDefault();
			if ( onClear ) {
				onClear();
			}
			return;
		}
		if ( ev.key !== 'Enter' ) {
			return;
		}
		ev.preventDefault();
		const trimmed = value.trim();
		if ( ! trimmed ) {
			return;
		}
		// Pass the raw line up — the parent runs it through
		// shellInterpret so the same shell layer drives every code
		// path (local builtins included).
		onSubmit( trimmed );
		setValue( '' );
		setExpanded( true );
	}

	// Show the transcript pane whenever the user has explicitly
	// expanded it — even if it's empty. Initial render is minimized,
	// so an empty pane only appears after a click on ▲ or after the
	// first command auto-opens; either way the user asked for it.
	const showTranscript = expanded;

	return (
		<footer
			className={ `topology-repl${
				showTranscript ? ' is-expanded' : ''
			}` }
		>
			{ showTranscript && (
				<div className="topology-repl__transcript" ref={ logRef }>
					<div className="topology-repl__actions">
						<button
							type="button"
							className="topology-repl__clear"
							onClick={ () => {
								if ( onClear ) {
									onClear();
								}
							} }
							title="Clear transcript (Ctrl+L)"
							aria-label="Clear transcript"
						>
							✕
						</button>
						<button
							type="button"
							className="topology-repl__toggle"
							onClick={ () => setExpanded( false ) }
							title="Minimize transcript"
							aria-label="Minimize transcript"
						>
							▼
						</button>
					</div>
					{ transcript.map( ( entry ) => (
						<pre
							key={ entry.key }
							className={ `topology-repl__entry topology-repl__entry--${ entry.kind }` }
						>
							{ entry.kind === 'sent'
								? `${ topology }.p${ partition }> ${ entry.text }`
								: entry.text }
						</pre>
					) ) }
				</div>
			) }
			<div className="topology-repl__bar">
				<span className="topology-repl__prompt">
					{ topology }.p{ partition }&gt;
				</span>
				<input
					type="text"
					className="topology-repl__input"
					placeholder={
						canSend
							? 'ls / ls -als / make_node Echo my_node / connect_node a b …'
							: 'Connecting…'
					}
					value={ value }
					onChange={ ( ev ) => setValue( ev.target.value ) }
					onKeyDown={ handleKeyDown }
					disabled={ ! canSend }
					autoComplete="off"
					spellCheck="false"
				/>
				<span className="topology-repl__status">
					<span
						className={ `topology-repl__dot${
							streamStatus === 'open' ? ' is-pulsing' : ''
						}` }
					/>
					{ statusLabel }
				</span>
				{ ! expanded && (
					<button
						type="button"
						className="topology-repl__toggle"
						onClick={ () => setExpanded( true ) }
						title="Restore transcript"
						aria-label="Restore transcript"
					>
						▲
					</button>
				) }
			</div>
		</footer>
	);
}
