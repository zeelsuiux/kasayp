<?php
/**
 * Master Directory: Parties, Brokers & Outsource Units
 * Strictly Segmented Views with Dedicated Actions, Filters & Stats
 * Diamond ERP
 */

declare(strict_types=1);

$typeParam = trim($_GET['type'] ?? 'party');

// Determine Section: 'broker', 'outsource', or 'party' (default)
if ($typeParam === 'broker' || in_array($typeParam, ['rough_broker', 'polish_broker', 'sell_broker', 'rough_sale_broker', 'polish_sale_broker', 'rough_purchase_broker', 'polish_purchase_broker'])) {
    $section = 'broker';
} elseif ($typeParam === 'outsource' || in_array($typeParam, ['outsource_galaxy', 'outsource_ls', 'outsource_factory', 'lab_gia', 'galaxy', 'ls', 'factory', 'lab'])) {
    $section = 'outsource';
} else {
    $section = 'party';
}

$sub = trim($_GET['sub'] ?? '');
if (empty($sub) && !in_array($typeParam, ['party', 'broker', 'outsource'])) {
    $sub = $typeParam;
}

$cityFilter   = trim($_GET['city'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$search       = trim($_GET['search'] ?? '');

$pageTitle = match($section) {
    'broker'    => 'દલાલ ડિરેક્ટરી (Diamond Brokers)',
    'outsource' => 'આઉટસોર્સ યુનિટ્સ & વેન્ડર્સ (Outsource Units)',
    default     => 'પાર્ટી ડિરેક્ટરી (Parties / Customers & Suppliers)'
};

require_once __DIR__ . '/../../includes/header.php';

$pdo = getDbConnection();
$params = [];

// =========================================================================
// 1. DATA QUERIES ACCORDING TO CURRENT SECTION
// =========================================================================
$scJoin = "";

if ($section === 'broker') {
    $sql = "SELECT p.*,
                   (SELECT COUNT(*) FROM rough_purchases WHERE broker_id = p.id{$scJoin}) +
                   (SELECT COUNT(*) FROM polish_purchases WHERE broker_id = p.id{$scJoin}) +
                   (SELECT COUNT(*) FROM rough_sales WHERE broker_id = p.id{$scJoin}) +
                   (SELECT COUNT(*) FROM polish_sales WHERE broker_id = p.id{$scJoin}) as total_deals,
                   (SELECT COALESCE(SUM(brokerage_amount), 0) FROM rough_purchases WHERE broker_id = p.id{$scJoin}) +
                   (SELECT COALESCE(SUM(brokerage_amount), 0) FROM polish_purchases WHERE broker_id = p.id{$scJoin}) as total_brokerage
            FROM parties p
            WHERE (p.party_type IN ('broker', 'both') OR p.roles LIKE '%broker%')";

    if (!empty($sub)) {
        if ($sub === 'rough_purchase' || $sub === 'rough_purchase_broker') {
            $sql .= " AND (p.roles LIKE '%rough_purchase_broker%' OR (p.roles LIKE '%rough%' AND p.roles LIKE '%purchase%'))";
        } elseif ($sub === 'rough_sale' || $sub === 'rough_sale_broker') {
            $sql .= " AND (p.roles LIKE '%rough_sale_broker%' OR (p.roles LIKE '%rough%' AND p.roles LIKE '%sale%'))";
        } elseif ($sub === 'polish_purchase' || $sub === 'polish_purchase_broker') {
            $sql .= " AND (p.roles LIKE '%polish_purchase_broker%' OR (p.roles LIKE '%polish%' AND p.roles LIKE '%purchase%'))";
        } elseif ($sub === 'polish_sale' || $sub === 'polish_sale_broker') {
            $sql .= " AND (p.roles LIKE '%polish_sale_broker%' OR (p.roles LIKE '%polish%' AND p.roles LIKE '%sale%'))";
        } elseif ($sub === 'rough' || $sub === 'rough_broker') {
            $sql .= " AND (p.roles LIKE '%rough%broker%' OR p.roles LIKE '%rough%')";
        } elseif ($sub === 'polish' || $sub === 'polish_broker') {
            $sql .= " AND (p.roles LIKE '%polish%broker%' OR p.roles LIKE '%polish%')";
        }
    }

    // Broker KPI Stats
    $bStats = $pdo->query("SELECT 
        COUNT(*) as total_brokers,
        SUM(CASE WHEN roles LIKE '%rough%' THEN 1 ELSE 0 END) as rough_brokers,
        SUM(CASE WHEN roles LIKE '%polish%' THEN 1 ELSE 0 END) as polish_brokers
    FROM parties WHERE (party_type IN ('broker', 'both') OR roles LIKE '%broker%')")->fetch(PDO::FETCH_ASSOC);

    $secTotal   = (int)($bStats['total_brokers'] ?? 0);
    $secSubA    = (int)($bStats['rough_brokers'] ?? 0);
    $secSubB    = (int)($bStats['polish_brokers'] ?? 0);

} elseif ($section === 'outsource') {
    $sql = "SELECT p.*,
                   (SELECT COUNT(*) FROM jangad_vouchers WHERE party_id = p.id AND status IN ('issued', 'partially_returned'){$scJoin}) as pending_jangads,
                   (SELECT COALESCE(SUM(total_amount), 0) FROM outsource_bills WHERE party_id = p.id{$scJoin}) as total_billed,
                   (SELECT COALESCE(SUM(paid_amount), 0) FROM outsource_bills WHERE party_id = p.id{$scJoin}) as total_paid
            FROM parties p
            WHERE (p.party_type LIKE 'outsource_%' OR p.party_type IN ('lab_gia', 'factory', 'galaxy', 'ls'))";

    if (!empty($sub)) {
        if (in_array($sub, ['outsource_galaxy', 'outsource_ls', 'outsource_factory', 'lab_gia'])) {
            $sql .= " AND p.party_type = ?";
            $params[] = $sub;
        }
    }

    // Outsource KPI Stats
    $oStats = $pdo->query("SELECT 
        COUNT(*) as total_outsource,
        SUM(CASE WHEN party_type = 'outsource_galaxy' THEN 1 ELSE 0 END) as galaxy_units,
        SUM(CASE WHEN party_type = 'outsource_ls' THEN 1 ELSE 0 END) as ls_units,
        SUM(CASE WHEN party_type = 'outsource_factory' THEN 1 ELSE 0 END) as factory_units,
        SUM(CASE WHEN party_type = 'lab_gia' THEN 1 ELSE 0 END) as lab_units
    FROM parties WHERE (party_type LIKE 'outsource_%' OR party_type = 'lab_gia')")->fetch(PDO::FETCH_ASSOC);

    $secTotal   = (int)($oStats['total_outsource'] ?? 0);
    $secGalaxy  = (int)($oStats['galaxy_units'] ?? 0);
    $secLs      = (int)($oStats['ls_units'] ?? 0);
    $secFactory = (int)($oStats['factory_units'] ?? 0);
    $secLab     = (int)($oStats['lab_units'] ?? 0);

} else { // 'party'
    $sql = "SELECT p.*,
                   (SELECT COUNT(*) FROM rough_purchases WHERE party_id = p.id{$scJoin}) as rough_pur_count,
                   (SELECT COUNT(*) FROM polish_purchases WHERE party_id = p.id{$scJoin}) as pol_pur_count,
                   (SELECT COUNT(*) FROM rough_sales WHERE party_id = p.id{$scJoin}) as rough_sale_count,
                   (SELECT COUNT(*) FROM polish_sales WHERE party_id = p.id{$scJoin}) as pol_sale_count,
                   (SELECT COUNT(*) FROM jangad_vouchers WHERE party_id = p.id AND status IN ('issued', 'partially_returned'){$scJoin}) as pending_jangads
            FROM parties p
            WHERE (p.party_type IN ('party', 'both') OR (p.party_type NOT IN ('broker', 'outsource_galaxy', 'outsource_ls', 'outsource_factory', 'lab_gia') AND (p.roles IS NULL OR p.roles NOT LIKE '%broker%')))";

    if (!empty($sub)) {
        if (in_array($sub, ['rough_sale', 'rough_purchase', 'polish_sale', 'polish_purchase'])) {
            $sql .= " AND p.roles LIKE ?";
            $params[] = '%' . $sub . '%';
        } elseif ($sub === 'rough') {
            $sql .= " AND (p.roles LIKE '%rough%' OR p.roles LIKE '%rough_purchase%' OR p.roles LIKE '%rough_sale%')";
        } elseif ($sub === 'polish') {
            $sql .= " AND (p.roles LIKE '%polish%' OR p.roles LIKE '%polish_purchase%' OR p.roles LIKE '%polish_sale%')";
        } elseif ($sub === 'suppliers' || $sub === 'supplier') {
            $sql .= " AND (p.roles LIKE '%purchase%' OR p.roles LIKE '%rough_purchase%' OR p.roles LIKE '%polish_purchase%')";
        } elseif ($sub === 'customers' || $sub === 'customer') {
            $sql .= " AND (p.roles LIKE '%sale%' OR p.roles LIKE '%rough_sale%' OR p.roles LIKE '%polish_sale%')";
        }
    }

    // Party KPI Stats
    $pStats = $pdo->query("SELECT 
        COUNT(*) as total_parties,
        SUM(CASE WHEN roles LIKE '%rough%' THEN 1 ELSE 0 END) as rough_parties,
        SUM(CASE WHEN roles LIKE '%polish%' THEN 1 ELSE 0 END) as polish_parties
    FROM parties WHERE (party_type IN ('party', 'both') OR (party_type NOT IN ('broker', 'outsource_galaxy', 'outsource_ls', 'outsource_factory', 'lab_gia') AND (roles IS NULL OR roles NOT LIKE '%broker%')))")->fetch(PDO::FETCH_ASSOC);

    $secTotal   = (int)($pStats['total_parties'] ?? 0);
    $secRough   = (int)($pStats['rough_parties'] ?? 0);
    $secPolish  = (int)($pStats['polish_parties'] ?? 0);
}

// Common filters for city, status and search
if (!empty($statusFilter)) {
    $sql .= " AND p.status = ?";
    $params[] = $statusFilter;
}

if (!empty($cityFilter)) {
    $sql .= " AND p.city = ?";
    $params[] = $cityFilter;
}

if (!empty($search)) {
    $sql .= " AND (p.party_name LIKE ? OR p.phone LIKE ? OR p.contact_person LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term]);
}

$sql .= " ORDER BY p.party_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allParties = $stmt->fetchAll();
$recordCount = count($allParties);
$pagData = get_pagination_data($recordCount, 25);
$parties = array_slice($allParties, $pagData['offset'], $pagData['per_page']);
?>

<!-- =======================================================================
     3. PAGE HEADER WITH SECTION-SPECIFIC ACTION BUTTONS
     ======================================================================= -->
<?php if ($section === 'broker'): ?>
    <?= render_page_header(
        'દલાલ ડિરેક્ટરી (Diamond Brokers)',
        'હીરા બજારના તમામ સક્રિય દલાલો (Brokers) અને દલાલી કમિશન વિગતો',
        'handshake',
        '
        <div class="d-flex flex-wrap gap-2">
            <a href="' . BASE_URL . '/modules/reports/export.php?type=parties&type_filter=broker" class="btn btn-outline-success px-3 shadow-sm">' . svg_icon('file-excel', 'me-1') . 'Excel Export</a>
            <a href="' . BASE_URL . '/modules/finance/brokerage.php" class="btn btn-outline-primary px-3 shadow-sm">' . svg_icon('wallet', 'me-1') . 'દલાલી હિસાબ</a>
            <a href="' . BASE_URL . '/modules/parties/create.php?type=broker" class="btn btn-primary px-3 shadow-sm">' . svg_icon('plus', 'me-1') . 'નવો દલાલ (Add Broker)</a>
        </div>
        '
    ) ?>

<?php elseif ($section === 'outsource'): ?>
    <?= render_page_header(
        'આઉટસોર્સ યુનિટ્સ & વેન્ડર્સ (Outsource Units & Labs)',
        'Galaxy Scanning, Laser Sawing (LS), કારખાનું અને GIA લેબોરેટરીઝ',
        'industry',
        '
        <div class="d-flex flex-wrap gap-2">
            <a href="' . BASE_URL . '/modules/reports/export.php?type=parties&type_filter=outsource" class="btn btn-outline-success px-3 shadow-sm">' . svg_icon('file-excel', 'me-1') . 'Excel Export</a>
            <a href="' . BASE_URL . '/modules/staff_outsource/outsource_bills.php" class="btn btn-outline-primary px-3 shadow-sm">' . svg_icon('receipt', 'me-1') . 'વેન્ડર બિલો</a>
            <a href="' . BASE_URL . '/modules/parties/create.php?type=outsource" class="btn btn-primary px-3 shadow-sm">' . svg_icon('plus', 'me-1') . 'નવું આઉટસોર્સ યુનિટ</a>
        </div>
        '
    ) ?>

<?php else: ?>
    <?= render_page_header(
        'પાર્ટી ડિરેક્ટરી (Parties / Customers & Suppliers)',
        'રફ અને પોલિશ હીરાના તમામ ખરીદ-વેચાણ ગ્રાહકો તથા સપ્લાયર્સ',
        'users',
        '
        <div class="d-flex flex-wrap gap-2">
            <a href="' . BASE_URL . '/modules/reports/export.php?type=parties&type_filter=party" class="btn btn-outline-success px-3 shadow-sm">' . svg_icon('file-excel', 'me-1') . 'Excel Export</a>
            <a href="' . BASE_URL . '/modules/parties/create.php?type=party" class="btn btn-primary px-3 shadow-sm">' . svg_icon('plus', 'me-1') . 'નવી પાર્ટી (Add Party)</a>
        </div>
        '
    ) ?>
<?php endif; ?>

<!-- =======================================================================
     4. SECTION-SPECIFIC KPI STAT CARDS (ZERO MIXING)
     ======================================================================= -->
<div class="row g-2.5 mb-3">
    <?php if ($section === 'broker'): ?>
        <div class="col-12 col-md-4">
            <?= render_stat_card('કુલ દલાલો (Total Brokers)', (string)$secTotal . ' દલાલો', 'હીરા બજાર દલાલો', 'સક્રિય કમિશન ખાતા', 'hero', BASE_URL . '/modules/parties/index.php?type=broker', '', 'handshake') ?>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <?= render_stat_card('રફ દલાલો (Rough Brokers)', (string)$secSubA . ' દલાલો', 'રફ ખરીદી-વેચાણ', 'Rough Diamond Deals', 'white', BASE_URL . '/modules/parties/index.php?type=broker&sub=rough', 'pill-mint', 'gem') ?>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <?= render_stat_card('પોલિશ દલાલો (Polish Brokers)', (string)$secSubB . ' દલાલો', 'પોલિશ ખરીદી-વેચાણ', 'Polish Diamond Deals', 'white', BASE_URL . '/modules/parties/index.php?type=broker&sub=polish', 'pill-mint', 'award') ?>
        </div>

    <?php elseif ($section === 'outsource'): ?>
        <div class="col-12 col-md-4">
            <?= render_stat_card('કુલ આઉટસોર્સ યુનિટ્સ', (string)$secTotal . ' એકમો', 'સર્વિસ પ્રોવાઇડર્સ', 'Galaxy, LS, કારખાના, લેબ્સ', 'hero', BASE_URL . '/modules/parties/index.php?type=outsource', '', 'industry') ?>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <?= render_stat_card('Galaxy & LS કેન્દ્રો', (string)($secGalaxy + $secLs) . ' યુનિટ્સ', $secGalaxy . ' Galaxy • ' . $secLs . ' LS', 'પ્લાનિંગ & લેસર', 'white', BASE_URL . '/modules/parties/index.php?type=outsource&sub=outsource_galaxy', 'pill-mint', 'bolt') ?>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <?= render_stat_card('કારખાના & GIA લેબ્સ', (string)($secFactory + $secLab) . ' એકમો', $secFactory . ' કારખાના • ' . $secLab . ' લેબ', 'પોલિશિંગ & સર્ટિફિકેશન', 'white', BASE_URL . '/modules/parties/index.php?type=outsource&sub=outsource_factory', 'pill-mint', 'microscope') ?>
        </div>

    <?php else: ?>
        <div class="col-12 col-md-4">
            <?= render_stat_card('કુલ ગ્રાહકો & સપ્લાયર્સ', (string)$secTotal . ' પાર્ટીઓ', 'સક્રિય વેપારીઓ', 'સુરત & મુંબઈ ડિરેક્ટરી', 'hero', BASE_URL . '/modules/parties/index.php?type=party', '', 'user-check') ?>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <?= render_stat_card('રફ વેપાર પાર્ટીઓ', (string)$secRough . ' પાર્ટીઓ', 'રફ ખરીદ-વેચાણ', 'Rough Diamond Parties', 'white', BASE_URL . '/modules/parties/index.php?type=party&sub=rough', 'pill-mint', 'gem') ?>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <?= render_stat_card('પોલિશ વેપાર પાર્ટીઓ', (string)$secPolish . ' પાર્ટીઓ', 'પોલિશ ખરીદ-વેચાણ', 'Polish Diamond Parties', 'white', BASE_URL . '/modules/parties/index.php?type=party&sub=polish', 'pill-mint', 'award') ?>
        </div>
    <?php endif; ?>
</div>

<!-- =======================================================================
     5. SECTION-SPECIFIC FILTERS BAR (ZERO MIXING OF OPTIONS)
     ======================================================================= -->
<div class="erp-filter-card mb-3">
    <form method="GET" action="<?= BASE_URL ?>/modules/parties/index.php" class="row g-2 align-items-center">
        <input type="hidden" name="type" value="<?= e($section) ?>">

        <div class="col-12 col-md-4">
            <input type="text" 
                   name="search" 
                   class="form-control" 
                   placeholder="<?= $section === 'broker' ? 'દલાલનું નામ, ફોન, સંપર્ક વ્યક્તિ...' : ($section === 'outsource' ? 'યુનિટ / કારખાનાનું નામ, ફોન, સંપર્ક...' : 'પાર્ટીનું નામ, ફોન, સંપર્ક વ્યક્તિ...') ?>" 
                   value="<?= e($search) ?>">
        </div>

        <div class="col-6 col-md-3">
            <select name="sub" class="form-select" onchange="this.form.submit()">
                <?php if ($section === 'broker'): ?>
                    <option value="">બધા દલાલો (All Brokers)</option>
                    <option value="rough_purchase" <?= in_array($sub, ['rough_purchase', 'rough_purchase_broker']) ? 'selected' : '' ?>>📦 રફ ખરીદી દલાલ (Rough Purchase)</option>
                    <option value="rough_sale" <?= in_array($sub, ['rough_sale', 'rough_sale_broker']) ? 'selected' : '' ?>>💎 રફ વેચાણ દલાલ (Rough Sell)</option>
                    <option value="polish_purchase" <?= in_array($sub, ['polish_purchase', 'polish_purchase_broker']) ? 'selected' : '' ?>>📦 પોલિશ ખરીદી દલાલ (Polish Purchase)</option>
                    <option value="polish_sale" <?= in_array($sub, ['polish_sale', 'polish_sale_broker']) ? 'selected' : '' ?>>✨ પોલિશ વેચાણ દલાલ (Polish Sell)</option>

                <?php elseif ($section === 'outsource'): ?>
                    <option value="">બધા આઉટસોર્સ યુનિટ્સ (All)</option>
                    <option value="outsource_galaxy" <?= $sub === 'outsource_galaxy' ? 'selected' : '' ?>>🌌 Galaxy Scanner Hub</option>
                    <option value="outsource_ls" <?= $sub === 'outsource_ls' ? 'selected' : '' ?>>⚡ Laser Sawing (LS) Center</option>
                    <option value="outsource_factory" <?= $sub === 'outsource_factory' ? 'selected' : '' ?>>🏭 કારખાનું (Polish Factory)</option>
                    <option value="lab_gia" <?= $sub === 'lab_gia' ? 'selected' : '' ?>>🔬 GIA Laboratory (લેબ)</option>

                <?php else: ?>
                    <option value="">બધી પાર્ટીઓ (All Parties)</option>
                    <option value="rough" <?= $sub === 'rough' ? 'selected' : '' ?>>🔹 રફ વેપાર (Rough Parties: ખરીદ-વેચાણ)</option>
                    <option value="polish" <?= $sub === 'polish' ? 'selected' : '' ?>>✨ પોલિશ વેપાર (Polish Parties: ખરીદ-વેચાણ)</option>
                    <option value="suppliers" <?= in_array($sub, ['suppliers', 'supplier']) ? 'selected' : '' ?>>📦 તમામ સપ્લાયર્સ (All Suppliers)</option>
                    <option value="customers" <?= in_array($sub, ['customers', 'customer']) ? 'selected' : '' ?>>💎 તમામ ગ્રાહકો (All Customers)</option>
                    <option value="rough_purchase" <?= $sub === 'rough_purchase' ? 'selected' : '' ?>>   • રફ ખરીદી સપ્લાયર (Rough Supplier)</option>
                    <option value="rough_sale" <?= $sub === 'rough_sale' ? 'selected' : '' ?>>   • રફ વેચાણ ગ્રાહક (Rough Sell Customer)</option>
                    <option value="polish_purchase" <?= $sub === 'polish_purchase' ? 'selected' : '' ?>>   • પોલિશ ખરીદી સપ્લાયર (Polish Supplier)</option>
                    <option value="polish_sale" <?= $sub === 'polish_sale' ? 'selected' : '' ?>>   • પોલિશ વેચાણ ગ્રાહક (Polish Sell Customer)</option>
                <?php endif; ?>
            </select>
        </div>

        <div class="col-6 col-md-2">
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="">બધી સ્થિતિ (Status)</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active (સક્રિય)</option>
                <option value="blocked" <?= $statusFilter === 'blocked' ? 'selected' : '' ?>>🛑 Blocked (બ્લોક)</option>
            </select>
        </div>

        <div class="col-6 col-md-2">
            <select name="city" class="form-select" onchange="this.form.submit()">
                <option value="">બધા શહેરો</option>
                <option value="Surat" <?= $cityFilter === 'Surat' ? 'selected' : '' ?>>Surat</option>
                <option value="Mumbai" <?= $cityFilter === 'Mumbai' ? 'selected' : '' ?>>Mumbai</option>
            </select>
        </div>

        <div class="col-6 col-md-1 d-flex gap-1">
            <button type="submit" class="btn btn-primary flex-grow-1 px-2" title="શોધો"><?= svg_icon('magnifying-glass') ?></button>
            <?php if (!empty($search) || !empty($sub) || !empty($cityFilter) || !empty($statusFilter)): ?>
                <a href="<?= BASE_URL ?>/modules/parties/index.php?type=<?= e($section) ?>" class="btn btn-outline-dark px-2" title="રીસેટ"><?= svg_icon('rotate-left', '', 13) ?></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- =======================================================================
     6. DATA TABLE TAILORED TO ACTIVE SECTION
     ======================================================================= -->
<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <?php if ($section === 'broker'): ?>
                            <th class="ps-3 text-nowrap">દલાલનું નામ (Broker Name)</th>
                            <th class="text-nowrap">દલાલી પ્રકાર</th>
                            <th class="text-nowrap">સંપર્ક વ્યક્તિ</th>
                            <th class="text-nowrap">ફોન નંબર</th>
                            <th class="text-nowrap">શહેર (City)</th>
                            <th class="text-nowrap">PAN / GST</th>
                            <th class="text-end text-nowrap">ખાતાવહી બેલેન્સ</th>
                            <th class="text-center text-nowrap">કુલ સોદા</th>
                            <th class="text-center text-nowrap">સ્થિતિ</th>
                            <th class="text-center table-actions pe-3 text-nowrap">એક્શન</th>

                        <?php elseif ($section === 'outsource'): ?>
                            <th class="ps-3 text-nowrap">યુનિટ / વેન્ડરનું નામ</th>
                            <th class="text-nowrap">સર્વિસ કેટેગરી</th>
                            <th class="text-nowrap">સંપર્ક વ્યક્તિ</th>
                            <th class="text-nowrap">ફોન નંબર</th>
                            <th class="text-nowrap">શહેર (City)</th>
                            <th class="text-end text-nowrap">બિલિંગ / બાકી</th>
                            <th class="text-center text-nowrap">પેન્ડિંગ Jangad</th>
                            <th class="text-center text-nowrap">સ્થિતિ</th>
                            <th class="text-center table-actions pe-3 text-nowrap">એક્શન</th>

                        <?php else: ?>
                            <th class="ps-3 text-nowrap">પાર્ટીનું નામ (Customer / Supplier)</th>
                            <th class="text-nowrap">વેપાર ક્ષેત્ર / રોલ</th>
                            <th class="text-nowrap">સંપર્ક વ્યક્તિ</th>
                            <th class="text-nowrap">ફોન નંબર</th>
                            <th class="text-nowrap">શહેર (City)</th>
                            <th class="text-nowrap">PAN / GST</th>
                            <th class="text-end text-nowrap">ખાતાવહી બેલેન્સ</th>
                            <th class="text-center text-nowrap">સક્રિય જાંગડ (Jangad)</th>
                            <th class="text-center text-nowrap">સ્થિતિ</th>
                            <th class="text-center table-actions pe-3 text-nowrap">એક્શન</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($parties)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <?= svg_icon($section === 'broker' ? 'handshake' : ($section === 'outsource' ? 'industry' : 'users'), 'text-muted mb-2', 28) ?><br>
                                કોઈ <?= $section === 'broker' ? 'દલાલ' : ($section === 'outsource' ? 'આઉટસોર્સ યુનિટ' : 'પાર્ટી') ?> રેકોર્ડ મળ્યો નથી.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($parties as $p): 
                            $cBal = (float)($p['current_balance'] ?? 0);
                            $isBlk = (($p['status'] ?? 'active') === 'blocked');
                        ?>
                            <tr class="<?= $isBlk ? 'table-danger bg-danger-subtle' : '' ?>">
                                <td class="ps-3 text-nowrap">
                                    <div class="d-flex align-items-center gap-2">
                                        <?php if ($isBlk): ?>
                                            <span class="badge bg-danger text-white rounded-circle p-1" title="Blocked"><?= svg_icon('ban', '', 12) ?></span>
                                        <?php endif; ?>
                                        <div>
                                            <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $p['id'] ?>" class="text-primary fw-bold text-decoration-none">
                                                <?= e($p['party_name']) ?>
                                            </a>
                                            <?php if (!empty($p['notes'])): ?>
                                                <small class="text-muted d-block text-truncate" style="max-width: 220px;"><?= e($p['notes']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <?php if ($section === 'broker'): ?>
                                    <!-- Broker Specific Columns -->
                                    <td class="text-nowrap">
                                        <span class="badge bg-warning-subtle text-warning-emphasis border">Broker (દલાલ)</span>
                                        <div class="mt-1">
                                            <?= render_party_role_badges($p['roles'] ?? null) ?>
                                        </div>
                                    </td>
                                    <td class="text-nowrap"><?= (!empty(trim($p['contact_person'] ?? '')) && trim($p['contact_person']) !== '-') ? e($p['contact_person']) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-nowrap font-mono">
                                        <?php if (!empty(trim($p['phone'] ?? '')) && trim($p['phone']) !== '-'): ?>
                                            <?= svg_icon('phone', 'text-muted me-1') ?>
                                            <strong><?= e($p['phone']) ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?= svg_icon('location-dot', 'text-secondary me-1') ?>
                                        <strong><?= e($p['city'] ?: 'Surat') ?></strong>
                                    </td>
                                    <td class="text-nowrap font-mono small"><?= (!empty(trim($p['pan_gst'] ?? '')) && trim($p['pan_gst']) !== '-') ? e($p['pan_gst']) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-end font-mono text-nowrap">
                                        <span class="fw-bold <?= $cBal > 0 ? 'text-danger' : ($cBal < 0 ? 'text-success' : 'text-muted') ?>">
                                            <?= format_inr(abs($cBal)) ?>
                                        </span>
                                        <small class="d-block text-muted" style="font-size: 0.72rem;">
                                            <?= $cBal > 0 ? 'આપવાના (Payable)' : ($cBal < 0 ? 'લેવાના (Receivable)' : 'સરભર (Nil)') ?>
                                        </small>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <span class="badge bg-light text-dark border font-mono">
                                            <?= (int)($p['total_deals'] ?? 0) ?> સોદા
                                        </span>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <?php if ($isBlk): ?>
                                            <span class="badge bg-danger text-white border px-2 py-1 fw-bold shadow-xs" title="<?= e($p['block_reason'] ?? 'બ્લોક કરેલ') ?>">
                                                🛑 બ્લોક
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border px-2 py-1">સક્રિય</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center table-actions pe-3 text-nowrap">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <a href="<?= BASE_URL ?>/modules/finance/brokerage.php?broker_id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-primary py-1 px-2" title="દલાલી હિસાબ">
                                                <?= svg_icon('wallet', 'me-1') ?>દલાલી
                                            </a>
                                            <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-secondary py-1 px-2" title="પ્રોફાઇલ">
                                                <?= svg_icon('receipt', 'me-1') ?>ખાતાવહી
                                            </a>
                                            <a href="<?= BASE_URL ?>/modules/parties/edit.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-secondary py-1 px-2" title="એડિટ">
                                                <?= svg_icon('pen-to-square', 'me-1') ?>એડિટ
                                            </a>
                                            <?php if ($isBlk): ?>
                                                <button type="button" class="btn btn-xs btn-success text-white py-1 px-2" onclick="unblockParty(<?= $p['id'] ?>, '<?= e(addslashes($p['party_name'])) ?>')" title="અન-બ્લોક કરો">
                                                    <?= svg_icon('check', 'me-1', 12) ?>અન-બ્લોક
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-xs btn-outline-danger py-1 px-2" onclick="openBlockModal(<?= $p['id'] ?>, '<?= e(addslashes($p['party_name'])) ?>')" title="બ્લોક કરો">
                                                    <?= svg_icon('ban', 'me-1', 12) ?>બ્લોક
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                <?php elseif ($section === 'outsource'): ?>
                                    <!-- Outsource Specific Columns -->
                                    <td class="text-nowrap">
                                        <?php
                                         $oMap = [
                                            'outsource_galaxy' => ['Galaxy Hub', 'bg-info-subtle text-info-emphasis'],
                                            'outsource_ls' => ['LS Sawing', 'bg-warning-subtle text-warning-emphasis'],
                                            'outsource_factory' => ['Polish કારખાનું', 'bg-danger-subtle text-danger-emphasis'],
                                            'lab_gia' => ['GIA લેબ', 'bg-primary-subtle text-primary']
                                        ];
                                        $oBadge = $oMap[$p['party_type']] ?? [e($p['party_type']), 'bg-light text-dark'];
                                        ?>
                                        <span class="badge <?= $oBadge[1] ?> border"><?= $oBadge[0] ?></span>
                                    </td>
                                    <td class="text-nowrap"><?= (!empty(trim($p['contact_person'] ?? '')) && trim($p['contact_person']) !== '-') ? e($p['contact_person']) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-nowrap font-mono">
                                        <?php if (!empty(trim($p['phone'] ?? '')) && trim($p['phone']) !== '-'): ?>
                                            <?= svg_icon('phone', 'text-muted me-1') ?>
                                            <strong><?= e($p['phone']) ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?= svg_icon('location-dot', 'text-secondary me-1') ?>
                                        <strong><?= e($p['city'] ?: 'Surat') ?></strong>
                                    </td>
                                    <td class="text-end font-mono text-nowrap">
                                        <?php 
                                        $dueAmt = (float)($p['total_billed'] ?? 0) - (float)($p['total_paid'] ?? 0);
                                        ?>
                                        <span class="fw-bold <?= $dueAmt > 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= format_inr(abs($dueAmt)) ?>
                                        </span>
                                        <small class="d-block text-muted" style="font-size: 0.72rem;">
                                            <?= $dueAmt > 0 ? 'આપવાના બાકી' : ($dueAmt < 0 ? 'જમા બેલેન્સ' : 'સરભર') ?> (બિલિંગ: <?= format_inr((float)($p['total_billed'] ?? 0)) ?>)
                                        </small>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <?php if ((int)$p['pending_jangads'] > 0): ?>
                                            <span class="badge bg-danger-subtle text-danger border font-mono">
                                                <?= (int)$p['pending_jangads'] ?> બાકી
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <?php if ($isBlk): ?>
                                            <span class="badge bg-danger text-white border px-2 py-1 fw-bold shadow-xs" title="<?= e($p['block_reason'] ?? 'બ્લોક કરેલ') ?>">
                                                🛑 બ્લોક
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border px-2 py-1">સક્રિય</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center table-actions pe-3 text-nowrap">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <a href="<?= BASE_URL ?>/modules/staff_outsource/outsource_bills.php?party_id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-primary py-1 px-2" title="બિલો જુઓ">
                                                <?= svg_icon('receipt', 'me-1') ?>બિલ
                                            </a>
                                            <a href="<?= BASE_URL ?>/modules/jangad/create.php?party_id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-success py-1 px-2" title="Jangad ઇસ્યુ કરો">
                                                <?= svg_icon('file-invoice', 'me-1') ?>જાંગડ
                                            </a>
                                            <a href="<?= BASE_URL ?>/modules/parties/edit.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-secondary py-1 px-2" title="એડિટ">
                                                <?= svg_icon('pen-to-square', 'me-1') ?>એડિટ
                                            </a>
                                            <?php if ($isBlk): ?>
                                                <button type="button" class="btn btn-xs btn-success text-white py-1 px-2" onclick="unblockParty(<?= $p['id'] ?>, '<?= e(addslashes($p['party_name'])) ?>')" title="અન-બ્લોક કરો">
                                                    <?= svg_icon('check', 'me-1', 12) ?>અન-બ્લોક
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-xs btn-outline-danger py-1 px-2" onclick="openBlockModal(<?= $p['id'] ?>, '<?= e(addslashes($p['party_name'])) ?>')" title="બ્લોક કરો">
                                                    <?= svg_icon('ban', 'me-1', 12) ?>બ્લોક
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                <?php else: ?>
                                    <!-- Party Specific Columns -->
                                    <td style="max-width: 250px;">
                                        <?php if (empty($p['roles']) || $p['roles'] === '[]'): ?>
                                            <span class="finora-pill finora-pill-slate" style="font-size: 0.70rem; padding: 2px 8px;">General</span>
                                        <?php else: ?>
                                            <?= render_party_role_badges($p['roles'] ?? null) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap"><?= (!empty(trim($p['contact_person'] ?? '')) && trim($p['contact_person']) !== '-') ? e($p['contact_person']) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-nowrap font-mono">
                                        <?php if (!empty(trim($p['phone'] ?? '')) && trim($p['phone']) !== '-'): ?>
                                            <?= svg_icon('phone', 'text-muted me-1') ?>
                                            <strong><?= e($p['phone']) ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap">
                                        <?= svg_icon('location-dot', 'text-secondary me-1') ?>
                                        <strong><?= e($p['city'] ?: 'Surat') ?></strong>
                                    </td>
                                    <td class="text-nowrap font-mono small"><?= (!empty(trim($p['pan_gst'] ?? '')) && trim($p['pan_gst']) !== '-') ? e($p['pan_gst']) : '<span class="text-muted">—</span>' ?></td>
                                    <td class="text-end font-mono text-nowrap">
                                        <span class="fw-bold <?= $cBal > 0 ? 'text-danger' : ($cBal < 0 ? 'text-success' : 'text-muted') ?>">
                                            <?= format_inr(abs($cBal)) ?>
                                        </span>
                                        <small class="d-block text-muted" style="font-size: 0.72rem;">
                                            <?= $cBal > 0 ? 'આપવાના (Payable)' : ($cBal < 0 ? 'લેવાના (Receivable)' : 'સરભર (Nil)') ?>
                                        </small>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <?php if ((int)$p['pending_jangads'] > 0): ?>
                                            <span class="badge bg-danger-subtle text-danger border font-mono">
                                                <?= (int)$p['pending_jangads'] ?> બાકી
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-nowrap">
                                        <?php if ($isBlk): ?>
                                            <span class="badge bg-danger text-white border px-2 py-1 fw-bold shadow-xs" title="<?= e($p['block_reason'] ?? 'બ્લોક કરેલ') ?>">
                                                🛑 બ્લોક
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success border px-2 py-1">સક્રિય</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center table-actions pe-3 text-nowrap">
                                        <div class="d-inline-flex gap-1 align-items-center">
                                            <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-primary py-1 px-2" title="ખાતાવહી જુઓ">
                                                <?= svg_icon('receipt', 'me-1') ?>ખાતાવહી
                                            </a>
                                            <a href="<?= BASE_URL ?>/modules/parties/edit.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-secondary py-1 px-2" title="પાર્ટી એડિટ કરો">
                                                <?= svg_icon('pen-to-square', 'me-1') ?>એડિટ
                                            </a>
                                            <?php if ($isBlk): ?>
                                                <button type="button" class="btn btn-xs btn-success text-white py-1 px-2" onclick="unblockParty(<?= $p['id'] ?>, '<?= e(addslashes($p['party_name'])) ?>')" title="અન-બ્લોક કરો">
                                                    <?= svg_icon('check', 'me-1', 12) ?>અન-બ્લોક
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-xs btn-outline-danger py-1 px-2" onclick="openBlockModal(<?= $p['id'] ?>, '<?= e(addslashes($p['party_name'])) ?>')" title="બ્લોક કરો">
                                                    <?= svg_icon('ban', 'me-1', 12) ?>બ્લોક
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= render_table_pagination($pagData, BASE_URL . '/modules/parties/index.php', ['type' => $section] + (!empty($search) ? ['search' => $search] : []) + (!empty($subFilter) ? ['sub' => $subFilter] : []) + (!empty($cityFilter) ? ['city' => $cityFilter] : [])) ?>
    </div>
</div>

<!-- Block Confirmation Modal -->
<div class="modal fade" id="partyBlockModal" tabindex="-1" aria-labelledby="partyBlockModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="<?= BASE_URL ?>/modules/parties/toggle_block.php" class="modal-content border-danger shadow-lg">
            <?= csrf_field() ?>
            <input type="hidden" name="party_id" id="modalBlockPartyId" value="">
            <input type="hidden" name="action" value="block">
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold" id="partyBlockModalLabel">
                    <?= svg_icon('ban', 'me-2') ?>બ્લોક કરો (Block Confirmation)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-dark fw-semibold mb-2">
                    શું તમે ખરેખર <span class="text-danger fw-bold fs-6" id="modalBlockPartyName"></span> ને બ્લોક કરવા માંગો છો?
                </p>
                <div class="alert alert-warning small mb-3">
                    <?= svg_icon('triangle-exclamation', 'me-1') ?>
                    <strong>ચેતવણી:</strong> બ્લોક કર્યા પછી સિસ્ટમમાં આ પાર્ટી/દલાલના નામ પર કોઈપણ નવી ખરીદી, વેચાણ કે જાંગડ બની શકશે નહીં.
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">બ્લોક કરવાનું કારણ (Block Reason)</label>
                    <input type="text" name="reason" class="form-control" placeholder="દા.ત. પેમેન્ટ મોડું / વિવાદ / માલિકી નિર્ણય" required>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">રદ કરો</button>
                <button type="submit" class="btn btn-danger fw-bold px-4">હા, બ્લોક કરો</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden Unblock Form -->
<form id="unblockPartyForm" method="POST" action="<?= BASE_URL ?>/modules/parties/toggle_block.php" style="display: none;">
    <?= csrf_field() ?>
    <input type="hidden" name="party_id" id="unblockPartyId" value="">
    <input type="hidden" name="action" value="unblock">
    <input type="hidden" name="redirect" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
</form>

<script>
function openBlockModal(id, name) {
    document.getElementById('modalBlockPartyId').value = id;
    document.getElementById('modalBlockPartyName').innerText = "'" + name + "'";
    var myModal = new bootstrap.Modal(document.getElementById('partyBlockModal'));
    myModal.show();
}

function unblockParty(id, name) {
    if (confirm("શું તમે ખરેખર '" + name + "' ને અન-બ્લોક કરવા માંગો છો? અન-બ્લોક કર્યા પછી તેમના નામ પર ફરીથી ખરીદી, વેચાણ અને જાંગડ શરૂ થઈ જશે.")) {
        document.getElementById('unblockPartyId').value = id;
        document.getElementById('unblockPartyForm').submit();
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
