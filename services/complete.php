<?php
/** Marks a maintenance reminder as completed. POST only, with a valid CSRF token. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/service_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/services/');
}
if (!verify_csrf()) {
    set_flash('danger', 'Your session expired. Please try again.');
    redirect('/services/');
}

$id = valid_id($_POST['id'] ?? null);
if ($id === null) {
    set_flash('danger', 'Invalid service ID.');
    redirect('/services/');
}

$done = (($_POST['done'] ?? '1') === '0') ? 0 : 1;
$db   = require_db('services');

try {
    $service = find_service($db, $id);
    if ($service === null) {
        set_flash('warning', 'That service record no longer exists.');
        redirect('/services/');
    }

    $stmt = $db->prepare('UPDATE services SET reminder_done = ? WHERE id = ?');
    $stmt->bind_param('ii', $done, $id);
    $stmt->execute();
    $stmt->close();

    set_flash('success', $done ? 'Reminder marked as completed.' : 'Reminder re-opened.');
} catch (Throwable $ex) {
    error_log('[AutoCare] Complete reminder failed: ' . $ex->getMessage());
    set_flash('danger', 'Could not update the reminder. Please try again.');
}

redirect('/services/');
