<?php
/**
 * Schandpaal: wie betrapt is op vals spel, en de tomaten die daarbij horen.
 *
 * Wat hier gerepareerd is:
 *
 *  - **Geld bijdrukken.** Bij een dodelijke tomaat kreeg de gooier het geld
 *    van het slachtoffer erbij (`zak = zak + $victim->zak`), maar de zak van
 *    het slachtoffer werd nooit leeggemaakt — alleen `bank` ging op nul. Elke
 *    dode op de schandpaal verdubbelde dus zijn contante geld. Het afhandelen
 *    van een overlijden gaat nu via speler_sterft() in inc/combat.php, dat één
 *    keer goed staat en door kill.php en oc.php ook gebruikt wordt.
 *  - Gooien ging via een GET-link (`wallofshame.php?hit=Naam`), dus zonder
 *    token: een plaatje met die URL in een forumbericht liet een ander €10.000
 *    per weergave betalen.
 *  - De €10.000 werd pás na de klap afgeschreven, met een losse UPDATE en
 *    zonder ondergrens: wie €5.000 op zak had, kwam op −€5.000 uit. Op een
 *    `unsigned` kolom liep dat vast op de databaselaag.
 *  - `$victim->status == dood` vergelijkt met een ongedefinieerde constante:
 *    sinds PHP 8 een fatale fout.
 *  - Per regel in de lijst werd een extra query gedaan om de gezondheid op te
 *    halen. Nu één query met een join.
 *  - Naam en reden kwamen ongefilterd op de pagina; de reden wordt door een
 *    beheerder ingevoerd, maar de naam kon van de speler zelf komen.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/combat.php';

/** Wat een tomaat kost en aanricht. */
const TOMAAT_PRIJS  = 10_000;
const TOMAAT_SCHADE = 5;
const TOMAAT_XP     = 10;

$user    = require_login();
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = gooien($user, post('naam'));
        $type    = 'ok';
        $user    = current_user() ?? $user;
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Schandpaal');

if ($melding !== null) {
    notice(e($melding), $type);
}

$lijst = q_all(
    'SELECT s.`cheater`, s.`com`, s.`time`, u.`health`, u.`status`
       FROM `shame` s LEFT JOIN `users` u ON u.`login` = s.`cheater`
      ORDER BY s.`time` DESC'
);

panel_open('Schandpaal');

if ($lijst === []) {
    echo '<p>Er hangt niemand aan de schandpaal.</p>';
} else {
    echo '<p>Een tomaat kost ' . money(TOMAAT_PRIJS) . ' en doet '
       . TOMAAT_SCHADE . ' schade.</p>';

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Speler</th><th>Sinds</th><th>Reden</th>'
       . '<th class="getal">Gezondheid</th><th></th></tr></thead><tbody>';

    foreach ($lijst as $rij) {
        $naam   = (string) $rij['cheater'];
        $leeft  = $rij['status'] === 'levend' && (int) $rij['health'] > 0;
        $isZelf = $naam === (string) $user['login'];

        echo '<tr>';
        echo '<td><a' . speler_rol_attr($naam) . ' href="' . e(url('user.php?x=' . rawurlencode($naam))) . '">'
           . e($naam) . '</a></td>';
        echo '<td>' . e(datetime_nl($rij['time'])) . '</td>';
        echo '<td>' . e((string) $rij['com']) . '</td>';
        echo '<td class="getal">' . ($rij['status'] === null ? '—'
            : num((int) $rij['health'])) . '</td>';
        echo '<td>';

        if ($isZelf) {
            echo '<span class="uit">dat ben jij</span>';
        } elseif (!$leeft) {
            echo '<span class="uit">dood</span>';
        } elseif (is_dead()) {
            echo '<span class="uit">—</span>';
        } else {
            echo '<form method="post" style="margin:0">' . csrf_field()
               . '<input type="hidden" name="naam" value="' . e($naam) . '">'
               . '<button type="submit">Tomaat gooien</button></form>';
        }

        echo '</td></tr>';
    }

    echo '</tbody></table></div>';
}

panel_close();
layout_footer();

// ==========================================================================

/** @throws SpelFout */
function gooien(array $user, string $naam): string
{
    if (is_dead()) {
        throw new SpelFout('Doden gooien niet.');
    }
    if ($naam === (string) $user['login']) {
        throw new SpelFout('Op jezelf gooien heeft weinig zin.');
    }

    $regels = [];

    db_transaction(static function () use ($user, $naam, &$regels): void {
        $staat = (int) q_val('SELECT COUNT(*) FROM `shame` WHERE `cheater` = ?', [$naam], 0);

        if ($staat === 0) {
            throw new SpelFout('Die speler staat niet op de schandpaal.');
        }

        $doel = lock_user_by_login($naam);

        if ($doel === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }
        if ($doel['status'] !== 'levend') {
            throw new SpelFout($naam . ' is al dood.');
        }

        // Eerst betalen. De voorwaarde zit in de UPDATE, dus er kan geen
        // negatief saldo ontstaan en er is geen gat tussen kijken en afboeken.
        if (!afboeken((int) $user['id'], TOMAAT_PRIJS)) {
            throw new SpelFout('Een tomaat kost ' . money(TOMAAT_PRIJS)
                . ' en zoveel heb je niet op zak.');
        }

        $nieuweHealth = (int) $doel['health'] - TOMAAT_SCHADE;

        if ($nieuweHealth > 0) {
            q('UPDATE `users` SET `health` = ? WHERE `id` = ?', [$nieuweHealth, $doel['id']]);

            notify($naam, 'Schandpaal',
                $user['login'] . ' heeft een tomaat naar je gegooid. Je verliest '
                . TOMAAT_SCHADE . ' gezondheid.');

            $regels[] = 'Raak. ' . $naam . ' houdt er ' . num($nieuweHealth)
                      . ' gezondheid aan over.';
            return;
        }

        // Dodelijk. De hele afhandeling (erfenis, familie, casino's, premie,
        // zak en bank op nul) zit in speler_sterft().
        $buit   = (int) $doel['zak'];
        $regels = array_merge(
            ['Je hebt ' . $naam . ' met een tomaat om zeep geholpen.'],
            speler_sterft($doel, (string) $user['login'], 'Doodgegooid met een tomaat.')
        );

        bijschrijven((int) $user['id'], $buit, 'zak');
        q('UPDATE `users` SET `xp` = `xp` + ? WHERE `id` = ?', [TOMAAT_XP, $user['id']]);

        $regels[] = 'Hij had ' . money($buit) . ' op zak. Dat is nu van jou.';

        log_action((string) $user['login'], 'schandpaal', 'Doodgegooid', $buit, $naam);
    });

    return implode(' ', $regels);
}
