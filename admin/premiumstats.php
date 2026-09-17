<?php
/**
 * Statistieken over premium en diamanten.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user = require_level(beheerpaginas()['premiumstats.php'][1]);

beheer_header($user, 'premiumstats.php');

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

beheer_footer();
