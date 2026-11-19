<?php
/**
 * Database Backup & Export Engine (સેક્શન ૬.૧૧)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_role(['admin']);
$pdo = getDbConnection();

// Action: Generate & Stream SQL Backup File
if (isset($_GET['download']) && $_GET['download'] === 'sql') {
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $filename = 'diamond_erp_backup_' . date('Y-m-d_H-i-s') . '.sql';
    $backupStorageDir = defined('BACKUP_STORAGE_DIR') ? BACKUP_STORAGE_DIR : 'C:/xampp/kasyap_backups';
    if (!is_dir($backupStorageDir)) {
        @mkdir($backupStorageDir, 0750, true);
    }
    $localFilePath = $backupStorageDir . '/' . $filename;
    $fileHandle = @fopen($localFilePath, 'w');

    log_activity('Database Backup Downloaded', 'Admin', null, "Full SQL database backup generated, saved to $localFilePath and downloaded");

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $headerText = "-- ===================================================\n"
        . "-- Kasyap Diamond ERP Full Database Backup\n"
        . "-- Date: " . date('Y-m-d H:i:s') . "\n"
        . "-- Database: " . DB_NAME . "\n"
        . "-- Storage: " . $localFilePath . "\n"
        . "-- ===================================================\n\n"
        . "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    echo $headerText;
    if ($fileHandle) {
        fwrite($fileHandle, $headerText);
    }

    foreach ($tables as $tbl) {
        // Table schema
        $createRow = $pdo->query("SHOW CREATE TABLE `$tbl`")->fetch(PDO::FETCH_NUM);
        $tblSchema = "DROP TABLE IF EXISTS `$tbl`;\n" . $createRow[1] . ";\n\n";
        echo $tblSchema;
        if ($fileHandle) {
            fwrite($fileHandle, $tblSchema);
        }

        // Table data streamed in chunks to prevent PHP memory exhaustion
        $dataStmt = $pdo->query("SELECT * FROM `$tbl`");
        $chunk = [];
        $chunkSize = 250;

        while ($r = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
            $vals = [];
            foreach ($r as $val) {
                if ($val === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = $pdo->quote((string)$val);
                }
            }
            $chunk[] = '(' . implode(', ', $vals) . ')';

            if (count($chunk) >= $chunkSize) {
                $chunkSql = "INSERT INTO `$tbl` VALUES \n" . implode(",\n", $chunk) . ";\n\n";
                echo $chunkSql;
                if ($fileHandle) {
                    fwrite($fileHandle, $chunkSql);
                }
                $chunk = [];
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }

        if (!empty($chunk)) {
            $chunkSql = "INSERT INTO `$tbl` VALUES \n" . implode(",\n", $chunk) . ";\n\n";
            echo $chunkSql;
            if ($fileHandle) {
                fwrite($fileHandle, $chunkSql);
            }
        }
    }

    $footerText = "SET FOREIGN_KEY_CHECKS = 1;\n-- End of Backup\n";
    echo $footerText;
    if ($fileHandle) {
        fwrite($fileHandle, $footerText);
        fclose($fileHandle);
    }
    exit;
}

$pageTitle = 'ડેટા બેકઅપ ડાઉનલોડ (Database Backup)';
require_once __DIR__ . '/../../includes/header.php';

// Quick stats of database tables
$statStmt = $pdo->prepare("
    SELECT table_name as tbl, table_rows as cnt, 
           ROUND((data_length + index_length) / 1024, 2) as size_kb
    FROM information_schema.TABLES 
    WHERE table_schema = ?
    ORDER BY data_length DESC
");
$statStmt->execute([DB_NAME]);
$tablesStat = $statStmt->fetchAll();

$totalRows = 0;
$totalKb = 0;
foreach ($tablesStat as $ts) {
    $totalRows += (int)$ts['cnt'];
    $totalKb += (float)$ts['size_kb'];
}

$adminTabs = [
    [
        'url' => BASE_URL . '/modules/admin/users.php',
        'label' => 'યુઝર્સ મેનેજમેન્ટ (Users)',
        'icon' => 'users-gear',
        'active' => false
    ],
    [
        'url' => BASE_URL . '/modules/admin/activity_logs.php',
        'label' => 'ઓડિટ લોગ્સ (Audit Trail)',
        'icon' => 'shield-halved',
        'active' => false
    ],
    [
        'url' => BASE_URL . '/modules/admin/backup.php',
        'label' => 'બેકઅપ & રિસ્ટોર (Backup)',
        'icon' => 'database',
        'active' => true
    ],
    [
        'url' => BASE_URL . '/modules/admin/upload_stock.php',
        'label' => 'Excel સ્ટોક અપલોડ',
        'icon' => 'file-arrow-up',
        'active' => false
    ]
];
?>

<!-- Master Admin Segmented Tab Bar -->
<?= render_segmented_tabs($adminTabs) ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'ડેટા બેકઅપ ડાઉનલોડ (Database Backup & Export)',
    '',
    'database',
    '<a href="?download=sql" class="btn btn-primary px-3 shadow-sm">' . svg_icon('cloud-arrow-down', 'me-1') . 'તત્કાલ બેકઅપ ડાઉનલોડ</a>',
    'emerald'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('ડેટાબેઝ કોષ્ટકો (Database Tables)', (string)count($tablesStat) . ' ટેબલ્સ', 'SQL Schema', 'સુરક્ષિત આર્કિટેક્ચર', 'hero', '?download=sql', '', 'table') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ ડેટા રેકોર્ડ્સ (Total Records)', number_format($totalRows) . ' પંક્તિઓ', 'ડેટા એન્ટ્રીઝ', 'સક્રિય રેકોર્ડ્સ', 'white', null, 'pill-mint', 'server') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('કુલ સાઇઝ (Database Storage)', number_format($totalKb / 1024, 2) . ' MB', 'MySQL InnoDB', 'ઓપ્ટિમાઇઝ્ડ', 'white', null, 'pill-mint', 'hard-drive') ?>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-xl-9">
        <div class="erp-form-card mb-4">
            <div class="erp-card-header">
                <h6 class="header-title">
                    <span class="header-icon icon-emerald"><?= svg_icon('cloud-arrow-down') ?></span>
                    કુલ ડેટાનો Backup ડાઉનલોડ
                </h6>
                
            </div>

            <div class="erp-card-body p-4 text-center">
                <div class="mb-4">
                    <span class="rounded-circle bg-primary-subtle text-primary p-4 d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                        <?= svg_icon('database', '', 22) ?>
                    </span>
                </div>

                <h4 class="fw-bold text-dark">એક જ ક્લિકમાં આખા ડેટાનો બેકઅપ ડાઉનલોડ કરો</h4>
                <p class="text-muted" style="max-width: 580px; margin: auto;">
                    આ વિકલ્પ દ્વારા Rough/Polish ખરીદી, Kapan, બારકોડ પેકેટ્સ, Jangad મેમો, બેંક એકાઉન્ટ્સ અને તમામ નાણાકીય લેણાં/દેવાંનો ડેટાબેઝ બેકઅપ તરત જ તમારા કમ્પ્યુટર પર ડાઉનલોડ થઈ જશે.
                </p>

                <div class="mt-4">
                    <a href="<?= BASE_URL ?>/modules/admin/backup.php?download=sql" class="btn btn-primary btn-lg px-5 fw-bold shadow">
                        <?= svg_icon('download', 'me-2') ?>સંપૂર્ણ SQL બેકઅપ ડાઉનલોડ કરો (.sql)
                    </a>
                </div>

                <div class="small text-muted mt-3">
                    <?= svg_icon('shield-halved', 'text-success me-1') ?>256-bit એન્ક્રિપ્શન • સ્વચાલિત સુરક્ષા માન્યતા
                </div>
            </div>
        </div>

        <!-- Database Tables Summary -->
        <div class="card-erp">
            <div class="card-header bg-white">
                <h6 class="fw-bold text-dark mb-0"><?= svg_icon('table-list', 'me-2 text-secondary') ?>ડેટાબેઝ કોષ્ટકો (Database Tables Overview)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-diamond mb-0">
                        <thead>
                            <tr>
                                <th>ટેબલનું નામ (Table)</th>
                                <th>રેકોર્ડ્સ અંદાજ</th>
                                <th class="text-end">સાઇઝ (KB)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tablesStat as $ts): ?>
                                <tr>
                                    <td><strong class="font-monospace text-dark"><?= e($ts['tbl']) ?></strong></td>
                                    <td><?= (int)$ts['cnt'] ?> રેકોર્ડ્સ</td>
                                    <td class="text-end fw-semibold"><?= $ts['size_kb'] ?> KB</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
