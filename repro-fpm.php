<?php

declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'database';
$database = getenv('DB_NAME') ?: 'repro';
$user = getenv('DB_USER') ?: 'repro';
$password = getenv('DB_PASSWORD') ?: 'repro';
$version = getenv('PHP_VERSION') ?: '8.6.0alpha3';
$dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";

$connection = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_PERSISTENT => true,
    Pdo\Mysql::ATTR_INIT_COMMAND => "SET time_zone = '+02:00'",
]);

$state = $connection->query(
    'SELECT CONNECTION_ID() AS id, '
    . '@@character_set_client AS client_encoding, '
    . '@@session.time_zone AS time_zone, '
    . '@fpm_marker AS marker',
)->fetch(PDO::FETCH_ASSOC);

$temporaryTableBefore = $connection->query("SHOW TABLES LIKE 'fpm_reset_repro'")->fetchColumn() !== false;
$connection->exec('CREATE TEMPORARY TABLE fpm_reset_repro (id INT)');
$connection->exec("SET @fpm_marker = 'set by the previous request'");

register_shutdown_function(static function () use (&$connection): void {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }

    $connection->exec('DROP TEMPORARY TABLE IF EXISTS fpm_reset_repro');
    $connection = null;
});

header('Content-Type: application/json');
echo json_encode([
    'php' => PHP_VERSION,
    'build' => $version,
    'connection_id' => $state['id'],
    'client_encoding' => $state['client_encoding'],
    'time_zone' => $state['time_zone'],
    'marker_before' => $state['marker'],
    'temporary_table' => $temporaryTableBefore,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
