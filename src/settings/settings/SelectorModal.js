/**
 * `HookSelectorModal` and `CustomEventSelectorModal` both render this chrome: a
 * framed dialog holding a header search box over an actions row, a scrolling
 * body and an optional footer.
 *
 * Each picker supplies what it is selecting and every translated string,
 * because the translation extractor reads only literal `__()` arguments and
 * cannot follow a string handed in as a prop.
 */

import { Modal, SearchControl } from '@wordpress/components';
import '../styles/selector-modal.scss';

/**
 * Selector Modal component.
 *
 * `<Modal>` portals to `document.body`, outside the themed dashboard root, so
 * the frame carries the canonical modal, theme and UI classes itself. A frame
 * missing them resolves its design tokens to nothing.
 *
 * `selector-modal.scss` sizes the dialog from the picker's own class — the
 * hook catalog is wider than the event picker — so no width belongs here.
 *
 * @param {Object}                    props             Component props.
 * @param {string}                    props.title       Dialog title.
 * @param {() => void}                props.onClose     Close callback.
 * @param {string}                    props.search      Current search term.
 * @param {(term: string) => void}    props.onSearch    Called as the term changes.
 * @param {string}                    props.placeholder Search-box placeholder.
 * @param {import('react').ReactNode} [props.actions]   The header's button row.
 * @param {import('react').ReactNode} [props.children]  The selectable list.
 * @param {import('react').ReactNode} [props.footer]    Summary row under the body.
 * @param {string}                    [props.className] The picker's own frame class, plus any skin theming.
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
