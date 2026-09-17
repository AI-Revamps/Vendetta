# Beheerdashboard — Ontwerp

## Doel

Het adminpaneel loskoppelen van de spelerslay-out tot een eigen dashboard op
`/admin`: alle beheerfuncties overzichtelijk ingedeeld in categorieën, fysiek
verplaatst naar een eigen map, plus statistieken en grafieken die spelers
niet te zien krijgen.

## Huidige situatie

`admin.php` en de zeventien `adm-*.php`-pagina's delen nu `layout_header()` /
`layout_footer()` met de spelerspagina's: volledige kopbalk (nieuws, forum,
poll, ticket, FAQ), het complete spelmenu plus een los "Beheer"-groepje in het
zijmenu, en het statuspaneel. Daarbovenop tekent elke pagina zelf nog een
platte rij knoppen via `beheer_menu()` om tussen beheerpagina's te wisselen.

Er bestaat geen enkele tabel die cijfers over tijd bijhoudt. `logs` legt losse
gebeurtenissen vast (niet elke soort actie), en `users.start` is het enige
bruikbare tijdstempel voor een trend (registratiedatum).

## Eigen schil voor beheer

`inc/layout.php`: de `<head>`-opbouw (doctype, meta, stylesheet, favicon —
huidige regels 229-239) verhuist naar een kleine interne functie die zowel
`layout_header()` als de nieuwe beheerfunctie gebruiken. Zo blijft er één
plek voor die boilerplate.

`inc/beheer.php` krijgt twee nieuwe functies die `layout_header()` +
`beheer_menu()` + `layout_footer()` vervangen:

- `beheer_header(array $user, string $huidig, string $titel = 'Beheer'): void`
  — eigen kopbalk (merknaam, link "Terug naar het spel" naar `home.php`, naam
  en rol van de ingelogde beheerder, uitlogformulier), gevolgd door een
  zijmenu opgebouwd uit categorieën (zie hieronder) in plaats van de platte
  lijst.
- `beheer_footer(): void` — sluit de inhoud af en laadt de scripts: eerst de
  zelf-gehoste grafiekbibliotheek, dan `assets/js/beheer.js`.

Het zijmenu hergebruikt dezelfde klassen en id's als het spelerszijmenu
(`sidebar`, `zijmenu`, `menu-toggle`, `menu-overlay`) zodat de bestaande
uitklap-logica in `assets/js/app.js` ongewijzigd blijft werken — er is geen
nieuwe JavaScript nodig voor het openklappen op smalle schermen, alleen voor
de grafieken.

`beheerpaginas()` in `inc/beheer.php` krijgt een derde element per rij: de
categorie. Dat blijft zo de ene plek waaruit zowel de rechtencontrole als het
menu wordt opgebouwd — geen aparte lijst om synchroon te houden.

Alle achttien bestanden (`admin.php` + de zeventien `adm-*.php`) krijgen in
dezelfde bewerkingsslag drie wijzigingen: de verplaatsing naar `admin/` (zie
hieronder), en de regel `layout_header('Beheer'); ` +
`beheer_menu($user, '<bestand>');` wordt `beheer_header($user, '<bestand>');`,
met de afsluitende `layout_footer();` naar `beheer_footer();`. Waar
`beheer_start()` gebruikt wordt (nu alleen `adm-online.php`), verandert die
functie zelf vanbinnen mee en hoeft de aanroeper niets aan te passen.

Nieuw stijlblad `assets/css/beheer.css`, alleen geladen op beheerpagina's.
Kleuren en lettertype blijven gedeeld met `style.css`; de kaarten- en
dashboard-indeling staat los van de spelerslay-out.

## Mapstructuur: verplaatsing naar `admin/`

Alle beheerbestanden verhuizen naar een nieuwe map `admin/` in de hoofdmap.
Het voorvoegsel `adm-` vervalt daarbij: de map maakt dat onderscheid al, dus
een los voorvoegsel is dubbelop. `admin.php` zelf wordt **niet**
`admin/index.php` maar `admin/dashboard.php` — een expliciete naam in plaats
van op de map-index leunen. Gevolg: een kale `/admin/` toont niets vanzelf
(geen `DirectoryIndex`-bestand); dat is geen probleem, want de enige weg naar
het dashboard is de nieuwe "Beheer"-link in het spelmenu, die rechtstreeks
naar `admin/dashboard.php` wijst.

