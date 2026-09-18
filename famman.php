<?php
/**
 * Familiebeheer: leden, uitnodigen, info, familiebericht, kas, logboek,
 * grondgebied en de crusher.
 *
 * LET OP - de familiekas kon nooit gevuld worden.
 *
 * In de oude versie was de enige plek waar `famillie`.`bank` omhoog ging een
 * overboeking vanaf een ándere familie. Er was geen enkele manier om er zelf
 * geld in te storten. Het saldo stond dus permanent op nul en alles wat van de
 * kas afhing werkte niet. Er is nu een stortmogelijkheid voor elk lid.
 *
 * Verder gerepareerd:
 *
 *  - Overboeken naar een andere familie had géén rangcontrole, terwijl
 *    overboeken naar een persoon dat wel had. Elk willekeurig lid kon dus de
 *    hele kas naar een andere familie sturen.
 *  - Geen transactie om de overboekingen heen: twee gelijktijdige opdrachten
 *    zagen allebei genoeg saldo en boekten allebei af.
 *  - De omschrijving ging ongefilterd in de logquery.
 *  - Familie-info werd gefilterd met preg_replace('/</','&#60;'), wat alleen
 *    het kleiner-dan-teken verving; aanhalingstekens bleven staan.
 *  - Alle routes liepen op kale woorden zoals `$_GET['p'] == bank`, wat in
 *    PHP 8 een fatale fout geeft.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require_once BV_INC . '/familie.php';
require BV_INC . '/opmaak.php';

const FAM_INFO_MAX    = 2000;
const FAM_BERICHT_MAX = 1000;
const CRUSHER_PRIJS   = 500_000;
const CRUSHER_AANTAL  = 25;

$user = require_login();

if (($user['famillie'] ?? '') === '') {
    layout_header('Familie');
    panel_open('Familiebeheer');
    echo '<p>Je zit niet in een familie. <a href="' . e(url('fam.php')) . '">Bekijk de families</a>.</p>';
    panel_close();
    layout_footer();
    exit;
}

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

$familie = fam_van($user);

if ($familie === null) {
    redirect('fam.php');
}

layout_header('Familiebeheer');

if ($melding !== null) {
    notice(e($melding), $type);
}

toon_menu($user, get('p'));

match (get('p')) {
    'members' => toon_leden($user, $familie),
    'invite'  => toon_uitnodigen($user, $familie),
    'info'    => toon_info($user, $familie),
    'message' => toon_bericht($user, $familie),
    'log'     => toon_logboek($user, $familie),
    'land'    => toon_grond($user, $familie),
    'bulfac'  => toon_crusher($user, $familie),
    default   => toon_kas($user, $familie),
};

layout_footer();

// ==========================================================================
// Verwerking
// ==========================================================================

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'storten'    => storten($user, int_input('bedrag', 0)),
        'naar_lid'   => overmaken_lid($user, post('naar'), int_input('bedrag', 0), post('com')),
        'naar_fam'   => overmaken_familie($user, post('naar'), int_input('bedrag', 0), post('com')),
        'rang'       => rang_wijzigen($user, post('lid'), int_input('rang')),
        'verwijder'  => lid_verwijderen($user, post('lid')),
        'uitnodigen' => uitnodigen($user, post('lid')),
        'info'       => info_opslaan($user, post('info'), post('pic')),
        'bericht'    => bericht_sturen($user, post('subject'), post('message')),
        'grond'      => grond_kopen($user, int_input('grond', 0)),
        'crusher'    => crusher_huren($user),
        default      => throw new SpelFout('Onbekende handeling.'),
    };
}

/**
 * Geld in de familiekas storten.
 *
 * Deze mogelijkheid ontbrak volledig in de oude versie, waardoor de kas
 * permanent leeg bleef.
 *
 * @throws SpelFout
 */
function storten(array $user, int $bedrag): string
{
    if ($bedrag < 1) {
        throw new SpelFout('Vul een bedrag van minstens 1 in.');
    }

    return db_transaction(static function () use ($user, $bedrag): string {
        $familie = fam_van($user, true);

        if ($familie === null) {
            throw new SpelFout('Je zit niet in een familie.');
        }

        lock_user((int) $user['id']);

        if (!afboeken((int) $user['id'], $bedrag, 'zak')) {
            throw new SpelFout('Zoveel geld heb je niet op zak.');
        }

        fam_bijschrijven((string) $familie['name'], $bedrag);
        fam_log((string) $familie['name'], (string) $user['login'], $bedrag, 'Storting');

        return 'Je hebt ' . money($bedrag) . ' in de kas gestort.';
    });
}

