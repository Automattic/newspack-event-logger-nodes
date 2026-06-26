import { DecodedSliceViewNode } from './decoded-slice-view-node';

/**
 * `urls:view` — owns the always-on urls slice. The urls verb returns an envelope
 * `{ data, total, limit, offset }`; the published slice exposes `data` (the URL
 * rows) + `total` (the table footer count), plus loading/error. React reads it
 * via useNodeState('urls:view','view') in <UrlTable>.
 */
export class UrlsViewNode extends DecodedSliceViewNode {
	emptySlice() {
		return { data: [], total: 0, loading: false, error: null };
	}

	storeResult( payload ) {
		this.model = {
			data: ( payload && payload.data ) || [],
			total: ( payload && payload.total ) || 0,
			loading: false,
			error: null,
		};
	}
}
