<?php
/**
 * Databaselaag.
 *
 * Alle queries lopen via prepared statements. Bouw NOOIT een query op met
 * string-concatenatie van gebruikersinvoer -- geef waarden altijd mee als
 * parameter:
 *
 *     q('UPDATE users SET zak = zak + ? WHERE id = ?', [$bedrag, $id]);
 *     $user = q_row('SELECT * FROM users WHERE login = ?', [$login]);
 */

declare(strict_types=1);

// Alleen laadbaar via bootstrap.php. .htaccess schermt /inc/ al af, maar dat
// werkt niet op nginx en niet bij de ingebouwde PHP-server.
defined('BV_INC') || exit;

/** Actieve PDO-verbinding, of maak er een aan bij eerste gebruik. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = config('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['host'],
        (int) ($cfg['port'] ?? 3306),
        $cfg['name']
    );

    try {
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Echte prepared statements, geen emulatie: de database ziet
            // waarden en query gescheiden, dus injectie is onmogelijk.
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $e) {
        db_fail($e);
    }

    // Strikte modus: stille afkapping van te grote getallen en ongeldige
    // datums wordt een fout in plaats van corrupte data.
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

    return $pdo;
}

/** Voer een query uit met parameters en geef het statement terug. */
function q(string $sql, array $params = []): PDOStatement
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        db_fail($e, $sql);
    }
}

/** Eén rij, of null als er niets gevonden is. */
function q_row(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/** Alle rijen als array van arrays. */
function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Eén enkele waarde uit de eerste kolom van de eerste rij. */
function q_val(string $sql, array $params = [], mixed $default = null): mixed
{
    $val = q($sql, $params)->fetchColumn();
    return $val === false ? $default : $val;
}

/** Aantal rijen dat de laatste INSERT/UPDATE/DELETE raakte. */
function q_count(string $sql, array $params = []): int
{
    return q($sql, $params)->rowCount();
}

/** Id van de laatst ingevoegde rij. */
function db_last_id(): int
{
    return (int) db()->lastInsertId();
}

/**
 * Voer werk uit binnen een transactie. Gooit de callback een exception,
 * dan wordt alles teruggedraaid.
 *
 * Gebruik dit voor elke handeling waarbij geld of items van eigenaar wisselen:
 *
 *     db_transaction(function () use ($van, $naar, $bedrag) {
 *         $saldo = q_val('SELECT zak FROM users WHERE id = ? FOR UPDATE', [$van]);
 *         if ($saldo < $bedrag) { throw new RuntimeException('Te weinig geld'); }
 *         q('UPDATE users SET zak = zak - ? WHERE id = ?', [$bedrag, $van]);
 *         q('UPDATE users SET zak = zak + ? WHERE id = ?', [$bedrag, $naar]);
 *     });
 */
function db_transaction(callable $fn): mixed
{
    $pdo = db();

    // Geneste aanroepen doen niet nog een keer BEGIN.
    if ($pdo->inTransaction()) {
        return $fn();
    }

    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Named lock op de database. Voorkomt dat twee gelijktijdige requests
 * dezelfde periodieke taak uitvoeren. Werkt ook op shared hosting.
 *
 * @return bool True als de lock verkregen is (en dus uitgevoerd mag worden).
 */
function db_try_lock(string $name, int $timeout = 0): bool
{
    return (bool) q_val('SELECT GET_LOCK(?, ?)', [$name, $timeout], 0);
}

function db_release_lock(string $name): void
{
    q('SELECT RELEASE_LOCK(?)', [$name]);
}

/** Bestaat deze tabel in de huidige database? */
function db_table_exists(string $table): bool
{
    $n = q_val(
        'SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?',
        [$table],
        0
    );
    return (int) $n > 0;
}

/**
 * Databasefout afhandelen: naar het log, en een nette pagina voor de bezoeker.
 * In debug-modus wordt de echte fout getoond.
 *
 * @return never
 */
function db_fail(PDOException $e, string $sql = ''): void
{
    $detail = $e->getMessage() . ($sql !== '' ? "\nQuery: {$sql}" : '');
    error_log('[db] ' . $detail);

    if (config('debug')) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Databasefout</h1><pre>' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</pre>';
        exit;
    }

    fail_page(
        'We zijn zo terug',
        'We zijn het spel eventjes aan het bijwerken. Meestal duurt dat maar een paar ' .
        'minuten — vernieuw deze pagina zo nog eens. Blijft dit lang duren, laat het dan ' .
        'weten aan de beheerder.'
    );
}
