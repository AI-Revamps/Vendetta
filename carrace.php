<?php
/**
 * Autorace: twee spelers racen om elkaars wagen.
 *
 * Wie wint, krijgt de wagen van de verliezer. Beide wagens lopen schade op.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - De uitslag werd gemeld aan de letterlijke tekst 'enemy->login'; het
 *    dollarteken ontbrak. De tegenstander hoorde dus nooit of hij gewonnen of
 *    verloren had, en er bleef telkens een bericht voor een niet-bestaande
 *    speler achter.
 *  - De controle "je kunt niet tegen jezelf racen" vergeleek je naam met
 *    $_POST['piloot'], een veld dat in het formulier niet bestaat. De
 *    tegenstander stond in $_POST['enemy']. De controle vuurde dus nooit en
 *    racen tegen jezelf was gewoon mogelijk.
 *  - De controle "is al bezig met een race" zocht op een kolom `piloot` die in
 *    de tabel autorace niet bestaat, met een variabele die nergens gezet werd.
 *    Die query faalde altijd, dus ook die controle deed niets.
 *  - Accepteren, weigeren, annuleren en starten liepen via GET-links.
 *  - De schade werd opgeteld zonder bovengrens, waardoor een wagen boven de
 *    100% schade kon uitkomen.
 *  - Geen transactie rond het overzetten van de wagen.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/captcha.php';

const RACE_MIN_XP    = 150;   // minimaal Pickpocket
const RACE_MAXSCHADE = 75;    // hiermee mag je nog racen

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

$race = mijn_race((string) $user['login']);

layout_header('Autorace');

if ($melding !== null) {
    notice(nl2br(e($melding)), $type);
}

panel_open('Autorace');

if ((int) $user['xp'] < RACE_MIN_XP) {
    echo '<p>Je moet minstens de rang Pickpocket hebben (' . num(RACE_MIN_XP)
       . ' ervaringspunten) om te racen.</p>';
} elseif ($race === null) {
    toon_uitdaging($user);
} else {
    toon_race($user, $race);
}

panel_close();
layout_footer();

// ==========================================================================

/** De race waar deze speler bij betrokken is, of null. */
function mijn_race(string $login, bool $vergrendel = false): ?array
{
    $sql = 'SELECT * FROM `autorace` WHERE `login` = :a OR `enemy` = :b LIMIT 1';
    if ($vergrendel) {
        $sql .= ' FOR UPDATE';
    }
    return q_row($sql, ['a' => $login, 'b' => $login]);
}

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'uitdagen'  => uitdagen($user, post('enemy'), int_input('auto'), post('verify')),
        'wagen'     => wagen_kiezen($user, int_input('auto')),
        'weiger'    => weigeren($user),
        'annuleer'  => annuleren($user),
        'go'        => racen($user),
        default     => throw new SpelFout('Onbekende handeling.'),
    };
}

/**
 * Haal een bruikbare wagen van deze speler op.
 *
 * @throws SpelFout
 */
function bruikbare_wagen(array $user, int $garageId, string $stad): array
{
    $auto = q_row('SELECT * FROM `garage` WHERE `id` = ? AND `login` = ? FOR UPDATE',
        [$garageId, $user['login']]);

    if ($auto === null) {
        throw new SpelFout('Die wagen staat niet in jouw garage.');
    }
    if ((int) $auto['damage'] > RACE_MAXSCHADE) {
        throw new SpelFout('Deze wagen is te zwaar beschadigd om mee te racen.');
    }
    if ($auto['stad'] !== $stad) {
        throw new SpelFout('Deze wagen staat niet in ' . $stad . '.');
    }
    if ((int) $auto['safe'] === 1) {
        throw new SpelFout('Deze wagen staat in een safehouse.');
    }

    return $auto;
}

