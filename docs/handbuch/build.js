/**
 * Baut das Handbuch: fügt die Kapitel zu einer HTML-Datei zusammen und druckt sie als PDF.
 *
 * Aufruf, aus dem Plugin-Ordner:  node docs/handbuch/build.js
 *
 * Die Kapitel liegen als HTML-Schnipsel in docs/handbuch/kapitel/ und werden
 * in der Reihenfolge ihrer Dateinamen eingelesen. Jede Datei beginnt mit einem
 * Kommentar, der sagt, was sie ist:
 *
 *   <!-- teil: A | Für alle | Kurzer Text zur Trennseite -->
 *   <!-- kapitel: Der Titel des Kapitels -->
 *
 * Kapitelnummern, Sprungmarken und das Inhaltsverzeichnis entstehen hier.
 * Gedruckt wird mit Chrome oder Edge im Hintergrund; der Pfad lässt sich über
 * die Umgebungsvariable BROWSER vorgeben.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { pathToFileURL } = require( 'url' );

const here = __dirname;
const chapterDir = path.join( here, 'kapitel' );
const htmlFile = path.join( here, 'handbuch.html' );
const pdfFile = path.join( here, '..', 'Smart-Search-Handbuch.pdf' );
const version = fs.readFileSync( path.join( here, '..', '..', 'smart-search.php' ), 'utf8' ).match( /Version:\s*([\d.]+)/ )[ 1 ];

const escapeHtml = ( text ) => text.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
const slug = ( text ) =>
	text
		.toLowerCase()
		.replace( /ä/g, 'ae' )
		.replace( /ö/g, 'oe' )
		.replace( /ü/g, 'ue' )
		.replace( /ß/g, 'ss' )
		.replace( /<[^>]+>/g, '' )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-|-$/g, '' );

let chapterNumber = 0;
let body = '';
const contents = [];

for ( const name of fs.readdirSync( chapterDir ).filter( ( file ) => file.endsWith( '.html' ) ).sort() ) {
	let source = fs.readFileSync( path.join( chapterDir, name ), 'utf8' ).replace( /\r\n/g, '\n' );
	const part = source.match( /^<!--\s*teil:\s*(.+?)\s*\|\s*(.+?)\s*\|\s*([\s\S]+?)\s*-->/ );
	const chapter = source.match( /^<!--\s*kapitel:\s*(.+?)\s*-->/ );

	if ( part ) {
		contents.push( { type: 'part', letter: part[ 1 ], title: part[ 2 ] } );
		body += `<section class="teil" id="teil-${ part[ 1 ].toLowerCase() }">
	<div class="teil__kennung">Teil ${ part[ 1 ] }</div>
	<div class="teil__name">${ part[ 2 ] }</div>
	<p class="teil__text">${ part[ 3 ] }</p>
	<!--LISTE:${ part[ 1 ] }-->
</section>\n`;
		continue;
	}

	if ( ! chapter ) {
		throw new Error( name + ' beginnt weder mit "teil:" noch mit "kapitel:".' );
	}

	chapterNumber++;

	const id = 'k' + chapterNumber + '-' + slug( chapter[ 1 ] );
	const entry = { type: 'chapter', number: chapterNumber, title: chapter[ 1 ], id, sections: [] };
	let sectionNumber = 0;

	// Abschnitte bekommen eine Sprungmarke und erscheinen im Inhaltsverzeichnis.
	source = source.replace( /<h2>([\s\S]*?)<\/h2>/g, ( match, title ) => {
		sectionNumber++;

		const sectionId = id + '-' + sectionNumber;

		entry.sections.push( { title: title.replace( /<[^>]+>/g, '' ), id: sectionId } );

		return `<h2 id="${ sectionId }">${ title }</h2>`;
	} );

	contents.push( entry );
	body += `<section class="kapitel" id="${ id }">
<h1><span class="nummer">Kapitel ${ chapterNumber }</span>${ chapter[ 1 ] }</h1>
${ source.replace( /^<!--[\s\S]*?-->\n?/, '' ) }
</section>\n`;
}

// Jede Trennseite listet die Kapitel ihres Teils.
let currentPart = null;
const byPart = {};

for ( const entry of contents ) {
	if ( entry.type === 'part' ) {
		currentPart = entry.letter;
		byPart[ currentPart ] = [];
	} else if ( currentPart ) {
		byPart[ currentPart ].push( entry );
	}
}

body = body.replace( /<!--LISTE:(\w+)-->/g, ( match, letter ) =>
	'<ul class="teil__liste">' + byPart[ letter ].map( ( entry ) => `<li><a href="#${ entry.id }">${ entry.number }&ensp;${ entry.title }</a></li>` ).join( '' ) + '</ul>'
);

const toc = contents
	.map( ( entry ) =>
		entry.type === 'part'
			? `<li class="inhalt__teil">Teil ${ entry.letter } · ${ entry.title }</li>`
			: `<li class="inhalt__kapitel"><span class="inhalt__zahl">${ entry.number }</span><a href="#${ entry.id }">${ entry.title }</a></li>` +
			  entry.sections.map( ( section ) => `<li class="inhalt__unter"><a href="#${ section.id }">${ escapeHtml( section.title ) }</a></li>` ).join( '' )
	)
	.join( '\n' );

const today = new Date().toLocaleDateString( 'de-DE', { year: 'numeric', month: 'long' } );

const html = `<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Smart Search – Handbuch</title>
<meta name="author" content="Peter R. Stuhlmann">
<link rel="stylesheet" href="style.css">
</head>
<body>

<section class="titel">
	<div>
		<span class="titel__marke"><span class="titel__punkt"></span>WordPress-Plugin · Version ${ version }</span>
	</div>
	<div>
		<h1>Smart Search</h1>
		<p class="titel__unter">Das Handbuch. Für alle, die das Plugin einrichten und nutzen – und für alle, die damit entwickeln.</p>
		<div class="titel__kacheln">
			<div class="titel__kachel"><strong>Keyword</strong><span>Schnelle Suche im eigenen Index. Versteht Wortformen, Zusammensetzungen und Tippfehler.</span></div>
			<div class="titel__kachel"><strong>Semantisch</strong><span>Findet Inhalte nach ihrer Bedeutung, auch wenn das Suchwort gar nicht vorkommt.</span></div>
			<div class="titel__kachel"><strong>KI-Antwort</strong><span>Schreibt aus den besten Treffern eine kurze Antwort und nennt die Quellen.</span></div>
		</div>
	</div>
	<div class="titel__fuss">
		<span>Peter R. Stuhlmann</span>
		<span>Stand: ${ today }</span>
	</div>
</section>

<section class="inhalt">
<h1>Inhalt</h1>
<ol>
${ toc }
</ol>
</section>

${ body }
</body>
</html>
`;

fs.writeFileSync( htmlFile, html );

const candidates = [
	process.env.BROWSER,
	'C:/Program Files/Google/Chrome/Application/chrome.exe',
	'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
	'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
	'/usr/bin/google-chrome',
	'/usr/bin/chromium',
].filter( Boolean );
const browser = candidates.find( ( candidate ) => fs.existsSync( candidate ) );

if ( ! browser ) {
	console.log( 'HTML geschrieben: ' + htmlFile );
	console.log( 'Kein Chrome oder Edge gefunden. Öffne die Datei im Browser und drucke sie als PDF, oder setze BROWSER.' );
	process.exit( 0 );
}

execFileSync(
	browser,
	[
		'--headless=new',
		'--disable-gpu',
		'--no-pdf-header-footer',
		'--user-data-dir=' + path.join( require( 'os' ).tmpdir(), 'smart-search-handbuch' ),
		'--print-to-pdf=' + pdfFile,
		pathToFileURL( htmlFile ).href,
	],
	{ stdio: 'ignore' }
);

console.log( `${ chapterNumber } Kapitel → ${ path.relative( process.cwd(), pdfFile ) } (${ Math.round( fs.statSync( pdfFile ).size / 1024 ) } KB)` );