/** @throws SpelFout */
function overmaken_lid(array $user, string $naar, int $bedrag, string $omschrijving): string
{
    if ($bedrag < 1) {
        throw new SpelFout('Vul een bedrag van minstens 1 in.');
    }

    return db_transaction(static function () use ($user, $naar, $bedrag, $omschrijving): string {
        fam_eis_rang($user, FAM_CONSIGLIERI);
        $familie = fam_van($user, true);

        if ($familie === null) {
            throw new SpelFout('Je zit niet in een familie.');
        }

        $ontvanger = lock_user_by_login($naar);

        if ($ontvanger === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }
        if ($ontvanger['status'] !== 'levend') {
            throw new SpelFout('Die speler is dood.');
        }
        if (!fam_afboeken((string) $familie['name'], $bedrag)) {
            throw new SpelFout('Zoveel staat er niet in de kas.');
        }

        bijschrijven((int) $ontvanger['id'], $bedrag, 'zak');

        fam_log((string) $familie['name'], (string) $ontvanger['login'], -$bedrag,
            'Uitbetaling door ' . $user['login'] . ': ' . $omschrijving);

        notify((string) $ontvanger['login'], 'Familiekas',
            'Je hebt ' . money($bedrag) . ' uit de kas van ' . $familie['name'] . ' ontvangen.'
            . ($omschrijving !== '' ? ' Omschrijving: ' . $omschrijving : ''));

        return 'Je hebt ' . money($bedrag) . ' overgemaakt aan ' . $ontvanger['login'] . '.';
    });
}

/** @throws SpelFout */
function overmaken_familie(array $user, string $naar, int $bedrag, string $omschrijving): string
{
    if ($bedrag < 1) {
        throw new SpelFout('Vul een bedrag van minstens 1 in.');
    }

    return db_transaction(static function () use ($user, $naar, $bedrag, $omschrijving): string {
        // Deze rangcontrole ontbrak in de oude versie, waardoor elk lid de
        // hele kas naar een andere familie kon overmaken.
        fam_eis_rang($user, FAM_CONSIGLIERI);
        $familie = fam_van($user, true);

        if ($familie === null) {
            throw new SpelFout('Je zit niet in een familie.');
        }
        if (strcasecmp($naar, (string) $familie['name']) === 0) {
            throw new SpelFout('Je kunt niet aan je eigen familie overmaken.');
        }

        $doel = q_row('SELECT * FROM `famillie` WHERE `name` = ? FOR UPDATE', [$naar]);

        if ($doel === null) {
            throw new SpelFout('Die familie bestaat niet.');
        }
        if (!fam_afboeken((string) $familie['name'], $bedrag)) {
            throw new SpelFout('Zoveel staat er niet in de kas.');
        }

        fam_bijschrijven((string) $doel['name'], $bedrag);

        fam_log((string) $familie['name'], (string) $doel['name'], -$bedrag,
            'Overboeking door ' . $user['login'] . ': ' . $omschrijving);
        fam_log((string) $doel['name'], (string) $familie['name'], $bedrag,
            'Ontvangen van ' . $familie['name'] . ': ' . $omschrijving);

        return 'Je hebt ' . money($bedrag) . ' overgemaakt aan ' . $doel['name'] . '.';
    });
}

/** @throws SpelFout */
function rang_wijzigen(array $user, string $lid, int $rang): string
{
    $familie = fam_eis_rang($user, FAM_HALFDON);

    if (!isset(fam_rangen()[$rang])) {
        throw new SpelFout('Die rang bestaat niet.');
    }
    if ($rang >= (int) $user['famrang']) {
        throw new SpelFout('Je kunt niemand een rang geven die gelijk is aan of hoger dan die van jezelf.');
    }

    return db_transaction(static function () use ($user, $familie, $lid, $rang): string {
        $speler = lock_user_by_login($lid);

        if ($speler === null || $speler['famillie'] !== $familie['name']) {
            throw new SpelFout('Die speler zit niet in jouw familie.');
        }
        if ($speler['login'] === $user['login']) {
            throw new SpelFout('Je kunt je eigen rang niet wijzigen.');
        }
        if ((int) $speler['famrang'] >= (int) $user['famrang']) {
            throw new SpelFout('Je kunt de rang van een gelijke of hogere niet wijzigen.');
        }

        q('UPDATE `users` SET `famrang` = ? WHERE `id` = ?', [$rang, $speler['id']]);

        notify((string) $speler['login'], 'Familie',
            'Je rang in ' . $familie['name'] . ' is gewijzigd naar ' . fam_rangnaam($rang) . '.');

        return $speler['login'] . ' heeft nu de rang ' . fam_rangnaam($rang) . '.';
    });
}

