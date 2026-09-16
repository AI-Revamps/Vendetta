<?php
/**
 * Je bent omgelegd. Hier zie je wie het deed, en begin je opnieuw.
 *
 * Doodgaan is geen einde meer: hetzelfde account gaat terug naar de beginstand
 * en je speelt verder. Daarom hoef je geen nieuw account aan te maken — en
 * daarom mag er ook maar één account per IP-adres en per e-mailadres bestaan.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

$user = require_login();

// Gezondheid op nul betekent alsnog het einde.
if (!is_dead() && (int) $user['health'] < 1) {
    q("UPDATE `users` SET `status` = 'dood' WHERE `id` = ?", [$user['id']]);
    $user = current_user(true);
}

if (!is_dead()) {
    redirect('home.php');
}

$melding = null;
$type    = 'info';
$stad    = null;

if (is_post()) {
    csrf_check();
    try {
        $stad = speler_herstarten((int) $user['id']);
        $user = current_user(true);
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

// Herstart gelukt: de speler leeft weer en krijgt de welkomstpagina.
if ($stad !== null) {
    layout_header('Een nieuw begin');
    panel_open('Je begint opnieuw');

    echo '<p>Je bent onder een schone lei begonnen in <strong>' . e($stad) . '</strong>.</p>';
    echo '<p>Je hebt ' . money((int) config('game.start_money', 1000))
       . ' op zak en de beginnersbescherming loopt weer, dus je kunt even niet '
       . 'vermoord worden. Je rang, geld, wapens, auto\'s en familie zijn weg.</p>';
    echo '<p>Dit is de <strong>' . num((int) $user['gestorven'])
       . 'e</strong> keer dat je opnieuw begint. Dat staat op je profiel.</p>';
    echo '<p><a class="knop" href="' . e(url('home.php')) . '">Aan de slag</a></p>';

    panel_close();
    layout_footer();
    exit;
}

$moord = q_row(
    'SELECT * FROM `vermoord` WHERE `login` = ? ORDER BY `date` DESC LIMIT 1',
    [$user['login']]
);

layout_header('Rust in vrede');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('Rust in vrede');

echo '<p><img src="' . e(url('images/rip.gif')) . '" alt="" style="float:right;margin-left:1rem">';
echo '<strong>' . speler_naam((string) $user['login']) . '</strong> is niet meer. Je bent omgelegd.</p>';

if ($moord !== null) {
    echo '<p>Vermoord op ' . e(datetime_nl($moord['date'])) . '.</p>';

    // Wie het deed staat er nadrukkelijk niet bij. Dat kom je alleen te weten
    // via een ooggetuige, en die verklaring staat op de zwarte markt.
    echo '<p>Je hebt niet gezien wie het was.</p>';

    // Het bericht van de dader is tekst van een speler: eerst escapen, dan pas
    // de regelovergangen omzetten.
    if (($moord['msg'] ?? '') !== '') {
        echo '<p>Er lag wel een briefje:</p><blockquote>'
           . nl2br(e((string) $moord['msg'])) . '</blockquote>';
    }

    $getuigen = (int) q_val(
        'SELECT COUNT(*) FROM `ws` WHERE `victim` = ? AND `time` > NOW()',
        [$user['login']],
        0
    );

    if ($getuigen > 0) {
        echo '<p>' . ($getuigen === 1 ? 'Eén iemand' : num($getuigen) . ' mensen')
           . ' ' . ($getuigen === 1 ? 'heeft' : 'hebben') . ' het zien gebeuren. '
           . 'Op de <a href="' . e(url('mshop.php?x=ws')) . '">zwarte markt</a> wordt zo\'n '
           . 'verklaring wel eens te koop aangeboden.</p>';
    } else {
        echo '<p>Voor zover je weet heeft niemand het zien gebeuren.</p>';
    }
}

if (($user['testament'] ?? '') !== '') {
    echo '<p>Je bezit is nagelaten aan <strong>' . e((string) $user['testament'])
       . '</strong>.</p>';
}

if ((int) $user['gestorven'] > 0) {
    echo '<p style="clear:both">Je bent al ' . num((int) $user['gestorven'])
       . ' keer eerder omgelegd.</p>';
}

panel_close();

panel_open('Opnieuw beginnen');

echo '<p>Je hoeft geen nieuw account te maken: dit account begint opnieuw. '
   . 'Je houdt je gebruikersnaam, je profiel en je lopende donaties.</p>';

echo '<p>Je raakt kwijt:</p><ul>'
   . '<li>je rang en ervaring</li>'
   . '<li>al je geld, op zak en op de bank</li>'
   . '<li>je wapens, bescherming, vervoer en lijfwachten</li>'
   . '<li>je auto\'s, je familie en je huwelijk</li>'
   . '<li>een casino of kogelfabriek die van jou was</li>'
   . '</ul>';

echo '<p>Je begint in een willekeurige stad met '
   . money((int) config('game.start_money', 1000))
   . ', en de beginnersbescherming loopt weer even.</p>';

echo '<form method="post">' . csrf_field()
   . '<button type="submit">Opnieuw beginnen</button></form>';

echo '<p class="uitleg">Je hoeft niet meteen te kiezen. Zolang je niet opnieuw '
   . 'begint, blijft dit scherm staan en kun je alleen rondkijken.</p>';

panel_close();

echo '<form method="post" action="' . e(url('logout.php')) . '" style="margin-top:1rem">'
   . csrf_field() . '<button type="submit">Uitloggen</button></form>';

layout_footer();
