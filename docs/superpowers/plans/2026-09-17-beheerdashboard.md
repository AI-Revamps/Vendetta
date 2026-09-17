# Beheerdashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Het adminpaneel loskoppelen van de spelerslay-out tot een eigen
dashboard in een map `admin/`, met een categorie-indeling, uitgebreide
statistieken en trendgrafieken die spelers niet zien.

**Architecture:** Alle achttien beheerbestanden verhuizen (zonder het
`adm-`-voorvoegsel) naar `admin/` en krijgen een eigen schil
(`beheer_header()`/`beheer_footer()` in `inc/beheer.php`) die dezelfde
`<head>`-opbouw en dezelfde uitklap-CSS/JS hergebruikt als de spelerslay-out,
maar een eigen, categorie-ingedeeld zijmenu tekent. Een nieuwe tabel
`beheer_geschiedenis`, gevuld door een dagelijkse cron-taak, voedt
trendgrafieken op het dashboard; de grafieken zelf gebruiken een
zelf-gehoste, bewust uitgezonderde Chart.js.

**Tech Stack:** PHP 8 (vanilla, geen frameworks), MySQL/PDO, vanilla
JavaScript, en — als enige, gedocumenteerde uitzondering — de zelf-gehoste
Chart.js-bibliotheek voor de grafieken.

**Spec:** `docs/superpowers/specs/2026-09-17-beheerdashboard-design.md`

## Global Constraints

- Geen frameworks, geen Composer, geen bouwstap — behalve de bewust
  uitgezonderde, zelf-gehoste Chart.js (zie spec, "Grafiektechniek").
- Eén PHP-bestand per pagina, geen router. Elk beheerbestand verhuist als
  bestand op schijf naar `admin/`, met een eigen `require
  __DIR__ . '/../inc/bootstrap.php'`.
- Elke query via plaatshouders, nooit een variabele in de SQL-tekst zelf.
  Benoemde plaatshouders mogen niet herhaald worden binnen één query
  (`ATTR_EMULATE_PREPARES => false`) — gebruik verschillende namen per
  voorkomen, ook als het om dezelfde waarde gaat.
- `csrf_check()` bovenaan elke POST-verwerking; geen handeling achter een
  gewone link. Dit plan voegt geen nieuwe POST-acties toe.
- Geld verandert alleen via `afboeken()`/`bijschrijven()` binnen
  `db_transaction()` + `lock_user()`. Dit plan doet geen geldmutaties.
- Alle spelerstekst door `e()`. De JSON-data voor de grafieken gaat met
  `JSON_HEX_TAG | JSON_HEX_AMP` naar een `<script type="application/json">`.
- Na een POST altijd `redirect()` — niet van toepassing, dit plan voegt geen
  POST-acties toe.
- **Nooit PHP-bestanden bewerken via PowerShell** — gebruik Write/Edit.
  `Set-Content` zet een BOM vóór `<?php` en breekt `declare(strict_types=1)`.
- Alles in het Nederlands: code, commentaar, commitberichten, kolomnamen.
- De tests praten over HTTP met een draaiende server (zie
  `tests/LEESMIJ.md`); zorg dat die server en de testdatabase `bv_test`
  draaien voordat je een taak test.

---

## Task 1: Testhelper voor pagina's in submappen

Vóór er ook maar iets verplaatst wordt: de testscripts moeten straks
bestanden in `admin/` net zo vinden als bestanden in de hoofdmap. Deze taak
is een no-op voor het gedrag van vandaag (de map `admin/` bestaat nog niet),
maar maakt de volgende taken veilig.

**Files:**
- Modify: `tests/_start.php`
- Modify: `tests/rook.php:21`
- Modify: `tests/veiligheid.php:281`, `tests/veiligheid.php:306`
- Modify: `tests/adressen.php:103`

**Interfaces:**
- Produces: `alle_paginas(): array` — lijst van paginabestanden, hoofdmap
  als kale bestandsnaam (`'home.php'`), submappen met hun relatieve pad
  (`'admin/ban.php'`). Gebruikt door taken 3 t/m 7 zodra bestanden naar
  `admin/` verhuizen, zonder dat deze testbestanden dan nog een keer hoeven
  te veranderen.

- [ ] **Stap 1: `alle_paginas()` toevoegen aan `tests/_start.php`**

Voeg toe na de functie `totaal_geld()` (rond regel 234):

```php
/**
 * Alle paginabestanden: de hoofdmap en submappen zoals admin/. Bestanden in
 * de hoofdmap komen terug als kale naam ("home.php"), bestanden in een
 * submap met hun relatieve pad ("admin/ban.php") — zodat haal() en mooi()
 * ze rechtstreeks kunnen opvragen.
 */
function alle_paginas(): array
{
    $hoofdmap = array_map('basename', glob(BV_WORTEL . '/*.php') ?: []);

    $admin = array_map(
        static fn (string $pad): string => 'admin/' . basename($pad),
        glob(BV_WORTEL . '/admin/*.php') ?: []
    );

    return array_merge($hoofdmap, $admin);
}
```

- [ ] **Stap 2: `tests/rook.php` laten gebruiken**

In `tests/rook.php:21`, vervang:

```php
$paginas = array_map('basename', glob(BV_WORTEL . '/*.php') ?: []);
```

door:

```php
$paginas = alle_paginas();
```

- [ ] **Stap 3: `tests/veiligheid.php` op twee plekken laten gebruiken**

Op regel 281, vervang:

```php
foreach (array_map('basename', glob(BV_WORTEL . '/*.php') ?: []) as $pagina) {
```

door:

```php
foreach (alle_paginas() as $pagina) {
```

Doe dezelfde vervanging op regel 306 (identieke regel, ander blok).

- [ ] **Stap 4: `tests/adressen.php` laten gebruiken**

Op regel 103, vervang:

```php
foreach (array_map('basename', glob(BV_WORTEL . '/*.php') ?: []) as $naam) {
```

door:

```php
foreach (alle_paginas() as $naam) {
```

- [ ] **Stap 5: Draai de bestaande testen om te bevestigen dat er niets kapot is**

Zorg dat de gedeelde testserver en `bv_test` draaien (zie
`tests/LEESMIJ.md`), draai dan:

```bash
php tests/rook.php
php tests/veiligheid.php
```

Expected: beide eindigen met "Alles goed." — er verandert nog niets aan het
gedrag, want `admin/` bestaat nog niet.

- [ ] **Stap 6: Commit**

```bash
git add tests/_start.php tests/rook.php tests/veiligheid.php tests/adressen.php
git commit -m "test: paginabestanden in submappen meenemen in de testhulpjes"
```

---

## Task 2: Datamodel en periodieke taak voor de geschiedenis

Eén rij per dag met kerncijfers, gevuld door een nieuwe cron-taak. Dit is
onafhankelijk van de schil-verbouwing (taken 3-7) en de dashboard-inhoud
(taak 8) hangt er straks van af.

**Files:**
- Modify: `install/schema.sql`
- Modify: `inc/cron.php`
- Create: `tests/beheergeschiedenis.php`
- Modify: `tests/LEESMIJ.md`
- Modify: `CLAUDE.md`

**Interfaces:**
- Produces: tabel `beheer_geschiedenis` (kolommen: `dag`, `spelers`,
  `levend`, `online`, `geld_totaal`, `nieuwe_registraties`, `vast`,
  `bans_totaal`, `families`); functie `beheer_geschiedenis_bijwerken(): void`
  in `inc/cron.php`, aangeroepen via de taak `'beheer_geschiedenis'` in
  `cron_tasks()`. Taak 8 leest deze tabel rechtstreeks met `q_all()`.

- [ ] **Stap 1: Tabel toevoegen aan `install/schema.sql`**

Voeg toe vlak vóór `CREATE TABLE IF NOT EXISTS \`cron\`` (rond regel 654):

```sql
-- Dagelijkse momentopname van kerncijfers, voor trendgrafieken op het
-- beheerdashboard. Zonder deze tabel is een cijfer als "geld in omloop
-- vorige week" achteraf niet meer te reconstrueren.
CREATE TABLE IF NOT EXISTS `beheer_geschiedenis` (
  `dag`                 date NOT NULL,
  `spelers`             int unsigned NOT NULL DEFAULT 0,
  `levend`              int unsigned NOT NULL DEFAULT 0,
  `online`              int unsigned NOT NULL DEFAULT 0,
  `geld_totaal`         bigint NOT NULL DEFAULT 0,
  `nieuwe_registraties` int unsigned NOT NULL DEFAULT 0,
  `vast`                int unsigned NOT NULL DEFAULT 0,
  `bans_totaal`         int unsigned NOT NULL DEFAULT 0,
  `families`            int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`dag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

