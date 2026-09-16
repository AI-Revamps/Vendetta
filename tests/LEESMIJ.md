# Tests

De tests praten met een **draaiend spel over HTTP**. Ze doen dus precies wat een
speler doet: inloggen, een formulier invullen, op verzenden drukken. Daarmee
testen ze sessies, tokens, doorverwijzingen en rechten mee — dingen die je bij
het rechtstreeks aanroepen van functies mist.

De keerzijde: je moet eerst een server en een database opstarten.

## Opstarten

Eenmalig een testdatabase aanmaken:

```sql
CREATE DATABASE bv_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Het schema erin laden en de server starten, vanuit de hoofdmap:

```bash
mysql -u root bv_test < install/schema.sql
php -S 127.0.0.1:8149 -t . tests/router.php
```

En in een tweede venster de testaccounts aanmaken:

```bash
php tests/seed.php
```

Zorg dat `inc/config.php` naar `bv_test` wijst en dat `debug` aan staat, anders
zie je databasefouten niet.

## De testaccounts

| Naam | Niveau | Wachtwoord |
|---|---|---|
| `Speler` | 1 (gewone speler) | `spelerwachtwoord123` |
| `Mod` | 200 (moderator) | `modwachtwoord123456` |
| `Admin` | 255 (admin) | `adminwachtwoord12345` |
| `Baas` | 1000 (eigenaar) | `baaswachtwoord12345` |

Allemaal in Brussel, met een huis, 100.000 op zak en 50.000 op de bank.

## De tests

| Script | Wat het controleert |
|---|---|
| `rook.php` | elke pagina laadt zonder fout — dit is de test die je na élke wijziging draait |
| `geld.php` | de geldbalans klopt na bank, winkel, casino, moord en cron |
| `veiligheid.php` | XSS, rechten per niveau, CSRF op elk formulier |
| `opbouw.php` | de indeling per toestand: uitgelogd, ingelogd, dood; menu en onderbalk |
| `adressen.php` | adressen zonder `.php`, en of geen enkele link er nog een bevat |

Elk script geeft exitcode 0 als alles goed ging en 1 als er iets misging, dus:

```bash
for t in rook geld veiligheid opbouw adressen; do php tests/$t.php || break; done
```

`geld.php` en `adressen.php` regelen hun eigen omgeving: de eerste maakt een
verse database `bv_geldtest` aan en gooit die daarna weg, de tweede start zijn
eigen server op poort 8157 met mooie adressen aan. Beide zetten `inc/config.php`
bij het afsluiten weer terug zoals hij was.

## Andere instellingen

De scripts lezen vier omgevingsvariabelen:

| Variabele | Standaard |
|---|---|
| `BV_BASIS` | `http://127.0.0.1:8149` |
| `BV_DB` | `bv_test` |
| `BV_DBUSER` | `root` |
| `BV_DBPASS` | leeg |

```bash
BV_BASIS=http://localhost:8080 BV_DBPASS=geheim php tests/rook.php
```

## Zelf een test schrijven

Begin met `require __DIR__ . '/_start.php';`. Daarin zitten `haal()`, `doe()`,
`login()`, `tok()`, `melding()`, `totaal_geld()`, `kop()`, `check()` en
`samenvatting()`. Zie de commentaren in dat bestand.

Het patroon is steeds hetzelfde:

```php
kop('wat je onderzoekt');

$r = doe('bank.php', ['amount' => '5000', 'in' => '1']);

check('het geld staat op de bank', $bank === 5000, 'bank ' . $bank);
```

`doe()` haalt de pagina eerst op, pikt het CSRF-token eruit en beantwoordt de
rekensom als de pagina erom vraagt. `check()` telt de mislukkingen, en
`samenvatting()` sluit af met de juiste exitcode.

**Twee valkuilen**, allebei hier al eens in getrapt:

- Zoek niet op tekst waar je op structuur kunt controleren. Een test die
  `str_contains($html, 'onload')` doet, valt over geëscapete tekst die juist
  bewijst dat het goed zit. `veiligheid.php` parseert daarom met `DOMDocument`.
- Controleer wat er is gebeurd, niet dat er iets op het scherm staat. "Het woord
  moord komt voor" is geen bewijs; "het banksaldo is precies met dit bedrag
  gedaald" wel.

## `router.php`

De ingebouwde PHP-server leest geen `.htaccess`. `tests/router.php` bootst de
enige regel na die ertoe doet — bestaat `/iets` niet maar `/iets.php` wel, laad
dan dat bestand — plus de 404 die Apache geeft en de ingebouwde server niet.
Op de webhost wordt dit bestand nooit gebruikt.
