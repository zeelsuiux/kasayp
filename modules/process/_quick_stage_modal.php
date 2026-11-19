<?php
/**
 * Quick Stage & Factory Transfer Modal
 * Diamond ERP - Designed by Senior UI/UX & Frontend Architecture
 * 
 * Reusable modal for updating diamond stage and assigning factory/workshop
 * with real-time interactive search and instant visual feedback.
 */

declare(strict_types=1);

if (!isset($modalParties)) {
    $pdoModal = $pdo ?? getDbConnection();
    $modalParties = $pdoModal->query("SELECT id, party_name, party_type, city FROM parties WHERE (status != 'blocked' OR status IS NULL) ORDER BY party_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

if (!isset($modalStagesList)) {
    $modalStagesList = get_active_processing_stages();
}
// Ensure Polish Return is present in modal target stage dropdown
$hasPolishReturnInModal = false;
foreach ($modalStagesList as $stg) {
    if (($stg['stage_code'] ?? '') === 'polish_return') {
        $hasPolishReturnInModal = true;
        break;
    }
}
if (!$hasPolishReturnInModal) {
    $newModalStages = [];
    $insertedPr = false;
    foreach ($modalStagesList as $stg) {
        if (($stg['stage_code'] ?? '') === 'polish_stock' && !$insertedPr) {
            $newModalStages[] = [
                'stage_code' => 'polish_return',
                'stage_name' => 'Polish Return (બોઇલ રિટર્ન / Re-Assort)',
                'default_location' => 'Office Safe / Surat',
                'color_code' => '#4f46e5'
            ];
            $insertedPr = true;
        }
        $newModalStages[] = $stg;
    }
    if (!$insertedPr) {
        $newModalStages[] = [
            'stage_code' => 'polish_return',
            'stage_name' => 'Polish Return (બોઇલ રિટર્ન / Re-Assort)',
            'default_location' => 'Office Safe / Surat',
            'color_code' => '#4f46e5'
        ];
    }
    $modalStagesList = $newModalStages;
}
?>

<!-- Quick Stage & Factory Transfer Modal -->
<div class="modal fade" id="quickStageModal" tabindex="-1" aria-labelledby="quickStageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 600px;">
        <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
            
            <!-- Modal Header (Clean Finora) -->
            <div class="modal-header px-4 py-3 border-bottom bg-white">
                <div class="d-flex align-items-center gap-2.5">
                    <div class="kpi-icon-tile kpi-icon-blue" style="width: 36px; height: 36px; border-radius: 10px;">
                        <?= svg_icon('arrows-split-up-and-left', '', 18) ?>
                    </div>
                    <h6 class="modal-title fw-bold mb-0 text-dark" id="quickStageModalLabel">સ્ટેજ & કારખાનું ટ્રાન્સફર</h6>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-4 bg-white">
                
                <!-- Diamond Summary Card -->
                <div class="p-3 rounded-4 mb-3 border bg-light-subtle d-flex flex-wrap justify-content-between align-items-center gap-2" 
                     style="background: #f8fafc;">
                    <div>
                        <span class="text-secondary small d-block">પેકેટ / હીરા નં.</span>
                        <div class="font-mono fw-bold text-dark d-flex align-items-center gap-1.5 mt-0.5" id="qmodal_packet_no" style="font-size: 1.05rem;">-</div>
                        <span class="small text-muted font-mono" id="qmodal_barcode">-</span>
                    </div>
                    <div class="text-center px-2">
                        <span class="text-secondary small d-block">હાજર વજન</span>
                        <div class="font-mono fw-bold text-success d-flex align-items-center justify-content-center gap-1 mt-0.5" style="font-size: 1.15rem;">
                            <?= svg_icon('gem', 'text-success', 15) ?>
                            <span id="qmodal_weight">0.000 ct</span>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="text-secondary small d-block">હાલનું સ્ટેજ</span>
                        <div class="mt-1">
                            <span class="finora-pill finora-pill-blue font-mono" id="qmodal_cur_stage_badge">-</span>
                        </div>
                    </div>
                </div>

                <form id="quickStageModalForm" onsubmit="event.preventDefault(); submitQuickStageUpdate();">
                    <input type="hidden" id="qmodal_packet_id" value="0">
                    <input type="hidden" id="qmodal_packet_ids" value="">
                    <input type="hidden" id="qmodal_csrf" value="<?= get_csrf_token() ?>">
                    <input type="hidden" id="qmodal_party_select" value="">
                    <input type="hidden" id="qmodal_current_mode" value="transfer">

                    <!-- Boil Return Action Selector Cards (Visible ONLY for Boil Return Packets) -->
                    <div id="qmodal_boil_decision_cards" class="row g-2 mb-3" style="display: none;">
                        <div class="col-6">
                            <div id="qmodal_card_stock" 
                                 class="p-2.5 rounded-3 border text-center transition-all h-100" 
                                 style="cursor: pointer; border-width: 2px !important; border-color: #22c55e !important; background: #f0fdf4;" 
                                 onclick="setBoilActionChoice('polish_stock')">
                                <div class="text-success mb-1"><?= svg_icon('gem', '', 20) ?></div>
                                <div class="fw-bold text-dark small">Polish Stock માં જમા</div>
                                <small class="text-muted d-block" style="font-size: 0.70rem;">હીરો તૈયાર છે (ઓફિસ સેફ)</small>
                                <span class="badge bg-success text-white rounded-pill mt-1 px-2 py-0.5" id="badge_choice_stock" style="font-size: 0.68rem;">પસંદ ✓</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div id="qmodal_card_reassort" 
                                 class="p-2.5 rounded-3 border text-center transition-all h-100" 
                                 style="cursor: pointer; border-width: 1.5px !important; border-color: #cbd5e1 !important; background: #ffffff;" 
                                 onclick="setBoilActionChoice('reassort')">
                                <div class="text-warning-emphasis mb-1"><?= svg_icon('fire-burner', '', 20) ?></div>
                                <div class="fw-bold text-dark small">⚡ Re-Assort (ભાગ પાડવા)</div>
                                <small class="text-muted d-block" style="font-size: 0.70rem;">નવો લોટ પાડો &amp; Assort</small>
                                <span class="badge bg-warning text-dark rounded-pill mt-1 px-2 py-0.5" id="badge_choice_reassort" style="font-size: 0.68rem; display: none;">પસંદ કરો</span>
                            </div>
                        </div>
                    </div>

                    <!-- Target Stage Dropdown Wrap -->
                    <div class="mb-3" id="qmodal_target_stage_wrap">
                        <label class="form-label fw-bold text-dark mb-1.5 d-flex align-items-center gap-1.5">
                            <?= svg_icon('layer-group', 'text-primary', 15) ?>
                            <span>ટાર્ગેટ સ્ટેજ *</span>
                        </label>
                        <select id="qmodal_target_stage" class="form-select fw-semibold text-dark" required onchange="onQuickModalStageChange(this.value)">
                            <option value="__NEXT__">→ આગળનું સ્ટેજ (Auto Next Stage)</option>
                            <?php foreach ($modalStagesList as $idx => $stg): ?>
                                <option value="<?= e($stg['stage_code']) ?>">
                                    <?= ($idx + 1) ?>. <?= e($stg['stage_name']) ?> — (<?= e(($stg['default_location'] ?? '') ?: 'In House') ?>)
                                </option>
                            <?php endforeach; ?>
                            <option value="reassort" id="qmodal_opt_reassort" class="text-warning-emphasis fw-bold bg-warning-subtle" style="display: none;">
                                ⚡ Re-Assort કરો (નવો લોટ પાડો &amp; જૂની કાપનમાંથી એક્ઝિટ)
                            </option>
                        </select>
                    </div>

                    <!-- Re-Assort Box -->
                    <div id="qmodal_reassort_box" class="p-3.5 rounded-4 border border-warning-subtle bg-warning-subtle mb-3" style="display: none;">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="fw-bold text-dark d-flex align-items-center gap-2">
                                <?= svg_icon('fire-burner', 'text-warning-emphasis', 17) ?>
                                <span class="fs-6">⚡ Re-Assort વિગત (નવો લોટ &amp; રેટ)</span>
                            </div>
                            <span class="badge bg-warning text-dark fw-bold rounded-pill px-2 py-0.5 font-mono">NEW LOT</span>
                        </div>
                        <p class="small text-secondary mb-3" style="font-size: 0.82rem; line-height: 1.4;">
                            આ હીરા જૂની કાપનના સ્ટોકમાંથી બહાર નીકળશે અને <strong>નવા લોટ</strong> તરીકે Assort (ભાગ પાડવા) માટે જશે. જૂની કાપનની તમામ પ્રોસેસ હિસ્ટ્રી કાયમ સચવાશે.
                        </p>
                        <div class="row g-2 mb-2">
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold text-dark mb-1">નવો લોટ નંબર (Lot No) *</label>
                                <input type="text" id="qmodal_reassort_lot_no" class="form-control form-control-sm font-mono fw-bold text-primary" value="<?= e(generate_next_reassort_lot_no()) ?>">
                                <div class="form-text text-muted" style="font-size: 0.72rem;">દા.ત. LOT-2026-0001 (ઓટો અથવા કસ્ટમ)</div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small fw-bold text-dark mb-1">લોટ રેટ ₹/Ct (વૈકલ્પિક)</label>
                                <input type="number" step="0.01" min="0" id="qmodal_reassort_lot_rate" class="form-control form-control-sm font-mono fw-bold" placeholder="0.00">
                                <div class="form-text text-muted" style="font-size: 0.72rem;">કેરેટ દીઠ અંદાજિત કિંમત</div>
                            </div>
                        </div>
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" id="qmodal_go_to_assort" checked>
                            <label class="form-check-label small fw-semibold text-dark" for="qmodal_go_to_assort">
                                સેવ કર્યા બાદ તરત જ <strong>Assort (ભાગ પાડવા)</strong> પેજ ખોલો
                            </label>
                        </div>
                    </div>

                    <!-- Factory & Workshop Card -->
                    <div class="p-3 rounded-4 border mb-3 bg-white" id="qmodal_party_card_wrap">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label fw-bold text-dark mb-0 small d-flex align-items-center gap-1.5">
                                <?= svg_icon('industry', 'text-primary', 15) ?>
                                <span>કારખાનું / પાર્ટી *</span>
                            </label>
                            <span class="small text-muted font-mono" id="qmodal_party_count"><?= count($modalParties) ?> કારખાના</span>
                        </div>

                        <div class="position-relative mb-2">
                            <span class="position-absolute top-50 text-muted" style="left: 12px; transform: translateY(-50%); pointer-events: none;"><?= svg_icon('search', '', 14) ?></span>
                            <input type="text" id="qmodal_party_search" class="form-control form-control-sm" 
                                   placeholder="કારખાનું અથવા પાર્ટી સર્ચ કરો..." 
                                   style="padding-left: 34px; padding-right: 32px; height: 38px;"
                                   oninput="filterQuickParties(this.value)" autocomplete="off">
                            <button type="button" id="qmodal_search_clear_btn" 
                                    class="btn btn-link position-absolute top-50 p-0 text-muted text-decoration-none" 
                                    style="right: 10px; transform: translateY(-50%); display: none;"
                                    onclick="clearPartySearch()" title="Clear">
                                <?= svg_icon('circle-xmark', '', 15) ?>
                            </button>
                        </div>

                        <div id="qmodal_party_list" class="qmodal-party-list-container" 
                             style="max-height: 180px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; padding-right: 2px;">
                            
                            <div class="qmodal-party-item active" data-party-id="" data-party-name="ઇન-હાઉસ / પોતાની ઓફિસ" data-city="Surat" onclick="selectQuickParty(this)"
                                 style="display: flex !important; flex-direction: row !important; align-items: center !important; gap: 10px !important; padding: 8px 12px !important; border-radius: 8px !important; border: 1.5px solid #3b82f6 !important; background: #ebf4ff !important; cursor: pointer !important;">
                                <div class="party-avatar inhouse" style="width: 32px !important; height: 32px !important; min-width: 32px !important; border-radius: 7px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; flex-shrink: 0 !important; background: #e2e8f0; color: #475569;"><?= svg_icon('building', '', 14) ?></div>
                                <div class="party-info" style="flex: 1 1 auto !important; min-width: 0 !important;">
                                    <div class="party-name" style="font-size: 0.86rem !important; font-weight: 700 !important; color: #1d4ed8 !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; line-height: 1.3 !important;">ઇન-હાઉસ / પોતાની ઓફિસ</div>
                                    <div class="party-meta" style="display: flex !important; align-items: center !important; gap: 6px !important; font-size: 0.72rem !important; color: #64748b !important; margin-top: 2px !important;"><span class="party-city">Surat</span></div>
                                </div>
                                <div class="party-check" style="margin-left: auto !important; flex-shrink: 0 !important; display: inline-flex !important; align-items: center !important; color: #2563eb !important;"><?= svg_icon('circle-check', '', 16) ?></div>
                            </div>

                            <?php foreach ($modalParties as $pt): 
                                $ptType = $pt['party_type'] ?? 'party';
                                $ptTypeBadge = match($ptType) {
                                    'outsource_factory' => 'કારખાનું',
                                    'outsource_ls'      => 'LS',
                                    'outsource_galaxy'  => 'Galaxy',
                                    'broker'            => 'દલાલ',
                                    'lab_gia'           => 'GIA Lab',
                                    default             => 'પાર્ટી'
                                };
                                $avatarClass = match($ptType) {
                                    'outsource_factory' => 'avatar-factory',
                                    'outsource_ls'      => 'avatar-ls',
                                    'outsource_galaxy'  => 'avatar-galaxy',
                                    'broker'            => 'avatar-broker',
                                    'lab_gia'           => 'avatar-lab',
                                    default             => 'avatar-party'
                                };
                                $avatarIcon = match($ptType) {
                                    'outsource_factory' => 'industry',
                                    'outsource_ls'      => 'bolt',
                                    'outsource_galaxy'  => 'atom',
                                    'broker'            => 'handshake',
                                    'lab_gia'           => 'certificate',
                                    default             => 'user'
                                };
                                $cityText = !empty($pt['city']) ? $pt['city'] : 'Surat';
                            ?>
                                <div class="qmodal-party-item" data-party-id="<?= (int)$pt['id'] ?>" data-party-name="<?= e($pt['party_name']) ?>" data-city="<?= e($cityText) ?>" onclick="selectQuickParty(this)"
                                     style="display: flex !important; flex-direction: row !important; align-items: center !important; gap: 10px !important; padding: 8px 12px !important; border-radius: 8px !important; border: 1.5px solid #e2e8f0 !important; background: #ffffff !important; cursor: pointer !important;">
                                    <div class="party-avatar <?= $avatarClass ?>" style="width: 32px !important; height: 32px !important; min-width: 32px !important; border-radius: 7px !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; flex-shrink: 0 !important;"><?= svg_icon($avatarIcon, '', 14) ?></div>
                                    <div class="party-info" style="flex: 1 1 auto !important; min-width: 0 !important;">
                                        <div class="party-name" style="font-size: 0.86rem !important; font-weight: 600 !important; color: #1e293b !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; line-height: 1.3 !important;"><?= e($pt['party_name']) ?></div>
                                        <div class="party-meta" style="display: flex !important; align-items: center !important; gap: 6px !important; font-size: 0.72rem !important; color: #64748b !important; margin-top: 2px !important;">
                                            <span class="badge-type <?= $avatarClass ?>"><?= $ptTypeBadge ?></span>
                                            <span class="party-city"><?= e($cityText) ?></span>
                                        </div>
                                    </div>
                                    <div class="party-check" style="margin-left: auto !important; flex-shrink: 0 !important; display: inline-flex !important; align-items: center !important; color: #cbd5e1 !important;"><?= svg_icon('circle-check', '', 16) ?></div>
                                </div>
                            <?php endforeach; ?>

                            <div id="qmodal_party_empty" class="text-center py-3 text-muted" style="display: none;">
                                <?= svg_icon('building-circle-xmark', 'text-secondary mb-1', 24) ?>
                                <div class="small fw-semibold">કોઈ કારખાનું કે પાર્ટી મળી નથી</div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-2 mb-1">
                        <div class="col-sm-6" id="qmodal_location_col">
                            <label class="form-label small fw-bold text-muted mb-1 d-flex align-items-center gap-1">
                                <?= svg_icon('location-dot', 'text-danger', 14) ?>
                                <span>સ્થળ</span>
                            </label>
                            <select id="qmodal_location" class="form-select form-select-sm">
                                <option value="Surat">Surat Office</option>
                                <option value="Mumbai">Mumbai Office</option>
                                <option value="Galaxy">Galaxy Hub</option>
                                <option value="LS">LS Sawing Center</option>
                                <option value="Katargam Karkhanu">Katargam Karkhanu</option>
                                <option value="GIA">GIA Laboratory</option>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold text-muted mb-1 d-flex align-items-center gap-1">
                                <?= svg_icon('comment-dots', 'text-secondary', 14) ?>
                                <span>નોંધ</span>
                            </label>
                            <input type="text" id="qmodal_remarks" class="form-control form-control-sm" placeholder="નોંધ...">
                        </div>
                    </div>
                </form>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer px-4 py-3 bg-light border-top d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2" id="qmodal_single_actions">
                    <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" onclick="openDiamondHistoryModal(document.getElementById('qmodal_packet_id').value)">
                        <?= svg_icon('clock-rotate-left', '', 14) ?>
                        <span>હિસ્ટ્રી</span>
                    </button>
                    <a id="qmodal_full_page_link" href="#" class="btn btn-sm btn-outline-secondary rounded-pill" target="_blank">
                        <?= svg_icon('arrow-up-right-from-square', '', 14) ?>
                        <span>વિસ્તૃત ફોર્મ</span>
                    </a>
                </div>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <button type="button" class="btn btn-sm btn-light border rounded-pill px-3" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="button" id="qmodal_submit_btn" class="btn btn-sm btn-primary rounded-pill px-3" onclick="submitQuickStageUpdate()">
                        <?= svg_icon('check', '', 14) ?>
                        <span>સેવ કરો</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Sticky Bottom Bulk Stage Action Bar -->
<div id="bulkStageStickyBar" class="bulk-stage-sticky-bar shadow-lg" style="display: none;">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2.5 w-100">
        <div class="d-flex align-items-center gap-2.5">
            <div class="kpi-icon-tile kpi-icon-blue" style="width: 34px; height: 34px; border-radius: 10px;">
                <?= svg_icon('gem', '', 16) ?>
            </div>
            <div>
                <div class="fw-bold text-dark small lh-sm" id="bulk_bar_count">0 હીરા સિલેક્ટ</div>
                <div class="font-mono text-primary fw-bold small lh-sm" id="bulk_bar_weight">0.000 ct</div>
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-sm rounded-pill bg-primary-subtle text-primary fw-semibold border-0 px-3" onclick="instantNextFromSelection()">
                <?= svg_icon('arrow-right', 'me-1', 13) ?>નેક્સ્ટ સ્ટેજ
            </button>
            <button type="button" class="btn btn-sm btn-primary rounded-pill fw-semibold px-3" onclick="openBulkModalFromSelection()">
                <?= svg_icon('arrows-split-up-and-left', 'me-1', 13) ?>સ્ટેજ &amp; કારખાનું પસંદ કરો
            </button>
            <button type="button" id="bulk_reassort_btn" class="btn btn-sm btn-warning rounded-pill fw-bold text-dark px-3 shadow-sm" style="display: none;" onclick="openBulkReassortFromSelection()">
                <?= svg_icon('fire-burner', 'me-1', 13) ?>Re-Assort (નવો લોટ)
            </button>
            <button type="button" class="btn btn-sm btn-light border rounded-pill text-secondary px-2.5" onclick="clearBulkSelection()" title="સિલેક્શન ક્લિયર કરો">
                <?= svg_icon('xmark', '', 14) ?>
            </button>
        </div>
    </div>
</div>

<style>
/* Custom Interactive Party Item Styles */
.qmodal-party-list-container::-webkit-scrollbar {
    width: 5px;
}
.qmodal-party-list-container::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 8px;
}
.qmodal-party-list-container::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 8px;
}
.qmodal-party-list-container::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

