# Klikmissies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Laat spelers de website promoten door op externe stemlinks ("klikmissies") te klikken, met een door de admin ingestelde cooldown en beloning (zak/bank/diamanten) per link, en met of zonder automatische callback-bevestiging.

**Architecture:** Eén nieuw inc-bestand (`inc/klikmissies.php`) met de kernlogica (beloning toekennen, cooldown bepalen), hergebruikt door drie nieuwe pagina's: een spelerspagina met twee stemstromen, een admin-CRUD-pagina, en een publiek callback-eindpunt. Alle geldmutaties lopen via de bestaande `bijschrijven()`/`diamanten_bijschrijven()` binnen `db_transaction()` + `lock_user_by_login()`.

**Tech Stack:** PHP 8.1+, MySQL/MariaDB (PDO, prepared statements zonder emulatie), geen frameworks, vanilla JS beperkt tot het al bestaande `data-tot`-aftelmechanisme.

**Spec:** `docs/superpowers/specs/2026-09-14-klikmissies-design.md`

## Global Constraints

- Geen frameworks, geen Composer, geen bouwstap — alleen PHP, MySQL en een handvol regels vanilla JavaScript.
- Eén bestand per pagina, geen router. Elke nieuwe pagina begint met `require __DIR__ . '/inc/bootstrap.php';`.
- Alles in het Nederlands: code, commentaar, commitberichten, foutmeldingen, kolomnamen.
- Nooit een variabele in een query — alleen plaatshouders. Benoemde plaatshouders (`:naam`) mogen niet herhaald worden binnen dezelfde query (`ATTR_EMULATE_PREPARES => false`).
- `csrf_check()` bovenaan elke POST-verwerking; nooit een handeling achter een gewone GET-link.
- Geld en diamanten veranderen alleen via `bijschrijven()` / `diamanten_bijschrijven()`, binnen `db_transaction()` met `lock_user_by_login()`.
- Alles wat een speler heeft ingetypt gaat door `e()` bij weergave.
- Na een POST altijd `redirect()`.
- PHP-bestanden nooit via PowerShell bewerken (BOM-probleem) — alleen via Write/Edit.
- Tests draaien via `tests/rook.php`, `tests/geld.php`, `tests/veiligheid.php`, `tests/opbouw.php`, `tests/adressen.php` tegen een draaiende server + database (zie `tests/LEESMIJ.md`).
- De tabellen `klikmissies` en `klikmissies_log` staan al in `install/schema.sql` en in de lokale testdatabase (dit werk is al gedaan, vóór dit plan). Dit plan bouwt alleen nog de code eromheen.

---

## Task 1: Productiemigratie voor bestaande installaties

`install/schema.sql` wordt alleen gelezen bij een nieuwe installatie. Een al draaiende (productie)database heeft een apart migratiebestand nodig, zoals `migratie-economie-2026-08-05.sql` dat al in de repo staat.

**Files:**
- Create: `migratie-klikmissies-2026-09-14.sql`

**Interfaces:**
- Consumes: niets (zuiver SQL, geen PHP).
- Produces: de tabellen `klikmissies` en `klikmissies_log`, identiek aan wat al in `install/schema.sql` staat en in de lokale testdatabase draait.

- [ ] **Step 1: Schrijf het migratiebestand**

```sql
-- Klikmissies (stemlinks met beloning en cooldown) — bijwerken van een
-- bestaande database
--
-- install/schema.sql wordt alleen gelezen bij een nieuwe installatie. Dit
-- bestand voegt de twee nieuwe tabellen toe aan een al draaiende database.
-- CREATE TABLE IF NOT EXISTS is veilig om opnieuw te draaien.
--
-- Uitvoeren via phpMyAdmin (of de mysql-CLI) tegen je productiedatabase.
-- Raakt geen bestaande tabel of speler.

CREATE TABLE IF NOT EXISTS `klikmissies` (
  `id`                 int unsigned NOT NULL AUTO_INCREMENT,
  `naam`               varchar(100) NOT NULL DEFAULT '',
  `omschrijving`       varchar(255) NOT NULL DEFAULT '',
  `url`                varchar(500) NOT NULL DEFAULT '', -- mag `{login}` bevatten
  `heeft_callback`     tinyint unsigned NOT NULL DEFAULT 0,
  `callback_geheim`    varchar(64) NOT NULL DEFAULT '',
  `wachttijd_klik`     int unsigned NOT NULL DEFAULT 20,    -- seconden; alleen zonder callback
  `cooldown_seconden`  int unsigned NOT NULL DEFAULT 86400,
  `beloning_zak`       bigint NOT NULL DEFAULT 0,
  `beloning_bank`      bigint NOT NULL DEFAULT 0,
  `beloning_diamanten` int unsigned NOT NULL DEFAULT 0,
  `actief`             tinyint unsigned NOT NULL DEFAULT 1,
  `volgorde`           int unsigned NOT NULL DEFAULT 0,
  `aangemaakt_op`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `actief` (`actief`, `volgorde`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `klikmissies_log` (
  `id`             int unsigned NOT NULL AUTO_INCREMENT,
  `klikmissie_id`  int unsigned NOT NULL,
  `login`          varchar(16) NOT NULL,
  `tijd`           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `methode`        enum('callback','zelf') NOT NULL,
  `ip`             varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `cooldown` (`klikmissie_id`, `login`, `tijd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Controle: bestaan de tabellen nu? ---------------------------------------

