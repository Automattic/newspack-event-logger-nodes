/**
 * Decorative frame around the SVG canvas — engineering plotter chrome.
 *
 * Top-left: human-readable topology label + file-path hint
 * Four corners: alignment reticles
 * Bottom-right: drafting title block
 *
 * Renders its children inside the inner canvas area so the SVG fits
 * within the reticle frame.
 */

const DRAWN_BY =
	( window.NewspackNodesData && window.NewspackNodesData.userLogin ) || '—';

function todayISO() {
	return new Date().toISOString().slice( 0, 10 );
}

export default function CanvasFrame( {
	topology,
	partition,
	children,
	onResetLayout,
} ) {
	return (
		<div className="topology-canvas">
			<div className="topology-canvas__meta">
				{ topology } · Partition { partition }
				<div className="topology-canvas__topology-name">
					topologies/{ topology }.php
				</div>
				{ /* Only appears once the user has dragged anything —
				clicking it clears every override for this topology/
				partition and the canvas re-auto-lays out. */ }
				{ onResetLayout && (
					<button
						type="button"
						className="topology-canvas__reset"
						onClick={ onResetLayout }
						title="Discard dragged positions and re-auto-layout"
					>
						↺ Reset layout
					</button>
				) }
			</div>

			<div className="topology-reticle topology-reticle--tl" />
			<div className="topology-reticle topology-reticle--tr" />
			<div className="topology-reticle topology-reticle--bl" />
			<div className="topology-reticle topology-reticle--br" />

			{ children }

			<div className="topology-title-block">
				<div className="topology-title-block__row">
					<div className="topology-title-block__k">Project</div>
					<div className="topology-title-block__v topology-title-block__v--proj">
						EVENT LOG/NODES
					</div>
				</div>
				<div className="topology-title-block__row">
					<div className="topology-title-block__k">Drawn</div>
					<div className="topology-title-block__v">
						{ DRAWN_BY } · { todayISO() }
					</div>
				</div>
				<div className="topology-title-block__row">
					<div className="topology-title-block__k">Sheet</div>
					<div className="topology-title-block__v">
						{ topology }.p{ partition }
					</div>
				</div>
				<div className="topology-title-block__row">
					<div className="topology-title-block__k">Scale</div>
					<div className="topology-title-block__v">
						1:1 · do not detail
					</div>
				</div>
			</div>
		</div>
	);
}
