<?php
/**
 * Eerpunten geven en ontvangen.
 *
 * Wat hier gerepareerd is:
 *
 *  - `if ($_POST['type'] == e)` vergelijkt met de ongedefinieerde constante
 *    `e`. In PHP 5 werd daar stilzwijgend de string 'e' van gemaakt; sinds
 *    PHP 8 is het een fatale fout, dus punten geven werkte niet meer.
 *  - Beide overzichten filterden op `` `time` >= '{$data->signup}' ``. De
 *    kolom `signup` bestaat niet — het veld heet `start` — dus daar stond
 *    altijd een lege string. MySQL kan '' niet naar een datetime omzetten en
 *    geeft daarop tegenwoordig zelfs een harde fout (1525). Beide lijsten
 *    waren dus altijd leeg.
 *  - Het aantal en de omschrijving gingen ongefilterd in de query.
 *  - Er zat geen transactie omheen: de ontvanger kreeg zijn punten met een
 *    losse UPDATE en pas daarna werd de gever afgeschreven. Twee verzoeken
 *    tegelijk deelden dus meer punten uit dan iemand had.
 *  - Schandepunten trokken `respect` omlaag zonder ondergrens, terwijl die
 *    kolom `unsigned` is. Onder nul liep dat vast op de databaselaag.
 *  - Je kon punten geven aan een dode speler en aan jezelf via een andere
 *    schrijfwijze: de vergelijking gebruikte `strtolower()` op de invoer maar
 *    de UPDATE de originele invoer.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

const RESPECT_MAX_COM = 100;

$user    = require_login();
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = versturen($user, post('naar'), int_input('aantal'), post('soort'), post('com'));
        $type    = 'ok';

        // Opnieuw ophalen: het saldo aan uit te delen punten is veranderd.
        $user = current_user() ?? $user;
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Eerpunten');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('Eerpunten versturen');

$volgende = q_val("SELECT UNIX_TIMESTAMP(`time`) FROM `cron` WHERE `name` = 'week'", [], 0);

echo '<p>Je hebt nog <strong>' . num((int) $user['rp']) . '</strong> '
   . ((int) $user['rp'] === 1 ? 'punt' : 'punten') . ' om weg te geven. '
   . 'De volgende ronde komt op ' . e(timestamp_nl((int) $volgende + 604800)) . '.</p>';

if ((int) $user['rp'] < 1) {
    echo '<p>Je hebt op dit moment niets uit te delen.</p>';
} elseif (is_dead()) {
    echo '<p>Doden geven geen eer.</p>';
} else {
    echo '<form method="post">' . csrf_field();
    echo '<div class="veldenraster">';
    echo '<label for="naar">Naar</label>';
    echo '<input id="naar" name="naar" maxlength="16" required>';
    echo '<label for="aantal">Aantal</label>';
    echo '<input id="aantal" name="aantal" type="number" min="1" max="' . (int) $user['rp']
       . '" value="1" required>';
    echo '<label for="com">Omschrijving</label>';
    echo '<input id="com" name="com" maxlength="' . RESPECT_MAX_COM . '">';
    echo '<label>Soort</label>';
    echo '<span><label><input type="radio" name="soort" value="eer" checked> Eer</label> '
       . '<label><input type="radio" name="soort" value="schande"> Schande</label></span>';
    echo '<span></span><button type="submit">Versturen</button>';
    echo '</div></form>';
}

panel_close();

overzicht('Laatst ontvangen', 'person', 'login', 'Van', (string) $user['login']);
overzicht('Laatst gegeven', 'login', 'person', 'Naar', (string) $user['login']);

layout_footer();

// ==========================================================================

/** @throws SpelFout */
function versturen(array $user, string $naar, int $aantal, string $soort, string $com): string
{
    if (is_dead()) {
        throw new SpelFout('Doden geven geen eer.');
    }
    if (!in_array($soort, ['eer', 'schande'], true)) {
        throw new SpelFout('Kies eer of schande.');
    }
    if ($aantal < 1) {
        throw new SpelFout('Geef minstens één punt.');
    }
    if (strcasecmp($naar, (string) $user['login']) === 0) {
        throw new SpelFout('Je kunt jezelf geen eerpunten geven.');
    }

    $com = mb_substr(trim($com), 0, RESPECT_MAX_COM);

    db_transaction(static function () use ($user, $naar, $aantal, $soort, $com): void {
        $doel = lock_user_by_login($naar);

        if ($doel === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }
        if ($doel['status'] !== 'levend') {
            throw new SpelFout('Die speler is dood.');
        }

        // De voorwaarde zit ín de UPDATE, dus er is geen gat tussen
        // controleren en afschrijven.
        $af = q_count('UPDATE `users` SET `rp` = `rp` - ? WHERE `id` = ? AND `rp` >= ?',
            [$aantal, $user['id'], $aantal]);

        if ($af === 0) {
            throw new SpelFout('Zoveel punten heb je niet meer.');
        }

        if ($soort === 'eer') {
            q('UPDATE `users` SET `respect` = `respect` + ? WHERE `id` = ?',
                [$aantal, $doel['id']]);
        } else {
            // GREATEST omdat `respect` unsigned is: zonder ondergrens loopt
            // een aftrek onder nul vast op de databaselaag.
            q('UPDATE `users` SET `respect` = GREATEST(0, CAST(`respect` AS SIGNED) - ?)
                 WHERE `id` = ?',
                [$aantal, $doel['id']]);
        }

        log_action((string) $user['login'], 'respect', $com,
            $soort === 'eer' ? $aantal : -$aantal, (string) $doel['login']);
    });

    $doelnaam = (string) q_val('SELECT `login` FROM `users` WHERE `login` = ?', [$naar], $naar);

    return $soort === 'eer'
        ? 'Je hebt ' . num($aantal) . ' eerpunten aan ' . $doelnaam . ' gegeven.'
        : 'Je hebt ' . num($aantal) . ' schandepunten aan ' . $doelnaam . ' gegeven.';
}

/**
 * Toon een van de twee logboeken.
 *
 * @param string $waar  Kolom waarop gefilterd wordt (person = ontvangen, login = gegeven).
 * @param string $toon  Kolom met de tegenpartij.
 */
function overzicht(string $titel, string $waar, string $toon, string $kop, string $login): void
{
    $regels = q_all(
        'SELECT `' . $waar . '`, `' . $toon . '`, `code`, `com`, `time`
           FROM `logs` WHERE `' . $waar . '` = ? AND `area` = \'respect\'
          ORDER BY `time` DESC LIMIT 10',
        [$login]
    );

    panel_open($titel);

    if ($regels === []) {
        echo '<p>Nog niets.</p>';
        panel_close();
        return;
    }

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>' . e($kop) . '</th><th class="getal">Aantal</th>'
       . '<th>Wanneer</th><th>Omschrijving</th></tr></thead><tbody>';

    foreach ($regels as $regel) {
        $aantal = (int) $regel['code'];

        echo '<tr>';
        echo '<td><a' . speler_rol_attr((string) $regel[$toon]) . ' href="'
           . e(url('user.php?x=' . rawurlencode((string) $regel[$toon]))) . '">'
           . e((string) $regel[$toon]) . '</a></td>';
        echo '<td class="getal ' . ($aantal < 0 ? 'schande' : 'eer') . '">'
           . ($aantal > 0 ? '+' : '') . num($aantal) . '</td>';
        echo '<td>' . e(datetime_nl($regel['time'])) . '</td>';
        echo '<td>' . ((string) $regel['com'] === '' ? '—' : e((string) $regel['com'])) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    panel_close();
}
