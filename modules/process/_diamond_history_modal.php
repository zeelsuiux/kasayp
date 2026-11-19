<?php
/**
 * Diamond History & Multi-Stage Journey Modal Component
 * Diamond ERP
 * 
 * Reusable modal for inspecting 1-to-1 diamond history, stage-by-stage weight audit,
 * factory/workshop assignments, loss, and tops records.
 */

declare(strict_types=1);
?>

<!-- Diamond History & Journey Modal -->
<div class="modal fade" id="diamondHistoryModal" tabindex="-1" aria-labelledby="diamondHistoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" style="max-width: 820px;">
        <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
            
            <!-- Modal Header (Clean Finora) -->
            <div class="modal-header px-4 py-3 border-bottom bg-white">
                <div class="d-flex align-items-center gap-2.5">
                    <div class="kpi-icon-tile kpi-icon-blue" style="width: 36px; height: 36px; border-radius: 10px;">
                        <?= svg_icon('clock-rotate-left', '', 18) ?>
                    </div>
                    <h6 class="modal-title fw-bold mb-0 text-dark" id="diamondHistoryModalLabel">હીરાનો ઇતિહાસ & જર્ની</h6>
                    <span class="finora-pill finora-pill-blue font-mono" id="dhist_kapan_badge">Kapan: -</span>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Modal Body -->
            <div class="modal-body p-4 bg-white" id="dhist_modal_body">
                
                <div id="dhist_loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status" style="width: 2.2rem; height: 2.2rem;">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="mt-2 text-muted small">લોડ થઈ રહ્યું છે...</div>
                </div>

                <div id="dhist_content" style="display: none;">
                    
                    <div class="p-3 rounded-4 mb-3 border d-flex flex-wrap justify-content-between align-items-center gap-2" style="background: #f8fafc;">
                        <div>
                            <span class="text-secondary small d-block">પેકેટ / હીરા નં.</span>
                            <div class="font-mono fw-bold text-dark d-flex align-items-center gap-1.5 mt-0.5" id="dhist_packet_title" style="font-size: 1.08rem;">-</div>
                            <div class="d-flex align-items-center gap-2 mt-1 small text-muted font-mono">
                                <span id="dhist_barcode">-</span> &bull;
                                <span id="dhist_shape">Shape: -</span>
                            </div>
                        </div>
                        <div class="text-end">
                            <span class="text-secondary small d-block">હાલનું સ્ટેજ</span>
                            <div class="mt-1 d-flex flex-wrap justify-content-end gap-1.5">
                                <span class="finora-pill finora-pill-blue font-mono" id="dhist_current_stage">-</span>
                                <span class="finora-pill finora-pill-slate font-mono" id="dhist_location">
                                    <?= svg_icon('location-dot', 'text-danger', 12) ?><span id="dhist_location_text">Surat</span>
                                </span>
                            </div>
                            <small class="text-muted d-block mt-1 font-mono" id="dhist_assigned_party_text">કારખાનું: ઇન-હાઉસ</small>
                        </div>
                    </div>

                    <div class="row g-2.5 mb-3">
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-4 border text-center" style="background: #f8fafc;">
                                <span class="text-muted d-block small">મૂળ વજન</span>
                                <strong class="font-mono text-dark d-block mt-0.5" id="dhist_orig_weight">0.000 ct</strong>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-4 text-center kpi-tone-green">
                                <span class="text-success d-block small fw-semibold">હાજર વજન</span>
                                <strong class="font-mono text-success d-block mt-0.5" id="dhist_curr_weight">0.000 ct</strong>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-4 text-center kpi-tone-rose">
                                <span class="text-danger d-block small fw-semibold">કુલ ઘટ</span>
                                <strong class="font-mono text-danger d-block mt-0.5" id="dhist_total_loss">0.000 ct</strong>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="p-2.5 rounded-4 text-center kpi-tone-amber">
                                <span class="text-warning-emphasis d-block small fw-semibold">કુલ ટોપ્સ</span>
                                <strong class="font-mono text-dark d-block mt-0.5" id="dhist_total_tops">0.000 ct</strong>
                            </div>
                        </div>
                    </div>

                    <div id="dhist_split_container" class="mb-3" style="display: none;">
                        <div class="p-2.5 rounded-3 border bg-light">
                            <div class="d-flex align-items-center gap-1.5 fw-bold text-dark small mb-1">
                                <?= svg_icon('code-fork', 'text-warning', 14) ?>
                                <span>સ્પ્લિટ ભાગો:</span>
                            </div>
                            <div id="dhist_split_list" class="d-flex flex-wrap gap-2"></div>
                        </div>
                    </div>

                    <div class="finora-card overflow-hidden">
                        <div class="finora-card-header py-2.5 px-3">
                            <h6 class="finora-card-title small">
                                <?= svg_icon('timeline', 'text-primary', 15) ?>
                                <span>સ્ટેજ ઓડિટ ટ્રેઇલ</span>
                            </h6>
                            <span class="small text-muted font-mono" id="dhist_stages_count_badge">0 સ્ટેજ</span>
                        </div>
                        <div class="p-0">
                            <div class="table-responsive">
                                <table class="table table-diamond mb-0">
                                    <thead>
                                        <tr>
                                            <th class="ps-3" style="width: 40px;">#</th>
                                            <th>સ્ટેજ</th>
                                            <th>કારખાનું / પાર્ટી</th>
                                            <th class="text-end">આપેલ</th>
                                            <th class="text-end">આવેલ</th>
                                            <th class="text-end">ઘટ</th>
                                            <th class="text-end">ટોપ્સ</th>
                                            <th>તારીખ / સ્થળ</th>
                                            <th class="pe-3">નોંધ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dhist_timeline_tbody"></tbody>
                                </table>
                            </div>
                            
                            <div id="dhist_empty_history" class="text-center py-4 text-muted" style="display: none;">
                                <?= svg_icon('box-open', 'text-secondary mb-1', 26) ?>
                                <div class="small fw-semibold">આ હીરાની હજુ કોઈ સ્ટેજ હિસ્ટ્રી નથી.</div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Modal Footer -->
            <div class="modal-footer px-4 py-3 bg-light border-top d-flex justify-content-between align-items-center">
                <a id="dhist_full_page_link" href="#" class="btn btn-sm btn-outline-secondary" target="_blank">
                    <?= svg_icon('arrow-up-right-from-square', '', 14) ?>
                    <span>સંપૂર્ણ જર્ની રિપોર્ટ</span>
                </a>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                        <?= svg_icon('print', '', 14) ?>પ્રિન્ટ
                    </button>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-dismiss="modal">બંધ કરો</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Open Diamond History Modal & Fetch Records via AJAX
