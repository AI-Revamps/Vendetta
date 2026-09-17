<?php
/**
 * Wall of Shame: spelers die betrapt zijn op vals spel.
 *
 * Wat hier gerepareerd is: verwijderen ging via een GET-link, de toelichting
 * ging ongefilterd in de query, en er werd niet gecontroleerd of de genoemde
 * speler bestond.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user    = require_level(beheerpaginas()['shame.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'toevoegen'   => toevoegen($user, post('naam'), post('com')),
            'verwijderen' => verwijderen(int_input('id')),
            default       => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'shame.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('Iemand op de schandpaal zetten');
echo '<p>De vermelding is voor alle spelers zichtbaar op de schandpaal.</p>';
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="toevoegen">';
echo '<div class="veldenraster">';
echo '<label for="naam">Speler</label>';
echo '<input id="naam" name="naam" maxlength="16" required>';
echo '<label for="com">Toelichting</label>';
echo '<input id="com" name="com" maxlength="255" required>';
echo '<span></span><button type="submit">Toevoegen</button>';
echo '</div></form>';
panel_close();

$lijst = q_all('SELECT * FROM `shame` ORDER BY `time` DESC LIMIT 100');

panel_open('Op de schandpaal (' . count($lijst) . ')');

if ($lijst === []) {
    echo '<p>De schandpaal is leeg.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Wanneer</th><th>Speler</th><th>Toelichting</th><th>Door</th><th></th></tr></thead><tbody>';

    foreach ($lijst as $rij) {
        echo '<tr>';
        echo '<td>' . e(datetime_nl($rij['time'])) . '</td>';
        echo '<td>' . speler_naam((string) $rij['cheater']) . '</td>';
        echo '<td>' . e((string) $rij['com']) . '</td>';
        echo '<td>' . speler_naam((string) $rij['person']) . '</td>';
        echo '<td><form method="post" style="margin:0">' . csrf_field()
           . '<input type="hidden" name="actie" value="verwijderen">'
           . '<input type="hidden" name="id" value="' . (int) $rij['id'] . '">'
           . '<button type="submit">Verwijderen</button></form></td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

panel_close();
beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function toevoegen(array $user, string $naam, string $com): string
{
    $com = trim($com);

    if ($com === '') {
        throw new SpelFout('Vul een toelichting in.');
    }

    $speler = beheer_speler($naam);

    if ((int) $speler['level'] >= (int) $user['level']) {
        throw new SpelFout('Je kunt geen speler met gelijke of hogere rechten op de schandpaal zetten.');
    }

    $bestaat = (int) q_val('SELECT COUNT(*) FROM `shame` WHERE `cheater` = ?', [$speler['login']], 0);

    if ($bestaat > 0) {
        throw new SpelFout($speler['login'] . ' staat al op de schandpaal.');
    }

    q('INSERT INTO `shame` (`time`, `cheater`, `person`, `com`) VALUES (NOW(), ?, ?, ?)',
        [$speler['login'], $user['login'], mb_substr($com, 0, 255)]);

    notify((string) $speler['login'], 'Schandpaal',
        'Je bent op de schandpaal gezet. Reden: ' . $com);

    log_action((string) $user['login'], 'schandpaal', $com, 0, (string) $speler['login']);

    return $speler['login'] . ' staat nu op de schandpaal.';
}

/** @throws SpelFout */
function verwijderen(int $id): string
{
    if (q_count('DELETE FROM `shame` WHERE `id` = ?', [$id]) === 0) {
        throw new SpelFout('Die vermelding bestaat niet.');
    }

    return 'De vermelding is verwijderd.';
}
