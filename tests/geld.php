<?php
/**
 * Geldintegriteit: een volledige speelsessie op een verse database, waarbij na
 * elke stap gecontroleerd wordt of de totale geldhoeveelheid klopt.
 *
 *     php tests/geld.php
 *
 * Dit is de belangrijkste test van het spel. In de oude versie zaten twee
 * lekken waar geld werd bijgedrukt (bij een moord en bij de dodelijke tomaat op
 * de schandpaal); zulke fouten vind je alleen door de balans te vergelijken.
 *
 * De captcha staat tijdens deze test op 'tekst', zodat de som uit de pagina te
 * lezen is. Dat is een gewone instelling, geen testluik.
 */

declare(strict_types=1);

require __DIR__ . '/_start.php';

const DB = 'bv_geldtest';

$db = verse_database(DB);

config_tijdelijk([
    "'name' => '" . BV_DB . "'" => "'name' => '" . DB . "'",
    "'debug'     => true"       => "'debug'     => true,\n    'captcha'   => 'tekst'",
]);

register_shutdown_function(static function (): void {
    tserver()->exec('DROP DATABASE IF EXISTS `' . DB . '`');
});

// --- Twee spelers ----------------------------------------------------------

$hash = password_hash('eenlangwachtwoord', PASSWORD_DEFAULT);

$maak = $db->prepare(
    "INSERT INTO users (login,pass,email,level,stad,geslacht,activated,status,health,xp,
                        zak,bank,kogels,wapon,se,start,online)
     VALUES (?,?,?,1,'Brussel','Man',1,'levend',100,25000,?,?,?,6,100,
             DATE_SUB(NOW(), INTERVAL 30 DAY), NOW())"
);
$maak->execute(['Speler', $hash, 's@example.com', 1_000_000, 0, 100_000]);
$maak->execute(['Doelwit', $hash, 'd@example.com', 777_777, 222_222, 0]);

$db->exec("INSERT INTO huizen (login,stad) VALUES ('Speler','Brussel'),('Doelwit','Brussel')");

login('Speler', 'eenlangwachtwoord');

// --- Bank ------------------------------------------------------------------

kop('bank: storten en opnemen laten het totaal ongemoeid');

$db->exec("UPDATE users SET zak=100000, bank=0, bc=NULL WHERE login='Speler'");
$voor = totaal_geld($db);

doe('bank.php', ['amount' => '60000', 'in' => '1']);
$db->exec("UPDATE users SET bc=NULL WHERE login='Speler'");
doe('bank.php', ['amount' => '20000', 'out' => '1']);

$u = $db->query("SELECT zak, bank FROM users WHERE login='Speler'")->fetch();

check('zak 60.000 en bank 40.000', (int) $u['zak'] === 60000 && (int) $u['bank'] === 40000,
    json_encode($u));
check('totaal onveranderd', totaal_geld($db) === $voor);

$db->exec("UPDATE users SET bc=NULL WHERE login='Speler'");
$r = doe('bank.php', ['amount' => '999999', 'out' => '1']);
check('te veel opnemen wordt geweigerd', str_contains(melding($r['body']), '[fout]'));

// --- Winkel ----------------------------------------------------------------

kop('winkel: geld verdwijnt uit het spel, precies het bedrag');

$db->exec("UPDATE users SET zak=50000000, wapon=0 WHERE login='Speler'");
$voor = totaal_geld($db);

$r = doe('shop.php', ['actie' => 'koop_item', 'soort' => 'att', 'nr' => '1']);
$na = totaal_geld($db);

check('aankoop gelukt', str_contains(melding($r['body']), '[ok]'), melding($r['body']));
check('totaal daalt met de koopprijs', $voor - $na === 25000, 'verschil ' . ($voor - $na));

// --- Casino ----------------------------------------------------------------

kop('casino: wat de speler verliest komt in de kas');

