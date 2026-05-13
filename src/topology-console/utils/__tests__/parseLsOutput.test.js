import { parseLsOutput } from '../parseLsOutput';

describe( 'parseLsOutput', () => {
	it( 'returns empty graph for empty input', () => {
		expect( parseLsOutput( '' ) ).toEqual( { nodes: [], edges: [] } );
	} );

	it( 'returns empty graph for null/undefined input', () => {
		expect( parseLsOutput( null ) ).toEqual( { nodes: [], edges: [] } );
		expect( parseLsOutput( undefined ) ).toEqual( {
			nodes: [],
			edges: [],
		} );
	} );

	it( 'skips the COUNT header line', () => {
		const text = 'COUNT NAME       TARGET\n  10 alpha          -> beta\n';
		const { nodes } = parseLsOutput( text );
		expect( nodes ).toEqual( [ { id: 'alpha', count: 10 } ] );
	} );

	it( 'parses a single node with a single target into one node + one edge', () => {
		const text = '  42 alpha          -> beta\n';
		expect( parseLsOutput( text ) ).toEqual( {
			nodes: [ { id: 'alpha', count: 42 } ],
			edges: [ { from: 'alpha', to: 'beta' } ],
		} );
	} );

	it( 'parses comma-separated targets into multiple edges', () => {
		const text = '  3 firehose:tee     -> request-builder, job-router\n';
		const { edges } = parseLsOutput( text );
		expect( edges ).toEqual( [
			{ from: 'firehose:tee', to: 'request-builder' },
			{ from: 'firehose:tee', to: 'job-router' },
		] );
	} );

	it( 'parses a node with no target (dash or empty)', () => {
		const text = '  7 sink            -> -\n  8 orphan\n';
		const { nodes, edges } = parseLsOutput( text );
		expect( nodes ).toEqual( [
			{ id: 'sink', count: 7 },
			{ id: 'orphan', count: 8 },
		] );
		expect( edges ).toEqual( [] );
	} );

	it( 'excludes scaffolding nodes (_command_interpreter, _router, _output, _repl)', () => {
		const text =
			'  1 _command_interpreter -> _router\n' +
			'  2 _router             -> _output\n' +
			'  3 _output             -> -\n' +
			'  4 _repl               -> _router\n' +
			'  5 firehose:consumer   -> firehose:tee\n';
		const { nodes } = parseLsOutput( text );
		expect( nodes ).toEqual( [ { id: 'firehose:consumer', count: 5 } ] );
	} );

	it( 'parses a realistic multi-line topology', () => {
		const text = [
			'COUNT NAME                 TARGET',
			' 1334 firehose:consumer    -> firehose:tee',
			' 1334 firehose:tee         -> request-builder, job-router',
			' 1335 job-router           -> jobs:partition',
			'',
		].join( '\n' );
		const { nodes, edges } = parseLsOutput( text );
		expect( nodes ).toEqual( [
			{ id: 'firehose:consumer', count: 1334 },
			{ id: 'firehose:tee', count: 1334 },
			{ id: 'job-router', count: 1335 },
		] );
		expect( edges ).toEqual( [
			{ from: 'firehose:consumer', to: 'firehose:tee' },
			{ from: 'firehose:tee', to: 'request-builder' },
			{ from: 'firehose:tee', to: 'job-router' },
			{ from: 'job-router', to: 'jobs:partition' },
		] );
	} );

	it( 'skips malformed lines', () => {
		const text =
			'this is garbage\n' +
			'  5 alpha          -> beta\n' +
			'another bad line\n';
		const { nodes } = parseLsOutput( text );
		expect( nodes ).toEqual( [ { id: 'alpha', count: 5 } ] );
	} );
} );
