<?php
/**
 * Bulk Import Polish Diamond Sales (જથ્થાબંધ પોલિશ હીરા વેચાણ અપલોડ)
 * Accepts exact columns:
 * Sel Date, Sell No, Pocket No, Pcs, Weight, Price Ct Dolar & INR, Payment, AC%, Brockerage%, GST, Net Payment, Party, Brocker, Color Tag, Product Tag, Details
 * Supports Excel (.xlsx), CSV, TSV, and Direct Copy-Paste from Spreadsheet
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

if (php_sapi_name() !== 'cli') {
    require_login();
    $user = current_user();
} else {
    $user = ['id' => 1];
}
$pdo = getDbConnection();

// 1. Download Sample Template (.csv) with the exact 16 requested columns
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $filename = 'polish_sell_bulk_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Microsoft Excel compatibility
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Exact columns requested by user:
    // Sel Date, Sell No, Pocket No, Pcs, Weight, Price Ct Dollar, Dollar Rate, Price Ct INR, Payment, AC%, Brockerage%, GST, Net Payment, Due Date, Paid Date, Party, Brocker, Color Tag, Product Tag, Details
    fputcsv($out, [
        'Sel Date',
        'Sell No',
        'Pocket No',
        'Pcs',
        'Weight',
        'Price Ct Dollar',
        'Dollar Rate',
        'Price Ct INR',
        'Payment',
        'AC%',
        'Brockerage%',
        'GST',
        'Net Payment',
        'Due Date',
        'Paid Date',
        'Party',
        'Brocker',
        'Color Tag',
        'Product Tag',
        'Details'
    ]);

    // Realistic sample rows
    $today = date('Y-m-d');
    $sampleDueDate1 = date('Y-m-d', strtotime('+50 days'));
    $sampleDueDate2 = date('Y-m-d', strtotime('+30 days'));
    $sampleDueDate3 = date('Y-m-d', strtotime('+45 days'));

    fputcsv($out, [
        $today, 'P-INV-2026-0002', 'POL-GIA-2026-0002', '1', '2.600',
        '550.00', '85.00', '46750.00', '121550.00', '1.00',
        '1.00', '0.25%', '121853.88', $sampleDueDate1, '',
        'Zeel Sagar Test', 'Zeel Broker Test',
        'Fancy', 'Marquise GIA', 'Certified Single Stone Export'
    ]);
    fputcsv($out, [
        $today, 'P-INV-2026-0003', 'PKT-PARCEL-101', '20', '20.200',
        '0.00', '0.00', '50300.00', '1016060.00', '0.00',
        '0.50', '0.00', '1010979.70', $sampleDueDate2, $today,
        'Surat Diamond Trading Co', 'Kanti Dalal Surat',
        'White', 'Parcel Round', 'Full loose parcel lot sold - Fully Paid'
    ]);
    fputcsv($out, [
        $today, '', 'STOCK-26', '1', '1.500',
        '700.00', '85.50', '59850.00', '89775.00', '1.50',
        '1.00', '0.25%', '90223.88', $sampleDueDate3, '',
        'Mumbai Gem Impex', 'Direct',
        'TTLBF', 'Single Round', 'Direct Mumbai counter delivery'
    ]);

    fclose($out);
    exit;
}

// Helper: Normalize Date string to YYYY-MM-DD
function normalize_import_date($rawDate): string {
    if (empty($rawDate)) {
        return date('Y-m-d');
    }
    $str = trim((string)$rawDate);
    // Excel numeric date (30000 - 60000)
    if (is_numeric($str) && (int)$str > 30000 && (int)$str < 60000) {
        $days = (int)$str - 25569;
        return gmdate('Y-m-d', $days * 86400);
    }
    // Match dd/mm/yyyy or dd-mm-yyyy or dd.mm.yyyy
    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $str, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    // Match yyyy-mm-dd or yyyy/mm/dd
    if (preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/', $str, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }
    $ts = strtotime($str);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }
    return date('Y-m-d');
}

// Helper: Parse XLSX file into 2D array without external libraries
function parse_xlsx_data(string $filepath): array {
    $rows = [];
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        return $rows;
    }

    $strings = [];
    if (($strXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = @simplexml_load_string($strXml);
        if ($xml && isset($xml->si)) {
            foreach ($xml->si as $si) {
                $t = '';
                if (isset($si->t)) {
                    $t = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $r) {
                        $t .= (string)$r->t;
                    }
                }
                $strings[] = $t;
            }
        }
    }

    $sheetContent = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetContent === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetContent = $zip->getFromName($name);
                break;
            }
        }
    }

    if ($sheetContent !== false) {
        $xml = @simplexml_load_string($sheetContent);
        if ($xml && isset($xml->sheetData->row)) {
            foreach ($xml->sheetData->row as $r) {
                $rowCells = [];
                $colIdx = 0;
                foreach ($r->c as $c) {
                    $cellRef = (string)$c['r'];
                    preg_match('/^([A-Z]+)(\d+)$/', $cellRef, $matches);
                    if ($matches) {
                        $colLetters = $matches[1];
                        $targetIdx = 0;
                        for ($k = 0; $k < strlen($colLetters); $k++) {
                            $targetIdx = $targetIdx * 26 + (ord($colLetters[$k]) - ord('A') + 1);
                        }
                        $targetIdx -= 1;
                        while ($colIdx < $targetIdx) {
                            $rowCells[$colIdx++] = '';
                        }
                    }

                    $val = (string)$c->v;
                    $type = (string)$c['t'];
                    if ($type === 's' && isset($strings[(int)$val])) {
                        $val = $strings[(int)$val];
                    }
                    $rowCells[$colIdx++] = trim((string)$val);
                }
                if (!empty(array_filter($rowCells, fn($v) => $v !== ''))) {
                    $rows[] = $rowCells;
                }
            }
        }
    }
    $zip->close();
    return $rows;
}

// Helper: Parse Raw Rows (from CSV, XLSX, or direct paste) and map fields
function parse_polish_sales_rows(array $rawRows): array {
    if (empty($rawRows)) {
        return ['error' => 'કોઈ ડેટા મળ્યો નથી. કૃપા કરીને ફાઇલ પસંદ કરો અથવા ડેટા પેસ્ટ કરો.'];
    }

    // Identify header row
    $headerRowIdx = -1;
    $colMap = [];

    foreach ($rawRows as $idx => $row) {
        $matchesScore = 0;
        foreach ($row as $cIdx => $cell) {
            $col = strtolower(trim((string)$cell));
            if (str_contains($col, 'date') || str_contains($col, 'તારીખ') || str_contains($col, 'sel')) $matchesScore += 2;
            if (str_contains($col, 'sell no') || str_contains($col, 'sale no') || str_contains($col, 'invoice') || str_contains($col, 'bill') || str_contains($col, 'બિલ')) $matchesScore += 2;
            if (str_contains($col, 'pocket') || str_contains($col, 'packet') || str_contains($col, 'stock') || str_contains($col, 'પેકેટ')) $matchesScore += 2;
            if (str_contains($col, 'weight') || str_contains($col, 'carat') || str_contains($col, 'ct') || str_contains($col, 'વજન')) $matchesScore += 2;
            if (str_contains($col, 'party') || str_contains($col, 'customer') || str_contains($col, 'પાર્ટી') || str_contains($col, 'ગ્રાહક')) $matchesScore += 2;
            if (str_contains($col, 'price') || str_contains($col, 'rate') || str_contains($col, 'ભાવ') || str_contains($col, 'dolar') || str_contains($col, 'dollar')) $matchesScore += 2;
            if (str_contains($col, 'brocker') || str_contains($col, 'broker') || str_contains($col, 'દલાલ')) $matchesScore += 2;
            if (str_contains($col, 'net payment') || str_contains($col, 'net amount') || str_contains($col, 'નેટ રકમ')) $matchesScore += 2;
        }
        if ($matchesScore >= 4) {
            $headerRowIdx = $idx;
            break;
        }
    }

    // Default to row 0 if no clear header
    if ($headerRowIdx === -1) {
        $headerRowIdx = 0;
    }

    $headerRow = $rawRows[$headerRowIdx];
    foreach ($headerRow as $cIdx => $cell) {
        $name = strtolower(trim((string)$cell));
        $name = preg_replace('/[^a-z0-9%]/', '', $name);

        // 1. Sell Date
        if ((str_contains($name, 'seldate') || str_contains($name, 'selldate') || str_contains($name, 'saledate') || $name === 'date' || str_contains($name, 'tarikh')) && !isset($colMap['sale_date'])) {
            $colMap['sale_date'] = $cIdx;
        }
        // 2. Sell No
        elseif ((str_contains($name, 'sellno') || str_contains($name, 'saleno') || str_contains($name, 'invoiceno') || str_contains($name, 'billno') || $name === 'sell' || $name === 'inv') && !isset($colMap['sell_no'])) {
            $colMap['sell_no'] = $cIdx;
        }
        // 3. Pocket No
        elseif ((str_contains($name, 'pocketno') || str_contains($name, 'packetno') || str_contains($name, 'pocket') || str_contains($name, 'packet') || str_contains($name, 'stockno') || str_contains($name, 'lotno')) && !isset($colMap['packet_no'])) {
            $colMap['packet_no'] = $cIdx;
        }
        // 4. Pcs
        elseif (($name === 'pcs' || $name === 'qty' || str_contains($name, 'stone') || str_contains($name, 'pieces')) && !isset($colMap['pcs'])) {
            $colMap['pcs'] = $cIdx;
        }
        // 5. Weight
        elseif (($name === 'weight' || $name === 'wt' || $name === 'carat' || $name === 'ct' || str_contains($name, 'vajan')) && !isset($colMap['weight'])) {
            $colMap['weight'] = $cIdx;
        }
        // 6. Dollar / Ct
        elseif ((str_contains($name, 'dollar') || str_contains($name, 'dolar') || $name === 'pricectdollar' || $name === 'pricectdolar') && !str_contains($name, 'rate') && !isset($colMap['dollar_per_carat'])) {
            $colMap['dollar_per_carat'] = $cIdx;
        }
        // 7. Dollar Rate (Exchange Rate)
        elseif ((str_contains($name, 'dollarrate') || str_contains($name, 'dolarrate') || str_contains($name, 'usdrate') || $name === 'exchangerate') && !isset($colMap['dollar_rate'])) {
            $colMap['dollar_rate'] = $cIdx;
        }
        // 8. Price Ct INR
        elseif ((str_contains($name, 'pricectinr') || str_contains($name, 'inr') || str_contains($name, 'pricepercarat') || str_contains($name, 'ratepercarat') || $name === 'rate' || $name === 'price' || str_contains($name, 'bhav')) && !isset($colMap['price_per_carat_inr'])) {
            $colMap['price_per_carat_inr'] = $cIdx;
        }
        // 9. Payment (Gross)
        elseif (($name === 'payment' || $name === 'grosspayment' || $name === 'paymentamount' || $name === 'amount' || str_contains($name, 'grossamount')) && !isset($colMap['payment'])) {
            $colMap['payment'] = $cIdx;
        }
        // 10. AC%
        elseif ((str_contains($name, 'ac%') || str_contains($name, 'acpct') || str_contains($name, 'acpercent') || $name === 'ac') && !isset($colMap['ac_percent'])) {
            $colMap['ac_percent'] = $cIdx;
        }
        // 11. Brokerage%
        elseif ((str_contains($name, 'brockerage%') || str_contains($name, 'brokerage%') || str_contains($name, 'brockerage') || str_contains($name, 'brokerage') || str_contains($name, 'brok%') || str_contains($name, 'dalali')) && !isset($colMap['brokerage_percent'])) {
            $colMap['brokerage_percent'] = $cIdx;
        }
        // 12. GST
        elseif ((str_contains($name, 'gst') || str_contains($name, 'tax')) && !isset($colMap['gst'])) {
            $colMap['gst'] = $cIdx;
        }
        // 13. Net Payment
        elseif ((str_contains($name, 'netpayment') || str_contains($name, 'netamount') || str_contains($name, 'netamt') || $name === 'net') && !isset($colMap['net_payment'])) {
            $colMap['net_payment'] = $cIdx;
        }
        // 14. Due Date
        elseif ((str_contains($name, 'duedate') || str_contains($name, 'mudatdate') || str_contains($name, 'mudat') || $name === 'due' || str_contains($name, 'chuko')) && !isset($colMap['due_date'])) {
            $colMap['due_date'] = $cIdx;
        }
        // 15. Paid Date
        elseif ((str_contains($name, 'paiddate') || str_contains($name, 'receiveddate') || str_contains($name, 'paymentdate') || $name === 'paid' || str_contains($name, 'jamadate') || str_contains($name, 'paydate')) && !isset($colMap['paid_date'])) {
            $colMap['paid_date'] = $cIdx;
        }
        // 16. Party
        elseif ((str_contains($name, 'party') || str_contains($name, 'customer') || str_contains($name, 'buyer') || str_contains($name, 'client')) && !isset($colMap['party'])) {
            $colMap['party'] = $cIdx;
        }
        // 17. Broker
        elseif ((str_contains($name, 'brocker') || str_contains($name, 'broker') || str_contains($name, 'dalal') || str_contains($name, 'agent')) && !isset($colMap['broker'])) {
            $colMap['broker'] = $cIdx;
        }
        // 18. Color Tag
        elseif ((str_contains($name, 'colortag') || str_contains($name, 'colourtag') || $name === 'color' || $name === 'colour') && !isset($colMap['color_tag'])) {
            $colMap['color_tag'] = $cIdx;
        }
        // 19. Product Tag
        elseif ((str_contains($name, 'producttag') || str_contains($name, 'product') || str_contains($name, 'shape') || str_contains($name, 'tag') || $name === 'type') && !isset($colMap['product_tag'])) {
            $colMap['product_tag'] = $cIdx;
        }
        // 20. Details
        elseif ((str_contains($name, 'detail') || str_contains($name, 'notes') || str_contains($name, 'remark') || str_contains($name, 'desc') || str_contains($name, 'description')) && !isset($colMap['details'])) {
            $colMap['details'] = $cIdx;
        }
    }

    $parsedSales = [];
    $today = date('Y-m-d');

    for ($i = $headerRowIdx + 1; $i < count($rawRows); $i++) {
        $r = $rawRows[$i];
        if (empty(array_filter($r, fn($v) => trim((string)$v) !== ''))) {
            continue; // Skip entirely blank rows
        }

        $saleDate = isset($colMap['sale_date']) ? normalize_import_date($r[$colMap['sale_date']] ?? '') : $today;
        $sellNo = isset($colMap['sell_no']) ? trim((string)($r[$colMap['sell_no']] ?? '')) : '';
        $packetNo = isset($colMap['packet_no']) ? trim((string)($r[$colMap['packet_no']] ?? '')) : '';
        $pcs = isset($colMap['pcs']) ? max(1, (int)($r[$colMap['pcs']] ?? 1)) : 1;

        $rawWeight = isset($colMap['weight']) ? (string)($r[$colMap['weight']] ?? '0') : '0';
        $weight = max(0.0, round((float)str_replace(',', '', $rawWeight), 3));

        $dollarPerCarat = isset($colMap['dollar_per_carat']) ? max(0.0, round((float)str_replace(['$', ','], '', (string)($r[$colMap['dollar_per_carat']] ?? 0)), 2)) : 0.00;
        $dollarRate = isset($colMap['dollar_rate']) ? max(0.0, round((float)str_replace(['$', '₹', ','], '', (string)($r[$colMap['dollar_rate']] ?? 0)), 2)) : 0.00;
        $pricePerCtInr = isset($colMap['price_per_carat_inr']) ? max(0.0, round((float)str_replace(['₹', ','], '', (string)($r[$colMap['price_per_carat_inr']] ?? 0)), 2)) : 0.00;

        // Auto calculate INR if Dollar and Rate are supplied
        if ($dollarPerCarat > 0 && $dollarRate > 0 && $pricePerCtInr <= 0) {
            $pricePerCtInr = round($dollarPerCarat * $dollarRate, 2);
        }

        // Gross Payment
        $rawPayment = isset($colMap['payment']) ? str_replace(['₹', '$', ','], '', (string)($r[$colMap['payment']] ?? '')) : '';
        $paymentAmount = is_numeric($rawPayment) && (float)$rawPayment > 0 ? round((float)$rawPayment, 2) : round($weight * $pricePerCtInr, 2);

        // AC%
        $rawAc = isset($colMap['ac_percent']) ? str_replace(['%', ','], '', (string)($r[$colMap['ac_percent']] ?? '0')) : '0';
        $acPercent = round((float)$rawAc, 2);
        $acAmount = round($paymentAmount * ($acPercent / 100), 2);

        // Brokerage%
        $rawBrok = isset($colMap['brokerage_percent']) ? str_replace(['%', ','], '', (string)($r[$colMap['brokerage_percent']] ?? '0')) : '0';
        $brokeragePercent = round((float)$rawBrok, 2);
        $brokerageAmount = round($paymentAmount * ($brokeragePercent / 100), 2);

        // GST
        $rawGst = isset($colMap['gst']) ? trim((string)($r[$colMap['gst']] ?? '0')) : '0';
        $gstPercent = 0.00;
        $gstAmount = 0.00;
        if (str_contains($rawGst, '%') || ((float)$rawGst > 0 && (float)$rawGst <= 28 && !str_contains($rawGst, '₹'))) {
            $gstPercent = (float)str_replace(['%', '₹', ','], '', $rawGst);
            $gstAmount = round($paymentAmount * ($gstPercent / 100), 2);
        } else {
            $gstAmount = max(0.0, round((float)str_replace(['₹', ','], '', $rawGst), 2));
        }

        // Net Payment
        $rawNet = isset($colMap['net_payment']) ? str_replace(['₹', '$', ','], '', (string)($r[$colMap['net_payment']] ?? '')) : '';
        if (is_numeric($rawNet) && (float)$rawNet > 0) {
            $netAmount = round((float)$rawNet, 2);
        } else {
            // Net = Payment + AC Amount + GST Amount
            $netAmount = round($paymentAmount + $acAmount + $gstAmount, 2);
        }

        // Due Date
        $rawDueDate = isset($colMap['due_date']) ? trim((string)($r[$colMap['due_date']] ?? '')) : '';
        if (!empty($rawDueDate)) {
            $dueDate = normalize_import_date($rawDueDate);
            $dueDays = max(0, (int)round((strtotime($dueDate) - strtotime($saleDate)) / 86400));
        } else {
            $dueDays = 50;
            $dueDate = date('Y-m-d', strtotime($saleDate . " +{$dueDays} days"));
        }

        // Paid Date / Payment Status
        $rawPaidDate = isset($colMap['paid_date']) ? trim((string)($r[$colMap['paid_date']] ?? '')) : '';
        $paidDate = null;
        $paymentStatus = 'pending';
        if (!empty($rawPaidDate)) {
            $cleanPaid = normalize_import_date($rawPaidDate);
            if ($cleanPaid) {
                $paidDate = $cleanPaid;
                $paymentStatus = 'paid';
            }
        }

        $party = isset($colMap['party']) ? trim((string)($r[$colMap['party']] ?? '')) : '';
        $broker = isset($colMap['broker']) ? trim((string)($r[$colMap['broker']] ?? '')) : '';
        $colorTag = isset($colMap['color_tag']) ? trim((string)($r[$colMap['color_tag']] ?? '')) : '';
        $productTag = isset($colMap['product_tag']) ? trim((string)($r[$colMap['product_tag']] ?? '')) : '';
        $details = isset($colMap['details']) ? trim((string)($r[$colMap['details']] ?? '')) : '';

        // Determine Warnings / Errors for preview
        $errors = [];
        if ($weight <= 0) $errors[] = 'વજન (Weight) 0 કે ખાલી છે.';
        if ($packetNo === '') $errors[] = 'Pocket No ખાલી છે.';
        if ($party === '') $errors[] = 'Party ખાલી છે.';
        if ($pricePerCtInr <= 0 && $dollarPerCarat <= 0) $errors[] = 'ભાવ (Price/Ct) 0 છે.';

        $parsedSales[] = [
            'row_num' => $i + 1,
            'sale_date' => $saleDate,
            'sell_no' => $sellNo,
            'packet_no' => $packetNo,
            'pcs' => $pcs,
            'weight' => $weight,
            'dollar_per_carat' => $dollarPerCarat,
            'dollar_rate' => $dollarRate,
            'price_per_carat_inr' => $pricePerCtInr,
            'payment_amount' => $paymentAmount,
            'ac_percent' => $acPercent,
            'ac_amount' => $acAmount,
            'brokerage_percent' => $brokeragePercent,
            'brokerage_amount' => $brokerageAmount,
            'gst_percent' => $gstPercent,
            'gst_amount' => $gstAmount,
            'net_amount' => $netAmount,
            'due_days' => $dueDays,
            'due_date' => $dueDate,
            'paid_date' => $paidDate,
            'payment_status' => $paymentStatus,
            'party' => $party,
            'broker' => $broker,
            'color_tag' => $colorTag,
            'product_tag' => $productTag,
            'details' => $details,
            'errors' => $errors
        ];
    }

    return [
        'col_map' => $colMap,
        'sales' => $parsedSales
    ];
}

$pageTitle = 'Polish Diamond Sale - Bulk Upload (જથ્થાબંધ વેચાણ અપલોડ)';
$step = 1;
$parsedData = null;
$importSummary = null;

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['form_action'] ?? 'preview';

    // ─────────────────────────────────────────────────────────────────
    // STEP 1: PARSE AND PREVIEW
    // ─────────────────────────────────────────────────────────────────
    if ($action === 'preview') {
        $rawRows = [];

        // Check if file uploaded
        if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['import_file']['tmp_name'];
            $origName = $_FILES['import_file']['name'];
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if ($ext === 'xlsx') {
                $rawRows = parse_xlsx_data($tmpPath);
            } else {
                // CSV / TSV / TXT
                $handle = fopen($tmpPath, 'r');
                if ($handle) {
                    // Detect delimiter
                    $firstLine = fgets($handle);
                    rewind($handle);
                    $delim = (substr_count($firstLine, "\t") > substr_count($firstLine, ",")) ? "\t" : ",";

                    while (($data = fgetcsv($handle, 10000, $delim)) !== false) {
                        $rawRows[] = $data;
                    }
                    fclose($handle);
                }
            }
        } elseif (!empty($_POST['paste_data'])) {
            // Direct paste from spreadsheet
            $text = trim($_POST['paste_data']);
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $delim = str_contains($line, "\t") ? "\t" : ",";
                $rawRows[] = str_getcsv($line, $delim);
            }
        }

        $res = parse_polish_sales_rows($rawRows);
        if (isset($res['error'])) {
            set_flash('error', $res['error']);
        } elseif (empty($res['sales'])) {
            set_flash('error', 'કોઈ માન્ય વેચાણ રેકોર્ડ મળ્યો નથી. ખાતરી કરો કે હેડર રો યોગ્ય છે.');
        } else {
            $parsedData = $res['sales'];
            $step = 2; // Move to preview confirmation step
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // STEP 2: CONFIRM AND COMMIT TO DATABASE
    // ─────────────────────────────────────────────────────────────────
    elseif ($action === 'confirm_import') {
        $submittedSalesJson = $_POST['parsed_sales_json'] ?? '';
        $submittedSales = json_decode($submittedSalesJson, true);
        $fallbackPartyName = trim((string)($_POST['fallback_party'] ?? ''));
        $fallbackBrokerName = trim((string)($_POST['fallback_broker'] ?? ''));
        $defaultDueDays = (int)($_POST['default_due_days'] ?? 50);

        if (empty($submittedSales)) {
            set_flash('error', 'સેવ કરવા માટે કોઈ ડેટા મળ્યો નથી.');
        } else {
            $inserted = 0;
            $skipped = 0;
            $totalWtImported = 0.000;
            $totalAmountImported = 0.00;

            try {
                $pdo->beginTransaction();

                // Prepared Statements
                $findPartyStmt = $pdo->prepare("SELECT id FROM parties WHERE LOWER(TRIM(party_name)) = LOWER(TRIM(?)) LIMIT 1");
                $createPartyStmt = $pdo->prepare("INSERT INTO parties (party_name, party_type, status, city) VALUES (?, 'customer', 'active', 'Surat')");
                $createBrokerStmt = $pdo->prepare("INSERT INTO parties (party_name, party_type, status, city) VALUES (?, 'broker', 'active', 'Surat')");

                // Stock lookup & creation statements
                $findStockStmt = $pdo->prepare("SELECT id, available_pcs, available_weight, status FROM polish_stocks WHERE packet_no = ? OR gia_number = ? LIMIT 1");
                $deductStockStmt = $pdo->prepare("UPDATE polish_stocks SET available_pcs = ?, available_weight = ?, status = ? WHERE id = ?");
                $createStockStmt = $pdo->prepare("
                    INSERT INTO polish_stocks (
                        packet_no, stock_type, shape, color_tag, total_pcs, total_weight,
                        available_pcs, available_weight, cost_per_carat, sale_price_per_carat,
                        status, source_type, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?,
                        0, 0.000, ?, ?,
                        'sold', 'mfg', ?
                    )
                ");

                // Sale insert statement
                $insertSaleStmt = $pdo->prepare("
                    INSERT INTO polish_sales (
                        invoice_no, sale_date, payment_currency, party_id, broker_id,
                        color_tag, product_tag, polish_stock_id, pcs, weight,
                        dollar_per_carat, dollar_rate, price_per_carat_inr, payment_amount,
                        brokerage_percent, brokerage_amount, ac_percent, gst_percent, gst_amount,
                        ac_gst_amount, net_amount, due_days, due_date, payment_status,
                        received_amount, received_date, notes, created_by
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?
                    )
                ");

                // Cache parties & brokers to avoid redundant queries
                $partyCache = [];
                $brokerCache = [];

                foreach ($submittedSales as $idx => $s) {
                    $weight = (float)$s['weight'];
                    if ($weight <= 0) {
                        $skipped++;
                        continue;
                    }

                    $pktNo = trim((string)$s['packet_no']);
                    $pcs = max(1, (int)$s['pcs']);
                    $saleDate = !empty($s['sale_date']) ? $s['sale_date'] : date('Y-m-d');

                    // 1. Resolve Invoice No
                    $sellNo = trim((string)$s['sell_no']);
                    if ($sellNo === '') {
                        $sellNo = generate_next_code('P-INV', 'polish_sales', 'invoice_no');
                    } else {
                        // Check for duplicate invoice
                        $dupChk = $pdo->prepare("SELECT id FROM polish_sales WHERE invoice_no = ? LIMIT 1");
                        $dupChk->execute([$sellNo]);
                        if ($dupChk->fetchColumn()) {
                            $sellNo = $sellNo . '-' . ($idx + 1);
                        }
                    }

                    // 2. Resolve Customer Party
                    $partyName = trim((string)$s['party']);
                    if ($partyName === '') {
                        $partyName = !empty($fallbackPartyName) ? $fallbackPartyName : 'Direct Customer';
                    }
                    $partyKey = strtolower($partyName);
                    if (isset($partyCache[$partyKey])) {
                        $partyId = $partyCache[$partyKey];
                    } else {
                        $findPartyStmt->execute([$partyName]);
                        $fId = $findPartyStmt->fetchColumn();
                        if ($fId) {
                            $partyId = (int)$fId;
                        } else {
                            $createPartyStmt->execute([$partyName]);
                            $partyId = (int)$pdo->lastInsertId();
                        }
                        $partyCache[$partyKey] = $partyId;
                    }

                    // 3. Resolve Broker
                    $brokerId = null;
                    $brokerName = trim((string)$s['broker']);
                    if ($brokerName === '' && !empty($fallbackBrokerName)) {
                        $brokerName = $fallbackBrokerName;
                    }
                    if ($brokerName !== '' && strtolower($brokerName) !== 'direct' && strtolower($brokerName) !== 'none') {
                        $brokerKey = strtolower($brokerName);
                        if (isset($brokerCache[$brokerKey])) {
                            $brokerId = $brokerCache[$brokerKey];
                        } else {
                            $findPartyStmt->execute([$brokerName]);
                            $bId = $findPartyStmt->fetchColumn();
                            if ($bId) {
                                $brokerId = (int)$bId;
                            } else {
                                $createBrokerStmt->execute([$brokerName]);
                                $brokerId = (int)$pdo->lastInsertId();
                            }
                            $brokerCache[$brokerKey] = $brokerId;
                        }
                    }

                    // 4. Resolve / Link Polish Stock
                    $stockId = 0;
                    if ($pktNo !== '') {
                        $findStockStmt->execute([$pktNo, $pktNo]);
                        $stRow = $findStockStmt->fetch(PDO::FETCH_ASSOC);
                        if ($stRow) {
                            $stockId = (int)$stRow['id'];
                            $newAvailPcs = max(0, (int)$stRow['available_pcs'] - $pcs);
                            $newAvailWt = max(0.0, round((float)$stRow['available_weight'] - $weight, 3));
                            $newStatus = ($newAvailWt <= 0.0005) ? 'sold' : 'available';
                            $deductStockStmt->execute([$newAvailPcs, $newAvailWt, $newStatus, $stockId]);
                        }
                    }

                    // If stock packet does not exist, auto-create a registered sold stock entry
                    if ($stockId <= 0) {
                        $prodTag = trim((string)$s['product_tag']);
                        $stockType = (stripos($prodTag, 'gia') !== false) ? 'gia_single' : ($pcs > 1 ? 'parcel' : 'single');
                        $shape = !empty($prodTag) ? $prodTag : 'Round';
                        $colorTag = trim((string)$s['color_tag']);
                        $priceInr = (float)$s['price_per_carat_inr'];
                        $autoPkt = ($pktNo !== '') ? $pktNo : 'SL-LOT-' . date('ymd') . '-' . rand(100, 999);

                        $createStockStmt->execute([
                            $autoPkt, $stockType, $shape, $colorTag, $pcs, $weight,
                            $priceInr, $priceInr, $saleDate . ' ' . date('H:i:s')
                        ]);
                        $stockId = (int)$pdo->lastInsertId();
                    }

                    // 5. Calculations
                    $dollarPerCarat = (float)$s['dollar_per_carat'];
                    $dollarRate = (float)$s['dollar_rate'];
                    $pricePerCtInr = (float)$s['price_per_carat_inr'];
                    $paymentCurrency = ($dollarPerCarat > 0 && $dollarRate <= 0) ? 'USD' : 'INR';

                    $paymentAmount = (float)$s['payment_amount'];
                    if ($paymentAmount <= 0) {
                        $paymentAmount = round($weight * $pricePerCtInr, 2);
                    }

                    $brokeragePercent = (float)$s['brokerage_percent'];
                    $brokerageAmount = (float)($s['brokerage_amount'] ?? round($paymentAmount * ($brokeragePercent / 100), 2));
                    $acPercent = (float)$s['ac_percent'];
                    $acAmount = (float)($s['ac_amount'] ?? round($paymentAmount * ($acPercent / 100), 2));
                    $gstPercent = (float)$s['gst_percent'];
                    $gstAmount = (float)($s['gst_amount'] ?? round($paymentAmount * ($gstPercent / 100), 2));
                    $acGstCombined = round($acAmount + $gstAmount, 2);

                    $netAmount = (float)$s['net_amount'];
                    if ($netAmount <= 0) {
                        $netAmount = round($paymentAmount + $acGstCombined, 2);
                    }

                    $sDueDays = isset($s['due_days']) && is_numeric($s['due_days']) ? (int)$s['due_days'] : $defaultDueDays;
                    $sDueDate = !empty($s['due_date']) ? $s['due_date'] : date('Y-m-d', strtotime($saleDate . " +$sDueDays days"));
                    $sPaidDate = !empty($s['paid_date']) ? $s['paid_date'] : null;
                    $sStatus = !empty($s['payment_status']) ? $s['payment_status'] : (!empty($sPaidDate) ? 'paid' : 'pending');
                    $sReceivedAmount = ($sStatus === 'paid') ? $netAmount : 0.00;

                    $details = trim((string)$s['details']);
                    $colorTagVal = trim((string)$s['color_tag']);
                    $productTagVal = trim((string)$s['product_tag']);

                    // Insert Sale
                    $insertSaleStmt->execute([
                        $sellNo, $saleDate, $paymentCurrency, $partyId, $brokerId,
                        $colorTagVal, $productTagVal, $stockId, $pcs, $weight,
                        $dollarPerCarat, $dollarRate, $pricePerCtInr, $paymentAmount,
                        $brokeragePercent, $brokerageAmount, $acPercent, $gstPercent, $gstAmount,
                        $acGstCombined, $netAmount, $sDueDays, $sDueDate, $sStatus,
                        $sReceivedAmount, $sPaidDate, $details, $user['id']
                    ]);
                    $saleId = (int)$pdo->lastInsertId();

                    // Update customer receivable balance (only unpaid portion increases ledger balance)
                    $unpaidReceivable = round($netAmount - $sReceivedAmount, 2);
                    if ($unpaidReceivable > 0) {
                        update_party_balance($partyId, +$unpaidReceivable);
                    }
                    if ($brokerId && $brokerageAmount > 0) {
                        update_party_balance($brokerId, -$brokerageAmount);
                    }

                    log_activity(
                        'Polish Diamond Sale Bulk Upload',
                        'polish_sales',
                        $saleId,
                        "Bulk uploaded Polish Sale #{$sellNo} | Party: {$partyName} | {$weight} Ct | ₹" . number_format($netAmount, 2)
                    );

                    $inserted++;
                    $totalWtImported += $weight;
                    $totalAmountImported += $netAmount;
                }

                $pdo->commit();

                set_flash(
                    'success',
                    "🎉 સફળતા! કુલ <strong>{$inserted}</strong> પોલિશ વેચાણ બિલ સફળતાપૂર્વક અપલોડ થઈ ગયા છે! (કુલ વજન: " . format_carat($totalWtImported) . ", રકમ: " . format_inr($totalAmountImported) . ")"
                );
                header('Location: ' . BASE_URL . '/modules/polish/index.php?tab=gia_sell');
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                set_flash('error', 'અપલોડ દરમિયાન ભૂલ આવી: ' . $e->getMessage());
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ───────────────────────────────────────────────────────────────── -->
<!-- ENTERPRISE HEADER WITH BREADCRUMB & TEMPLATE DOWNLOAD             -->
<!-- ───────────────────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2.5 mb-3">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small text-muted">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/modules/dashboard/index.php" class="text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>/modules/polish/index.php" class="text-decoration-none">Polish Hub</a></li>
                <li class="breadcrumb-item active" aria-current="page">Bulk વેચાણ અપલોડ</li>
            </ol>
        </nav>
        <h3 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
            <?= svg_icon('file-arrow-up', 'text-primary') ?>
            <span>Polish Diamond Sale - Bulk Upload (જથ્થાબંધ વેચાણ અપલોડ)</span>
        </h3>
        <div class="small text-muted mt-0.5">
            Excel / CSV ફાઇલ દ્વારા અથવા સ્પ્રેડશીટમાંથી ડાયરેક્ટ કોપી-પેસ્ટ કરીને એકસાથે સેંકડો વેચાણ બિલ નોંધો
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2">
        <a href="?action=download_template" class="btn btn-sm btn-outline-success fw-bold px-3 shadow-xs">
            <?= svg_icon('file-excel', 'me-1 text-success') ?>Sample Template (.CSV) ડાઉનલોડ કરો
        </a>
        <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=gia_sell" class="btn btn-sm btn-outline-secondary fw-semibold px-3 shadow-xs">
            <?= svg_icon('arrow-left', 'me-1') ?>વેચાણ રજિસ્ટર
        </a>
    </div>
</div>

<?php if ($step === 1): ?>
<!-- ───────────────────────────────────────────────────────────────── -->
<!-- STEP 1: FILE UPLOAD OR SPREADSHEET PASTE FORM                     -->
<!-- ───────────────────────────────────────────────────────────────── -->
<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="finora-card border shadow-sm">
            <div class="finora-card-header bg-white border-bottom p-3">
                <ul class="nav nav-pills" id="importTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-bold d-flex align-items-center gap-1.5 py-1.5 px-3" id="file-tab" data-bs-toggle="pill" data-bs-target="#tab-file" type="button" role="tab">
                            <?= svg_icon('file-excel', 'me-1') ?>Excel / CSV ફાઇલ અપલોડ
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-bold d-flex align-items-center gap-1.5 py-1.5 px-3" id="paste-tab" data-bs-toggle="pill" data-bs-target="#tab-paste" type="button" role="tab">
                            <?= svg_icon('clipboard', 'me-1') ?>સ્પ્રેડશીટમાંથી ડાયરેક્ટ Paste (Copy-Paste)
                        </button>
                    </li>
                </ul>
            </div>

            <form method="POST" action="" enctype="multipart/form-data" class="p-3 mb-0" id="uploadForm">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="preview">

                <div class="tab-content" id="importTabContent">
                    <!-- Tab 1: File Upload -->
                    <div class="tab-pane fade show active" id="tab-file" role="tabpanel">
                        <div class="p-4 border-2 border-dashed rounded-3 text-center bg-light-subtle mb-3">
                            <div class="mb-2 text-primary"><?= svg_icon('cloud-arrow-up', '', 40) ?></div>
                            <h6 class="fw-bold text-dark mb-1">તમારી વેચાણ લિસ્ટ ફાઇલ અહીં પસંદ કરો</h6>
                            <p class="text-muted small mb-3">સપોર્ટેડ ફોર્મેટ્સ: .xlsx, .csv, .tsv, .txt (Max: 10 MB)</p>
                            <input type="file" name="import_file" id="fileInput" class="form-control form-control-sm mx-auto" style="max-width: 380px;" accept=".csv,.xlsx,.tsv,.txt">
                        </div>
                    </div>

                    <!-- Tab 2: Direct Copy Paste -->
                    <div class="tab-pane fade" id="tab-paste" role="tabpanel">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark small mb-1">
                                Excel અથવા Google Sheets માંથી ડેટા સિલેક્ટ કરી અહીં પેસ્ટ કરો (Ctrl+V):
                            </label>
                            <textarea name="paste_data" rows="8" class="form-control font-mono small" placeholder="Sel Date&#9;Sell No&#9;Pocket No&#9;Pcs&#9;Weight&#9;Price Ct Dollar&#9;Dollar Rate&#9;Price Ct INR&#9;Payment&#9;AC%&#9;Brockerage%&#9;GST&#9;Net Payment&#9;Party&#9;Brocker&#9;Color Tag&#9;Product Tag&#9;Details&#10;2026-11-19&#9;P-INV-001&#9;STOCK 26&#9;1&#9;2.600&#9;550.00&#9;85.00&#9;46750.00&#9;121550.00&#9;1.00&#9;1.00&#9;0.25%&#9;121853.88&#9;Zeel Sagar Test&#9;Zeel Broker Test&#9;Fancy&#9;GIA Marquise&#9;Direct Sale"></textarea>
                            <div class="form-text small text-muted">હેડર લાઇન સાથે કે હેડર વગર કોપી-પેસ્ટ કરી શકાય છે.</div>
                        </div>
                    </div>
                </div>

                <!-- Fallback / Defaults Options -->
                <div class="p-3 bg-light rounded-3 border mb-3">
                    <h6 class="fw-bold text-dark small mb-2 d-flex align-items-center gap-1.5">
                        <?= svg_icon('sliders', 'text-secondary', 14) ?>ડિફોલ્ટ સેટિંગ્સ (જો ફાઇલમાં ખાલી હોય તો)
                    </h6>
                    <div class="row g-2">
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-0.5">ડિફોલ્ટ ગ્રાહક પાર્ટી (Customer):</label>
                            <input type="text" name="fallback_party" class="form-control form-control-sm" placeholder="દા.ત. Direct Customer">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-0.5">ડિફોલ્ટ દલાલ (Broker):</label>
                            <input type="text" name="fallback_broker" class="form-control form-control-sm" placeholder="દા.ત. Direct">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-0.5">ચૂકવણી મુદત (Due Days):</label>
                            <input type="number" name="default_due_days" class="form-control form-control-sm" value="50">
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a href="<?= BASE_URL ?>/modules/polish/index.php?tab=gia_sell" class="btn btn-sm btn-light border px-3">રદ કરો</a>
                    <button type="submit" class="btn btn-sm btn-primary fw-bold px-4 shadow-xs">
                        <?= svg_icon('magnifying-glass', 'me-1') ?>આગળ વધો (Preview & Validate) &rarr;
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Right Side Help & Column Reference -->
    <div class="col-12 col-xl-4">
        <div class="card border rounded-3 p-3 bg-white shadow-xs mb-3">
            <h6 class="fw-bold text-dark mb-2 d-flex align-items-center gap-2">
                <?= svg_icon('circle-info', 'text-primary') ?>
                <span>આવશ્યક કોલમોનું લિસ્ટ (Field List)</span>
            </h6>
            <div class="small text-muted mb-3">
                તમારી એક્સેલ કે CSV ફાઇલમાં નીચે મુજબની ૧૬ કોલમો હોવી જોઈએ:
            </div>
            <ul class="list-group list-group-flush small font-mono">
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>1. Sel Date</strong>
                    <span class="badge bg-light text-muted">વેચાણ તારીખ (YYYY-MM-DD)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>2. Sell No</strong>
                    <span class="badge bg-light text-muted">ઇન્વોઇસ / બિલ નં (ઓટો જો ખાલી)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong class="text-primary">3. Pocket No *</strong>
                    <span class="badge bg-primary-subtle text-primary">પેકેટ / સ્ટોક સંદર્ભ નં</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>4. Pcs</strong>
                    <span class="badge bg-light text-muted">નંગ (Stones/Qty)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong class="text-primary">5. Weight *</strong>
                    <span class="badge bg-primary-subtle text-primary">વજન (Ct)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>6. Price Ct Dollar / INR</strong>
                    <span class="badge bg-light text-muted">ભાવ ($/Ct અથવા ₹/Ct)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>7. Payment</strong>
                    <span class="badge bg-light text-muted">ગ્રોસ રકમ (Weight × Rate)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>8. AC%</strong>
                    <span class="badge bg-light text-muted">આડત / એકાઉન્ટ ટકાવારી</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>9. Brockerage%</strong>
                    <span class="badge bg-light text-muted">દલાલી ટકા (%)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>10. GST</strong>
                    <span class="badge bg-light text-muted">GST ટકા (%) અથવા રકમ</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>11. Net Payment</strong>
                    <span class="badge bg-success-subtle text-success">નેટ ચૂકવણી રકમ</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong class="text-primary">12. Due Date</strong>
                    <span class="badge bg-primary-subtle text-primary">ચુકવણી મુદત તારીખ (YYYY-MM-DD)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong class="text-success">13. Paid Date</strong>
                    <span class="badge bg-success-subtle text-success">ચુકવણી જમા તારીખ (Paid હોય તો)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong class="text-primary">14. Party *</strong>
                    <span class="badge bg-primary-subtle text-primary">ગ્રાહક પાર્ટી નામ</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>15. Brocker</strong>
                    <span class="badge bg-light text-muted">દલાલ નામ</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>16. Color Tag</strong>
                    <span class="badge bg-light text-muted">કલર ટેગ (White/Fancy)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>17. Product Tag</strong>
                    <span class="badge bg-light text-muted">પ્રોડક્ટ ટેગ (Single/Parcel/GIA)</span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0 py-1.5">
                    <strong>18. Details</strong>
                    <span class="badge bg-light text-muted">વિગત / શેરો (Notes)</span>
                </li>
            </ul>
        </div>
    </div>
</div>

<?php elseif ($step === 2 && !empty($parsedData)): ?>
<!-- ───────────────────────────────────────────────────────────────── -->
<!-- STEP 2: PREVIEW & CONFIRMATION TABLE                              -->
<!-- ───────────────────────────────────────────────────────────────── -->
<?php
$totalLots = count($parsedData);
$totalWt = 0.000;
$totalPcs = 0;
$totalNet = 0.00;
$errorRows = 0;

foreach ($parsedData as $row) {
    $totalWt += (float)$row['weight'];
    $totalPcs += (int)$row['pcs'];
    $totalNet += (float)$row['net_amount'];
    if (!empty($row['errors'])) {
        $errorRows++;
    }
}
?>

<div class="row g-2.5 mb-3">
    <div class="col-12 col-sm-6 col-md-3">
        <div class="stat-card-finora bg-white border p-3">
            <div class="kpi-label">કુલ વેચાણ રો (Total Rows)</div>
            <div class="kpi-value text-primary font-mono"><?= $totalLots ?> બિલ</div>
            <div class="small text-muted mt-1"><?= $errorRows > 0 ? "<span class='text-danger fw-bold'>{$errorRows} રો માં વોર્નિંગ</span>" : "તમામ રો માન્ય છે" ?></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <div class="stat-card-finora bg-white border p-3">
            <div class="kpi-label">કુલ વજન (Total Weight)</div>
            <div class="kpi-value text-success font-mono"><?= format_carat($totalWt) ?></div>
            <div class="small text-muted mt-1"><?= number_format($totalPcs) ?> કુલ નંગ (Pcs)</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <div class="stat-card-finora bg-white border p-3">
            <div class="kpi-label">કુલ નેટ રકમ (Net Amount)</div>
            <div class="kpi-value text-dark font-mono"><?= format_inr($totalNet) ?></div>
            <div class="small text-muted mt-1">GST & દલાલી સાથે</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-md-3">
        <div class="stat-card-finora bg-white border p-3">
            <div class="kpi-label">સ્થિતિ (Validation)</div>
            <div class="kpi-value <?= $errorRows > 0 ? 'text-warning' : 'text-success' ?> font-mono">
                <?= $errorRows > 0 ? 'ચકાસણી જરૂરી' : '🟢 Ready to Save' ?>
            </div>
            <div class="small text-muted mt-1">બટન દબાવી કન્ફર્મ કરો</div>
        </div>
    </div>
</div>

<form method="POST" action="" class="mb-4">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="confirm_import">
    <input type="hidden" name="parsed_sales_json" value="<?= e(json_encode($parsedData)) ?>">
    <input type="hidden" name="fallback_party" value="<?= e($_POST['fallback_party'] ?? '') ?>">
    <input type="hidden" name="fallback_broker" value="<?= e($_POST['fallback_broker'] ?? '') ?>">
    <input type="hidden" name="default_due_days" value="<?= (int)($_POST['default_due_days'] ?? 50) ?>">

    <div class="finora-card border shadow-sm mb-3">
        <div class="finora-card-header bg-white border-bottom py-2.5 px-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0 text-dark d-flex align-items-center gap-2">
                <?= svg_icon('table-list', 'text-primary') ?>
                <span>વેચાણ પ્રીવ્યૂ ટેબલ (Sales Preview - <?= $totalLots ?> Records)</span>
            </h6>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>/modules/polish_sales/import.php" class="btn btn-sm btn-outline-secondary">
                    <?= svg_icon('rotate-left', 'me-1') ?>ફરીથી અપલોડ કરો
                </a>
                <button type="submit" class="btn btn-sm btn-success text-white fw-bold px-4 shadow-xs">
                    <?= svg_icon('check-double', 'me-1') ?>આ તમામ <?= $totalLots ?> વેચાણ કન્ફર્મ & સેવ કરો &rarr;
                </button>
            </div>
        </div>

        <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
            <table class="table table-diamond mb-0 align-middle">
                <thead class="sticky-top bg-light">
                    <tr>
                        <th class="ps-3">#</th>
                        <th>તારીખ</th>
                        <th>બિલ નં</th>
                        <th>Pocket No</th>
                        <th>ગ્રાહક પાર્ટી</th>
                        <th>દલાલ</th>
                        <th>Color / Tag</th>
                        <th>Product Tag</th>
                        <th class="text-center">નંગ</th>
                        <th class="text-end">વજન (Ct)</th>
                        <th class="text-end">ભાવ / Ct</th>
                        <th class="text-end">ગ્રોસ રકમ</th>
                        <th class="text-center">AC %</th>
                        <th class="text-center">દલાલી %</th>
                        <th class="text-end">GST</th>
                        <th class="text-end">નેટ રકમ (₹)</th>
                        <th class="text-nowrap">મુદત (Due Date)</th>
                        <th class="text-nowrap">ચુકવણી (Paid Date / Status)</th>
                        <th>વિગત</th>
                        <th class="text-end pe-3">ચકાસણી</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parsedData as $idx => $row): ?>
                        <tr class="<?= !empty($row['errors']) ? 'bg-danger-subtle bg-opacity-25' : '' ?>">
                            <td class="ps-3 font-mono text-muted small"><?= $idx + 1 ?></td>
                            <td class="font-mono text-nowrap small"><?= format_date($row['sale_date']) ?></td>
                            <td class="font-mono fw-bold text-primary text-nowrap"><?= e($row['sell_no'] ?: 'AUTO') ?></td>
                            <td class="font-mono fw-bold text-dark text-nowrap"><?= e($row['packet_no']) ?></td>
                            <td class="fw-semibold text-dark text-nowrap"><?= e($row['party'] ?: 'Direct Customer') ?></td>
                            <td class="text-muted text-nowrap small"><?= e($row['broker'] ?: '—') ?></td>
                            <td class="text-nowrap small"><span class="badge bg-light text-dark border"><?= e($row['color_tag'] ?: '-') ?></span></td>
                            <td class="text-nowrap small"><span class="badge bg-light text-dark border"><?= e($row['product_tag'] ?: 'Single') ?></span></td>
                            <td class="text-center font-mono"><?= (int)$row['pcs'] ?></td>
                            <td class="text-end font-mono fw-bold text-dark"><?= format_carat((float)$row['weight']) ?></td>
                            <td class="text-end font-mono text-dark">
                                <?php if ((float)$row['dollar_per_carat'] > 0): ?>
                                    <span class="text-muted small">$<?= number_format((float)$row['dollar_per_carat'], 2) ?></span><br>
                                <?php endif; ?>
                                <?= format_inr((float)$row['price_per_carat_inr']) ?>
                            </td>
                            <td class="text-end font-mono text-muted"><?= format_inr((float)$row['payment_amount']) ?></td>
                            <td class="text-center font-mono small"><?= (float)$row['ac_percent'] > 0 ? (float)$row['ac_percent'] . '%' : '—' ?></td>
                            <td class="text-center font-mono small"><?= (float)$row['brokerage_percent'] > 0 ? (float)$row['brokerage_percent'] . '%' : '—' ?></td>
                            <td class="text-end font-mono small text-muted"><?= (float)$row['gst_amount'] > 0 ? format_inr((float)$row['gst_amount']) : '—' ?></td>
                            <td class="text-end font-mono fw-bold text-success"><?= format_inr((float)$row['net_amount']) ?></td>
                            <td class="font-mono text-nowrap small">
                                <span class="fw-bold text-dark"><?= format_date($row['due_date']) ?></span>
                                <span class="text-muted d-block" style="font-size:0.7rem;"><?= (int)$row['due_days'] ?> દિવસ મુદત</span>
                            </td>
                            <td class="text-nowrap small">
                                <?php if (!empty($row['paid_date'])): ?>
                                    <span class="badge bg-success-subtle text-success border font-mono">
                                        <?= svg_icon('check', 'me-0.5', 11) ?>Paid: <?= format_date($row['paid_date']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-dark border font-mono">
                                        <?= svg_icon('clock', 'me-0.5', 11) ?>Pending
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted text-truncate" style="max-width: 140px;"><?= e($row['details'] ?: '—') ?></td>
                            <td class="text-end pe-3 text-nowrap">
                                <?php if (!empty($row['errors'])): ?>
                                    <span class="badge bg-danger" title="<?= e(implode(', ', $row['errors'])) ?>">
                                        <?= svg_icon('triangle-exclamation', 'me-0.5', 11) ?><?= e($row['errors'][0]) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success border">🟢 OK</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="p-3 bg-light border-top d-flex justify-content-between align-items-center">
            <span class="small text-muted">
                સેવ કરવાથી ગ્રાહકનું ખાતું, દલાલી અને સ્ટોક રજિસ્ટર આપોઆપ અપડેટ થશે.
            </span>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/modules/polish_sales/import.php" class="btn btn-sm btn-outline-secondary px-3">
                    <?= svg_icon('xmark', 'me-1') ?>રદ કરો
                </a>
                <button type="submit" class="btn btn-sm btn-success text-white fw-bold px-4 shadow-xs">
                    <?= svg_icon('check-double', 'me-1') ?>આ તમામ <?= $totalLots ?> વેચાણ કન્ફર્મ & સેવ કરો &rarr;
                </button>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