/** @throws SpelFout */
function uitdagen(array $user, string $naam, int $garageId, string $captcha): string
{
    if (!captcha_check($captcha)) {
        throw new SpelFout('De code die je invoerde klopt niet.');
    }

    return db_transaction(static function () use ($user, $naam, $garageId): string {
        if (mijn_race((string) $user['login'], true) !== null) {
            throw new SpelFout('Je bent al met een race bezig.');
        }

        $tegen = lock_user_by_login($naam);

        if ($tegen === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }

        // Deze controle keek in de oude versie naar het verkeerde formulierveld
        // en vuurde daardoor nooit.
        if (strcasecmp((string) $tegen['login'], (string) $user['login']) === 0) {
            throw new SpelFout('Je kunt niet tegen jezelf racen.');
        }
        if ($tegen['status'] !== 'levend') {
            throw new SpelFout($tegen['login'] . ' is dood.');
        }
        if ((int) $tegen['xp'] < RACE_MIN_XP) {
            throw new SpelFout($tegen['login'] . ' is nog geen Pickpocket.');
        }
        if ($tegen['stad'] !== $user['stad']) {
            throw new SpelFout($tegen['login'] . ' is niet in ' . $user['stad'] . '.');
        }
        if (mijn_race((string) $tegen['login'], true) !== null) {
            throw new SpelFout($tegen['login'] . ' is al met een race bezig.');
        }

        $auto = bruikbare_wagen($user, $garageId, (string) $user['stad']);

        q('INSERT INTO `autorace` (`login`, `enemy`, `stad`, `ready1`, `ready2`, `id1`, `id2`)
                VALUES (?, ?, ?, 1, 0, ?, 0)',
            [$user['login'], $tegen['login'], $user['stad'], $auto['id']]);

        notify((string) $tegen['login'], 'Autorace',
            $user['login'] . ' daagt je uit voor een race in ' . $user['stad']
            . '. De winnaar krijgt de wagen van de verliezer. Ga naar Race om te antwoorden.');

        return 'Je hebt ' . $tegen['login'] . ' uitgedaagd met je ' . $auto['naam'] . '.';
    });
}

/** @throws SpelFout */
function wagen_kiezen(array $user, int $garageId): string
{
    return db_transaction(static function () use ($user, $garageId): string {
        $race = mijn_race((string) $user['login'], true);

        if ($race === null || $race['enemy'] !== $user['login']) {
            throw new SpelFout('Je bent niet uitgedaagd voor deze race.');
        }
        if ((int) $race['ready2'] === 1) {
            throw new SpelFout('Je hebt al een wagen gekozen.');
        }

        $auto = bruikbare_wagen($user, $garageId, (string) $race['stad']);

        q('UPDATE `autorace` SET `id2` = ?, `ready2` = 1 WHERE `id` = ?', [$auto['id'], $race['id']]);

        notify((string) $race['login'], 'Autorace',
            $user['login'] . ' heeft de uitdaging aangenomen met een ' . $auto['naam'] . '.');

        return 'Je racet met je ' . $auto['naam'] . '. Wacht tot ' . $race['login'] . ' start.';
    });
}

/** @throws SpelFout */
function weigeren(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $race = mijn_race((string) $user['login'], true);

        if ($race === null || $race['enemy'] !== $user['login']) {
            throw new SpelFout('Je bent niet uitgedaagd.');
        }

        q('DELETE FROM `autorace` WHERE `id` = ?', [$race['id']]);
        notify((string) $race['login'], 'Autorace', $user['login'] . ' heeft de uitdaging afgewezen.');

        return 'Je hebt de uitdaging afgewezen.';
    });
}

/** @throws SpelFout */
function annuleren(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $race = mijn_race((string) $user['login'], true);

        if ($race === null) {
            throw new SpelFout('Je bent niet met een race bezig.');
        }

        $ander = $race['login'] === $user['login'] ? $race['enemy'] : $race['login'];
        q('DELETE FROM `autorace` WHERE `id` = ?', [$race['id']]);
        notify((string) $ander, 'Autorace', $user['login'] . ' heeft de race geannuleerd.');

        return 'De race is geannuleerd.';
    });
}

