# Klikmissies — Ontwerp

## Doel

Spelers een reden geven om de website te promoten via externe stemsites
("toplijsten"). Een admin beheert een lijst van links; per link staan een
cooldown en een beloning (zak, bank en/of diamanten) vast. Sommige stemsites
melden een stem automatisch terug (callback); andere niet, en daar moet de
speler zelf bevestigen dat hij gestemd heeft.

## Terminologie

De speler ziet dit onder de naam **Klikmissies**. Interne namen volgen
hetzelfde woord: tabellen `klikmissies` / `klikmissies_log`, pagina's
`klikmissies.php` en `adm-klikmissies.php`, eindpunt
`klikmissies-callback.php`.

## Twee stemstromen

**Met callback** (`heeft_callback = 1`):
1. Speler klikt op de kale, uitgaande link (`target="_blank"`) naar de
   stemsite. De link bevat zijn `login` als parameter (plaatshouder
   `{login}` in de opgeslagen `url`, vervangen en `e()`-veilig weergegeven).
   Dit is geen handeling op ons systeem, dus een gewone `<a>` volstaat.
2. De speler stemt op de externe site.
3. Die site roept zelf `klikmissies-callback.php?id=&geheim=&login=` aan.
   Wij controleren het geheime token, de cooldown, en schrijven de beloning
   direct bij.

**Zonder callback** (`heeft_callback = 0`):
1. Speler klikt op een POST-knop "Stem" (met `csrf_field()`) op
   `klikmissies.php`. De handler zet `$_SESSION['klikmissie_klik'][$id] =
   time()` en doet daarna `redirect()` naar de externe URL — dezelfde tab
   navigeert dus weg naar de stemsite.
2. Speler stemt daar en gaat met de terugknop van de browser terug naar
   `klikmissies.php`.
3. Zolang de wachttijd (`wachttijd_klik` seconden na de sessie-timestamp)
   nog niet voorbij is, toont de pagina een afteller met het bestaande
   `data-tot="<unix-tijd>"`-mechanisme uit `assets/js/app.js` — dat telt al
   af én herlaadt de pagina vanzelf zodra de teller nul bereikt. Geen
   nieuwe JavaScript nodig.
4. Na het verstrijken verschijnt de POST-knop "Ik heb gestemd". De handler
   controleert **server-side** opnieuw of de sessie-timestamp bestaat, of
   de wachttijd echt voorbij is, en of de cooldown voorbij is — een
   speler kan de aftelling in de browser niet omzeilen door de knop met
   devtools vroeger te activeren. Bij succes: beloning bijschrijven, rij
   in `klikmissies_log`, sessie-status wissen, `redirect()` met
   bevestigingsmelding.

## Datamodel (`install/schema.sql`)

```sql
-- Door de admin beheerde links naar externe toplijsten/stemsites, met een
-- optionele automatische callback, een afkoeltijd en een beloning.
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

-- Logboek van uitgekeerde klikmissie-beloningen. Bepaalt de cooldown per
-- speler per missie en dient als overzicht voor de admin.
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
```

De drie beloningsvelden zijn onafhankelijke bedragen (standaard 0). Eén
link kan dus alleen diamanten geven, alleen cash, of een combinatie — er is
geen apart "type"-veld nodig.

`beloning_zak`/`beloning_bank` zijn `bigint` zonder `unsigned`, net als
`users.zak`/`users.bank`. `beloning_diamanten` is `int unsigned`, net als
`users.diamanten`.

## Spelfuncties (nieuw bestand `inc/klikmissies.php`)

Volgt het patroon van `inc/premium.php`. Kernfuncties:

- `klikmissies_actief(): array` — alle actieve missies, gesorteerd op
  `volgorde`.
- `klikmissie_cooldown_tot(int $id, string $login): int` — unix-tijdstip
  waarop de cooldown voor deze speler/missie afloopt (0 als hij nu al mag),
  gebaseerd op `MAX(tijd)` uit `klikmissies_log` voor die combinatie. Dit is
  de weergave-check voor de pagina (wel/niet de knop tonen) — **niet** de
  beveiliging tegen dubbel belonen.
