import { enhanceCombobox } from '../../assets/src/frontend/combobox';

const SUGGESTIONS = [
	{ title: 'Shipping abroad', url: 'https://example.org/shipping-abroad/' },
	{ title: 'Shipping costs', url: 'https://example.org/shipping-costs/' },
];

/**
 * Builds the markup the search form template prints.
 *
 * @return {{input: HTMLInputElement, listbox: HTMLElement, status: HTMLElement}} The parts of the form.
 */
function renderForm() {
	document.body.innerHTML = `
		<form>
			<div data-prstsearch-combobox>
				<input type="search" id="search" name="s" />
				<ul id="search-suggestions" role="listbox" hidden></ul>
			</div>
			<p role="status" data-prstsearch-suggestion-status></p>
		</form>`;

	enhanceCombobox( document.querySelector( '[data-prstsearch-combobox]' ) );

	return {
		input: document.querySelector( 'input' ),
		listbox: document.querySelector( '[role="listbox"]' ),
		status: document.querySelector( '[role="status"]' ),
	};
}

/**
 * Types into the field and waits for the debounced request to finish.
 *
 * @param {HTMLInputElement} input The search field.
 * @param {string}           value Text to type.
 */
async function type( input, value ) {
	input.value = value;
	input.dispatchEvent( new Event( 'input' ) );

	await jest.runAllTimersAsync();
}

/**
 * Presses a key in the field.
 *
 * @param {HTMLInputElement} input The search field.
 * @param {string}           key   Key name.
 * @return {KeyboardEvent} The dispatched event.
 */
function press( input, key ) {
	const event = new KeyboardEvent( 'keydown', { key, cancelable: true } );

	input.dispatchEvent( event );

	return event;
}