/** @throws SpelFout */
function racen(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $race = mijn_race((string) $user['login'], true);

        if ($race === null || $race['login'] !== $user['login']) {
            throw new SpelFout('Alleen de uitdager kan de race starten.');
        }
        if ((int) $race['ready2'] !== 1) {
            throw new SpelFout($race['enemy'] . ' heeft nog geen wagen gekozen.');
        }

        $een  = lock_user_by_login((string) $race['login']);
        $twee = lock_user_by_login((string) $race['enemy']);

        if ($een === null || $twee === null
            || $een['status'] !== 'levend' || $twee['status'] !== 'levend') {
            throw new SpelFout('Een van jullie is niet meer beschikbaar.');
        }
        if ($een['stad'] !== $race['stad'] || $twee['stad'] !== $race['stad']) {
            throw new SpelFout('Jullie moeten allebei in ' . $race['stad'] . ' zijn.');
        }

        $auto1 = q_row('SELECT * FROM `garage` WHERE `id` = ? FOR UPDATE', [$race['id1']]);
        $auto2 = q_row('SELECT * FROM `garage` WHERE `id` = ? FOR UPDATE', [$race['id2']]);

        if ($auto1 === null || $auto2 === null) {
            throw new SpelFout('Een van de wagens bestaat niet meer.');
        }
        if ($auto1['stad'] !== $race['stad'] || $auto2['stad'] !== $race['stad']) {
            throw new SpelFout('Een van de wagens staat niet in ' . $race['stad'] . '.');
        }

        // --- De race zelf ---
        $punten1 = punten($auto1, $een);
        $punten2 = punten($auto2, $twee);

        $winnaar   = $punten1 >= $punten2 ? $een : $twee;
        $verliezer = $punten1 >= $punten2 ? $twee : $een;
        $prijs     = $punten1 >= $punten2 ? $auto2 : $auto1;

        // --- Schade, met een bovengrens ---
        $nieuw1 = schade_toepassen($auto1);
        $nieuw2 = schade_toepassen($auto2);

        // De wagen van de verliezer gaat naar de winnaar.
        q('UPDATE `garage` SET `login` = ? WHERE `id` = ?', [$winnaar['login'], $prijs['id']]);

        foreach ([$een, $twee] as $speler) {
            q('UPDATE `users` SET `xp` = `xp` + 1, `nrofrace` = `nrofrace` + 1 WHERE `id` = ?',
                [$speler['id']]);
        }

        q('DELETE FROM `autorace` WHERE `id` = ?', [$race['id']]);

        $verslag = 'Na de race: jouw ' . $auto1['naam'] . ' heeft ' . $nieuw1['damage']
                 . '% schade en is nog ' . money($nieuw1['waarde']) . ' waard. De '
                 . $auto2['naam'] . ' van ' . $twee['login'] . ' heeft ' . $nieuw2['damage']
                 . '% schade en is nog ' . money($nieuw2['waarde']) . ' waard.';

        // De tegenstander krijgt nu wel bericht; in de oude versie ging dat naar
        // de letterlijke tekst 'enemy->login'.
        $anderNaam = $winnaar['login'] === $een['login'] ? $twee['login'] : $een['login'];
        notify((string) $anderNaam, 'Autorace',
            $winnaar['login'] . ' heeft de race gewonnen en krijgt de ' . $prijs['naam'] . '.');

        log_action((string) $winnaar['login'], 'autorace',
            'Race gewonnen, wagen: ' . $prijs['naam'], (int) $prijs['waarde'],
            (string) $verliezer['login']);

        return ($winnaar['login'] === $user['login']
            ? 'Je hebt de race gewonnen. Je krijgt de ' . $prijs['naam'] . '.'
            : 'Je hebt verloren van ' . $twee['login'] . '. Je bent je ' . $prijs['naam'] . ' kwijt.')
            . "\n" . $verslag;
    });
}

/** Racepunten: waarde van de wagen, schade, gezondheid en een dosis geluk. */
function punten(array $auto, array $speler): int
{
    return (int) floor((int) $auto['waarde'] / 1000)
         + random_int(1, 10)
         + (100 - (int) $auto['damage'])
         + (int) $speler['health'];
}

/**
 * Werk de schade en de waarde van een wagen bij na de race.
 *
 * @return array{damage:int, waarde:int}
 */
function schade_toepassen(array $auto): array
{
    // Bovengrens op 100: in de oude versie werd er gewoon opgeteld, waardoor
    // een wagen boven de 100% schade kon uitkomen.
    $schade = min(100, (int) $auto['damage'] + random_int(1, 25));

    $nieuwprijs = (int) q_val('SELECT `waarde` FROM `cars` WHERE `naam` = ?', [$auto['naam']], 0);
    $waarde     = max(500, (int) floor($nieuwprijs * (1 - $schade / 100)));

    q('UPDATE `garage` SET `damage` = ?, `waarde` = ? WHERE `id` = ?',
        [$schade, $waarde, $auto['id']]);

    return ['damage' => $schade, 'waarde' => $waarde];
}

// ==========================================================================

