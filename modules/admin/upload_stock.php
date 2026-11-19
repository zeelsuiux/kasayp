<?php
/**
 * Existing Stock Excel / CSV Upload (સેક્શન ૬.૧૧)
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

require_role(['admin', 'manager']);
$pdo = getDbConnection();

// Template download handlers
if (isset($_GET['template'])) {
    $t = $_GET['template'];
    header('Content-Type: text/csv; charset=utf-8');
    if ($t === 'rough') {
        header('Content-Disposition: attachment; filename="sample_rough_stock.csv"');
        echo "PurchaseNo,PurchaseDate,PartyName,Pcs,Weight,PricePerCtINR,DueDays,Description\n";
        echo "R-EX-001,2026-11-01,Antwerp Rough Direct Ltd,50,75.250,28500,50,Botswana Makeables Lot\n";
    } else {
        header('Content-Disposition: attachment; filename="sample_polish_stock.csv"');
        echo "PacketNo,StockType,Shape,Color,Clarity,Cut,Pcs,Weight,CostPerCt,Location\n";
        echo "POL-EX-001,parcel,Round,G,VS1,EX,25,8.450,45000,Surat\n";
    }
    exit;
}

// Upload processor
$uploadResults = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['stock_csv'])) {
    validate_csrf();

    $uploadType = $_POST['upload_type'] ?? 'rough';
    $file = $_FILES['stock_csv'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', 'ફાઈલ અપલોડ કરવામાં ક્ષતિ.');
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            set_flash('error', 'કૃપા કરીને માત્ર CSV ફોર્મેટ વાળી ફાઈલ અપલોડ કરો.');
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle !== false) {
                $header = fgetcsv($handle);
                $imported = 0;
                $errors = 0;

                try {
                    $pdo->beginTransaction();

                    if ($uploadType === 'rough') {
                        // Rough purchase batch
                        $partyStmt = $pdo->prepare("SELECT id FROM parties WHERE party_name = ? LIMIT 1");
                        $insertStmt = $pdo->prepare("INSERT INTO rough_purchases (
                            purchase_no, purchase_date, party_id, pcs, weight, available_weight, available_pcs,
                            price_per_carat_inr, payment_amount, net_payment, due_days, due_date, party_payment_status,
                            product_description, status, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, 'in_stock', ?)");

                        while (($row = fgetcsv($handle)) !== false) {
                            if (count($row) < 6) continue;
                            $purNo = trim($row[0]);
                            $purDate = !empty($row[1]) ? trim($row[1]) : date('Y-m-d');
                            $partyName = trim($row[2]);
                            $pcs = (int)$row[3];
                            $weight = (float)$row[4];
                            $pricePerCt = (float)$row[5];
                            $dueDays = isset($row[6]) ? (int)$row[6] : 50;
                            $desc = $row[7] ?? 'Imported Rough Stock';

                            // Find or create party
                            $partyStmt->execute([$partyName]);
                            $partyId = $partyStmt->fetchColumn();
                            if (!$partyId) {
                                $newP = $pdo->prepare("INSERT INTO parties (party_name, party_type, city) VALUES (?, 'party', 'Surat')");
                                $newP->execute([$partyName]);
                                $partyId = (int)$pdo->lastInsertId();
                            }

                            $payment = round($weight * $pricePerCt, 2);
                            $dueDate = date('Y-m-d', strtotime($purDate . " +$dueDays days"));

                            $insertStmt->execute([
                                $purNo, $purDate, $partyId, $pcs, $weight, $weight, $pcs,
                                $pricePerCt, $payment, $payment, $dueDays, $dueDate,
                                $desc, $user['id']
                            ]);
                            $imported++;
                        }
                    } else {
                        // Polish stock batch
                        $insertPol = $pdo->prepare("INSERT INTO polish_stocks (
                            packet_no, stock_type, shape, color, clarity, cut, total_pcs, total_weight,
                            available_pcs, available_weight, cost_per_carat, current_location, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')");

                        while (($row = fgetcsv($handle)) !== false) {
                            if (count($row) < 8) continue;
                            $pktNo = trim($row[0]);
                            $stockType = trim($row[1]);
                            $shape = trim($row[2]);
                            $color = trim($row[3]);
                            $clarity = trim($row[4]);
                            $cut = trim($row[5]);
                            $pcs = (int)$row[6];
                            $weight = (float)$row[7];
                            $cost = isset($row[8]) ? (float)$row[8] : 0.00;
                            $loc = isset($row[9]) ? trim($row[9]) : 'Surat';

                            $insertPol->execute([
                                $pktNo, $stockType, $shape, $color, $clarity, $cut,
                                $pcs, $weight, $pcs, $weight, $cost, $loc
                            ]);
                            $imported++;
                        }
                    }

                    $pdo->commit();
                    log_activity('Stock CSV Uploaded', 'Admin', null, "Uploaded $imported $uploadType records from CSV");
                    set_flash('success', "સફળતાપૂર્વક $imported સ્ટોક રેકોર્ડ્સ આયાત (Import) થઈ ગયા છે.");
                    header('Location: ' . BASE_URL . '/modules/admin/upload_stock.php');
                    exit;
                } catch (\Throwable $ex) {
                    $pdo->rollBack();
                    set_flash('error', 'CSV વાંચવામાં ક્ષતિ: ' . $ex->getMessage());
                } finally {
                    fclose($handle);
                }
            }
        }
    }
}

$pageTitle = 'Excel સ્ટોક અપલોડ (Stock Upload)';
require_once __DIR__ . '/../../includes/header.php';

$inventoryTabs = [
    [
        'url' => BASE_URL . '/modules/polish_stock/index.php',
        'label' => 'Polish Stock (હાજર સ્ટોક)',
        'icon' => 'gem',
        'active' => false
    ],
    [
        'url' => BASE_URL . '/modules/polish_stock/gia.php',
        'label' => 'GIA સર્ટિફિકેશન',
        'icon' => 'award',
        'active' => false
    ],
    [
        'url' => BASE_URL . '/modules/rough_purchase/index.php?status=in_stock',
        'label' => 'Rough In-Stock',
        'icon' => 'shapes',
        'active' => false
    ],
    [
        'url' => BASE_URL . '/modules/tops/index.php',
        'label' => 'Tops (Scrap રદિયો)',
        'icon' => 'recycle',
        'active' => false
    ],
    [
        'url' => BASE_URL . '/modules/admin/upload_stock.php',
        'label' => 'Excel સ્ટોક અપલોડ',
        'icon' => 'file-arrow-up',
        'active' => true
    ]
];
?>

<!-- Master Inventory Segmented Tab Bar -->
<?= render_segmented_tabs($inventoryTabs) ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'Existing Stock Excel / CSV Upload',
    '',
    'file-arrow-up',
    '',
    'primary'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-8">
        <div class="erp-form-card">
            <div class="erp-card-header">
                <h6 class="header-title">
                    <span class="header-icon"><?= svg_icon('file-arrow-up') ?></span>
                    Existing Stock CSV Upload
                </h6>
                
            </div>

            <div class="erp-card-body p-4">
                <!-- Sample Download Templates -->
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded border d-flex justify-content-between align-items-center">
                            <div>
                                <strong class="d-block text-dark">Rough Stock CSV ફોર્મેટ</strong>
                                <small class="text-muted">નમૂનાની CSV ફાઈલ ડાઉનલોડ કરો</small>
                            </div>
                            <a href="?template=rough" class="btn btn-outline-primary btn-sm fw-semibold">
                                <?= svg_icon('download', 'me-1') ?>નમૂનો ડાઉનલોડ
                            </a>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded border d-flex justify-content-between align-items-center">
                            <div>
                                <strong class="d-block text-dark">Polish Stock CSV ફોર્મેટ</strong>
                                <small class="text-muted">નમૂનાની CSV ફાઈલ ડાઉનલોડ કરો</small>
                            </div>
                            <a href="?template=polish" class="btn btn-outline-success btn-sm fw-semibold">
                                <?= svg_icon('download', 'me-1') ?>નમૂનો ડાઉનલોડ
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Upload Form -->
                <form action="<?= BASE_URL ?>/modules/admin/upload_stock.php" method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">અપલોડ કેટેગરી પસંદ કરો *</label>
                        <select name="upload_type" class="form-select fw-semibold" required>
                            <option value="rough" selected>૧. Rough Stock (હાજર રફ હીરા ખરીદી સ્ટોક)</option>
                            <option value="polish">૨. Polish Stock (હાજર તૈયાર હીરા સ્ટોક)</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted">CSV ફાઈલ પસંદ કરો (.csv file) *</label>
                        <input type="file" name="stock_csv" class="form-control" accept=".csv" required>
                        <small class="text-muted mt-1 d-block">
                            Excel માંથી 'Save As' કરીને CSV (Comma delimited) તરીકે સેવ કરેલી ફાઈલ પસંદ કરો.
                        </small>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                        <button type="submit" class="btn btn-primary px-4 fw-bold">
                            <?= svg_icon('cloud-arrow-up', 'me-1') ?>સ્ટોક અપલોડ કરો (Import Now)
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
