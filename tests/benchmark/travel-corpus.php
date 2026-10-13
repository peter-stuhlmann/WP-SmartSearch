<?php
/**
 * Fills a WordPress installation with travel offers, as a corpus to measure the search with.
 *
 * Every post advertises one trip. Some are round trips through several
 * countries, and every country has many posts. The texts are put together
 * from fixed parts with a seeded random generator, so the same number always
 * yields the same post, and a run can be repeated or continued.
 *
 * Usage: WP_LOAD_PATH=/path/to/wp-load.php php tests/benchmark/travel-corpus.php [count] [first number]
 *        WP_LOAD_PATH=/path/to/wp-load.php php tests/benchmark/travel-corpus.php delete
 *
 * @package PrstSearch
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

require (string) getenv( 'WP_LOAD_PATH' );

const PRSTSEARCH_CORPUS_META = '_prstsearch_corpus';

/**
 * Countries by region: cities, sights, and what kind of trips suit them.
 *
 * @return array<string, array<string, array{cities: string[], sights: string[], traits: string[]}>>
 */
function prstsearch_corpus_countries(): array {
	$country = static fn ( string $cities, string $sights, string $traits = '' ): array => array(
		'cities' => explode( ', ', $cities ),
		'sights' => explode( ', ', $sights ),
		'traits' => '' === $traits ? array() : explode( ', ', $traits ),
	);

	return array(
		'Südeuropa'      => array(
			'Portugal'     => $country( 'Lissabon, Porto, Faro, Coimbra', 'Algarve, Douro-Tal, Sintra, Torre de Belém', 'Strand, Wein' ),
			'Spanien'      => $country( 'Madrid, Barcelona, Sevilla, Valencia, Granada', 'Alhambra, Sagrada Família, Costa Brava, Jakobsweg', 'Strand, Wein' ),
			'Frankreich'   => $country( 'Paris, Lyon, Nizza, Bordeaux, Straßburg', 'Provence, Loire-Schlösser, Mont-Saint-Michel, Côte d’Azur', 'Strand, Wein' ),
			'Italien'      => $country( 'Rom, Florenz, Venedig, Neapel, Bologna', 'Toskana, Amalfiküste, Dolomiten, Kolosseum', 'Strand, Wein, Berge' ),
			'Griechenland' => $country( 'Athen, Thessaloniki, Heraklion, Nafplio', 'Akropolis, Santorin, Meteora-Klöster, Olympia', 'Strand' ),
			'Malta'        => $country( 'Valletta, Mdina, Sliema', 'Blaue Grotte, Gozo, Hypogäum', 'Strand' ),
		),
		'Alpen und Adria' => array(
			'Österreich' => $country( 'Wien, Salzburg, Innsbruck, Graz', 'Wachau, Großglockner, Schloss Schönbrunn, Salzkammergut', 'Berge, Wein' ),
			'Schweiz'    => $country( 'Zürich, Luzern, Bern, Genf', 'Matterhorn, Jungfraujoch, Rheinfall, Glacier Express', 'Berge' ),
			'Slowenien'  => $country( 'Ljubljana, Bled, Piran, Maribor', 'Triglav-Nationalpark, Höhlen von Postojna, Bleder See', 'Berge' ),
			'Kroatien'   => $country( 'Zagreb, Split, Dubrovnik, Zadar', 'Plitvicer Seen, Istrien, Insel Hvar, Krka-Wasserfälle', 'Strand' ),
			'Deutschland' => $country( 'Berlin, Hamburg, München, Dresden, Köln', 'Schwarzwald, Ostsee, Rheintal, Schloss Neuschwanstein', 'Berge, Wein' ),
		),
		'Nordeuropa'     => array(
			'Norwegen' => $country( 'Oslo, Bergen, Tromsø, Ålesund', 'Geirangerfjord, Lofoten, Nordkap, Hurtigruten', 'Berge' ),
			'Schweden' => $country( 'Stockholm, Göteborg, Malmö, Kiruna', 'Schärengarten, Lappland, Göta-Kanal' ),
			'Finnland' => $country( 'Helsinki, Rovaniemi, Turku, Tampere', 'Seenplatte, Nordlichter, Weihnachtsmanndorf' ),
			'Dänemark' => $country( 'Kopenhagen, Aarhus, Odense, Skagen', 'Tivoli, Kreidefelsen von Møn, Nordseeküste', 'Strand' ),
			'Island'   => $country( 'Reykjavík, Akureyri, Vík, Húsavík', 'Gullfoss, Blaue Lagune, Geysir, Gletscherlagune Jökulsárlón', 'Berge' ),
		),
		'Britische Inseln' => array(
			'Irland'     => $country( 'Dublin, Galway, Cork, Killarney', 'Cliffs of Moher, Ring of Kerry, Connemara' ),
			'Schottland' => $country( 'Edinburgh, Glasgow, Inverness, Oban', 'Loch Ness, Isle of Skye, Highlands, Whisky-Destillerien', 'Berge' ),
			'England'    => $country( 'London, Bath, York, Oxford', 'Stonehenge, Cornwall, Lake District' ),
		),
		'Osteuropa'      => array(
			'Polen'      => $country( 'Warschau, Krakau, Danzig, Breslau', 'Masuren, Hohe Tatra, Marienburg', 'Berge' ),
			'Tschechien' => $country( 'Prag, Brünn, Karlsbad, Český Krumlov', 'Böhmische Schweiz, Karlsbrücke, Burg Karlštejn' ),
			'Ungarn'     => $country( 'Budapest, Pécs, Eger, Debrecen', 'Plattensee, Puszta, Donauknie', 'Wein' ),
			'Rumänien'   => $country( 'Bukarest, Brașov, Sibiu, Cluj', 'Karpaten, Donaudelta, Schloss Bran', 'Berge' ),
			'Estland'    => $country( 'Tallinn, Tartu, Pärnu', 'Lahemaa-Nationalpark, Insel Saaremaa' ),
			'Lettland'   => $country( 'Riga, Jūrmala, Sigulda', 'Gauja-Nationalpark, Schloss Rundāle', 'Strand' ),
			'Litauen'    => $country( 'Vilnius, Kaunas, Klaipėda', 'Kurische Nehrung, Wasserburg Trakai', 'Strand' ),
		),
		'Orient'         => array(
			'Türkei'    => $country( 'Istanbul, Antalya, Izmir, Ankara', 'Kappadokien, Pamukkale, Ephesos, Hagia Sophia', 'Strand' ),
			'Marokko'   => $country( 'Marrakesch, Fès, Casablanca, Essaouira', 'Atlasgebirge, Sahara, Djemaa el Fna', 'Berge, Strand' ),
			'Ägypten'   => $country( 'Kairo, Luxor, Assuan, Hurghada', 'Pyramiden von Gizeh, Tal der Könige, Rotes Meer, Abu Simbel', 'Strand' ),
			'Jordanien' => $country( 'Amman, Aqaba, Madaba', 'Petra, Wadi Rum, Totes Meer, Jerash' ),
			'Oman'      => $country( 'Maskat, Nizwa, Salala, Sur', 'Wahiba-Wüste, Wadi Shab, Hadschar-Gebirge', 'Strand, Berge' ),
		),
		'Südliches Afrika' => array(
			'Südafrika' => $country( 'Kapstadt, Johannesburg, Durban, Stellenbosch', 'Krüger-Nationalpark, Garden Route, Tafelberg, Kap der Guten Hoffnung', 'Safari, Wein, Strand' ),
			'Namibia'   => $country( 'Windhoek, Swakopmund, Lüderitz', 'Etosha-Nationalpark, Sossusvlei, Fish River Canyon', 'Safari' ),
			'Botswana'  => $country( 'Maun, Kasane, Gaborone', 'Okavango-Delta, Chobe-Nationalpark, Kalahari', 'Safari' ),
		),
		'Ostafrika'      => array(
			'Tansania' => $country( 'Arusha, Daressalam, Stone Town', 'Serengeti, Kilimandscharo, Ngorongoro-Krater, Sansibar', 'Safari, Strand, Berge' ),
			'Kenia'    => $country( 'Nairobi, Mombasa, Nakuru', 'Masai Mara, Amboseli-Nationalpark, Diani Beach', 'Safari, Strand' ),
			'Uganda'   => $country( 'Kampala, Entebbe, Fort Portal', 'Bwindi-Regenwald, Murchison Falls, Viktoriasee', 'Safari' ),
		),
		'Südostasien'    => array(
			'Thailand'   => $country( 'Bangkok, Chiang Mai, Phuket, Sukhothai', 'Ayutthaya, Krabi, Goldenes Dreieck, Koh Samui', 'Strand' ),
			'Vietnam'    => $country( 'Hanoi, Hoi An, Hue, Ho-Chi-Minh-Stadt', 'Halong-Bucht, Mekong-Delta, Reisterrassen von Sapa', 'Strand' ),
			'Kambodscha' => $country( 'Phnom Penh, Siem Reap, Battambang', 'Angkor Wat, Tonle-Sap-See, Bayon-Tempel' ),
			'Laos'       => $country( 'Luang Prabang, Vientiane, Pakse', 'Kuang-Si-Wasserfälle, Ebene der Tonkrüge, Viertausend Inseln' ),
			'Indonesien' => $country( 'Jakarta, Ubud, Yogyakarta, Lombok', 'Bali, Borobudur, Komodo-Nationalpark, Vulkan Bromo', 'Strand, Berge' ),
		),
		'Ostasien'       => array(
			'Japan'    => $country( 'Tokio, Kyoto, Osaka, Hiroshima, Nara', 'Fuji, Fushimi-Inari-Schrein, Kirschblüte, Japanische Alpen', 'Berge' ),
			'Südkorea' => $country( 'Seoul, Busan, Gyeongju, Jeonju', 'Insel Jeju, Palast Gyeongbokgung, Seoraksan-Nationalpark', 'Berge, Strand' ),
			'China'    => $country( 'Peking, Shanghai, Xi’an, Guilin', 'Große Mauer, Terrakotta-Armee, Verbotene Stadt, Li-Fluss', 'Berge' ),
		),
		'Südasien'       => array(
			'Indien'    => $country( 'Delhi, Jaipur, Agra, Varanasi, Mumbai', 'Taj Mahal, Kerala, Rajasthan, Ganges' ),
			'Sri Lanka' => $country( 'Colombo, Kandy, Galle, Ella', 'Sigiriya, Teeplantagen, Yala-Nationalpark, Zahntempel', 'Strand, Safari' ),
			'Nepal'     => $country( 'Kathmandu, Pokhara, Bhaktapur', 'Annapurna, Himalaya, Chitwan-Nationalpark, Everest-Basislager', 'Berge' ),
		),
		'Nordamerika'    => array(
			'USA'    => $country( 'New York, San Francisco, Las Vegas, Chicago, New Orleans', 'Grand Canyon, Yellowstone, Route 66, Monument Valley', 'Strand, Berge' ),
			'Kanada' => $country( 'Vancouver, Toronto, Montreal, Québec', 'Rocky Mountains, Niagarafälle, Banff-Nationalpark, Icefields Parkway', 'Berge' ),
			'Mexiko' => $country( 'Mexiko-Stadt, Oaxaca, Cancún, Mérida', 'Chichén Itzá, Yucatán, Teotihuacán, Kupferschlucht', 'Strand' ),
		),
		'Karibik und Mittelamerika' => array(
			'Kuba'       => $country( 'Havanna, Trinidad, Varadero, Santiago de Cuba', 'Viñales-Tal, Schweinebucht, Tabakplantagen', 'Strand' ),
			'Costa Rica' => $country( 'San José, La Fortuna, Monteverde', 'Vulkan Arenal, Tortuguero, Nebelwald, Manuel-Antonio-Nationalpark', 'Strand' ),
			'Panama'     => $country( 'Panama-Stadt, Boquete, Bocas del Toro', 'Panamakanal, San-Blas-Inseln', 'Strand' ),
		),
		'Südamerika'     => array(
			'Peru'        => $country( 'Lima, Cusco, Arequipa, Puno', 'Machu Picchu, Titicacasee, Heiliges Tal, Colca-Canyon', 'Berge' ),
			'Bolivien'    => $country( 'La Paz, Sucre, Potosí', 'Salar de Uyuni, Titicacasee, Mondtal', 'Berge' ),
			'Chile'       => $country( 'Santiago, Valparaíso, Puerto Natales', 'Atacama-Wüste, Torres del Paine, Osterinsel', 'Berge, Wein' ),
			'Argentinien' => $country( 'Buenos Aires, Mendoza, Bariloche, Salta', 'Iguazú-Wasserfälle, Patagonien, Perito-Moreno-Gletscher', 'Berge, Wein' ),
			'Brasilien'   => $country( 'Rio de Janeiro, Salvador, Manaus', 'Amazonas, Zuckerhut, Pantanal, Copacabana', 'Strand' ),
		),
		'Ozeanien'       => array(
			'Australien' => $country( 'Sydney, Melbourne, Cairns, Adelaide', 'Uluru, Great Barrier Reef, Great Ocean Road, Kakadu-Nationalpark', 'Strand' ),
			'Neuseeland' => $country( 'Auckland, Wellington, Queenstown, Christchurch', 'Milford Sound, Tongariro-Nationalpark, Rotorua, Abel-Tasman-Nationalpark', 'Berge, Strand' ),
		),
	);
}

