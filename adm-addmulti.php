<?php
/**
 * IP-adressen waarop meerdere accounts toegestaan zijn.
 *
 * Handig voor spelers die een huis of internetverbinding delen. Zonder
 * vermelding weigert register.php een tweede account op hetzelfde IP.
 *
 * Wat hier gerepareerd is: verwijderen ging via een GET-link, het IP werd niet
 * gecontroleerd, en bij het toevoegen werd de hele IP-geschiedenis van dat
 * adres gewist in plaats van alleen de markering bij te werken.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user    = require_level(beheerpaginas()['adm-addmulti.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'toevoegen'  => toevoegen($user, post('ip')),
            'verwijderen'=> verwijderen($user, post('ip')),
            default      => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Beheer');
beheer_menu($user, 'adm-addmulti.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('IP-adres toestaan');
echo '<p>Op deze adressen mogen meerdere accounts geregistreerd worden.</p>';
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="toevoegen">';
echo '<div class="veldenraster">';
echo '<label for="ip">IP-adres</label>';
echo '<input id="ip" name="ip" maxlength="45" required>';
echo '<span></span><button type="submit">Toevoegen</button>';
echo '</div></form>';
panel_close();

$lijst = q_all(
    'SELECT m.`ip`,
            (SELECT COUNT(DISTINCT u.`login`) FROM `users` u WHERE u.`ip` = m.`ip`) AS `accounts`
       FROM `multiple` m ORDER BY m.`ip`'
);

panel_open('Toegestane adressen (' . count($lijst) . ')');

if ($lijst === []) {
    echo '<p>Er staat nog geen enkel adres in de lijst.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>IP-adres</th><th class="getal">Accounts</th><th>Wie</th><th></th></tr></thead><tbody>';

    foreach ($lijst as $rij) {
        $namen = q_all('SELECT `login` FROM `users` WHERE `ip` = ? ORDER BY `login` LIMIT 10',
            [$rij['ip']]);

        $links = array_map(
            static fn (array $r): string => '<a' . speler_rol_attr((string) $r['login']) . ' href="'
                . e(url('adm-search.php?login=' . rawurlencode((string) $r['login']))) . '">'
                . e((string) $r['login']) . '</a>',
            $namen
        );

        echo '<tr>';
        echo '<td>' . e((string) $rij['ip']) . '</td>';
        echo '<td class="getal">' . num((int) $rij['accounts']) . '</td>';
        echo '<td>' . ($links === [] ? '<small>geen</small>' : implode(', ', $links)) . '</td>';
        echo '<td><form method="post" style="margin:0">' . csrf_field()
           . '<input type="hidden" name="actie" value="verwijderen">'
           . '<input type="hidden" name="ip" value="' . e((string) $rij['ip']) . '">'
           . '<button type="submit">Verwijderen</button></form></td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

panel_close();
layout_footer();

// ==========================================================================

/** @throws SpelFout */
function toevoegen(array $user, string $ip): string
{
    $ip = trim($ip);

    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        throw new SpelFout('Dat is geen geldig IP-adres.');
    }

    $nieuw = q_count('INSERT IGNORE INTO `multiple` (`ip`, `allo`) VALUES (?, 1)', [$ip]);

    if ($nieuw === 0) {
        throw new SpelFout('Dit adres staat al in de lijst.');
    }

    // Alleen de markering bijwerken. De oude versie wiste de hele
    // IP-geschiedenis van dat adres, waardoor het spoor kwijt was.
    q('UPDATE `iplog` SET `allo` = 1 WHERE `ip` = ?', [$ip]);

    log_action((string) $user['login'], 'beheer', 'Multi-account toegestaan op ' . $ip);

    return 'Het adres ' . $ip . ' staat nu meerdere accounts toe.';
}

/** @throws SpelFout */
function verwijderen(array $user, string $ip): string
{
    if (q_count('DELETE FROM `multiple` WHERE `ip` = ?', [$ip]) === 0) {
        throw new SpelFout('Dat adres staat niet in de lijst.');
    }

    q('UPDATE `iplog` SET `allo` = 0 WHERE `ip` = ?', [$ip]);

    log_action((string) $user['login'], 'beheer', 'Multi-account ingetrokken op ' . $ip);

    return 'Het adres ' . $ip . ' staat geen meerdere accounts meer toe.';
}