.qmodal-party-item {
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    gap: 10px !important;
    padding: 8px 12px !important;
    border-radius: 8px !important;
    border: 1.5px solid #e2e8f0 !important;
    background: #ffffff !important;
    cursor: pointer !important;
    transition: all 0.15s ease-in-out !important;
    user-select: none !important;
    margin-bottom: 5px !important;
}
.qmodal-party-item:hover {
    background: #f8fafc !important;
    border-color: #cbd5e1 !important;
}
.qmodal-party-item.active {
    background: #ebf4ff !important;
    border-color: #3b82f6 !important;
    box-shadow: 0 1px 4px rgba(59, 130, 246, 0.14) !important;
}
.qmodal-party-item.active .party-name {
    color: #1d4ed8 !important;
    font-weight: 700 !important;
}
.qmodal-party-item .party-check {
    color: #cbd5e1 !important;
    margin-left: auto !important;
    transition: color 0.15s ease !important;
    flex-shrink: 0 !important;
}
.qmodal-party-item.active .party-check {
    color: #2563eb !important;
}
.party-avatar {
    width: 32px !important;
    height: 32px !important;
    min-width: 32px !important;
    border-radius: 7px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 0.85rem !important;
    flex-shrink: 0 !important;
}
.party-avatar.inhouse       { background: #e2e8f0 !important; color: #475569 !important; }
.party-avatar.avatar-factory { background: #e0f2fe !important; color: #0284c7 !important; }
.party-avatar.avatar-ls      { background: #fef3c7 !important; color: #d97706 !important; }
.party-avatar.avatar-galaxy  { background: #ccfbf1 !important; color: #0d9488 !important; }
.party-avatar.avatar-broker  { background: #f3e8ff !important; color: #9333ea !important; }
.party-avatar.avatar-lab     { background: #e0e7ff !important; color: #4f46e5 !important; }
.party-avatar.avatar-party   { background: #dcfce7 !important; color: #16a34a !important; }

.party-info {
    flex: 1 1 auto !important;
    min-width: 0 !important;
}
.party-name {
    font-size: 0.86rem !important;
    font-weight: 600 !important;
    color: #1e293b !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    line-height: 1.3 !important;
}
.party-meta {
    display: flex !important;
    align-items: center !important;
    gap: 6px !important;
    font-size: 0.72rem !important;
    color: #64748b !important;
    margin-top: 2px !important;
}
.badge-type {
    font-size: 0.68rem !important;
    padding: 1px 6px !important;
    border-radius: 4px !important;
    font-weight: 500 !important;
}
.badge-type.type-inhouse     { background: #f1f5f9 !important; color: #475569 !important; }
.badge-type.avatar-factory   { background: #f0f9ff !important; color: #0369a1 !important; border: 1px solid #bae6fd !important; }
.badge-type.avatar-ls        { background: #fffbeb !important; color: #b45309 !important; border: 1px solid #fde68a !important; }
.badge-type.avatar-galaxy    { background: #f0fdfa !important; color: #0f766e !important; border: 1px solid #99f6e4 !important; }
.badge-type.avatar-broker    { background: #faf5ff !important; color: #7e22ce !important; border: 1px solid #e9d5ff !important; }
.badge-type.avatar-lab       { background: #eef2ff !important; color: #4338ca !important; border: 1px solid #c7d2fe !important; }
.badge-type.avatar-party     { background: #f0fdf4 !important; color: #15803d !important; border: 1px solid #bbf7d0 !important; }
.party-city {
    font-family: var(--font-mono);
}
.bulk-stage-sticky-bar {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 1045;
    background: #ffffff;
    border: 1.5px solid #bfdbfe;
    border-radius: 18px;
    padding: 10px 18px;
    min-width: min(94vw, 660px);
    max-width: 96vw;
    box-shadow: 0 12px 35px rgba(37, 99, 235, 0.16) !important;
}
</style>

<script>
// Select a party from the interactive list
function selectQuickParty(el) {
    if (!el) return;

    // Remove active from all items
    document.querySelectorAll('#qmodal_party_list .qmodal-party-item').forEach(item => {
        item.classList.remove('active');
    });

    // Mark clicked item active
    el.classList.add('active');

    // Update hidden input
    const partyId = el.getAttribute('data-party-id') || '';
    document.getElementById('qmodal_party_select').value = partyId;

    // Auto-update Location if city provided
    const city = el.getAttribute('data-city');
    if (city) {
        const locSelect = document.getElementById('qmodal_location');
        if (locSelect) {
            for (let i = 0; i < locSelect.options.length; i++) {
                if (locSelect.options[i].value.toLowerCase() === city.toLowerCase()) {
                    locSelect.selectedIndex = i;
                    break;
                }
            }
        }
    }
}

// Clear search input
function clearPartySearch() {
    const input = document.getElementById('qmodal_party_search');
    if (input) {
        input.value = '';
        filterQuickParties('');
        input.focus();
    }
}

// Live Filter for Factory / Workshop list
function filterQuickParties(query) {
    const q = (query || '').toLowerCase().trim();
    const clearBtn = document.getElementById('qmodal_search_clear_btn');
    const counter = document.getElementById('qmodal_party_count');
    const emptyState = document.getElementById('qmodal_party_empty');
    const items = document.querySelectorAll('#qmodal_party_list .qmodal-party-item');

    if (clearBtn) {
        clearBtn.style.display = q ? 'block' : 'none';
    }

    let matchCount = 0;
    items.forEach(item => {
        const partyId = item.getAttribute('data-party-id');
        // Always show in-house if query is empty
        if (!partyId && !q) {
            item.style.display = 'flex';
            matchCount++;
            return;
        }

        const name = (item.getAttribute('data-party-name') || '').toLowerCase();
        const city = (item.getAttribute('data-city') || '').toLowerCase();
        const text = item.textContent.toLowerCase();

        const matches = name.includes(q) || city.includes(q) || text.includes(q);
        item.style.display = matches ? 'flex' : 'none';
        if (matches) matchCount++;
    });

    if (emptyState) {
        emptyState.style.display = (matchCount === 0) ? 'block' : 'none';
    }

    if (counter) {
        if (q) {
            counter.textContent = matchCount + ' મળ્યા';
            counter.style.background = matchCount > 0 ? '#eff6ff' : '#fee2e2';
            counter.style.color = matchCount > 0 ? '#2563eb' : '#b91c1c';
            counter.style.borderColor = matchCount > 0 ? '#bfdbfe' : '#fca5a5';
        } else {
            counter.textContent = (items.length - 1) + ' કારખાના/પાર્ટી';
            counter.style.background = '#eff6ff';
            counter.style.color = '#2563eb';
            counter.style.borderColor = '#bfdbfe';
        }
    }
}

function selectQuickModalPartyById(targetPartyId) {
    let partySelected = false;
    document.querySelectorAll('#qmodal_party_list .qmodal-party-item').forEach(item => {
        const pId = item.getAttribute('data-party-id') || '';
        if (pId === targetPartyId) {
            item.classList.add('active');
            partySelected = true;
            item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } else {
            item.classList.remove('active');
        }
    });
    if (!partySelected) {
        const firstItem = document.querySelector('#qmodal_party_list .qmodal-party-item');
        if (firstItem) firstItem.classList.add('active');
        document.getElementById('qmodal_party_select').value = '';
    } else {
        document.getElementById('qmodal_party_select').value = targetPartyId;
    }
}

function selectQuickModalStageByCur(curStage, forceAutoNext) {
    const targetSelect = document.getElementById('qmodal_target_stage');
    if (!targetSelect) return;
    if (forceAutoNext) {
        targetSelect.value = '__NEXT__';
        return;
    }
    const cleanCur = String(curStage || '').toLowerCase().trim();
    if (cleanCur === 'polish_return' || cleanCur === 'boil_return') {
        targetSelect.value = 'polish_stock';
        return;
    }
    if (cleanCur === 'boil' || cleanCur === 'weekly_boil' || cleanCur === 'boil_polish') {
        targetSelect.value = 'polish_return';
        return;
    }
    const stageNormMap = {
        ls: 'laser',
        laser_sawing: 'laser',
        ls_return: 'laser',
        sawing: 'laser',
        planning: 'planner',
        sarin_final: 'blocking',
        bruting: 'blocking',
        mfg: 'blocking',
        mfg_polish: 'polishing',
        factory: 'polishing',
        qc: 'final_checking',
        boil_polish: 'boil',
        weekly_boil: 'boil',
        polish_return: 'polish_return',
        boil_return: 'polish_return'
    };
    const normStage = stageNormMap[cleanCur] || cleanCur;

    let found = false;
    for (let i = 0; i < targetSelect.options.length; i++) {
        if (targetSelect.options[i].value === cleanCur || targetSelect.options[i].value === normStage) {
            targetSelect.selectedIndex = (i + 1 < targetSelect.options.length) ? (i + 1) : i;
            found = true;
            break;
        }
    }
    if (!found && targetSelect.options.length > 0) {
        targetSelect.value = '__NEXT__';
    }
}

function showQuickStageModalUi() {
    const modalEl = document.getElementById('quickStageModal');
    if (!modalEl) return;
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    } else if (typeof $ !== 'undefined') {
        $(modalEl).modal('show');
    }
}

// Switch between Polish Stock and Re-Assort for Boil Return packets
function setBoilActionChoice(choice) {
    const cardStock = document.getElementById('qmodal_card_stock');
    const cardReassort = document.getElementById('qmodal_card_reassort');
    const badgeStock = document.getElementById('badge_choice_stock');
    const badgeReassort = document.getElementById('badge_choice_reassort');

    if (choice === 'reassort') {
        if (cardReassort) {
            cardReassort.style.borderWidth = '2px';
            cardReassort.style.borderColor = '#f59e0b';
            cardReassort.style.background = '#fffbeb';
        }
        if (badgeReassort) {
            badgeReassort.style.display = 'inline-block';
            badgeReassort.textContent = 'પસંદ ✓';
            badgeReassort.className = 'badge bg-warning text-dark rounded-pill mt-1 px-2 py-0.5';
        }
        if (cardStock) {
            cardStock.style.borderWidth = '1.5px';
            cardStock.style.borderColor = '#cbd5e1';
            cardStock.style.background = '#ffffff';
        }
        if (badgeStock) {
            badgeStock.style.display = 'none';
        }
        setQuickModalMode('reassort');
    } else {
        if (cardStock) {
            cardStock.style.borderWidth = '2px';
            cardStock.style.borderColor = '#22c55e';
            cardStock.style.background = '#f0fdf4';
        }
        if (badgeStock) {
            badgeStock.style.display = 'inline-block';
            badgeStock.textContent = 'પસંદ ✓';
            badgeStock.className = 'badge bg-success text-white rounded-pill mt-1 px-2 py-0.5';
        }
        if (cardReassort) {
            cardReassort.style.borderWidth = '1.5px';
            cardReassort.style.borderColor = '#cbd5e1';
            cardReassort.style.background = '#ffffff';
        }
        if (badgeReassort) {
            badgeReassort.style.display = 'none';
        }
        setQuickModalMode('transfer');
        const targetSelect = document.getElementById('qmodal_target_stage');
        if (targetSelect) {
            targetSelect.value = 'polish_stock';
        }
    }
}

// Switch Between Normal Stage Transfer & Re-Assort Mode
function setQuickModalMode(mode) {
    const currentModeInput = document.getElementById('qmodal_current_mode');
    if (currentModeInput) currentModeInput.value = mode;

    const tabTransfer = document.getElementById('qmodal_tab_transfer');
    const tabReassort = document.getElementById('qmodal_tab_reassort');
    const stageWrap = document.getElementById('qmodal_target_stage_wrap');
    const partyWrap = document.getElementById('qmodal_party_card_wrap');
    const reassortBox = document.getElementById('qmodal_reassort_box');
    const locationCol = document.getElementById('qmodal_location_col');
    const callout = document.getElementById('qmodal_reassort_callout');
    const submitBtn = document.getElementById('qmodal_submit_btn');
    const targetSelect = document.getElementById('qmodal_target_stage');

    if (mode === 'reassort') {
        if (tabTransfer) {
            tabTransfer.className = 'btn btn-sm flex-fill rounded-pill fw-bold py-2 border-0 d-flex align-items-center justify-content-center gap-1.5 text-secondary';
            tabTransfer.style.backgroundColor = 'transparent';
        }
        if (tabReassort) {
            tabReassort.className = 'btn btn-sm flex-fill rounded-pill fw-bold py-2 border-0 d-flex align-items-center justify-content-center gap-1.5 bg-warning text-dark shadow-sm';
            tabReassort.style.backgroundColor = '';
        }
        if (stageWrap) stageWrap.style.display = 'none';
        if (partyWrap) partyWrap.style.display = 'none';
        if (locationCol) locationCol.style.display = 'none';
        if (callout) callout.style.display = 'none';
        if (reassortBox) reassortBox.style.display = 'block';

        if (targetSelect) targetSelect.value = 'reassort';

        if (submitBtn) {
            submitBtn.className = 'btn btn-sm btn-warning text-dark fw-bold rounded-pill px-4 shadow-sm';
            submitBtn.innerHTML = (window.svgIcon ? window.svgIcon('fire-burner', 'me-1', 14) : '') + '<span>⚡ Re-Assort કરો (નવો લોટ બનાવો) &rarr;</span>';
        }
    } else {
        if (tabTransfer) {
            tabTransfer.className = 'btn btn-sm flex-fill rounded-pill fw-bold py-2 border-0 d-flex align-items-center justify-content-center gap-1.5 bg-white text-primary shadow-sm';
            tabTransfer.style.backgroundColor = '#ffffff';
        }
        if (tabReassort) {
            tabReassort.className = 'btn btn-sm flex-fill rounded-pill fw-bold py-2 border-0 d-flex align-items-center justify-content-center gap-1.5 text-secondary';
            tabReassort.style.backgroundColor = 'transparent';
        }
        if (stageWrap) stageWrap.style.display = 'block';
        if (partyWrap) partyWrap.style.display = 'block';
        if (locationCol) locationCol.style.display = 'block';
        if (reassortBox) reassortBox.style.display = 'none';

        if (targetSelect && targetSelect.value === 'reassort') {
            targetSelect.value = '__NEXT__';
        }

        if (submitBtn) {
            submitBtn.className = 'btn btn-sm btn-primary rounded-pill px-4';
            submitBtn.innerHTML = (window.svgIcon ? window.svgIcon('check', '', 14) : '') + '<span>સેવ કરો</span>';
        }
    }
}

function onQuickModalStageChange(val) {
    if (val === 'reassort') {
        setQuickModalMode('reassort');
    } else {
        setQuickModalMode('transfer');
    }
}

// Helper: Check if a stage represents Boil Return
function isBoilReturnStage(st) {
    if (!st) return false;
    const s = String(st).toLowerCase().trim();
    return ['boil', 'boil_return', 'polish_return', 'boil_polish', 'weekly_boil'].includes(s);
}

// Open Quick Stage & Factory Transfer Modal (Single Packet)
function openQuickStageModal(pkt, initialMode = 'transfer') {
    if (!pkt || !pkt.id) return;

    document.getElementById('qmodal_packet_id').value = pkt.id;
    document.getElementById('qmodal_packet_ids').value = '';
    document.getElementById('qmodal_packet_no').innerHTML = (window.svgIcon ? window.svgIcon('gem', 'text-primary', 15) : '') + ' ' + escapeHtml(pkt.packet_no || ('#' + pkt.id));
    document.getElementById('qmodal_barcode').textContent = pkt.barcode || '';
    document.getElementById('qmodal_weight').textContent = (parseFloat(pkt.weight) || 0).toFixed(3) + ' ct';
    
    const curStage = pkt.stage || 'galaxy';
    const stageBadge = document.getElementById('qmodal_cur_stage_badge');
    if (stageBadge) {
        stageBadge.textContent = curStage.toUpperCase().replace('_', ' ');
    }

    // STRICT CHECK: ONLY Boil Return packets can be reassorted
    const isBoilReturn = isBoilReturnStage(curStage);
    const boilDecisionCards = document.getElementById('qmodal_boil_decision_cards');
    const reassortOpt = document.getElementById('qmodal_opt_reassort');

    if (boilDecisionCards) {
        boilDecisionCards.style.display = isBoilReturn ? 'flex' : 'none';
    }
    if (reassortOpt) {
        reassortOpt.style.display = isBoilReturn ? '' : 'none';
        reassortOpt.disabled = !isBoilReturn;
    }

    // If not boil return, strictly enforce normal transfer mode
    if (!isBoilReturn) {
        initialMode = 'transfer';
    }

    selectQuickModalStageByCur(curStage, false);

    const searchInput = document.getElementById('qmodal_party_search');
    if (searchInput) searchInput.value = '';
    filterQuickParties('');

    selectQuickModalPartyById(pkt.party_id ? String(pkt.party_id) : '');

    const locSelect = document.getElementById('qmodal_location');
    if (locSelect) {
        const curLoc = pkt.location || 'Surat';
        let locFound = false;
        for (let i = 0; i < locSelect.options.length; i++) {
            if (locSelect.options[i].value.toLowerCase() === curLoc.toLowerCase()) {
                locSelect.selectedIndex = i;
                locFound = true;
                break;
            }
        }
        if (!locFound) locSelect.value = 'Surat';
    }

    document.getElementById('qmodal_remarks').value = '';
    const singleActions = document.getElementById('qmodal_single_actions');
    if (singleActions) singleActions.style.display = 'flex';

    const advLink = document.getElementById('qmodal_full_page_link');
    if (advLink) {
        advLink.href = '<?= BASE_URL ?>/modules/process/update_stage.php?id=' + pkt.id;
    }

    if (isBoilReturn) {
        setBoilActionChoice(initialMode === 'reassort' ? 'reassort' : 'polish_stock');
    } else {
        setQuickModalMode(initialMode);
    }
    showQuickStageModalUi();
}

// Convenience function to open modal directly in Re-Assort mode
function openQuickReassortModal(pkt) {
    if (!isBoilReturnStage(pkt.stage)) {
        alert('માત્ર બોઇલ રિટર્ન (Boil Return) થયેલા હીરા જ Re-Assort થઈ શકે છે. અન્ય કોઈ સ્ટેજના હીરા Re-Assort થશે નહીં.');
        return;
    }
    openQuickStageModal(pkt, 'reassort');
}

// Open Bulk Stage & Factory Transfer Modal (Multiple Packets)
function openBulkStageModal(pkts, initialMode = 'transfer') {
    if (!Array.isArray(pkts) || pkts.length === 0) return;
    if (pkts.length === 1) {
        openQuickStageModal(pkts[0], initialMode);
        return;
    }

    const ids = pkts.map(p => p.id);
    const totWt = pkts.reduce((acc, p) => acc + (parseFloat(p.weight) || 0), 0);
    const stages = [...new Set(pkts.map(p => p.stage || 'galaxy'))];
    const sampleNames = pkts.slice(0, 3).map(p => p.packet_no).join(', ') + (pkts.length > 3 ? ' +' + (pkts.length - 3) : '');

    document.getElementById('qmodal_packet_id').value = '0';
    document.getElementById('qmodal_packet_ids').value = ids.join(',');
    document.getElementById('qmodal_packet_no').innerHTML = (window.svgIcon ? window.svgIcon('layer-group', 'text-primary', 15) : '') + ` ${pkts.length} હીરા બલ્ક ટ્રાન્સફર`;
    document.getElementById('qmodal_barcode').textContent = sampleNames;
    document.getElementById('qmodal_weight').textContent = totWt.toFixed(3) + ' ct';

    const stageBadge = document.getElementById('qmodal_cur_stage_badge');
    if (stageBadge) {
        stageBadge.textContent = stages.length === 1 ? stages[0].toUpperCase().replace('_', ' ') : 'મિક્સ સ્ટેજ (' + stages.length + ')';
    }

    // STRICT CHECK FOR BULK: all must be boil return
    const allBoilReturn = pkts.every(p => isBoilReturnStage(p.stage));
    const boilDecisionCards = document.getElementById('qmodal_boil_decision_cards');
    const reassortOpt = document.getElementById('qmodal_opt_reassort');

    if (boilDecisionCards) {
        boilDecisionCards.style.display = allBoilReturn ? 'flex' : 'none';
    }
    if (reassortOpt) {
        reassortOpt.style.display = allBoilReturn ? '' : 'none';
        reassortOpt.disabled = !allBoilReturn;
    }

    if (!allBoilReturn) {
        initialMode = 'transfer';
    }

    selectQuickModalStageByCur(stages[0], stages.length > 1);

    const searchInput = document.getElementById('qmodal_party_search');
    if (searchInput) searchInput.value = '';
    filterQuickParties('');
    selectQuickModalPartyById('');
    document.getElementById('qmodal_remarks').value = '';

    const singleActions = document.getElementById('qmodal_single_actions');
    if (singleActions) singleActions.style.display = 'none';

    if (allBoilReturn) {
        setBoilActionChoice(initialMode === 'reassort' ? 'reassort' : 'polish_stock');
    } else {
        setQuickModalMode(initialMode);
    }
    showQuickStageModalUi();
}

function openBulkReassortFromSelection() {
    const checked = getCheckedPackets();
    if (checked.length === 0) {
        alert('કૃપા કરીને ઓછામાં ઓછો એક હીરો પસંદ કરો.');
        return;
    }
    const nonBoilReturn = checked.filter(p => !isBoilReturnStage(p.stage));
    if (nonBoilReturn.length > 0) {
        alert('માત્ર બોઇલ રિટર્ન (Boil Return) થયેલા હીરા જ Re-Assort થઈ શકે છે. પસંદ કરેલા હીરામાં અન્ય સ્ટેજના હીરા છે.');
        return;
    }
    openBulkStageModal(checked, 'reassort');
}

// Submit AJAX Stage & Factory Update (Handles both Single & Bulk cleanly)
function submitQuickStageUpdate() {
    const packetId = document.getElementById('qmodal_packet_id').value;
    const packetIds = document.getElementById('qmodal_packet_ids').value;
    const currentMode = document.getElementById('qmodal_current_mode')?.value || 'transfer';
    let targetStage = document.getElementById('qmodal_target_stage').value;
    if (currentMode === 'reassort') {
        targetStage = 'reassort';
    }
    const partyId = document.getElementById('qmodal_party_select').value;
    const location = document.getElementById('qmodal_location').value;
    const remarks = document.getElementById('qmodal_remarks').value.trim();
    const csrfToken = document.getElementById('qmodal_csrf').value;
    const submitBtn = document.getElementById('qmodal_submit_btn');

    if ((!packetIds || packetIds === '') && (!packetId || packetId === '0')) {
        alert('પેકેટ પસંદ થયેલ નથી.');
        return;
    }

    if (!targetStage) {
        alert('કૃપા કરીને ટાર્ગેટ સ્ટેજ પસંદ કરો.');
        return;
    }

    const origBtnHtml = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = (window.svgIcon ? window.svgIcon('spinner', 'svg-spin me-1', 14) : '') + 'સેવ થઈ રહ્યું છે...';

    const postData = new URLSearchParams();
    postData.append('csrf_token', csrfToken);
    if (packetIds) {
        postData.append('packet_ids', packetIds);
    } else {
        postData.append('packet_id', packetId);
    }
    postData.append('target_stage', targetStage);
    if (targetStage === '__NEXT__') {
        postData.append('action_mode', 'next_stage');
    }
    if (targetStage === 'reassort') {
        const lotNo = document.getElementById('qmodal_reassort_lot_no')?.value || '';
        const lotRate = document.getElementById('qmodal_reassort_lot_rate')?.value || '0';
        postData.append('reassort_lot_no', lotNo);
        postData.append('reassort_lot_rate', lotRate);
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
    .then(async res => {
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            const cleanErr = text.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim();
            throw new Error(cleanErr || 'સર્વર પ્રતિસાદ અમાન્ય છે.');
        }
        return data;
    })
    .then(data => {
        if (data.success) {
            const modalEl = document.getElementById('quickStageModal');
            if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();
            }

            const goToAssort = (targetStage === 'reassort' && document.getElementById('qmodal_go_to_assort')?.checked && data.redirect_url);

            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'success',
                    title: 'સ્ટેજ અપડેટ થઈ ગયું!',
                    text: data.message || 'સફળતાપૂર્વક ટ્રાન્સફર થયું.',
                    timer: goToAssort ? 1200 : 1400,
                    showConfirmButton: false
                }).then(() => {
                    if (goToAssort) {
                        window.location.href = data.redirect_url;
                    } else {
                        window.location.reload();
                    }
                });
            } else {
                alert(data.message || 'સ્ટેજ સફળતાપૂર્વક અપડેટ થયું!');
                if (goToAssort) {
                    window.location.href = data.redirect_url;
                } else {
                    window.location.reload();
                }
            }
        } else {
            alert('ક્ષતિ: ' + (data.message || 'સ્ટેજ અપડેટ થઈ શક્યું નથી.'));
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
        }
    })
    .catch(err => {
        console.error('AJAX Error:', err);
        alert('સર્વર સાથે સંપર્ક કરવામાં ક્ષતિ: ' + err.message);
        submitBtn.disabled = false;
        submitBtn.innerHTML = origBtnHtml;
    });
}

// ---------- SHARED BULK SELECTION & ONE-CLICK NEXT-STAGE HELPERS ----------
function getCheckedPackets() {
    const list = [];
    document.querySelectorAll('.pkt-bulk-cb:checked').forEach(cb => {
        list.push({
            id: parseInt(cb.getAttribute('data-id') || '0', 10),
            packet_no: cb.getAttribute('data-packet-no') || '',
            barcode: cb.getAttribute('data-barcode') || '',
            weight: parseFloat(cb.getAttribute('data-weight') || '0') || 0,
            stage: cb.getAttribute('data-stage') || 'galaxy',
            location: cb.getAttribute('data-location') || 'Surat',
            party_id: parseInt(cb.getAttribute('data-party-id') || '0', 10)
        });
    });
    return list.filter(p => p.id > 0);
}

function refreshBulkSelectionBar() {
    const selected = getCheckedPackets();
    const bar = document.getElementById('bulkStageStickyBar');
    if (!bar) return;
    if (selected.length === 0) {
        bar.style.display = 'none';
        return;
    }
    const totWt = selected.reduce((acc, p) => acc + p.weight, 0);
    const cntEl = document.getElementById('bulk_bar_count');
    const wtEl = document.getElementById('bulk_bar_weight');
    if (cntEl) cntEl.textContent = selected.length + ' હીરા સિલેક્ટ';
    if (wtEl) wtEl.textContent = totWt.toFixed(3) + ' ct';

    // Show Re-Assort button in bulk bar ONLY if ALL selected packets are in Boil Return
    const allBoilReturn = selected.length > 0 && selected.every(p => isBoilReturnStage(p.stage));
    const bulkReassortBtn = document.getElementById('bulk_reassort_btn');
    if (bulkReassortBtn) {
        bulkReassortBtn.style.display = allBoilReturn ? 'inline-flex' : 'none';
    }

    bar.style.display = 'flex';
}

function toggleKapanSelectAll(masterCb, containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const isChecked = !!masterCb.checked;
    container.querySelectorAll('.pkt-bulk-cb').forEach(cb => {
        cb.checked = isChecked;
    });
    refreshBulkSelectionBar();
}

function syncKapanMasterCheckbox(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const master = container.querySelector('.kapan-select-all');
    const cbs = container.querySelectorAll('.pkt-bulk-cb');
    if (master && cbs.length > 0) {
        const checkedCnt = container.querySelectorAll('.pkt-bulk-cb:checked').length;
        master.checked = (checkedCnt === cbs.length);
        master.indeterminate = (checkedCnt > 0 && checkedCnt < cbs.length);
    }
    refreshBulkSelectionBar();
}

function applyKapanRangeSelect(inputEl, containerId) {
    const container = document.getElementById(containerId);
    if (!container || !inputEl) return;
    const raw = (inputEl.value || '').trim();
    if (!raw) return;

    const pickedIndices = new Set();
    raw.split(',').forEach(part => {
        const clean = part.trim();
        if (!clean) return;
        if (clean.includes('-')) {
            const [s, e] = clean.split('-').map(n => parseInt(n.trim(), 10));
            if (!isNaN(s) && !isNaN(e)) {
                const start = Math.min(s, e);
                const end = Math.max(s, e);
                for (let i = start; i <= end; i++) pickedIndices.add(i);
            }
        } else {
            const single = parseInt(clean, 10);
            if (!isNaN(single)) pickedIndices.add(single);
        }
    });

    if (pickedIndices.size === 0) return;
    container.querySelectorAll('.pkt-bulk-cb').forEach(cb => {
        const rIdx = parseInt(cb.getAttribute('data-row-idx') || '0', 10);
        if (pickedIndices.has(rIdx)) cb.checked = true;
    });
    syncKapanMasterCheckbox(containerId);
}

function clearBulkSelection() {
    document.querySelectorAll('.pkt-bulk-cb, .kapan-select-all').forEach(cb => {
        cb.checked = false;
        cb.indeterminate = false;
    });
    refreshBulkSelectionBar();
}

function openBulkModalFromSelection() {
    const selected = getCheckedPackets();
    if (selected.length > 0) openBulkStageModal(selected);
}

function instantNextFromSelection() {
    const selected = getCheckedPackets();
    if (selected.length === 0) return;
    const ids = selected.map(p => p.id);
    executeInstantStageAdvance(ids, `પસંદ કરેલ ${selected.length} હીરા (${selected.reduce((a,b)=>a+b.weight,0).toFixed(3)} ct) આગળના સ્ટેજમાં મોકલવા છે?`);
}

function bulkAdvanceKapan(containerId, kapanName) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const ids = [];
    let totWt = 0;
    container.querySelectorAll('.pkt-bulk-cb').forEach(cb => {
        const id = parseInt(cb.getAttribute('data-id') || '0', 10);
        if (id > 0) {
            ids.push(id);
            totWt += parseFloat(cb.getAttribute('data-weight') || '0') || 0;
        }
    });
    if (ids.length === 0) return;
    executeInstantStageAdvance(ids, `'${kapanName}' ના તમામ ${ids.length} હીરા (${totWt.toFixed(3)} ct) આગળના સ્ટેજમાં મોકલવા છે?`);
}

function quickAdvanceSinglePacket(packetId, packetNo) {
    if (!packetId) return;
    executeInstantStageAdvance([packetId], null, `'${packetNo}' ને આગળના સ્ટેજમાં મોકલ્યું`);
}

function executeInstantStageAdvance(packetIds, confirmPromptText, quickDoneTitle) {
    if (!Array.isArray(packetIds) || packetIds.length === 0) return;
    const runAjax = () => {
        const csrfToken = document.getElementById('qmodal_csrf')?.value || '';
        const postData = new URLSearchParams();
        postData.append('csrf_token', csrfToken);
        postData.append('packet_ids', packetIds.join(','));
        postData.append('target_stage', '__NEXT__');
        postData.append('action_mode', 'next_stage');

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
            if (data.success) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: data.updated_count > 0 ? 'success' : 'info',
                        title: quickDoneTitle || (data.updated_count > 0 ? 'આગળના સ્ટેજમાં ટ્રાન્સફર થયું!' : 'માહિતી'),
                        text: data.message,
                        timer: 1300,
                        showConfirmButton: false
                    }).then(() => window.location.reload());
                } else {
                    window.location.reload();
                }
            } else {
                alert('ક્ષતિ: ' + (data.message || 'અપડેટ નિષ્ફળ ગયું.'));
            }
        })
        .catch(err => alert('સર્વર ક્ષતિ: ' + err.message));
    };

    if (!confirmPromptText) {
        runAjax();
        return;
    }

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: 'question',
            title: 'કન્ફર્મ કરો',
            text: confirmPromptText,
            showCancelButton: true,
            confirmButtonText: 'હા, મોકલો',
            cancelButtonText: 'રદ કરો',
            confirmButtonColor: '#2563eb'
        }).then(r => {
            if (r.isConfirmed) runAjax();
        });
    } else if (confirm(confirmPromptText)) {
        runAjax();
    }
}
</script>
