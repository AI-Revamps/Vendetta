<?php
/**
 * Organised Crime: met vier spelers een bank beroven.
 *
 * De leider maakt de plannen en nodigt drie mensen uit:
 *   WE - wapenexpert, koopt wapens en kogels
 *   BE - bommenexpert, koopt explosieven
 *   DR - driver, levert de vluchtauto
 *
 * Zijn alle drie klaar, dan kan de leider de overval starten.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - Het aantal kogels en bommen kwam ongefilterd uit het formulier in de
 *    kostenberekening: `50000 * aantal`. Met een negatief aantal werden de
 *    kosten negatief en leverde `zak = zak - kosten` juist geld op. Zo was er
 *    onbeperkt geld te maken. Nu worden hoeveelheden begrensd en gecontroleerd.
 *  - Accepteren, annuleren en zelfs het starten van de overval liepen via
 *    GET-links. Een afbeelding met `oc.php?go=1` in een bericht liet de
 *    ontvanger de overval starten. Alles gaat nu via POST met CSRF-token.
 *  - Betalen en de OC bijwerken gebeurde in losse queries zonder transactie.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/captcha.php';

const OC_MIN_XP        = 500;        // minimaal Thief
const OC_PLANKOSTEN    = 50_000;
const OC_RUSTTIJD      = 43200;      // twaalf uur
const OC_CELTIJD       = 600;
const OC_BOETE         = 1_000_000;
const OC_MAX_KOGELS    = 1000;
const OC_MAX_BOMMEN    = 10;
const OC_AUTO_MAXSCHADE = 90;

/** Wapens en bommen die de experts kunnen kopen. */
function oc_wapens(): array
{
    return [
        1 => ['naam' => 'Machinegeweer', 'prijs' => 50_000],
        2 => ['naam' => 'Zware artillerie', 'prijs' => 100_000],
    ];
}

function oc_bommen(): array
{
    return [
        1 => ['naam' => 'Dynamiet', 'prijs' => 50_000],
        2 => ['naam' => 'C4', 'prijs' => 100_000],
    ];
}

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

$oc    = mijn_oc((string) $user['login']);
$rust  = cooldown_left((int) $user['bc_ts']);

layout_header('Organised Crime');

if ($melding !== null) {
    notice(nl2br(e($melding)), $type);
}

panel_open('Organised Crime');

if ((int) $user['xp'] < OC_MIN_XP) {
    echo '<p>Je moet minstens de rang Thief hebben (' . num(OC_MIN_XP)
       . ' ervaringspunten) om aan een Organised Crime mee te doen.</p>';
} elseif ($rust > 0 && $oc === null) {
    echo '<p>Je bent nog aan het uitrusten van je vorige overval. Nog '
       . '<strong data-tot="' . (time() + $rust) . '">' . e(duration($rust)) . '</strong>.</p>';
} elseif ($oc === null) {
    toon_planformulier($user);
} else {
    toon_oc($user, $oc);
}

panel_close();
layout_footer();

// ==========================================================================
// Verwerking
// ==========================================================================

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'plan'        => plannen($user),
        'vervang'     => vervangen($user, post('rol'), post('naam')),
        // Bewust zonder begrenzing inlezen: de controles in de functies zelf
        // wijzen een ongeldig aantal af met een duidelijke melding, in plaats
        // van het stil naar de dichtstbijzijnde waarde te verschuiven.
        'kies_wapens' => wapens_kopen($user, int_input('wapen'), int_input('kogels', -1)),
        'kies_bommen' => bommen_kopen($user, int_input('bom'), int_input('aantal', -1)),
        'kies_auto'   => auto_leveren($user, int_input('car')),
        'weiger'      => weigeren($user),
        'annuleer'    => annuleren($user),
        'go'          => overval_plegen($user),
        default       => throw new SpelFout('Onbekende handeling.'),
    };
}

/**
 * De OC waar deze speler bij betrokken is, of null.
 *
 * Elke plaatshouder krijgt zijn eigen naam: met echte prepared statements
 * (PDO::ATTR_EMULATE_PREPARES = false) mag dezelfde naam niet hergebruikt
 * worden, want MySQL bindt op positie.
 */
