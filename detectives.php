<?php
/**
 * Detectivebureau: huur iemand in om een speler op te sporen.
 *
 * Een detective zoekt in één stad. Is het doelwit daar wanneer hij terugkomt,
 * dan meldt hij dat. De afhandeling gebeurt in inc/cron.php.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - Bij "alle steden" werd gecontroleerd of je € 60.000 had, maar er werd
 *    € 70.000 afgeschreven. Met een saldo tussen die twee bedragen kwam je in
 *    de min te staan.
 *  - De controle of je jezelf zocht luidde
 *    `strtolower($data->login == $_POST['naar'])`. Het haakje stond verkeerd,
 *    dus strtolower werd op de uitkomst van de vergelijking losgelaten in
 *    plaats van op de naam. Bij een verschil in hoofdletters werkte de controle
 *    daardoor niet.
 *  - De acht steden werden met acht losse, bijna identieke queries ingevoerd.
 *  - Er werd geschreven naar een tabel `log` die niet in het schema staat.
 *    Dat gaat nu via log_action() naar `logs`.
 *  - Het aantal lopende opdrachten werd opgehaald en daarna overschreven met
 *    de vaste waarde 1, waardoor het overzicht altijd getoond werd.
 *  - Detectives terughalen kon zonder CSRF-bescherming.
 *  - De afhandeling van teruggekeerde detectives stond midden in config.php en
 *    draaide dus bij elk paginabezoek van elke speler opnieuw.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

const DETECTIVE_PRIJS      = 10_000;
const DETECTIVE_PRIJS_ALLE = 70_000;

$user = require_login();

if (is_dead()) {
    redirect('rip.php');
}
block_if_jailed();

$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = verwerk($user, post('actie'));
        $type    = 'ok';
        $user    = current_user(true);
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

$lopend = q_all(
    'SELECT *, UNIX_TIMESTAMP(`time`) AS `terug_ts` FROM `detectives`
      WHERE `van` = ? ORDER BY `time` ASC',
    [$user['login']]
);

layout_header('Detectivebureau');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('Huur een detective');

echo '<p>Een detective zoekt in één stad. Is je doelwit daar wanneer hij terugkomt, '
   . 'dan krijg je bericht. Eén stad kost ' . money(DETECTIVE_PRIJS)
   . ', alle steden tegelijk ' . money(DETECTIVE_PRIJS_ALLE) . '.</p>';
echo '<p>Je hebt ' . money((int) $user['zak']) . ' op zak.</p>';

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="huren">';
echo '<div class="veldenraster">';
echo '<label for="naar">Zoek naar</label>';
echo '<input id="naar" name="naar" maxlength="16" required>';
echo '<label for="stad">Stad</label><select id="stad" name="stad">';
echo '<option value="alle">Alle steden (' . strip_tags(money(DETECTIVE_PRIJS_ALLE)) . ')</option>';
foreach (cities() as $stad) {
    echo '<option value="' . e($stad) . '">' . e($stad) . '</option>';
}
echo '</select>';
echo '<span></span><button type="submit">Stuur eropuit</button>';
echo '</div></form>';

panel_close();

panel_open('Lopende opdrachten');

if ($lopend === []) {
    echo '<p>Je hebt geen detectives op pad.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Zoekt naar</th><th>Stad</th><th>Terug over</th></tr></thead><tbody>';
    foreach ($lopend as $opdracht) {
        $over = max(0, (int) $opdracht['terug_ts'] - time());
        echo '<tr>';
        echo '<td>' . speler_naam((string) $opdracht['naar']) . '</td>';
        echo '<td>' . e((string) $opdracht['stad']) . '</td>';
        echo '<td>' . ($over > 0
            ? '<span data-tot="' . (int) $opdracht['terug_ts'] . '">' . e(duration($over)) . '</span>'
            : 'meldt zich zo') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    echo '<form method="post">' . csrf_field()
       . '<input type="hidden" name="actie" value="terughalen">'
       . '<button type="submit">Roep al je detectives terug</button></form>';
    echo '<p class="uitleg">Je krijgt het geld niet terug.</p>';
}

panel_close();
layout_footer();

// ==========================================================================

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'huren'      => huren($user, post('naar'), post('stad')),
        'terughalen' => terughalen($user),
        default      => throw new SpelFout('Onbekende handeling.'),
    };
}

/** @throws SpelFout */
function huren(array $user, string $naam, string $stad): string
{
    $naam = trim($naam);

    if ($naam === '') {
        throw new SpelFout('Vul in wie je wilt laten zoeken.');
    }
    // Deze vergelijking ging in de oude versie mis door een verkeerd haakje.
    if (strcasecmp($naam, (string) $user['login']) === 0) {
        throw new SpelFout('Je kunt jezelf niet laten zoeken.');
    }

    $alle = $stad === 'alle';

    if (!$alle && !is_city($stad)) {
        throw new SpelFout('Die stad bestaat niet.');
    }

    return db_transaction(static function () use ($user, $naam, $stad, $alle): string {
        $doelwit = q_row('SELECT `login`, `status` FROM `users` WHERE `login` = ?', [$naam]);

        if ($doelwit === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }
        if ($doelwit['status'] !== 'levend') {
            throw new SpelFout($doelwit['login'] . ' is dood; die hoef je niet te zoeken.');
        }

        $steden = $alle ? cities() : [$stad];
        $kosten = $alle ? DETECTIVE_PRIJS_ALLE : DETECTIVE_PRIJS;

        // Bedrag en controle waren in de oude versie verschillend (60.000 tegen
        // 70.000), waardoor je in de min kon komen.
        lock_user((int) $user['id']);

        if (!afboeken((int) $user['id'], $kosten, 'zak')) {
            throw new SpelFout('Dit kost ' . money($kosten) . ' en zoveel heb je niet op zak.');
        }

        foreach ($steden as $doelstad) {
            // Geen dubbele opdracht op dezelfde speler in dezelfde stad.
            $bestaat = (int) q_val(
                'SELECT COUNT(*) FROM `detectives` WHERE `van` = ? AND `naar` = ? AND `stad` = ?',
                [$user['login'], $doelwit['login'], $doelstad],
                0
            );

            if ($bestaat === 0) {
                q(
                    'INSERT INTO `detectives` (`van`, `naar`, `stad`, `time`)
                          VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
                    [$user['login'], $doelwit['login'], $doelstad, random_int(300, 3600)]
                );
            }
        }

        log_action((string) $user['login'], 'detective',
            'Detective op ' . $doelwit['login'] . ($alle ? ' (alle steden)' : ' in ' . $stad),
            $kosten, (string) $doelwit['login']);

        return $alle
            ? 'Je detectives zijn naar alle steden vertrokken om ' . $doelwit['login'] . ' te zoeken.'
            : 'Je detective is naar ' . $stad . ' vertrokken om ' . $doelwit['login'] . ' te zoeken.';
    });
}

/** @throws SpelFout */
function terughalen(array $user): string
{
    $aantal = q_count('DELETE FROM `detectives` WHERE `van` = ?', [$user['login']]);

    if ($aantal === 0) {
        throw new SpelFout('Je hebt geen detectives op pad.');
    }

    return $aantal . ' ' . ($aantal === 1 ? 'detective is' : 'detectives zijn')
         . ' teruggeroepen. Het geld krijg je niet terug.';
}
