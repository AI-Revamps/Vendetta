<?php
/**
 * Items beheren: wapens, bescherming en vervoer uit de winkel.
 *
 * Wat hier gerepareerd is: alle velden gingen ongefilterd in de query, het
 * soort item werd niet gecontroleerd tegen de toegestane waarden, en er was
 * geen CSRF-bescherming. Ook kon je twee items met hetzelfde soort en nummer
 * aanmaken, waarna de winkel er willekeurig een van koos.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

/** De soorten items en hun betekenis. */
function itemsoorten(): array
{
    return [
        'att'   => 'Wapen',
        'def'   => 'Bescherming',
        'trans' => 'Vervoer',
    ];
}

$user    = require_level(beheerpaginas()['items.php'][1]);
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

beheer_header($user, 'items.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

foreach (itemsoorten() as $soort => $label) {
    $items = q_all('SELECT * FROM `items` WHERE `type` = ? ORDER BY `nr`', [$soort]);

    panel_open($label);

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th class="getal">Nr</th><th>Naam</th><th class="getal">Koop</th>'
       . '<th class="getal">Verkoop</th><th class="getal">Effect</th><th></th></tr></thead><tbody>';

    foreach ($items as $item) {
        echo '<tr><form method="post" style="display:contents">' . csrf_field()
           . '<input type="hidden" name="actie" value="opslaan">'
           . '<input type="hidden" name="id" value="' . (int) $item['id'] . '">'
           . '<input type="hidden" name="type" value="' . e($soort) . '">';
        echo '<td><input name="nr" value="' . (int) $item['nr'] . '" size="3" inputmode="numeric"></td>';
        echo '<td><input name="naam" value="' . e((string) $item['naam']) . '" maxlength="32"></td>';
        echo '<td><input name="aprijs" value="' . (int) $item['aprijs'] . '" size="10" inputmode="numeric"></td>';
        echo '<td><input name="vprijs" value="' . (int) $item['vprijs'] . '" size="10" inputmode="numeric"></td>';
        echo '<td><input name="effect" value="' . e((string) $item['effect']) . '" size="8"></td>';
        echo '<td><button type="submit">Opslaan</button></td>';
        echo '</form></tr>';
    }

    echo '</tbody></table></div>';

    echo '<h3>Nieuw item</h3>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="toevoegen">';
    echo '<input type="hidden" name="type" value="' . e($soort) . '">';
    echo '<div class="veldenraster">';
    echo '<label>Nummer</label><input name="nr" inputmode="numeric" required>';
    echo '<label>Naam</label><input name="naam" maxlength="32" required>';
    echo '<label>Koopprijs</label><input name="aprijs" inputmode="numeric" required>';
    echo '<label>Verkoopprijs</label><input name="vprijs" inputmode="numeric" required>';
    echo '<label>Effect</label><input name="effect" required>';
    echo '<span></span><button type="submit">Toevoegen</button>';
    echo '</div></form>';

    panel_close();
}

echo '<p class="uitleg">Bij wapens en bescherming is een hoger effect beter: het is een '
   . 'vermenigvuldiger op respectievelijk trefzekerheid en weerstand. Bij vervoer is het '
   . 'effect de reistijd in seconden — daar is lager beter.</p>';

beheer_footer();

// ==========================================================================

/**
 * Sla een item op. Met id 0 wordt er een nieuw aangemaakt.
 *
 * @throws SpelFout
 */
function opslaan(int $id): string
{
    $soort = post('type');

    if (!isset(itemsoorten()[$soort])) {
        throw new SpelFout('Dat soort item bestaat niet.');
    }

    $nr     = int_input('nr', -1);
    $naam   = trim(post('naam'));
    $aprijs = int_input('aprijs', -1);
    $vprijs = int_input('vprijs', -1);
    $effect = trim(post('effect'));

    if ($nr < 0 || $nr > 99) {
        throw new SpelFout('Het nummer moet tussen 0 en 99 liggen.');
    }
    if ($naam === '') {
        throw new SpelFout('Vul een naam in.');
    }
    if ($aprijs < 0 || $vprijs < 0) {
        throw new SpelFout('De prijzen mogen niet negatief zijn.');
    }
    if ($vprijs > $aprijs) {
        throw new SpelFout('De verkoopprijs mag niet hoger zijn dan de koopprijs; '
            . 'dan levert kopen en meteen verkopen geld op.');
    }
    if (!preg_match('/^\d{1,8}([.,]\d{1,2})?$/', $effect)) {
        throw new SpelFout('Het effect moet een getal zijn.');
    }

    $effect = (float) str_replace(',', '.', $effect);

    // Soort en nummer samen moeten uniek zijn: de winkel zoekt daarop.
    $botsing = (int) q_val(
        'SELECT COUNT(*) FROM `items` WHERE `type` = ? AND `nr` = ? AND `id` <> ?',
        [$soort, $nr, $id],
        0
    );

    if ($botsing > 0) {
        throw new SpelFout('Er bestaat al een item van dit soort met nummer ' . $nr . '.');
    }

    if ($id > 0) {
        q('UPDATE `items` SET `nr` = ?, `naam` = ?, `aprijs` = ?, `vprijs` = ?, `effect` = ?
            WHERE `id` = ? AND `type` = ?',
            [$nr, mb_substr($naam, 0, 32), $aprijs, $vprijs, $effect, $id, $soort]);

        return 'Het item is bijgewerkt.';
    }

    q('INSERT INTO `items` (`nr`, `type`, `naam`, `aprijs`, `vprijs`, `effect`)
            VALUES (?, ?, ?, ?, ?, ?)',
        [$nr, $soort, mb_substr($naam, 0, 32), $aprijs, $vprijs, $effect]);

    return 'Het item is toegevoegd.';
}

/** @throws SpelFout */
function verwijderen(int $id): string
{
    if (q_count('DELETE FROM `items` WHERE `id` = ?', [$id]) === 0) {
        throw new SpelFout('Dat item bestaat niet.');
    }

    return 'Het item is verwijderd.';
}
