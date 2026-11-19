<?php
/**
 * Logout Handler
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

logout_user();
set_flash('info', 'તમે સફળતાપૂર્વક લૉગ આઉટ થયા છો (Logged out successfully).');
header('Location: ' . BASE_URL . '/login.php');
exit;