/** @throws SpelFout */
function lid_verwijderen(array $user, string $lid): string
{
    $familie = fam_eis_rang($user, FAM_HALFDON);

    return db_transaction(static function () use ($user, $familie, $lid): string {
        $speler = lock_user_by_login($lid);

        if ($speler === null || $speler['famillie'] !== $familie['name']) {
            throw new SpelFout('Die speler zit niet in jouw familie.');
        }
        if ((int) $speler['famrang'] >= (int) $user['famrang']) {
            throw new SpelFout('Je kunt een gelijke of hogere niet verwijderen.');
        }

        q("UPDATE `users` SET `famillie` = '', `famrang` = 0, `famcapo` = '' WHERE `id` = ?",
            [$speler['id']]);

        notify((string) $speler['login'], 'Familie',
            'Je bent uit de familie ' . $familie['name'] . ' gezet.');

        return $speler['login'] . ' is uit de familie gezet.';
    });
}

/** @throws SpelFout */
function uitnodigen(array $user, string $lid): string
{
    $familie = fam_eis_rang($user, FAM_CAPO);

    $speler = q_row('SELECT * FROM `users` WHERE `login` = ?', [$lid]);

    if ($speler === null) {
        throw new SpelFout('Die speler bestaat niet.');
    }
    if ($speler['status'] !== 'levend') {
        throw new SpelFout('Die speler is dood.');
    }
    if ($speler['famillie'] !== '') {
        throw new SpelFout($speler['login'] . ' zit al in een familie.');
    }

    $bestaat = (int) q_val('SELECT COUNT(*) FROM `invite` WHERE `login` = ? AND `famillie` = ?',
        [$speler['login'], $familie['name']], 0);

    if ($bestaat > 0) {
        throw new SpelFout($speler['login'] . ' is al uitgenodigd.');
    }

    q('INSERT INTO `invite` (`login`, `famillie`) VALUES (?, ?)',
        [$speler['login'], $familie['name']]);

    notify((string) $speler['login'], 'Uitnodiging',
        $user['login'] . ' nodigt je uit voor de familie ' . $familie['name']
        . '. [invite]' . $familie['name'] . '[/invite]');

    return $speler['login'] . ' is uitgenodigd.';
}

/** @throws SpelFout */
function info_opslaan(array $user, string $info, string $plaatje): string
{
    $familie = fam_eis_rang($user, FAM_HALFDON);

    $info    = mb_substr(trim($info), 0, FAM_INFO_MAX);
    $plaatje = trim($plaatje);

    if ($plaatje !== '' && veilige_url($plaatje) === null) {
        throw new SpelFout('Het opgegeven adres van de afbeelding is ongeldig. '
            . 'Gebruik een volledig http- of https-adres.');
    }

    q('UPDATE `famillie` SET `info` = ?, `pic` = ? WHERE `name` = ?',
        [$info, $plaatje, $familie['name']]);

    return 'De familie-informatie is bijgewerkt.';
}

/** @throws SpelFout */
function bericht_sturen(array $user, string $onderwerp, string $tekst): string
{
    $familie = fam_eis_rang($user, FAM_HALFDON);

    $onderwerp = trim($onderwerp) === '' ? 'Familiebericht' : mb_substr(trim($onderwerp), 0, 80);
    $tekst     = mb_substr(trim($tekst), 0, FAM_BERICHT_MAX);

    if ($tekst === '') {
        throw new SpelFout('Je bericht is leeg.');
    }

    $leden  = fam_leden((string) $familie['name']);
    $aantal = 0;

    foreach ($leden as $lid) {
        if ($lid['login'] !== $user['login']) {
            q('INSERT INTO `messages` (`time`, `from`, `to`, `subject`, `message`) VALUES (NOW(), ?, ?, ?, ?)',
                [$user['login'], $lid['login'], $onderwerp, $tekst]);
            $aantal++;
        }
    }

    return 'Het bericht is naar ' . $aantal . ' ' . ($aantal === 1 ? 'lid' : 'leden') . ' gestuurd.';
}

