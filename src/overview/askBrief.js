/**
 * Render an assembled brief as markdown — the thing you paste into whatever
 * assistant you have, with no integration and no credential.
 *
 * Every brief ends with its caveat. That is not documentation: a model handed
 * `175.6ms profiled / 420000ms duration` with nothing saying what is unmeasured
 * WILL invent a cause for the difference, and the invented cause reads exactly
 * like a finding.
 */

/** A prompt longer than this stops being a link and starts being a payload. */
const PROMPT_MAX = 6000;

/** What the brief is, for a chat that has no other context. */
const PROMPT_INTRO =
	'This is a performance brief from my site\u2019s event logger. Tell me what is actually slow and what to change:';

/** When the brief will not fit in a URL, the link carries the ask instead. */
const PROMPT_TOO_LONG =
	'I have a performance brief from my site\u2019s event logger. I will paste it next — read it and tell me what is actually slow and what to change.';

/**
 * A claude.ai chat with the brief already in it.
 *
 * A link cannot connect an MCP server, so this carries the brief itself; where
 * the site is reachable and its MCP server IS connected, the `fetch` lines in
 * the brief let that chat pull the detail this one trimmed.
 *
 * @param {string} markdown The brief, as `briefToMarkdown` rendered it.
 * @return {string} An absolute claude.ai URL.
 */
export function askClaudeUrl( markdown ) {
	const full = `${ PROMPT_INTRO }\n\n${ markdown }`;
	const prompt =
		encodeURIComponent( full ).length <= PROMPT_MAX
			? full
			: PROMPT_TOO_LONG;
	return `https://claude.ai/new?q=${ encodeURIComponent( prompt ) }`;
}

/** Where the `fetch` calls above are answered. Named once per document. */
const MCP_NOTE =
	'Run those with this site\u2019s MCP server at %s — it answers the same verbs this brief was built from.';

/**
 * A number a reader can scan, without dragging a formatter in.
 *
 * @param {*} value Anything numeric-ish.
 * @return {string} The rendered number.
 */
function num( value ) {
	if ( 'number' !== typeof value || ! Number.isFinite( value ) ) {
		return String( value ?? '—' );
	}
	return Number.isInteger( value ) ? String( value ) : value.toFixed( 1 );
}

/**
 * `key: value` lines for a flat object, skipping what is absent.
 *
 * @param {Array<Array>} pairs `[ label, value ]` tuples.
 * @return {string[]} Markdown list items.
 */
function fields( pairs ) {
	return pairs
		.filter(
			( [ , value ] ) =>
				undefined !== value && null !== value && '' !== value
		)
		.map( ( [ key, value ] ) => `- **${ key }:** ${ value }` );
}

/**
 * The rule an edit would land on — or the fact that there is none.
 *
 * @param {?Object} rule The governing rule, or null.
 * @return {string[]} Markdown list items.
 */
function ruleLines( rule ) {
	if ( ! rule ) {
		return [
			'- **rule:** none — no rule governs this URL, so nothing about it is logged',
		];
	}
	return fields( [
		[ 'rule', `\`${ rule.id }\` \`${ rule.pattern }\` (${ rule.action })` ],
		[
			'hooks',
			null === rule.hook_count ? 'stored out of line' : rule.hook_count,
		],
		[ 'custom events', rule.custom_event_count ],
		[
			'significant events',
			( rule.significant_events ?? [] ).join( ', ' ),
		],
	] );
}

/**
 * One finding: the claim, the number, where it was measured, what to do.
 *
 * @param {Object} finding An assembled finding.
 * @return {string[]} Markdown lines.
 */
function findingLines( finding ) {
	const lines = [ `### ${ finding.title }`, '' ];
	if ( finding.detail ) {
		lines.push( finding.detail, '' );
	}
	lines.push(
		`- **severity:** ${ finding.severity } · **measured:** ${ finding.measured }`
	);
	const metric = finding.metric ?? {};
	const numbers = Object.keys( metric )
		.map( ( key ) => `${ key }=${ num( metric[ key ] ) }` )
		.join( ' ' );
	if ( numbers ) {
		lines.push( `- **numbers:** ${ numbers }` );
	}
	const proposal = finding.proposal;
	if ( proposal && 'none' !== proposal.action ) {
		lines.push(
			`- **proposed:** \`${ proposal.action }\` (${ proposal.direction } visibility)` +
				( proposal.value ? ` — \`${ proposal.value }\`` : '' ) +
				( proposal.hooks?.length
					? ` — ${ proposal.hooks.join( ', ' ) }`
					: '' )
		);
		if ( proposal.why ) {
			lines.push( `  - why: ${ proposal.why }` );
		}
		// Every proposal that ADDS instrumentation names what removes it.
		if ( proposal.undo ) {
			lines.push( `  - undo: ${ proposal.undo }` );
		}
	}
	lines.push( '' );
	return lines;
}

/**
 * Subject-specific body, above the findings.
 *
 * @param {Object} brief An assembled brief.
 * @return {string[]} Markdown list items.
 */
