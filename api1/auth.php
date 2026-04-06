<?php
// ============================================================
//  api/auth.php
//  POST /api/auth.php?action=login    — Log in
//  POST /api/auth.php?action=logout   — Log out
//  GET  /api/auth.php?action=verify   — Verify session token
// ============================================================

require_once __DIR__ . '/../config/helpers.php';

setCors();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // ── LOGIN ─────────────────────────────────────────────────
    case 'login':
        if ($method !== 'POST') {
            respond(405, ['success' => false, 'message' => 'Method not allowed.']);
        }

        $db   = getDB();
        $body = getBody();
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Rate-limit: max 5 attempts per 15 minutes per IP
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ?
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)'
        );
        $stmt->execute([$ip, MAX_LOGIN_ATTEMPTS]);
        $attempts = (int) $stmt->fetchColumn();

        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            respond(429, [
                'success'        => false,
                'message'        => 'Too many failed attempts. Try again in ' . LOCKOUT_MINUTES . ' minutes.',
                'locked'         => true,
                'lockout_minutes' => LOCKOUT_MINUTES,
            ]);
        }

        $username = trim($body['username'] ?? '');
        $password = trim($body['password'] ?? '');
        $pin      = trim($body['pin']      ?? '');

        // Validate credentials (constant-time comparison)
        $usernameOk = hash_equals(ADMIN_USERNAME, $username);
        $passwordOk = hash_equals(ADMIN_PASSWORD_HASH, hash('sha256', $password));
        $pinOk      = hash_equals(ADMIN_PIN, $pin);

        if (!$usernameOk || !$passwordOk || !$pinOk) {
            // Record failed attempt
            $db->prepare('INSERT INTO login_attempts (ip_address) VALUES (?)')
               ->execute([$ip]);

            $remaining = MAX_LOGIN_ATTEMPTS - $attempts - 1;
            respond(401, [
                'success'           => false,
                'message'           => 'Incorrect credentials.',
                'attempts_remaining' => max(0, $remaining),
            ]);
        }

        // ── Success: create session ───────────────────────────
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_HOURS * 3600);
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300);

        $db->prepare(
            'INSERT INTO admin_sessions (token, username, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$token, $username, $ip, $userAgent, $expiresAt]);

        // Clean up old sessions for this IP
        $db->prepare('DELETE FROM login_attempts WHERE ip_address = ?')->execute([$ip]);

        ok([
            'token'      => $token,
            'expires_at' => $expiresAt,
            'user'       => $username,
        ], 'Login successful.');
        break;

    // ── LOGOUT ────────────────────────────────────────────────
    case 'logout':
        if ($method !== 'POST') {
            respond(405, ['success' => false, 'message' => 'Method not allowed.']);
        }

        $body  = getBody();
        $token = $body['token'] ?? '';

        if (!empty($token)) {
            $db = getDB();
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
        ok([
            'user'       => $session['username'],
            'expires_at' => $session['expires_at'],
        ], 'Session valid.');
        break;

    default:
        notFound('Auth action not found.');
}
