<?php
/**
 * Spelregels die op veel plaatsen nodig zijn: rangen, wachttijden en de
 * gevangenis. Vervangt de oude rangen.php, tijden.php en de losse blokken
 * die in config.php stonden.
 */

declare(strict_types=1);

defined('BV_INC') || exit;

// --- Rangen ---------------------------------------------------------------

/**
 * De rangladder: [minimale xp, naam, naam voor vrouwelijke spelers].
 * De bovengrens van een rang is de ondergrens van de volgende.
 */
function rank_ladder(): array
{
    return [
        [0,     'Empty-Suit',  null],
        [10,    'Deliveryboy', 'Delivery Girl'],
        [20,    'Picciotto',   null],
        [50,    'Shoplifter',  null],
        [150,   'Pickpocket',  null],
        [500,   'Thief',       null],
        [1000,  'Associate',   null],
        [2000,  'Mobster',     null],
        [3000,  'Soldier',     null],
        [4500,  'Swindler',    null],
        [6000,  'Assassin',    null],
        [8000,  'Local Chief', null],
        [11000, 'Chief',       null],
        [15000, 'Bruglione',   null],
        [20000, 'Godfather',   'First Lady'],
    ];
}

/** Naam van de rang die bij dit aantal ervaringspunten hoort. */
function rank_name(int $xp, string $gender = 'Man'): string
{
    $ladder = rank_ladder();
    $match  = $ladder[0];

    foreach ($ladder as $step) {
        if ($xp >= $step[0]) {
            $match = $step;
        }
    }

    return ($gender === 'Vrouw' && $match[2] !== null) ? $match[2] : $match[1];
}

/**
 * Voortgang binnen de huidige rang, in hele procenten (0-100).
 * De hoogste rang geeft altijd 100 terug.
 */
function rank_progress(int $xp): int
{
    $ladder = rank_ladder();
    $last   = count($ladder) - 1;

    for ($i = $last; $i >= 0; $i--) {
        if ($xp >= $ladder[$i][0]) {
            if ($i === $last) {
                return 100;
            }
            $from = $ladder[$i][0];
            $to   = $ladder[$i + 1][0];
            return (int) max(0, min(100, floor(($xp - $from) / ($to - $from) * 100)));
        }
    }
    return 0;
}

/**
 * Positie op de rangladder, 1 (Empty-Suit) tot en met 15 (Godfather).
 * Wordt gebruikt om te bepalen hoeveel rangen je omhoog mag moorden.
 */
function rank_index(int $xp): int
{
    $index = 1;
    foreach (rank_ladder() as $i => $step) {
        if ($xp >= $step[0]) {
            $index = $i + 1;
        }
    }
    return $index;
}

// --- Rolkleur van gebruikersnamen -------------------------------------------

/**
 * Niveau en premiumstatus van elke speler, één keer per verzoek geladen.
 * Zo kost het inkleuren van een lijst met tientallen namen (forum,
 * ledenlijst, topspelers) geen aparte query per naam.
 *
 * @return array<string,array{level:int,premium:bool}>
 */
function speler_rollen(): array
{
    static $rollen = null;

    if ($rollen === null) {
        $rollen = [];
        foreach (q_all('SELECT `login`, `level`, `premium_tot` FROM `users`') as $rij) {
            $rollen[(string) $rij['login']] = [
                'level'   => (int) $rij['level'],
                'premium' => $rij['premium_tot'] !== null
                    && strtotime((string) $rij['premium_tot']) > time(),
            ];
        }
    }

    return $rollen;
}

/** Alle gebande logins, één keer per verzoek geladen. */
function gebande_logins(): array
{
    static $logins = null;

    if ($logins === null) {
        $logins = array_column(
            q_all("SELECT DISTINCT `login` FROM `bans` WHERE `login` <> ''"),
            'login'
        );
    }

    return $logins;
}

/**
 * CSS-klasse voor de kleur van een gebruikersnaam, op basis van rol,
 * premium en ban. Een gewone speler zonder rol, premium of ban krijgt ''.
 * Een ban overschrijft rol en premium; een rol overschrijft premium.
 */
