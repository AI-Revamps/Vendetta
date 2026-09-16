<?php
/**
 * Zwarte markt: kogels, auto's en ooggetuigenverklaringen verhandelen.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - Kopen ging via een GET-link (mshop.php?x=bullets&buy=5). Een afbeelding
 *    met die URL in een forumbericht liet iedereen die het las kopen. Nu POST
 *    met CSRF-token.
 *  - Er zat geen transactie omheen: twee kopers konden dezelfde partij kopen,
 *    want de DELETE kwam pas na beide afboekingen. Nu wordt de aanbieding
 *    vergrendeld met FOR UPDATE.
 *  - Bij het verkopen van een ooggetuige werd `login` overschreven met de
 *    verkoper, waardoor iedereen andermans verklaring te koop kon zetten.
 *    Nu moet de verklaring van jezelf zijn.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

const VERKOOPDUUR      = 21600;      // zes uur
const KOGELS_MIN       = 100;
const KOGELS_MINPRIJS  = 25000;
const KOGELS_MAXPERSTUK = 2000;
const AUTO_MINPRIJS    = 500;
const AUTO_MAXSCHADE   = 90;
const WS_MINPRIJS      = 100;
const WS_MAXPRIJS      = 10_000_000;

$user = require_login();

if (is_dead()) {
    redirect('rip.php');
}

$afdeling = get('x');
$pagina   = get('page');
$melding  = null;
$type     = 'info';

if (is_post()) {
    csrf_check();
    block_if_jailed();
    try {
        $melding = verwerk($user, post('actie'));
        $type    = 'ok';
        $user    = current_user(true);
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Zwarte markt');

if ($melding !== null) {
    notice(e($melding), $type);
}

match ($afdeling) {
    'bullets' => $pagina === 'sell' ? kogels_verkopen($user) : kogels_lijst(),
    'cars'    => $pagina === 'sell' ? autos_verkopen($user)  : autos_lijst(),
    'ws'      => $pagina === 'sell' ? ws_verkopen($user)     : ws_lijst(),
    default   => overzicht(),
};

layout_footer();

// ==========================================================================
// Verwerking
// ==========================================================================

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'koop_kogels' => koop_kogels($user, int_input('id')),
        'koop_auto'   => koop_auto($user, int_input('id')),
        'koop_ws'     => koop_ws($user, int_input('id')),
        'zet_kogels'  => zet_kogels($user, int_input('aantal', 0, 0), int_input('prijs', 0, 0)),
        'zet_auto'    => zet_auto($user, int_input('car'), int_input('prijs', 0, 0)),
        'zet_ws'      => zet_ws($user, int_input('id'), int_input('prijs', 0, 0)),
        default       => throw new SpelFout('Onbekende handeling.'),
    };
}

// --- Kogels ---------------------------------------------------------------

/** @throws SpelFout */
function koop_kogels(array $user, int $id): string
{
    return db_transaction(static function () use ($user, $id): string {
        $aanbod = q_row('SELECT * FROM `kogels` WHERE `id` = ? FOR UPDATE', [$id]);

        if ($aanbod === null) {
            throw new SpelFout('Deze aanbieding bestaat niet meer.');
        }
        if ($aanbod['login'] === $user['login']) {
            throw new SpelFout('Je kunt je eigen kogels niet kopen.');
        }

        $prijs  = (int) $aanbod['prijs'];
        $aantal = (int) $aanbod['aantal'];

        lock_user((int) $user['id']);
        if (!afboeken((int) $user['id'], $prijs, 'zak')) {
            throw new SpelFout('Je hebt niet genoeg geld op zak.');
        }

        bijschrijven((int) $user['id'], $aantal, 'kogels');

        $verkoper = lock_user_by_login((string) $aanbod['login']);
        if ($verkoper !== null) {
            bijschrijven((int) $verkoper['id'], $prijs, 'zak');
            notify((string) $aanbod['login'], 'Kogels verkocht',
                'Je hebt ' . num($aantal) . ' kogels verkocht voor ' . money($prijs) . '.');
        }

        q('DELETE FROM `kogels` WHERE `id` = ?', [$id]);

        log_action((string) $user['login'], 'mshop',
            'Kogels gekocht: ' . $aantal, $prijs, (string) $aanbod['login']);

        return 'Je hebt ' . num($aantal) . ' kogels gekocht voor ' . money($prijs) . '.';
    });
}

