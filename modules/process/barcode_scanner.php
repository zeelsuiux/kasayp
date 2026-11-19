<?php
/**
 * Real-time Hardware Barcode Scanner & Rapid Bulk Stage Transfer
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'બારકોડ સ્કેનર (Barcode Scanner)';
require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
$scannedBarcode = trim($_GET['barcode'] ?? '');
$initialMode = (($_GET['mode'] ?? '') === 'rapid' && $scannedBarcode === '') ? 'rapid' : 'lookup';

$scannerStages = get_active_processing_stages();
$scannerParties = $pdo->query("SELECT id, party_name, party_type, city FROM parties ORDER BY party_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$packet = null;
$history = [];

if (!empty($scannedBarcode)) {
    $stmt = $pdo->prepare("SELECT p.*, k.kapan_code, k.kapan_name, pt.party_name as vendor_name
                           FROM packets p
                           JOIN kapans k ON p.kapan_id = k.id
                           LEFT JOIN parties pt ON p.assigned_party_id = pt.id
                           WHERE p.barcode = ? OR p.packet_no = ?");
    $stmt->execute([$scannedBarcode, $scannedBarcode]);
    $packet = $stmt->fetch();

    if ($packet) {
        $histStmt = $pdo->prepare("SELECT h.*, u.full_name as user_name, pt.party_name
                                   FROM packet_process_history h
                                   LEFT JOIN users u ON h.logged_by = u.id
                                   LEFT JOIN parties pt ON h.party_id = pt.id
                                   WHERE h.packet_id = ?
                                   ORDER BY h.id DESC");
        $histStmt->execute([$packet['id']]);
        $history = $histStmt->fetchAll();
    }
}
?>

<?= render_page_header(
    'બારકોડ સ્કેનર ટર્મિનલ',
    '',
    'barcode',
    '<a href="' . BASE_URL . '/modules/process/index.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">' . svg_icon('arrow-left', 'me-1', 13) . 'પ્રોસેસ બોર્ડ</a>'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-10">

        <!-- Scanner Mode Switcher Pills -->
        <div class="nav-segmented-tabs mb-3">
            <button type="button" id="tabBtnLookup" class="nav-segment-item border-0 bg-transparent <?= $initialMode === 'lookup' ? 'active' : '' ?>" onclick="switchScannerMode('lookup')">
                <?= svg_icon('magnifying-glass', '', 15) ?>
                <span>હીરો શોધો (Lookup)</span>
            </button>
            <button type="button" id="tabBtnRapid" class="nav-segment-item border-0 bg-transparent <?= $initialMode === 'rapid' ? 'active' : '' ?>" onclick="switchScannerMode('rapid')">
                <?= svg_icon('bolt', '', 15) ?>
                <span>સળંગ સ્કેન ટ્રાન્સફર (Rapid Bulk Scan)</span>
            </button>
        </div>

        <!-- ==================== MODE 1: LOOKUP MODE ==================== -->
        <div id="scannerLookupSection" style="display: <?= $initialMode === 'lookup' ? 'block' : 'none' ?>;">
            <div class="finora-card mb-4">
                <div class="finora-card-header d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                        <?= svg_icon('barcode', 'text-primary', 17) ?>
                        <span>બારકોડ સ્કેન કરી વિગત જુઓ</span>
                    </h6>
                </div>
                <div class="finora-card-body p-4 text-center">
                    <form action="<?= BASE_URL ?>/modules/process/barcode_scanner.php" method="GET" class="d-flex justify-content-center">
                        <div class="input-group" style="max-width: 550px;">
                            <span class="input-group-text bg-light border-end-0"><?= svg_icon('barcode', 'text-primary', 18) ?></span>
                            <input type="text" name="barcode" id="scanner_input" class="form-control form-control-lg fw-bold text-center border-start-0 font-mono"
                                   placeholder="અહીં બારકોડ સ્કેન કરો અથવા લખો..."
                                   value="<?= e($scannedBarcode) ?>" autocomplete="off">
                            <button type="submit" class="btn btn-primary px-4 fw-bold">શોધો</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (!empty($scannedBarcode) && !$packet): ?>
                <div class="alert alert-danger rounded-4 text-center py-4">
                    <div class="mb-2 text-danger"><?= svg_icon('triangle-exclamation', '', 28) ?></div>
                    <h5 class="fw-bold">બારકોડ '<?= e($scannedBarcode) ?>' મળ્યો નથી!</h5>
                    <p class="mb-0 text-muted">કૃપા કરીને બારકોડ નંબર ચકાસો અથવા Kapan માં પેકેટ છે કે નહીં તે તપાસો.</p>
                </div>
            <?php elseif ($packet): ?>
                <div class="finora-card mb-4">
                    <div class="finora-card-header d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="fw-bold text-dark mb-0"><?= e($packet['packet_no']) ?></h5>
                            <small class="text-muted">Barcode: <strong><?= e($packet['barcode']) ?></strong> &bull; Kapan: <?= e($packet['kapan_name']) ?></small>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="<?= BASE_URL ?>/modules/process/update_stage.php?id=<?= $packet['id'] ?>" class="btn btn-primary btn-sm rounded-pill px-3">
                                <?= svg_icon('arrow-right-arrow-left', 'me-1', 14) ?>સ્ટેજ અપડેટ કરો &rarr;
                            </a>
                            <a href="<?= BASE_URL ?>/modules/kapan/print_barcode.php?packet_id=<?= $packet['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm rounded-pill">
                                <?= svg_icon('print', '', 14) ?>
                            </a>
                        </div>
                    </div>

                    <div class="finora-card-body">
                        <div class="row g-3 mb-4">
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded-4 text-center">
                                    <small class="text-muted d-block">રફ વજન</small>
                                    <strong class="fs-5 text-primary"><?= format_carat($packet['weight']) ?></strong>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded-4 text-center">
                                    <small class="text-muted d-block">હાલનું સ્ટેજ</small>
                                    <span class="finora-pill finora-pill-blue text-uppercase mt-1"><?= e($packet['current_stage']) ?></span>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded-4 text-center">
                                    <small class="text-muted d-block">હાલની જગ્યા</small>
                                    <strong class="fs-6 text-danger"><?= svg_icon('location-dot', 'me-1', 14) ?><?= e($packet['current_location']) ?></strong>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="p-3 bg-light rounded-4 text-center">
                                    <small class="text-muted d-block">Polish વજન</small>
                                    <strong class="fs-5 text-success"><?= ((float)$packet['polish_weight'] > 0) ? format_carat($packet['polish_weight']) : '-' ?></strong>
                                </div>
                            </div>
                        </div>

                        <div class="text-center p-3 border rounded-4 mb-4 bg-white">
                            <?= render_svg_barcode($packet['barcode'], 55) ?>
                        </div>

                        <h6 class="fw-bold text-dark mb-3 d-flex align-items-center gap-2">
                            <?= svg_icon('timeline', 'text-primary', 16) ?>
                            <span>પેકેટનો પ્રવાસ (Process Trail)</span>
                        </h6>
                        <div class="table-responsive">
                            <table class="table table-diamond mb-0">
                                <thead>
                                    <tr>
                                        <th>તારીખ / સમય</th>
                                        <th>સ્ટેજ</th>
                                        <th>વેન્ડર / પાર્ટી</th>
                                        <th>વજન</th>
                                        <th>વિગત / નોંધ</th>
                                        <th>યુઝર</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($history)): ?>
                                        <tr><td colspan="6" class="text-center py-3 text-muted">હજુ કોઈ સ્ટેજ હિસ્ટ્રી નોંધાઈ નથી.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($history as $h): ?>
                                            <tr>
                                                <td><?= date('d-m-Y H:i', strtotime($h['created_at'])) ?></td>
                                                <td><span class="finora-pill finora-pill-slate text-uppercase"><?= e($h['stage']) ?></span></td>
                                                <td><?= e($h['party_name'] ?? '-') ?></td>
                                                <td><?= format_carat($h['output_weight']) ?></td>
                                                <td><?= e($h['details'] ?? '-') ?></td>
                                                <td><small class="text-muted"><?= e($h['user_name'] ?? 'System') ?></small></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ==================== MODE 2: RAPID CONTINUOUS SCAN TRANSFER ==================== -->
        <div id="scannerRapidSection" style="display: <?= $initialMode === 'rapid' ? 'block' : 'none' ?>;">
            <input type="hidden" id="rapid_csrf" value="<?= get_csrf_token() ?>">

            <div class="finora-card mb-4">
                <div class="finora-card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                        <?= svg_icon('bolt', 'text-primary', 17) ?>
                        <span>સળંગ બારકોડ સ્કેન ટ્રાન્સફર (૧ વાર સેટિંગ → સતત સ્કેન)</span>
                    </h6>
                    <div class="d-flex align-items-center gap-2 font-mono small">
                        <span class="finora-pill finora-pill-blue" id="rapid_stat_count">0 સ્કેન</span>
                        <span class="finora-pill finora-pill-green" id="rapid_stat_weight">0.000 ct</span>
                    </div>
                </div>
                <div class="finora-card-body p-4">
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">ટાર્ગેટ સ્ટેજ *</label>
                            <select id="rapid_target_stage" class="form-select fw-semibold">
                                <option value="__NEXT__">→ આગળનું સ્ટેજ (Auto Next Stage)</option>
                                <?php foreach ($scannerStages as $idx => $stg): ?>
                                    <option value="<?= e($stg['stage_code']) ?>">
                                        <?= ($idx + 1) ?>. <?= e($stg['stage_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">કારખાનું / પાર્ટી</label>
                            <select id="rapid_party_id" class="form-select tom-select-search">
                                <option value="0">હાલનું જ રાખો (Keep Current)</option>
                                <?php foreach ($scannerParties as $sp): ?>
                                    <option value="<?= (int)$sp['id'] ?>">
                                        <?= e($sp['party_name']) ?><?= !empty($sp['city']) ? ' (' . e($sp['city']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-muted">સ્થળ</label>
                            <select id="rapid_location" class="form-select">
                                <option value="">આપોઆપ (Auto)</option>
                                <option value="Surat">Surat</option>
                                <option value="Mumbai">Mumbai</option>
                                <option value="Galaxy">Galaxy</option>
                                <option value="LS">LS</option>
                                <option value="Katargam Karkhanu">Katargam</option>
                                <option value="GIA">GIA</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-muted">નોંધ (Optional)</label>
                            <input type="text" id="rapid_remarks" class="form-control" placeholder="બેચ / રિમાર્ક્સ...">
                        </div>
                    </div>

                    <div class="p-4 rounded-4 bg-light border text-center">
                        <form id="rapidScanForm" onsubmit="event.preventDefault(); submitRapidBarcodeScan();" class="d-flex justify-content-center">
                            <div class="input-group" style="max-width: 580px;">
                                <span class="input-group-text bg-white border-end-0"><?= svg_icon('barcode', 'text-primary', 20) ?></span>
                                <input type="text" id="rapid_barcode_input" class="form-control form-control-lg fw-bold text-center border-start-0 font-mono"
                                       placeholder="સતત બારકોડ સ્કેન કરતા જાઓ (Enter આપોઆપ થશે)..." autocomplete="off" style="font-size: 1.15rem; min-height: 48px;">
                                <button type="submit" id="rapid_scan_btn" class="btn btn-primary px-4 fw-bold scanner-btn-touch">ટ્રાન્સફર</button>
                            </div>
                        </form>
                        <div id="rapid_status_alert" class="mt-3 mx-auto" style="max-width: 580px; display: none;"></div>
                    </div>
                </div>
            </div>

            <!-- Last 5 Scans & Undo Component (છેલ્લા ૫ સ્કેન અને અનડૂ) -->
            <div class="finora-card mb-4 border">
                <div class="finora-card-header d-flex justify-content-between align-items-center bg-white border-bottom py-2.5 px-3">
                    <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                        <?= svg_icon('rotate-left', 'text-warning', 16) ?>
                        <span>છેલ્લા ૫ સ્કેન અને તાત્કાલિક અનડૂ (Recent 5 Scans & Undo)</span>
                    </h6>
                    <span class="badge bg-light text-muted border font-mono small" id="undo_badge_count">0/5 રેકોર્ડ</span>
                </div>
                <div class="finora-card-body p-3">
                    <div id="last5ScansList" class="d-flex flex-column gap-2">
                        <div class="text-center py-3 text-muted small" id="last5_empty_msg">
                            હજુ કોઈ હીરા સ્કેન થયા નથી. સ્કેન કર્યા બાદ અહીંથી છેલ્લું સ્કેન પાછું ખેંચી (Undo કરી) શકાશે.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Live Rapid Scan Transfer Log Table -->
            <div class="finora-card mb-4">
                <div class="finora-card-header d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                        <?= svg_icon('list-check', 'text-primary', 16) ?>
                        <span>આ સેશનમાં ટ્રાન્સફર થયેલ હીરા</span>
                    </h6>
                    <button type="button" class="btn btn-sm btn-light border rounded-pill px-3 text-secondary" onclick="clearRapidLog()">ક્લિયર લિસ્ટ</button>
                </div>
                <div class="finora-card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-diamond mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-4">#</th>
                                    <th>પેકેટ / હીરા નં.</th>
                                    <th>બારકોડ</th>
                                    <th>સ્ટેજ ફેરફાર</th>
                                    <th class="text-end">વજન (Ct)</th>
                                    <th>પાર્ટી / સ્થળ</th>
                                    <th class="pe-4">સમય</th>
                                </tr>
                            </thead>
                            <tbody id="rapid_log_tbody">
                                <tr id="rapid_empty_row">
                                    <td colspan="7" class="text-center py-4 text-muted">ઉપર સ્ટેજ પસંદ કરીને બારકોડ સ્કેન કરવાની શરૂઆત કરો.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// Web Audio API Hardware Scanner Audio Feedback
function playSound(type) {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        const ctx = new AudioCtx();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);

        if (type === 'success') {
            osc.type = 'sine';
            osc.frequency.setValueAtTime(987.77, ctx.currentTime);
            gain.gain.setValueAtTime(0.18, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.18);
            osc.start();
            osc.stop(ctx.currentTime + 0.18);
        } else {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(196.00, ctx.currentTime);
            gain.gain.setValueAtTime(0.25, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.35);
            osc.start();
            osc.stop(ctx.currentTime + 0.35);
        }
    } catch (e) {}
}

function switchScannerMode(mode) {
    const isRapid = (mode === 'rapid');
    document.getElementById('scannerLookupSection').style.display = isRapid ? 'none' : 'block';
    document.getElementById('scannerRapidSection').style.display = isRapid ? 'block' : 'none';
    document.getElementById('tabBtnLookup').classList.toggle('active', !isRapid);
    document.getElementById('tabBtnRapid').classList.toggle('active', isRapid);

    const targetInput = document.getElementById(isRapid ? 'rapid_barcode_input' : 'scanner_input');
    if (targetInput) {
        setTimeout(() => {
            targetInput.focus();
            targetInput.select();
        }, 60);
    }
}

let rapidScanCount = 0;
let rapidScanWeight = 0;
let rapidScanBusy = false;

function escapeScannerHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function showRapidAlert(isOk, text) {
    const el = document.getElementById('rapid_status_alert');
    if (!el) return;
    el.style.display = 'block';
    el.className = 'mt-3 mx-auto alert rounded-4 py-2 px-3 small fw-semibold mb-0 ' + (isOk ? 'alert-success' : 'alert-danger');
    el.textContent = text;
}

function submitRapidBarcodeScan() {
    const input = document.getElementById('rapid_barcode_input');
    if (!input || rapidScanBusy) return;
    const code = input.value.trim();
    if (!code) return;

    const targetStage = document.getElementById('rapid_target_stage').value;
    const partyId = document.getElementById('rapid_party_id').value || '0';
    const location = document.getElementById('rapid_location').value;
    const remarks = document.getElementById('rapid_remarks').value.trim();
    const csrfToken = document.getElementById('rapid_csrf').value;

    rapidScanBusy = true;
    input.value = '';

    const postData = new URLSearchParams();
    postData.append('csrf_token', csrfToken);
    postData.append('barcode', code);
    postData.append('target_stage', targetStage);
    if (targetStage === '__NEXT__') {
        postData.append('action_mode', 'next_stage');
    }
    postData.append('assigned_party_id', partyId);
    postData.append('current_location', location);
    postData.append('remarks', remarks);

    fetch('<?= BASE_URL ?>/modules/process/ajax_update_stage.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: postData.toString()
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && (data.updated_count || 0) > 0 && Array.isArray(data.updated_packets) && data.updated_packets[0]) {
            playSound('success');
            const p = data.updated_packets[0];
            rapidScanCount++;
            rapidScanWeight += (parseFloat(p.weight) || 0);
            document.getElementById('rapid_stat_count').textContent = rapidScanCount + ' સ્કેન';
            document.getElementById('rapid_stat_weight').textContent = rapidScanWeight.toFixed(3) + ' ct';
            showRapidAlert(true, data.message || `${p.packet_no} ટ્રાન્સફર થયું`);

            const nowTime = new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

            // Record into Last 5 Scans for Instant Undo
            recordScanForUndo({
                barcode: p.barcode || code,
                packet_no: p.packet_no,
                old_stage: p.old_stage,
                new_stage: p.new_stage_title || p.new_stage,
                weight: p.weight,
                old_party_id: p.old_party_id || '0',
                old_location: p.old_location || 'Surat',
                time: nowTime
            });

            const emptyRow = document.getElementById('rapid_empty_row');
            if (emptyRow) emptyRow.remove();

            const tbody = document.getElementById('rapid_log_tbody');
            if (tbody) {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="ps-4 text-muted font-mono small">${rapidScanCount}</td>
                    <td class="font-mono fw-bold text-dark">${escapeScannerHtml(p.packet_no)}</td>
                    <td class="font-mono small text-muted">${escapeScannerHtml(p.barcode || code)}</td>
                    <td>
                        <span class="finora-pill finora-pill-slate font-mono">${escapeScannerHtml(p.old_stage)}</span>
                        ${window.svgIcon ? window.svgIcon('arrow-right', 'text-muted mx-1', 12) : '->'}
                        <span class="finora-pill finora-pill-green font-mono">${escapeScannerHtml(p.new_stage_title || p.new_stage)}</span>
                    </td>
                    <td class="text-end font-mono fw-bold text-primary">${(parseFloat(p.weight) || 0).toFixed(3)} ct</td>
                    <td class="small">${escapeScannerHtml(p.party_name || 'ઇન-હાઉસ')} &bull; <span class="text-muted">${escapeScannerHtml(p.new_location || 'Surat')}</span></td>
                    <td class="pe-4 font-mono small text-muted">${nowTime}</td>
                `;
                tbody.prepend(tr);
                if (typeof window.autoLabelResponsiveTables === 'function') {
                    window.autoLabelResponsiveTables();
                }
            }
        } else if (data.success) {
            playSound('success');
            showRapidAlert(true, data.message || `${code}: પહેલેથી આ સ્ટેજમાં છે.`);
        } else {
            playSound('error');
            showRapidAlert(false, data.message || `${code}: ટ્રાન્સફર નિષ્ફળ ગયું.`);
        }
    })
    .catch(err => {
        playSound('error');
        showRapidAlert(false, 'સર્વર ક્ષતિ: ' + err.message);
    })
    .finally(() => {
        rapidScanBusy = false;
        input.focus();
    });
}

// ----------------------------------------------------------------------
// Last 5 Scans & Undo System
// ----------------------------------------------------------------------
let last5Scans = [];

function recordScanForUndo(item) {
    last5Scans.unshift(item);
    if (last5Scans.length > 5) {
        last5Scans.pop();
    }
    renderLast5Scans();
}

function renderLast5Scans() {
    const listEl = document.getElementById('last5ScansList');
    const badgeEl = document.getElementById('undo_badge_count');
    if (!listEl) return;
    if (badgeEl) badgeEl.textContent = `${last5Scans.length}/5 રેકોર્ડ`;

    if (last5Scans.length === 0) {
        listEl.innerHTML = '<div class="text-center py-3 text-muted small" id="last5_empty_msg">હજુ કોઈ હીરા સ્કેન થયા નથી. સ્કેન કર્યા બાદ અહીંથી છેલ્લું સ્કેન પાછું ખેંચી (Undo કરી) શકાશે.</div>';
        return;
    }

    listEl.innerHTML = '';
    last5Scans.forEach((item, idx) => {
        const row = document.createElement('div');
        row.className = 'd-flex flex-wrap align-items-center justify-content-between p-2.5 rounded-3 bg-light border gap-2';
        row.innerHTML = `
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="badge bg-secondary-subtle text-secondary font-mono">${idx + 1}</span>
                <strong class="font-mono text-dark">${escapeScannerHtml(item.packet_no)}</strong>
                <span class="text-muted small">(${escapeScannerHtml(item.barcode)})</span>
                <span class="finora-pill finora-pill-slate font-mono">${escapeScannerHtml(item.old_stage)}</span>
                <span class="text-muted">&rarr;</span>
                <span class="finora-pill finora-pill-green font-mono">${escapeScannerHtml(item.new_stage)}</span>
                <span class="font-mono text-primary fw-bold small">${(parseFloat(item.weight) || 0).toFixed(3)} ct</span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger scanner-btn-touch d-inline-flex align-items-center gap-1" onclick="undoLastScan(${idx})" title="આ સ્કેન રદ કરી જૂના સ્ટેજમાં પાછું લો">
                <span>અનડૂ (Undo)</span>
            </button>
        `;
        listEl.appendChild(row);
    });
}

function undoLastScan(idx) {
    if (!last5Scans[idx]) return;
    const item = last5Scans[idx];
    if (!confirm(`શું તમે હીરો ${item.packet_no} નું સ્ટેજ પાછું '${item.old_stage}' કરવા માંગો છો?`)) {
        return;
    }

    const csrfToken = document.getElementById('rapid_csrf').value;
    const postData = new URLSearchParams();
    postData.append('csrf_token', csrfToken);
    postData.append('barcode', item.barcode);
    postData.append('target_stage', item.old_stage);
    postData.append('assigned_party_id', item.old_party_id || '0');
    postData.append('current_location', item.old_location || 'Surat');
    postData.append('remarks', 'Undo from Barcode Terminal');

    fetch('<?= BASE_URL ?>/modules/process/ajax_update_stage.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: postData.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            playSound('success');
            showRapidAlert(true, `${item.packet_no} પાછું '${item.old_stage}' સ્ટેજમાં લેવાઈ ગયું (Undo Completed)`);
            last5Scans.splice(idx, 1);
            renderLast5Scans();
        } else {
            playSound('error');
            showRapidAlert(false, 'Undo નિષ્ફળ: ' + (data.message || 'અજ્ઞાત ક્ષતિ'));
        }
    })
    .catch(err => {
        playSound('error');
        showRapidAlert(false, 'Undo સર્વર ક્ષતિ: ' + err.message);
    });
}

function clearRapidLog() {
    rapidScanCount = 0;
    rapidScanWeight = 0;
    document.getElementById('rapid_stat_count').textContent = '0 સ્કેન';
    document.getElementById('rapid_stat_weight').textContent = '0.000 ct';
    document.getElementById('rapid_status_alert').style.display = 'none';
    const tbody = document.getElementById('rapid_log_tbody');
    if (tbody) {
        tbody.innerHTML = '<tr id="rapid_empty_row"><td colspan="7" class="text-center py-4 text-muted">ઉપર સ્ટેજ પસંદ કરીને બારકોડ સ્કેન કરવાની શરૂઆત કરો.</td></tr>';
    }
    last5Scans = [];
    renderLast5Scans();
    const input = document.getElementById('rapid_barcode_input');
    if (input) input.focus();
}

// Continuous Autofocus Retention (Ensures Factory Barcode Scanner is Always Ready)
function setupContinuousFocus() {
    ['scanner_input', 'rapid_barcode_input'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('blur', () => {
            setTimeout(() => {
                const act = document.activeElement;
                if (act && (act.tagName === 'INPUT' || act.tagName === 'SELECT' || act.tagName === 'TEXTAREA' || act.tagName === 'BUTTON')) {
                    return;
                }
                const activeMode = (document.getElementById('scannerRapidSection').style.display === 'block') ? 'rapid' : 'lookup';
                const targetInput = document.getElementById(activeMode === 'rapid' ? 'rapid_barcode_input' : 'scanner_input');
                if (targetInput) targetInput.focus();
            }, 180);
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    const mode = '<?= $initialMode ?>';
    const input = document.getElementById(mode === 'rapid' ? 'rapid_barcode_input' : 'scanner_input');
    if (input) {
        input.focus();
        input.select();
    }
    setupContinuousFocus();

    <?php if (!empty($scannedBarcode)): ?>
        <?php if ($packet): ?>
            playSound('success');
        <?php else: ?>
            playSound('error');
        <?php endif; ?>
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
