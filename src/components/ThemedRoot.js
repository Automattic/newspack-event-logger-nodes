import { useState } from '@wordpress/element';
import { getStoredTheme } from '@newspack-nodes/shared/theme';

/**
 * No-box themed token-provider for standalone ELN dashboards. Reads the
 * console-selected skin (persisted in localStorage) once at mount and wraps its
 * children in a `display:contents` `.topology-app.newspack-nodes-theme.theme-<slug>`
 * div, putting the skin's universal tokens (--paper/--ink/--cyan/…) + color in
 * scope for the dashboard's `var(--paper, var(--np-*))` chains so it reskins per
 * theme.
 *
 * Two inline neutralizations keep it a PURE token provider — `.topology-app`'s
 * own rule block sets box layout AND a monospace `font-family`, both of which
 * would otherwise reach the dashboard:
 *   - `display: contents` drops this wrapper's box, so `.topology-app`'s
 *     grid/size/background layout never renders;
 *   - `font-family: inherit` overrides `.topology-app`'s `var(--font-mono)`,
 *     which `display:contents` would otherwise cascade into the dashboard
 *     (inheritance passes through a contents box). The product dashboards stay
 *     sans; the log/flame pieces that genuinely want mono set `$mono-font`
 *     themselves. Color is intentionally left to inherit so text reskins too.
 * Mirrors the debug-overlay DebugPanel token provider (which likewise resets the
 * inherited console mono for its reused dashboard pieces).
 *
 * @param {Object}                    props          Component props.
 * @param {import('react').ReactNode} props.children Dashboard root(s) to skin.
 * @return {import('react').ReactElement} The themed token provider.
 */
export default function ThemedRoot( { children } ) {
	const [ theme ] = useState( getStoredTheme );

	return (
		<div
			className={ `topology-app newspack-nodes-theme theme-${ theme }` }
			style={ { display: 'contents', fontFamily: 'inherit' } }
		>
			{ children }
		</div>
	);
}