function mijn_oc(string $login, bool $vergrendel = false): ?array
{
    $sql = 'SELECT * FROM `oc`
             WHERE `login` = :a OR `dr` = :b OR `we` = :c OR `be` = :d
             LIMIT 1';
    if ($vergrendel) {
        $sql .= ' FOR UPDATE';
    }
    return q_row($sql, ['a' => $login, 'b' => $login, 'c' => $login, 'd' => $login]);
}

/** Welke rol heeft deze speler in deze OC? */
function mijn_rol(array $oc, string $login): string
{
    return match ($login) {
        $oc['login'] => 'leider',
        $oc['dr']    => 'dr',
        $oc['we']    => 'we',
        $oc['be']    => 'be',
        default      => '',
    };
}

/**
 * Controleer of iemand mee kan doen.
 *
 * @throws SpelFout
 */
function bruikbare_deelnemer(string $naam, array $bezet): array
{
    if ($naam === '') {
        throw new SpelFout('Vul voor elke rol een naam in.');
    }

    $speler = q_row(
        'SELECT *, UNIX_TIMESTAMP(`bc`) AS `bc_ts` FROM `users` WHERE `login` = ?',
        [$naam]
    );

    if ($speler === null) {
        throw new SpelFout('De speler ' . $naam . ' bestaat niet.');
    }
    if ($speler['status'] !== 'levend') {
        throw new SpelFout($speler['login'] . ' is dood.');
    }
    if ((int) $speler['xp'] < OC_MIN_XP) {
        throw new SpelFout($speler['login'] . ' is nog geen Thief.');
    }
    if (cooldown_left((int) $speler['bc_ts']) > 0) {
        throw new SpelFout($speler['login'] . ' is nog aan het uitrusten van een vorige overval.');
    }
    if (mijn_oc((string) $speler['login']) !== null) {
        throw new SpelFout($speler['login'] . ' is al met een OC bezig.');
    }

    // Vergelijking zonder hoofdlettergevoeligheid: anders kun je jezelf
    // als "padrino" naast "Padrino" invullen.
    foreach ($bezet as $andere) {
        if (strcasecmp((string) $speler['login'], $andere) === 0) {
            throw new SpelFout('Je moet voor iedere rol iemand anders kiezen.');
        }
    }

    return $speler;
}

/** @throws SpelFout */
function plannen(array $user): string
{
    if (!captcha_check(post('verify'))) {
        throw new SpelFout('De code die je invoerde klopt niet.');
    }
    if ((int) $user['xp'] < OC_MIN_XP) {
        throw new SpelFout('Je moet minstens de rang Thief hebben.');
    }
    if (cooldown_left((int) $user['bc_ts']) > 0) {
        throw new SpelFout('Je bent nog aan het uitrusten van je vorige overval.');
    }

    return db_transaction(static function () use ($user): string {
        if (mijn_oc((string) $user['login'], true) !== null) {
            throw new SpelFout('Je bent al met een OC bezig.');
        }

        $zelf = (string) $user['login'];
        $dr = bruikbare_deelnemer(post('dr'), [$zelf]);
        $we = bruikbare_deelnemer(post('we'), [$zelf, (string) $dr['login']]);
        $be = bruikbare_deelnemer(post('be'), [$zelf, (string) $dr['login'], (string) $we['login']]);

        lock_user((int) $user['id']);
        if (!afboeken((int) $user['id'], OC_PLANKOSTEN, 'zak')) {
            throw new SpelFout('Je hebt ' . money(OC_PLANKOSTEN) . ' op zak nodig om de plannen te maken.');
        }

        q(
            'INSERT INTO `oc` (`login`, `dr`, `we`, `be`, `stad`) VALUES (?, ?, ?, ?, ?)',
            [$zelf, $dr['login'], $we['login'], $be['login'], $user['stad']]
        );

        uitnodigen((string) $dr['login'], $zelf, (string) $user['stad'], 'driver', 'een wagen te kiezen');
        uitnodigen((string) $we['login'], $zelf, (string) $user['stad'], 'wapenexpert', 'wapens te kopen');
        uitnodigen((string) $be['login'], $zelf, (string) $user['stad'], 'bommenexpert', 'explosieven te kopen');

        return 'De plannen zijn gemaakt en de uitnodigingen zijn verstuurd.';
    });
}

