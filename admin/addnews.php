<?php
/**
 * Nieuwsberichten plaatsen, bewerken en verwijderen.
 *
 * Wat hier gerepareerd is:
 *
 *  - De rechtencontrole was `if ($data->level == 1) exit;`. Elk niveau boven 1
 *    kwam er dus door, ook een niveau dat helemaal niet bestond.
 *  - Titel en tekst gingen ongefilterd in de query.
 *  - `if (!isset($_POST['title']))` liet een lege titel gewoon door: een leeg
 *    tekstveld is wel gezet, alleen leeg.
 *  - Verwijderen liep via ?d=, bewerken via ?x=, allebei zonder token, en het
 *    verwijderformulier accepteerde daarna elke id in een POST.
 *  - Drie bijna identieke blokken met eigen paginering, elk met een andere
 *    parameternaam (?p= en ?e=). Nu een lijst met knoppen per bericht.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';
require BV_INC . '/opmaak.php';

const PER_PAGINA  = 10;
const TITEL_MAX   = 120;
const TEKST_MAX   = 10000;

$user    = require_level(beheerpaginas()['addnews.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'toevoegen'   => toevoegen($user),
            'opslaan'     => opslaan($user, int_input('id')),
            'verwijderen' => verwijderen($user, int_input('id')),
            default       => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'addnews.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

$bewerken = int_input('bewerk');

if ($bewerken > 0) {
    toon_bewerken($bewerken);
} else {
    toon_nieuw();
}

toon_lijst(int_input('p', 0, 0));

beheer_logregels('nieuws');

beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function toevoegen(array $user): string
{
    [$titel, $tekst] = invoer();

    q('INSERT INTO `news` (`title`, `text`, `time`) VALUES (?, ?, ?)',
        [$titel, $tekst, time()]);

    log_action((string) $user['login'], 'nieuws', 'Geplaatst: ' . $titel, 0, '');

    return 'Het nieuwsbericht is geplaatst.';
}

/** @throws SpelFout */
function opslaan(array $user, int $id): string
{
    $bericht = q_row('SELECT * FROM `news` WHERE `id` = ?', [$id]);

    if ($bericht === null) {
        throw new SpelFout('Dat nieuwsbericht bestaat niet.');
    }

    [$titel, $tekst] = invoer();

    // De oude versie zette de tijd standaard op nu tenzij je een vinkje aan
    // zette; dat is omgedraaid, want een tikfout corrigeren hoort een bericht
    // niet weer bovenaan te zetten.
    $tijd = post('tijd_bijwerken') === '1' ? time() : (int) $bericht['time'];

    q('UPDATE `news` SET `title` = ?, `text` = ?, `time` = ? WHERE `id` = ?',
        [$titel, $tekst, $tijd, $id]);

    log_action((string) $user['login'], 'nieuws', 'Bewerkt: ' . $titel, 0, '');

    return 'Het nieuwsbericht is bijgewerkt.';
}

/** @throws SpelFout */
function verwijderen(array $user, int $id): string
{
    $bericht = q_row('SELECT `title` FROM `news` WHERE `id` = ?', [$id]);

    if ($bericht === null) {
        throw new SpelFout('Dat nieuwsbericht bestaat niet.');
    }

    q('DELETE FROM `news` WHERE `id` = ?', [$id]);

    log_action((string) $user['login'], 'nieuws',
        'Verwijderd: ' . $bericht['title'], 0, '');

    return 'Het nieuwsbericht is verwijderd.';
}

/**
 * Haal titel en tekst uit het formulier.
 *
 * @return array{0: string, 1: string}
 * @throws SpelFout
 */
