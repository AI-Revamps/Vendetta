<?php
/**
 * Paginaopmaak: kop, menu, statuspaneel en voet.
 *
 * Vervangt de oude frameset (index.php + upper.php + menu.php + right.php).
 * Gebruik in een pagina:
 *
 *     require __DIR__ . '/inc/bootstrap.php';
 *     $user = require_login();
 *     layout_header('Misdaad');
 *     panel_open('Misdaad');
 *     ... inhoud ...
 *     panel_close();
 *     layout_footer();
 */

declare(strict_types=1);

defined('BV_INC') || exit;

/**
 * Bestandsnaam van de pagina die nu getoond wordt, bv. 'crime.php'.
 *
 * Uit SCRIPT_NAME en niet uit REQUEST_URI: met mooie adressen staat er in de
 * adresbalk "/crime", maar draait nog steeds crime.php. SCRIPT_NAME geeft in
 * beide gevallen de echte bestandsnaam, zodat het menu weet waar je bent.
 */
function current_page(): string
{
    $script = basename((string) parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH));

    if ($script !== '' && str_ends_with($script, '.php')) {
        return $script;
    }

    // Terugval voor opstellingen waar SCRIPT_NAME niets bruikbaars geeft.
    $uri = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));

    if ($uri === '') {
        return 'home.php';
    }

    return str_ends_with($uri, '.php') ? $uri : $uri . '.php';
}

/**
 * Het zijmenu. Per groep: titel => [bestand => label].
 * Items die van de speler afhangen worden hieronder toegevoegd.
 */
function menu_groups(array $user): array
{
    $groepen = [
        'Status' => [
            'home.php'                  => 'Status',
            'profile.php'               => 'Profiel',
            'message.php'               => 'Berichten',
            'respect.php'               => 'Eerpunten',
            'wallofshame.php'           => 'Schandpaal',
            'getmarried.php'            => 'Trouwen',
            'stats.php'                 => 'Statistieken',
            'hitlist.php?watch'         => 'Premielijst',
            'members.php?filter=levend' => 'Spelers',
            'premium.php'               => 'Premium',
            'klikmissies.php'           => 'Klikmissies',
        ],
        'Plaatsen' => [
            'shop.php'            => 'Winkel / Markt',
            'bank.php'            => 'Bank',
            'mbulletfactory.php'  => 'Lokale kogelfabriek',
            'bulletfactory.php'   => 'Kogelfabriek',
            'garage.php'          => 'Garage',
            'bloodbank.php'       => 'Bloedbank',
            'rijbewijs.php'       => 'Rijschool',
            'transport.php'       => 'Transport',
            'detectives.php'      => 'Detectivebureau',
            'mshop.php'           => 'Zwarte markt',
        ],
        'Misdaden' => [
            'drank.php'     => 'Drank',
            'drugs.php'     => 'Drugs',
            'crime.php'     => 'Misdaad',
            'nickacar.php'  => 'Auto stelen',
            'heist.php'     => 'Route 66',
            'oc.php'        => 'Organised Crime',
            'carrace.php'   => 'Race',
            'kill.php'      => 'Moorden',
            'jail.php'      => 'Gevangenis',
        ],
        'Familie' => [
            'fam.php?p=list' => 'Familielijst',
        ],
        'Gokken' => [
            'guess.php'    => 'Nummerraden',
            'roulette.php' => 'Roulette',
            'blackjack.php'=> 'Blackjack',
            'krassen.php'  => 'Krassen',
            'slots.php'    => 'Fruitmachine',
            'loterij.php'  => 'Loterij',
        ],
    ];

    $beschikbaar = status_summary($user)['klikmissies_beschikbaar'] ?? 0;

    if ($beschikbaar > 0) {
        $groepen['Status']['klikmissies.php'] .= ' (' . num(min(99, $beschikbaar)) . ')';
    }

    // Familie-items hangen af van lidmaatschap en rang binnen de familie.
    $fam  = (string) ($user['famillie'] ?? '');
    $rang = (int) ($user['famrang'] ?? 0);

    if ($fam === '') {
        $groepen['Familie']['fam.php?p=new'] = 'Maak een familie';
    } else {
        $groepen['Familie']['fam.php?x=' . rawurlencode($fam)] = 'Familiepagina';
        if ($rang > 1) { $groepen['Familie']['famman.php?p=invite']  = 'Uitnodigen'; }
        if ($rang > 3) {
            $groepen['Familie']['famman.php?p=info']    = 'Familie-info';
            $groepen['Familie']['famman.php?p=message'] = 'Familiebericht';
            $groepen['Familie']['famman.php?p=members'] = 'Members';
        }
        if ($rang > 2) {
            $groepen['Familie']['famman.php?p=bulfac'] = 'Crusher';
            $groepen['Familie']['famman.php?p=log']    = 'Logboek';
            $groepen['Familie']['famman.php?p=land']   = 'Koop gebied';
        }
        if ($rang >= 3) { $groepen['Familie']['famman.php?p=bank'] = 'Familiebank'; }
        if ($rang === 5) { $groepen['Familie']['fam.php?p=delete'] = 'Verwijder familie'; }
    }

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

    return $groepen;
}