function uitnodigen(string $naar, string $leider, string $stad, string $rol, string $taak): void
{
    notify($naar, 'Organised Crime',
        $leider . ' nodigt je uit voor een overval in ' . $stad . ' als ' . $rol . '. '
        . 'Ga naar Organised Crime om ' . $taak . ' of te weigeren.');
}

/** @throws SpelFout */
function vervangen(array $user, string $rol, string $naam): string
{
    if (!in_array($rol, ['dr', 'we', 'be'], true)) {
        throw new SpelFout('Onbekende rol.');
    }

    return db_transaction(static function () use ($user, $rol, $naam): string {
        $oc = mijn_oc((string) $user['login'], true);

        if ($oc === null || $oc['login'] !== $user['login']) {
            throw new SpelFout('Alleen de leider kan iemand vervangen.');
        }

        $bezet = array_filter([
            (string) $oc['login'], (string) $oc['dr'], (string) $oc['we'], (string) $oc['be'],
        ], static fn (string $n): bool => $n !== '' && $n !== (string) $oc[$rol]);

        $nieuw = bruikbare_deelnemer($naam, array_values($bezet));

        // Rol leegmaken en wat die persoon had ingebracht ongedaan maken.
        $resets = match ($rol) {
            'dr' => "`ready3` = 0, `auto` = '', `damage` = 0, `autoid` = 0",
            'we' => '`ready1` = 0, `wapens` = 0, `kogels` = 0',
            'be' => '`ready2` = 0, `bommen` = 0, `aantal` = 0',
        };

        q("UPDATE `oc` SET `{$rol}` = ?, {$resets} WHERE `id` = ?", [$nieuw['login'], $oc['id']]);

        $rolnaam = ['dr' => 'driver', 'we' => 'wapenexpert', 'be' => 'bommenexpert'][$rol];
        uitnodigen((string) $nieuw['login'], (string) $user['login'], (string) $oc['stad'], $rolnaam, 'mee te doen');

        return $nieuw['login'] . ' is uitgenodigd als ' . $rolnaam . '.';
    });
}

/** @throws SpelFout */
function wapens_kopen(array $user, int $wapen, int $kogels): string
{
    $lijst = oc_wapens();
    if (!isset($lijst[$wapen])) {
        throw new SpelFout('Kies een geldig wapen.');
    }
    if ($kogels < 0 || $kogels > OC_MAX_KOGELS) {
        throw new SpelFout('Kies tussen 0 en ' . OC_MAX_KOGELS . ' kogels.');
    }

    return db_transaction(static function () use ($user, $wapen, $kogels, $lijst): string {
        $oc = mijn_oc((string) $user['login'], true);

        if ($oc === null || $oc['we'] !== $user['login']) {
            throw new SpelFout('Je bent niet de wapenexpert van deze OC.');
        }
        if ((int) $oc['ready1'] === 1) {
            throw new SpelFout('Je hebt de wapens al gekocht.');
        }

        // Kosten worden hier berekend, niet meegestuurd door de browser.
        $kosten = $lijst[$wapen]['prijs'] + $kogels * 500;

        lock_user((int) $user['id']);
        if (!afboeken((int) $user['id'], $kosten, 'zak')) {
            throw new SpelFout('Je hebt ' . money($kosten) . ' op zak nodig.');
        }

        q('UPDATE `oc` SET `wapens` = ?, `kogels` = ?, `ready1` = 1 WHERE `id` = ?',
            [$wapen, $kogels, $oc['id']]);

        notify((string) $oc['login'], 'Organised Crime',
            $user['login'] . ' heeft voor ' . money($kosten) . ' aan wapens gekocht en is klaar.');

        return 'Je hebt ' . $lijst[$wapen]['naam'] . ' en ' . num($kogels)
             . ' kogels gekocht voor ' . money($kosten) . '.';
    });
}

