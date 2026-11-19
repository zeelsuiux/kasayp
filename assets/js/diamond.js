/**
 * Diamond ERP Interactive JavaScript Engine
 * Automatic Form Calculations, Barcode Listener & Assort Validator
 */

/**
 * Crisp Client-Side Inline SVG Icon Helper
 */
window.svgIcon = function (name, cls, size) {
    const s = size || 14;
    const paths = {
        'dollar-sign': '<line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
        'tag': '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
        'circle-exclamation': '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
        'check': '<polyline points="20 6 9 17 4 12"/>',
        'circle-check': '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'spinner': '<path d="M21 12a9 9 0 1 1-6.219-8.56"/>',
        'gem': '<path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/>',
        'diamond': '<path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/>',
        'clock': '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'building': '<rect width="16" height="20" x="4" y="2" rx="2" ry="2"/><path d="M9 22v-4h6v4"/>',
        'industry': '<path d="M2 20a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8l-7 5V8l-7 5V4a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/>',
        'location-dot': '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'calendar': '<rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/>',
        'eye': '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
        'user': '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'user-plus': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
        'pen-to-square': '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        'arrow-right': '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'trash-can': '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
        'triangle-exclamation': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'circle-xmark': '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'ban': '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
        'wand-magic-sparkles': '<path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/><path d="M5 3v4"/><path d="M19 17v4"/><path d="M3 5h4"/><path d="M17 19h4"/>',
        'check-double': '<path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/>',
        'lock': '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'bolt': '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'layer-group': '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
        'arrows-split-up-and-left': '<line x1="6" x2="6" y1="3" y2="15"/><circle cx="18" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><path d="M18 9a9 9 0 0 1-9 9"/>',
        'barcode': '<path d="M3 5v14"/><path d="M8 5v14"/><path d="M12 5v14"/><path d="M17 5v14"/><path d="M21 5v14"/>',
        'xmark': '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>'
    };
    const inner = paths[name] || paths['circle-exclamation'];
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" width="${s}" height="${s}" class="erp-svg-icon ${cls || ''}" aria-hidden="true">${inner}</svg>`;
};

