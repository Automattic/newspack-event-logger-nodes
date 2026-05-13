/**
 * Infer a display TYPE label from a substrate node name.
 *
 * The live `ls -al` output exposes only the registered name (e.g.
 * "firehose:tee", "request-builder"). Until the substrate gains an
 * `inspect <node>` verb that returns the class, we infer the type from
 * the part after the colon if present, otherwise from the last
 * hyphen-segment, capitalised.
 *
 * Examples:
 *   firehose:tee       -> TEE
 *   firehose:consumer  -> CONSUMER
 *   request-builder    -> REQUESTBUILDER
 *   jobs:partition     -> PARTITION
 *   sink               -> SINK
 */

export function inferType( name ) {
	if ( ! name || typeof name !== 'string' ) {
		return 'NODE';
	}
	const colonIdx = name.lastIndexOf( ':' );
	const tail = colonIdx >= 0 ? name.slice( colonIdx + 1 ) : name;
	const collapsed = tail.replace( /[-_]/g, '' );
	return collapsed.toUpperCase() || 'NODE';
}
