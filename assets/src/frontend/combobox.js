/**
 * Suggestions below the search field.
 *
 * Follows the WAI-ARIA "combobox with list autocomplete" pattern: focus stays
 * in the input, the highlighted option is announced via aria-activedescendant,
 * and nothing is selected until the visitor chooses to.
 */
import { getJson, settings } from './api';

const MIN_LENGTH = 2;
const DEBOUNCE_MS = 200;
const MAX_REMEMBERED = 50;

/**
 * Adds suggestions to a search field.
 *
 * @param {HTMLElement} field Wrapper containing the input and the empty listbox.
 */
export function enhanceCombobox( field ) {
	const input = field.querySelector( 'input' );
	const listbox = field.querySelector( '[role="listbox"]' );
	const status = field
		.closest( 'form' )
		?.querySelector( '[data-prstsearch-suggestion-status]' );

	if ( ! input || ! listbox ) {
		return;
	}

	/**
	 * Suggestions already received, by what was typed. Deleting a letter
	 * and typing it again shows the list at once, without asking the server.
	 *
	 * @type {Map<string, Array<{title: string, url: string}>>}
	 */
	const known = new Map();

	let suggestions = [];
	let activeIndex = -1;
	let timer;
	let request;

	input.setAttribute( 'role', 'combobox' );
	input.setAttribute( 'aria-autocomplete', 'list' );
	input.setAttribute( 'aria-haspopup', 'listbox' );
	input.setAttribute( 'aria-controls', listbox.id );
	input.setAttribute( 'aria-expanded', 'false' );

	const isOpen = () => ! listbox.hidden;

	const announce = ( text ) => {
		if ( status ) {
			status.textContent = text;
		}
	};

	// Stops whatever could still open the list: the waiting timer and the request in flight.
	const cancel = () => {
		window.clearTimeout( timer );
		request?.abort();
		request = null;
	};

	const close = () => {
		cancel();
		announce( '' );
		listbox.hidden = true;
		listbox.replaceChildren();
		input.setAttribute( 'aria-expanded', 'false' );
		input.removeAttribute( 'aria-activedescendant' );
		suggestions = [];
		activeIndex = -1;
	};

	const setActive = ( index ) => {
		activeIndex = index;

		[ ...listbox.children ].forEach( ( option, position ) => {
			option.setAttribute(
				'aria-selected',
				String( position === index )
			);
		} );

		const active = listbox.children[ index ];

		if ( active ) {
			input.setAttribute( 'aria-activedescendant', active.id );
			active.scrollIntoView( { block: 'nearest' } );
		} else {
			input.removeAttribute( 'aria-activedescendant' );
		}
	};

	const open = ( items ) => {
		suggestions = items;

		listbox.replaceChildren(
			...items.map( ( item, position ) => {
				const option = document.createElement( 'li' );

				option.id = `${ listbox.id }-${ position }`;
				option.className = 'prstsearch-form__suggestion';
				option.setAttribute( 'role', 'option' );
				option.setAttribute( 'aria-selected', 'false' );
				option.textContent = item.title;

				return option;
			} )
		);

		listbox.hidden = false;
		input.setAttribute( 'aria-expanded', 'true' );
		setActive( -1 );
		announce( countText( items.length ) );
	};

	const load = async () => {
		const query = input.value.trim();

		cancel();

		if ( query.length < MIN_LENGTH ) {
			close();
			return;
		}

		const current = new AbortController();

		request = current;

		try {
			const key = query.toLowerCase();
			const items =
				known.get( key ) ??
				( await getJson(
					'/suggestions',
					{ q: query },
					current.signal
				) );

			// Cancelled while the response was read: the field was left or its text changed.
			if ( current.signal.aborted || ! Array.isArray( items ) ) {
				return;
			}

			remember( known, key, items );

			if ( items.length > 0 ) {
				open( items );
			} else {
				close();
			}
		} catch {
			// Suggestions are a convenience; the form still works without them.
			if ( ! current.signal.aborted ) {
				close();
			}
		}
	};

	input.addEventListener( 'input', () => {
		// Also the request already on its way: its answer would be suggestions for text that is no longer there.
		cancel();
		timer = window.setTimeout( load, DEBOUNCE_MS );
	} );

	// Searching is the end of choosing. Without this the list would open over the results a moment later.
	input.form?.addEventListener( 'submit', close );

	input.addEventListener( 'keydown', ( event ) => {
		if ( ! isOpen() ) {
			// Brings back a list that was closed with Escape.
			if ( event.key === 'ArrowDown' ) {
				event.preventDefault();
				load();
			}

			return;
		}

		const last = suggestions.length - 1;

		switch ( event.key ) {
			case 'ArrowDown':
				event.preventDefault();
				setActive( activeIndex >= last ? 0 : activeIndex + 1 );
				break;
			case 'ArrowUp':
				event.preventDefault();
				setActive( activeIndex <= 0 ? last : activeIndex - 1 );
				break;
			case 'Enter':
				// Without a highlighted option, Enter submits the search as usual.
				if ( activeIndex >= 0 ) {
					event.preventDefault();
					window.location.assign( suggestions[ activeIndex ].url );
				}
				break;
			case 'Escape':
				event.preventDefault();
				close();
				break;
		}
	} );

	// Keeps focus in the input when an option is clicked.
	listbox.addEventListener( 'mousedown', ( event ) =>
		event.preventDefault()
	);

	listbox.addEventListener( 'click', ( event ) => {
		const option =
			event.target instanceof Element
				? event.target.closest( '[role="option"]' )
				: null;
		const index = option ? [ ...listbox.children ].indexOf( option ) : -1;

		if ( index >= 0 ) {
			window.location.assign( suggestions[ index ].url );
		}
	} );

	input.addEventListener( 'blur', close );
}

/**
 * Keeps suggestions for a text, and lets go of the oldest ones.
 *
 * @param {Map<string, Array>} known Suggestions by text.
 * @param {string}             key   The text.
 * @param {Array}              items Its suggestions.
 */
function remember( known, key, items ) {
	known.set( key, items );

	// A Map keeps the order of insertion, so the first key is the oldest.
	if ( known.size > MAX_REMEMBERED ) {
		known.delete( known.keys().next().value );
	}
}

/**
 * Returns the sentence that tells screen reader users how many suggestions there are.
 *
 * @param {number} count Number of suggestions.
 * @return {string} The sentence, empty if the texts are missing.
 */
function countText( count ) {
	const { suggestion, suggestions } = settings().strings;

	if ( count === 1 ) {
		return suggestion ?? '';
	}

	return suggestions?.replace( '%d', String( count ) ) ?? '';
}
