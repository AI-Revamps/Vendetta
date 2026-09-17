<?php
/**
 * Logboek van alles rond premium en diamanten.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user = require_level(beheerpaginas()['premiumlog.php'][1]);

beheer_header($user, 'premiumlog.php');

panel_open('Logboek');
beheer_logregels(['premium', 'diamant'], 50);
panel_close();

beheer_footer();