/**
 * Kerncijfers voor het statuspaneel: één query in plaats van dertien
 * tabelscans zoals de oude right.php deed.
 *
 * De uitkomst wordt per verzoek onthouden. De onderbalk, de statusstrook en
 * het statuspaneel vragen er alle drie om; zonder dit geheugen zouden dat drie
 * dezelfde queries zijn.
 */
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

/**
 * Pad naar een logobestand, of null als het er niet is.
 *
 * De logobestanden worden los aangeleverd. Ontbreken ze, dan valt de kopbalk
 * terug op alleen de tekstnaam in plaats van een gebroken plaatje te tonen.
 */
function logo_url(string $bestand): ?string
{
    return is_file(BV_ROOT . '/assets/img/' . $bestand)
        ? url('assets/img/' . $bestand)
        : null;
}

// --- Opbouw van de pagina ---------------------------------------------------

/** Open de pagina: <head>, kopbalk, menu en de opening van het inhoudsvak. */
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

    // Zijmenu, statuspaneel en onderbalk horen bij een levende, ingelogde
    // speler. Zijn ze er niet, dan moet het raster één kolom zijn in plaats
    // van drie — anders valt de inhoud in de smalle menukolom.
    $metZijkanten = $user !== null && !is_dead();

    // De klasse op body stuurt de mobiele weergave: alleen in spelmodus wordt
    // de bovennavigatie ingeruild voor de onderbalk.
    echo '<body' . ($metZijkanten ? ' class="spelmodus"' : '') . ">\n";

    // --- Kopbalk ---
    echo '<header class="topbar">' . "\n";

    $merk = logo_url('logo-mark.png');
    echo '<a class="brand" href="' . e(url($user !== null ? 'home.php' : 'index.php')) . '">';
    if ($merk !== null) {
        echo '<img src="' . e($merk) . '" alt="" width="36" height="36">';
    }
    echo '<span>' . e($siteNaam) . "</span></a>\n";

    echo '<nav class="topnav">';
    if ($user !== null) {
        $top = [
            'news.php'   => 'Nieuws',
            'forum.php'  => 'Forum',
            'poll.php'   => 'Poll',
            'tip.php'    => 'Ticket',
            'help.php'   => 'FAQ',
        ];
    } else {
        $top = [
            'index.php'    => 'Home',
            'news.php'     => 'Nieuws',
            'register.php' => 'Registreer',
            'help.php'     => 'FAQ',
        ];
    }
    foreach ($top as $bestand => $label) {
        $klasse = $bestand === $huidig ? ' class="actief"' : '';
        echo '<a href="' . e(url($bestand)) . '"' . $klasse . '>' . e($label) . '</a>';
    }
    if ($user !== null) {
        // Uitloggen is een handeling, dus via POST met CSRF-token.
        echo '<form method="post" action="' . e(url('logout.php')) . '" class="logout-form">'
           . csrf_field()
           . '<button type="submit">Uitloggen</button></form>';
    } else {
        echo '<a href="' . e(url('login.php')) . '">Inloggen</a>';
    }
    echo "</nav>\n";

    // Knop om het menu op smalle schermen open te klappen. Ook uitgelogd:
    // daar wordt de bovennavigatie op een telefoon anders een rommelige rij
    // links. De la bevat dan diezelfde links.
    echo '<button class="menu-toggle" type="button" aria-controls="zijmenu" '
       . 'aria-expanded="false" aria-label="Menu">&#9776;</button>' . "\n";

    echo "</header>\n";

    // --- Statusstrook (alleen op smalle schermen zichtbaar) ---
    if ($metZijkanten) {
        status_strook($user);
    }

    echo '<button class="menu-overlay" type="button" hidden aria-label="Menu sluiten"></button>' . "\n";

    echo '<div class="layout' . ($metZijkanten ? '' : ' alleen-inhoud') . '">' . "\n";

    // --- Zijmenu ---
    if ($metZijkanten) {
        $groepen = menu_groups($user);

        // Alles openzetten gaf een menu van ruim 2500 pixels: zeven groepen met
        // bij elkaar bijna zeventig items. Alleen de groep waar je nu bent staat
        // open; app.js onthoudt wat je verder openklapt.
        $openGroep = huidige_groep($groepen, $huidig) ?? array_key_first($groepen);

        echo '<nav class="sidebar" id="zijmenu">' . "\n";
        foreach ($groepen as $groep => $items) {
            echo '<details class="menugroep" data-groep="' . e($groep) . '"'
               . ($groep === $openGroep ? ' open' : '')
               . '><summary>' . e($groep) . "</summary>\n<ul>\n";
            foreach ($items as $bestand => $label) {
                $klasse = str_starts_with($bestand, $huidig) ? ' class="actief"' : '';
                echo '<li><a href="' . e(url($bestand)) . '"' . $klasse . '>' . $label . "</a></li>\n";
            }
            echo "</ul>\n</details>\n";
        }

        // Op een telefoon staat de bovennavigatie niet in beeld; zonder deze
        // groep zouden forum, nieuws en de spelregels daar onbereikbaar zijn.
        // Op een breed scherm is hij overbodig en dus verborgen.
        echo '<details class="menugroep alleen-mobiel" data-groep="Meer"><summary>Meer</summary>'
           . "\n<ul>\n";
        foreach ($top as $bestand => $label) {
            $klasse = $bestand === $huidig ? ' class="actief"' : '';
            echo '<li><a href="' . e(url($bestand)) . '"' . $klasse . '>' . e($label) . "</a></li>\n";
        }
        echo '<li><a href="' . e(url('premium.php')) . "\">Premium</a></li>\n";
        echo "</ul>\n</details>\n";

        echo '<form class="zoekform" method="get" action="' . e(url('profile.php')) . '">'
           . '<input type="search" name="login" maxlength="16" placeholder="Zoek speler" aria-label="Zoek speler">'
           . '<button type="submit">Ga</button></form>' . "\n";

        // Uitloggen hoort ook in de la; in de kopbalk is hij op een telefoon weg.
        echo '<form class="alleen-mobiel uitlog-la" method="post" action="'
           . e(url('logout.php')) . '">' . csrf_field()
           . '<button type="submit">Uitloggen</button></form>' . "\n";

        echo "</nav>\n";
    } else {
        // Uitgelogd of dood: dezelfde la, gevuld met de bovennavigatie. Op een
        // breed scherm staat die navigatie in de kopbalk en is dit menu
        // verborgen; op een telefoon zit het achter de hamburger.
        echo '<nav class="sidebar" id="zijmenu">' . "\n";
        echo '<details class="menugroep" open><summary>Menu</summary>' . "\n<ul>\n";

        foreach ($top as $bestand => $label) {
            $klasse = $bestand === $huidig ? ' class="actief"' : '';
            echo '<li><a href="' . e(url($bestand)) . '"' . $klasse . '>' . e($label) . "</a></li>\n";
        }

        if ($user === null) {
            echo '<li><a href="' . e(url('login.php')) . "\">Inloggen</a></li>\n";
        }

        echo "</ul>\n</details>\n";

        if ($user !== null) {
            // Een dode speler moet er wel uit kunnen.
            echo '<form class="uitlog-la" method="post" action="'
               . e(url('logout.php')) . '">' . csrf_field()
               . '<button type="submit">Uitloggen</button></form>' . "\n";
        }

        echo "</nav>\n";
    }

    // --- Inhoud ---
    echo '<main class="content">' . "\n";

    foreach (flash_take() as $melding) {
        echo '<div class="melding melding-' . e($melding['type']) . '">' . e($melding['msg']) . "</div>\n";
    }
}

