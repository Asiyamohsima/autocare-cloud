<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fuel_helpers.php';

$pageTitle  = 'Add Fuel Record';
$activePage = 'fuel';
$db = require_db('fuel');

$vehicles = list_vehicles_for_select($db);
if (!$vehicles) {
    set_flash('warning', 'Add a vehicle first, then you can record fuel for it.');
    redirect('/vehicles/add.php');
}

$old    = fuel_defaults();
$errors = [];

$preselect = valid_id($_GET['vehicle_id'] ?? null);
if ($preselect !== null) {
    $old['vehicle_id'] = (string)$preselect;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = read_fuel_post();

    if (!verify_csrf()) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        [$errors, $data] = validate_fuel($old, $db);

        if (!$errors) {
            try {
                insert_fuel($db, $data);
                set_flash('success', 'Fuel record added.');
                redirect('/fuel/?vehicle_id=' . $data['vehicle_id']);
            } catch (Throwable $ex) {
                error_log('[AutoCare] Add fuel failed: ' . $ex->getMessage());
                $errors['form'] = 'Could not save the fuel record. Please try again.';
            }
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>

<div class="page-header mb-4">
    <h1>Add Fuel Record</h1>
    <p>Record a fill-up. Fields marked * are required.</p>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert alert-danger">Please correct the highlighted fields.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <?php
        $lockedVehicle = null;
        $submitLabel   = 'Save Fuel Record';
        $cancelUrl     = url('/fuel/');
        require __DIR__ . '/../includes/fuel_form.php';
        ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

