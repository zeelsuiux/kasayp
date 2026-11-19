<?php
/**
 * Central Reports Catalog (Finora Minimalist)
 * Diamond ERP - Pure SVG Icons & Important Titles Only
 */

declare(strict_types=1);

$pageTitle = 'રિપોર્ટ્સ';
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'રિપોર્ટ્સ અને Excel Export',
    '',
    'chart-pie',
    '<a href="#excel-export-section" class="btn btn-primary">' . svg_icon('file-excel', '', 15) . 'Excel Export</a>'
) ?>

<!-- 3 Pastel Metric Summary Cards -->
<div class="row g-3 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('વેપાર & સ્ટોક સારાંશ', '9 Pillars Hub', 'રફ • GIA • પાર્સલ (તારીખ મુજબ)', '', 'purple', BASE_URL . '/modules/reports/summary.php', '', 'chart-pie') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('Excel Export', '૮ મોડ્યુલ્સ', 'CSV ડાઉનલોડ', '', 'green', '#excel-export-section', '', 'file-excel') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('ઓડિટ લોગ્સ', 'સક્રિય', 'સુરક્ષા ટ્રેઇલ', '', 'amber', BASE_URL . '/modules/admin/activity_logs.php', '', 'shield-halved') ?>
    </div>
</div>

<!-- Central Excel Export Hub -->
<div class="finora-card mb-3" id="excel-export-section">
    <div class="finora-card-header">
        <h6 class="finora-card-title">
            <?= svg_icon('file-excel', 'text-success', 18) ?>
            <span>Excel Export</span>
        </h6>
    </div>
    <div class="p-3">
        <div class="row g-2.5">
            <?php
            $exports = [
                ['type' => 'transactions', 'title' => 'આવક - જાવક રોજમેળ', 'icon' => 'book-bookmark', 'tone' => 'blue'],
                ['type' => 'polish_stock', 'title' => 'Polish Stock', 'icon' => 'gem', 'tone' => 'green'],
                ['type' => 'rough_purchase', 'title' => 'Rough ખરીદી', 'icon' => 'shapes', 'tone' => 'blue'],
                ['type' => 'polish_sales', 'title' => 'Polish વેચાણ', 'icon' => 'hand-holding-dollar', 'tone' => 'amber'],
                ['type' => 'payment_schedule', 'title' => 'ચુકવણી Schedule', 'icon' => 'calendar-days', 'tone' => 'rose'],
                ['type' => 'parties', 'title' => 'પાર્ટી ડિરેક્ટરી', 'icon' => 'address-book', 'tone' => 'blue'],
                ['type' => 'jangad_outstanding', 'title' => 'Jangad બાકી માલ', 'icon' => 'file-invoice', 'tone' => 'amber'],
                ['type' => 'costing', 'title' => 'Kapan Costing', 'icon' => 'calculator', 'tone' => 'green'],
            ];
            foreach ($exports as $ex): ?>
                <div class="col-12 col-sm-6 col-lg-3">
                    <a href="<?= BASE_URL ?>/modules/reports/export.php?type=<?= e($ex['type']) ?>" class="finora-list-item border rounded-4 p-2.5 text-decoration-none">
                        <div class="d-flex align-items-center gap-2.5">
                            <div class="kpi-icon-tile kpi-icon-<?= $ex['tone'] ?>" style="width: 36px; height: 36px; border-radius: 10px;">
                                <?= svg_icon($ex['icon'], '', 16) ?>
                            </div>
                            <span class="fw-semibold text-dark small"><?= e($ex['title']) ?></span>
                        </div>
                        <?= svg_icon('download', 'text-muted', 15) ?>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 5 Clean Report Category Cards -->
