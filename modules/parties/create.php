<?php
/**
 * Create New Party / Broker Entry
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

// Determine Mode: party, broker, or outsource
$typeParam = trim($_GET['type'] ?? 'party');
if (in_array($typeParam, ['broker', 'rough_broker', 'polish_broker', 'sell_broker', 'rough_sale_broker', 'polish_sale_broker', 'rough_purchase_broker', 'polish_purchase_broker'])) {
    $mode = 'broker';
} elseif (in_array($typeParam, ['outsource', 'outsource_galaxy', 'outsource_ls', 'outsource_factory', 'lab_gia', 'galaxy', 'ls', 'factory', 'lab'])) {
    $mode = 'outsource';
} else {
    $mode = 'party';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $name    = trim($_POST['party_name'] ?? '');
    $type    = trim($_POST['party_type'] ?? ($mode === 'broker' ? 'broker' : ($mode === 'outsource' ? 'outsource_factory' : 'party')));
    $roles   = isset($_POST['roles']) && is_array($_POST['roles']) ? array_values(array_filter($_POST['roles'])) : [];
    
    // Auto-align party_type based on active mode & selected roles
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
            $stmt = $pdo->prepare("INSERT INTO parties (party_name, party_type, roles, contact_person, phone, city, address, pan_gst, notes)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $type, $rolesJson, $contact, $phone, $city, $address, $panGst, $notes]);
            $partyId = (int)$pdo->lastInsertId();

            $logLabel = match($mode) {
                'broker' => 'Broker Created',
                'outsource' => 'Outsource Unit Created',
                default => 'Party Created'
            };
            log_activity($logLabel, 'Party', $partyId, "Added $name ($type) in $city");
            
            $successMsg = match($mode) {
                'broker' => "નવા દલાલ '$name' સફળતાપૂર્વક ઉમેરાયા છે.",
                'outsource' => "આઉટસોર્સ યુનિટ '$name' સફળતાપૂર્વક ઉમેરાઈ ગયું છે.",
                default => "પાર્ટી '$name' સફળતાપૂર્વક ઉમેરાઈ ગઈ છે."
            };
            set_flash('success', $successMsg);

            $redirectUrl = match($mode) {
                'broker' => BASE_URL . '/modules/parties/index.php?type=broker',
                'outsource' => BASE_URL . '/modules/parties/index.php?type=outsource',
                default => BASE_URL . '/modules/parties/index.php?type=party'
            };
            header('Location: ' . $redirectUrl);
            exit;
        } catch (\Throwable $ex) {
            set_flash('error', 'ઉમેરવામાં ક્ષતિ: ' . $ex->getMessage());
        }
    }
}

// Initial Roles Pre-selection (Default: all unselected)
$initialRoles = [];
if ($typeParam === 'rough_sale') {
    $initialRoles = ['rough_sale'];
} elseif ($typeParam === 'polish_sale') {
    $initialRoles = ['polish_sale'];
} elseif ($typeParam === 'rough_purchase') {
    $initialRoles = ['rough_purchase'];
} elseif ($typeParam === 'polish_purchase') {
    $initialRoles = ['polish_purchase'];
} elseif ($typeParam === 'rough_broker' || $typeParam === 'rough_sale_broker') {
    $initialRoles = ['rough_sale_broker'];
} elseif ($typeParam === 'polish_broker' || $typeParam === 'polish_sale_broker') {
    $initialRoles = ['polish_sale_broker'];
} elseif ($typeParam === 'galaxy' || $typeParam === 'outsource_galaxy') {
    $initialRoles = ['outsource_galaxy'];
} elseif ($typeParam === 'ls' || $typeParam === 'outsource_ls') {
    $initialRoles = ['outsource_ls'];
} elseif ($typeParam === 'lab_gia' || $typeParam === 'lab') {
    $initialRoles = ['lab_gia'];
}

$pageTitle = match($mode) {
    'broker' => 'નવો દલાલ ઉમેરો (Add Broker)',
    'outsource' => 'નવું Outsource યુનિટ ઉમેરો (Add Outsource & Labs)',
    default => 'નવો ગ્રાહક / પાર્ટી ઉમેરો (Add Customer / Party)'
};

require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    $pageTitle,
    $mode === 'broker' ? 'હીરા બજારના નવા દલાલની એન્ટ્રી (Brokers)' : ($mode === 'outsource' ? 'Galaxy, Laser Sawing, કારખાનું અથવા લેબ' : 'નવા ગ્રાહક / સપ્લાયરની એન્ટ્રી (Customers & Suppliers)'),
    $mode === 'broker' ? 'handshake' : ($mode === 'outsource' ? 'industry' : 'user-plus'),
    '<a href="' . BASE_URL . '/modules/parties/index.php' . ($mode === 'broker' ? '?type=broker' : ($mode === 'outsource' ? '?type=outsource' : '?type=party')) . '" class="btn btn-outline-secondary btn-sm rounded-pill px-3">' . svg_icon('arrow-left', 'me-1', 13) . 'ડિરેક્ટરી</a>'
) ?>

<div class="row justify-content-center">
    <div class="col-12 col-xl-8">
        <form action="<?= BASE_URL ?>/modules/parties/create.php?type=<?= $mode ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="party_type" value="<?= ($mode === 'broker') ? 'broker' : (($mode === 'outsource') ? 'outsource_factory' : 'party') ?>">

            <!-- Section 1: Basic Information -->
            <div class="erp-form-card">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon"><?= svg_icon($mode === 'broker' ? 'handshake' : ($mode === 'outsource' ? 'industry' : 'building')) ?></span>
                        ૧. <?= $mode === 'broker' ? 'દલાલ મૂળભૂત વિગત (Broker Profile)' : ($mode === 'outsource' ? 'આઉટસોર્સ પ્રોફાઇલ (Outsource Profile)' : 'પાર્ટી મૂળભૂત વિગત (Party Basic Profile)') ?>
                    </h6>
                </div>
                <div class="erp-card-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-bold text-muted">
                                <?php if ($mode === 'broker'): ?>
                                    દલાલનું નામ (Broker / Firm Name) *
                                <?php elseif ($mode === 'outsource'): ?>
                                    આઉટસોર્સ યુનિટ / કારખાનાનું નામ (Unit Name) *
                                <?php else: ?>
                                    પાર્ટીનું નામ (Customer / Party Name) *
                                <?php endif; ?>
                            </label>
                            <input type="text" name="party_name" class="form-control form-control-lg fw-bold" placeholder="<?php
                                if ($mode === 'broker') echo 'દા.ત. મુકેશભાઈ દલાલ (સુરત)';
                                elseif ($mode === 'outsource') echo 'દા.ત. શ્રી ગણેશ લેસર / ગેલેક્સી હબ';
                                else echo 'દા.ત. શ્રી હરિકૃષ્ણ ડાયમંડ્સ';
                            ?>" required autofocus>
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
                                        <label class="erp-role-compact <?= in_array('rough_purchase', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="rough_purchase" class="form-check-input role-check" <?= in_array('rough_purchase', $initialRoles) ? 'checked' : '' ?>>
                                            <span>રફ ખરીદી સપ્લાયર</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('rough_sale', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="rough_sale" class="form-check-input role-check" <?= in_array('rough_sale', $initialRoles) ? 'checked' : '' ?>>
                                            <span>રફ વેચાણ ગ્રાહક</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('polish_purchase', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="polish_purchase" class="form-check-input role-check" <?= in_array('polish_purchase', $initialRoles) ? 'checked' : '' ?>>
                                            <span>પોલિશ ખરીદી સપ્લાયર</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('polish_sale', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="polish_sale" class="form-check-input role-check" <?= in_array('polish_sale', $initialRoles) ? 'checked' : '' ?>>
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
                                        <label class="erp-role-compact <?= in_array('rough_purchase_broker', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="rough_purchase_broker" class="form-check-input role-check" <?= in_array('rough_purchase_broker', $initialRoles) ? 'checked' : '' ?>>
                                            <span>રફ ખરીદી દલાલ</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('rough_sale_broker', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="rough_sale_broker" class="form-check-input role-check" <?= in_array('rough_sale_broker', $initialRoles) ? 'checked' : '' ?>>
                                            <span>રફ વેચાણ દલાલ</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('polish_purchase_broker', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="polish_purchase_broker" class="form-check-input role-check" <?= in_array('polish_purchase_broker', $initialRoles) ? 'checked' : '' ?>>
                                            <span>પોલિશ ખરીદી દલાલ</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('polish_sale_broker', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="polish_sale_broker" class="form-check-input role-check" <?= in_array('polish_sale_broker', $initialRoles) ? 'checked' : '' ?>>
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
                                        <label class="erp-role-compact <?= in_array('outsource_galaxy', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="outsource_galaxy" class="form-check-input role-check" <?= in_array('outsource_galaxy', $initialRoles) ? 'checked' : '' ?>>
                                            <span>Galaxy Scanner Hub</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('outsource_ls', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="outsource_ls" class="form-check-input role-check" <?= in_array('outsource_ls', $initialRoles) ? 'checked' : '' ?>>
                                            <span>Laser Sawing (LS)</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('outsource_factory', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="outsource_factory" class="form-check-input role-check" <?= in_array('outsource_factory', $initialRoles) ? 'checked' : '' ?>>
                                            <span>પોલિશ કારખાનું</span>
                                        </label>
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="erp-role-compact <?= in_array('lab_gia', $initialRoles) ? 'active' : '' ?>">
                                            <input type="checkbox" name="roles[]" value="lab_gia" class="form-check-input role-check" <?= in_array('lab_gia', $initialRoles) ? 'checked' : '' ?>>
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
            <div class="erp-form-card">
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
                                <input type="text" name="contact_person" class="form-control" placeholder="સંપર્ક વ્યક્તિનું નામ">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">ફોન / મોબાઈલ નંબર</label>
                            <div class="input-group">
                                <span class="input-group-text"><?= svg_icon('phone', 'text-muted') ?></span>
                                <input type="text" name="phone" class="form-control font-mono" placeholder="98250 XXXXX">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted">શહેર (City) *</label>
                            <select name="city" class="form-select fw-semibold">
                                <option value="Surat" selected>Surat (સુરત - મહિધરપુરા/કતારગામ)</option>
                                <option value="Mumbai">Mumbai (મુંબઈ - BDB BKC / Opera)</option>
                                <option value="Other">Other (અન્ય)</option>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-bold text-muted">સરનામું (Office / Cabin Address)</label>
                            <input type="text" name="address" class="form-control" placeholder="ઓફિસ / કેબિન / માર્કેટ સરનામું">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-muted">PAN અથવા GST Number</label>
                            <input type="text" name="pan_gst" class="form-control font-mono" placeholder="GSTIN / PAN">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 3: Notes & Actions -->
            <div class="erp-form-card">
                <div class="erp-card-header">
                    <h6 class="header-title">
                        <span class="header-icon icon-gold"><?= svg_icon('note-sticky') ?></span>
                        ૩. શેરો અને શરતો (Notes & Remarks)
                    </h6>
                </div>
                <div class="erp-card-body">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">ખાસ નોંધ / કમિશન નિયમો</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="દલાલી દર, મુદતના નિયમો અથવા સંદર્ભ..."></textarea>
                    </div>

                    <div class="d-flex justify-content-end gap-2 pt-2 border-top">
                        <a href="<?= BASE_URL ?>/modules/parties/index.php<?= $mode === 'broker' ? '?type=broker' : ($mode === 'outsource' ? '?type=outsource' : '?type=party') ?>" class="btn btn-secondary px-4">રદ કરો</a>
                        <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                            <?= svg_icon('floppy-disk', 'me-2') ?>
                            <?= $mode === 'broker' ? 'દલાલ સેવ કરો (Save Broker)' : ($mode === 'outsource' ? 'આઉટસોર્સ યુનિટ સેવ કરો' : 'પાર્ટી સેવ કરો (Save Party)') ?>
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

const partyForm = document.querySelector('form');
if (partyForm) {
    partyForm.addEventListener('submit', function(e) {
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
