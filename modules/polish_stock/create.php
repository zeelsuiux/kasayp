<?php
/**
 * Add New Polish Stock Entry (સેક્શન ૬.૬)
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
$kapans = $pdo->query("SELECT id, kapan_code, kapan_name FROM kapans ORDER BY id DESC")->fetchAll();

$defaultPacketNo = generate_next_code('POL', 'polish_stocks', 'packet_no');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $packetNo   = trim($_POST['packet_no'] ?? $defaultPacketNo);
    $stockType  = $_POST['stock_type'] ?? 'parcel';
    $giaNo      = !empty($_POST['gia_number']) ? trim($_POST['gia_number']) : null;
    $sourceType = $_POST['source_type'] ?? 'mfg';
    $kapanId    = !empty($_POST['kapan_id']) ? (int)$_POST['kapan_id'] : null;
    $shape      = $_POST['shape'] ?? 'Round';
    $color      = $_POST['color'] ?? 'G';
    $clarity    = $_POST['clarity'] ?? 'VS1';
    $cut        = $_POST['cut'] ?? 'EX';
    $sizeDesc   = trim($_POST['size_description'] ?? '');
    $pcs        = (int)($_POST['total_pcs'] ?? 1);
    $weight     = (float)($_POST['total_weight'] ?? 0.000);
    $cost       = (float)($_POST['cost_per_carat'] ?? 0.00);
    $salePrice  = (float)($_POST['sale_price_per_carat'] ?? 0.00);
    $location   = $_POST['current_location'] ?? 'Surat';

    if ($weight <= 0 || $pcs <= 0 || empty($packetNo)) {
        set_flash('error', 'કૃપા કરીને પેકેટ નંબર, નંગ અને વજન યોગ્ય રીતે દાખલ કરો.');
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO polish_stocks (
                packet_no, stock_type, gia_number, source_type, kapan_id, shape, clarity, color, cut,
                size_description, total_pcs, total_weight, available_pcs, available_weight,
                cost_per_carat, sale_price_per_carat, current_location, status
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, 'available'
            )");

            $stmt->execute([
                $packetNo, $stockType, $giaNo, $sourceType, $kapanId, $shape, $clarity, $color, $cut,
                $sizeDesc, $pcs, $weight, $pcs, $weight,
                $cost, $salePrice, $location
            ]);

            $stockId = (int)$pdo->lastInsertId();
            log_activity('Polish Stock Created', 'Polish', $stockId, "Added polish stock $packetNo: $weight ct, Type: $stockType");
            set_flash('success', "Polish Stock $packetNo સફળતાપૂર્વક ઉમેરાઈ ગયો છે.");
            header('Location: ' . BASE_URL . '/modules/polish_stock/index.php');
            exit;
        } catch (\Throwable $ex) {
            set_flash('error', 'સેવ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'નવો Polish Stock ઉમેરો (Add Polish Stock)';
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'નવો Polish Stock ઉમેરો (Add Polish Stock)',
    '',
    'gem',
    '<a href="' . BASE_URL . '/modules/polish_stock/index.php" class="btn btn-outline-secondary btn-sm px-3">' . svg_icon('arrow-left', 'me-1') . 'સ્ટોક લિસ્ટ પર પાછા જાઓ</a>',
    'emerald'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-10">
        <form action="<?= BASE_URL ?>/modules/polish_stock/create.php" method="POST">
            <?= csrf_field() ?>

            <!-- Card 1: Packet & Stock Type -->
            <div class="erp-form-card mb-4">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('barcode') ?></span>
                        ૧. પેકેટ વિગત અને પ્રકાર (Packet & Stock Type)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">પેકેટ નંબર (Packet No) *</label>
                            <input type="text" name="packet_no" class="form-control fw-bold font-mono" value="<?= e($defaultPacketNo) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">સ્ટોક પ્રકાર (Stock Type) *</label>
                            <select name="stock_type" class="form-select fw-semibold" id="stock_type_sel">
                                <option value="parcel">૨. Parcel (ઘણા પેકેટનો સમૂહ)</option>
                                <option value="single">૧. Single Packet (એક પેકેટ એક માલ)</option>
                                <option value="gia_single">૩. GIA Single Packet (GIA સર્ટિફાઇડ)</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="gia_box">
                            <label class="form-label small fw-bold text-muted">GIA સર્ટિફિકેટ નંબર (GIA Report No)</label>
                            <input type="text" name="gia_number" class="form-control font-mono" placeholder="GIA-2194883921">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: 4Cs & Diamond Specs -->
            <div class="erp-form-card mb-4">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-purple"><?= svg_icon('gem') ?></span>
                        ૨. હીરા સ્પેસિફિકેશન & 4Cs (Diamond Specification)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">શેપ (Shape)</label>
                            <select name="shape" class="form-select">
                                <option value="Round">Round</option>
                                <option value="Princess">Princess</option>
                                <option value="Emerald">Emerald</option>
                                <option value="Oval">Oval</option>
                                <option value="Pear">Pear</option>
                                <option value="Marquise">Marquise</option>
                                <option value="Cushion">Cushion</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">કલર (Color)</label>
                            <input type="text" name="color" class="form-control" value="G" placeholder="D, E, F, G, H...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">ક્લેરિટી (Clarity)</label>
                            <input type="text" name="clarity" class="form-control" value="VS1" placeholder="VVS1, VS1, SI1...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">કટ (Cut Grade)</label>
                            <select name="cut" class="form-select">
                                <option value="EX">EX (Excellent)</option>
                                <option value="VG">VG (Very Good)</option>
                                <option value="GD">GD (Good)</option>
                                <option value="FR">FR (Fair)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 3: Weight, Rate & Storage -->
            <div class="erp-form-card mb-4">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-gold"><?= svg_icon('calculator') ?></span>
                        ૩. વજન, કિંમત અને સંગ્રહ સ્થાન (Units, Pricing & Location)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">કુલ નંગ (Total Pcs) *</label>
                            <input type="number" name="total_pcs" class="form-control fw-bold font-mono" value="1" min="1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-primary">કુલ વજન (Carat) *</label>
                            <input type="number" step="0.01" name="total_weight" class="form-control fw-bold text-primary font-mono" placeholder="0.00" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">પડતર ભાવ / Ct (Cost INR)</label>
                            <input type="number" step="0.01" name="cost_per_carat" class="form-control font-mono" placeholder="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">અપેક્ષિત વેચાણ ભાવ / Ct</label>
                            <input type="number" step="0.01" name="sale_price_per_carat" class="form-control font-mono" placeholder="0.00">
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">હાજર જગ્યા (Current Location) *</label>
                            <select name="current_location" class="form-select fw-semibold">
                                <option value="Surat" selected>Surat Office</option>
                                <option value="Mumbai">Mumbai Office</option>
                                <option value="GIA">GIA Lab</option>
                                <option value="Jangad">Jangad પર</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">સોર્સ પ્રકાર (Source Type)</label>
                            <select name="source_type" class="form-select">
                                <option value="mfg">પોતાનો MFG Polish</option>
                                <option value="polish_purchase">બહારથી Polish ખરીદી</option>
                                <option value="memo_inward">Memo / Jangad Inward</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">કદ વર્ણન (Size Sieve)</label>
                            <input type="text" name="size_description" class="form-control" placeholder="દા.ત. +11 to +14, 0.50 - 0.69 ct">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                <a href="<?= BASE_URL ?>/modules/polish_stock/index.php" class="btn btn-outline-secondary px-4">
                    રદ કરો
                </a>
                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                    <?= svg_icon('floppy-disk', 'me-1') ?>Polish Stock સેવ કરો
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
