<?php
/**
 * Premium, diamanten en advertenties instellen.
 *
 * De pagina is voor admins en hoger. De advertentie- en balansinstellingen
 * eronder zijn dat niet: het advertentieveld gaat ongefilterd naar de browser
 * — dat moet ook, anders werkt er geen enkel advertentienetwerk — maar het
 * betekent wél dat wie hier bij kan, script kan laten draaien bij elke speler
 * die de advertentiepagina ziet. Dat is niets voor iemand onder eigenaarsniveau,
 * dus die twee onderdelen blijven hieronder apart met `LEVEL_OWNER` afgeschermd.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user    = require_level(beheerpaginas()['adm-premium.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'advertentie' => advertentie_opslaan($user),
            'balans'      => balans_opslaan($user),
            'code'        => code_maken($user, post('voor')),
            'diamanten'   => diamanten_geven($user, post('speler'), int_input('aantal')),
            'dagen'       => premiumdagen_geven($user, post('speler2'), int_input('dagen')),
            default       => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Beheer');
beheer_menu($user, 'adm-premium.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

// --- Advertenties -------------------------------------------------------
// Alleen de eigenaar: het codeveld gaat ongefilterd naar de browser van elke
// speler.

if ((int) $user['level'] >= LEVEL_OWNER) {
    panel_open('Advertentie');

    echo '<p>Plak hier de code van je advertentienetwerk. Wat je invult komt '
       . '<strong>ongefilterd</strong> op de pagina te staan — dat moet, anders werkt de '
       . 'code van het netwerk niet. Plak dus alleen iets waarvan je weet waar het '
       . 'vandaan komt.</p>';

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="advertentie">';
    echo '<div class="veldenraster">';

    echo '<label for="html">Advertentiecode</label>';
    echo '<textarea id="html" name="html" rows="10" spellcheck="false">'
       . e(ads_html()) . '</textarea>';

    echo '<label for="interval">Om de hoeveel pagina\'s</label>';
    echo '<input id="interval" name="interval" type="number" min="0" max="1000" step="1" value="'
       . ads_interval() . '">';

    echo '<label for="captcha">Controlecode erbij</label>';
    echo '<span><label><input type="checkbox" id="captcha" name="captcha" value="1"'
       . (ads_captcha() ? ' checked' : '') . '> Speler moet een code overtypen voordat '
       . 'hij door kan</label></span>';

    echo '<label for="outgame">Ook op de voorpagina</label>';
    echo '<span><label><input type="checkbox" id="outgame" name="outgame" value="1"'
       . (ads_outgame() ? ' checked' : '') . '> Dezelfde advertentiecode ook tonen aan '
       . 'bezoekers die niet ingelogd zijn</label></span>';

    echo '<span></span><button type="submit">Opslaan</button>';
    echo '</div></form>';

    echo '<p class="uitleg">Op 0 zetten schakelt de advertentiepagina helemaal uit. Is het '
       . 'codeveld leeg, dan gebeurt er ook niets — spelers worden dan nooit onderbroken. '
       . 'Premiumspelers krijgen de pagina nooit te zien. "Ook op de voorpagina" staat los van '
       . 'het aantal pagina\'s hierboven: die teller bestaat pas na het inloggen.</p>';

    if (ads_html() !== '') {
        echo '<p><a class="knop" href="' . e(url('advertentie.php')) . '">Bekijk de pagina</a> '
           . '<small>(alleen zichtbaar als je zelf geen premium hebt)</small></p>';
    }

    panel_close();

    // --- Balans -----------------------------------------------------------
    panel_open('Diamanten en prijs');

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="balans">';
    echo '<div class="veldenraster">';

    echo '<label for="kans">Vindkans: één op</label>';
    echo '<input id="kans" name="kans" type="number" min="1" max="1000000" step="1" value="'
       . diamant_kans() . '">';

    echo '<label for="prijs">Premium kost (diamanten)</label>';
    echo '<input id="prijs" name="prijs" type="number" min="1" max="1000000" step="1" value="'
       . premium_prijs() . '">';

    echo '<label for="kofi">Koopadres (Ko-fi of iets anders)</label>';
    echo '<input id="kofi" name="kofi" maxlength="255" value="'
       . e(instelling('kofi_url', '')) . '">';

    echo '<span></span><button type="submit">Opslaan</button>';
    echo '</div></form>';

    echo '<p class="uitleg">De vindkans geldt per geslaagde misdaad. Op één op '
       . num(diamant_kans()) . ' heeft een speler die vijftig misdaden per dag pleegt er '
       . 'gemiddeld ' . num((int) round(diamant_kans() / 50)) . ' dagen voor nodig om er één '
       . 'te vinden, en ' . num((int) round(premium_prijs() * diamant_kans() / 50)) . ' dagen '
       . 'om premium bij elkaar te sparen.</p>';

    panel_close();
}

// --- Codes ------------------------------------------------------------------

$open = q_all('SELECT * FROM `donate` ORDER BY `id` DESC LIMIT 25');

panel_open('Premiumcodes');

echo '<p>Iemand heeft buiten het spel om betaald? Maak hier een code aan. Vul je een '
   . 'naam in, dan werkt de code alleen voor die speler en krijgt hij hem meteen als '
   . 'bericht. Laat je het leeg, dan werkt hij voor de eerste die hem invoert.</p>';

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="code">';
echo '<div class="veldenraster">';
echo '<label for="voor">Voor speler (mag leeg)</label>';
echo '<input id="voor" name="voor" maxlength="16">';
echo '<span></span><button type="submit">Code aanmaken</button>';
echo '</div></form>';

if ($open !== []) {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Code</th><th>Op naam van</th></tr></thead><tbody>';
    foreach ($open as $rij) {
        echo '<tr><td><code>' . e((string) $rij['code']) . '</code></td>'
           . '<td>' . ((string) $rij['door'] === ''
                ? '<em>iedereen</em>' : e((string) $rij['door'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="uitleg">Deze codes zijn nog niet ingewisseld. Zodra iemand hem '
       . 'gebruikt verdwijnt hij uit de lijst.</p>';
}

panel_close();

// --- Diamanten geven --------------------------------------------------------

panel_open('Diamanten toekennen');

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="diamanten">';
echo '<div class="veldenraster">';
echo '<label for="speler">Speler</label>';
echo '<input id="speler" name="speler" maxlength="16" required>';
echo '<label for="aantal">Aantal</label>';
echo '<input id="aantal" name="aantal" type="number" min="1" max="100000" step="1" required>';
echo '<span></span><button type="submit">Toekennen</button>';
echo '</div></form>';

panel_close();

// --- Premiumdagen geven -------------------------------------------------

panel_open('Premium dagen toekennen');

echo '<p>Rechtstreeks dagen premium bijschrijven, zonder code — bijvoorbeeld als '
   . 'goedmaker. Loopt er nog premium, dan komen de dagen erbij.</p>';

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="dagen">';
echo '<div class="veldenraster">';
echo '<label for="speler2">Speler</label>';
echo '<input id="speler2" name="speler2" maxlength="16" required>';
echo '<label for="dagen">Aantal dagen</label>';
echo '<input id="dagen" name="dagen" type="number" min="1" max="365" step="1" required>';
echo '<span></span><button type="submit">Toekennen</button>';
echo '</div></form>';

panel_close();

// --- Overzicht --------------------------------------------------------------

$cijfers = q_row(
    'SELECT COUNT(*)                                        AS `spelers`,
            SUM(`premium_tot` > NOW())                      AS `premium`,
            SUM(`diamanten`)                                AS `diamanten`,
            SUM(`diamanten_gevonden`)                       AS `gevonden`
       FROM `users` WHERE `activated` = 1'
) ?? [];

panel_open('Hoe het ervoor staat');

echo '<div class="tabelwikkel"><table class="lijst"><tbody>';
echo '<tr><th>Spelers met premium</th><td class="getal">'
   . num((int) ($cijfers['premium'] ?? 0)) . ' van ' . num((int) ($cijfers['spelers'] ?? 0))
   . '</td></tr>';
echo '<tr><th>Diamanten in omloop</th><td class="getal">'
   . num((int) ($cijfers['diamanten'] ?? 0)) . '</td></tr>';
echo '<tr><th>Ooit gevonden</th><td class="getal">'
   . num((int) ($cijfers['gevonden'] ?? 0)) . '</td></tr>';
echo '</tbody></table></div>';

panel_close();

beheer_logregels('premium', 20);

layout_footer();

// ==========================================================================

/** @throws SpelFout */
function advertentie_opslaan(array $user): string
{
    if ((int) $user['level'] < LEVEL_OWNER) {
        throw new SpelFout('Alleen de eigenaar mag de advertentiecode aanpassen.');
    }

    $html     = trim(post('html'));
    $interval = int_input('interval', -1);

    if ($interval < 0 || $interval > 1000) {
        throw new SpelFout('Het aantal pagina\'s moet tussen 0 en 1000 liggen.');
    }
    if (mb_strlen($html) > 20000) {
        throw new SpelFout('De advertentiecode mag hoogstens 20.000 tekens lang zijn.');
    }

    instelling_zetten('ads_html', $html);
    instelling_zetten('ads_interval', (string) $interval);
    instelling_zetten('ads_captcha', post('captcha') === '1' ? '1' : '0');
    instelling_zetten('ads_outgame', post('outgame') === '1' ? '1' : '0');

    log_action((string) $user['login'], 'premium',
        'Advertentie-instellingen gewijzigd (interval ' . $interval . ')');

    return $interval === 0 || $html === ''
        ? 'Opgeslagen. Er worden op dit moment geen advertenties getoond.'
        : 'Opgeslagen. Spelers zonder premium zien elke ' . num($interval)
          . ' pagina\'s een advertentie.';
}