function bodyLines( brief ) {
	switch ( brief.subject ) {
		case 'request':
			return [
				...fields( [
					[ 'url', brief.url ],
					[ 'duration_ms', num( brief.duration_ms ) ],
					[ 'status', brief.status_code ],
					[ 'profiled_ms', num( brief.flame?.profiled_ms ) ],
					[
						'top spans',
						( brief.flame?.top_level ?? [] )
							.slice( 0, 6 )
							.map(
								( s ) =>
									`${ s.name } ${ num( s.ms ) }ms×${
										s.count
									}`
							)
							.join( ', ' ),
					],
					[
						'env',
						Object.entries( brief.env ?? {} )
							.map( ( [ k, v ] ) => `${ k }=${ v }` )
							.join( ' ' ),
					],
					[
						'entries',
						`${ ( brief.entries ?? [] ).length }${
							brief.entries_truncated ? ' (truncated)' : ''
						}`,
					],
				] ),
				...ruleLines( brief.rule ),
			];
		case 'url':
			return [
				...fields( [
					[ 'url', brief.url ],
					[ 'count', brief.stats?.count ],
					[ 'avg_ms', num( brief.stats?.avg_ms ) ],
					[ 'p95_ms', num( brief.stats?.p95_ms ) ],
					[ 'max_peak_mb', num( brief.stats?.max_peak_mb ) ],
					[
						'worst recent',
						( brief.worst_requests ?? [] )
							.map(
								( r ) =>
									`${ r.rid } ${ num( r.duration_ms ) }ms ${
										r.status_code
									}`
							)
							.join( ', ' ),
					],
				] ),
				...ruleLines( brief.rule ),
			];
		case 'span':
			return [
				...fields( [
					[ 'span', brief.name ],
					[ 'ms', num( brief.ms ) ],
					[ 'calls', brief.count ],
					[
						'parent',
						`${ brief.parent ?? '' } ${ num( brief.parent_ms ) }ms`,
					],
					[
						'siblings',
						( brief.siblings ?? [] )
							.map( ( s ) => `${ s.name } ${ num( s.ms ) }ms` )
							.join( ', ' ),
					],
					[
						'inside it',
						( brief.subtree ?? [] )
							.map(
								( s ) =>
									`${ s.name } ${ num( s.ms ) }ms×${
										s.count
									}`
							)
							.join( ', ' ),
					],
					[ 'url', brief.url ],
				] ),
				...ruleLines( brief.rule ),
			];
		case 'entry':
			return fields( [
				[ 'entry', `#${ brief.entry?.n } ${ brief.entry?.k }` ],
				[ 'message', brief.entry?.m ],
				[
					'gap before',
					null === brief.gap_before_ms
						? 'start of request'
						: `${ num( brief.gap_before_ms ) }ms`,
				],
				[
					'gap after',
					null === brief.gap_after_ms
						? 'end of request'
						: `${ num( brief.gap_after_ms ) }ms`,
				],
				[
					'around it',
					( brief.neighbours ?? [] )
						.map( ( e ) => `#${ e.n } ${ e.k }` )
						.join( ', ' ),
				],
				[ 'url', brief.url ],
			] );
		case 'category':
			return fields( [
				[ 'category', brief.name ],
				[ 'avg_time_ms', num( brief.avg_time_ms ) ],
				[ 'avg_count', num( brief.avg_count ) ],
				[ 'share', `${ Math.round( ( brief.share ?? 0 ) * 100 ) }%` ],
				[
					'competing with',
					( brief.others ?? [] )
						.slice( 0, 6 )
						.map(
							( o ) => `${ o.name } ${ num( o.avg_time_ms ) }ms`
						)
						.join( ', ' ),
				],
			] );
		default:
			return [];
	}
}

/**
 * The tool call that fetches this thing again, as an agent would type it.
 *
 * A brief is deliberately trimmed — the time series, the rosters and the deep
 * spans stay on the server. This is the address of the rest.
 *
 * @param {Object} brief An assembled brief.
 * @return {string[]} Markdown list items.
 */
function fetchLines( brief ) {
	return ( brief.fetch ?? [] ).map( ( call ) => {
		const args = Object.entries( call.arguments ?? {} )
			.map( ( [ key, value ] ) => ` ${ key }="${ value }"` )
			.join( '' );
		return `- **fetch:** \`${ call.tool }${ args }\``;
	} );
}

/**
 * One brief, or several, as markdown.
 *
 * @param {Object|Object[]|null} briefs     An assembled brief, or a list of them.
 * @param {string}               [endpoint] This site's MCP endpoint, named once
 *                                          at the end so the `fetch` lines above
 *                                          are addressable.
 * @return {string} Markdown, or '' when there is nothing to say.
 */
export function briefToMarkdown( briefs, endpoint = '' ) {
	const list = Array.isArray( briefs ) ? briefs : [ briefs ];
	const sections = list.filter( Boolean ).map( ( brief ) => {
		const lines = [
			`## ${ brief.subject }`,
			'',
			...bodyLines( brief ),
			...fetchLines( brief ),
			'',
		];
		const findings = brief.findings ?? [];
		if ( findings.length ) {
			lines.push( '### Findings', '' );
			for ( const finding of findings ) {
				lines.push( ...findingLines( finding ) );
			}
		}
		if ( brief.caveat ) {
			lines.push( `> ${ brief.caveat }`, '' );
		}
		return lines.join( '\n' );
	} );

	const document = sections.join( '\n' ).trim();
	if ( '' === document || ! endpoint ) {
		return document;
	}
	return `${ document }\n\n${ MCP_NOTE.replace( '%s', endpoint ) }`;
}
