/**
 * Ambient declarations for things the bundler and the browser provide but the
 * type-checker cannot infer from the JavaScript alone.
 *
 * Two kinds live here:
 *
 * 1. Globals PHP injects into the page. `Current_Request_Overlay` prints
 *    `window.NewspackEventLoggerNodes`; the settings screens print the colour
 *    and recommended-hook maps. To tsc they are absent from `Window`, so every
 *    read is an error even though the read is correct and already guarded.
 *
 * 2. CSS custom properties in `style={{ … }}`. React types `CSSProperties`
 *    with known CSS keys only, so a `--stream-grid-template` entry is rejected
 *    as an unknown property. Passing custom properties through `style` is the
 *    supported way to hand a value to a stylesheet, so the type is what is
 *    wrong here, not the code.
 */

// The `import` below makes this a MODULE, so Window needs `declare global`.
declare global {
	interface Window {
		/** Printed by `Current_Request_Overlay`; carries `currentRequest`. */
		NewspackEventLoggerNodes?: {
			currentRequest?: unknown;
			[ key: string ]: unknown;
		};
		/** Hook-category map, including a `_colors` lookup. */
		eventLoggerHookCategories?: {
			_colors?: Record< string, string >;
			[ key: string ]: unknown;
		};
		/** Custom event-colour map from the settings config. */
		newspackNodesCustomColors?: Record< string, string >;
		/** Recommended hook names offered in the hook picker. */
		newspackNodesRecommendedHooks?: string[];
	}
}

import 'react';

declare module 'react' {
	interface CSSProperties {
		[ key: `--${ string }` ]: string | number | undefined;
	}
}
