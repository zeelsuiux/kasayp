<?php
/**
 * Add Tops Record
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $kapanId = !empty($_POST['kapan_id']) ? (int)$_POST['kapan_id'] : null;
    $pcs = (int)($_POST['pcs'] ?? 1);
    $weight = (float)($_POST['weight'] ?? 0.000);
    $rate = (float)($_POST['rate_per_carat'] ?? 0.00);
    $totalVal = round($weight * $rate, 2);
    $date = $_POST['record_date'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');

    if ($weight <= 0) {
        set_flash('error', 'કૃપા કરીને વજન યોગ્ય રીતે દાખલ કરો.');
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO tops_records (kapan_id, source_stage, pcs, weight, rate_per_carat, total_value, status, record_date, notes)
                                   VALUES (?, 'manual_entry', ?, ?, ?, ?, 'in_stock', ?, ?)");
            $stmt->execute([$kapanId, $pcs, $weight, $rate, $totalVal, $date, $notes]);

            if ($kapanId) {
                $pdo->prepare("UPDATE kapans SET total_tops_weight = total_tops_weight + ? WHERE id = ?")->execute([$weight, $kapanId]);
            }

            set_flash('success', "Tops રેકોર્ડ સફળતાપૂર્વક ઉમેરાઈ ગયો છે.");
            header('Location: ' . BASE_URL . '/modules/tops/index.php');
            exit;
        } catch (\Throwable $ex) {
            set_flash('error', 'ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$kapans = $pdo->query("SELECT id, kapan_code, kapan_name FROM kapans ORDER BY id DESC")->fetchAll();
$pageTitle = 'નવો Tops ઉમેરો (Add Tops)';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-3 align-items-center">
    <div class="col-md-7">
        <h4 class="fw-bold mb-1 text-dark">
            <?= svg_icon('recycle', 'text-warning me-2') ?>નવો Tops ઉમેરો (Add Tops / Scrap Record)
        </h4>
        <div class="text-muted small">LS Sawing અને પ્રોસેસમાંથી નીકળતા Tops નું વજન, ભાવ અને સ્ટોક જમા વિગત</div>
    </div>
    <div class="col-md-5 text-md-end mt-2 mt-md-0">
        <a href="<?= BASE_URL ?>/modules/tops/index.php" class="btn btn-outline-secondary btn-sm px-3">
            <?= svg_icon('arrow-left', 'me-1') ?>Tops સ્ટોક પર પાછા જાઓ
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-xl-8">
        <form action="<?= BASE_URL ?>/modules/tops/create.php" method="POST">
            <?= csrf_field() ?>

            <!-- Card 1: Kapan & Units -->
            <div class="erp-form-card">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-gold"><?= svg_icon('recycle') ?></span>
                        ૧. Tops સોર્સ અને વજન (Tops Units & Kapan Link)
                    </h6>
                    
                </div>
                <div class="erp-card-body">
                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted">Kapan સંદર્ભ (Optional Kapan Link)</label>
                        <select name="kapan_id" class="form-select form-select-lg">
                            <option value="">કોઈ Kapan સાથે લિંક નથી (General Tops)</option>
                            <?php foreach ($kapans as $k): ?>
                                <option value="<?= $k['id'] ?>"><?= e($k['kapan_name']) ?> (<?= e($k['kapan_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">તારીખ (Date) *</label>
                            <input type="date" name="record_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">નંગ (Pcs)</label>
                            <div class="input-group">
                                <input type="number" name="pcs" class="form-control font-mono" value="1" min="1">
                                <span class="input-group-text">Pcs</span>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-primary">વજન (Weight Carat) *</label>
                            <div class="input-group">
                                <input min="0" type="number" step="0.01" name="weight" id="top_wt" class="form-control form-control-lg fw-bold text-primary font-mono" placeholder="0.00" required oninput="calcTopTotal()">
                                <span class="input-group-text fw-bold text-primary">Ct</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-dark">ભાવ / Ct (Rate INR)</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" name="rate_per_carat" id="top_rate" class="form-control form-control-lg font-mono" placeholder="0.00" oninput="calcTopTotal()">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card 2: Value & Notes -->
            <div class="erp-form-card">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('receipt') ?></span>
                        ૨. કુલ રકમ અને નોંધ (Valuation & Notes)
                    </h6>
                </div>
                <div class="erp-card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">કુલ રકમ (Total Value INR)</label>
                        <input type="text" id="top_total" class="form-control form-control-lg bg-light fw-bold text-success font-mono" readonly placeholder="₹ 0.00">
                    </div>

                    <div class="mb-4">
                        <label class="form-label small fw-bold text-muted">નોંધ (Notes / LS Source)</label>
                        <input type="text" name="notes" class="form-control" placeholder="LS Sawing Tops, કારીગર માર્ક...">
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                        <a href="<?= BASE_URL ?>/modules/tops/index.php" class="btn btn-secondary px-4">રદ કરો</a>
                        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                            <?= svg_icon('floppy-disk', 'me-2') ?>Tops સેવ કરો
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function calcTopTotal() {
    const wt = parseFloat(document.getElementById('top_wt')?.value) || 0;
    const rate = parseFloat(document.getElementById('top_rate')?.value) || 0;
    const total = Math.round(wt * rate * 100) / 100;
    const el = document.getElementById('top_total');
    if (el) el.value = '₹ ' + total.toFixed(2);
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
