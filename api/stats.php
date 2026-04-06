<?php
// ============================================================
//  api/stats.php  — Dashboard statistics
//  GET /api/stats.php
// ============================================================

require_once __DIR__ . '/../config/helpers.php';

setCors();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

requireAuth();

$db = getDB();

$total     = (int) $db->query('SELECT COUNT(*) FROM posts')->fetchColumn();
$published = (int) $db->query('SELECT COUNT(*) FROM posts WHERE published = 1')->fetchColumn();
$drafts    = (int) $db->query('SELECT COUNT(*) FROM posts WHERE published = 0')->fetchColumn();
$withSeo   = (int) $db->query(
    "SELECT COUNT(*) FROM posts WHERE seo_title != '' AND seo_keywords != ''"
)->fetchColumn();

// Category breakdown
$catStmt = $db->query(
    'SELECT category, COUNT(*) as count FROM posts GROUP BY category ORDER BY count DESC'
);
$categories = $catStmt->fetchAll();

// Recent 5 posts
$recentStmt = $db->query(
    'SELECT id, title, category, published, seo_title, seo_keywords, publish_date, created_at
     FROM posts ORDER BY created_at DESC LIMIT 5'
);
$recent = $recentStmt->fetchAll();
$recent = array_map(function ($r) {
    $r['published'] = (bool) $r['published'];
    return $r;
}, $recent);

ok([
    'stats' => [
        'total'      => $total,
        'published'  => $published,
        'drafts'     => $drafts,
        'with_seo'   => $withSeo,
    ],
    'categories' => $categories,
    'recent'     => $recent,
]);
