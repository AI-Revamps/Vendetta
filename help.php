<?php
/**
 * Spelregels en veelgestelde vragen.
 *
 * De oude versie was een losse HTML-pagina met de regels van het spel van 2007
 * erin. Die klopten grotendeels niet meer, en waren op geen enkele manier
 * gekoppeld aan wat de code werkelijk doet.
 *
 * Deze versie haalt alle getallen uit de code en de instellingen: rangen uit
 * rank_ladder(), afkoeltijden uit cooldowns(), prijzen uit de gedeelde
 * constanten in inc/game.php, en de premiuminstellingen uit de database. Wie de
 * balans aanpast hoeft dus niet ook nog de uitleg bij te werken.
 */

declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
require BV_INC . '/combat.php';
require BV_INC . '/casino.php';
require BV_INC . '/opmaak.php';

layout_header('Spelregels');

// --- Inhoud ---------------------------------------------------------------

$onderdelen = [
    'begin'    => 'Hoe je begint',
    'rangen'   => 'Rangen',
    'geld'     => 'Geld verdienen',
    'klikmissies' => 'Klikmissies',
    'moord'    => 'Moord en getuigen',
    'dood'     => 'Doodgaan',
    'accounts' => 'Accounts',
    'premium'  => 'Premium en diamanten',
    'opmaak'   => 'Opmaakcodes',
    'regels'   => 'Huisregels',
    'privacy'  => 'Privacy',
];

panel_open('Spelregels');
echo '<p>Alles wat hieronder staat komt rechtstreeks uit het spel zelf. Verandert het '
   . 'beheer de balans, dan verandert deze pagina mee.</p>';
echo '<ul class="inhoud">';
foreach ($onderdelen as $anker => $titel) {
    echo '<li><a href="#' . e($anker) . '">' . e($titel) . '</a></li>';
}
echo '</ul>';
panel_close();

// --- Hoe je begint ---------------------------------------------------------

panel_open('Hoe je begint', 'begin');

echo '<p>Je begint als <strong>' . e(rank_name(0)) . '</strong> met '
   . money((int) config('game.start_money', 1000)) . ' op zak, in een willekeurige stad. '
   . 'Vanaf daar is het de bedoeling dat je opklimt tot '
   . '<strong>' . e(rank_name(999999)) . '</strong>.</p>';

echo '<p>Dat doe je met misdaden, auto\'s stelen, overvallen, handel in drank en drugs, '
   . 'en door concurrenten uit de weg te ruimen. Je klimt op ervaring; die krijg je van '
   . 'vrijwel alles wat je onderneemt, ook als het mislukt.</p>';

echo '<p>De eerste <strong>' . BESCHERMING_UREN . ' uur</strong> sta je onder '
   . 'beginnersbescherming: niemand kan je vermoorden, maar jij ook niemand.</p>';

echo '<h3>Afkoeltijden</h3>';
echo '<p>Na een handeling moet je even wachten voor je hem opnieuw kunt doen.</p>';

echo '<div class="tabelwikkel"><table class="lijst">';
echo '<thead><tr><th>Handeling</th><th class="getal">Wachttijd</th></tr></thead><tbody>';

$namen = [
    'crime'    => 'Misdaad',
    'auto'     => 'Auto stelen',
    'bank'     => 'Bankoverval',
    'route'    => 'Route 66',
    'kill'     => 'Moord',
    'slaap'    => 'Kogelfabriek',
    'fitness'  => 'Fitness',
    'schieten' => 'Schietbaan',
];

foreach (cooldowns() as $sleutel => $seconden) {
    echo '<tr><th scope="row">' . e($namen[$sleutel] ?? $sleutel) . '</th>'
       . '<td class="getal">' . e(duration($seconden)) . '</td></tr>';
}

echo '</tbody></table></div>';
panel_close();

// --- Rangen ----------------------------------------------------------------

panel_open('Rangen', 'rangen');

echo '<p>Je rang hangt af van je ervaring. Er zijn er ' . count(rank_ladder()) . '.</p>';

echo '<div class="tabelwikkel"><table class="lijst">';
echo '<thead><tr><th>Rang</th><th>Vrouwelijke vorm</th><th class="getal">Vanaf ervaring</th>'
   . '</tr></thead><tbody>';

foreach (rank_ladder() as $stap) {
    echo '<tr>';
    echo '<td>' . e($stap[1]) . '</td>';
    echo '<td>' . ($stap[2] !== null ? e($stap[2]) : '<span class="uit">—</span>') . '</td>';
    echo '<td class="getal">' . num($stap[0]) . '</td>';
    echo '</tr>';
}

echo '</tbody></table></div>';
panel_close();