/** Statuspaneel rechts, onderbalk en de afsluiting van de pagina. */
function layout_footer(): void
{
    echo "</main>\n";

    $user         = current_user();
    $metZijkanten = $user !== null && !is_dead();

    if ($metZijkanten) {
        status_panel($user);
    }

    echo "</div>\n"; // .layout

    echo '<footer class="paginavoet">' . e((string) config('site.name'))
       . ' &middot; <a href="' . e(url('help.php')) . '">Spelregels</a>'
       . ' &middot; <a href="' . e(url('tip.php')) . '">Hulp nodig?</a></footer>' . "\n";

    // De onderbalk staat vast onderaan het scherm en vervangt op een telefoon
    // de bovennavigatie. Hij komt na de voet zodat hij in de leesvolgorde niet
    // tussen de inhoud valt.
    if ($metZijkanten) {
        onderbalk($user);
    }

    echo '<script src="' . e(asset_url('assets/js/app.js')) . '" defer></script>' . "\n";
    echo "</body>\n</html>\n";
}

/**
 * Adres van een bestand in /assets/, met de wijzigingsdatum erachter.
 *
 * Zo krijgt de browser vanzelf de nieuwe versie zodra het bestand verandert.
 * Eerder stond er een handmatig getal (?v=3), en dat is precies één keer
 * misgegaan: de stylesheet werd aangepast zonder het getal op te hogen,
 * waardoor iedereen de oude opmaak bleef zien.
 */
