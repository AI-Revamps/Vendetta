<?php
/**
 * Statistieken: spelers, geld, steden, casino's en kogelfabrieken.
 *
 * Wat hier gerepareerd is:
 *
 *  - De totalen werden opgehaald door álle spelers uit de database te lezen
 *    en in PHP op te tellen. Bij duizend spelers is dat duizend rijen per
 *    paginaweergave; nu één query met SUM().
 *  - Het aantal spelers, levenden, doden en online werd elk met een aparte
 *    `SELECT *` gevolgd door mysql_num_rows() geteld: vier volledige
 *    tabelscans waarbij alle kolommen over de lijn gingen om alleen het
 *    aantal te weten.
 *  - Per stad twee losse queries, met de naam hardcoded. Dat waren zestien
 *    queries voor acht steden, en een stad die niet in de lijst stond kwam er
 *    niet in voor. Nu één query, met een lus over cities().
 *  - `number_format($bank, 0, ',' , ',')` gebruikte de komma óók als
 *    duizendtalscheiding, dus er stond "1,234,567" in een spel dat verder
 *    Nederlandse notatie hanteert.
 *  - De spelersnamen kwamen ongefilterd in de links terecht.
 *  - `<font color=009900>` zonder hekje is geen geldige kleur; browsers
 *    maakten daar iets willekeurigs van.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

$user = require_login();

layout_header('Statistieken');

// --- Spelers en geld -------------------------------------------------------

$cijfers = q_row(
    "SELECT COUNT(*)                                                   AS `totaal`,
            SUM(`status` = 'levend')                                   AS `levend`,
            SUM(`status` = 'dood')                                     AS `dood`,
            SUM(`online` > DATE_SUB(NOW(), INTERVAL 5 MINUTE))         AS `online`,
            SUM(`zak`)                                                 AS `zak`,
            SUM(`bank`)                                                AS `bank`,
            SUM(`kogels`)                                              AS `kogels`
       FROM `users` WHERE `activated` = 1"
) ?? [];

panel_open('Spelers');
echo '<div class="tabelwikkel"><table class="lijst"><tbody>';
regel('Spelers',           num((int) ($cijfers['totaal'] ?? 0)));
regel('Levend',            num((int) ($cijfers['levend'] ?? 0)));
// "Dood" betekent sinds de herstartregeling: omgelegd en nog niet opnieuw
// begonnen. Wie doorstart telt weer als levend.
regel('Omgelegd, nog niet herstart', num((int) ($cijfers['dood'] ?? 0)));
regel('Online',            num((int) ($cijfers['online'] ?? 0)));
regel('Geld op zak',       money((int) ($cijfers['zak'] ?? 0)));
regel('Geld op de bank',   money((int) ($cijfers['bank'] ?? 0)));
regel('Geld in omloop',    money((int) ($cijfers['zak'] ?? 0) + (int) ($cijfers['bank'] ?? 0)));
regel('Kogels',            num((int) ($cijfers['kogels'] ?? 0)));
echo '</tbody></table></div>';
panel_close();

// --- Ranglijsten -----------------------------------------------------------

ranglijst('Meest geëerd', 'DESC');
ranglijst('Minst geëerd', 'ASC');

panel_open('Laatste tien doden');

// De kolom `dader` staat er bewust niet bij. Wie een moord pleegde is geheim;
// dat kom je alleen te weten via een ooggetuigenverklaring van de zwarte markt.
$doden = q_all(
    'SELECT v.`login`, v.`date` FROM `vermoord` v ORDER BY v.`date` DESC LIMIT 10'
);

if ($doden === []) {
    echo '<p>Er is nog niemand vermoord.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Slachtoffer</th><th>Wanneer</th></tr></thead><tbody>';

    foreach ($doden as $dode) {
        echo '<tr>';
        echo '<td>' . spelerlink((string) $dode['login']) . '</td>';
        echo '<td>' . e(datetime_nl($dode['date'])) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    echo '<p class="uitleg">Wie de moorden pleegde staat er niet bij. Dat weten alleen '
       . 'de ooggetuigen, en die zijn te vinden op de zwarte markt.</p>';
}

panel_close();

// --- Steden ----------------------------------------------------------------

$steden = [];

foreach (q_all('SELECT * FROM `stad`') as $rij) {
    $steden[(string) $rij['stad']] = $rij;
}

$bewoners = [];

foreach (q_all("SELECT `stad`, COUNT(*) AS `aantal` FROM `users`
                 WHERE `status` = 'levend' AND `activated` = 1 GROUP BY `stad`") as $rij) {
    $bewoners[(string) $rij['stad']] = (int) $rij['aantal'];
}

panel_open('Steden');
echo '<div class="tabelwikkel"><table class="lijst">';
echo '<thead><tr><th>Stad</th><th class="getal">Drugs</th><th class="getal">Drank</th>'
   . '<th class="getal">Kogels</th><th class="getal">Kogelprijs</th>'
   . '<th class="getal">Spelers</th></tr></thead><tbody>';

foreach (cities() as $naam) {
    $stad = $steden[$naam] ?? null;

    echo '<tr>';
    echo '<td>' . e($naam) . '</td>';

    if ($stad === null) {
        echo '<td colspan="4"><em>staat niet in de database</em></td>';
    } else {
        echo '<td class="getal">' . num((int) $stad['drugs']) . '</td>';
        echo '<td class="getal">' . num((int) $stad['drank']) . '</td>';
        echo '<td class="getal">' . num((int) $stad['kogels']) . '</td>';
        echo '<td class="getal">' . money((int) $stad['prijs']) . '</td>';
    }

    echo '<td class="getal">' . num($bewoners[$naam] ?? 0) . '</td>';
    echo '</tr>';
}

echo '</tbody></table></div>';
panel_close();

// --- Casino's en fabrieken -------------------------------------------------

$panden = [];

foreach (q_all('SELECT * FROM `casino` ORDER BY `spel`, `stad`') as $rij) {
    $panden[(string) $rij['spel']][] = $rij;
}

$namen = [
    'guess'        => 'Nummer raden',
    'blackjack'    => 'Blackjack',
    'fruitmachine' => 'Fruitmachine',
    'roulette'     => 'Roulette',
    'kogelfabriek' => 'Kogelfabrieken',
];

foreach ($namen as $spel => $titel) {
    $rijen = $panden[$spel] ?? [];

    panel_open($titel);

    if ($rijen === []) {
        echo '<p>Hier staat nog niets.</p>';
        panel_close();
        continue;
    }

    $isFabriek = $spel === 'kogelfabriek';

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Stad</th><th>Eigenaar</th>'
       . '<th class="getal">' . ($isFabriek ? 'Kogels' : 'Winst') . '</th>'
       . '<th class="getal">' . ($isFabriek ? 'Prijs' : 'Inzet') . '</th></tr></thead><tbody>';

    foreach ($rijen as $pand) {
        $eigenaar = (string) $pand['owner'];
        $winst    = (int) $pand['winst'];

        echo '<tr>';
        echo '<td>' . e((string) $pand['stad']) . '</td>';
        echo '<td>' . ($eigenaar === '' ? '<em>te koop</em>' : spelerlink($eigenaar)) . '</td>';
        echo '<td class="getal' . ($isFabriek || $winst >= 0 ? '' : ' verlies') . '">'
           . ($isFabriek ? num($winst) : money($winst)) . '</td>';
        echo '<td class="getal">' . money((int) $pand['inzet']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    panel_close();
}

layout_footer();

// ==========================================================================

function regel(string $label, string $waarde): void
{
    echo '<tr><th>' . e($label) . '</th><td class="getal">' . $waarde . '</td></tr>';
}

function spelerlink(string $naam): string
{
    return '<a' . speler_rol_attr($naam) . ' href="' . e(url('user.php?x=' . rawurlencode($naam)))
         . '">' . e($naam) . '</a>';
}

function ranglijst(string $titel, string $richting): void
{
    $rijen = q_all(
        "SELECT `login`, `respect` FROM `users`
          WHERE `status` = 'levend' AND `activated` = 1
          ORDER BY `respect` " . ($richting === 'ASC' ? 'ASC' : 'DESC') . ", `login`
          LIMIT 10"
    );

    panel_open($titel);

    if ($rijen === []) {
        echo '<p>Nog geen spelers.</p>';
        panel_close();
        return;
    }

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th class="getal">#</th><th>Speler</th><th class="getal">Eer</th>'
       . '</tr></thead><tbody>';

    foreach ($rijen as $i => $rij) {
        echo '<tr>';
        echo '<td class="getal">' . ($i + 1) . '</td>';
        echo '<td>' . spelerlink((string) $rij['login']) . '</td>';
        echo '<td class="getal">' . num((int) $rij['respect']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    panel_close();
}
