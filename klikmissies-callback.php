<?php
/**
 * Callback-eindpunt voor stemsites die een stem automatisch bevestigen.
 *
 * Geen require_login(): dit bestand wordt door de stemsite zelf aangeroepen,
 * niet door een ingelogde speler. De beveiliging zit in het geheime token,
 * niet in een sessie.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/klikmissies.php';

header('Content-Type: text/plain; charset=utf-8');

$id     = int_input('id');
$geheim = get('geheim');
$login  = get('login');

$missie = q_row('SELECT * FROM `klikmissies` WHERE `id` = ? AND `actief` = 1', [$id]);

if ($missie === null) {
    echo 'FOUT: onbekende missie';
    exit;
}

if ((int) $missie['heeft_callback'] !== 1 || (string) $missie['callback_geheim'] === '') {
    echo 'FOUT: onbekende missie';
    exit;
}

if (!hash_equals((string) $missie['callback_geheim'], $geheim)) {
    echo 'FOUT: ongeldig token';
    exit;
}

try {
    klikmissie_belonen($missie, $login, 'callback', client_ip());
    echo 'OK';
} catch (SpelFout $e) {
    echo 'FOUT: ' . $e->getMessage();
}