/** @throws SpelFout */
function grond_kopen(array $user, int $aantal): string
{
    if ($aantal < 1) {
        throw new SpelFout('Vul een aantal van minstens 1 in.');
    }

    return db_transaction(static function () use ($user, $aantal): string {
        fam_eis_rang($user, FAM_CONSIGLIERI);
        $familie = fam_van($user, true);

        if ($familie === null) {
            throw new SpelFout('Je zit niet in een familie.');
        }

        $kosten = $aantal * FAM_GROND_PRIJS;

        if (!fam_afboeken((string) $familie['name'], $kosten)) {
            throw new SpelFout('Dit kost ' . money($kosten) . ' en zoveel staat er niet in de kas.');
        }

        q('UPDATE `famillie` SET `grond` = `grond` + ? WHERE `name` = ?', [$aantal, $familie['name']]);
        fam_log((string) $familie['name'], (string) $user['login'], -$kosten,
            $aantal . ' stukken grond gekocht');

        return 'Je hebt ' . num($aantal) . ' stukken grond gekocht voor ' . money($kosten) . '.';
    });
}

/** @throws SpelFout */
function crusher_huren(array $user): string
{
    return db_transaction(static function () use ($user): string {
        fam_eis_rang($user, FAM_CONSIGLIERI);
        $familie = fam_van($user, true);

        if ($familie === null) {
            throw new SpelFout('Je zit niet in een familie.');
        }
        if ((int) $familie['crusher'] > 0) {
            throw new SpelFout('Je familie heeft vandaag al een crusher ingehuurd.');
        }
        if (!fam_afboeken((string) $familie['name'], CRUSHER_PRIJS)) {
            throw new SpelFout('Een crusher kost ' . money(CRUSHER_PRIJS)
                . ' en zoveel staat er niet in de kas.');
        }

        q('UPDATE `famillie` SET `crusher` = 1, `aantal` = ? WHERE `name` = ?',
            [CRUSHER_AANTAL, $familie['name']]);
        fam_log((string) $familie['name'], (string) $user['login'], -CRUSHER_PRIJS, 'Crusher gehuurd');

        return 'De crusher is ingehuurd. Je familie kan vandaag ' . CRUSHER_AANTAL . ' wagens crushen.';
    });
}

// ==========================================================================
// Weergave
// ==========================================================================

function toon_menu(array $user, string $huidig): void
{
    $rang  = (int) $user['famrang'];
    $items = ['bank' => 'Kas'];

    if ($rang >= FAM_CAPO) {
        $items['invite'] = 'Uitnodigen';
    }
    if ($rang >= FAM_CONSIGLIERI) {
        $items['log']    = 'Logboek';
        $items['land']   = 'Grondgebied';
        $items['bulfac'] = 'Crusher';
    }
    if ($rang >= FAM_HALFDON) {
        $items['members'] = 'Leden';
        $items['info']    = 'Info';
        $items['message'] = 'Familiebericht';
    }

    echo '<p>';
    foreach ($items as $sleutel => $label) {
        $actief = ($huidig === $sleutel || ($huidig === '' && $sleutel === 'bank')) ? ' knop-nadruk' : '';
        echo '<a class="knop' . $actief . '" style="display:inline-block;margin:0 .3rem .3rem 0" href="'
           . e(url('famman.php?p=' . $sleutel)) . '">' . e($label) . '</a>';
    }
    echo '</p>';
}