function asset_url(string $pad): string
{
    $vol = BV_ROOT . '/' . ltrim($pad, '/');
    $tijd = is_file($vol) ? filemtime($vol) : time();

    return url($pad) . '?v=' . $tijd;
}

/**
 * In welke menugroep zit de pagina waar je nu bent?
 *
 * @param array<string, array<string, string>> $groepen
 */
function huidige_groep(array $groepen, string $huidig): ?string
{
    foreach ($groepen as $groep => $items) {
        foreach (array_keys($items) as $bestand) {
            if (str_starts_with($bestand, $huidig)) {
                return $groep;
            }
        }
    }

    return null;
}

/**
 * Pictogram voor de onderbalk, als inline SVG.
 *
 * Bewust geen emoji of tekens uit een lettertype: die zien er op elk toestel
 * anders uit en zijn niet te kleuren. Deze lijntekeningen nemen via
 * currentColor de kleur van de tab over, ook als die actief is.
 */
function icoon(string $naam): string
{
    $paden = [
        // Een persoon: je eigen status.
        'persoon' => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',

        // Een bankgebouw met zuilen.
        'bank' => '<path d="M3 9.5 12 4l9 5.5"/>'
                . '<path d="M5.5 11v7M9.5 11v7M14.5 11v7M18.5 11v7"/>'
                . '<path d="M3 20.5h18"/>',

        // Een boodschappentas.
        'winkel' => '<path d="M5 8h14l-1.1 12H6.1L5 8Z"/><path d="M9 8V6.2a3 3 0 0 1 6 0V8"/>',

        // Een envelop.
        'envelop' => '<rect x="3" y="5.5" width="18" height="13" rx="2"/>'
                   . '<path d="m3.6 7 8.4 6 8.4-6"/>',

        // Drie streepjes voor het menu.
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    ];

    return '<svg class="teken" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
         . 'aria-hidden="true" focusable="false">' . ($paden[$naam] ?? '') . '</svg>';
}

