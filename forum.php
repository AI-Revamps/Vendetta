<?php
/**
 * Forum: categorieën, topics en reacties.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - De auteur van een topic kwam uit een verborgen formulierveld:
 *    INSERT INTO forum_topics (user, ...) VALUES ('$_POST[user]', ...).
 *    Iedereen kon dus posten onder de naam van een ander, inclusief die van
 *    een beheerder. De auteur komt nu altijd uit de sessie.
 *  - De categorie kwam eveneens ongecontroleerd uit het formulier, zodat er
 *    topics in niet-bestaande categorieën konden belanden.
 *  - Berichten werden getoond met nl2br(stripslashes(...)) zonder enige
 *    escaping, en [img]...[/img] werd rechtstreeks omgezet naar een img-tag.
 *    Beide leverden XSS op. Weergave loopt nu via bericht_html().
 *  - Verwijderen en bewerken liepen via GET-links, dus een afbeelding in een
 *    bericht kon andermans topic laten verwijderen.
 *  - Het bewerkformulier zette de bestaande tekst ongeëscaped in een attribuut
 *    met enkele aanhalingstekens, waardoor een apostrof het veld afbrak.
 *  - Bij het verwijderen van een topic bevatte de query voor de reacties
 *    een kolomnaam met spaties erin. Die query faalde altijd, dus de reacties
 *    bleven als wezen in de database achter.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/opmaak.php';

const TOPICS_PER_PAGINA   = 20;
const REACTIES_PER_PAGINA = 10;
const ONDERWERP_MAX       = 80;
const FORUM_BERICHT_MAX   = 10000;

/** De categorieën en de leesrechten staan in inc/game.php: het beheer gebruikt ze ook. */
function categorieen(): array
{
    return forum_categorieen();
}

function is_alleen_lezen(string $type): bool
{
    return forum_alleen_lezen($type);
}

$user = require_login();

$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = verwerk($user, post('actie'));
        $type    = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Forum');

if ($melding !== null) {
    notice(e($melding), $type);
}

if (int_input('topic') > 0) {
    toon_topic($user, int_input('topic'), int_input('p', 0, 0));
} elseif (isset(categorieen()[get('type')])) {
    toon_categorie($user, get('type'), int_input('p', 0, 0));
} elseif (get('bewerk') !== '') {
    toon_bewerken($user, int_input('bewerk'));
} else {
    toon_overzicht();
}

layout_footer();

// ==========================================================================
// Verwerking
// ==========================================================================

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'nieuw_topic' => topic_plaatsen($user, get('type') !== '' ? get('type') : post('type'),
                                        post('subject'), post('message')),
        'reageer'     => reactie_plaatsen($user, int_input('topic'), post('message')),
        'bewerk'      => topic_bewerken($user, int_input('id'), post('subject'), post('message')),
        'verwijder'   => verwijderen($user, 'topic', int_input('id')),
        'verwijder_reactie' => verwijderen($user, 'reactie', int_input('id')),
        default       => throw new SpelFout('Onbekende handeling.'),
    };
}

/** Mag deze speler dit bericht beheren? */
function mag_beheren(array $user, string $auteur): bool
{
    return $auteur === $user['login'] || (int) $user['level'] >= LEVEL_ADMIN;
}

/** @throws SpelFout */
function controleer_tekst(string $onderwerp, string $tekst): array
{
    $onderwerp = trim($onderwerp);
    $tekst     = trim($tekst);

    if (mb_strlen(str_replace(' ', '', $onderwerp)) < 2) {
        throw new SpelFout('Vul een duidelijk onderwerp in.');
    }
    if (mb_strlen(str_replace(' ', '', $tekst)) < 2) {
        throw new SpelFout('Vul een bericht in.');
    }
    if (mb_strlen($tekst) > FORUM_BERICHT_MAX) {
        throw new SpelFout('Je bericht mag hoogstens ' . num(FORUM_BERICHT_MAX) . ' tekens lang zijn.');
    }

    return [mb_substr($onderwerp, 0, ONDERWERP_MAX), $tekst];
}

