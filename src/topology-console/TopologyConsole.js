/**
 * TopologyConsole — top-level shell.
 *
 * Wires:
 *   useTopologyStream → SSE subscription
 *   parseLsOutput     → derive {nodes, edges} from msg payloads
 *   Header            → topology/partition selectors + LIVE LED
 *   Palette           → static class catalog (inert in v1)
 *   CanvasFrame       → plotter chrome (meta + reticles + title block)
 *   SchematicCanvas   → SVG node graph
 *   Inspector         → selected-node detail pane
 *   ReplFooter        → prompt + status line
 */

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import CanvasFrame from './components/CanvasFrame';
import Header from './components/Header';
import Inspector from './components/Inspector';
import Palette from './components/Palette';
import ReplFooter from './components/ReplFooter';
import SchematicCanvas from './components/SchematicCanvas';

import { useTopologyStream } from './hooks/useTopologyStream';
import { parseLsOutput } from './utils/parseLsOutput';
import { shellInterpret } from './utils/shellInterpret';

const TOPOLOGIES = [
	'firehose-workers',
	'request-workers',
	'job-workers',
	'aggregator',
];

// Default to 4 partitions for the selector. The actual live count
// comes from NewspackNodesData.numPartitions if the admin page wires
// it in; this is a sane fallback for the v1 visual.
const DEFAULT_PARTITIONS = 4;

function partitionList() {
	const n =
		( window.NewspackNodesData &&
			window.NewspackNodesData.numPartitions ) ||
		DEFAULT_PARTITIONS;
	return Array.from( { length: n }, ( _, i ) => i );
}

const TRANSCRIPT_MAX = 200;

// Message TYPE bitmask flags, mirroring substrate's class-message.php
// constants so we can apply Dumper-style type-aware rendering to each
// incoming SSE msg envelope.
const TM_BYTESTREAM = 1;
const TM_EOF = 2;
const TM_PING = 4;
const TM_COMMAND = 8;
const TM_RESPONSE = 16;
const TM_ERROR = 32;
const TM_INFO = 64;
const TM_STRUCT = 256;

// eslint-disable-next-line no-bitwise
const has = ( type, flag ) => ( type & flag ) !== 0;

/**
 * Convert a raw SSE msg envelope into a transcript entry, following the
 * cli Dumper's render rules so the GUI surfaces what the terminal would:
 *
 *   - TM_EOF                       → dropped (control marker, no output)
 *   - TM_COMMAND|TM_RESPONSE       → payload only, never the wrapper JSON
 *   - TM_COMMAND|TM_ERROR          → "ERROR: <payload>", error styling
 *   - TM_ERROR                     → "ERROR: <value>"
 *   - TM_PING                      → "round trip time: X ms"
 *   - TM_STRUCT                    → JSON-encoded value
 *   - TM_INFO                      → "INFO[from]: <value>"
 *   - default (TM_BYTESTREAM)      → value as-is
 *
 * Returns null when the message should be dropped silently.
 *
 * @param {Object} msg Raw SSE msg envelope (type, from, to, value, ...).
 * @return {Object|null} { kind, text } transcript entry or null to drop.
 */