function openDiamondHistoryModal(packetId) {
    if (!packetId) return;

    const modalEl = document.getElementById('diamondHistoryModal');
    if (!modalEl) return;

    const loadingEl = document.getElementById('dhist_loading');
    const contentEl = document.getElementById('dhist_content');
    
    // Reset view
    if (loadingEl) loadingEl.style.display = 'block';
    if (contentEl) contentEl.style.display = 'none';

    // Show modal
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();
    } else {
        $(modalEl).modal('show');
    }

    // Set full report link
    const fullLink = document.getElementById('dhist_full_page_link');
    if (fullLink) {
        fullLink.href = '<?= BASE_URL ?>/modules/process/diamond_journey.php?id=' + packetId;
    }

    // Fetch Diamond History via AJAX
    fetch('<?= BASE_URL ?>/modules/process/ajax_diamond_history.php?id=' + packetId)
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
            if (!data.success) {
                alert('ક્ષતિ: ' + (data.message || 'હીરાની હિસ્ટ્રી મળી નથી.'));
                return;
            }

            renderDiamondHistoryData(data);

            if (loadingEl) loadingEl.style.display = 'none';
            if (contentEl) contentEl.style.display = 'block';
        })
        .catch(err => {
            console.error('History Fetch Error:', err);
            alert('સર્વર સાથે સંપર્ક કરવામાં ક્ષતિ: ' + err.message);
            if (loadingEl) loadingEl.style.display = 'none';
        });
}

