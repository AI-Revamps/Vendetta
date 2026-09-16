<?php
/**
 * Eigen instellingen: profieltekst, wachtwoord, testament, backfire, vrienden.
 *
 * Wat hier gerepareerd is:
 *
 *  - **Het wachtwoord werd als kale MD5 opgeslagen** (`SET pass = MD5(...)`)
 *    en met `$data->pass != MD5($pass)` gecontroleerd. Dat is in 2026 in
 *    seconden te kraken en de vergelijking was bovendien niet in constante
 *    tijd. Nu password_hash()/password_verify() via inc/auth.php.
 *  - Er stond geen enkele eis aan het nieuwe wachtwoord; één teken mocht.
 *  - `$_POST['standaard'] == ander` vergelijkt met een ongedefinieerde
 *    constante: sinds PHP 8 een fatale fout, dus de backfire-instelling deed
 *    het al niet meer.
 *  - Profieltekst, plaatje, testament en vriendennaam gingen ongefilterd in
 *    de query.
 *  - Vrienden toevoegen en verwijderen liep via GET-links, zonder token.
 *  - Testament en vriendennaam werden met strtolower() opgeslagen, waardoor
 *    ze in het profiel in kleine letters verschenen terwijl de speler zelf
 *    hoofdletters gebruikt.
 *  - Bij het toevoegen van een vriend werd op de kleine letters gecontroleerd
 *    maar de originele invoer weggeschreven, zodat dezelfde persoon twee keer
 *    in de lijst kon belanden.
 *  - De referrerlink stond hardcoded op vendettagame.be.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/opmaak.php';   // veilige_url() voor het profielplaatje

const MAX_VRIENDEN  = 20;
const INFO_MAX      = 1000;
const WACHTWOORD_MIN = 8;

$user    = require_login();
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'profiel'     => profiel_opslaan($user),
            'wachtwoord'  => wachtwoord_wijzigen($user),
            'testament'   => testament_zetten($user, post('testament')),
            'backfire'    => backfire_zetten($user),
            'vriend'      => vriend_toevoegen($user, post('vriend')),
            'vriend_weg'  => vriend_verwijderen($user, post('vriend')),
            default       => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
        $user = current_user() ?? $user;
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Profiel');

if ($melding !== null) {
    notice(e($melding), $type);
}

// --- Profieltekst ----------------------------------------------------------

panel_open('Profiel');
echo '<p>Je referrerlink: <code>' . e(url('register.php?refer=' . (int) $user['id']))
   . '</code></p>';
echo '<p>E-mailadres: ' . e((string) $user['email']) . '</p>';

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="profiel">';
echo '<div class="veldenraster">';
echo '<label for="pic">Plaatje (URL)</label>';
echo '<input id="pic" name="pic" maxlength="255" value="' . e((string) $user['pic']) . '">';
echo '<label for="info">Info</label>';
echo '<textarea id="info" name="info" rows="10" maxlength="' . INFO_MAX . '">'
   . e((string) ($user['info'] ?? '')) . '</textarea>';
echo '<span></span><button type="submit">Opslaan</button>';
echo '</div></form>';
echo '<p class="uitleg">Je kunt opmaak gebruiken: [b], [i], [u], [color=rood], [url=…]…[/url] '
   . 'en de tellers [crime], [oc], [auto], [race], [route], [kill] en [bo].</p>';
panel_close();

// --- Wachtwoord ------------------------------------------------------------

panel_open('Wachtwoord');
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="wachtwoord">';
echo '<div class="veldenraster">';
echo '<label for="oud">Huidig wachtwoord</label>';
echo '<input id="oud" type="password" name="oud" autocomplete="current-password" required>';
echo '<label for="nieuw">Nieuw wachtwoord</label>';
echo '<input id="nieuw" type="password" name="nieuw" minlength="' . WACHTWOORD_MIN
   . '" autocomplete="new-password" required>';
echo '<label for="herhaal">Herhaal</label>';
echo '<input id="herhaal" type="password" name="herhaal" minlength="' . WACHTWOORD_MIN
   . '" autocomplete="new-password" required>';
echo '<span></span><button type="submit">Wijzigen</button>';
echo '</div></form>';
echo '<p class="uitleg">Minstens ' . WACHTWOORD_MIN . ' tekens.</p>';
panel_close();

// --- Testament -------------------------------------------------------------

panel_open('Testament');
echo '<p>Wie erft, krijgt de helft van je banksaldo en je wagens. Nu ingesteld: <strong>'
   . ((string) $user['testament'] === '' ? 'niemand' : e((string) $user['testament']))
   . '</strong>.</p>';
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="testament">';
echo '<div class="veldenraster">';
echo '<label for="testament">Erfgenaam</label>';
echo '<input id="testament" name="testament" maxlength="16" value="'
   . e((string) $user['testament']) . '">';
echo '<span></span><button type="submit">Opslaan</button>';
echo '</div></form>';
echo '<p class="uitleg">Laat het veld leeg om je testament in te trekken.</p>';
panel_close();

// --- Backfire --------------------------------------------------------------

panel_open('Backfire');
echo '<p>Hoeveel kogels je terugschiet als iemand op je schiet. Nu ingesteld: <strong>'
   . e(backfire_omschrijving((int) $user['bf'])) . '</strong>.</p>';
echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="backfire">';
echo '<div class="veldenraster">';
echo '<label for="keuze">Instelling</label>';
echo '<select id="keuze" name="keuze">';
foreach ([0 => 'Geen', 1 => 'De helft', 2 => 'Hetzelfde aantal', 3 => 'Het dubbele',
          'vast' => 'Een vast aantal…'] as $waarde => $label) {
    echo '<option value="' . e((string) $waarde) . '"'
       . ((string) $waarde === (string) $user['bf'] ? ' selected' : '') . '>'
       . e($label) . '</option>';
}
echo '</select>';
echo '<label for="aantal">Vast aantal</label>';
echo '<input id="aantal" name="aantal" inputmode="numeric" maxlength="7">';
echo '<span></span><button type="submit">Opslaan</button>';
echo '</div></form>';
panel_close();

// --- Vrienden --------------------------------------------------------------

$vrienden = q_all('SELECT `friend` FROM `friends` WHERE `login` = ? ORDER BY `friend`',
    [$user['login']]);

panel_open('Vrienden (' . count($vrienden) . ' van ' . MAX_VRIENDEN . ')');

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="vriend">';
echo '<div class="veldenraster">';
echo '<label for="vriend">Toevoegen</label>';
echo '<input id="vriend" name="vriend" maxlength="16" required>';
echo '<span></span><button type="submit">Toevoegen</button>';
echo '</div></form>';

if ($vrienden === []) {
    echo '<p>Je vriendenlijst is leeg.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst"><tbody>';

    foreach ($vrienden as $vriend) {
        $naam = (string) $vriend['friend'];

        echo '<tr>';
        echo '<td><a' . speler_rol_attr($naam) . ' href="' . e(url('user.php?x=' . rawurlencode($naam))) . '">'
           . e($naam) . '</a></td>';
        echo '<td><form method="post" style="margin:0">' . csrf_field()
           . '<input type="hidden" name="actie" value="vriend_weg">'
           . '<input type="hidden" name="vriend" value="' . e($naam) . '">'
           . '<button type="submit">Verwijderen</button></form></td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

panel_close();
layout_footer();

// ==========================================================================

/** @throws SpelFout */
function profiel_opslaan(array $user): string
{
    $info = mb_substr(post('info'), 0, INFO_MAX);
    $pic  = trim(post('pic'));

    if ($pic !== '' && veilige_url($pic) === null) {
        throw new SpelFout('Dat is geen geldige http- of https-adres voor een plaatje.');
    }

    q('UPDATE `users` SET `info` = ?, `pic` = ? WHERE `id` = ?',
        [$info, mb_substr($pic, 0, 255), $user['id']]);

    return 'Je profiel is opgeslagen.';
}

