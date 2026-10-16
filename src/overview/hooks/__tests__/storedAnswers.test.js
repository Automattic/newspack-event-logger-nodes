/**
 * The questions the overview-prefetch job answers are the ones a default
 * Overview poll sends, token for token: the request chart's default breakdown
 * resolved as the page resolves it, for a fleet that can chart by server and
 * for one that cannot.
 */
import storedAnswers from '../../../../includes/app/stored-answers.json';
import { DEFAULT_CHART_BREAKDOWN } from '../../constants';
import { activeChart } from '../../chartSlots';
import {
	NO_CHIPS,
	overviewArgs,
	urlsArgs,
	seriesArgs,
	categoryArgs,
	siteScope,
	DEFAULT_URL_PARAMS,
} from '../usePerformanceGraph';

/**
 * The request chart's default question over a fleet answering `serverNames`.
 *
 * @param {string[]} serverNames The servers the overview names.
 * @return {string[]} The `query` tokens.
 */
const defaultChart = ( serverNames ) => {
	const { breakdown, thenBy } = activeChart( {
		breakdown: DEFAULT_CHART_BREAKDOWN,
		thenBy: '',
		serverFilter: '',
		serverNames,
	} );
	return seriesArgs(
		{ breakdown, thenBy, chips: NO_CHIPS },
		siteScope( '' )
	);
};

describe( 'stored answers', () => {
	it( 'are the questions a default Overview poll asks', () => {
		expect( storedAnswers ).toEqual( {
			overview: { verb: 'overview', tokens: overviewArgs( '' ) },
			urls: {
				verb: 'urls',
				tokens: urlsArgs( {
					urlParams: DEFAULT_URL_PARAMS,
					serverFilter: '',
				} ),
			},
			chart_by_server: {
				verb: 'query',
				tokens: defaultChart( [ 'kea.example', 'tui.example' ] ),
			},
			chart_by_status_class: {
				verb: 'query',
				tokens: defaultChart( [ 'kea.example' ] ),
			},
			categories: {
				verb: 'query',
				tokens: categoryArgs( siteScope( '' ) ),
			},
		} );
	} );

	it( 'charts the two fleets differently', () => {
		expect( defaultChart( [ 'kea.example', 'tui.example' ] ) ).toContain(
			'server'
		);
		expect( defaultChart( [ 'kea.example' ] ) ).toContain( 'status_class' );
	} );
} );
