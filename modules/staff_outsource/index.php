<?php
/**
 * Staff Payroll & Outsource Vendors (સેક્શન ૬.૯)
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
$pdo = getDbConnection();

// Handle Employee Add/Edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_employee') {
    validate_csrf();
    $empId = (int)($_POST['emp_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $empCode = trim($_POST['emp_code'] ?? '');
    $designation = trim($_POST['designation'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $salaryType = in_array($_POST['salary_type'] ?? '', ['monthly', 'daily', 'per_piece']) ? $_POST['salary_type'] : 'monthly';
    $basicSalary = (float)($_POST['basic_salary'] ?? 0.00);
    $joiningDate = !empty($_POST['joining_date']) ? $_POST['joining_date'] : date('Y-m-d');
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

    if (empty($name)) {
        set_flash('error', 'કૃપા કરીને કર્મચારીનું નામ દાખલ કરો.');
    } else {
        try {
            if ($empId > 0) {
                $stmt = $pdo->prepare("UPDATE employees SET name = ?, emp_code = ?, designation = ?, phone = ?, salary_type = ?, basic_salary = ?, joining_date = ?, status = ? WHERE id = ?");
                $stmt->execute([$name, $empCode, $designation, $phone, $salaryType, $basicSalary, $joiningDate, $status, $empId]);
                log_activity('Employee Updated', 'Staff', $empId, "Updated employee $name ($empCode)");
                set_flash('success', "કર્મચારી '$name' સફળતાપૂર્વક અપડેટ થઈ ગયો છે.");
            } else {
                if (empty($empCode)) {
                    $empCode = generate_next_code('EMP', 'employees', 'emp_code');
                }
                $stmt = $pdo->prepare("INSERT INTO employees (emp_code, name, designation, phone, salary_type, basic_salary, joining_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$empCode, $name, $designation, $phone, $salaryType, $basicSalary, $joiningDate, $status]);
                $newId = (int)$pdo->lastInsertId();
                log_activity('Employee Created', 'Staff', $newId, "Added employee $name ($empCode)");
                set_flash('success', "કર્મચારી '$name' સફળતાપૂર્વક ઉમેરાઈ ગયો છે.");
            }
        } catch (\Throwable $ex) {
            set_flash('error', 'કર્મચારી સાચવવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
    header('Location: ' . BASE_URL . '/modules/staff_outsource/index.php');
    exit;
}

$pageTitle = 'સ્ટાફ અને Outsource હિસાબ (Staff & Outsource)';
require_once __DIR__ . '/../../includes/header.php';

// Fetch In-house Staff
$staff = $pdo->query("SELECT e.*, 
                      (SELECT COALESCE(SUM(amount), 0) FROM salary_payments WHERE employee_id = e.id) as total_paid
                      FROM employees e ORDER BY e.id ASC")->fetchAll();

// Fetch Outsource Vendors
$outsourceVendors = $pdo->query("SELECT p.*,
                                 (SELECT COALESCE(SUM(total_amount), 0) FROM outsource_bills WHERE party_id = p.id) as total_billed,
                                 (SELECT COALESCE(SUM(paid_amount), 0) FROM outsource_bills WHERE party_id = p.id) as total_paid,
                                 (SELECT COUNT(*) FROM jangad_vouchers WHERE party_id = p.id AND status IN ('issued', 'partially_returned')) as pending_jangad
                                 FROM parties p
                                 WHERE p.party_type IN ('outsource_galaxy', 'outsource_ls', 'outsource_factory')
                                 ORDER BY p.party_name ASC")->fetchAll();
$partyCount = (int)$pdo->query("SELECT COUNT(*) FROM parties WHERE party_type IN ('party', 'broker', 'both')")->fetchColumn();
$staffCount = count($staff);

$totSalary = 0.00;
foreach ($staff as $st) {
    $totSalary += (float)($st['salary'] ?? 0);
}
$totOutsourceDue = 0.00;
foreach ($outsourceVendors as $ov) {
    $totOutsourceDue += ((float)$ov['total_billed'] - (float)$ov['total_paid']);
}
?>

<!-- Master Directory Segmented Tab Bar -->
<?= render_segmented_tabs([
    ['url' => BASE_URL . '/modules/parties/index.php', 'label' => 'પાર્ટી અને દલાલ ડિરેક્ટરી', 'icon' => 'address-book', 'count' => $partyCount],
    ['url' => BASE_URL . '/modules/staff_outsource/index.php', 'label' => 'સ્ટાફ અને કારીગરો (Staff)', 'icon' => 'users', 'count' => $staffCount, 'active' => true],
    ['url' => BASE_URL . '/modules/staff_outsource/salary.php', 'label' => 'પગાર રજિસ્ટર (Salary)', 'icon' => 'money-bill-wave'],
    ['url' => BASE_URL . '/modules/staff_outsource/outsource_bills.php', 'label' => 'વેન્ડર & કારખાના બિલ', 'icon' => 'receipt'],
]) ?>

<!-- Standardized Enterprise Page Header -->
<?= render_page_header(
    'સ્ટાફ પગાર અને Outsource વ્યવસ્થાપન',
    '',
    'users',
    '
    <a href="' . BASE_URL . '/modules/staff_outsource/salary.php" class="btn btn-primary px-3 shadow-sm">
        ' . svg_icon('money-bill-wave', 'me-1') . 'પગાર ચુકવણી (Pay Salary)
    </a>
    <a href="' . BASE_URL . '/modules/staff_outsource/outsource_bills.php" class="btn btn-outline-dark px-3 shadow-sm">
        ' . svg_icon('file-invoice-dollar', 'me-1') . 'Outsource બિલો
    </a>
    '
) ?>

<!-- Donezo Metric Stat Cards -->
<div class="row g-2.5 mb-3">
    <div class="col-12 col-md-4">
        <?= render_stat_card('ઇન-હાઉસ સ્ટાફ (In-House Staff)', (string)$staffCount . ' કર્મચારીઓ', 'સક્રિય સ્ટાફ', 'ઓફિસ & કારખાનું', 'hero', BASE_URL . '/modules/staff_outsource/salary.php', '', 'user-check') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('માસિક પગાર (Monthly Payroll)', format_inr($totSalary), 'ફિક્સ પગાર', 'દરેક મહિને ચુકવવાપાત્ર', 'white', null, 'pill-mint', 'money-bill-wave') ?>
    </div>
    <div class="col-12 col-sm-6 col-md-4">
        <?= render_stat_card('આઉટસોર્સ બાકી બિલો (Vendor Due)', format_inr($totOutsourceDue), 'ચૂકવવાપાત્ર રકમ', 'Galaxy, LS & Polish', 'white', BASE_URL . '/modules/staff_outsource/outsource_bills.php', 'pill-coral', 'clock-rotate-left') ?>
    </div>
</div>

<!-- SECTION 1: IN-HOUSE EMPLOYEES -->
<div class="card-erp mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0"><?= svg_icon('users', 'me-2 text-primary') ?>In-house Staff પગાર હિસાબ</h6>
        <div class="d-flex align-items-center gap-2">
            
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#employeeModal" onclick="resetEmployeeModal()">
                <?= svg_icon('plus', 'me-1') ?>નવો કર્મચારી
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>કોડ / નામ</th>
                        <th>હોદ્દો (Designation)</th>
                        <th>પગાર પ્રકાર</th>
                        <th>મૂળ પગાર (Basic Salary)</th>
                        <th>જોડાણ તારીખ</th>
                        <th>કુલ ચૂકવેલ પગાર</th>
                        <th>સ્થિતિ</th>
                        <th class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff as $emp): ?>
                        <tr>
                            <td>
                                <strong><?= e($emp['name']) ?></strong>
                                <div class="small text-muted"><?= e($emp['emp_code']) ?> • <?= e($emp['phone'] ?? '-') ?></div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?= e($emp['designation']) ?></span></td>
                            <td><span class="text-uppercase small"><?= e($emp['salary_type']) ?></span></td>
                            <td><strong class="text-dark fs-6"><?= format_inr($emp['basic_salary']) ?></strong></td>
                            <td><?= format_date($emp['joining_date']) ?></td>
                            <td><strong class="text-success"><?= format_inr($emp['total_paid']) ?></strong></td>
                            <td><span class="badge bg-success-subtle text-success">Active</span></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1 btn-edit-emp"
                                    data-id="<?= $emp['id'] ?>"
                                    data-code="<?= e($emp['emp_code']) ?>"
                                    data-name="<?= e($emp['name']) ?>"
                                    data-desig="<?= e($emp['designation']) ?>"
                                    data-phone="<?= e($emp['phone'] ?? '') ?>"
                                    data-saltype="<?= e($emp['salary_type']) ?>"
                                    data-salary="<?= (float)$emp['basic_salary'] ?>"
                                    data-jdate="<?= e($emp['joining_date']) ?>"
                                    data-status="<?= e($emp['status']) ?>"
                                    title="કર્મચારી એડિટ કરો">
                                    <?= svg_icon('pen-to-square') ?>
                                </button>
                                <a href="<?= BASE_URL ?>/modules/staff_outsource/salary.php?emp_id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <?= svg_icon('hand-holding-dollar', 'me-1') ?>પગાર ચૂકવો
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SECTION 2: OUTSOURCE VENDORS (GALAXY, LS, FACTORY) -->
<div class="card-erp">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="fw-bold text-dark mb-0"><?= svg_icon('industry', 'me-2 text-danger') ?>Outsource વેન્ડર્સ Live હિસાબ (Galaxy, LS, કારખાનું)</h6>
        <a href="<?= BASE_URL ?>/modules/staff_outsource/outsource_bills.php" class="btn btn-sm btn-outline-danger"><?= svg_icon('plus', 'me-1') ?>નવું બિલ ઉમેરો</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-diamond mb-0">
                <thead>
                    <tr>
                        <th>વેન્ડરનું નામ</th>
                        <th>સેવા પ્રકાર (Service)</th>
                        <th>શહેર / ફોન</th>
                        <th>પેન્ડિંગ Jangad (માલ)</th>
                        <th>કુલ બિલ રકમ</th>
                        <th>ચૂકવેલ રકમ</th>
                        <th>બાકી ચૂકવવાપાત્ર (Balance Due)</th>
                        <th class="text-end">એક્શન</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($outsourceVendors as $ov): ?>
                        <?php $balDue = (float)$ov['total_billed'] - (float)$ov['total_paid']; ?>
                        <tr>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $ov['id'] ?>&tab=jangad" class="fs-6 fw-bold text-primary text-decoration-none" title="વેન્ડર લેજર & જાંગડ હિસાબ જુઓ">
                                    <?= e($ov['party_name']) ?>
                                </a>
                            </td>
                            <td>
                                <?php
                                $sMap = [
                                    'outsource_galaxy' => 'Galaxy Scanning',
                                    'outsource_ls' => 'Laser Sawing LS',
                                    'outsource_factory' => 'Diamond Factory'
                                ];
                                ?>
                                <span class="badge bg-light text-dark border"><?= $sMap[$ov['party_type']] ?? e($ov['party_type']) ?></span>
                            </td>
                            <td><?= e($ov['city']) ?> • <?= e($ov['phone'] ?? '-') ?></td>
                            <td>
                                <?php if ((int)$ov['pending_jangad'] > 0): ?>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border"><?= svg_icon('clock', 'me-1') ?><?= (int)$ov['pending_jangad'] ?> જાંગડ બાકી</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">માલ ક્લિયર</span>
                                <?php endif; ?>
                            </td>
                            <td><?= format_inr($ov['total_billed']) ?></td>
                            <td><strong class="text-success"><?= format_inr($ov['total_paid']) ?></strong></td>
                            <td>
                                <?php if ($balDue > 0): ?>
                                    <strong class="text-danger fs-6"><?= format_inr($balDue) ?></strong>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success">ક્લિયર</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="<?= BASE_URL ?>/modules/parties/edit.php?id=<?= $ov['id'] ?>" class="btn btn-sm btn-outline-secondary me-1" title="વેન્ડર એડિટ કરો">
                                    <?= svg_icon('pen-to-square') ?>
                                </a>
                                <a href="<?= BASE_URL ?>/modules/staff_outsource/outsource_bills.php?party_id=<?= $ov['id'] ?>" class="btn btn-sm btn-outline-dark">
                                    <?= svg_icon('file-invoice', 'me-1') ?>બિલો જુઓ
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Add / Edit Employee -->
<div class="modal fade" id="employeeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= BASE_URL ?>/modules/staff_outsource/index.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_employee">
                <input type="hidden" name="emp_id" id="emp_id" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark" id="empModalTitle"><?= svg_icon('user-plus', 'me-2 text-primary') ?>કર્મચારી વિગત</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-muted">કોડ (Code)</label>
                            <input type="text" name="emp_code" id="emp_code" class="form-control font-mono" placeholder="ઓટો">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label small fw-bold text-muted">કર્મચારીનું નામ *</label>
                            <input type="text" name="name" id="emp_name" class="form-control fw-bold" placeholder="પૂરું નામ" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">હોદ્દો (Designation) *</label>
                            <input type="text" name="designation" id="emp_designation" class="form-control" placeholder="દા.ત. Galaxy Operator, Manager" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">મોબાઈલ નંબર</label>
                            <input type="text" name="phone" id="emp_phone" class="form-control font-mono" placeholder="98250 XXXXX">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">પગાર પ્રકાર (Salary Type)</label>
                            <select name="salary_type" id="emp_salary_type" class="form-select">
                                <option value="monthly">Monthly (માસિક)</option>
                                <option value="daily">Daily (દૈનિક)</option>
                                <option value="per_piece">Per Piece (નંગ દીઠ)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">મૂળ પગાર / દર (₹) *</label>
                            <input type="number" step="0.01" name="basic_salary" id="emp_basic_salary" class="form-control font-mono fw-bold" value="0.00" required>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">જોડાણ તારીખ (Joining Date)</label>
                            <input type="date" name="joining_date" id="emp_joining_date" class="form-control font-mono" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted">સ્થિતિ (Status)</label>
                            <select name="status" id="emp_status" class="form-select">
                                <option value="active">Active (ચાલુ)</option>
                                <option value="inactive">Inactive (બંધ)</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                    <button type="submit" class="btn btn-primary fw-bold" id="empModalSubmit">સેવ કરો</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetEmployeeModal() {
    document.getElementById('emp_id').value = '';
    document.getElementById('emp_code').value = '';
    document.getElementById('emp_name').value = '';
    document.getElementById('emp_designation').value = '';
    document.getElementById('emp_phone').value = '';
    document.getElementById('emp_salary_type').value = 'monthly';
    document.getElementById('emp_basic_salary').value = '0.00';
    document.getElementById('emp_joining_date').value = '<?= date('Y-m-d') ?>';
    document.getElementById('emp_status').value = 'active';
    document.getElementById('empModalTitle').innerHTML = window.svgIcon('user-plus', 'me-2 text-primary') + 'નવો કર્મચારી ઉમેરો';
    document.getElementById('empModalSubmit').innerText = 'કર્મચારી સેવ કરો';
}

document.addEventListener('DOMContentLoaded', function() {
    const empModalEl = document.getElementById('employeeModal');
    if (!empModalEl) return;
    const empModal = new bootstrap.Modal(empModalEl);

    document.querySelectorAll('.btn-edit-emp').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('emp_id').value = this.dataset.id;
            document.getElementById('emp_code').value = this.dataset.code;
            document.getElementById('emp_name').value = this.dataset.name;
            document.getElementById('emp_designation').value = this.dataset.desig;
            document.getElementById('emp_phone').value = this.dataset.phone;
            document.getElementById('emp_salary_type').value = this.dataset.saltype;
            document.getElementById('emp_basic_salary').value = this.dataset.salary;
            document.getElementById('emp_joining_date').value = this.dataset.jdate;
            document.getElementById('emp_status').value = this.dataset.status || 'active';
            document.getElementById('empModalTitle').innerHTML = window.svgIcon('pen-to-square', 'me-2 text-primary') + 'કર્મચારી એડિટ કરો';
            document.getElementById('empModalSubmit').innerText = 'ફેરફારો સેવ કરો';
            empModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