/** @throws SpelFout */
function bommen_kopen(array $user, int $bom, int $aantal): string
{
    $lijst = oc_bommen();
    if (!isset($lijst[$bom])) {
        throw new SpelFout('Kies een geldig explosief.');
    }
    if ($aantal < 1 || $aantal > OC_MAX_BOMMEN) {
        throw new SpelFout('Kies tussen 1 en ' . OC_MAX_BOMMEN . ' explosieven.');
    }

    return db_transaction(static function () use ($user, $bom, $aantal, $lijst): string {
        $oc = mijn_oc((string) $user['login'], true);

        if ($oc === null || $oc['be'] !== $user['login']) {
            throw new SpelFout('Je bent niet de bommenexpert van deze OC.');
        }
        if ((int) $oc['ready2'] === 1) {
            throw new SpelFout('Je hebt de explosieven al gekocht.');
        }

        $kosten = $lijst[$bom]['prijs'] * $aantal;

        lock_user((int) $user['id']);
        if (!afboeken((int) $user['id'], $kosten, 'zak')) {
            throw new SpelFout('Je hebt ' . money($kosten) . ' op zak nodig.');
        }

        q('UPDATE `oc` SET `bommen` = ?, `aantal` = ?, `ready2` = 1 WHERE `id` = ?',
            [$bom, $aantal, $oc['id']]);

        notify((string) $oc['login'], 'Organised Crime',
            $user['login'] . ' heeft voor ' . money($kosten) . ' aan explosieven gekocht en is klaar.');

        return 'Je hebt ' . $aantal . '× ' . $lijst[$bom]['naam'] . ' gekocht voor ' . money($kosten) . '.';
    });
}

/** @throws SpelFout */
function auto_leveren(array $user, int $garageId): string
{
    return db_transaction(static function () use ($user, $garageId): string {
        $oc = mijn_oc((string) $user['login'], true);

        if ($oc === null || $oc['dr'] !== $user['login']) {
            throw new SpelFout('Je bent niet de driver van deze OC.');
        }
        if ((int) $oc['ready3'] === 1) {
            throw new SpelFout('Je hebt de wagen al geleverd.');
        }

        $auto = q_row('SELECT * FROM `garage` WHERE `id` = ? AND `login` = ? FOR UPDATE',
            [$garageId, $user['login']]);

        if ($auto === null) {
            throw new SpelFout('Die wagen staat niet in jouw garage.');
        }
        if ((int) $auto['damage'] > OC_AUTO_MAXSCHADE) {
            throw new SpelFout('Deze wagen is te zwaar beschadigd.');
        }
        if ($auto['stad'] !== $oc['stad']) {
            throw new SpelFout('Deze wagen staat niet in ' . $oc['stad'] . '.');
        }

        q('UPDATE `oc` SET `autoid` = ?, `auto` = ?, `damage` = ?, `ready3` = 1 WHERE `id` = ?',
            [$auto['id'], $auto['naam'], $auto['damage'], $oc['id']]);
        q('DELETE FROM `garage` WHERE `id` = ?', [$auto['id']]);

        notify((string) $oc['login'], 'Organised Crime',
            $user['login'] . ' levert een ' . $auto['naam'] . ' als vluchtauto en is klaar.');

        return 'Je ' . $auto['naam'] . ' staat klaar als vluchtauto. '
             . 'Let op: je krijgt hem niet terug, hoe de overval ook afloopt.';
    });
}

/** @throws SpelFout */
function weigeren(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $oc  = mijn_oc((string) $user['login'], true);
        $rol = $oc === null ? '' : mijn_rol($oc, (string) $user['login']);

        if ($oc === null || $rol === '' || $rol === 'leider') {
            throw new SpelFout('Je bent niet als deelnemer uitgenodigd.');
        }

        q("UPDATE `oc` SET `{$rol}` = '' WHERE `id` = ?", [$oc['id']]);

        notify((string) $oc['login'], 'Organised Crime',
            $user['login'] . ' heeft de uitnodiging geweigerd. Zoek een vervanger.');

        return 'Je hebt de uitnodiging geweigerd.';
    });
}