| Huidige naam | Nieuwe naam | Categorie |
|---|---|---|
| `admin.php` | `admin/dashboard.php` | Overzicht |
| `adm-search.php` | `admin/search.php` | Spelers |
| `adm-online.php` | `admin/online.php` | Spelers |
| `adm-ban.php` | `admin/ban.php` | Spelers |
| `adm-warn.php` | `admin/warn.php` | Spelers |
| `adm-prison.php` | `admin/prison.php` | Spelers |
| `adm-addmulti.php` | `admin/addmulti.php` | Spelers |
| `adm-bo.php` | `admin/bo.php` | Spelers |
| `adm-addnews.php` | `admin/addnews.php` | Inhoud |
| `adm-poll.php` | `admin/poll.php` | Inhoud |
| `adm-forum.php` | `admin/forum.php` | Inhoud |
| `adm-shame.php` | `admin/shame.php` | Inhoud |
| `adm-items.php` | `admin/items.php` | Spelwereld |
| `adm-drdrpr.php` | `admin/drdrpr.php` | Spelwereld |
| `adm-klikmissies.php` | `admin/klikmissies.php` | Spelwereld |
| `adm-getuigen.php` | `admin/getuigen.php` | Spelwereld |
| `adm-premium.php` | `admin/premium.php` | Economie |
| `adm-msg.php` | `admin/msg.php` | Communicatie |

Wat dat concreet raakt:

- **`require __DIR__ . '/inc/bootstrap.php'`** wordt in elk verplaatst
  bestand `require __DIR__ . '/../inc/bootstrap.php'` — één map dieper.
- **`beheerpaginas()`** in `inc/beheer.php` blijft gesleuteld op de kale
  nieuwe bestandsnaam (`'ban.php'`, niet `'admin/ban.php'`). Dat is
  bewust: `current_page()` (`inc/layout.php`) geeft via `basename()` altijd
  de kale bestandsnaam terug, dus de rechtencontrole en de
  actief-in-het-menu-vergelijking blijven op precies dezelfde manier werken
  als nu, ongeacht de map.
- Nieuwe functie **`beheer_url(string $bestand): string`** in
  `inc/beheer.php`, simpelweg `url('admin/' . $bestand)`. Dit is de enige
  plek die weet dat beheerbestanden in `admin/` staan; alle links naar een
  beheerpagina — het zijmenu in `beheer_header()`, en de kruislinks tussen
  beheerpagina's onderling — gaan hierdoorheen in plaats van rechtstreeks
  `url()` aan te roepen.
- **Bestaande kruislinks** die nu `url('adm-iets.php')` gebruiken
  (`adm-addmulti.php`, `adm-addnews.php`, `adm-forum.php`,
  `adm-klikmissies.php`, `adm-online.php`, `adm-poll.php`, `adm-prison.php`,
  `adm-search.php`, en de link naar `admin.php` in `inc/beheer.php`) worden
  `beheer_url('iets.php')`.
- **Het spelmenu** (`menu_groups()` in `inc/layout.php`) verliest de huidige
  platte `'Beheer'`-groep met vijftien losse links. Daarvoor in de plaats komt
  één link voor `LEVEL_MODERATOR` en hoger: `'Beheer' => ['admin/dashboard.php'
  => 'Beheerdashboard']`. Spelers die geen staflid zijn, zien deze groep
  helemaal niet — zoals nu ook al het geval is.

## Indeling: categorieën in het beheerzijmenu

| Categorie | Pagina's (nieuwe namen) |
|---|---|
| Overzicht | `dashboard.php` (los bovenaan, geen groep) |
| Spelers | `search.php`, `online.php`, `ban.php`, `warn.php`, `prison.php`, `addmulti.php`, `bo.php` |
| Inhoud | `addnews.php`, `poll.php`, `forum.php`, `shame.php` |
| Spelwereld | `items.php`, `drdrpr.php`, `klikmissies.php`, `getuigen.php` |
| Economie | `premium.php` |
| Communicatie | `msg.php` |

## Dashboard: `admin/dashboard.php` wordt het overzicht

**Nu-cijfers** (geen nieuwe tabel nodig, rechtstreeks uit bestaande
tabellen):

- De bestaande kerncijfers-tabel blijft, in de kaartenstijl van
  `beheer.css`.
