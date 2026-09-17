<?php
/**
 * Premiumcodes aanmaken, en diamanten of premiumdagen rechtstreeks toekennen.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user    = require_level(beheerpaginas()['toekennen.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'code'      => code_maken($user, post('voor')),
            'diamanten' => diamanten_geven($user, post('speler'), int_input('aantal')),
            'dagen'     => premiumdagen_geven($user, post('speler2'), int_input('dagen')),
            default     => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'toekennen.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

// --- Codes --------------------------------------------------------------

$open = q_all('SELECT * FROM `donate` ORDER BY `id` DESC LIMIT 25');

panel_open('Premiumcodes');

echo '<p>Iemand heeft buiten het spel om betaald? Maak hier een code aan. Vul je een '
   . 'naam in, dan werkt de code alleen voor die speler en krijgt hij hem meteen als '
   . 'bericht. Laat je het leeg, dan werkt hij voor de eerste die hem invoert.</p>';

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="code">';
echo '<div class="veldenraster">';
echo '<label for="voor">Voor speler (mag leeg)</label>';
echo '<input id="voor" name="voor" maxlength="16">';
echo '<span></span><button type="submit">Code aanmaken</button>';
echo '</div></form>';

if ($open !== []) {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Code</th><th>Op naam van</th></tr></thead><tbody>';
    foreach ($open as $rij) {
        echo '<tr><td><code>' . e((string) $rij['code']) . '</code></td>'
           . '<td>' . ((string) $rij['door'] === ''
                ? '<em>iedereen</em>' : speler_naam((string) $rij['door'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p class="uitleg">Deze codes zijn nog niet ingewisseld. Zodra iemand hem '
       . 'gebruikt verdwijnt hij uit de lijst.</p>';
}

panel_close();

// --- Diamanten geven ------------------------------------------------------

panel_open('Diamanten toekennen');

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="diamanten">';
echo '<div class="veldenraster">';
echo '<label for="speler">Speler</label>';
echo '<input id="speler" name="speler" maxlength="16" required>';
echo '<label for="aantal">Aantal</label>';
echo '<input id="aantal" name="aantal" type="number" min="1" max="100000" step="1" required>';
echo '<span></span><button type="submit">Toekennen</button>';
echo '</div></form>';

panel_close();

// --- Premiumdagen geven ---------------------------------------------------

panel_open('Premium dagen toekennen');

echo '<p>Rechtstreeks dagen premium bijschrijven, zonder code — bijvoorbeeld als '
   . 'goedmaker. Loopt er nog premium, dan komen de dagen erbij.</p>';

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="dagen">';
echo '<div class="veldenraster">';
echo '<label for="speler2">Speler</label>';
echo '<input id="speler2" name="speler2" maxlength="16" required>';
echo '<label for="dagen">Aantal dagen</label>';
echo '<input id="dagen" name="dagen" type="number" min="1" max="365" step="1" required>';
echo '<span></span><button type="submit">Toekennen</button>';
echo '</div></form>';

panel_close();

beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function code_maken(array $user, string $voor): string
{
    $voor = trim($voor);

    if ($voor !== '') {
        $ontvanger = q_val('SELECT `login` FROM `users` WHERE `login` = ?', [$voor]);

        if ($ontvanger === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }

        $voor = (string) $ontvanger;
    }

    $code = premium_code_maken($voor);

    if ($voor !== '') {
        notify($voor, 'Je premiumcode',
            "Bedankt voor je steun.\n\nJe code is: " . $code . "\n\n"
            . 'Wissel hem in op de premiumpagina. Hij is goed voor '
            . PREMIUM_DAGEN . ' dagen premium.');
    }

    log_action((string) $user['login'], 'premium',
        'Code aangemaakt' . ($voor !== '' ? ' voor ' . $voor : ' (op naam van niemand)'),
        0, $voor);

    return 'Code ' . $code . ' aangemaakt'
         . ($voor !== '' ? ' voor ' . $voor . '. Hij heeft een bericht gekregen.' : '.');
}

/** @throws SpelFout */
function diamanten_geven(array $user, string $naam, int $aantal): string
{
    if ($aantal < 1 || $aantal > 100_000) {
        throw new SpelFout('Het aantal moet tussen 1 en 100.000 liggen.');
    }

    $speler = q_row('SELECT `id`, `login` FROM `users` WHERE `login` = ?', [$naam]);

    if ($speler === null) {
        throw new SpelFout('Die speler bestaat niet.');
    }

    diamanten_bijschrijven((int) $speler['id'], $aantal);

    notify((string) $speler['login'], 'Diamanten',
        'Je hebt ' . num($aantal) . ' diamanten gekregen van het beheer.');

    log_action((string) $user['login'], 'diamant',
        num($aantal) . ' diamanten toegekend', $aantal, (string) $speler['login']);

    return $speler['login'] . ' heeft er ' . num($aantal) . ' gekregen.';
}

/** @throws SpelFout */
function premiumdagen_geven(array $user, string $naam, int $dagen): string
{
    if ($dagen < 1 || $dagen > 365) {
        throw new SpelFout('Het aantal dagen moet tussen 1 en 365 liggen.');
    }

    $speler = q_row('SELECT `id`, `login` FROM `users` WHERE `login` = ?', [$naam]);

    if ($speler === null) {
        throw new SpelFout('Die speler bestaat niet.');
    }

    premium_verlengen((int) $speler['id'], $dagen);

    notify((string) $speler['login'], 'Premium',
        'Je hebt ' . num($dagen) . ' dagen premium gekregen van het beheer.');

    log_action((string) $user['login'], 'premium',
        num($dagen) . ' dagen premium toegekend', $dagen, (string) $speler['login']);

    return $speler['login'] . ' heeft er ' . num($dagen) . ' dagen premium bij gekregen.';
}