/** @throws SpelFout */
function wachtwoord_wijzigen(array $user): string
{
    $oud     = post('oud');
    $nieuw   = post('nieuw');
    $herhaal = post('herhaal');

    if (!password_verify($oud, (string) $user['pass'])) {
        throw new SpelFout('Je huidige wachtwoord klopt niet.');
    }
    if (mb_strlen($nieuw) < WACHTWOORD_MIN) {
        throw new SpelFout('Het nieuwe wachtwoord moet minstens ' . WACHTWOORD_MIN
            . ' tekens lang zijn.');
    }
    if ($nieuw !== $herhaal) {
        throw new SpelFout('De twee wachtwoorden zijn niet gelijk.');
    }
    if ($nieuw === $oud) {
        throw new SpelFout('Kies een ander wachtwoord dan je huidige.');
    }

    q('UPDATE `users` SET `pass` = ? WHERE `id` = ?', [auth_hash($nieuw), $user['id']]);

    // Nieuw sessie-id: wie je sessie eventueel had overgenomen, ligt eruit.
    session_regenerate_id(true);

    log_action((string) $user['login'], 'account', 'Wachtwoord gewijzigd');

    return 'Je wachtwoord is gewijzigd.';
}

/** @throws SpelFout */
function testament_zetten(array $user, string $naam): string
{
    $naam = trim($naam);

    if ($naam === '') {
        q("UPDATE `users` SET `testament` = '' WHERE `id` = ?", [$user['id']]);
        return 'Je testament is ingetrokken.';
    }
    if (strcasecmp($naam, (string) $user['login']) === 0) {
        throw new SpelFout('Je kunt jezelf niet in je testament zetten.');
    }

    // De naam zoals hij écht geschreven is, niet zoals hij is ingetypt.
    $echt = q_val('SELECT `login` FROM `users` WHERE `login` = ?', [$naam]);

    if ($echt === null) {
        throw new SpelFout('Die speler bestaat niet.');
    }

    q('UPDATE `users` SET `testament` = ? WHERE `id` = ?', [$echt, $user['id']]);

    return $echt . ' staat nu in je testament.';
}

