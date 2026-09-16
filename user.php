<?php
/**
 * Profiel van een andere speler.
 *
 * Wat hier gerepareerd is:
 *
 *  - Het profielveld werd door tachtig regels `eregi_replace()` gehaald die
 *    rechtstreeks HTML terugzetten: `[color=…]` werd `<font color=…>` zonder
 *    enige controle op wat daar stond. Met `[color=x onmouseover=…]` kon je
 *    dus een attribuut openen op de pagina van iedereen die je profiel bekeek.
 *    `[img]` deed hetzelfde met een `src`. Dat gaat nu door bericht_html() in
 *    inc/opmaak.php, dat eerst alles onschadelijk maakt en daarna alleen de
 *    opmaak terugzet die het zelf kent.
 *  - `eregi_replace()` bestaat sinds PHP 7 niet meer, dus deze pagina gaf
 *    sowieso een fatale fout.
 *  - De rang kwam uit vijftien elseif-takken met variabelen uit rangen.php.
 *  - `$user->status == dood` en `== levend` vergelijken met ongedefinieerde
 *    constanten.
 *  - Voor iedereen onder niveau 255 werd een blok JavaScript meegestuurd dat
 *    rechtermuisknop, tekstselectie en het contextmenu uitzette. Dat hield
 *    niemand tegen die het echt wilde kopiëren, en maakte de pagina voor
 *    toetsenbord- en schermlezergebruikers onbruikbaar. Weg.
 *  - Het RIP-plaatje zocht het forumtopic op met een LIKE op de titel; nu
 *    gewoon een link naar de categorie.
 *  - De vriendenlijst deed een aparte query zonder de naam te filteren.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/opmaak.php';

$user = require_login();
$naam = get('x') !== '' ? get('x') : (string) $user['login'];

$doel = q_row(
    'SELECT *, UNIX_TIMESTAMP(`online`) AS `online_ts`,
            UNIX_TIMESTAMP(`pc`) AS `pc_ts`, UNIX_TIMESTAMP(`bc`) AS `bc_ts`
       FROM `users` WHERE `login` = ?',
    [$naam]
);

layout_header('Profiel');

if ($doel === null) {
    notice('Die speler bestaat niet.', 'fout');
    layout_footer();
    exit;
}

$isZelf = (int) $doel['id'] === (int) $user['id'];

// --- Kop -------------------------------------------------------------------

panel_open('Profiel van ' . $doel['login']);

if ((string) $doel['pic'] !== '') {
    $adres = veilige_url((string) $doel['pic']);

    if ($adres !== null) {
        echo '<p><img class="profielfoto" src="' . e($adres) . '" alt=""></p>';
    }
}

echo '<div class="tabelwikkel"><table class="lijst"><tbody>';

veld('Naam', speler_naam((string) $doel['login'])
    . ((int) $doel['level'] >= LEVEL_MODERATOR ? ' <span class="staf">staf</span>' : ''));
veld('Geslacht', e((string) $doel['geslacht']));
veld('Status', $doel['status'] === 'levend'
    ? '<span class="levend">levend</span>'
    : '<span class="dood">dood</span> &middot; <a href="'
      . e(url('forum.php?type=rip')) . '">in memoriam</a>');
veld('Rang', e(rank_name((int) $doel['xp'])));
veld('Eer', num((int) $doel['respect']));
veld('Vermogen', e(welstand((int) $doel['zak'])));
veld('Omgelegd', omgelegd($doel));

veld((int) $doel['famrang'] === 5 ? 'Don van' : 'Familie',
    (string) $doel['famillie'] === ''
        ? '—'
        : '<a href="' . e(url('fam.php?x=' . rawurlencode((string) $doel['famillie']))) . '">'
          . e((string) $doel['famillie']) . '</a>');

veld('Huwelijk', (string) $doel['huwelijk'] === ''
    ? '—'
    : speler_naam((string) $doel['login']) . ' &amp; ' . speler_naam((string) $doel['huwelijk']));

veld('Route 66', beschikbaar((int) $doel['pc_ts'], (int) $doel['xp'], 150));
veld('Organised Crime', beschikbaar((int) $doel['bc_ts'], (int) $doel['xp'], 500));
veld('Online', (int) $doel['online_ts'] > time() - 300 ? 'ja' : 'nee');

$bezit = q_all('SELECT `spel`, `stad` FROM `casino` WHERE `owner` = ? ORDER BY `stad`',
    [$doel['login']]);

veld('Bezit', $bezit === [] ? '—' : implode('<br>', array_map(
    static fn (array $p): string => e($p['spel'] . ' in ' . $p['stad']),
    $bezit
)));

echo '</tbody></table></div>';
panel_close();

// --- Vrije tekst -----------------------------------------------------------

panel_open('Info');

$info = (string) ($doel['info'] ?? '');

if (trim($info) === '') {
    echo '<p>Deze speler heeft niets over zichzelf geschreven.</p>';
} else {
    echo '<div class="profieltekst">' . bericht_html(tellers($info, $doel)) . '</div>';
}

panel_close();

// --- Vrienden --------------------------------------------------------------

$vrienden = q_all('SELECT `friend` FROM `friends` WHERE `login` = ? ORDER BY `friend`',
    [$doel['login']]);

panel_open('Vrienden (' . count($vrienden) . ')');

if ($vrienden === []) {
    echo '<p>Geen.</p>';
} else {
    echo '<p>';
    foreach ($vrienden as $i => $vriend) {
        echo ($i > 0 ? ' &middot; ' : '')
           . '<a' . speler_rol_attr((string) $vriend['friend']) . ' href="'
           . e(url('user.php?x=' . rawurlencode((string) $vriend['friend']))) . '">'
           . e((string) $vriend['friend']) . '</a>';
    }
    echo '</p>';
}

panel_close();

// --- Acties ----------------------------------------------------------------

if (!$isZelf) {
    panel_open('Acties');
    echo '<p>';
    echo '<a class="knop" href="'
       . e(url('message.php?p=new&to=' . rawurlencode((string) $doel['login'])))
       . '">Bericht sturen</a> ';
    echo '</p>';

    // Toevoegen gaat via POST naar profile.php; in de oude versie was dit een
    // gewone link, waarmee een geprepareerde URL iemands lijst kon vullen.
    echo '<form method="post" action="' . e(url('profile.php')) . '">' . csrf_field()
       . '<input type="hidden" name="actie" value="vriend">'
       . '<input type="hidden" name="vriend" value="' . e((string) $doel['login']) . '">'
       . '<button type="submit">Aan vriendenlijst toevoegen</button></form>';
    panel_close();
}

layout_footer();

// ==========================================================================

function veld(string $label, string $waarde): void
{
    echo '<tr><th>' . e($label) . '</th><td>' . $waarde . '</td></tr>';
}

/**
 * Hoe vaak deze speler is omgelegd, en wanneer hij voor het laatst opnieuw
 * begon. Wie nog nooit dood is geweest, krijgt dat ook te lezen — dat is
 * immers iets om trots op te zijn.
 */
