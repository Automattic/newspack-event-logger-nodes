/* eslint-env jest */
/**
 * The profile block says why it has nothing to show: a refusal, a first
 * load, or a window that profiled no request; and titles the breakdown by
 * the server filter.
 */

let mockSite = {};
let mockServer = null;
let mockProfile = null;

jest.mock( '@newspack-nodes/shared/composition/page/pageState', () => ( {
	usePageState: () => [ mockServer ],
} ) );
jest.mock( '../pageQuestions', () => ( { useSite: () => mockSite } ) );
jest.mock( '../../OwnerTable', () => ( {
	ProfileWithOwners: ( props ) => {
		mockProfile = props;
		return <p>profile</p>;
	},
} ) );

import { renderComponent } from '../../../test-helpers/renderHook';
import block from '../profile';

const { View } = block;

beforeEach( () => {
	mockSite = {};
	mockServer = null;
	mockProfile = null;
} );

/**
 * Mount the block.
 *
 * @return {string} What it drew.
 */
function drawn() {
	const view = renderComponent( <View /> );
	const { container } = view;
	const html = container.innerHTML;
	view.unmount();
	return html;
}

test( 'a refusal is shown in place of the profile', () => {
	mockSite = {
		error: 'the overview timed out on partition 9',
		loading: false,
		overview: null,
	};
	expect( drawn() ).toContain( 'the overview timed out on partition 9' );
} );

test( 'a first load shows a placeholder, not the empty notice', () => {
	mockSite = { loading: true, overview: null };
	const html = drawn();
	expect( html ).not.toContain( 'No request was profiled' );
	expect( mockProfile ).toBeNull();
} );

test( 'a window that profiled no request says so', () => {
	mockSite = { loading: false, overview: { leaderboard: { count: 0 } } };
	expect( drawn() ).toContain( 'No request was profiled in this window.' );
} );

test( 'a profile is titled by the server filter it stands under', () => {
	mockServer = 'kea.example';
	mockSite = {
		loading: false,
		overview: {
			leaderboard: { count: 3, avg_ms: 812 },
			owners: [ { owner: 'core' } ],
		},
	};
	drawn();
	expect( mockProfile.heading ).toBe( 'Time Breakdown (kea.example)' );
	expect( mockProfile.totalMs ).toBe( 812 );
	expect( mockProfile.serverName ).toBe( 'kea.example' );
} );

test( 'an unfiltered profile is the global one', () => {
	mockSite = {
		loading: false,
		overview: { leaderboard: { count: 3, avg_ms: 5 }, owners: [] },
	};
	drawn();
	expect( mockProfile.heading ).toBe( 'Global Time Breakdown' );
} );
