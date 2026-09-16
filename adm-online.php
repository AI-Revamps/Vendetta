<?php
/**
 * Wie er nu online is.
 *
 * De oude versie haalde alle online spelers op en deed daarna per speler nog
 * een losse query voor de familie. Dat is nu één query met een join.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/beheer.php';

const ONLINE_MINUTEN = 15;

$user = beheer_start('adm-online.php');

$spelers = q_all(
    'SELECT `login`, `level`, `xp`, `stad`, `famillie`, `status`, `ip`, `online`
       FROM `users`
      WHERE `online` > DATE_SUB(NOW(), INTERVAL ? MINUTE)
   ORDER BY `xp` DESC',
    [ONLINE_MINUTEN]
);

panel_open(count($spelers) . ' online in de laatste ' . ONLINE_MINUTEN . ' minuten');

if ($spelers === []) {
    echo '<p>Er is niemand online.</p>';
} else {
    // Meerdere accounts vanaf hetzelfde IP vallen zo meteen op.
    $perIp = [];
    foreach ($spelers as $speler) {
        $perIp[(string) $speler['ip']] = ($perIp[(string) $speler['ip']] ?? 0) + 1;
    }

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Speler</th><th>Rang</th><th>Stad</th><th>Familie</th>'
       . '<th>IP-adres</th><th>Laatst gezien</th></tr></thead><tbody>';

    foreach ($spelers as $speler) {
        $ip     = (string) $speler['ip'];
        $meer   = ($perIp[$ip] ?? 0) > 1;
        $staf   = (int) $speler['level'] >= LEVEL_MODERATOR;

        echo '<tr>';
        echo '<td><a' . speler_rol_attr((string) $speler['login']) . ' href="'
           . e(url('adm-search.php?login=' . rawurlencode((string) $speler['login'])))
           . '">' . e((string) $speler['login']) . '</a>'
           . ($staf ? ' <small>(staf)</small>' : '')
           . ($speler['status'] !== 'levend' ? ' <small>(dood)</small>' : '') . '</td>';
        echo '<td>' . e(rank_name((int) $speler['xp'])) . '</td>';
        echo '<td>' . e((string) $speler['stad']) . '</td>';
        echo '<td>' . ($speler['famillie'] !== '' ? e((string) $speler['famillie']) : '-') . '</td>';
        echo '<td>' . e($ip) . ($meer ? ' <strong>(' . $perIp[$ip] . ' accounts)</strong>' : '') . '</td>';
        echo '<td>' . e(datetime_nl($speler['online'])) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

panel_close();
layout_footer();
