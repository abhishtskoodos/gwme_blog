<?php
// ============================================================
//  config/database.php  — Database connection + admin config
// ============================================================

define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'gwme_india');
define('DB_USER',    'root');       // ← change to your MySQL username
define('DB_PASS',    'Gwme@2025India');           // ← change to your MySQL password
define('DB_CHARSET', 'utf8mb4');

// ── Admin credentials ────────────────────────────────────────
// auth.php auto-creates the admin_users table and seeds this
// admin on the very first login attempt. Change before deploying.
define('ADMIN_USERNAME', 'gwme_admin');
define('ADMIN_PASSWORD', 'GwmeIndia@2025!');  // ← change this
define('ADMIN_PIN',      '7319');              // ← change this (4 digits)

// Session / security settings
define('SESSION_HOURS',      8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES',   15);

// Allowed CORS origins
define('ALLOWED_ORIGINS', [
    'http://localhost',
    'http://127.0.0.1',
    'https://gwmeindia.com',
    'https://www.gwmeindia.com',
]);

/**
 * Return a singleton PDO connection.
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed.',
                'debug'   => $e->getMessage(),
            ]);
            exit;
        }
    }

    return $pdo;
}
