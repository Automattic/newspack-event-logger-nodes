/**
 * Members d3-flame-graph 4.1.3 ships but does not declare.
 *
 * Its bundled `index.d.ts` covers most of the chart API and misses these two,
 * both of which exist in the published bundle (`src/flamegraph.js` defines
 * `chart.getName` and `chart.tooltip`; both survive into `dist/`). Declaring
 * them here restores type-checking across a builder chain that would otherwise
 * go `any` at the first undeclared call.
 *
 * - `getName( fn )` overrides the frame label accessor, which is how the
 *   dashboards render `detail` ("name: message") instead of the bare name.
 * - `tooltip` is declared upstream as taking a boolean, but the implementation
 *   assigns only when handed a FUNCTION and ignores everything else — a
 *   boolean is silently a no-op. The tooltip object `createTooltip()` builds
 *   is what it actually wants.
 */

declare module 'd3-flame-graph' {
	interface FlameGraph {
		getName( val: ( node: any ) => string ): FlameGraph;
		getName(): ( node: any ) => string;
		tooltip( val: ( ...args: any[] ) => any ): FlameGraph;
	}
}
