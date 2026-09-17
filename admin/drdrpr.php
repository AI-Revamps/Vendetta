<?php
/**
 * Drank- en drugsprijzen per stad.
 *
 * Wat hier gerepareerd is:
 *
 *  - De rechtencontrole (`level < 200`) stond boven de HTML, terwijl de pagina
 *    zelf voor de eigenaar bedoeld was. Nu een niveau, uit inc/beheer.php.
 *  - Acht bijna identieke blokken van twintig regels, met per stad een eigen
 *    variabelenaam. Ging er een stad bij, dan moest je op vier plaatsen
 *    plakken. Nu een lus over cities().
 *  - De stadsnaam stond hardcoded in de query, dus een stad die niet in de
 *    tabel `stad` stond werd stil overgeslagen: geen fout, geen prijs.
 *  - De prijzen werden alleen willekeurig getrokken; een prijs zelf zetten kon
 *    niet, terwijl dat juist is wat je bij een beheerpagina wilt.
 *  - `$msg` werd bij de eerste stad met `=` gezet en daarna met `"$msg ..."`
 *    aangevuld. Sloeg je de eerste stad over, dan stond er "Notice: undefined
 *    variable" in de melding.
 *
 * De uurlijkse crontaak trekt deze prijzen sowieso opnieuw; deze pagina is er
 * om tussendoor bij te sturen.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

/** Dezelfde marges als de crontaak in inc/cron.php gebruikt. */
const DRUGS_MIN = 6000;
const DRUGS_MAX = 15000;
const DRANK_MIN = 1000;
const DRANK_MAX = 6000;

