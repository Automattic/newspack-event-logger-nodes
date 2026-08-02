import { DecodedSliceViewNode } from './decoded-slice-view-node';

/**
 * `urldetail:view` — owns the ON-DEMAND url_detail slice. Fetched on modal-open
 * (not polled); the reply arrives ALREADY merged by UrlDetailMergeNode on the
 * receiver→view edge, so the default storeResult (data = the merged payload)
 * applies unchanged. React reads it via useNodeState('urldetail:view','view') in
 * <UrlDetailView>.
 *
 * The awaited fetchUrlBreakdown verb is a different node's job — a breakdown
 * reply is that node's Promise, not this modal's stats.
 */
export class UrlDetailViewNode extends DecodedSliceViewNode {}
