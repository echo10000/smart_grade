<?php
/**
 * Database configuration using PHP Data Objects (PDO).
 *
 * This file defines a PDO connection shared by the application. It uses
 * prepared statements for all SQL operations, which helps protect against SQL
 * injection by sending SQL commands and user data separately.
 */

$host = 'localhost';
$db = 'smartgrade_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$db};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

require_once __DIR__ . '/app_helpers.php';
ensure_app_schema($pdo);
