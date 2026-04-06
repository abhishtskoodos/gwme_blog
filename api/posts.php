<?php
// ============================================================
//  api/posts.php  — RESTful Posts API
//
//  PUBLIC  (no auth needed):
//    GET  /api/posts.php              — list published posts
//    GET  /api/posts.php?id=5         — single published post
//    GET  /api/posts.php?slug=my-slug — single published post by slug
//
//  ADMIN  (Bearer token required):
//    GET    /api/posts.php?admin=1         — list ALL posts (inc. drafts)
//    GET    /api/posts.php?admin=1&id=5    — any post by id
//    POST   /api/posts.php                 — create post
//    PUT    /api/posts.php?id=5            — full update
//    PATCH  /api/posts.php?id=5&action=toggle  — toggle published
//    DELETE /api/posts.php?id=5            — delete
// ============================================================

require_once __DIR__ . '/../config/helpers.php';

setCors();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id'])   ? sanitizeInt($_GET['id'])   : null;
$slug   = isset($_GET['slug']) ? sanitize($_GET['slug'])    : null;
$admin  = !empty($_GET['admin']);
$action = $_GET['action'] ?? '';

// ── Helper: format a post row for the API response ───────────
function formatPost(array $row): array
{
    return [
        'id'              => (int) $row['id'],
        'title'           => $row['title'],
        'slug'            => $row['slug'],
        'excerpt'         => $row['excerpt'],
        'content'         => $row['content'],
        'author'          => $row['author'],
        'category'        => $row['category'],
        'emoji'           => $row['emoji'],
        'card_color'      => $row['card_color'],
        'published'       => (bool) $row['published'],
        'read_time'       => (int) $row['read_time'],
        'seo_title'       => $row['seo_title'],
        'seo_description' => $row['seo_description'],
        'seo_keywords'    => $row['seo_keywords'],
        'seo_canonical'   => $row['seo_canonical'],
        'og_title'        => $row['og_title'],
        'og_description'  => $row['og_description'],
        'og_image'        => $row['og_image'],
        'schema_enabled'  => (bool) $row['schema_enabled'],
        'faq_schema'      => (bool) $row['faq_schema'],
        'publish_date'    => $row['publish_date'],
        'created_at'      => $row['created_at'],
        'updated_at'      => $row['updated_at'],
    ];
}

// ── Helper: build INSERT/UPDATE fields from body ─────────────
function buildFields(array $body, PDO $db, ?int $excludeId = null): array
{
    $fields = [];
    $params = [];

    $map = [
        'title', 'excerpt', 'content', 'author', 'category',
        'emoji', 'card_color', 'read_time', 'publish_date',
        'seo_title', 'seo_description', 'seo_keywords', 'seo_canonical',
        'og_title', 'og_description', 'og_image',
    ];

    foreach ($map as $key) {
        if (array_key_exists($key, $body)) {
            $fields[] = "`$key` = ?";
            $params[] = is_string($body[$key]) ? trim($body[$key]) : $body[$key];
        }
    }

    // Boolean fields
    foreach (['published', 'schema_enabled', 'faq_schema'] as $bool) {
        if (array_key_exists($bool, $body)) {
            $fields[] = "`$bool` = ?";
            $params[] = $body[$bool] ? 1 : 0;
        }
    }

    // Auto-generate slug when title changes
    if (array_key_exists('title', $body)) {
        $slug     = uniqueSlug($db, trim($body['title']), $excludeId);
        $fields[] = '`slug` = ?';
        $params[] = $slug;
    }

    return ['fields' => $fields, 'params' => $params];
}


