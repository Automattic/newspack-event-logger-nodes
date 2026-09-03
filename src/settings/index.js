/**
 * Settings page entry point — the `build/settings` bundle, enqueued on the
 * `newspack-event-logger-nodes` admin page.
 *
 * The bundle dresses the whole settings page, not only its React root, and the
 * stylesheet imports below are what make the build kit emit
 * `build/settings/index.css`, the sheet `enqueue_react_page()` pairs with the
 * script. `settings.scss` lays out the form table `class-admin.php` prints and
 * `rules-editor.scss` styles `RulesAdmin`; no component imports either, so
 * this entry is where both reach the build. `HookSelectorModal` and
 * `CustomEventSelectorModal` import their own pickers' sheets as well.
 *
 * The one React mount is the per-URL logging-ruleset editor, `RulesAdmin`,
 * which fills the `#event-logger-rules-editor` div that page's "Logging Rules"
 * section renders.
 *
 * Importing `../rules/nodes/register` for its side effect merges `RulesView`
 * into the runtime's `includeNodes` map, the name surface the console palette
 * reads. The editor's graph does not depend on that merge — `useRulesGraph`
 * reaches the same class through the module's `views` export — so the
 * side-effect import states the dependency at the entry point rather than
 * resting on the page tree's import chain.
 */

import { createRoot } from '@wordpress/element';

import '../rules/nodes/register';
import RulesAdmin from '../rules/RulesAdmin';
import './styles/base.scss';
import './styles/settings.scss';
import './styles/hook-selector.scss';
import './styles/custom-event-selector.scss';
import './styles/rules-editor.scss';

/**
 * Id of the mount point `Admin::render_rules_editor_section()` prints.
 *
 * @type {string}
 */
const RULES_CONTAINER_ID = 'event-logger-rules-editor';

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
	createRoot( container ).render( <RulesAdmin /> );
}

// Enqueued in the footer, so this listener still beats the parser finishing.
document.addEventListener( 'DOMContentLoaded', () => {
	mountRulesEditor();
} );
