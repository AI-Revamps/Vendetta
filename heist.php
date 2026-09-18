<?php
/**
 * Route 66: met z'n tweeën een vrachtwagen overvallen.
 *
 * De leider nodigt een driver uit en betaalt de wapens. De driver levert een
 * wagen. Zijn beiden klaar, dan kan de leider de overval starten.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - Accepteren, weigeren, annuleren en het starten van de overval liepen via
 *    GET-links. Een afbeelding met heist.php?go=1 in een bericht liet de
 *    ontvanger de overval starten, en met cancel=1 alles verliezen.
 *  - Betalen en de route aanmaken gebeurde in losse queries zonder transactie.
 *  - De wagen van de driver werd uit de garage verwijderd zonder dat ergens
 *    stond dat hij hem kwijt was.
 *  - Bij een mislukking werd de gevangenisstraf voor de driver weggeschreven
 *    met de stad en familie van de leider in plaats van die van de driver.
 *  - $item werd als kaal woord gebruikt (drugs of drank) en daarna als
 *    kolomnaam in een query gezet, in PHP 8 een fatale fout.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/captcha.php';

const R66_MIN_XP     = 150;        // minimaal Pickpocket
const R66_WAPENPRIJS = 50_000;
const R66_CELTIJD    = 600;
const R66_BOETE      = 150_000;
const R66_MAXSCHADE  = 90;

$user = require_login();

if (is_dead()) {
    redirect('rip.php');
}
block_if_jailed();

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

$route = mijn_route((string) $user['login']);
$rust  = cooldown_left((int) $user['pc_ts']);

layout_header('Route 66');

if ($melding !== null) {
    notice(nl2br(e($melding)), $type);
}

panel_open('Route 66');

if ((int) $user['xp'] < R66_MIN_XP) {
    echo '<p>Je moet minstens de rang Pickpocket hebben voor een Route 66.</p>';
} elseif ($rust > 0 && $route === null) {
    echo '<p>Je bent nog aan het uitrusten. Nog <strong data-tot="' . (time() + $rust) . '">'
       . e(duration($rust)) . '</strong>.</p>';
} elseif ($route === null) {
    toon_uitnodiging($user);
} else {
    toon_route($user, $route);
}

panel_close();
layout_footer();

// ==========================================================================

/** De route waar deze speler bij betrokken is, of null. */
function mijn_route(string $login, bool $vergrendel = false): ?array
{
    // Elke plaatshouder een eigen naam: met echte prepared statements mag
    // dezelfde naam niet twee keer voorkomen.
    $sql = 'SELECT * FROM `route66` WHERE `login` = :a OR `driver` = :b LIMIT 1';
    if ($vergrendel) {
        $sql .= ' FOR UPDATE';
    }
    return q_row($sql, ['a' => $login, 'b' => $login]);
}

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'uitnodigen' => uitnodigen($user, post('driver'), post('wapon'), post('verify')),
        'wagen'      => wagen_leveren($user, int_input('car')),
        'weiger'     => weigeren($user),
        'annuleer'   => annuleren($user),
        'go'         => overval($user),
        default      => throw new SpelFout('Onbekende handeling.'),
    };
}

/** @throws SpelFout */
function uitnodigen(array $user, string $naam, string $wapen, string $captcha): string
{
    if (!captcha_check($captcha)) {
        throw new SpelFout('De code die je invoerde klopt niet.');
    }
    if (!in_array($wapen, ['fake', 'echt'], true)) {
        throw new SpelFout('Kies een geldig wapen.');
    }
    if (cooldown_left((int) $user['pc_ts']) > 0) {
        throw new SpelFout('Je bent nog aan het uitrusten.');
    }

    $kosten = $wapen === 'fake' ? 0 : R66_WAPENPRIJS;

    return db_transaction(static function () use ($user, $naam, $wapen, $kosten): string {
        if (mijn_route((string) $user['login'], true) !== null) {
            throw new SpelFout('Je bent al met een Route 66 bezig.');
        }

        $driver = lock_user_by_login($naam);

        if ($driver === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }
        if (strcasecmp((string) $driver['login'], (string) $user['login']) === 0) {
            throw new SpelFout('Je kunt niet tegelijk leider en driver zijn.');
        }
        if ($driver['status'] !== 'levend') {
            throw new SpelFout($driver['login'] . ' is dood.');
        }
        if ((int) $driver['xp'] < R66_MIN_XP) {
            throw new SpelFout($driver['login'] . ' is nog geen Pickpocket.');
        }
        if (mijn_route((string) $driver['login'], true) !== null) {
            throw new SpelFout($driver['login'] . ' is al met een Route 66 bezig.');
        }

        $rustDriver = (int) q_val('SELECT UNIX_TIMESTAMP(`pc`) FROM `users` WHERE `id` = ?',
            [$driver['id']], 0);
        if (cooldown_left($rustDriver) > 0) {
            throw new SpelFout($driver['login'] . ' is nog aan het uitrusten van een vorige Route 66.');
        }

        lock_user((int) $user['id']);

        if ($kosten > 0 && !afboeken((int) $user['id'], $kosten, 'zak')) {
            throw new SpelFout('Echte wapens kosten ' . money($kosten) . '.');
        }

        q('INSERT INTO `route66` (`login`, `driver`, `stad`, `ready1`, `ready2`, `car`)
                VALUES (?, ?, ?, 1, 0, 0)',
            [$user['login'], $driver['login'], $user['stad']]);

        notify((string) $driver['login'], 'Route 66',
            $user['login'] . ' nodigt je uit voor een Route 66 in ' . $user['stad']
            . '. Ga naar Route 66 om een wagen te kiezen of te weigeren.');

        return 'De uitnodiging is verstuurd'
             . ($kosten > 0 ? ' en de wapens zijn gekocht voor ' . money($kosten) : '') . '.';
    });
}

