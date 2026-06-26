import { DecodedSliceViewNode } from './decoded-slice-view-node';

/**
 * `overview:view` — owns the always-on overview slice ({ data, loading, error }).
 * The overview verb's payload IS the slice data (the whole overview object), so
 * the default storeResult applies unchanged. React reads it via
 * useNodeState('overview:view','view') in <OverviewSection>.
 */
export class OverviewViewNode extends DecodedSliceViewNode {}
