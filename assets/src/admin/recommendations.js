/**
 * The list of recommended results on the settings page.
 *
 * Progressive enhancement: the server prints every rule and one empty row,
 * and a page can always be named by its ID. This script adds further rows,
 * removes rows, and offers the pages of the website while a title is typed.
 */
import { request } from './api';

const DEBOUNCE_MS = 200;

/**
 * Number of the most recent request for pages.
 */
let lastAsked = 0;
const MIN_LENGTH = 2;

/**
 * Wires up the list, if it is on the page.
 */
export function initRecommendations() {
	const list = document.querySelector( '[data-prstsearch-rules]' );
	const template = document.querySelector(
		'[data-prstsearch-rule-template]'
	);
	const pages = document.getElementById( 'prstsearch-rule-pages' );

	if ( ! list || ! template || ! pages ) {
		return;
	}

	let added = 0;
	let timer;

	const addRow = () => {
		// The server reads the rows as a list, so the index only has to be unique.
		const html = template.innerHTML.replaceAll(
			'__INDEX__',
			`new-${ ++added }`
		);

		list.insertAdjacentHTML( 'beforeend', html );

		return list.lastElementChild;
	};

	const addButton = document.querySelector( '[data-prstsearch-rule-add]' );

	if ( addButton ) {
		// Hidden by the server: without this script the button could do nothing.
		addButton.hidden = false;
		addButton.addEventListener( 'click', () =>
			addRow().querySelector( 'input' ).focus()
		);
	}

	list.addEventListener( 'click', ( event ) => {
		const button =
			event.target instanceof Element
				? event.target.closest( '[data-prstsearch-rule-remove]' )
				: null;

		if ( ! button ) {
			return;
		}

		const row = button.closest( '[data-prstsearch-rule]' );
		// Focus must not be lost with the row: it moves to a neighbour, or to a fresh empty row.
		const neighbour =
			row.nextElementSibling ?? row.previousElementSibling ?? addRow();

		row.remove();
		neighbour.querySelector( 'input' ).focus();
	} );

	list.addEventListener( 'input', ( event ) => {
		const field =
			event.target instanceof Element
				? event.target.closest( '[data-prstsearch-rule-page]' )
				: null;

		if ( ! field ) {
			return;
		}

		window.clearTimeout( timer );
		timer = window.setTimeout(
			() => offerPages( field.value.trim(), pages ),
			DEBOUNCE_MS
		);
	} );
}

/**
 * Fills the list of pages the browser offers for the field.
 *
 * @param {string}      text  What was typed.
 * @param {HTMLElement} pages The datalist element.
 */
async function offerPages( text, pages ) {
	// A chosen entry ends with the ID of the page: nothing left to look up.
	if ( text.length < MIN_LENGTH || /#\d+$/.test( text ) ) {
		return;
	}

	// Answers can arrive in another order than the questions. Only the answer to the last question counts.
	const asked = ++lastAsked;

	try {
		const found = await request(
			`/index/pages?q=${ encodeURIComponent( text ) }`
		);

		if ( asked !== lastAsked || ! Array.isArray( found ) ) {
			return;
		}

		pages.replaceChildren(
			...found.map( ( page ) => {
				const option = document.createElement( 'option' );

				// The value alone: some browsers show a label instead of it, and the label would hide the title.
				option.value = `${ page.title } #${ page.id }`;

				return option;
			} )
		);
	} catch {
		// Without the list the page can still be entered by its ID.
	}
}
