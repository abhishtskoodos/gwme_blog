<?php
// ============================================================
//  api/auth.php
//
//  POST /api/auth.php?action=login   — Authenticate admin
//  POST /api/auth.php?action=logout  — Invalidate session
//  GET  /api/auth.php?action=verify  — Check session token
// ============================================================

require_once __DIR__ . '/../config/helpers.php';

setCors();

// ── Sync PHP timezone to MySQL so expires_at comparisons work ─
// Your server has PHP=Europe/Berlin, MySQL=UTC+5:30 (IST).
// Telling PHP to use UTC means date() output always matches
// MySQL's NOW(), regardless of the OS timezone setting.
date_default_timezone_set('UTC');

$db     = getDB();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── Self-heal: ensure admin_users table and default admin exist ─
// This runs on every request to auth.php so the app never breaks
// even if migrate.php was never run or ran an old version.
ensureAdminSetup($db);

// ─────────────────────────────────────────────────────────────

switch ($action) {

    // ── LOGIN ─────────────────────────────────────────────────
    case 'login':
        if ($method !== 'POST') {
            respond(405, ['success' => false, 'message' => 'Method not allowed.']);
        }

        $body = getBody();
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Brute-force check
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip_address = ?
                AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $stmt->execute([$ip, LOCKOUT_MINUTES]);
        $recentAttempts = (int) $stmt->fetchColumn();

        if ($recentAttempts >= MAX_LOGIN_ATTEMPTS) {
            respond(429, [
                'success'         => false,
                'message'         => 'Too many failed attempts. Try again in ' . LOCKOUT_MINUTES . ' minutes.',
                'locked'          => true,
                'lockout_minutes' => LOCKOUT_MINUTES,
            ]);
        }

        $username = trim($body['username'] ?? '');
        $password = trim($body['password'] ?? '');
        $pin      = trim($body['pin']      ?? '');

        if ($username === '' || $password === '' || $pin === '') {
            recordAttempt($db, $ip);
            respond(401, [
                'success'            => false,
                'message'            => 'All fields are required.',
                'attempts_remaining' => max(0, MAX_LOGIN_ATTEMPTS - $recentAttempts - 1),
            ]);
        }

        // Look up user
        $row = $db->prepare(
            'SELECT id, username, password_hash, pin_hash, is_active
               FROM admin_users WHERE username = ? LIMIT 1'
        );
        $row->execute([$username]);
        $adminUser = $row->fetch();

        // Always run both verifies (prevents timing-based enumeration)
        $dummy      = '$2y$12$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $pwHash     = $adminUser['password_hash'] ?? $dummy;
        $pinHash    = $adminUser['pin_hash']      ?? $dummy;
        $passwordOk = password_verify($password, $pwHash);
        $pinOk      = password_verify($pin,      $pinHash);
        $valid      = $adminUser && (bool)$adminUser['is_active'] && $passwordOk && $pinOk;

        if (!$valid) {
            recordAttempt($db, $ip);
            respond(401, [
                'success'            => false,
                'message'            => 'Incorrect credentials.',
                'attempts_remaining' => max(0, MAX_LOGIN_ATTEMPTS - $recentAttempts - 1),
            ]);
        }

        // Create session — use UTC timestamp so it matches MySQL NOW()
        $token     = bin2hex(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + SESSION_HOURS * 3600);
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);

        $db->prepare(
            'INSERT INTO admin_sessions (token, username, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$token, $adminUser['username'], $ip, $userAgent, $expiresAt]);

        $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);

        // Auto-rehash if needed
        if (password_needs_rehash($pwHash, PASSWORD_BCRYPT, ['cost' => 12])) {
            $db->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
               ->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $adminUser['id']]);
        }

        ok(['token' => $token, 'expires_at' => $expiresAt, 'user' => $adminUser['username']], 'Login successful.');
        break;

    // ── LOGOUT ────────────────────────────────────────────────
    case 'logout':
        if ($method !== 'POST') {
            respond(405, ['success' => false, 'message' => 'Method not allowed.']);
        }
        $body  = getBody();
        $token = trim($body['token'] ?? '');
        if ($token !== '') {
            $db->prepare('DELETE FROM admin_sessions WHERE token = ?')->execute([$token]);
        }
        ok([], 'Logged out successfully.');
        break;

    // ── VERIFY ────────────────────────────────────────────────
    case 'verify':
        if ($method !== 'GET') {
            respond(405, ['success' => false, 'message' => 'Method not allowed.']);
        }
        $session = requireAuth();
        ok(['user' => $session['username'], 'expires_at' => $session['expires_at']], 'Session valid.');
        break;

    default:
        notFound('Auth action not found.');
}

// ─────────────────────────────────────────────────────────────

function recordAttempt(PDO $db, string $ip): void
{
    $db->prepare('INSERT INTO login_attempts (ip_address) VALUES (?)')->execute([$ip]);
}

// ── Self-healing setup ────────────────────────────────────────
// Creates admin_users table and seeds the default admin user
// if either is missing. Safe to call on every request.
function ensureAdminSetup(PDO $db): void
{
    // 1. Create admin_users table if it does not exist
    $db->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
          id            INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
          username      VARCHAR(64)   NOT NULL UNIQUE,
          password_hash VARCHAR(255)  NOT NULL,
          pin_hash      VARCHAR(255)  NOT NULL,
          is_active     TINYINT(1)    NOT NULL DEFAULT 1,
          created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // 2. Seed default admin if no users exist yet
    $count = (int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    if ($count === 0) {
        $db->prepare(
            'INSERT INTO admin_users (username, password_hash, pin_hash) VALUES (?, ?, ?)'
        )->execute([
            ADMIN_USERNAME,
            password_hash(ADMIN_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]),
            password_hash(ADMIN_PIN,      PASSWORD_BCRYPT, ['cost' => 12]),
        ]);
    }
}
