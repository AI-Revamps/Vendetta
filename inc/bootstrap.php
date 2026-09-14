<?php
/**
 * Startpunt van elk verzoek.
 *
 * Zet bovenaan iedere pagina precies één regel:
 *
 *     require __DIR__ . '/inc/bootstrap.php';
 *
 * Daarna zijn config(), db(), q(), e(), current_user() en de layout-functies
 * beschikbaar en is de sessie gestart.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80100) {
    header('Content-Type: text/html; charset=utf-8');
    exit('<h1>PHP te oud</h1><p>Dit spel heeft PHP 8.1 of nieuwer nodig. '
       . 'Deze server draait ' . PHP_VERSION . '. In het configuratiescherm van je '
       . 'webhost kun je meestal een nieuwere PHP-versie kiezen.</p>');
}

define('BV_ROOT', dirname(__DIR__));
define('BV_INC', __DIR__);

require BV_INC . '/helpers.php';

// --- Nog niet geïnstalleerd? --------------------------------------------
if (!is_file(BV_INC . '/config.php')) {
    if (is_dir(BV_ROOT . '/install')) {
        header('Location: install/');
        exit;
    }
    fail_page(
        'Niet geïnstalleerd',
        'Er is nog geen <code>inc/config.php</code>. Kopieer <code>inc/config.sample.php</code> '
        . 'naar <code>inc/config.php</code> en vul je databasegegevens in.'
    );
}

// --- Foutafhandeling -----------------------------------------------------
$bv_debug = (bool) config('debug', false);
error_reporting(E_ALL);
ini_set('display_errors', $bv_debug ? '1' : '0');
ini_set('log_errors', '1');

// Zet onopgemerkte fouten om in exceptions, zodat ze niet half uitgevoerd
// werk achterlaten maar netjes in het log belanden.
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($str, 0, $no, $file, $line);
});

set_exception_handler(static function (Throwable $e): void {
    error_log('[fout] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (config('debug')) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Fout</h1><pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
        exit;
    }
    fail_page('Er ging iets mis',
        'Er trad een onverwachte fout op. De beheerder is op de hoogte gesteld. '
        . '<a href="' . htmlspecialchars(url('home.php'), ENT_QUOTES, 'UTF-8') . '">Terug naar het spel</a>.',
        500);
});

date_default_timezone_set((string) config('site.timezone', 'Europe/Amsterdam'));
mb_internal_encoding('UTF-8');

// --- Onderdelen laden -----------------------------------------------------
require BV_INC . '/db.php';
require BV_INC . '/auth.php';
require BV_INC . '/game.php';
require BV_INC . '/mail.php';
require BV_INC . '/cron.php';
require BV_INC . '/layout.php';
require BV_INC . '/premium.php';

// --- Beveiligingsheaders ---------------------------------------------------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Content-Type: text/html; charset=utf-8');

    // Tweede slot op XSS. inc/opmaak.php escapet alles voordat het de opmaak
    // terugzet, maar mocht daar ooit iets doorheen glippen, dan weigert de
    // browser alsnog om vreemd script te draaien.
    //
    // img-src staat wél extern toe: spelers zetten zelf een profielplaatje in,
    // en dat mag overal vandaan komen. Een plaatje kan geen code uitvoeren.
    //
    // Op advertentie.php geldt een ruimer beleid: daar staat code van een
    // advertentienetwerk die anders geweigerd wordt. Die pagina zet zijn eigen
    // header vóórdat bootstrap.php geladen is, en dan slaan we deze over.
    if (!defined('BV_ADVERTENTIEPAGINA')) {
        // klikmissies.php stuurt de "Stem"-knop naar zichzelf (form-action
        // 'self' staat dat toe) en verwijst pas daarna, server-side, door
        // naar de externe stemsite. Chrome en andere moderne browsers passen
        // form-action ook toe op die tweede stap, dus zonder deze uitzondering
        // blokkeert de browser de doorverwijzing en blijft de speler op de
        // eigen pagina hangen. Die pagina zet BV_EXTERNE_DOORVERWIJZING vóórdat
        // bootstrap.php geladen is, net als advertentie.php hierboven.
        $formAction = defined('BV_EXTERNE_DOORVERWIJZING') ? "'self' https: http:" : "'self'";

        header(
            "Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: https: http:; "
            . "form-action {$formAction}; "
            . "frame-ancestors 'self'; "
            . "base-uri 'self'; "
            . "object-src 'none'"
        );
    }
}

session_boot();

// --- Per verzoek -----------------------------------------------------------
ban_check();

if (($user = current_user()) !== null) {
    // Laatst gezien bijwerken, maar hooguit eens per minuut om schrijfacties
    // op drukke momenten te beperken.
    if (time() - (int) ($_SESSION['_seen'] ?? 0) > 60) {
        $_SESSION['_seen'] = time();
        q('UPDATE `users` SET `online` = NOW() WHERE `id` = ?', [$user['id']]);
    }

    // Promotiegeld van de familie uitkeren bij een nieuwe rang. Doet alleen
    // werk als de rang werkelijk gestegen is.
    if (rank_index((int) $user['xp']) > (int) $user['laatste_rang']) {
        require_once BV_INC . '/familie.php';
        fam_promotie_uitbetalen($user);
    }
}

// cron.php roept de taken zelf aan en meldt wat er gedraaid heeft. Zou de
// bootstrap ze daar ook uitvoeren, dan vond cron.php niets meer en leek het
// alsof de cronjob niets deed.
if (!defined('BV_CRON_ENDPOINT')) {
    cron_run();
}

// Advertentie tonen aan wie geen premium heeft. Staat bewust helemaal
// onderaan: pas nadat de sessie, de ban-controle en de cron gedaan zijn.
if (!defined('BV_ADVERTENTIEPAGINA') && !defined('BV_CRON_ENDPOINT')) {
    advertentie_check($user ?? null);
}
