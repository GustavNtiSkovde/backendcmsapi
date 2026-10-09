<?php
header('Content-Type: application/json');
require_once 'db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$method = $_SERVER['REQUEST_METHOD'];
const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

// ---------- Helpers ----------
function slugify(string $s): string {
    $s = strtr($s, ['å'=>'a','ä'=>'a','ö'=>'o','Å'=>'A','Ä'=>'A','Ö'=>'O']);
    return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $s), '-')) ?: 'page';
}

function getOrCreateLang(mysqli $conn, string $name): int {
    $stmt = $conn->prepare("SELECT ID FROM lang WHERE lang = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['ID'];

    $stmt = $conn->prepare("INSERT INTO lang (lang) VALUES (?)");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

function getOrCreateCategory(mysqli $conn, string $name): int {
    $stmt = $conn->prepare("SELECT ID FROM category WHERE category = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return (int)$row['ID'];

    $slug = slugify($name);
    $stmt = $conn->prepare("INSERT INTO category (category, slug) VALUES (?, ?)");
    $stmt->bind_param("ss", $name, $slug);
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

/** Insert or update the translation of $pageId in $langName. */
function saveTranslation(mysqli $conn, int $pageId, string $langName, ?string $title, ?string $content): void {
    $langId = getOrCreateLang($conn, $langName);

    $stmt = $conn->prepare("SELECT ID, title, content FROM pagelang WHERE FORpage = ? AND FORlang = ?");
    $stmt->bind_param("ii", $pageId, $langId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $newTitle = ($title !== null && $title !== '') ? $title : $existing['title'];
        if ($content !== null) {
            $json = json_encode(['text' => $content], JSON_UNESCAPED_UNICODE);
        } else {
            $json = $existing['content'];
        }
        $slug = slugify($newTitle);
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

/** Returns pages, each with a `translations` array. */
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
                    'file_path' => $r['file_path'], 'file_name' => $r['file_name'],
                    'file_type' => $r['file_type'], 'alt_text'  => $r['alt_text'],
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
    foreach ($pages as &$p) $p['translations'] = array_values($p['translations']);
    return array_values($pages);
}

// ==========================================
// GET
// ==========================================
if ($method === 'GET') {
    try {
        $pageId   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;
        $title    = !empty($_GET['title'])    ? $_GET['title']    : null;
        $slug     = !empty($_GET['slug'])     ? $_GET['slug']     : null;
        $category = !empty($_GET['category']) ? $_GET['category'] : null;
        $lang     = !empty($_GET['lang'])     ? $_GET['lang']     : null;

        $codes = ['sv' => 'Svenska', 'en' => 'Engelska'];
        if ($lang !== null && isset($codes[strtolower($lang)])) $lang = $codes[strtolower($lang)];

        $conditions = []; $params = []; $types = "";

        if ($pageId !== null) {
            $conditions[] = "p.ID = ?"; $params[] = $pageId; $types .= "i";
        } elseif ($slug !== null) {
            $conditions[] = "p.ID IN (SELECT FORpage FROM pagelang WHERE slug = ?)"; $params[] = $slug; $types .= "s";
        } elseif ($title !== null) {
            $conditions[] = "p.ID IN (SELECT FORpage FROM pagelang WHERE title = ?)"; $params[] = $title; $types .= "s";
        }

        if ($lang !== null) {
            if (is_numeric($lang)) { $conditions[] = "l.ID = ?";   $params[] = (int)$lang; $types .= "i"; }
            else                   { $conditions[] = "l.lang = ?"; $params[] = $lang;      $types .= "s"; }
        }
        if ($category !== null) {
            if (is_numeric($category)) { $conditions[] = "c.ID = ?";       $params[] = (int)$category; $types .= "i"; }
            else                       { $conditions[] = "c.category = ?"; $params[] = $category;      $types .= "s"; }
        }

        $pages = fetchPages($conn, $conditions, $types, $params);

        if ($pageId !== null || $slug !== null || $title !== null) {
            if ($pages) {
                echo json_encode(['success' => true, 'data' => $pages[0]], JSON_FLAGS);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'No matching record found.']);
            }
        } else {
            echo json_encode(['success' => true, 'data' => $pages], JSON_FLAGS);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// POST: CREATE / UPDATE / DELETE
// ==========================================
if ($method === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? 'CREATE';

    try {
        // ---------- CREATE ----------
        if ($action === 'CREATE') {
            if (empty($data['category']) || empty($data['translations']) || !is_array($data['translations'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Category and at least one translation are required.']);
                exit;
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
                if ($langName === '' || $title === '') continue;
                saveTranslation($conn, $pageId, $langName, $title, $t['content'] ?? '');
                $saved++;
            }
            if ($saved === 0) throw new Exception("At least one translation needs a language and title.");

            $catId = getOrCreateCategory($conn, $data['category']);
            $stmt = $conn->prepare("INSERT INTO pagecategory (FORcategory, FORpage) VALUES (?, ?)");
            $stmt->bind_param("ii", $catId, $pageId);
            $stmt->execute();
            $stmt->close();

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
            echo json_encode(['success' => true, 'message' => 'Page created with translations!', 'page_id' => $pageId]);
            exit;
        }

        // ---------- UPDATE (edit one language; creates it if missing) ----------
        if ($action === 'UPDATE') {
            if (empty($data['page_id']) || empty($data['lang'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'page_id and lang are required.']);
                exit;
            }
            $pageId = (int)$data['page_id'];

            $stmt = $conn->prepare("SELECT ID FROM page WHERE ID = ?");
            $stmt->bind_param("i", $pageId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$exists) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Page not found.']);
                exit;
            }

            saveTranslation(
                $conn, $pageId, trim($data['lang']),
                isset($data['title'])   ? trim($data['title']) : null,
                isset($data['content']) ? (string)$data['content'] : null
            );
            echo json_encode(['success' => true, 'message' => 'Translation saved!']);
            exit;
        }

        // ---------- DELETE (pagelang, pagecategory, pagemedia cascade) ----------
        if ($action === 'DELETE') {
            if (empty($data['page_id'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Page ID is required.']);
                exit;
            }
            $pageId = (int)$data['page_id'];
            $stmt = $conn->prepare("DELETE FROM page WHERE ID = ?");
            $stmt->bind_param("i", $pageId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected === 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Page not found.']);
            } else {
                echo json_encode(['success' => true, 'message' => 'Site deleted successfully!']);
            }
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);

    } catch (Exception $e) {
        try { $conn->rollback(); } catch (Exception $ignore) {}
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}