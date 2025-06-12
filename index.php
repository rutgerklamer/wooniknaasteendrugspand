<?php
// index.php – Speculatief Nieuws (B1-taal, volledig in het Nederlands met gettext)
// ------------------------------------------------------------------------------

// 1. Zet de locale en laad gettext
setlocale(LC_ALL, 'nl_NL.UTF-8');
bindtextdomain('messages', __DIR__ . '/locale');
textdomain('messages');

// 2. API-sleutels en mappen
require_once __DIR__ . '/api.php';
$mapsKey    = GOOGLE_API_KEY;
$openaiKey  = OPENAI_API_KEY;

define('SCENARIO_DIR', __DIR__ . '/scenario');
define('TEST_DIR',     __DIR__ . '/test_data');
define('REQUEST_DIR',  __DIR__ . '/requests');
$fov = 120;

define('DEBUG', false);  // Debug-modus: testdata alleen opslaan/laden bij true

// 3. Helpers
function fetchJson(string $url): ?array {
    $j = @file_get_contents($url);
    return $j ? json_decode($j, true) : null;
}

function fetchBuildingTypeFromOSM(float $lat, float $lng): ?string {
    $ql = sprintf(
        '[out:json][timeout:5];way["building"](around:20,%F,%F);out tags 1;',
        $lat, $lng
    );
    $d  = fetchJson('https://overpass-api.de/api/interpreter?data=' . urlencode($ql));
    return $d['elements'][0]['tags']['building'] ?? null;
}

function fetchHouseNumbersFromOSM(string $street, float $lat, float $lng): array {
    $ql = sprintf(
        '[out:json][timeout:10];node["addr:street"="%s"]["addr:housenumber"](around:500,%F,%F);out tags;',
        addslashes($street), $lat, $lng
    );
    $d = fetchJson('https://overpass-api.de/api/interpreter?data=' . urlencode($ql));
    $nums = [];
    if (isset($d['elements'])) {
        foreach ($d['elements'] as $e) {
            if (!empty($e['tags']['addr:housenumber'])) {
                $nums[] = $e['tags']['addr:housenumber'];
            }
        }
    }
    return $nums;
}

function generateWitnessQuotes(string $scenario, string $address, string $apiKey): array {
    $prompt = "Je bent misdaadjournalist. Schrijf 3 korte citaten (max 30 woorden) over een $scenario op $address, met herkenbare drugssignalen. Geef alleen een JSON-array.";
    $pl = [
        'model'       => 'gpt-4o-mini',
        'messages'    => [
            ['role'=>'system', 'content'=>'Antwoord alleen met JSON-array.'],
            ['role'=>'user',   'content'=>$prompt],
        ],
        'temperature' => 0.7,
        'max_tokens'  => 150,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($pl),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $js   = json_decode($resp, true)['choices'][0]['message']['content'] ?? '[]';
    $arr  = json_decode($js, true);
    return is_array($arr) ? array_slice($arr, 0, 3) : [];
}

/**
 * Berekent de afstand in kilometers tussen twee punten via de haversine-formule.
 *
 * @param float $lat1 Breedtegraad punt 1
 * @param float $lon1 Lengtegraad punt 1
 * @param float $lat2 Breedtegraad punt 2
 * @param float $lon2 Lengtegraad punt 2
 * @return float Afstand in kilometers
 */
function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371.0; // Aarde-straal in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * asin(min(1, sqrt($a)));
    return $R * $c;
}

/**
 * Zoekt een buuradres op dezelfde straat binnen ~100 m van het originele punt.
 *
 * @param string $street      Straatnaam zonder huisnummer
 * @param string $origNumber  Origineel huisnummer (bijv. "129" of "129A")
 * @param float  $latOrig     Breedtegraad van het originele adres
 * @param float  $lngOrig     Lengtegraad van het originele adres
 * @param string $mapsKey     Google Maps API-sleutel
 * @return array|null         Geocode-resultaat van het buuradres, of null als niet gevonden
 */
