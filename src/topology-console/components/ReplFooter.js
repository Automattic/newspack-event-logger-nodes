/**
 * REPL footer — prompt + inert command input + connection status.
 *
 * Inspect-only in v1; the input is read-only. Status mirrors
 * useTopologyStream — the green dot pulses when SSE is open, freezes
 * on error/closed.
 */

export default function ReplFooter( { topology, partition, streamStatus } ) {
	const statusLabel =
		{
			connecting: 'CONNECTING',
			open: 'CONNECTED',
			error: 'DISCONNECTED',
			closed: 'CLOSED',
		}[ streamStatus ] || streamStatus.toUpperCase();

	return (
		<footer className="topology-repl">
			<span className="topology-repl__prompt">
				{ topology }.p{ partition }&nbsp;&gt;
			</span>
			<input
				type="text"
				className="topology-repl__input"
				placeholder="REPL input is read-only in v1 — inspect-only build"
				readOnly
				autoComplete="off"
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
