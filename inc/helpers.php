<?php
/**
 * Algemene hulpfuncties: configuratie, escaping, invoer, opmaak, redirects.
 */

declare(strict_types=1);

defined('BV_INC') || exit;

// --- Configuratie --------------------------------------------------------

/**
 * Haal een instelling op met puntnotatie: config('db.host'), config('site.url').
 * Zonder argument krijg je de hele configuratie.
 */
function config(?string $key = null, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require BV_INC . '/config.php';
    }
    if ($key === null) {
        return $cfg;
    }

    $value = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

// --- Escaping ------------------------------------------------------------

/**
 * Escape tekst voor weergave in HTML. Gebruik dit ALTIJD rond alles wat van
 * een speler komt: namen, berichten, profielteksten, familie-info.
 *
 *     echo 'Welkom ' . e($user['login']);
 */
function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Escape voor gebruik binnen een JavaScript-string of data-attribuut. */
function ejs(mixed $value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
}

// --- Invoer --------------------------------------------------------------

/** Waarde uit $_POST als string (getrimd), of de standaardwaarde. */
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? null;
    return is_string($v) ? trim($v) : $default;
}

/** Waarde uit $_GET als string (getrimd), of de standaardwaarde. */
function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? null;
    return is_string($v) ? trim($v) : $default;
}

/** Geheel getal uit $_POST of $_GET, desgewenst begrensd. */
function int_input(string $key, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $raw = $_POST[$key] ?? $_GET[$key] ?? null;
    $val = is_scalar($raw) && preg_match('/^-?\d+$/', trim((string) $raw))
        ? (int) trim((string) $raw)
        : $default;

    if ($min !== null && $val < $min) { $val = $min; }
    if ($max !== null && $val > $max) { $val = $max; }
    return $val;
}

/** Is dit een POST-request? */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * IP-adres van de bezoeker.
 *
 * De site draait achter Cloudflare, dus REMOTE_ADDR is Cloudflare's eigen
 * adres, niet dat van de speler. Cloudflare zet het echte adres altijd zelf
 * in CF-Connecting-IP; die header is door de bezoeker niet te overschrijven
 * zolang de origin niet ook rechtstreeks (buiten Cloudflare om) bereikbaar
 * is. Ontbreekt de header — lokale ontwikkeling, tests — dan valt deze
 * functie terug op REMOTE_ADDR.
 */
function client_ip(): string
{
    $cf = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');

    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        return $cf;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

// --- URL's en redirects ---------------------------------------------------

/** Volledige URL naar een pagina binnen het spel: url('home.php'). */
function url(string $path = ''): string
{
    $base = rtrim((string) config('site.url', ''), '/');
    $path = ltrim($path, '/');

    if (config('mooie_urls')) {
        $path = zonder_extensie($path);
    }

    return $base . '/' . $path;
}

/**
 * Haal ".php" uit een pad, met behoud van de querystring.
 *
 * "members.php?filter=levend" wordt "members?filter=levend". Alleen bestanden
 * die op .php eindigen worden aangeraakt; stylesheets, plaatjes en mappen
 * blijven zoals ze zijn.
 */
function zonder_extensie(string $path): string
{
    $vraag = strpos($path, '?');
    $deel  = $vraag === false ? $path : substr($path, 0, $vraag);
    $rest  = $vraag === false ? '' : substr($path, $vraag);

    if (str_ends_with($deel, '.php')) {
        $deel = substr($deel, 0, -4);
    }

    return $deel . $rest;
}

/**
 * Stuur de bezoeker door en stop.
 *
 * @return never
 */
function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

// --- Opmaak ---------------------------------------------------------------

/**
 * Geldbedrag als "€ 1.234.567".
 *
 * Geeft een echt euroteken terug, geen &euro;-entiteit: dan kan de uitkomst
 * zonder problemen door e() en blijft hij ook bruikbaar in tekst die niet in
 * HTML terechtkomt, zoals e-mail en systeemberichten.
 */
function money(int|float|string $amount): string
{
    return '€ ' . number_format((float) $amount, 0, ',', '.');
}

/** Getal met puntjes als duizendtalscheiding. */
function num(int|float|string $n, int $decimals = 0): string
{
    return number_format((float) $n, $decimals, ',', '.');
}

/**
 * Resterende tijd als "3:42" (m:ss) of "1:02:33" (u:mm:ss).
 * Bij nul of minder wordt "0:00" teruggegeven.
 */
function duration(int $seconds): string
{
    if ($seconds <= 0) {
        return '0:00';
    }
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;

    return $h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s)
        : sprintf('%d:%02d', $m, $s);
}

