<?php
/**
 * Bericht sturen namens het beheer, aan één speler of aan iedereen.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - De rechtencontrole stond in de else-tak. Het verzenden gebeurde dus
 *    zónder de controle op niveau 255; die draaide alleen wanneer het
 *    formulier getoond werd. Precies omgekeerd: een moderator kon wel
 *    versturen maar het formulier niet zien.
 *  - Onderwerp en bericht werden gefilterd met preg_replace('/</','&#60;'),
 *    dezelfde schijnfiltering als elders: alleen het kleiner-dan-teken werd
 *    vervangen.
 *  - Er werd niet gecontroleerd of de ontvanger bestond.
 *  - Geen CSRF-bescherming.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

const ADM_BERICHT_MAX = 5000;

$user    = require_level(beheerpaginas()['msg.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = versturen($user, post('naar'), post('subject'), post('message'), post('actie'));
        $type    = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'msg.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('Bericht namens het beheer');

echo '<p>Het bericht komt binnen met afzender <strong>Admin</strong>.</p>';

echo '<form method="post">' . csrf_field();
echo '<div class="veldenraster">';
echo '<label for="naar">Aan</label>';
echo '<input id="naar" name="naar" maxlength="16">';
echo '<span></span><small>Laat leeg als je naar alle levende spelers stuurt.</small>';
echo '<label for="subject">Onderwerp</label>';
echo '<input id="subject" name="subject" maxlength="80" required>';
echo '<label for="message">Bericht</label>';
echo '<textarea id="message" name="message" maxlength="' . ADM_BERICHT_MAX . '" required></textarea>';
echo '<span></span><div>'
   . '<button type="submit" name="actie" value="een">Naar deze speler</button> '
   . ((int) $user['level'] >= LEVEL_OWNER
        ? '<button type="submit" name="actie" value="allen">Naar alle spelers</button>'
        : '')
   . '</div>';
echo '</div></form>';

if ((int) $user['level'] < LEVEL_OWNER) {
    echo '<p class="uitleg">Een bericht aan alle spelers kan alleen de eigenaar versturen.</p>';
}

panel_close();

beheer_logregels('beheerbericht');

beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function versturen(array $user, string $naar, string $onderwerp, string $tekst, string $actie): string
{
    $onderwerp = trim($onderwerp);
    $tekst     = trim($tekst);

    if ($onderwerp === '' || $tekst === '') {
        throw new SpelFout('Vul een onderwerp en een bericht in.');
    }
    if (mb_strlen($tekst) > ADM_BERICHT_MAX) {
        throw new SpelFout('Het bericht is te lang.');
    }

    $onderwerp = mb_substr($onderwerp, 0, 80);

    if ($actie === 'allen') {
        if ((int) $user['level'] < LEVEL_OWNER) {
            throw new SpelFout('Alleen de eigenaar kan naar alle spelers sturen.');
        }

        // Eén query in plaats van een lus met duizenden losse inserts.
        $aantal = q_count(
            "INSERT INTO `messages` (`time`, `from`, `to`, `subject`, `message`)
             SELECT NOW(), 'Admin', `login`, ?, ? FROM `users`
              WHERE `status` = 'levend' AND `activated` = 1",
            [$onderwerp, $tekst]
        );

        log_action((string) $user['login'], 'beheerbericht',
            'Bericht aan alle spelers: ' . $onderwerp, $aantal);

        return 'Het bericht is naar ' . num($aantal) . ' spelers gestuurd.';
    }

    $ontvanger = beheer_speler($naar);

    q("INSERT INTO `messages` (`time`, `from`, `to`, `subject`, `message`)
            VALUES (NOW(), 'Admin', ?, ?, ?)",
        [$ontvanger['login'], $onderwerp, $tekst]);

    log_action((string) $user['login'], 'beheerbericht',
        'Bericht: ' . $onderwerp, 0, (string) $ontvanger['login']);

    return 'Het bericht is naar ' . $ontvanger['login'] . ' gestuurd.';
}