SHOW TABLES LIKE 'klikmissies%';
```

- [ ] **Step 2: Commit**

```bash
git add migratie-klikmissies-2026-09-14.sql
git commit -m "Migratiebestand toevoegen voor klikmissies-tabellen op bestaande installaties"
```

---

## Task 2: Kernfuncties en het callback-eindpunt

Dit is de kern van het systeem: de functie die een beloning toekent, en het publieke eindpunt dat externe stemsites aanroepen. Alle latere taken (adminpagina, spelerspagina) roepen deze functies aan.

**Files:**
- Create: `inc/klikmissies.php`
- Create: `klikmissies-callback.php`
- Modify: `inc/premium.php` (constante `ADS_OVERSLAAN`)
- Test: `tests/geld.php`
- Test: `tests/veiligheid.php`

**Interfaces:**
- Produces:
  - `klikmissies_actief(): array` — alle actieve klikmissies, op volgorde.
  - `klikmissie_url(array $missie, string $login): string` — de uitgaande URL met `{login}` vervangen.
  - `klikmissie_cooldown_tot(int $klikmissieId, int $cooldownSeconden, string $login): int` — unix-tijdstip waarop de cooldown afloopt, of 0.
  - `klikmissie_belonen(array $missie, string $login, string $methode, string $ip): void` — kent de beloning toe; gooit `SpelFout` bij een lopende cooldown of een onbekende speler.
- Consumes (bestaand): `q()`, `q_row()`, `q_all()`, `q_val()`, `db_transaction()`, `lock_user_by_login()`, `bijschrijven()`, `diamanten_bijschrijven()`, `client_ip()`, `int_input()`, `get()`, `SpelFout`.

### Stap 1: schrijf de falende test in `tests/geld.php`

- [ ] **Voeg dit blok toe vlak vóór `// --- Cron` in `tests/geld.php`:**

```php
// --- Klikmissies -------------------------------------------------------------

kop('klikmissies: callback beloont precies het ingestelde bedrag, en niet twee keer binnen de cooldown');

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");
$db->exec(
    "INSERT INTO klikmissies
        (naam, url, heeft_callback, callback_geheim, cooldown_seconden,
         beloning_zak, beloning_bank, beloning_diamanten, actief)
     VALUES ('Testlijst', 'http://127.0.0.1:1/stem?ref={login}', 1, 'geheimtoken123', 86400,
             5000, 2000, 3, 1)"
);
$missieId = (int) $db->lastInsertId();

$db->exec("UPDATE users SET zak=0, bank=0, diamanten=0 WHERE login='Speler'");

$r = haal('klikmissies-callback.php?id=' . $missieId . '&geheim=geheimtoken123&login=Speler');
$u = $db->query("SELECT zak, bank, diamanten FROM users WHERE login='Speler'")->fetch();

check('callback antwoordt OK', trim($r['body']) === 'OK', $r['body']);
check('zak precies 5.000 hoger', (int) $u['zak'] === 5000, 'zak ' . $u['zak']);
check('bank precies 2.000 hoger', (int) $u['bank'] === 2000, 'bank ' . $u['bank']);
check('diamanten precies 3 hoger', (int) $u['diamanten'] === 3, 'diamanten ' . $u['diamanten']);

$aantalLog = (int) $db->query(
    "SELECT COUNT(*) FROM klikmissies_log
      WHERE klikmissie_id={$missieId} AND login='Speler' AND methode='callback'"
)->fetchColumn();
check('er staat precies één logregel', $aantalLog === 1, (string) $aantalLog);

// Een tweede callback binnen de cooldown mag niets meer bijschrijven.
$r2 = haal('klikmissies-callback.php?id=' . $missieId . '&geheim=geheimtoken123&login=Speler');
$u2 = $db->query("SELECT zak, bank, diamanten FROM users WHERE login='Speler'")->fetch();

check('tweede callback binnen de cooldown wordt geweigerd',
    str_starts_with(trim($r2['body']), 'FOUT'), $r2['body']);
check('en schrijft niets extra bij', $u2 === $u, json_encode($u2));

kop('klikmissies: een verkeerd geheim of onbekende speler beloont niets');

$db->exec("UPDATE users SET zak=0, bank=0, diamanten=0 WHERE login='Speler'");
$db->exec("DELETE FROM klikmissies_log");

$r3 = haal('klikmissies-callback.php?id=' . $missieId . '&geheim=verkeerdtoken&login=Speler');
$u3 = $db->query("SELECT zak, bank, diamanten FROM users WHERE login='Speler'")->fetch();

check('verkeerd geheim wordt geweigerd', str_starts_with(trim($r3['body']), 'FOUT'), $r3['body']);
check('niets bijgeschreven bij een verkeerd geheim',
    (int) $u3['zak'] === 0 && (int) $u3['bank'] === 0 && (int) $u3['diamanten'] === 0, json_encode($u3));

$r4 = haal('klikmissies-callback.php?id=' . $missieId . '&geheim=geheimtoken123&login=Onbekendespeler');
check('onbekende speler wordt geweigerd', str_starts_with(trim($r4['body']), 'FOUT'), $r4['body']);

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");
```