/** @throws SpelFout */
function balans_opslaan(array $user): string
{
    if ((int) $user['level'] < LEVEL_OWNER) {
        throw new SpelFout('Alleen de eigenaar mag deze instellingen aanpassen.');
    }

    $kans  = int_input('kans', 0);
    $prijs = int_input('prijs', 0);
    $kofi  = trim(post('kofi'));

    if ($kans < 1 || $kans > 1_000_000) {
        throw new SpelFout('De vindkans moet tussen 1 en 1.000.000 liggen.');
    }
    if ($prijs < 1 || $prijs > 1_000_000) {
        throw new SpelFout('De prijs moet tussen 1 en 1.000.000 diamanten liggen.');
    }
    if ($kofi !== '' && !preg_match('#^https://#i', $kofi)) {
        throw new SpelFout('Het koopadres moet met https:// beginnen.');
    }

    instelling_zetten('diamant_kans', (string) $kans);
    instelling_zetten('premium_prijs', (string) $prijs);
    instelling_zetten('kofi_url', mb_substr($kofi, 0, 255));

    log_action((string) $user['login'], 'premium',
        'Vindkans 1 op ' . $kans . ', prijs ' . $prijs . ' diamanten');

    return 'Opgeslagen.';
}

/** @throws SpelFout */
function code_maken(array $user, string $voor): string
{
    $voor = trim($voor);

    if ($voor !== '') {
        $ontvanger = q_val('SELECT `login` FROM `users` WHERE `login` = ?', [$voor]);

        if ($ontvanger === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }

        $voor = (string) $ontvanger;
    }

    $code = premium_code_maken($voor);

    if ($voor !== '') {
        notify($voor, 'Je premiumcode',
            "Bedankt voor je steun.\n\nJe code is: " . $code . "\n\n"
            . 'Wissel hem in op de premiumpagina. Hij is goed voor '
            . PREMIUM_DAGEN . ' dagen premium.');
    }

    log_action((string) $user['login'], 'premium',
        'Code aangemaakt' . ($voor !== '' ? ' voor ' . $voor : ' (op naam van niemand)'),
        0, $voor);

    return 'Code ' . $code . ' aangemaakt'
         . ($voor !== '' ? ' voor ' . $voor . '. Hij heeft een bericht gekregen.' : '.');
}

