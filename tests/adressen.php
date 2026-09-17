<?php
/**
 * Mooie adressen: werkt het spel zonder .php in de adresbalk, en verwijst het
 * dan ook nergens meer naar een adres mét .php?
 *
 *     php tests/adressen.php
 *
 * Deze test start zijn eigen server met tests/router.php, want de gedeelde
 * testserver draait zonder router. Apache doet dit op de webhost met de regel
 * in .htaccess; de router bootst precies die regel na.
 */

declare(strict_types=1);

require __DIR__ . '/_start.php';

const POORT = 8157;
const BASIS = 'http://127.0.0.1:' . POORT;

// --- Eigen server met mooie adressen aan -----------------------------------

config_tijdelijk([
    "'debug'     => true"                 => "'debug'     => true,\n    'mooie_urls' => true",
    "'url'            => '" . BV_BASIS . "'" => "'url'            => '" . BASIS . "'",
]);

$php = PHP_BINARY;
$log = tempnam(sys_get_temp_dir(), 'srv');

$server = proc_open(
    sprintf('"%s" -S 127.0.0.1:%d -t "%s" "%s"', $php, POORT, BV_WORTEL, __DIR__ . '/router.php'),
    [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $pipes
);

register_shutdown_function(static function () use ($server, $log): void {
    if (is_resource($server)) {
        $info = proc_get_status($server);
        // proc_terminate raakt op Windows alleen de cmd-wikkel, niet php zelf.
        exec('taskkill /F /T /PID ' . $info['pid'] . ' 2>&1', $uit);
        proc_close($server);
    }
    @unlink($log);
});

// Wachten tot hij luistert.
for ($i = 0; $i < 50; $i++) {
    $sok = @fsockopen('127.0.0.1', POORT, $e, $s, 0.2);
    if ($sok) { fclose($sok); break; }
    usleep(200_000);
}

/** Haal op bij de eigen server. */
function mooi(string $pad, ?array $post = null): array
{
    $ch = curl_init(BASIS . '/' . ltrim($pad, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => huidige_jar(),
        CURLOPT_COOKIEFILE     => huidige_jar(),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => $body];
}

// --- Werkt het überhaupt? --------------------------------------------------

kop('een adres zonder .php komt op de juiste pagina uit');

nieuwe_sessie();
check('de server luistert', mooi('index')['code'] === 200);

$db = tdb();
$db->exec("UPDATE users SET level=1000, famillie='De Testers', famrang=5 WHERE login='Speler'");

$h = mooi('login');
mooi('login', ['_token' => tok($h['body']), 'login' => 'Speler',
    'password' => 'spelerwachtwoord123']);

check('inloggen lukt op /login', str_contains(mooi('home')['body'], 'Speler'));
check('/home geeft home.php', str_contains(mooi('home')['body'], 'class="statuspaneel"'));
check('/home/ met slash ook', mooi('home/')['code'] === 200);
check('/home.php blijft werken', mooi('home.php')['code'] === 200);
check('/bestaat-niet geeft 404', mooi('bestaat-niet')['code'] === 404);
check('een echte map wordt niet herschreven', mooi('assets/css/style.css')['code'] === 200);

// --- Alle pagina's zonder .php ---------------------------------------------

kop('alle pagina\'s zijn zonder .php te bereiken');

$overslaan = ['cron.php', 'logout.php', 'img.php'];
$paginas   = [];

foreach (alle_paginas() as $naam) {
    if (!in_array($naam, $overslaan, true)) {
        $paginas[] = substr($naam, 0, -4);
    }
}

$stuk = [];
$vuil = [];
$links = 0;

foreach ($paginas as $pagina) {
    $r = mooi($pagina);

    if ($r['code'] !== 200 || str_contains($r['body'], 'Fatal error')) {
        $stuk[] = $pagina . ' (HTTP ' . $r['code'] . ')';
        continue;
    }

    // Elke verwijzing naar een eigen adres.
    preg_match_all('#(?:href|action|src)="([^"]*)"#i', $r['body'], $m);

    foreach ($m[1] as $link) {
        if (!str_starts_with($link, BASIS) && !str_starts_with($link, '/')) {
            continue;   // extern, mailto, anker of data-uri
        }

        $links++;

        // Echte bestanden houden hun naam; alleen pagina's worden herschreven.
        if (preg_match('/\.php($|[?#])/', $link)) {
            $vuil[] = $pagina . ': ' . $link;
        }
    }
}

check(count($paginas) . ' pagina\'s laden schoon', $stuk === [],
    implode(', ', array_slice($stuk, 0, 5)));
check($links . ' verwijzingen, geen enkele met .php', $vuil === [],
    implode(' | ', array_slice($vuil, 0, 5)));

// --- Leiden de menulinks ergens heen? --------------------------------------

kop('de menulinks werken echt');

$body = mooi('home')['body'];
preg_match('#<nav class="sidebar".*?</nav>#s', $body, $m);
preg_match_all('#href="([^"]+)"#', $m[0] ?? '', $links);

$kapot  = [];
$getest = 0;

foreach (array_unique($links[1]) as $link) {
    $pad = ltrim((string) parse_url($link, PHP_URL_PATH), '/');
    $q   = (string) parse_url($link, PHP_URL_QUERY);

    $r = mooi($pad . ($q !== '' ? '?' . $q : ''));
    $getest++;

    if ($r['code'] !== 200) {
        $kapot[] = $link . ' -> ' . $r['code'];
    }
}

check($getest . ' menulinks komen ergens uit', $kapot === [],
    implode(', ', array_slice($kapot, 0, 5)));

// --- En met mooie adressen uit? --------------------------------------------

kop('met mooie_urls uit blijft alles gewoon werken');

check('de gedeelde server draait nog steeds op .php',
    haal('home.php')['code'] === 200);

$db->exec("UPDATE users SET level=1, famillie='', famrang=0 WHERE login='Speler'");

samenvatting();
