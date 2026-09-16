<?php
/**
 * Statuspagina: het overzicht van je gangster.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

$user = require_login();

if (is_dead()) {
    redirect('rip.php');
}

// Afgelopen verkopen op de zwarte markt teruggeven aan de eigenaar.
markt_afhandelen($user['login']);

// Verlopen premies opruimen.
q('DELETE FROM `ws` WHERE `time` < NOW()');

$user      = current_user(true);
$ongelezen = (int) q_val(
    'SELECT COUNT(*) FROM `messages` WHERE `to` = ? AND `read` = 0', [$user['login']], 0
);

$wapen  = wapennaam((int) $user['wapon'], 'att');
$vest   = wapennaam((int) $user['defence'], 'def');
$vervoer= wapennaam((int) $user['trans'], 'trans');
$cel    = jail_status($user['login']);
$huis   = heeft_huis((string) $user['login'], (string) $user['stad']) ? 'Ja' : 'Nee';

layout_header('Status');

// --- Meldingen bovenaan ---
if ($ongelezen > 0) {
    notice(
        'Je hebt <a href="' . e(url('message.php')) . '">' . $ongelezen . ' ongelezen bericht'
        . ($ongelezen === 1 ? '' : 'en') . '</a>.',
        'info'
    );
}
if ($cel !== null) {
    notice(
        'Je zit in de gevangenis in ' . e((string) $cel['stad']) . '. Nog '
        . '<span data-tot="' . (time() + $cel['resterend']) . '">' . e(duration($cel['resterend']))
        . '</span>. <a href="' . e(url('jisin.php')) . '">Bekijk je cel</a>.',
        'fout'
    );
}

// --- Gegevens ---
panel_open('Je gangster');
echo '<div class="tabelwikkel"><table class="lijst">';
gegevensrij('Naam', speler_naam((string) $user['login']) . ' <small>(#' . (int) $user['id'] . ')</small>');
gegevensrij('Rang', e(rank_name((int) $user['xp'], (string) $user['geslacht']))
    . ' <small>(' . rank_progress((int) $user['xp']) . '% naar de volgende)</small>');
gegevensrij('Ervaring', num((int) $user['xp']));
gegevensrij('Stad', e((string) $user['stad']) . ' <small>Eigen huis: ' . $huis . '</small>');
gegevensrij('Familie', $user['famillie'] !== ''
    ? '<a href="' . e(url('fam.php?x=' . rawurlencode((string) $user['famillie']))) . '">'
      . e((string) $user['famillie']) . '</a>'
    : 'Geen');
gegevensrij('Gezondheid', (int) $user['health'] . '%');
gegevensrij('Energie', num((float) $user['energie'], 1) . '%');
gegevensrij('Moordervaring', num((float) $user['se'], 1) . '%');
gegevensrij('Eerpunten', num((int) $user['respect']));
gegevensrij('Lid sinds', datetime_nl($user['start']));
echo '</table></div>';
panel_close();

// --- Bezit ---
panel_open('Bezit');
echo '<div class="tabelwikkel"><table class="lijst">';
gegevensrij('Op zak', money((int) $user['zak']));
gegevensrij('Op de bank', money((int) $user['bank']));
gegevensrij('Totaal', '<strong>' . money((int) $user['zak'] + (int) $user['bank']) . '</strong>');
gegevensrij('Kogels', num((int) $user['kogels']));
gegevensrij('Wapen', e($wapen));
gegevensrij('Bescherming', e($vest));
gegevensrij('Vervoer', e($vervoer));
gegevensrij('Bodyguards', num((int) $user['guard']));
gegevensrij('Testament', $user['testament'] !== '' ? e((string) $user['testament']) : 'Geen');
gegevensrij('Getrouwd met', $user['huwelijk'] !== '' ? e((string) $user['huwelijk']) : 'Niemand');
echo '</table></div>';
panel_close();

// --- Wachttijden ---
panel_open('Wachttijden');
echo '<div class="tabelwikkel"><table class="lijst">';
wachtrij('Misdaad',      (int) $user['crime_ts'],     'crime.php');
wachtrij('Auto stelen',  (int) $user['ac_ts'],        'nickacar.php');
wachtrij('Bank',         (int) $user['bc_ts'],        'bank.php');
wachtrij('Route 66',     (int) $user['pc_ts'],        'heist.php');
wachtrij('Moorden',      (int) $user['kc_ts'],        'kill.php');
wachtrij('Transport',    (int) $user['transport_ts'], 'transport.php');
wachtrij('Slapen',       (int) $user['slaap_ts'],     null);
echo '</table></div>';
panel_close();

// --- Prestaties ---
panel_open('Prestaties');
echo '<div class="tabelwikkel"><table class="lijst">';
gegevensrij('Misdaden gepleegd', num((int) $user['nrofcrime']));
gegevensrij("Auto's gestolen",   num((int) $user['nrofcar']));
gegevensrij('Route 66 gereden',  num((int) $user['nrofroute']));
gegevensrij("Organised Crimes",  num((int) $user['nrofoc']));
gegevensrij('Races gereden',     num((int) $user['nrofrace']));
gegevensrij('Moorden gepleegd',  num((int) $user['nrofkill']));
gegevensrij('Uitbraken',         num((int) $user['bo']));
echo '</table></div>';
panel_close();

layout_footer();

// ==========================================================================

function gegevensrij(string $label, string $waarde): void
{
    echo '<tr><th scope="row">' . e($label) . '</th><td>' . $waarde . '</td></tr>';
}

/** Rij met een aftellende wachttijd. */
function wachtrij(string $label, int $tot, ?string $link): void
{
    $over = cooldown_left($tot);

    if ($over > 0) {
        $waarde = '<span data-tot="' . $tot . '">' . e(duration($over)) . '</span>';
    } else {
        $waarde = $link !== null
            ? '<a href="' . e(url($link)) . '">Nu beschikbaar</a>'
            : 'Nu beschikbaar';
    }

    echo '<tr><th scope="row">' . e($label) . '</th><td>' . $waarde . '</td></tr>';
}