/** @throws SpelFout */
function zet_kogels(array $user, int $aantal, int $prijs): string
{
    if ($aantal < KOGELS_MIN) {
        throw new SpelFout('Je moet minstens ' . KOGELS_MIN . ' kogels tegelijk verkopen.');
    }
    if ($prijs < KOGELS_MINPRIJS) {
        throw new SpelFout('De minimumprijs is ' . money(KOGELS_MINPRIJS) . '.');
    }
    if ($prijs > $aantal * KOGELS_MAXPERSTUK) {
        throw new SpelFout('Je mag hoogstens ' . money(KOGELS_MAXPERSTUK) . ' per kogel vragen.');
    }

    return db_transaction(static function () use ($user, $aantal, $prijs): string {
        lock_user((int) $user['id']);

        if (!afboeken((int) $user['id'], $aantal, 'kogels')) {
            throw new SpelFout('Zoveel kogels heb je niet.');
        }

        q(
            'INSERT INTO `kogels` (`login`, `aantal`, `prijs`, `time`)
                  VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
            [$user['login'], $aantal, $prijs, VERKOOPDUUR]
        );

        return num($aantal) . ' kogels staan te koop voor ' . money($prijs) . '.';
    });
}

// --- Auto's ----------------------------------------------------------------

/** @throws SpelFout */
function koop_auto(array $user, int $id): string
{
    return db_transaction(static function () use ($user, $id): string {
        $aanbod = q_row('SELECT * FROM `mgarage` WHERE `id` = ? FOR UPDATE', [$id]);

        if ($aanbod === null) {
            throw new SpelFout('Deze wagen staat niet meer te koop.');
        }
        if ($aanbod['login'] === $user['login']) {
            throw new SpelFout('Je kunt je eigen wagen niet kopen.');
        }

        $prijs = (int) $aanbod['prijs'];

        lock_user((int) $user['id']);
        if (!afboeken((int) $user['id'], $prijs, 'zak')) {
            throw new SpelFout('Je hebt niet genoeg geld op zak.');
        }

        $verkoper = lock_user_by_login((string) $aanbod['login']);
        if ($verkoper !== null) {
            bijschrijven((int) $verkoper['id'], $prijs, 'zak');
            notify((string) $aanbod['login'], 'Wagen verkocht',
                'Je hebt je ' . $aanbod['naam'] . ' verkocht voor ' . money($prijs) . '.');
        }

        // De wagen komt in de garage van de koper, in de stad waar hij stond.
        q(
            'INSERT INTO `garage` (`login`, `naam`, `waarde`, `damage`, `stad`)
                  VALUES (?, ?, ?, ?, ?)',
            [$user['login'], $aanbod['naam'], $aanbod['waarde'], $aanbod['damage'], $aanbod['stad']]
        );
        q('DELETE FROM `mgarage` WHERE `id` = ?', [$id]);

        log_action((string) $user['login'], 'mshop',
            'Wagen gekocht: ' . $aanbod['naam'], $prijs, (string) $aanbod['login']);

        return 'Je hebt een ' . $aanbod['naam'] . ' gekocht voor ' . money($prijs)
             . ' met ' . (int) $aanbod['damage'] . '% schade.';
    });
}

/** @throws SpelFout */
function zet_auto(array $user, int $garageId, int $prijs): string
{
    if ($prijs < AUTO_MINPRIJS) {
        throw new SpelFout('De minimumprijs voor een wagen is ' . money(AUTO_MINPRIJS) . '.');
    }

    return db_transaction(static function () use ($user, $garageId, $prijs): string {
        // Eigendom in de query, niet in PHP: zo kun je geen andermans wagen verkopen.
        $auto = q_row(
            'SELECT * FROM `garage` WHERE `id` = ? AND `login` = ? FOR UPDATE',
            [$garageId, $user['login']]
        );

        if ($auto === null) {
            throw new SpelFout('Die wagen staat niet in jouw garage.');
        }
        if ((int) $auto['damage'] > AUTO_MAXSCHADE) {
            throw new SpelFout('Deze wagen is te zwaar beschadigd om te verkopen.');
        }

        q(
            'INSERT INTO `mgarage` (`login`, `naam`, `waarde`, `damage`, `stad`, `prijs`, `time`)
                  VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
            [
                $auto['login'], $auto['naam'], $auto['waarde'],
                $auto['damage'], $auto['stad'], $prijs, VERKOOPDUUR,
            ]
        );
        q('DELETE FROM `garage` WHERE `id` = ? AND `login` = ?', [$garageId, $user['login']]);

        return 'Je ' . $auto['naam'] . ' staat te koop voor ' . money($prijs) . '.';
    });
}