/**
 * Kinds of trips: what a country needs for them, and the words they are advertised with.
 *
 * @return array<string, array{needs: string, days: int[], means: string[], daily: string[], hooks: string[]}>
 */
function prstsearch_corpus_kinds(): array {
	return array(
		'Rundreise'     => array(
			'needs' => '',
			'days'  => array( 8, 10, 12, 14, 16, 21 ),
			'means' => array( 'im klimatisierten Reisebus', 'mit dem Mietwagen', 'im Kleinbus mit höchstens zwölf Gästen', 'mit Bahn und Bus' ),
			'daily' => array( 'Stadtführung mit deutschsprachiger Reiseleitung', 'Fahrt durch die Landschaft mit mehreren Fotostopps', 'Besuch eines Marktes und Zeit zur freien Verfügung', 'Besichtigung am Vormittag, der Nachmittag gehört Ihnen' ),
			'hooks' => array( 'Höhepunkte in aller Ruhe', 'die große Runde', 'von Stadt zu Stadt', 'das ganze Land entdecken' ),
		),
		'Städtereise'   => array(
			'needs' => '',
			'days'  => array( 3, 4, 5 ),
			'means' => array( 'mit dem Flugzeug', 'mit dem Zug', 'mit Direktflug ab mehreren deutschen Flughäfen' ),
			'daily' => array( 'Rundgang durch die Altstadt', 'Museumsbesuch mit Eintritt ohne Wartezeit', 'Bummel über den Wochenmarkt und Kaffeepause', 'Abendspaziergang mit Blick über die Dächer' ),
			'hooks' => array( 'ein langes Wochenende', 'Kultur und Kulinarik', 'kurz weg', 'Museen, Märkte, Gassen' ),
		),
		'Badereise'     => array(
			'needs' => 'Strand',
			'days'  => array( 7, 10, 14 ),
			'means' => array( 'mit Direktflug und Transfer zum Hotel', 'mit Flug und Mietwagen' ),
			'daily' => array( 'Tag am Strand, Liegen und Sonnenschirm inklusive', 'Bootsausflug zu einer Badebucht', 'Schnorcheln am Riff', 'freier Tag am Pool' ),
			'hooks' => array( 'Sonne, Sand und Meer', 'Erholung direkt am Strand', 'einfach abschalten', 'Meerblick inklusive' ),
		),
		'Wanderreise'   => array(
			'needs' => 'Berge',
			'days'  => array( 7, 8, 10, 14 ),
			'means' => array( 'mit Gepäcktransport von Unterkunft zu Unterkunft', 'mit festem Standorthotel', 'von Hütte zu Hütte' ),
			'daily' => array( 'Wanderung von etwa fünf Stunden mit 600 Höhenmetern', 'leichte Tour am See entlang', 'Gipfeltag mit Bergführer', 'Ruhetag mit Gelegenheit zu einer Halbtagestour' ),
			'hooks' => array( 'zu Fuß durch die Berge', 'Gipfel, Grate und Hütten', 'Schritt für Schritt', 'Wandern mit leichtem Gepäck' ),
		),
		'Radreise'      => array(
			'needs' => '',
			'days'  => array( 6, 7, 8, 10 ),
			'means' => array( 'mit Leihrad oder E-Bike', 'mit Begleitfahrzeug und Gepäcktransport', 'individuell mit ausgearbeiteten Routen' ),
			'daily' => array( 'Etappe von etwa 50 Kilometern auf ruhigen Nebenstraßen', 'kurze Etappe und Zeit für eine Besichtigung', 'Fahrt am Fluss entlang mit Einkehr', 'Etappe mit einigen Steigungen und langer Abfahrt' ),
			'hooks' => array( 'im Sattel unterwegs', 'gemütlich auf zwei Rädern', 'Etappe für Etappe', 'mit Rückenwind' ),
		),
		'Safari'        => array(
			'needs' => 'Safari',
			'days'  => array( 8, 10, 12, 14 ),
			'means' => array( 'im offenen Geländewagen', 'als Flugsafari zwischen den Camps', 'im Geländewagen mit Dachluke' ),
			'daily' => array( 'Pirschfahrt bei Sonnenaufgang', 'Pirschfahrt am späten Nachmittag mit Sundowner', 'Fußpirsch mit einem Ranger', 'Bootsfahrt zu Flusspferden und Krokodilen' ),
			'hooks' => array( 'auf der Spur der Big Five', 'Tierbeobachtung aus nächster Nähe', 'Nächte im Zeltcamp', 'Wildnis erleben' ),
		),
		'Familienreise' => array(
			'needs' => '',
			'days'  => array( 7, 10, 14 ),
			'means' => array( 'mit kurzen Fahrstrecken und kinderfreundlichen Unterkünften', 'im Familienzimmer mit Halbpension', 'mit dem Mietwagen in eigenem Tempo' ),
			'daily' => array( 'Ausflug, der auch Kindern ab sechs Jahren Spaß macht', 'Vormittag im Tierpark, Nachmittag am Wasser', 'kleine Wanderung mit Picknick', 'Tag zur freien Verfügung mit Kinderbetreuung im Hotel' ),
			'hooks' => array( 'Abenteuer für Groß und Klein', 'Ferien mit Kindern', 'gemeinsam unterwegs', 'entspannt mit der ganzen Familie' ),
		),
		'Studienreise'  => array(
			'needs' => '',
			'days'  => array( 8, 10, 12, 15 ),
			'means' => array( 'mit einer Kunsthistorikerin als Reiseleitung', 'in einer Gruppe von höchstens 18 Gästen', 'mit Vorträgen an den Abenden' ),
			'daily' => array( 'Führung durch eine Ausgrabungsstätte', 'Besuch eines Klosters und Gespräch mit einem Mönch', 'Museumstag mit Einführungsvortrag', 'Besichtigung einer Kirche und ihrer Fresken' ),
			'hooks' => array( 'Geschichte verstehen', 'Kunst und Kultur mit Tiefgang', 'auf den Spuren der Antike', 'mehr sehen, mehr wissen' ),
		),
		'Genussreise'   => array(
			'needs' => 'Wein',
			'days'  => array( 5, 6, 7, 8 ),
			'means' => array( 'in kleinen, familiengeführten Hotels', 'mit Weinproben bei den Winzern', 'mit einem Koch als Begleitung' ),
			'daily' => array( 'Weinprobe auf einem Weingut mit Kellerführung', 'Kochkurs mit regionalen Zutaten', 'Besuch einer Käserei und Verkostung', 'Abendessen in einem Restaurant mit Stern' ),
			'hooks' => array( 'Wein und gutes Essen', 'schlemmen wie die Einheimischen', 'von Weingut zu Weingut', 'für Feinschmecker' ),
		),
		'Bahnreise'     => array(
			'needs' => '',
			'days'  => array( 6, 8, 10, 12 ),
			'means' => array( 'in der ersten Klasse mit reservierten Plätzen', 'mit Panoramazug und Nachtzug', 'ganz ohne Flug' ),
			'daily' => array( 'Bahnfahrt durch die Berge mit Fensterplatz', 'Fahrt im Panoramawagen und Stadtbummel am Ziel', 'Tag ohne Zug zur freien Verfügung', 'Fahrt im Speisewagen mit Mittagessen' ),
			'hooks' => array( 'auf Schienen', 'entspannt mit dem Zug', 'der Weg ist das Ziel', 'klimafreundlich reisen' ),
		),
	);
}

