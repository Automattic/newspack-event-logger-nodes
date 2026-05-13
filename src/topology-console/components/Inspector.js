/**
 * Right-pane inspector for the selected node.
 *
 * Inspect-only in v1. The available data is what `ls -al` exposes —
 * id, counter, and the edges connecting it. Type is inferred from the
 * name. Rate, message-size histogram, memory, uptime, and process
 * metadata are v2 affordances that will land once the substrate gains
 * an `inspect <node>` verb returning the full state envelope.
 *
 * The action buttons are inert in v1 — they're scaffolded as a hint of
 * the v2 surface and to keep the visual rhythm of the mockup intact.
 */

import { inferType } from '../utils/inferType';

function FieldRow( { k, v, vClass } ) {
	return (
		<div className="topology-field-row">
			<span className="topology-field-row__key">{ k }</span>
			<span
				className={ `topology-field-row__val${
					vClass ? ' ' + vClass : ''
				}` }
			>
				{ v }
			</span>
		</div>
	);
}

// Comma-joined list of clickable node-name links — used for the
// Routing section's `target →`, `also →`, and `← from` values.
// Hover highlights the node's edges on the canvas (same hoveredId
// state the canvas's onMouseEnter drives); click selects it.
// Names that don't correspond to a known node in the parsed graph
// (substrate scaffolding like `_command_interpreter`, or path-shaped
// targets like `_repl/_output/1490`) render as plain dim text — no
// point hovering or clicking something the canvas can't show.
function NodeLinks( { names, nodeIds, onSelect, onHover } ) {
	if ( ! names || ! names.length ) {
		return (
			<span className="topology-field-row__val topology-field-row__val--dim">
				—
			</span>
		);
	}
	return (
		<span className="topology-field-row__val">
			{ names.map( ( name, i ) => {
				const known = nodeIds && nodeIds.has( name );
				const sep = i < names.length - 1 ? ', ' : '';
				if ( ! known ) {
					return (
						<span
							key={ name }
							className="topology-field-row__val--dim"
						>
							{ name }
							{ sep }
						</span>
					);
				}
				return (
					<span key={ name }>
						<button
							type="button"
							className="topology-field-row__nav"
							onClick={ () => onSelect && onSelect( name ) }
							onMouseEnter={ () => onHover && onHover( name ) }
							onMouseLeave={ () => onHover && onHover( null ) }
						>
							{ name }
						</button>
						{ sep }
					</span>
				);
			} ) }
		</span>
	);
}

function Section( { title, meta, children } ) {
	return (
		<div className="topology-insp__section">
			<h4 className="topology-insp__section-title">
				{ title }
				{ meta && (
					<span className="topology-insp__section-meta">
						{ meta }
					</span>
				) }
			</h4>
			{ children }
		</div>
	);
}

function formatRate( rate ) {
	if ( rate === undefined || rate === null ) {
		return '— /s';
	}
	if ( rate === 0 ) {
		return '0 /s';
	}
	if ( rate >= 100 ) {
		return `${ Math.round( rate ) } /s`;
	}
	if ( rate >= 1 ) {
		return `${ rate.toFixed( 1 ) } /s`;
	}
	return `${ rate.toFixed( 2 ) } /s`;
}

// Bytes rendered with K / M / G suffixes once the magnitude makes the
// raw number unreadable. Mirrors what the Throughput / Process panel
// in the mockup wants: dense, glanceable values.
function formatBytes( n ) {
	if ( typeof n !== 'number' || n < 0 ) {
		return '—';
	}
	if ( n < 1024 ) {
		return `${ n } B`;
	}
	if ( n < 1024 * 1024 ) {
		return `${ ( n / 1024 ).toFixed( 1 ) } K`;
	}
	if ( n < 1024 * 1024 * 1024 ) {
		return `${ ( n / ( 1024 * 1024 ) ).toFixed( 1 ) } M`;
	}
	return `${ ( n / ( 1024 * 1024 * 1024 ) ).toFixed( 1 ) } G`;
}

