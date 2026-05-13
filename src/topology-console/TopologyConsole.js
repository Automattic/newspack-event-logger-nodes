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

import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import CanvasFrame from './components/CanvasFrame';
import Header from './components/Header';
import Inspector from './components/Inspector';
import ReplFooter from './components/ReplFooter';
import SchematicCanvas from './components/SchematicCanvas';

import { useTopologyStream } from './hooks/useTopologyStream';
import { parseMetadata } from './utils/parseMetadata';
import { shellInterpret, SHELL_BUILTINS_BLURB } from './utils/shellInterpret';

const TOPOLOGIES = [
	'firehose-workers',
	'request-workers',
	'job-workers',
	'aggregator',
];

// Per-topology partition counts, injected by the admin page via
// NewspackNodesData.topologyPartitions (filled from the
// `newspack_nodes/topologies` filter — the same map the supervisor
// uses to spawn workers, so the dropdown can't drift). Fallback to a
// single partition for any topology the map doesn't cover.
function partitionList( topology ) {
	const map =
		( window.NewspackNodesData &&
			window.NewspackNodesData.topologyPartitions ) ||
		{};
	const n = map[ topology ] || 1;
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
	// Hover state — lifted so the Inspector can highlight a node by
	// hovering one of its routing-list links (target/also/from).
	const [ hoveredId, setHoveredId ] = useState( null );
	const [ parsed, setParsed ] = useState( { nodes: [], edges: [] } );
	const [ transcript, setTranscript ] = useState( [] );
	// Lifted: ReplFooter's transcript visibility, so Inspector actions
	// (Dump, Tail) can pop the pane open when they fire commands the
	// user wants to see the response of.
	const [ replExpanded, setReplExpanded ] = useState( false );

	// Per-node rate tracking: { id: { count, ts, rate, lastChangedTs } }
	// Updated each time a `gui:auto` ls table arrives. rate = Δcount/Δs
	// across consecutive ticks; lastChangedTs marks the last tick where
	// count grew so the Inspector can render "Xs ago" without polling.
	const rateRef = useRef( new Map() );
	const [ rateVersion, setRateVersion ] = useState( 0 );

	// Worker uptime, refreshed by `gui:uptime` polls every UPTIME_INTERVAL_S
	// (5s). Substrate's `uptime` verb returns one line like
	// `HH:MM:SS  up N days, HH:MM:SS\n` — we keep just the days/HMS half for
	// the Inspector's Process section.
	const [ uptime, setUptime ] = useState( null );

	// User-pinned positions, keyed by node name. Survives reloads via
	// localStorage; scoped per `topology.partition` so positions don't
	// bleed between worker types. Loaded on topology/partition change;
	// updates write back synchronously on each drag commit.
	const positionStorageKey = `newspack-nodes:topology:${ topology }.p${ partition }:positions`;
	const [ positionOverrides, setPositionOverrides ] = useState( {} );
	useEffect( () => {
		try {
			const raw = window.localStorage.getItem( positionStorageKey );
			setPositionOverrides( raw ? JSON.parse( raw ) : {} );
		} catch ( _err ) {
			setPositionOverrides( {} );
		}
	}, [ positionStorageKey ] );
	const handlePositionChange = useCallback(
		( nodeId, pos ) => {
			setPositionOverrides( ( prev ) => {
				const next = { ...prev, [ nodeId ]: pos };
				try {
					window.localStorage.setItem(
						positionStorageKey,
						JSON.stringify( next )
					);
				} catch ( _err ) {
					// localStorage may be disabled / quota'd; silently
					// fall back to in-session-only overrides.
				}
				return next;
			} );
		},
		[ positionStorageKey ]
	);
	const handleResetLayout = useCallback( () => {
		setPositionOverrides( {} );
		try {
			window.localStorage.removeItem( positionStorageKey );
		} catch ( _err ) {
			// Ignore — clearing in-memory state is the important part.
		}
	}, [ positionStorageKey ] );
	const hasOverrides = Object.keys( positionOverrides ).length > 0;

	const partitions = useMemo( () => partitionList( topology ), [ topology ] );

	// If the user switches to a topology with fewer partitions than the
	// one they were on, reset the partition selector to p0 so we don't
	// stream from a non-existent worker.
	useEffect( () => {
		if ( partition >= partitions.length ) {
			setPartition( 0 );
		}
	}, [ partitions, partition ] );

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

	// Reset selection + graph + transcript when the (topology, partition)
	// pair changes — we're effectively starting a fresh REPL session.
	useEffect( () => {
		setSelectedId( null );
		setParsed( { nodes: [], edges: [] } );
		setTranscript( [] );
	}, [ topology, partition ] );

	// Process every incoming SSE msg synchronously. Routing by KEY:
	//   key = 'gui:auto'  → response to one of our own SSE-controller polls;
	//                       feed it to the canvas-parse and never the transcript.
	//   key = '' (empty)  → either a user-typed command's response or an
	//                       async broadcast (debug traces, etc.); show it in
	//                       the transcript verbatim.
	//
	// Synchronous handling is critical: a burst of TM_STRUCT broadcasts
	// could otherwise coalesce React state updates and drop intermediate
	// values (a command response could land BETWEEN auto-fired ls polls
	// and get clobbered by setLastMessage). Callback-based processing
	// guarantees every message is observed.
	//
	// CommandInterpreter copies KEY from each TM_COMMAND request onto its
	// TM_RESPONSE, so the round-trip correlation is automatic.
	const handleMessage = useCallback(
		( msg ) => {
			const value = msg.value;
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
			if ( msg.key === 'gui:uptime' ) {
				// `09:44:52  up 0 days, 00:01:00\n` → keep the right half.
				const match =
					typeof text === 'string'
						? text.match( /up\s+(.+)$/m )
						: null;
				if ( match ) {
					setUptime( match[ 1 ].trim() );
				}
				return;
			}
			const isOurPoll = msg.key === 'gui:auto';
			if ( ! isOurPoll ) {
				// User-typed command response, or an async broadcast. Run
				// it through the Dumper-style renderer so each message
				// type gets its appropriate framing.
				const rendered = dumperRender( msg );
				if ( rendered ) {
					appendTranscript( {
						...rendered,
						text: rendered.text.replace( /\n+$/, '' ),
					} );
				}
				return;
			}
			// gui:auto polls only ever emit `dump_metadata` JSON.
			// `text` is the JSON payload string; let parseMetadata
			// handle malformed input gracefully.
			if ( ! text ) {
				return;
			}
			const next = parseMetadata( text );

			// Update per-node rate + last-changed tracking. Same tick
			// drives both — Δcount/Δs gives the msg/s rate, and a
			// non-zero Δcount marks the node as "live, recently active."
			const now = Date.now() / 1000;
			let touched = false;
			for ( const n of next.nodes ) {
				const prevEntry = rateRef.current.get( n.id );
				if ( prevEntry && prevEntry.ts < now ) {
					const dCount = n.count - prevEntry.count;
					const dTime = now - prevEntry.ts;
					const rate = dTime > 0 ? dCount / dTime : 0;
					rateRef.current.set( n.id, {
						count: n.count,
						ts: now,
						rate,
						lastChangedTs:
							dCount > 0 ? now : prevEntry.lastChangedTs,
					} );
					touched = true;
				} else if ( ! prevEntry ) {
					rateRef.current.set( n.id, {
						count: n.count,
						ts: now,
						rate: 0,
						lastChangedTs: now,
					} );
					touched = true;
				}
			}
			if ( touched ) {
				setRateVersion( ( v ) => v + 1 );
			}

			// dump_metadata is authoritative on every tick — no
			// need to merge sink data across responses the way the
			// old ls -als + ls -ct dance required.
			setParsed( next );
		},
		[ appendTranscript ]
	);

	const { status, ssePid } = useTopologyStream(
		topology,
		partition,
		handleMessage
	);

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
			// `help`: prepend the Shell-builtins blurb so the user sees
			// our local verbs alongside the worker's authoritative
			// server-side list. Mirrors Perl Tachikoma CommandInterpreter::
			// help which prepends `### SHELL BUILTINS ###` from the
			// responder's $shell->help_topics before its own commands.
			if ( interpreted.body.name === 'help' ) {
				appendTranscript( {
					kind: 'info',
					text: SHELL_BUILTINS_BLURB,
				} );
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

	// Inspector action dispatch. Each action maps to a verb the user
	// could have typed at the REPL; routing through sendLine keeps the
	// echo + response visible in the transcript instead of being a
	// silent backchannel.
	const handleInspectorAction = useCallback(
		( action, nodeId, payload ) => {
			if ( action === 'dump' ) {
				sendLine( `dump_node ${ nodeId }` );
			} else if ( action === 'tail' ) {
				sendLine( `connect_node ${ nodeId }` );
			} else if ( action === 'disconnect' ) {
				sendLine( `disconnect_node ${ nodeId }` );
			} else if ( action === 'send' ) {
				sendLine( `send_node ${ nodeId } ${ payload }` );
			} else if ( action === 'trace' ) {
				// payload here is the target level (0 to disable, 1
				// to enable) — Inspector decides based on the current
				// debug_state field from the latest dump_metadata.
				const level = typeof payload === 'number' ? payload : 1;
				sendLine( `debug_state ${ nodeId } ${ level }` );
			}
			// Always pop the transcript open after an Inspector action
			// — the user's expecting to see the worker's reply.
			setReplExpanded( true );
		},
		[ sendLine ]
	);

	// Pull rate info for the selected node. rateVersion is the
	// "something changed in the rate map" signal that drives the
	// useMemo recompute; the actual data lives in rateRef (mutable so
	// hot-path ls -ct ticks don't trigger a full state update per
	// node).
	const selectedRateInfo = useMemo(
		() => ( selectedId ? rateRef.current.get( selectedId ) : null ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ selectedId, rateVersion ]
	);

	return (
		<div
			className={ `topology-app${
				selectedId ? ' is-inspector-open' : ''
			}` }
		>
			<Header
				topologies={ TOPOLOGIES }
				topology={ topology }
				onTopologyChange={ setTopology }
				partitions={ partitions }
				partition={ partition }
				onPartitionChange={ setPartition }
				streamStatus={ status }
				uptime={ uptime }
			/>
			{ /* Palette is a v2 edit-mode affordance (drag node types onto
			the canvas). In v1 we're inspect-only — hiding the pane
			reclaims its 232px column for the canvas. Reintroduce when
			the EDIT button becomes live. */ }
			<CanvasFrame
				topology={ topology }
				partition={ partition }
				onResetLayout={ hasOverrides ? handleResetLayout : null }
			>
				<SchematicCanvas
					parsed={ parsed }
					selectedId={ selectedId }
					onSelect={ setSelectedId }
					positionOverrides={ positionOverrides }
					onPositionChange={ handlePositionChange }
					onDeselect={ () => setSelectedId( null ) }
					hoveredId={ hoveredId }
					onHover={ setHoveredId }
					rateRef={ rateRef }
					rateVersion={ rateVersion }
				/>
			</CanvasFrame>
			{ /* Inspector is only mounted when a node is selected — the
			"Select a node to inspect" empty state was a permanent 308px
			of dead pixels. Selecting any node restores the column via
			the `is-inspector-open` class on `.topology-app`. */ }
			{ selectedId && (
				<Inspector
					selectedId={ selectedId }
					parsed={ parsed }
					streamStatus={ status }
					rateInfo={ selectedRateInfo }
					onAction={ handleInspectorAction }
					onSelect={ setSelectedId }
					onHover={ setHoveredId }
					nodeIds={ new Set( parsed.nodes.map( ( n ) => n.id ) ) }
					ssePid={ ssePid }
					uptime={ uptime }
				/>
			) }
			<ReplFooter
				topology={ topology }
				partition={ partition }
				streamStatus={ status }
				canSend={ status === 'open' && !! ssePid }
				onSubmit={ sendLine }
				onClear={ () => setTranscript( [] ) }
				transcript={ transcript }
				expanded={ replExpanded }
				onExpandedChange={ setReplExpanded }
			/>
		</div>
	);
}