/**
 * A generator of random numbers that only depends on its seed, whatever PHP version runs it.
 */
final class Prstsearch_Corpus_Dice {

	private int $rolls = 0;

	public function __construct( private readonly int $seed ) {}

	public function below( int $limit ): int {
		// A hash of seed and count: evenly spread, and the same on every machine.
		return hexdec( substr( md5( $this->seed . ":" . ++$this->rolls ), 0, 7 ) ) % $limit;
	}

	/**
	 * @param array<int, mixed> $items
	 */
	public function pick( array $items ): mixed {
		return $items[ $this->below( count( $items ) ) ];
	}

	/**
	 * @param array<int, mixed> $items
	 * @return array<int, mixed>
	 */
	public function some( array $items, int $count ): array {
		$picked = array();

		while ( count( $picked ) < min( $count, count( $items ) ) ) {
			$picked[ $this->below( count( $items ) ) ] = true;
		}

		ksort( $picked );

		return array_values( array_intersect_key( $items, $picked ) );
	}
}

/**
 * Joins names the way a sentence does: "A, B und C".
 *
 * @param string[] $names
 */
function prstsearch_corpus_list( array $names ): string {
	$last = array_pop( $names );

	return array() === $names ? (string) $last : implode( ', ', $names ) . ' und ' . $last;
}