// ════════════════════════════════════════════════════════════
//  GET — Read
// ════════════════════════════════════════════════════════════
if ($method === 'GET') {

    // Admin: require auth for drafts
    if ($admin) {
        requireAuth();
    }

    // ── Single post by id ────────────────────────────────────
    if ($id !== null) {
        $whereExtra = $admin ? '' : 'AND published = 1';
        $stmt = $db->prepare(
            "SELECT * FROM posts WHERE id = ? $whereExtra LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) notFound('Post not found.');
        ok(['post' => formatPost($row)]);
    }

    // ── Single post by slug (public only) ───────────────────
    if ($slug !== null) {
        $stmt = $db->prepare(
            'SELECT * FROM posts WHERE slug = ? AND published = 1 LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if (!$row) notFound('Post not found.');
        ok(['post' => formatPost($row)]);
    }

    // ── List posts ───────────────────────────────────────────
    $category  = $_GET['category']  ?? '';
    $search    = $_GET['search']    ?? '';
    $page      = max(1, sanitizeInt($_GET['page'] ?? 1, 1));
    $perPage   = min(50, max(1, sanitizeInt($_GET['per_page'] ?? 20, 20)));
    $offset    = ($page - 1) * $perPage;

    $where  = [];
    $params = [];

    if (!$admin) {
        $where[] = 'published = 1';
    }
    if (!empty($category)) {
        $where[] = 'category = ?';
        $params[] = $category;
    }
    if (!empty($search)) {
        $where[] = '(title LIKE ? OR excerpt LIKE ? OR seo_keywords LIKE ?)';
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like]);
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Total count
    $countStmt = $db->prepare("SELECT COUNT(*) FROM posts $whereSQL");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Rows
    $listStmt = $db->prepare(
        "SELECT * FROM posts $whereSQL
         ORDER BY publish_date DESC, created_at DESC
         LIMIT ? OFFSET ?"
    );
    $listStmt->execute(array_merge($params, [$perPage, $offset]));
    $rows = $listStmt->fetchAll();

    ok([
        'posts'      => array_map('formatPost', $rows),
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'total_pages' => (int) ceil($total / $perPage),
    ]);
}


// ════════════════════════════════════════════════════════════
//  POST — Create
// ════════════════════════════════════════════════════════════
if ($method === 'POST') {
    requireAuth();
    $body   = getBody();
    $errors = validatePost($body, true);
    if ($errors) badRequest(implode(' ', $errors));

    ['fields' => $fields, 'params' => $params] = buildFields($body, $db);

    $sql  = 'INSERT INTO posts (' . implode(', ', array_map(fn($f) => explode(' =', $f)[0], $fields)) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')';

    // Cleaner approach: explicit INSERT
    $slug = uniqueSlug($db, trim($body['title']));
    $stmt = $db->prepare(
        'INSERT INTO posts
         (title, slug, excerpt, content, author, category, emoji, card_color,
          published, read_time, seo_title, seo_description, seo_keywords,
          seo_canonical, og_title, og_description, og_image,
          schema_enabled, faq_schema, publish_date)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        trim($body['title']),
        $slug,
        trim($body['excerpt']),
        trim($body['content']),
        trim($body['author']          ?? 'Dr. Siya Seth'),
        $body['category']             ?? 'Tendering',
        $body['emoji']                ?? '📄',
        $body['card_color']           ?? '#0A1628',
        empty($body['published'])     ? 0 : 1,
        sanitizeInt($body['read_time'] ?? 5, 5),
        trim($body['seo_title']       ?? ''),
        trim($body['seo_description'] ?? ''),
        trim($body['seo_keywords']    ?? ''),
        trim($body['seo_canonical']   ?? ''),
        trim($body['og_title']        ?? ''),
        trim($body['og_description']  ?? ''),
        trim($body['og_image']        ?? ''),
        empty($body['schema_enabled']) ? 1 : (int) $body['schema_enabled'],
        empty($body['faq_schema'])    ? 0 : 1,
        !empty($body['publish_date']) ? $body['publish_date'] : date('Y-m-d'),
    ]);

    $newId = (int) $db->lastInsertId();
    $newPost = $db->prepare('SELECT * FROM posts WHERE id = ?');
    $newPost->execute([$newId]);
    $row = $newPost->fetch();

    created(['post' => formatPost($row)], 'Post created successfully.');
}