/** Datum/tijd uit de database in Nederlandse notatie. */
function datetime_nl(?string $sqlDatetime): string
{
    if ($sqlDatetime === null || str_starts_with($sqlDatetime, '0000')) {
        return '-';
    }
    $ts = strtotime($sqlDatetime);
    return $ts === false ? '-' : date('d-m-Y H:i', $ts);
}

/**
 * Idem, maar voor de kolommen die een unix-tijdstip als getal bewaren
 * (`news`.`time`, `poll`.`datum`, `donate`.`time`).
 */
function timestamp_nl(int $ts, bool $metTijd = true): string
{
    return $ts <= 0 ? '-' : date($metTijd ? 'd-m-Y H:i' : 'd-m-Y', $ts);
}

// --- Meldingen tussen requests -------------------------------------------

/**
 * Bewaar een melding die na een redirect getoond wordt.
 * $type is 'ok', 'fout' of 'info'.
 */
function flash(string $message, string $type = 'info'): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $message];
}

/** Haal de bewaarde meldingen op en leeg de wachtrij. */
function flash_take(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

// --- Foutpagina -----------------------------------------------------------

/**
 * Toon een losstaande foutpagina en stop. Wordt gebruikt als de normale
 * layout niet beschikbaar is (geen database, ban, ontbrekende config).
 *
 * $detail is voor de ontwikkelaar: de echte foutmelding, ingeklapt in een
 * detailsvenstertje. Geef dit alleen mee in debug-modus — een bezoeker
 * hoort geen databasedetails te zien.
 *
 * @return never
 */
function fail_page(string $title, string $body, int $status = 503, ?string $detail = null): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }

    $t         = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $siteNaam  = htmlspecialchars((string) config('site.name', 'Black Vendetta'), ENT_QUOTES, 'UTF-8');
    $logoPad   = BV_ROOT . '/assets/img/logo-mark.png';
    $logo      = is_file($logoPad)
        ? '<img src="' . htmlspecialchars(url('assets/img/logo-mark.png'), ENT_QUOTES, 'UTF-8')
          . '" alt="" width="40" height="40">'
        : '';

    $details = $detail === null ? '' : '<details class="detail"><summary>Technische details</summary>'
        . '<pre>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre></details>';

    echo <<<HTML
    <!doctype html>
    <html lang="nl">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>{$t} - {$siteNaam}</title>
      <style>
        body { background:#070b12; color:#dde5f0; font:14px/1.6 system-ui, sans-serif;
               display:grid; place-items:center; min-height:100vh; margin:0; padding:1rem; }
        .box { max-width:34rem; border:1px solid #22304a; background:#0f1724;
               padding:1.5rem 2rem; border-radius:10px; }
        .merk { display:flex; align-items:center; gap:.6rem; margin-bottom:1.1rem; }
        .merk img { border-radius:8px; }
        .merk span { font-weight:600; letter-spacing:.02em; color:#8fa0b8; }
        h1 { color:#3ba2f0; font-size:1.2rem; margin:0 0 .75rem; }
        a { color:#3ba2f0; }
        a:hover { color:#6ec8ff; }
        .detail { margin-top:1.1rem; border-top:1px solid #22304a; padding-top:.75rem; }
        .detail summary { cursor:pointer; color:#8fa0b8; font-size:.8rem; user-select:none; }
        .detail summary:hover { color:#dde5f0; }
        .detail pre { margin:.6rem 0 0; padding:.6rem .75rem; background:#070b12;
                      border:1px solid #22304a; border-radius:6px; color:#e0596b;
                      font-size:.78rem; white-space:pre-wrap; word-break:break-word; }
      </style>
    </head>
    <body><div class="box">
      <div class="merk">{$logo}<span>{$siteNaam}</span></div>
      <h1>{$t}</h1><div>{$body}</div>
      {$details}
    </div></body>
    </html>
    HTML;
    exit;
}
