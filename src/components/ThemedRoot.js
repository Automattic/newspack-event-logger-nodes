import { useState, useEffect, useRef } from '@wordpress/element';
import { getStoredTheme } from '@newspack-nodes/shared/theme';

import './ThemedRoot.scss';

/**
 * No-box themed token-provider for standalone ELN dashboards. Reads the
 * console-selected skin (persisted in localStorage) once at mount and wraps its
 * children in a `display:contents` `.topology-app.newspack-nodes-theme.theme-<slug>`
 * div, putting the skin's universal tokens (--paper/--ink/--cyan/--font-mono/…)
 * in scope so the dashboard reskins onto them.
 *
 * `display: contents` drops this wrapper's own box, so `.topology-app`'s console
 * grid/size/background layout never renders — but inheritance still passes
 * through, so the skin's `font-family: var(--font-mono)` cascades into the
 * dashboard (terminal-mono under decorative skins, the Newspack sans by default).
 *
 * The dashboard's dark surface only covers its own box; the WP-admin area around
 * it (the ~20px left gutter beside the menu, the right `max-width` margin, the
 * footer area below the content) otherwise shows the light body background as
 * stray strips. The effect below paints `document.body` with the RESOLVED skin
 * surface (read once from this themed wrapper) so every gutter matches the
 * dashboard; it's restored on unmount.
 *
 * @param {Object}                    props          Component props.
 * @param {import('react').ReactNode} props.children Dashboard root(s) to skin.
 * @return {import('react').ReactElement} The themed token provider.
 */
export default function ThemedRoot( { children } ) {
	const [ theme ] = useState( getStoredTheme );
	const ref = useRef( null );

	useEffect( () => {
		const host = ref.current;
		if ( ! host ) {
			return undefined;
		}
		// Resolve the skin's --paper to a concrete colour from inside the themed
		// wrapper (custom props resolve on the element even under display:contents).
		const probe = document.createElement( 'span' );
		host.appendChild( probe );
		let paper;
		try {
			probe.style.background = 'var(--paper)';
			paper = window.getComputedStyle( probe ).backgroundColor;
		} finally {
			probe.remove();
		}
		if ( ! paper || paper === 'rgba(0, 0, 0, 0)' ) {
			return undefined;
		}
		const previous = document.body.style.background;
		document.body.style.background = paper;
		return () => {
			document.body.style.background = previous;
		};
	}, [ theme ] );

	return (
		<div
			ref={ ref }
			className={ `topology-app newspack-nodes-theme theme-${ theme }` }
			style={ { display: 'contents' } }
		>
			{ children }
		</div>
	);
}
