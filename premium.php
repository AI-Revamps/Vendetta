<?php
/**
 * Premium en diamanten.
 *
 * Vervangt donate.php. Er is één premiummodel van veertien dagen, af te sluiten
 * met diamanten of met een code die je buiten het spel gekocht hebt.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/opmaak.php';   // veilige_url() voor de Ko-fi-link

$user = require_login();

if (is_dead()) {
    redirect('rip.php');
}

$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'kopen'      => kopen_met_diamanten($user),
            'inwisselen' => code_inwisselen($user, post('code')),
            default      => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
        $user = current_user(true);
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Premium');

if ($melding !== null) {
    notice(e($melding), $type);
}

// --- Status -----------------------------------------------------------------

panel_open('Jouw status');

echo '<div class="tabelwikkel"><table class="lijst"><tbody>';
echo '<tr><th>Premium</th><td>';

if (is_premium($user)) {
    echo '<strong>actief</strong> tot ' . e(datetime_nl((string) $user['premium_tot']))
       . ' <small>(nog ' . e(duur_in_dagen(premium_resterend($user))) . ')</small>';
} else {
    echo 'niet actief';
}

echo '</td></tr>';
echo '<tr><th>Diamanten</th><td><strong>' . num((int) $user['diamanten']) . '</strong></td></tr>';
echo '<tr><th>Ooit gevonden</th><td>' . num((int) $user['diamanten_gevonden']) . '</td></tr>';
echo '</tbody></table></div>';

panel_close();

// --- Wat premium doet -------------------------------------------------------

panel_open('Wat je eraan hebt');

$interval = ads_interval();

echo '<p><strong>Geen advertenties.</strong> Zonder premium onderbreekt het spel je '
   . ($interval > 0
        ? 'elke ' . num($interval) . ' paginabezoeken'
        : 'af en toe')
   . ' met een advertentiepagina. Met premium gebeurt dat niet.</p>';

echo '<p>Meer is het niet, en dat is met opzet: premium maakt je niet sterker. Je koopt '
   . 'rust, geen voorsprong. Wie niet betaalt speelt precies hetzelfde spel.</p>';

echo '<p>Premium duurt ' . PREMIUM_DAGEN . ' dagen. Verleng je terwijl het nog loopt, '
   . 'dan worden de dagen erbij opgeteld — je verliest dus niets door op tijd te zijn.</p>';

panel_close();

// --- Afsluiten met diamanten ------------------------------------------------

$prijs = premium_prijs();

panel_open('Afsluiten met diamanten');

echo '<p>Premium kost <strong>' . num($prijs) . ' diamanten</strong> voor '
   . PREMIUM_DAGEN . ' dagen. Je hebt er ' . num((int) $user['diamanten']) . '.</p>';

if ((int) $user['diamanten'] < $prijs) {
    echo '<p>Je komt ' . num($prijs - (int) $user['diamanten']) . ' diamanten tekort.</p>';
} else {
    echo '<form method="post">' . csrf_field()
       . '<input type="hidden" name="actie" value="kopen">'
       . '<button type="submit">' . num($prijs) . ' diamanten uitgeven</button></form>';
}

echo '<p class="uitleg">Diamanten vind je bij toeval tijdens een misdaad. De kans is '
   . 'ongeveer één op ' . num(diamant_kans()) . '.</p>';

panel_close();

// --- Code inwisselen --------------------------------------------------------

panel_open('Een code inwisselen');

echo '<p>Heb je premium buiten het spel om gekocht, dan heb je een code gekregen. '
   . 'Vul hem hier in.</p>';

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="inwisselen">';
echo '<div class="veldenraster">';
echo '<label for="code">Code</label>';
echo '<input id="code" name="code" maxlength="32" required autocomplete="off" spellcheck="false">';
echo '<span></span><button type="submit">Inwisselen</button>';
echo '</div></form>';

$kofi = instelling('kofi_url', '');

if ($kofi !== '') {
    $adres = veilige_url($kofi);

    if ($adres !== null) {
        echo '<p><a class="knop" href="' . e($adres) . '" target="_blank" rel="noopener noreferrer">'
           . 'Premium kopen</a></p>';
    }
}

panel_close();

// --- Wat je met diamanten kunt ----------------------------------------------

$laatste = q_all(
    "SELECT `time`, `com`, `code` FROM `logs`
      WHERE `login` = ? AND `area` = 'diamant' ORDER BY `time` DESC LIMIT 10",
    [$user['login']]
);

panel_open('Je laatste vondsten');

if ($laatste === []) {
    echo '<p>Je hebt nog geen diamanten gevonden.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Wanneer</th><th>Waar</th><th class="getal">Aantal</th></tr></thead><tbody>';

    foreach ($laatste as $regel) {
        echo '<tr><td>' . e(datetime_nl($regel['time'])) . '</td>';
        echo '<td>' . e((string) $regel['com']) . '</td>';
        echo '<td class="getal">' . num((int) $regel['code']) . '</td></tr>';
    }

    echo '</tbody></table></div>';
}

panel_close();
layout_footer();

// ==========================================================================

/** @throws SpelFout */
function kopen_met_diamanten(array $user): string
{
    $prijs = premium_prijs();

    return db_transaction(static function () use ($user, $prijs): string {
        lock_user((int) $user['id']);

        if (!diamanten_afboeken((int) $user['id'], $prijs)) {
            throw new SpelFout('Je hebt ' . num($prijs) . ' diamanten nodig.');
        }

        premium_verlengen((int) $user['id']);

        log_action((string) $user['login'], 'diamant',
            'Uitgegeven aan premium', $prijs);

        return 'Je hebt premium voor ' . PREMIUM_DAGEN . ' dagen. Geen advertenties meer.';
    });
}

/** @throws SpelFout */
function code_inwisselen(array $user, string $code): string
{
    $code = strtoupper(trim($code));

    if ($code === '') {
        throw new SpelFout('Vul een code in.');
    }

    return db_transaction(static function () use ($user, $code): string {
        // Vergrendelen, zodat dezelfde code niet twee keer tegelijk werkt.
        $rij = q_row('SELECT * FROM `donate` WHERE `code` = ? FOR UPDATE', [$code]);

        if ($rij === null) {
            throw new SpelFout('Deze code is ongeldig of al gebruikt.');
        }

        // Een code die op naam staat, is alleen voor die speler.
        if ((string) $rij['door'] !== '' && (string) $rij['door'] !== (string) $user['login']) {
            throw new SpelFout('Deze code staat op naam van een andere speler.');
        }

        premium_verlengen((int) $user['id']);
        q('DELETE FROM `donate` WHERE `id` = ?', [$rij['id']]);

        log_action((string) $user['login'], 'premium', 'Code ingewisseld: ' . $code);

        return 'Bedankt. Je hebt premium voor ' . PREMIUM_DAGEN . ' dagen.';
    });
}

/** "3 dagen" of "4 uur", afhankelijk van wat er nog over is. */
function duur_in_dagen(int $seconden): string
{
    $dagen = intdiv($seconden, 86400);

    if ($dagen >= 1) {
        return num($dagen) . ($dagen === 1 ? ' dag' : ' dagen');
    }

    $uren = intdiv($seconden, 3600);

    return $uren >= 1
        ? num($uren) . ($uren === 1 ? ' uur' : ' uur')
        : 'minder dan een uur';
}
