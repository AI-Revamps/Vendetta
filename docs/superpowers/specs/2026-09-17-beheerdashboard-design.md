# Beheerdashboard — Ontwerp

## Doel

Het adminpaneel loskoppelen van de spelerslay-out tot een eigen dashboard op
`/admin`: alle beheerfuncties overzichtelijk ingedeeld in categorieën, plus
statistieken en grafieken die spelers niet te zien krijgen.

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

Alle achttien bestanden (`admin.php` + de zeventien `adm-*.php`) krijgen
dezelfde mechanische aanpassing: de regel `layout_header('Beheer'); ` +
`beheer_menu($user, '<bestand>');` wordt `beheer_header($user, '<bestand>');`,
en de afsluitende `layout_footer();` wordt `beheer_footer();`. Waar
`beheer_start()` gebruikt wordt (nu alleen `adm-online.php`), verandert die
functie zelf vanbinnen mee en hoeft de aanroeper niets aan te passen.

Nieuw stijlblad `assets/css/beheer.css`, alleen geladen op beheerpagina's.
Kleuren en lettertype blijven gedeeld met `style.css`; de kaarten- en
dashboard-indeling staat los van de spelerslay-out.

## Indeling: categorieën

| Categorie | Pagina's |
|---|---|
| Overzicht | `admin.php` (los bovenaan, geen groep) |
| Spelers | `adm-search`, `adm-online`, `adm-ban`, `adm-warn`, `adm-prison`, `adm-addmulti`, `adm-bo` |
| Inhoud | `adm-addnews`, `adm-poll`, `adm-forum`, `adm-shame` |
| Spelwereld | `adm-items`, `adm-drdrpr`, `adm-klikmissies`, `adm-getuigen` |
| Economie | `adm-premium` |
| Communicatie | `adm-msg` |

## Dashboard: `admin.php` wordt het overzicht

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

**Wel:** eigen adminschil (`beheer_header()`/`beheer_footer()`), indeling in
categorieën, uitgebreide nu-cijfers, nieuwe trendtabel plus cron-taak, vijf
grafieken op `admin.php`, zelf-gehoste Chart.js.

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

## Testen

- `tests/rook.php`: `admin.php` en alle `adm-*.php` moeten schoon laden met
  de nieuwe schil.
- `tests/opbouw.php`: nieuw geval voor de beheerschil (geen statuspaneel,
  geen onderbalk, wel het beheer-zijmenu met categorieën) naast de
  bestaande spelerstoestanden.
- `tests/veiligheid.php`: `require_level()` blijft afgedwongen per pagina
  via `beheer_header()`; het JSON-datablok op `admin.php` bevat geen
  ongeëscapete tekst.
- Nieuwe controle (uitbreiding van een testbestand of een nieuw
  `tests/beheergeschiedenis.php`): `cron_geschiedenis_bijwerken()` twee keer
  kort na elkaar aanroepen levert precies één rij op voor vandaag, en de
  waarden in die rij kloppen met een losse controlequery op hetzelfde
  moment.
- `tests/adressen.php`: bestaande beheeradressen blijven zonder `.php`
  bereikbaar.
