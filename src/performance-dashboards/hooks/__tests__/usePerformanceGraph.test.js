import { Core, newMessage, VALUE } from '@newspack-nodes/runtime';
import { renderHook } from '../../../shared/hooks/__tests__/renderHook';
import { usePerformanceGraph } from '../usePerformanceGraph';

const reply = ( payload ) => {
	const m = newMessage();
	m[ VALUE ] = { payload };
	return m;
};
function fakeClient( payload = {} ) {
	return {
		calls: [],
		send( a ) {
			this.calls.push( a );
			return Promise.resolve( reply( payload ) );
		},
	};
}
beforeEach( () => Core.reset() );

test( 'mounts command + view and fires the initial overview + urls', async () => {
	const client = fakeClient( { total_requests: 1 } );
	const { unmount } = renderHook( ( p ) => usePerformanceGraph( p ), {
		initialProps: { commandClient: client },
	} );
	await Promise.resolve();
	await Promise.resolve();
	expect( Core.node( 'performance/command' ) ).toBeTruthy();
	expect( Core.node( 'performance/view' ) ).toBeTruthy();
	const verbs = client.calls.map( ( c ) => c.verb );
	expect( verbs ).toContain( 'overview' );
	expect( verbs ).toContain( 'urls' );
	unmount();
} );

test( 're-fetches when serverFilter changes', async () => {
	const client = fakeClient();
	const { rerender, unmount } = renderHook(
		( p ) => usePerformanceGraph( p ),
		{
			initialProps: { commandClient: client },
		}
	);
	await Promise.resolve();
	const before = client.calls.length;
	rerender( { commandClient: client, serverFilter: 'web1' } );
	await Promise.resolve();
	expect( client.calls.length ).toBeGreaterThan( before );
	expect(
		client.calls.some(
			( c ) => c.verb === 'overview' && c.payload.server === 'web1'
		)
	).toBe( true );
	unmount();
} );

test( 'fires fetchUrlDetail with the selected hash when a URL is selected', async () => {
	const client = fakeClient( { requests: [] } );
	const { rerender, unmount } = renderHook(
		( p ) => usePerformanceGraph( p ),
		{
			initialProps: { commandClient: client },
		}
	);
	await Promise.resolve();
	rerender( { commandClient: client, selectedUrl: { hash: 'abc' } } );
	await Promise.resolve();
	expect(
		client.calls.some(
			( c ) => c.verb === 'url_detail' && c.payload.hash === 'abc'
		)
	).toBe( true );
	unmount();
} );

test( 'fires fetchRequestDetail using the provided partition', async () => {
	const client = fakeClient( { rid: 'r1' } );
	const { rerender, unmount } = renderHook(
		( p ) => usePerformanceGraph( p ),
		{
			initialProps: { commandClient: client },
		}
	);
	await Promise.resolve();
	rerender( {
		commandClient: client,
		selectedRequest: 'r1',
		requestPartition: 2,
	} );
	await Promise.resolve();
	expect(
		client.calls.some(
			( c ) => c.verb === 'request_detail' && c.payload.partition === 2
		)
	).toBe( true );
	unmount();
} );

test( 'handleUrlParamsChange debounces a search change', async () => {
	jest.useFakeTimers();
	const client = fakeClient( { data: [], total: 0 } );
	let api;
	const { unmount } = renderHook(
		( p ) => {
			api = usePerformanceGraph( p );
			return api;
		},
		{ initialProps: { commandClient: client } }
	);
	await Promise.resolve();
	const before = client.calls.filter( ( c ) => c.verb === 'urls' ).length;
	api.handleUrlParamsChange( {
		search: 'x',
		sort: 'count',
		order: 'desc',
		offset: 0,
	} );
	expect( client.calls.filter( ( c ) => c.verb === 'urls' ).length ).toBe(
		before
	);
	jest.advanceTimersByTime( 300 );
	expect( client.calls.filter( ( c ) => c.verb === 'urls' ).length ).toBe(
		before + 1
	);
	unmount();
	jest.useRealTimers();
} );

test( 'resolveRequest returns the unwrapped reply', async () => {
	const client = fakeClient( { url_hash: 'h', partition: 1 } );
	let api;
	const { unmount } = renderHook(
		( p ) => {
			api = usePerformanceGraph( p );
			return api;
		},
		{ initialProps: { commandClient: client } }
	);
	await Promise.resolve();
	expect( await api.resolveRequest( 'r' ) ).toEqual( {
		url_hash: 'h',
		partition: 1,
	} );
	unmount();
} );

test( 'resolveRequest resolves via the client when the node is not mounted (deep-link timing)', async () => {
	// useUrlNavigation fires the `?request=`-only resolver from its mount effect,
	// which runs BEFORE this hook's mount effect populates commandRef. Dropping
	// the mounted node (unmount → commandRef.current null) stands in for that
	// pre-mount state: resolveRequest must still resolve via the client directly.
	const client = fakeClient( { url_hash: 'h', partition: 0 } );
	let api;
	const { unmount } = renderHook(
		( p ) => {
			api = usePerformanceGraph( p );
			return api;
		},
		{ initialProps: { commandClient: client } }
	);
	await Promise.resolve();
	unmount();
	client.calls.length = 0;
	const out = await api.resolveRequest( 'rid-7' );
	expect( out ).toEqual( { url_hash: 'h', partition: 0 } );
	expect( client.calls.some( ( c ) => c.verb === 'request_search' ) ).toBe(
		true
	);
} );

test( 'unmount closes the command and unregisters both nodes', async () => {
	const client = fakeClient();
	const { unmount } = renderHook( ( p ) => usePerformanceGraph( p ), {
		initialProps: { commandClient: client },
	} );
	await Promise.resolve();
	const command = Core.node( 'performance/command' );
	const spy = jest.spyOn( command, 'close' );
	unmount();
	expect( spy ).toHaveBeenCalled();
	expect( Core.node( 'performance/command' ) ).toBeFalsy();
	expect( Core.node( 'performance/view' ) ).toBeFalsy();
} );
