<?php
/**
 * Master Settings & Configuration (માસ્ટર સેટિંગ્સ & ભાગ વિભાજન)
 * Diamond ERP
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role(['admin']);
$user = current_user();

$pdo = getDbConnection();
$activeTab = $_GET['tab'] ?? 'part_names';
if ($activeTab === 'part_types') {
    $activeTab = 'part_names';
}

// Process Actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    // --- 1. Add Part Name ---
    if ($action === 'add_part_name') {
        $name = trim($_POST['part_name'] ?? '');
        $tag = trim($_POST['tag'] ?? 'mfg');
        if (!in_array($tag, ['mfg', 'sale', 'mfg_later'])) {
            $tag = 'mfg';
        }
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name)) {
            set_flash('error', 'કૃપા કરીને ભાગનું નામ દાખલ કરો.');
        } else {
            // Check uniqueness
            $chk = $pdo->prepare("SELECT COUNT(*) FROM assort_part_names WHERE name = ?");
            $chk->execute([$name]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "ભાગનું નામ '$name' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $ins = $pdo->prepare("INSERT INTO assort_part_names (name, tag, sort_order, status) VALUES (?, ?, ?, 'active')");
                $ins->execute([$name, $tag, $sortOrder]);
                log_activity('Part Name Created', 'Settings', (int)$pdo->lastInsertId(), "Added Part Name: $name ($tag)");
                set_flash('success', "ભાગનું નામ '$name' સફળતાપૂર્વક ઉમેરાયું.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=part_names');
        exit;
    }

    // --- 2. Toggle Part Name Status ---
    if ($action === 'toggle_part_name_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT status, name FROM assort_part_names WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $upd = $pdo->prepare("UPDATE assort_part_names SET status = ? WHERE id = ?");
            $upd->execute([$newStatus, $id]);
            log_activity('Part Name Status Changed', 'Settings', $id, "Toggled status of {$row['name']} to $newStatus");
            set_flash('success', "ભાગ '{$row['name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=part_names');
        exit;
    }

    // --- 3. Delete Part Name ---
    if ($action === 'delete_part_name') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT name FROM assort_part_names WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            // Check if used in assort_parts
            $useChk = $pdo->prepare("SELECT COUNT(*) FROM assort_parts WHERE part_name = ?");
            $useChk->execute([$row['name']]);
            if ($useChk->fetchColumn() > 0) {
                set_flash('error', "ભાગ '{$row['name']}' અગાઉના Assort ટાસ્કમાં વપરાયેલ છે, તેથી તેને ડિલીટ કરી શકાતો નથી. તમે તેને Inactive કરી શકો છો.");
            } else {
                $del = $pdo->prepare("DELETE FROM assort_part_names WHERE id = ?");
                $del->execute([$id]);
                log_activity('Part Name Deleted', 'Settings', $id, "Deleted Part Name: {$row['name']}");
                set_flash('success', "ભાગ '{$row['name']}' ડિલીટ કરવામાં આવ્યો.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=part_names');
        exit;
    }

    // --- 3b. Edit Part Name ---
    if ($action === 'edit_part_name') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['part_name'] ?? '');
        $tag = trim($_POST['tag'] ?? 'mfg');
        if (!in_array($tag, ['mfg', 'sale', 'mfg_later'])) {
            $tag = 'mfg';
        }
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય ભાગનું નામ દાખલ કરો.');
        } else {
            $st = $pdo->prepare("SELECT name, tag FROM assort_part_names WHERE id = ?");
            $st->execute([$id]);
            $old = $st->fetch();

            if ($old) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM assort_part_names WHERE name = ? AND id != ?");
                $chk->execute([$name, $id]);
                if ($chk->fetchColumn() > 0) {
                    set_flash('error', "ભાગનું નામ '$name' પહેલેથી અસ્તિત્વમાં છે.");
                } else {
                    $upd = $pdo->prepare("UPDATE assort_part_names SET name = ?, tag = ?, sort_order = ? WHERE id = ?");
                    $upd->execute([$name, $tag, $sortOrder, $id]);

                    $partType = map_assort_tag_to_part_type($tag);
                    $pdo->prepare("UPDATE assort_parts SET part_name = ?, part_type = ? WHERE part_name = ?")->execute([$name, $partType, $old['name']]);

                    log_activity('Part Name Updated', 'Settings', $id, "Updated Part Name: {$old['name']} to $name ($tag)");
                    set_flash('success', "ભાગનું નામ '$name' સફળતાપૂર્વક અપડેટ થયું.");
                }
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=part_names');
        exit;
    }

    // --- 4. Add Part Type ---
    if ($action === 'add_part_type') {
        $code = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['type_code'] ?? '')));
        $name = trim($_POST['type_name'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($code) || empty($name)) {
            set_flash('error', 'કૃપા કરીને પ્રકાર કોડ અને પ્રકારનું નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM assort_part_types WHERE type_code = ?");
            $chk->execute([$code]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "પ્રકાર કોડ '$code' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $ins = $pdo->prepare("INSERT INTO assort_part_types (type_code, type_name, sort_order, status) VALUES (?, ?, ?, 'active')");
                $ins->execute([$code, $name, $sortOrder]);
                log_activity('Part Type Created', 'Settings', (int)$pdo->lastInsertId(), "Added Part Type: $name ($code)");
                set_flash('success', "ભાગનો પ્રકાર '$name' સફળતાપૂર્વક ઉમેરાયો.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=part_types');
        exit;
    }

    // --- 5. Toggle Part Type Status ---
    if ($action === 'toggle_part_type_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT status, type_name FROM assort_part_types WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $upd = $pdo->prepare("UPDATE assort_part_types SET status = ? WHERE id = ?");
            $upd->execute([$newStatus, $id]);
            log_activity('Part Type Status Changed', 'Settings', $id, "Toggled status of {$row['type_name']} to $newStatus");
            set_flash('success', "પ્રકાર '{$row['type_name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=part_types');
        exit;
    }

    // --- 6. Delete Part Type ---
    if ($action === 'delete_part_type') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT type_code, type_name FROM assort_part_types WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $useChk = $pdo->prepare("SELECT COUNT(*) FROM assort_parts WHERE part_type = ?");
            $useChk->execute([$row['type_code']]);
            if ($useChk->fetchColumn() > 0) {
                set_flash('error', "પ્રકાર '{$row['type_name']}' અગાઉના ભાગોમાં વપરાયેલ છે, તેથી તેને ડિલીટ કરી શકાતો નથી. તમે તેને Inactive કરી શકો છો.");
            } else {
                $del = $pdo->prepare("DELETE FROM assort_part_types WHERE id = ?");
                $del->execute([$id]);
                log_activity('Part Type Deleted', 'Settings', $id, "Deleted Part Type: {$row['type_name']}");
                set_flash('success', "પ્રકાર '{$row['type_name']}' ડિલીટ કરવામાં આવ્યો.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=part_types');
        exit;
    }

    // --- 6b. Edit Part Type (Routing) ---
    if ($action === 'edit_part_type') {
        $id = (int)($_POST['id'] ?? 0);
        $code = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['type_code'] ?? '')));
        $name = trim($_POST['type_name'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($code) || empty($name) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય કોડ અને નામ દાખલ કરો.');
        } else {
            $st = $pdo->prepare("SELECT type_code, type_name FROM assort_part_types WHERE id = ?");
            $st->execute([$id]);
            $old = $st->fetch();

            if ($old) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM assort_part_types WHERE type_code = ? AND id != ?");
                $chk->execute([$code, $id]);
                if ($chk->fetchColumn() > 0) {
                    set_flash('error', "પ્રકાર કોડ '$code' પહેલેથી અસ્તિત્વમાં છે.");
                } else {
                    $upd = $pdo->prepare("UPDATE assort_part_types SET type_code = ?, type_name = ?, sort_order = ? WHERE id = ?");
                    $upd->execute([$code, $name, $sortOrder, $id]);
                    if ($old['type_code'] !== $code) {
                        $pdo->prepare("UPDATE assort_parts SET part_type = ? WHERE part_type = ?")->execute([$code, $old['type_code']]);
                    }
                    log_activity('Part Type Updated', 'Settings', $id, "Updated Part Type: {$old['type_name']} to $name");
                    set_flash('success', "ભાગનો પ્રકાર (Routing) '$name' સફળતાપૂર્વક અપડેટ થયો.");
                }
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=part_types');
        exit;
    }

    // --- 7. Save System Settings ---
    if ($action === 'save_general_settings') {
        $settings = [
            'company_name' => trim($_POST['company_name'] ?? ''),
            'company_address' => trim($_POST['company_address'] ?? ''),
            'company_phone' => trim($_POST['company_phone'] ?? ''),
            'company_email' => trim($_POST['company_email'] ?? ''),
            'company_gst' => trim($_POST['company_gst'] ?? ''),
            'default_dollar_rate' => trim($_POST['default_dollar_rate'] ?? '83.50'),
            'default_due_days' => trim($_POST['default_due_days'] ?? '50')
        ];

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
        foreach ($settings as $k => $v) {
            $stmt->execute([$k, $v]);
        }
        log_activity('System Settings Updated', 'Settings', 0, "Updated company profile & business parameters");
        set_flash('success', 'સિસ્ટમ સેટિંગ્સ સફળતાપૂર્વક અપડેટ થયા.');
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=general');
        exit;
    }

    // --- 8. Add Processing Stage ---
    if ($action === 'add_processing_stage') {
        $stageCode = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['stage_code'] ?? '')));
        $stageName = trim($_POST['stage_name'] ?? '');
        $stageIcon = trim($_POST['stage_icon'] ?? 'gem');
        $colorCode = trim($_POST['color_code'] ?? '#3B82F6');
        $defaultLoc = trim($_POST['default_location'] ?? 'In House');
        $defaultCat = trim($_POST['default_category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($stageCode) || empty($stageName)) {
            set_flash('error', 'કૃપા કરીને સ્ટેજ કોડ અને સ્ટેજનું નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM processing_stages WHERE stage_code = ?");
            $chk->execute([$stageCode]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "સ્ટેજ કોડ '$stageCode' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO processing_stages (
                        stage_code, stage_name, stage_icon, color_code,
                        default_location, default_category, description, sort_order, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')
                ");
                $ins->execute([$stageCode, $stageName, $stageIcon, $colorCode, $defaultLoc, $defaultCat, $description, $sortOrder]);
                log_activity('Processing Stage Created', 'Settings', (int)$pdo->lastInsertId(), "Added Stage: $stageName ($stageCode)");
                set_flash('success', "પ્રોસેસિંગ સ્ટેજ '$stageName' સફળતાપૂર્વક ઉમેરાયું.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=processing_stages');
        exit;
    }

    // --- 9. Toggle Processing Stage Status ---
    if ($action === 'toggle_processing_stage_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT status, stage_name FROM processing_stages WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $upd = $pdo->prepare("UPDATE processing_stages SET status = ? WHERE id = ?");
            $upd->execute([$newStatus, $id]);
            log_activity('Stage Status Toggled', 'Settings', $id, "Toggled status of {$row['stage_name']} to $newStatus");
            set_flash('success', "સ્ટેજ '{$row['stage_name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=processing_stages');
        exit;
    }

    // --- 10. Delete Processing Stage ---
    if ($action === 'delete_processing_stage') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT stage_code, stage_name FROM processing_stages WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            // Check if used in diamond history
            $useChk = $pdo->prepare("SELECT COUNT(*) FROM diamond_stage_history WHERE stage_code = ?");
            $useChk->execute([$row['stage_code']]);
            if ($useChk->fetchColumn() > 0) {
                set_flash('error', "સ્ટેજ '{$row['stage_name']}' ડાયમંડ હિસ્ટ્રીમાં વપરાયેલ છે, તેથી તેને ડિલીટ કરી શકાતો નથી. તમે તેને Inactive કરી શકો છો.");
            } else {
                $del = $pdo->prepare("DELETE FROM processing_stages WHERE id = ?");
                $del->execute([$id]);
                log_activity('Stage Deleted', 'Settings', $id, "Deleted Stage: {$row['stage_name']}");
                set_flash('success', "સ્ટેજ '{$row['stage_name']}' ડિલીટ કરવામાં આવ્યો.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=processing_stages');
        exit;
    }

    // --- 10b. Edit Processing Stage ---
    if ($action === 'edit_processing_stage') {
        $id = (int)($_POST['id'] ?? 0);
        $stageCode = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['stage_code'] ?? '')));
        $stageName = trim($_POST['stage_name'] ?? '');
        $stageIcon = trim($_POST['stage_icon'] ?? 'gem');
        $colorCode = trim($_POST['color_code'] ?? '#3B82F6');
        $defaultLoc = trim($_POST['default_location'] ?? 'In House');
        $defaultCat = trim($_POST['default_category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($stageCode) || empty($stageName) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય સ્ટેજ કોડ અને નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM processing_stages WHERE stage_code = ? AND id != ?");
            $chk->execute([$stageCode, $id]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "સ્ટેજ કોડ '$stageCode' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $upd = $pdo->prepare("
                    UPDATE processing_stages 
                    SET stage_code = ?, stage_name = ?, stage_icon = ?, color_code = ?,
                        default_location = ?, default_category = ?, description = ?, sort_order = ?
                    WHERE id = ?
                ");
                $upd->execute([$stageCode, $stageName, $stageIcon, $colorCode, $defaultLoc, $defaultCat, $description, $sortOrder, $id]);
                log_activity('Processing Stage Updated', 'Settings', $id, "Updated Stage: $stageName ($stageCode)");
                set_flash('success', "પ્રોસેસિંગ સ્ટેજ '$stageName' સફળતાપૂર્વક અપડેટ થયું.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=processing_stages');
        exit;
    }

    // --- 11. Destination Actions ---
    if ($action === 'add_destination') {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['code'] ?? '')));
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name)) {
            set_flash('error', 'કૃપા કરીને ડેસ્ટિનેશનનું નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_destinations WHERE name = ?");
            $chk->execute([$name]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "ડેસ્ટિનેશન '$name' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $ins = $pdo->prepare("INSERT INTO master_destinations (name, code, description, sort_order) VALUES (?, ?, ?, ?)");
                $ins->execute([$name, $code ?: strtoupper(substr($name, 0, 8)), $desc, $sortOrder]);
                log_activity('Destination Created', 'Settings', (int)$pdo->lastInsertId(), "Added Destination: $name");
                set_flash('success', "ડેસ્ટિનેશન '$name' સફળતાપૂર્વક ઉમેરાયું.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=destinations');
        exit;
    }

    if ($action === 'toggle_destination_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT status, name FROM master_destinations WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $pdo->prepare("UPDATE master_destinations SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            log_activity('Destination Status Changed', 'Settings', $id, "Toggled status of {$row['name']} to $newStatus");
            set_flash('success', "ડેસ્ટિનેશન '{$row['name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=destinations');
        exit;
    }

    if ($action === 'delete_destination') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT name FROM master_destinations WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $pdo->prepare("DELETE FROM master_destinations WHERE id = ?")->execute([$id]);
            log_activity('Destination Deleted', 'Settings', $id, "Deleted Destination: {$row['name']}");
            set_flash('success', "ડેસ્ટિનેશન '{$row['name']}' ડિલીટ કરવામાં આવ્યું.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=destinations');
        exit;
    }

    // --- 11b. Edit Destination ---
    if ($action === 'edit_destination') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['code'] ?? '')));
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય ડેસ્ટિનેશનનું નામ દાખલ કરો.');
        } else {
            $st = $pdo->prepare("SELECT name FROM master_destinations WHERE id = ?");
            $st->execute([$id]);
            $old = $st->fetch();

            if ($old) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM master_destinations WHERE name = ? AND id != ?");
                $chk->execute([$name, $id]);
                if ($chk->fetchColumn() > 0) {
                    set_flash('error', "ડેસ્ટિનેશન '$name' પહેલેથી અસ્તિત્વમાં છે.");
                } else {
                    $pdo->prepare("UPDATE master_destinations SET name = ?, code = ?, description = ?, sort_order = ? WHERE id = ?")->execute([$name, $code ?: strtoupper(substr($name, 0, 8)), $desc, $sortOrder, $id]);
                    if ($old['name'] !== $name) {
                        $pdo->prepare("UPDATE rough_purchases SET destination = ? WHERE destination = ?")->execute([$name, $old['name']]);
                    }
                    log_activity('Destination Updated', 'Settings', $id, "Updated Destination: {$old['name']} to $name");
                    set_flash('success', "ડેસ્ટિનેશન '$name' સફળતાપૂર્વક અપડેટ થયું.");
                }
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=destinations');
        exit;
    }

    // --- 12. Safe Actions ---
    if ($action === 'add_safe') {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['code'] ?? '')));
        $destId = !empty($_POST['destination_id']) ? (int)$_POST['destination_id'] : null;
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name)) {
            set_flash('error', 'કૃપા કરીને સેફનું નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_safes WHERE name = ?");
            $chk->execute([$name]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "સેફ '$name' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $ins = $pdo->prepare("INSERT INTO master_safes (name, code, destination_id, description, sort_order) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$name, $code ?: strtoupper(substr($name, 0, 8)), $destId, $desc, $sortOrder]);
                log_activity('Safe Created', 'Settings', (int)$pdo->lastInsertId(), "Added Safe: $name");
                set_flash('success', "સેફ '$name' સફળતાપૂર્વક ઉમેરાઈ ગયું.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=safes');
        exit;
    }

    if ($action === 'toggle_safe_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT status, name FROM master_safes WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $pdo->prepare("UPDATE master_safes SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            log_activity('Safe Status Changed', 'Settings', $id, "Toggled status of {$row['name']} to $newStatus");
            set_flash('success', "સેફ '{$row['name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=safes');
        exit;
    }

    if ($action === 'delete_safe') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT name FROM master_safes WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $pdo->prepare("DELETE FROM master_safes WHERE id = ?")->execute([$id]);
            log_activity('Safe Deleted', 'Settings', $id, "Deleted Safe: {$row['name']}");
            set_flash('success', "સેફ '{$row['name']}' ડિલીટ કરવામાં આવ્યો.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=safes');
        exit;
    }

    // --- 12b. Edit Safe ---
    if ($action === 'edit_safe') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['code'] ?? '')));
        $destId = !empty($_POST['destination_id']) ? (int)$_POST['destination_id'] : null;
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય સેફનું નામ દાખલ કરો.');
        } else {
            $st = $pdo->prepare("SELECT name FROM master_safes WHERE id = ?");
            $st->execute([$id]);
            $old = $st->fetch();

            if ($old) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM master_safes WHERE name = ? AND id != ?");
                $chk->execute([$name, $id]);
                if ($chk->fetchColumn() > 0) {
                    set_flash('error', "સેફ '$name' પહેલેથી અસ્તિત્વમાં છે.");
                } else {
                    $pdo->prepare("UPDATE master_safes SET name = ?, code = ?, destination_id = ?, description = ?, sort_order = ? WHERE id = ?")->execute([$name, $code ?: strtoupper(substr($name, 0, 8)), $destId, $desc, $sortOrder, $id]);
                    if ($old['name'] !== $name) {
                        $pdo->prepare("UPDATE rough_purchases SET safe = ? WHERE safe = ?")->execute([$name, $old['name']]);
                    }
                    log_activity('Safe Updated', 'Settings', $id, "Updated Safe: {$old['name']} to $name");
                    set_flash('success', "સેફ '$name' સફળતાપૂર્વક અપડેટ થઈ ગયું.");
                }
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=safes');
        exit;
    }

    // --- 13. Dabba Actions ---
    if ($action === 'add_dabba') {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['code'] ?? '')));
        $safeId = !empty($_POST['safe_id']) ? (int)$_POST['safe_id'] : null;
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name)) {
            set_flash('error', 'કૃપા કરીને ડબ્બા નંબર / નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_dabbas WHERE name = ?");
            $chk->execute([$name]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "ડબ્બો '$name' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $ins = $pdo->prepare("INSERT INTO master_dabbas (name, code, safe_id, description, sort_order) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$name, $code ?: strtoupper(substr($name, 0, 8)), $safeId, $desc, $sortOrder]);
                log_activity('Dabba Created', 'Settings', (int)$pdo->lastInsertId(), "Added Dabba: $name");
                set_flash('success', "ડબ્બો '$name' સફળતાપૂર્વક ઉમેરાયો.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=dabbas');
        exit;
    }

    if ($action === 'toggle_dabba_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT status, name FROM master_dabbas WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $pdo->prepare("UPDATE master_dabbas SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            log_activity('Dabba Status Changed', 'Settings', $id, "Toggled status of {$row['name']} to $newStatus");
            set_flash('success', "ડબ્બો '{$row['name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=dabbas');
        exit;
    }

    if ($action === 'delete_dabba') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT name FROM master_dabbas WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $pdo->prepare("DELETE FROM master_dabbas WHERE id = ?")->execute([$id]);
            log_activity('Dabba Deleted', 'Settings', $id, "Deleted Dabba: {$row['name']}");
            set_flash('success', "ડબ્બો '{$row['name']}' ડિલીટ કરવામાં આવ્યો.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=dabbas');
        exit;
    }

    // --- 13b. Edit Dabba ---
    if ($action === 'edit_dabba') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['code'] ?? '')));
        $safeId = !empty($_POST['safe_id']) ? (int)$_POST['safe_id'] : null;
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય ડબ્બા નંબર / નામ દાખલ કરો.');
        } else {
            $st = $pdo->prepare("SELECT name FROM master_dabbas WHERE id = ?");
            $st->execute([$id]);
            $old = $st->fetch();

            if ($old) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM master_dabbas WHERE name = ? AND id != ?");
                $chk->execute([$name, $id]);
                if ($chk->fetchColumn() > 0) {
                    set_flash('error', "ડબ્બો '$name' પહેલેથી અસ્તિત્વમાં છે.");
                } else {
                    $pdo->prepare("UPDATE master_dabbas SET name = ?, code = ?, safe_id = ?, description = ?, sort_order = ? WHERE id = ?")->execute([$name, $code ?: strtoupper(substr($name, 0, 8)), $safeId, $desc, $sortOrder, $id]);
                    if ($old['name'] !== $name) {
                        $pdo->prepare("UPDATE rough_purchases SET dabba = ? WHERE dabba = ?")->execute([$name, $old['name']]);
                    }
                    log_activity('Dabba Updated', 'Settings', $id, "Updated Dabba: {$old['name']} to $name");
                    set_flash('success', "ડબ્બો '$name' સફળતાપૂર્વક અપડેટ થયો.");
                }
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=dabbas');
        exit;
    }

    // --- Master Parcel Clarities ---
    if ($action === 'add_parcel_clarity') {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['code'] ?? '')));
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name)) {
            set_flash('error', 'કૃપા કરીને ક્લેરિટીનું નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_parcel_clarities WHERE name = ?");
            $chk->execute([$name]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "ક્લેરિટી '$name' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $ins = $pdo->prepare("INSERT INTO master_parcel_clarities (name, code, description, sort_order) VALUES (?, ?, ?, ?)");
                $ins->execute([$name, $code ?: strtoupper(substr($name, 0, 8)), $desc, $sortOrder]);
                log_activity('Parcel Clarity Created', 'Settings', (int)$pdo->lastInsertId(), "Added Parcel Clarity: $name");
                set_flash('success', "પોલિશ પાર્સલ ક્લેરિટી '$name' સફળતાપૂર્વક ઉમેરાઈ.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=parcel_clarities');
        exit;
    }

    if ($action === 'toggle_parcel_clarity_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT status, name FROM master_parcel_clarities WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $pdo->prepare("UPDATE master_parcel_clarities SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            log_activity('Parcel Clarity Status Changed', 'Settings', $id, "Toggled status of {$row['name']} to $newStatus");
            set_flash('success', "ક્લેરિટી '{$row['name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=parcel_clarities');
        exit;
    }

    if ($action === 'delete_parcel_clarity') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT name FROM master_parcel_clarities WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $pdo->prepare("DELETE FROM master_parcel_clarities WHERE id = ?")->execute([$id]);
            log_activity('Parcel Clarity Deleted', 'Settings', $id, "Deleted Parcel Clarity: {$row['name']}");
            set_flash('success', "ક્લેરિટી '{$row['name']}' ડિલીટ કરવામાં આવી.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=parcel_clarities');
        exit;
    }

    if ($action === 'edit_parcel_clarity') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['code'] ?? '')));
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય ક્લેરિટી નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_parcel_clarities WHERE name = ? AND id != ?");
            $chk->execute([$name, $id]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "ક્લેરિટી '$name' પહેલેથી બીજા રેકોર્ડમાં છે.");
            } else {
                $pdo->prepare("UPDATE master_parcel_clarities SET name = ?, code = ?, description = ?, sort_order = ? WHERE id = ?")->execute([$name, $code ?: strtoupper(substr($name, 0, 8)), $desc, $sortOrder, $id]);
                log_activity('Parcel Clarity Updated', 'Settings', $id, "Updated Parcel Clarity: $name");
                set_flash('success', "ક્લેરિટી '$name' સફળતાપૂર્વક અપડેટ થઈ.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=parcel_clarities');
        exit;
    }

    // --- 10. Tags Management (Rough, Tops, Rejection, etc.) ---
    if ($action === 'add_tag') {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['code'] ?? '')));
        $color = trim($_POST['color'] ?? 'blue');
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name)) {
            set_flash('error', 'કૃપા કરીને ટેગનું નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_tags WHERE name = ?");
            $chk->execute([$name]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "ટેગ '$name' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $ins = $pdo->prepare("INSERT INTO master_tags (name, code, color, description, sort_order, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $ins->execute([$name, $code ?: strtoupper($name), $color, $desc, $sortOrder]);
                log_activity('Tag Created', 'Settings', (int)$pdo->lastInsertId(), "Added Tag: $name");
                set_flash('success', "ટેગ '$name' સફળતાપૂર્વક ઉમેરાયો.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=tags');
        exit;
    }

    if ($action === 'toggle_tag_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT status, name FROM master_tags WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $pdo->prepare("UPDATE master_tags SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            log_activity('Tag Status Changed', 'Settings', $id, "Toggled status of tag {$row['name']} to $newStatus");
            set_flash('success', "ટેગ '{$row['name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=tags');
        exit;
    }

    if ($action === 'delete_tag') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT name FROM master_tags WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $useChk = $pdo->prepare("SELECT COUNT(*) FROM rough_purchases WHERE tag = ?");
            $useChk->execute([$row['name']]);
            if ($useChk->fetchColumn() > 0) {
                set_flash('error', "ટેગ '{$row['name']}' રફ ખરીદીમાં વપરાયેલ છે, તેથી તેને ડિલીટ કરી શકાતો નથી. તમે તેને Inactive કરી શકો છો.");
            } else {
                $pdo->prepare("DELETE FROM master_tags WHERE id = ?")->execute([$id]);
                log_activity('Tag Deleted', 'Settings', $id, "Deleted Tag: {$row['name']}");
                set_flash('success', "ટેગ '{$row['name']}' ડિલીટ કરવામાં આવ્યો.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=tags');
        exit;
    }

    // --- 10b. Edit Tag ---
    if ($action === 'edit_tag') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['code'] ?? '')));
        $color = trim($_POST['color'] ?? 'blue');
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય ટેગનું નામ દાખલ કરો.');
        } else {
            $st = $pdo->prepare("SELECT name FROM master_tags WHERE id = ?");
            $st->execute([$id]);
            $old = $st->fetch();

            if ($old) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM master_tags WHERE name = ? AND id != ?");
                $chk->execute([$name, $id]);
                if ($chk->fetchColumn() > 0) {
                    set_flash('error', "ટેગ '$name' પહેલેથી અસ્તિત્વમાં છે.");
                } else {
                    $pdo->prepare("UPDATE master_tags SET name = ?, code = ?, color = ?, description = ?, sort_order = ? WHERE id = ?")->execute([$name, $code ?: strtoupper($name), $color, $desc, $sortOrder, $id]);
                    if ($old['name'] !== $name) {
                        $pdo->prepare("UPDATE rough_purchases SET tag = ? WHERE tag = ?")->execute([$name, $old['name']]);
                    }
                    log_activity('Tag Updated', 'Settings', $id, "Updated Tag: {$old['name']} to $name");
                    set_flash('success', "ટેગ '$name' સફળતાપૂર્વક અપડેટ થયો.");
                }
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=tags');
        exit;
    }

    // --- 11. Process Tags Management (Sell, Hold, Assort, etc.) ---
    if ($action === 'add_process_tag') {
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['code'] ?? '')));
        $color = trim($_POST['color'] ?? 'emerald');
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name)) {
            set_flash('error', 'કૃપા કરીને પ્રોસેસ ટેગનું નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_process_tags WHERE name = ?");
            $chk->execute([$name]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "પ્રોસેસ ટેગ '$name' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $ins = $pdo->prepare("INSERT INTO master_process_tags (name, code, color, description, sort_order, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $ins->execute([$name, $code ?: strtoupper($name), $color, $desc, $sortOrder]);
                log_activity('Process Tag Created', 'Settings', (int)$pdo->lastInsertId(), "Added Process Tag: $name");
                set_flash('success', "પ્રોસેસ ટેગ '$name' સફળતાપૂર્વક ઉમેરાયો.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=process_tags');
        exit;
    }

    if ($action === 'toggle_process_tag_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT status, name FROM master_process_tags WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $pdo->prepare("UPDATE master_process_tags SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            log_activity('Process Tag Status Changed', 'Settings', $id, "Toggled status of process tag {$row['name']} to $newStatus");
            set_flash('success', "પ્રોસેસ ટેગ '{$row['name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=process_tags');
        exit;
    }

    if ($action === 'delete_process_tag') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT name FROM master_process_tags WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $useChk = $pdo->prepare("SELECT COUNT(*) FROM rough_purchases WHERE process_tag = ?");
            $useChk->execute([$row['name']]);
            if ($useChk->fetchColumn() > 0) {
                set_flash('error', "પ્રોસેસ ટેગ '{$row['name']}' રફ ખરીદીમાં વપરાયેલ છે, તેથી તેને ડિલીટ કરી શકાતો નથી. તમે તેને Inactive કરી શકો છો.");
            } else {
                $pdo->prepare("DELETE FROM master_process_tags WHERE id = ?")->execute([$id]);
                log_activity('Process Tag Deleted', 'Settings', $id, "Deleted Process Tag: {$row['name']}");
                set_flash('success', "પ્રોસેસ ટેગ '{$row['name']}' ડિલીટ કરવામાં આવ્યો.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=process_tags');
        exit;
    }

    // --- 11b. Edit Process Tag ---
    if ($action === 'edit_process_tag') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_]/', '_', $_POST['code'] ?? '')));
        $color = trim($_POST['color'] ?? 'emerald');
        $desc = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($name) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય પ્રોસેસ ટેગનું નામ દાખલ કરો.');
        } else {
            $st = $pdo->prepare("SELECT name FROM master_process_tags WHERE id = ?");
            $st->execute([$id]);
            $old = $st->fetch();

            if ($old) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM master_process_tags WHERE name = ? AND id != ?");
                $chk->execute([$name, $id]);
                if ($chk->fetchColumn() > 0) {
                    set_flash('error', "પ્રોસેસ ટેગ '$name' પહેલેથી અસ્તિત્વમાં છે.");
                } else {
                    $pdo->prepare("UPDATE master_process_tags SET name = ?, code = ?, color = ?, description = ?, sort_order = ? WHERE id = ?")->execute([$name, $code ?: strtoupper($name), $color, $desc, $sortOrder, $id]);
                    if ($old['name'] !== $name) {
                        $pdo->prepare("UPDATE rough_purchases SET process_tag = ? WHERE process_tag = ?")->execute([$name, $old['name']]);
                    }
                    log_activity('Process Tag Updated', 'Settings', $id, "Updated Process Tag: {$old['name']} to $name");
                    set_flash('success', "પ્રોસેસ ટેગ '$name' સફળતાપૂર્વક અપડેટ થયો.");
                }
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=process_tags');
        exit;
    }

    // --- GIA Master: Add Attribute ---
    if ($action === 'add_gia_attribute') {
        $attrType = trim($_POST['attribute_type'] ?? 'shape');
        $validTypes = ['shape', 'color', 'clarity', 'polish', 'symmetry', 'fluorescence', 'key_symbols'];
        if (!in_array($attrType, $validTypes)) {
            $attrType = 'shape';
        }
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($code) || empty($name)) {
            set_flash('error', 'કૃપા કરીને કોડ અને નામ બંને દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_gia_attributes WHERE attribute_type = ? AND code = ?");
            $chk->execute([$attrType, $code]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "આ કોડ '$code' પહેલેથી આ કેટેગરીમાં અસ્તિત્વ ધરાવે છે.");
            } else {
                $ins = $pdo->prepare("INSERT INTO master_gia_attributes (attribute_type, code, name, sort_order, status) VALUES (?, ?, ?, ?, 'active')");
                $ins->execute([$attrType, $code, $name, $sortOrder]);
                log_activity('GIA Master Created', 'Settings', (int)$pdo->lastInsertId(), "Added GIA Attribute: $attrType - $code ($name)");
                set_flash('success', "GIA વિગત '$name' સફળતાપૂર્વક ઉમેરાઈ.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=polish_gia&sub=' . urlencode($attrType));
        exit;
    }

    // --- GIA Master: Edit Attribute ---
    if ($action === 'edit_gia_attribute') {
        $id = (int)($_POST['id'] ?? 0);
        $attrType = trim($_POST['attribute_type'] ?? 'shape');
        $validTypes = ['shape', 'color', 'clarity', 'polish', 'symmetry', 'fluorescence', 'key_symbols'];
        if (!in_array($attrType, $validTypes)) {
            $attrType = 'shape';
        }
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($code) || empty($name) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય વિગત દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_gia_attributes WHERE attribute_type = ? AND code = ? AND id != ?");
            $chk->execute([$attrType, $code, $id]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "આ કોડ '$code' પહેલેથી અસ્તિત્વ ધરાવે છે.");
            } else {
                $upd = $pdo->prepare("UPDATE master_gia_attributes SET code = ?, name = ?, sort_order = ? WHERE id = ?");
                $upd->execute([$code, $name, $sortOrder, $id]);
                log_activity('GIA Master Updated', 'Settings', $id, "Updated GIA Attribute: $attrType - $code ($name)");
                set_flash('success', "GIA વિગત '$name' સફળતાપૂર્વક અપડેટ થઈ.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=polish_gia&sub=' . urlencode($attrType));
        exit;
    }

    // --- GIA Master: Toggle Status ---
    if ($action === 'toggle_gia_attribute_status') {
        $id = (int)($_POST['id'] ?? 0);
        $attrType = trim($_POST['attribute_type'] ?? 'shape');
        $st = $pdo->prepare("SELECT status, name, attribute_type FROM master_gia_attributes WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $upd = $pdo->prepare("UPDATE master_gia_attributes SET status = ? WHERE id = ?");
            $upd->execute([$newStatus, $id]);
            log_activity('GIA Master Status Changed', 'Settings', $id, "Toggled status of {$row['name']} to $newStatus");
            set_flash('success', "'{$row['name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
            $attrType = $row['attribute_type'];
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=polish_gia&sub=' . urlencode($attrType));
        exit;
    }

    // --- GIA Master: Delete Attribute ---
    if ($action === 'delete_gia_attribute') {
        $id = (int)($_POST['id'] ?? 0);
        $attrType = trim($_POST['attribute_type'] ?? 'shape');
        $st = $pdo->prepare("SELECT name, attribute_type FROM master_gia_attributes WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $del = $pdo->prepare("DELETE FROM master_gia_attributes WHERE id = ?");
            $del->execute([$id]);
            log_activity('GIA Master Deleted', 'Settings', $id, "Deleted GIA Attribute: {$row['name']}");
            set_flash('success', "'{$row['name']}' સફળતાપૂર્વક ડિલીટ કરવામાં આવ્યું.");
            $attrType = $row['attribute_type'];
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=polish_gia&sub=' . urlencode($attrType));
        exit;
    }

    // --- 11. Color Master: Add Color Tag ---
    if ($action === 'add_color_tag') {
        $category = trim($_POST['category'] ?? 'gia');
        if (!in_array($category, ['gia', 'polish'])) {
            $category = 'gia';
        }
        $tab = ($category === 'gia') ? 'gia_colors' : 'polish_colors';
        $tagName = trim($_POST['tag_name'] ?? '');
        $tagCode = trim($_POST['tag_code'] ?? '');
        $colorHex = trim($_POST['color_hex'] ?? '#94a3b8');
        $description = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($tagName)) {
            set_flash('error', 'કૃપા કરીને કલર ટેગનું નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_color_tags WHERE category = ? AND tag_name = ?");
            $chk->execute([$category, $tagName]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "કલર ટેગ '$tagName' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $ins = $pdo->prepare("INSERT INTO master_color_tags (category, tag_name, tag_code, color_hex, description, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                $ins->execute([$category, $tagName, $tagCode, $colorHex, $description, $sortOrder]);
                $newTagId = (int)$pdo->lastInsertId();
                log_activity('Color Tag Created', 'Settings', $newTagId, "Added $category Color Tag: $tagName");
                set_flash('success', "કલર ટેગ '$tagName' સફળતાપૂર્વક ઉમેરાયો.");
                header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=' . $tab . '&tag_id=' . $newTagId);
                exit;
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=' . $tab);
        exit;
    }

    // --- Color Master: Edit Color Tag ---
    if ($action === 'edit_color_tag') {
        $id = (int)($_POST['id'] ?? 0);
        $category = trim($_POST['category'] ?? 'gia');
        if (!in_array($category, ['gia', 'polish'])) {
            $category = 'gia';
        }
        $tab = ($category === 'gia') ? 'gia_colors' : 'polish_colors';
        $tagName = trim($_POST['tag_name'] ?? '');
        $tagCode = trim($_POST['tag_code'] ?? '');
        $colorHex = trim($_POST['color_hex'] ?? '#94a3b8');
        $description = trim($_POST['description'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($tagName) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય કલર ટેગ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_color_tags WHERE category = ? AND tag_name = ? AND id != ?");
            $chk->execute([$category, $tagName, $id]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "આ કલર ટેગ '$tagName' પહેલેથી બીજા રેકોર્ડમાં છે.");
            } else {
                $upd = $pdo->prepare("UPDATE master_color_tags SET tag_name = ?, tag_code = ?, color_hex = ?, description = ?, sort_order = ? WHERE id = ?");
                $upd->execute([$tagName, $tagCode, $colorHex, $description, $sortOrder, $id]);
                log_activity('Color Tag Updated', 'Settings', $id, "Updated $category Color Tag: $tagName");
                set_flash('success', "કલર ટેગ '$tagName' સફળતાપૂર્વક અપડેટ થયો.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=' . $tab . '&tag_id=' . $id);
        exit;
    }

    // --- Color Master: Toggle Color Tag Status ---
    if ($action === 'toggle_color_tag_status') {
        $id = (int)($_POST['id'] ?? 0);
        $category = trim($_POST['category'] ?? 'gia');
        $tab = ($category === 'gia') ? 'gia_colors' : 'polish_colors';
        $st = $pdo->prepare("SELECT status, tag_name FROM master_color_tags WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $upd = $pdo->prepare("UPDATE master_color_tags SET status = ? WHERE id = ?");
            $upd->execute([$newStatus, $id]);
            log_activity('Color Tag Status Changed', 'Settings', $id, "Toggled status of tag {$row['tag_name']} to $newStatus");
            set_flash('success', "કલર ટેગ '{$row['tag_name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=' . $tab . '&tag_id=' . $id);
        exit;
    }

    // --- Color Master: Delete Color Tag ---
    if ($action === 'delete_color_tag') {
        $id = (int)($_POST['id'] ?? 0);
        $category = trim($_POST['category'] ?? 'gia');
        $tab = ($category === 'gia') ? 'gia_colors' : 'polish_colors';
        $st = $pdo->prepare("SELECT tag_name FROM master_color_tags WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $pdo->prepare("DELETE FROM master_color_descriptions WHERE color_tag_id = ?")->execute([$id]);
            $del = $pdo->prepare("DELETE FROM master_color_tags WHERE id = ?");
            $del->execute([$id]);
            log_activity('Color Tag Deleted', 'Settings', $id, "Deleted Color Tag: {$row['tag_name']}");
            set_flash('success', "કલર ટેગ '{$row['tag_name']}' અને તેના તમામ ડિસ્ક્રિપ્શન સફળતાપૂર્વક ડિલીટ થયા.");
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=' . $tab);
        exit;
    }

    // --- Color Master: Add Color Description ---
    if ($action === 'add_color_description') {
        $category = trim($_POST['category'] ?? 'gia');
        if (!in_array($category, ['gia', 'polish'])) {
            $category = 'gia';
        }
        $tab = ($category === 'gia') ? 'gia_colors' : 'polish_colors';
        $colorTagId = (int)($_POST['color_tag_id'] ?? 0);
        $descName = trim($_POST['description_name'] ?? '');
        $descCode = trim($_POST['description_code'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($descName) || $colorTagId <= 0) {
            set_flash('error', 'કૃપા કરીને કલર ડિસ્ક્રિપ્શન નામ દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_color_descriptions WHERE color_tag_id = ? AND description_name = ?");
            $chk->execute([$colorTagId, $descName]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "આ ડિસ્ક્રિપ્શન '$descName' પહેલેથી અસ્તિત્વમાં છે.");
            } else {
                $ins = $pdo->prepare("INSERT INTO master_color_descriptions (category, color_tag_id, description_name, description_code, sort_order, status) VALUES (?, ?, ?, ?, ?, 'active')");
                $ins->execute([$category, $colorTagId, $descName, $descCode, $sortOrder]);
                $newDescId = (int)$pdo->lastInsertId();
                log_activity('Color Description Created', 'Settings', $newDescId, "Added Description: $descName under tag #$colorTagId");
                set_flash('success', "કલર ડિસ્ક્રિપ્શન '$descName' સફળતાપૂર્વક ઉમેરાયું.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=' . $tab . '&tag_id=' . $colorTagId);
        exit;
    }

    // --- Color Master: Edit Color Description ---
    if ($action === 'edit_color_description') {
        $id = (int)($_POST['id'] ?? 0);
        $category = trim($_POST['category'] ?? 'gia');
        $tab = ($category === 'gia') ? 'gia_colors' : 'polish_colors';
        $colorTagId = (int)($_POST['color_tag_id'] ?? 0);
        $descName = trim($_POST['description_name'] ?? '');
        $descCode = trim($_POST['description_code'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (empty($descName) || $id <= 0) {
            set_flash('error', 'કૃપા કરીને માન્ય કલર ડિસ્ક્રિપ્શન દાખલ કરો.');
        } else {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM master_color_descriptions WHERE color_tag_id = ? AND description_name = ? AND id != ?");
            $chk->execute([$colorTagId, $descName, $id]);
            if ($chk->fetchColumn() > 0) {
                set_flash('error', "આ ડિસ્ક્રિપ્શન '$descName' પહેલેથી બીજા રેકોર્ડમાં છે.");
            } else {
                $upd = $pdo->prepare("UPDATE master_color_descriptions SET description_name = ?, description_code = ?, sort_order = ? WHERE id = ?");
                $upd->execute([$descName, $descCode, $sortOrder, $id]);
                log_activity('Color Description Updated', 'Settings', $id, "Updated Description: $descName");
                set_flash('success', "કલર ડિસ્ક્રિપ્શન '$descName' સફળતાપૂર્વક અપડેટ થયું.");
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=' . $tab . '&tag_id=' . $colorTagId);
        exit;
    }

    // --- Color Master: Toggle Color Description Status ---
    if ($action === 'toggle_color_description_status') {
        $id = (int)($_POST['id'] ?? 0);
        $category = trim($_POST['category'] ?? 'gia');
        $tab = ($category === 'gia') ? 'gia_colors' : 'polish_colors';
        $colorTagId = (int)($_POST['color_tag_id'] ?? 0);
        $st = $pdo->prepare("SELECT status, description_name, color_tag_id FROM master_color_descriptions WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
            $upd = $pdo->prepare("UPDATE master_color_descriptions SET status = ? WHERE id = ?");
            $upd->execute([$newStatus, $id]);
            log_activity('Color Description Status Changed', 'Settings', $id, "Toggled status of desc {$row['description_name']} to $newStatus");
            set_flash('success', "ડિસ્ક્રિપ્શન '{$row['description_name']}' ની સ્થિતિ $newStatus કરવામાં આવી.");
            if ($colorTagId <= 0) {
                $colorTagId = (int)$row['color_tag_id'];
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=' . $tab . '&tag_id=' . $colorTagId);
        exit;
    }

    // --- Color Master: Delete Color Description ---
    if ($action === 'delete_color_description') {
        $id = (int)($_POST['id'] ?? 0);
        $category = trim($_POST['category'] ?? 'gia');
        $tab = ($category === 'gia') ? 'gia_colors' : 'polish_colors';
        $colorTagId = (int)($_POST['color_tag_id'] ?? 0);
        $st = $pdo->prepare("SELECT description_name, color_tag_id FROM master_color_descriptions WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if ($row) {
            $del = $pdo->prepare("DELETE FROM master_color_descriptions WHERE id = ?");
            $del->execute([$id]);
            log_activity('Color Description Deleted', 'Settings', $id, "Deleted Description: {$row['description_name']}");
            set_flash('success', "ડિસ્ક્રિપ્શન '{$row['description_name']}' સફળતાપૂર્વક ડિલીટ થયું.");
            if ($colorTagId <= 0) {
                $colorTagId = (int)$row['color_tag_id'];
            }
        }
        header('Location: ' . BASE_URL . '/modules/admin/settings.php?tab=' . $tab . '&tag_id=' . $colorTagId);
        exit;
    }
}

// Fetch Data
$partNames = $pdo->query("SELECT * FROM assort_part_names ORDER BY sort_order ASC, id ASC")->fetchAll();
$partTypes = $pdo->query("SELECT * FROM assort_part_types ORDER BY sort_order ASC, id ASC")->fetchAll();
$processingStages = get_all_processing_stages();

$destinations = get_all_destinations();
$safes = get_all_safes();
$dabbas = get_all_dabbas();
$activeDestinations = get_active_destinations();
$activeSafes = get_active_safes();
$allTags = get_all_tags();
$allProcessTags = get_all_process_tags();
$parcelClarities = get_all_master_parcel_clarities();

$systemSettings = [];
$sRows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
foreach ($sRows as $sr) {
    $systemSettings[$sr['setting_key']] = $sr['setting_value'];
}

$pageTitle = 'સિસ્ટમ સેટિંગ્સ & માસ્ટર મેનેજમેન્ટ';
require_once __DIR__ . '/../../includes/header.php';
?>

<?= render_page_header(
    'સેટિંગ્સ',
    '',
    'sliders',
    '
    <a href="' . BASE_URL . '/modules/parties/index.php" class="btn btn-outline-primary">' . svg_icon('address-book', '', 15) . 'પાર્ટી લિસ્ટ</a>
    <a href="' . BASE_URL . '/modules/reports/index.php" class="btn btn-outline-primary">' . svg_icon('chart-pie', '', 15) . 'રિપોર્ટ્સ</a>
    <a href="' . BASE_URL . '/modules/admin/users.php" class="btn btn-outline-primary">' . svg_icon('users-gear', '', 15) . 'યુઝર્સ</a>
    <a href="' . BASE_URL . '/modules/admin/backup.php" class="btn btn-primary">' . svg_icon('database', '', 15) . 'બેકઅપ</a>
    '
) ?>

<!-- Clean Finora Pill Tabs -->
<div class="nav-segmented-tabs mb-3 flex-wrap">
    <a class="nav-segment-item <?= $activeTab === 'part_names' ? 'active' : '' ?>" href="?tab=part_names">
        <?= svg_icon('puzzle-piece', '', 15) ?>
        <span>Assort ભાગના નામ & ટેગ</span>
    </a>
    <a class="nav-segment-item <?= $activeTab === 'processing_stages' ? 'active' : '' ?>" href="?tab=processing_stages">
        <?= svg_icon('stairs', '', 15) ?>
        <span>પ્રોસેસિંગ સ્ટેજ</span>
    </a>
    <a class="nav-segment-item <?= $activeTab === 'destinations' ? 'active' : '' ?>" href="?tab=destinations">
        <?= svg_icon('location-dot', '', 15) ?>
        <span>Destination (ડેસ્ટિનેશન)</span>
    </a>
    <a class="nav-segment-item <?= $activeTab === 'safes' ? 'active' : '' ?>" href="?tab=safes">
        <?= svg_icon('vault', '', 15) ?>
        <span>Safe (સેફ / તિજોરી)</span>
    </a>
    <a class="nav-segment-item <?= $activeTab === 'dabbas' ? 'active' : '' ?>" href="?tab=dabbas">
        <?= svg_icon('box-archive', '', 15) ?>
        <span>Dabba (ડબ્બો / બોક્સ)</span>
    </a>
    <a class="nav-segment-item <?= $activeTab === 'tags' ? 'active' : '' ?>" href="?tab=tags">
        <?= svg_icon('tag', '', 15) ?>
        <span>Tags (Rough, Tops, Rejection)</span>
    </a>
    <a class="nav-segment-item <?= $activeTab === 'process_tags' ? 'active' : '' ?>" href="?tab=process_tags">
        <?= svg_icon('bolt', '', 15) ?>
        <span>Process Tags (Sell, Hold, Assort)</span>
    </a>
    <a class="nav-segment-item <?= $activeTab === 'general' ? 'active' : '' ?>" href="?tab=general">
        <?= svg_icon('building', '', 15) ?>
        <span>કંપની વિગત</span>
    </a>
    <a class="nav-segment-item <?= $activeTab === 'polish_gia' ? 'active' : '' ?>" href="?tab=polish_gia">
        <?= svg_icon('gem', '', 15) ?>
        <span>Polish GIA માસ્ટર</span>
    </a>
    <a class="nav-segment-item <?= $activeTab === 'gia_colors' ? 'active' : '' ?>" href="?tab=gia_colors">
        <?= svg_icon('palette', '', 15) ?>
        <span>GIA કલર માસ્ટર (Tags & Desc)</span>
    </a>
    <a class="nav-segment-item <?= $activeTab === 'polish_colors' ? 'active' : '' ?>" href="?tab=polish_colors">
        <?= svg_icon('sparkles', '', 15) ?>
        <span>પોલિશ પાર્સલ કલર માસ્ટર</span>
    </a>
    <a class="nav-segment-item <?= $activeTab === 'parcel_clarities' ? 'active' : '' ?>" href="?tab=parcel_clarities">
        <?= svg_icon('certificate', '', 15) ?>
        <span>Polish Parcel Clarity (ક્લેરિટી)</span>
    </a>
</div>

<div class="tab-content" id="settingsTabContent">
    
    <!-- TAB 1: ASSORT PART NAMES -->
    <?php if ($activeTab === 'part_names'): ?>
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="finora-card">
                    <div class="finora-card-header">
                        <h6 class="finora-card-title"><?= svg_icon('circle-plus', 'text-primary', 17) ?>નવો ભાગ ઉમેરો</h6>
                    </div>
                    <div class="p-3">
                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=part_names" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_part_name">

                            <div class="mb-3">
                                <label class="form-label">ભાગનું નામ *</label>
                                <input type="text" name="part_name" class="form-control" placeholder="દા.ત. Part 7 (ચારણી)" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ટેગ (હેતુ / Routing) *</label>
                                <select name="tag" class="form-select fw-semibold" required>
                                    <option value="mfg">⚙️ MFG માટે (Direct Process)</option>
                                    <option value="sale">🏷️ Sell (વેચાણ માટે / Direct Sell)</option>
                                    <option value="mfg_later">⏳ MFG Later (ઓછા નંબર / પછીથી MFG)</option>
                                </select>
                                <small class="text-muted" style="font-size: 0.72rem;">આ ભાગ સીધો વેચાણમાં જશે કે મેન્યુફેક્ચરિંગમાં તે નક્કી કરો.</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ક્રમ</label>
                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= count($partNames) + 1 ?>" min="0">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <?= svg_icon('plus', '', 15) ?>ભાગ ઉમેરો
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="finora-card">
                    <div class="finora-card-header d-flex justify-content-between align-items-center">
                        <h6 class="finora-card-title mb-0"><?= svg_icon('list-check', 'text-primary', 17) ?>Assort ભાગોની યાદી</h6>
                        <span class="badge bg-light text-muted border small fw-normal"><i class="small">↕ ડ્રેગ કરીને ક્રમ બદલો</i></span>
                    </div>
                    <div class="p-0">
                        <div class="table-responsive">
                            <table class="table table-diamond mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">ક્રમ</th>
                                        <th style="width: 35%;">ભાગનું નામ</th>
                                        <th style="width: 25%;">ટેગ (હેતુ / Routing)</th>
                                        <th style="width: 14%;">સ્થિતિ</th>
                                        <th style="width: 16%;" class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyPartNames">
                                    <?php if (empty($partNames)): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">કોઈ ભાગ મળ્યો નથી.</td></tr>
                                    <?php else: ?>
                                        <?php $pIdx = 1; foreach ($partNames as $pn): ?>
                                            <tr data-id="<?= $pn['id'] ?>" class="sortable-row">
                                                <td class="font-mono text-muted text-nowrap">
                                                    <span class="drag-handle me-1" title="ડ્રેગ કરીને ક્રમ બદલો"><?= svg_icon('grip-vertical', '', 13) ?></span>
                                                    <span class="sort-order-badge fw-bold text-dark">#<?= $pIdx++ ?></span>
                                                </td>
                                                <td><strong class="text-dark"><?= e($pn['name']) ?></strong></td>
                                                <td><?= get_assort_tag_badge($pn['tag'] ?? 'mfg') ?></td>
                                                <td>
                                                    <span class="finora-pill <?= $pn['status'] === 'active' ? 'finora-pill-green' : 'finora-pill-slate' ?>">
                                                        <?= $pn['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1">
                                                        <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editPartNameModal<?= $pn['id'] ?>" title="સુધારો (Edit)">
                                                            <?= svg_icon('pen-to-square', '', 13) ?>
                                                        </button>

                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=part_names" method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_part_name_status">
                                                            <input type="hidden" name="id" value="<?= $pn['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-secondary" title="<?= $pn['status'] === 'active' ? 'નિષ્ક્રિય કરો' : 'સક્રિય કરો' ?>">
                                                                <?= svg_icon($pn['status'] === 'active' ? 'eye-slash' : 'eye', '', 13) ?>
                                                            </button>
                                                        </form>

                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=part_names" method="POST" class="d-inline" onsubmit="return confirm('શું તમે ખરેખર આ ભાગ ડિલીટ કરવા માંગો છો?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_part_name">
                                                            <input type="hidden" name="id" value="<?= $pn['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-danger" title="ડિલીટ">
                                                                <?= svg_icon('trash-can', '', 13) ?>
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <!-- Edit Modal for Part Name -->
                                                    <div class="modal fade text-start" id="editPartNameModal<?= $pn['id'] ?>" tabindex="-1">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content">
                                                                <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=part_names" method="POST">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="edit_part_name">
                                                                    <input type="hidden" name="id" value="<?= $pn['id'] ?>">
                                                                    <div class="modal-header">
                                                                        <h6 class="modal-title fw-bold">
                                                                            <?= svg_icon('pen-to-square', 'text-primary me-2', 16) ?>Assort ભાગમાં સુધારો
                                                                        </h6>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">ભાગનું નામ *</label>
                                                                            <input type="text" name="part_name" class="form-control" value="<?= e($pn['name']) ?>" required>
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">ટેગ (હેતુ / Routing) *</label>
                                                                            <select name="tag" class="form-select fw-semibold" required>
                                                                                <option value="mfg" <?= ($pn['tag'] ?? 'mfg') === 'mfg' ? 'selected' : '' ?>>⚙️ MFG માટે (Direct Process)</option>
                                                                                <option value="sale" <?= ($pn['tag'] ?? 'mfg') === 'sale' ? 'selected' : '' ?>>🏷️ Sell (વેચાણ માટે / Direct Sell)</option>
                                                                                <option value="mfg_later" <?= ($pn['tag'] ?? 'mfg') === 'mfg_later' ? 'selected' : '' ?>>⏳ MFG Later (ઓછા નંબર / પછીથી MFG)</option>
                                                                            </select>
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">ક્રમ (Sort Order)</label>
                                                                            <input type="number" name="sort_order" class="form-control font-mono" value="<?= (int)$pn['sort_order'] ?>" min="0">
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">રદ કરો</button>
                                                                        <button type="submit" class="btn btn-primary btn-sm fw-bold">
                                                                            <?= svg_icon('check', 'me-1', 13) ?>ફેરફાર સાચવો
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- TAB 3: PROCESSING STAGES MASTER -->
    <?php elseif ($activeTab === 'processing_stages'): ?>
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="finora-card">
                    <div class="finora-card-header">
                        <h6 class="finora-card-title"><?= svg_icon('circle-plus', 'text-primary', 17) ?>નવો સ્ટેજ ઉમેરો</h6>
                    </div>
                    <div class="p-3">
                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=processing_stages" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_processing_stage">

                            <div class="mb-3">
                                <label class="form-label">સ્ટેજ કોડ *</label>
                                <input type="text" name="stage_code" class="form-control font-mono" placeholder="faceting, blocking" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">સ્ટેજનું નામ *</label>
                                <input type="text" name="stage_name" class="form-control" placeholder="Faceting" required>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="form-label">આયકન નામ (SVG)</label>
                                    <input type="text" name="stage_icon" class="form-control font-mono" value="gem">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">કલર</label>
                                    <input type="color" name="color_code" class="form-control form-control-color w-100" value="#3b82f6">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ડિફોલ્ટ લોકેશન</label>
                                <input type="text" name="default_location" class="form-control" value="In House">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">કેટેગરી</label>
                                <select name="default_category" class="form-select">
                                    <option value="">-- સિલેક્ટ --</option>
                                    <option value="outsource_ls">લેસર સોઇંગ (LS)</option>
                                    <option value="outsource_galaxy">ગેલેક્સી પ્લાનિંગ</option>
                                    <option value="outsource_factory">જોબવર્ક ફેક્ટરી</option>
                                    <option value="lab_gia">GIA / IGI લેબ</option>
                                    <option value="memo_broker">દલાલ મેમો</option>
                                    <option value="memo_party">પાર્ટી મેમો</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ક્રમ</label>
                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= count($processingStages) + 1 ?>" min="0">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">નોંધ</label>
                                <textarea name="description" class="form-control" rows="2"></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <?= svg_icon('plus', '', 15) ?>સ્ટેજ ઉમેરો
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="finora-card">
                    <div class="finora-card-header d-flex justify-content-between align-items-center">
                        <h6 class="finora-card-title mb-0"><?= svg_icon('stairs', 'text-primary', 17) ?>પ્રોસેસિંગ સ્ટેજીસ</h6>
                        <span class="badge bg-light text-muted border small fw-normal"><i class="small">↕ ડ્રેગ કરીને ક્રમ બદલો</i></span>
                    </div>
                    <div class="p-0">
                        <div class="table-responsive">
                            <table class="table table-diamond mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">ક્રમ</th>
                                        <th style="width: 28%;">સ્ટેજ નામ</th>
                                        <th style="width: 18%;">કોડ</th>
                                        <th style="width: 20%;">લોકેશન</th>
                                        <th style="width: 12%;">સ્થિતિ</th>
                                        <th style="width: 12%;" class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyProcessingStages">
                                    <?php if (empty($processingStages)): ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted">કોઈ સ્ટેજ મળ્યો નથી.</td></tr>
                                    <?php else: ?>
                                        <?php $stgIdx = 1; foreach ($processingStages as $stg): ?>
                                            <tr data-id="<?= $stg['id'] ?>" class="sortable-row">
                                                <td class="font-mono text-muted text-nowrap">
                                                    <span class="drag-handle me-1" title="ડ્રેગ કરીને ક્રમ બદલો"><?= svg_icon('grip-vertical', '', 13) ?></span>
                                                    <span class="sort-order-badge fw-bold text-dark">#<?= $stgIdx++ ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="kpi-icon-tile text-white" style="width: 30px; height: 30px; border-radius: 8px; background-color: <?= e($stg['color_code'] ?: '#3b82f6') ?>;">
                                                            <?= svg_icon($stg['stage_icon'] ?: 'gem', '', 14) ?>
                                                        </span>
                                                        <strong class="text-dark"><?= e($stg['stage_name']) ?></strong>
                                                    </div>
                                                </td>
                                                <td><span class="badge-code"><?= e($stg['stage_code']) ?></span></td>
                                                <td class="small text-muted"><?= e($stg['default_location'] ?: 'In House') ?></td>
                                                <td>
                                                    <span class="finora-pill <?= $stg['status'] === 'active' ? 'finora-pill-green' : 'finora-pill-slate' ?>">
                                                        <?= $stg['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-1">
                                                        <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editStageModal<?= $stg['id'] ?>" title="સુધારો (Edit)">
                                                            <?= svg_icon('pen-to-square', '', 13) ?>
                                                        </button>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=processing_stages" method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_processing_stage_status">
                                                            <input type="hidden" name="id" value="<?= $stg['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-secondary" title="Toggle">
                                                                <?= svg_icon($stg['status'] === 'active' ? 'pause' : 'play', '', 13) ?>
                                                            </button>
                                                        </form>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=processing_stages" method="POST" class="d-inline" onsubmit="return confirm('શું તમે આ સ્ટેજ ડિલીટ કરવા માંગો છો?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_processing_stage">
                                                            <input type="hidden" name="id" value="<?= $stg['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-danger" title="ડિલીટ">
                                                                <?= svg_icon('trash-can', '', 13) ?>
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <!-- Edit Modal for Processing Stage -->
                                                    <div class="modal fade text-start" id="editStageModal<?= $stg['id'] ?>" tabindex="-1">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content">
                                                                <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=processing_stages" method="POST">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="edit_processing_stage">
                                                                    <input type="hidden" name="id" value="<?= $stg['id'] ?>">
                                                                    <div class="modal-header">
                                                                        <h6 class="modal-title fw-bold">
                                                                            <?= svg_icon('pen-to-square', 'text-primary me-2', 16) ?>પ્રોસેસિંગ સ્ટેજમાં સુધારો
                                                                        </h6>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <div class="row g-2 mb-2">
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">સ્ટેજ કોડ *</label>
                                                                                <input type="text" name="stage_code" class="form-control font-mono" value="<?= e($stg['stage_code']) ?>" required>
                                                                            </div>
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">સ્ટેજનું નામ *</label>
                                                                                <input type="text" name="stage_name" class="form-control" value="<?= e($stg['stage_name']) ?>" required>
                                                                            </div>
                                                                        </div>
                                                                        <div class="row g-2 mb-2">
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">SVG Icon</label>
                                                                                <input type="text" name="stage_icon" class="form-control font-mono" value="<?= e($stg['stage_icon'] ?? 'gem') ?>">
                                                                            </div>
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">કલર કોડ</label>
                                                                                <input type="color" name="color_code" class="form-control form-control-color w-100" value="<?= e($stg['color_code'] ?? '#3b82f6') ?>">
                                                                            </div>
                                                                        </div>
                                                                        <div class="row g-2 mb-2">
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">Default Location</label>
                                                                                <input type="text" name="default_location" class="form-control" value="<?= e($stg['default_location'] ?? 'In House') ?>">
                                                                            </div>
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">ક્રમ (Sort Order)</label>
                                                                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= (int)$stg['sort_order'] ?>" min="0">
                                                                            </div>
                                                                        </div>
                                                                        <div class="mb-2">
                                                                            <label class="form-label small fw-bold">વિગત / વર્ણન</label>
                                                                            <input type="text" name="description" class="form-control" value="<?= e($stg['description'] ?? '') ?>">
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">રદ કરો</button>
                                                                        <button type="submit" class="btn btn-primary btn-sm fw-bold">
                                                                            <?= svg_icon('check', 'me-1', 13) ?>ફેરફાર સાચવો
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- TAB: DESTINATIONS -->
    <?php elseif ($activeTab === 'destinations'): ?>
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="finora-card">
                    <div class="finora-card-header">
                        <h6 class="finora-card-title"><?= svg_icon('circle-plus', 'text-primary', 17) ?>નવું ડેસ્ટિનેશન ઉમેરો</h6>
                    </div>
                    <div class="p-3">
                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=destinations" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_destination">

                            <div class="mb-3">
                                <label class="form-label">ડેસ્ટિનેશન / લોકેશન નામ *</label>
                                <input type="text" name="name" class="form-control fw-bold" placeholder="દા.ત. Surat, Mumbai, Navsari" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">શોર્ટ કોડ</label>
                                <input type="text" name="code" class="form-control font-mono text-uppercase" placeholder="દા.ત. SURAT, MUM">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">વિગત / નોંધ</label>
                                <input type="text" name="description" class="form-control" placeholder="દા.ત. હેડ ઓફિસ / ટ્રેડિંગ યુનિટ">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ક્રમ (Sort Order)</label>
                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= count($destinations) + 1 ?>" min="0">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <?= svg_icon('plus', '', 15) ?>ડેસ્ટિનેશન ઉમેરો
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="finora-card">
                    <div class="finora-card-header d-flex justify-content-between align-items-center">
                        <h6 class="finora-card-title mb-0"><?= svg_icon('location-dot', 'text-primary', 17) ?>ડેસ્ટિનેશન યાદી (Destinations / Locations)</h6>
                        <span class="badge bg-light text-muted border small fw-normal"><i class="small">↕ ડ્રેગ કરીને ક્રમ બદલો</i></span>
                    </div>
                    <div class="p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">ક્રમ</th>
                                        <th style="width: 28%;">ડેસ્ટિનેશન નામ</th>
                                        <th style="width: 16%;">કોડ</th>
                                        <th style="width: 24%;">વિગત</th>
                                        <th style="width: 11%;">સ્થિતિ</th>
                                        <th style="width: 11%;" class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyDestinations">
                                    <?php if (empty($destinations)): ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted">કોઈ ડેસ્ટિનેશન મળ્યું નથી.</td></tr>
                                    <?php else: ?>
                                        <?php $dstIdx = 1; foreach ($destinations as $dst): ?>
                                            <tr data-id="<?= $dst['id'] ?>" class="sortable-row">
                                                <td class="font-mono text-muted text-nowrap">
                                                    <span class="drag-handle me-1" title="ડ્રેગ કરીને ક્રમ બદલો"><?= svg_icon('grip-vertical', '', 13) ?></span>
                                                    <span class="sort-order-badge fw-bold text-dark">#<?= $dstIdx++ ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-circle p-1.5">
                                                            <?= svg_icon('location-dot', '', 13) ?>
                                                        </span>
                                                        <strong class="text-dark"><?= e($dst['name']) ?></strong>
                                                    </div>
                                                </td>
                                                <td><span class="badge-code font-mono text-uppercase"><?= e($dst['code'] ?: '-') ?></span></td>
                                                <td class="small text-muted"><?= e($dst['description'] ?: '—') ?></td>
                                                <td>
                                                    <span class="finora-pill <?= $dst['status'] === 'active' ? 'finora-pill-green' : 'finora-pill-slate' ?>">
                                                        <?= $dst['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-1">
                                                        <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editDestModal<?= $dst['id'] ?>" title="સુધારો (Edit)">
                                                            <?= svg_icon('pen-to-square', '', 13) ?>
                                                        </button>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=destinations" method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_destination_status">
                                                            <input type="hidden" name="id" value="<?= $dst['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-secondary" title="સ્થિતિ બદલો">
                                                                <?= svg_icon($dst['status'] === 'active' ? 'pause' : 'play', '', 13) ?>
                                                            </button>
                                                        </form>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=destinations" method="POST" class="d-inline" onsubmit="return confirm('શું તમે આ ડેસ્ટિનેશન ડિલીટ કરવા માંગો છો?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_destination">
                                                            <input type="hidden" name="id" value="<?= $dst['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-danger" title="ડિલીટ">
                                                                <?= svg_icon('trash-can', '', 13) ?>
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <!-- Edit Modal for Destination -->
                                                    <div class="modal fade text-start" id="editDestModal<?= $dst['id'] ?>" tabindex="-1">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content">
                                                                <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=destinations" method="POST">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="edit_destination">
                                                                    <input type="hidden" name="id" value="<?= $dst['id'] ?>">
                                                                    <div class="modal-header">
                                                                        <h6 class="modal-title fw-bold">
                                                                            <?= svg_icon('pen-to-square', 'text-primary me-2', 16) ?>ડેસ્ટિનેશનમાં સુધારો
                                                                        </h6>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">ડેસ્ટિનેશન નામ *</label>
                                                                            <input type="text" name="name" class="form-control" value="<?= e($dst['name']) ?>" required>
                                                                        </div>
                                                                        <div class="row g-2 mb-3">
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">કોડ (Code)</label>
                                                                                <input type="text" name="code" class="form-control font-mono text-uppercase" value="<?= e($dst['code'] ?? '') ?>">
                                                                            </div>
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">ક્રમ (Sort Order)</label>
                                                                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= (int)$dst['sort_order'] ?>" min="0">
                                                                            </div>
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">વિગત / નોંધ</label>
                                                                            <input type="text" name="description" class="form-control" value="<?= e($dst['description'] ?? '') ?>">
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">રદ કરો</button>
                                                                        <button type="submit" class="btn btn-primary btn-sm fw-bold">
                                                                            <?= svg_icon('check', 'me-1', 13) ?>ફેરફાર સાચવો
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- TAB: SAFES -->
    <?php elseif ($activeTab === 'safes'): ?>
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="finora-card">
                    <div class="finora-card-header">
                        <h6 class="finora-card-title"><?= svg_icon('circle-plus', 'text-primary', 17) ?>નવો સેફ / તિજોરી ઉમેરો</h6>
                    </div>
                    <div class="p-3">
                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=safes" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_safe">

                            <div class="mb-3">
                                <label class="form-label">સેફનું નામ (Safe Name) *</label>
                                <input type="text" name="name" class="form-control fw-bold font-mono" placeholder="દા.ત. OFC, M1, N1, Safe A" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">શોર્ટ કોડ</label>
                                <input type="text" name="code" class="form-control font-mono text-uppercase" placeholder="દા.ત. OFC, M1">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ડેસ્ટિનેશન (ક્યાં આવેલ છે?)</label>
                                <select name="destination_id" class="form-select">
                                    <option value="">— સામાન્ય / સિલેક્ટ કરો —</option>
                                    <?php foreach ($activeDestinations as $ad): ?>
                                        <option value="<?= (int)$ad['id'] ?>"><?= e($ad['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">વિગત / નોંધ</label>
                                <input type="text" name="description" class="form-control" placeholder="દા.ત. મુખ્ય ઓફિસ તિજોરી">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ક્રમ (Sort Order)</label>
                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= count($safes) + 1 ?>" min="0">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <?= svg_icon('plus', '', 15) ?>સેફ ઉમેરો
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="finora-card">
                    <div class="finora-card-header d-flex justify-content-between align-items-center">
                        <h6 class="finora-card-title mb-0"><?= svg_icon('vault', 'text-primary', 17) ?>સેફ / તિજોરી યાદી (Safes Directory)</h6>
                        <span class="badge bg-light text-muted border small fw-normal"><i class="small">↕ ડ્રેગ કરીને ક્રમ બદલો</i></span>
                    </div>
                    <div class="p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">ક્રમ</th>
                                        <th style="width: 23%;">સેફ નામ</th>
                                        <th style="width: 15%;">કોડ</th>
                                        <th style="width: 18%;">ડેસ્ટિનેશન</th>
                                        <th style="width: 15%;">વિગત</th>
                                        <th style="width: 10%;">સ્થિતિ</th>
                                        <th style="width: 9%;" class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodySafes">
                                    <?php if (empty($safes)): ?>
                                        <tr><td colspan="7" class="text-center py-4 text-muted">કોઈ સેફ મળ્યો નથી.</td></tr>
                                    <?php else: ?>
                                        <?php $sfIdx = 1; foreach ($safes as $sf): ?>
                                            <tr data-id="<?= $sf['id'] ?>" class="sortable-row">
                                                <td class="font-mono text-muted text-nowrap">
                                                    <span class="drag-handle me-1" title="ડ્રેગ કરીને ક્રમ બદલો"><?= svg_icon('grip-vertical', '', 13) ?></span>
                                                    <span class="sort-order-badge fw-bold text-dark">#<?= $sfIdx++ ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono fw-bold px-2 py-1">
                                                            <?= e($sf['name']) ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td><span class="badge-code font-mono text-uppercase"><?= e($sf['code'] ?: '-') ?></span></td>
                                                <td><span class="badge bg-light text-dark border"><?= e($sf['destination_name'] ?: 'Surat') ?></span></td>
                                                <td class="small text-muted"><?= e($sf['description'] ?: '—') ?></td>
                                                <td>
                                                    <span class="finora-pill <?= $sf['status'] === 'active' ? 'finora-pill-green' : 'finora-pill-slate' ?>">
                                                        <?= $sf['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-1">
                                                        <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSafeModal<?= $sf['id'] ?>" title="સુધારો (Edit)">
                                                            <?= svg_icon('pen-to-square', '', 13) ?>
                                                        </button>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=safes" method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_safe_status">
                                                            <input type="hidden" name="id" value="<?= $sf['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-secondary" title="સ્થિતિ બદલો">
                                                                <?= svg_icon($sf['status'] === 'active' ? 'pause' : 'play', '', 13) ?>
                                                            </button>
                                                        </form>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=safes" method="POST" class="d-inline" onsubmit="return confirm('શું તમે આ સેફ ડિલીટ કરવા માંગો છો?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_safe">
                                                            <input type="hidden" name="id" value="<?= $sf['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-danger" title="ડિલીટ">
                                                                <?= svg_icon('trash-can', '', 13) ?>
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <!-- Edit Modal for Safe -->
                                                    <div class="modal fade text-start" id="editSafeModal<?= $sf['id'] ?>" tabindex="-1">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content">
                                                                <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=safes" method="POST">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="edit_safe">
                                                                    <input type="hidden" name="id" value="<?= $sf['id'] ?>">
                                                                    <div class="modal-header">
                                                                        <h6 class="modal-title fw-bold">
                                                                            <?= svg_icon('pen-to-square', 'text-primary me-2', 16) ?>સેફમાં સુધારો
                                                                        </h6>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">સેફ નામ / નંબર *</label>
                                                                            <input type="text" name="name" class="form-control" value="<?= e($sf['name']) ?>" required>
                                                                        </div>
                                                                        <div class="row g-2 mb-3">
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">કોડ (Code)</label>
                                                                                <input type="text" name="code" class="form-control font-mono text-uppercase" value="<?= e($sf['code'] ?? '') ?>">
                                                                            </div>
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">ડેસ્ટિનેશન (શહેર / લોકેશન)</label>
                                                                                <select name="destination_id" class="form-select">
                                                                                    <option value="">-- સિલેક્ટ કરો --</option>
                                                                                    <?php foreach ($destinations as $d): ?>
                                                                                        <option value="<?= $d['id'] ?>" <?= ((int)($sf['destination_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>>
                                                                                            <?= e($d['name']) ?>
                                                                                        </option>
                                                                                    <?php endforeach; ?>
                                                                                </select>
                                                                            </div>
                                                                        </div>
                                                                        <div class="row g-2 mb-3">
                                                                            <div class="col-8">
                                                                                <label class="form-label small fw-bold">વિગત / નોંધ</label>
                                                                                <input type="text" name="description" class="form-control" value="<?= e($sf['description'] ?? '') ?>">
                                                                            </div>
                                                                            <div class="col-4">
                                                                                <label class="form-label small fw-bold">ક્રમ (Sort)</label>
                                                                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= (int)$sf['sort_order'] ?>" min="0">
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">રદ કરો</button>
                                                                        <button type="submit" class="btn btn-primary btn-sm fw-bold">
                                                                            <?= svg_icon('check', 'me-1', 13) ?>ફેરફાર સાચવો
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- TAB: DABBAS -->
    <?php elseif ($activeTab === 'dabbas'): ?>
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="finora-card">
                    <div class="finora-card-header">
                        <h6 class="finora-card-title"><?= svg_icon('circle-plus', 'text-primary', 17) ?>નવો ડબ્બો / બોક્સ ઉમેરો</h6>
                    </div>
                    <div class="p-3">
                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=dabbas" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_dabba">

                            <div class="mb-3">
                                <label class="form-label">ડબ્બા નંબર / નામ (Dabba Name / No) *</label>
                                <input type="text" name="name" class="form-control fw-bold font-mono" placeholder="દા.ત. 1, 2, 3, 10, Dabba A" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">શોર્ટ કોડ</label>
                                <input type="text" name="code" class="form-control font-mono text-uppercase" placeholder="દા.ત. D1, D2">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ક્યાં સેફમાં આવેલ છે? (Safe Reference)</label>
                                <select name="safe_id" class="form-select">
                                    <option value="">— સામાન્ય / સિલેક્ટ કરો —</option>
                                    <?php foreach ($activeSafes as $as): ?>
                                        <option value="<?= (int)$as['id'] ?>"><?= e($as['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">વિગત / નોંધ</label>
                                <input type="text" name="description" class="form-control" placeholder="દા.ત. ડબ્બો નંબર ૧">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ક્રમ (Sort Order)</label>
                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= count($dabbas) + 1 ?>" min="0">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <?= svg_icon('plus', '', 15) ?>ડબ્બો ઉમેરો
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="finora-card">
                    <div class="finora-card-header d-flex justify-content-between align-items-center">
                        <h6 class="finora-card-title mb-0"><?= svg_icon('box-archive', 'text-primary', 17) ?>ડબ્બા / બોક્સ યાદી (Dabbas Directory)</h6>
                        <span class="badge bg-light text-muted border small fw-normal"><i class="small">↕ ડ્રેગ કરીને ક્રમ બદલો</i></span>
                    </div>
                    <div class="p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">ક્રમ</th>
                                        <th style="width: 23%;">ડબ્બા નંબર/નામ</th>
                                        <th style="width: 15%;">કોડ</th>
                                        <th style="width: 18%;">સેફ સંદર્ભ</th>
                                        <th style="width: 15%;">વિગત</th>
                                        <th style="width: 10%;">સ્થિતિ</th>
                                        <th style="width: 9%;" class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyDabbas">
                                    <?php if (empty($dabbas)): ?>
                                        <tr><td colspan="7" class="text-center py-4 text-muted">કોઈ ડબ્બો મળ્યો નથી.</td></tr>
                                    <?php else: ?>
                                        <?php $dbIdx = 1; foreach ($dabbas as $db): ?>
                                            <tr data-id="<?= $db['id'] ?>" class="sortable-row">
                                                <td class="font-mono text-muted text-nowrap">
                                                    <span class="drag-handle me-1" title="ડ્રેગ કરીને ક્રમ બદલો"><?= svg_icon('grip-vertical', '', 13) ?></span>
                                                    <span class="sort-order-badge fw-bold text-dark">#<?= $dbIdx++ ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge bg-light text-dark border font-mono fw-bold px-2.5 py-1">
                                                            <?= e($db['name']) ?>
                                                        </span>
                                                    </div>
                                                </td>
                                                <td><span class="badge-code font-mono text-uppercase"><?= e($db['code'] ?: '-') ?></span></td>
                                                <td><span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono"><?= e($db['safe_name'] ?: 'OFC') ?></span></td>
                                                <td class="small text-muted"><?= e($db['description'] ?: '—') ?></td>
                                                <td>
                                                    <span class="finora-pill <?= $db['status'] === 'active' ? 'finora-pill-green' : 'finora-pill-slate' ?>">
                                                        <?= $db['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-1">
                                                        <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editDabbaModal<?= $db['id'] ?>" title="સુધારો (Edit)">
                                                            <?= svg_icon('pen-to-square', '', 13) ?>
                                                        </button>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=dabbas" method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_dabba_status">
                                                            <input type="hidden" name="id" value="<?= $db['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-secondary" title="સ્થિતિ બદલો">
                                                                <?= svg_icon($db['status'] === 'active' ? 'pause' : 'play', '', 13) ?>
                                                            </button>
                                                        </form>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=dabbas" method="POST" class="d-inline" onsubmit="return confirm('શું તમે આ ડબ્બો ડિલીટ કરવા માંગો છો?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_dabba">
                                                            <input type="hidden" name="id" value="<?= $db['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-danger" title="ડિલીટ">
                                                                <?= svg_icon('trash-can', '', 13) ?>
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <!-- Edit Modal for Dabba -->
                                                    <div class="modal fade text-start" id="editDabbaModal<?= $db['id'] ?>" tabindex="-1">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content">
                                                                <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=dabbas" method="POST">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="edit_dabba">
                                                                    <input type="hidden" name="id" value="<?= $db['id'] ?>">
                                                                    <div class="modal-header">
                                                                        <h6 class="modal-title fw-bold">
                                                                            <?= svg_icon('pen-to-square', 'text-primary me-2', 16) ?>ડબ્બામાં સુધારો
                                                                        </h6>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">ડબ્બા નંબર / નામ *</label>
                                                                            <input type="text" name="name" class="form-control fw-bold font-mono" value="<?= e($db['name']) ?>" required>
                                                                        </div>
                                                                        <div class="row g-2 mb-3">
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">શોર્ટ કોડ</label>
                                                                                <input type="text" name="code" class="form-control font-mono text-uppercase" value="<?= e($db['code'] ?? '') ?>">
                                                                            </div>
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">સેફ સંદર્ભ (Safe)</label>
                                                                                <select name="safe_id" class="form-select">
                                                                                    <option value="">— સામાન્ય / સિલેક્ટ કરો —</option>
                                                                                    <?php foreach ($safes as $sf_opt): ?>
                                                                                        <option value="<?= $sf_opt['id'] ?>" <?= ((int)($db['safe_id'] ?? 0) === (int)$sf_opt['id']) ? 'selected' : '' ?>>
                                                                                            <?= e($sf_opt['name']) ?>
                                                                                        </option>
                                                                                    <?php endforeach; ?>
                                                                                </select>
                                                                            </div>
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">વિગત / નોંધ</label>
                                                                            <input type="text" name="description" class="form-control" value="<?= e($db['description'] ?? '') ?>">
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">ક્રમ (Sort Order)</label>
                                                                            <input type="number" name="sort_order" class="form-control font-mono" value="<?= (int)($db['sort_order'] ?? 0) ?>" min="0">
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                                                                        <button type="submit" class="btn btn-primary">
                                                                            <?= svg_icon('floppy-disk', '', 14) ?>સેવ કરો
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- TAB 4: GENERAL SETTINGS -->
    <?php elseif ($activeTab === 'general'): ?>
        <div class="row justify-content-center">
            <div class="col-12 col-xl-8">
                <div class="finora-card">
                    <div class="finora-card-header">
                        <h6 class="finora-card-title"><?= svg_icon('building', 'text-primary', 17) ?>કંપની પ્રોફાઇલ & બિઝનેસ સેટિંગ્સ</h6>
                    </div>
                    <div class="p-4">
                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=general" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_general_settings">

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">કંપનીનું નામ</label>
                                    <input type="text" name="company_name" class="form-control fw-bold" value="<?= e($systemSettings['company_name'] ?? 'Kasyap Gems & Diamonds') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">GST નંબર</label>
                                    <input type="text" name="company_gst" class="form-control font-mono" value="<?= e($systemSettings['company_gst'] ?? '') ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">સરનામું</label>
                                    <textarea name="company_address" class="form-control" rows="2"><?= e($systemSettings['company_address'] ?? '') ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">ફોન નંબર</label>
                                    <input type="text" name="company_phone" class="form-control" value="<?= e($systemSettings['company_phone'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">ઇમેઇલ</label>
                                    <input type="email" name="company_email" class="form-control" value="<?= e($systemSettings['company_email'] ?? '') ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">ડિફોલ્ટ ડોલર રેટ</label>
                                    <div class="input-group">
                                        <span class="input-group-text">$</span>
                                        <input type="number" step="0.01" name="default_dollar_rate" class="form-control font-mono" value="<?= e($systemSettings['default_dollar_rate'] ?? '83.50') ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">ડિફોલ્ટ મુદત (Days)</label>
                                    <div class="input-group">
                                        <input type="number" name="default_due_days" class="form-control font-mono" value="<?= e($systemSettings['default_due_days'] ?? '50') ?>">
                                        <span class="input-group-text">Days</span>
                                    </div>
                                </div>
                            </div>

                            <div class="text-end border-top pt-3">
                                <button type="submit" class="btn btn-primary px-4">
                                    <?= svg_icon('floppy-disk', '', 15) ?>સેટિંગ્સ સેવ કરો
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    <!-- TAB: TAGS (Rough, Tops, Rejection) -->
    <?php elseif ($activeTab === 'tags'): ?>
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="finora-card">
                    <div class="finora-card-header">
                        <h6 class="finora-card-title"><?= svg_icon('circle-plus', 'text-primary', 17) ?>નવો Tag ઉમેરો</h6>
                    </div>
                    <div class="p-3">
                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=tags" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_tag">

                            <div class="mb-3">
                                <label class="form-label">ટેગનું નામ (Tag Name) *</label>
                                <input type="text" name="name" class="form-control fw-bold" placeholder="દા.ત. Rough, Tops, Rejection, Special" required>
                                <div class="form-text text-muted" style="font-size: 0.75rem;">આ ટેગ રફ ખરીદી કરતી વખતે સિલેક્ટ કરી શકાશે.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">શોર્ટ કોડ (Code)</label>
                                <input type="text" name="code" class="form-control font-mono text-uppercase" placeholder="દા.ત. ROUGH, TOPS, REJ">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">કલર બેજ (Badge Color)</label>
                                <select name="color" class="form-select">
                                    <option value="blue">Blue (વાદળી)</option>
                                    <option value="purple">Purple (જાંબલી)</option>
                                    <option value="rose">Rose / Red (ગુલાબી/લાલ)</option>
                                    <option value="emerald">Emerald / Green (લીલો)</option>
                                    <option value="amber">Amber / Orange (નારંગી)</option>
                                    <option value="cyan">Cyan (આકાશી)</option>
                                    <option value="slate">Slate / Gray (ગ્રે)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">વિગત / નોંધ (Description)</label>
                                <input type="text" name="description" class="form-control" placeholder="દા.ત. રફ હીરા ખરીદી લૉટ">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ક્રમ (Sort Order)</label>
                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= count($allTags) + 1 ?>" min="0">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <?= svg_icon('plus', '', 15) ?>ટેગ સેવ કરો
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="finora-card">
                    <div class="finora-card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <h6 class="finora-card-title mb-0"><?= svg_icon('tag', 'text-primary', 17) ?>ટેગ ડિરેક્ટરી (Tags Directory)</h6>
                            <span class="badge bg-primary-subtle text-primary border px-2 py-0.5"><?= count($allTags) ?> ટેગ</span>
                        </div>
                        <span class="badge bg-light text-muted border small fw-normal"><i class="small">↕ ડ્રેગ કરીને ક્રમ બદલો</i></span>
                    </div>
                    <div class="p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">ક્રમ</th>
                                        <th style="width: 25%;">ટેગ નામ</th>
                                        <th style="width: 15%;">કોડ</th>
                                        <th style="width: 23%;">વિગત</th>
                                        <th style="width: 12%;">સ્થિતિ</th>
                                        <th style="width: 15%;" class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyTags">
                                    <?php if (empty($allTags)): ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted">કોઈ ટેગ મળ્યો નથી.</td></tr>
                                    <?php else: ?>
                                        <?php $tgIdx = 1; foreach ($allTags as $tg): ?>
                                            <tr data-id="<?= $tg['id'] ?>" class="sortable-row">
                                                <td class="font-mono text-muted text-nowrap">
                                                    <span class="drag-handle me-1" title="ડ્રેગ કરીને ક્રમ બદલો"><?= svg_icon('grip-vertical', '', 13) ?></span>
                                                    <span class="sort-order-badge fw-bold text-dark">#<?= $tgIdx++ ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <?= render_tag_badge($tg['name']) ?>
                                                    </div>
                                                </td>
                                                <td><span class="badge-code font-mono text-uppercase"><?= e($tg['code'] ?: '-') ?></span></td>
                                                <td class="small text-muted"><?= e($tg['description'] ?: '—') ?></td>
                                                <td>
                                                    <span class="finora-pill <?= $tg['status'] === 'active' ? 'finora-pill-green' : 'finora-pill-slate' ?>">
                                                        <?= $tg['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-1">
                                                        <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editTagModal<?= $tg['id'] ?>" title="સુધારો (Edit)">
                                                            <?= svg_icon('pen-to-square', '', 13) ?>
                                                        </button>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=tags" method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_tag_status">
                                                            <input type="hidden" name="id" value="<?= $tg['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-secondary" title="<?= $tg['status'] === 'active' ? 'Inactive કરો' : 'Active કરો' ?>">
                                                                <?= svg_icon($tg['status'] === 'active' ? 'pause' : 'play', '', 13) ?>
                                                            </button>
                                                        </form>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=tags" method="POST" class="d-inline" onsubmit="return confirm('શું તમે આ ટેગ ડિલીટ કરવા માંગો છો?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_tag">
                                                            <input type="hidden" name="id" value="<?= $tg['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-danger" title="ડિલીટ">
                                                                <?= svg_icon('trash-can', '', 13) ?>
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <!-- Edit Modal for Tag -->
                                                    <div class="modal fade text-start" id="editTagModal<?= $tg['id'] ?>" tabindex="-1">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content">
                                                                <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=tags" method="POST">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="edit_tag">
                                                                    <input type="hidden" name="id" value="<?= $tg['id'] ?>">
                                                                    <div class="modal-header">
                                                                        <h6 class="modal-title fw-bold">
                                                                            <?= svg_icon('pen-to-square', 'text-primary me-2', 16) ?>ટેગમાં સુધારો
                                                                        </h6>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">ટેગનું નામ (Tag Name) *</label>
                                                                            <input type="text" name="name" class="form-control fw-bold" value="<?= e($tg['name']) ?>" required>
                                                                        </div>
                                                                        <div class="row g-2 mb-3">
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">શોર્ટ કોડ (Code)</label>
                                                                                <input type="text" name="code" class="form-control font-mono text-uppercase" value="<?= e($tg['code'] ?? '') ?>">
                                                                            </div>
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">કલર બેજ (Badge Color)</label>
                                                                                <?php $currColor = $tg['color'] ?? 'blue'; ?>
                                                                                <select name="color" class="form-select">
                                                                                    <option value="blue" <?= $currColor === 'blue' ? 'selected' : '' ?>>Blue (વાદળી)</option>
                                                                                    <option value="purple" <?= $currColor === 'purple' ? 'selected' : '' ?>>Purple (જાંબલી)</option>
                                                                                    <option value="rose" <?= $currColor === 'rose' || $currColor === 'red' ? 'selected' : '' ?>>Rose / Red (ગુલાબી/લાલ)</option>
                                                                                    <option value="emerald" <?= $currColor === 'emerald' || $currColor === 'green' ? 'selected' : '' ?>>Emerald / Green (લીલો)</option>
                                                                                    <option value="amber" <?= $currColor === 'amber' || $currColor === 'orange' ? 'selected' : '' ?>>Amber / Orange (નારંગી)</option>
                                                                                    <option value="cyan" <?= $currColor === 'cyan' ? 'selected' : '' ?>>Cyan (આકાશી)</option>
                                                                                    <option value="slate" <?= $currColor === 'slate' || $currColor === 'gray' ? 'selected' : '' ?>>Slate / Gray (ગ્રે)</option>
                                                                                </select>
                                                                            </div>
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">વિગત / નોંધ (Description)</label>
                                                                            <input type="text" name="description" class="form-control" value="<?= e($tg['description'] ?? '') ?>">
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">ક્રમ (Sort Order)</label>
                                                                            <input type="number" name="sort_order" class="form-control font-mono" value="<?= (int)($tg['sort_order'] ?? 0) ?>" min="0">
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                                                                        <button type="submit" class="btn btn-primary">
                                                                            <?= svg_icon('floppy-disk', '', 14) ?>સેવ કરો
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <!-- TAB: PROCESS TAGS (Sell, Hold, Assort) -->
    <?php elseif ($activeTab === 'process_tags'): ?>
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="finora-card">
                    <div class="finora-card-header">
                        <h6 class="finora-card-title"><?= svg_icon('circle-plus', 'text-primary', 17) ?>નવો Process Tag ઉમેરો</h6>
                    </div>
                    <div class="p-3">
                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=process_tags" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_process_tag">

                            <div class="mb-3">
                                <label class="form-label">પ્રોસેસ ટેગનું નામ (Process Tag) *</label>
                                <input type="text" name="name" class="form-control fw-bold" placeholder="દા.ત. Sell, Hold, Assort, Polishing" required>
                                <div class="form-text text-muted" style="font-size: 0.75rem;">આ ટેગ રફ માલ કઈ પ્રોસેસ માટે છે (વેચાણ, હોલ્ડ, અસોર્ટ) તે દર્શાવે છે.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">શોર્ટ કોડ (Code)</label>
                                <input type="text" name="code" class="form-control font-mono text-uppercase" placeholder="દા.ત. SELL, HOLD, ASSORT">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">કલર બેજ (Badge Color)</label>
                                <select name="color" class="form-select">
                                    <option value="emerald">Emerald / Green (લીલો - Sell)</option>
                                    <option value="amber">Amber / Orange (નારંગી - Hold)</option>
                                    <option value="blue">Blue / Cyan (વાદળી - Assort)</option>
                                    <option value="purple">Purple (જાંબલી)</option>
                                    <option value="rose">Rose / Red (ગુલાબી)</option>
                                    <option value="slate">Slate / Gray (ગ્રે)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">વિગત / નોંધ (Description)</label>
                                <input type="text" name="description" class="form-control" placeholder="દા.ત. સીધું વેચાણ માટે (Direct Sell)">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ક્રમ (Sort Order)</label>
                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= count($allProcessTags) + 1 ?>" min="0">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <?= svg_icon('plus', '', 15) ?>પ્રોસેસ ટેગ સેવ કરો
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="finora-card">
                    <div class="finora-card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <h6 class="finora-card-title mb-0"><?= svg_icon('bolt', 'text-primary', 17) ?>પ્રોસેસ ટેગ ડિરેક્ટરી (Process Tags Directory)</h6>
                            <span class="badge bg-primary-subtle text-primary border px-2 py-0.5"><?= count($allProcessTags) ?> પ્રોસેસ ટેગ</span>
                        </div>
                        <span class="badge bg-light text-muted border small fw-normal"><i class="small">↕ ડ્રેગ કરીને ક્રમ બદલો</i></span>
                    </div>
                    <div class="p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">ક્રમ</th>
                                        <th style="width: 25%;">પ્રોસેસ ટેગ નામ</th>
                                        <th style="width: 15%;">કોડ</th>
                                        <th style="width: 23%;">વિગત</th>
                                        <th style="width: 12%;">સ્થિતિ</th>
                                        <th style="width: 15%;" class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyProcessTags">
                                    <?php if (empty($allProcessTags)): ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted">કોઈ પ્રોસેસ ટેગ મળ્યો નથી.</td></tr>
                                    <?php else: ?>
                                        <?php $ptgIdx = 1; foreach ($allProcessTags as $ptg): ?>
                                            <tr data-id="<?= $ptg['id'] ?>" class="sortable-row">
                                                <td class="font-mono text-muted text-nowrap">
                                                    <span class="drag-handle me-1" title="ડ્રેગ કરીને ક્રમ બદલો"><?= svg_icon('grip-vertical', '', 13) ?></span>
                                                    <span class="sort-order-badge fw-bold text-dark">#<?= $ptgIdx++ ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <?= render_process_tag_badge($ptg['name']) ?>
                                                    </div>
                                                </td>
                                                <td><span class="badge-code font-mono text-uppercase"><?= e($ptg['code'] ?: '-') ?></span></td>
                                                <td class="small text-muted"><?= e($ptg['description'] ?: '—') ?></td>
                                                <td>
                                                    <span class="finora-pill <?= $ptg['status'] === 'active' ? 'finora-pill-green' : 'finora-pill-slate' ?>">
                                                        <?= $ptg['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-1">
                                                        <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editProcessTagModal<?= $ptg['id'] ?>" title="સુધારો (Edit)">
                                                            <?= svg_icon('pen-to-square', '', 13) ?>
                                                        </button>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=process_tags" method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_process_tag_status">
                                                            <input type="hidden" name="id" value="<?= $ptg['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-secondary" title="<?= $ptg['status'] === 'active' ? 'Inactive કરો' : 'Active કરો' ?>">
                                                                <?= svg_icon($ptg['status'] === 'active' ? 'pause' : 'play', '', 13) ?>
                                                            </button>
                                                        </form>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=process_tags" method="POST" class="d-inline" onsubmit="return confirm('શું તમે આ પ્રોસેસ ટેગ ડિલીટ કરવા માંગો છો?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_process_tag">
                                                            <input type="hidden" name="id" value="<?= $ptg['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-danger" title="ડિલીટ">
                                                                <?= svg_icon('trash-can', '', 13) ?>
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <!-- Edit Modal for Process Tag -->
                                                    <div class="modal fade text-start" id="editProcessTagModal<?= $ptg['id'] ?>" tabindex="-1">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content">
                                                                <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=process_tags" method="POST">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="edit_process_tag">
                                                                    <input type="hidden" name="id" value="<?= $ptg['id'] ?>">
                                                                    <div class="modal-header">
                                                                        <h6 class="modal-title fw-bold">
                                                                            <?= svg_icon('pen-to-square', 'text-primary me-2', 16) ?>પ્રોસેસ ટેગમાં સુધારો
                                                                        </h6>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">પ્રોસેસ ટેગનું નામ *</label>
                                                                            <input type="text" name="name" class="form-control fw-bold" value="<?= e($ptg['name']) ?>" required>
                                                                        </div>
                                                                        <div class="row g-2 mb-3">
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">શોર્ટ કોડ (Code)</label>
                                                                                <input type="text" name="code" class="form-control font-mono text-uppercase" value="<?= e($ptg['code'] ?? '') ?>">
                                                                            </div>
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">કલર બેજ (Badge Color)</label>
                                                                                <?php $currPtColor = $ptg['color'] ?? 'emerald'; ?>
                                                                                <select name="color" class="form-select">
                                                                                    <option value="emerald" <?= ($currPtColor === 'emerald' || $currPtColor === 'green') ? 'selected' : '' ?>>Emerald / Green (લીલો - Sell)</option>
                                                                                    <option value="amber" <?= ($currPtColor === 'amber' || $currPtColor === 'orange') ? 'selected' : '' ?>>Amber / Orange (નારંગી - Hold)</option>
                                                                                    <option value="blue" <?= ($currPtColor === 'blue' || $currPtColor === 'cyan') ? 'selected' : '' ?>>Blue / Cyan (વાદળી - Assort)</option>
                                                                                    <option value="purple" <?= $currPtColor === 'purple' ? 'selected' : '' ?>>Purple (જાંબલી)</option>
                                                                                    <option value="rose" <?= ($currPtColor === 'rose' || $currPtColor === 'red') ? 'selected' : '' ?>>Rose / Red (ગુલાબી)</option>
                                                                                    <option value="slate" <?= ($currPtColor === 'slate' || $currPtColor === 'gray') ? 'selected' : '' ?>>Slate / Gray (ગ્રે)</option>
                                                                                </select>
                                                                            </div>
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">વિગત / નોંધ (Description)</label>
                                                                            <input type="text" name="description" class="form-control" value="<?= e($ptg['description'] ?? '') ?>">
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">ક્રમ (Sort Order)</label>
                                                                            <input type="number" name="sort_order" class="form-control font-mono" value="<?= (int)($ptg['sort_order'] ?? 0) ?>" min="0">
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                                                                        <button type="submit" class="btn btn-primary">
                                                                            <?= svg_icon('floppy-disk', '', 14) ?>સેવ કરો
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($activeTab === 'polish_gia'): 
        $giaTypes = get_gia_attribute_types();
        $subGiaTab = $_GET['sub'] ?? 'shape';
        if (!isset($giaTypes[$subGiaTab])) {
            $subGiaTab = 'shape';
        }
        $currTypeInfo = $giaTypes[$subGiaTab];
        $giaItemsStmt = $pdo->prepare("SELECT * FROM master_gia_attributes WHERE attribute_type = ? ORDER BY sort_order ASC, id ASC");
        $giaItemsStmt->execute([$subGiaTab]);
        $itemsList = $giaItemsStmt->fetchAll();
    ?>
        <!-- Sub-tabs navigation pills for 7 GIA attributes -->
        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center p-2 bg-light rounded-3 border">
            <?php foreach ($giaTypes as $k => $gt): ?>
                <a href="?tab=polish_gia&sub=<?= $k ?>" class="btn btn-sm <?= $subGiaTab === $k ? 'btn-primary shadow-xs' : 'btn-outline-secondary' ?> d-flex align-items-center gap-1.5 fw-semibold">
                    <?= svg_icon($gt['icon'] ?? 'gem', '', 14) ?>
                    <span><?= $gt['title_gu'] ?></span>
                    <span class="badge <?= $subGiaTab === $k ? 'bg-white text-primary' : 'bg-secondary text-white' ?> rounded-pill font-mono" style="font-size: 0.70rem;">
                        <?php
                            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM master_gia_attributes WHERE attribute_type = ?");
                            $cntStmt->execute([$k]);
                            echo $cntStmt->fetchColumn();
                        ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="finora-card">
                    <div class="finora-card-header">
                        <h6 class="finora-card-title"><?= svg_icon('circle-plus', 'text-primary', 17) ?>નવું <?= e($currTypeInfo['title']) ?> ઉમેરો</h6>
                    </div>
                    <div class="p-3">
                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=polish_gia&sub=<?= urlencode($subGiaTab) ?>" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_gia_attribute">
                            <input type="hidden" name="attribute_type" value="<?= e($subGiaTab) ?>">

                            <div class="mb-3">
                                <label class="form-label">કોડ (Code / Abbreviation) *</label>
                                <input type="text" name="code" class="form-control font-mono fw-bold text-uppercase" placeholder="<?= e($currTypeInfo['placeholder_code']) ?>" required>
                                <div class="form-text small">દા.ત. શોર્ટ કોડ જે રિપોર્ટ અને બિલમાં વપરાશે.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">નામ / વિગત (Name / Full Label) *</label>
                                <input type="text" name="name" class="form-control fw-bold" placeholder="<?= e($currTypeInfo['placeholder_name']) ?>" required>
                                <div class="form-text small">દા.ત. ડ્રોપડાઉનમાં દેખાતું પૂરું નામ.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ક્રમ (Sort Order)</label>
                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= count($itemsList) + 1 ?>" min="0">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <?= svg_icon('plus', '', 15) ?><?= e($currTypeInfo['title']) ?> ઉમેરો
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="finora-card">
                    <div class="finora-card-header d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <h6 class="finora-card-title mb-0"><?= svg_icon($currTypeInfo['icon'] ?? 'gem', 'text-primary', 17) ?><?= e($currTypeInfo['title_gu']) ?> યાદી (<?= count($itemsList) ?>)</h6>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono">GIA Master</span>
                        </div>
                        <span class="badge bg-light text-muted border small fw-normal"><i class="small">↕ ડ્રેગ કરીને ક્રમ બદલો</i></span>
                    </div>
                    <div class="p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">ક્રમ</th>
                                        <th style="width: 25%;">કોડ</th>
                                        <th style="width: 40%;">નામ / વિગત</th>
                                        <th style="width: 12%;">સ્થિતિ</th>
                                        <th style="width: 13%;" class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyGiaAttributes">
                                    <?php if (empty($itemsList)): ?>
                                        <tr><td colspan="5" class="text-center py-4 text-muted">કોઈ રેકોર્ડ મળ્યો નથી. નવો રેકોર્ડ ઉમેરો.</td></tr>
                                    <?php else: ?>
                                        <?php $itIdx = 1; foreach ($itemsList as $it): ?>
                                            <tr data-id="<?= $it['id'] ?>" class="sortable-row">
                                                <td class="font-mono text-muted text-nowrap">
                                                    <span class="drag-handle me-1" title="ડ્રેગ કરીને ક્રમ બદલો"><?= svg_icon('grip-vertical', '', 13) ?></span>
                                                    <span class="sort-order-badge fw-bold text-dark">#<?= $itIdx++ ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge-code font-mono fw-bold text-uppercase"><?= e($it['code']) ?></span>
                                                </td>
                                                <td>
                                                    <strong class="text-dark"><?= e($it['name']) ?></strong>
                                                </td>
                                                <td>
                                                    <span class="finora-pill <?= $it['status'] === 'active' ? 'finora-pill-green' : 'finora-pill-slate' ?>">
                                                        <?= $it['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-1">
                                                        <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editGiaModal<?= $it['id'] ?>" title="સુધારો (Edit)">
                                                            <?= svg_icon('pen-to-square', '', 13) ?>
                                                        </button>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=polish_gia&sub=<?= urlencode($subGiaTab) ?>" method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_gia_attribute_status">
                                                            <input type="hidden" name="attribute_type" value="<?= e($subGiaTab) ?>">
                                                            <input type="hidden" name="id" value="<?= $it['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-secondary" title="સ્થિતિ બદલો">
                                                                <?= svg_icon($it['status'] === 'active' ? 'pause' : 'play', '', 13) ?>
                                                            </button>
                                                        </form>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=polish_gia&sub=<?= urlencode($subGiaTab) ?>" method="POST" class="d-inline" onsubmit="return confirm('શું તમે ખરેખર \'<?= addslashes(e($it['name'])) ?>\' ડિલીટ કરવા માંગો છો?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_gia_attribute">
                                                            <input type="hidden" name="attribute_type" value="<?= e($subGiaTab) ?>">
                                                            <input type="hidden" name="id" value="<?= $it['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-danger" title="ડિલીટ કરો">
                                                                <?= svg_icon('trash', '', 13) ?>
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <!-- Edit Modal -->
                                                    <div class="modal fade text-start" id="editGiaModal<?= $it['id'] ?>" tabindex="-1" aria-hidden="true">
                                                        <div class="modal-dialog">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h5 class="modal-title fs-6 fw-bold"><?= svg_icon('pen-to-square', 'text-primary me-1', 16) ?><?= e($currTypeInfo['title']) ?> સુધારો</h5>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                </div>
                                                                <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=polish_gia&sub=<?= urlencode($subGiaTab) ?>" method="POST">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="edit_gia_attribute">
                                                                    <input type="hidden" name="attribute_type" value="<?= e($subGiaTab) ?>">
                                                                    <input type="hidden" name="id" value="<?= $it['id'] ?>">
                                                                    <div class="modal-body">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">કોડ (Code) *</label>
                                                                            <input type="text" name="code" class="form-control font-mono fw-bold text-uppercase" value="<?= e($it['code']) ?>" required>
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label">નામ / વિગત (Name / Full Label) *</label>
                                                                            <input type="text" name="name" class="form-control fw-bold" value="<?= e($it['name']) ?>" required>
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label">ક્રમ (Sort Order)</label>
                                                                            <input type="number" name="sort_order" class="form-control font-mono" value="<?= (int)$it['sort_order'] ?>" min="0">
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                                                                        <button type="submit" class="btn btn-primary">
                                                                            <?= svg_icon('floppy-disk', '', 14) ?>સેવ કરો
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php elseif ($activeTab === 'gia_colors' || $activeTab === 'polish_colors'): 
        $colorCategory = ($activeTab === 'gia_colors') ? 'gia' : 'polish';
        $isGia = ($colorCategory === 'gia');
        $catTitle = $isGia ? 'GIA કલર માસ્ટર' : 'પોલિશ પાર્સલ કલર માસ્ટર';
        $catBadgeIcon = $isGia ? 'gem' : 'sparkles';
        $catDesc = $isGia 
            ? 'GIA પ્રમાણિત સિંગલ સ્ટોન માટેના કલર ટેગ્સ (Pink, Yellow, Blue, Green, White વગેરે) અને તે દરેક ટેગ હેઠળના વિવિધ કલર ડિસ્ક્રિપ્શન્સ (દા.ત. Fancy Pink, Fancy Light Pink, Fancy Intense Pink, Y-Z, W-X વગેરે).'
            : 'પોલિશ પાર્સલ માટેના કલર ટેગ્સ (White, LB, LC, TTL, Fancy Pink વગેરે) અને તેમના હેઠળના ચોક્કસ કલર ડિસ્ક્રિપ્શન્સ.';
        $colorTags = get_master_color_tags($colorCategory, false);
        $selectedTagId = (int)($_GET['tag_id'] ?? 0);
        if ($selectedTagId <= 0 && !empty($colorTags)) {
            $selectedTagId = (int)$colorTags[0]['id'];
        }
        $selectedTag = null;
        foreach ($colorTags as $ct) {
            if ((int)$ct['id'] === $selectedTagId) {
                $selectedTag = $ct;
                break;
            }
        }
        $descriptions = ($selectedTagId > 0) ? get_master_color_descriptions($selectedTagId, $colorCategory, false) : [];
    ?>
        <!-- Header Info Banner -->
        <div class="alert bg-white border border-secondary border-opacity-25 rounded-3 p-3 mb-3 shadow-2xs d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <span class="avatar avatar-md rounded-circle <?= $isGia ? 'bg-primary-subtle text-primary' : 'bg-success-subtle text-success' ?>">
                    <?= svg_icon($catBadgeIcon, '', 20) ?>
                </span>
                <div>
                    <h6 class="mb-0 fw-bold fs-6 text-dark d-flex align-items-center gap-2">
                        <?= e($catTitle) ?>
                        <span class="badge bg-secondary-subtle text-secondary rounded-pill font-mono fs-8">
                            <?= count($colorTags) ?> કલર ટેગ્સ
                        </span>
                    </h6>
                    <small class="text-muted d-block mt-0.5"><?= e($catDesc) ?></small>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="?tab=gia_colors" class="btn btn-sm <?= $isGia ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= svg_icon('gem', '', 14) ?> GIA કલર્સ
                </a>
                <a href="?tab=polish_colors" class="btn btn-sm <?= !$isGia ? 'btn-success' : 'btn-outline-secondary' ?>">
                    <?= svg_icon('sparkles', '', 14) ?> પાર્સલ પોલિશ કલર્સ
                </a>
            </div>
        </div>

        <div class="row g-3">
            <!-- LEFT COLUMN: Color Tags List & Add Form -->
            <div class="col-12 col-lg-5 col-xl-4">
                <!-- Add New Tag Card -->
                <div class="finora-card mb-3">
                    <div class="finora-card-header d-flex justify-content-between align-items-center py-2.5">
                        <h6 class="finora-card-title mb-0 fs-7">
                            <?= svg_icon('circle-plus', 'text-primary', 15) ?> નવો કલર ટેગ ઉમેરો
                        </h6>
                    </div>
                    <div class="p-3">
                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=<?= $activeTab ?>" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_color_tag">
                            <input type="hidden" name="category" value="<?= $colorCategory ?>">

                            <div class="mb-2.5">
                                <label class="form-label small fw-bold mb-1">કલર ટેગનું નામ *</label>
                                <input type="text" name="tag_name" class="form-control form-control-sm fw-bold" placeholder="<?= $isGia ? 'દા.ત. Pink, Yellow, White' : 'દા.ત. White, LB, Fancy Pink' ?>" required>
                            </div>

                            <div class="row g-2 mb-2.5">
                                <div class="col-6">
                                    <label class="form-label small fw-bold mb-1">કોડ (Code)</label>
                                    <input type="text" name="tag_code" class="form-control form-control-sm font-mono text-uppercase" placeholder="દા.ત. PNK, YEL">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold mb-1">કલર બેજ (Hex)</label>
                                    <div class="input-group input-group-sm">
                                        <input type="color" class="form-control form-control-color p-0 border-0" id="addTagColorPicker" value="#ec4899" title="Choose color" style="width: 32px; height: 31px; cursor: pointer;" oninput="document.getElementById('addTagColorHex').value = this.value;">
                                        <input type="text" name="color_hex" id="addTagColorHex" class="form-control form-control-sm font-mono text-uppercase" value="#ec4899" placeholder="#ec4899" oninput="document.getElementById('addTagColorPicker').value = this.value;">
                                    </div>
                                </div>
                            </div>

                            <div class="row g-2 mb-2.5">
                                <div class="col-8">
                                    <label class="form-label small fw-bold mb-1">વિગત / નોંધ (ઓપ્શનલ)</label>
                                    <input type="text" name="description" class="form-control form-control-sm" placeholder="દા.ત. Fancy Natural Color">
                                </div>
                                <div class="col-4">
                                    <label class="form-label small fw-bold mb-1">ક્રમ (Sort)</label>
                                    <input type="number" name="sort_order" class="form-control form-control-sm font-mono" value="0" min="0">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">
                                <?= svg_icon('plus', '', 14) ?> કલર ટેગ સેવ કરો
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Existing Color Tags Navigation Card -->
                <div class="finora-card">
                    <div class="finora-card-header d-flex justify-content-between align-items-center py-2.5 bg-light">
                        <h6 class="finora-card-title mb-0 fs-7">
                            <?= svg_icon('palette', 'text-primary', 15) ?> કલર ટેગ્સ (<?= count($colorTags) ?>)
                        </h6>
                        <small class="text-muted fs-8">ક્લિક કરીને સિલેક્ટ કરો</small>
                    </div>
                    <div class="p-2">
                        <?php if (empty($colorTags)): ?>
                            <div class="text-center py-4 text-muted">
                                <?= svg_icon('palette', 'text-muted mb-2', 28) ?>
                                <p class="small mb-0">હજુ કોઈ કલર ટેગ ઉમેરેલ નથી.</p>
                            </div>
                        <?php else: ?>
                            <div id="listColorTags" class="d-flex flex-column gap-1.5" style="max-height: 520px; overflow-y: auto;">
                                <?php foreach ($colorTags as $tag): 
                                    $isSelected = ($selectedTagId === (int)$tag['id']);
                                    $tagHex = !empty($tag['color_hex']) ? $tag['color_hex'] : '#94a3b8';
                                ?>
                                    <div data-id="<?= $tag['id'] ?>" class="color-tag-item p-2 rounded-2 border transition-all d-flex align-items-center justify-content-between gap-2 <?= $isSelected ? 'border-primary bg-primary bg-opacity-10 shadow-xs' : 'border-light-subtle bg-white hover-bg-light' ?>" style="cursor: pointer;" onclick="if (!event.target.closest('button, form, .modal')) { window.location.href='?tab=<?= $activeTab ?>&tag_id=<?= $tag['id'] ?>'; }">
                                        <div class="d-flex align-items-center gap-2 overflow-hidden flex-grow-1">
                                            <span class="drag-handle me-0.5" title="ડ્રેગ કરીને ક્રમ બદલો" onclick="event.stopPropagation();"><?= svg_icon('grip-vertical', '', 13) ?></span>
                                            <span class="rounded-circle d-inline-block shadow-2xs flex-shrink-0" style="width: 18px; height: 18px; background-color: <?= e($tagHex) ?>; border: 1.5px solid rgba(0,0,0,0.15);"></span>
                                            <div class="truncate">
                                                <div class="fw-bold fs-7 <?= $isSelected ? 'text-primary' : 'text-dark' ?> truncate">
                                                    <?= e($tag['tag_name']) ?>
                                                    <?php if (!empty($tag['tag_code'])): ?>
                                                        <span class="badge bg-secondary-subtle text-secondary font-mono fs-9 py-0.5"><?= e($tag['tag_code']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-muted fs-8 d-flex align-items-center gap-1.5">
                                                    <span class="badge bg-primary-subtle text-primary py-0 px-1 font-mono fs-9">
                                                        <?= (int)$tag['description_count'] ?> ડિસ્ક્રિપ્શન
                                                    </span>
                                                    <?php if ($tag['status'] === 'inactive'): ?>
                                                        <span class="badge bg-danger-subtle text-danger py-0 px-1 fs-9">નિષ્ક્રિય</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="d-flex align-items-center gap-1 flex-shrink-0" onclick="event.stopPropagation();">
                                            <!-- Toggle Status Form -->
                                            <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=<?= $activeTab ?>&tag_id=<?= $tag['id'] ?>" method="POST" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_color_tag_status">
                                                <input type="hidden" name="id" value="<?= $tag['id'] ?>">
                                                <input type="hidden" name="category" value="<?= $colorCategory ?>">
                                                <button type="submit" class="btn btn-sm btn-light p-1 text-<?= $tag['status'] === 'active' ? 'success' : 'secondary' ?>" title="<?= $tag['status'] === 'active' ? 'નિષ્ક્રિય કરો' : 'સક્રિય કરો' ?>">
                                                    <?= svg_icon($tag['status'] === 'active' ? 'toggle-on' : 'toggle-off', '', 14) ?>
                                                </button>
                                            </form>

                                            <!-- Edit Tag Button -->
                                            <button type="button" class="btn btn-sm btn-light p-1 text-primary" data-bs-toggle="modal" data-bs-target="#editTagModal<?= $tag['id'] ?>" title="ટેગ સુધારો">
                                                <?= svg_icon('pen-to-square', '', 13) ?>
                                            </button>

                                            <!-- Delete Tag Form -->
                                            <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=<?= $activeTab ?>" method="POST" class="d-inline" onsubmit="return confirm('શું તમે ખરેખર કલર ટેગ \'<?= addslashes($tag['tag_name']) ?>\' અને તેના તમામ ડિસ્ક્રિપ્શન ડિલીટ કરવા માંગો છો?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_color_tag">
                                                <input type="hidden" name="id" value="<?= $tag['id'] ?>">
                                                <input type="hidden" name="category" value="<?= $colorCategory ?>">
                                                <button type="submit" class="btn btn-sm btn-light p-1 text-danger" title="ટેગ ડિલીટ કરો">
                                                    <?= svg_icon('trash', '', 13) ?>
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                    <!-- Edit Tag Modal -->
                                    <div class="modal fade text-start" id="editTagModal<?= $tag['id'] ?>" tabindex="-1" aria-hidden="true" onclick="event.stopPropagation();">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h6 class="modal-title fw-bold">
                                                        <?= svg_icon('pen-to-square', 'text-primary me-1', 15) ?> કલર ટેગ સુધારો
                                                    </h6>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=<?= $activeTab ?>&tag_id=<?= $tag['id'] ?>" method="POST">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="edit_color_tag">
                                                    <input type="hidden" name="id" value="<?= $tag['id'] ?>">
                                                    <input type="hidden" name="category" value="<?= $colorCategory ?>">
                                                    <div class="modal-body">
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-bold">કલર ટેગનું નામ *</label>
                                                            <input type="text" name="tag_name" class="form-control fw-bold" value="<?= e($tag['tag_name']) ?>" required>
                                                        </div>
                                                        <div class="row g-2 mb-3">
                                                            <div class="col-6">
                                                                <label class="form-label small fw-bold">કોડ (Code)</label>
                                                                <input type="text" name="tag_code" class="form-control font-mono text-uppercase" value="<?= e($tag['tag_code'] ?? '') ?>">
                                                            </div>
                                                            <div class="col-6">
                                                                <label class="form-label small fw-bold">કલર બેજ (Hex)</label>
                                                                <div class="input-group">
                                                                    <input type="color" class="form-control form-control-color p-0 border-0" id="editTagColorPicker<?= $tag['id'] ?>" value="<?= e($tagHex) ?>" style="width: 38px; height: 38px; cursor: pointer;" oninput="document.getElementById('editTagColorHex<?= $tag['id'] ?>').value = this.value;">
                                                                    <input type="text" name="color_hex" id="editTagColorHex<?= $tag['id'] ?>" class="form-control font-mono text-uppercase" value="<?= e($tagHex) ?>" oninput="document.getElementById('editTagColorPicker<?= $tag['id'] ?>').value = this.value;">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="row g-2 mb-3">
                                                            <div class="col-8">
                                                                <label class="form-label small fw-bold">વિગત / નોંધ</label>
                                                                <input type="text" name="description" class="form-control" value="<?= e($tag['description'] ?? '') ?>">
                                                            </div>
                                                            <div class="col-4">
                                                                <label class="form-label small fw-bold">ક્રમ (Sort)</label>
                                                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= (int)$tag['sort_order'] ?>" min="0">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer py-2">
                                                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                                                        <button type="submit" class="btn btn-sm btn-primary fw-bold">
                                                            <?= svg_icon('floppy-disk', '', 14) ?> ફેરફાર સેવ કરો
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: Color Descriptions for Selected Tag -->
            <div class="col-12 col-lg-7 col-xl-8">
                <?php if ($selectedTag): 
                    $selHex = !empty($selectedTag['color_hex']) ? $selectedTag['color_hex'] : '#94a3b8';
                ?>
                    <!-- Selected Tag Banner & Add Description Form Card -->
                    <div class="finora-card mb-3">
                        <div class="finora-card-header d-flex justify-content-between align-items-center py-2.5 bg-light border-bottom">
                            <div class="d-flex align-items-center gap-2">
                                <span class="rounded-circle d-inline-block shadow-2xs" style="width: 20px; height: 20px; background-color: <?= e($selHex) ?>; border: 1.5px solid rgba(0,0,0,0.2);"></span>
                                <h6 class="finora-card-title mb-0 fs-6 text-dark fw-bold">
                                    <span class="text-primary"><?= e($selectedTag['tag_name']) ?></span> કલર ટેગના ડિસ્ક્રિપ્શન
                                </h6>
                                <?php if (!empty($selectedTag['tag_code'])): ?>
                                    <span class="badge bg-secondary-subtle text-secondary font-mono fs-8"><?= e($selectedTag['tag_code']) ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-primary text-white font-mono rounded-pill px-2.5 py-1">
                                <?= count($descriptions) ?> વિગતો (Descriptions)
                            </span>
                        </div>

                        <!-- Inline Add Color Description Form -->
                        <div class="p-3 bg-light-subtle border-bottom">
                            <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=<?= $activeTab ?>&tag_id=<?= $selectedTag['id'] ?>" method="POST" class="row g-2 align-items-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="add_color_description">
                                <input type="hidden" name="category" value="<?= $colorCategory ?>">
                                <input type="hidden" name="color_tag_id" value="<?= $selectedTag['id'] ?>">

                                <div class="col-12 col-md-5">
                                    <label class="form-label small fw-bold mb-1">નવું કલર ડિસ્ક્રિપ્શન (Color Description) *</label>
                                    <input type="text" name="description_name" class="form-control form-control-sm fw-bold" placeholder="<?= $isGia ? 'દા.ત. Fancy Light ' . e($selectedTag['tag_name']) : 'દા.ત. Top ' . e($selectedTag['tag_name']) ?>" required>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-bold mb-1">શોર્ટ કોડ (Code)</label>
                                    <input type="text" name="description_code" class="form-control form-control-sm font-mono text-uppercase" placeholder="દા.ત. FLP, FIP">
                                </div>
                                <div class="col-6 col-md-2">
                                    <label class="form-label small fw-bold mb-1">ક્રમ (Sort)</label>
                                    <input type="number" name="sort_order" class="form-control form-control-sm font-mono" value="0" min="0">
                                </div>
                                <div class="col-12 col-md-2">
                                    <button type="submit" class="btn btn-sm btn-primary w-100 fw-bold">
                                        <?= svg_icon('plus', '', 14) ?> ઉમેરો
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Descriptions Table -->
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 finora-table">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 70px;" class="text-center font-mono">#</th>
                                        <th>કલર ડિસ્ક્રિપ્શન નામ (Description)</th>
                                        <th style="width: 120px;" class="font-mono">કોડ (Code)</th>
                                        <th style="width: 80px;" class="text-center font-mono">ક્રમ</th>
                                        <th style="width: 90px;" class="text-center">સ્થિતિ</th>
                                        <th style="width: 100px;" class="text-end">એક્શન્સ</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyColorDescriptions">
                                    <?php if (empty($descriptions)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">
                                                <?= svg_icon('palette', 'text-muted mb-2', 26) ?>
                                                <p class="small mb-0">આ કલર ટેગ હેઠળ હજુ કોઈ ડિસ્ક્રિપ્શન ઉમેરેલ નથી. ઉપરના ફોર્મમાંથી નવું ડિસ્ક્રિપ્શન ઉમેરો.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php $dIdx = 1; foreach ($descriptions as $desc): ?>
                                            <tr data-id="<?= $desc['id'] ?>" class="sortable-row">
                                                <td class="text-center font-mono text-muted fs-8 text-nowrap">
                                                    <span class="drag-handle me-1" title="ડ્રેગ કરીને ક્રમ બદલો"><?= svg_icon('grip-vertical', '', 12) ?></span>
                                                    <span class="sort-order-badge">#<?= $dIdx++ ?></span>
                                                </td>
                                                <td>
                                                    <span class="fw-bold text-dark fs-7"><?= e($desc['description_name']) ?></span>
                                                </td>
                                                <td class="font-mono">
                                                    <?php if (!empty($desc['description_code'])): ?>
                                                        <span class="badge bg-secondary-subtle text-secondary font-mono fs-8"><?= e($desc['description_code']) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted fs-8">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center font-mono text-muted fs-8"><?= (int)$desc['sort_order'] ?></td>
                                                <td class="text-center">
                                                    <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=<?= $activeTab ?>&tag_id=<?= $selectedTag['id'] ?>" method="POST" class="d-inline">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="toggle_color_description_status">
                                                        <input type="hidden" name="id" value="<?= $desc['id'] ?>">
                                                        <input type="hidden" name="category" value="<?= $colorCategory ?>">
                                                        <input type="hidden" name="color_tag_id" value="<?= $selectedTag['id'] ?>">
                                                        <button type="submit" class="btn btn-sm p-0 border-0" title="<?= $desc['status'] === 'active' ? 'નિષ્ક્રિય કરો' : 'સક્રિય કરો' ?>">
                                                            <span class="badge <?= $desc['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?> rounded-pill" style="cursor: pointer;">
                                                                <?= $desc['status'] === 'active' ? 'સક્રિય' : 'નિષ્ક્રિય' ?>
                                                            </span>
                                                        </button>
                                                    </form>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-inline-flex gap-1">
                                                        <!-- Edit Description Modal Trigger -->
                                                        <button type="button" class="btn btn-sm btn-light p-1 text-primary" data-bs-toggle="modal" data-bs-target="#editDescModal<?= $desc['id'] ?>" title="ડિસ્ક્રિપ્શન સુધારો">
                                                            <?= svg_icon('pen-to-square', '', 14) ?>
                                                        </button>

                                                        <!-- Delete Description Form -->
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=<?= $activeTab ?>&tag_id=<?= $selectedTag['id'] ?>" method="POST" class="d-inline" onsubmit="return confirm('શું તમે ખરેખર ડિસ્ક્રિપ્શન \'<?= addslashes($desc['description_name']) ?>\' ડિલીટ કરવા માંગો છો?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_color_description">
                                                            <input type="hidden" name="id" value="<?= $desc['id'] ?>">
                                                            <input type="hidden" name="category" value="<?= $colorCategory ?>">
                                                            <input type="hidden" name="color_tag_id" value="<?= $selectedTag['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-light p-1 text-danger" title="ડિસ્ક્રિપ્શન ડિલીટ કરો">
                                                                <?= svg_icon('trash', '', 14) ?>
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <!-- Edit Description Modal -->
                                                    <div class="modal fade text-start" id="editDescModal<?= $desc['id'] ?>" tabindex="-1" aria-hidden="true">
                                                        <div class="modal-dialog modal-dialog-centered">
                                                            <div class="modal-content">
                                                                <div class="modal-header">
                                                                    <h6 class="modal-title fw-bold">
                                                                        <?= svg_icon('pen-to-square', 'text-primary me-1', 15) ?> કલર ડિસ્ક્રિપ્શન સુધારો
                                                                    </h6>
                                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                                </div>
                                                                <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=<?= $activeTab ?>&tag_id=<?= $selectedTag['id'] ?>" method="POST">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="edit_color_description">
                                                                    <input type="hidden" name="id" value="<?= $desc['id'] ?>">
                                                                    <input type="hidden" name="category" value="<?= $colorCategory ?>">
                                                                    <input type="hidden" name="color_tag_id" value="<?= $selectedTag['id'] ?>">
                                                                    <div class="modal-body">
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">કલર ટેગ (Parent Tag)</label>
                                                                            <input type="text" class="form-control form-control-sm bg-light" value="<?= e($selectedTag['tag_name']) ?>" disabled>
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label small fw-bold">કલર ડિસ્ક્રિપ્શન નામ *</label>
                                                                            <input type="text" name="description_name" class="form-control fw-bold" value="<?= e($desc['description_name']) ?>" required>
                                                                        </div>
                                                                        <div class="row g-2 mb-3">
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">શોર્ટ કોડ (Code)</label>
                                                                                <input type="text" name="description_code" class="form-control font-mono text-uppercase" value="<?= e($desc['description_code'] ?? '') ?>">
                                                                            </div>
                                                                            <div class="col-6">
                                                                                <label class="form-label small fw-bold">ક્રમ (Sort Order)</label>
                                                                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= (int)$desc['sort_order'] ?>" min="0">
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer py-2">
                                                                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                                                                        <button type="submit" class="btn btn-sm btn-primary fw-bold">
                                                                            <?= svg_icon('floppy-disk', '', 14) ?> ફેરફાર સેવ કરો
                                                                        </button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="finora-card p-5 text-center text-muted">
                        <?= svg_icon('palette', 'text-muted mb-3', 42) ?>
                        <h5>ડાબી બાજુથી કલર ટેગ સિલેક્ટ કરો</h5>
                        <p class="small text-muted mb-0">કોઈપણ કલર ટેગ પર ક્લિક કરવાથી તેના હેઠળના તમામ કલર ડિસ્ક્રિપ્શન અહીં દેખાશે અને નવા ડિસ્ક્રિપ્શન ઉમેરી શકાશે.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <!-- TAB: POLISH PARCEL CLARITIES MASTER -->
    <?php elseif ($activeTab === 'parcel_clarities'): ?>
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="finora-card">
                    <div class="finora-card-header">
                        <h6 class="finora-card-title"><?= svg_icon('circle-plus', 'text-primary', 17) ?>નવી પાર્સલ ક્લેરિટી ઉમેરો</h6>
                    </div>
                    <div class="p-3">
                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=parcel_clarities" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_parcel_clarity">

                            <div class="mb-3">
                                <label class="form-label">ક્લેરિટી નામ *</label>
                                <input type="text" name="name" class="form-control" placeholder="Clean, Eye Clean, VS, SI, Pique..." required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">કોડ (Code)</label>
                                <input type="text" name="code" class="form-control font-mono text-uppercase" placeholder="CLN, ECLN, VS, SI...">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">વિગત / નોંધ (Description)</label>
                                <input type="text" name="description" class="form-control" placeholder="દા.ત. Eye Clean / સાફ માલ">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">ક્રમ (Sort Order)</label>
                                <input type="number" name="sort_order" class="form-control font-mono" value="<?= count($parcelClarities) + 1 ?>" min="0">
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <?= svg_icon('plus', '', 15) ?>ક્લેરિટી ઉમેરો
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="finora-card">
                    <div class="finora-card-header d-flex justify-content-between align-items-center">
                        <h6 class="finora-card-title mb-0"><?= svg_icon('certificate', 'text-primary', 17) ?>પોલિશ પાર્સલ ક્લેરિટી યાદી (Polish Parcel Clarities)</h6>
                        <span class="badge bg-light text-muted border small fw-normal"><i class="small">↕ ડ્રેગ કરીને ક્રમ બદલો</i></span>
                    </div>
                    <div class="p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 10%;">ક્રમ</th>
                                        <th style="width: 28%;">ક્લેરિટી નામ</th>
                                        <th style="width: 16%;">કોડ</th>
                                        <th style="width: 24%;">વિગત</th>
                                        <th style="width: 11%;">સ્થિતિ</th>
                                        <th style="width: 11%;" class="text-end">એક્શન</th>
                                    </tr>
                                </thead>
                                <tbody id="tbodyParcelClarities">
                                    <?php if (empty($parcelClarities)): ?>
                                        <tr><td colspan="6" class="text-center py-4 text-muted">કોઈ ક્લેરિટી મળેલ નથી.</td></tr>
                                    <?php else: ?>
                                        <?php $pcIdx = 1; foreach ($parcelClarities as $pc): ?>
                                            <tr data-id="<?= $pc['id'] ?>" class="sortable-row">
                                                <td class="font-mono text-muted text-nowrap">
                                                    <span class="drag-handle me-1" title="ડ્રેગ કરીને ક્રમ બદલો"><?= svg_icon('grip-vertical', '', 13) ?></span>
                                                    <span class="sort-order-badge fw-bold text-dark">#<?= $pcIdx++ ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-circle p-1.5">
                                                            <?= svg_icon('certificate', '', 13) ?>
                                                        </span>
                                                        <strong class="text-dark"><?= e($pc['name']) ?></strong>
                                                    </div>
                                                </td>
                                                <td><span class="badge-code font-mono text-uppercase"><?= e($pc['code'] ?: '-') ?></span></td>
                                                <td class="small text-muted"><?= e($pc['description'] ?: '—') ?></td>
                                                <td>
                                                    <span class="finora-pill <?= $pc['status'] === 'active' ? 'finora-pill-green' : 'finora-pill-slate' ?>">
                                                        <?= $pc['status'] === 'active' ? 'Active' : 'Inactive' ?>
                                                    </span>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex justify-content-end gap-1">
                                                        <button type="button" class="btn btn-xs btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editClarityModal<?= $pc['id'] ?>" title="સુધારો (Edit)">
                                                            <?= svg_icon('pen-to-square', '', 13) ?>
                                                        </button>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=parcel_clarities" method="POST" class="d-inline">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="toggle_parcel_clarity_status">
                                                            <input type="hidden" name="id" value="<?= $pc['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-secondary" title="સ્થિતિ બદલો">
                                                                <?= svg_icon($pc['status'] === 'active' ? 'pause' : 'play', '', 13) ?>
                                                            </button>
                                                        </form>
                                                        <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=parcel_clarities" method="POST" class="d-inline" onsubmit="return confirm('શું તમે આ ક્લેરિટી ડિલીટ કરવા માંગો છો?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete_parcel_clarity">
                                                            <input type="hidden" name="id" value="<?= $pc['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-danger" title="ડિલીટ">
                                                                <?= svg_icon('trash-can', '', 13) ?>
                                                            </button>
                                                        </form>
                                                    </div>

                                                    <!-- Edit Modal -->
                                                    <div class="modal fade" id="editClarityModal<?= $pc['id'] ?>" tabindex="-1" aria-hidden="true">
                                                        <div class="modal-dialog modal-dialog-centered text-start">
                                                            <div class="modal-content">
                                                                <form action="<?= BASE_URL ?>/modules/admin/settings.php?tab=parcel_clarities" method="POST">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="edit_parcel_clarity">
                                                                    <input type="hidden" name="id" value="<?= $pc['id'] ?>">
                                                                    <div class="modal-header">
                                                                        <h6 class="modal-title fw-bold">
                                                                            <?= svg_icon('pen-to-square', 'text-primary me-2') ?>ક્લેરિટી સુધારો
                                                                        </h6>
                                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                                    </div>
                                                                    <div class="modal-body">
                                                                        <div class="mb-3">
                                                                            <label class="form-label">ક્લેરિટી નામ *</label>
                                                                            <input type="text" name="name" class="form-control" value="<?= e($pc['name']) ?>" required>
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label">કોડ (Code)</label>
                                                                            <input type="text" name="code" class="form-control font-mono text-uppercase" value="<?= e($pc['code']) ?>">
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label">વિગત / નોંધ (Description)</label>
                                                                            <input type="text" name="description" class="form-control" value="<?= e($pc['description']) ?>">
                                                                        </div>
                                                                        <div class="mb-3">
                                                                            <label class="form-label">ક્રમ (Sort Order)</label>
                                                                            <input type="number" name="sort_order" class="form-control font-mono" value="<?= (int)$pc['sort_order'] ?>" min="0">
                                                                        </div>
                                                                    </div>
                                                                    <div class="modal-footer">
                                                                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">રદ કરો</button>
                                                                        <button type="submit" class="btn btn-sm btn-primary">સાચવો (Save)</button>
                                                                    </div>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Drag-and-Drop Reordering Assets & Styles -->
<style>
.drag-handle {
    cursor: grab;
    user-select: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 4px;
    color: #94a3b8;
    transition: color 0.15s ease, background 0.15s ease;
}
.drag-handle:hover {
    color: #2563eb !important;
    background: #eff6ff;
}
.drag-handle:active {
    cursor: grabbing;
}
.sortable-ghost {
    opacity: 0.35;
    background-color: #dbeafe !important;
}
.sortable-chosen {
    background-color: #f1f5f9 !important;
    box-shadow: 0 4px 14px rgba(0,0,0,0.08);
}
.reorder-toast {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9999;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    box-shadow: 0 8px 24px rgba(0,0,0,0.14);
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.25s ease;
    opacity: 0;
    transform: translateY(15px);
    pointer-events: none;
}
.reorder-toast.show {
    opacity: 1;
    transform: translateY(0);
}
.reorder-toast-success {
    background: #065f46;
    color: #ffffff;
}
.reorder-toast-error {
    background: #991b1b;
    color: #ffffff;
}
</style>

<div id="reorderToast" class="reorder-toast reorder-toast-success">
    <span id="reorderToastIcon">✓</span>
    <span id="reorderToastMsg">ક્રમ અપડેટ થયો</span>
</div>

<script src="<?= BASE_URL ?>/assets/vendor/sortable.min.js"></script>
<script>
function showReorderToast(isSuccess, msg) {
    const toast = document.getElementById('reorderToast');
    const msgEl = document.getElementById('reorderToastMsg');
    const iconEl = document.getElementById('reorderToastIcon');
    if (!toast) return;
    toast.className = 'reorder-toast ' + (isSuccess ? 'reorder-toast-success' : 'reorder-toast-error') + ' show';
    iconEl.textContent = isSuccess ? '✓' : '⚠';
    msgEl.textContent = msg;
    clearTimeout(window.__toastTimer);
    window.__toastTimer = setTimeout(() => {
        toast.classList.remove('show');
    }, 2800);
}

function initSortableTable(containerSelector, tableName, itemSelector = 'tr[data-id]', badgeSelector = '.sort-order-badge') {
    const container = document.querySelector(containerSelector);
    if (!container || typeof Sortable === 'undefined') return;

    new Sortable(container, {
        handle: '.drag-handle',
        animation: 160,
        ghostClass: 'sortable-ghost',
        chosenClass: 'sortable-chosen',
        onEnd: function() {
            const items = Array.from(container.querySelectorAll(itemSelector));
            items.forEach((it, idx) => {
                const b = it.querySelector(badgeSelector);
                if (b) b.textContent = '#' + (idx + 1);
            });

            const ids = items.map(it => it.getAttribute('data-id'));
            if (!ids.length) return;

            fetch('<?= BASE_URL ?>/ajax/update_sort_order.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': '<?= get_csrf_token() ?>'
                },
                body: JSON.stringify({ 
                    table: tableName, 
                    order: ids,
                    csrf_token: '<?= get_csrf_token() ?>'
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showReorderToast(true, data.message || 'ક્રમ સફળતાપૂર્વક અપડેટ થયો');
                } else {
                    showReorderToast(false, data.message || 'ક્રમ અપડેટ કરવામાં નિષ્ફળ');
                }
            })
            .catch(err => {
                console.error(err);
                showReorderToast(false, 'નેટવર્ક એરર');
            });
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initSortableTable('#tbodyPartNames', 'assort_part_names');
    initSortableTable('#tbodyProcessingStages', 'processing_stages');
    initSortableTable('#tbodyDestinations', 'master_destinations');
    initSortableTable('#tbodySafes', 'master_safes');
    initSortableTable('#tbodyDabbas', 'master_dabbas');
    initSortableTable('#tbodyTags', 'master_tags');
    initSortableTable('#tbodyProcessTags', 'master_process_tags');
    initSortableTable('#tbodyGiaAttributes', 'master_gia_attributes');
    initSortableTable('#tbodyParcelClarities', 'master_parcel_clarities');
    initSortableTable('#listColorTags', 'master_color_tags', '.color-tag-item', '.sort-order-badge');
    initSortableTable('#tbodyColorDescriptions', 'master_color_descriptions');
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