```

- [ ] **Stap 2: Tabel ook aanmaken in de bestaande testdatabase**

`tests/_start.php` laadt `install/schema.sql` alleen voor verse databases
(`verse_database()`); de gedeelde `bv_test` bestaat al. Voer eenmalig uit:

```bash
mysql -u root bv_test -e "CREATE TABLE IF NOT EXISTS beheer_geschiedenis (dag date NOT NULL, spelers int unsigned NOT NULL DEFAULT 0, levend int unsigned NOT NULL DEFAULT 0, online int unsigned NOT NULL DEFAULT 0, geld_totaal bigint NOT NULL DEFAULT 0, nieuwe_registraties int unsigned NOT NULL DEFAULT 0, vast int unsigned NOT NULL DEFAULT 0, bans_totaal int unsigned NOT NULL DEFAULT 0, families int unsigned NOT NULL DEFAULT 0, PRIMARY KEY (dag)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
```

- [ ] **Stap 3: Schrijf het nieuwe testbestand `tests/beheergeschiedenis.php` (faalt nu nog)**

```php
<?php
/**
 * Dagelijkse geschiedenis voor het beheerdashboard: schrijft de cron-taak
 * precies één rij per dag, en kloppen de cijfers daarin?
 *
 *     php tests/beheergeschiedenis.php
 *
 * De taak liftvt mee op gewone paginabezoeken (cron_mode = 'request', de
 * standaard) — elke haal() hieronder kan hem dus laten draaien zodra hij
 * aan de beurt is.
 */

declare(strict_types=1);

require __DIR__ . '/_start.php';

$db = tdb();

kop('beheer_geschiedenis: de eerste keer schrijft meteen een rij voor vandaag');

$db->exec("DELETE FROM beheer_geschiedenis WHERE dag = CURDATE()");
$db->exec("DELETE FROM cron WHERE name = 'beheer_geschiedenis'");

haal('home.php');

$rij = $db->query(
    "SELECT * FROM beheer_geschiedenis WHERE dag = CURDATE()"
)->fetch();

check('er staat een rij voor vandaag', $rij !== false);

$verwacht = $db->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE activated = 1) AS spelers,
        (SELECT COUNT(*) FROM users WHERE status = 'levend' AND activated = 1) AS levend,
        (SELECT IFNULL(SUM(zak) + SUM(bank), 0) FROM users) AS geld_totaal,
        (SELECT COUNT(*) FROM bans) AS bans_totaal,
        (SELECT COUNT(*) FROM famillie) AS families"
)->fetch();

check('spelers klopt met een losse telling',
    $rij !== false && (int) $rij['spelers'] === (int) $verwacht['spelers']);
check('geld_totaal klopt met een losse telling',
    $rij !== false && (int) $rij['geld_totaal'] === (int) $verwacht['geld_totaal']);
check('bans_totaal klopt met een losse telling',
    $rij !== false && (int) $rij['bans_totaal'] === (int) $verwacht['bans_totaal']);
check('families klopt met een losse telling',
    $rij !== false && (int) $rij['families'] === (int) $verwacht['families']);

kop('beheer_geschiedenis: nog een keer draaien overschrijft, verdubbelt niet');

// Forceer dat de taak weer "aan de beurt" is, en verander ondertussen een
// cijfer — zo bewijst een gewijzigde rij dat de tweede run echt gedraaid
// heeft, in plaats van dat de test toevallig al slaagde.
$db->exec("UPDATE cron SET time = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE name = 'beheer_geschiedenis'");
$db->exec("UPDATE bans SET id = id"); // no-op, houdt de intentie leesbaar
$db->exec("INSERT INTO bans (ip, login, reden, door) VALUES ('9.9.9.9', 'Speler', 'test', 'Baas')");

haal('home.php');

$aantalRijen = (int) $db->query(
    "SELECT COUNT(*) FROM beheer_geschiedenis WHERE dag = CURDATE()"
)->fetchColumn();
$nieuweRij = $db->query(
    "SELECT bans_totaal FROM beheer_geschiedenis WHERE dag = CURDATE()"
)->fetch();
$nieuwVerwacht = (int) $db->query("SELECT COUNT(*) FROM bans")->fetchColumn();

check('nog steeds precies één rij voor vandaag', $aantalRijen === 1, (string) $aantalRijen);
check('de rij is bijgewerkt, niet blijven hangen op de oude waarde',
    $nieuweRij !== false && (int) $nieuweRij['bans_totaal'] === $nieuwVerwacht);

$db->exec("DELETE FROM bans WHERE ip = '9.9.9.9'");

samenvatting();
```

- [ ] **Stap 4: Draai de test om te bevestigen dat hij faalt**

```bash
php tests/beheergeschiedenis.php
```

Expected: FAIL — `beheer_geschiedenis_bijwerken()` bestaat nog niet, dus de
tabel blijft leeg (`DATABASEFOUT` in `melding()` treedt hier niet op omdat
we de database rechtstreeks bevragen; de rij ontbreekt gewoon).

- [ ] **Stap 5: Schrijf `beheer_geschiedenis_bijwerken()` en registreer de taak**

In `inc/cron.php`, voeg toe aan de array in `cron_tasks()` (na de
`'loterij'`-taak, vóór de afsluitende `];`):

```php
        // Dagelijkse momentopname voor de trendgrafieken op het
        // beheerdashboard.
        'beheer_geschiedenis' => [86400, static function (): void {
            beheer_geschiedenis_bijwerken();
        }],
```

Voeg de functie zelf toe, na `loterij_uitbetalen()`:

```php
/**
 * Dagelijkse momentopname van kerncijfers, voor de trendgrafieken op het
 * beheerdashboard. `ON DUPLICATE KEY UPDATE` maakt dit onschadelijk als de
 * taak per ongeluk twee keer op dezelfde dag draait: de tweede run
 * overschrijft de eerste in plaats van een tweede rij te maken.
 *
 * Elke waarde staat twee keer in de parameterlijst, onder een andere naam
 * (`a` voor INSERT, `a2` voor UPDATE) — benoemde plaatshouders mogen niet
 * herhaald worden binnen één query.
 */
