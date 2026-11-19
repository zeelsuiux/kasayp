<?php
/**
 * Ultra-Sleek Executive Login Portal
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/helpers.php';

if (is_logged_in()) {
    if (!empty($_SESSION['must_change_password']) && (int)$_SESSION['must_change_password'] === 1) {
        header('Location: ' . BASE_URL . '/change_password.php');
    } else {
        header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
    }
    exit;
}

$pdo = getDbConnection();
$error = '';
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    validate_csrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'કૃપા કરીને યુઝરનેમ અને પાસવર્ડ બંને દાખલ કરો (Please fill both fields).';
    } else {
        // 1.2 DB-backed Brute-force check: Is this IP and username currently locked?
        $lockStmt = $pdo->prepare("
            SELECT id, attempts, locked_until 
            FROM login_attempts 
            WHERE ip_address = ? AND username = ? AND locked_until > NOW() 
            LIMIT 1
        ");
        $lockStmt->execute([$ipAddress, $username]);
        $lockRow = $lockStmt->fetch();

        if ($lockRow) {
            $secondsRemaining = strtotime($lockRow['locked_until']) - time();
            $minutesRemaining = max(1, (int)ceil($secondsRemaining / 60));
            $error = "ઘણા બધા અમાન્ય પ્રયાસો થયા છે. તમારું એકાઉન્ટ લૉક કરવામાં આવ્યું છે. કૃપા કરીને {$minutesRemaining} મિનિટ પછી પ્રયાસ કરો (Account locked. Please try again after {$minutesRemaining} minutes).";
        } else {
            // Attempt login
            if (login_user($username, $password)) {
                // Clear any recorded failed attempts on successful login
                $delStmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND username = ?");
                $delStmt->execute([$ipAddress, $username]);

                // Check force change password flag
                if (!empty($_SESSION['must_change_password']) && (int)$_SESSION['must_change_password'] === 1) {
                    set_flash('warning', 'સુરક્ષા ખાતર આગળ વધતા પહેલાં તમારો નવો પાસવર્ડ સેટ કરવો ફરજિયાત છે (Please change your default password).');
                    header('Location: ' . BASE_URL . '/change_password.php');
                } else {
                    set_flash('success', 'સ્વાગત છે! સફળતાપૂર્વક લોગિન થયા (Welcome back).');
                    header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
                }
                exit;
            } else {
                // Record failed attempt in DB
                $attStmt = $pdo->prepare("
                    SELECT id, attempts, locked_until 
                    FROM login_attempts 
                    WHERE ip_address = ? AND username = ? 
                    LIMIT 1
                ");
                $attStmt->execute([$ipAddress, $username]);
                $attRow = $attStmt->fetch();

                if ($attRow) {
                    // If previous lock expired, reset counter to 1
                    if (!empty($attRow['locked_until']) && strtotime($attRow['locked_until']) <= time()) {
                        $newAttempts = 1;
                        $newLockUntil = null;
                    } else {
                        $newAttempts = (int)$attRow['attempts'] + 1;
                        $newLockUntil = ($newAttempts >= 5) ? date('Y-m-d H:i:s', time() + 900) : null;
                    }

                    $updStmt = $pdo->prepare("UPDATE login_attempts SET attempts = ?, locked_until = ?, last_attempt_at = NOW() WHERE id = ?");
                    $updStmt->execute([$newAttempts, $newLockUntil, $attRow['id']]);
                } else {
                    $newAttempts = 1;
                    $insStmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, username, attempts, locked_until, last_attempt_at) VALUES (?, ?, 1, NULL, NOW())");
                    $insStmt->execute([$ipAddress, $username]);
                }

                if ($newAttempts >= 5) {
                    $error = '૫ અમાન્ય પ્રયાસો થયા છે. સુરક્ષા ખાતર આ એકાઉન્ટ ૧૫ મિનિટ માટે લૉક કરવામાં આવ્યું છે (Account locked for 15 minutes due to 5 failed attempts).';
                } else {
                    $remaining = 5 - $newAttempts;
                    $error = "ખોટો યુઝરનેમ અથવા પાસવર્ડ (Invalid username or password). બાકી પ્રયાસો: {$remaining} (Remaining attempts: {$remaining}).";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="gu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>સુરક્ષિત લૉગિન | <?= APP_NAME ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Noto+Sans+Gujarati:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/bootstrap.min.css">

    <style>
        :root {
            --brand-navy: #0f172a;
            --brand-primary: #1e3a8a;
            --brand-gold: #d97706;
            --font-primary: 'Noto Sans Gujarati', 'Poppins', sans-serif;
        }
        body {
            font-family: var(--font-primary);
            background: #0f172a;
            background-image: radial-gradient(circle at 50% 20%, rgba(30, 58, 138, 0.35) 0%, rgba(15, 23, 42, 1) 75%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
            color: #0f172a;
        }
        .login-wrapper {
            max-width: 440px;
            width: 100%;
        }
        .login-card {
            background: #ffffff;
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 12px;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.35);
            overflow: hidden;
        }
        .form-control-finora {
            background: #f8fafc;
            border: 1px solid #dce5f0;
            border-radius: 10px;
            padding: 12px 16px 12px 42px;
            color: #0f172a;
            font-size: 0.95rem;
            transition: all 0.18s ease;
        }
        .form-control-finora:focus {
            background: #ffffff;
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3.5px rgba(30, 58, 138, 0.14);
            outline: none;
        }
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            z-index: 5;
            display: inline-flex;
        }
        .btn-finora-login {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
            border: 1px solid #1e3a8a;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
            color: #ffffff;
            font-size: 1rem;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.25);
            transition: all 0.18s ease;
        }
        .btn-finora-login:hover {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            border-color: #d97706;
            transform: translateY(-1px);
            color: #ffffff;
        }
    </style>
</head>
<body>

<div class="login-wrapper">
    <div class="login-card p-4 p-sm-5">
        <div class="text-center mb-4">
            <div class="mb-3 d-inline-flex align-items-center justify-content-center">
                <?= render_brand_logo_svg(44) ?>
            </div>
            <h4 class="fw-bold mb-1 text-dark">Kasyap Diamond</h4>
            <p class="text-muted small mb-0">સુરક્ષિત એકાઉન્ટ લૉગિન</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show small py-2 px-3 border-0 rounded-3 d-flex align-items-center" role="alert">
                <?= svg_icon('circle-exclamation', 'me-2 flex-shrink-0', 16) ?>
                <span><?= e($error) ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST" autocomplete="off">
            <?= csrf_field() ?>

            <div class="mb-3 position-relative">
                <label class="form-label small fw-semibold text-secondary mb-1">યુઝરનેમ (Username)</label>
                <div class="position-relative">
                    <span class="input-icon"><?= svg_icon('user', '', 16) ?></span>
                    <input type="text" name="username" id="usernameInput" class="form-control form-control-finora" placeholder="Username" required autofocus value="<?= e($_POST['username'] ?? '') ?>">
                </div>
            </div>

            <div class="mb-4 position-relative">
                <label class="form-label small fw-semibold text-secondary mb-1">પાસવર્ડ (Password)</label>
                <div class="position-relative">
                    <span class="input-icon"><?= svg_icon('lock', '', 16) ?></span>
                    <input type="password" name="password" id="passwordInput" class="form-control form-control-finora pe-5" placeholder="Password" required value="">
                    <button type="button" class="btn btn-sm text-secondary position-absolute end-0 top-50 translate-middle-y me-2 border-0 bg-transparent p-1" id="togglePasswordBtn" onclick="togglePasswordVisibility()">
                        <span id="eyeOpenIcon"><?= svg_icon('eye', '', 16) ?></span>
                        <span id="eyeClosedIcon" style="display:none;"><?= svg_icon('eye-slash', 'text-primary', 16) ?></span>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-finora-login w-100 d-flex align-items-center justify-content-center gap-2">
                <?= svg_icon('right-to-bracket', '', 17) ?>
                <span>લૉગિન કરો (Sign In)</span>
            </button>
        </form>
    </div>
</div>

<script>
function togglePasswordVisibility() {
    const pwd = document.getElementById('passwordInput');
    const openIcon = document.getElementById('eyeOpenIcon');
    const closedIcon = document.getElementById('eyeClosedIcon');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        openIcon.style.display = 'none';
        closedIcon.style.display = 'inline-flex';
    } else {
        pwd.type = 'password';
        openIcon.style.display = 'inline-flex';
        closedIcon.style.display = 'none';
    }
}
</script>
<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>
