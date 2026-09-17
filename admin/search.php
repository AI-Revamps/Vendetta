<?php
/**
 * Spelers opzoeken: gegevens, IP-geschiedenis en accounts op hetzelfde IP.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - Inloggen als een andere speler gebeurde met `$_SESSION['login'] =
 *    $_GET['login']`, dus via een gewone link zonder CSRF-bescherming. Een
 *    beheerder die een geprepareerde link opende, werd ongemerkt omgezet naar
 *    een ander account. Bovendien was er geen weg terug: je eigen sessie was
 *    weg. Nu POST met token, alleen voor de eigenaar, en met een knop om terug
 *    te keren naar je eigen account.
 *  - Het zoekveld werd als kolomnaam gebruikt: `WHERE $need = $gegevens`, met
 *    beide waarden rechtstreeks uit het formulier.
 *  - Verwijderen en bannen liepen via GET-links.
 *  - Er werd geschreven naar een tabel `log` die niet in het schema staat, en
 *    gekopieerd naar `backup-users`, die er evenmin is.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$melding = null;
$type    = 'info';

// De rechtencontrole gebeurt in beheer_start(), maar POST-acties worden
// verwerkt voordat er iets getoond wordt.
$nodig = beheerpaginas()['search.php'][1];
$user  = require_level($nodig);

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

beheer_header($user, 'search.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

// Zit deze beheerder in een ander account?
if (isset($_SESSION['beheer_terug'])) {
    panel_open('Je kijkt mee als een andere speler');
    echo '<p>Je bent ingelogd als <strong>' . speler_naam((string) $user['login'])
       . '</strong>. Alles wat je doet gebeurt op dat account.</p>';
    echo '<form method="post">' . csrf_field()
       . '<input type="hidden" name="actie" value="terug">'
       . '<button type="submit">Terug naar je eigen account</button></form>';
    panel_close();
}

$gezocht = get('login') !== '' ? get('login') : post('naam');

if ($gezocht !== '') {
    toon_speler($user, $gezocht);
}

toon_zoekformulier();

beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'inloggen'  => inloggen_als($user, post('naam')),
        'terug'     => terug_naar_eigen(),
        'verwijder' => verwijderen($user, post('naam')),
        'ban_ip'    => ip_bannen($user, post('ip')),
        default     => throw new SpelFout('Onbekende handeling.'),
    };
}

/**
 * Log in op het account van een speler, om een melding na te kunnen lopen.
 *
 * @throws SpelFout
 */
function inloggen_als(array $user, string $naam): string
{
    if ((int) $user['level'] < LEVEL_OWNER) {
        throw new SpelFout('Alleen de eigenaar kan op een account inloggen.');
    }
    if (isset($_SESSION['beheer_terug'])) {
        throw new SpelFout('Ga eerst terug naar je eigen account.');
    }

    $doel = beheer_speler($naam);

    if ((int) $doel['level'] >= (int) $user['level']) {
        throw new SpelFout('Je kunt niet inloggen op een account met gelijke of hogere rechten.');
    }

    log_action((string) $user['login'], 'beheer',
        'Ingelogd op account ' . $doel['login'], 0, (string) $doel['login']);

    // Het eigen account onthouden, zodat er een weg terug is. In de oude
    // versie was je eigen sessie gewoon weg.
    $eigen = (int) $user['id'];
    auth_login_as((int) $doel['id']);
    $_SESSION['beheer_terug'] = $eigen;

    return 'Je bent nu ingelogd als ' . $doel['login'] . '.';
}

/** @throws SpelFout */
function terug_naar_eigen(): string
{
    $eigen = $_SESSION['beheer_terug'] ?? null;

    if (!is_int($eigen)) {
        throw new SpelFout('Je kijkt niet mee als iemand anders.');
    }

    unset($_SESSION['beheer_terug']);
    auth_login_as($eigen);

    return 'Je bent terug op je eigen account.';
}

/** @throws SpelFout */
function verwijderen(array $user, string $naam): string
{
    if ((int) $user['level'] < LEVEL_OWNER) {
        throw new SpelFout('Alleen de eigenaar kan een account verwijderen.');
    }

    $doel = beheer_speler($naam);

    if ((int) $doel['level'] >= LEVEL_MODERATOR) {
        throw new SpelFout('Stafleden kun je niet verwijderen.');
    }
    if ((int) $doel['id'] === (int) $user['id']) {
        throw new SpelFout('Je kunt jezelf niet verwijderen.');
    }

    return db_transaction(static function () use ($user, $doel): string {
        foreach (['garage', 'iplog', 'hitlist', 'invite', 'loterij', 'kras'] as $tabel) {
            q("DELETE FROM `{$tabel}` WHERE `login` = ?", [$doel['login']]);
        }
        q("DELETE FROM `friends` WHERE `login` = ? OR `friend` = ?", [$doel['login'], $doel['login']]);
        q("UPDATE `casino` SET `owner` = '' WHERE `owner` = ?", [$doel['login']]);
        q('DELETE FROM `users` WHERE `id` = ?', [$doel['id']]);

        log_action((string) $user['login'], 'beheer',
            'Account verwijderd', 0, (string) $doel['login']);

        return 'Het account ' . $doel['login'] . ' is verwijderd, inclusief garage, '
             . 'vrienden en openstaande vermeldingen. Een casino komt weer vrij.';
    });
}

