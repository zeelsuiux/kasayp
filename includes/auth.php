<?php
/**
 * Authentication & Role-Based Access Control (RBAC)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

function is_logged_in(): bool {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return false;
    }

    // 60-minute idle session timeout
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
        logout_user();
        return false;
    }
    $_SESSION['last_activity'] = time();

    // Note: User-Agent MD5 fingerprint logout removed per Phase 1.5 to prevent random logouts

    return true;
}

function require_login(): void {
    if (!is_logged_in()) {
        $_SESSION['flash_warning'] = 'કૃપા કરીને પહેલાં લોગિન કરો (Please log in to continue).';
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }

    // 1.8 Security: Prevent browser caching of authenticated pages
    if (!headers_sent()) {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Pragma: no-cache");
    }

    // 1.1 Force change password on first login flow
    if (!empty($_SESSION['must_change_password']) && (int)$_SESSION['must_change_password'] === 1) {
        $currentScript = basename($_SERVER['PHP_SELF'] ?? '');
        $allowedScripts = ['change_password.php', 'logout.php'];
        if (!in_array($currentScript, $allowedScripts, true)) {
            $_SESSION['flash_warning'] = 'સુરક્ષા ખાતર આગળ વધતા પહેલાં તમારો નવો પાસવર્ડ સેટ કરવો ફરજિયાત છે (Please change your password to continue).';
            header('Location: ' . BASE_URL . '/change_password.php');
            exit;
        }
    }
}

function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'        => (int)($_SESSION['user_id'] ?? 0),
        'username'  => (string)($_SESSION['username'] ?? ''),
        'full_name' => (string)($_SESSION['full_name'] ?? ''),
        'email'     => (string)($_SESSION['email'] ?? ''),
        'role'      => (string)($_SESSION['role'] ?? 'operator')
    ];
}

function require_role(array $allowedRoles): void {
    require_login();
    $user = current_user();
    if (!$user || !in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;max-width:600px;margin:80px auto;padding:30px;background:#fff1f2;border:1px solid #fda4af;border-radius:12px;color:#9f1239;">
            <h2 style="margin-top:0;">🚫 એક્સેસ પ્રતિબંધિત (Access Denied)</h2>
            <p>તમારી પાસે આ પેજ જોવાની પરવાનગી નથી (You do not have sufficient permissions to access this page).</p>
            <p><a href="' . BASE_URL . '/modules/dashboard/index.php" style="color:#0284c7;font-weight:bold;text-decoration:none;">&larr; ડેશબોર્ડ પર પાછા જાઓ (Back to Dashboard)</a></p>
        </div>');
    }
}

function login_user(string $username, string $password): bool {
    $pdo = getDbConnection();
    
    $stmt = $pdo->prepare("SELECT id, username, full_name, email, password_hash, role, status, must_change_password FROM users WHERE username = ? LIMIT 1");
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if ($user && $user['status'] === 'active' && password_verify($password, $user['password_hash'])) {
        if (!headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id']              = (int)$user['id'];
        $_SESSION['username']             = $user['username'];
        $_SESSION['full_name']            = $user['full_name'];
        $_SESSION['email']                = $user['email'];
        $_SESSION['role']                 = $user['role'];
        $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
        $_SESSION['last_activity']        = time();

        $upd = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $upd->execute([$user['id']]);

        log_activity('User Login', 'Auth', (int)$user['id'], 'User ' . $user['username'] . ' successfully logged in');
        return true;
    }

    return false;
}

function logout_user(): void {
    if (isset($_SESSION['user_id'])) {
        log_activity('User Logout', 'Auth', (int)$_SESSION['user_id'], 'User logged out');
    }
    
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    @session_destroy();
}

function log_activity(string $action, string $module, ?int $recordId = null, ?string $details = null): void {
    try {
        $pdo = getDbConnection();
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, record_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $module, $recordId, $details, $ip]);
    } catch (\Throwable $e) {
        error_log('Activity logging error: ' . $e->getMessage());
    }
}