/**
 * Builds the trip with the given number.
 *
 * @return array{title: string, content: string, countries: string[], kind: string, days: int, price: int, date: string}
 */
function prstsearch_corpus_trip( int $number ): array {
	$dice    = new Prstsearch_Corpus_Dice( $number );
	$regions = prstsearch_corpus_countries();
	$kinds   = prstsearch_corpus_kinds();

	$region    = $dice->pick( array_keys( $regions ) );
	$available = $regions[ $region ];
	$kind_name = $dice->pick( array_keys( $kinds ) );
	$kind      = $kinds[ $kind_name ];

	// Every third round trip crosses borders; other trips stay in one country.
	$crosses = 'Rundreise' === $kind_name && count( $available ) > 1 && 0 === $dice->below( 3 );
	$names   = $crosses
		? $dice->some( array_keys( $available ), 2 + $dice->below( min( 3, count( $available ) - 1 ) ) )
		: array( $dice->pick( array_keys( $available ) ) );

	$is_near = in_array( $region, array( 'Südeuropa', 'Alpen und Adria', 'Nordeuropa', 'Britische Inseln', 'Osteuropa' ), true );
	$fits    = '' === $kind['needs'] || in_array( $kind['needs'], $available[ $names[0] ]['traits'], true );

	// A trip a country is not made for becomes a round trip, which every country offers.
	// Nobody travels to Kenya by train or cycles through Australia in a week.
	$on_rails = $is_near && ! in_array( $names[0], array( 'Malta', 'Island' ), true );

	// A trip a country is not made for becomes one it is made for. Nobody travels
	// to Kenya by train or cycles through Australia in a week.
	if ( ! $fits || ( ! $on_rails && in_array( $kind_name, array( 'Bahnreise', 'Radreise' ), true ) ) ) {
		$kind_name = $dice->pick( array( 'Rundreise', 'Städtereise', 'Familienreise', 'Studienreise' ) );
		$kind      = $kinds[ $kind_name ];
	}

	$days   = $dice->pick( $kind['days'] ) + ( $crosses ? 2 * ( count( $names ) - 1 ) : 0 );
	$price  = 390 + $days * ( 95 + $dice->below( 160 ) ) + ( $is_near ? 0 : 900 );
	$price  = (int) ( round( $price / 10 ) * 10 ) - 1;
	$hook   = $dice->pick( $kind['hooks'] );
	$means  = $dice->pick( $kind['means'] );
	$months = array( 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' );
	$season = $dice->some( $months, 3 );
	$where  = prstsearch_corpus_list( $names );

	$stops = array();

	foreach ( $names as $name ) {
		foreach ( $dice->some( $available[ $name ]['cities'], $crosses ? 2 : 3 ) as $city ) {
			$stops[] = array( $city, $dice->pick( $available[ $name ]['sights'] ), $name );
		}
	}

	// A place in the title tells apart the many trips of one kind to one country.
	$title = sprintf( '%s %s: %s – %s und %s (%d Tage)', $kind_name, $crosses ? 'durch ' . $where : $where, $hook, $stops[0][0], $stops[ count( $stops ) - 1 ][1], $days );

	$openers = array(
		'%4$s: Diese %1$s führt Sie %2$s nach %3$s.',
		'%4$s: Unsere %1$s nach %3$s bringt Sie %2$s zu den schönsten Orten.',
		'Sie suchen eine %1$s nach %3$s? Dann reisen Sie mit uns %2$s. Unser Motto für diese Reise: %5$s.',
		'Mit dieser %1$s lernen Sie %3$s so kennen, wie es wirklich ist: %2$s, in kleinen Etappen und mit viel Zeit vor Ort.',
	);
	$promises = array(
		'Sie wohnen in ausgewählten Hotels der Mittelklasse, das Frühstück ist an jedem Tag inbegriffen.',
		'Übernachtet wird in landestypischen Unterkünften mit Halbpension.',
		'Alle Unterkünfte liegen zentral, sodass Sie abends zu Fuß in die Altstadt kommen.',
		'Die Reise ist auch für Alleinreisende geeignet, Einzelzimmer sind ohne hohen Zuschlag buchbar.',
		'Auf Wunsch holen wir Sie mit dem Taxi an der Haustür ab und bringen Sie zum Flughafen.',
		'An zwei Abenden ist ein gemeinsames Abendessen mit regionalen Spezialitäten eingeplant.',
	);
	$closers = array(
		'Für die Einreise genügt in der Regel ein gültiger Reisepass; ob Sie ein Visum brauchen, erfahren Sie bei der Buchung.',
		'Eine Reiserücktrittsversicherung ist nicht im Preis enthalten, wir empfehlen sie aber ausdrücklich.',
		'Die Reise findet ab sechs Teilnehmern statt, die Gruppe ist auf sechzehn Gäste begrenzt.',
		'Bei Buchung bis vier Monate vor Abreise erhalten Sie fünf Prozent Frühbucherrabatt.',
		'Die Anreise mit der Bahn zum Flughafen ist innerhalb Deutschlands kostenlos.',
	);
	$included = array(
		'Flug ab Frankfurt, München oder Berlin',
		'alle Transfers vor Ort',
		'Übernachtungen im Doppelzimmer',
		'tägliches Frühstück',
		'Halbpension an ausgewählten Tagen',
		'deutschsprachige Reiseleitung',
		'Eintrittsgelder laut Programm',
		'Reiseführer und Kartenmaterial',
		'Gepäcktransport zwischen den Unterkünften',
	);

	$blocks = array(
		array( 'p', sprintf( $dice->pick( $openers ), $kind_name, $means, $where, ucfirst( $hook ), $hook ) ),
		array( 'p', implode( ' ', $dice->some( $promises, 2 ) ) ),
		array( 'h', 'Reiseverlauf' ),
	);

	$program = array( sprintf( 'Tag 1: Anreise nach %s und Begrüßung durch die Reiseleitung.', $stops[0][0] ) );
	$step    = max( 1, intdiv( $days - 2, count( $stops ) ) );

	foreach ( $stops as $position => $stop ) {
		$from = 2 + $position * $step;
		// The last stop lasts until the day before the journey home.
		$to   = $position === count( $stops ) - 1 ? $days - 1 : min( $days - 1, $from + $step - 1 );
		$from = min( $from, $to );

		$program[] = sprintf(
			'%s: %s (%s) – %s. Ausflug: %s.',
			$from === $to ? 'Tag ' . $from : 'Tag ' . $from . ' bis ' . $to,
			$stop[0],
			$stop[2],
			$dice->pick( $kind['daily'] ),
			$stop[1]
		);
	}

	$program[] = sprintf( 'Tag %d: Rückreise oder individuelle Verlängerung.', $days );

	$blocks[] = array( 'l', $program );
	$blocks[] = array( 'h', 'Leistungen' );
	$blocks[] = array( 'l', $dice->some( 'Bahnreise' === $kind_name ? array_merge( array( 'alle Bahnfahrten in der ersten Klasse' ), array_slice( $included, 1 ) ) : $included, 5 ) );
	$blocks[] = array( 'h', 'Preis und Termine' );
	$blocks[] = array( 'p', sprintf( 'Die Reise dauert %d Tage und kostet ab %s Euro pro Person im Doppelzimmer. Termine gibt es im %s. %s', $days, number_format( $price, 0, ',', '.' ), prstsearch_corpus_list( $season ), $dice->pick( $closers ) ) );

	$content = '';

	foreach ( $blocks as $block ) {
		$content .= match ( $block[0] ) {
			'p' => "<!-- wp:paragraph -->\n<p>" . esc_html( $block[1] ) . "</p>\n<!-- /wp:paragraph -->\n\n",
			'h' => "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $block[1] ) . "</h2>\n<!-- /wp:heading -->\n\n",
			'l' => "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . implode( '', array_map( static fn ( string $item ): string => "<!-- wp:list-item -->\n<li>" . esc_html( $item ) . "</li>\n<!-- /wp:list-item -->", $block[1] ) ) . "</ul>\n<!-- /wp:list -->\n\n",
		};
	}

	return array(
		'title'     => $title,
		'content'   => $content,
		'countries' => $names,
		'kind'      => $kind_name,
		'days'      => $days,
		'price'     => $price,
		// Spread over three years, the same day for the same number.
		'date'      => gmdate( 'Y-m-d H:i:s', strtotime( '2026-10-01 08:00:00' ) - $dice->below( 3 * 365 * 24 ) * HOUR_IN_SECONDS ),
	);
}

/**
 * Returns the IDs of the posts of the corpus, keyed by their number.
 *
 * @return array<int, int>
 */
function prstsearch_corpus_existing(): array {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value, post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", PRSTSEARCH_CORPUS_META ), ARRAY_N );

	return array_map( 'intval', array_column( $rows ? $rows : array(), 1, 0 ) );
}

$started  = microtime( true );
$existing = prstsearch_corpus_existing();

if ( 'delete' === ( $argv[1] ?? '' ) ) {
	foreach ( $existing as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	printf( "deleted %d posts in %.1f s\n", count( $existing ), microtime( true ) - $started );
	exit( 0 );
}

$count = max( 1, (int) ( $argv[1] ?? 100 ) );
$first = max( 1, (int) ( $argv[2] ?? 1 ) );

$category = term_exists( 'Reisen', 'category' );
$category = $category ? $category : wp_insert_term( 'Reisen', 'category' );
$author   = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
$created  = 0;

wp_defer_term_counting( true );

for ( $number = $first; $number < $first + $count; $number++ ) {
	if ( isset( $existing[ $number ] ) ) {
		continue;
	}

	$trip    = prstsearch_corpus_trip( $number );
	$post_id = wp_insert_post(
		array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_author'   => $author,
			'post_title'    => $trip['title'],
			'post_content'  => $trip['content'],
			'post_date'     => get_date_from_gmt( $trip['date'] ),
			'post_date_gmt' => $trip['date'],
			'post_category' => array( (int) $category['term_id'] ),
			'tags_input'    => $trip['countries'],
			'meta_input'    => array(
				PRSTSEARCH_CORPUS_META              => $number,
				PRSTSEARCH_CORPUS_META . '_kind'    => $trip['kind'],
				PRSTSEARCH_CORPUS_META . '_days'    => $trip['days'],
				PRSTSEARCH_CORPUS_META . '_country' => implode( '|', $trip['countries'] ),
			),
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		printf( "number %d failed: %s\n", $number, $post_id->get_error_message() );
		continue;
	}

	// On a multilingual site a post without a language is shown nowhere.
	if ( function_exists( 'pll_set_post_language' ) && function_exists( 'pll_default_language' ) ) {
		pll_set_post_language( $post_id, pll_default_language() );
	}

	++$created;
}

wp_defer_term_counting( false );

$seconds = microtime( true ) - $started;

printf( "created %d posts (numbers %d to %d) in %.1f s, %.0f ms each, peak memory %.0f MB\n", $created, $first, $first + $count - 1, $seconds, $created > 0 ? 1000 * $seconds / $created : 0, memory_get_peak_usage( true ) / 1048576 );
