# Black Vendetta — voor wie hier begint

Een Nederlands tekst-rollenspel in de onderwereld, volledig herbouwd vanaf een
versie uit ongeveer 2005. Draait op gewone shared hosting.

**Lees eerst [docs/ARCHITECTUUR.md](docs/ARCHITECTUUR.md).** Dat is niet lang en
het bespaart je het verkeerde patroon.

## De harde eisen

Deze staan vast en zijn niet ter discussie:

1. **Geen frameworks, geen Composer, geen bouwstap.** Alleen PHP, MySQL en een
   handvol regels vanilla JavaScript. Als iets een `composer require` nodig
   heeft, is het het antwoord niet.
2. **Moet werken op shared hosting.** Uploaden per FTP, database via phpMyAdmin.
   Geen SSH, geen shell, geen schrijfrechten buiten de webmap.
3. **Eén bestand per pagina, geen router.** Wat in de adresbalk staat, is een
   bestand op schijf.
4. **Alles in het Nederlands.** Code, commentaren, commitberichten,
   documentatie, foutmeldingen en kolomnamen. Dat is een bewuste keuze.

## Waar wat staat

```
/                 de ~70 pagina's van het spel
/inc              bootstrap, database, sessies, opmaak, spellogica, layout
/install          installer plus schema.sql (40 tabellen)
/assets           style.css, app.js, logo
/docs             documentatie
/tests            testscripts, met LEESMIJ.md
```

De belangrijkste bestanden in `/inc`:

| Bestand | Waarvoor |
|---|---|
| `bootstrap.php` | wat elke pagina als eerste laadt |
| `db.php` | PDO, `q()`, `q_row()`, `db_transaction()`, `lock_user()` |
| `auth.php` | inloggen, `require_login()`, `csrf_check()`, bans |
| `game.php` | de spelregels: rangen, geld, huizen, cooldowns, sterven |
| `layout.php` | de paginaschil: kopbalk, menu, statuspaneel, onderbalk |
| `opmaak.php` | BBCode van spelers naar veilige HTML |
| `helpers.php` | `e()`, `url()`, `money()`, `flash()`, `redirect()` |

## Zes regels die je niet mag breken

Elk van deze staat er omdat het in de oude versie fout ging.

1. **Nooit een variabele in een query.** Alles via plaatshouders. De oude versie
   had SQL-injectie in 66 bestanden.
2. **`csrf_check()` bovenaan elke POST-verwerking.** En geen enkele handeling
   achter een gewone link — een `<a href="...?actie=verwijder">` is te
   activeren door een plaatje op een andere site.
3. **Geld verandert alleen via `afboeken()` en `bijschrijven()`, binnen
   `db_transaction()` met `lock_user()`.** `afboeken()` zet de voorwaarde in de
   `UPDATE` zelf (`WHERE zak >= ?`); saldo uitlezen, rekenen en terugschrijven
   laat twee gelijktijdige verzoeken allebei slagen.
4. **Alles wat een speler heeft ingetypt door `e()`.** Of door `bericht_html()`
   als opmaak toegestaan is; die escapet eerst alles en zet daarna alleen terug
   wat uitdrukkelijk mag. Nooit andersom.
5. **Na een POST altijd `redirect()`.** Anders herhaalt vernieuwen de handeling.
6. **Bewerk PHP-bestanden nooit via PowerShell.** `Set-Content -Encoding utf8`
   schrijft een BOM vóór `<?php`; dat breekt `declare(strict_types=1)` en geeft
   witruimte vóór de headers. Dit is hier twee keer misgegaan.

## Twee dingen die je zullen verrassen

**Benoemde plaatshouders mogen niet herhaald worden.** De verbinding staat op
`ATTR_EMULATE_PREPARES => false`. Gebruik `:a`, `:b`, `:c` in plaats van twee
keer `:naam`.

**Spelers worden gekoppeld op `login`, niet op `id`.** Dat komt uit het
origineel en zit door het hele schema. Namen zijn uniek, dus het werkt, maar
hernoemen kan daarom niet.

## Voordat je zegt dat je klaar bent

```bash
php tests/rook.php        # elke pagina laadt schoon — draai deze altijd
php tests/geld.php        # de geldbalans klopt
php tests/veiligheid.php  # XSS, rechten, CSRF
php tests/opbouw.php      # de indeling per toestand
php tests/adressen.php    # adressen zonder .php
php tests/beheergeschiedenis.php  # de dagelijkse geschiedenis klopt
```

Ze hebben een draaiende server en database nodig; zie
[tests/LEESMIJ.md](tests/LEESMIJ.md) voor het opstarten en
[docs/ONTWIKKELEN.md](docs/ONTWIKKELEN.md) voor de rest.

Schrijf bij nieuw gedrag een test die controleert **wat er is gebeurd**, niet
dat er iets op het scherm staat. "Het banksaldo daalde met precies dit bedrag"
is bewijs; "het woord gelukt komt voor" niet. Dat onderscheid heeft hier al
meermaals een fout verborgen gehouden.

## Over het origineel

Veel van wat het oude spel leek te hebben, werkte nooit: de loterij had geen
trekking, de familiekas kon niet gevuld worden, promotie-uitbetalingen werden
nooit uitgelezen, het rijbewijs was onhaalbaar en de zwarte markt miste zijn
tabellen. Dat is bij de herbouw allemaal alsnog gebouwd.

Kom je iets tegen dat vreemd lijkt, ga er dan niet van uit dat het zo hoorde.
[docs/HERBOUW.md](docs/HERBOUW.md) vertelt per onderdeel wat er kapot was en wat
ervoor in de plaats is gekomen.

## Werkwijze

Kleine commits met een Nederlands bericht dat zegt wat er verandert en waarom.
Commit en push als een onderdeel af is, niet pas aan het eind. Verandert er iets
aan de spelregels, werk dan `help.php` bij — dat zijn de spelregels die de
speler leest, en die haalt zijn getallen uit de code.