document.addEventListener('DOMContentLoaded', function () {
    // 1. Diamond Buy/Sell Real-time Formula Calculator
    const calcPriceInr = document.getElementById('calc_price_inr');
    const dollarPerCt = document.getElementById('dollar_per_carat');
    const dollarRate = document.getElementById('dollar_rate');
    const weightInput = document.getElementById('weight');
    const pcsInput = document.getElementById('pcs');
    const paymentInput = document.getElementById('payment_amount');
    const brokeragePct = document.getElementById('brokerage_percent');
    const brokerageAmt = document.getElementById('brokerage_amount');
    const acPercentInput = document.getElementById('ac_percent');
    const gstPercentInput = document.getElementById('gst_percent');
    const gstAmountInput = document.getElementById('gst_amount');
    const acGstInput = document.getElementById('ac_gst_amount');
    const netPaymentInput = document.getElementById('net_payment');
    const purchaseDateInput = document.getElementById('purchase_date') || document.getElementById('sale_date') || document.querySelector('[name="purchase_date"], [name="sale_date"]');
    const dueDaysInput = document.getElementById('due_days') || document.querySelector('[name="due_days"]');
    const dueDateInput = document.getElementById('due_date') || document.getElementById('disp_due_date') || document.querySelector('[name="due_date"]');

    // Robust Local Date Parsing & Formatting (Zero Timezone Shift)
    function parseDateParts(val) {
        if (!val || typeof val !== 'string') return null;
        const s = val.trim();
        if (s.includes('-')) {
            const parts = s.split('-');
            if (parts.length === 3 && parts[0].length === 4) {
                const y = parseInt(parts[0], 10);
                const m = parseInt(parts[1], 10) - 1;
                const d = parseInt(parts[2], 10);
                if (!isNaN(y) && !isNaN(m) && !isNaN(d)) return new Date(y, m, d);
            }
            if (parts.length === 3 && parts[2].length === 4) {
                const d = parseInt(parts[0], 10);
                const m = parseInt(parts[1], 10) - 1;
                const y = parseInt(parts[2], 10);
                if (!isNaN(y) && !isNaN(m) && !isNaN(d)) return new Date(y, m, d);
            }
        }
        if (s.includes('/')) {
            const parts = s.split('/');
            if (parts.length === 3 && parts[2].length === 4) {
                const d = parseInt(parts[0], 10);
                const m = parseInt(parts[1], 10) - 1;
                const y = parseInt(parts[2], 10);
                if (!isNaN(y) && !isNaN(m) && !isNaN(d)) return new Date(y, m, d);
            }
        }
        const d = new Date(s);
        return isNaN(d.getTime()) ? null : d;
    }

    function formatDateISO(d) {
        if (!d || isNaN(d.getTime())) return '';
        const yyyy = d.getFullYear();
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${yyyy}-${mm}-${dd}`;
    }

    function syncDueDateFromDays() {
        if (!purchaseDateInput || !dueDateInput) return;
        const pDate = parseDateParts(purchaseDateInput.value);
        if (!pDate) return;
        const rawDays = dueDaysInput ? dueDaysInput.value : '';
        const days = (rawDays !== '' && !isNaN(rawDays)) ? parseInt(rawDays, 10) : 50;
        const targetDate = new Date(pDate.getFullYear(), pDate.getMonth(), pDate.getDate());
        targetDate.setDate(targetDate.getDate() + days);
        dueDateInput.value = formatDateISO(targetDate);
        syncPresetButtons(days);
    }

    function syncDueDaysFromDate() {
        if (!purchaseDateInput || !dueDaysInput || !dueDateInput) return;
        const pDate = parseDateParts(purchaseDateInput.value);
        const dDate = parseDateParts(dueDateInput.value);
        if (!pDate || !dDate) return;
        const pUtc = Date.UTC(pDate.getFullYear(), pDate.getMonth(), pDate.getDate());
        const dUtc = Date.UTC(dDate.getFullYear(), dDate.getMonth(), dDate.getDate());
        const diffDays = Math.round((dUtc - pUtc) / 86400000);
        const finalDays = diffDays >= 0 ? diffDays : 0;
        dueDaysInput.value = finalDays;
        syncPresetButtons(finalDays);
    }

    function syncPresetButtons(days) {
        document.querySelectorAll('.due-preset-btn').forEach(btn => {
            const btnDays = parseInt(btn.dataset.days, 10);
            if (btnDays === days) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    }

    function recalculateDiamondForm() {
        const currencyMode = document.querySelector('input[name="payment_currency"]:checked')?.value || 'INR';
        const inrBox = document.getElementById('inr_currency_box');
        const usdBox = document.getElementById('usd_currency_box');
        const usdPreview = document.getElementById('usd_conversion_preview');
        const rateModeBadge = document.getElementById('rate_mode_badge');

        let dpc = 0;
        let drate = 0;
        let priceInr = 0;

        if (currencyMode === 'INR') {
            if (inrBox) inrBox.style.display = '';
            if (usdBox) usdBox.style.display = 'none';
            if (calcPriceInr) calcPriceInr.required = true;
            if (dollarPerCt) dollarPerCt.required = false;

            priceInr = parseFloat(calcPriceInr?.value) || 0;
            if (rateModeBadge) {
                rateModeBadge.className = 'finora-pill finora-pill-blue';
                rateModeBadge.innerHTML = `${window.svgIcon('tag', 'me-1', 13)}Indian Currency (₹)`;
            }
        } else {
            // USD Mode
            if (inrBox) inrBox.style.display = 'none';
            if (usdBox) usdBox.style.display = '';
            if (calcPriceInr) calcPriceInr.required = false;
            if (dollarPerCt) dollarPerCt.required = true;

            dpc = parseFloat(dollarPerCt?.value) || 0;
            drate = parseFloat(dollarRate?.value) || 0;

            if (dpc > 0 && drate > 0) {
                const convPrice = Math.round((dpc * drate) * 100) / 100;
                if (calcPriceInr) calcPriceInr.value = convPrice.toFixed(2);
                priceInr = convPrice;
                if (rateModeBadge) {
                    rateModeBadge.className = 'finora-pill finora-pill-green';
                    rateModeBadge.innerHTML = `${window.svgIcon('dollar-sign', 'me-1', 13)}Dollar Deal ($${dpc.toFixed(2)} @ ₹${drate.toFixed(2)})`;
                }
                if (usdPreview) {
                    usdPreview.className = 'alert alert-soft-success py-2 px-3 small mb-0 border';
                    usdPreview.innerHTML = `<strong>કન્વર્ટ કરેલ ભાવ:</strong> ₹${convPrice.toFixed(2)} / Ct ($${dpc.toFixed(2)} × ₹${drate.toFixed(2)})`;
                }
            } else {
                // Stand Dollar deal (Rate pending)
                if (calcPriceInr) calcPriceInr.value = '0.00';
                priceInr = 0;
                if (rateModeBadge) {
                    rateModeBadge.className = 'finora-pill finora-pill-rose';
                    rateModeBadge.innerHTML = `<span class="stand-dollar-dot"></span>Stand Dollar (દર બાકી)`;
                }
                if (usdPreview) {
                    usdPreview.className = 'alert alert-soft-danger py-2 px-3 small mb-0 border border-danger-subtle';
                    usdPreview.innerHTML = `<span class="stand-dollar-dot"></span><strong>Stand Dollar:</strong> ડોલર પ્રતિ કેરેટ $${dpc.toFixed(2)} નોંધાશે; એક્સચેન્જ રેટ ખાલી હોવાથી લૉટ સ્ટેન્ડ ડોલરમાં સેવ થશે.`;
                }
            }
        }

        const wt = Math.max(0, parseFloat(weightInput?.value) || 0);
        const pcsVal = Math.max(0, parseInt(pcsInput?.value) || 0);

        const avgSizeDisp = document.getElementById('display_avg_size');
        if (avgSizeDisp) {
            const avg = (wt > 0 && pcsVal > 0) ? (wt / pcsVal).toFixed(2) : '0.00';
            avgSizeDisp.innerText = avg + ' Ct/Pc';
        }

        const payment = Math.round(wt * priceInr * 100) / 100;
        if (paymentInput) paymentInput.value = payment.toFixed(2);

        // AC % (A/C Deduction)
        const acPct = Math.max(0, parseFloat(acPercentInput?.value) || 0);
        const acAmt = Math.round((payment * (acPct / 100)) * 100) / 100;
        const acAmountHidden = document.getElementById('ac_amount');
        if (acAmountHidden) acAmountHidden.value = acAmt.toFixed(2);

        // Sub Total = Raw Payment - AC Amount
        const subTotal = Math.max(0, Math.round((payment - acAmt) * 100) / 100);

        // Brokerage on Sub Total
        const bpct = Math.max(0, parseFloat(brokeragePct?.value) || 0);
        const bAmt = Math.round((subTotal * (bpct / 100)) * 100) / 100;
        if (brokerageAmt) brokerageAmt.value = bAmt.toFixed(2);

        // GST Expense on Sub Total
        const gstPct = Math.max(0, parseFloat(gstPercentInput?.value) || 0);
        let gst = 0;
        if (gstPercentInput) {
            gst = Math.round((subTotal * (gstPct / 100)) * 100) / 100;
            if (gstAmountInput) gstAmountInput.value = gst.toFixed(2);
        } else if (gstAmountInput) {
            gst = Math.max(0, Math.round((parseFloat(gstAmountInput.value) || 0) * 100) / 100);
        } else if (acGstInput) {
            gst = Math.max(0, Math.round((parseFloat(acGstInput.value) || 0) * 100) / 100);
        }
        if (acGstInput) {
            acGstInput.value = (Math.round((acAmt + gst) * 100) / 100).toFixed(2);
        }

        // Other Expense in INR
        const otherExpenseInput = document.getElementById('other_expense');
        const otherExpense = Math.max(0, parseFloat(otherExpenseInput?.value) || 0);

        // Visionary Price & Total Visionary Price (Always in USD $)
        const visPriceInput = document.getElementById('visionary_price');
        const visPrice = Math.max(0, parseFloat(visPriceInput?.value) || 0);
        const totVisPrice = (visPrice > 0 && wt > 0) ? Math.round(visPrice * wt * 100) / 100 : 0;
        const totVisInput = document.getElementById('total_visionary_price');
        const dispTotVisInput = document.getElementById('disp_total_visionary_price');
        if (totVisInput) totVisInput.value = totVisPrice > 0 ? totVisPrice.toFixed(2) : '';
        if (dispTotVisInput) dispTotVisInput.value = totVisPrice > 0 ? ('$ ' + totVisPrice.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })) : '$ 0.00';
        const dispTermVis = document.getElementById('display_terminal_visionary');
        if (dispTermVis) dispTermVis.innerText = '$ ' + totVisPrice.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        // Party Bill Amount = Sub Total + GST + Other Expense
        const partyBillAmt = Math.round((subTotal + gst + otherExpense) * 100) / 100;

        // Net Payment = Sub Total + Brokerage + GST + Other Expense
        const net = Math.round((subTotal + bAmt + gst + otherExpense) * 100) / 100;
        if (netPaymentInput) netPaymentInput.value = net.toFixed(2);
        const dispNetInput = document.getElementById('disp_net_payment_input');
        if (dispNetInput) dispNetInput.value = '₹ ' + Math.round(net).toLocaleString('en-IN');

        // Auto Due Date Calculation
        if (purchaseDateInput && dueDaysInput && dueDateInput && purchaseDateInput.value && !dueDateInput.value) {
            syncDueDateFromDays();
        }

        // Live Executive Commercial Terminal Updates
        const fmtINR = (val) => '₹ ' + Math.round(Number(val) || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
        const dispWeightVal = document.getElementById('display_weight_val');
        if (dispWeightVal) dispWeightVal.innerText = wt > 0 ? wt.toFixed(3) + ' Ct' : '0.00 Ct';

        const dispPriceVal = document.getElementById('display_price_val');
        if (dispPriceVal) dispPriceVal.innerText = fmtINR(priceInr);

        const dispRaw = document.getElementById('display_raw_amount');
        if (dispRaw) dispRaw.innerText = fmtINR(payment);

        const dispAc = document.getElementById('display_ac_amount');
        if (dispAc) {
            dispAc.innerText = (acAmt > 0 ? '- ' : '') + fmtINR(acAmt);
        }
        const dispAcPct = document.getElementById('display_ac_pct');
        if (dispAcPct) {
            dispAcPct.innerText = acPct.toFixed(2) + '%';
        }

        const dispSubTotal = document.getElementById('display_sub_total');
        if (dispSubTotal) dispSubTotal.innerText = fmtINR(subTotal);

        const dispBrok = document.getElementById('display_brokerage_amount');
        if (dispBrok) dispBrok.innerText = (bAmt > 0 ? '+ ' : '') + fmtINR(bAmt);
        const dispBrokPct = document.getElementById('display_brokerage_pct');
        if (dispBrokPct) dispBrokPct.innerText = bpct.toFixed(2) + '%';

        const dispGst = document.getElementById('display_gst_amount');
        if (dispGst) dispGst.innerText = (gst > 0 ? '+ ' : '') + fmtINR(gst);
        const dispGstPct = document.getElementById('display_gst_pct');
        if (dispGstPct) dispGstPct.innerText = gstPct.toFixed(2) + '%';

        const dispOtherExp = document.getElementById('display_other_expense');
        if (dispOtherExp) dispOtherExp.innerText = (otherExpense > 0 ? '+ ' : '') + fmtINR(otherExpense);

        const dispNet = document.getElementById('display_net_payment');
        if (dispNet) dispNet.innerText = fmtINR(net);

        const stickyNet = document.getElementById('sticky_net_display');
        if (stickyNet) stickyNet.innerText = fmtINR(net);

        const dispPartyBill = document.getElementById('display_party_bill');
        if (dispPartyBill) dispPartyBill.innerText = fmtINR(partyBillAmt);

        const dispPerCt = document.getElementById('display_effective_cost');
        if (dispPerCt) {
            const eff = wt > 0 ? (net / wt) : priceInr;
            const modeSuffix = (currencyMode === 'USD' && dpc > 0 && drate > 0) ? ` ($${dpc.toFixed(2)})` : (currencyMode === 'USD' ? ' (Stand Rate)' : '');
            dispPerCt.innerText = fmtINR(eff) + ' / ct' + modeSuffix;
        }

        // If status is paid, auto-fill paid/received amount
        const pStatusEl = document.querySelector('[name="payment_status"], [name="party_payment_status"]');
        const pPaidInput = document.querySelector('[name="paid_amount"], [name="received_amount"]');
        if (pStatusEl && pStatusEl.value === 'paid' && pPaidInput && (!pPaidInput.dataset.touched || parseFloat(pPaidInput.value) <= 0)) {
            pPaidInput.value = partyBillAmt.toFixed(2);
        }
    }

    const otherExpenseInput = document.getElementById('other_expense');
    const visPriceInput = document.getElementById('visionary_price');
    const calcInputs = [dollarPerCt, dollarRate, calcPriceInr, weightInput, pcsInput, brokeragePct, acPercentInput, gstPercentInput, gstAmountInput, acGstInput, otherExpenseInput, visPriceInput];
    calcInputs.forEach(input => {
        if (input) {
            input.addEventListener('input', recalculateDiamondForm);
            input.addEventListener('change', recalculateDiamondForm);
        }
    });

    // Listen to payment_currency radio changes
    document.querySelectorAll('input[name="payment_currency"]').forEach(r => {
        r.addEventListener('change', recalculateDiamondForm);
    });

    // 1.1 Due Days & Due Date Event Handlers
    document.querySelectorAll('.due-preset-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const days = parseInt(this.dataset.days, 10) || 50;
            if (dueDaysInput) {
                dueDaysInput.value = days;
                syncDueDateFromDays();
                recalculateDiamondForm();
            }
            syncPresetButtons(days);
        });
    });

    if (dueDaysInput) {
        dueDaysInput.addEventListener('input', function () {
            syncDueDateFromDays();
            recalculateDiamondForm();
        });
        dueDaysInput.addEventListener('change', function () {
            syncDueDateFromDays();
            recalculateDiamondForm();
        });
    }

    if (dueDateInput) {
        dueDateInput.addEventListener('input', function () {
            syncDueDaysFromDate();
        });
        dueDateInput.addEventListener('change', function () {
            syncDueDaysFromDate();
        });
    }

    if (purchaseDateInput) {
        purchaseDateInput.addEventListener('input', function () {
            syncDueDateFromDays();
        });
        purchaseDateInput.addEventListener('change', function () {
            syncDueDateFromDays();
        });
    }

    // Run initial calculation & sync due date preset
    if (dueDaysInput && purchaseDateInput && dueDateInput) {
        const initialDays = parseInt(dueDaysInput.value, 10) || 50;
        syncDueDateFromDays();
        syncPresetButtons(initialDays);
    }
    recalculateDiamondForm();

    // 1.2 Conditional Payment Settlement Disclosure
    function updatePaymentSettlementVisibility() {
        const statusEl = document.querySelector('[name="payment_status"], [name="party_payment_status"]');
        const box = document.getElementById('conditional_payment_box');
        const notice = document.getElementById('pending_payment_notice');
        const paidInput = document.querySelector('[name="paid_amount"], [name="received_amount"]');
        const netAmtVal = parseFloat(document.getElementById('net_payment')?.value) || 0;

        if (!statusEl) return;
        const currentStatus = statusEl.value;

        if (currentStatus === 'pending') {
            if (box) box.style.display = 'none';
            if (notice) notice.style.display = 'block';
            if (paidInput) {
                paidInput.value = '0.00';
                delete paidInput.dataset.touched;
            }
        } else {
            if (box) box.style.display = 'block';
            if (notice) notice.style.display = 'none';
            if (currentStatus === 'paid' && paidInput && (parseFloat(paidInput.value) <= 0 || !paidInput.dataset.touched)) {
                paidInput.value = netAmtVal.toFixed(2);
            }
        }
    }

    const pStatusElem = document.querySelector('[name="payment_status"], [name="party_payment_status"]');
    if (pStatusElem) {
        pStatusElem.addEventListener('change', updatePaymentSettlementVisibility);
        updatePaymentSettlementVisibility();
    }

    const paidInputElem = document.querySelector('[name="paid_amount"], [name="received_amount"]');
    if (paidInputElem) {
        paidInputElem.addEventListener('input', function () {
            this.dataset.touched = 'true';
        });
    }

    // 1.5 Strict Non-Negative Numeric Protection (Blocks '-' / negative inputs everywhere)
    document.addEventListener('keydown', function (e) {
        const target = e.target;
        if (target && target.tagName === 'INPUT' && (target.type === 'number' || target.classList.contains('non-negative'))) {
            if (e.key === '-' || e.key === 'Minus' || e.code === 'NumpadSubtract' || e.key === 'e' || e.key === 'E') {
                e.preventDefault();
            }
        }
    });

    document.addEventListener('input', function (e) {
        const target = e.target;
        if (target && target.tagName === 'INPUT' && target.type === 'number') {
            const val = parseFloat(target.value);
            if (!isNaN(val) && val < 0) {
                target.value = Math.abs(val) || 0;
            }
        }
    });

    // 2. NumPad Ergonomics: Enter key moves to next input field (Rapid Entry)
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            const active = document.activeElement;
            if (active && active.tagName === 'INPUT' && active.type !== 'submit' && active.type !== 'button' && active.id !== 'scanner_input') {
                const form = active.form;
                if (form) {
                    const focusables = Array.from(form.querySelectorAll('input:not([type="hidden"]):not([readonly]):not([disabled]), select:not([disabled]), textarea:not([readonly])'));
                    const currentIndex = focusables.indexOf(active);
                    if (currentIndex > -1 && currentIndex < focusables.length - 1) {
                        e.preventDefault();
                        const next = focusables[currentIndex + 1];
                        next.focus();
                        if (next.tagName === 'INPUT' && typeof next.select === 'function') {
                            next.select();
                        }
                    }
                }
            }
        }
    });

    // 3. Auto-select text on input focus (One-shot NumPad replace)
    document.querySelectorAll('input[type="number"], input[type="text"]').forEach(input => {
        input.addEventListener('focus', function () {
            if (this.readOnly) return;
            this.select();
        });
    });

    // 4. Global Hardware Barcode Scanner Listener
    let barcodeBuffer = '';
    let lastKeyTime = Date.now();

    window.addEventListener('keydown', function (e) {
        // If user is focused on an input field, do not hijack scanner keystrokes
        const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isInput = (activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select');

        const currentTime = Date.now();
        if (currentTime - lastKeyTime > 80) {
            barcodeBuffer = ''; // Reset if typing is human speed (>80ms between keystrokes)
        }
        lastKeyTime = currentTime;

        if (e.key === 'Enter') {
            if (barcodeBuffer.length >= 4 && !isInput) {
                console.log('Hardware Barcode scanned:', barcodeBuffer);
                window.location.href = (window.BASE_URL || '/projects/kasyap') + '/modules/process/barcode_scanner.php?barcode=' + encodeURIComponent(barcodeBuffer.trim());
                barcodeBuffer = '';
                e.preventDefault();
            }
        } else if (e.key.length === 1 && !isInput) {
            barcodeBuffer += e.key;
        }
    });

    // 5. Assort Balance Real-time Validator
    window.recalcAssortBalance = function () {
        const roughWeight = parseFloat(document.getElementById('input_rough_weight')?.value) || 0;
        const partWeights = document.querySelectorAll('.part-weight-input');
        let totalAssortedWeight = 0;

        partWeights.forEach(inp => {
            totalAssortedWeight += parseFloat(inp.value) || 0;
        });

        const diff = Math.round((roughWeight - totalAssortedWeight) * 1000) / 1000;
        const totalSpan = document.getElementById('total_assorted_weight');
        const diffSpan = document.getElementById('weight_difference_display');
        const submitBtn = document.getElementById('submit_assort_btn');

        if (totalSpan) totalSpan.innerText = totalAssortedWeight.toFixed(2) + ' ct';
        if (diffSpan) {
            diffSpan.innerText = (diff >= 0 ? '+' : '') + diff.toFixed(2) + ' ct';
            // Allow up to natural loss or exact match
            if (diff >= -0.005 && totalAssortedWeight > 0) {
                diffSpan.className = (Math.abs(diff) <= 0.005) ? 'badge bg-success' : 'badge bg-warning text-dark';
                if (submitBtn) submitBtn.disabled = false;
            } else {
                diffSpan.className = 'badge bg-danger';
                if (submitBtn) submitBtn.disabled = (totalAssortedWeight <= 0 || diff < -0.005);
            }
        }
    };

    // 6. Smart Searchable Select Engine (Auto-enhances dropdowns with >6 options or entity master selects)
    function initSmartSearchableSelects() {
        if (typeof TomSelect === 'undefined') {
            console.warn('TomSelect library not loaded.');
            return;
        }

        const highPriorityNames = [
            'party_id', 'broker_id', 'kapan_id', 'packet_id', 'stock_id', 
            'polish_stock_id', 'rough_purchase_id', 'bank_account_id', 
            'employee_id', 'assort_part_id', 'account_id'
        ];

        const staticIgnoreNames = [
            'type', 'city', 'status', 'account_type', 'payment_status', 
            'stage', 'payment_mode', 'cut'
        ];

        document.querySelectorAll('select').forEach(function (selectEl) {
            // Skip if opted out, already enhanced, or is the label size selector
            if (selectEl.classList.contains('no-search') || selectEl.tomselect || selectEl.id === 'sizeSelector') {
                return;
            }

            const name = (selectEl.name || '').toLowerCase();
            const optionCount = selectEl.options.length;
            const isSearchableClass = selectEl.classList.contains('searchable') || selectEl.classList.contains('select-search') || selectEl.classList.contains('select-tom') || selectEl.classList.contains('select2');
            const isHighPriority = highPriorityNames.includes(name);
            const isStaticIgnore = staticIgnoreNames.includes(name);

            // Skip selects inside modals unless explicitly marked with searchable class
            if (selectEl.closest('.modal') && !isSearchableClass) {
                return;
            }

            // Activate if high-priority, has searchable class, or has > 6 options (and not in static small enums)
            if (isHighPriority || isSearchableClass || (optionCount > 6 && !isStaticIgnore)) {
                try {
                    const isMultiple = selectEl.hasAttribute('multiple');
                    const plugins = isMultiple ? ['remove_button'] : ['dropdown_input'];
                    const ts = new TomSelect(selectEl, {
                        create: isMultiple,
                        maxOptions: 1000,
                        placeholder: selectEl.getAttribute('placeholder') || (isMultiple ? 'શોધો અને પસંદ કરો... (Select multiple)' : 'શોધો અથવા પસંદ કરો... (Search...)'),
                        searchField: ['text'],
                        plugins: plugins,
                        dropdownParent: isMultiple ? null : 'body',
                        render: {
                            no_results: function (data, escape) {
                                return '<div class="no-results p-2 text-muted small">' + window.svgIcon('circle-exclamation', 'me-1 text-warning', 14) + 'કોઈ પરિણામ મળ્યું નથી ("' + escape(data.input) + '")</div>';
                            }
                        }
                    });

                    // Forward change events to native select for any change listener
                    ts.on('change', function () {
                        if (!selectEl._isSyntheticChange) {
                            selectEl._isSyntheticChange = true;
                            selectEl.dispatchEvent(new Event('change', { bubbles: true }));
                            setTimeout(() => { selectEl._isSyntheticChange = false; }, 10);
                        }
                    });
                } catch (e) {
                    console.error('Error initializing TomSelect on', selectEl, e);
                }
            }
        });

        // Sync TomSelect dropdowns inside Bootstrap modals when shown
        document.querySelectorAll('.modal').forEach(function (modalEl) {
            modalEl.addEventListener('shown.bs.modal', function () {
                modalEl.querySelectorAll('select').forEach(function (s) {
                    if (s.tomselect) s.tomselect.sync();
                });
            });
        });

        // Global repositioning on any container scroll (tables, modals, window)
        window.addEventListener('scroll', function () {
            document.querySelectorAll('.tomselected').forEach(function (s) {
                if (s.tomselect && s.tomselect.isOpen) {
                    s.tomselect.positionDropdown();
                }
            });
        }, true);

        window.addEventListener('resize', function () {
            document.querySelectorAll('.tomselected').forEach(function (s) {
                if (s.tomselect && s.tomselect.isOpen) {
                    s.tomselect.positionDropdown();
                }
            });
        });

        // Outside click handler to cleanly close body-attached dropdowns
        document.addEventListener('mousedown', function (e) {
            if (!e.target.closest('.ts-wrapper') && !e.target.closest('.ts-dropdown')) {
                document.querySelectorAll('.tomselected').forEach(function (s) {
                    if (s.tomselect && s.tomselect.isOpen) {
                        s.tomselect.close();
                    }
                });
            }
        });
    }

    window.initSmartSearchableSelects = initSmartSearchableSelects;
    initSmartSearchableSelects();

    // 7. Auto-Label Responsive Tables for Mobile Card View (when table-card-mobile is opted in)
    function autoLabelResponsiveTables() {
        document.querySelectorAll('table.table-card-mobile').forEach(function (table) {
            const thElements = table.querySelectorAll('thead th');
            const headers = Array.from(thElements).map(function (th) {
                return th.innerText.trim();
            });
            if (headers.length === 0) return;

            table.querySelectorAll('tbody tr').forEach(function (row) {
                const cells = row.querySelectorAll('td');
                if (cells.length === 1 && cells[0].hasAttribute('colspan')) return;

                cells.forEach(function (td, index) {
                    if (headers[index] && !td.getAttribute('data-label')) {
                        td.setAttribute('data-label', headers[index]);
                    }
                });
            });
        });
    }

    window.autoLabelResponsiveTables = autoLabelResponsiveTables;
    autoLabelResponsiveTables();

    // 8. Universal Client-Side 1-Click Excel / CSV Table Exporter
    window.exportTableToExcel = function (tableSelector, filename) {
        let table = null;
        if (typeof tableSelector === 'string') {
            table = document.querySelector(tableSelector);
        } else if (tableSelector instanceof HTMLElement) {
            table = tableSelector;
        }

        if (!table) {
            table = document.querySelector('table.table-diamond, table.table');
        }

        if (!table) {
            alert('એક્સપોર્ટ કરવા માટે કોઈ ટેબલ મળ્યું નથી.');
            return;
        }

        filename = filename || ('Export_' + new Date().toISOString().slice(0, 10) + '.csv');
        if (!filename.toLowerCase().endsWith('.csv') && !filename.toLowerCase().endsWith('.xls')) {
            filename += '.csv';
        }

        const rows = [];
        const headerRow = [];
        const thElements = table.querySelectorAll('thead th');
        const ignoreIndices = [];

        thElements.forEach((th, idx) => {
            const txt = th.innerText.trim();
            // Ignore Action columns or elements with .no-export
            if (th.classList.contains('no-export') || txt.toLowerCase().includes('action') || txt.includes('એક્શન') || txt === '') {
                ignoreIndices.push(idx);
            } else {
                headerRow.push('"' + txt.replace(/"/g, '""').replace(/\r?\n|\r/g, ' ') + '"');
            }
        });
        if (headerRow.length > 0) {
            rows.push(headerRow.join(','));
        }

        // Extract body rows
        table.querySelectorAll('tbody tr').forEach(tr => {
            if (tr.querySelector('td[colspan]')) return;

            const rowData = [];
            const cells = tr.querySelectorAll('td');
            cells.forEach((td, idx) => {
                if (ignoreIndices.includes(idx) || td.classList.contains('no-export')) return;

                let text = td.innerText.trim();
                text = text.replace(/\r?\n|\r/g, ' ').replace(/\s+/g, ' ');
                rowData.push('"' + text.replace(/"/g, '""') + '"');
            });

            if (rowData.length > 0) {
                rows.push(rowData.join(','));
            }
        });

        if (rows.length === 0) {
            alert('એક્સપોર્ટ કરવા માટે ડેટા ઉપલબ્ધ નથી.');
            return;
        }

        // UTF-8 BOM so Microsoft Excel renders Gujarati characters properly
        const csvContent = '\uFEFF' + rows.join('\r\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    // Auto-bind any button with [data-export-table]
    document.querySelectorAll('[data-export-table]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const targetTable = this.getAttribute('data-export-table') || 'table';
            const filename = this.getAttribute('data-filename') || 'Export.csv';
            window.exportTableToExcel(targetTable, filename);
        });
    });
});
