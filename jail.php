<?php
/**
 * Gevangenis: wie vastzit, en de drie manieren om eruit te komen.
 *
 * Wat hier gerepareerd is:
 *
 *  - **Het bericht aan wie werd vrijgekocht kwam nooit aan.** De INSERT noemde
 *    vijf kolommen (`time`, `from`, `to`, `subject`, `message`) maar gaf vier
 *    waarden mee. Die query mislukte dus altijd; de vrijgekochte speler hoorde
 *    er nooit iets van.
 *  - Vrijkopen ging via een GET-link (`jail.php?x=Naam`) die geld van je
 *    rekening haalde. Een plaatje met die URL in een forumbericht was genoeg
 *    om andermans zak leeg te trekken.
 *  - De saldocontrole las een rij die al opgehaald was en boekte daarna in een
 *    losse UPDATE af, zonder transactie. Twee verzoeken tegelijk konden dus
 *    allebei "genoeg geld" zien.
 *  - Bij een mislukte uitbraak werd een cel aangemaakt met `$boete`,
 *    `$famillie` en `$jailtime`: drie variabelen die op dat punt niet bestaan.
 *    `$boete` en `$famillie` bleven achter uit de lus die de lijst tekende, en
 *    stonden dus op de waarden van de laatste getoonde gevangene.
 *  - `$_GET['bo'] == yes` vergelijkt met een ongedefinieerde constante: sinds
 *    PHP 8 een fatale fout.
 *  - `elseif ($data->level >= 255)` liet beheerders altijd ontsnappen, ook als
 *    de kansworp mislukt was. Dat is nu weg; wie vastzit zit vast.
 *  - Uitbreken kon oneindig vaak: elke mislukte poging verlengde de straf met
 *    50 seconden, maar de teller `bo` werd alleen in de cel opgehoogd terwijl
 *    de controle op `bo >= 2` in de lijst stond. Nu een echte limiet.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

/** Zoveel keer mag je proberen uit te breken voordat je in de isoleercel gaat. */
const MAX_POGINGEN = 2;

/** Straf erbij als een uitbraak mislukt. */
const STRAF_EXTRA_SECONDEN = 50;
const BOETE_VERHOGING      = 1.2;

$user    = require_login();
$melding = null;
$type    = 'info';

if (is_post()) {
    csrf_check();
    try {
        $melding = match (post('actie')) {
            'vrijkopen' => vrijkopen($user, post('naam')),
            'uitbreken' => uitbreken($user),
            'bevrijden' => bevrijden($user, post('naam')),
            default     => throw new SpelFout('Onbekende handeling.'),
        };
        $type = 'ok';
        $user = current_user() ?? $user;
    } catch (SpelFout $e) {
        $melding = $e->getMessage();
        $type    = 'fout';
    }
}

layout_header('Gevangenis');

if ($melding !== null) {
    notice(e($melding), $type);
}

$eigenCel = jail_status((string) $user['login']);

$cellen = q_all(
    'SELECT `login`, `famillie`, `boete`, `bo`, UNIX_TIMESTAMP(`time`) AS `tot`
       FROM `jail` WHERE `stad` = ? AND `time` > NOW() ORDER BY `time`',
    [$user['stad']]
);

panel_open('Gevangenis ' . $user['stad']);