function toon_uitdaging(array $user): void
{
    $garage = q_all(
        'SELECT * FROM `garage` WHERE `login` = ? AND `stad` = ? AND `damage` <= ? AND `safe` = 0
      ORDER BY `waarde` DESC',
        [$user['login'], $user['stad'], RACE_MAXSCHADE]
    );

    echo '<p>Daag iemand uit voor een race in ' . e((string) $user['stad']) . '. '
       . '<strong>De winnaar krijgt de wagen van de verliezer.</strong> Beide wagens lopen schade op.</p>';

    if ($garage === []) {
        echo '<p>Je hebt geen bruikbare wagen in ' . e((string) $user['stad'])
           . '. Je hebt er een nodig met hoogstens ' . RACE_MAXSCHADE . '% schade, '
           . 'die niet in een safehouse staat.</p>';
        return;
    }

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="uitdagen">';
    echo '<div class="veldenraster">';
    echo '<label for="enemy">Tegenstander</label>';
    echo '<input id="enemy" name="enemy" maxlength="16" required>';
    echo '<label for="auto">Jouw wagen</label><select id="auto" name="auto" required>';
    foreach ($garage as $auto) {
        echo '<option value="' . (int) $auto['id'] . '">' . e((string) $auto['naam'])
           . ' - ' . (int) $auto['damage'] . '% schade - ' . money((int) $auto['waarde']) . '</option>';
    }
    echo '</select>';
    echo '<span></span>' . captcha_field();
    echo '<span></span><button type="submit">Uitdagen</button>';
    echo '</div></form>';
}

function toon_race(array $user, array $race): void
{
    $isUitdager = $race['login'] === $user['login'];
    $klaar      = (int) $race['ready2'] === 1;

    echo '<p>Race in <strong>' . e((string) $race['stad']) . '</strong> tussen '
       . speler_naam((string) $race['login']) . ' en ' . speler_naam((string) $race['enemy']) . '.</p>';

    $auto1 = q_row('SELECT * FROM `garage` WHERE `id` = ?', [$race['id1']]);
    $auto2 = q_row('SELECT * FROM `garage` WHERE `id` = ?', [$race['id2']]);

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Speler</th><th>Wagen</th><th class="getal">Schade</th>'
       . '<th class="getal">Waarde</th></tr></thead><tbody>';
    wagenrij((string) $race['login'], $auto1);
    wagenrij((string) $race['enemy'], $auto2);
    echo '</tbody></table></div>';

    if (!$isUitdager && !$klaar) {
        toon_wagenkeuze($user, (string) $race['stad']);
        echo '<form method="post" style="display:inline">' . csrf_field()
           . '<input type="hidden" name="actie" value="weiger">'
           . '<button type="submit">Uitdaging afwijzen</button></form> ';
    }

    if ($isUitdager) {
        if ($klaar) {
            echo '<form method="post" style="display:inline">' . csrf_field()
               . '<input type="hidden" name="actie" value="go">'
               . '<button type="submit" class="knop-nadruk" style="display:inline-block">Start de race</button></form> ';
        } else {
            echo '<p>Wachten tot ' . speler_naam((string) $race['enemy']) . ' een wagen kiest.</p>';
        }
    }

    echo '<form method="post" style="display:inline">' . csrf_field()
       . '<input type="hidden" name="actie" value="annuleer">'
       . '<button type="submit">Annuleren</button></form>';
}

function wagenrij(string $speler, ?array $auto): void
{
    echo '<tr><td>' . speler_naam($speler) . '</td>';

    if ($auto === null) {
        echo '<td colspan="3"><em>nog geen wagen gekozen</em></td></tr>';
        return;
    }

    echo '<td>' . e((string) $auto['naam']) . '</td>'
       . '<td class="getal">' . (int) $auto['damage'] . '%</td>'
       . '<td class="getal">' . money((int) $auto['waarde']) . '</td></tr>';
}

function toon_wagenkeuze(array $user, string $stad): void
{
    $garage = q_all(
        'SELECT * FROM `garage` WHERE `login` = ? AND `stad` = ? AND `damage` <= ? AND `safe` = 0
      ORDER BY `waarde` DESC',
        [$user['login'], $stad, RACE_MAXSCHADE]
    );

    echo '<h3>Kies je wagen</h3>';

    if ($garage === []) {
        echo '<p>Je hebt geen bruikbare wagen in ' . e($stad) . '.</p>';
        return;
    }

    echo '<p>Verlies je, dan ben je deze wagen kwijt.</p>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="wagen">';
    echo '<div class="veldenraster">';
    echo '<label for="auto">Wagen</label><select id="auto" name="auto" required>';
    foreach ($garage as $auto) {
        echo '<option value="' . (int) $auto['id'] . '">' . e((string) $auto['naam'])
           . ' - ' . (int) $auto['damage'] . '% schade - ' . money((int) $auto['waarde']) . '</option>';
    }
    echo '</select>';
    echo '<span></span><button type="submit">Meedoen</button>';
    echo '</div></form>';
}
