<?php
/**
 * Moorden.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - Twee achterdeurtjes verwijderd: de speler "JanuS" mocht moorden ongeacht
 *    zijn niveau, en "pitbullgirl" kon niet gewond raken.
 *  - Het slachtoffer hield zijn zakgeld terwijl de moordenaar datzelfde bedrag
 *    kreeg. Elke moord drukte dus geld bij.
 *  - Een dubbele backtick maakte de query die het slachtoffer kogels afnam
 *    ongeldig, dus die werd nooit uitgevoerd.
 *  - Bij een backfire werd de premie uitbetaald vanuit een variabele die niet
 *    bestond, waardoor er nul werd uitgekeerd en de verkeerde rij verwijderd.
 *  - Het hele sterfgeval liep zonder transactie: geld, erfenis, familie en
 *    garage konden half verwerkt achterblijven.
 *  - Het bericht van de dader ging ongefilterd de database in.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/captcha.php';
require BV_INC . '/combat.php';

$user = require_login();

if (is_dead()) {
    redirect('rip.php');
}
block_if_jailed();

$uitslag = null;

if (is_post()) {
    csrf_check();
    try {
        $uitslag = aanslag_plegen(
            $user,
            post('victim'),
            int_input('kogels', 0, 0),
            post('message'),
            post('ano') !== 'no',
            post('verify')
        );
    } catch (SpelFout $e) {
        $uitslag = ['type' => 'fout', 'regels' => [$e->getMessage()]];
    }
    $user = current_user(true);
}

$wacht      = cooldown_left((int) $user['kc_ts']);
$dezeWeek   = (int) q_val(
    'SELECT COUNT(*) FROM `vermoord` WHERE `dader` = ? AND `date` > DATE_SUB(NOW(), INTERVAL 7 DAY)',
    [$user['login']],
    0
);

layout_header('Moorden');

if ($uitslag !== null) {
    notice(implode('<br>', array_map('e', $uitslag['regels'])), $uitslag['type']);
}

panel_open('Moorden');

if ($dezeWeek >= MOORDEN_PER_WEEK) {
    echo '<p>Je bent nog bezig het bloed van je vorige moorden af te wassen. '
       . 'Je hebt deze week al ' . $dezeWeek . ' moorden gepleegd; het maximum is '
       . MOORDEN_PER_WEEK . '.</p>';
} elseif ($wacht > 0) {
    echo '<p>Je moet nog <strong data-tot="' . (time() + $wacht) . '">'
       . e(duration($wacht)) . '</strong> wachten voor je weer kunt moorden.</p>';
} elseif ((int) $user['wapon'] < 1) {
    echo '<p>Je hebt nog geen wapen. Koop er een in de <a href="'
       . e(url('shop.php')) . '">winkel</a>.</p>';
} else {
    toon_formulier($user);
}

panel_close();

toon_beschietingen($user);

layout_footer();

// ==========================================================================

/**
 * @return array{type:string, regels:string[]}
 * @throws SpelFout
 */