function omgelegd(array $doel): string
{
    $aantal = (int) $doel['gestorven'];

    if ($aantal === 0) {
        return 'nog nooit';
    }

    $tekst = num($aantal) . ($aantal === 1 ? ' keer' : ' keer');

    if (($doel['herstart'] ?? null) !== null) {
        $tekst .= ' <small>(laatste doorstart ' . e(datetime_nl($doel['herstart'])) . ')</small>';
    }

    return $tekst;
}

/** Grofmazige aanduiding van het vermogen; het exacte bedrag blijft geheim. */
function welstand(int $zak): string
{
    return match (true) {
        $zak < 1          => 'blut',
        $zak < 10_000     => 'arm',
        $zak < 100_000    => 'modaal',
        $zak < 1_000_000  => 'rijk',
        $zak < 10_000_000 => 'zeer rijk',
        default           => 'rijker dan god',
    };
}

/** Kan deze speler nu aan Route 66 of een OC meedoen? */
function beschikbaar(int $klaarOp, int $xp, int $nodig): string
{
    if ($xp < $nodig) {
        return 'nee (vanaf ' . num($nodig) . ' ervaring)';
    }

    return $klaarOp > time() ? 'nee (afkoeltijd loopt)' : 'ja';
}

/**
 * Vervang de tellers in de profieltekst.
 *
 * Dit gebeurt vóór bericht_html(): het zijn getallen uit de database, geen
 * invoer van de speler, en na het escapen zou de haakjesnotatie niet meer
 * herkend worden.
 */
function tellers(string $tekst, array $doel): string
{
    $vervang = [
        '[bo]'    => (int) $doel['bo'],
        '[crime]' => (int) $doel['nrofcrime'],
        '[oc]'    => (int) $doel['nrofoc'],
        '[auto]'  => (int) $doel['nrofcar'],
        '[race]'  => (int) $doel['nrofrace'],
        '[route]' => (int) $doel['nrofroute'],
        '[kill]'  => (int) $doel['nrofkill'],
    ];

    return str_replace(array_keys($vervang), array_map('strval', $vervang), $tekst);
}
