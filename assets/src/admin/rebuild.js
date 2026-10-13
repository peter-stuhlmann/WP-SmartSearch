/**
 * Rebuilding and emptying the index from the settings page.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';

import { errorMessage, request } from './api';
import { CONFIRMED_EVENT } from './confirm';

/**
 * Share of the progress bar used for reading the content; the rest is for the AI provider.
 */
const READING_SHARE = 0.6;

/**
 * Another run, usually the background job, can hold the queue. How long to
 * wait for it, and how often, before telling the user that it carries on by itself.
 */
const BUSY_WAIT_MS = 3000;
const BUSY_ATTEMPTS = 20;

/**
 * Wires up all rebuild controls and the "empty index" button.
 */
export function initIndexTools() {
	document
		.querySelectorAll( '[data-prstsearch-rebuild]' )
		.forEach( initRebuild );

	document
		.querySelector( '[data-prstsearch-clear-index]' )
		?.addEventListener( CONFIRMED_EVENT, clearIndex );
}

/**
 * Connects one rebuild button with its progress bar and status line.
 *
 * @param {HTMLElement} container Element holding button, progress and status.
 */
function initRebuild( container ) {
	const button = container.querySelector( '[data-prstsearch-rebuild-start]' );
	const progress = container.querySelector(
		'[data-prstsearch-rebuild-progress]'
	);
	const status = container.querySelector(
		'[data-prstsearch-rebuild-status]'
	);

	const report = ( text, share, state = '' ) => {
		status.textContent = text;
		status.dataset.state = state;
		progress.value = Math.round( share * 100 );
	};

	button.addEventListener( 'click', async () => {
		if ( button.getAttribute( 'aria-disabled' ) === 'true' ) {
			return;
		}

		button.setAttribute( 'aria-disabled', 'true' );
		progress.hidden = false;

		// Screen readers hear the start and the end; the steps in between would only be noise.
		speak( __( 'Rebuilding the index …', 'smart-search' ) );

		try {
			const total = await readContent( report );
			const warning = await createEmbeddings( report );

			if ( warning ) {
				report( warning, 1, 'error' );
				speak( warning, 'assertive' );
			} else {
				const done = sprintf(
					/* translators: %d: number of items. */
					_n(
						'Done. %d item is in the index.',
						'Done. %d items are in the index.',
						total,
						'smart-search'
					),
					total
				);

				report( done, 1 );
				speak( done );
			}
		} catch ( error ) {
			const failed = errorMessage(
				error,
				__(
					'The index could not be rebuilt. Please try again.',
					'smart-search'
				)
			);

			report( failed, 0, 'error' );
			speak( failed, 'assertive' );
		} finally {
			button.removeAttribute( 'aria-disabled' );
		}
	} );
}

/**
 * Reads all content into the index, page by page.
 *
 * @param {Function} report Updates status text and progress.
 * @return {Promise<number>} Number of items the index was built from.
 */
async function readContent( report ) {
	let offset = 0;
	let result;

	do {
		result = await request( '/index/rebuild', {
			method: 'POST',
			data: { offset },
		} );
		offset = result.offset;

		report(
			sprintf(
				/* translators: 1: items processed, 2: all items. */
				__( 'Reading content: %1$d of %2$d', 'smart-search' ),
				Math.min( offset, result.total ),
				result.total
			),
			result.total > 0
				? ( READING_SHARE * offset ) / result.total
				: READING_SHARE
		);
	} while ( ! result.done );

	return result.total;
}

/**
 * Creates the missing embeddings, batch by batch.
 *
 * @param {Function} report Updates status text and progress.
 * @return {Promise<string>} A note if the run did not finish here, otherwise an empty string.
 */
async function createEmbeddings( report ) {
	let initial = null;
	let waited = 0;
	let result;

	do {
		result = await request( '/index/embeddings', { method: 'POST' } );
		initial ??= result.remaining + result.processed;

		if ( result.is_busy ) {
			waited++;

			if ( waited > BUSY_ATTEMPTS ) {
				return __(
					'The content is searchable by keyword. Semantic search is being prepared in the background and will be ready shortly.',
					'smart-search'
				);
			}

			await new Promise( ( resolve ) =>
				window.setTimeout( resolve, BUSY_WAIT_MS )
			);
			continue;
		}

		waited = 0;

		if ( result.error ) {
			return sprintf(
				/* translators: %s: error message of the AI provider. */
				__(
					'The content is searchable by keyword. The AI provider reported: %s',
					'smart-search'
				),
				result.error
			);
		}

		if ( initial > 0 ) {
			report(
				sprintf(
					/* translators: %d: number of text passages. */
					__(
						'Preparing semantic search: %d passages left',
						'smart-search'
					),
					result.remaining
				),
				READING_SHARE +
					( ( 1 - READING_SHARE ) * ( initial - result.remaining ) ) /
						initial
			);
		}
	} while (
		result.is_busy ||
		( result.processed > 0 && result.remaining > 0 )
	);

	return '';
}

/**
 * Empties the index and reloads the page to show the new state.
 *
 * @param {Event} event The confirmation event.
 */
async function clearIndex( event ) {
	const button = event.currentTarget;

	button.setAttribute( 'aria-disabled', 'true' );
	button.parentElement
		.querySelector( '[data-prstsearch-clear-index-error]' )
		?.remove();

	try {
		await request( '/index', { method: 'DELETE' } );
		window.location.reload();
	} catch ( error ) {
		const message = document.createElement( 'p' );

		// Visible, and announced by itself because it is an alert.
		message.className = 'prstsearch-rebuild__status';
		message.dataset.state = 'error';
		message.dataset.prstsearchClearIndexError = '';
		message.setAttribute( 'role', 'alert' );
		message.textContent = errorMessage(
			error,
			__( 'The index could not be emptied.', 'smart-search' )
		);

		button.removeAttribute( 'aria-disabled' );
		button.after( message );
	}
}
