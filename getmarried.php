<?php
/**
 * Trouwen: een aanzoek doen, aannemen, voltrekken en scheiden.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - `getmarried.php?divorce=1` was een GET-link, en stond bovendien als
 *    allereerste controle in het bestand. Een afbeelding met die URL in een
 *    bericht liet de lezer op slag scheiden. Alles gaat nu via POST met
 *    CSRF-token, met een bevestigingsstap voor de scheiding.
 *  - De trouwkosten werden bij het aanzoek geïnd, maar bij een afwijzing of
 *    annulering nooit terugbetaald. Wie een blauwtje liep was zijn geld kwijt.
 *    Het bedrag wordt nu teruggestort.
 *  - Aannemen, weigeren, annuleren en voltrekken liepen allemaal via GET.
 *  - Geen transactie: het aanzoek werd betaald en aangemaakt in losse queries.
 *  - `ready1` in de tabel `trouwen` werd nergens op 1 gezet en deed dus niets.
 *    De aanvrager geldt nu vanzelf als akkoord.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

const HUWELIJK_PRIJS = 250_000;
const HUWELIJK_XP    = 500;      // minimaal Thief

$user = require_login();

if (is_dead()) {
    redirect('rip.php');
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

$aanzoek = mijn_aanzoek((string) $user['login']);

layout_header('Trouwen');

if ($melding !== null) {
    notice(e($melding), $type);
}

if (($user['huwelijk'] ?? '') !== '') {
    toon_getrouwd($user);
} elseif ($aanzoek !== null) {
    toon_aanzoek($user, $aanzoek);
} else {
    toon_nieuw($user);
}

layout_footer();

// ==========================================================================

/** Het aanzoek waar deze speler bij betrokken is, of null. */
function mijn_aanzoek(string $login, bool $vergrendel = false): ?array
{
    // Elke plaatshouder een eigen naam: met echte prepared statements mag
    // dezelfde naam niet twee keer voorkomen.
    $sql = 'SELECT * FROM `trouwen` WHERE `login` = :a OR `partner` = :b LIMIT 1';
    if ($vergrendel) {
        $sql .= ' FOR UPDATE';
    }
    return q_row($sql, ['a' => $login, 'b' => $login]);
}

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'aanzoek'   => aanzoek_doen($user, post('partner')),
        'jawoord'   => jawoord($user),
        'nee'       => afwijzen($user),
        'annuleer'  => annuleren($user),
        'voltrek'   => voltrekken($user),
        'scheiden'  => scheiden($user),
        default     => throw new SpelFout('Onbekende handeling.'),
    };
}

/** @throws SpelFout */
function aanzoek_doen(array $user, string $naam): string
{
    $naam = trim($naam);

    if ($naam === '') {
        throw new SpelFout('Vul in wie je ten huwelijk wilt vragen.');
    }
    if (strcasecmp($naam, (string) $user['login']) === 0) {
        throw new SpelFout('Je kunt niet met jezelf trouwen.');
    }
    if ((int) $user['xp'] < HUWELIJK_XP) {
        throw new SpelFout('Je moet minstens de rang Thief hebben om te trouwen.');
    }

    return db_transaction(static function () use ($user, $naam): string {
        $speler = lock_user((int) $user['id']);

        if ($speler['huwelijk'] !== '') {
            throw new SpelFout('Je bent al getrouwd.');
        }
        if (mijn_aanzoek((string) $speler['login'], true) !== null) {
            throw new SpelFout('Er loopt al een aanzoek.');
        }

        $partner = lock_user_by_login($naam);

        if ($partner === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }
        if ($partner['status'] !== 'levend') {
            throw new SpelFout($partner['login'] . ' is dood.');
        }
        if ($partner['huwelijk'] !== '') {
            throw new SpelFout($partner['login'] . ' is al getrouwd.');
        }
        if ((int) $partner['xp'] < HUWELIJK_XP) {
            throw new SpelFout($partner['login'] . ' is nog geen Thief.');
        }
        if (mijn_aanzoek((string) $partner['login'], true) !== null) {
            throw new SpelFout($partner['login'] . ' heeft al een aanzoek lopen.');
        }
        if (!afboeken((int) $speler['id'], HUWELIJK_PRIJS, 'zak')) {
            throw new SpelFout('Een huwelijk kost ' . money(HUWELIJK_PRIJS) . '.');
        }

        q('INSERT INTO `trouwen` (`login`, `partner`, `stad`, `ready1`, `ready2`) VALUES (?, ?, ?, 1, 0)',
            [$speler['login'], $partner['login'], $speler['stad']]);

        notify((string) $partner['login'], 'Huwelijksaanzoek',
            $speler['login'] . ' vraagt je ten huwelijk in ' . $speler['stad']
            . '. Ga naar Trouwen om te antwoorden.');

        return 'Je aanzoek aan ' . $partner['login'] . ' is verstuurd. '
             . 'Het kostte ' . money(HUWELIJK_PRIJS) . '; dat krijg je terug als het niet doorgaat.';
    });
}