$db->exec("UPDATE users SET zak=1000000 WHERE login='Speler'");
haal('slots.php');   // maakt het casino aan als het er nog niet is
$db->exec("UPDATE casino SET owner='Doelwit', winst=0, inzet=1000
            WHERE spel='fruitmachine' AND stad='Brussel'");
$db->exec("UPDATE users SET bank=10000000 WHERE login='Doelwit'");

for ($i = 0; $i < 15; $i++) {
    $h = haal('slots.php');
    if (!str_contains($h['body'], 'name="inzet"')) {
        break;
    }
    haal('slots.php', ['_token' => tok($h['body']), 'actie' => 'speel',
        'inzet' => '1000', 'verify' => som($h['body'])]);
}

$zak   = (int) $db->query("SELECT zak FROM users WHERE login='Speler'")->fetchColumn();
$winst = (int) $db->query("SELECT winst FROM casino WHERE spel='fruitmachine'")->fetchColumn();

check('speler plus kas is nog steeds 1.000.000', $zak + $winst === 1_000_000,
    "zak {$zak}, kas {$winst}");

// --- Moord -----------------------------------------------------------------

kop('moord: de buit wordt verplaatst, niet verdubbeld');

$db->exec("UPDATE users SET zak=1000000, bank=0, kogels=100000, health=100, kc=NULL,
                            start=DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE login='Speler'");
$db->exec("UPDATE users SET zak=777777, bank=222222, health=1, defence=0, guard=0,
                            status='levend', testament='', kogels=0, bf=0,
                            start=DATE_SUB(NOW(), INTERVAL 30 DAY) WHERE login='Doelwit'");

$voor = totaal_geld($db);

$h = haal('kill.php?x=Doelwit');
$r = haal('kill.php?x=Doelwit', ['_token' => tok($h['body']), 'victim' => 'Doelwit',
    'kogels' => '50000', 'message' => 'Dag.', 'verify' => som($h['body'])]);

$dood = $db->query("SELECT status, zak, bank FROM users WHERE login='Doelwit'")->fetch();
$na   = totaal_geld($db);

check('doelwit is dood', $dood['status'] === 'dood', melding($r['body']));
check('zak en bank van het slachtoffer op nul',
    (int) $dood['zak'] === 0 && (int) $dood['bank'] === 0, json_encode($dood));
check('totaal daalt met precies het banksaldo', $voor - $na === 222222,
    'verschil ' . ($voor - $na));
check('er is geen geld bijgekomen', $na <= $voor);

// --- Huis --------------------------------------------------------------------

kop('huis: geen gratis huis bij registratie of herstart');

$vraagHuizen = $db->prepare('SELECT COUNT(*) FROM huizen WHERE login = ?');

// Registratie: een gloednieuwe speler krijgt geen huis cadeau dat meteen
// weer verkocht kan worden voor gratis startgeld.
$nieuweLogin = 'Huistest' . random_int(10000, 99999);
doe('register.php', [
    'gebruiker'   => $nieuweLogin,
    'pass'        => 'eenlangwachtwoord',
    'passconfirm' => 'eenlangwachtwoord',
    'email'       => strtolower($nieuweLogin) . '@voorbeeld.test',
    'geslacht'    => 'Man',
], nieuwe_sessie());

$vraagHuizen->execute([$nieuweLogin]);
check('nieuwe speler krijgt geen gratis huis', (int) $vraagHuizen->fetchColumn() === 0);

// Herstart na overlijden: Doelwit is hierboven vermoord. Diezelfde truc mag
// niet herhaalbaar zijn door telkens dood te gaan en opnieuw te beginnen.
$doelwitJar = login('Doelwit', 'eenlangwachtwoord');
doe('rip.php', [], $doelwitJar);

$vraagHuizen->execute(['Doelwit']);
check('herstart na overlijden geeft geen gratis huis', (int) $vraagHuizen->fetchColumn() === 0);

// Registreren en inloggen als Doelwit hebben de gedeelde standaardsessie
// verlegd; de tests hierna verwachten weer Speler als ingelogde speler.
login('Speler', 'eenlangwachtwoord');

// --- Promotie ----------------------------------------------------------------

kop('promotie: bericht ook zonder (betalende) familie');

$db->exec("UPDATE users SET laatste_rang=0, xp=25000, famillie='' WHERE login='Speler'");
$db->exec("DELETE FROM messages WHERE `to`='Speler' AND subject='Promotie'");

haal('home.php');

$aantal = (int) $db->query(
    "SELECT COUNT(*) FROM messages WHERE `to`='Speler' AND subject='Promotie'"
)->fetchColumn();
check('promotiebericht zonder familie', $aantal > 0);

// --- Auto stelen -------------------------------------------------------------

kop('auto stelen: slaagkans daalt nooit als xp stijgt, en neemt af van boven naar onder');

function auto_percentages(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    $percentages = [];
    foreach ($doc->getElementsByTagName('td') as $td) {
        if ($td->getAttribute('class') === 'getal') {
            $percentages[] = (int) rtrim(trim($td->textContent), '%');
        }
    }
    return $percentages;
}

$zetXp  = $db->prepare("UPDATE users SET xp = ? WHERE login = 'Speler'");
$reeksen = [];

foreach ([0, 50, 100, 300, 600, 1000] as $xp) {
    $zetXp->execute([$xp]);
    $reeksen[$xp] = auto_percentages(haal('nickacar.php')['body']);
}

$kolommen = ['parkeerplaats', 'woonwijk', 'tankstation', 'garage van een speler'];

foreach ($kolommen as $index => $naam) {
    $vorige = null;
    $gestegen = false;

    foreach ($reeksen as $xp => $rij) {
        $waarde = $rij[$index] ?? null;
        check($naam . ': percentage aanwezig bij xp ' . $xp, $waarde !== null);

        if ($vorige !== null) {
            check($naam . ': ' . $vorige . '% bij lagere xp stijgt niet naar ' . $waarde . '% bij xp ' . $xp,
                $waarde >= $vorige, $vorige . '% -> ' . $waarde . '%');
            if ($waarde > $vorige) {
                $gestegen = true;
            }
        }
        $vorige = $waarde;
    }

    check($naam . ': stijgt ergens tussen xp 0 en 1000', $gestegen);
}

// De makkelijkste optie staat bovenaan: bij elke xp mag de slaagkans nooit
// hoger zijn verderop in de lijst (parkeerplaats, woonwijk, tankstation,
// garage van een speler — in die volgorde). Bij de vloer (1%, lage xp) en
// het plafond (30%, hoge xp) mogen kolommen gelijk zijn; daartussenin moet
// er minstens één xp-waarde zijn waar het echt afneemt.
foreach ($reeksen as $xp => $rij) {
    for ($i = 1; $i < count($kolommen); $i++) {
        check($kolommen[$i - 1] . ' >= ' . $kolommen[$i] . ' bij xp ' . $xp,
            ($rij[$i - 1] ?? -1) >= ($rij[$i] ?? -1),
            ($rij[$i - 1] ?? '?') . '% vs ' . ($rij[$i] ?? '?') . '%');
    }
}

for ($i = 1; $i < count($kolommen); $i++) {
    $strikt = false;
    foreach ($reeksen as $rij) {
        if (($rij[$i - 1] ?? -1) > ($rij[$i] ?? -1)) {
            $strikt = true;
            break;
        }
    }
    check($kolommen[$i - 1] . ' ligt écht boven ' . $kolommen[$i] . ' bij minstens één xp', $strikt);
}

// --- Wapens: effect --------------------------------------------------------

kop('wapens: effect is een vermenigvuldiger op trefzekerheid, hoger is beter');

$wapens = $db->query(
    "SELECT naam, aprijs, effect FROM items WHERE type='att' ORDER BY aprijs"
)->fetchAll();

check('alle zes wapens staan in de catalogus', count($wapens) === 6, (string) count($wapens));

$vorige = null;
foreach ($wapens as $wapen) {
    if ($vorige !== null) {
        check($vorige['naam'] . ' (€' . number_format((int) $vorige['aprijs'], 0, ',', '.') . ') <= '
            . $wapen['naam'] . ' (€' . number_format((int) $wapen['aprijs'], 0, ',', '.') . ') qua effect',
            (float) $wapen['effect'] >= (float) $vorige['effect'],
            $vorige['effect'] . ' -> ' . $wapen['effect']);
    }
    $vorige = $wapen;
}

$m16    = (float) $db->query("SELECT effect FROM items WHERE naam='M16'")->fetchColumn();
$tommy  = (float) $db->query("SELECT effect FROM items WHERE naam='Tommy Gun'")->fetchColumn();
check('Tommy Gun (140.000) is nu écht effectiever dan de goedkopere M16 (50.000)',
    $tommy > $m16, "M16 {$m16} vs Tommy Gun {$tommy}");

$goedkoopste = (float) $db->query(
    "SELECT effect FROM items WHERE type='att' ORDER BY aprijs LIMIT 1"
)->fetchColumn();
check('zelfs het goedkoopste wapen is beter dan blote handen (effect boven de 1.0-basiswaarde)',
    $goedkoopste > 1.0, (string) $goedkoopste);
// --- Autoafbeeldingen ---------------------------------------------------------

kop('auto stelen: geen kapot plaatje als de afbeelding ontbreekt');

// Level boven LEVEL_ADMIN geeft een vaste slaagkans van 50%, zodat een
// geslaagde diefstal binnen een paar pogingen gegarandeerd is. De afkoeltijd
// wordt voor elke poging losgelaten: op de testserver hoeft niet echt
// gewacht te worden.
$db->exec("UPDATE users SET level=1000 WHERE login='Speler'");

$gelukt = false;
for ($poging = 0; $poging < 20 && !$gelukt; $poging++) {
    $db->exec("UPDATE users SET ac=NULL WHERE login='Speler'");
    $r = doe('nickacar.php', ['waar' => 'parkeerplaats']);
    $gelukt = str_contains(melding($r['body']), '[ok]') && str_contains($r['body'], 'gestolen');
}

check('een autodiefstal is gelukt binnen 20 pogingen', $gelukt);
check('geen kapot plaatje-icoon: geen <img> naar images/autos zonder dat het bestand bestaat',
    !preg_match('#<img src="[^"]*images/autos/[^"]*"#', $r['body']), $r['body']);

$db->exec("UPDATE users SET level=1 WHERE login='Speler'");

// --- Misdaad: opbrengst, kans en xp -----------------------------------------

kop('misdaad: nieuwe speler kan binnen ~50 pogingen het goedkoopste wapen verdienen');

// 50 pogingen bij een afkoeltijd van 60s komt overeen met de bovenkant van
// het beoogde venster van 30-60 minuten actief spelen.
$db->exec("UPDATE users SET xp=0, zak=0, crime=NULL WHERE login='Speler'");
$db->exec("DELETE FROM jail WHERE login='Speler'");

for ($poging = 0; $poging < 50; $poging++) {
    $db->exec("UPDATE users SET crime=NULL WHERE login='Speler'");
    $db->exec("DELETE FROM jail WHERE login='Speler'");
    doe('crime.php', ['crime' => 'juwelier']);
}

$zak = (int) $db->query("SELECT zak FROM users WHERE login='Speler'")->fetchColumn();
$xp  = (int) $db->query("SELECT xp FROM users WHERE login='Speler'")->fetchColumn();

check('goedkoopste wapen (€10.000) is haalbaar binnen 50 pogingen op "beroof een juwelier"',
    $zak >= 10000, 'zak ' . $zak . ', xp ' . $xp);

// --- Rijlessen ---------------------------------------------------------------

kop('rijlessen: een rijbewijs is haalbaar zonder een fortuin uit te geven');

$db->exec("UPDATE users SET zak=300000, rijbewijs=0, rijvord=0, lessen=0,
                            rijbewijstijd=NULL WHERE login='Speler'");

$uitgegeven = 0;
$klaar      = false;

for ($poging = 0; $poging < 40 && !$klaar; $poging++) {
    $lessen = (int) $db->query("SELECT lessen FROM users WHERE login='Speler'")->fetchColumn();

    if ($lessen < 1) {
        doe('rijbewijs.php', ['actie' => 'lessen', 'aantal' => '1']);
        $uitgegeven += 5000;
        continue;
    }

    $db->exec("UPDATE users SET rijbewijstijd=NULL WHERE login='Speler'");
    doe('rijbewijs.php', ['actie' => 'rijden']);
    $klaar = (int) $db->query("SELECT rijbewijs FROM users WHERE login='Speler'")->fetchColumn() === 1;
}

check('rijbewijs gehaald binnen 40 iteraties', $klaar);
check('totale lesgeld blijft onder €150.000', $uitgegeven <= 150000, (string) $uitgegeven);

// --- Voertuigprijzen -----------------------------------------------------------

kop('voertuigen: prijzen passen bij de nieuwe verdiencurve');

$voertuigen = $db->query(
    "SELECT naam, aprijs FROM items WHERE type='trans' ORDER BY aprijs"
)->fetchAll();

$verwacht = [
    'Treinabonnement' => 25000,
    'Privé-Jet'        => 150000,
];

foreach ($voertuigen as $voertuig) {
    if (isset($verwacht[$voertuig['naam']])) {
        check($voertuig['naam'] . ' kost ' . $verwacht[$voertuig['naam']],
            (int) $voertuig['aprijs'] === $verwacht[$voertuig['naam']],
            (string) $voertuig['aprijs']);
    }
}

check('duurste voertuig kost hoogstens €200.000',
    (int) $voertuigen[count($voertuigen) - 1]['aprijs'] <= 200000,
    (string) $voertuigen[count($voertuigen) - 1]['aprijs']);

// --- Klikmissies -------------------------------------------------------------

kop('klikmissies: callback beloont precies het ingestelde bedrag, en niet twee keer binnen de cooldown');

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");
$db->exec(
    "INSERT INTO klikmissies
        (naam, url, heeft_callback, callback_geheim, cooldown_seconden,
         beloning_zak, beloning_bank, beloning_diamanten, actief)
     VALUES ('Testlijst', 'http://127.0.0.1:1/stem?ref={login}', 1, 'geheimtoken123', 86400,
             5000, 2000, 3, 1)"
);
$missieId = (int) $db->lastInsertId();

$db->exec("UPDATE users SET zak=0, bank=0, diamanten=0 WHERE login='Speler'");

$r = haal('klikmissies-callback.php?id=' . $missieId . '&geheim=geheimtoken123&login=Speler');
$u = $db->query("SELECT zak, bank, diamanten FROM users WHERE login='Speler'")->fetch();

check('callback antwoordt OK', trim($r['body']) === 'OK', $r['body']);
check('zak precies 5.000 hoger', (int) $u['zak'] === 5000, 'zak ' . $u['zak']);
check('bank precies 2.000 hoger', (int) $u['bank'] === 2000, 'bank ' . $u['bank']);
check('diamanten precies 3 hoger', (int) $u['diamanten'] === 3, 'diamanten ' . $u['diamanten']);

$diamantLogregel = $db->query(
    "SELECT * FROM logs WHERE area = 'diamant' AND login = 'Speler' ORDER BY id DESC LIMIT 1"
)->fetch();
check('de diamantbeloning staat in het diamantenlog',
    $diamantLogregel !== false && (int) $diamantLogregel['code'] === 3,
    $diamantLogregel === false ? '(geen regel)' : json_encode($diamantLogregel));

$aantalLog = (int) $db->query(
    "SELECT COUNT(*) FROM klikmissies_log
      WHERE klikmissie_id={$missieId} AND login='Speler' AND methode='callback'"
)->fetchColumn();
check('er staat precies één logregel', $aantalLog === 1, (string) $aantalLog);

// Een tweede callback binnen de cooldown mag niets meer bijschrijven.
$r2 = haal('klikmissies-callback.php?id=' . $missieId . '&geheim=geheimtoken123&login=Speler');
$u2 = $db->query("SELECT zak, bank, diamanten FROM users WHERE login='Speler'")->fetch();

check('tweede callback binnen de cooldown wordt geweigerd',
    str_starts_with(trim($r2['body']), 'FOUT'), $r2['body']);
check('en schrijft niets extra bij', $u2 === $u, json_encode($u2));

kop('klikmissies: een verkeerd geheim of onbekende speler beloont niets');

$db->exec("UPDATE users SET zak=0, bank=0, diamanten=0 WHERE login='Speler'");
$db->exec("DELETE FROM klikmissies_log");

$r3 = haal('klikmissies-callback.php?id=' . $missieId . '&geheim=verkeerdtoken&login=Speler');
$u3 = $db->query("SELECT zak, bank, diamanten FROM users WHERE login='Speler'")->fetch();

check('verkeerd geheim wordt geweigerd', str_starts_with(trim($r3['body']), 'FOUT'), $r3['body']);
check('niets bijgeschreven bij een verkeerd geheim',
    (int) $u3['zak'] === 0 && (int) $u3['bank'] === 0 && (int) $u3['diamanten'] === 0, json_encode($u3));

$r4 = haal('klikmissies-callback.php?id=' . $missieId . '&geheim=geheimtoken123&login=Onbekendespeler');
check('onbekende speler wordt geweigerd', str_starts_with(trim($r4['body']), 'FOUT'), $r4['body']);

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");

kop('klikmissies: een leeg callback_geheim beloont niets, ook niet met een leeg geheim in de aanroep');

$db->exec(
    "INSERT INTO klikmissies
        (naam, url, heeft_callback, callback_geheim, cooldown_seconden,
         beloning_zak, beloning_bank, beloning_diamanten, actief)
     VALUES ('Testlijst zonder geheim', 'http://127.0.0.1:1/stem?ref={login}', 1, '', 86400,
             5000, 2000, 3, 1)"
);
$missieIdLeeg = (int) $db->lastInsertId();

$db->exec("UPDATE users SET zak=0, bank=0, diamanten=0 WHERE login='Speler'");

$r5 = haal('klikmissies-callback.php?id=' . $missieIdLeeg . '&geheim=&login=Speler');
$u5 = $db->query("SELECT zak, bank, diamanten FROM users WHERE login='Speler'")->fetch();

check('een leeg callback_geheim wordt geweigerd', str_starts_with(trim($r5['body']), 'FOUT'), $r5['body']);
check('niets bijgeschreven bij een leeg callback_geheim',
    (int) $u5['zak'] === 0 && (int) $u5['bank'] === 0 && (int) $u5['diamanten'] === 0, json_encode($u5));

$aantalLogLeeg = (int) $db->query(
    "SELECT COUNT(*) FROM klikmissies_log WHERE klikmissie_id={$missieIdLeeg}"
)->fetchColumn();
check('er staat geen logregel bij een leeg callback_geheim', $aantalLogLeeg === 0, (string) $aantalLogLeeg);

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");

kop('klikmissies: zelf-bevestigen beloont pas na de wachttijd, en respecteert de cooldown');

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");
// wachttijd_klik staat op 5 seconden, niet 2: haal() volgt redirects (curl
// CURLOPT_FOLLOWLOCATION), dus de POST met actie=stem laat de testclient zelf
// deze onbereikbare url achterna gaan. Op sommige machines (o.a. Windows)
// duurt een "connection refused" op een loopback-poort daardoor zelf al een
// paar seconden, wat een wachttijd van 2 seconden te dicht op de meetfout
// van de test zelf zou zetten.
$db->exec(
    "INSERT INTO klikmissies
        (naam, url, heeft_callback, wachttijd_klik, cooldown_seconden, beloning_zak, actief)
     VALUES ('Testlijst zelf', 'http://127.0.0.1:1/stem?ref={login}', 0, 5, 86400, 5000, 1)"
);
$missieId = (int) $db->lastInsertId();
$db->exec("UPDATE users SET zak=0 WHERE login='Speler'");

login('Speler', 'eenlangwachtwoord');

$h = haal('klikmissies.php');
haal('klikmissies.php', ['_token' => tok($h['body']), 'actie' => 'stem', 'id' => (string) $missieId]);

$h2 = haal('klikmissies.php');
$r4 = haal('klikmissies.php',
    ['_token' => tok($h2['body']), 'actie' => 'bevestig', 'id' => (string) $missieId]);

$u4 = $db->query("SELECT zak FROM users WHERE login='Speler'")->fetch();
check('te vroeg bevestigen beloont niets', (int) $u4['zak'] === 0, 'zak ' . $u4['zak']);
check('met een nette foutmelding', str_contains(melding($r4['body']), '[fout]'), melding($r4['body']));

sleep(5);

$h3 = haal('klikmissies.php');
$r5 = haal('klikmissies.php',
    ['_token' => tok($h3['body']), 'actie' => 'bevestig', 'id' => (string) $missieId]);

$u5 = $db->query("SELECT zak FROM users WHERE login='Speler'")->fetch();
check('na de wachttijd wordt wel beloond', (int) $u5['zak'] === 5000, 'zak ' . $u5['zak']);
check('met een nette bevestiging', str_contains(melding($r5['body']), '[ok]'), melding($r5['body']));

// Nogmaals bevestigen (cooldown loopt nog) mag niets meer opleveren.
haal('klikmissies.php', ['_token' => tok(haal('klikmissies.php')['body']), 'actie' => 'stem',
    'id' => (string) $missieId]);
sleep(5);
$h6 = haal('klikmissies.php');
$r6 = haal('klikmissies.php',
    ['_token' => tok($h6['body']), 'actie' => 'bevestig', 'id' => (string) $missieId]);

$u6 = $db->query("SELECT zak FROM users WHERE login='Speler'")->fetch();
check('een tweede keer binnen de cooldown beloont niets extra',
    (int) $u6['zak'] === 5000, 'zak ' . $u6['zak']);
check('met een foutmelding over de cooldown', str_contains(melding($r6['body']), '[fout]'), melding($r6['body']));

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");

kop('klikmissies: een sessie-klik op een verwijderde missie telt niet mee voor een '
  . 'nieuwe missie die toevallig hetzelfde id krijgt');

$db->exec("ALTER TABLE klikmissies AUTO_INCREMENT = 1");
$db->exec(
    "INSERT INTO klikmissies
        (naam, url, heeft_callback, wachttijd_klik, cooldown_seconden, beloning_zak, actief)
     VALUES ('Oude missie', 'http://127.0.0.1:1/stem?ref={login}', 0, 2, 86400, 5000, 1)"
);
$oudId = (int) $db->lastInsertId();
$db->exec("UPDATE users SET zak=0 WHERE login='Speler'");

login('Speler', 'eenlangwachtwoord');

$h7 = haal('klikmissies.php');
haal('klikmissies.php', ['_token' => tok($h7['body']), 'actie' => 'stem', 'id' => (string) $oudId]);

// De admin verwijdert deze missie en maakt een heel andere aan; door het
// hergebruikte AUTO_INCREMENT krijgt die toevallig hetzelfde id.
$db->exec("DELETE FROM klikmissies WHERE id={$oudId}");
$db->exec("ALTER TABLE klikmissies AUTO_INCREMENT = {$oudId}");
$db->exec(
    "INSERT INTO klikmissies
        (naam, url, heeft_callback, wachttijd_klik, cooldown_seconden, beloning_zak, actief)
     VALUES ('Nieuwe missie', 'http://127.0.0.1:1/stem?ref={login}', 0, 2, 86400, 7000, 1)"
);
$nieuwId = (int) $db->lastInsertId();
check('de vervangende missie heeft inderdaad hetzelfde id', $nieuwId === $oudId, 'nieuw id ' . $nieuwId);

sleep(2);

$h8 = haal('klikmissies.php');
$r7 = haal('klikmissies.php',
    ['_token' => tok($h8['body']), 'actie' => 'bevestig', 'id' => (string) $nieuwId]);

$u7 = $db->query("SELECT zak FROM users WHERE login='Speler'")->fetch();
check('bevestigen zonder op de nieuwe missie gestemd te hebben beloont niets',
    (int) $u7['zak'] === 0, 'zak ' . $u7['zak']);
check('met een nette foutmelding', str_contains(melding($r7['body']), '[fout]'), melding($r7['body']));

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");

login('Speler', 'eenlangwachtwoord');

// --- Cron ------------------------------------------------------------------

kop('cron: alle taken draaien');

$db->exec("UPDATE cron SET time='1970-01-01 00:00:01'");
$r = haal('cron.php?key=testsleutel');

$blijven = (int) $db->query("SELECT COUNT(*) FROM cron WHERE time='1970-01-01 00:00:01'")
    ->fetchColumn();

check('elke taak heeft gedraaid', $blijven === 0, $blijven . ' niet gedraaid');

samenvatting();
