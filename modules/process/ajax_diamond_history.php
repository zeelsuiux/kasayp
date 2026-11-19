<?php
/**
 * AJAX Diamond Journey & History API
 * Diamond ERP
 * 
 * Returns full multi-stage audit trail, weight changes, factory assignments,
 * and timeline for any individual diamond packet.
 */

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

if (ob_get_length()) {
    ob_clean();
}
header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'કૃપા કરીને પહેલા લૉગિન કરો.']);
    exit;
}

$packetId = (int)($_GET['id'] ?? $_GET['packet_id'] ?? 0);
$diamondNoQuery = trim($_GET['diamond_no'] ?? '');

$pdo = getDbConnection();

if ($packetId <= 0 && !empty($diamondNoQuery)) {
    $stFind = $pdo->prepare("SELECT id FROM packets WHERE diamond_no = ? OR packet_no = ? OR barcode = ? LIMIT 1");
    $stFind->execute([$diamondNoQuery, $diamondNoQuery, $diamondNoQuery]);
    $packetId = (int)($stFind->fetchColumn() ?: 0);
}

if ($packetId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'અમાન્ય પેકેટ ID.']);
    exit;
}

// 1. Fetch Diamond Master Information
$dStmt = $pdo->prepare("
    SELECT p.*, k.kapan_code, k.kapan_name, pt.party_name as vendor_name, pt.city as party_city
    FROM packets p
    JOIN kapans k ON p.kapan_id = k.id
    LEFT JOIN parties pt ON p.assigned_party_id = pt.id
    WHERE p.id = ?
");
$dStmt->execute([$packetId]);
$diamond = $dStmt->fetch(PDO::FETCH_ASSOC);

if (!$diamond) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'હીરો / પેકેટ મળ્યું નથી.']);
    exit;
}

// 2. Fetch Multi-Stage Audit History from diamond_stage_history
$hStmt = $pdo->prepare("
    SELECT *
    FROM diamond_stage_history
    WHERE packet_id = ?
    ORDER BY id ASC
");
$hStmt->execute([$packetId]);
$stageHistory = $hStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Also fetch process events from packet_process_history
$pStmt = $pdo->prepare("
    SELECT ph.*, pt.party_name as ph_party_name, u.full_name as user_name
    FROM packet_process_history ph
    LEFT JOIN parties pt ON ph.party_id = pt.id
    LEFT JOIN users u ON ph.logged_by = u.id
    WHERE ph.packet_id = ?
    ORDER BY ph.id ASC
");
$pStmt->execute([$packetId]);
$processHistory = $pStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Fetch Child Diamonds (Split parts from LS or Planner)
$cStmt = $pdo->prepare("
    SELECT id, diamond_no, packet_no, barcode, weight, current_weight, current_stage, status, current_location
    FROM packets
    WHERE parent_diamond_id = ?
    ORDER BY id ASC
");
$cStmt->execute([$packetId]);
$childDiamonds = $cStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Totals
$totalLoss = 0.0;
$totalTops = 0.0;
$stagesCompleted = 0;

foreach ($stageHistory as $row) {
    $totalLoss += (float)($row['loss_weight'] ?? 0.0);
    $totalTops += (float)($row['tops_weight'] ?? 0.0);
    if (($row['status'] ?? '') === 'completed') {
        $stagesCompleted++;
    }
}

$origWeight = (float)($diamond['original_weight'] > 0 ? $diamond['original_weight'] : $diamond['weight']);
$currWeight = (float)($diamond['current_weight'] > 0 ? $diamond['current_weight'] : $diamond['weight']);

echo json_encode([
    'success' => true,
    'diamond' => [
        'id'               => (int)$diamond['id'],
        'packet_no'        => $diamond['packet_no'] ?: ($diamond['diamond_no'] ?: '#' . $diamond['id']),
        'diamond_no'       => $diamond['diamond_no'] ?: '',
        'barcode'          => $diamond['barcode'] ?: '',
        'kapan_id'         => (int)$diamond['kapan_id'],
        'kapan_code'       => $diamond['kapan_code'],
        'kapan_name'       => $diamond['kapan_name'],
        'current_stage'    => $diamond['current_stage'],
        'current_location' => $diamond['current_location'] ?: 'Surat',
        'expected_shape'   => $diamond['expected_shape'] ?: '—',
        'status'           => $diamond['status'] ?: 'in_process',
        'vendor_name'      => $diamond['vendor_name'] ?: 'ઇન-હાઉસ (In-House)',
        'assigned_party_id'=> (int)($diamond['assigned_party_id'] ?? 0),
        'is_reassorted'    => (int)($diamond['is_reassorted'] ?? 0),
        'reassort_lot_no'  => $diamond['reassort_lot_no'] ?? null,
        'origin_reassort_lot_no' => $diamond['origin_reassort_lot_no'] ?? null
    ],
    'metrics' => [
        'original_weight' => round($origWeight, 3),
        'current_weight'  => round($currWeight, 3),
        'polish_weight'   => (float)$diamond['polish_weight'],
        'total_loss'      => round($totalLoss, 3),
        'total_tops'      => round($totalTops, 3),
        'stages_count'    => count($stageHistory),
        'completed_count' => $stagesCompleted
    ],
    'stage_history'   => $stageHistory,
    'process_history' => $processHistory,
    'child_diamonds'  => $childDiamonds
]);
exit;
