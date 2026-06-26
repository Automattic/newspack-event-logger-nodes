import { DecodedSliceViewNode } from './decoded-slice-view-node';
import { PendingReplies } from '@newspack-nodes/shared/pendingReplies';

/**
 * `requestdetail:view` — owns the ON-DEMAND request_detail slice (the full
 * request + flame data for a selected rid). Fetched on request-selection; the
 * default storeResult (data = the reply payload) applies unchanged. React reads
 * it via useNodeState('requestdetail:view','view') in <RequestDetailView>.
 *
 * It also owns a PendingReplies registry for the awaited `resolveRequest`
 * (request_search) navigation lookup — a deep-link / search resolves a rid to
 * its { url_hash, partition }. The base fill() settles that reply via message[ID]
 * without touching the request_detail data slice.
 */
export class RequestDetailViewNode extends DecodedSliceViewNode {
	constructor() {
		super();
		this.replies = new PendingReplies();
	}
}