/** @throws SpelFout */
function annuleren(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $oc  = mijn_oc((string) $user['login'], true);
        $rol = $oc === null ? '' : mijn_rol($oc, (string) $user['login']);

        if ($oc === null || $rol === '') {
            throw new SpelFout('Je bent niet met een OC bezig.');
        }

        if ($rol !== 'leider') {
            q("UPDATE `oc` SET `{$rol}` = '' WHERE `id` = ?", [$oc['id']]);
            notify((string) $oc['login'], 'Organised Crime',
                $user['login'] . ' heeft zich teruggetrokken. Zoek een vervanger.');
            return 'Je hebt je teruggetrokken uit de OC.';
        }

        foreach (['dr', 'we', 'be'] as $r) {
            if ($oc[$r] !== '') {
                notify((string) $oc[$r], 'Organised Crime',
                    $user['login'] . ' heeft de OC geannuleerd. Je spullen ben je kwijt.');
            }
        }
        q('DELETE FROM `oc` WHERE `id` = ?', [$oc['id']]);

        return 'Je hebt de OC geannuleerd. Iedereen is zijn inbreng kwijt.';
    });
}

/**
 * Voer de overval uit.
 *
 * @throws SpelFout
 */
function overval_plegen(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $oc = mijn_oc((string) $user['login'], true);

        if ($oc === null || $oc['login'] !== $user['login']) {
            throw new SpelFout('Alleen de leider kan de overval starten.');
        }
        if ((int) $oc['ready1'] !== 1 || (int) $oc['ready2'] !== 1 || (int) $oc['ready3'] !== 1) {
            throw new SpelFout('Niet iedereen is klaar.');
        }

        // Alle vier ophalen en vergrendelen.
        $team = [];
        foreach (['login', 'dr', 'we', 'be'] as $rol) {
            $speler = lock_user_by_login((string) $oc[$rol]);
            if ($speler === null || $speler['status'] !== 'levend') {
                throw new SpelFout('Een van je teamgenoten is niet meer beschikbaar.');
            }
            if ($speler['stad'] !== $oc['stad']) {
                throw new SpelFout($speler['login'] . ' is niet in ' . $oc['stad'] . '.');
            }
            $team[$rol] = $speler;
        }

        // --- Slaagkans ---
        $ervaring = array_sum(array_map(static fn (array $s): int => (int) $s['xp'], $team));
        $staf     = array_sum(array_map(static fn (array $s): int => (int) $s['level'], $team)) > 255;

        $worp = match (true) {
            $staf             => random_int(1, 2),
            $ervaring < 6000  => random_int(1, 10),
            $ervaring < 12000 => random_int(1, 9),
            $ervaring < 22000 => random_int(1, 8),
            $ervaring < 40000 => random_int(1, 7),
            default           => random_int(1, 6),
        };

        // Goede uitrusting verlaagt de worp en dus de kans op mislukking.
        if ((int) $oc['wapens'] === 2)    { $worp--; }
        if ((int) $oc['kogels'] >= 25)    { $worp--; }
        if ((int) $oc['bommen'] === 2)    { $worp--; }
        if ((int) $oc['aantal'] >= 3)     { $worp--; }
        if ((int) $oc['damage'] >= 50)    { $worp--; }
        if ((int) $oc['damage'] >= 100)   { $worp--; }

        $gelukt = $worp <= 1;
        $rust   = time() + OC_RUSTTIJD;

        // Iedereen krijgt ervaring en rusttijd, geslaagd of niet.
        foreach ($team as $speler) {
            q('UPDATE `users` SET `xp` = `xp` + 6, `bc` = FROM_UNIXTIME(?), `nrofoc` = `nrofoc` + 1 WHERE `id` = ?',
                [$rust, $speler['id']]);
        }

        $tekst = $gelukt
            ? overval_geslaagd($team, $oc)
            : overval_mislukt($team, $oc);

        q('DELETE FROM `oc` WHERE `id` = ?', [$oc['id']]);

        return $tekst;
    });
}

function overval_geslaagd(array $team, array $oc): string
{
    $buit = random_int(500_000, 4_000_000);

    $verhalen = [
        'Jullie maakten het alarm onschadelijk. Met een wapen onder zijn neus vulde de bediende drie zakken.',
        'Jullie vroegen doodleuk de portefeuilles van alle aanwezigen op. Niemand durfde te weigeren.',
        'De directeur wilde het alarm indrukken, maar jullie wapenexpert was sneller. Al schietend kwamen jullie weg.',
        'Een bediende begon te schreeuwen en de beveiliging kwam af. Na een vuurgevecht wisten jullie te ontsnappen.',
    ];
    $verhaal = $verhalen[array_rand($verhalen)];

    bijschrijven((int) $team['login']['id'], $buit, 'zak');

    $bericht = $verhaal . ' Jullie hebben ' . money($buit) . ' buitgemaakt. '
             . 'De leider heeft het geld; verdeel het onderling.';

    foreach (['dr', 'we', 'be'] as $rol) {
        notify((string) $team[$rol]['login'], 'Organised Crime', $bericht);
    }
    log_action((string) $team['login']['login'], 'oc', 'Overval geslaagd', $buit);

    return $bericht;
}

