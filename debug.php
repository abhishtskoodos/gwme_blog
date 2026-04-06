<?php
// ============================================================
//  debug.php  — Temporary diagnostic tool
//  Visit: https://yourdomain.com/debug.php
//  DELETE THIS FILE after debugging is done.
// ============================================================

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/config/database.php';

$out = [];

// ── 1. PHP version ───────────────────────────────────────────
$out['php_version'] = PHP_VERSION;
$out['php_sapi']    = PHP_SAPI;  // 'apache2handler', 'fpm-fcgi', 'cgi', etc.

// ── 2. Test localStorage from PHP side (not applicable,
//       but we can test if DB connection works) ──────────────
try {
    $db = getDB();
    $out['db_connection'] = 'OK';

    // Check tables exist
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $out['tables'] = $tables;

    // Check admin_users
    if (in_array('admin_users', $tables)) {
        $users = $db->query("SELECT id, username, is_active, created_at FROM admin_users")->fetchAll();
        $out['admin_users'] = $users;
    } else {
        $out['admin_users'] = 'TABLE MISSING — run migrate.php';
    }

    // Check admin_sessions count
    if (in_array('admin_sessions', $tables)) {
        $count = $db->query("SELECT COUNT(*) FROM admin_sessions")->fetchColumn();
        $recent = $db->query("SELECT token, username, expires_at, created_at FROM admin_sessions ORDER BY created_at DESC LIMIT 5")->fetchAll();
        $out['session_count'] = (int)$count;
        $out['recent_sessions'] = $recent;
    } else {
        $out['admin_sessions'] = 'TABLE MISSING';
    }

} catch (Exception $e) {
    $out['db_connection'] = 'FAILED: ' . $e->getMessage();
}

// ── 3. Authorization header visibility ───────────────────────
$out['headers']['HTTP_AUTHORIZATION']          = $_SERVER['HTTP_AUTHORIZATION']          ?? 'NOT SET';
$out['headers']['REDIRECT_HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? 'NOT SET';
if (function_exists('getallheaders')) {
    $out['headers']['getallheaders'] = getallheaders();
} else {
    $out['headers']['getallheaders'] = 'function not available';
}

// ── 4. Server info ───────────────────────────────────────────
$out['server_software'] = $_SERVER['SERVER_SOFTWARE'] ?? 'unknown';
$out['document_root']   = $_SERVER['DOCUMENT_ROOT']   ?? 'unknown';
$out['script_filename'] = $_SERVER['SCRIPT_FILENAME'] ?? 'unknown';

// ── 5. Test: simulate what happens when we call verify ───────
//    Manually check if any non-expired session exists
try {
    if (isset($db)) {
        $sessions = $db->query(
            "SELECT token, username, expires_at FROM admin_sessions WHERE expires_at > NOW() ORDER BY created_at DESC LIMIT 3"
        )->fetchAll();
        $out['active_sessions'] = $sessions;
        $out['active_session_count'] = count($sessions);
    }
} catch(Exception $e) {
    $out['session_check_error'] = $e->getMessage();
}

// ── 6. Timezone (affects expires_at comparison) ──────────────
$out['php_timezone']  = date_default_timezone_get();
$out['php_time_now']  = date('Y-m-d H:i:s');
$out['mysql_time_now'] = isset($db) ? $db->query("SELECT NOW()")->fetchColumn() : 'N/A';

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
