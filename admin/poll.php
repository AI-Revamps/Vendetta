<?php
/**
 * Polls beheren: aanmaken, archiveren en verwijderen.
 *
 * Wat hier gerepareerd is:
 *
 *  - De rechtencontrole stond er twee keer: bovenaan `level < 200` afbreken,
 *    verderop `level < 1000`. Het eerste blok draaide voor de HTML, het tweede
 *    erna. Een moderator kreeg dus een halve pagina te zien.
 *  - `$_GET['x'] == u` vergelijkt met de ongedefinieerde constante `u`. Dat
 *    werkte alleen omdat PHP daar toen de string 'u' van maakte; sinds PHP 8
 *    is het een fatale fout, dus deze pagina deed het sowieso niet meer.
 *  - Vraag en alle acht antwoorden gingen ongefilterd in de query.
 *  - Archiveren en verwijderen liepen via een GET-link naar een formulier dat
 *    daarna elke id accepteerde, zonder token.
 *  - Er kon meer dan een poll tegelijk actief zijn; poll.php toonde er dan
 *    willekeurig een van.
 *  - De uitslag was nergens te zien.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

/** Zoveel keuzes kent de polltabel. */
const MAX_KEUZES = 10;

$user    = require_level(beheerpaginas()['poll.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'toevoegen'   => toevoegen($user),
            'activeren'   => activeren($user, int_input('id')),
            'archiveren'  => archiveren($user, int_input('id')),
            'verwijderen' => verwijderen($user, int_input('id')),
            default       => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'poll.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

$tonen = int_input('poll');

if ($tonen > 0) {
    toon_uitslag($tonen);
}

toon_nieuw();
toon_lijst();

beheer_logregels('poll');

beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function toevoegen(array $user): string
{
    $vraag = trim(post('vraag'));

    if ($vraag === '') {
        throw new SpelFout('Vul een vraag in.');
    }
    if (mb_strlen($vraag) > 200) {
        throw new SpelFout('De vraag mag hoogstens 200 tekens lang zijn.');
    }

    $keuzes = [];

    for ($i = 1; $i <= MAX_KEUZES; $i++) {
        $keuze = trim(post('keuze' . $i));

        if ($keuze !== '') {
            $keuzes[] = mb_substr($keuze, 0, 64);
        }
    }

    if (count($keuzes) < 2) {
        throw new SpelFout('Vul minstens twee keuzes in.');
    }

    // De lege keuzes worden naar achteren geschoven; poll.php toont alleen
    // wat niet leeg is, dus een gat in het midden zou een keuze doen verdwijnen.
    $kolommen = ['`vraag`', '`actief`', '`datum`'];
    $vragen   = ['?', '0', '?'];
    $params   = [$vraag, time()];

    for ($i = 1; $i <= MAX_KEUZES; $i++) {
        $kolommen[] = '`keuze' . $i . '`';
        $vragen[]   = '?';
        $params[]   = $keuzes[$i - 1] ?? '';
    }

    q('INSERT INTO `poll` (' . implode(', ', $kolommen) . ') VALUES ('
        . implode(', ', $vragen) . ')', $params);

    log_action((string) $user['login'], 'poll', 'Aangemaakt: ' . $vraag, 0, '');

    return 'De poll is aangemaakt. Zet hem hieronder actief om hem te tonen.';
}

/** @throws SpelFout */
function activeren(array $user, int $id): string
{
    $poll = q_row('SELECT `vraag` FROM `poll` WHERE `id` = ?', [$id]);

    if ($poll === null) {
        throw new SpelFout('Die poll bestaat niet.');
    }

    // Er mag er maar een actief zijn, anders kiest poll.php er willekeurig een.
    db_transaction(static function () use ($id): void {
        q('UPDATE `poll` SET `actief` = 0 WHERE `actief` = 1');
        q('UPDATE `poll` SET `actief` = 1 WHERE `id` = ?', [$id]);
    });

    log_action((string) $user['login'], 'poll', 'Actief gezet: ' . $poll['vraag'], 0, '');

    return 'Deze poll staat nu open. Een eventuele andere open poll is gesloten.';
}

/** @throws SpelFout */
function archiveren(array $user, int $id): string
{
    if (q_count('UPDATE `poll` SET `actief` = 0 WHERE `id` = ? AND `actief` = 1', [$id]) === 0) {
        throw new SpelFout('Die poll staat niet open.');
    }

    log_action((string) $user['login'], 'poll', 'Gesloten: poll ' . $id, 0, '');

    return 'De poll is gesloten. De uitslag blijft bewaard.';
}

/** @throws SpelFout */
function verwijderen(array $user, int $id): string
{
    $poll = q_row('SELECT `vraag` FROM `poll` WHERE `id` = ?', [$id]);

    if ($poll === null) {
        throw new SpelFout('Die poll bestaat niet.');
    }

    db_transaction(static function () use ($id): void {
        q('DELETE FROM `poll_stemmen` WHERE `poll_id` = ?', [$id]);
        q('DELETE FROM `poll` WHERE `id` = ?', [$id]);
    });

    log_action((string) $user['login'], 'poll', 'Verwijderd: ' . $poll['vraag'], 0, '');

    return 'De poll en de bijbehorende stemmen zijn verwijderd.';
}

// ==========================================================================

function toon_nieuw(): void
{
    panel_open('Nieuwe poll');
    echo '<p>Twee keuzes zijn genoeg; wat je leeg laat wordt niet getoond.</p>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="toevoegen">';
    echo '<div class="veldenraster">';
    echo '<label for="vraag">Vraag</label>';
    echo '<input id="vraag" name="vraag" maxlength="200" required>';

    for ($i = 1; $i <= MAX_KEUZES; $i++) {
        echo '<label for="keuze' . $i . '">Keuze ' . $i . '</label>';
        echo '<input id="keuze' . $i . '" name="keuze' . $i . '" maxlength="64"'
           . ($i <= 2 ? ' required' : '') . '>';
    }

    echo '<span></span><button type="submit">Aanmaken</button>';
    echo '</div></form>';
    panel_close();
}

function toon_lijst(): void
{
    $polls = q_all('SELECT * FROM `poll` ORDER BY `actief` DESC, `datum` DESC');

    panel_open('Polls (' . count($polls) . ')');

    if ($polls === []) {
        echo '<p>Er zijn nog geen polls.</p>';
        panel_close();
        return;
    }

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th class="getal">Nr</th><th>Vraag</th><th>Aangemaakt</th>'
       . '<th class="getal">Stemmen</th><th>Status</th><th></th></tr></thead><tbody>';

    foreach ($polls as $poll) {
        $id       = (int) $poll['id'];
        $stemmen  = (int) q_val('SELECT COUNT(*) FROM `poll_stemmen` WHERE `poll_id` = ?', [$id], 0);
        $isActief = (int) $poll['actief'] === 1;

        echo '<tr>';
        echo '<td class="getal">' . $id . '</td>';
        echo '<td><a href="' . e(beheer_url('poll.php?poll=' . $id)) . '">'
           . e((string) $poll['vraag']) . '</a></td>';
        echo '<td>' . e(timestamp_nl((int) $poll['datum'], false)) . '</td>';
        echo '<td class="getal">' . num($stemmen) . '</td>';
        echo '<td>' . ($isActief ? 'open' : 'gesloten') . '</td>';
        echo '<td>'
           . knop($isActief ? 'archiveren' : 'activeren', $id, $isActief ? 'Sluiten' : 'Openen')
           . ' ' . knop('verwijderen', $id, 'Verwijderen')
           . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    panel_close();
}

function toon_uitslag(int $id): void
{
    $poll = q_row('SELECT * FROM `poll` WHERE `id` = ?', [$id]);

    if ($poll === null) {
        panel_open('Uitslag');
        notice('Die poll bestaat niet.', 'fout');
        panel_close();
        return;
    }

    $totaal = 0;
    $regels = [];

    for ($i = 1; $i <= MAX_KEUZES; $i++) {
        if ((string) $poll['keuze' . $i] === '') {
            continue;
        }

        $aantal   = (int) $poll['antwoord' . $i];
        $totaal  += $aantal;
        $regels[] = ['tekst' => (string) $poll['keuze' . $i], 'aantal' => $aantal];
    }

    panel_open('Uitslag: ' . $poll['vraag']);

    if ($regels === []) {
        echo '<p>Deze poll heeft geen keuzes.</p>';
    } else {
        echo '<div class="tabelwikkel"><table class="lijst">';
        echo '<thead><tr><th>Keuze</th><th class="getal">Stemmen</th>'
           . '<th class="getal">Aandeel</th></tr></thead><tbody>';

        foreach ($regels as $regel) {
            $deel = $totaal > 0 ? round($regel['aantal'] / $totaal * 100) : 0;

            echo '<tr>';
            echo '<td>' . e($regel['tekst']) . '</td>';
            echo '<td class="getal">' . num($regel['aantal']) . '</td>';
            echo '<td class="getal">' . $deel . '%</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '<p>Totaal ' . num($totaal) . ' ' . ($totaal === 1 ? 'stem' : 'stemmen') . '.</p>';
    }

    echo '<p><a href="' . e(beheer_url('poll.php')) . '">&larr; Terug</a></p>';
    panel_close();
}

function knop(string $actie, int $id, string $label): string
{
    return '<form method="post" style="display:inline;margin:0">' . csrf_field()
         . '<input type="hidden" name="actie" value="' . e($actie) . '">'
         . '<input type="hidden" name="id" value="' . $id . '">'
         . '<button type="submit">' . e($label) . '</button></form>';
}
