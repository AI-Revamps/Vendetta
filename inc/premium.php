<?php
/**
 * Diamanten, premium en de advertentiepagina.
 *
 * Het model in het kort:
 *
 *  - **Diamanten** zijn de betaalde munt. Je vindt ze bij toeval tijdens een
 *    misdaad (standaard één op de vijfhonderd), of je krijgt ze van het beheer.
 *  - **Premium** duurt veertien dagen en is er in één soort. Je sluit het af
 *    met diamanten, of met een code die je buiten het spel gekocht hebt.
 *  - **Wie geen premium heeft** krijgt elke zoveel paginabezoeken een
 *    advertentiepagina te zien, met een knop om door te gaan.
 *
 * De aantallen en teksten staan in de tabel `instellingen`, zodat de beheerder
 * ze kan aanpassen zonder in de code te hoeven.
 */

declare(strict_types=1);

defined('BV_INC') || exit;

// Standaardwaarden. De beheerder kan ze omzetten op adm-premium.php.
const DIAMANT_KANS_STANDAARD   = 500;   // één op de zoveel
const PREMIUM_DAGEN            = 14;
const PREMIUM_PRIJS_STANDAARD  = 250;   // diamanten
const ADS_INTERVAL_STANDAARD   = 25;    // paginabezoeken tussen twee advertenties

/** Pagina's die nooit onderbroken mogen worden door een advertentie. */
const ADS_OVERSLAAN = [
    'advertentie.php',   // anders kom je er nooit meer vanaf
    'img.php',           // het captchaplaatje
    'logout.php',
    'cron.php',
    'login.php',
    'register.php',
    'klikmissies-callback.php', // machine-naar-machine, mag nooit een advertentie tonen
];

// --- Instellingen -----------------------------------------------------------

/** Eén op hoeveel is de kans op een diamant? */
function diamant_kans(): int
{
    return max(1, (int) instelling('diamant_kans', (string) DIAMANT_KANS_STANDAARD));
}

/** Wat premium kost in diamanten. */
function premium_prijs(): int
{
    return max(1, (int) instelling('premium_prijs', (string) PREMIUM_PRIJS_STANDAARD));
}

/** Om de hoeveel paginabezoeken komt er een advertentie? 0 zet ze helemaal uit. */
function ads_interval(): int
{
    return max(0, (int) instelling('ads_interval', (string) ADS_INTERVAL_STANDAARD));
}

/** Moet er een controlecode op de advertentiepagina staan? */
function ads_captcha(): bool
{
    return instelling('ads_captcha', '0') === '1';
}

/** De advertentiecode die de beheerder heeft ingeplakt. */
function ads_html(): string
{
    return instelling('ads_html', '');
}

/**
 * Moet dezelfde advertentiecode ook op de publieke voorpagina te zien zijn?
 *
 * Los van `ads_interval()`: die telt paginabezoeken van een ingelogde
 * speler, en vóór het inloggen bestaat die teller niet. Sommige
 * advertentienetwerken eisen dat hun code ook zichtbaar is voor bezoekers
 * die niet ingelogd zijn, voordat ze een site goedkeuren.
 */
function ads_outgame(): bool
{
    return instelling('ads_outgame', '0') === '1';
}

// --- Premium ----------------------------------------------------------------

/** Heeft deze speler op dit moment premium? */
function is_premium(?array $user): bool
{
    if ($user === null || ($user['premium_tot'] ?? null) === null) {
        return false;
    }

    return strtotime((string) $user['premium_tot']) > time();
}

/** Hoeveel seconden premium er nog over is, of 0. */
function premium_resterend(?array $user): int
{
    if (!is_premium($user)) {
        return 0;
    }

    return max(0, strtotime((string) $user['premium_tot']) - time());
}

/**
 * Zet premium aan of verleng het.
 *
 * Loopt er nog premium, dan worden de dagen erbij opgeteld in plaats van
 * overschreven. Zo verlies je niets door te vroeg te verlengen.
 */