function speler_rol_klasse(string $login): string
{
    if (in_array($login, gebande_logins(), true)) {
        return 'naam-gebanned';
    }

    $rol = speler_rollen()[$login] ?? null;

    if ($rol === null) {
        return '';
    }
    if ($rol['level'] >= LEVEL_ADMIN) {
        return 'naam-staf';
    }
    if ($rol['level'] >= LEVEL_MODERATOR) {
        return 'naam-moderator';
    }
    if ($rol['premium']) {
        return 'naam-premium';
    }

    return '';
}

/**
 * Kant-en-klaar `class="..."`-attribuut voor een link of ander element om een
 * gebruikersnaam heen, of '' als er niets bijzonders is. Scheelt een losse
 * lege `class=""` op elke plek die dit gebruikt.
 */
function speler_rol_attr(string $login): string
{
    $klasse = speler_rol_klasse($login);

    return $klasse === '' ? '' : ' class="' . e($klasse) . '"';
}

/**
 * Een gebruikersnaam, veilig ge-escaped en in de kleur van zijn rol.
 * Voor plekken die geen link om de naam heen bouwen; bouwt de pagina wel
 * een link, gebruik dan `speler_rol_attr()` op die `<a>` in plaats van
 * deze functie.
 */
function speler_naam(string $login): string
{
    $klasse = speler_rol_klasse($login);

    return $klasse === ''
        ? e($login)
        : '<span class="' . e($klasse) . '">' . e($login) . '</span>';
}

// --- Wachttijden ----------------------------------------------------------

/**
 * Afkoeltijden in seconden. Pas hier de balans van het spel aan; alle
 * modules lezen dezelfde waarden.
 */
function cooldowns(): array
{
    return [
        'crime'    => 60,
        'auto'     => 120,
        'bank'     => 300,
        'route'    => 3600,
        'kill'     => 3600,
        'slaap'    => 300,
        'fitness'  => 120,
        'schieten' => 60,
    ];
}

/** Unix-tijdstip waarop een afkoeltijd afloopt, vanaf nu. */
function cooldown_until(string $name): int
{
    return time() + (cooldowns()[$name] ?? 60);
}

/**
 * Resterende seconden van een afkoeltijd.
 * $timestamp is een van de `*_ts` velden uit current_user().
 */
function cooldown_left(?int $timestamp): int
{
    return max(0, (int) $timestamp - time());
}

// --- Gevangenis ------------------------------------------------------------

/**
 * Straftijd en boete bij arrestatie, oplopend met ervaring.
 *
 * @return array{seconden:int, boete:int}
 */
function jail_sentence(int $xp): array
{
    $ladder = [
        [10,    20,  250],
        [20,    40,  500],
        [50,    60,  750],
        [150,   90,  1000],
        [500,   120, 5000],
        [1000,  180, 7500],
        [2000,  190, 12500],
        [3000,  230, 25000],
        [4500,  270, 50000],
        [6000,  320, 60000],
        [8000,  370, 75000],
        [11000, 420, 85000],
        [15000, 480, 100000],
        [20000, 540, 125000],
    ];

    foreach ($ladder as [$maxXp, $seconden, $boete]) {
        if ($xp < $maxXp) {
            return ['seconden' => $seconden, 'boete' => $boete];
        }
    }
    return ['seconden' => 600, 'boete' => 150000];
}

/** Zet een speler vast. */
function jail_put(string $login, int $xp, string $stad, string $famillie = ''): void
{
    $s = jail_sentence($xp);

    // `jail`.`login` is uniek. Een verlopen cel wordt pas door de crontaak
    // opgeruimd, dus tot die tijd zou een kale INSERT vastlopen op een
    // dubbele sleutel. `bo` gaat terug naar nul: een nieuwe straf, een nieuwe
    // reeks uitbraakpogingen.
    q(
        'INSERT INTO `jail` (`login`, `boete`, `stad`, `famillie`, `time`, `bo`)
              VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), 0)
         ON DUPLICATE KEY UPDATE
              `boete`    = VALUES(`boete`),
              `stad`     = VALUES(`stad`),
              `famillie` = VALUES(`famillie`),
              `time`     = VALUES(`time`),
              `bo`       = 0',
        [$login, $s['boete'], $stad, $famillie, $s['seconden']]
    );
}

