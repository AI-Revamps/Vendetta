# Architectuur

Hoe de code in elkaar zit, en waarom op deze manier.

## Het idee in het kort

Elke pagina is één PHP-bestand in de hoofdmap. Dat bestand laadt
`inc/bootstrap.php`, doet zijn werk, en tekent zichzelf met de bouwstenen uit
`inc/layout.php`. Er is geen router en geen framework.

De beheerpagina's zijn een uitzondering op "alles in de hoofdmap": die staan
in `admin/`, met hun eigen schil (`beheer_header()`/`beheer_footer()` in
`inc/beheer.php`) in plaats van de spelerslay-out uit `inc/layout.php`.

Een typische pagina ziet er zo uit:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

$user = require_login();

if (is_post()) {
    csrf_check();
    try {
        $melding = iets_doen($user, post('veld'));
        $type    = 'ok';
        $user    = current_user(true);      // opnieuw ophalen na een wijziging
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Titel');
if ($melding !== null) { notice(e($melding), $type); }

panel_open('Kopje');
// ... uitvoer ...
panel_close();

layout_footer();
```

Dat patroon herhaalt zich over alle 68 pagina's. Wie er één begrijpt, begrijpt
ze allemaal.

## De fundering: `/inc`

Elk bestand hierin begint met `defined('BV_INC') || exit;`. De map is
afgeschermd met een `.htaccess`, maar dat werkt niet op nginx en niet bij de
ingebouwde PHP-server — die regel is het tweede slot.

| Bestand | Waarvoor |
|---|---|
| `bootstrap.php` | het startpunt van elke pagina: foutafhandeling, headers, sessie, bancontrole, cron, advertenties |
| `config.php` | jouw instellingen. Staat in `.gitignore`; `config.sample.php` is het voorbeeld |
| `db.php` | `q()`, `q_row()`, `q_all()`, `q_val()`, `q_count()`, `db_transaction()` |
| `helpers.php` | `e()`, `money()`, `num()`, `url()`, `post()`, `redirect()`, `config()` |
| `auth.php` | inloggen, sessies, CSRF-tokens, rechten, bans |
| `game.php` | spelregels: rangen, afkoeltijden, gevangenis, geld, huizen, `SpelFout` |
| `layout.php` | de pagina eromheen: kopbalk, menu, panelen, statuspaneel, onderbalk |
| `combat.php` | gevechten, sterven, erfenis, ooggetuigen |
| `handel.php` | drank en drugs |
| `casino.php` | het gedeelde deel van de gokspellen |
| `familie.php` | families en promotiegeld |
| `premium.php` | diamanten, premium, advertenties |
| `beheer.php` | rechtentabel en menu voor de beheerpagina's |
| `opmaak.php` | spelerstekst omzetten naar veilige HTML |
| `captcha.php` | beveiligingscode |
| `mail.php` | systeemmail en eenmalige codes |
| `cron.php` | de periodieke taken |

## Regels die overal gelden

**Elke query gaat via `inc/db.php`.** Prepared statements zonder emulatie
(`ATTR_EMULATE_PREPARES => false`). Let op: een benoemde plaatshouder mag dan
niet hergebruikt worden — gebruik `:a`, `:b`, `:c` in plaats van drie keer
dezelfde naam.

**Elke uitvoer gaat door `e()`.** Spelerstekst met opmaak gaat door
`bericht_html()` uit `inc/opmaak.php`: dat escapet éérst alles en zet daarna
alleen de opmaak terug die het zelf kent.

**Elke handeling is een POST met een token.** `csrf_check()` bovenaan, en het
formulier krijgt `csrf_field()`. Er staat niets meer achter een GET-link wat
iets verandert.

**Geld beweegt alleen binnen een transactie**, en de saldocontrole zit ín de
UPDATE:

```php
db_transaction(function () use ($user, $bedrag) {
    $speler = lock_user((int) $user['id']);        // SELECT ... FOR UPDATE

    if (!afboeken((int) $speler['id'], $bedrag)) { // WHERE zak >= ?
        throw new SpelFout('Je hebt niet genoeg geld.');
    }
    // ...
});
```

Zo zit er geen gat tussen kijken of het geld er is en het afschrijven. Twee
gelijktijdige verzoeken kunnen niet allebei slagen.

**`SpelFout` is voor fouten die de speler moet zien.** Gooien draait de
transactie terug en de pagina toont de melding. Alles wat de speler niet kan
oplossen hoort geen `SpelFout` te zijn.

## De database

40 tabellen, InnoDB en `utf8mb4`. Het schema staat in `install/schema.sql` —
bewust in die map, zodat het samen met de installer verdwijnt.

**Spelers worden gekoppeld op `login`, niet op `id`.** Dat is een bewuste
keuze: 25 tabellen verwijzen naar spelers via hun naam, en dat omzetten zou de
herbouw verdubbeld hebben zonder dat een speler er iets van merkt. Gevolg:
gebruikersnamen zijn onveranderlijk.

**`level` is een getal**: 1 speler, 200 moderator, 255 admin, 1000 eigenaar. Er
zijn constanten voor (`LEVEL_SPELER` enzovoort). Geen rollentabel, omdat de
waarde ook spelgedrag aanstuurt.

**Bedragen zijn `bigint`.** Geen floats bij geld.

## Instellingen: twee soorten

| | Waar | Wanneer |
|---|---|---|
| `inc/config.php` | bestand op de server | alles wat bij de installatie hoort: database, adres, cron, steden |
| tabel `instellingen` | database | wat een beheerder tijdens het spelen wil omzetten: advertenties, getuigenwijze, diamantkans |

Lezen doe je met `config('game.cities')` respectievelijk `instelling('ads_html')`.
De tweede valt terug op een standaardwaarde in de code, dus een ontbrekende rij
breekt niets.

## De periodieke taken

`inc/cron.php` bevat zes taken met elk een eigen interval. Ze draaien op twee
manieren, in te stellen met `cron_mode`:

- `request` — meeliftend op gewone paginabezoeken. Werkt altijd, ook zonder
  cronjob-ondersteuning.
- `cron` — via een echte cronjob die `cron.php` aanroept.

Een `GET_LOCK` plus een claim-`UPDATE` zorgen dat een taak nooit dubbel draait,
ook niet bij veel gelijktijdige bezoekers.

## De pagina eromheen

`layout_header()` tekent alles tot en met de opening van de inhoud,
`layout_footer()` de rest. Daartussen gebruik je:

| Functie | Wat |
|---|---|
| `panel_open($titel, $id = '')` | opent een kader; `$id` maakt er een anker van |
| `panel_close()` | sluit het |
| `notice($tekst, $type)` | melding: `ok`, `fout` of `info` |

De klassen in de HTML liggen vast en worden overal hetzelfde gebruikt:
`veldenraster` voor formulieren, `tabelwikkel` + `table.lijst` voor tabellen,
`getal` voor rechts uitgelijnde cijfers, `knop` voor een link die als knop
oogt, `uitleg` voor kleine grijze tekst. Daardoor verandert het uiterlijk van
alle pagina's mee met alleen de stylesheet.

## Mobiel

Onder 760 pixels verandert de indeling van drie kolommen naar één:

- een **statusstrook** onder de kopbalk met gezondheid, geld, kogels en diamanten
- een **onderbalk** met vijf tabs
- het zijmenu wordt een **la** die van links inschuift

Dat zit allemaal in `inc/layout.php` en de mediaregels onderaan
`assets/css/style.css`. Er is geen aparte mobiele versie.

## Adressen zonder .php

Alle links lopen door `url()` in `inc/helpers.php`. Staat `mooie_urls` aan, dan
haalt die functie de extensie eruit en zorgt een regel in `.htaccess` dat
`/home` bij `home.php` uitkomt. Adressen mét `.php` blijven altijd werken.

Belangrijk: `current_page()` leest `SCRIPT_NAME`, niet de adresbalk. Anders zou
het menu met mooie adressen niet meer weten op welke pagina je bent.

## Wat er bewust niet in zit

- **Geen betaalprovider.** Premiumcodes maak je met de hand aan;
  `premium_code_maken()` in `adm-premium.php` is het aanknopingspunt.
- **Geen lichte weergave.** Eén set kleuren.
- **Geen build-stap.** Wat in de repo staat is wat er draait.