/** Groen, oranje of rood, afhankelijk van de gezondheid. */
function health_klasse(int $health): string
{
    return match (true) {
        $health >= 60 => 'vol',
        $health >= 25 => 'middel',
        default       => 'laag',
    };
}

/**
 * Smalle strook onder de kopbalk met de cijfers waar een speler het vaakst
 * naar kijkt. Alleen zichtbaar op smalle schermen; op een breed scherm doet
 * het statuspaneel rechts dit werk.
 */
function status_strook(array $user): void
{
    $health = max(0, min(100, (int) $user['health']));

    echo '<div class="statusstrook">' . "\n";

    echo '<div class="chip"><b>Gezondheid</b><span>' . $health . '%</span>'
       . '<div class="balk balk-health ' . health_klasse($health) . '">'
       . '<span style="width:' . $health . '%"></span></div></div>';

    echo '<div class="chip"><b>Op zak</b><span>' . money((int) $user['zak']) . '</span></div>';
    echo '<div class="chip"><b>Bank</b><span>' . money((int) $user['bank']) . '</span></div>';
    echo '<div class="chip"><b>Kogels</b><span>' . num((int) $user['kogels']) . '</span></div>';
    echo '<div class="chip"><b>Diamanten</b><span>'
       . num((int) ($user['diamanten'] ?? 0)) . '</span></div>';

    echo "\n</div>\n";
}

/**
 * Vaste balk onderaan op een telefoon: de vier plekken waar je het vaakst
 * heen gaat, plus een knop die het menu openschuift.
 */
function onderbalk(array $user): void
{
    $huidig = current_page();
    $stat   = status_summary($user);

    $tabs = [
        ['home.php',    'Status',    'persoon'],
        ['bank.php',    'Bank',      'bank'],
        ['shop.php',    'Winkel',    'winkel'],
        ['message.php', 'Berichten', 'envelop'],
    ];

    echo '<nav class="onderbalk" aria-label="Snelmenu">' . "\n<ul>\n";

    foreach ($tabs as [$bestand, $label, $teken]) {
        $klasse = $bestand === $huidig ? ' class="actief"' : '';

        echo '<li><a href="' . e(url($bestand)) . '"' . $klasse . '>';
        echo icoon($teken);
        echo '<span>' . e($label) . '</span>';

        if ($bestand === 'message.php' && $stat['ongelezen'] > 0) {
            echo '<span class="bolletje">' . num(min(99, $stat['ongelezen'])) . '</span>';
        }

        echo "</a></li>\n";
    }

    echo '<li><button class="menu-toggle-onder" type="button" aria-controls="zijmenu" '
       . 'aria-expanded="false">'
       . icoon('menu') . '<span>Menu</span>'
       . "</button></li>\n";

    echo "</ul>\n</nav>\n";
}

