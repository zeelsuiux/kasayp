<?php
/**
 * Bulk Import Rough Stock & Purchases
 * Accepts exact columns: packet no, date, pcs (Qty), weight, price per carat, tag, process tag, description, supplier party, broker, due days, brokerage %, destination, safe, dabba
 * Supports Excel (.xlsx), CSV, and Direct Copy-Paste from Spreadsheet
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

// Fetch active masters for validation & normalization
$masterTags = get_active_tags();
$masterProcessTags = get_active_process_tags();
$masterSafes = get_active_safes();
$masterDestinations = get_active_destinations();
$masterDabbas = get_active_dabbas();

// 1. Download Sample Template with the exact columns including Broker, Supplier Party, Due Days & Brokerage %
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $format = $_GET['format'] ?? 'csv';
    $filename = 'rough_stock_bulk_template.' . ($format === 'csv' ? 'csv' : 'csv');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $out = fopen('php://output', 'w');
    // UTF-8 BOM for Excel compatibility
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Column headers matching user's requirement
    fputcsv($out, [
        'packet no',
        'date',
        'pcs (Qty)',
        'weight',
        'price per carat',
        'tag',
        'process tag',
        'description',
        'supplier party',
        'broker',
        'due days',
        'brokerage %',
        'destination',
        'safe',
        'dabba'
    ]);
    
    // Realistic sample rows
    $today = date('Y-m-d');
    fputcsv($out, ['PKT-101', $today, '1', '424.990', '25.00', 'Tops', 'Sell', 'Orange pink fancy rough', 'Antwerp Rough Direct Ltd', 'Kanti Dalal Surat', '60', '1.00', 'Surat', 'OFC', '12']);
    fputcsv($out, ['PKT-102', $today, '185', '186.070', '12000.00', 'Rough', 'Assort', 'Yellow rough sorting lot', 'Shree Ramkrishna Rough Supply', 'Direct', '50', '0.00', 'Surat', 'N1', '3']);
    fputcsv($out, ['PKT-103', $today, '50', '65.450', '8500.00', 'Rejection', 'Hold', 'Small rejection stones on hold', 'Surat Diamond Trading Co', 'Ramesh Dalal BDB', '45', '0.50', 'Mumbai', 'M2', '2']);
    fputcsv($out, ['PKT-104', $today, '12', '14.280', '16500.00', 'Rough', 'Assort', 'White octahedron rough', 'Dharmanandan Rough Impex', 'Direct', '50', '0.00', 'Katargam', 'OFC', '5']);
    
    fclose($out);
    exit;
}

// Helper: Normalize Date string to YYYY-MM-DD
function normalize_import_date($rawDate): string {
    if (empty($rawDate)) {
        return date('Y-m-d');
    }
    $str = trim((string)$rawDate);
    // Excel serial number (numeric between 30000 and 60000)
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

// Helper: Normalize Tag value
function normalize_tag_value(string $raw, array $validTags): string {
    $clean = trim($raw);
    if ($clean === '') return 'Rough';
    foreach ($validTags as $vt) {
        if (strcasecmp($clean, $vt['name']) === 0 || strcasecmp($clean, $vt['code'] ?? '') === 0) {
            return $vt['name'];
        }
    }
    if (stripos($clean, 'top') !== false) return 'Tops';
    if (stripos($clean, 'reject') !== false) return 'Rejection';
    if (stripos($clean, 'rough') !== false) return 'Rough';
    return ucfirst($clean);
}

// Helper: Normalize Process Tag value
function normalize_process_tag_value(string $raw, array $validProcessTags): string {
    $clean = trim($raw);
    if ($clean === '') return 'Assort';
    foreach ($validProcessTags as $vpt) {
        if (strcasecmp($clean, $vpt['name']) === 0 || strcasecmp($clean, $vpt['code'] ?? '') === 0) {
            return $vpt['name'];
        }
    }
    if (stripos($clean, 'sell') !== false) return 'Sell';
    if (stripos($clean, 'hold') !== false) return 'Hold';
    if (stripos($clean, 'assort') !== false) return 'Assort';
    return ucfirst($clean);
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
        $sheetXml = @simplexml_load_string($sheetContent);
        if ($sheetXml && isset($sheetXml->sheetData->row)) {
            foreach ($sheetXml->sheetData->row as $r) {
                $row = [];
                $lastColIdx = -1;
                foreach ($r->c as $c) {
                    $cellRef = (string)$c['r'];
                    preg_match('/^([A-Z]+)(\d+)$/', $cellRef, $matches);
                    $colLetters = $matches[1] ?? '';
                    $colIdx = 0;
                    if ($colLetters !== '') {
                        $len = strlen($colLetters);
                        for ($k = 0; $k < $len; $k++) {
                            $colIdx = $colIdx * 26 + (ord($colLetters[$k]) - ord('A') + 1);
                        }
                        $colIdx--;
                    }

                    while ($lastColIdx < $colIdx - 1) {
                        $row[] = '';
                        $lastColIdx++;
                    }

                    $t = (string)$c['t'];
                    $v = (string)$c->v;
                    $val = '';
                    if ($t === 's') {
                        $val = $strings[(int)$v] ?? '';
                    } else {
                        $val = $v;
                    }
                    $row[] = $val;
                    $lastColIdx = $colIdx;
                }
                $rows[] = $row;
            }
        }
    }

    $zip->close();
    return $rows;
}

// Helper: Normalize rows and auto-map headers strictly focusing on requested fields
function map_imported_rows(array $rawRows, array $masterTags, array $masterProcessTags, int $defaultDueDays = 50): array {
    $headerRowIdx = -1;
    $colMap = [];

    // Search for header row
    foreach ($rawRows as $idx => $row) {
        $cleanRow = array_map(fn($v) => strtolower(trim((string)$v)), $row);
        $score = 0;
        foreach ($cleanRow as $col) {
            if (str_contains($col, 'packet') || str_contains($col, 'પેકેટ') || $col === 'no' || $col === 'lot') $score += 3;
            if (str_contains($col, 'weight') || str_contains($col, 'carat') || str_contains($col, 'વજન') || $col === 'wt') $score += 3;
            if (str_contains($col, 'price') || str_contains($col, 'rate') || str_contains($col, 'ભાવ')) $score += 2;
            if (str_contains($col, 'party') || str_contains($col, 'supplier') || str_contains($col, 'વેપારી') || str_contains($col, 'પાર્ટી')) $score += 2;
            if (str_contains($col, 'broker') || str_contains($col, 'dalal') || str_contains($col, 'દલાલ')) $score += 2;
            if (str_contains($col, 'due') || str_contains($col, 'મુદ્દત') || str_contains($col, 'મુદત') || str_contains($col, 'mudat') || str_contains($col, 'muddat') || str_contains($col, 'terms') || str_contains($col, 'days')) $score += 2;
            if (str_contains($col, 'tag') || str_contains($col, 'ટેગ')) $score += 2;
            if (str_contains($col, 'date') || str_contains($col, 'તારીખ')) $score += 2;
            if (str_contains($col, 'pcs') || str_contains($col, 'qty') || str_contains($col, 'નંગ')) $score += 2;
        }

        if ($score >= 4) {
            $headerRowIdx = $idx;
            foreach ($cleanRow as $cIdx => $name) {
                if (empty($name)) continue;

                // 1. Process Tag vs Tag
                if ((str_contains($name, 'process tag') || str_contains($name, 'process_tag') || str_contains($name, 'પ્રોસેસ')) && !isset($colMap['process_tag'])) {
                    $colMap['process_tag'] = $cIdx;
                } elseif ((str_contains($name, 'tag') || str_contains($name, 'ટેગ') || str_contains($name, 'પ્રકાર')) && !isset($colMap['tag'])) {
                    $colMap['tag'] = $cIdx;
                }
                // 2. Brokerage % vs Broker
                elseif ((str_contains($name, 'brokerage') || str_contains($name, 'brockrage') || str_contains($name, 'દલાલી') || str_contains($name, 'brok') || str_contains($name, '%')) && !isset($colMap['brokerage_percent']) && (str_contains($name, '%') || str_contains($name, 'rate') || str_contains($name, 'pct') || str_contains($name, 'ટકા'))) {
                    $colMap['brokerage_percent'] = $cIdx;
                }
                // 3. Broker / Dalal
                elseif ((str_contains($name, 'broker') || str_contains($name, 'dalal') || str_contains($name, 'દલાલ') || str_contains($name, 'બ્રોકર')) && !isset($colMap['broker'])) {
                    $colMap['broker'] = $cIdx;
                }
                // 4. Due Date vs Due Days (Check Due Date first so it doesn't get confused)
                elseif ((
                    str_contains($name, 'due date') || str_contains($name, 'due_date') || str_contains($name, 'duedate') ||
                    str_contains($name, 'due dt') || str_contains($name, 'મુદત તારીખ') || str_contains($name, 'મુદ્દત તારીખ') ||
                    str_contains($name, 'ચુકવણી તારીખ') || str_contains($name, 'આખરી તારીખ')
                ) && !isset($colMap['due_date'])) {
                    $colMap['due_date'] = $cIdx;
                }
                // 5. Due Days / Terms / Mudat
                elseif ((
                    str_contains($name, 'due day') || str_contains($name, 'due_day') || str_contains($name, 'duedays') || str_contains($name, 'due-day') ||
                    str_contains($name, 'mudat') || str_contains($name, 'muddat') || str_contains($name, 'modat') || str_contains($name, 'mudaat') ||
                    str_contains($name, 'મુદત') || str_contains($name, 'મુદ્દત') ||
                    str_contains($name, 'term') ||
                    str_contains($name, 'cr day') || str_contains($name, 'credit day') || str_contains($name, 'crday') ||
                    str_contains($name, 'p day') || str_contains($name, 'pday') || str_contains($name, 'pay day') ||
                    str_contains($name, 'days') || str_contains($name, 'દિવસ') ||
                    $name === 'due' || $name === 'dues' || $name === 'day'
                ) && !isset($colMap['due_days'])) {
                    $colMap['due_days'] = $cIdx;
                }
                // 6. Packet No
                elseif ((str_contains($name, 'packet') || str_contains($name, 'lot') || str_contains($name, 'પેકેટ') || $name === 'no') && !isset($colMap['packet_no'])) {
                    $colMap['packet_no'] = $cIdx;
                }
                // 7. Purchase Date
                elseif ((str_contains($name, 'date') || str_contains($name, 'તારીખ') || $name === 'dt') && !isset($colMap['date'])) {
                    $colMap['date'] = $cIdx;
                }
                // 8. Pcs / Qty
                elseif ((str_contains($name, 'pcs') || str_contains($name, 'qty') || str_contains($name, 'pieces') || str_contains($name, 'નંગ') || str_contains($name, 'જથ્થો')) && !isset($colMap['pcs'])) {
                    $colMap['pcs'] = $cIdx;
                }
                // 9. Weight
                elseif ((str_contains($name, 'weight') || str_contains($name, 'carat') || str_contains($name, 'cts') || str_contains($name, 'વજન') || $name === 'wt') && !isset($colMap['weight'])) {
                    $colMap['weight'] = $cIdx;
                }
                // 10. Price per carat
                elseif ((str_contains($name, 'price') || str_contains($name, 'rate') || str_contains($name, 'ભાવ') || str_contains($name, 'દર')) && !isset($colMap['price_per_carat'])) {
                    $colMap['price_per_carat'] = $cIdx;
                }
                // 11. Supplier Party
                elseif ((str_contains($name, 'party') || str_contains($name, 'supplier') || str_contains($name, 'vendor') || str_contains($name, 'વેપારી') || str_contains($name, 'પાર્ટી')) && !isset($colMap['supplier_party'])) {
                    $colMap['supplier_party'] = $cIdx;
                }
                // 12. Destination
                elseif ((str_contains($name, 'destination') || str_contains($name, 'dest') || str_contains($name, 'સ્થળ') || str_contains($name, 'શહેર') || str_contains($name, 'city')) && !isset($colMap['destination'])) {
                    $colMap['destination'] = $cIdx;
                }
                // 13. Safe
                elseif ((str_contains($name, 'safe') || str_contains($name, 'vault') || str_contains($name, 'સેફ') || str_contains($name, 'તિજોરી')) && !isset($colMap['safe'])) {
                    $colMap['safe'] = $cIdx;
                }
                // 14. Dabba Number
                elseif ((str_contains($name, 'dabba') || str_contains($name, 'daba') || str_contains($name, 'box') || str_contains($name, 'ડબ્બા') || str_contains($name, 'ડબ્બો')) && !isset($colMap['dabba'])) {
                    $colMap['dabba'] = $cIdx;
                }
                // 15. Description
                elseif ((str_contains($name, 'desc') || str_contains($name, 'detail') || str_contains($name, 'વિગત') || str_contains($name, 'વર્ણન') || str_contains($name, 'remark') || str_contains($name, 'નોંધ')) && !isset($colMap['description'])) {
                    $colMap['description'] = $cIdx;
                }
            }
            break;
        }
    }

    if ($headerRowIdx === -1 || !isset($colMap['packet_no']) || !isset($colMap['weight'])) {
        return ['error' => 'Header Row ઓળખાયી નથી. ખાતરી કરો કે ફાઇલમાં "packet no", "weight", "price per carat", "tag", "process tag", "supplier party", "broker", "due days", "brokerage %" જેવા હેડર છે.'];
    }

    $parsedLots = [];
    for ($i = $headerRowIdx + 1; $i < count($rawRows); $i++) {
        $r = $rawRows[$i];
        $packetNo = trim((string)($r[$colMap['packet_no']] ?? ''));
        if ($packetNo === '') continue; // Skip empty rows

        $weight = (float)str_replace(',', '', (string)($r[$colMap['weight'] ?? -1] ?? 0));
        if ($weight <= 0) continue;

        // Purchase Date
        $rawDate = isset($colMap['date']) ? trim((string)($r[$colMap['date']] ?? '')) : '';
        $purchaseDate = normalize_import_date($rawDate);

        // Due Days & Due Date resolution strictly from upload file first
        $rawDueDays = isset($colMap['due_days']) ? trim((string)($r[$colMap['due_days']] ?? '')) : '';
        $rawDueDate = isset($colMap['due_date']) ? trim((string)($r[$colMap['due_date']] ?? '')) : '';

        $resolvedDueDays = null;
        $resolvedDueDate = null;

        // 1. Try resolving Due Days from rawDueDays (number, text like "45 days", "60 D", or date)
        if ($rawDueDays !== '') {
            // If cell has date format or Excel serial date (> 30000)
            if ((is_numeric($rawDueDays) && (float)$rawDueDays > 30000 && (float)$rawDueDays < 60000) || preg_match('/[\/\-\.]/', $rawDueDays)) {
                $parsedDt = normalize_import_date($rawDueDays);
                if ($parsedDt && $parsedDt !== date('Y-m-d')) {
                    $resolvedDueDate = $parsedDt;
                    $diff = (int)round((strtotime($resolvedDueDate) - strtotime($purchaseDate)) / 86400);
                    $resolvedDueDays = max(0, $diff);
                }
            }
            if ($resolvedDueDays === null && preg_match('/(\d+)/', $rawDueDays, $m)) {
                $resolvedDueDays = (int)$m[1];
            }
        }

        // 2. If Due Days not found, but Due Date column exists in file
        if ($resolvedDueDays === null && $rawDueDate !== '') {
            $parsedDt = normalize_import_date($rawDueDate);
            if ($parsedDt) {
                $resolvedDueDate = $parsedDt;
                $diff = (int)round((strtotime($resolvedDueDate) - strtotime($purchaseDate)) / 86400);
                $resolvedDueDays = max(0, $diff);
            }
        }

        // 3. Fallback only if no due days specified anywhere in the uploaded row
        $dueDays = ($resolvedDueDays !== null) ? $resolvedDueDays : $defaultDueDays;
        $dueDate = $resolvedDueDate ?: date('Y-m-d', strtotime("$purchaseDate + $dueDays days"));

        // Brokerage %
        $rawBrokerage = isset($colMap['brokerage_percent']) ? trim((string)($r[$colMap['brokerage_percent']] ?? '')) : '0';
        $rawBrokerage = str_replace('%', '', $rawBrokerage);
        $brokeragePercent = is_numeric($rawBrokerage) ? round((float)$rawBrokerage, 2) : 0.00;

        // Destination
        $destination = isset($colMap['destination']) ? trim((string)($r[$colMap['destination']] ?? '')) : '';
        if ($destination === '') $destination = 'Surat';

        // Safe
        $safe = isset($colMap['safe']) ? trim((string)($r[$colMap['safe']] ?? '')) : '';
        if ($safe === '') $safe = 'OFC';

        // Dabba Number
        $dabba = isset($colMap['dabba']) ? trim((string)($r[$colMap['dabba']] ?? '')) : '';

        $paymentAmt = round($weight * $priceCt, 2);
        $brokerageAmt = round($paymentAmt * ($brokeragePercent / 100), 2);
        $netPayment = round($paymentAmt + $brokerageAmt, 2);
        $avgSize = ($pcs > 0) ? round($weight / $pcs, 3) : 0.000;

        $parsedLots[] = [
            'packet_no' => $packetNo,
            'purchase_date' => $purchaseDate,
            'pcs' => $pcs,
            'weight' => $weight,
            'avg_size' => $avgSize,
            'price_per_carat' => $priceCt,
            'tag' => $tag,
            'process_tag' => $processTag,
            'description' => $desc,
            'supplier_party' => $supplierParty,
            'broker' => $broker,
            'due_days' => $dueDays,
            'due_date' => $dueDate,
            'brokerage_percent' => $brokeragePercent,
            'brokerage_amount' => $brokerageAmt,
            'destination' => $destination,
            'safe' => $safe,
            'dabba' => $dabba,
            'payment_amount' => $paymentAmt,
            'net_payment' => $netPayment
        ];
    }

    return ['success' => true, 'lots' => $parsedLots];
}

$previewLots = [];
$step = 'upload'; // upload | preview

// POST Action: Preview or Final Commit
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'preview') {
        $rawRows = [];

        // 1. File Upload
        if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['import_file']['tmp_name'];
            $fileName = $_FILES['import_file']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            if ($ext === 'xlsx') {
                $rawRows = parse_xlsx_data($tmpPath);
            } elseif ($ext === 'csv' || $ext === 'txt') {
                $handle = fopen($tmpPath, 'r');
                while (($data = fgetcsv($handle, 10000, ',')) !== false) {
                    $rawRows[] = $data;
                }
                fclose($handle);
            } else {
                set_flash('error', 'અમાન્ય ફાઇલ ફોર્મેટ! માત્ર .xlsx અથવા .csv ફાઇલ અપલોડ કરો.');
            }
        } 
        // 2. Direct Copy-Paste
        elseif (!empty(trim($_POST['paste_data'] ?? ''))) {
            $pasteLines = explode("\n", trim($_POST['paste_data']));
            foreach ($pasteLines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (str_contains($line, "\t")) {
                    $rawRows[] = explode("\t", $line);
                } else {
                    $rawRows[] = str_getcsv($line);
                }
            }
        }

        if (empty($rawRows)) {
            set_flash('error', 'કોઈ ડેટા મળ્યો નથી! કૃપા કરીને એક્સેલ ફાઇલ પસંદ કરો અથવા ડેટા પેસ્ટ કરો.');
        } else {
            $initialDefaultDueDays = isset($_POST['default_due_days']) ? (int)$_POST['default_due_days'] : 50;
            $result = map_imported_rows($rawRows, $masterTags, $masterProcessTags, $initialDefaultDueDays);
            if (isset($result['error'])) {
                set_flash('error', $result['error']);
            } elseif (empty($result['lots'])) {
                set_flash('error', 'ફાઇલમાં કોઈ માન્ય રફ સ્ટોક રેકોર્ડ મળ્યો નથી.');
            } else {
                $previewLots = $result['lots'];
                $step = 'preview';
            }
        }
    } elseif ($action === 'commit') {
        // Final Database Commit
        $submittedLots = json_decode($_POST['lots_json'] ?? '[]', true);
        $defaultSafe = !empty($_POST['default_safe']) ? trim($_POST['default_safe']) : 'OFC';
        $defaultDestination = !empty($_POST['default_destination']) ? trim($_POST['default_destination']) : 'Surat';
        $fallbackPartyName = trim($_POST['fallback_party'] ?? '');
        $fallbackBrokerName = trim($_POST['fallback_broker'] ?? '');
        $defaultDueDays = (int)($_POST['default_due_days'] ?? 50);
        $onDuplicate = $_POST['on_duplicate'] ?? 'skip'; // skip | suffix | update

        if (empty($submittedLots)) {
            set_flash('error', 'આયાત કરવા માટે કોઈ ડેટા નથી.');
        } else {
            $inserted = 0;
            $updated = 0;
            $skipped = 0;

            $pdo->beginTransaction();
            try {
                // Statements for party lookup & creation
                $findPartyStmt = $pdo->prepare("SELECT id FROM parties WHERE LOWER(TRIM(party_name)) = LOWER(?) LIMIT 1");
                $createPartyStmt = $pdo->prepare("INSERT INTO parties (party_name, party_type, opening_balance, current_balance, status) VALUES (?, 'party', 0.00, 0.00, 'active')");
                $createBrokerStmt = $pdo->prepare("INSERT INTO parties (party_name, party_type, opening_balance, current_balance, status) VALUES (?, 'broker', 0.00, 0.00, 'active')");

                // Check existing purchase
                $checkStmt = $pdo->prepare("SELECT id FROM rough_purchases WHERE purchase_no = ?");
                
                // Insert statement with Destination, Safe, Dabba, Broker, Due Days, Brokerage %, Tag and Process Tag
                $insertStmt = $pdo->prepare("
                    INSERT INTO rough_purchases (
                        purchase_no, purchase_date, payment_currency, party_id, broker_id,
                        pcs, weight, available_weight, available_pcs,
                        dollar_per_carat, dollar_rate, price_per_carat_inr,
                        payment_amount, brokerage_percent, brokerage_amount,
                        ac_gst_amount, net_payment, due_days, due_date,
                        party_payment_status, destination, safe, dabba, product_description,
                        tag, process_tag, status, created_by
                    ) VALUES (
                        ?, ?, 'INR', ?, ?,
                        ?, ?, ?, ?,
                        0.00, 0.00, ?,
                        ?, ?, ?,
                        0.00, ?, ?, ?,
                        'pending', ?, ?, ?, ?,
                        ?, ?, 'in_stock', ?
                    )
                ");

                // Update statement if replacing
                $updateStmt = $pdo->prepare("
                    UPDATE rough_purchases SET
                        purchase_date = ?, party_id = ?, broker_id = ?, pcs = ?, weight = ?,
                        available_weight = ?, available_pcs = ?, price_per_carat_inr = ?,
                        payment_amount = ?, brokerage_percent = ?, brokerage_amount = ?, net_payment = ?, 
                        due_days = ?, due_date = ?, destination = ?, safe = ?, dabba = ?, 
                        product_description = ?, tag = ?, process_tag = ?
                    WHERE id = ?
                ");

                // Cache parties & brokers map during this transaction
                $partyCache = [];
                $brokerCache = [];

                foreach ($submittedLots as $lot) {
                    $pktNo = trim((string)$lot['packet_no']);
                    if ($pktNo === '') continue;

                    $checkStmt->execute([$pktNo]);
                    $existingId = $checkStmt->fetchColumn();

                    if ($existingId) {
                        if ($onDuplicate === 'skip') {
                            $skipped++;
                            continue;
                        } elseif ($onDuplicate === 'suffix') {
                            $pktNo = $pktNo . '-' . date('dHis') . rand(10, 99);
                        }
                    }

                    // 1. Resolve Supplier Party
                    $partyName = trim((string)($lot['supplier_party'] ?? ''));
                    if ($partyName === '') {
                        $partyName = !empty($fallbackPartyName) ? $fallbackPartyName : 'Direct Rough Supplier';
                    }

                    $cacheKey = strtolower($partyName);
                    if (isset($partyCache[$cacheKey])) {
                        $partyId = $partyCache[$cacheKey];
                    } else {
                        $findPartyStmt->execute([$partyName]);
                        $foundId = $findPartyStmt->fetchColumn();
                        if ($foundId) {
                            $partyId = (int)$foundId;
                        } else {
                            $createPartyStmt->execute([$partyName]);
                            $partyId = (int)$pdo->lastInsertId();
                        }
                        $partyCache[$cacheKey] = $partyId;
                    }

                    // 2. Resolve Broker
                    $brokerName = trim((string)($lot['broker'] ?? ''));
                    if ($brokerName === '' && !empty($fallbackBrokerName)) {
                        $brokerName = $fallbackBrokerName;
                    }

                    $brokerId = null;
                    if ($brokerName !== '' && strcasecmp($brokerName, 'direct') !== 0 && strcasecmp($brokerName, 'none') !== 0) {
                        $bCacheKey = strtolower($brokerName);
                        if (isset($brokerCache[$bCacheKey])) {
                            $brokerId = $brokerCache[$bCacheKey];
                        } else {
                            $findPartyStmt->execute([$brokerName]);
                            $foundBId = $findPartyStmt->fetchColumn();
                            if ($foundBId) {
                                $brokerId = (int)$foundBId;
                            } else {
                                $createBrokerStmt->execute([$brokerName]);
                                $brokerId = (int)$pdo->lastInsertId();
                            }
                            $brokerCache[$bCacheKey] = $brokerId;
                        }
                    }

                    $purchaseDate = !empty($lot['purchase_date']) ? $lot['purchase_date'] : date('Y-m-d');
                    $dueDays = isset($lot['due_days']) && $lot['due_days'] !== '' ? (int)$lot['due_days'] : $defaultDueDays;
                    if ($dueDays < 0) $dueDays = 0;
                    $dueDate = !empty($lot['due_date']) ? $lot['due_date'] : date('Y-m-d', strtotime("$purchaseDate + $dueDays days"));

                    $weight = (float)$lot['weight'];
                    $pcs = (int)$lot['pcs'];
                    $priceCt = (float)$lot['price_per_carat'];
                    $paymentAmt = round($weight * $priceCt, 2);

                    $brokeragePct = (float)($lot['brokerage_percent'] ?? 0.00);
                    $brokerageAmt = round($paymentAmt * ($brokeragePct / 100), 2);
                    $netPayment = round($paymentAmt + $brokerageAmt, 2);

                    $tag = !empty($lot['tag']) ? $lot['tag'] : 'Rough';
                    $processTag = !empty($lot['process_tag']) ? $lot['process_tag'] : 'Assort';
                    $destination = !empty($lot['destination']) ? $lot['destination'] : $defaultDestination;
                    $safe = !empty($lot['safe']) ? $lot['safe'] : $defaultSafe;
                    $dabba = !empty($lot['dabba']) ? $lot['dabba'] : null;
                    $desc = $lot['description'] ?? '';

                    if ($existingId && $onDuplicate === 'update') {
                        $updateStmt->execute([
                            $purchaseDate, $partyId, $brokerId, $pcs, $weight,
                            $weight, $pcs, $priceCt,
                            $paymentAmt, $brokeragePct, $brokerageAmt, $netPayment,
                            $dueDays, $dueDate,
                            $destination, $safe, $dabba, $desc, $tag, $processTag,
                            $existingId
                        ]);
                        $updated++;
                    } else {
                        $insertStmt->execute([
                            $pktNo, $purchaseDate, $partyId, $brokerId,
                            $pcs, $weight, $weight, $pcs,
                            $priceCt,
                            $paymentAmt, $brokeragePct, $brokerageAmt,
                            $netPayment, $dueDays, $dueDate,
                            $destination, $safe, $dabba, $desc,
                            $tag, $processTag,
                            $user['id']
                        ]);
                        $inserted++;
                    }
                }

                $pdo->commit();
                log_activity('Bulk Rough Import', 'Rough', 0, "Bulk imported $inserted lots, updated $updated lots (skipped $skipped)");
                
                $msg = "સફળતાપૂર્વક $inserted રફ લૉટ્સ સ્ટોકમાં ઉમેરાયા છે!";
                if ($updated > 0) $msg .= " ($updated અપડેટ કર્યા)";
                if ($skipped > 0) $msg .= " ($skipped ડુપ્લિકેટ પેકેટ છોડી દીધા)";
                
                set_flash('success', $msg);
                header('Location: ' . BASE_URL . '/modules/rough/index.php?tab=stock');
                exit;
            } catch (\Throwable $ex) {
                $pdo->rollBack();
                set_flash('error', 'બલ્ક ડેટા સેવ કરવામાં ક્ષતિ: ' . $ex->getMessage());
            }
        }
    }
}

$pageTitle = 'Rough Bulk Import (બલ્ક રફ સ્ટોક આયાત)';
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'Rough Bulk Import (બલ્ક સ્ટોક આયાત)',
    'પેકેટ નં, તારીખ, નંગ, વજન, ભાવ/કેરેટ, Tag, Process Tag, વિગત, સપ્લાયર, દલાલ, મુદ્દત દિવસો, દલાલી %, Destination, Safe & ડબ્બા નંબર',
    'file-excel',
    '<div class="d-flex gap-2">
        <a href="' . BASE_URL . '/modules/rough_purchase/import.php?action=download_template" class="btn btn-outline-success btn-sm">' . svg_icon('file-arrow-down', 'me-1') . 'સેમ્પલ એક્સેલ ટેમ્પલેટ</a>
        <a href="' . BASE_URL . '/modules/rough/index.php?tab=stock" class="btn btn-outline-secondary btn-sm">' . svg_icon('arrow-left', 'me-1') . 'Rough સ્ટોક પર પાછા</a>
    </div>',
    'emerald'
) ?>

<?php if ($step === 'upload'): ?>
<div class="row justify-content-center">
    <div class="col-12 col-xl-11">
        <div class="erp-form-card mb-4">
            <div class="erp-card-header bg-white py-3 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="fw-bold text-dark fs-6">
                    <?= svg_icon('file-excel', 'text-success me-2') ?>૧. એક્સેલ ફાઇલ અપલોડ કરો અથવા ડેટા પેસ્ટ કરો
                </span>
                <a href="<?= BASE_URL ?>/modules/rough_purchase/import.php?action=download_template" class="badge bg-success-subtle text-success text-decoration-none border border-success-subtle p-2">
                    <?= svg_icon('file-arrow-down', 'me-1') ?>સેમ્પલ ટેમ્પલેટ ડાઉનલોડ કરો (.CSV)
                </a>
            </div>
            <div class="erp-card-body p-4">
                <form action="" method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="preview">

                    <!-- Excel Columns Required Hint Banner -->
                    <div class="p-3 mb-4 rounded-3 bg-light border">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-success">સંપૂર્ણ ૧૫ કોલમો (Bulk Upload Fields)</span>
                                <small class="text-muted fw-semibold">તમારી એક્સેલ ફાઇલમાં આ ક્રમ અથવા હેડર હોવા જોઈએ:</small>
                            </div>
                            <a href="<?= BASE_URL ?>/modules/rough_purchase/import.php?action=download_template" class="small text-success text-decoration-none fw-bold">
                                <?= svg_icon('download', 'me-1', 13) ?>ટેમ્પલેટ ડાઉનલોડ
                            </a>
                        </div>
                        <div class="d-flex flex-wrap gap-1 font-mono small">
                            <span class="badge bg-white text-dark border">1. packet no</span>
                            <span class="badge bg-white text-dark border">2. date</span>
                            <span class="badge bg-white text-dark border">3. pcs (Qty)</span>
                            <span class="badge bg-white text-success border border-success">4. weight</span>
                            <span class="badge bg-white text-primary border border-primary">5. price per carat</span>
                            <span class="badge bg-white text-info border border-info">6. tag (Rough/Tops/Rejection)</span>
                            <span class="badge bg-white text-warning text-dark border border-warning">7. process tag (Sell/Hold/Assort)</span>
                            <span class="badge bg-white text-muted border">8. description</span>
                            <span class="badge bg-white text-purple border" style="color:#6f42c1;border-color:#d6b6f7;">9. supplier party</span>
                            <span class="badge bg-white text-primary border border-primary">10. broker (દલાલ)</span>
                            <span class="badge bg-white text-dark border">11. due days (મુદત / Terms / Days / Due Date)</span>
                            <span class="badge bg-white text-danger border border-danger">12. brokerage % (દલાલી)</span>
                            <span class="badge bg-white text-secondary border">13. destination</span>
                            <span class="badge bg-white text-secondary border">14. safe</span>
                            <span class="badge bg-white text-dark border border-dark">15. dabba</span>
                        </div>
                    </div>

                    <div class="row g-4 mb-4">
                        <!-- Option A: File Upload -->
                        <div class="col-md-6 border-end">
                            <label class="form-label fw-bold text-dark mb-2">
                                પદ્ધતિ A: એક્સેલ ફાઇલ અપલોડ (.xlsx અથવા .csv)
                            </label>
                            <div class="p-4 border border-2 border-dashed rounded-3 text-center bg-white hover-shadow">
                                <div class="mb-3 text-success">
                                    <?= svg_icon('file-excel', '', 42) ?>
                                </div>
                                <input type="file" name="import_file" id="import_file" class="form-control mb-2" accept=".xlsx, .csv, .txt">
                                <div class="small text-muted">
                                    તમારી રફ સ્ટોકની એક્સેલ અથવા CSV ફાઇલ પસંદ કરો.
                                </div>
                            </div>
                        </div>

                        <!-- Option B: Direct Copy-Paste -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-dark mb-2">
                                પદ્ધતિ B: એક્સેલમાંથી સીધો કોપી-પેસ્ટ (Copy & Paste)
                            </label>
                            <textarea name="paste_data" class="form-control font-mono small" rows="7" placeholder="એક્સેલમાંથી લાઇન કોપી કરીને અહીં પેસ્ટ કરો (Ctrl + V)...&#10;ઉદાહરણ:&#10;packet no	date	pcs (Qty)	weight	price per carat	tag	process tag	description	supplier party	broker	due days	brokerage %	destination	safe	dabba&#10;PKT-101	2026-11-17	1	424.990	25.00	Tops	Sell	Orange pink	Antwerp Rough Direct Ltd	Kanti Dalal Surat	60	1.00	Surat	OFC	12"></textarea>
                            <div class="small text-muted mt-1">
                                એક્સેલ શીટમાંથી હેડર સહિતની રોઝ કોપી કરીને સીધી અહીં પેસ્ટ કરી શકાય છે.
                            </div>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="submit" class="btn btn-primary btn-lg px-4 fw-bold shadow-sm">
                            <?= svg_icon('eye', 'me-1') ?>ડેટા ચકાસો & પ્રીવ્યૂ જુઓ (Preview & Verify) &rarr;
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($step === 'preview'): ?>
<!-- STEP 2: PREVIEW & FINAL COMMIT -->
<?php
    $totPreviewWt = 0.000;
    $totPreviewPcs = 0;
    $totPreviewPayment = 0.0;
    $totPreviewBrokerage = 0.0;
    $totPreviewNet = 0.0;
    $uniqueParties = [];
    $uniqueBrokers = [];
    foreach ($previewLots as $l) {
        $totPreviewWt += (float)$l['weight'];
        $totPreviewPcs += (int)$l['pcs'];
        $totPreviewPayment += (float)$l['payment_amount'];
        $totPreviewBrokerage += (float)$l['brokerage_amount'];
        $totPreviewNet += (float)$l['net_payment'];
        if (!empty($l['supplier_party'])) {
            $uniqueParties[$l['supplier_party']] = true;
        }
        if (!empty($l['broker'])) {
            $uniqueBrokers[$l['broker']] = true;
        }
    }
?>
<div class="row justify-content-center">
    <div class="col-12 col-xl-12">
        <form action="" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="commit">
            <textarea name="lots_json" class="d-none"><?= htmlspecialchars(json_encode($previewLots, JSON_UNESCAPED_UNICODE)) ?></textarea>

            <!-- Card: Batch Settings -->
            <div class="erp-form-card mb-4">
                <div class="erp-card-header bg-white py-3 px-3">
                    <span class="fw-bold text-dark fs-6">
                        <?= svg_icon('sliders', 'text-primary me-2') ?>૨. આ બલ્ક લૉટ્સ માટે મૂળભૂત વિકલ્પો (Import Settings)
                    </span>
                </div>
                <div class="erp-card-body p-3">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-dark">જો પાર્ટી ખાલી હોય (Fallback Party)</label>
                            <input type="text" name="fallback_party" class="form-control" placeholder="Direct Rough Supplier" value="Direct Rough Supplier">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-dark">જો દલાલ ખાલી હોય (Fallback Broker)</label>
                            <input type="text" name="fallback_broker" class="form-control" placeholder="Direct (કોઈ દલાલ નથી)" value="">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-dark">ડિફોલ્ટ મુદ્દત (Default Due Days)</label>
                            <div class="input-group">
                                <input type="number" id="global_default_due_days" name="default_due_days" class="form-control" value="<?= (int)($initialDefaultDueDays ?? 50) ?>" min="0">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btn_apply_all_due_days" title="બધા લૉટ્સ પર આ મુદ્દત લાગુ કરો">બધા પર</button>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-dark">મૂળભૂત તિજોરી (Default Safe)</label>
                            <select name="default_safe" class="form-select fw-semibold">
                                <?php foreach ($masterSafes as $sf): ?>
                                    <option value="<?= e($sf['name']) ?>" <?= $sf['name'] === 'OFC' ? 'selected' : '' ?>><?= e($sf['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-dark">ડુપ્લિકેટ પેકેટ નં. હોય તો</label>
                            <select name="on_duplicate" class="form-select">
                                <option value="skip">છોડી દો (Skip Existing)</option>
                                <option value="suffix">નવો નંબર બનાવી ઉમેરો (Add Unique Suffix)</option>
                                <option value="update">હાલના પેકેટની વિગત સુધારો (Update Existing)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card: Table Preview -->
            <div class="erp-form-card mb-4">
                <div class="erp-card-header bg-white py-3 px-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <span class="fw-bold text-dark fs-6">
                            <?= svg_icon('table', 'text-primary me-2') ?>૩. આયાત થનાર ડેટા પ્રીવ્યૂ (<?= count($previewLots) ?> લૉટ્સ તૈયાર)
                        </span>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-light text-dark border font-mono">પેકેટ: <strong><?= count($previewLots) ?></strong></span>
                        <span class="badge bg-light text-dark border font-mono">નંગ: <strong><?= number_format($totPreviewPcs) ?></strong></span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle font-mono">વજન: <strong><?= format_carat($totPreviewWt) ?></strong></span>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono">માલ રકમ: <strong><?= format_inr($totPreviewPayment) ?></strong></span>
                        <span class="badge bg-warning-subtle text-dark border border-warning-subtle font-mono">દલાલી રકમ: <strong><?= format_inr($totPreviewBrokerage) ?></strong></span>
                        <span class="badge bg-dark text-white font-mono">કુલ ચૂકવણી (Net): <strong><?= format_inr($totPreviewNet) ?></strong></span>
                    </div>
                </div>
                <div class="erp-card-body p-0">
                    <div class="table-responsive" style="max-height: 480px;">
                        <table class="table table-bordered table-hover table-diamond mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th class="ps-3" style="width: 40px;">#</th>
                                    <th>Packet No</th>
                                    <th>Date</th>
                                    <th>Supplier Party</th>
                                    <th>Broker (દલાલ)</th>
                                    <th class="text-end">Pcs</th>
                                    <th class="text-end">Weight (Ct)</th>
                                    <th class="text-end">Price / Ct</th>
                                    <th class="text-center" style="min-width: 110px;">Due Days (મુદ્દત)</th>
                                    <th class="text-center">Brokerage %</th>
                                    <th class="text-center">Tag</th>
                                    <th class="text-center">Process Tag</th>
                                    <th class="text-center">Safe / Dabba</th>
                                    <th>Description</th>
                                    <th class="text-end pe-3">Net Payment</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($previewLots as $i => $lot): ?>
                                    <tr>
                                        <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                                        <td class="font-mono fw-bold text-primary"><?= e($lot['packet_no']) ?></td>
                                        <td class="font-mono small text-muted"><?= e($lot['purchase_date']) ?></td>
                                        <td class="fw-semibold text-dark"><?= e($lot['supplier_party'] ?: 'Direct Purchase') ?></td>
                                        <td>
                                            <?php if (!empty($lot['broker'])): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= e($lot['broker']) ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-muted border">Direct</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end font-mono"><?= $lot['pcs'] ?></td>
                                        <td class="text-end font-mono fw-bold text-success"><?= format_carat($lot['weight']) ?></td>
                                        <td class="text-end font-mono"><?= format_inr($lot['price_per_carat']) ?></td>
                                        <td class="text-center font-mono small" style="min-width: 110px;">
                                            <div class="input-group input-group-sm justify-content-center">
                                                <input type="number" class="form-control form-control-sm font-mono text-center due-days-input fw-bold" 
                                                       data-idx="<?= $i ?>" data-pdate="<?= e($lot['purchase_date']) ?>"
                                                       value="<?= (int)$lot['due_days'] ?>" min="0" style="max-width: 65px;">
                                                <span class="input-group-text px-1 text-muted">D</span>
                                            </div>
                                            <div class="text-muted due-date-label-<?= $i ?>" style="font-size: 10px;"><?= e($lot['due_date']) ?></div>
                                        </td>
                                        <td class="text-center font-mono">
                                            <?php if ($lot['brokerage_percent'] > 0): ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= number_format($lot['brokerage_percent'], 2) ?>%</span>
                                                <div class="text-muted" style="font-size: 10px;">₹<?= number_format($lot['brokerage_amount'], 2) ?></div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?= render_tag_badge($lot['tag']) ?>
                                        </td>
                                        <td class="text-center">
                                            <?= render_process_tag_badge($lot['process_tag']) ?>
                                        </td>
                                        <td class="text-center small">
                                            <span class="badge bg-light text-dark border font-mono"><?= e($lot['safe'] ?: 'OFC') ?></span>
                                            <?php if (!empty($lot['dabba'])): ?>
                                                <span class="badge bg-warning-subtle text-dark border border-warning-subtle font-mono">D:<?= e($lot['dabba']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-truncate" style="max-width: 150px;" title="<?= e($lot['description']) ?>">
                                            <?= e($lot['description'] ?: '-') ?>
                                        </td>
                                        <td class="text-end pe-3 font-mono fw-bold text-dark">
                                            <?= format_inr($lot['net_payment']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="p-3 bg-light border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <a href="<?= BASE_URL ?>/modules/rough_purchase/import.php" class="btn btn-outline-secondary">
                        <?= svg_icon('arrow-left', 'me-1') ?>ફરીથી ફાઇલ અપલોડ કરો
                    </a>
                    <button type="submit" class="btn btn-success btn-lg px-4 fw-bold shadow">
                        <?= svg_icon('check-double', 'me-1') ?>બધા <?= count($previewLots) ?> પેકેટ્સ સ્ટોકમાં સેવ કરો (Confirm & Import) &rarr;
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
window.previewLotsData = <?= !empty($previewLots) ? json_encode($previewLots, JSON_UNESCAPED_UNICODE) : '[]' ?>;

function updateLotDueDays(idx, days) {
    if (!window.previewLotsData || !window.previewLotsData[idx]) return;
    const pDate = window.previewLotsData[idx].purchase_date || new Date().toISOString().split('T')[0];
    const d = new Date(pDate);
    d.setDate(d.getDate() + days);
    const newDueDate = d.toISOString().split('T')[0];

    const label = document.querySelector('.due-date-label-' + idx);
    if (label) label.innerText = newDueDate;

    window.previewLotsData[idx].due_days = days;
    window.previewLotsData[idx].due_date = newDueDate;

    const jsonArea = document.querySelector('textarea[name="lots_json"]');
    if (jsonArea) jsonArea.value = JSON.stringify(window.previewLotsData);
}

document.querySelectorAll('.due-days-input').forEach(input => {
    input.addEventListener('input', function() {
        const idx = parseInt(this.dataset.idx);
        const days = parseInt(this.value) || 0;
        updateLotDueDays(idx, days);
    });
});

document.getElementById('btn_apply_all_due_days')?.addEventListener('click', function() {
    const globalDays = parseInt(document.getElementById('global_default_due_days').value) || 0;
    document.querySelectorAll('.due-days-input').forEach(input => {
        input.value = globalDays;
        const idx = parseInt(input.dataset.idx);
        updateLotDueDays(idx, globalDays);
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
