<?php
/**
 * Toggle Party / Broker / Outsource Block Status
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/modules/parties/index.php');
    exit;
}

validate_csrf();

$partyId = (int)($_POST['party_id'] ?? 0);
$action = trim($_POST['action'] ?? 'toggle');
$reason = trim($_POST['reason'] ?? '');
$redirect = $_POST['redirect'] ?? (BASE_URL . '/modules/parties/index.php');

if ($partyId <= 0) {
    set_flash('error', 'અમાન્ય પાર્ટી/દલાલ આઈડી.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id, party_name, party_type, status FROM parties WHERE id = ?");
    $stmt->execute([$partyId]);
    $party = $stmt->fetch();

    if (!$party) {
        set_flash('error', 'પાર્ટી રેકોર્ડ મળ્યો નથી.');
        header('Location: ' . $redirect);
        exit;
    }

    $currentStatus = $party['status'] ?? 'active';
    $name = $party['party_name'];

    if ($action === 'unblock' || ($action === 'toggle' && $currentStatus === 'blocked')) {
        $stmt = $pdo->prepare("UPDATE parties SET status = 'active', block_reason = NULL, blocked_at = NULL WHERE id = ?");
        $stmt->execute([$partyId]);

        log_activity('Party Unblocked', 'Party', $partyId, "Unblocked party $name by {$user['username']}");
        set_flash('success', "પાર્ટી/દલાલ '{$name}' સફળતાપૂર્વક અન-બ્લોક (Active) થઈ ગઈ છે. હવે તેમના નામ પર ખરીદી, વેચાણ અને જાંગડ થઈ શકશે.");
    } else {
        $blockReason = !empty($reason) ? $reason : 'વહીવટી નિર્ણય / સ્થગિત વ્યવહાર';
        $stmt = $pdo->prepare("UPDATE parties SET status = 'blocked', block_reason = ?, blocked_at = NOW() WHERE id = ?");
        $stmt->execute([$blockReason, $partyId]);

        log_activity('Party Blocked', 'Party', $partyId, "Blocked party $name. Reason: $blockReason by {$user['username']}");
        set_flash('warning', "🛑 પાર્ટી/દલાલ '{$name}' બ્લોક (Blocked) કરી દેવામાં આવી છે. હવે સિસ્ટમમાં તેમના નામ પર કોઈ ખરીદી, વેચાણ કે જાંગડ થઈ શકશે નહીં.");
    }
} catch (\Throwable $ex) {
    set_flash('error', 'બ્લોક સ્ટેટસ બદલવામાં ક્ષતિ: ' . $ex->getMessage());
}

header('Location: ' . $redirect);
exit;