function dumperRender( msg ) {
	const type = typeof msg.type === 'number' ? msg.type : 0;
	const value = msg.value;
	if ( has( type, TM_EOF ) ) {
		return null;
	}
	const unwrapPayload = () => {
		if ( value && typeof value === 'object' ) {
			return typeof value.payload === 'string' ? value.payload : '';
		}
		return typeof value === 'string' ? value : '';
	};
	if ( has( type, TM_COMMAND ) && has( type, TM_RESPONSE ) ) {
		const payload = unwrapPayload();
		if ( ! payload ) {
			return null;
		}
		return { kind: 'recv', text: payload };
	}
	if ( has( type, TM_COMMAND ) && has( type, TM_ERROR ) ) {
		return { kind: 'error', text: 'ERROR: ' + unwrapPayload() };
	}
	if ( has( type, TM_ERROR ) ) {
		return { kind: 'error', text: 'ERROR: ' + String( value ?? '' ) };
	}
	if ( has( type, TM_PING ) ) {
		const sent = parseFloat( value );
		const now = Date.now() / 1000;
		const rtt = ( ( now - sent ) * 1000 ).toFixed( 2 );
		return { kind: 'info', text: `round trip time: ${ rtt } ms` };
	}
	if ( has( type, TM_STRUCT ) ) {
		return {
			kind: 'recv',
			text:
				typeof value === 'string'
					? value
					: JSON.stringify( value, null, 2 ),
		};
	}
	if ( has( type, TM_INFO ) ) {
		const from = msg.from || '?';
		return { kind: 'info', text: `INFO[${ from }]: ${ value }` };
	}
	if ( has( type, TM_BYTESTREAM ) ) {
		return { kind: 'recv', text: String( value ?? '' ) };
	}
	return null;
}

