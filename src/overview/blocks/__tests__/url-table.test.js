/* eslint-env jest */
/**
 * The URL table block hands its table the `?expand=` pages it restores, and
 * toggling a page adds or drops its key; a control writes its page state, each
 * but the pager's own from the first page.
 */

const mockState = {};
const mockSetters = {};
let mockRaw = [];
let mockExpanded = null;
let mockSetExpanded = null;
let mockTable = null;

jest.mock( '@newspack-nodes/shared/composition/page', () => ( {
	usePage: () => ( {
		selectedSubject: 'ab12:shape.example',
		busySubject: null,
		openSubject: () => {},
	} ),
} ) );
jest.mock( '@newspack-nodes/shared/composition/page/pageState', () => ( {
	usePageState: ( key ) => {
		mockSetters[ key ] ??= jest.fn();
		return [ mockState[ key ], mockSetters[ key ] ];
	},
} ) );
jest.mock( '@newspack-nodes/shared/hooks/useQueryParamState', () => ( {
	useQueryParamList: ( param, restore ) => {
		mockExpanded ??= restore( mockRaw );
		mockSetExpanded = ( update ) => {
			mockExpanded = update( mockExpanded );
		};
		return [ mockExpanded, mockSetExpanded ];
	},
} ) );
jest.mock( '@newspack-nodes/shared/sources', () => ( {
	useSource: () => ( {
		model: { data: [ { url: 'shape' } ], rows: 41 },
		loading: false,
		error: null,
	} ),
} ) );
jest.mock( '../pageQuestions', () => ( {
	useChartSlots: () => [],
	useFilter: () => [ null, () => {} ],
	useUrlsParams: () => ( { sort: 'errors' } ),
} ) );
jest.mock( '../../UrlTable', () => ( {
	__esModule: true,
	default: ( props ) => {
		mockTable = props;
		return null;
	},
} ) );

import { renderComponent } from '../../../test-helpers/renderHook';
import block from '../url-table';

const { View } = block;

beforeEach( () => {
	mockRaw = [];
	mockExpanded = null;
	mockTable = null;
	Object.keys( mockSetters ).forEach( ( key ) => delete mockSetters[ key ] );
} );

/** Mount the block and drop it, leaving the props its table was handed. */
const mountView = () => renderComponent( <View /> ).unmount();

test( 'restores each named page once, its server folded, and drops what names none', () => {
	mockRaw = [
		'ab12:Shape.EXAMPLE',
		'ab12:Shape.EXAMPLE',
		'not a page key',
		'cd34:other.example',
	];
	mountView();
	expect( mockTable.expanded ).toEqual( [
		'ab12:shape.example',
		'cd34:other.example',
	] );
	expect( mockTable.totalRows ).toBe( 41 );
} );

test( 'a bar naming no page leaves the table nothing expanded', () => {
	mockRaw = [ 'nothing' ];
	mountView();
	expect( mockTable.expanded ).toEqual( [] );
} );

test( 'toggling a page adds it, and toggling it again drops it', () => {
	mockRaw = [ 'ab12:shape.example' ];
	mountView();
	mockTable.onToggleExpand( 'ef56:third.example' );
	expect( mockExpanded ).toEqual( [
		'ab12:shape.example',
		'ef56:third.example',
	] );
	mockTable.onToggleExpand( 'ab12:shape.example' );
	expect( mockExpanded ).toEqual( [ 'ef56:third.example' ] );
} );

test( 'a control writes its state and returns the table to its first page', () => {
	mountView();
	mockTable.onQuestion( { sort: 'p95', order: 'asc' } );
	expect( mockSetters.sort ).toHaveBeenCalledWith( 'p95' );
	expect( mockSetters.order ).toHaveBeenCalledWith( 'asc' );
	expect( mockSetters.paged ).toHaveBeenCalledWith( 1 );
} );

test( 'the pager writes its page and keeps it', () => {
	mountView();
	mockTable.onQuestion( { paged: 7 } );
	expect( mockSetters.paged ).toHaveBeenCalledTimes( 1 );
	expect( mockSetters.paged ).toHaveBeenCalledWith( 7 );
} );
