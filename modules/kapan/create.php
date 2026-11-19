<?php
/**
 * Create Multiple Kapans & Merge Rough Packets (સેક્શન ૬.૩ Units & Rough Cost)
 * Diamond ERP - Advanced Enterprise Multi-Kapan & Rough Merging Architecture
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

// Fetch available rough packets (assort parts & direct rough purchases)
$availableRoughPackets = get_available_rough_packets();

// Pre-selected part if coming from Assort module
$preselectedPartId = (int)($_GET['part_id'] ?? 0);
$preselectedPartKey = ($preselectedPartId > 0) ? 'part_' . $preselectedPartId : '';

// Process Form Submission
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    validate_csrf();

    $submittedKapans = $_POST['kapans'] ?? [];
    $autoPackets = isset($_POST['auto_generate_packets']);

    $errors = [];
    $validKapans = [];
    $globallyAssignedPackets = [];

    if (empty($submittedKapans)) {
        $errors[] = "ઓછામાં ઓછો એક Kapan ઉમેરવો ફરજિયાત છે.";
    }

    // Packet lookup map for quick validation & server-side recalculation
    $packetLookup = [];
    foreach ($availableRoughPackets as $p) {
        $packetLookup[$p['key']] = $p;
    }

    foreach ($submittedKapans as $idx => $kData) {
        $rowNum = $idx + 1;
        $kName = trim($kData['name'] ?? '');
        $kCode = trim($kData['code'] ?? '');
        $selectedPacketKeys = $kData['packets'] ?? [];

        if (is_string($selectedPacketKeys)) {
            $selectedPacketKeys = array_filter(array_map('trim', explode(',', $selectedPacketKeys)));
        }

        if (empty($kName)) {
            $errors[] = "લાઇન #$rowNum: કાપનનું નામ (Kapan Name) દાખલ કરવું ફરજિયાત છે.";
        }

        if (empty($selectedPacketKeys)) {
            $errors[] = "લાઇન #$rowNum: ઓછામાં ઓછો એક Rough Packet પસંદ કરવો ફરજિયાત છે.";
        }

        // Validate each packet
        $kapanPackets = [];
        $kapanCalcPcs = 0;
        $kapanCalcWeight = 0.000;
        $kapanCalcCost = 0.00;
        $roughNumbersList = [];

        foreach ($selectedPacketKeys as $pKey) {
            if (!isset($packetLookup[$pKey])) {
                $errors[] = "લાઇન #$rowNum: અમાન્ય અથવા અનુપલબ્ધ Rough Packet ($pKey) પસંદ કરેલ છે.";
                continue;
            }

            // Inventory Control: Check duplicate across all Kapans in this submission
            if (in_array($pKey, $globallyAssignedPackets, true)) {
                $errors[] = "લાઇન #$rowNum: Rough Packet '{$packetLookup[$pKey]['rough_number']}' એકથી વધુ કાપનમાં પસંદ કરેલ છે. એક પેકેટ માત્ર એક જ કાપનમાં ફાળવી શકાય છે.";
                continue;
            }

            $globallyAssignedPackets[] = $pKey;
            $pkt = $packetLookup[$pKey];
            $kapanPackets[] = $pkt;
            $kapanCalcPcs += $pkt['pcs'];
            $kapanCalcWeight = round($kapanCalcWeight + $pkt['weight'], 3);
            $kapanCalcCost = round($kapanCalcCost + $pkt['rough_cost'], 2);
            $roughNumbersList[] = $pkt['rough_number'];
        }

        // Allow user-specified override or fallback to calculated
        $finalPcs = isset($kData['pcs']) && (int)$kData['pcs'] > 0 ? (int)$kData['pcs'] : $kapanCalcPcs;
        $finalWeight = isset($kData['weight']) && (float)$kData['weight'] > 0 ? round((float)$kData['weight'], 3) : $kapanCalcWeight;
        $finalCost = isset($kData['cost']) && (float)$kData['cost'] > 0 ? round((float)$kData['cost'], 2) : $kapanCalcCost;

        if ($finalWeight <= 0) {
            $errors[] = "લાઇન #$rowNum: કાપનનું વજન શૂન્ય કરતા વધુ હોવું જોઈએ.";
        }

        $validKapans[] = [
            'name'          => $kName,
            'code'          => $kCode,
            'pcs'           => $finalPcs,
            'weight'        => $finalWeight,
            'rough_cost'    => $finalCost,
            'rough_numbers' => implode(', ', $roughNumbersList),
            'packets'       => $kapanPackets
        ];
    }

    if (!empty($errors)) {
        set_flash('error', implode('<br>', $errors));
    } else {
        try {
            $pdo->beginTransaction();

            $createdCount = 0;
            $createdCodes = [];

            $insertKapanStmt = $pdo->prepare("
                INSERT INTO kapans (
                    kapan_code, kapan_name, rough_numbers, rough_purchase_id, assort_part_id,
                    total_pcs, total_weight, rough_cost, total_cost, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");

            $insertMappingStmt = $pdo->prepare("
                INSERT INTO kapan_rough_mapping (
                    kapan_id, rough_packet_id, rough_packet_type, rough_number,
                    allocated_pcs, allocated_weight, allocated_cost
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $updatePartStmt = $pdo->prepare("UPDATE assort_parts SET status = 'in_kapan' WHERE id = ?");
            $updateRoughStmt = $pdo->prepare("UPDATE rough_purchases SET available_weight = GREATEST(0, available_weight - ?), available_pcs = GREATEST(0, available_pcs - ?) WHERE id = ?");


            foreach ($validKapans as $vk) {
                // Generate unique code if not provided
                $kCode = $vk['code'];
                if (empty($kCode)) {
                    $kCode = generate_next_code('KPN', 'kapans', 'kapan_code');
                }

                // Check code uniqueness
                $codeChk = $pdo->prepare("SELECT COUNT(*) FROM kapans WHERE kapan_code = ?");
                $codeChk->execute([$kCode]);
                if ($codeChk->fetchColumn() > 0) {
                    $kCode = generate_next_code('KPN', 'kapans', 'kapan_code');
                }

                // Primary legacy link (first packet) for backward compatibility
                $firstPkt = $vk['packets'][0] ?? null;
                $primaryPartId = ($firstPkt && $firstPkt['type'] === 'assort_part') ? $firstPkt['id'] : null;
                $primaryRoughId = ($firstPkt && $firstPkt['type'] === 'rough_purchase') ? $firstPkt['id'] : null;

                $insertKapanStmt->execute([
                    $kCode,
                    $vk['name'],
                    $vk['rough_numbers'],
                    $primaryRoughId,
                    $primaryPartId,
                    $vk['pcs'],
                    $vk['weight'],
                    $vk['rough_cost'],
                    $vk['rough_cost']
                ]);
                $kapanId = (int)$pdo->lastInsertId();

                // Insert Rough Mapping & Reserve Inventory
                foreach ($vk['packets'] as $p) {
                    $insertMappingStmt->execute([
                        $kapanId,
                        $p['id'],
                        $p['type'],
                        $p['rough_number'],
                        $p['pcs'],
                        $p['weight'],
                        $p['rough_cost']
                    ]);

                    if ($p['type'] === 'assort_part') {
                        $updatePartStmt->execute([$p['id']]);
                    } elseif ($p['type'] === 'rough_purchase') {
                        $updateRoughStmt->execute([$p['weight'], $p['pcs'], $p['id']]);
                    }
                }

                // Auto-generate individual 1 PC diamond records with hierarchical numbering (e.g. 01.1.1 .. 01.1.30)
                // Rule: 1 PC = 1 Individual Diamond Record, exact weight reconciliation, no duplicate numbers
                generate_kapan_diamonds_from_rough_packets($kapanId, $kCode, $vk['packets']);

                $createdCount++;
                $createdCodes[] = "{$vk['name']} ($kCode)";
            }

            $pdo->commit();

            log_activity(
                'Multiple Kapans Created',
                'Kapan',
                $kapanId ?? 0,
                "Created $createdCount Kapans: " . implode(', ', $createdCodes)
            );

            set_flash('success', "સફળતાપૂર્વક $createdCount કાપન બની ગયા છે: " . implode(', ', $createdCodes));
            header('Location: ' . BASE_URL . '/modules/kapan/index.php');
            exit;
        } catch (\Throwable $ex) {
            $pdo->rollBack();
            set_flash('error', 'કાપન સેવ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = 'નવા Kapan બનાવો & Rough Merging (Units & Rough Cost)';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Page Header -->
<?= render_page_header(
    'નવા Kapan બનાવો & Rough Packet Merging (Units & Rough Cost)',
    '',
    'boxes-stacked',
    '<a href="' . BASE_URL . '/modules/kapan/index.php" class="btn btn-outline-secondary btn-sm px-3">
        ' . svg_icon('arrow-left', 'me-1') . 'Kapan યાદી પર પાછા જાઓ
    </a>',
    'primary'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-12">
        <form action="<?= BASE_URL ?>/modules/kapan/create.php" method="POST" id="multiKapanForm" novalidate>
            <?= csrf_field() ?>

            <!-- Available Rough Inventory Overview Bar -->
            <div class="card-erp p-3 mb-4" style="background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 16px;">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <div class="d-flex align-items-center gap-2">
                        <?= svg_icon('gem', 'text-primary fs-5', 17) ?>
                        <span class="fw-bold text-dark fs-6">ઉપલબ્ધ Rough પેકેટ્સ (Available Rough Packets for Merging)</span>
                        <span class="badge bg-primary-subtle text-primary border font-mono"><?= count($availableRoughPackets) ?> પેકેટ્સ ઉપલબ્ધ</span>
                    </div>
                    <div class="small text-muted">
                        <?= svg_icon('info-circle', 'text-primary me-1') ?>
                        તમે એક જ કાપનમાં એક અથવા વધુ Rough પેકેટ્સ મર્જ કરી શકો છો.
                    </div>
                </div>

                <?php if (empty($availableRoughPackets)): ?>
                    <div class="alert alert-warning mb-0 py-2.5 px-3 small">
                        <?= svg_icon('triangle-exclamation', 'me-1') ?>
                        હાલમાં કોઈ ઉપલબ્ધ Rough Packet નથી. કૃપા કરીને Assort મોડ્યુલમાંથી નવો ભાગ બનાવો અથવા નવી Rough ખરીદી નોંધો.
                    </div>
                <?php else: ?>
                    <div class="d-flex flex-wrap gap-2 pt-1">
                        <?php foreach ($availableRoughPackets as $ap): ?>
                            <div class="px-2.5 py-1.5 rounded-3 border bg-light small font-mono d-flex align-items-center gap-2">
                                <span class="badge bg-secondary-subtle text-secondary border"><?= e($ap['rough_number']) ?></span>
                                <span class="fw-bold text-dark"><?= format_carat($ap['weight']) ?></span>
                                <span class="text-muted">(<?= (int)$ap['pcs'] ?> Pcs)</span>
                                <span class="text-primary fw-semibold"><?= format_inr($ap['rough_cost']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- CARD: UNITS & ROUGH COST - MULTIPLE KAPAN & ROUGH MERGING TABLE -->
            <div class="card-erp mb-4">
                <div class="card-header bg-white py-3 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <span class="fw-bold text-dark fs-6">
                            <?= svg_icon('table-cells', 'text-primary me-2') ?>Units & Rough Cost (કાપન અને પડતર વિભાજન)
                        </span>
                        
                    </div>
                    <div>
                        <button type="button" class="btn btn-primary btn-sm px-3 fw-bold shadow-sm" id="addKapanRowBtn">
                            <?= svg_icon('plus', 'me-1') ?>નવો Kapan ઉમેરો (Add Kapan)
                        </button>
                    </div>
                </div>
                <div class="card-body p-3">
                    <div class="table-responsive mb-3" style="min-height: 180px;">
                        <table class="table table-bordered align-middle mb-0" id="kapansAllocationTable" style="min-width: 800px; width: 100%;">
                            <colgroup>
                                <col style="width: 18%;">
                                <col style="width: 32%;">
                                <col style="width: 12%;">
                                <col style="width: 14%;">
                                <col style="width: 16%;">
                                <col style="width: 8%;">
                            </colgroup>
                            <thead class="table-light">
                                <tr>
                                    <th>Kapan Name (કાપનનું નામ) *</th>
                                    <th>Rough Number (સમાવિષ્ટ રફ પેકેટ્સ) *</th>
                                    <th class="text-center">Total Pcs *</th>
                                    <th class="text-end">Total Weight (Ct) *</th>
                                    <th class="text-end">Rough Cost (પડતર ₹) *</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="kapanTableBody">
                                <!-- Dynamic Kapan rows injected here -->
                            </tbody>
                            <tfoot class="table-light" style="background-color: #f8fafc; border-top: 2px solid #cbd5e1;">
                                <tr class="fw-bold">
                                    <td class="text-dark">
                                        <div class="d-flex align-items-center gap-1.5">
                                            <?= svg_icon('calculator', 'text-primary') ?>
                                            <span class="small">કુલ સમરી:</span>
                                            <span class="badge bg-primary rounded-pill font-mono" id="summary_kapan_count">0</span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="small text-muted fst-italic" id="summary_merged_note">બધા કાપનનું કુલ વિભાજન</span>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="text" id="summary_total_pcs" class="form-control font-mono fw-bold text-center bg-white" readonly value="0">
                                            <span class="input-group-text font-mono small">Pcs</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <input type="text" id="summary_total_weight" class="form-control font-mono fw-bold text-primary text-end bg-white" readonly value="0.000">
                                            <span class="input-group-text font-mono small text-primary fw-bold">Ct</span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text font-mono small">₹</span>
                                            <input type="text" id="summary_rough_cost" class="form-control font-mono fw-bold text-dark text-end bg-white" readonly value="0.00">
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-muted border small">Total</span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Auto Individual Diamonds Generation (1 PC = 1 Diamond Record) -->
                    <div class="card-erp p-3 mb-3 bg-light border rounded-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-primary px-2.5 py-1.5"><?= svg_icon('gem', 'me-1') ?>૧ PC = ૧ ડાયમંડ રેકોર્ડ</span>
                            <strong class="text-dark">સ્વયંસંચાલિત હાયરાર્કીકલ ડાયમંડ જનરેશન (Hierarchical Diamond Numbering)</strong>
                        </div>
                        <small class="text-muted d-block mt-1">
                            પસંદ કરેલ Assort પેકેટના કુલ નંગ (Pcs) મુજબ દરેક ડાયમંડ માટે અલગ યુનિક રેકોર્ડ બનશે (દા.ત. Assort 01.1 &rarr; 01.1.1, 01.1.2 ... 01.1.30). વજન ચોકસાઈપૂર્વક વિભાજિત થશે.
                        </small>
                    </div>

                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 pt-2 border-top">
                        <div class="text-muted small">
                            <?= svg_icon('shield-halved', 'text-primary me-1') ?>
                            ઇન્વેન્ટરી કંટ્રોલ: એક પેકેટ એક જ કાપનમાં ફાળવી શકાશે; સેવ થતાં પેકેટની સ્થિતિ 'In Kapan' તરીકે રિઝર્વ થશે.
                        </div>
                        <div class="d-flex gap-2">
                            <a href="<?= BASE_URL ?>/modules/kapan/index.php" class="btn btn-outline-secondary px-4">રદ કરો</a>
                            <button type="submit" id="submit_kapan_btn" class="btn btn-primary px-4 fw-bold shadow-sm">
                                <?= svg_icon('check-double', 'me-1') ?>બધા Kapan સેવ કરો & આગળ વધો
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript for Dynamic Multiple Kapan Creation & Rough Merging -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const availablePackets = <?= json_encode($availableRoughPackets, JSON_UNESCAPED_UNICODE) ?>;
    const preselectedKey = <?= json_encode($preselectedPartKey) ?>;

    const tbody = document.getElementById('kapanTableBody');
    const addRowBtn = document.getElementById('addKapanRowBtn');
    const submitBtn = document.getElementById('submit_kapan_btn');

    let kapanRowIndex = 0;

    // Helper: Build multi-select options for rough packets
    function getPacketOptionsHtml(selectedKeys = []) {
        let html = '';
        availablePackets.forEach(function (p) {
            const isSel = selectedKeys.includes(p.key) ? 'selected' : '';
            html += `<option value="${p.key}" ${isSel} data-pcs="${p.pcs}" data-weight="${p.weight}" data-cost="${p.rough_cost}" data-name="${p.rough_number}">
                ${p.display_text}
            </option>`;
        });
        return html;
    }

    // Add a Kapan Row
    function addKapanRow(defaultName = '', selectedPacketKeys = []) {
        const currentCount = tbody.querySelectorAll('.kapan-data-row').length + 1;
        const autoName = defaultName || `Kapan ${new Date().getFullYear()}-${String(currentCount).padStart(3, '0')}`;

        const tr = document.createElement('tr');
        tr.className = 'kapan-data-row';
        tr.dataset.rowIndex = kapanRowIndex;

        tr.innerHTML = `
            <td>
                <input type="text" name="kapans[${kapanRowIndex}][name]" class="form-control form-control-sm fw-bold kapan-name-input" value="${escapeHtml(autoName)}" placeholder="દા.ત. KAPAN-001" required>
                <div class="small text-muted mt-1 font-mono" style="font-size: 0.72rem;">Row #${currentCount}</div>
            </td>
            <td>
                <select name="kapans[${kapanRowIndex}][packets][]" multiple class="form-select select-packets-multi" required data-placeholder="Rough પેકેટ્સ પસંદ કરો...">
                    ${getPacketOptionsHtml(selectedPacketKeys)}
                </select>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" name="kapans[${kapanRowIndex}][pcs]" class="form-control font-mono fw-bold text-center kapan-pcs-input px-1" min="1" step="1" value="0" required oninput="recalcAllKapanTotals()">
                    <span class="input-group-text font-mono small px-1">Pcs</span>
                </div>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" step="0.01" min="0.001" name="kapans[${kapanRowIndex}][weight]" class="form-control font-mono fw-bold text-primary text-end kapan-weight-input px-1" value="0.000" required oninput="recalcAllKapanTotals()">
                    <span class="input-group-text font-mono small text-primary fw-bold px-1">Ct</span>
                </div>
            </td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text font-mono small px-1">₹</span>
                    <input type="number" step="0.01" min="0" name="kapans[${kapanRowIndex}][cost]" class="form-control font-mono fw-bold text-dark text-end kapan-cost-input px-1" value="0.00" required oninput="recalcAllKapanTotals()">
                </div>
            </td>
            <td class="text-center">
                <button type="button" class="btn btn-outline-danger btn-sm remove-kapan-btn p-1" title="આ કાપન હટાવો" onclick="removeKapanRow(this)">
                    ' + window.svgIcon('trash-can') + '
                </button>
            </td>
        `;

        tbody.appendChild(tr);

        const selectEl = tr.querySelector('.select-packets-multi');

        // Initialize TomSelect if available
        if (typeof TomSelect !== 'undefined') {
            try {
                const ts = new TomSelect(selectEl, {
                    plugins: ['remove_button'],
                    dropdownParent: 'body',
                    maxOptions: 500,
                    placeholder: 'Rough પેકેટ્સ પસંદ કરો...',
                    closeAfterSelect: false,
                    render: {
                        item: function (data, escape) {
                            const packet = availablePackets.find(p => p.key === data.value);
                            let label = data.text || '';
                            if (packet) {
                                label = (packet.part_name || packet.rough_number || 'Rough') + ' (' + packet.weight.toFixed(3) + ' ct)';
                            } else {
                                const match = label.match(/^([^\—\-]+)[\—\-]\s*([0-9\.]+\s*ct)/i);
                                if (match) label = match[1].trim() + ' (' + match[2].trim() + ')';
                            }
                            return '<div class="item badge bg-white text-dark border px-2 py-1 me-1 mb-1 font-mono small shadow-xs" style="font-size: 0.74rem;" title="' + escape(data.text || '') + '">' + escape(label) + '</div>';
                        },
                        option: function (data, escape) {
                            return '<div class="py-1 px-2 font-mono small text-dark">' + escape(data.text) + '</div>';
                        }
                    },
                    onChange: function (values) {
                        handlePacketSelectionChange(tr, values);
                    }
                });
                tr._tomselect = ts;
            } catch (e) {
                console.warn('TomSelect init fallback', e);
                selectEl.addEventListener('change', function () {
                    const values = Array.from(selectEl.selectedOptions).map(o => o.value);
                    handlePacketSelectionChange(tr, values);
                });
            }
        } else {
            selectEl.addEventListener('change', function () {
                const values = Array.from(selectEl.selectedOptions).map(o => o.value);
                handlePacketSelectionChange(tr, values);
            });
        }

        kapanRowIndex++;

        // Trigger calculation if pre-selected
        if (selectedPacketKeys.length > 0) {
            handlePacketSelectionChange(tr, selectedPacketKeys);
        } else {
            recalcAllKapanTotals();
        }
    }

    // Handle rough packets selection for a specific row
    function handlePacketSelectionChange(row, selectedKeys) {
        let totalPcs = 0;
        let totalWeight = 0.000;
        let totalCost = 0.00;

        const badgesContainer = row.querySelector('.selected-packets-badges');
        if (badgesContainer) badgesContainer.innerHTML = '';

        selectedKeys.forEach(function (key) {
            const packet = availablePackets.find(p => p.key === key);
            if (packet) {
                totalPcs += packet.pcs;
                totalWeight = Math.round((totalWeight + packet.weight) * 1000) / 1000;
                totalCost = Math.round((totalCost + packet.rough_cost) * 100) / 100;

                if (badgesContainer) {
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-light text-dark border font-mono';
                    badge.style.fontSize = '0.74rem';
                    badge.innerHTML = `${window.svgIcon('gem', 'text-primary me-1')}${escapeHtml(packet.rough_number)} (${packet.weight.toFixed(3)} ct)`;
                    badgesContainer.appendChild(badge);
                }
            }
        });

        const pcsInput = row.querySelector('.kapan-pcs-input');
        const weightInput = row.querySelector('.kapan-weight-input');
        const costInput = row.querySelector('.kapan-cost-input');

        if (pcsInput) {
            pcsInput.value = (totalPcs > 0) ? totalPcs : (parseInt(pcsInput.value) > 0 ? pcsInput.value : 1);
        }
        if (weightInput) weightInput.value = totalWeight.toFixed(3);
        if (costInput) costInput.value = totalCost.toFixed(2);

        recalcAllKapanTotals();
    }

    // Remove row
    window.removeKapanRow = function (btn) {
        const row = btn.closest('tr');
        if (row) {
            if (row._tomselect) {
                row._tomselect.destroy();
            }
            row.remove();
            recalcAllKapanTotals();
        }
    };

    // Recalculate summary row
    window.recalcAllKapanTotals = function () {
        const rows = tbody.querySelectorAll('.kapan-data-row');
        let grandPcs = 0;
        let grandWeight = 0.000;
        let grandCost = 0.00;

        rows.forEach(function (r) {
            const pcs = parseInt(r.querySelector('.kapan-pcs-input')?.value, 10) || 0;
            const w = parseFloat(r.querySelector('.kapan-weight-input')?.value) || 0.000;
            const c = parseFloat(r.querySelector('.kapan-cost-input')?.value) || 0.00;

            grandPcs += pcs;
            grandWeight = Math.round((grandWeight + w) * 1000) / 1000;
            grandCost = Math.round((grandCost + c) * 100) / 100;
        });

        document.getElementById('summary_kapan_count').innerText = rows.length;
        document.getElementById('summary_total_pcs').value = grandPcs;
        document.getElementById('summary_total_weight').value = grandWeight.toFixed(3);
        document.getElementById('summary_rough_cost').value = grandCost.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        if (submitBtn) {
            submitBtn.disabled = (rows.length === 0 || grandWeight <= 0);
        }
    };

    // Add Kapan Button Click
    if (addRowBtn) {
        addRowBtn.addEventListener('click', function () {
            addKapanRow();
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Initialize with 1 default row
    if (preselectedKey && availablePackets.some(p => p.key === preselectedKey)) {
        const pObj = availablePackets.find(p => p.key === preselectedKey);
        const name = pObj ? `Kapan ${pObj.rough_number}` : '';
        addKapanRow(name, [preselectedKey]);
    } else {
        addKapanRow();
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
