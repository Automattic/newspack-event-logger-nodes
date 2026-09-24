/**
 * The findings `Findings` computed for one record, rendered: the request page
 * shows its own, and the Ask panel shows each brief's.
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * The status class each severity renders as. `Findings` mints exactly these
 * three, and `info` takes no modifier so it keeps the shared role's own
 * neutral styling.
 *
 * @type {Object<string,string>}
 */
const TONE = {
	high: 'is-error',
	medium: 'is-warning',
	info: '',
};

/**
 * One finding, rendered. This is the half that is worth shipping with no model
 * involved at all: the detector computes, and the numbers speak.
 *
 * @param {Object} props
 * @param {Object} props.finding `severity`, `title`, `detail`, `measured`, and
 *                               a `proposal` carrying the rule edit to make as
 *                               `action` — `none` when there is nothing to
 *                               change — beside the `direction` that edit
 *                               moves visibility in, its `why`, its `undo`,
 *                               and what it acts on: the `hooks` it names,
 *                               or a single `value`.
 * @return {import('react').ReactElement} The rendered finding.
 */
function Finding( { finding } ) {
	const proposal = finding.proposal;
	const target = ( proposal?.hooks ?? [] ).join( ', ' ) || proposal?.value;
	return (
		<li className="event-logger-findings__item">
			<strong
				className={ `newspack-nodes-status ${
					TONE[ finding.severity ] ?? ''
				}` }
			>
				{ finding.title }
			</strong>
			{ finding.detail && <p>{ finding.detail }</p> }
			<p className="event-logger-findings__measured">
				{ sprintf(
					// translators: %s: where the finding was measured.
					__( 'measured: %s', 'newspack-event-logger-nodes' ),
					finding.measured
				) }
			</p>
			{ proposal && 'none' !== proposal.action && (
				<p className="event-logger-findings__proposal">
					<code>{ proposal.action }</code>
					{ target && (
						<>
							{ ' ' }
							<code>{ target }</code>
						</>
					) }{ ' ' }
					{ 'more' === proposal.direction
						? __(
								'— more visibility',
								'newspack-event-logger-nodes'
						  )
						: __( '— less noise', 'newspack-event-logger-nodes' ) }
					{ proposal.why && <span> — { proposal.why }</span> }
				</p>
			) }
			{ proposal?.undo && 'none' !== proposal.action && (
				<p className="event-logger-findings__proposal">
					{ sprintf(
						// translators: %s: how to reverse the proposed rule edit.
						__( 'undo: %s', 'newspack-event-logger-nodes' ),
						proposal.undo
					) }
				</p>
			) }
		</li>
	);
}

/**
 * A record's findings, worst first as they arrive.
 *
 * An empty list looked and found nothing; an absent one had no detector run
 * over it, and saying nothing stands out there is a claim nobody made, so it
 * renders nothing at all.
 *
 * @param {Object} props
 * @param {?Array} props.findings The record's findings, or undefined.
 * @return {?import('react').ReactElement} The list, the empty note, or null.
 */
export default function FindingList( { findings } ) {
	if ( ! Array.isArray( findings ) ) {
		return null;
	}
	if ( 0 === findings.length ) {
		return (
			<p className="newspack-nodes-status">
				{ __(
					'Nothing stands out in the numbers here.',
					'newspack-event-logger-nodes'
				) }
			</p>
		);
	}
	return (
		<ul className="event-logger-findings">
			{ findings.map( ( finding, i ) => (
				<Finding
					key={ `${ finding.kind }-${ i }` }
					finding={ finding }
				/>
			) ) }
		</ul>
	);
}
