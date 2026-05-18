<?php
declare(strict_types=1);

/*
 * Shared database connection for all UGNAYAN modules.
 * Update these values if your local MySQL setup uses a different account.
 */
$dbHost = getenv('UGNAYAN_DB_HOST') ?: 'localhost';
$dbName = getenv('UGNAYAN_DB_NAME') ?: 'ugnayan_db';
$dbUser = getenv('UGNAYAN_DB_USER') ?: 'root';
$dbPass = getenv('UGNAYAN_DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Please import database/ugnayan_database_modules_1_to_10.sql and check config/database.php.');
}

function log_action(PDO $pdo, ?int $userId, string $action, ?string $description = null): void
{
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)');
    $stmt->execute([$userId, $action, $description]);
}
