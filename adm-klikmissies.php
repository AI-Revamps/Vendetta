<?php
/**
 * Klikmissies beheren: stemlinks naar externe toplijsten, met een optionele
 * callback, een afkoeltijd en een beloning in zak, bank en/of diamanten.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/beheer.php';
require BV_INC . '/klikmissies.php';

$user    = require_level(beheerpaginas()['adm-klikmissies.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'toevoegen'    => toevoegen($user),
            'bewerken'     => bewerken($user, int_input('id')),
            'nieuw_geheim' => nieuw_geheim($user, int_input('id')),
            'verwijderen'  => verwijderen($user, int_input('id')),
            default        => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Beheer');
beheer_menu($user, 'adm-klikmissies.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

$bewerkId = int_input('bewerk');
toon_form($bewerkId > 0 ? q_row('SELECT * FROM `klikmissies` WHERE `id` = ?', [$bewerkId]) : null);
toon_lijst();

beheer_logregels('klikmissies');

layout_footer();

// ==========================================================================

/** @throws SpelFout */
function toevoegen(array $user): string
{
    $naam = trim(post('naam'));

    if ($naam === '') {
        throw new SpelFout('Vul een naam in.');
    }
    if (mb_strlen($naam) > 100) {
        throw new SpelFout('De naam mag hoogstens 100 tekens lang zijn.');
    }

    $url = trim(post('url'));

    if ($url === '') {
        throw new SpelFout('Vul een url in.');
    }

    q(
        'INSERT INTO `klikmissies`
                (`naam`, `omschrijving`, `url`, `heeft_callback`, `callback_geheim`,
                 `wachttijd_klik`, `cooldown_seconden`,
                 `beloning_zak`, `beloning_bank`, `beloning_diamanten`, `actief`, `volgorde`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $naam,
            mb_substr(trim(post('omschrijving')), 0, 255),
            mb_substr($url, 0, 500),
            post('heeft_callback') === '1' ? 1 : 0,
            bin2hex(random_bytes(32)),
            int_input('wachttijd_klik', 20, 0, 3600),
            int_input('cooldown_seconden', 86400, 0, 31_536_000),
            int_input('beloning_zak', 0, 0),
            int_input('beloning_bank', 0, 0),
            int_input('beloning_diamanten', 0, 0),
            post('actief') === '1' ? 1 : 0,
            int_input('volgorde', 0),
        ]
    );

    log_action((string) $user['login'], 'klikmissies', 'Aangemaakt: ' . $naam, 0, '');

    return 'De klikmissie is aangemaakt.';
}

/** @throws SpelFout */
function bewerken(array $user, int $id): string
{
    $missie = q_row('SELECT `naam` FROM `klikmissies` WHERE `id` = ?', [$id]);

    if ($missie === null) {
        throw new SpelFout('Die klikmissie bestaat niet.');
    }

    $naam = trim(post('naam'));

    if ($naam === '') {
        throw new SpelFout('Vul een naam in.');
    }

    $url = trim(post('url'));

    if ($url === '') {
        throw new SpelFout('Vul een url in.');
    }

    q(
        'UPDATE `klikmissies`
            SET `naam` = ?, `omschrijving` = ?, `url` = ?, `heeft_callback` = ?,
                `wachttijd_klik` = ?, `cooldown_seconden` = ?,
                `beloning_zak` = ?, `beloning_bank` = ?, `beloning_diamanten` = ?,
                `actief` = ?, `volgorde` = ?
          WHERE `id` = ?',
        [
            $naam,
            mb_substr(trim(post('omschrijving')), 0, 255),
            mb_substr($url, 0, 500),
            post('heeft_callback') === '1' ? 1 : 0,
            int_input('wachttijd_klik', 20, 0, 3600),
            int_input('cooldown_seconden', 86400, 0, 31_536_000),
            int_input('beloning_zak', 0, 0),
            int_input('beloning_bank', 0, 0),
            int_input('beloning_diamanten', 0, 0),
            post('actief') === '1' ? 1 : 0,
            int_input('volgorde', 0),
            $id,
        ]
    );

    log_action((string) $user['login'], 'klikmissies', 'Bewerkt: ' . $naam, 0, '');

    return 'De klikmissie is bijgewerkt.';
}

