<?php
/**
 * Helper Functions & Formatting
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/icons.php';

function e(?string $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function format_inr($amount, bool $showSymbol = true): string {
    $num = round((float)$amount);
    $isNegative = $num < 0;
    $num = abs($num);
    $intPart = (string)$num;

    if (strlen($intPart) > 3) {
        $lastThree = substr($intPart, -3);
        $remaining = substr($intPart, 0, -3);
        $remainingFormatted = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $remaining);
        $intPart = $remainingFormatted . ',' . $lastThree;
    }

    $symbol = $showSymbol ? '₹ ' : '';
    return ($isNegative ? '-' : '') . $symbol . $intPart;
}

function format_carat($weight, bool $showUnit = true): string {
    $num = max(0.0, (float)$weight);
    $unit = $showUnit ? ' ct' : '';
    return number_format($num, 2, '.', '') . $unit;
}

function format_date(?string $date): string {
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    $d = date('d-m-Y', strtotime($date));
    $nobrk = str_replace('-', '&#8209;', $d);
    return '<span class="text-nowrap date-no-wrap" style="display:inline-block; white-space:nowrap !important; word-break:keep-all !important; overflow-wrap:normal !important; font-variant-numeric:tabular-nums;">' . $nobrk . '</span>';
}

function set_flash(string $type, string $message): void {
    $_SESSION['flash_' . $type] = $message;
}

function display_flash(): string {
    $types = [
        'success' => ['class' => 'bg-success text-white', 'icon' => 'circle-check', 'title' => 'સફળતા (Success)'],
        'error'   => ['class' => 'bg-danger text-white', 'icon' => 'triangle-exclamation', 'title' => 'ભૂલ (Error)'],
        'warning' => ['class' => 'bg-warning text-dark', 'icon' => 'circle-exclamation', 'title' => 'ધ્યાન આપો (Warning)'],
        'info'    => ['class' => 'bg-primary text-white', 'icon' => 'circle-info', 'title' => 'માહિતી (Notice)']
    ];

    $hasToast = false;
    $toasts = '';

    foreach ($types as $key => $meta) {
        if (isset($_SESSION['flash_' . $key])) {
            $msg = $_SESSION['flash_' . $key];
            unset($_SESSION['flash_' . $key]);
            $hasToast = true;

            $toasts .= '
            <div class="toast align-items-center border-0 shadow-lg show app-floating-toast" role="alert" aria-live="assertive" aria-atomic="true" data-bs-autohide="true" data-bs-delay="4000" style="min-width: 320px; border-radius: 14px; overflow: hidden; margin-bottom: 10px;">
                <div class="d-flex ' . $meta['class'] . ' p-3 align-items-center">
                    <div class="toast-icon me-2.5 flex-shrink-0">' . svg_icon($meta['icon'], '', 20) . '</div>
                    <div class="toast-body flex-grow-1 p-0 fw-semibold small lh-sm">' . e($msg) . '</div>
                    <button type="button" class="btn-close ' . ($key === 'warning' ? '' : 'btn-close-white') . ' ms-2 m-auto shadow-none" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>';
        }
    }

    if ($hasToast) {
        return '<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090;">' . $toasts . '</div>';
    }

    return '';
}

function render_empty_state(string $title, string $description = '', string $actionHtml = '', string $icon = 'box-open'): string {
    return '
    <div class="text-center py-5 px-3">
        <div class="empty-state-icon mb-3 text-primary opacity-50" style="display: inline-flex; align-items: center; justify-content: center; width: 68px; height: 68px; border-radius: 20px; background: rgba(59, 130, 246, 0.08);">
            ' . svg_icon($icon, '', 32) . '
        </div>
        <h6 class="fw-bold text-dark mb-1 fs-6">' . e($title) . '</h6>
        ' . ($description ? '<p class="text-muted small mb-3 mx-auto" style="max-width: 380px;">' . e($description) . '</p>' : '') . '
        ' . ($actionHtml ? '<div class="mt-2">' . $actionHtml . '</div>' : '') . '
    </div>';
}

function get_due_badge(string $dueDate, string $paymentStatus): string {
    if ($paymentStatus === 'paid') {
        return '<span class="finora-pill finora-pill-green">' . svg_icon('check', 'me-1', 12) . 'Paid</span>';
    }

    $today = new DateTime('today');
    $due = new DateTime($dueDate);
    $diff = (int)$today->diff($due)->format("%r%a");

    if ($diff < 0) {
        $days = abs($diff);
        return '<span class="finora-pill finora-pill-rose">' . svg_icon('clock', 'me-1', 12) . $days . 'd Overdue</span>';
    } elseif ($diff <= 5) {
        return '<span class="finora-pill finora-pill-amber">' . svg_icon('hourglass-half', 'me-1', 12) . $diff . 'd Left</span>';
    } else {
        return '<span class="finora-pill finora-pill-blue">' . svg_icon('calendar', 'me-1', 12) . $diff . 'd બાકી</span>';
    }
}

function generate_next_code(string $prefix, string $table, string $column): string {
    $pdo = getDbConnection();
    $year = date('Y');
    $likePattern = $prefix . '-' . $year . '-%';

    // 1.7 Strict Whitelist of allowed tables and columns to prevent SQL injection
    $allowedMap = [
        'assort_tasks'         => ['task_no'],
        'jangad_vouchers'      => ['jangad_no'],
        'polish_sales'         => ['invoice_no'],
        'kapans'               => ['kapan_code'],
        'polish_purchases'     => ['purchase_no'],
        'polish_stocks'        => ['packet_no'],
        'rough_purchases'      => ['purchase_no'],
        'rough_sales'          => ['invoice_no'],
        'employees'            => ['emp_code'],
        'account_transactions' => ['transaction_no'],
        'packets'              => ['packet_no'],
        'boil_batches'         => ['batch_no'],
        'parties'              => ['party_code']
    ];

    if (!isset($allowedMap[$table]) || !in_array($column, $allowedMap[$table], true)) {
        throw new InvalidArgumentException("Invalid table or column for code generation: {$table}.{$column}");
    }
    
    $stmt = $pdo->prepare("SELECT `{$column}` FROM `{$table}` WHERE `{$column}` LIKE ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$likePattern]);
    $last = $stmt->fetchColumn();

    if ($last) {
        $parts = explode('-', $last);
        $lastSeq = (int)end($parts);
        $nextSeq = str_pad((string)($lastSeq + 1), 4, '0', STR_PAD_LEFT);
    } else {
        $nextSeq = '0001';
    }

    return sprintf('%s-%s-%s', $prefix, $year, $nextSeq);
}

/**
 * Generate a guaranteed unique packet number for polish_stocks
 */
function get_unique_polish_packet_no(PDO $pdo, string $basePktNo): string {
    $candidate = trim($basePktNo);
    if ($candidate === '') {
        $candidate = 'POL-' . date('Ymd-His');
    }
    $suffix = 1;
    $chk = $pdo->prepare("SELECT id FROM polish_stocks WHERE packet_no = ? LIMIT 1");
    $test = $candidate;
    while (true) {
        $chk->execute([$test]);
        if (!$chk->fetch()) {
            return $test;
        }
        $suffix++;
        $test = $candidate . '-' . $suffix;
    }
}

function get_total_company_balance(): float {
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT SUM(current_balance) as total FROM bank_accounts WHERE status = 'active'");
    return (float)($stmt->fetchColumn() ?: 0.00);
}

function record_account_transaction(int $bankAccountId, string $type, float $amount, string $relatedType, ?int $relatedId, ?string $refNo, string $date, ?string $notes = '', ?int $userId = null): void {
    if ($bankAccountId <= 0 || $amount <= 0) return;
    $pdo = getDbConnection();
    
    // Update bank account current balance
    if ($type === 'debit') {
        $upd = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
        $upd->execute([$amount, $bankAccountId]);
    } else {
        $upd = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE id = ?");
        $upd->execute([$amount, $bankAccountId]);
    }

    $txn = $pdo->prepare("INSERT INTO account_transactions (bank_account_id, type, amount, related_type, related_id, reference_no, transaction_date, notes, created_by)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $txn->execute([$bankAccountId, $type, $amount, $relatedType, $relatedId, $refNo, $date, $notes, $userId]);
}

function update_party_balance(int $partyId, float $amountDelta): void {
    if ($partyId <= 0 || $amountDelta == 0) return;
    $pdo = getDbConnection();
    $pdo->prepare("UPDATE parties SET current_balance = current_balance + ? WHERE id = ?")->execute([$amountDelta, $partyId]);
}

function render_svg_barcode(string $code, int $height = 50, float $moduleWidth = 1.35): string {
    $code = trim($code);
    if ($code === '') return '';

    // Standard Code 128 (Subset B) table
    $patterns = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
        '221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
        '221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
        '212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
        '231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
        '231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
        '112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
        '111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
        '214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
        '114131','311141','411131','211412','211214','211232','2331112'
    ];

    $checksum = 104; // Start B
    $sequence = [$patterns[104]];

    $len = strlen($code);
    for ($i = 0; $i < $len; $i++) {
        $ascii = ord($code[$i]);
        $val = ($ascii >= 32 && $ascii <= 126) ? ($ascii - 32) : 0;
        $checksum += $val * ($i + 1);
        $sequence[] = $patterns[$val];
    }

    $checkVal = $checksum % 103;
    $sequence[] = $patterns[$checkVal];
    $sequence[] = $patterns[106]; // Stop pattern

    $quietZone = 12 * $moduleWidth;
    $x = $quietZone;
    $barHeight = $height - 16;
    $rects = '';

    foreach ($sequence as $pat) {
        $patLen = strlen($pat);
        for ($j = 0; $j < $patLen; $j++) {
            $w = (int)$pat[$j] * $moduleWidth;
            if ($j % 2 === 0) {
                // Bar (black)
                $rects .= '<rect x="' . round($x, 2) . '" y="0" width="' . round($w, 2) . '" height="' . $barHeight . '" fill="#0f172a" />';
            }
            $x += $w;
        }
    }

    $totalW = $x + $quietZone;
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . round($totalW, 2) . ' ' . $height . '" width="' . round($totalW, 2) . '" height="' . $height . '">
        <rect width="100%" height="100%" fill="#ffffff"/>
        ' . $rects . '
        <text x="' . round($totalW / 2, 2) . '" y="' . ($height - 3) . '" font-family="monospace" font-size="11" font-weight="700" text-anchor="middle" fill="#0f172a" letter-spacing="1">' . htmlspecialchars($code) . '</text>
    </svg>';

    return $svg;
}

/**
 * Clean Finora Page Header (Important Title Only, Crisp SVG Icon, Zero Clutter)
 */
function render_page_header(string $title, string $subtitle = '', string $icon = 'gem', string $actionsHtml = '', string $tone = 'blue'): string {
    $cleanActions = $actionsHtml ? replace_fa_tags_with_svg($actionsHtml) : '';
    $toneClass = match($tone) {
        'emerald', 'success', 'green' => 'text-success',
        'purple' => 'text-indigo',
        'gold', 'warning', 'amber' => 'text-warning',
        'rose', 'danger' => 'text-danger',
        default => 'text-primary'
    };

    return '
    <div class="erp-page-header d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
        <div class="d-flex align-items-center gap-2.5" style="min-width: 0; flex: 1 1 260px;">
            <span class="erp-title-svg ' . $toneClass . ' flex-shrink-0">' . svg_icon($icon, '', 22) . '</span>
            <h2 class="erp-page-title mb-0 text-truncate">' . $title . '</h2>
        </div>
        ' . ($cleanActions ? '<div class="erp-header-actions d-flex flex-wrap align-items-center gap-2 flex-shrink-0">' . $cleanActions . '</div>' : '') . '
    </div>';
}

/**
 * Clean Finora Segmented Pill Navigation (SVG Icons Only, Zero Count Badge Clutter)
 */
function render_segmented_tabs(array $tabs): string {
    if (empty($tabs)) {
        return '';
    }
    $html = '<div class="nav-segmented-tabs mb-3">';
    foreach ($tabs as $tab) {
        $url    = $tab['url'] ?? '#';
        $label  = $tab['label'] ?? '';
        $icon   = $tab['icon'] ?? 'circle';
        $active = !empty($tab['active']) ? ' active' : '';
        $count  = isset($tab['count']) ? (int)$tab['count'] : null;
        $html .= '<a href="' . e($url) . '" class="nav-segment-item' . $active . '">';
        $html .= svg_icon($icon, '', 16);
        $html .= '<span>' . e($label) . '</span>';
        if ($count !== null && $count > 0) {
            $html .= '<span class="badge rounded-pill bg-danger-subtle text-danger border border-danger-subtle ms-1 px-1.5 py-0.5" style="font-size: 0.72rem; font-weight: 600;">' . $count . '</span>';
        }
        $html .= '</a>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Standardized Master Finance Module Navigation Tabs
 */
function render_finance_tabs(string $activeTab = ''): string {
    $pdo = getDbConnection();
    $duePayableCount = 0;
    $dueReceivableCount = 0;
    try {
        $duePayableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_purchases WHERE party_payment_status != 'paid') + (SELECT COUNT(*) FROM polish_purchases WHERE payment_status != 'paid')")->fetchColumn();
        $dueReceivableCount = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM rough_sales WHERE payment_status != 'paid') + (SELECT COUNT(*) FROM polish_sales WHERE payment_status != 'paid')")->fetchColumn();
    } catch (\Throwable $e) {}

    $financeTabs = [
        [
            'url'    => BASE_URL . '/modules/finance/index.php',
            'label'  => 'નાણાકીય હિસાબ (Overview)',
            'icon'   => 'scale-balanced',
            'active' => ($activeTab === 'overview' || $activeTab === 'index')
        ],
        [
            'url'    => BASE_URL . '/modules/finance/schedule.php',
            'label'  => 'ચૂકવવા બાકી (Payables Schedule)',
            'icon'   => 'calendar-days',
            'count'  => $duePayableCount,
            'active' => ($activeTab === 'schedule' || $activeTab === 'payables')
        ],
        [
            'url'    => BASE_URL . '/modules/finance/receivables.php',
            'label'  => 'વસૂલવા બાકી (Receivables)',
            'icon'   => 'hand-holding-dollar',
            'count'  => $dueReceivableCount,
            'active' => ($activeTab === 'receivables')
        ],
        [
            'url'    => BASE_URL . '/modules/finance/transactions.php',
            'label'  => 'આવક - જાવક રોજમેળ (Cash & Bank Book)',
            'icon'   => 'book-bookmark',
            'active' => ($activeTab === 'transactions')
        ],
        [
            'url'    => BASE_URL . '/modules/finance/accounts.php',
            'label'  => 'Bank & Cash ખાતાઓ',
            'icon'   => 'building-columns',
            'active' => ($activeTab === 'accounts')
        ],
        [
            'url'    => BASE_URL . '/modules/finance/brokerage.php',
            'label'  => 'દલાલી હિસાબ (Brokerage)',
            'icon'   => 'handshake',
            'active' => ($activeTab === 'brokerage')
        ],
        [
            'url'    => BASE_URL . '/modules/finance/networth.php',
            'label'  => 'કંપની નેટવર્થ (Net Worth)',
            'icon'   => 'chart-line',
            'active' => ($activeTab === 'networth')
        ]
    ];

    return render_segmented_tabs($financeTabs);
}

/**
 * Finora Pastel Metric Stat Card (Clean Pastel Gradient + SVG Icon Square + Crisp Figure)
 */
function render_stat_card(
    string $label,
    string $value,
    string $pillText = '',
    string $subText = '',
    string $type = 'blue',
    ?string $link = null,
    string $pillClass = '',
    string $icon = ''
): string {
    // Infer pastel Finora tone seamlessly from legacy callers
    $tone = match ($type) {
        'hero', 'blue' => 'blue',
        'green', 'mint', 'emerald' => 'green',
        'amber', 'gold', 'warning' => 'amber',
        'rose', 'coral', 'danger' => 'rose',
        default => match (true) {
            str_contains($pillClass, 'mint') => 'green',
            str_contains($pillClass, 'coral') => 'rose',
            str_contains($pillClass, 'lavender') || str_contains($pillClass, 'amber') || str_contains($pillClass, 'gold') => 'amber',
            default => 'blue',
        },
    };

    // Infer crisp SVG icon if not explicitly specified
    $iconName = $icon;
    if ($iconName === '' && preg_match('/fa-([a-z0-9-]+)/i', $pillText, $m)) {
        $cand = $m[1];
        if (!in_array($cand, ['solid', 'regular', 'brands'], true)) {
            $iconName = $cand;
        }
    }
    if ($iconName === '') {
        $iconName = match ($tone) {
            'green' => 'arrow-trend-up',
            'amber' => 'coins',
            'rose'  => 'arrow-trend-down',
            default => 'wallet',
        };
    }

    $cleanPillText = trim(strip_tags(replace_fa_tags_with_svg($pillText), '<svg><path><circle><rect><line><polyline><polygon>'));
    $finoraPillTone = match ($tone) {
        'green' => 'finora-pill-green',
        'rose'  => 'finora-pill-rose',
        'amber' => 'finora-pill-amber',
        default => 'finora-pill-blue',
    };
    if (str_contains($pillClass, 'coral')) {
        $finoraPillTone = 'finora-pill-rose';
    } elseif (str_contains($pillClass, 'mint')) {
        $finoraPillTone = 'finora-pill-green';
    }

    $arrowHtml = svg_icon('chevron-right', 'kpi-chevron', 16);
    $wrapStart = $link ? '<a href="' . e($link) . '" class="text-decoration-none d-block h-100">' : '<div class="h-100">';
    $wrapEnd   = $link ? '</a>' : '</div>';

    $html  = $wrapStart;
    $html .= '<div class="stat-card-finora kpi-tone-' . $tone . ' h-100">';
    $html .= '  <div class="d-flex justify-content-between align-items-start mb-2.5">';
    $html .= '    <div class="kpi-icon-tile kpi-icon-' . $tone . '">' . svg_icon($iconName, '', 19) . '</div>';
    $html .= '    <span class="kpi-arrow-wrap">' . $arrowHtml . '</span>';
    $html .= '  </div>';
    $html .= '  <div class="kpi-label">' . e($label) . '</div>';
    $html .= '  <div class="kpi-value">' . $value . '</div>';
    if ($cleanPillText !== '' || $subText !== '') {
        $html .= '  <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">';
        if ($cleanPillText !== '') {
            $html .= '    <span class="finora-pill ' . $finoraPillTone . '">' . $cleanPillText . '</span>';
        }
        if ($subText !== '') {
            $html .= '    <span class="kpi-subtext">' . e($subText) . '</span>';
        }
        $html .= '  </div>';
    }
    $html .= '</div>';
    $html .= $wrapEnd;
    return $html;
}

/**
 * Master Party & Broker Business Roles
 * Enables multi-role selection and strict workflow filtering (e.g. Rough Sale, Polish Sale, Purchases)
 */
function get_party_available_roles(): array {
    return [
        'rough_purchase' => [
            'label_guj' => 'રફ ખરીદી સપ્લાયર',
            'label_en' => 'Rough Purchase Supplier',
            'category' => 'rough',
            'type' => 'party',
            'badge' => 'bg-primary-subtle text-primary'
        ],
        'rough_purchase_broker' => [
            'label_guj' => 'રફ ખરીદી દલાલ',
            'label_en' => 'Rough Purchase Broker',
            'category' => 'rough',
            'type' => 'broker',
            'badge' => 'bg-warning-subtle text-warning-emphasis'
        ],
        'rough_sale' => [
            'label_guj' => 'રફ વેચાણ ગ્રાહક',
            'label_en' => 'Rough Sell Customer',
            'category' => 'rough',
            'type' => 'party',
            'badge' => 'bg-success-subtle text-success'
        ],
        'rough_sale_broker' => [
            'label_guj' => 'રફ વેચાણ દલાલ',
            'label_en' => 'Rough Sell Broker',
            'category' => 'rough',
            'type' => 'broker',
            'badge' => 'bg-warning-subtle text-warning-emphasis'
        ],
        'polish_purchase' => [
            'label_guj' => 'પોલિશ ખરીદી સપ્લાયર',
            'label_en' => 'Polish Purchase Supplier',
            'category' => 'polish',
            'type' => 'party',
            'badge' => 'bg-info-subtle text-info'
        ],
        'polish_purchase_broker' => [
            'label_guj' => 'પોલિશ ખરીદી દલાલ',
            'label_en' => 'Polish Purchase Broker',
            'category' => 'polish',
            'type' => 'broker',
            'badge' => 'bg-warning-subtle text-warning-emphasis'
        ],
        'polish_sale' => [
            'label_guj' => 'પોલિશ વેચાણ ગ્રાહક',
            'label_en' => 'Polish Sell Customer',
            'category' => 'polish',
            'type' => 'party',
            'badge' => 'bg-success-subtle text-success'
        ],
        'polish_sale_broker' => [
            'label_guj' => 'પોલિશ વેચાણ દલાલ',
            'label_en' => 'Polish Sell Broker',
            'category' => 'polish',
            'type' => 'broker',
            'badge' => 'bg-warning-subtle text-warning-emphasis'
        ],
        'outsource_galaxy' => [
            'label_guj' => 'Galaxy Scanner Hub',
            'label_en' => 'Galaxy Scanner Vendor',
            'category' => 'vendor',
            'type' => 'vendor',
            'badge' => 'bg-secondary-subtle text-secondary'
        ],
        'outsource_ls' => [
            'label_guj' => 'Laser Sawing (LS) Center',
            'label_en' => 'Laser Sawing Vendor',
            'category' => 'vendor',
            'type' => 'vendor',
            'badge' => 'bg-secondary-subtle text-secondary'
        ],
        'outsource_factory' => [
            'label_guj' => 'પોલિશ કારખાનું',
            'label_en' => 'Polish Factory Unit',
            'category' => 'vendor',
            'type' => 'vendor',
            'badge' => 'bg-secondary-subtle text-secondary'
        ],
        'lab_gia' => [
            'label_guj' => 'GIA / IGI લેબોરેટરી',
            'label_en' => 'Certification Lab',
            'category' => 'vendor',
            'type' => 'vendor',
            'badge' => 'bg-dark-subtle text-dark'
        ],
    ];
}