- `klikmissie_belonen(array $missie, string $login, string $methode, string $ip): void`
  — binnen `db_transaction()`: eerst `lock_user_by_login($login)`, dán pas
  (ná de lock, dus met de nieuwste gecommitte data) de cooldown herchecken
  en bij overtreding een `SpelFout` gooien. Pas daarna
  `beloning_zak`/`beloning_bank` bijschrijven via `bijschrijven()`,
  `beloning_diamanten` via `diamanten_bijschrijven()`, en de logregel
  toevoegen. Zonder deze volgorde zouden twee gelijktijdige aanroepen voor
  dezelfde speler/missie (twee callbacks vlak na elkaar, of een callback
  samen met een zelf-bevestiging) elkaar allebei vóór zijn en dubbel
  belonen — de `FOR UPDATE`-lock op de spelersrij dwingt af dat de tweede
  aanroep wacht tot de eerste klaar is, en dan de bijgewerkte cooldown ziet.
  Wordt zowel door `klikmissies.php` (methode `zelf`) als door
  `klikmissies-callback.php` (methode `callback`) aangeroepen, zodat de
  transactiegrens op één plek staat.

## Adminpagina: `adm-klikmissies.php`

Toegevoegd aan `beheerpaginas()` in `inc/beheer.php` op `LEVEL_ADMIN`,
label "Klikmissies". Gebruikt `beheer_start()`, `panel_open()`/
`panel_close()`, zoals de andere `adm-*.php`-pagina's.

- Lijst van alle missies: naam, actief/inactief, callback aan/uit,
  cooldown, beloning, volgorde.
- Formulier voor toevoegen/bewerken: naam, omschrijving, url (met uitleg
  over de `{login}`-plaatshouder), callback aan/uit, wachttijd (alleen
  relevant zonder callback), cooldown, drie beloningsvelden, volgorde,
  actief.
- Bij `heeft_callback = 1`: toont de kant-en-klare callback-URL
  (`https://.../klikmissies-callback.php?id=<id>&geheim=<token>&login=`)
  om in het paneel van de stemsite te plakken, plus een knop "Nieuw
  geheim genereren" (bv. bij een lek) — beide als POST met csrf.
- Verwijderen van een missie: POST met bevestiging, nooit via een link.
- `callback_geheim` wordt bij aanmaken gevuld met `bin2hex(random_bytes(32))`.

## Spelerspagina: `klikmissies.php`

Toegevoegd aan de menugroep "Status" in `menu_groups()`
(`inc/layout.php`), na "Premium". Per actieve missie:

- Naam, omschrijving.
- Bij callback: kale uitgaande link + "Beloning komt automatisch na het
  stemmen."
- Bij geen callback: de "Stem"-knop, of — als er een sessie-klik loopt of
  de cooldown nog niet voorbij is — de afteller resp. de
  bevestigingsknop, zoals hierboven beschreven.
- Als de cooldown nog loopt (ongeacht methode): "Nog beschikbaar over
  ..." met dezelfde `data-tot`-afteller, geen knop.

## Callback-eindpunt: `klikmissies-callback.php`

Geen `require_login()` — dit bestand wordt door de stemsite zelf
aangeroepen, niet door een ingelogde speler. Wel `require
__DIR__ . '/inc/bootstrap.php'`.

Verwacht GET-parameters `id`, `geheim`, `login`. Stappen:
1. Missie ophalen op `id`; bestaat hij niet of is hij niet actief →
   platte tekstrespons `FOUT: onbekende missie`.
2. `hash_equals($missie['callback_geheim'], $geheim)` — bij mismatch
   `FOUT: ongeldig token`. Dit is de enige verificatie; geen extra
   snelheidsbeperking nodig omdat het token 32 willekeurige bytes is en
   dus niet te raden.
3. Speler opzoeken op `login`; bestaat hij niet → `FOUT: onbekende
   speler`.
4. `klikmissie_belonen($missie, $login, 'callback', $_SERVER['REMOTE_ADDR'] ?? '')`
   binnen een `try`/`catch (SpelFout $e)`. Deze functie doet de
   authoritatieve cooldown-check zelf (zie hierboven) — bij een `SpelFout`
   (cooldown nog actief) is de respons `FOUT: cooldown actief`.
5. Bij succes: respons `OK`.

