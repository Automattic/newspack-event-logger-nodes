import { SliceViewNode } from '@newspack-nodes/shared/nodes/slice-view-node';

/**
 * `currentrequest:view` — THIS request's own stored record.
 *
 * The record is written by a worker moments after the page rendered, so the
 * first few asks legitimately answer "nothing yet". That is not a failure and
 * must not blank the tab: the slice keeps what it has until a record arrives.
 */
export class CurrentRequestViewNode extends SliceViewNode {
	/**
	 * @return {Object} Empty render model.
	 */
	emptySlice() {
		return { request: null, error: null };
	}

	/**
	 * @param {*} payload The decoded `request_detail` body.
	 * @return {?Object} The render model, or null while nothing is written.
	 */
	_parse( payload ) {
		if ( ! payload || 'object' !== typeof payload ) {
			return null;
		}
		return { request: payload, error: null };
	}
}