/** @throws SpelFout */
function wagen_leveren(array $user, int $garageId): string
{
    return db_transaction(static function () use ($user, $garageId): string {
        $route = mijn_route((string) $user['login'], true);

        if ($route === null || $route['driver'] !== $user['login']) {
            throw new SpelFout('Je bent niet de driver van deze Route 66.');
        }
        if ((int) $route['ready2'] === 1) {
            throw new SpelFout('Je hebt de wagen al geleverd.');
        }

        $auto = q_row('SELECT * FROM `garage` WHERE `id` = ? AND `login` = ? FOR UPDATE',
            [$garageId, $user['login']]);

        if ($auto === null) {
            throw new SpelFout('Die wagen staat niet in jouw garage.');
        }
        if ((int) $auto['damage'] > R66_MAXSCHADE) {
            throw new SpelFout('Deze wagen is te zwaar beschadigd.');
        }
        if ($auto['stad'] !== $route['stad']) {
            throw new SpelFout('Deze wagen staat niet in ' . $route['stad'] . '.');
        }

        q('UPDATE `route66` SET `car` = ?, `ready2` = 1 WHERE `id` = ?', [$auto['id'], $route['id']]);
        q('DELETE FROM `garage` WHERE `id` = ?', [$auto['id']]);

        notify((string) $route['login'], 'Route 66',
            $user['login'] . ' levert een ' . $auto['naam'] . ' en is klaar.');

        return 'Je ' . $auto['naam'] . ' staat klaar. Let op: je krijgt hem niet terug, '
             . 'hoe de overval ook afloopt.';
    });
}

/** @throws SpelFout */
function weigeren(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $route = mijn_route((string) $user['login'], true);

        if ($route === null || $route['driver'] !== $user['login']) {
            throw new SpelFout('Je bent niet als driver uitgenodigd.');
        }

        q('DELETE FROM `route66` WHERE `id` = ?', [$route['id']]);

        notify((string) $route['login'], 'Route 66',
            $user['login'] . ' heeft de uitnodiging geweigerd.');

        return 'Je hebt de uitnodiging geweigerd.';
    });
}

/** @throws SpelFout */
function annuleren(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $route = mijn_route((string) $user['login'], true);

        if ($route === null) {
            throw new SpelFout('Je bent niet met een Route 66 bezig.');
        }

        $ander = $route['login'] === $user['login'] ? $route['driver'] : $route['login'];
        q('DELETE FROM `route66` WHERE `id` = ?', [$route['id']]);

        notify((string) $ander, 'Route 66', $user['login'] . ' heeft de Route 66 geannuleerd.');

        return 'De Route 66 is geannuleerd. De inbreng is verloren.';
    });
}

