<?php
/**
 * Merge Rough Purchases (રફ ખરીદી મર્જ કરો)
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

// Check if AJAX JSON request
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
          || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    validate_csrf();

    $purchaseIds = $_POST['purchase_ids'] ?? [];
    if (is_string($purchaseIds)) {
        $purchaseIds = array_filter(array_map('intval', explode(',', $purchaseIds)));
    } else {
        $purchaseIds = array_filter(array_map('intval', (array)$purchaseIds));
    }

    $targetId = (int)($_POST['target_id'] ?? 0);
    $customPurchaseNo = trim($_POST['custom_purchase_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (count($purchaseIds) < 2) {
        $errMsg = 'મર્જ કરવા માટે ઓછામાં ઓછી ૨ ખરીદી પસંદ કરવી જરૂરી છે.';
        if ($isAjax) {
            echo json_encode(['success' => false, 'error' => $errMsg]);
            exit;
        }
        set_flash('error', $errMsg);
        header('Location: ' . BASE_URL . '/modules/rough/index.php?tab=purchase');
        exit;
    }

    if ($targetId <= 0 || !in_array($targetId, $purchaseIds, true)) {
        // default to first selected
        $targetId = $purchaseIds[0];
    }

    try {
        $pdo->beginTransaction();

        // Lock and fetch selected purchases
        $inClause = implode(',', array_fill(0, count($purchaseIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM rough_purchases WHERE id IN ($inClause) FOR UPDATE");
        $stmt->execute($purchaseIds);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($records) < 2) {
            throw new Exception('પસંદ કરેલ ખરીદીઓ મળતી નથી.');
        }

        // Verify none is already merged
        $targetRecord = null;
        $sourceRecords = [];
        foreach ($records as $r) {
            if ($r['status'] === 'merged') {
                throw new Exception("ખરીદી #{$r['purchase_no']} પહેલેથી જ મર્જ થયેલી છે.");
            }
            if ((int)$r['id'] === $targetId) {
                $targetRecord = $r;
            } else {
                $sourceRecords[] = $r;
            }
        }

        if (!$targetRecord) {
            throw new Exception('મુખ્ય (Target) ખરીદી રેકોર્ડ મળ્યો નથી.');
        }

        // Calculate combined aggregates
        $combinedWeight = (float)$targetRecord['weight'];
        $combinedAvailWeight = (float)$targetRecord['available_weight'];
        $combinedPcs = (int)$targetRecord['pcs'];
        $combinedAvailPcs = (int)$targetRecord['available_pcs'];
        $combinedPaymentAmount = (float)$targetRecord['payment_amount'];
        $combinedNetPayment = (float)$targetRecord['net_payment'];

        $sourceNos = [];
        $sourceDetailsTxt = [];

        foreach ($sourceRecords as $src) {
            $srcWeight = (float)$src['weight'];
            $srcAvailWeight = (float)$src['available_weight'];
            $srcPcs = (int)$src['pcs'];
            $srcAvailPcs = (int)$src['available_pcs'];
            $srcPayment = (float)$src['payment_amount'];
            $srcNet = (float)$src['net_payment'];

            $combinedWeight += $srcWeight;
            $combinedAvailWeight += $srcAvailWeight;
            $combinedPcs += $srcPcs;
            $combinedAvailPcs += $srcAvailPcs;
            $combinedPaymentAmount += $srcPayment;
            $combinedNetPayment += $srcNet;

            $sourceNos[] = $src['purchase_no'];
            $sourceDetailsTxt[] = "#{$src['purchase_no']} (" . format_carat($srcWeight) . ", {$srcPcs} pcs)";
        }

        $combinedWeight = round($combinedWeight, 3);
        $combinedAvailWeight = round($combinedAvailWeight, 3);
        $combinedPaymentAmount = round($combinedPaymentAmount, 2);
        $combinedNetPayment = round($combinedNetPayment, 2);

        // Weighted Average Price per Carat
        $weightedAvgRate = $combinedWeight > 0 ? round($combinedPaymentAmount / $combinedWeight, 2) : (float)$targetRecord['price_per_carat_inr'];

        // Determine final purchase_no for target
        $finalPurchaseNo = $targetRecord['purchase_no'];
        if (!empty($customPurchaseNo) && $customPurchaseNo !== $targetRecord['purchase_no']) {
            // Check if unique
            $chk = $pdo->prepare("SELECT id FROM rough_purchases WHERE purchase_no = ? AND id != ?");
            $chk->execute([$customPurchaseNo, $targetId]);
            if ($chk->fetch()) {
                throw new Exception("ખરીદી નંબર '{$customPurchaseNo}' પહેલેથી ઉપયોગમાં છે. કૃપા કરીને બીજો નંબર પસંદ કરો.");
            }
            $finalPurchaseNo = $customPurchaseNo;
        }

        $mergeTimestamp = date('d-m-Y H:i');
        $targetNotesAppend = "\n[🔀 Merged with: " . implode(', ', $sourceDetailsTxt) . " on {$mergeTimestamp} by {$user['full_name']}]";
        if (!empty($notes)) {
            $targetNotesAppend .= " (Note: {$notes})";
        }

        // Determine target status
        $targetStatus = $combinedAvailWeight > 0 ? 'in_stock' : 'assorted';

        // 1. Update Target Master Purchase
        $updTarget = $pdo->prepare("UPDATE rough_purchases SET 
            purchase_no = ?,
            weight = ?,
            available_weight = ?,
            pcs = ?,
            available_pcs = ?,
            price_per_carat_inr = ?,
            payment_amount = ?,
            net_payment = ?,
            product_description = CONCAT(COALESCE(product_description, ''), ?),
            status = ?
            WHERE id = ?");
        $updTarget->execute([
            $finalPurchaseNo,
            $combinedWeight,
            $combinedAvailWeight,
            $combinedPcs,
            $combinedAvailPcs,
            $weightedAvgRate,
            $combinedPaymentAmount,
            $combinedNetPayment,
            $targetNotesAppend,
            $targetStatus,
            $targetId
        ]);

        // 2. Update Source Purchases -> status = 'merged', available = 0
        $updSource = $pdo->prepare("UPDATE rough_purchases SET 
            status = 'merged',
            merged_into_id = ?,
            available_weight = 0.000,
            available_pcs = 0,
            product_description = CONCAT(COALESCE(product_description, ''), ?)
            WHERE id = ?");

        foreach ($sourceRecords as $src) {
            $srcNotesAppend = "\n[🔀 Merged into Purchase #{$finalPurchaseNo} on {$mergeTimestamp} by {$user['full_name']}]";
            $updSource->execute([
                $targetId,
                $srcNotesAppend,
                (int)$src['id']
            ]);
        }

        // 3. Record Audit in rough_purchase_merges
        $mergeCode = 'MRG-' . date('Ymd') . '-' . str_pad((string)$targetId, 4, '0', STR_PAD_LEFT);
        $insMerge = $pdo->prepare("INSERT INTO rough_purchase_merges (
            merge_code, target_purchase_id, source_purchase_ids, merged_total_weight, merged_total_pcs, merged_total_amount, weighted_average_rate, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $insMerge->execute([
            $mergeCode,
            $targetId,
            json_encode(array_values(array_map('intval', array_column($sourceRecords, 'id')))),
            $combinedWeight,
            $combinedPcs,
            $combinedNetPayment,
            $weightedAvgRate,
            $notes ?: "Merged " . implode(', ', $sourceNos) . " into #{$finalPurchaseNo}",
            $user['id']
        ]);

        // Activity log
        log_activity(
            'Rough Purchase Merged',
            'rough_purchases',
            $targetId,
            "Merged purchases (" . implode(', ', $sourceNos) . ") into purchase #{$finalPurchaseNo}. Combined weight: " . format_carat($combinedWeight) . ", Avg Rate: ₹{$weightedAvgRate}."
        );

        $pdo->commit();

        $successMsg = "ખરીદી સફળતાપૂર્વક મર્જ થઈ ગઈ છે! (માસ્ટર ખરીદી: #{$finalPurchaseNo}, કુલ વજન: " . format_carat($combinedWeight) . ", સરેરાશ ભાવ: ₹{$weightedAvgRate}/Ct)";

        if ($isAjax) {
            echo json_encode([
                'success' => true,
                'message' => $successMsg,
                'target_id' => $targetId,
                'purchase_no' => $finalPurchaseNo,
                'total_weight' => $combinedWeight,
                'avg_rate' => $weightedAvgRate
            ]);
            exit;
        }

        set_flash('success', $successMsg);
        header('Location: ' . BASE_URL . '/modules/rough/index.php?tab=purchase');
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errMsg = 'મર્જ કરવામાં ભૂલ આવી: ' . $e->getMessage();
        if ($isAjax) {
            echo json_encode(['success' => false, 'error' => $errMsg]);
            exit;
        }
        set_flash('error', $errMsg);
        header('Location: ' . BASE_URL . '/modules/rough/index.php?tab=purchase');
        exit;
    }
}

// If GET, redirect back to purchases tab
header('Location: ' . BASE_URL . '/modules/rough/index.php?tab=purchase');
exit;
