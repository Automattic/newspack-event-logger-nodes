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
	/** The substrate alias base `createJestConfig()` hands every test. */
	const NEWSPACK_NODES_SRC: string;
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
		/** Hook names offered for time-by-owner attribution. */
		newspackNodesOwnerTimeEvents?: string[];
		/** Set by `Performance_Page::enqueue()`: the composed page's data. */
		NewspackEventLoggerPerformancePage?: {
			/** Whether the reader may curate the layout. */
			canTune: boolean;
			/** Whether the reader is an operator, who reads a refusal's own words. */
			canManage: boolean;
			/** Context => its layout or its refusal. */
			compositions: Record< string, Object >;
			/** The stats window in buckets, or its refusal. */
			window: number | { error: string };
		};
		/** What a log page's load sets (`Log_Pages::page_data()`). */
		NewspackEventLoggerLogPage?: {
			/** The page, as `?edit=` names it. */
			page: 'request-log' | 'error-log' | 'gyroscope';
			/** Whether the reader may curate the layout. */
			canTune: boolean;
			/** Whether the reader is an operator, who reads a refusal's own words. */
			canManage: boolean;
			/** Context => its layout or its refusal. */
			compositions: Record< string, Object >;
		};
	}
}

import 'react';

declare module 'react' {
	interface CSSProperties {
		[ key: `--${ string }` ]: string | number | undefined;
	}
}
