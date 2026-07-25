/**
 * SegmentBrowseSidebar — the shared Kafka-UI browse rail for the glob-subscribed
 * ELN dashboards (Error Log, Request Log). Given a `useGlobBrowse` model it renders
 * the partition <select> plus, once a partition is picked, the substrate's
 * `LogBrowser` segment list (Live / Replay / per-segment seeks). Both dashboards
 * render this ONE component instead of copy-pasting the select + LogBrowser + a
 * byte formatter; it self-gates so the parent just drops it into the body.
 *
 * @param {Object}   props
 * @param {Object}   [props.browse]          The `useGlobBrowse` return (undefined
 *                                           before the graph mounts → renders null).
 * @param {Function} props.onSelectPartition `(key) => void` — the parent's handler
 *                                           (browse.selectPartition + row rebase).
 * @return {import('react').ReactElement|null} The rail, or null when no partitions.
 */

import { __, sprintf } from '@wordpress/i18n';
import LogBrowser from '@newspack-nodes/shared/components/LogBrowser';
import formatBytes from '@newspack-nodes/shared/utils/formatBytes';
import './SegmentBrowseSidebar.scss';

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
					className="event-logger-browse-rail__single"
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
