<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/vehicle_helpers.php';

$pageTitle  = 'Add Vehicle';
$activePage = 'vehicles';
$db = require_db('vehicles');

$old    = vehicle_defaults();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = read_vehicle_post();

    if (!verify_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        [$errors, $data] = validate_vehicle($old, $db);

        if (!$errors) {
            try {
                $newId = insert_vehicle($db, $data);
                set_flash('success', 'Vehicle added successfully.');
                redirect('/vehicles/view.php?id=' . $newId);
            } catch (mysqli_sql_exception $ex) {
                if ((int)$ex->getCode() === 1062) {
                    $errors['registration_number'] = 'A vehicle with this registration number already exists.';
                } else {
                    error_log('[AutoCare] Add vehicle failed: ' . $ex->getMessage());
                    $errors['form'] = 'Could not save the vehicle. Please try again.';
                }
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header mb-4">
    <h1>Add Vehicle</h1>
    <p>Enter your vehicle's details. Fields marked * are required.</p>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert alert-danger">Please correct the highlighted fields.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php
        $submitLabel = 'Save Vehicle';
        $cancelUrl   = url('/vehicles/');
        require __DIR__ . '/../includes/vehicle_form.php';
        ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
