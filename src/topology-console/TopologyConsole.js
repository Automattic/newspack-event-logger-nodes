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

import { useEffect, useMemo, useState } from '@wordpress/element';

import CanvasFrame from './components/CanvasFrame';
import Header from './components/Header';
import Inspector from './components/Inspector';
import Palette from './components/Palette';
import ReplFooter from './components/ReplFooter';
import SchematicCanvas from './components/SchematicCanvas';

import { useTopologyStream } from './hooks/useTopologyStream';
import { parseLsOutput } from './utils/parseLsOutput';

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

export default function TopologyConsole() {
	const [ topology, setTopology ] = useState( TOPOLOGIES[ 0 ] );
	const [ partition, setPartition ] = useState( 0 );
	const [ selectedId, setSelectedId ] = useState( null );
	const [ parsed, setParsed ] = useState( { nodes: [], edges: [] } );

	const partitions = useMemo( partitionList, [] );
	const { status, lastMessage } = useTopologyStream( topology, partition );

	// Reset selection + graph when the (topology, partition) pair changes.
	useEffect( () => {
		setSelectedId( null );
		setParsed( { nodes: [], edges: [] } );
	}, [ topology, partition ] );

	// Re-parse on every new msg envelope that looks like an ls table.
	useEffect( () => {
		if ( ! lastMessage ) {
			return;
		}
		const value = lastMessage.value;
		if ( typeof value !== 'string' ) {
			return;
		}
		// `ls -al` and `ls -ct` output starts with a COUNT header.
		if ( ! /^COUNT\b/m.test( value ) ) {
			return;
		}
		setParsed( parseLsOutput( value ) );
	}, [ lastMessage ] );

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
			/>
		</div>
	);
}