/** @throws SpelFout */
function topic_plaatsen(array $user, string $categorie, string $onderwerp, string $tekst): string
{
    if (!isset(categorieen()[$categorie])) {
        throw new SpelFout('Die categorie bestaat niet.');
    }
    if (is_alleen_lezen($categorie)) {
        throw new SpelFout('In deze categorie plaatst alleen het spel berichten.');
    }
    if (is_dead()) {
        throw new SpelFout('Je bent dood en kunt niet meer posten.');
    }

    [$onderwerp, $tekst] = controleer_tekst($onderwerp, $tekst);

    // De auteur komt uit de sessie, nooit uit het formulier.
    q(
        'INSERT INTO `forum_topics` (`user`, `type`, `subject`, `message`, `date`)
              VALUES (?, ?, ?, ?, NOW())',
        [$user['login'], $categorie, $onderwerp, $tekst]
    );

    return 'Je topic is geplaatst.';
}

/** @throws SpelFout */
function reactie_plaatsen(array $user, int $topicId, string $tekst): string
{
    if (is_dead()) {
        throw new SpelFout('Je bent dood en kunt niet meer posten.');
    }

    $topic = q_row('SELECT * FROM `forum_topics` WHERE `id` = ?', [$topicId]);

    if ($topic === null) {
        throw new SpelFout('Dat topic bestaat niet.');
    }
    if (is_alleen_lezen((string) $topic['type'])) {
        throw new SpelFout('In deze categorie kun je niet reageren.');
    }

    [, $tekst] = controleer_tekst('reactie', $tekst);

    q(
        'INSERT INTO `forum_reacties` (`topic_id`, `user`, `subject`, `message`, `date`)
              VALUES (?, ?, ?, ?, NOW())',
        [$topicId, $user['login'], mb_substr('Re: ' . $topic['subject'], 0, ONDERWERP_MAX), $tekst]
    );

    return 'Je reactie is geplaatst.';
}

/** @throws SpelFout */
function topic_bewerken(array $user, int $id, string $onderwerp, string $tekst): string
{
    $topic = q_row('SELECT * FROM `forum_topics` WHERE `id` = ?', [$id]);

    if ($topic === null) {
        throw new SpelFout('Dat topic bestaat niet.');
    }
    if (!mag_beheren($user, (string) $topic['user'])) {
        throw new SpelFout('Dit topic is niet van jou.');
    }

    [$onderwerp, $tekst] = controleer_tekst($onderwerp, $tekst);

    q('UPDATE `forum_topics` SET `subject` = ?, `message` = ? WHERE `id` = ?',
        [$onderwerp, $tekst, $id]);

    return 'Je topic is bijgewerkt.';
}

/** @throws SpelFout */
function verwijderen(array $user, string $wat, int $id): string
{
    $tabel = $wat === 'topic' ? 'forum_topics' : 'forum_reacties';

    $rij = q_row("SELECT `id`, `user` FROM `{$tabel}` WHERE `id` = ?", [$id]);

    if ($rij === null) {
        throw new SpelFout('Dat bericht bestaat niet.');
    }
    if (!mag_beheren($user, (string) $rij['user'])) {
        throw new SpelFout('Dit bericht is niet door jou geplaatst.');
    }

    return db_transaction(static function () use ($wat, $tabel, $id): string {
        q("DELETE FROM `{$tabel}` WHERE `id` = ?", [$id]);

        if ($wat === 'topic') {
            q('DELETE FROM `forum_reacties` WHERE `topic_id` = ?', [$id]);
            return 'Het topic en de bijbehorende reacties zijn verwijderd.';
        }

        return 'De reactie is verwijderd.';
    });
}

// ==========================================================================
// Weergave
// ==========================================================================

