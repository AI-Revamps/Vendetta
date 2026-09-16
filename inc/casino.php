<?php
/**
 * Gedeelde onderdelen van de casinospellen.
 *
 * Elk spel staat per stad in de tabel `casino` en heeft een speler als
 * eigenaar. Inzetten worden verrekend met de bankrekening van die eigenaar:
 * wint de speler, dan betaalt de eigenaar; verliest hij, dan int de eigenaar.
 * Kan de eigenaar niet uitbetalen, dan is hij failliet en wisselt het casino
 * van eigenaar.
 *
 * In de oude versie stond deze logica vier keer bijna identiek uitgeschreven,
 * in slots.php, guess.php, roulette.php en blackjack.php. Wat daar misging:
 *
 *  - Geen transactie en geen vergrendeling. Twee gelijktijdige winnende
 *    inzetten lazen allebei hetzelfde banksaldo van de eigenaar en werden
 *    allebei volledig uitbetaald, waardoor er geld bij kwam.
 *  - In roulette.php werd bij een faillissement `$winst = $eigenaar->bank`
 *    berekend, maar vervolgens `$prijs1` uitbetaald: het volle bedrag terwijl
 *    de eigenaar minder had. Het verschil kwam uit het niets. In slots.php en
 *    guess.php ging dat wel goed, wat laat zien dat het een vergissing was.
 *  - Een casino kopen ging in losse queries zonder controle achteraf, dus twee
 *    kopers konden hetzelfde casino kopen.
 *  - Bij het kopen van een casino zonder eigenaar ging het aankoopbedrag naar
 *    een lege gebruikersnaam en verdween het.
 */

declare(strict_types=1);

defined('BV_INC') || exit;

const CASINO_PRIJS      = 500_000;
const CASINO_MIN_KAS    = 50_000;    // minder in de bank en de eigenaar gaat failliet
const CASINO_MAX_INZET  = 10_000_000;

/** De spellen en hun weergavenaam. */
function casino_spellen(): array
{
    return [
        'fruitmachine' => 'Fruitmachine',
        'nummerraden'  => 'Nummerraden',
        'roulette'     => 'Roulette',
        'blackjack'    => 'Blackjack',
        'kogelfabriek' => 'Kogelfabriek',
    ];
}

/**
 * Haal het casino van dit spel in deze stad op. Bestaat het nog niet, dan
 * wordt het aangemaakt zonder eigenaar.
 */
