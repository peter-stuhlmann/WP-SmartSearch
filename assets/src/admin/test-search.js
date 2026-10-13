/**
 * "Try the search" field on the settings page.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { errorMessage, request } from './api';

/**
 * Wires up the test form, if it is on the page.
 */
export function initTestSearch() {
	const form = document.querySelector( '[data-prstsearch-test]' );

	if ( ! form ) {
		return;
	}

	const input = form.querySelector( 'input[type="search"]' );
	const status = form.querySelector( '[data-prstsearch-test-status]' );
	const list = form.querySelector( '[data-prstsearch-test-results]' );

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();

		status.textContent = __( 'Searching …', 'smart-search' );
		list.replaceChildren();

		try {
			const data = await request(
				addQueryArgs( '/search', { q: input.value, preview: true } )
			);

			status.textContent = summary( data );
			list.replaceChildren( ...data.results.map( resultItem ) );
		} catch ( error ) {
			status.textContent = errorMessage(
				error,
				__( 'The test search failed.', 'smart-search' )
			);
		}
	} );
}

/**
 * Describes the outcome of a test search in one sentence.
 *
 * @param {Object} data Response of the search route.
 * @return {string} The summary.
 */
function summary( data ) {
	const count = sprintf(
		/* translators: %d: number of results. */
		_n( '%d result.', '%d results.', data.total, 'smart-search' ),
		data.total
	);

	if ( data.corrected ) {
		return (
			count +
			' ' +
			sprintf(
				/* translators: %s: corrected search phrase. */
				__(
					'Searched for “%s” instead, because the spelling found nothing.',
					'smart-search'
				),
				data.corrected
			)
		);
	}

	// Semantic search was wanted but could not be used: say so, the results alone would hide it.
	return data.degraded
		? count +
				' ' +
				__(
					'The AI provider was not available, so this is a keyword search.',
					'smart-search'
				)
		: count;
}

/**
 * Builds the list item of one result.
 *
 * @param {Object} result A result of the search route.
 * @return {HTMLLIElement} The list item.
 */
function resultItem( result ) {
	const item = document.createElement( 'li' );
	const title = document.createElement( 'a' );
	const excerpt = document.createElement( 'p' );
	const reason = document.createElement( 'p' );

	item.className = 'prstsearch-test__result';

	title.className = 'prstsearch-test__title';
	title.href = result.url;
	title.textContent = result.title;

	excerpt.className = 'prstsearch-test__excerpt';
	// Escaped on the server; contains only <mark> around the search words.
	excerpt.innerHTML = result.excerpt;

	reason.className = 'prstsearch-test__reason';
	reason.textContent = sprintf(
		/* translators: 1: relevance level, 2: raw score, 3: explanation why the result was found. */
		__( '%1$s (%2$s) · %3$s', 'smart-search' ),
		result.relevance_label,
		result.score.toLocaleString(
			document.documentElement.lang || undefined,
			{ minimumFractionDigits: 2 }
		),
		result.explanation
	);

	item.append( title, excerpt, reason );

	return item;
}
