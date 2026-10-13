/**
 * Loads AI answers into their placeholder regions.
 */
import { getJson, routeUrl, settings } from './api';

/**
 * Requests the answer for every placeholder inside a container.
 *
 * The region is revealed while loading and removed again if there is no
 * answer, so visitors never see an empty box.
 *
 * @param {Document|HTMLElement} container Element to look in.
 */
export function loadAnswers( container = document ) {
	container
		.querySelectorAll( '[data-prstsearch-answer]:not([data-loaded])' )
		.forEach( loadAnswer );
}

/**
 * Loads one answer.
 *
 * @param {HTMLElement} region The placeholder region.
 */
async function loadAnswer( region ) {
	const body = region.querySelector( '[data-prstsearch-answer-body]' );
	const params = {
		q: region.dataset.query,
		token: region.dataset.token ?? '',
	};

	region.dataset.loaded = 'true';
	region.hidden = false;

	try {
		const html = await finishedAnswer( params, body );

		if ( ! html ) {
			region.remove();
			return;
		}

		// The markup comes from the plugin's own, escaped PHP templates.
		body.innerHTML = html;
		// Screen readers were told to wait. Now they read the finished answer once.
		body.setAttribute( 'aria-busy', 'false' );
	} catch {
		region.remove();
	}
}

/**
 * Returns the markup of the finished answer, showing its text while it is written if that is switched on.
 *
 * @param {{q: string, token: string}} params Query and log token.
 * @param {HTMLElement}                body   Element the answer is shown in.
 * @return {Promise<string>} The markup, empty if there is no answer.
 */
async function finishedAnswer( params, body ) {
	if ( settings().streamAnswers && typeof EventSource === 'function' ) {
		try {
			return await streamedAnswer( params, body );
		} catch {
			/*
			 * The stream broke off or the server cannot stream. The ordinary
			 * route still works, and if the answer was finished on the server
			 * in the meantime, it comes from the cache.
			 */
		}
	}

	return ( await getJson( '/answer', params ) ).html;
}

/**
 * Shows the text of an answer piece by piece and returns the markup of the finished answer.
 *
 * @param {{q: string, token: string}} params Query and log token.
 * @param {HTMLElement}                body   Element the answer is shown in.
 * @return {Promise<string>} The markup, empty if there is no answer. Rejects if the stream fails.
 */
function streamedAnswer( params, body ) {
	return new Promise( ( resolve, reject ) => {
		const source = new EventSource( routeUrl( '/answer/stream', params ) );
		let text = null;

		source.addEventListener( 'text', ( event ) => {
			if ( ! text ) {
				text = document.createElement( 'p' );
				text.className = 'prstsearch-answer__draft';
				body.replaceChildren( text );
			}

			// Inserted as text: what the model writes is never treated as markup.
			text.append( JSON.parse( event.data ) );
		} );

		source.addEventListener( 'done', ( event ) => {
			source.close();
			resolve( JSON.parse( event.data ).html );
		} );

		source.addEventListener( 'error', () => {
			// Closing stops the browser from connecting again, which would request a second answer.
			source.close();
			reject( new Error( 'The answer stream failed.' ) );
		} );
	} );
}
