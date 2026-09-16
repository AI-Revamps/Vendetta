<?php
/**
 * Privéberichten: postvak in, verzonden, bewaard, lezen en versturen.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - [inc]adres[/inc] werd omgezet naar <iframe src=adres>. Iedereen kon
 *    daarmee een willekeurige externe pagina in andermans postvak tonen, wat
 *    een kant-en-klare manier is om een nagemaakt inlogscherm voor te
 *    schotelen. Die tag is niet overgenomen.
 *  - De enige filtering was preg_replace('/</','&#60;') bij het opslaan. Die
 *    verving alleen het kleiner-dan-teken, dus aanhalingstekens bleven staan
 *    en attributen waren nog te openen. Berichten die al in de database
 *    stonden bleven bovendien ongefilterd.
 *  - Webadressen werden klikbaar gemaakt door de tekst rechtstreeks in een
 *    href te zetten, zonder controle op het schema.
 *  - Verwijderen, bewaren en ontbewaren liepen via GET-links.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/opmaak.php';

const BERICHTEN_PER_PAGINA = 25;
const ONDERWERP_MAX        = 80;
const BERICHT_MAX          = 5000;

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

$pagina = get('p') !== '' ? get('p') : 'inbox';

layout_header('Berichten');

if ($melding !== null) {
    notice(e($melding), $type);
}

toon_tabs($pagina, $user);

match ($pagina) {
    'new'   => toon_nieuw(),
    'read'  => toon_bericht($user, int_input('id')),
    'send'  => toon_lijst($user, 'verzonden'),
    'saved' => toon_lijst($user, 'bewaard'),
    default => toon_lijst($user, 'inbox'),
};

layout_footer();

// ==========================================================================

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'versturen' => versturen($user, post('to'), post('subject'), post('message')),
        'verwijder' => bulk($user, 'verwijder'),
        'bewaar'    => bulk($user, 'bewaar'),
        'ontbewaar' => bulk($user, 'ontbewaar'),
        default     => throw new SpelFout('Onbekende handeling.'),
    };
}

/** @throws SpelFout */
function versturen(array $user, string $naar, string $onderwerp, string $tekst): string
{
    $naar      = trim($naar);
    $onderwerp = trim($onderwerp);
    $tekst     = trim($tekst);

    if ($naar === '') {
        throw new SpelFout('Vul in naar wie het bericht moet.');
    }
    if (strcasecmp($naar, (string) $user['login']) === 0) {
        throw new SpelFout('Je kunt jezelf geen bericht sturen.');
    }
    if ($tekst === '') {
        throw new SpelFout('Je bericht is leeg.');
    }
    if (mb_strlen($tekst) > BERICHT_MAX) {
        throw new SpelFout('Je bericht mag hoogstens ' . num(BERICHT_MAX) . ' tekens lang zijn.');
    }

    $ontvanger = q_row('SELECT `login`, `activated`, `status` FROM `users` WHERE `login` = ?', [$naar]);

    if ($ontvanger === null) {
        throw new SpelFout('Die speler bestaat niet.');
    }
    if ((int) $ontvanger['activated'] !== 1) {
        throw new SpelFout($ontvanger['login'] . ' heeft zijn account nog niet geactiveerd.');
    }
    if ($ontvanger['status'] !== 'levend') {
        throw new SpelFout($ontvanger['login'] . ' is dood en leest geen berichten meer.');
    }

    // De tekst gaat onbewerkt de database in. Opmaken en onschadelijk maken
    // gebeurt bij het tonen, zodat berichten die er al staan ook veilig zijn.
    q(
        'INSERT INTO `messages` (`time`, `from`, `to`, `subject`, `message`) VALUES (NOW(), ?, ?, ?, ?)',
        [
            $user['login'],
            $ontvanger['login'],
            mb_substr($onderwerp === '' ? '(geen onderwerp)' : $onderwerp, 0, ONDERWERP_MAX),
            $tekst,
        ]
    );

    return 'Je bericht aan ' . $ontvanger['login'] . ' is verstuurd.';
}

