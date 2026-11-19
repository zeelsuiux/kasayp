<?php
/**
 * Unified Process Hub Redirect
 * Redirects legacy process Kanban index to the active Jobwork & Process hub
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';

$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: ' . BASE_URL . '/modules/process/jobwork_stock.php' . $queryString, true, 301);
exit;
