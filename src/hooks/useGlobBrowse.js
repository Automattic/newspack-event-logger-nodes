/**
 * useGlobBrowse — the partition PICK a glob subscription needs, on top of the
 * substrate's segment browsing.
 *
 * The browse model over a glob is two-level:
 *   - selectedPartition '' → the glob, tailed live. The sidebar shows the
 *     partition picker alone, with no segment rail.
 *   - selectedPartition 'errors.p3' → that ONE dir, with the rail and Live /
 *     Replay / per-segment seeks — which is exactly `useSegmentBrowse`.
 *
 * A sole partition auto-selects, because one dir already IS the whole live glob.
 *
 * Seeks ride the existing `positions` transport (keyed by partition DIRECTORY,
 * the same key SseIn tracks resume offsets under), so no new server verb is
 * needed. The substrate's `raw-logs` CI catalogs the concrete dirs with
 * `list_logs` and one dir's segments with `log_status`. Each verb gets its OWN
 * awaitable node, so every reply lands on the node that asked for it and nothing
 * needs correlating.
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { useNodeState } from '@newspack-nodes/runtime';
import {
	useSegmentBrowse,
	useLogStatusSegments,
} from '@newspack-nodes/shared/hooks/useLogPositions';
import { LIVE } from '@newspack-nodes/shared/nodes/seekTracker';
import { useLogCatalog } from '@newspack-nodes/shared/hooks/useStreamGraph';
import { egressPath } from '@newspack-nodes/shared/helpers/egressPath';

// The substrate service CI that catalogs the on-disk logs.
const RAW_LOGS = 'raw-logs';

/**
 * @param {Object}     o       Hook options.
 * @param {string}     o.glob  The subscription glob (e.g. `errors.*`).
 * @param {Object}     o.graph The `useStreamGraph` handle this browses.
 * @param {() => void} o.step  Deliver one record while paused.
 * @return {{ partitions: Object[], selectedPartition: string,
 *   selectPartition: (key: string) => void, jump: (text: string) => void,
 *   sidebar: import('react').ReactElement }}
 *   The picker's rows and selection, the offset-jump handler, and the rail.
 *   `partitions` are the `list_logs` rows (`{ key, label, … }`) narrowed to the
 *   glob.
 */
export default function useGlobBrowse( { glob, graph, step } ) {
	const globPrefix = glob.endsWith( '*' ) ? glob.slice( 0, -1 ) : glob;
	const { prefix, control, resubscribe, seek, setPaused } = graph;
	const viewName = `${ prefix }:view`;

	const [ selectedPartition, setSelectedPartition ] = useState( '' );

	// The selection, read without re-arming the auto-select effect.
	const selectedRef = useRef( selectedPartition );
	selectedRef.current = selectedPartition;

	// View-derived seek model (mode + received segment), NOT the click state.
	const viewModel = useNodeState( viewName, 'view' );

	// The glob's concrete partition dirs, narrowed out of the polled catalog.
	const inGlob = useCallback(
		( l ) => 'string' === typeof l?.key && l.key.startsWith( globPrefix ),
		[ globPrefix ]
	);
	const partitions = useLogCatalog( {
		prefix,
		command: 'list_logs',
		target: egressPath( RAW_LOGS ),
		keep: inGlob,
	} );

	const { source, refresh } = useLogStatusSegments( {
		sub: selectedPartition,
		scope: `${ viewName }:status`,
	} );

	// Switch partition: reset+arm the view's seek (dir), or widen to glob ('').
	const selectPartition = useCallback(
		( key ) => {
			setSelectedPartition( key );
			control( { action: 'select', dir: key } );
			resubscribe( key ? [ key ] : [ glob ], null );
		},
		[ glob, control, resubscribe ]
	);

	// A sole partition auto-selects: one dir IS the whole live glob.
	const selectPartitionRef = useRef( selectPartition );
	selectPartitionRef.current = selectPartition;
	useEffect( () => {
		if ( 1 === partitions.length && '' === selectedRef.current ) {
			selectPartitionRef.current( partitions[ 0 ].key );
		}
	}, [ partitions ] );

	const { jump, sidebar } = useSegmentBrowse( {
		sub: selectedPartition,
		source,
		refresh,
		railName: `${ viewName }:segments`,
		mode: viewModel?.mode ?? LIVE,
		lastReceivedSegment: viewModel?.lastReceivedSegment ?? null,
		seek,
		setPaused,
		step,
	} );

	return { partitions, selectedPartition, selectPartition, jump, sidebar };
}