// --- Geld ------------------------------------------------------------------

panel_open('Geld verdienen', 'geld');

echo '<ul>';
echo '<li><strong>Misdaden</strong> zijn het startpunt. Hoe hoger je rang, hoe vaker ze lukken.</li>';
echo '<li><strong>Auto\'s stelen</strong> levert wagens op die je in de garage kunt opknappen '
   . 'en op de zwarte markt verkopen.</li>';
echo '<li><strong>Drank en drugs</strong> koop je goedkoop in de ene stad en verkoop je duur '
   . 'in de andere. De prijzen wisselen elk uur.</li>';
echo '<li><strong>Route 66 en Organised Crime</strong> doe je samen met anderen; de buit is '
   . 'groter maar je hebt medespelers nodig.</li>';
echo '<li><strong>Casino\'s</strong> koop je voor ' . money(CASINO_PRIJS) . '. Je verdient aan '
   . 'iedereen die er speelt, maar je moet minstens ' . money(CASINO_MIN_KAS) . ' op de bank '
   . 'houden, anders kan er niet gespeeld worden.</li>';
echo '<li><strong>De loterij</strong> kost ' . money(LOT_PRIJS) . ' per lot, met hoogstens '
   . LOT_MAX . ' loten per speler. Er wordt wekelijks getrokken.</li>';
echo '</ul>';

echo '<h3>Huizen</h3>';
echo '<p>Een huis kost ' . money(HUIS_KOOPPRIJS) . ' en levert '
   . money(HUIS_VERKOOPPRIJS) . ' op als je het weer verkoopt. Je kunt er één per stad '
   . 'hebben, en in een stad waar je een huis hebt verdedig je jezelf beter.</p>';

echo '<h3>De bank</h3>';
echo '<p>Geld op de bank raak je niet kwijt als iemand je berooft, maar wél als je vermoord '
   . 'wordt. Zet daarom iemand in je testament: die erft de helft van je banksaldo en je '
   . 'wagens.</p>';

panel_close();

// --- Klikmissies -------------------------------------------------------------

panel_open('Klikmissies', 'klikmissies');

echo '<p>Op de pagina <a href="' . e(url('klikmissies.php')) . '">Klikmissies</a> vind je '
   . 'stemlinks naar externe toplijsten. Stem daar op de website en je krijgt een beloning: '
   . 'geld op zak, geld op de bank, diamanten, of een combinatie daarvan — dat verschilt per '
   . 'link.</p>';

echo '<p>Sommige links bevestigen je stem automatisch; de beloning komt dan vanzelf. Bij '
   . 'andere klik je na het stemmen zelf op "Ik heb gestemd" — dat kan pas na een korte '
   . 'wachttijd, zodat je ook echt eerst gestemd hebt.</p>';

echo '<p>Elke link heeft zijn eigen afkoeltijd voordat je er opnieuw voor beloond kunt worden.</p>';

panel_close();

// --- Moord -----------------------------------------------------------------

panel_open('Moord en getuigen', 'moord');

echo '<p>Je kunt iemand vermoorden die in dezelfde stad is als jij. Je hebt kogels nodig en '
   . 'een wapen; hoe meer moordervaring je hebt, hoe beter je richt.</p>';

echo '<ul>';
echo '<li>Je mag hoogstens <strong>' . MAX_RANGVERSCHIL . ' rangen boven je eigen rang</strong> '
   . 'moorden. Naar beneden mag altijd.</li>';
echo '<li>Hoogstens <strong>' . MOORDEN_PER_WEEK . ' moorden per week</strong>.</li>';
echo '<li>Wie onder beginnersbescherming staat kun je niet raken, en zelf ook niet schieten.</li>';
echo '<li>Stafleden kun je niet vermoorden.</li>';
echo '<li>Je slachtoffer kan terugschieten. Hoeveel kogels dat zijn stelt hij zelf in bij '
   . 'zijn profiel.</li>';
echo '</ul>';

echo '<h3>Wie heeft mij vermoord?</h3>';
echo '<p><strong>Dat krijg je niet te horen.</strong> Het staat niet op je overlijdenspagina '
   . 'en ook niet in de statistieken. Alleen ooggetuigen weten wie het deed.</p>';

$wijze = getuigenwijzen()[getuigenwijze()];

echo '<p>Bij elke moord worden er getuigen aangewezen. Op dit moment: <strong>'
   . e($wijze['naam']) . '</strong>. ' . e($wijze['uitleg']) . '</p>';

