<?php
/**
 * Verbanningen beheren: op IP-adres of op gebruikersnaam.
 *
 * Wat hier gerepareerd is: de reden ging ongefilterd in de query, er zat geen
 * CSRF-bescherming op, en er werd niet gecontroleerd of het opgegeven adres
 * wel een IP-adres was. Ook kon een staflid gewoon verbannen worden, en was
 * een verbanning niet op te heffen zonder in de database te duiken.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user    = require_level(beheerpaginas()['ban.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'ban'      => bannen($user, post('doel'), post('soort'), post('reden')),
            'opheffen' => opheffen(int_input('id')),
            default    => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'ban.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('Iemand verbannen');
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="ban">';
echo '<div class="veldenraster">';
echo '<span>Type</span><div>'
   . '<label><input type="radio" name="soort" value="login" checked> Gebruikersnaam</label> '
   . '<label><input type="radio" name="soort" value="ip"> IP-adres</label></div>';
echo '<label for="doel">Naam of IP</label>';
echo '<input id="doel" name="doel" maxlength="45" required>';
echo '<label for="reden">Reden</label>';
echo '<input id="reden" name="reden" maxlength="255">';
echo '<span></span><button type="submit">Verbannen</button>';
echo '</div></form>';
panel_close();

$bans = q_all('SELECT * FROM `bans` ORDER BY `id` DESC LIMIT 100');

panel_open('Huidige verbanningen (' . count($bans) . ')');

if ($bans === []) {
    echo '<p>Er is niemand verbannen.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Naam</th><th>IP-adres</th><th>Reden</th><th>Door</th><th></th></tr></thead><tbody>';
    foreach ($bans as $ban) {
        echo '<tr>';
        echo '<td>' . ($ban['login'] !== '' ? speler_naam((string) $ban['login']) : '-') . '</td>';
        echo '<td>' . ($ban['ip'] !== '' ? e((string) $ban['ip']) : '-') . '</td>';
        echo '<td>' . e((string) $ban['reden']) . '</td>';
        echo '<td>' . speler_naam((string) $ban['door']) . '</td>';
        echo '<td><form method="post" style="margin:0">' . csrf_field()
           . '<input type="hidden" name="actie" value="opheffen">'
           . '<input type="hidden" name="id" value="' . (int) $ban['id'] . '">'
           . '<button type="submit">Opheffen</button></form></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

panel_close();
beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function bannen(array $user, string $doel, string $soort, string $reden): string
{
    $doel  = trim($doel);
    $reden = trim($reden) === '' ? 'Geen reden opgegeven' : mb_substr(trim($reden), 0, 255);

    if ($doel === '') {
        throw new SpelFout('Vul een naam of IP-adres in.');
    }

    if ($soort === 'ip') {
        if (filter_var($doel, FILTER_VALIDATE_IP) === false) {
            throw new SpelFout('Dat is geen geldig IP-adres.');
        }

        $staf = (int) q_val('SELECT COUNT(*) FROM `users` WHERE `ip` = ? AND `level` >= ?',
            [$doel, LEVEL_MODERATOR], 0);

        if ($staf > 0) {
            throw new SpelFout('Op dit IP-adres zit een staflid.');
        }
        if ((int) q_val('SELECT COUNT(*) FROM `bans` WHERE `ip` = ?', [$doel], 0) > 0) {
            throw new SpelFout('Dit IP-adres is al verbannen.');
        }

        q("INSERT INTO `bans` (`ip`, `login`, `reden`, `door`) VALUES (?, '', ?, ?)",
            [$doel, $reden, $user['login']]);

        log_action((string) $user['login'], 'beheer', 'IP verbannen: ' . $doel . ' (' . $reden . ')');

        return 'Het IP-adres ' . $doel . ' is verbannen.';
    }

    $speler = beheer_speler($doel);

    if ((int) $speler['level'] >= LEVEL_MODERATOR) {
        throw new SpelFout('Stafleden kun je niet verbannen.');
    }
    if ((int) $speler['id'] === (int) $user['id']) {
        throw new SpelFout('Je kunt jezelf niet verbannen.');
    }
    if ((int) q_val('SELECT COUNT(*) FROM `bans` WHERE `login` = ?', [$speler['login']], 0) > 0) {
        throw new SpelFout($speler['login'] . ' is al verbannen.');
    }

    q("INSERT INTO `bans` (`ip`, `login`, `reden`, `door`) VALUES ('', ?, ?, ?)",
        [$speler['login'], $reden, $user['login']]);

    log_action((string) $user['login'], 'beheer',
        'Speler verbannen (' . $reden . ')', 0, (string) $speler['login']);

    return $speler['login'] . ' is verbannen.';
}

/** @throws SpelFout */
function opheffen(int $id): string
{
    if (q_count('DELETE FROM `bans` WHERE `id` = ?', [$id]) === 0) {
        throw new SpelFout('Die verbanning bestaat niet.');
    }

    return 'De verbanning is opgeheven.';
}
