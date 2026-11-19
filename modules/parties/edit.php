<?php
/**
 * Edit Party / Customer / Broker Entry
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

$partyId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($partyId <= 0) {
    set_flash('error', 'અમાન્ય પાર્ટી ID.');
    header('Location: ' . BASE_URL . '/modules/parties/index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM parties WHERE id = ?");
$stmt->execute([$partyId]);
$party = $stmt->fetch();

if (!$party) {
    set_flash('error', 'પાર્ટી રેકોર્ડ મળ્યો નથી.');
    header('Location: ' . BASE_URL . '/modules/parties/index.php');
    exit;
}

$currentRoles = !empty($party['roles']) ? (is_array($party['roles']) ? $party['roles'] : (json_decode($party['roles'], true) ?: [])) : [];

// Determine active mode: broker, outsource, or party
if ($party['party_type'] === 'broker' || in_array('rough_purchase_broker', $currentRoles) || in_array('rough_sale_broker', $currentRoles) || in_array('polish_purchase_broker', $currentRoles) || in_array('polish_sale_broker', $currentRoles)) {
    $mode = 'broker';
} elseif (strpos($party['party_type'], 'outsource_') !== false || in_array($party['party_type'], ['lab_gia', 'factory', 'galaxy', 'ls']) || in_array('outsource_galaxy', $currentRoles) || in_array('outsource_ls', $currentRoles) || in_array('outsource_factory', $currentRoles) || in_array('lab_gia', $currentRoles)) {
    $mode = 'outsource';
} else {
    $mode = 'party';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    validate_csrf();

    $name    = trim($_POST['party_name'] ?? '');
    $type    = trim($_POST['party_type'] ?? $party['party_type']);
    $status  = in_array($_POST['status'] ?? '', ['active', 'blocked']) ? $_POST['status'] : 'active';
    $blockReason = trim($_POST['block_reason'] ?? '');
    $roles   = isset($_POST['roles']) && is_array($_POST['roles']) ? array_values(array_filter($_POST['roles'])) : [];
    
    // Auto align type based on mode & selected roles
    if ($mode === 'outsource') {
        if (!empty($roles[0])) {
            $type = $roles[0];
        } else {
            $type = 'outsource_factory';
        }
    } elseif ($mode === 'broker') {
        $type = 'broker';
    } else {
        $type = 'party';
    }

    $rolesJson = !empty($roles) ? json_encode($roles, JSON_UNESCAPED_UNICODE) : null;
    $contact = trim($_POST['contact_person'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $city    = $_POST['city'] ?? 'Surat';
    $address = trim($_POST['address'] ?? '');
    $panGst  = trim($_POST['pan_gst'] ?? '');
    $notes   = trim($_POST['notes'] ?? '');

    if (empty($name)) {
        set_flash('error', 'કૃપા કરીને નામ દાખલ કરો.');
    } elseif (empty($roles)) {
        set_flash('error', 'કૃપા કરીને ઓછામાં ઓછો એક રોલ (વેપાર ક્ષેત્ર) સિલેક્ટ કરો.');
    } else {
        try {
            $blockedAt = ($status === 'blocked') ? (!empty($party['blocked_at']) ? $party['blocked_at'] : date('Y-m-d H:i:s')) : null;
            $stmt = $pdo->prepare("UPDATE parties 
                                   SET party_name = ?, party_type = ?, status = ?, block_reason = ?, blocked_at = ?, roles = ?, contact_person = ?, phone = ?, city = ?, address = ?, pan_gst = ?, notes = ?
                                   WHERE id = ?");
            $stmt->execute([$name, $type, $status, ($status === 'blocked' ? $blockReason : null), $blockedAt, $rolesJson, $contact, $phone, $city, $address, $panGst, $notes, $partyId]);

            $logLabel = match($mode) {
                'broker' => 'Broker Updated',
                'outsource' => 'Outsource Unit Updated',
                default => 'Party Updated'
            };
            log_activity($logLabel, 'Party', $partyId, "Updated $name ($type, $status) in $city");
            
            set_flash('success', "વિગતો સફળતાપૂર્વક અપડેટ કરવામાં આવી છે.");
            header('Location: ' . BASE_URL . '/modules/parties/view.php?id=' . $partyId);
            exit;
        } catch (\Throwable $ex) {
            set_flash('error', 'અપડેટ કરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

$pageTitle = ($mode === 'broker' ? 'દલાલ એડિટ કરો: ' : ($mode === 'outsource' ? 'આઉટસોર્સ યુનિટ એડિટ કરો: ' : 'પાર્ટી એડિટ કરો: ')) . e($party['party_name']);
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row mb-3 align-items-center">
    <div class="col-md-7">
        <h4 class="fw-bold mb-1 text-dark">
            <?= svg_icon('pen-to-square', 'text-primary me-2') ?>
            <?= $mode === 'broker' ? 'દલાલ એડિટ કરો (Edit Broker)' : ($mode === 'outsource' ? 'આઉટસોર્સ યુનિટ એડિટ કરો (Edit Outsource)' : 'પાર્ટી એડિટ કરો (Edit Party / Customer)') ?>
        </h4>
        <div class="text-muted small">નામ, ભૂમિકાઓ, સંપર્ક, સરનામું અને વિગતો અપડેટ કરો</div>
    </div>
    <div class="col-md-5 text-md-end mt-2 mt-md-0">
        <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $partyId ?>" class="btn btn-outline-secondary btn-sm px-3 me-2">
            <?= svg_icon('receipt', 'me-1') ?>ખાતાવહી પર પાછા
        </a>
        <a href="<?= BASE_URL ?>/modules/parties/index.php<?= $mode === 'broker' ? '?type=broker' : ($mode === 'outsource' ? '?type=outsource' : '?type=party') ?>" class="btn btn-outline-secondary btn-sm px-3">
            <?= svg_icon('arrow-left', 'me-1') ?>ડિરેક્ટરી
        </a>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-xl-8">
        <form action="<?= BASE_URL ?>/modules/parties/edit.php?id=<?= $partyId ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $partyId ?>">
            <input type="hidden" name="party_type" value="<?= e($party['party_type']) ?>">

            <!-- Section 1: Basic Information -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon"><?= svg_icon($mode === 'broker' ? 'handshake' : ($mode === 'outsource' ? 'industry' : 'building')) ?></span>
                        ૧. <?= $mode === 'broker' ? 'દલાલ મૂળભૂત પ્રોફાઇલ (Broker Profile)' : ($mode === 'outsource' ? 'આઉટસોર્સ પ્રોફાઇલ (Outsource Profile)' : 'પાર્ટી મૂળભૂત પ્રોફાઇલ (Basic Profile)') ?>
                    </h6>
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label small fw-bold text-muted">
                                <?php if ($mode === 'broker'): ?>
                                    દલાલનું નામ (Broker / Firm Name) *
                                <?php elseif ($mode === 'outsource'): ?>
                                    આઉટસોર્સ યુનિટ / કારખાનાનું નામ (Unit Name) *
                                <?php else: ?>
                                    પાર્ટીનું નામ (Customer / Party Name) *
                                <?php endif; ?>
                            </label>
                            <input type="text" name="party_name" class="form-control form-control-lg fw-bold" value="<?= e($party['party_name']) ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">વ્યવહાર સ્ટેટસ (Status) *</label>
                            <select name="status" id="party_status_select" class="form-select form-select-lg fw-bold <?= ($party['status'] ?? 'active') === 'blocked' ? 'border-danger text-danger bg-danger-subtle' : 'border-success text-success bg-success-subtle' ?>" onchange="document.getElementById('blockReasonWrap').style.display = (this.value === 'blocked') ? 'block' : 'none'">
                                <option value="active" <?= ($party['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active (સક્રિય)</option>
                                <option value="blocked" <?= ($party['status'] ?? 'active') === 'blocked' ? 'selected' : '' ?>>🛑 Blocked (બ્લોક)</option>
                            </select>
                        </div>

                        <div class="col-12" id="blockReasonWrap" style="<?= ($party['status'] ?? 'active') === 'blocked' ? '' : 'display: none;' ?>">
                            <label class="form-label small fw-bold text-danger">બ્લોક કરવાનું કારણ (Block Reason)</label>
                            <input type="text" name="block_reason" class="form-control border-danger" placeholder="દા.ત. પેમેન્ટ વિલંબ / વિવાદ / માલિકી નિર્ણય" value="<?= e($party['block_reason'] ?? '') ?>">
                            <small class="text-danger">⚠️ બ્લોક કરેલ પાર્ટી/દલાલના નામ પર કોઈપણ નવી ખરીદી, વેચાણ કે જાંગડ બની શકશે નહીં.</small>
                        </div>

                        <!-- ========================================================= -->
                        <!-- 1. PARTY ROLES (PURCHASE ROUGH, SELL ROUGH, PURCHASE POLISH, SELL POLISH) -->
                        <!-- ========================================================= -->
                        <?php if ($mode === 'party'): ?>
                        <div class="col-12 mt-1">
                            <div class="d-flex justify-content-between align-items-center mb-1.5 flex-wrap gap-2">
                                <label class="form-label small fw-bold text-dark mb-0">
                                    <?= svg_icon('tags', 'text-primary me-1') ?>વેપાર ક્ષેત્ર (Business Roles) <span class="text-danger">*</span>
                                </label>
                                <div>
                                    <button type="button" class="btn btn-xs btn-outline-primary py-0.5 px-2.5 rounded-pill" onclick="toggleSelectAll()" style="font-size: 0.75rem; font-weight: 600;"><?= svg_icon('check-double', 'me-1', 11) ?>બધા (All)</button>
                                </div>
                            </div>
                            
                            <div class="p-2.5 bg-light rounded-3 border" id="roleBoxContainer">
                                <div class="row g-2">
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('rough_purchase', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="rough_purchase" class="form-check-input role-check" <?= in_array('rough_purchase', $currentRoles) ? 'checked' : '' ?>>
                                            <span>રફ ખરીદી સપ્લાયર</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('rough_sale', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="rough_sale" class="form-check-input role-check" <?= in_array('rough_sale', $currentRoles) ? 'checked' : '' ?>>
                                            <span>રફ વેચાણ ગ્રાહક</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('polish_purchase', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="polish_purchase" class="form-check-input role-check" <?= in_array('polish_purchase', $currentRoles) ? 'checked' : '' ?>>
                                            <span>પોલિશ ખરીદી સપ્લાયર</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('polish_sale', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="polish_sale" class="form-check-input role-check" <?= in_array('polish_sale', $currentRoles) ? 'checked' : '' ?>>
                                            <span>પોલિશ વેચાણ ગ્રાહક</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ========================================================= -->
                        <!-- 2. BROKER ROLES (PURCHASE ROUGH, SELL ROUGH, PURCHASE POLISH, SELL POLISH BROKER) -->
                        <!-- ========================================================= -->
                        <?php elseif ($mode === 'broker'): ?>
                        <div class="col-12 mt-1">
                            <div class="d-flex justify-content-between align-items-center mb-1.5 flex-wrap gap-2">
                                <label class="form-label small fw-bold text-dark mb-0">
                                    <?= svg_icon('tags', 'text-primary me-1') ?>દલાલી કાર્યક્ષેત્ર (Brokerage Roles) <span class="text-danger">*</span>
                                </label>
                                <div>
                                    <button type="button" class="btn btn-xs btn-outline-primary py-0.5 px-2.5 rounded-pill" onclick="toggleSelectAll()" style="font-size: 0.75rem; font-weight: 600;"><?= svg_icon('check-double', 'me-1', 11) ?>બધા (All)</button>
                                </div>
                            </div>
                            
                            <div class="p-2.5 bg-light rounded-3 border" id="roleBoxContainer">
                                <div class="row g-2">
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('rough_purchase_broker', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="rough_purchase_broker" class="form-check-input role-check" <?= in_array('rough_purchase_broker', $currentRoles) ? 'checked' : '' ?>>
                                            <span>રફ ખરીદી દલાલ</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('rough_sale_broker', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="rough_sale_broker" class="form-check-input role-check" <?= in_array('rough_sale_broker', $currentRoles) ? 'checked' : '' ?>>
                                            <span>રફ વેચાણ દલાલ</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('polish_purchase_broker', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="polish_purchase_broker" class="form-check-input role-check" <?= in_array('polish_purchase_broker', $currentRoles) ? 'checked' : '' ?>>
                                            <span>પોલિશ ખરીદી દલાલ</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('polish_sale_broker', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="polish_sale_broker" class="form-check-input role-check" <?= in_array('polish_sale_broker', $currentRoles) ? 'checked' : '' ?>>
                                            <span>પોલિશ વેચાણ દલાલ</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ========================================================= -->
                        <!-- 3. OUTSOURCE & LABS ONLY -->
                        <!-- ========================================================= -->
                        <?php elseif ($mode === 'outsource'): ?>
                        <div class="col-12 mt-1">
                            <div class="d-flex justify-content-between align-items-center mb-1.5 flex-wrap gap-2">
                                <label class="form-label small fw-bold text-dark mb-0">
                                    <?= svg_icon('industry', 'text-secondary me-1') ?>સર્વિસ પ્રકાર (Outsource Services) <span class="text-danger">*</span>
                                </label>
                            </div>
                            
                            <div class="p-2.5 bg-light rounded-3 border" id="roleBoxContainer">
                                <div class="row g-2">
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('outsource_galaxy', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="outsource_galaxy" class="form-check-input role-check" <?= in_array('outsource_galaxy', $currentRoles) ? 'checked' : '' ?>>
                                            <span>Galaxy Scanner Hub</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('outsource_ls', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="outsource_ls" class="form-check-input role-check" <?= in_array('outsource_ls', $currentRoles) ? 'checked' : '' ?>>
                                            <span>Laser Sawing (LS)</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('outsource_factory', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="outsource_factory" class="form-check-input role-check" <?= in_array('outsource_factory', $currentRoles) ? 'checked' : '' ?>>
                                            <span>પોલિશ કારખાનું</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('lab_gia', $currentRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="lab_gia" class="form-check-input role-check" <?= in_array('lab_gia', $currentRoles) ? 'checked' : '' ?>>
                                            <span>GIA / IGI લેબ</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

            <!-- Section 2: Contact & Address -->
            <div class="erp-form-card mb-3">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-emerald"><?= svg_icon('address-book') ?></span>
                        ૨. સંપર્ક અને સરનામું (Contact & Location)
                    </h6>
                </div>
                <div class="erp-card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">સંપર્ક વ્યક્તિ (Contact Person)</label>
                            <div class="input-group">
                                <span class="input-group-text"><?= svg_icon('user', 'text-muted') ?></span>
                                <input type="text" name="contact_person" class="form-control" value="<?= e($party['contact_person'] ?? '') ?>" placeholder="સંપર્ક વ્યક્તિનું નામ">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">ફોન / મોબાઈલ નંબર</label>
                            <div class="input-group">
                                <span class="input-group-text"><?= svg_icon('phone', 'text-muted') ?></span>
                                <input type="text" name="phone" class="form-control font-mono" value="<?= e($party['phone'] ?? '') ?>" placeholder="98250 XXXXX">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">શહેર (City) *</label>
                            <select name="city" class="form-select fw-semibold">
                                <option value="Surat" <?= $party['city'] === 'Surat' ? 'selected' : '' ?>>Surat (સુરત - મહિધરપુરા/કતારગામ)</option>
                                <option value="Mumbai" <?= $party['city'] === 'Mumbai' ? 'selected' : '' ?>>Mumbai (મુંબઈ - BDB BKC / Opera)</option>
                                <option value="Other" <?= !in_array($party['city'], ['Surat', 'Mumbai']) ? 'selected' : '' ?>>Other (અન્ય)</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-bold text-muted">સરનામું (Office / Cabin Address)</label>
                            <input type="text" name="address" class="form-control" value="<?= e($party['address'] ?? '') ?>" placeholder="ઓફિસ / કેબિન / માર્કેટ સરનામું">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-muted">PAN અથવા GST Number</label>
                            <input type="text" name="pan_gst" class="form-control font-mono" value="<?= e($party['pan_gst'] ?? '') ?>" placeholder="GSTIN / PAN">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Notes & Actions -->
            <div class="erp-form-card mb-4">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-gold"><?= svg_icon('note-sticky') ?></span>
                        ૩. શેરો અને શરતો (Notes & Remarks)
                    </h6>
                </div>
                <div class="erp-card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">ખાસ નોંધ / કમિશન નિયમો</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="દલાલી દર, મુદતના નિયમો અથવા સંદર્ભ..."><?= e($party['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                        <a href="<?= BASE_URL ?>/modules/parties/view.php?id=<?= $partyId ?>" class="btn btn-secondary px-4">રદ કરો</a>
                        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                            <?= svg_icon('floppy-disk', 'me-2') ?>ફેરફારો સેવ કરો (Save Changes)
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSelectAll() {
    const checks = document.querySelectorAll('.role-check');
    const allChecked = Array.from(checks).every(c => c.checked);
    checks.forEach(c => {
        c.checked = !allChecked;
        const card = c.closest('.erp-role-compact');
        if (card) {
            if (c.checked) card.classList.add('active');
            else card.classList.remove('active');
        }
    });
    const container = document.getElementById('roleBoxContainer');
    if (container && document.querySelectorAll('.role-check:checked').length > 0) {
        container.style.border = '';
    }
}

document.querySelectorAll('.role-check').forEach(c => {
    c.addEventListener('change', function() {
        const card = this.closest('.erp-role-compact');
        if (card) {
            if (this.checked) card.classList.add('active');
            else card.classList.remove('active');
        }
        const container = document.getElementById('roleBoxContainer');
        if (container && document.querySelectorAll('.role-check:checked').length > 0) {
            container.style.border = '';
        }
    });
});

const partyEditForm = document.querySelector('form');
if (partyEditForm) {
    partyEditForm.addEventListener('submit', function(e) {
        const checked = document.querySelectorAll('.role-check:checked');
        if (checked.length === 0) {
            e.preventDefault();
            const container = document.getElementById('roleBoxContainer');
            if (container) {
                container.style.border = '2px solid #ef4444';
                container.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            alert('કૃપા કરીને ઓછામાં ઓછો ૧ રોલ (ખરીદી/વેચાણ) સિલેક્ટ કરો!');
            return false;
        }
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
