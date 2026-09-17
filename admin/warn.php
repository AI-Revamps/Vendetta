<?php
/**
 * Een speler waarschuwen.
 *
 * Wat hier gerepareerd is ten opzichte van de oude versie:
 *
 *  - De waarschuwing werd verstuurd bij het opvragen van de pagina, met de
 *    ontvanger uit de URL: adm-warn.php?x=Naam. Geen bevestiging, geen
 *    CSRF-bescherming, en er werd niet gecontroleerd of die speler bestond.
 *    Er kwam dus een bericht voor een niet-bestaande naam in de database.
 *  - De tekst stond vast; er was geen manier om een andere reden op te geven.
 *  - Het bestand controleerde eerst op niveau 200 en daarna nog eens op 255,
 *    waarbij de tweede controle pas kwam nadat de pagina al begonnen was.
 */

declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require BV_INC . '/beheer.php';

/** De standaardteksten waar een beheerder uit kan kiezen. */
function waarschuwingen(): array
{
    return [
        'multi' => 'Er is meer dan één account op jouw IP-adres gevonden. Dat is tegen de '
                 . 'regels. Stuur een bericht naar een beheerder om je tweede account toe te '
                 . 'lichten, anders wordt een van je accounts verwijderd.',
        'taal'  => 'Je taalgebruik op het forum of in privéberichten is niet in orde. '
                 . 'Matig je toon, anders volgen er maatregelen.',
        'bug'   => 'Er zijn aanwijzingen dat je een fout in het spel hebt uitgebuit. '
                 . 'Stop daarmee en meld de fout in plaats van hem te gebruiken.',
        'naam'  => 'Je gebruikersnaam of profieltekst is aanstootgevend. Pas hem aan.',
    ];
}

$user    = require_level(beheerpaginas()['warn.php'][1]);
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = waarschuwen($user, post('naam'), post('soort'), post('eigen'));
        $type    = 'ok';
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

beheer_header($user, 'warn.php');

if ($melding !== null) {
    notice(e($melding), $type);
}

panel_open('Speler waarschuwen');

echo '<form method="post">' . csrf_field();
echo '<div class="veldenraster">';
echo '<label for="naam">Speler</label>';
echo '<input id="naam" name="naam" maxlength="16" required value="' . e(get('x')) . '">';
echo '<label for="soort">Reden</label><select id="soort" name="soort">';
foreach (waarschuwingen() as $sleutel => $tekst) {
    echo '<option value="' . e($sleutel) . '">' . e(mb_substr($tekst, 0, 60)) . '…</option>';
}
echo '<option value="eigen">Eigen tekst</option>';
echo '</select>';
echo '<label for="eigen">Eigen tekst</label>';
echo '<textarea id="eigen" name="eigen" maxlength="1000"></textarea>';
echo '<span></span><small>Alleen gebruikt als je hierboven "Eigen tekst" kiest.</small>';
echo '<span></span><button type="submit">Waarschuwing sturen</button>';
echo '</div></form>';

panel_close();

beheer_logregels('waarschuwing');

beheer_footer();

// ==========================================================================

/** @throws SpelFout */
function waarschuwen(array $user, string $naam, string $soort, string $eigen): string
{
    $speler = beheer_speler($naam);

    if ((int) $speler['level'] >= (int) $user['level']) {
        throw new SpelFout('Je kunt geen speler met gelijke of hogere rechten waarschuwen.');
    }

    if ($soort === 'eigen') {
        $tekst = trim($eigen);

        if ($tekst === '') {
            throw new SpelFout('Vul je eigen tekst in, of kies een standaardreden.');
        }

        $tekst = mb_substr($tekst, 0, 1000);
    } else {
        $tekst = waarschuwingen()[$soort] ?? null;

        if ($tekst === null) {
            throw new SpelFout('Kies een geldige reden.');
        }
    }

    notify((string) $speler['login'], 'Waarschuwing',
        $tekst . "\n\nVerstuurd door " . $user['login'] . '.');

    log_action((string) $user['login'], 'waarschuwing',
        'Waarschuwing verstuurd (' . $soort . ')', 0, (string) $speler['login']);

    return 'De waarschuwing is naar ' . $speler['login'] . ' gestuurd.';
}
