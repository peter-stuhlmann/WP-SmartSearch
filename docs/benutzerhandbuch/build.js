/**
 * Baut das Benutzerhandbuch: nummeriert die Funktionen, erzeugt die Übersicht
 * und die Kapitelseiten und druckt alles als PDF.
 *
 * Aufruf, aus dem Plugin-Ordner:  node docs/benutzerhandbuch/build.js
 *
 * Der Inhalt steht in inhalt.html. Jede Gruppe ist ein
 *   <section class="gruppe" data-name="…" data-farbe="blau" data-text="…">
 * und darin jede Funktion ein
 *   <article class="funktion" data-titel="…" data-kurz="…">.
 * Gedruckt wird mit Chrome oder Edge im Hintergrund; der Pfad lässt sich über
 * die Umgebungsvariable BROWSER vorgeben.
 */

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { pathToFileURL } = require( 'url' );

const here = __dirname;
const htmlFile = path.join( here, 'benutzerhandbuch.html' );
const pdfFile = path.join( here, '..', 'Smart-Search-Benutzerhandbuch.pdf' );
const version = fs.readFileSync( path.join( here, '..', '..', 'smart-search.php' ), 'utf8' ).match( /Version:\s*([\d.]+)/ )[ 1 ];
const source = fs.readFileSync( path.join( here, 'inhalt.html' ), 'utf8' ).replace( /\r\n/g, '\n' );

const groups = [];
let number = 0;
let groupNumber = 0;

// Jede Gruppe bekommt ihre Farbe und eine Startseite, jede Funktion ihre Nummer.
const body = source.replace( /<section class="gruppe" data-name="([^"]+)" data-farbe="([^"]+)" data-text="([^"]+)">([\s\S]*?)<\/section>/g, ( match, name, colour, text, inner ) => {
	groupNumber++;

	const group = { name, colour, features: [] };

	groups.push( group );

	const features = inner.replace( /<article class="funktion" data-titel="([^"]+)" data-kurz="([^"]+)">\n?([\s\S]*?)<\/article>/g, ( m, title, short, content ) => {
		number++;
		group.features.push( { number, title, short } );

		return `<article class="funktion" id="f${ number }">
	<div class="funktion__kopf"><span class="funktion__zahl">${ number }</span><h3>${ title }</h3></div>
	<p class="kurz">${ short }</p>
	${ content }</article>`;
	} );

	return `<section class="gruppe" style="--farbe: var(--${ colour }); --hell: var(--${ colour }-hell);">
	<div class="gruppe__start">
		<span class="gruppe__nummer">Kapitel ${ groupNumber }</span>
		<h2>${ name }</h2>
		<p>${ text }</p>
	</div>
	${ features }
</section>`;
} );

const overview = groups
	.map(
		( group ) => `<div class="uebersicht__gruppe" style="--farbe: var(--${ group.colour }); --hell: var(--${ group.colour }-hell);">
	<div class="uebersicht__kopf"><span class="uebersicht__punkt"></span>${ group.name }</div>
	<ul class="uebersicht__liste">
		${ group.features.map( ( f ) => `<li><b>${ f.number }</b><a href="#f${ f.number }"><strong>${ f.title }</strong><span>${ f.short }</span></a></li>` ).join( '\n\t\t' ) }
	</ul>
</div>`
	)
	.join( '\n' );

const month = new Date().toLocaleDateString( 'de-DE', { year: 'numeric', month: 'long' } );

fs.writeFileSync(
	htmlFile,
	body
		.replace( '<!--UEBERSICHT-->', overview )
		.replace( '<!--ANZAHL-->', String( number ) )
		.replace( '<!--VERSION-->', version )
		.replace( '<!--STAND-->', month )
);

const browser = [
	process.env.BROWSER,
	'C:/Program Files/Google/Chrome/Application/chrome.exe',
	'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
	'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
	'/usr/bin/google-chrome',
	'/usr/bin/chromium',
]
	.filter( Boolean )
	.find( ( candidate ) => fs.existsSync( candidate ) );

if ( ! browser ) {
	console.log( 'HTML geschrieben: ' + htmlFile + '. Kein Chrome oder Edge gefunden; öffne die Datei im Browser und drucke sie als PDF.' );
	process.exit( 0 );
}

execFileSync(
	browser,
	[ '--headless=new', '--disable-gpu', '--no-pdf-header-footer', '--user-data-dir=' + path.join( os.tmpdir(), 'smart-search-benutzerhandbuch' ), '--print-to-pdf=' + pdfFile, pathToFileURL( htmlFile ).href ],
	{ stdio: 'ignore' }
);

console.log( `${ number } Funktionen in ${ groups.length } Kapiteln → ${ path.relative( process.cwd(), pdfFile ) } (${ Math.round( fs.statSync( pdfFile ).size / 1024 ) } KB)` );
