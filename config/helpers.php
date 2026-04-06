<?php
// ============================================================
//  config/helpers.php  — Shared utilities
// ============================================================

require_once __DIR__ . '/database.php';

// ── CORS ─────────────────────────────────────────────────────
function setCors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array($origin, ALLOWED_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        // Allow all origins during local development; tighten in production
        header('Access-Control-Allow-Origin: *');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    header('Content-Type: application/json; charset=UTF-8');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ── JSON Responses ───────────────────────────────────────────
function respond(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(array $data = [], string $message = 'Success'): void
{
    respond(200, ['success' => true, 'message' => $message] + $data);
}

function created(array $data = [], string $message = 'Created'): void
{
    respond(201, ['success' => true, 'message' => $message] + $data);
}

function badRequest(string $message = 'Bad request'): void
{
    respond(400, ['success' => false, 'message' => $message]);
}

function unauthorized(string $message = 'Unauthorized'): void
{
    respond(401, ['success' => false, 'message' => $message]);
}

function notFound(string $message = 'Not found'): void
{
    respond(404, ['success' => false, 'message' => $message]);
}

function serverError(string $message = 'Server error'): void
{
    respond(500, ['success' => false, 'message' => $message]);
}

// ── Request body ─────────────────────────────────────────────
function getBody(): array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        badRequest('Invalid JSON payload.');
    }
    return $data ?? [];
}

// ── Sanitize ─────────────────────────────────────────────────
function sanitize(mixed $value): string
{
    return htmlspecialchars(trim((string) $value), ENT_QUOTES, 'UTF-8');
}

function sanitizeInt(mixed $value, int $default = 0): int
{
    return filter_var($value, FILTER_VALIDATE_INT) !== false
        ? (int) $value
        : $default;
}

// ── Slug generator ───────────────────────────────────────────
function makeSlug(string $title): string
{
    $slug = mb_strtolower($title, 'UTF-8');
    $slug = preg_replace('/[^a-z0-9\s\-]/', '', $slug);
    $slug = preg_replace('/[\s\-]+/', '-', $slug);
    return trim($slug, '-');
}

function uniqueSlug(PDO $db, string $title, ?int $excludeId = null): string
{
    $base = makeSlug($title);
    $slug = $base;
    $i    = 1;

    while (true) {
        $sql  = 'SELECT id FROM posts WHERE slug = ?';
        $args = [$slug];
        if ($excludeId !== null) {
            $sql  .= ' AND id != ?';
            $args[] = $excludeId;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        if (!$stmt->fetch()) break;
        $slug = $base . '-' . $i++;
    }

    return $slug;
}

// ── Auth guard ───────────────────────────────────────────────
function requireAuth(): array
{
    $db = getDB();

    // ── Extract Bearer token ──────────────────────────────────
    // Apache/mod_php often strips the Authorization header.
    // We check all known locations it may appear after rewrite rules.
    $token = '';

    // 1. Standard header (works on nginx and Apache with mod_rewrite fix)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
               ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']  // Apache CGI/FastCGI
               ?? '';

    // 2. Some hosts expose it via getallheaders()
    if (empty($authHeader) && function_exists('getallheaders')) {
        $all = getallheaders();
        // Header names are case-insensitive
        foreach ($all as $name => $value) {
            if (strtolower($name) === 'authorization') {
                $authHeader = $value;
                break;
            }
        }
    }

    if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
        $token = $m[1];
    }

    // 3. Fallback: token in query string (used by verify calls)
    if (empty($token) && !empty($_GET['token'])) {
        $token = $_GET['token'];
    }

    if (empty($token)) {
        unauthorized('No authentication token provided.');
    }

    $stmt = $db->prepare(
        'SELECT * FROM admin_sessions WHERE token = ? AND expires_at > UTC_TIMESTAMP()'
    );
    $stmt->execute([$token]);
    $session = $stmt->fetch();

    if (!$session) {
        unauthorized('Session expired or invalid. Please log in again.');
    }

    return $session;
}

// ── Validation helpers ───────────────────────────────────────
function validatePost(array $data, bool $requireAll = true): array
{
    $errors = [];

    if ($requireAll || isset($data['title'])) {
        if (empty(trim($data['title'] ?? ''))) {
            $errors[] = 'Title is required.';
        } elseif (mb_strlen($data['title']) > 255) {
            $errors[] = 'Title must be 255 characters or fewer.';
        }
    }

    if ($requireAll || isset($data['excerpt'])) {
        if (empty(trim($data['excerpt'] ?? ''))) {
            $errors[] = 'Excerpt is required.';
        }
    }

    if ($requireAll || isset($data['content'])) {
        if (empty(trim($data['content'] ?? ''))) {
            $errors[] = 'Content is required.';
        }
    }

    $validCategories = [
        'Tendering', 'Compliance', 'Strategy',
        'GeM Portal', 'Case Study', 'News & Updates',
    ];
    if (!empty($data['category']) && !in_array($data['category'], $validCategories, true)) {
        $errors[] = 'Invalid category.';
    }

    if (!empty($data['seo_title']) && mb_strlen($data['seo_title']) > 70) {
        $errors[] = 'SEO title must be 70 characters or fewer.';
    }

    if (!empty($data['seo_description']) && mb_strlen($data['seo_description']) > 165) {
        $errors[] = 'SEO description must be 165 characters or fewer.';
    }

    return $errors;
}