echo '<p>Een getuige kan zijn verklaring <strong>' . round(OOGGETUIGE_GELDIG / 86400)
   . ' dagen lang</strong> op de <a href="' . e(url('mshop.php?x=ws')) . '">zwarte markt</a> '
   . 'te koop zetten. Koop je er een, dan hoor je wie de dader was. Zo kom je erachter, en zo '
   . 'kun je als getuige verdienen aan wat je gezien hebt.</p>';

panel_close();

// --- Doodgaan --------------------------------------------------------------

panel_open('Doodgaan', 'dood');

echo '<p>Word je vermoord, dan is dat <strong>niet het einde van je account</strong>. Je '
   . 'krijgt een scherm waar staat wat er gebeurd is, en met één knop begin je opnieuw met '
   . 'hetzelfde account. Je hoeft je dus niet opnieuw aan te melden.</p>';

echo '<div class="tabelwikkel"><table class="lijst">';
echo '<thead><tr><th>Wat je kwijtraakt</th><th>Wat je houdt</th></tr></thead><tbody>';
echo '<tr><td>rang, ervaring en eerpunten</td><td>je gebruikersnaam en wachtwoord</td></tr>';
echo '<tr><td>al je geld, op zak en op de bank</td><td>je profieltekst en je plaatje</td></tr>';
echo '<tr><td>wapens, bescherming, vervoer en lijfwachten</td><td>lopende premium</td></tr>';
echo '<tr><td>je auto\'s, huizen, familie en huwelijk</td><td>hoe vaak je al omgelegd bent</td></tr>';
echo '<tr><td>een casino of kogelfabriek</td><td></td></tr>';
echo '</tbody></table></div>';

echo '<p>Je begint weer in een willekeurige stad met '
   . money((int) config('game.start_money', 1000)) . ', en de '
   . 'beginnersbescherming loopt opnieuw. Hoe vaak je omgelegd bent staat op je profiel.</p>';

panel_close();

// --- Accounts --------------------------------------------------------------

panel_open('Accounts', 'accounts');

if (config('game.allow_multi_accounts')) {
    echo '<p>Meerdere accounts vanaf hetzelfde adres zijn hier toegestaan.</p>';
} else {
    echo '<p><strong>Eén account per persoon.</strong> Per IP-adres en per e-mailadres kan er '
       . 'maar één account bestaan, ook als dat account op dit moment dood is. Dat kan, omdat '
       . 'je na een moord gewoon opnieuw begint met hetzelfde account.</p>';
    echo '<p>Speel je met een huisgenoot op dezelfde aansluiting? Vraag dan een beheerder om '
       . 'toestemming; die kan dat per adres regelen.</p>';
}

echo '<h3>Wachtwoord vergeten</h3>';
echo '<p>Gebruik <a href="' . e(url('login.php?x=lostpass')) . '">wachtwoord vergeten</a> op '
   . 'de inlogpagina. Je krijgt een link per e-mail waarmee je een nieuw wachtwoord instelt. '
   . 'Je wachtwoord veranderen doe je op je <a href="' . e(url('profile.php')) . '">profiel</a>.</p>';

echo '<h3>Kleur van namen</h3>';
echo '<p>Overal waar een gebruikersnaam getoond wordt zie je aan de kleur meteen wie het is: '
   . '<strong style="color:var(--rol-staf)">bordeauxrood</strong> voor Owner en Admin, '
   . '<strong style="color:var(--rol-moderator)">oranje</strong> voor Moderator, '
   . '<strong style="color:var(--rol-premium)">goud</strong> voor wie premium heeft, en een '
   . '<strong class="naam-gebanned">doorgestreepte naam</strong> voor een gebanned account.</p>';

panel_close();

// --- Premium ---------------------------------------------------------------

panel_open('Premium en diamanten', 'premium');

echo '<p><strong>Diamanten</strong> zijn de betaalde munt. Je vindt ze bij toeval tijdens een '
   . 'geslaagde misdaad of autodiefstal: de kans is ongeveer <strong>één op '
   . num(diamant_kans()) . '</strong>.</p>';

echo '<p><strong>Premium</strong> duurt ' . PREMIUM_DAGEN . ' dagen en kost '
   . num(premium_prijs()) . ' diamanten. Je kunt het ook buiten het spel om kopen; dan krijg '
   . 'je een code die je op de <a href="' . e(url('premium.php')) . '">premiumpagina</a> '
   . 'invult. Verlengen telt de dagen erbij op.</p>';

$interval = ads_interval();

if ($interval > 0 && ads_html() !== '') {
    echo '<p>Wat het je oplevert: <strong>geen advertenties</strong>. Zonder premium '
       . 'onderbreekt het spel je elke ' . num($interval) . ' paginabezoeken met een '
       . 'advertentiepagina.</p>';
} else {
    echo '<p>Er staan op dit moment geen advertenties aan, dus premium levert nu weinig op.</p>';
}