function premium_verlengen(int $userId, int $dagen = PREMIUM_DAGEN): void
{
    q(
        'UPDATE `users`
            SET `premium_tot` = DATE_ADD(
                    GREATEST(COALESCE(`premium_tot`, NOW()), NOW()),
                    INTERVAL ? DAY)
          WHERE `id` = ?',
        [$dagen, $userId]
    );
}

// --- Diamanten --------------------------------------------------------------

/** Schrijf diamanten bij. */
function diamanten_bijschrijven(int $userId, int $aantal): void
{
    if ($aantal < 1) {
        return;
    }

    q('UPDATE `users` SET `diamanten` = `diamanten` + ? WHERE `id` = ?', [$aantal, $userId]);
}

/**
 * Boek diamanten af, maar alleen als ze er zijn.
 *
 * De voorwaarde zit in de query zelf, zodat twee gelijktijdige aankopen niet
 * allebei kunnen slagen bij hetzelfde saldo.
 */
function diamanten_afboeken(int $userId, int $aantal): bool
{
    if ($aantal < 1) {
        return false;
    }

    return q_count(
        'UPDATE `users` SET `diamanten` = `diamanten` - ? WHERE `id` = ? AND `diamanten` >= ?',
        [$aantal, $userId, $aantal]
    ) === 1;
}

/**
 * Rol met de dobbelsteen: heeft deze speler een diamant gevonden?
 *
 * Roep dit aan na een geslaagde actie waar iets te vinden valt. Geeft het
 * aantal gevonden diamanten terug, of 0.
 *
 * @param string $waar Korte omschrijving voor het logboek ("misdaad", "autodiefstal").
 */
function diamant_vondst(array $user, string $waar): int
{
    if (random_int(1, diamant_kans()) !== 1) {
        return 0;
    }

    $aantal = 1;

    q(
        'UPDATE `users`
            SET `diamanten` = `diamanten` + ?,
                `diamanten_gevonden` = `diamanten_gevonden` + ?
          WHERE `id` = ?',
        [$aantal, $aantal, $user['id']]
    );

    log_action((string) $user['login'], 'diamant', 'Gevonden bij ' . $waar, $aantal);

    return $aantal;
}

/** De zin die je te zien krijgt als je er een vindt. */
function diamant_melding(int $aantal): string
{
    return $aantal === 1
        ? 'Tussen de buit lag een diamant.'
        : 'Tussen de buit lagen ' . num($aantal) . ' diamanten.';
}

// --- Advertenties -----------------------------------------------------------

/**
 * Tel dit paginabezoek mee en stuur zo nodig door naar de advertentiepagina.
 *
 * Wordt aangeroepen vanuit inc/bootstrap.php, na het inloggen. Onderbreekt
 * nooit een POST: dan zou de handeling van de speler verloren gaan.
 */
function advertentie_check(?array $user): void
{
    $interval = ads_interval();

    if ($interval < 1 || is_premium($user) || ads_html() === '') {
        return;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    $pagina = basename((string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH));

    if (in_array($pagina, ADS_OVERSLAAN, true)) {
        return;
    }

    $_SESSION['_adteller'] = ($_SESSION['_adteller'] ?? 0) + 1;

    if ($_SESSION['_adteller'] < $interval) {
        return;
    }

    // Onthouden waar de speler heen wilde, zodat hij daar na de advertentie
    // weer uitkomt. Alleen het pad plus querystring: nooit een adres van
    // buitenaf, want dan is dit een open doorstuurluik.
    $_SESSION['_adterug'] = $pagina
        . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');

    redirect('advertentie.php');
}

/**
 * De advertentie is bekeken: teller op nul en zeg waar de speler heen moet.
 *
 * @return string Een pad binnen het spel.
 */
function advertentie_klaar(): string
{
    $_SESSION['_adteller'] = 0;
    $terug = (string) ($_SESSION['_adterug'] ?? 'home.php');
    unset($_SESSION['_adterug']);

    // Alleen een eigen bestandsnaam met eventueel een querystring. Alles wat
    // daar niet aan voldoet gaat naar de startpagina.
    if (!preg_match('#^[a-z0-9_\-]+\.php(\?[^\s]*)?$#i', $terug)) {
        return 'home.php';
    }

    return $terug;
}
