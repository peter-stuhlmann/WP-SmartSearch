/**
 * Turns the model name fields into a select with the models of the chosen provider.
 *
 * Progressive enhancement: the server renders a plain text field, which
 * keeps working without this script. The select writes into that field, so
 * the form submits exactly what it would submit without it. "Other model"
 * reveals the text field for names that are not on the list. A provider
 * without a list, such as a custom endpoint that reports no models, shows
 * the text field only.
 */
import { __, sprintf } from '@wordpress/i18n';

const OTHER = '__other';

/**
 * Creates the selects for all model fields of the provider form.
 *
 * @param {HTMLFormElement} form The provider form.
 * @return {{show: Function}} Fills the selects, see showModels().
 */
export function createModelPickers( form ) {
	const pickers = [
		...form.querySelectorAll( '[data-prstsearch-models]' ),
	].map( ( input ) => createPicker( form, input ) );

	return {
		show: ( defaults, resetToPreset ) =>
			pickers.forEach( ( picker ) =>
				showModels( picker, defaults, resetToPreset )
			),
	};
}

/**
 * Puts a select in front of one model field.
 *
 * @param {HTMLFormElement}  form  The provider form.
 * @param {HTMLInputElement} input The model field.
 * @return {{input: HTMLInputElement, select: HTMLSelectElement, label: ?HTMLLabelElement}} The parts of the picker.
 */
function createPicker( form, input ) {
	const wrapper = document.createElement( 'div' );
	const select = document.createElement( 'select' );
	const label = form.querySelector( `label[for="${ input.id }"]` );

	wrapper.className = 'prstsearch-model';
	select.className = 'prstsearch-input';
	select.id = `${ input.id }-select`;

	if ( input.getAttribute( 'aria-describedby' ) ) {
		select.setAttribute(
			'aria-describedby',
			input.getAttribute( 'aria-describedby' )
		);
	}

	// Once the label points to the select, the text field needs a name of its own.
	input.setAttribute(
		'aria-label',
		sprintf(
			/* translators: %s: label of the field, e.g. "Model for the AI answer". */
			__( '%s: model name', 'smart-search' ),
			label?.textContent.trim() ?? ''
		)
	);

	input.replaceWith( wrapper );
	wrapper.append( select, input );

	select.addEventListener( 'change', () => {
		const isOther = select.value === OTHER;

		input.hidden = ! isOther;

		if ( isOther ) {
			input.value = '';
			input.focus();
		} else {
			input.value = select.value;
		}
	} );

	return { input, select, label };
}

/**
 * Fills a select with the models of a provider.
 *
 * @param {{input: HTMLInputElement, select: HTMLSelectElement, label: ?HTMLLabelElement}} picker        The picker.
 * @param {Object}                                                                         defaults      Defaults of the provider, including its model lists.
 * @param {boolean}                                                                        resetToPreset Whether to drop the current model name, because the provider changed.
 */
function showModels( { input, select, label }, defaults, resetToPreset ) {
	const models = defaults[ input.dataset.prstsearchModels ] ?? [];
	const preset = defaults[ input.dataset.prstsearchDefault ] ?? '';
	const hasList = models.length > 0;

	// The list can be replaced while someone is typing a name of their own. That choice stays.
	const isTypingOther = ! resetToPreset && select.value === OTHER;

	// A model name of one provider means nothing to another.
	if ( resetToPreset ) {
		input.value = '';
	}

	select.hidden = ! hasList;

	if ( label ) {
		label.htmlFor = hasList ? select.id : input.id;
	}

	if ( ! hasList ) {
		input.hidden = false;
		return;
	}

	select.replaceChildren(
		// A custom endpoint has no recommended model, only what it reports.
		option(
			'',
			preset
				? sprintf(
						/* translators: %s: model name. */
						__( 'Recommended: %s', 'smart-search' ),
						preset
				  )
				: __( 'Choose a model …', 'smart-search' )
		),
		...models
			.filter( ( model ) => model !== preset )
			.map( ( model ) => option( model, model ) ),
		option( OTHER, __( 'Other model …', 'smart-search' ) )
	);

	// The preset can be stored by name or as "empty"; both mean the first option.
	const current = input.value === preset ? '' : input.value;
	const isListed =
		! isTypingOther && ( current === '' || models.includes( current ) );

	select.value = isListed ? current : OTHER;
	input.hidden = isListed;
}

/**
 * Creates an option element.
 *
 * @param {string} value Value of the option.
 * @param {string} text  Visible text.
 * @return {HTMLOptionElement} The option.
 */
function option( value, text ) {
	const element = document.createElement( 'option' );

	element.value = value;
	element.textContent = text;

	return element;
}
