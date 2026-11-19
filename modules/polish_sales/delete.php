<?php
/**
 * Delete Polish Diamond Sale Entry & Restore Stock
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
    header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=gia_sell');
    exit;
}

validate_csrf();

$saleId = (int)($_POST['id'] ?? 0);
$returnTab = trim((string)($_POST['return_tab'] ?? 'gia_sell'));
if (!in_array($returnTab, ['gia_sell', 'parcel_sell'], true)) {
    $returnTab = 'gia_sell';
}

if ($saleId <= 0) {
    set_flash('error', 'અમાન્ય વેચાણ ID.');
    header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $returnTab);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Fetch sale record
    $stmt = $pdo->prepare("SELECT * FROM polish_sales WHERE id = ? FOR UPDATE");
    $stmt->execute([$saleId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        $pdo->rollBack();
        set_flash('error', 'આ વેચાણ રેકોર્ડ મળ્યો નથી અથવા પહેલેથી જ રદ થઈ ગયેલ છે.');
        header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $returnTab);
        exit;
    }

    $stockId = (int)$sale['polish_stock_id'];
    $soldPcs = (int)$sale['pcs'];
    $soldWeight = (float)$sale['weight'];
    $invNo = $sale['invoice_no'];

    // 2. Restore polish stock
    if ($stockId > 0) {
        $stkStmt = $pdo->prepare("SELECT * FROM polish_stocks WHERE id = ? FOR UPDATE");
        $stkStmt->execute([$stockId]);
        $stock = $stkStmt->fetch(PDO::FETCH_ASSOC);

        if ($stock) {
            $newAvailPcs = (int)$stock['available_pcs'] + $soldPcs;
            $newAvailWeight = (float)$stock['available_weight'] + $soldWeight;
            $newStatus = ($newAvailWeight > 0) ? 'available' : $stock['status'];

            $updStk = $pdo->prepare("
                UPDATE polish_stocks 
                SET available_pcs = ?, 
                    available_weight = ?, 
                    status = ? 
                WHERE id = ?
            ");
            $updStk->execute([$newAvailPcs, $newAvailWeight, $newStatus, $stockId]);
        }
    }

    // 3. Delete shape-wise entries if parcel sale
    $delShapes = $pdo->prepare("DELETE FROM polish_parcel_shapes WHERE transaction_type = 'sale' AND transaction_id = ?");
    $delShapes->execute([$saleId]);

    // 4. Delete the sale record
    $delSale = $pdo->prepare("DELETE FROM polish_sales WHERE id = ?");
    $delSale->execute([$saleId]);

    // 5. Activity log
    log_activity(
        $pdo,
        $user['id'],
        'Polish Sale Deleted',
        'Polish',
        $saleId,
        "Deleted Polish Sale Invoice $invNo ($soldWeight ct restored to stock)"
    );

    $pdo->commit();
    set_flash('success', "વેચાણ બિલ $invNo સફળતાપૂર્વક રદ (Delete) કરવામાં આવ્યું છે અને સ્ટોક પાછો જમા થયો છે.");
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('error', 'વેચાણ રદ કરતી વખતે ભૂલ: ' . $e->getMessage());
}

header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=' . $returnTab);
exit;
