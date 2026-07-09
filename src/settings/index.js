/**
 * Event Logger Settings entry point.
 *
 * Mounts the per-URL logging-ruleset editor (RulesAdmin) into the
 * `#event-logger-rules-editor` container rendered by the "Logging Rules"
 * settings section (class-admin.php).
 */

import { createRoot } from '@wordpress/element';

import '../rules/nodes/register';
import RulesAdmin from '../rules/RulesAdmin';
import './styles/base.scss';
import './styles/settings.scss';
import './styles/hook-selector.scss';
import './styles/custom-event-selector.scss';
import './styles/rules-editor.scss';

const RULES_CONTAINER_ID = 'event-logger-rules-editor';

/**
 * Mount the RulesAdmin React root into the rules-editor container, if present.
 */
export function mountRulesEditor() {
	const container = document.getElementById( RULES_CONTAINER_ID );
	if ( ! container ) {
		return;
	}
	createRoot( container ).render( <RulesAdmin /> );
}

document.addEventListener( 'DOMContentLoaded', () => {
	mountRulesEditor();
} );