<div class="row g-3">
    <?php
    $catalog = [
        [
            'title' => 'ખરીદી - વેચાણ',
            'icon'  => 'cart-shopping',
            'tone'  => 'blue',
            'col'   => 'col-xl-4',
            'items' => [
                ['url' => '/modules/reports/summary.php', 'label' => '9 Pillars વેપાર & સ્ટોક સારાંશ', 'icon' => 'chart-pie'],
                ['url' => '/modules/rough_purchase/index.php', 'label' => 'Rough ખરીદી રજિસ્ટર', 'icon' => 'file-lines'],
                ['url' => '/modules/rough_sales/index.php', 'label' => 'Rough વેચાણ રજિસ્ટર', 'icon' => 'file-invoice'],
                ['url' => '/modules/polish_purchase/index.php', 'label' => 'Polish ખરીદી રજિસ્ટર', 'icon' => 'gem'],
                ['url' => '/modules/polish_sales/index.php', 'label' => 'Polish વેચાણ રજિસ્ટર', 'icon' => 'hand-holding-dollar'],
                ['url' => '/modules/finance/brokerage.php', 'label' => 'દલાલી રિપોર્ટ', 'icon' => 'percent'],
            ]
        ],
        [
            'title' => 'સ્ટોક રિપોર્ટ',
            'icon'  => 'boxes-stacked',
            'tone'  => 'green',
            'col'   => 'col-xl-4',
            'items' => [
                ['url' => '/modules/dashboard/index.php', 'label' => 'Live Stock Summary', 'icon' => 'map-location-dot'],
                ['url' => '/modules/kapan/index.php', 'label' => 'Kapan & Yield %', 'icon' => 'boxes-stacked'],
                ['url' => '/modules/polish_stock/index.php', 'label' => 'Polish Stock', 'icon' => 'gem'],
                ['url' => '/modules/tops/index.php', 'label' => 'Tops / Scrap રિપોર્ટ', 'icon' => 'recycle'],
                ['url' => '/modules/boil/index.php', 'label' => 'Weekly Boil બેચ', 'icon' => 'fire-burner'],
            ]
        ],
        [
            'title' => 'પ્રોસેસ & જાંગડ',
            'icon'  => 'barcode',
            'tone'  => 'amber',
            'col'   => 'col-xl-4',
            'items' => [
                ['url' => '/modules/process/barcode_scanner.php', 'label' => 'બારકોડ ટ્રેકિંગ', 'icon' => 'barcode'],
                ['url' => '/modules/process/index.php', 'label' => 'Kanban પ્રોસેસ બોર્ડ', 'icon' => 'arrows-split-up-and-left'],
                ['url' => '/modules/jangad/outstanding.php', 'label' => 'Jangad બાકી માલ', 'icon' => 'clock-rotate-left'],
                ['url' => '/modules/jangad/index.php', 'label' => 'Jangad વાઉચર્સ', 'icon' => 'file-invoice'],
            ]
        ],
        [
            'title' => 'Costing રિપોર્ટ',
            'icon'  => 'calculator',
            'tone'  => 'blue',
            'col'   => 'col-xl-6',
            'items' => [
                ['url' => '/modules/costing/index.php', 'label' => 'Rough થી Polish ખર્ચ & પડતર', 'icon' => 'receipt'],
                ['url' => '/modules/costing/index.php', 'label' => 'Kapan Yield % સરખામણી', 'icon' => 'chart-line'],
            ]
        ],
        [
            'title' => 'નાણાકીય હિસાબ',
            'icon'  => 'coins',
            'tone'  => 'rose',
            'col'   => 'col-xl-6',
            'items' => [
                ['url' => '/modules/finance/schedule.php?filter=due7', 'label' => 'નજીકની મુદત ચેતવણી', 'icon' => 'bell'],
                ['url' => '/modules/finance/schedule.php', 'label' => 'Payment Schedule', 'icon' => 'calendar-check'],
                ['url' => '/modules/finance/accounts.php', 'label' => 'Bank & Cash લેજર', 'icon' => 'building-columns'],
                ['url' => '/modules/staff_outsource/index.php', 'label' => 'સ્ટાફ પગાર & લેબર', 'icon' => 'users'],
            ]
        ],
    ];
    foreach ($catalog as $cat): ?>
        <div class="col-12 col-md-6 <?= $cat['col'] ?>">
            <div class="finora-card h-100">
                <div class="finora-card-header">
                    <h6 class="finora-card-title">
                        <span class="kpi-icon-tile kpi-icon-<?= $cat['tone'] ?>" style="width: 30px; height: 30px; border-radius: 9px;">
                            <?= svg_icon($cat['icon'], '', 15) ?>
                        </span>
                        <span><?= e($cat['title']) ?></span>
                    </h6>
                </div>
                <div class="p-2.5 d-flex flex-column gap-1.5">
                    <?php foreach ($cat['items'] as $it): ?>
                        <a href="<?= BASE_URL . $it['url'] ?>" class="finora-list-item text-decoration-none">
                            <span class="d-flex align-items-center gap-2 text-dark fw-medium small">
                                <?= svg_icon($it['icon'], 'text-primary', 15) ?>
                                <span><?= e($it['label']) ?></span>
                            </span>
                            <?= svg_icon('chevron-right', 'text-muted', 14) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
