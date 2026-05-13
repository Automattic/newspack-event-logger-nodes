/**
 * REPL footer — collapsible transcript + prompt + command input + status.
 *
 * The transcript surfaces worker output that isn't an `ls` topology
 * snapshot: command echoes (kind='sent'), command responses
 * (kind='recv'), TM_ERROR responses (kind='error'). It's collapsed by
 * default so the footer keeps its 38px drafting-room rhythm; the
 * prompt-side caret expands it. Auto-scrolls to the latest entry.
 *
 * Command parsing: the first whitespace-separated token is the verb
 * name, everything after is the arguments string. Mirrors how the
 * cli's Shell splits `<verb> <args>` before dispatching.
 */

import { useEffect, useRef, useState } from '@wordpress/element';

const STATUS_LABELS = {
	connecting: 'CONNECTING',
	open: 'CONNECTED',
	error: 'DISCONNECTED',
	closed: 'CLOSED',
};

function previewLine( entry ) {
	const text = entry.text || '';
	const idx = text.indexOf( '\n' );
	return idx === -1 ? text : `${ text.slice( 0, idx ) } …`;
}

const KIND_SIGIL = {
	sent: '>',
	error: '!',
	recv: '<',
};

function sigilFor( kind ) {
	return KIND_SIGIL[ kind ] || '<';
}

export default function ReplFooter( {
	topology,
	partition,
	streamStatus,
	canSend,
	onSubmit,
	onClear,
	transcript = [],
} ) {
	const [ value, setValue ] = useState( '' );
	// Default to expanded so command output is always visible. The
	// toggle still lets the user collapse the panel down to the
	// single-line peek when they want canvas real estate.
	const [ expanded, setExpanded ] = useState( true );
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
		if ( ! trimmed || ! canSend ) {
			return;
		}
		const idx = trimmed.search( /\s/ );
		const name = idx === -1 ? trimmed : trimmed.slice( 0, idx );
		const args = idx === -1 ? '' : trimmed.slice( idx + 1 ).trim();
		onSubmit( { name, arguments: args } );
		setValue( '' );
		setExpanded( true );
	}

	const latest = transcript.length
		? transcript[ transcript.length - 1 ]
		: null;

	return (
		<footer
			className={ `topology-repl${ expanded ? ' is-expanded' : '' }` }
		>
			{ expanded ? (
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
					{ transcript.length === 0 ? (
						<div className="topology-repl__transcript-empty">
							No command output yet. Try `ls` or `make_node Echo
							my_node`.
						</div>
					) : (
						transcript.map( ( entry ) => (
							<div
								key={ entry.key }
								className={ `topology-repl__entry topology-repl__entry--${ entry.kind }` }
							>
								{ entry.kind === 'sent' && (
									<span className="topology-repl__sigil">
										&gt;
									</span>
								) }
								{ entry.kind === 'recv' && (
									<span className="topology-repl__sigil">
										&lt;
									</span>
								) }
								{ entry.kind === 'error' && (
									<span className="topology-repl__sigil">
										!
									</span>
								) }
								{ entry.kind === 'info' && (
									<span className="topology-repl__sigil">
										i
									</span>
								) }
								<pre className="topology-repl__entry-text">
									{ entry.text }
								</pre>
							</div>
						) )
					) }
				</div>
			) : (
				latest && (
					<div
						className={ `topology-repl__peek topology-repl__peek--${ latest.kind }` }
					>
						<span className="topology-repl__sigil">
							{ sigilFor( latest.kind ) }
						</span>
						{ previewLine( latest ) }
					</div>
				)
			) }
			<div className="topology-repl__bar">
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
				<span className="topology-repl__prompt">
					{ topology }.p{ partition }&nbsp;&gt;
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
			</div>
		</footer>
	);
}