/** @throws SpelFout */
function ip_bannen(array $user, string $ip): string
{
    if ((int) $user['level'] < LEVEL_ADMIN) {
        throw new SpelFout('Je hebt niet genoeg rechten om te bannen.');
    }
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        throw new SpelFout('Dat is geen geldig IP-adres.');
    }

    $staf = (int) q_val('SELECT COUNT(*) FROM `users` WHERE `ip` = ? AND `level` >= ?',
        [$ip, LEVEL_MODERATOR], 0);

    if ($staf > 0) {
        throw new SpelFout('Op dit IP-adres zit een staflid; dat ban je niet.');
    }
    if ((int) q_val('SELECT COUNT(*) FROM `bans` WHERE `ip` = ?', [$ip], 0) > 0) {
        throw new SpelFout('Dit IP-adres is al verbannen.');
    }

    q('INSERT INTO `bans` (`ip`, `reden`, `door`) VALUES (?, ?, ?)',
        [$ip, 'Verbannen via zoekpagina', $user['login']]);

    log_action((string) $user['login'], 'beheer', 'IP verbannen: ' . $ip);

    return 'Het IP-adres ' . $ip . ' is verbannen.';
}

// ==========================================================================

function toon_zoekformulier(): void
{
    panel_open('Speler zoeken');
    echo '<form method="post">' . csrf_field();
    echo '<div class="veldenraster">';
    echo '<label for="naam">Gebruikersnaam</label>';
    echo '<input id="naam" name="naam" maxlength="16" required>';
    echo '<span></span><button type="submit">Zoeken</button>';
    echo '</div></form>';
    panel_close();
}

function toon_speler(array $user, string $naam): void
{
    $doel = q_row('SELECT * FROM `users` WHERE `login` = ?', [$naam]);

    if ($doel === null) {
        panel_open('Zoekresultaat');
        notice('Die speler bestaat niet.', 'fout');
        panel_close();
        return;
    }

    panel_open($doel['login']);

    echo '<div class="tabelwikkel"><table class="lijst">';
    foreach ([
        'Nummer'       => '#' . (int) $doel['id'],
        'Status'       => e((string) $doel['status']) . ((int) $doel['activated'] === 0 ? ' (niet geactiveerd)' : ''),
        'Rechten'      => (int) $doel['level'],
        'E-mail'       => e((string) $doel['email']),
        'IP-adres'     => e((string) $doel['ip']),
        'Stad'         => e((string) $doel['stad']),
        'Familie'      => $doel['famillie'] !== '' ? e((string) $doel['famillie']) : 'Geen',
        'Ervaring'     => num((int) $doel['xp']),
        'Op zak'       => money((int) $doel['zak']),
        'Op de bank'   => money((int) $doel['bank']),
        'Kogels'       => num((int) $doel['kogels']),
        'Lid sinds'    => e(datetime_nl($doel['start'])),
        'Laatst online'=> e(datetime_nl($doel['online'])),
    ] as $label => $waarde) {
        echo '<tr><th scope="row">' . e($label) . '</th><td>' . $waarde . '</td></tr>';
    }
    echo '</table></div>';

    // --- Acties ---
    echo '<p>';
    if ((int) $user['level'] >= LEVEL_ADMIN && (string) $doel['ip'] !== '') {
        echo '<form method="post" style="display:inline">' . csrf_field()
           . '<input type="hidden" name="actie" value="ban_ip">'
           . '<input type="hidden" name="ip" value="' . e((string) $doel['ip']) . '">'
           . '<button type="submit">Ban dit IP-adres</button></form> ';
    }
    if ((int) $user['level'] >= LEVEL_OWNER && (int) $doel['level'] < (int) $user['level']) {
        echo '<form method="post" style="display:inline">' . csrf_field()
           . '<input type="hidden" name="actie" value="inloggen">'
           . '<input type="hidden" name="naam" value="' . e((string) $doel['login']) . '">'
           . '<button type="submit">Inloggen als deze speler</button></form> ';
        echo '<form method="post" style="display:inline">' . csrf_field()
           . '<input type="hidden" name="actie" value="verwijder">'
           . '<input type="hidden" name="naam" value="' . e((string) $doel['login']) . '">'
           . '<button type="submit">Account verwijderen</button></form>';
    }
    echo '</p>';

    panel_close();

    // --- IP-geschiedenis ---
    $iplog = q_all('SELECT * FROM `iplog` WHERE `login` = ? ORDER BY `time` DESC LIMIT 25',
        [$doel['login']]);

    panel_open('IP-geschiedenis');

    if ($iplog === []) {
        echo '<p>Geen aanmeldingen vastgelegd.</p>';
    } else {
        echo '<div class="tabelwikkel"><table class="lijst">';
        echo '<thead><tr><th>IP-adres</th><th>Laatst</th><th>Andere accounts op dit IP</th></tr></thead><tbody>';

        foreach ($iplog as $regel) {
            $andere = q_all(
                'SELECT `login` FROM `iplog` WHERE `ip` = ? AND `login` <> ? GROUP BY `login` LIMIT 10',
                [$regel['ip'], $doel['login']]
            );

            $namen = array_map(
                static fn (array $r): string => '<a' . speler_rol_attr((string) $r['login']) . ' href="'
                    . e(beheer_url('search.php?login=' . rawurlencode((string) $r['login']))) . '">'
                    . e((string) $r['login']) . '</a>',
                $andere
            );

            echo '<tr>';
            echo '<td>' . e((string) $regel['ip']) . '</td>';
            echo '<td>' . e(datetime_nl($regel['time'])) . '</td>';
            echo '<td>' . ($namen === [] ? '<small>geen</small>' : implode(', ', $namen)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    panel_close();
}
