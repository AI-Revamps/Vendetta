<?php
/**
 * Veiligheid: opmaak die geen code mag worden, rechten die niet te omzeilen
 * zijn, en formulieren die zonder token niets doen.
 *
 *     php tests/veiligheid.php
 *
 * Het eerste deel draait zonder server: het roept bericht_html() rechtstreeks
 * aan en parseert de uitvoer met DOMDocument. Dat is met opzet geen zoeken op
 * tekst — geëscapete tekst als &lt;svg onload=x&gt; ís veilig, en een test die
 * op "onload" zoekt zou daar ten onrechte over vallen.
 */

declare(strict_types=1);

require __DIR__ . '/_start.php';

// --- Deel 1: opmaak --------------------------------------------------------

kop('BBCode: alles wat geen opmaak is, blijft tekst');

// De bestanden in /inc weigeren rechtstreeks te draaien; dit vlaggetje zegt dat
// ze via een bootstrap geladen worden. Verder heeft bericht_html() alleen e()
// en url() nodig, dus die zetten we hier na.
defined('BV_INC') || define('BV_INC', BV_WORTEL . '/inc');

if (!function_exists('e')) {
    function e(?string $t): string
    {
        return htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('url')) {
    function url(string $p = ''): string { return 'https://spel.test/' . ltrim($p, '/'); }
}

require BV_WORTEL . '/inc/opmaak.php';

/** Elementen die de opmaak bewust mag opleveren. */
const TOEGESTAAN = ['strong', 'em', 'u', 's', 'small', 'span', 'ul', 'li',
                    'blockquote', 'a', 'img', 'br', 'body', 'html', 'p', 'div'];

/**
 * Ontleed de uitvoer en geef terug wat er werkelijk mis is.
 *
 * @return list<string>
 */
function keur(string $html): array
{
    $problemen = [];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>',
        LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    foreach ($doc->getElementsByTagName('*') as $el) {
        $tag = strtolower($el->nodeName);

        if (!in_array($tag, TOEGESTAAN, true)) {
            $problemen[] = "element <{$tag}>";
        }

        foreach ($el->attributes ?? [] as $attr) {
            $naam   = strtolower($attr->nodeName);
            $waarde = $attr->nodeValue ?? '';

            if (str_starts_with($naam, 'on')) {
                $problemen[] = "attribuut {$naam} op <{$tag}>";
            }

            if (in_array($naam, ['href', 'src'], true)) {
                $schema = strtolower((string) parse_url($waarde, PHP_URL_SCHEME));
                if ($schema !== '' && !in_array($schema, ['http', 'https'], true)) {
                    $problemen[] = "{$naam} met schema '{$schema}' op <{$tag}>";
                }
            }

            if ($naam === 'style' && preg_match('/javascript|expression|url\s*\(/i', $waarde)) {
                $problemen[] = "verdachte style op <{$tag}>";
            }
        }
    }

    return $problemen;
}

$payloads = [
    'kale script-tag'          => '<script>alert(1)</script>',
    'img met onerror'          => '<img src=x onerror=alert(1)>',
    'bbcode img attribuut'     => '[img]x" onerror="alert(1)[/img]',
    'bbcode img javascript'    => '[img]javascript:alert(1)[/img]',
    'bbcode img data-uri'      => '[img]data:text/html,<script>alert(1)</script>[/img]',
    'url met javascript'       => '[url]javascript:alert(1)[/url]',
    'url=javascript'           => '[url=javascript:alert(1)]klik[/url]',
    'url attribuut ontsnappen' => '[url=http://x"onmouseover="alert(1)]klik[/url]',
    'iframe via [inc]'         => '[inc]http://boef.example/nep-login[/inc]',
    'kale iframe'              => '<iframe src="http://boef.example"></iframe>',
    'svg onload'               => '<svg onload=alert(1)>',
    'color met expression'     => '[color=red;background:url(javascript:alert(1))]x[/color]',
    'color met afsluiter'      => '[color=red"><script>alert(1)</script>]x[/color]',
    'size met injectie'        => '[size=1"><script>alert(1)</script>]x[/size]',
    'body onload'              => '<body onload=alert(1)>',
    'autolink javascript'      => 'kijk hier: javascript:alert(1)',
    'genest bbcode'            => '[b][img]x" onerror="alert(1)[/img][/b]',
];

foreach ($payloads as $naam => $payload) {
    $problemen = keur(bericht_html($payload, ['spelacties' => true]));
    check($naam, $problemen === [], implode('; ', $problemen));
}

// En de opmaak moet natuurlijk wel gewoon werken.
$net = bericht_html('[b]vet[/b] en [url=https://voorbeeld.nl]link[/url]');
check('nette opmaak wordt wél omgezet',
    str_contains($net, '<strong>vet</strong>')
    && str_contains($net, 'href="https://voorbeeld.nl"'), $net);

// --- Deel 2: opgeslagen XSS ------------------------------------------------

kop('opgeslagen XSS: wat een speler invult, komt er als tekst weer uit');

$db  = tdb();
$gif = '<script>alert(1)</script>';

$db->prepare("UPDATE users SET info = ? WHERE login = 'Speler'")->execute([$gif]);
login('Speler', 'spelerwachtwoord123');

$profiel = haal('user.php?login=Speler')['body'];
check('profieltekst bevat geen echt script',
    !preg_match('#<script[^>]*>\s*alert#i', $profiel));
check('en is wel zichtbaar als tekst', str_contains($profiel, '&lt;script&gt;'));

$db->prepare("UPDATE users SET info = '' WHERE login = 'Speler'")->execute();

// --- Deel 3: rechten -------------------------------------------------------

kop('rechten: elk beheerniveau ziet precies wat het mag');

$speler = login('Speler', 'spelerwachtwoord123');
$mod    = login('Mod',    'modwachtwoord123456');
$admin  = login('Admin',  'adminwachtwoord12345');
$baas   = login('Baas',   'baaswachtwoord12345');

/** naam => rechtenniveau, in dezelfde volgorde als $sessies hieronder */
$niveaus = ['speler' => 1, 'mod' => 200, 'admin' => 255, 'baas' => 1000];
$sessies = ['speler' => $speler, 'mod' => $mod, 'admin' => $admin, 'baas' => $baas];

/** pagina => het laagste niveau dat erbij mag */
$paginas = [
    'admin.php'        => 'mod',
    'adm-online.php'   => 'mod',
    'adm-warn.php'     => 'mod',
    'adm-search.php'   => 'mod',
    'adm-msg.php'      => 'baas',
    'adm-ban.php'      => 'baas',
    'adm-addmulti.php' => 'baas',
    'adm-items.php'    => 'baas',
    'adm-premium.php'  => 'admin',
    'adm-getuigen.php' => 'baas',
    'adm-bo.php'       => 'baas',
    'adm-klikmissies.php' => 'baas',
];

foreach ($paginas as $pagina => $vanaf) {
    $mag = [];
    foreach ($sessies as $wie => $jar) {
        if (haal($pagina, null, $jar)['code'] === 200) {
            $mag[] = $wie;
        }
    }

    $verwacht = array_keys(array_filter(
        $niveaus,
        static fn (int $n): bool => $n >= $niveaus[$vanaf]
    ));

    check($pagina . ': vanaf ' . $vanaf, $mag === $verwacht,
        $mag === [] ? 'niemand' : implode(', ', $mag));
}

// Op adm-premium.php mag een admin de veilige acties, maar niet de
// advertentiecode of de balansinstellingen — die blijven voor de eigenaar,
// want dat veld gaat ongefilterd naar de browser van elke speler.
kop('adm-premium.php: admin mag geen advertentiecode of balans aanpassen');

$db->exec("DELETE FROM instellingen WHERE naam IN ('ads_html', 'premium_prijs')");

$tokenAdmin = tok(haal('adm-premium.php', null, $admin)['body']);
haal('adm-premium.php', ['_token' => $tokenAdmin, 'actie' => 'advertentie',
    'html' => '<script>alert(1)</script>', 'interval' => '10'], $admin);

$adsHtml = $db->query(
    "SELECT waarde FROM instellingen WHERE naam = 'ads_html'"
)->fetchColumn();

check('admin kan de advertentiecode niet zetten', $adsHtml === false,
    'ads_html: ' . var_export($adsHtml, true));

haal('adm-premium.php', ['_token' => $tokenAdmin, 'actie' => 'balans',
    'kans' => '1', 'prijs' => '1', 'kofi' => 'https://voorbeeld.nl'], $admin);

$premiumPrijs = $db->query(
    "SELECT waarde FROM instellingen WHERE naam = 'premium_prijs'"
)->fetchColumn();

check('admin kan de premiumprijs niet zetten', $premiumPrijs === false,
    'premium_prijs: ' . var_export($premiumPrijs, true));

kop('adm-premium.php: admin mag wel rechtstreeks premiumdagen toekennen');

$db->exec("UPDATE users SET premium_tot = NULL WHERE login = 'Speler'");

haal('adm-premium.php', ['_token' => $tokenAdmin, 'actie' => 'dagen',
    'speler2' => 'Speler', 'dagen' => '7'], $admin);

$premiumTot    = (string) $db->query(
    "SELECT premium_tot FROM users WHERE login = 'Speler'"
)->fetchColumn();
$verschilDagen = $premiumTot !== ''
    ? (int) round((strtotime($premiumTot) - time()) / 86400)
    : null;

check('premium van Speler staat nu precies 7 dagen in de toekomst',
    $verschilDagen === 7, 'verschil: ' . var_export($verschilDagen, true));

$db->exec("UPDATE users SET premium_tot = NULL WHERE login = 'Speler'");

kop('adm-bo.php: de eigenaar mag iemand tot eigenaar maken, niet hoger');

$db->exec("UPDATE users SET level = 1 WHERE login = 'Speler'");

$id      = (int) $db->query("SELECT id FROM users WHERE login = 'Speler'")->fetchColumn();
$tokenBo = tok(haal('adm-bo.php', null, $baas)['body']);
haal('adm-bo.php', ['_token' => $tokenBo, 'id' => (string) $id, 'level' => '1000'], $baas);

$nieuwLevel = (int) $db->query("SELECT level FROM users WHERE login = 'Speler'")->fetchColumn();
check('Speler is nu eigenaar (niveau 1000)', $nieuwLevel === 1000, 'niveau ' . $nieuwLevel);

$db->exec("UPDATE users SET level = 1 WHERE login = 'Speler'");

// Rechten moeten ook bij een POST gelden, niet alleen bij het tonen.
kop('rechten gelden ook bij POST');

$h = haal('adm-ban.php', null, $baas)['body'];
$r = haal('adm-ban.php', ['_token' => tok($h), 'actie' => 'ban', 'soort' => 'login',
    'doel' => 'Speler', 'reden' => 'test'], $mod);

$nog = (int) $db->query("SELECT COUNT(*) FROM bans WHERE login='Speler'")->fetchColumn();
check('moderator kan niet bannen', $r['code'] !== 200 && $nog === 0, 'HTTP ' . $r['code']);

// --- Deel 4: CSRF ----------------------------------------------------------

kop('CSRF: zonder geldig token gebeurt er niets');

$db->exec("UPDATE users SET zak=100000, bank=0, bc=NULL WHERE login='Speler'");

$zonder = haal('bank.php', ['amount' => '50000', 'in' => '1'], $speler);
$fout   = haal('bank.php', ['_token' => str_repeat('a', 64), 'amount' => '50000',
    'in' => '1'], $speler);

$bank = (int) $db->query("SELECT bank FROM users WHERE login='Speler'")->fetchColumn();

check('storten zonder token doet niets', $bank === 0, 'bank ' . $bank);
check('en met een verzonnen token ook niet', $bank === 0);

// Het verzoek wordt afgebroken met 419 en een uitlegpagina, niet met een lege
// witte pagina of een fatale fout.
check('antwoord is 419', $zonder['code'] === 419 && $fout['code'] === 419,
    $zonder['code'] . ' en ' . $fout['code']);
check('met uitleg en een weg terug',
    str_contains($zonder['body'], 'Sessie verlopen')
    && str_contains($zonder['body'], 'Ga terug naar het spel'));

// Elk formulier dat iets verandert, hoort een token te hebben.
kop('elk formulier draagt een token');

$zonderToken = [];

foreach (array_map('basename', glob(BV_WORTEL . '/*.php') ?: []) as $pagina) {
    if (in_array($pagina, ['cron.php', 'logout.php'], true)) {
        continue;
    }

    $body = haal($pagina, null, $baas)['body'];
    preg_match_all('#<form[^>]*method="post"[^>]*>.*?</form>#is', $body, $formulieren);

    foreach ($formulieren[0] as $formulier) {
        if (!str_contains($formulier, 'name="_token"')) {
            $zonderToken[] = $pagina;
            break;
        }
    }
}

check('geen enkel POST-formulier zonder token', $zonderToken === [],
    implode(', ', array_slice($zonderToken, 0, 8)));

// --- Deel 5: geen handelingen achter een GET-link --------------------------

kop('geen handelingen achter een gewone link');

$verdacht = [];

foreach (array_map('basename', glob(BV_WORTEL . '/*.php') ?: []) as $pagina) {
    $body = haal($pagina, null, $baas)['body'];

    preg_match_all('#href="([^"]*(?:actie|action|verwijder|delete)=[^"]*)"#i',
        $body, $links);

    foreach ($links[1] as $link) {
        // Links die alleen iets tonen of een formulier voorinvullen zijn prima.
        if (preg_match('/(actie|action)=(tonen|bekijk|lezen|nieuw|bewerk|zoek)/i', $link)) {
            continue;
        }
        $verdacht[] = $pagina . ': ' . $link;
    }
}

check('geen wijzigende GET-links', $verdacht === [],
    implode(' | ', array_slice($verdacht, 0, 5)));

// --- Deel: IP-adres achter Cloudflare ---------------------------------------

kop('IP-adres: CF-Connecting-IP telt, REMOTE_ADDR is de terugval');

$db  = tdb();
$vraagIp = $db->prepare('SELECT ip FROM users WHERE login = ?');
$db->exec("DELETE FROM users WHERE login LIKE 'Cftest%'");

$login1 = 'Cftest' . random_int(10000, 99999);
doe('register.php', [
    'gebruiker'   => $login1,
    'pass'        => 'eenlangwachtwoord',
    'passconfirm' => 'eenlangwachtwoord',
    'email'       => strtolower($login1) . '@voorbeeld.test',
    'geslacht'    => 'Man',
], nieuwe_sessie(), ['CF-Connecting-IP' => '203.0.113.9']);

$vraagIp->execute([$login1]);
$ip1 = $vraagIp->fetchColumn();
check('CF-Connecting-IP wordt overgenomen', $ip1 === '203.0.113.9', (string) $ip1);

$login2 = 'Cftest' . random_int(10000, 99999);
doe('register.php', [
    'gebruiker'   => $login2,
    'pass'        => 'eenlangwachtwoord',
    'passconfirm' => 'eenlangwachtwoord',
    'email'       => strtolower($login2) . '@voorbeeld.test',
    'geslacht'    => 'Man',
], nieuwe_sessie());

$vraagIp->execute([$login2]);
$ip2 = $vraagIp->fetchColumn();
check('zonder de header valt terug op REMOTE_ADDR', $ip2 !== '203.0.113.9' && $ip2 !== '',
    (string) $ip2);

$db->exec("DELETE FROM users WHERE login LIKE 'Cftest%'");

// --- Deel: CSP form-action alleen verruimd waar dat nodig is -----------------

kop('CSP: form-action staat een externe doorverwijzing alleen toe op klikmissies.php');

/** De ruwe CSP-header van een pagina, zonder de doorverwijzing te volgen. */
function csp_header(string $pad): string
{
    $ch = curl_init(BV_BASIS . '/' . ltrim($pad, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);

    return preg_match('/^Content-Security-Policy:\s*(.+)$/mi', $body, $m) ? trim($m[1]) : '';
}

$klik = csp_header('klikmissies.php');
$home = csp_header('home.php');

check('klikmissies.php verruimt form-action voor de externe doorverwijzing',
    str_contains($klik, "form-action 'self' https: http:;"), $klik);
check('gewone pagina\'s houden de strikte form-action',
    str_contains($home, "form-action 'self';"), $home);

samenvatting();