- Nieuw: spelers per stad (staafdiagram, uit `users.stad`).
- Nieuw: spelers per rang — speler / moderator / admin / eigenaar
  (staafdiagram, uit `users.level`).

**Trends over de laatste 30 dagen** (uit `beheer_geschiedenis`, zie
hieronder):

- Totaal spelers (lijn).
- Geld in omloop (lijn).
- Nieuwe registraties per dag (staaf).

De lijst "Laatste gebeurtenissen" (uit `logs`) blijft ongewijzigd staan.

## Datamodel: `beheer_geschiedenis`

Eén rij per dag, geschreven door een nieuwe periodieke taak.

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

Voor een al draaiende installatie: dit project heeft geen migratiesysteem,
`install/schema.sql` is de enige bron. Op een bestaande database volstaat het
om exact bovenstaand `CREATE TABLE IF NOT EXISTS`-statement handmatig via
phpMyAdmin uit te voeren.

## Periodieke taak: `cron_geschiedenis_bijwerken()`

Nieuw in `inc/cron.php`, naast de zes bestaande taken. Interval 86400
seconden (1 dag), zelfde `GET_LOCK` + claim-patroon via de tabel `cron` als
de andere taken.

```sql
INSERT INTO `beheer_geschiedenis`
    (`dag`, `spelers`, `levend`, `online`, `geld_totaal`,
     `nieuwe_registraties`, `vast`, `bans_totaal`, `families`)
VALUES (CURDATE(), :spelers, :levend, :online, :geld,
        :nieuw, :vast, :bans, :families)
ON DUPLICATE KEY UPDATE
    `spelers` = :spelers, `levend` = :levend, `online` = :online,
    `geld_totaal` = :geld, `nieuwe_registraties` = :nieuw,
    `vast` = :vast, `bans_totaal` = :bans, `families` = :families
```

Bronwaarden: `spelers` = `COUNT(*)` waar `activated = 1`; `levend` = idem met
`status = 'levend'`; `online` = `COUNT(*)` waar `online` binnen 15 minuten;
`geld_totaal` = `SUM(zak) + SUM(bank)`; `nieuwe_registraties` = `COUNT(*)`
waar `DATE(start) = CURDATE()`; `vast` = `COUNT(*)` uit `jail` waar
`time > NOW()`; `bans_totaal` = `COUNT(*)` uit `bans`; `families` =
`COUNT(*)` uit `famillie`. Dezelfde queries als in `admin.php` vandaag,
hergebruikt in plaats van opnieuw uitgevonden.

`ON DUPLICATE KEY UPDATE` maakt de taak idempotent: draait hij per ongeluk
twee keer op dezelfde dag (bijvoorbeeld bij `cron_mode = request` met veel
gelijktijdige bezoekers rond middernacht), dan overschrijft de tweede run de
eerste in plaats van een dubbele rij te maken.

## Grafiektechniek: Chart.js, zelf gehost — bewuste afwijking

`CLAUDE.md` legt vast: "Geen frameworks... alleen PHP, MySQL en een handvol
regels vanilla JavaScript." Voor dit onderdeel is in overleg gekozen voor een
expliciete, gedocumenteerde uitzondering: vijf grafieken met de hand in
inline SVG opbouwen kost voor een eerste versie onevenredig veel tijd
tegenover de winst.

- Het bestand (bijv. `chart.umd.min.js`, versie vastgelegd in een commentaar)
  wordt éénmalig gedownload en toegevoegd aan `assets/js/vendor/` — in de
  repo, niet via een live CDN-link. Dat voorkomt dat de grafieken kapotgaan
  als een externe CDN eruit ligt of geblokkeerd wordt, en past bij "uploaden
  per FTP, geen bouwstap": het is gewoon een bestand dat meegaat.
  Dit is de enige plek in de codebase met een externe bibliotheek; nergens
  anders wijkt dit ontwerp van de vanilla-JS-regel af.
- `assets/js/beheer.js` (nieuw, handgeschreven) leest de cijfers uit een
  `<script type="application/json" id="beheer-data">`-blok dat de PHP-pagina
  vult met `json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP)`, en tekent
  daarmee de canvassen. Geen data in HTML-attributen, geen inline
  event-handlers.

## Beveiliging (toetsing aan de zes regels)

