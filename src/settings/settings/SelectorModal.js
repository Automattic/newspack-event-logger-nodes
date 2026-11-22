/**
 * `HookSelectorModal` and `CustomEventSelectorModal` both render this chrome: a
 * dialog under a compact header, holding a search box over an actions row, a
 * scrolling body and an optional footer.
 *
 * Each picker supplies what it is selecting and every translated string,
 * because the translation extractor reads only literal `__()` arguments and
 * cannot follow a string handed in as a prop.
 */

import { __ } from '@wordpress/i18n';
import { SearchControl } from '@wordpress/components';
import Modal, { ModalHeader } from '@newspack-nodes/shared/components/Modal';
import '../styles/selector-modal.scss';

/**
 * Selector Modal component: the shared medium dialog under a compact header.
 *
 * `selector-modal.scss` sizes the dialog from the picker's own class, the hook
 * catalog wider and capped lower than the event picker, so neither belongs here.
 *
 * @param {Object}                                                                    props             Component props.
 * @param {string}                                                                    props.title       Dialog title.
 * @param {() => void}                                                                props.onClose     Runs once a close has played the exit.
 * @param {string}                                                                    props.search      Current search term.
 * @param {(term: string) => void}                                                    props.onSearch    Called as the term changes.
 * @param {string}                                                                    props.placeholder Search-box placeholder.
 * @param {import('react').ReactNode}                                                 [props.actions]   The search box's button row.
 * @param {import('react').ReactNode}                                                 [props.children]  The selectable list.
 * @param {import('react').ReactNode}                                                 [props.footer]    Summary row under the body.
 * @param {string}                                                                    [props.className] The picker's own box class, plus any its caller adds.
 * @param {{current: ?import('@newspack-nodes/shared/hooks/useDialog').DialogHandle}} [props.handleRef] Takes the dialog's animated close.
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
	handleRef,
} ) {
	return (
		<Modal
			onClose={ onClose }
			handleRef={ handleRef }
			className={ `${ className } event-logger-selector-modal`.trim() }
		>
			<ModalHeader
				title={ title }
				closeLabel={ __( 'Close', 'newspack-event-logger-nodes' ) }
				compact
			/>
			<div className="event-logger-selector-modal__header">
				<SearchControl
					__next40pxDefaultSize
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
