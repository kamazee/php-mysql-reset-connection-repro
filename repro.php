<?php

declare(strict_types=1);

const TEST_TEXT = 'café';
const CORRECT_BYTES = '636166C3A9';
const BROKEN_TEXT = 'cafÃ©';
const BROKEN_BYTES = '636166C383C2A9';

$host = getenv('DB_HOST') ?: 'database';
$database = getenv('DB_NAME') ?: 'repro';
$user = getenv('DB_USER') ?: 'repro';
$password = getenv('DB_PASSWORD') ?: 'repro';
$version = getenv('PHP_VERSION') ?: '8.6.0alpha3';
$resetExpected = $version === 'feat-mysqlnd-com-reset-connection';
$token = bin2hex(random_bytes(8));
$dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";
$failures = [];

$check = static function (bool $condition, string $message) use (&$failures): void {
    printf("  %s: %s\n", $condition ? 'OK' : 'FAIL', $message);

    if (!$condition) {
        $failures[] = $message;
    }
};

$connectPdo = static function (array $options = []) use (
    $dsn,
    $user,
    $password,
): PDO {
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_PERSISTENT => 'pdo-repro',
        PDO::ATTR_EMULATE_PREPARES => false,
    ] + $options);
};

$inspect = static fn (): PDO => new PDO(
    $dsn,
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$readState = static function (PDO|mysqli $connection): array {
    $sql = 'SELECT CONNECTION_ID() AS id, '
        . '@@character_set_client AS client_encoding, '
        . '@@character_set_connection AS connection_encoding, '
        . '@@character_set_results AS result_encoding, '
        . '@@session.time_zone AS time_zone, '
        . '@repro_marker AS marker';
    $result = $connection->query($sql);

    return $connection instanceof PDO
        ? $result->fetch(PDO::FETCH_ASSOC)
        : $result->fetch_assoc();
};

$readStoredText = static function (string $scenario) use ($inspect, $token): array {
    $statement = $inspect()->prepare(
        'SELECT payload AS text, HEX(payload) AS bytes '
        . 'FROM repro_data WHERE scenario = ? AND run_token = ?',
    );
    $statement->execute([$scenario, $token]);

    return $statement->fetch(PDO::FETCH_ASSOC);
};

$expectedEncoding = $resetExpected ? 'latin1' : 'utf8mb4';
$expectedText = $resetExpected ? BROKEN_TEXT : TEST_TEXT;
$expectedBytes = $resetExpected ? BROKEN_BYTES : CORRECT_BYTES;

printf(
    "PHP: %s; build: %s; reset expected: %s\n",
    PHP_VERSION,
    $version,
    $resetExpected ? 'yes' : 'no',
);
printf("mysqlnd: %s\n", mysqli_get_client_info());

$inspect()->exec(
    'CREATE TABLE IF NOT EXISTS repro_data ('
    . 'scenario VARCHAR(64) NOT NULL, '
    . 'run_token VARCHAR(64) NOT NULL, '
    . 'payload TEXT CHARACTER SET utf8mb4 NOT NULL, '
    . 'PRIMARY KEY (scenario, run_token)'
    . ') CHARACTER SET utf8mb4',
);

$serverEncoding = $inspect()
    ->query('SELECT @@character_set_server')
    ->fetchColumn();
$check($serverEncoding === 'latin1', 'server text encoding is latin1');

echo "\n[1] PDO connection reuse\n";

$options = [
    Pdo\Mysql::ATTR_INIT_COMMAND => "SET time_zone = '+02:00'",
];
$connection = $connectPdo($options);
$before = $readState($connection);
$temporaryTable = "tmp_repro_{$token}";

$connection->exec("SET @repro_marker = 'present'");
$connection->exec(
    "CREATE TEMPORARY TABLE `{$temporaryTable}` (value_col INT)",
);
$connection->exec("INSERT INTO `{$temporaryTable}` VALUES (42)");

unset($connection);
gc_collect_cycles();

$connection = $connectPdo($options);
$after = $readState($connection);
$temporaryTableExists = false;

try {
    $temporaryTableExists = (int) $connection
        ->query("SELECT value_col FROM `{$temporaryTable}`")
        ->fetchColumn() === 42;
} catch (PDOException) {
    // Resetting the connection removes the temporary table.
}

$statement = $connection->prepare(
    'REPLACE INTO repro_data '
    . '(scenario, run_token, payload) VALUES (?, ?, ?)',
);
$statement->execute(['pdo', $token, TEST_TEXT]);
$storedText = $readStoredText('pdo');

printf("  before: %s\n", json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
printf("  after:  %s\n", json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
printf("  stored: %s\n", json_encode($storedText, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

$check($before['id'] === $after['id'], 'PDO uses the same connection');
$check($before['client_encoding'] === 'utf8mb4', 'PDO starts with UTF-8');
$check($before['time_zone'] === '+02:00', 'PDO runs its setup query');
$check($after['client_encoding'] === $expectedEncoding, 'PDO text encoding is expected');
$check(
    $after['time_zone'] === ($resetExpected ? '+00:00' : '+02:00'),
    'PDO time zone is expected',
);
$check(
    ($after['marker'] === 'present') !== $resetExpected,
    'PDO user variable is expected',
);
$check(
    $temporaryTableExists !== $resetExpected,
    'PDO temporary table is expected',
);
$check($storedText['text'] === $expectedText, 'PDO stored text is expected');
$check($storedText['bytes'] === $expectedBytes, 'PDO stored bytes are expected');

$connection = null;
gc_collect_cycles();

echo "\n[2] mysqli connection reuse\n";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$connection = mysqli_init();
$connection->real_connect("p:{$host}", $user, $password, $database);
$connection->set_charset('utf8mb4');
$before = $readState($connection);
$connection->close();

$connection = mysqli_init();
$connection->real_connect("p:{$host}", $user, $password, $database);
$after = $readState($connection);
$statement = $connection->prepare(
    'REPLACE INTO repro_data '
    . '(scenario, run_token, payload) VALUES (?, ?, ?)',
);
$scenario = 'mysqli';
$value = TEST_TEXT;
$statement->bind_param('sss', $scenario, $token, $value);
$statement->execute();
$storedText = $readStoredText('mysqli');

printf("  before: %s\n", json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
printf("  after:  %s\n", json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
printf("  stored: %s\n", json_encode($storedText, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

$check($before['id'] === $after['id'], 'mysqli uses the same connection');
$check($before['client_encoding'] === 'utf8mb4', 'mysqli starts with UTF-8');
$check($after['client_encoding'] === $expectedEncoding, 'mysqli text encoding is expected');
$check($storedText['text'] === $expectedText, 'mysqli stored text is expected');
$check($storedText['bytes'] === $expectedBytes, 'mysqli stored bytes are expected');

$connection->close();

if ($failures !== []) {
    printf("\nRESULT: FAILED (%d assertions)\n", count($failures));
    exit(1);
}

printf(
    "\nRESULT: OK — %s\n",
    $resetExpected
        ? 'problem reproduced; connection settings were lost.'
        : 'connection settings were kept.',
);