describe( 'enhanceCombobox', () => {
	beforeEach( () => {
		jest.useFakeTimers();

		window.prstsearchSettings = {
			restUrl: 'https://example.org/wp-json/prstsearch/v1',
			strings: {
				suggestions: '%d suggestions available.',
				suggestion: '1 suggestion available.',
			},
		};

		global.fetch = jest.fn( () =>
			Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( SUGGESTIONS ),
			} )
		);

		// jsdom does not implement scrolling.
		window.HTMLElement.prototype.scrollIntoView = jest.fn();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'gives the field the combobox semantics', () => {
		const { input } = renderForm();

		expect( input.getAttribute( 'role' ) ).toBe( 'combobox' );
		expect( input.getAttribute( 'aria-autocomplete' ) ).toBe( 'list' );
		expect( input.getAttribute( 'aria-controls' ) ).toBe(
			'search-suggestions'
		);
		expect( input.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );

	it( 'does not ask for suggestions before two characters are typed', async () => {
		const { input, listbox } = renderForm();

		await type( input, 's' );

		expect( global.fetch ).not.toHaveBeenCalled();
		expect( listbox.hidden ).toBe( true );
	} );

	it( 'opens the list and announces the number of suggestions', async () => {
		const { input, listbox, status } = renderForm();

		await type( input, 'ship' );

		expect( listbox.hidden ).toBe( false );
		expect( input.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( listbox.querySelectorAll( '[role="option"]' ) ).toHaveLength(
			2
		);
		expect( status.textContent ).toBe( '2 suggestions available.' );
	} );

	it( 'renders titles as text, never as markup', async () => {
		global.fetch = jest.fn( () =>
			Promise.resolve( {
				ok: true,
				json: () =>
					Promise.resolve( [
						{ title: '<img src=x onerror=alert(1)>', url: '#' },
					] ),
			} )
		);

		const { input, listbox } = renderForm();

		await type( input, 'img' );

		expect( listbox.querySelector( 'img' ) ).toBeNull();
		expect( listbox.textContent ).toBe( '<img src=x onerror=alert(1)>' );
	} );

	it( 'moves the active option with the arrow keys and wraps around', async () => {
		const { input, listbox } = renderForm();

		await type( input, 'ship' );

		press( input, 'ArrowDown' );
		expect( input.getAttribute( 'aria-activedescendant' ) ).toBe(
			listbox.children[ 0 ].id
		);
		expect( listbox.children[ 0 ].getAttribute( 'aria-selected' ) ).toBe(
			'true'
		);

		press( input, 'ArrowDown' );
		press( input, 'ArrowDown' );
		expect( input.getAttribute( 'aria-activedescendant' ) ).toBe(
			listbox.children[ 0 ].id
		);

		press( input, 'ArrowUp' );
		expect( input.getAttribute( 'aria-activedescendant' ) ).toBe(
			listbox.children[ 1 ].id
		);
	} );

	it( 'lets Enter submit the form while no option is active', async () => {
		const { input } = renderForm();

		await type( input, 'ship' );

		expect( press( input, 'Enter' ).defaultPrevented ).toBe( false );
	} );

	it( 'closes the list with Escape', async () => {
		const { input, listbox } = renderForm();

		await type( input, 'ship' );
		press( input, 'Escape' );

		expect( listbox.hidden ).toBe( true );
		expect( input.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( input.hasAttribute( 'aria-activedescendant' ) ).toBe( false );
	} );

	it( 'uses the singular sentence for a single suggestion', async () => {
		global.fetch = jest.fn( () =>
			Promise.resolve( {
				ok: true,
				json: () => Promise.resolve( SUGGESTIONS.slice( 0, 1 ) ),
			} )
		);

		const { input, status } = renderForm();

		await type( input, 'ship' );

		expect( status.textContent ).toBe( '1 suggestion available.' );
	} );

	it( 'clears the announcement when the list closes', async () => {
		const { input, status } = renderForm();

		await type( input, 'ship' );
		press( input, 'Escape' );

		expect( status.textContent ).toBe( '' );
	} );

	it( 'opens the chosen suggestion with Enter', async () => {
		const { input } = renderForm();
		const assign = jest.fn();

		// jsdom cannot navigate; the call is what matters.
		delete window.location;
		window.location = { assign, origin: 'https://example.org' };

		await type( input, 'ship' );
		press( input, 'ArrowDown' );
		press( input, 'ArrowDown' );

		expect( press( input, 'Enter' ).defaultPrevented ).toBe( true );
		expect( assign ).toHaveBeenCalledWith( SUGGESTIONS[ 1 ].url );
	} );

	it( 'brings a closed list back with the down arrow', async () => {
		const { input, listbox } = renderForm();

		await type( input, 'ship' );
		press( input, 'Escape' );

		expect( press( input, 'ArrowDown' ).defaultPrevented ).toBe( true );
		await jest.runAllTimersAsync();

		expect( listbox.hidden ).toBe( false );
	} );

	it( 'does not open the list after the field was left', async () => {
		let respond;

		global.fetch = jest.fn(
			() =>
				new Promise( ( resolve ) => {
					respond = () =>
						resolve( {
							ok: true,
							json: () => Promise.resolve( SUGGESTIONS ),
						} );
				} )
		);

		const { input, listbox } = renderForm();

		await type( input, 'ship' );
		input.dispatchEvent( new Event( 'blur' ) );
		respond();
		await jest.runAllTimersAsync();

		expect( listbox.hidden ).toBe( true );
	} );

	it( 'does not ask for suggestions once the field was left before the pause ended', async () => {
		const { input } = renderForm();

		input.value = 'ship';
		input.dispatchEvent( new Event( 'input' ) );
		input.dispatchEvent( new Event( 'blur' ) );
		await jest.runAllTimersAsync();

		expect( global.fetch ).not.toHaveBeenCalled();
	} );

	it( 'does not ask the server twice for the same text', async () => {
		const { input, listbox } = renderForm();

		await type( input, 'ship' );
		await type( input, 'shipp' );
		await type( input, 'Ship' );

		expect( global.fetch ).toHaveBeenCalledTimes( 2 );
		expect( listbox.hidden ).toBe( false );
	} );

	it( 'keeps working when the request fails', async () => {
		global.fetch = jest.fn( () =>
			Promise.reject( new Error( 'offline' ) )
		);

		const { input, listbox } = renderForm();

		await type( input, 'ship' );

		expect( listbox.hidden ).toBe( true );
	} );
} );
