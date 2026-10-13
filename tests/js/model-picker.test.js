import { createModelPickers } from '../../assets/src/admin/model-picker';

const OPENAI = {
	embedding_model: 'embed-small',
	embedding_models: [ 'embed-small', 'embed-large' ],
};

const CUSTOM = { embedding_model: '', embedding_models: [] };

/**
 * Renders a model row as the server prints it and enhances it.
 *
 * @param {string} storedValue Model name stored in the settings.
 * @return {{showModels: Function, input: HTMLInputElement, select: HTMLSelectElement, label: HTMLLabelElement}} The parts of the row.
 */
function renderRow( storedValue ) {
	document.body.innerHTML = `
		<form>
			<label for="model">Model for semantic search</label>
			<p id="model-description">Help text.</p>
			<input id="model" name="prstsearch[embedding_model]" value="${ storedValue }"
				aria-describedby="model-description"
				data-prstsearch-default="embedding_model"
				data-prstsearch-models="embedding_models" />
		</form>`;

	const pickers = createModelPickers( document.querySelector( 'form' ) );

	return {
		showModels: pickers.show,
		input: document.querySelector( 'input' ),
		select: document.querySelector( 'select' ),
		label: document.querySelector( 'label' ),
	};
}

/**
 * Chooses an option of the select the way a user would.
 *
 * @param {HTMLSelectElement} select The select.
 * @param {string}            value  Value of the option.
 */
function choose( select, value ) {
	select.value = value;
	select.dispatchEvent( new Event( 'change' ) );
}

describe( 'model picker', () => {
	it( 'offers the recommended model first, then the others, then a free entry', () => {
		const { showModels, select } = renderRow( '' );

		showModels( OPENAI, false );

		expect(
			[ ...select.options ].map( ( option ) => option.value )
		).toEqual( [ '', 'embed-large', '__other' ] );
		expect( select.options[ 0 ].textContent ).toBe(
			'Recommended: embed-small'
		);
	} );

	it( 'hides the text field and points the label to the select', () => {
		const { showModels, input, select, label } = renderRow( '' );

		showModels( OPENAI, false );

		expect( input.hidden ).toBe( true );
		expect( label.htmlFor ).toBe( select.id );
		expect( select.getAttribute( 'aria-describedby' ) ).toBe(
			'model-description'
		);
	} );

	it( 'selects the stored model', () => {
		const { showModels, select } = renderRow( 'embed-large' );

		showModels( OPENAI, false );

		expect( select.value ).toBe( 'embed-large' );
	} );

	it( 'treats the recommended model stored by name like an empty field', () => {
		const { showModels, select } = renderRow( 'embed-small' );

		showModels( OPENAI, false );

		expect( select.value ).toBe( '' );
	} );

	it( 'shows the text field for a stored model that is not on the list', () => {
		const { showModels, input, select } = renderRow( 'my-fine-tune' );

		showModels( OPENAI, false );

		expect( select.value ).toBe( '__other' );
		expect( input.hidden ).toBe( false );
		expect( input.value ).toBe( 'my-fine-tune' );
	} );

	it( 'writes the chosen model into the submitted field', () => {
		const { showModels, input, select } = renderRow( '' );

		showModels( OPENAI, false );
		choose( select, 'embed-large' );

		expect( input.value ).toBe( 'embed-large' );
		expect( input.hidden ).toBe( true );
	} );

	it( 'reveals and focuses the text field for another model', () => {
		const { showModels, input, select } = renderRow( 'embed-large' );

		showModels( OPENAI, false );
		choose( select, '__other' );

		expect( input.hidden ).toBe( false );
		expect( input.value ).toBe( '' );
		expect( document.activeElement ).toBe( input );
	} );

	it( 'falls back to the plain text field for a provider without a list', () => {
		const { showModels, input, select, label } = renderRow( 'llama3' );

		showModels( CUSTOM, false );

		expect( select.hidden ).toBe( true );
		expect( input.hidden ).toBe( false );
		expect( input.value ).toBe( 'llama3' );
		expect( label.htmlFor ).toBe( input.id );
	} );

	it( 'drops the model name when the provider changes', () => {
		const { showModels, input, select } = renderRow( 'embed-large' );

		showModels( OPENAI, false );
		showModels(
			{
				embedding_model: 'other-embed',
				embedding_models: [ 'other-embed' ],
			},
			true
		);

		expect( input.value ).toBe( '' );
		expect( select.value ).toBe( '' );
	} );
} );
