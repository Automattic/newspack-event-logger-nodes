/**
 * The flame-graph section three detail views share: a heading, the graph
 * behind a Suspense boundary, and a peak-memory track beneath it on the same
 * time axis. The request detail modal, the URL detail view's aggregate flame
 * and the current-request overlay all render it, and each mounts it only when
 * there is a flame to draw.
 *
 * The Suspense fallback's compact geometry ships from `request-trace.scss`
 * here rather than from either dashboard's own stylesheet, because every
 * bundle that renders a trace needs the rule.
 */

import { lazy, Suspense } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import LoadingFallback from '../../components/LoadingFallback';
import MemoryTrack from './MemoryTrack';
import '../styles/request-trace.scss';

/**
 * The graph module, held out of the render that opens a detail view.
 *
 * It pulls in d3-flame-graph and d3's selection and scale machinery, so
 * `lazy()` lets the summary, the banners and the entry list paint first while
 * Suspense holds this section's place. Each dashboard builds as one IIFE
 * bundle with no code splitting, so the bytes ship either way — what defers is
 * the module's evaluation, not its download.
 *
 * knip cannot parse JSX in a `.js` file and so never sees this `import()`;
 * `FlameGraph.js` is an entry in `knip.json` to keep the dead-code audit from
 * claiming it.
 */
const FlameGraph = lazy( () => import( '../FlameGraph' ) );

/**
 * The memory track's X domain is the flame ROOT's `value`, not the record's
 * `duration_ms`. Both charts stretch to the same container width, so the
 * readings sit under the frames they belong to only while the two scale by
 * one number. A flame value is a layout width — `Flame_Tree` raises a parent
 * to cover its children — and can exceed the measured duration, so passing
 * the duration instead would slide the track out of register.
 *
 * @param {Object}                   props                 Component props.
 * @param {Object}                   props.flameData       Flame tree root from `Flame_Tree`, for one request or aggregated across many.
 * @param {string}                   [props.title]         Heading, already translated; defaults to "Request Trace".
 * @param {number}                   [props.lastModified]  Server timestamp gating the graph's in-place update; omit for a single request, whose flame never changes.
 * @param {(path: string[]) => void} [props.onRevealEntry] Called with a frame's path, root-first frame names, on Cmd/Ctrl+click.
 * @param {Array<Object>}            [props.entries]       The request's log entries, for the peak-memory track; the track draws nothing without them, which is how the aggregate and overlay views render.
 * @return {import('react').ReactElement} The trace section.
 */
export default function RequestTrace( {
	flameData,
	title = __( 'Request Trace', 'newspack-event-logger-nodes' ),
	lastModified,
	onRevealEntry,
	entries,
} ) {
	return (
		<div className="event-logger-flame-container">
			<h3 className="newspack-nodes-section-heading">{ title }</h3>
			<Suspense
				fallback={
					<LoadingFallback
						message={ __(
							'Loading chart…',
							'newspack-event-logger-nodes'
						) }
					/>
				}
			>
				<FlameGraph
					data={ flameData }
					lastModified={ lastModified }
					onRevealEntry={ onRevealEntry }
				/>
			</Suspense>
			<MemoryTrack
				entries={ entries }
				totalMs={ flameData?.value || 0 }
			/>
		</div>
	);
}
