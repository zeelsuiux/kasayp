<?php
/**
 * AJAX Handler: Update Sort Order for Settings & Master Tables
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = current_user();
if (($user['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'માત્ર એડમિન યુઝર જ ક્રમ બદલી શકે છે.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 1.6 CSRF Protection validation
validate_csrf();

// Whitelist of tables allowed to be reordered
$allowedTables = [
    'assort_part_names',
    'assort_part_types',
    'processing_stages',
    'master_destinations',
    'master_safes',
    'master_dabbas',
    'master_tags',
    'master_process_tags',
    'master_gia_attributes',
    'master_color_tags',
    'master_color_descriptions',
];

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?: $_POST;

$table = trim((string)($data['table'] ?? ''));
$order = $data['order'] ?? [];

if (!in_array($table, $allowedTables, true)) {
    echo json_encode(['success' => false, 'message' => 'અમાન્ય ટેબલ નામ: ' . htmlspecialchars($table)]);
    exit;
}

if (!is_array($order) || empty($order)) {
    echo json_encode(['success' => false, 'message' => 'અમાન્ય અથવા ખાલી ક્રમ ડેટા']);
    exit;
}

$pdo = getDbConnection();

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE `$table` SET sort_order = ? WHERE id = ?");

    $idx = 1;
    foreach ($order as $id) {
        $cleanId = (int)$id;
        if ($cleanId > 0) {
            $stmt->execute([$idx, $cleanId]);
            $idx++;
        }
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => 'ક્રમ સફળતાપૂર્વક અપડેટ થયો',
        'count' => $idx - 1
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'ડેટાબેઝ એરર: ' . $e->getMessage()]);
}