/** @throws SpelFout */
function jawoord(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $aanzoek = mijn_aanzoek((string) $user['login'], true);

        if ($aanzoek === null || $aanzoek['partner'] !== $user['login']) {
            throw new SpelFout('Je hebt geen aanzoek gekregen.');
        }
        if ((int) $aanzoek['ready2'] === 1) {
            throw new SpelFout('Je hebt al ja gezegd.');
        }

        q('UPDATE `trouwen` SET `ready2` = 1 WHERE `id` = ?', [$aanzoek['id']]);

        notify((string) $aanzoek['login'], 'Huwelijksaanzoek',
            $user['login'] . ' heeft ja gezegd. Kom samen naar ' . $aanzoek['stad']
            . ' om het huwelijk te voltrekken.');

        return 'Je hebt ja gezegd. Zorg dat jullie allebei in ' . $aanzoek['stad'] . ' zijn.';
    });
}

/** @throws SpelFout */
function afwijzen(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $aanzoek = mijn_aanzoek((string) $user['login'], true);

        if ($aanzoek === null || $aanzoek['partner'] !== $user['login']) {
            throw new SpelFout('Je hebt geen aanzoek gekregen.');
        }

        terugbetalen($aanzoek);
        q('DELETE FROM `trouwen` WHERE `id` = ?', [$aanzoek['id']]);

        notify((string) $aanzoek['login'], 'Huwelijksaanzoek',
            $user['login'] . ' heeft nee gezegd. Je inleg van ' . money(HUWELIJK_PRIJS)
            . ' is teruggestort.');

        return 'Je hebt het aanzoek afgewezen.';
    });
}

/** @throws SpelFout */
function annuleren(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $aanzoek = mijn_aanzoek((string) $user['login'], true);

        if ($aanzoek === null) {
            throw new SpelFout('Er loopt geen aanzoek.');
        }

        terugbetalen($aanzoek);

        $ander = $aanzoek['login'] === $user['login'] ? $aanzoek['partner'] : $aanzoek['login'];
        q('DELETE FROM `trouwen` WHERE `id` = ?', [$aanzoek['id']]);

        notify((string) $ander, 'Huwelijksaanzoek',
            $user['login'] . ' heeft het aanzoek geannuleerd.');

        return 'Het aanzoek is geannuleerd.';
    });
}

/**
 * De inleg terug naar de aanvrager. In de oude versie gebeurde dit niet: wie
 * een blauwtje liep was zijn geld kwijt.
 */
function terugbetalen(array $aanzoek): void
{
    $aanvrager = lock_user_by_login((string) $aanzoek['login']);

    if ($aanvrager !== null) {
        bijschrijven((int) $aanvrager['id'], HUWELIJK_PRIJS, 'zak');
    }
}

/** @throws SpelFout */
function voltrekken(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $aanzoek = mijn_aanzoek((string) $user['login'], true);

        if ($aanzoek === null) {
            throw new SpelFout('Er loopt geen aanzoek.');
        }
        if ((int) $aanzoek['ready2'] !== 1) {
            throw new SpelFout($aanzoek['partner'] . ' heeft nog geen ja gezegd.');
        }

        $een  = lock_user_by_login((string) $aanzoek['login']);
        $twee = lock_user_by_login((string) $aanzoek['partner']);

        if ($een === null || $twee === null) {
            throw new SpelFout('Een van jullie bestaat niet meer.');
        }
        if ($een['status'] !== 'levend' || $twee['status'] !== 'levend') {
            throw new SpelFout('Een van jullie is dood.');
        }
        if ($een['stad'] !== $aanzoek['stad'] || $twee['stad'] !== $aanzoek['stad']) {
            throw new SpelFout('Jullie moeten allebei in ' . $aanzoek['stad'] . ' zijn.');
        }

        q('UPDATE `users` SET `huwelijk` = ? WHERE `id` = ?', [$twee['login'], $een['id']]);
        q('UPDATE `users` SET `huwelijk` = ? WHERE `id` = ?', [$een['login'], $twee['id']]);
        q('DELETE FROM `trouwen` WHERE `id` = ?', [$aanzoek['id']]);

        $ander = $een['login'] === $user['login'] ? $twee['login'] : $een['login'];
        notify((string) $ander, 'Huwelijk',
            'Gefeliciteerd, jullie zijn getrouwd in ' . $aanzoek['stad'] . '.');

        return 'Gefeliciteerd, jullie zijn getrouwd in ' . $aanzoek['stad'] . '.';
    });
}

