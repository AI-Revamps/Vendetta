<?php
/**
 * Gedeelde start voor de testscripts.
 *
 * Elk script begint met:
 *
 *     require __DIR__ . '/_start.php';
 *
 * Daarna zijn BV_WORTEL, BV_BASIS en de hulpfuncties hieronder beschikbaar.
 *
 * De tests praten met een draaiend spel over HTTP. Dat is met opzet: zo test je
 * wat een speler werkelijk krijgt, inclusief sessies, tokens en doorverwijzingen.
 * Zie tests/LEESMIJ.md voor hoe je alles opstart.
 */

declare(strict_types=1);

/** De hoofdmap van het spel. */
define('BV_WORTEL', dirname(__DIR__));

/** Waar het spel draait. Overschrijf met de omgevingsvariabele BV_BASIS. */
define('BV_BASIS', rtrim(getenv('BV_BASIS') ?: 'http://127.0.0.1:8149', '/'));

/** De testdatabase. Overschrijf met BV_DB, BV_DBUSER en BV_DBPASS. */
define('BV_DB',     getenv('BV_DB')     ?: 'bv_test');
define('BV_DBUSER', getenv('BV_DBUSER') ?: 'root');
define('BV_DBPASS', getenv('BV_DBPASS') ?: '');

// --- Database ---------------------------------------------------------------

