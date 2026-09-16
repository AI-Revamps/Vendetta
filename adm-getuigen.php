<?php
/**
 * Instellen hoe ooggetuigen bij een moord worden aangewezen.
 *
 * Het slachtoffer krijgt niet te horen wie hem omlegde. De enige manier om
 * daarachter te komen is een ooggetuigenverklaring, en die staat op de zwarte
 * markt. Hoeveel getuigen er zijn bepaalt dus hoe snel een moord uitlekt — en
 * dat is precies waar je hier aan draait.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/beheer.php';
require BV_INC . '/combat.php';

$user    = require_level(beheerpaginas()['adm-getuigen.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = opslaan($user, post('wijze'));
        $type    = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Beheer');
beheer_menu($user, 'adm-getuigen.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

$huidig = getuigenwijze();

panel_open('Ooggetuigen bij een moord');

echo '<p>Een slachtoffer ziet nooit wie hem omlegde. Alleen ooggetuigen weten dat, en '
   . 'zij kunnen hun verklaring op de zwarte markt verkopen. Hier bepaal je wie er '
   . 'getuige wordt.</p>';

echo '<form method="post">' . csrf_field();
echo '<div class="keuzelijst">';

foreach (getuigenwijzen() as $sleutel => $optie) {
    echo '<label class="keuze' . ($sleutel === $huidig ? ' gekozen' : '') . '">';
    echo '<input type="radio" name="wijze" value="' . e($sleutel) . '"'
       . ($sleutel === $huidig ? ' checked' : '') . '> ';
    echo '<strong>' . e($optie['naam']) . '</strong>';
    echo '<span class="uitleg">' . e($optie['uitleg']) . '</span>';
    echo '</label>';
}

echo '</div>';
echo '<button type="submit">Opslaan</button>';
echo '</form>';

echo '<p class="uitleg">Iemand geldt als online wanneer hij in de laatste '
   . GETUIGEN_ONLINE_MINUTEN . ' minuten een pagina heeft opgevraagd. Een verklaring is '
   . round(OOGGETUIGE_GELDIG / 86400) . ' dagen geldig; daarna vervalt hij.</p>';

panel_close();

// --- Wat het nu in de praktijk zou doen ------------------------------------

panel_open('Hoeveel getuigen zou dit nu opleveren?');

echo '<p>Op basis van wie er op dit moment online is, per stad.</p>';

$online = [];
foreach (q_all(
    "SELECT `stad`, COUNT(*) AS `aantal` FROM `users`
      WHERE `status` = 'levend' AND `activated` = 1
        AND `online` > DATE_SUB(NOW(), INTERVAL ? MINUTE)
      GROUP BY `stad`",
    [GETUIGEN_ONLINE_MINUTEN]
) as $rij) {
    $online[(string) $rij['stad']] = (int) $rij['aantal'];
}

$levend = [];
foreach (q_all(
    "SELECT `stad`, COUNT(*) AS `aantal` FROM `users`
      WHERE `status` = 'levend' AND `activated` = 1 GROUP BY `stad`"
) as $rij) {
    $levend[(string) $rij['stad']] = (int) $rij['aantal'];
}

echo '<div class="tabelwikkel"><table class="lijst">';
echo '<thead><tr><th>Stad</th><th class="getal">Online</th><th class="getal">Levend</th>'
   . '<th>Getuigen bij een moord hier</th></tr></thead><tbody>';

foreach (cities() as $stad) {
    $nuOnline = $online[$stad] ?? 0;
    $nuLevend = $levend[$stad] ?? 0;

    // Min twee: de dader en het slachtoffer tellen zelf niet mee.
    $kandidaten = match ($huidig) {
        'stad'        => max(0, $nuOnline - 2),
        'online'      => max(0, $nuOnline - 2),
        default       => max(0, $nuLevend - 2),
    };

    $uitkomst = match ($huidig) {
        'stad'  => $kandidaten === 0
            ? 'niemand'
            : num($kandidaten) . ($kandidaten === 1 ? ' getuige' : ' getuigen'),
        default => $kandidaten >= GETUIGEN_AANTAL
            ? num(GETUIGEN_AANTAL) . ' uit deze stad'
            : num($kandidaten) . ' uit deze stad, de rest van elders',
    };

    echo '<tr>';
    echo '<td>' . e($stad) . '</td>';
    echo '<td class="getal">' . num($nuOnline) . '</td>';
    echo '<td class="getal">' . num($nuLevend) . '</td>';
    echo '<td>' . e($uitkomst) . '</td>';
    echo '</tr>';
}

echo '</tbody></table></div>';

if ($huidig === 'stad') {
    echo '<p class="uitleg">Bij deze keuze wordt er niet aangevuld met spelers van elders. '
       . 'Is er niemand online in een stad, dan blijft een moord daar onopgemerkt.</p>';
}

panel_close();

// --- Lopende verklaringen ---------------------------------------------------

$verklaringen = q_all(
    'SELECT * FROM `ws` WHERE `time` > NOW() ORDER BY `time` DESC LIMIT 25'
);

panel_open('Lopende verklaringen (' . count($verklaringen) . ')');

if ($verklaringen === []) {
    echo '<p>Er zijn op dit moment geen geldige ooggetuigenverklaringen.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Getuige</th><th>Moord op</th><th>Verdachte</th>'
       . '<th>Status</th><th class="getal">Vraagprijs</th><th>Geldig tot</th></tr></thead><tbody>';

    foreach ($verklaringen as $v) {
        echo '<tr>';
        echo '<td>' . speler_naam((string) $v['login']) . '</td>';
        echo '<td>' . speler_naam((string) $v['victim']) . '</td>';
        echo '<td>' . speler_naam((string) $v['suspect']) . '</td>';
        echo '<td>' . ((int) $v['status'] === 1 ? 'te koop' : 'in bezit') . '</td>';
        echo '<td class="getal">' . ((int) $v['status'] === 1 ? money((int) $v['prijs']) : '—') . '</td>';
        echo '<td>' . e(datetime_nl($v['time'])) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    echo '<p class="uitleg">Deze lijst is alleen voor het beheer. Spelers zien op de zwarte '
       . 'markt uitsluitend wat te koop staat, en pas ná aankoop wie de verdachte is.</p>';
}

panel_close();

beheer_logregels('getuigen');

layout_footer();

// ==========================================================================

/** @throws SpelFout */
function opslaan(array $user, string $wijze): string
{
    if (!isset(getuigenwijzen()[$wijze])) {
        throw new SpelFout('Die keuze bestaat niet.');
    }

    $oud = getuigenwijze();

    if ($oud === $wijze) {
        throw new SpelFout('Die instelling stond er al op.');
    }

    instelling_zetten('getuigen_wijze', $wijze);

    log_action((string) $user['login'], 'getuigen',
        'Getuigenwijze van ' . $oud . ' naar ' . $wijze);

    return 'Vanaf nu: ' . getuigenwijzen()[$wijze]['naam'] . '.';
}
