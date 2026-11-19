<?php
/**
 * AJAX Endpoint: Get Master Color Tags & Color Descriptions
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$category = trim((string)($_GET['category'] ?? $_POST['category'] ?? 'gia'));
if (!in_array($category, ['gia', 'polish'], true)) {
    $category = 'gia';
}

$tagId = (int)($_GET['tag_id'] ?? $_POST['tag_id'] ?? 0);
$tagName = trim((string)($_GET['tag_name'] ?? $_POST['tag_name'] ?? ''));

$pdo = getDbConnection();

// If tag_id is 0 but tag_name is given, look up tag_id
if ($tagId <= 0 && $tagName !== '') {
    $stmt = $pdo->prepare("SELECT id FROM master_color_tags WHERE category = ? AND (tag_name = ? OR tag_code = ?) LIMIT 1");
    $stmt->execute([$category, $tagName, $tagName]);
    $tagId = (int)($stmt->fetchColumn() ?: 0);
}

// If specific tag requested
if ($tagId > 0) {
    $tagStmt = $pdo->prepare("SELECT id, tag_name, tag_code, color_hex FROM master_color_tags WHERE id = ? AND category = ?");
    $tagStmt->execute([$tagId, $category]);
    $tag = $tagStmt->fetch(PDO::FETCH_ASSOC);

    $descriptions = get_master_color_descriptions($tagId, $category, true);

    echo json_encode([
        'success' => true,
        'category' => $category,
        'tag' => $tag ?: null,
        'tag_id' => $tagId,
        'tag_name' => $tag['tag_name'] ?? '',
        'descriptions' => array_map(function($d) {
            return [
                'id' => (int)$d['id'],
                'name' => $d['description_name'],
                'code' => $d['description_code'] ?? '',
                'sort_order' => (int)$d['sort_order'],
            ];
        }, $descriptions),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Otherwise return full hierarchy (all tags + their descriptions)
$all = get_all_color_tags_with_descriptions($category, true);

echo json_encode([
    'success' => true,
    'category' => $category,
    'tags' => $all,
], JSON_UNESCAPED_UNICODE);
