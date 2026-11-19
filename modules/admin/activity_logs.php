<?php
/**
 * Security Activity Logs / Audit Trail
 * Diamond ERP
 */

declare(strict_types=1);

$pageTitle = 'સિક્યુરિટી ઓડિટ લોગ્સ (Activity Logs)';
require_once __DIR__ . '/../../includes/header.php';

require_role(['admin']);
$pdo = getDbConnection();

$stmt = $pdo->query("SELECT al.*, u.username, u.full_name
                     FROM activity_logs al
                     LEFT JOIN users u ON al.user_id = u.id
                     ORDER BY al.id DESC LIMIT 100");
$logs = $stmt->fetchAll();
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
        'active' => true,
        'badge' => count($logs)
    ],
    [
        'url' => BASE_URL . '/modules/admin/backup.php',
        'label' => 'બેકઅપ & રિસ્ટોર (Backup)',
        'icon' => 'database',
        'active' => false
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
    'સિક્યુરિટી ઓડિટ લોગ્સ (Security Audit Trail)',
    'સિસ્ટમમાં થયેલી તમામ મહત્વપૂર્ણ પ્રવૃત્તિઓ, યુઝર, ટાઈમસ્ટેમ્પ અને IP એડ્રેસ',
    'shield-halved',
    '<button onclick="window.print()" class="btn btn-outline-dark px-3 shadow-sm">' . svg_icon('print', 'me-1') . 'પ્રિન્ટ લોગ્સ</button>',
    'purple'
) ?>

<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>તારીખ / સમય</th>
                        <th>યુઝર</th>
                        <th>મોડ્યુલ (Module)</th>
                        <th>પ્રવૃત્તિ (Action)</th>
                        <th>વિગત (Details)</th>
                        <th>IP એડ્રેસ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">કોઈ લોગ મળ્યા નથી.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td>
                                    <span class="font-monospace small"><?= date('d-m-Y H:i:s', strtotime($l['created_at'])) ?></span>
                                </td>
                                <td>
                                    <strong><?= e($l['full_name'] ?? 'System') ?></strong>
                                    <small class="text-muted d-block"><?= e($l['username'] ?? '') ?></small>
                                </td>
                                <td><span class="badge bg-light text-dark border text-uppercase"><?= e($l['module']) ?></span></td>
                                <td><strong class="text-primary"><?= e($l['action']) ?></strong></td>
                                <td><?= e($l['details'] ?? '-') ?></td>
                                <td><span class="badge bg-secondary-subtle text-secondary font-monospace"><?= e($l['ip_address'] ?? '127.0.0.1') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