// ════════════════════════════════════════════════════════════
//  PUT — Full update
// ════════════════════════════════════════════════════════════
if ($method === 'PUT') {
    requireAuth();
    if (!$id) badRequest('Post ID is required.');

    // Check exists
    $check = $db->prepare('SELECT id FROM posts WHERE id = ?');
    $check->execute([$id]);
    if (!$check->fetch()) notFound('Post not found.');

    $body   = getBody();
    $errors = validatePost($body, true);
    if ($errors) badRequest(implode(' ', $errors));

    $slug = uniqueSlug($db, trim($body['title']), $id);
    $stmt = $db->prepare(
        'UPDATE posts SET
           title=?, slug=?, excerpt=?, content=?, author=?, category=?,
           emoji=?, card_color=?, published=?, read_time=?,
           seo_title=?, seo_description=?, seo_keywords=?, seo_canonical=?,
           og_title=?, og_description=?, og_image=?,
           schema_enabled=?, faq_schema=?, publish_date=?
         WHERE id=?'
    );
    $stmt->execute([
        trim($body['title']),
        $slug,
        trim($body['excerpt']),
        trim($body['content']),
        trim($body['author']          ?? 'Dr. Siya Seth'),
        $body['category']             ?? 'Tendering',
        $body['emoji']                ?? '📄',
        $body['card_color']           ?? '#0A1628',
        empty($body['published'])     ? 0 : 1,
        sanitizeInt($body['read_time'] ?? 5, 5),
        trim($body['seo_title']       ?? ''),
        trim($body['seo_description'] ?? ''),
        trim($body['seo_keywords']    ?? ''),
        trim($body['seo_canonical']   ?? ''),
        trim($body['og_title']        ?? ''),
        trim($body['og_description']  ?? ''),
        trim($body['og_image']        ?? ''),
        empty($body['schema_enabled']) ? 1 : (int) $body['schema_enabled'],
        empty($body['faq_schema'])    ? 0 : 1,
        !empty($body['publish_date']) ? $body['publish_date'] : date('Y-m-d'),
        $id,
    ]);

    $updated = $db->prepare('SELECT * FROM posts WHERE id = ?');
    $updated->execute([$id]);
    ok(['post' => formatPost($updated->fetch())], 'Post updated successfully.');
}


// ════════════════════════════════════════════════════════════
//  PATCH — Partial update / toggle
// ════════════════════════════════════════════════════════════
if ($method === 'PATCH') {
    requireAuth();
    if (!$id) badRequest('Post ID is required.');

    $check = $db->prepare('SELECT * FROM posts WHERE id = ?');
    $check->execute([$id]);
    $existing = $check->fetch();
    if (!$existing) notFound('Post not found.');

    // Toggle published status
    if ($action === 'toggle') {
        $newStatus = $existing['published'] ? 0 : 1;
        $db->prepare('UPDATE posts SET published = ? WHERE id = ?')
           ->execute([$newStatus, $id]);
        ok([
            'published' => (bool) $newStatus,
        ], $newStatus ? 'Post published.' : 'Post unpublished.');
    }

    // Generic partial update
    $body = getBody();
    if (empty($body)) badRequest('No fields to update.');

    $errors = validatePost($body, false);
    if ($errors) badRequest(implode(' ', $errors));

    ['fields' => $fields, 'params' => $params] = buildFields($body, $db, $id);

    if (empty($fields)) badRequest('No valid fields to update.');

    $params[] = $id;
    $db->prepare('UPDATE posts SET ' . implode(', ', $fields) . ' WHERE id = ?')
       ->execute($params);

    $updated = $db->prepare('SELECT * FROM posts WHERE id = ?');
    $updated->execute([$id]);
    ok(['post' => formatPost($updated->fetch())], 'Post updated.');
}


// ════════════════════════════════════════════════════════════
//  DELETE
// ════════════════════════════════════════════════════════════
if ($method === 'DELETE') {
    requireAuth();
    if (!$id) badRequest('Post ID is required.');

    $check = $db->prepare('SELECT id FROM posts WHERE id = ?');
    $check->execute([$id]);
    if (!$check->fetch()) notFound('Post not found.');

    $db->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
    ok(['deleted_id' => $id], 'Post deleted successfully.');
}

// ── Fallback ─────────────────────────────────────────────────
respond(405, ['success' => false, 'message' => 'Method not allowed.']);
