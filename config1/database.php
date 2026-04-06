<?php
// ============================================================
//  config/database.php  — DB connection (PDO)
// ============================================================

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'gwme_blog');
define('DB_USER', 'root');          // ← change to your MySQL username
define('DB_PASS', '');              // ← change to your MySQL password
define('DB_CHARSET', 'utf8mb4');

// ── Admin credentials (SHA-256 hashed) ──────────────────────
// To generate a new hash run in terminal:
//   php -r "echo hash('sha256', 'YourPassword123!');"
define('ADMIN_USERNAME', 'gwme_admin');
define('ADMIN_PASSWORD_HASH', hash('sha256', 'GwmeIndia@2025!'));  // change password
define('ADMIN_PIN',  '7319');       // ← change this PIN
define('SESSION_HOURS', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES', 15);

// ── Allowed origins for CORS ─────────────────────────────────
define('ALLOWED_ORIGINS', [
    'http://localhost',
    'http://127.0.0.1',
    'https://gwmeindia.com',         // ← add your live domain
    'https://www.gwmeindia.com',
]);

/**
 * Return a PDO connection (singleton).
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
            die(json_encode([
                'success' => false,
                'message' => 'Database connection failed.',
                // Remove 'debug' line in production:
                'debug'   => $e->getMessage(),
            ]));
        }
    }

    return $pdo;
}
