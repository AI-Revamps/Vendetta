<?php
/**
 * De opbouw van de pagina: krijgt elke toestand de juiste onderdelen?
 *
 *     php tests/opbouw.php
 *
 * Drie toestanden met elk hun eigen indeling:
 *
 *   uitgelogd  geen zijmenu, geen statuspaneel, geen onderbalk. Het raster is
 *              één kolom (klasse alleen-inhoud); zonder die klasse belandde de
 *              inhoud in de smalle menukolom.
 *   ingelogd   alles compleet, body krijgt de klasse spelmodus.
 *   dood       als uitgelogd, maar wel een eenvoudig menu met uitloggen en de
 *              spelregels — die speler moet ergens heen kunnen.
 */

declare(strict_types=1);

require __DIR__ . '/_start.php';

/** @return array<string,bool|string> */
function onderdelen(string $html): array
{
    return [
        'spelmodus'    => (bool) preg_match('/<body class="spelmodus"/', $html),
        'raster'       => preg_match('/<div class="(layout[^"]*)"/', $html, $m) ? $m[1] : '-',
        'statusstrook' => str_contains($html, 'class="statusstrook"'),
        'onderbalk'    => str_contains($html, 'class="onderbalk"'),
        'la'           => str_contains($html, 'id="zijmenu"'),
        'statuspaneel' => str_contains($html, 'class="statuspaneel"'),
        'hamburger'    => str_contains($html, 'class="menu-toggle"'),
    ];
}

// --- Uitgelogd -------------------------------------------------------------

kop('uitgelogd');
nieuwe_sessie();

foreach (['index.php', 'help.php', 'login.php', 'register.php'] as $pagina) {
    $o = onderdelen(haal($pagina)['body']);

    check($pagina . ': één kolom', $o['raster'] === 'layout alleen-inhoud', $o['raster']);
    check($pagina . ': geen spelonderdelen',
        !$o['statusstrook'] && !$o['onderbalk'] && !$o['statuspaneel']);
    check($pagina . ': wel een hamburger met menu', $o['hamburger'] && $o['la']);
}

// --- Advertentie op de voorpagina --------------------------------------------

kop('advertentie op de voorpagina, alleen als hij aanstaat');

$uit = haal('index.php', null, nieuwe_sessie())['body'];
check('standaard geen advertentieblok op de voorpagina',
    !str_contains($uit, 'class="advertentie"'));

$baas = login('Baas', 'baaswachtwoord12345');
doe('adm-premium.php', [
    'actie'    => 'advertentie',
    'html'     => '<div id="testadvertentie">Test-advertentie</div>',
    'interval' => '25',
    'outgame'  => '1',
], $baas);

$aan = haal('index.php', null, nieuwe_sessie())['body'];
check('advertentieblok verschijnt als de instelling aanstaat',
    str_contains($aan, 'class="advertentie"')
        && str_contains($aan, '<div id="testadvertentie">Test-advertentie</div>'));

// Instelling weer uitzetten, anders draait elke volgende pagina met reclame.
doe('adm-premium.php', [
    'actie'    => 'advertentie',
    'html'     => '',
    'interval' => '25',
], $baas);

$weerUit = haal('index.php', null, nieuwe_sessie())['body'];
check('advertentieblok weg na uitzetten', !str_contains($weerUit, 'class="advertentie"'));

// --- Ingelogd --------------------------------------------------------------

kop('ingelogd');
login('Speler', 'spelerwachtwoord123');

foreach (['home.php', 'crime.php', 'shop.php', 'help.php'] as $pagina) {
    $o = onderdelen(haal($pagina)['body']);

    check($pagina . ': drie kolommen', $o['raster'] === 'layout', $o['raster']);
    check($pagina . ': alles compleet',
        $o['spelmodus'] && $o['statusstrook'] && $o['onderbalk']
        && $o['la'] && $o['statuspaneel']);
}

$menu = haal('home.php')['body'];
check('menu bevat Loterij',
    (bool) preg_match('#<a href="[^"]*loterij[^"]*"[^>]*>Loterij</a>#', $menu));
check('menu bevat Rijschool',
    (bool) preg_match('#<a href="[^"]*rijbewijs[^"]*"[^>]*>Rijschool</a>#', $menu));