function aanslag_plegen(
    array $user,
    string $doelwitNaam,
    int $kogels,
    string $bericht,
    bool $anoniem,
    string $captcha
): array {
    if ($doelwitNaam === '') {
        throw new SpelFout('Vul in wie je wilt vermoorden.');
    }
    if ($kogels < 1) {
        throw new SpelFout('Je moet minstens één kogel afvuren.');
    }
    if (!captcha_check($captcha)) {
        throw new SpelFout('De code die je invoerde klopt niet.');
    }
    if (cooldown_left((int) $user['kc_ts']) > 0) {
        throw new SpelFout('Je moet nog wachten voor je weer kunt moorden.');
    }

    $bericht = mb_substr(trim($bericht), 0, 255);

    return db_transaction(static function () use ($user, $doelwitNaam, $kogels, $bericht, $anoniem): array {

        // Beide partijen vergrendelen, altijd in dezelfde volgorde (op id),
        // anders kunnen twee gelijktijdige aanslagen op elkaar blijven wachten.
        $dader = lock_user((int) $user['id']);
        $doel  = q_row('SELECT * FROM `users` WHERE `login` = ?', [$doelwitNaam]);

        if ($doel === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }
        if ((int) $doel['id'] === (int) $dader['id']) {
            throw new SpelFout('Je kunt jezelf niet vermoorden.');
        }

        $doel = lock_user((int) $doel['id']);

        // --- Voorwaarden ---
        if ($doel['status'] !== 'levend') {
            throw new SpelFout($doel['login'] . ' is al dood.');
        }
        if ((int) $doel['level'] >= LEVEL_MODERATOR) {
            throw new SpelFout('Je kunt geen stafleden vermoorden.');
        }
        if ($dader['stad'] !== $doel['stad']) {
            throw new SpelFout('Je hebt geen idee waar je slachtoffer zich bevindt.');
        }
        if (is_ondergedoken($doel)) {
            throw new SpelFout('Je doelwit is ondergedoken.');
        }
        if (is_ondergedoken($dader)) {
            throw new SpelFout('Je zit zelf ondergedoken en kunt nu niet moorden.');
        }
        if (is_beschermd($doel)) {
            throw new SpelFout($doel['login'] . ' staat nog onder de bescherming voor nieuwe spelers.');
        }
        if (is_beschermd($dader)) {
            throw new SpelFout('Je staat zelf nog onder bescherming en kunt niet moorden.');
        }
        if ((int) $dader['kogels'] < $kogels) {
            throw new SpelFout('Zoveel kogels heb je niet.');
        }

        $verschil = rank_index((int) $dader['xp']) - rank_index((int) $doel['xp']);
        if ($verschil < -MAX_RANGVERSCHIL) {
            throw new SpelFout('Je kunt hoogstens iemand van ' . MAX_RANGVERSCHIL
                . ' rangen boven je aanvallen.');
        }

        $perWeek = (int) q_val(
            'SELECT COUNT(*) FROM `vermoord` WHERE `dader` = ? AND `date` > DATE_SUB(NOW(), INTERVAL 7 DAY)',
            [$dader['login']],
            0
        );
        if ($perWeek >= MOORDEN_PER_WEEK) {
            throw new SpelFout('Je hebt deze week al te veel moorden gepleegd.');
        }

        // --- Schoten wisselen ---
        $stad          = (string) $dader['stad'];
        $terugKogels   = backfire_kogels($doel, $kogels);

        $schadeDoel   = schade($dader, $doel, $kogels, $stad);
        $schadeDader  = schade($doel, $dader, $terugKogels, $stad);

        $doelLeven   = (float) $doel['health'] - $schadeDoel;
        $daderLeven  = (float) $dader['health'] - $schadeDader;

        // Kogels zijn hoe dan ook verschoten, en de afkoeltijd loopt.
        q('UPDATE `users` SET `kogels` = GREATEST(0, `kogels` - ?), `kc` = FROM_UNIXTIME(?) WHERE `id` = ?',
            [$kogels, cooldown_until('kill'), $dader['id']]);
        if ($terugKogels > 0) {
            q('UPDATE `users` SET `kogels` = GREATEST(0, `kogels` - ?) WHERE `id` = ?',
                [$terugKogels, $doel['id']]);
        }

        $regels = [];
        $type   = 'info';

        // --- Het doelwit sterft ---
        if ($doelLeven < 1) {
            // Het slachtoffer mag niet zien wie hem omlegde: dat is nu juist
            // wat een ooggetuigenverklaring waard maakt. Het bericht gaat dus
            // altijd zonder naam mee. Wie wil opscheppen, zet zijn naam zelf
            // in de tekst.
            $ondertekening = $bericht;

            $regels[] = $doel['login'] . ' is dood. Je hebt ' . money((int) $doel['zak'])
                      . ' uit zijn zakken gehaald.';

            // Eerst het geld pakken, dan pas het sterfgeval afhandelen: daarin
            // wordt de zak op nul gezet.
            bijschrijven((int) $dader['id'], (int) $doel['zak'], 'zak');
            q('UPDATE `stad` SET `drugs` = `drugs` + ?, `drank` = `drank` + ? WHERE `stad` = ?',
                [(int) $doel['drugs'], (int) $doel['drank'], $stad]);

            $regels = array_merge($regels, speler_sterft($doel, (string) $dader['login'], $ondertekening));

            q('UPDATE `users` SET `nrofkill` = `nrofkill` + 1, `xp` = `xp` + 10, `se` = LEAST(100, `se` + 1) WHERE `id` = ?',
                [$dader['id']]);

            $aantalGetuigen = ooggetuigen_aanwijzen(
                (string) $dader['login'], (string) $doel['login'], $stad);

            if ($aantalGetuigen > 0) {
                $regels[] = 'Er ' . ($aantalGetuigen === 1 ? 'was één getuige' : 'waren '
                    . num($aantalGetuigen) . ' getuigen') . '. Die kunnen je verklappen.';
            } else {
                $regels[] = 'Niemand heeft het gezien.';
            }

            log_action((string) $dader['login'], 'kill', 'Doelwit gedood', 1, (string) $doel['login']);

            $type = 'ok';
        } elseif ($schadeDoel > 0) {
            q('UPDATE `users` SET `health` = ? WHERE `id` = ?',
                [(int) max(1, round($doelLeven)), $doel['id']]);
            q('UPDATE `users` SET `se` = LEAST(100, `se` + 0.5) WHERE `id` = ?', [$dader['id']]);

            $regels[] = 'Je aanslag op ' . $doel['login'] . ' mislukte, maar hij is wel gewond geraakt.';
            log_action((string) $dader['login'], 'kill', 'Doelwit gewond', 3, (string) $doel['login']);
            $type = 'fout';
        } else {
            $regels[] = 'Je schoot op ' . $doel['login'] . ', maar raakte hem niet eens.';
            log_action((string) $dader['login'], 'kill', 'Doelwit gemist', 2, (string) $doel['login']);
            $type = 'fout';
        }

        // --- De dader sterft in de backfire ---
        if ($terugKogels > 0) {
            if ($daderLeven < 1) {
                $regels[] = 'Je had er beter over moeten nadenken: ' . $doel['login']
                          . ' schoot je dood in zijn backfire.';

                bijschrijven((int) $doel['id'], (int) $dader['zak'], 'zak');
                q('UPDATE `stad` SET `drugs` = `drugs` + ?, `drank` = `drank` + ? WHERE `stad` = ?',
                    [(int) $dader['drugs'], (int) $dader['drank'], $stad]);

                speler_sterft($dader, (string) $doel['login'],
                    'Je bent gedood door de backfire van ' . $doel['login'] . '.');

                q('UPDATE `users` SET `nrofkill` = `nrofkill` + 1 WHERE `id` = ?', [$doel['id']]);
                notify((string) $doel['login'], 'Moord',
                    'Je hebt ' . $dader['login'] . ' gedood met je backfire.');

                log_action((string) $doel['login'], 'kill', 'Gedood via backfire', 6, (string) $dader['login']);
                $type = 'fout';
            } elseif ($schadeDader > 0) {
                q('UPDATE `users` SET `health` = ? WHERE `id` = ?',
                    [(int) max(1, round($daderLeven)), $dader['id']]);

                $regels[] = $doel['login'] . ' schoot terug. Je bent gewond geraakt.';
                log_action((string) $doel['login'], 'kill', 'Schutter gewond', 5, (string) $dader['login']);
            } else {
                $regels[] = $doel['login'] . ' schoot terug, maar raakte je niet.';
                log_action((string) $doel['login'], 'kill', 'Schutter gemist', 4, (string) $dader['login']);
            }
        }

        return ['type' => $type, 'regels' => $regels];
    });
}

