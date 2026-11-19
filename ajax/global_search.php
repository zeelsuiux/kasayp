<?php
/**
 * Global Intelligent Search Endpoint (Ctrl + K)
 * Diamond ERP - Instant multi-entity lookup
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = getDbConnection();
$q = trim((string)($_GET['q'] ?? ''));

$response = [
    'success' => true,
    'query' => $q,
    'categories' => []
];

// If empty query, return Quick Action Shortcuts
if (empty($q)) {
    $response['categories'][] = [
        'name' => 'ઝડપી એક્શન્સ (Quick Actions)',
        'icon' => 'bolt',
        'items' => [
            [
                'title' => 'નવી રફ ખરીદી (Add Rough Purchase)',
                'subtitle' => 'નવો રફ લોટ, વજન અને એ/સી ડિસ્કાઉન્ટ એન્ટ્રી',
                'url' => BASE_URL . '/modules/rough_purchase/create.php',
                'badge' => 'ખરીદી',
                'badge_color' => 'primary'
            ],
            [
                'title' => 'નવો જાંગડ મેમો (Issue Jangad Memo)',
                'subtitle' => 'કારીગર, ગેલેક્સી, એલએસ અથવા દલાલને માલ મોકલો',
                'url' => BASE_URL . '/modules/jangad/create.php',
                'badge' => 'જાંગડ',
                'badge_color' => 'indigo'
            ],
            [
                'title' => 'નવા Kapan બનાવો (Create Kapan)',
                'subtitle' => 'રફ લોટનું કાપનમાં વિભાજન અને પેકેટ્સ',
                'url' => BASE_URL . '/modules/kapan/create.php',
                'badge' => 'કાપન',
                'badge_color' => 'success'
            ],
            [
                'title' => 'આવક - જાવક રોજમેળ (Cash & Bank Book)',
                'subtitle' => 'રોકડ કેશ અને બેંક ખાતાઓની દૈનિક વાઉચર એન્ટ્રી',
                'url' => BASE_URL . '/modules/finance/transactions.php',
                'badge' => 'રોજમેળ',
                'badge_color' => 'warning'
            ],
            [
                'title' => 'નેટવર્થ બેલેન્સ શીટ (Live Net Worth)',
                'subtitle' => 'વાસ્તવિક લાઈવ મૂડી, સ્ટોક વેલ્યુએશન અને ઉઘરાણી',
                'url' => BASE_URL . '/modules/finance/networth.php',
                'badge' => 'નાણાં',
                'badge_color' => 'info'
            ]
        ]
    ];
    echo json_encode($response);
    exit;
}

$term = '%' . $q . '%';

// 1. Search Packets & Barcodes (પેકેટ્સ & બારકોડ)
$pktStmt = $pdo->prepare("
    SELECT p.id, p.packet_no, p.barcode, p.weight, p.current_stage, p.status, k.kapan_code
    FROM packets p
    LEFT JOIN kapans k ON p.kapan_id = k.id
    WHERE p.packet_no LIKE ? OR p.barcode LIKE ?
    ORDER BY p.id DESC
    LIMIT 5
");
$pktStmt->execute([$term, $term]);
$packets = $pktStmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($packets)) {
    $items = [];
    foreach ($packets as $pkt) {
        $items[] = [
            'title' => 'પેકેટ #' . $pkt['packet_no'] . ' (બારકોડ: ' . ($pkt['barcode'] ?: 'N/A') . ')',
            'subtitle' => number_format((float)$pkt['weight'], 3) . ' ct • Kapan: ' . ($pkt['kapan_code'] ?: 'N/A') . ' • સ્ટેજ: ' . $pkt['current_stage'],
            'url' => BASE_URL . '/modules/process/barcode_scanner.php?barcode=' . urlencode($pkt['barcode'] ?: $pkt['packet_no']),
            'badge' => 'પેકેટ / હીરો',
            'badge_color' => 'indigo'
        ];
    }
    $response['categories'][] = [
        'name' => 'પેકેટ્સ & બારકોડ (Packets & Diamonds)',
        'icon' => 'barcode',
        'items' => $items
    ];
}

// 2. Search Parties, Brokers, Outsource Units (પાર્ટી & દલાલ ડિરેક્ટરી)
$partyStmt = $pdo->prepare("
    SELECT id, party_name, party_type, city, phone, current_balance
    FROM parties 
    WHERE party_name LIKE ? OR phone LIKE ? OR city LIKE ?
    ORDER BY party_name ASC 
    LIMIT 5
");
$partyStmt->execute([$term, $term, $term]);
$parties = $partyStmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($parties)) {
    $items = [];
    foreach ($parties as $p) {
        $typeLabel = match($p['party_type'] ?? '') {
            'broker' => 'દલાલ (Broker)',
            'outsource' => 'આઉટસોર્સ યુનિટ',
            default => 'પાર્ટી / ગ્રાહક'
        };
        $items[] = [
            'title' => $p['party_name'],
            'subtitle' => $typeLabel . ($p['city'] ? ' • ' . $p['city'] : '') . ($p['phone'] ? ' • 📞 ' . $p['phone'] : ''),
            'url' => BASE_URL . '/modules/parties/view.php?id=' . $p['id'],
            'badge' => $typeLabel,
            'badge_color' => ($p['party_type'] ?? '') === 'broker' ? 'warning' : (($p['party_type'] ?? '') === 'outsource' ? 'danger' : 'primary')
        ];
    }
    $response['categories'][] = [
        'name' => 'પાર્ટી & દલાલ ડિરેક્ટરી (Parties)',
        'icon' => 'users',
        'items' => $items
    ];
}

// 3. Search Bill Numbers: Rough, Polish, Sales, Jangad (ખરીદી-વેચાણ બિલ નંબરો)
$billItems = [];

// 3a. Rough Purchases
$roughStmt = $pdo->prepare("
    SELECT rp.id, rp.purchase_no, rp.purchase_date, rp.weight, rp.pcs, rp.net_payment, p.party_name
    FROM rough_purchases rp
    LEFT JOIN parties p ON rp.party_id = p.id
    WHERE rp.purchase_no LIKE ? OR p.party_name LIKE ?
    ORDER BY rp.id DESC
    LIMIT 3
");
$roughStmt->execute([$term, $term]);
$roughs = $roughStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($roughs as $r) {
    $billItems[] = [
        'title' => 'Rough ખરીદી #' . $r['purchase_no'] . ' • ' . $r['party_name'],
        'subtitle' => number_format((float)$r['weight'], 2) . ' ct • ₹ ' . number_format((float)$r['net_payment'], 2) . ' • ' . $r['purchase_date'],
        'url' => BASE_URL . '/modules/rough_purchase/view.php?id=' . $r['id'],
        'badge' => 'Rough Buy',
        'badge_color' => 'info'
    ];
}

// 3b. Polish Purchases
$polishPurStmt = $pdo->prepare("
    SELECT pp.id, pp.purchase_no, pp.purchase_date, pp.weight, pp.pcs, pp.net_payment, p.party_name
    FROM polish_purchases pp
    LEFT JOIN parties p ON pp.party_id = p.id
    WHERE pp.purchase_no LIKE ? OR p.party_name LIKE ?
    ORDER BY pp.id DESC
    LIMIT 3
");
$polishPurStmt->execute([$term, $term]);
$polishPurs = $polishPurStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($polishPurs as $pp) {
    $billItems[] = [
        'title' => 'Polish ખરીદી #' . $pp['purchase_no'] . ' • ' . $pp['party_name'],
        'subtitle' => number_format((float)$pp['weight'], 3) . ' ct • ₹ ' . number_format((float)$pp['net_payment'], 2) . ' • ' . $pp['purchase_date'],
        'url' => BASE_URL . '/modules/polish/index.php?search=' . urlencode($pp['purchase_no']),
        'badge' => 'Polish Buy',
        'badge_color' => 'success'
    ];
}

// 3c. Sales Invoices (Rough & Polish)
$salesStmt = $pdo->prepare("
    SELECT s.id, s.invoice_no, s.sale_date, s.weight, s.net_amount, p.party_name
    FROM polish_sales s
    LEFT JOIN parties p ON s.party_id = p.id
    WHERE s.invoice_no LIKE ? OR p.party_name LIKE ?
    ORDER BY s.id DESC
    LIMIT 3
");
$salesStmt->execute([$term, $term]);
$sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($sales as $s) {
    $billItems[] = [
        'title' => 'વેચાણ બિલ #' . $s['invoice_no'] . ' • ' . $s['party_name'],
        'subtitle' => number_format((float)$s['weight'], 3) . ' ct • ₹ ' . number_format((float)$s['net_amount'], 2) . ' • ' . $s['sale_date'],
        'url' => BASE_URL . '/modules/polish/index.php?tab=gia_sell&search=' . urlencode($s['invoice_no']),
        'badge' => 'વેચાણ બિલ',
        'badge_color' => 'primary'
    ];
}

// 3d. Jangad Memos
$jangadStmt = $pdo->prepare("
    SELECT j.id, j.jangad_no, j.voucher_type, j.issue_date, j.total_pcs, j.total_weight, p.party_name
    FROM jangad_vouchers j
    LEFT JOIN parties p ON j.party_id = p.id
    WHERE j.jangad_no LIKE ? OR p.party_name LIKE ?
    ORDER BY j.id DESC
    LIMIT 3
");
$jangadStmt->execute([$term, $term]);
$jangads = $jangadStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($jangads as $j) {
    $billItems[] = [
        'title' => 'જાંગડ મેમો #' . $j['jangad_no'] . ' • ' . $j['party_name'],
        'subtitle' => (int)$j['total_pcs'] . ' Pcs • ' . number_format((float)$j['total_weight'], 3) . ' ct • ' . $j['issue_date'],
        'url' => BASE_URL . '/modules/jangad/view.php?id=' . $j['id'],
        'badge' => 'Jangad Memo',
        'badge_color' => 'indigo'
    ];
}

if (!empty($billItems)) {
    $response['categories'][] = [
        'name' => 'બિલ નંબરો & વાઉચર્સ (Bill Numbers & Invoices)',
        'icon' => 'receipt',
        'items' => $billItems
    ];
}

// 4. Search Kapans
$kapanStmt = $pdo->prepare("
    SELECT id, kapan_code, kapan_name, total_pcs, total_weight, rough_cost, status
    FROM kapans
    WHERE kapan_code LIKE ? OR kapan_name LIKE ?
    ORDER BY id DESC
    LIMIT 5
");
$kapanStmt->execute([$term, $term]);
$kapans = $kapanStmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($kapans)) {
    $items = [];
    foreach ($kapans as $k) {
        $items[] = [
            'title' => 'કાપન: ' . ($k['kapan_code'] ?: $k['kapan_name']),
            'subtitle' => (int)$k['total_pcs'] . ' Pcs • ' . number_format((float)$k['total_weight'], 3) . ' ct • Status: ' . $k['status'],
            'url' => BASE_URL . '/modules/kapan/view.php?id=' . $k['id'],
            'badge' => 'Kapan',
            'badge_color' => 'success'
        ];
    }
    $response['categories'][] = [
        'name' => 'Kapan પેકેટ્સ',
        'icon' => 'boxes-stacked',
        'items' => $items
    ];
}

echo json_encode($response);
