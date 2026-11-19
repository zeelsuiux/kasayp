<?php
/**
 * Print Barcode Labels for Thermal & Sheet Printers (સેક્શન ૬.૩)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
$pdo = getDbConnection();

$kapanId = (int)($_GET['kapan_id'] ?? $_GET['id'] ?? 0);
$packetId = (int)($_GET['packet_id'] ?? 0);

if ($kapanId > 0) {
    $stmt = $pdo->prepare("SELECT p.*, k.kapan_name, k.kapan_code FROM packets p JOIN kapans k ON p.kapan_id = k.id WHERE p.kapan_id = ? ORDER BY p.id ASC");
    $stmt->execute([$kapanId]);
    $packets = $stmt->fetchAll();
} elseif ($packetId > 0) {
    $stmt = $pdo->prepare("SELECT p.*, k.kapan_name, k.kapan_code FROM packets p JOIN kapans k ON p.kapan_id = k.id WHERE p.id = ?");
    $stmt->execute([$packetId]);
    $packets = $stmt->fetchAll();
} else {
    die("No Kapan or Packet selected.");
}
?>
<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <title>બારકોડ સ્ટીકર્સ પ્રિન્ટ (Print Barcodes) - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/diamond.css">
    <style>
        body { background: #F4F7FB; padding: 20px; font-family: 'Poppins', 'Noto Sans Gujarati', sans-serif; }
        .barcode-container {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
        }
        /* Default 50mm x 25mm Diamond Pouch Label */
        .barcode-card {
            width: 50mm;
            min-height: 28mm;
            background: #ffffff;
            border: 1px dashed #cbd5e1;
            border-radius: 4px;
            padding: 5px 8px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            page-break-inside: avoid;
            margin-bottom: 6px;
        }
        .size-38 { width: 38mm; min-height: 25mm; padding: 3px 5px; }
        .size-50 { width: 50mm; min-height: 28mm; padding: 5px 8px; }
        .size-65 { width: 65mm; min-height: 40mm; padding: 8px 12px; }
        .size-a4 { width: 68mm; min-height: 38mm; border: 1px solid #e2e8f0; margin: 4px; }

        @media print {
            body { background: #ffffff; padding: 0; margin: 0; }
            .no-print { display: none !important; }
            .barcode-card { border: none !important; margin: 0 !important; page-break-after: always; }
            .barcode-container { gap: 0; }
        }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="no-print d-flex flex-wrap justify-content-between align-items-center mb-4 bg-white p-3 rounded-4 shadow-sm border gap-3">
        <div>
            <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
                <?= svg_icon('barcode', 'text-primary', 18) ?>
                <span>બારકોડ લેબલ પ્રિન્ટ (<?= count($packets) ?>)</span>
            </h5>
        </div>
        <div class="d-flex align-items-center gap-2">
            <label class="small fw-bold text-muted mb-0">સાઇઝ:</label>
            <select id="sizeSelector" class="form-select form-select-sm" style="width: 170px;" onchange="changeLabelSize(this.value)">
                <option value="size-50" selected>50mm × 25mm (સ્ટાન્ડર્ડ)</option>
                <option value="size-38">38mm × 25mm (નાની પડીકી)</option>
                <option value="size-65">65mm × 42mm (મોટી સાઇઝ)</option>
                <option value="size-a4">A4 Sheet (24 સ્ટીકર્સ)</option>
            </select>
            <button onclick="window.print()" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold">
                <?= svg_icon('print', 'me-1', 14) ?>પ્રિન્ટ કરો
            </button>
            <button onclick="window.close()" class="btn btn-outline-secondary btn-sm rounded-pill">બંધ કરો</button>
        </div>
    </div>

    <div class="barcode-container" id="barcodeGrid">
        <?php foreach ($packets as $pkt): ?>
            <div class="barcode-card size-50">
                <div class="d-flex justify-content-between align-items-center border-bottom pb-1 mb-1">
                    <span style="font-size: 10px; font-weight: 800; color: #0f2c59; letter-spacing: 0.5px;">KASYAP GEMS</span>
                    <span style="font-size: 8.5px; font-weight: 600; color: #64748b;"><?= e($pkt['kapan_name']) ?></span>
                </div>

                <div class="my-1 d-flex justify-content-center">
                    <?= render_svg_barcode($pkt['barcode'], 42, 1.25) ?>
                </div>

                <div class="d-flex justify-content-between align-items-center border-top pt-1 mt-1 font-mono" style="font-size: 10.5px;">
                    <div><strong><?= e($pkt['packet_no']) ?></strong></div>
                    <div><strong style="color: #0284c7; font-weight: 700;"><?= format_carat($pkt['weight']) ?></strong></div>
                    <div style="font-size: 8.5px; text-transform: uppercase;" class="text-muted fw-bold"><?= e($pkt['current_stage']) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function changeLabelSize(sizeClass) {
    const cards = document.querySelectorAll('.barcode-card');
    cards.forEach(c => {
        c.className = 'barcode-card ' + sizeClass;
    });
}
</script>

</body>
</html>