export default function TopologyConsole() {
	const [ topology, setTopology ] = useState( TOPOLOGIES[ 0 ] );
	const [ partition, setPartition ] = useState( 0 );
	const [ selectedId, setSelectedId ] = useState( null );
	const [ parsed, setParsed ] = useState( { nodes: [], edges: [] } );
	const [ transcript, setTranscript ] = useState( [] );

	const partitions = useMemo( partitionList, [] );
	const { status, lastMessage, ssePid } = useTopologyStream(
		topology,
		partition
	);

	const appendTranscript = useCallback( ( entry ) => {
		setTranscript( ( prev ) => {
			const next = prev.concat( {
				...entry,
				key: `${ Date.now() }-${ Math.random()
					.toString( 36 )
					.slice( 2, 7 ) }`,
			} );
			return next.length > TRANSCRIPT_MAX
				? next.slice( next.length - TRANSCRIPT_MAX )
				: next;
		} );
	}, [] );

	const sendLine = useCallback(
		( line ) => {
			const interpreted = shellInterpret( line );
			if ( ! interpreted ) {
				return;
			}
			// Echo the user's input verbatim so they see what was
			// dispatched. Sigil styling distinguishes outgoing from
			// responses in the transcript.
			appendTranscript( { kind: 'sent', text: line.trim() } );

			if ( interpreted.kind === 'error' ) {
				appendTranscript( { kind: 'error', text: interpreted.text } );
				return;
			}
			if ( interpreted.kind === 'local' ) {
				if ( interpreted.name === 'clear' ) {
					setTranscript( [] );
				} else if ( interpreted.name === 'help' ) {
					appendTranscript( {
						kind: 'info',
						text: interpreted.text,
					} );
				} else if ( interpreted.name === 'debug_level' ) {
					appendTranscript( {
						kind: 'info',
						text:
							interpreted.level === null
								? 'debug_level: (no local Dumper yet — try `cmd _command_interpreter debug_state <n>` to set the worker-side level)'
								: `debug_level: ${ interpreted.level } (frontend acknowledged; full local Dumper lands in v0.3)`,
					} );
				}
				return;
			}
			// kind === 'post'
			if ( ! ssePid ) {
				appendTranscript( {
					kind: 'error',
					text: '[no sse_pid yet] retry once CONNECTED',
				} );
				return;
			}
			apiFetch( {
				path: `/newspack-event-logger-nodes/v1/topology/${ encodeURIComponent(
					topology
				) }/p${ encodeURIComponent( partition ) }/command`,
				method: 'POST',
				data: { ...interpreted.body, sse_pid: ssePid },
			} ).catch( ( err ) => {
				appendTranscript( {
					kind: 'error',
					text: `[POST failed] ${
						( err && err.message ) || 'network error'
					}`,
				} );
			} );
		},
		[ topology, partition, ssePid, appendTranscript ]
	);

	// Reset selection + graph + transcript when the (topology, partition)
	// pair changes — we're effectively starting a fresh REPL session.
	useEffect( () => {
		setSelectedId( null );
		setParsed( { nodes: [], edges: [] } );
		setTranscript( [] );
	}, [ topology, partition ] );

	// Route every incoming msg by its KEY:
	//   key = 'gui:auto'  → response to one of our own SSE-controller polls;
	//                       feed it to the canvas-parse and never the transcript.
	//   key = '' (empty)  → either a user-typed command's response or an
	//                       async broadcast (debug traces, etc.); show it in
	//                       the transcript verbatim.
	//
	// CommandInterpreter copies KEY from each TM_COMMAND request onto its
	// TM_RESPONSE, so the round-trip correlation is automatic.
	//
	// Substrate envelope shapes we handle:
	//   value: "COUNT ..."                                  — raw bytestream payload
	//   value: { name: "ls", payload: "COUNT ..." }         — command-response struct
	useEffect( () => {
		if ( ! lastMessage ) {
			return;
		}
		const value = lastMessage.value;
		let text = null;
		if ( typeof value === 'string' ) {
			text = value;
		} else if (
			value &&
			typeof value === 'object' &&
			typeof value.payload === 'string'
		) {
			text = value.payload;
		}
		if ( ! text ) {
			return;
		}
		const isOurPoll = lastMessage.key === 'gui:auto';
		if ( ! isOurPoll ) {
			// User-typed command response, or an async broadcast. Run it
			// through the Dumper-style renderer so each message type gets
			// its appropriate framing — TM_COMMAND|TM_RESPONSE shows the
			// unwrapped payload, TM_ERROR variants get an "ERROR: " prefix,
			// TM_INFO carries the FROM tag, etc.
			const rendered = dumperRender( lastMessage );
			if ( rendered ) {
				appendTranscript( {
					...rendered,
					text: rendered.text.replace( /\n+$/, '' ),
				} );
			}
			return;
		}
		// gui:auto polls only ever emit ls table data. If something else
		// snuck through, drop it silently rather than misparse.
		if ( ! /^COUNT\b/m.test( text ) ) {
			return;
		}
		const next = parseLsOutput( text );
		// `ls -als` (initial) carries the SINK column. Subsequent `ls -ct`
		// counter refreshes don't — so merge the prior parse's sink data
		// into nodes that came back without one, keyed by id.
		setParsed( ( prev ) => {
			const priorSinks = new Map();
			for ( const n of prev.nodes ) {
				if ( n.sink !== undefined ) {
					priorSinks.set( n.id, n.sink );
				}
			}
			return {
				nodes: next.nodes.map( ( n ) =>
					n.sink !== undefined || ! priorSinks.has( n.id )
						? n
						: { ...n, sink: priorSinks.get( n.id ) }
				),
				edges: next.edges,
			};
		} );
	}, [ lastMessage, appendTranscript ] );

	return (
		<div className="topology-app">
			<Header
				topologies={ TOPOLOGIES }
				topology={ topology }
				onTopologyChange={ setTopology }
				partitions={ partitions }
				partition={ partition }
				onPartitionChange={ setPartition }
				streamStatus={ status }
			/>
			<Palette />
			<CanvasFrame topology={ topology } partition={ partition }>
				<SchematicCanvas
					parsed={ parsed }
					selectedId={ selectedId }
					onSelect={ setSelectedId }
					onDeselect={ () => setSelectedId( null ) }
				/>
			</CanvasFrame>
			<Inspector
				selectedId={ selectedId }
				parsed={ parsed }
				streamStatus={ status }
			/>
			<ReplFooter
				topology={ topology }
				partition={ partition }
				streamStatus={ status }
				canSend={ status === 'open' && !! ssePid }
				onSubmit={ sendLine }
				onClear={ () => setTranscript( [] ) }
				transcript={ transcript }
			/>
		</div>
	);
}
