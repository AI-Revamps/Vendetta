<?php
/**
 * Premielijst: zet een prijs op iemands hoofd, of koop iemand er weer af.
 *
 * De oude versie zette $_POST['prijs'] ongefilterd in de query en boekte het
 * bedrag af zonder transactie. Bovendien werd de prijs niet als getal
 * gecontroleerd.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

$user = require_login();

if (is_dead()) {
    redirect('rip.php');
}

$melding = null;
$type    = 'info';

// --- Iemand van de lijst afkopen -----------------------------------------
if (is_post() && post('actie') === 'afkopen') {
    csrf_check();
    block_if_jailed();
    try {
        $melding = afkopen($user, post('doelwit'));
        $type    = 'ok';
        $user    = current_user(true);
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

// --- Iemand op de lijst zetten --------------------------------------------
if (is_post() && post('actie') === 'plaatsen') {
    csrf_check();
    block_if_jailed();
    try {
        $melding = plaatsen($user, post('naam'), int_input('prijs', 0, 0));
        $type    = 'ok';
        $user    = current_user(true);
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

$lijst = q_all(
    'SELECT h.`login`, h.`prijs`, u.`xp`, u.`stad`
       FROM `hitlist` h
       JOIN `users` u ON u.`login` = h.`login`
      WHERE u.`status` = \'levend\'
   ORDER BY h.`prijs` DESC'
);

layout_header('Premielijst');

if ($melding !== null) {
    notice(e($melding), $type);
}

// --- De lijst ---
panel_open('Premielijst');

if ($lijst === []) {
    echo '<p>Er staat op dit moment niemand op de premielijst.</p>';
} else {
    echo '<p>Wie iemand vermoordt die op deze lijst staat, krijgt de premie uitbetaald. '
       . 'Je kunt iemand ook van de lijst kopen voor het dubbele bedrag.</p>';
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Speler</th><th>Stad</th><th class="getal">Premie</th><th>Afkopen</th></tr></thead><tbody>';

    foreach ($lijst as $rij) {
        $afkoop = (int) $rij['prijs'] * 2;

        echo '<tr>';
        echo '<td><a' . speler_rol_attr((string) $rij['login']) . ' href="'
           . e(url('user.php?x=' . rawurlencode((string) $rij['login']))) . '">'
           . e((string) $rij['login']) . '</a></td>';
        echo '<td>' . e((string) $rij['stad']) . '</td>';
        echo '<td class="getal">' . money((int) $rij['prijs']) . '</td>';
        echo '<td><form method="post" style="margin:0">' . csrf_field()
           . '<input type="hidden" name="actie" value="afkopen">'
           . '<input type="hidden" name="doelwit" value="' . e((string) $rij['login']) . '">'
           . '<button type="submit">' . money($afkoop) . '</button></form></td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

panel_close();

// --- Iemand toevoegen ---
panel_open('Zet iemand op de premielijst');
echo '<p>De minimale premie hangt af van de rang van je doelwit. Het bedrag wordt '
   . 'meteen van je zakgeld afgeschreven.</p>';

echo '<form method="post">' . csrf_field();
echo '<input type="hidden" name="actie" value="plaatsen">';
echo '<div class="veldenraster">';
echo '<label for="naam">Naam</label>';
echo '<input id="naam" name="naam" maxlength="16" required>';
echo '<label for="prijs">Premie</label>';
echo '<input id="prijs" name="prijs" type="number" min="1" step="1" required inputmode="numeric">';
echo '<span></span><button type="submit">Zet erop</button>';
echo '</div></form>';

echo '<h3>Minimale premie per rang</h3>';
echo '<div class="tabelwikkel"><table class="lijst">';
echo '<thead><tr><th>Rang</th><th class="getal">Minimale premie</th></tr></thead><tbody>';
$vorigeGrens = 0;
foreach (premie_tabel() as [$maxXp, $bedrag]) {
    $label = $maxXp === PHP_INT_MAX
        ? 'vanaf ' . e(rank_name($vorigeGrens))
        : 'tot en met ' . e(rank_name($maxXp - 1));
    echo '<tr><td>' . $label . '</td><td class="getal">' . money($bedrag) . '</td></tr>';
    $vorigeGrens = $maxXp;
}
echo '</tbody></table></div>';

panel_close();
layout_footer();

// ==========================================================================

/** Minimale premie per ervaringsniveau: [bovengrens xp, bedrag]. */
function premie_tabel(): array
{
    return [
        [20,          100_000],
        [50,          150_000],
        [150,         250_000],
        [500,         500_000],
        [1000,      1_000_000],
        [2000,      2_000_000],
        [4500,      3_000_000],
        [6000,      3_000_000],
        [11000,     4_000_000],
        [15000,     5_000_000],
        [20000,     7_500_000],
        [PHP_INT_MAX, 10_000_000],
    ];
}

