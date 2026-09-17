<?php
/**
 * De autocatalogus beheren: welke auto's er te stelen zijn, wat ze waard
 * zijn, en welk plaatje erbij hoort.
 *
 * `naam` is de sleutel waarmee garage.php en carrace.php de actuele
 * nieuwprijs opzoeken voor een auto die een speler al in zijn garage heeft
 * (bijv. bij repareren). Wijzig je die naam op een bestaande rij, dan vindt
 * dat opzoeken voor auto's die spelers al bezitten niets meer — de nieuwprijs
 * valt dan terug op 0. `auto` is alleen voor de weergave (bijvoorbeeld in de
 * tekst bij het stelen van een auto).
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user    = require_level(beheerpaginas()['autos.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'opslaan'     => opslaan(int_input('id')),
            'toevoegen'   => opslaan(0),
            'verwijderen' => verwijderen(int_input('id')),
            default       => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'autos.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

$autos = q_all('SELECT * FROM `cars` ORDER BY `waarde`');

panel_open('Auto\'s');

echo '<div class="tabelwikkel"><table class="lijst">';
echo '<thead><tr><th>Merk/model</th><th>Naam (in garages)</th><th>Plaatje</th>'
   . '<th class="getal">Waarde</th><th></th></tr></thead><tbody>';

foreach ($autos as $auto) {
    echo '<tr><form method="post" style="display:contents">' . csrf_field()
       . '<input type="hidden" name="actie" value="opslaan">'
       . '<input type="hidden" name="id" value="' . (int) $auto['id'] . '">';
    echo '<td><input name="auto" value="' . e((string) $auto['auto']) . '" maxlength="64"></td>';
    echo '<td><input name="naam" value="' . e((string) $auto['naam']) . '" maxlength="64"></td>';
    echo '<td><input name="url" value="' . e((string) $auto['url']) . '" maxlength="255" size="24"></td>';
    echo '<td><input name="waarde" value="' . (int) $auto['waarde'] . '" size="10" inputmode="numeric"></td>';
    echo '<td><button type="submit">Opslaan</button></td>';
    echo '</form></tr>';
}

echo '</tbody></table></div>';

echo '<h3>Nieuwe auto</h3>';
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="toevoegen">';
echo '<div class="veldenraster">';
echo '<label>Merk/model</label><input name="auto" maxlength="64" required>';
echo '<label>Naam (in garages)</label><input name="naam" maxlength="64" required>';
echo '<label>Plaatje (pad vanaf de hoofdmap)</label><input name="url" maxlength="255" placeholder="images/autos/voorbeeld.jpg">';
echo '<label>Waarde</label><input name="waarde" inputmode="numeric" required>';
echo '<span></span><button type="submit">Toevoegen</button>';
echo '</div></form>';

panel_close();

echo '<p class="uitleg">Het plaatje mag ontbreken — komt het bestand niet voor, dan tonen '
   . 'de spelerspagina\'s gewoon geen afbeelding in plaats van een kapot plaatje-icoon. '
   . 'Verwijderen laat auto\'s die spelers al bezitten met rust: die blijven gewoon in hun '
   . 'garage staan, alleen de nieuwprijs kan dan niet meer worden opgezocht bij repareren.</p>';

beheer_footer();

// ==========================================================================

/**
 * Sla een auto op. Met id 0 wordt er een nieuwe aangemaakt.
 *
 * @throws SpelFout
 */
function opslaan(int $id): string
{
    $auto   = trim(post('auto'));
    $naam   = trim(post('naam'));
    $url    = trim(post('url'));
    $waarde = int_input('waarde', -1);

    if ($auto === '') {
        throw new SpelFout('Vul een merk/model in.');
    }
    if ($naam === '') {
        throw new SpelFout('Vul een naam in.');
    }
    if ($waarde < 0) {
        throw new SpelFout('De waarde mag niet negatief zijn.');
    }

    // De naam is de sleutel waarmee andere pagina's een auto terugvinden;
    // twee auto's met dezelfde naam zouden daar met elkaar verward worden.
    $botsing = (int) q_val(
        'SELECT COUNT(*) FROM `cars` WHERE `naam` = ? AND `id` <> ?',
        [$naam, $id],
        0
    );

    if ($botsing > 0) {
        throw new SpelFout('Er bestaat al een auto met deze naam.');
    }

    $auto = mb_substr($auto, 0, 64);
    $naam = mb_substr($naam, 0, 64);
    $url  = mb_substr($url, 0, 255);

    if ($id > 0) {
        q('UPDATE `cars` SET `auto` = ?, `naam` = ?, `url` = ?, `waarde` = ? WHERE `id` = ?',
            [$auto, $naam, $url, $waarde, $id]);

        return 'De auto is bijgewerkt.';
    }

    q('INSERT INTO `cars` (`auto`, `naam`, `url`, `waarde`) VALUES (?, ?, ?, ?)',
        [$auto, $naam, $url, $waarde]);

    return 'De auto is toegevoegd.';
}

/** @throws SpelFout */
function verwijderen(int $id): string
{
    if (q_count('DELETE FROM `cars` WHERE `id` = ?', [$id]) === 0) {
        throw new SpelFout('Die auto bestaat niet.');
    }

    return 'De auto is verwijderd.';
}