function findNeighborAddress(
    string $street,
    string $origNumber,
    float  $latOrig,
    float  $lngOrig,
    string $mapsKey
): ?array {
    // 1) Bereken kleine bounds rond originele punt (±0.0005° ≃ ±50 m)
    $delta = 0.0005;
    $bounds = sprintf(
        '%F,%F|%F,%F',
        $latOrig - $delta,
        $lngOrig - $delta,
        $latOrig + $delta,
        $lngOrig + $delta
    );

    // 2) Bouw kandidaat-huisnummers (zonder het originele nummer zelf)
    $candidates = [];
    // Haal de numerieke waarde en suffix uit het originele nummer
if (preg_match('/^(\d+)([A-Za-z])?$/', $origNumber, $m)) {
    $nr  = (int)$m[1];
    $suf = isset($m[2]) ? strtoupper($m[2]) : '';

    $parity = $nr % 2; // 0 = even, 1 = oneven

    // Alleen deltas gebruiken die dezelfde parity behouden
    foreach ([-2, -1, +1, +2] as $delta) {
        $newNr = $nr + $delta;
        if ($newNr > 0 && ($newNr % 2) === $parity) {
            $candidates[] = $newNr . $suf;
        }
    }

    // Voor letter-buren (zelfde nummer, andere suffix) blijft parity altijd gelijk
    if ($suf !== '') {
        $prev = chr(ord($suf) - 1);
        $next = chr(ord($suf) + 1);
        if (ctype_alpha($prev)) { $candidates[] = "{$nr}{$prev}"; }
        if (ctype_alpha($next)) { $candidates[] = "{$nr}{$next}"; }
    }
}

    shuffle($candidates);
    foreach ($candidates as $cand) {
        $address = urlencode("{$street} {$cand}");
        $url = "https://maps.googleapis.com/maps/api/geocode/json"
             . "?address={$address}"
             . "&bounds={$bounds}"
             . "&key={$mapsKey}";

        $geo = fetchJson($url);
        if (!$geo || $geo['status'] !== 'OK') {
            continue;
        }
        $res = $geo['results'][0];

        // 3) Skip als Google tóch je originele nummer teruggeeft
        $foundNumber = '';
        foreach ($res['address_components'] as $comp) {
            if (in_array('street_number', $comp['types'], true)) {
                $foundNumber = $comp['long_name'];
                break;
            }
        }
        if ($foundNumber === "{$nr}{$suf}") {
            continue;
        }

        // 4) Controleer of het écht dezelfde straat is
        $route = '';
        foreach ($res['address_components'] as $comp) {
            if (in_array('route', $comp['types'], true)) {
                $route = $comp['long_name'];
                break;
            }
        }
        if (strcasecmp($route, $street) !== 0) {
            continue;
        }

        // 5) Controleer of de afstand < 0.1 km (100 m)
        $lat2 = $res['geometry']['location']['lat'];
        $lng2 = $res['geometry']['location']['lng'];
        if (haversine($latOrig, $lngOrig, $lat2, $lng2) > 0.1) {
            continue;
        }

        // gevonden buuradres
        return $res;
    }

    // niets gevonden
    return null;
}

// 4. Invoer verwerken en eventueel laden via ID
$input      = $_GET['input_text']  ?? '';
$placeId    = $_GET['place_id']    ?? '';
$id         = $_GET['id']          ?? '';
$save       = DEBUG && isset($_GET['save_test_data']);
$load       = DEBUG && isset($_GET['use_saved']);
$loadedFromId = false;

$userAddress     = '';
$drugAddress     = '';
$svUrl           = '';
$map             = '';
$headline        = '';
$lead            = '';
$body            = [];
$displayBuilding = null;
$quotes          = [];
$signals         = [];
$shareUrl        = '';

