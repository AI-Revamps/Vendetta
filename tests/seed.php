<?php
/**
 * Zet vier testaccounts klaar in de testdatabase, plus de steden.
 *
 *     php tests/seed.php
 *
 * Speler  (niveau 1)     spelerwachtwoord123
 * Mod     (niveau 200)   modwachtwoord123456
 * Admin   (niveau 255)   adminwachtwoord12345
 * Baas    (niveau 1000)  baaswachtwoord12345
 */

declare(strict_types=1);

require __DIR__ . '/_start.php';

$accounts = [
    ['Speler', 'spelerwachtwoord123',  1],
    ['Mod',    'modwachtwoord123456',  200],
    ['Admin',  'adminwachtwoord12345', 255],
    ['Baas',   'baaswachtwoord12345',  1000],
];

$db = tdb();

foreach ($accounts as [$naam, $ww, $level]) {
    $db->prepare('DELETE FROM `users` WHERE `login` = ?')->execute([$naam]);
    $db->prepare('DELETE FROM `huizen` WHERE `login` = ?')->execute([$naam]);

    $db->prepare(
        "INSERT INTO `users` (`login`, `pass`, `email`, `level`, `stad`, `geslacht`,
                              `activated`, `status`, `health`, `xp`, `zak`, `bank`,
                              `kogels`, `start`, `online`)
              VALUES (?, ?, ?, ?, 'Brussel', 'Man', 1, 'levend', 100, 5000, 100000, 50000,
                      500, DATE_SUB(NOW(), INTERVAL 30 DAY), NOW())"
    )->execute([$naam, password_hash($ww, PASSWORD_DEFAULT), strtolower($naam) . '@example.com', $level]);

    // Iedereen begint met een huis in zijn startstad, net als bij registratie.
    $db->prepare("INSERT IGNORE INTO `huizen` (`login`, `stad`) VALUES (?, 'Brussel')")
       ->execute([$naam]);
}

// De steden uit de configuratie, voor het geval het schema ze niet had.
$config = require BV_WORTEL . '/inc/config.php';

foreach ($config['game']['cities'] ?? [] as $stad) {
    $db->prepare('INSERT IGNORE INTO `stad` (`stad`) VALUES (?)')->execute([$stad]);
}

printf("spelers: %s\n", $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
printf("steden:  %s\n", $db->query('SELECT COUNT(*) FROM stad')->fetchColumn());
printf("items:   %s\n", $db->query('SELECT COUNT(*) FROM items')->fetchColumn());
