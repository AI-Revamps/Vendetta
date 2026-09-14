<?php
/**
 * Klikmissies: stemlinks naar externe toplijsten, met een optionele
 * automatische callback, een afkoeltijd en een beloning in zak, bank en/of
 * diamanten.
 */

declare(strict_types=1);

defined('BV_INC') || exit;

/** Alle actieve klikmissies, in de volgorde die de admin heeft ingesteld. */
function klikmissies_actief(): array
{
    return q_all('SELECT * FROM `klikmissies` WHERE `actief` = 1 ORDER BY `volgorde`, `id`');
}

/** De uitgaande URL van een missie, met de speler zijn login erin verwerkt. */
function klikmissie_url(array $missie, string $login): string
{
    return str_replace('{login}', rawurlencode($login), (string) $missie['url']);
}

/**
 * Unix-tijdstip waarop de cooldown van deze speler voor deze missie afloopt,
 * of 0 als hij nu al mag meedoen.
 *
 * Dit is de weergave-check voor de pagina's — wel of niet de knop tonen. De
 * echte beveiliging tegen dubbel belonen zit in klikmissie_belonen().
 */
function klikmissie_cooldown_tot(int $klikmissieId, int $cooldownSeconden, string $login): int
{
    $laatst = q_val(
        'SELECT UNIX_TIMESTAMP(MAX(`tijd`)) FROM `klikmissies_log`
          WHERE `klikmissie_id` = ? AND `login` = ?',
        [$klikmissieId, $login]
    );

    if ($laatst === null) {
        return 0;
    }

    $tot = (int) $laatst + $cooldownSeconden;

    return $tot > time() ? $tot : 0;
}

/**
 * Ken de beloning van een missie toe aan een speler.
 *
 * De cooldown wordt hier, ná het vergrendelen van de spelersrij, nog een keer
 * gecontroleerd. Zonder die volgorde zouden twee gelijktijdige aanroepen voor
 * dezelfde speler/missie (een callback vlak na een zelf-bevestiging, of twee
 * callbacks vlak na elkaar) de eerder uitgelezen cooldown allebei nog geldig
 * kunnen vinden en dubbel belonen: de FOR UPDATE-lock op de spelersrij dwingt
 * af dat de tweede aanroep wacht tot de eerste klaar is, en dan de bijgewerkte
 * cooldown ziet.
 *
 * @throws SpelFout Als de speler niet bestaat of de cooldown nog loopt.
 */
function klikmissie_belonen(array $missie, string $login, string $methode, string $ip): void
{
    db_transaction(function () use ($missie, $login, $methode, $ip): void {
        $speler = lock_user_by_login($login);

        if ($speler === null) {
            throw new SpelFout('Die speler bestaat niet.');
        }

        $klikmissieId = (int) $missie['id'];

        if (klikmissie_cooldown_tot($klikmissieId, (int) $missie['cooldown_seconden'], $login) > 0) {
            throw new SpelFout('Deze missie is nog in afkoeltijd.');
        }

        $userId = (int) $speler['id'];

        if ((int) $missie['beloning_zak'] > 0) {
            bijschrijven($userId, (int) $missie['beloning_zak'], 'zak');
        }
        if ((int) $missie['beloning_bank'] > 0) {
            bijschrijven($userId, (int) $missie['beloning_bank'], 'bank');
        }
        if ((int) $missie['beloning_diamanten'] > 0) {
            diamanten_bijschrijven($userId, (int) $missie['beloning_diamanten']);
        }

        q(
            'INSERT INTO `klikmissies_log` (`klikmissie_id`, `login`, `tijd`, `methode`, `ip`)
                  VALUES (?, ?, NOW(), ?, ?)',
            [$klikmissieId, $login, $methode, $ip]
        );
    });
}
