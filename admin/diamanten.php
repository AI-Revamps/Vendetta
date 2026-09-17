<?php
/**
 * Vindkans, premiumprijs en koopadres voor diamanten.
 *
 * Alleen de eigenaar: dit stuurt de economie van het hele spel.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user    = require_level(beheerpaginas()['diamanten.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = balans_opslaan($user);
        $type    = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'diamanten.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('Diamanten en prijs');

echo '<form method="post">' . csrf_field();
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

beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function balans_opslaan(array $user): string
{
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
