<?php

declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'database';
$port = (int) (getenv('DB_PORT') ?: 9004);
$database = getenv('DB_NAME') ?: 'default';
$user = getenv('DB_USER') ?: 'default';
$password = getenv('DB_PASSWORD') ?: '';
$version = getenv('PHP_VERSION') ?: '8.6.0alpha3';
$resetExpected = $version === 'feat-mysqlnd-com-reset-connection';
$dsn = "mysql:host={$host};port={$port};dbname={$database}";

$connect = static fn (): PDO => new PDO(
    $dsn,
    $user,
    $password,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_PERSISTENT => 'clickhouse-reset',
    ],
);

$connectionId = static function (PDO $connection): array {
    $queryId = (string) $connection
        ->query('SELECT currentQueryID()')
        ->fetchColumn();

    if (!preg_match('/^mysql:(\d+):/', $queryId, $matches)) {
        throw new RuntimeException(
            "Unexpected ClickHouse query ID: {$queryId}",
        );
    }

    return [(int) $matches[1], $queryId];
};

printf(
    "PHP: %s; build: %s; reset expected: %s\n",
    PHP_VERSION,
    $version,
    $resetExpected ? 'yes' : 'no',
);

try {
    $connection = $connect();
    [$beforeId, $beforeQuery] = $connectionId($connection);
} catch (Throwable $error) {
    fwrite(
        STDERR,
        "RESULT: FAILED — first connection failed: {$error->getMessage()}\n",
    );
    exit(1);
}

unset($connection);
gc_collect_cycles();

try {
    $connection = $connect();
    [$afterId, $afterQuery] = $connectionId($connection);
} catch (Throwable $error) {
    if ($resetExpected) {
        printf(
            "RESULT: PROBLEM REPRODUCED — reset failed: %s\n",
            $error->getMessage(),
        );
        exit(0);
    }

    fwrite(
        STDERR,
        "RESULT: FAILED — connection reuse failed: {$error->getMessage()}\n",
    );
    exit(1);
}

printf(
    "ClickHouse query IDs:\n  %s\n  %s\n",
    $beforeQuery,
    $afterQuery,
);
printf(
    "MySQL protocol connection: %d -> %d\n",
    $beforeId,
    $afterId,
);

if (!$resetExpected && $beforeId === $afterId) {
    echo "RESULT: OK — PDO used the same connection.\n";
    exit(0);
}

if ($resetExpected && $beforeId !== $afterId) {
    echo "RESULT: PROBLEM REPRODUCED — PDO opened a new connection.\n";
    exit(0);
}

fwrite(STDERR, "RESULT: FAILED — unexpected connection behavior.\n");
exit(1);
