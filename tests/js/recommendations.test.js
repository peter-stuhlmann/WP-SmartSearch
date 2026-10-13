import { request } from '../../assets/src/admin/api';
import { initRecommendations } from '../../assets/src/admin/recommendations';

// The real module needs WordPress to send requests. What is asked for is all that matters here.
jest.mock( '../../assets/src/admin/api', () => ( { request: jest.fn() } ) );

/**
 * Builds the markup of one rule the way the server prints it.
 *
 * @param {string} index Index of the row.
 * @param {string} terms Search terms of the rule.
 * @return {string} The markup.
 */
function row( index, terms = '' ) {
	return `
		<li data-prstsearch-rule>
			<input name="prstsearch_recommendations[${ index }][terms]" value="${ terms }" />
			<input name="prstsearch_recommendations[${ index }][page]" list="prstsearch-rule-pages" data-prstsearch-rule-page />
			<button type="button" data-prstsearch-rule-remove>Remove</button>
		</li>`;
}

/**
 * Renders the list with two rules and enhances it.
 *
 * @return {{list: HTMLElement, add: HTMLElement, pages: HTMLElement}} The parts of the list.
 */
function renderList() {
	document.body.innerHTML = `
		<ul data-prstsearch-rules>${ row( '0', 'opening hours' ) }${ row( 'new' ) }</ul>
		<template data-prstsearch-rule-template>${ row( '__INDEX__' ) }</template>
		<datalist id="prstsearch-rule-pages"></datalist>
		<button type="button" data-prstsearch-rule-add hidden>Add</button>`;

	initRecommendations();

	return {
		list: document.querySelector( '[data-prstsearch-rules]' ),
		add: document.querySelector( '[data-prstsearch-rule-add]' ),
		pages: document.getElementById( 'prstsearch-rule-pages' ),
	};
}

/**
 * Types into a page field and waits for the list of pages.
 *
 * @param {HTMLInputElement} field The field.
 * @param {string}           value Text to type.
 */
async function type( field, value ) {
	field.value = value;
	field.dispatchEvent( new Event( 'input', { bubbles: true } ) );

	await jest.runAllTimersAsync();
}

describe( 'initRecommendations', () => {
	beforeEach( () => {
		jest.useFakeTimers();

		request.mockReset();
		request.mockResolvedValue( [
			{ id: 12, title: 'Contact', type: 'Page' },
			{ id: 34, title: 'Contact form', type: 'Page' },
		] );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'shows the button that only works with the script', () => {
		expect( renderList().add.hidden ).toBe( false );
	} );

	it( 'adds an empty row with names of its own and puts the cursor in it', () => {
		const { list, add } = renderList();

		add.click();
		add.click();

		const names = [ ...list.querySelectorAll( 'input' ) ].map(
			( input ) => input.name
		);

		expect( list.children ).toHaveLength( 4 );
		expect( new Set( names ).size ).toBe( names.length );
		expect( names.join() ).not.toContain( '__INDEX__' );
		expect( document.activeElement ).toBe(
			list.lastElementChild.querySelector( 'input' )
		);
	} );

	it( 'removes a row and keeps the focus in the list', () => {
		const { list } = renderList();

		list.querySelector( '[data-prstsearch-rule-remove]' ).click();

		expect( list.children ).toHaveLength( 1 );
		expect( list.querySelector( 'input' ).value ).toBe( '' );
		expect( document.activeElement ).toBe( list.querySelector( 'input' ) );
	} );

	it( 'never leaves the list without a row', () => {
		const { list } = renderList();

		list.querySelectorAll( '[data-prstsearch-rule-remove]' )[ 1 ].click();
		list.querySelector( '[data-prstsearch-rule-remove]' ).click();

		expect( list.children ).toHaveLength( 1 );
		expect( list.querySelector( 'input' ).value ).toBe( '' );
	} );

	it( 'offers the pages whose title matches, each with its ID', async () => {
		const { list, pages } = renderList();

		await type(
			list.querySelector( '[data-prstsearch-rule-page]' ),
			'cont'
		);

		expect( request ).toHaveBeenCalledWith( '/index/pages?q=cont' );
		expect(
			[ ...pages.querySelectorAll( 'option' ) ].map(
				( option ) => option.value
			)
		).toEqual( [ 'Contact #12', 'Contact form #34' ] );
	} );

	it( 'does not look up a page that was already chosen, or a single letter', async () => {
		const { list } = renderList();
		const field = list.querySelector( '[data-prstsearch-rule-page]' );

		await type( field, 'c' );
		await type( field, 'Contact #12' );

		expect( request ).not.toHaveBeenCalled();
	} );

	it( 'still accepts a page ID when the pages cannot be loaded', async () => {
		request.mockRejectedValue( new Error( 'offline' ) );

		const { list, pages } = renderList();

		await type(
			list.querySelector( '[data-prstsearch-rule-page]' ),
			'cont'
		);

		expect( pages.children ).toHaveLength( 0 );
	} );
} );
