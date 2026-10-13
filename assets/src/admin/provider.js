/**
 * Provider form: recommended values as placeholders, and the connection test.
 */
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage, request } from './api';
import { createModelPickers } from './model-picker';

/**
 * Wires up the provider form, if it is on the page.
 */
export function initProviderForm() {
	const form = document.querySelector( '[data-prstsearch-provider-form]' );

	if ( ! form ) {
		return;
	}

	// A copy, because the model lists are replaced by what the provider reports.
	const state = {
		form,
		pickers: createModelPickers( form ),
		defaults: { ...window.prstsearchAdmin.providerDefaults },
	};

	// The test runs on the server with the saved settings, so it must not
	// pretend to check values that only exist in the form.
	let hasUnsavedChanges = false;

	form.addEventListener( 'change', ( event ) => {
		hasUnsavedChanges = true;

		if (
			event.target.matches( '[data-prstsearch-setting="provider"] input' )
		) {
			showDefaults( state, true );
		}
	} );

	showDefaults( state, false );
	loadLiveModels( state );

	form.querySelector( '[data-prstsearch-provider-test]' )?.addEventListener(
		'click',
		( event ) => {
			const status = form.querySelector(
				'[data-prstsearch-provider-status]'
			);

			if ( hasUnsavedChanges ) {
				status.dataset.state = 'error';
				status.textContent = __(
					'You have unsaved changes. Save first: the test checks the saved settings.',
					'smart-search'
				);
				return;
			}

			testConnection( event.currentTarget, status );
		}
	);
}

/**
 * Replaces the built-in model list of the saved provider with the one the provider reports.
 *
 * The built-in list is only as current as the plugin; the provider's own
 * list includes models released since. If the provider cannot be asked, for
 * example because no API key is saved yet, the built-in list stays.
 *
 * @param {{form: HTMLFormElement, pickers: {show: Function}, defaults: Object}} state The form and what is known about the providers.
 */
async function loadLiveModels( state ) {
	let live;

	try {
		live = await request( '/provider/models' );
	} catch {
		return;
	}

	const builtIn = state.defaults[ live?.provider ];

	if ( ! builtIn ) {
		return;
	}

	const reported = Object.fromEntries(
		[ 'embedding_models', 'chat_models' ]
			.filter(
				( key ) =>
					Array.isArray( live[ key ] ) && live[ key ].length > 0
			)
			.map( ( key ) => [ key, live[ key ] ] )
	);

	state.defaults = {
		...state.defaults,
		[ live.provider ]: { ...builtIn, ...reported },
	};

	showDefaults( state, false );
}

/**
 * Adapts the form to the selected provider: its models, and its list prices as placeholders.
 *
 * @param {{form: HTMLFormElement, pickers: {show: Function}, defaults: Object}} state           The form and what is known about the providers.
 * @param {boolean}                                                              providerChanged Whether the user just switched the provider.
 */
function showDefaults( state, providerChanged ) {
	const { form } = state;
	const provider = form.querySelector(
		'[data-prstsearch-setting="provider"] input:checked'
	)?.value;
	const defaults = state.defaults[ provider ] ?? {};
	const endpoint = form.querySelector( '#prstsearch-base-url' );

	// The address row is hidden for the other providers. A hidden field must
	// not take part in validation, or an invalid value would block the form
	// without any visible message. Disabled fields keep their stored value.
	if ( endpoint ) {
		endpoint.disabled = provider !== 'custom';
	}

	state.pickers.show( defaults, providerChanged );

	form.querySelectorAll( '[data-prstsearch-default]' ).forEach( ( input ) => {
		input.placeholder = String(
			defaults[ input.dataset.prstsearchDefault ] ?? ''
		);
	} );
}

/**
 * Runs the connection test and shows the outcome next to the button.
 *
 * @param {HTMLButtonElement} button The test button.
 * @param {HTMLElement}       status Live region for the result.
 */
async function testConnection( button, status ) {
	if ( button.getAttribute( 'aria-disabled' ) === 'true' ) {
		return;
	}

	button.setAttribute( 'aria-disabled', 'true' );
	status.dataset.state = '';
	status.textContent = __( 'Testing the connection …', 'smart-search' );

	try {
		const { embedding, chat } = await request( '/provider/test', {
			method: 'POST',
		} );

		status.dataset.state = embedding.ok && chat.ok ? 'success' : 'error';
		status.textContent = sprintf(
			/* translators: 1: result for semantic search, 2: result for AI answers. */
			__( 'Semantic search: %1$s AI answer: %2$s', 'smart-search' ),
			embedding.message,
			chat.message
		);
	} catch ( error ) {
		status.dataset.state = 'error';
		status.textContent = errorMessage(
			error,
			__( 'The test could not be run.', 'smart-search' )
		);
	} finally {
		button.removeAttribute( 'aria-disabled' );
	}
}
