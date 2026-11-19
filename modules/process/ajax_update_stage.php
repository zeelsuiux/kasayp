<?php
/**
 * AJAX Single, Bulk & Rapid Barcode Stage Update Handler
 * Diamond ERP
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
    echo json_encode(['success' => false, 'message' => 'સત્ર સમાપ્ત થઈ ગયું છે. કૃપા કરીને ફરી લોગિન કરો.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Read either POST parameters or JSON body
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);
if (!is_array($jsonData)) {
    $jsonData = [];
}

$csrfToken   = $_POST['csrf_token'] ?? $jsonData['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$targetStage = trim((string)($_POST['target_stage'] ?? $jsonData['target_stage'] ?? ''));
$actionMode  = trim((string)($_POST['action_mode'] ?? $jsonData['action_mode'] ?? ''));
$barcode     = trim((string)($_POST['barcode'] ?? $jsonData['barcode'] ?? ''));

// Validate CSRF
if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'સિક્યોરિટી ટોકન અમાન્ય છે (Invalid CSRF).']);
    exit;
}

// Parse target packet IDs (supports single packet_id, array packet_ids[], JSON string, or comma-separated)
$packetIds = [];
$rawIds = $_POST['packet_ids'] ?? $jsonData['packet_ids'] ?? null;
if (is_array($rawIds)) {
    $packetIds = $rawIds;
} elseif (is_string($rawIds) && trim($rawIds) !== '') {
    $decoded = json_decode($rawIds, true);
    $packetIds = is_array($decoded) ? $decoded : explode(',', $rawIds);
}
$singleId = (int)($_POST['packet_id'] ?? $jsonData['packet_id'] ?? 0);
if ($singleId > 0) {
    $packetIds[] = $singleId;
}
$packetIds = array_values(array_unique(array_filter(array_map('intval', $packetIds), fn(int $id) => $id > 0)));

// Build ordered active stage pipeline from Master Settings
$activeStages = get_active_processing_stages();
$validStages = [];
$stageDefaultLocs = [];
$orderedStageCodes = [];
foreach ($activeStages as $stg) {
    $code = (string)$stg['stage_code'];
    $validStages[$code] = (string)$stg['stage_name'];
    $stageDefaultLocs[$code] = (string)($stg['default_location'] ?? '');
    $orderedStageCodes[] = $code;
}
if (!isset($validStages['polish_stock'])) {
    $validStages['polish_stock'] = 'Polish Stock';
    $stageDefaultLocs['polish_stock'] = 'Surat';
    $orderedStageCodes[] = 'polish_stock';
}

// Ensure Polish Return is a recognized stage in pipeline between boil and polish_stock
if (!isset($validStages['polish_return'])) {
    $validStages['polish_return'] = 'Polish Return';
    $stageDefaultLocs['polish_return'] = 'Surat';
    $psIdx = array_search('polish_stock', $orderedStageCodes, true);
    if ($psIdx !== false) {
        array_splice($orderedStageCodes, $psIdx, 0, ['polish_return']);
    } else {
        $orderedStageCodes[] = 'polish_return';
    }
}

// Alias mapping for backward compatibility across modules
$stageAliases = [
    'ls'             => 'laser',
    'laser_sawing'   => 'laser',
    'ls_return'      => 'laser',
    'sawing'         => 'laser',
    'laser'          => 'laser',
    'qc'             => 'final_checking',
    'final_checking' => 'final_checking',
    'planning'       => 'planner',
    'planner'        => 'planner',
    'sarin'          => 'sarin',
    'sarin_final'    => 'blocking',
    'bruting'        => 'blocking',
    'mfg'            => 'blocking',
    'blocking'       => 'blocking',
    'faceting'       => 'faceting',
    'mfg_polish'     => 'polishing',
    'factory'        => 'polishing',
    'polishing'      => 'polishing',
    'boil_polish'    => 'boil',
    'weekly_boil'    => 'boil',
    'boil'           => 'boil',
    'boil_return'    => 'polish_return',
    'polish_return'  => 'polish_return',
    'polish_stock'   => 'polish_stock'
];

$isNextMode = ($actionMode === 'next_stage' || $targetStage === '__NEXT__');

if (!$isNextMode) {
    if (!array_key_exists($targetStage, $validStages) && isset($stageAliases[$targetStage])) {
        $aliasCandidate = $stageAliases[$targetStage];
        $targetStage = $aliasCandidate;
    }
    $validStages['ls'] = $validStages['ls'] ?? 'Laser Sawing';
    $validStages['factory'] = $validStages['factory'] ?? 'Polishing';
    $validStages['boil'] = $validStages['boil'] ?? 'Weekly Boil';
    $validStages['laser'] = $validStages['laser'] ?? 'Laser Sawing';
    $validStages['blocking'] = $validStages['blocking'] ?? 'Manufacturing / Blocking';
    $validStages['polishing'] = $validStages['polishing'] ?? 'Polishing';
    $validStages['final_checking'] = $validStages['final_checking'] ?? 'Final QC';
    $validStages['reassort'] = '⚡ Re-Assort (નવો લોટ)';

    if (!array_key_exists($targetStage, $validStages)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'અમાન્ય ટાર્ગેટ સ્ટેજ (Invalid target stage).']);
        exit;
    }
}

$pdo = getDbConnection();
ensure_packets_dynamic_stage_column();
$user = current_user();

// Resolve packets either by barcode or by ID list
if ($barcode !== '' && empty($packetIds)) {
    $bStmt = $pdo->prepare("
        SELECT p.*, k.kapan_code, k.kapan_name, pt.party_name as vendor_name
        FROM packets p
        JOIN kapans k ON p.kapan_id = k.id
        LEFT JOIN parties pt ON p.assigned_party_id = pt.id
        WHERE p.barcode = ? OR p.packet_no = ?
        LIMIT 1
    ");
    $bStmt->execute([$barcode, $barcode]);
    $found = $bStmt->fetch(PDO::FETCH_ASSOC);
    if (!$found) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "બારકોડ '{$barcode}' મળ્યો નથી."]);
        exit;
    }
    $packets = [$found];
} else {
    if (empty($packetIds)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ઓછામાં ઓછો ૧ હીરો / પેકેટ પસંદ કરો.']);
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($packetIds), '?'));
    $pStmt = $pdo->prepare("
        SELECT p.*, k.kapan_code, k.kapan_name, pt.party_name as vendor_name
        FROM packets p
        JOIN kapans k ON p.kapan_id = k.id
        LEFT JOIN parties pt ON p.assigned_party_id = pt.id
        WHERE p.id IN ({$placeholders})
        ORDER BY p.id ASC
    ");
    $pStmt->execute($packetIds);
    $packets = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($packets)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'પસંદ કરેલ પેકેટ્સ મળ્યા નથી.']);
        exit;
    }
}

$assignedPartyId = (int)($_POST['assigned_party_id'] ?? $jsonData['assigned_party_id'] ?? 0);
$customLocation  = trim((string)($_POST['current_location'] ?? $jsonData['current_location'] ?? ''));
$remarks         = trim((string)($_POST['remarks'] ?? $jsonData['remarks'] ?? ''));

$partyName = '';
$partyCity = '';
if ($assignedPartyId > 0) {
    $ptStmt = $pdo->prepare("SELECT party_name, city, status FROM parties WHERE id = ?");
    $ptStmt->execute([$assignedPartyId]);
    $pRow = $ptStmt->fetch(PDO::FETCH_ASSOC);
    if ($pRow) {
        if (($pRow['status'] ?? 'active') === 'blocked') {
            echo json_encode([
                'success' => false,
                'error'   => "આ પાર્ટી/યુનિટ '{$pRow['party_name']}' બ્લોક (Blocked) કરેલ છે. તેમને કામ સોંપી શકાશે નહીં."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $partyName = (string)$pRow['party_name'];
        $partyCity = (string)($pRow['city'] ?? '');
    } else {
        $assignedPartyId = 0;
    }
}

/**
 * Resolve exact next active stage code for a packet's current stage
 */