function toon_overzicht(): void
{
    $tellingen = [];
    foreach (q_all('SELECT `type`, COUNT(*) AS `aantal` FROM `forum_topics` GROUP BY `type`') as $rij) {
        $tellingen[(string) $rij['type']] = (int) $rij['aantal'];
    }

    panel_open('Forum');
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Categorie</th><th class="getal">Topics</th><th>Laatste bericht</th></tr></thead><tbody>';

    foreach (categorieen() as $sleutel => $naam) {
        $laatste = q_row(
            'SELECT `user`, `date` FROM `forum_topics` WHERE `type` = ? ORDER BY `date` DESC LIMIT 1',
            [$sleutel]
        );

        echo '<tr>';
        echo '<td><a href="' . e(url('forum.php?type=' . $sleutel)) . '">' . e($naam) . '</a>'
           . (is_alleen_lezen($sleutel) ? ' <small>(alleen lezen)</small>' : '') . '</td>';
        echo '<td class="getal">' . num($tellingen[$sleutel] ?? 0) . '</td>';
        echo '<td>' . ($laatste === null ? '-'
             : speler_naam((string) $laatste['user']) . ' &middot; ' . e(datetime_nl($laatste['date']))) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    panel_close();
}

function toon_categorie(array $user, string $categorie, int $pagina): void
{
    $naam   = categorieen()[$categorie];
    $totaal = (int) q_val('SELECT COUNT(*) FROM `forum_topics` WHERE `type` = ?', [$categorie], 0);
    $offset = max(0, $pagina) * TOPICS_PER_PAGINA;

    $topics = q_all(
        'SELECT t.*, (SELECT COUNT(*) FROM `forum_reacties` r WHERE r.`topic_id` = t.`id`) AS `reacties`
           FROM `forum_topics` t
          WHERE t.`type` = ?
       ORDER BY t.`date` DESC
          LIMIT ' . TOPICS_PER_PAGINA . ' OFFSET ' . $offset,
        [$categorie]
    );

    panel_open('Forum — ' . $naam);
    echo '<p><a href="' . e(url('forum.php')) . '">&larr; Alle categorieën</a></p>';

    if ($topics === []) {
        echo '<p>Er staan hier nog geen topics.</p>';
    } else {
        echo '<div class="tabelwikkel"><table class="lijst">';
        echo '<thead><tr><th>Onderwerp</th><th>Door</th><th class="getal">Reacties</th><th>Wanneer</th></tr></thead><tbody>';
        foreach ($topics as $topic) {
            echo '<tr>';
            echo '<td><a href="' . e(url('forum.php?topic=' . (int) $topic['id'])) . '">'
               . e((string) $topic['subject']) . '</a></td>';
            echo '<td>' . speler_naam((string) $topic['user']) . '</td>';
            echo '<td class="getal">' . num((int) $topic['reacties']) . '</td>';
            echo '<td>' . e(datetime_nl($topic['date'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    paginering(url('forum.php?type=' . $categorie), $pagina, $totaal, TOPICS_PER_PAGINA);
    panel_close();

    if (!is_alleen_lezen($categorie) && !is_dead()) {
        panel_open('Nieuw topic in ' . $naam);
        echo '<form method="post" action="' . e(url('forum.php?type=' . $categorie)) . '">' . csrf_field();
        echo '<input type="hidden" name="actie" value="nieuw_topic">';
        echo '<div class="veldenraster">';
        echo '<label for="subject">Onderwerp</label>';
        echo '<input id="subject" name="subject" maxlength="' . ONDERWERP_MAX . '" required>';
        echo '<label for="message">Bericht</label>';
        echo '<textarea id="message" name="message" maxlength="' . FORUM_BERICHT_MAX . '" required></textarea>';
        echo '<span></span><button type="submit">Plaatsen</button>';
        echo '</div></form>';
        panel_close();
    }
}

function toon_topic(array $user, int $id, int $pagina): void
{
    $topic = q_row('SELECT * FROM `forum_topics` WHERE `id` = ?', [$id]);

    if ($topic === null) {
        panel_open('Forum');
        notice('Dat topic bestaat niet.', 'fout');
        panel_close();
        return;
    }

    $categorie = (string) $topic['type'];
    $naam      = categorieen()[$categorie] ?? $categorie;

    panel_open((string) $topic['subject']);
    echo '<p><a href="' . e(url('forum.php?type=' . rawurlencode($categorie))) . '">&larr; '
       . e($naam) . '</a></p>';

    bericht_blok($user, $topic, 'topic');
    panel_close();

    $totaal = (int) q_val('SELECT COUNT(*) FROM `forum_reacties` WHERE `topic_id` = ?', [$id], 0);
    $offset = max(0, $pagina) * REACTIES_PER_PAGINA;

    $reacties = q_all(
        'SELECT * FROM `forum_reacties` WHERE `topic_id` = ? ORDER BY `date` ASC
          LIMIT ' . REACTIES_PER_PAGINA . ' OFFSET ' . $offset,
        [$id]
    );

    if ($reacties !== []) {
        panel_open($totaal . ' ' . ($totaal === 1 ? 'reactie' : 'reacties'));
        foreach ($reacties as $reactie) {
            bericht_blok($user, $reactie, 'reactie');
        }
        paginering(url('forum.php?topic=' . $id), $pagina, $totaal, REACTIES_PER_PAGINA);
        panel_close();
    }

    if (!is_alleen_lezen($categorie) && !is_dead()) {
        panel_open('Reageren');
        echo '<form method="post" action="' . e(url('forum.php?topic=' . $id)) . '">' . csrf_field();
        echo '<input type="hidden" name="actie" value="reageer">';
        echo '<input type="hidden" name="topic" value="' . $id . '">';
        echo '<textarea name="message" maxlength="' . FORUM_BERICHT_MAX . '" required '
           . 'aria-label="Je reactie"></textarea>';
        echo '<p><button type="submit">Plaats reactie</button></p>';
        echo '</form>';
        panel_close();
    }
}

/** Eén bericht met kop, tekst en beheerknoppen. */
function bericht_blok(array $user, array $bericht, string $soort): void
{
    echo '<div class="forumbericht">';
    echo '<div class="forumkop"><strong><a' . speler_rol_attr((string) $bericht['user']) . ' href="'
       . e(url('user.php?x=' . rawurlencode((string) $bericht['user']))) . '">'
       . e((string) $bericht['user']) . '</a></strong> '
       . '<span class="uitleg">' . e(datetime_nl($bericht['date'])) . '</span></div>';

    echo '<div class="berichttekst">' . bericht_html((string) $bericht['message']) . '</div>';

    if (mag_beheren($user, (string) $bericht['user'])) {
        echo '<p>';
        if ($soort === 'topic') {
            echo '<a class="knop" href="' . e(url('forum.php?bewerk=' . (int) $bericht['id']))
               . '">Bewerken</a> ';
        }
        echo '<form method="post" style="display:inline">' . csrf_field()
           . '<input type="hidden" name="actie" value="'
           . ($soort === 'topic' ? 'verwijder' : 'verwijder_reactie') . '">'
           . '<input type="hidden" name="id" value="' . (int) $bericht['id'] . '">'
           . '<button type="submit">Verwijderen</button></form>';
        echo '</p>';
    }

    echo '</div>';
}

function toon_bewerken(array $user, int $id): void
{
    $topic = q_row('SELECT * FROM `forum_topics` WHERE `id` = ?', [$id]);

    panel_open('Topic bewerken');

    if ($topic === null) {
        notice('Dat topic bestaat niet.', 'fout');
    } elseif (!mag_beheren($user, (string) $topic['user'])) {
        notice('Dit topic is niet van jou.', 'fout');
    } else {
        echo '<form method="post">' . csrf_field();
        echo '<input type="hidden" name="actie" value="bewerk">';
        echo '<input type="hidden" name="id" value="' . (int) $topic['id'] . '">';
        echo '<div class="veldenraster">';
        echo '<label for="subject">Onderwerp</label>';
        // Geëscapet en tussen dubbele aanhalingstekens: in de oude versie stond
        // hier een enkel aanhalingsteken zonder escaping, dus een apostrof in
        // het onderwerp brak het veld af.
        echo '<input id="subject" name="subject" maxlength="' . ONDERWERP_MAX . '" required value="'
           . e((string) $topic['subject']) . '">';
        echo '<label for="message">Bericht</label>';
        echo '<textarea id="message" name="message" maxlength="' . FORUM_BERICHT_MAX . '" required>'
           . e((string) $topic['message']) . '</textarea>';
        echo '<span></span><button type="submit">Opslaan</button>';
        echo '</div></form>';
        echo '<p><a href="' . e(url('forum.php?topic=' . (int) $topic['id'])) . '">Terug naar het topic</a></p>';
    }

    panel_close();
}

function paginering(string $basis, int $pagina, int $totaal, int $perPagina): void
{
    $paginas = (int) ceil($totaal / $perPagina);

    if ($paginas < 2) {
        return;
    }

    $scheiding = str_contains($basis, '?') ? '&' : '?';

    echo '<p class="paginering">';
    for ($i = 0; $i < $paginas; $i++) {
        if ($i === $pagina) {
            echo '<strong>' . ($i + 1) . '</strong> ';
        } else {
            echo '<a href="' . e($basis . $scheiding . 'p=' . $i) . '">' . ($i + 1) . '</a> ';
        }
    }
    echo '</p>';
}
