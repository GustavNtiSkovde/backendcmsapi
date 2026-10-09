<?php
// db.php sets the JSON header and mysqli_report, and creates $conn
require_once 'db.php';

const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

//=================
// Helper Funcs
//=================

/** Send a JSON response and stop. */
function respond(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_FLAGS);
    exit;
}

function slugify(string $s): string {
    $s = strtr($s, ['å'=>'a','ä'=>'a','ö'=>'o','Å'=>'A','Ä'=>'A','Ö'=>'O']); //Convert to slugable
    return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $s), '-')) ?: 'page'; //Replaces special characters
}

// Run a SELECT with bound parameters and return the first row, or null.
function fetchRow(mysqli $conn, string $sql, string $types, array $params): ?array {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}
//Language
function getOrCreateLang(mysqli $conn, string $name): int {
    $row = fetchRow($conn, "SELECT ID FROM lang WHERE lang = ?", "s", [$name]);
    if ($row) return (int)$row['ID'];

    $stmt = $conn->prepare("INSERT INTO lang (lang) VALUES (?)");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

//Category
function getOrCreateCategory(mysqli $conn, string $name): int { 
    $row = fetchRow($conn, "SELECT ID FROM category WHERE category = ?", "s", [$name]);
    if ($row) return (int)$row['ID'];

    $slug = slugify($name);
    $stmt = $conn->prepare("INSERT INTO category (category, slug) VALUES (?, ?)");
    $stmt->bind_param("ss", $name, $slug);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

// Insert or update the translation of $pageId in $langName.
function saveTranslation(mysqli $conn, int $pageId, string $langName, ?string $title, ?string $content): void {
    $langId   = getOrCreateLang($conn, $langName);
    $existing = fetchRow($conn, "SELECT ID, title, content FROM pagelang WHERE FORpage = ? AND FORlang = ?", "ii", [$pageId, $langId]);

    if ($existing) {
        $newTitle = ($title !== null && $title !== '') ? $title : $existing['title'];
        $json     = $content !== null ? json_encode(['text' => $content], JSON_UNESCAPED_UNICODE) : $existing['content'];
        $slug     = slugify($newTitle);

        $stmt = $conn->prepare("UPDATE pagelang SET title = ?, slug = ?, content = ? WHERE ID = ?");
        $stmt->bind_param("sssi", $newTitle, $slug, $json, $existing['ID']);
    } else {
        if ($title === null || $title === '') {
            throw new Exception("Title is required for a new translation ($langName).");
        }
        $slug = slugify($title);
        $json = json_encode(['text' => $content ?? ''], JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare("INSERT INTO pagelang (FORlang, FORpage, title, slug, content) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $langId, $pageId, $title, $slug, $json);
    }
    $stmt->execute();
    $stmt->close();
}

// Returns pages, each with a `translations` array.
function fetchPages(mysqli $conn, array $conditions, string $types, array $params): array {
    $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
    $sql = "
        SELECT p.ID AS page_id, p.created_at,
                pl.title, pl.slug, pl.content, l.lang,
                c.category,
                m.file_path, m.file_name, m.file_type, m.alt_text
        FROM page p
        LEFT JOIN pagelang pl ON p.ID = pl.FORpage
        LEFT JOIN lang l ON pl.FORlang = l.ID
        LEFT JOIN pagecategory pc ON p.ID = pc.FORpage
        LEFT JOIN category c ON pc.FORcategory = c.ID
        LEFT JOIN pagemedia pm ON p.ID = pm.FORpage
        LEFT JOIN media m ON pm.FORmedia = m.ID
        $where
        ORDER BY p.ID DESC, l.ID ASC
    ";

    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $pages = [];
    foreach ($rows as $r) {
        $id = $r['page_id'];

        if (!isset($pages[$id])) {
            $pages[$id] = [
                'page_id'      => $id,
                'created_at'   => $r['created_at'],
                'category'     => $r['category'],
                'media'        => $r['file_path'] ? [
                    'file_path' => $r['file_path'],
                    'file_name' => $r['file_name'],
                    'file_type' => $r['file_type'],
                    'alt_text'  => $r['alt_text'],
                ] : null,
                'translations' => [],
            ];
        }

        if ($r['lang'] !== null && !isset($pages[$id]['translations'][$r['lang']])) {
            $decoded = json_decode($r['content'] ?? '', true);
            $pages[$id]['translations'][$r['lang']] = [
                'lang'    => $r['lang'],
                'title'   => $r['title'],
                'slug'    => $r['slug'],
                'content' => (json_last_error() === JSON_ERROR_NONE) ? $decoded : $r['content'],
            ];
        }
    }

    foreach ($pages as &$p) {
        $p['translations'] = array_values($p['translations']);
    }
    unset($p); // break the reference left by foreach

    return array_values($pages);
}

// ==========================================
// GET 
// ==========================================
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $pageId   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;
        $title    = !empty($_GET['title'])    ? $_GET['title']    : null;
        $slug     = !empty($_GET['slug'])     ? $_GET['slug']     : null;
        $category = !empty($_GET['category']) ? $_GET['category'] : null;
        $lang     = !empty($_GET['lang'])     ? $_GET['lang']     : null;

        // Accept short codes for languages
        $codes = ['sv' => 'Svenska', 'en' => 'Engelska'];
        if ($lang !== null) $lang = $codes[strtolower($lang)] ?? $lang;

        $conditions = [];
        $params     = [];
        $types      = "";

        // Single page lookup (priority: id, slug, title)
        if ($pageId !== null) {
            $conditions[] = "p.ID = ?";
            $params[] = $pageId;
            $types   .= "i";
        } elseif ($slug !== null) {
            $conditions[] = "p.ID IN (SELECT FORpage FROM pagelang WHERE slug = ?)";
            $params[] = $slug;
            $types   .= "s";
        } elseif ($title !== null) {
            $conditions[] = "p.ID IN (SELECT FORpage FROM pagelang WHERE title = ?)";
            $params[] = $title;
            $types   .= "s";
        }

        // Filters: a number matches the ID, anything else matches the name
        if ($lang !== null) {
            $conditions[] = is_numeric($lang) ? "l.ID = ?" : "l.lang = ?";
            $params[] = is_numeric($lang) ? (int)$lang : $lang;
            $types   .= is_numeric($lang) ? "i" : "s";
        }
        if ($category !== null) {
            $conditions[] = is_numeric($category) ? "c.ID = ?" : "c.category = ?";
            $params[] = is_numeric($category) ? (int)$category : $category;
            $types   .= is_numeric($category) ? "i" : "s";
        }

        $pages  = fetchPages($conn, $conditions, $types, $params);
        $single = $pageId !== null || $slug !== null || $title !== null;

        if (!$single) respond(200, ['success' => true, 'data' => $pages]);
        if ($pages)   respond(200, ['success' => true, 'data' => $pages[0]]);
        respond(404, ['success' => false, 'message' => 'No matching record found.']);

    } catch (Exception $e) {
        respond(500, ['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

// ==========================================
// POST: CREATE / UPDATE / DELETE
// ==========================================
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) $data = [];
    $action = $data['action'] ?? 'CREATE';

    try {
        // Create
        if ($action === 'CREATE') {
            if (empty($data['category']) || empty($data['translations']) || !is_array($data['translations'])) {
                respond(400, ['success' => false, 'message' => 'Category and at least one translation are required.']);
            }

            $conn->begin_transaction();

            $stmt = $conn->prepare("INSERT INTO page (created_at) VALUES (NOW())");
            $stmt->execute();
            $pageId = $conn->insert_id;
            $stmt->close();

            $saved = 0;
            foreach ($data['translations'] as $t) {
                $langName = trim($t['lang'] ?? '');
                $title    = trim($t['title'] ?? '');
                if ($langName === '' || $title === '') continue; // skip incomplete translations

                saveTranslation($conn, $pageId, $langName, $title, $t['content'] ?? '');
                $saved++;
            }
            if ($saved === 0) {
                throw new Exception("At least one translation needs a language and title.");
            }

            $catId = getOrCreateCategory($conn, $data['category']);
            $stmt = $conn->prepare("INSERT INTO pagecategory (FORcategory, FORpage) VALUES (?, ?)");
            $stmt->bind_param("ii", $catId, $pageId);
            $stmt->execute();
            $stmt->close();

            // Optional image
            if (!empty($data['imgName'])) {
                $imgName   = $data['imgName'];
                $imgAlt    = $data['imgAlt'] ?? '';
                $filePath  = 'uploads/' . $imgName;
                $ext       = strtolower(pathinfo($imgName, PATHINFO_EXTENSION));
                $mimeTypes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
                $fileType  = $mimeTypes[$ext] ?? 'image/jpeg';

                $stmt = $conn->prepare("INSERT INTO media (file_path, file_name, file_type, alt_text, created_at) VALUES (?, ?, ?, ?, NOW())");
                $stmt->bind_param("ssss", $filePath, $imgName, $fileType, $imgAlt);
                $stmt->execute();
                $mediaId = $conn->insert_id;
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO pagemedia (FORmedia, FORpage) VALUES (?, ?)");
                $stmt->bind_param("ii", $mediaId, $pageId);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            respond(200, ['success' => true, 'message' => 'Page created with translations!', 'page_id' => $pageId]);
        }

        // Edit
        if ($action === 'UPDATE') {
            if (empty($data['page_id']) || empty($data['lang'])) {
                respond(400, ['success' => false, 'message' => 'page_id and lang are required.']);
            }
            $pageId = (int)$data['page_id'];

            if (!fetchRow($conn, "SELECT ID FROM page WHERE ID = ?", "i", [$pageId])) {
                respond(404, ['success' => false, 'message' => 'Page not found.']);
            }

            saveTranslation(
                $conn, $pageId, trim($data['lang']),
                isset($data['title'])   ? trim($data['title']) : null,
                isset($data['content']) ? (string)$data['content'] : null
            );
            respond(200, ['success' => true, 'message' => 'Translation saved!']);
        }

        // Delete with cascade
        if ($action === 'DELETE') {
            if (empty($data['page_id'])) {
                respond(400, ['success' => false, 'message' => 'Page ID is required.']);
            }
            $pageId = (int)$data['page_id'];

            $stmt = $conn->prepare("DELETE FROM page WHERE ID = ?");
            $stmt->bind_param("i", $pageId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected === 0) {
                respond(404, ['success' => false, 'message' => 'Page not found.']);
            }
            respond(200, ['success' => true, 'message' => 'Site deleted successfully!']);
        }

        respond(400, ['success' => false, 'message' => 'Unknown action.']);

    } catch (Exception $e) {
        try { $conn->rollback(); } catch (Exception $ignore) {}
        respond(500, ['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}