<?php
/**
 * Finora Minimalist Navigation Sidebar
 * Kasyap Diamond ERP - Collapsible 5 Groups & Structured Navigation
 */

declare(strict_types=1);

$currentUri = $_SERVER['REQUEST_URI'] ?? '';

// Active state detection
$isDashboard = (strpos($currentUri, '/modules/dashboard/') !== false);

// Sub-modules detection
$isRoughStock   = (strpos($currentUri, '/modules/rough/') !== false && strpos($currentUri, 'tab=stock') !== false);
$isRoughTrade   = (strpos($currentUri, '/modules/rough/') !== false && !$isRoughStock) || strpos($currentUri, '/modules/rough_purchase/') !== false || strpos($currentUri, '/modules/rough_sales/') !== false;

$isPolishGia    = (strpos($currentUri, '/modules/polish/') !== false && (
                    strpos($currentUri, 'section=gia') !== false ||
                    strpos($currentUri, 'tab=gia_') !== false ||
                    (strpos($currentUri, 'tab=sell_jangad') !== false && strpos($currentUri, 'section=parcel') === false)
                  ))
                  || (strpos($currentUri, '/modules/polish_purchase/') !== false && strpos($currentUri, 'type=parcel') === false)
                  || (strpos($currentUri, '/modules/polish_sales/') !== false && strpos($currentUri, 'type=parcel') === false);

$isPolishParcel = (strpos($currentUri, '/modules/polish/') !== false && (
                    strpos($currentUri, 'section=parcel') !== false ||
                    strpos($currentUri, 'tab=parcel_') !== false
                  ))
                  || (strpos($currentUri, '/modules/polish_purchase/') !== false && strpos($currentUri, 'type=parcel') !== false)
                  || (strpos($currentUri, '/modules/polish_sales/') !== false && strpos($currentUri, 'type=parcel') !== false);

$isPolishStock  = (strpos($currentUri, '/modules/polish/') !== false && !$isPolishGia && !$isPolishParcel && (strpos($currentUri, 'tab=stock') !== false));

$isPolishHub    = (strpos($currentUri, '/modules/polish/') !== false || strpos($currentUri, '/modules/polish_') !== false) && !$isPolishGia && !$isPolishParcel && !$isPolishStock;

$isPartiesModule  = (strpos($currentUri, '/modules/parties/') !== false);
$isStaffOutsource = (strpos($currentUri, '/modules/staff_outsource/') !== false);
$typeParam = $_GET['type'] ?? '';

$isBroker    = ($isPartiesModule && ($typeParam === 'broker' || strpos($currentUri, 'type=broker') !== false));
$isOutsource = ($isPartiesModule && ($typeParam === 'outsource' || strpos($currentUri, 'type=outsource') !== false)) || $isStaffOutsource;
$isParty     = ($isPartiesModule && !$isBroker && !$isOutsource);

$isAssort    = (strpos($currentUri, '/modules/assort/') !== false);
$isKapan     = (strpos($currentUri, '/modules/kapan/') !== false);
$isJobwork   = (strpos($currentUri, '/modules/process/') !== false && strpos($currentUri, 'barcode_scanner.php') === false) || strpos($currentUri, '/modules/stock/') !== false || strpos($currentUri, '/modules/tops/') !== false;
$isBoil      = (strpos($currentUri, '/modules/boil/') !== false);
$isBarcode   = (strpos($currentUri, '/modules/process/barcode_scanner.php') !== false);

$isJangad    = (strpos($currentUri, '/modules/jangad/') !== false);

$isNetworth  = (strpos($currentUri, '/modules/finance/networth.php') !== false);
$isSchedule  = (strpos($currentUri, '/modules/finance/schedule.php') !== false);
$isBrokerage = (strpos($currentUri, '/modules/finance/brokerage.php') !== false);
$isTx        = (strpos($currentUri, '/modules/finance/transactions.php') !== false) || (strpos($currentUri, '/modules/finance/') !== false && !$isNetworth && !$isSchedule && !$isBrokerage);

$isReports   = (strpos($currentUri, '/modules/reports/') !== false);
$isTeam      = (strpos($currentUri, '/modules/admin/users.php') !== false);
$isBackup    = (strpos($currentUri, '/modules/admin/backup.php') !== false);
$isSettings  = (strpos($currentUri, '/modules/admin/settings.php') !== false) || (strpos($currentUri, '/modules/admin/') !== false && !$isTeam && !$isBackup);

