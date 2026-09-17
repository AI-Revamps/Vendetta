<?php
/**
 * Dagelijkse geschiedenis voor het beheerdashboard: schrijft de cron-taak
 * precies één rij per dag, en kloppen de cijfers daarin?
 *
 *     php tests/beheergeschiedenis.php
 *
 * De taak liftvt mee op gewone paginabezoeken (cron_mode = 'request', de
 * standaard) — elke haal() hieronder kan hem dus laten draaien zodra hij
 * aan de beurt is.
 */

declare(strict_types=1);

require __DIR__ . '/_start.php';

$db = tdb();

kop('beheer_geschiedenis: de eerste keer schrijft meteen een rij voor vandaag');

$db->exec("DELETE FROM beheer_geschiedenis WHERE dag = CURDATE()");
$db->exec("DELETE FROM cron WHERE name = 'geschiedenis'");

haal('home.php');

$rij = $db->query(
    "SELECT * FROM beheer_geschiedenis WHERE dag = CURDATE()"
)->fetch();

check('er staat een rij voor vandaag', $rij !== false);

$verwacht = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE activated = 1) AS spelers,
        (SELECT COUNT(*) FROM users WHERE status = 'levend' AND activated = 1) AS levend,
        (SELECT IFNULL(SUM(zak) + SUM(bank), 0) FROM users) AS geld_totaal,
        (SELECT COUNT(*) FROM bans) AS bans_totaal,
        (SELECT COUNT(*) FROM famillie) AS families"
)->fetch();

check('spelers klopt met een losse telling',
    $rij !== false && (int) $rij['spelers'] === (int) $verwacht['spelers']);
check('geld_totaal klopt met een losse telling',
    $rij !== false && (int) $rij['geld_totaal'] === (int) $verwacht['geld_totaal']);
check('bans_totaal klopt met een losse telling',
    $rij !== false && (int) $rij['bans_totaal'] === (int) $verwacht['bans_totaal']);
check('families klopt met een losse telling',
    $rij !== false && (int) $rij['families'] === (int) $verwacht['families']);

kop('beheer_geschiedenis: nog een keer draaien overschrijft, verdubbelt niet');

// Forceer dat de taak weer "aan de beurt" is, en verander ondertussen een
// cijfer — zo bewijst een gewijzigde rij dat de tweede run echt gedraaid
// heeft, in plaats van dat de test toevallig al slaagde.
$db->exec("UPDATE cron SET time = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE name = 'geschiedenis'");
$db->exec("INSERT INTO bans (ip, login, reden, door) VALUES ('9.9.9.9', 'Speler', 'test', 'Baas')");

haal('home.php');

$aantalRijen = (int) $db->query(
    "SELECT COUNT(*) FROM beheer_geschiedenis WHERE dag = CURDATE()"
)->fetchColumn();
$nieuweRij = $db->query(
    "SELECT bans_totaal FROM beheer_geschiedenis WHERE dag = CURDATE()"
)->fetch();
$nieuwVerwacht = (int) $db->query("SELECT COUNT(*) FROM bans")->fetchColumn();

check('nog steeds precies één rij voor vandaag', $aantalRijen === 1, (string) $aantalRijen);
check('de rij is bijgewerkt, niet blijven hangen op de oude waarde',
    $nieuweRij !== false && (int) $nieuweRij['bans_totaal'] === $nieuwVerwacht);

$db->exec("DELETE FROM bans WHERE ip = '9.9.9.9'");

samenvatting();
