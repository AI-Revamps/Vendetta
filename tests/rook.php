<?php
/**
 * Rooktest: haal elke pagina op als ingelogde eigenaar en meld alles wat geen
 * schone 200 geeft.
 *
 *     php tests/rook.php
 *
 * Dit is de test die je na élke wijziging draait. Hij vangt geen logica, maar
 * wel alles wat stukgaat: fatale fouten, databasefouten, ontbrekende functies.
 */

declare(strict_types=1);

require __DIR__ . '/_start.php';

login('Baas', 'baaswachtwoord12345');

/** Deze zijn geen gewone pagina's. */
const OVERSLAAN = ['cron.php', 'logout.php'];

$paginas = alle_paginas();
$stuk    = 0;

foreach ($paginas as $pagina) {
    if (in_array($pagina, OVERSLAAN, true)) {
        continue;
    }

    $r    = haal($pagina);
    $body = $r['body'];

    $problemen = [];

    if ($r['code'] !== 200)                       { $problemen[] = 'HTTP ' . $r['code']; }
    if (str_contains($body, 'Fatal error'))       { $problemen[] = 'fatale fout'; }
    if (str_contains($body, 'Warning:'))          { $problemen[] = 'waarschuwing'; }
    if (str_contains($body, 'Deprecated:'))       { $problemen[] = 'verouderd'; }
    if (str_contains($body, 'Databasefout'))      { $problemen[] = 'databasefout'; }
    if (preg_match('/Undefined (variable|array key|index)/', $body)) {
        $problemen[] = 'ongedefinieerd';
    }

    if ($problemen !== []) {
        $stuk++;
        printf("  %-24s %s\n", $pagina, implode(', ', $problemen));

        if (preg_match('/(Fatal error|Warning|Deprecated|Undefined)[^<\n]{0,160}/', $body, $m)) {
            echo '    ', trim((string) preg_replace('/\s+/', ' ', $m[0])), "\n";
        }
    }
}

check('alle pagina\'s laden schoon', $stuk === 0,
    $stuk . ' van ' . count($paginas) . ' met problemen');

samenvatting();