- [ ] **Step 2: Run de test om te zien dat hij faalt**

Run: `php tests/geld.php`
Expected: FAIL — `klikmissies-callback.php` bestaat nog niet (HTTP 404), dus `trim($r['body']) === 'OK'` en de rest van de controles in dit blok slaan om.

### Stap 2: implementeer de kernfuncties

- [ ] **Step 3: Schrijf `inc/klikmissies.php`**

```php
<?php
/**
 * Klikmissies: stemlinks naar externe toplijsten, met een optionele
 * automatische callback, een afkoeltijd en een beloning in zak, bank en/of
 * diamanten.
 */

declare(strict_types=1);

defined('BV_INC') || exit;

/** Alle actieve klikmissies, in de volgorde die de admin heeft ingesteld. */
function klikmissies_actief(): array
{
    return q_all('SELECT * FROM `klikmissies` WHERE `actief` = 1 ORDER BY `volgorde`, `id`');
}

/** De uitgaande URL van een missie, met de speler zijn login erin verwerkt. */
function klikmissie_url(array $missie, string $login): string
{
    return str_replace('{login}', rawurlencode($login), (string) $missie['url']);
}

/**
 * Unix-tijdstip waarop de cooldown van deze speler voor deze missie afloopt,
 * of 0 als hij nu al mag meedoen.
 *
 * Dit is de weergave-check voor de pagina's — wel of niet de knop tonen. De
 * echte beveiliging tegen dubbel belonen zit in klikmissie_belonen().
 */
function klikmissie_cooldown_tot(int $klikmissieId, int $cooldownSeconden, string $login): int
{
    $laatst = q_val(
        'SELECT UNIX_TIMESTAMP(MAX(`tijd`)) FROM `klikmissies_log`
          WHERE `klikmissie_id` = ? AND `login` = ?',
        [$klikmissieId, $login]
    );

    if ($laatst === null) {
        return 0;
    }

    $tot = (int) $laatst + $cooldownSeconden;

    return $tot > time() ? $tot : 0;
}

/**
 * Ken de beloning van een missie toe aan een speler.
 *
 * De cooldown wordt hier, ná het vergrendelen van de spelersrij, nog een keer
 * gecontroleerd. Zonder die volgorde zouden twee gelijktijdige aanroepen voor
 * dezelfde speler/missie (een callback vlak na een zelf-bevestiging, of twee
 * callbacks vlak na elkaar) de eerder uitgelezen cooldown allebei nog geldig
 * kunnen vinden en dubbel belonen: de FOR UPDATE-lock op de spelersrij dwingt
 * af dat de tweede aanroep wacht tot de eerste klaar is, en dan de bijgewerkte
 * cooldown ziet.
 *
 * @throws SpelFout Als de speler niet bestaat of de cooldown nog loopt.
 */
function klikmissie_belonen(array $missie, string $login, string $methode, string $ip): void
{
    db_transaction(function () use ($missie, $login, $methode, $ip): void {
        $speler = lock_user_by_login($login);

        if ($speler === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }

        $klikmissieId = (int) $missie['id'];

        if (klikmissie_cooldown_tot($klikmissieId, (int) $missie['cooldown_seconden'], $login) > 0) {
            throw new SpelFout('Deze missie is nog in afkoeltijd.');
        }

        $userId = (int) $speler['id'];

        if ((int) $missie['beloning_zak'] > 0) {
            bijschrijven($userId, (int) $missie['beloning_zak'], 'zak');
        }
        if ((int) $missie['beloning_bank'] > 0) {
            bijschrijven($userId, (int) $missie['beloning_bank'], 'bank');
        }
        if ((int) $missie['beloning_diamanten'] > 0) {
            diamanten_bijschrijven($userId, (int) $missie['beloning_diamanten']);
        }

        q(
            'INSERT INTO `klikmissies_log` (`klikmissie_id`, `login`, `tijd`, `methode`, `ip`)
                  VALUES (?, ?, NOW(), ?, ?)',
            [$klikmissieId, $login, $methode, $ip]
        );
    });
}
```

- [ ] **Step 4: Voeg `klikmissies-callback.php` toe aan de uitzonderingslijst voor advertenties**

In `inc/premium.php`, in de constante `ADS_OVERSLAAN`:

```php
const ADS_OVERSLAAN = [
    'advertentie.php',   // anders kom je er nooit meer vanaf
    'img.php',           // het captchaplaatje
    'logout.php',
    'cron.php',
    'login.php',
    'register.php',
    'klikmissies-callback.php', // machine-naar-machine, mag nooit een advertentie tonen
];
```

Zonder dit zou een stemsite die zonder cookies aanroept, bij `ads_interval() === 1` naar `advertentie.php` doorgestuurd worden in plaats van het verwachte "OK"/"FOUT"-antwoord te krijgen.

