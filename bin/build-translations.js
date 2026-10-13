/**
 * Builds the JavaScript translation files WordPress loads by script handle.
 *
 * `wp i18n make-json` writes one file per source module and removes the
 * JavaScript strings from the .po file it reads. The plugin ships bundles and
 * wants to keep its .po files complete, so this script lets make-json work on
 * a temporary copy and merges the result into one file per bundle, named
 * after the script handle: smart-search-{locale}-{handle}.json.
 *
 * Run it through `composer i18n:build`.
 */
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const LANGUAGES = path.join( ROOT, 'languages' );
const WP_CLI = path.join( ROOT, 'vendor/wp-cli/wp-cli/php/boot-fs.php' );

/** Source folders and the handle of the bundle they end up in. */
const BUNDLES = {
	'assets/src/admin/': 'prstsearch-admin',
	'assets/src/editor/': 'prstsearch-editor',
	'assets/src/blocks/search/': 'prstsearch-search-editor-script',
};

const workDir = fs.mkdtempSync( path.join( os.tmpdir(), 'prstsearch-i18n-' ) );

for ( const file of fs.readdirSync( LANGUAGES ) ) {
	if ( file.endsWith( '.po' ) ) {
		fs.copyFileSync( path.join( LANGUAGES, file ), path.join( workDir, file ) );
	}
}

execFileSync( 'php', [ WP_CLI, 'i18n', 'make-json', workDir ], { stdio: 'ignore' } );

const merged = {};

for ( const file of fs.readdirSync( workDir ) ) {
	const match = file.match( /^smart-search-([a-zA-Z_]+)-[0-9a-f]{32}\.json$/ );

	if ( ! match ) {
		continue;
	}

	const data = JSON.parse( fs.readFileSync( path.join( workDir, file ), 'utf8' ) );
	const folder = Object.keys( BUNDLES ).find( ( prefix ) => data.source.startsWith( prefix ) );

	if ( ! folder ) {
		continue;
	}

	const key = `${ match[ 1 ] }-${ BUNDLES[ folder ] }`;

	merged[ key ] ??= { ...data, source: folder };
	Object.assign( merged[ key ].locale_data.messages, data.locale_data.messages );
}

for ( const [ key, data ] of Object.entries( merged ) ) {
	fs.writeFileSync( path.join( LANGUAGES, `smart-search-${ key }.json` ), JSON.stringify( data ) );
}

fs.rmSync( workDir, { recursive: true, force: true } );

console.log( `Wrote ${ Object.keys( merged ).length } translation bundle(s).` ); // eslint-disable-line no-console