function overval_mislukt(array $team, array $oc): string
{
    // Elk verhaal heeft zijn eigen gevolg.
    $uitkomsten = [
        ['tekst' => 'Jullie hadden de explosieven slecht aangebracht. Al het geld ging in vlammen op, '
                  . 'maar jullie konden wegrijden voor de politie kwam.',
         'gevolg' => 'geen'],
        ['tekst' => 'Er kwam een bewaker binnen die begon te schieten. Jullie moesten vluchten en raakten gewond.',
         'gevolg' => 'gewond'],
        ['tekst' => 'De kluis openen was een makkie, maar buiten stond een bataljon agenten te wachten.',
         'gevolg' => 'cel'],
        ['tekst' => 'Iemand liet het alarm afgaan. De wapenexpert schoot in het wilde weg; jullie kwamen net weg.',
         'gevolg' => 'geen'],
        ['tekst' => 'Er stond een eenheid agenten buiten. Jullie bliezen de overval af.',
         'gevolg' => 'geen'],
        ['tekst' => 'De vluchtauto werd onderweg tegengehouden voor een drugscontrole. Er werd niets gevonden.',
         'gevolg' => 'geen'],
        ['tekst' => 'De bediende gaf zich zonder slag of stoot over en verzorgde jullie zelfs. '
                  . 'Jullie zijn er beter uitgekomen dan jullie erin gingen.',
         'gevolg' => 'genezen'],
        ['tekst' => 'Jullie probeerden via het ventilatiesysteem bij de kluis te komen.',
         'gevolg' => 'kluisje'],
        ['tekst' => 'Een oude dame kreeg een hartaanval en viel tegen de leider aan. In de verwarring '
                  . 'zag niemand dat het alarm werd ingedrukt.',
         'gevolg' => 'geen'],
    ];

    $uitkomst = $uitkomsten[array_rand($uitkomsten)];
    $tekst    = $uitkomst['tekst'];

    switch ($uitkomst['gevolg']) {
        case 'gewond':
            foreach ($team as $speler) {
                q('UPDATE `users` SET `health` = GREATEST(1, `health` - 2) WHERE `id` = ?', [$speler['id']]);
            }
            break;

        case 'cel':
            foreach ($team as $speler) {
                q(
                    'INSERT INTO `jail` (`login`, `boete`, `stad`, `famillie`, `time`)
                          VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                     ON DUPLICATE KEY UPDATE `time` = VALUES(`time`), `boete` = VALUES(`boete`)',
                    [$speler['login'], OC_BOETE, $oc['stad'], $speler['famillie'], OC_CELTIJD]
                );
            }
            $tekst .= ' Jullie zitten nu allemaal vast.';
            break;

        case 'genezen':
            foreach ($team as $speler) {
                q('UPDATE `users` SET `health` = 100 WHERE `id` = ?', [$speler['id']]);
            }
            $tekst .= ' Jullie gezondheid staat weer op 100%.';
            break;

        case 'kluisje':
            if (random_int(1, 2) === 1) {
                $contant = random_int(100_000, 1_000_000);
                $waar    = random_int(1, 2) === 1 ? 'drugs' : 'drank';
                $units   = random_int(5, 20);

                bijschrijven((int) $team['login']['id'], $contant, 'zak');
                // Kolomnaam uit een vaste lijst, niet uit invoer.
                q("UPDATE `users` SET `{$waar}` = `{$waar}` + ? WHERE `id` = ?",
                    [$units, $team['login']['id']]);

                $tekst .= ' Jullie kwamen uit bij het kantoor van de directeur, met een klein privékluisje. '
                        . 'Er lag ' . money($contant) . ' en ' . $units . ' units ' . $waar . ' in. '
                        . 'De leider heeft het; verdeel het onderling.';
            } else {
                $tekst .= ' Jullie kwamen vast te zitten in de koker en zijn maar teruggekropen.';
            }
            break;
    }

    foreach (['dr', 'we', 'be'] as $rol) {
        notify((string) $team[$rol]['login'], 'Organised Crime', $tekst);
    }
    log_action((string) $team['login']['login'], 'oc', 'Overval mislukt');

    return $tekst;
}