/** Minimale premie voor een speler met dit aantal ervaringspunten. */
function minimale_premie(int $xp): int
{
    foreach (premie_tabel() as [$maxXp, $bedrag]) {
        if ($xp < $maxXp) {
            return $bedrag;
        }
    }
    return 10_000_000;
}

/** @throws SpelFout */
function plaatsen(array $user, string $naam, int $prijs): string
{
    if ($naam === '') {
        throw new SpelFout('Vul een naam in.');
    }
    if (strcasecmp($naam, (string) $user['login']) === 0) {
        throw new SpelFout('Je kunt jezelf niet op de premielijst zetten.');
    }
    if ($prijs < 1) {
        throw new SpelFout('Vul een geldige premie in.');
    }

    return db_transaction(static function () use ($user, $naam, $prijs): string {
        $doelwit = lock_user_by_login($naam);

        if ($doelwit === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }
        if ($doelwit['status'] !== 'levend') {
            throw new SpelFout('Die speler is al dood.');
        }
        if ((int) $doelwit['level'] >= LEVEL_MODERATOR) {
            throw new SpelFout('Je kunt geen premie zetten op iemand van de staf.');
        }

        $bestaat = q_val('SELECT COUNT(*) FROM `hitlist` WHERE `login` = ?', [$doelwit['login']], 0);
        if ((int) $bestaat > 0) {
            throw new SpelFout($doelwit['login'] . ' staat al op de premielijst.');
        }

        $minimum = minimale_premie((int) $doelwit['xp']);
        if ($prijs < $minimum) {
            throw new SpelFout(
                'De minimale premie voor iemand met deze rang is '
                . money($minimum) . '.'
            );
        }

        lock_user((int) $user['id']);
        if (!afboeken((int) $user['id'], $prijs, 'zak')) {
            throw new SpelFout('Je hebt niet genoeg geld op zak.');
        }

        q('INSERT INTO `hitlist` (`login`, `prijs`, `suspect`) VALUES (?, ?, ?)',
            [$doelwit['login'], $prijs, $user['login']]);

        notify((string) $doelwit['login'], 'Premielijst',
            'Er is een premie van ' . money($prijs) . ' op je hoofd gezet.');

        log_action((string) $user['login'], 'hitlist',
            'Premie gezet op ' . $doelwit['login'], $prijs, (string) $doelwit['login']);

        return $doelwit['login'] . ' staat nu op de premielijst voor '
             . money($prijs) . '.';
    });
}

/** @throws SpelFout */
function afkopen(array $user, string $naam): string
{
    if ($naam === '') {
        throw new SpelFout('Kies wie je van de lijst wilt kopen.');
    }

    return db_transaction(static function () use ($user, $naam): string {
        // Vergrendel de vermelding, zodat twee kopers niet allebei betalen.
        $vermelding = q_row('SELECT * FROM `hitlist` WHERE `login` = ? FOR UPDATE', [$naam]);

        if ($vermelding === null) {
            throw new SpelFout('Die speler staat niet op de premielijst.');
        }

        $kosten = (int) $vermelding['prijs'] * 2;

        lock_user((int) $user['id']);
        if (!afboeken((int) $user['id'], $kosten, 'zak')) {
            throw new SpelFout(
                'Je hebt ' . money($kosten) . ' op zak nodig om deze premie af te kopen.'
            );
        }

        q('DELETE FROM `hitlist` WHERE `login` = ?', [$vermelding['login']]);

        notify((string) $vermelding['suspect'], 'Premielijst',
            $vermelding['login'] . ' is van de premielijst gekocht.');
        notify((string) $vermelding['login'], 'Premielijst',
            'Je bent van de premielijst gekocht door ' . $user['login'] . '.');

        log_action((string) $user['login'], 'hitlist',
            'Premie afgekocht van ' . $vermelding['login'], $kosten, (string) $vermelding['login']);

        return 'Je hebt ' . $vermelding['login'] . ' van de premielijst gekocht voor '
             . money($kosten) . '.';
    });
}
