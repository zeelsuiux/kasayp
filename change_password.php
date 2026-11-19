<?php
/**
 * Change Password Portal (Force Change & Self-Service)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';

require_login();
$currentUser = current_user();
$pdo = getDbConnection();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Fetch latest user hash
    $stmt = $pdo->prepare("SELECT id, password_hash, username FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$currentUser['id']]);
    $userRow = $stmt->fetch();

    if (!$userRow || !password_verify($currentPassword, $userRow['password_hash'])) {
        $error = 'હાલનો પાસવર્ડ ખોટો છે (Current password is incorrect).';
    } elseif (strlen($newPassword) < 8) {
        $error = 'નવો પાસવર્ડ ઓછામાં ઓછો ૮ અક્ષરનો હોવો જોઈએ (New password must be at least 8 characters).';
    } elseif (!preg_match('/[A-Za-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        $error = 'નવા પાસવર્ડમાં અક્ષરો અને અંકો બંને હોવા જરૂરી છે (Must contain letters and numbers).';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'નવો પાસવર્ડ અને કન્ફર્મ પાસવર્ડ મેળ ખાતા નથી (Passwords do not match).';
    } elseif ($newPassword === $currentPassword) {
        $error = 'નવો પાસવર્ડ જૂના પાસવર્ડ જેવો જ ન હોવો જોઈએ (New password must be different from current password).';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, updated_at = NOW() WHERE id = ?");
        $upd->execute([$newHash, $currentUser['id']]);

        $_SESSION['must_change_password'] = 0;
        log_activity('Password Changed', 'Auth', (int)$currentUser['id'], "User {$userRow['username']} updated their password");

        set_flash('success', 'તમારો પાસવર્ડ સફળતાપૂર્વક બદલાઈ ગયો છે (Password changed successfully).');
        header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>પાસવર્ડ બદલો (Change Password) | <?= APP_NAME ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Noto+Sans+Gujarati:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

    <style>
        :root {
            --font-sans: 'Poppins', 'Noto Sans Gujarati', sans-serif;
        }
        body {
            font-family: var(--font-sans);
            background: #f4f7fb;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
            color: #0f172a;
        }
        .change-card {
            background: #ffffff;
            border: 1px solid #e8eef5;
            border-radius: 20px;
            box-shadow: 0 18px 45px -12px rgba(15, 23, 42, 0.08);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }
        .form-control-finora {
            background: #f8fafc;
            border: 1px solid #dce5f0;
            border-radius: 12px;
            padding: 11px 16px;
            color: #0f172a;
            font-size: 0.92rem;
        }
        .form-control-finora:focus {
            background: #ffffff;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3.5px rgba(59, 130, 246, 0.14);
            outline: none;
        }
        .btn-finora {
            background: #2563eb;
            border: none;
            border-radius: 12px;
            padding: 12px;
            font-weight: 600;
            color: #ffffff;
            transition: all 0.18s ease;
        }
        .btn-finora:hover {
            background: #1d4ed8;
            color: #ffffff;
        }
    </style>
</head>
<body>

<div class="change-card p-4 p-sm-5">
    <div class="text-center mb-4">
        <div class="mb-3 d-inline-flex align-items-center justify-content-center">
            <?= render_brand_logo_svg(42) ?>
        </div>
        <h4 class="fw-bold mb-1 text-dark">નવો પાસવર્ડ સેટ કરો</h4>
        <p class="text-muted small mb-0">સુરક્ષા ખાતર તમારો પાસવર્ડ બદલવો ફરજિયાત છે (Change Password)</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger py-2.5 px-3 rounded-3 small mb-3 border-0 d-flex align-items-center gap-2">
            <?= svg_icon('circle-exclamation', 'text-danger flex-shrink-0', 16) ?>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <form action="change_password.php" method="POST" autocomplete="off">
        <?= csrf_field() ?>

        <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary mb-1">હાલનો પાસવર્ડ (Current Password) <span class="text-danger">*</span></label>
            <input type="password" name="current_password" class="form-control form-control-finora" required autofocus placeholder="હાલનો પાસવર્ડ લખો">
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary mb-1">નવો પાસવર્ડ (New Password) <span class="text-danger">*</span></label>
            <input type="password" name="new_password" class="form-control form-control-finora" required minlength="8" placeholder="ઓછામાં ઓછા ૮ અક્ષર">
            <small class="text-muted" style="font-size: 0.76rem;">ઓછામાં ઓછા ૮ અક્ષરો જેમાં અક્ષર અને અંક બંને હોવા જોઈએ.</small>
        </div>

        <div class="mb-4">
            <label class="form-label small fw-semibold text-secondary mb-1">નવો પાસવર્ડ કન્ફર્મ કરો (Confirm Password) <span class="text-danger">*</span></label>
            <input type="password" name="confirm_password" class="form-control form-control-finora" required minlength="8" placeholder="ફરીથી નવો પાસવર્ડ લખો">
        </div>

        <button type="submit" class="btn btn-finora w-100 d-flex align-items-center justify-content-center gap-2 shadow-sm mb-3">
            <?= svg_icon('key', '', 16) ?>
            <span>પાસવર્ડ અપડેટ કરો (Update Password)</span>
        </button>

        <div class="text-center">
            <a href="<?= BASE_URL ?>/logout.php" class="text-muted small text-decoration-none">
                <?= svg_icon('right-from-bracket', 'me-1', 13) ?>લૉગ આઉટ કરો (Logout)
            </a>
        </div>
    </form>
</div>

</body>
</html>