/** Naam van een item, of 'Geen' als de speler het niet heeft. */
function wapennaam(int $nr, string $type): string
{
    if ($nr < 1) {
        return 'Geen';
    }
    return (string) q_val(
        'SELECT `naam` FROM `items` WHERE `type` = ? AND `nr` = ?', [$type, $nr], 'Onbekend'
    );
}

/**
 * Kogels en auto's die op de zwarte markt stonden maar niet verkocht zijn,
 * teruggeven aan de verkoper.
 */
function markt_afhandelen(string $login): void
{
    $kogels = q_all('SELECT * FROM `kogels` WHERE `login` = ? AND `time` < NOW()', [$login]);
    foreach ($kogels as $partij) {
        db_transaction(static function () use ($partij) {
            q('UPDATE `users` SET `kogels` = `kogels` + ? WHERE `login` = ?',
                [(int) $partij['aantal'], $partij['login']]);
            notify((string) $partij['login'], 'Kogels',
                'Je kogels zijn niet verkocht en staan weer in je voorraad.');
            q('DELETE FROM `kogels` WHERE `id` = ?', [$partij['id']]);
        });
    }

    $autos = q_all('SELECT * FROM `mgarage` WHERE `login` = ? AND `time` < NOW()', [$login]);
    foreach ($autos as $auto) {
        db_transaction(static function () use ($auto) {
            q(
                'INSERT INTO `garage` (`login`, `naam`, `waarde`, `damage`, `stad`)
                      VALUES (?, ?, ?, ?, ?)',
                [$auto['login'], $auto['naam'], $auto['waarde'], $auto['damage'], $auto['stad']]
            );
            notify((string) $auto['login'], 'Wagen',
                'Je wagen is niet verkocht en staat weer in je garage.');
            q('DELETE FROM `mgarage` WHERE `id` = ?', [$auto['id']]);
        });
    }
}
