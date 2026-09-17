<?php
/**
 * Forum opruimen: topics en reacties verwijderen.
 *
 * Wat hier gerepareerd is:
 *
 *  - De rechtencontrole stond er twee keer en tegenstrijdig: bovenaan `level <
 *    200` afbreken, verderop `level < 255` afbreken. Het eerste blok draaide
 *    voor de HTML, het tweede erna, dus een moderator kreeg een halve pagina.
 *  - Verwijderen ging via ?del= en ?delr= in de URL, zonder token. Een
 *    beheerder die een geprepareerde link opende wiste een topic.
 *  - Bij het verwijderen van een topic bleven de reacties staan: de query
 *    luidde WHERE ` topic_id ` = ..., met spaties binnen de backticks. Dat is
 *    een andere kolomnaam, dus die query mislukte altijd stil. Het forum liep
 *    vol met reacties op topics die niet meer bestonden.
 *  - De hele pagina was verder een kopie van forum.php met leeslogica erin;
 *    dat hoort daar thuis, hier alleen het opruimen.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';
require BV_INC . '/opmaak.php';

const PER_PAGINA = 25;

$user    = require_level(beheerpaginas()['forum.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'topic'   => topic_verwijderen($user, int_input('id')),
            'reactie' => reactie_verwijderen($user, int_input('id')),
            default   => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'forum.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

$categorie = get('type');
$topicId   = int_input('topic');

if ($topicId > 0) {
    toon_topic($topicId);
} else {
    toon_topics(isset(forum_categorieen()[$categorie]) ? $categorie : '', int_input('p', 0, 0));
}

beheer_logregels('forum');

beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function topic_verwijderen(array $user, int $id): string
{
    $topic = q_row('SELECT * FROM `forum_topics` WHERE `id` = ?', [$id]);

    if ($topic === null) {
        throw new SpelFout('Dat topic bestaat niet.');
    }

    $reacties = 0;

    db_transaction(static function () use ($id, &$reacties): void {
        // Deze regel stond er ook, maar met spaties in de kolomnaam, waardoor
        // hij nooit iets deed en de reacties bleven rondzwerven.
        $reacties = q_count('DELETE FROM `forum_reacties` WHERE `topic_id` = ?', [$id]);
        q('DELETE FROM `forum_topics` WHERE `id` = ?', [$id]);
    });

    log_action((string) $user['login'], 'forum',
        'Topic ' . $id . ' verwijderd: ' . $topic['subject'], 0, (string) $topic['user']);

    return 'Het topic is verwijderd, samen met ' . num($reacties)
         . ($reacties === 1 ? ' reactie.' : ' reacties.');
}

/** @throws SpelFout */
function reactie_verwijderen(array $user, int $id): string
{
    $reactie = q_row('SELECT * FROM `forum_reacties` WHERE `id` = ?', [$id]);

    if ($reactie === null) {
        throw new SpelFout('Die reactie bestaat niet.');
    }

    q('DELETE FROM `forum_reacties` WHERE `id` = ?', [$id]);

    log_action((string) $user['login'], 'forum',
        'Reactie ' . $id . ' in topic ' . $reactie['topic_id'] . ' verwijderd',
        0, (string) $reactie['user']);

    return 'De reactie is verwijderd.';
}

// ==========================================================================

