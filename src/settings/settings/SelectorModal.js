/**
 * Selector Modal Component
 *
 * The chrome both picker modals wear: the framed dialog, the header search box
 * over an actions row, the scrolling body, and an optional footer. Each picker
 * supplies what it is selecting and every translated string, because
 * `@wordpress/i18n` needs literal arguments at the call site.
 */

import { Modal, SearchControl } from '@wordpress/components';
import '../styles/selector-modal.scss';

/**
 * Selector Modal component.
 *
 * @param {Object}                 props             Component props.
 * @param {string}                 props.title       Dialog title.
 * @param {() => void}             props.onClose     Close callback.
 * @param {string}                 props.search      Current search term.
 * @param {(term: string) => void} props.onSearch    Called as the term changes.
 * @param {string}                 props.placeholder Search-box placeholder.
 * @param {*}                      [props.actions]   The header's button row.
 * @param {*}                      [props.children]  The selectable list.
 * @param {*}                      [props.footer]    Optional footer content.
 * @param {string}                 [props.className] The picker's own frame class, plus any skin theming.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function SelectorModal( {
	title,
	onClose,
	search,
	onSearch,
	placeholder,
	actions,
	children,
	footer,
	className = '',
} ) {
	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			className={ `${ className } event-logger-selector-modal newspack-nodes-modal newspack-nodes-theme newspack-nodes-ui`.trim() }
		>
			<div className="event-logger-selector-modal__header">
				<SearchControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					value={ search }
					onChange={ onSearch }
					placeholder={ placeholder }
				/>
				<div className="event-logger-selector-modal__actions">
					{ actions }
				</div>
			</div>

			<div className="event-logger-selector-modal__body">
				{ children }
			</div>

			{ footer && (
				<div className="event-logger-selector-modal__footer">
					{ footer }
				</div>
			) }
		</Modal>
	);
}
