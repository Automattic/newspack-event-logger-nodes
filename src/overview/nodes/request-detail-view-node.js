import { DecodedSliceViewNode } from './decoded-slice-view-node';

/**
 * `requestdetail:view` — owns the ON-DEMAND request_detail slice (the full
 * request + flame data for a selected rid). Fetched on request-selection; the
 * default storeResult (data = the reply payload) applies unchanged. React reads
 * it via useNodeState('requestdetail:view','view') in <RequestDetailView>.
 *
 * The awaited `resolveRequest` (request_search) lookup is a different node's
 * job — its reply is addressed there and never reaches this slice.
 */
export class RequestDetailViewNode extends DecodedSliceViewNode {}