// Als er een ID is, laad de opgeslagen request
if ($id !== '') {
    $jsonFile = REQUEST_DIR . "/request_{$id}.json";
    if (file_exists($jsonFile)) {
        $d = json_decode(file_get_contents($jsonFile), true);
        if (is_array($d)) {
            extract($d, EXTR_OVERWRITE);
            $headline = $d['headline'] ?? '';
            $lead     = $d['lead'] ?? '';
            $body     = $d['body'] ?? [];
            $userAddress     = $d['userAddress'] ?? '';
            $drugAddress     = $d['drugAddress'] ?? '';
            $svUrl           = $d['svUrl'] ?? '';
            $map             = $d['map'] ?? '';
            $displayBuilding = $d['displayBuilding'] ?? null;
            $quotes          = $d['quotes'] ?? [];
            $signals         = $d['signals'] ?? [];
            $loadedFromId = true;
            // Bouw shareUrl
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $shareUrl = "{$protocol}://{$_SERVER['HTTP_HOST']}{$_SERVER['SCRIPT_NAME']}?id={$id}";
        }
    }
}

// Als niet geladen via ID en input aanwezig, verwerk nieuwe request
if (!$loadedFromId && $input !== '') {
    // 4.1 Geocode origineel adres, met fix om gemeente te beperken
    $tmpGeo = fetchJson("https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($input) . "&key={$mapsKey}");
    $cmps = [];
    foreach (($tmpGeo['results'][0]['address_components'] ?? []) as $c) {
        foreach ($c['types'] as $t) {
            $cmps[$t] = $c['long_name'];
        }
    }
    $locality     = $cmps['locality'] ?? '';
    $encodedInput = urlencode($input);

    if ($placeId) {
        $gUrl = "https://maps.googleapis.com/maps/api/geocode/json?place_id={$placeId}&key={$mapsKey}";
    } else {
        $gUrl = "https://maps.googleapis.com/maps/api/geocode/json?address={$encodedInput}"
              . ($locality
                  ? "&components=locality:" . urlencode($locality) . "|country:NL"
                  : "&components=country:NL")
              . "&key={$mapsKey}";
    }
    $geo = fetchJson($gUrl);
    if (!$geo || $geo['status'] !== 'OK') {
        die(_('Geocode fout of buiten de gewenste plaats'));
    }
    $res = $geo['results'][0];

    $foundLocality = '';
    foreach ($res['address_components'] as $c) {
        if (in_array('locality', $c['types'], true)) {
            $foundLocality = $c['long_name'];
            break;
        }
    }
    if ($locality && strcasecmp($foundLocality, $locality) !== 0) { 
        die(sprintf(_('Adres moet in %s liggen, niet in %s.'), $locality, $foundLocality));
    }

    $street      = $cmps['route']        ?? '';
    $origNumber  = $cmps['street_number'] ?? '';
    $userAddress = trim("{$street} {$origNumber}");

    $latOrig = $res['geometry']['location']['lat'];
    $lngOrig = $res['geometry']['location']['lng'];

    $newRes = findNeighborAddress(
        $street,
        $origNumber,
        $latOrig,
        $lngOrig,
        $mapsKey
    );
    if (!$newRes) {
        $latInit = $latOrig;
        $lngInit = $lngOrig;
        $nums    = fetchHouseNumbersFromOSM($street, $latInit, $lngInit);
        if (!empty($nums)) {
            $randNum = $nums[array_rand($nums)];
            $q       = urlencode("{$street} {$randNum}");
            $g       = fetchJson("https://maps.googleapis.com/maps/api/geocode/json?address={$q}&key={$mapsKey}");
            if ($g && $g['status'] === 'OK') {
                $newRes = $g['results'][0];
            }
        }
    }

    if ($newRes) {
        $drugAddress = $newRes['formatted_address'];
        $latDrug     = $newRes['geometry']['location']['lat'];
        $lngDrug     = $newRes['geometry']['location']['lng'];
    } else {
        $drugAddress = $userAddress;
        $latDrug     = $latOrig;
        $lngDrug     = $lngOrig;
    }

    $rawType = fetchBuildingTypeFromOSM($latDrug, $lngDrug);
    $tr = [
        'apartments'  => 'appartement',
        'house'       => 'huis',
        'residential' => 'woonhuis',
        'detached'    => 'vrijstaand huis',
        'terrace'     => 'rijtjeshuis',
        'hotel'       => 'hotel',
        'office'      => 'kantoor',
        'retail'      => 'winkel',
        'supermarket' => 'supermarkt',
        'warehouse'   => 'pakhuis',
        'industrial'  => 'industrieel pand',
    ];
    $displayBuilding = $tr[$rawType] ?? _('pand');

    $svUrl = "https://maps.googleapis.com/maps/api/streetview?size=800x400&location={$latDrug},{$lngDrug}&fov={$fov}&key={$mapsKey}";
    $map   = 'https://www.google.com/maps/embed/v1/place?key=' . $mapsKey . '&q=' . urlencode($drugAddress);

    $files = glob(SCENARIO_DIR . '/*.json');
    if (!$files) die(_("Geen scenario's"));
    $scenarioFile = $files[array_rand($files)];
    $scenarioJson = file_get_contents($scenarioFile);
    $scenario     = json_decode($scenarioJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        die(_('Fout bij het lezen van scenario-bestand: ') . json_last_error_msg());
    }
    foreach (['headline_variants','lead_variants','body_variants'] as $key) {
        if (empty($scenario[$key]) || !is_array($scenario[$key])) {
            die(sprintf("%s ontbreekt of is leeg in scenario-bestand", $key));
        }
    }

    $randDeaths   = rand(2, 6);
    $randInjuries = rand(2, 16);
    $randAnimals  = rand(2, 5);
    $randCosts    = number_format(rand(30000, 120000), 0, ',', '.');
    $nowTime      = date('H:i');

    if (!empty($scenario['interview_pool'])) {
        shuffle($scenario['interview_pool']);
        $quotes = array_slice($scenario['interview_pool'], 1, 4);
    } else {
        $rawQuotes = generateWitnessQuotes($scenario['type'] ?? 'incident', $drugAddress, $openaiKey);
        $quotes = array_map(fn($q) => ['speaker'=>'Buurtbewoner','quote'=>$q], $rawQuotes);
    }
    if (!empty($scenario['interview_signals'])) {
        shuffle($scenario['interview_signals']);
        $signals = array_slice($scenario['interview_signals'], 1, 4);
    } else {
        $rawQuotes = generateWitnessQuotes($scenario['type'] ?? 'incident', $drugAddress, $openaiKey);
        $signals = array_map(fn($q) => ['speaker'=>'Buurtbewoner','quote'=>$q], $rawQuotes);
    }

    $vals = [
        '%BUILDING%' => $displayBuilding,
        '%ADDRESS%'  => $drugAddress,
        '%DEATHS%'   => $randDeaths,
        '%INJURIES%' => $randInjuries,
        '%ANIMALS%'  => $randAnimals,
        '%COSTS%'    => $randCosts,
        '%TIME%'     => $nowTime,
        '%QUOTE1%'   => $quotes[0]['quote'] ?? '',
        '%QUOTE2%'   => $quotes[1]['quote'] ?? '',
        '%QUOTE3%'   => $quotes[2]['quote'] ?? '',
        '%SIGNAL1%'   => $signals[0]['quote'] ?? '',
        '%SIGNAL2%'   => $signals[1]['quote'] ?? '',
        '%SIGNAL3%'   => $signals[2]['quote'] ?? '',
    ];

    $headlineTpl = $scenario['headline_variants'][array_rand($scenario['headline_variants'])];
    $headline    = strtr($headlineTpl, $vals);
    $leadTpl     = $scenario['lead_variants'][array_rand($scenario['lead_variants'])];
    $lead        = strtr($leadTpl, $vals);

    $allBodyTexts = [];
foreach ($scenario['body_variants'] as $bodySet) {
    $allBodyTexts = array_merge($allBodyTexts, $bodySet);
}
shuffle($allBodyTexts);
$paras = $allBodyTexts; // Gebruik alle b
    if (count($paras)>1) {
        $paras[0] .= ' '.$paras[1];
        array_splice($paras,1,1);
    }
    $body = array_map(fn($p)=>strtr($p,$vals),$paras);
    array_unshift($body, "De doden en gewonden zijn niet alleen gevallen in het drugspand, maar ook in het pand ernaast ({$userAddress}), dat tevens ernstig beschadigd raakte. De bewoners van dit pand hadden vermoedens dat er iets niet pluis was, maar ze hebben nooit een melding gemaakt. Ze ervoeren immers geen overlast.");

    // Sla JSON op
    if (!is_dir(REQUEST_DIR)) mkdir(REQUEST_DIR, 0755, true);
    $id = uniqid();
    $jsonData = compact(
  'userAddress','drugAddress','svUrl','map',
  'headline','lead','body','displayBuilding',
  'quotes','signals',
  'latOrig','lngOrig','latDrug','lngDrug'
);
    file_put_contents(REQUEST_DIR . "/request_{$id}.json", json_encode($jsonData, JSON_PRETTY_PRINT));

    // Bouw share URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $shareUrl = "{$protocol}://{$_SERVER['HTTP_HOST']}{$_SERVER['SCRIPT_NAME']}?id={$id}";
}

// 5. Toekomstige datum (+5 jaar) genereren voor nieuwsfooter
$future = new DateTime();
$future->modify('+5 years');
$year   = $future->format('Y');
$month  = rand(1,12);
$day    = rand(1,28);
$futureDate = DateTime::createFromFormat('Y-n-j', "{$year}-{$month}-{$day}");
$formatter  = new IntlDateFormatter('nl_NL', IntlDateFormatter::LONG, IntlDateFormatter::NONE);
$dateStr    = ucfirst($formatter->format($futureDate));

// 5b. Caching streetview image voor delen én Open Graph meta
if (!is_dir(REQUEST_DIR)) {
    mkdir(REQUEST_DIR, 0755, true);
}
if ($headline) {
    $hash     = md5($drugAddress);
    $filename = "request_{$hash}.jpg";
    $filePath = REQUEST_DIR . '/' . $filename;
    if (!file_exists($filePath)) {
        file_put_contents($filePath, file_get_contents($svUrl));
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $ogUrl    = "{$protocol}://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
    $ogImage  = "{$protocol}://{$_SERVER['HTTP_HOST']}/requests/{$filename}";
}
// index.php (bovenaan)
$markerFiles = glob(__DIR__ . '/markers/*.json');
$allMarkers = [];
foreach ($markerFiles as $f) {
    $m = json_decode(file_get_contents($f), true);
    if (isset($m['lat'],$m['lng'])) {
        $allMarkers[] = $m;
    }
}

?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Speculatief Nieuws – <?= htmlspecialchars($headline) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="style.css">
  <script src="https://maps.googleapis.com/maps/api/js?key=<?= $mapsKey ?>&libraries=places"></script>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.3/dist/leaflet.css" />
  <script src="https://unpkg.com/leaflet@1.9.3/dist/leaflet.js"></script>
  <style>
    #map { width:100%; height:400px; }
  </style>

  <?php if ($headline): ?>
    <!-- Open Graph voor sociale media & WhatsApp -->
    <meta property="og:title"       content="<?= htmlspecialchars($headline) ?>" />
    <meta property="og:description" content="<?= htmlspecialchars($lead) ?>" />
    <meta property="og:image"       content="<?= htmlspecialchars($ogImage) ?>" />
    <meta property="og:url"         content="<?= htmlspecialchars($ogUrl) ?>" />
    <meta property="og:type"        content="article" />
    <meta name="twitter:card"       content="summary_large_image" />
  <?php endif; ?>
</head>
<body>
  <header class="site-header">
    <div class="header-top">
      <a href="/" class="logo">Speculatief<span>Nieuws</span></a>
      <form class="search-form2">
        <input placeholder="Zoek nieuws…">
        <button type="submit">Zoeken</button>
      </form>
    </div>
    <nav class="main-nav">
      <ul>
        <li><a href="#">Binnenland</a></li>
        <li><a href="#">Buitenland</a></li>
        <li><a href="#">Economie</a></li>
        <li><a href="#">Sport</a></li>
        <li><a href="#">Cultuur</a></li>
        <li><a href="#">Video</a></li>
      </ul>
    </nav>
  </header>

  <?php if ($headline): ?>
    <div class="breaking-news">
      <span>BREAKING:</span> <?= htmlspecialchars($headline) ?>
    </div>
  <?php endif; ?>

  <main class="content-wrapper">
    <?php if ($headline): ?>
    <article class="lead-article">
      <figure class="lead-image">
        <img src="<?= htmlspecialchars($svUrl) ?>" alt="StreetView van <?= htmlspecialchars($drugAddress) ?>">
      </figure>
      <div class="lead-text">
        <h1><?= htmlspecialchars($headline) ?></h1>
        <p class="meta"><?= $dateStr ?> – door Redactie</p>
        <p class="lead"><?= htmlspecialchars($lead) ?></p>
        <p>
          
      <div class="map-container">
        <iframe src="<?= $map ?>" loading="lazy"></iframe>
      </div>
          <button id="shareBtn">Deel dit artikel</button>
        </p>
      </div>
      <div class="article-body">
      <?php foreach ($body as $para): ?>
        <?php if (strpos($para, '"') === 0): ?>
          <blockquote class="quote"><?= $para ?></blockquote>
        <?php else: ?>
          <p><?= $para ?></p>
        <?php endif; ?>
      <?php endforeach; ?>

       <b>Verdiepende vragen!</b> <br>
      Wat gebeurde er met je toen je deze tekst las? <br>
      Wat denk je dat het doel is van dit artikel? <br>
      Waar ligt voor jou de grens? Wietkwekerij? Dealer? Drugslab?  <br>
      Waar zou jij een drugspand liever plaatsen? Vul in op de kaart.<br><br>
      
      
      

      
<b>Waar mag het wel?</b><br>
       <p class="explanation">
    Er is vraag naar drugs, daarom is er ook aanbod.<br>
    Het aanbod wordt vaak snel weer aangevuld na het sluiten van een pand.<br>
    Waar zou jij een drugspand wel oké vinden?
  </p>
  <div id="map"></div>
   
<button id="placeBtn">Plaats drugspand hier</button>
<p id="status-text"></p>      

De politie roept iedereen op die iets verdachts ziet dit te melden. Dat kan via het algemene nummer 0900-8844 of anoniem via Meld Misdaad Anoniem op 0800-7000.
      Hoe meer mensen een melding doen, hoe sneller er actie kan worden ondernomen.
      Ookal heb je niet direct overlast, het is belangrijk dat je dit soort zaken meldt.
      Gelukkig is dit slechts een voorbeeld van wat er mis kan gaan.<br><br>

      <b>Hoe herken je een drugspand?</b> <br>
      Herken je deze signalen in je buurt? Dit zijn de meest voorkomende kenmerken van een drugspand:
      <ul>
        <li><strong>Ongewone bezoekersstromen:</strong> veel korte bezoekjes, vooral 's avonds of 's nachts.</li>
        <li><strong>Verduisterde ramen:</strong> ramen die gesloten, beplakt of zwaar verduisterd zijn.</li>
        <li><strong>Verdachte bezorgingen:</strong> regelmatig pakketjes of zakjes die snel worden afgeleverd en weer meegenomen.</li>
        <li><strong>Extra beveiliging:</strong> camera's, tralies of zware sloten die afwijken van de rest van de straat.</li>
        <li><strong>Lawaai en overlast:</strong> veel verkeer, muziek of geroezemoes op ongewone tijden.</li>
      </ul>
      Wil je dit verder oefenen? <a href="https://e-powerment.nl/wooniknaasteendrugspand" target="_blank">Doe hier de test</a> of <a href="https://e-powerment.nl/drugspandchatsimulatie" target="_blank">chat met Alex</a>.
<!--<h2>Locatie</h2>
      <p><?= htmlspecialchars($drugAddress) ?></p>
      <div class="map-container">
        <iframe src="<?= $map ?>" loading="lazy"></iframe>
      </div>-->
    </article>
    <?php endif; ?>

    

    <aside class="sidebar">
      <div class="search-div">
        <b>Heb je het idee naast een drugspand te wonen? Bekijk de meldingen bij jou in de buurt. Voor het beste effect, gebruik je ook je huisnummer! </b>
        <form class="search-form">
          <input id="autocomplete" name="input_text" type="text" placeholder="Vind drugspanden bij jou in de buurt..." value="<?= htmlspecialchars($input) ?>">
          <input type="hidden" id="place_id" name="place_id" value="<?= htmlspecialchars($placeId) ?>">
          <button type="submit">Zoeken</button>
        </form><br>
      </div>
      <section class="widget">
        <h3>Trending</h3>
        <ul>
          <li><a target="_blank" href="https://e-powerment.nl/wooniknaasteendrugspand">Herkennen hoe een drugspand eruit ziet? Doe de test.</a></li>
          <li><a target="_blank" href="https://e-powerment.nl/drugspandchatsimulatie">Alex woont naast een drugspand, wat moet hij doen?.</a></li>
          <li><a target="_blank" href="https://e-powerment.nl/wooniknaasteendrugspand">Herkennen hoe een drugsgebruiker eruit ziet? Doe de test.</a></li>
        </ul>
      </section>
    </aside>
  </main>

  <footer class="site-footer">
    <div class="footer-links">
      <a href="#">Contact</a> |
      <a href="#">Privacy</a> |
      <a href="#">Disclaimer</a> |
      <a href="#">Adverteren</a>
    </div>
    <div class="footer-copy">
      &copy; <?= date('Y') ?> Speculatief Nieuws. Alle rechten voorbehouden.
    </div>
  </footer>
<script>
   const centerLat  = <?= isset($latOrig) ? $latOrig : '0' ?>;
  const centerLng  = <?= isset($lngOrig) ? $lngOrig : '0' ?>;
window.addEventListener('load', function() {
  // 1) Data uit PHP
  const centerLat  = <?= $latOrig ?>;
  const centerLng  = <?= $lngOrig ?>;
  const userAddr   = <?= json_encode($userAddress, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const existing   = <?= json_encode($allMarkers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

  // 2) Icons: rood voor uw adres, blauw voor drugspand
  const redIcon = new L.Icon({
    iconUrl:  'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-red.png',
    shadowUrl:'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-shadow.png',
    iconSize: [25,41], iconAnchor:[12,41],
    popupAnchor:[1,-34], shadowSize:[41,41]
  });
  const blueIcon = new L.Icon({
    iconUrl:  'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-blue.png',
    shadowUrl:'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-shadow.png',
    iconSize: [25,41], iconAnchor:[12,41],
    popupAnchor:[1,-34], shadowSize:[41,41]
  });

  // 3) Kaart initialiseren (pas laden na window.load)
  const map = L.map('map').setView([centerLat, centerLng], 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
    attribution:'&copy; OpenStreetMap'
  }).addTo(map);

  // 4) Rode marker: uw adres, popup direct openen
  L.marker([centerLat, centerLng], { icon: redIcon })
    .bindPopup('<strong>Uw adres:</strong><br>' + userAddr)
    .addTo(map)
    .openPopup();

  // 5) Bestaande blauwe markers inladen
  existing.forEach(m => {
    L.marker([m.lat, m.lng], { icon: blueIcon }).addTo(map);
  });

  // 6) Voorbereiden draggable drugspand-marker
  let drugMarker   = null;
  let markerPlaced = false;
  let markerSaved  = false;
  const statusText = document.getElementById('status-text');
  const placeBtn   = document.getElementById('placeBtn');

  // 7) Klik op kaart: zet of verplaats blauwe marker
  map.on('click', function(e) {
    if (markerSaved) return;            // niet meer verplaatsen na opslaan
    const { lat, lng } = e.latlng;

    if (!markerPlaced) {
      // eerste keer plaatsen
      drugMarker = L.marker([lat, lng], {
        icon: blueIcon,
        draggable: true
      })
      .addTo(map)
      .bindPopup('Wilt u dit pand hier plaatsen?')
      .openPopup();
      markerPlaced = true;
      statusText.textContent = '';
    } else {
      // verplaatsen
      drugMarker.setLatLng([lat, lng]).openPopup();
      statusText.textContent = '';
    }
  });

  // 8) Klik op knop: opslaan en popup aanpassen
  placeBtn.addEventListener('click', async function() {
    if (!markerPlaced) {
      statusText.textContent = 'Kies eerst een locatie op de kaart.';
      return;
    }
    if (markerSaved) return;

    placeBtn.disabled   = true;
    statusText.textContent = 'Bezig met opslaan…';

    const { lat, lng } = drugMarker.getLatLng();
    const form = new URLSearchParams({
      lat:       lat,
      lng:       lng,
      centerLat: centerLat,
      centerLng: centerLng
    });

    try {
      const res  = await fetch('save_marker.php', { method:'POST', body: form });
      const json = await res.json();
      if (!json.ok) throw json.error || 'Onbekende fout';

      // Marker is opgeslagen: vergrendel en update popup
      markerSaved = true;
      drugMarker.dragging.disable();
      drugMarker
        .unbindPopup()
        .bindPopup('Drugspand')
        .openPopup();

      statusText.textContent = 'Pand succesvol opgeslagen!';
      placeBtn.textContent   = 'Opgeslagen';
    } catch (err) {
      console.error(err);
      statusText.textContent = 'Opslaan mislukt: ' + err;
      placeBtn.disabled      = false;
    }
  });
});
</script>



  <script>
    const input = document.getElementById('autocomplete');
    const pid   = document.getElementById('place_id');
    const ac    = new google.maps.places.Autocomplete(input, {
      types: ['geocode'],
      componentRestrictions: { country: 'nl' }
    });
    ac.addListener('place_changed', () => {
      pid.value = ac.getPlace().place_id || '';
    });

    // 1) Jouw bestaande showCopyPopup
    function showCopyPopup(text) {
      const msg = document.createElement('div');
      msg.className = 'copy-msg';
      msg.textContent = text;
      document.body.appendChild(msg);
      requestAnimationFrame(() => msg.classList.add('visible'));
      setTimeout(() => {
        msg.classList.remove('visible');
        msg.addEventListener('transitionend', () => msg.remove(), { once: true });
      }, 3000);
    }

    // 2) **Nieuwe** copyToClipboard-fallback
    async function copyToClipboard(text) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        // HTTPS of localhost
        return navigator.clipboard.writeText(text);
      }
      // Fallback oude execCommand-hack
      const textarea = document.createElement('textarea');
      textarea.value = text;
      textarea.style.position = 'fixed';
      textarea.style.top = '-9999px';
      document.body.appendChild(textarea);
      textarea.focus();
      textarea.select();
      try {
        document.execCommand('copy');
      } finally {
        document.body.removeChild(textarea);
      }
    }

    // 3) Pas je shareBtn-listener aan
    document.getElementById('shareBtn')?.addEventListener('click', async () => {
      const url = '<?= $shareUrl ?>';
      try {
        await copyToClipboard(url);
        showCopyPopup('Gekopieerd');
      } catch (err) {
        console.error('Copy failed', err);
        showCopyPopup('Kopiëren mislukt – gebruik handmatig');
      }
    });
  </script>
</body>
</html>