function toon_kas(array $user, array $familie): void
{
    panel_open('Familiekas');
    echo '<p>In kas: <strong>' . money((int) $familie['bank']) . '</strong></p>';

    echo '<h3>Storten</h3>';
    echo '<p>Je hebt ' . money((int) $user['zak']) . ' op zak.</p>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="storten">';
    echo '<div class="veldenraster">';
    echo '<label for="bedrag">Bedrag</label>';
    echo '<input id="bedrag" name="bedrag" type="number" min="1" step="1" required>';
    echo '<span></span><button type="submit">Storten</button>';
    echo '</div></form>';

    if ((int) $user['famrang'] >= FAM_CONSIGLIERI) {
        echo '<h3>Uitbetalen</h3>';
        echo '<form method="post">' . csrf_field();
        echo '<div class="veldenraster">';
        echo '<label for="naar">Naar</label>';
        echo '<input id="naar" name="naar" maxlength="20" required>';
        echo '<label for="ubedrag">Bedrag</label>';
        echo '<input id="ubedrag" name="bedrag" type="number" min="1" step="1" required>';
        echo '<label for="com">Omschrijving</label>';
        echo '<input id="com" name="com" maxlength="100">';
        echo '<span></span><div>'
           . '<button type="submit" name="actie" value="naar_lid">Naar een speler</button> '
           . '<button type="submit" name="actie" value="naar_fam">Naar een familie</button></div>';
        echo '</div></form>';
    } else {
        echo '<p class="uitleg">Uitbetalen kan alleen vanaf de rang Consiglieri.</p>';
    }

    panel_close();
}

function toon_leden(array $user, array $familie): void
{
    if ((int) $user['famrang'] < FAM_HALFDON) {
        geen_rechten();
        return;
    }

    panel_open('Leden van ' . $familie['name']);
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Speler</th><th>Rang</th><th>Spelrang</th>'
       . '<th>Wijzigen</th></tr></thead><tbody>';

    foreach (fam_leden((string) $familie['name']) as $lid) {
        $eigen = $lid['login'] === $user['login'];
        $hoger = (int) $lid['famrang'] >= (int) $user['famrang'];

        echo '<tr>';
        echo '<td>' . speler_naam((string) $lid['login']) . '</td>';
        echo '<td>' . e(fam_rangnaam((int) $lid['famrang'])) . '</td>';
        echo '<td>' . e(rank_name((int) $lid['xp'], (string) $lid['geslacht'])) . '</td>';
        echo '<td>';

        if ($eigen || $hoger) {
            echo '<small>-</small>';
        } else {
            echo '<form method="post" style="display:inline">' . csrf_field()
               . '<input type="hidden" name="actie" value="rang">'
               . '<input type="hidden" name="lid" value="' . e((string) $lid['login']) . '">'
               . '<select name="rang" aria-label="Nieuwe rang">';
            foreach (fam_rangen() as $nr => $naam) {
                if ($nr < (int) $user['famrang']) {
                    echo '<option value="' . $nr . '"' . ($nr === (int) $lid['famrang'] ? ' selected' : '')
                       . '>' . e($naam) . '</option>';
                }
            }
            echo '</select><button type="submit">Zet</button></form> ';
            echo '<form method="post" style="display:inline">' . csrf_field()
               . '<input type="hidden" name="actie" value="verwijder">'
               . '<input type="hidden" name="lid" value="' . e((string) $lid['login']) . '">'
               . '<button type="submit">Verwijder</button></form>';
        }

        echo '</td></tr>';
    }

    echo '</tbody></table></div>';
    panel_close();
}

function toon_uitnodigen(array $user, array $familie): void
{
    if ((int) $user['famrang'] < FAM_CAPO) {
        geen_rechten();
        return;
    }

    panel_open('Iemand uitnodigen');
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="uitnodigen">';
    echo '<div class="veldenraster">';
    echo '<label for="lid">Gebruikersnaam</label>';
    echo '<input id="lid" name="lid" maxlength="16" required>';
    echo '<span></span><button type="submit">Uitnodigen</button>';
    echo '</div></form>';

    $open = q_all('SELECT * FROM `invite` WHERE `famillie` = ?', [$familie['name']]);

    if ($open !== []) {
        echo '<h3>Openstaande uitnodigingen</h3><ul>';
        foreach ($open as $rij) {
            echo '<li>' . speler_naam((string) $rij['login']) . '</li>';
        }
        echo '</ul>';
    }

    panel_close();
}

function toon_info(array $user, array $familie): void
{
    if ((int) $user['famrang'] < FAM_HALFDON) {
        geen_rechten();
        return;
    }

    panel_open('Familie-informatie');
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="info">';
    echo '<div class="veldenraster">';
    echo '<label for="pic">Afbeelding</label>';
    echo '<input id="pic" name="pic" maxlength="255" value="' . e((string) $familie['pic']) . '">';
    echo '<span></span><small>Volledig http- of https-adres, of laat leeg.</small>';
    echo '<label for="info">Tekst</label>';
    echo '<textarea id="info" name="info" maxlength="' . FAM_INFO_MAX . '">'
       . e((string) $familie['info']) . '</textarea>';
    echo '<span></span><button type="submit">Opslaan</button>';
    echo '</div></form>';
    panel_close();
}