/** @throws SpelFout */
function backfire_zetten(array $user): string
{
    $keuze = post('keuze');

    if ($keuze === 'vast') {
        $aantal = int_input('aantal', -1);

        if ($aantal < 100) {
            throw new SpelFout('Een vast aantal moet minstens 100 kogels zijn.');
        }
        if ($aantal > 1_000_000) {
            throw new SpelFout('Dat is meer kogels dan er ooit in omloop zijn.');
        }

        q('UPDATE `users` SET `bf` = ? WHERE `id` = ?', [$aantal, $user['id']]);

        return 'Je schiet voortaan ' . num($aantal) . ' kogels terug.';
    }

    if (!in_array($keuze, ['0', '1', '2', '3'], true)) {
        throw new SpelFout('Die instelling bestaat niet.');
    }

    q('UPDATE `users` SET `bf` = ? WHERE `id` = ?', [(int) $keuze, $user['id']]);

    return 'Je backfire staat op: ' . backfire_omschrijving((int) $keuze) . '.';
}

/** @throws SpelFout */
function vriend_toevoegen(array $user, string $naam): string
{
    $naam = trim($naam);

    if (strcasecmp($naam, (string) $user['login']) === 0) {
        throw new SpelFout('Jezelf toevoegen heeft weinig zin.');
    }

    $echt = q_val('SELECT `login` FROM `users` WHERE `login` = ?', [$naam]);

    if ($echt === null) {
        throw new SpelFout('Die speler bestaat niet.');
    }

    $aantal = (int) q_val('SELECT COUNT(*) FROM `friends` WHERE `login` = ?',
        [$user['login']], 0);

    if ($aantal >= MAX_VRIENDEN) {
        throw new SpelFout('Je vriendenlijst is vol; er passen er ' . MAX_VRIENDEN . ' in.');
    }

    $bestaat = (int) q_val('SELECT COUNT(*) FROM `friends` WHERE `login` = ? AND `friend` = ?',
        [$user['login'], $echt], 0);

    if ($bestaat > 0) {
        throw new SpelFout($echt . ' staat al in je vriendenlijst.');
    }

    q('INSERT INTO `friends` (`login`, `friend`) VALUES (?, ?)', [$user['login'], $echt]);

    return $echt . ' staat nu in je vriendenlijst.';
}

/** @throws SpelFout */
function vriend_verwijderen(array $user, string $naam): string
{
    if (q_count('DELETE FROM `friends` WHERE `login` = ? AND `friend` = ?',
            [$user['login'], $naam]) === 0) {
        throw new SpelFout('Die speler staat niet in je vriendenlijst.');
    }

    return $naam . ' is uit je vriendenlijst verwijderd.';
}

function backfire_omschrijving(int $bf): string
{
    return match ($bf) {
        0       => 'geen',
        1       => 'de helft',
        2       => 'hetzelfde aantal',
        3       => 'het dubbele',
        default => num($bf) . ' kogels',
    };
}