$user    = require_level(beheerpaginas()['drdrpr.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'opslaan'    => opslaan($user, post('stad')),
            'vernieuwen' => vernieuwen($user, post('stad')),
            'alles'      => alles_vernieuwen($user),
            'aanmaken'   => aanmaken($user, post('stad')),
            default      => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'drdrpr.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

$steden = q_all('SELECT * FROM `stad` ORDER BY `stad`');

panel_open('Prijzen per stad');

echo '<p>De uurlijkse taak trekt drugs tussen ' . money(DRUGS_MIN) . ' en ' . money(DRUGS_MAX)
   . ' en drank tussen ' . money(DRANK_MIN) . ' en ' . money(DRANK_MAX)
   . '. Wat je hier zelf zet blijft staan tot de volgende trekking.</p>';

echo '<div class="tabelwikkel"><table class="lijst">';
echo '<thead><tr><th>Stad</th><th class="getal">Drugsprijs</th><th class="getal">Drankprijs</th>'
   . '<th class="getal">Drugs</th><th class="getal">Drank</th><th class="getal">Reisprijs</th>'
   . '<th></th></tr></thead><tbody>';

foreach ($steden as $stad) {
    $naam = (string) $stad['stad'];

    echo '<tr><form method="post" style="display:contents">' . csrf_field();
    echo '<input type="hidden" name="stad" value="' . e($naam) . '">';
    echo '<td>' . e($naam) . (is_city($naam) ? '' : ' <em>(niet in de configuratie)</em>') . '</td>';
    echo '<td><input name="drugsp" value="' . (int) $stad['drugsp'] . '" size="8" inputmode="numeric"></td>';
    echo '<td><input name="drankp" value="' . (int) $stad['drankp'] . '" size="8" inputmode="numeric"></td>';
    echo '<td><input name="drugs" value="' . (int) $stad['drugs'] . '" size="5" inputmode="numeric"></td>';
    echo '<td><input name="drank" value="' . (int) $stad['drank'] . '" size="5" inputmode="numeric"></td>';
    echo '<td><input name="transp" value="' . (int) $stad['transp'] . '" size="7" inputmode="numeric"></td>';
    echo '<td><button type="submit" name="actie" value="opslaan">Opslaan</button> '
       . '<button type="submit" name="actie" value="vernieuwen">Loten</button></td>';
    echo '</form></tr>';
}

echo '</tbody></table></div>';

echo '<p class="uitleg">De reisprijs is wat het kost om met transport naar die stad te '
   . 'gaan. Een stad die niet in de configuratie staat is geen bestemming en telt nergens '
   . 'mee; die kun je met een gerust hart laten staan of uit de database verwijderen.</p>';

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="alles">';
echo '<button type="submit">Alle steden opnieuw loten</button>';
echo '</form>';

panel_close();

$ontbreekt = array_values(array_diff(cities(), array_column($steden, 'stad')));

if ($ontbreekt !== []) {
    panel_open('Nog aan te maken');
    echo '<p>Deze steden staan in <code>inc/config.php</code> maar nog niet in de database. '
       . 'Zolang dat zo is valt er niets te kopen of te verkopen en kun je er niet heen '
       . 'reizen. Eén klik maakt ze aan met standaardwaarden, die je hierboven kunt '
       . 'bijstellen.</p>';

    echo '<div class="tabelwikkel"><table class="lijst"><tbody>';
    foreach ($ontbreekt as $naam) {
        echo '<tr><td>' . e($naam) . '</td><td>'
           . '<form method="post" style="margin:0">' . csrf_field()
           . '<input type="hidden" name="actie" value="aanmaken">'
           . '<input type="hidden" name="stad" value="' . e($naam) . '">'
           . '<button type="submit">Aanmaken</button></form></td></tr>';
    }
    echo '</tbody></table></div>';
    panel_close();
}

panel_open('Een stad toevoegen of hernoemen');

echo '<p>Steden komen uit <code>inc/config.php</code>, bij <code>game.cities</code>. '
   . 'Zet de naam daar in de lijst en maak hem hierboven aan; verder is er niets nodig.</p>';

echo '<p><strong>Hernoemen</strong> vraagt wel om een handeling in de database, want er '
   . 'staan spelers, huizen en families in die stad. Pas de naam aan in de configuratie en '
   . 'draai daarna in phpMyAdmin:</p>';

echo '<pre class="codeblok">'
   . e("UPDATE `stad`     SET `stad` = 'Nieuw' WHERE `stad` = 'Oud';\n"
     . "UPDATE `users`    SET `stad` = 'Nieuw' WHERE `stad` = 'Oud';\n"
     . "UPDATE `huizen`   SET `stad` = 'Nieuw' WHERE `stad` = 'Oud';\n"
     . "UPDATE `famillie` SET `stad` = 'Nieuw' WHERE `stad` = 'Oud';\n"
     . "UPDATE `casino`   SET `stad` = 'Nieuw' WHERE `stad` = 'Oud';\n"
     . "UPDATE `garage`   SET `stad` = 'Nieuw' WHERE `stad` = 'Oud';\n"
     . "UPDATE `jail`     SET `stad` = 'Nieuw' WHERE `stad` = 'Oud';")
   . '</pre>';

echo '<p class="uitleg">Een stad wég halen doe je alleen als er niemand meer woont. '
   . 'Haal hem uit de configuratie, verplaats de achterblijvers met '
   . '<code>UPDATE `users` SET `stad` = \'Elders\' WHERE `stad` = \'Weg\'</code> en '
   . 'verwijder daarna de rij.</p>';

panel_close();

beheer_logregels('prijzen');

beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function opslaan(array $user, string $stad): string
{
    controleer_stad($stad);

    $drugsp = int_input('drugsp', -1);
    $drankp = int_input('drankp', -1);
    $drugs  = int_input('drugs', -1);
    $drank  = int_input('drank', -1);
    $transp = int_input('transp', -1);

    if ($drugsp < 1 || $drankp < 1) {
        throw new SpelFout('Een prijs moet minstens 1 zijn; op 0 zou alles gratis worden.');
    }
    if ($drugsp > 10_000_000 || $drankp > 10_000_000) {
        throw new SpelFout('Die prijs is onrealistisch hoog.');
    }
    if ($drugs < 0 || $drank < 0 || $drugs > 100_000 || $drank > 100_000) {
        throw new SpelFout('De voorraad moet tussen 0 en 100.000 liggen.');
    }
    if ($transp < 1 || $transp > 10_000_000) {
        throw new SpelFout('De reisprijs moet tussen 1 en 10.000.000 liggen.');
    }

    q('UPDATE `stad` SET `drugsp` = ?, `drankp` = ?, `drugs` = ?, `drank` = ?, `transp` = ?
        WHERE `stad` = ?',
        [$drugsp, $drankp, $drugs, $drank, $transp, $stad]);

    log_action((string) $user['login'], 'prijzen',
        $stad . ': drugs ' . money($drugsp) . ', drank ' . money($drankp)
        . ', reis ' . money($transp), 0, '');

    return 'De prijzen van ' . $stad . ' zijn opgeslagen.';
}

/** @throws SpelFout */
function vernieuwen(array $user, string $stad): string
{
    controleer_stad($stad);

    $drugsp = random_int(DRUGS_MIN, DRUGS_MAX);
    $drankp = random_int(DRANK_MIN, DRANK_MAX);

    q('UPDATE `stad` SET `drugsp` = ?, `drankp` = ? WHERE `stad` = ?',
        [$drugsp, $drankp, $stad]);

    log_action((string) $user['login'], 'prijzen',
        $stad . ' opnieuw geloot: drugs ' . money($drugsp) . ', drank ' . money($drankp), 0, '');

    return $stad . ' heeft nieuwe prijzen: drugs ' . money($drugsp) . ', drank ' . money($drankp) . '.';
}

function alles_vernieuwen(array $user): string
{
    // Een RAND() per rij, zodat niet elke stad dezelfde prijs krijgt.
    $aantal = q_count(
        'UPDATE `stad`
            SET `drugsp` = FLOOR(? + RAND() * ?),
                `drankp` = FLOOR(? + RAND() * ?)',
        [DRUGS_MIN, DRUGS_MAX - DRUGS_MIN, DRANK_MIN, DRANK_MAX - DRANK_MIN]
    );

    log_action((string) $user['login'], 'prijzen', 'Alle steden opnieuw geloot', 0, '');

    return 'Er zijn nieuwe prijzen geloot voor ' . num($aantal)
         . ($aantal === 1 ? ' stad.' : ' steden.');
}

/**
 * Maak een stad aan die wel in de configuratie staat maar nog niet in de
 * database. Prijzen krijgen meteen een eerste trekking, zodat de stad niet met
 * de standaardwaarde van iedereen begint.
 *
 * @throws SpelFout
 */
function aanmaken(array $user, string $stad): string
{
    if (!is_city($stad)) {
        throw new SpelFout('Die stad staat niet in inc/config.php. Zet hem daar eerst '
            . 'in de lijst bij game.cities.');
    }
    if (q_val('SELECT `stad` FROM `stad` WHERE `stad` = ?', [$stad]) !== null) {
        throw new SpelFout('Die stad bestaat al in de database.');
    }

    $drugsp = random_int(DRUGS_MIN, DRUGS_MAX);
    $drankp = random_int(DRANK_MIN, DRANK_MAX);

    q(
        'INSERT INTO `stad` (`stad`, `kogels`, `prijs`, `drugs`, `drank`,
                             `drugsp`, `drankp`, `transp`, `grond`)
              VALUES (?, 100, 1273, 50, 50, ?, ?, 2000, 1000)',
        [$stad, $drugsp, $drankp]
    );

    log_action((string) $user['login'], 'prijzen', 'Stad aangemaakt: ' . $stad, 0, '');

    return $stad . ' is aangemaakt met een reisprijs van ' . money(2000)
         . '. Pas die hierboven aan als je wilt.';
}

/** @throws SpelFout */
function controleer_stad(string $stad): void
{
    if (q_val('SELECT `stad` FROM `stad` WHERE `stad` = ?', [$stad]) === null) {
        throw new SpelFout('Die stad staat niet in de database.');
    }
}
