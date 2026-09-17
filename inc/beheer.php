<?php
/**
 * Gedeelde onderdelen van de beheerpagina's.
 *
 * De rechtenniveaus staan in inc/auth.php: LEVEL_MODERATOR (200),
 * LEVEL_ADMIN (255) en LEVEL_OWNER (1000).
 */

declare(strict_types=1);

defined('BV_INC') || exit;

/**
 * De beheerpagina's: bestand => [label, benodigd niveau, categorie].
 *
 * De categorie ontbreekt bewust bij bestanden die nog niet naar admin/ zijn
 * verplaatst — beheer_categorieen() slaat zulke rijen over, zodat het
 * zijmenu nooit naar een bestand linkt dat nog op zijn oude plek staat.
 * Naarmate elke categorie verhuist krijgt de rij zijn derde element én zijn
 * nieuwe, kale bestandsnaam als sleutel.
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
        // De pagina zelf mag een admin in; de advertentiecode en de
        // balansinstellingen erop blijven daarbinnen apart op eigenaarsniveau
        // afgeschermd, want dat veld gaat ongefilterd bij elke speler in de
        // browser terecht.
        'adm-premium.php'  => ['Premium',         LEVEL_ADMIN],
        'adm-items.php'    => ['Items',           LEVEL_OWNER],
        'adm-drdrpr.php'   => ['Steden',          LEVEL_OWNER],
        'adm-bo.php'       => ['Speler bewerken', LEVEL_OWNER],
    ];
}

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

/**
 * Zorg dat de bezoeker deze beheerpagina mag zien.
 *
 * Het niveau komt uit één tabel, zodat een pagina niet per ongeluk zonder
 * controle kan blijven. In de oude versie stond het niveau in elk bestand
 * apart en ontbrak het in adm-cleandb.php en admin.php volledig.
 */
function beheer_start(string $pagina): array
{
    $nodig = beheerpaginas()[$pagina][1] ?? LEVEL_OWNER;
    $user  = require_level($nodig);

    beheer_header($user, $pagina);

    return $user;
}

/**
 * De oude, platte knoppenrij — gebruikt door de beheerbestanden die nog niet
 * naar admin/ zijn verplaatst. Verdwijnt zodra de laatste daarvan verhuisd
 * is naar beheer_header().
 */
function beheer_menu(array $user, string $huidig): void
{
    echo '<p>';
    echo '<a class="knop" style="display:inline-block;margin:0 .3rem .3rem 0" href="'
       . e(beheer_url('dashboard.php')) . '">Overzicht</a>';

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

/**
 * Zoek een speler op naam.
 *
 * @throws SpelFout
 */
function beheer_speler(string $naam): array
{
    $naam = trim($naam);

    if ($naam === '') {
        throw new SpelFout('Vul een gebruikersnaam in.');
    }

    $speler = q_row('SELECT * FROM `users` WHERE `login` = ?', [$naam]);

    if ($speler === null) {
        throw new SpelFout('Die speler bestaat niet.');
    }

    return $speler;
}

/** Toon een korte regel met wie wat wanneer deed. */
function beheer_logregels(string $area, int $aantal = 25): void
{
    $regels = q_all(
        'SELECT * FROM `logs` WHERE `area` = ? ORDER BY `time` DESC LIMIT ' . (int) $aantal,
        [$area]
    );

    if ($regels === []) {
        return;
    }

    echo '<h3>Recent</h3><div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Wanneer</th><th>Door</th><th>Wie</th><th>Wat</th></tr></thead><tbody>';

    foreach ($regels as $regel) {
        echo '<tr>';
        echo '<td>' . e(datetime_nl($regel['time'])) . '</td>';
        echo '<td>' . speler_naam((string) $regel['login']) . '</td>';
        echo '<td>' . speler_naam((string) $regel['person']) . '</td>';
        echo '<td>' . e((string) $regel['com']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}
