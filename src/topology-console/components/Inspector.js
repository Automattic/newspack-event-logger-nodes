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

export default function Inspector( { selectedId, parsed, streamStatus } ) {
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
					k="sink →"
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
					v="— /s"
					vClass="topology-field-row__val--dim"
				/>
				<FieldRow
					k="dropped"
					v="—"
					vClass="topology-field-row__val--dim"
				/>
			</Section>

			<Section title="Process">
				<FieldRow
					k="memory"
					v="—"
					vClass="topology-field-row__val--dim"
				/>
				<FieldRow
					k="uptime"
					v="—"
					vClass="topology-field-row__val--dim"
				/>
				<FieldRow
					k="last_seen"
					v={ live ? 'streaming' : streamStatus }
					vClass={ live ? '' : 'topology-field-row__val--dim' }
				/>
			</Section>

			<div className="topology-insp__actions">
				<button type="button" disabled>
					Dump
				</button>
				<button type="button" disabled>
					Send
				</button>
				<button type="button" disabled>
					Tail
				</button>
				<button type="button" disabled>
					Trace
				</button>
				<button
					type="button"
					className="topology-insp__actions-full"
					disabled
				>
					Open in REPL
				</button>
				<button
					type="button"
					className="topology-insp__actions-full topology-insp__actions-danger"
					disabled
				>
					Disconnect
				</button>
			</div>
		</aside>
	);
}