function formatLastSeen( ts, live ) {
	if ( ts === undefined || ts === null ) {
		return live ? 'streaming' : '—';
	}
	const ago = Date.now() / 1000 - ts;
	if ( ago < 1 ) {
		return 'just now';
	}
	if ( ago < 60 ) {
		return `${ ago.toFixed( 1 ) }s ago`;
	}
	if ( ago < 3600 ) {
		return `${ Math.round( ago / 60 ) }m ago`;
	}
	return `${ Math.round( ago / 3600 ) }h ago`;
}

export default function Inspector( {
	selectedId,
	parsed,
	streamStatus,
	rateInfo,
	onAction,
	onSelect,
	onHover,
	nodeIds,
	ssePid,
	uptime,
} ) {
	if ( ! selectedId ) {
		return (
			<aside className="topology-inspector">
				<div className="topology-insp__empty">
					Select a node to inspect
				</div>
			</aside>
		);
	}

	const node = parsed.nodes.find( ( n ) => n.id === selectedId );
	if ( ! node ) {
		return (
			<aside className="topology-inspector">
				<div className="topology-insp__empty">
					{ selectedId } no longer present
				</div>
			</aside>
		);
	}

	const targets = parsed.edges.filter( ( e ) => e.from === selectedId );
	const sources = parsed.edges.filter( ( e ) => e.to === selectedId );
	// Prefer the authoritative class name from dump_metadata; fall
	// back to inferring from the node name if (somehow) absent.
	const type = node.klass || inferType( node.id );
	const live = streamStatus === 'open';

	// Authoritative button state, derived from server metadata —
	// no client-side bookkeeping that could drift from worker reality.
	const traceOn = node.debugState > 0;
	// The worker's input Partition is named `_repl`, so it stamps
	// `_repl/` onto every incoming command's FROM before CI sees it —
	// `connect_node <tee>` from this SSE session therefore lands in
	// the tee's target list as `_repl/_output/{sse_pid}`. The bare
	// `_output/{pid}` and `{pid}` forms only exist transiently on TO
	// as Router peels path segments; they're never stored in any
	// target list, so we only need to match the stamped form.
	const tailOn =
		ssePid &&
		parsed.edges.some(
			( e ) =>
				e.from === selectedId && e.to === `_repl/_output/${ ssePid }`
		);

	return (
		<aside className="topology-inspector">
			<h2 className="topology-insp__title">{ node.id }</h2>
			<div className="topology-insp__type">
				<span
					className={ `topology-insp__led${
						live ? ' is-pulsing' : ''
					}` }
				/>
				{ type } · { live ? 'LIVE' : streamStatus.toUpperCase() }
			</div>

			<Section title="Identity" meta="make_node">
				<FieldRow k="name" v={ node.id } />
				<FieldRow k="class" v={ type } />
				<FieldRow
					k="arguments"
					v="—"
					vClass="topology-field-row__val--dim"
				/>
			</Section>

			<Section title="Routing">
				<div className="topology-field-row">
					<span className="topology-field-row__key">target →</span>
					<NodeLinks
						names={ targets.slice( 0, 1 ).map( ( t ) => t.to ) }
						nodeIds={ nodeIds }
						onSelect={ onSelect }
						onHover={ onHover }
					/>
				</div>
				{ targets.length > 1 && (
					<div className="topology-field-row">
						<span className="topology-field-row__key">also →</span>
						<NodeLinks
							names={ targets.slice( 1 ).map( ( t ) => t.to ) }
							nodeIds={ nodeIds }
							onSelect={ onSelect }
							onHover={ onHover }
						/>
					</div>
				) }
				<FieldRow
					k="sink ↦"
					v={ node.sink !== undefined ? node.sink : '—' }
					vClass="topology-field-row__val--dim"
				/>
				<div className="topology-field-row">
					<span className="topology-field-row__key">← from</span>
					<NodeLinks
						names={ sources.map( ( s ) => s.from ) }
						nodeIds={ nodeIds }
						onSelect={ onSelect }
						onHover={ onHover }
					/>
				</div>
			</Section>

			<Section title="Throughput" meta="cumulative">
				<FieldRow
					k="counter"
					v={
						node.count !== undefined
							? node.count.toLocaleString()
							: '—'
					}
					vClass="topology-field-row__val--num"
				/>
				<FieldRow
					k="rate"
					v={ formatRate( rateInfo?.rate ) }
					vClass={
						rateInfo && rateInfo.rate > 0
							? 'topology-field-row__val--num'
							: 'topology-field-row__val--num topology-field-row__val--dim'
					}
				/>
				<FieldRow
					k="lgst_msg"
					v={ formatBytes( node.lgstMsg || 0 ) }
					vClass={
						node.lgstMsg
							? 'topology-field-row__val--num'
							: 'topology-field-row__val--num topology-field-row__val--dim'
					}
				/>
				<FieldRow
					k="read"
					v={ formatBytes( node.bytesRead || 0 ) }
					vClass={
						node.bytesRead
							? 'topology-field-row__val--num'
							: 'topology-field-row__val--num topology-field-row__val--dim'
					}
				/>
				<FieldRow
					k="written"
					v={ formatBytes( node.bytesWritten || 0 ) }
					vClass={
						node.bytesWritten
							? 'topology-field-row__val--num'
							: 'topology-field-row__val--num topology-field-row__val--dim'
					}
				/>
				<FieldRow
					k="last_seen"
					v={ formatLastSeen( rateInfo?.lastChangedTs, live ) }
					vClass={
						rateInfo && rateInfo.rate > 0
							? 'topology-field-row__val--right'
							: 'topology-field-row__val--right topology-field-row__val--dim'
					}
				/>
			</Section>

			{ uptime && (
				<Section title="Process" meta="worker">
					<FieldRow
						k="uptime"
						v={ uptime }
						vClass="topology-field-row__val--num"
					/>
				</Section>
			) }

			<div className="topology-insp__actions">
				<button
					type="button"
					onClick={ () => onAction && onAction( 'dump', node.id ) }
					disabled={ ! live }
					title="Send `dump_node <name>` to the worker"
				>
					Dump
				</button>
				<button
					type="button"
					onClick={ () => {
						// eslint-disable-next-line no-alert
						const payload = window.prompt(
							`Send bytes to ${ node.id }:`,
							''
						);
						if ( payload !== null && payload !== '' ) {
							if ( onAction ) {
								onAction( 'send', node.id, payload );
							}
						}
					} }
					disabled={ ! live }
					title="Send a TM_BYTESTREAM payload to this node via `send_node <name> <bytes>`"
				>
					Send
				</button>
				<button
					type="button"
					className={ `topology-insp__actions-full${
						traceOn ? ' is-active' : ''
					}` }
					onClick={ () =>
						onAction &&
						onAction( 'trace', node.id, traceOn ? 0 : 1 )
					}
					disabled={ ! live }
					title={
						traceOn
							? 'Stop tracing — `debug_state <name> 0`'
							: 'Start tracing — `debug_state <name> 1`'
					}
				>
					{ traceOn ? 'Stop Trace' : 'Trace' }
				</button>
				{ type === 'Tee' && (
					<button
						type="button"
						className={ `topology-insp__actions-full${
							tailOn ? ' is-active' : ''
						}` }
						onClick={ () =>
							onAction &&
							onAction( tailOn ? 'disconnect' : 'tail', node.id )
						}
						disabled={ ! live }
						title={
							tailOn
								? 'Disconnect this session from the Tee — `disconnect_node <name>`'
								: 'Connect this session to the Tee — `connect_node <name>` (its output then flows into the transcript)'
						}
					>
						{ tailOn ? 'Disconnect' : 'Connect' }
					</button>
				) }
			</div>
		</aside>
	);
}