- [ ] **Step 5: Schrijf `klikmissies-callback.php`**

```php
<?php
/**
 * Callback-eindpunt voor stemsites die een stem automatisch bevestigen.
 *
 * Geen require_login(): dit bestand wordt door de stemsite zelf aangeroepen,
 * niet door een ingelogde speler. De beveiliging zit in het geheime token,
 * niet in een sessie.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/klikmissies.php';

header('Content-Type: text/plain; charset=utf-8');

$id     = int_input('id');
$geheim = get('geheim');
$login  = get('login');

$missie = q_row('SELECT * FROM `klikmissies` WHERE `id` = ? AND `actief` = 1', [$id]);

if ($missie === null) {
    echo 'FOUT: onbekende missie';
    exit;
}

if (!hash_equals((string) $missie['callback_geheim'], $geheim)) {
    echo 'FOUT: ongeldig token';
    exit;
}

try {
    klikmissie_belonen($missie, $login, 'callback', client_ip());
    echo 'OK';
} catch (SpelFout $e) {
    echo 'FOUT: ' . $e->getMessage();
}
```

- [ ] **Step 6: Run de test om te zien dat hij slaagt**

Run: `php tests/geld.php`
Expected: PASS voor alle "klikmissies: ..."-controles.

- [ ] **Step 7: Commit**

```bash
git add inc/klikmissies.php klikmissies-callback.php inc/premium.php tests/geld.php
git commit -m "Kernfuncties en callback-eindpunt voor klikmissies toevoegen"
```

---

## Task 3: Adminpagina `adm-klikmissies.php`

**Files:**
- Modify: `inc/beheer.php` (functie `beheerpaginas()`)
- Modify: `inc/layout.php` (functie `menu_groups()`, groep "Beheer")
- Create: `adm-klikmissies.php`
- Test: `tests/veiligheid.php`

**Interfaces:**
- Consumes: `beheer_start()` is hier niet gebruikt (adm-poll.php doet dat ook niet) — in plaats daarvan `require_level(beheerpaginas()['adm-klikmissies.php'][1])`, `beheer_menu()`, `beheer_logregels()`, `log_action()`, alles al bestaand in `inc/beheer.php` / `inc/game.php`.
- Produces: niets dat andere taken nodig hebben — dit is beheer, geen spelerslogica.

- [ ] **Step 1: Voeg `adm-klikmissies.php` toe aan de rechtenlijst in `tests/veiligheid.php`**

In het `$paginas`-array van Deel 3 ("rechten: elk beheerniveau ziet precies wat het mag"):

```php
$paginas = [
    'admin.php'           => 'mod',
    'adm-online.php'      => 'mod',
    'adm-warn.php'        => 'mod',
    'adm-search.php'      => 'mod',
    'adm-msg.php'         => 'baas',
    'adm-ban.php'         => 'baas',
    'adm-addmulti.php'    => 'baas',
    'adm-items.php'       => 'baas',
    'adm-premium.php'     => 'baas',
    'adm-getuigen.php'    => 'baas',
    'adm-bo.php'          => 'baas',
    'adm-klikmissies.php' => 'baas',
];
```

- [ ] **Step 2: Run de rechtentest om te zien dat hij faalt**

Run: `php tests/veiligheid.php`
Expected: FAIL op `adm-klikmissies.php: vanaf baas` — de pagina bestaat nog niet (HTTP 404 voor iedereen, dus `$mag` is leeg in plaats van `['baas']`).

- [ ] **Step 3: Voeg de pagina toe aan `beheerpaginas()` in `inc/beheer.php`**

```php
function beheerpaginas(): array
{
    return [
        'adm-search.php'    => ['Zoeken',          LEVEL_MODERATOR],
        'adm-online.php'    => ['Online',          LEVEL_MODERATOR],
        'adm-prison.php'    => ['Gevangenis',      LEVEL_MODERATOR],
        'adm-warn.php'      => ['Waarschuwen',     LEVEL_MODERATOR],
        'adm-msg.php'       => ['Bericht sturen',  LEVEL_ADMIN],
        'adm-ban.php'       => ['Bannen',          LEVEL_ADMIN],
        'adm-addmulti.php'  => ['Multi-accounts',  LEVEL_ADMIN],
        'adm-shame.php'     => ['Wall of Shame',   LEVEL_ADMIN],
        'adm-forum.php'     => ['Forum opruimen',  LEVEL_ADMIN],
        'adm-addnews.php'   => ['Nieuws',          LEVEL_ADMIN],
        'adm-poll.php'      => ['Polls',           LEVEL_ADMIN],
        'adm-getuigen.php'  => ['Ooggetuigen',     LEVEL_ADMIN],
        'adm-klikmissies.php' => ['Klikmissies',   LEVEL_ADMIN],
        // Op eigenaarsniveau: hier wordt advertentiecode ingeplakt die
        // ongefilterd bij elke speler in de browser terechtkomt.
        'adm-premium.php'   => ['Premium',         LEVEL_OWNER],
        'adm-items.php'     => ['Items',           LEVEL_OWNER],
        'adm-drdrpr.php'    => ['Steden',          LEVEL_OWNER],
        'adm-bo.php'        => ['Speler bewerken', LEVEL_OWNER],
    ];
}
```

