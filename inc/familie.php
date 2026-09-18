<?php
/**
 * Gedeelde onderdelen voor families: rangen, lidmaatschap en de familiebank.
 */

declare(strict_types=1);

defined('BV_INC') || exit;

// Rangen binnen een familie.
const FAM_LID         = 1;
const FAM_CAPO        = 2;
const FAM_CONSIGLIERI = 3;
const FAM_HALFDON     = 4;
const FAM_DON         = 5;

const FAM_OPRICHTKOSTEN = 25_000_000;
const FAM_OPRICHT_XP    = 8000;        // minimaal Local Chief
const FAM_GROND_PRIJS   = 25_000;

/** Naam van elke familierang. */
function fam_rangen(): array
{
    return [
        FAM_LID         => 'Lid',
        FAM_CAPO        => 'Capo',
        FAM_CONSIGLIERI => 'Consiglieri',
        FAM_HALFDON     => 'Onderbaas',
        FAM_DON         => 'Don',
    ];
}

function fam_rangnaam(int $rang): string
{
    return fam_rangen()[$rang] ?? 'Geen';
}

/** De familie van deze speler, of null. */
function fam_van(array $user, bool $vergrendel = false): ?array
{
    if (($user['famillie'] ?? '') === '') {
        return null;
    }

    $sql = 'SELECT * FROM `famillie` WHERE `name` = ?' . ($vergrendel ? ' FOR UPDATE' : '');
    return q_row($sql, [$user['famillie']]);
}

/**
 * Eis een minimale familierang.
 *
 * @throws SpelFout
 */
function fam_eis_rang(array $user, int $minimum): array
{
    $familie = fam_van($user);

    if ($familie === null) {
        throw new SpelFout('Je zit niet in een familie.');
    }
    if ((int) $user['famrang'] < $minimum) {
        throw new SpelFout('Je hebt hiervoor minstens de rang ' . fam_rangnaam($minimum) . ' nodig.');
    }

    return $familie;
}

/** Aantal levende leden. */
function fam_aantal_leden(string $naam): int
{
    return (int) q_val(
        "SELECT COUNT(*) FROM `users` WHERE `famillie` = ? AND `status` = 'levend'", [$naam], 0
    );
}

/** Leden, gesorteerd op rang en ervaring. */
function fam_leden(string $naam): array
{
    return q_all(
        "SELECT `id`, `login`, `famrang`, `xp`, `geslacht`, `stad`, `status`, `online`
           FROM `users` WHERE `famillie` = ?
       ORDER BY `famrang` DESC, `xp` DESC",
        [$naam]
    );
}

/**
 * Boek geld af van de familiebank, maar alleen als het er is.
 * De voorwaarde zit in de query, dus er zit geen gat tussen controle en boeking.
 */
function fam_afboeken(string $naam, int $bedrag): bool
{
    if ($bedrag < 1) {
        return false;
    }
    return q_count(
        'UPDATE `famillie` SET `bank` = `bank` - ? WHERE `name` = ? AND `bank` >= ?',
        [$bedrag, $naam, $bedrag]
    ) === 1;
}

function fam_bijschrijven(string $naam, int $bedrag): void
{
    if ($bedrag > 0) {
        q('UPDATE `famillie` SET `bank` = `bank` + ? WHERE `name` = ?', [$bedrag, $naam]);
    }
}

/**
 * Keer het promotiegeld uit als een lid een nieuwe rang heeft bereikt.
 *
 * De familie stelt per spelersrang een bedrag in via fampromotie.php. In de
 * oude versie werden die bedragen wel opgeslagen maar nergens uitgelezen: er
 * bestond geen enkele uitbetalingscode, dus het instelscherm deed niets.
 *
 * Wordt bij elk verzoek aangeroepen vanuit bootstrap.php, maar doet alleen
 * werk wanneer de rang daadwerkelijk gestegen is.
 */
function fam_promotie_uitbetalen(array $user): void
{
    $rang    = rank_index((int) $user['xp']);
    $betaald = (int) $user['laatste_rang'];

    if ($rang <= $betaald) {
        return;
    }

    $nieuweRang = rank_name((int) $user['xp'], (string) ($user['geslacht'] ?? 'Man'));

    // Zonder familie alleen de teller bijwerken en een kaal promotiebericht
    // sturen — promotiegeld komt uit de familiekas, maar een rangstijging
    // verdient sowieso een melding.
    if (($user['famillie'] ?? '') === '') {
        q('UPDATE `users` SET `laatste_rang` = ? WHERE `id` = ?', [$rang, $user['id']]);
        notify((string) $user['login'], 'Promotie',
            'Gefeliciteerd, je bent gepromoveerd naar ' . $nieuweRang . '.');
        return;
    }

    db_transaction(static function () use ($user, $rang, $betaald, $nieuweRang): void {
        $familie = fam_van($user, true);

        if ($familie === null) {
            q('UPDATE `users` SET `laatste_rang` = ? WHERE `id` = ?', [$rang, $user['id']]);
            notify((string) $user['login'], 'Promotie',
                'Gefeliciteerd, je bent gepromoveerd naar ' . $nieuweRang . '.');
            return;
        }

        // Alle rangen optellen die sinds de vorige uitbetaling gehaald zijn.
        $bedrag = 0;
        for ($n = $betaald + 1; $n <= $rang; $n++) {
            if ($n >= 2 && $n <= 14) {
                $bedrag += (int) ($familie['rang' . $n] ?? 0);
            }
        }

        q('UPDATE `users` SET `laatste_rang` = ? WHERE `id` = ?', [$rang, $user['id']]);

        if ($bedrag < 1) {
            notify((string) $user['login'], 'Promotie',
                'Gefeliciteerd, je bent gepromoveerd naar ' . $nieuweRang . '.');
            return;
        }

        // Alleen uitbetalen als de kas het toelaat.
        if (!fam_afboeken((string) $familie['name'], $bedrag)) {
            notify((string) $user['login'], 'Promotie',
                'Je bent gepromoveerd naar ' . $nieuweRang . ', maar de kas van ' . $familie['name']
                . ' had niet genoeg om je promotiegeld uit te keren.');
            return;
        }

        bijschrijven((int) $user['id'], $bedrag, 'zak');

        fam_log((string) $familie['name'], (string) $user['login'], -$bedrag,
            'Promotiegeld tot rang ' . $rang);

        notify((string) $user['login'], 'Promotie',
            'Gefeliciteerd met je nieuwe rang, ' . $nieuweRang . '. Je familie keert je '
            . money($bedrag) . ' promotiegeld uit.');
    });
}

/** Leg een geldbeweging van de familie vast. */
function fam_log(string $familie, string $wie, int $bedrag, string $omschrijving): void
{
    q(
        'INSERT INTO `logs` (`time`, `login`, `person`, `code`, `area`, `com`)
              VALUES (NOW(), ?, ?, ?, ?, ?)',
        [$familie, $wie, $bedrag, 'famibank', mb_substr($omschrijving, 0, 255)]
    );
}
