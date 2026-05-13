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
				<FieldRow
					k="target →"
					v={ targets[ 0 ] ? targets[ 0 ].to : '—' }
					vClass={
						targets[ 0 ]
							? 'topology-field-row__val--link'
							: 'topology-field-row__val--dim'
					}
				/>
				{ targets.length > 1 && (
					<FieldRow
						k="also →"
						v={ targets
							.slice( 1 )
							.map( ( t ) => t.to )
							.join( ', ' ) }
						vClass="topology-field-row__val--link"
					/>
				) }
				<FieldRow
					k="sink ↦"
					v={ node.sink !== undefined ? node.sink : '—' }
					vClass={
						node.sink !== undefined
							? 'topology-field-row__val--dim'
							: 'topology-field-row__val--dim'
					}
				/>
				<FieldRow
					k="← from"
					v={
						sources.length
							? sources.map( ( s ) => s.from ).join( ', ' )
							: '—'
					}
					vClass={
						sources.length
							? 'topology-field-row__val--link'
							: 'topology-field-row__val--dim'
					}
				/>
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
					disabled
					title="v0.3 (needs payload prompt)"
				>
					Send
				</button>
				{ type === 'TEE' && (
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
				<button
					type="button"
					className="topology-insp__actions-full topology-insp__actions-danger"
					disabled
					title="EDIT mode only (v0.3) — `remove_node <name>`"
				>
					Remove
				</button>
			</div>
		</aside>
	);
}
