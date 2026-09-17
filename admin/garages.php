<?php
/**
 * De garage van een speler bekijken en corrigeren: welke auto's hij heeft,
 * waar, met hoeveel schade, en of hij "safe" (buiten bereik van stelen,
 * racen en verkopen) staat.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user    = require_level(beheerpaginas()['garages.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'opslaan'     => opslaan($user, int_input('id')),
            'verwijderen' => verwijderen($user, int_input('id')),
            default       => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'garages.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

$gezocht = get('login') !== '' ? get('login') : post('naam');

if ($gezocht !== '') {
    toon_garage($gezocht);
}

toon_zoekformulier();

beheer_footer();

// ==========================================================================

function toon_zoekformulier(): void
{
    panel_open('Garage opzoeken');
    echo '<form method="post">' . csrf_field();
    echo '<div class="veldenraster">';
    echo '<label for="naam">Gebruikersnaam</label>';
    echo '<input id="naam" name="naam" maxlength="16" required>';
    echo '<span></span><button type="submit">Zoeken</button>';
    echo '</div></form>';
    panel_close();
}

function toon_garage(string $login): void
{
    $speler = q_row('SELECT `login` FROM `users` WHERE `login` = ?', [$login]);

    if ($speler === null) {
        panel_open('Zoekresultaat');
        notice('Die speler bestaat niet.', 'fout');
        panel_close();
        return;
    }

    $wagens = q_all('SELECT * FROM `garage` WHERE `login` = ? ORDER BY `id`', [$speler['login']]);

    panel_open('Garage van ' . $speler['login']);

    if ($wagens === []) {
        echo '<p>Deze speler heeft geen auto\'s.</p>';
        panel_close();
        return;
    }

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Naam</th><th>Stad</th><th class="getal">Waarde</th>'
       . '<th class="getal">Schade</th><th>Safe</th><th></th></tr></thead><tbody>';

    foreach ($wagens as $wagen) {
        echo '<tr><form method="post" style="display:contents">' . csrf_field()
           . '<input type="hidden" name="id" value="' . (int) $wagen['id'] . '">'
           . '<input type="hidden" name="login" value="' . e($speler['login']) . '">';
        echo '<td><input name="naam" value="' . e((string) $wagen['naam']) . '" maxlength="64"></td>';
        echo '<td><select name="stad">';
        foreach (cities() as $stad) {
            echo '<option' . ($stad === $wagen['stad'] ? ' selected' : '') . '>' . e($stad) . '</option>';
        }
        echo '</select></td>';
        echo '<td><input name="waarde" value="' . (int) $wagen['waarde'] . '" size="10" inputmode="numeric"></td>';
        echo '<td><input name="damage" value="' . (int) $wagen['damage']
           . '" size="4" inputmode="numeric" min="0" max="100">%</td>';
        echo '<td><input type="checkbox" name="safe" value="1"'
           . ((int) $wagen['safe'] === 1 ? ' checked' : '') . '></td>';
        echo '<td>'
           . '<button type="submit" name="actie" value="opslaan">Opslaan</button> '
           . '<button type="submit" name="actie" value="verwijderen">Verwijderen</button>'
           . '</td>';
        echo '</form></tr>';
    }

    echo '</tbody></table></div>';
    echo '<p class="uitleg">De naam moet overeenkomen met een auto uit de '
       . '<a href="' . e(beheer_url('autos.php')) . '">autocatalogus</a>, anders is bij '
       . 'repareren de nieuwprijs niet op te zoeken en kost repareren dan niets. '
       . 'Verwijderen haalt de auto weg zonder de speler te compenseren.</p>';

    panel_close();
}

/** @throws SpelFout */
function opslaan(array $user, int $id): string
{
    $wagen = q_row('SELECT * FROM `garage` WHERE `id` = ?', [$id]);

    if ($wagen === null) {
        throw new SpelFout('Die auto bestaat niet.');
    }

    $naam   = trim(post('naam'));
    $stad   = post('stad');
    $waarde = int_input('waarde', -1);
    $damage = int_input('damage', -1);
    $safe   = post('safe') === '1' ? 1 : 0;

    if ($naam === '') {
        throw new SpelFout('Vul een naam in.');
    }
    if (!is_city($stad)) {
        throw new SpelFout('Die stad bestaat niet.');
    }
    if ($waarde < 0) {
        throw new SpelFout('De waarde mag niet negatief zijn.');
    }
    if ($damage < 0 || $damage > 100) {
        throw new SpelFout('De schade moet tussen 0 en 100 liggen.');
    }

    q(
        'UPDATE `garage` SET `naam` = ?, `stad` = ?, `waarde` = ?, `damage` = ?, `safe` = ?
          WHERE `id` = ?',
        [mb_substr($naam, 0, 64), $stad, $waarde, $damage, $safe, $id]
    );

    log_action((string) $user['login'], 'garage',
        'Auto bijgewerkt: ' . $naam, $waarde, (string) $wagen['login']);

    return 'De auto is bijgewerkt.';
}

/** @throws SpelFout */
function verwijderen(array $user, int $id): string
{
    $wagen = q_row('SELECT * FROM `garage` WHERE `id` = ?', [$id]);

    if ($wagen === null) {
        throw new SpelFout('Die auto bestaat niet.');
    }

    q('DELETE FROM `garage` WHERE `id` = ?', [$id]);

    log_action((string) $user['login'], 'garage',
        'Auto verwijderd: ' . $wagen['naam'], 0, (string) $wagen['login']);

    return 'De auto is verwijderd.';
}
