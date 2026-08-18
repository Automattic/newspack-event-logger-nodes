/**
 * useGlobBrowse — the partition PICK a glob subscription needs, on top of the
 * substrate's segment browsing.
 *
 * The browse model over a glob is two-level:
 *   - selectedPartition '' → the glob, tailed live: the toolbar picker's empty
 *     row, and no segment rail.
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

import {
	useState,
	useLayoutEffect,
	useMemo,
	useRef,
	useCallback,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
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
 * @return {{ pickerOptions: Object[], pickerLabel: string,
 *   selectedPartition: string, selectPartition: (key: string) => void,
 *   jump: ?(text: string) => void, sidebar: ?import('react').ReactElement }}
 *   The toolbar picker, and — only once a dir is selected — the segment rail
 *   and the offset jump into it.
 */
export default function useGlobBrowse( { glob, graph, step } ) {
	const globPrefix = glob.endsWith( '*' ) ? glob.slice( 0, -1 ) : glob;
	const { prefix, control, resubscribe, seek, setPaused } = graph;
	const viewName = `${ prefix }:view`;

	const [ selectedPartition, setSelectedPartition ] = useState( '' );

	// The selection, kept out of the auto-select effect's dependencies.
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

	// @longform
	// A sole partition auto-selects, before paint: one dir IS the live glob,
	// and a passive effect would paint the picker at a value matching no row
	// first. Layout effects run before the graph's own mount effect, so this
	// leans on the catalog never arriving populated on a first render — it
	// only ever fills from a command reply, and a rebuild clears the node's
	// state cache. A catalog hoisted above this dashboard would break that.
	useLayoutEffect( () => {
		if ( 1 === partitions.length && '' === selectedRef.current ) {
			selectPartition( partitions[ 0 ].key );
		}
	}, [ partitions, selectPartition ] );

	// The empty row widens back to the glob; a sole dir gets none.
	const pickerOptions = useMemo( () => {
		if ( partitions.length < 2 ) {
			return partitions;
		}
		// Widens the subscription back to the whole glob.
		const all = {
			key: '',
			label: __( 'All partitions (live)', 'newspack-event-logger-nodes' ),
		};
		return [ all, ...partitions ];
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

	// No dir means nothing to browse WITHIN: no rail, and no jump into one.
	return {
		pickerOptions,
		pickerLabel: __( 'Browse a partition', 'newspack-event-logger-nodes' ),
		selectedPartition,
		selectPartition,
		jump: selectedPartition ? jump : undefined,
		sidebar: selectedPartition ? sidebar : null,
	};
}
