import { DecodedSliceViewNode } from './decoded-slice-view-node';
import { PendingReplies } from '@newspack-nodes/shared/pendingReplies';

/**
 * `urldetail:view` — owns the ON-DEMAND url_detail slice. Fetched on modal-open
 * (not polled); the reply arrives ALREADY merged by UrlDetailMergeNode on the
 * receiver→view edge, so the default storeResult (data = the merged payload)
 * applies unchanged. React reads it via useNodeState('urldetail:view','view') in
 * <UrlDetailView>.
 *
 * It also owns a PendingReplies registry for the awaited fetchUrlBreakdown verb:
 * the hook stashes a `{ resolve, reject }` under the outbound message[ID], and
 * the base fill() settles the matching reply first — without touching the data
 * slice (a breakdown reply is the caller's Promise, not the modal's stats).
 */
export class UrlDetailViewNode extends DecodedSliceViewNode {
	constructor() {
		super();
		this.replies = new PendingReplies();
	}
}
