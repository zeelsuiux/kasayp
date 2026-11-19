<?php
/**
 * RBAC User Management (સેક્શન ૬.૧૧)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_login();
$user = current_user();

require_role(['admin']);
$pdo = getDbConnection();

// Add User Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    validate_csrf();

    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = $_POST['role'] ?? 'operator';

    if (empty($username) || empty($fullName) || empty($password)) {
        set_flash('error', 'કૃપા કરીને યુઝરનેમ, પૂરું નામ અને પાસવર્ડ દાખલ કરો.');
    } else {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (username, full_name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$username, $fullName, $email, $hash, $role]);

            log_activity('User Created', 'Admin', (int)$pdo->lastInsertId(), "Created user $username with role $role");
            set_flash('success', "યુઝર '$username' ($role) સફળતાપૂર્વક બની ગયો છે.");
            header('Location: ' . BASE_URL . '/modules/admin/users.php');
            exit;
        } catch (\Throwable $ex) {
            set_flash('error', 'યુઝર બનાવવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

// Toggle user status (Secure POST with CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    validate_csrf();
    $tId = (int)($_POST['user_id'] ?? 0);
    if ($tId !== 1) { // Protect super admin
        $pdo->prepare("UPDATE users SET status = IF(status='active', 'inactive', 'active') WHERE id = ?")->execute([$tId]);
        log_activity('User Status Toggled', 'Admin', $tId, "Toggled status for user ID $tId");
        set_flash('success', 'યુઝર સ્થિતિ સફળતાપૂર્વક બદલાઈ ગઈ.');
        header('Location: ' . BASE_URL . '/modules/admin/users.php');
        exit;
    }
}

// Reset Password & Update Role Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    validate_csrf();
    $uId = (int)($_POST['user_id'] ?? 0);
    $newPass = $_POST['new_password'] ?? '';
    $newRole = $_POST['new_role'] ?? 'operator';
    
    if (!empty($newPass)) {
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = ?, role = ? WHERE id = ?")->execute([$hash, $newRole, $uId]);
        log_activity('User Password Reset', 'Admin', $uId, "Password reset for user ID $uId");
        set_flash('success', 'પાસવર્ડ અને રોલ સફળતાપૂર્વક અપડેટ થઈ ગયા.');
    } else {
        $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $uId]);
        set_flash('success', 'યુઝર રોલ સફળતાપૂર્વક અપડેટ થયો.');
    }
    header('Location: ' . BASE_URL . '/modules/admin/users.php');
    exit;
}

$users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();

$adminCount = 0;
$opCount = 0;
foreach ($users as $u) {
    if (in_array($u['role'], ['admin', 'manager'])) $adminCount++;
    else $opCount++;
}

$pageTitle = 'ટીમ મેમ્બર અને પરવાનગી (Team Members & User Management)';
require_once __DIR__ . '/../../includes/header.php';

$adminTabs = [
    [
        'url' => BASE_URL . '/modules/admin/users.php',
        'label' => 'ટીમ મેમ્બર્સ (Team Members)',
        'icon' => 'users-gear',
        'active' => true,
        'count' => count($users)
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
    'ટીમ મેમ્બર અને પરવાનગી (Team Members & Roles)',
    'ઓપરેટર્સ, એકાઉન્ટન્ટ્સ અને એડમિન એક્સેસ',
    'users-gear',
    '<button class="btn btn-primary px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#newUserModal">' . svg_icon('user-plus', 'me-1') . 'નવો મેમ્બર ઉમેરો (Add Member)</button>',
    'primary'
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('કુલ સિસ્ટમ યુઝર્સ (Total Users)', (string)count($users) . ' એકાઉન્ટ્સ', 'એક્ટિવ પ્રોફાઇલ્સ', 'ERP એક્સેસ', 'hero', null, '', 'users') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('એડમિન & મેનેજર્સ (Admin / Manager)', (string)$adminCount . ' સુપર યુઝર્સ', 'પૂર્ણ નિયંત્રણ', 'સિક્યુરિટી & ઓડિટ', 'white', null, 'pill-mint', 'shield-halved') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('ઓપરેટર્સ & એકાઉન્ટન્ટ્સ (Staff)', (string)$opCount . ' યુઝર્સ', 'ડેટા એન્ટ્રી & બિલિંગ', 'રોલ પરવાનગી', 'white', null, 'pill-mint', 'user-tie') ?>
    </div>
</div>

<div class="card-erp">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>યુઝરનેમ</th>
                        <th>પૂરું નામ</th>
                        <th>ઈમેલ</th>
                        <th>રોલ / પરવાનગી</th>
                        <th>છેલ્લું લોગિન</th>
                        <th>સ્થિતિ</th>
                        <th class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <strong class="text-dark fs-6"><?= e($u['username']) ?></strong>
                            </td>
                            <td><?= e($u['full_name']) ?></td>
                            <td><?= e($u['email'] ?? '-') ?></td>
                            <td>
                                <?php
                                $rMap = [
                                    'admin' => ['Super Admin (સર્વસત્તા)', 'bg-danger-subtle text-danger'],
                                    'manager' => ['Production Manager', 'bg-primary-subtle text-primary'],
                                    'accountant' => ['Accountant (નાણાં)', 'bg-success-subtle text-success'],
                                    'operator' => ['Operator (સ્કેનર/ડેટા)', 'bg-light text-dark']
                                ];
                                $rBadge = $rMap[$u['role']] ?? [e($u['role']), 'bg-light text-dark'];
                                ?>
                                <span class="badge <?= $rBadge[1] ?> border"><?= $rBadge[0] ?></span>
                            </td>
                            <td><?= !empty($u['last_login']) ? date('d-m-Y H:i', strtotime($u['last_login'])) : '-' ?></td>
                            <td>
                                <?php if ($u['status'] === 'active'): ?>
                                    <span class="badge bg-success-subtle text-success border"><?= svg_icon('check', 'me-1') ?>Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#editUserModal<?= $u['id'] ?>" title="પાસવર્ડ અથવા રોલ બદલો">
                                    <?= svg_icon('key') ?>
                                </button>
                                <?php if ($u['id'] !== 1): ?>
                                    <form action="<?= BASE_URL ?>/modules/admin/users.php" method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm <?= $u['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                            <?= $u['status'] === 'active' ? 'નિષ્ક્રિય' : 'સક્રિય' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small">પ્રાથમિક એડમિન</span>
                                <?php endif; ?>

                                <!-- Modal: Reset Password / Edit Role -->
                                <div class="modal fade" id="editUserModal<?= $u['id'] ?>" tabindex="-1">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content text-start">
                                            <form action="<?= BASE_URL ?>/modules/admin/users.php" method="POST">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="reset_password">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">

                                                <div class="modal-header">
                                                    <h6 class="modal-title fw-bold">યુઝર સેટિંગ્સ: <?= e($u['full_name']) ?> (@<?= e($u['username']) ?>)</h6>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold text-muted">રોલ / પરવાનગી</label>
                                                        <select name="new_role" class="form-select">
                                                            <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin (પૂર્ણ વહીવટ)</option>
                                                            <option value="manager" <?= $u['role'] === 'manager' ? 'selected' : '' ?>>Manager (સ્ટોક & પ્રોડક્શન)</option>
                                                            <option value="accountant" <?= $u['role'] === 'accountant' ? 'selected' : '' ?>>Accountant (નાણાકીય હિસાબ)</option>
                                                            <option value="operator" <?= $u['role'] === 'operator' ? 'selected' : '' ?>>Operator (માત્ર બારકોડ સ્કેન)</option>
                                                        </select>
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="form-label small fw-bold text-muted">નવો પાસવર્ડ (New Password)</label>
                                                        <input type="password" name="new_password" class="form-control" placeholder="પાસવર્ડ બદલવો હોય તો જ લખો">
                                                        <small class="text-muted">જૂનો પાસવર્ડ રાખવા માટે ખાલી રાખો.</small>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">રદ કરો</button>
                                                    <button type="submit" class="btn btn-primary btn-sm fw-bold">સેવ કરો</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: New User -->
<div class="modal fade" id="newUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= BASE_URL ?>/modules/admin/users.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_user">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><?= svg_icon('user-plus', 'me-2 text-primary') ?>નવો યુઝર ઉમેરો</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">યુઝરનેમ (Username) *</label>
                        <input type="text" name="username" class="form-control" required placeholder="દા.ત. rajesh_surat">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">પૂરું નામ (Full Name) *</label>
                        <input type="text" name="full_name" class="form-control" required placeholder="દા.ત. રાજેશભાઈ પટેલ">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">ઈમેલ</label>
                        <input type="email" name="email" class="form-control" placeholder="user@company.com">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">પાસવર્ડ *</label>
                        <input type="password" name="password" class="form-control" required placeholder="••••••••">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">રોલ / પરવાનગી *</label>
                        <select name="role" class="form-select fw-semibold" required>
                            <option value="operator">Operator (બારકોડ સ્કેન અને પ્રોસેસ એન્ટ્રી)</option>
                            <option value="accountant">Accountant (નાણાં, બિલો અને લેણાં/દેવાં)</option>
                            <option value="manager">Production Manager (Kapan, Assort અને પ્રોડક્શન)</option>
                            <option value="admin">Super Admin (તમામ સત્તાઓ)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-primary fw-bold">યુઝર સેવ કરો</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
