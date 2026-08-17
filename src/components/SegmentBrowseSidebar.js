/**
 * SegmentBrowseSidebar — the partition PICKER a glob-subscribed dashboard (Error
 * Log, Request Log) puts above the substrate's segment rail. Both dashboards
 * pass this ONE component as their `LogStreamViewer` sidebar.
 *
 * The picker takes two shapes. Several partitions get a <select> whose empty
 * option widens the subscription back to the whole glob; a sole partition gets a
 * static label instead, because `useGlobBrowse` auto-selects it and "All
 * partitions" would then name the same thing twice.
 *
 * The component gates itself: an unmounted graph or an empty catalog renders
 * null, and the rail appears only once a partition is selected.
 */

import { __ } from '@wordpress/i18n';
import './SegmentBrowseSidebar.scss';

/**
 * @param {Object}                props                   Component props.
 * @param {Object}                [props.browse]          The `useGlobBrowse`
 *                                                        return — the partition
 *                                                        catalog, the selection
 *                                                        and the rail. Undefined
 *                                                        before the graph mounts
 *                                                        → renders null.
 * @param {(key: string) => void} props.onSelectPartition Receives the chosen
 *                                                        partition key, or ''
 *                                                        for the whole glob.
 * @return {import('react').ReactElement|null} The rail, or null when the browse
 *                                             model lists no partitions.
 */
export default function SegmentBrowseSidebar( { browse, onSelectPartition } ) {
	const partitions = browse?.partitions ?? [];
	if ( 0 === partitions.length ) {
		return null;
	}
	const selectedPartition = browse.selectedPartition ?? '';
	// One dir: useGlobBrowse auto-selects it; show a label, not a dropdown.
	const single = 1 === partitions.length;

	return (
		<div className="event-logger-browse-rail">
			{ single ? (
				<div
					className="event-logger-browse-rail__single newspack-nodes-status"
					title={ __(
						'The only partition',
						'newspack-event-logger-nodes'
					) }
				>
					{ partitions[ 0 ].label || partitions[ 0 ].key }
				</div>
			) : (
				<select
					className="newspack-nodes-select"
					value={ selectedPartition }
					onChange={ ( e ) => onSelectPartition( e.target.value ) }
					title={ __(
						'Browse a partition',
						'newspack-event-logger-nodes'
					) }
				>
					<option value="">
						{ __(
							'All partitions (live)',
							'newspack-event-logger-nodes'
						) }
					</option>
					{ partitions.map( ( p ) => (
						<option key={ p.key } value={ p.key }>
							{ p.label || p.key }
						</option>
					) ) }
				</select>
			) }

			{ selectedPartition && browse.sidebar }
		</div>
	);
}
