<?php
/**
 * Finora Minimalist Top Header Template
 * Diamond ERP - 100% Crisp SVG Icons
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/helpers.php';

require_login();
$user = current_user();
$companyBalance = get_total_company_balance();

$pdo = getDbConnection();
$dueAlertCount = (int)$pdo->query("SELECT 
    (SELECT COUNT(*) FROM rough_purchases WHERE party_payment_status != 'paid' AND due_date <= DATE_ADD(CURDATE(), INTERVAL 5 DAY)) +
    (SELECT COUNT(*) FROM polish_purchases WHERE payment_status != 'paid' AND due_date <= DATE_ADD(CURDATE(), INTERVAL 5 DAY)) AS total
")->fetchColumn();

$initials = strtoupper(substr($user['full_name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' | ' : '' ?><?= APP_NAME ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&family=Noto+Sans+Gujarati:wght@400;500;600;700&display=swap">

    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/tom-select/tom-select.bootstrap5.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/diamond.css?v=<?= filemtime(__DIR__ . '/../assets/css/diamond.css') ?>">
    <script>
        window.BASE_URL = '<?= BASE_URL ?>';
        window.escapeHtml = function(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        };
        var escapeHtml = window.escapeHtml;
    </script>
</head>
<body>

<div class="d-flex" id="wrapper">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div id="page-content-wrapper" class="d-flex flex-column min-vh-100 flex-grow-1">
        <nav class="top-navbar d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-2.5">
                <button class="btn-icon-circle border-0" id="sidebarToggle" type="button" aria-label="Toggle navigation">
                    <?= svg_icon('bars-staggered', '', 18) ?>
                </button>

                <!-- Mobile Visible Search Trigger -->
                <button class="btn-icon-circle border-0 d-md-none" type="button" aria-label="Search" onclick="openGlobalCommandPalette()" title="શોધો (Ctrl+K)">
                    <?= svg_icon('search', '', 17) ?>
                </button>

                <div class="header-search-box d-none d-md-flex align-items-center cursor-pointer" onclick="openGlobalCommandPalette()" style="cursor: pointer;">
                    <span class="search-icon"><?= svg_icon('search', '', 16) ?></span>
                    <input type="text" class="header-search-input" placeholder="Search anything..." id="globalSearchInput" readonly style="cursor: pointer;">
                    <span class="badge bg-light text-muted border px-1.5 py-0.5 font-mono" style="font-size: 0.68rem; border-radius: 5px;">Ctrl K</span>
                </div>

                <!-- Financial Year Context -->
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle py-1.5 px-2.5 rounded-pill font-mono small d-none d-xl-inline-flex align-items-center gap-1.5 ms-2" title="સક્રિય નાણાકીય વર્ષ (Active Financial Year)">
                    <?= svg_icon('calendar-days', '', 12) ?>FY <?= (date('m') >= 4 ? date('Y') . '-' . (date('y') + 1) : (date('Y') - 1) . '-' . date('y')) ?>
                </span>
            </div>

            <div class="d-flex align-items-center gap-2.5">
                <button class="btn-icon-circle border-0 text-decoration-none" id="densityToggleBtn" type="button" title="ટેબલ ડેન્સિટી (Compact / Comfortable Toggle)" onclick="toggleTableDensity()">
                    <?= svg_icon('table-cells-large', '', 17) ?>
                </button>

                <button class="btn-icon-circle border-0 text-decoration-none" id="quickCalcBtn" type="button" title="ઝડપી કેલ્ક્યુલેટર (Alt + C)" onclick="openCalculatorModal('')">
                    <?= svg_icon('calculator', '', 17) ?>
                </button>

                <a href="<?= BASE_URL ?>/modules/process/barcode_scanner.php" class="btn-icon-circle text-decoration-none" title="બારકોડ સ્કેન">
                    <?= svg_icon('barcode', '', 18) ?>
                </a>

                <a href="<?= BASE_URL ?>/modules/finance/schedule.php" class="btn-icon-circle position-relative text-decoration-none" title="ચુકવણી મુદત એલર્ટ">
                    <?= svg_icon('bell', '', 18) ?>
                    <?php if ($dueAlertCount > 0): ?>
                        <span class="finora-dot-alert"></span>
                    <?php endif; ?>
                </a>

                <div class="dropdown">
                    <button class="user-profile-btn border-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="user-avatar-circle"><?= e($initials) ?></div>
                        <?= svg_icon('chevron-down', 'text-muted', 14) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2 p-2" style="border-radius: 16px; min-width: 220px;">
                        <li>
                            <div class="px-3 py-2 border-bottom mb-1">
                                <div class="fw-bold text-dark"><?= e($user['full_name']) ?></div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <small class="text-muted"><?= e(ucfirst($user['role'])) ?></small>
                                    <strong class="font-mono text-success small"><?= format_inr($companyBalance) ?></strong>
                                </div>
                            </div>
                        </li>
                        <li><a class="dropdown-item rounded-2 py-2 d-flex align-items-center gap-2" href="<?= BASE_URL ?>/modules/parties/index.php"><?= svg_icon('users', 'text-primary', 16) ?>પાર્ટી લિસ્ટ</a></li>
                        <li><a class="dropdown-item rounded-2 py-2 d-flex align-items-center gap-2" href="<?= BASE_URL ?>/modules/reports/index.php"><?= svg_icon('chart-pie', 'text-primary', 16) ?>રિપોર્ટ્સ</a></li>
                        <li><a class="dropdown-item rounded-2 py-2 d-flex align-items-center gap-2" href="<?= BASE_URL ?>/modules/admin/backup.php"><?= svg_icon('cloud-arrow-down', 'text-primary', 16) ?>ડેટા બેકઅપ</a></li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li><a class="dropdown-item rounded-2 py-2 text-danger d-flex align-items-center gap-2" href="<?= BASE_URL ?>/logout.php"><?= svg_icon('right-from-bracket', '', 16) ?>લૉગ આઉટ</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <main class="flex-grow-1 main-content">
            <?= display_flash() ?>
            <?= render_breadcrumbs() ?>
