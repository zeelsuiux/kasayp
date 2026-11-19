<?php
/**
 * Configuration & Security Bootstrap
 * Diamond Business Management Software (Kasyap Gems)
 */

declare(strict_types=1);

if (!ob_get_level()) {
    ob_start();
}

date_default_timezone_set('Asia/Kolkata');

// 1.3 Security: Disable display of raw errors to clients; log errors to internal log file
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
ini_set('error_log', $logDir . '/php_error.log');

$rawHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostOnly = explode(':', $rawHost)[0];
$isLocalNetwork = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true) ||
                  preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.|169\.254\.)/', $hostOnly);

$isHttps = (
    (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') ||
    (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower((string)$_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
    !$isLocalNetwork
);
$protocol = $isHttps ? "https://" : "http://";
$host = $rawHost;

// 1.5 Configurable BASE_URL
if (!defined('BASE_URL')) {
    $envBase = getenv('BASE_URL');
    if ($envBase) {
        define('BASE_URL', rtrim($envBase, '/'));
    } else {
        define('BASE_URL', $protocol . $host . '/projects/kasyap');
    }
}

// Cookie path derived from BASE_URL
$parsedPath = parse_url(BASE_URL, PHP_URL_PATH) ?: '/';

// 1.5 Session Hardening (60-minute idle timeout, secure, httponly, samesite cookies)
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.gc_maxlifetime', '3600');
        session_set_cookie_params([
            'lifetime' => 3600,
            'path'     => $parsedPath,
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
    } else {
        @session_start();
    }
}

// 1.3 Database Credentials loaded from protected config file
$dbCreds = require __DIR__ . '/database_credentials.php';
define('DB_HOST', $dbCreds['host']);
define('DB_NAME', $dbCreds['dbname']);
define('DB_USER', $dbCreds['username']);
define('DB_PASS', $dbCreds['password']);
define('DB_CHARSET', $dbCreds['charset']);

// Backup Storage Directory (Outside Web Root)
define('BACKUP_STORAGE_DIR', getenv('BACKUP_STORAGE_DIR') ?: 'C:/xampp/kasyap_backups');

define('APP_NAME', 'Kasyap Diamond ERP');
define('APP_NAME_GUJ', 'ડાયમંડ બિઝનેસ મેનેજમેન્ટ સોફ્ટવેર');
define('APP_VERSION', '1.0.0 Enterprise');

// 1.8 Security HTTP Headers
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    // Content-Security-Policy (Report-Only)
    header("Content-Security-Policy-Report-Only: default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data:; frame-ancestors 'self';");
}