/**
 * Zit deze speler vast? Geeft null terug als hij vrij is, anders de cel
 * met een extra veld `resterend` (seconden).
 */
function jail_status(string $login): ?array
{
    $cel = q_row(
        'SELECT *, UNIX_TIMESTAMP(`time`) - UNIX_TIMESTAMP(NOW()) AS `resterend`
           FROM `jail` WHERE `login` = ? LIMIT 1',
        [$login]
    );

    if ($cel === null || (int) $cel['resterend'] <= 0) {
        return null;
    }

    $cel['resterend'] = (int) $cel['resterend'];
    return $cel;
}

/**
 * Stuur door naar de gevangenispagina als de speler vastzit.
 * Roep dit aan op elke pagina waar je iets mag ondernemen.
 */
function block_if_jailed(): void
{
    $user = current_user();
    if ($user !== null && jail_status($user['login']) !== null) {
        redirect('jisin.php');
    }
}

// --- Geld en spelers vergrendelen -------------------------------------------

/**
 * Haal een speler op en vergrendel de rij tot het einde van de transactie.
 *
 * Gebruik dit vóór elke controle op saldo, kogels of voorraad. Zonder deze
 * vergrendeling kunnen twee gelijktijdige verzoeken allebei "genoeg geld"
 * zien en allebei afboeken — de klassieke manier om geld te verdubbelen.
 *
 *     db_transaction(function () use ($id, $bedrag) {
 *         $speler = lock_user($id);
 *         if ($speler['zak'] < $bedrag) { throw new SpelFout('Te weinig geld.'); }
 *         q('UPDATE `users` SET `zak` = `zak` - ? WHERE `id` = ?', [$bedrag, $id]);
 *     });
 */
function lock_user(int $id): ?array
{
    return q_row('SELECT * FROM `users` WHERE `id` = ? FOR UPDATE', [$id]);
}

/** Zelfde, maar op naam. */
function lock_user_by_login(string $login): ?array
{
    return q_row('SELECT * FROM `users` WHERE `login` = ? FOR UPDATE', [$login]);
}

/**
 * Boek geld af, maar alleen als het er is. De voorwaarde zit in de query zelf,
 * zodat er geen gaatje zit tussen controleren en afboeken.
 *
 * @return bool False als het saldo niet toereikend was.
 */
function afboeken(int $userId, int $bedrag, string $veld = 'zak'): bool
{
    if ($bedrag < 0 || !in_array($veld, ['zak', 'bank', 'kogels'], true)) {
        return false;
    }
    return q_count(
        "UPDATE `users` SET `{$veld}` = `{$veld}` - ? WHERE `id` = ? AND `{$veld}` >= ?",
        [$bedrag, $userId, $bedrag]
    ) === 1;
}

/** Geld of kogels bijschrijven. */
function bijschrijven(int $userId, int $bedrag, string $veld = 'zak'): void
{
    if ($bedrag < 0 || !in_array($veld, ['zak', 'bank', 'kogels'], true)) {
        return;
    }
    q("UPDATE `users` SET `{$veld}` = `{$veld}` + ? WHERE `id` = ?", [$bedrag, $userId]);
}

// --- Instellingen die een beheerder in het spel kan omzetten ---------------

/**
 * Lees een instelling uit de tabel `instellingen`.
 *
 * Anders dan config() in inc/config.php gaat dit over keuzes die een beheerder
 * tijdens het spelen wil kunnen omzetten. De hele tabel wordt één keer per
 * verzoek gelezen; het zijn er een handvol.
 */
function instelling(string $naam, string $standaard = ''): string
{
    static $alles = null;

    if ($alles === null) {
        $alles = [];

        // Bij een installatie die nog van vóór deze tabel komt, valt alles
        // terug op de standaardwaarden in plaats van de pagina te breken.
        if (db_table_exists('instellingen')) {
            foreach (q_all('SELECT `naam`, `waarde` FROM `instellingen`') as $rij) {
                $alles[(string) $rij['naam']] = (string) $rij['waarde'];
            }
        }
    }

    return $alles[$naam] ?? $standaard;
}

