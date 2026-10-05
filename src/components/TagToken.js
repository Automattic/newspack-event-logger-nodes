/**
 * A removable token: a label and a close-icon button. The rule editor's tag
 * input draws one per value, and the overview draws one for a bucket filter.
 */

import { Button } from '@wordpress/components';
import { closeSmall } from '@wordpress/icons';
import './TagToken.scss';

/**
 * The token carries `newspack-nodes-badge` beside its own class: the shared
 * badge paints it — background, radius and the inset ring — while
 * `TagToken.scss` contributes only geometry.
 *
 * @param {Object}     props             Component props.
 * @param {string}     props.label       The token's text.
 * @param {() => void} props.onRemove    Takes the token away.
 * @param {string}     props.removeLabel The remove button's accessible name.
 * @return {import('react').ReactElement} The token.
 */
export default function TagToken( { label, onRemove, removeLabel } ) {
	return (
		<span className="event-logger-tag-token newspack-nodes-badge">
			<span className="event-logger-tag-text">{ label }</span>
			<Button
				icon={ closeSmall }
				iconSize={ 16 }
				onClick={ onRemove }
				label={ removeLabel }
				className="event-logger-tag-remove"
			/>
		</span>
	);
}
