<?php
/**
 * Lokale kogelfabriek: een fabriek met een speler als eigenaar.
 *
 * De eigenaar koopt kogels in en bepaalt de verkoopprijs. Andere spelers kopen
 * eruit; de opbrengst gaat naar de bankrekening van de eigenaar. De voorraad
 * staat in `casino`.`winst`, de verkoopprijs in `casino`.`inzet`.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - De eigenaar kreeg maar de HELFT van de verkoopprijs uitbetaald, en de
 *    andere helft ging nergens heen. Omdat inkopen 100 per kogel kost en de
 *    minimumprijs 50 was, verloor de eigenaar per definitie geld op elke
 *    verkoop. Hij krijgt nu de volle opbrengst.
 *  - Bij het overdragen van de fabriek werd de voorraad op 100 gezet. Die
 *    honderd kogels kwamen uit het niets. De voorraad blijft nu staan.
 *  - Betalen, voorraad bijwerken en uitbetalen gebeurde in losse queries
 *    zonder transactie.
 *  - De minimumprijs werd gecontroleerd met een losse vergelijking op tekst,
 *    zonder te controleren of er wel een getal was ingevuld.
 *  - Kopen was mogelijk zonder CSRF-bescherming.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/casino.php';

const FABRIEK_INKOOPPRIJS = 100;
const FABRIEK_MINPRIJS    = 50;
const FABRIEK_WACHTTIJD   = 3600;

$user = require_login();

if (is_dead()) {
    redirect('rip.php');
}
block_if_jailed();

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

$fabriek  = casino_spel('kogelfabriek', (string) $user['stad']);
$eigenaar = casino_eigenaar($fabriek);
$wacht    = cooldown_left((int) $user['slaap_ts']);

layout_header('Lokale kogelfabriek');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('Kogelfabriek in ' . $user['stad']);

if ($eigenaar === null) {
    echo '<p>Deze fabriek heeft geen eigenaar. Je kunt hem kopen voor '
       . money(CASINO_PRIJS) . '.</p>';
    echo '<form method="post">' . csrf_field()
       . '<input type="hidden" name="actie" value="koop">'
       . '<button type="submit">Koop deze fabriek</button></form>';
} elseif ($eigenaar['login'] === $user['login']) {
    toon_beheer($user, $fabriek);
} else {
    toon_winkel($user, $fabriek, $eigenaar, $wacht);
}

panel_close();
layout_footer();

// ==========================================================================

/** @throws SpelFout */
function verwerk(array $user, string $actie): string
{
    return match ($actie) {
        'koop'      => casino_kopen($user, 'kogelfabriek', (string) $user['stad']),
        'prijs'     => prijs_zetten($user, int_input('prijs', 0)),
        'inkopen'   => inkopen($user, int_input('aantal', 0)),
        'verkoop'   => verkopen($user, int_input('aantal', 0)),
        default     => throw new SpelFout('Onbekende handeling.'),
    };
}

/** @throws SpelFout */
function prijs_zetten(array $user, int $prijs): string
{
    $fabriek = casino_spel('kogelfabriek', (string) $user['stad']);

    if ($fabriek['owner'] !== $user['login']) {
        throw new SpelFout('Deze fabriek is niet van jou.');
    }
    if ($prijs < FABRIEK_MINPRIJS) {
        throw new SpelFout('De prijs moet minstens ' . money(FABRIEK_MINPRIJS) . ' per kogel zijn.');
    }

    q('UPDATE `casino` SET `inzet` = ? WHERE `id` = ?', [$prijs, $fabriek['id']]);

    return 'De kogelprijs staat nu op ' . money($prijs) . ' per stuk.';
}

/** @throws SpelFout */
function inkopen(array $user, int $aantal): string
{
    if ($aantal < 1) {
        throw new SpelFout('Vul een aantal van minstens 1 in.');
    }

    return db_transaction(static function () use ($user, $aantal): string {
        $fabriek = casino_spel('kogelfabriek', (string) $user['stad'], true);

        if ($fabriek['owner'] !== $user['login']) {
            throw new SpelFout('Deze fabriek is niet van jou.');
        }

        $kosten = $aantal * FABRIEK_INKOOPPRIJS;

        lock_user((int) $user['id']);

        if (!afboeken((int) $user['id'], $kosten, 'zak')) {
            throw new SpelFout('Dit kost ' . money($kosten) . ' en zoveel heb je niet op zak.');
        }

        q('UPDATE `casino` SET `winst` = `winst` + ? WHERE `id` = ?', [$aantal, $fabriek['id']]);

        return 'Je hebt ' . num($aantal) . ' kogels ingekocht voor ' . money($kosten) . '.';
    });
}