/** Zet een instelling. Geeft de nieuwe waarde terug. */
function instelling_zetten(string $naam, string $waarde): string
{
    q(
        'INSERT INTO `instellingen` (`naam`, `waarde`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `waarde` = VALUES(`waarde`)',
        [$naam, $waarde]
    );

    return $waarde;
}

// --- Opnieuw beginnen na de dood ------------------------------------------

/**
 * Zet een omgelegd account terug op nul en maak het weer speelbaar.
 *
 * Je hoeft na een moord dus geen nieuw account aan te maken: hetzelfde account
 * begint opnieuw. Dat is ook de reden dat er bij registratie maar één account
 * per IP-adres en per e-mailadres mag bestaan — er is geen enkele reden meer
 * om een tweede aan te maken.
 *
 * Wat blijft staan:
 *  - inloggegevens, e-mailadres, geslacht en rechtenniveau
 *  - profieltekst en profielplaatje
 *  - lopende donaties; daar is voor betaald en die horen niet bij de voortgang
 *  - `gestorven`, de teller die juist ophoogt
 *
 * Al het overige gaat terug naar de beginstand van een nieuwe speler.
 */
function speler_herstarten(int $userId): string
{
    return db_transaction(static function () use ($userId): string {
        $speler = lock_user($userId);

        if ($speler === null) {
            throw new SpelFout('Dit account bestaat niet meer.');
        }
        if ($speler['status'] === 'levend') {
            throw new SpelFout('Je leeft nog.');
        }

        $stad = random_city();

        q(
            "UPDATE `users` SET
                `status`    = 'levend',
                `health`    = 100,
                `energie`   = 99.9,
                `stad`      = ?,
                `zak`       = ?,
                `bank`      = 0,
                `banktime`  = 0,
                `xp`        = 0,
                `se`        = 0,
                `respect`   = 0,
                `rp`        = 0,
                `kogels`    = 0,
                `wapon`     = 0,
                `defence`   = 0,
                `trans`     = 0,
                `guard`     = 0,
                `drugs`     = 0,
                `drank`     = 0,
                `vet`       = 0,
                `sl`        = 0,
                `bf`        = 0,
                `bo`        = 0,
                `famillie`  = '',
                `famrang`   = 0,
                `famcapo`   = '',
                `laatste_rang` = 0,
                `testament` = '',
                `huwelijk`  = '',
                `rijbewijs` = 0,
                `rijvord`   = 0,
                `lessen`    = 0,
                `rijbewijstijd` = NULL,
                `nrofcrime` = 0,
                `nrofcar`   = 0,
                `nrofroute` = 0,
                `nrofoc`    = 0,
                `nrofrace`  = 0,
                `nrofkill`  = 0,
                `ac`        = NULL,
                `bc`        = NULL,
                `crime`     = NULL,
                `kc`        = NULL,
                `pc`        = NULL,
                `safe`      = NULL,
                `slaap`     = NULL,
                `transport` = NULL,
                `drugst`    = NULL,
                `drankt`    = NULL,
                -- Beginnersbescherming loopt vanaf `start`, dus die gaat mee
                -- terug: je bent weer even onaantastbaar als een nieuwe speler.
                `start`     = NOW(),
                `herstart`  = NOW(),
                `gestorven` = `gestorven` + 1
              WHERE `id` = ?",
            [$stad, (int) config('game.start_money', 1000), $userId]
        );

        // Resten van het vorige leven. Wat geërfd moest worden is al bij het
        // overlijden verplaatst; dit is wat er daarna nog naar dit account wees.
        $naam = (string) $speler['login'];

        foreach (['garage', 'hitlist', 'invite', 'loterij', 'kras', 'jail', 'huizen'] as $tabel) {
            q("DELETE FROM `{$tabel}` WHERE `login` = ?", [$naam]);
        }
        q('DELETE FROM `friends` WHERE `login` = ? OR `friend` = ?', [$naam, $naam]);
        q("UPDATE `casino` SET `owner` = '', `winst` = 0 WHERE `owner` = ?", [$naam]);

        log_action($naam, 'herstart',
            'Opnieuw begonnen na overlijden nummer ' . ((int) $speler['gestorven'] + 1));

        return $stad;
    });
}

