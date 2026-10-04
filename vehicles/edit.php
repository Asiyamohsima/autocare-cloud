<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vehicle_helpers.php';

$pageTitle  = 'Edit Vehicle';
$activePage = 'vehicles';
$db = require_db('vehicles');

// ---- Validate the ID and load the vehicle ----
$id = valid_id($_GET['id'] ?? null);
if ($id === null) {
    render_error_page('Invalid vehicle', 'The vehicle ID is not valid.', 400);
}

try {
    $vehicle = find_vehicle($db, $id);
} catch (Throwable $ex) {
    error_log('[AutoCare] Edit load failed: ' . $ex->getMessage());
    render_error_page('Error', 'Something went wrong while loading this vehicle.', 500);
}
if ($vehicle === null) {
    render_error_page('Vehicle not found', 'This vehicle does not exist or was deleted.', 404);
}

$old    = vehicle_to_form($vehicle);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = read_vehicle_post();

    if (!verify_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        [$errors, $data] = validate_vehicle($old, $db, $id);

        if (!$errors) {
            try {
                update_vehicle($db, $id, $data);
                set_flash('success', 'Vehicle updated successfully.');
                redirect('/vehicles/view.php?id=' . $id);
            } catch (mysqli_sql_exception $ex) {
                if ((int)$ex->getCode() === 1062) {
                    $errors['registration_number'] = 'A vehicle with this registration number already exists.';
                } else {
                    error_log('[AutoCare] Update vehicle failed: ' . $ex->getMessage());
                    $errors['form'] = 'Could not update the vehicle. Please try again.';
                }
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header mb-4">
    <h1>Edit Vehicle</h1>
    <p><?= e($vehicle['make'] . ' ' . $vehicle['model']) ?> &middot; <?= e($vehicle['registration_number']) ?></p>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert alert-danger">Please correct the highlighted fields.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php
        $submitLabel = 'Update Vehicle';
        $cancelUrl   = url('/vehicles/view.php?id=' . $id);
        require __DIR__ . '/../includes/vehicle_form.php';
        ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