/** @throws SpelFout */
function nieuw_geheim(array $user, int $id): string
{
    $missie = q_row('SELECT `naam` FROM `klikmissies` WHERE `id` = ?', [$id]);

    if ($missie === null) {
        throw new SpelFout('Die klikmissie bestaat niet.');
    }

    q('UPDATE `klikmissies` SET `callback_geheim` = ? WHERE `id` = ?',
        [bin2hex(random_bytes(32)), $id]);

    log_action((string) $user['login'], 'klikmissies', 'Nieuw geheim: ' . $missie['naam'], 0, '');

    return 'Er is een nieuw geheim token gegenereerd. Werk de callback-url bij op de stemsite.';
}

/** @throws SpelFout */
function verwijderen(array $user, int $id): string
{
    $missie = q_row('SELECT `naam` FROM `klikmissies` WHERE `id` = ?', [$id]);

    if ($missie === null) {
        throw new SpelFout('Die klikmissie bestaat niet.');
    }

    db_transaction(static function () use ($id): void {
        q('DELETE FROM `klikmissies_log` WHERE `klikmissie_id` = ?', [$id]);
        q('DELETE FROM `klikmissies` WHERE `id` = ?', [$id]);
    });

    log_action((string) $user['login'], 'klikmissies', 'Verwijderd: ' . $missie['naam'], 0, '');

    return 'De klikmissie en het bijbehorende logboek zijn verwijderd.';
}

// ==========================================================================

function toon_form(?array $missie): void
{
    $bewerken = $missie !== null;
    $titel    = $bewerken ? 'Bewerken: ' . (string) $missie['naam'] : 'Nieuwe klikmissie';

    panel_open($titel);

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="' . ($bewerken ? 'bewerken' : 'toevoegen') . '">';
    if ($bewerken) {
        echo '<input type="hidden" name="id" value="' . (int) $missie['id'] . '">';
    }
    echo '<div class="veldenraster">';

    echo '<label for="naam">Naam</label>';
    echo '<input id="naam" name="naam" maxlength="100" required value="'
       . e((string) ($missie['naam'] ?? '')) . '">';

    echo '<label for="omschrijving">Omschrijving</label>';
    echo '<input id="omschrijving" name="omschrijving" maxlength="255" value="'
       . e((string) ($missie['omschrijving'] ?? '')) . '">';

    echo '<label for="url">Url</label>';
    echo '<input id="url" name="url" maxlength="500" required value="'
       . e((string) ($missie['url'] ?? '')) . '">';
    echo '<span></span><p class="uitleg">Gebruik <code>{login}</code> op de plek waar de '
       . 'gebruikersnaam van de speler moet komen.</p>';

    echo '<label for="heeft_callback">Heeft callback</label>';
    echo '<span><label><input type="checkbox" id="heeft_callback" name="heeft_callback" value="1"'
       . ((int) ($missie['heeft_callback'] ?? 0) === 1 ? ' checked' : '') . '> De stemsite roept '
       . 'zelf de callback-url aan om een stem te bevestigen</label></span>';

    echo '<label for="wachttijd_klik">Wachttijd na klikken (seconden)</label>';
    echo '<input id="wachttijd_klik" name="wachttijd_klik" type="number" min="0" max="3600" value="'
       . (int) ($missie['wachttijd_klik'] ?? 20) . '">';
    echo '<span></span><p class="uitleg">Alleen relevant zonder callback.</p>';

    echo '<label for="cooldown_seconden">Cooldown (seconden)</label>';
    echo '<input id="cooldown_seconden" name="cooldown_seconden" type="number" min="0" '
       . 'max="31536000" value="' . (int) ($missie['cooldown_seconden'] ?? 86400) . '">';

    echo '<label for="beloning_zak">Beloning: op zak</label>';
    echo '<input id="beloning_zak" name="beloning_zak" type="number" min="0" value="'
       . (int) ($missie['beloning_zak'] ?? 0) . '">';

    echo '<label for="beloning_bank">Beloning: op de bank</label>';
    echo '<input id="beloning_bank" name="beloning_bank" type="number" min="0" value="'
       . (int) ($missie['beloning_bank'] ?? 0) . '">';

    echo '<label for="beloning_diamanten">Beloning: diamanten</label>';
    echo '<input id="beloning_diamanten" name="beloning_diamanten" type="number" min="0" value="'
       . (int) ($missie['beloning_diamanten'] ?? 0) . '">';

    echo '<label for="volgorde">Volgorde</label>';
    echo '<input id="volgorde" name="volgorde" type="number" value="'
       . (int) ($missie['volgorde'] ?? 0) . '">';

    echo '<label for="actief">Actief</label>';
    echo '<span><label><input type="checkbox" id="actief" name="actief" value="1"'
       . ((int) ($missie['actief'] ?? 1) === 1 ? ' checked' : '') . '> Zichtbaar voor spelers</label></span>';

    echo '<span></span><button type="submit">' . ($bewerken ? 'Opslaan' : 'Aanmaken') . '</button>';
    echo '</div></form>';

    if ($bewerken && (int) $missie['heeft_callback'] === 1) {
        echo '<p><strong>Callback-url:</strong><br><code>'
           . e(url('klikmissies-callback.php') . '?id=' . (int) $missie['id']
                . '&geheim=' . (string) $missie['callback_geheim'] . '&login=')
           . '</code> <span class="uitleg">Plak dit in het paneel van de stemsite; laat de '
           . 'gebruikersnaam-plaatshouder van de stemsite achter "login=" staan.</span></p>';

        echo '<form method="post" style="display:inline;margin:0">' . csrf_field();
        echo '<input type="hidden" name="actie" value="nieuw_geheim">';
        echo '<input type="hidden" name="id" value="' . (int) $missie['id'] . '">';
        echo '<button type="submit">Nieuw geheim genereren</button></form>';
    }

    panel_close();
}

