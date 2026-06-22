/**
 * Regression guard: every @wordpress/components <Modal> in the dashboards must
 * carry `newspack-nodes-theme` on its className. WP Modals portal to
 * document.body, escaping the themed dashboard root — without the class the
 * modal's `var(--np-*)` token references resolve to nothing and its Newspack
 * chrome collapses to transparent/initial. (Caught in code review when the
 * reskin themed the page roots but missed the portaled modals.)
 */

import fs from 'fs';
import path from 'path';

const MODAL_FILES = [
	'performance-dashboards/PerformanceDashboard.js',
	'performance-logger/settings/HookSelectorModal.js',
	'performance-logger/settings/CustomEventSelectorModal.js',
];

describe( 'dashboard Modal theme class', () => {
	it.each( MODAL_FILES )(
		'%s: the Modal frame className carries newspack-nodes-theme',
		( rel ) => {
			const src = fs.readFileSync(
				path.join( __dirname, '..', rel ),
				'utf8'
			);
			expect( src ).toMatch(
				/className="event-logger-[\w-]+-modal newspack-nodes-theme"/
			);
		}
	);
} );
