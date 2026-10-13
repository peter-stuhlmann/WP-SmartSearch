import {
	CONFIRMED_EVENT,
	initConfirmations,
} from '../../assets/src/admin/confirm';

/**
 * Renders a button that asks for confirmation, and the dialog it uses.
 *
 * jsdom has no modal dialogs, so showModal and close are stood in for.
 *
 * @param {string} [attributes] Further attributes of the button.
 * @return {{button: HTMLButtonElement, dialog: HTMLElement, confirmed: jest.Mock}} The parts of the page.
 */
function renderButton( attributes = '' ) {
	document.body.innerHTML = `
		<button type="button" ${ attributes }
			data-prstsearch-confirm="This cannot be undone."
			data-prstsearch-confirm-title="Empty the index?"
			data-prstsearch-confirm-label="Empty index">Empty index</button>
		<dialog data-prstsearch-dialog>
			<h2 class="prstsearch-dialog__title"></h2>
			<p class="prstsearch-dialog__text"></p>
			<button data-prstsearch-dialog-confirm></button>
		</dialog>`;

	const button = document.querySelector( 'button' );
	const dialog = document.querySelector( 'dialog' );
	const confirmed = jest.fn();

	dialog.showModal = jest.fn();
	button.addEventListener( CONFIRMED_EVENT, confirmed );

	return { button, dialog, confirmed };
}

/**
 * Closes the dialog the way the browser does when one of its buttons is pressed.
 *
 * @param {HTMLElement} dialog      The dialog.
 * @param {string}      returnValue Value of the pressed button.
 */
async function closeDialog( dialog, returnValue ) {
	dialog.returnValue = returnValue;
	dialog.dispatchEvent( new Event( 'close' ) );

	await Promise.resolve();
	await Promise.resolve();
}

describe( 'initConfirmations', () => {
	beforeAll( () => {
		// Listens on the document, so once is enough for all tests.
		initConfirmations();
	} );

	it( 'asks with the texts of the control', () => {
		const { button, dialog } = renderButton();

		button.click();

		expect( dialog.showModal ).toHaveBeenCalled();
		expect(
			dialog.querySelector( '.prstsearch-dialog__title' ).textContent
		).toBe( 'Empty the index?' );
		expect(
			dialog.querySelector( '.prstsearch-dialog__text' ).textContent
		).toBe( 'This cannot be undone.' );
		expect(
			dialog.querySelector( '[data-prstsearch-dialog-confirm]' )
				.textContent
		).toBe( 'Empty index' );
	} );

	it( 'runs the action after confirmation', async () => {
		const { button, dialog, confirmed } = renderButton();

		button.click();
		await closeDialog( dialog, 'confirm' );

		expect( confirmed ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does nothing when the dialog is cancelled', async () => {
		const { button, dialog, confirmed } = renderButton();

		button.click();
		await closeDialog( dialog, '' );

		expect( confirmed ).not.toHaveBeenCalled();
	} );

	it( 'does not ask while the control is busy', () => {
		const { button, dialog } = renderButton( 'aria-disabled="true"' );

		button.click();

		expect( dialog.showModal ).not.toHaveBeenCalled();
	} );
} );