/**
 * Maak een unieke premiumcode en sla hem op.
 *
 * Dit is ook het aanknopingspunt voor een echte betaalprovider: roep deze
 * functie aan zodra een betaling bevestigd is, en stuur de code naar de koper.
 */
function premium_code_maken(string $voor): string
{
    // Zonder tekens die op elkaar lijken, zodat overtypen makkelijk blijft.
    $tekens = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    do {
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $tekens[random_int(0, strlen($tekens) - 1)];
        }
        $bestaat = (int) q_val('SELECT COUNT(*) FROM `donate` WHERE `code` = ?', [$code], 0);
    } while ($bestaat > 0);

    q('INSERT INTO `donate` (`door`, `code`, `status`) VALUES (?, ?, 0)', [$voor, $code]);

    return $code;
}

/** @throws SpelFout */
function diamanten_geven(array $user, string $naam, int $aantal): string
{
    if ($aantal < 1 || $aantal > 100_000) {
        throw new SpelFout('Het aantal moet tussen 1 en 100.000 liggen.');
    }

    $speler = q_row('SELECT `id`, `login` FROM `users` WHERE `login` = ?', [$naam]);

    if ($speler === null) {
        throw new SpelFout('Die speler bestaat niet.');
    }

    diamanten_bijschrijven((int) $speler['id'], $aantal);

    notify((string) $speler['login'], 'Diamanten',
        'Je hebt ' . num($aantal) . ' diamanten gekregen van het beheer.');

    log_action((string) $user['login'], 'premium',
        num($aantal) . ' diamanten toegekend', $aantal, (string) $speler['login']);

    return $speler['login'] . ' heeft er ' . num($aantal) . ' gekregen.';
}

/** @throws SpelFout */
function premiumdagen_geven(array $user, string $naam, int $dagen): string
{
    if ($dagen < 1 || $dagen > 365) {
        throw new SpelFout('Het aantal dagen moet tussen 1 en 365 liggen.');
    }

    $speler = q_row('SELECT `id`, `login` FROM `users` WHERE `login` = ?', [$naam]);

    if ($speler === null) {
        throw new SpelFout('Die speler bestaat niet.');
    }

    premium_verlengen((int) $speler['id'], $dagen);

    notify((string) $speler['login'], 'Premium',
        'Je hebt ' . num($dagen) . ' dagen premium gekregen van het beheer.');

    log_action((string) $user['login'], 'premium',
        num($dagen) . ' dagen premium toegekend', $dagen, (string) $speler['login']);

    return $speler['login'] . ' heeft er ' . num($dagen) . ' dagen premium bij gekregen.';
}