function toon_topics(string $categorie, int $pagina): void
{
    $waar   = $categorie !== '' ? 'WHERE t.`type` = ?' : '';
    $params = $categorie !== '' ? [$categorie] : [];

    $totaal = (int) q_val('SELECT COUNT(*) FROM `forum_topics` t ' . $waar, $params, 0);
    $start  = max(0, $pagina) * PER_PAGINA;

    $rijen = q_all(
        'SELECT t.*, (SELECT COUNT(*) FROM `forum_reacties` r WHERE r.`topic_id` = t.`id`) AS `reacties`
           FROM `forum_topics` t ' . $waar . '
          ORDER BY t.`date` DESC LIMIT ' . PER_PAGINA . ' OFFSET ' . $start,
        $params
    );

    panel_open('Categorie kiezen');
    echo '<p>';
    echo '<a href="' . e(beheer_url('forum.php')) . '">Alles</a>';
    foreach (forum_categorieen() as $sleutel => $naam) {
        echo ' &middot; <a href="' . e(beheer_url('forum.php?type=' . rawurlencode($sleutel))) . '">'
           . e($naam) . '</a>';
    }
    echo '</p>';
    panel_close();

    panel_open('Topics (' . num($totaal) . ')');

    if ($rijen === []) {
        echo '<p>Geen topics gevonden.</p>';
    } else {
        echo '<div class="tabelwikkel"><table class="lijst">';
        echo '<thead><tr><th class="getal">Nr</th><th>Onderwerp</th><th>Categorie</th>'
           . '<th>Door</th><th>Wanneer</th><th class="getal">Reacties</th><th></th></tr></thead><tbody>';

        foreach ($rijen as $rij) {
            echo '<tr>';
            echo '<td class="getal">' . (int) $rij['id'] . '</td>';
            echo '<td><a href="' . e(beheer_url('forum.php?topic=' . (int) $rij['id'])) . '">'
               . e((string) $rij['subject']) . '</a></td>';
            echo '<td>' . e(forum_categorieen()[$rij['type']] ?? (string) $rij['type']) . '</td>';
            echo '<td>' . speler_naam((string) $rij['user']) . '</td>';
            echo '<td>' . e(datetime_nl($rij['date'])) . '</td>';
            echo '<td class="getal">' . num((int) $rij['reacties']) . '</td>';
            echo '<td>' . verwijderknop('topic', (int) $rij['id']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    paginabalk($categorie, $pagina, $totaal);
    panel_close();
}

function toon_topic(int $id): void
{
    $topic = q_row('SELECT * FROM `forum_topics` WHERE `id` = ?', [$id]);

    if ($topic === null) {
        panel_open('Topic');
        notice('Dat topic bestaat niet.', 'fout');
        panel_close();
        return;
    }

    panel_open('Topic ' . $id . ': ' . $topic['subject']);
    echo '<p><a href="' . e(beheer_url('forum.php?type=' . rawurlencode((string) $topic['type'])))
       . '">&larr; Terug naar de lijst</a></p>';
    echo '<p><strong>' . speler_naam((string) $topic['user']) . '</strong> &middot; '
       . e(datetime_nl($topic['date'])) . '</p>';
    echo '<div class="forumbericht">' . bericht_html((string) $topic['message']) . '</div>';
    echo verwijderknop('topic', $id, 'Topic en alle reacties verwijderen');
    panel_close();

    $reacties = q_all(
        'SELECT * FROM `forum_reacties` WHERE `topic_id` = ? ORDER BY `date`',
        [$id]
    );

    panel_open('Reacties (' . count($reacties) . ')');

    if ($reacties === []) {
        echo '<p>Er zijn geen reacties.</p>';
    } else {
        foreach ($reacties as $reactie) {
            echo '<div class="forumreactie">';
            echo '<p><strong>' . speler_naam((string) $reactie['user']) . '</strong> &middot; '
               . e(datetime_nl($reactie['date'])) . ' '
               . verwijderknop('reactie', (int) $reactie['id']) . '</p>';
            echo '<div class="forumbericht">' . bericht_html((string) $reactie['message']) . '</div>';
            echo '</div>';
        }
    }

    panel_close();
}

function verwijderknop(string $soort, int $id, string $label = 'Verwijderen'): string
{
    return '<form method="post" style="display:inline;margin:0">' . csrf_field()
         . '<input type="hidden" name="actie" value="' . e($soort) . '">'
         . '<input type="hidden" name="id" value="' . $id . '">'
         . '<button type="submit">' . e($label) . '</button></form>';
}

function paginabalk(string $categorie, int $pagina, int $totaal): void
{
    $paginas = (int) ceil($totaal / PER_PAGINA);

    if ($paginas < 2) {
        return;
    }

    $basis = 'forum.php?' . ($categorie !== '' ? 'type=' . rawurlencode($categorie) . '&' : '');

    echo '<p class="paginering">';
    for ($i = 0; $i < $paginas; $i++) {
        echo $i === $pagina
            ? '<strong>' . ($i + 1) . '</strong> '
            : '<a href="' . e(beheer_url($basis . 'p=' . $i)) . '">' . ($i + 1) . '</a> ';
    }
    echo '</p>';
}