/**
 * Verwijderen, bewaren of ontbewaren van aangevinkte berichten.
 *
 * @throws SpelFout
 */
function bulk(array $user, string $wat): string
{
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['id'] ?? []))));

    if ($ids === []) {
        throw new SpelFout('Je hebt geen berichten aangevinkt.');
    }

    // Plaatshouders opbouwen; de waarden blijven parameters.
    $gaten  = implode(',', array_fill(0, count($ids), '?'));
    // Eigendom staat in de WHERE: je komt alleen aan je eigen berichten.
    $params = array_merge($ids, [$user['login'], $user['login']]);

    $aantal = match ($wat) {
        'verwijder' => q_count(
            "DELETE FROM `messages` WHERE `id` IN ({$gaten}) AND (`to` = ? OR `from` = ?)", $params),
        'bewaar'    => q_count(
            "UPDATE `messages` SET `save` = 1 WHERE `id` IN ({$gaten}) AND (`to` = ? OR `from` = ?)", $params),
        'ontbewaar' => q_count(
            "UPDATE `messages` SET `save` = 0 WHERE `id` IN ({$gaten}) AND (`to` = ? OR `from` = ?)", $params),
        default     => throw new SpelFout('Onbekende handeling.'),
    };

    $woord = $aantal === 1 ? 'bericht' : 'berichten';

    return match ($wat) {
        'verwijder' => $aantal . ' ' . $woord . ' verwijderd.',
        'bewaar'    => $aantal . ' ' . $woord . ' bewaard.',
        default     => $aantal . ' ' . $woord . ' niet meer bewaard.',
    };
}

// ==========================================================================
// Weergave
// ==========================================================================

function toon_tabs(string $huidig, array $user): void
{
    $tabs = [
        'inbox' => 'Postvak in',
        'send'  => 'Verzonden',
        'saved' => 'Bewaard',
        'new'   => 'Nieuw bericht',
    ];

    $ongelezen = (int) q_val('SELECT COUNT(*) FROM `messages` WHERE `to` = ? AND `read` = 0',
        [$user['login']], 0);

    echo '<p>';
    foreach ($tabs as $sleutel => $label) {
        $actief = $sleutel === $huidig ? ' knop-nadruk' : '';
        $telnu  = $sleutel === 'inbox' && $ongelezen > 0 ? ' (' . $ongelezen . ')' : '';
        echo '<a class="knop' . $actief . '" style="display:inline-block;margin-right:.3rem" href="'
           . e(url('message.php?p=' . $sleutel)) . '">' . e($label) . $telnu . '</a>';
    }
    echo '</p>';
}

