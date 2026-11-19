<?php
/**
 * Merge Rough Assort Parts Handler
 * Merges multiple available parts with same tag into a new packet with custom packet number and weighted average cost
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
$user = current_user();
$pdo = getDbConnection();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    set_flash('error', 'અમાન્ય વિનંતી પદ્ધતિ.');
    header('Location: ' . BASE_URL . '/modules/assort/index.php?tab=assorted');
    exit;
}

validate_csrf();

$tagType = trim((string)($_POST['tag_type'] ?? 'sell')); // 'sell', 'mfg_later', or 'mfg'
$partIdsRaw = $_POST['part_ids'] ?? [];
if (!is_array($partIdsRaw) || count($partIdsRaw) < 2) {
    set_flash('error', 'મર્જ કરવા માટે ઓછામાં ઓછા ૨ ભાગો પસંદ કરવા જરૂરી છે.');
    $redirectSub = ($tagType === 'sell') ? 'sell' : (($tagType === 'mfg_later') ? 'mfg_later' : 'mfg');
    header('Location: ' . BASE_URL . '/modules/assort/index.php?tab=assorted&subtab=' . $redirectSub);
    exit;
}

$partIds = array_filter(array_map('intval', $partIdsRaw));
$newPacketNo = trim((string)($_POST['new_packet_no'] ?? ''));
$newPartName = trim((string)($_POST['new_part_name'] ?? ''));
$notes = trim((string)($_POST['notes'] ?? ''));

if (empty($newPacketNo)) {
    set_flash('error', 'કૃપા કરીને નવો પેકેટ / લોટ નંબર દાખલ કરો.');
    $redirectSub = ($tagType === 'sell') ? 'sell' : (($tagType === 'mfg_later') ? 'mfg_later' : 'mfg');
    header('Location: ' . BASE_URL . '/modules/assort/index.php?tab=assorted&subtab=' . $redirectSub);
    exit;
}

if (empty($newPartName)) {
    $newPartName = ($tagType === 'sell') ? 'MERGED SELL ROUGH' : 'MERGED MFG LATER';
}

try {
    $pdo->beginTransaction();

    // 1. Fetch and lock source parts
    $inClause = implode(',', array_fill(0, count($partIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM assort_parts WHERE id IN ($inClause) FOR UPDATE");
    $stmt->execute($partIds);
    $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($parts) !== count($partIds)) {
        throw new Exception('પસંદ કરેલા કેટલાક ભાગો ડેટાબેઝમાં મળ્યા નથી.');
    }

    $totalPcs = 0;
    $totalWeight = 0.0;
    $totalCostAmount = 0.0;
    $sourceLots = [];
    $firstTaskId = 0;

    foreach ($parts as $p) {
        if (($p['status'] ?? '') !== 'available') {
            throw new Exception("ભાગ #{$p['assort_number']} ({$p['part_name']}) હાલ સ્ટોકમાં ઉપલબ્ધ નથી (સ્થિતિ: {$p['status']}).");
        }

        $pcs = (int)$p['pcs'];
        $wt = (float)$p['weight'];
        $cost = (float)$p['cost_per_carat'];

        $totalPcs += $pcs;
        $totalWeight += $wt;
        $totalCostAmount += ($wt * $cost);

        if (!empty($p['rough_number']) && !in_array($p['rough_number'], $sourceLots, true)) {
            $sourceLots[] = $p['rough_number'];
        }
        if ($firstTaskId === 0 && !empty($p['assort_task_id'])) {
            $firstTaskId = (int)$p['assort_task_id'];
        }
    }

    if ($totalWeight <= 0) {
        throw new Exception('કુલ મર્જ વજન ૦ થી વધુ હોવું જોઈએ.');
    }

    $weightedRate = round($totalCostAmount / $totalWeight, 2);
    $sourceLotsStr = implode(', ', $sourceLots);

    // Target part type
    $targetPartType = match($tagType) {
        'sell' => 'out_sell',
        'mfg_later' => 'low_number_mfg',
        default => 'mfg'
    };

    // 2. Insert new merged part into assort_parts
    $insStmt = $pdo->prepare("
        INSERT INTO assort_parts (
            assort_task_id, source_type, lot_no, assort_number, rough_number,
            part_name, part_type, pcs, weight, cost_per_carat, status, notes, created_at
        ) VALUES (
            ?, 'rough_purchase', ?, ?, ?,
            ?, ?, ?, ?, ?, 'available', ?, NOW()
        )
    ");
    $insStmt->execute([
        $firstTaskId,
        $newPacketNo,
        $newPacketNo,
        $sourceLotsStr ?: 'MERGED',
        $newPartName,
        $targetPartType,
        $totalPcs,
        $totalWeight,
        $weightedRate,
        "Merged from parts: " . implode(', ', array_column($parts, 'assort_number')) . ($notes ? " | " . $notes : "")
    ]);
    $newPartId = (int)$pdo->lastInsertId();

    // 3. Update source parts to 'merged'
    $updStmt = $pdo->prepare("UPDATE assort_parts SET status = 'merged', merged_into_part_id = ? WHERE id IN ($inClause)");
    $updStmt->execute(array_merge([$newPartId], $partIds));

    // 4. Record audit in assort_part_merges
    $mergeCode = 'MRG-' . date('Y') . '-' . str_pad((string)$newPartId, 4, '0', STR_PAD_LEFT);
    $auditStmt = $pdo->prepare("
        INSERT INTO assort_part_merges (
            merge_code, tag_type, new_part_id, new_packet_no, new_part_name,
            total_pcs, total_weight, total_cost_amount, weighted_rate,
            source_part_ids, source_lot_numbers, notes, created_by, created_at
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, NOW()
        )
    ");
    $auditStmt->execute([
        $mergeCode,
        $tagType,
        $newPartId,
        $newPacketNo,
        $newPartName,
        $totalPcs,
        $totalWeight,
        $totalCostAmount,
        $weightedRate,
        json_encode($partIds),
        $sourceLotsStr,
        $notes,
        $user['id'] ?? null
    ]);

    // 5. Activity log
    log_activity(
        $pdo,
        $user['id'] ?? 1,
        'Rough Parts Merged',
        'Assort',
        $newPartId,
        "Merged " . count($parts) . " parts into $newPacketNo ($totalWeight ct @ ₹$weightedRate/ct)"
    );

    $pdo->commit();
    set_flash('success', "ભાગો સફળતાપૂર્વક મર્જ થયા! નવો પેકેટ નંબર: $newPacketNo ($totalWeight ct, સરેરાશ ભાવ: ₹$weightedRate/ct).");
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'ભાગો મર્જ કરતી વખતે ભૂલ: ' . $e->getMessage());
}

$redirectSub = ($tagType === 'sell') ? 'sell' : (($tagType === 'mfg_later') ? 'mfg_later' : 'mfg');
header('Location: ' . BASE_URL . '/modules/assort/index.php?tab=assorted&subtab=' . $redirectSub);
exit;
