<?php
/**
 * Universal Excel / CSV Export Engine (સેક્શન ૭ ડેટા એક્સપોર્ટ)
 * Kasyap Diamond ERP - Surat & Mumbai
 * Generates Microsoft Excel compatible CSV with UTF-8 BOM
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
$user = current_user();
$pdo = getDbConnection();

$type = trim($_GET['type'] ?? 'transactions');
$todayStr = date('Y-m-d');

// Helper to output UTF-8 CSV headers
function sendCsvHeaders(string $filename): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    // Output UTF-8 Byte Order Mark (BOM) so Excel reads Gujarati Unicode characters flawlessly
    echo "\xEF\xBB\xBF";
}

switch ($type) {
    // -------------------------------------------------------------------------
    // 1. Cash & Bank Book / Transactions (આવક - જાવક રોજમેળ)
    // -------------------------------------------------------------------------
    case 'transactions':
        $search     = trim($_GET['search'] ?? '');
        $typeFilter = trim($_GET['type_filter'] ?? $_GET['type'] ?? '');
        if ($typeFilter === 'transactions') $typeFilter = ''; // avoid collision with export type
        $accFilter  = (int)($_GET['account_id'] ?? 0);
        $fromDate   = trim($_GET['from_date'] ?? '');
        $toDate     = trim($_GET['to_date'] ?? '');

        $sql = "SELECT t.*, a.account_name, a.account_type, p.party_name, u.full_name as created_user
                FROM account_transactions t
                JOIN bank_accounts a ON t.bank_account_id = a.id
                LEFT JOIN parties p ON t.party_id = p.id
                LEFT JOIN users u ON t.created_by = u.id
                WHERE 1=1";
        $params = [];

        if (!empty($fromDate)) {
            $sql .= " AND t.transaction_date >= ?";
            $params[] = $fromDate;
        }
        if (!empty($toDate)) {
            $sql .= " AND t.transaction_date <= ?";
            $params[] = $toDate;
        }
        if ($accFilter > 0) {
            $sql .= " AND t.bank_account_id = ?";
            $params[] = $accFilter;
        }
        if (!empty($typeFilter) && in_array($typeFilter, ['credit', 'debit'])) {
            $sql .= " AND t.type = ?";
            $params[] = $typeFilter;
        }
        if (!empty($search)) {
            $sql .= " AND (t.reference_no LIKE ? OR t.notes LIKE ? OR t.category LIKE ? OR p.party_name LIKE ?)";
            $term = "%$search%";
            $params = array_merge($params, [$term, $term, $term, $term]);
        }

        $sql .= " ORDER BY t.transaction_date DESC, t.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        sendCsvHeaders("Aavak_Javak_Rojmel_{$todayStr}.csv");
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'વાઉચર નં (Voucher No)',
            'તારીખ (Date)',
            'ખાતું (Bank/Cash Account)',
            'ખાતાનો પ્રકાર (Account Type)',
            'પ્રકાર (Type)',
            'કેટેગરી (Category)',
            'પાર્ટી / વ્યક્તિ (Party/Person)',
            'રકમ ₹ (Amount INR)',
            'પેમેન્ટ મોડ (Payment Mode)',
            'સંદર્ભ / રેફરન્સ નં (Reference No)',
            'શેરો / વિગત (Notes)',
            'એન્ટ્રી કરનાર (Created By)'
        ]);

        foreach ($rows as $r) {
            $typeLabel = ($r['type'] === 'credit') ? 'આવક (Credit / In)' : 'જાવક (Debit / Out)';
            fputcsv($out, [
                'V-' . str_pad((string)$r['id'], 5, '0', STR_PAD_LEFT),
                $r['transaction_date'],
                $r['account_name'],
                strtoupper($r['account_type']),
                $typeLabel,
                $r['category'] ?? 'General',
                $r['party_name'] ?? '-',
                number_format((float)$r['amount'], 2, '.', ''),
                strtoupper($r['payment_mode'] ?? 'CASH'),
                $r['reference_no'] ?? '-',
                $r['notes'] ?? '',
                $r['created_user'] ?? 'System'
            ]);
        }
        fclose($out);
        log_activity('Excel Export', 'Finance', null, 'Exported ' . count($rows) . ' transaction records to Excel');
        exit;

    // -------------------------------------------------------------------------
    // 2. Polish Stock Inventory (હાજર પોલિશ હીરા સ્ટોક)
    // -------------------------------------------------------------------------
    case 'polish_stock':
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $typeF  = trim($_GET['stock_type'] ?? '');

        $sql = "SELECT ps.*, k.kapan_code, k.kapan_name 
                FROM polish_stocks ps
                LEFT JOIN kapans k ON ps.kapan_id = k.id
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (ps.packet_no LIKE ? OR ps.gia_number LIKE ? OR ps.shape LIKE ? OR ps.color LIKE ? OR ps.clarity LIKE ?)";
            $term = "%$search%";
            $params = array_merge($params, [$term, $term, $term, $term, $term]);
        }
        if (!empty($status)) {
            $sql .= " AND ps.status = ?";
            $params[] = $status;
        }
        if (!empty($typeF)) {
            $sql .= " AND ps.stock_type = ?";
            $params[] = $typeF;
        }

        $sql .= " ORDER BY ps.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        sendCsvHeaders("Polish_Stock_Inventory_{$todayStr}.csv");
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'પેકેટ નં (Packet No)',
            'સ્ટોક પ્રકાર (Stock Type)',
            'GIA સર્ટિ નં (GIA Number)',
            'આકાર (Shape)',
            'કલર (Color)',
            'ક્લેરિટી (Clarity)',
            'કટ (Cut)',
            'સાઈઝ / વર્ણન (Description)',
            'હાજર નંગ (Pcs)',
            'હાજર વજન કેરેટ (Carats)',
            'પડતર ભાવ પ્રતિ કેરેટ ₹ (Cost/Ct INR)',
            'કુલ પડતર મૂલ્ય ₹ (Total Cost INR)',
            'અપેક્ષિત વેચાણ ભાવ ₹ (Sale Price/Ct)',
            'હાલની જગ્યા (Current Location)',
            'સ્થિતિ (Status)',
            'Kapan સંદર્ભ (Kapan Reference)'
        ]);

        foreach ($rows as $r) {
            $wt   = (float)$r['available_weight'];
            $cost = (float)$r['cost_per_carat'];
            $totVal = round($wt * $cost, 2);

            fputcsv($out, [
                $r['packet_no'],
                strtoupper(str_replace('_', ' ', $r['stock_type'])),
                $r['gia_number'] ?? '-',
                $r['shape'] ?? '-',
                $r['color'] ?? '-',
                $r['clarity'] ?? '-',
                $r['cut'] ?? '-',
                $r['size_description'] ?? '-',
                (int)$r['available_pcs'],
                number_format($wt, 3, '.', ''),
                number_format($cost, 2, '.', ''),
                number_format($totVal, 2, '.', ''),
                number_format((float)$r['sale_price_per_carat'], 2, '.', ''),
                $r['current_location'] ?? 'Surat',
                strtoupper(str_replace('_', ' ', $r['status'])),
                ($r['kapan_code'] ? $r['kapan_code'] . ' - ' . $r['kapan_name'] : '-')
            ]);
        }
        fclose($out);
        log_activity('Excel Export', 'Inventory', null, 'Exported ' . count($rows) . ' polish stock records to Excel');
        exit;

    // -------------------------------------------------------------------------
    // 3. Rough Purchase Register (રફ ખરીદી રજિસ્ટર)
    // -------------------------------------------------------------------------
    case 'rough_purchase':
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');
        $tagFilter = trim($_GET['tag'] ?? '');
        $processTagFilter = trim($_GET['process_tag'] ?? '');

        $sql = "SELECT rp.*, p.party_name, b.party_name as broker_name 
                FROM rough_purchases rp
                JOIN parties p ON rp.party_id = p.id
                LEFT JOIN parties b ON rp.broker_id = b.id
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (rp.purchase_no LIKE ? OR p.party_name LIKE ? OR rp.product_description LIKE ?)";
            $term = "%$search%";
            $params = array_merge($params, [$term, $term, $term]);
        }
        if (!empty($status)) {
            $sql .= " AND rp.status = ?";
            $params[] = $status;
        }
        if (!empty($tagFilter)) {
            $sql .= " AND rp.tag = ?";
            $params[] = $tagFilter;
        }
        if (!empty($processTagFilter)) {
            $sql .= " AND rp.process_tag = ?";
            $params[] = $processTagFilter;
        }

        $sql .= " ORDER BY rp.purchase_date DESC, rp.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        sendCsvHeaders("Rough_Purchase_Register_{$todayStr}.csv");
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'ખરીદી નં (Purchase No)',
            'તારીખ (Date)',
            'પાર્ટીનું નામ (Supplier Party)',
            'TAG (ટેગ)',
            'PROCESS TAG (પ્રોસેસ ટેગ)',
            'દલાલ (Broker)',
            'નંગ (Pcs)',
            'કુલ વજન (Carats)',
            'હાજર વજન (Available Ct)',
            'ભાવ / ct ₹ (Price/Ct INR)',
            'ડોલર ભાવ ($/ct)',
            'ડોલર દર ($ Rate)',
            'મૂળ ખરીદી રકમ ₹ (Gross Amount)',
            'દલાલી % (Brokerage %)',
            'દલાલી રકમ ₹ (Brokerage INR)',
            'GST રકમ ₹ (GST INR)',
            'કુલ નેટ ચૂકવણી ₹ (Net Payment INR)',
            'મુદત દિવસ (Due Days)',
            'ચૂકવણી તારીખ (Due Date)',
            'પાર્ટી ચુકવણી સ્થિતિ (Payment Status)',
            'ચૂકવેલ રકમ ₹ (Paid Amount)',
            'ચૂકવ્યા તારીખ (Payment Date)',
            'દલાલી સ્થિતિ (Brokerage Status)',
            'સ્ટોક સ્થિતિ (Stock Status)',
            'વિગત / નોટ્સ (Description)'
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['purchase_no'],
                $r['purchase_date'],
                $r['party_name'],
                $r['tag'] ?? 'Rough',
                $r['process_tag'] ?? 'Assort',
                $r['broker_name'] ?? '-',
                (int)$r['pcs'],
                number_format((float)$r['weight'], 3, '.', ''),
                number_format((float)$r['available_weight'], 3, '.', ''),
                number_format((float)$r['price_per_carat_inr'], 2, '.', ''),
                number_format((float)$r['dollar_per_carat'], 2, '.', ''),
                number_format((float)$r['dollar_rate'], 2, '.', ''),
                number_format((float)$r['payment_amount'], 2, '.', ''),
                number_format((float)$r['brokerage_percent'], 2, '.', ''),
                number_format((float)$r['brokerage_amount'], 2, '.', ''),
                number_format((float)$r['ac_gst_amount'], 2, '.', ''),
                number_format((float)$r['net_payment'], 2, '.', ''),
                (int)$r['due_days'],
                $r['due_date'],
                strtoupper($r['party_payment_status']),
                number_format((float)$r['paid_amount'], 2, '.', ''),
                $r['chukvya_date'] ?? '-',
                strtoupper($r['brokerage_payment_status']),
                strtoupper(str_replace('_', ' ', $r['status'])),
                $r['product_description'] ?? ''
            ]);
        }
        fclose($out);
        log_activity('Excel Export', 'Purchase', null, 'Exported ' . count($rows) . ' rough purchase records to Excel');
        exit;

    // -------------------------------------------------------------------------
    // 4. Polish Purchase Register (પોલિશ ખરીદી રજિસ્ટર)
    // -------------------------------------------------------------------------
    case 'polish_purchase':
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $sql = "SELECT pp.*, p.party_name, b.party_name as broker_name 
                FROM polish_purchases pp
                JOIN parties p ON pp.party_id = p.id
                LEFT JOIN parties b ON pp.broker_id = b.id
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (pp.purchase_no LIKE ? OR p.party_name LIKE ? OR pp.gia_number LIKE ?)";
            $term = "%$search%";
            $params = array_merge($params, [$term, $term, $term]);
        }
        if (!empty($status)) {
            $sql .= " AND pp.payment_status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY pp.purchase_date DESC, pp.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        sendCsvHeaders("Polish_Purchase_Register_{$todayStr}.csv");
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'ખરીદી નં (Purchase No)',
            'તારીખ (Date)',
            'પાર્ટીનું નામ (Supplier Party)',
            'દલાલ (Broker)',
            'સ્ટોક પ્રકાર (Stock Type)',
            'GIA નં (GIA No)',
            'આકાર (Shape)',
            'કલર (Color)',
            'ક્લેરિટી (Clarity)',
            'નંગ (Pcs)',
            'વજન કેરેટ (Carats)',
            'ભાવ / ct ₹ (Price/Ct INR)',
            'ખરીદી રકમ ₹ (Gross Amount)',
            'દલાલી રકમ ₹ (Brokerage INR)',
            'GST રકમ ₹ (GST INR)',
            'નેટ ચુકવણી ₹ (Net Payment INR)',
            'મુદત દિવસ (Due Days)',
            'ચુકવણી તારીખ (Due Date)',
            'સ્થિતિ (Payment Status)',
            'ચૂકવેલ રકમ ₹ (Paid Amount)'
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['purchase_no'],
                $r['purchase_date'],
                $r['party_name'],
                $r['broker_name'] ?? '-',
                strtoupper(str_replace('_', ' ', $r['stock_type'])),
                $r['gia_number'] ?? '-',
                $r['shape'] ?? '-',
                $r['color'] ?? '-',
                $r['clarity'] ?? '-',
                (int)$r['pcs'],
                number_format((float)$r['weight'], 3, '.', ''),
                number_format((float)$r['price_per_carat_inr'], 2, '.', ''),
                number_format((float)$r['payment_amount'], 2, '.', ''),
                number_format((float)$r['brokerage_amount'], 2, '.', ''),
                number_format((float)$r['ac_gst_amount'], 2, '.', ''),
                number_format((float)$r['net_payment'], 2, '.', ''),
                (int)$r['due_days'],
                $r['due_date'],
                strtoupper($r['payment_status']),
                number_format((float)$r['paid_amount'], 2, '.', '')
            ]);
        }
        fclose($out);
        log_activity('Excel Export', 'Purchase', null, 'Exported ' . count($rows) . ' polish purchase records to Excel');
        exit;

    // -------------------------------------------------------------------------
    // 5. Polish Sales Register (પોલિશ વેચાણ રજિસ્ટર)
    // -------------------------------------------------------------------------
    case 'polish_sales':
        $search = trim($_GET['search'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $sql = "SELECT ps.*, p.party_name, b.party_name as broker_name, stk.packet_no, stk.shape, stk.color, stk.clarity
                FROM polish_sales ps
                JOIN parties p ON ps.party_id = p.id
                LEFT JOIN parties b ON ps.broker_id = b.id
                LEFT JOIN polish_stocks stk ON ps.polish_stock_id = stk.id
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (ps.invoice_no LIKE ? OR p.party_name LIKE ? OR stk.packet_no LIKE ?)";
            $term = "%$search%";
            $params = array_merge($params, [$term, $term, $term]);
        }
        if (!empty($status)) {
            $sql .= " AND ps.payment_status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY ps.sale_date DESC, ps.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        sendCsvHeaders("Polish_Sales_Register_{$todayStr}.csv");
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'ઇન્વોઇસ નં (Invoice No)',
            'વેચાણ તારીખ (Sale Date)',
            'ગ્રાહક પાર્ટી (Customer Party)',
            'દલાલ (Broker)',
            'સ્ટોક પેકેટ નં (Packet No)',
            '4Cs વિગત (Shape / Color / Clarity)',
            'નંગ (Pcs)',
            'વજન કેરેટ (Carats)',
            'ભાવ / ct ₹ (Price/Ct INR)',
            'મૂળ રકમ ₹ (Gross Amount)',
            'દલાલી રકમ ₹ (Brokerage INR)',
            'GST રકમ ₹ (GST INR)',
            'કુલ નેટ લેણી રકમ ₹ (Net Receivable INR)',
            'મુદત દિવસ (Due Days)',
            'લેણાં તારીખ (Due Date)',
            'લેણાં સ્થિતિ (Payment Status)',
            'જમા થયેલ રકમ ₹ (Received Amount)'
        ]);

        foreach ($rows as $r) {
            $desc = ($r['shape'] ?? '') . ' ' . ($r['color'] ?? '') . ' ' . ($r['clarity'] ?? '');
            fputcsv($out, [
                $r['invoice_no'],
                $r['sale_date'],
                $r['party_name'],
                $r['broker_name'] ?? '-',
                $r['packet_no'] ?? '-',
                trim($desc) ?: '-',
                (int)$r['pcs'],
                number_format((float)$r['weight'], 3, '.', ''),
                number_format((float)$r['price_per_carat_inr'], 2, '.', ''),
                number_format((float)$r['payment_amount'], 2, '.', ''),
                number_format((float)$r['brokerage_amount'], 2, '.', ''),
                number_format((float)$r['ac_gst_amount'], 2, '.', ''),
                number_format((float)$r['net_amount'], 2, '.', ''),
                (int)$r['due_days'],
                $r['due_date'],
                strtoupper($r['payment_status']),
                number_format((float)$r['received_amount'], 2, '.', '')
            ]);
        }
        fclose($out);
        log_activity('Excel Export', 'Sales', null, 'Exported ' . count($rows) . ' polish sales records to Excel');
        exit;

    // -------------------------------------------------------------------------
    // 6. Rough Sales Register (રફ વેચાણ રજિસ્ટર)
    // -------------------------------------------------------------------------
    case 'rough_sales':
        $search = trim($_GET['search'] ?? '');

        $sql = "SELECT rs.*, p.party_name, b.party_name as broker_name 
                FROM rough_sales rs
                JOIN parties p ON rs.party_id = p.id
                LEFT JOIN parties b ON rs.broker_id = b.id
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (rs.invoice_no LIKE ? OR p.party_name LIKE ?)";
            $term = "%$search%";
            $params = array_merge($params, [$term, $term]);
        }

        $sql .= " ORDER BY rs.sale_date DESC, rs.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        sendCsvHeaders("Rough_Sales_Register_{$todayStr}.csv");
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'ઇન્વોઇસ નં (Invoice No)',
            'વેચાણ તારીખ (Sale Date)',
            'ગ્રાહક પાર્ટી (Customer Party)',
            'દલાલ (Broker)',
            'સ્ત્રોત (Source)',
            'નંગ (Pcs)',
            'વજન કેરેટ (Carats)',
            'ભાવ / ct ₹ (Price/Ct INR)',
            'મૂળ રકમ ₹ (Gross Amount)',
            'દલાલી રકમ ₹ (Brokerage INR)',
            'GST રકમ ₹ (GST INR)',
            'કુલ નેટ રકમ ₹ (Net Amount INR)',
            'મુદત દિવસ (Due Days)',
            'Due Date',
            'સ્થિતિ (Payment Status)',
            'જમા થયેલ રકમ ₹ (Received Amount)'
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['invoice_no'],
                $r['sale_date'],
                $r['party_name'],
                $r['broker_name'] ?? '-',
                strtoupper($r['source_type'] ?? 'direct'),
                (int)$r['pcs'],
                number_format((float)$r['weight'], 3, '.', ''),
                number_format((float)$r['price_per_carat_inr'], 2, '.', ''),
                number_format((float)$r['payment_amount'], 2, '.', ''),
                number_format((float)$r['brokerage_amount'], 2, '.', ''),
                number_format((float)$r['ac_gst_amount'], 2, '.', ''),
                number_format((float)$r['net_amount'], 2, '.', ''),
                (int)$r['due_days'],
                $r['due_date'],
                strtoupper($r['payment_status']),
                number_format((float)$r['received_amount'], 2, '.', '')
            ]);
        }
        fclose($out);
        log_activity('Excel Export', 'Sales', null, 'Exported ' . count($rows) . ' rough sales records to Excel');
        exit;

    // -------------------------------------------------------------------------
    // 7. Parties & Brokers Directory (પાર્ટી અને દલાલ લિસ્ટ)
    // -------------------------------------------------------------------------
    case 'parties':
        $search = trim($_GET['search'] ?? '');
        $pType  = trim($_GET['party_type'] ?? $_GET['type_filter'] ?? '');

        $sql = "SELECT * FROM parties WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (party_name LIKE ? OR contact_person LIKE ? OR phone LIKE ? OR city LIKE ?)";
            $term = "%$search%";
            $params = array_merge($params, [$term, $term, $term, $term]);
        }
        if (!empty($pType)) {
            $sql .= " AND party_type = ?";
            $params[] = $pType;
        }

        $sql .= " ORDER BY party_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        sendCsvHeaders("Parties_and_Brokers_{$todayStr}.csv");
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'પાર્ટી ID (ID)',
            'પેઢી / પાર્ટીનું નામ (Party Name)',
            'પ્રકાર (Type)',
            'સંપર્ક વ્યક્તિ (Contact Person)',
            'મોબાઈલ નંબર (Phone)',
            'ઈમેલ (Email)',
            'GST નંબર (GSTIN)',
            'PAN નંબર (PAN No)',
            'શહેર (City)',
            'સરનામું (Address)',
            'હાલનું બેલેન્સ ₹ (Current Balance INR)',
            'ખાતા સ્થિતિ (Balance Status)',
            'સ્ટેટસ (Status)'
        ]);

        foreach ($rows as $r) {
            $bal = (float)$r['current_balance'];
            $balStatus = ($bal > 0) ? 'લેણાં (Receivable)' : (($bal < 0) ? 'દેવાં (Payable)' : 'હિસાબ સરભર (Nil)');
            fputcsv($out, [
                $r['id'],
                $r['party_name'],
                strtoupper($r['party_type']),
                $r['contact_person'] ?? '-',
                $r['phone'] ?? '-',
                $r['email'] ?? '-',
                $r['gst_number'] ?? '-',
                $r['pan_number'] ?? '-',
                $r['city'] ?? '-',
                $r['address'] ?? '-',
                number_format($bal, 2, '.', ''),
                $balStatus,
                strtoupper($r['status'] ?? 'active')
            ]);
        }
        fclose($out);
        log_activity('Excel Export', 'Parties', null, 'Exported ' . count($rows) . ' parties to Excel');
        exit;

    // -------------------------------------------------------------------------
    // 8. Payment Schedule (ચુકવણી મુદત યાદી)
    // -------------------------------------------------------------------------
    case 'payment_schedule':
        $filter = trim($_GET['filter'] ?? 'pending');
        $currFilter = trim($_GET['curr'] ?? 'all');
        $dirFilter = trim($_GET['direction'] ?? 'all');

        $sql = "
            (SELECT 'Rough Purchase' as bill_type, rp.purchase_no as doc_no, rp.purchase_date as doc_date,
                    p.party_name, p.phone, rp.net_payment as total_amount, rp.paid_amount,
                    (rp.net_payment - rp.paid_amount) as pending_amount, rp.due_days, rp.due_date,
                    rp.party_payment_status as status, DATEDIFF(rp.due_date, CURDATE()) as days_left,
                    rp.payment_currency, rp.dollar_per_carat, rp.dollar_rate, rp.weight,
                    ROUND(COALESCE(rp.weight, 0) * COALESCE(rp.dollar_per_carat, 0), 2) as dollar_total,
                    'ચૂકવવાના (Payable)' as direction
             FROM rough_purchases rp
             JOIN parties p ON rp.party_id = p.id)
            UNION ALL
            (SELECT 'Polish Purchase' as bill_type, pp.purchase_no as doc_no, pp.purchase_date as doc_date,
                    p.party_name, p.phone, pp.net_payment as total_amount, pp.paid_amount,
                    (pp.net_payment - pp.paid_amount) as pending_amount, pp.due_days, pp.due_date,
                    pp.payment_status as status, DATEDIFF(pp.due_date, CURDATE()) as days_left,
                    pp.payment_currency, pp.dollar_per_carat, pp.dollar_rate, pp.weight,
                    ROUND(COALESCE(pp.weight, 0) * COALESCE(pp.dollar_per_carat, 0), 2) as dollar_total,
                    'ચૂકવવાના (Payable)' as direction
             FROM polish_purchases pp
             JOIN parties p ON pp.party_id = p.id)
            UNION ALL
            (SELECT 'Rough Sale' as bill_type, rs.invoice_no as doc_no, rs.sale_date as doc_date,
                    p.party_name, p.phone, rs.net_amount as total_amount, rs.received_amount as paid_amount,
                    (rs.net_amount - rs.received_amount) as pending_amount, rs.due_days, rs.due_date,
                    rs.payment_status as status, DATEDIFF(rs.due_date, CURDATE()) as days_left,
                    rs.payment_currency, rs.dollar_per_carat, rs.dollar_rate, rs.weight,
                    ROUND(COALESCE(rs.weight, 0) * COALESCE(rs.dollar_per_carat, 0), 2) as dollar_total,
                    'લેવાના (Receivable)' as direction
             FROM rough_sales rs
             JOIN parties p ON rs.party_id = p.id)
            UNION ALL
            (SELECT 'Polish Sale' as bill_type, ps.invoice_no as doc_no, ps.sale_date as doc_date,
                    p.party_name, p.phone, ps.net_amount as total_amount, ps.received_amount as paid_amount,
                    (ps.net_amount - ps.received_amount) as pending_amount, ps.due_days, ps.due_date,
                    ps.payment_status as status, DATEDIFF(ps.due_date, CURDATE()) as days_left,
                    ps.payment_currency, ps.dollar_per_carat, ps.dollar_rate, ps.weight,
                    ROUND(COALESCE(ps.weight, 0) * COALESCE(ps.dollar_per_carat, 0), 2) as dollar_total,
                    'લેવાના (Receivable)' as direction
             FROM polish_sales ps
             JOIN parties p ON ps.party_id = p.id)
        ";

        $whereClause = "WHERE 1=1";
        if ($filter === 'overdue') {
            $whereClause .= " AND status != 'paid' AND days_left < 0";
        } elseif ($filter === 'due7') {
            $whereClause .= " AND status != 'paid' AND days_left >= 0 AND days_left <= 7";
        } elseif ($filter === 'paid') {
            $whereClause .= " AND status = 'paid'";
        } elseif ($filter === 'pending') {
            $whereClause .= " AND status != 'paid'";
        }

        if ($dirFilter === 'payable') {
            $whereClause .= " AND direction LIKE '%Payable%'";
        } elseif ($dirFilter === 'receivable') {
            $whereClause .= " AND direction LIKE '%Receivable%'";
        }

        if ($currFilter === 'inr') {
            $whereClause .= " AND NOT ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)";
        } elseif ($currFilter === 'usd') {
            $whereClause .= " AND ((payment_currency = 'USD' OR COALESCE(dollar_per_carat, 0) > 0) AND COALESCE(dollar_rate, 0) <= 0)";
        }

        $sql = "SELECT * FROM ($sql) as t $whereClause ORDER BY due_date ASC";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        sendCsvHeaders("Payment_Schedule_{$filter}_{$todayStr}.csv");
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'બિલ પ્રકાર (Bill Type)',
            'બિલ નં (Bill No)',
            'બિલ તારીખ (Bill Date)',
            'પાર્ટીનું નામ (Party Name)',
            'ફોન નંબર (Phone)',
            'દિશા (Payable / Receivable)',
            'કરન્સી (Currency Mode)',
            'કુલ રકમ ₹ (INR Total)',
            'ચૂકવેલ રકમ ₹ (INR Paid)',
            'બાકી રકમ ₹ (INR Pending)',
            'Stand Dollar કુલ $ (USD Total)',
            'મુદત દિવસો (Due Days)',
            'ચુકવણી તારીખ (Due Date)',
            'બાકી દિવસો (Days Left / Overdue)',
            'સ્થિતિ (Status)'
        ]);

        foreach ($rows as $r) {
            $dl = (int)$r['days_left'];
            $daysMsg = ($dl < 0) ? (abs($dl) . ' દિવસ વિલંબ (Overdue)') : ($dl . ' દિવસ બાકી (Days Left)');
            $isStand = is_stand_dollar($r);
            $currLabel = $isStand ? 'Stand Dollar ($ દર બાકી)' : ((($r['payment_currency'] ?? '') === 'USD') ? 'USD ($ Locked)' : 'INR (₹)');

            fputcsv($out, [
                $r['bill_type'],
                $r['doc_no'],
                $r['doc_date'],
                $r['party_name'],
                $r['phone'] ?? '-',
                $r['direction'],
                $currLabel,
                number_format((float)$r['total_amount'], 2, '.', ''),
                number_format((float)$r['paid_amount'], 2, '.', ''),
                number_format((float)$r['pending_amount'], 2, '.', ''),
                $isStand ? ('$' . number_format((float)$r['dollar_total'], 2, '.', '')) : '-',
                (int)$r['due_days'],
                $r['due_date'],
                $daysMsg,
                strtoupper($r['status'])
            ]);
        }
        fclose($out);
        log_activity('Excel Export', 'Finance', null, 'Exported ' . count($rows) . ' payment schedule rows to Excel');
        exit;

    // -------------------------------------------------------------------------
    // 9. Jangad Outstanding by Party (પાર્ટી વાઇઝ બાકી જાંગડ માલ)
    // -------------------------------------------------------------------------
    case 'jangad_outstanding':
        $sql = "SELECT p.id as party_id, p.party_name, p.phone, p.city,
                       COUNT(jv.id) as voucher_count,
                       SUM(jv.total_pcs) as total_issued_pcs,
                       SUM(jv.total_weight) as total_issued_weight,
                       SUM(jv.returned_pcs) as total_returned_pcs,
                       SUM(jv.returned_weight) as total_returned_weight,
                       SUM(jv.total_pcs - jv.returned_pcs) as pending_pcs,
                       SUM(jv.total_weight - jv.returned_weight) as pending_weight
                FROM jangad_vouchers jv
                JOIN parties p ON jv.party_id = p.id
                WHERE jv.status IN ('issued', 'partially_returned')
                GROUP BY p.id, p.party_name, p.phone, p.city
                HAVING pending_weight > 0.001
                ORDER BY pending_weight DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        sendCsvHeaders("Jangad_Outstanding_Parties_{$todayStr}.csv");
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'પાર્ટીનું નામ (Party Name)',
            'શહેર (City)',
            'ફોન નંબર (Phone)',
            'પેન્ડિંગ વાઉચર્સ સંખ્યા (Vouchers)',
            'મોકલેલ નંગ (Issued Pcs)',
            'મોકલેલ વજન કેરેટ (Issued Carats)',
            'પરત આવેલ વજન કેરેટ (Returned Carats)',
            'પાર્ટી પાસે બાકી નંગ (Pending Pcs)',
            'પાર્ટી પાસે બાકી વજન કેરેટ (Pending Carats)'
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['party_name'],
                $r['city'] ?? '-',
                $r['phone'] ?? '-',
                (int)$r['voucher_count'],
                (int)$r['total_issued_pcs'],
                number_format((float)$r['total_issued_weight'], 3, '.', ''),
                number_format((float)$r['total_returned_weight'], 3, '.', ''),
                (int)$r['pending_pcs'],
                number_format((float)$r['pending_weight'], 3, '.', '')
            ]);
        }
        fclose($out);
        log_activity('Excel Export', 'Jangad', null, 'Exported ' . count($rows) . ' jangad outstanding records to Excel');
        exit;

    // -------------------------------------------------------------------------
    // 10. Costing & Yield % (કાપન કોસ્ટિંગ રિપોર્ટ)
    // -------------------------------------------------------------------------
    case 'costing':
        $sql = "SELECT k.*, rp.purchase_no, rp.price_per_carat_inr as rough_price_per_ct
                FROM kapans k
                LEFT JOIN rough_purchases rp ON k.rough_purchase_id = rp.id
                ORDER BY k.id DESC";
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        sendCsvHeaders("Kapan_Costing_and_Yield_{$todayStr}.csv");
        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'Kapan કોડ (Code)',
            'Kapan નામ (Name)',
            'Rough ખરીદી નં (Rough Purchase No)',
            'કુલ રફ વજન કેરેટ (Rough Weight ct)',
            'તૈયાર પોલિશ વજન કેરેટ (Polish Weight ct)',
            'Yield / રીકવરી %',
            'રફ ખરીદી ભાવ / ct ₹',
            'મૂળ રફ પડતર ₹ (Rough Cost INR)',
            'પ્રોસેસ / કારખાનું ખર્ચ ₹ (Mfg Expenses)',
            'કુલ Kapan પડતર રકમ ₹ (Total Cost INR)',
            'સરેરાશ પોલિશ પડતર ભાવ / ct ₹ (Avg Cost/Ct)',
            'સ્થિતિ (Status)'
        ]);

        foreach ($rows as $r) {
            $mfgExpenses = (float)$r['galaxy_cost'] + (float)$r['ls_cost'] + (float)$r['labour_cost'] + (float)$r['other_cost'];
            fputcsv($out, [
                $r['kapan_code'],
                $r['kapan_name'],
                $r['purchase_no'] ?? '-',
                number_format((float)$r['total_weight'], 3, '.', ''),
                number_format((float)$r['total_polish_weight'], 3, '.', ''),
                number_format((float)$r['yield_percentage'], 2, '.', '') . '%',
                number_format((float)($r['rough_price_per_ct'] ?? 0), 2, '.', ''),
                number_format((float)$r['rough_cost'], 2, '.', ''),
                number_format($mfgExpenses, 2, '.', ''),
                number_format((float)$r['total_cost'], 2, '.', ''),
                number_format((float)$r['cost_per_carat'], 2, '.', ''),
                strtoupper($r['status'] ?? 'active')
            ]);
        }
        fclose($out);
        log_activity('Excel Export', 'Costing', null, 'Exported ' . count($rows) . ' costing records to Excel');
        exit;

    default:
        set_flash('error', 'અમાન્ય એક્સપોર્ટ પ્રકાર.');
        header('Location: ' . BASE_URL . '/modules/reports/index.php');
        exit;
}
