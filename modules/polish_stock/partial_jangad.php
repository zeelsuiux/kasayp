<?php
/**
 * Partial Jangad (Memo) Issue from Polish Stock (સેક્શન ૬.૬)
 * Example from Brief: 10 pc 100 ct -> 3 pc 40 ct to Broker, Main shows 7 pc 60 ct balance!
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
$stockId = (int)($_GET['stock_id'] ?? $_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM polish_stocks WHERE id = ?");
$stmt->execute([$stockId]);
$stock = $stmt->fetch();

if (!$stock || (float)$stock['available_weight'] <= 0) {
    set_flash('error', 'આ સ્ટોક પેકેટ ઉપલબ્ધ નથી અથવા વજન 0 છે.');
    header('Location: ' . BASE_URL . '/modules/polish_stock/index.php');
    exit;
}

// Fetch parties & brokers
$parties = $pdo->query("SELECT id, party_name, city FROM parties WHERE party_type IN ('party', 'both') ORDER BY party_name ASC")->fetchAll();
$brokers = $pdo->query("SELECT id, party_name FROM parties WHERE party_type IN ('broker', 'both') ORDER BY party_name ASC")->fetchAll();

$defaultJangadNo = generate_next_code('JNG-MEM', 'jangad_vouchers', 'jangad_no');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $jangadNo   = trim($_POST['jangad_no'] ?? $defaultJangadNo);
    $partyId    = (int)($_POST['party_id'] ?? 0);
    $brokerId   = !empty($_POST['broker_id']) ? (int)$_POST['broker_id'] : null;
    $issueDate  = $_POST['issue_date'] ?? date('Y-m-d');
    $returnDate = !empty($_POST['expected_return_date']) ? $_POST['expected_return_date'] : null;
    $issuePcs   = (int)($_POST['issue_pcs'] ?? 1);
    $issueWeight = (float)($_POST['issue_weight'] ?? 0.000);
    $remarks    = trim($_POST['remarks'] ?? '');

    $availPcs = (int)$stock['available_pcs'];
    $availWeight = (float)$stock['available_weight'];

    if ($partyId <= 0 || $issueWeight <= 0 || $issuePcs <= 0) {
        set_flash('error', 'કૃપા કરીને પાર્ટી, ઇસ્યુ નંગ અને ઇસ્યુ વજન યોગ્ય રીતે દાખલ કરો.');
    } elseif ($issueWeight > $availWeight || $issuePcs > $availPcs) {
        set_flash('error', "ઇસ્યુ વજન અથવા નંગ હાજર સ્ટોક કરતાં વધુ હોઈ શકે નહીં (હાજર: $availWeight ct / $availPcs Pcs).");
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Create Jangad Voucher
            $jStmt = $pdo->prepare("INSERT INTO jangad_vouchers (
                jangad_no, voucher_type, category, party_id, broker_id, issue_date,
                expected_return_date, total_pcs, total_weight, pending_weight, status, remarks, created_by
            ) VALUES (
                ?, 'issue', 'memo_broker', ?, ?, ?,
                ?, ?, ?, ?, 'issued', ?, ?
            )");
            $jStmt->execute([
                $jangadNo, $partyId, $brokerId, $issueDate,
                $returnDate, $issuePcs, $issueWeight, $issueWeight, $remarks, $user['id']
            ]);
            $jangadId = (int)$pdo->lastInsertId();

            // 2. Create Jangad Item linked to this Polish Stock
            $itemStmt = $pdo->prepare("INSERT INTO jangad_items (
                jangad_id, item_type, polish_stock_id, description, issued_pcs, issued_weight, status
            ) VALUES (
                ?, 'polish_parcel', ?, ?, ?, ?, 'issued'
            )");
            $desc = "Partial Memo from {$stock['packet_no']} ({$stock['shape']} {$stock['color']}/{$stock['clarity']})";
            $itemStmt->execute([$jangadId, $stock['id'], $desc, $issuePcs, $issueWeight]);

            // 3. Deduct from Main Packet (Section 6.6 Formula)
            $remPcs = $availPcs - $issuePcs;
            $remWeight = round($availWeight - $issueWeight, 3);
            $newStatus = ($remWeight > 0) ? 'partially_on_jangad' : 'on_jangad';

            $updStock = $pdo->prepare("UPDATE polish_stocks SET
                available_pcs = ?,
                available_weight = ?,
                status = ?
                WHERE id = ?");
            $updStock->execute([$remPcs, $remWeight, $newStatus, $stock['id']]);

            $pdo->commit();
            log_activity('Partial Jangad Issued', 'Jangad', $jangadId, "Issued $issuePcs pcs ($issueWeight ct) from {$stock['packet_no']} to Party ID $partyId");
            set_flash('success', "Partial Jangad $jangadNo સફળતાપૂર્વક ઇસ્યુ થયો. Main Packet માં Balance: $remWeight ct ($remPcs Pcs) છે.");
            header('Location: ' . BASE_URL . '/modules/jangad/view.php?id=' . $jangadId);
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'Jangad ઇસ્યુ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'Partial Jangad ઇસ્યુ (Memo Issue)';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-9">
        <div class="card-erp">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold mb-0 text-dark"><?= svg_icon('file-invoice', 'me-2 text-warning') ?>Partial Jangad (Memo) ઇસ્યુ</h5>
                    <small class="text-muted">ઉદાહરણ — 10 pc 100 ct માંથી 3 pc 40 ct આપવું &rarr; Main Packet માં 7 pc 60 ct Balance</small>
                </div>
                <a href="<?= BASE_URL ?>/modules/polish_stock/index.php" class="btn btn-outline-secondary btn-sm">&larr; સ્ટોક પર પાછા</a>
            </div>

            <div class="card-body p-4">
                <!-- Source Stock Info Banner -->
                <div class="p-3 bg-light rounded-3 border mb-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-primary me-2">Main Polish Packet</span>
                            <strong class="fs-6"><?= e($stock['packet_no']) ?></strong>
                            <div class="small text-muted"><?= e($stock['shape']) ?> • <?= e($stock['color']) ?> / <?= e($stock['clarity']) ?> (<?= e($stock['size_description'] ?? '') ?>)</div>
                        </div>
                        <div class="d-flex gap-4">
                            <div>
                                <small class="text-muted d-block">હાજર નંગ</small>
                                <strong class="fs-5" id="avail_pcs_display"><?= (int)$stock['available_pcs'] ?> Pcs</strong>
                                <input type="hidden" id="avail_pcs_val" value="<?= (int)$stock['available_pcs'] ?>">
                            </div>
                            <div>
                                <small class="text-muted d-block">હાજર વજન</small>
                                <strong class="fs-5 text-success" id="avail_wt_display"><?= format_carat($stock['available_weight']) ?></strong>
                                <input type="hidden" id="avail_wt_val" value="<?= (float)$stock['available_weight'] ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <form action="<?= BASE_URL ?>/modules/polish_stock/partial_jangad.php?stock_id=<?= $stock['id'] ?>" method="POST">
                    <?= csrf_field() ?>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">Jangad નંબર *</label>
                            <input type="text" name="jangad_no" class="form-control fw-bold" value="<?= e($defaultJangadNo) ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">ઇસ્યુ તારીખ *</label>
                            <input type="date" name="issue_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">અપેક્ષિત પરત તારીખ (Expected Return)</label>
                            <input type="date" name="expected_return_date" class="form-control" value="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">દલાલ (Broker)</label>
                            <select name="broker_id" class="form-select">
                                <option value="">કોઈ દલાલ નથી</option>
                                <?php foreach ($brokers as $br): ?>
                                    <option value="<?= $br['id'] ?>"><?= e($br['party_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">પાર્ટીનું નામ (Customer / Party Name) *</label>
                            <select name="party_id" class="form-select fw-semibold" required>
                                <option value="">પાર્ટી પસંદ કરો...</option>
                                <?php foreach ($parties as $pt): ?>
                                    <option value="<?= $pt['id'] ?>"><?= e($pt['party_name']) ?> (<?= e($pt['city']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">નોંધ / રિમાર્ક્સ (Remarks)</label>
                            <input type="text" name="remarks" class="form-control" placeholder="જોવા માટે મેમો પર આપેલ...">
                        </div>
                    </div>

                    <!-- Partial Quantity Inputs & Live Balance Calculation -->
                    <div class="p-3 bg-warning-subtle rounded-3 border border-warning mb-4">
                        <h6 class="fw-bold text-dark mb-3"><?= svg_icon('calculator', 'me-2') ?>Partial Jangad ઇસ્યુ ગણતરી</h6>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label small fw-bold text-dark">ઇસ્યુ નંગ (Issue Pcs) *</label>
                                <input type="number" name="issue_pcs" id="issue_pcs" class="form-control fw-bold" value="1" min="1" max="<?= (int)$stock['available_pcs'] ?>" required oninput="calcPartialBalance()">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold text-dark">ઇસ્યુ વજન (Issue Weight Ct) *</label>
                                <input type="number" step="0.01" name="issue_weight" id="issue_weight" class="form-control fw-bold text-danger" placeholder="0.00" required oninput="calcPartialBalance()">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold text-dark">Main Packet માં Balance Pcs</label>
                                <input type="text" id="balance_pcs_preview" class="form-control bg-white fw-bold" readonly value="<?= (int)$stock['available_pcs'] - 1 ?> Pcs">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold text-dark">Main Packet માં Balance વજન</label>
                                <input type="text" id="balance_wt_preview" class="form-control bg-white fw-bold text-success" readonly value="<?= format_carat((float)$stock['available_weight']) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                        <a href="<?= BASE_URL ?>/modules/polish_stock/index.php" class="btn btn-secondary px-4">રદ કરો</a>
                        <button type="submit" class="btn btn-warning px-4 fw-bold text-dark">
                            <?= svg_icon('file-invoice', 'me-1') ?>Jangad ઇસ્યુ કરો & Stock અપડેટ કરો
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function calcPartialBalance() {
    const availPcs = parseInt(document.getElementById('avail_pcs_val')?.value) || 0;
    const availWt = parseFloat(document.getElementById('avail_wt_val')?.value) || 0;

    const issuePcs = parseInt(document.getElementById('issue_pcs')?.value) || 0;
    const issueWt = parseFloat(document.getElementById('issue_weight')?.value) || 0;

    const balPcs = Math.max(0, availPcs - issuePcs);
    const balWt = Math.max(0, Math.round((availWt - issueWt) * 1000) / 1000);

    const bpEl = document.getElementById('balance_pcs_preview');
    const bwEl = document.getElementById('balance_wt_preview');

    if (bpEl) bpEl.value = balPcs + ' Pcs';
    if (bwEl) bwEl.value = balWt.toFixed(3) + ' ct';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