function beheer_geschiedenis_bijwerken(): void
{
    $cijfers = q_row(
        "SELECT
            (SELECT COUNT(*) FROM `users` WHERE `activated` = 1) AS spelers,
            (SELECT COUNT(*) FROM `users`
              WHERE `status` = 'levend' AND `activated` = 1)      AS levend,
            (SELECT COUNT(*) FROM `users`
              WHERE `online` > DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS online,
            (SELECT IFNULL(SUM(`zak`) + SUM(`bank`), 0) FROM `users`) AS geld,
            (SELECT COUNT(*) FROM `users`
              WHERE DATE(`start`) = CURDATE())                    AS nieuw,
            (SELECT COUNT(*) FROM `jail` WHERE `time` > NOW())    AS vast,
            (SELECT COUNT(*) FROM `bans`)                         AS bans,
            (SELECT COUNT(*) FROM `famillie`)                     AS families"
    ) ?? [];

    q(
        'INSERT INTO `beheer_geschiedenis`
            (`dag`, `spelers`, `levend`, `online`, `geld_totaal`,
             `nieuwe_registraties`, `vast`, `bans_totaal`, `families`)
         VALUES (CURDATE(), :a, :b, :c, :d, :e, :f, :g, :h)
         ON DUPLICATE KEY UPDATE
            `spelers` = :a2, `levend` = :b2, `online` = :c2,
            `geld_totaal` = :d2, `nieuwe_registraties` = :e2,
            `vast` = :f2, `bans_totaal` = :g2, `families` = :h2',
        [
            'a' => (int) ($cijfers['spelers'] ?? 0), 'a2' => (int) ($cijfers['spelers'] ?? 0),
            'b' => (int) ($cijfers['levend'] ?? 0),  'b2' => (int) ($cijfers['levend'] ?? 0),
            'c' => (int) ($cijfers['online'] ?? 0),  'c2' => (int) ($cijfers['online'] ?? 0),
            'd' => (int) ($cijfers['geld'] ?? 0),    'd2' => (int) ($cijfers['geld'] ?? 0),
            'e' => (int) ($cijfers['nieuw'] ?? 0),   'e2' => (int) ($cijfers['nieuw'] ?? 0),
            'f' => (int) ($cijfers['vast'] ?? 0),    'f2' => (int) ($cijfers['vast'] ?? 0),
            'g' => (int) ($cijfers['bans'] ?? 0),    'g2' => (int) ($cijfers['bans'] ?? 0),
            'h' => (int) ($cijfers['families'] ?? 0),'h2' => (int) ($cijfers['families'] ?? 0),
        ]
    );
}
```

- [ ] **Stap 6: Draai de test om te bevestigen dat hij slaagt**

```bash
php tests/beheergeschiedenis.php
```

Expected: PASS — "Alles goed."

- [ ] **Stap 7: Documentatie bijwerken**

In `tests/LEESMIJ.md`, voeg een rij toe aan de tabel "De tests" (na
`adressen.php`):

```markdown
| `beheergeschiedenis.php` | de dagelijkse cron-taak schrijft precies één rij per dag, met kloppende cijfers |
```

En werk de for-loop in datzelfde bestand bij:

```bash
for t in rook geld veiligheid opbouw adressen beheergeschiedenis; do php tests/$t.php || break; done
```

In `CLAUDE.md` (hoofdmap van het project), voeg toe aan de lijst onder
"Voordat je zegt dat je klaar bent":

```markdown
php tests/beheergeschiedenis.php  # de dagelijkse geschiedenis klopt
```

- [ ] **Stap 8: Regressiecontrole en commit**

```bash
php tests/rook.php
php tests/geld.php
```

Expected: beide "Alles goed." (de nieuwe cron-taak mag de geldbalans niet
raken — hij leest alleen).

```bash
git add install/schema.sql inc/cron.php tests/beheergeschiedenis.php tests/LEESMIJ.md CLAUDE.md
git commit -m "feat: dagelijkse geschiedenistabel voor het beheerdashboard"
```

---

## Task 3: De beheerschil bouwen, en het dashboard verplaatsen

Dit is de kern van de verbouwing: een eigen kopbalk/zijmenu voor beheer, en
het eerste bestand dat echt naar `admin/` verhuist. Na deze taak is
`/admin/dashboard` de nieuwe plek voor het overzicht, en heeft het spelmenu
nog maar één link naar beheer in plaats van vijftien.

**Files:**
- Modify: `inc/layout.php`
- Modify: `inc/beheer.php`
- Create: `assets/css/beheer.css`
- Verplaatsen (git mv): `admin.php` → `admin/dashboard.php`
- Modify: `tests/opbouw.php`
- Modify: `tests/veiligheid.php:152`

**Interfaces:**
- Consumes: `logo_url()`, `asset_url()`, `config()`, `csrf_field()`, `url()`
  (allemaal bestaand, ongewijzigd).
- Produces: `layout_head_html(string $titel, array $extraCss = []): void`
  (nieuw, gebruikt door zowel `layout_header()` als `beheer_header()`);
  `beheer_header(array $user, string $huidig, string $titel = 'Beheer'): void`;
  `beheer_footer(): void`; `beheer_url(string $bestand): string`;
  `beheer_categorieen(array $user): array`. Taken 4-7 roepen
  `beheer_header()`/`beheer_footer()`/`beheer_url()` aan vanuit elk
  verplaatst bestand.

- [ ] **Stap 1: `layout_head_html()` uit `layout_header()` trekken**

In `inc/layout.php`, vervang de functie `layout_header()` (regels 222-239,
het stuk vóór de `$metZijkanten`-berekening):

Huidige code:

```php
function layout_header(string $titel = ''): void
{
    $user     = current_user();
    $siteNaam = (string) config('site.name', 'Black Vendetta');
    $volTitel = $titel !== '' ? "{$titel} - {$siteNaam}" : $siteNaam;
    $huidig   = current_page();

    echo '<!doctype html>' . "\n";
    echo '<html lang="nl">' . "\n<head>\n";
    echo '<meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<title>' . e($volTitel) . "</title>\n";
    echo '<link rel="stylesheet" href="' . e(asset_url('assets/css/style.css')) . '">' . "\n";

    $favicon = logo_url('favicon.png');
    echo '<link rel="icon" href="' . e($favicon ?? url('favicon.ico')) . '">' . "\n";
    echo '<meta name="theme-color" content="#0a1120">' . "\n";
    echo "</head>\n";
```

Wordt:

```php
/**
 * De <head> die spelers- en beheerschil delen: doctype, meta, stylesheet(s),
 * favicon. $extraCss komt ná style.css, voor een schil-specifiek stijlblad
 * (zoals assets/css/beheer.css).
 *
 * @param string[] $extraCss Paden relatief aan de hoofdmap.
 */
function layout_head_html(string $titel, array $extraCss = []): void
{
    $siteNaam = (string) config('site.name', 'Black Vendetta');
    $volTitel = $titel !== '' ? "{$titel} - {$siteNaam}" : $siteNaam;

    echo '<!doctype html>' . "\n";
    echo '<html lang="nl">' . "\n<head>\n";
    echo '<meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<title>' . e($volTitel) . "</title>\n";
    echo '<link rel="stylesheet" href="' . e(asset_url('assets/css/style.css')) . '">' . "\n";

    foreach ($extraCss as $pad) {
        echo '<link rel="stylesheet" href="' . e(asset_url($pad)) . '">' . "\n";
    }

    $favicon = logo_url('favicon.png');
    echo '<link rel="icon" href="' . e($favicon ?? url('favicon.ico')) . '">' . "\n";
    echo '<meta name="theme-color" content="#0a1120">' . "\n";
    echo "</head>\n";
}

/** Open de pagina: <head>, kopbalk, menu en de opening van het inhoudsvak. */
function layout_header(string $titel = ''): void
{
    $user     = current_user();
    $siteNaam = (string) config('site.name', 'Black Vendetta');
    $huidig   = current_page();

    layout_head_html($titel);
```

(De rest van `layout_header()`, vanaf `// Zijmenu, statuspaneel...` tot het
einde van de functie, blijft ongewijzigd — die gebruikt `$siteNaam` en
`$huidig` verderop en die blijven dus gewoon staan.)

- [ ] **Stap 2: Categorieën aan `beheerpaginas()` toevoegen, met een veilige val-door**

In `inc/beheer.php`, vervang de hele functie `beheerpaginas()`:

```php
/**
 * De beheerpagina's: bestand => [label, benodigd niveau, categorie].
 *
 * De categorie ontbreekt bewust bij bestanden die nog niet naar admin/ zijn
 * verplaatst — beheer_categorieen() slaat zulke rijen over, zodat het
 * zijmenu nooit naar een bestand linkt dat nog op zijn oude plek staat.
 * Naarmate elke categorie verhuist (zie de volgende taken) krijgt de rij
 * zijn derde element én zijn nieuwe, kale bestandsnaam als sleutel.
 */
function beheerpaginas(): array
{
    return [
        'adm-search.php'   => ['Zoeken',          LEVEL_MODERATOR],
        'adm-online.php'   => ['Online',          LEVEL_MODERATOR],
        'adm-prison.php'   => ['Gevangenis',      LEVEL_MODERATOR],
        'adm-warn.php'     => ['Waarschuwen',     LEVEL_MODERATOR],
        'adm-msg.php'      => ['Bericht sturen',  LEVEL_ADMIN],
        'adm-ban.php'      => ['Bannen',          LEVEL_ADMIN],
        'adm-addmulti.php' => ['Multi-accounts',  LEVEL_ADMIN],
        'adm-shame.php'    => ['Wall of Shame',   LEVEL_ADMIN],
        'adm-forum.php'    => ['Forum opruimen',  LEVEL_ADMIN],
        'adm-addnews.php'  => ['Nieuws',          LEVEL_ADMIN],
        'adm-poll.php'     => ['Polls',           LEVEL_ADMIN],
        'adm-getuigen.php' => ['Ooggetuigen',     LEVEL_ADMIN],
        'adm-klikmissies.php' => ['Klikmissies',  LEVEL_ADMIN],
        'adm-premium.php'  => ['Premium',         LEVEL_ADMIN],
        'adm-items.php'    => ['Items',           LEVEL_OWNER],
        'adm-drdrpr.php'   => ['Steden',          LEVEL_OWNER],
        'adm-bo.php'       => ['Speler bewerken', LEVEL_OWNER],
    ];
}
```

(Dit is functioneel identiek aan de huidige inhoud — er verandert nu nog
niets aan de sleutels of het aantal elementen. Dat gebeurt per categorie in
taken 4-7.)

- [ ] **Stap 3: `beheer_categorieen()`, `beheer_url()`, `beheer_header()` en
      `beheer_footer()` toevoegen**

Nog in `inc/beheer.php`, voeg toe ná `beheerpaginas()`:

```php
/**
 * Adres van een beheerpagina. De enige plek die weet dat beheerbestanden in
 * admin/ staan — links naar een beheerpagina gaan hier altijd doorheen, niet
 * via een rechtstreekse url()-aanroep.
 */
function beheer_url(string $bestand): string
{
    return url('admin/' . $bestand);
}

/**
 * Het beheerzijmenu, per categorie, gefilterd op het niveau van deze
 * gebruiker. Rijen zonder categorie (nog niet verplaatst) worden overgeslagen.
 *
 * @return array<string, array<string, string>>
 */
function beheer_categorieen(array $user): array
{
    $categorieen = [];

    foreach (beheerpaginas() as $bestand => $rij) {
        $nodig     = $rij[1];
        $categorie = $rij[2] ?? null;

        if ($categorie === null || (int) $user['level'] < $nodig) {
            continue;
        }

        $categorieen[$categorie][$bestand] = $rij[0];
    }

    return $categorieen;
}

/**
 * Open de beheerpagina: eigen kopbalk en een zijmenu met categorieën, in
 * plaats van de volledige spelerslay-out.
 *
 * Hergebruikt bewust dezelfde klassen en id's als het spelerszijmenu
 * (sidebar, zijmenu, menu-toggle, menu-overlay): de uitklap-logica in
 * assets/js/app.js werkt daardoor ongewijzigd, ook hier.
 */
function beheer_header(array $user, string $huidig, string $titel = 'Beheer'): void
{
    $siteNaam = (string) config('site.name', 'Black Vendetta');

    layout_head_html($titel, ['assets/css/beheer.css']);

    echo "<body>\n";

    echo '<header class="topbar">' . "\n";
    $merk = logo_url('logo-mark.png');
    echo '<a class="brand" href="' . e(beheer_url('dashboard.php')) . '">';
    if ($merk !== null) {
        echo '<img src="' . e($merk) . '" alt="" width="36" height="36">';
    }
    echo '<span>' . e($siteNaam) . ' beheer</span></a>' . "\n";

    echo '<nav class="topnav">';
    echo '<a href="' . e(url('home.php')) . '">Terug naar het spel</a>';
    echo '<span class="beheer-wie">' . e((string) $user['login']) . '</span>';
    echo '<form method="post" action="' . e(url('logout.php')) . '" class="logout-form">'
       . csrf_field() . '<button type="submit">Uitloggen</button></form>';
    echo "</nav>\n";

    echo '<button class="menu-toggle" type="button" aria-controls="zijmenu" '
       . 'aria-expanded="false" aria-label="Menu">&#9776;</button>' . "\n";
    echo "</header>\n";

    echo '<button class="menu-overlay" type="button" hidden aria-label="Menu sluiten"></button>' . "\n";

    echo '<div class="layout beheer-layout">' . "\n";

    echo '<nav class="sidebar" id="zijmenu">' . "\n";
    echo '<a class="knop beheer-overzicht' . ($huidig === 'dashboard.php' ? ' knop-nadruk' : '')
       . '" href="' . e(beheer_url('dashboard.php')) . '">Overzicht</a>' . "\n";

    foreach (beheer_categorieen($user) as $categorie => $items) {
        $open = array_key_exists($huidig, $items);
        echo '<details class="menugroep" data-groep="' . e($categorie) . '"'
           . ($open ? ' open' : '') . '><summary>' . e($categorie) . "</summary>\n<ul>\n";
        foreach ($items as $bestand => $label) {
            $klasse = $bestand === $huidig ? ' class="actief"' : '';
            echo '<li><a href="' . e(beheer_url($bestand)) . '"' . $klasse . '>' . e($label) . "</a></li>\n";
        }
        echo "</ul>\n</details>\n";
    }

    echo "</nav>\n";

    echo '<main class="content">' . "\n";

    foreach (flash_take() as $melding) {
        echo '<div class="melding melding-' . e($melding['type']) . '">' . e($melding['msg']) . "</div>\n";
    }
}

/** Sluit de beheerpagina af. */
function beheer_footer(): void
{
    echo "</main>\n";
    echo "</div>\n"; // .layout.beheer-layout

    echo '<script src="' . e(asset_url('assets/js/vendor/chart.umd.min.js')) . '" defer></script>' . "\n";
    echo '<script src="' . e(asset_url('assets/js/app.js')) . '" defer></script>' . "\n";
    echo '<script src="' . e(asset_url('assets/js/beheer.js')) . '" defer></script>' . "\n";
    echo "</body>\n</html>\n";
}
```

`assets/js/vendor/chart.umd.min.js` en `assets/js/beheer.js` bestaan pas na
taak 8; tot die tijd geeft de `<script>`-tag een onschuldige 404 in de
browserconsole (geen fatale fout, `asset_url()` faalt niet op een
ontbrekend bestand). `panel_open()`/`panel_close()`/`notice()` blijven
werken zoals ze zijn: die tekenen alleen in `<main class="content">`, wat
hier identiek staat aan de spelerslay-out.

- [ ] **Stap 4: `beheer_start()` laten meeliften**

Vervang in `inc/beheer.php` de functie `beheer_start()`:

```php
function beheer_start(string $pagina): array
{
    $nodig = beheerpaginas()[$pagina][1] ?? LEVEL_OWNER;
    $user  = require_level($nodig);

    layout_header('Beheer');
    beheer_menu($user, $pagina);

    return $user;
}
```

Wordt:

```php
function beheer_start(string $pagina): array
{
    $nodig = beheerpaginas()[$pagina][1] ?? LEVEL_OWNER;
    $user  = require_level($nodig);

    beheer_header($user, $pagina);

    return $user;
}
```

Laat `beheer_menu()` (de oude platte-knoppenrij-functie) en `beheer_speler()`
en `beheer_logregels()` ongewijzigd staan — `beheer_menu()` heeft tot en met
taak 6 nog gebruikers in bestanden die nog niet verplaatst zijn, en wordt in
taak 7 opgeruimd zodra de laatste gebruiker verdwijnt.

- [ ] **Stap 5: Basis-CSS voor de beheerschil**

Maak `assets/css/beheer.css`:

```css
/* Black Vendetta - beheerdashboard.
   Kleuren en lettertype komen uit style.css (:root); dit bestand voegt
   alleen de dashboard-specifieke indeling toe: geen derde kolom (geen
   statuspaneel in beheer), kaarten voor kerncijfers, vakken voor
   grafieken. */

/* Boven 1100px tekent style.css .layout met drie kolommen (sidebar, inhoud,
   statuspaneel). Beheer heeft geen statuspaneel, dus twee kolommen — maar
   alleen op dit brede scherm: de tablet- en telefoonregels in style.css
   voor .layout blijven verder ongemoeid. */
@media (min-width: 1101px) {
    .beheer-layout { grid-template-columns: 200px minmax(0, 1fr); }
}

.beheer-overzicht {
    display: block;
    margin-bottom: .75rem;
    text-align: center;
}

.beheer-wie {
    color: var(--tekst-dof);
    margin: 0 .75rem;
}

.beheer-kaarten {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.beheer-kaart {
    background: var(--paneel-op);
    border: 1px solid var(--rand);
    border-radius: var(--radius);
    padding: .9rem 1rem;
}

.beheer-kaart .cijfer {
    display: block;
    font-size: 1.6rem;
    font-weight: 600;
    color: var(--accent-fel);
}

.beheer-kaart .label {
    color: var(--tekst-dof);
    font-size: .85rem;
}

.beheer-grafieken {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
}

.beheer-grafiek {
    background: var(--paneel-op);
    border: 1px solid var(--rand);
    border-radius: var(--radius);
    padding: 1rem;
}

.beheer-grafiek h3 { margin-top: 0; }
```

- [ ] **Stap 6: `admin.php` verplaatsen naar `admin/dashboard.php`**

```bash
mkdir admin
git mv admin.php admin/dashboard.php
```

In `admin/dashboard.php`, drie wijzigingen:

Vervang:

```php
require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user = require_level(LEVEL_MODERATOR);

layout_header('Beheer');
beheer_menu($user, 'admin.php');
```

door:

```php
require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

$user = require_level(LEVEL_MODERATOR);

beheer_header($user, 'dashboard.php');
```

En vervang de afsluitende regel:

```php
panel_close();
layout_footer();
```

door:

```php
panel_close();
beheer_footer();
```

- [ ] **Stap 7: Het spelmenu bijwerken**

In `inc/layout.php`, in `menu_groups()`, vervang het hele blok:

```php
    if ((int) $user['level'] >= LEVEL_MODERATOR) {
        $groepen['Beheer'] = [
            'adm-search.php'   => 'Zoeken',
            'adm-online.php'   => 'Online',
            'adm-addnews.php'  => 'Nieuws',
            'adm-ban.php'      => 'Bannen',
            'adm-addmulti.php' => 'Multi-accounts',
            'adm-msg.php'      => 'Adminbericht',
            'adm-bo.php'       => 'Userstats',
            'adm-drdrpr.php'   => 'Steden',
            'adm-prison.php'   => 'Gevangenis',
            'adm-items.php'    => 'Items',
            'adm-shame.php'    => 'Wall of Shame',
            'adm-poll.php'     => 'Poll',
            'adm-getuigen.php' => 'Ooggetuigen',
            'adm-premium.php'  => 'Premium',
            'adm-klikmissies.php' => 'Klikmissies',
        ];
    }
```

door:

```php
    if ((int) $user['level'] >= LEVEL_MODERATOR) {
        $groepen['Beheer'] = [
            'admin/dashboard.php' => 'Beheerdashboard',
        ];
    }
```

- [ ] **Stap 8: `tests/opbouw.php` bijwerken**

`adm-premium.php` verhuist pas in taak 7 (categorie Economie) — laat de
`doe('adm-premium.php', ...)`-aanroepen op regel 58 en 71 dus ongemoeid;
die worden pas in taak 7 (stap 4 daar) `'admin/premium.php'`.

Verwijder wél nu al de regel die niet meer klopt, in het blok "menu: alleen
de groep waar je bent staat open" (rond regel 156):

```php
    'adm-search.php' => 'Beheer',
```

Deze regel testte dat het **spelmenu** de groep "Beheer" opent als je op een
adminpagina zit. Na deze taak heeft het spelmenu nog maar één regel in de
groep "Beheer" (de link naar het dashboard) en wordt die groep niet meer
geopend door op een adminpagina te staan — adminpagina's tekenen vanaf nu
hun eigen schil, niet meer het spelmenu. Verwijder de regel uit de
`$verwacht`-array; laat de rest van die array (`home.php`, `shop.php`, enz.)
ongewijzigd.

Voeg daarna, na het bestaande blok "--- Menu ---" (ná de check
`'alle groepen staan er nog'`, rond regel 187), een nieuw blok toe voor de
beheerschil zelf:

```php
// --- Beheerschil -------------------------------------------------------

kop('beheerschil: eigen kopbalk en zijmenu, geen spelonderdelen');

$baasJar = login('Baas', 'baaswachtwoord12345');
$html    = haal('admin/dashboard.php', null, $baasJar)['body'];

check('geen statuspaneel', !str_contains($html, 'class="statuspaneel"'));
check('geen onderbalk', !str_contains($html, 'class="onderbalk"'));
check('geen spelmodus-klasse op body', !str_contains($html, '<body class="spelmodus"'));
check('wel het beheer-zijmenu', str_contains($html, 'id="zijmenu"'));
check('wel een link terug naar het spel', str_contains($html, 'Terug naar het spel'));
check('toont wie is ingelogd', str_contains($html, '>Baas<'));
```

- [ ] **Stap 9: `tests/veiligheid.php` bijwerken**

Op regel 152, vervang:

```php
    'admin.php'        => 'mod',
```

door:

```php
    'admin/dashboard.php' => 'mod',
```

- [ ] **Stap 10: Draai de testen**

```bash
php tests/rook.php
php tests/opbouw.php
php tests/veiligheid.php
php tests/adressen.php
```

Expected: alle vier "Alles goed." `rook.php` laadt `admin/dashboard.php`
dankzij `alle_paginas()` uit taak 1; de zeventien nog niet verplaatste
`adm-*.php`-bestanden werken nog exact zoals voorheen (ze roepen nog steeds
`layout_header()`/`beheer_menu()`/`layout_footer()` aan, die functies
bestaan nog).

- [ ] **Stap 11: Commit**

```bash
git add inc/layout.php inc/beheer.php assets/css/beheer.css admin/dashboard.php tests/opbouw.php tests/veiligheid.php
git status   # bevestig dat admin.php als verplaatst (renamed) wordt herkend
git commit -m "feat: eigen beheerschil, dashboard verhuisd naar admin/dashboard.php"
```

---

## Task 4: Categorie Spelers verplaatsen

Zeven bestanden: `adm-search.php`, `adm-online.php`, `adm-ban.php`,
`adm-warn.php`, `adm-prison.php`, `adm-addmulti.php`, `adm-bo.php`.

**Files:**
- Verplaatsen (git mv): `adm-search.php` → `admin/search.php`,
  `adm-online.php` → `admin/online.php`, `adm-ban.php` → `admin/ban.php`,
  `adm-warn.php` → `admin/warn.php`, `adm-prison.php` → `admin/prison.php`,
  `adm-addmulti.php` → `admin/addmulti.php`, `adm-bo.php` → `admin/bo.php`
- Modify: `inc/beheer.php` (`beheerpaginas()`)
- Modify: `tests/veiligheid.php`

**Interfaces:**
- Consumes: `beheer_header()`, `beheer_footer()`, `beheer_url()` (taak 3).

- [ ] **Stap 1: `beheerpaginas()` bijwerken voor deze zeven rijen**

In `inc/beheer.php`, vervang de zeven rijen:

```php
        'adm-search.php'   => ['Zoeken',          LEVEL_MODERATOR],
        'adm-online.php'   => ['Online',          LEVEL_MODERATOR],
        'adm-prison.php'   => ['Gevangenis',      LEVEL_MODERATOR],
        'adm-warn.php'     => ['Waarschuwen',     LEVEL_MODERATOR],
```

door:

```php
        'search.php'   => ['Zoeken',          LEVEL_MODERATOR, 'Spelers'],
        'online.php'   => ['Online',          LEVEL_MODERATOR, 'Spelers'],
        'prison.php'   => ['Gevangenis',      LEVEL_MODERATOR, 'Spelers'],
        'warn.php'     => ['Waarschuwen',     LEVEL_MODERATOR, 'Spelers'],
```

en vervang:

```php
        'adm-ban.php'      => ['Bannen',          LEVEL_ADMIN],
        'adm-addmulti.php' => ['Multi-accounts',  LEVEL_ADMIN],
```

door:

```php
        'ban.php'      => ['Bannen',          LEVEL_ADMIN, 'Spelers'],
        'addmulti.php' => ['Multi-accounts',  LEVEL_ADMIN, 'Spelers'],
```

en vervang:

```php
        'adm-bo.php'       => ['Speler bewerken', LEVEL_OWNER],
```

door:

```php
        'bo.php'       => ['Speler bewerken', LEVEL_OWNER, 'Spelers'],
```

- [ ] **Stap 2: Verplaatsen en de require-regel repareren**

```bash
git mv adm-search.php admin/search.php
git mv adm-online.php admin/online.php
git mv adm-ban.php admin/ban.php
git mv adm-warn.php admin/warn.php
git mv adm-prison.php admin/prison.php
git mv adm-addmulti.php admin/addmulti.php
git mv adm-bo.php admin/bo.php
```

In elk van deze zeven bestanden, vervang:

```php
require __DIR__ . '/inc/bootstrap.php';
```

door:

```php
require __DIR__ . '/../inc/bootstrap.php';
```

- [ ] **Stap 3: Rechtenregel en schil-aanroep per bestand**

`admin/search.php` (was `adm-search.php`) heeft de afwijkende, tweeregelige
vorm. Vervang:

```php
$nodig = beheerpaginas()['adm-search.php'][1];
$user  = require_level($nodig);
```

door:

```php
$nodig = beheerpaginas()['search.php'][1];
$user  = require_level($nodig);
```

en vervang:

```php
layout_header('Beheer');
beheer_menu($user, 'adm-search.php');
```

door:

```php
beheer_header($user, 'search.php');
```

`admin/online.php` gebruikt `beheer_start()` en heeft dus geen losse
rechten-/header-regels om aan te passen — alleen de aanroep zelf:

```php
$user = beheer_start('adm-online.php');
```

wordt:

```php
$user = beheer_start('online.php');
```

Voor de overige vijf bestanden (`admin/ban.php`, `admin/warn.php`,
`admin/prison.php`, `admin/addmulti.php`, `admin/bo.php`) geldt hetzelfde
patroon. Vervang in elk bestand de regel

```php
$user    = require_level(beheerpaginas()['adm-<naam>.php'][1]);
```

door

```php
$user    = require_level(beheerpaginas()['<naam>.php'][1]);
```

en de twee regels

```php
layout_header('Beheer');
beheer_menu($user, 'adm-<naam>.php');
```

door

```php
beheer_header($user, '<naam>.php');
```

waarbij `<naam>` respectievelijk `ban`, `warn`, `prison`, `addmulti`, `bo`
is. Vervang tot slot in al deze zeven bestanden de afsluitende regel:

```php
layout_footer();
```

door:

```php
beheer_footer();
```

- [ ] **Stap 4: Kruislinks binnen deze categorie repareren**

In `admin/search.php` (rond regel 283), vervang:

```php
. e(url('adm-search.php?login=' . rawurlencode((string) $r['login']))) . '">'
```

door:

```php
. e(beheer_url('search.php?login=' . rawurlencode((string) $r['login']))) . '">'
```

In `admin/online.php` (rond regel 48), vervang:

```php
. e(url('adm-search.php?login=' . rawurlencode((string) $speler['login'])))
```

door:

```php
. e(beheer_url('search.php?login=' . rawurlencode((string) $speler['login'])))
```

In `admin/addmulti.php` (rond regel 75), vervang:

```php
. e(url('adm-search.php?login=' . rawurlencode((string) $r['login']))) . '">'
```

door:

```php
. e(beheer_url('search.php?login=' . rawurlencode((string) $r['login']))) . '">'
```

In `admin/prison.php` (rond regel 73), vervang:

```php
   . e(url('adm-search.php?login=' . rawurlencode((string) $cel['login'])))
```

door:

```php
   . e(beheer_url('search.php?login=' . rawurlencode((string) $cel['login'])))
```

- [ ] **Stap 5: `tests/veiligheid.php` bijwerken**

In de rechtentabel (rond regel 151-164), vervang:

```php
    'adm-online.php'   => 'mod',
    'adm-warn.php'     => 'mod',
    'adm-search.php'   => 'mod',
```

door:

```php
    'admin/online.php' => 'mod',
    'admin/warn.php'   => 'mod',
    'admin/search.php' => 'mod',
```

en vervang:

```php
    'adm-ban.php'      => 'admin',
    'adm-addmulti.php' => 'admin',
```

door:

```php
    'admin/ban.php'      => 'admin',
    'admin/addmulti.php' => 'admin',
```

Er is geen regel voor `adm-bo.php` of `adm-prison.php` in deze tabel — die
blijven ontbreken, dat is de bestaande situatie.

Verderop, vervang (rond regel 235-236):

```php
$tokenBo = tok(haal('adm-bo.php', null, $baas)['body']);
haal('adm-bo.php', ['_token' => $tokenBo, 'id' => (string) $id, 'level' => '1000'], $baas);
```

door:

```php
$tokenBo = tok(haal('admin/bo.php', null, $baas)['body']);
haal('admin/bo.php', ['_token' => $tokenBo, 'id' => (string) $id, 'level' => '1000'], $baas);
```

En vervang (rond regel 246-247):

```php
$h = haal('adm-ban.php', null, $baas)['body'];
$r = haal('adm-ban.php', ['_token' => tok($h), 'actie' => 'ban', 'soort' => 'login',
    'doel' => 'Speler', 'reden' => 'test'], $mod);
```

door:

```php
$h = haal('admin/ban.php', null, $baas)['body'];
$r = haal('admin/ban.php', ['_token' => tok($h), 'actie' => 'ban', 'soort' => 'login',
    'doel' => 'Speler', 'reden' => 'test'], $mod);
```

- [ ] **Stap 6: Draai de testen**

```bash
php tests/rook.php
php tests/veiligheid.php
php tests/opbouw.php
```

Expected: alle drie "Alles goed."

- [ ] **Stap 7: Commit**

```bash
git add -A admin inc/beheer.php tests/veiligheid.php
git commit -m "refactor: categorie Spelers verhuisd naar admin/"
```

---

## Task 5: Categorie Inhoud verplaatsen

Vier bestanden: `adm-addnews.php`, `adm-poll.php`, `adm-forum.php`,
`adm-shame.php`. Geen van deze vier komt voor in `tests/veiligheid.php`'s
rechtentabel of literale `haal()`-aanroepen, dus daar is niets aan te
passen.

**Files:**
- Verplaatsen (git mv): `adm-addnews.php` → `admin/addnews.php`,
  `adm-poll.php` → `admin/poll.php`, `adm-forum.php` → `admin/forum.php`,
  `adm-shame.php` → `admin/shame.php`
- Modify: `inc/beheer.php` (`beheerpaginas()`)

- [ ] **Stap 1: `beheerpaginas()` bijwerken**

Vervang:

```php
        'adm-shame.php'    => ['Wall of Shame',   LEVEL_ADMIN],
        'adm-forum.php'    => ['Forum opruimen',  LEVEL_ADMIN],
        'adm-addnews.php'  => ['Nieuws',          LEVEL_ADMIN],
        'adm-poll.php'     => ['Polls',           LEVEL_ADMIN],
```

door:

```php
        'shame.php'    => ['Wall of Shame',   LEVEL_ADMIN, 'Inhoud'],
        'forum.php'    => ['Forum opruimen',  LEVEL_ADMIN, 'Inhoud'],
        'addnews.php'  => ['Nieuws',          LEVEL_ADMIN, 'Inhoud'],
        'poll.php'     => ['Polls',           LEVEL_ADMIN, 'Inhoud'],
```

- [ ] **Stap 2: Verplaatsen en de require-regel repareren**

```bash
git mv adm-addnews.php admin/addnews.php
git mv adm-poll.php admin/poll.php
git mv adm-forum.php admin/forum.php
git mv adm-shame.php admin/shame.php
```

In elk van deze vier bestanden, vervang `require __DIR__ .
'/inc/bootstrap.php';` door `require __DIR__ . '/../inc/bootstrap.php';`.

- [ ] **Stap 3: Rechtenregel en schil-aanroep per bestand**

In elk bestand, vervang de regel

```php
$user    = require_level(beheerpaginas()['adm-<naam>.php'][1]);
```

door

```php
$user    = require_level(beheerpaginas()['<naam>.php'][1]);
```

(`<naam>` = `addnews`, `poll`, `forum`, `shame`), vervang

```php
layout_header('Beheer');
beheer_menu($user, 'adm-<naam>.php');
```

door

```php
beheer_header($user, '<naam>.php');
```

en vervang de afsluitende `layout_footer();` door `beheer_footer();`.

- [ ] **Stap 4: Kruislinks binnen deze categorie repareren**

In `admin/forum.php`, vervang alle vier voorkomens:

```php
    echo '<a href="' . e(url('adm-forum.php')) . '">Alles</a>';
```
→
```php
    echo '<a href="' . e(beheer_url('forum.php')) . '">Alles</a>';
```

```php
        echo ' &middot; <a href="' . e(url('adm-forum.php?type=' . rawurlencode($sleutel))) . '">'
```
→
```php
        echo ' &middot; <a href="' . e(beheer_url('forum.php?type=' . rawurlencode($sleutel))) . '">'
```

```php
            echo '<td><a href="' . e(url('adm-forum.php?topic=' . (int) $rij['id'])) . '">'
```
→
```php
            echo '<td><a href="' . e(beheer_url('forum.php?topic=' . (int) $rij['id'])) . '">'
```

```php
    echo '<p><a href="' . e(url('adm-forum.php?type=' . rawurlencode((string) $topic['type'])))
```
→
```php
    echo '<p><a href="' . e(beheer_url('forum.php?type=' . rawurlencode((string) $topic['type'])))
```

In `admin/addnews.php`, vervang de twee voorkomens:

```php
    echo '<p><a href="' . e(url('adm-addnews.php')) . '">Annuleren</a></p>';
```
→
```php
    echo '<p><a href="' . e(beheer_url('addnews.php')) . '">Annuleren</a></p>';
```

```php
        echo '<div class="knoppenrij"><a href="' . e(url('adm-addnews.php?bewerk=' . (int) $rij['id'])) . '">Bewerken</a> '
```
→
```php
        echo '<div class="knoppenrij"><a href="' . e(beheer_url('addnews.php?bewerk=' . (int) $rij['id'])) . '">Bewerken</a> '
```

```php
                : '<a href="' . e(url('adm-addnews.php?p=' . $i)) . '">' . ($i + 1) . '</a> ';
```
→
```php
                : '<a href="' . e(beheer_url('addnews.php?p=' . $i)) . '">' . ($i + 1) . '</a> ';
```

In `admin/poll.php`, vervang de twee voorkomens:

```php
        echo '<td><a href="' . e(url('adm-poll.php?poll=' . $id)) . '">'
```
→
```php
        echo '<td><a href="' . e(beheer_url('poll.php?poll=' . $id)) . '">'
```

```php
    echo '<p><a href="' . e(url('adm-poll.php')) . '">&larr; Terug</a></p>';
```
→
```php
    echo '<p><a href="' . e(beheer_url('poll.php')) . '">&larr; Terug</a></p>';
```

- [ ] **Stap 5: Draai de testen**

```bash
php tests/rook.php
php tests/veiligheid.php
php tests/opbouw.php
```

Expected: alle drie "Alles goed."

- [ ] **Stap 6: Commit**

```bash
git add -A admin inc/beheer.php
git commit -m "refactor: categorie Inhoud verhuisd naar admin/"
```

---

## Task 6: Categorie Spelwereld verplaatsen

Vier bestanden: `adm-items.php`, `adm-drdrpr.php`, `adm-klikmissies.php`,
`adm-getuigen.php`.

**Files:**
- Verplaatsen (git mv): `adm-items.php` → `admin/items.php`,
  `adm-drdrpr.php` → `admin/drdrpr.php`, `adm-klikmissies.php` →
  `admin/klikmissies.php`, `adm-getuigen.php` → `admin/getuigen.php`
- Modify: `inc/beheer.php` (`beheerpaginas()`)
- Modify: `tests/veiligheid.php`

- [ ] **Stap 1: `beheerpaginas()` bijwerken**

Vervang:

```php
        'adm-getuigen.php' => ['Ooggetuigen',     LEVEL_ADMIN],
        'adm-klikmissies.php' => ['Klikmissies',  LEVEL_ADMIN],
```

door:

```php
        'getuigen.php' => ['Ooggetuigen',     LEVEL_ADMIN, 'Spelwereld'],
        'klikmissies.php' => ['Klikmissies',  LEVEL_ADMIN, 'Spelwereld'],
```

en vervang:

```php
        'adm-items.php'    => ['Items',           LEVEL_OWNER],
        'adm-drdrpr.php'   => ['Steden',          LEVEL_OWNER],
```

door:

```php
        'items.php'    => ['Items',           LEVEL_OWNER, 'Spelwereld'],
        'drdrpr.php'   => ['Steden',          LEVEL_OWNER, 'Spelwereld'],
```

- [ ] **Stap 2: Verplaatsen en de require-regel repareren**

```bash
git mv adm-items.php admin/items.php
git mv adm-drdrpr.php admin/drdrpr.php
git mv adm-klikmissies.php admin/klikmissies.php
git mv adm-getuigen.php admin/getuigen.php
```

In elk van deze vier bestanden, vervang `require __DIR__ .
'/inc/bootstrap.php';` door `require __DIR__ . '/../inc/bootstrap.php';`.

- [ ] **Stap 3: Rechtenregel en schil-aanroep per bestand**

In elk bestand, vervang `beheerpaginas()['adm-<naam>.php'][1]` door
`beheerpaginas()['<naam>.php'][1]` (`<naam>` = `items`, `drdrpr`,
`klikmissies`, `getuigen`), vervang

```php
layout_header('Beheer');
beheer_menu($user, 'adm-<naam>.php');
```

door

```php
beheer_header($user, '<naam>.php');
```

en de afsluitende `layout_footer();` door `beheer_footer();`.

- [ ] **Stap 4: Kruislink binnen deze categorie repareren**

In `admin/klikmissies.php` (rond regel 299), vervang:

```php
        echo '<td><a href="' . e(url('adm-klikmissies.php?bewerk=' . $id)) . '">'
```

door:

```php
        echo '<td><a href="' . e(beheer_url('klikmissies.php?bewerk=' . $id)) . '">'
```

- [ ] **Stap 5: `tests/veiligheid.php` bijwerken**

In de rechtentabel, vervang:

```php
    'adm-items.php'    => 'baas',
```
→
```php
    'admin/items.php'  => 'baas',
```

```php
    'adm-getuigen.php' => 'admin',
```
→
```php
    'admin/getuigen.php' => 'admin',
```

```php
    'adm-klikmissies.php' => 'admin',
```
→
```php
    'admin/klikmissies.php' => 'admin',
```

- [ ] **Stap 6: Draai de testen**

```bash
php tests/rook.php
php tests/veiligheid.php
php tests/opbouw.php
```

Expected: alle drie "Alles goed."

- [ ] **Stap 7: Commit**

```bash
git add -A admin inc/beheer.php tests/veiligheid.php
git commit -m "refactor: categorie Spelwereld verhuisd naar admin/"
```

---

## Task 7: Categorie Economie en Communicatie verplaatsen, oude schil opruimen

Twee bestanden: `adm-premium.php` (Economie), `adm-msg.php`
(Communicatie). Na deze taak heeft geen enkel bestand `beheer_menu()` of
`layout_header('Beheer')` meer nodig — die worden opgeruimd.

**Files:**
- Verplaatsen (git mv): `adm-premium.php` → `admin/premium.php`,
  `adm-msg.php` → `admin/msg.php`
- Modify: `inc/beheer.php` (`beheerpaginas()`, `beheer_menu()` verwijderen)
- Modify: `tests/veiligheid.php`
- Modify: `tests/opbouw.php`

- [ ] **Stap 1: `beheerpaginas()` bijwerken**

Vervang de laatste twee overgebleven "adm-"-rijen:

```php
        'adm-premium.php'  => ['Premium',         LEVEL_ADMIN],
```

door:

```php
        'premium.php'  => ['Premium',         LEVEL_ADMIN, 'Economie'],
```

en:

```php
        'adm-msg.php'      => ['Bericht sturen',  LEVEL_ADMIN],
```

door:

```php
        'msg.php'      => ['Bericht sturen',  LEVEL_ADMIN, 'Communicatie'],
```

Na deze stap heeft elke rij in `beheerpaginas()` drie elementen; het
commentaar bij de functie ("categorie ontbreekt bewust bij...") klopt niet
meer — vervang het door:

```php
/**
 * De beheerpagina's: bestand => [label, benodigd niveau, categorie].
 * Wordt gebruikt voor het zijmenu én voor de rechtencontrole per pagina.
 */
```

- [ ] **Stap 2: Verplaatsen en de require-regel repareren**

```bash
git mv adm-premium.php admin/premium.php
git mv adm-msg.php admin/msg.php
```

In beide bestanden, vervang `require __DIR__ . '/inc/bootstrap.php';` door
`require __DIR__ . '/../inc/bootstrap.php';`.

- [ ] **Stap 3: Rechtenregel en schil-aanroep**

In beide bestanden, vervang `beheerpaginas()['adm-premium.php'][1]` /
`beheerpaginas()['adm-msg.php'][1]` door `beheerpaginas()['premium.php'][1]`
/ `beheerpaginas()['msg.php'][1]`, vervang

```php
layout_header('Beheer');
beheer_menu($user, 'adm-premium.php');
```

(resp. `'adm-msg.php'`) door

```php
beheer_header($user, 'premium.php');
```

(resp. `'msg.php'`), en de afsluitende `layout_footer();` door
`beheer_footer();`.

- [ ] **Stap 4: `tests/opbouw.php` bijwerken (de nog openstaande vervanging uit taak 3)**

Vervang beide voorkomens van `'adm-premium.php'` (in de
advertentie-`doe()`-aanroepen, rond regel 58 en 71) door
`'admin/premium.php'`.

- [ ] **Stap 5: `tests/veiligheid.php` bijwerken**

In de rechtentabel, vervang:

```php
    'adm-msg.php'      => 'admin',
```
→
```php
    'admin/msg.php'    => 'admin',
```

```php
    'adm-premium.php'  => 'admin',
```
→
```php
    'admin/premium.php' => 'admin',
```

Verderop, vervang alle drie voorkomens van `'adm-premium.php'` in de
`haal()`- en `tok(haal(...))`-aanroepen (rond regel 190, 191, 201, 215) door
`'admin/premium.php'`. Concreet, vervang:

```php
$tokenAdmin = tok(haal('adm-premium.php', null, $admin)['body']);
haal('adm-premium.php', ['_token' => $tokenAdmin, 'actie' => 'advertentie',
    'html' => '<script>alert(1)</script>', 'interval' => '10'], $admin);
```

door:

```php
$tokenAdmin = tok(haal('admin/premium.php', null, $admin)['body']);
haal('admin/premium.php', ['_token' => $tokenAdmin, 'actie' => 'advertentie',
    'html' => '<script>alert(1)</script>', 'interval' => '10'], $admin);
```

en vervang:

```php
haal('adm-premium.php', ['_token' => $tokenAdmin, 'actie' => 'balans',
    'kans' => '1', 'prijs' => '1', 'kofi' => 'https://voorbeeld.nl'], $admin);
```

door:

```php
haal('admin/premium.php', ['_token' => $tokenAdmin, 'actie' => 'balans',
    'kans' => '1', 'prijs' => '1', 'kofi' => 'https://voorbeeld.nl'], $admin);
```

en vervang:

```php
haal('adm-premium.php', ['_token' => $tokenAdmin, 'actie' => 'dagen',
    'speler2' => 'Speler', 'dagen' => '7'], $admin);
```

door:

```php
haal('admin/premium.php', ['_token' => $tokenAdmin, 'actie' => 'dagen',
    'speler2' => 'Speler', 'dagen' => '7'], $admin);
```

- [ ] **Stap 6: `beheer_menu()` opruimen**

Alle achttien beheerbestanden gebruiken nu `beheer_header()`; niets roept
`beheer_menu()` nog aan. Verwijder de hele functie uit `inc/beheer.php`:

```php
function beheer_menu(array $user, string $huidig): void
{
    echo '<p>';
    echo '<a class="knop' . ($huidig === 'admin.php' ? ' knop-nadruk' : '')
       . '" style="display:inline-block;margin:0 .3rem .3rem 0" href="'
       . e(url('admin.php')) . '">Overzicht</a>';

    foreach (beheerpaginas() as $bestand => [$label, $nodig]) {
        if ((int) $user['level'] < $nodig) {
            continue;
        }
        $actief = $bestand === $huidig ? ' knop-nadruk' : '';
        echo '<a class="knop' . $actief . '" style="display:inline-block;margin:0 .3rem .3rem 0" href="'
           . e(url($bestand)) . '">' . e($label) . '</a>';
    }
    echo '</p>';
}
```

- [ ] **Stap 7: Grep-controle: geen `adm-` en geen oude aanroepen meer over**

```bash
grep -rn "adm-[a-z]" --include=*.php . | grep -v '^./docs/' | grep -v '^./tests/'
grep -rln "beheer_menu(" --include=*.php .
grep -rln "layout_header('Beheer')" --include=*.php .
```

Expected: alle drie commando's geven geen resultaat (op de spec- en
plandocumenten in `docs/` na, die verwijzen er bewust nog naar als
geschiedenis).

- [ ] **Stap 8: Volledige testronde**

```bash
php tests/rook.php
php tests/veiligheid.php
php tests/opbouw.php
php tests/adressen.php
php tests/geld.php
```

Expected: alle vijf "Alles goed."

- [ ] **Stap 9: Commit**

```bash
git add -A admin inc/beheer.php tests/opbouw.php tests/veiligheid.php
git commit -m "refactor: categorie Economie en Communicatie verhuisd, oude beheer_menu() opgeruimd"
```

---

## Task 8: Dashboard-inhoud — nu-cijfers, trends en zelf-gehoste Chart.js

**Files:**
- Modify: `admin/dashboard.php`
- Create: `assets/js/vendor/chart.umd.min.js`
- Create: `assets/js/beheer.js`
- Modify: `tests/veiligheid.php`

**Interfaces:**
- Consumes: tabel `beheer_geschiedenis` (taak 2), `beheer_header()`/
  `beheer_footer()` (taak 3, laadt al `assets/js/vendor/chart.umd.min.js`
  en `assets/js/beheer.js`).

- [ ] **Stap 1: Chart.js zelf-hosten**

Download een stabiele, vastgepinde versie en zet hem in de repo:

```bash
curl -L -o assets/js/vendor/chart.umd.min.js https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js
```

Voeg bovenaan het gedownloade bestand een regel provenance toe (handmatig,
met Write/Edit, niet met een stream-redirect die het bestand overschrijft):

```js
/* Chart.js 4.4.4 (MIT-licentie). Gedownload van jsdelivr.net en hier
   zelf gehost — bewuste uitzondering op de vanilla-JS-regel, zie
   docs/superpowers/specs/2026-09-17-beheerdashboard-design.md. */
```

Controleer dat het bestand niet leeg is en niet met een HTML-foutpagina
begint (bijvoorbeeld een 404 van de CDN):

```bash
head -c 200 assets/js/vendor/chart.umd.min.js
```

Expected: begint met `/*!` of vergelijkbare Chart.js-bannertekst, niet met
`<!DOCTYPE` of `<html`.

- [ ] **Stap 2: Queries voor de "nu"-cijfers en de trend**

In `admin/dashboard.php`, ná het bestaande blok dat `$cijfers` ophaalt (de
kerncijfers-query) en vóór `panel_open('Kerncijfers');`, voeg toe:

```php
$stadRijen = q_all(
    "SELECT `stad`, COUNT(*) AS n FROM `users`
      WHERE `activated` = 1 GROUP BY `stad` ORDER BY n DESC"
);
$stadVerdeling = array_combine(
    array_column($stadRijen, 'stad'),
    array_map('intval', array_column($stadRijen, 'n'))
);

$rangVerdeling = [
    'Speler'    => (int) q_val(
        'SELECT COUNT(*) FROM `users` WHERE `activated` = 1 AND `level` < ?',
        [LEVEL_MODERATOR]
    ),
    'Moderator' => (int) q_val(
        'SELECT COUNT(*) FROM `users` WHERE `activated` = 1 AND `level` >= ? AND `level` < ?',
        [LEVEL_MODERATOR, LEVEL_ADMIN]
    ),
    'Admin'     => (int) q_val(
        'SELECT COUNT(*) FROM `users` WHERE `activated` = 1 AND `level` >= ? AND `level` < ?',
        [LEVEL_ADMIN, LEVEL_OWNER]
    ),
    'Eigenaar'  => (int) q_val(
        'SELECT COUNT(*) FROM `users` WHERE `activated` = 1 AND `level` >= ?',
        [LEVEL_OWNER]
    ),
];

$geschiedenis = q_all(
    "SELECT * FROM `beheer_geschiedenis`
      WHERE `dag` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
   ORDER BY `dag`"
);

$grafiekData = [
    'stad' => $stadVerdeling,
    'rang' => $rangVerdeling,
    'trend' => [
        'dagen'        => array_column($geschiedenis, 'dag'),
        'spelers'      => array_map('intval', array_column($geschiedenis, 'spelers')),
        'geld_totaal'  => array_map('intval', array_column($geschiedenis, 'geld_totaal')),
        'registraties' => array_map('intval', array_column($geschiedenis, 'nieuwe_registraties')),
    ],
];
```

- [ ] **Stap 3: HTML voor de kaarten en de grafiekvakken**

Ná het bestaande blok "Laatste gebeurtenissen" (vóór `beheer_footer();`),
voeg toe:

```php
panel_open('Verdeling nu');
echo '<div class="beheer-grafieken">';
echo '<div class="beheer-grafiek"><h3>Spelers per stad</h3><canvas id="grafiek-stad"></canvas></div>';
echo '<div class="beheer-grafiek"><h3>Spelers per rang</h3><canvas id="grafiek-rang"></canvas></div>';
echo '</div>';
panel_close();

panel_open('Trends (laatste 30 dagen)');
echo '<div class="beheer-grafieken">';
echo '<div class="beheer-grafiek"><h3>Totaal spelers</h3><canvas id="grafiek-spelers"></canvas></div>';
echo '<div class="beheer-grafiek"><h3>Geld in omloop</h3><canvas id="grafiek-geld"></canvas></div>';
echo '<div class="beheer-grafiek"><h3>Nieuwe registraties</h3><canvas id="grafiek-registraties"></canvas></div>';
echo '</div>';
panel_close();

echo '<script type="application/json" id="beheer-data">'
   . json_encode($grafiekData, JSON_HEX_TAG | JSON_HEX_AMP) . '</script>' . "\n";
```

- [ ] **Stap 4: `assets/js/beheer.js` schrijven**

```js
/* Black Vendetta - grafieken op het beheerdashboard.
   Leest de cijfers uit #beheer-data (door de server als veilige JSON
   neergezet) en tekent ze met de zelf-gehoste Chart.js. */

(function () {
    'use strict';

    var blok = document.getElementById('beheer-data');
    if (!blok || typeof Chart === 'undefined') { return; }

    var data;
    try {
        data = JSON.parse(blok.textContent);
    } catch (e) {
        return;
    }

    function basis(legend) {
        return { plugins: { legend: { display: !!legend } } };
    }

    function lijn(id, labels, waarden, kleur) {
        var el = document.getElementById(id);
        if (!el) { return; }
        new Chart(el, {
            type: 'line',
            data: { labels: labels, datasets: [{ data: waarden, borderColor: kleur, tension: .25, pointRadius: 0 }] },
            options: Object.assign(basis(false), { scales: { x: { display: false } } })
        });
    }

    function staaf(id, labels, waarden, kleur) {
        var el = document.getElementById(id);
        if (!el) { return; }
        new Chart(el, {
            type: 'bar',
            data: { labels: labels, datasets: [{ data: waarden, backgroundColor: kleur }] },
            options: basis(false)
        });
    }

    if (data.trend) {
        lijn('grafiek-spelers', data.trend.dagen, data.trend.spelers, '#3ba2f0');
        lijn('grafiek-geld', data.trend.dagen, data.trend.geld_totaal, '#35c17a');
        staaf('grafiek-registraties', data.trend.dagen, data.trend.registraties, '#e8a33d');
    }

    if (data.stad) {
        staaf('grafiek-stad', Object.keys(data.stad), Object.values(data.stad), '#6ec8ff');
    }

    if (data.rang) {
        staaf('grafiek-rang', Object.keys(data.rang), Object.values(data.rang), '#a83246');
    }
}());
```

- [ ] **Stap 5: Test dat de JSON-data geldig en veilig is**

In `tests/veiligheid.php`, voeg aan het eind (vóór `samenvatting();`) een
nieuw blok toe:

```php
// --- Deel 6: het datablok van het beheerdashboard ---------------------------

kop('beheerdashboard: het datablok is geldige, veilig-ingesloten JSON');

$dashboard = haal('admin/dashboard.php', null, $baas)['body'];

check('bevat het datablok', str_contains($dashboard, 'id="beheer-data"'));

preg_match('#<script type="application/json" id="beheer-data">(.*?)</script>#s',
    $dashboard, $m);
$json = $m[1] ?? '';

check('geen letterlijke </script erin', !str_contains($json, '</script'));

$data = json_decode($json, true);
check('is geldige JSON', $data !== null, json_last_error_msg());
check('bevat de drie verwachte sleutels',
    is_array($data) && isset($data['stad'], $data['rang'], $data['trend']));
```

- [ ] **Stap 6: Draai de volledige testronde**

```bash
php tests/rook.php
php tests/veiligheid.php
php tests/opbouw.php
```

Expected: alle drie "Alles goed." (`rook.php` controleert onder meer dat er
geen "Undefined array key" in de pagina staat — dat vangt een `LEVEL_*`
constante die niet bestaat, of een lege `beheer_geschiedenis`-tabel die
`array_column()` op een leeg resultaat verkeerd zou afhandelen; met een
lege tabel geeft `array_column([], ...)` gewoon `[]`, dus dat blijft veilig.)

- [ ] **Stap 7: Commit**

```bash
git add admin/dashboard.php assets/js/vendor/chart.umd.min.js assets/js/beheer.js tests/veiligheid.php
git commit -m "feat: dashboard-grafieken met zelf-gehoste Chart.js"
```

---

## Task 9: Afronding

**Files:**
- Modify: `docs/ARCHITECTUUR.md`

- [ ] **Stap 1: `docs/ARCHITECTUUR.md` een regel geven over `admin/`**

Voeg in de sectie "Het idee in het kort" (na de alinea over "Elke pagina is
één PHP-bestand..."), toe:

```markdown
De beheerpagina's zijn een uitzondering op "alles in de hoofdmap": die staan
in `admin/`, met hun eigen schil (`beheer_header()`/`beheer_footer()` in
`inc/beheer.php`) in plaats van de spelerslay-out uit `inc/layout.php`.
```

- [ ] **Stap 2: Volledige testronde, één keer alles achter elkaar**

Zorg dat de gedeelde testserver en `bv_test` draaien, en:

```bash
for t in rook geld veiligheid opbouw adressen beheergeschiedenis; do php tests/$t.php || break; done
```

Expected: alle zes "Alles goed."

- [ ] **Stap 3: Handmatige steekproef in de browser**

Log in als `Baas` (`baaswachtwoord12345`), open `/admin/dashboard.php` (of
`/admin/dashboard` als `mooie_urls` aanstaat), en controleer met het oog:

- De kopbalk toont "Terug naar het spel", je gebruikersnaam en een
  uitlogknop — geen nieuws/forum/poll-links.
- Het zijmenu toont "Overzicht" plus de vijf categorieën, elk met de juiste
  pagina's.
- De kaarten met kerncijfers en de vijf grafieken tonen cijfers (op een
  verse testdatabase zijn de trendgrafieken leeg totdat
  `beheergeschiedenis.php` of een dag cron heeft gedraaid — dat is
  verwacht, geen fout).
- Op een smal browservenster (of de mobiele weergave in de devtools) werkt
  de hamburgerknop: het zijmenu schuift in als la, precies zoals op de
  spelerspagina's.

- [ ] **Stap 4: Commit**

```bash
git add docs/ARCHITECTUUR.md
git commit -m "docs: admin/ als uitzondering op de platte paginastructuur vermelden"
```