/** Stuur een systeembericht naar een speler. */
function notify(string $naar, string $onderwerp, string $tekst): void
{
    q(
        'INSERT INTO `messages` (`time`, `from`, `to`, `subject`, `message`)
              VALUES (NOW(), ?, ?, ?, ?)',
        ['Notificatie', $naar, $onderwerp, $tekst]
    );
}

/** Leg een handeling vast in het logboek, voor de beheerders. */
function log_action(string $login, string $area, string $com, int $code = 0, string $person = ''): void
{
    q(
        'INSERT INTO `logs` (`time`, `login`, `person`, `code`, `area`, `com`)
              VALUES (NOW(), ?, ?, ?, ?, ?)',
        [$login, $person, $code, $area, $com]
    );
}

/**
 * Fout die de speler mag zien: "je hebt niet genoeg geld", "die persoon
 * bestaat niet". Draait een transactie netjes terug.
 */
class SpelFout extends RuntimeException
{
}

// --- Steden ----------------------------------------------------------------

/** Lijst met steden uit de configuratie. */
function cities(): array
{
    return (array) config('game.cities', []);
}

/** Bestaat deze stad? Gebruik dit om invoer te valideren. */
function is_city(string $name): bool
{
    return in_array($name, cities(), true);
}

/** Willekeurige startstad voor een nieuwe speler. */
function random_city(): string
{
    $list = cities();
    return $list[array_rand($list)];
}

// --- Prijzen die op meer dan één plek nodig zijn ----------------------------
//
// Deze stonden in shop.php en loterij.php zelf. help.php noemt ze ook, en die
// kan die bestanden niet inladen zonder ze uit te voeren. Hier staan ze één
// keer, zodat de uitleg nooit uit de pas kan lopen met het spel.

const HUIS_KOOPPRIJS    = 850_000;
const HUIS_VERKOOPPRIJS = 750_000;
const LOT_PRIJS         = 10_000;
const LOT_MAX           = 100;

// --- Huizen ----------------------------------------------------------------
//
// Huisbezit stond als één kolom per stad in `users`. Daardoor kostte een stad
// toevoegen een schemawijziging, en brak de registratie zodra een speler in een
// stad terechtkwam waarvoor die kolom niet bestond. Het staat nu in `huizen`.

/** Heeft deze speler een huis in deze stad? */
function heeft_huis(string $login, string $stad): bool
{
    return (int) q_val(
        'SELECT COUNT(*) FROM `huizen` WHERE `login` = ? AND `stad` = ?',
        [$login, $stad],
        0
    ) > 0;
}

/**
 * Alle steden waar deze speler een huis heeft.
 *
 * @return string[]
 */
function huizen_van(string $login): array
{
    return array_column(
        q_all('SELECT `stad` FROM `huizen` WHERE `login` = ? ORDER BY `stad`', [$login]),
        'stad'
    );
}

/**
 * Geef een speler een huis. Heeft hij er al een, dan verandert er niets.
 *
 * @return bool True als er werkelijk een huis bij kwam.
 */
function huis_geven(string $login, string $stad): bool
{
    return q_count(
        'INSERT IGNORE INTO `huizen` (`login`, `stad`) VALUES (?, ?)',
        [$login, $stad]
    ) === 1;
}

/**
 * Neem een huis weg.
 *
 * @return bool True als er werkelijk een huis weg was.
 */
function huis_afnemen(string $login, string $stad): bool
{
    return q_count(
        'DELETE FROM `huizen` WHERE `login` = ? AND `stad` = ?',
        [$login, $stad]
    ) === 1;
}

// --- Forum -----------------------------------------------------------------

/**
 * De vaste forumcategorieën. Alleen deze sleutels komen in de database.
 * Staat hier omdat zowel forum.php als adm-forum.php ze nodig heeft.
 */
function forum_categorieen(): array
{
    return [
        'algemeen' => 'Algemeen',
        'vragen'   => 'Vragen',
        'tip'      => 'Tips',
        'bug'      => 'Bugs',
        'oc'       => 'Organised Crime',
        'race'     => 'Races',
        'familie'  => 'Families',
        'varia'    => 'Varia',
        'rip'      => 'In memoriam',
    ];
}

/** In deze categorie plaatst alleen het spel zelf berichten. */
function forum_alleen_lezen(string $type): bool
{
    return $type === 'rip';
}