// ==========================================================================

function toon_formulier(array $user): void
{
    echo '<p>Je hebt ' . num((int) $user['kogels']) . ' kogels. Je slachtoffer moet in '
       . 'dezelfde stad zijn (' . e((string) $user['stad']) . ') en mag hoogstens '
       . MAX_RANGVERSCHIL . ' rangen boven je staan.</p>';

    echo '<form method="post">' . csrf_field();
    echo '<div class="veldenraster">';

    echo '<label for="victim">Doelwit</label>';
    echo '<input id="victim" name="victim" maxlength="16" required>';

    echo '<label for="kogels">Kogels</label>';
    echo '<input id="kogels" name="kogels" type="number" min="1" max="'
       . (int) $user['kogels'] . '" step="1" required>';

    echo '<label for="message">Afscheidsbericht</label>';
    echo '<input id="message" name="message" maxlength="255">';
    echo '<span></span><small>Dit ziet je slachtoffer op zijn overlijdenspagina. '
       . 'Je naam komt daar niet bij te staan: wie je bent hoort hij alleen als een '
       . 'ooggetuige zijn verklaring doorspeelt. Wil je toch dat hij het weet, zet het '
       . 'dan zelf in je bericht.</small>';

    $anoniem = post('ano') !== 'no';
    echo '<span>Ondertekening</span><div>'
       . '<label><input type="radio" name="ano" value="yes"' . ($anoniem ? ' checked' : '') . '> Anoniem</label> '
       . '<label><input type="radio" name="ano" value="no"' . ($anoniem ? '' : ' checked') . '> Met mijn naam</label>'
       . '</div>';
    echo '<span></span><small>Deze keuze geldt voor het rouwbericht op het forum, niet '
       . 'voor je slachtoffer.</small>';

    echo '<span></span>' . captcha_field();
    echo '<span></span><button type="submit">Vermoord</button>';
    echo '</div></form>';
}

/** Overzicht van wie er op jou geschoten heeft. */
function toon_beschietingen(array $user): void
{
    $regels = q_all(
        "SELECT * FROM `logs`
          WHERE `person` = ? AND `area` = 'kill'
       ORDER BY `time` DESC LIMIT 10",
        [$user['login']]
    );

    panel_open('Zij schoten op jou');

    if ($regels === []) {
        echo '<p>Er heeft nog niemand op je geschoten.</p>';
    } else {
        echo '<div class="tabelwikkel"><table class="lijst">';
        echo '<thead><tr><th>Wanneer</th><th>Wie</th><th>Uitkomst</th></tr></thead><tbody>';
        foreach ($regels as $r) {
            echo '<tr>'
               . '<td>' . e(datetime_nl($r['time'])) . '</td>'
               . '<td><a' . speler_rol_attr((string) $r['login']) . ' href="'
               . e(url('user.php?x=' . rawurlencode((string) $r['login']))) . '">'
               . e((string) $r['login']) . '</a></td>'
               . '<td>' . e(uitkomst_tekst((int) $r['code'])) . '</td>'
               . '</tr>';
        }
        echo '</tbody></table></div>';
    }

    panel_close();
}

function uitkomst_tekst(int $code): string
{
    return match ($code) {
        1       => 'Je werd gedood',
        2       => 'Miste je',
        3       => 'Verwondde je',
        4       => 'Jouw backfire miste',
        5       => 'Jouw backfire verwondde',
        6       => 'Jouw backfire doodde',
        default => 'Onbekend',
    };
}
