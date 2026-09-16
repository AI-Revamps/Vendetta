<?php
/**
 * Spelerslijst met zoeken, filteren en sorteren.
 *
 * Wat hier gerepareerd is:
 *
 *  - `ORDER BY $sort $order` kwam rechtstreeks uit de URL. Daarmee viel niet
 *    alleen te sorteren op elke kolom, maar ook van alles achteraan de query
 *    te plakken. Nu een lijst met toegestane kolommen.
 *  - De zoekterm ging ongefilterd in een LIKE, inclusief `%` en `_`, dus wie
 *    een `%` intypte kreeg iedereen te zien en wie een aanhalingsteken intypte
 *    brak de query.
 *  - Acht bijna identieke queries voor de combinaties van filters, en daarna
 *    nog eens zes om het totaal te tellen. Nu één query met opgebouwde
 *    voorwaarden, en één COUNT.
 *  - Het totaal werd geteld met `SELECT id FROM users` en mysql_num_rows():
 *    álle rijen ophalen om alleen het aantal te weten.
 *  - De rang werd bepaald met vijftien elseif-takken die naar variabelen
 *    verwezen (`$rang1` … `$rang15`) die uit rangen.php kwamen. Stond die niet
 *    ingeladen, dan bleef de kolom leeg. Nu rank_name() uit inc/game.php.
 *  - `$_REQUEST['q']` werd ongefilterd in het waarde-attribuut van het
 *    zoekveld gezet: een XSS op elke bezoeker die op zo'n link klikte.
 *  - `($usr->status == levend)` vergelijkt met een ongedefinieerde constante.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

const PER_PAGINA = 20;

/** Waarop gesorteerd mag worden: van de URL-waarde naar de echte kolom. */
function sorteerkolommen(): array
{
    return [
        'login'  => ['`login`',   'Naam'],
        'xp'     => ['`xp`',      'Rang'],
        'status' => ['`status`',  'Status'],
        'online' => ['`online`',  'Online'],
    ];
}

$user = require_login();

$sorteer = isset(sorteerkolommen()[get('s')]) ? get('s') : 'xp';
$richting = strtoupper(get('o')) === 'ASC' ? 'ASC' : 'DESC';
$filter   = in_array(get('filter'), ['levend', 'dood', 'online'], true) ? get('filter') : '';
$zoek     = mb_substr(trim(get('q')), 0, 16);
$pagina   = int_input('p', 0, 0);

// --- Voorwaarden opbouwen --------------------------------------------------

$waar   = ['`activated` = 1'];
$params = [];

if ($filter === 'levend' || $filter === 'dood') {
    $waar[]   = '`status` = ?';
    $params[] = $filter;
} elseif ($filter === 'online') {
    $waar[] = '`online` > DATE_SUB(NOW(), INTERVAL 5 MINUTE)';
}

if ($zoek !== '') {
    // % en _ zijn jokers in LIKE; wie ze intypt bedoelt de tekens zelf.
    $waar[]   = '`login` LIKE ? ESCAPE \'\\\\\'';
    $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $zoek) . '%';
}

$voorwaarde = 'WHERE ' . implode(' AND ', $waar);
$totaal     = (int) q_val('SELECT COUNT(*) FROM `users` ' . $voorwaarde, $params, 0);
$paginas    = max(1, (int) ceil($totaal / PER_PAGINA));
$pagina     = min($pagina, $paginas - 1);

$spelers = q_all(
    'SELECT `login`, `xp`, `status`, UNIX_TIMESTAMP(`online`) AS `online`
       FROM `users` ' . $voorwaarde . '
      ORDER BY ' . sorteerkolommen()[$sorteer][0] . ' ' . $richting . ', `login`
      LIMIT ' . PER_PAGINA . ' OFFSET ' . ($pagina * PER_PAGINA),
    $params
);

// --- Weergave --------------------------------------------------------------

layout_header('Spelers');

panel_open('Zoeken');
echo '<form method="get">';
echo '<input type="hidden" name="s" value="' . e($sorteer) . '">';
echo '<input type="hidden" name="o" value="' . e($richting) . '">';
echo '<div class="veldenraster">';
echo '<label for="q">Naam bevat</label>';
echo '<input id="q" name="q" maxlength="16" value="' . e($zoek) . '">';
echo '<label for="filter">Tonen</label>';
echo '<select id="filter" name="filter">';
foreach (['' => 'Alle spelers', 'levend' => 'Levend', 'dood' => 'Dood', 'online' => 'Online'] as $k => $v) {
    echo '<option value="' . e($k) . '"' . ($k === $filter ? ' selected' : '') . '>'
       . e($v) . '</option>';
}
echo '</select>';
echo '<span></span><button type="submit">Zoeken</button>';
echo '</div></form>';
panel_close();

panel_open('Spelers (' . num($totaal) . ')');

if ($spelers === []) {
    echo '<p>Geen spelers gevonden.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst"><thead><tr>';

    foreach (sorteerkolommen() as $sleutel => [, $label]) {
        $andersom = ($sleutel === $sorteer && $richting === 'ASC') ? 'DESC' : 'ASC';
        $pijl     = $sleutel === $sorteer ? ($richting === 'ASC' ? ' ▲' : ' ▼') : '';

        echo '<th><a href="' . e(lijstlink($sleutel, $andersom, $filter, $zoek, 0)) . '">'
           . e($label) . $pijl . '</a></th>';
    }

    echo '</tr></thead><tbody>';

    foreach ($spelers as $speler) {
        $online = (int) $speler['online'] > time() - 300;

        echo '<tr>';
        echo '<td><a' . speler_rol_attr((string) $speler['login']) . ' href="'
           . e(url('user.php?x=' . rawurlencode((string) $speler['login'])))
           . '">' . e((string) $speler['login']) . '</a></td>';
        echo '<td>' . e(rank_name((int) $speler['xp'])) . '</td>';
        echo '<td>' . ($speler['status'] === 'levend'
            ? '<span class="levend">levend</span>' : '<span class="dood">dood</span>') . '</td>';
        echo '<td>' . ($online ? 'ja' : 'nee') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

if ($paginas > 1) {
    echo '<p class="paginering">';
    for ($i = 0; $i < $paginas; $i++) {
        echo $i === $pagina
            ? '<strong>' . ($i + 1) . '</strong> '
            : '<a href="' . e(lijstlink($sorteer, $richting, $filter, $zoek, $i)) . '">'
              . ($i + 1) . '</a> ';
    }
    echo '</p>';
}

panel_close();
layout_footer();

// ==========================================================================

function lijstlink(string $sorteer, string $richting, string $filter, string $zoek, int $pagina): string
{
    return url('members.php?' . http_build_query([
        's'      => $sorteer,
        'o'      => $richting,
        'filter' => $filter,
        'q'      => $zoek,
        'p'      => $pagina,
    ]));
}
