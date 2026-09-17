<?php
/**
 * Advertentiepagina voor spelers zonder premium.
 *
 * Om de zoveel paginabezoeken komt een speler hier terecht. Hij ziet de code
 * die de beheerder heeft ingeplakt, drukt op "ga door" en belandt weer op de
 * pagina waar hij heen wilde.
 *
 * Deze pagina zet zijn eigen Content-Security-Policy, vóór bootstrap.php. Het
 * hele punt is dat er code van een advertentienetwerk draait, en het gewone
 * beleid (`script-src 'self'`) zou die weigeren. Buiten deze pagina blijft dat
 * strikte beleid gewoon staan.
 */

declare(strict_types=1);

define('BV_ADVERTENTIEPAGINA', true);

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Content-Type: text/html; charset=utf-8');

    // Ruimer dan de rest van het spel, maar niet onbeperkt: het formulier kan
    // alleen naar het spel zelf posten, en er kan geen andere pagina in een
    // frame omheen gezet worden.
    header(
        "Content-Security-Policy: "
        . "default-src 'self' https:; "
        . "script-src 'self' 'unsafe-inline' https:; "
        . "style-src 'self' 'unsafe-inline' https:; "
        . "img-src 'self' data: https: http:; "
        . "frame-src https:; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'"
    );
}

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/captcha.php';

$user = require_login();

// Premium? Dan hoort deze pagina er niet te zijn.
if (is_premium($user) || ads_interval() < 1 || ads_html() === '') {
    redirect(advertentie_klaar());
}

$melding = null;

if (is_post()) {
    csrf_check();

    if (ads_captcha() && !captcha_check(post('verify'))) {
        $melding = 'De controlecode klopt niet. Er staat een nieuwe klaar.';
    } else {
        redirect(advertentie_klaar());
    }
}

layout_header('Even wachten');

if ($melding !== null) {
    notice(e($melding), 'fout');
}

panel_open('Advertentie');

echo '<p>Deze pagina houdt het spel gratis. Met een premiumaccount zie je hem niet: '
   . '<a href="' . e(url('premium.php')) . '">bekijk wat dat inhoudt</a>.</p>';

// De code van de beheerder. Bewust niet ge-escaped: het is HTML die hij zelf
// heeft ingeplakt om zijn advertentienetwerk te laten werken. Alleen de
// eigenaar kan dit veld vullen; zie admin/premium.php.
echo '<div class="advertentie">' . ads_html() . '</div>';

echo '<form method="post">' . csrf_field();

if (ads_captcha()) {
    echo '<div class="veldenraster">';
    echo '<span></span>' . captcha_field();
    echo '<span></span><button type="submit">Ga door</button>';
    echo '</div>';
} else {
    echo '<button type="submit">Ga door</button>';
}

echo '</form>';

panel_close();
layout_footer();
