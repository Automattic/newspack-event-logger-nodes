import { DecodedSliceViewNode } from './decoded-slice-view-node';

/**
 * `overview:view` — owns the always-on overview slice ({ data, loading, error }).
 *
 * The `overview` verb's payload IS the slice data (the whole overview object),
 * so `DecodedSliceViewNode`'s `emptySlice()` and `storeResult()` apply unchanged
 * and this subclass adds nothing but a name.
 *
 * `usePerformanceGraph` builds it through `addSliceFetcher` under the registered
 * class name `OverviewView`, on the polled `overview:fetch` → `overview:in` →
 * `overview:view` path. `PerformanceDashboard` reads the slice with
 * useNodeState('overview:view','view') and passes the data down to the
 * presentational <OverviewSection>.
 */
export class OverviewViewNode extends DecodedSliceViewNode {}