/**
 * Filter Parties by Business Role (with graceful legacy fallback)
 */
function get_parties_by_role(string $role, string $fallbackType = 'party', bool $includeBlocked = false): array {
    $pdo = getDbConnection();
    $sql = "SELECT id, party_name, city, roles, party_type, status 
            FROM parties 
            WHERE (" . ($includeBlocked ? "1=1" : "status != 'blocked'") . ")
              AND (
                (roles IS NOT NULL AND roles != '' AND roles != '[]' AND roles LIKE :rolePattern)
                OR 
                ((roles IS NULL OR roles = '' OR roles = '[]') AND party_type IN (:fbType, 'both'))
            )
            ORDER BY party_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'rolePattern' => '%"' . $role . '"%',
        'fbType' => $fallbackType
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}



/**
 * Check if a party/broker/outsource vendor is blocked
 */
function is_party_blocked(int $partyId): bool {
    if ($partyId <= 0) {
        return false;
    }
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT status FROM parties WHERE id = ?");
    $stmt->execute([$partyId]);
    return ($stmt->fetchColumn() === 'blocked');
}

/**
 * Get party block details (reason, date)
 */
function get_party_block_info(int $partyId): ?array {
    if ($partyId <= 0) {
        return null;
    }
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, party_name, status, block_reason, blocked_at FROM parties WHERE id = ?");
    $stmt->execute([$partyId]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

/**
 * Render visual badge pills for assigned roles
 */
function render_party_role_badges(mixed $roles): string {
    if (is_string($roles)) {
        $roles = json_decode($roles, true);
    }
    if (!is_array($roles) || empty($roles)) {
        return '<span class="finora-pill finora-pill-slate">General</span>';
    }
    $all = get_party_available_roles();
    $html = '<div class="d-inline-flex flex-wrap gap-1">';
    foreach ($roles as $r) {
        if (isset($all[$r])) {
            $def = $all[$r];
            $tone = match ($def['type'] ?? '') {
                'broker' => 'amber',
                'vendor' => 'blue',
                'customer', 'buyer' => 'green',
                default => 'slate',
            };
            $html .= '<span class="finora-pill finora-pill-' . $tone . '" style="font-size: 0.70rem; padding: 2px 8px;">' . e($def['label_guj']) . '</span>';
        }
    }
    $html .= '</div>';
    return $html;
}

/**
 * Get active assort part names for dynamic dropdowns with tags
 */
function get_active_assort_part_names(): array {
    $pdo = getDbConnection();
    return $pdo->query("SELECT id, name, COALESCE(tag, 'mfg') as tag, sort_order FROM assort_part_names WHERE status = 'active' ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Render stylish badge for Assort Part Tag (MFG, Sale, MFG Later)
 */
function get_assort_tag_badge(string $tag): string {
    $norm = strtolower(trim($tag));
    if ($norm === 'sale' || $norm === 'out_sell') {
        return '<span class="badge bg-success-subtle text-success border font-mono">🏷️ Sell (વેચાણ)</span>';
    } elseif ($norm === 'mfg_later' || $norm === 'low_number_mfg') {
        return '<span class="badge bg-warning-subtle text-warning-emphasis border font-mono">⏳ MFG Later (ઓછા નંબર)</span>';
    } else {
        return '<span class="badge bg-primary-subtle text-primary border font-mono">⚙️ MFG (પ્રક્રિયા)</span>';
    }
}

/**
 * Map Assort Part Tag to database part_type code
 */
function map_assort_tag_to_part_type(string $tag): string {
    $norm = strtolower(trim($tag));
    if ($norm === 'sale' || $norm === 'out_sell') {
        return 'out_sell';
    } elseif ($norm === 'mfg_later' || $norm === 'low_number_mfg') {
        return 'low_number_mfg';
    } else {
        return 'mfg';
    }
}

/**
 * Get active assort part types for dynamic dropdowns
 */
function get_active_assort_part_types(): array {
    $pdo = getDbConnection();
    return $pdo->query("SELECT id, type_code, type_name, sort_order FROM assort_part_types WHERE status = 'active' ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get all available rough packets (from Assort parts & direct rough purchases) for Kapan creation
 */
function get_available_rough_packets(int $currentKapanId = 0): array {
    $pdo = getDbConnection();
    if (!$pdo->inTransaction()) {
        ensure_boil_reassort_columns();
    }
    $packets = [];

    // 1. Available Assort Parts (supports both Rough Purchase and Boil Re-Assort lots)
    $sqlParts = "
        SELECT ap.id, ap.part_name, ap.pcs, ap.weight, ap.part_type, ap.status,
               ap.assort_number, ap.rough_number, ap.rough_purchase_id,
               ap.source_type, ap.reassort_lot_id, ap.lot_no, ap.cost_per_carat as part_cost_per_carat,
               at.id as assort_task_id, at.task_no, rp.id as rp_id, rp.purchase_no, rp.price_per_carat_inr
        FROM assort_parts ap
        JOIN assort_tasks at ON ap.assort_task_id = at.id
        LEFT JOIN rough_purchases rp ON COALESCE(ap.rough_purchase_id, at.rough_purchase_id) = rp.id
        WHERE (
            (ap.status = 'available' AND ap.id NOT IN (
                SELECT DISTINCT assort_part_id FROM packets WHERE assort_part_id IS NOT NULL AND kapan_id != ?
            ))
            OR ap.id IN (
                SELECT rough_packet_id FROM kapan_rough_mapping WHERE kapan_id = ? AND rough_packet_type = 'assort_part'
            )
        )
        AND ap.part_type IN ('mfg', 'low_number_mfg', 'custom')
        ORDER BY ap.id DESC
    ";
    $stmtParts = $pdo->prepare($sqlParts);
    $stmtParts->execute([$currentKapanId, $currentKapanId]);
    while ($row = $stmtParts->fetch(PDO::FETCH_ASSOC)) {
        $pricePerCt = ((float)($row['part_cost_per_carat'] ?? 0) > 0)
            ? (float)$row['part_cost_per_carat']
            : (float)($row['price_per_carat_inr'] ?? 0);
        $cost = round((float)$row['weight'] * $pricePerCt, 2);
        $isReassort = (($row['source_type'] ?? 'rough_purchase') === 'boil_reassort');
        $roughNo = !empty($row['lot_no'])
            ? $row['lot_no']
            : (!empty($row['rough_number']) ? $row['rough_number'] : ($row['purchase_no'] ?? $row['task_no']));
        $assortNo = !empty($row['assort_number']) ? $row['assort_number'] : ($roughNo . '.' . $row['id']);
        $refLabel = $isReassort
            ? ('Boil Re-Assort Lot: ' . $roughNo)
            : ($row['purchase_no'] ?? $roughNo);

        $packets[] = [
            'key'               => 'part_' . $row['id'],
            'type'              => 'assort_part',
            'source_type'       => $row['source_type'] ?? 'rough_purchase',
            'reassort_lot_id'   => !empty($row['reassort_lot_id']) ? (int)$row['reassort_lot_id'] : null,
            'lot_no'            => $row['lot_no'] ?? null,
            'id'                => (int)$row['id'],
            'assort_task_id'    => (int)$row['assort_task_id'],
            'rough_purchase_id' => !empty($row['rp_id']) ? (int)$row['rp_id'] : null,
            'assort_number'     => $assortNo,
            'rough_number'      => $roughNo,
            'part_name'         => $row['part_name'],
            'purchase_no'       => $refLabel,
            'task_no'           => $row['task_no'],
            'pcs'               => (int)$row['pcs'],
            'weight'            => (float)$row['weight'],
            'price_per_carat'   => $pricePerCt,
            'rough_cost'        => $cost,
            'display_text'      => $assortNo . ' – ' . $row['part_name'] . ' [' . $refLabel . '] — ' . format_carat($row['weight']) . ' / ' . (int)$row['pcs'] . ' Pcs — ' . format_inr($cost)
        ];
    }

    // 2. Available Direct Rough Purchases
    $sqlRough = "
        SELECT rp.id, rp.purchase_no, rp.available_pcs, rp.available_weight, rp.price_per_carat_inr, p.party_name
        FROM rough_purchases rp
        JOIN parties p ON rp.party_id = p.id
        WHERE (rp.available_weight > 0 AND rp.status IN ('in_stock', 'in_assort'))
        OR rp.id IN (
            SELECT rough_packet_id FROM kapan_rough_mapping WHERE kapan_id = ? AND rough_packet_type = 'rough_purchase'
        )
        ORDER BY rp.id DESC
    ";
    $stmtRough = $pdo->prepare($sqlRough);
    $stmtRough->execute([$currentKapanId]);
    while ($row = $stmtRough->fetch(PDO::FETCH_ASSOC)) {
        $cost = round((float)$row['available_weight'] * (float)$row['price_per_carat_inr'], 2);
        $packets[] = [
            'key'               => 'rough_' . $row['id'],
            'type'              => 'rough_purchase',
            'id'                => (int)$row['id'],
            'assort_task_id'    => null,
            'rough_purchase_id' => (int)$row['id'],
            'assort_number'     => $row['purchase_no'],
            'rough_number'      => $row['purchase_no'],
            'part_name'         => 'Direct Purchase Lot',
            'purchase_no'       => $row['purchase_no'],
            'task_no'           => 'Direct Lot',
            'pcs'               => (int)$row['available_pcs'],
            'weight'            => (float)$row['available_weight'],
            'price_per_carat'   => (float)$row['price_per_carat_inr'],
            'rough_cost'        => $cost,
            'display_text'      => $row['purchase_no'] . ' (Direct Lot) — ' . format_carat($row['available_weight']) . ' / ' . (int)$row['available_pcs'] . ' Pcs — ' . format_inr($cost)
        ];
    }

    return $packets;
}

/**
 * Generate hierarchical Assort Number (e.g. 01.1, 01.2)
 */
function generate_assort_number(string $roughNumber, int $partIndex): string {
    $cleanRough = trim($roughNumber);
    return $cleanRough . '.' . $partIndex;
}

/**
 * Generate individual 1 PC diamond records for each rough/assort packet assigned to a Kapan.
 * Core Rule: 1 PC = 1 Individual Diamond = 1 Unique Hierarchical Number (e.g. 01.1.1 .. 01.1.30)
 * Reconciles total weights exactly and guarantees zero duplication on edit.
 */
function generate_kapan_diamonds_from_rough_packets(int $kapanId, string $kapanCode, array $selectedPackets): array {
    $pdo = getDbConnection();
    if (!$pdo->inTransaction()) {
        ensure_boil_reassort_columns();
    }
    $generated = [];

    $checkExistingStmt = $pdo->prepare("SELECT id, diamond_no, packet_no, weight FROM packets WHERE kapan_id = ? AND diamond_no = ?");
    $checkGlobalStmt = $pdo->prepare("SELECT id, kapan_id FROM packets WHERE packet_no = ? OR barcode = ?");
    
    $insertPktStmt = $pdo->prepare("
        INSERT INTO packets (
            kapan_id, rough_purchase_id, rough_number, assort_task_id, assort_part_id, assort_number,
            kapan_number, diamond_no, packet_no, barcode, pcs, weight, original_weight, current_weight,
            split_depth, current_stage, current_location, status, diamond_status,
            origin_reassort_lot_id, origin_reassort_lot_no
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, 1, ?, ?, ?,
            0, 'galaxy', 'Surat', 'in_process', 'available',
            ?, ?
        )
    ");

    foreach ($selectedPackets as $pkt) {
        $totalPcs = (int)($pkt['pcs'] ?? 0);
        $totalWeight = round((float)($pkt['weight'] ?? 0.000), 3);
        $roughNo = trim((string)($pkt['rough_number'] ?? $pkt['purchase_no'] ?? 'R'));
        $roughId = !empty($pkt['rough_purchase_id']) ? (int)$pkt['rough_purchase_id'] : ($pkt['type'] === 'rough_purchase' ? (int)$pkt['id'] : null);
        $assortTaskId = !empty($pkt['assort_task_id']) ? (int)$pkt['assort_task_id'] : null;
        $assortPartId = ($pkt['type'] === 'assort_part') ? (int)$pkt['id'] : null;
        $assortNo = !empty($pkt['assort_number']) ? trim((string)$pkt['assort_number']) : ($pkt['type'] === 'assort_part' ? "{$roughNo}.{$assortPartId}" : $roughNo);
        $originReassortLotId = !empty($pkt['reassort_lot_id']) ? (int)$pkt['reassort_lot_id'] : null;
        $originReassortLotNo = !empty($pkt['lot_no']) ? trim((string)$pkt['lot_no']) : null;

        if ($totalPcs <= 0 || $totalWeight <= 0) {
            continue;
        }

        // Per user requirement: individual diamond weights start BLANK (0.000) instead of average,
        // so the user can enter exact individual weights manually.
        $indivWeight = 0.000;

        // Check highest existing sequence number for this assort prefix in other kapans to prevent any duplication
        $maxSeqStmt = $pdo->prepare("
            SELECT MAX(CAST(SUBSTRING(packet_no, LENGTH(?) + 2) AS UNSIGNED))
            FROM packets
            WHERE packet_no LIKE CONCAT(?, '.%')
              AND kapan_id != ?
        ");
        $maxSeqStmt->execute([$assortNo, $assortNo, $kapanId]);
        $baseSequenceOffset = (int)$maxSeqStmt->fetchColumn();

        for ($seq = 1; $seq <= $totalPcs; $seq++) {
            // Ideal standard sequence number, offset if prefix was used in another Kapan
            $seqNumber = $baseSequenceOffset + $seq;
            $targetDiamondNo = "{$assortNo}.{$seqNumber}";

            // 1. Check if this diamond ALREADY exists for THIS Kapan (e.g. edit mode / re-save)
            $checkExistingStmt->execute([$kapanId, $targetDiamondNo]);
            $existingRow = $checkExistingStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingRow) {
                $generated[] = [
                    'id'         => (int)$existingRow['id'],
                    'diamond_no' => $existingRow['diamond_no'],
                    'weight'     => (float)$existingRow['weight'],
                    'created'    => false
                ];
                continue;
            }

            // 2. Guarantee GLOBAL uniqueness across all kapans & packets
            $uniqueDiamondNo = $targetDiamondNo;
            $uniqueBarcode = "BAR-{$uniqueDiamondNo}";

            while (true) {
                $checkGlobalStmt->execute([$uniqueDiamondNo, $uniqueBarcode]);
                $collidingRow = $checkGlobalStmt->fetch(PDO::FETCH_ASSOC);

                if (!$collidingRow) {
                    // Truly unique globally!
                    break;
                }

                // If collision is with this same kapan, we reuse it
                if ((int)$collidingRow['kapan_id'] === $kapanId) {
                    break;
                }

                // Collision with another Kapan! Increment sequence number to avoid duplicate entry violation
                $seqNumber++;
                $uniqueDiamondNo = "{$assortNo}.{$seqNumber}";
                $uniqueBarcode = "BAR-{$uniqueDiamondNo}";
            }

            // Check again if found candidate already exists in this Kapan
            $checkExistingStmt->execute([$kapanId, $uniqueDiamondNo]);
            $existingRow = $checkExistingStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingRow) {
                $generated[] = [
                    'id'         => (int)$existingRow['id'],
                    'diamond_no' => $existingRow['diamond_no'],
                    'weight'     => (float)$existingRow['weight'],
                    'created'    => false
                ];
            } else {
                $insertPktStmt->execute([
                    $kapanId,
                    $roughId,
                    $roughNo,
                    $assortTaskId,
                    $assortPartId,
                    $assortNo,
                    $kapanCode,
                    $uniqueDiamondNo,
                    $uniqueDiamondNo,
                    $uniqueBarcode,
                    $indivWeight,
                    $indivWeight,
                    $indivWeight,
                    $originReassortLotId,
                    $originReassortLotNo
                ]);
                $newId = (int)$pdo->lastInsertId();
                $generated[] = [
                    'id'         => $newId,
                    'diamond_no' => $uniqueDiamondNo,
                    'weight'     => $indivWeight,
                    'created'    => true
                ];
            }
        }
    }

    return $generated;
}

/**
 * Get all mapped rough packets for a Kapan
 */
function get_kapan_rough_mapping(int $kapanId): array {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT * FROM kapan_rough_mapping WHERE kapan_id = ? ORDER BY id ASC");
    $stmt->execute([$kapanId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}



/**
 * Get parties filtered by Jangad category (Type)
 */
function get_parties_by_jangad_category(?string $category = null, bool $includeBlocked = false): array {
    $pdo = getDbConnection();
    $sql = "SELECT id, party_name, party_type, roles, city, phone, status FROM parties WHERE " . ($includeBlocked ? "1=1" : "(status != 'blocked' OR status IS NULL)");

    if (!empty($category)) {
        switch ($category) {
            case 'outsource_galaxy':
                $sql .= " AND (party_type = 'outsource_galaxy' OR roles LIKE '%outsource_galaxy%')";
                break;
            case 'outsource_ls':
                $sql .= " AND (party_type = 'outsource_ls' OR roles LIKE '%outsource_ls%')";
                break;
            case 'outsource_factory':
                $sql .= " AND (party_type = 'outsource_factory' OR roles LIKE '%outsource_factory%')";
                break;
            case 'lab_gia':
                $sql .= " AND (party_type = 'lab_gia' OR roles LIKE '%lab_gia%')";
                break;
            case 'memo_broker':
                $sql .= " AND (party_type IN ('broker', 'both') OR roles LIKE '%broker%')";
                break;
            case 'memo_party':
                $sql .= " AND (party_type IN ('party', 'both') OR roles LIKE '%sale%' OR roles LIKE '%party%')";
                break;
        }
    }

    $sql .= " ORDER BY party_name ASC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Get available individual diamonds (packets) for a Kapan for Jangad Assignment
 */
function get_kapan_available_diamonds(int $kapanId, int $currentJangadId = 0): array {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT id, diamond_no, packet_no, barcode, pcs, weight, original_weight, current_weight,
               assort_number, rough_number, split_depth, parent_diamond_no, current_stage, status, diamond_status, jangad_id
        FROM packets
        WHERE kapan_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$kapanId]);

    $diamonds = [];
    $seq = 1;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Preferred identifier is hierarchical diamond_no, fallback to packet_no
        $diamondNo = !empty($row['diamond_no']) ? $row['diamond_no'] : $row['packet_no'];
        $assignNo = $diamondNo;

        // Diamond is available if it has no jangad_id or belongs to current jangad being edited,
        // and its diamond_status is available or returned (not split, completed or tops)
        $isAssignedToOther = (!empty($row['jangad_id']) && (int)$row['jangad_id'] !== $currentJangadId);
        $isArchivedOrSplit = in_array($row['diamond_status'], ['split', 'completed', 'tops'], true);

        $currWeight = ((float)$row['current_weight'] > 0) ? (float)$row['current_weight'] : (float)$row['weight'];

        $diamonds[] = [
            'id'                => (int)$row['id'],
            'diamond_no'        => $diamondNo,
            'assign_number'     => $assignNo,
            'packet_no'         => $row['packet_no'],
            'barcode'           => $row['barcode'],
            'pcs'               => (int)$row['pcs'],
            'weight'            => $currWeight,
            'original_weight'   => (float)$row['original_weight'],
            'current_weight'    => $currWeight,
            'assort_number'     => $row['assort_number'],
            'rough_number'      => $row['rough_number'],
            'split_depth'       => (int)$row['split_depth'],
            'parent_diamond_no' => $row['parent_diamond_no'],
            'current_stage'     => $row['current_stage'],
            'status'            => $row['status'],
            'diamond_status'    => $row['diamond_status'],
            'is_assigned'       => ($isAssignedToOther || $isArchivedOrSplit),
            'display_label'     => "{$diamondNo} (" . number_format($currWeight, 3) . " Ct)"
        ];
        $seq++;
    }

    return $diamonds;
}

/**
 * Get comprehensive Kapan-wise diamond assignments and returns for a Jangad
 */
function get_jangad_kapan_assignments(int $jangadId): array {
    $pdo = getDbConnection();

    // Fetch Kapans linked to this Jangad
    $kStmt = $pdo->prepare("
        SELECT jk.*, k.kapan_code, k.kapan_name, k.total_pcs as kapan_total_pcs, k.total_weight as kapan_total_weight
        FROM jangad_kapans jk
        JOIN kapans k ON jk.kapan_id = k.id
        WHERE jk.jangad_id = ?
        ORDER BY jk.id ASC
    ");
    $kStmt->execute([$jangadId]);
    $kapans = $kStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all assignments for this Jangad
    $aStmt = $pdo->prepare("
        SELECT ja.*, p.packet_no, p.barcode, p.current_stage
        FROM jangad_assignments ja
        JOIN packets p ON ja.original_diamond_id = p.id
        WHERE ja.jangad_id = ?
        ORDER BY ja.id ASC
    ");
    $aStmt->execute([$jangadId]);
    $allAssignments = $aStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all returns for this Jangad
    $rStmt = $pdo->prepare("
        SELECT jr.*
        FROM jangad_returns jr
        WHERE jr.jangad_id = ?
        ORDER BY jr.id ASC
    ");
    $rStmt->execute([$jangadId]);
    $allReturns = $rStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group returns by assignment_id
    $returnsByAssignment = [];
    foreach ($allReturns as $ret) {
        $returnsByAssignment[$ret['assignment_id']][] = $ret;
    }

    // Group assignments by jangad_kapan_id
    $assignmentsByKapan = [];
    foreach ($allAssignments as $assign) {
        $assign['returns'] = $returnsByAssignment[$assign['id']] ?? [];
        $assignmentsByKapan[$assign['jangad_kapan_id']][] = $assign;
    }

    foreach ($kapans as &$k) {
        $k['assignments'] = $assignmentsByKapan[$k['id']] ?? [];
    }
    unset($k);

    return $kapans;
}

/**
 * Generate next hierarchical Sub Number for diamond splitting (e.g. 1.1, 1.2, 1.3 or 01.1.1.1, 01.1.1.2)
 */
function generate_next_sub_number(int $assignmentId, string $baseAssignNumber): string {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT sub_number FROM jangad_returns WHERE assignment_id = ? ORDER BY id ASC");
    $stmt->execute([$assignmentId]);
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($existing)) {
        return $baseAssignNumber . '.1';
    }

    $maxSuffix = 0;
    foreach ($existing as $sub) {
        if (preg_match('/\.(\d+)$/', $sub, $m)) {
            $val = (int)$m[1];
            if ($val > $maxSuffix) {
                $maxSuffix = $val;
            }
        }
    }

    return $baseAssignNumber . '.' . ($maxSuffix + 1);
}

/**
 * Record Jangad Diamond Split Return.
 * Creates a brand-new individual Sub-Diamond record in `packets` with hierarchical numbering (e.g. 01.1.1.1),
 * links parent-child relationships, preserves weight reconciliations, and logs to `jangad_returns`.
 */
function record_diamond_split_return(
    int $assignmentId,
    int $jangadId,
    float $receivedWeight,
    string $receivedDate,
    string $notes = '',
    ?int $userId = null
): array {
    $pdo = getDbConnection();

    // 1. Fetch assignment
    $asgnStmt = $pdo->prepare("SELECT ja.*, jv.jangad_no FROM jangad_assignments ja JOIN jangad_vouchers jv ON ja.jangad_id = jv.id WHERE ja.id = ? AND ja.jangad_id = ?");
    $asgnStmt->execute([$assignmentId, $jangadId]);
    $asgn = $asgnStmt->fetch(PDO::FETCH_ASSOC);

    if (!$asgn) {
        throw new \InvalidArgumentException('અમાન્ય ડાયમંડ ફાળવણી (Assignment record not found).');
    }
    if ($asgn['status'] === 'completed') {
        throw new \InvalidArgumentException("Assign #{$asgn['assign_number']} પહેલેથી જ પૂર્ણ (Completed) થઈ ગયેલ છે.");
    }
    if ($receivedWeight <= 0) {
        throw new \InvalidArgumentException('કૃપા કરીને 0 થી વધુ માન્ય જમા વજન (Received Weight) દાખલ કરો.');
    }

    $currentPending = round((float)$asgn['assigned_weight'] - (float)$asgn['returned_weight'], 3);
    if ($receivedWeight > $currentPending + 0.0001) {
        throw new \InvalidArgumentException("જમા વજન ({$receivedWeight} Ct) બાકી પેન્ડિંગ વજન ({$currentPending} Ct) કરતાં વધુ હોઈ શકે નહીં.");
    }

    // 2. Fetch parent diamond from packets
    $parentStmt = $pdo->prepare("SELECT * FROM packets WHERE id = ?");
    $parentStmt->execute([$asgn['original_diamond_id']]);
    $parentDiamond = $parentStmt->fetch(PDO::FETCH_ASSOC);

    $baseDiamondNo = !empty($parentDiamond['diamond_no']) ? $parentDiamond['diamond_no'] : (!empty($asgn['assign_number']) ? $asgn['assign_number'] : (string)$asgn['original_diamond_id']);
    
    // Generate hierarchical Sub Number: e.g. 01.1.1.1
    $subNumber = generate_next_sub_number($assignmentId, $baseDiamondNo);
    $subBarcode = 'BAR-' . $subNumber;

    // 3. Insert NEW Sub-Diamond record into packets table
    $insSubPkt = $pdo->prepare("
        INSERT INTO packets (
            kapan_id, rough_purchase_id, rough_number, assort_task_id, assort_part_id, assort_number,
            kapan_number, diamond_no, packet_no, barcode, pcs, weight, original_weight, current_weight,
            parent_diamond_id, parent_diamond_no, split_depth, current_stage, current_location,
            status, diamond_status
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, 1, ?, ?, ?,
            ?, ?, ?, 'polish_return', 'Surat',
            'in_process', 'available'
        )
    ");

    $splitDepth = !empty($parentDiamond) ? ((int)$parentDiamond['split_depth'] + 1) : 1;
    $insSubPkt->execute([
        $asgn['kapan_id'],
        $parentDiamond['rough_purchase_id'] ?? null,
        $parentDiamond['rough_number'] ?? null,
        $parentDiamond['assort_task_id'] ?? null,
        $parentDiamond['assort_part_id'] ?? null,
        $parentDiamond['assort_number'] ?? null,
        $parentDiamond['kapan_number'] ?? null,
        $subNumber,
        $subNumber,
        $subBarcode,
        $receivedWeight,
        $receivedWeight,
        $receivedWeight,
        $asgn['original_diamond_id'],
        $baseDiamondNo,
        $splitDepth
    ]);
    $subDiamondId = (int)$pdo->lastInsertId();

    // 4. Insert into jangad_returns
    $insRet = $pdo->prepare("
        INSERT INTO jangad_returns (
            assignment_id, jangad_id, kapan_id, assign_number,
            sub_number, sub_diamond_id, diamond_no, received_weight, received_pcs, received_at, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
    ");
    $insRet->execute([
        $assignmentId,
        $jangadId,
        $asgn['kapan_id'],
        $asgn['assign_number'],
        $subNumber,
        $subDiamondId,
        $subNumber,
        $receivedWeight,
        $receivedDate,
        $notes
    ]);
    $returnId = (int)$pdo->lastInsertId();

    // 5. Update jangad_assignments
    $newReturnedWeight = round((float)$asgn['returned_weight'] + $receivedWeight, 3);
    $newPendingWeight = max(0.0, round((float)$asgn['assigned_weight'] - $newReturnedWeight, 3));

    $updAsgn = $pdo->prepare("
        UPDATE jangad_assignments SET 
            returned_pcs = returned_pcs + 1,
            returned_weight = ?,
            pending_weight = ?,
            status = 'partially_returned'
        WHERE id = ?
    ");
    $updAsgn->execute([$newReturnedWeight, $newPendingWeight, $assignmentId]);

    // 6. Update parent diamond status in packets
    $updParent = $pdo->prepare("
        UPDATE packets SET 
            polish_weight = polish_weight + ?,
            current_weight = GREATEST(0, current_weight - ?),
            diamond_status = 'partially_returned'
        WHERE id = ?
    ");
    $updParent->execute([$receivedWeight, $receivedWeight, $asgn['original_diamond_id']]);

    // 7. Update jangad_kapans
    $updJk = $pdo->prepare("
        UPDATE jangad_kapans SET 
            returned_pcs = returned_pcs + 1,
            returned_weight = returned_weight + ?,
            pending_weight = GREATEST(0, pending_weight - ?)
        WHERE id = ?
    ");
    $updJk->execute([$receivedWeight, $receivedWeight, $asgn['jangad_kapan_id']]);

    // 8. Update jangad_vouchers
    $updJv = $pdo->prepare("
        UPDATE jangad_vouchers SET 
            returned_pcs = returned_pcs + 1,
            returned_weight = returned_weight + ?,
            pending_weight = GREATEST(0, pending_weight - ?),
            status = 'partially_returned',
            actual_return_date = ?
        WHERE id = ?
    ");
    $updJv->execute([$receivedWeight, $receivedWeight, $receivedDate, $jangadId]);

    return [
        'return_id'      => $returnId,
        'sub_diamond_id' => $subDiamondId,
        'sub_number'     => $subNumber,
        'received_weight'=> $receivedWeight,
        'pending_weight' => $newPendingWeight
    ];
}

/**
 * Finalize Jangad Diamond Assignment and calculate Tops.
 * Calculates exact remaining Tops (Assigned Wt - Returned Wt), inserts into `tops_records`,
 * marks assignment as 'completed', sets parent diamond status to 'split' (or 'completed'),
 * and unlocks diamond from active Jangad.
 */
function finalize_diamond_assignment_tops(int $assignmentId, int $jangadId, ?int $userId = null, string $diffClassification = 'loss'): array {
    $pdo = getDbConnection();

    $asgnStmt = $pdo->prepare("SELECT ja.*, jv.jangad_no FROM jangad_assignments ja JOIN jangad_vouchers jv ON ja.jangad_id = jv.id WHERE ja.id = ? AND ja.jangad_id = ?");
    $asgnStmt->execute([$assignmentId, $jangadId]);
    $asgn = $asgnStmt->fetch(PDO::FETCH_ASSOC);

    if (!$asgn) {
        throw new \InvalidArgumentException('અમાન્ય ડાયમંડ ફાળવણી (Assignment not found).');
    }
    if ($asgn['status'] === 'completed') {
        throw new \InvalidArgumentException("Assign #{$asgn['assign_number']} પહેલેથી જ પૂર્ણ થયેલ છે.");
    }

    $assignedWt = (float)$asgn['assigned_weight'];
    $returnedWt = (float)$asgn['returned_weight'];
    $calculatedDiff = max(0.0, round($assignedWt - $returnedWt, 3));
    $returnedPcs = (int)$asgn['returned_pcs'];

    $lossWt  = ($diffClassification === 'loss') ? $calculatedDiff : 0.0;
    $topsWt  = ($diffClassification === 'tops') ? $calculatedDiff : 0.0;
    $otherWt = ($diffClassification === 'other') ? $calculatedDiff : 0.0;

    // 1. Fetch parent diamond
    $parentStmt = $pdo->prepare("SELECT * FROM packets WHERE id = ?");
    $parentStmt->execute([$asgn['original_diamond_id']]);
    $parentDiamond = $parentStmt->fetch(PDO::FETCH_ASSOC);
    $parentDiamondNo = !empty($parentDiamond['diamond_no']) ? $parentDiamond['diamond_no'] : (!empty($asgn['assign_number']) ? $asgn['assign_number'] : (string)$asgn['original_diamond_id']);

    // 2. Mark assignment as completed with finalized difference
    $updAsgn = $pdo->prepare("
        UPDATE jangad_assignments SET 
            tops_weight = ?,
            loss_weight = ?,
            other_diff_weight = ?,
            diff_classification = ?,
            pending_weight = 0.000,
            status = 'completed',
            finalized_at = NOW()
        WHERE id = ?
    ");
    $updAsgn->execute([$topsWt, $lossWt, $otherWt, $diffClassification, $assignmentId]);

    // 3. If Tops > 0, record in tops_records table with full diamond traceability
    $topsRecordId = null;
    if ($topsWt > 0) {
        $insTops = $pdo->prepare("
            INSERT INTO tops_records (
                kapan_id, packet_id, diamond_no, assort_number, rough_number,
                source_stage, pcs, weight, status, record_date, notes
            ) VALUES (?, ?, ?, ?, ?, 'jangad_return', 1, ?, 'in_stock', CURDATE(), ?)
        ");
        $topsNotes = "Tops from Jangad {$asgn['jangad_no']} - Diamond {$parentDiamondNo} ({$topsWt} Ct)";
        $insTops->execute([
            $asgn['kapan_id'],
            $asgn['original_diamond_id'],
            $parentDiamondNo,
            $parentDiamond['assort_number'] ?? null,
            $parentDiamond['rough_number'] ?? null,
            $topsWt,
            $topsNotes
        ]);
        $topsRecordId = (int)$pdo->lastInsertId();

        // Update packet tops weight
        $pdo->prepare("UPDATE packets SET tops_weight = tops_weight + ? WHERE id = ?")
            ->execute([$topsWt, $asgn['original_diamond_id']]);
    }

    // 4. Update parent diamond status and current weight:
    // If returnedPcs == 0 and single diamond return was completed with received weight:
    if ($returnedPcs === 0 && $returnedWt > 0) {
        $pdo->prepare("UPDATE packets SET current_weight = ?, weight = ?, current_location = 'Surat Stock', diamond_status = 'available', status = 'in_stock', jangad_id = NULL WHERE id = ?")
            ->execute([$returnedWt, $returnedWt, $asgn['original_diamond_id']]);
        $newParentStatus = 'available';
    } else {
        $newParentStatus = ($returnedPcs > 0) ? 'split' : ($topsWt > 0 ? 'tops' : 'completed');
        $pdo->prepare("
            UPDATE packets SET 
                diamond_status = ?,
                current_location = 'Surat Stock',
                jangad_id = NULL
            WHERE id = ?
        ")->execute([$newParentStatus, $asgn['original_diamond_id']]);
    }

    // 5. Update stage history
    $stageCode = !empty($asgn['stage_code']) ? $asgn['stage_code'] : 'processing';
    record_diamond_stage_return(
        (int)$asgn['original_diamond_id'],
        $stageCode,
        $returnedWt,
        $diffClassification,
        $lossWt,
        $topsWt,
        $otherWt,
        'Surat Stock',
        "Finalized from Jangad {$asgn['jangad_no']}"
    );

    // 5. Update jangad_kapans tops & pending
    $updJk = $pdo->prepare("
        UPDATE jangad_kapans SET 
            tops_weight = tops_weight + ?,
            pending_weight = GREATEST(0, pending_weight - ?)
        WHERE id = ?
    ");
    $updJk->execute([$calculatedTops, $calculatedTops, $asgn['jangad_kapan_id']]);

    // 6. Update jangad_vouchers tops & pending
    $updJv = $pdo->prepare("
        UPDATE jangad_vouchers SET 
            total_tops_weight = total_tops_weight + ?,
            pending_weight = GREATEST(0, pending_weight - ?)
        WHERE id = ?
    ");
    $updJv->execute([$calculatedTops, $calculatedTops, $jangadId]);

    // 7. Check if all assignments in this Jangad are now completed
    $unfinStmt = $pdo->prepare("SELECT COUNT(*) FROM jangad_assignments WHERE jangad_id = ? AND status != 'completed'");
    $unfinStmt->execute([$jangadId]);
    $unfinCount = (int)$unfinStmt->fetchColumn();
    if ($unfinCount === 0) {
        $pdo->prepare("UPDATE jangad_vouchers SET status = 'completed' WHERE id = ?")->execute([$jangadId]);
        $pdo->prepare("UPDATE jangad_kapans SET status = 'completed' WHERE jangad_id = ?")->execute([$jangadId]);
    }

    return [
        'assignment_id' => $assignmentId,
        'tops_weight'   => $calculatedTops,
        'parent_status' => $newParentStatus,
        'tops_record_id'=> $topsRecordId
    ];
}

/**
 * =========================================================================
 * DIAMOND PROCESSING – DYNAMIC STAGES & STAGE-TO-STAGE WEIGHT TRANSFER
 * =========================================================================
 */

/**
 * Ensure packets.current_stage uses flexible VARCHAR(50) instead of a fixed ENUM,
 * and repair any rows truncated to '' when moving to dynamic stages like 'laser'.
 */
function ensure_packets_dynamic_stage_column(): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo = getDbConnection();
    try {
        $col = $pdo->query("SHOW COLUMNS FROM packets LIKE 'current_stage'")->fetch(PDO::FETCH_ASSOC);
        if ($col && stripos((string)($col['Type'] ?? ''), 'enum') !== false) {
            $pdo->exec("ALTER TABLE packets MODIFY COLUMN current_stage VARCHAR(50) NOT NULL DEFAULT 'galaxy'");
        }

        // Recover any packets whose stage became empty due to old ENUM truncation
        $emptyCnt = (int)$pdo->query("SELECT COUNT(*) FROM packets WHERE current_stage = '' OR current_stage IS NULL")->fetchColumn();
        if ($emptyCnt > 0) {
            $pdo->exec("
                UPDATE packets p
                SET p.current_stage = COALESCE(
                    NULLIF((SELECT pph.stage FROM packet_process_history pph WHERE pph.packet_id = p.id AND pph.stage IS NOT NULL AND pph.stage != '' ORDER BY pph.id DESC LIMIT 1), ''),
                    NULLIF((SELECT dsh.stage_code FROM diamond_stage_history dsh WHERE dsh.packet_id = p.id AND dsh.stage_code IS NOT NULL AND dsh.stage_code != '' ORDER BY dsh.id DESC LIMIT 1), ''),
                    'galaxy'
                )
                WHERE p.current_stage = '' OR p.current_stage IS NULL
            ");
        }

        // Auto-ensure stage history and jangad assignments columns
        $pdo->exec("
            ALTER TABLE diamond_stage_history 
            ADD COLUMN IF NOT EXISTS loss_weight DECIMAL(10,3) DEFAULT 0.000,
            ADD COLUMN IF NOT EXISTS tops_weight DECIMAL(10,3) DEFAULT 0.000,
            ADD COLUMN IF NOT EXISTS other_weight DECIMAL(10,3) DEFAULT 0.000,
            ADD COLUMN IF NOT EXISTS logged_by INT(11) DEFAULT NULL;
        ");
        $pdo->exec("
            ALTER TABLE jangad_assignments 
            ADD COLUMN IF NOT EXISTS loss_weight DECIMAL(10,3) DEFAULT 0.000,
            ADD COLUMN IF NOT EXISTS other_diff_weight DECIMAL(10,3) DEFAULT 0.000,
            ADD COLUMN IF NOT EXISTS diff_classification VARCHAR(50) DEFAULT 'none';
        ");
    } catch (Exception $e) {
        // Non-fatal fallback
    }
}

/**
 * Fetch all active processing stages ordered by sort_order
 */
function get_active_processing_stages(): array {
    $pdo = getDbConnection();
    try {
        $stmt = $pdo->query("SELECT * FROM processing_stages WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Fallback default stages if table not yet initialized
        return [
            ['stage_code' => 'galaxy', 'stage_name' => 'Galaxy', 'sort_order' => 1],
            ['stage_code' => 'planner', 'stage_name' => 'Planner', 'sort_order' => 2],
            ['stage_code' => 'sarin', 'stage_name' => 'Sarin', 'sort_order' => 3],
            ['stage_code' => 'laser_sawing', 'stage_name' => 'Laser Sawing', 'sort_order' => 4],
            ['stage_code' => 'manual_sawing', 'stage_name' => 'Manual Sawing', 'sort_order' => 5],
            ['stage_code' => 'bruting', 'stage_name' => 'Bruting', 'sort_order' => 6],
            ['stage_code' => 'blocking', 'stage_name' => 'Blocking', 'sort_order' => 7],
            ['stage_code' => 'faceting', 'stage_name' => 'Faceting', 'sort_order' => 8],
            ['stage_code' => 'polishing', 'stage_name' => 'Polishing', 'sort_order' => 9],
            ['stage_code' => 'final_checking', 'stage_name' => 'Final Checking', 'sort_order' => 10],
            ['stage_code' => 'weekly_boil', 'stage_name' => 'Weekly Boil', 'sort_order' => 11],
            ['stage_code' => 'polish_stock', 'stage_name' => 'Polish Stock', 'sort_order' => 12]
        ];
    }
}

/**
 * Fetch all processing stages for Settings CRUD
 */
function get_all_processing_stages(): array {
    $pdo = getDbConnection();
    try {
        $stmt = $pdo->query("SELECT * FROM processing_stages ORDER BY sort_order ASC, id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Fetch all active destinations (Surat, Mumbai, Navsari, etc.)
 */
function get_active_destinations(): array {
    $pdo = getDbConnection();
    try {
        return $pdo->query("SELECT * FROM master_destinations WHERE status = 'active' ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [
            ['id' => 1, 'name' => 'Surat', 'code' => 'SURAT'],
            ['id' => 2, 'name' => 'Mumbai', 'code' => 'MUMBAI'],
            ['id' => 3, 'name' => 'Navsari', 'code' => 'NAVSARI']
        ];
    }
}

function get_all_destinations(): array {
    $pdo = getDbConnection();
    try {
        return $pdo->query("SELECT * FROM master_destinations ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Fetch all active safes / vaults (OFC, M1, M2, etc.)
 */
function get_active_safes(): array {
    $pdo = getDbConnection();
    try {
        return $pdo->query("SELECT * FROM master_safes WHERE status = 'active' ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [
            ['id' => 1, 'name' => 'OFC', 'code' => 'OFC'],
            ['id' => 2, 'name' => 'M1', 'code' => 'M1'],
            ['id' => 3, 'name' => 'M2', 'code' => 'M2']
        ];
    }
}

function get_all_safes(): array {
    $pdo = getDbConnection();
    try {
        return $pdo->query("SELECT s.*, d.name as destination_name FROM master_safes s LEFT JOIN master_destinations d ON s.destination_id = d.id ORDER BY s.sort_order ASC, s.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Fetch all active dabbas / boxes (1, 2, 3, etc.)
 */
function get_active_dabbas(): array {
    $pdo = getDbConnection();
    try {
        return $pdo->query("SELECT * FROM master_dabbas WHERE status = 'active' ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [
            ['id' => 1, 'name' => '1', 'code' => 'D1'],
            ['id' => 2, 'name' => '2', 'code' => 'D2'],
            ['id' => 3, 'name' => '3', 'code' => 'D3']
        ];
    }
}

function get_all_dabbas(): array {
    $pdo = getDbConnection();
    try {
        return $pdo->query("SELECT b.*, s.name as safe_name FROM master_dabbas b LEFT JOIN master_safes s ON b.safe_id = s.id ORDER BY b.sort_order ASC, b.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Fetch all active tags (Rough, Tops, Rejection, etc.)
 */
function get_active_tags(): array {
    $pdo = getDbConnection();
    try {
        return $pdo->query("SELECT * FROM master_tags WHERE status = 'active' ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [
            ['id' => 1, 'name' => 'Rough', 'code' => 'ROUGH', 'color' => 'blue'],
            ['id' => 2, 'name' => 'Tops', 'code' => 'TOPS', 'color' => 'purple'],
            ['id' => 3, 'name' => 'Rejection', 'code' => 'REJECTION', 'color' => 'rose'],
        ];
    }
}

/**
 * Fetch all tags for settings management
 */
function get_all_tags(): array {
    $pdo = getDbConnection();
    try {
        return $pdo->query("SELECT * FROM master_tags ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Fetch all active process tags (Sell, Hold, Assort, etc.)
 */
function get_active_process_tags(): array {
    $pdo = getDbConnection();
    try {
        return $pdo->query("SELECT * FROM master_process_tags WHERE status = 'active' ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [
            ['id' => 1, 'name' => 'Sell', 'code' => 'SELL', 'color' => 'emerald'],
            ['id' => 2, 'name' => 'Hold', 'code' => 'HOLD', 'color' => 'amber'],
            ['id' => 3, 'name' => 'Assort', 'code' => 'ASSORT', 'color' => 'blue'],
        ];
    }
}

/**
 * Fetch all process tags for settings management
 */
function get_all_process_tags(): array {
    $pdo = getDbConnection();
    try {
        return $pdo->query("SELECT * FROM master_process_tags ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Render stylish badge for Tag (Rough, Tops, Rejection)
 */
function render_tag_badge(?string $tag): string {
    $tag = trim((string)$tag);
    if ($tag === '') return '<span class="text-muted">—</span>';
    
    $lower = strtolower($tag);
    $pillClass = match($lower) {
        'rough'     => 'finora-pill finora-pill-blue',
        'tops'      => 'finora-pill finora-pill-purple',
        'rejection' => 'finora-pill finora-pill-rose',
        default     => 'finora-pill finora-pill-slate'
    };
    
    $iconName = match($lower) {
        'rough'     => 'gem',
        'tops'      => 'sparkles',
        'rejection' => 'circle-xmark',
        default     => 'tag'
    };

    return '<span class="' . $pillClass . '">' . svg_icon($iconName, 'me-1', 12) . e($tag) . '</span>';
}

/**
 * Render stylish badge for Process Tag (Sell, Hold, Assort)
 */
function render_process_tag_badge(?string $processTag): string {
    $pt = trim((string)$processTag);
    if ($pt === '') return '<span class="text-muted">—</span>';
    
    $lower = strtolower($pt);
    $pillClass = match($lower) {
        'sell'   => 'finora-pill finora-pill-green',
        'hold'   => 'finora-pill finora-pill-amber',
        'assort' => 'finora-pill finora-pill-cyan',
        default  => 'finora-pill finora-pill-slate'
    };
    
    $iconName = match($lower) {
        'sell'   => 'cart-shopping',
        'hold'   => 'hand',
        'assort' => 'boxes-stacked',
        default  => 'gear'
    };

    return '<span class="' . $pillClass . '">' . svg_icon($iconName, 'me-1', 12) . e($pt) . '</span>';
}

/**
 * Check if a purchase or sale record is a "Stand Dollar" deal
 * (Entered in USD or dollar_per_carat > 0, but dollar rate is pending / not entered)
 */
function is_stand_dollar(array $row): bool {
    $curr = strtoupper(trim((string)($row['payment_currency'] ?? '')));
    $dollarCt = (float)($row['dollar_per_carat'] ?? 0);
    $dollarRate = (float)($row['dollar_rate'] ?? 0);

    return ($curr === 'USD' || $dollarCt > 0) && $dollarRate <= 0;
}

/**
 * Render glowing Red Dot indicator for Stand Dollar records
 */
function render_stand_dollar_indicator(array $row): string {
    if (!is_stand_dollar($row)) {
        return '';
    }
    return '<span class="stand-dollar-dot" title="Stand Dollar - ડોલર દર બાકી છે (Rate Pending)" data-bs-toggle="tooltip"></span>';
}


/**
 * Get single processing stage by stage code
 */
function get_processing_stage_by_code(string $code): ?array {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT * FROM processing_stages WHERE stage_code = ? LIMIT 1");
    $stmt->execute([$code]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    return $res ?: null;
}

/**
 * Get Diamond's actual current available weight (Received from previous stage)
 * Formula: Next Stage Assign Weight = Previous Stage Actual Received Weight
 */
function get_diamond_current_available_weight(int $packetId): float {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT current_weight, weight, original_weight FROM packets WHERE id = ?");
    $stmt->execute([$packetId]);
    $pkt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pkt) return 0.0;

    if ((float)$pkt['current_weight'] > 0) {
        return (float)$pkt['current_weight'];
    }
    if ((float)$pkt['weight'] > 0) {
        return (float)$pkt['weight'];
    }
    return (float)$pkt['original_weight'];
}

/**
 * Record outgoing diamond stage assignment (Audit logging and weight transfer)
 */
function record_diamond_stage_assignment(
    int $packetId,
    string $stageCode,
    string $stageName,
    int $partyId = 0,
    string $partyName = '',
    int $jangadId = 0,
    string $jangadNo = '',
    int|float $assignmentId = 0,
    float|int $givenWeight = 0.0,
    string $outgoingLoc = '',
    string $notes = ''
): int {
    $pdo = getDbConnection();
    $assignmentId = (int)$assignmentId;
    $givenWeight = (float)$givenWeight;

    // Fetch diamond details
    $dStmt = $pdo->prepare("SELECT diamond_no, packet_no, weight, current_weight, current_location FROM packets WHERE id = ?");
    $dStmt->execute([$packetId]);
    $diamond = $dStmt->fetch(PDO::FETCH_ASSOC);
    if (!$diamond) return 0;

    $dNo = !empty($diamond['diamond_no']) ? $diamond['diamond_no'] : $diamond['packet_no'];

    // If given weight not specified, use actual current weight from previous stage
    if ($givenWeight <= 0) {
        $givenWeight = ((float)$diamond['current_weight'] > 0) ? (float)$diamond['current_weight'] : (float)$diamond['weight'];
    }

    $destLoc = !empty($outgoingLoc) ? $outgoingLoc : ($partyName ? "Party: {$partyName}" : "Process: {$stageName}");

    // Update packets current location and stage (preserve finished polish status)
    $pktTargetStatus = ($stageCode === 'polish_stock') ? 'in_polish_stock' : 'in_process';
    $updPkt = $pdo->prepare("
        UPDATE packets SET 
            current_stage = ?,
            current_location = ?,
            status = ?
        WHERE id = ?
    ");
    $updPkt->execute([$stageCode, $destLoc, $pktTargetStatus, $packetId]);

    // Insert into diamond_stage_history
    $insHist = $pdo->prepare("
        INSERT INTO diamond_stage_history (
            packet_id, diamond_no, stage_code, stage_name, party_id, party_name,
            jangad_id, jangad_no, assignment_id, given_weight, received_weight,
            difference_weight, pending_weight, diff_classification,
            assigned_date, outgoing_location, receiving_location, status, notes, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, 0.000,
            0.000, ?, 'pending',
            CURDATE(), ?, '', 'in_process', ?, NOW()
        )
    ");
    $insHist->execute([
        $packetId, $dNo, $stageCode, $stageName, $partyId, $partyName,
        $jangadId, $jangadNo, $assignmentId, $givenWeight,
        $givenWeight, $destLoc, $notes
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Record diamond return from a processing stage with Difference Classification
 * Formula: Next Stage Assign Weight = Previous Stage Actual Received Weight
 * Difference Classification: 'loss' (ઘટ), 'tops' (રદિયો), 'other' (અન્ય), 'pending' (બાકી)
 */
function record_diamond_stage_return(
    int $packetId,
    string $stageCode,
    float $receivedWeight,
    string $diffClassification = 'loss',
    float $lossWeight = 0.0,
    float $topsWeight = 0.0,
    float $otherWeight = 0.0,
    string $receivingLoc = 'Surat Stock',
    string $notes = ''
): bool {
    $pdo = getDbConnection();

    // Fetch the open stage history entry for this diamond
    $hStmt = $pdo->prepare("
        SELECT id, given_weight, stage_name, diamond_no
        FROM diamond_stage_history
        WHERE packet_id = ? AND stage_code = ? AND status = 'in_process'
        ORDER BY id DESC LIMIT 1
    ");
    $hStmt->execute([$packetId, $stageCode]);
    $historyRow = $hStmt->fetch(PDO::FETCH_ASSOC);

    $givenWeight = $historyRow ? (float)$historyRow['given_weight'] : get_diamond_current_available_weight($packetId);
    $diffWeight = max(0.0, round($givenWeight - $receivedWeight, 3));

    // Reconcile difference weights based on classification
    if ($diffClassification === 'loss' && $lossWeight <= 0) {
        $lossWeight = $diffWeight;
    } elseif ($diffClassification === 'tops' && $topsWeight <= 0) {
        $topsWeight = $diffWeight;
    } elseif ($diffClassification === 'other' && $otherWeight <= 0) {
        $otherWeight = $diffWeight;
    }

    $pendingWeight = ($diffClassification === 'pending') ? $diffWeight : 0.0;

    // 1. Update diamond master with the new received weight
    $updPkt = $pdo->prepare("
        UPDATE packets SET 
            current_weight = ?,
            weight = ?,
            current_location = ?,
            status = 'in_stock'
        WHERE id = ?
    ");
    $updPkt->execute([$receivedWeight, $receivedWeight, $receivingLoc, $packetId]);

    // 2. Update diamond_stage_history
    if ($historyRow) {
        $updHist = $pdo->prepare("
            UPDATE diamond_stage_history SET 
                received_weight = ?,
                difference_weight = ?,
                pending_weight = ?,
                diff_classification = ?,
                loss_weight = ?,
                tops_weight = ?,
                other_weight = ?,
                return_date = CURDATE(),
                receiving_location = ?,
                status = 'completed',
                notes = CONCAT(IFNULL(notes, ''), ' ', ?)
            WHERE id = ?
        ");
        $updHist->execute([
            $receivedWeight, $diffWeight, $pendingWeight, $diffClassification,
            $lossWeight, $topsWeight, $otherWeight,
            $receivingLoc, $notes, $historyRow['id']
        ]);
    } else {
        // Direct record if no previous assignment record
        $insHist = $pdo->prepare("
            INSERT INTO diamond_stage_history (
                packet_id, diamond_no, stage_code, stage_name, given_weight, received_weight,
                difference_weight, pending_weight, diff_classification, loss_weight, tops_weight, other_weight,
                assigned_date, return_date, outgoing_location, receiving_location, status, notes, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                CURDATE(), CURDATE(), 'Surat Stock', ?, 'completed', ?, NOW()
            )
        ");
        $dStmt = $pdo->prepare("SELECT diamond_no, packet_no FROM packets WHERE id = ?");
        $dStmt->execute([$packetId]);
        $dia = $dStmt->fetch(PDO::FETCH_ASSOC);
        $dNo = $dia ? (!empty($dia['diamond_no']) ? $dia['diamond_no'] : $dia['packet_no']) : "DIA-{$packetId}";

        $insHist->execute([
            $packetId, $dNo, $stageCode, ucfirst(str_replace('_', ' ', $stageCode)),
            $givenWeight, $receivedWeight, $diffWeight, $pendingWeight, $diffClassification,
            $lossWeight, $topsWeight, $otherWeight, $receivingLoc, $notes
        ]);
    }

    // 3. If Tops was generated and Tops > 0, log to tops_records
    if ($topsWeight > 0) {
        $pInfoStmt = $pdo->prepare("SELECT kapan_id FROM packets WHERE id = ?");
        $pInfoStmt->execute([$packetId]);
        $pInfo = $pInfoStmt->fetch(PDO::FETCH_ASSOC);
        $kapanId = $pInfo ? (int)$pInfo['kapan_id'] : 0;
        $pdo->prepare("
            INSERT INTO tops_records (
                kapan_id, packet_id, source_stage, pcs, weight,
                record_date, notes, created_at
            ) VALUES (
                ?, ?, ?, 1, ?,
                CURDATE(), CONCAT('Tops generated during ', ?), NOW()
            )
        ")->execute([
            $kapanId, $packetId, $stageCode, $topsWeight, $stageCode
        ]);
    }

    return true;
}

/**
 * Get Diamond Journey Audit Report
 * Shows complete stage progression: Stage | Given Wt | Received Wt | Difference | Classification | Party | Location | Date | Status
 */
function get_diamond_journey_report(int $packetId): array {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("
        SELECT *
        FROM diamond_stage_history
        WHERE packet_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$packetId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}



/**
 * Bridge a completed/polished diamond packet into `polish_stocks` so it can go on Sales Jangad or Direct Sale.
 * Rule: Diamonds only enter Jangad / Sales after Polishing is finished.
 */
function sync_packet_to_polish_stock(int $packetId, ?float $polishWeight = null, ?string $shape = null): int {
    $pdo = getDbConnection();

    $stmt = $pdo->prepare("
        SELECT p.*, k.total_cost, k.total_polish_weight, k.cost_per_carat
        FROM packets p
        LEFT JOIN kapans k ON p.kapan_id = k.id
        WHERE p.id = ?
    ");
    $stmt->execute([$packetId]);
    $pkt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pkt) {
        return 0;
    }

    // Do not sync packets that have exited via Boil Re-Assort
    if (
        ($pkt['status'] ?? '') === 'reassorted'
        || !empty($pkt['is_reassorted'])
        || ($pkt['current_stage'] ?? '') === 'reassort_exited'
    ) {
        return 0;
    }

    // Return existing linked stock if valid
    if (!empty($pkt['polish_stock_id'])) {
        $chk = $pdo->prepare("SELECT id FROM polish_stocks WHERE id = ?");
        $chk->execute([(int)$pkt['polish_stock_id']]);
        if ($chk->fetchColumn()) {
            return (int)$pkt['polish_stock_id'];
        }
    }

    $basePktNo = !empty($pkt['packet_no']) ? $pkt['packet_no'] : (!empty($pkt['diamond_no']) ? $pkt['diamond_no'] : (string)$pkt['id']);
    $stockPktNo = 'POL-' . $basePktNo;

    // Check by packet_no in case already bridged
    $chkNo = $pdo->prepare("SELECT id FROM polish_stocks WHERE packet_no = ? LIMIT 1");
    $chkNo->execute([$stockPktNo]);
    $existingStkId = (int)$chkNo->fetchColumn();

    if ($existingStkId <= 0) {
        $wt = ($polishWeight !== null && $polishWeight > 0)
            ? round($polishWeight, 3)
            : ((float)($pkt['polish_weight'] ?? 0) > 0
                ? (float)$pkt['polish_weight']
                : ((float)($pkt['current_weight'] ?? 0) > 0 ? (float)$pkt['current_weight'] : (float)$pkt['weight']));

        if ($wt <= 0) {
            $wt = (float)$pkt['weight'];
        }

        $costPerCt = (float)($pkt['cost_per_carat'] ?? 0);
        if ($costPerCt <= 0 && (float)($pkt['total_polish_weight'] ?? 0) > 0) {
            $costPerCt = round((float)$pkt['total_cost'] / (float)$pkt['total_polish_weight'], 2);
        }
        $saleRate = ($costPerCt > 0) ? round($costPerCt * 1.15, 2) : 0.00;
        $finalShape = !empty($shape) ? $shape : (!empty($pkt['expected_shape']) ? $pkt['expected_shape'] : 'Round');
        $loc = !empty($pkt['current_location']) ? $pkt['current_location'] : 'Surat';

        $ins = $pdo->prepare("
            INSERT INTO polish_stocks (
                packet_no, stock_type, source_type, kapan_id, shape, clarity, color, cut,
                total_pcs, total_weight, available_pcs, available_weight,
                cost_per_carat, sale_price_per_carat, current_location, status
            ) VALUES (
                ?, 'single', 'mfg', ?, ?, 'VS1', 'G', 'EX',
                1, ?, 1, ?,
                ?, ?, ?, 'available'
            )
        ");
        $ins->execute([
            $stockPktNo,
            !empty($pkt['kapan_id']) ? (int)$pkt['kapan_id'] : null,
            $finalShape,
            $wt,
            $wt,
            $costPerCt,
            $saleRate,
            $loc
        ]);
        $existingStkId = (int)$pdo->lastInsertId();
    }

    // Link packet back to polish_stocks & ensure stage/status reflect completed polish stock
    $pdo->prepare("
        UPDATE packets SET
            polish_stock_id = ?,
            current_stage = 'polish_stock',
            status = 'in_polish_stock'
        WHERE id = ?
    ")->execute([$existingStkId, $packetId]);

    return $existingStkId;
}

/**
 * Ensure all finished manufacturing packets are bridged into polish_stocks.
 */
function sync_all_completed_packets_to_polish_stock(): void {
    $pdo = getDbConnection();
    if (!$pdo->inTransaction()) {
        ensure_boil_reassort_columns();
    }
    $rows = $pdo->query("
        SELECT id
        FROM packets
        WHERE (current_stage = 'polish_stock' OR status IN ('completed', 'boil_ready', 'in_polish_stock'))
          AND status != 'reassorted'
          AND COALESCE(is_reassorted, 0) = 0
          AND (polish_stock_id IS NULL OR polish_stock_id = 0)
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($rows as $pktId) {
        sync_packet_to_polish_stock((int)$pktId);
    }
}

/**
 * Fetch all ready Polish Diamonds (Completed Mfg + Purchased) eligible for Sales Jangad.
 */
function get_ready_polish_stocks_for_jangad(array $includeStockIds = []): array {
    sync_all_completed_packets_to_polish_stock();
    $pdo = getDbConnection();

    $includeIds = array_values(array_unique(array_filter(array_map('intval', $includeStockIds), fn($v) => $v > 0)));
    $extraClause = '';
    if (!empty($includeIds)) {
        $extraClause = ' OR ps.id IN (' . implode(',', $includeIds) . ')';
    }

    $sql = "
        SELECT ps.*, k.kapan_code, k.kapan_name, pp.purchase_no,
               p.id as linked_packet_id, p.diamond_no, p.barcode
        FROM polish_stocks ps
        LEFT JOIN kapans k ON ps.kapan_id = k.id
        LEFT JOIN polish_purchases pp ON ps.polish_purchase_id = pp.id
        LEFT JOIN packets p ON p.polish_stock_id = ps.id
        WHERE (ps.available_pcs > 0 AND ps.available_weight > 0 AND ps.status IN ('available', 'partially_on_jangad'))
              {$extraClause}
        ORDER BY ps.id DESC
    ";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Ensure Sales Jangad split tracking columns exist cleanly (call outside transactions).
 * Tracks Sold qty/weight separately from Returned-to-Kapan-Stock qty/weight.
 */
function ensure_jangad_sales_columns(): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $pdo = getDbConnection();
    $checks = [
        ['jangad_vouchers', 'sold_pcs', 'INT NOT NULL DEFAULT 0 AFTER total_weight'],
        ['jangad_vouchers', 'sold_weight', 'DECIMAL(10,3) NOT NULL DEFAULT 0.000 AFTER sold_pcs'],
        ['jangad_items', 'kapan_id', 'INT DEFAULT NULL AFTER polish_stock_id'],
        ['jangad_items', 'sold_pcs', 'INT NOT NULL DEFAULT 0 AFTER issued_weight'],
        ['jangad_items', 'sold_weight', 'DECIMAL(10,3) NOT NULL DEFAULT 0.000 AFTER sold_pcs'],
        ['jangad_items', 'polish_sale_id', 'INT DEFAULT NULL AFTER sold_weight'],
        ['polish_sales', 'jangad_id', 'INT DEFAULT NULL AFTER polish_stock_id'],
        ['polish_sales', 'jangad_item_id', 'INT DEFAULT NULL AFTER jangad_id'],
    ];

    $colStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");

    foreach ($checks as [$tbl, $col, $def]) {
        try {
            $colStmt->execute([$tbl, $col]);
            if ((int)$colStmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE `{$tbl}` ADD COLUMN `{$col}` {$def}");
            }
        } catch (\Throwable $e) {
            // Ignore non-fatal schema check issues
        }
    }

    try {
        $pdo->exec("
            UPDATE jangad_items ji
            JOIN polish_stocks ps ON ji.polish_stock_id = ps.id
            SET ji.kapan_id = ps.kapan_id
            WHERE ji.kapan_id IS NULL AND ps.kapan_id IS NOT NULL
        ");
    } catch (\Throwable $e) {
        // Ignore if backfill not needed
    }
}

/**
 * Recalculate `polish_stocks.status` consistently from vault balance and open Sales Jangad items.
 */
function reconcile_polish_stock_status(int $stockId): void {
    if ($stockId <= 0) {
        return;
    }
    $pdo = getDbConnection();
    $stkStmt = $pdo->prepare("SELECT available_pcs, available_weight FROM polish_stocks WHERE id = ?");
    $stkStmt->execute([$stockId]);
    $stk = $stkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$stk) {
        return;
    }

    $openStmt = $pdo->prepare("
        SELECT COALESCE(SUM(GREATEST(0, issued_weight - COALESCE(sold_weight, 0) - COALESCE(returned_weight, 0))), 0)
        FROM jangad_items
        WHERE polish_stock_id = ? AND status != 'returned'
    ");
    $openStmt->execute([$stockId]);
    $openJangadWt = round((float)$openStmt->fetchColumn(), 3);

    $availWt = round((float)$stk['available_weight'], 3);

    if ($availWt <= 0.0005 && $openJangadWt <= 0.0005) {
        $status = 'sold';
    } elseif ($openJangadWt > 0.0005 && $availWt > 0.0005) {
        $status = 'partially_on_jangad';
    } elseif ($openJangadWt > 0.0005 && $availWt <= 0.0005) {
        $status = 'on_jangad';
    } else {
        $status = 'available';
    }

    $pdo->prepare("UPDATE polish_stocks SET status = ? WHERE id = ?")->execute([$status, $stockId]);
}

/**
 * Return unsold diamonds from a Sales Jangad item straight back to its respective Kapan & Polish Stock.
 * Expects caller to manage transaction if combined with a multi-item batch settlement.
 */
function apply_jangad_item_return_to_kapan(int $itemId, int $jangadId, int $retPcs, float $retWt, string $notes = ''): array {
    $pdo = getDbConnection();

    $itStmt = $pdo->prepare("
        SELECT ji.*, ps.kapan_id as stk_kapan_id, ps.packet_no as stk_packet_no, k.kapan_code, k.kapan_name
        FROM jangad_items ji
        LEFT JOIN polish_stocks ps ON ji.polish_stock_id = ps.id
        LEFT JOIN kapans k ON COALESCE(ji.kapan_id, ps.kapan_id) = k.id
        WHERE ji.id = ? AND ji.jangad_id = ?
        FOR UPDATE
    ");
    $itStmt->execute([$itemId, $jangadId]);
    $item = $itStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        throw new \RuntimeException('જાંગડ આઇટમ મળ્યું નથી.');
    }

    $soldPcs = (int)($item['sold_pcs'] ?? 0);
    $soldWt  = round((float)($item['sold_weight'] ?? 0), 3);
    $pendPcs = max(0, (int)$item['issued_pcs'] - $soldPcs - (int)$item['returned_pcs']);
    $pendWt  = max(0.0, round((float)$item['issued_weight'] - $soldWt - (float)$item['returned_weight'], 3));

    if ($retPcs <= 0 || $retWt <= 0) {
        throw new \RuntimeException('રિટર્ન નંગ અને વજન શૂન્ય કરતા વધુ હોવું જોઈએ.');
    }
    if ($retPcs > $pendPcs || $retWt > $pendWt + 0.001) {
        throw new \RuntimeException("રિટર્ન નંગ/વજન બાકી જથ્થા કરતા વધુ છે (બાકી: {$pendPcs} Pcs / {$pendWt} Ct).");
    }
    if ($retWt > $pendWt) {
        $retWt = $pendWt;
    }

    $newRetPcs = (int)$item['returned_pcs'] + $retPcs;
    $newRetWt  = round((float)$item['returned_weight'] + $retWt, 3);
    $remItemPcs = max(0, (int)$item['issued_pcs'] - $soldPcs - $newRetPcs);
    $remItemWt  = max(0.0, round((float)$item['issued_weight'] - $soldWt - $newRetWt, 3));
    $itemStatus = ($remItemWt <= 0.0005 || $remItemPcs <= 0) ? 'returned' : 'partially_returned';

    $pdo->prepare("
        UPDATE jangad_items SET
            returned_pcs = ?,
            returned_weight = ?,
            status = ?
        WHERE id = ?
    ")->execute([$newRetPcs, $newRetWt, $itemStatus, $itemId]);

    $targetKapanId = !empty($item['kapan_id']) ? (int)$item['kapan_id'] : (!empty($item['stk_kapan_id']) ? (int)$item['stk_kapan_id'] : null);
    $kapanLabel = !empty($item['kapan_code']) ? "{$item['kapan_code']}" : 'Polish Stock';

    // Restore returned quantity back into the Kapan's Polish Stock
    if (!empty($item['polish_stock_id'])) {
        $stkId = (int)$item['polish_stock_id'];
        $stkStmt = $pdo->prepare("SELECT available_pcs, available_weight, kapan_id FROM polish_stocks WHERE id = ? FOR UPDATE");
        $stkStmt->execute([$stkId]);
        $stk = $stkStmt->fetch(PDO::FETCH_ASSOC);

        if ($stk) {
            $newAvailPcs = (int)$stk['available_pcs'] + $retPcs;
            $newAvailWt  = round((float)$stk['available_weight'] + $retWt, 3);
            $resolvedKapanId = !empty($stk['kapan_id']) ? (int)$stk['kapan_id'] : $targetKapanId;

            $pdo->prepare("
                UPDATE polish_stocks SET
                    available_pcs = ?,
                    available_weight = ?,
                    current_location = 'Surat',
                    kapan_id = COALESCE(kapan_id, ?)
                WHERE id = ?
            ")->execute([$newAvailPcs, $newAvailWt, $resolvedKapanId, $stkId]);

            reconcile_polish_stock_status($stkId);
        }
    }

    // Restore individual packet record back inside its Kapan vault stock if linked
    if (!empty($item['packet_id']) && $itemStatus === 'returned') {
        $pktId = (int)$item['packet_id'];
        $pdo->prepare("
            UPDATE packets SET
                jangad_id = NULL,
                assigned_party_id = NULL,
                current_stage = 'polish_stock',
                current_location = 'Surat Stock',
                status = 'in_polish_stock',
                diamond_status = 'available'
            WHERE id = ?
        ")->execute([$pktId]);

        $pdo->prepare("
            UPDATE diamond_stage_history SET
                received_weight = ?,
                pending_weight = 0.000,
                return_date = CURDATE(),
                receiving_location = ?,
                status = 'completed',
                notes = CONCAT(IFNULL(notes, ''), ?)
            WHERE packet_id = ? AND jangad_id = ? AND stage_code = 'sales_jangad' AND status = 'in_process'
        ")->execute([
            $retWt,
            "Surat Stock ({$kapanLabel})",
            $notes ? " | પાછું કાપન ({$kapanLabel}) સ્ટોકમાં જમા: {$notes}" : " | પાછું કાપન ({$kapanLabel}) સ્ટોકમાં જમા",
            $pktId,
            $jangadId
        ]);
    }

    return [
        'item_id'       => $itemId,
        'kapan_id'      => $targetKapanId,
        'kapan_code'    => $item['kapan_code'] ?? '',
        'kapan_name'    => $item['kapan_name'] ?? '',
        'returned_pcs'  => $retPcs,
        'returned_wt'   => $retWt,
        'stock_id'      => (int)($item['polish_stock_id'] ?? 0)
    ];
}

/**
 * Recalculate `jangad_vouchers` totals & status from `jangad_items` (Sales / Memo Jangads).
 * Clearly aggregates Sold vs Returned-to-Kapan-Stock separately.
 */
function recalculate_jangad_voucher_totals(int $jangadId): void {
    $pdo = getDbConnection();
    $aggStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(issued_pcs), 0) as tot_pcs,
            COALESCE(SUM(issued_weight), 0) as tot_wt,
            COALESCE(SUM(COALESCE(sold_pcs, 0)), 0) as sold_pcs,
            COALESCE(SUM(COALESCE(sold_weight, 0)), 0) as sold_wt,
            COALESCE(SUM(returned_pcs), 0) as ret_pcs,
            COALESCE(SUM(returned_weight), 0) as ret_wt,
            SUM(CASE WHEN status != 'returned' AND (issued_weight - COALESCE(sold_weight, 0) - returned_weight) > 0.0005 THEN 1 ELSE 0 END) as pending_items
        FROM jangad_items
        WHERE jangad_id = ?
    ");
    $aggStmt->execute([$jangadId]);
    $agg = $aggStmt->fetch(PDO::FETCH_ASSOC);
    if (!$agg) {
        return;
    }

    $soldPcs = (int)$agg['sold_pcs'];
    $soldWt  = round((float)$agg['sold_wt'], 3);
    $retPcs  = (int)$agg['ret_pcs'];
    $retWt   = round((float)$agg['ret_wt'], 3);
    $totWt   = round((float)$agg['tot_wt'], 3);
    $pendWt  = max(0.0, round($totWt - $soldWt - $retWt, 3));
    $pendingItems = (int)($agg['pending_items'] ?? 0);

    if ($pendingItems === 0 || $pendWt <= 0.0005) {
        $status = 'completed';
        $actualRetDate = date('Y-m-d');
    } elseif ($soldWt > 0 || $retWt > 0 || $soldPcs > 0 || $retPcs > 0) {
        $status = 'partially_returned';
        $actualRetDate = null;
    } else {
        $status = 'issued';
        $actualRetDate = null;
    }

    $upd = $pdo->prepare("
        UPDATE jangad_vouchers SET
            sold_pcs = ?,
            sold_weight = ?,
            returned_pcs = ?,
            returned_weight = ?,
            pending_weight = ?,
            status = ?,
            actual_return_date = COALESCE(?, actual_return_date)
        WHERE id = ?
    ");
    $upd->execute([$soldPcs, $soldWt, $retPcs, $retWt, $pendWt, $status, $actualRetDate, $jangadId]);
}

/**
 * Ensure schema extensions for Boil Office Return -> Re-Assort (New Lot No, Old Kapan exit, Full History)
 * Must be called outside any open transaction.
 */
function ensure_boil_reassort_columns(): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $pdo = getDbConnection();
    if ($pdo->inTransaction()) {
        return;
    }
    $checked = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS boil_reassort_lots (
                id INT AUTO_INCREMENT PRIMARY KEY,
                lot_no VARCHAR(50) NOT NULL UNIQUE,
                boil_batch_id INT NOT NULL,
                source_kapan_ids VARCHAR(255) DEFAULT NULL,
                source_kapan_codes TEXT DEFAULT NULL,
                total_pcs INT NOT NULL DEFAULT 0,
                total_weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
                return_date DATE NOT NULL,
                assort_task_id INT DEFAULT NULL,
                status ENUM('pending_assort', 'assorted') NOT NULL DEFAULT 'pending_assort',
                notes TEXT DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                assorted_at DATETIME DEFAULT NULL,
                KEY idx_brl_status (status),
                KEY idx_brl_boil (boil_batch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS boil_reassort_lot_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reassort_lot_id INT NOT NULL,
                boil_batch_id INT NOT NULL,
                packet_id INT NOT NULL,
                old_kapan_id INT NOT NULL,
                old_kapan_code VARCHAR(50) DEFAULT NULL,
                old_diamond_no VARCHAR(100) DEFAULT NULL,
                old_polish_stock_id INT DEFAULT NULL,
                pcs INT NOT NULL DEFAULT 1,
                weight DECIMAL(10,3) NOT NULL DEFAULT 0.000,
                cost_per_carat DECIMAL(14,2) NOT NULL DEFAULT 0.00,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_brli_lot (reassort_lot_id),
                KEY idx_brli_pkt (packet_id),
                KEY idx_brli_kapan (old_kapan_id),
                KEY idx_brli_boil (boil_batch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (\Throwable $e) {
        // Ignore if table already exists or permissions differ
    }

    // Ensure packets.status & polish_stocks.status support 'reassorted'
    $enumChecks = [
        ['packets', 'status', 'reassorted', "ALTER TABLE packets MODIFY COLUMN status ENUM('in_process','boil_ready','in_polish_stock','sold','reassorted') DEFAULT 'in_process'"],
        ['polish_stocks', 'status', 'reassorted', "ALTER TABLE polish_stocks MODIFY COLUMN status ENUM('available','on_jangad','partially_on_jangad','sold','reassorted') DEFAULT 'available'"],
    ];
    $colTypeStmt = $pdo->prepare("SELECT COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    foreach ($enumChecks as [$tbl, $col, $val, $alterSql]) {
        try {
            $colTypeStmt->execute([$tbl, $col]);
            $meta = $colTypeStmt->fetch(PDO::FETCH_ASSOC);
            if ($meta && stripos((string)$meta['COLUMN_TYPE'], $val) === false) {
                $pdo->exec($alterSql);
            }
        } catch (\Throwable $e) {
            // Safe fallback
        }
    }

    // Allow assort_tasks.rough_purchase_id to be NULL for Boil Re-Assort tasks
    try {
        $colTypeStmt->execute(['assort_tasks', 'rough_purchase_id']);
        $meta = $colTypeStmt->fetch(PDO::FETCH_ASSOC);
        if ($meta && strtoupper((string)$meta['IS_NULLABLE']) === 'NO') {
            $pdo->exec("ALTER TABLE assort_tasks MODIFY COLUMN rough_purchase_id INT NULL DEFAULT NULL");
        }
    } catch (\Throwable $e) {
        // Safe fallback
    }

    $colChecks = [
        ['packets', 'is_reassorted', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER polish_stock_id'],
        ['packets', 'reassort_lot_id', 'INT DEFAULT NULL AFTER is_reassorted'],
        ['packets', 'reassort_lot_no', 'VARCHAR(50) DEFAULT NULL AFTER reassort_lot_id'],
        ['packets', 'reassort_task_id', 'INT DEFAULT NULL AFTER reassort_lot_no'],
        ['packets', 'reassorted_at', 'DATETIME DEFAULT NULL AFTER reassort_task_id'],
        ['packets', 'origin_reassort_lot_id', 'INT DEFAULT NULL AFTER assort_number'],
        ['packets', 'origin_reassort_lot_no', 'VARCHAR(50) DEFAULT NULL AFTER origin_reassort_lot_id'],
        ['polish_stocks', 'reassort_lot_id', 'INT DEFAULT NULL AFTER status'],
        ['polish_stocks', 'reassort_lot_no', 'VARCHAR(50) DEFAULT NULL AFTER reassort_lot_id'],
        ['boil_batches', 'office_return_date', 'DATE DEFAULT NULL AFTER completed_date'],
        ['boil_batches', 'polish_pcs', 'INT NOT NULL DEFAULT 0 AFTER office_return_date'],
        ['boil_batches', 'polish_weight', 'DECIMAL(10,3) NOT NULL DEFAULT 0.000 AFTER polish_pcs'],
        ['boil_batches', 'reassort_pcs', 'INT NOT NULL DEFAULT 0 AFTER polish_weight'],
        ['boil_batches', 'reassort_weight', 'DECIMAL(10,3) NOT NULL DEFAULT 0.000 AFTER reassort_pcs'],
        ['assort_tasks', 'source_type', "ENUM('rough_purchase','boil_reassort') NOT NULL DEFAULT 'rough_purchase' AFTER task_no"],
        ['assort_tasks', 'reassort_lot_id', 'INT DEFAULT NULL AFTER rough_purchase_id'],
        ['assort_tasks', 'lot_no', 'VARCHAR(50) DEFAULT NULL AFTER reassort_lot_id'],
        ['assort_tasks', 'lot_rate', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER lot_no'],
        ['assort_tasks', 'total_lot_value', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER lot_rate'],
        ['boil_reassort_lots', 'lot_rate', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER total_weight'],
        ['boil_reassort_lots', 'total_lot_value', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER lot_rate'],
        ['assort_parts', 'source_type', "ENUM('rough_purchase','boil_reassort') NOT NULL DEFAULT 'rough_purchase' AFTER assort_task_id"],
        ['assort_parts', 'reassort_lot_id', 'INT DEFAULT NULL AFTER source_type'],
        ['assort_parts', 'lot_no', 'VARCHAR(50) DEFAULT NULL AFTER reassort_lot_id'],
        ['assort_parts', 'cost_per_carat', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER weight'],
    ];

    $colExistsStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");

    foreach ($colChecks as [$tbl, $col, $def]) {
        try {
            $colExistsStmt->execute([$tbl, $col]);
            if ((int)$colExistsStmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE {$tbl} ADD COLUMN {$col} {$def}");
            }
        } catch (\Throwable $e) {
            // Safe fallback
        }
    }
}

/**
 * Generate next unique Re-Assort Lot Number (e.g. LOT-2026-0001).
 */
function generate_next_reassort_lot_no(): string {
    $pdo = getDbConnection();
    $year = date('Y');
    $pattern = 'LOT-' . $year . '-%';
    $maxSeq = 0;

    foreach (['boil_reassort_lots', 'assort_tasks'] as $tbl) {
        try {
            $st = $pdo->prepare("SELECT lot_no FROM {$tbl} WHERE lot_no LIKE ? ORDER BY id DESC");
            $st->execute([$pattern]);
            while ($val = $st->fetchColumn()) {
                $parts = explode('-', (string)$val);
                $num = (int)end($parts);
                if ($num > $maxSeq) {
                    $maxSeq = $num;
                }
            }
        } catch (\Throwable $e) {
            // Ignore if table/column not yet present
        }
    }

    return sprintf('LOT-%s-%s', $year, str_pad((string)($maxSeq + 1), 4, '0', STR_PAD_LEFT));
}

/**
 * Recalculate active Polish metrics for a Kapan excluding diamonds that exited via Boil Re-Assort.
 */
function recalculate_kapan_active_metrics(int $kapanId): void {
    if ($kapanId <= 0) {
        return;
    }
    $pdo = getDbConnection();

    $aggStmt = $pdo->prepare("
        SELECT
            COUNT(CASE WHEN (current_stage = 'polish_stock' OR status IN ('boil_ready', 'in_polish_stock', 'sold')) AND COALESCE(is_reassorted, 0) = 0 AND status != 'reassorted' THEN 1 END) as pol_pcs,
            COALESCE(SUM(CASE WHEN (current_stage = 'polish_stock' OR status IN ('boil_ready', 'in_polish_stock', 'sold')) AND COALESCE(is_reassorted, 0) = 0 AND status != 'reassorted' THEN polish_weight ELSE 0 END), 0) as pol_wt,
            COALESCE(SUM(tops_weight), 0) as tops_wt
        FROM packets
        WHERE kapan_id = ?
    ");
    $aggStmt->execute([$kapanId]);
    $agg = $aggStmt->fetch(PDO::FETCH_ASSOC);
    if (!$agg) {
        return;
    }

    $polPcs = (int)($agg['pol_pcs'] ?? 0);
    $polWt  = round((float)($agg['pol_wt'] ?? 0), 3);
    $topsWt = round((float)($agg['tops_wt'] ?? 0), 3);

    $kStmt = $pdo->prepare("SELECT total_weight, total_cost FROM kapans WHERE id = ?");
    $kStmt->execute([$kapanId]);
    $kapan = $kStmt->fetch(PDO::FETCH_ASSOC);
    if (!$kapan) {
        return;
    }

    $totWt   = (float)($kapan['total_weight'] ?? 0);
    $totCost = (float)($kapan['total_cost'] ?? 0);
    $yield   = ($totWt > 0 && $polWt > 0) ? round(($polWt / $totWt) * 100, 2) : 0.00;
    $perPc   = ($polPcs > 0) ? round($totCost / $polPcs, 2) : 0.00;
    $perCt   = ($polWt > 0) ? round($totCost / $polWt, 2) : 0.00;

    $upd = $pdo->prepare("
        UPDATE kapans SET
            total_polish_pcs = ?,
            total_polish_weight = ?,
            total_tops_weight = ?,
            yield_percentage = ?,
            cost_per_piece = ?,
            cost_per_carat = ?
        WHERE id = ?
    ");
    $upd->execute([$polPcs, $polWt, $topsWt, $yield, $perPc, $perCt, $kapanId]);
}

/**
 * Refresh cached Boil Batch breakdown totals (Polish Stock vs Re-Assort).
 */
function recalculate_boil_batch_totals(int $batchId): void {
    if ($batchId <= 0) {
        return;
    }
    $pdo = getDbConnection();
    $aggStmt = $pdo->prepare("
        SELECT
            COUNT(CASE WHEN COALESCE(is_reassorted, 0) = 0 AND status != 'reassorted' AND (current_stage = 'polish_stock' OR status IN ('boil_ready', 'in_polish_stock', 'sold')) THEN 1 END) as pol_pcs,
            COALESCE(SUM(CASE WHEN COALESCE(is_reassorted, 0) = 0 AND status != 'reassorted' AND (current_stage = 'polish_stock' OR status IN ('boil_ready', 'in_polish_stock', 'sold')) THEN polish_weight ELSE 0 END), 0) as pol_wt,
            COUNT(CASE WHEN COALESCE(is_reassorted, 0) = 1 OR status = 'reassorted' THEN 1 END) as rea_pcs,
            COALESCE(SUM(CASE WHEN COALESCE(is_reassorted, 0) = 1 OR status = 'reassorted' THEN polish_weight ELSE 0 END), 0) as rea_wt,
            COUNT(CASE WHEN COALESCE(is_reassorted, 0) = 0 AND current_stage = 'boil' THEN 1 END) as pending_boil_pcs
        FROM packets
        WHERE boil_batch_id = ?
    ");
    $aggStmt->execute([$batchId]);
    $agg = $aggStmt->fetch(PDO::FETCH_ASSOC);
    if (!$agg) {
        return;
    }

    $pending = (int)($agg['pending_boil_pcs'] ?? 0);
    $status = ($pending === 0) ? 'completed' : 'in_boil';

    $pdo->prepare("
        UPDATE boil_batches SET
            polish_pcs = ?,
            polish_weight = ?,
            reassort_pcs = ?,
            reassort_weight = ?,
            status = ?,
            completed_date = CASE WHEN ? = 'completed' AND completed_date IS NULL THEN CURDATE() ELSE completed_date END
        WHERE id = ?
    ")->execute([
        (int)$agg['pol_pcs'],
        round((float)$agg['pol_wt'], 3),
        (int)$agg['rea_pcs'],
        round((float)$agg['rea_wt'], 3),
        $status,
        $status,
        $batchId
    ]);
}

/**
 * Finalize Boil-returned packets into Polish Stock (call inside active transaction).
 */
function process_boil_return_to_polish_stock(int $batchId, array $packetIds, string $returnDate, string $notes = '', ?int $userId = null): array {
    $pdo = getDbConnection();
    $cleanIds = array_values(array_unique(array_filter(array_map('intval', $packetIds), fn($v) => $v > 0)));
    if (empty($cleanIds)) {
        return ['count' => 0, 'weight' => 0.0];
    }

    $inClause = implode(',', $cleanIds);
    $pktRows = $pdo->query("
        SELECT p.*, k.kapan_code, k.total_cost, k.total_polish_weight, k.cost_per_carat
        FROM packets p
        JOIN kapans k ON p.kapan_id = k.id
        WHERE p.id IN ($inClause)
          AND COALESCE(p.is_reassorted, 0) = 0
          AND p.status NOT IN ('sold', 'reassorted')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $batchNoStmt = $pdo->prepare("SELECT batch_no FROM boil_batches WHERE id = ?");
    $batchNoStmt->execute([$batchId]);
    $batchNo = (string)($batchNoStmt->fetchColumn() ?: ('#' . $batchId));

    $insStk = $pdo->prepare("INSERT INTO polish_stocks (
        packet_no, stock_type, source_type, kapan_id, shape, clarity, color, cut,
        total_pcs, total_weight, available_pcs, available_weight, cost_per_carat, sale_price_per_carat, current_location, status
    ) VALUES (?, 'single', 'mfg', ?, ?, 'VS1', 'G', 'EX', 1, ?, 1, ?, ?, ?, 'Surat', 'available')");

    $updPkt = $pdo->prepare("UPDATE packets SET boil_batch_id = ?, polish_stock_id = ?, current_stage = 'polish_stock', current_location = 'Surat', assigned_party_id = NULL, status = 'boil_ready', diamond_status = 'completed' WHERE id = ?");

    $insDsh = $pdo->prepare("
        INSERT INTO diamond_stage_history (
            packet_id, diamond_no, stage_code, stage_name, given_weight, received_weight,
            difference_weight, pending_weight, diff_classification, assigned_date, return_date,
            outgoing_location, receiving_location, status, notes, logged_by
        ) VALUES (?, ?, 'boil_return_polish', 'Boil પૂર્ણ -> Polish Stock (ઓફિસ પરત)', ?, ?, 0.000, 0.000, 'none', ?, ?, 'Boil Dept', 'Surat (Office)', 'completed', ?, ?)
    ");

    $insPph = $pdo->prepare("
        INSERT INTO packet_process_history (
            packet_id, diamond_no, stage, action, input_weight, output_weight,
            process_date, location, status, details, logged_by
        ) VALUES (?, ?, 'polish_stock', 'Boil Return to Polish Stock', ?, ?, ?, 'Surat (Office)', 'completed', ?, ?)
    ");

    $count = 0;
    $weightSum = 0.0;
    $kapanIds = [];

    foreach ($pktRows as $pr) {
        $wt = (float)$pr['polish_weight'] > 0
            ? (float)$pr['polish_weight']
            : ((float)$pr['current_weight'] > 0 ? (float)$pr['current_weight'] : (float)$pr['weight']);
        $basePktNo = !empty($pr['packet_no']) ? $pr['packet_no'] : (!empty($pr['diamond_no']) ? $pr['diamond_no'] : (string)$pr['id']);
        $stockPktNo = 'POL-' . $basePktNo;

        $checkStk = $pdo->prepare("SELECT id FROM polish_stocks WHERE packet_no = ?");
        $checkStk->execute([$stockPktNo]);
        $stkId = (int)$checkStk->fetchColumn();

        if ($stkId <= 0) {
            $costPerCt = (float)($pr['cost_per_carat'] ?? 0);
            if ($costPerCt <= 0 && (float)$pr['total_polish_weight'] > 0) {
                $costPerCt = round((float)$pr['total_cost'] / (float)$pr['total_polish_weight'], 2);
            }
            if ($costPerCt <= 0) {
                $costPerCt = 35000.00;
            }
            $shape = !empty($pr['expected_shape']) ? $pr['expected_shape'] : 'Round';
            $insStk->execute([$stockPktNo, $pr['kapan_id'], $shape, $wt, $wt, $costPerCt, round($costPerCt * 1.15, 2)]);
            $stkId = (int)$pdo->lastInsertId();
        }

        $updPkt->execute([$batchId, $stkId, $pr['id']]);
        $dNo = !empty($pr['diamond_no']) ? $pr['diamond_no'] : $basePktNo;
        $noteText = "Boil Batch {$batchNo} પૂર્ણ થઈ Polish Stock ({$stockPktNo}) માં જમા." . ($notes !== '' ? " {$notes}" : '');
        $insDsh->execute([$pr['id'], $dNo, $wt, $wt, $returnDate, $returnDate, $noteText, $userId]);
        $insPph->execute([$pr['id'], $dNo, $wt, $wt, $returnDate, $noteText, $userId]);

        $count++;
        $weightSum = round($weightSum + $wt, 3);
        $kapanIds[(int)$pr['kapan_id']] = true;
    }

    $pdo->prepare("UPDATE boil_batches SET office_return_date = COALESCE(office_return_date, ?) WHERE id = ?")->execute([$returnDate, $batchId]);

    foreach (array_keys($kapanIds) as $kId) {
        recalculate_kapan_active_metrics((int)$kId);
        $pdo->prepare("UPDATE kapans SET status = 'boil_completed' WHERE id = ? AND status IN ('active', 'in_production')")->execute([(int)$kId]);
    }
    recalculate_boil_batch_totals($batchId);

    return ['count' => $count, 'weight' => $weightSum];
}

/**
 * Send Boil-returned packets at Office to Re-Assort:
 *  - Assigns a new Lot Number (e.g. LOT-2026-0001)
 *  - Detaches packets from Old Kapan active stock & Polish Stock
 *  - Preserves complete audit history on old packets, Old Kapan, Boil Batch & boil_reassort_lot_items
 * Must be called inside an active PDO transaction.
 */
function process_boil_return_to_reassort(?int $batchId, array $packetIds, string $lotNo = '', ?string $returnDate = null, string $notes = '', ?int $userId = null, float $lotRate = 0.0): array {
    $pdo = getDbConnection();
    $cleanIds = array_values(array_unique(array_filter(array_map('intval', $packetIds), fn($v) => $v > 0)));
    if (empty($cleanIds)) {
        throw new \RuntimeException('Re-Assort માટે ઓછામાં ઓછું એક પેકેટ પસંદ કરો.');
    }

    $lotNo = trim($lotNo);
    if ($lotNo === '') {
        $lotNo = generate_next_reassort_lot_no();
    }

    $returnDate = !empty($returnDate) ? trim($returnDate) : date('Y-m-d');

    // Ensure unique Lot No
    $dupChk = $pdo->prepare("SELECT COUNT(*) FROM boil_reassort_lots WHERE lot_no = ?");
    $dupChk->execute([$lotNo]);
    if ((int)$dupChk->fetchColumn() > 0) {
        throw new \RuntimeException("લોટ નંબર '{$lotNo}' પહેલેથી મોજૂદ છે. કૃપા કરીને નવો યુનિક લોટ નંબર આપો.");
    }

    $inClause = implode(',', $cleanIds);
    $pktRows = $pdo->query("
        SELECT p.*, k.kapan_code, k.kapan_name, k.total_cost, k.total_polish_weight, k.cost_per_carat,
               ps.status as polish_stock_status, ps.available_weight as ps_avail_wt, ps.total_weight as ps_total_wt
        FROM packets p
        JOIN kapans k ON p.kapan_id = k.id
        LEFT JOIN polish_stocks ps ON p.polish_stock_id = ps.id
        WHERE p.id IN ($inClause)
        FOR UPDATE
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($pktRows)) {
        throw new \RuntimeException('પસંદ કરેલ પેકેટ્સ મળ્યા નથી.');
    }

    $totalPcs = 0;
    $totalWt = 0.0;
    $kapanIdsMap = [];
    $kapanCodesMap = [];

    foreach ($pktRows as $pr) {
        $dNo = !empty($pr['diamond_no']) ? $pr['diamond_no'] : $pr['packet_no'];
        $curSt = strtolower(trim((string)($pr['current_stage'] ?? '')));
        if (!in_array($curSt, ['boil', 'boil_return', 'polish_return', 'boil_polish', 'weekly_boil'], true)) {
            throw new \RuntimeException("પેકેટ {$dNo} સ્ટેજ '{$pr['current_stage']}' માં છે. માત્ર બોઇલ રિટર્ન (Boil Return) થયેલા હીરા જ Re-Assort થઈ શકે છે.");
        }
        if (!empty($pr['is_reassorted']) || ($pr['status'] ?? '') === 'reassorted') {
            throw new \RuntimeException("પેકેટ {$dNo} પહેલેથી Re-Assort (Lot: {$pr['reassort_lot_no']}) માં મોકલાઈ ગયેલ છે.");
        }
        if (($pr['status'] ?? '') === 'sold') {
            throw new \RuntimeException("પેકેટ {$dNo} વેચાઈ ગયેલ (Sold) હોવાથી Re-Assort થઈ શકે નહીં.");
        }
        if (($pr['diamond_status'] ?? '') === 'assigned_to_jangad' || in_array(($pr['polish_stock_status'] ?? ''), ['on_jangad', 'partially_on_jangad', 'sold'], true)) {
            throw new \RuntimeException("પેકેટ {$dNo} હાલ જાંગડ પર અથવા વેચાયેલ હોવાથી Re-Assort થઈ શકે નહીં.");
        }

        $wt = (float)$pr['polish_weight'] > 0
            ? (float)$pr['polish_weight']
            : ((float)$pr['current_weight'] > 0 ? (float)$pr['current_weight'] : (float)$pr['weight']);
        $totalPcs += max(1, (int)$pr['pcs']);
        $totalWt = round($totalWt + $wt, 3);
        $kapanIdsMap[(int)$pr['kapan_id']] = true;
        $kapanCodesMap[(string)$pr['kapan_code']] = true;
    }

    $effBatchId = null;
    $batchNo = 'Direct Re-Assort';
    if (!empty($batchId) && (int)$batchId > 0) {
        $effBatchId = (int)$batchId;
        $batchStmt = $pdo->prepare("SELECT batch_no FROM boil_batches WHERE id = ?");
        $batchStmt->execute([$effBatchId]);
        $batchNo = (string)($batchStmt->fetchColumn() ?: ('#' . $effBatchId));
    }

    $srcKapanIdsStr = implode(',', array_keys($kapanIdsMap));
    $srcKapanCodesStr = implode(', ', array_keys($kapanCodesMap));

    $lotRate = max(0.0, round($lotRate, 2));
    $totalLotValue = round($lotRate * $totalWt, 2);

    // 1. Create Re-Assort Lot master record
    $insLot = $pdo->prepare("
        INSERT INTO boil_reassort_lots (
            lot_no, boil_batch_id, source_kapan_ids, source_kapan_codes,
            total_pcs, total_weight, lot_rate, total_lot_value, return_date, status, notes, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_assort', ?, ?)
    ");
    $insLot->execute([
        $lotNo,
        $effBatchId,
        $srcKapanIdsStr,
        $srcKapanCodesStr,
        $totalPcs,
        $totalWt,
        $lotRate,
        $totalLotValue,
        $returnDate,
        $notes,
        $userId
    ]);
    $lotId = (int)$pdo->lastInsertId();

    $insItem = $pdo->prepare("
        INSERT INTO boil_reassort_lot_items (
            reassort_lot_id, boil_batch_id, packet_id, old_kapan_id, old_kapan_code,
            old_diamond_no, old_polish_stock_id, pcs, weight, cost_per_carat
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $updPS = $pdo->prepare("
        UPDATE polish_stocks SET
            available_pcs = 0,
            available_weight = 0.000,
            status = 'reassorted',
            reassort_lot_id = ?,
            reassort_lot_no = ?,
            current_location = ?
        WHERE id = ?
    ");

    $updPkt = $pdo->prepare("
        UPDATE packets SET
            boil_batch_id = ?,
            polish_stock_id = NULL,
            current_stage = 'reassort_exited',
            current_location = ?,
            assigned_party_id = NULL,
            jangad_id = NULL,
            status = 'reassorted',
            diamond_status = 'completed',
            is_reassorted = 1,
            reassort_lot_id = ?,
            reassort_lot_no = ?,
            reassorted_at = NOW()
        WHERE id = ?
    ");

    $insDsh = $pdo->prepare("
        INSERT INTO diamond_stage_history (
            packet_id, diamond_no, stage_code, stage_name, given_weight, received_weight,
            difference_weight, pending_weight, diff_classification, assigned_date, return_date,
            outgoing_location, receiving_location, status, notes, logged_by
        ) VALUES (?, ?, 'reassort_exit', ?, ?, ?, 0.000, 0.000, 'none', ?, ?, 'Boil / Office', 'Assort Dept (Re-Assort)', 'completed', ?, ?)
    ");

    $insPph = $pdo->prepare("
        INSERT INTO packet_process_history (
            packet_id, diamond_no, stage, action, input_weight, output_weight,
            process_date, location, status, details, logged_by
        ) VALUES (?, ?, 'reassort_exit', 'Boil Return -> Re-Assort (Exited Old Kapan)', ?, ?, ?, 'Assort Dept', 'completed', ?, ?)
    ");

    foreach ($pktRows as $pr) {
        $wt = (float)$pr['polish_weight'] > 0
            ? (float)$pr['polish_weight']
            : ((float)$pr['current_weight'] > 0 ? (float)$pr['current_weight'] : (float)$pr['weight']);
        $dNo = !empty($pr['diamond_no']) ? $pr['diamond_no'] : $pr['packet_no'];
        $costPerCt = (float)($pr['cost_per_carat'] ?? 0);
        if ($costPerCt <= 0 && (float)$pr['total_polish_weight'] > 0) {
            $costPerCt = round((float)$pr['total_cost'] / (float)$pr['total_polish_weight'], 2);
        }

        $oldStkId = !empty($pr['polish_stock_id']) ? (int)$pr['polish_stock_id'] : null;
        $insItem->execute([
            $lotId,
            $batchId,
            $pr['id'],
            $pr['kapan_id'],
            $pr['kapan_code'],
            $dNo,
            $oldStkId,
            max(1, (int)$pr['pcs']),
            $wt,
            $costPerCt
        ]);

        if ($oldStkId) {
            $updPS->execute([$lotId, $lotNo, "Re-Assorted ({$lotNo})", $oldStkId]);
        }

        $updPkt->execute([
            $effBatchId,
            "Assort Dept ({$lotNo})",
            $lotId,
            $lotNo,
            $pr['id']
        ]);

        $stageTitle = "Boil પરત -> Re-Assort (નવો લોટ: {$lotNo})";
        $histNote = ($effBatchId ? "Boil Batch {$batchNo} બાદ ઓફિસ પરત" : "પ્રોસેસ / સ્ટોકમાંથી Re-Assort") . " — જૂની કાપન {$pr['kapan_code']} માંથી નીકળી નવો લોટ {$lotNo} માં દાખલ." . ($notes !== '' ? " {$notes}" : '');
        $insDsh->execute([$pr['id'], $dNo, $stageTitle, $wt, $wt, $returnDate, $returnDate, $histNote, $userId]);
        $insPph->execute([$pr['id'], $dNo, $wt, $wt, $returnDate, $histNote, $userId]);
    }

    if ($effBatchId) {
        $pdo->prepare("UPDATE boil_batches SET office_return_date = COALESCE(office_return_date, ?) WHERE id = ?")->execute([$returnDate, $effBatchId]);
        recalculate_boil_batch_totals($effBatchId);
    }

    foreach (array_keys($kapanIdsMap) as $kId) {
        recalculate_kapan_active_metrics((int)$kId);
    }

    return [
        'reassort_lot_id' => $lotId,
        'lot_no'          => $lotNo,
        'pcs'             => $totalPcs,
        'weight'          => $totalWt,
        'lot_rate'        => $lotRate,
        'total_lot_value' => $totalLotValue,
        'kapan_codes'     => $srcKapanCodesStr,
    ];
}

/**
 * Update Re-Assort Lot Rate (₹/Carat) & Part Rates after Re-Assort.
 * Syncs boil_reassort_lots, assort_tasks, assort_parts, and any linked Kapan costing.
 * Must be called inside an active PDO transaction.
 */
function update_reassort_lot_rates(int $assortTaskId, float $lotRate, array $partRatesById = [], bool $applyLotRateToAllParts = true): array {
    $pdo = getDbConnection();
    $lotRate = max(0.0, round($lotRate, 2));

    $tStmt = $pdo->prepare("SELECT * FROM assort_tasks WHERE id = ?");
    $tStmt->execute([$assortTaskId]);
    $task = $tStmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        throw new \RuntimeException('Assort ટાસ્ક મળ્યો નથી.');
    }

    $pStmt = $pdo->prepare("SELECT * FROM assort_parts WHERE assort_task_id = ? ORDER BY id ASC");
    $pStmt->execute([$assortTaskId]);
    $parts = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    $updPart = $pdo->prepare("UPDATE assort_parts SET cost_per_carat = ? WHERE id = ?");
    $totalPartsWt = 0.0;
    $totalPartsValue = 0.0;
    $updatedPartIds = [];

    foreach ($parts as $part) {
        $pId = (int)$part['id'];
        $pWt = (float)($part['weight'] ?? 0);

        if (array_key_exists($pId, $partRatesById) && $partRatesById[$pId] !== '' && $partRatesById[$pId] !== null) {
            $newRate = max(0.0, round((float)$partRatesById[$pId], 2));
        } elseif ($applyLotRateToAllParts) {
            $newRate = $lotRate;
        } else {
            $newRate = (float)($part['cost_per_carat'] ?? 0);
        }

        $updPart->execute([$newRate, $pId]);
        $updatedPartIds[$pId] = $newRate;
        $totalPartsWt = round($totalPartsWt + $pWt, 3);
        $totalPartsValue = round($totalPartsValue + ($pWt * $newRate), 2);
    }

    $effectiveLotRate = ($lotRate > 0)
        ? $lotRate
        : (($totalPartsWt > 0 && $totalPartsValue > 0) ? round($totalPartsValue / $totalPartsWt, 2) : 0.0);
    $effectiveTotalValue = ($totalPartsValue > 0)
        ? $totalPartsValue
        : round($effectiveLotRate * (float)($task['total_output_weight'] ?: $task['total_input_weight']), 2);

    $pdo->prepare("UPDATE assort_tasks SET lot_rate = ?, total_lot_value = ? WHERE id = ?")
        ->execute([$effectiveLotRate, $effectiveTotalValue, $assortTaskId]);

    if (!empty($task['reassort_lot_id'])) {
        $pdo->prepare("UPDATE boil_reassort_lots SET lot_rate = ?, total_lot_value = ? WHERE id = ?")
            ->execute([$effectiveLotRate, $effectiveTotalValue, (int)$task['reassort_lot_id']]);
    }

    // Sync any Kapans that already consumed these assort_parts
    if (!empty($updatedPartIds)) {
        $inIds = implode(',', array_map('intval', array_keys($updatedPartIds)));
        $mapRows = $pdo->query("
            SELECT id, kapan_id, rough_packet_id, allocated_weight
            FROM kapan_rough_mapping
            WHERE rough_packet_type = 'assort_part' AND rough_packet_id IN ($inIds)
        ")->fetchAll(PDO::FETCH_ASSOC);

        $affectedKapanIds = [];
        $updMap = $pdo->prepare("UPDATE kapan_rough_mapping SET allocated_cost = ? WHERE id = ?");
        foreach ($mapRows as $mr) {
            $rpId = (int)$mr['rough_packet_id'];
            $rate = $updatedPartIds[$rpId] ?? $effectiveLotRate;
            $allocCost = round((float)$mr['allocated_weight'] * $rate, 2);
            $updMap->execute([$allocCost, (int)$mr['id']]);
            $affectedKapanIds[(int)$mr['kapan_id']] = true;
        }

        foreach (array_keys($affectedKapanIds) as $kId) {
            $sumRoughCostStmt = $pdo->prepare("SELECT COALESCE(SUM(allocated_cost), 0) FROM kapan_rough_mapping WHERE kapan_id = ?");
            $sumRoughCostStmt->execute([$kId]);
            $sumRoughCost = (float)$sumRoughCostStmt->fetchColumn();
            $pdo->prepare("
                UPDATE kapans SET
                    rough_cost = ?,
                    total_cost = ? + COALESCE(galaxy_cost, 0) + COALESCE(ls_cost, 0) + COALESCE(labour_cost, 0) + COALESCE(other_cost, 0)
                WHERE id = ?
            ")->execute([round($sumRoughCost, 2), round($sumRoughCost, 2), $kId]);
            recalculate_kapan_active_metrics($kId);
        }
    }

    return [
        'lot_rate'        => $effectiveLotRate,
        'total_lot_value' => $effectiveTotalValue,
    ];
}

/**
 * Master List of Diamond Shapes for Polish Modules
 */
function get_polish_shapes_list(): array {
    $options = get_gia_master_options('shape');
    if (!empty($options)) {
        $list = [];
        foreach ($options as $opt) {
            $list[$opt['code']] = $opt['name'];
        }
        return $list;
    }
    return [
        'Round'    => 'Round Brilliant',
        'Princess' => 'Princess',
        'Pear'     => 'Pear',
        'Marquise' => 'Marquise',
        'Oval'     => 'Oval',
        'Cushion'  => 'Cushion',
        'Emerald'  => 'Emerald',
        'Radiant'  => 'Radiant',
        'Heart'    => 'Heart',
        'Asscher'  => 'Asscher',
        'Fancy'    => 'Other Fancy'
    ];
}

/**
 * Validate Parcel Shape-wise Breakdown according to Strict Business Rules:
 * 1. Quantity Validation: Sum(Shape Qty) == Parcel Qty
 * 2. 1% Weight Validation: Abs(Parcel Weight - Sum(Shape Weight)) <= (Parcel Weight * 0.01)
 */
function validate_parcel_shape_breakdown(int $parcelPcs, float $parcelWeight, array $shapes): array {
    $cleanRows = [];
    $totalShapePcs = 0;
    $totalShapeWeight = 0.000;

    if ($parcelWeight <= 0) {
        return [
            'valid'        => false,
            'error'        => 'પાર્સલ વજન (Parcel Weight) 0 થી વધુ હોવું જોઈએ.',
            'total_pcs'    => 0,
            'total_weight' => 0.000,
            'actual_diff'  => 0,
            'allowed_diff' => 0,
            'clean_rows'   => []
        ];
    }

    foreach ($shapes as $idx => $row) {
        $shapeName = trim((string)($row['shape'] ?? ''));
        if (empty($shapeName)) {
            continue;
        }
        $qty = (int)($row['pcs'] ?? $row['qty'] ?? 0);
        $totalWt = isset($row['total_weight']) && (float)$row['total_weight'] > 0
            ? round((float)$row['total_weight'], 3)
            : 0.000;
        $weightPerShape = (float)($row['weight_per_shape'] ?? 0.0000);

        if ($totalWt > 0 && $qty > 0 && $weightPerShape <= 0) {
            $weightPerShape = round($totalWt / $qty, 4);
        } elseif ($weightPerShape > 0 && $qty > 0 && $totalWt <= 0) {
            $totalWt = round($qty * $weightPerShape, 3);
        }

        $approxPrice = (float)($row['price_per_carat'] ?? $row['approx_price'] ?? 0.00);

        if ($qty <= 0 && $totalWt <= 0) {
            continue;
        }

        $totalShapePcs += $qty;
        $totalShapeWeight = round($totalShapeWeight + $totalWt, 3);

        $cleanRows[] = [
            'shape'            => $shapeName,
            'pcs'              => $qty,
            'weight_per_shape' => $weightPerShape,
            'total_weight'     => $totalWt,
            'price_per_carat'  => $approxPrice
        ];
    }

    // If shapes were entered with only Pcs (no total_weight entered) and parcelWeight > 0 and totalShapePcs > 0:
    // Distribute total parcel weight proportionally based on Pcs!
    if (!empty($cleanRows) && $totalShapeWeight <= 0 && $parcelWeight > 0 && $totalShapePcs > 0) {
        $runningWeight = 0.000;
        $count = count($cleanRows);
        foreach ($cleanRows as $k => &$cr) {
            if ($k === $count - 1) {
                // Last row gets remainder so sum equals parcel weight exactly
                $cr['total_weight'] = round($parcelWeight - $runningWeight, 3);
            } else {
                $cr['total_weight'] = round(($cr['pcs'] / $totalShapePcs) * $parcelWeight, 3);
                $runningWeight = round($runningWeight + $cr['total_weight'], 3);
            }
            $cr['weight_per_shape'] = ($cr['pcs'] > 0) ? round($cr['total_weight'] / $cr['pcs'], 4) : 0.0000;
        }
        unset($cr);
        $totalShapeWeight = $parcelWeight;
    }

    // If no rows were provided at all, fallback to a single default row
    if (empty($cleanRows)) {
        if ($parcelPcs > 0 && $parcelWeight > 0) {
            $cleanRows[] = [
                'shape' => 'Mix',
                'pcs' => $parcelPcs,
                'weight_per_shape' => round($parcelWeight / $parcelPcs, 4),
                'total_weight' => $parcelWeight,
                'price_per_carat' => 0.00
            ];
            $totalShapePcs = $parcelPcs;
            $totalShapeWeight = $parcelWeight;
        } else {
            return [
                'valid'        => false,
                'error'        => 'કૃપા કરીને પાર્સલ માટે શેપ અને નંગ (Shape & Pcs) દાખલ કરો.',
                'total_pcs'    => 0,
                'total_weight' => 0.000,
                'actual_diff'  => $parcelWeight,
                'allowed_diff' => round($parcelWeight * 0.01, 3),
                'clean_rows'   => []
            ];
        }
    }

    $allowedDiff = round($parcelWeight * 0.01, 3);
    $actualDiff = round(abs($parcelWeight - $totalShapeWeight), 3);

    // 1. Quantity Validation Rule
    if ($parcelPcs > 0 && $totalShapePcs !== $parcelPcs) {
        return [
            'valid'        => false,
            'error'        => "Total Shape Quantity ($totalShapePcs Pcs) must match the selected Parcel Quantity ($parcelPcs Pcs).",
            'total_pcs'    => $totalShapePcs,
            'total_weight' => $totalShapeWeight,
            'actual_diff'  => $actualDiff,
            'allowed_diff' => $allowedDiff,
            'clean_rows'   => $cleanRows
        ];
    }

    // 2. Critical Weight Validation – 1% Rule
    if ($actualDiff > ($allowedDiff + 0.0001)) {
        return [
            'valid'        => false,
            'error'        => "Shape-wise Total Weight ({$totalShapeWeight} Ct) exceeds the allowed 1% parcel weight difference. Please correct the shape-wise weight entries.",
            'total_pcs'    => $totalShapePcs,
            'total_weight' => $totalShapeWeight,
            'actual_diff'  => $actualDiff,
            'allowed_diff' => $allowedDiff,
            'clean_rows'   => $cleanRows
        ];
    }

    return [
        'valid'        => true,
        'error'        => '',
        'total_pcs'    => $totalShapePcs,
        'total_weight' => $totalShapeWeight,
        'actual_diff'  => $actualDiff,
        'allowed_diff' => $allowedDiff,
        'clean_rows'   => $cleanRows
    ];
}

/**
 * Save / Replace Polish Parcel Shape Breakdown in Database
 */
function save_polish_parcel_shapes(PDO $pdo, string $transType, int $transId, ?int $stockId, array $cleanRows): void {
    // Delete existing entries for this transaction
    $delStmt = $pdo->prepare("DELETE FROM polish_parcel_shapes WHERE transaction_type = ? AND transaction_id = ?");
    $delStmt->execute([$transType, $transId]);

    if (empty($cleanRows)) {
        return;
    }

    $insStmt = $pdo->prepare("
        INSERT INTO polish_parcel_shapes (
            transaction_type, transaction_id, polish_stock_id, shape,
            pcs, weight_per_shape, total_weight, price_per_carat
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?, ?
        )
    ");

    foreach ($cleanRows as $r) {
        $insStmt->execute([
            $transType,
            $transId,
            $stockId,
            $r['shape'],
            (int)$r['pcs'],
            (float)$r['weight_per_shape'],
            (float)$r['total_weight'],
            (float)$r['price_per_carat']
        ]);
    }
}

/**
 * Fetch Polish Parcel Shape Breakdown for Transaction
 */
function get_polish_parcel_shapes(PDO $pdo, string $transType, int $transId): array {
    $stmt = $pdo->prepare("
        SELECT id, shape, pcs, weight_per_shape, total_weight, price_per_carat
        FROM polish_parcel_shapes
        WHERE transaction_type = ? AND transaction_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([$transType, $transId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * GIA Master Attribute Definitions
 */
function get_gia_attribute_types(): array {
    return [
        'shape' => [
            'key' => 'shape',
            'title' => 'Diamond Shape',
            'title_gu' => 'ડાયમંડ શેપ (આકાર)',
            'icon' => 'gem',
            'placeholder_code' => 'ROUND',
            'placeholder_name' => 'Round (ગોળ)'
        ],
        'color' => [
            'key' => 'color',
            'title' => 'Color Grade',
            'title_gu' => 'કલર ગ્રેડ (કલર)',
            'icon' => 'palette',
            'placeholder_code' => 'D',
            'placeholder_name' => 'D (Colorless)'
        ],
        'clarity' => [
            'key' => 'clarity',
            'title' => 'Clarity Grade',
            'title_gu' => 'ક્લેરિટી ગ્રેડ (ક્લેરિટી)',
            'icon' => 'magnifying-glass',
            'placeholder_code' => 'VVS1',
            'placeholder_name' => 'VVS1 (Very Very Slightly Included 1)'
        ],
        'polish' => [
            'key' => 'polish',
            'title' => 'Polish',
            'title_gu' => 'પોલિશ (Polish)',
            'icon' => 'sparkles',
            'placeholder_code' => 'EX',
            'placeholder_name' => 'EX (Excellent)'
        ],
        'symmetry' => [
            'key' => 'symmetry',
            'title' => 'Symmetry',
            'title_gu' => 'સિમિટ્રી (Symmetry)',
            'icon' => 'shapes',
            'placeholder_code' => 'EX',
            'placeholder_name' => 'EX (Excellent)'
        ],
        'fluorescence' => [
            'key' => 'fluorescence',
            'title' => 'Floro (Fluorescence)',
            'title_gu' => 'ફ્લોરોસન્સ (Floro)',
            'icon' => 'sun',
            'placeholder_code' => 'NONE',
            'placeholder_name' => 'None (નિલ)'
        ],
        'key_symbols' => [
            'key' => 'key_symbols',
            'title' => 'Key to Symbols',
            'title_gu' => 'કી ટુ સિમ્બોલ્સ (Key to Symbols)',
            'icon' => 'tag',
            'placeholder_code' => 'Feather',
            'placeholder_name' => 'Feather (પીંછું / કાપો)'
        ]
    ];
}

/**
 * Fetch GIA Master options from master_gia_attributes table
 */
function get_gia_master_options(string $attributeType, bool $activeOnly = true): array {
    try {
        $pdo = getDbConnection();
        $sql = "SELECT id, attribute_type, code, name, sort_order, status 
                FROM master_gia_attributes 
                WHERE attribute_type = ?";
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$attributeType]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            return $rows;
        }
    } catch (\Throwable $e) {
        error_log("get_gia_master_options error: " . $e->getMessage());
    }

    // Default Fallbacks
    $defaults = [
        'shape' => [
            ['code' => 'Round', 'name' => 'Round (ગોળ)'],
            ['code' => 'Princess', 'name' => 'Princess (ચોરસ)'],
            ['code' => 'Pear', 'name' => 'Pear (શેપ / નાસપતી)'],
            ['code' => 'Marquise', 'name' => 'Marquise (માર્કીસ)'],
            ['code' => 'Oval', 'name' => 'Oval (લંબગોળ)'],
            ['code' => 'Cushion', 'name' => 'Cushion (કુશન)'],
            ['code' => 'Emerald', 'name' => 'Emerald (એમરાલ્ડ)'],
            ['code' => 'Radiant', 'name' => 'Radiant (રેડિયન્ટ)'],
            ['code' => 'Heart', 'name' => 'Heart (હાર્ટ / દિલ)'],
            ['code' => 'Asscher', 'name' => 'Asscher (આશેર)'],
            ['code' => 'Fancy', 'name' => 'Fancy Shapes (અન્ય ફેન્સી)']
        ],
        'color' => [
            ['code' => 'D', 'name' => 'D (Colorless)'],
            ['code' => 'E', 'name' => 'E (Colorless)'],
            ['code' => 'F', 'name' => 'F (Colorless)'],
            ['code' => 'G', 'name' => 'G (Near Colorless)'],
            ['code' => 'H', 'name' => 'H (Near Colorless)'],
            ['code' => 'I', 'name' => 'I (Near Colorless)'],
            ['code' => 'J', 'name' => 'J (Near Colorless)'],
            ['code' => 'K', 'name' => 'K (Faint Color)'],
            ['code' => 'Fancy', 'name' => 'Fancy Color']
        ],
        'clarity' => [
            ['code' => 'FL', 'name' => 'FL (Flawless)'],
            ['code' => 'IF', 'name' => 'IF (Internally Flawless)'],
            ['code' => 'VVS1', 'name' => 'VVS1'],
            ['code' => 'VVS2', 'name' => 'VVS2'],
            ['code' => 'VS1', 'name' => 'VS1'],
            ['code' => 'VS2', 'name' => 'VS2'],
            ['code' => 'SI1', 'name' => 'SI1'],
            ['code' => 'SI2', 'name' => 'SI2'],
            ['code' => 'I1', 'name' => 'I1'],
            ['code' => 'I2', 'name' => 'I2'],
            ['code' => 'I3', 'name' => 'I3']
        ],
        'polish' => [
            ['code' => 'EX', 'name' => 'EX (Excellent)'],
            ['code' => 'VG', 'name' => 'VG (Very Good)'],
            ['code' => 'GD', 'name' => 'GD (Good)'],
            ['code' => 'FR', 'name' => 'FR (Fair)'],
            ['code' => 'PR', 'name' => 'PR (Poor)']
        ],
        'symmetry' => [
            ['code' => 'EX', 'name' => 'EX (Excellent)'],
            ['code' => 'VG', 'name' => 'VG (Very Good)'],
            ['code' => 'GD', 'name' => 'GD (Good)'],
            ['code' => 'FR', 'name' => 'FR (Fair)'],
            ['code' => 'PR', 'name' => 'PR (Poor)']
        ],
        'fluorescence' => [
            ['code' => 'NONE', 'name' => 'None (નિલ)'],
            ['code' => 'FAINT', 'name' => 'Faint (હલકું)'],
            ['code' => 'MEDIUM', 'name' => 'Medium (મધ્યમ)'],
            ['code' => 'STRONG', 'name' => 'Strong (મજબૂત)'],
            ['code' => 'VERY STRONG', 'name' => 'Very Strong (ખૂબ મજબૂત)']
        ],
        'key_symbols' => [
            ['code' => 'Feather', 'name' => 'Feather (પીંછું / કાપો)'],
            ['code' => 'Crystal', 'name' => 'Crystal (ક્રિસ્ટલ)'],
            ['code' => 'Cloud', 'name' => 'Cloud (વાદળી)'],
            ['code' => 'Pinpoint', 'name' => 'Pinpoint (ટપકું)'],
            ['code' => 'Needle', 'name' => 'Needle (સોય જેવું)'],
            ['code' => 'Cavity', 'name' => 'Cavity (ખાડો)'],
            ['code' => 'Knot', 'name' => 'Knot (ગાંઠ)'],
            ['code' => 'Twinning Wisp', 'name' => 'Twinning Wisp (રેશમી લાઇન)'],
            ['code' => 'Chip', 'name' => 'Chip (કચરો / તૂટેલું)'],
            ['code' => 'Natural', 'name' => 'Natural (કુદરતી સપાટી)']
        ]
    ];

    $list = $defaults[$attributeType] ?? [];
    $formatted = [];
    foreach ($list as $idx => $item) {
        $formatted[] = [
            'id' => $idx + 1,
            'attribute_type' => $attributeType,
            'code' => $item['code'],
            'name' => $item['name'],
            'sort_order' => $idx + 1,
            'status' => 'active'
        ];
    }
    return $formatted;
}

/**
 * Get Master Color Tags (GIA or Parcel Polish)
 */
function get_master_color_tags(string $category = 'gia', bool $activeOnly = true): array {
    $pdo = getDbConnection();
    $params = [$category];
    $sql = "SELECT t.*,
                   (SELECT COUNT(*) FROM master_color_descriptions d WHERE d.color_tag_id = t.id" .
                   ($activeOnly ? " AND d.status = 'active'" : "") . ") as description_count
            FROM master_color_tags t
            WHERE t.category = ?";
    if ($activeOnly) {
        $sql .= " AND t.status = 'active'";
    }
    $sql .= " ORDER BY t.sort_order ASC, t.tag_name ASC";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Get Color Descriptions for a specific Color Tag
 */
function get_master_color_descriptions(int $colorTagId, string $category = 'gia', bool $activeOnly = true): array {
    $pdo = getDbConnection();
    $params = [$colorTagId, $category];
    $sql = "SELECT * FROM master_color_descriptions WHERE color_tag_id = ? AND category = ?";
    if ($activeOnly) {
        $sql .= " AND status = 'active'";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Get Full Hierarchy of Color Tags and their Descriptions (as nested array)
 */
function get_all_color_tags_with_descriptions(string $category = 'gia', bool $activeOnly = true): array {
    $tags = get_master_color_tags($category, $activeOnly);
    $result = [];
    foreach ($tags as $tag) {
        $tagId = (int)$tag['id'];
        $tag['descriptions'] = get_master_color_descriptions($tagId, $category, $activeOnly);
        $result[] = $tag;
    }
    return $result;
}

/**
 * Fetch Master Polish Parcel Clarities
 */
function get_master_parcel_clarities(bool $activeOnly = true): array {
    $pdo = getDbConnection();
    try {
        $sql = "SELECT * FROM master_parcel_clarities";
        if ($activeOnly) {
            $sql .= " WHERE status = 'active'";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [
            ['id' => 1, 'name' => 'Clean', 'code' => 'CLN', 'status' => 'active', 'sort_order' => 1],
            ['id' => 2, 'name' => 'Eye Clean', 'code' => 'ECLN', 'status' => 'active', 'sort_order' => 2],
            ['id' => 3, 'name' => 'VS', 'code' => 'VS', 'status' => 'active', 'sort_order' => 3],
            ['id' => 4, 'name' => 'SI', 'code' => 'SI', 'status' => 'active', 'sort_order' => 4],
            ['id' => 5, 'name' => 'Pique', 'code' => 'PIQ', 'status' => 'active', 'sort_order' => 5],
            ['id' => 6, 'name' => 'Heavy Pique', 'code' => 'HPIQ', 'status' => 'active', 'sort_order' => 6],
            ['id' => 7, 'name' => 'Rejection', 'code' => 'REJ', 'status' => 'active', 'sort_order' => 7],
        ];
    }
}

function get_all_master_parcel_clarities(): array {
    return get_master_parcel_clarities(false);
}

/**
 * ─────────────────────────────────────────────────────────────────
 * Core Financial Period & Date Filter Helper
 * Supports: 'fy' (Current Financial Year), 'month', 'today', 'custom' (from_date & to_date), 'all' (All History)
 * ─────────────────────────────────────────────────────────────────
 */
function get_filter_period_dates(?string $period = 'fy', ?string $customFrom = null, ?string $customTo = null): array {
    $now = new DateTime();
    $curMonth = (int)$now->format('n');
    $curYear  = (int)$now->format('Y');

    $fyStartYear = ($curMonth >= 4) ? $curYear : ($curYear - 1);
    $fyEndYear   = $fyStartYear + 1;
    $fyStart     = sprintf('%04d-04-01', $fyStartYear);
    $fyEnd       = sprintf('%04d-03-31', $fyEndYear);
    $fyLabel     = sprintf('%d-%02d', $fyStartYear, $fyEndYear % 100);

    $period = trim((string)($period ?? 'fy'));

    // Check if custom dates provided via query string
    if (!empty($customFrom) && !empty($customTo)) {
        $tFrom = strtotime($customFrom);
        $tTo   = strtotime($customTo);
        if ($tFrom !== false && $tTo !== false && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo)) {
            $period = 'custom';
            $from = $customFrom;
            $to   = $customTo;
            $label = date('d-m-Y', $tFrom) . ' થી ' . date('d-m-Y', $tTo);
        } else {
            $period = 'fy';
            $from = $fyStart;
            $to   = $fyEnd;
            $label = 'FY ' . $fyLabel . ' (ચાલુ વર્ષ)';
        }
    } elseif ($period === 'today') {
        $from = $now->format('Y-m-d');
        $to   = $now->format('Y-m-d');
        $label = 'આજે (' . $now->format('d-m-Y') . ')';
    } elseif ($period === 'month') {
        $from = $now->format('Y-m-01');
        $to   = $now->format('Y-m-t');
        $label = 'આ મહિનો (' . $now->format('M Y') . ')';
    } elseif ($period === 'all') {
        $from = null;
        $to   = null;
        $label = 'સમગ્ર ઇતિહાસ (All Time)';
    } else {
        $period = 'fy';
        $from = $fyStart;
        $to   = $fyEnd;
        $label = 'FY ' . $fyLabel . ' (ચાલુ વર્ષ)';
    }

    return [
        'period'    => $period,
        'from_date' => $from,
        'to_date'   => $to,
        'label'     => $label,
        'fy_label'  => $fyLabel,
        'fy_start'  => $fyStart,
        'fy_end'    => $fyEnd
    ];
}

/**
 * ─────────────────────────────────────────────────────────────────
 * 9 Pillars: Live Trade & Stock Metrics
 * 1. Rough Purchase, 2. Rough Sale, 3. Rough Stock
 * 4. GIA Purchase, 5. GIA Sale, 6. GIA Stock
 * 7. Parcel Purchase, 8. Parcel Sale, 9. Parcel Stock
 * ─────────────────────────────────────────────────────────────────
 */
function get_trade_and_stock_9_pillars(PDO $pdo, array $pInfo): array {
    $from = $pInfo['from_date'] ?? null;
    $to   = $pInfo['to_date'] ?? null;

    // 1. ROUGH KHARIDI (Excluding merged lots to prevent double count)
    $rpSql = "SELECT COUNT(*) as bills, COALESCE(SUM(pcs),0) as pcs, COALESCE(SUM(weight),0) as weight, COALESCE(SUM(net_payment),0) as total_amount FROM rough_purchases WHERE status != 'merged'";
    $rpParams = [];
    if ($from && $to) {
        $rpSql .= " AND purchase_date BETWEEN ? AND ?";
        $rpParams = [$from, $to];
    }
    $stmt = $pdo->prepare($rpSql);
    $stmt->execute($rpParams);
    $roughBuy = $stmt->fetch(PDO::FETCH_ASSOC);
    $roughBuy['avg_rate'] = ($roughBuy['weight'] > 0) ? round((float)$roughBuy['total_amount'] / (float)$roughBuy['weight'], 2) : 0;

    // 2. ROUGH VECHAN
    $rsSql = "SELECT COUNT(*) as bills, COALESCE(SUM(pcs),0) as pcs, COALESCE(SUM(weight),0) as weight, COALESCE(SUM(net_amount),0) as total_amount FROM rough_sales WHERE 1=1";
    $rsParams = [];
    if ($from && $to) {
        $rsSql .= " AND sale_date BETWEEN ? AND ?";
        $rsParams = [$from, $to];
    }
    $stmt = $pdo->prepare($rsSql);
    $stmt->execute($rsParams);
    $roughSale = $stmt->fetch(PDO::FETCH_ASSOC);
    $roughSale['avg_rate'] = ($roughSale['weight'] > 0) ? round((float)$roughSale['total_amount'] / (float)$roughSale['weight'], 2) : 0;

    // 3. ROUGH STOCK (Live Inventory)
    $rstkSql = "SELECT COUNT(*) as total_lots, COALESCE(SUM(available_pcs),0) as pcs, COALESCE(SUM(available_weight),0) as weight, COALESCE(SUM(available_weight * price_per_carat_inr),0) as total_val FROM rough_purchases WHERE (available_weight > 0 OR status IN ('in_stock', 'pending'))";
    $roughStock = $pdo->query($rstkSql)->fetch(PDO::FETCH_ASSOC);
    $roughStock['avg_rate'] = ($roughStock['weight'] > 0) ? round((float)$roughStock['total_val'] / (float)$roughStock['weight'], 2) : 0;

    // 4. GIA POLISH KHARIDI
    $gpSql = "SELECT COUNT(*) as bills, COALESCE(SUM(pcs),0) as pcs, COALESCE(SUM(weight),0) as weight, COALESCE(SUM(net_payment),0) as total_amount FROM polish_purchases WHERE (stock_type = 'gia_single' OR (gia_number IS NOT NULL AND gia_number != ''))";
    $gpParams = [];
    if ($from && $to) {
        $gpSql .= " AND purchase_date BETWEEN ? AND ?";
        $gpParams = [$from, $to];
    }
    $stmt = $pdo->prepare($gpSql);
    $stmt->execute($gpParams);
    $giaBuy = $stmt->fetch(PDO::FETCH_ASSOC);
    $giaBuy['avg_rate'] = ($giaBuy['weight'] > 0) ? round((float)$giaBuy['total_amount'] / (float)$giaBuy['weight'], 2) : 0;

    // 5. GIA POLISH VECHAN
    $gsSql = "SELECT COUNT(ps.id) as bills, COALESCE(SUM(ps.pcs),0) as pcs, COALESCE(SUM(ps.weight),0) as weight, COALESCE(SUM(ps.net_amount),0) as total_amount 
              FROM polish_sales ps 
              JOIN polish_stocks stk ON ps.polish_stock_id = stk.id 
              WHERE (stk.stock_type = 'gia_single' OR (stk.gia_number IS NOT NULL AND stk.gia_number != ''))";
    $gsParams = [];
    if ($from && $to) {
        $gsSql .= " AND ps.sale_date BETWEEN ? AND ?";
        $gsParams = [$from, $to];
    }
    $stmt = $pdo->prepare($gsSql);
    $stmt->execute($gsParams);
    $giaSale = $stmt->fetch(PDO::FETCH_ASSOC);
    $giaSale['avg_rate'] = ($giaSale['weight'] > 0) ? round((float)$giaSale['total_amount'] / (float)$giaSale['weight'], 2) : 0;

    // 6. GIA POLISH STOCK (Live Inventory)
    $gstkSql = "SELECT COUNT(*) as total_lots, COALESCE(SUM(available_pcs),0) as pcs, COALESCE(SUM(available_weight),0) as weight, COALESCE(SUM(available_weight * sale_price_per_carat),0) as total_val 
                FROM polish_stocks 
                WHERE status IN ('available', 'partially_on_jangad', 'in_stock') AND available_weight > 0 
                  AND (stock_type = 'gia_single' OR (gia_number IS NOT NULL AND gia_number != ''))";
    $giaStock = $pdo->query($gstkSql)->fetch(PDO::FETCH_ASSOC);
    $giaStock['avg_rate'] = ($giaStock['weight'] > 0) ? round((float)$giaStock['total_val'] / (float)$giaStock['weight'], 2) : 0;

    // 7. PARCEL POLISH KHARIDI
    $ppSql = "SELECT COUNT(*) as bills, COALESCE(SUM(pcs),0) as pcs, COALESCE(SUM(weight),0) as weight, COALESCE(SUM(net_payment),0) as total_amount 
              FROM polish_purchases 
              WHERE (stock_type = 'parcel' OR (stock_type != 'gia_single' AND (gia_number IS NULL OR gia_number = '')))";
    $ppParams = [];
    if ($from && $to) {
        $ppSql .= " AND purchase_date BETWEEN ? AND ?";
        $ppParams = [$from, $to];
    }
    $stmt = $pdo->prepare($ppSql);
    $stmt->execute($ppParams);
    $parcelBuy = $stmt->fetch(PDO::FETCH_ASSOC);
    $parcelBuy['avg_rate'] = ($parcelBuy['weight'] > 0) ? round((float)$parcelBuy['total_amount'] / (float)$parcelBuy['weight'], 2) : 0;

    // 8. PARCEL POLISH VECHAN
    $psSql = "SELECT COUNT(ps.id) as bills, COALESCE(SUM(ps.pcs),0) as pcs, COALESCE(SUM(ps.weight),0) as weight, COALESCE(SUM(ps.net_amount),0) as total_amount 
              FROM polish_sales ps 
              JOIN polish_stocks stk ON ps.polish_stock_id = stk.id 
              WHERE (stk.stock_type = 'parcel' OR (stk.stock_type != 'gia_single' AND (stk.gia_number IS NULL OR stk.gia_number = '')))";
    $psParams = [];
    if ($from && $to) {
        $psSql .= " AND ps.sale_date BETWEEN ? AND ?";
        $psParams = [$from, $to];
    }
    $stmt = $pdo->prepare($psSql);
    $stmt->execute($psParams);
    $parcelSale = $stmt->fetch(PDO::FETCH_ASSOC);
    $parcelSale['avg_rate'] = ($parcelSale['weight'] > 0) ? round((float)$parcelSale['total_amount'] / (float)$parcelSale['weight'], 2) : 0;

    // 9. PARCEL POLISH STOCK (Live Inventory)
    $pstkSql = "SELECT COUNT(*) as total_lots, COALESCE(SUM(available_pcs),0) as pcs, COALESCE(SUM(available_weight),0) as weight, COALESCE(SUM(available_weight * sale_price_per_carat),0) as total_val 
                FROM polish_stocks 
                WHERE status IN ('available', 'partially_on_jangad', 'in_stock') AND available_weight > 0 
                  AND (stock_type != 'gia_single' AND (gia_number IS NULL OR gia_number = ''))";
    $parcelStock = $pdo->query($pstkSql)->fetch(PDO::FETCH_ASSOC);
    $parcelStock['avg_rate'] = ($parcelStock['weight'] > 0) ? round((float)$parcelStock['total_val'] / (float)$parcelStock['weight'], 2) : 0;

    return [
        'rough_buy'    => $roughBuy,
        'rough_sale'   => $roughSale,
        'rough_stock'  => $roughStock,
        'gia_buy'      => $giaBuy,
        'gia_sale'     => $giaSale,
        'gia_stock'    => $giaStock,
        'parcel_buy'   => $parcelBuy,
        'parcel_sale'  => $parcelSale,
        'parcel_stock' => $parcelStock,
    ];
}

/**
 * Render standard period switcher with presets and custom date range
 */
function render_period_filter_toolbar(string $currentPeriod, ?string $fromDate, ?string $toDate, string $baseUrl, array $extraParams = []): string {
    $buildUrl = function(string $p, ?string $f = null, ?string $t = null) use ($baseUrl, $extraParams): string {
        $params = array_merge($extraParams, ['period' => $p]);
        if ($p === 'custom' && $f && $t) {
            $params['from_date'] = $f;
            $params['to_date'] = $t;
        } else {
            unset($params['from_date'], $params['to_date']);
        }
        return $baseUrl . '?' . http_build_query($params);
    };

    $info = get_filter_period_dates($currentPeriod, $fromDate, $toDate);
    $p = $info['period'];
    $fromVal = e($fromDate ?? date('Y-m-01'));
    $toVal = e($toDate ?? date('Y-m-d'));

    ob_start();
    ?>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="btn-group btn-group-sm border rounded-3 p-0.5 bg-white shadow-xs" role="group">
            <a href="<?= $buildUrl('fy') ?>" class="btn <?= $p === 'fy' ? 'btn-primary fw-bold' : 'btn-light text-muted' ?> py-1 px-2.5" title="ચાલુ નાણાકીય વર્ષ">
                <?= svg_icon('calendar-days', 'me-1', 12) ?>FY <?= e($info['fy_label']) ?>
            </a>
            <a href="<?= $buildUrl('month') ?>" class="btn <?= $p === 'month' ? 'btn-primary fw-bold' : 'btn-light text-muted' ?> py-1 px-2.5" title="ચાલુ મહિનો">
                આ મહિનો
            </a>
            <a href="<?= $buildUrl('today') ?>" class="btn <?= $p === 'today' ? 'btn-primary fw-bold' : 'btn-light text-muted' ?> py-1 px-2.5" title="આજની તારીખ">
                આજે
            </a>
            <a href="<?= $buildUrl('all') ?>" class="btn <?= $p === 'all' ? 'btn-dark fw-bold' : 'btn-light text-muted' ?> py-1 px-2.5" title="સમગ્ર ઇતિહાસ">
                <?= svg_icon('clock-rotate-left', 'me-1', 12) ?>સંપૂર્ણ હિસ્ટ્રી
            </a>
        </div>

        <!-- Custom Date Range Form Inline -->
        <form method="GET" action="<?= e($baseUrl) ?>" class="d-flex align-items-center gap-1.5 p-0.5 bg-white border rounded-3 shadow-xs">
            <?php foreach ($extraParams as $k => $v): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="period" value="custom">
            <span class="small text-muted fw-bold ps-1.5 d-none d-sm-inline" style="font-size: 0.78rem;"><?= svg_icon('calendar', 'text-primary me-1', 12) ?>તારીખ:</span>
            <input type="date" name="from_date" class="form-control form-control-sm font-mono border-0 bg-light py-0.5 px-1.5" style="width: 120px; font-size: 0.79rem; height: 26px; min-height: 26px;" value="<?= $fromVal ?>" required>
            <span class="text-muted small" style="font-size: 0.76rem;">થી</span>
            <input type="date" name="to_date" class="form-control form-control-sm font-mono border-0 bg-light py-0.5 px-1.5" style="width: 120px; font-size: 0.79rem; height: 26px; min-height: 26px;" value="<?= $toVal ?>" required>
            <button type="submit" class="btn btn-sm btn-outline-primary py-0.5 px-2 fw-bold" style="font-size: 0.76rem; height: 26px; min-height: 26px; line-height: 1;" title="તારીખ મુજબ શોધો">લાગુ કરો</button>
            <?php if ($p === 'custom'): ?>
                <a href="<?= $buildUrl('fy') ?>" class="btn btn-sm btn-outline-danger py-0.5 px-1.5 d-flex align-items-center justify-content-center" style="height: 26px; min-height: 26px;" title="રીસેટ"><?= svg_icon('xmark', '', 12) ?></a>
            <?php endif; ?>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Enterprise Server-Side Pagination Data Calculator
 */
function get_pagination_data(int $totalRecords, int $defaultPerPage = 25): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = (int)($_GET['per_page'] ?? $defaultPerPage);
    if (!in_array($perPage, [25, 50, 100], true)) {
        $perPage = $defaultPerPage;
    }
    $totalPages = max(1, (int)ceil($totalRecords / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $from = $totalRecords > 0 ? $offset + 1 : 0;
    $to = min($totalRecords, $offset + $perPage);

    return [
        'page'          => $page,
        'per_page'      => $perPage,
        'total_records' => $totalRecords,
        'total_pages'   => $totalPages,
        'offset'        => $offset,
        'limit'         => $perPage,
        'from'          => $from,
        'to'            => $to
    ];
}

/**
 * Render Enterprise Table Pagination Toolbar
 * Supports 25, 50, 100 records per page and sliding page numbers
 */
function render_table_pagination(array $pag, string $baseUrl, array $extraParams = []): string {
    $total = (int)($pag['total_records'] ?? 0);
    if ($total <= 0) {
        return '';
    }

    $page = (int)($pag['page'] ?? 1);
    $perPage = (int)($pag['per_page'] ?? 25);
    $totalPages = (int)($pag['total_pages'] ?? 1);
    $from = (int)($pag['from'] ?? 1);
    $to = (int)($pag['to'] ?? $total);

    $buildUrl = function(int $targetPage, ?int $targetPerPage = null) use ($baseUrl, $extraParams, $perPage): string {
        $params = array_merge($extraParams, [
            'page'     => $targetPage,
            'per_page' => $targetPerPage ?? $perPage
        ]);
        return $baseUrl . '?' . http_build_query($params);
    };

    ob_start();
    ?>
    <div class="erp-pagination-bar d-flex flex-wrap align-items-center justify-content-between gap-2.5 px-3 py-2.5 bg-white border-top">
        <div class="d-flex align-items-center gap-2 small text-muted font-mono">
            <span>કુલ <strong><?= number_format($total) ?></strong> માંથી <strong><?= number_format($from) ?> થી <?= number_format($to) ?></strong></span>
            <div class="vr mx-1 d-none d-sm-inline"></div>
            <div class="d-inline-flex align-items-center gap-1.5">
                <label for="erp_per_page_select" class="d-none d-sm-inline">પ્રતિ પેજ:</label>
                <select id="erp_per_page_select" class="form-select form-select-sm py-0.5 px-2 font-mono" style="width: auto; height: 28px; min-height: 28px; font-size: 0.78rem;" onchange="window.location.href=this.value;">
                    <?php foreach ([25, 50, 100] as $opt): ?>
                        <option value="<?= e($buildUrl(1, $opt)) ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav aria-label="Page Navigation">
                <ul class="pagination pagination-sm mb-0 gap-1 align-items-center">
                    <!-- First & Prev -->
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link rounded-2 py-1 px-2 font-mono" href="<?= $buildUrl(1) ?>" title="પ્રથમ પેજ">
                                <?= svg_icon('angles-left', '', 11) ?>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link rounded-2 py-1 px-2 font-mono" href="<?= $buildUrl($page - 1) ?>" title="અગાઉ">
                                <?= svg_icon('chevron-left', '', 11) ?>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled"><span class="page-link rounded-2 py-1 px-2 font-mono"><?= svg_icon('angles-left', '', 11) ?></span></li>
                        <li class="page-item disabled"><span class="page-link rounded-2 py-1 px-2 font-mono"><?= svg_icon('chevron-left', '', 11) ?></span></li>
                    <?php endif; ?>

                    <!-- Sliding Pages -->
                    <?php
                    $startP = max(1, $page - 2);
                    $endP = min($totalPages, $page + 2);
                    if ($startP > 1) {
                        echo '<li class="page-item"><a class="page-link rounded-2 py-1 px-2 font-mono" href="' . $buildUrl(1) . '">1</a></li>';
                        if ($startP > 2) echo '<li class="page-item disabled"><span class="page-link rounded-2 py-1 px-1 text-muted">…</span></li>';
                    }
                    for ($pIdx = $startP; $pIdx <= $endP; $pIdx++):
                    ?>
                        <li class="page-item <?= $pIdx === $page ? 'active' : '' ?>">
                            <?php if ($pIdx === $page): ?>
                                <span class="page-link rounded-2 py-1 px-2.5 font-mono fw-bold"><?= $pIdx ?></span>
                            <?php else: ?>
                                <a class="page-link rounded-2 py-1 px-2.5 font-mono" href="<?= $buildUrl($pIdx) ?>"><?= $pIdx ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endfor; ?>
                    <?php
                    if ($endP < $totalPages) {
                        if ($endP < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link rounded-2 py-1 px-1 text-muted">…</span></li>';
                        echo '<li class="page-item"><a class="page-link rounded-2 py-1 px-2 font-mono" href="' . $buildUrl($totalPages) . '">' . $totalPages . '</a></li>';
                    }
                    ?>

                    <!-- Next & Last -->
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link rounded-2 py-1 px-2 font-mono" href="<?= $buildUrl($page + 1) ?>" title="આગળ">
                                <?= svg_icon('chevron-right', '', 11) ?>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link rounded-2 py-1 px-2 font-mono" href="<?= $buildUrl($totalPages) ?>" title="છેલ્લું પેજ">
                                <?= svg_icon('angles-right', '', 11) ?>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled"><span class="page-link rounded-2 py-1 px-2 font-mono"><?= svg_icon('chevron-right', '', 11) ?></span></li>
                        <li class="page-item disabled"><span class="page-link rounded-2 py-1 px-2 font-mono"><?= svg_icon('angles-right', '', 11) ?></span></li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Universal Breadcrumbs Generator
 * Parses URL / Page Title and renders accessible breadcrumbs
 */
function render_breadcrumbs(?string $customTitle = null): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/modules/dashboard/') !== false || $uri === '/' || $uri === '/index.php') {
        return '';
    }

    $crumbs = [
        ['label' => 'Dashboard', 'url' => BASE_URL . '/modules/dashboard/index.php', 'icon' => 'home']
    ];

    // Module Mapping
    if (strpos($uri, '/modules/rough/') !== false || strpos($uri, '/modules/rough_purchase/') !== false || strpos($uri, '/modules/rough_sales/') !== false) {
        $crumbs[] = ['label' => 'Rough (રફ)', 'url' => BASE_URL . '/modules/rough/index.php'];
        if (strpos($uri, '/modules/rough_purchase/create.php') !== false) {
            $crumbs[] = ['label' => 'નવી ખરીદી', 'url' => null];
        } elseif (strpos($uri, '/modules/rough_purchase/view.php') !== false) {
            $crumbs[] = ['label' => 'ખરીદી વિગત', 'url' => null];
        } elseif (strpos($uri, '/modules/rough_sales/create.php') !== false) {
            $crumbs[] = ['label' => 'નવું વેચાણ', 'url' => null];
        } elseif (strpos($uri, '/modules/rough_sales/view.php') !== false) {
            $crumbs[] = ['label' => 'વેચાણ હિસ્ટ્રી', 'url' => null];
        }
    } elseif (strpos($uri, '/modules/polish/') !== false || strpos($uri, '/modules/polish_purchase/') !== false || strpos($uri, '/modules/polish_sales/') !== false) {
        $crumbs[] = ['label' => 'Polish (પોલિશ)', 'url' => BASE_URL . '/modules/polish/index.php'];
        if (strpos($uri, '/modules/polish_purchase/create.php') !== false) {
            $crumbs[] = ['label' => 'નવી પોલિશ ખરીદી', 'url' => null];
        } elseif (strpos($uri, '/modules/polish_sales/create.php') !== false) {
            $crumbs[] = ['label' => 'નવું પોલિશ વેચાણ', 'url' => null];
        }
    } elseif (strpos($uri, '/modules/assort/') !== false) {
        $crumbs[] = ['label' => 'Assort (એસોર્ટ)', 'url' => BASE_URL . '/modules/assort/index.php'];
        if (strpos($uri, '/modules/assort/create.php') !== false) {
            $crumbs[] = ['label' => 'નવો એસોર્ટ ટાસ્ક', 'url' => null];
        } elseif (strpos($uri, '/modules/assort/view.php') !== false) {
            $crumbs[] = ['label' => 'એસોર્ટ વિગત', 'url' => null];
        }
    } elseif (strpos($uri, '/modules/kapan/') !== false) {
        $crumbs[] = ['label' => 'Kapan (કાપન)', 'url' => BASE_URL . '/modules/kapan/index.php'];
    } elseif (strpos($uri, '/modules/process/') !== false) {
        $crumbs[] = ['label' => 'Process (જોબવર્ક)', 'url' => BASE_URL . '/modules/process/jobwork_stock.php'];
        if (strpos($uri, 'barcode_scanner.php') !== false) {
            $crumbs[] = ['label' => 'બારકોડ સ્કેનર', 'url' => null];
        }
    } elseif (strpos($uri, '/modules/boil/') !== false) {
        $crumbs[] = ['label' => 'Boiling (બોઇલિંગ)', 'url' => BASE_URL . '/modules/boil/index.php'];
    } elseif (strpos($uri, '/modules/jangad/') !== false) {
        $crumbs[] = ['label' => 'Jangad Memo (જાંગડ)', 'url' => BASE_URL . '/modules/jangad/index.php'];
        if (strpos($uri, '/create.php') !== false) $crumbs[] = ['label' => 'નવું જાંગડ', 'url' => null];
        elseif (strpos($uri, '/return.php') !== false) $crumbs[] = ['label' => 'માલ જમા / Return', 'url' => null];
    } elseif (strpos($uri, '/modules/parties/') !== false) {
        $type = $_GET['type'] ?? 'party';
        $crumbs[] = ['label' => $type === 'broker' ? 'દલાલ (Brokers)' : ($type === 'outsource' ? 'આઉટસોર્સ' : 'પાર્ટી (Parties)'), 'url' => BASE_URL . '/modules/parties/index.php?type=' . urlencode($type)];
        if (strpos($uri, 'view.php') !== false) $crumbs[] = ['label' => 'ખાતાવહી & પ્રોફાઇલ', 'url' => null];
        elseif (strpos($uri, 'create.php') !== false) $crumbs[] = ['label' => 'નવો રેકોર્ડ ઉમેરો', 'url' => null];
    } elseif (strpos($uri, '/modules/finance/') !== false) {
        $crumbs[] = ['label' => 'Finance (નાણાં)', 'url' => BASE_URL . '/modules/finance/transactions.php'];
        if (strpos($uri, 'networth.php') !== false) $crumbs[] = ['label' => 'નેટવર્થ બેલેન્સ શીટ', 'url' => null];
        elseif (strpos($uri, 'accounts.php') !== false) $crumbs[] = ['label' => 'બેંક & રોકડ ખાતા', 'url' => null];
        elseif (strpos($uri, 'schedule.php') !== false) $crumbs[] = ['label' => 'ચુકવણી મુદત શેડ્યૂલ', 'url' => null];
        elseif (strpos($uri, 'brokerage.php') !== false) $crumbs[] = ['label' => 'દલાલી હિસાબ', 'url' => null];
    } elseif (strpos($uri, '/modules/reports/') !== false) {
        $crumbs[] = ['label' => 'Reports (રિપોર્ટ્સ)', 'url' => BASE_URL . '/modules/reports/summary.php'];
    } elseif (strpos($uri, '/modules/admin/') !== false) {
        if (strpos($uri, 'users.php') !== false) {
            $crumbs[] = ['label' => 'ટીમ મેમ્બર્સ', 'url' => null];
        } else {
            $crumbs[] = ['label' => 'સેટિંગ્સ', 'url' => BASE_URL . '/modules/admin/settings.php'];
        }
    }

    $lastIdx = count($crumbs) - 1;
    ob_start();
    ?>
    <nav aria-label="breadcrumb" class="erp-breadcrumb-bar mb-2.5 d-print-none">
        <ol class="breadcrumb mb-0 py-1 px-2.5 rounded-3 bg-white border shadow-2xs small font-mono">
            <?php foreach ($crumbs as $i => $c): ?>
                <?php if ($i === $lastIdx || empty($c['url'])): ?>
                    <li class="breadcrumb-item active text-dark fw-bold d-inline-flex align-items-center gap-1" aria-current="page">
                        <?php if (!empty($c['icon'])): ?><?= svg_icon($c['icon'], 'text-muted me-1', 12) ?><?php endif; ?>
                        <span><?= e($c['label']) ?></span>
                    </li>
                <?php else: ?>
                    <li class="breadcrumb-item d-inline-flex align-items-center gap-1">
                        <a href="<?= e($c['url']) ?>" class="text-decoration-none text-muted hover-primary">
                            <?php if (!empty($c['icon'])): ?><?= svg_icon($c['icon'], 'me-1', 12) ?><?php endif; ?>
                            <span><?= e($c['label']) ?></span>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ol>
    </nav>
    <?php
    return ob_get_clean();
}