1. Alle nieuwe queries (dashboardcijfers, cron-taak) gebruiken
   plaatshouders; geen van alle bevat spelerinvoer.
2. Dit ontwerp voegt geen nieuwe POST-acties toe. Bestaande `csrf_check()`-
   aanroepen in de adm-pagina's blijven ongewijzigd.
3. Geen geldmutaties — alleen leesqueries voor statistieken. `afboeken()` /
   `bijschrijven()` zijn niet van toepassing.
4. De JSON-data voor de grafieken gaat met `JSON_HEX_TAG | JSON_HEX_AMP`
   naar een `<script type="application/json">`-blok, nooit ongeëscapet in
   HTML. `require_level()` blijft per pagina de toegangscontrole, nu
   aangeroepen vanuit `beheer_header()` in plaats van los in elk bestand.
5. Geen nieuwe POSTs, dus geen nieuw `redirect()`-gedrag nodig.
6. Alle nieuwe en aangepaste PHP-bestanden via Write/Edit, nooit PowerShell.

## Reikwijdte

**Wel:** verplaatsing naar `admin/` zonder `adm-`-voorvoegsel, eigen
adminschil (`beheer_header()`/`beheer_footer()`), indeling in categorieën,
uitgebreide nu-cijfers, nieuwe trendtabel plus cron-taak, vijf grafieken op
`admin/dashboard.php`, zelf-gehoste Chart.js.

**Niet:**

- Geen migratiesysteem — alleen deze ene tabel, handmatig toe te voegen aan
  een bestaande installatie.
- Geen omzet-/diamantentrend: de tabel `donate` heeft geen tijdstempel, dus
  dat cijfer is nu niet reconstrueerbaar. Latere uitbreiding als daar
  behoefte aan is.
- Geen herschrijving van de inhoud van de individuele `adm-*.php`-pagina's
  zelf — alleen de schil eromheen en het nieuwe dashboard.
- Geen wijziging aan `mooie_urls`; `/admin` werkt al zodra die instelling
  aanstaat, dat hoeft niet aangepast te worden.
- Geen extra mobiele herontwerp buiten het hergebruiken van het bestaande
  uitklap-patroon.
- Geen `admin/index.php`: een kale `/admin/` toont niets, alleen
  `/admin/dashboard` (of met `.php`) doet dat. Bewuste keuze, zie boven.

## Testen

`tests/rook.php`, `tests/adressen.php` en `tests/veiligheid.php` sommen nu
pagina's op met `glob(BV_WORTEL . '/*.php')` — alleen de hoofdmap. Dat wordt
`array_merge(glob(BV_WORTEL . '/*.php'), glob(BV_WORTEL . '/admin/*.php'))`
(met de bestandsnaam voortaan relatief, dus `admin/ban.php` in plaats van
`ban.php`, zodat `haal()` het juiste pad opvraagt). Zonder deze aanpassing
zouden alle beheerpagina's stilzwijgend uit deze tests verdwijnen.

- `tests/rook.php`: alle bestanden in `admin/` moeten schoon laden met de
  nieuwe schil, net als `dashboard.php` zelf.
- `tests/opbouw.php`: de letterlijke verwijzingen `'adm-premium.php'` en
  `'adm-search.php'` worden `'admin/premium.php'` / `'admin/search.php'`;
  nieuw geval voor de beheerschil (geen statuspaneel, geen onderbalk, wel
  het beheer-zijmenu met categorieën) naast de bestaande
  spelerstoestanden.
- `tests/veiligheid.php`: dezelfde naamswijziging in de rechtentabel
  (`'adm-online.php' => 'mod'` wordt `'admin/online.php' => 'mod'`,
  enzovoort) en in elke `haal('adm-...', ...)`-aanroep. `require_level()`
  blijft afgedwongen per pagina via `beheer_header()`; het JSON-datablok op
  `dashboard.php` bevat geen ongeëscapete tekst.
- Nieuwe controle (uitbreiding van een testbestand of een nieuw
  `tests/beheergeschiedenis.php`): `cron_geschiedenis_bijwerken()` twee keer
  kort na elkaar aanroepen levert precies één rij op voor vandaag, en de
  waarden in die rij kloppen met een losse controlequery op hetzelfde
  moment.
- `tests/adressen.php`: de verplaatste beheeradressen blijven zonder `.php`
  bereikbaar (bijv. `/admin/ban`).
