/**
 * The profile block: the Time Breakdown the overview averages, the site's or
 * under a server filter that server's, with the Time By Owner table beneath
 * it. A window that profiled no request says so.
 */

import { __, sprintf } from '@wordpress/i18n';
import { usePageState } from '@newspack-nodes/shared/composition/page/pageState';
import Skeleton from '@newspack-nodes/shared/components/Skeleton';
import { ProfileWithOwners } from '../OwnerTable';
import { useSite } from './pageQuestions';

/**
 * @return {import('react').ReactElement} The profile.
 */
function View() {
	const [ server ] = usePageState( 'server' );
	const { overview, loading, error } = useSite();
	if ( error ) {
		return <p className="newspack-nodes-status is-error">{ error }</p>;
	}
	if ( loading && ! overview ) {
		return <Skeleton shape="line" count={ 1 } />;
	}
	if ( ! ( overview?.leaderboard?.count > 0 ) ) {
		return (
			<p className="newspack-nodes-status">
				{ __(
					'No request was profiled in this window.',
					'newspack-event-logger-nodes'
				) }
			</p>
		);
	}
	return (
		<ProfileWithOwners
			profile={ overview.leaderboard }
			totalMs={ overview.leaderboard.avg_ms }
			owners={ overview.owners }
			heading={
				server
					? sprintf(
							// translators: %s: the server name being filtered by.
							__(
								'Time Breakdown (%s)',
								'newspack-event-logger-nodes'
							),
							server
					  )
					: __(
							'Global Time Breakdown',
							'newspack-event-logger-nodes'
					  )
			}
			serverName={ server }
		/>
	);
}

export default { View };