/** @throws SpelFout */
function overval(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $route = mijn_route((string) $user['login'], true);

        if ($route === null || $route['login'] !== $user['login']) {
            throw new SpelFout('Alleen de leider kan de overval starten.');
        }
        if ((int) $route['ready2'] !== 1) {
            throw new SpelFout('De driver heeft nog geen wagen geleverd.');
        }

        $leider = lock_user_by_login((string) $route['login']);
        $driver = lock_user_by_login((string) $route['driver']);

        if ($leider === null || $driver === null
            || $leider['status'] !== 'levend' || $driver['status'] !== 'levend') {
            throw new SpelFout('Een van jullie is niet meer beschikbaar.');
        }
        if ($leider['stad'] !== $route['stad'] || $driver['stad'] !== $route['stad']) {
            throw new SpelFout('Jullie moeten allebei in ' . $route['stad'] . ' zijn.');
        }

        // Drugs, drank en verwondingen drukken de opbrengst.
        $ballast = ((int) $leider['drugs'] + (int) $leider['drank']
                  + (int) $driver['drugs'] + (int) $driver['drank']) * 2500;
        $letsel  = ((100 - (int) $leider['health']) + (100 - (int) $driver['health'])) * 5000;

        $buit    = max(5_000, 500_000 - $ballast - $letsel);
        $wagen   = max(10_000, 100_000 - $ballast - $letsel);

        $ervaring = (int) $leider['xp'] + (int) $driver['xp'];
        $staf     = (int) $leider['level'] + (int) $driver['level'] > 255;

        $worp = match (true) {
            $staf             => 1,
            $ervaring < 1000  => random_int(1, 10),
            $ervaring < 2000  => random_int(1, 9),
            $ervaring < 3000  => random_int(1, 8),
            $ervaring < 4500  => random_int(1, 7),
            $ervaring < 6000  => random_int(1, 6),
            $ervaring < 8000  => random_int(1, 5),
            $ervaring < 11000 => random_int(1, 4),
            default           => random_int(1, 3),
        };

        $rust = cooldown_until('route');

        foreach ([$leider, $driver] as $speler) {
            q('UPDATE `users` SET `xp` = `xp` + ?, `pc` = FROM_UNIXTIME(?), `nrofroute` = `nrofroute` + 1
                WHERE `id` = ?',
                [$worp === 1 ? 4 : 1, $rust, $speler['id']]);
        }

        $tekst = $worp === 1
            ? geslaagd($leider, $driver, $buit)
            : mislukt($leider, $driver, $route, $wagen);

        q('DELETE FROM `route66` WHERE `id` = ?', [$route['id']]);

        return $tekst;
    });
}

function geslaagd(array $leider, array $driver, int $buit): string
{
    $verhalen = [
        'Jullie hielden een kleine vrachtwagen tegen. Het laadruim lag vol met zakken geld '
        . 'van het plaatselijke casino.',
        'Jullie lieten een vrachtwagen stoppen die volstond met radio\'s en televisies. '
        . 'Die waren goed te verkopen.',
        'Het laadruim was leeg, dus dwongen jullie de chauffeur naar zijn transportbedrijf te '
        . 'rijden. Daar lieten jullie iedereen op een rij zetten en alles van waarde afgeven.',
    ];

    $verhaal = $verhalen[array_rand($verhalen)] . ' Jullie hebben ' . money($buit) . ' buitgemaakt. '
             . 'De leider heeft het geld; verdeel het onderling.';

    bijschrijven((int) $leider['id'], $buit, 'zak');
    notify((string) $driver['login'], 'Route 66', $verhaal);
    log_action((string) $leider['login'], 'route66', 'Route 66 geslaagd', $buit);

    return $verhaal;
}

function mislukt(array $leider, array $driver, array $route, int $wagen): string
{
    $uitkomsten = [
        ['Er kwam urenlang niets langs. Jullie zijn maar naar huis gegaan.', 'geen'],
        ['De chauffeur trapte het gaspedaal in en ramde jullie wagen. Jullie raakten gewond.', 'gewond'],
        ['Er stond een politiepost verderop. Jullie zijn opgepakt.', 'cel'],
        ['De vrachtwagen bleek van een transportbedrijf met bewaking. Jullie kozen het hazenpad.', 'geen'],
        ['Jullie hielden een lege bestelbus tegen.', 'geen'],
        ['De chauffeur was een oude bekende. Hij trakteerde jullie en lapte jullie op.', 'genezen'],
        ['Jullie hielden een junkie tegen die in een gestolen wagen reed.', 'junkie'],
        ['De wagen sloeg af midden op de weg. Tegen de tijd dat hij weer liep was iedereen weg.', 'geen'],
    ];

    [$tekst, $gevolg] = $uitkomsten[array_rand($uitkomsten)];

    switch ($gevolg) {
        case 'gewond':
            foreach ([$leider, $driver] as $speler) {
                q('UPDATE `users` SET `health` = GREATEST(1, `health` - 2) WHERE `id` = ?', [$speler['id']]);
            }
            break;

        case 'cel':
            // In de oude versie werden stad en familie van de leider gebruikt
            // voor beide cellen, ook voor de driver.
            foreach ([$leider, $driver] as $speler) {
                q(
                    'INSERT INTO `jail` (`login`, `boete`, `stad`, `famillie`, `time`)
                          VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                     ON DUPLICATE KEY UPDATE `time` = VALUES(`time`), `boete` = VALUES(`boete`)',
                    [$speler['login'], R66_BOETE, $speler['stad'], $speler['famillie'], R66_CELTIJD]
                );
            }
            $tekst .= ' Jullie zitten nu allebei vast.';
            break;

        case 'genezen':
            foreach ([$leider, $driver] as $speler) {
                q('UPDATE `users` SET `health` = 100 WHERE `id` = ?', [$speler['id']]);
            }
            $tekst .= ' Jullie gezondheid staat weer op 100%.';
            break;

        case 'junkie':
            if (random_int(1, 2) === 1) {
                $waar  = random_int(1, 2) === 1 ? 'drugs' : 'drank';
                $units = random_int(5, 20);

                bijschrijven((int) $leider['id'], $wagen, 'zak');
                // Kolomnaam uit een vaste lijst, niet uit een variabele die
                // als kaal woord was neergezet.
                q("UPDATE `users` SET `{$waar}` = `{$waar}` + ? WHERE `id` = ?", [$units, $leider['id']]);

                $tekst .= ' Jullie overmeesterden hem, vonden ' . $units . ' units ' . $waar
                        . ' in de wagen en konden die verkopen voor ' . money($wagen)
                        . '. De leider heeft het geld.';
            } else {
                $tekst .= ' Hij wist te ontsnappen.';
            }
            break;
    }

    notify((string) $driver['login'], 'Route 66', $tekst);
    log_action((string) $leider['login'], 'route66', 'Route 66 mislukt');

    return $tekst;
}

