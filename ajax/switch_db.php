<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';

// Force session and cookie strictly to single database: diamond_erp
$_SESSION['active_db'] = 'diamond_erp';
if (isset($_COOKIE['kasyap_firm_target'])) {
    setcookie('kasyap_firm_target', '', time() - 3600, '/');
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true, 'db' => 'diamond_erp']);
exit;
