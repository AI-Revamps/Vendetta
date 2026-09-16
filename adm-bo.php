<?php
/**
 * Spelergegevens bewerken. Alleen voor de eigenaar.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - Het controlespoor werd als privébericht naar de hardcoded gebruikersnaam
 *    'JanuS' gestuurd. Bestaat die speler niet, dan verdween het bericht en
 *    was er dus geen enkel spoor van wie wat had aangepast. Wijzigingen gaan
 *    nu naar het logboek, per gewijzigd veld.
 *  - Ervaring bijschrijven kon met adm-bo.php?x=xp&u=Naam, een GET-link
 *    zonder CSRF-bescherming.
 *  - Alle velden gingen ongefilterd in de query, inclusief `login` en `level`.
 *    Er wordt nu per veld gecontroleerd op type en bereik.
 *  - Er stond geen bovengrens op de bedragen, terwijl de kolommen die wel
 *    hebben; een te groot getal liep stil vast op de databaselaag.
 *  - Je kon je eigen rechtenniveau aanpassen en dat van een gelijke.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/beheer.php';

/** De rechtenniveaus met hun naam, van laag naar hoog. */
function rechtenniveaus(): array
{
    return [
        'Speler'    => LEVEL_SPELER,
        'Moderator' => LEVEL_MODERATOR,
        'Admin'     => LEVEL_ADMIN,
        'Eigenaar'  => LEVEL_OWNER,
    ];
}

/**
 * De velden die bewerkt mogen worden, met hun soort.
 * Alles wat hier niet in staat is niet aan te passen.
 */
function bewerkbare_velden(): array
{
    return [
        'email'     => ['label' => 'E-mailadres',     'soort' => 'email'],
        'ip'        => ['label' => 'IP-adres',        'soort' => 'tekst', 'max' => 45],
        'level'     => ['label' => 'Rechtenniveau',   'soort' => 'niveau'],
        'stad'      => ['label' => 'Stad',            'soort' => 'stad'],
        'geslacht'  => ['label' => 'Geslacht',        'soort' => 'geslacht'],
        'activated' => ['label' => 'Geactiveerd',     'soort' => 'getal', 'min' => 0, 'max' => 1],
        'status'    => ['label' => 'Status',          'soort' => 'status'],
        'gestorven' => ['label' => 'Keer omgelegd',   'soort' => 'getal', 'min' => 0, 'max' => 100000],
        'health'    => ['label' => 'Gezondheid',      'soort' => 'getal', 'min' => 0, 'max' => 100],
        'xp'        => ['label' => 'Ervaring',        'soort' => 'getal', 'min' => 0, 'max' => 100000000],
        'se'        => ['label' => 'Moordervaring',   'soort' => 'komma', 'min' => 0, 'max' => 100],
        'respect'   => ['label' => 'Eerpunten',       'soort' => 'getal', 'min' => 0, 'max' => 100000000],
        'zak'       => ['label' => 'Geld op zak',     'soort' => 'getal', 'min' => 0, 'max' => 999999999999],
        'bank'      => ['label' => 'Geld op de bank', 'soort' => 'getal', 'min' => 0, 'max' => 999999999999],
        'kogels'    => ['label' => 'Kogels',          'soort' => 'getal', 'min' => 0, 'max' => 100000000],
        'wapon'     => ['label' => 'Wapen',           'soort' => 'getal', 'min' => 0, 'max' => 20],
        'defence'   => ['label' => 'Bescherming',     'soort' => 'getal', 'min' => 0, 'max' => 20],
        'trans'     => ['label' => 'Vervoer',         'soort' => 'getal', 'min' => 0, 'max' => 20],
        'guard'     => ['label' => 'Lijfwachten',     'soort' => 'getal', 'min' => 0, 'max' => 5],
        'bo'        => ['label' => 'Uitbraken',       'soort' => 'getal', 'min' => 0, 'max' => 100000],
        'rijbewijs' => ['label' => 'Rijbewijs',       'soort' => 'getal', 'min' => 0, 'max' => 1],
        'diamanten' => ['label' => 'Diamanten',       'soort' => 'getal', 'min' => 0, 'max' => 10000000],
        'testament' => ['label' => 'Testament',       'soort' => 'tekst', 'max' => 16],
        'huwelijk'  => ['label' => 'Getrouwd met',    'soort' => 'tekst', 'max' => 16],
        'famillie'  => ['label' => 'Familie',         'soort' => 'tekst', 'max' => 20],
        'famrang'   => ['label' => 'Familierang',     'soort' => 'getal', 'min' => 0, 'max' => 5],
    ];
}