// ==========================================================================
// Weergave
// ==========================================================================

function toon_planformulier(array $user): void
{
    echo '<p>Maak hier de plannen om een bank te beroven. Je hebt drie medeplichtigen '
       . 'nodig die minstens Thief zijn, in ' . e((string) $user['stad']) . ' verblijven '
       . 'en niet al met een OC bezig zijn.</p>';
    echo '<p>De plannen kosten ' . money(OC_PLANKOSTEN) . '.</p>';

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="plan">';
    echo '<div class="veldenraster">';
    echo '<label for="we">Wapenexpert</label><input id="we" name="we" maxlength="16" required>';
    echo '<label for="be">Bommenexpert</label><input id="be" name="be" maxlength="16" required>';
    echo '<label for="dr">Driver</label><input id="dr" name="dr" maxlength="16" required>';
    echo '<span></span>' . captcha_field();
    echo '<span></span><button type="submit">Maak de plannen</button>';
    echo '</div></form>';
}

function toon_oc(array $user, array $oc): void
{
    $rol   = mijn_rol($oc, (string) $user['login']);
    $klaar = (int) $oc['ready1'] === 1 && (int) $oc['ready2'] === 1 && (int) $oc['ready3'] === 1;

    // --- Stand van zaken ---
    echo '<p>Overval in <strong>' . e((string) $oc['stad']) . '</strong>. Jouw rol: <strong>'
       . e(rolnaam($rol)) . '</strong>.</p>';

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Rol</th><th>Speler</th><th>Status</th></tr></thead><tbody>';
    echo '<tr><th scope="row">Leider</th><td>' . speler_naam((string) $oc['login'])
       . '</td><td>Plannen gemaakt</td></tr>';
    regelrij('Wapenexpert', (string) $oc['we'], (int) $oc['ready1'],
        oc_wapens()[(int) $oc['wapens']]['naam'] ?? '' , num((int) $oc['kogels']) . ' kogels');
    regelrij('Bommenexpert', (string) $oc['be'], (int) $oc['ready2'],
        oc_bommen()[(int) $oc['bommen']]['naam'] ?? '', (int) $oc['aantal'] . '×');
    regelrij('Driver', (string) $oc['dr'], (int) $oc['ready3'],
        (string) $oc['auto'], (int) $oc['damage'] . '% schade');
    echo '</tbody></table></div>';

    // --- Wat kan ik nu doen? ---
    if ($rol === 'we' && (int) $oc['ready1'] === 0) {
        formulier_wapens();
    } elseif ($rol === 'be' && (int) $oc['ready2'] === 0) {
        formulier_bommen();
    } elseif ($rol === 'dr' && (int) $oc['ready3'] === 0) {
        formulier_auto($user, (string) $oc['stad']);
    }

    // --- Leider: vervangen en starten ---
    if ($rol === 'leider') {
        foreach (['we' => 'wapenexpert', 'be' => 'bommenexpert', 'dr' => 'driver'] as $r => $naam) {
            if ((string) $oc[$r] === '') {
                echo '<h3>Nieuwe ' . e($naam) . ' zoeken</h3>';
                echo '<form method="post">' . csrf_field()
                   . '<input type="hidden" name="actie" value="vervang">'
                   . '<input type="hidden" name="rol" value="' . e($r) . '">'
                   . '<div class="veldenraster"><label for="n_' . e($r) . '">Naam</label>'
                   . '<input id="n_' . e($r) . '" name="naam" maxlength="16" required>'
                   . '<span></span><button type="submit">Uitnodigen</button></div></form>';
            }
        }

        if ($klaar) {
            echo '<p>Jullie zijn er klaar voor.</p>';
            echo '<form method="post" style="display:inline">' . csrf_field()
               . '<input type="hidden" name="actie" value="go">'
               . '<button type="submit" class="knop-nadruk" style="display:inline-block">Overval starten</button></form> ';
        } else {
            echo '<p>Nog niet iedereen is klaar.</p>';
        }
    }

    // --- Annuleren of weigeren ---
    echo '<form method="post" style="display:inline">' . csrf_field()
       . '<input type="hidden" name="actie" value="annuleer">'
       . '<button type="submit">' . ($rol === 'leider' ? 'OC annuleren' : 'Terugtrekken') . '</button></form>';

    if ($rol !== 'leider' && (int) $oc[gereedveld($rol)] === 0) {
        echo ' <form method="post" style="display:inline">' . csrf_field()
           . '<input type="hidden" name="actie" value="weiger">'
           . '<button type="submit">Uitnodiging weigeren</button></form>';
    }
}