/** Het rechterpaneel met de kerncijfers van de speler. */
function status_panel(array $user): void
{
    $stat = status_summary($user);
    $rang = rank_name((int) $user['xp'], (string) $user['geslacht']);
    $vord = rank_progress((int) $user['xp']);
    $cel  = jail_status($user['login']);

    echo '<aside class="statuspaneel">' . "\n";
    echo '<h2>' . e($user['login']) . "</h2>\n";
    echo '<p class="rang">' . e($rang) . "</p>\n";

    echo '<div class="balk" role="img" aria-label="Vordering naar volgende rang: ' . $vord . ' procent">'
       . '<span style="width:' . $vord . '%"></span></div>' . "\n";
    echo '<p class="balklabel">' . $vord . '% naar de volgende rang</p>' . "\n";

    if ($cel !== null) {
        echo '<p class="waarschuwing">Je zit vast: nog <strong data-tot="'
           . (time() + $cel['resterend']) . '">' . e(duration($cel['resterend'])) . '</strong></p>' . "\n";
    }

    // Gezondheid als balk: in één oogopslag zie je of je naar de bloedbank moet.
    $health = max(0, min(100, (int) $user['health']));

    echo '<div class="balk balk-health ' . health_klasse($health)
       . '" role="img" aria-label="Gezondheid: ' . $health . ' procent">'
       . '<span style="width:' . $health . '%"></span></div>' . "\n";
    echo '<p class="balklabel">' . $health . '% gezondheid</p>' . "\n";

    // Geld en kogels krijgen nadruk; daar kijkt een speler het vaakst naar.
    $regels = [
        ['Op zak',        money((int) $user['zak']), true],
        ['Bank',          money((int) $user['bank']), true],
        ['Kogels',        num((int) $user['kogels']), true],
        ['Diamanten',     num((int) ($user['diamanten'] ?? 0)), false],
        ['Energie',       num((float) $user['energie'], 1) . '%', false],
        ['Moordervaring', num((float) $user['se'], 1) . '%', false],
        ['Stad',          e((string) $user['stad']), false],
        ['Familie',       $user['famillie'] !== '' ? e((string) $user['famillie']) : 'Geen', false],
        ['Positie',       '#' . num($stat['positie']) . ' van ' . num($stat['spelers']), false],
    ];

    echo "<dl>\n";
    foreach ($regels as [$label, $waarde, $nadruk]) {
        echo '<dt>' . e($label) . '</dt>'
           . '<dd' . ($nadruk ? ' class="nadruk"' : '') . '>' . $waarde . "</dd>\n";
    }
    echo "</dl>\n";

    if ($stat['ongelezen'] > 0) {
        echo '<a class="knop knop-nadruk" href="' . e(url('message.php')) . '">'
           . $stat['ongelezen'] . ' nieuw bericht' . ($stat['ongelezen'] === 1 ? '' : 'en') . "</a>\n";
    }

    if (is_premium($user)) {
        echo '<p class="premium">Premium tot ' . e(datetime_nl((string) $user['premium_tot']))
           . "</p>\n";
    }

    echo '<p class="online">' . num($stat['online']) . " spelers online</p>\n";
    echo "</aside>\n";
}

// --- Bouwstenen voor in de inhoud -------------------------------------------

/**
 * Open een kader met een titel.
 *
 * De titel wordt geëscaped, dus HTML werkt hier niet. Wil je ergens naartoe
 * kunnen linken, geef dan $id mee; dat wordt een anker op het kader zelf.
 * Eerder werd daarvoor een `<a id="...">` in de titel gezet, en dat kwam als
 * platte tekst in beeld.
 */
function panel_open(string $titel, string $id = ''): void
{
    echo '<section class="paneel"'
       . ($id !== '' ? ' id="' . e($id) . '"' : '')
       . '><h1>' . e($titel) . '</h1><div class="paneelinhoud">' . "\n";
}

function panel_close(): void
{
    echo "</div></section>\n";
}

/** Losse melding binnen de inhoud. $type is 'ok', 'fout' of 'info'. */
function notice(string $tekst, string $type = 'info'): void
{
    echo '<div class="melding melding-' . e($type) . '">' . $tekst . "</div>\n";
}
