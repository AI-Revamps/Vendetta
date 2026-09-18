<?php
/**
 * Families: overzicht, familiepagina, oprichten en opheffen.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - De familienaam ging ongefilterd in de query. Er stond wel een
 *    preg_match-controle, maar die kwam ná de query die op de naam zocht.
 *  - Oprichten gebeurde in drie losse queries zonder transactie: als er iets
 *    tussendoor misging, stond je wel in een familie die niet bestond of
 *    andersom.
 *  - Familie-informatie werd ongeëscaped weergegeven.
 *  - Opheffen liet de familiebank en de openstaande uitnodigingen staan.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require_once BV_INC . '/familie.php';
require BV_INC . '/opmaak.php';

$user = require_login();

$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = verwerk($user, post('actie'));
        $type    = 'ok';
        $user    = current_user(true);
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Familie');

if ($melding !== null) {
    notice(e($melding), $type);
}

if (get('x') !== '') {
    toon_familie($user, get('x'));
} else {
    match (get('p')) {
        'new'    => toon_oprichten($user),
        'delete' => toon_opheffen($user),
        default  => toon_lijst($user),
    };
}

layout_footer();

// ==========================================================================

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'oprichten' => oprichten($user, post('name')),
        'opheffen'  => opheffen($user),
        'verlaten'  => verlaten($user),
        default     => throw new SpelFout('Onbekende handeling.'),
    };
}

/** @throws SpelFout */
function oprichten(array $user, string $naam): string
{
    $naam = trim(mb_substr($naam, 0, 20));

    if (!preg_match('/^[A-Za-z0-9_\- ]{3,20}$/', $naam)) {
        throw new SpelFout('De naam mag 3 tot 20 tekens lang zijn en alleen letters, '
            . 'cijfers, spaties, - en _ bevatten.');
    }
    if (is_dead()) {
        throw new SpelFout('Je bent dood.');
    }

    return db_transaction(static function () use ($user, $naam): string {
        $speler = lock_user((int) $user['id']);

        if ($speler['famillie'] !== '') {
            throw new SpelFout('Je zit al in een familie.');
        }
        if ((int) $speler['xp'] < FAM_OPRICHT_XP) {
            throw new SpelFout('Je moet minstens de rang Local Chief hebben om een familie te stichten.');
        }
        if ((int) q_val('SELECT COUNT(*) FROM `famillie` WHERE `name` = ?', [$naam], 0) > 0) {
            throw new SpelFout('Er bestaat al een familie met die naam.');
        }
        if (!afboeken((int) $speler['id'], FAM_OPRICHTKOSTEN, 'zak')) {
            throw new SpelFout('Een familie stichten kost ' . money(FAM_OPRICHTKOSTEN) . '.');
        }

        q('INSERT INTO `famillie` (`name`, `stad`, `grond`, `bank`) VALUES (?, ?, 50, 0)',
            [$naam, $speler['stad']]);
        q('UPDATE `users` SET `famillie` = ?, `famrang` = ? WHERE `id` = ?',
            [$naam, FAM_DON, $speler['id']]);

        log_action((string) $speler['login'], 'familie', 'Familie gesticht: ' . $naam, FAM_OPRICHTKOSTEN);

        return 'De familie ' . $naam . ' is gesticht. Jij bent de Don.';
    });
}

/** @throws SpelFout */
function opheffen(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $familie = fam_van($user, true);

        if ($familie === null || (int) $user['famrang'] !== FAM_DON) {
            throw new SpelFout('Alleen de Don kan de familie opheffen.');
        }

        $naam = (string) $familie['name'];

        foreach (fam_leden($naam) as $lid) {
            if ($lid['login'] !== $user['login']) {
                notify((string) $lid['login'], 'Familie',
                    'De familie ' . $naam . ' is opgeheven door de Don.');
            }
        }

        q("UPDATE `users` SET `famillie` = '', `famrang` = 0, `famcapo` = '' WHERE `famillie` = ?", [$naam]);
        q('DELETE FROM `invite` WHERE `famillie` = ?', [$naam]);
        q('DELETE FROM `famillie` WHERE `name` = ?', [$naam]);

        return 'De familie ' . $naam . ' is opgeheven.';
    });
}

/** @throws SpelFout */
function verlaten(array $user): string
{
    return db_transaction(static function () use ($user): string {
        $familie = fam_van($user, true);

        if ($familie === null) {
            throw new SpelFout('Je zit niet in een familie.');
        }
        if ((int) $user['famrang'] === FAM_DON) {
            throw new SpelFout('Als Don kun je de familie niet zomaar verlaten. '
                . 'Draag eerst het leiderschap over of hef de familie op.');
        }

        q("UPDATE `users` SET `famillie` = '', `famrang` = 0, `famcapo` = '' WHERE `id` = ?",
            [$user['id']]);

        notify((string) $familie['name'], 'Familie', $user['login'] . ' heeft de familie verlaten.');

        return 'Je hebt de familie ' . $familie['name'] . ' verlaten.';
    });
}

// ==========================================================================
// Weergave
// ==========================================================================