function rolnaam(string $rol): string
{
    return ['leider' => 'leider', 'we' => 'wapenexpert', 'be' => 'bommenexpert', 'dr' => 'driver'][$rol] ?? 'onbekend';
}

function gereedveld(string $rol): string
{
    return ['we' => 'ready1', 'be' => 'ready2', 'dr' => 'ready3'][$rol] ?? 'ready1';
}

function regelrij(string $rol, string $speler, int $gereed, string $wat, string $extra): void
{
    $status = $speler === ''
        ? '<em>heeft afgehaakt</em>'
        : ($gereed === 1 ? 'Klaar — ' . e($wat) . ' (' . e($extra) . ')' : 'Wacht nog');

    echo '<tr><th scope="row">' . e($rol) . '</th>'
       . '<td>' . ($speler === '' ? '-' : speler_naam($speler)) . '</td>'
       . '<td>' . $status . '</td></tr>';
}

function formulier_wapens(): void
{
    echo '<h3>Wapens kopen</h3>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="kies_wapens">';
    echo '<div class="veldenraster">';
    echo '<label for="wapen">Wapen</label><select id="wapen" name="wapen">';
    foreach (oc_wapens() as $nr => $w) {
        echo '<option value="' . $nr . '">' . e($w['naam']) . ' - ' . money($w['prijs']) . '</option>';
    }
    echo '</select>';
    echo '<label for="kogels">Kogels</label>';
    echo '<input id="kogels" name="kogels" type="number" min="0" max="' . OC_MAX_KOGELS . '" step="1" value="25" required>';
    echo '<span></span><small>' . money(500) . ' per kogel. Vanaf 25 kogels stijgt de slaagkans.</small>';
    echo '<span></span><button type="submit">Kopen</button>';
    echo '</div></form>';
}

function formulier_bommen(): void
{
    echo '<h3>Explosieven kopen</h3>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="kies_bommen">';
    echo '<div class="veldenraster">';
    echo '<label for="bom">Soort</label><select id="bom" name="bom">';
    foreach (oc_bommen() as $nr => $b) {
        echo '<option value="' . $nr . '">' . e($b['naam']) . ' - ' . money($b['prijs']) . ' per stuk</option>';
    }
    echo '</select>';
    echo '<label for="aantal">Aantal</label>';
    echo '<input id="aantal" name="aantal" type="number" min="1" max="' . OC_MAX_BOMMEN . '" step="1" value="3" required>';
    echo '<span></span><small>Vanaf 3 stuks stijgt de slaagkans.</small>';
    echo '<span></span><button type="submit">Kopen</button>';
    echo '</div></form>';
}

function formulier_auto(array $user, string $stad): void
{
    $garage = q_all(
        'SELECT * FROM `garage` WHERE `login` = ? AND `stad` = ? AND `damage` <= ? ORDER BY `waarde` DESC',
        [$user['login'], $stad, OC_AUTO_MAXSCHADE]
    );

    echo '<h3>Vluchtauto leveren</h3>';

    if ($garage === []) {
        echo '<p>Je hebt geen bruikbare wagen in ' . e($stad) . '. Je hebt er een nodig met '
           . 'hoogstens ' . OC_AUTO_MAXSCHADE . '% schade.</p>';
        return;
    }

    echo '<p>Let op: je krijgt de wagen niet terug, hoe de overval ook afloopt.</p>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="kies_auto">';
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