// --- Gevangenistimer ---------------------------------------------------------

kop('gevangenistimer');
$db = tdb();
$db->exec("DELETE FROM jail WHERE login = 'Speler'");
$db->exec("INSERT INTO jail (login, boete, time, stad, famillie, bo)
           VALUES ('Speler', 1000, DATE_ADD(NOW(), INTERVAL 300 SECOND), 'Brussel', '', 0)");

$html = haal('home.php')['body'];
check('gevangenistimer heeft data-tot',
    (bool) preg_match('/class="waarschuwing">Je zit vast:[^<]*<strong data-tot="\d+">/', $html));

$db->exec("DELETE FROM jail WHERE login = 'Speler'");

// --- Dood ------------------------------------------------------------------

kop('dood');
$db = tdb();
$db->exec("UPDATE users SET status='dood' WHERE login='Speler'");

$rip = haal('rip.php')['body'];
$o   = onderdelen($rip);

check('één kolom', $o['raster'] === 'layout alleen-inhoud', $o['raster']);
check('geen spelmodus', !$o['spelmodus']);
check('geen misdaadlinks', !str_contains($rip, 'crime.php'));
check('wel kunnen uitloggen', str_contains($rip, 'logout.php'));
check('wel bij de spelregels', str_contains($rip, 'help.php'));

$db->exec("UPDATE users SET status='levend' WHERE login='Speler'");

// --- Menu ------------------------------------------------------------------

kop('menu: alleen de groep waar je bent staat open');

$db->exec("UPDATE users SET famillie='De Testers', famrang=5, level=1000 WHERE login='Speler'");
login('Speler', 'spelerwachtwoord123');

$verwacht = [
    'home.php'       => 'Status',
    'shop.php'       => 'Plaatsen',
    'crime.php'      => 'Misdaden',
    'fam.php'        => 'Familie',
    'famman.php'     => 'Familie',
    'roulette.php'   => 'Gokken',
    'adm-search.php' => 'Beheer',
];

foreach ($verwacht as $pagina => $groep) {
    $html = haal($pagina)['body'];

    preg_match_all('/<details class="menugroep[^"]*" data-groep="([^"]+)"( open)?/',
        $html, $m, PREG_SET_ORDER);

    $open = [];
    foreach ($m as $g) {
        if (isset($g[2])) { $open[] = $g[1]; }
    }

    check($pagina . ' opent ' . $groep, $open === [$groep],
        $open === [] ? '(geen)' : implode(', ', $open));
}

// Het menu is met alles open ruim 2500 pixels; dan valt de onderste helft van
// het scherm en is Familie niet te bereiken.
$html = haal('home.php')['body'];
preg_match_all('/<details class="menugroep[^"]*"[^>]* open>.*?<\/details>/s', $html, $blokken);

$zichtbaar = 0;
foreach ($blokken[0] as $blok) {
    $zichtbaar += substr_count($blok, '<li>');
}

check('hoogstens één groep open', count($blokken[0]) === 1, count($blokken[0]) . ' open');
check('zichtbare items blijven beperkt', $zichtbaar <= 15, $zichtbaar . ' items');
check('alle groepen staan er nog', substr_count($html, '<summary>') >= 6);

// --- Klikmissies: badge in het menu ------------------------------------------

kop('klikmissies: het zijmenu toont hoeveel klikmissies beschikbaar zijn');

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");
$db->exec(
    "INSERT INTO klikmissies (naam, url, cooldown_seconden, actief)
     VALUES ('Testlijst', 'https://voorbeeld.test/stem?ref={login}', 86400, 1)"
);
$missieId = (int) $db->lastInsertId();

$html = haal('home.php')['body'];
check('badge toont 1 beschikbare klikmissie', str_contains($html, 'Klikmissies (1)'), '');

$db->exec("INSERT INTO klikmissies_log (klikmissie_id, login, methode, ip)
            VALUES ({$missieId}, 'Speler', 'zelf', '127.0.0.1')");

$html2 = haal('home.php')['body'];
check('badge verdwijnt zodra de cooldown loopt', !str_contains($html2, 'Klikmissies ('), '');
check('het menu-item zelf blijft gewoon staan', str_contains($html2, '>Klikmissies<'), '');

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");

// --- Klikmissies: "nieuw venster" bepaalt target op Stem-link/-knop --------

kop('klikmissies: nieuw_venster bepaalt of Stem in een nieuw tabblad opent');

$db->exec(
    "INSERT INTO klikmissies (naam, url, heeft_callback, nieuw_venster, cooldown_seconden, actief)
     VALUES
        ('CallbackNieuw', 'https://voorbeeld.test/a?ref={login}', 1, 1, 86400, 1),
        ('CallbackZelfde', 'https://voorbeeld.test/b?ref={login}', 1, 0, 86400, 1),
        ('ZelfNieuw',      'https://voorbeeld.test/c?ref={login}', 0, 1, 86400, 1),
        ('ZelfZelfde',     'https://voorbeeld.test/d?ref={login}', 0, 0, 86400, 1)"
);

$html = haal('klikmissies.php')['body'];

/** Knip het stuk pagina voor deze ene missie eruit, op naam. */
function missieblok(string $html, string $naam): string
{
    $start = strpos($html, '>' . $naam . '<');
    if ($start === false) {
        return '';
    }
    $einde = strpos($html, '</div>', $start);
    return $einde === false ? '' : substr($html, $start, $einde - $start);
}

$callbackNieuw  = missieblok($html, 'CallbackNieuw');
$callbackZelfde = missieblok($html, 'CallbackZelfde');
$zelfNieuw      = missieblok($html, 'ZelfNieuw');
$zelfZelfde     = missieblok($html, 'ZelfZelfde');

check('callback + nieuw venster: link heeft target="_blank"',
    str_contains($callbackNieuw, 'target="_blank"'), $callbackNieuw);
check('callback + zelfde venster: link heeft geen target="_blank"',
    $callbackZelfde !== '' && !str_contains($callbackZelfde, 'target="_blank"'), $callbackZelfde);
check('zelf-bevestigen + nieuw venster: formulier heeft target="_blank"',
    str_contains($zelfNieuw, 'form method="post" target="_blank"'), $zelfNieuw);
check('zelf-bevestigen + zelfde venster: formulier heeft geen target="_blank"',
    $zelfZelfde !== '' && !str_contains($zelfZelfde, 'target="_blank"'), $zelfZelfde);

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");

$db->exec("UPDATE users SET famillie='', famrang=0, level=1 WHERE login='Speler'");

// --- Onderbalk -------------------------------------------------------------

kop('onderbalk');

login('Speler', 'spelerwachtwoord123');
$html = haal('home.php')['body'];
preg_match('#<nav class="onderbalk".*?</nav>#s', $html, $m);
$balk = $m[0] ?? '';

foreach (['home.php', 'bank.php', 'shop.php', 'message.php'] as $doel) {
    check('tab naar ' . $doel, str_contains($balk, $doel));
}

check('menuknop aanwezig', str_contains($balk, 'menu-toggle-onder'));
check('geen emoji meer', !preg_match('/&#\d{4,};/', $balk));

preg_match_all('/<svg class="teken"[^>]*>(.*?)<\/svg>/s', $balk, $svgs);
check('vijf pictogrammen', count($svgs[0]) === 5, count($svgs[0]) . ' gevonden');
check('ze nemen de kleur over',
    substr_count($balk, 'stroke="currentColor"') === count($svgs[0]));

$ongeldig = 0;
foreach ($svgs[0] as $svg) {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$doc->loadXML($svg)) { $ongeldig++; }
    libxml_clear_errors();
}
check('het zijn geldige SVG\'s', $ongeldig === 0, $ongeldig . ' ongeldig');

// --- Gezondheidsbalk -------------------------------------------------------

kop('gezondheidsbalk verandert van kleur');

foreach ([100 => 'vol', 40 => 'middel', 10 => 'laag'] as $hp => $klasse) {
    $db->exec("UPDATE users SET health={$hp} WHERE login='Speler'");
    $html = haal('home.php')['body'];
    $gevonden = preg_match('/balk-health (\w+)/', $html, $m) ? $m[1] : '-';

    check($hp . '% geeft ' . $klasse, $gevonden === $klasse, $gevonden);
}

$db->exec("UPDATE users SET health=100 WHERE login='Speler'");

samenvatting();
