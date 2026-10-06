/**
 * Removable tokens ahead of the shared `CommitInput` that adds them, in a row
 * of their own when given a row class and in the caller's row otherwise. The
 * Time field and the rule editor's tag input are both one.
 */

import { __, sprintf } from '@wordpress/i18n';
import CommitInput from '@newspack-nodes/shared/components/CommitInput';
import TagToken from './TagToken';

/**
 * What the input takes beyond the `CommitInput` props it hands the box.
 *
 * @typedef  {Object}                  TokenInputOptions
 * @property {string[]}                tokens         Each token's label, in order.
 * @property {(index: number) => void} onRemove       Takes the token at an index away.
 * @property {string}                  [rowClassName] The token row's class; without one the tokens join the caller's row.
 */

/**
 * The options, plus every `CommitInput` prop but `onKeyDown`, which is its own.
 *
 * @typedef {TokenInputOptions & Omit<import('@newspack-nodes/shared/components/CommitInput').CommitInputProps, 'onKeyDown'>} TokenInputProps
 */

/**
 * The input. Backspace in an empty box removes the last token, holding the
 * key, and only while a token exists; an empty field lets it through.
 *
 * @param {TokenInputProps} props The options and the box's props.
 * @return {import('react').ReactElement} The tokens and the box.
 */
export default function TokenInput( {
	tokens,
	onRemove,
	rowClassName,
	...commitInputProps
} ) {
	const drawn = tokens.map( ( label, index ) => (
		<TagToken
			key={ index }
			label={ label }
			onRemove={ () => onRemove( index ) }
			removeLabel={ sprintf(
				// translators: %s: the token's label.
				__( 'Remove %s', 'newspack-event-logger-nodes' ),
				label
			) }
		/>
	) );
	return (
		<>
			{ rowClassName
				? tokens.length > 0 && (
						<div className={ rowClassName }>{ drawn }</div>
				  )
				: drawn }
			<CommitInput
				{ ...commitInputProps }
				onKeyDown={ ( event ) => {
					if (
						'Backspace' === event.key &&
						! event.currentTarget.value &&
						tokens.length
					) {
						event.preventDefault();
						onRemove( tokens.length - 1 );
					}
				} }
			/>
		</>
	);
}