function toon_lijst(array $user): void
{
    $families = q_all(
        "SELECT f.*,
                (SELECT COUNT(*) FROM `users` u
                  WHERE u.`famillie` = f.`name` AND u.`status` = 'levend') AS `leden`,
                (SELECT SUM(u.`xp`) FROM `users` u
                  WHERE u.`famillie` = f.`name` AND u.`status` = 'levend') AS `ervaring`
           FROM `famillie` f
       ORDER BY `ervaring` DESC, `leden` DESC"
    );

    panel_open('Families');

    if ($families === []) {
        echo '<p>Er zijn nog geen families.</p>';
    } else {
        echo '<div class="tabelwikkel"><table class="lijst">';
        echo '<thead><tr><th class="getal">Positie</th><th>Familie</th><th>Stad</th>'
           . '<th class="getal">Leden</th></tr></thead><tbody>';
        foreach ($families as $positie => $familie) {
            echo '<tr>';
            echo '<td class="getal">' . num($positie + 1) . '</td>';
            echo '<td><a href="' . e(url('fam.php?x=' . rawurlencode((string) $familie['name']))) . '">'
               . e((string) $familie['name']) . '</a></td>';
            echo '<td>' . e((string) $familie['stad']) . '</td>';
            echo '<td class="getal">' . num((int) $familie['leden']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    panel_close();

    if (($user['famillie'] ?? '') === '') {
        panel_open('Zelf een familie beginnen');
        echo '<p>Een familie stichten kost ' . money(FAM_OPRICHTKOSTEN)
           . ' en je hebt minstens de rang Local Chief nodig.</p>';
        echo '<p><a class="knop" href="' . e(url('fam.php?p=new')) . '">Familie stichten</a></p>';
        panel_close();
    }
}

function toon_familie(array $user, string $naam): void
{
    $familie = q_row('SELECT * FROM `famillie` WHERE `name` = ?', [$naam]);

    if ($familie === null) {
        panel_open('Familie');
        notice('Die familie bestaat niet.', 'fout');
        panel_close();
        return;
    }

    $leden    = fam_leden((string) $familie['name']);
    $eigenFam = $user['famillie'] === $familie['name'];

    panel_open((string) $familie['name']);

    if (($familie['pic'] ?? '') !== '') {
        $plaatje = veilige_url((string) $familie['pic']);
        if ($plaatje !== null) {
            echo '<p><img src="' . e($plaatje) . '" alt="" style="max-width:100%;border-radius:4px"></p>';
        }
    }

    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<tr><th scope="row">Stad</th><td>' . e((string) $familie['stad']) . '</td></tr>';
    echo '<tr><th scope="row">Leden</th><td>' . num(count($leden)) . '</td></tr>';
    echo '<tr><th scope="row">Grondgebied</th><td>' . num((int) $familie['grond']) . '</td></tr>';
    if ($eigenFam && (int) $user['famrang'] >= FAM_CONSIGLIERI) {
        echo '<tr><th scope="row">In kas</th><td>' . money((int) $familie['bank']) . '</td></tr>';
    }
    echo '</table></div>';

    if (($familie['info'] ?? '') !== '') {
        echo '<div class="berichttekst">' . bericht_html((string) $familie['info']) . '</div>';
    }

    panel_close();

    // --- Leden ---
    panel_open('Leden');
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Speler</th><th>Rang</th><th>Spelrang</th><th>Stad</th></tr></thead><tbody>';
    foreach ($leden as $lid) {
        $dood = $lid['status'] !== 'levend';
        echo '<tr>';
        echo '<td><a' . speler_rol_attr((string) $lid['login']) . ' href="'
           . e(url('user.php?x=' . rawurlencode((string) $lid['login']))) . '">'
           . e((string) $lid['login']) . '</a>' . ($dood ? ' <small>(dood)</small>' : '') . '</td>';
        echo '<td>' . e(fam_rangnaam((int) $lid['famrang'])) . '</td>';
        echo '<td>' . e(rank_name((int) $lid['xp'], (string) $lid['geslacht'])) . '</td>';
        echo '<td>' . e((string) $lid['stad']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    panel_close();

    if ($eigenFam && (int) $user['famrang'] !== FAM_DON) {
        panel_open('Familie verlaten');
        echo '<p>Je verliest je rang en alle rechten binnen de familie.</p>';
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="actie" value="verlaten">'
           . '<button type="submit">Verlaat ' . e((string) $familie['name']) . '</button></form>';
        panel_close();
    }
}

function toon_oprichten(array $user): void
{
    panel_open('Familie stichten');

    if (($user['famillie'] ?? '') !== '') {
        echo '<p>Je zit al in een familie.</p>';
    } elseif ((int) $user['xp'] < FAM_OPRICHT_XP) {
        echo '<p>Je moet minstens de rang Local Chief hebben.</p>';
    } else {
        echo '<p>Een familie stichten kost ' . money(FAM_OPRICHTKOSTEN)
           . '. Je hebt ' . money((int) $user['zak']) . ' op zak.</p>';
        echo '<form method="post">' . csrf_field();
        echo '<input type="hidden" name="actie" value="oprichten">';
        echo '<div class="veldenraster">';
        echo '<label for="name">Naam van de familie</label>';
        echo '<input id="name" name="name" maxlength="20" pattern="[A-Za-z0-9_\- ]{3,20}" required '
           . 'value="' . e(post('name')) . '">';
        echo '<span></span><small>3 tot 20 tekens. Deze naam kun je later niet wijzigen.</small>';
        echo '<span></span><button type="submit">Stichten</button>';
        echo '</div></form>';
    }

    panel_close();
}

function toon_opheffen(array $user): void
{
    panel_open('Familie opheffen');

    if ((int) $user['famrang'] !== FAM_DON) {
        echo '<p>Alleen de Don kan de familie opheffen.</p>';
    } else {
        $leden = fam_aantal_leden((string) $user['famillie']);
        echo '<p>Je staat op het punt <strong>' . e((string) $user['famillie'])
           . '</strong> op te heffen. Alle ' . $leden . ' leden verliezen hun rang, '
           . 'en wat er in de kas zit is verloren.</p>';
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="actie" value="opheffen">'
           . '<button type="submit">Ja, hef de familie op</button> '
           . '<a class="knop" href="' . e(url('fam.php?x=' . rawurlencode((string) $user['famillie'])))
           . '">Annuleren</a></form>';
    }

    panel_close();
}
