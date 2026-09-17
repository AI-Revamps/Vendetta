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
 * Wordt gebruikt voor het zijmenu én voor de rechtencontrole per pagina.
 */
function beheerpaginas(): array
{
    return [
        'search.php'   => ['Zoeken',          LEVEL_MODERATOR, 'Spelers'],
        'online.php'   => ['Online',          LEVEL_MODERATOR, 'Spelers'],
        'prison.php'   => ['Gevangenis',      LEVEL_MODERATOR, 'Spelers'],
        'warn.php'     => ['Waarschuwen',     LEVEL_MODERATOR, 'Spelers'],
        'msg.php'      => ['Bericht sturen',  LEVEL_ADMIN, 'Communicatie'],
        'ban.php'      => ['Bannen',          LEVEL_ADMIN, 'Spelers'],
        'addmulti.php' => ['Multi-accounts',  LEVEL_ADMIN, 'Spelers'],
        'garages.php'  => ['Garages',         LEVEL_ADMIN, 'Spelers'],
        'shame.php'    => ['Wall of Shame',   LEVEL_ADMIN, 'Inhoud'],
        'forum.php'    => ['Forum opruimen',  LEVEL_ADMIN, 'Inhoud'],
        'addnews.php'  => ['Nieuws',          LEVEL_ADMIN, 'Inhoud'],
        'poll.php'     => ['Polls',           LEVEL_ADMIN, 'Inhoud'],
        'getuigen.php' => ['Ooggetuigen',     LEVEL_ADMIN, 'Spelwereld'],
        'klikmissies.php' => ['Klikmissies',  LEVEL_ADMIN, 'Spelwereld'],
        'premiumlog.php'   => ['Logs',              LEVEL_ADMIN, 'Economie'],
        'premiumstats.php' => ['Statistieken',      LEVEL_ADMIN, 'Economie'],
        // Deze twee mogen alleen de eigenaar in: de advertentiecode gaat
        // ongefilterd bij elke speler in de browser terecht.
        'advertenties.php' => ['Advertenties',      LEVEL_OWNER, 'Economie'],
        'diamanten.php'    => ['Diamanten en prijs', LEVEL_OWNER, 'Economie'],
        'toekennen.php'    => ['Toekennen',         LEVEL_ADMIN, 'Economie'],
        'items.php'    => ['Items',           LEVEL_OWNER, 'Spelwereld'],
        'autos.php'    => ['Auto\'s',         LEVEL_OWNER, 'Spelwereld'],
        'drdrpr.php'   => ['Steden',          LEVEL_OWNER, 'Spelwereld'],
        'bo.php'       => ['Speler bewerken', LEVEL_OWNER, 'Spelers'],
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

/**
 * Toon een korte regel met wie wat wanneer deed.
 *
 * @param string|string[] $area Eén gebied, of meerdere die bij elkaar horen
 *                               (bijv. 'premium' en 'diamant' samen).
 */
function beheer_logregels(string|array $area, int $aantal = 25): void
{
    $gebieden  = is_array($area) ? $area : [$area];
    $plekken   = implode(',', array_fill(0, count($gebieden), '?'));

    $regels = q_all(
        "SELECT * FROM `logs` WHERE `area` IN ({$plekken}) ORDER BY `time` DESC LIMIT " . (int) $aantal,
        $gebieden
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