function toon_bericht(array $user, array $familie): void
{
    if ((int) $user['famrang'] < FAM_HALFDON) {
        geen_rechten();
        return;
    }

    panel_open('Bericht aan alle leden');
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="bericht">';
    echo '<div class="veldenraster">';
    echo '<label for="subject">Onderwerp</label>';
    echo '<input id="subject" name="subject" maxlength="80" value="Familiebericht">';
    echo '<label for="message">Bericht</label>';
    echo '<textarea id="message" name="message" maxlength="' . FAM_BERICHT_MAX . '" required></textarea>';
    echo '<span></span><button type="submit">Versturen</button>';
    echo '</div></form>';
    panel_close();
}

function toon_logboek(array $user, array $familie): void
{
    if ((int) $user['famrang'] < FAM_CONSIGLIERI) {
        geen_rechten();
        return;
    }

    $regels = q_all(
        "SELECT * FROM `logs` WHERE `area` = 'famibank' AND (`login` = ? OR `person` = ?)
      ORDER BY `time` DESC LIMIT 50",
        [$familie['name'], $familie['name']]
    );

    panel_open('Logboek van de kas');

    if ($regels === []) {
        echo '<p>Er is nog niets vastgelegd.</p>';
    } else {
        echo '<div class="tabelwikkel"><table class="lijst">';
        echo '<thead><tr><th>Wanneer</th><th>Wie</th><th class="getal">Bedrag</th>'
           . '<th>Omschrijving</th></tr></thead><tbody>';
        foreach ($regels as $regel) {
            $bedrag = (int) $regel['code'];
            echo '<tr>';
            echo '<td>' . e(datetime_nl($regel['time'])) . '</td>';
            echo '<td>' . speler_naam((string) $regel['person']) . '</td>';
            echo '<td class="getal">' . ($bedrag < 0 ? '&minus;' : '+') . ' ' . money(abs($bedrag)) . '</td>';
            echo '<td>' . e((string) $regel['com']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    panel_close();
}

function toon_grond(array $user, array $familie): void
{
    panel_open('Grondgebied');
    echo '<p>Je familie bezit <strong>' . num((int) $familie['grond'])
       . '</strong> stukken grond. Een stuk kost ' . money(FAM_GROND_PRIJS) . '.</p>';

    if ((int) $user['famrang'] < FAM_CONSIGLIERI) {
        echo '<p class="uitleg">Grond kopen kan alleen vanaf de rang Consiglieri.</p>';
    } else {
        echo '<form method="post">' . csrf_field();
        echo '<input type="hidden" name="actie" value="grond">';
        echo '<div class="veldenraster">';
        echo '<label for="grond">Aantal stukken</label>';
        echo '<input id="grond" name="grond" type="number" min="1" step="1" required>';
        echo '<span></span><button type="submit">Kopen</button>';
        echo '</div></form>';
    }

    panel_close();
}

function toon_crusher(array $user, array $familie): void
{
    panel_open('Crusher');

    if ((int) $familie['crusher'] > 0) {
        echo '<p>Er is vandaag een crusher ingehuurd. Je familie kan nog <strong>'
           . num((int) $familie['aantal']) . '</strong> wagens crushen.</p>';
    } else {
        echo '<p>Met een crusher kunnen leden hun wagens tot kogels laten verwerken. '
           . 'Huren kost ' . money(CRUSHER_PRIJS) . ' uit de kas en geldt voor '
           . CRUSHER_AANTAL . ' wagens, tot het einde van de dag.</p>';

        if ((int) $user['famrang'] < FAM_CONSIGLIERI) {
            echo '<p class="uitleg">Een crusher huren kan alleen vanaf de rang Consiglieri.</p>';
        } else {
            echo '<form method="post">' . csrf_field()
               . '<input type="hidden" name="actie" value="crusher">'
               . '<button type="submit">Crusher huren</button></form>';
        }
    }

    panel_close();
}

function geen_rechten(): void
{
    panel_open('Geen toegang');
    echo '<p>Je hebt niet de juiste rang voor dit onderdeel.</p>';
    panel_close();
}
