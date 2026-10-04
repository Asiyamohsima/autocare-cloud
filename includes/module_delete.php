<?php
/** Shared delete handler for Insurance, Warranty and Registration. POST only, with CSRF token. Expects $module. */
$cfg = module_config($module);
if ($cfg === null) {
    http_response_code(404);
    exit;
}
$base = '/' . $module . '/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($base);
}
if (!verify_csrf()) {
    set_flash('danger', 'Your session expired. Please try again.');
    redirect($base);
}
$id = valid_id($_POST['id'] ?? null);
if ($id === null) {
    set_flash('danger', 'Invalid record ID.');
    redirect($base);
}

$db   = require_db($module);
$back = $base;

try {
    $rec = find_module_record($db, $cfg, $id);
    if ($rec === null) {
        set_flash('warning', 'That record no longer exists.');
        redirect($base);
    }
    $back = $base . '?vehicle_id=' . (int)$rec['vehicle_id'];

    $stmt = $db->prepare('DELETE FROM `' . $cfg['table'] . '` WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    set_flash('success', $cfg['singular'] . ' deleted. Uploaded documents were not affected.');
} catch (Throwable $ex) {
    error_log('[AutoCare] Delete ' . $module . ' failed: ' . $ex->getMessage());
    set_flash('danger', 'Could not delete the record. Please try again.');
}

redirect($back);
