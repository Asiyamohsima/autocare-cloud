<?php
/** Deletes a fuel record. POST only, with a valid CSRF token. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fuel_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/fuel/');
}
if (!verify_csrf()) {
    set_flash('danger', 'Your session expired. Please try again.');
    redirect('/fuel/');
}

$id = valid_id($_POST['id'] ?? null);
if ($id === null) {
    set_flash('danger', 'Invalid fuel record ID.');
    redirect('/fuel/');
}

$db = require_db('fuel');

try {
    if (find_fuel_log($db, $id) === null) {
        set_flash('warning', 'That fuel record no longer exists.');
        redirect('/fuel/');
    }

    $stmt = $db->prepare('DELETE FROM fuel_logs WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    set_flash('success', 'Fuel record deleted.');
} catch (Throwable $ex) {
    error_log('[AutoCare] Delete fuel failed: ' . $ex->getMessage());
    set_flash('danger', 'Could not delete the fuel record. Please try again.');
}

redirect('/fuel/');