function invoer(): array
{
    $titel = trim(post('titel'));
    $tekst = trim(post('tekst'));

    if ($titel === '') {
        throw new SpelFout('Vul een titel in.');
    }
    if ($tekst === '') {
        throw new SpelFout('Vul een tekst in.');
    }
    if (mb_strlen($titel) > TITEL_MAX) {
        throw new SpelFout('De titel mag hoogstens ' . TITEL_MAX . ' tekens lang zijn.');
    }
    if (mb_strlen($tekst) > TEKST_MAX) {
        throw new SpelFout('De tekst mag hoogstens ' . num(TEKST_MAX) . ' tekens lang zijn.');
    }

    return [$titel, $tekst];
}

// ==========================================================================

function toon_nieuw(): void
{
    panel_open('Nieuws plaatsen');
    formulier('toevoegen', 0, '', '', 'Plaatsen');
    panel_close();
}

function toon_bewerken(int $id): void
{
    $bericht = q_row('SELECT * FROM `news` WHERE `id` = ?', [$id]);

    if ($bericht === null) {
        panel_open('Bewerken');
        notice('Dat nieuwsbericht bestaat niet.', 'fout');
        panel_close();
        return;
    }

    panel_open('Bericht ' . $id . ' bewerken');
    formulier('opslaan', $id, (string) $bericht['title'], (string) $bericht['text'], 'Opslaan');
    echo '<p><a href="' . e(beheer_url('addnews.php')) . '">Annuleren</a></p>';
    panel_close();
}

function formulier(string $actie, int $id, string $titel, string $tekst, string $knop): void
{
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="' . e($actie) . '">';

    if ($id > 0) {
        echo '<input type="hidden" name="id" value="' . $id . '">';
    }

    echo '<div class="veldenraster">';
    echo '<label for="titel">Titel</label>';
    echo '<input id="titel" name="titel" maxlength="' . TITEL_MAX . '" required value="'
       . e($titel) . '">';
    echo '<label for="tekst">Tekst</label>';
    echo '<textarea id="tekst" name="tekst" rows="10" maxlength="' . TEKST_MAX . '" required>'
       . e($tekst) . '</textarea>';

    if ($id > 0) {
        echo '<label for="tijd">Datum op nu zetten</label>';
        echo '<input id="tijd" type="checkbox" name="tijd_bijwerken" value="1">';
    }

    echo '<span></span><button type="submit">' . e($knop) . '</button>';
    echo '</div></form>';
}

function toon_lijst(int $pagina): void
{
    $totaal = (int) q_val('SELECT COUNT(*) FROM `news`', [], 0);
    $start  = max(0, $pagina) * PER_PAGINA;

    $rijen = q_all('SELECT * FROM `news` ORDER BY `time` DESC LIMIT ' . PER_PAGINA
        . ' OFFSET ' . $start);

    panel_open('Geplaatst nieuws (' . num($totaal) . ')');

    if ($rijen === []) {
        echo '<p>Er is nog geen nieuws geplaatst.</p>';
    }

    foreach ($rijen as $rij) {
        echo '<article class="nieuwsbericht">';
        echo '<h3>' . e((string) $rij['title']) . '</h3>';
        echo '<p class="klein">' . e(timestamp_nl((int) $rij['time'])) . '</p>';
        echo '<div>' . bericht_html((string) $rij['text']) . '</div>';
        echo '<div class="knoppenrij"><a href="' . e(beheer_url('addnews.php?bewerk=' . (int) $rij['id'])) . '">Bewerken</a> '
           . '<form method="post" style="display:inline;margin:0">' . csrf_field()
           . '<input type="hidden" name="actie" value="verwijderen">'
           . '<input type="hidden" name="id" value="' . (int) $rij['id'] . '">'
           . '<button type="submit">Verwijderen</button></form></div>';
        echo '</article>';
    }

    $paginas = (int) ceil($totaal / PER_PAGINA);

    if ($paginas > 1) {
        echo '<p class="paginering">';
        for ($i = 0; $i < $paginas; $i++) {
            echo $i === $pagina
                ? '<strong>' . ($i + 1) . '</strong> '
                : '<a href="' . e(beheer_url('addnews.php?p=' . $i)) . '">' . ($i + 1) . '</a> ';
        }
        echo '</p>';
    }

    panel_close();
}
