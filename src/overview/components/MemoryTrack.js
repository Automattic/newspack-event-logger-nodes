/**
 * Peak memory across one request, on the flame graph's own time axis.
 *
 * `Log_Manager` appends `peak_mb` to every `complete()` entry when the
 * `log_memory` setting is on, and the flame's X axis is already milliseconds
 * from the request's start — so the two line up over the same request without
 * either knowing about the other. The chart draws nothing when no entry
 * carried a reading, which is what "the setting is off" looks like from here.
 *
 * What it plots is `memory_get_peak_usage()`, a HIGH-WATER MARK: it never
 * falls, so the shape is a staircase and a step says where memory was CLAIMED,
 * not what was live at that moment. Drawing it as a smooth line would suggest
 * a fall that the reading cannot show.
 *
 * A 0..VIEW_W viewBox with `preserveAspectRatio="none"` is what keeps it in
 * register: the flame fills its container's `clientWidth`, this stretches to
 * the same box, and neither has to measure the other. That distorts anything
 * drawn inside, so every label is HTML positioned over the plot rather than
 * SVG text.
 */

import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import '../styles/memory-track.scss';

/** Viewbox units; the SVG stretches to the container, so these are not pixels. */
const VIEW_W = 1000;
const VIEW_H = 100;
/** Room above the peak so its own gridline is not the top edge. */
const PAD_TOP = 12;
/** Gridline fractions of the peak, floor and ceiling included. */
const GRID_AT = [ 0, 0.5, 1 ];

/**
 * Readings from the entries that carried one, in request order.
 *
 * @param {Array<Object>} entries Log entries.
 * @return {Array<{ms: number, mb: number}>} Readings, oldest first.
 */
const readings = ( entries ) => {
	const rows = ( entries || [] ).filter(
		( e ) => Number.isFinite( Number( e?.peak_mb ) ) && Number( e?.ts ) > 0
	);
	if ( ! rows.length ) {
		return [];
	}
	const start = Math.min(
		...( entries || [] )
			.map( ( e ) => Number( e?.ts ) || Infinity )
			.filter( ( t ) => Number.isFinite( t ) )
	);
	return rows
		.map( ( e ) => ( {
			ms: ( Number( e.ts ) - start ) * 1000,
			mb: Number( e.peak_mb ),
		} ) )
		.sort( ( a, b ) => a.ms - b.ms );
};

/**
 * @param {Object}        props         Component props.
 * @param {Array<Object>} props.entries Log entries for one request.
 * @param {number}        props.totalMs The request's duration, the X domain.
 * @return {import('react').ReactElement|null} The chart, or null with no readings.
 */
export default function MemoryTrack( { entries, totalMs } ) {
	const points = useMemo( () => readings( entries ), [ entries ] );

	const peak = points.reduce( ( max, p ) => Math.max( max, p.mb ), 0 );
	if ( points.length < 2 || peak <= 0 ) {
		return null;
	}

	const span = totalMs > 0 ? totalMs : points[ points.length - 1 ].ms;
	const x = ( ms ) => ( span > 0 ? ( ms / span ) * VIEW_W : 0 );
	const y = ( mb ) => VIEW_H - ( mb / peak ) * ( VIEW_H - PAD_TOP );

	// Step-after: a reading holds until the next one says otherwise.
	const vertices = [];
	points.forEach( ( p, i ) => {
		if ( i > 0 ) {
			vertices.push( [ x( p.ms ), y( points[ i - 1 ].mb ) ] );
		}
		vertices.push( [ x( p.ms ), y( p.mb ) ] );
	} );
	const line = vertices
		.map( ( [ px, py ] ) => `${ px },${ py }` )
		.join( ' ' );
	const area = [
		`M${ vertices[ 0 ][ 0 ] },${ VIEW_H }`,
		...vertices.map( ( [ px, py ] ) => `L${ px },${ py }` ),
		`L${ vertices[ vertices.length - 1 ][ 0 ] },${ VIEW_H }`,
		'Z',
	].join( ' ' );

	const peakLabel = sprintf(
		// translators: %s: peak memory in megabytes, e.g. "94.25".
		__( '%s MB', 'newspack-event-logger-nodes' ),
		String( peak )
	);

	return (
		<div className="event-logger-memory-track">
			<div className="event-logger-memory-track__head">
				<span className="event-logger-memory-track__title newspack-nodes-status is-muted">
					{ __( 'Peak memory', 'newspack-event-logger-nodes' ) }
				</span>
			</div>
			<div className="event-logger-memory-track__plot">
				<svg
					viewBox={ `0 0 ${ VIEW_W } ${ VIEW_H }` }
					preserveAspectRatio="none"
					role="img"
					aria-label={ sprintf(
						// translators: %s: peak memory, e.g. "94.25 MB".
						__(
							'Peak memory across the request, high-water mark %s',
							'newspack-event-logger-nodes'
						),
						peakLabel
					) }
				>
					{ GRID_AT.map( ( at ) => (
						<line
							key={ at }
							className="event-logger-memory-track__gridline"
							x1="0"
							x2={ VIEW_W }
							y1={ y( peak * at ) }
							y2={ y( peak * at ) }
						/>
					) ) }
					<path
						className="event-logger-memory-track__area"
						d={ area }
					/>
					<polyline
						className="event-logger-memory-track__line"
						points={ line }
					/>
				</svg>
				<span className="event-logger-memory-track__ymax newspack-nodes-status is-muted">
					{ peakLabel }
				</span>
				<span className="event-logger-memory-track__ymin newspack-nodes-status is-muted">
					{ __( '0', 'newspack-event-logger-nodes' ) }
				</span>
			</div>
		</div>
	);
}
