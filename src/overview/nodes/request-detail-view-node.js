import { DecodedSliceViewNode } from './decoded-slice-view-node';

/**
 * `requestdetail:view` — owns the ON-DEMAND request_detail slice: everything
 * known about one selected request, the durable request body with its flame
 * data merged in. `usePerformanceGraph` mints this node as `RequestDetailView`,
 * addresses the `performance` CI's `request_detail` reply here on
 * request-selection, and drives the rest of the slice's lifecycle with
 * TM_STRUCT controls — `loading` on selection, `error` on an invalid rid, and
 * `clear` when the modal closes so the next open starts fresh.
 *
 * The subclass is empty by design. The base's `emptySlice()` and
 * `storeResult()` already give this slice its shape (`data` = the reply
 * payload); the class exists to give the node a name `makeNode` can resolve and
 * an identity of its own, which is what keeps the reply addressed to it alone.
 *
 * `PerformanceDashboard` reads the slice with
 * `useNodeState( 'requestdetail:view', 'view' )` and passes `data` down to
 * `<RequestDetailView>`, which renders its props and fetches nothing.
 *
 * The `request_search` lookups — the search box and the deep link — belong to
 * different nodes under their own scopes, so their replies address those and
 * never reach this slice.
 */
export class RequestDetailViewNode extends DecodedSliceViewNode {}
