/**
 * SegmentBrowseSidebar — the shared Kafka-UI browse rail for the glob-subscribed
 * ELN dashboards (Error Log, Request Log). Given a `useGlobBrowse` model it renders
 * the partition picker and, once a partition is selected, the substrate's
 * `LogBrowser` segment list (Live / Replay / per-segment seeks). Both dashboards
 * pass this ONE component as their `LogStreamViewer` sidebar instead of
 * copy-pasting the picker, the LogBrowser wiring, and a byte formatter.
 *
 * The picker takes two shapes. Several partitions get a <select> whose empty
 * option widens the subscription back to the whole glob; a sole partition gets a
 * static label instead, because `useGlobBrowse` auto-selects it and "All
 * partitions" would then name the same thing twice.
 *
 * The component gates itself: an unmounted graph or an empty catalog renders
 * null, and the segment list appears only once a partition is selected.
 */

import { __, sprintf } from '@wordpress/i18n';
import LogBrowser from '@newspack-nodes/shared/components/LogBrowser';
import { formatBytes } from '@newspack-nodes/shared/utils/formatters';
import './SegmentBrowseSidebar.scss';

/**
 * @param {Object}                props                   Component props.
 * @param {Object}                [props.browse]          The `useGlobBrowse`
 *                                                        return — the partition
 *                                                        catalog, the selection,
 *                                                        the segments, the
 *                                                        view-derived mode, and
 *                                                        the seek actions
 *                                                        (`follow`, `replay`,
 *                                                        `browseSegment`).
 *                                                        Undefined before the
 *                                                        graph mounts → renders
 *                                                        null.
 * @param {(key: string) => void} props.onSelectPartition Receives the chosen
 *                                                        partition key, or ''
 *                                                        for the whole glob.
 *                                                        Both dashboards forward
 *                                                        it to
 *                                                        `browse.selectPartition`.
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

			{ selectedPartition && (
				<LogBrowser
					mode={ browse.mode }
					onFollow={ browse.follow }
					onReplay={ browse.replay }
					items={ browse.segments }
					selectedKey={ browse.segmentId }
					activeKey={ browse.lastReceivedSegment }
					onSelectItem={ browse.browseSegment }
					itemKey={ ( s ) => s.id }
					itemLabel={ ( s ) =>
						sprintf(
							// translators: %d: log segment number.
							__( 'Segment %d', 'newspack-event-logger-nodes' ),
							s.id
						)
					}
					itemMeta={ ( s ) => formatBytes( s.size ) }
					title={ __( 'Segments', 'newspack-event-logger-nodes' ) }
					emptyLabel={ __(
						'No segments',
						'newspack-event-logger-nodes'
					) }
				/>
			) }
		</div>
	);
}