// --- Ooggetuigen ------------------------------------------------------------

/** @throws SpelFout */
function koop_ws(array $user, int $id): string
{
    return db_transaction(static function () use ($user, $id): string {
        $aanbod = q_row("SELECT * FROM `ws` WHERE `id` = ? AND `status` = 1 FOR UPDATE", [$id]);

        if ($aanbod === null) {
            throw new SpelFout('Deze verklaring is niet meer te koop.');
        }

        $prijs = (int) $aanbod['prijs'];

        lock_user((int) $user['id']);
        if (!afboeken((int) $user['id'], $prijs, 'zak')) {
            throw new SpelFout('Je hebt niet genoeg geld op zak.');
        }

        $verkoper = lock_user_by_login((string) $aanbod['login']);
        if ($verkoper !== null) {
            bijschrijven((int) $verkoper['id'], $prijs, 'zak');
            notify((string) $aanbod['login'], 'Ooggetuige verkocht',
                'Je verklaring over de moord op ' . $aanbod['victim'] . ' is verkocht voor '
                . money($prijs) . '.');
        }

        notify((string) $user['login'], 'Ooggetuige',
            'De moordenaar van ' . $aanbod['victim'] . ' is ' . $aanbod['suspect'] . '.');

        q('DELETE FROM `ws` WHERE `id` = ?', [$id]);

        return 'Je hebt de verklaring gekocht. De moordenaar van ' . $aanbod['victim']
             . ' is ' . $aanbod['suspect'] . '.';
    });
}

/** @throws SpelFout */
function zet_ws(array $user, int $id, int $prijs): string
{
    if ($prijs < WS_MINPRIJS) {
        throw new SpelFout('De minimumprijs is ' . money(WS_MINPRIJS) . '.');
    }
    if ($prijs > WS_MAXPRIJS) {
        throw new SpelFout('De maximumprijs is ' . money(WS_MAXPRIJS) . '.');
    }

    return db_transaction(static function () use ($user, $id, $prijs): string {
        // De verklaring moet van jou zijn. In de oude versie ontbrak deze
        // voorwaarde en werd `login` domweg overschreven met de verkoper.
        $ws = q_row(
            'SELECT * FROM `ws` WHERE `id` = ? AND `login` = ? AND `status` = 0 FOR UPDATE',
            [$id, $user['login']]
        );

        if ($ws === null) {
            throw new SpelFout('Je hebt geen verklaring met dit nummer die nog te koop gezet kan worden.');
        }

        q('UPDATE `ws` SET `status` = 1, `prijs` = ? WHERE `id` = ?', [$prijs, $id]);

        return 'Je verklaring over de moord op ' . $ws['victim'] . ' staat te koop voor '
             . money($prijs) . '.';
    });
}

// ==========================================================================
// Weergave
// ==========================================================================

function overzicht(): void
{
    $kogels = (int) q_val('SELECT COUNT(*) FROM `kogels`', [], 0);
    $autos  = (int) q_val('SELECT COUNT(*) FROM `mgarage`', [], 0);
    $ws     = (int) q_val('SELECT COUNT(*) FROM `ws` WHERE `status` = 1', [], 0);

    panel_open('Zwarte markt');
    echo '<p>Hier verhandel je onderling wat je niet in de gewone winkel kwijt kunt.</p>';
    echo '<ul>';
    echo '<li><a href="' . e(url('mshop.php?x=bullets')) . '">Kogels</a> (' . $kogels . ')</li>';
    echo '<li><a href="' . e(url('mshop.php?x=cars')) . '">Auto\'s</a> (' . $autos . ')</li>';
    echo '<li><a href="' . e(url('mshop.php?x=ws')) . '">Ooggetuigen</a> (' . $ws . ')</li>';
    echo '</ul>';
    panel_close();
}

/** Knop die één aanbieding koopt. */
function koopknop(string $actie, int $id, string $label): string
{
    return '<form method="post" style="margin:0">' . csrf_field()
         . '<input type="hidden" name="actie" value="' . e($actie) . '">'
         . '<input type="hidden" name="id" value="' . $id . '">'
         . '<button type="submit">' . e($label) . '</button></form>';
}