echo '<p>Premium maakt je <strong>niet sterker</strong>. Wie niet betaalt speelt precies '
   . 'hetzelfde spel; je koopt rust, geen voorsprong.</p>';

panel_close();

// --- Opmaak ----------------------------------------------------------------

panel_open('Opmaakcodes', 'opmaak');

echo '<p>In je profiel, op het forum en in privéberichten kun je opmaak gebruiken.</p>';

echo '<div class="tabelwikkel"><table class="lijst">';
echo '<thead><tr><th>Code</th><th>Resultaat</th></tr></thead><tbody>';

$voorbeelden = [
    '[b]vet[/b]',
    '[i]schuin[/i]',
    '[u]onderstreept[/u]',
    '[s]doorgehaald[/s]',
    '[small]klein[/small]',
    '[color=rood]gekleurd[/color]',
    '[size=5]groter[/size]',
    '[url=https://example.com]een link[/url]',
    '[quote]een citaat[/quote]',
    '[list][*]eerste[*]tweede[/list]',
];

foreach ($voorbeelden as $code) {
    echo '<tr><td><code>' . e($code) . '</code></td>'
       . '<td>' . bericht_html($code) . '</td></tr>';
}

echo '</tbody></table></div>';

echo '<p class="uitleg">Kleuren die je met een naam kunt aanroepen: rood, blauw, groen, geel, '
   . 'wit, zwart, oranje, paars, grijs, roze en bruin. Een kleurcode als '
   . '<code>[color=#ff9900]</code> mag ook.</p>';

echo '<h3>Tellers</h3>';
echo '<p>In je profieltekst worden deze vervangen door je eigen cijfers:</p>';
echo '<p><code>[crime]</code> <code>[auto]</code> <code>[route]</code> <code>[oc]</code> '
   . '<code>[race]</code> <code>[kill]</code> <code>[bo]</code></p>';

echo '<h3>Emoticons</h3>';
echo '<p>' . bericht_html(':) :( ;) :D :p :o 8) :| :s :x') . '</p>';

panel_close();

// --- Huisregels ------------------------------------------------------------

panel_open('Huisregels', 'regels');

echo '<ol>';
echo '<li>Eén account per persoon. Wie er meer aanmaakt raakt ze allemaal kwijt.</li>';
echo '<li>Geen scripts, bots of andere hulpmiddelen die voor je spelen.</li>';
echo '<li>Vind je een fout waarmee je kunt valsspelen, meld hem dan via '
   . '<a href="' . e(url('tip.php')) . '">tip of bug melden</a>. Misbruik je hem, dan lig je eruit.</li>';
echo '<li>Geen beledigingen, bedreigingen of reclame in berichten en op het forum.</li>';
echo '<li>Accounts zijn niet over te dragen en niet te verkopen.</li>';
echo '<li>Het beheer beslist bij twijfel.</li>';
echo '</ol>';

echo '<p>Wie betrapt wordt op valsspelen komt op de '
   . '<a href="' . e(url('wallofshame.php')) . '">schandpaal</a> te staan, waar andere '
   . 'spelers tomaten naar hem kunnen gooien.</p>';

panel_close();

// --- Privacy ---------------------------------------------------------------

panel_open('Privacy', 'privacy');

echo '<p>Wat er van je bewaard wordt:</p>';
echo '<ul>';
echo '<li><strong>Je e-mailadres</strong>, voor activatie en wachtwoordherstel. Het wordt '
   . 'nergens aan derden gegeven en is voor andere spelers niet zichtbaar.</li>';
echo '<li><strong>Je IP-adres</strong>, bij het aanmelden en bij het inloggen. Dat is nodig om '
   . 'te zien of iemand meerdere accounts aanmaakt. Alleen het beheer kan dit inzien, en het '
   . 'wordt na negentig dagen opgeruimd.</li>';
echo '<li><strong>Je wachtwoord</strong> wordt versleuteld opgeslagen en is voor niemand '
   . 'leesbaar, ook niet voor het beheer.</li>';
echo '<li><strong>Eén cookie</strong> om je ingelogd te houden. Meer cookies zet het spel '
   . 'zelf niet.</li>';
echo '</ul>';

if (ads_html() !== '') {
    echo '<p>Op de advertentiepagina staat materiaal van een advertentienetwerk. Dat netwerk '
       . 'kan zelf gegevens verzamelen; daar heeft dit spel geen zeggenschap over.</p>';
}

echo '<p>Wil je dat je account verwijderd wordt, vraag dat dan aan een beheerder via '
   . '<a href="' . e(url('tip.php')) . '">tip of bug melden</a>.</p>';

panel_close();

layout_footer();
