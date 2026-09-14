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
 * De beheerpagina's, met het niveau dat ervoor nodig is.
 * Wordt gebruikt voor het menu én voor de rechtencontrole per pagina.
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
        // Op eigenaarsniveau: hier wordt advertentiecode ingeplakt die
        // ongefilterd bij elke speler in de browser terechtkomt.
        'adm-premium.php'  => ['Premium',         LEVEL_OWNER],
        'adm-items.php'    => ['Items',           LEVEL_OWNER],
        'adm-drdrpr.php'   => ['Steden',          LEVEL_OWNER],
        'adm-bo.php'       => ['Speler bewerken', LEVEL_OWNER],
    ];
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

    layout_header('Beheer');
    beheer_menu($user, $pagina);

    return $user;
}

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
        echo '<td>' . e((string) $regel['login']) . '</td>';
        echo '<td>' . e((string) $regel['person']) . '</td>';
        echo '<td>' . e((string) $regel['com']) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}