function kogels_lijst(): void
{
    $aanbod = q_all('SELECT * FROM `kogels` WHERE `time` > NOW() ORDER BY `time` ASC');

    panel_open('Kogels');
    echo '<p><a class="knop" href="' . e(url('mshop.php?x=bullets&page=sell')) . '">Kogels verkopen</a></p>';

    if ($aanbod === []) {
        echo '<p>Er staan geen kogels te koop.</p>';
    } else {
        echo '<div class="tabelwikkel"><table class="lijst">';
        echo '<thead><tr><th>Verkoper</th><th class="getal">Aantal</th><th class="getal">Prijs</th>'
           . '<th class="getal">Per kogel</th><th>Loopt af</th><th></th></tr></thead><tbody>';
        foreach ($aanbod as $r) {
            $perStuk = (int) $r['aantal'] > 0 ? (int) round((int) $r['prijs'] / (int) $r['aantal']) : 0;
            echo '<tr>'
               . '<td>' . speler_naam((string) $r['login']) . '</td>'
               . '<td class="getal">' . num((int) $r['aantal']) . '</td>'
               . '<td class="getal">' . money((int) $r['prijs']) . '</td>'
               . '<td class="getal">' . money($perStuk) . '</td>'
               . '<td>' . e(datetime_nl($r['time'])) . '</td>'
               . '<td>' . koopknop('koop_kogels', (int) $r['id'], 'Koop') . '</td>'
               . '</tr>';
        }
        echo '</tbody></table></div>';
    }
    panel_close();
}

function kogels_verkopen(array $user): void
{
    panel_open('Kogels verkopen');
    echo '<p>Je hebt ' . num((int) $user['kogels']) . ' kogels. Minimaal ' . KOGELS_MIN
       . ' per partij, minimaal ' . money(KOGELS_MINPRIJS) . ' totaal, en hoogstens '
       . money(KOGELS_MAXPERSTUK) . ' per kogel. Niet verkocht binnen zes uur? Dan krijg je ze terug.</p>';

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="zet_kogels">';
    echo '<div class="veldenraster">';
    echo '<label for="aantal">Aantal kogels</label>';
    echo '<input id="aantal" name="aantal" type="number" min="' . KOGELS_MIN . '" step="1" required>';
    echo '<label for="prijs">Vraagprijs</label>';
    echo '<input id="prijs" name="prijs" type="number" min="' . KOGELS_MINPRIJS . '" step="1" required>';
    echo '<span></span><button type="submit">Zet te koop</button>';
    echo '</div></form>';
    echo '<p><a href="' . e(url('mshop.php?x=bullets')) . '">Terug naar de kogellijst</a></p>';
    panel_close();
}

function autos_lijst(): void
{
    $aanbod = q_all('SELECT * FROM `mgarage` WHERE `time` > NOW() ORDER BY `time` ASC');

    panel_open('Auto\'s');
    echo '<p><a class="knop" href="' . e(url('mshop.php?x=cars&page=sell')) . '">Een wagen verkopen</a></p>';

    if ($aanbod === []) {
        echo '<p>Er staan geen auto\'s te koop.</p>';
    } else {
        echo '<div class="tabelwikkel"><table class="lijst">';
        echo '<thead><tr><th>Wagen</th><th>Stad</th><th class="getal">Schade</th>'
           . '<th class="getal">Waarde</th><th class="getal">Prijs</th><th>Loopt af</th><th></th></tr></thead><tbody>';
        foreach ($aanbod as $r) {
            echo '<tr>'
               . '<td>' . e((string) $r['naam']) . '</td>'
               . '<td>' . e((string) $r['stad']) . '</td>'
               . '<td class="getal">' . (int) $r['damage'] . '%</td>'
               . '<td class="getal">' . money((int) $r['waarde']) . '</td>'
               . '<td class="getal">' . money((int) $r['prijs']) . '</td>'
               . '<td>' . e(datetime_nl($r['time'])) . '</td>'
               . '<td>' . koopknop('koop_auto', (int) $r['id'], 'Koop') . '</td>'
               . '</tr>';
        }
        echo '</tbody></table></div>';
    }
    panel_close();
}

