<?php
/**
 * Delete Polish Diamond Purchase Entry
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
    set_flash('error', 'અમાન્ય વિનંતી પદ્ધતિ.');
    header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=gia_purchase');
    exit;
}

validate_csrf();

$purchaseId = (int)($_POST['id'] ?? 0);
$returnTab = trim((string)($_POST['return_tab'] ?? 'gia_purchase'));
if (!in_array($returnTab, ['gia_purchase', 'parcel_purchase'], true)) {
    $returnTab = 'gia_purchase';
}

if ($purchaseId <= 0) {
    set_flash('error', 'અમાન્ય ખરીદી ID.');
    header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $returnTab);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM polish_purchases WHERE id = ? FOR UPDATE");
    $stmt->execute([$purchaseId]);
    $pur = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pur) {
        $pdo->rollBack();
        set_flash('error', 'આ ખરીદી રેકોર્ડ મળ્યો નથી.');
        header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $returnTab);
        exit;
    }

    $purNo = $pur['purchase_no'];

    // Check if any stock from this purchase has been sold or put on jangad
    $stkStmt = $pdo->prepare("SELECT id, status, available_weight, total_weight FROM polish_stocks WHERE polish_purchase_id = ? FOR UPDATE");
    $stkStmt->execute([$purchaseId]);
    $stocks = $stkStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($stocks as $stk) {
        if ($stk['status'] === 'sold' || (float)$stk['available_weight'] < (float)$stk['total_weight']) {
            $pdo->rollBack();
            set_flash('error', "આ ખરીદી ($purNo) માંથી માલ વેચાઈ ગયેલ છે અથવા વપરાયેલ છે, તેથી તેને રદ કરી શકાશે નહીં.");
            header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $returnTab);
            exit;
        }
        if ($stk['status'] === 'on_jangad' || $stk['status'] === 'partially_on_jangad') {
            $pdo->rollBack();
            set_flash('error', "આ ખરીદીનો માલ જાંગડ પર મોકલેલ છે, પહેલાં જાંગડ રિટર્ન કરો.");
            header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $returnTab);
            exit;
        }
    }

    // Delete associated stock records
    $delStk = $pdo->prepare("DELETE FROM polish_stocks WHERE polish_purchase_id = ?");
    $delStk->execute([$purchaseId]);

    // Delete parcel shapes
    $delShapes = $pdo->prepare("DELETE FROM polish_parcel_shapes WHERE transaction_type = 'purchase' AND transaction_id = ?");
    $delShapes->execute([$purchaseId]);

    // Delete purchase record
    $delPur = $pdo->prepare("DELETE FROM polish_purchases WHERE id = ?");
    $delPur->execute([$purchaseId]);

    log_activity(
        $pdo,
        $user['id'],
        'Polish Purchase Deleted',
        'Polish',
        $purchaseId,
        "Deleted Polish Purchase $purNo"
    );

    $pdo->commit();
    set_flash('success', "ખરીદી બિલ $purNo સફળતાપૂર્વક રદ (Delete) કરવામાં આવ્યું છે.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'ખરીદી રદ કરતી વખતે ભૂલ: ' . $e->getMessage());
}

header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $returnTab);
exit;
