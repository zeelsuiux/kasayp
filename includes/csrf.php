<?php
/**
 * CSRF Protection Token Helper
 * Diamond ERP High Security
 */

declare(strict_types=1);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function get_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    $token = get_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

function validate_csrf(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        // Check header X-CSRF-Token or X-XSRF-Token (standard for AJAX / fetch)
        if (empty($token) && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        if (empty($token) && !empty($_SERVER['HTTP_X_XSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_X_XSRF_TOKEN'];
        }

        // Support JSON request body
        if (empty($token)) {
            $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $rawBody = file_get_contents('php://input');
                if (!empty($rawBody)) {
                    $decoded = json_decode($rawBody, true);
                    if (is_array($decoded) && !empty($decoded['csrf_token'])) {
                        $token = (string)$decoded['csrf_token'];
                    }
                }
            }
        }

        if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
                || (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false);

            if ($isAjax) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'સુરક્ષા ચકાસણી નિષ્ફળ (CSRF validation failed)']);
                exit;
            }

            // Store posted form data in session (excluding sensitive fields) to repopulate on redirect
            $oldInput = $_POST;
            unset($oldInput['password'], $oldInput['current_password'], $oldInput['new_password'], $oldInput['confirm_password'], $oldInput['csrf_token']);
            $_SESSION['_old_input'] = $oldInput;

            $_SESSION['flash_error'] = 'સુરક્ષા ચકાસણી નિષ્ફળ (Security validation failed / Invalid or expired CSRF token). કૃપા કરીને ફરી પ્રયાસ કરો.';
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? (defined('BASE_URL') ? BASE_URL . '/index.php' : '/')));
            exit;
        }
    }
    return true;
}

/**
 * Retrieve flashed old input value for form repopulation
 */
function old(string $key, mixed $default = ''): mixed {
    $val = $_SESSION['_old_input'][$key] ?? $default;
    unset($_SESSION['_old_input'][$key]);
    return $val;
}