/** @throws SpelFout */
function scheiden(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $speler = lock_user((int) $user['id']);
        $partner = (string) $speler['huwelijk'];

        if ($partner === '') {
            throw new SpelFout('Je bent niet getrouwd.');
        }

        q("UPDATE `users` SET `huwelijk` = '' WHERE `id` = ?", [$speler['id']]);
        q("UPDATE `users` SET `huwelijk` = '' WHERE `login` = ?", [$partner]);

        notify($partner, 'Scheiding',
            $speler['login'] . ' heeft een scheiding aangevraagd. Jullie zijn gescheiden.');

        return 'Jullie zijn gescheiden.';
    });
}

// ==========================================================================

function toon_getrouwd(array $user): void
{
    panel_open('Je huwelijk');
    echo '<p>Je bent getrouwd met <strong><a' . speler_rol_attr((string) $user['huwelijk']) . ' href="'
       . e(url('user.php?x=' . rawurlencode((string) $user['huwelijk']))) . '">'
       . e((string) $user['huwelijk']) . '</a></strong>.</p>';

    if (post('actie') === 'scheiden_vragen') {
        echo '<p>Weet je zeker dat je wilt scheiden?</p>';
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="actie" value="scheiden">'
           . '<button type="submit">Ja, scheiden</button> '
           . '<a class="knop" href="' . e(url('getmarried.php')) . '">Nee, toch niet</a></form>';
    } else {
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="actie" value="scheiden_vragen">'
           . '<button type="submit">Scheiden</button></form>';
    }

    panel_close();
}

function toon_aanzoek(array $user, array $aanzoek): void
{
    $isAanvrager = $aanzoek['login'] === $user['login'];
    $ander       = $isAanvrager ? $aanzoek['partner'] : $aanzoek['login'];
    $jawoord     = (int) $aanzoek['ready2'] === 1;

    panel_open('Huwelijksaanzoek');

    if ($isAanvrager) {
        echo '<p>Je hebt <strong>' . speler_naam((string) $ander) . '</strong> ten huwelijk gevraagd in '
           . e((string) $aanzoek['stad']) . '.</p>';
        echo '<p>' . ($jawoord
            ? '<strong>' . speler_naam((string) $ander) . ' heeft ja gezegd.</strong> Zorg dat jullie allebei in '
              . e((string) $aanzoek['stad']) . ' zijn en voltrek het huwelijk.'
            : 'Wachten op antwoord.') . '</p>';
    } else {
        echo '<p><strong>' . speler_naam((string) $ander) . '</strong> vraagt je ten huwelijk in '
           . e((string) $aanzoek['stad']) . '.</p>';
    }

    if (!$isAanvrager && !$jawoord) {
        echo '<form method="post" style="display:inline">' . csrf_field()
           . '<input type="hidden" name="actie" value="jawoord">'
           . '<button type="submit">Ja, ik wil</button></form> ';
        echo '<form method="post" style="display:inline">' . csrf_field()
           . '<input type="hidden" name="actie" value="nee">'
           . '<button type="submit">Nee</button></form> ';
    }

    if ($jawoord) {
        echo '<form method="post" style="display:inline">' . csrf_field()
           . '<input type="hidden" name="actie" value="voltrek">'
           . '<button type="submit">Voltrek het huwelijk</button></form> ';
    }

    echo '<form method="post" style="display:inline">' . csrf_field()
       . '<input type="hidden" name="actie" value="annuleer">'
       . '<button type="submit">Annuleren</button></form>';

    panel_close();
}

function toon_nieuw(array $user): void
{
    panel_open('Trouwen');

    if ((int) $user['xp'] < HUWELIJK_XP) {
        echo '<p>Je moet minstens de rang Thief hebben om te trouwen. Je hebt nu '
           . num((int) $user['xp']) . ' van de ' . num(HUWELIJK_XP) . ' ervaringspunten.</p>';
        panel_close();
        return;
    }

    echo '<p>Heb je de man of vrouw van je leven gevonden? Een huwelijk kost '
       . money(HUWELIJK_PRIJS) . ', te betalen door de aanvrager. Gaat het niet door, '
       . 'dan krijg je dat bedrag terug.</p>';
    echo '<p>Jullie moeten allebei minstens de rang Thief hebben en samen in dezelfde stad '
       . 'zijn om het huwelijk te voltrekken.</p>';

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="aanzoek">';
    echo '<div class="veldenraster">';
    echo '<label for="partner">Wie vraag je?</label>';
    echo '<input id="partner" name="partner" maxlength="16" required>';
    echo '<span></span><button type="submit">Aanzoek doen</button>';
    echo '</div></form>';

    panel_close();
}
