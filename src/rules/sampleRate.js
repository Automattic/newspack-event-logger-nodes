/**
 * A rule's sample rate as the share of requests it logs, to three significant
 * digits, and to as many more as a rate just under 1 needs, so a rate of 1 in
 * 2,500 never reads as 0 and one dropping 1 request in 2,500 never reads as
 * every request.
 *
 * @param {number} rate The rule's `sample_rate`, from 0 to 1.
 * @return {string} The share, such as `12.5%`, `0.04%` or `99.96%`.
 */
export function samplePercent( rate ) {
	let digits = 3;
	let shown = parseFloat( ( rate * 100 ).toPrecision( digits ) );
	while ( rate < 1 && shown >= 100 && digits < 17 ) {
		digits++;
		shown = parseFloat( ( rate * 100 ).toPrecision( digits ) );
	}
	return `${ shown }%`;
}