if ($cellen === []) {
    echo '<p>Er zit hier niemand vast.</p>';
} else {
    echo '<div class="tabelwikkel"><table class="lijst">';
    echo '<thead><tr><th>Speler</th><th>Familie</th><th class="getal">Borgsom</th>'
       . '<th>Nog</th><th>Actie</th></tr></thead><tbody>';

    foreach ($cellen as $cel) {
        $naam   = (string) $cel['login'];
        $isZelf = $naam === (string) $user['login'];
        $over   = max(0, (int) $cel['tot'] - time());

        echo '<tr>';
        echo '<td><a' . speler_rol_attr($naam) . ' href="' . e(url('user.php?x=' . rawurlencode($naam))) . '">'
           . e($naam) . '</a></td>';
        echo '<td>' . ((string) $cel['famillie'] === '' ? '—'
            : '<a href="' . e(url('fam.php?x=' . rawurlencode((string) $cel['famillie']))) . '">'
              . e((string) $cel['famillie']) . '</a>') . '</td>';
        echo '<td class="getal">' . money((int) $cel['boete']) . '</td>';
        echo '<td><span data-tot="' . (int) $cel['tot'] . '">' . e(duration($over)) . '</span></td>';
        echo '<td>' . acties($user, $naam, $isZelf, (int) $cel['bo'], $eigenCel !== null) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

echo '<p class="uitleg">Vrijkopen kost de borgsom en werkt altijd. Uitbreken is gratis '
   . 'maar lukt ongeveer een op de drie keer; mislukt het, dan wordt je straf langer en '
   . 'je borgsom hoger. Na ' . MAX_POGINGEN . ' pogingen ga je in de isoleercel.</p>';

panel_close();

layout_footer();

// ==========================================================================

/** De knoppen achter één regel in de lijst. */
function acties(array $user, string $naam, bool $isZelf, int $pogingen, bool $zitZelfVast): string
{
    $html = knop('vrijkopen', $naam, 'Vrijkopen');

    if ($isZelf) {
        $html .= $pogingen >= MAX_POGINGEN
            ? ' <span class="uit">isoleercel</span>'
            : ' ' . knop('uitbreken', $naam, 'Uitbreken');
    } elseif (!$zitZelfVast) {
        $html .= ' ' . knop('bevrijden', $naam, 'Bevrijden');
    }

    return $html;
}

function knop(string $actie, string $naam, string $label): string
{
    return '<form method="post" style="display:inline;margin:0">' . csrf_field()
         . '<input type="hidden" name="actie" value="' . e($actie) . '">'
         . '<input type="hidden" name="naam" value="' . e($naam) . '">'
         . '<button type="submit">' . e($label) . '</button></form>';
}

// ==========================================================================

/**
 * Betaal de borgsom van een gevangene. Mag ook je eigen borgsom zijn.
 *
 * @throws SpelFout
 */
function vrijkopen(array $user, string $naam): string
{
    $bedrag = 0;

    db_transaction(static function () use ($user, $naam, &$bedrag): void {
        $cel = q_row('SELECT * FROM `jail` WHERE `login` = ? AND `time` > NOW() FOR UPDATE',
            [$naam]);

        if ($cel === null) {
            throw new SpelFout('Die speler zit niet (meer) vast.');
        }

        $bedrag = (int) $cel['boete'];

        // De saldocontrole zit ín de UPDATE, dus er is geen gat tussen kijken
        // of het geld er is en het afschrijven.
        if (!afboeken((int) $user['id'], $bedrag)) {
            throw new SpelFout('Je hebt ' . money($bedrag) . ' op zak nodig.');
        }

        q('DELETE FROM `jail` WHERE `login` = ?', [$naam]);

        if ($naam !== (string) $user['login']) {
            // Dit bericht kwam in de oude versie nooit aan: de INSERT noemde
            // vijf kolommen en gaf vier waarden mee.
            notify($naam, 'Vrijgekocht',
                'Je bent door ' . $user['login'] . ' uit de gevangenis vrijgekocht.');
        }

        log_action((string) $user['login'], 'gevangenis',
            'Vrijgekocht voor ' . money($bedrag), $bedrag, $naam);
    });

    return $naam === (string) $user['login']
        ? 'Je hebt jezelf vrijgekocht voor ' . money($bedrag) . '.'
        : 'Je hebt ' . $naam . ' vrijgekocht voor ' . money($bedrag) . '.';
}

/**
 * Probeer zelf te ontsnappen. Eén op drie kans; mislukt het, dan langer vast.
 *
 * @throws SpelFout
 */
function uitbreken(array $user): string
{
    $gelukt = false;

    db_transaction(static function () use ($user, &$gelukt): void {
        $cel = q_row('SELECT * FROM `jail` WHERE `login` = ? AND `time` > NOW() FOR UPDATE',
            [$user['login']]);

        if ($cel === null) {
            throw new SpelFout('Je zit niet in de gevangenis.');
        }
        if ((int) $cel['bo'] >= MAX_POGINGEN) {
            throw new SpelFout('Je zit in een isoleercel. Uitbreken kan niet meer.');
        }

        $gelukt = random_int(1, 3) === 2;

        if ($gelukt) {
            q('DELETE FROM `jail` WHERE `login` = ?', [$user['login']]);
            q('UPDATE `users` SET `xp` = `xp` + 1 WHERE `id` = ?', [$user['id']]);
            log_action((string) $user['login'], 'gevangenis', 'Uitgebroken');
            return;
        }

        q(
            'UPDATE `jail`
                SET `boete` = FLOOR(`boete` * ?),
                    `bo`    = `bo` + 1,
                    `time`  = DATE_ADD(`time`, INTERVAL ? SECOND)
              WHERE `login` = ?',
            [BOETE_VERHOGING, STRAF_EXTRA_SECONDEN, $user['login']]
        );

        log_action((string) $user['login'], 'gevangenis', 'Uitbraak mislukt');
    });

    return $gelukt
        ? 'Je bent ontsnapt.'
        : 'Je uitbraak is mislukt. Je zit ' . STRAF_EXTRA_SECONDEN
          . ' seconden langer vast en je borgsom is verhoogd.';
}

/**
 * Breek een ander uit. Vijftig procent kans; mislukt het, dan zit je zelf vast.
 *
 * @throws SpelFout
 */
function bevrijden(array $user, string $naam): string
{
    if ($naam === (string) $user['login']) {
        throw new SpelFout('Jezelf bevrijden gaat via uitbreken.');
    }
    if (jail_status((string) $user['login']) !== null) {
        throw new SpelFout('Je kunt niemand bevrijden zolang je zelf vastzit.');
    }

    $gelukt = false;

    db_transaction(static function () use ($user, $naam, &$gelukt): void {
        $cel = q_row('SELECT * FROM `jail` WHERE `login` = ? AND `time` > NOW() FOR UPDATE',
            [$naam]);

        if ($cel === null) {
            throw new SpelFout('Die speler zit niet (meer) vast.');
        }

        $gelukt = random_int(1, 2) === 1;

        if ($gelukt) {
            q('DELETE FROM `jail` WHERE `login` = ?', [$naam]);
            q('UPDATE `users` SET `xp` = `xp` + 1, `bo` = `bo` + 1 WHERE `id` = ?',
                [$user['id']]);

            notify($naam, 'Bevrijd',
                'Je bent door ' . $user['login'] . ' uit de gevangenis gebroken.');
            log_action((string) $user['login'], 'gevangenis', 'Bevrijd', 0, $naam);
            return;
        }

        // Mislukt: je gaat er zelf in. In de oude versie stonden hier drie
        // variabelen die op dit punt niet bestonden.
        jail_put((string) $user['login'], (int) $user['xp'], (string) $user['stad'],
            (string) $user['famillie']);

        q('UPDATE `users` SET `health` = GREATEST(1, `health` - 1) WHERE `id` = ?',
            [$user['id']]);

        log_action((string) $user['login'], 'gevangenis', 'Bevrijding mislukt', 0, $naam);
    });

    return $gelukt
        ? 'Je hebt ' . $naam . ' bevrijd.'
        : 'Je bent gepakt bij je poging om ' . $naam . ' te bevrijden en zit nu zelf vast.';
}