- [ ] **Step 4: Voeg dezelfde pagina toe aan de "Beheer"-groep in `menu_groups()` (`inc/layout.php`)**

```php
if ((int) $user['level'] >= LEVEL_MODERATOR) {
    $groepen['Beheer'] = [
        'adm-search.php'    => 'Zoeken',
        'adm-online.php'    => 'Online',
        'adm-addnews.php'   => 'Nieuws',
        'adm-ban.php'       => 'Bannen',
        'adm-addmulti.php'  => 'Multi-accounts',
        'adm-msg.php'       => 'Adminbericht',
        'adm-bo.php'        => 'Userstats',
        'adm-drdrpr.php'    => 'Steden',
        'adm-prison.php'    => 'Gevangenis',
        'adm-items.php'     => 'Items',
        'adm-shame.php'     => 'Wall of Shame',
        'adm-poll.php'      => 'Poll',
        'adm-getuigen.php'  => 'Ooggetuigen',
        'adm-premium.php'   => 'Premium',
        'adm-klikmissies.php' => 'Klikmissies',
    ];
}
```

- [ ] **Step 5: Schrijf `adm-klikmissies.php`**

```php
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
```

- [ ] **Step 6: Run de rechtentest om te zien dat hij slaagt**

Run: `php tests/veiligheid.php`
Expected: PASS op `adm-klikmissies.php: vanaf baas`.

- [ ] **Step 7: Run de rooktest**

Run: `php tests/rook.php`
Expected: PASS — `adm-klikmissies.php` laadt schoon (glob vindt het automatisch).

- [ ] **Step 8: Commit**

```bash
git add inc/beheer.php inc/layout.php adm-klikmissies.php tests/veiligheid.php
git commit -m "Adminpagina voor het beheren van klikmissies toevoegen"
```

---

## Task 4: Beschikbaarheidsbadge in het zijmenu

**Files:**
- Modify: `inc/layout.php` (functies `status_summary()` en `menu_groups()`)
- Test: `tests/opbouw.php`

**Interfaces:**
- Consumes: de tabellen `klikmissies` / `klikmissies_log` (rechtstreeks in SQL, geen functie uit `inc/klikmissies.php` nodig).
- Produces: `status_summary($user)['klikmissies_beschikbaar']` (int) — door latere taken niet gebruikt, puur voor het menu.

- [ ] **Step 1: Schrijf de falende test in `tests/opbouw.php`**

Voeg dit toe direct ná de regel `check('alle groepen staan er nog', substr_count($html, '<summary>') >= 6);` (vóór de familie-reset die daarop volgt):

```php
// --- Klikmissies: badge in het menu ------------------------------------------

kop('klikmissies: het zijmenu toont hoeveel klikmissies beschikbaar zijn');

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");
$db->exec(
    "INSERT INTO klikmissies (naam, url, cooldown_seconden, actief)
     VALUES ('Testlijst', 'https://voorbeeld.test/stem?ref={login}', 86400, 1)"
);
$missieId = (int) $db->lastInsertId();

$html = haal('home.php')['body'];
check('badge toont 1 beschikbare klikmissie', str_contains($html, 'Klikmissies (1)'), '');

$db->exec("INSERT INTO klikmissies_log (klikmissie_id, login, methode, ip)
            VALUES ({$missieId}, 'Speler', 'zelf', '127.0.0.1')");

$html2 = haal('home.php')['body'];
check('badge verdwijnt zodra de cooldown loopt', !str_contains($html2, 'Klikmissies ('), '');
check('het menu-item zelf blijft gewoon staan', str_contains($html2, '>Klikmissies<'), '');

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");
```

(Op dit punt in het bestand is 'Speler' al ingelogd met `level=1000` en een familie, zie de regels ervoor — geen nieuwe `login()`-aanroep nodig.)

- [ ] **Step 2: Run de test om te zien dat hij faalt**

Run: `php tests/opbouw.php`
Expected: FAIL — "Klikmissies (1)" staat nergens in de pagina, want het menu-item en de badge bestaan nog niet.

- [ ] **Step 3: Voeg de badge-query toe aan `status_summary()` in `inc/layout.php`**