Platte tekst, geen HTML-pagina — dit is een machine-naar-machine
eindpunt.

## Menu-badge: beschikbare klikmissies

`status_summary()` (`inc/layout.php`) krijgt een extra subquery die telt
hoeveel actieve missies voor deze speler *niet* in cooldown zitten:

```sql
(SELECT COUNT(*) FROM `klikmissies` k
  WHERE k.`actief` = 1
    AND NOT EXISTS (
      SELECT 1 FROM `klikmissies_log` l
       WHERE l.`klikmissie_id` = k.`id` AND l.`login` = :login
         AND l.`tijd` > DATE_SUB(NOW(), INTERVAL k.`cooldown_seconden` SECOND)
    )
) AS klikmissies_beschikbaar
```

In `menu_groups()` krijgt het label "Klikmissies" hetzelfde
`<span class="bolletje">`-badge-patroon als "Berichten" wanneer dit
aantal groter dan 0 is. Geen aparte query: deze schuift mee in de al
bestaande, éénmalig per verzoek uitgevoerde `status_summary()`-query.

## help.php

Nieuwe sectie die uitlegt wat klikmissies zijn, dat de beloning per link
kan verschillen, en dat sommige links een wachttijd hebben na het
klikken. Geen concrete bedragen hardcoden — die staan al in de database
en veranderen per link.

## Beveiliging (toetsing aan de zes regels)

1. Geen variabelen in queries — alle nieuwe queries via plaatshouders.
2. `csrf_check()` op de admin-formulieren en op de "Stem"/"Ik heb
   gestemd"-POSTs in `klikmissies.php`. Het callback-eindpunt heeft geen
   sessie/CSRF-token (kan het ook niet, want het wordt door een externe
   server aangeroepen) en leunt in plaats daarvan op het geheime token.
3. Geldmutaties uitsluitend via `klikmissie_belonen()`, binnen
   `db_transaction()` + `lock_user_by_login()`, met `bijschrijven()` /
   `diamanten_bijschrijven()` — nooit een losse `UPDATE`.
4. `url`, `naam`, `omschrijving` van een missie zijn admin-ingevoerd, niet
   spelerinput, maar gaan bij weergave alsnog door `e()` — geen reden om
   ze te vertrouwen alleen omdat ze uit de database komen.
5. Elke POST eindigt in `redirect()`.
6. Bewerking van alle nieuwe PHP-bestanden via Write/Edit, nooit
   PowerShell.

## Reikwijdte

**Wel:** admin-CRUD, speler-pagina met beide stemstromen, callback-
eindpunt, menu-integratie met badge, help.php-update.

**Niet:**
- Geen statistiekendashboard verder dan de simpele lijst in
  `klikmissies_log` — als daar later behoefte aan is, is dat een
  vervolgstap.
- Geen periodieke/maandelijkse ranglijst-beloning voor de speler die het
  vaakst stemt — alleen de directe beloning per stem.
- Geen rate-limiting op het callback-eindpunt anders dan het geheime
  token — bij misbruik kan de admin het token vernieuwen.
- Geen ondersteuning voor callbacks die als POST (in plaats van GET)
  binnenkomen; `klikmissies-callback.php` leest de parameters via
  `$_GET` zoals de meeste toplijst-callbacks werken. Blijkt een concrete
  stemsite alleen POST te sturen, dan is dat een kleine vervolgaanpassing.

## Testen

- `tests/rook.php`: `klikmissies.php` en `adm-klikmissies.php` moeten
  schoon laden.
- `tests/geld.php`: een klikmissie-beloning (zak, bank én diamanten)
  verhoogt het saldo met precies het ingestelde bedrag, niet meer en niet
  minder; twee gelijktijdige bevestigingen voor dezelfde speler/missie
  binnen de cooldown mogen niet allebei belonen.
- `tests/veiligheid.php`: een callback met een fout geheim wijzigt niets;
  `adm-klikmissies.php` is niet bereikbaar onder `LEVEL_ADMIN`; de
  "Stem"/"Ik heb gestemd"-POSTs weigeren zonder geldig CSRF-token.
- `tests/adressen.php`: de nieuwe pagina's zonder `.php` bereikbaar.
