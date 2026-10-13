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
		const html = await finishedAnswer( params, region, body );

		if ( html === null ) {
			// The stream broke off after text had arrived. That text stays; asking again would be a second answer.
			body.setAttribute( 'aria-busy', 'false' );
			return;
		}

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
 * @param {HTMLElement}                region The placeholder region.
 * @param {HTMLElement}                body   Element the answer is shown in.
 * @return {Promise<?string>} The markup, empty if there is no answer, null if what the stream showed has to do.
 */
async function finishedAnswer( params, region, body ) {
	if ( settings().streamAnswers && typeof EventSource === 'function' ) {
		const stream = await streamedAnswer( params, region, body );

		if ( stream.html !== null ) {
			return stream.html;
		}

		// A new search has replaced the region. Nobody is waiting for this answer any more.
		if ( ! region.isConnected ) {
			return '';
		}

		// The server goes on writing an answer it has begun. A second request would only be a second one to pay for.
		if ( stream.hasText ) {
			return null;
		}

		// Nothing arrived at all: the server cannot stream, or the connection failed early. The ordinary route still works.
	}

	return ( await getJson( '/answer', params ) ).html;
}

/**
 * Shows the text of an answer piece by piece.
 *
 * @param {{q: string, token: string}} params Query and log token.
 * @param {HTMLElement}                region The placeholder region.
 * @param {HTMLElement}                body   Element the answer is shown in.
 * @return {Promise<{html: ?string, hasText: boolean}>} The markup of the finished answer, empty if there is none,
 *                                                      null if the stream failed; and whether any text was shown.
 */
function streamedAnswer( params, region, body ) {
	return new Promise( ( resolve ) => {
		const source = new EventSource( routeUrl( '/answer/stream', params ) );
		let draft = null;

		const finish = ( html ) => {
			// Closing also stops the browser from connecting again, which would request a second answer.
			source.close();
			resolve( { html, hasText: draft !== null } );
		};

		// Whatever the server sends is read as data that may be broken, not trusted to be well-formed.
		const read = ( event, use ) => {
			// A region that left the page, because a new search replaced it, needs no answer any more.
			if ( ! region.isConnected ) {
				finish( null );
				return;
			}

			try {
				use( JSON.parse( event.data ) );
			} catch {
				finish( null );
			}
		};

		source.addEventListener( 'text', ( event ) =>
			read( event, ( piece ) => {
				if ( ! draft ) {
					draft = document.createElement( 'p' );
					draft.className = 'prstsearch-answer__draft';
					// The growing text is for the eye. Read aloud, every piece would interrupt the one before;
					// the finished answer is read once.
					draft.setAttribute( 'aria-hidden', 'true' );
					body.replaceChildren( draft );
				}

				// Inserted as text: what the model writes is never treated as markup.
				draft.append( String( piece ) );
			} )
		);

		source.addEventListener( 'done', ( event ) =>
			read( event, ( data ) =>
				finish( typeof data?.html === 'string' ? data.html : null )
			)
		);

		source.addEventListener( 'error', () => finish( null ) );
	} );
}