```php
function status_summary(array $user): array
{
    static $onthouden = null;

    if ($onthouden !== null) {
        return $onthouden;
    }

    $rij = q_row(
        "SELECT
            (SELECT COUNT(*) + 1 FROM `users`
              WHERE `status` = 'levend' AND `activated` = 1 AND `xp` > :xp)      AS positie,
            (SELECT COUNT(*) FROM `users`
              WHERE `status` = 'levend' AND `activated` = 1)                     AS spelers,
            (SELECT COUNT(*) FROM `messages`
              WHERE `to` = :login AND `read` = 0)                                AS ongelezen,
            (SELECT COUNT(*) FROM `users`
              WHERE `online` > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                AND `status` = 'levend')                                         AS online,
            (SELECT COUNT(*) FROM `klikmissies` k
              WHERE k.`actief` = 1
                AND NOT EXISTS (
                  SELECT 1 FROM `klikmissies_log` l
                   WHERE l.`klikmissie_id` = k.`id` AND l.`login` = :klogin
                     AND l.`tijd` > DATE_SUB(NOW(), INTERVAL k.`cooldown_seconden` SECOND)
                )
            )                                                                    AS klikmissies_beschikbaar",
        ['xp' => (int) $user['xp'], 'login' => $user['login'], 'klogin' => $user['login']]
    ) ?? [];

    $onthouden = [
        'positie'                 => (int) ($rij['positie'] ?? 0),
        'spelers'                 => (int) ($rij['spelers'] ?? 0),
        'ongelezen'               => (int) ($rij['ongelezen'] ?? 0),
        'online'                  => (int) ($rij['online'] ?? 0),
        'klikmissies_beschikbaar' => (int) ($rij['klikmissies_beschikbaar'] ?? 0),
    ];

    return $onthouden;
}
```

Let op de plaatshouder `:klogin` naast `:login` — dezelfde waarde, maar met een andere naam, omdat benoemde plaatshouders in deze codebase niet herhaald mogen worden (`ATTR_EMULATE_PREPARES => false`).

- [ ] **Step 4: Voeg het menu-item en de badge toe aan `menu_groups()` in `inc/layout.php`**

Voeg `'klikmissies.php' => 'Klikmissies',` toe aan de groep `'Status'`, ná `'premium.php' => 'Premium',`. Voeg daarna, ná de definitie van `$groepen` en vóór de bestaande familie-logica, dit toe:

```php
$beschikbaar = status_summary($user)['klikmissies_beschikbaar'] ?? 0;

if ($beschikbaar > 0) {
    $groepen['Status']['klikmissies.php'] .= ' (' . num(min(99, $beschikbaar)) . ')';
}
```

- [ ] **Step 5: Run de test om te zien dat hij slaagt**

Run: `php tests/opbouw.php`
Expected: PASS op de drie nieuwe "klikmissies: ..."-controles.

Let op: `klikmissies.php` bestaat op dit punt nog niet als bestand (dat is Taak 5), maar dat is voor déze test geen probleem — de test controleert alleen de tekst in het menu, niet of de link werkt. De algemene menulink-test in `tests/adressen.php` (die élke sidebar-link daadwerkelijk opvraagt) faalt pas als `klikmissies.php` na Taak 5 nog steeds ontbreekt; op dit moment in het plan draai je `tests/adressen.php` nog niet opnieuw.

- [ ] **Step 6: Commit**

```bash
git add inc/layout.php tests/opbouw.php
git commit -m "Badge in het zijmenu tonen voor beschikbare klikmissies"
```

---

## Task 5: Spelerspagina `klikmissies.php`

**Files:**
- Create: `klikmissies.php`
- Test: `tests/geld.php`

**Interfaces:**
- Consumes: `klikmissies_actief()`, `klikmissie_url()`, `klikmissie_cooldown_tot()`, `klikmissie_belonen()` (uit `inc/klikmissies.php`, Taak 2).
- Produces: niets dat andere taken nodig hebben.

- [ ] **Step 1: Schrijf de falende test in `tests/geld.php`**

Voeg dit toe direct ná het "klikmissies: een verkeerd geheim..."-blok uit Taak 2 (vóór de laatste `$db->exec("DELETE FROM klikmissies...")`-opschoning, of maak een nieuw opschoon-en-opnieuw-invoegen-blok):

```php
kop('klikmissies: zelf-bevestigen beloont pas na de wachttijd, en respecteert de cooldown');

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");
$db->exec(
    "INSERT INTO klikmissies
        (naam, url, heeft_callback, wachttijd_klik, cooldown_seconden, beloning_zak, actief)
     VALUES ('Testlijst zelf', 'http://127.0.0.1:1/stem?ref={login}', 0, 2, 86400, 5000, 1)"
);
$missieId = (int) $db->lastInsertId();
$db->exec("UPDATE users SET zak=0 WHERE login='Speler'");

login('Speler', 'eenlangwachtwoord');

$h = haal('klikmissies.php');
haal('klikmissies.php', ['_token' => tok($h['body']), 'actie' => 'stem', 'id' => (string) $missieId]);

$h2 = haal('klikmissies.php');
$r4 = haal('klikmissies.php',
    ['_token' => tok($h2['body']), 'actie' => 'bevestig', 'id' => (string) $missieId]);

$u4 = $db->query("SELECT zak FROM users WHERE login='Speler'")->fetch();
check('te vroeg bevestigen beloont niets', (int) $u4['zak'] === 0, 'zak ' . $u4['zak']);
check('met een nette foutmelding', str_contains(melding($r4['body']), '[fout]'), melding($r4['body']));

sleep(2);

$h3 = haal('klikmissies.php');
$r5 = haal('klikmissies.php',
    ['_token' => tok($h3['body']), 'actie' => 'bevestig', 'id' => (string) $missieId]);

$u5 = $db->query("SELECT zak FROM users WHERE login='Speler'")->fetch();
check('na de wachttijd wordt wel beloond', (int) $u5['zak'] === 5000, 'zak ' . $u5['zak']);
check('met een nette bevestiging', str_contains(melding($r5['body']), '[ok]'), melding($r5['body']));

// Nogmaals bevestigen (cooldown loopt nog) mag niets meer opleveren.
haal('klikmissies.php', ['_token' => tok(haal('klikmissies.php')['body']), 'actie' => 'stem',
    'id' => (string) $missieId]);
sleep(2);
$h6 = haal('klikmissies.php');
$r6 = haal('klikmissies.php',
    ['_token' => tok($h6['body']), 'actie' => 'bevestig', 'id' => (string) $missieId]);

$u6 = $db->query("SELECT zak FROM users WHERE login='Speler'")->fetch();
check('een tweede keer binnen de cooldown beloont niets extra',
    (int) $u6['zak'] === 5000, 'zak ' . $u6['zak']);
check('met een foutmelding over de cooldown', str_contains(melding($r6['body']), '[fout]'), melding($r6['body']));

$db->exec("DELETE FROM klikmissies_log");
$db->exec("DELETE FROM klikmissies");

login('Speler', 'eenlangwachtwoord');
```