// Render Received Data into Modal DOM
function renderDiamondHistoryData(data) {
    const d = data.diamond || {};
    const m = data.metrics || {};
    const history = data.stage_history || [];
    const procHist = data.process_history || [];
    const children = data.child_diamonds || [];

    // Header info
    document.getElementById('dhist_kapan_badge').textContent = 'Kapan: ' + (d.kapan_name || d.kapan_code || '-');
    document.getElementById('dhist_packet_title').innerHTML = window.svgIcon('gem', 'text-primary me-1', 15) + ' ' + escapeHtml(d.diamond_no || d.packet_no || '');
    document.getElementById('dhist_barcode').textContent = 'Barcode: ' + (d.barcode || d.packet_no);
    document.getElementById('dhist_shape').textContent = 'Shape: ' + (d.expected_shape || 'Round');

    const curStageEl = document.getElementById('dhist_current_stage');
    if (curStageEl) {
        let stageTxt = (d.current_stage || 'GALAXY').toUpperCase().replace(/_/g, ' ');
        if (d.is_reassorted && d.reassort_lot_no) {
            stageTxt = 'RE-ASSORTED (' + d.reassort_lot_no + ')';
        } else if (d.origin_reassort_lot_no) {
            stageTxt += ' • LOT: ' + d.origin_reassort_lot_no;
        }
        curStageEl.textContent = stageTxt;
    }
    document.getElementById('dhist_location_text').textContent = d.current_location || 'Surat';
    document.getElementById('dhist_assigned_party_text').textContent = 'કારખાનું: ' + (d.vendor_name || 'ઇન-હાઉસ');

    // Metrics
    document.getElementById('dhist_orig_weight').textContent = parseFloat(m.original_weight || 0).toFixed(3) + ' ct';
    document.getElementById('dhist_curr_weight').textContent = parseFloat(m.current_weight || 0).toFixed(3) + ' ct';
    document.getElementById('dhist_total_loss').textContent = parseFloat(m.total_loss || 0).toFixed(3) + ' ct';
    document.getElementById('dhist_total_tops').textContent = parseFloat(m.total_tops || 0).toFixed(3) + ' ct';

    // Split parts (Children)
    const splitContainer = document.getElementById('dhist_split_container');
    const splitList = document.getElementById('dhist_split_list');
    if (children && children.length > 0) {
        splitContainer.style.display = 'block';
        splitList.innerHTML = children.map(c => `
            <a href="javascript:void(0)" onclick="openDiamondHistoryModal(${c.id})" class="finora-pill finora-pill-amber text-decoration-none font-mono">
                ${window.svgIcon('gem', 'me-1', 12)}${escapeHtml(c.packet_no || c.diamond_no || '')} (${parseFloat(c.current_weight || c.weight || 0).toFixed(3)} ct - ${escapeHtml(c.current_stage || '')})
            </a>
        `).join('');
    } else {
        splitContainer.style.display = 'none';
        splitList.innerHTML = '';
    }

    // Timeline Table
    const tbody = document.getElementById('dhist_timeline_tbody');
    const emptyEl = document.getElementById('dhist_empty_history');
    const countBadge = document.getElementById('dhist_stages_count_badge');

    // Prefer stage_history, fallback or merge with process_history
    let displayRows = [];
    if (history.length > 0) {
        displayRows = history;
    } else if (procHist.length > 0) {
        displayRows = procHist.map(p => ({
            stage_name: p.stage,
            party_name: p.ph_party_name || '',
            given_weight: p.input_weight,
            received_weight: p.output_weight,
            loss_weight: p.loss_weight,
            tops_weight: p.tops_weight,
            assigned_date: p.created_at ? p.created_at.substring(0, 10) : '',
            outgoing_location: p.location || '',
            notes: p.details || p.action || ''
        }));
    }

    countBadge.textContent = displayRows.length + ' સ્ટેજ રેકોર્ડ્સ';

    if (displayRows.length === 0) {
        tbody.innerHTML = '';
        emptyEl.style.display = 'block';
    } else {
        emptyEl.style.display = 'none';
        tbody.innerHTML = displayRows.map((r, idx) => {
            const given = parseFloat(r.given_weight || 0);
            const recv = parseFloat(r.received_weight || 0);
            const loss = parseFloat(r.loss_weight || 0);
            const tops = parseFloat(r.tops_weight || 0);
            const party = r.party_name ? `<span class="fw-semibold text-dark">${window.svgIcon('industry', 'text-primary me-1', 13)}${escapeHtml(r.party_name)}</span>` : '<span class="text-muted">ઇન-હાઉસ</span>';
            const dateStr = r.assigned_date || (r.created_at ? r.created_at.substring(0, 10) : '—');
            const locStr = r.receiving_location || r.outgoing_location || 'Surat';

            return `
                <tr>
                    <td class="ps-3 font-mono text-muted" style="font-size: 0.72rem;">${idx + 1}</td>
                    <td>
                        <span class="finora-pill finora-pill-blue font-mono">
                            ${escapeHtml(r.stage_name || r.stage_code || 'Stage')}
                        </span>
                    </td>
                    <td>${party}</td>
                    <td class="text-end font-mono">${given > 0 ? given.toFixed(3) + ' ct' : '—'}</td>
                    <td class="text-end font-mono fw-bold text-dark">${recv > 0 ? recv.toFixed(3) + ' ct' : (given > 0 ? given.toFixed(3) + ' ct' : '—')}</td>
                    <td class="text-end font-mono ${loss > 0 ? 'text-danger fw-bold' : 'text-muted'}">${loss > 0 ? '-' + loss.toFixed(3) : '0.000'}</td>
                    <td class="text-end font-mono ${tops > 0 ? 'text-warning fw-bold' : 'text-muted'}">${tops > 0 ? '+' + tops.toFixed(3) : '0.000'}</td>
                    <td class="small font-mono">
                        <div>${window.svgIcon('calendar', 'text-muted me-1', 12)}${escapeHtml(dateStr)}</div>
                        <small class="text-muted">${window.svgIcon('location-dot', 'text-danger me-1', 12)}${escapeHtml(locStr)}</small>
                    </td>
                    <td class="pe-3 small text-muted font-mono" style="font-size: 0.74rem;">
                        ${escapeHtml(r.notes || '—')}
                    </td>
                </tr>
            `;
        }).join('');
    }
}
</script>