function toon_lijst(): void
{
    $missies = q_all('SELECT * FROM `klikmissies` ORDER BY `volgorde`, `id`');

    panel_open('Klikmissies (' . count($missies) . ')');

    if ($missies === []) {
        echo '<p>Er zijn nog geen klikmissies.</p>';
        panel_close();
        return;
    }

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Naam</th><th>Callback</th><th class="getal">Cooldown</th>'
       . '<th>Beloning</th><th>Status</th><th></th></tr></thead><tbody>';

    foreach ($missies as $missie) {
        $id         = (int) $missie['id'];
        $beloningen = [];

        if ((int) $missie['beloning_zak'] > 0)       { $beloningen[] = money((int) $missie['beloning_zak']) . ' op zak'; }
        if ((int) $missie['beloning_bank'] > 0)      { $beloningen[] = money((int) $missie['beloning_bank']) . ' op de bank'; }
        if ((int) $missie['beloning_diamanten'] > 0) { $beloningen[] = num((int) $missie['beloning_diamanten']) . ' diamanten'; }

        echo '<tr>';
        echo '<td><a href="' . e(url('adm-klikmissies.php?bewerk=' . $id)) . '">'
           . e((string) $missie['naam']) . '</a></td>';
        echo '<td>' . ((int) $missie['heeft_callback'] === 1 ? 'ja' : 'nee') . '</td>';
        echo '<td class="getal">' . duration((int) $missie['cooldown_seconden']) . '</td>';
        echo '<td>' . ($beloningen === [] ? '-' : e(implode(', ', $beloningen))) . '</td>';
        echo '<td>' . ((int) $missie['actief'] === 1 ? 'actief' : 'uit') . '</td>';
        echo '<td>' . knop('verwijderen', $id, 'Verwijderen') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    panel_close();
}

function knop(string $actie, int $id, string $label): string
{
    return '<form method="post" style="display:inline;margin:0">' . csrf_field()
         . '<input type="hidden" name="actie" value="' . e($actie) . '">'
         . '<input type="hidden" name="id" value="' . $id . '">'
         . '<button type="submit">' . e($label) . '</button></form>';
}
