<?php
/**
 * Voorpagina.
 *
 * Vervangt de oude frameset. Ingelogde spelers gaan meteen door naar hun status.
 *
 * Kan dezelfde advertentiecode tonen als advertentie.php (aan/uit op
 * admin/premium.php), voor bezoekers die nog niet ingelogd zijn. Daarom krijgt
 * ook deze pagina het ruimere beleid dat advertentienetwerken nodig hebben,
 * ongeacht of de advertentie op dit moment aanstaat — dat weet je pas na het
 * laden van inc/premium.php, en de header moet eerder verstuurd worden.
 */

declare(strict_types=1);

define('BV_ADVERTENTIEPAGINA', true);

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Content-Type: text/html; charset=utf-8');

    header(
        "Content-Security-Policy: "
        . "default-src 'self' https:; "
        . "script-src 'self' 'unsafe-inline' https:; "
        . "style-src 'self' 'unsafe-inline' https:; "
        . "img-src 'self' data: https: http:; "
        . "frame-src https:; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'"
    );
}

require __DIR__ . '/inc/bootstrap.php';

if (is_logged_in()) {
    redirect('home.php');
}

$online = (int) q_val(
    "SELECT COUNT(*) FROM `users`
      WHERE `status` = 'levend' AND `online` > DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
    [],
    0
);

$spelers = (int) q_val(
    "SELECT COUNT(*) FROM `users` WHERE `status` = 'levend' AND `activated` = 1", [], 0
);

$naam = (string) config('site.name');

layout_header('Welkom');
panel_open('Welkom bij ' . $naam);

// Het volledige logo, als het geplaatst is. Zo niet, dan begint de pagina
// gewoon met de tekst.
$logo = logo_url('logo.png');
if ($logo !== null) {
    echo '<img class="voorpagina-logo" src="' . e($logo) . '" alt="' . e($naam) . '">';
}
?>
<p><strong><?= e($naam) ?> is een online multiplayer rollenspel in tekstvorm.</strong></p>

<p>Het spel speelt zich af in de onderwereld, tussen gangsters en wiseguys, waar
misdaad tot de orde van de dag hoort. Door misdaden te plegen, auto's te stelen
en banken te beroven word je een machtige crimineel. Obstakels op je pad ruim je
uit de weg met wapens en kogels.</p>

<p>Je kunt jezelf ook beschermen: verwerf bondgenoten, richt een familie op,
koop bodyguards en kogelvrije vesten. Want wie omhoog klimt, wordt gezien.</p>

<p>Denk je klaar te zijn voor deze uitdaging? Meld je aan en stap de onderwereld
binnen.</p>

<p>
  <a class="knop knop-nadruk" style="display:inline-block" href="<?= e(url('register.php')) ?>">Registreren</a>
  <a class="knop" href="<?= e(url('login.php')) ?>">Inloggen</a>
</p>

<p class="uitleg">
  Er <?= $online === 1 ? 'is' : 'zijn' ?> momenteel <strong><?= num($online) ?></strong>
  <?= $online === 1 ? 'speler' : 'spelers' ?> aan het spelen,
  van in totaal <?= num($spelers) ?> geregistreerde <?= $spelers === 1 ? 'gangster' : 'gangsters' ?>.
</p>
<?php
panel_close();

if (ads_outgame() && ads_html() !== '') {
    panel_open('Advertentie');
    // Bewust niet ge-escaped, net als op advertentie.php: dit is HTML die de
    // beheerder zelf heeft ingeplakt om een advertentienetwerk te laten werken.
    echo '<div class="advertentie">' . ads_html() . '</div>';
    panel_close();
}

layout_footer();
