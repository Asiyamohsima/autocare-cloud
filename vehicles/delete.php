<?php
/**
 * Deletes a vehicle. Accepts POST only, with a valid CSRF token.
 * Refuses if the vehicle still has documents (their S3 files must be removed first).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vehicle_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/vehicles/');
}

if (!verify_csrf()) {
    set_flash('danger', 'Your session expired. Please try again.');
    redirect('/vehicles/');
}

$id = valid_id($_POST['id'] ?? null);
if ($id === null) {
    set_flash('danger', 'Invalid vehicle ID.');
    redirect('/vehicles/');
}

$db = require_db('vehicles');

try {
    $vehicle = find_vehicle($db, $id);
    if ($vehicle === null) {
        set_flash('warning', 'That vehicle no longer exists.');
        redirect('/vehicles/');
    }

    // Block deletion while documents exist, so S3 files are never orphaned.
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM documents WHERE vehicle_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $docCount = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    if ($docCount > 0) {
        set_flash('warning', 'This vehicle still has ' . $docCount
            . ' document(s). Delete its documents first, then delete the vehicle.');
        redirect('/vehicles/view.php?id=' . $id);
    }

    $stmt = $db->prepare('DELETE FROM vehicles WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    set_flash('success', 'Vehicle ' . $vehicle['registration_number'] . ' was deleted.');
} catch (Throwable $ex) {
    error_log('[AutoCare] Delete vehicle failed: ' . $ex->getMessage());
    set_flash('danger', 'Could not delete the vehicle. Please try again.');
}

redirect('/vehicles/');
