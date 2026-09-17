<?php
/**
 * Gevangenisbeheer: spelers opsluiten of vrijlaten.
 *
 * Wat hier gerepareerd is: vrijlaten ging via een GET-link
 * (adm-prison.php?x=Naam), er zat geen CSRF-bescherming op, en de straftijd
 * werd berekend uit variabelen die in het oude config.php gezet werden.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user    = require_level(beheerpaginas()['prison.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'opsluiten' => opsluiten($user, post('naam'), int_input('minuten', 0)),
            'vrijlaten' => vrijlaten($user, post('naam')),
            default     => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'prison.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('Iemand opsluiten');
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="opsluiten">';
echo '<div class="veldenraster">';
echo '<label for="naam">Speler</label>';
echo '<input id="naam" name="naam" maxlength="16" required>';
echo '<label for="minuten">Duur in minuten</label>';
echo '<input id="minuten" name="minuten" type="number" min="1" max="1440" step="1" value="10" required>';
echo '<span></span><button type="submit">Opsluiten</button>';
echo '</div></form>';
panel_close();

$cellen = q_all(
    'SELECT j.*, UNIX_TIMESTAMP(j.`time`) AS `tot_ts`, u.`level`
       FROM `jail` j LEFT JOIN `users` u ON u.`login` = j.`login`
      WHERE j.`time` > NOW() ORDER BY j.`time`'
);

panel_open('In de gevangenis (' . count($cellen) . ')');

if ($cellen === []) {
    echo '<p>Er zit niemand vast.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Speler</th><th>Stad</th><th class="getal">Boete</th>'
       . '<th>Nog</th><th></th></tr></thead><tbody>';

    foreach ($cellen as $cel) {
        $over = max(0, (int) $cel['tot_ts'] - time());

        echo '<tr>';
        echo '<td><a' . speler_rol_attr((string) $cel['login']) . ' href="'
           . e(beheer_url('search.php?login=' . rawurlencode((string) $cel['login'])))
           . '">' . e((string) $cel['login']) . '</a></td>';
        echo '<td>' . e((string) $cel['stad']) . '</td>';
        echo '<td class="getal">' . money((int) $cel['boete']) . '</td>';
        echo '<td><span data-tot="' . (int) $cel['tot_ts'] . '">' . e(duration($over)) . '</span></td>';
        echo '<td><form method="post" style="margin:0">' . csrf_field()
           . '<input type="hidden" name="actie" value="vrijlaten">'
           . '<input type="hidden" name="naam" value="' . e((string) $cel['login']) . '">'
           . '<button type="submit">Vrijlaten</button></form></td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

panel_close();

beheer_logregels('gevangenis');

beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function opsluiten(array $user, string $naam, int $minuten): string
{
    if ($minuten < 1 || $minuten > 1440) {
        throw new SpelFout('Kies een duur tussen 1 en 1440 minuten.');
    }

    $speler = beheer_speler($naam);

    if ((int) $speler['level'] >= (int) $user['level']) {
        throw new SpelFout('Je kunt geen speler met gelijke of hogere rechten opsluiten.');
    }
    if ($speler['status'] !== 'levend') {
        throw new SpelFout('Die speler is dood.');
    }

    $sentence = jail_sentence((int) $speler['xp']);

    q(
        'INSERT INTO `jail` (`login`, `boete`, `stad`, `famillie`, `time`)
              VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
         ON DUPLICATE KEY UPDATE `time` = VALUES(`time`), `boete` = VALUES(`boete`)',
        [$speler['login'], $sentence['boete'], $speler['stad'], $speler['famillie'], $minuten]
    );

    notify((string) $speler['login'], 'Gevangenis',
        'Je bent door het beheer opgesloten voor ' . $minuten . ' minuten.');

    log_action((string) $user['login'], 'gevangenis',
        'Opgesloten voor ' . $minuten . ' minuten', 0, (string) $speler['login']);

    return $speler['login'] . ' zit nu ' . $minuten . ' minuten vast.';
}

/** @throws SpelFout */
function vrijlaten(array $user, string $naam): string
{
    if (q_count('DELETE FROM `jail` WHERE `login` = ?', [$naam]) === 0) {
        throw new SpelFout('Die speler zit niet vast.');
    }

    notify($naam, 'Gevangenis', 'Je bent door het beheer vrijgelaten.');
    log_action((string) $user['login'], 'gevangenis', 'Vrijgelaten', 0, $naam);

    return $naam . ' is vrijgelaten.';
}