- [ ] **Step 2: Run de test om te zien dat hij faalt**

Run: `php tests/geld.php`
Expected: FAIL — `klikmissies.php` bestaat nog niet (HTTP 404), dus geen van de nieuwe controles klopt.

- [ ] **Step 3: Schrijf `klikmissies.php`**

```php
<?php
/**
 * Klikmissies: stem op externe toplijsten voor een beloning.
 *
 * Missies met een callback belonen automatisch zodra de stemsite zelf
 * klikmissies-callback.php aanroept. Missies zonder callback vereisen dat de
 * speler na het klikken op "Stem" bevestigt dat hij gestemd heeft; dat kan
 * pas na de ingestelde wachttijd, en die controle gebeurt hier server-side,
 * niet alleen in de afteller in de browser.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/klikmissies.php';

$user = require_login();

if (is_dead()) {
    redirect('rip.php');
}

$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        if (post('actie') === 'stem') {
            stem_klik($user, int_input('id'));   // redirect() bij succes, stopt het script hier
        }

        $melding = match (post('actie')) {
            'bevestig' => stem_bevestigen($user, int_input('id')),
            default    => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
        $user = current_user(true);
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Klikmissies');
panel_open('Klikmissies');

if ($melding !== null) {
    notice(e($melding), $type);
}

echo '<p>Steun de website door op een van deze links te stemmen. Elke missie heeft zijn '
   . 'eigen beloning en afkoeltijd.</p>';

toon_missies($user);

panel_close();
layout_footer();

// ==========================================================================

/** @throws SpelFout */
function stem_klik(array $user, int $id): void
{
    $missie = q_row('SELECT * FROM `klikmissies` WHERE `id` = ? AND `actief` = 1', [$id]);

    if ($missie === null) {
        throw new SpelFout('Die klikmissie bestaat niet meer.');
    }
    if ((int) $missie['heeft_callback'] === 1) {
        throw new SpelFout('Deze missie beloont automatisch; er is geen knop voor nodig.');
    }
    if (klikmissie_cooldown_tot($id, (int) $missie['cooldown_seconden'], $user['login']) > 0) {
        throw new SpelFout('Je moet nog even wachten voor je hier weer aan mag meedoen.');
    }

    $_SESSION['klikmissie_klik'][$id] = time();

    redirect(klikmissie_url($missie, $user['login']));
}

/** @throws SpelFout */
function stem_bevestigen(array $user, int $id): string
{
    $missie = q_row('SELECT * FROM `klikmissies` WHERE `id` = ? AND `actief` = 1', [$id]);

    if ($missie === null) {
        throw new SpelFout('Die klikmissie bestaat niet meer.');
    }

    $geklikt = $_SESSION['klikmissie_klik'][$id] ?? null;

    if ($geklikt === null) {
        throw new SpelFout('Klik eerst op "Stem" voordat je dit kunt bevestigen.');
    }
    if (time() - (int) $geklikt < (int) $missie['wachttijd_klik']) {
        throw new SpelFout('Dat ging te snel. Wacht nog even.');
    }

    klikmissie_belonen($missie, $user['login'], 'zelf', client_ip());
    unset($_SESSION['klikmissie_klik'][$id]);

    return 'Bedankt voor het stemmen! De beloning is bijgeschreven.';
}

// ==========================================================================

function toon_missies(array $user): void
{
    $missies = klikmissies_actief();

    if ($missies === []) {
        echo '<p>Er zijn op dit moment geen klikmissies.</p>';
        return;
    }

    foreach ($missies as $missie) {
        $id = (int) $missie['id'];

        echo '<div class="paneelinhoud">';
        echo '<h3>' . e((string) $missie['naam']) . '</h3>';

        if ((string) $missie['omschrijving'] !== '') {
            echo '<p>' . e((string) $missie['omschrijving']) . '</p>';
        }

        $wacht = klikmissie_cooldown_tot($id, (int) $missie['cooldown_seconden'], $user['login']);

        if ($wacht > 0) {
            echo '<p>Nog beschikbaar over <strong data-tot="' . $wacht . '">'
               . e(duration($wacht - time())) . '</strong>.</p>';
        } elseif ((int) $missie['heeft_callback'] === 1) {
            echo '<p><a class="knop" target="_blank" rel="noopener" href="'
               . e(klikmissie_url($missie, $user['login'])) . '">Stem</a> '
               . '<span class="uitleg">De beloning komt automatisch na het stemmen.</span></p>';
        } else {
            toon_klikflow($missie, $id);
        }

        echo '</div>';
    }
}

function toon_klikflow(array $missie, int $id): void
{
    $geklikt = $_SESSION['klikmissie_klik'][$id] ?? null;

    if ($geklikt === null) {
        echo '<form method="post">' . csrf_field();
        echo '<input type="hidden" name="actie" value="stem">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<button type="submit" class="knop">Stem</button>';
        echo '</form>';
        return;
    }

    $magVanaf = (int) $geklikt + (int) $missie['wachttijd_klik'];

    if ($magVanaf > time()) {
        echo '<p>Wacht nog <strong data-tot="' . $magVanaf . '">'
           . e(duration($magVanaf - time())) . '</strong> en klik dan op bevestigen.</p>';
    }

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="bevestig">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    echo '<button type="submit" class="knop"' . ($magVanaf > time() ? ' disabled' : '') . '>'
       . 'Ik heb gestemd</button>';
    echo '</form>';
}
```

