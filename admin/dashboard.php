<?php
/**
 * Beheerdersoverzicht: kerncijfers en de weg naar de beheerpagina's.
 *
 * Het oude admin.php was iets heel anders: een losse beheerder voor een
 * gastenboekje, met een eigen gebruikersnaam en wachtwoord uit config.php.
 * Het leunde op $HTTP_POST_VARS, session_register() en register_globals, alle
 * drie verwijderd uit PHP, en had geen enkele koppeling met de rechten van het
 * spel. Bovendien luidde de sessiecontrole `$_SESSION[login] + 60*60*24`,
 * waarbij $_SESSION['login'] de gebruikersnaam is en dus geen getal.
 *
 * Dat viel niet te redden. Deze pagina is nieuw en toont wat een beheerder
 * werkelijk nodig heeft.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user = require_level(LEVEL_MODERATOR);

$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    // Strenger dan het dashboard zelf: sommige taken (loterij, dagelijkse
    // reset) hebben echte spelimpact als je ze buiten hun ritme laat draaien.
    require_level(LEVEL_OWNER);

    try {
        cron_run_nu(post('taak'));
        $melding = 'Taak "' . post('taak') . '" is uitgevoerd.';
        $type    = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'dashboard.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

$cijfers = q_row(
    "SELECT
        (SELECT COUNT(*) FROM `users` WHERE `activated` = 1)                    AS spelers,
        (SELECT COUNT(*) FROM `users` WHERE `status` = 'levend' AND `activated` = 1) AS levend,
        (SELECT COUNT(*) FROM `users` WHERE `activated` = 0)                    AS ongeactiveerd,
        (SELECT COUNT(*) FROM `users`
          WHERE `online` > DATE_SUB(NOW(), INTERVAL 15 MINUTE))                 AS online,
        (SELECT COUNT(*) FROM `users` WHERE `level` >= 200)                     AS staf,
        (SELECT COUNT(*) FROM `bans`)                                           AS bans,
        (SELECT COUNT(*) FROM `jail` WHERE `time` > NOW())                      AS vast,
        (SELECT COUNT(*) FROM `famillie`)                                       AS families,
        (SELECT COUNT(*) FROM `forum_topics`)                                   AS topics,
        (SELECT IFNULL(SUM(`zak`) + SUM(`bank`), 0) FROM `users`)               AS geld"
) ?? [];

panel_open('Kerncijfers');
echo '<div class="tabelwikkel"><table class="lijst">';
foreach ([
    'Spelers (geactiveerd)' => num((int) ($cijfers['spelers'] ?? 0)),
    'Waarvan levend'        => num((int) ($cijfers['levend'] ?? 0)),
    'Nog niet geactiveerd'  => num((int) ($cijfers['ongeactiveerd'] ?? 0)),
    'Online (15 min)'       => num((int) ($cijfers['online'] ?? 0)),
    'Stafleden'             => num((int) ($cijfers['staf'] ?? 0)),
    'Verbanningen'          => num((int) ($cijfers['bans'] ?? 0)),
    'In de gevangenis'      => num((int) ($cijfers['vast'] ?? 0)),
    'Families'              => num((int) ($cijfers['families'] ?? 0)),
    'Forumtopics'           => num((int) ($cijfers['topics'] ?? 0)),
    'Geld in omloop'        => money((int) ($cijfers['geld'] ?? 0)),
] as $label => $waarde) {
    echo '<tr><th scope="row">' . e($label) . '</th><td class="getal">' . $waarde . '</td></tr>';
}
echo '</table></div>';
panel_close();

// --- Cron-status ---
$cronRijen  = q_all('SELECT `name`, `time` FROM `cron`');
$cronLaatst = array_combine(array_column($cronRijen, 'name'), array_column($cronRijen, 'time'));

$magForceren = (int) $user['level'] >= LEVEL_OWNER;

panel_open('Cron-status');
echo '<div class="tabelwikkel"><table class="lijst">';
echo '<thead><tr><th>Taak</th><th>Laatst gedraaid</th><th>Interval</th><th>Status</th>'
   . ($magForceren ? '<th></th>' : '') . '</tr></thead><tbody>';
foreach (cron_tasks() as $taakNaam => $taakRij) {
    $interval = $taakRij[0];
    $laatst   = $cronLaatst[$taakNaam] ?? null;
    $opTijd   = $laatst !== null && (time() - strtotime($laatst)) < $interval;

    echo '<tr>';
    echo '<td>' . e($taakNaam) . '</td>';
    echo '<td>' . ($laatst !== null ? e(datetime_nl($laatst)) : 'nog nooit') . '</td>';
    echo '<td>' . e(cron_interval_nl($interval)) . '</td>';
    echo '<td>' . ($opTijd ? 'recent gedraaid' : 'moet nog draaien') . '</td>';
    if ($magForceren) {
        echo '<td><form method="post">' . csrf_field()
           . '<input type="hidden" name="taak" value="' . e($taakNaam) . '">'
           . '<button type="submit" class="knop">Nu draaien</button></form></td>';
    }
    echo '</tr>';
}
echo '</tbody></table></div>';
panel_close();

// --- Verdeling nu: stad en rang ---
$stadRijen = q_all(
    "SELECT `stad`, COUNT(*) AS n FROM `users`
      WHERE `activated` = 1 GROUP BY `stad` ORDER BY n DESC"
);
$stadVerdeling = array_combine(
    array_column($stadRijen, 'stad'),
    array_map('intval', array_column($stadRijen, 'n'))
);

$rangVerdeling = [
    'Speler'    => (int) q_val(
        'SELECT COUNT(*) FROM `users` WHERE `activated` = 1 AND `level` < ?',
        [LEVEL_MODERATOR]
    ),
    'Moderator' => (int) q_val(
        'SELECT COUNT(*) FROM `users` WHERE `activated` = 1 AND `level` >= ? AND `level` < ?',
        [LEVEL_MODERATOR, LEVEL_ADMIN]
    ),
    'Admin'     => (int) q_val(
        'SELECT COUNT(*) FROM `users` WHERE `activated` = 1 AND `level` >= ? AND `level` < ?',
        [LEVEL_ADMIN, LEVEL_OWNER]
    ),
    'Eigenaar'  => (int) q_val(
        'SELECT COUNT(*) FROM `users` WHERE `activated` = 1 AND `level` >= ?',
        [LEVEL_OWNER]
    ),
];

// --- Trends: de laatste 30 dagen uit de dagelijkse geschiedenis ---
$geschiedenis = q_all(
    "SELECT * FROM `beheer_geschiedenis`
      WHERE `dag` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
   ORDER BY `dag`"
);

$grafiekData = [
    'stad' => $stadVerdeling,
    'rang' => $rangVerdeling,
    'trend' => [
        'dagen'        => array_column($geschiedenis, 'dag'),
        'spelers'      => array_map('intval', array_column($geschiedenis, 'spelers')),
        'geld_totaal'  => array_map('intval', array_column($geschiedenis, 'geld_totaal')),
        'registraties' => array_map('intval', array_column($geschiedenis, 'nieuwe_registraties')),
    ],
];

panel_open('Verdeling nu');
echo '<div class="beheer-grafieken">';
echo '<div class="beheer-grafiek"><h3>Spelers per stad</h3><canvas id="grafiek-stad"></canvas></div>';
echo '<div class="beheer-grafiek"><h3>Spelers per rang</h3><canvas id="grafiek-rang"></canvas></div>';
echo '</div>';
panel_close();

panel_open('Trends (laatste 30 dagen)');
echo '<div class="beheer-grafieken">';
echo '<div class="beheer-grafiek"><h3>Totaal spelers</h3><canvas id="grafiek-spelers"></canvas></div>';
echo '<div class="beheer-grafiek"><h3>Geld in omloop</h3><canvas id="grafiek-geld"></canvas></div>';
echo '<div class="beheer-grafiek"><h3>Nieuwe registraties</h3><canvas id="grafiek-registraties"></canvas></div>';
echo '</div>';
panel_close();

echo '<script type="application/json" id="beheer-data">'
   . json_encode($grafiekData, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>' . "\n";

// --- Diamantenlog ---
$diamantLog = q_all(
    "SELECT * FROM `logs` WHERE `area` = 'diamant' ORDER BY `time` DESC LIMIT 30"
);

panel_open('Diamanten: laatste mutaties');

if ($diamantLog === []) {
    echo '<p>Er is nog niets vastgelegd.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Wanneer</th><th>Door</th><th>Wie</th>'
       . '<th class="getal">Aantal</th><th>Waaraan</th></tr></thead><tbody>';
    foreach ($diamantLog as $regel) {
        echo '<tr>';
        echo '<td>' . e(datetime_nl($regel['time'])) . '</td>';
        echo '<td>' . speler_naam((string) $regel['login']) . '</td>';
        echo '<td>' . ($regel['person'] !== '' ? speler_naam((string) $regel['person']) : '-') . '</td>';
        echo '<td class="getal">' . num((int) $regel['code']) . '</td>';
        echo '<td>' . e((string) $regel['com']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

panel_close();

// --- Wat er recent gebeurd is ---
$recent = q_all('SELECT * FROM `logs` ORDER BY `time` DESC LIMIT 30');

panel_open('Laatste gebeurtenissen');

if ($recent === []) {
    echo '<p>Er is nog niets vastgelegd.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Wanneer</th><th>Gebied</th><th>Door</th><th>Wie</th>'
       . '<th class="getal">Waarde</th><th>Wat</th></tr></thead><tbody>';
    foreach ($recent as $regel) {
        echo '<tr>';
        echo '<td>' . e(datetime_nl($regel['time'])) . '</td>';
        echo '<td>' . e((string) $regel['area']) . '</td>';
        echo '<td>' . speler_naam((string) $regel['login']) . '</td>';
        echo '<td>' . speler_naam((string) $regel['person']) . '</td>';
        echo '<td class="getal">' . ((int) $regel['code'] !== 0 ? num((int) $regel['code']) : '') . '</td>';
        echo '<td>' . e((string) $regel['com']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

panel_close();
beheer_footer();