function autos_verkopen(array $user): void
{
    $garage = q_all(
        'SELECT * FROM `garage` WHERE `login` = ? AND `damage` <= ? ORDER BY `waarde` DESC',
        [$user['login'], AUTO_MAXSCHADE]
    );

    panel_open('Een wagen verkopen');

    if ($garage === []) {
        echo '<p>Je hebt geen wagens die verkocht kunnen worden. Wagens met meer dan '
           . AUTO_MAXSCHADE . '% schade kun je niet aanbieden.</p>';
    } else {
        echo '<form method="post">' . csrf_field();
        echo '<input type="hidden" name="actie" value="zet_auto">';
        echo '<div class="veldenraster">';
        echo '<label for="car">Wagen</label><select id="car" name="car" required>';
        foreach ($garage as $auto) {
            echo '<option value="' . (int) $auto['id'] . '">'
               . e((string) $auto['naam']) . ' - ' . e((string) $auto['stad'])
               . ' - ' . (int) $auto['damage'] . '% schade'
               . ' - waarde ' . money((int) $auto['waarde'])
               . '</option>';
        }
        echo '</select>';
        echo '<label for="prijs">Vraagprijs</label>';
        echo '<input id="prijs" name="prijs" type="number" min="' . AUTO_MINPRIJS . '" step="1" required>';
        echo '<span></span><button type="submit">Zet te koop</button>';
        echo '</div></form>';
    }

    echo '<p><a href="' . e(url('mshop.php?x=cars')) . '">Terug naar de autolijst</a></p>';
    panel_close();
}

function ws_lijst(): void
{
    $aanbod = q_all('SELECT * FROM `ws` WHERE `status` = 1 AND `time` > NOW() ORDER BY `time` ASC');

    panel_open('Ooggetuigen');
    echo '<p>Een ooggetuigenverklaring vertelt je wie een moord gepleegd heeft.</p>';
    echo '<p><a class="knop" href="' . e(url('mshop.php?x=ws&page=sell')) . '">Een verklaring verkopen</a></p>';

    if ($aanbod === []) {
        echo '<p>Er staan geen verklaringen te koop.</p>';
    } else {
        echo '<div class="tabelwikkel"><table class="lijst">';
        echo '<thead><tr><th>Moord op</th><th class="getal">Prijs</th><th>Loopt af</th><th></th></tr></thead><tbody>';
        foreach ($aanbod as $r) {
            echo '<tr>'
               . '<td><a' . speler_rol_attr((string) $r['victim']) . ' href="'
               . e(url('user.php?x=' . rawurlencode((string) $r['victim']))) . '">'
               . e((string) $r['victim']) . '</a></td>'
               . '<td class="getal">' . money((int) $r['prijs']) . '</td>'
               . '<td>' . e(datetime_nl($r['time'])) . '</td>'
               . '<td>' . koopknop('koop_ws', (int) $r['id'], 'Koop') . '</td>'
               . '</tr>';
        }
        echo '</tbody></table></div>';
    }
    panel_close();
}

function ws_verkopen(array $user): void
{
    // Alleen je eigen, nog niet aangeboden verklaringen. In de oude versie
    // typte je een nummer in en kon je die van een ander te koop zetten.
    $eigen = q_all(
        'SELECT * FROM `ws` WHERE `login` = ? AND `status` = 0 AND `time` > NOW() ORDER BY `time` ASC',
        [$user['login']]
    );

    panel_open('Een verklaring verkopen');

    if ($eigen === []) {
        echo '<p>Je hebt geen ooggetuigenverklaringen die je te koop kunt zetten. '
           . 'Je krijgt er een als je toevallig getuige bent van een moord.</p>';
    } else {
        echo '<form method="post">' . csrf_field();
        echo '<input type="hidden" name="actie" value="zet_ws">';
        echo '<div class="veldenraster">';
        echo '<label for="id">Verklaring</label><select id="id" name="id" required>';
        foreach ($eigen as $ws) {
            echo '<option value="' . (int) $ws['id'] . '">Moord op ' . e((string) $ws['victim'])
               . ' (geldig tot ' . e(datetime_nl($ws['time'])) . ')</option>';
        }
        echo '</select>';
        echo '<label for="prijs">Vraagprijs</label>';
        echo '<input id="prijs" name="prijs" type="number" min="' . WS_MINPRIJS
           . '" max="' . WS_MAXPRIJS . '" step="1" required>';
        echo '<span></span><button type="submit">Zet te koop</button>';
        echo '</div></form>';
    }

    echo '<p><a href="' . e(url('mshop.php?x=ws')) . '">Terug naar de ooggetuigenlijst</a></p>';
    panel_close();
}
