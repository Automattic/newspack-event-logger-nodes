/**
 * Settings page entry point — the `build/settings` bundle, enqueued on the
 * `newspack-event-logger-nodes` admin page.
 *
 * The bundle dresses the whole settings page, not only its React root, and the
 * stylesheet imports below are what make the build kit emit
 * `build/settings/index.css`, the sheet `enqueue_react_page()` pairs with the
 * script. `settings.scss` lays out the form table `class-admin.php` prints and
 * `rules-editor.scss` styles `RulesAdmin`; a component never imports either, so
 * this entry is where both reach the build. `HookSelectorModal` and
 * `CustomEventSelectorModal` import their own pickers' sheets as well.
 *
 * The one React mount is the per-URL logging-ruleset editor, `RulesAdmin`,
 * which fills the `#event-logger-rules-editor` div that page's "Logging Rules"
 * section renders. The PHP-printed `.wrap` is the page's one skin root. The
 * bundle applies the stored skin to `<html>` as it loads, before React mounts
 * anything, and the editor paints the admin gutters from that wrap through
 * `useSkinGutters`. No editor control opens a popover, so the page needs no
 * Popover slot inside the skin.
 *
 * Importing `../rules/nodes/register` for its side effect merges `RulesView`
 * into the runtime's `includeNodes` map, the name surface the console palette
 * reads. The editor's graph does not depend on that merge — `useRulesGraph`
 * reaches the same class through the module's `views` export — so the
 * side-effect import states the dependency at the entry point rather than
 * resting on the page tree's import chain.
 */

import { createRoot } from '@wordpress/element';
import useSkinGutters from '@newspack-nodes/shared/hooks/useSkinGutters';
import { initSkin } from '@newspack-nodes/shared/theme';

import '../rules/nodes/register';
import RulesAdmin from '../rules/RulesAdmin';
import './styles/base.scss';
import './styles/settings.scss';
import './styles/hook-selector.scss';
import './styles/custom-event-selector.scss';
import './styles/rules-editor.scss';

// The printed form paints before React mounts, and the hook runs after that.
initSkin();

/**
 * Id of the mount point `Admin::render_rules_editor_section()` prints.
 *
 * @type {string}
 */
const RULES_CONTAINER_ID = 'event-logger-rules-editor';

/**
 * The rules editor, painting the admin gutters from the printed skin root.
 *
 * @param {Object}              props         Component props.
 * @param {{current: ?Element}} props.wrapRef The settings page's `.wrap`.
 * @return {import('react').ReactElement} The editor.
 */
function RulesEditor( { wrapRef } ) {
	useSkinGutters( wrapRef );
	return <RulesAdmin />;
}

/**
 * Mount the `RulesAdmin` React root into the rules-editor container.
 *
 * The stylesheets above are the bundle's other half, so a page can want the
 * bundle without printing the container. A missing container is therefore an
 * ordinary case and the function returns rather than reporting anything.
 *
 * @testonly Exported for index.test.js; the `DOMContentLoaded` listener below
 *           is the only production caller.
 */
export function mountRulesEditor() {
	const container = document.getElementById( RULES_CONTAINER_ID );
	if ( ! container ) {
		return;
	}
	const wrapRef = {
		current: container.closest( '.event-logger-settings-wrap' ),
	};
	createRoot( container ).render( <RulesEditor wrapRef={ wrapRef } /> );
}

// Enqueued in the footer, so this listener still beats the parser finishing.
document.addEventListener( 'DOMContentLoaded', () => {
	mountRulesEditor();
} );
