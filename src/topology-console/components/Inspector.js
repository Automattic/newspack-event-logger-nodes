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
	const type = inferType( node.id );
	const live = streamStatus === 'open';

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
					k="last_seen"
					v={ formatLastSeen( rateInfo?.lastChangedTs, live ) }
					vClass={
						rateInfo && rateInfo.rate > 0
							? 'topology-field-row__val--right'
							: 'topology-field-row__val--right topology-field-row__val--dim'
					}
				/>
			</Section>

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
					className="topology-insp__actions-full"
					onClick={ () => onAction && onAction( 'trace', node.id ) }
					disabled={ ! live }
					title="Enable state-transition logging via `debug_state <name> 1` — events broadcast to listeners as TM_INFO"
				>
					Trace
				</button>
				{ type === 'Tee' && (
					<>
						<button
							type="button"
							className="topology-insp__actions-full"
							onClick={ () =>
								onAction && onAction( 'tail', node.id )
							}
							disabled={ ! live }
							title="Tee this node's output into the transcript via `connect_node <name>` (default target = this session)"
						>
							Tail
						</button>
						<button
							type="button"
							className="topology-insp__actions-full"
							onClick={ () =>
								onAction && onAction( 'disconnect', node.id )
							}
							disabled={ ! live }
							title="Stop tailing via `disconnect_node <name>` (default target = this session)"
						>
							Disconnect
						</button>
					</>
				) }
			</div>
		</aside>
	);
}