- [ ] **Step 4: Run de test om te zien dat hij slaagt**

Run: `php tests/geld.php`
Expected: PASS op alle "klikmissies: ..."-controles.

- [ ] **Step 5: Run de volledige testreeks**

Run: `php tests/rook.php && php tests/veiligheid.php && php tests/opbouw.php && php tests/adressen.php`
Expected: alle vier PASS. `tests/veiligheid.php`'s generieke controles ("elk formulier draagt een token", "geen wijzigende GET-links") en `tests/adressen.php`'s menulink-crawl controleren `klikmissies.php` nu automatisch mee, zonder dat daar losse testregels voor nodig waren.

- [ ] **Step 6: Commit**

```bash
git add klikmissies.php tests/geld.php
git commit -m "Spelerspagina voor klikmissies toevoegen, met stem- en bevestigknop"
```

---

## Task 6: `help.php` bijwerken

**Files:**
- Modify: `help.php`

**Interfaces:** geen — puur tekst.

- [ ] **Step 1: Voeg een nieuwe sectie toe in `help.php`**, direct ná de sectie "Geld verdienen" (`panel_close();` die daarbij hoort) en vóór "Moord en getuigen":

```php
// --- Klikmissies -------------------------------------------------------------

panel_open('Klikmissies', 'klikmissies');

echo '<p>Op de pagina <a href="' . e(url('klikmissies.php')) . '">Klikmissies</a> vind je '
   . 'stemlinks naar externe toplijsten. Stem daar op de website en je krijgt een beloning: '
   . 'geld op zak, geld op de bank, diamanten, of een combinatie daarvan — dat verschilt per '
   . 'link.</p>';

echo '<p>Sommige links bevestigen je stem automatisch; de beloning komt dan vanzelf. Bij '
   . 'andere klik je na het stemmen zelf op "Ik heb gestemd" — dat kan pas na een korte '
   . 'wachttijd, zodat je ook echt eerst gestemd hebt.</p>';

echo '<p>Elke link heeft zijn eigen afkoeltijd voordat je er opnieuw voor beloond kunt worden.</p>';

panel_close();
```

- [ ] **Step 2: Run de rooktest**

Run: `php tests/rook.php`
Expected: PASS — `help.php` laadt nog steeds schoon.

- [ ] **Step 3: Commit**

```bash
git add help.php
git commit -m "Spelregels over klikmissies toevoegen aan help.php"
```

---

## Self-Review (uitgevoerd door de planschrijver)

**Spec-dekking:** dataflow (Taak 2 + 5), datamodel (al gedaan vóór dit plan, Taak 1 dekt productie), admin-CRUD (Taak 3), spelerspagina met beide stemstromen (Taak 5), callback-eindpunt (Taak 2), menu + badge (Taak 4), help.php (Taak 6), beveiliging (CSRF/rechten via bestaande generieke tests, geheim-token in Taak 2). Alles uit de spec heeft een taak.

**Placeholder-scan:** geen TBD's; elke stap heeft volledige code.

**Typeconsistentie:** `klikmissie_cooldown_tot(int $klikmissieId, int $cooldownSeconden, string $login): int` wordt in Taak 2 (binnen `klikmissie_belonen`), Taak 4 (niet gebruikt — SQL rechtstreeks) en Taak 5 (`toon_missies`, `stem_klik`) overal met dezelfde drie parameters in dezelfde volgorde aangeroepen. `klikmissie_belonen(array $missie, string $login, string $methode, string $ip): void` wordt in Taak 2 (`klikmissies-callback.php`) en Taak 5 (`stem_bevestigen`) identiek aangeroepen. `klikmissie_url(array $missie, string $login): string` idem in Taak 5.
