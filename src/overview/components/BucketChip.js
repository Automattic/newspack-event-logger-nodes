/**
 * The removable token naming the five-minute bucket a chart click narrowed a
 * list to. The URL table and the URL modal each hold one, beside their Errors
 * Only toggle.
 */

import { __, sprintf } from '@wordpress/i18n';
import TagToken from '../../components/TagToken';
import { bucketLabel } from '../chartSlots';

/**
 * A bucket filter as a token with a clear button, or nothing without one.
 *
 * @param {Object}     props         Component props.
 * @param {string}     props.bucket  The bucket key, `YYYY-MM-DD-HH-MM`; '' for none.
 * @param {() => void} props.onClear Drops the filter.
 * @return {?import('react').ReactElement} The token, or null for ''.
 */
export default function BucketChip( { bucket, onClear } ) {
	if ( ! bucket ) {
		return null;
	}
	const label = bucketLabel( bucket );
	return (
		<TagToken
			label={ label }
			onRemove={ onClear }
			removeLabel={ sprintf(
				// translators: %s: the bucket's span, e.g. 14:05–14:10 UTC.
				__( 'Clear the %s filter', 'newspack-event-logger-nodes' ),
				label
			) }
		/>
	);
}