// ==========================================================================

function toon_uitnodiging(array $user): void
{
    echo '<p>Overval samen met een driver een vrachtwagen. Je medeplichtige moet minstens '
       . 'Pickpocket zijn, in ' . e((string) $user['stad']) . ' verblijven en niet al met een '
       . 'Route 66 bezig zijn.</p>';
    echo '<p>Met echte wapens (' . money(R66_WAPENPRIJS) . ') maak je meer kans dan met een nepwapen.</p>';

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="uitnodigen">';
    echo '<div class="veldenraster">';
    echo '<label for="driver">Driver</label>';
    echo '<input id="driver" name="driver" maxlength="16" required>';
    echo '<span>Wapens</span><div>'
       . '<label><input type="radio" name="wapon" value="fake" checked> Nepwapen (gratis)</label> '
       . '<label><input type="radio" name="wapon" value="echt"> Echte wapens (' . money(R66_WAPENPRIJS) . ')</label>'
       . '</div>';
    echo '<span></span>' . captcha_field();
    echo '<span></span><button type="submit">Uitnodigen</button>';
    echo '</div></form>';
}

function toon_route(array $user, array $route): void
{
    $isLeider = $route['login'] === $user['login'];
    $klaar    = (int) $route['ready2'] === 1;

    echo '<p>Route 66 in <strong>' . e((string) $route['stad']) . '</strong>. '
       . 'Leider: ' . speler_naam((string) $route['login'])
       . '. Driver: ' . speler_naam((string) $route['driver']) . '.</p>';
    echo '<p>Status: ' . ($klaar ? 'de wagen staat klaar.' : 'wachten op de wagen van de driver.') . '</p>';

    if (!$isLeider && !$klaar) {
        toon_wagenkeuze($user, (string) $route['stad']);

        echo '<form method="post" style="display:inline">' . csrf_field()
           . '<input type="hidden" name="actie" value="weiger">'
           . '<button type="submit">Uitnodiging weigeren</button></form> ';
    }

    if ($isLeider && $klaar) {
        echo '<form method="post" style="display:inline">' . csrf_field()
           . '<input type="hidden" name="actie" value="go">'
           . '<button type="submit" class="knop-nadruk" style="display:inline-block">Start de overval</button></form> ';
    }

    echo '<form method="post" style="display:inline">' . csrf_field()
       . '<input type="hidden" name="actie" value="annuleer">'
       . '<button type="submit">Annuleren</button></form>';
}

function toon_wagenkeuze(array $user, string $stad): void
{
    $garage = q_all(
        'SELECT * FROM `garage` WHERE `login` = ? AND `stad` = ? AND `damage` <= ? ORDER BY `waarde` DESC',
        [$user['login'], $stad, R66_MAXSCHADE]
    );

    echo '<h3>Lever een wagen</h3>';

    if ($garage === []) {
        echo '<p>Je hebt geen bruikbare wagen in ' . e($stad) . '. Je hebt er een nodig met '
           . 'hoogstens ' . R66_MAXSCHADE . '% schade.</p>';
        return;
    }

    echo '<p>Let op: je krijgt de wagen niet terug, hoe de overval ook afloopt.</p>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="wagen">';
    echo '<div class="veldenraster">';
    echo '<label for="car">Wagen</label><select id="car" name="car" required>';
    foreach ($garage as $auto) {
        echo '<option value="' . (int) $auto['id'] . '">' . e((string) $auto['naam'])
           . ' - ' . (int) $auto['damage'] . '% schade - ' . money((int) $auto['waarde']) . '</option>';
    }
    echo '</select>';
    echo '<span></span><button type="submit">Wagen leveren</button>';
    echo '</div></form>';
}
