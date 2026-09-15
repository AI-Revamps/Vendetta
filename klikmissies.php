<?php
/**
 * Klikmissies: stem op externe toplijsten voor een beloning.
 *
 * Missies met een callback belonen automatisch zodra de stemsite zelf
 * klikmissies-callback.php aanroept. Missies zonder callback vereisen dat de
 * speler na het klikken op "Stem" bevestigt dat hij gestemd heeft; dat kan
 * pas na de ingestelde wachttijd, en die controle gebeurt hier server-side,
 * niet alleen in de afteller in de browser.
 */

declare(strict_types=1);

// De "Stem"-knop bij niet-callback-missies post naar deze pagina, die daarna
// pas doorverwijst naar de externe stemsite. Zie de toelichting bij
// BV_EXTERNE_DOORVERWIJZING in inc/bootstrap.php.
define('BV_EXTERNE_DOORVERWIJZING', true);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/klikmissies.php';

$user = require_login();

if (is_dead()) {
    redirect('rip.php');
}

$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        if (post('actie') === 'stem') {
            stem_klik($user, int_input('id'));   // redirect() bij succes, stopt het script hier
        }

        $melding = match (post('actie')) {
            'bevestig' => stem_bevestigen($user, int_input('id')),
            default    => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
        $user = current_user(true);
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Klikmissies');
panel_open('Klikmissies');

if ($melding !== null) {
    notice(e($melding), $type);
}

echo '<p>Steun de website door op een van deze links te stemmen. Elke missie heeft zijn '
   . 'eigen beloning en afkoeltijd.</p>';

toon_missies($user);

panel_close();
layout_footer();

// ==========================================================================

/** @throws SpelFout */
function stem_klik(array $user, int $id): void
{
    $missie = q_row('SELECT * FROM `klikmissies` WHERE `id` = ? AND `actief` = 1', [$id]);

    if ($missie === null) {
        throw new SpelFout('Die klikmissie bestaat niet meer.');
    }
    if ((int) $missie['heeft_callback'] === 1) {
        throw new SpelFout('Deze missie beloont automatisch; er is geen knop voor nodig.');
    }
    if (klikmissie_cooldown_tot($id, (int) $missie['cooldown_seconden'], $user['login']) > 0) {
        throw new SpelFout('Je moet nog even wachten voor je hier weer aan mag meedoen.');
    }

    $_SESSION['klikmissie_klik'][$id] = time();

    header('Referrer-Policy: origin');
    redirect(klikmissie_url($missie, $user['login']));
}

/** @throws SpelFout */
function stem_bevestigen(array $user, int $id): string
{
    $missie = q_row('SELECT * FROM `klikmissies` WHERE `id` = ? AND `actief` = 1', [$id]);

    if ($missie === null) {
        throw new SpelFout('Die klikmissie bestaat niet meer.');
    }

    $geklikt = $_SESSION['klikmissie_klik'][$id] ?? null;

    if ($geklikt === null) {
        throw new SpelFout('Klik eerst op "Stem" voordat je dit kunt bevestigen.');
    }
    if (time() - (int) $geklikt < (int) $missie['wachttijd_klik']) {
        throw new SpelFout('Dat ging te snel. Wacht nog even.');
    }

    klikmissie_belonen($missie, $user['login'], 'zelf', client_ip());
    unset($_SESSION['klikmissie_klik'][$id]);

    return 'Bedankt voor het stemmen! De beloning is bijgeschreven.';
}

// ==========================================================================

function toon_missies(array $user): void
{
    $missies = klikmissies_actief();

    if ($missies === []) {
        echo '<p>Er zijn op dit moment geen klikmissies.</p>';
        return;
    }

    foreach ($missies as $missie) {
        $id = (int) $missie['id'];

        echo '<div class="paneelinhoud">';
        echo '<h3>' . e((string) $missie['naam']) . '</h3>';

        if ((string) $missie['omschrijving'] !== '') {
            echo '<p>' . e((string) $missie['omschrijving']) . '</p>';
        }

        $wacht = klikmissie_cooldown_tot($id, (int) $missie['cooldown_seconden'], $user['login']);

        if ($wacht > 0) {
            echo '<p>Nog beschikbaar over <strong data-tot="' . $wacht . '">'
               . e(duration($wacht - time())) . '</strong>.</p>';
        } elseif ((int) $missie['heeft_callback'] === 1) {
            $venster = (int) $missie['nieuw_venster'] === 1 ? ' target="_blank" rel="noopener"' : '';
            echo '<p><a class="knop"' . $venster . ' referrerpolicy="origin" href="'
               . e(klikmissie_url($missie, $user['login'])) . '">Stem</a> '
               . '<span class="uitleg">De beloning komt automatisch na het stemmen.</span></p>';
        } else {
            toon_klikflow($missie, $id);
        }

        echo '</div>';
    }
}

function toon_klikflow(array $missie, int $id): void
{
    $geklikt = $_SESSION['klikmissie_klik'][$id] ?? null;

    if ($geklikt === null) {
        $venster = (int) $missie['nieuw_venster'] === 1 ? ' target="_blank"' : '';
        $wachtId = 'klikmissie-wacht-' . $id;

        echo '<form method="post"' . $venster . ' class="klikmissie-stem" data-wachttijd="'
           . (int) $missie['wachttijd_klik'] . '" data-doel="' . e($wachtId) . '">' . csrf_field();
        echo '<input type="hidden" name="actie" value="stem">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<button type="submit" class="knop">Stem</button>';
        echo '</form>';
        echo '<noscript><p class="uitleg">Vernieuw deze pagina na het stemmen om te bevestigen.</p></noscript>';

        // Verborgen tot de JavaScript hierboven hem na het klikken op "Stem"
        // meteen zichtbaar maakt, zodat de speler niet zelf hoeft te
        // vernieuwen. Zonder JavaScript verschijnt dit blok pas na een
        // handmatige vernieuwing van de pagina, via de tak hieronder.
        echo '<div id="' . e($wachtId) . '" hidden>';
        echo '<p>Wacht nog <strong>0:00</strong> en klik dan op bevestigen.</p>';
        echo '<form method="post">' . csrf_field();
        echo '<input type="hidden" name="actie" value="bevestig">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<button type="submit" class="knop" disabled>Ik heb gestemd</button>';
        echo '</form>';
        echo '</div>';
        return;
    }

    $magVanaf = (int) $geklikt + (int) $missie['wachttijd_klik'];

    if ($magVanaf > time()) {
        echo '<p>Wacht nog <strong data-tot="' . $magVanaf . '">'
           . e(duration($magVanaf - time())) . '</strong> en klik dan op bevestigen.</p>';
    }

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="actie" value="bevestig">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    echo '<button type="submit" class="knop"' . ($magVanaf > time() ? ' disabled' : '') . '>'
       . 'Ik heb gestemd</button>';
    echo '</form>';
}
