/**
 * The Gyroscope's controls: the legend of the state colors, which stands in
 * the page header with the 0–9 keys that choose the cadence, and the cadence
 * picker, which the page hands the `eln/gyroscope` block to stand in its
 * toolbar after the rate, as it always stood. The cadence is page state's
 * `refresh`, in milliseconds, which the source's view takes its snapshot at,
 * and the browser keeps the choice, in seconds, for the next visit.
 */

import { useCallback, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import RefreshPicker from '@newspack-nodes/shared/components/RefreshPicker';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import { getStateColor } from '@newspack-nodes/shared/utils/formatUtils';
import { isTypingTarget } from '@newspack-nodes/shared/utils/isTypingTarget';
import { writeStorage } from '@newspack-nodes/shared/utils/storage';
import { INFLIGHT_REFRESH_OPTIONS, REFRESH_KEY, badgeStyle } from './sources';

/** The hook categories the legend names, of the many the plugin colors. */
const LEGEND = [
	'Lifecycle',
	'Query & Posts',
	'Content Rendering',
	'Theme',
	'Scripts & Styles',
	'REST API',
];

/** Each digit key => the cadence it picks, in seconds, one the picker offers. */
const KEY_CADENCES = {
	0: 0.1,
	1: 1,
	2: 2,
	3: 3,
	4: 3,
	5: 5,
	6: 5,
	7: 5,
	8: 10,
	9: 10,
};

/**
 * The cadence in seconds, and the one way to choose it: page state for the
 * snapshot, and the browser for the next visit.
 *
 * @return {[number, (seconds: number) => void]} The cadence and its chooser.
 */
function useCadence() {
	const [ refreshMs, setRefreshMs ] = usePageState( 'refresh', {
		writes: true,
	} );
	const choose = useCallback(
		( seconds ) => {
			setRefreshMs( seconds * 1000 );
			writeStorage( REFRESH_KEY, String( seconds ) );
		},
		[ setRefreshMs ]
	);
	return [ Number( refreshMs ) / 1000, choose ];
}

/**
 * The legend, and the 0–9 keys, which choose the cadence wherever focus is
 * not typing.
 *
 * @return {import('react').ReactElement} The legend.
 */
export function GyroscopeLegend() {
	const [ , choose ] = useCadence();

	useEffect( () => {
		const press = ( event ) => {
			const seconds = KEY_CADENCES[ event.key ];
			if ( undefined !== seconds && ! isTypingTarget( event.target ) ) {
				choose( seconds );
			}
		};
		window.addEventListener( 'keydown', press );
		return () => window.removeEventListener( 'keydown', press );
	}, [ choose ] );

	return (
		<div className="event-logger-inflight-legend">
			{ LEGEND.map( ( category ) => (
				<span
					key={ category }
					className="event-logger-state-badge newspack-nodes-badge"
					style={ badgeStyle(
						window.eventLoggerHookCategories?._colors?.[
							category
						] || '#9e9e9e'
					) }
				>
					{ category }
				</span>
			) ) }
			{ [ 'process', 'complete' ].map( ( state ) => (
				<span
					key={ state }
					className="event-logger-state-badge newspack-nodes-badge"
					style={ badgeStyle( getStateColor( state ) ) }
				>
					{ state }
				</span>
			) ) }
		</div>
	);
}

/**
 * The cadence picker.
 *
 * @return {import('react').ReactElement} The picker.
 */
export function CadencePicker() {
	const [ seconds, choose ] = useCadence();
	return (
		<RefreshPicker
			value={ seconds }
			options={ INFLIGHT_REFRESH_OPTIONS }
			onChange={ ( next ) => choose( Number( next ) ) }
			title={ __(
				'Refresh interval (also press 0–9 keys)',
				'newspack-event-logger-nodes'
			) }
		/>
	);
}