/** @throws SpelFout */
function verkopen(array $user, int $aantal): string
{
    if ($aantal < 1) {
        throw new SpelFout('Vul een aantal van minstens 1 in.');
    }

    return db_transaction(static function () use ($user, $aantal): string {
        $speler = lock_user((int) $user['id']);

        if (cooldown_left((int) q_val('SELECT UNIX_TIMESTAMP(`slaap`) FROM `users` WHERE `id` = ?',
                [$speler['id']], 0)) > 0) {
            throw new SpelFout('Je moet nog wachten voordat je weer kogels kunt kopen.');
        }

        $fabriek = casino_spel('kogelfabriek', (string) $speler['stad'], true);

        if (($fabriek['owner'] ?? '') === '') {
            throw new SpelFout('Deze fabriek heeft geen eigenaar.');
        }
        if ($fabriek['owner'] === $speler['login']) {
            throw new SpelFout('Je kunt niet bij jezelf kopen.');
        }
        if ($aantal > (int) $fabriek['winst']) {
            throw new SpelFout('Er zijn maar ' . num((int) $fabriek['winst']) . ' kogels in voorraad.');
        }

        $prijs = $aantal * (int) $fabriek['inzet'];

        if (!afboeken((int) $speler['id'], $prijs, 'zak')) {
            throw new SpelFout('Dit kost ' . money($prijs) . ' en zoveel heb je niet op zak.');
        }

        // De eigenaar krijgt de volle opbrengst. In de oude versie was dat de
        // helft en verdween de rest, waardoor de fabriek altijd verliesgevend was.
        $eigenaar = lock_user_by_login((string) $fabriek['owner']);

        if ($eigenaar !== null) {
            bijschrijven((int) $eigenaar['id'], $prijs, 'bank');
        }

        q('UPDATE `casino` SET `winst` = `winst` - ? WHERE `id` = ?', [$aantal, $fabriek['id']]);
        q('UPDATE `users` SET `kogels` = `kogels` + ?, `slaap` = DATE_ADD(NOW(), INTERVAL ? SECOND)
            WHERE `id` = ?',
            [$aantal, FABRIEK_WACHTTIJD, $speler['id']]);

        return 'Je hebt ' . num($aantal) . ' kogels gekocht voor ' . money($prijs) . '.';
    });
}

// ==========================================================================

function toon_beheer(array $user, array $fabriek): void
{
    echo '<p>Deze fabriek is van jou. Voorraad: <strong>' . num((int) $fabriek['winst'])
       . '</strong> kogels. Verkoopprijs: <strong>' . money((int) $fabriek['inzet'])
       . '</strong> per stuk.</p>';
    echo '<p>Inkopen kost ' . money(FABRIEK_INKOOPPRIJS) . ' per kogel. '
       . 'Zet je prijs daarboven, dan verdien je eraan.</p>';

    echo '<h3>Kogels inkopen</h3>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="inkopen">';
    echo '<div class="veldenraster">';
    echo '<label for="aantal">Aantal</label>';
    echo '<input id="aantal" name="aantal" type="number" min="1" step="1" required>';
    echo '<span></span><button type="submit">Inkopen</button>';
    echo '</div></form>';

    echo '<h3>Verkoopprijs</h3>';
    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="prijs">';
    echo '<div class="veldenraster">';
    echo '<label for="prijs">Prijs per kogel</label>';
    echo '<input id="prijs" name="prijs" type="number" min="' . FABRIEK_MINPRIJS
       . '" step="1" value="' . (int) $fabriek['inzet'] . '" required>';
    echo '<span></span><button type="submit">Aanpassen</button>';
    echo '</div></form>';
}

function toon_winkel(array $user, array $fabriek, array $eigenaar, int $wacht): void
{
    $voorraad = (int) $fabriek['winst'];
    $prijs    = (int) $fabriek['inzet'];

    echo '<p>Eigenaar: <strong>' . speler_naam((string) $eigenaar['login']) . '</strong>. '
       . 'Voorraad: ' . num($voorraad) . ' kogels à ' . money($prijs) . '.</p>';

    if ($wacht > 0) {
        echo '<p>Je moet nog <strong data-tot="' . (time() + $wacht) . '">' . e(duration($wacht))
           . '</strong> wachten voordat je weer kogels kunt kopen.</p>';
        return;
    }
    if ($voorraad < 1) {
        echo '<p>De fabriek is uitverkocht.</p>';
        return;
    }

    $max = min($voorraad, $prijs > 0 ? intdiv((int) $user['zak'], $prijs) : 0);

    if ($max < 1) {
        echo '<p>Je hebt niet genoeg geld voor een kogel.</p>';
        return;
    }

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="verkoop">';
    echo '<div class="veldenraster">';
    echo '<label for="aantal">Aantal kogels</label>';
    echo '<input id="aantal" name="aantal" type="number" min="1" max="' . $max . '" step="1" required>';
    echo '<span></span><button type="submit">Kopen</button>';
    echo '</div></form>';
    echo '<p class="uitleg">Je kunt er nu hoogstens ' . num($max) . ' kopen. '
       . 'Na een aankoop moet je ' . e(duration(FABRIEK_WACHTTIJD)) . ' wachten.</p>';
}
