/**
 * REPL footer — prompt + command input + connection status.
 *
 * Command parsing: the first whitespace-separated token is the verb name,
 * everything after is the arguments string. Mirrors how the cli's Shell
 * splits `<verb> <args>` before dispatching to CommandInterpreter.
 *
 * On Enter, calls onSubmit({name, arguments}). The parent (TopologyConsole)
 * posts that to the companion REST endpoint, which writes a single
 * TM_COMMAND to the worker. The worker's reply flows back through the
 * already-open SSE stream and the canvas refreshes when the next ls -ct
 * tick arrives — or sooner if the verb itself responded.
 */

import { useState } from '@wordpress/element';

export default function ReplFooter( {
	topology,
	partition,
	streamStatus,
	canSend,
	onSubmit,
} ) {
	const [ value, setValue ] = useState( '' );

	const statusLabel =
		{
			connecting: 'CONNECTING',
			open: 'CONNECTED',
			error: 'DISCONNECTED',
			closed: 'CLOSED',
		}[ streamStatus ] || streamStatus.toUpperCase();

	function handleKeyDown( ev ) {
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
	}

	return (
		<footer className="topology-repl">
			<span className="topology-repl__prompt">
				{ topology }.p{ partition }&nbsp;&gt;
			</span>
			<input
				type="text"
				className="topology-repl__input"
				placeholder={
					canSend
						? 'ls / ls -al / dump <node> / send Echo <node> …'
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
		</footer>
	);
}
