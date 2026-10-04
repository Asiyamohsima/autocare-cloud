<?php
/** Deletes a service record. POST only, with a valid CSRF token. */
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

$db = require_db('services');

try {
    $service = find_service($db, $id);
    if ($service === null) {
        set_flash('warning', 'That service record no longer exists.');
        redirect('/services/');
    }

    $stmt = $db->prepare('DELETE FROM services WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    set_flash('success', 'Service record deleted.');
} catch (Throwable $ex) {
    error_log('[AutoCare] Delete service failed: ' . $ex->getMessage());
    set_flash('danger', 'Could not delete the service record. Please try again.');
}

redirect('/services/');