function resolve_next_stage_code(string $currentStage, array $orderedStageCodes, array $stageAliases): ?string {
    $idx = array_search($currentStage, $orderedStageCodes, true);
    if ($idx === false) {
        $normCur = $stageAliases[$currentStage] ?? $currentStage;
        foreach ($orderedStageCodes as $i => $code) {
            if (($stageAliases[$code] ?? $code) === $normCur) {
                $idx = $i;
                break;
            }
        }
    }
    if ($idx === false) {
        return $orderedStageCodes[1] ?? ($orderedStageCodes[0] ?? null);
    }
    return $orderedStageCodes[$idx + 1] ?? null;
}

/**
 * Apply atomic stage + party transition on one packet inside an active transaction
 */
function apply_packet_stage_transition(
    PDO $pdo,
    array $packet,
    string $destStage,
    int $assignedPartyId,
    string $partyName,
    string $partyCity,
    string $customLocation,
    string $remarks,
    array $validStages,
    array $stageDefaultLocs,
    array $user
): array {
    $packetId = (int)$packet['id'];
    $oldStage = (string)($packet['current_stage'] ?? 'galaxy');

    $locationMap = [
        'galaxy'         => 'Galaxy',
        'planner'        => 'Surat',
        'sarin'          => 'Surat',
        'ls'             => 'LS',
        'laser'          => 'LS',
        'laser_sawing'   => 'LS',
        'sarin_final'    => 'Surat',
        'factory'        => 'Katargam Karkhanu',
        'blocking'       => 'Katargam Karkhanu',
        'polishing'      => 'Katargam Karkhanu',
        'boil'           => 'Surat',
        'weekly_boil'    => 'Surat',
        'final_checking' => 'Surat',
        'polish_return'  => 'Surat',
        'polish_stock'   => 'Surat'
    ];
    $newLocation = !empty($customLocation)
        ? $customLocation
        : (!empty($partyCity)
            ? $partyCity
            : (!empty($stageDefaultLocs[$destStage])
                ? $stageDefaultLocs[$destStage]
                : ($locationMap[$destStage] ?? ($packet['current_location'] ?: 'Surat'))));

    $destStage = trim($destStage);
    if ($destStage === '') {
        throw new \RuntimeException('ટાર્ગેટ સ્ટેજ ખાલી હોઈ શકે નહીં.');
    }

    $lsStatus = $packet['ls_status'] ?? 'pending';
    if (in_array($destStage, ['ls', 'laser', 'laser_sawing', 'sawing'], true)) {
        $lsStatus = 'pending';
    } elseif (in_array($oldStage, ['ls', 'laser', 'laser_sawing', 'sawing'], true)) {
        $lsStatus = 'completed';
    }

    $packetStatus = ($destStage === 'polish_stock') ? 'in_polish_stock' : 'in_process';
    $outputWeight = (float)($packet['polish_weight'] ?? 0) > 0
        ? (float)$packet['polish_weight']
        : (float)($packet['current_weight'] ?? $packet['weight'] ?? 0);

    // Determine candidate party and validate against parties table
    $candidatePartyId = $assignedPartyId > 0 ? $assignedPartyId : (int)($packet['assigned_party_id'] ?? 0);
    $validPartyId = null;
    $validPartyName = '';

    if ($candidatePartyId > 0) {
        $chkStmt = $pdo->prepare("SELECT id, party_name FROM parties WHERE id = ?");
        $chkStmt->execute([$candidatePartyId]);
        $partyRow = $chkStmt->fetch(PDO::FETCH_ASSOC);
        if ($partyRow) {
            $validPartyId = (int)$partyRow['id'];
            $validPartyName = (string)$partyRow['party_name'];
        }
    }

    $effectivePartyName = $partyName !== '' ? $partyName : ($validPartyName ?: (string)($packet['vendor_name'] ?? ''));

    // For packets table: if assignedPartyId > 0, set validated party (or null); otherwise preserve existing valid party (or null)
    $targetPacketPartyId = ($assignedPartyId > 0)
        ? $validPartyId
        : ((!empty($packet['assigned_party_id']) && $validPartyId) ? $validPartyId : null);

    $upd = $pdo->prepare("UPDATE packets SET
        current_stage = ?,
        current_location = ?,
        assigned_party_id = ?,
        ls_status = ?,
        status = ?
        WHERE id = ?");
    $upd->execute([$destStage, $newLocation, $targetPacketPartyId, $lsStatus, $packetStatus, $packetId]);

    $histDetails = "Stage changed from {$oldStage} to {$destStage}"
        . ($effectivePartyName ? " (કારખાનું: {$effectivePartyName})" : '')
        . ($remarks ? " - {$remarks}" : '');

    $hist = $pdo->prepare("INSERT INTO packet_process_history (packet_id, stage, action, party_id, input_weight, output_weight, tops_weight, cost, details, logged_by)
                           VALUES (?, ?, 'Stage & Workshop Update', ?, ?, ?, 0.000, 0.00, ?, ?)");
    $hist->execute([
        $packetId,
        $destStage,
        $validPartyId, // Null when no party assigned, prevents FK 1452 violation
        $packet['weight'],
        $outputWeight,
        $histDetails,
        $user['id']
    ]);

    $availWeight = get_diamond_current_available_weight($packetId);
    record_diamond_stage_return($packetId, $oldStage, $availWeight, 'loss', 0.0, 0.0, 0.0, 'Surat Stock', "Stage updated from {$oldStage}");
    record_diamond_stage_assignment(
        $packetId,
        $destStage,
        $validStages[$destStage] ?? ucfirst($destStage),
        $validPartyId ?? 0,
        $effectivePartyName,
        0,
        '',
        0,
        (float)$availWeight,
        $newLocation,
        !empty($remarks) ? $remarks : "Stage assigned to {$destStage}" . ($effectivePartyName ? " ({$effectivePartyName})" : '')
    );

    $syncedPolishStockId = 0;
    if ($destStage === 'polish_stock') {
        $syncedPolishStockId = sync_packet_to_polish_stock($packetId, (float)$outputWeight, $packet['expected_shape'] ?: null);
    }

    return [
        'packet_id'       => $packetId,
        'packet_no'       => (string)($packet['packet_no'] ?: ($packet['diamond_no'] ?: '#' . $packetId)),
        'barcode'         => (string)($packet['barcode'] ?? ''),
        'kapan_code'      => (string)($packet['kapan_code'] ?? ''),
        'kapan_name'      => (string)($packet['kapan_name'] ?? ''),
        'weight'          => round((float)$availWeight, 3),
        'old_stage'       => $oldStage,
        'new_stage'       => $destStage,
        'new_stage_title' => $validStages[$destStage] ?? ucfirst($destStage),
        'party_id'        => $validPartyId,
        'party_name'      => $effectivePartyName,
        'new_location'    => $newLocation,
        'polish_stock_id' => $syncedPolishStockId,
    ];
}

try {
    $pdo->beginTransaction();

    if ($targetStage === 'reassort') {
        $boilReturnStages = ['boil', 'boil_return', 'polish_return', 'boil_polish', 'weekly_boil'];
        $invalidPkts = [];
        foreach ($packets as $p) {
            $st = strtolower(trim((string)($p['current_stage'] ?? '')));
            if (!in_array($st, $boilReturnStages, true)) {
                $invalidPkts[] = ($p['packet_no'] ?? '#' . $p['id']) . " (" . ($p['current_stage'] ?? '-') . ")";
            }
        }
        if (!empty($invalidPkts)) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'માત્ર બોઇલ રિટર્ન (Boil Return) થયેલા હીરા જ Re-Assort થઈ શકે છે. નીચેના હીરા અન્ય સ્ટેજમાં હોવાથી Re-Assort થઈ શકે નહીં: ' . implode(', ', $invalidPkts)
            ]);
            exit;
        }

        $pktIdList = array_map(fn($p) => (int)$p['id'], $packets);
        $lotNo = trim((string)($_POST['reassort_lot_no'] ?? $jsonData['reassort_lot_no'] ?? ''));
        if ($lotNo === '') {
            $lotNo = generate_next_reassort_lot_no();
        }
        $lotRate = max(0.0, (float)($_POST['reassort_lot_rate'] ?? $jsonData['reassort_lot_rate'] ?? 0));
        $returnDate = date('Y-m-d');
        $notes = trim((string)($_POST['remarks'] ?? $jsonData['remarks'] ?? 'Kanban Stage Re-Assort Exit'));

        $existingBatchId = null;
        foreach ($packets as $p) {
            if (!empty($p['boil_batch_id'])) {
                $existingBatchId = (int)$p['boil_batch_id'];
                break;
            }
        }

        $res = process_boil_return_to_reassort($existingBatchId, $pktIdList, $lotNo, $returnDate, $notes, $user['id'] ?? null, $lotRate);
        $pdo->commit();

        log_activity('Packet Stage Re-Assort', 'Process', $pktIdList[0], "Moved " . count($pktIdList) . " packets to Re-Assort Lot {$res['lot_no']}");

        echo json_encode([
            'success'         => true,
            'message'         => count($pktIdList) . " હીરા જૂની કાપનમાંથી નીકળી નવા Re-Assort લોટ '{$res['lot_no']}' માં ટ્રાન્સફર થયા!",
            'updated_count'   => count($pktIdList),
            'reassort_lot_id' => $res['reassort_lot_id'],
            'lot_no'          => $res['lot_no'],
            'redirect_url'    => BASE_URL . '/modules/assort/create.php?reassort_lot_id=' . $res['reassort_lot_id'],
        ]);
        exit;
    }

    $updatedPackets = [];
    $skippedCount = 0;

    foreach ($packets as $pkt) {
        $oldStage = (string)($pkt['current_stage'] ?? 'galaxy');
        $destStage = $isNextMode
            ? resolve_next_stage_code($oldStage, $orderedStageCodes, $stageAliases)
            : $targetStage;

        if ($destStage === null) {
            $skippedCount++;
            continue;
        }
        if ($oldStage === $destStage && ($assignedPartyId === 0 || $assignedPartyId === (int)($pkt['assigned_party_id'] ?? 0))) {
            $skippedCount++;
            continue;
        }

        $res = apply_packet_stage_transition(
            $pdo,
            $pkt,
            $destStage,
            $assignedPartyId,
            $partyName,
            $partyCity,
            $customLocation,
            $remarks,
            $validStages,
            $stageDefaultLocs,
            $user
        );
        $updatedPackets[] = $res;
    }

    $pdo->commit();

    $updatedCount = count($updatedPackets);
    if ($updatedCount === 0) {
        $infoMsg = $isNextMode
            ? 'પસંદ કરેલ હીરા પહેલેથી આખરી સ્ટેજ (Polish Stock) પર છે.'
            : 'પેકેટ પહેલેથી આ જ સ્ટેજ અને કારખાનામાં છે.';
        echo json_encode([
            'success'         => true,
            'message'         => $infoMsg,
            'updated_count'   => 0,
            'skipped_count'   => $skippedCount,
            'updated_packets' => [],
            'packet_id'       => (int)($packets[0]['id'] ?? 0),
            'packet_no'       => (string)($packets[0]['packet_no'] ?? ''),
            'old_stage'       => (string)($packets[0]['current_stage'] ?? ''),
            'new_stage'       => (string)($packets[0]['current_stage'] ?? ''),
        ]);
        exit;
    }

    if ($updatedCount === 1) {
        $first = $updatedPackets[0];
        log_activity('Packet Stage Updated', 'Process', $first['packet_id'], "Moved {$first['packet_no']} from {$first['old_stage']} to {$first['new_stage']}" . ($partyName ? " via {$partyName}" : ''));
        $successMsg = ($first['new_stage'] === 'polish_stock')
            ? "પેકેટ {$first['packet_no']} પોલિશ કમ્પ્લીટ થઈને Polish Stock માં જમા થઈ ગયું છે!"
            : "પેકેટ {$first['packet_no']} '{$first['new_stage_title']}'" . ($first['party_name'] ? " ({$first['party_name']})" : '') . " માં ટ્રાન્સફર થયું.";
        echo json_encode(array_merge([
            'success'         => true,
            'message'         => $successMsg,
            'updated_count'   => 1,
            'skipped_count'   => $skippedCount,
            'updated_packets' => $updatedPackets,
        ], $first));
    } else {
        $totWt = array_sum(array_column($updatedPackets, 'weight'));
        $stageSummary = $isNextMode ? 'આગળના સ્ટેજ' : ($validStages[$targetStage] ?? $targetStage);
        log_activity('Bulk Packet Stage Updated', 'Process', $updatedPackets[0]['packet_id'], "Moved {$updatedCount} packets (" . number_format($totWt, 3) . " ct) to {$stageSummary}");
        $successMsg = "{$updatedCount} હીરા (" . number_format($totWt, 3) . " ct) સફળતાપૂર્વક '{$stageSummary}' માં ટ્રાન્સફર થઈ ગયા!"
            . ($skippedCount > 0 ? " ({$skippedCount} પહેલેથી પૂર્ણ હતા)" : '');
        echo json_encode([
            'success'         => true,
            'message'         => $successMsg,
            'updated_count'   => $updatedCount,
            'skipped_count'   => $skippedCount,
            'updated_packets' => $updatedPackets,
        ]);
    }
} catch (\Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'સ્ટેજ અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage(),
    ]);
}
exit;
