<?php
/**
 * Advertentie-instellingen.
 *
 * Alleen de eigenaar: het codeveld gaat ongefilterd naar de browser van elke
 * speler die de advertentiepagina ziet — dat moet, anders werkt geen enkel
 * advertentienetwerk, maar het betekent ook dat wie hierbij kan script kan
 * laten draaien bij elke speler. Niets voor iemand onder eigenaarsniveau.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user    = require_level(beheerpaginas()['advertenties.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = advertentie_opslaan($user);
        $type    = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'advertenties.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('Advertentie');

echo '<p>Plak hier de code van je advertentienetwerk. Wat je invult komt '
   . '<strong>ongefilterd</strong> op de pagina te staan — dat moet, anders werkt de '
   . 'code van het netwerk niet. Plak dus alleen iets waarvan je weet waar het '
   . 'vandaan komt.</p>';

echo '<form method="post">' . csrf_field();
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

beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function advertentie_opslaan(array $user): string
{
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
