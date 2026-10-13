/**
 * Confirmation dialog for actions that cannot be undone.
 *
 * Uses the native dialog element, which traps focus, closes on Escape and
 * returns focus to the triggering control.
 */

export const CONFIRMED_EVENT = 'prstsearch:confirmed';

/**
 * Asks for confirmation.
 *
 * @param {{title: string, text: string, label: string}} texts Texts of the dialog.
 * @return {Promise<boolean>} Whether the action was confirmed.
 */
export function confirmAction( { title, text, label } ) {
	const dialog = document.querySelector( '[data-prstsearch-dialog]' );

	// Without a dialog element the browser's own prompt is the safe fallback.
	if ( ! dialog || typeof dialog.showModal !== 'function' ) {
		return Promise.resolve( window.confirm( `${ title }\n\n${ text }` ) ); // eslint-disable-line no-alert
	}

	dialog.querySelector( '.prstsearch-dialog__title' ).textContent = title;
	dialog.querySelector( '.prstsearch-dialog__text' ).textContent = text;
	dialog.querySelector( '[data-prstsearch-dialog-confirm]' ).textContent =
		label;
	dialog.returnValue = '';

	return new Promise( ( resolve ) => {
		dialog.addEventListener(
			'close',
			() => resolve( dialog.returnValue === 'confirm' ),
			{ once: true }
		);
		dialog.showModal();
	} );
}

/**
 * Makes every control with a data-prstsearch-confirm attribute ask first.
 *
 * Links are followed and forms submitted after confirmation; plain buttons
 * receive a "prstsearch:confirmed" event instead.
 */
export function initConfirmations() {
	document.addEventListener( 'click', async ( event ) => {
		const control =
			event.target instanceof Element
				? event.target.closest( '[data-prstsearch-confirm]' )
				: null;

		if ( ! control ) {
			return;
		}

		// A control that is busy stays focusable, but must not start its action a second time.
		if ( control.getAttribute( 'aria-disabled' ) === 'true' ) {
			event.preventDefault();
			return;
		}

		// A form with missing required fields should show its own message first.
		if ( control.form && ! control.form.reportValidity() ) {
			event.preventDefault();
			return;
		}

		event.preventDefault();

		const isConfirmed = await confirmAction( {
			title: control.dataset.prstsearchConfirmTitle,
			text: control.dataset.prstsearchConfirm,
			label: control.dataset.prstsearchConfirmLabel,
		} );

		if ( ! isConfirmed ) {
			return;
		}

		if ( control instanceof HTMLAnchorElement ) {
			window.location.assign( control.href );
		} else if ( control.form && control.type === 'submit' ) {
			control.form.requestSubmit( control );
		} else {
			control.dispatchEvent(
				new CustomEvent( CONFIRMED_EVENT, { bubbles: true } )
			);
		}
	} );
}
