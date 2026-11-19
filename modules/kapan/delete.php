<?php
/**
 * Delete Kapan Entry & Safely Release Allocated Rough Packets back to Inventory
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
require_role(['admin', 'manager']);
$user = current_user();
$pdo = getDbConnection();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    set_flash('error', 'અમાન્ય વિનંતી પદ્ધતિ (Method not allowed).');
    header('Location: ' . BASE_URL . '/modules/kapan/index.php');
    exit;
}

validate_csrf();

$kapanId = (int)($_POST['id'] ?? 0);
if ($kapanId <= 0) {
    set_flash('error', 'અમાન્ય કાપન ID.');
    header('Location: ' . BASE_URL . '/modules/kapan/index.php');
    exit;
}

// Fetch Kapan
$stmt = $pdo->prepare("SELECT * FROM kapans WHERE id = ?");
$stmt->execute([$kapanId]);
$kapan = $stmt->fetch();

if (!$kapan) {
    set_flash('error', 'કાપન રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/kapan/index.php');
    exit;
}

// Safety check: Cannot delete if packets are already polished, in polish stock, or marked closed
if ($kapan['status'] === 'closed') {
    set_flash('error', 'બંધ થયેલ કાપન (Closed Kapan) ડિલીટ કરી શકાતો નથી.');
    header('Location: ' . BASE_URL . '/modules/kapan/view.php?id=' . $kapanId);
    exit;
}

$chkStmt = $pdo->prepare("SELECT COUNT(*) FROM packets WHERE kapan_id = ? AND (current_stage IN ('factory', 'polish_stock') OR polish_weight > 0)");
$chkStmt->execute([$kapanId]);
$advancedPacketsCount = (int)$chkStmt->fetchColumn();
if ($advancedPacketsCount > 0) {
    set_flash('error', 'આ કાપનના ' . $advancedPacketsCount . ' પેકેટ્સ કારખાને/પોલિશિંગ અથવા પોલિશ સ્ટોકમાં આગળ વધી ચૂક્યા છે, તેથી આ કાપન ડિલીટ કરી શકાય નહીં.');
    header('Location: ' . BASE_URL . '/modules/kapan/view.php?id=' . $kapanId);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Fetch all mapped rough packets for this Kapan
    $mappedPackets = get_kapan_rough_mapping($kapanId);

    // 2. Release allocated rough packets back to available inventory
    foreach ($mappedPackets as $mp) {
        if ($mp['rough_packet_type'] === 'assort_part') {
            $upd = $pdo->prepare("UPDATE assort_parts SET status = 'available' WHERE id = ?");
            $upd->execute([(int)$mp['rough_packet_id']]);
        } elseif ($mp['rough_packet_type'] === 'rough_purchase') {
            $upd = $pdo->prepare("UPDATE rough_purchases SET 
                available_weight = available_weight + ?,
                available_pcs = available_pcs + ?
                WHERE id = ?");
            $upd->execute([(float)$mp['allocated_weight'], (int)$mp['allocated_pcs'], (int)$mp['rough_packet_id']]);
        }
    }

    // Fallback: If legacy Kapan had rough_purchase_id but no mapping
    if (empty($mappedPackets) && !empty($kapan['rough_purchase_id'])) {
        $upd = $pdo->prepare("UPDATE rough_purchases SET 
            available_weight = available_weight + ?,
            available_pcs = available_pcs + ?
            WHERE id = ?");
        $upd->execute([(float)$kapan['total_weight'], (int)$kapan['total_pcs'], (int)$kapan['rough_purchase_id']]);
    }

    // 3. Delete mappings
    $delMap = $pdo->prepare("DELETE FROM kapan_rough_mapping WHERE kapan_id = ?");
    $delMap->execute([$kapanId]);

    // 4. Delete unadvanced child packets
    $delPkts = $pdo->prepare("DELETE FROM packets WHERE kapan_id = ?");
    $delPkts->execute([$kapanId]);

    // 5. Delete Kapan
    $delKapan = $pdo->prepare("DELETE FROM kapans WHERE id = ?");
    $delKapan->execute([$kapanId]);

    log_activity('Kapan Deleted', 'Kapan', $kapanId, "Deleted Kapan {$kapan['kapan_code']} ({$kapan['kapan_name']}) and released all allocated rough packets back to inventory");
    $pdo->commit();

    set_flash('success', "કાપન '{$kapan['kapan_code']}' સફળતાપૂર્વક ડિલીટ કરાયું છે અને તેના તમામ Rough પેકેટ્સ ઇન્વેન્ટરીમાં મુક્ત (Available) થઈ ગયા છે.");
    header('Location: ' . BASE_URL . '/modules/kapan/index.php');
    exit;
} catch (\Throwable $ex) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'કાપન ડિલીટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
    header('Location: ' . BASE_URL . '/modules/kapan/view.php?id=' . $kapanId);
    exit;
}
