<?php
header('Content-Type: application/json');
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ==========================================
// 1. GET REQUEST: Fetch All Sites OR Single Site
// ==========================================
if ($method === 'GET') {
    try {
        $pageId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: null;
        $title  = !empty($_GET['title']) ? $_GET['title'] : null;
        $slug   = !empty($_GET['slug']) ? $_GET['slug'] : null;

        // Look up 1 site
        if ($pageId !== null || $title !== null || $slug !== null) {
            $whereClause = "";
            $param = "";
            $type = "";

            if ($pageId !== null) {
                $whereClause = "p.ID = ?";
                $param = $pageId;
                $type = "i";
            } elseif ($slug !== null) {
                $whereClause = "pl.slug = ?";
                $param = $slug;
                $type = "s";
            } else {
                $whereClause = "pl.title = ?";
                $param = $title;
                $type = "s";
            }

            $sql = "
                SELECT 
                    p.ID as page_id,
                    p.created_at,
                    pl.title,
                    pl.slug,
                    pl.content,
                    l.lang,
                    c.category,
                    m.file_path,
                    m.file_name,
                    m.file_type,
                    m.alt_text
                FROM page p
                LEFT JOIN pagelang pl ON p.ID = pl.FORpage
                LEFT JOIN lang l ON pl.FORlang = l.ID
                LEFT JOIN pagecategory pc ON p.ID = pc.FORpage
                LEFT JOIN category c ON pc.FORcategory = c.ID
                LEFT JOIN pagemedia pm ON p.ID = pm.FORpage
                LEFT JOIN media m ON pm.FORmedia = m.ID
                WHERE {$whereClause}
                LIMIT 1
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($type, $param);
            $stmt->execute();
            $site = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($site) {
                if (!empty($site['content'])) {
                    $decoded = json_decode($site['content'], true);
                    $site['content'] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $site['content'];
                }

                echo json_encode(['success' => true, 'data' => $site], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'No matching record found.']);
            }
            exit;
        }

        // Load img list of sites in client
        $sql = "
            SELECT 
                p.ID as page_id,
                p.created_at,
                pl.title,
                pl.slug,
                pl.content,
                l.lang,
                c.category,
                m.file_path,
                m.file_name,
                m.file_type,
                m.alt_text
            FROM page p
            LEFT JOIN pagelang pl ON p.ID = pl.FORpage
            LEFT JOIN lang l ON pl.FORlang = l.ID
            LEFT JOIN pagecategory pc ON p.ID = pc.FORpage
            LEFT JOIN category c ON pc.FORcategory = c.ID
            LEFT JOIN pagemedia pm ON p.ID = pm.FORpage
            LEFT JOIN media m ON pm.FORmedia = m.ID
            ORDER BY p.ID DESC
        ";

        $result = $conn->query($sql);
        $sites = $result->fetch_all(MYSQLI_ASSOC);

        // Decode content for each page entry
        foreach ($sites as &$site) {
            if (!empty($site['content'])) {
                $decoded = json_decode($site['content'], true);
                $site['content'] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $site['content'];
            }
        }

        echo json_encode(['success' => true, 'data' => $sites], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

// ==========================================
// 2. POST REQUEST: Create, Update, or Delete
// ==========================================
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? 'CREATE';

    // ------------------------------------------
    // ACTION: DELETE
    // ------------------------------------------
    if ($action === 'DELETE') {
        if (empty($data['page_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Page ID is required.']);
            exit;
        }

        $pageId = (int)$data['page_id'];

        try {
            // Get files to unlink
            $stmtFetch = $conn->prepare("SELECT m.ID, m.file_path FROM media m JOIN pagemedia pm ON m.ID = pm.FORmedia WHERE pm.FORpage = ?");
            $stmtFetch->bind_param("i", $pageId);
            $stmtFetch->execute();
            $mediaToDelete = $stmtFetch->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmtFetch->close();

            // Delete Page
            $stmtPage = $conn->prepare("DELETE FROM page WHERE ID = ?");
            $stmtPage->bind_param("i", $pageId);
            $stmtPage->execute();
            $stmtPage->close();

            // Delete Media
            if (!empty($mediaToDelete)) {
                $stmtMedia = $conn->prepare("DELETE FROM media WHERE ID = ?");
                foreach ($mediaToDelete as $media) {
                    $stmtMedia->bind_param("i", $media['ID']);
                    $stmtMedia->execute();
                    if (!empty($media['file_path']) && file_exists($media['file_path'])) {
                        unlink($media['file_path']);
                    }
                }
                $stmtMedia->close();
            }

            echo json_encode(['success' => true, 'message' => 'Site and media deleted successfully!']);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Delete error: ' . $e->getMessage()]);
            exit;
        }
    }

    // ------------------------------------------
    // ACTION: UPDATE
    // ------------------------------------------
    if ($action === 'UPDATE') {
        if (empty($data['page_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Page ID is required.']);
            exit;
        }

        $pageId = (int)$data['page_id'];

        try {
            if (!empty($data['title'])) {
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['title']), '-'));
                $stmt = $conn->prepare("UPDATE pagelang SET title = ?, slug = ? WHERE FORpage = ?");
                $stmt->bind_param("ssi", $data['title'], $slug, $pageId);
                $stmt->execute();
                $stmt->close();
            }

            if (isset($data['content'])) {
                $jsonContent = json_encode(['text' => $data['content']]);
                $stmt = $conn->prepare("UPDATE pagelang SET content = ? WHERE FORpage = ?");
                $stmt->bind_param("si", $jsonContent, $pageId);
                $stmt->execute();
                $stmt->close();
            }

            echo json_encode(['success' => true, 'message' => 'Site updated successfully!']);
            exit;
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Update error: ' . $e->getMessage()]);
            exit;
        }
    }

    // ------------------------------------------
    // ACTION: CREATE
    // ------------------------------------------
    if (empty($data['title']) || empty($data['category']) || empty($data['lang'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Title, Category, and Language are required.']);
        exit;
    }

    try {
        $conn->begin_transaction();

        // 1. Insert Page
        $stmt = $conn->prepare("INSERT INTO page (created_at) VALUES (NOW())");
        $stmt->execute();
        $pageId = $conn->insert_id;
        $stmt->close();

        // 2. Language
        $stmt = $conn->prepare("SELECT ID FROM lang WHERE lang = ?");
        $stmt->bind_param("s", $data['lang']);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res) {
            $langId = $res['ID'];
        } else {
            $stmt = $conn->prepare("INSERT INTO lang (lang) VALUES (?)");
            $stmt->bind_param("s", $data['lang']);
            $stmt->execute();
            $langId = $conn->insert_id;
            $stmt->close();
        }

        // 3. Pagelang
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['title']), '-'));
        $jsonContent = json_encode(['text' => $data['content'] ?? '']);
        $stmt = $conn->prepare("INSERT INTO pagelang (FORlang, FORpage, title, slug, content) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $langId, $pageId, $data['title'], $slug, $jsonContent);
        $stmt->execute();
        $stmt->close();

        // 4. Category
        $stmt = $conn->prepare("SELECT ID FROM category WHERE category = ?");
        $stmt->bind_param("s", $data['category']);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res) {
            $catId = $res['ID'];
        } else {
            $catSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $data['category']), '-'));
            $stmt = $conn->prepare("INSERT INTO category (category, slug) VALUES (?, ?)");
            $stmt->bind_param("ss", $data['category'], $catSlug);
            $stmt->execute();
            $catId = $conn->insert_id;
            $stmt->close();
        }

        $stmt = $conn->prepare("INSERT INTO pagecategory (FORcategory, FORpage) VALUES (?, ?)");
        $stmt->bind_param("ii", $catId, $pageId);
        $stmt->execute();
        $stmt->close();

        // 5. Media (Optional)
        if (!empty($data['imgName'])) {
            $filePath  = 'uploads/' . $data['imgName'];
            $ext       = strtolower(pathinfo($data['imgName'], PATHINFO_EXTENSION));
            $mimeTypes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            $fileType  = $mimeTypes[$ext] ?? 'image/jpeg';

            $stmt = $conn->prepare("INSERT INTO media (file_path, file_name, file_type, alt_text, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssss", $filePath, $data['imgName'], $fileType, $data['imgAlt']);
            $stmt->execute();
            $mediaId = $conn->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO pagemedia (FORmedia, FORpage) VALUES (?, ?)");
            $stmt->bind_param("ii", $mediaId, $pageId);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Site created successfully!', 'page_id' => $pageId]);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}