<?php

declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'database';
$port = (int) (getenv('DB_PORT') ?: 3306);
$database = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$server = getenv('DB_SERVER') ?: 'singlestore';
$version = getenv('PHP_VERSION') ?: '8.6.0alpha3';
$resetExpected = $version === 'feat-mysqlnd-com-reset-connection';
$dsn = "mysql:host={$host};port={$port}";

if ($database !== '') {
    $dsn .= ";dbname={$database}";
}

$connect = static fn (): PDO => new PDO(
    $dsn,
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_PERSISTENT => 'mysql-protocol-reset',
    ],
);

$connectionId = match ($server) {
    'clickhouse' => static function (PDO $connection): string {
        $queryId = (string) $connection
            ->query('SELECT currentQueryID()')
            ->fetchColumn();

        if (!preg_match('/^mysql:(\d+):/', $queryId, $matches)) {
            throw new RuntimeException("Unexpected ClickHouse query ID: {$queryId}");
        }

        return $matches[1];
    },
    'singlestore' => static fn (PDO $connection): string => (string) $connection
        ->query('SELECT CONNECTION_ID()')
        ->fetchColumn(),
    default => throw new RuntimeException("Unknown database server: {$server}"),
};

printf(
    "PHP: %s; build: %s; reset expected: %s\n",
    PHP_VERSION,
    $version,
    $resetExpected ? 'yes' : 'no',
);

try {
    $connection = $connect();
    $beforeId = $connectionId($connection);

    if ($server === 'singlestore') {
        $connection->exec("SET @example_value = 'kept'");
    }
} catch (Throwable $error) {
    fwrite(STDERR, "RESULT: FAILED — first connection failed: {$error->getMessage()}\n");
    exit(1);
}

unset($connection);
gc_collect_cycles();

try {
    $connection = $connect();
    $afterId = $connectionId($connection);
} catch (Throwable $error) {
    if ($server === 'clickhouse' && $resetExpected) {
        printf("RESULT: PROBLEM REPRODUCED — reset failed: %s\n", $error->getMessage());
        exit(0);
    }

    fwrite(STDERR, "RESULT: FAILED — connection reuse failed: {$error->getMessage()}\n");
    exit(1);
}

$value = null;

if ($server === 'singlestore') {
    try {
        $value = $connection->query('SELECT @example_value')->fetchColumn();
    } catch (PDOException $error) {
        if (!$resetExpected || !str_contains($error->getMessage(), 'Unknown user-defined variable')) {
            fwrite(STDERR, "RESULT: FAILED — value check failed: {$error->getMessage()}\n");
            exit(1);
        }
    }
}

printf("MySQL protocol connection: %s -> %s\n", $beforeId, $afterId);

if (
    !$resetExpected
    && $beforeId === $afterId
    && ($server !== 'singlestore' || $value === 'kept')
) {
    echo "RESULT: OK — PDO used the same connection.\n";
    exit(0);
}

if ($server === 'clickhouse' && $resetExpected && $beforeId !== $afterId) {
    echo "RESULT: PROBLEM REPRODUCED — PDO opened a new connection.\n";
    exit(0);
}

if ($server === 'singlestore' && $resetExpected && $beforeId === $afterId && $value === null) {
    echo "RESULT: OK — reset kept the connection and cleared its value.\n";
    exit(0);
}

fwrite(STDERR, "RESULT: FAILED — unexpected connection behavior.\n");
exit(1);
