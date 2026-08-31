/**
 * The "Request Trace" section: one request's flame graph, behind Suspense.
 * Callers mount it only when there is a flame to draw.
 *
 * The graph drags in d3 and d3-flame-graph, the heaviest dependency in either
 * bundle, so it loads lazily. knip cannot parse JSX in a `.js` file and so
 * never sees this `import()`; `FlameGraph.js` is an entry in `knip.json` to
 * keep the dead-code audit from claiming it.
 */

import { lazy, Suspense } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import LoadingFallback from '../../components/LoadingFallback';
import MemoryTrack from './MemoryTrack';
import '../styles/request-trace.scss';

const FlameGraph = lazy( () => import( '../FlameGraph' ) );

/**
 * @param {Object}                   props                 Component props.
 * @param {Object}                   props.flameData       Server-built flame tree.
 * @param {string}                   [props.title]         Heading, already translated; defaults to "Request Trace".
 * @param {number}                   [props.lastModified]  Stamp the graph redraws on.
 * @param {(path: string[]) => void} [props.onRevealEntry] Called with a frame's path on Cmd/Ctrl+click.
 * @param {Array<Object>}            [props.entries]       Log entries, for the peak-memory track under the graph.
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