function casino_spel(string $spel, string $stad, bool $vergrendel = false): array
{
    $casino = q_row(
        'SELECT * FROM `casino` WHERE `spel` = ? AND `stad` = ?' . ($vergrendel ? ' FOR UPDATE' : ''),
        [$spel, $stad]
    );

    if ($casino !== null) {
        return $casino;
    }

    q("INSERT INTO `casino` (`spel`, `owner`, `stad`, `winst`, `inzet`, `status`)
            VALUES (?, '', ?, 0, 1000, 1)", [$spel, $stad]);

    return q_row('SELECT * FROM `casino` WHERE `id` = ?', [db_last_id()]) ?? [];
}

/** De eigenaar van dit casino, of null als er geen is. */
function casino_eigenaar(array $casino): ?array
{
    if (($casino['owner'] ?? '') === '') {
        return null;
    }
    return q_row('SELECT * FROM `users` WHERE `login` = ?', [$casino['owner']]);
}

/**
 * Koop een casino dat geen eigenaar heeft.
 *
 * @throws SpelFout
 */
function casino_kopen(array $user, string $spel, string $stad): string
{
    return db_transaction(static function () use ($user, $spel, $stad): string {
        $casino = casino_spel($spel, $stad, true);

        if (($casino['owner'] ?? '') !== '') {
            throw new SpelFout('Dit casino heeft al een eigenaar.');
        }

        $eigenBezit = (int) q_val('SELECT COUNT(*) FROM `casino` WHERE `owner` = ?', [$user['login']], 0);

        if ($eigenBezit > 0) {
            throw new SpelFout('Je bezit al een casino. Meer dan een mag niet.');
        }

        lock_user((int) $user['id']);

        if (!afboeken((int) $user['id'], CASINO_PRIJS, 'zak')) {
            throw new SpelFout('Een casino kost ' . money(CASINO_PRIJS) . '.');
        }

        q("UPDATE `casino` SET `owner` = ?, `winst` = 0 WHERE `id` = ?",
            [$user['login'], $casino['id']]);

        log_action((string) $user['login'], 'casino',
            'Casino gekocht: ' . $spel . ' in ' . $stad, CASINO_PRIJS);

        return 'Je hebt ' . (casino_spellen()[$spel] ?? $spel) . ' in ' . $stad . ' gekocht.';
    });
}

/**
 * De eigenaar stelt de maximale inzet in.
 *
 * @throws SpelFout
 */
function casino_inzet_zetten(array $user, array $casino, int $max): string
{
    if ($casino['owner'] !== $user['login']) {
        throw new SpelFout('Dit casino is niet van jou.');
    }
    if ($max < 1 || $max > CASINO_MAX_INZET) {
        throw new SpelFout('Kies een maximum tussen 1 en ' . money(CASINO_MAX_INZET) . '.');
    }

    q('UPDATE `casino` SET `inzet` = ? WHERE `id` = ?', [$max, $casino['id']]);

    return 'De maximale inzet staat nu op ' . money($max) . '.';
}

/**
 * Reken een ronde af.
 *
 * Alles gebeurt in één transactie met beide partijen vergrendeld, zodat twee
 * gelijktijdige rondes elkaar niet in de weg zitten.
 *
 * @param int $inzet       Wat de speler inzette.
 * @param int $uitbetaling Wat hij wint. Nul betekent verloren.
 *
 * @return array{tekst:string, type:string, uitbetaald:int, failliet:bool}
 * @throws SpelFout
 */
function casino_afrekenen(array $user, string $spel, string $stad, int $inzet, int $uitbetaling): array
{
    return db_transaction(static function () use ($user, $spel, $stad, $inzet, $uitbetaling): array {
        $casino = casino_spel($spel, $stad, true);

        if (($casino['owner'] ?? '') === '') {
            throw new SpelFout('Dit casino heeft geen eigenaar.');
        }

        $speler   = lock_user((int) $user['id']);
        $eigenaar = lock_user_by_login((string) $casino['owner']);

        if ($eigenaar === null) {
            throw new SpelFout('De eigenaar van dit casino bestaat niet meer.');
        }

        // --- Speler verliest ---
        if ($uitbetaling < 1) {
            if (!afboeken((int) $speler['id'], $inzet, 'zak')) {
                throw new SpelFout('Je hebt niet genoeg geld om te spelen.');
            }

            bijschrijven((int) $eigenaar['id'], $inzet, 'bank');
            q('UPDATE `casino` SET `winst` = `winst` + ? WHERE `id` = ?', [$inzet, $casino['id']]);

            return [
                'tekst'      => 'Je hebt ' . money($inzet) . ' verloren.',
                'type'       => 'fout',
                'uitbetaald' => 0,
                'failliet'   => false,
            ];
        }

        // --- Speler wint ---
        $kas = (int) $eigenaar['bank'];

        // Kan de eigenaar het niet betalen, dan krijgt de speler wat er is.
        // In roulette.php werd hier het volle bedrag uitgekeerd terwijl de kas
        // leeg was, waardoor er geld bijkwam.
        $werkelijk = min($uitbetaling, max(0, $kas));
        $failliet  = $werkelijk < $uitbetaling || $kas - $werkelijk < CASINO_MIN_KAS;

        if ($werkelijk > 0) {
            q('UPDATE `users` SET `bank` = `bank` - ? WHERE `id` = ?', [$werkelijk, $eigenaar['id']]);
            bijschrijven((int) $speler['id'], $werkelijk, 'zak');
        }

        q('UPDATE `casino` SET `winst` = `winst` - ? WHERE `id` = ?', [$werkelijk, $casino['id']]);

        $tekst = 'Je hebt ' . money($werkelijk) . ' gewonnen.';

        if ($werkelijk < $uitbetaling) {
            $tekst .= ' Dat is minder dan de ' . money($uitbetaling) . ' die je had moeten krijgen: '
                    . $eigenaar['login'] . ' kon niet volledig uitbetalen.';
        }

        if ($failliet) {
            $tekst .= ' ' . casino_faillissement($casino, $eigenaar, $speler);
        }

        return [
            'tekst'      => $tekst,
            'type'       => 'ok',
            'uitbetaald' => $werkelijk,
            'failliet'   => $failliet,
        ];
    });
}

/**
 * De eigenaar is failliet. Het casino gaat naar de winnaar, tenzij die er al
 * een heeft; dan komt het weer vrij.
 */
function casino_faillissement(array $casino, array $eigenaar, array $winnaar): string
{
    $heeftAl = (int) q_val('SELECT COUNT(*) FROM `casino` WHERE `owner` = ?', [$winnaar['login']], 0);

    $nieuweEigenaar = $heeftAl > 0 ? '' : (string) $winnaar['login'];

    q("UPDATE `casino` SET `owner` = ?, `winst` = 0 WHERE `id` = ?",
        [$nieuweEigenaar, $casino['id']]);

    notify((string) $eigenaar['login'], 'Casino',
        'Je ' . (casino_spellen()[$casino['spel']] ?? $casino['spel']) . ' in ' . $casino['stad']
        . ' is failliet gespeeld door ' . $winnaar['login'] . '.');

    log_action((string) $winnaar['login'], 'casino',
        'Casino failliet gespeeld: ' . $casino['spel'] . ' in ' . $casino['stad'],
        0, (string) $eigenaar['login']);

    return $nieuweEigenaar === ''
        ? $eigenaar['login'] . ' is failliet. Het casino komt weer vrij, want je bezit er al een.'
        : $eigenaar['login'] . ' is failliet. Het casino is nu van jou.';
}

/**
 * Standaardkop van een casinopagina: eigenaar, kas, maximale inzet, en de
 * knoppen om te kopen of de inzet te wijzigen.
 *
 * @return bool True als er gespeeld kan worden.
 */
function casino_kop(array $user, array $casino): bool
{
    $naam     = casino_spellen()[$casino['spel']] ?? $casino['spel'];
    $eigenaar = casino_eigenaar($casino);

    panel_open($naam . ' in ' . $casino['stad']);

    if ($eigenaar === null) {
        echo '<p>Dit casino heeft geen eigenaar. Je kunt het kopen voor '
           . money(CASINO_PRIJS) . '.</p>';
        echo '<form method="post">' . csrf_field()
           . '<input type="hidden" name="actie" value="koop">'
           . '<button type="submit">Koop dit casino</button></form>';
        panel_close();
        return false;
    }

    echo '<p>Eigenaar: <strong>' . speler_naam((string) $eigenaar['login']) . '</strong>. '
       . 'Maximale inzet: <strong>' . money((int) $casino['inzet']) . '</strong>.</p>';

    if ($eigenaar['login'] === $user['login']) {
        echo '<p>Dit casino is van jou. In kas: ' . money((int) $eigenaar['bank'])
           . '. Opbrengst tot nu toe: ' . money((int) $casino['winst']) . '.</p>';
        echo '<form method="post">' . csrf_field();
        echo '<input type="hidden" name="actie" value="inzet">';
        echo '<div class="veldenraster">';
        echo '<label for="maxinzet">Maximale inzet</label>';
        echo '<input id="maxinzet" name="maxinzet" type="number" min="1" max="' . CASINO_MAX_INZET
           . '" step="1" value="' . (int) $casino['inzet'] . '" required>';
        echo '<span></span><button type="submit">Aanpassen</button>';
        echo '</div></form>';
        echo '<p class="uitleg">Je kunt niet in je eigen casino spelen.</p>';
        panel_close();
        return false;
    }

    if ((int) $eigenaar['bank'] < CASINO_MIN_KAS) {
        echo '<p>De kas van ' . speler_naam((string) $eigenaar['login']) . ' is te laag. '
           . 'Er kan nu niet gespeeld worden.</p>';
        panel_close();
        return false;
    }

    panel_close();
    return true;
}
