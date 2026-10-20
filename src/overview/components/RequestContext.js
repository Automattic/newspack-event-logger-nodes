/**
 * What WordPress made of one logged request: the template it included, the
 * rewrite rule and query it matched, the conditionals the main query held
 * true and the main query's vars, from the `request_context` entry
 * `App\Request_Context` writes at shutdown and `Request_Builder_Node` keeps on
 * the record as `context`.
 */

import { __ } from '@wordpress/i18n';

/**
 * A query var as the row lists it: a leaf as written, a branch as JSON.
 *
 * @param {*} value The shaped value.
 * @return {string} Its text.
 */
const varText = ( value ) =>
	null !== value && 'object' === typeof value
		? JSON.stringify( value )
		: String( value );

/**
 * Each key of `context` is present only where WordPress set it: `template`,
 * the included file relative to wp-content or the install's root;
 * `matched_rule` and `matched_query`, the rewrite rule `WP::parse_request()`
 * matched and its query, redacted as a URL's is; `query_vars`, the main
 * query's non-empty vars, an unlisted value read as `?`; `conditionals`, the
 * `is_*` flags the main query held true; and `trimmed`, set when
 * `Request_Context::fit()` cut the map to fit one log line.
 *
 * @param {Object} props         Component props.
 * @param {Object} props.context The record's `context` map.
 * @return {import('react').ReactElement} The context rows.
 */
export default function RequestContext( { context } ) {
	const rows = [
		[ __( 'Template:', 'newspack-event-logger-nodes' ), context.template ],
		[
			__( 'Rewrite rule:', 'newspack-event-logger-nodes' ),
			context.matched_rule,
		],
		[
			__( 'Matched query:', 'newspack-event-logger-nodes' ),
			context.matched_query,
		],
		[
			__( 'Conditionals:', 'newspack-event-logger-nodes' ),
			Array.isArray( context.conditionals )
				? context.conditionals.join( ', ' )
				: '',
		],
		[
			__( 'Query vars:', 'newspack-event-logger-nodes' ),
			context.query_vars && 'object' === typeof context.query_vars
				? Object.entries( context.query_vars )
						.map(
							( [ key, value ] ) =>
								`${ key }=${ varText( value ) }`
						)
						.join( ', ' )
				: '',
		],
	].filter( ( [ , value ] ) => 'string' === typeof value && '' !== value );

	return (
		<div className="event-logger-request-context">
			<h3 className="newspack-nodes-section-heading">
				{ __( 'Context', 'newspack-event-logger-nodes' ) }
			</h3>
			{ rows.map( ( [ label, value ] ) => (
				<p key={ label }>
					<strong>{ label }</strong> { value }
				</p>
			) ) }
			{ context.trimmed && (
				<p className="newspack-nodes-status is-muted">
					{ __(
						'Trimmed to fit one log line: its last query vars went first, then its matched query, rewrite rule and template were halved in that order, then its last conditionals went.',
						'newspack-event-logger-nodes'
					) }
				</p>
			) }
		</div>
	);
}
