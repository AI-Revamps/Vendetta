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

beheer_header($user, 'dashboard.php');

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