/** Verbinding met de testdatabase. */
function tdb(?string $naam = null): PDO
{
    static $verbindingen = [];
    $naam ??= BV_DB;

    if (!isset($verbindingen[$naam])) {
        $verbindingen[$naam] = new PDO(
            'mysql:host=127.0.0.1;dbname=' . $naam . ';charset=utf8mb4',
            BV_DBUSER,
            BV_DBPASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    return $verbindingen[$naam];
}

/** Verbinding zonder database, om er een aan te maken of weg te gooien. */
function tserver(): PDO
{
    return new PDO('mysql:host=127.0.0.1;charset=utf8mb4', BV_DBUSER, BV_DBPASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/**
 * Maak een verse database met het schema erin.
 *
 * Handig voor tests die niet in de gedeelde testdatabase willen rommelen.
 */
function verse_database(string $naam): PDO
{
    $server = tserver();
    $server->exec('DROP DATABASE IF EXISTS `' . $naam . '`');
    $server->exec('CREATE DATABASE `' . $naam . '` CHARACTER SET utf8mb4 '
        . 'COLLATE utf8mb4_unicode_ci');

    $db = new PDO('mysql:host=127.0.0.1;dbname=' . $naam . ';charset=utf8mb4',
        BV_DBUSER, BV_DBPASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    $db->exec((string) file_get_contents(BV_WORTEL . '/install/schema.sql'));

    return $db;
}

// --- Configuratie tijdelijk aanpassen ---------------------------------------

/**
 * Pas inc/config.php tijdelijk aan en zet hem aan het eind terug.
 *
 * @param array<string,string> $vervangingen zoek => vervang
 */
function config_tijdelijk(array $vervangingen): void
{
    static $origineel = null;

    if ($origineel === null) {
        $origineel = (string) file_get_contents(BV_WORTEL . '/inc/config.php');

        register_shutdown_function(static function () use ($origineel): void {
            file_put_contents(BV_WORTEL . '/inc/config.php', $origineel);
        });
    }

    file_put_contents(BV_WORTEL . '/inc/config.php',
        str_replace(array_keys($vervangingen), array_values($vervangingen), $origineel));
}

// --- HTTP -------------------------------------------------------------------

/**
 * Haal een pagina op, of post ernaartoe.
 *
 * @return array{code:int, body:string, url:string}
 */
/** @param array<string,string> $headers Extra headers, bv. ['CF-Connecting-IP' => '1.2.3.4']. */
function haal(string $pad, ?array $post = null, ?string $jar = null, array $headers = []): array
{
    $jar ??= huidige_jar();

    $ch = curl_init(BV_BASIS . '/' . ltrim($pad, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
    ]);

    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    if ($headers !== []) {
        $regels = [];
        foreach ($headers as $naam => $waarde) {
            $regels[] = $naam . ': ' . $waarde;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $regels);
    }

    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $url  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return ['code' => $code, 'body' => $body, 'url' => $url];
}

/** De koekjespot die haal() standaard gebruikt. */
function huidige_jar(?string $nieuw = null): string
{
    static $jar = null;

    if ($nieuw !== null) {
        $jar = $nieuw;
    }
    if ($jar === null) {
        $jar = tempnam(sys_get_temp_dir(), 'bv');
        register_shutdown_function(static function () use (&$jar): void { @unlink($jar); });
    }

    return $jar;
}

/** Begin met een schone sessie. */
function nieuwe_sessie(): string
{
    return huidige_jar(tempnam(sys_get_temp_dir(), 'bv'));
}

/** Het CSRF-token uit een pagina. */
function tok(string $html): string
{
    return preg_match('/name="_token" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
}

/** De uitkomst van een rekensom-captcha, als die op de pagina staat. */
function som(string $html): string
{
    return preg_match('/Hoeveel is (\d+) \+ (\d+)/', $html, $m)
        ? (string) ((int) $m[1] + (int) $m[2])
        : '';
}

/**
 * Haal de pagina op, en post er dan naartoe met een vers token en, als de
 * pagina erom vraagt, het antwoord op de rekensom.
 *
 * @param array<string,string> $headers Extra headers, op beide requests.
 */
function doe(string $pad, array $velden, ?string $jar = null, array $headers = []): array
{
    $eerst = haal($pad, null, $jar, $headers);
    $mee   = ['_token' => tok($eerst['body'])] + $velden;

    if (str_contains($eerst['body'], 'Hoeveel is')) {
        $mee['verify'] = som($eerst['body']);
    }

    return haal($pad, $mee, $jar, $headers);
}

/** Log in en geef de koekjespot terug. */
function login(string $naam, string $wachtwoord): string
{
    $jar = nieuwe_sessie();
    $h   = haal('login.php', null, $jar);

    haal('login.php', ['_token' => tok($h['body']), 'login' => $naam,
        'password' => $wachtwoord], $jar);

    return $jar;
}

// --- Uitlezen ---------------------------------------------------------------

/** De melding boven aan de pagina, als "[ok] tekst" of "[fout] tekst". */
function melding(string $html): string
{
    if (preg_match('/melding melding-(\w+)">(.*?)<\/div>/s', $html, $m)) {
        return '[' . $m[1] . '] '
             . mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($m[2]))), 0, 250);
    }

    if (preg_match('/Databasefout.*?<pre>(.*?)<\/pre>/s', $html, $m)) {
        return 'DATABASEFOUT: ' . mb_substr(trim(strip_tags($m[1])), 0, 200);
    }

    return '(geen melding)';
}

/** Totaal geld in het spel; handig om vóór en ná een handeling te vergelijken. */
function totaal_geld(?PDO $db = null): int
{
    return (int) ($db ?? tdb())
        ->query('SELECT COALESCE(SUM(zak) + SUM(bank), 0) FROM users')
        ->fetchColumn();
}

/**
 * Alle paginabestanden: de hoofdmap en submappen zoals admin/. Bestanden in
 * de hoofdmap komen terug als kale naam ("home.php"), bestanden in een
 * submap met hun relatieve pad ("admin/ban.php") — zodat haal() en mooi()
 * ze rechtstreeks kunnen opvragen.
 */
function alle_paginas(): array
{
    $hoofdmap = array_map('basename', glob(BV_WORTEL . '/*.php') ?: []);

    $admin = array_map(
        static fn (string $pad): string => 'admin/' . basename($pad),
        glob(BV_WORTEL . '/admin/*.php') ?: []
    );

    return array_merge($hoofdmap, $admin);
}

// --- Uitvoer ----------------------------------------------------------------

function kop(string $tekst): void
{
    echo "\n=== {$tekst} ===\n";
}

/**
 * Meld een controle. Houdt bij hoeveel er misgingen; zie mislukt().
 */
function check(string $wat, bool $goed, string $extra = ''): void
{
    static $aantal = 0;

    if (!$goed) {
        $aantal++;
    }

    mislukt($goed ? null : $aantal);

    printf("  %-46s %s%s\n", $wat, $goed ? 'ja' : 'NEE', $extra !== '' ? '  ' . $extra : '');
}

/** Hoeveel controles er tot nu toe misgingen. */
function mislukt(?int $zet = null): int
{
    static $aantal = 0;

    if ($zet !== null) {
        $aantal = $zet;
    }

    return $aantal;
}

/**
 * Sluit het script af met een samenvatting en een exitcode.
 * 0 als alles goed ging, 1 als er iets misging.
 */
function samenvatting(): void
{
    $fout = mislukt();

    echo "\n";
    echo $fout === 0
        ? "Alles goed.\n"
        : "{$fout} controle(s) misgegaan.\n";

    exit($fout === 0 ? 0 : 1);
}
