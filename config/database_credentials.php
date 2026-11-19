<?php
/**
 * Database Credentials Configuration
 * Diamond ERP Enterprise Security
 * 
 * NOTE: Web access to this file is strictly denied via Apache .htaccess.
 * 
 * =========================================================================
 * PRODUCTION LEAST-PRIVILEGE SETUP INSTRUCTIONS:
 * =========================================================================
 * Stop using root with an empty password in production.
 * Create a dedicated MySQL user with least privileges:
 * 
 * 1. Connect to MySQL as root:
 *    mysql -u root -p
 * 
 * 2. Create the application user and grant permissions:
 *    CREATE USER 'diamond_app'@'localhost' IDENTIFIED BY 'Strong_Custom_Password_2026!';
 *    GRANT SELECT, INSERT, UPDATE, DELETE ON diamond_erp.* TO 'diamond_app'@'localhost';
 *    FLUSH PRIVILEGES;
 * 
 * 3. Update the credentials below or set environment variables:
 *    DB_HOST, DB_NAME, DB_USER, DB_PASS
 * =========================================================================
 */

declare(strict_types=1);

return [
    'host'     => getenv('DB_HOST') ?: 'localhost',
    'dbname'   => getenv('DB_NAME') ?: 'diamond_erp',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
    'charset'  => 'utf8mb4'
];