// Group Active Flags
$isTradeActive   = ($isRoughTrade || $isPolishHub || $isPolishGia || $isPolishParcel || $isParty || $isBroker);
$isMfgActive     = ($isAssort || $isKapan || $isJobwork || $isBoil || $isBarcode || $isOutsource);
$isStockActive   = ($isRoughStock || $isPolishStock || $isJangad);
$isFinanceActive = ($isTx || $isNetworth || $isSchedule || $isBrokerage);
$isSettingActive = ($isReports || $isTeam || $isSettings || $isBackup);
?>
<aside id="sidebar-wrapper" aria-label="Primary Navigation">
    <div class="d-flex align-items-center justify-content-between brand-section-wrap pe-2">
        <a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="brand-section text-decoration-none flex-grow-1">
            <div class="brand-icon-box" title="Kasyap ERP">
                <?= render_brand_logo_svg(32) ?>
            </div>
            <div class="brand-title">Kasyap</div>
        </a>
        <button class="btn-icon-circle border-0 d-lg-none" id="sidebarCloseBtn" type="button" aria-label="Close navigation" style="width: 34px; height: 34px; flex-shrink: 0;">
            <?= svg_icon('xmark', '', 16) ?>
        </button>
    </div>

    <nav class="sidebar-scroll">
        <!-- 0. Dashboard -->
        <a class="nav-link <?= $isDashboard ? 'active' : '' ?>"
           href="<?= BASE_URL ?>/modules/dashboard/index.php"
           data-title="Dashboard">
            <?= svg_icon('home', '', 18) ?>
            <span>Dashboard (ડેશબોર્ડ)</span>
        </a>

        <!-- 1. ખરીદી-વેચાણ (Trade Group) -->
        <div class="sidebar-dropdown-wrap">
            <a class="nav-link sidebar-dropdown-toggle d-flex align-items-center justify-content-between <?= $isTradeActive ? 'active' : '' ?>"
               href="#tradeSubmenu"
               data-bs-toggle="collapse"
               role="button"
               aria-expanded="<?= $isTradeActive ? 'true' : 'false' ?>"
               aria-controls="tradeSubmenu"
               data-title="ખરીદી-વેચાણ">
                <div class="d-flex align-items-center gap-2.5">
                    <?= svg_icon('cart-shopping', '', 18) ?>
                    <span>ખરીદી-વેચાણ</span>
                </div>
                <?= svg_icon('chevron-down', 'sidebar-arrow-icon', 12) ?>
            </a>
            <div class="collapse <?= $isTradeActive ? 'show' : '' ?> sidebar-submenu" id="tradeSubmenu">
                <a class="nav-link <?= $isRoughTrade ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/rough/index.php"
                   data-title="Rough">
                    <?= svg_icon('shapes', '', 15) ?>
                    <span>Rough (રફ ખરીદી-વેચાણ)</span>
                </a>
                <a class="nav-link <?= $isPolishHub ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/polish/index.php"
                   data-title="Polish">
                    <?= svg_icon('gem', '', 15) ?>
                    <span>Polish (પોલિશ)</span>
                </a>
                <a class="nav-link <?= $isPolishGia ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/polish/index.php?section=gia&tab=gia_stock"
                   data-title="Polish GIA">
                    <?= svg_icon('certificate', '', 15) ?>
                    <span>Polish GIA</span>
                </a>
                <a class="nav-link <?= $isPolishParcel ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/polish/index.php?section=parcel&tab=parcel_stock"
                   data-title="Polish Parcel">
                    <?= svg_icon('boxes-stacked', '', 15) ?>
                    <span>Polish Parcel</span>
                </a>
                <a class="nav-link <?= $isParty ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/parties/index.php?type=party"
                   data-title="Party">
                    <?= svg_icon('users', '', 15) ?>
                    <span>Party (પાર્ટી / વેપારી)</span>
                </a>
                <a class="nav-link <?= $isBroker ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/parties/index.php?type=broker"
                   data-title="Dalal">
                    <?= svg_icon('handshake', '', 15) ?>
                    <span>Dalal (દલાલ / બ્રોકર)</span>
                </a>
            </div>
        </div>

        <!-- 2. ઉત્પાદન (Manufacturing Group) -->
        <div class="sidebar-dropdown-wrap">
            <a class="nav-link sidebar-dropdown-toggle d-flex align-items-center justify-content-between <?= $isMfgActive ? 'active' : '' ?>"
               href="#mfgSubmenu"
               data-bs-toggle="collapse"
               role="button"
               aria-expanded="<?= $isMfgActive ? 'true' : 'false' ?>"
               aria-controls="mfgSubmenu"
               data-title="ઉત્પાદન">
                <div class="d-flex align-items-center gap-2.5">
                    <?= svg_icon('diagram-project', '', 18) ?>
                    <span>ઉત્પાદન (પ્રોસેસ)</span>
                </div>
                <?= svg_icon('chevron-down', 'sidebar-arrow-icon', 12) ?>
            </a>
            <div class="collapse <?= $isMfgActive ? 'show' : '' ?> sidebar-submenu" id="mfgSubmenu">
                <a class="nav-link <?= $isAssort ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/assort/index.php"
                   data-title="Assort">
                    <?= svg_icon('layer-group', '', 15) ?>
                    <span>Assort (એસોર્ટ વર્ગીકરણ)</span>
                </a>
                <a class="nav-link <?= $isKapan ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/kapan/index.php"
                   data-title="Kapan">
                    <?= svg_icon('boxes-stacked', '', 15) ?>
                    <span>Kapan (કાપન ઉત્પાદન)</span>
                </a>
                <a class="nav-link <?= $isJobwork ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/process/jobwork_stock.php"
                   data-title="Job Work">
                    <?= svg_icon('briefcase', '', 15) ?>
                    <span>Job Work (જોબવર્ક)</span>
                </a>
                <a class="nav-link <?= $isBoil ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/boil/index.php"
                   data-title="Boiling">
                    <?= svg_icon('fire-burner', '', 15) ?>
                    <span>Boiling (બોઇલિંગ)</span>
                </a>
                <a class="nav-link <?= $isBarcode ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/process/barcode_scanner.php"
                   data-title="Barcode">
                    <?= svg_icon('barcode', '', 15) ?>
                    <span>Barcode (બારકોડ સ્કેનર)</span>
                </a>
                <a class="nav-link <?= $isOutsource ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/parties/index.php?type=outsource"
                   data-title="Outsource">
                    <?= svg_icon('industry', '', 15) ?>
                    <span>Outsource (આઉટસોર્સ)</span>
                </a>
            </div>
        </div>

        <!-- 3. સ્ટોક (Stock & Inventory Group) -->
        <div class="sidebar-dropdown-wrap">
            <a class="nav-link sidebar-dropdown-toggle d-flex align-items-center justify-content-between <?= $isStockActive ? 'active' : '' ?>"
               href="#stockSubmenu"
               data-bs-toggle="collapse"
               role="button"
               aria-expanded="<?= $isStockActive ? 'true' : 'false' ?>"
               aria-controls="stockSubmenu"
               data-title="સ્ટોક">
                <div class="d-flex align-items-center gap-2.5">
                    <?= svg_icon('warehouse', '', 18) ?>
                    <span>સ્ટોક (ઇન્વેન્ટરી)</span>
                </div>
                <?= svg_icon('chevron-down', 'sidebar-arrow-icon', 12) ?>
            </a>
            <div class="collapse <?= $isStockActive ? 'show' : '' ?> sidebar-submenu" id="stockSubmenu">
                <a class="nav-link <?= $isRoughStock ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/rough/index.php?tab=stock"
                   data-title="Rough Stock">
                    <?= svg_icon('shapes', '', 15) ?>
                    <span>Rough Stock (રફ હાજર સ્ટોક)</span>
                </a>
                <a class="nav-link <?= $isPolishStock ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/polish/index.php?tab=gia_stock"
                   data-title="Polish Stock">
                    <?= svg_icon('gem', '', 15) ?>
                    <span>Polish Stock (પોલિશ સ્ટોક)</span>
                </a>
                <a class="nav-link <?= $isJangad ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/jangad/index.php"
                   data-title="Jangad Memo">
                    <?= svg_icon('file-invoice', '', 15) ?>
                    <span>Jangad Memo (જાંગડ મેમો)</span>
                </a>
            </div>
        </div>

        <!-- 4. નાણાં (Finance Group) -->
        <div class="sidebar-dropdown-wrap">
            <a class="nav-link sidebar-dropdown-toggle d-flex align-items-center justify-content-between <?= $isFinanceActive ? 'active' : '' ?>"
               href="#financeSubmenu"
               data-bs-toggle="collapse"
               role="button"
               aria-expanded="<?= $isFinanceActive ? 'true' : 'false' ?>"
               aria-controls="financeSubmenu"
               data-title="નાણાં">
                <div class="d-flex align-items-center gap-2.5">
                    <?= svg_icon('wallet', '', 18) ?>
                    <span>નાણાં (હિસાબ)</span>
                </div>
                <?= svg_icon('chevron-down', 'sidebar-arrow-icon', 12) ?>
            </a>
            <div class="collapse <?= $isFinanceActive ? 'show' : '' ?> sidebar-submenu" id="financeSubmenu">
                <a class="nav-link <?= $isTx ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/finance/transactions.php"
                   data-title="Transactions">
                    <?= svg_icon('receipt', '', 15) ?>
                    <span>Transactions (લેવડ-દેવડ)</span>
                </a>
                <a class="nav-link <?= $isNetworth ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/finance/networth.php"
                   data-title="Networth">
                    <?= svg_icon('scale-balanced', '', 15) ?>
                    <span>Networth (કુલ મૂડી / નેટવર્થ)</span>
                </a>
                <a class="nav-link <?= $isSchedule ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/finance/schedule.php"
                   data-title="Payment Schedule">
                    <?= svg_icon('bell', '', 15) ?>
                    <span>Schedule (મુદત એલર્ટ)</span>
                </a>
                <a class="nav-link <?= $isBrokerage ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/finance/brokerage.php"
                   data-title="Brokerage">
                    <?= svg_icon('handshake', '', 15) ?>
                    <span>Dalali (દલાલી હિસાબ)</span>
                </a>
            </div>
        </div>

        <!-- 5. સેટિંગ (Settings & Admin Group) -->
        <div class="sidebar-dropdown-wrap">
            <a class="nav-link sidebar-dropdown-toggle d-flex align-items-center justify-content-between <?= $isSettingActive ? 'active' : '' ?>"
               href="#settingSubmenu"
               data-bs-toggle="collapse"
               role="button"
               aria-expanded="<?= $isSettingActive ? 'true' : 'false' ?>"
               aria-controls="settingSubmenu"
               data-title="સેટિંગ">
                <div class="d-flex align-items-center gap-2.5">
                    <?= svg_icon('gear', '', 18) ?>
                    <span>સેટિંગ & રિપોર્ટ્સ</span>
                </div>
                <?= svg_icon('chevron-down', 'sidebar-arrow-icon', 12) ?>
            </a>
            <div class="collapse <?= $isSettingActive ? 'show' : '' ?> sidebar-submenu" id="settingSubmenu">
                <a class="nav-link <?= $isReports ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/reports/summary.php"
                   data-title="Reports">
                    <?= svg_icon('chart-pie', '', 15) ?>
                    <span>Reports (રિપોર્ટ & સારાંશ)</span>
                </a>
                <a class="nav-link <?= $isTeam ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/admin/users.php"
                   data-title="Team Member">
                    <?= svg_icon('users-gear', '', 15) ?>
                    <span>Team (ટીમ & યુઝર્સ)</span>
                </a>
                <a class="nav-link <?= $isSettings ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/admin/settings.php"
                   data-title="Setting">
                    <?= svg_icon('sliders', '', 15) ?>
                    <span>Settings (સિસ્ટમ સેટિંગ્સ)</span>
                </a>
                <a class="nav-link <?= $isBackup ? 'active' : '' ?>"
                   href="<?= BASE_URL ?>/modules/admin/backup.php"
                   data-title="Backup">
                    <?= svg_icon('cloud-arrow-down', '', 15) ?>
                    <span>Backup (ડેટા બેકઅપ)</span>
                </a>
            </div>
        </div>
    </nav>
</aside>