$user    = require_level(beheerpaginas()['adm-bo.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = opslaan($user, int_input('id'));
        $type    = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Beheer');
beheer_menu($user, 'adm-bo.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

$gezocht = post('naam') !== '' ? post('naam') : get('login');

panel_open('Speler opzoeken');
echo '<form method="post">' . csrf_field();
echo '<div class="veldenraster">';
echo '<label for="naam">Gebruikersnaam</label>';
echo '<input id="naam" name="naam" maxlength="16" required value="' . e($gezocht) . '">';
echo '<span></span><button type="submit">Ophalen</button>';
echo '</div></form>';
panel_close();

if ($gezocht !== '') {
    $doel = q_row('SELECT * FROM `users` WHERE `login` = ?', [$gezocht]);

    if ($doel === null) {
        panel_open('Bewerken');
        notice('Die speler bestaat niet.', 'fout');
        panel_close();
    } else {
        toon_formulier($user, $doel);
    }
}

beheer_logregels('spelerbewerking', 30);

layout_footer();

// ==========================================================================

/** @throws SpelFout */
function opslaan(array $user, int $id): string
{
    if ($id < 1) {
        return '';   // alleen opgezocht, niets opgeslagen
    }

    $doel = q_row('SELECT * FROM `users` WHERE `id` = ?', [$id]);

    if ($doel === null) {
        throw new SpelFout('Die speler bestaat niet.');
    }
    if ((int) $doel['id'] === (int) $user['id']) {
        throw new SpelFout('Je kunt je eigen account hier niet bewerken.');
    }
    if ((int) $doel['level'] >= (int) $user['level']) {
        throw new SpelFout('Je kunt geen account met gelijke of hogere rechten bewerken.');
    }

    $zetten     = [];
    $params     = [];
    $wijzigingen = [];

    foreach (bewerkbare_velden() as $veld => $def) {
        if (!array_key_exists($veld, $_POST)) {
            continue;
        }

        $nieuw = waarde_controleren($veld, $def, (string) $_POST[$veld]);
        $oud   = (string) $doel[$veld];

        if (onveranderd($def['soort'], $oud, $nieuw)) {
            continue;
        }

        // Je mag niemand een hoger rechtenniveau geven dan jezelf hebt. Gelijk mag
        // wel, zodat de eigenaar een tweede eigenaar kan aanwijzen.
        if ($veld === 'level' && (int) $nieuw > (int) $user['level']) {
            throw new SpelFout('Je kunt niemand een hoger rechtenniveau geven dan dat van jezelf.');
        }

        $zetten[]      = "`{$veld}` = ?";
        $params[]      = $nieuw;
        $wijzigingen[] = $def['label'] . ': ' . ($oud === '' ? '(leeg)' : $oud)
                       . ' naar ' . ($nieuw === '' ? '(leeg)' : $nieuw);
    }

    if ($zetten === []) {
        throw new SpelFout('Er is niets gewijzigd.');
    }

    $params[] = $id;

    q('UPDATE `users` SET ' . implode(', ', $zetten) . ' WHERE `id` = ?', $params);

    // Het spoor gaat naar het logboek in plaats van naar een privébericht aan
    // een hardcoded gebruikersnaam die misschien niet eens bestaat.
    log_action((string) $user['login'], 'spelerbewerking',
        implode('; ', $wijzigingen), 0, (string) $doel['login']);

    return count($wijzigingen) . ' ' . (count($wijzigingen) === 1 ? 'veld' : 'velden')
         . ' gewijzigd bij ' . $doel['login'] . '.';
}

/**
 * Is dit veld werkelijk gewijzigd?
 *
 * Getallen numeriek vergelijken, want de database geeft een decimal terug als
 * "0.0" terwijl het formulier "0" terugstuurt. Op tekstvergelijking zou dat
 * elke keer als wijziging in het logboek belanden.
 */
function onveranderd(string $soort, string $oud, string|int|float $nieuw): bool
{
    if ($soort === 'getal' || $soort === 'komma' || $soort === 'niveau') {
        return abs((float) $oud - (float) $nieuw) < 0.001;
    }

    return $oud === (string) $nieuw;
}

/**
 * Controleer één veld op type en bereik.
 *
 * @throws SpelFout
 */
function waarde_controleren(string $veld, array $def, string $ruw): string|int|float
{
    $ruw = trim($ruw);

    switch ($def['soort']) {
        case 'getal':
            if (!preg_match('/^-?\d{1,15}$/', $ruw)) {
                throw new SpelFout($def['label'] . ' moet een getal zijn.');
            }
            $waarde = (int) $ruw;
            if ($waarde < $def['min'] || $waarde > $def['max']) {
                throw new SpelFout($def['label'] . ' moet tussen ' . num($def['min'])
                    . ' en ' . num($def['max']) . ' liggen.');
            }
            return $waarde;

        case 'komma':
            if (!preg_match('/^\d{1,5}([.,]\d{1,2})?$/', $ruw)) {
                throw new SpelFout($def['label'] . ' moet een getal zijn.');
            }
            $waarde = (float) str_replace(',', '.', $ruw);
            if ($waarde < $def['min'] || $waarde > $def['max']) {
                throw new SpelFout($def['label'] . ' ligt buiten het toegestane bereik.');
            }
            return $waarde;

        case 'email':
            if ($ruw !== '' && filter_var($ruw, FILTER_VALIDATE_EMAIL) === false) {
                throw new SpelFout('Het e-mailadres is ongeldig.');
            }
            return $ruw;

        case 'stad':
            if ($ruw !== '' && !is_city($ruw)) {
                throw new SpelFout('Die stad bestaat niet.');
            }
            return $ruw;

        case 'geslacht':
            if (!in_array($ruw, ['Man', 'Vrouw'], true)) {
                throw new SpelFout('Geslacht moet Man of Vrouw zijn.');
            }
            return $ruw;

        case 'status':
            if (!in_array($ruw, ['levend', 'dood'], true)) {
                throw new SpelFout('Status moet levend of dood zijn.');
            }
            return $ruw;

        case 'niveau':
            if (!in_array((int) $ruw, rechtenniveaus(), true)) {
                throw new SpelFout('Kies een bestaand rechtenniveau.');
            }
            return (int) $ruw;

        default:
            return mb_substr($ruw, 0, $def['max'] ?? 255);
    }
}

// ==========================================================================

function toon_formulier(array $user, array $doel): void
{
    panel_open('Bewerken: ' . $doel['login']);

    if ((int) $doel['id'] === (int) $user['id']) {
        echo '<p>Dit is je eigen account. Bewerken kan hier niet.</p>';
        panel_close();
        return;
    }
    if ((int) $doel['level'] >= (int) $user['level']) {
        echo '<p>Dit account heeft gelijke of hogere rechten dan jij. Bewerken kan niet.</p>';
        panel_close();
        return;
    }

    echo '<form method="post">' . csrf_field();
    echo '<input type="hidden" name="id" value="' . (int) $doel['id'] . '">';
    echo '<input type="hidden" name="naam" value="' . e((string) $doel['login']) . '">';
    echo '<div class="veldenraster">';

    foreach (bewerkbare_velden() as $veld => $def) {
        $waarde = (string) ($doel[$veld] ?? '');

        echo '<label for="v_' . e($veld) . '">' . e($def['label']) . '</label>';

        if ($def['soort'] === 'geslacht' || $def['soort'] === 'status') {
            $opties = $def['soort'] === 'geslacht' ? ['Man', 'Vrouw'] : ['levend', 'dood'];
            echo '<select id="v_' . e($veld) . '" name="' . e($veld) . '">';
            foreach ($opties as $optie) {
                echo '<option value="' . e($optie) . '"' . ($optie === $waarde ? ' selected' : '')
                   . '>' . e($optie) . '</option>';
            }
            echo '</select>';
        } elseif ($def['soort'] === 'niveau') {
            echo '<select id="v_' . e($veld) . '" name="' . e($veld) . '">';
            foreach (rechtenniveaus() as $naam => $niveau) {
                echo '<option value="' . $niveau . '"' . ((string) $niveau === $waarde ? ' selected' : '')
                   . '>' . e($naam) . ' (' . $niveau . ')</option>';
            }
            echo '</select>';
        } elseif ($def['soort'] === 'stad') {
            echo '<select id="v_' . e($veld) . '" name="' . e($veld) . '">';
            foreach (cities() as $stad) {
                echo '<option value="' . e($stad) . '"' . ($stad === $waarde ? ' selected' : '')
                   . '>' . e($stad) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input id="v_' . e($veld) . '" name="' . e($veld) . '" value="' . e($waarde) . '"'
               . ($def['soort'] === 'getal' ? ' inputmode="numeric"' : '') . '>';
        }
    }

    echo '<span></span><button type="submit">Opslaan</button>';
    echo '</div></form>';

    echo '<p class="uitleg">Elke wijziging wordt met oude en nieuwe waarde vastgelegd in het '
       . 'logboek, met jouw naam erbij.</p>';

    panel_close();
}