function toon_lijst(array $user, string $soort): void
{
    [$titel, $waar, $params] = match ($soort) {
        'verzonden' => ['Verzonden berichten', '`from` = ?', [$user['login']]],
        'bewaard'   => ['Bewaarde berichten', '(`to` = ? OR `from` = ?) AND `save` = 1',
                        [$user['login'], $user['login']]],
        default     => ['Postvak in', '`to` = ?', [$user['login']]],
    };

    $berichten = q_all(
        "SELECT * FROM `messages` WHERE {$waar} ORDER BY `time` DESC LIMIT " . BERICHTEN_PER_PAGINA,
        $params
    );

    panel_open($titel);

    if ($berichten === []) {
        echo '<p>Geen berichten.</p>';
        panel_close();
        return;
    }

    echo '<form method="post">' . csrf_field();
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th></th><th>' . ($soort === 'verzonden' ? 'Aan' : 'Van')
       . '</th><th>Onderwerp</th><th>Wanneer</th></tr></thead><tbody>';

    foreach ($berichten as $bericht) {
        $nieuw       = $soort !== 'verzonden' && (int) $bericht['read'] === 0;
        $tegenpartij = $soort === 'verzonden' ? $bericht['to'] : $bericht['from'];

        echo '<tr>';
        echo '<td><input type="checkbox" name="id[]" value="' . (int) $bericht['id']
           . '" aria-label="Selecteer bericht"></td>';
        echo '<td>' . speler_naam((string) $tegenpartij) . '</td>';
        echo '<td><a href="' . e(url('message.php?p=read&id=' . (int) $bericht['id'])) . '">'
           . ($nieuw ? '<strong>' : '') . e((string) $bericht['subject']) . ($nieuw ? '</strong>' : '')
           . '</a>' . ((int) $bericht['save'] === 1 ? ' <small>(bewaard)</small>' : '') . '</td>';
        echo '<td>' . e(datetime_nl($bericht['time'])) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    echo '<p><button type="submit" name="actie" value="verwijder">Verwijder</button> '
       . '<button type="submit" name="actie" value="bewaar">Bewaar</button> '
       . '<button type="submit" name="actie" value="ontbewaar">Niet meer bewaren</button></p>';
    echo '</form>';

    panel_close();
}

function toon_bericht(array $user, int $id): void
{
    $bericht = q_row(
        'SELECT * FROM `messages` WHERE `id` = ? AND (`to` = ? OR `from` = ?)',
        [$id, $user['login'], $user['login']]
    );

    if ($bericht === null) {
        panel_open('Bericht');
        notice('Dit bericht bestaat niet of is niet voor jou.', 'fout');
        panel_close();
        return;
    }

    if ($bericht['to'] === $user['login'] && (int) $bericht['read'] === 0) {
        q('UPDATE `messages` SET `read` = 1 WHERE `id` = ?', [$id]);
    }

    panel_open($bericht['subject'] === '' ? '(geen onderwerp)' : (string) $bericht['subject']);

    echo '<p class="uitleg">Van <strong>' . speler_naam((string) $bericht['from']) . '</strong> aan <strong>'
       . speler_naam((string) $bericht['to']) . '</strong> op ' . e(datetime_nl($bericht['time'])) . '</p>';

    // Alleen systeemberichten mogen spelknoppen tonen, nooit tekst van spelers.
    $vanSysteem = $bericht['from'] === 'Notificatie';

    echo '<div class="berichttekst">'
       . bericht_html((string) $bericht['message'], ['spelacties' => $vanSysteem])
       . '</div>';

    if (!$vanSysteem && $bericht['from'] !== $user['login']) {
        $onderwerp = 'Re: ' . preg_replace('/^(Re:\s*)+/i', '', (string) $bericht['subject']);
        echo '<p><a class="knop" href="' . e(url('message.php?p=new&to='
           . rawurlencode((string) $bericht['from']) . '&subject=' . rawurlencode($onderwerp)))
           . '">Antwoorden</a></p>';
    }

    echo '<form method="post">' . csrf_field()
       . '<input type="hidden" name="id[]" value="' . (int) $bericht['id'] . '">'
       . '<button type="submit" name="actie" value="verwijder">Verwijder</button> '
       . '<button type="submit" name="actie" value="'
       . ((int) $bericht['save'] === 1 ? 'ontbewaar">Niet meer bewaren' : 'bewaar">Bewaar')
       . '</button></form>';

    panel_close();
}

function toon_nieuw(): void
{
    panel_open('Nieuw bericht');

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="versturen">';
    echo '<div class="veldenraster">';

    echo '<label for="to">Aan</label>';
    echo '<input id="to" name="to" maxlength="16" required value="'
       . e(get('to') !== '' ? get('to') : post('to')) . '">';

    echo '<label for="subject">Onderwerp</label>';
    echo '<input id="subject" name="subject" maxlength="' . ONDERWERP_MAX . '" value="'
       . e(get('subject') !== '' ? get('subject') : post('subject')) . '">';

    echo '<label for="message">Bericht</label>';
    echo '<textarea id="message" name="message" maxlength="' . BERICHT_MAX . '" required>'
       . e(post('message')) . '</textarea>';

    echo '<span></span><button type="submit">Versturen</button>';
    echo '</div></form>';

    echo '<p class="uitleg">Opmaak: <code>[b]vet[/b]</code>, <code>[i]schuin[/i]</code>, '
       . '<code>[u]onderstreept[/u]</code>, <code>[url]adres[/url]</code>, '
       . '<code>[img]adres[/img]</code>, <code>[quote]citaat[/quote]</code>.</p>';

    panel_close();
}
